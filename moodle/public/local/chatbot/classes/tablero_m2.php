<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Tablero del Modulo 2 («Propuesta de dashboard - Indicadores, vistas y uso», Interfases v3, 30-10-2026).
 *
 * Tres vistas: individual (cada persona facilitadora, solo con su informacion y con avisos automaticos), agregada
 * (coordinacion del programa, sin seguimiento individual) y calidad del agente (administracion del asistente, con la
 * revision experta de una muestra). Los indicadores salen de lo que ya guarda el asistente (validaciones de la Etapa 0,
 * etapas del diseno, evaluacion de la Etapa 8), de los registros de Moodle (primer acceso al curso, consulta de la guia
 * y del ejemplo) y de tres registros propios: los envios de la ficha al control de entrada, la revision experta y la
 * encuesta de cierre.
 *
 * El calculo es puro: facts() recibe los datos de una ficha ya leidos y devuelve sus hechos; program(), agent() y
 * notices() trabajan sobre esos hechos. La lectura de la base va aparte (load_*), como en el resto del plugin.
 *
 * No es calificacion: nada de esto toca el libro de calificaciones.
 */
class tablero_m2 {

    /** @var int Puntaje desde el que la rubrica dice «alto estandar tecnico» (techo bajo: menor que esto). */
    const ALTO_ESTANDAR = 32;

    /** @var int Puntaje maximo de la rubrica. */
    const MAXIMO = 36;

    /** @var int Dias por defecto de «Corrección pendiente» (los plazos «X» los define el cliente: ajuste del plugin). */
    const PLAZO_CORRECCION = 7;

    /** @var int Dias por defecto de «Proceso detenido». */
    const PLAZO_DETENIDO = 7;

    /** @var string Preferencia de usuario donde se guarda cuando se envio cada aviso. */
    const PREFERENCIA = 'local_chatbot_avisos_m2';

    /** @var string[] Avisos que se repiten cada tantos dias como su plazo mientras sigan activos. */
    const REPETIBLES = ['correccion', 'detenido'];

    /** @var int[] Grupos de observaciones no resueltas del indicador F (0; 1 a 2; 3 o mas). */
    const TRAMOS_NO_RESUELTAS = ['0' => 0, '1-2' => 1, '3+' => 3];

    /**
     * Avisos automaticos de la vista individual, con el texto de la propuesta del cliente.
     *
     * @return array tipo => ['nombre', 'cuando', 'sugerencia'].
     */
    public static function avisos(): array {
        return [
            'correccion' => ['nombre' => 'Corrección pendiente',
                'cuando' => 'Más de {X} días sin reenviar la ficha después de un ❌ No aprobado.',
                'sugerencia' => 'Retomar la corrección indicada en la Etapa 0.'],
            'critico' => ['nombre' => 'Ítem CRÍTICO observado',
                'cuando' => 'Cualquier ⚠ o ❌ en un ítem CRÍTICO del checklist.',
                'sugerencia' => 'Revisar la correspondencia de la ficha con el plan formativo.'],
            'noresueltas' => ['nombre' => 'Observaciones no resueltas',
                'cuando' => 'Se inicia el diseño con ítems ⚠ o ❌ sin corregir.',
                'sugerencia' => 'Corregir la ficha antes de diseñar o asumir que los criterios vinculados quedarán '
                    . 'limitados a 3.'],
            'techo' => ['nombre' => 'Techo bajo',
                'cuando' => 'Puntaje máximo alcanzable menor que 32.',
                'sugerencia' => 'Entender por qué el diseño no puede alcanzar «alto estándar técnico».'],
            'c2nivel1' => ['nombre' => 'Criterio 2 en nivel 1',
                'cuando' => 'Puntaje 1 en alineación con aprendizaje esperado y criterios.',
                'sugerencia' => 'Revisar que los criterios se usen sin modificaciones ni omisiones.'],
            'detenido' => ['nombre' => 'Proceso detenido',
                'cuando' => 'Más de {X} días sin avanzar de etapa.',
                'sugerencia' => 'Retomar el proceso en la etapa en que quedó.'],
        ];
    }

    /**
     * Tipos de incidencia de la revision experta (indicador G, «Errores de aplicación de reglas»). La propuesta del
     * cliente pide contarlas «por tipo» sin fijar los tipos: estos salen de las reglas que el asistente aplica.
     *
     * @return string[] codigo => texto.
     */
    public static function incidencias(): array {
        return [
            'resultado' => 'Resultado de ítem o nivel de criterio que no corresponde a su descriptor',
            'critico' => 'Regla de ítems CRÍTICO o diferencia de forma mal aplicada',
            'limite' => 'Límite por observaciones no resueltas mal aplicado',
            'evidencia' => 'Observación o justificación sin evidencia del documento',
            'inventa' => 'Información que no está en la ficha ni en el plan formativo',
            'recomendacion' => 'Sugerencias o recomendaciones genéricas o no accionables',
            'otro' => 'Otra incidencia',
        ];
    }

    /**
     * Preguntas de la encuesta de cierre (indicador H, «Utilidad y claridad percibidas», escala 1 a 5). La propuesta
     * no trae el texto de la encuesta: estas cuatro miden utilidad y claridad de los instrumentos y del asistente.
     *
     * @return array codigo => ['texto', 'dimension' (utilidad|claridad)].
     */
    public static function encuesta(): array {
        return [
            'p1' => ['texto' => 'El asistente me fue útil para diseñar la experiencia de aprendizaje.',
                'dimension' => 'utilidad'],
            'p2' => ['texto' => 'Las observaciones y propuestas del asistente fueron claras.', 'dimension' => 'claridad'],
            'p3' => ['texto' => 'La ficha, la guía y el ejemplo me fueron útiles para el diagnóstico.',
                'dimension' => 'utilidad'],
            'p4' => ['texto' => 'Los resultados de la validación (Etapa 0) y de la evaluación (Etapa 8) fueron claros.',
                'dimension' => 'claridad'],
        ];
    }

    /**
     * Respuestas validas de la encuesta, o null si falta alguna. Funcion pura.
     *
     * @param array $raw codigo => valor.
     * @return int[]|null
     */
    public static function clean_survey(array $raw): ?array {
        $salida = [];
        foreach (array_keys(self::encuesta()) as $codigo) {
            $v = $raw[$codigo] ?? null;
            if (!is_numeric($v) || (int) $v < 1 || (int) $v > 5 || (float) $v !== (float) (int) $v) {
                return null;
            }
            $salida[$codigo] = (int) $v;
        }
        return $salida;
    }

    /**
     * Mediana. Funcion pura.
     *
     * @param float[] $valores
     * @return float|null
     */
    public static function median(array $valores): ?float {
        $valores = array_values(array_map('floatval', $valores));
        $n = count($valores);
        if ($n === 0) {
            return null;
        }
        sort($valores);
        $medio = intdiv($n, 2);
        return $n % 2 ? $valores[$medio] : ($valores[$medio - 1] + $valores[$medio]) / 2;
    }

    /**
     * Promedio con un decimal. Funcion pura.
     *
     * @param float[] $valores
     * @return float|null
     */
    public static function mean(array $valores): ?float {
        return $valores ? round(array_sum($valores) / count($valores), 1) : null;
    }

