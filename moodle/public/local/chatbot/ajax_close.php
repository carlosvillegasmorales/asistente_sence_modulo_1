<?php
/**
 * Registra el cierre del Tutor (EVT-IA-T-U1-CLOSE, Anexo G §7.2, RU-TRZ-003).
 *
 * El widget lo avisa con navigator.sendBeacon al cerrarse o al salir de la pagina. No
 * devuelve contenido: el navegador no espera respuesta. Solo registra si hubo consultas al
 * Tutor despues del ultimo cierre, y los datos son etiquetas y codigos, nunca texto.
 */

define('AJAX_SCRIPT', true);
require_once('../../config.php');

require_login();
require_sesskey();
require_capability('local/chatbot:use', context_system::instance());

$courseid = optional_param('courseid', 0, PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);

global $USER, $DB;

try {
    $mode = \local_chatbot\assistant::MODE_TUTOR;
    $last = $DB->get_records('local_chatbot_interaction',
        ['userid' => $USER->id, 'courseid' => $courseid, 'mode' => $mode], 'timecreated DESC, id DESC', '*', 0, 1);
    $last = $last ? reset($last) : null;

    $source = \local_chatbot\trace::source_resource($courseid, $cmid);
    $unit = $source !== '' ? \local_chatbot\trace::unit_from_hint($source) : \local_chatbot\trace::unit_for_course($courseid);

    $lastclose = (int) $DB->get_field_select('local_chatbot_trace', 'MAX(timecreated)',
        'userid = ? AND courseid = ? AND eventcode = ?',
        [$USER->id, $courseid, str_replace('{U}', $unit, \local_chatbot\trace::T_CLOSE)]);

    if ($last && \local_chatbot\trace::should_log_close((int) $last->timecreated, $lastclose)) {
        \local_chatbot\trace::log(\local_chatbot\trace::T_CLOSE, $USER->id, $courseid, $unit,
            \local_chatbot\trace::close_data((string) $last->concepttags, (string) $last->sourcesreferred,
                (string) $last->intent),
            (string) $last->interactioncode);
    }
} catch (\Throwable $e) {
    debugging('local_chatbot: no se pudo registrar el cierre: ' . $e->getMessage(), DEBUG_DEVELOPER);
}

http_response_code(204);
