<?php
namespace local_chatbot\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Envia una vez al dia los recordatorios del asistente (classes/reminders.php) a quienes tienen un
 * plan en curso. Respeta el interruptor «reminders» y las preferencias de notificacion de Moodle.
 */
class send_reminders extends \core\task\scheduled_task {

    /**
     * @return string
     */
    public function get_name() {
        return get_string('task_send_reminders', 'local_chatbot');
    }

    /**
     * @return void
     */
    public function execute() {
        global $DB;
        if (!\local_chatbot\ux::on('reminders')) {
            mtrace('Recordatorios apagados en la configuracion del plugin.');
            return;
        }
        $ahora = time();
        $total = 0;
        $personas = 0;
        foreach ($DB->get_records('local_chatbot_pdp', null, 'id', 'id, userid, courseid') as $plan) {
            $user = \core_user::get_user((int) $plan->userid);
            if (!$user || $user->deleted || $user->suspended) {
                continue;
            }
            if ($plan->courseid > 0 && !is_enrolled(\context_course::instance($plan->courseid, IGNORE_MISSING), $user->id)) {
                continue;
            }
            try {
                $n = \local_chatbot\reminders::send_for($user, (int) $plan->courseid, $ahora);
            } catch (\Throwable $e) {
                mtrace('No se pudo enviar el recordatorio a ' . $user->id . ': ' . $e->getMessage());
                continue;
            }
            if ($n > 0) {
                $total += $n;
                $personas++;
            }
        }
        mtrace("Recordatorios: {$total} asunto(s) a {$personas} persona(s).");
    }
}
