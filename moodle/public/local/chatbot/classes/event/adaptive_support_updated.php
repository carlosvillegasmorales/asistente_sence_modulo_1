<?php
namespace local_chatbot\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Un apoyo adaptativo cambio de estado.
 *
 * RU-ADAPT-001 pide que Moodle registre "eventos de trazabilidad" de lo que
 * ocurre en la conversacion, aunque ningun apoyo cree una actividad del curso.
 * Este es ese evento: al log va el estado, nunca la consigna ni la evidencia,
 * que viven en local_chatbot_adapt y local_chatbot_adapt_review.
 */
class adaptive_support_updated extends \core\event\base {

    /**
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'local_chatbot_adapt';
    }

    /**
     * @return string
     */
    public static function get_name() {
        return get_string('event_adaptive_support_updated', 'local_chatbot');
    }

    /**
     * @return string
     */
    public function get_description() {
        $estado = $this->other['estado'] ?? '';
        return "El apoyo adaptativo con id '{$this->objectid}' del usuario con id "
            . "'{$this->userid}' paso al estado '{$estado}'.";
    }
}
