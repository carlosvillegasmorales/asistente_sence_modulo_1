<?php
namespace local_chatbot\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Catalogo de proveedores de IA y fabrica del adaptador activo.
 *
 * El resto del plugin llama a generate_content() sin saber si detras hay
 * Gemini, Claude, OpenAI o un despliegue de Azure AI Foundry. Esa
 * indiferencia es deliberada: el proveedor es una decision de contrato y de
 * region de procesamiento, no de arquitectura, y tiene que poder cambiarse
 * sin tocar codigo (ver RU-CONS-004, que obliga a declararle a la persona
 * quien procesa sus datos y donde).
 *
 * Lo que cada proveedor necesita —que ajuste guarda su clave, que modelo usa
 * por defecto, que campos extra exige— vive en catalog() como dato, no
 * repartido en condicionales. Antes estaba en tres switch y en cuatro bloques
 * de settings.php, y agregar un proveedor obligaba a acordarse de los siete
 * lugares. Ahora se agrega una entrada y la pantalla de configuracion, la
 * comprobacion de credenciales y la fabrica se enteran solas.
 */
class provider_factory {

    /** @var string Proveedor que se usa si el sitio todavia no eligio uno. */
    const DEFAULT_PROVIDER = 'gemini';

    private static ?ai_provider_interface $instance = null;

    /**
     * Que proveedores conoce el plugin y que necesita cada uno.
     *
     * Claves de cada entrada:
     * - name         Nombre comercial, tal como se le muestra a quien configura.
     * - vendor       Persona juridica que procesa los datos. Es el dato que pide
     *                RU-CONS-004 para la declaracion de consentimiento.
     * - keyfield     Ajuste donde vive la credencial.
     * - modelfield   Ajuste del modelo, opcional para quien configura.
     * - defaultmodel Modelo que usa el adaptador si nadie especifica otro.
     * - keysurl      Donde se obtiene una credencial.
     * - extra        Campos adicionales obligatorios, si el proveedor los exige.
     *
     * @return array[]
     */
    public static function catalog(): array {
        return [
            'gemini' => [
                'name' => 'Google Gemini',
                'vendor' => 'Google LLC',
                'keyfield' => 'gemini_api_key',
                'modelfield' => 'gemini_model',
                'defaultmodel' => \local_chatbot\gemini_client::DEFAULT_MODEL,
                'keysurl' => 'https://aistudio.google.com/apikey',
                'extra' => [],
            ],
            'anthropic' => [
                'name' => 'Claude (Anthropic)',
                'vendor' => 'Anthropic PBC',
                'keyfield' => 'anthropic_api_key',
                'modelfield' => 'anthropic_model',
                'defaultmodel' => anthropic_provider::DEFAULT_MODEL,
                'keysurl' => 'https://console.anthropic.com/settings/keys',
                'extra' => [],
            ],
            'openai' => [
                'name' => 'OpenAI',
                'vendor' => 'OpenAI, L.L.C.',
                'keyfield' => 'openai_api_key',
                'modelfield' => 'openai_model',
                'defaultmodel' => openai_provider::DEFAULT_MODEL,
                'keysurl' => 'https://platform.openai.com/api-keys',
                'extra' => [],
            ],
            'azure_foundry' => [
                'name' => 'Azure AI Foundry',
                'vendor' => 'Microsoft Corporation',
                'keyfield' => 'azure_api_key',
                'modelfield' => '',
                'defaultmodel' => '',
                'keysurl' => 'https://ai.azure.com/',
                // Azure no expone un endpoint comun: cada organizacion despliega
                // el suyo, asi que sin estos tres datos no hay a donde llamar.
                'extra' => [
                    'azure_endpoint' => ['param' => PARAM_URL, 'required' => true],
                    'azure_deployment' => ['param' => PARAM_RAW_TRIMMED, 'required' => true],
                    'azure_api_version' => ['param' => PARAM_RAW_TRIMMED, 'required' => false,
                        'default' => '2024-08-01-preview'],
                ],
            ],
        ];
    }

