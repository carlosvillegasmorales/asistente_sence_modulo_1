<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Thin wrapper around Google's Gemini generateContent REST endpoint.
 *
 * Centralizes the HTTP call, retry-on-503/429 logic and model name so
 * ajax.php, ajax_summary.php and manage_kb.php don't each keep their own
 * (previously drifting) copy of this logic.
 */
class gemini_client {

    /** @var string Default model used across the plugin unless a caller overrides it. */
    const DEFAULT_MODEL = 'gemini-3.5-flash-lite';

    /**
     * Calls the Gemini generateContent endpoint.
     *
     * @param array $contents The "contents" array as expected by the Gemini API.
     * @param int $timeout cURL timeout in seconds.
     * @param int $maxretries Number of attempts before giving up on 429/503.
     * @param string $model Model name to target.
     * @param bool $usesearch Adds Gemini's built-in Google Search grounding tool,
     *        so the model can pull current information instead of relying only
     *        on training data or the uploaded knowledge base. Inspired by
     *        OpenMAIC's (open.maic.chat) "web search for up-to-date info".
     * @return array Decoded JSON response (or a synthetic ['error' => [...]] on transport failure).
     */
    public static function generate_content(array $contents, int $timeout = 30, int $maxretries = 2,
            string $model = self::DEFAULT_MODEL, bool $usesearch = false, ?string $apikey = null,
            ?float $temperature = null): array {

        if (empty($apikey)) {
            $apikey = (string) get_config('local_chatbot', 'gemini_api_key');
        }
        if (empty($apikey)) {
            return ['error' => ['code' => 0, 'message' => 'missing_api_key']];
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model
            . ':generateContent?key=' . $apikey;

        $payloadarr = ['contents' => $contents];
        if ($temperature !== null) {
            $payloadarr['generationConfig'] = ['temperature' => $temperature];
        }
        if ($usesearch) {
            $payloadarr['tools'] = [['google_search' => new \stdClass()]];
        }
        $payload = json_encode($payloadarr);

        $retrycount = 0;
        $result = null;
        $rawresponse = false;
        $curlerr = '';

        while ($retrycount < $maxretries) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            // Certificate verification must stay on: this call carries the tenant's API key
            // and student/course data, disabling it would allow a MITM to intercept both.
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

            $rawresponse = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlerr = curl_error($ch);
            curl_close($ch);

            $result = json_decode((string) $rawresponse, true);

            $retryable = ($httpcode == 503 || $httpcode == 429
                || (isset($result['error']['code']) && in_array($result['error']['code'], [503, 429])));

            if ($retryable) {
                $retrycount++;
                if ($retrycount < $maxretries) {
                    sleep(1);
                    continue;
                }
            }
            break;
        }

        if (empty($result)) {
            if ($curlerr !== '') {
                debugging('local_chatbot gemini_client: cURL error: ' . $curlerr, DEBUG_DEVELOPER);
            }
            return ['error' => ['code' => 0, 'message' => 'transport_error']];
        }

        return $result;
    }

