<?php
namespace local_chatbot\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Adapter for Anthropic's Messages API (Claude). Translates the plugin's
 * canonical Gemini-shaped "contents" into Claude's request shape and
 * translates the reply back, so every other file in the plugin keeps
 * calling generate_content()/extract_text() exactly as before.
 */
class anthropic_provider implements ai_provider_interface {

    /** @var string Fast/cheap default, equivalent tier to Gemini's flash-lite. */
    const DEFAULT_MODEL = 'claude-haiku-4-5';

    /** @var string Anthropic Messages API version header value. */
    const API_VERSION = '2023-06-01';

    private string $apikey;

    /**
     * @param string $apikey Anthropic API key.
     */
    public function __construct(string $apikey) {
        $this->apikey = $apikey;
    }

    /**
     * @see ai_provider_interface::generate_content()
     */
    public function generate_content(array $contents, int $timeout = 30, int $maxretries = 2,
            ?string $model = null, bool $usesearch = false, ?float $temperature = null): array {

        if (empty($this->apikey)) {
            return ['error' => ['code' => 0, 'message' => 'missing_api_key']];
        }

        $messages = [];
        foreach ($contents as $index => $entry) {
            $role = ($entry['role'] ?? 'user') === 'model' ? 'assistant' : 'user';
            $blocks = [];
            foreach ($entry['parts'] ?? [] as $part) {
                if (isset($part['text'])) {
                    $blocks[] = ['type' => 'text', 'text' => (string) $part['text']];
                } else if (isset($part['inlineData']['data'])) {
                    $mimetype = $part['inlineData']['mimeType'] ?? 'application/octet-stream';
                    $data = $part['inlineData']['data'];
                    if ($mimetype === 'application/pdf') {
                        $blocks[] = ['type' => 'document',
                            'source' => ['type' => 'base64', 'media_type' => $mimetype, 'data' => $data]];
                    } else {
                        // Anything else attached (jpg/png) is treated as an image;
                        // Claude only accepts image/* media types on an image block.
                        $blocks[] = ['type' => 'image',
                            'source' => ['type' => 'base64', 'media_type' => $mimetype, 'data' => $data]];
                    }
                }
            }

            // Prompt caching: the very first turn (built by ajax.php) is the
            // system prompt plus the entire knowledge base — identical on every
            // message of a conversation, and by far the largest block sent
            // (unlike Gemini/OpenAI, Claude only caches a block you explicitly
            // mark). Anthropic bills a cache write once at ~1.25x, then every
            // later message that repeats this same block pays only ~10% of its
            // normal input price for it instead of the full price every time.
            if ($index === 0 && !empty($blocks)) {
                $lastblockidx = count($blocks) - 1;
                $blocks[$lastblockidx]['cache_control'] = ['type' => 'ephemeral'];
            }

            if (!empty($blocks)) {
                $messages[] = ['role' => $role, 'content' => $blocks];
            }
        }

        $payload = [
            'model' => $model ?? self::DEFAULT_MODEL,
            'max_tokens' => 4096,
            'messages' => $messages,
        ];
        if ($temperature !== null) {
            $payload['temperature'] = $temperature;
        }

        $retrycount = 0;
        $result = null;
        $curlerr = '';

        while ($retrycount < $maxretries) {
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apikey,
                'anthropic-version: ' . self::API_VERSION,
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

            $rawresponse = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlerr = curl_error($ch);
            curl_close($ch);

            $result = json_decode((string) $rawresponse, true);

            // 429 (rate limit) and 529 (Anthropic-specific "overloaded") are retryable,
            // same policy as gemini_client's 429/503.
            $retryable = ($httpcode == 429 || $httpcode == 529 || $httpcode >= 500);
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
                debugging('local_chatbot anthropic_provider: cURL error: ' . $curlerr, DEBUG_DEVELOPER);
            }
            return ['error' => ['code' => 0, 'message' => 'transport_error']];
        }

        if (isset($result['error'])) {
            return ['error' => ['code' => $result['error']['type'] ?? 0, 'message' => $result['error']['message'] ?? 'unknown_error']];
        }

        if (($result['stop_reason'] ?? '') === 'refusal') {
            return ['error' => ['code' => 'refusal', 'message' => 'La IA rechazó responder a este mensaje.']];
        }

        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        if ($text === '') {
            return ['error' => ['code' => 0, 'message' => 'empty_response']];
        }

        // Reshaped to look exactly like a Gemini response so extract_text()/
        // extract_sources() work unmodified regardless of provider.
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }
}
