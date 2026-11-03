<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Perfiles del Modulo 1 en el uso del Modulo 2 (pedido del cliente del 03-11-2026: «saber como se comportan los perfiles
 * en el uso del Modulo 2»).
 *
 * Cruza las respuestas del perfilamiento del Modulo 1 (rol, programa, modalidad, experiencia, familiaridad, tiempo y
 * aspecto a fortalecer) con el comportamiento de la misma persona en el Modulo 2, calculado con los mismos hechos que el
 * tablero (tablero_m2::facts()). Solo agregados: un grupo con menos de MINIMO personas con el dato no se muestra.
 *
 * Todo es puro salvo load() y los cursos, que leen la base. Lo ejercita cli/verificar_perfiles_m2.php.
 */
class perfiles_m2 {

    /** @var int Personas minimas con el dato para mostrar un grupo (para que no se pueda identificar a nadie). */
    const MINIMO = 3;

    /**
     * @var int Personas minimas con el dato en cada grupo para sacar una conclusion: con menos, una diferencia puede ser
     * azar (en la demostracion, «3 coordinadores frente a 3 «otro»» salia como hallazgo).
     */
    const MINIMO_CONCLUSION = 5;

    /** @var string[] Preguntas del perfil que se cruzan, en el orden del selector. */
    const DIMENSIONES = ['experiencia', 'familiaridad', 'rol', 'programa', 'modalidad', 'tiempo', 'fortalecer'];

    /** @var string Grupo de quienes estan en el Modulo 2 sin perfil del Modulo 1 (o sin consentimiento vigente). */
    const SIN_PERFIL = 'sinperfil';

    /** @var string Respuesta vacia de una pregunta del perfil. */
    const SIN_RESPUESTA = 'sinrespuesta';

    /**
     * @var array Comportamientos en el Modulo 2: grupo, tipo de agregado (pct, mediana o promedio) y que es mejor
     * (alto o bajo). El orden es el de las columnas.
     */
    const METRICAS = [
        'entrego' => ['grupo' => 'inicio', 'tipo' => 'pct', 'mejor' => 'alto'],
        'dias_inicio' => ['grupo' => 'inicio', 'tipo' => 'mediana', 'mejor' => 'bajo'],
        'uso_guia' => ['grupo' => 'inicio', 'tipo' => 'pct', 'mejor' => 'alto'],
        'completo' => ['grupo' => 'etapa0', 'tipo' => 'pct', 'mejor' => 'alto'],
        'aprob_primer' => ['grupo' => 'etapa0', 'tipo' => 'pct', 'mejor' => 'alto'],
        'ciclos' => ['grupo' => 'etapa0', 'tipo' => 'promedio', 'mejor' => 'bajo'],
        'criticos' => ['grupo' => 'etapa0', 'tipo' => 'pct', 'mejor' => 'bajo'],
        'dias_correccion' => ['grupo' => 'etapa0', 'tipo' => 'mediana', 'mejor' => 'bajo'],
        'diseno' => ['grupo' => 'diseno', 'tipo' => 'pct', 'mejor' => 'alto'],
        'rondas' => ['grupo' => 'diseno', 'tipo' => 'promedio', 'mejor' => 'bajo'],
        'detenido' => ['grupo' => 'diseno', 'tipo' => 'pct', 'mejor' => 'bajo'],
        'termino' => ['grupo' => 'resultado', 'tipo' => 'pct', 'mejor' => 'alto'],
        'duracion' => ['grupo' => 'resultado', 'tipo' => 'mediana', 'mejor' => 'bajo'],
        'puntaje' => ['grupo' => 'resultado', 'tipo' => 'promedio', 'mejor' => 'alto'],
        'encuesta' => ['grupo' => 'resultado', 'tipo' => 'promedio', 'mejor' => 'alto'],
    ];

    /**
     * @var float[] Diferencia minima entre el mejor y el peor grupo para que sea una conclusion: puntos porcentuales en
     * los porcentajes y unidades de la metrica en el resto.
     */
    const UMBRALES = ['pct' => 20, 'dias_inicio' => 2, 'dias_correccion' => 2, 'duracion' => 3, 'ciclos' => 0.5,
        'rondas' => 0.5, 'puntaje' => 3, 'encuesta' => 0.5];

