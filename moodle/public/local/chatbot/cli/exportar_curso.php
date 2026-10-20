<?php
/**
 * Exporta un curso a una copia de respaldo (.mbz) SIN datos de personas.
 *
 * Es la copia base del Modulo 1 (deploy/curso/Modulo1_curso_base.mbz): trae el contenido del curso para
 * restaurarlo en otro Moodle, y deja fuera usuarios, matriculas, roles, comentarios, insignias, registros,
 * historial de notas y finalizacion. Despues de restaurarla se corre configurar_modulo1.php.
 *
 * Uso (como el usuario del servidor web):
 *   php cli/exportar_curso.php --curso=6 --destino=/tmp/Modulo1_curso_base.mbz
 *
 * @package local_chatbot
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

[$options, $unrecognised] = cli_get_params(
    ['curso' => 0, 'destino' => '', 'help' => false],
    ['c' => 'curso', 'd' => 'destino', 'h' => 'help']);

if ($options['help'] || empty($options['curso']) || $options['destino'] === '') {
    echo "Exporta un curso a un .mbz sin datos de personas.\n\n"
        . "  --curso=ID         curso a exportar (obligatorio)\n"
        . "  --destino=RUTA     archivo .mbz de salida (obligatorio)\n";
    exit(0);
}

\core\session\manager::set_user(get_admin());
$cursoid = (int) $options['curso'];
$destino = (string) $options['destino'];
$DB->get_record('course', ['id' => $cursoid], 'id', MUST_EXIST);

$bc = new backup_controller(backup::TYPE_1COURSE, $cursoid, backup::FORMAT_MOODLE, backup::INTERACTIVE_NO,
    backup::MODE_GENERAL, get_admin()->id);
$plan = $bc->get_plan();
// Nada que identifique a una persona ni su avance: solo el contenido del curso.
foreach (['users', 'role_assignments', 'comments', 'badges', 'logs', 'grade_histories', 'userscompletion',
        'anonymize'] as $nombre) {
    if ($plan->setting_exists($nombre)) {
        $plan->get_setting($nombre)->set_value(0);
    }
}
$bc->set_status(backup::STATUS_AWAITING);
$bc->execute_plan();
$resultados = $bc->get_results();
$resultados['backup_destination']->copy_content_to($destino);
$bc->destroy();

if (!is_file($destino)) {
    fwrite(STDERR, "No se pudo escribir $destino\n");
    exit(1);
}
echo "Copia sin datos de personas: $destino (" . round(filesize($destino) / 1024) . " KB)\n";
