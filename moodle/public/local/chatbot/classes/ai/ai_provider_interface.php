<?php
namespace local_chatbot\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Common contract every AI provider adapter implements, so the rest of the
 * plugin (ajax.php, pdp, project, manage_kb.php) never has to know
 * which provider is actually configured.
 *
 * The "contents" format is the one Gemini's own API already uses (kept as
 * the plugin's internal canonical format since it predates this interface
 * and every call site already speaks it):
 *   [['role' => 'user'|'model', 'parts' => [
 *       ['text' => string],
 *       ['inlineData' => ['mimeType' => string, 'data' => base64 string]],
 *   ]], ...]
 *
 * generate_content() must always return an array shaped like Gemini's own
 * response, regardless of provider, so gemini_client::extract_text()/
 * extract_sources() keep working unchanged for every provider:
 *   ['candidates' => [['content' => ['parts' => [['text' => string]]],
 *       'groundingMetadata' => [...] // only ever populated by Gemini
 *   ]]]
 * or, on failure: ['error' => ['code' => int, 'message' => string]].
 */
interface ai_provider_interface {
    /**
     * @param array $contents Canonical contents, see class docblock.
     * @param int $timeout cURL timeout in seconds.
     * @param int $maxretries Attempts before giving up on a retryable error.
     * @param string|null $model Provider-specific model name; null uses the provider's own default.
     * @param bool $usesearch Web-search grounding. Only Gemini implements this; other
     *        providers accept and silently ignore it so callers don't need per-provider branching.
     * @return array Gemini-shaped response, see class docblock.
     */
    public function generate_content(array $contents, int $timeout = 30, int $maxretries = 2,
        ?string $model = null, bool $usesearch = false): array;
}