    /** @var string[] Comportamientos que se ven sin pedir el detalle (los otros, con «ver los 15»). */
    const CLAVE = ['entrego', 'aprob_primer', 'diseno', 'detenido', 'termino', 'puntaje'];

    /**
     * @var float[] Diferencia con el promedio del curso que se considera «parecido» (ni mejor ni peor): puntos
     * porcentuales en los porcentajes y unidades de la metrica en el resto.
     */
    const TOLERANCIA = ['pct' => 10, 'dias_inicio' => 1, 'dias_correccion' => 1, 'duracion' => 2, 'ciclos' => 0.3,
        'rondas' => 0.5, 'puntaje' => 2, 'encuesta' => 0.3];

    /**
     * Alternativas de cada pregunta, en el orden del formulario del perfil. Funcion pura.
     *
     * @param string $dim
     * @return string[]
     */
    public static function options(string $dim): array {
        return profile::get_options()[$dim] ?? [];
    }

    /**
     * Comportamiento de una persona en el Modulo 2, a partir de los hechos de sus fichas. Funcion pura.
     *
     * Con varias fichas cuenta la mas avanzada (la activa si empatan, y si no la mas reciente): es la que muestra hasta
     * donde llego la persona. Lo que todavia no ocurre queda en null (por ejemplo, el puntaje sin evaluacion), y asi no
     * entra en el agregado de su grupo.
     *
     * @param array $hechos Lista de tablero_m2::facts() de las fichas de la persona (puede ser vacia).
     * @param int $now
     * @param array $plazos tablero_m2::plazos().
     * @return array metrica => bool|float|int|null
     */
    public static function person(array $hechos, int $now, array $plazos): array {
        $m = array_fill_keys(array_keys(self::METRICAS), null);
        if (!$hechos) {
            $m['entrego'] = false;
            return $m;
        }
        $h = self::main_ficha($hechos);
        $m['entrego'] = $h['primera_entrega'] !== null;
        if (!$m['entrego']) {
            return $m;
        }
        $entrega = (int) $h['primera_entrega'];
        $m['dias_inicio'] = $h['primer_acceso'] !== null ? tablero_m2::days((int) $h['primer_acceso'], $entrega) : null;
        $vistas = array_filter([$h['vista_guia'] ?? null, $h['vista_ejemplo'] ?? null]);
        $m['uso_guia'] = $vistas ? (int) min($vistas) <= $entrega : false;
        $m['completo'] = $h['primer_envio_completo'];
        if ($h['decision_primera'] !== null) {
            $m['aprob_primer'] = exante::can_advance($h['decision_primera']);
            $m['criticos'] = !empty($h['criticos_obs']);
        }
        $m['ciclos'] = $h['ciclos_aprobacion'];
        $m['dias_correccion'] = $h['correcciones'] ? tablero_m2::median($h['correcciones']) : null;
        $m['diseno'] = !empty($h['etapas'][1]['alcanzada']);
        if ($m['diseno']) {
            $rondas = 0;
            foreach ($h['etapas'] as $n => $e) {
                if ($n >= 1 && $n <= 6) {
                    $rondas += (int) $e['rondas'];
                }
            }
            $m['rondas'] = $rondas;
        }
        $tipos = array_column(tablero_m2::notices($h, $now, $plazos), 'tipo');
        $m['detenido'] = in_array('detenido', $tipos, true) || in_array('correccion', $tipos, true);
        $m['termino'] = $h['evaluacion'] !== null;
        $m['duracion'] = $h['duracion'];
        $m['puntaje'] = $h['evaluacion']['total'] ?? null;
        $respuestas = array_filter((array) ($h['encuesta'] ?? []), 'is_numeric');
        $m['encuesta'] = $respuestas ? round(array_sum($respuestas) / count($respuestas), 1) : null;
        return $m;
    }