    /**
     * Vista a la que lleva el boton flotante de indicadores en un curso del Modulo 2 (pedido del cliente del 02-11: «igual
     * que el Modulo 1»): coordinacion al «Programa», quien tiene una ficha a «Mi avance»; null si no corresponde boton.
     * Funcion pura.
     *
     * @param bool $cursom2 El curso tiene fichas del Modulo 2.
     * @param bool $analitica Permiso local/chatbot:viewanalytics.
     * @param bool $conficha La persona tiene una ficha en el curso (y permiso de uso).
     * @return string|null programa, persona o null.
     */
    public static function fab_view(bool $cursom2, bool $analitica, bool $conficha): ?string {
        if (!$cursom2) {
            return null;
        }
        return $analitica ? 'programa' : ($conficha ? 'persona' : null);
    }

    /** @var int[] Periodos del filtro de la vista «Programa», en dias (pedido del cliente del 02-11). */
    const PERIODOS = ['semana' => 7, 'mes' => 30, 'semestre' => 182, 'anio' => 365];

    /**
     * Comienzo del periodo del filtro: hace 7, 30, 182 o 365 dias; 0 para «todo» o un valor desconocido. Funcion pura.
     *
     * @param string $periodo semana, mes, semestre, anio o todo.
     * @param int $now
     * @return int
     */
    public static function period_start(string $periodo, int $now): int {
        return isset(self::PERIODOS[$periodo]) ? $now - self::PERIODOS[$periodo] * 86400 : 0;
    }

    /**
     * Fichas con actividad dentro del periodo (envio al control de entrada, validacion o trabajo en una etapa desde
     * $desde). Con $desde = 0, todas. Funcion pura.
     *
     * @param array $hechos De facts(), por ficha.
     * @param int $desde
     * @return array
     */
    public static function in_period(array $hechos, int $desde): array {
        if ($desde <= 0) {
            return $hechos;
        }
        return array_filter($hechos, static function(array $h) use ($desde): bool {
            return (int) ($h['actividad'] ?? 0) >= $desde;
        });
    }

    /**
     * Porcentaje entero. Funcion pura.
     *
     * @param int $parte
     * @param int $total
     * @return int|null null si no hay sobre que calcularlo.
     */
    public static function pct(int $parte, int $total): ?int {
        return $total > 0 ? (int) round($parte * 100 / $total) : null;
    }

    /**
     * Dias entre dos marcas de tiempo, con un decimal. Funcion pura.
     *
     * @param int $desde
     * @param int $hasta
     * @return float
     */
    public static function days(int $desde, int $hasta): float {
        return round(max(0, $hasta - $desde) / 86400, 1);
    }

