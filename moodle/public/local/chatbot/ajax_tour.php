<?php
/**
 * Marca que la persona ya vio el recorrido guiado, para no volver a mostrarlo.
 */

define('AJAX_SCRIPT', true);
require_once('../../config.php');

require_login();
require_sesskey();

set_user_preference('local_chatbot_tour_done', time());

echo json_encode(['ok' => true]);
