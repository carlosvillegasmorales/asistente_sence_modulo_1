<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot.'/course/modlib.php');

$admin = get_admin();
\core\session\manager::init_empty_session();
\core\session\manager::set_user($admin);

mtrace("Continuando con la configuración de Fichas Institucionales como Admin...");

$course_shortname = 'LAB-DISENO';
$course = $DB->get_record('course', ['shortname' => $course_shortname]);

function create_data_via_api($course, $name, $intro, $sectionnum) {
    global $DB, $CFG;
    
    if ($DB->get_record('data', ['course' => $course->id, 'name' => $name])) {
        mtrace("- Ficha '{$name}' ya existe.");
        return;
    }
    
    $module = new stdClass();
    $module->modulename = 'data';
    $module->module = $DB->get_field('modules', 'id', ['name' => 'data']);
    $module->name = $name;
    $module->introeditor = ['text' => $intro, 'format' => FORMAT_HTML];
    $module->course = $course->id;
    $module->section = $sectionnum;
    $module->visible = 1;
    $module->manageapproved = 1;
    $module->comments = 0;
    
    try {
        $moduleinfo = create_module($module);
        mtrace("- Ficha '{$name}' creada mediante API.");
    } catch (Exception $e) {
        mtrace("- Error creando '{$name}': " . $e->getMessage());
    }
}

create_data_via_api($course, 'Ficha de Diagnóstico y Evaluación Ex Ante', 'Llenado obligatorio por el Consultor (Módulo 2).', 1);
create_data_via_api($course, 'Ficha de Implementación y Evaluación Durante', 'Llenado obligatorio por el Consultor (Módulo 3).', 2);

rebuild_course_cache($course->id);
mtrace("Script finalizado.");
