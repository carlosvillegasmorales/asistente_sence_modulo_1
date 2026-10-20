<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Puente entre el recorrido del asistente y el curso de Moodle.
 *
 * El Modulo 1 se vive en dos lugares: el curso, que tiene la narrativa y la ruta
 * formativa, y el asistente, donde la persona hace su perfil, su diagnostico, su
 * plan, su proyecto y su cierre. Sin un puente, el curso no sabe lo que pasa en
 * el asistente: su barra de progreso no avanza, las restricciones de acceso no se
 * desbloquean y los informes de finalizacion muestran un recorrido vacio.
 *
 * El puente es una actividad del curso por cada paso, reconocida por su idnumber
 * (M1-PASO-PERFIL y siguientes), con finalizacion manual. El plugin la marca
 * cuando el paso esta hecho de verdad y la desmarca si alguien la marca sin
 * haberlo hecho. La verdad vive en los datos del asistente; el curso la refleja.
 *
 * Sin actividades M1-PASO-* en el curso, el puente no hace nada: el asistente
 * funciona igual que siempre.
 */
class course_bridge {

    /** @var array Pasos del recorrido: codigo de la actividad, pagina del asistente y texto. */
    const STEPS = [
        'perfil' => ['code' => 'M1-PASO-PERFIL', 'path' => '/local/chatbot/index.php', 'label' => 'bridge_step_perfil'],
        'diagnostico' => ['code' => 'M1-PASO-DIAG', 'path' => '/local/chatbot/diagnostico.php',
            'label' => 'bridge_step_diagnostico'],
        'pdp' => ['code' => 'M1-PASO-PDP', 'path' => '/local/chatbot/pdp.php', 'label' => 'bridge_step_pdp'],
        'proyecto' => ['code' => 'M1-PASO-PROY', 'path' => '/local/chatbot/proyecto.php', 'label' => 'bridge_step_proyecto'],
        'cierre' => ['code' => 'M1-PASO-CIERRE', 'path' => '/local/chatbot/cierre.php', 'label' => 'bridge_step_cierre'],
    ];

    /**
     * Codigo de la actividad del curso con la declaracion de consentimiento (revision final de
     * Bienvenida y Perfilamiento, 17-10-2026): se marca completada sola cuando la persona acepta
     * las condiciones en su ficha, junto con el paso del perfil. No es una etapa de la ruta.
     */
    const CONSENT_CODE = 'M1-CONSENTIMIENTO';

    /**
     * Si un idnumber es el de la actividad del consentimiento. Funcion pura.
     *
     * @param string $idnumber
     * @return bool
     */
    public static function is_consent_code(string $idnumber): bool {
        return strtoupper(trim($idnumber)) === self::CONSENT_CODE;
    }

    /**
     * Paso al que corresponde un idnumber de actividad. Funcion pura.
     *
     * @param string $idnumber
     * @return string Clave del paso o cadena vacia si no es una actividad del puente.
     */
    public static function step_for_code(string $idnumber): string {
        $idnumber = strtoupper(trim($idnumber));
        foreach (self::STEPS as $step => $def) {
            if ($def['code'] === $idnumber) {
                return $step;
            }
        }
        return '';
    }

    /**
     * Estado real de cada paso, leido de los datos del asistente.
     *
     * Es la misma regla que usa la pagina de inicio del recorrido, para que el
     * curso y el asistente nunca digan cosas distintas. Incluye la Ruta Formativa
     * Base (ruta de 6 etapas): hecha cuando la persona recorrio los recursos fijos
     * de las unidades del curso.
     *
     * Se calcula una vez por peticion (la barra, el widget y el contexto del asistente lo piden
     * varias veces); sync() lo recalcula porque corre justo despues de que algo cambio.
     *
     * @param int $userid
     * @param int $courseid
     * @param bool $fresh Recalcular aunque ya este calculado en esta peticion.
     * @return array Clave del paso => true si esta hecho.
     */
    public static function step_states(int $userid, int $courseid, bool $fresh = false): array {
        static $cache = [];
        $clave = $userid . ':' . $courseid;
        if (!$fresh && isset($cache[$clave])) {
            return $cache[$clave];
        }
        $project = project::get($userid, $courseid);
        return $cache[$clave] = [
            'perfil' => profile::has_consent($userid, $courseid),
            'diagnostico' => diagnostic::has_responses($userid, $courseid),
            'ruta' => journey::ruta_status(self::ruta_resources($userid, $courseid, $fresh))['done'],
            'pdp' => pdp::has_active_plan($userid, $courseid),
            'proyecto' => $project && $project->status !== project::STATUS_BORRADOR,
            'cierre' => closure::is_complete($userid, $courseid),
        ];
    }

    /**
     * La ruta vigente: 6 etapas con el interruptor «route6», si no la original de 5.
     *
     * @return string[]
     */
    public static function route_keys(): array {
        return journey::route_keys(ux::on('route6'));
    }

