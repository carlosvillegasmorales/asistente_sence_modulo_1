<?php
/**
 * Carga el plan de prueba del flujo completo del Modulo 1 (Unidad 1).
 *
 * Crea, en un curso aislado y con usuarios marcados como prueba:
 *   - Curso PRUEBA-M1-U1 con la Unidad 1 del cliente: lectura, caso, ejemplo y quiz de
 *     10 preguntas (Anexos A a D), con finalizacion y restricciones de acceso del Doc 6.0.
 *   - El Diagnostico real del Doc 2 como instrumento propio del curso (D1 a D4, S1 a S6).
 *   - La base de conocimiento del Tutor con A, B, C y el Anexo D SANEADO (sin claves).
 *   - Cinco personas de prueba en distintos puntos del flujo (ver $PERFILES).
 *
 * Uso (dentro del contenedor, como www-data):
 *   php cargar_plan_prueba.php /tmp/paquete_u1.json [--sin-ia]
 *
 * --sin-ia  no genera matrices del Coach ni apoyos adaptativos (no llama al modelo).
 *
 * Deshacer: borrar_plan_prueba.php
 */
define('CLI_SCRIPT', true);
require(getenv('MOODLE_CFG') ?: '/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use local_chatbot\profile;
use local_chatbot\diagnostic;
use local_chatbot\pdp;
use local_chatbot\kb_store;
use local_chatbot\coach_report;
use local_chatbot\adaptive;
use local_chatbot\project;

const CURSO_CORTO = 'PRUEBA-M1-U1';
const CLAVE = 'Prueba.2026!';

$paquete = json_decode(file_get_contents($argv[1] ?? '/tmp/paquete_u1.json'), true);
if (!$paquete) {
    cli_error('No se pudo leer el paquete JSON.');
}
$sinia = in_array('--sin-ia', $argv, true);

global $DB;
\core\session\manager::set_user(get_admin());

if ($DB->record_exists('course', ['shortname' => CURSO_CORTO])) {
    cli_error('El curso ' . CURSO_CORTO . ' ya existe. Ejecuta borrar_plan_prueba.php antes de volver a cargar.');
}

$gen = new testing_data_generator();
$t0 = time();
$log = function (string $msg) { echo '[' . date('H:i:s') . "] $msg\n"; };

// ---------------------------------------------------------------------------------------
// 1. Curso y secciones
// ---------------------------------------------------------------------------------------
$course = $gen->create_course([
    'shortname' => CURSO_CORTO,
    'fullname' => 'PRUEBA · Módulo 1 — Unidad 1: Formación con enfoque en competencias',
    'summary' => '<p><strong>Curso de prueba.</strong> Recorrido completo del Módulo 1 para validar el asistente IA '
        . 'contra el SPEC v5 y los casos del Anexo H. No usar con participantes reales.</p>',
    'format' => 'topics',
    'numsections' => 2,
    'enablecompletion' => 1,
    'category' => 1,
]);
$courseid = (int) $course->id;
$log("Curso creado: id=$courseid");

$DB->set_field('course_sections', 'name', 'Inicio del Módulo 1', ['course' => $courseid, 'section' => 1]);
$DB->set_field('course_sections', 'name', 'Unidad 1 · Formación con enfoque en competencias y diseño de actividades',
    ['course' => $courseid, 'section' => 2]);

$url = fn(string $page) => (new moodle_url("/local/chatbot/$page", ['courseid' => $courseid]))->out(false);

$gen->create_module('label', ['course' => $courseid, 'section' => 0, 'intro' =>
    '<div style="border:2px solid #0b4582;border-radius:8px;padding:12px">'
    . '<h4>Plan de prueba del flujo completo</h4>'
    . '<p>Este curso existe solo para probar el Módulo 1. Ingresa con una de las personas de prueba '
    . '(ver guion <code>docs/PLAN_PRUEBAS_FLUJO_COMPLETO.md</code>). El asistente IA está disponible con el botón '
    . 'flotante en todas las páginas del curso.</p></div>']);

$gen->create_module('label', ['course' => $courseid, 'section' => 1, 'intro' =>
    '<h5>Recorrido del Módulo 1</h5><ol>'
    . '<li><a href="' . $url('index.php') . '">Bienvenida, perfilamiento y consentimiento informado</a></li>'
    . '<li><a href="' . $url('diagnostico.php') . '">Diagnóstico de prácticas y matriz de desarrollo</a></li>'
    . '<li><a href="' . $url('pdp.php') . '">Mi desarrollo: Plan Personal de Desarrollo</a></li>'
    . '<li><a href="' . $url('proyecto.php') . '">Proyecto integrador</a></li>'
    . '<li><a href="' . $url('cierre.php') . '">Seguimiento y cierre</a></li></ol>']);

// ---------------------------------------------------------------------------------------
// 2. Unidad 1: recursos fijos (Doc 6.0 §5)
// ---------------------------------------------------------------------------------------
$gen->create_module('label', ['course' => $courseid, 'section' => 2, 'intro' =>
    '<p><strong>Pregunta orientadora:</strong> ¿Cómo se traduce el enfoque de competencias en una actividad de '
    . 'aprendizaje situada?</p>'
    . '<p><strong>Aprendizaje esperado:</strong> Analizar los fundamentos de la formación con enfoque de competencias '
    . 'y justificar el diseño de una actividad de aprendizaje contextualizada, considerando el desempeño esperado, las '
    . 'características de las personas participantes y las evidencias que permitirán observar su avance.</p>'
    . '<p><strong>Criterios de evaluación:</strong></p><ol>'
    . '<li>Distingue entre contenidos de apoyo y desempeños observables esperados en una experiencia formativa.</li>'
    . '<li>Relaciona aprendizajes esperados con situaciones o tareas propias de un contexto de aplicación.</li>'
    . '<li>Diferencia aprendizaje esperado, criterio de desempeño, evidencia de logro e instrumento de evaluación.</li>'
    . '<li>Organiza una secuencia inicial de actividades coherente con el aprendizaje esperado.</li>'
    . '<li>Justifica una situación de práctica auténtica, simulada o contextualizada para favorecer la demostración de aprendizaje.</li>'
    . '<li>Comunica con claridad qué deben realizar las personas participantes y cómo se observará su avance.</li></ol>'
    . '<p>Recorrido: lectura → caso → ejemplo de resolución → quiz formativo. Si una idea no queda clara, consulta al '
    . 'asistente IA en modo Tutor desde cualquiera de los recursos.</p>']);

$cms = [];
foreach (['U1-LF-01', 'U1-CA-01', 'U1-ER-01'] as $codigo) {
    $p = $paquete['paginas'][$codigo];
    $page = $gen->create_module('page', [
        'course' => $courseid, 'section' => 2,
        'name' => $p['nombre'],
        'idnumber' => $codigo,
        'intro' => "<p>Recurso fijo <code>$codigo</code></p>",
        'content' => $p['html'], 'contentformat' => FORMAT_HTML,
        'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1,
    ]);
    $cms[$codigo] = (int) $page->cmid;
    $log("Página $codigo cmid={$page->cmid}");
}

// Quiz U1-QF-01 (Anexo D §4): 2 intentos, calificación más alta, retroalimentación por alternativa
// después de cada intento, clave explícita solo al cierre, preguntas aleatorias, alternativas fijas.
$quiz = $gen->create_module('quiz', [
    'course' => $courseid, 'section' => 2,
    'name' => 'Quiz formativo: fundamentos y diseño de actividades',
    'idnumber' => 'U1-QF-01',
    'intro' => '<p>Este quiz te invita a analizar decisiones de diseño de actividades a partir de la lectura, el caso y '
        . 'el ejemplo de resolución de la Unidad 1.</p><p>No se trata de seleccionar la alternativa que suena más cercana '
        . 'a una idea general sobre aprendizaje activo. En cada pregunta, revisa qué opción responde mejor al desempeño '
        . 'esperado, a las condiciones del contexto, a la progresión de la experiencia y a la evidencia de avance que se '
        . 'necesita observar.</p><p>Tendrás dos intentos. Después de cada intento recibirás retroalimentación para revisar '
        . 'tu razonamiento y, si es necesario, volver a los recursos de la unidad antes de responder nuevamente.</p>'
        . '<p>El resultado se utiliza para tu aprendizaje y para orientar apoyos dentro de tu Plan Personal de Desarrollo. '
        . 'No constituye una calificación laboral, contractual ni certificadora.</p>',
    'attempts' => 2,
    'grademethod' => QUIZ_GRADEHIGHEST,
    'preferredbehaviour' => 'deferredfeedback',
    'questionsperpage' => 1,
    'navmethod' => 'free',
    'grade' => 10,
    'attemptduring' => 1, 'correctnessduring' => 0, 'maxmarksduring' => 1, 'marksduring' => 0,
    'specificfeedbackduring' => 0, 'generalfeedbackduring' => 0, 'rightanswerduring' => 0, 'overallfeedbackduring' => 0,
    'attemptimmediately' => 1, 'correctnessimmediately' => 1, 'maxmarksimmediately' => 1, 'marksimmediately' => 1,
    'specificfeedbackimmediately' => 1, 'generalfeedbackimmediately' => 0, 'rightanswerimmediately' => 0,
    'overallfeedbackimmediately' => 1,
    'attemptopen' => 1, 'correctnessopen' => 1, 'maxmarksopen' => 1, 'marksopen' => 1,
    'specificfeedbackopen' => 1, 'generalfeedbackopen' => 0, 'rightansweropen' => 0, 'overallfeedbackopen' => 1,
    'attemptclosed' => 1, 'correctnessclosed' => 1, 'maxmarksclosed' => 1, 'marksclosed' => 1,
    'specificfeedbackclosed' => 1, 'generalfeedbackclosed' => 1, 'rightanswerclosed' => 1, 'overallfeedbackclosed' => 1,
    'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1,
]);
$cms['U1-QF-01'] = (int) $quiz->cmid;
$log("Quiz U1-QF-01 cmid={$quiz->cmid}");

// Banco de preguntas propio del curso (Moodle 5: las categorías viven en una instancia qbank).
$qgen = $gen->get_plugin_generator('core_question');
$qbank = $gen->create_module('qbank', ['course' => $courseid, 'name' => 'Banco de preguntas Unidad 1']);
$cat = $qgen->create_question_category([
    'contextid' => context_module::instance($qbank->cmid)->id,
    'name' => 'U1-QF-01 Quiz formativo Unidad 1',
]);
foreach ($paquete['quiz'] as $p) {
    $answers = [];
    $fractions = [];
    $feedback = [];
    foreach ($p['opciones'] as $i => $op) {
        $answers[$i] = ['text' => $op, 'format' => FORMAT_PLAIN];
        $fractions[$i] = ($i === (int) $p['correcta']) ? '1.0' : '0.0';
        $feedback[$i] = ['text' => $p['retroalimentacion'][$i], 'format' => FORMAT_HTML];
    }
    // API estándar del tipo de pregunta (el generador de pruebas de Moodle depende de PHPUnit).
    $form = (object) [
        'category' => $cat->id,
        'name' => "{$p['codigo']} · {$p['titulo']}",
        'idnumber' => $p['codigo'],
        'questiontext' => ['text' => '<p>' . nl2br(s($p['enunciado'])) . '</p>', 'format' => FORMAT_HTML],
        'generalfeedback' => ['text' => '<p>Criterio: ' . s($p['criterio']) . ' · ' . s($p['dimension']) . '</p>',
            'format' => FORMAT_HTML],
        'answer' => $answers, 'fraction' => $fractions, 'feedback' => $feedback,
        'noanswers' => 4, 'penalty' => 0, 'defaultmark' => 1,
        'shuffleanswers' => 0, 'answernumbering' => 'ABCD', 'single' => 1, 'shownumcorrect' => 0,
        'showstandardinstruction' => 0,
        'correctfeedback' => ['text' => '', 'format' => FORMAT_HTML],
        'partiallycorrectfeedback' => ['text' => '', 'format' => FORMAT_HTML],
        'incorrectfeedback' => ['text' => '', 'format' => FORMAT_HTML],
        'status' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
    ];
    $q = question_bank::get_qtype('multichoice')->save_question((object) ['qtype' => 'multichoice'], $form);
    quiz_add_quiz_question($q->id, $quiz, 0, 1);
}
$DB->set_field('quiz_sections', 'shufflequestions', 1, ['quizid' => $quiz->id]);
\mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
// Retroalimentación general por tramo (Anexo D §6). Los umbrales no vienen en el documento:
// alto >= 8, intermedio 5 a 7, inicial < 5 (supuesto declarado en el guion).
$tramos = [
    [8, 11, 'Tus respuestas muestran una comprensión sólida de la relación entre desempeño de referencia, aprendizaje esperado, condiciones de implementación, actividad situada y evidencia de avance. Revisa los comentarios por pregunta para identificar matices que puedas transferir a tu propio contexto de facilitación.'],
    [5, 8, 'Tus respuestas muestran comprensión de varios principios de diseño con enfoque de competencias. Te recomendamos revisar especialmente las preguntas en las que fue necesario distinguir entre recursos de apoyo y evidencia de aprendizaje, o entre un desempeño laboral global y un aprendizaje esperado adecuado al nivel y condiciones de una sesión.'],
    [0, 5, 'Este resultado indica oportunidades para profundizar la relación entre desempeño, aprendizaje esperado, actividad situada, condiciones de realización y evidencia de avance. Revisa el documento de lectura y el ejemplo de resolución. Luego, si lo deseas, utiliza el asistente IA para analizar una situación de tu propio contexto paso a paso.'],
];
foreach ($tramos as [$min, $max, $texto]) {
    $DB->insert_record('quiz_feedback', (object) ['quizid' => $quiz->id, 'feedbacktext' => "<p>$texto</p>",
        'feedbacktextformat' => FORMAT_HTML, 'mingrade' => $min, 'maxgrade' => $max]);
}
$log('Quiz: 10 preguntas agregadas, sumgrades=' . $DB->get_field('quiz', 'sumgrades', ['id' => $quiz->id]));

// Restricciones de acceso (Doc 6.0 §5.2): caso tras lectura, ejemplo tras caso, quiz tras los tres.
$cond = fn(array $codes) => json_encode([
    'op' => '&',
    'c' => array_map(fn($c) => ['type' => 'completion', 'cm' => $cms[$c], 'e' => 1], $codes),
    'showc' => array_fill(0, count($codes), true),
]);
$DB->set_field('course_modules', 'availability', $cond(['U1-LF-01']), ['id' => $cms['U1-CA-01']]);
$DB->set_field('course_modules', 'availability', $cond(['U1-CA-01']), ['id' => $cms['U1-ER-01']]);
$DB->set_field('course_modules', 'availability', $cond(['U1-LF-01', 'U1-CA-01', 'U1-ER-01']), ['id' => $cms['U1-QF-01']]);
rebuild_course_cache($courseid, true);
$log('Restricciones de acceso configuradas');

// ---------------------------------------------------------------------------------------
// 3. Instrumento del Diagnóstico (Doc 2) propio del curso
// ---------------------------------------------------------------------------------------
$dimids = [];
$itemids = [];   // codigo de item => id
$orden = 0;
foreach ($paquete['diagnostico'] as $code => $dim) {
    $dimid = $DB->insert_record('local_chatbot_dimension', (object) [
        'courseid' => $courseid, 'code' => $code, 'name' => $dim['name'],
        'description' => 'Instrumento del Diagnóstico Autoaplicado de Brechas Metodológicas (Doc 2).',
        'criterios' => implode("\n", $dim['criterios']), 'sortorder' => $orden++, 'timecreated' => time(),
    ]);
    $dimids[$code] = $dimid;
    $n = 0;
    foreach ($dim['items'] as $it) {
        $itemids[$it['codigo']] = $DB->insert_record('local_chatbot_diag_item', (object) [
            'dimensionid' => $dimid, 'statement' => $it['enunciado'], 'kind' => 'declarativa',
            'options' => null, 'correctoption' => null, 'sortorder' => $n++, 'timecreated' => time()]);
    }
    foreach ($dim['sit'] as $s) {
        $itemids[$s['codigo']] = $DB->insert_record('local_chatbot_diag_item', (object) [
            'dimensionid' => $dimid, 'statement' => $s['enunciado'], 'kind' => 'situacional',
            'options' => json_encode($s['opciones'], JSON_UNESCAPED_UNICODE), 'correctoption' => $s['correcta'],
            'sortorder' => $n++, 'timecreated' => time()]);
    }
}
$log('Diagnóstico: ' . count($dimids) . ' dimensiones, ' . count($itemids) . ' ítems');

// ---------------------------------------------------------------------------------------
// 4. Base de conocimiento del Tutor
// ---------------------------------------------------------------------------------------
// Con --sin-kb no se toca la base de conocimiento. Hace falta cuando el sitio ya
// tiene cargados los documentos reales del cliente: add_document versiona por
// nombre de archivo, así que sembrar las fixtures dejaría vigente el texto
// simplificado del paquete en lugar del recurso real, y sin su PDF asociado.
if (in_array('--sin-kb', $argv, true)) {
    $log('Base de conocimiento: sin cambios (--sin-kb), ' . count(kb_store::list_documents())
        . ' documentos vigentes');
} else {
    foreach ($paquete['kb'] as $doc) {
        kb_store::add_document($doc['filename'], $doc['content']);
        if (strpos($doc['content'], 'Alternativa correcta') !== false) {
            cli_error('ABORTO: un documento de la base de conocimiento contiene claves del quiz.');
        }
    }
    $log('Base de conocimiento: ' . count($paquete['kb']) . ' documentos (' . kb_store::total_size() . ' bytes activos)');
}

// ---------------------------------------------------------------------------------------
// 5. Personas de prueba
// ---------------------------------------------------------------------------------------
// Respuestas: D1..D4 = lista de puntajes declarativos en orden; S = mapa situacional => acierto.
// Los puntajes estan elegidos para caer en un nivel concreto (PCd = 0,7 PDd + 0,3 CSd).
$PERFILES = [
    'prueba.nueva' => ['nombre' => 'Nueva', 'etapa' => 'sin_datos',
        'desc' => 'Parte de cero: consentimiento, perfil, diagnóstico, matriz y PDP los hace quien prueba.'],
    'prueba.a' => ['nombre' => 'Perfil A', 'etapa' => 'matriz',
        'desc' => 'Anexo H Perfil A: D1 Prioridad alta. Diagnóstico enviado y matriz generada; PDP sin crear.',
        'perfil' => ['rol' => 'relator', 'experiencia' => 'entre1y3', 'modalidad' => 'presencial', 'tiempo' => 'entre1y2',
            'sector' => 'Logística y distribución', 'contexto' => 'Cursos de operación de bodega para personal nuevo',
            'necesidad' => 'Me cuesta pasar del procedimiento a una actividad práctica que pueda observar.'],
        'D1' => [2, 2, 1, 2, 1, 2, 2, 2], 'D2' => [3, 4, 3, 4, 3, 4], 'D3' => array_fill(0, 11, 3), 'D4' => array_fill(0, 7, 4),
        'S' => ['S1' => false, 'S2' => false, 'S3' => true, 'S4' => true, 'S5' => false, 'S6' => true]],
    'prueba.b' => ['nombre' => 'Perfil B', 'etapa' => 'pdp_activo',
        'desc' => 'Anexo H Perfil B: D1 En desarrollo, poco tiempo y recursos. PDP activo con una meta D1: listo para el Coach.',
        'perfil' => ['rol' => 'facilitador', 'experiencia' => 'entre3y10', 'modalidad' => 'online', 'tiempo' => 'menos1',
            'sector' => 'Comercio y servicios', 'contexto' => 'Talleres virtuales de atención de clientes',
            'necesidad' => 'Tengo poco tiempo de práctica y no puedo llevar al grupo a una tienda real.'],
        'D1' => [3, 3, 3, 2, 3, 3, 2, 3], 'D2' => [4, 3, 4, 3, 4, 3], 'D3' => array_fill(0, 11, 4), 'D4' => [4, 3, 4, 3, 4, 3, 4],
        'S' => ['S1' => true, 'S2' => false, 'S3' => true, 'S4' => true, 'S5' => true, 'S6' => true]],
    'prueba.c' => ['nombre' => 'Perfil C', 'etapa' => 'apoyo_iniciado',
        'desc' => 'Anexo H Perfil C: D1 Funcional. PDP activo, apoyo adaptativo iniciado (listo para entregar evidencia) y proyecto en borrador.',
        'perfil' => ['rol' => 'instructor', 'experiencia' => 'mas10', 'modalidad' => 'mixta', 'tiempo' => 'entre3y4',
            'sector' => 'Salud y cuidados', 'contexto' => 'Formación de asistentes de cuidado en residencias',
            'necesidad' => 'Quiero revisar críticamente mis actividades y proyectarlas al proyecto integrador.'],
        'D1' => [4, 3, 4, 3, 4, 3, 4, 3], 'D2' => array_fill(0, 6, 4), 'D3' => array_fill(0, 11, 4), 'D4' => [5, 4, 5, 4, 5, 4, 5],
        'S' => ['S1' => true, 'S2' => false, 'S3' => true, 'S4' => true, 'S5' => true, 'S6' => true]],
    'prueba.incompleta' => ['nombre' => 'Incompleta', 'etapa' => 'matriz',
        'desc' => 'CP-IA-C-06: D2 y D3 bajo el 80% de completitud (matriz preliminar). Debe proponer una sola meta exploratoria.',
        'perfil' => ['rol' => 'docente', 'experiencia' => 'menos1', 'modalidad' => 'presencial', 'tiempo' => 'entre1y2',
            'sector' => 'Construcción', 'contexto' => 'Curso de seguridad en obra',
            'necesidad' => 'Estoy empezando a facilitar y no sé por dónde partir.'],
        'D1' => [3, 3, 3, 3, 3, 3, 3, 3], 'D2' => [3, 3], 'D3' => [3, 3, 3, 3, 3], 'D4' => array_fill(0, 7, 3),
        'S' => ['S1' => true, 'S2' => false, 'S4' => true, 'S6' => true]],
];

$studentrole = $DB->get_field('role', 'id', ['shortname' => 'student']);
$resumen = [];
foreach ($PERFILES as $username => $pf) {
    $user = $gen->create_user([
        'username' => $username, 'password' => CLAVE,
        'firstname' => 'Prueba', 'lastname' => $pf['nombre'],
        'email' => "$username@example.invalid", 'description' => $pf['desc'],
        'idnumber' => 'PLAN-PRUEBA-M1',
    ]);
    $gen->enrol_user($user->id, $courseid, $studentrole);
    $uid = (int) $user->id;
    $linea = "$username (id=$uid): {$pf['desc']}";

    if ($pf['etapa'] !== 'sin_datos') {
        profile::save($uid, $courseid, $pf['perfil'] + ['consent' => 1]);

        $answers = [];
        foreach (['D1', 'D2', 'D3', 'D4'] as $d) {
            foreach ($pf[$d] as $i => $score) {
                $answers[$itemids[$d . '.' . ($i + 1)]] = $score;
            }
        }
        foreach ($pf['S'] as $scode => $acierto) {
            $sit = null;
            foreach ($paquete['diagnostico'] as $dim) {
                foreach ($dim['sit'] as $s) {
                    if ($s['codigo'] === $scode) { $sit = $s; }
                }
            }
            $answers[$itemids[$scode]] = $acierto ? $sit['correcta'] : (($sit['correcta'] + 1) % 4);
        }
        diagnostic::save_responses($uid, $courseid, $answers);

        $niveles = [];
        foreach (diagnostic::get_gap_matrix($uid, $courseid) as $row) {
            $niveles[] = $row['code'] . '=' . number_format((float) $row['pc'], 2) . ' ' . $row['nivel']
                . ($row['preliminar'] ? " (PRELIMINAR {$row['completitud']}%)" : '');
        }
        $linea .= "\n      matriz: " . implode(' · ', $niveles);

        if (!$sinia) {
            $r = coach_report::generate($uid, $courseid);
            $linea .= "\n      matriz del Coach: " . ($r['ok'] ? "generada (id {$r['reportid']})" : 'ERROR ' . ($r['message'] ?? ''));
        }
    }

    if (in_array($pf['etapa'], ['pdp_activo', 'apoyo_iniciado'], true)) {
        $plan = pdp::get_or_create($uid, $courseid);
        $meta = $username === 'prueba.b'
            ? ['objetivo' => 'Diseñar una actividad de práctica contextualizada para un aprendizaje esperado de mi taller virtual de atención de clientes, incorporando una acción observable, condiciones de realización y una evidencia de avance.',
               'brecha' => 'D1 en desarrollo: dificultad para contextualizar actividades y definir evidencia observable.',
               'acciones' => "Revisar el Caso de Análisis U1-CA-01 y el Ejemplo de Resolución U1-ER-01.\nDiseñar una simulación breve de atención por videollamada.",
               'evidencia' => 'Propuesta de actividad con situación, acción esperada, condiciones y evidencia de avance.',
               'actividad' => 'Simulación de atención de un cliente en modalidad online con una incidencia definida.']
            : ['objetivo' => 'Revisar críticamente una actividad existente de mi curso de asistentes de cuidado y ajustarla para hacerla más situada y progresiva, proyectándola al proyecto integrador.',
               'brecha' => 'D1 funcional: oportunidad de profundizar en progresión y coherencia interna del diseño.',
               'acciones' => "Revisar el apartado 4 del Documento de Lectura U1-LF-01.\nAnalizar una actividad propia con los criterios de revisión.",
               'evidencia' => 'Actividad existente ajustada, con justificación de progresión y evidencia de avance.',
               'actividad' => 'Revisión crítica de una actividad de práctica de movilización segura de personas.'];
        $goalid = pdp::add_goal($plan->id, $meta + [
            'dimensionid' => $dimids['D1'], 'unidad' => 'Unidad 1', 'recurso' => 'U1-CA-01 Caso de Análisis',
            'plazo' => time() + 21 * DAYSECS], pdp::ORIGIN_IA, $uid);
        pdp::decide_goal($goalid, pdp::GOAL_ACEPTADO, $uid, 'Aceptada por la persona (carga del plan de prueba)');
        $v = pdp::validate($plan->id, $uid);
        $linea .= "\n      PDP: meta $goalid aceptada; plan " . ($v['ok'] ? 'validado (activo)' : 'NO validado');

        if ($pf['etapa'] === 'apoyo_iniciado' && !$sinia) {
            $a = adaptive::propose($uid, $courseid, $goalid);
            if ($a['ok']) {
                adaptive::start($a['adaptid'], $uid);
                $linea .= "\n      apoyo adaptativo {$a['adaptid']} propuesto e iniciado";
            } else {
                $linea .= "\n      apoyo adaptativo: ERROR {$a['message']}";
            }
        }
    }

    if ($pf['etapa'] === 'apoyo_iniciado') {
        project::save($uid, $courseid, [
            'title' => 'Rediseño de la práctica de movilización segura de personas mayores',
            'perfilucl' => 'Perfil de asistente de cuidado: UCL de apoyo en actividades de la vida diaria.',
            'aprendizajes' => 'Ejecutar, en una situación simulada y supervisada, la movilización segura de una persona con movilidad reducida, aplicando el protocolo y comunicando cualquier incidencia.',
            'criterios' => 'Verifica condiciones del entorno antes de movilizar; aplica la técnica según protocolo; se comunica con la persona durante el procedimiento; registra incidencias.',
            'actividades' => 'Demostración comentada; práctica por parejas con maniquí; estación con incidencia (resistencia de la persona); práctica final con mayor autonomía.',
            'evidencias' => 'Ejecución observada en la estación con incidencia y registro breve de la decisión tomada.',
            'instrumentos' => 'Lista de cotejo con los criterios de desempeño y pauta de registro de incidencias.',
            'mejora' => 'Retroalimentación inmediata por criterio; ajuste de la secuencia si más de un tercio del grupo falla en la verificación del entorno.',
            'autoevaluacion' => 'Creo que la evidencia está bien definida, pero no estoy segura de que la progresión sea suficiente.',
        ]);
        $linea .= "\n      proyecto integrador en borrador";
    }
    $resumen[] = $linea;
}

echo "\n==================== RESUMEN ====================\n";
echo "Curso: {$course->fullname}\n  " . course_get_url($courseid)->out(false) . "\n";
echo "Clave de todas las personas de prueba: " . CLAVE . "\n\n";
echo implode("\n\n", $resumen) . "\n";
echo "\nDuración: " . (time() - $t0) . " s\n";
