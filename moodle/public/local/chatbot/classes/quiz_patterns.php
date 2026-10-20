<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Patrones del quiz formativo para el apoyo adaptativo (Anexo G §5.1 y §8.3; Anexo D §9).
 *
 * Al enviarse un intento se guarda el resultado por pregunta, identificada por su idnumber
 * (U1-QF-01-P04). Las reglas de activacion convierten los errores en un foco sugerido para
 * el Coach. Son evidencia complementaria: no modifican metas ni el diagnostico, y el Coach
 * las presenta como propuesta revisable.
 */
class quiz_patterns {

    /** @var array Subdimension principal de cada pregunta del quiz U1-QF-01 (Anexo G §4). */
    const SUBDIMENSION = [
        'U1-QF-01-P01' => 'D1.1', 'U1-QF-01-P02' => 'D1.1', 'U1-QF-01-P03' => 'D1.2', 'U1-QF-01-P04' => 'D1.2',
        'U1-QF-01-P05' => 'D1.1', 'U1-QF-01-P06' => 'D1.2', 'U1-QF-01-P07' => 'D1.1', 'U1-QF-01-P08' => 'D1.2',
        'U1-QF-01-P09' => 'D1.2', 'U1-QF-01-P10' => 'D1.1',
    ];

    /**
     * Reglas de activacion del Anexo G §5.1. Se usa "y/o", la version del documento de
     * trazabilidad; el Anexo D dice "y" para P01/P07 (pendiente P-06 con el cliente).
     *
     * @return array[] Cada regla: preguntas, foco, hipotesis formativa e intervencion inicial.
     */
    public static function rules(): array {
        return [
            ['preguntas' => ['P01', 'P07'], 'foco' => 'D1.1',
                'hipotesis' => 'Dificultad para distinguir desempeño laboral y aprendizaje esperado formativo',
                'intervencion' => 'Solicitar una situación de su contexto y proponer comparar el desempeño global con el aprendizaje esperado de una sesión inicial'],
            ['preguntas' => ['P02'], 'foco' => 'D1.1 o D1.2 según la meta vigente',
                'hipotesis' => 'Dificultad para analizar la función de exposición, manual, demostración y práctica dentro de una secuencia',
                'intervencion' => 'Pedir revisar una actividad propia e identificar la función de cada recurso y la evidencia que se busca producir'],
            ['preguntas' => ['P03', 'P04', 'P06'], 'foco' => 'D1.2',
                'hipotesis' => 'Dificultad para considerar condiciones, viabilidad, progresión, autonomía o complejidad',
                'intervencion' => 'Proponer una actividad de diseño o ajuste contextualizado según tiempo, recursos, participantes y restricciones'],
            ['preguntas' => ['P05', 'P09'], 'foco' => 'D1.1, con proyección a D1.2',
                'hipotesis' => 'Confusión entre evidencia, instrumento y actividad',
                'intervencion' => 'Generar un ejercicio breve que vincule capacidad, actividad, evidencia e instrumento, sin entrar al diseño completo de la Unidad 3'],
            ['preguntas' => ['P08'], 'foco' => 'D1.2',
                'hipotesis' => 'Dificultad para transferir la lógica de diseño entre sectores',
                'intervencion' => 'Solicitar traducir una situación de la Unidad 1 a su sector, identificando las condiciones y decisiones que cambian'],
            ['preguntas' => ['P10'], 'foco' => 'D1.1 y D1.2',
                'hipotesis' => 'Dificultad para revisar la coherencia interna de una actividad',
                'intervencion' => 'Proponer un análisis guiado de una actividad propia mediante preguntas de coherencia'],
        ];
    }