    /**
     * Recursos fijos de las unidades del curso (codigo del Anexo G) y si la persona ya los recorrio.
     *
     * Lectura, caso y ejemplo cuentan al abrirse (evento VIEW que ya registra el plugin) o al
     * completarse en Moodle; el quiz, al terminar un intento. No depende de que el curso tenga
     * activada la finalizacion de actividades.
     *
     * La barra, el widget y el proximo paso lo piden varias veces por peticion: se calcula una vez.
     *
     * @param int $userid
     * @param int $courseid
     * @param bool $fresh Recalcular aunque ya este calculado en esta peticion.
     * @return array Lista de ['code', 'kind', 'done', 'cmid', 'section'].
     */
    public static function ruta_resources(int $userid, int $courseid, bool $fresh = false): array {
        global $DB;
        static $cache = [];
        if ($courseid <= 0 || $courseid == SITEID) {
            return [];
        }
        $clave = $userid . ':' . $courseid;
        if (!$fresh && isset($cache[$clave])) {
            return $cache[$clave];
        }
        $recursos = [];
        try {
            $modinfo = get_fast_modinfo($courseid, $userid);
        } catch (\Throwable $e) {
            return [];
        }
        $vistos = $DB->get_fieldset_select('local_chatbot_trace', 'DISTINCT eventcode',
            "userid = ? AND courseid = ? AND eventcode LIKE 'EVT-U%'", [$userid, $courseid]);
        foreach ($modinfo->cms as $cm) {
            $codigo = strtoupper(trim((string) $cm->idnumber));
            if (!preg_match('/^(U\d+)-(LF|CA|ER|QF)-\d+$/', $codigo, $m) || $cm->deletioninprogress || !$cm->visible) {
                continue;
            }
            [, $unidad, $tipo] = $m;
            if ($tipo === 'QF') {
                $hecho = $DB->record_exists_select('quiz_attempts', "quiz = ? AND userid = ? AND state = 'finished'",
                    [$cm->instance, $userid])
                    || in_array("EVT-{$unidad}-QF-SUBMIT", $vistos, true) || in_array("EVT-{$unidad}-QF-COMPLETE", $vistos, true);
            } else {
                $hecho = in_array("EVT-{$unidad}-{$tipo}-VIEW", $vistos, true)
                    || in_array("EVT-{$unidad}-{$tipo}-COMPLETE", $vistos, true);
            }
            $recursos[] = ['code' => $codigo, 'kind' => $tipo, 'done' => $hecho, 'cmid' => (int) $cm->id,
                'section' => (int) $cm->section];
        }
        return $cache[$clave] = $recursos;
    }

    /**
     * Donde se hace un paso: su pantalla del asistente, o la seccion del curso para la Ruta
     * Formativa Base (que vive en el curso).
     *
     * @param int $courseid
     * @param string $key Clave del paso.
     * @param string $anchor Ancla opcional.
     * @return \moodle_url
     */
    public static function step_url(int $courseid, string $key, string $anchor = ''): \moodle_url {
        if ($key === 'ruta') {
            foreach (self::ruta_resources(0, $courseid) as $r) {
                return new \moodle_url('/course/section.php', ['id' => $r['section']]);
            }
            return new \moodle_url('/course/view.php', ['id' => $courseid]);
        }
        return new \moodle_url(self::STEPS[$key]['path'], ['courseid' => $courseid], $anchor !== '' ? $anchor : null);
    }

    /**
     * Cadena de idioma del nombre corto de un paso. Funcion pura.
     *
     * En la ruta de 6 etapas son los nombres del documento del cliente (stage6_*), porque pide que
     * la barra «Tu ruta del módulo 1» tenga la misma estructura.
     *
     * @param string $key
     * @param bool $six Si rige la ruta de 6 etapas.
     * @return string
     */
    public static function step_name_key(string $key, bool $six = false): string {
        if ($six && isset(journey::NAMES6[$key])) {
            return 'stage6_' . $key;
        }
        return [
            'perfil' => 'journey_step_profile', 'diagnostico' => 'journey_step_diagnostic', 'ruta' => 'journey_step_ruta',
            'pdp' => 'journey_step_pdp', 'proyecto' => 'journey_step_project', 'cierre' => 'journey_step_closure',
        ][$key] ?? 'journey_step_profile';
    }

