<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Un solo asistente con tres modos funcionales explicitos.
 *
 * El documento de arquitectura es especifico en esto: "se recomienda
 * implementar un solo asistente con tres modos funcionales explicitos, en
 * lugar de tres bots desconectados. Esto preserva continuidad de contexto,
 * evita duplicacion de datos y permite que las recomendaciones se relacionen
 * con el diagnostico, el avance en la ruta y el proyecto integrador".
 *
 * Esta clase arma el prompt de cada modo y, en paralelo, la lista de fuentes
 * de contexto que se usaron. Esa lista no es decorativa: la regla de
 * gobernanza exige que "las interacciones deben explicitar si el asistente
 * esta utilizando resultados del diagnostico, avance de unidad, PDP o
 * proyecto integrador", asi que se muestra a la persona y se guarda en el
 * registro de interaccion.
 *
 * Ningun dato identificatorio entra al prompt: la persona viaja como
 * seudonimo (ver pseudonymizer).
 */
class assistant {

    /** @var string Modo A: acompana la comprension durante las cuatro unidades. */
    const MODE_TUTOR = 'tutor';

    /** @var string Modo B: interpreta brechas y trabaja el PDP. */
    const MODE_COACH = 'coach';

    /** @var string Modo C: evalua el proyecto integrador contra la rubrica. */
    const MODE_EVALUADOR = 'evaluador';

    /**
     * @var string Fase de diagnostico: mientras la persona responde el
     * instrumento y todavia no lo envia.
     *
     * No es un cuarto modo. La especificacion es explicita en que "no es un
     * modo con interfaz propia, pero tiene reglas de conducta propias y muy
     * estrictas". La persona sigue viendo el modo que eligio; lo que cambia es
     * que el asistente opera bajo las restricciones RU-DIAG-001 a RU-DIAG-004,
     * porque cualquier pista sobre las respuestas contamina la autoevaluacion
     * que el resto del modulo usa como linea base.
     */
    const PHASE_DIAGNOSTICO = 'diagnostico';

    /**
     * @return string[] Modos disponibles con su nombre de interfaz.
     */
    public static function get_modes(): array {
        return [
            self::MODE_TUTOR => get_string('mode_tutor', 'local_chatbot'),
            self::MODE_COACH => get_string('mode_coach', 'local_chatbot'),
            self::MODE_EVALUADOR => get_string('mode_evaluador', 'local_chatbot'),
        ];
    }

    /**
     * Contextos que tienen prompt propio versionado: los tres modos mas la
     * fase de diagnostico.
     *
     * Se distingue de get_modes() porque la fase de diagnostico no se ofrece
     * en el selector del widget, pero si necesita prompt editable y versionado
     * como cualquier otro (RU-PRIV-006).
     *
     * @return string[] Mapa clave => nombre legible.
     */
    public static function get_prompt_contexts(): array {
        return self::get_modes() + [
            self::PHASE_DIAGNOSTICO => get_string('phase_diagnostico', 'local_chatbot'),
        ];
    }

    /**
     * @param string $key
     * @return bool Si la clave nombra un modo o la fase de diagnostico.
     */
    public static function is_prompt_context(string $key): bool {
        return self::is_valid_mode($key) || $key === self::PHASE_DIAGNOSTICO;
    }

    /**
     * Catalogo completo de perfiles/roles con su metadata pedagogica segun la
     * especificacion del Modulo 1 (letra, nombre, momento de uso y proposito).
     *
     * @return array[]
     */
    public static function get_profiles_catalog(): array {
        return [
            self::MODE_TUTOR => [
                'key' => self::MODE_TUTOR,
                'letter' => 'A',
                'name' => get_string('mode_tutor', 'local_chatbot'),
                'short' => get_string('mode_tutor_short', 'local_chatbot'),
                'timing' => get_string('mode_tutor_timing', 'local_chatbot'),
                'purpose' => get_string('mode_tutor_purpose', 'local_chatbot'),
                'banner' => get_string('widget_banner_tutor', 'local_chatbot'),
                'welcome' => get_string('widget_welcome_tutor', 'local_chatbot'),
                'badge' => 'Unidades 1 - 4',
                'icon' => 'book-open',
            ],
            self::MODE_COACH => [
                'key' => self::MODE_COACH,
                'letter' => 'B',
                'name' => get_string('mode_coach', 'local_chatbot'),
                'short' => get_string('mode_coach_short', 'local_chatbot'),
                'timing' => get_string('mode_coach_timing', 'local_chatbot'),
                'purpose' => get_string('mode_coach_purpose', 'local_chatbot'),
                'banner' => get_string('widget_banner_coach', 'local_chatbot'),
                'welcome' => get_string('widget_welcome_coach', 'local_chatbot'),
                'badge' => 'Diagnostico & PDP',
                'icon' => 'target',
            ],
            self::MODE_EVALUADOR => [
                'key' => self::MODE_EVALUADOR,
                'letter' => 'C',
                'name' => get_string('mode_evaluador', 'local_chatbot'),
                'short' => get_string('mode_evaluador_short', 'local_chatbot'),
                'timing' => get_string('mode_evaluador_timing', 'local_chatbot'),
                'purpose' => get_string('mode_evaluador_purpose', 'local_chatbot'),
                'banner' => get_string('widget_banner_evaluador', 'local_chatbot'),
                'welcome' => get_string('widget_welcome_evaluador', 'local_chatbot'),
                'badge' => 'Proyecto Integrador',
                'icon' => 'clipboard-check',
            ],
        ];
    }

    /**
     * @param string $mode
     * @return bool
     */
    public static function is_valid_mode(string $mode): bool {
        return in_array($mode, [self::MODE_TUTOR, self::MODE_COACH, self::MODE_EVALUADOR], true);
    }

