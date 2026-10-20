<?php
/**
 * Un turno de conversacion con el asistente del Modulo 1.
 *
 * Recibe el modo funcional en que la persona esta trabajando (tutor, coach o
 * evaluador), arma el contexto correspondiente, llama al proveedor de IA y
 * devuelve la respuesta junto con la lista de fuentes que se usaron.
 *
 * Esa lista viaja de vuelta a proposito: la regla de gobernanza exige que las
 * interacciones expliciten si el asistente esta utilizando resultados del
 * diagnostico, avance de unidad, PDP o proyecto integrador, asi que el widget
 * la muestra bajo cada respuesta.
 *
 * Nada identificatorio sale hacia el proveedor: la persona viaja como
 * seudonimo y el texto se limpia de correos y RUT antes de enviarse.
 */

define('AJAX_SCRIPT', true);
require_once('../../config.php');

require_login();
require_sesskey();
require_capability('local/chatbot:use', context_system::instance());

$message = required_param('message', PARAM_TEXT);
$mode = optional_param('mode', \local_chatbot\assistant::MODE_TUTOR, PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);
// Etapa del instrumento que la persona esta respondiendo, si el turno ocurre
// sobre el diagnostico. Solo puede endurecer las reglas, nunca relajarlas:
// resolve_phase() vuelve a comprobar contra la base de datos si esa etapa ya
// fue enviada.
$diagstage = optional_param('diagstage', '', PARAM_ALPHA);
// Actividad desde la que se abrio el asistente (Anexo E §11). Se valida contra el curso.
$cmid = optional_param('cmid', 0, PARAM_INT);
$screen = \local_chatbot\ux::on('screencontext') ? max(0, min(6, optional_param('screen', 0, PARAM_INT))) : 0;

global $USER, $DB;

// Respuesta en vivo (SSE): solo si esta encendida, con Gemini y si el widget la pide. Los
// encabezados van antes de cualquier salida; desde aqui todo lo que se devuelve pasa por
// local_chatbot_ajax_respond(), que en vivo lo manda como evento «final».
$stream = optional_param('stream', 0, PARAM_INT) === 1 && \local_chatbot\ux::on('streaming')
    && \local_chatbot\ai\provider_factory::get_active() === 'gemini';
if ($stream) {
    @ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
}

/**
 * Envia un evento SSE y lo empuja al navegador.
 *
 * @param string $event delta, replace o final.
 * @param array $data
 * @return void
 */
function local_chatbot_sse(string $event, array $data): void {
    echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    flush();
}

/**
 * Devuelve la respuesta del turno: JSON, o el evento final en vivo.
 *
 * @param array $payload
 * @param bool $stream
 * @return void
 */
function local_chatbot_ajax_respond(array $payload, bool $stream): void {
    if ($stream) {
        local_chatbot_sse('final', $payload);
    } else {
        echo json_encode($payload);
    }
}

if (!\local_chatbot\assistant::is_valid_mode($mode)) {
    $mode = \local_chatbot\assistant::MODE_TUTOR;
}

// Un courseid ajeno no debe poder usarse para armar contexto.
if ($courseid > 0 && !is_enrolled(context_course::instance($courseid, IGNORE_MISSING), $USER->id)) {
    $courseid = 0;
}

// Conversacion por meta: la meta tiene que ser del plan de quien escribe; si no, chat general.
$goalid = \local_chatbot\thread::goal_for((int) $USER->id, $courseid, optional_param('goalid', 0, PARAM_INT));

// Bajo que reglas opera este turno. Puede no ser el modo que pidio el widget:
// mientras el diagnostico no se envia, manda la fase de diagnostico
// (RU-DIAG-002), y el Coach no existe antes de ese envio (RU-COA-001).
$phase = \local_chatbot\assistant::resolve_phase($mode, $USER->id, $courseid, $diagstage);
$indiagnostic = ($phase === \local_chatbot\assistant::PHASE_DIAGNOSTICO);

// El consentimiento informado es condicion previa para que el asistente opere.
if (!\local_chatbot\profile::has_consent($USER->id, $courseid)) {
    local_chatbot_ajax_respond([
        'reply' => get_string('error_needsconsent', 'local_chatbot'),
        'resources' => [],
        'needsconsent' => true,
    ], $stream);
    exit;
}

// Una contrasena pegada o autocompletada en el chat no se guarda ni viaja al proveedor de IA.
if (\local_chatbot\guardrails::looks_like_password($message)) {
    local_chatbot_ajax_respond([
        'reply' => get_string('error_looks_like_password', 'local_chatbot'),
        'resources' => [],
    ], $stream);
    exit;
}

