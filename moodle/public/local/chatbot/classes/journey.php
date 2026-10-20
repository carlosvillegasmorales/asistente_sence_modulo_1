<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Ruta del Modulo 1 desde el punto de vista de la persona: en que pantalla esta y que sigue.
 *
 * Antes el widget decia "estas en X" con X = el primer paso sin completar. Pero un paso
 * (p. ej. el PDP) cuenta como completo apenas hay un plan validado, y la persona sigue
 * trabajando ahi: el asistente la daba por pasada al Proyecto y se perdia. Aqui la pantalla
 * abierta manda para decir "donde estas", y lo pendiente se dice aparte como "lo que sigue".
 *
 * Hay dos rutas. La de 5 pasos es la original. La de 6 etapas es la de los ajustes del cliente
 * del 08-10-2026 («Ajustes al Entorno de Aprendizaje del Modulo 1»): Perfil, Diagnostico, Ruta
 * Formativa Base, Proyecto, PDP y Apoyos (opcional) y Cierre. Las funciones reciben la lista de
 * pasos y, sin ella, trabajan con la de 5 como siempre.
 *
 * Todo es logica pura: recibe el estado ya calculado y no consulta la base.
 */
class journey {

    /** @var string[] Ruta original de 5 pasos. */
    const ROUTE5 = ['perfil', 'diagnostico', 'pdp', 'proyecto', 'cierre'];

    /** @var string[] Ruta de 6 etapas de los ajustes del cliente del 08-10-2026. */
    const ROUTE6 = ['perfil', 'diagnostico', 'ruta', 'proyecto', 'pdp', 'cierre'];

    /** @var string[] Pasos opcionales de la ruta de 6: no bloquean ni cuentan para avanzar. */
    const OPTIONAL6 = ['pdp'];

    /** @var string[] Lo que necesita un paso opcional para abrirse (el PDP propone metas desde el diagnostico). */
    const REQUIRES = ['pdp' => 'diagnostico'];

    /** @var string[] Pantalla del plugin (archivo) => paso. La Ruta Formativa Base no tiene pantalla: vive en el curso. */
    const PAGES = [
        'index.php' => 'perfil', 'diagnostico.php' => 'diagnostico', 'pdp.php' => 'pdp',
        'proyecto.php' => 'proyecto', 'cierre.php' => 'cierre',
    ];

    /** @var string[] Nombre de cada paso para el contexto del modelo (el que ve la persona va en lang). */
    const NAMES = [
        'perfil' => 'Perfil y consentimiento', 'diagnostico' => 'Diagnóstico', 'ruta' => 'Ruta Formativa Base',
        'proyecto' => 'Proyecto integrador', 'pdp' => 'Plan Personal de Desarrollo', 'cierre' => 'Cierre',
    ];

    /**
     * @var string[] Nombres de las 6 etapas tal como los escribe el documento del cliente (08-10-2026):
     * «esta estructura debe ser la misma que aparece en la viñeta Tu ruta del módulo 1». Los mismos van
     * en lang (stage6_*) para la pantalla, así el Tutor nombra las etapas igual que la barra.
     */
    const NAMES6 = [
        'perfil' => 'Bienvenida y Perfilamiento', 'diagnostico' => 'Diagnóstico Autoaplicado',
        'ruta' => 'Ruta Formativa Base', 'proyecto' => 'Proyecto Integrador', 'pdp' => 'PDP y Apoyos Adaptativos',
        'cierre' => 'Seguimiento y Cierre',
    ];

    /**
     * Nombre de un paso para el contexto del modelo, segun la ruta vigente. Funcion pura.
     *
     * @param string $key Clave del paso.
     * @param string[] $keys Ruta vigente.
     * @return string
     */
    public static function step_name(string $key, array $keys): string {
        $nombres = in_array('ruta', $keys, true) ? self::NAMES6 : self::NAMES;
        return $nombres[$key] ?? $key;
    }

