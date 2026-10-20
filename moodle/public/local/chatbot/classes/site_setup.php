<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Configuracion del sitio y del curso que acompana al plugin (funciones puras).
 *
 * Lo que el plugin trae en codigo viaja en el zip; esto es lo que se hizo a mano en cada Moodle
 * (HTML adicional del sitio, enlaces del curso) y que sin un guion habia que repetir a ojo. El guion
 * `cli/configurar_modulo1.php` aplica estas piezas; aqui vive la parte que no consulta la base y que
 * `cli/verificar_configuracion.php` comprueba.
 *
 * El HTML adicional del sitio es un solo campo que otras personas tambien pueden usar, asi que cada
 * bloque propio lleva una marca y la fusion solo toca los bloques que reconoce: lo ajeno se conserva.
 */
class site_setup {

    /** Prefijo de la marca que identifica un bloque propio dentro del HTML adicional. */
    const MARK = 'chatbot-m1:';

    /**
     * Marcas de los bloques que se pusieron a mano antes de existir el guion (13 al 17-10-2026).
     * La fusion los reemplaza para no dejar reglas duplicadas, pero no toca ningun otro.
     */
    const LEGACY = [
        'Solo VPS propio: oculta «Estado de tu recorrido»',
        'Solo VPS propio: oculta la tarjeta «Acompanamiento del Asistente IA»',
        'Solo VPS propio: oculta «¿Quieres mantener estas prioridades',
        'revision-final-bienvenida-17-10-2026',
        'consentimiento-automatico-17-10-2026',
        'cursor-pestanas-edicion-17-10-2026',
        'url-del-recorrido-directa-17-10-2026',
    ];

    /**
     * Bloques de HTML adicional del Modulo 1, en el orden en que se escriben.
     *
     * @param int $consentcmid Actividad de la declaracion de consentimiento (0 si el curso no la tiene aun):
     *     su boton manual de finalizacion se oculta porque la marca el asistente.
     * @return string[] Clave => bloque completo (<style> o <script>).
     */
    public static function head_blocks(int $consentcmid): array {
        $m = fn(string $id): string => '/* ' . self::MARK . $id . ' */';
        $bloques = [];

        $bloques['ocultos'] = '<style>' . $m('ocultos') . "\n"
            . "/* Perfil: sin la lista «Estado de tu recorrido». Diagnostico: sin la tarjeta de acompanamiento ni la pregunta\n"
            . "   final de la matriz de desarrollo. */\n"
            . "#page-local-chatbot-index #region-main h5:has(+ .list-group),\n"
            . "#page-local-chatbot-index #region-main h5 + .list-group { display: none !important; }\n"
            . ".local-chatbot-diag-intro-card { display: none !important; }\n"
            . ".local-chatbot-coach-report p:last-child:has(> strong:only-child) { display: none !important; }</style>";

        $bloques['aspecto'] = '<style>' . $m('aspecto') . "\n"
            . "/* Texto justificado en el contenido del curso. */\n"
            . ".path-course-view .course-content .activity-altcontent, .path-course-view .course-content .no-overflow,\n"
            . ".path-course-section .course-content .activity-altcontent, .path-course-section .course-content .no-overflow"
            . " { text-align: justify; }\n"
            . "/* Manito sobre las pestanas del curso, tambien en modo edicion (multitopic las marca como arrastrables). */\n"
            . ".path-course-view .course-content .nav-tabs .nav-link, .path-course-view .course-content .nav-tabs [draggable]"
            . " { cursor: pointer !important; }\n"
            . "body.format-multitopic .course-content ul.nav-tabs li a.draggable,\n"
            . "body.format-multitopic .course-content ul.nav-tabs li a { cursor: pointer !important; }</style>";

        $bloques['expandido'] = '<script>' . $m('expandido') . "\n"
            . "document.addEventListener('DOMContentLoaded', function() {\n"
            . "  // Subsecciones de la pestana abiertas por defecto.\n"
            . "  if (document.body.classList.contains('path-course-view')) {\n"
            . "    setTimeout(function() {\n"
            . "      document.querySelectorAll('.course-content [data-for=\"sectiontoggler\"][aria-expanded=\"false\"]')"
            . ".forEach(function(t) { t.click(); });\n"
            . "    }, 400);\n"
            . "  }\n"
            . "});\n"
            . "</script>";

        if ($consentcmid > 0) {
            $bloques['consentimiento'] = '<style>' . $m('consentimiento') . "\n"
                . "/* El consentimiento lo marca el asistente al aceptar y guardar: sin boton manual. */\n"
                . '#module-' . $consentcmid . ' [data-action="toggle-manual-completion"] { display: none !important; }</style>';
        }

        $bloques['url_directa'] = '<style>' . $m('url_directa') . "\n"
            . "#page-mod-url-view.chatbot-url-recorrido #region-main { visibility: hidden; }</style>\n"
            . '<script>' . $m('url_directa') . "\n"
            . "document.addEventListener('DOMContentLoaded', function() {\n"
            . "  if (document.body.id !== 'page-mod-url-view') { return; }\n"
            . "  var a = document.querySelector('.urlworkaround a, #region-main a[href*=\"/local/chatbot/\"]');\n"
            . "  if (!a || a.href.indexOf('/local/chatbot/') === -1) { return; }\n"
            . "  document.body.classList.add('chatbot-url-recorrido');\n"
            . "  window.location.replace(a.href);\n"
            . "});\n"
            . "</script>";

        return $bloques;
    }

