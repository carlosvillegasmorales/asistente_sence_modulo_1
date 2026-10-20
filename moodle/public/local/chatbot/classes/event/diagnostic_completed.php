<?php
namespace local_chatbot\event;

defined('MOODLE_INTERNAL') || die();

/**
 * La persona completo una aplicacion del diagnostico de brechas.
 *
 * El documento pide registrar en logs y eventos los hitos del recorrido, no
 * solo guardarlos en tablas propias: asi la capa analitica de la suite puede
 * leerlos con las mismas herramientas que el resto de Moodle.
 */
class diagnostic_completed extends \core\event\base {

    /**
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * @return string
     */
    public static function get_name() {
        return get_string('event_diagnostic_completed', 'local_chatbot');
    }

    /**
     * @return string
     */
    public function get_description() {
        $stage = $this->other['stage'] ?? 'entrada';
        return "El usuario con id '{$this->userid}' completo el diagnostico de brechas (etapa {$stage}).";
    }
}