    /**
     * Hechos de una ficha (un diseno) para todos los indicadores. Funcion pura.
     *
     * @param array $d Datos ya leidos:
     *   ficha: ['id', 'userid', 'organismo', 'activa'];
     *   validaciones: lista por id de ['id', 'estado', 'condicion', 'items' (id => ['estado', 'forma']), 'time'];
     *   envios: lista por fecha de ['completo' (bool), 'faltantes' (string[]), 'plan' (bool), 'perfil' (bool), 'time'];
     *   etapas: n => ['estado', 'inicio', 'cierre', 'rondas', 'validacionid'] (las filas de local_chatbot_diseno);
     *   aceptadas: etapas aceptadas y vigentes (diseno::accepted());
     *   evaluacion: evaluacion vigente (diseno::current_evaluation()) o null;
     *   primer_acceso: primer ingreso al curso o null; vistas: ['guia' => ts|null, 'ejemplo' => ts|null];
     *   actividad: ultima actividad en la ficha o el diseno; encuesta: respuestas o null.
     * @return array
     */
    public static function facts(array $d): array {
        $ficha = $d['ficha'];
        $h = ['fichaid' => (int) $ficha['id'], 'userid' => (int) $ficha['userid'],
            'organismo' => trim((string) ($ficha['organismo'] ?? '')), 'activa' => !empty($ficha['activa']),
            'etiqueta' => (string) ($ficha['etiqueta'] ?? '')];

        // --- Envios al control de entrada ---
        $envios = array_values($d['envios'] ?? []);
        $h['envios'] = count($envios);
        $h['primer_envio_completo'] = $envios ? !empty($envios[0]['completo']) : null;
        $h['plan_primer_envio'] = $envios ? !empty($envios[0]['plan']) : null;
        $h['perfil_primer_envio'] = $envios ? !empty($envios[0]['perfil']) : null;
        $h['envios_incompletos'] = 0;
        $h['faltantes'] = [];
        foreach ($envios as $e) {
            if (!empty($e['completo'])) {
                continue;
            }
            $h['envios_incompletos']++;
            foreach (array_unique((array) ($e['faltantes'] ?? [])) as $campo) {
                $h['faltantes'][(string) $campo] = ($h['faltantes'][(string) $campo] ?? 0) + 1;
            }
        }

        // --- Validaciones (Etapa 0): un ciclo por validacion terminada ---
        $validaciones = array_values($d['validaciones'] ?? []);
        $porid = [];
        $ciclos = [];
        foreach ($validaciones as $v) {
            $porid[(int) $v['id']] = $v;
            if (($v['estado'] ?? '') === exante::LISTO && $v['condicion']) {
                $ciclos[] = $v;
            }
        }
        $tiempos = array_merge(array_column($envios, 'time'), array_column($validaciones, 'time'));
        $h['primera_entrega'] = $tiempos ? (int) min($tiempos) : null;
        $h['ciclos'] = array_map(static function($v) {
            return ['id' => (int) $v['id'], 'condicion' => (string) $v['condicion'], 'time' => (int) $v['time']];
        }, $ciclos);
        $h['decision_primera'] = $ciclos ? (string) $ciclos[0]['condicion'] : null;
        $ultimo = $ciclos ? end($ciclos) : null;
        $h['decision_final'] = $ultimo ? (string) $ultimo['condicion'] : null;
        $h['validacion_final'] = $ultimo ? (int) $ultimo['id'] : null;
        $h['ciclos_aprobacion'] = null;
        foreach ($ciclos as $i => $v) {
            if (exante::can_advance($v['condicion'])) {
                $h['ciclos_aprobacion'] = $i + 1;
                break;
            }
        }
        // Tiempo de correccion: del ❌ No aprobado al reenvio de la ficha (el primer envio despues, pase o no el control
        // de entrada; antes de v1.21.0 no hay envios registrados y cuenta la validacion siguiente).
        $h['correcciones'] = [];
        $h['correccion_pendiente'] = null;
        foreach ($validaciones as $i => $v) {
            if (($v['estado'] ?? '') !== exante::LISTO || $v['condicion'] !== exante::NO_APROBADO) {
                continue;
            }
            $despues = array_filter(array_merge(array_column($envios, 'time'),
                array_column(array_slice($validaciones, $i + 1), 'time')), static function($t) use ($v) {
                    return (int) $t > (int) $v['time'];
                });
            if ($despues) {
                $h['correcciones'][] = self::days((int) $v['time'], (int) min($despues));
            } else if (!isset($validaciones[$i + 1])) {
                $h['correccion_pendiente'] = (int) $v['time'];
            }
        }
        $h['items'] = [];
        foreach (($ultimo['items'] ?? []) as $id => $item) {
            $h['items'][$id] = (string) ($item['estado'] ?? '');
        }
        $h['criticos_obs'] = [];
        foreach (exante::CRITICOS as $id) {
            if (isset($h['items'][$id]) && $h['items'][$id] !== exante::CUMPLE) {
                $h['criticos_obs'][] = $id;
            }
        }
        // Por ciclo, para los porcentajes agregados (resultado por item e items CRITICO).
        $h['val_items'] = [];
        $h['val_criticos'] = [];
        foreach ($ciclos as $v) {
            $estados = [];
            $parciales = 0;
            $forma = 0;
            $obs = false;
            foreach ((array) $v['items'] as $id => $item) {
                $estado = (string) ($item['estado'] ?? '');
                $estados[$id] = $estado;
                if (in_array($id, exante::CRITICOS, true) && $estado !== exante::CUMPLE) {
                    $obs = true;
                    if ($estado === exante::PARCIAL) {
                        $parciales++;
                        $forma += !empty($item['forma']) ? 1 : 0;
                    }
                }
            }
            $h['val_items'][] = $estados;
            $h['val_criticos'][] = ['obs' => $obs, 'parciales' => $parciales, 'forma' => $forma];
        }

        // --- Diseno (etapas 1 a 8) ---
        $etapas = $d['etapas'] ?? [];
        $aceptadas = array_map('intval', $d['aceptadas'] ?? []);
        $evaluacion = $d['evaluacion'] ?? null;
        $h['etapas'] = [];
        for ($n = 1; $n <= diseno::EVALUACION; $n++) {
            $fila = $etapas[$n] ?? null;
            $cerrada = $n === diseno::EVALUACION ? $evaluacion !== null : in_array($n, $aceptadas, true);
            $cierre = $n === diseno::EVALUACION ? (int) ($evaluacion['fecha'] ?? 0) : (int) ($fila['cierre'] ?? 0);
            $h['etapas'][$n] = ['alcanzada' => $fila !== null, 'cerrada' => $cerrada,
                'rondas' => (int) ($fila['rondas'] ?? 0),
                'dias' => $fila && $cerrada && $cierre > 0 ? self::days((int) $fila['inicio'], $cierre) : null];
        }
        // Observaciones no resueltas al iniciar la Etapa 1 y techo que dejan: la validacion que habilitaba la Fase 2
        // cuando se abrio la etapa (validacionid de la fila cambia con cada ajuste pedido; queda solo de respaldo).
        $base = null;
        if (isset($etapas[1])) {
            foreach ($ciclos as $v) {
                if ((int) $v['time'] <= (int) $etapas[1]['inicio'] && exante::can_advance($v['condicion'])) {
                    $base = $v;
                }
            }
            $base = $base ?? ($porid[(int) ($etapas[1]['validacionid'] ?? 0)] ?? null);
        }
        $h['no_resueltas'] = null;
        $h['no_resueltas_secciones'] = [];
        $h['limitados_previstos'] = [];
        $h['techo_previsto'] = null;
        $h['validacion_diseno'] = $base ? (int) $base['id'] : null;
        if ($base) {
            $pendientes = exante::unresolved_items((array) $base['items']);
            $h['no_resueltas'] = count($pendientes);
            foreach ($pendientes as $p) {
                $h['no_resueltas_secciones'][$p['seccion']] = ($h['no_resueltas_secciones'][$p['seccion']] ?? 0) + 1;
            }
            $h['limitados_previstos'] = diseno::rubric_limits(['items' => (array) $base['items']]);
            $h['techo_previsto'] = self::MAXIMO - count($h['limitados_previstos']);
        }
        // Decision de la Etapa 0 con que se hizo el diseno evaluado (la de la Etapa 8, o la de la Etapa 1).
        $baseeval = isset($etapas[diseno::EVALUACION]['validacionid'])
            ? ($porid[(int) $etapas[diseno::EVALUACION]['validacionid']] ?? null) : null;
        $baseeval = $baseeval ?? $base;
        $h['decision_diseno'] = $baseeval ? (string) $baseeval['condicion'] : null;
        $h['no_resueltas_diseno'] = $baseeval ? count(exante::unresolved_items((array) $baseeval['items'])) : null;

        // --- Evaluacion (Etapa 8) ---
        $h['evaluacion'] = null;
        if ($evaluacion && isset($evaluacion['total'])) {
            $criterios = [];
            foreach ((array) ($evaluacion['criterios'] ?? []) as $id => $c) {
                $criterios[$id] = (int) (is_array($c) ? ($c['puntaje'] ?? 0) : $c);
            }
            $limitados = array_values((array) ($evaluacion['limitados'] ?? []));
            $maximo = (int) ($evaluacion['maximo'] ?? (self::MAXIMO - count($limitados)));
            $total = (int) $evaluacion['total'];
            $nivel1 = array_keys(array_filter($criterios, static function($p) {
                return $p === 1;
            }));
            $h['evaluacion'] = ['total' => $total, 'maximo' => $maximo, 'brecha' => max(0, $maximo - $total),
                'banda' => diseno::band($total)['clave'], 'criterios' => $criterios, 'limitados' => $limitados,
                'nivel1' => $nivel1, 'time' => (int) ($evaluacion['fecha'] ?? 0)];
        }

        // --- Uso de la guia y el ejemplo, primer acceso, duracion, actividad y encuesta ---
        $vistas = $d['vistas'] ?? [];
        foreach (['guia', 'ejemplo'] as $m) {
            $t = isset($vistas[$m]) ? (int) $vistas[$m] : 0;
            $h['vista_' . $m] = $t > 0 ? $t : null;
        }
        $h['primer_acceso'] = !empty($d['primer_acceso']) ? (int) $d['primer_acceso'] : null;
        $h['duracion'] = $h['evaluacion'] && $h['primera_entrega'] && $h['evaluacion']['time'] > 0
            ? self::days($h['primera_entrega'], $h['evaluacion']['time']) : null;
        $h['actividad'] = (int) ($d['actividad'] ?? 0);
        $h['encuesta'] = $d['encuesta'] ?? null;
        return $h;
    }

    /**
     * Etapa en que esta la ficha: 0 (diagnostico) hasta su aprobacion; despues la primera etapa del diseno sin
     * cerrar; 9 si el diseno ya fue evaluado. Funcion pura.
     *
     * @param array $h Hechos de facts().
     * @return int
     */
    public static function current_stage(array $h): int {
        if ($h['ciclos_aprobacion'] === null || !exante::can_advance($h['decision_final'])) {
            return 0;
        }
        foreach ($h['etapas'] as $n => $e) {
            if (!$e['cerrada']) {
                return (int) $n;
            }
        }
        return 9;
    }

