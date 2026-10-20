<?php
/**
 * Borra el historial de conversacion de la persona en un modo funcional: sus
 * propios mensajes y los archivos que hubiera adjuntado. Nunca afecta el
 * historial de otra persona.
 *
 * El registro de interaccion (modo, recomendacion y decision) no se borra
 * aqui: es la evidencia de auditoria del asistente, no contenido conversacional.
 */

define('AJAX_SCRIPT', true);
require_once('../../config.php');

require_login();
require_sesskey();
require_capability('local/chatbot:use', context_system::instance());

$courseid = optional_param('courseid', 0, PARAM_INT);
$mode = optional_param('mode', \local_chatbot\assistant::MODE_TUTOR, PARAM_ALPHA);

if (!\local_chatbot\assistant::is_valid_mode($mode)) {
    $mode = \local_chatbot\assistant::MODE_TUTOR;
}

global $USER, $DB;

// Se borra lo que la persona tiene a la vista: el hilo de la meta abierta, o el chat general.
$goalid = \local_chatbot\thread::goal_for((int) $USER->id, $courseid, optional_param('goalid', 0, PARAM_INT));

$deleted = 0;
try {
    [$hilosql, $hiloparams] = \local_chatbot\thread::where($goalid);
    $where = "userid = :userid AND courseid = :courseid AND mode = :mode AND {$hilosql}";
    $params = ['userid' => $USER->id, 'courseid' => $courseid, 'mode' => $mode] + $hiloparams;
    $messages = $DB->get_records_select('local_chatbot_messages', $where, $params, '', 'id, attachment_filename');

    $fs = get_file_storage();
    foreach ($messages as $entry) {
        if (!empty($entry->attachment_filename)) {
            $fs->delete_area_files(context_system::instance()->id, 'local_chatbot',
                'chat_attachment', $entry->id);
        }
    }

    $deleted = count($messages);
    $DB->delete_records_select('local_chatbot_messages', $where, $params);
} catch (Exception $e) {
    debugging('local_chatbot: no se pudo borrar el historial: ' . $e->getMessage(), DEBUG_DEVELOPER);
    echo json_encode(['success' => false]);
    die();
}

echo json_encode(['success' => true, 'deleted' => $deleted]);
