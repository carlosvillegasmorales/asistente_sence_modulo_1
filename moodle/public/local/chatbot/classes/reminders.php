<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Recordatorios por las notificaciones de Moodle: plazo de una meta que se acerca, un apoyo que
 * lleva dias esperando la evidencia, propuestas de metas sin revisar.
 *
 * Una sola notificacion al dia por persona, con todo lo pendiente junto, y cada asunto se repite
 * como mucho cada REPEAT_DAYS: un recordatorio que llega todos los dias se termina ignorando.
 * El calculo de que corresponde avisar es puro (due()); la tarea solo junta los hechos y envia.
 */
class reminders {

    /** @var int Dias antes del plazo de una meta en que se avisa. */
    const DEADLINE_DAYS = 3;

    /** @var int Dias sin movimiento de un apoyo que espera evidencia antes de avisar. */
    const IDLE_SUPPORT_DAYS = 3;

    /** @var int Dias que puede esperar una propuesta de meta sin revisar antes de avisar. */
    const PROPOSAL_DAYS = 2;

    /** @var int Dias minimos entre dos avisos del mismo asunto. */
    const REPEAT_DAYS = 3;

    /** @var string Preferencia de usuario donde se guarda cuando se aviso cada asunto. */
    const PREFERENCE = 'local_chatbot_reminders';

    /**
     * Que corresponde avisar hoy. Funcion pura.
     *
     * @param array $f Hechos: goals (lista de ['n', 'id', 'plazo']), supports (lista de ['n', 'id',
     *                 'waiting' (bool, espera evidencia), 'idle_since']), proposals (lista de ['id', 'since']).
     * @param int $now
     * @param array $sent Asunto => ultima vez que se aviso.
     * @return array Lista de ['key' (asunto, unico), 'type' (plazo, apoyo, propuestas), 'a'].
     */
    public static function due(array $f, int $now, array $sent): array {
        $dia = 86400;
        $avisos = [];
        $repetible = static function (string $clave) use ($sent, $now, $dia): bool {
            return !isset($sent[$clave]) || $now - (int) $sent[$clave] >= self::REPEAT_DAYS * $dia;
        };
        foreach ($f['goals'] ?? [] as $meta) {
            $plazo = (int) ($meta['plazo'] ?? 0);
            $clave = 'plazo-' . (int) $meta['id'];
            if ($plazo >= $now && $plazo - $now <= self::DEADLINE_DAYS * $dia && $repetible($clave)) {
                $avisos[] = ['key' => $clave, 'type' => 'plazo', 'a' => ['n' => (int) $meta['n'], 'plazo' => $plazo]];
            }
        }
        foreach ($f['supports'] ?? [] as $apoyo) {
            $clave = 'apoyo-' . (int) $apoyo['id'];
            if (!empty($apoyo['waiting']) && $now - (int) $apoyo['idle_since'] >= self::IDLE_SUPPORT_DAYS * $dia
                    && $repetible($clave)) {
                $avisos[] = ['key' => $clave, 'type' => 'apoyo', 'a' => (int) $apoyo['n']];
            }
        }
        $viejas = array_filter($f['proposals'] ?? [], static function ($p) use ($now, $dia) {
            return $now - (int) $p['since'] >= self::PROPOSAL_DAYS * $dia;
        });
        if (!empty($viejas) && $repetible('propuestas')) {
            $avisos[] = ['key' => 'propuestas', 'type' => 'propuestas', 'a' => count($viejas)];
        }
        return $avisos;
    }

    /**
     * Registro de avisos enviados, sin los asuntos que ya no vale la pena recordar. Funcion pura.
     *
     * @param array $sent Asunto => ultima vez.
     * @param array $avisos Lo que se acaba de enviar (salida de due()).
     * @param int $now
     * @return array
     */
    public static function record(array $sent, array $avisos, int $now): array {
        foreach ($avisos as $aviso) {
            $sent[$aviso['key']] = $now;
        }
        // Lo que no se aviso en 60 dias ya no importa: el registro no crece sin fin.
        return array_filter($sent, static function ($t) use ($now) {
            return $now - (int) $t < 60 * 86400;
        });
    }

