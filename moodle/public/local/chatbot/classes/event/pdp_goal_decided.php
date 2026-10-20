<?php
namespace local_chatbot\event;

defined('MOODLE_INTERNAL') || die();

/**
 * La persona decidio sobre una meta de su PDP: la acepto, la modifico, la
 * descarto o la dio por cumplida.
 *
 * Es el hito del PDP que el documento pide registrar. Va al log de Moodle
 * ademas de a local_chatbot_pdp_history, que guarda el detalle de que cambio
 * y con que evidencia.
 */
class pdp_goal_decided extends \core\event\base {

    /**
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'local_chatbot_pdp_goal';
    }

    /**
     * @return string
     */
    public static function get_name() {
        return get_string('event_pdp_goal_decided', 'local_chatbot');
    }

    /**
     * @return string
     */
    public function get_description() {
        $decision = $this->other['decision'] ?? '';
        return "El usuario con id '{$this->userid}' marco la meta de PDP con id "
            . "'{$this->objectid}' como '{$decision}'.";
    }
}
