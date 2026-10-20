<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/enrol/locallib.php');

mtrace("Iniciando la inserción masiva de datos en todos los cursos...");

// 1. Get all users (limit to 100 to avoid taking forever, but enough for data)
$users = $DB->get_records('user', ['deleted' => 0], 'id ASC', '*', 0, 100);
$role = $DB->get_record('role', ['shortname' => 'student']);

// 2. Enroll these users into all courses
$courses = $DB->get_records_select('course', 'id != 1');
foreach ($courses as $course) {
    mtrace("Matriculando usuarios en el curso: " . $course->fullname);
    $enrol = enrol_get_plugin('manual');
    $instances = enrol_get_instances($course->id, true);
    $manualinstance = null;
    foreach ($instances as $instance) {
        if ($instance->enrol === 'manual') {
            $manualinstance = $instance;
            break;
        }
    }
    if (!$manualinstance) {
        $enrol->add_default_instance($course);
        $instances = enrol_get_instances($course->id, true);
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                $manualinstance = $instance;
                break;
            }
        }
    }
    
    foreach ($users as $u) {
        // Skip admin and guest
        if ($u->id <= 2) continue;
        
        if (rand(1, 100) > 30) { // 70% chance to enroll
            $enrol->enrol_user($manualinstance, $u->id, $role->id);
        }
    }
}

// 3. Inject random grades for all grade items in all courses
foreach ($courses as $course) {
    mtrace("Generando calificaciones para el curso: " . $course->fullname);
    $context = context_course::instance($course->id);
    $enrolled_users = get_enrolled_users($context);
    
    $grade_items = grade_item::fetch_all(['courseid' => $course->id, 'itemtype' => 'mod']);
    
    if (empty($grade_items)) {
        mtrace("  No hay actividades calificables en este curso.");
        continue;
    }

    foreach ($grade_items as $gi) {
        mtrace("  -> Calificando item: " . $gi->itemname);
        $min = $gi->grademin ?? 0;
        $max = $gi->grademax ?? 100;
        if ($max <= $min) { $max = $min + 100; }
        
        foreach ($enrolled_users as $u) {
            if ($u->id <= 2) continue; // Skip admin
            
            // 80% chance the student completed the assignment
            if (rand(1, 100) > 20) {
                // Generate a random grade heavily weighted towards passing (e.g. 50-100)
                $pass_threshold = $min + (($max - $min) * 0.5);
                $grade = rand(intval($pass_threshold), intval($max));
                
                $gi->update_final_grade($u->id, $grade, 'generator');
            }
        }
    }
    
    // Recalculate grades for the course so final grades appear
    mtrace("  Recalculando calificaciones finales del curso...");
    grade_recalculate();
}

mtrace("¡Finalizado con éxito! El sistema ahora tiene el máximo de información.");