    /**
     * Hechos de una persona en un curso, leidos de la base. Numeracion de metas igual que el plan.
     *
     * @param int $userid
     * @param int $courseid
     * @return array
     */
    public static function facts(int $userid, int $courseid): array {
        global $DB;
        $hechos = ['goals' => [], 'supports' => [], 'proposals' => []];
        $plan = $DB->get_record('local_chatbot_pdp', ['userid' => $userid, 'courseid' => $courseid]);
        if (!$plan) {
            return $hechos;
        }
        foreach (pdp::list_goals((int) $plan->id, [pdp::GOAL_PROPUESTO]) as $p) {
            $hechos['proposals'][] = ['id' => (int) $p->id, 'since' => (int) $p->timecreated];
        }
        $metas = pdp::list_goals((int) $plan->id,
            [pdp::GOAL_ACEPTADO, pdp::GOAL_MODIFICADO, pdp::GOAL_POSTERGADO, pdp::GOAL_COMPLETADO]);
        $metas = ux::on('goalorder')
            ? pdp::order_goals($metas, $DB->get_records_menu('local_chatbot_dimension', null, '', 'id, sortorder'))
            : array_values($metas);
        foreach ($metas as $i => $meta) {
            if (!in_array($meta->status, [pdp::GOAL_ACEPTADO, pdp::GOAL_MODIFICADO], true)) {
                continue;
            }
            $hechos['goals'][] = ['n' => $i + 1, 'id' => (int) $meta->id, 'plazo' => (int) $meta->plazo];
            foreach (adaptive::list_for_goal((int) $meta->id) as $apoyo) {
                if (!adaptive::is_terminal($apoyo->estado)) {
                    $hechos['supports'][] = ['n' => $i + 1, 'id' => (int) $apoyo->id,
                        'waiting' => adaptive::accepts_evidence($apoyo->estado),
                        'idle_since' => (int) ($apoyo->timemodified ?: $apoyo->timegenerated)];
                    break;
                }
            }
        }
        return $hechos;
    }

    /**
     * Envia el recordatorio del dia a una persona, si hay algo que recordar.
     *
     * @param \stdClass $user
     * @param int $courseid
     * @param int $now
     * @return int Cantidad de asuntos recordados.
     */
    public static function send_for(\stdClass $user, int $courseid, int $now): int {
        $enviados = json_decode((string) get_user_preferences(self::PREFERENCE, '', $user->id), true) ?: [];
        $avisos = self::due(self::facts((int) $user->id, $courseid), $now, $enviados);
        if (empty($avisos)) {
            return 0;
        }
        $lineas = [];
        foreach ($avisos as $aviso) {
            $a = $aviso['a'];
            if ($aviso['type'] === 'plazo') {
                $a = ['n' => $a['n'], 'fecha' => userdate($a['plazo'], get_string('strftimedateshort', 'langconfig'))];
            }
            $lineas[] = '• ' . get_string('reminder_' . $aviso['type'], 'local_chatbot', $a);
        }
        $url = new \moodle_url('/local/chatbot/pdp.php', ['courseid' => $courseid]);
        $mensaje = new \core\message\message();
        $mensaje->component = 'local_chatbot';
        $mensaje->name = 'reminder';
        $mensaje->userfrom = \core_user::get_noreply_user();
        $mensaje->userto = $user;
        $mensaje->subject = get_string('reminder_subject', 'local_chatbot');
        $mensaje->fullmessage = get_string('reminder_intro', 'local_chatbot') . "\n\n" . implode("\n", $lineas)
            . "\n\n" . $url->out(false);
        $mensaje->fullmessageformat = FORMAT_PLAIN;
        $mensaje->fullmessagehtml = '<p>' . s(get_string('reminder_intro', 'local_chatbot')) . '</p><ul><li>'
            . implode('</li><li>', array_map(static function ($l) {
                return s(ltrim($l, '• '));
            }, $lineas)) . '</li></ul><p><a href="' . $url->out() . '">' . s(get_string('reminder_go', 'local_chatbot'))
            . '</a></p>';
        $mensaje->smallmessage = get_string('reminder_subject', 'local_chatbot');
        $mensaje->notification = 1;
        $mensaje->contexturl = $url->out(false);
        $mensaje->contexturlname = get_string('reminder_go', 'local_chatbot');
        $mensaje->courseid = $courseid > 0 ? $courseid : SITEID;
        message_send($mensaje);
        set_user_preference(self::PREFERENCE, json_encode(self::record($enviados, $avisos, $now)), $user->id);
        return count($avisos);
    }
}
