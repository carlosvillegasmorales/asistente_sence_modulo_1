<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Traduce el código de error del proveedor de IA al mensaje que ve la persona.
 *
 * Todo lo que no fuera 503 o 429 se le decía como "no se pudo conectar, revisa
 * tu conexión". Con un 402 (crédito agotado) o un 401/403 (credencial inválida)
 * eso manda a la persona a revisar un internet que funciona, cuando el problema
 * es del servicio y no lo puede resolver ella: lo que corresponde es decirle que
 * es una incidencia técnica y llevarla a soporte.
 */
class service_error {

    /** @var string Cadena de idioma para cada desenlace. */
    const REPLY_BUSY = 'error_busy';
    const REPLY_RATELIMIT = 'error_ratelimit';
    const REPLY_SERVICE = 'error_service';
    const REPLY_CONNECTION = 'error_connection';

    /**
     * Cadena de idioma que corresponde a un código de error. Función pura.
     *
     * @param int $code Código HTTP del proveedor; 0 si no hubo respuesta.
     * @return string Una de las constantes REPLY_*.
     */
    public static function reply_key(int $code): string {
        if ($code === 503) {
            return self::REPLY_BUSY;
        }
        if ($code === 429) {
            return self::REPLY_RATELIMIT;
        }
        if (in_array($code, [401, 402, 403], true)) {
            return self::REPLY_SERVICE;
        }
        return self::REPLY_CONNECTION;
    }

    /**
     * Si la persona no puede hacer nada para resolverlo y hay que llevarla a
     * soporte. Un error de conexión también termina en soporte si persiste, pero
     * ahí el consejo de reintentar sigue siendo razonable; aquí no.
     *
     * @param int $code
     * @return bool
     */
    public static function needs_support(int $code): bool {
        return self::reply_key($code) === self::REPLY_SERVICE;
    }
}
