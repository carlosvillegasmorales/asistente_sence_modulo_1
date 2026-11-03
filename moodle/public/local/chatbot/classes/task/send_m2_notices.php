<?php
namespace local_chatbot\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Envia los avisos automaticos del tablero del Modulo 2 (classes/tablero_m2.php) a quienes tienen una ficha en uso.
 * Respeta el interruptor «avisosm2» y las preferencias de notificacion de Moodle. Cada aviso se envia una vez; los de
 * plazo («Corrección pendiente» y «Proceso detenido») se repiten cada tantos dias como su plazo mientras sigan activos.
 */
class send_m2_notices extends \core\task\scheduled_task {

    /**
     * @return string
     */
    public function get_name() {
        return get_string('task_send_m2_notices', 'local_chatbot');
    }

    /**
     * @return void
     */
    public function execute() {
        global $DB;
        if (!\local_chatbot\ux::on('avisosm2')) {
            mtrace('Avisos del Modulo 2 apagados en la configuracion del plugin.');
            return;
        }
        $ahora = time();
        $total = 0;
        $personas = 0;
        $cursos = $DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {local_chatbot_exante} WHERE activa = 1 AND courseid > 0');
        foreach ($cursos as $courseid) {
            $courseid = (int) $courseid;
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            $porpersona = [];
            // Sin el registro de Moodle (primer acceso, guia y ejemplo): los avisos no lo usan.
            foreach (\local_chatbot\tablero_m2::load_facts($courseid, null, false) as $h) {
                $porpersona[$h['userid']][] = $h;
            }
            foreach ($porpersona as $userid => $hechos) {
                $user = \core_user::get_user($userid);
                if (!$user || $user->deleted || $user->suspended || !is_enrolled($context, $user->id, '', true)) {
                    continue;
                }
                try {
                    $n = \local_chatbot\tablero_m2::send_notices($user, $courseid, $hechos, $ahora);
                } catch (\Throwable $e) {
                    mtrace('No se pudo enviar el aviso a ' . $user->id . ': ' . $e->getMessage());
                    continue;
                }
                if ($n > 0) {
                    $total += $n;
                    $personas++;
                }
            }
        }
        mtrace("Avisos del Modulo 2: {$total} aviso(s) a {$personas} persona(s).");
    }
}
