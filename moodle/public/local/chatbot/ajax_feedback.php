<?php
/**
 * Guarda la valoracion de una respuesta del asistente (util / no util y motivo).
 *
 * Solo acepta interacciones de quien vota; el voto se puede cambiar.
 */

define('AJAX_SCRIPT', true);
require_once('../../config.php');

require_login();
require_sesskey();
require_capability('local/chatbot:use', context_system::instance());

$interactionid = required_param('interactionid', PARAM_INT);
$vote = required_param('vote', PARAM_INT);
$reason = optional_param('reason', '', PARAM_ALPHANUMEXT);

global $USER;

$ok = \local_chatbot\ux::on('feedback')
    && \local_chatbot\feedback::save((int) $USER->id, $interactionid, $vote, $reason);

echo json_encode(['ok' => $ok]);
