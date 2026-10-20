<?php
/**
 * Deshace cargar_plan_prueba.php: borra el curso PRUEBA-M1-U1, las personas de prueba
 * (idnumber PLAN-PRUEBA-M1), todos sus registros del asistente, el instrumento propio del
 * curso y los documentos U1-* de la base de conocimiento.
 *
 * Uso (dentro del contenedor, como www-data):  php borrar_plan_prueba.php [--confirmar]
 * Sin --confirmar solo muestra lo que borraría.
 */
define('CLI_SCRIPT', true);
require(getenv('MOODLE_CFG') ?: '/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');

global $DB;
\core\session\manager::set_user(get_admin());
$confirmar = in_array('--confirmar', $argv, true);

$course = $DB->get_record('course', ['shortname' => 'PRUEBA-M1-U1']);
$users = $DB->get_records('user', ['idnumber' => 'PLAN-PRUEBA-M1', 'deleted' => 0], '', 'id, username');
$uids = array_keys($users);
$kbdocs = $DB->get_records_select('local_chatbot_kb_documents', "filename LIKE 'U1-%'", null, '', 'id, filename');

echo 'Curso: ' . ($course ? "{$course->id} {$course->shortname}" : '(no existe)') . "\n";
echo 'Personas: ' . (implode(', ', array_column($users, 'username')) ?: '(ninguna)') . "\n";
echo 'Documentos KB U1-*: ' . count($kbdocs) . "\n";
if (!$confirmar) {
    echo "\nModo simulación. Agrega --confirmar para borrar.\n";
    exit(0);
}

$courseid = $course ? (int) $course->id : -1;
if ($uids) {
    [$in, $params] = $DB->get_in_or_equal($uids);
    $pdpids = $DB->get_fieldset_select('local_chatbot_pdp', 'id', "userid $in", $params);
    if ($pdpids) {
        [$inp, $pp] = $DB->get_in_or_equal($pdpids);
        $DB->delete_records_select('local_chatbot_pdp_goal', "pdpid $inp", $pp);
        $DB->delete_records_select('local_chatbot_pdp_history', "pdpid $inp", $pp);
    }
    $adaptids = $DB->get_fieldset_select('local_chatbot_adapt', 'id', "userid $in", $params);
    if ($adaptids) {
        [$ina, $pa] = $DB->get_in_or_equal($adaptids);
        $DB->delete_records_select('local_chatbot_adapt_review', "adaptid $ina", $pa);
    }
    $projids = $DB->get_fieldset_select('local_chatbot_project', 'id', "userid $in", $params);
    if ($projids) {
        [$inj, $pj] = $DB->get_in_or_equal($projids);
        $DB->delete_records_select('local_chatbot_project_fb', "projectid $inj", $pj);
    }
    foreach (['profile', 'diag_response', 'pdp', 'project', 'messages', 'interaction', 'closure',
            'coach_report', 'adapt'] as $t) {
        $DB->delete_records_select("local_chatbot_$t", "userid $in", $params);
    }
}
if ($course) {
    $dimids = $DB->get_fieldset_select('local_chatbot_dimension', 'id', 'courseid = ?', [$courseid]);
    if ($dimids) {
        [$ind, $pd] = $DB->get_in_or_equal($dimids);
        $DB->delete_records_select('local_chatbot_diag_item', "dimensionid $ind", $pd);
    }
    $DB->delete_records('local_chatbot_dimension', ['courseid' => $courseid]);
    foreach (['messages', 'interaction'] as $t) {
        $DB->delete_records("local_chatbot_$t", ['courseid' => $courseid]);
    }
    delete_course($course, false);
    echo "Curso borrado\n";
}
foreach ($users as $u) {
    user_delete_user($DB->get_record('user', ['id' => $u->id]));
}
echo count($users) . " personas borradas\n";
// Con --sin-kb no se tocan los documentos U1-*. La base de conocimiento es del
// sitio, no del curso de pruebas: si el sitio ya tiene cargados los recursos
// reales del cliente, borrarlos aqui se lleva por delante las cuatro fuentes
// prioritarias del Tutor, que no forman parte de las fixtures.
if (in_array('--sin-kb', $argv, true)) {
    echo "Base de conocimiento: sin cambios (--sin-kb), " . count($kbdocs) . " documentos U1-* conservados\n";
} else if ($kbdocs) {
    [$ink, $pk] = $DB->get_in_or_equal(array_keys($kbdocs));
    $DB->delete_records_select('local_chatbot_kb_documents', "id $ink", $pk);
    echo count($kbdocs) . " documentos KB borrados\n";
}
purge_all_caches();
echo "Listo.\n";
