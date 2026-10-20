<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Observadores de eventos de Moodle que alimentan la trazabilidad del Anexo G.
 *
 * El recorrido por los recursos fijos (lectura, caso, ejemplo y quiz) se traza
 * escuchando los eventos que Moodle ya emite, no instrumentando las paginas: la
 * actividad se reconoce por su idnumber (U1-LF-01 y similares), asi que una
 * actividad sin codigo no genera registros. Es deliberado: el Anexo G identifica
 * los recursos por codigo, y registrar actividades ajenas al modulo ensuciaria
 * los indicadores.
 */
class observer {

    /**
     * Un intento de quiz calificado: se guarda el resultado por pregunta (Anexo G §8.3).
     *
     * @param \mod_quiz\event\attempt_graded $event
     * @return void
     */
    public static function quiz_attempt_graded(\mod_quiz\event\attempt_graded $event): void {
        try {
            quiz_patterns::record_attempt((int) $event->objectid);
        } catch (\Throwable $e) {
            // Nunca interrumpir el envio del intento por un fallo de trazabilidad.
            debugging('local_chatbot: no se registraron los patrones del intento ' . $event->objectid . ': '
                . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Apertura de un recurso fijo: EVT-U[n]-[LF|CA|ER|QF]-VIEW (Anexo G §4).
     *
     * Se observa la clase padre, asi que cubre paginas, archivos, cuestionarios y
     * cualquier otro modulo que la extienda.
     *
     * @param \core\event\course_module_viewed $event
     * @return void
     */
    public static function module_viewed(\core\event\course_module_viewed $event): void {
        self::registrar_recurso($event, trace::RES_VIEW, []);
    }

    /**
     * Finalizacion de un recurso fijo: EVT-U[n]-[LF|CA|ER|QF]-COMPLETE (Anexo G §4).
     *
     * Solo cuenta el paso a completado; el evento de Moodle tambien salta cuando la
     * finalizacion se deshace o la revisa alguien del equipo.
     *
     * @param \core\event\course_module_completion_updated $event
     * @return void
     */
    public static function module_completed(\core\event\course_module_completion_updated $event): void {
        global $CFG;
        // Las constantes de finalizacion no estan cargadas en todos los contextos
        // desde los que Moodle dispara este evento (por ejemplo, el cron).
        require_once($CFG->dirroot . '/lib/completionlib.php');
        $otros = $event->other ?? [];
        $estado = (int) ($otros['completionstate'] ?? $otros['relateduserid'] ?? COMPLETION_INCOMPLETE);
        if (!in_array($estado, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
            return;
        }
        // La finalizacion la registra Moodle para la persona del curso, que en este
        // evento viaja como relateduserid y no como userid.
        self::registrar_recurso($event, trace::RES_COMPLETE, ['estado' => $estado],
            (int) ($event->relateduserid ?: $event->userid));
    }

    /**
     * La persona entra al curso: sus actividades del puente quedan iguales al recorrido.
     *
     * Cubre el avance que ocurrio antes de que existiera el puente y cualquier
     * desfase: el curso siempre muestra lo que dicen los datos del asistente.
     *
     * @param \core\event\course_viewed $event
     * @return void
     */
    public static function course_viewed(\core\event\course_viewed $event): void {
        $courseid = (int) $event->courseid;
        if ($courseid > 0 && $courseid != SITEID && (int) $event->userid > 0) {
            course_bridge::sync((int) $event->userid, $courseid);
        }
    }

    /**
     * Alguien cambio la finalizacion de una actividad del puente.
     *
     * Si la persona marca "hecho" un paso que no hizo, se revierte: la finalizacion
     * del paso la decide el asistente, no un clic. Cuando el cambio lo hizo el
     * propio puente, el estado ya coincide y no se escribe nada, asi que no hay
     * bucle.
     *
     * @param \core\event\course_module_completion_updated $event
     * @return void
     */
    public static function bridge_completion_changed(\core\event\course_module_completion_updated $event): void {
        try {
            $courseid = (int) $event->courseid;
            $cmid = (int) $event->contextinstanceid;
            if ($courseid <= 0 || $cmid <= 0) {
                return;
            }
            $modinfo = get_fast_modinfo($courseid);
            if (!isset($modinfo->cms[$cmid])
                    || (course_bridge::step_for_code((string) $modinfo->cms[$cmid]->idnumber) === ''
                        && !course_bridge::is_consent_code((string) $modinfo->cms[$cmid]->idnumber))) {
                return;
            }
            course_bridge::sync((int) ($event->relateduserid ?: $event->userid), $courseid);
        } catch (\Throwable $e) {
            debugging('local_chatbot: no se pudo revisar la finalizacion del paso: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Inicio de un intento del quiz formativo: EVT-U[n]-QF-START (Anexo G §4).
     *
     * @param \mod_quiz\event\attempt_started $event
     * @return void
     */
    public static function quiz_attempt_started(\mod_quiz\event\attempt_started $event): void {
        self::registrar_quiz($event, trace::QUIZ_STARTED);
    }

    /**
     * Consulta de la retroalimentacion de un intento: EVT-U[n]-QF-REVIEW (Anexo G §4).
     *
     * @param \mod_quiz\event\attempt_reviewed $event
     * @return void
     */
    public static function quiz_attempt_reviewed(\mod_quiz\event\attempt_reviewed $event): void {
        self::registrar_quiz($event, trace::QUIZ_REVIEWED);
    }

    /**
     * Registra un evento de recurso fijo si la actividad lleva codigo del Anexo G.
     *
     * @param \core\event\base $event
     * @param string $plantilla trace::RES_VIEW o trace::RES_COMPLETE.
     * @param array $datos Datos adicionales del registro.
     * @param int|null $userid Persona a la que corresponde el registro.
     * @return void
     */
    protected static function registrar_recurso(\core\event\base $event, string $plantilla,
            array $datos = [], ?int $userid = null): void {
        try {
            $courseid = (int) $event->courseid;
            $cmid = (int) $event->contextinstanceid;
            if ($courseid <= 0 || $cmid <= 0) {
                return;
            }
            $modinfo = get_fast_modinfo($courseid);
            if (!isset($modinfo->cms[$cmid])) {
                return;
            }
            $cm = $modinfo->cms[$cmid];
            $idnumber = (string) $cm->idnumber;
            $kind = trace::resource_kind($idnumber);
            if ($kind === '') {
                return;
            }
            // El quiz no tiene evento de apertura en el catalogo del Anexo G: su
            // recorrido se traza con START, SUBMIT, REVIEW y COMPLETE. Emitir un
            // QF-VIEW inventaria un codigo fuera del estandar del cliente.
            if ($kind === 'QF' && $plantilla === trace::RES_VIEW) {
                return;
            }
            $plantillafinal = ($kind === 'QF')
                ? trace::QUIZ_COMPLETED
                : trace::resource_event($plantilla, $kind);
            trace::log($plantillafinal, $userid ?: (int) $event->userid, $courseid,
                trace::unit_from_hint($idnumber), $datos + ['recurso' => $idnumber]);
        } catch (\Throwable $e) {
            debugging('local_chatbot: no se registro el recorrido del recurso: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Registra un evento de intento de quiz si el cuestionario lleva codigo del Anexo G.
     *
     * @param \core\event\base $event
     * @param string $codigo Codigo de evento con {U} sin resolver.
     * @return void
     */
    protected static function registrar_quiz(\core\event\base $event, string $codigo): void {
        try {
            $courseid = (int) $event->courseid;
            $cmid = (int) $event->contextinstanceid;
            if ($courseid <= 0 || $cmid <= 0) {
                return;
            }
            $modinfo = get_fast_modinfo($courseid);
            if (!isset($modinfo->cms[$cmid])) {
                return;
            }
            $idnumber = (string) $modinfo->cms[$cmid]->idnumber;
            if (trace::resource_kind($idnumber) !== 'QF') {
                return;
            }
            trace::log($codigo, (int) $event->relateduserid ?: (int) $event->userid, $courseid,
                trace::unit_from_hint($idnumber),
                ['recurso' => $idnumber, 'intento' => (int) $event->objectid]);
        } catch (\Throwable $e) {
            debugging('local_chatbot: no se registro el evento del quiz: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
