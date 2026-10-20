<?php
/**
 * Library callbacks for local_chatbot: renderiza el widget del asistente,
 * inyecta los enlaces del recorrido del Modulo 1 y sirve los archivos que la
 * persona adjunta en la conversacion.
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Sirve un archivo adjuntado por la persona en la conversacion, o un PDF de la
 * base de conocimiento del Tutor.
 *
 * Los adjuntos del chat y del proyecto son material de trabajo propio: solo
 * los recupera quien los subio. Los PDF de la base de conocimiento (area
 * kb_document) son material administrado compartido: los recupera quien tiene
 * la capacidad de gestionar el KB, sin importar quien los subio.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false si el archivo no existe o no corresponde entregarlo.
 */
function local_chatbot_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;

    $allowedareas = ['chat_attachment', 'project_attachment', 'kb_document'];
    if ($context->contextlevel != CONTEXT_SYSTEM || !in_array($filearea, $allowedareas, true)) {
        return false;
    }
    require_login();

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);

    if ($filearea === 'kb_document') {
        // Los PDF de la base de conocimiento no son de una persona: son material
        // administrado, y solo quien puede gestionar el KB puede recuperarlos.
        require_capability('local/chatbot:managekb', $context);
    } else {
        // En estas dos areas el itemid apunta a una fila con dueno, y solo esa
        // persona puede recuperar el archivo.
        $ownertable = ($filearea === 'chat_attachment')
            ? 'local_chatbot_messages' : 'local_chatbot_project';
        $owner = $DB->get_record($ownertable, ['id' => $itemid], 'id, userid');
        if (!$owner || (int) $owner->userid !== (int) $USER->id) {
            return false;
        }
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_chatbot', $filearea, $itemid, '/', $filename);
    if (!$file) {
        return false;
    }

    send_stored_file($file, null, 0, $forcedownload, $options);
}

/**
 * Enlace al recorrido del Modulo 1 en la navegacion principal.
 *
 * El texto 'Mi Modulo 1' existia desde la primera version, pero ningun menu lo
 * usaba: la unica puerta de entrada era el widget del asistente. Quien lo
 * cerraba se quedaba sin camino de vuelta a su perfil o su plan.
 *
 * @param global_navigation $navigation
 * @return void
 */
function local_chatbot_extend_navigation(global_navigation $navigation): void {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return;
    }
    if (!has_capability('local/chatbot:use', context_system::instance(), $USER)) {
        return;
    }

    $node = navigation_node::create(
        get_string('nav_module', 'local_chatbot'),
        new moodle_url('/local/chatbot/index.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_chatbot_module',
        new pix_icon('i/course', '')
    );
    $node->showinflatnavigation = true;
    $navigation->add_node($node);
}

/**
 * Callback estandar de Moodle para insertar HTML despues de la region principal.
 * Moodle invoca esta funcion en cada pagina via get_plugins_with_function('standard_after_main_region_html').
 *
 * @return string HTML del widget y scripts asociados.
 */
function local_chatbot_standard_after_main_region_html(): string {
    return local_chatbot_get_widget_html() . local_chatbot_get_analytics_button_html();
}

/**
 * Compatibilidad con llamadas directas anteriores.
 *
 * @return void
 */
function local_chatbot_before_footer(): void {
    echo local_chatbot_get_widget_html() . local_chatbot_get_analytics_button_html();
}

/**
 * Boton flotante que lleva a los indicadores del modulo.
 *
 * Va separado del widget del asistente: son para roles distintos (quien hace
 * el recorrido y quien gestiona el modulo) y el acceso a los indicadores no
 * debe depender de si la persona tiene habilitado el asistente. Se ancla a la
 * izquierda para no competir con el boton del asistente, que ocupa la derecha.
 *
 * Solo se muestra con local/chatbot:viewanalytics, el mismo permiso que exige
 * indicadores.php, asi que no amplia quien ve los datos: solo acorta el camino.
 *
 * @return string
 */
function local_chatbot_get_analytics_button_html(): string {
    global $PAGE;

    static $rendered = false;
    if ($rendered) {
        return '';
    }
    $rendered = true;

    if (!isloggedin() || isguestuser()) {
        return '';
    }
    if (!has_capability('local/chatbot:viewanalytics', context_system::instance())) {
        return '';
    }
    // Cursos sin asistente: tampoco el acceso flotante a los indicadores.
    $coursepage = isset($PAGE->course->id) ? (int) $PAGE->course->id : 0;
    if (\local_chatbot\ux::hidden_in_course($coursepage)) {
        return '';
    }

    // En la propia pagina de indicadores el boton apuntaria a donde ya se esta.
    // has_set_url() y no isset(): url es una propiedad magica de moodle_page,
    // que define __get pero no __isset, asi que isset() siempre da false.
    $pageurl = $PAGE->has_set_url() ? $PAGE->url->get_path() : '';
    if (strpos($pageurl, '/local/chatbot/indicadores.php') !== false) {
        return '';
    }

    $url = (new moodle_url('/local/chatbot/indicadores.php'))->out();
    $label = s(get_string('analytics_heading', 'local_chatbot'));

    return <<<HTML
    <a href="{$url}" class="chatbot-reports-fab" aria-label="{$label}">
        <svg class="chatbot-reports-fab-icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 3v16a2 2 0 0 0 2 2h16"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path></svg>
        <span class="chatbot-reports-fab-label">{$label}</span>
    </a>
HTML;
}

/**
 * Inyecta el widget del asistente y los enlaces del recorrido.
 *
 * @return string
 */