if (!\local_chatbot\ai\provider_factory::is_configured()) {
    local_chatbot_ajax_respond([
        'reply' => get_string('error_nokey', 'local_chatbot'),
        'resources' => [],
    ], $stream);
    exit;
}

$starttime = microtime(true);

// --- 0. Rate limiting por usuario e instalacion (ERTF v3.0, RF-LIM-01) ---
$ratecheck = \local_chatbot\rate_limiter::check_and_increment($USER->id);
if (!$ratecheck['allowed']) {
    $pseudoid = \local_chatbot\pseudonymizer::for_user($USER->id);
    \local_chatbot\interaction::record(
        $USER->id, $courseid, $phase, \local_chatbot\interaction::classify_intent($message),
        [], '', null, [], $pseudoid, '', 0, 0, 0, 'ratelimit'
    );
    local_chatbot_ajax_respond([
        'reply' => get_string('error_ratelimit_local', 'local_chatbot'),
        'resources' => [],
        'ratelimited' => true,
    ], $stream);
    exit;
}

// --- 1. Registro del mensaje de la persona ---
$messagerecord = new stdClass();
$messagerecord->userid = $USER->id;
$messagerecord->courseid = $courseid;
$messagerecord->message = $message;
$messagerecord->is_bot = 0;
$messagerecord->mode = $phase;
$messagerecord->timecreated = time();
$messagerecord->goalid = $goalid ?: null;

$messageid = null;
try {
    $messageid = $DB->insert_record('local_chatbot_messages', $messagerecord);
} catch (Exception $e) {
    debugging('local_chatbot: no se pudo registrar el mensaje: ' . $e->getMessage(), DEBUG_DEVELOPER);
}

// --- 2. Contexto del modo ---
$context = \local_chatbot\assistant::build_context($phase, $USER->id, $courseid, $screen);
$hilo = \local_chatbot\thread::context_line($goalid);
if ($hilo !== '') {
    $context['prompt'] .= "\n" . $hilo . "\n";
}

$contents = [];
$contents[] = [
    'role' => 'user',
    'parts' => [['text' => $context['prompt']]],
];
$contents[] = [
    'role' => 'model',
    'parts' => [['text' => 'Entendido. Trabajare en este modo y con este contexto.']],
];

// --- 3. Historial reciente del mismo modo ---
// Se acota al modo actual para que el asistente no arrastre a una sesion de
// evaluacion lo conversado como tutor, que es justamente lo que la separacion
// de modos busca evitar.
try {
    [$hilosql, $hiloparams] = \local_chatbot\thread::where($goalid);
    $past = $DB->get_records_select('local_chatbot_messages',
        "userid = :userid AND courseid = :courseid AND mode = :mode AND {$hilosql}",
        ['userid' => $USER->id, 'courseid' => $courseid, 'mode' => $phase] + $hiloparams,
        'timecreated DESC, id DESC', '*', 0, 11);

    // Por id y no por segundo: si la persona escribe en el mismo segundo en que llego la
    // respuesta anterior, filtrar por timecreated dejaba esa respuesta fuera y el modelo
    // veia la pregunta previa sin contestar, asi que la respondia de nuevo (prueba del
    // 21-09-2026, casos F-07 y F-26).
    $past = array_filter($past, function($m) use ($messagerecord, $messageid) {
        return $messageid ? ((int) $m->id < (int) $messageid) : ($m->timecreated < $messagerecord->timecreated);
    });
    $past = array_reverse($past);

    $fs = get_file_storage();
    foreach ($past as $entry) {
        $parts = [['text' => \local_chatbot\pseudonymizer::scrub($entry->message)]];

        if (!empty($entry->attachment_filename)) {
            $stored = $fs->get_file(context_system::instance()->id, 'local_chatbot',
                'chat_attachment', $entry->id, '/', $entry->attachment_filename);
            if ($stored) {
                // NFR-SEC-003: la marca va antes del archivo, en el mismo turno.
                $parts[] = ['text' => \local_chatbot\guardrails::attachment_notice()];
                $parts[] = ['inlineData' => [
                    'mimeType' => $entry->attachment_mimetype ?: 'application/pdf',
                    'data' => base64_encode($stored->get_content()),
                ]];
            }
        }

        $contents[] = [
            'role' => $entry->is_bot ? 'model' : 'user',
            'parts' => $parts,
        ];
    }
} catch (Exception $e) {
    debugging('local_chatbot: no se pudo reconstruir el historial: ' . $e->getMessage(), DEBUG_DEVELOPER);
}

