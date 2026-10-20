<?php
/**
 * Registra que hizo la persona con una recomendacion del asistente: la acepto,
 * la modifico o la descarto.
 *
 * Existe porque la regla de gobernanza no se conforma con que la recomendacion
 * "pueda" revisarse: el modulo tiene que poder mostrar despues cuantas se
 * aceptaron, cuantas se modificaron y cuantas se rechazaron. Sin este endpoint
 * ese indicador no se puede calcular.
 */

define('AJAX_SCRIPT', true);
require_once('../../config.php');

require_login();
require_sesskey();
require_capability('local/chatbot:use', context_system::instance());

$interactionid = required_param('interactionid', PARAM_INT);
$decision = required_param('decision', PARAM_INT);

global $USER;

// set_decision() exige que la interaccion sea de quien responde, asi que nadie
// puede decidir por otra persona aunque adivine el id.
$ok = \local_chatbot\interaction::set_decision($interactionid, $USER->id, $decision);

echo json_encode(['success' => $ok]);