    /**
     * Llamada en vivo (streamGenerateContent con SSE): entrega el texto a medida que llega.
     *
     * $ondelta recibe el texto acumulado cada vez que llega un trozo; si devuelve false, la
     * descarga se corta (lo usa ajax.php cuando una frase activaria un guardrail). Ante 503 o
     * 429 antes de recibir texto se reintenta una vez, como en generate_content().
     *
     * @param array $contents Formato "contents" de Gemini.
     * @param int $timeout Segundos.
     * @param string $model
     * @param callable $ondelta function(string $acumulado): bool
     * @param string|null $apikey
     * @return array Mismo formato que generate_content(), mas 'aborted' => true si se corto.
     */
    public static function stream_content(array $contents, int $timeout, string $model, callable $ondelta,
            ?string $apikey = null): array {
        if (empty($apikey)) {
            $apikey = (string) get_config('local_chatbot', 'gemini_api_key');
        }
        if (empty($apikey)) {
            return ['error' => ['code' => 0, 'message' => 'missing_api_key']];
        }
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model
            . ':streamGenerateContent?alt=sse&key=' . $apikey;
        $payload = json_encode(['contents' => $contents]);

        for ($intento = 1; $intento <= 2; $intento++) {
            $buffer = '';
            $cuerpo = '';
            $texto = '';
            $uso = null;
            $fin = null;
            $version = null;
            $cortado = false;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $trozo) use (&$buffer, &$cuerpo, &$texto, &$uso,
                    &$fin, &$version, &$cortado, $ondelta) {
                if ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
                    // Respuesta de error: se junta para leerla al final.
                    if (strlen($cuerpo) < 65536) {
                        $cuerpo .= $trozo;
                    }
                    return strlen($trozo);
                }
                $buffer .= $trozo;
                foreach (stream::parse_sse($buffer) as $evento) {
                    $uso = $evento['usageMetadata'] ?? $uso;
                    $version = $evento['modelVersion'] ?? $version;
                    $fin = $evento['candidates'][0]['finishReason'] ?? $fin;
                    $parte = '';
                    foreach ($evento['candidates'][0]['content']['parts'] ?? [] as $p) {
                        $parte .= (string) ($p['text'] ?? '');
                    }
                    if ($parte !== '') {
                        $texto .= $parte;
                        if ($ondelta($texto) === false) {
                            $cortado = true;
                            return 0;
                        }
                    }
                }
                return strlen($trozo);
            });
            curl_exec($ch);
            $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($cortado) {
                return ['aborted' => true, 'candidates' => [['content' => ['parts' => [['text' => $texto]]]]],
                    'usageMetadata' => $uso, 'modelVersion' => $version];
            }
            if ($codigo === 200 && $error !== '') {
                // Se corto a mitad (tiempo de espera o red): no se entrega una respuesta trunca como completa.
                debugging('local_chatbot gemini_client: stream interrumpido: ' . $error, DEBUG_DEVELOPER);
                return ['error' => ['code' => 504, 'message' => 'stream_interrupted']];
            }
            if ($codigo === 200 && $texto !== '') {
                return ['candidates' => [['content' => ['parts' => [['text' => $texto]]], 'finishReason' => $fin]],
                    'usageMetadata' => $uso, 'modelVersion' => $version];
            }
            if ($codigo === 200) {
                // Termino sin texto (p. ej. RECITATION): mismo efecto que una respuesta vacia.
                return ['candidates' => [['finishReason' => $fin]], 'usageMetadata' => $uso, 'modelVersion' => $version];
            }
            if (($codigo === 503 || $codigo === 429) && $texto === '' && $intento < 2) {
                sleep(1);
                continue;
            }
            $decodificado = json_decode($cuerpo, true);
            if (is_array($decodificado) && isset($decodificado['error'])) {
                return $decodificado;
            }
            if ($error !== '') {
                debugging('local_chatbot gemini_client: cURL error (stream): ' . $error, DEBUG_DEVELOPER);
            }
            return ['error' => ['code' => $codigo === 200 ? 0 : $codigo, 'message' => 'transport_error']];
        }
        return ['error' => ['code' => 0, 'message' => 'transport_error']];
    }

    /**
     * Convenience accessor for the plain text of the first candidate, if any.
     *
     * @param array $result Result as returned by generate_content().
     * @return string|null
     */
    public static function extract_text(array $result): ?string {
        return $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    /**
     * Extracts the list of web sources Gemini used when search grounding was
     * enabled, so the UI can show "fuentes consultadas" the same way OpenMAIC
     * surfaces citations for its web-search feature.
     *
     * @param array $result Result as returned by generate_content().
     * @return array List of ['title' => string, 'uri' => string].
     */
    public static function extract_sources(array $result): array {
        $chunks = $result['candidates'][0]['groundingMetadata']['groundingChunks'] ?? [];
        $sources = [];
        foreach ($chunks as $chunk) {
            if (!empty($chunk['web']['uri'])) {
                $sources[] = [
                    'title' => $chunk['web']['title'] ?? $chunk['web']['uri'],
                    'uri' => $chunk['web']['uri'],
                ];
            }
        }
        return $sources;
    }
}
