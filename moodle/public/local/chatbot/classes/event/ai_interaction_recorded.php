<?php
namespace local_chatbot\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Se registro una interaccion con el asistente.
 *
 * Lleva el modo funcional y la version de prompt con que se generó, nunca el
 * contenido de la conversacion: el log de Moodle es para trazabilidad de uso,
 * no un segundo lugar donde queden los textos de la persona.
 */
class ai_interaction_recorded extends \core\event\base {

    /**
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'local_chatbot_interaction';
    }

    /**
     * @return string
     */
    public static function get_name() {
        return get_string('event_ai_interaction', 'local_chatbot');
    }

    /**
     * @return string
     */
    public function get_description() {
        $mode = $this->other['mode'] ?? '';
        $promptversion = $this->other['promptversion'] ?? 0;
        return "El usuario con id '{$this->userid}' interactuo con el asistente en modo "
            . "'{$mode}' (prompt version {$promptversion}).";
    }
}
