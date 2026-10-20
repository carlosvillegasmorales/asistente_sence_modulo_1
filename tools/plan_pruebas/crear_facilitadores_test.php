<?php
/**
 * Crea personas facilitadoras de prueba (rol estudiante en el curso, que es como las
 * ve el asistente) y las matricula en los cursos indicados. Idempotente: si el usuario
 * ya existe no lo toca ni le cambia la clave.
 *
 * Uso (desde el servidor, como el usuario del web):
 *   MOODLE_CFG=/ruta/config.php php crear_facilitadores_test.php            # lista cursos
 *   MOODLE_CFG=/ruta/config.php php crear_facilitadores_test.php 6,22      # crea y matricula
 *
 * Las claves se generan al azar y se imprimen una sola vez; no quedan en el repo.
 * Todas las cuentas llevan idnumber FACIL-TEST-M1 para poder retirarlas despues.
 */
define('CLI_SCRIPT', true);
require getenv('MOODLE_CFG');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/enrollib.php');

global $DB;

const FACIL_IDNUMBER = 'FACIL-TEST-M1';
$cuentas = [
    'facilitador.test1' => ['Facilitadora', 'Test Uno'],
    'facilitador.test2' => ['Facilitador', 'Test Dos'],
    'facilitador.test3' => ['Facilitadora', 'Test Tres'],
];

$arg = $argv[1] ?? '';
if ($arg === '') {
    echo "Cursos disponibles:\n";
    foreach ($DB->get_records_select('course', 'id <> ?', [SITEID], 'id', 'id, shortname, fullname') as $c) {
        echo "  {$c->id}  {$c->shortname}  {$c->fullname}\n";
    }
    echo "\nVuelve a correr con los ids separados por coma.\n";
    exit(0);
}

$cursos = array_filter(array_map('intval', explode(',', $arg)));
foreach ($cursos as $cid) {
    if (!$DB->record_exists('course', ['id' => $cid])) {
        fwrite(STDERR, "El curso {$cid} no existe.\n");
        exit(1);
    }
}

/** Clave al azar que cumple la politica por defecto de Moodle (mayuscula, minuscula, digito, simbolo). */
function facil_clave(): string {
    $pick = static function (string $set, int $n): string {
        $s = '';
        for ($i = 0; $i < $n; $i++) {
            $s .= $set[random_int(0, strlen($set) - 1)];
        }
        return $s;
    };
    $c = $pick('ABCDEFGHJKLMNPQRSTUVWXYZ', 3) . $pick('abcdefghijkmnpqrstuvwxyz', 5)
        . $pick('23456789', 3) . $pick('.-*#', 1);
    return str_shuffle($c);
}

$studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
foreach ($cuentas as $username => [$nombre, $apellido]) {
    $existente = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0]);
    if ($existente) {
        $uid = (int) $existente->id;
        $clave = '(ya existia: clave sin cambios)';
    } else {
        $clave = facil_clave();
        $uid = user_create_user((object) [
            'username' => $username, 'password' => $clave, 'auth' => 'manual', 'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id, 'firstname' => $nombre, 'lastname' => $apellido,
            'email' => "{$username}@example.invalid", 'idnumber' => FACIL_IDNUMBER, 'lang' => 'es',
            'description' => 'Cuenta de prueba de facilitador (M1). Retirar al terminar las pruebas.',
        ], true, false);
    }
    foreach ($cursos as $cid) {
        enrol_try_internal_enrol($cid, $uid, $studentrole);
    }
    echo "{$username}  (id={$uid})  clave: {$clave}  cursos: " . implode(',', $cursos) . "\n";
}
