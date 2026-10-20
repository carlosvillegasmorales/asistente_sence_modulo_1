<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Objeto de datos del Coach para el trabajo sobre una meta (RU-COA-DATA-002,
 * RU-COA-001 momento b).
 *
 * El Anexo F §4 define, para cada intervencion sobre una meta, un objeto
 * preprocesado con identificacion tecnica, origen de la meta, estado, contexto
 * autorizado, recursos fijos, progreso y reglas de privacidad, y recomienda usarlo
 * "como schema de integracion". Hasta M1-v1.9.4 esos datos llegaban al Coach como
 * cuatro bloques de texto sueltos (PDP, apoyo en curso, patrones del quiz y
 * estado del proyecto), cada uno armado por su cuenta.
 *
 * Las claves del primer nivel siguen literalmente la tabla del documento. Lo que
 * el §4 lista como campos minimos pero su ejemplo no muestra (actividad previa,
 * feedback anterior, siguiente accion, decisiones) va bajo estado_meta, y lo que
 * el plugin agrega por su cuenta va aparte, en detalle_plugin y reglas_sistema,
 * para que quien integre contra el documento no tenga que distinguir campo por
 * campo cual es cual.
 *
 * Armar el objeto no consulta nada (assemble); recoger sus datos si (inputs).
 * Asi el contrato se verifica sin Moodle, con cli/verificar_coach_meta.php.
 */
class coach_goal {

    /** @var string[] Claves del primer nivel del objeto del documento (Anexo F §4). */
    const DOC_KEYS = ['user_id_pseudonimizado', 'pdp_id', 'pdp_goal', 'contexto_autorizado',
        'recursos_fijos', 'progreso', 'privacy'];

    /** @var string[] Claves de pdp_goal. */
    const GOAL_KEYS = ['pdp_goal_id', 'goal_version', 'title', 'dimension', 'subdimension',
        'orientation_level', 'priority_source', 'source_evidence_summary', 'goal_statement',
        'goal_status', 'suggested_timeframe', 'learner_decision'];

    /** @var string[] Claves de contexto_autorizado. */
    const CONTEXT_KEYS = ['rol', 'tipo_programa', 'modalidad', 'sector_especialidad', 'participantes',
        'tiempo_disponible', 'restricciones'];

    /** @var string[] Claves de progreso. */
    const PROGRESS_KEYS = ['recursos_consultados', 'quiz_result_summary', 'previous_evidence_summary'];

    /** @var string[] Claves de privacy. */
    const PRIVACY_KEYS = ['consent_version', 'retention_policy_version', 'permissions'];

    /** @var string[] Valores que el documento admite en learner_decision. */
    const DECISIONS = ['aceptada', 'editada', 'postergada', 'rechazada', 'pendiente'];

    /**
     * @var string[] Nombres de campo que jamas pueden aparecer en el objeto: llevan
     * identidad directa. user_id_pseudonimizado es otra clave y esta permitida.
     */
    const FORBIDDEN_KEYS = ['nombre', 'name', 'firstname', 'lastname', 'apellido', 'email', 'correo',
        'rut', 'username', 'userid', 'user_id', 'telefono', 'phone'];

    /** @var int Largo maximo de un texto libre dentro del objeto. */
    const TEXT_LIMIT = 500;

    // ------------------------------------------------------------------
    // Parte pura: no consulta nada.
    // ------------------------------------------------------------------

    /**
     * Decision de la persona sobre una meta, en el vocabulario del documento. Funcion pura.
     *
     * @param string $status Estado guardado de la meta (pdp::GOAL_*).
     * @return string Uno de DECISIONS.
     */
    public static function learner_decision(string $status): string {
        $map = [
            'aceptado' => 'aceptada',
            'completado' => 'aceptada',
            'modificado' => 'editada',
            'postergado' => 'postergada',
            'rechazado' => 'rechazada',
            'propuesto' => 'pendiente',
        ];
        return $map[$status] ?? 'pendiente';
    }

