<?php
/**
 * Informe de los indicadores del Modulo 1 en PDF: las conclusiones y las cifras principales, para enviarlas sin que
 * quien las recibe entre al panel. Solo datos agregados y anonimos.
 *
 * @package local_chatbot
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('local/chatbot:viewanalytics', context_system::instance());
require_sesskey();

$courseid = \local_chatbot\analytics::dashboard_course(optional_param('courseid', 0, PARAM_INT));
$datos = \local_chatbot\dashboard_report::data($courseid);
$pdf = \local_chatbot\dashboard_report::pdf($datos);

$nombre = 'Informe_Indicadores_Modulo1_' . date('Y-m-d') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