    /**
     * Matriz de acceso a datos por modo (ERTF §7.3).
     *
     * La ERTF es explicita en que esto "es una regla de seguridad, no una
     * sugerencia" y que "se implementa en el ensamblador de contexto". Por eso
     * vive aqui como dato declarativo y no repartida en condicionales: se puede
     * leer la regla completa de un vistazo, y una prueba puede verificarla
     * objeto por objeto sin ejecutar una conversacion real.
     *
     * Valores posibles: false (sin acceso), true (acceso completo), o una
     * cadena que nombra el alcance parcial permitido.
     *
     * @var array<string, array<string, bool|string>>
     */
    const ACCESS_MATRIX = [
        // Fase de diagnostico: la fila mas restrictiva de todas, y a proposito.
        // Mientras el instrumento no se envia, el asistente no puede ver nada
        // de la persona. Ni siquiera los recursos internos del modulo, porque
        // un ejemplo tomado de la lectura de una unidad puede orientar la
        // respuesta de un item (RU-DIAG-001 pide ejemplos generales).
        self::PHASE_DIAGNOSTICO => [
            'profile' => false,
            'gaps' => false,
            'diag_responses' => false,
            'unit_content' => false,
            'quiz_pending_items' => false,
            'quiz_results' => false,
            'pdp' => false,
            'project' => false,
            'rubric' => false,
            'resource_catalog' => false,
            'adaptive_supports' => false,
        ],
        self::MODE_TUTOR => [
            'profile' => 'teaching',        // Solo rol, sector y modalidad.
            'gaps' => false,
            'diag_responses' => false,
            'unit_content' => true,         // Recursos internos del modulo.
            'quiz_pending_items' => false,  // Nunca, en ningun modo.
            'quiz_results' => 'aggregated',
            'pdp' => false,
            'project' => false,
            'rubric' => false,
            'resource_catalog' => true,
            'adaptive_supports' => 'current', // Solo la consigna en curso, no el PDP.
        ],
        // Perfil, matriz y catalogo le llegan al Coach dentro del objeto de
        // datos de Doc 3 §10.1 ('contract'), no como bloques sueltos. Es mas
        // estrecho que la ERTF §7.3, que le daba el perfil completo: RU-COA-DATA-001
        // pide "unicamente el objeto preprocesado", y la experiencia declarada
        // no esta entre los datos que RU-COA-001 autoriza.
        self::MODE_COACH => [
            'profile' => 'contract',
            'gaps' => 'contract',
            'diag_responses' => 'aggregated',
            'unit_content' => false,
            'quiz_pending_items' => false,
            'quiz_results' => 'aggregated',
            'pdp' => true,
            'project' => 'status',          // Solo metadatos de estado.
            'rubric' => false,
            'resource_catalog' => 'contract',
            'adaptive_supports' => true,
        ],
        self::MODE_EVALUADOR => [
            'profile' => 'context',         // Solo el contexto formativo declarado.
            'gaps' => 'readonly',
            'diag_responses' => false,
            'unit_content' => false,
            'quiz_pending_items' => false,
            'quiz_results' => false,
            'pdp' => true,
            'project' => true,
            'rubric' => true,
            'resource_catalog' => false,
            'adaptive_supports' => false,
        ],
    ];

    /**
     * Consulta puntual a la matriz de acceso.
     *
     * @param string $mode
     * @param string $object Clave de objeto de contexto de ACCESS_MATRIX.
     * @return bool|string false si el modo no puede acceder a ese objeto.
     */
    public static function can_access(string $mode, string $object) {
        if (!isset(self::ACCESS_MATRIX[$mode][$object])) {
            // Lo que la matriz no autoriza explicitamente, no se entrega.
            return false;
        }
        return self::ACCESS_MATRIX[$mode][$object];
    }

    /**
     * Decide bajo que reglas opera realmente el asistente en este turno.
     *
     * El cliente puede pedir un modo, pero no puede escaparse de una
     * restriccion: esta funcion solo endurece, nunca ablanda. Hay dos formas
     * de caer en la fase de diagnostico:
     *
     * 1. La persona esta sobre el instrumento y esa aplicacion sigue sin
     *    enviarse (RU-DIAG-002). La pagina declara que etapa esta respondiendo,
     *    pero es el servidor el que comprueba si esa etapa ya fue enviada.
     * 2. Se pide el modo Coach sin diagnostico de entrada enviado. RU-COA-001
     *    condiciona el Coach al envio del diagnostico, asi que antes de eso no
     *    hay Coach que activar: pedirle que interprete un perfil que no existe
     *    es justamente lo que la fase de diagnostico prohibe. Esta segunda via
     *    no depende del cliente en absoluto.
     *
     * La restriccion se amarra a la etapa concreta y no a "queda algo
     * pendiente" porque son cosas distintas: quien ya envio su diagnostico de
     * entrada y vuelve a esa pagina a mirar su matriz de brechas debe poder
     * hablar con el Coach, aunque la aplicacion de salida siga pendiente para
     * el cierre del modulo.
     *
     * @param string $mode Modo solicitado.
     * @param int $userid
     * @param int $courseid
     * @param string $diagstage Etapa del instrumento que la persona esta respondiendo,
     *        o cadena vacia si el turno no ocurre sobre el instrumento.
     * @return string Modo efectivo, o PHASE_DIAGNOSTICO.
     */
    public static function resolve_phase(string $mode, int $userid, int $courseid,
            string $diagstage = ''): string {
        if (!self::is_valid_mode($mode)) {
            $mode = self::MODE_TUTOR;
        }

        $stages = [diagnostic::STAGE_ENTRADA, diagnostic::STAGE_SALIDA];
        if (in_array($diagstage, $stages, true)
                && !diagnostic::has_responses($userid, $courseid, $diagstage)) {
            return self::PHASE_DIAGNOSTICO;
        }

        if ($mode === self::MODE_COACH
                && !diagnostic::has_responses($userid, $courseid, diagnostic::STAGE_ENTRADA)) {
            return self::PHASE_DIAGNOSTICO;
        }

        return $mode;
    }

