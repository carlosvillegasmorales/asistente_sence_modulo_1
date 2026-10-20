<?php
namespace local_chatbot\event;

defined('MOODLE_INTERNAL') || die();

/**
 * El Modo C emitio retroalimentacion sobre un proyecto integrador.
 *
 * Incluye si la IA declaro que hacia falta revision humana en algun criterio,
 * porque ese es el dato que permite a la operacion del piloto detectar cuando
 * intervenir sin tener que abrir cada proyecto.
 */
class project_evaluated extends \core\event\base {

    /**
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'local_chatbot_project';
    }

    /**
     * @return string
     */
    public static function get_name() {
        return get_string('event_project_evaluated', 'local_chatbot');
    }

    /**
     * @return string
     */
    public function get_description() {
        $needshuman = !empty($this->other['needshuman']) ? 'si' : 'no';
        return "El proyecto integrador con id '{$this->objectid}' recibio retroalimentacion "
            . "del evaluador formativo. Requiere revision humana: {$needshuman}.";
    }
}