    /**
     * Avisos activos de una ficha, con el criterio de la propuesta del cliente. Funcion pura.
     *
     * @param array $h Hechos de facts().
     * @param int $now
     * @param array $plazos ['correccion' => dias, 'detenido' => dias].
     * @return array Lista de ['tipo', 'clave' (unica por asunto), 'a' (datos para el texto)].
     */
    public static function notices(array $h, int $now, array $plazos): array {
        $dia = 86400;
        $avisos = [];
        $fid = $h['fichaid'];
        $pc = max(1, (int) ($plazos['correccion'] ?? self::PLAZO_CORRECCION));
        $pd = max(1, (int) ($plazos['detenido'] ?? self::PLAZO_DETENIDO));
        if ($h['correccion_pendiente'] !== null && $now - $h['correccion_pendiente'] > $pc * $dia) {
            $avisos[] = ['tipo' => 'correccion', 'clave' => 'correccion-' . $h['validacion_final'],
                'a' => ['dias' => (int) floor(($now - $h['correccion_pendiente']) / $dia)]];
        }
        if ($h['criticos_obs']) {
            $avisos[] = ['tipo' => 'critico', 'clave' => 'critico-' . $h['validacion_final'],
                'a' => ['items' => $h['criticos_obs']]];
        }
        if ($h['etapas'][1]['alcanzada'] && (int) $h['no_resueltas'] > 0) {
            $avisos[] = ['tipo' => 'noresueltas', 'clave' => 'noresueltas-' . $h['validacion_diseno'],
                'a' => ['n' => (int) $h['no_resueltas'], 'criterios' => array_keys($h['limitados_previstos'])]];
        }
        $techo = $h['evaluacion'] ? $h['evaluacion']['maximo']
            : ($h['etapas'][1]['alcanzada'] ? $h['techo_previsto'] : null);
        if ($techo !== null && $techo < self::ALTO_ESTANDAR) {
            $avisos[] = ['tipo' => 'techo', 'clave' => 'techo-' . $fid . '-' . $techo, 'a' => ['techo' => $techo]];
        }
        if ($h['evaluacion'] && ($h['evaluacion']['criterios']['r2'] ?? 0) === 1) {
            $avisos[] = ['tipo' => 'c2nivel1', 'clave' => 'c2nivel1-' . $fid . '-' . $h['evaluacion']['time'], 'a' => []];
        }
        // Detenido: empezo y no termino, sin una correccion pendiente (que ya tiene su aviso).
        $etapa = self::current_stage($h);
        if ($h['primera_entrega'] !== null && $etapa < 9 && $h['correccion_pendiente'] === null
                && $h['actividad'] > 0 && $now - $h['actividad'] > $pd * $dia) {
            $avisos[] = ['tipo' => 'detenido', 'clave' => 'detenido-' . $fid . '-' . $etapa,
                'a' => ['etapa' => $etapa, 'dias' => (int) floor(($now - $h['actividad']) / $dia)]];
        }
        return $avisos;
    }

    /**
     * Avisos que corresponde enviar ahora: los que nunca se enviaron, y los repetibles cuando ya paso su plazo desde
     * el ultimo envio. Funcion pura.
     *
     * @param array $avisos Salida de notices().
     * @param array $enviados clave => ultima vez.
     * @param int $now
     * @param array $plazos ['correccion' => dias, 'detenido' => dias].
     * @return array
     */
    public static function due(array $avisos, array $enviados, int $now, array $plazos): array {
        return array_values(array_filter($avisos, static function($a) use ($enviados, $now, $plazos) {
            if (!isset($enviados[$a['clave']])) {
                return true;
            }
            if (!in_array($a['tipo'], self::REPETIBLES, true)) {
                return false;
            }
            $plazo = max(1, (int) ($plazos[$a['tipo']] ?? self::PLAZO_DETENIDO));
            return $now - (int) $enviados[$a['clave']] >= $plazo * 86400;
        }));
    }

    /**
     * Registro de avisos enviados: solo los asuntos que siguen activos, con la fecha del ultimo envio. Un aviso de una
     * vez sigue registrado mientras este activo, por viejo que sea (no se repite); uno que dejo de estar activo se olvida,
     * y asi el registro queda chico (cabe en una preferencia de usuario). Funcion pura.
     *
     * @param array $enviados clave => ultima vez.
     * @param array $recien Avisos que se acaban de enviar.
     * @param array $activos Avisos activos ahora (salida de notices()).
     * @param int $now
     * @return array
     */
    public static function record(array $enviados, array $recien, array $activos, int $now): array {
        foreach ($recien as $a) {
            $enviados[$a['clave']] = $now;
        }
        return array_intersect_key($enviados, array_flip(array_column($activos, 'clave')));
    }

