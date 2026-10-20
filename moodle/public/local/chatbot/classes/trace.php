<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Trazabilidad del Anexo G: codigos (§2), catalogo de eventos (§7) y registro estructurado
 * del Tutor (Anexo E §11).
 *
 * Nada de lo que se guarda aqui es texto de la persona: codigos, etiquetas, estados y
 * datos minimos. Los eventos van a local_chatbot_trace (para los indicadores del §11) y
 * al log estandar de Moodle como \local_chatbot\event\trace_recorded.
 */
class trace {

    // Tutor (Anexo G §7.2). OPEN se deriva de la sesion: es la primera consulta despues de
    // 30 minutos sin actividad. CLOSE lo avisa el widget al cerrarse (navigator.sendBeacon a
    // ajax_close.php), una sola vez por sesion de consultas.
    const T_OPEN = 'EVT-IA-T-{U}-OPEN';
    const T_CONSULT = 'EVT-IA-T-{U}-CONSULT';
    const T_QUIZ_GUARD = 'EVT-IA-T-{U}-QUIZ-GUARD';
    const T_TRANSFER = 'EVT-IA-T-{U}-TRANSFER';
    const T_COACH_REF = 'EVT-IA-T-{U}-COACH-REF';
    const T_CLOSE = 'EVT-IA-T-{U}-CLOSE';

    // Coach y PDP (Anexo G §7.3).
    const C_OPEN = 'EVT-IA-C-{U}-OPEN';
    const C_PROPOSE = 'EVT-IA-C-{U}-PROPOSE';
    const C_DECISION = 'EVT-IA-C-{U}-DECISION';
    const C_EVIDENCE = 'EVT-IA-C-{U}-EVIDENCE';
    const C_VERIFY = 'EVT-IA-C-{U}-VERIFY';
    const C_UPDATE_PROPOSE = 'EVT-IA-C-{U}-UPDATE-PROPOSE';
    const C_UPDATE_DECISION = 'EVT-IA-C-{U}-UPDATE-DECISION';
    const C_CLOSE_PROPOSE = 'EVT-IA-C-{U}-GOAL-CLOSE-PROPOSE';
    const C_CLOSE_CONFIRM = 'EVT-IA-C-{U}-GOAL-CLOSE-CONFIRM';

    // Doc 6.0 §10.2 y calidad IA (Anexo G §11.4).
    const PDP_DECISION = 'EVT-PDP-DECISION';
    const PDP_HISTORY_VIEW = 'EVT-PDP-HISTORIAL-VIEW';
    const TECH_INCIDENT = 'EVT-INCIDENCIA-TECNICA';
    const QUALITY_INVENTED_RESOURCE = 'EVT-CALIDAD-RECURSO-INVENTADO';
    const QUALITY_BLOCKED_DATA = 'EVT-CALIDAD-DATOS-NO-PERMITIDOS';
    const QUIZ_SUBMITTED = 'EVT-{U}-QF-SUBMIT';

    // Recorrido por los recursos fijos (Anexo G §4). El evento sale del idnumber de la
    // actividad, no de su nombre: LF lectura, CA caso, ER ejemplo, QF quiz.
    const RES_VIEW = 'EVT-{U}-{R}-VIEW';
    const RES_COMPLETE = 'EVT-{U}-{R}-COMPLETE';
    const QUIZ_STARTED = 'EVT-{U}-QF-START';
    const QUIZ_REVIEWED = 'EVT-{U}-QF-REVIEW';
    const QUIZ_COMPLETED = 'EVT-{U}-QF-COMPLETE';

    /** @var string[] Tipos de recurso fijo que el Anexo G traza, por su codigo. */
    const RESOURCE_KINDS = ['LF', 'CA', 'ER', 'QF'];

    /** @var int Minutos sin actividad a partir de los cuales una consulta abre una sesion nueva. */
    const SESSION_GAP_MINUTES = 30;

    /**
     * Tipo de recurso fijo a partir del idnumber de una actividad. Funcion pura.
     *
     * Devuelve cadena vacia cuando la actividad no es uno de los recursos del Anexo G:
     * sin idnumber, o con uno que no sigue la convencion U[n]-[LF|CA|ER|QF]-[nn].
     *
     * @param string $idnumber
     * @return string LF, CA, ER, QF o cadena vacia.
     */
    public static function resource_kind(string $idnumber): string {
        if (preg_match('/^U\d+-(LF|CA|ER|QF)-\d+/i', trim($idnumber), $m)) {
            return strtoupper($m[1]);
        }
        return '';
    }

