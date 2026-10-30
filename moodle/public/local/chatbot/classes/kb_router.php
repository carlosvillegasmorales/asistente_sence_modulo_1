<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Enrutador puro de la libreria de conocimientos del Modulo 2 (ambito 'm2').
 *
 * Cada seccion Markdown (#, ## o ###) puede declarar al final de su titulo en que tareas del Modulo 2 participa
 * ({ruta: fase1 e1 ...}) y si corresponde a un nivel de cualificacion especifico ({nivel: N}). Todo aqui es funcion
 * pura sin dependencias de Moodle ($DB, get_config, core_text) para verificarse en CLI sin base de datos.
 */
class kb_router {

    /** @var int Presupuesto maximo de caracteres de la libreria enrutada por pedido (cubre las 7 fuentes textuales sin omitir secciones). */
    const PRESUPUESTO = 220000;

    /** @var string[] Tareas validas del Modulo 2 (la etapa 7 no usa libreria). */
    const TAREAS = ['fase1', 'e1', 'e2', 'e3', 'e4', 'e5', 'e6', 'e8'];

    /**
     * Lee el nivel de cualificacion (1 a 5) del campo «nivel» de la ficha, en cifra o palabra.
     * Si no hay uno solo claro, devuelve null.
     *
     * @param string $texto Valor registrado en el campo «nivel» de la ficha.
     * @return int|null Nivel entre 1 y 5, o null.
     */
    public static function level_of(string $texto): ?int {
        $limpio = mb_strtolower(trim($texto), 'UTF-8');
        if ($limpio === '') {
            return null;
        }
        // Si menciona un digito suelto fuera de 1..5 (por ejemplo 0 o 6..9), no hay un nivel unico valido.
        if (preg_match('/(?<![a-záéíóúüñ0-9.,])(?:0|[6-9])(?![0-9]|[.,][0-9])/u', $limpio)) {
            return null;
        }
        $niveles = [];
        if (preg_match_all('/(?<![a-záéíóúüñ0-9.,])([1-5])(?![0-9]|[.,][0-9])/u', $limpio, $md)) {
            foreach ($md[1] as $d) {
                $niveles[(int) $d] = true;
            }
        }
        $palabras = [
            'uno' => 1, 'primero' => 1, 'primer' => 1, 'primera' => 1,
            'dos' => 2, 'segundo' => 2, 'segunda' => 2,
            'tres' => 3, 'tercero' => 3, 'tercer' => 3, 'tercera' => 3,
            'cuatro' => 4, 'cuarto' => 4, 'cuarta' => 4,
            'cinco' => 5, 'quinto' => 5, 'quinta' => 5,
        ];
        if (preg_match_all('/(?<![\p{L}\p{N}])(' . implode('|', array_keys($palabras)) . ')(?![\p{L}\p{N}])/u',
                $limpio, $mp)) {
            foreach ($mp[1] as $p) {
                $niveles[$palabras[$p]] = true;
            }
        }
        if (count($niveles) !== 1) {
            return null;
        }
        return (int) array_key_first($niveles);
    }

    /**
     * Quita las marcas {ruta: ...} y {nivel: ...} del texto antes de enviarlo al modelo o armar titulos.
     *
     * @param string $texto Texto con posibles marcas.
     * @return string Texto limpio sin marcas.
     */
    public static function strip_marks(string $texto): string {
        return (string) preg_replace_callback('/^.*\{\s*(?:ruta|nivel)\s*:[^}\r\n]*\}.*$/imu',
            static function(array $m): string {
                $linea = preg_replace('/[ \t]*\{\s*(?:ruta|nivel)\s*:[^}\r\n]*\}/iu', '', $m[0]);
                return rtrim((string) $linea, " \t");
            }, $texto);
    }

    /**
     * Elige las secciones de la libreria que corresponden a una tarea y nivel, respetando el presupuesto.
     *
     * @param array $docs Lista de ['nombre' => string, 'contenido' => string, 'version' => int].
     * @param string $tarea Tarea ('fase1', 'e1'..'e6', 'e8'); vacia o desconocida devuelve todo sin marcas.
     * @param int|null $nivel Nivel de cualificacion 1..5 de la ficha, o null si no se pudo leer.
     * @param int $presupuesto Maximo de caracteres (por defecto self::PRESUPUESTO).
     * @return array ['texto' => string, 'secciones' => int, 'omitidas' => int, 'caracteres' => int].
     */
    public static function select(array $docs, string $tarea, ?int $nivel,
            int $presupuesto = self::PRESUPUESTO): array {
        $tarea = mb_strtolower(trim($tarea), 'UTF-8');

        // Tarea vacia o desconocida: devuelve la libreria completa sin marcas (comportamiento anterior).
        if (!in_array($tarea, self::TAREAS, true)) {
            $bloques = [];
            $totalSecciones = 0;
            foreach ($docs as $doc) {
                $nombre = (string) ($doc['nombre'] ?? '');
                $version = (int) ($doc['version'] ?? 1);
                $contenido = trim(self::strip_marks(str_replace("\r\n", "\n", (string) ($doc['contenido'] ?? ''))));
                if ($contenido === '') {
                    continue;
                }
                $totalSecciones += count(self::parse_sections((string) ($doc['contenido'] ?? '')));
                $bloques[] = "=== DOCUMENTO: {$nombre} (v{$version}) ===\n" . $contenido;
            }
            $texto = implode("\n\n", $bloques);
            return [
                'texto' => $texto,
                'secciones' => $totalSecciones,
                'omitidas' => 0,
                'caracteres' => mb_strlen($texto, 'UTF-8'),
            ];
        }

        // Pasada 1: secciones sin {nivel}, en orden de documento.
        // Pasada 2: secciones con {nivel} al final de toda la libreria para preservar el prefijo comun en cache.
        $normales = [];
        $porNivel = [];
        foreach ($docs as $idx => $doc) {
            $nombre = (string) ($doc['nombre'] ?? '');
            $version = (int) ($doc['version'] ?? 1);
            $encabezado = "=== DOCUMENTO: {$nombre} (v{$version}) ===";
            foreach (self::parse_sections((string) ($doc['contenido'] ?? '')) as $sec) {
                if (!self::matches_route($sec['ruta'], $tarea)) {
                    continue;
                }
                if ($sec['nivel'] !== null) {
                    if ($nivel === null || $sec['nivel'] !== $nivel) {
                        continue;
                    }
                    $porNivel[] = ['grupo' => 'n_' . $idx, 'encabezado' => $encabezado, 'texto' => $sec['texto']];
                } else {
                    $normales[] = ['grupo' => 'g_' . $idx, 'encabezado' => $encabezado, 'texto' => $sec['texto']];
                }
            }
        }

        $candidatas = array_merge($normales, $porNivel);
        $grupos = [];
        $ordenGrupos = [];
        $largoActual = 0;
        $secciones = 0;
        $omitidas = 0;

        foreach ($candidatas as $item) {
            $g = $item['grupo'];
            if (!isset($grupos[$g])) {
                $bloqueNuevo = $item['encabezado'] . "\n" . $item['texto'];
                $incremento = ($largoActual === 0 ? 0 : 2) + mb_strlen($bloqueNuevo, 'UTF-8');
                if ($largoActual + $incremento > $presupuesto) {
                    $omitidas++;
                    continue;
                }
                $grupos[$g] = $bloqueNuevo;
                $ordenGrupos[] = $g;
                $largoActual += $incremento;
                $secciones++;
            } else {
                $incremento = 2 + mb_strlen($item['texto'], 'UTF-8');
                if ($largoActual + $incremento > $presupuesto) {
                    $omitidas++;
                    continue;
                }
                $grupos[$g] .= "\n\n" . $item['texto'];
                $largoActual += $incremento;
                $secciones++;
            }
        }

        $partes = [];
        foreach ($ordenGrupos as $g) {
            $partes[] = $grupos[$g];
        }
        $texto = implode("\n\n", $partes);
        if ($omitidas > 0) {
            $aviso = "(Por límite de tamaño se omitieron {$omitidas} secciones de la librería.)";
            $texto = $texto !== '' ? $texto . "\n\n" . $aviso : $aviso;
        }

        return [
            'texto' => $texto,
            'secciones' => $secciones,
            'omitidas' => $omitidas,
            'caracteres' => mb_strlen($texto, 'UTF-8'),
        ];
    }

    /**
     * Divide el contenido de un documento en secciones (#, ## o ###) y resuelve la herencia de marcas.
     *
     * @param string $contenido Texto Markdown del documento.
     * @return array Lista de ['texto' => string, 'ruta' => string[], 'nivel' => int|null].
     */
    private static function parse_sections(string $contenido): array {
        $normalizado = str_replace("\r\n", "\n", preg_replace('/^\xEF\xBB\xBF/', '', $contenido));
        if (trim($normalizado) === '') {
            return [];
        }

        $cruda = [];
        $actual = null;
        $tieneMarcaRuta = false;

        foreach (explode("\n", $normalizado) as $linea) {
            if (preg_match('/^(#{1,3})[ \t]+(.+)$/u', $linea, $m)) {
                if ($actual !== null) {
                    $cruda[] = $actual;
                }
                $nivelTitulo = strlen($m[1]);
                [$rutaExp, $nivelExp] = self::extract_marks($m[2]);
                if ($rutaExp !== null) {
                    $tieneMarcaRuta = true;
                }
                $actual = [
                    'nivel_titulo' => $nivelTitulo,
                    'ruta_exp' => $rutaExp,
                    'nivel_exp' => $nivelExp,
                    'lineas' => [self::strip_marks($linea)],
                ];
            } else {
                if ($actual === null) {
                    if (trim($linea) === '') {
                        continue;
                    }
                    $actual = [
                        'nivel_titulo' => 0,
                        'ruta_exp' => null,
                        'nivel_exp' => null,
                        'lineas' => [self::strip_marks($linea)],
                    ];
                } else {
                    $actual['lineas'][] = self::strip_marks($linea);
                }
            }
        }
        if ($actual !== null) {
            $cruda[] = $actual;
        }

        $secciones = [];
        $pila = [];
        foreach ($cruda as $sec) {
            $lt = $sec['nivel_titulo'];
            if ($lt > 0) {
                foreach (array_keys($pila) as $k) {
                    if ($k >= $lt) {
                        unset($pila[$k]);
                    }
                }
                $pila[$lt] = [
                    'ruta' => $sec['ruta_exp'],
                    'nivel' => $sec['nivel_exp'],
                ];
            }

            if (!$tieneMarcaRuta) {
                $ruta = ['todas'];
            } else {
                $ruta = [];
                for ($k = $lt; $k >= 1; $k--) {
                    if (isset($pila[$k]) && $pila[$k]['ruta'] !== null) {
                        $ruta = $pila[$k]['ruta'];
                        break;
                    }
                }
            }

            $nivelSec = null;
            for ($k = $lt; $k >= 1; $k--) {
                if (isset($pila[$k]) && $pila[$k]['nivel'] !== null) {
                    $nivelSec = $pila[$k]['nivel'];
                    break;
                }
            }

            $textoSec = trim(implode("\n", $sec['lineas']));
            if ($textoSec === '') {
                continue;
            }
            $secciones[] = [
                'texto' => $textoSec,
                'ruta' => $ruta,
                'nivel' => $nivelSec,
            ];
        }

        return $secciones;
    }

    /**
     * Extrae las marcas {ruta: ...} y {nivel: ...} de una linea de titulo.
     *
     * @param string $titulo Texto del titulo sin los signos #.
     * @return array [string[]|null $ruta, int|null $nivel].
     */
    private static function extract_marks(string $titulo): array {
        $ruta = null;
        $nivel = null;

        if (preg_match_all('/\{\s*ruta\s*:\s*([^}\r\n]*)\}/iu', $titulo, $mr)) {
            $tokens = [];
            foreach ($mr[1] as $bloque) {
                foreach (preg_split('/\s+/u', mb_strtolower(trim($bloque), 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
                    if ($tok === 'todas' || $tok === 'ninguna' || in_array($tok, self::TAREAS, true)) {
                        $tokens[] = $tok;
                    }
                }
            }
            $ruta = array_values(array_unique($tokens));
        }

        if (preg_match('/\{\s*nivel\s*:\s*([^}\r\n]*)\}/iu', $titulo, $mn)) {
            $leido = self::level_of($mn[1]);
            // Un {nivel: ...} invalido queda en 0 para no coincidir con ningun nivel 1..5.
            $nivel = $leido ?? 0;
        }

        return [$ruta, $nivel];
    }

    /**
     * Comprueba si la lista de rutas efectivas de una seccion incluye la tarea pedida.
     *
     * @param string[] $rutas Tokens de ruta efectivos de la seccion.
     * @param string $tarea Tarea valida.
     * @return bool
     */
    private static function matches_route(array $rutas, string $tarea): bool {
        if (!$rutas || in_array('ninguna', $rutas, true)) {
            return false;
        }
        if (in_array('todas', $rutas, true)) {
            return true;
        }
        return in_array($tarea, $rutas, true);
    }
}
