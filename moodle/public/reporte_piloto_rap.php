<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');

mtrace("Generando Reporte de Piloto y RAP...");

$csv_file = $CFG->dataroot . '/reporte_piloto_rap.csv';
$fp = fopen($csv_file, 'w');

// Escribir cabeceras CSV (BOM para Excel)
fputs($fp, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF)));
fputcsv($fp, ['ID_Usuario', 'Nombre', 'Email', 'Estado_RAP', 'Total_Clics_Plataforma', 'Ultimo_Acceso', 'Promedio_General']);

// Get all users
$users = $DB->get_records('user', ['deleted' => 0]);

// Fetch RAP field ID
$rap_field = $DB->get_record('user_info_field', ['shortname' => 'estado_rap']);

foreach ($users as $u) {
    if ($u->id <= 2) continue; // Skip admin
    
    // 1. Verificar Estado RAP
    $is_rap = 'No';
    if ($rap_field) {
        $rap_data = $DB->get_record('user_info_data', ['userid' => $u->id, 'fieldid' => $rap_field->id]);
        if ($rap_data && $rap_data->data == 1) {
            $is_rap = 'Si';
        }
    }
    
    // 2. Extraer Clics del log (Piloto)
    $clics = $DB->count_records('logstore_standard_log', ['userid' => $u->id]);
    
    // 3. Extraer Promedio General
    $sql_grades = "SELECT AVG(finalgrade) as avg_grade 
                   FROM {grade_grades} g 
                   JOIN {grade_items} gi ON gi.id = g.itemid 
                   WHERE g.userid = ? AND gi.itemtype = 'course' AND g.finalgrade IS NOT NULL";
    $grade_record = $DB->get_record_sql($sql_grades, [$u->id]);
    $promedio = $grade_record && $grade_record->avg_grade ? round($grade_record->avg_grade, 1) : 'Sin Notas';
    
    // 4. Último acceso legible
    $ultimo_acceso = $u->lastaccess ? date('Y-m-d H:i', $u->lastaccess) : 'Nunca';
    
    fputcsv($fp, [
        $u->id,
        $u->firstname . ' ' . $u->lastname,
        $u->email,
        $is_rap,
        $clics,
        $ultimo_acceso,
        $promedio
    ]);
}

fclose($fp);

mtrace("Reporte generado exitosamente en: " . $csv_file);