    /**
     * Arma el objeto de una meta a partir de datos ya recogidos. Funcion pura.
     *
     * Todo texto libre pasa por el barrido de correos, RUT y telefonos: la persona
     * puede haber editado su meta o escrito sus evidencias, y este objeto va al
     * modelo. El identificador de la persona es siempre el seudonimo.
     *
     * @param array $in Datos de la meta; ver inputs() para la forma.
     * @return array Objeto listo para json_encode.
     */
    public static function assemble(array $in): array {
        $g = $in['goal'] ?? [];
        $ctx = $in['context'] ?? [];
        $prog = $in['progress'] ?? [];
        $priv = $in['privacy'] ?? [];
        $st = $in['goal_state'] ?? [];
        $plugin = $in['plugin'] ?? [];

        $goal = [
            'pdp_goal_id' => (int) ($g['id'] ?? 0),
            'goal_version' => (string) ($g['version'] ?? '1'),
            'title' => self::text($g['title'] ?? null),
            'dimension' => (string) ($g['dimension'] ?? ''),
            // El instrumento trabaja por dimension; cuando trae subdimensiones,
            // la meta la declara aqui (desvio declarado en la seccion 15 del SPEC).
            'subdimension' => (string) ($g['subdimension'] ?? ($g['dimension'] ?? '')),
            'orientation_level' => self::text($g['orientation_level'] ?? null),
            // El plugin no guarda el codigo de prioridad de origen del Anexo G
            // (MIBND-M1-PR-01): va en null, no inventado.
            'priority_source' => self::text($g['priority_source'] ?? null),
            'source_evidence_summary' => self::text($g['source_evidence_summary'] ?? null),
            'goal_statement' => self::text($g['statement'] ?? null),
            'goal_status' => (string) ($g['status'] ?? ''),
            'suggested_timeframe' => self::text($g['timeframe'] ?? null),
            'learner_decision' => self::learner_decision((string) ($g['stored_status'] ?? '')),
        ];

        return [
            'user_id_pseudonimizado' => (string) ($in['pseudoid'] ?? ''),
            'pdp_id' => (int) ($in['pdp']['id'] ?? 0),
            'pdp_goal' => $goal,
            'contexto_autorizado' => [
                'rol' => self::text($ctx['rol'] ?? null),
                'tipo_programa' => self::text($ctx['tipo_programa'] ?? null),
                'modalidad' => self::text($ctx['modalidad'] ?? null),
                'sector_especialidad' => self::text($ctx['sector_especialidad'] ?? null),
                'participantes' => self::text($ctx['participantes'] ?? null),
                'tiempo_disponible' => self::text($ctx['tiempo_disponible'] ?? null),
                'restricciones' => self::text($ctx['restricciones'] ?? null),
            ],
            'recursos_fijos' => array_values(array_map('strval', $in['fixed_resources'] ?? [])),
            'progreso' => [
                'recursos_consultados' => array_values(array_map('strval', $prog['consulted'] ?? [])),
                'quiz_result_summary' => self::text($prog['quiz_summary'] ?? null),
                'previous_evidence_summary' => array_values(array_filter(array_map(
                    fn($t) => self::text($t), $prog['previous_evidence'] ?? []))),
            ],
            'privacy' => [
                'consent_version' => (string) ($priv['consent_version'] ?? ''),
                'retention_policy_version' => (string) ($priv['retention_version'] ?? ''),
                'permissions' => array_values(array_map('strval', $priv['permissions'] ?? [])),
            ],
            // Campos minimos del §4 (tabla "Estado de meta") que su ejemplo no muestra.
            'estado_meta' => [
                'actividad_previa' => self::text($st['activity'] ?? null),
                'evidencia_presentada' => self::text($st['evidence'] ?? null),
                'feedback_anterior' => self::text($st['feedback'] ?? null),
                'siguiente_accion' => self::text($st['next_action'] ?? null),
                'decisiones_persona' => array_values(array_map('strval', $st['decisions'] ?? [])),
            ],
            // Lo que el plugin guarda de la meta y el documento no nombra.
            'detalle_plugin' => [
                'version_plan' => (int) ($in['pdp']['version'] ?? 0),
                'origen_meta' => (string) ($plugin['origin'] ?? ''),
                'unidad' => self::text($plugin['unit'] ?? null),
                'recurso' => self::text($plugin['resource'] ?? null),
                'actividad' => self::text($plugin['activity'] ?? null),
                'acciones' => self::text($plugin['actions'] ?? null),
                'evidencia_comprometida' => self::text($plugin['committed_evidence'] ?? null),
            ],
        ];
    }

