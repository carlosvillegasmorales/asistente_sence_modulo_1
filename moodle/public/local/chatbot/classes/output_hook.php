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
        $html = local_chatbot_get_widget_html() . local_chatbot_get_analytics_button_html();
        if (!empty($html)) {
            $hook->add_html($html);
        }
    }
}
