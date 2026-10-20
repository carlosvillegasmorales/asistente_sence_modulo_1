<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Primera entrega del Coach al terminar el diagnostico: la matriz de
 * desarrollo (RU-COA-OUT-001).
 *
 * Doc 3 §9.1 fija una plantilla concreta —encabezado, sintesis, tabla por
 * ambito, hasta tres prioridades y una pregunta de cierre— que reemplaza al
 * formato generico de cinco campos para este caso. La plantilla vive aqui,
 * como codigo, y no en el prompt: al modelo se le pide solo el texto de cada
 * celda, en JSON, y el servidor arma la estructura. Asi la forma no depende de
 * que el modelo la respete, y las reglas que no son de redaccion tampoco:
 *
 * - que dimensiones pueden ser prioridad y en que orden (RU-COA-PRIOR-002);
 * - cuantas (tres, o una si hay dos o mas dimensiones incompletas;
 *   RU-COA-003 y RU-COA-COMPLET-001);
 * - que no se repita la misma brecha (RU-COA-PRIOR-003, por dimension);
 * - que los recursos nombrados existan (RU-COA-LIM-001);
 * - que cada meta tenga la forma verbo + capacidad + contexto + evidencia
 *   (RU-COA-PDP-FLOW-001).
 *
 * Todo eso se aplica en validate_payload(), que es funcion pura y se verifica
 * en cli/verificar_coach.php sin modelo ni base de datos.
 */
class coach_report {

    /**
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|false La ultima matriz de desarrollo generada.
     */
    public static function get_latest(int $userid, int $courseid) {
        global $DB;
        $records = $DB->get_records('local_chatbot_coach_report',
            ['userid' => $userid, 'courseid' => $courseid], 'timecreated DESC', '*', 0, 1);
        return $records ? reset($records) : false;
    }

    /**
     * Si la matriz de desarrollo se redacto con otro resultado del diagnostico. Funcion pura.
     *
     * Una matriz anterior a la huella (sin la clave) no se da por vieja: no se sabe con que
     * resultado se hizo, y regenerarlas todas gastaria credito sin motivo. Esas se renuevan
     * cuando la persona cambia sus respuestas (ver refresh_after_change()).
     *
     * @param array $payload Payload guardado de la matriz.
     * @param array $matrix Filas actuales de diagnostic::get_gap_matrix().
     * @return bool
     */
    public static function is_stale(array $payload, array $matrix): bool {
        if (empty($payload['huella']) || empty($matrix)) {
            return false;
        }
        return $payload['huella'] !== diagnostic::matrix_fingerprint($matrix);
    }

    /**
     * Rehace lo que depende del resultado del diagnostico: la matriz de desarrollo y, si hay,
     * las propuestas de metas que la persona todavia no decidio. Las metas que ya acepto,
     * modifico o posterga son suyas y no se tocan.
     *
     * @param int $userid
     * @param int $courseid
     * @return array ok (bool), proposals (int, propuestas renovadas) y message (string).
     */
    public static function refresh_after_change(int $userid, int $courseid): array {
        $generated = self::generate($userid, $courseid);
        if (!$generated['ok']) {
            return ['ok' => false, 'proposals' => 0, 'message' => $generated['message']];
        }
        if (ux::on('autopdp')) {
            return self::fill_plan($userid, $courseid);
        }
        $renovadas = pdp::replace_pending_proposals($userid, $courseid);
        return ['ok' => true, 'proposals' => $renovadas,
            'message' => $renovadas > 0
                ? get_string('diag_refresh_done_pdp', 'local_chatbot', $renovadas)
                : get_string('diag_refresh_done', 'local_chatbot')];
    }

    /**
     * Lleva la matriz de desarrollo recien hecha al PDP como una version nueva del plan
     * (interruptor autopdp). Ver pdp::sync_from_diagnostic().
     *
     * @param int $userid
     * @param int $courseid
     * @return array ok, proposals y message, como refresh_after_change().
     */
    public static function fill_plan(int $userid, int $courseid): array {
        $plan = pdp::sync_from_diagnostic($userid, $courseid);
        if (!$plan['ok']) {
            return ['ok' => false, 'proposals' => 0,
                'message' => get_string('autopdp_failed', 'local_chatbot', $plan['message'])];
        }
        return ['ok' => true, 'proposals' => (int) $plan['created'],
            'message' => get_string('autopdp_done', 'local_chatbot', (int) $plan['created'])];
    }