    /**
     * La ruta vigente. Funcion pura.
     *
     * @param bool $six Si rige la ruta de 6 etapas.
     * @return string[]
     */
    public static function route_keys(bool $six): array {
        return $six ? self::ROUTE6 : self::ROUTE5;
    }

    /**
     * Posiciones (1 a N) de los pasos opcionales de una ruta. Funcion pura.
     *
     * @param string[] $keys
     * @return int[]
     */
    public static function optional_positions(array $keys): array {
        if (!in_array('ruta', $keys, true)) {
            return [];
        }
        $pos = [];
        foreach (self::OPTIONAL6 as $clave) {
            $i = array_search($clave, $keys, true);
            if ($i !== false) {
                $pos[] = $i + 1;
            }
        }
        return $pos;
    }

    /**
     * Paso al que corresponde la pantalla abierta. Funcion pura.
     *
     * @param string $path Ruta de la pagina (/local/chatbot/pdp.php, /mod/page/view.php).
     * @param string[] $keys Ruta vigente.
     * @param string $cmidnumber Codigo de la actividad abierta, si la hay: un recurso fijo de una
     *               unidad (U1-LF-01) es la Ruta Formativa Base.
     * @return int 1 a N, o 0 si la pagina no es del recorrido.
     */
    public static function screen_step(string $path, array $keys = self::ROUTE5, string $cmidnumber = ''): int {
        $clave = '';
        if (strpos($path, '/local/chatbot/') !== false) {
            $clave = self::PAGES[basename($path)] ?? '';
        } else if ($cmidnumber !== '' && preg_match('/^U\d+-(LF|CA|ER|QF)-\d+$/i', trim($cmidnumber))) {
            $clave = 'ruta';
        }
        $i = $clave !== '' ? array_search($clave, $keys, true) : false;
        return $i === false ? 0 : $i + 1;
    }

    /**
     * Donde esta la persona y que sigue. Funcion pura.
     *
     * @param bool[] $done Paso (1 a N) => completado.
     * @param int $screen Paso de la pantalla abierta, 0 si no es del recorrido.
     * @param int $total Cantidad de pasos de la ruta.
     * @param int[] $optional Posiciones opcionales: no son «lo pendiente» ni «lo que sigue».
     * @param bool[] $locked Paso => bloqueado (salida de locked()): un paso bloqueado no es «lo que sigue».
     * @return array pending (primer paso obligatorio sin hacer, total+1 si no queda ninguno), focus
     *               (paso del que se habla: la pantalla abierta o, sin pantalla, el pendiente;
     *               total+1 = todo hecho), focus_done, next (siguiente paso obligatorio sin hacer y
     *               abierto, distinto del actual, 0 si no hay).
     */
    public static function view(array $done, int $screen, int $total = 5, array $optional = [], array $locked = []): array {
        $cuenta = static function (int $i) use ($done, $optional): bool {
            return empty($done[$i]) && !in_array($i, $optional, true);
        };
        $siguiente = static function (int $i) use ($cuenta, $locked): bool {
            return $cuenta($i) && empty($locked[$i]);
        };
        $pending = $total + 1;
        for ($i = 1; $i <= $total; $i++) {
            if ($cuenta($i)) {
                $pending = $i;
                break;
            }
        }
        $focus = ($screen >= 1 && $screen <= $total) ? $screen : $pending;
        $focusdone = $focus <= $total && !empty($done[$focus]);

        $next = 0;
        for ($i = $focus + 1; $i <= $total && $next === 0; $i++) {
            if ($siguiente($i)) {
                $next = $i;
            }
        }
        for ($i = 1; $i < $focus && $i <= $total && $next === 0; $i++) {
            if ($siguiente($i)) {
                $next = $i;
            }
        }
        return ['pending' => $pending, 'focus' => $focus, 'focus_done' => $focusdone, 'next' => $next];
    }

