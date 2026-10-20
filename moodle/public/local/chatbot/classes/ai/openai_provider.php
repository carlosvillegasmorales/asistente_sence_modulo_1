<?php
namespace local_chatbot\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Adapter for OpenAI's Chat Completions API. Also the base class for
 * azure_foundry_provider, which only overrides the endpoint URL and auth
 * header — the request/response shape is otherwise the same on both.
 */
class openai_provider implements ai_provider_interface {

    /** @var string Fast/cheap default, equivalent tier to Gemini's flash-lite. */
    const DEFAULT_MODEL = 'gpt-4o-mini';

    protected string $apikey;

    /**
     * @param string $apikey OpenAI (or Azure-compatible) API key.
     */
    public function __construct(string $apikey) {
        $this->apikey = $apikey;
    }

    /**
     * @see ai_provider_interface::generate_content()
     */
    public function generate_content(array $contents, int $timeout = 30, int $maxretries = 2,
            ?string $model = null, bool $usesearch = false): array {

        if (empty($this->apikey)) {
            return ['error' => ['code' => 0, 'message' => 'missing_api_key']];
        }

        $model = $model ?? static::DEFAULT_MODEL;
        $messages = $this->build_messages($contents);

        $payload = [
            'model' => $model,
            'max_tokens' => 4096,
            'messages' => $messages,
        ];

        $retrycount = 0;
        $result = null;
        $curlerr = '';

        while ($retrycount < $maxretries) {
            $ch = curl_init($this->build_url($model));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->build_headers());
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

            $rawresponse = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlerr = curl_error($ch);
            curl_close($ch);

            $result = json_decode((string) $rawresponse, true);

            $retryable = ($httpcode == 429 || $httpcode >= 500);
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
                debugging('local_chatbot ' . static::class . ': cURL error: ' . $curlerr, DEBUG_DEVELOPER);
            }
            return ['error' => ['code' => 0, 'message' => 'transport_error']];
        }

        if (isset($result['error'])) {
            return ['error' => ['code' => $result['error']['code'] ?? 0, 'message' => $result['error']['message'] ?? 'unknown_error']];
        }

        $text = $result['choices'][0]['message']['content'] ?? null;
        if ($text === null || $text === '') {
            return ['error' => ['code' => 0, 'message' => 'empty_response']];
        }

        // Reshaped to look exactly like a Gemini response so extract_text()/
        // extract_sources() work unmodified regardless of provider.
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    /**
     * Translates the canonical Gemini-shaped contents into Chat Completions
     * messages. Any 'model' role becomes 'assistant'; multimodal parts become
     * a content-parts array (image_url for images, file for PDFs).
     */
    protected function build_messages(array $contents): array {
        $messages = [];
        foreach ($contents as $entry) {
            $role = ($entry['role'] ?? 'user') === 'model' ? 'assistant' : 'user';
            $parts = $entry['parts'] ?? [];

            // A plain-text-only turn can stay a simple string; only build the
            // content-parts array form when there's an actual attachment.
            $hasattachment = false;
            foreach ($parts as $part) {
                if (isset($part['inlineData']['data'])) {
                    $hasattachment = true;
                    break;
                }
            }

            if (!$hasattachment) {
                $text = '';
                foreach ($parts as $part) {
                    $text .= $part['text'] ?? '';
                }
                if ($text !== '') {
                    $messages[] = ['role' => $role, 'content' => $text];
                }
                continue;
            }

            $blocks = [];
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $blocks[] = ['type' => 'text', 'text' => (string) $part['text']];
                } else if (isset($part['inlineData']['data'])) {
                    $mimetype = $part['inlineData']['mimeType'] ?? 'application/octet-stream';
                    $data = $part['inlineData']['data'];
                    if ($mimetype === 'application/pdf') {
                        $blocks[] = ['type' => 'file',
                            'file' => ['filename' => 'documento.pdf', 'file_data' => "data:{$mimetype};base64,{$data}"]];
                    } else {
                        $blocks[] = ['type' => 'image_url',
                            'image_url' => ['url' => "data:{$mimetype};base64,{$data}"]];
                    }
                }
            }
            if (!empty($blocks)) {
                $messages[] = ['role' => $role, 'content' => $blocks];
            }
        }
        return $messages;
    }

    /**
     * @param string $model Unused here (OpenAI picks the model from the JSON
     *        body, not the URL); azure_foundry_provider overrides this to
     *        build a per-deployment URL instead.
     */
    protected function build_url(string $model): string {
        return 'https://api.openai.com/v1/chat/completions';
    }

    /**
     * @return string[] cURL headers for the request.
     */
    protected function build_headers(): array {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apikey,
        ];
    }
}
