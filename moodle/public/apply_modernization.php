<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');

mtrace("Aplicando configuración de modernización global...");

// 1. Activar Integración Móvil
set_config('enablemobilewebservice', 1);
mtrace("- Servicios web para Moodle Mobile activados.");

// 2. Activar Motor Analítico
set_config('enableanalytics', 1);
mtrace("- Motor de Machine Learning / Analítica Predictiva activado.");

// 3. Forzar Rastreo de Finalización en todos los cursos (si no lo tienen)
$courses = $DB->get_records_select('course', 'id != 1');
foreach ($courses as $course) {
    if (!$course->enablecompletion) {
        $course->enablecompletion = 1;
        $DB->update_record('course', $course);
        mtrace("  - Rastreo de finalización habilitado para el curso: " . $course->fullname);
    }
}

// 4. Asegurar que H5P esté habilitado
$h5p_active = $DB->get_record('filter_active', ['filter' => 'displayh5p']);
if (!$h5p_active) {
    $record = new stdClass();
    $record->filter = 'displayh5p';
    $record->contextid = context_system::instance()->id;
    $record->active = 1;
    $record->sortorder = 1;
    $DB->insert_record('filter_active', $record);
} else if ($h5p_active->active != 1) {
    $h5p_active->active = 1;
    $DB->update_record('filter_active', $h5p_active);
}
mtrace("- Filtro de Contenido Interactivo (H5P) asegurado y activado.");

// 5. Configurar Insignias Globales
set_config('enablebadges', 1);
mtrace("- Subsistema de Insignias (Gamificación base) habilitado.");

mtrace("¡Todas las configuraciones aplicadas correctamente!");