    /**
     * De que etapa habla la linea de resumen de la barra y en que estado. Funcion pura.
     *
     * En una pantalla del recorrido habla de esa pantalla; fuera de ellas (la portada del curso)
     * habla de la etapa pendiente, que es donde va la persona.
     *
     * @param array $view Salida de view().
     * @param int $screen Paso de la pantalla abierta, 0 si no es del recorrido.
     * @param bool[] $locked Paso => bloqueado.
     * @param int $total Cantidad de pasos de la ruta.
     * @return array n (etapa, 0 si ya no queda ninguna) y state (done, open, locked, pending o alldone).
     */
    public static function summary_state(array $view, int $screen, array $locked, int $total): array {
        if ($screen >= 1 && $screen <= $total) {
            $estado = $view['focus_done'] ? 'done' : (!empty($locked[$screen]) ? 'locked' : 'open');
            return ['n' => $screen, 'state' => $estado];
        }
        if ($view['pending'] > $total) {
            return ['n' => 0, 'state' => 'alldone'];
        }
        return ['n' => $view['pending'], 'state' => 'pending'];
    }

    /**
     * Que pasos estan bloqueados: «obligatorio para poder continuar». Funcion pura.
     *
     * Un paso obligatorio sin hacer se bloquea mientras falte alguno obligatorio anterior. Un paso
     * ya hecho nunca se bloquea: se puede volver a el cuando se quiera (navegacion libre hacia
     * atras). Un paso opcional solo espera lo que necesita (el PDP, el diagnostico).
     *
     * @param bool[] $done Paso (1 a N) => completado.
     * @param string[] $keys Ruta vigente.
     * @return bool[] Paso (1 a N) => bloqueado.
     */
    public static function locked(array $done, array $keys): array {
        $opcionales = self::optional_positions($keys);
        $bloqueo = [];
        $faltaobligatorio = false;
        foreach ($keys as $i => $clave) {
            $pos = $i + 1;
            if (in_array($pos, $opcionales, true)) {
                $requiere = self::REQUIRES[$clave] ?? '';
                $p = $requiere !== '' ? array_search($requiere, $keys, true) : false;
                $bloqueo[$pos] = empty($done[$pos]) && $p !== false && empty($done[$p + 1]);
                continue;
            }
            $bloqueo[$pos] = empty($done[$pos]) && $faltaobligatorio;
            if (empty($done[$pos])) {
                $faltaobligatorio = true;
            }
        }
        return $bloqueo;
    }

    /**
     * Estado de la Ruta Formativa Base a partir de sus recursos fijos. Funcion pura.
     *
     * @param array $resources Lista de ['code' (U1-LF-01), 'kind' (LF, CA, ER, QF), 'done' (bool)].
     * @return array ['done' => bool, 'missing' => tipos que faltan, en orden lectura, caso, ejemplo, quiz,
     *               'total' => cantidad de recursos]. Sin recursos fijos en el curso no hay nada que
     *               exigir: queda hecha.
     */
    public static function ruta_status(array $resources): array {
        $orden = ['LF', 'CA', 'ER', 'QF'];
        $faltan = [];
        foreach ($resources as $r) {
            if (empty($r['done'])) {
                $faltan[strtoupper((string) $r['kind'])] = true;
            }
        }
        $faltan = array_values(array_filter($orden, static function ($k) use ($faltan) {
            return isset($faltan[$k]);
        }));
        return ['done' => empty($faltan), 'missing' => $faltan, 'total' => count($resources)];
    }

    /** @var int Dias antes del plazo de una meta a partir de los cuales se avisa. */
    const DEADLINE_WARNING_DAYS = 7;