    /**
     * Texto libre depurado y acotado, o null si esta vacio. Funcion pura.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function text($value): ?string {
        if ($value === null) {
            return null;
        }
        $text = trim(pseudonymizer::scrub((string) $value));
        if ($text === '') {
            return null;
        }
        // mb_*, no core_text: esta funcion se verifica sin Moodle cargado.
        return mb_strlen($text) > self::TEXT_LIMIT
            ? mb_substr($text, 0, self::TEXT_LIMIT - 1) . '…'
            : $text;
    }

    /**
     * Problemas del objeto frente al contrato. Funcion pura.
     *
     * Se valida antes de cada llamada al modelo, como recomienda el §4: un objeto
     * que no cumple no viaja. Revisa que esten todas las claves del documento, que
     * no haya ningun campo de identidad y que ningun texto lleve un correo o un RUT.
     *
     * @param array $object Objeto de assemble().
     * @return string[] Descripcion de cada problema; vacio si cumple.
     */
    public static function violations(array $object): array {
        $problems = [];

        foreach (self::DOC_KEYS as $key) {
            if (!array_key_exists($key, $object)) {
                $problems[] = "falta la clave '{$key}'";
            }
        }
        $nested = ['pdp_goal' => self::GOAL_KEYS, 'contexto_autorizado' => self::CONTEXT_KEYS,
            'progreso' => self::PROGRESS_KEYS, 'privacy' => self::PRIVACY_KEYS];
        foreach ($nested as $parent => $keys) {
            foreach ($keys as $key) {
                if (!is_array($object[$parent] ?? null) || !array_key_exists($key, $object[$parent])) {
                    $problems[] = "falta '{$parent}.{$key}'";
                }
            }
        }

        if (!preg_match('/^FAC-[0-9a-f]{8}$/', (string) ($object['user_id_pseudonimizado'] ?? ''))) {
            $problems[] = 'user_id_pseudonimizado no es un seudonimo valido';
        }
        if (!in_array($object['pdp_goal']['learner_decision'] ?? '', self::DECISIONS, true)) {
            $problems[] = 'learner_decision fuera del vocabulario del documento';
        }

        self::walk($object, '', $problems);
        return $problems;
    }

