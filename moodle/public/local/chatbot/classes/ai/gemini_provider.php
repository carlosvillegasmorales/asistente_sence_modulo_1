<?php
namespace local_chatbot\ai;

use local_chatbot\gemini_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Adapts the plugin's original gemini_client (kept as-is: its "contents"
 * format IS the canonical format every provider now speaks, so wrapping it
 * is a zero-behaviour-change delegation, not a rewrite).
 */
class gemini_provider implements ai_provider_interface {

    private ?string $apikey = null;

    /**
     * @param string|null $apikey Gemini API key opcional para testing o instanciacion directa.
     */
    public function __construct(?string $apikey = null) {
        $this->apikey = $apikey;
    }

    /**
     * @see ai_provider_interface::generate_content()
     */
    public function generate_content(array $contents, int $timeout = 30, int $maxretries = 2,
            ?string $model = null, bool $usesearch = false): array {
        $model = $model ?? gemini_client::DEFAULT_MODEL;
        return gemini_client::generate_content($contents, $timeout, $maxretries, $model, $usesearch, $this->apikey);
    }
}