    /**
     * La accion concreta que mas conviene hacer ahora. Funcion pura.
     *
     * Antes cada lugar (barra de ruta, widget, tarjeta de siguiente paso) calculaba su propio
     * «que sigue» y decia solo la pantalla. Esta es la unica fuente: dice la accion y lleva al
     * lugar exacto (la propuesta, el apoyo, la meta).
     *
     * Ruta de 5: perfil, diagnostico, propuestas por revisar, pedir propuestas, validar el plan,
     * apoyos que esperan a la persona (decidir, entregar evidencia, iniciar), plazos cercanos,
     * proyecto y cierre. Ruta de 6 (hecho route6): perfil, diagnostico, Ruta Formativa Base,
     * proyecto, el plan solo si la persona ya lo usa (es opcional) y cierre.
     *
     * @param array $f Hechos: consent, diagnostic, plan_validated, project, closure (bool);
     *                 proposed, inplan (int); supports (lista de ['n', 'id', 'kind'] con kind
     *                 decidir, evidencia o iniciar); deadlines (lista de ['n', 'id', 'plazo']); now (int);
     *                 project_first, route6, ruta (bool); ruta_missing (tipos que faltan).
     * @return array ['key', 'a' (dato para la cadena), 'step' (posicion en la ruta, 0 si ninguno),
     *               'stepkey', 'anchor'].
     */
    public static function next_action(array $f): array {
        $ruta = !empty($f['route6']) ? self::ROUTE6 : self::ROUTE5;
        $accion = static function (string $key, string $stepkey, $a = null, string $anchor = '') use ($ruta): array {
            $i = $stepkey !== '' ? array_search($stepkey, $ruta, true) : false;
            return ['key' => $key, 'a' => $a, 'step' => $i === false ? 0 : $i + 1, 'stepkey' => $stepkey,
                'anchor' => $anchor];
        };
        if (empty($f['consent'])) {
            return $accion('perfil', 'perfil');
        }
        if (empty($f['diagnostic'])) {
            return $accion('diagnostico', 'diagnostico');
        }
        if (!empty($f['route6'])) {
            if (empty($f['ruta'])) {
                return $accion('ruta', 'ruta', array_values($f['ruta_missing'] ?? []));
            }
            if (empty($f['project'])) {
                return $accion('proyecto', 'proyecto');
            }
            // El plan es opcional: solo se sugiere lo que la persona ya tiene en curso.
            if ((int) ($f['proposed'] ?? 0) > 0 || (int) ($f['inplan'] ?? 0) > 0) {
                $plan = self::pdp_action($f, false);
                if ($plan !== null) {
                    return $accion($plan['key'], 'pdp', $plan['a'], $plan['anchor']);
                }
            }
            if (empty($f['closure'])) {
                return $accion('cierre', 'cierre');
            }
            return $accion('completo', '');
        }
        // Con «project_first» (ajustes del cliente del 08-10-2026) el proyecto va antes que el PDP, que
        // pasa a ser la etapa flexible que se nutre del proyecto.
        if (!empty($f['project_first']) && empty($f['project'])) {
            return $accion('proyecto', 'proyecto');
        }
        $plan = self::pdp_action($f);
        if ($plan !== null) {
            return $accion($plan['key'], 'pdp', $plan['a'], $plan['anchor']);
        }
        if (empty($f['project'])) {
            return $accion('proyecto', 'proyecto');
        }
        if (empty($f['closure'])) {
            return $accion('cierre', 'cierre');
        }
        return $accion('completo', '');
    }

