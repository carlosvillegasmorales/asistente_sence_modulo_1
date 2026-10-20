<?php
/**
 * Endpoint AJAX para la administración de API Keys y Modelos de IA en local_chatbot.
 *
 * Exclusivo para administradores del sitio (is_siteadmin() o moodle/site:config).
 * Permite consultar el estado, probar credenciales y guardar la configuración
 * directamente desde el widget del asistente.
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

header('Content-Type: application/json; charset=utf-8');

// Verificación estricta de sesión y privilegios.
try {
    require_login();
    require_sesskey();
} catch (\Throwable $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sesión expirada o no autorizada.']);
    exit;
}

$context = context_system::instance();
if (!is_siteadmin() && !has_capability('moodle/site:config', $context)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => get_string('nopermissions', 'error', 'configurar IA')]);
    exit;
}

$action = required_param('action', PARAM_ALPHA);

/**
 * Enmascara una clave para no exponer secretos en el frontend.
 *
 * @param string $key
 * @return string
 */
function local_chatbot_mask_key(string $key): string {
    $trimmed = trim($key);
    $len = strlen($trimmed);
    if ($len === 0) {
        return '';
    }
    if ($len <= 8) {
        return str_repeat('•', 8);
    }
    $start = substr($trimmed, 0, min(4, (int) ($len / 4)));
    $end = substr($trimmed, -min(4, (int) ($len / 4)));
    return $start . '••••••••••••••••' . $end;
}

try {
    switch ($action) {
        case 'get':
            $active = \local_chatbot\ai\provider_factory::get_active();
            $catalog = \local_chatbot\ai\provider_factory::catalog();

            $providers = [];
            foreach (['gemini', 'anthropic', 'openai'] as $p) {
                $meta = $catalog[$p] ?? null;
                if (!$meta) {
                    continue;
                }
                $rawkey = (string) get_config('local_chatbot', $meta['keyfield']);
                $model = (string) get_config('local_chatbot', $meta['modelfield']);

                $providers[$p] = [
                    'name' => $meta['name'],
                    'vendor' => $meta['vendor'],
                    'has_key' => ($rawkey !== ''),
                    'key_masked' => local_chatbot_mask_key($rawkey),
                    'model' => $model,
                    'default_model' => $meta['defaultmodel'],
                    'keys_url' => $meta['keysurl'],
                ];
            }

            echo json_encode([
                'success' => true,
                'active_provider' => $active,
                'providers' => $providers,
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'save':
            $activeprovider = optional_param('active_provider', '', PARAM_ALPHAEXT);
            if ($activeprovider !== '' && \local_chatbot\ai\provider_factory::is_known($activeprovider)) {
                set_config('ai_provider', $activeprovider, 'local_chatbot');
            }

            $catalog = \local_chatbot\ai\provider_factory::catalog();

            foreach (['gemini', 'anthropic', 'openai'] as $p) {
                $meta = $catalog[$p] ?? null;
                if (!$meta) {
                    continue;
                }

                // Guardar API Key si se envió un valor nuevo (no enmascarado).
                $keyparam = optional_param($p . '_api_key', null, PARAM_RAW);
                if ($keyparam !== null) {
                    $keyparam = trim($keyparam);
                    if ($keyparam !== '' && strpos($keyparam, '•') === false) {
                        set_config($meta['keyfield'], $keyparam, 'local_chatbot');
                    }
                }

                // Guardar modelo elegido o personalizado.
                $modelparam = optional_param($p . '_model', null, PARAM_RAW_TRIMMED);
                if ($modelparam !== null) {
                    set_config($meta['modelfield'], $modelparam, 'local_chatbot');
                }
            }

            echo json_encode([
                'success' => true,
                'message' => get_string('admin_save_success', 'local_chatbot'),
                'active_provider' => \local_chatbot\ai\provider_factory::get_active(),
            ], JSON_UNESCAPED_UNICODE);
            exit;

        case 'test':
            $provider = required_param('provider', PARAM_ALPHAEXT);
            if (!in_array($provider, ['gemini', 'anthropic', 'openai'], true)) {
                throw new moodle_exception('invalidprovider', 'local_chatbot');
            }

            $catalog = \local_chatbot\ai\provider_factory::catalog();
            $meta = $catalog[$provider];

            // Si vino una clave en el formulario y no es la máscara, usar esa; sino la guardada.
            $apikey = optional_param('api_key', '', PARAM_RAW);
            $apikey = trim($apikey);
            if ($apikey === '' || strpos($apikey, '•') !== false) {
                $apikey = (string) get_config('local_chatbot', $meta['keyfield']);
            }

            if (empty($apikey)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'No hay API Key configurada para este proveedor. Ingrésala primero.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $model = optional_param('model', '', PARAM_RAW_TRIMMED);
            if ($model === '') {
                $model = (string) get_config('local_chatbot', $meta['modelfield']);
            }
            if ($model === '') {
                $model = $meta['defaultmodel'];
            }

            $adapter = null;
            if ($provider === 'gemini') {
                $adapter = new \local_chatbot\ai\gemini_provider($apikey);
            } else if ($provider === 'anthropic') {
                $adapter = new \local_chatbot\ai\anthropic_provider($apikey);
            } else if ($provider === 'openai') {
                $adapter = new \local_chatbot\ai\openai_provider($apikey);
            }

            $testprompt = [
                ['role' => 'user', 'parts' => [['text' => 'Responde exactamente y unicamente la palabra: OK']]],
            ];

            $starttime = microtime(true);
            $res = $adapter->generate_content($testprompt, 20, 1, $model);
            $latencyms = (int) round((microtime(true) - $starttime) * 1000);

            $replytext = \local_chatbot\gemini_client::extract_text($res);

            if ($replytext !== null) {
                echo json_encode([
                    'success' => true,
                    'latency' => $latencyms,
                    'model' => $model,
                    'reply' => trim($replytext),
                ], JSON_UNESCAPED_UNICODE);
            } else {
                $errmsg = 'Error desconocido al invocar la API.';
                if (isset($res['error']['message'])) {
                    $errmsg = (string) $res['error']['message'];
                } else if (isset($res['error']) && is_string($res['error'])) {
                    $errmsg = $res['error'];
                } else if (isset($res['error']) && is_array($res['error'])) {
                    $errmsg = json_encode($res['error'], JSON_UNESCAPED_UNICODE);
                }

                echo json_encode([
                    'success' => false,
                    'latency' => $latencyms,
                    'model' => $model,
                    'error' => $errmsg,
                ], JSON_UNESCAPED_UNICODE);
            }
            exit;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Acción no válida.']);
            exit;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
