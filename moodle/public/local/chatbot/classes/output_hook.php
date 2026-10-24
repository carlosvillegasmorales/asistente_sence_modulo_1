<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook subscriber para inyectar el widget del asistente en la salida estandar de Moodle.
 */
class output_hook {

    /**
     * @param \core\hook\output\after_standard_main_region_html_generation $hook
     * @return void
     */
    public static function after_main_region(\core\hook\output\after_standard_main_region_html_generation $hook): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/chatbot/lib.php');
        $html = local_chatbot_get_widget_html() . local_chatbot_get_analytics_button_html() . self::pdf_viewer_fix();
        if (!empty($html)) {
            $hook->add_html($html);
        }
    }

    /**
     * En la pagina de un recurso PDF incrustado, el visor del navegador se abre sin el panel de miniaturas y
     * ajustado al ancho. Con el visor alineado al encabezado (styles.css), el panel se comia el espacio y la hoja
     * quedaba cortada a la derecha con el zoom calculado para el ancho anterior.
     *
     * @return string
     */
    public static function pdf_viewer_fix(): string {
        global $PAGE;
        if (!ux::on('pdfvisor') || strpos((string) $PAGE->pagetype, 'mod-resource-view') !== 0) {
            return '';
        }
        return '<script>(function() {'
            . 'var f = document.getElementById("resourceobject"); if (!f) { return; }'
            . 'var a = f.tagName === "IFRAME" ? "src" : "data"; var u = f.getAttribute(a) || "";'
            . 'if (/\.pdf$/i.test(u.split("#")[0].split("?")[0]) && u.indexOf("#") < 0) {'
            . 'f.setAttribute(a, u + "#navpanes=0&view=FitH"); }'
            . '})();</script>';
    }
}