    /**
     * Modelo de la ruta para una persona: pasos en orden, cuales estan hechos, cuales son
     * opcionales o estan bloqueados, y a donde lleva cada uno. Lo usan la barra de ruta, la
     * barra del curso, el widget y el contexto del asistente, para que nunca digan cosas distintas.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $screen Paso de la pantalla abierta (0 si no es del recorrido).
     * @return array keys, total, done (pos => bool), optional (posiciones), locked (pos => bool),
     *               view (journey::view()), screen.
     */
    public static function route_model(int $userid, int $courseid, int $screen = 0): array {
        $keys = self::route_keys();
        $estados = self::step_states($userid, $courseid);
        $done = [];
        foreach ($keys as $i => $clave) {
            $done[$i + 1] = !empty($estados[$clave]);
        }
        $opcionales = journey::optional_positions($keys);
        // El bloqueo es de la ruta de 6 etapas: con el interruptor apagado vuelve la ruta de 5 tal cual era.
        $bloqueos = in_array('ruta', $keys, true) ? journey::locked($done, $keys) : [];
        return [
            'keys' => $keys, 'total' => count($keys), 'done' => $done, 'optional' => $opcionales,
            'locked' => $bloqueos, 'screen' => $screen,
            'view' => journey::view($done, $screen, count($keys), $opcionales, $bloqueos),
        ];
    }

    /**
     * Aviso que reemplaza una pantalla bloqueada: «obligatorio para poder continuar» (ruta de 6).
     *
     * @param int $userid
     * @param int $courseid
     * @param string $key Paso de la pantalla.
     * @return string HTML del aviso, o cadena vacia si la pantalla esta abierta.
     */
    public static function gate_html(int $userid, int $courseid, string $key): string {
        if (!ux::on('route6')) {
            return '';
        }
        $modelo = self::route_model($userid, $courseid);
        $pos = array_search($key, $modelo['keys'], true);
        if ($pos === false || empty($modelo['locked'][$pos + 1])) {
            return '';
        }
        // El primer paso obligatorio que falta es el que hay que hacer.
        foreach ($modelo['keys'] as $i => $clave) {
            if (empty($modelo['done'][$i + 1]) && !in_array($i + 1, $modelo['optional'], true)) {
                $url = self::step_url($courseid, $clave);
                $nombre = get_string(self::step_name_key($clave, in_array('ruta', $modelo['keys'], true)), 'local_chatbot');
                return '<div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2">'
                    . '<span>🔒 ' . get_string('gate_locked', 'local_chatbot', $nombre) . '</span>'
                    . '<a class="chatbot-gatebtn" href="' . $url->out() . '">'
                    . get_string('gate_go', 'local_chatbot', $nombre) . '</a></div>';
            }
        }
        return '';
    }

    /**
     * Actividades del puente presentes en el curso.
     *
     * @param int $courseid
     * @return \cm_info[] Clave del paso => actividad.
     */
    public static function step_cms(int $courseid): array {
        if ($courseid <= 0 || $courseid == SITEID) {
            return [];
        }
        $cms = [];
        foreach (get_fast_modinfo($courseid)->cms as $cm) {
            $step = self::step_for_code((string) $cm->idnumber);
            if ($step !== '' && !isset($cms[$step])) {
                $cms[$step] = $cm;
            }
        }
        return $cms;
    }

    /**
     * Estado que deberia tener cada actividad del puente. Funcion pura.
     *
     * @param array $states Resultado de step_states().
     * @param array $current Clave del paso => estado de finalizacion actual (COMPLETION_*).
     * @return array Clave del paso => estado que corresponde, solo para los que difieren.
     */
    public static function pending_changes(array $states, array $current): array {
        $cambios = [];
        foreach ($current as $step => $estado) {
            if (!array_key_exists($step, $states)) {
                continue;
            }
            $hecho = in_array((int) $estado, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true);
            if ($states[$step] && !$hecho) {
                $cambios[$step] = COMPLETION_COMPLETE;
            } else if (!$states[$step] && $hecho) {
                $cambios[$step] = COMPLETION_INCOMPLETE;
            }
        }
        return $cambios;
    }

