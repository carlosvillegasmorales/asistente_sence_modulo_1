<?php
/**
 * Sonda de estado para las pruebas automáticas (ejecutar_pruebas.py). Solo lectura,
 * salvo el comando quiz_finish, que termina el intento abierto de una persona de prueba.
 *
 * Uso: php sonda.php <comando> [args]  → imprime JSON
 *   estado                       ids, metas, planes, apoyos, revisiones, matrices, proyecto, cierre
 *   interacciones <usuario> <ts> interacciones y mensajes registrados desde ts
 *   disponibilidad <usuario>     qué recursos de la Unidad 1 están disponibles para la persona
 *   quiz_finish <usuario> <n_errores>  responde el intento abierto (n primeras preguntas mal) y lo envía
 */
define('CLI_SCRIPT', true);
// MOODLE_CFG permite apuntar a otro servidor (el del cliente instala Moodle en
// /var/www/moodle, no en /var/www/html). Sin la variable, el destino de siempre.
require(getenv('MOODLE_CFG') ?: '/var/www/html/config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
global $DB;

$cmd = $argv[1] ?? 'estado';
$course = $DB->get_record('course', ['shortname' => 'PRUEBA-M1-U1'], '*', MUST_EXIST);
$cid = (int) $course->id;
$users = $DB->get_records_menu('user', ['idnumber' => 'PLAN-PRUEBA-M1', 'deleted' => 0], '', 'username, id');
$out = ['courseid' => $cid, 'users' => $users];

function uid(string $u) { global $users; if (!isset($users[$u])) { throw new Exception("usuario $u"); } return (int) $users[$u]; }

if ($cmd === 'estado') {
    foreach ($users as $u => $id) {
        $r = ['profile' => $DB->get_record('local_chatbot_profile', ['userid' => $id, 'courseid' => $cid],
            'consentaccepted, consenttime, rol, sector, tiempo')];
        $plan = $DB->get_record('local_chatbot_pdp', ['userid' => $id, 'courseid' => $cid]);
        $r['pdp'] = $plan ? ['id' => $plan->id, 'status' => $plan->status, 'version' => $plan->version] : null;
        $r['goals'] = $plan ? array_values($DB->get_records('local_chatbot_pdp_goal', ['pdpid' => $plan->id], 'id',
            'id, status, origin, objetivo, evidencia, plazo, unidad, recurso, microcapsula')) : [];
        $r['history'] = $plan ? array_values($DB->get_records('local_chatbot_pdp_history', ['pdpid' => $plan->id], 'id',
            'id, goalid, changetype, reason')) : [];
        $r['adapts'] = array_values($DB->get_records('local_chatbot_adapt', ['userid' => $id, 'courseid' => $cid], 'id',
            'id, goalid, estado, consigna, criterios'));
        foreach ($r['adapts'] as $a) {
            $a->reviews = array_values($DB->get_records('local_chatbot_adapt_review', ['adaptid' => $a->id], 'id'));
        }
        $rep = $DB->get_records('local_chatbot_coach_report', ['userid' => $id, 'courseid' => $cid], 'id DESC', '*', 0, 1);
        $r['coach_report'] = $rep ? reset($rep)->content : null;
        $p = $DB->get_record('local_chatbot_project', ['userid' => $id, 'courseid' => $cid]);
        $r['project'] = $p ? ['id' => $p->id, 'status' => $p->status, 'version' => $p->version,
            'feedback' => array_values($DB->get_records('local_chatbot_project_fb', ['projectid' => $p->id]))] : null;
        $r['closure'] = $DB->get_record('local_chatbot_closure', ['userid' => $id, 'courseid' => $cid]);
        $r['diag_responses'] = $DB->count_records('local_chatbot_diag_response', ['userid' => $id, 'courseid' => $cid]);
        $r['quiz_attempts'] = array_values($DB->get_records_sql(
            'SELECT qa.id, qa.attempt, qa.state, qa.sumgrades FROM {quiz_attempts} qa JOIN {quiz} q ON q.id = qa.quiz
              WHERE q.course = ? AND qa.userid = ? ORDER BY qa.id', [$cid, $id]));
        $out['personas'][$u] = $r;
    }
} else if ($cmd === 'interacciones') {
    $id = uid($argv[2]);
    $since = (int) ($argv[3] ?? 0);
    $out['interacciones'] = array_values($DB->get_records_select('local_chatbot_interaction',
        'userid = ? AND timecreated >= ?', [$id, $since], 'id',
        'id, mode, intent, resources, guardrail, status, pseudoid, model, promptversion, recommendation,'
        . ' interactioncode, sourceresource, concepttags, sourcesreferred, quizguard'));
    $out['mensajes_con_texto_en_interaccion'] = $DB->count_records_select('local_chatbot_interaction',
        'userid = ? AND timecreated >= ? AND ' . $DB->sql_like('recommendation', '?'), [$id, $since, '%RUT%']);
} else if ($cmd === 'disponibilidad') {
    $id = uid($argv[2]);
    $modinfo = get_fast_modinfo($cid, $id);
    foreach ($modinfo->get_cms() as $cm) {
        if ($cm->idnumber) {
            $out['recursos'][$cm->idnumber] = ['cmid' => (int) $cm->id, 'uservisible' => $cm->uservisible,
                'available' => $cm->available];
        }
    }
} else if ($cmd === 'trazas') {
    // Trazabilidad del Anexo G en el curso de prueba: eventos por codigo, patrones del quiz,
    // registro del Tutor e indicadores del §11.
    $out['eventos'] = $DB->get_records_sql_menu('SELECT eventcode, COUNT(id) FROM {local_chatbot_trace}
        WHERE courseid = ? GROUP BY eventcode ORDER BY eventcode', [$cid]);
    $out['objetos_ejemplo'] = array_values($DB->get_fieldset_sql("SELECT DISTINCT objectcode FROM {local_chatbot_trace}
        WHERE courseid = ? AND objectcode IS NOT NULL", [$cid]));
    $out['quiz_patrones'] = array_values($DB->get_records_sql('SELECT id, userid, attemptnumber, questioncode, subdimension,
        responsestatus FROM {local_chatbot_quiz_pattern} WHERE courseid = ?', [$cid]));
    $out['registro_tutor'] = array_values($DB->get_records_select('local_chatbot_interaction',
        "courseid = ? AND mode = 'tutor'", [$cid], 'id', 'id, interactioncode, sourceresource, intent, concepttags, sourcesreferred, quizguard'));
    $out['indicadores'] = \local_chatbot\analytics::annex_g_indicators($cid);
    $out['evidencias'] = array_values($DB->get_fieldset_sql('SELECT r.evidenceid FROM {local_chatbot_adapt_review} r
        JOIN {local_chatbot_adapt} a ON a.id = r.adaptid WHERE a.courseid = ?', [$cid]));
} else if ($cmd === 'quiz_finish') {
    $id = uid($argv[2]);
    $nerrores = (int) ($argv[3] ?? 0);
    $attempt = $DB->get_record_sql("SELECT qa.* FROM {quiz_attempts} qa JOIN {quiz} q ON q.id = qa.quiz
        WHERE q.course = ? AND qa.userid = ? AND qa.state = 'inprogress'", [$cid, $id], MUST_EXIST);
    \core\session\manager::set_user($DB->get_record('user', ['id' => $id]));
    $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
    $resp = [];
    $plan = [];
    foreach ($attemptobj->get_slots() as $i => $slot) {
        $q = $attemptobj->get_question_attempt($slot)->get_question();
        $correct = null;
        $wrong = null;
        foreach ($q->answers as $ans) {
            if ($ans->fraction > 0.9) { $correct = $ans->answer; } else if ($wrong === null) { $wrong = $ans->answer; }
        }
        $mal = $i < $nerrores;
        $resp[$slot] = ['answer' => $mal ? $wrong : $correct];
        $plan[] = ($q->idnumber ?? $slot) . ($mal ? ':mal' : ':bien');
    }
    $attemptobj->process_submitted_actions(time(), false, $resp);
    $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
    $attemptobj->process_submit(time(), false);
    $attemptobj->process_grade_submission(time());
    $out['intento'] = $attempt->id;
    $out['respuestas'] = $plan;
    $out['sumgrades'] = $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attempt->id]);
    $out['state'] = $DB->get_field('quiz_attempts', 'state', ['id' => $attempt->id]);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