    /**
     * Ficha que representa a la persona: la mas avanzada; la activa si empatan; si no, la mas reciente. Funcion pura.
     *
     * @param array $hechos Lista de tablero_m2::facts() (no vacia).
     * @return array
     */
    public static function main_ficha(array $hechos): array {
        $hechos = array_values($hechos);
        usort($hechos, static function(array $a, array $b): int {
            return [tablero_m2::current_stage($b), (int) $b['activa'], $b['fichaid']]
                <=> [tablero_m2::current_stage($a), (int) $a['activa'], $a['fichaid']];
        });
        return $hechos[0];
    }

    /**
     * Respuesta de una persona a una pregunta del perfil, como lista de grupos (la pregunta «fortalecer» admite dos
     * marcas: la persona cuenta en ambos grupos). Funcion pura.
     *
     * @param array|null $perfil dim => valor (texto; fortalecer separado por comas), null si no tiene perfil.
     * @param string $dim
     * @return string[]
     */
    public static function answers(?array $perfil, string $dim): array {
        if ($perfil === null) {
            return [self::SIN_PERFIL];
        }
        $valor = trim((string) ($perfil[$dim] ?? ''));
        if ($valor === '') {
            return [self::SIN_RESPUESTA];
        }
        $partes = $dim === 'fortalecer' ? array_filter(array_map('trim', explode(',', $valor))) : [$valor];
        $validas = array_values(array_intersect($partes, self::options($dim)));
        return $validas ?: [self::SIN_RESPUESTA];
    }

    /**
     * Agrega los valores de una metrica: porcentaje de verdaderos, mediana o promedio. Funcion pura.
     *
     * @param array $valores Sin null.
     * @param string $tipo pct, mediana o promedio.
     * @return float|int|null
     */
    public static function aggregate(array $valores, string $tipo) {
        if (!$valores) {
            return null;
        }
        if ($tipo === 'pct') {
            return tablero_m2::pct(count(array_filter($valores)), count($valores));
        }
        if ($tipo === 'mediana') {
            return tablero_m2::median($valores);
        }
        return tablero_m2::mean($valores);
    }

    /**
     * Cruce de una pregunta del perfil con el comportamiento en el Modulo 2. Funcion pura.
     *
     * @param array $personas Lista de ['perfil' => array|null, 'metricas' => person()].
     * @param string $dim
     * @param int $minimo
     * @return array respuesta => ['n' => personas del grupo, 'celdas' => metrica => ['valor', 'n', 'oculto']], en el
     *               orden del formulario, con «sin respuesta» y «sin perfil» al final.
     */
    public static function cross(array $personas, string $dim, int $minimo = self::MINIMO): array {
        $grupos = [];
        foreach ($personas as $p) {
            foreach (self::answers($p['perfil'] ?? null, $dim) as $respuesta) {
                $grupos[$respuesta][] = $p['metricas'];
            }
        }
        $orden = array_merge(self::options($dim), [self::SIN_RESPUESTA, self::SIN_PERFIL]);
        $filas = [];
        foreach ($orden as $respuesta) {
            if (!isset($grupos[$respuesta])) {
                continue;
            }
            $celdas = [];
            foreach (self::METRICAS as $clave => $def) {
                $valores = array_values(array_filter(array_column($grupos[$respuesta], $clave), static function($v) {
                    return $v !== null;
                }));
                $oculto = count($valores) < $minimo;
                $celdas[$clave] = ['valor' => $oculto ? null : self::aggregate($valores, $def['tipo']),
                    'n' => count($valores), 'oculto' => $oculto];
            }
            $filas[$respuesta] = ['n' => count($grupos[$respuesta]), 'celdas' => $celdas];
        }
        return $filas;
    }

    /**
     * Todo el curso como un solo grupo: la referencia con que se compara cada perfil. Funcion pura.
     *
     * @param array $personas Como en cross().
     * @param int $minimo
     * @return array ['n' => personas, 'celdas' => metrica => ['valor', 'n', 'oculto']].
     */
    public static function overall(array $personas, int $minimo = self::MINIMO): array {
        $metricas = array_column($personas, 'metricas');
        $celdas = [];
        foreach (self::METRICAS as $clave => $def) {
            $valores = array_values(array_filter(array_column($metricas, $clave), static function($v) {
                return $v !== null;
            }));
            $oculto = count($valores) < $minimo;
            $celdas[$clave] = ['valor' => $oculto ? null : self::aggregate($valores, $def['tipo']), 'n' => count($valores),
                'oculto' => $oculto];
        }
        return ['n' => count($personas), 'celdas' => $celdas];
    }