    /**
     * Codigo de evento de un recurso fijo, con la unidad y el tipo ya resueltos.
     *
     * @param string $plantilla self::RES_VIEW o self::RES_COMPLETE.
     * @param string $kind LF, CA, ER o QF.
     * @return string
     */
    public static function resource_event(string $plantilla, string $kind): string {
        return str_replace('{R}', strtoupper($kind), $plantilla);
    }

    /**
     * Codigo de unidad a partir de un indicio (idnumber de actividad, "Unidad 1", "U1-CA-01").
     * Funcion pura.
     *
     * @param string $hint
     * @return string U1, U2... o UX si no se puede saber.
     */
    public static function unit_from_hint(string $hint): string {
        if (preg_match('/\bU(\d+)\b|^U(\d+)-/u', $hint, $m) || preg_match('/unidad\s*(\d+)/iu', $hint, $m)) {
            $n = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            if ($n !== '') {
                return 'U' . (int) $n;
            }
        }
        return 'UX';
    }

    /**
     * Unidad de un curso cuando no hay otro indicio: si todas las actividades codificadas
     * del curso son de una misma unidad, esa; si no, UX.
     *
     * @param int $courseid
     * @return string
     */
    public static function unit_for_course(int $courseid): string {
        global $DB;
        if ($courseid <= 0) {
            return 'UX';
        }
        $units = [];
        foreach ($DB->get_fieldset_select('course_modules', 'idnumber', "course = ? AND idnumber <> ''", [$courseid]) as $idn) {
            $u = self::unit_from_hint((string) $idn);
            if ($u !== 'UX') {
                $units[$u] = true;
            }
        }
        return count($units) === 1 ? array_key_first($units) : 'UX';
    }

    /**
     * Codigo de objeto con la convencion del Anexo G §2.1. Funcion pura.
     *
     * @param string $prefix IA-T, IA-C, EV, VI o PDP-UPD.
     * @param string $unit U1, U2... (se ignora para PDP-UPD).
     * @param int $id
     * @return string p. ej. IA-T-U1-000123, EV-U1-000045, PDP-UPD-000007.
     */
    public static function code(string $prefix, string $unit, int $id): string {
        $num = str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        return $prefix === 'PDP-UPD' ? "PDP-UPD-{$num}" : "{$prefix}-{$unit}-{$num}";
    }

