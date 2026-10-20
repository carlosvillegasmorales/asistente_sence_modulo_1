<?php
/**
 * Verifica qué le dice el asistente a la persona cuando el proveedor de IA falla.
 *
 * Un 402 (crédito agotado) o un 401/403 (credencial inválida) se reportaban como
 * "no se pudo conectar, revisa tu conexión", que manda a la persona a revisar un
 * internet que funciona. Aquí se comprueba que cada código lleve al mensaje que
 * le sirve: esperar cuando pasa solo, y avisar que es un problema del servicio
 * cuando no lo puede resolver ella (RU-PRIV-005).
 *
 * Uso:  php cli/verificar_errores_servicio.php
 * Sale con código 1 ante cualquier caso fallido, para encadenarlo a CI.
 */

define('MOODLE_INTERNAL', true);

require_once(__DIR__ . '/../classes/service_error.php');

use local_chatbot\service_error;

$fallidos = 0;
$total = 0;

/**
 * @param bool $condicion
 * @param string $descripcion
 * @return void
 */
function local_chatbot_verificar(bool $condicion, string $descripcion): void {
    global $fallidos, $total;
    $total++;
    if (!$condicion) {
        echo "  FALLA  {$descripcion}\n";
        $fallidos++;
    }
}

$casos = [
    // Pasan solos con esperar: no se manda a soporte.
    [503, service_error::REPLY_BUSY, false, 'servicio saturado'],
    [429, service_error::REPLY_RATELIMIT, false, 'límite de consultas'],
    // La persona no puede resolverlos: es del servicio.
    [402, service_error::REPLY_SERVICE, true, 'crédito agotado en el proveedor'],
    [401, service_error::REPLY_SERVICE, true, 'credencial inválida'],
    [403, service_error::REPLY_SERVICE, true, 'credencial sin permiso'],
    // Sin respuesta o fallo genérico: el mensaje de siempre.
    [0, service_error::REPLY_CONNECTION, false, 'sin respuesta del proveedor'],
    [500, service_error::REPLY_CONNECTION, false, 'error interno del proveedor'],
    [404, service_error::REPLY_CONNECTION, false, 'modelo no encontrado'],
];

foreach ($casos as [$codigo, $esperado, $soporte, $descripcion]) {
    local_chatbot_verificar(service_error::reply_key($codigo) === $esperado,
        "código {$codigo} ({$descripcion}) debería usar '{$esperado}' y usa '"
        . service_error::reply_key($codigo) . "'");
    local_chatbot_verificar(service_error::needs_support($codigo) === $soporte,
        "código {$codigo} ({$descripcion}): needs_support debería ser " . var_export($soporte, true));
}

// El caso que originó esto: un 402 no puede decirle a nadie que revise su conexión.
local_chatbot_verificar(service_error::reply_key(402) !== service_error::REPLY_CONNECTION,
    'un 402 no se puede reportar como problema de conexión');

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);

if ($fallidos === 0) {
    echo "Errores del servicio conformes a RU-PRIV-005.\n";
    echo "  Un crédito agotado o una credencial inválida se explican como problema\n";
    echo "  del servicio, no de la conexión de quien escribe.\n";
    exit(0);
}

echo "Errores del servicio NO conformes: revisar classes/service_error.php.\n";
exit(1);