    /**
     * Arma el prompt de sistema del modo pedido y la lista de fuentes que lo
     * alimentaron.
     *
     * @param string $mode Modo, o PHASE_DIAGNOSTICO ya resuelto por resolve_phase().
     * @param int $userid
     * @param int $courseid
     * @param int $screen Paso del recorrido (1 a 5) de la pantalla que tiene abierta la persona; 0 si no se sabe.
     * @return array Con claves prompt (string), resources (string[]) y promptversion (int).
     */
    public static function build_context(string $mode, int $userid, int $courseid, int $screen = 0): array {
        if (!self::is_prompt_context($mode)) {
            $mode = self::MODE_TUTOR;
        }

        $resources = [];
        $prompt = prompt_store::get_text($mode);
        if ($prompt === '') {
            $prompt = self::fallback_prompt($mode);
        }

        // Contenido de la unidad activa: solo el Tutor. El Coach y el Evaluador
        // trabajan sobre el desempeno de la persona, no sobre el material.
        $kbblock = '';
        if (self::can_access($mode, 'unit_content') !== false) {
            $kb = kb_store::get_combined_context();
            if ($kb !== '') {
                $kbblock = "\n--- RECURSOS INTERNOS DEL MODULO ---\n" . $kb . "\n--- FIN DE RECURSOS INTERNOS ---\n";
                $resources[] = get_string('resource_kb', 'local_chatbot');
            }
        }
        // Con la base al inicio, el comienzo del contexto es identico para todas las personas
        // (prompt del modo + material), y el proveedor puede reutilizarlo entre mensajes en vez
        // de procesar todo el material cada vez. Lo personal va despues.
        $kbfirst = $kbblock !== '' && ux::on('kbcache');
        if ($kbfirst) {
            $prompt .= "\n" . $kbblock;
        }

        // Marco comun a los tres modos.
        $prompt .= "\n\n--- CONTEXTO DE LA PERSONA ---\n";
        $prompt .= "Identificador: " . pseudonymizer::for_user($userid) . "\n";
        $prompt .= "Curso: " . pseudonymizer::for_course($courseid) . "\n";
        $prompt .= "No dispones del nombre real ni de datos de contacto de la persona, y no debes pedirselos.\n";
        $prompt .= "\n" . guardrails::injection_clause() . "\n";

        // Avance en la ruta del Modulo 1 (para responder preguntas de progreso del estudiante).
        if ($mode !== self::PHASE_DIAGNOSTICO) {
            $journeyblock = self::journey_progress_block($userid, $courseid);
            if ($journeyblock !== '') {
                $prompt .= "\n" . $journeyblock . "\n";
            }
        }

        // Fase de diagnostico: las prohibiciones se repiten aqui, ademas de
        // estar en el prompt versionado, porque son la unica barrera que existe
        // entre el asistente y la autoevaluacion. El prompt versionado se puede
        // editar desde la interfaz; esto no.
        if ($mode === self::PHASE_DIAGNOSTICO) {
            $prompt .= "\n" . self::diagnostic_phase_clause() . "\n";
            $resources[] = get_string('resource_diagphase', 'local_chatbot');
        }

        // A partir de aqui, cada bloque de contexto se entrega solo si la matriz
        // del modo lo autoriza (ERTF §7.3). Ningun bloque se agrega "porque
        // siempre estuvo": si la matriz dice false, no se arma.

        // Perfil declarado en el Componente 0, con el alcance que corresponda.
        $profilescope = self::can_access($mode, 'profile');
        if ($profilescope !== false && $profilescope !== 'contract') {
            $profileblock = self::profile_block($userid, $courseid, $profilescope);
            if ($profileblock !== '') {
                $prompt .= "\n" . $profileblock . "\n";
                $resources[] = get_string('resource_profile', 'local_chatbot');
            }
        }

        if ($kbblock !== '' && !$kbfirst) {
            $prompt .= $kbblock;
        }

        // Catalogo de apoyos activos: nombres, no contenido. Con alcance
        // 'contract' ya viaja dentro del objeto de datos del Coach.
        if (self::can_access($mode, 'resource_catalog') === true) {
            $names = array_column(coach_context::catalog($userid, $courseid), 'nombre');
            if (!empty($names)) {
                $prompt .= "\nCATALOGO DE APOYOS ACTIVOS (solo puedes recomendar por su nombre lo que"
                    . " figura aqui; si nada sirve, dilo en vez de inventar un recurso):\n- "
                    . implode("\n- ", $names) . "\n";
                $resources[] = get_string('resource_catalog', 'local_chatbot');
            }
        }

        // Apoyo adaptativo en curso (seccion 5 del spec). El Tutor ve solo la
        // consigna vigente; el Coach, ademas, la meta del PDP a la que responde.
        $adaptscope = self::can_access($mode, 'adaptive_supports');

        // Coach: cuando la persona tiene metas vigentes, todo lo que el Coach sabe de
        // ellas (la meta, su estado, el apoyo en curso, la evidencia, el avance y los
        // patrones del quiz) llega en un solo objeto del Anexo F §4 (RU-COA-DATA-002),
        // en vez de tres bloques de texto que decian cada uno una parte.
        $goalcontext = $mode === self::MODE_COACH ? coach_goal::as_prompt_context($userid, $courseid) : '';

        if ($adaptscope !== false) {
            $adaptblock = $goalcontext !== ''
                ? '' : adaptive::as_prompt_context($userid, $courseid, $adaptscope);
            if ($goalcontext !== '' && !empty(adaptive::list_open($userid, $courseid))) {
                $resources[] = get_string('resource_adaptive', 'local_chatbot');
            }
            if ($adaptblock !== '') {
                $prompt .= "\n" . $adaptblock . "\n";
                $resources[] = get_string('resource_adaptive', 'local_chatbot');
            }
            // Donde hay apoyos adaptativos hay evidencias que la persona tiene
            // que producir ella: RU-TUT-008 vale para el Tutor por el requisito
            // y para el Coach por la misma razon.
            $prompt .= "\n" . self::authorship_clause() . "\n";
            $prompt .= "\n" . self::concepts_clause() . "\n";
        }

        // Matriz de brechas. Con alcance 'contract' se entrega el objeto de datos
        // de Doc 3, que ya trae perfil autorizado, matriz y catalogo.
        if (self::can_access($mode, 'gaps') !== false) {
            $gaps = self::can_access($mode, 'gaps') === 'contract'
                ? coach_context::as_prompt_context($userid, $courseid)
                : diagnostic::as_prompt_context($userid, $courseid);
            if ($gaps !== '') {
                $prompt .= "\n" . $gaps . "\n";
                if (self::can_access($mode, 'gaps') === 'readonly') {
                    $prompt .= "Estas brechas son solo de lectura: no propongas cambiarlas aqui.\n";
                }
                $resources[] = get_string('resource_diagnostic', 'local_chatbot');
            } else if ($mode === self::MODE_COACH) {
                $prompt .= "\nLa persona todavia no ha aplicado el diagnostico. No inventes un perfil"
                    . " de brechas: invitala a responderlo antes de proponer metas.\n";
            }
        }

        // Plan Personal de Desarrollo.
        if (self::can_access($mode, 'pdp') !== false) {
            if ($goalcontext !== '') {
                $prompt .= "\n" . $goalcontext . "\n";
                $resources[] = get_string('resource_pdp', 'local_chatbot');
            }
            $plan = $goalcontext !== '' ? '' : pdp::as_prompt_context($userid, $courseid);
            if ($plan !== '') {
                $prompt .= "\n" . $plan . "\n";
                $resources[] = get_string('resource_pdp', 'local_chatbot');
            }
        }

        // Rubrica oficial del proyecto integrador.
        if (self::can_access($mode, 'rubric') !== false) {
            $rubric = project::rubric_as_prompt_context($courseid);
            if ($rubric !== '') {
                $prompt .= "\n" . $rubric . "\n";
                $resources[] = get_string('resource_rubric', 'local_chatbot');
            }
        }

        // Proyecto integrador: el Coach solo ve metadatos de estado; el
        // contenido de los campos lo lee unicamente el Evaluador, y lo hace en
        // project::evaluate(), no en una conversacion abierta.
        $projectscope = self::can_access($mode, 'project');
        if ($projectscope === 'status') {
            $status = self::project_status_block($userid, $courseid);
            if ($status !== '') {
                $prompt .= "\n" . $status . "\n";
                $resources[] = get_string('resource_projectstatus', 'local_chatbot');
            }
        }

        // Resultados de cuestionarios ya calificados, siempre agregados.
        // Patrones del quiz para el Coach (Anexo G §5.1): evidencia complementaria, solo despues
        // de un intento enviado. El Tutor no los recibe.
        if ($mode === self::MODE_COACH) {
            // Con metas vigentes los patrones ya van en progreso.quiz_result_summary
            // del objeto de la meta; sin metas se conserva el bloque de siempre.
            $patterns = quiz_patterns::coach_block($userid, $courseid);
            if ($patterns !== '') {
                if ($goalcontext === '') {
                    $prompt .= "\n" . $patterns . "\n";
                }
                $resources[] = get_string('resource_quizpatterns', 'local_chatbot');
            }
        }

        if (self::can_access($mode, 'quiz_results') === 'aggregated') {
            $results = self::quiz_results_block($userid, $courseid);
            if ($results !== '') {
                $prompt .= "\n" . $results . "\n";
                $resources[] = get_string('resource_quizresults', 'local_chatbot');
            }
        }

        // Proteccion de evaluacion activa. Los enunciados de items pendientes
        // nunca entran al contexto en ningun modo: esta clausula solo nombra
        // que cuestionarios siguen sin responder.
        if ($mode === self::MODE_TUTOR) {
            $guard = self::quiz_guard_clause($userid, $courseid);
            if ($guard !== '') {
                $prompt .= "\n" . $guard . "\n";
                $resources[] = get_string('resource_quizstate', 'local_chatbot');
            }
        }

        // Que resuelve el asistente, que deriva y que redirige. Va siempre, haya
        // o no contacto de soporte configurado: la parte que mas importa es la
        // que prohibe derivar dudas conceptuales, y esa no depende del contacto.
        $prompt .= "\n\n" . self::scope_and_derivation_clause() . "\n";

        // Lo que realmente se registra, para que el asistente no lo describa de memoria
        // (prueba del 21-09-2026, caso P-03: afirmaba que solo se guardaban resumenes).
        $prompt .= "\n" . self::data_registration_clause() . "\n";

        if ($mode === self::MODE_COACH) {
            $prompt .= "\n" . self::coach_goal_rules_clause() . "\n";
        } else if ($mode === self::MODE_TUTOR) {
            // Con el chat solo Tutor (ajustes del cliente del 08-10-2026) no hay Coach visible al que
            // derivar, y el chat no navega: la derivacion se reemplaza por la regla de conversacion.
            $prompt .= "\n" . (ux::on('tutoronly') ? self::tutor_only_clause() : self::tutor_handoff_clause()) . "\n";
        }

        // Pantalla abierta: sin esto el asistente deduce "donde esta" de lo que falta por hacer
        // y ubica en el Proyecto a quien sigue trabajando en su plan.
        if ($screen >= 1 && $courseid >= 0) {
            // La misma ruta que ve la persona (5 pasos, o las 6 etapas de los ajustes del cliente).
            $modelo = course_bridge::route_model($userid, $courseid, $screen);
            if ($screen <= $modelo['total']) {
                $linea = journey::context_line($modelo['view'], $screen, $modelo['keys']);
                if ($linea !== '') {
                    $prompt .= "\n" . $linea . "\n";
                }
            }
        }

        return [
            'prompt' => $prompt,
            'resources' => $resources,
            'promptversion' => prompt_store::get_version($mode),
        ];
    }

