<?php
/**
 * Instala la Unidad 1 del cliente en un curso existente (no solo en el curso de prueba).
 *
 * Carga, a partir del paquete generado por construir_paquete.py:
 *   - el instrumento del Diagnostico (Doc 2: D1 a D4, 32 declarativos y S1 a S6) como
 *     instrumento propio del curso, con los criterios de verificacion (Anexo F para D1);
 *   - la base de conocimiento del Tutor (Anexos A, B y C, y los criterios del quiz sin claves);
 *   - con --con-recursos, las paginas de lectura, caso y ejemplo y el quiz de 10 preguntas
 *     (Anexo D) en una seccion del curso, con finalizacion y restricciones del Doc 6.0.
 *
 * No crea personas ni datos de uso. Es idempotente por partes: si el curso ya tiene
 * instrumento propio o recursos U1, los deja como estan (salvo --reemplazar-instrumento).
 *
 * Uso (dentro del contenedor, como www-data):
 *   php instalar_unidad1.php --curso=ID [--con-recursos] [--seccion=N] [--reemplazar-instrumento] /tmp/paquete_u1.json
 */
define('CLI_SCRIPT', true);
if (file_exists('/var/www/html/config.php')) {
    require('/var/www/html/config.php');
} else if (file_exists(__DIR__ . '/../../moodle/public/config.php')) {
    require(__DIR__ . '/../../moodle/public/config.php');
} else {
    fwrite(STDERR, "Error: no se encontró config.php de Moodle.\n");
    exit(1);
}
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

[$options, $unrecognised] = cli_get_params(
    ['curso' => 0, 'con-recursos' => false, 'seccion' => 1, 'reemplazar-instrumento' => false, 'help' => false],
    ['h' => 'help']);
$paquetepath = end($unrecognised) ?: '/tmp/paquete_u1.json';
if ($options['help'] || empty($options['curso'])) {
    echo "Uso: php instalar_unidad1.php --curso=ID [--con-recursos] [--seccion=N] [--reemplazar-instrumento] paquete.json\n";
    exit(0);
}

global $DB;
\core\session\manager::set_user(get_admin());
$course = $DB->get_record('course', ['id' => (int) $options['curso']], '*', MUST_EXIST);
$courseid = (int) $course->id;
$paquete = json_decode(file_get_contents($paquetepath), true);
if (!$paquete) {
    cli_error("No se pudo leer el paquete {$paquetepath}");
}
$log = function (string $m) { echo '[' . date('H:i:s') . "] $m\n"; };
$log("Curso {$courseid}: {$course->fullname}");

// ------------------------------------------------------------------ instrumento
$existentes = $DB->get_records('local_chatbot_dimension', ['courseid' => $courseid]);
if ($existentes && !$options['reemplazar-instrumento']) {
    $log('Instrumento: el curso ya tiene ' . count($existentes) . ' dimensiones propias; no se modifica.');
} else {
    if ($existentes) {
        if ($DB->record_exists('local_chatbot_diag_response', ['courseid' => $courseid])) {
            cli_error('El curso ya tiene respuestas de diagnostico: reemplazar el instrumento las dejaria huerfanas.');
        }
        [$in, $p] = $DB->get_in_or_equal(array_keys($existentes));
        $DB->delete_records_select('local_chatbot_diag_item', "dimensionid $in", $p);
        $DB->delete_records('local_chatbot_dimension', ['courseid' => $courseid]);
    }
    $orden = 0;
    $items = 0;
    foreach ($paquete['diagnostico'] as $code => $dim) {
        $dimid = $DB->insert_record('local_chatbot_dimension', (object) [
            'courseid' => $courseid, 'code' => $code, 'name' => $dim['name'],
            'description' => 'Instrumento del Diagnóstico Autoaplicado de Brechas Metodológicas (Doc 2).',
            'criterios' => implode("\n", $dim['criterios']), 'sortorder' => $orden++, 'timecreated' => time()]);
        $n = 0;
        foreach ($dim['items'] as $it) {
            $DB->insert_record('local_chatbot_diag_item', (object) ['dimensionid' => $dimid, 'statement' => $it['enunciado'],
                'kind' => 'declarativa', 'options' => null, 'correctoption' => null, 'sortorder' => $n++, 'timecreated' => time()]);
            $items++;
        }
        foreach ($dim['sit'] as $s) {
            $DB->insert_record('local_chatbot_diag_item', (object) ['dimensionid' => $dimid, 'statement' => $s['enunciado'],
                'kind' => 'situacional', 'options' => json_encode($s['opciones'], JSON_UNESCAPED_UNICODE),
                'correctoption' => $s['correcta'], 'sortorder' => $n++, 'timecreated' => time()]);
            $items++;
        }
    }
    $log("Instrumento: 4 dimensiones y {$items} ítems cargados.");
}