    /**
     * Recorre el objeto buscando campos de identidad y datos personales en los textos.
     *
     * @param mixed $node
     * @param string $path
     * @param string[] $problems
     * @return void
     */
    private static function walk($node, string $path, array &$problems): void {
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                    $problems[] = "campo de identidad '{$path}{$key}'";
                }
                self::walk($value, $path . $key . '.', $problems);
            }
            return;
        }
        if (is_string($node)) {
            if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $node)) {
                $problems[] = "correo en '" . rtrim($path, '.') . "'";
            }
            if (preg_match('/\b\d{1,2}\.?\d{3}\.?\d{3}\s*-\s*[\dkK]\b/', $node)) {
                $problems[] = "RUT en '" . rtrim($path, '.') . "'";
            }
        }
    }

    /**
     * Resumen de los patrones del ultimo intento del quiz, para progreso. Funcion pura.
     *
     * @param array $activations Reglas de quiz_patterns::activations().
     * @return string
     */
    public static function quiz_summary(array $activations): string {
        if (empty($activations)) {
            return 'Ultimo intento enviado: no se activo ninguna regla de apoyo.';
        }
        $parts = [];
        foreach ($activations as $rule) {
            $parts[] = 'error en ' . implode(', ', $rule['activada_por']) . " → foco {$rule['foco']}";
        }
        return 'Ultimo intento enviado (evidencia complementaria, no calificacion): ' . implode('; ', $parts) . '.';
    }

    // ------------------------------------------------------------------
    // Recoleccion: consulta la base.
    // ------------------------------------------------------------------

    /**
     * Recoge los datos de cada meta vigente de la persona.
     *
     * Son las metas que el Coach ya veia en el PDP: aceptadas, modificadas,
     * postergadas y completadas. Una propuesta que la persona no decidio todavia y
     * una meta rechazada no entran.
     *
     * @param int $userid
     * @param int $courseid
     * @return array[] Una entrada por meta, con la forma que espera assemble().
     */
    public static function inputs(int $userid, int $courseid): array {
        global $DB;

        $plan = $DB->get_record('local_chatbot_pdp', ['userid' => $userid, 'courseid' => $courseid]);
        if (!$plan) {
            return [];
        }
        $goals = pdp::list_goals((int) $plan->id,
            [pdp::GOAL_ACEPTADO, pdp::GOAL_MODIFICADO, pdp::GOAL_POSTERGADO, pdp::GOAL_COMPLETADO]);
        if (empty($goals)) {
            return [];
        }

        $states = pdp::goal_states_v5_for($plan, $goals);
        $levels = diagnostic::level_labels_plain();
        $matrix = [];
        foreach (diagnostic::get_gap_matrix($userid, $courseid) as $row) {
            $matrix[(int) $row['dimensionid']] = $row;
        }

        $profile = profile::get($userid, $courseid);
        $context = coach_context::authorized_context($profile);
        $fixed = self::fixed_resources($userid, $courseid);
        $quiz = self::quiz_patterns_summary($userid, $courseid);
        $retention = 'RET-' . ((int) get_config('local_chatbot', 'message_retention_months') ?: 12) . 'm';
        $permissions = self::permissions();
        $pseudoid = pseudonymizer::for_user($userid);
        $labels = adaptive::state_labels_plain();

        $out = [];
        foreach ($goals as $goal) {
            $row = $matrix[(int) $goal->dimensionid] ?? null;
            $dimension = (string) $DB->get_field('local_chatbot_dimension', 'code', ['id' => $goal->dimensionid]);

            $lastadapt = null;
            $lastreview = null;
            $previous = [];
            foreach ($DB->get_records('local_chatbot_adapt', ['goalid' => $goal->id], 'timegenerated ASC') as $adapt) {
                $lastadapt = $adapt;
                foreach (adaptive::list_reviews((int) $adapt->id) as $review) {
                    if (!(int) $review->rejected && (string) $review->evidencesynthesis !== '') {
                        $previous[] = (string) $review->evidencesynthesis;
                        $lastreview = $review;
                    }
                }
            }

            $decisions = $DB->get_fieldset_select('local_chatbot_pdp_history', 'changetype',
                "goalid = ? AND changetype <> 'crear'", [$goal->id], 'id ASC');

            $summary = trim(((string) $goal->brecha !== '' ? $goal->brecha : $dimension)
                . ($row ? " | puntaje compuesto {$row['pc']}, completitud {$row['completitud']}%" : ''));

            $out[] = [
                'pseudoid' => $pseudoid,
                'pdp' => ['id' => (int) $plan->id, 'version' => (int) $plan->version],
                'goal' => [
                    'id' => (int) $goal->id,
                    // La meta no guarda version propia: se cuenta desde sus cambios registrados.
                    'version' => (string) (1 + count($decisions)),
                    'title' => $goal->objetivo,
                    'dimension' => $dimension,
                    'subdimension' => $dimension,
                    'orientation_level' => $row
                        ? $levels[$row['nivel']] . (!empty($row['preliminar']) ? ' (preliminar)' : '') : null,
                    'priority_source' => null,
                    'source_evidence_summary' => $summary,
                    'statement' => $goal->objetivo,
                    'status' => $states[(int) $goal->id] ?? (string) $goal->status,
                    'stored_status' => (string) $goal->status,
                    'timeframe' => !empty($goal->plazo) ? userdate($goal->plazo, '%d/%m/%Y') : null,
                ],
                'context' => [
                    'rol' => $context['rol'] ?? null,
                    'tipo_programa' => $context['tipo_programa'] ?? null,
                    'modalidad' => $context['modalidad'] ?? null,
                    'sector_especialidad' => $context['sector'] ?? null,
                    'participantes' => null,
                    'tiempo_disponible' => $context['tiempo_disponible'] ?? null,
                    'restricciones' => null,
                ],
                'fixed_resources' => $fixed['codes'],
                'progress' => [
                    'consulted' => $fixed['consulted'],
                    'quiz_summary' => $quiz,
                    'previous_evidence' => $previous,
                ],
                'privacy' => [
                    'consent_version' => $profile ? (string) ($profile->consentversion ?? '') : '',
                    'retention_version' => $retention,
                    'permissions' => $permissions,
                ],
                'goal_state' => [
                    'activity' => $lastadapt
                        ? ($labels[$lastadapt->estado] ?? $lastadapt->estado) . ': '
                            . \core_text::substr((string) $lastadapt->consigna, 0, 240)
                        : null,
                    'evidence' => $lastreview ? $lastreview->evidencesynthesis : null,
                    'feedback' => $lastreview ? $lastreview->feedbacksynthesis : null,
                    'next_action' => $lastreview ? $lastreview->nextaction : null,
                    'decisions' => array_slice($decisions, -5),
                ],
                'plugin' => [
                    'origin' => (string) $goal->origin,
                    'unit' => $goal->unidad,
                    'resource' => $goal->recurso,
                    'activity' => $goal->actividad,
                    'actions' => $goal->acciones,
                    'committed_evidence' => $goal->evidencia,
                ],
            ];
        }
        return $out;
    }

    /**
     * Objetos de todas las metas vigentes, ya armados y validados.
     *
     * @param int $userid
     * @param int $courseid
     * @return array[] Objetos que cumplen el contrato; los que no, se descartan.
     */
    public static function objects(int $userid, int $courseid): array {
        $objects = [];
        foreach (self::inputs($userid, $courseid) as $input) {
            $object = self::assemble($input);
            $problems = self::violations($object);
            if (!empty($problems)) {
                debugging('local_chatbot: objeto de meta descartado por el contrato: '
                    . implode('; ', $problems), DEBUG_DEVELOPER);
                continue;
            }
            $objects[] = $object;
        }
        return $objects;
    }

    /**
     * El objeto de una meta puntual, para armar una propuesta de apoyo.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $goalid
     * @return array|null Null si la meta no esta entre las vigentes o no cumple el contrato.
     */
    public static function object_for_goal(int $userid, int $courseid, int $goalid): ?array {
        foreach (self::objects($userid, $courseid) as $object) {
            if ((int) $object['pdp_goal']['pdp_goal_id'] === $goalid) {
                return $object;
            }
        }
        return null;
    }

    /**
     * Los objetos como bloque de prompt del Coach.
     *
     * @param int $userid
     * @param int $courseid
     * @return string Vacio si la persona no tiene metas vigentes.
     */
    public static function as_prompt_context(int $userid, int $courseid): string {
        $objects = self::objects($userid, $courseid);
        if (empty($objects)) {
            return '';
        }
        $json = json_encode($objects, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return "OBJETO DE DATOS AUTORIZADO DEL COACH POR META (Anexo F §4, momento b). Hay "
            . count($objects) . " meta(s) vigente(s); cada elemento es una:\n" . $json . "\n"
            . "Trabaja sobre la meta que la persona nombre; si no nombra ninguna y hay una sola, esa."
            . " goal_status 'postergada' sigue vigente: la persona la dejo para mas adelante, no la"
            . " descarto. estado_meta y progreso son todo lo que sabes de sus evidencias y su avance:"
            . " no inventes evidencias ni resultados. detalle_plugin son datos de la propia meta."
            . " Una meta que la persona no decidio todavia no esta aqui.";
    }

    // ------------------------------------------------------------------
    // Auxiliares de recoleccion.
    // ------------------------------------------------------------------

    /**
     * Codigos de los recursos fijos visibles para la persona y cuales ya consulto.
     *
     * @param int $userid
     * @param int $courseid
     * @return array Con claves codes y consulted.
     */
    private static function fixed_resources(int $userid, int $courseid): array {
        global $CFG;

        $result = ['codes' => [], 'consulted' => []];
        if ($courseid <= 0) {
            return $result;
        }
        try {
            require_once($CFG->libdir . '/completionlib.php');
            $completion = new \completion_info(get_course($courseid));
            foreach (get_fast_modinfo($courseid, $userid)->get_cms() as $cm) {
                $code = trim((string) $cm->idnumber);
                if (!$cm->uservisible || !preg_match('/^U\d+-(?:LF|CA|ER|QF)-\d+$/', $code)) {
                    continue;
                }
                $result['codes'][] = $code;
                if ($completion->is_enabled($cm)) {
                    $data = $completion->get_data($cm, false, $userid);
                    if ((int) $data->completionstate > 0 || !empty($data->viewed)) {
                        $result['consulted'][] = $code;
                    }
                }
            }
        } catch (\Throwable $e) {
            debugging('local_chatbot: no se pudieron leer los recursos fijos: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return $result;
    }

    /**
     * @param int $userid
     * @param int $courseid
     * @return string|null Resumen de los patrones del ultimo intento, o null si no hay intentos.
     */
    private static function quiz_patterns_summary(int $userid, int $courseid): ?string {
        global $DB;

        $last = $DB->get_field_sql(
            'SELECT MAX(attemptid) FROM {local_chatbot_quiz_pattern} WHERE userid = ? AND courseid = ?',
            [$userid, $courseid]);
        if (!$last) {
            return null;
        }
        $wrong = $DB->get_fieldset_select('local_chatbot_quiz_pattern', 'questioncode',
            "attemptid = ? AND responsestatus <> 'correcta'", [$last]);
        return self::quiz_summary(quiz_patterns::activations($wrong));
    }

    /**
     * Permisos vigentes del Coach, segun la matriz de acceso.
     *
     * @return string[]
     */
    private static function permissions(): array {
        $out = [];
        foreach (['profile', 'gaps', 'diag_responses', 'unit_content', 'quiz_results', 'pdp', 'project',
                'rubric', 'resource_catalog', 'adaptive_supports'] as $object) {
            $access = assistant::can_access(assistant::MODE_COACH, $object);
            if ($access !== false) {
                $out[] = is_string($access) ? "{$object}:{$access}" : $object;
            }
        }
        return $out;
    }
}
