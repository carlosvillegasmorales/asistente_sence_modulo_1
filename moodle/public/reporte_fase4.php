<?php
require_once(__DIR__ . '/config.php');
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context); // Only admins

$download = optional_param('download', '', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/reporte_fase4.php'));
$PAGE->set_context($context);
$PAGE->set_title('Reporte Consolidado Piloto Fase 4');
$PAGE->set_heading('Reporte Consolidado Piloto Fase 4');

// Gather data
$columns = ['ID', 'Nombre Completo', 'Email', 'Estado RAP (M4)', 'Clics', 'Promedio General', 'Último Acceso'];
$data = [];

$users = $DB->get_records('user', ['deleted' => 0]);
$rap_field = $DB->get_record('user_info_field', ['shortname' => 'estado_rap']);

foreach ($users as $u) {
    if ($u->id <= 2) continue; // Skip admin
    
    $is_rap = 'No';
    if ($rap_field) {
        $rap_data = $DB->get_record('user_info_data', ['userid' => $u->id, 'fieldid' => $rap_field->id]);
        if ($rap_data && $rap_data->data == 1) {
            $is_rap = 'Sí';
        }
    }
    
    $clics = $DB->count_records('logstore_standard_log', ['userid' => $u->id]);
    
    $sql_grades = "SELECT AVG(finalgrade) as avg_grade 
                   FROM {grade_grades} g 
                   JOIN {grade_items} gi ON gi.id = g.itemid 
                   WHERE g.userid = ? AND gi.itemtype = 'course' AND g.finalgrade IS NOT NULL";
    $grade_record = $DB->get_record_sql($sql_grades, [$u->id]);
    $promedio = $grade_record && $grade_record->avg_grade ? round($grade_record->avg_grade, 1) : 'S/N';
    
    $ultimo_acceso = $u->lastaccess ? date('Y-m-d H:i', $u->lastaccess) : 'Nunca';
    
    $data[] = [
        $u->id,
        $u->firstname . ' ' . $u->lastname,
        $u->email,
        $is_rap,
        $clics,
        $promedio,
        $ultimo_acceso
    ];
}

// Handle download
if ($download === 'excel') {
    \core\dataformat::download_data(
        'Reporte_Piloto_Fase4_' . date('Ymd'),
        'excel', // format
        $columns,
        $data
    );
    exit;
}

// Display page
echo $OUTPUT->header();

echo html_writer::tag('p', 'Este reporte consolida los datos de los 5 módulos integrados en la Fase 4 del piloto.');

// Download Button
$downloadurl = new moodle_url('/reporte_fase4.php', ['download' => 'excel']);
echo html_writer::tag('div', 
    $OUTPUT->single_button($downloadurl, 'Descargar en formato Excel (.xlsx)', 'get', ['class' => 'btn btn-primary']), 
    ['class' => 'mb-3']
);

// Display Table (limit to 50 for preview)
$table = new html_table();
$table->head = $columns;
$table->data = array_slice($data, 0, 50);

echo html_writer::tag('h3', 'Previsualización de Datos (Primeros 50 registros)');
echo html_writer::table($table);

echo $OUTPUT->footer();