    /**
     * Perfil declarado, recortado al alcance que la matriz autoriza al modo.
     *
     * El Tutor recibe solo lo que necesita para contextualizar ejemplos (rol,
     * sector, modalidad). El Evaluador recibe solo el contexto formativo. La
     * necesidad declarada y la experiencia son del Coach: son material de
     * conversacion sobre el desarrollo de la persona, no de las otras dos
     * tareas.
     *
     * @param int $userid
     * @param int $courseid
     * @param bool|string $scope true (completo), 'teaching' o 'context'.
     * @return string
     */
    private static function profile_block(int $userid, int $courseid, $scope): string {
        $profile = profile::get($userid, $courseid);
        if (!$profile) {
            return '';
        }

        $p = profile::describe($profile);
        $nd = 'no declarado';

        if ($scope === 'teaching') {
            return 'PERFIL (parcial): rol ' . ($p['rol'] ?? $nd) . ', tipo de programa ' . ($p['programa'] ?? $nd)
                . ', sector ' . ($p['sector'] ?? $nd) . ', modalidad ' . ($p['modalidad'] ?? $nd)
                . '. Usalo solo para contextualizar tus ejemplos.';
        }

        if ($scope === 'context') {
            return $p['programa'] === null ? '' : 'CONTEXTO FORMATIVO DECLARADO: ' . $p['programa'];
        }

        $block = 'PERFIL DECLARADO: rol ' . ($p['rol'] ?? $nd) . ', tipo de programa ' . ($p['programa'] ?? $nd)
            . ', modalidad ' . ($p['modalidad'] ?? $nd) . ', sector ' . ($p['sector'] ?? $nd)
            . ', experiencia ' . ($p['experiencia'] ?? $nd)
            . ', familiaridad con el enfoque de competencias ' . ($p['familiaridad'] ?? $nd)
            . ', tiempo semanal ' . ($p['tiempo'] ?? $nd) . '.';
        if ($p['aplicacion'] !== null) {
            $block .= "\nCurso o unidad donde aplicara lo aprendido: {$p['aplicacion']}";
        }
        if (!empty($p['fortalecer'])) {
            $block .= "\nAspectos que quiere fortalecer: " . implode('; ', $p['fortalecer']);
        }
        return $block;
    }

    /**
     * Metadatos de estado del proyecto para el Coach: en que va, no que dice.
     *
     * @param int $userid
     * @param int $courseid
     * @return string
     */
    private static function project_status_block(int $userid, int $courseid): string {
        $project = project::get($userid, $courseid);
        if (!$project) {
            return 'ESTADO DEL PROYECTO INTEGRADOR: la persona aun no lo ha iniciado.';
        }

        return "ESTADO DEL PROYECTO INTEGRADOR: {$project->status}, version {$project->version}."
            . " No dispones de su contenido: si la conversacion requiere revisarlo, deriva al modo"
            . " Evaluador formativo.";
    }

    /**
     * Resultados de cuestionarios ya calificados, agregados por actividad.
     *
     * Se entrega el porcentaje logrado, nunca el detalle por item: eso ultimo
     * revelaria indirectamente el contenido de la evaluacion.
     *
     * @param int $userid
     * @param int $courseid
     * @return string
     */
    public static function quiz_results_block(int $userid, int $courseid): string {
        global $DB;

        if ($courseid <= 0) {
            return '';
        }

        $sql = "SELECT q.id, q.name, q.sumgrades AS maxgrade, MAX(qa.sumgrades) AS best
                  FROM {quiz} q
                  JOIN {quiz_attempts} qa ON qa.quiz = q.id
                 WHERE q.course = :courseid AND qa.userid = :userid AND qa.state = 'finished'
              GROUP BY q.id, q.name, q.sumgrades";

        try {
            $rows = $DB->get_records_sql($sql, ['courseid' => $courseid, 'userid' => $userid]);
        } catch (\Exception $e) {
            // El modulo quiz puede no estar disponible en la instalacion.
            return '';
        }

        $lines = [];
        foreach ($rows as $row) {
            if (empty($row->maxgrade) || $row->best === null) {
                continue;
            }
            $pct = round(((float) $row->best / (float) $row->maxgrade) * 100);
            $lines[] = '- ' . format_string($row->name) . ": {$pct}% logrado";
        }

        if (empty($lines)) {
            return '';
        }

        return "RESULTADOS DE CUESTIONARIOS YA RESPONDIDOS (agregado, sin detalle por item):\n"
            . implode("\n", $lines);
    }