    /**
     * Lo que conviene hacer en el plan, si hay algo: propuestas por revisar, pedir propuestas,
     * validar, apoyos que esperan a la persona y plazos cercanos. Funcion pura.
     *
     * @param array $f Hechos de next_action().
     * @param bool $allowask Si se puede sugerir pedir propuestas (no, cuando el plan es opcional).
     * @return array|null ['key', 'a', 'anchor'] o null si el plan no pide nada ahora.
     */
    private static function pdp_action(array $f, bool $allowask = true): ?array {
        $propuestas = (int) ($f['proposed'] ?? 0);
        if ($propuestas > 0) {
            return ['key' => 'propuestas', 'a' => $propuestas, 'anchor' => 'propuestas'];
        }
        if ((int) ($f['inplan'] ?? 0) === 0) {
            return $allowask ? ['key' => 'pedir', 'a' => null, 'anchor' => ''] : null;
        }
        if (empty($f['plan_validated'])) {
            return ['key' => 'validar', 'a' => null, 'anchor' => 'chatbot-pdp-guide'];
        }
        foreach (['decidir', 'evidencia', 'iniciar'] as $tipo) {
            foreach ($f['supports'] ?? [] as $apoyo) {
                if (($apoyo['kind'] ?? '') === $tipo) {
                    return ['key' => 'apoyo_' . $tipo, 'a' => (int) $apoyo['n'], 'anchor' => 'apoyo-' . (int) $apoyo['id']];
                }
            }
        }
        $ahora = (int) ($f['now'] ?? 0);
        $proxima = null;
        foreach ($f['deadlines'] ?? [] as $plazo) {
            $vence = (int) $plazo['plazo'];
            if ($vence >= $ahora && $vence - $ahora <= self::DEADLINE_WARNING_DAYS * 86400
                    && ($proxima === null || $vence < (int) $proxima['plazo'])) {
                $proxima = $plazo;
            }
        }
        if ($proxima !== null) {
            return ['key' => 'plazo', 'a' => ['n' => (int) $proxima['n'], 'plazo' => (int) $proxima['plazo']],
                'anchor' => 'meta-' . (int) $proxima['id']];
        }
        return null;
    }

    /**
     * Pasos de la barra de ruta que se muestra arriba de cada pantalla. Funcion pura.
     *
     * @param bool[] $done Paso (1 a N) => completado.
     * @param int $screen Paso de la pantalla abierta (0 si no es del recorrido).
     * @param int $total Cantidad de pasos.
     * @param int[] $optional Posiciones opcionales.
     * @param bool[] $locked Paso => bloqueado (salida de locked()).
     * @return array Lista de ['step', 'done', 'here', 'optional', 'locked'].
     */
    public static function route_steps(array $done, int $screen, int $total = 5, array $optional = [],
            array $locked = []): array {
        $pasos = [];
        for ($i = 1; $i <= $total; $i++) {
            $pasos[] = ['step' => $i, 'done' => !empty($done[$i]), 'here' => $i === $screen,
                'optional' => in_array($i, $optional, true), 'locked' => !empty($locked[$i])];
        }
        return $pasos;
    }

    /**
     * Linea de ubicacion para el modelo: que pantalla tiene abierta la persona. Funcion pura.
     *
     * @param array $view Resultado de view().
     * @param int $screen Paso de la pantalla abierta (0 si no es del recorrido).
     * @param string[] $keys Ruta vigente.
     * @return string Vacia cuando no hay pantalla del recorrido que decir.
     */
    public static function context_line(array $view, int $screen, array $keys = self::ROUTE5): string {
        if ($screen < 1 || $screen > count($keys)) {
            return '';
        }
        $nombre = static function (int $pos) use ($keys): string {
            return self::step_name($keys[$pos - 1], $keys);
        };
        // La ruta de 6 habla de etapas, como el documento del cliente y la barra.
        $seis = in_array('ruta', $keys, true);
        $hecho = $seis ? 'esa etapa ya está completa, pero puede volver a ella' : 'ese paso ya está completo, pero puede seguir ajustándolo';
        $encurso = $seis ? 'esa etapa está en curso' : 'ese paso está en curso';
        $linea = 'UBICACIÓN DE LA PERSONA: tiene abierta la pantalla «' . $nombre($screen) . '» del Módulo 1 ('
            . ($view['focus_done'] ? $hecho : $encurso) . ').';
        if ($view['next'] > 0) {
            $linea .= ' Su siguiente ' . ($seis ? 'etapa pendiente' : 'paso pendiente') . ' es «' . $nombre($view['next'])
                . '»; menciónalo como lo que sigue, no como el lugar donde está.';
        }
        return $linea;
    }