    /**
     * Deja las actividades del puente iguales al estado real del recorrido.
     *
     * Idempotente y barato: si no hay actividades del puente, o ya coinciden, no
     * escribe nada. Nunca interrumpe la pagina: un fallo aqui no debe impedir que
     * la persona guarde su diagnostico.
     *
     * @param int $userid
     * @param int $courseid
     * @return array Clave del paso => estado aplicado.
     */
    public static function sync(int $userid, int $courseid): array {
        global $CFG, $DB;
        try {
            $cms = self::step_cms($courseid);
            if (empty($cms) || $userid <= 0) {
                return [];
            }
            $estados = self::step_states($userid, $courseid, true);
            if (!empty($estados['cierre'])) {
                // Al cerrar el Modulo 1 se abren los siguientes (ajustes del cliente del 08-10-2026),
                // tambien cuando la ultima condicion se cumple fuera de la pantalla de cierre.
                closure::unlock_next_modules($userid);
            }
            require_once($CFG->dirroot . '/lib/completionlib.php');
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $completion = new \completion_info($course);
            if (!$completion->is_enabled()) {
                return [];
            }
            // La actividad del consentimiento sigue al paso del perfil: aceptar las condiciones es parte de el.
            foreach (get_fast_modinfo($courseid)->cms as $cm) {
                if (self::is_consent_code((string) $cm->idnumber)) {
                    $cms['consentimiento'] = $cm;
                    $estados['consentimiento'] = !empty($estados['perfil']);
                    break;
                }
            }
            $actual = [];
            foreach ($cms as $step => $cm) {
                if ($completion->is_enabled($cm) == COMPLETION_TRACKING_MANUAL) {
                    $actual[$step] = (int) $completion->get_data($cm, false, $userid)->completionstate;
                }
            }
            $cambios = self::pending_changes($estados, $actual);
            foreach ($cambios as $step => $estado) {
                $completion->update_state($cms[$step], $estado, $userid);
            }
            return $cambios;
        } catch (\Throwable $e) {
            debugging('local_chatbot: no se pudo sincronizar el recorrido con el curso: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
            return [];
        }
    }

    /**
     * Prepara una pagina del recorrido, dentro del curso cuando corresponde.
     *
     * Con curso, la pagina se abre con el diseno del curso: la persona ve el menu
     * del curso, el indice lateral y la barra de progreso, y el widget del
     * asistente calcula el avance con los datos de ese curso. Antes la pagina se
     * abria fuera del curso y el widget mostraba el avance general, distinto del
     * de la pagina.
     *
     * @param string $path Ruta de la pagina (/local/chatbot/...).
     * @param string $titlekey Cadena del titulo.
     * @param string $headingkey Cadena del encabezado.
     * @param array $extraparams Parametros adicionales de la URL.
     * @return array [$courseid, $context]
     */
    public static function setup_page(string $path, string $titlekey, string $headingkey,
            array $extraparams = []): array {
        global $DB, $PAGE;

        $courseid = optional_param('courseid', 0, PARAM_INT);
        $course = ($courseid > 0 && $courseid != SITEID) ? $DB->get_record('course', ['id' => $courseid]) : false;

        if ($course) {
            // require_login con el curso comprueba la matricula y deja la pagina en su contexto.
            require_login($course);
            $context = \context_course::instance($course->id);
            $PAGE->set_pagelayout('incourse');
        } else {
            $courseid = 0;
            require_login();
            $context = \context_system::instance();
            $PAGE->set_context($context);
            $PAGE->set_pagelayout('standard');
        }
        require_capability('local/chatbot:use', $context);

        // Aviso de espera en las acciones que llaman al modelo: sin el, la pagina
        // parece colgada y la persona vuelve a pulsar, disparandolas dos veces.
        $PAGE->requires->js(new \moodle_url('/local/chatbot/js/espera.js'), true);

        $PAGE->set_url(new \moodle_url($path, ['courseid' => $courseid] + $extraparams));
        $PAGE->set_title(get_string($titlekey, 'local_chatbot'));
        $PAGE->set_heading($course ? format_string($course->fullname) : get_string($headingkey, 'local_chatbot'));
        if ($course) {
            $PAGE->navbar->add(get_string('nav_module', 'local_chatbot'),
                new \moodle_url('/local/chatbot/index.php', ['courseid' => $courseid]));
            if ($path !== '/local/chatbot/index.php') {
                $PAGE->navbar->add(get_string($headingkey, 'local_chatbot'));
            }
        }
        return [$courseid, $context];
    }

    /**
     * Enlace de vuelta a la seccion del curso donde vive el paso.
     *
     * @param int $courseid
     * @param string $step
     * @return \moodle_url|null null fuera de un curso.
     */
    public static function back_to_course(int $courseid, string $step): ?\moodle_url {
        if ($courseid <= 0) {
            return null;
        }
        $cms = self::step_cms($courseid);
        if (isset($cms[$step])) {
            return new \moodle_url('/course/section.php', ['id' => $cms[$step]->section]);
        }
        return new \moodle_url('/course/view.php', ['id' => $courseid]);
    }

    /**
     * Cual es el paso siguiente al que se acaba de completar. Funcion pura.
     *
     * Devuelve cadena vacia cuando el paso actual todavia no esta hecho (no hay
     * a donde empujar) o cuando ya no queda nada por delante. Los pasos opcionales
     * (el PDP en la ruta de 6) no se proponen como siguiente.
     *
     * @param string $paso Paso en el que esta la persona.
     * @param array $estados Resultado de step_states().
     * @param string[]|null $orden Ruta vigente; sin ella, la original de 5.
     * @return string Clave del paso siguiente, o cadena vacia.
     */
    public static function next_step(string $paso, array $estados, ?array $orden = null): string {
        $orden = $orden ?? array_keys(self::STEPS);
        $opcionales = in_array('ruta', $orden, true) ? journey::OPTIONAL6 : [];
        $i = array_search($paso, $orden, true);
        if ($i === false || empty($estados[$paso])) {
            return '';
        }
        for ($j = $i + 1; $j < count($orden); $j++) {
            if (empty($estados[$orden[$j]]) && !in_array($orden[$j], $opcionales, true)) {
                return $orden[$j];
            }
        }
        return '';
    }

    /**
     * Barra de ruta que va arriba de cada pantalla del recorrido: los pasos, el que esta
     * abierto marcado con «Estas aqui», y una linea que dice que sigue. Es lo primero que la
     * persona ve, asi que no depende de abrir el asistente para saber donde esta.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $key Paso de la pantalla que la muestra (perfil, diagnostico, pdp, proyecto, cierre).
     * @return string HTML.
     */
    public static function route_bar(int $userid, int $courseid, string $key): string {
        $pos = array_search($key, self::route_keys(), true);
        return self::back_to_course_top($courseid, $key)
            . self::route_bar_body($userid, $courseid, $pos === false ? 0 : $pos + 1);
    }

    /**
     * Boton «Volver al curso» al inicio de una pantalla del recorrido (ajustes del cliente del
     * 08-10-2026). Lleva a la seccion del curso de esa etapa, donde se ven las pestanas del curso.
     * El de abajo ya existia; este no depende de la barra de ruta.
     *
     * @param int $courseid
     * @param string $key Paso de la pantalla.
     * @return string HTML, vacio fuera de un curso o con el interruptor apagado.
     */
    public static function back_to_course_top(int $courseid, string $key): string {
        if (!ux::on('backtocourse') || !isset(self::STEPS[$key])) {
            return '';
        }
        $boton = self::back_to_course_button($courseid, $key, true);
        return $boton === '' ? '' : '<div class="chatbot-backtocourse">' . $boton . '</div>';
    }

    /**
     * Boton para regresar al curso, el mismo arriba y abajo de cada pantalla del recorrido.
     *
     * Con el interruptor «clearback» es un boton solido de alto contraste con el texto del
     * documento del cliente («regresar al curso»); apagado, el boton con borde de antes.
     *
     * @param int $courseid
     * @param string $key Paso de la pantalla.
     * @param bool $top Si va arriba (el de antes era chico y con flecha).
     * @return string HTML, vacio fuera de un curso.
     */
    public static function back_to_course_button(int $courseid, string $key, bool $top = false): string {
        $url = self::back_to_course($courseid, $key);
        if (!$url) {
            return '';
        }
        if (ux::on('clearback')) {
            return '<a class="chatbot-backbtn' . ($top ? '' : ' mb-4 me-2') . '" href="' . $url->out() . '">'
                . '<span class="chatbot-backbtn-arrow" aria-hidden="true">←</span>'
                . get_string('back_to_course_clear', 'local_chatbot') . '</a>';
        }
        return $top
            ? '<a class="btn btn-sm btn-outline-primary" href="' . $url->out() . '">↩ '
                . get_string('bridge_back_to_course', 'local_chatbot') . '</a>'
            : '<a class="btn btn-outline-primary mb-4 me-2" href="' . $url->out() . '">'
                . get_string('bridge_back_to_course', 'local_chatbot') . '</a>';
    }

    /**
     * Pasos de una barra de ruta en HTML: hecho, pendiente, «Estas aqui», opcional y bloqueado.
     *
     * @param array $modelo Resultado de route_model().
     * @param int $courseid
     * @param string $itemclass Clase de cada paso.
     * @return string HTML de los pasos (sin contenedor).
     */
    private static function route_items(array $modelo, int $courseid, string $itemclass): string {
        $items = '';
        foreach (journey::route_steps($modelo['done'], $modelo['screen'], $modelo['total'], $modelo['optional'],
                $modelo['locked']) as $paso) {
            $i = $paso['step'];
            $clave = $modelo['keys'][$i - 1];
            $clase = $paso['here'] ? 'is-here' : ($paso['done'] ? 'is-done' : ($paso['locked'] ? 'is-locked' : 'is-pending'));
            $clase .= ($paso['done'] ? ' is-complete' : '') . ($paso['optional'] ? ' is-optional' : '');
            $icono = $paso['done'] ? '✓' : ($paso['locked'] ? '🔒' : $i);
            $contenido = '<span class="chatbot-routebar-dot">' . $icono . '</span>'
                . '<span class="chatbot-routebar-label">'
                . get_string(self::step_name_key($clave, in_array('ruta', $modelo['keys'], true)), 'local_chatbot') . '</span>'
                . ($paso['optional'] ? '<span class="chatbot-routebar-optional">' . get_string('route_optional', 'local_chatbot')
                    . '</span>' : '')
                . ($paso['here'] ? '<span class="chatbot-routebar-here">' . get_string('route_here', 'local_chatbot') . '</span>' : '');
            // Un paso bloqueado no lleva enlace: no se puede abrir hasta completar lo anterior.
            $enlace = $paso['locked']
                ? '<span title="' . s(get_string('route_locked_hint', 'local_chatbot')) . '">' . $contenido . '</span>'
                : '<a href="' . self::step_url($courseid, $clave) . '"' . ($paso['here'] ? ' aria-current="step"' : '') . '>'
                    . $contenido . '</a>';
            $items .= '<li class="' . $itemclass . ' ' . $clase . '">' . $enlace . '</li>';
        }
        return $items;
    }

    /**
     * Linea de resumen de una barra de ruta: la etapa de la pantalla y su estado, o, fuera de
     * las pantallas del recorrido, la etapa en la que va la persona.
     *
     * @param array $modelo Resultado de route_model().
     * @param int $screen Paso de la pagina, 0 si ninguno.
     * @return string Texto plano.
     */
    private static function route_summary(array $modelo, int $screen): string {
        $seis = in_array('ruta', $modelo['keys'], true);
        $prefijo = $seis ? 'route6_summary_' : 'route_summary_';
        // Una etapa bloqueada no esta «en curso»: el aviso de la pantalla dice que falta antes.
        $resumen = journey::summary_state($modelo['view'], $screen, $modelo['locked'], $modelo['total']);
        if ($resumen['state'] === 'alldone') {
            return get_string('route_all_done', 'local_chatbot');
        }
        $a = ['n' => $resumen['n'], 'total' => $modelo['total'],
            'name' => get_string(self::step_name_key($modelo['keys'][$resumen['n'] - 1], $seis), 'local_chatbot')];
        if ($resumen['state'] === 'pending') {
            return get_string($prefijo . 'pending', 'local_chatbot', $a);
        }
        return get_string($prefijo . 'here', 'local_chatbot', $a) . ' '
            . get_string($prefijo . $resumen['state'], 'local_chatbot');
    }

    /**
     * Cuerpo de la barra de ruta (ver route_bar()).
     *
     * @param int $userid
     * @param int $courseid
     * @param int $screen
     * @return string
     */
    private static function route_bar_body(int $userid, int $courseid, int $screen): string {
        if (!ux::on('routebar')) {
            return '';
        }
        $modelo = self::route_model($userid, $courseid, $screen);
        $items = self::route_items($modelo, $courseid, 'chatbot-routebar-step');
        $vista = $modelo['view'];
        // La ruta de 6 habla de etapas y usa los nombres del documento del cliente.
        $seis = in_array('ruta', $modelo['keys'], true);
        $nombre = static function (int $pos) use ($modelo, $seis): string {
            return get_string(self::step_name_key($modelo['keys'][$pos - 1], $seis), 'local_chatbot');
        };
        $resumen = $screen > 0 ? self::route_summary($modelo, $screen) : '';
        $lista = '<ol class="chatbot-routebar-steps' . ($seis ? ' is-six' : '') . '">';
        if (ux::on('nextaction')) {
            // El proximo paso concreto reemplaza al «lo que sigue» por pantalla.
            return '<nav class="chatbot-routebar" aria-label="' . s(get_string('route_title', 'local_chatbot')) . '">'
                . '<div class="chatbot-routebar-title">' . get_string('route_title', 'local_chatbot') . '</div>'
                . $lista . $items . '</ol>'
                . '<p class="chatbot-routebar-summary">' . s($resumen) . '</p>'
                . self::next_action_html(self::next_action($userid, $courseid)) . '</nav>';
        }
        if ($vista['next'] > 0) {
            // Un pendiente anterior a la pantalla abierta es algo que falta, no lo que sigue.
            $resumen .= ' ' . get_string($vista['next'] < $screen ? 'route_summary_before' : 'route_summary_next',
                'local_chatbot', $nombre($vista['next']));
        } else if ($vista['pending'] > $modelo['total']) {
            $resumen .= ' ' . get_string('route_all_done', 'local_chatbot');
        }

        return '<nav class="chatbot-routebar" aria-label="' . s(get_string('route_title', 'local_chatbot')) . '">'
            . '<div class="chatbot-routebar-title">' . get_string('route_title', 'local_chatbot') . '</div>'
            . $lista . $items . '</ol>'
            . '<p class="chatbot-routebar-summary">' . s($resumen) . '</p></nav>';
    }

    /**
     * Hechos que necesita journey::next_action(), leidos de la base.
     *
     * La numeracion de las metas («Meta 2») es la misma que muestra el plan: mismo filtro de
     * estados y mismo orden.
     *
     * @param int $userid
     * @param int $courseid
     * @return array
     */
    public static function next_action_facts(int $userid, int $courseid): array {
        global $DB;
        $estados = self::step_states($userid, $courseid);
        $hechos = [
            'consent' => !empty($estados['perfil']), 'diagnostic' => !empty($estados['diagnostico']),
            'project' => !empty($estados['proyecto']), 'closure' => !empty($estados['cierre']),
            'plan_validated' => false, 'proposed' => 0, 'inplan' => 0, 'supports' => [], 'deadlines' => [],
            'now' => time(),
            'project_first' => ux::on('projectfirst'),
            'route6' => ux::on('route6'),
            'ruta' => !empty($estados['ruta']),
            'ruta_missing' => journey::ruta_status(self::ruta_resources($userid, $courseid))['missing'],
        ];
        $plan = $DB->get_record('local_chatbot_pdp', ['userid' => $userid, 'courseid' => $courseid]);
        if (!$plan) {
            return $hechos;
        }
        $hechos['plan_validated'] = $plan->status === pdp::PLAN_ACTIVO;
        $hechos['proposed'] = count(pdp::list_goals((int) $plan->id, [pdp::GOAL_PROPUESTO]));
        $metas = pdp::list_goals((int) $plan->id,
            [pdp::GOAL_ACEPTADO, pdp::GOAL_MODIFICADO, pdp::GOAL_POSTERGADO, pdp::GOAL_COMPLETADO]);
        $hechos['inplan'] = count($metas);
        $metas = ux::on('goalorder')
            ? pdp::order_goals($metas, $DB->get_records_menu('local_chatbot_dimension', null, '', 'id, sortorder'))
            : array_values($metas);
        foreach ($metas as $indice => $meta) {
            if (!in_array($meta->status, [pdp::GOAL_ACEPTADO, pdp::GOAL_MODIFICADO], true)) {
                continue;
            }
            $n = $indice + 1;
            if (!empty($meta->plazo)) {
                $hechos['deadlines'][] = ['n' => $n, 'id' => (int) $meta->id, 'plazo' => (int) $meta->plazo];
            }
            foreach (adaptive::list_for_goal((int) $meta->id) as $apoyo) {
                if (adaptive::is_terminal($apoyo->estado)) {
                    continue;
                }
                $revisiones = adaptive::list_reviews((int) $apoyo->id);
                $ultima = $revisiones ? end($revisiones) : null;
                $tipo = ($ultima && (int) $ultima->personconfirmed === -1) ? 'decidir'
                    : ($apoyo->estado === adaptive::STATE_PROPUESTA_IA ? 'iniciar'
                    : (adaptive::accepts_evidence($apoyo->estado) ? 'evidencia' : ''));
                $hechos['supports'][] = ['n' => $n, 'id' => (int) $apoyo->id, 'kind' => $tipo];
                break;
            }
        }
        return $hechos;
    }

    /**
     * El proximo paso de la persona, con su texto y el enlace al lugar exacto.
     *
     * @param int $userid
     * @param int $courseid
     * @return array journey::next_action() mas 'text' y 'url' (null si no hay a donde ir).
     */
    public static function next_action(int $userid, int $courseid): array {
        static $cache = [];
        $clave = $userid . ':' . $courseid;
        if (isset($cache[$clave])) {
            return $cache[$clave];
        }
        $accion = journey::next_action(self::next_action_facts($userid, $courseid));
        $a = $accion['a'];
        if ($accion['key'] === 'plazo') {
            $a = ['n' => $a['n'], 'fecha' => userdate($a['plazo'], get_string('strftimedateshort', 'langconfig'))];
        }
        if ($accion['key'] === 'ruta') {
            // «te falta el caso y el quiz»: los tipos de recurso que faltan, en palabras.
            $nombres = array_map(static function ($k) {
                return get_string('ruta_kind_' . strtolower($k), 'local_chatbot');
            }, (array) $a);
            $ultimo = array_pop($nombres);
            $a = $nombres ? implode(', ', $nombres) . get_string('list_and', 'local_chatbot') . $ultimo : (string) $ultimo;
        }
        $accion['text'] = get_string('na_' . $accion['key'], 'local_chatbot', $a);
        $accion['url'] = $accion['stepkey'] !== '' ? self::step_url($courseid, $accion['stepkey'], $accion['anchor']) : null;
        return $cache[$clave] = $accion;
    }

    /**
     * Bloque «Tu proximo paso» con su boton.
     *
     * @param array $accion Resultado de next_action().
     * @return string HTML.
     */
    public static function next_action_html(array $accion): string {
        $boton = $accion['url']
            ? ' <a class="btn btn-sm btn-primary ms-1 chatbot-nextaction-go" href="' . $accion['url']->out() . '">'
                . get_string('na_go', 'local_chatbot') . '</a>'
            : '';
        return '<div class="chatbot-nextaction"><span class="chatbot-nextaction-label">👉 '
            . get_string('na_label', 'local_chatbot') . ':</span> <strong>' . s($accion['text']) . '</strong>'
            . $boton . '</div>';
    }

    /**
     * Ruta compacta para la portada del curso y sus actividades: los pasos, el recurso
     * abierto si lo hay y el proximo paso. Solo en el curso que tiene las actividades del puente.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $resource Nombre del recurso abierto (vacio en la portada).
     * @param int $screen Paso de la pagina (la Ruta Formativa Base en sus recursos), 0 si ninguno.
     * @return string HTML, o cadena vacia si no corresponde.
     */
    public static function course_bar(int $userid, int $courseid, string $resource = '', int $screen = 0): string {
        if (!ux::on('coursebar') || $courseid <= 0 || empty(self::step_cms($courseid))) {
            return '';
        }
        $modelo = self::route_model($userid, $courseid, $screen);
        $donde = $resource !== ''
            ? '<span class="chatbot-coursebar-where">' . get_string('coursebar_here', 'local_chatbot', s($resource)) . '</span>'
            : '';
        $proximo = ux::on('nextaction') ? self::next_action_html(self::next_action($userid, $courseid)) : '';
        if (ux::on('coursebarfull')) {
            // Misma estructura que la barra de las pantallas del recorrido (lo pide el cliente).
            $seis = in_array('ruta', $modelo['keys'], true);
            return '<nav class="chatbot-routebar" id="chatbot-coursebar" aria-label="'
                . s(get_string('route_title', 'local_chatbot')) . '">'
                . '<div class="chatbot-coursebar-head"><span class="chatbot-routebar-title mb-0">'
                . get_string('route_title', 'local_chatbot') . '</span>' . $donde . '</div>'
                . '<ol class="chatbot-routebar-steps' . ($seis ? ' is-six' : '') . '">'
                . self::route_items($modelo, $courseid, 'chatbot-routebar-step') . '</ol>'
                . '<p class="chatbot-routebar-summary">' . s(self::route_summary($modelo, $screen)) . '</p>'
                . $proximo . '</nav>';
        }
        $pasos = self::route_items($modelo, $courseid, 'chatbot-coursebar-step');
        return '<nav class="chatbot-coursebar" id="chatbot-coursebar" aria-label="'
            . s(get_string('route_title', 'local_chatbot')) . '">'
            . '<div class="chatbot-coursebar-head"><span class="chatbot-routebar-title mb-0">'
            . get_string('route_title', 'local_chatbot') . '</span>' . $donde . '</div>'
            . '<ol class="chatbot-coursebar-steps">' . $pasos . '</ol>' . $proximo . '</nav>';
    }

    /**
     * Tarjeta que invita a seguir al paso siguiente.
     *
     * Terminar un paso y quedarse sin saber que sigue es donde la gente se
     * detiene. La tarjeta aparece solo cuando el paso actual esta hecho, y
     * desaparece cuando el siguiente tambien lo esta.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $paso Paso de la pagina que la muestra.
     * @return string HTML, o cadena vacia si no corresponde invitar a nada.
     */
    public static function next_step_card(int $userid, int $courseid, string $paso): string {
        $estados = self::step_states($userid, $courseid);
        $orden = self::route_keys();
        $siguiente = self::next_step($paso, $estados, $orden);

        if ($siguiente === '') {
            // Recorrido completo (los pasos obligatorios): se dice, en vez de dejar la pagina muda.
            $obligatorios = array_diff($orden, in_array('ruta', $orden, true) ? journey::OPTIONAL6 : []);
            $faltan = array_filter($obligatorios, static function ($k) use ($estados) {
                return empty($estados[$k]);
            });
            if (!empty($estados[$paso]) && empty($faltan)) {
                return '<div class="alert alert-success d-flex align-items-center justify-content-between'
                    . ' flex-wrap gap-2 mt-4"><div><strong>'
                    . get_string('next_done_title', 'local_chatbot') . '</strong><br><span class="small">'
                    . get_string('next_done_body', 'local_chatbot') . '</span></div></div>';
            }
            return '';
        }

        $url = self::step_url($courseid, $siguiente);
        return '<div class="card border-success shadow-sm mt-4 mb-4"><div class="card-body '
            . 'd-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">'
            . '<div><h6 class="mb-1">' . get_string('next_title_' . $paso, 'local_chatbot') . '</h6>'
            . '<p class="small text-muted mb-0">' . get_string('next_body_' . $siguiente, 'local_chatbot')
            . '</p></div>'
            . '<a href="' . $url . '" class="btn btn-success text-nowrap">'
            . get_string('next_go_' . $siguiente, 'local_chatbot') . '</a>'
            . '</div></div>';
    }

    /**
     * Regla de acceso de Moodle: completar la actividad de un paso. Funcion pura.
     *
     * @param int $cmid Actividad del paso previo.
     * @return string JSON de disponibilidad.
     */
    public static function availability_after(int $cmid): string {
        return json_encode(['op' => '&', 'c' => [['type' => 'completion', 'cm' => $cmid, 'e' => COMPLETION_COMPLETE]],
            'showc' => [true]]);
    }
}