    /**
     * Fusiona los bloques propios en el HTML adicional actual. Funcion pura e idempotente.
     *
     * Quita los bloques que lleven una marca propia o una de las antiguas (LEGACY) y agrega al final los
     * nuevos. Cualquier otro bloque, o texto suelto, queda tal cual.
     *
     * @param string $current Contenido actual de additionalhtmlhead.
     * @param string[] $blocks Resultado de head_blocks().
     * @return string
     */
    public static function merge_head(string $current, array $blocks): string {
        $current = str_replace("\r\n", "\n", $current);
        $conservar = preg_replace_callback('~<(style|script)\b[^>]*>.*?</\1>~s', function (array $m): string {
            return self::is_own_block($m[0]) ? '' : $m[0];
        }, $current);
        $conservar = trim(preg_replace("~\n{3,}~", "\n\n", (string) $conservar));
        $nuevo = implode("\n", $blocks);
        return $conservar === '' ? $nuevo : $conservar . "\n" . $nuevo;
    }

    /**
     * Si un bloque <style>/<script> es de este plugin (marca actual o antigua).
     *
     * @param string $block
     * @return bool
     */
    public static function is_own_block(string $block): bool {
        if (strpos($block, '/* ' . self::MARK) !== false) {
            return true;
        }
        foreach (self::LEGACY as $marca) {
            if (strpos($block, $marca) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reescribe un enlace a una pagina del asistente para el sitio y el curso de destino. Funcion pura.
     *
     * Al restaurar el curso en otro Moodle, los enlaces conservan el servidor y el id de origen. Cualquier
     * enlace que no apunte a /local/chatbot/ se devuelve igual.
     *
     * @param string $url
     * @param string $wwwroot Raiz del sitio de destino, sin barra final.
     * @param int $courseid Curso de destino.
     * @return string
     */
    public static function rewrite_url(string $url, string $wwwroot, int $courseid): string {
        if (!preg_match('~^https?://[^/]+(?:/[^?#]*?)?(/local/chatbot/[A-Za-z_]+\.php)(\?[^#]*)?(#.*)?$~', $url, $m)) {
            return $url;
        }
        $query = [];
        if (!empty($m[2])) {
            parse_str(substr($m[2], 1), $query);
        }
        $query['courseid'] = $courseid;
        return rtrim($wwwroot, '/') . $m[1] . '?' . http_build_query($query) . ($m[3] ?? '');
    }

    /**
     * Reescribe los enlaces a paginas del asistente dentro de un HTML (por ejemplo, el boton de la ficha).
     *
     * @param string $html
     * @param string $wwwroot
     * @param int $courseid
     * @return string
     */
    public static function rewrite_links(string $html, string $wwwroot, int $courseid): string {
        return preg_replace_callback('~href="([^"]*/local/chatbot/[^"]*)"~', function (array $m) use ($wwwroot, $courseid): string {
            $url = self::rewrite_url(html_entity_decode($m[1]), $wwwroot, $courseid);
            return 'href="' . htmlspecialchars($url, ENT_COMPAT) . '"';
        }, $html);
    }
}
