<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot.'/course/modlib.php');

$admin = get_admin();
\core\session\manager::init_empty_session();
\core\session\manager::set_user($admin);

mtrace("Preparando infraestructura Piloto Fase 4...");

// 1. Crear el Curso Piloto Fase 2
$category = $DB->get_record('course_categories', [], '*', IGNORE_MULTIPLE);
$course_shortname = 'PILOTO-FASE2';

if ($existing_course = $DB->get_record('course', ['shortname' => $course_shortname])) {
    $course = $existing_course;
    mtrace("- Curso 'Piloto Fase 2' ya existe.");
} else {
    $course = new stdClass();
    $course->category = $category->id;
    $course->fullname = 'Piloto Fase 2: Programa de Certificación y Empleabilidad';
    $course->shortname = $course_shortname;
    $course->summary = 'Entorno de pruebas integradas de la Suite Moodle (Módulos 1 al 5).';
    $course->format = 'topics';
    $course->visible = 1;
    $course->enablecompletion = 1;
    $course = create_course($course);
    mtrace("- Curso '{$course->fullname}' creado exitosamente.");
}

// 2. Función para crear Tareas (Assign)
function create_assign_via_api($course, $name, $intro, $sectionnum) {
    global $DB, $CFG;
    
    if ($DB->get_record('assign', ['course' => $course->id, 'name' => $name])) {
        mtrace("- Actividad '{$name}' ya existe.");
        return;
    }
    
    $module = new stdClass();
    $module->modulename = 'assign';
    $module->module = $DB->get_field('modules', 'id', ['name' => 'assign']);
    $module->name = $name;
    $module->introeditor = ['text' => $intro, 'format' => FORMAT_HTML];
    $module->course = $course->id;
    $module->section = $sectionnum;
    $module->visible = 1;
    $module->alwaysshowdescription = 1;
    $module->submissiondrafts = 0;
    $module->requiresubmissionstatement = 0;
    $module->completionsubmit = 0;
    $module->assignsubmission_file_enabled = 1;
    $module->assignsubmission_file_maxfiles = 3;
    $module->assignsubmission_file_maxsizebytes = 0;
    $module->grade = 100; // Calificación máxima
    
    try {
        $moduleinfo = create_module($module);
        mtrace("- Actividad '{$name}' creada mediante API.");
    } catch (Exception $e) {
        mtrace("- Error creando '{$name}': " . $e->getMessage());
    }
}

// 3. Crear Buzón RAP (Módulo 4) en la Sección 1
create_assign_via_api(
    $course, 
    '[Módulo 4] Buzón de Evidencias RAP', 
    'Sube aquí tus diplomas, certificados SENCE antiguos y portafolios de experiencia laboral para iniciar el Reconocimiento de Aprendizajes Previos.', 
    1
);

// 4. Crear Evaluación en Puesto de Trabajo (Módulo 5) en la Sección 2
create_assign_via_api(
    $course, 
    '[Módulo 5] Evaluación Práctica en Puesto de Trabajo', 
    'Esta sección está reservada para que la jefatura directa o un evaluador externo califique tu desempeño práctico utilizando la rúbrica/lista de cotejo oficial.', 
    2
);

rebuild_course_cache($course->id);
mtrace("Infraestructura de la Fase 4 desplegada con éxito.");
