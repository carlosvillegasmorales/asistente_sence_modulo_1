<?php
namespace local_chatbot\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Evento del catalogo de trazabilidad del Anexo G §7 (EVT-IA-T-U1-*, EVT-IA-C-U1-*, etc.).
 *
 * Un solo evento de Moodle con el codigo del anexo en other['code']: asi el catalogo del
 * cliente queda en el log estandar sin una clase por cada uno de sus veinte codigos. El
 * detalle consultable para los indicadores vive en local_chatbot_trace.
 */
class trace_recorded extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'local_chatbot_trace';
    }

    public static function get_name() {
        return get_string('event_trace_recorded', 'local_chatbot');
    }

    public function get_description() {
        $code = $this->other['code'] ?? '';
        $object = $this->other['object'] ?? '';
        return "Evento de trazabilidad '{$code}'" . ($object !== '' ? " sobre '{$object}'" : '')
            . " del usuario con id '{$this->userid}'.";
    }
}
