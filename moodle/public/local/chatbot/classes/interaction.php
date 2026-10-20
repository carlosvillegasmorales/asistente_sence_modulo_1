<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Objeto de datos "Interaccion IA" del Modulo 1.
 *
 * El documento de arquitectura lo define con campos precisos: modo, fecha,
 * intencion, recursos referidos, recomendacion y aceptacion del usuario, y le
 * asigna un uso explicito: "auditoria, calidad y mejora del asistente".
 *
 * La diferencia con el historial de mensajes es deliberada. El historial
 * guarda la conversacion; esto guarda que hizo el asistente y que hizo la
 * persona con lo que el asistente le propuso. Sin este registro no hay forma
 * de responder la pregunta que el pilotaje necesita: cuantas recomendaciones
 * se aceptaron, cuantas se modificaron y cuantas se descartaron.
 */
class interaction {

    /** @var int La persona todavia no se pronuncia sobre la recomendacion. */
    const PENDING = -1;

    /** @var int La persona descarto la recomendacion. */
    const REJECTED = 0;

    /** @var int La persona acepto la recomendacion tal cual. */
    const ACCEPTED = 1;

    /** @var int La persona acepto la recomendacion pero la modifico. */
    const MODIFIED = 2;

    /** @var int La persona dejo la recomendacion para mas adelante (RU-COA-004). */
    const POSTPONED = 3;

    /**
     * Clasifica de que tipo es una consulta, sin enviarla a ningun modelo.
     *
     * RU-PRIV-004 pide registrar "el tipo de consulta" de cada interaccion.
     * Antes se guardaban los primeros 100 caracteres del mensaje, que no es un
     * tipo sino una copia parcial del texto de la persona: dejaba contenido
     * conversacional dentro de la tabla de auditoria, que es justo la que mas
     * gente puede leer para calcular indicadores. Una etiqueta cerrada dice lo
     * que el indicador necesita —sobre que se consulta— sin arrastrar nada de
     * lo que la persona escribio.
     *
     * Es un clasificador por palabras clave, no comprension semantica: acierta
     * en la formulacion frecuente y cae en 'otro' cuando no reconoce el caso.
     * Se resolvio asi a proposito: una segunda llamada al modelo para etiquetar
     * la primera duplicaria costo y latencia, y volveria a enviar el texto que
     * este metodo existe para no guardar.
     *
     * @param string $message Mensaje de la persona.
     * @return string Etiqueta de tipo de consulta.
     */
    public static function classify_intent(string $message): string {
        $text = \core_text::strtolower($message);

        $rules = [
            // El orden importa: gana la primera regla que calza, y las mas
            // especificas van antes que las mas generales.
            'tecnico' => '/\b(error|no\s+(carga|funciona|abre|guarda|deja)|se\s+cay[oó]|'
                . 'pantalla\s+en\s+blanco|no\s+puedo\s+(entrar|acceder|subir)|falla)\b/u',
            'termino' => '/\b(qu[eé]\s+(es|significa)|a\s+qu[eé]\s+se\s+refiere|definici[oó]n\s+de|'
                . 'diferencia\s+entre)\b/u',
            'evaluacion' => '/\b(quiz|cuestionario|r[uú]brica|criterio\s+de\s+evaluaci[oó]n|'
                . 'proyecto\s+integrador|nota|calificaci[oó]n|alternativa)\b/u',
            'pdp' => '/\b(pdp|plan\s+personal|meta|prioridad|objetivo\s+de\s+desarrollo)\b/u',
            'retroalimentacion' => '/\b(revisa|rev[ií]same|qu[eé]\s+te\s+parece|'
                . 'retroalimenta|comenta\s+mi|mejorar[ií]as)\b/u',
            'procedimiento' => '/\b(c[oó]mo\s+(funciona|hago|se\s+hace|entrego|subo)|d[oó]nde\s+(esta|encuentro)|'
                . 'cu[aá]ndo\s+(debo|tengo))\b/u',
            'contenido' => '/\b(explica|expl[ií]came|ejemplo\s+de|c[oó]mo\s+redacto|c[oó]mo\s+diseno|'
                . 'c[oó]mo\s+dise[nñ]o)\b/u',
        ];

        foreach ($rules as $label => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $label;
            }
        }

