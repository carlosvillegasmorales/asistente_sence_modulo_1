<?php
namespace local_chatbot\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Envia los avisos automaticos del tablero del Modulo 3 (classes/tablero_m3.php) a quienes tienen una evaluacion en uso.
 * Respeta el interruptor «avisosm3» y las preferencias de notificacion de Moodle. Cada aviso se envia una vez; los de
 * plazo («Registro pendiente», «Corrección pendiente», «Cierre del ciclo pendiente» y «Proceso detenido») se repiten cada
 * tantos dias como su plazo mientras sigan activos.
 */
class send_m3_notices extends \core\task\scheduled_task {

    /**
     * @return string
     */
    public function get_name() {
        return get_string('task_send_m3_notices', 'local_chatbot');
    }

    /**
     * @return void
     */
    public function execute() {
        global $DB;
        if (!\local_chatbot\ux::on('avisosm3')) {
            mtrace('Avisos del Modulo 3 apagados en la configuracion del plugin.');
            return;
        }
        $ahora = time();
        $total = 0;
        $personas = 0;
        $cursos = $DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {local_chatbot_impl} WHERE activa = 1 AND courseid > 0');
        foreach ($cursos as $courseid) {
            $courseid = (int) $courseid;
            $context = \context_course::instance($courseid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            $porpersona = [];
            // Sin el registro de Moodle (primer acceso, guia y ejemplo): los avisos no lo usan.
            foreach (\local_chatbot\tablero_m3::load_facts($courseid, null, false) as $h) {
                $porpersona[$h['userid']][] = $h;
            }
            foreach ($porpersona as $userid => $hechos) {
                $user = \core_user::get_user($userid);
                if (!$user || $user->deleted || $user->suspended || !is_enrolled($context, $user->id, '', true)) {
                    continue;
                }
                try {
                    $n = \local_chatbot\tablero_m3::send_notices($user, $courseid, $hechos, $ahora);
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
        mtrace("Avisos del Modulo 3: {$total} aviso(s) a {$personas} persona(s).");
    }
}