    /**
     * Indicadores agregados del programa (grupos A a F y H). Sin nombres: solo conteos, porcentajes, promedios y
     * medianas. Funcion pura.
     *
     * Las fichas de quienes no estan inscritos como participantes (pruebas del equipo) cuentan en los indicadores de
     * las fichas, pero no en la tasa de inicio, que es sobre las personas inscritas; se informan en «externos».
     *
     * @param array $hechos Lista de facts() de las fichas del curso (o del grupo).
     * @param int[] $inscritos Personas inscritas que cursan el modulo (userid).
     * @return array
     */
    public static function program(array $hechos, array $inscritos): array {
        $inscritos = array_flip(array_map('intval', $inscritos));
        $r = ['inscritos' => count($inscritos), 'fichas' => count($hechos)];

        // --- A. Uso y adopcion (por persona) ---
        $personas = [];
        foreach ($hechos as $h) {
            $p = $personas[$h['userid']] ?? ['entrega' => null, 'guia' => null, 'ejemplo' => null, 'acceso' => null];
            if ($h['primera_entrega'] !== null) {
                $p['entrega'] = $p['entrega'] === null ? $h['primera_entrega'] : min($p['entrega'], $h['primera_entrega']);
            }
            $p['guia'] = $p['guia'] ?? $h['vista_guia'];
            $p['ejemplo'] = $p['ejemplo'] ?? $h['vista_ejemplo'];
            $p['acceso'] = $p['acceso'] ?? $h['primer_acceso'];
            $personas[$h['userid']] = $p;
        }
        $conentrega = array_filter($personas, static function($p) {
            return $p['entrega'] !== null;
        });
        $antes = static function($t, $limite) {
            return $t !== null && $t <= $limite;
        };
        $consultaron = 0;
        $guia = 0;
        $ejemplo = 0;
        $dias = [];
        foreach ($conentrega as $p) {
            $g = $antes($p['guia'], $p['entrega']);
            $e = $antes($p['ejemplo'], $p['entrega']);
            $guia += $g ? 1 : 0;
            $ejemplo += $e ? 1 : 0;
            $consultaron += $g && $e ? 1 : 0;
            if ($p['acceso'] !== null && $p['acceso'] <= $p['entrega']) {
                $dias[] = self::days($p['acceso'], $p['entrega']);
            }
        }
        $inscritosentrega = count(array_intersect_key($conentrega, $inscritos));
        $r['externos'] = count(array_diff_key($personas, $inscritos));
        $r['a'] = ['personas_entrega' => $inscritosentrega,
            'tasa_inicio' => self::pct($inscritosentrega, count($inscritos)),
            'uso_guia_ejemplo' => self::pct($consultaron, count($conentrega)),
            'uso_guia' => self::pct($guia, count($conentrega)), 'uso_ejemplo' => self::pct($ejemplo, count($conentrega)),
            'dias_primera' => self::median($dias)];

        // --- B. Control de entrada ---
        $conenvios = array_filter($hechos, static function($h) {
            return $h['envios'] > 0;
        });
        $incompletos = 0;
        $faltantes = [];
        $completas = 0;
        $plan = 0;
        $perfil = 0;
        foreach ($conenvios as $h) {
            $completas += $h['primer_envio_completo'] ? 1 : 0;
            $plan += $h['plan_primer_envio'] ? 1 : 0;
            $perfil += $h['perfil_primer_envio'] ? 1 : 0;
            $incompletos += $h['envios_incompletos'];
            foreach ($h['faltantes'] as $campo => $n) {
                $faltantes[$campo] = ($faltantes[$campo] ?? 0) + $n;
            }
        }
        arsort($faltantes);
        $r['b'] = ['fichas_envio' => count($conenvios), 'primer_intento' => self::pct($completas, count($conenvios)),
            'envios_incompletos' => $incompletos,
            'faltantes' => array_map(static function($n) use ($incompletos) {
                return ['n' => $n, 'pct' => self::pct($n, $incompletos)];
            }, $faltantes),
            'plan' => self::pct($plan, count($conenvios)), 'perfil' => self::pct($perfil, count($conenvios))];

        // --- C. Validacion del diagnostico (Etapa 0) ---
        $validadas = array_filter($hechos, static function($h) {
            return $h['ciclos'] !== [];
        });
        $conds = [exante::APROBADO => 0, exante::OBSERVACIONES => 0, exante::NO_APROBADO => 0];
        $primera = $conds;
        $final = $conds;
        $ciclosaprob = [];
        $correcciones = [];
        $items = [];
        $nval = 0;
        $valcriticos = 0;
        $parciales = 0;
        $forma = 0;
        $otec = [];
        foreach ($validadas as $h) {
            $primera[$h['decision_primera']] = ($primera[$h['decision_primera']] ?? 0) + 1;
            $final[$h['decision_final']] = ($final[$h['decision_final']] ?? 0) + 1;
            if ($h['ciclos_aprobacion'] !== null) {
                $ciclosaprob[] = $h['ciclos_aprobacion'];
            }
            $correcciones = array_merge($correcciones, $h['correcciones']);
            foreach ($h['val_items'] as $estados) {
                $nval++;
                foreach ($estados as $id => $estado) {
                    $items[$id][$estado] = ($items[$id][$estado] ?? 0) + 1;
                }
            }
            foreach ($h['val_criticos'] as $c) {
                $valcriticos += $c['obs'] ? 1 : 0;
                $parciales += $c['parciales'];
                $forma += $c['forma'];
            }
            $clave = \core_text::strtolower(preg_replace('/\s+/u', ' ', $h['organismo'])) ?: '—';
            $otec[$clave]['nombre'] = $otec[$clave]['nombre'] ?? ($h['organismo'] !== '' ? $h['organismo'] : '—');
            $otec[$clave]['fichas'] = ($otec[$clave]['fichas'] ?? 0) + 1;
            $otec[$clave][$h['decision_final']] = ($otec[$clave][$h['decision_final']] ?? 0) + 1;
        }
        $porcentajes = [];
        foreach (exante::checklist_ids() as $id) {
            $c = $items[$id] ?? [];
            $porcentajes[$id] = ['cumple' => self::pct($c[exante::CUMPLE] ?? 0, $nval),
                'parcial' => self::pct($c[exante::PARCIAL] ?? 0, $nval),
                'no_cumple' => self::pct($c[exante::NO_CUMPLE] ?? 0, $nval),
                'obs' => ($c[exante::PARCIAL] ?? 0) + ($c[exante::NO_CUMPLE] ?? 0)];
        }
        $iniciaron = array_filter($hechos, static function($h) {
            return $h['etapas'][1]['alcanzada'] && $h['no_resueltas'] !== null;
        });
        $secciones = [];
        foreach ($iniciaron as $h) {
            foreach (array_keys($h['no_resueltas_secciones']) as $s) {
                $secciones[$s] = ($secciones[$s] ?? 0) + 1;
            }
        }
        ksort($secciones);
        uasort($otec, static function($a, $b) {
            return $b['fichas'] <=> $a['fichas'];
        });
        $r['c'] = ['validadas' => count($validadas), 'validaciones' => $nval, 'primera' => $primera, 'final' => $final,
            'ciclos_promedio' => self::mean($ciclosaprob), 'aprobadas' => count($ciclosaprob),
            'correccion_mediana' => self::median($correcciones), 'correcciones' => count($correcciones),
            'items' => $porcentajes, 'criticos' => self::pct($valcriticos, $nval),
            'forma' => self::pct($forma, $parciales), 'parciales_criticos' => $parciales,
            'no_resueltas_promedio' => self::mean(array_column($iniciaron, 'no_resueltas')),
            'iniciaron' => count($iniciaron),
            'secciones' => array_map(static function($n) use ($iniciaron) {
                return ['n' => $n, 'pct' => self::pct($n, count($iniciaron))];
            }, $secciones),
            'otec' => array_values($otec)];

        // --- D. Avance en el proceso ---
        $aprobadas = array_filter($hechos, static function($h) {
            return $h['ciclos_aprobacion'] !== null;
        });
        $embudo = [];
        $iteraciones = [];
        $duraciones = [];
        for ($n = 1; $n <= diseno::EVALUACION; $n++) {
            $llegan = 0;
            $cerraron = 0;
            $rondas = 0;
            $dias = [];
            foreach ($aprobadas as $h) {
                $e = $h['etapas'][$n];
                $llegan += $e['alcanzada'] ? 1 : 0;
                if ($e['cerrada']) {
                    $cerraron++;
                    $rondas += $e['rondas'];
                }
                if ($e['dias'] !== null) {
                    $dias[] = $e['dias'];
                }
            }
            $embudo[$n] = ['n' => $llegan, 'pct' => self::pct($llegan, count($aprobadas))];
            $iteraciones[$n] = $cerraron > 0 ? round($rondas / $cerraron, 1) : null;
            $duraciones[$n] = self::median($dias);
        }
        $r['d'] = ['aprobadas' => count($aprobadas), 'embudo' => $embudo, 'iteraciones' => $iteraciones,
            'duracion_etapa' => $duraciones,
            'duracion_total' => self::median(array_filter(array_column($hechos, 'duracion'), static function($v) {
                return $v !== null;
            }))];

        // --- E. Calidad del diseno (Etapa 8) ---
        $evaluadas = array_values(array_filter(array_column($hechos, 'evaluacion')));
        $ne = count($evaluadas);
        $bandas = ['alto' => 0, 'menores' => 0, 'importantes' => 0, 'no' => 0];
        $criterios = [];
        $conlimite = 0;
        $techobajo = 0;
        $nivel1 = 0;
        $c2nivel1 = 0;
        foreach ($evaluadas as $ev) {
            $bandas[$ev['banda']]++;
            foreach ($ev['criterios'] as $id => $p) {
                $criterios[$id][] = $p;
            }
            $conlimite += $ev['limitados'] ? 1 : 0;
            $techobajo += $ev['maximo'] < self::ALTO_ESTANDAR ? 1 : 0;
            $nivel1 += $ev['nivel1'] ? 1 : 0;
            $c2nivel1 += in_array('r2', $ev['nivel1'], true) ? 1 : 0;
        }
        $r['e'] = ['evaluadas' => $ne, 'total_promedio' => self::mean(array_column($evaluadas, 'total')),
            'bandas' => array_map(static function($n) use ($ne) {
                return ['n' => $n, 'pct' => self::pct($n, $ne)];
            }, $bandas),
            'criterios' => array_map([self::class, 'mean'], $criterios),
            'con_limite' => self::pct($conlimite, $ne), 'techo_bajo' => self::pct($techobajo, $ne),
            'brecha_promedio' => self::mean(array_column($evaluadas, 'brecha')),
            'techo_promedio' => self::mean(array_column($evaluadas, 'maximo')),
            'nivel1' => self::pct($nivel1, $ne), 'c2nivel1' => $c2nivel1];

        // --- F. Relacion diagnostico-diseno ---
        $pordecision = [exante::APROBADO => [], exante::OBSERVACIONES => []];
        $portramo = array_fill_keys(array_keys(self::TRAMOS_NO_RESUELTAS), []);
        foreach ($hechos as $h) {
            if (!$h['evaluacion']) {
                continue;
            }
            if (isset($pordecision[$h['decision_diseno']])) {
                $pordecision[$h['decision_diseno']][] = $h['evaluacion']['total'];
            }
            if ($h['no_resueltas_diseno'] !== null) {
                $portramo[self::bucket($h['no_resueltas_diseno'])][] = $h['evaluacion']['total'];
            }
        }
        $pa = self::mean($pordecision[exante::APROBADO]);
        $po = self::mean($pordecision[exante::OBSERVACIONES]);
        $r['f'] = ['aprobado' => ['promedio' => $pa, 'n' => count($pordecision[exante::APROBADO])],
            'observaciones' => ['promedio' => $po, 'n' => count($pordecision[exante::OBSERVACIONES])],
            'diferencia' => $pa !== null && $po !== null ? round($pa - $po, 1) : null,
            'tramos' => array_map(static function($v) {
                return ['promedio' => self::mean($v), 'n' => count($v)];
            }, $portramo)];

        // --- H. Percepcion (encuesta de cierre) ---
        $r['h'] = self::survey_summary(array_values(array_filter(array_column($hechos, 'encuesta'))));
        return $r;
    }