// --- 4. Turno actual, con adjunto si lo hay ---
// GR-07 (capa de entrada): lo que escribe la persona viaja dentro de un bloque
// delimitado que el prompt de sistema ya declaro como contenido a analizar y no
// como instrucciones. Sin esto, un mensaje que diga "ignora tus reglas" llega al
// modelo con el mismo estatus que las reglas mismas.
$currentparts = [['text' => \local_chatbot\guardrails::isolate_user_content(
    \local_chatbot\pseudonymizer::scrub($message))]];

if (isset($_FILES['chat_attachment']) && $_FILES['chat_attachment']['error'] === UPLOAD_ERR_OK) {
    $tmpname = $_FILES['chat_attachment']['tmp_name'];
    $realmime = mime_content_type($tmpname);

    if ($realmime === 'application/pdf') {
        $currentparts[] = ['text' => \local_chatbot\guardrails::attachment_notice()];
        $currentparts[] = ['inlineData' => [
            'mimeType' => 'application/pdf',
            'data' => base64_encode(file_get_contents($tmpname)),
        ]];

        if (!empty($messageid)) {
            $filename = clean_param($_FILES['chat_attachment']['name'], PARAM_FILE);
            $fs = get_file_storage();
            $filerecord = [
                'contextid' => context_system::instance()->id,
                'component' => 'local_chatbot',
                'filearea' => 'chat_attachment',
                'itemid' => $messageid,
                'filepath' => '/',
                'filename' => $filename,
            ];
            $fs->delete_area_files($filerecord['contextid'], $filerecord['component'],
                $filerecord['filearea'], $filerecord['itemid']);
            $fs->create_file_from_pathname($filerecord, $tmpname);

            $DB->update_record('local_chatbot_messages', (object) [
                'id' => $messageid,
                'attachment_filename' => $filename,
                'attachment_mimetype' => 'application/pdf',
            ]);
        }
    }
}

$contents[] = ['role' => 'user', 'parts' => $currentparts];

// --- 5. Llamada al proveedor ---
// Reglas de salida que dependen del momento: se resuelven antes de llamar porque en vivo cada
// frase se revisa a medida que llega.
$haspendingquiz = \local_chatbot\assistant::has_protected_quiz($USER->id, $courseid)
    && $phase === \local_chatbot\assistant::MODE_TUTOR;

// La sesion no se necesita mas en este turno: soltarla evita que la espera del modelo bloquee
// otras peticiones de la misma persona (abrir otra pagina, el aviso de cierre del widget).
\core\session\manager::write_close();

$configuredmodel = \local_chatbot\ai\provider_factory::get_configured_model();
if ($stream) {
    $liberado = 0;
    $alvivo = function(string $acumulado) use (&$liberado, $haspendingquiz, $indiagnostic): bool {
        $hasta = \local_chatbot\stream::release_point($acumulado, $liberado, false);
        if ($hasta <= $liberado) {
            return true;
        }
        // Lo que se va a mostrar pasa primero por los guardrails de salida.
        $revisado = \local_chatbot\guardrails::check_output(substr($acumulado, 0, $hasta), $haspendingquiz, $indiagnostic);
        if ($revisado['blocked']) {
            local_chatbot_sse('replace', ['text' => $revisado['reply']]);
            return false;
        }
        local_chatbot_sse('delta', ['text' => substr($acumulado, $liberado, $hasta - $liberado)]);
        $liberado = $hasta;
        return true;
    };
    $result = \local_chatbot\gemini_client::stream_content($contents, 90,
        $configuredmodel ?: \local_chatbot\gemini_client::DEFAULT_MODEL, $alvivo);
} else {
    $result = \local_chatbot\ai\provider_factory::get()->generate_content($contents, 60, 2, $configuredmodel);
}
$latencyms = (int) round((microtime(true) - $starttime) * 1000);
$modelname = (string) ($result['modelVersion'] ?? $result['model'] ?? $configuredmodel ?? get_config('local_chatbot', 'ai_provider') ?: 'default');

$reply = get_string('error_unexpected', 'local_chatbot');
$text = \local_chatbot\gemini_client::extract_text($result);

