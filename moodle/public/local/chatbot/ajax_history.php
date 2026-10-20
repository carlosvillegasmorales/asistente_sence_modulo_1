<?php
/**
 * Devuelve el historial de conversacion de la persona en un modo funcional,
 * para que el widget restaure la conversacion al reabrirse.
 *
 * El historial esta separado por modo a proposito: cambiar de modo abre un
 * hilo distinto en vez de mezclar una sesion de tutoria con una de evaluacion.
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

// Hilo de una meta, o chat general (classes/thread.php).
$goalid = \local_chatbot\thread::goal_for((int) $USER->id, $courseid, optional_param('goalid', 0, PARAM_INT));

$history = [];
try {
    [$hilosql, $hiloparams] = \local_chatbot\thread::where($goalid);
    // Los 50 ultimos, en orden: con mas de 50 mensajes se mostraban los 50 primeros y la
    // conversacion reciente no aparecia al reabrir.
    $messages = array_reverse($DB->get_records_select('local_chatbot_messages',
        "userid = :userid AND courseid = :courseid AND mode = :mode AND {$hilosql}",
        ['userid' => $USER->id, 'courseid' => $courseid, 'mode' => $mode] + $hiloparams,
        'timecreated DESC, id DESC', '*', 0, 50));

    foreach ($messages as $entry) {
        $history[] = [
            'message' => $entry->message,
            'is_bot' => $entry->is_bot,
        ];
    }
} catch (Exception $e) {
    debugging('local_chatbot: no se pudo leer el historial: ' . $e->getMessage(), DEBUG_DEVELOPER);
}

echo json_encode(['history' => $history]);