function local_chatbot_get_widget_html(): string {
    global $USER, $CFG, $PAGE, $OUTPUT;

    static $rendered = false;
    if ($rendered) {
        return '';
    }
    $rendered = true;

    if (!isloggedin() || isguestuser()) {
        return '';
    }

    $context = context_system::instance();
    if (!has_capability('local/chatbot:use', $context)) {
        return '';
    }

    $courseid = isset($PAGE->course->id) ? (int) $PAGE->course->id : 0;
    // SITEID viene como cadena '1', asi que la comparacion estricta nunca se
    // cumplia: en las paginas del sitio el widget tomaba el curso 1 en vez del
    // recorrido general. Mostraba un avance distinto del de la pagina, y lo que
    // la persona hacia ahi quedaba guardado con courseid=1.
    if ((int) $courseid === (int) SITEID) {
        $courseid = 0;
    }
    // Cursos sin asistente (por ejemplo, los Modulos 2 y 3): no se pinta nada.
    if (\local_chatbot\ux::hidden_in_course($courseid)) {
        return '';
    }
    // Actividad desde la que se abre el asistente: el Anexo E §11 pide registrar el recurso
    // de origen (source_resource). El servidor comprueba que pertenezca al curso.
    // $PAGE->cm se lee directo: moodle_page no define __isset, asi que empty($PAGE->cm) daba
    // siempre verdadero y el widget nunca sabia desde que actividad se abria (el recurso de
    // origen del Anexo E §11 quedaba vacio).
    $pagecm = $PAGE->cm;
    $cmid = ($pagecm && $courseid > 0) ? (int) $pagecm->id : 0;

    $sesskey = sesskey();
    $v = time();

    // Verificacion de consentimiento informado y avance en la ruta del Modulo 1. La ruta (5 pasos, o
    // las 6 etapas de los ajustes del cliente del 08-10-2026) sale de course_bridge::route_model(),
    // la misma que usan la barra de las paginas y la del curso.
    $hasconsent = \local_chatbot\profile::has_consent($USER->id, $courseid);
    $routekeys = \local_chatbot\course_bridge::route_keys();
    // has_set_url() y no isset(): url es una propiedad magica y isset() da siempre falso. Un recurso
    // fijo de una unidad (U1-LF-01) abierto en el curso es la etapa Ruta Formativa Base.
    $screenstep = \local_chatbot\ux::on('screencontext')
        ? \local_chatbot\journey::screen_step($PAGE->has_set_url() ? $PAGE->url->get_path() : '', $routekeys,
            $pagecm ? (string) $pagecm->idnumber : '')
        : 0;
    $rmodel = \local_chatbot\course_bridge::route_model($USER->id, $courseid, $screenstep);
    $totalsteps = $rmodel['total'];
    $jview = $rmodel['view'];
    $currentstep = $jview['focus'];
    $nexturl = $jview['next'] > 0
        ? \local_chatbot\course_bridge::step_url($courseid, $routekeys[$jview['next'] - 1])->out(false) : '';

    // El avance cuenta solo los pasos obligatorios: el PDP opcional no resta.
    $obligatorios = array_values(array_diff(range(1, $totalsteps), $rmodel['optional']));
    $completedcount = count(array_filter($obligatorios, static function ($i) use ($rmodel) {
        return !empty($rmodel['done'][$i]);
    }));
    $progresspct = round(($completedcount / max(1, count($obligatorios))) * 100);

    $titulos = ['perfil' => 'step_profile', 'diagnostico' => 'step_diagnostic', 'ruta' => 'step_ruta',
        'pdp' => 'step_pdp', 'proyecto' => 'step_project', 'cierre' => 'step_closing'];
    $stepnames = [];
    $steptitles = [];
    $stepurls = [];
    $stepdones = [];
    // Con la ruta de 6 etapas, los nombres del documento del cliente, iguales a los de la barra.
    $seisetapas = in_array('ruta', $routekeys, true);
    foreach ($routekeys as $indice => $clavepaso) {
        $pos = $indice + 1;
        $stepnames[$pos] = get_string(\local_chatbot\course_bridge::step_name_key($clavepaso, $seisetapas), 'local_chatbot');
        $steptitles[$pos] = $seisetapas ? $stepnames[$pos] : get_string($titulos[$clavepaso], 'local_chatbot');
        $stepurls[$pos] = \local_chatbot\course_bridge::step_url($courseid, $clavepaso)->out(false);
        $stepdones[$pos] = !empty($rmodel['done'][$pos]);
    }

    $journeysteps = [];
    $journeyitemshtml = '';
    for ($i = 1; $i <= $totalsteps; $i++) {
        $isdone = $stepdones[$i];
        $isactive = ($currentstep === $i);
        $statusclass = $isdone ? 'is-done' : ($isactive ? 'is-active' : 'is-pending');
        // «Estas aqui» se marca aparte del estado: un paso ya hecho tambien puede ser donde esta la persona.
        $statusclass .= $isactive ? ' is-here' : '';
        $surl = $stepurls[$i];
        $sname = s($stepnames[$i]);
        $stitle = s($steptitles[$i]);

        $journeysteps[] = [
            'step' => $i,
            'name' => $stepnames[$i],
            'title' => $steptitles[$i],
            'done' => $isdone,
            'active' => $isactive,
            'url' => $surl,
        ];

        $innericon = $isdone
            ? '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>'
            : '<span>' . $i . '</span>';

        $journeyitemshtml .= <<<HTML
            <a href="{$surl}" class="chatbot-journey-step {$statusclass}" data-step="{$i}" title="{$stitle}">
                <div class="chatbot-journey-step-circle">{$innericon}</div>
                <span class="chatbot-journey-step-label">{$sname}</span>
            </a>
HTML;
    }

    $steptagtext = ($currentstep <= $totalsteps)
        ? get_string($jview['focus_done'] ? 'journey_step_tag_done' : 'journey_step_tag', 'local_chatbot',
            ['current' => $currentstep, 'total' => $totalsteps])
        : get_string('journey_completed_all', 'local_chatbot');

    // Ajustes del cliente del 08-10-2026: el chat es solo Tutor y no navega.
    $tutoronly = \local_chatbot\ux::on('tutoronly');
    $tutoonlyclass = $tutoronly ? ' chatbot-tutor-only' : '';

    $journeyhtml = <<<HTML
        <!-- Barra de Progreso y Recorrido Mi Ruta Modulo 1 -->
        <div class="chatbot-journey-tracker" id="chatbot-journey-tracker">
            <div class="chatbot-journey-header" id="chatbot-journey-toggle" title="Ver / ocultar detalle de mi ruta">
                <div class="chatbot-journey-title-wrap">
                    <span class="chatbot-journey-icon">🗺️</span>
                    <strong class="chatbot-journey-title">Mi Ruta</strong>
                    <span class="chatbot-journey-badge">{$progresspct}%</span>
                </div>
                <div class="chatbot-journey-current-hint">
                    <span class="chatbot-journey-step-tag">{$steptagtext}</span>
                    <svg class="chatbot-journey-chevron" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </div>
            </div>
            <div class="chatbot-journey-stepper" id="chatbot-journey-stepper">
                <div class="chatbot-journey-progress-track">
                    <div class="chatbot-journey-progress-fill" style="width: {$progresspct}%;"></div>
                </div>
                <div class="chatbot-journey-steps">
                    {$journeyitemshtml}
                </div>
            </div>
        </div>
HTML;
    if ($tutoronly) {
        // La barra de las paginas ya muestra la ruta; en el chat era una via de navegacion.
        $journeyhtml = '';
    }

    // Pildoras segun el recurso abierto (lectura, caso, ejemplo) y aviso durante el quiz. El tipo
    // sale del codigo del Anexo G de la actividad, el mismo que usa la trazabilidad.
    $resourcechips = '';
    $quiznotice = '';
    $resourcename = '';
    if ($cmid > 0) {
        $resourcename = format_string($pagecm->name);
    }
    if ($cmid > 0 && \local_chatbot\ux::on('resourcechips')) {
        $kind = \local_chatbot\trace::resource_kind((string) $pagecm->idnumber);
        $etiqueta = trim(((string) $pagecm->idnumber) . ' ' . $resourcename);
        $porrecurso = [
            'LF' => [['explain', 'tutor'], ['example', 'tutor'], ['link', 'coach']],
            'CA' => [['case', 'tutor'], ['example', 'tutor'], ['link', 'coach']],
            'ER' => [['solved', 'tutor'], ['link', 'coach']],
            'QF' => [['concept', 'tutor']],
        ];
        foreach ($porrecurso[$kind] ?? [] as [$clave, $modo]) {
            if ($tutoronly && $modo !== 'tutor') {
                continue;
            }
            $resourcechips .= '<button type="button" class="chatbot-chip chatbot-chip-resource" data-mode="' . $modo . '"'
                . ' data-ask="' . s(get_string('rchip_q_' . $clave, 'local_chatbot', $etiqueta)) . '">'
                . '<span class="chatbot-chip-icon">📘</span><span class="chatbot-chip-text">'
                . s(get_string('rchip_' . $clave, 'local_chatbot')) . '</span></button>';
        }
        if ($kind === 'QF' && \local_chatbot\assistant::has_protected_quiz($USER->id, $courseid)) {
            $quiznotice = '<div class="chatbot-quiz-notice" role="note">🔒 '
                . s(get_string('quiz_notice', 'local_chatbot')) . '</div>';
        }
    }

    $chipwhere = s(get_string('journey_chip_whereami', 'local_chatbot'));
    // Con un solo modo en el chat, «¿Que hace este modo?» no tiene sentido.
    $chipmode = s(get_string($tutoronly ? 'journey_chip_whatmode_tutoronly' : 'journey_chip_whatmode', 'local_chatbot'));
    $chipnext = s(get_string('journey_chip_nextstep', 'local_chatbot'));

    $chipshtml = <<<HTML
        {$quiznotice}
        <div class="chatbot-quick-chips" id="chatbot-quick-chips">
            {$resourcechips}
            <button type="button" class="chatbot-chip" data-chip="whereami" title="Consultar en qué etapa de la ruta estás">
                <span class="chatbot-chip-icon">📍</span>
                <span class="chatbot-chip-text">{$chipwhere}</span>
            </button>
            <button type="button" class="chatbot-chip" data-chip="whatmode" title="Conocer el rol y propósito del modo actual">
                <span class="chatbot-chip-icon">💡</span>
                <span class="chatbot-chip-text">{$chipmode}</span>
            </button>
            <button type="button" class="chatbot-chip" data-chip="nextstep" title="Avanzar al siguiente hito de la ruta">
                <span class="chatbot-chip-icon">🚀</span>
                <span class="chatbot-chip-text">{$chipnext}</span>
            </button>
        </div>
HTML;
    if ($tutoronly) {
        $chipshtml = preg_replace('#<button[^>]*data-chip="nextstep".*?</button>#s', '', $chipshtml);
    }

    // Proximo paso concreto (classes/journey.php::next_action): la misma accion que muestran la
    // barra de ruta y el curso, y el boton «Siguiente paso» lleva al lugar exacto.
    $nextaction = \local_chatbot\ux::on('nextaction')
        ? \local_chatbot\course_bridge::next_action($USER->id, $courseid) : null;
    if ($nextaction) {
        $nexturl = $nextaction['url'] ? $nextaction['url']->out(false) : '';
    }

    // Mensaje de bienvenida del widget: dice donde esta la persona (la pantalla abierta) y,
    // aparte, lo que sigue. Se arma aqui para que viva en las cadenas de idioma.
    if ($currentstep > $totalsteps) {
        $journeywelcome = "\n\n" . get_string('journey_welcome_all', 'local_chatbot');
    } else {
        $journeywelcome = "\n\n" . get_string('journey_welcome_here', 'local_chatbot', $stepnames[$currentstep])
            . get_string($jview['focus_done'] ? 'journey_welcome_here_done' : 'journey_welcome_here_open', 'local_chatbot');
        if (!$nextaction && $jview['next'] > 0) {
            $journeywelcome .= get_string('journey_welcome_next', 'local_chatbot', $stepnames[$jview['next']]);
        }
    }
    if ($nextaction && $nextaction['key'] !== 'completo' && !$tutoronly) {
        $journeywelcome .= "\n\n👉 **" . get_string('na_label', 'local_chatbot') . ':** ' . $nextaction['text'];
    }

    $journeyjson = json_encode([
        'welcome' => $journeywelcome,
        'screenStep' => $screenstep,
        'focusDone' => $jview['focus_done'],
        'currentStep' => $currentstep,
        'completedCount' => $completedcount,
        'progressPct' => $progresspct,
        'nextUrl' => $nexturl,
        'steps' => $journeysteps,
    ], JSON_UNESCAPED_UNICODE);

    $starturl = new moodle_url('/local/chatbot/index.php', ['courseid' => $courseid]);
    $profiles = \local_chatbot\assistant::get_profiles_catalog();

    // Deteccion contextual del perfil sugerido segun la pagina donde navega el usuario.
    // has_set_url() y no isset(): con isset() la ruta nunca se leia. El widget abria siempre en
    // Tutor (tambien en el plan, donde el Tutor no ve las metas) y, peor, no sabia que estaba
    // sobre el diagnostico: no enviaba la etapa y las reglas de la fase de diagnostico
    // (RU-DIAG-002) solo se aplicaban si la persona estaba en modo Coach.
    $pageurl = $PAGE->has_set_url() ? $PAGE->url->get_path() : '';
    $suggestedmode = \local_chatbot\assistant::MODE_TUTOR;
    if (\local_chatbot\ux::on('modebyscreen') && !$tutoronly) {
        if (strpos($pageurl, '/local/chatbot/pdp.php') !== false || strpos($pageurl, '/local/chatbot/diagnostico.php') !== false) {
            $suggestedmode = \local_chatbot\assistant::MODE_COACH;
        } else if (strpos($pageurl, '/local/chatbot/proyecto.php') !== false) {
            $suggestedmode = \local_chatbot\assistant::MODE_EVALUADOR;
        }
    }
    $modeautojson = $suggestedmode !== \local_chatbot\assistant::MODE_TUTOR ? 'true' : 'false';
    // Interruptores de experiencia que el widget necesita conocer (classes/ux.php).
    $uxjson = json_encode([
        'feedback' => \local_chatbot\ux::on('feedback'),
        'retry' => \local_chatbot\ux::on('retry'),
        'goalthreads' => \local_chatbot\ux::on('goalthreads') && !$tutoronly,
        'tutorOnly' => $tutoronly,
        'streaming' => \local_chatbot\ux::on('streaming')
            && \local_chatbot\ai\provider_factory::get_active() === 'gemini',
    ]);

    $initialprofile = $profiles[$suggestedmode] ?? $profiles[\local_chatbot\assistant::MODE_TUTOR];

    // Si el widget se esta pintando sobre el instrumento diagnostico, que etapa
    // se esta respondiendo. Se resuelve aqui, desde $PAGE->url, y no en el
    // navegador: la ruta que ve el cliente no lleva la etapa, y adivinarla
    // dejaria la restriccion de RU-DIAG-002 dependiendo de la pagina equivocada.
    // El servidor igual comprueba si esa etapa ya fue enviada antes de aplicar
    // la fase (assistant::resolve_phase).
    $diagstage = '';
    if (strpos($pageurl, 'diagnostico.php') !== false) {
        // has_set_url() y no isset(), por la misma razon que $pageurl.
        $requested = $PAGE->has_set_url() ? $PAGE->url->get_param('stage') : null;
        $diagstage = in_array($requested, [
            \local_chatbot\diagnostic::STAGE_ENTRADA,
            \local_chatbot\diagnostic::STAGE_SALIDA,
        ], true) ? $requested : \local_chatbot\diagnostic::STAGE_ENTRADA;
    }

    $profilecards = '';
    $timinglbl = s(get_string('widget_role_timing_badge', 'local_chatbot'));
    $purposelbl = s(get_string('widget_role_purpose_badge', 'local_chatbot'));
    $currentlbl = s(get_string('widget_role_current', 'local_chatbot'));

    foreach ($profiles as $pkey => $pdata) {
        $isactive = ($pkey === $suggestedmode) ? ' is-active' : '';
        $letter = s($pdata['letter']);
        $pname = s($pdata['name']);
        $timing = s($pdata['timing']);
        $purpose = s($pdata['purpose']);

        $profilecards .= <<<HTML
            <div class="chatbot-role-card{$isactive}" data-mode="{$pkey}">
                <div class="chatbot-role-card-header">
                    <span class="chatbot-role-card-badge">Modo {$letter}</span>
                    <strong class="chatbot-role-card-name">{$pname}</strong>
                    <span class="chatbot-role-card-indicator" title="{$currentlbl}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    </span>
                </div>
                <div class="chatbot-role-card-body">
                    <div class="chatbot-role-meta-row">
                        <span class="chatbot-role-meta-label">{$timinglbl}:</span>
                        <span class="chatbot-role-meta-text">{$timing}</span>
                    </div>
                    <div class="chatbot-role-meta-row">
                        <span class="chatbot-role-meta-label">{$purposelbl}:</span>
                        <span class="chatbot-role-meta-text">{$purpose}</span>
                    </div>
                </div>
            </div>
HTML;
    }

    $isadmin = is_siteadmin() || has_capability('moodle/site:config', $context);
    $isadminjson = $isadmin ? 'true' : 'false';

    $adminbuttonhtml = '';
    $admindrawerhtml = '';
    $adminmodalhtml = '';

    if ($isadmin) {
        $adminbtntitle = s(get_string('admin_btn_tooltip', 'local_chatbot'));
        $admindrawertitle = s(get_string('admin_drawer_shortcut', 'local_chatbot'));
        $admindrawerdesc = s(get_string('admin_drawer_shortcut_desc', 'local_chatbot'));

        $adminmodaltitle = s(get_string('admin_modal_title', 'local_chatbot'));
        $adminmodalbadge = s(get_string('admin_modal_badge', 'local_chatbot'));
        $adminmodaldesc = s(get_string('admin_modal_desc', 'local_chatbot'));
        $adminactiveprovider = s(get_string('admin_active_provider', 'local_chatbot'));
        $adminactiveproviderdesc = s(get_string('admin_active_provider_desc', 'local_chatbot'));
        $adminkeylabel = s(get_string('admin_key_label', 'local_chatbot'));
        $adminkeyplaceholder = s(get_string('admin_key_placeholder_new', 'local_chatbot'));
        $adminmodelpreset = s(get_string('admin_model_preset', 'local_chatbot'));
        $admintestbtn = s(get_string('admin_test_btn', 'local_chatbot'));
        $adminsavebtn = s(get_string('admin_save_btn', 'local_chatbot'));
        $adminclosebtn = s(get_string('admin_close_btn', 'local_chatbot'));
        $admingetkeylink = s(get_string('admin_get_key_link', 'local_chatbot'));
        $adminmoodlesettingslink = s(get_string('admin_moodle_settings_link', 'local_chatbot'));

        $adminbuttonhtml = <<<HTML
                    <button class="chatbot-header-btn chatbot-admin-header-btn" id="chatbot-admin-config-btn" title="{$adminbtntitle}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                    </button>
HTML;

        $admindrawerhtml = <<<HTML
                    <div class="chatbot-role-admin-card" id="chatbot-role-admin-card" title="{$adminbtntitle}">
                        <div class="chatbot-role-admin-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                        </div>
                        <div class="chatbot-role-admin-info">
                            <div class="chatbot-role-admin-title">{$admindrawertitle}</div>
                            <div class="chatbot-role-admin-desc">{$admindrawerdesc}</div>
                        </div>
                        <span class="chatbot-role-admin-badge">Admin</span>
                    </div>
HTML;

        if (has_capability('local/chatbot:viewanalytics', $context)) {
            $analyticsurl = (new moodle_url('/local/chatbot/indicadores.php'))->out(false);
            $analyticslabel = s(get_string('nav_analytics', 'local_chatbot'));
            $admindrawerhtml .= <<<HTML
                    <a href="{$analyticsurl}" class="chatbot-role-admin-card" style="text-decoration:none; margin-top:8px;">
                        <div class="chatbot-role-admin-icon" style="background:rgba(59,130,246,0.1); color:#2563EB;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                        </div>
                        <div class="chatbot-role-admin-info">
                            <div class="chatbot-role-admin-title">{$analyticslabel}</div>
                            <div class="chatbot-role-admin-desc">Panel de analítica y métricas del módulo</div>
                        </div>
                        <span class="chatbot-role-admin-badge" style="background:#EFF6FF; color:#2563EB;">Datos</span>
                    </a>
HTML;
        }

        $adminmodalhtml = <<<HTML
        <!-- Modal de Administracion Exclusivo para Administrador -->
        <div class="chatbot-admin-modal-overlay" id="chatbot-admin-modal-overlay" style="display: none;">
            <div class="chatbot-admin-modal" id="chatbot-admin-modal" role="dialog" aria-modal="true" aria-labelledby="chatbot-admin-modal-title">
                <div class="chatbot-admin-modal-header">
                    <div class="chatbot-admin-modal-title-area">
                        <span class="chatbot-admin-badge">{$adminmodalbadge}</span>
                        <h3 class="chatbot-admin-modal-title" id="chatbot-admin-modal-title">{$adminmodaltitle}</h3>
                    </div>
                    <button type="button" class="chatbot-admin-modal-close" id="chatbot-admin-modal-close" title="{$adminclosebtn}">&times;</button>
                </div>
                <div class="chatbot-admin-modal-body">
                    <p class="chatbot-admin-desc">{$adminmodaldesc}</p>

                    <!-- Selector de Proveedor Activo -->
                    <div class="chatbot-admin-section">
                        <div class="chatbot-admin-label">
                            <strong>{$adminactiveprovider}</strong>
                            <span class="chatbot-admin-hint">{$adminactiveproviderdesc}</span>
                        </div>
                        <div class="chatbot-admin-provider-selector">
                            <label class="chatbot-admin-radio-card" for="provider-radio-gemini">
                                <input type="radio" name="chatbot_active_provider" value="gemini" id="provider-radio-gemini">
                                <span class="chatbot-admin-radio-content">
                                    <span class="provider-radio-icon">✦</span>
                                    <span class="provider-radio-name">Google Gemini</span>
                                    <span class="provider-radio-tag" id="tag-status-gemini">...</span>
                                </span>
                            </label>
                            <label class="chatbot-admin-radio-card" for="provider-radio-anthropic">
                                <input type="radio" name="chatbot_active_provider" value="anthropic" id="provider-radio-anthropic">
                                <span class="chatbot-admin-radio-content">
                                    <span class="provider-radio-icon">◈</span>
                                    <span class="provider-radio-name">Claude (Anthropic)</span>
                                    <span class="provider-radio-tag" id="tag-status-anthropic">...</span>
                                </span>
                            </label>
                            <label class="chatbot-admin-radio-card" for="provider-radio-openai">
                                <input type="radio" name="chatbot_active_provider" value="openai" id="provider-radio-openai">
                                <span class="chatbot-admin-radio-content">
                                    <span class="provider-radio-icon">✺</span>
                                    <span class="provider-radio-name">OpenAI</span>
                                    <span class="provider-radio-tag" id="tag-status-openai">...</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Pestanas de configuracion por proveedor -->
                    <div class="chatbot-admin-tabs">
                        <button type="button" class="chatbot-admin-tab active" data-tab="tab-gemini">Google Gemini</button>
                        <button type="button" class="chatbot-admin-tab" data-tab="tab-claude">Claude (Anthropic)</button>
                        <button type="button" class="chatbot-admin-tab" data-tab="tab-openai">OpenAI</button>
                    </div>

                    <!-- Tab Gemini -->
                    <div class="chatbot-admin-tab-content active" id="tab-gemini">
                        <div class="chatbot-admin-field-group">
                            <div class="chatbot-admin-field-header">
                                <label for="admin-gemini-key">{$adminkeylabel}</label>
                                <span class="chatbot-admin-key-badge" id="gemini-key-status">...</span>
                            </div>
                            <div class="chatbot-admin-input-wrap">
                                <input type="text" id="admin-gemini-key" class="chatbot-admin-input chatbot-admin-input-secret" placeholder="{$adminkeyplaceholder}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other">
                                <button type="button" class="chatbot-admin-toggle-eye" data-target="admin-gemini-key" title="Mostrar/ocultar clave">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                            <div class="chatbot-admin-input-subtext">
                                <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer">↗ {$admingetkeylink}</a>
                            </div>
                        </div>

                        <div class="chatbot-admin-field-group">
                            <label for="admin-gemini-model-preset">{$adminmodelpreset}</label>
                            <select id="admin-gemini-model-preset" class="chatbot-admin-select">
                                <option value="gemini-3.8-flash">Gemini 3.8 Flash (Recomendado, ultra rápido)</option>
                                <option value="gemini-3.6-flash">Gemini 3.6 Flash (Oficial recomendado por Google)</option>
                                <option value="gemini-3.5-flash-lite">Gemini 3.5 Flash Lite (Económico)</option>
                                <option value="gemini-flash-latest">Gemini Flash Latest (Siempre actualizado)</option>
                                <option value="gemini-3.1-pro-preview">Gemini 3.1 Pro Preview (Máximo razonamiento)</option>
                                <option value="custom">Otro modelo (Personalizado)...</option>
                            </select>
                            <div id="admin-gemini-model-custom-wrap" class="chatbot-admin-custom-wrap" style="display:none; margin-top:8px;">
                                <input type="text" id="admin-gemini-model" class="chatbot-admin-input" placeholder="Nombre exacto del modelo">
                            </div>
                        </div>

                        <div class="chatbot-admin-test-row">
                            <button type="button" class="chatbot-admin-test-btn" data-provider="gemini">
                                <span>{$admintestbtn}</span>
                            </button>
                            <div class="chatbot-admin-test-result" id="gemini-test-result"></div>
                        </div>
                    </div>

                    <!-- Tab Claude -->
                    <div class="chatbot-admin-tab-content" id="tab-claude">
                        <div class="chatbot-admin-field-group">
                            <div class="chatbot-admin-field-header">
                                <label for="admin-anthropic-key">{$adminkeylabel}</label>
                                <span class="chatbot-admin-key-badge" id="anthropic-key-status">...</span>
                            </div>
                            <div class="chatbot-admin-input-wrap">
                                <input type="text" id="admin-anthropic-key" class="chatbot-admin-input chatbot-admin-input-secret" placeholder="{$adminkeyplaceholder}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other">
                                <button type="button" class="chatbot-admin-toggle-eye" data-target="admin-anthropic-key" title="Mostrar/ocultar clave">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                            <div class="chatbot-admin-input-subtext">
                                <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener noreferrer">↗ {$admingetkeylink}</a>
                            </div>
                        </div>

                        <div class="chatbot-admin-field-group">
                            <label for="admin-anthropic-model-preset">{$adminmodelpreset}</label>
                            <select id="admin-anthropic-model-preset" class="chatbot-admin-select">
                                <option value="claude-3-7-sonnet-20250219">Claude 3.7 Sonnet (Nuevo modelo híbrido y potente)</option>
                                <option value="claude-3-5-sonnet-20241022">Claude 3.5 Sonnet (Excelente capacidad pedagógica)</option>
                                <option value="claude-3-5-haiku-20241022">Claude 3.5 Haiku (Rápido y económico)</option>
                                <option value="claude-haiku-4-5">Claude Haiku 4.5</option>
                                <option value="custom">Otro modelo (Personalizado)...</option>
                            </select>
                            <div id="admin-anthropic-model-custom-wrap" class="chatbot-admin-custom-wrap" style="display:none; margin-top:8px;">
                                <input type="text" id="admin-anthropic-model" class="chatbot-admin-input" placeholder="Nombre exacto del modelo">
                            </div>
                        </div>

                        <div class="chatbot-admin-test-row">
                            <button type="button" class="chatbot-admin-test-btn" data-provider="anthropic">
                                <span>{$admintestbtn}</span>
                            </button>
                            <div class="chatbot-admin-test-result" id="anthropic-test-result"></div>
                        </div>
                    </div>

                    <!-- Tab OpenAI -->
                    <div class="chatbot-admin-tab-content" id="tab-openai">
                        <div class="chatbot-admin-field-group">
                            <div class="chatbot-admin-field-header">
                                <label for="admin-openai-key">{$adminkeylabel}</label>
                                <span class="chatbot-admin-key-badge" id="openai-key-status">...</span>
                            </div>
                            <div class="chatbot-admin-input-wrap">
                                <input type="text" id="admin-openai-key" class="chatbot-admin-input chatbot-admin-input-secret" placeholder="{$adminkeyplaceholder}" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other">
                                <button type="button" class="chatbot-admin-toggle-eye" data-target="admin-openai-key" title="Mostrar/ocultar clave">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                </button>
                            </div>
                            <div class="chatbot-admin-input-subtext">
                                <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">↗ {$admingetkeylink}</a>
                            </div>
                        </div>

                        <div class="chatbot-admin-field-group">
                            <label for="admin-openai-model-preset">{$adminmodelpreset}</label>
                            <select id="admin-openai-model-preset" class="chatbot-admin-select">
                                <option value="gpt-4o-mini">GPT-4o Mini (Recomendado, rápido y eficiente)</option>
                                <option value="gpt-4o">GPT-4o (Alta capacidad multimodal)</option>
                                <option value="o3-mini">o3-mini (Razonamiento lógico profundo)</option>
                                <option value="gpt-4.5-preview">GPT-4.5 Preview</option>
                                <option value="custom">Otro modelo (Personalizado)...</option>
                            </select>
                            <div id="admin-openai-model-custom-wrap" class="chatbot-admin-custom-wrap" style="display:none; margin-top:8px;">
                                <input type="text" id="admin-openai-model" class="chatbot-admin-input" placeholder="Nombre exacto del modelo">
                            </div>
                        </div>

                        <div class="chatbot-admin-test-row">
                            <button type="button" class="chatbot-admin-test-btn" data-provider="openai">
                                <span>{$admintestbtn}</span>
                            </button>
                            <div class="chatbot-admin-test-result" id="openai-test-result"></div>
                        </div>
                    </div>
                </div>

                <div class="chatbot-admin-modal-footer">
                    <a href="{$CFG->wwwroot}/admin/settings.php?section=local_chatbot" target="_blank" class="chatbot-admin-link-settings">
                        {$adminmoodlesettingslink} ↗
                    </a>
                    <div class="chatbot-admin-footer-actions">
                        <button type="button" class="chatbot-admin-cancel-btn" id="chatbot-admin-cancel-btn">{$adminclosebtn}</button>
                        <button type="button" class="chatbot-admin-save-btn" id="chatbot-admin-save-btn">
                            <span class="chatbot-admin-save-text">{$adminsavebtn}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
HTML;
    }

    $botname = get_string('botname', 'local_chatbot');
    $cleartitle = s(get_string('widget_clear_title', 'local_chatbot'));
    $placeholder = $hasconsent ? get_string('type_message', 'local_chatbot') : 'Completa tu perfil para conversar con el asistente';
    $logo = $CFG->wwwroot . '/local/chatbot/pix/interfases_logo.png';
    $rolebtntitle = get_string('widget_role_btn_title', 'local_chatbot');
    $drawertitle = get_string('widget_role_drawer_title', 'local_chatbot');
    $drawersubtitle = get_string('widget_role_drawer_subtitle', 'local_chatbot');

    $profilesjson = json_encode($profiles, JSON_UNESCAPED_UNICODE);
    $hasconsentjson = $hasconsent ? 'true' : 'false';

    // Si no ha aceptado consentimiento aun, se muestra una tarjeta explicativa dentro del chat
    // y se desactiva el input, pero el boton del bot SIEMPRE abre la ventana.
    $initialmessageshtml = '';
    if (!$hasconsent) {
        $consentcta = get_string('widget_needsconsent', 'local_chatbot');
        $consentaction = get_string('action_gotostart', 'local_chatbot');
        $initialmessageshtml = <<<HTML
        <div class="chatbot-consent-card">
            <div class="chatbot-consent-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#4F46E5" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <h4>Bienvenida/o al Asistente</h4>
            <p>{$consentcta}</p>
            <a href="{$starturl}" class="chatbot-consent-card-btn">{$consentaction}</a>
        </div>
HTML;
    }

    $inputdisabled = $hasconsent ? '' : ' disabled="disabled"';

    $html = <<<HTML
    <link rel="stylesheet" type="text/css" href="{$CFG->wwwroot}/local/chatbot/styles.css?v={$v}">
    <!-- Aislamiento de AMD/RequireJS para evitar colisiones con marked y DOMPurify -->
    <script>
        if (typeof define === 'function' && define.amd) {
            window.__moodleAmdBackup = define.amd;
            define.amd = false;
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/marked@11.1.1/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.8/dist/purify.min.js"></script>
    <script>
        if (window.__moodleAmdBackup) {
            define.amd = window.__moodleAmdBackup;
            delete window.__moodleAmdBackup;
        }
    </script>
    <div class="chatbot-overlay" id="chatbot-overlay"></div>
    <div id="local-chatbot-widget" class="chatbot-widget-container{$tutoonlyclass}">
        <button type="button" class="chatbot-toggle-btn" id="chatbot-toggle-btn" title="Abrir asistente">
            <span class="chatbot-toggle-pulse"></span>
            <span class="chatbot-toggle-inner">
                <img src="{$logo}" alt="{$botname}" class="chatbot-toggle-avatar" onerror="this.style.display='none'">
                <svg class="chatbot-toggle-close-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </span>
            <span class="chatbot-toggle-status-dot" title="En línea"></span>
        </button>
        <div class="chatbot-window" id="chatbot-window">
            <div class="chatbot-header">
                <div class="chatbot-header-identity">
                    <img src="{$logo}" alt="" class="chatbot-header-logo" onerror="this.style.display='none'">
                    <span class="chatbot-header-title">{$botname}</span>
                </div>
                <button type="button" class="chatbot-role-selector-btn" id="chatbot-role-btn" title="{$rolebtntitle}">
                    <span class="chatbot-role-btn-badge" id="chatbot-role-btn-badge">{$initialprofile['letter']}</span>
                    <span class="chatbot-role-btn-name" id="chatbot-role-btn-name">{$initialprofile['short']}</span>
                    <svg class="chatbot-role-btn-arrow" xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </button>
                <div class="chatbot-header-actions">
                    {$adminbuttonhtml}
                    <button class="chatbot-header-btn" id="chatbot-clear-history-btn" title="{$cleartitle}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                    </button>
                    <button class="chatbot-header-btn" id="chatbot-fullscreen-btn" title="Pantalla completa">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"></path></svg>
                    </button>
                    <button class="chatbot-header-btn" id="chatbot-close-btn" title="Cerrar">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>
            </div>
            <!-- Drawer desplegable con la seleccion explicita de perfiles / roles -->
            <div class="chatbot-role-drawer" id="chatbot-role-drawer">
                <div class="chatbot-role-drawer-head">
                    <div>
                        <div class="chatbot-role-drawer-title">{$drawertitle}</div>
                        <div class="chatbot-role-drawer-desc">{$drawersubtitle}</div>
                    </div>
                    <button type="button" class="chatbot-role-drawer-close" id="chatbot-role-drawer-close" title="Cerrar selector">&times;</button>
                </div>
                <div class="chatbot-role-drawer-list" id="chatbot-role-drawer-list">
                    {$profilecards}
                    {$admindrawerhtml}
                </div>
            </div>
            <div class="chatbot-mode-banner" id="chatbot-mode-banner"></div>
            {$journeyhtml}
            <div class="chatbot-messages" id="chatbot-messages">{$initialmessageshtml}</div>
            <div class="chatbot-input">
                {$chipshtml}
                <div class="chatbot-input-wrapper">
                    <div class="chatbot-attachment-preview" id="chatbot-attachment-preview">
                        <span id="chatbot-attachment-name">documento.pdf</span>
                        <span class="chatbot-attachment-remove" id="chatbot-attachment-remove">&times;</span>
                    </div>
                    <div style="display: flex; align-items: center; width: 100%;">
                        <label for="chatbot-file-upload" class="chatbot-attach-btn" title="Adjuntar PDF">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
                        </label>
                        <input type="file" id="chatbot-file-upload" accept=".pdf"{$inputdisabled}>
                        <input type="text" id="chatbot-input-field" name="chatbot_query_message" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-form-type="other" placeholder="{$placeholder}"{$inputdisabled}>
                        <button id="chatbot-send-btn"{$inputdisabled}>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        {$adminmodalhtml}
        <script>
            window.chatbotConfig = {
                ajaxurl: "{$CFG->wwwroot}/local/chatbot/ajax.php",
                sesskey: "{$sesskey}",
                wwwroot: "{$CFG->wwwroot}",
                courseid: {$courseid},
                cmid: {$cmid},
                initialMode: "{$suggestedmode}",
                diagStage: "{$diagstage}",
                profiles: {$profilesjson},
                hasConsent: {$hasconsentjson},
                startUrl: "{$starturl}",
                isAdmin: {$isadminjson},
                adminAjaxUrl: "{$CFG->wwwroot}/local/chatbot/ajax_admin_config.php",
                journey: {$journeyjson},
                modeAuto: {$modeautojson},
                ux: {$uxjson},
                feedbackUrl: "{$CFG->wwwroot}/local/chatbot/ajax_feedback.php",
                strings: {
HTML;

    $strings = [
        'welcome_tutor' => get_string('widget_welcome_tutor', 'local_chatbot'),
        'welcome_coach' => get_string('widget_welcome_coach', 'local_chatbot'),
        'welcome_evaluador' => get_string('widget_welcome_evaluador', 'local_chatbot'),
        'banner_tutor' => get_string('widget_banner_tutor', 'local_chatbot'),
        'banner_coach' => get_string('widget_banner_coach', 'local_chatbot'),
        'banner_evaluador' => get_string('widget_banner_evaluador', 'local_chatbot'),
        'sources' => get_string('widget_sources', 'local_chatbot'),
        'accept' => get_string('action_accept', 'local_chatbot'),
        'modify' => get_string('action_modify_short', 'local_chatbot'),
        'postpone' => get_string('action_postpone', 'local_chatbot'),
        'reject' => get_string('action_reject', 'local_chatbot'),
        'decisionsaved' => get_string('widget_decision_saved', 'local_chatbot'),
        'decisionprompt' => get_string('widget_decision_prompt', 'local_chatbot'),
        'confirmclear' => get_string('widget_confirm_clear', 'local_chatbot'),
        'clearfailed' => get_string('widget_clear_failed', 'local_chatbot'),
        'connerror' => get_string('error_connection', 'local_chatbot'),
        'roleswitched' => get_string('widget_role_switched', 'local_chatbot'),
        'journey_title' => get_string('journey_title', 'local_chatbot'),
        'journey_chip_whereami' => get_string('journey_chip_whereami', 'local_chatbot'),
        'journey_chip_whatmode' => get_string('journey_chip_whatmode', 'local_chatbot'),
        'journey_chip_nextstep' => get_string('journey_chip_nextstep', 'local_chatbot'),
        'mode_auto_coach' => get_string('mode_auto_coach', 'local_chatbot'),
        'mode_auto_evaluador' => get_string('mode_auto_evaluador', 'local_chatbot'),
        'fb_up' => get_string('fb_up', 'local_chatbot'),
        'fb_down' => get_string('fb_down', 'local_chatbot'),
        'fb_thanks' => get_string('fb_thanks', 'local_chatbot'),
        'fb_why' => get_string('fb_why', 'local_chatbot'),
        'fb_no_responde' => get_string('fb_no_responde', 'local_chatbot'),
        'fb_incorrecta' => get_string('fb_incorrecta', 'local_chatbot'),
        'fb_larga' => get_string('fb_larga', 'local_chatbot'),
        'fb_no_entendi' => get_string('fb_no_entendi', 'local_chatbot'),
        'retry_btn' => get_string('retry_btn', 'local_chatbot'),
        'thread_label' => get_string('thread_label', 'local_chatbot'),
        'thread_back' => get_string('thread_back', 'local_chatbot'),
        'thread_welcome' => get_string('thread_welcome', 'local_chatbot'),
    ];

    if ($isadmin) {
        $strings['admin_save_success'] = get_string('admin_save_success', 'local_chatbot');
        $strings['admin_save_error'] = get_string('admin_save_error', 'local_chatbot');
        $strings['admin_testing'] = get_string('admin_testing', 'local_chatbot');
        $strings['admin_saving'] = get_string('admin_saving', 'local_chatbot');
    }


    $stringlines = [];
    foreach ($strings as $key => $value) {
        $stringlines[] = '                    ' . $key . ': ' . json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    $html .= implode(",\n", $stringlines) . "\n";

    $html .= <<<HTML
                }
            };
        </script>
        <script src="{$CFG->wwwroot}/local/chatbot/js/chatbot-widget.js?v={$v}"></script>
    </div>
HTML;

    // Ruta dentro del curso de Moodle (portada y actividades del Modulo 1): las pantallas del
    // plugin ya tienen su propia barra. Se pinta oculta junto al widget y se lleva arriba del
    // contenido principal, que es donde la persona mira primero.
    $coursebar = '';
    if ($courseid > 0 && strpos($pageurl, '/local/chatbot/') === false) {
        $coursebar = \local_chatbot\course_bridge::course_bar($USER->id, $courseid, $resourcename, $screenstep);
    }
    // Recorrido guiado de la primera vez: tres pantallas sobre la ruta, los modos y la ayuda. Se
    // muestra una sola vez por persona y solo en las pantallas del recorrido: en la portada del
    // curso Moodle lanza su propio recorrido y los dos quedaban uno encima del otro.
    if (\local_chatbot\ux::on('tour') && !get_user_preferences('local_chatbot_tour_done', 0) && $screenstep > 0) {
        $html .= local_chatbot_tour_html();
    }

    if ($coursebar !== '') {
        $html = '<div id="chatbot-coursebar-holder" style="display:none">' . $coursebar . '</div>'
            . '<script>(function() {'
            . 'var mover = function() {'
            . 'var barra = document.getElementById("chatbot-coursebar"); var holder = document.getElementById("chatbot-coursebar-holder");'
            . 'var main = document.getElementById("region-main") || document.querySelector("[role=main]");'
            . 'if (!barra || !holder) { return; }'
            . 'if (main) { main.insertBefore(barra, main.firstChild); } else { holder.style.display = ""; }'
            . '};'
            . 'if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", mover); } else { mover(); }'
            . '})();</script>'
            . $html;
    }

    return $html;
}

/**
 * Recorrido guiado de la primera vez (interruptor «tour»): tres pantallas y un boton para
 * terminar, que guarda que ya se vio.
 *
 * @return string HTML del modal y su script.
 */
function local_chatbot_tour_html(): string {
    global $CFG;
    $pasos = '';
    for ($i = 1; $i <= 3; $i++) {
        $pasos .= '<div class="chatbot-tour-step" data-step="' . $i . '"' . ($i > 1 ? ' hidden' : '') . '>'
            . '<h5>' . s(get_string('tour_' . $i . '_title' . (($i === 1 && \local_chatbot\ux::on('route6')) ? '_route6' : ''),
                'local_chatbot')) . '</h5>'
            . '<div class="chatbot-tour-body">' . get_string('tour_' . $i . '_body'
                . (($i === 1 && \local_chatbot\ux::on('route6')) ? '_route6' : ''), 'local_chatbot') . '</div></div>';
    }
    $siguiente = s(get_string('tour_next', 'local_chatbot'));
    $empezar = s(get_string('tour_start', 'local_chatbot'));
    $saltar = s(get_string('tour_skip', 'local_chatbot'));
    $titulo = s(get_string('tour_label', 'local_chatbot'));
    $url = $CFG->wwwroot . '/local/chatbot/ajax_tour.php';
    $sesskey = sesskey();
    return <<<HTML
    <div class="chatbot-tour-overlay" id="chatbot-tour" role="dialog" aria-modal="true" aria-label="{$titulo}">
        <div class="chatbot-tour-card">
            {$pasos}
            <div class="chatbot-tour-foot">
                <span class="chatbot-tour-dots"><span class="is-on"></span><span></span><span></span></span>
                <span>
                    <button type="button" class="btn btn-link btn-sm" id="chatbot-tour-skip">{$saltar}</button>
                    <button type="button" class="btn btn-primary btn-sm" id="chatbot-tour-next">{$siguiente}</button>
                </span>
            </div>
        </div>
    </div>
    <script>
    (function() {
        var tour = document.getElementById('chatbot-tour');
        if (!tour) { return; }
        var paso = 1;
        var siguiente = document.getElementById('chatbot-tour-next');
        var cerrar = function() {
            tour.remove();
            var fd = new FormData();
            fd.append('sesskey', '{$sesskey}');
            fetch('{$url}', {method: 'POST', body: fd}).catch(function() {});
        };
        var mostrar = function(n) {
            tour.querySelectorAll('.chatbot-tour-step').forEach(function(el) {
                el.hidden = parseInt(el.getAttribute('data-step'), 10) !== n;
            });
            tour.querySelectorAll('.chatbot-tour-dots span').forEach(function(el, i) {
                el.classList.toggle('is-on', i === n - 1);
            });
            siguiente.textContent = n === 3 ? '{$empezar}' : '{$siguiente}';
            siguiente.focus();
        };
        siguiente.addEventListener('click', function() {
            if (paso >= 3) { cerrar(); return; }
            paso++;
            mostrar(paso);
        });
        document.getElementById('chatbot-tour-skip').addEventListener('click', cerrar);
        tour.addEventListener('keydown', function(e) { if (e.key === 'Escape') { cerrar(); } });
        siguiente.focus();
    })();
    </script>
HTML;
}