    /**
     * Reglas que se activan con un conjunto de preguntas incorrectas. Funcion pura.
     *
     * @param string[] $wrong Codigos de pregunta incorrectos (U1-QF-01-P05 o P05).
     * @return array[] Reglas activadas, con las preguntas que la activaron.
     */
    public static function activations(array $wrong): array {
        $short = [];
        foreach ($wrong as $code) {
            if (preg_match('/(P\d{2})$/', (string) $code, $m)) {
                $short[$m[1]] = true;
            }
        }
        $active = [];
        foreach (self::rules() as $rule) {
            $hits = array_values(array_filter($rule['preguntas'], fn($p) => isset($short[$p])));
            if (!empty($hits)) {
                $active[] = $rule + ['activada_por' => $hits];
            }
        }
        return $active;
    }

    /**
     * Guarda el resultado por pregunta de un intento enviado.
     *
     * @param int $attemptid
     * @return int Preguntas registradas.
     */
    public static function record_attempt(int $attemptid): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
        if (!$attempt || $DB->record_exists('local_chatbot_quiz_pattern', ['attemptid' => $attemptid])) {
            return 0;
        }
        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], 'id, course');
        $attemptobj = \mod_quiz\quiz_attempt::create($attemptid);
        $now = time();
        $n = 0;
        $wrong = [];
        foreach ($attemptobj->get_slots() as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            $code = trim((string) ($qa->get_question()->idnumber ?? ''));
            if ($code === '') {
                continue;
            }
            $fraction = $qa->get_fraction();
            $status = $fraction === null ? 'omitida' : ($fraction >= 0.999 ? 'correcta' : 'incorrecta');
            if ($status !== 'correcta') {
                $wrong[] = $code;
            }
            $DB->insert_record('local_chatbot_quiz_pattern', (object) [
                'attemptid' => $attemptid, 'quizid' => $quiz->id, 'userid' => $attempt->userid,
                'courseid' => $quiz->course, 'attemptnumber' => $attempt->attempt, 'questioncode' => $code,
                'subdimension' => self::SUBDIMENSION[$code] ?? null, 'responsestatus' => $status, 'timecreated' => $now,
            ]);
            $n++;
        }
        if ($n > 0) {
            $unit = trace::unit_from_hint($wrong[0] ?? array_key_first(self::SUBDIMENSION));
            trace::log(trace::QUIZ_SUBMITTED, (int) $attempt->userid, (int) $quiz->course, $unit, [
                'intento' => (int) $attempt->attempt, 'preguntas' => $n, 'incorrectas' => count($wrong),
                'reglas_activadas' => array_map(fn($r) => implode('+', $r['activada_por']), self::activations($wrong)),
            ]);
        }
        return $n;
    }

    /**
     * Bloque para el contexto del Coach: patrones del ultimo intento enviado, como evidencia
     * complementaria (Anexo D §9, Anexo G §5.1). Vacio si no hay intentos enviados.
     *
     * @param int $userid
     * @param int $courseid
     * @return string
     */
    public static function coach_block(int $userid, int $courseid): string {
        global $DB;
        $last = $DB->get_field_sql('SELECT MAX(attemptid) FROM {local_chatbot_quiz_pattern} WHERE userid = ? AND courseid = ?',
            [$userid, $courseid]);
        if (!$last) {
            return '';
        }
        $wrong = $DB->get_fieldset_select('local_chatbot_quiz_pattern', 'questioncode',
            "attemptid = ? AND responsestatus <> 'correcta'", [$last]);
        $rules = self::activations($wrong);
        if (empty($rules)) {
            return "PATRONES DEL QUIZ FORMATIVO: en el ultimo intento enviado no se activo ninguna regla de apoyo.";
        }
        $lines = [];
        foreach ($rules as $r) {
            $lines[] = '- Error en ' . implode(', ', $r['activada_por']) . " → foco {$r['foco']}: {$r['hipotesis']}."
                . " Intervencion inicial posible: {$r['intervencion']}.";
        }
        return "PATRONES DEL QUIZ FORMATIVO (ultimo intento enviado; evidencia complementaria):\n"
            . implode("\n", $lines) . "\n"
            . "No reemplazan la matriz individual ni modifican metas por si solos. No uses el puntaje como"
            . " calificacion. Presenta cualquier apoyo como propuesta revisable y pide la decision de la persona.";
    }
}