    /**
     * Recurso desde el que se abrio el asistente: el idnumber de la actividad (U1-CA-01) o,
     * si no tiene, "modulo:cmid".
     *
     * @param int $courseid
     * @param int $cmid
     * @return string Cadena vacia si no hay actividad o no pertenece al curso.
     */
    public static function source_resource(int $courseid, int $cmid): string {
        global $DB;
        if ($cmid <= 0 || $courseid <= 0) {
            return '';
        }
        $cm = $DB->get_record_sql('SELECT cm.id, cm.idnumber, m.name AS modname FROM {course_modules} cm
                                     JOIN {modules} m ON m.id = cm.module
                                    WHERE cm.id = ? AND cm.course = ?', [$cmid, $courseid]);
        if (!$cm) {
            return '';
        }
        return trim((string) $cm->idnumber) !== '' ? (string) $cm->idnumber : "{$cm->modname}:{$cm->id}";
    }

    /** @var array Etiquetas de concepto del Anexo E §4 => patron que las reconoce. */
    const CONCEPTS = [
        'competencia' => '/\bcompetencias?\b/iu',
        'desempeno_esperado' => '/desempe(ñ|n)o(s)?\s+(esperado|de\s+referencia|laboral)/iu',
        'aprendizaje_esperado' => '/aprendizajes?\s+esperados?/iu',
        'criterio_desempeno' => '/criterios?\s+de\s+desempe(ñ|n)o/iu',
        'criterio_evaluacion' => '/criterios?\s+de\s+evaluaci(ó|o)n/iu',
        'evidencia' => '/\bevidencias?\b/iu',
        'instrumento' => '/\binstrumentos?\b|r(ú|u)brica|lista\s+de\s+cotejo|pauta/iu',
        'actividad_situada' => '/situad[ao]s?|simulaci(ó|o)n|pr(á|a)ctica\s+real/iu',
        'condiciones_realizacion' => '/condiciones?\s+de\s+(realizaci(ó|o)n|implementaci(ó|o)n)|viabilidad|viable/iu',
        'progresion' => '/progresi(ó|o)n|autonom(í|i)a|complejidad/iu',
        'contextualizacion' => '/contextualiz/iu',
        'traduccion_formativa' => '/traducci(ó|o)n\s+formativa|\bUCL\b|perfil\s+ocupacional/iu',
    ];

    /**
     * Etiquetas de concepto de una consulta. Funcion pura.
     *
     * @param string $text Consulta de la persona (no se guarda; solo se etiqueta).
     * @return string[]
     */
    public static function concept_tags(string $text): array {
        $tags = [];
        foreach (self::CONCEPTS as $tag => $pattern) {
            if (preg_match($pattern, $text)) {
                $tags[] = $tag;
            }
        }
        return $tags;
    }

    /**
     * Recursos y apartados citados en una respuesta, con la forma U1-LF-01-seccion-3.
     * Funcion pura.
     *
     * @param string $reply
     * @return string[]
     */
    public static function sources_referred(string $reply): array {
        $found = [];
        if (preg_match_all('/\b(U\d+-(?:LF|CA|ER|QF)-\d{2})\b[^\n]{0,60}?(?:apartados?|secci(?:ó|o)n(?:es)?)\s+(\d+(?:\.\d+)?)/iu',
                $reply, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $found[] = $hit[1] . '-seccion-' . $hit[2];
            }
        }
        if (preg_match_all('/\bU\d+-(?:LF|CA|ER|QF)-\d{2}\b/u', $reply, $m)) {
            foreach ($m[0] as $code) {
                $found[] = $code;
            }
        }
        // Referencias por nombre, que es como cita el asistente con el prompt del Anexo E: "revisa
        // los apartados 3.5 y 3.6 del Documento de Lectura de la Unidad 1". Se convierten al codigo
        // del recurso y, si hay apartados, a U1-LF-01-seccion-3.5.
        $resources = ['LF' => 'documento\s+de\s+lectura', 'CA' => 'caso\s+de\s+an(?:á|a)lisis',
            'ER' => 'ejemplo\s+de\s+resoluci(?:ó|o)n(?:\s+comentado)?'];
        foreach ($resources as $type => $name) {
            $pattern = '/(?:(?:apartados?|secci(?:ó|o)n(?:es)?)\s+((?:\d+(?:\.\d+)?)(?:\s*(?:,|y|e)\s*\d+(?:\.\d+)?)*)'
                . '\s+(?:\([^)]*\)\s+)?del?\s+)?(?:\*\*)?' . $name . '(?:\*\*)?(?:\s+de\s+la\s+unidad\s+(\d+))?/iu';
            if (!preg_match_all($pattern, $reply, $m, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($m as $hit) {
                $unit = !empty($hit[2]) ? (int) $hit[2] : null;
                $code = $unit !== null ? "U{$unit}-{$type}-01" : "U?-{$type}";
                if (!empty($hit[1])) {
                    foreach (preg_split('/\s*(?:,|y|e)\s*/u', $hit[1]) as $section) {
                        $found[] = "{$code}-seccion-{$section}";
                    }
                } else if (!preg_grep('/^U\d+-' . $type . '-/', $found)) {
                    $found[] = $code;
                }
            }
        }
        return array_values(array_unique($found));
    }

    /**
     * Intencion de una consulta al Tutor con el vocabulario del Anexo E §11. Funcion pura.
     *
     * @param string $message
     * @return string aclaracion_conceptual, analisis_caso, transferencia_sectorial, apoyo_quiz u orientacion_PDP.
     */
    public static function tutor_intent(string $message): string {
        if (preg_match('/\b(quiz|cuestionario|pregunta\s+\d+|alternativa)\b/iu', $message)) {
            return 'apoyo_quiz';
        }
        if (preg_match('/\b(meta|pdp|plan\s+personal)\b/iu', $message)) {
            return 'orientacion_PDP';
        }
        if (preg_match('/\b(sector|turismo|construcci(ó|o)n|salud|agro|manufactura|comercio|miner(í|i)a|otro\s+rubro|mi\s+curso\s+es)\b/iu',
                $message)) {
            return 'transferencia_sectorial';
        }
        if (preg_match('/\b(caso|distribuciones\s+andinas|consigna|actividad|viable)\b/iu', $message)) {
            return 'analisis_caso';
        }
        return 'aclaracion_conceptual';
    }

    /**
     * Si una respuesta del Tutor orienta hacia el Coach. Funcion pura.
     *
     * @param string $reply
     * @return bool
     */
    public static function refers_to_coach(string $reply): bool {
        return (bool) preg_match('/\bcoach\b/iu', $reply);
    }

    /**
     * Registra un evento del catalogo.
     *
     * @param string $eventcode Codigo, con {U} en lugar de la unidad si corresponde.
     * @param int $userid
     * @param int $courseid
     * @param string $unit
     * @param array $data Datos minimos del evento (nunca texto de la persona).
     * @param string $objectcode
     * @return int id del registro.
     */
    public static function log(string $eventcode, int $userid, int $courseid, string $unit, array $data = [],
            string $objectcode = ''): int {
        global $DB;
        $code = str_replace('{U}', $unit, $eventcode);
        try {
            $id = (int) $DB->insert_record('local_chatbot_trace', (object) [
                'eventcode' => $code,
                'objectcode' => $objectcode !== '' ? $objectcode : null,
                'userid' => $userid,
                'pseudoid' => $userid > 0 ? pseudonymizer::for_user($userid) : null,
                'courseid' => $courseid,
                'unitcode' => $unit,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'timecreated' => time(),
            ]);
            event\trace_recorded::create([
                'context' => \context_system::instance(),
                'userid' => $userid ?: null,
                'objectid' => $id,
                'other' => ['code' => $code, 'object' => $objectcode],
            ])->trigger();
            return $id;
        } catch (\Throwable $e) {
            // La trazabilidad nunca debe cortar la experiencia de la persona.
            debugging('local_chatbot: no se pudo registrar el evento ' . $code . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 0;
        }
    }

    /**
     * Datos minimos del cierre del Tutor (Anexo G §7.2): sintesis (etiquetas de concepto y de
     * intencion de la ultima consulta), recurso recomendado y siguiente paso. Funcion pura;
     * nunca texto de la persona.
     *
     * @param string $concepttags Etiquetas de la ultima consulta, separadas por coma.
     * @param string $sourcesreferred Recursos citados en la ultima respuesta, separados por coma.
     * @param string $intent Intencion clasificada de la ultima consulta.
     * @return array
     */
    public static function close_data(string $concepttags, string $sourcesreferred, string $intent): array {
        $split = static function (string $csv): array {
            return array_values(array_filter(array_map('trim', explode(',', $csv)), 'strlen'));
        };
        $fuentes = $split($sourcesreferred);
        return [
            'sintesis' => ['intencion' => $intent, 'conceptos' => $split($concepttags)],
            'recurso_recomendado' => $fuentes[0] ?? null,
            'siguiente_paso' => null,
        ];
    }

    /**
     * Si corresponde registrar un CLOSE: hubo consultas despues del ultimo cierre (o no hay
     * cierre previo). Evita duplicados cuando el widget avisa mas de una vez. Funcion pura.
     *
     * @param int $lastinteraction Marca de tiempo de la ultima consulta al Tutor (0 si no hay).
     * @param int $lastclose Marca de tiempo del ultimo CLOSE registrado (0 si no hay).
     * @return bool
     */
    public static function should_log_close(int $lastinteraction, int $lastclose): bool {
        return $lastinteraction > 0 && $lastinteraction > $lastclose;
    }

    /**
     * Si esta consulta abre una sesion nueva del modo (primera tras SESSION_GAP_MINUTES).
     *
     * @param int $userid
     * @param int $courseid
     * @param string $mode
     * @param int $currentmessageid Mensaje de la persona recien guardado.
     * @return bool
     */
    public static function opens_session(int $userid, int $courseid, string $mode, int $currentmessageid): bool {
        global $DB;
        $since = time() - self::SESSION_GAP_MINUTES * 60;
        return !$DB->record_exists_select('local_chatbot_messages',
            'userid = ? AND courseid = ? AND mode = ? AND is_bot = 0 AND timecreated >= ? AND id <> ?',
            [$userid, $courseid, $mode, $since, $currentmessageid]);
    }
}