    /**
     * Reglas de conducta de la fase de diagnostico (RU-DIAG-001 a RU-DIAG-004).
     *
     * Se escriben como permisos y prohibiciones explicitas y no como una
     * descripcion de rol, porque lo que esta en juego es la validez del
     * instrumento: si el asistente insinua una alternativa, el perfil de
     * brechas deja de medir la practica de la persona y pasa a medir la
     * sugerencia del asistente. Todo lo que viene despues —el PDP, el
     * proyecto, el contraste de salida— descansa sobre esa linea base.
     *
     * @return string
     */
    public static function diagnostic_phase_clause(): string {
        return "FASE DE DIAGNOSTICO EN CURSO (el instrumento aun no ha sido enviado).\n"
            . "Puedes: explicar el significado de terminos metodologicos (criterio de desempeno,"
            . " evidencia, instrumento, retroalimentacion, coevaluacion), explicar como funciona la"
            . " actividad, y distinguir componentes conceptuales entre si. Hazlo siempre con"
            . " ejemplos generales, no con ejemplos tomados de los items del instrumento.\n"
            . "El instrumento tiene dos tipos de item y se tratan distinto:\n"
            . "- DECLARATIVOS (afirmaciones con escala Nunca o casi nunca ... Siempre o casi siempre): preguntan"
            . " por la practica habitual. Solo en estos recuerdale que responda segun su practica habitual y no"
            . " segun lo que considera socialmente deseable.\n"
            . "- SITUACIONALES (una situacion con alternativas y una respuesta correcta): NO le recuerdes"
            . " responder segun su practica habitual (no aplica: aqui hay una respuesta correcta). Solo define"
            . " los terminos del enunciado (que es un aprendizaje esperado, que es un protocolo, que es una"
            . " evidencia). No expliques que tipo de actividad, estrategia, decision o enfoque es mas"
            . " adecuado, coherente o mejor para la situacion planteada, ni compares enfoques entre si"
            . " (practicar frente a escuchar, simular frente a leer, ejecutar frente a exponer): en un item"
            . " situacional eso ES la respuesta. Si el enunciado pregunta que es «mas coherente», «mas"
            . " adecuado» o «mejor», no des criterios para elegir; define solo los terminos.\n"
            . "NO debes, bajo ninguna formulacion: sugerir que alternativa elegir en un item,"
            . " interpretar o adelantar el resultado de sus respuestas, sugerir puntajes, inferir"
            . " resultados parciales, ni revelar la respuesta correcta de un item situacional.\n"
            . "Si te insisten, explica que responder por ella invalidaria su propio diagnostico, y"
            . " ofrece en cambio aclarar el termino o el procedimiento.\n"
            . "Las dudas sobre terminos o sobre como funciona la actividad las resuelves tu: no las"
            . " derives a una persona. Solo una dificultad tecnica (acceso, conectividad, la"
            . " plataforma que no responde) va a soporte humano.";
    }

    /**
     * Precision conceptual y de citas. Funcion pura.
     *
     * Nace de errores vistos en conversaciones reales (21-10-2026): el Tutor llamo «desempeño esperado» a un
     * aprendizaje esperado justo en una actividad cuyo criterio pedia distinguirlos, y atribuyo a U1-LF-01 §4.3 una
     * lista de «cinco criterios» que ese apartado no tiene. Las definiciones son las de la tabla de U1-LF-01 §3.7.
     *
     * @return string
     */
    public static function concepts_clause(): string {
        return "PRECISION CONCEPTUAL (U1-LF-01, apartado 3): no intercambies los componentes de una experiencia formativa.\n"
            . "- Desempeño esperado: lo que la persona debe poder hacer en una situacion relevante del trabajo"
            . " (ej.: «Atender una solicitud de cliente siguiendo protocolo y resolviendo una contingencia simple»).\n"
            . "- Aprendizaje esperado: la capacidad que se desarrolla y se observa en ESTA experiencia formativa"
            . " (ej.: «Analizar una interaccion de servicio e identificar decisiones que resguardan el protocolo»)."
            . " Verbos como analizar, identificar o justificar dentro de una actividad formativa suelen describir el"
            . " aprendizaje esperado, no el desempeño.\n"
            . "- Criterio de desempeño: lo que hace pertinente la accion en el trabajo. Criterio de evaluacion: lo que"
            . " se observara en la evidencia. Evidencia: lo que produce, decide o demuestra la persona. Instrumento:"
            . " la pauta, lista de cotejo o rubrica con que se recoge o valora la evidencia.\n"
            . "Al dar un ejemplo, rotula cada parte con su nombre correcto.\n"
            . "CITAS: cita un apartado de un recurso solo si ese apartado dice lo que afirmas. No le atribuyas listas,"
            . " cantidades («estos cinco criterios») ni elementos que no contiene. Si no estas seguro del apartado,"
            . " nombra el recurso sin apartado.";
    }

    /**
     * Autoria de las evidencias (RU-TUT-008).
     *
     * Una evidencia que redacta el asistente y la persona presenta como propia
     * no es evidencia de nada: el PDP registraria un avance que no ocurrio, y
     * el motor de apoyos adaptativos revisaria su propio texto. Se inyecta por
     * codigo, ademas de estar en el prompt versionado, porque es de las reglas
     * que un ajuste de prompt bien intencionado podria borrar sin notarlo.
     *
     * @return string
     */
    public static function authorship_clause(): string {
        return "AUTORIA DE LAS EVIDENCIAS: no resuelvas una actividad adaptativa en lugar de la"
            . " persona ni redactes una evidencia completa lista para presentar como propia. Si te"
            . " lo pide, guia el proceso: preguntas, criterios, una estructura o un ejemplo parcial"
            . " de otro caso. La evidencia la produce ella.";
    }