    /**
     * Tramo de observaciones no resueltas (0; 1 a 2; 3 o mas). Funcion pura.
     *
     * @param int $n
     * @return string
     */
    public static function bucket(int $n): string {
        return $n <= 0 ? '0' : ($n <= 2 ? '1-2' : '3+');
    }

    /**
     * Promedios de la encuesta de cierre, por pregunta y por dimension. Funcion pura.
     *
     * @param array $respuestas Lista de (codigo => 1..5).
     * @return array ['n', 'preguntas' => codigo => promedio, 'utilidad', 'claridad'].
     */
    public static function survey_summary(array $respuestas): array {
        $preguntas = [];
        $dimensiones = ['utilidad' => [], 'claridad' => []];
        foreach (self::encuesta() as $codigo => $p) {
            $valores = [];
            foreach ($respuestas as $r) {
                if (isset($r[$codigo])) {
                    $valores[] = (int) $r[$codigo];
                }
            }
            $preguntas[$codigo] = self::mean($valores);
            $dimensiones[$p['dimension']] = array_merge($dimensiones[$p['dimension']], $valores);
        }
        return ['n' => count($respuestas), 'preguntas' => $preguntas,
            'utilidad' => self::mean($dimensiones['utilidad']), 'claridad' => self::mean($dimensiones['claridad'])];
    }

    /**
     * Calidad del agente (grupo G) a partir de la revision experta de una muestra. Funcion pura.
     *
     * Etapa 0: validaciones en que el agente y la revision experta llegan a la misma decision. Etapa 8: criterios en
     * que el puntaje del agente y el de la revision difieren en 1 punto o menos. Incidencias por tipo sobre las
     * evaluaciones revisadas.
     *
     * @param array $revisiones Lista de ['tipo' (etapa0|etapa8), 'agente', 'experto', 'incidencias' (string[])]:
     *   en etapa0, agente y experto son la decision; en etapa8, criterio => puntaje.
     * @return array
     */
    public static function agent(array $revisiones): array {
        $e0 = 0;
        $e0iguales = 0;
        $criterios = 0;
        $cercanos = 0;
        $e8 = 0;
        $porcriterio = [];
        $incidencias = array_fill_keys(array_keys(self::incidencias()), 0);
        $confusion = [];
        foreach ($revisiones as $rev) {
            foreach ((array) ($rev['incidencias'] ?? []) as $tipo) {
                if (isset($incidencias[$tipo])) {
                    $incidencias[$tipo]++;
                }
            }
            if ($rev['tipo'] === 'etapa0') {
                $e0++;
                $e0iguales += (string) $rev['agente'] === (string) $rev['experto'] ? 1 : 0;
                $clave = $rev['agente'] . '>' . $rev['experto'];
                $confusion[$clave] = ($confusion[$clave] ?? 0) + 1;
                continue;
            }
            $e8++;
            foreach ((array) $rev['experto'] as $id => $p) {
                if (!isset($rev['agente'][$id])) {
                    continue;
                }
                $criterios++;
                $dif = abs((int) $rev['agente'][$id] - (int) $p);
                $cercanos += $dif <= 1 ? 1 : 0;
                $porcriterio[$id][] = (int) $rev['agente'][$id] - (int) $p;
            }
        }
        $revisadas = count($revisiones);
        return ['revisadas' => $revisadas, 'etapa0' => $e0, 'etapa8' => $e8,
            'concordancia0' => self::pct($e0iguales, $e0), 'concordancia8' => self::pct($cercanos, $criterios),
            'criterios' => $criterios, 'confusion' => $confusion,
            'sesgo' => array_map([self::class, 'mean'], $porcriterio),
            'incidencias' => array_map(static function($n) use ($revisadas) {
                return ['n' => $n, 'tasa' => $revisadas > 0 ? round($n / $revisadas, 2) : null];
            }, $incidencias)];
    }

