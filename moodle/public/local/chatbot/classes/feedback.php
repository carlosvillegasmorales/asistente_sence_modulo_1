<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Valoracion de las respuestas del asistente: un voto (util / no util) y, si no fue util, un
 * motivo de una lista cerrada. Alimenta los indicadores de calidad del Anexo G §11.4.
 *
 * No se guarda texto de la persona: solo el voto, el motivo codificado y la interaccion a la
 * que se refiere, que ya tiene su codigo IA-T/IA-C del Anexo G.
 */
class feedback {

    /** @var string[] Motivos de una valoracion negativa. */
    const REASONS = ['no_responde', 'incorrecta', 'larga', 'no_entendi'];

    /**
     * Voto y motivo validos, o null si el voto no es valido. Funcion pura.
     *
     * El motivo solo acompana a un voto negativo y solo si es de la lista; cualquier otra cosa
     * se descarta en vez de guardarse.
     *
     * @param int $vote 1 util, -1 no util.
     * @param string $reason
     * @return array|null ['vote' => int, 'reason' => string|null]
     */
    public static function normalize(int $vote, string $reason): ?array {
        if ($vote !== 1 && $vote !== -1) {
            return null;
        }
        $reason = trim($reason);
        return ['vote' => $vote, 'reason' => ($vote === -1 && in_array($reason, self::REASONS, true)) ? $reason : null];
    }

    /**
     * Resumen para los indicadores. Funcion pura.
     *
     * @param int $up Votos utiles.
     * @param int $down Votos no utiles.
     * @return string
     */
    public static function summary(int $up, int $down): string {
        $total = $up + $down;
        if ($total === 0) {
            return 'Sin valoraciones';
        }
        return $up . ' de ' . $total . ' (' . round(100 * $up / $total) . '%)';
    }

    /**
     * Guarda o actualiza la valoracion de una interaccion de quien vota.
     *
     * @param int $userid
     * @param int $interactionid
     * @param int $vote
     * @param string $reason
     * @return bool false si el voto no es valido o la interaccion no es de esa persona.
     */
    public static function save(int $userid, int $interactionid, int $vote, string $reason = ''): bool {
        global $DB;
        $valido = self::normalize($vote, $reason);
        if ($valido === null || $interactionid <= 0
                || !$DB->record_exists('local_chatbot_interaction', ['id' => $interactionid, 'userid' => $userid])) {
            return false;
        }
        $now = time();
        $previo = $DB->get_record('local_chatbot_feedback', ['interactionid' => $interactionid, 'userid' => $userid]);
        if ($previo) {
            $previo->vote = $valido['vote'];
            $previo->reason = $valido['reason'];
            $previo->timemodified = $now;
            $DB->update_record('local_chatbot_feedback', $previo);
        } else {
            $DB->insert_record('local_chatbot_feedback', (object) [
                'interactionid' => $interactionid, 'userid' => $userid, 'vote' => $valido['vote'],
                'reason' => $valido['reason'], 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        return true;
    }
}