    /**
     * Alcance del asistente y ruta de derivacion (RU-GEN-DERIV-001, RU-PRIV-005,
     * NFR-SEC-002).
     *
     * Hasta M1-v1.1.0 esta clausula mandaba derivar a soporte humano "una
     * incertidumbre metodologica relevante". Era fiel a la matriz v2 y es
     * exactamente lo que Doc 5 §13.3 prohibe: las dudas conceptuales,
     * metodologicas o de aplicacion del itinerario las resuelve el asistente
     * con los recursos fijos y el contexto autorizado. Derivarlas convierte al
     * soporte tecnico en tutoria por otro canal, y deja a la persona esperando
     * una respuesta que el modulo esta disenado para darle en el momento.
     *
     * Quedan tres caminos, y el modelo tiene que distinguirlos:
     * - duda conceptual o metodologica: la resuelve;
     * - incidencia tecnica: deriva a soporte humano;
     * - solicitud ajena al modulo: no la resuelve ni la deriva, redirige.
     *
     * @return string
     */
    public static function scope_and_derivation_clause(): string {
        $clause = "ALCANCE Y DERIVACION:\n"
            . "- Las dudas conceptuales, metodologicas o de aplicacion del itinerario las resuelves"
            . " tu, con los recursos del modulo y el contexto autorizado. Nunca las derives a una"
            . " persona, aunque te parezcan dificiles: si te falta contexto, pidelo.\n"
            . "- Si la solicitud es claramente ajena al modulo (tareas de otros cursos, temas sin"
            . " relacion con la formacion metodologica de personas facilitadoras), no la resuelvas:"
            . " explica en una frase cual es tu alcance y reconduce la conversacion al modulo.\n"
            . "- Solo derivas a soporte humano ante una incidencia tecnica: acceso, autenticacion,"
            . " conectividad, funcionamiento de Moodle o disponibilidad de la interfaz.";

        $support = self::support_reference();
        if ($support !== '') {
            $clause .= " En ese caso indica que acuda a: " . $support . ".";
        } else {
            $clause .= " En ese caso indica que acuda al canal de soporte de su institucion.";
        }

        return $clause;
    }

    /**
     * @return string Contacto de soporte configurado, o cadena vacia si el
     *         sitio todavia no definio uno.
     */
    public static function support_reference(): string {
        $contact = trim((string) get_config('local_chatbot', 'support_contact'));
        $url = trim((string) get_config('local_chatbot', 'support_url'));

        $parts = array_filter([$contact, $url]);
        return implode(' | ', $parts);
    }

    /**
     * Que registra el sistema, descrito desde el codigo y la configuracion (RU-PRIV-009).
     *
     * En la prueba del 21-09-2026 el asistente, sin esta clausula, afirmo que solo se
     * guardaban resumenes y que no se almacenaban nombres: ambas cosas eran falsas.
     * Tambien fija como responder una solicitud de revocacion (RU-PRIV-010).
     *
     * @return string
     */
    public static function data_registration_clause(): string {
        $months = (int) get_config('local_chatbot', 'message_retention_months') ?: 12;
        $rights = trim((string) get_config('local_chatbot', 'rights_contact'));

        $clause = "QUE SE REGISTRA (si la persona pregunta, explicalo asi y no agregues nada que no este aqui):\n"
            . "- El historial de esta conversacion (tus mensajes y los de la persona) se guarda en la plataforma"
            . " y se elimina automaticamente a los {$months} meses.\n"
            . "- De cada interaccion queda ademas un registro estructurado: modo, tipo de consulta, fuentes"
            . " utilizadas y un identificador seudonimizado. Ese registro no incluye el texto de la persona.\n"
            . "- De las evidencias de los apoyos adaptativos se guarda una sintesis y la retroalimentacion, no el"
            . " texto ni el archivo entregado.\n"
            . "- Tambien se guardan el perfil declarado, las respuestas del diagnostico, el Plan Personal de"
            . " Desarrollo con su historial de cambios y el proyecto integrador.\n"
            . "- Al proveedor del modelo de IA no se envian el nombre ni el correo de la persona: viaja un"
            . " seudonimo, y el texto se limpia de correos y RUT antes de enviarse.\n"
            . "- Estos datos se usan solo con fines formativos: no tienen consecuencias laborales, administrativas"
            . " ni de certificacion.\n";
        if ($rights !== '') {
            $clause .= "- Si la persona quiere revocar su consentimiento o ejercer derechos sobre sus datos, indicale"
                . " que lo solicite en: {$rights}. Tu no puedes borrar ni modificar registros.";
        } else {
            $clause .= "- Si la persona quiere revocar su consentimiento o ejercer derechos sobre sus datos, dile que"
                . " el canal institucional aun no esta publicado en la plataforma, que puede solicitarlo a la"
                . " coordinacion del programa, y que tu no puedes borrar ni modificar registros.";
        }
        return $clause;
    }

    /**
     * Reglas del Coach sobre las decisiones de metas (RU-COA-016, RU-COA-017 del SPEC v5).
     *
     * En la prueba del 21-09-2026, ante "cierra mi meta ya" sin evidencia revisada, el
     * Coach ofrecio cerrarla o rechazarla definitivamente sin mencionar la evidencia.
     *
     * @return string
     */
    public static function coach_goal_rules_clause(): string {
        return "REGLAS SOBRE LAS METAS DEL PLAN PERSONAL DE DESARROLLO (PDP):\n"
            . "- Llama al plan siempre \"Plan Personal de Desarrollo (PDP)\".\n"
            . "- Solo puedes proponer el cierre de una meta cuando hay evidencia revisada que muestra la capacidad"
            . " definida. Si la persona pide cerrarla sin esa evidencia, explica en una frase que el cierre requiere"
            . " evidencia de avance y ofrece: una actividad breve para producirla, postergar la meta (queda visible y"
            . " puede reactivarse) o, si no quiere trabajarla, rechazarla. No presentes el cierre como una de las"
            . " opciones.\n"
            . "- Tu propones; la persona decide. Los cambios se registran en la pantalla del PDP, no en esta"
            . " conversacion: nunca digas que ya cambiaste una meta.\n"
            . "- Si la persona rechaza o posterga algo, no insistas y no generes presion; ofrece una alternativa solo"
            . " si la pide.\n"
            . "- Propone una actividad a la vez, salvo que la persona pida expresamente una planificacion mas amplia.";
    }

    /**
     * Regla del Tutor cuando es el unico modo conversable (interruptor «tutoronly»): conversa y
     * responde, cita la fuente como respaldo y no manda a la persona a otra pagina, etapa o modo.
     * Mantiene RU-TUT-015 (el Tutor no toca metas del PDP).
     *
     * @return string
     */
    public static function tutor_only_clause(): string {
        return "CONVERSACION, NO NAVEGACION (ajustes del cliente del 08-10-2026):\n"
            . "- Eres el unico modo con el que la persona conversa. Resuelve aqui sus dudas de contenido y de"
            . " navegacion, con tus palabras.\n"
            . "- Dudas de navegacion (donde se hace algo): responde con exactitud en que etapa y en que parte de la"
            . " pantalla esta, describiendolo. No des ordenes de ir a otro lugar ni enlaces. Nunca digas que algo se"
            . " hace en este chat si se hace en una pantalla del modulo. Datos fijos: las metas, sus propuestas, los"
            . " apoyos adaptativos y la entrega de evidencias se gestionan en la pantalla del Plan Personal de"
            . " Desarrollo (PDP); la evidencia se escribe en el cuadro de evidencia que esta bajo la actividad del"
            . " apoyo y se envia con el boton «Entregar evidencia». El proyecto integrador se escribe y se envia a"
            . " revision en su propia pantalla, y el diagnostico se responde en la suya. En este chat puedes ayudar a"
            . " pensar, explicar y comentar un borrador de manera informal, sin que eso cuente como entrega.\n"
            . "- Fuentes: cita la fuente que respalda tu explicacion entre parentesis, por ejemplo"
            . " (fuente: U1-LF-01, apartado 3.3). No le pidas que lea, revise o consulte un documento ni un apartado:"
            . " explica tu lo necesario.\n"
            . "- No menciones al Coach, al Evaluador ni modos del asistente: la persona no conversa con ellos. Si"
            . " pide hablar con el Coach, explicale que en la pantalla de su plan el asistente le propone metas y"
            . " apoyos, y que aqui puedes ayudarle a pensar su meta.\n"
            . "- Como Tutor no creas, modificas ni cierras metas del Plan Personal de Desarrollo (PDP); si te pregunta"
            . " por sus metas, orientala conceptualmente. Llama al plan siempre \"Plan Personal de Desarrollo (PDP)\".";
    }