// ------------------------------------------------------------------ base de conocimiento
foreach ($paquete['kb'] as $doc) {
    $activo = $DB->get_record('local_chatbot_kb_documents', ['filename' => $doc['filename'], 'active' => 1]);
    if ($activo && $activo->content === $doc['content']) {
        $log("Base de conocimiento: «{$doc['filename']}» ya está vigente.");
        continue;
    }
    // add_document rechaza documentos con claves de evaluacion (RU-KB-001).
    \local_chatbot\kb_store::add_document($doc['filename'], $doc['content']);
    $log("Base de conocimiento: «{$doc['filename']}» cargado" . ($activo ? ' (nueva versión)' : '') . '.');
}

// ------------------------------------------------------------------ recursos
if (!$options['con-recursos']) {
    $log('Recursos: omitidos (agrega --con-recursos para crear las páginas y el quiz).');
    exit(0);
}
if ($DB->record_exists_select('course_modules', "course = ? AND idnumber LIKE 'U1-%'", [$courseid])) {
    $log('Recursos: el curso ya tiene actividades U1-*; no se duplican.');
    exit(0);
}
// La finalizacion tiene que estar habilitada ANTES de crear las actividades: si no, Moodle las
// guarda sin criterio de finalizacion y las restricciones de acceso nunca se desbloquean.
if (!$course->enablecompletion) {
    $DB->set_field('course', 'enablecompletion', 1, ['id' => $courseid]);
    $course->enablecompletion = 1;
    rebuild_course_cache($courseid, true);
    $log('Finalización de actividades habilitada en el curso (necesaria para las restricciones).');
}
$section = (int) $options['seccion'];
course_create_sections_if_missing($course, $section);
$gen = new testing_data_generator();
$cms = [];
foreach (['U1-LF-01', 'U1-CA-01', 'U1-ER-01'] as $codigo) {
    $p = $paquete['paginas'][$codigo];
    $page = $gen->create_module('page', ['course' => $courseid, 'section' => $section, 'name' => $p['nombre'],
        'idnumber' => $codigo, 'intro' => "<p>Recurso fijo <code>$codigo</code></p>", 'content' => $p['html'],
        'contentformat' => FORMAT_HTML, 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1]);
    $cms[$codigo] = (int) $page->cmid;
}
$quiz = $gen->create_module('quiz', ['course' => $courseid, 'section' => $section,
    'name' => 'Quiz formativo: fundamentos y diseño de actividades', 'idnumber' => 'U1-QF-01',
    'intro' => '<p>Tendrás dos intentos. Después de cada intento recibirás retroalimentación para revisar tu razonamiento.'
        . ' El resultado se usa para tu aprendizaje y para orientar apoyos de tu PDP; no es una calificación laboral,'
        . ' contractual ni certificadora.</p>',
    'attempts' => 2, 'grademethod' => QUIZ_GRADEHIGHEST, 'preferredbehaviour' => 'deferredfeedback',
    'questionsperpage' => 1, 'navmethod' => 'free', 'grade' => 10,
    'attemptduring' => 1, 'correctnessduring' => 0, 'maxmarksduring' => 1, 'marksduring' => 0,
    'specificfeedbackduring' => 0, 'generalfeedbackduring' => 0, 'rightanswerduring' => 0, 'overallfeedbackduring' => 0,
    'attemptimmediately' => 1, 'correctnessimmediately' => 1, 'maxmarksimmediately' => 1, 'marksimmediately' => 1,
    'specificfeedbackimmediately' => 1, 'generalfeedbackimmediately' => 0, 'rightanswerimmediately' => 0,
    'overallfeedbackimmediately' => 1,
    'attemptopen' => 1, 'correctnessopen' => 1, 'maxmarksopen' => 1, 'marksopen' => 1, 'specificfeedbackopen' => 1,
    'generalfeedbackopen' => 0, 'rightansweropen' => 0, 'overallfeedbackopen' => 1,
    'attemptclosed' => 1, 'correctnessclosed' => 1, 'maxmarksclosed' => 1, 'marksclosed' => 1,
    'specificfeedbackclosed' => 1, 'generalfeedbackclosed' => 1, 'rightanswerclosed' => 1, 'overallfeedbackclosed' => 1,
    'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1]);
$cms['U1-QF-01'] = (int) $quiz->cmid;
$qbank = $gen->create_module('qbank', ['course' => $courseid, 'name' => 'Banco de preguntas Unidad 1']);
$cat = $gen->get_plugin_generator('core_question')->create_question_category([
    'contextid' => context_module::instance($qbank->cmid)->id, 'name' => 'U1-QF-01 Quiz formativo Unidad 1']);
foreach ($paquete['quiz'] as $p) {
    $answers = $fractions = $feedback = [];
    foreach ($p['opciones'] as $i => $op) {
        $answers[$i] = ['text' => $op, 'format' => FORMAT_PLAIN];
        $fractions[$i] = ($i === (int) $p['correcta']) ? '1.0' : '0.0';
        $feedback[$i] = ['text' => $p['retroalimentacion'][$i], 'format' => FORMAT_HTML];
    }
    $form = (object) ['category' => $cat->id, 'name' => "{$p['codigo']} · {$p['titulo']}", 'idnumber' => $p['codigo'],
        'questiontext' => ['text' => '<p>' . nl2br(s($p['enunciado'])) . '</p>', 'format' => FORMAT_HTML],
        'generalfeedback' => ['text' => '<p>Criterio: ' . s($p['criterio']) . ' · ' . s($p['dimension']) . '</p>',
            'format' => FORMAT_HTML],
        'answer' => $answers, 'fraction' => $fractions, 'feedback' => $feedback, 'noanswers' => 4, 'penalty' => 0,
        'defaultmark' => 1, 'shuffleanswers' => 0, 'answernumbering' => 'ABCD', 'single' => 1, 'shownumcorrect' => 0,
        'showstandardinstruction' => 0,
        'correctfeedback' => ['text' => '', 'format' => FORMAT_HTML],
        'partiallycorrectfeedback' => ['text' => '', 'format' => FORMAT_HTML],
        'incorrectfeedback' => ['text' => '', 'format' => FORMAT_HTML],
        'status' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY];
    $q = question_bank::get_qtype('multichoice')->save_question((object) ['qtype' => 'multichoice'], $form);
    quiz_add_quiz_question($q->id, $quiz, 0, 1);
}
$DB->set_field('quiz_sections', 'shufflequestions', 1, ['quizid' => $quiz->id]);
\mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
foreach ([[8, 11, 'alto'], [5, 8, 'intermedio'], [0, 5, 'inicial']] as [$min, $max, $tramo]) {
    $DB->insert_record('quiz_feedback', (object) ['quizid' => $quiz->id, 'feedbacktextformat' => FORMAT_HTML,
        'feedbacktext' => "<p>Desempeño {$tramo}: revisa los comentarios por pregunta y, si lo deseas, analiza una situación"
            . ' de tu contexto con el asistente IA.</p>', 'mingrade' => $min, 'maxgrade' => $max]);
}
$cond = fn(array $codes) => json_encode(['op' => '&',
    'c' => array_map(fn($c) => ['type' => 'completion', 'cm' => $cms[$c], 'e' => 1], $codes),
    'showc' => array_fill(0, count($codes), true)]);
$DB->set_field('course_modules', 'availability', $cond(['U1-LF-01']), ['id' => $cms['U1-CA-01']]);
$DB->set_field('course_modules', 'availability', $cond(['U1-CA-01']), ['id' => $cms['U1-ER-01']]);
$DB->set_field('course_modules', 'availability', $cond(['U1-LF-01', 'U1-CA-01', 'U1-ER-01']), ['id' => $cms['U1-QF-01']]);
rebuild_course_cache($courseid, true);
$log("Recursos: 3 páginas y el quiz de 10 preguntas creados en la sección {$section}, con restricciones de acceso.");
