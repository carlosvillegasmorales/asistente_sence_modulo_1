<?php
namespace local_chatbot\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Adapter for an Azure AI Foundry / Azure OpenAI deployment. Reuses
 * openai_provider's request/response shape (Azure's chat-completions
 * endpoint mirrors OpenAI's) and only overrides the URL (Azure's
 * per-deployment path + api-version query string) and the auth header
 * (api-key instead of a Bearer token).
 *
 * PDF ('file' content parts) support depends on the specific model/API
 * version behind the deployment — unlike native OpenAI, Azure doesn't
 * uniformly guarantee it across every deployment, so treat that as
 * best-effort here (see settings.php's description for this provider).
 */
class azure_foundry_provider extends openai_provider {

    private string $endpoint;
    private string $deployment;
    private string $apiversion;

    /**
     * @param string $apikey Azure resource API key.
     * @param string $endpoint Resource base URL, e.g. https://my-resource.openai.azure.com
     * @param string $deployment Deployment name configured in Azure AI Foundry.
     * @param string $apiversion API version query parameter, e.g. 2024-08-01-preview.
     */
    public function __construct(string $apikey, string $endpoint, string $deployment, string $apiversion) {
        parent::__construct($apikey);
        $this->endpoint = rtrim($endpoint, '/');
        $this->deployment = $deployment;
        $this->apiversion = $apiversion;
    }

    /**
     * @see openai_provider::build_url()
     */
    protected function build_url(string $model): string {
        // On Azure the deployment name IS the model selector; the "model"
        // field in the JSON body is not what picks the model.
        return $this->endpoint . '/openai/deployments/' . rawurlencode($this->deployment)
            . '/chat/completions?api-version=' . rawurlencode($this->apiversion);
    }

    /**
     * @see openai_provider::build_headers()
     */
    protected function build_headers(): array {
        return [
            'Content-Type: application/json',
            'api-key: ' . $this->apikey,
        ];
    }
}