    /**
     * Paso del Tutor al Coach (RU-GEN-006 y RU-TUT-015 del SPEC v5), cuando el Coach es
     * conversable (interruptor «tutoronly» apagado).
     *
     * @return string
     */
    public static function tutor_handoff_clause(): string {
        return "RELACION CON EL PLAN PERSONAL DE DESARROLLO (PDP):\n"
            . "Llama al plan siempre \"Plan Personal de Desarrollo (PDP)\", nunca \"Plan de Desarrollo Personalizado\".\n"
            . "Como Tutor no creas, modificas ni cierras metas del PDP. Si la persona pide trabajar, mejorar o"
            . " actualizar una meta de su plan, o una actividad personalizada ligada a ella, entrega una orientacion"
            . " conceptual breve y dile explicitamente que ese trabajo se hace con el asistente en modo Coach,"
            . " desde su Plan Personal de Desarrollo, donde se proponen actividades, se revisa la evidencia y se"
            . " actualizan las metas. No menciones subdimensiones que no esten en su matriz.";
    }

    /**
     * Regla esencial del Modo A: "ante una pregunta que corresponda
     * directamente a un item de quiz aun no respondido, el tutor debe guiar
     * con preguntas, pistas y referencias a la lectura; no debe indicar la
     * alternativa correcta de forma directa".
     *
     * Para poder cumplirla, el asistente necesita saber que cuestionarios del
     * curso siguen pendientes. Se listan solo los nombres de las actividades,
     * nunca su contenido ni las respuestas.
     *
     * @param int $userid
     * @param int $courseid
     * @return string Clausula para el prompt, vacia si el curso no tiene cuestionarios.
     */
    public static function quiz_guard_clause(int $userid, int $courseid): string {
        $states = self::quiz_key_states($userid, $courseid);
        if (empty($states)) {
            return '';
        }

        $protected = [];
        $released = [];
        foreach ($states as $quiz) {
            if ($quiz['state'] === self::QUIZ_KEY_RELEASED) {
                $released[] = $quiz['name'];
            } else {
                $protected[] = $quiz['name'] . ' (' . self::QUIZ_STATE_LABELS[$quiz['state']] . ')';
            }
        }

        $clause = "REGLA ESENCIAL DE INTEGRIDAD DEL CUESTIONARIO (RU-TUT-007):\n";
        if (!empty($protected)) {
            $clause .= "Cuestionarios cuya clave todavia NO ha sido liberada por Moodle: "
                . implode('; ', $protected) . ".\n"
                . "Sobre cualquier pregunta de esos cuestionarios, NO debes:\n"
                . "- indicar cual alternativa es correcta;\n"
                . "- decir que una alternativa es incorrecta, insuficiente, mejor o mas pertinente;\n"
                . "- comparar alternativas identificables ni citarlas o parafrasearlas para guiar un descarte;\n"
                . "- reescribir la pregunta de manera que la clave quede explicita;\n"
                . "- resolver el razonamiento completo de la pregunta;\n"
                . "- interpretar un resultado parcial ni atribuir errores a falta de capacidad profesional.\n"
                . "Si puedes: explicar los conceptos generales que la pregunta trabaja, recordar la secuencia"
                . " de analisis (capacidad, condiciones, actividad, evidencia y progresion), remitir a un"
                . " apartado de la lectura, el caso o el ejemplo, y formular preguntas de analisis que sirvan"
                . " para cualquier alternativa. Puedes decir: \"Puedo ayudarte a revisar el criterio para"
                . " analizar la pregunta, pero no indicar una alternativa mientras la clave no este liberada.\"\n"
                . "Entre un intento y el siguiente, puedes explicar el razonamiento de la retroalimentacion"
                . " que Moodle ya le mostro, pero sin revelar ni dejar deducible la alternativa correcta.\n";
        }
        if (!empty($released)) {
            $clause .= "En estos cuestionarios la clave ya fue liberada o no quedan intentos, asi que puedes"
                . " explicar los contenidos evaluados con mayor detalle: " . implode('; ', $released) . ".\n";
        }

        return $clause;
    }

    /** @var string Sin intentos enviados. */
    const QUIZ_KEY_PENDING = 'pendiente';
    /** @var string Hay un intento abierto. */
    const QUIZ_KEY_IN_PROGRESS = 'en_curso';
    /** @var string Al menos un intento enviado, quedan intentos y Moodle aun no muestra la clave. */
    const QUIZ_KEY_BETWEEN = 'entre_intentos';
    /** @var string Moodle ya muestra la respuesta correcta, o no quedan intentos. */
    const QUIZ_KEY_RELEASED = 'liberada';

    /** @var array Etiquetas para el modelo. */
    const QUIZ_STATE_LABELS = [
        self::QUIZ_KEY_PENDING => 'sin intentos enviados',
        self::QUIZ_KEY_IN_PROGRESS => 'intento en curso',
        self::QUIZ_KEY_BETWEEN => 'entre intentos, clave no liberada',
        self::QUIZ_KEY_RELEASED => 'clave liberada',
    ];

