<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');
require_once($CFG->libdir.'/gradelib.php');

mtrace("Iniciando calificación masiva de TODOS los estudiantes en TODOS los cursos...");

$courses = $DB->get_records_select('course', 'id != 1');
foreach ($courses as $course) {
    mtrace("Procesando curso: " . $course->fullname);
    $context = context_course::instance($course->id);
    
    // Obtenemos a todos los usuarios matriculados
    $enrolled_users = get_enrolled_users($context);
    if (empty($enrolled_users)) {
        mtrace("  - Sin alumnos matriculados.");
        continue;
    }

    $grade_items = grade_item::fetch_all(['courseid' => $course->id, 'itemtype' => 'mod']);
    if (empty($grade_items)) {
        mtrace("  - No hay actividades calificables.");
        continue;
    }

    $count = 0;
    foreach ($grade_items as $gi) {
        $min = $gi->grademin ?? 0;
        $max = $gi->grademax ?? 100;
        if ($max <= $min) { $max = $min + 100; }
        
        foreach ($enrolled_users as $u) {
            if ($u->id <= 2) continue; // Skip admin
            
            // Generar nota aleatoria
            $pass_threshold = $min + (($max - $min) * 0.4);
            $grade = rand(intval($pass_threshold), intval($max));
            
            $gi->update_final_grade($u->id, $grade, 'generator');
            $count++;
        }
    }
    
    mtrace("  - $count calificaciones inyectadas. Recalculando notas finales...");
    // Función correcta para recalcular notas del curso
    grade_regrade_final_grades($course->id);
    mtrace("  - Curso $course->fullname completado.");
}

mtrace("¡Todas las calificaciones han sido inyectadas y recalculadas correctamente!");