    /**
     * @param string $provider
     * @return bool Si el plugin conoce ese proveedor.
     */
    public static function is_known(string $provider): bool {
        return isset(self::catalog()[$provider]);
    }

    /**
     * @return string Proveedor activo del sitio, siempre uno conocido.
     */
    public static function get_active(): string {
        $provider = (string) get_config('local_chatbot', 'ai_provider');
        return self::is_known($provider) ? $provider : self::DEFAULT_PROVIDER;
    }

    /**
     * @return ai_provider_interface El adaptador del proveedor activo, cacheado
     *         para el resto de la peticion.
     */
    public static function get(): ai_provider_interface {
        if (self::$instance !== null) {
            return self::$instance;
        }
        self::$instance = self::for_provider(self::get_active());
        return self::$instance;
    }

    /**
     * Adaptador de un proveedor concreto, sea o no el activo.
     *
     * Existe para poder probar una credencial antes de dejar ese proveedor a
     * cargo de las conversaciones: obligar a activarlo primero convertiria
     * cada comprobacion en un riesgo para las personas que esten usando el
     * asistente en ese momento.
     *
     * @param string $provider Clave del catalogo.
     * @return ai_provider_interface
     */
    public static function for_provider(string $provider): ai_provider_interface {
        switch ($provider) {
            case 'anthropic':
                return new anthropic_provider((string) get_config('local_chatbot', 'anthropic_api_key'));

            case 'openai':
                return new openai_provider((string) get_config('local_chatbot', 'openai_api_key'));

            case 'azure_foundry':
                return new azure_foundry_provider(
                    (string) get_config('local_chatbot', 'azure_api_key'),
                    (string) get_config('local_chatbot', 'azure_endpoint'),
                    (string) get_config('local_chatbot', 'azure_deployment'),
                    (string) (get_config('local_chatbot', 'azure_api_version') ?: '2024-08-01-preview')
                );

            case 'gemini':
            default:
                return new gemini_provider();
        }
    }

    /**
     * @return string|null Modelo configurado para el proveedor activo, o null
     *         para que el adaptador use el suyo por defecto.
     */
    public static function get_configured_model(): ?string {
        return self::get_model_for(self::get_active());
    }

    /**
     * @param string $provider
     * @return string|null Modelo configurado para ese proveedor, o null.
     */
    public static function get_model_for(string $provider): ?string {
        $entry = self::catalog()[$provider] ?? null;
        if (!$entry || empty($entry['modelfield'])) {
            return null;
        }
        $model = trim((string) get_config('local_chatbot', $entry['modelfield']));
        return $model !== '' ? $model : null;
    }

    /**
     * @return bool Si el proveedor activo tiene lo que necesita para llamar.
     */
    public static function is_configured(): bool {
        return self::is_configured_for(self::get_active());
    }

    /**
     * @param string $provider
     * @return bool Si ese proveedor tiene credencial y los campos que exige.
     */
    public static function is_configured_for(string $provider): bool {
        $entry = self::catalog()[$provider] ?? null;
        if (!$entry) {
            return false;
        }

        if (trim((string) get_config('local_chatbot', $entry['keyfield'])) === '') {
            return false;
        }

        foreach ($entry['extra'] as $field => $spec) {
            if (!empty($spec['required'])
                    && trim((string) get_config('local_chatbot', $field)) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $provider
     * @return bool Si ese proveedor tiene una credencial guardada, aunque le
     *         falte algun campo obligatorio.
     */
    public static function has_key(string $provider): bool {
        $entry = self::catalog()[$provider] ?? null;
        return $entry && trim((string) get_config('local_chatbot', $entry['keyfield'])) !== '';
    }

    /**
     * Nombre del proveedor activo, para mostrarlo donde haga falta declararlo.
     *
     * @return string
     */
    public static function active_vendor(): string {
        $entry = self::catalog()[self::get_active()] ?? null;
        return $entry ? $entry['vendor'] : '';
    }
}