    /**
     * Estado de la clave de cada cuestionario del curso para la persona.
     *
     * El SPEC v5 (RU-TUT-007) protege la clave hasta que Moodle la libera, no solo hasta
     * el primer intento: en la prueba del 21-09-2026, con un intento enviado y otro
     * disponible, el Tutor dejaba la alternativa correcta deducible (caso F-42).
     *
     * La clave se considera liberada cuando la configuracion de revision del cuestionario
     * muestra la respuesta correcta en el momento actual, o cuando ya no quedan intentos.
     *
     * @param int $userid
     * @param int $courseid
     * @return array[] Por cuestionario: id, name y state (una de las constantes QUIZ_KEY_*).
     */
    public static function quiz_key_states(int $userid, int $courseid): array {
        global $DB;

        if ($courseid <= 0) {
            return [];
        }

        $sql = "SELECT q.id, q.name, q.attempts, q.timeclose, q.reviewrightanswer,
                       (SELECT COUNT(qa.id) FROM {quiz_attempts} qa
                         WHERE qa.quiz = q.id AND qa.userid = :u1 AND qa.state = 'finished') AS terminados,
                       (SELECT COUNT(qa.id) FROM {quiz_attempts} qa
                         WHERE qa.quiz = q.id AND qa.userid = :u2 AND qa.state IN ('inprogress', 'overdue')) AS abiertos
                  FROM {quiz} q
                 WHERE q.course = :courseid
              ORDER BY q.id ASC";
        try {
            $quizzes = $DB->get_records_sql($sql, ['u1' => $userid, 'u2' => $userid, 'courseid' => $courseid]);
        } catch (\Exception $e) {
            // El modulo quiz podria no estar disponible en la instalacion.
            return [];
        }

        $states = [];
        foreach ($quizzes as $quiz) {
            $states[] = [
                'id' => (int) $quiz->id,
                'name' => format_string($quiz->name),
                'state' => self::quiz_key_state((int) $quiz->terminados, (int) $quiz->abiertos, (int) $quiz->attempts,
                    (int) $quiz->reviewrightanswer, (int) $quiz->timeclose, time()),
            ];
        }
        return $states;
    }

    /**
     * Funcion pura: estado de la clave a partir de los intentos y la configuracion de revision.
     *
     * @param int $finished Intentos enviados.
     * @param int $open Intentos abiertos.
     * @param int $maxattempts Intentos permitidos (0 = ilimitados).
     * @param int $reviewrightanswer Mascara de revision de "Respuesta correcta" del cuestionario.
     * @param int $timeclose Cierre del cuestionario (0 = sin cierre).
     * @param int $now
     * @return string Una de las constantes QUIZ_KEY_*.
     */
    public static function quiz_key_state(int $finished, int $open, int $maxattempts, int $reviewrightanswer,
            int $timeclose, int $now): string {
        // Mascaras de mod_quiz\question\display_options.
        $immediately = 0x01000;
        $laterwhileopen = 0x00100;
        $afterclose = 0x00010;

        if ($open > 0) {
            return self::QUIZ_KEY_IN_PROGRESS;
        }
        if ($finished === 0) {
            return self::QUIZ_KEY_PENDING;
        }
        $closed = $timeclose > 0 && $now > $timeclose;
        $shown = $closed ? ($reviewrightanswer & $afterclose) : ($reviewrightanswer & ($immediately | $laterwhileopen));
        if ($shown || ($maxattempts > 0 && $finished >= $maxattempts)) {
            return self::QUIZ_KEY_RELEASED;
        }
        return self::QUIZ_KEY_BETWEEN;
    }

    /**
     * @param int $userid
     * @param int $courseid
     * @return bool Si algun cuestionario del curso tiene todavia la clave protegida.
     */
    public static function has_protected_quiz(int $userid, int $courseid): bool {
        foreach (self::quiz_key_states($userid, $courseid) as $quiz) {
            if ($quiz['state'] !== self::QUIZ_KEY_RELEASED) {
                return true;
            }
        }
        return false;
    }

    /**
     * Entrega un bloque estructurado con el estado de avance en los 5 hitos del Modulo 1.
     *
     * @param int $userid
     * @param int $courseid
     * @return string
     */
    public static function journey_progress_block(int $userid, int $courseid): string {
        // Ruta de 6 etapas (ajustes del cliente del 08-10-2026): la misma que ve la persona.
        if (ux::on('route6')) {
            global $DB;
            $modelo = course_bridge::route_model($userid, $courseid);
            // Que se abre al cerrar: sin este dato el Tutor inventaba la continuacion («Unidad 2, Unidad 3»).
            $modulos = [];
            if (ux::on('unlockmodules')) {
                foreach (closure::parse_course_ids((string) get_config('local_chatbot', 'nextmodules')) as $id) {
                    $nombre = $DB->get_field('course', 'fullname', ['id' => $id]);
                    if ($nombre !== false) {
                        $modulos[] = format_string($nombre);
                    }
                }
            }
            return journey::progress_block($modelo['keys'], $modelo['done'])
                . journey::next_modules_line($modulos, !empty($modelo['done'][count($modelo['keys'])]))
                . "--- FIN AVANCE EN LA RUTA ---";
        }
        $hasconsent = profile::has_consent($userid, $courseid);
        $hasdiagnostic = diagnostic::has_responses($userid, $courseid);
        $hasplan = pdp::has_active_plan($userid, $courseid);
        $project = project::get($userid, $courseid);
        $hasproject = $project && $project->status !== project::STATUS_BORRADOR;
        $hasclosure = closure::is_complete($userid, $courseid);

        $steps = [
            1 => ['name' => '1. Perfil y consentimiento informado', 'done' => $hasconsent],
            2 => ['name' => '2. Evaluación diagnóstica inicial', 'done' => $hasdiagnostic],
            3 => ['name' => '3. Plan Personal de Desarrollo (PDP)', 'done' => $hasplan],
            4 => ['name' => '4. Proyecto Integrador', 'done' => $hasproject],
            5 => ['name' => '5. Cierre y evaluación de salida', 'done' => $hasclosure],
        ];

        $currentstep = 1;
        if ($hasconsent) {
            $currentstep = 2;
            if ($hasdiagnostic) {
                $currentstep = 3;
                if ($hasplan) {
                    $currentstep = 4;
                    if ($hasproject) {
                        $currentstep = 5;
                        if ($hasclosure) {
                            $currentstep = 6;
                        }
                    }
                }
            }
        }

        $out = "--- AVANCE EN LA RUTA FORMATIVA DEL MÓDULO 1 ---\n";
        foreach ($steps as $num => $st) {
            $out .= sprintf("[%s] Paso %d: %s\n", $st['done'] ? 'COMPLETADO' : 'PENDIENTE', $num, $st['name']);
        }
        if ($currentstep <= 5) {
            $out .= "Hito actual pendiente de la persona: Paso {$currentstep} ({$steps[$currentstep]['name']}).\n";
            $out .= "Instrucción pedagógica: Cuando la persona pregunte dónde va, qué le falta o cuál es su siguiente paso, "
                . "oriéntala con precisión según este avance, animándola a avanzar hacia el hito pendiente sin perder de vista su contexto.\n";
        } else {
            $out .= "Estado: ¡La persona ha completado exitosamente todos los hitos del Módulo 1!\n";
        }
        $out .= "--- FIN AVANCE EN LA RUTA ---";
        return $out;
    }

    /**
     * Prompt de respaldo cuando la base de datos todavia no tiene una version
     * cargada para ese modo. Mantiene el plugin operativo, pero lo normal es
     * que el prompt venga de prompt_store para que quede versionado.
     *
     * @param string $mode
     * @return string
     */
    private static function fallback_prompt(string $mode): string {
        $prompts = seeder::default_prompts();
        return $prompts[$mode] ?? $prompts[self::MODE_TUTOR];
    }
}
