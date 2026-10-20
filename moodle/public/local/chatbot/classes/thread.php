<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Conversacion por meta: cada meta del PDP puede tener su propio hilo con el Coach, separado del
 * chat general. «Conversar esta meta» abre o retoma ese hilo en vez de mezclarlo todo.
 */
class thread {

    /**
     * La meta de un hilo, si es valida para quien conversa: debe ser de su plan en ese curso.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $goalid Lo que pidio el widget.
     * @return int La meta, o 0 (chat general) si no corresponde o la mejora esta apagada.
     */
    public static function goal_for(int $userid, int $courseid, int $goalid): int {
        global $DB;
        if ($goalid <= 0 || !ux::on('goalthreads')) {
            return 0;
        }
        $valida = $DB->record_exists_sql('SELECT 1 FROM {local_chatbot_pdp_goal} g
            JOIN {local_chatbot_pdp} p ON p.id = g.pdpid
            WHERE g.id = :goalid AND p.userid = :userid AND p.courseid = :courseid',
            ['goalid' => $goalid, 'userid' => $userid, 'courseid' => $courseid]);
        return $valida ? $goalid : 0;
    }

    /**
     * Condicion SQL de los mensajes de un hilo. Funcion pura.
     *
     * El chat general son los mensajes sin meta (goalid nulo, como todos los anteriores a
     * M1-v1.14.0); un hilo de meta, los de esa meta.
     *
     * @param int $goalid 0 para el chat general.
     * @return array [fragmento SQL, parametros]
     */
    public static function where(int $goalid): array {
        return $goalid > 0 ? ['goalid = :threadgoal', ['threadgoal' => $goalid]] : ['goalid IS NULL', []];
    }

    /**
     * Linea de contexto para el modelo cuando la conversacion es sobre una meta.
     *
     * @param int $goalid
     * @return string Vacia en el chat general o si la meta no existe.
     */
    public static function context_line(int $goalid): string {
        global $DB;
        if ($goalid <= 0) {
            return '';
        }
        $objetivo = (string) $DB->get_field('local_chatbot_pdp_goal', 'objetivo', ['id' => $goalid]);
        if (trim($objetivo) === '') {
            return '';
        }
        return 'ESTA CONVERSACION ES SOBRE UNA META CONCRETA DEL PLAN: «' . pseudonymizer::scrub($objetivo) . '».'
            . ' Centra tus respuestas en esa meta (su estado, su apoyo y su evidencia). Si la persona pregunta'
            . ' por otra meta o por algo general, indicale que puede volver al chat general.';
    }
}