    /**
     * Como esta un valor frente al promedio del curso: mejor, parecido o peor, segun lo que es mejor en la metrica y la
     * tolerancia (TOLERANCIA). Funcion pura.
     *
     * @param float|int|null $valor
     * @param float|int|null $total
     * @param string $metrica
     * @return string mejor, igual, peor, o '' si falta alguno de los dos.
     */
    public static function compare($valor, $total, string $metrica): string {
        if ($valor === null || $total === null) {
            return '';
        }
        $def = self::METRICAS[$metrica];
        $tolerancia = $def['tipo'] === 'pct' ? self::TOLERANCIA['pct'] : (self::TOLERANCIA[$metrica] ?? 1);
        $diferencia = (float) $valor - (float) $total;
        if (abs($diferencia) < $tolerancia) {
            return 'igual';
        }
        return ($diferencia > 0) === ($def['mejor'] === 'alto') ? 'mejor' : 'peor';
    }

    /**
     * En que se distingue un grupo del promedio del curso: primero los comportamientos clave, y dentro de cada tipo de lo
     * mas a lo menos marcado. Funcion pura.
     *
     * @param array $fila Una fila de cross().
     * @param array $total Salida de overall().
     * @return array Lista de ['metrica', 'sentido' (mejor o peor), 'valor', 'total'].
     */
    public static function differences(array $fila, array $total): array {
        $salida = [];
        foreach (self::METRICAS as $clave => $def) {
            $v = $fila['celdas'][$clave]['valor'];
            $t = $total['celdas'][$clave]['valor'];
            $sentido = self::compare($v, $t, $clave);
            if ($sentido !== 'mejor' && $sentido !== 'peor') {
                continue;
            }
            // Los comportamientos clave van primero: son los que el resumen debe decir antes que el detalle.
            $peso = ($def['tipo'] === 'pct' ? abs($v - $t) / 100 : abs($v - $t) / max(abs((float) $t), 1.0))
                + (in_array($clave, self::CLAVE, true) ? 100 : 0);
            $salida[] = ['metrica' => $clave, 'sentido' => $sentido, 'valor' => $v, 'total' => $t, 'peso' => $peso];
        }
        usort($salida, static function($a, $b) {
            return $b['peso'] <=> $a['peso'];
        });
        return $salida;
    }

    /**
     * Un porcentaje dicho como «X de cada 10». Funcion pura.
     *
     * @param int $pct
     * @return int|null 0 a 10; null si no hay dato.
     */
    public static function out_of_ten(?int $pct): ?int {
        return $pct === null ? null : (int) round($pct / 10);
    }

    /**
     * Mejor y peor valor visible de cada columna, para el color de las celdas. Funcion pura.
     *
     * @param array $cruce Salida de cross().
     * @return array metrica => ['min' => float, 'max' => float] (solo las columnas con dos valores distintos o mas).
     */
    public static function ranges(array $cruce): array {
        $rangos = [];
        foreach (array_keys(self::METRICAS) as $clave) {
            $valores = [];
            foreach ($cruce as $fila) {
                if ($fila['celdas'][$clave]['valor'] !== null) {
                    $valores[] = (float) $fila['celdas'][$clave]['valor'];
                }
            }
            if (count($valores) >= 2 && max($valores) > min($valores)) {
                $rangos[$clave] = ['min' => min($valores), 'max' => max($valores)];
            }
        }
        return $rangos;
    }

    /**
     * Posicion de un valor dentro de su columna: 1 el mejor, 0 el peor, segun lo que es mejor en la metrica. Funcion pura.
     *
     * @param float $valor
     * @param array $rango ['min', 'max'].
     * @param string $metrica
     * @return float
     */
    public static function score(float $valor, array $rango, string $metrica): float {
        $t = ($valor - $rango['min']) / ($rango['max'] - $rango['min']);
        return self::METRICAS[$metrica]['mejor'] === 'alto' ? $t : 1 - $t;
    }

