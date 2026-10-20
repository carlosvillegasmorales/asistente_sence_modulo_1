<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');
require_once($CFG->dirroot.'/user/profile/lib.php');

mtrace("Preparando infraestructura RAP...");

// 1. Crear campo de perfil personalizado "RAP" si no existe
$field = $DB->get_record('user_info_field', ['shortname' => 'estado_rap']);
if (!$field) {
    $category = $DB->get_record('user_info_category', [], '*', IGNORE_MULTIPLE);
    if (!$category) {
        // Create default category if none exists
        $category = new stdClass();
        $category->name = 'Reconocimiento Académico';
        $category->sortorder = 1;
        $category->id = $DB->insert_record('user_info_category', $category);
    }
    
    $newfield = new stdClass();
    $newfield->shortname = 'estado_rap';
    $newfield->name = 'Aprobado por RAP';
    $newfield->datatype = 'checkbox';
    $newfield->description = 'Reconocimiento de Aprendizajes Previos (SENCE)';
    $newfield->categoryid = $category->id;
    $newfield->sortorder = 1;
    $newfield->required = 0;
    $newfield->locked = 0;
    $newfield->visible = 2; // Visible for users
    $newfield->param1 = ''; // For checkbox
    
    $field_id = $DB->insert_record('user_info_field', $newfield);
    $field = $DB->get_record('user_info_field', ['id' => $field_id]);
    mtrace("- Campo personalizado RAP creado exitosamente.");
} else {
    mtrace("- Campo RAP ya existía.");
}

// 2. Asignar RAP aleatoriamente a un 20% de los estudiantes
mtrace("Simulando datos RAP en usuarios...");
$users = $DB->get_records('user', ['deleted' => 0]);
$count = 0;
foreach ($users as $u) {
    if ($u->id <= 2) continue;
    
    if (rand(1, 100) <= 20) {
        $data = $DB->get_record('user_info_data', ['userid' => $u->id, 'fieldid' => $field->id]);
        if ($data) {
            $data->data = 1;
            $DB->update_record('user_info_data', $data);
        } else {
            $data = new stdClass();
            $data->userid = $u->id;
            $data->fieldid = $field->id;
            $data->data = 1;
            $data->dataformat = 0;
            $DB->insert_record('user_info_data', $data);
        }
        $count++;
    }
}
mtrace("- $count alumnos etiquetados con estado RAP = Sí.");

mtrace("Generador completado.");