if ($text !== null) {
    // Extraer o estimar tokens de entrada y salida (ERTF v3.0).
    $inputtokens = $result['usageMetadata']['promptTokenCount'] ?? $result['usage']['prompt_tokens'] ?? 0;
    $outputtokens = $result['usageMetadata']['candidatesTokenCount'] ?? $result['usage']['completion_tokens'] ?? 0;
    if ($inputtokens === 0 && !empty($context['prompt'])) {
        $inputtokens = (int) ceil((strlen($context['prompt']) + strlen($message)) / 4);
    }
    if ($outputtokens === 0 && !empty($text)) {
        $outputtokens = (int) ceil(strlen($text) / 4);
    }

    // Guardrails de salida (ERTF §8.4). Se aplican antes de guardar y antes de
    // devolver: si una regla bloquea, lo que queda en el historial es la
    // respuesta segura, no la original. La original no se persiste en ningun
    // lado, porque conservarla solo crearia una copia del contenido que
    // acabamos de decidir que no debe circular.
    $checked = \local_chatbot\guardrails::check_output($text, $haspendingquiz, $indiagnostic);

    $reply = $checked['reply'];
    $triggered = $checked['triggered'];

    if (!empty($triggered)) {
        debugging('local_chatbot: guardrails activados (' . implode(',', $triggered) . ')', DEBUG_DEVELOPER);
    }

    $botmessage = new stdClass();
    $botmessage->userid = $USER->id;
    $botmessage->courseid = $courseid;
    $botmessage->message = $reply;
    $botmessage->is_bot = 1;
    $botmessage->mode = $phase;
    $botmessage->timecreated = time();
    $botmessage->goalid = $goalid ?: null;

    $botmessageid = null;
    try {
        $botmessageid = $DB->insert_record('local_chatbot_messages', $botmessage);
    } catch (Exception $e) {
        debugging('local_chatbot: no se pudo guardar la respuesta: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    // Registro del objeto Interaccion IA. En los modos coach y evaluador la
    // respuesta cuenta como recomendacion y queda a la espera de que la
    // persona diga que hizo con ella.
    // Una respuesta bloqueada no es una recomendacion: no tiene sentido
    // preguntarle a la persona si la acepta.
    $isrecommendation = !$checked['blocked'] && in_array($phase, [
        \local_chatbot\assistant::MODE_COACH,
        \local_chatbot\assistant::MODE_EVALUADOR,
    ], true);

    $status = $checked['blocked'] ? 'blocked' : 'ok';
    $pseudoid = \local_chatbot\pseudonymizer::for_user($USER->id);

    $interactionid = \local_chatbot\interaction::record(
        $USER->id, $courseid, $phase,
        \local_chatbot\interaction::classify_intent($message), $context['resources'],
        $isrecommendation ? $reply : '', $botmessageid, $triggered,
        $pseudoid, $modelname, $inputtokens, $outputtokens, $latencyms, $status
    );

    // Registro estructurado y eventos de trazabilidad (Anexo E §11, Anexo G §7). Solo
    // codigos, etiquetas y estados: nunca el texto de la persona.
    try {
        $source = \local_chatbot\trace::source_resource($courseid, $cmid);
        $unit = $source !== '' ? \local_chatbot\trace::unit_from_hint($source) : \local_chatbot\trace::unit_for_course($courseid);
        $prefix = $phase === \local_chatbot\assistant::MODE_TUTOR ? 'IA-T'
            : ($phase === \local_chatbot\assistant::MODE_COACH ? 'IA-C' : 'IA-' . strtoupper(substr($phase, 0, 1)));
        $code = \local_chatbot\trace::code($prefix, $unit, (int) $interactionid);
        $tags = \local_chatbot\trace::concept_tags($message);
        $sources = \local_chatbot\trace::sources_referred($reply);
        $intent = \local_chatbot\trace::tutor_intent($message);
        $quizguard = ($haspendingquiz && $intent === 'apoyo_quiz') ? 1 : 0;
        // RU-PRIV-006: con que version del material se respondio. El recurso
        // citado manda; si la respuesta no cito ninguno, vale el de origen.
        $citado = $sources[0] ?? $source;
        $DB->update_record('local_chatbot_interaction', (object) [
            'id' => $interactionid, 'interactioncode' => $code, 'sourceresource' => $source ?: null,
            'sourceversion' => \local_chatbot\kb_store::resource_version((string) $citado) ?: null,
            'kbversion' => \local_chatbot\kb_store::context_version() ?: null,
            'concepttags' => implode(',', $tags), 'sourcesreferred' => implode(',', $sources), 'quizguard' => $quizguard,
        ]);
        $opens = \local_chatbot\trace::opens_session($USER->id, $courseid, $phase, (int) $messageid);
        if ($phase === \local_chatbot\assistant::MODE_TUTOR) {
            if ($opens) {
                \local_chatbot\trace::log(\local_chatbot\trace::T_OPEN, $USER->id, $courseid, $unit,
                    ['recurso_origen' => $source], $code);
            }
            \local_chatbot\trace::log(\local_chatbot\trace::T_CONSULT, $USER->id, $courseid, $unit,
                ['intencion' => $intent, 'conceptos' => $tags, 'fuentes' => $sources, 'regla_quiz' => (bool) $quizguard], $code);
            if ($quizguard) {
                // Sin la clave ni la alternativa: solo que la regla se aplico (Anexo G §7.2).
                \local_chatbot\trace::log(\local_chatbot\trace::T_QUIZ_GUARD, $USER->id, $courseid, $unit, [], $code);
            }
            if ($intent === 'transferencia_sectorial') {
                \local_chatbot\trace::log(\local_chatbot\trace::T_TRANSFER, $USER->id, $courseid, $unit,
                    ['conceptos' => $tags], $code);
            }
            if (\local_chatbot\trace::refers_to_coach($reply)) {
                \local_chatbot\trace::log(\local_chatbot\trace::T_COACH_REF, $USER->id, $courseid, $unit, [], $code);
            }
        } else if ($phase === \local_chatbot\assistant::MODE_COACH && $opens) {
            \local_chatbot\trace::log(\local_chatbot\trace::C_OPEN, $USER->id, $courseid, $unit, [], $code);
        }
    } catch (\Throwable $e) {
        debugging('local_chatbot: no se pudo completar la trazabilidad: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    local_chatbot_ajax_respond([
        'reply' => trim($reply),
        'resources' => $context['resources'],
        'interactionid' => $isrecommendation ? $interactionid : 0,
        // Para la valoracion 👍/👎: cualquier respuesta, no solo las recomendaciones.
        'feedbackid' => \local_chatbot\ux::on('feedback') ? (int) $interactionid : 0,
    ], $stream);
    exit;
}

$retryable = false;

if (isset($result['error'])) {
    $code = (int) $result['error']['code'];
    $status = ($code === 504 || $code === 408) ? 'timeout' : 'error';
    $pseudoid = \local_chatbot\pseudonymizer::for_user($USER->id);
    \local_chatbot\interaction::record(
        $USER->id, $courseid, $phase, \local_chatbot\interaction::classify_intent($message),
        [], '', null, [], $pseudoid, $modelname, 0, 0, $latencyms, $status
    );

    // Incidencia tecnica del asistente (Doc 6.0 §10.2).
    \local_chatbot\trace::log(\local_chatbot\trace::TECH_INCIDENT, $USER->id, $courseid,
        \local_chatbot\trace::unit_for_course($courseid), ['codigo' => $code, 'estado' => $status]);

    $replykey = \local_chatbot\service_error::reply_key($code);
    $reply = get_string($replykey, 'local_chatbot');

    // Fallas pasajeras: el widget ofrece reintentar con la misma pregunta. El mensaje de la
    // persona se quita del historial para que el reintento no lo deje dos veces ni el modelo
    // vea en el proximo turno una pregunta sin responder.
    $retryable = \local_chatbot\ux::on('retry') && in_array($code, [0, 408, 429, 500, 502, 503, 504], true);
    if ($retryable && !empty($messageid)) {
        try {
            get_file_storage()->delete_area_files(context_system::instance()->id, 'local_chatbot',
                'chat_attachment', (int) $messageid);
            $DB->delete_records('local_chatbot_messages', ['id' => (int) $messageid, 'userid' => $USER->id]);
        } catch (\Throwable $e) {
            debugging('local_chatbot: no se pudo retirar el mensaje fallido: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
    // 503 y 429 pasan solos con esperar. Todo lo demas lleva la ruta a soporte:
    // RU-PRIV-005 dice que una falla tecnica del asistente es justamente el caso
    // que si va a soporte humano, y decir solo "intenta de nuevo" deja a la
    // persona sin salida si la falla persiste.
    if ($replykey !== \local_chatbot\service_error::REPLY_BUSY
            && $replykey !== \local_chatbot\service_error::REPLY_RATELIMIT) {
        $support = \local_chatbot\assistant::support_reference();
        if ($support !== '') {
            $reply .= ' ' . get_string('error_support_route', 'local_chatbot', $support);
        }
    }
}

local_chatbot_ajax_respond([
    'reply' => $reply,
    'resources' => [],
    'interactionid' => 0,
    'retryable' => $retryable,
], $stream);