    /**
     * Linea para el modelo sobre que se abre al cerrar el Modulo 1 (ajustes del cliente del 08-10-2026:
     * «ambos módulos quedarán disponibles al cierre del Módulo 1»). Funcion pura.
     *
     * @param string[] $modules Nombres de los cursos que se abren al cerrar.
     * @param bool $closed Si la persona ya completo el cierre.
     * @return string
     */
    public static function next_modules_line(array $modules, bool $closed): string {
        if (empty($modules)) {
            return "Al completar el Seguimiento y Cierre no se abre ningún módulo nuevo en esta plataforma; "
                . "no inventes una continuación.\n";
        }
        $lista = '«' . implode('» y «', $modules) . '»';
        return ($closed
            ? "La persona ya completó el Seguimiento y Cierre, así que ya tiene acceso a los módulos siguientes: {$lista}."
            : "Al completar el Seguimiento y Cierre la persona queda con acceso a los módulos siguientes: {$lista}.")
            . " No menciones otras unidades ni módulos como continuación.\n";
    }

    /**
     * Bloque de avance en la ruta para el contexto del modelo. Funcion pura.
     *
     * @param string[] $keys Ruta vigente.
     * @param bool[] $done Paso (1 a N) => completado.
     * @return string
     */
    public static function progress_block(array $keys, array $done): string {
        $opcionales = self::optional_positions($keys);
        $vista = self::view($done, 0, count($keys), $opcionales);
        $unidad = in_array('ruta', $keys, true) ? 'Etapa' : 'Paso';
        $out = "--- AVANCE EN LA RUTA FORMATIVA DEL MÓDULO 1 ---\n";
        foreach ($keys as $i => $clave) {
            $pos = $i + 1;
            $out .= sprintf("[%s] %s %d: %s%s\n", !empty($done[$pos]) ? 'COMPLETADO' : 'PENDIENTE', $unidad, $pos,
                self::step_name($clave, $keys), in_array($pos, $opcionales, true) ? ' (opcional)' : '');
        }
        if ($vista['pending'] <= count($keys)) {
            $out .= "Hito actual pendiente de la persona: {$unidad} {$vista['pending']} ("
                . self::step_name($keys[$vista['pending'] - 1], $keys) . ").\n";
            $out .= "Instrucción pedagógica: Cuando la persona pregunte dónde va, qué le falta o cuál es su siguiente paso, "
                . "oriéntala con precisión según este avance, animándola a avanzar hacia el hito pendiente sin perder de vista su contexto.\n";
        } else {
            $out .= "La persona completó todos los pasos obligatorios de la ruta.\n";
        }
        return $out;
    }

    /**
     * Lista de pasos de la guia del PDP. Funcion pura.
     *
     * @param int $proposed Metas propuestas por revisar.
     * @param int $inplan Metas que ya forman parte del plan (aceptadas, modificadas, postergadas, completadas).
     * @param bool $validated Si el plan esta validado.
     * @return array Lista de ['key', 'done', 'count', 'current']; 'current' marca el primero sin hacer.
     */
    public static function pdp_guide(int $proposed, int $inplan, bool $validated): array {
        $items = [
            ['key' => 'ask', 'done' => ($proposed + $inplan) > 0, 'count' => 0],
            ['key' => 'review', 'done' => ($proposed + $inplan) > 0 && $proposed === 0, 'count' => $proposed],
            ['key' => 'validate', 'done' => $validated, 'count' => 0],
        ];
        $marcado = false;
        foreach ($items as &$item) {
            $item['current'] = !$marcado && !$item['done'];
            $marcado = $marcado || $item['current'];
        }
        unset($item);
        return $items;
    }
}