    /**
     * Las diferencias mas grandes entre perfiles, en todas las preguntas, para decirlas en palabras. Funcion pura.
     *
     * Compara solo grupos con MINIMO_CONCLUSION personas con el dato y con respuesta concreta: «sin perfil», «sin
     * respuesta», «prefiero no responder» y «otro» no son un perfil que se pueda describir. Una diferencia cuenta si supera su umbral (UMBRALES); se ordenan por tamano relativo.
     *
     * @param array $personas Como en cross().
     * @param int $maximo Cuantas devolver.
     * @param int $minimo
     * @return array Lista de ['dim', 'metrica', 'mejor' => [respuesta, valor, n], 'peor' => [respuesta, valor, n], 'peso'].
     */
    public static function highlights(array $personas, int $maximo = 6, int $minimo = self::MINIMO_CONCLUSION): array {
        $salida = [];
        foreach (self::DIMENSIONES as $dim) {
            $cruce = self::cross($personas, $dim, $minimo);
            foreach (self::METRICAS as $clave => $def) {
                $visibles = [];
                foreach ($cruce as $respuesta => $fila) {
                    $c = $fila['celdas'][$clave];
                    if ($c['valor'] !== null && !in_array($respuesta, [self::SIN_PERFIL, self::SIN_RESPUESTA, 'nr', 'otro'], true)) {
                        $visibles[$respuesta] = ['valor' => (float) $c['valor'], 'n' => $c['n']];
                    }
                }
                if (count($visibles) < 2) {
                    continue;
                }
                uasort($visibles, static function($a, $b) {
                    return $a['valor'] <=> $b['valor'];
                });
                $bajo = array_key_first($visibles);
                $alto = array_key_last($visibles);
                $vb = $visibles[$bajo]['valor'];
                $va = $visibles[$alto]['valor'];
                $umbral = $def['tipo'] === 'pct' ? self::UMBRALES['pct'] : (self::UMBRALES[$clave] ?? 1);
                if ($va - $vb < $umbral) {
                    continue;
                }
                [$mejor, $peor] = $def['mejor'] === 'alto' ? [$alto, $bajo] : [$bajo, $alto];
                $peso = $def['tipo'] === 'pct' ? ($va - $vb) / 100 : ($va - $vb) / max(abs($va), 1.0);
                $salida[] = ['dim' => $dim, 'metrica' => $clave,
                    'mejor' => ['respuesta' => $mejor, 'valor' => $visibles[$mejor]['valor'], 'n' => $visibles[$mejor]['n']],
                    'peor' => ['respuesta' => $peor, 'valor' => $visibles[$peor]['valor'], 'n' => $visibles[$peor]['n']],
                    'peso' => round($peso, 3)];
            }
        }
        usort($salida, static function($a, $b) {
            return $b['peso'] <=> $a['peso'];
        });
        // Una sola conclusion por metrica (la de la pregunta que mas la separa), para que no se repita la misma idea.
        $vistas = [];
        $unicas = [];
        foreach ($salida as $h) {
            if (!isset($vistas[$h['metrica']])) {
                $vistas[$h['metrica']] = true;
                $unicas[] = $h;
            }
        }
        return array_slice($unicas, 0, $maximo);
    }

    /**
     * Cifras de cabecera. Funcion pura.
     *
     * @param array $personas Como en cross().
     * @return array inscritos, con_perfil, entregaron, terminaron.
     */
    public static function totals(array $personas): array {
        $t = ['inscritos' => count($personas), 'con_perfil' => 0, 'entregaron' => 0, 'terminaron' => 0];
        foreach ($personas as $p) {
            $t['con_perfil'] += ($p['perfil'] ?? null) !== null ? 1 : 0;
            $t['entregaron'] += !empty($p['metricas']['entrego']) ? 1 : 0;
            $t['terminaron'] += !empty($p['metricas']['termino']) ? 1 : 0;
        }
        return $t;
    }

