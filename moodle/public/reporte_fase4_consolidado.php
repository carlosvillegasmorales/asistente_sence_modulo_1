<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');

mtrace("Consolidando Analítica de Piloto Fase 2...");

$csv_file = $CFG->dataroot . '/reporte_fase4_consolidado.csv';
$fp = fopen($csv_file, 'w');

// Escribir cabeceras CSV (BOM para Excel)
fputs($fp, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF)));
fputcsv($fp, ['ID_Usuario', 'Nombre_Completo', 'Estado_RAP_M4', 'Clics_Interaccion', 'Ultimo_Acceso', 'Promedio_General_Sistema']);

// Get all users
$users = $DB->get_records('user', ['deleted' => 0]);
$rap_field = $DB->get_record('user_info_field', ['shortname' => 'estado_rap']);

$count = 0;
foreach ($users as $u) {
    if ($u->id <= 2) continue; // Skip admin
    
    // 1. RAP Status (Módulo 4)
    $is_rap = 'No';
    if ($rap_field) {
        $rap_data = $DB->get_record('user_info_data', ['userid' => $u->id, 'fieldid' => $rap_field->id]);
        if ($rap_data && $rap_data->data == 1) {
            $is_rap = 'Si';
        }
    }
    
    // 2. Interacciones
    $clics = $DB->count_records('logstore_standard_log', ['userid' => $u->id]);
    
    // 3. Notas
    $sql_grades = "SELECT AVG(finalgrade) as avg_grade 
                   FROM {grade_grades} g 
                   JOIN {grade_items} gi ON gi.id = g.itemid 
                   WHERE g.userid = ? AND gi.itemtype = 'course' AND g.finalgrade IS NOT NULL";
    $grade_record = $DB->get_record_sql($sql_grades, [$u->id]);
    $promedio = $grade_record && $grade_record->avg_grade ? round($grade_record->avg_grade, 1) : 'Sin Notas';
    
    // 4. Último acceso
    $ultimo_acceso = $u->lastaccess ? date('Y-m-d H:i', $u->lastaccess) : 'Nunca';
    
    fputcsv($fp, [
        $u->id,
        $u->firstname . ' ' . $u->lastname,
        $is_rap,
        $clics,
        $ultimo_acceso,
        $promedio
    ]);
    $count++;
}

fclose($fp);
mtrace("Reporte consolidado generado exitosamente ({$count} registros): " . $csv_file);
