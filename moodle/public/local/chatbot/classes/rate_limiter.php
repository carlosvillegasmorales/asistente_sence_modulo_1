<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Control de cuotas y rate limiting local (ERTF v3.0, RF-LIM-01).
 *
 * Utiliza la Moodle Cache API (MUC) con ventana deslizante de 1 hora
 * para no saturar la base de datos con consultas de agregación en cada
 * mensaje del asistente.
 */
class rate_limiter {

    /** @var int Límite por defecto de mensajes por usuario por hora. */
    const DEFAULT_USER_LIMIT = 20;

    /** @var int Límite por defecto de mensajes globales del sitio por hora. */
    const DEFAULT_SITE_LIMIT = 1000;

    /**
     * Obtiene el almacén de caché de rate limiting.
     *
     * @return \cache|\cache_application
     */
    protected static function get_cache() {
        try {
            return \cache::make('local_chatbot', 'ratelimit');
        } catch (\Exception $e) {
            // Fallback en caso de que las cachés no hayan sido purgadas tras upgrade.
            return null;
        }
    }

    /**
     * Verifica si el usuario y el sitio están dentro de los límites y, si es así,
     * incrementa el contador.
     *
     * @param int $userid ID del usuario en Moodle.
     * @param int|null $userlimit Límite horario por usuario (opcional).
     * @param int|null $sitelimit Límite horario global del sitio (opcional).
     * @return array Con claves 'allowed' (bool), 'reason' (string|null), 'current' (int), 'limit' (int).
     */
    public static function check_and_increment(int $userid, ?int $userlimit = null, ?int $sitelimit = null): array {
        $cache = self::get_cache();
        if (!$cache) {
            // Si el subsistema de caché no está disponible, no bloquear la interacción.
            return ['allowed' => true, 'reason' => null, 'current' => 0, 'limit' => 0];
        }

        $userlimit = $userlimit ?? (int) get_config('local_chatbot', 'user_rate_limit') ?: self::DEFAULT_USER_LIMIT;
        $sitelimit = $sitelimit ?? (int) get_config('local_chatbot', 'site_rate_limit') ?: self::DEFAULT_SITE_LIMIT;

        $currenthour = (int) floor(time() / 3600);
        $userkey = "u_{$userid}_{$currenthour}";
        $sitekey = "site_{$currenthour}";

        $currentusercount = (int) $cache->get($userkey);
        $currentsitecount = (int) $cache->get($sitekey);

        // 1. Verificar límite por usuario.
        if ($userlimit > 0 && $currentusercount >= $userlimit) {
            return [
                'allowed' => false,
                'reason' => 'user_limit_exceeded',
                'current' => $currentusercount,
                'limit' => $userlimit,
            ];
        }

        // 2. Verificar límite global del sitio.
        if ($sitelimit > 0 && $currentsitecount >= $sitelimit) {
            return [
                'allowed' => false,
                'reason' => 'site_limit_exceeded',
                'current' => $currentsitecount,
                'limit' => $sitelimit,
            ];
        }

        // Incrementar contadores.
        $cache->set($userkey, $currentusercount + 1);
        $cache->set($sitekey, $currentsitecount + 1);

        return [
            'allowed' => true,
            'reason' => null,
            'current' => $currentusercount + 1,
            'limit' => $userlimit,
        ];
    }

    /**
     * Resetea el contador de un usuario (para testing o soporte administrativo).
     *
     * @param int $userid
     * @return void
     */
    public static function reset_user(int $userid): void {
        $cache = self::get_cache();
        if ($cache) {
            $currenthour = (int) floor(time() / 3600);
            $cache->delete("u_{$userid}_{$currenthour}");
        }
    }
}