    /**
     * Cohorte ficticia para mostrar el informe antes de tener datos (modo demostracion). Determinista: la misma semilla da
     * siempre las mismas personas. Las tendencias son inventadas y se dicen como tales en la pagina. Funcion pura.
     *
     * @param int $n
     * @param int $semilla
     * @return array Lista de ['perfil', 'metricas'] como en cross().
     */
    public static function demo(int $n = 60, int $semilla = 20261103): array {
        mt_srand($semilla);
        $azar = static function(): float {
            return mt_rand() / mt_getrandmax();
        };
        $elegir = static function(array $opciones, array $pesos) use ($azar): string {
            $r = $azar() * array_sum($pesos);
            foreach ($opciones as $i => $o) {
                $r -= $pesos[$i];
                if ($r <= 0) {
                    return $o;
                }
            }
            return end($opciones);
        };
        $base = ['menos1' => 0.25, 'entre1y3' => 0.45, 'entre4y7' => 0.6, 'mas7' => 0.75, 'nr' => 0.5];
        $fam = ['nada' => -0.15, 'poco' => -0.07, 'mediana' => 0.05, 'bastante' => 0.12, 'nr' => 0];
        $tiempo = ['menos1' => -0.12, 'entre1y2' => -0.03, 'entre3y5' => 0.05, 'mas5' => 0.1];
        $personas = [];
        for ($i = 0; $i < $n; $i++) {
            if ($azar() < 0.07) {
                $perfil = null;
                $k = 0.4;
            } else {
                $o = profile::get_options();
                $perfil = [
                    'rol' => $elegir($o['rol'], [4, 3, 3, 1, 1]),
                    'programa' => $elegir($o['programa'], [4, 3, 2, 2, 1]),
                    'modalidad' => $elegir($o['modalidad'], [6, 2, 3]),
                    'experiencia' => $elegir($o['experiencia'], [3, 4, 3, 3, 1]),
                    'familiaridad' => $elegir($o['familiaridad'], [2, 4, 4, 2, 1]),
                    'tiempo' => $elegir($o['tiempo'], [2, 4, 3, 2]),
                ];
                $f1 = $elegir($o['fortalecer'], [3, 3, 4, 4, 2, 3, 1, 1]);
                $f2 = $elegir($o['fortalecer'], [3, 3, 4, 4, 2, 3, 1, 1]);
                $perfil['fortalecer'] = implode(',', array_unique([$f1, $f2]));
                $k = $base[$perfil['experiencia']] + $fam[$perfil['familiaridad']] + $tiempo[$perfil['tiempo']]
                    + ($perfil['modalidad'] === 'virtual' ? -0.05 : 0);
            }
            $k = max(0.05, min(0.95, $k + ($azar() - 0.5) * 0.2));
            $m = array_fill_keys(array_keys(self::METRICAS), null);
            $m['entrego'] = $azar() < 0.55 + 0.4 * $k;
            if ($m['entrego']) {
                $m['dias_inicio'] = round(1 + (1 - $k) * 9 * $azar() + (1 - $k) * 3, 1);
                $m['uso_guia'] = $azar() < 0.35 + 0.5 * $k;
                $m['completo'] = $azar() < 0.3 + 0.6 * $k;
                $m['aprob_primer'] = $azar() < 0.15 + 0.75 * $k;
                $m['criticos'] = !$m['aprob_primer'] || $azar() < 0.2 * (1 - $k);
                $m['ciclos'] = $m['aprob_primer'] ? 1 : 2 + (int) ($azar() < 0.5 * (1 - $k)) + (int) ($azar() < 0.2);
                if (!$m['aprob_primer']) {
                    $m['dias_correccion'] = round(1 + (1 - $k) * 8 * $azar() + 1, 1);
                }
                $m['diseno'] = $azar() < 0.5 + 0.45 * $k;
                if ($m['diseno']) {
                    $m['rondas'] = (int) round((1 - $k) * 6 * $azar() + 1);
                    $m['termino'] = $azar() < 0.35 + 0.55 * $k;
                    if ($m['termino']) {
                        $m['duracion'] = round(8 + (1 - $k) * 18 + 6 * $azar(), 1);
                        $m['puntaje'] = (int) max(9, min(36, round(18 + 16 * $k + ($azar() - 0.5) * 6)));
                        $m['encuesta'] = round(max(1, min(5, 2.6 + 2 * $k + ($azar() - 0.5))), 1);
                    }
                } else {
                    $m['termino'] = false;
                }
                $m['detenido'] = !$m['termino'] && $azar() < 0.7;
            }
            $personas[] = ['userid' => -($i + 1), 'perfil' => $perfil, 'metricas' => $m];
        }
        mt_srand();
        return $personas;
    }

