<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');
require_once($CFG->dirroot.'/user/lib.php');
require_once($CFG->libdir.'/authlib.php');

mtrace("Corrigiendo contraseñas de perfiles de prueba...");

$usernames = ['alumno_basico', 'alumno_avanzado', 'consultor_sence'];

foreach ($usernames as $username) {
    if ($user = $DB->get_record('user', ['username' => $username])) {
        // En Moodle moderno, no hasheamos manualmente antes de update_internal_user_password,
        // la función lo hace internamente.
        $user->password = hash_internal_user_password('Sence2026*');
        $DB->update_record('user', $user);
        mtrace("- Contraseña corregida para {$username}.");
    }
}

mtrace("¡Listo! Intenta iniciar sesión ahora.");