    /**
     * Genera y guarda la matriz de desarrollo.
     *
     * @param int $userid
     * @param int $courseid
     * @return array Con claves ok (bool), message (string) y reportid (int).
     */
    public static function generate(int $userid, int $courseid): array {
        global $DB;

        $matrix = diagnostic::get_gap_matrix($userid, $courseid);
        if (empty($matrix)) {
            return ['ok' => false, 'reportid' => 0,
                'message' => get_string('pdp_error_nodiagnostic', 'local_chatbot')];
        }
        if (!ai\provider_factory::is_configured()) {
            return ['ok' => false, 'reportid' => 0,
                'message' => get_string('pdp_error_nokey', 'local_chatbot')];
        }

        $contract = coach_context::build($userid, $courseid);
        $candidates = diagnostic::rank_priorities($matrix);
        $limit = diagnostic::priority_limit($matrix);
        $catalog = coach_context::catalog($userid, $courseid);
        $catalognames = array_column($catalog, 'nombre');

        $context = assistant::build_context(assistant::MODE_COACH, $userid, $courseid);
        $prompt = $context['prompt'] . "\n\n" . self::instructions($candidates, $limit);

        $result = ai\provider_factory::get()->generate_content(
            [['role' => 'user', 'parts' => [['text' => $prompt]]]], 60, 2,
            ai\provider_factory::get_configured_model());
        $text = gemini_client::extract_text($result);
        if ($text === null) {
            return ['ok' => false, 'reportid' => 0,
                'message' => get_string('pdp_error_noresponse', 'local_chatbot')];
        }

        $decoded = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $text)), true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'reportid' => 0,
                'message' => get_string('pdp_error_unparsed', 'local_chatbot')];
        }

        $payload = self::validate_payload($decoded, $matrix,
            array_column($candidates, 'code'), $limit, $catalognames);
        // Con que resultado del diagnostico se redacto: si despues cambia, la matriz queda vieja.
        $payload['huella'] = diagnostic::matrix_fingerprint($matrix);

        // Indicador de calidad IA (Anexo G §11.4): recursos que el modelo nombro y no existen en
        // el catalogo. validate_payload ya los descarta; aqui se cuentan.
        $inventados = self::invented_resources($decoded, $catalognames);
        if ($inventados > 0) {
            trace::log(trace::QUALITY_INVENTED_RESOURCE, $userid, $courseid, trace::unit_for_course($courseid),
                ['origen' => 'matriz_coach', 'cantidad' => $inventados]);
        }

        // La redaccion tambien pasa por los guardrails: una sintesis que diga
        // "tu nivel es bajo" no se corrige sola por venir en JSON.
        $checked = guardrails::check_output(self::flatten_text($payload));
        if ($checked['blocked']) {
            return ['ok' => false, 'reportid' => 0,
                'message' => get_string('coach_report_blocked', 'local_chatbot')];
        }

        $header = [
            'fecha' => userdate(time(), get_string('strftimedatefullshort', 'langconfig')),
            'version' => $contract['diagnostico_version'],
            'estado' => $contract['estado_matriz'],
        ];
        $content = self::render($header, $payload, $matrix);
        if (!empty($checked['triggered'])) {
            // Las reglas de anotacion anteponen su correccion; se conserva.
            $content = trim(str_replace(self::flatten_text($payload), '', $checked['reply']))
                . "\n\n" . $content;
        }

        $now = time();
        $record = (object) [
            'userid' => $userid,
            'courseid' => $courseid,
            'diagversion' => $contract['diagnostico_version'],
            'estado' => $contract['estado_matriz'],
            'content' => $content,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'promptversion' => $context['promptversion'],
            'timecreated' => $now,
        ];
        $record->id = $DB->insert_record('local_chatbot_coach_report', $record);

        // Queda tambien como primer mensaje del hilo del Coach: la pregunta de
        // cierre de la plantilla pide una decision, y esa conversacion sigue
        // en el widget, no en esta pantalla.
        $messageid = $DB->insert_record('local_chatbot_messages', (object) [
            'userid' => $userid, 'courseid' => $courseid, 'message' => $content,
            'is_bot' => 1, 'mode' => assistant::MODE_COACH, 'timecreated' => $now,
        ]);

        interaction::record($userid, $courseid, assistant::MODE_COACH, 'matriz_inicial',
            $context['resources'], $content, $messageid, $checked['triggered']);

        return ['ok' => true, 'reportid' => (int) $record->id,
            'message' => get_string('coach_report_ready', 'local_chatbot')];
    }

    /**
     * Lo que se le pide al modelo: solo texto de celdas, en JSON.
     *
     * @param array $candidates Filas candidatas, ya ordenadas.
     * @param int $limit
     * @return string
     */
    private static function instructions(array $candidates, int $limit): string {
        $codes = implode(', ', array_column($candidates, 'code'));

        return "Prepara tu primera entrega tras el diagnostico: la matriz de desarrollo."
            . " Responde UNICAMENTE con un objeto JSON, sin texto adicional ni bloques de codigo:\n"
            . '{"sintesis": "...", "ambitos": [{"codigo": "...", "que_observamos": "...",'
            . ' "apoyo_sugerido": "...", "recurso": "...", "accion_inicial": "..."}],'
            . ' "prioridades": [{"codigo": "...", "meta_verbo": "...", "meta_capacidad": "...",'
            . ' "meta_contexto": "...", "apoyo_inicial": "...", "recurso": "...", "unidad": "...",'
            . ' "actividad": "...", "evidencia_avance": "...",'
            . ' "plazo_semanas": 0}]}' . "\n"
            . "Reglas:\n"
            . "- sintesis: dos o tres frases en segunda persona; primero las fortalezas iniciales,"
            . " luego las prioridades. Presenta los niveles como orientacion de este momento.\n"
            . "- ambitos: una entrada por cada dimension de resultados, con su codigo.\n"
            . "- prioridades: como maximo {$limit}, elegidas solo entre estos codigos y en este"
            . " orden salvo que lo justifiques por el contexto autorizado: {$codes}. Una por dimension.\n"
            . "- recurso: el nombre EXACTO de un elemento de catalogo_apoyos_activos, o cadena vacia"
            . " si ninguno sirve. No inventes nombres.\n"
            . "- meta_verbo: un verbo de desarrollo en infinitivo (analizar, seleccionar, justificar,"
            . " diseñar, observar, registrar, retroalimentar, ajustar, reflexionar, planificar).\n"
            . "- meta_contexto: el contexto de aplicación, empezando por su preposición (\"en tus talleres"
            . " presenciales…\", \"para tu curso de…\", \"durante…\"). La meta se arma como verbo +"
            . " capacidad + contexto y debe leerse como una frase correcta.\n"
            . "- No propongas microcápsulas: los apoyos son recursos del catálogo y actividades"
            . " conversacionales, no un catálogo de microcápsulas.\n"
            . "- plazo_semanas: numero entero entre 1 y 12, realista para su tiempo disponible.\n"
            . "- Ajusta cada prioridad al perfil autorizado: el tamano de la meta y el plazo a"
            . " tiempo_disponible, el contexto y los ejemplos a su sector, modalidad y tipo_programa, y"
            . " considera sus prioridades_declaradas cuando calcen con una dimension candidata.\n"
            . "- Nunca una calificacion global, ni etiquetas como competente, apta o nivel bajo.";
    }

    /**
     * Aplica las reglas que no pueden quedar a criterio del modelo. Funcion pura.
     *
     * @param array $decoded JSON devuelto por el modelo, ya decodificado.
     * @param array $matrix Filas de diagnostic::get_gap_matrix().
     * @param string[] $candidatecodes Codigos que pueden ser prioridad, en orden.
     * @param int $limit Maximo de prioridades.
     * @param string[] $catalognames Nombres del catalogo de apoyos activos.
     * @return array Carga util depurada: sintesis, ambitos (por codigo) y prioridades.
     */
    public static function validate_payload(array $decoded, array $matrix, array $candidatecodes,
            int $limit, array $catalognames): array {
        $clean = function($value, int $max = 600): string {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $value));
            return \core_text::substr($text, 0, $max);
        };

        // Ambitos: exactamente uno por dimension con resultado, aunque el modelo
        // haya omitido alguno o agregado uno que no existe.
        $bycode = [];
        foreach ((array) ($decoded['ambitos'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['codigo'])) {
                $bycode[(string) $entry['codigo']] = $entry;
            }
        }
        $ambitos = [];
        foreach ($matrix as $row) {
            $entry = $bycode[$row['code']] ?? [];
            $ambitos[$row['code']] = [
                'que_observamos' => $clean($entry['que_observamos'] ?? ''),
                'apoyo_sugerido' => $clean($entry['apoyo_sugerido'] ?? ''),
                'recurso' => coach_context::match_catalog((string) ($entry['recurso'] ?? ''), $catalognames),
                'accion_inicial' => $clean($entry['accion_inicial'] ?? ''),
            ];
        }

        // Prioridades: solo candidatas, una por dimension, hasta el limite, y
        // en el orden del sistema si el modelo no respeta el suyo propio con
        // codigos validos.
        $prioridades = [];
        $seen = [];
        foreach ((array) ($decoded['prioridades'] ?? []) as $entry) {
            if (count($prioridades) >= $limit || !is_array($entry)) {
                break;
            }
            $code = (string) ($entry['codigo'] ?? '');
            if (!in_array($code, $candidatecodes, true) || isset($seen[$code])) {
                continue;
            }
            $verbo = $clean($entry['meta_verbo'] ?? '', 40);
            $capacidad = $clean($entry['meta_capacidad'] ?? '', 200);
            $contexto = $clean($entry['meta_contexto'] ?? '', 200);
            $evidencia = $clean($entry['evidencia_avance'] ?? '', 300);
            // Sin las cuatro partes no hay meta segun RU-COA-PDP-FLOW-001: se
            // descarta antes que guardar una meta a medias.
            if ($verbo === '' || $capacidad === '' || $contexto === '' || $evidencia === '') {
                continue;
            }
            $seen[$code] = true;
            $plazo = (int) ($entry['plazo_semanas'] ?? 0);
            $prioridades[] = [
                'codigo' => $code,
                'meta' => self::compose_goal($verbo, $capacidad, $contexto),
                'apoyo_inicial' => $clean($entry['apoyo_inicial'] ?? ''),
                'recurso' => coach_context::match_catalog((string) ($entry['recurso'] ?? ''), $catalognames),
                'unidad' => $clean($entry['unidad'] ?? '', 255),
                // SPEC v5, RU-COA-003: sin microcapsulas. La columna se conserva por
                // compatibilidad con los planes ya guardados, pero no se vuelve a llenar.
                'microcapsula' => '',
                'actividad' => $clean($entry['actividad'] ?? '', 400),
                'evidencia_avance' => $evidencia,
                'plazo_semanas' => max(1, min(12, $plazo ?: 4)),
            ];
        }

        return [
            'sintesis' => $clean($decoded['sintesis'] ?? '', 900),
            'ambitos' => $ambitos,
            'prioridades' => $prioridades,
        ];
    }

    /**
     * Meta con la forma de Doc 3 §11.3: verbo + capacidad + contexto. La
     * evidencia de avance va en su propio campo. Funcion pura.
     *
     * @param string $verbo
     * @param string $capacidad
     * @param string $contexto
     * @return string
     */
    /**
     * Cuantos recursos nombro el modelo que no estan en el catalogo. Funcion pura.
     *
     * @param array $decoded
     * @param string[] $catalognames
     * @return int
     */
    public static function invented_resources(array $decoded, array $catalognames): int {
        $n = 0;
        foreach (['ambitos', 'prioridades'] as $key) {
            foreach ((array) ($decoded[$key] ?? []) as $entry) {
                $asked = is_array($entry) ? trim((string) ($entry['recurso'] ?? '')) : '';
                if ($asked !== '' && coach_context::match_catalog($asked, $catalognames) === '') {
                    $n++;
                }
            }
        }
        return $n;
    }

    public static function compose_goal(string $verbo, string $capacidad, string $contexto): string {
        // El modelo copia los verbos tal como se los sugiere el prompt; si alguna vez
        // llegan sin tilde ("disenar"), se corrigen aqui en vez de mostrarse asi.
        $verbo = strtr(\core_text::strtolower(trim($verbo)), self::VERB_SPELLING);
        $verbo = \core_text::strtoupper(\core_text::substr($verbo, 0, 1)) . \core_text::substr($verbo, 1);

        // Sin conector la meta no se lee: "Diseñar actividades a partir de manuales
        // talleres presenciales". Si el contexto no empieza por una preposicion, se
        // antepone "en", que es el caso general (prueba automatica del 21-09-2026).
        $contexto = trim($contexto);
        $primera = \core_text::strtolower((string) strtok($contexto, " \t,"));
        if ($contexto !== '' && !in_array($primera, self::CONTEXT_CONNECTORS, true)) {
            $contexto = 'en ' . $contexto;
        }

        return rtrim(trim(preg_replace('/\s+/u', ' ', "{$verbo} {$capacidad} {$contexto}")), '.') . '.';
    }

    /** @var string[] Palabras con las que un contexto de meta ya queda bien conectado al resto. */
    const CONTEXT_CONNECTORS = ['en', 'para', 'con', 'durante', 'a', 'al', 'ante', 'bajo', 'desde', 'dentro',
        'sobre', 'por', 'mediante', 'según', 'segun', 'hacia', 'del', 'de', 'entre', 'tras', 'frente', 'cuando',
        'como'];

    /** @var array Verbos de desarrollo que el modelo puede devolver sin tilde. */
    const VERB_SPELLING = ['disenar' => 'diseñar', 'redisenar' => 'rediseñar'];

    /**
     * Arma la matriz de desarrollo con la plantilla de Doc 3 §9.1. Funcion pura.
     *
     * @param array $header Claves fecha, version y estado.
     * @param array $payload Salida de validate_payload().
     * @param array $matrix Filas de diagnostic::get_gap_matrix().
     * @return string Markdown.
     */
    public static function render(array $header, array $payload, array $matrix): string {
        $cell = function(string $text): string {
            $text = str_replace(["\r", "\n", '|'], [' ', ' ', '/'], $text);
            return $text !== '' ? $text : '—';
        };
        $labels = diagnostic::level_labels_plain();

        $out = "### Tu matriz de desarrollo\n\n"
            . "*{$header['fecha']} · Diagnóstico {$header['version']} · Matriz {$header['estado']}*\n\n";

        if ($payload['sintesis'] !== '') {
            $out .= $payload['sintesis'] . "\n\n";
        }

        $out .= "| Ámbito | Nivel | Qué observamos | Apoyo sugerido | Acción inicial |\n"
            . "|---|---|---|---|---|\n";
        foreach ($matrix as $row) {
            $ambito = $payload['ambitos'][$row['code']] ?? [];
            $nivel = $labels[$row['nivel']] . (!empty($row['preliminar']) ? ' (preliminar)' : '');
            $apoyo = $ambito['apoyo_sugerido'] ?? '';
            if (!empty($ambito['recurso'])) {
                $apoyo = trim($apoyo . ' Recurso: ' . $ambito['recurso'] . '.');
            }
            $out .= '| ' . $cell($row['name']) . ' | ' . $cell($nivel)
                . ' | ' . $cell($ambito['que_observamos'] ?? '')
                . ' | ' . $cell($apoyo)
                . ' | ' . $cell($ambito['accion_inicial'] ?? '') . " |\n";
        }

        $out .= "\n#### Tus prioridades iniciales\n\n";
        if (empty($payload['prioridades'])) {
            $out .= "Por ahora no hay una prioridad que se pueda proponer con seguridad: completa"
                . " las dimensiones marcadas como preliminares y la revisamos juntos.\n";
        }
        foreach ($payload['prioridades'] as $i => $prioridad) {
            $apoyo = $prioridad['apoyo_inicial'];
            if ($prioridad['recurso'] !== '') {
                $apoyo = trim($apoyo . ' Recurso: ' . $prioridad['recurso'] . '.');
            }
            $out .= ($i + 1) . ". **Meta sugerida:** {$prioridad['meta']}\n"
                . "   **Apoyo inicial:** " . ($apoyo !== '' ? $apoyo : '—') . "\n"
                . "   **Evidencia de avance:** {$prioridad['evidencia_avance']}\n";
        }

        $out .= "\n*Estos niveles son una orientación para este momento, no una calificación ni"
            . " una clasificación permanente.*\n\n"
            . "**¿Quieres mantener estas prioridades, ajustar alguna o incorporar otra necesidad?**";

        return $out;
    }

    /**
     * Todo el texto redactado por el modelo en una sola cadena, para pasarlo
     * por los guardrails de salida.
     *
     * @param array $payload
     * @return string
     */
    private static function flatten_text(array $payload): string {
        $parts = [$payload['sintesis']];
        foreach ($payload['ambitos'] as $ambito) {
            $parts[] = $ambito['que_observamos'] . ' ' . $ambito['apoyo_sugerido'] . ' ' . $ambito['accion_inicial'];
        }
        foreach ($payload['prioridades'] as $prioridad) {
            $parts[] = $prioridad['meta'] . ' ' . $prioridad['apoyo_inicial'] . ' ' . $prioridad['evidencia_avance'];
        }
        return implode("\n", array_filter($parts));
    }
}