        return 'otro';
    }

    /**
     * Registra una interaccion con el asistente.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $mode Modo funcional en que ocurrio.
     * @param string $intent Intencion detectada o declarada de la consulta.
     * @param string[] $resources Fuentes de contexto que se declararon a la persona.
     * @param string $recommendation Recomendacion accionable, si la hubo.
     * @param int|null $messageid Mensaje del historial al que corresponde.
     * @param array $guardrails Reglas de guardrails activadas.
     * @param string $pseudoid Identificador seudonimizado enviado al modelo.
     * @param string $model Nombre del modelo de IA utilizado.
     * @param int $inputtokens Cantidad de tokens de entrada.
     * @param int $outputtokens Cantidad de tokens generados.
     * @param int $latencyms Tiempo de respuesta en milisegundos.
     * @param string $status Estado de la operacion (ok, error, timeout, blocked, ratelimit).
     * @return int id del registro.
     */
    public static function record(int $userid, int $courseid, string $mode, string $intent,
            array $resources = [], string $recommendation = '', ?int $messageid = null,
            array $guardrails = [], string $pseudoid = '', ?string $model = '',
            int $inputtokens = 0, int $outputtokens = 0, int $latencyms = 0,
            string $status = 'ok'): int {
        global $DB;

        $now = time();
        $record = new \stdClass();
        $record->userid = $userid;
        $record->courseid = $courseid;
        $record->mode = $mode;
        $record->intent = substr($intent, 0, 100);
        $record->resources = implode(', ', $resources);
        $record->recommendation = $recommendation;
        // Solo tiene sentido esperar una decision si hubo algo que decidir.
        $record->useraccepted = ($recommendation !== '') ? self::PENDING : self::ACCEPTED;
        $record->messageid = $messageid;
        $record->promptversion = prompt_store::get_version($mode);
        // RF-GRD-02: que reglas se activaron en esta interaccion, por identificador.
        $record->guardrail = !empty($guardrails) ? implode(',', $guardrails) : null;
        $record->pseudoid = $pseudoid ?: pseudonymizer::for_user($userid);
        $record->model = substr($model, 0, 100);
        $record->input_tokens = (int) $inputtokens;
        $record->output_tokens = (int) $outputtokens;
        $record->latency_ms = (int) $latencyms;
        $record->status = substr($status, 0, 20);
        $record->timecreated = $now;
        $record->timemodified = $now;

        $id = $DB->insert_record('local_chatbot_interaction', $record);

        // Al log de Moodle va solo el hecho y sus metadatos, nunca el texto de
        // la conversacion ni la recomendacion: eso ya vive en esta tabla.
        \local_chatbot\event\ai_interaction_recorded::create([
            'context' => \context_system::instance(),
            'userid' => $userid,
            'objectid' => $id,
            'other' => [
                'mode' => $mode,
                'promptversion' => $record->promptversion,
                'status' => $record->status,
                'latency_ms' => $record->latency_ms,
            ],
        ])->trigger();

        return $id;
    }

    /**
     * Guarda que hizo la persona con la recomendacion recibida.
     *
     * @param int $interactionid
     * @param int $userid Se exige para no permitir responder por otra persona.
     * @param int $decision Una de las constantes ACCEPTED, REJECTED o MODIFIED.
     * @return bool
     */
    public static function set_decision(int $interactionid, int $userid, int $decision): bool {
        global $DB;

        if (!in_array($decision, [self::ACCEPTED, self::REJECTED, self::MODIFIED, self::POSTPONED], true)) {
            return false;
        }

        $record = $DB->get_record('local_chatbot_interaction', ['id' => $interactionid, 'userid' => $userid]);
        if (!$record) {
            return false;
        }

        $record->useraccepted = $decision;
        $record->timemodified = time();
        $DB->update_record('local_chatbot_interaction', $record);

        return true;
    }

    /**
     * @param int $userid
     * @param int $courseid
     * @return \stdClass[] Recomendaciones que la persona todavia no ha revisado.
     */
    public static function list_pending(int $userid, int $courseid): array {
        global $DB;
        return $DB->get_records('local_chatbot_interaction',
            ['userid' => $userid, 'courseid' => $courseid, 'useraccepted' => self::PENDING],
            'timecreated DESC');
    }
}
