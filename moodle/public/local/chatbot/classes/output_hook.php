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
     * Sin la franja de navegacion del curso (Curso, Participantes, Calificaciones, Actividades, Mas) para quien cursa
     * (pedido del 26-10-2026). La conserva quien acompana o administra el curso, aunque no pueda editarlo (consultor).
     * Es solo visual: las paginas siguen abriendose por su direccion; lo que se debe impedir va por permisos.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook
     * @return void
     */
    public static function before_head(\core\hook\output\before_standard_head_html_generation $hook): void {
        global $PAGE;
        if (during_initial_install() || !isloggedin() || !ux::on('navestudiante')) {
            return;
        }
        // $PAGE->course es el sitio fuera de un curso; SITEID es el string '1'.
        $courseid = (int) $PAGE->course->id;
        if ($courseid === (int) SITEID) {
            return;
        }
        $acompana = has_capability('moodle/grade:viewall', \context_course::instance($courseid));
        if (self::hide_course_nav($courseid, (int) SITEID, $acompana)) {
            $hook->add_html('<style>.secondary-navigation { display: none !important; }</style>');
        }
    }

    /**
     * Si se oculta la franja de navegacion del curso. Funcion pura.
     *
     * @param int $courseid Curso de la pagina.
     * @param int $siteid
     * @param bool $acompana Si la persona ve las calificaciones de todo el curso (moodle/grade:viewall): docentes,
     *     con o sin edicion, gestores y administracion; no quien cursa.
     * @return bool
     */
    public static function hide_course_nav(int $courseid, int $siteid, bool $acompana): bool {
        return $courseid !== $siteid && $courseid > 0 && !$acompana;
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