    /**
     * Puntajes de la revision experta de una Etapa 8, o null si falta alguno. Funcion pura.
     *
     * @param array $raw criterio => valor.
     * @return int[]|null
     */
    public static function clean_scores(array $raw): ?array {
        $salida = [];
        foreach (array_keys(diseno::rubrica()) as $id) {
            $v = $raw[$id] ?? null;
            if (!is_numeric($v) || (int) $v < 1 || (int) $v > 4 || (float) $v !== (float) (int) $v) {
                return null;
            }
            $salida[$id] = (int) $v;
        }
        return $salida;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Lectura de la base de datos.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Plazos «X» de los avisos (ajustes del plugin; el cliente los define antes de la puesta en produccion).
     *
     * @return array ['correccion' => dias, 'detenido' => dias].
     */
    public static function plazos(): array {
        $c = (int) get_config('local_chatbot', 'm2plazocorreccion');
        $d = (int) get_config('local_chatbot', 'm2plazodetenido');
        return ['correccion' => $c > 0 ? $c : self::PLAZO_CORRECCION, 'detenido' => $d > 0 ? $d : self::PLAZO_DETENIDO];
    }

    /**
     * Guia y ejemplo de la Fase 1 en el curso (los mismos que enlaza configurar_modulo2.php).
     *
     * @param int $courseid
     * @return int[] ['guia' => cmid, 'ejemplo' => cmid] (solo los que existen).
     */
    public static function material_cms(int $courseid): array {
        $nombres = [];
        foreach (get_fast_modinfo($courseid)->get_instances_of('resource') as $cm) {
            $nombres[$cm->id] = $cm->name;
        }
        $m = modulo2_setup::match_resources($nombres);
        return array_filter(['guia' => $m['material1'] ?? 0, 'ejemplo' => $m['material3'] ?? 0]);
    }

    /**
     * Personas inscritas que cursan el modulo (pueden usar el asistente y no editan el curso).
     *
     * @param int $courseid
     * @param int $groupid 0 para todo el curso.
     * @return int[]
     */
    public static function enrolled(int $courseid, int $groupid = 0): array {
        $context = \context_course::instance($courseid);
        $ids = [];
        foreach (get_enrolled_users($context, 'local/chatbot:use', $groupid, 'u.id', null, 0, 0, true) as $u) {
            if (!has_capability('moodle/course:update', $context, $u->id)) {
                $ids[] = (int) $u->id;
            }
        }
        return $ids;
    }

    /**
     * Hechos de las fichas de un curso (de una persona, o de un conjunto de personas).
     *
     * @param int $courseid
     * @param int[]|null $userids null para todas.
     * @param bool $conlogs Leer del registro de Moodle el primer acceso y la consulta de la guia y el ejemplo (los avisos
     *                      no los usan: la tarea de cada hora no los lee).
     * @return array fichaid => hechos.
     */
    public static function load_facts(int $courseid, ?array $userids = null, bool $conlogs = true): array {
        global $DB;
        if ($userids === []) {
            return [];
        }
        $where = 'courseid = :courseid';
        $params = ['courseid' => $courseid];
        if ($userids !== null) {
            [$in, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $where .= ' AND userid ' . $in;
            $params += $inparams;
        }
        $fichas = $DB->get_records_select('local_chatbot_exante', $where, $params, 'id', 'id, userid, data, activa');
        if (!$fichas) {
            return [];
        }
        $porficha = static function(string $tabla, string $campos, string $orden) use ($DB, $where, $params): array {
            $salida = [];
            foreach ($DB->get_records_select($tabla, $where, $params, $orden, $campos) as $fila) {
                $salida[(int) $fila->fichaid][] = $fila;
            }
            return $salida;
        };
        $validaciones = $porficha('local_chatbot_exante_val', 'id, fichaid, estado, condicion, informe, timecreated', 'id');
        $envios = $porficha('local_chatbot_m2_envio', 'id, fichaid, completo, faltantes, plan, perfil, timecreated', 'id');
        $etapas = $porficha('local_chatbot_diseno', '*', 'etapa');
        $encuestas = [];
        foreach ($DB->get_records_select('local_chatbot_m2_encuesta', $where, $params, '', 'id, fichaid, respuestas') as $e) {
            $encuestas[(int) $e->fichaid] = (array) json_decode((string) $e->respuestas, true);
        }
        $personas = array_unique(array_map('intval', array_column($fichas, 'userid')));
        [$accesos, $vistas] = $conlogs ? self::load_logs($courseid, $personas) : [[], []];

        $hechos = [];
        foreach ($fichas as $f) {
            $fid = (int) $f->id;
            $data = (array) json_decode((string) $f->data, true);
            $vals = [];
            $actividad = 0;
            foreach ($validaciones[$fid] ?? [] as $v) {
                $informe = (array) json_decode((string) $v->informe, true);
                $vals[] = ['id' => (int) $v->id, 'estado' => (string) $v->estado, 'condicion' => (string) $v->condicion,
                    'items' => (array) ($informe['items'] ?? []), 'time' => (int) $v->timecreated];
                $actividad = max($actividad, (int) $v->timecreated);
            }
            $env = [];
            foreach ($envios[$fid] ?? [] as $e) {
                $env[] = ['completo' => (bool) $e->completo, 'faltantes' => (array) json_decode((string) $e->faltantes, true),
                    'plan' => (bool) $e->plan, 'perfil' => (bool) $e->perfil, 'time' => (int) $e->timecreated];
                $actividad = max($actividad, (int) $e->timecreated);
            }
            $filas = [];
            $et = [];
            foreach ($etapas[$fid] ?? [] as $fila) {
                $filas[(int) $fila->etapa] = $fila;
                $et[(int) $fila->etapa] = ['estado' => (string) $fila->estado, 'inicio' => (int) $fila->timecreated,
                    'cierre' => (int) $fila->timeaccepted, 'rondas' => (int) ($fila->rondas ?? 0),
                    'validacionid' => (int) $fila->validacionid];
                $actividad = max($actividad, (int) $fila->timemodified);
            }
            $uid = (int) $f->userid;
            $hechos[$fid] = self::facts(['ficha' => ['id' => $fid, 'userid' => $uid,
                    'organismo' => (string) ($data['organismo'] ?? ''), 'activa' => (int) $f->activa,
                    'etiqueta' => exante::design_label($data)],
                'validaciones' => $vals, 'envios' => $env, 'etapas' => $et, 'aceptadas' => diseno::accepted($filas),
                'evaluacion' => diseno::current_evaluation($filas), 'primer_acceso' => $accesos[$uid] ?? null,
                'vistas' => $vistas[$uid] ?? [], 'actividad' => $actividad, 'encuesta' => $encuestas[$fid] ?? null]);
        }
        return $hechos;
    }

    /**
     * Primer ingreso al curso y primera consulta de la guia y del ejemplo, por persona, del registro de Moodle.
     *
     * @param int $courseid
     * @param int[] $userids
     * @return array [userid => ts, userid => ['guia' => ts, 'ejemplo' => ts]].
     */
    public static function load_logs(int $courseid, array $userids): array {
        global $DB;
        $accesos = [];
        $vistas = [];
        if (!$userids || !$DB->get_manager()->table_exists('logstore_standard_log')) {
            return [$accesos, $vistas];
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;
        $params['evento'] = '\\core\\event\\course_viewed';
        foreach ($DB->get_records_sql("SELECT userid, MIN(timecreated) AS t FROM {logstore_standard_log}
                WHERE courseid = :courseid AND eventname = :evento AND userid $in GROUP BY userid", $params) as $fila) {
            $accesos[(int) $fila->userid] = (int) $fila->t;
        }
        $cms = self::material_cms($courseid);
        if ($cms) {
            [$incm, $paramscm] = $DB->get_in_or_equal(array_values($cms), SQL_PARAMS_NAMED, 'cm');
            $params['evento'] = '\\mod_resource\\event\\course_module_viewed';
            $sql = "SELECT " . $DB->sql_concat('userid', "'-'", 'contextinstanceid') . " AS clave, userid, contextinstanceid,
                           MIN(timecreated) AS t
                      FROM {logstore_standard_log}
                     WHERE courseid = :courseid AND eventname = :evento AND userid $in AND contextinstanceid $incm
                  GROUP BY userid, contextinstanceid";
            $nombre = array_flip($cms);
            foreach ($DB->get_records_sql($sql, $params + $paramscm) as $fila) {
                $vistas[(int) $fila->userid][$nombre[(int) $fila->contextinstanceid]] = (int) $fila->t;
            }
        }
        return [$accesos, $vistas];
    }

    /**
     * Registra un envio de la ficha al control de entrada (pase o no).
     *
     * @param \stdClass $ficha
     * @param string[] $faltantes Lo que falto (exante::missing_inputs()).
     * @param string[] $adjuntos Areas de los archivos adjuntos.
     * @return void
     */
    public static function log_submission(?\stdClass $ficha, array $faltantes, array $adjuntos): void {
        global $DB;
        if (!$ficha) {
            return;
        }
        // El registro es para el tablero: si falla, la validacion de la ficha sigue igual.
        try {
            $DB->insert_record('local_chatbot_m2_envio', (object) ['fichaid' => $ficha->id, 'userid' => $ficha->userid,
                'courseid' => $ficha->courseid, 'completo' => $faltantes ? 0 : 1,
                'faltantes' => json_encode(array_values($faltantes), JSON_UNESCAPED_UNICODE),
                'plan' => in_array('exante_plan', $adjuntos, true) ? 1 : 0,
                'perfil' => in_array('exante_perfil', $adjuntos, true) ? 1 : 0, 'timecreated' => time()]);
        } catch (\Throwable $e) {
            debugging('No se pudo registrar el envio de la ficha: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Muestra para la revision experta: validaciones terminadas y evaluaciones vigentes de un curso, con su revision
     * si ya la tienen. Sin nombres.
     *
     * @param int $courseid
     * @return array ['etapa0' => lista, 'etapa8' => lista] de ['ref', 'fichaid', 'agente', 'time', 'revision'].
     */
    public static function load_sample(int $courseid): array {
        global $DB;
        $revisiones = [];
        foreach ($DB->get_records('local_chatbot_m2_revision', ['courseid' => $courseid]) as $r) {
            $revisiones[$r->tipo . '-' . $r->refid] = $r;
        }
        $muestra = ['etapa0' => [], 'etapa8' => []];
        foreach ($DB->get_records_select('local_chatbot_exante_val', 'courseid = ? AND estado = ?',
                [$courseid, exante::LISTO], 'id DESC', 'id, fichaid, condicion, timecreated') as $v) {
            $muestra['etapa0'][] = ['ref' => (int) $v->id, 'fichaid' => (int) $v->fichaid, 'agente' => (string) $v->condicion,
                'time' => (int) $v->timecreated, 'revision' => $revisiones['etapa0-' . $v->id] ?? null];
        }
        foreach ($DB->get_records_select('local_chatbot_diseno', 'courseid = ? AND etapa = ? AND informe IS NOT NULL',
                [$courseid, diseno::EVALUACION], 'id DESC', 'id, fichaid, informe, timemodified') as $e) {
            $informe = (array) json_decode((string) $e->informe, true);
            if (!isset($informe['total'])) {
                continue;
            }
            // La fila de la Etapa 8 se reescribe en cada evaluacion: una revision hecha sobre otros puntajes es de la
            // evaluacion anterior y esta queda por revisar.
            $revision = $revisiones['etapa8-' . $e->id] ?? null;
            $anterior = $revision && !self::same_scores((array) json_decode((string) $revision->agente, true),
                self::agent_scores($informe));
            $muestra['etapa8'][] = ['ref' => (int) $e->id, 'fichaid' => (int) $e->fichaid, 'agente' => (int) $informe['total'],
                'time' => (int) $e->timemodified, 'revision' => $anterior ? null : $revision, 'anterior' => $anterior];
        }
        return $muestra;
    }

    /**
     * Puntajes del agente en un informe de la Etapa 8. Funcion pura.
     *
     * @param array $informe
     * @return int[] criterio => puntaje.
     */
    public static function agent_scores(array $informe): array {
        $p = [];
        foreach ((array) ($informe['criterios'] ?? []) as $id => $c) {
            $p[$id] = (int) (is_array($c) ? ($c['puntaje'] ?? 0) : $c);
        }
        return $p;
    }

    /**
     * Si dos juegos de puntajes son los mismos (la revision experta corresponde a la evaluacion vigente). Funcion pura.
     *
     * @param array $a
     * @param array $b
     * @return bool
     */
    public static function same_scores(array $a, array $b): bool {
        $a = array_map('intval', $a);
        $b = array_map('intval', $b);
        ksort($a);
        ksort($b);
        return $a === $b;
    }

    /**
     * Revisiones expertas de un curso, en la forma que pide agent().
     *
     * @param int $courseid
     * @return array
     */
    public static function load_reviews(int $courseid): array {
        global $DB;
        $salida = [];
        foreach ($DB->get_records('local_chatbot_m2_revision', ['courseid' => $courseid], 'id') as $r) {
            $agente = json_decode((string) $r->agente, true);
            $experto = json_decode((string) $r->experto, true);
            $salida[] = ['tipo' => (string) $r->tipo, 'agente' => $agente, 'experto' => $experto,
                'incidencias' => (array) json_decode((string) $r->incidencias, true)];
        }
        return $salida;
    }

    /**
     * Envia por las notificaciones de Moodle los avisos que correspondan a una persona (sus fichas en uso).
     *
     * @param \stdClass $user
     * @param int $courseid
     * @param array $hechos Hechos de sus fichas en el curso.
     * @param int $now
     * @return int Cantidad de avisos enviados.
     */
    public static function send_notices(\stdClass $user, int $courseid, array $hechos, int $now): int {
        $plazos = self::plazos();
        // Un registro por curso: cada pasada de la tarea ve solo los avisos de ese curso.
        $preferencia = self::PREFERENCIA . '_' . $courseid;
        $enviados = json_decode((string) get_user_preferences($preferencia, '', $user->id), true) ?: [];
        $avisos = [];
        foreach ($hechos as $h) {
            if ($h['activa']) {
                $avisos = array_merge($avisos, self::notices($h, $now, $plazos));
            }
        }
        $pendientes = self::due($avisos, $enviados, $now, $plazos);
        $registro = self::record($enviados, $pendientes, $avisos, $now);
        if ($registro !== $enviados) {
            // Se registra antes de enviar: si el registro fallara despues, el mismo aviso saldria cada hora.
            set_user_preference($preferencia, json_encode($registro), $user->id);
        }
        if (!$pendientes) {
            return 0;
        }
        $textos = self::avisos();
        $lineas = [];
        foreach ($pendientes as $a) {
            $lineas[] = $textos[$a['tipo']]['nombre'] . ': ' . self::notice_detail($a) . ' '
                . $textos[$a['tipo']]['sugerencia'];
        }
        $url = new \moodle_url('/local/chatbot/tablero_m2.php', ['courseid' => $courseid]);
        $mensaje = new \core\message\message();
        $mensaje->component = 'local_chatbot';
        $mensaje->name = 'avisom2';
        $mensaje->userfrom = \core_user::get_noreply_user();
        $mensaje->userto = $user;
        $mensaje->subject = get_string('m2dash_notice_subject', 'local_chatbot');
        $mensaje->fullmessage = implode("\n", array_map(static function($l) {
            return '• ' . $l;
        }, $lineas)) . "\n\n" . $url->out(false);
        $mensaje->fullmessageformat = FORMAT_PLAIN;
        $mensaje->fullmessagehtml = '<ul><li>' . implode('</li><li>', array_map('s', $lineas)) . '</li></ul><p><a href="'
            . $url->out() . '">' . s(get_string('m2dash_notice_go', 'local_chatbot')) . '</a></p>';
        $mensaje->smallmessage = get_string('m2dash_notice_subject', 'local_chatbot');
        $mensaje->notification = 1;
        $mensaje->contexturl = $url->out(false);
        $mensaje->contexturlname = get_string('m2dash_notice_go', 'local_chatbot');
        $mensaje->courseid = $courseid;
        message_send($mensaje);
        return count($pendientes);
    }

    /**
     * Detalle de un aviso con sus datos (dias, items, techo). Funcion pura.
     *
     * @param array $a Aviso de notices().
     * @return string
     */
    public static function notice_detail(array $a): string {
        $x = $a['a'];
        switch ($a['tipo']) {
            case 'correccion':
                return 'tu ficha lleva ' . $x['dias'] . ' días sin reenviarse después del ❌ No aprobado.';
            case 'critico':
                $textos = exante::checklist_texts();
                return 'ítems ' . implode('; ', array_map(static function($id) use ($textos) {
                    return '«' . ($textos[$id] ?? $id) . '»';
                }, $x['items'])) . '.';
            case 'noresueltas':
                $nombres = diseno::rubrica();
                return $x['n'] . ' ítem(s) con ⚠ o ❌ sin corregir; criterios que quedarán limitados a 3: '
                    . (implode(', ', array_map(static function($id) use ($nombres) {
                        return substr($id, 1) . '. ' . $nombres[$id]['nombre'];
                    }, $x['criterios'])) ?: 'ninguno') . '.';
            case 'techo':
                return 'tu puntaje máximo alcanzable es ' . $x['techo'] . ' de 36.';
            case 'c2nivel1':
                return 'la evaluación asignó 1 punto al criterio 2.';
            case 'detenido':
                return $x['dias'] . ' días sin avanzar ' . ($x['etapa'] === 0 ? 'desde la Etapa 0.'
                    : 'en la Etapa ' . $x['etapa'] . '.');
        }
        return '';
    }
}
