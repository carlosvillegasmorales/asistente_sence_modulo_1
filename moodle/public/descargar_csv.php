<?php
require_once(__DIR__ . '/config.php');

require_login(); // Security

$csv_file = $CFG->dataroot . '/reporte_fase4_consolidado.csv';

if (!file_exists($csv_file)) {
    die("El reporte consolidado de la Fase 4 aún no ha sido generado. Por favor, corre el script de consolidación primero.");
}

// Set headers for download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reporte_fase4_consolidado.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// Output file contents
readfile($csv_file);
exit;
