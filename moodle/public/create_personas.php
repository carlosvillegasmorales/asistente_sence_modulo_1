<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');
require_once($CFG->dirroot.'/user/lib.php');

mtrace("Creando perfiles de prueba (Personas) para el Chatbot IA...");

function create_persona($username, $firstname, $lastname, $email) {
    global $DB, $CFG;
    if ($existing = $DB->get_record('user', ['username' => $username])) {
        mtrace("- Usuario {$username} ya existe.");
        return $existing;
    }
    
    $user = new stdClass();
    $user->auth = 'manual';
    $user->username = $username;
    $user->password = hash_internal_user_password('Sence2026*');
    $user->firstname = $firstname;
    $user->lastname = $lastname;
    $user->email = $email;
    $user->confirmed = 1;
    $user->mnethostid = $CFG->mnet_localhost_id;
    $user->lang = $CFG->lang;
    $user->timezone = '99';
    $user->description = 'Usuario de Prueba para Agente IA';
    
    $user->id = user_create_user($user, true, false);
    mtrace("- Usuario {$username} creado exitosamente.");
    return $user;
}

function enroll_persona($user, $course_shortname, $role_shortname = 'student') {
    global $DB;
    $course = $DB->get_record('course', ['shortname' => $course_shortname]);
    if (!$course) {
        mtrace("  - Advertencia: Curso {$course_shortname} no encontrado.");
        return;
    }
    
    $role = $DB->get_record('role', ['shortname' => $role_shortname]);
    if (!$role) {
        $role = $DB->get_record('role', ['shortname' => 'student']);
    }
    
    $enrol = enrol_get_plugin('manual');
    $instances = enrol_get_instances($course->id, true);
    $manual_instance = null;
    foreach ($instances as $instance) {
        if ($instance->enrol == 'manual') {
            $manual_instance = $instance;
            break;
        }
    }
    
    if (!$manual_instance) {
        $enrolid = $enrol->add_instance($course);
        $manual_instance = $DB->get_record('enrol', ['id' => $enrolid]);
    }
    
    $enrol->enrol_user($manual_instance, $user->id, $role->id);
    mtrace("  - {$user->username} matriculado en {$course_shortname} como {$role->shortname}.");
}

// 1. Crear usuarios
$basico = create_persona('alumno_basico', 'Juan', 'Básico', 'basico@test.com');
$avanzado = create_persona('alumno_avanzado', 'María', 'Avanzada', 'avanzada@test.com');
$consultor = create_persona('consultor_sence', 'Pedro', 'Consultor', 'consultor@test.com');

// 2. Matricularlos en escenarios específicos
$intro = $DB->get_record('course', ['fullname' => 'Introducción a la Programación']);
$math = $DB->get_record('course', ['fullname' => 'Matemáticas Avanzadas']);
$adv = $DB->get_record('course', ['fullname' => 'Curso Avanzado de Ejemplo']);

if ($intro) enroll_persona($basico, $intro->shortname);
if ($math) enroll_persona($avanzado, $math->shortname);
if ($adv) enroll_persona($avanzado, $adv->shortname);

// Consultor
enroll_persona($consultor, 'PILOTO-FASE2', 'student');
enroll_persona($consultor, 'LAB-DISENO', 'consultor');

mtrace("Perfiles generados con éxito.");