    /**
     * Cursos del Modulo 1 (los que tienen actividades del recorrido): de ahi sale el perfil.
     *
     * @return int[]
     */
    public static function m1_courses(): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_sql("SELECT DISTINCT course FROM {course_modules}
            WHERE idnumber LIKE 'M1-PASO-%' AND deletioninprogress = 0"));
    }

    /**
     * Cursos del Modulo 2: los que tienen fichas y el configurado para el boton del perfil (aperturam2).
     *
     * @return array id => nombre.
     */
    public static function m2_courses(): array {
        global $DB;
        $ids = array_map('intval', $DB->get_fieldset_sql("SELECT DISTINCT courseid FROM {local_chatbot_exante} WHERE courseid > 1"));
        $config = (int) get_config('local_chatbot', 'aperturam2');
        if ($config > 1) {
            $ids[] = $config;
        }
        $cursos = [];
        foreach (array_unique($ids) as $id) {
            $nombre = $DB->get_field('course', 'fullname', ['id' => $id]);
            if ($nombre !== false) {
                $cursos[$id] = format_string($nombre);
            }
        }
        return $cursos;
    }

    /**
     * Personas inscritas en un curso del Modulo 2, con su perfil del Modulo 1 y su comportamiento en el Modulo 2.
     *
     * El perfil cuenta solo con el consentimiento vigente (profile::consent_is_current()): sin el, la persona va al grupo
     * «sin perfil». Con varios perfiles (varios cursos del Modulo 1) cuenta el ultimo modificado.
     *
     * @param int $courseid Curso del Modulo 2.
     * @param int $desde Comienzo del periodo (0 = todo). Con periodo cuentan solo quienes tuvieron actividad en sus
     *                   fichas desde esa fecha: quien no la tuvo no «dejo de entregar», simplemente no esta en el periodo.
     * @return array Lista de ['userid', 'perfil', 'metricas'].
     */
    public static function load(int $courseid, int $desde = 0): array {
        global $DB;
        $inscritos = tablero_m2::enrolled($courseid);
        if (!$inscritos) {
            return [];
        }
        $porpersona = [];
        foreach (tablero_m2::in_period(tablero_m2::load_facts($courseid, $inscritos), $desde) as $h) {
            $porpersona[$h['userid']][] = $h;
        }
        if ($desde > 0) {
            $inscritos = array_values(array_intersect($inscritos, array_keys($porpersona)));
        }
        $perfiles = [];
        $m1 = self::m1_courses();
        if ($m1) {
            [$incursos, $p1] = $DB->get_in_or_equal($m1, SQL_PARAMS_NAMED, 'c');
            [$inusers, $p2] = $DB->get_in_or_equal($inscritos, SQL_PARAMS_NAMED, 'u');
            foreach ($DB->get_records_select('local_chatbot_profile', "courseid $incursos AND userid $inusers", $p1 + $p2,
                    'timemodified ASC') as $p) {
                if ((int) $p->consentaccepted === 1 && profile::consent_is_current($p)) {
                    $perfiles[(int) $p->userid] = ['rol' => $p->rol ?? '', 'programa' => $p->programa ?? '',
                        'modalidad' => $p->modalidad ?? '', 'experiencia' => $p->experiencia ?? '',
                        'familiaridad' => $p->familiaridad ?? '', 'tiempo' => $p->tiempo ?? '',
                        'fortalecer' => $p->fortalecer ?? ''];
                }
            }
        }
        $now = time();
        $plazos = tablero_m2::plazos();
        $personas = [];
        foreach ($inscritos as $uid) {
            $personas[] = ['userid' => $uid, 'perfil' => $perfiles[$uid] ?? null,
                'metricas' => self::person($porpersona[$uid] ?? [], $now, $plazos)];
        }
        return $personas;
    }
}
