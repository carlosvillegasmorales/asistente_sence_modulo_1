<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Tablero del Modulo 3 («Propuesta de Dashboard · Módulo 3: indicadores, vistas y uso», Interfases, 2026).
 *
 * Tres vistas: individual (cada persona facilitadora, solo con su informacion y con avisos automaticos), acumulada
 * (coordinacion del programa: resultados de todas las personas, sin seguimiento individual) y calidad del agente
 * (administracion del asistente, con la encuesta breve que se responde al cerrar la Fase 3 y la Fase 4).
 *
 * Los indicadores salen de lo que el asistente ya guarda (fichas en linea, validaciones de las etapas 0 y 10, etapas 1 a 15,
 * ficha ex post), de los registros de Moodle (primer acceso, consulta de la guia y del ejemplo) y de dos registros propios:
 * los envios de las fichas al control de entrada (local_chatbot_m3_envio) y la encuesta breve (local_chatbot_m3_encuesta).
 * Lo que la propuesta pide y el asistente aun no entrega como campo estructurado (el tipo y la causa de cada cambio de la
 * implementacion, los pendientes vinculados al desempeño central) se informa como no disponible, sin estimarlo.
 *
 * El calculo es puro: facts() recibe los datos de una evaluacion ya leidos y devuelve sus hechos; program(), agent(),
 * alerts() y notices() trabajan sobre esos hechos. La lectura de la base va aparte (load_*), como en el resto del plugin.
 *
 * No es calificacion: nada de esto toca el libro de calificaciones.
 */
class tablero_m3 {

    /** @var int Dias por defecto de cada plazo «X» de los avisos (los define el cliente: ajustes del plugin). */
    const PLAZO_REGISTRO = 7;
    const PLAZO_CORRECCION = 7;
    const PLAZO_CIERRE = 7;
    const PLAZO_DETENIDO = 7;

    /** @var string Preferencia de usuario donde se guarda cuando se envio cada aviso. */
    const PREFERENCIA = 'local_chatbot_avisos_m3';

    /** @var string[] Avisos que se repiten cada tantos dias como su plazo mientras sigan activos. */
    const REPETIBLES = ['registro', 'correccion', 'cierre', 'detenido'];

    /** @var float Logro (%) por debajo del cual un criterio es una brecha de criticidad alta (aviso «Brecha de criticidad alta»). */
    const LOGRO_ALTA = 30.0;

    /** @var float Umbrales de las alertas de la administracion del asistente. */
    const INDICE_MIN = 3.5;
    const CAIDA_INDICE = 0.5;
    const PREGUNTA_MIN = 3.0;
    const ACUERDO_MIN = 70;
    const ERRORES_MAX = 10;
    const ERRORES_REPETIDO = 3;
    const RESPUESTA_MIN = 50;

    /** @var int[] Periodos del filtro de la vista acumulada, en dias. */
    const PERIODOS = ['semana' => 7, 'mes' => 30, 'semestre' => 182, 'anio' => 365];

    /** @var string[] Fases en que se aplica la encuesta breve. */
    const FASES_ENCUESTA = [3, 4];

    /** @var int Largo maximo del comentario de la encuesta. */
    const COMENTARIO_MAX = 500;

    // ------------------------------------------------------------------------------------------------------------
    // Textos de la propuesta del cliente.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Avisos automaticos de la vista individual, con el texto de la propuesta del cliente.
     *
     * @return array tipo => ['nombre', 'cuando', 'sugerencia'].
     */
    public static function avisos(): array {
        return [
            'registro' => ['nombre' => 'Registro pendiente',
                'cuando' => 'Más de {X} días desde el término de la implementación sin enviar la ficha de registro.',
                'sugerencia' => 'Registrar la implementación mientras la evidencia está disponible.'],
            'correccion' => ['nombre' => 'Corrección pendiente',
                'cuando' => 'Más de {X} días sin reenviar una ficha con ❌ No aprobado (Etapa 0 o Etapa 10).',
                'sugerencia' => 'Retomar la corrección indicada por el asistente.'],
            'critico' => ['nombre' => 'Ítem CRÍTICO observado',
                'cuando' => 'Cualquier ⚠ o ❌ en un ítem CRÍTICO del checklist.',
                'sugerencia' => 'Revisar la correspondencia de la ficha con el diseño validado y los conteos por nivel.'],
            'brecha' => ['nombre' => 'Brecha de criticidad alta',
                'cuando' => 'Algún criterio con logro inferior a 30 % o un pendiente vinculado al desempeño central.',
                'sugerencia' => 'Priorizar ese criterio en la experiencia de continuidad.'],
            'tiempo' => ['nombre' => 'Tiempo restante insuficiente',
                'cuando' => 'La experiencia de continuidad propuesta supera el tiempo restante.',
                'sugerencia' => 'Revisar la priorización de brechas con el asistente.'],
            'cierre' => ['nombre' => 'Cierre del ciclo pendiente',
                'cuando' => 'Más de {X} días desde la fecha de la experiencia de continuidad sin iniciar la Fase 4.',
                'sugerencia' => 'Completar la ficha de evaluación ex post.'],
            'detenido' => ['nombre' => 'Proceso detenido',
                'cuando' => 'Más de {X} días sin avanzar de etapa.',
                'sugerencia' => 'Retomar el proceso en la etapa en que quedó.'],
            'encuesta' => ['nombre' => 'Encuesta pendiente',
                'cuando' => 'Se consolidó la Fase 3 o la Fase 4 y no se respondió la encuesta breve.',
                'sugerencia' => 'Responder la encuesta (3 minutos) para mejorar el asistente.'],
        ];
    }

    /**
     * Alertas para la administracion del asistente (sobre las encuestas del periodo).
     *
     * @return array tipo => ['nombre', 'cuando'].
     */
    public static function alertas(): array {
        return [
            'global' => ['nombre' => 'Calidad global baja',
                'cuando' => 'Índice de calidad del agente menor que 3,5, o descenso de 0,5 puntos o más respecto del período anterior.'],
            'pregunta' => ['nombre' => 'Pregunta crítica baja',
                'cuando' => 'Promedio menor que 3,0 en cualquiera de las preguntas 1 a 6.'],
            'acuerdo' => ['nombre' => 'Desacuerdo con la decisión',
                'cuando' => 'Acuerdo con la decisión menor que 70 % en algún tipo de ajuste.'],
            'errores' => ['nombre' => 'Errores reportados',
                'cuando' => 'Más del 10 % de las encuestas con errores reportados, o un mismo tipo de error en 3 encuestas o más.'],
            'respuesta' => ['nombre' => 'Baja representatividad',
                'cuando' => 'Tasa de respuesta menor que 50 %: los resultados se informan con esa advertencia.'],
        ];
    }

    /**
     * Tipos de error que la persona puede reportar en la pregunta 7 de la encuesta.
     *
     * @return string[] codigo => texto.
     */
    public static function error_types(): array {
        return ['calculo' => 'Cálculo', 'datos' => 'Datos que no corresponden a su ficha o diseño',
            'regla' => 'Regla mal aplicada', 'otro' => 'Otro'];
    }

    /**
     * Preguntas de la encuesta breve (indicadores del grupo G). La pregunta 4 es solo de la Fase 3 y la 5 cambia de texto
     * segun la fase. Las preguntas 1 a 6 se responden de 1 a 5; la 7 es Si / No con el tipo de error; la 8 es opcional.
     *
     * @param int $fase 3 o 4.
     * @return array codigo => ['texto', 'indicador'].
     */
    public static function survey(int $fase): array {
        $p = [
            'p1' => ['texto' => 'Las instrucciones y los mensajes del asistente fueron claros.', 'indicador' => 'Claridad'],
            'p2' => ['texto' => 'La validación de mi ficha identificó correctamente lo que debía corregir.',
                'indicador' => 'Precisión de la validación'],
            'p3' => ['texto' => 'El análisis reflejó fielmente lo que registré en la ficha.', 'indicador' => 'Fidelidad del análisis'],
        ];
        if ($fase === 3) {
            $p['p4'] = ['texto' => 'Estoy de acuerdo con el tipo de ajuste que decidió el asistente.',
                'indicador' => 'Acuerdo con la decisión'];
        }
        $p['p5'] = ['texto' => $fase === 3
            ? 'La experiencia de continuidad es aplicable en mi contexto y en el tiempo disponible.'
            : 'Las recomendaciones para una nueva ejecución son aplicables.', 'indicador' => 'Aplicabilidad de las propuestas'];
        $p['p6'] = ['texto' => 'El asistente me ahorró tiempo respecto de hacer este trabajo por mi cuenta.',
            'indicador' => 'Ahorro de tiempo'];
        return $p;
    }

    /**
     * Respuestas validas de la encuesta, o null si falta alguna de las obligatorias. Funcion pura.
     *
     * @param array $raw codigo => valor (p1..p6 de 1 a 5; p7 si/no; p7tipo; p8 texto).
     * @param int $fase 3 o 4.
     * @return array|null
     */
    public static function clean_survey(array $raw, int $fase): ?array {
        $salida = [];
        foreach (array_keys(self::survey($fase)) as $codigo) {
            $v = $raw[$codigo] ?? null;
            if (!is_numeric($v) || (int) $v < 1 || (int) $v > 5 || (float) $v !== (float) (int) $v) {
                return null;
            }
            $salida[$codigo] = (int) $v;
        }
        $p7 = $raw['p7'] ?? null;
        if (!in_array($p7, ['si', 'no'], true)) {
            return null;
        }
        $salida['p7'] = $p7;
        $tipo = (string) ($raw['p7tipo'] ?? '');
        if ($p7 === 'si') {
            if (!isset(self::error_types()[$tipo])) {
                return null;
            }
            $salida['p7tipo'] = $tipo;
        }
        $comentario = trim(preg_replace('/\s+/u', ' ', (string) ($raw['p8'] ?? '')));
        if ($comentario !== '') {
            $salida['p8'] = \core_text::substr($comentario, 0, self::COMENTARIO_MAX);
        }
        return $salida;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Utilidades puras.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Mediana. Funcion pura.
     *
     * @param float[] $valores
     * @return float|null
     */
    public static function median(array $valores): ?float {
        return tablero_m2::median($valores);
    }

    /**
     * Promedio con un decimal. Funcion pura.
     *
     * @param float[] $valores
     * @return float|null
     */
    public static function mean(array $valores): ?float {
        return tablero_m2::mean($valores);
    }

    /**
     * Porcentaje entero. Funcion pura.
     *
     * @param int $parte
     * @param int $total
     * @return int|null
     */
    public static function pct(int $parte, int $total): ?int {
        return tablero_m2::pct($parte, $total);
    }

    /**
     * Dias entre dos marcas de tiempo, con un decimal. Funcion pura.
     *
     * @param int $desde
     * @param int $hasta
     * @return float
     */
    public static function days(int $desde, int $hasta): float {
        return tablero_m2::days($desde, $hasta);
    }

    /**
     * Comienzo del periodo del filtro: hace 7, 30, 182 o 365 dias; 0 para «todo». Funcion pura.
     *
     * @param string $periodo semana, mes, semestre, anio o todo.
     * @param int $now
     * @return int
     */
    public static function period_start(string $periodo, int $now): int {
        return isset(self::PERIODOS[$periodo]) ? $now - self::PERIODOS[$periodo] * 86400 : 0;
    }

    /**
     * Evaluaciones con actividad dentro del periodo. Con $desde = 0, todas. Funcion pura.
     *
     * @param array $hechos De facts().
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
     * Vista a la que lleva el boton flotante en un curso del Modulo 3: coordinacion al «Acumulado», quien tiene una evaluacion
     * a «Mi avance»; null si no corresponde boton. Funcion pura.
     *
     * @param bool $cursom3 El curso tiene evaluaciones del Modulo 3.
     * @param bool $analitica Permiso local/chatbot:viewanalytics.
     * @param bool $conficha La persona tiene una evaluacion en el curso (y permiso de uso).
     * @return string|null acumulada, persona o null.
     */
    public static function fab_view(bool $cursom3, bool $analitica, bool $conficha): ?string {
        if (!$cursom3) {
            return null;
        }
        return $analitica ? 'acumulada' : ($conficha ? 'persona' : null);
    }

    /**
     * Ultima fecha que menciona un texto libre de fechas («22 de marzo de 2026», «22 y 23 de marzo», «22/03/2026»,
     * «2026-03-22»). Si hay varias, la mas tardia (el termino de la implementacion). Funcion pura.
     *
     * @param string $texto
     * @param int $anio Año que se asume cuando el texto no lo dice.
     * @return int|null Marca de tiempo (mediodia UTC) o null si no hay una fecha que se entienda.
     */
    public static function parse_date(string $texto, int $anio): ?int {
        $meses = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
            'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];
        $t = \core_text::strtolower($texto);
        $fechas = [];
        $agregar = static function(int $y, int $m, int $d) use (&$fechas): void {
            if ($m >= 1 && $m <= 12 && $d >= 1 && $d <= 31 && $y >= 2000 && $y <= 2100 && checkdate($m, $d, $y)) {
                $fechas[] = gmmktime(12, 0, 0, $m, $d, $y);
            }
        };
        if (preg_match_all('/\b(\d{1,2})\s+de\s+([a-záéíóú]+)(?:\s+(?:de|del)\s+(\d{4}))?/u', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if (isset($meses[$x[2]])) {
                    $agregar(isset($x[3]) && $x[3] !== '' ? (int) $x[3] : $anio, $meses[$x[2]], (int) $x[1]);
                }
            }
        }
        if (preg_match_all('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $agregar((int) $x[1], (int) $x[2], (int) $x[3]);
            }
        }
        if (preg_match_all('/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4}|\d{2})\b/', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $y = (int) $x[3];
                $agregar($y < 100 ? 2000 + $y : $y, (int) $x[2], (int) $x[1]);
            }
        }
        return $fechas ? max($fechas) : null;
    }

    /**
     * Minutos de la experiencia de continuidad (etapa 6): el que declara su fila «Total» o su frase «tiempo total ... N
     * minutos». Si la etapa no lo declara de una de esas dos formas no se estima (las trayectorias son simultaneas y sumar
     * las filas contaria dos veces). Funcion pura.
     *
     * @param string $html Producto de la etapa 6.
     * @return int|null
     */
    public static function continuity_minutes(string $html): ?int {
        if (trim($html) === '') {
            return null;
        }
        if (stripos($html, '<table') !== false) {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
            libxml_clear_errors();
            foreach ($dom->getElementsByTagName('table') as $tabla) {
                $columna = null;
                foreach ($tabla->getElementsByTagName('tr') as $tr) {
                    $celdas = [];
                    $conth = false;
                    foreach ($tr->childNodes as $nodo) {
                        if ($nodo instanceof \DOMElement && in_array($nodo->nodeName, ['th', 'td'], true)) {
                            $celdas[] = trim(preg_replace('/\s+/u', ' ', $nodo->textContent));
                            $conth = $conth || $nodo->nodeName === 'th';
                        }
                    }
                    if (!$celdas) {
                        continue;
                    }
                    if ($columna === null) {
                        foreach ($celdas as $k => $c) {
                            if (stripos(diseno::plain_lower($c), 'minut') !== false) {
                                $columna = $k;
                                break;
                            }
                        }
                        continue;
                    }
                    if (preg_match('/^\W*total\b/u', diseno::plain_lower($celdas[0])) && isset($celdas[$columna])
                            && preg_match('/\d+/', $celdas[$columna], $n)) {
                        return (int) $n[0];
                    }
                }
            }
        }
        $texto = diseno::plain_lower(html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '</td>', '<br>'], ' ', $html)), ENT_QUOTES, 'UTF-8'));
        if (preg_match('/tiempo total[^0-9]{0,80}(\d{1,4})\s*min/u', $texto, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Hechos de una evaluacion.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Resumen de una lista de validaciones (Etapa 0 o Etapa 10): ciclos, decision, items CRITICO observados. Funcion pura.
     *
     * @param array $validaciones Lista de ['id', 'estado', 'condicion', 'items', 'time'].
     * @param string[] $criticos Ids de los items CRITICO del checklist.
     * @param array $envios Envios al control de entrada de esa ficha: ['completo', 'faltantes', 'time'].
     * @return array
     */
    public static function validation_summary(array $validaciones, array $criticos, array $envios): array {
        $ciclos = [];
        foreach ($validaciones as $v) {
            if (($v['estado'] ?? '') === implementacion::LISTO && !empty($v['condicion'])) {
                $ciclos[] = $v;
            }
        }
        $r = ['envios' => count($envios), 'primer_envio_completo' => $envios ? !empty($envios[0]['completo']) : null,
            'envios_incompletos' => 0, 'faltantes' => []];
        foreach ($envios as $e) {
            if (!empty($e['completo'])) {
                continue;
            }
            $r['envios_incompletos']++;
            foreach (array_unique((array) ($e['faltantes'] ?? [])) as $campo) {
                $r['faltantes'][(string) $campo] = ($r['faltantes'][(string) $campo] ?? 0) + 1;
            }
        }
        $r['ciclos'] = array_map(static function($v) {
            return ['id' => (int) $v['id'], 'condicion' => (string) $v['condicion'], 'time' => (int) $v['time']];
        }, $ciclos);
        $ultimo = $ciclos ? end($ciclos) : null;
        $r['decision_primera'] = $ciclos ? (string) $ciclos[0]['condicion'] : null;
        $r['decision_final'] = $ultimo ? (string) $ultimo['condicion'] : null;
        $r['ultima'] = $ultimo ? (int) $ultimo['id'] : null;
        $r['ciclos_aprobacion'] = null;
        foreach ($ciclos as $i => $v) {
            if (implementacion::can_advance($v['condicion'])) {
                $r['ciclos_aprobacion'] = $i + 1;
                break;
            }
        }
        // Items CRITICO observados (⚠ o ❌) por validacion terminada, y en la ultima.
        $r['val_criticos'] = [];
        foreach ($ciclos as $v) {
            $obs = [];
            foreach ($criticos as $id) {
                $estado = (string) (($v['items'][$id]['estado'] ?? '') ?: '');
                if ($estado !== '' && $estado !== implementacion::CUMPLE) {
                    $obs[] = $id;
                }
            }
            $r['val_criticos'][] = $obs;
        }
        $r['criticos_obs'] = $r['val_criticos'] ? end($r['val_criticos']) : [];
        // Correccion pendiente: la ultima validacion es ❌ y nada la siguio.
        $r['correccion_pendiente'] = null;
        if ($ultimo && $ultimo['condicion'] === implementacion::NO_APROBADO) {
            $despues = array_filter(array_merge(array_column($envios, 'time'), array_column($validaciones, 'time')),
                static function($t) use ($ultimo) {
                    return (int) $t > (int) $ultimo['time'];
                });
            if (!$despues) {
                $r['correccion_pendiente'] = (int) $ultimo['time'];
            }
        }
        // Dias del ❌ al reenvio.
        $r['correcciones'] = [];
        foreach ($ciclos as $v) {
            if ($v['condicion'] !== implementacion::NO_APROBADO) {
                continue;
            }
            $despues = array_filter(array_column($envios, 'time'), static function($t) use ($v) {
                return (int) $t > (int) $v['time'];
            });
            if ($despues) {
                $r['correcciones'][] = self::days((int) $v['time'], (int) min($despues));
            }
        }
        return $r;
    }

    /**
     * Hechos de una evaluacion (una ficha de implementacion con sus fases) para todos los indicadores. Funcion pura.
     *
     * @param array $d Datos ya leidos:
     *   ficha: ['id', 'userid', 'activa', 'organismo', 'plan', 'modulo', 'etiqueta', 'puntaje' (int|null: Modulo 2)];
     *   data: ficha de implementacion limpia (implementacion::clean); ref: implementacion::design_reference();
     *   validaciones: lista de ['id', 'estado', 'condicion', 'items', 'time'] (Etapa 0);
     *   envios: ['impl' => lista, 'expost' => lista] de ['completo', 'faltantes' (string[]), 'time'];
     *   etapas: n => ['estado', 'inicio', 'cierre', 'rondas', 'modificado'] (etapas 1 a 8 y 11 a 15);
     *   aceptadas: etapas 1 a 8 aceptadas y vigentes; aceptadas4: etapas 11 a 15 aceptadas y vigentes;
     *   ajuste: tipo de ajuste decidido (o null); minimo: el que indican los datos medibles (o null);
     *   continuidad_html: producto de la etapa 6; expost: null o ['data', 'pre', 'continuidad', 'tiempo' (de la confirmacion),
     *   'modificado']; expost_vals: lista como validaciones (Etapa 10);
     *   encuestas: [3 => respuestas|null, 4 => respuestas|null]; primer_acceso; vistas: ['guia', 'ejemplo'] (ts|null);
     *   actividad.
     * @return array
     */
    public static function facts(array $d): array {
        $ficha = $d['ficha'];
        $data = $d['data'];
        $ref = $d['ref'] ?? ['criterios' => []];
        $h = ['implid' => (int) $ficha['id'], 'userid' => (int) $ficha['userid'], 'activa' => !empty($ficha['activa']),
            'organismo' => trim((string) ($ficha['organismo'] ?? '')), 'plan' => trim((string) ($ficha['plan'] ?? '')),
            'modulo' => trim((string) ($ficha['modulo'] ?? '')), 'etiqueta' => (string) ($ficha['etiqueta'] ?? ''),
            'puntaje' => isset($ficha['puntaje']) ? (int) $ficha['puntaje'] : null,
            'creada' => (int) ($ficha['creada'] ?? 0)];

        // --- Fase 1: envios y validaciones de la Etapa 0 ---
        $envios1 = array_values($d['envios']['impl'] ?? []);
        $v1 = self::validation_summary((array) ($d['validaciones'] ?? []), implementacion::CRITICOS, $envios1);
        $h['f1'] = $v1;
        $tiempos1 = array_merge(array_column($envios1, 'time'), array_column((array) ($d['validaciones'] ?? []), 'time'));
        $h['primer_envio'] = $tiempos1 ? (int) min($tiempos1) : null;
        $h['fase1_ok'] = $v1['decision_final'] !== null && implementacion::can_advance($v1['decision_final']);
        $anio = (int) gmdate('Y', (int) ($h['creada'] ?: ($h['primer_envio'] ?? time())));
        $h['fecha_fin'] = self::parse_date((string) ($data['fechas'] ?? ''), $anio);
        $h['oportunidad'] = ($h['primer_envio'] !== null && $h['fecha_fin'] !== null)
            ? self::days($h['fecha_fin'], $h['primer_envio']) : null;
        $h['registro_pendiente'] = $h['primer_envio'] === null ? $h['fecha_fin'] : null;

        // --- Desempeño registrado en la ficha: logros recalculados y analisis de la Fase 2 ---
        $perf = implementacion::performance($data);
        $h['logros'] = ['total' => count($perf), 'recalculados' => count(array_filter($perf, static function(array $p): bool {
            return !$p['logro_ok'];
        }))];
        $h['analisis'] = null;
        if ($perf) {
            $a = calidad::analysis($data, $ref);
            $brechas = ['alta' => 0, 'media' => 0, 'baja' => 0];
            $presentes = 0;
            $nivelacion = 0;
            $criterios = [];
            foreach ($a['criterios'] as $c) {
                if ($c['criticidad'] !== null) {
                    $brechas[$c['criticidad']]++;
                }
                $presentes += $c['presentes'];
                $nivelacion += $c['nivelacion'];
                $criterios[] = ['n' => $c['n'], 'criterio' => $c['criterio'], 'logro' => $c['logro'],
                    'criticidad' => $c['criticidad'], 'nivelacion' => $c['nivelacion'], 'profundizacion' => $c['profundizacion'],
                    'presentes' => $c['presentes']];
            }
            $minimo = $a['decision']['minimo'];
            $determinantes = [];
            $claves = [0 => ($a['promedio'] !== null && $a['promedio'] >= calidad::LOGRO_MEDIA && $a['relevantes'] > 1) ? 'brechas' : 'logro',
                1 => $a['cobertura'] < calidad::COBERTURA_MIN ? 'cobertura' : 'tiempo', 2 => 'viabilidad'];
            // Con acciones remediales como minimo ninguna condicion obligo a mas: no hay condicion determinante.
            foreach ($a['decision']['filas'] as $i => $f) {
                if (calidad::adjustment_rank($minimo) > 1 && calidad::adjustment_rank($f['ajuste']) === calidad::adjustment_rank($minimo)) {
                    $determinantes[] = $claves[$i] ?? 'logro';
                }
            }
            $ajuste = $d['ajuste'] ?? null;
            if ($ajuste !== null && calidad::adjustment_rank($ajuste) > calidad::adjustment_rank($minimo)) {
                $determinantes[] = 'juicio';
            }
            $h['analisis'] = ['promedio' => $a['promedio'], 'cobertura' => $a['cobertura'], 'trabajados' => $a['trabajados'],
                'diseno' => $a['diseno'], 'pendientes' => $a['pendientes'], 'brechas' => $brechas, 'criterios' => $criterios,
                'relevantes' => $a['relevantes'], 'nivelacion_pct' => $presentes > 0 ? round(100 * $nivelacion / $presentes, 1) : null,
                'restante' => $a['tiempos']['restante'], 'minimo' => $minimo, 'determinantes' => array_values(array_unique($determinantes))];
        }
        $vals = [];
        foreach (['val31', 'val32', 'val33', 'val34'] as $k) {
            if (trim((string) ($data[$k] ?? '')) !== '') {
                $vals[] = (int) $data[$k];
            }
        }
        $h['fidelidad'] = self::mean($vals);

        // --- Fases 2 y 3: decision, tiempo de la continuidad, etapas ---
        $etapas = (array) ($d['etapas'] ?? []);
        $aceptadas = array_map('intval', (array) ($d['aceptadas'] ?? []));
        $aceptadas4 = array_map('intval', (array) ($d['aceptadas4'] ?? []));
        $h['ajuste'] = $d['ajuste'] ?? null;
        $h['minimo'] = $d['minimo'] ?? null;
        $h['continuidad_min'] = self::continuity_minutes((string) ($d['continuidad_html'] ?? ''));
        $restante = $h['analisis']['restante'] ?? null;
        $h['uso_tiempo'] = ($h['continuidad_min'] !== null && $restante !== null && $restante > 0)
            ? (int) round(100 * $h['continuidad_min'] / $restante) : null;
        $h['tiempo_excede'] = $h['continuidad_min'] !== null && $restante !== null && $h['continuidad_min'] > max(0, $restante);
        $h['etapas'] = [];
        foreach (array_merge(range(1, calidad::CONSOLIDACION), range(11, expost::CONSOLIDACION)) as $n) {
            $fila = $etapas[$n] ?? null;
            $cerrada = in_array($n, $n <= calidad::CONSOLIDACION ? $aceptadas : $aceptadas4, true);
            $cierre = (int) ($fila['cierre'] ?? 0);
            $h['etapas'][$n] = ['alcanzada' => $fila !== null, 'cerrada' => $cerrada, 'rondas' => (int) ($fila['rondas'] ?? 0),
                'dias' => ($fila && $cerrada && $cierre > 0) ? self::days((int) $fila['inicio'], $cierre) : null];
        }
        $h['fase2_ok'] = count(array_intersect([1, 2, 3], $aceptadas)) === 3;
        $h['fase3_ok'] = in_array(calidad::CONSOLIDACION, $aceptadas, true);
        $h['fase4_ok'] = in_array(expost::CONSOLIDACION, $aceptadas4, true);
        $h['consolidacion3'] = (int) ($etapas[calidad::CONSOLIDACION]['cierre'] ?? 0);
        $h['consolidacion4'] = (int) ($etapas[expost::CONSOLIDACION]['cierre'] ?? 0);
        $h['duracion'] = ($h['fase3_ok'] && $h['primer_envio'] !== null && $h['consolidacion3'] > 0)
            ? self::days($h['primer_envio'], $h['consolidacion3']) : null;

        // --- Fase 4: ficha ex post, validacion de la Etapa 10 y resultados del cierre ---
        $envios4 = array_values($d['envios']['expost'] ?? []);
        $v4 = self::validation_summary((array) ($d['expost_vals'] ?? []), expost::CRITICOS, $envios4);
        $h['f4'] = $v4;
        $h['expost_ok'] = $v4['decision_final'] !== null && implementacion::can_advance($v4['decision_final']);
        $h['expost_iniciada'] = !empty($d['expost']) && (!empty($d['expost']['continuidad']) || $envios4 || !empty($d['expost_vals']));
        $h['continuidad_confirmada'] = !empty($d['expost']['continuidad']);
        $h['fecha_continuidad'] = null;
        if (!empty($d['expost']['data']['fechas_cont'])) {
            $h['fecha_continuidad'] = self::parse_date((string) $d['expost']['data']['fechas_cont'], $anio);
        }
        $h['cierre'] = null;
        if ($h['expost_ok'] && !empty($d['expost']['pre'])) {
            $h['cierre'] = self::closing($d['expost']['data'], $d['expost']['pre'], (array) ($d['expost']['etq'] ?? []));
        }

        // --- Encuesta breve ---
        $h['encuestas'] = [3 => $d['encuestas'][3] ?? null, 4 => $d['encuestas'][4] ?? null];

        // --- Uso de la guia y el ejemplo, primer acceso, actividad ---
        $vistas = $d['vistas'] ?? [];
        foreach (['guia', 'ejemplo'] as $m) {
            $t = isset($vistas[$m]) ? (int) $vistas[$m] : 0;
            $h['vista_' . $m] = $t > 0 ? $t : null;
        }
        $h['primer_acceso'] = !empty($d['primer_acceso']) ? (int) $d['primer_acceso'] : null;
        $h['actividad'] = (int) ($d['actividad'] ?? 0);
        $h['etapa'] = self::current_stage($h);
        return $h;
    }

    /**
     * Resultados del cierre del ciclo (grupo E) de una ficha ex post validada. Funcion pura.
     *
     * @param array $data Ficha ex post limpia.
     * @param array $pre expost::preload().
     * @param array $etq Etiquetas (expost, buenas practicas).
     * @return array
     */
    public static function closing(array $data, array $pre, array $etq): array {
        $r = expost::results($data, $pre);
        $inicial = $r['promedio_inicial'];
        $final = $r['promedio_final'];
        $bajo = 0;
        $insuficientes = 0;
        foreach ($r['filas'] as $f) {
            if ($f['niveles_f'] !== null) {
                $bajo += $f['niveles_f']['ins'] + $f['niveles_f']['bas'];
                $insuficientes += $f['niveles_f']['ins'];
            }
        }
        $reaccion = [];
        foreach (['v42', 'v43', 'v44', 'v45', 'v46', 'v47'] as $k) {
            if (trim((string) ($data[$k] ?? '')) !== '') {
                $reaccion[] = (int) $data[$k];
            }
        }
        $seg = trim((string) ($data['n_seg5'] ?? '')) === '' ? null : (int) $data['n_seg5'];
        $apl = trim((string) ($data['n_apl5'] ?? '')) === '' ? null : (int) $data['n_apl5'];
        $propuestas = count((array) ($etq['practicas'] ?? []));
        $confirmadas = 0;
        for ($j = 1; $j <= expost::MAX_ETIQUETAS; $j++) {
            $confirmadas += ($data['bp' . $j . '_dec'] ?? '') === 'confirma' ? 1 : 0;
        }
        return ['inicial' => $inicial, 'final' => $final,
            'ganancia' => ($inicial !== null && $final !== null) ? round($final - $inicial, 1) : null,
            'por_criterio' => array_map(static function(array $f): array {
                return ['k' => $f['k'], 'criterio' => $f['criterio'], 'tipo' => $f['tipo'], 'inicial' => $f['inicial'],
                    'final' => $f['final'], 'variacion' => $f['variacion']];
            }, $r['filas']),
            'nivelacion' => $r['nivelacion'], 'profundizacion' => $r['profundizacion'],
            'persisten' => count($r['persisten']), 'bajo_adecuado' => $bajo, 'insuficientes' => $insuficientes,
            'reaccion' => self::mean($reaccion), 'transferencia' => ($seg !== null && $seg > 0 && $apl !== null)
                ? (int) round(100 * min($apl, $seg) / $seg) : null,
            'bp_propuestas' => $propuestas, 'bp_confirmadas' => $confirmadas];
    }

    /**
     * Etapa en que esta la evaluacion: 0 (Fase 1, hasta aprobar la ficha de registro), 1 a 8 (la primera etapa sin cerrar de
     * las Fases 2 y 3), 10 (ficha ex post sin aprobar), 11 a 15 (la primera etapa sin cerrar de la Fase 4) y 16 si el ciclo
     * esta completo. Funcion pura.
     *
     * @param array $h Hechos de facts() (con 'etapas', 'fase1_ok', 'expost_ok').
     * @return int
     */
    public static function current_stage(array $h): int {
        if (!$h['fase1_ok']) {
            return 0;
        }
        for ($n = 1; $n <= calidad::CONSOLIDACION; $n++) {
            if (!$h['etapas'][$n]['cerrada']) {
                return $n;
            }
        }
        if (!$h['expost_ok']) {
            return 10;
        }
        for ($n = 11; $n <= expost::CONSOLIDACION; $n++) {
            if (!$h['etapas'][$n]['cerrada']) {
                return $n;
            }
        }
        return 16;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Avisos de la vista individual.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Avisos activos de una evaluacion, con el criterio de la propuesta del cliente. Funcion pura.
     *
     * @param array $h Hechos de facts().
     * @param int $now
     * @param array $plazos ['registro', 'correccion', 'cierre', 'detenido'] en dias.
     * @return array Lista de ['tipo', 'clave' (unica por asunto), 'a' (datos para el texto)].
     */
    public static function notices(array $h, int $now, array $plazos): array {
        $dia = 86400;
        $p = static function(string $k) use ($plazos): int {
            return max(1, (int) ($plazos[$k] ?? self::PLAZO_DETENIDO));
        };
        $id = $h['implid'];
        $avisos = [];
        if ($h['registro_pendiente'] !== null && $now - $h['registro_pendiente'] > $p('registro') * $dia) {
            $avisos[] = ['tipo' => 'registro', 'clave' => 'registro-' . $id,
                'a' => ['dias' => (int) floor(($now - $h['registro_pendiente']) / $dia)]];
        }
        foreach (['f1' => 0, 'f4' => 10] as $clave => $etapa) {
            $f = $h[$clave];
            if ($f['correccion_pendiente'] !== null && $now - $f['correccion_pendiente'] > $p('correccion') * $dia) {
                $avisos[] = ['tipo' => 'correccion', 'clave' => 'correccion-' . $clave . '-' . $f['ultima'],
                    'a' => ['dias' => (int) floor(($now - $f['correccion_pendiente']) / $dia), 'etapa' => $etapa]];
            }
            if ($f['criticos_obs']) {
                $avisos[] = ['tipo' => 'critico', 'clave' => 'critico-' . $clave . '-' . $f['ultima'],
                    'a' => ['items' => $f['criticos_obs'], 'etapa' => $etapa]];
            }
        }
        if ($h['analisis']) {
            $bajos = array_filter($h['analisis']['criterios'], static function(array $c): bool {
                return $c['logro'] < self::LOGRO_ALTA;
            });
            if ($bajos) {
                $avisos[] = ['tipo' => 'brecha', 'clave' => 'brecha-' . $id . '-' . implode('.', array_column($bajos, 'n')),
                    'a' => ['criterios' => array_values(array_column($bajos, 'n'))]];
            }
        }
        if ($h['tiempo_excede']) {
            $avisos[] = ['tipo' => 'tiempo', 'clave' => 'tiempo-' . $id . '-' . $h['continuidad_min'],
                'a' => ['minutos' => $h['continuidad_min'], 'restante' => $h['analisis']['restante']]];
        }
        if ($h['fase3_ok'] && !$h['expost_iniciada'] && $h['f4']['envios'] === 0 && $h['f4']['ciclos'] === []) {
            $base = $h['fecha_continuidad'] ?? ($h['consolidacion3'] ?: null);
            if ($base !== null && $now - $base > $p('cierre') * $dia) {
                $avisos[] = ['tipo' => 'cierre', 'clave' => 'cierre-' . $id,
                    'a' => ['dias' => (int) floor(($now - $base) / $dia)]];
            }
        }
        // Detenido: empezo y no termino, sin una correccion pendiente (que ya tiene su aviso).
        if ($h['primer_envio'] !== null && $h['etapa'] < 16 && $h['f1']['correccion_pendiente'] === null
                && $h['f4']['correccion_pendiente'] === null && $h['actividad'] > 0
                && $now - $h['actividad'] > $p('detenido') * $dia) {
            $avisos[] = ['tipo' => 'detenido', 'clave' => 'detenido-' . $id . '-' . $h['etapa'],
                'a' => ['etapa' => $h['etapa'], 'dias' => (int) floor(($now - $h['actividad']) / $dia)]];
        }
        foreach ([3 => 'fase3_ok', 4 => 'fase4_ok'] as $fase => $ok) {
            if ($h[$ok] && $h['encuestas'][$fase] === null) {
                $avisos[] = ['tipo' => 'encuesta', 'clave' => 'encuesta-' . $fase . '-' . $id, 'a' => ['fase' => $fase]];
            }
        }
        return $avisos;
    }

    /**
     * Avisos que corresponde enviar ahora: los que nunca se enviaron, y los repetibles cuando ya paso su plazo desde el ultimo
     * envio. Funcion pura.
     *
     * @param array $avisos Salida de notices().
     * @param array $enviados clave => ultima vez.
     * @param int $now
     * @param array $plazos
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
     * Registro de avisos enviados: solo los asuntos que siguen activos, con la fecha del ultimo envio. Funcion pura.
     *
     * @param array $enviados clave => ultima vez.
     * @param array $recien Avisos que se acaban de enviar.
     * @param array $activos Avisos activos ahora.
     * @param int $now
     * @return array
     */
    public static function record(array $enviados, array $recien, array $activos, int $now): array {
        return tablero_m2::record($enviados, $recien, $activos, $now);
    }

    /**
     * Detalle de un aviso con sus datos (dias, items, minutos). Funcion pura.
     *
     * @param array $a Aviso de notices().
     * @return string
     */
    public static function notice_detail(array $a): string {
        $x = $a['a'];
        $etapa = static function(int $n): string {
            return $n === 0 ? 'la Etapa 0' : 'la Etapa ' . $n;
        };
        switch ($a['tipo']) {
            case 'registro':
                return 'han pasado ' . $x['dias'] . ' días desde el término de la implementación y la ficha de registro no se ha enviado.';
            case 'correccion':
                return 'tu ficha de ' . $etapa((int) $x['etapa']) . ' lleva ' . $x['dias'] . ' días sin reenviarse después del ❌ No aprobado.';
            case 'critico':
                $textos = (int) $x['etapa'] === 10 ? expost::checklist_texts() : implementacion::checklist_texts();
                return 'en ' . $etapa((int) $x['etapa']) . ', ítems ' . implode('; ', array_map(static function($id) use ($textos) {
                    return '«' . ($textos[$id] ?? $id) . '»';
                }, $x['items'])) . '.';
            case 'brecha':
                return 'criterio(s) ' . implode(', ', $x['criterios']) . ' con logro inferior a 30 %.';
            case 'tiempo':
                return 'la experiencia de continuidad dura ' . $x['minutos'] . ' minutos y el tiempo restante es ' . $x['restante'] . '.';
            case 'cierre':
                return 'han pasado ' . $x['dias'] . ' días sin iniciar la Fase 4.';
            case 'detenido':
                return $x['dias'] . ' días sin avanzar ' . ($x['etapa'] === 0 ? 'desde la Etapa 0.' : 'en la Etapa ' . $x['etapa'] . '.');
            case 'encuesta':
                return 'consolidaste la Fase ' . $x['fase'] . ' y falta la encuesta breve.';
        }
        return '';
    }

    // ------------------------------------------------------------------------------------------------------------
    // Vista acumulada.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Indicadores acumulados del programa (grupos A a F). Sin nombres: solo conteos, porcentajes, promedios y medianas.
     * Funcion pura.
     *
     * @param array $hechos Lista de facts() de las evaluaciones del curso (o del filtro).
     * @param int[] $inscritos Personas inscritas que cursan el modulo (userid).
     * @param int $disenos Diseños validados en el Modulo 2 de esas personas (los que se pueden evaluar).
     * @return array
     */
    public static function program(array $hechos, array $inscritos, int $disenos): array {
        $r = ['inscritos' => count($inscritos), 'evaluaciones' => count($hechos)];
        $inscritos = array_flip(array_map('intval', $inscritos));

        // --- A. Uso y adopcion ---
        $personas = [];
        foreach ($hechos as $h) {
            $p = $personas[$h['userid']] ?? ['entrega' => null, 'guia' => null, 'ejemplo' => null];
            if ($h['primer_envio'] !== null) {
                $p['entrega'] = $p['entrega'] === null ? $h['primer_envio'] : min($p['entrega'], $h['primer_envio']);
            }
            $p['guia'] = $p['guia'] ?? $h['vista_guia'];
            $p['ejemplo'] = $p['ejemplo'] ?? $h['vista_ejemplo'];
            $personas[$h['userid']] = $p;
        }
        $conentrega = array_filter($personas, static function($p) {
            return $p['entrega'] !== null;
        });
        $consultaron = 0;
        foreach ($conentrega as $p) {
            $g = $p['guia'] !== null && $p['guia'] <= $p['entrega'];
            $e = $p['ejemplo'] !== null && $p['ejemplo'] <= $p['entrega'];
            $consultaron += $g && $e ? 1 : 0;
        }
        $enviadas = array_filter($hechos, static function($h) {
            return $h['primer_envio'] !== null;
        });
        $r['a'] = ['con_ficha' => count($enviadas), 'disenos' => $disenos,
            'tasa_inicio' => self::pct(count($enviadas), $disenos),
            'uso_guia_ejemplo' => self::pct($consultaron, count($conentrega)), 'personas_entrega' => count($conentrega),
            'oportunidad' => self::median(array_filter(array_column($hechos, 'oportunidad'), static function($v) {
                return $v !== null;
            })), 'con_oportunidad' => count(array_filter(array_column($hechos, 'oportunidad'), static function($v) {
                return $v !== null;
            }))];

        // --- B. Registro y validacion (ficha de registro y ficha ex post) ---
        $b = [];
        foreach (['f1' => ['rotulo' => 'registro', 'criticos' => implementacion::CRITICOS, 'etapa' => 0],
                'f4' => ['rotulo' => 'expost', 'criticos' => expost::CRITICOS, 'etapa' => 10]] as $clave => $cfg) {
            $conenvios = array_filter($hechos, static function($h) use ($clave) {
                return $h[$clave]['envios'] > 0;
            });
            $completas = 0;
            $incompletos = 0;
            $faltantes = [];
            foreach ($conenvios as $h) {
                $completas += $h[$clave]['primer_envio_completo'] ? 1 : 0;
                $incompletos += $h[$clave]['envios_incompletos'];
                foreach ($h[$clave]['faltantes'] as $campo => $n) {
                    $faltantes[$campo] = ($faltantes[$campo] ?? 0) + $n;
                }
            }
            arsort($faltantes);
            $validadas = array_filter($hechos, static function($h) use ($clave) {
                return $h[$clave]['ciclos'] !== [];
            });
            $conds = [implementacion::APROBADO => 0, implementacion::OBSERVACIONES => 0, implementacion::NO_APROBADO => 0];
            $primera = $conds;
            $final = $conds;
            $ciclosaprob = [];
            $nval = 0;
            $nobs = 0;
            $poritem = array_fill_keys($cfg['criticos'], 0);
            foreach ($validadas as $h) {
                $f = $h[$clave];
                $primera[$f['decision_primera']] = ($primera[$f['decision_primera']] ?? 0) + 1;
                $final[$f['decision_final']] = ($final[$f['decision_final']] ?? 0) + 1;
                if ($f['ciclos_aprobacion'] !== null) {
                    $ciclosaprob[] = $f['ciclos_aprobacion'];
                }
                foreach ($f['val_criticos'] as $obs) {
                    $nval++;
                    $nobs += $obs ? 1 : 0;
                    foreach ($obs as $id) {
                        $poritem[$id]++;
                    }
                }
            }
            $b[$clave] = ['fichas_envio' => count($conenvios), 'primer_intento' => self::pct($completas, count($conenvios)),
                'envios_incompletos' => $incompletos,
                'faltantes' => array_map(static function($n) use ($incompletos) {
                    return ['n' => $n, 'pct' => self::pct($n, $incompletos)];
                }, $faltantes),
                'validadas' => count($validadas), 'primera' => $primera, 'final' => $final,
                'ciclos_promedio' => self::mean($ciclosaprob), 'aprobadas' => count($ciclosaprob),
                'validaciones' => $nval, 'criticos' => self::pct($nobs, $nval),
                'items_criticos' => array_map(static function($n) use ($nval) {
                    return ['n' => $n, 'pct' => self::pct($n, $nval)];
                }, $poritem)];
        }
        $criterios = 0;
        $recalculados = 0;
        foreach ($hechos as $h) {
            $criterios += $h['logros']['total'];
            $recalculados += $h['logros']['recalculados'];
        }
        $r['b'] = $b + ['recalculados' => ['n' => $recalculados, 'total' => $criterios, 'pct' => self::pct($recalculados, $criterios)]];

        // --- C. Resultados de la implementacion ---
        $conanalisis = array_values(array_filter(array_column($hechos, 'analisis')));
        $ciclos = count($conanalisis);
        $promedios = array_column($conanalisis, 'promedio');
        $tramos = ['menor30' => 0, '30a59' => 0, '60a89' => 0, '90mas' => 0];
        foreach ($promedios as $p) {
            $tramos[$p < 30 ? 'menor30' : ($p < 60 ? '30a59' : ($p < 90 ? '60a89' : '90mas'))]++;
        }
        $brechas = ['alta' => 0, 'media' => 0, 'baja' => 0];
        $conalta = 0;
        $pendientes = 0;
        $logrosporcriterio = [];
        foreach ($conanalisis as $a) {
            foreach ($brechas as $k => $_) {
                $brechas[$k] += $a['brechas'][$k];
            }
            $conalta += $a['brechas']['alta'] > 0 ? 1 : 0;
            $pendientes += $a['pendientes'];
        }
        $fidel = array_values(array_filter(array_column($hechos, 'fidelidad'), static function($v) {
            return $v !== null;
        }));
        $r['c'] = ['ciclos' => $ciclos, 'logro_promedio' => self::mean($promedios), 'tramos' => $tramos,
            'cobertura_promedio' => self::mean(array_column($conanalisis, 'cobertura')),
            'cobertura_menor' => self::pct(count(array_filter(array_column($conanalisis, 'cobertura'), static function($v) {
                return $v < 100;
            })), $ciclos),
            'brechas' => $brechas, 'brechas_promedio' => $ciclos > 0 ? round(array_sum($brechas) / $ciclos, 1) : null,
            'con_brecha_alta' => self::pct($conalta, $ciclos), 'pendientes' => $pendientes,
            'nivelacion_promedio' => self::mean(array_values(array_filter(array_column($conanalisis, 'nivelacion_pct'),
                static function($v) {
                    return $v !== null;
                }))),
            'fidelidad_promedio' => self::mean($fidel), 'fidelidad_n' => count($fidel)];

        // --- D. Decision y ajuste ---
        $condecision = array_filter($hechos, static function($h) {
            return $h['ajuste'] !== null;
        });
        $tipos = [calidad::AJUSTE_REMEDIALES => 0, calidad::AJUSTE_PARCIAL => 0, calidad::AJUSTE_TOTAL => 0];
        $det = ['logro' => 0, 'brechas' => 0, 'cobertura' => 0, 'tiempo' => 0, 'viabilidad' => 0, 'juicio' => 0];
        $usos = [];
        foreach ($condecision as $h) {
            $tipos[$h['ajuste']]++;
            foreach ((array) ($h['analisis']['determinantes'] ?? []) as $k) {
                $det[$k]++;
            }
            if ($h['uso_tiempo'] !== null) {
                $usos[] = $h['uso_tiempo'];
            }
        }
        $aprobadas = array_filter($hechos, static function($h) {
            return $h['fase1_ok'];
        });
        $embudo = [];
        $iteraciones = [];
        $duraciones = [];
        foreach (array_merge(range(1, calidad::CONSOLIDACION), range(11, expost::CONSOLIDACION)) as $n) {
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
        $r['d'] = ['con_decision' => count($condecision),
            'tipos' => array_map(static function($n) use ($condecision) {
                return ['n' => $n, 'pct' => self::pct($n, count($condecision))];
            }, $tipos),
            'determinantes' => array_map(static function($n) use ($condecision) {
                return ['n' => $n, 'pct' => self::pct($n, count($condecision))];
            }, $det),
            'uso_tiempo' => self::mean($usos), 'uso_tiempo_n' => count($usos),
            'poco_reserva' => self::pct(count(array_filter($usos, static function($u) {
                return $u >= 90;
            })), count($usos)),
            'aprobadas' => count($aprobadas), 'embudo' => $embudo, 'iteraciones' => $iteraciones, 'duracion_etapa' => $duraciones,
            'duracion_total' => self::median(array_filter(array_column($hechos, 'duracion'), static function($v) {
                return $v !== null;
            }))];

        // --- E. Cierre del ciclo ---
        $cerradas = array_values(array_filter(array_column($hechos, 'cierre')));
        $ne = count($cerradas);
        $mov = 0;
        $tot = 0;
        $tasas = [];
        foreach ($cerradas as $c) {
            $mov += $c['nivelacion']['mov'];
            $tot += $c['nivelacion']['tot'];
        }
        $reacciones = array_values(array_filter(array_column($cerradas, 'reaccion'), static function($v) {
            return $v !== null;
        }));
        $transf = array_values(array_filter(array_column($cerradas, 'transferencia'), static function($v) {
            return $v !== null;
        }));
        $bp = ['propuestas' => array_sum(array_column($cerradas, 'bp_propuestas')),
            'confirmadas' => array_sum(array_column($cerradas, 'bp_confirmadas'))];
        $r['e'] = ['ciclos' => $ne, 'logro_inicial' => self::mean(array_values(array_filter(array_column($cerradas, 'inicial'),
            static function($v) {
                return $v !== null;
            }))), 'logro_final' => self::mean(array_values(array_filter(array_column($cerradas, 'final'), static function($v) {
                return $v !== null;
            }))), 'ganancia' => self::mean(array_values(array_filter(array_column($cerradas, 'ganancia'), static function($v) {
                return $v !== null;
            }))), 'nivelacion' => ['mov' => $mov, 'tot' => $tot, 'pct' => self::pct($mov, $tot)],
            'profundizacion' => array_sum(array_column($cerradas, 'profundizacion')),
            'persisten' => array_sum(array_column($cerradas, 'persisten')),
            'bajo_adecuado' => array_sum(array_column($cerradas, 'bajo_adecuado')),
            'insuficientes' => array_sum(array_column($cerradas, 'insuficientes')),
            'reaccion' => self::mean($reacciones), 'transferencia' => self::mean($transf), 'transferencia_n' => count($transf),
            'bp' => $bp + ['pct' => self::pct($bp['confirmadas'], $bp['propuestas'])]];

        // --- F. Relacion entre modulos ---
        $portramo = ['alto' => [], 'menores' => [], 'importantes' => [], 'no' => []];
        $porajuste = [calidad::AJUSTE_REMEDIALES => [], calidad::AJUSTE_PARCIAL => [], calidad::AJUSTE_TOTAL => []];
        foreach ($hechos as $h) {
            if ($h['puntaje'] !== null && $h['analisis'] && $h['analisis']['promedio'] !== null) {
                $portramo[diseno::band($h['puntaje'])['clave']][] = $h['analisis']['promedio'];
            }
            if ($h['cierre'] && $h['cierre']['ganancia'] !== null && isset($porajuste[$h['ajuste']])) {
                $porajuste[$h['ajuste']][] = $h['cierre']['ganancia'];
            }
        }
        $r['f'] = ['tramos' => array_map(static function($v) {
            return ['promedio' => self::mean($v), 'n' => count($v)];
        }, $portramo), 'ajustes' => array_map(static function($v) {
            return ['promedio' => self::mean($v), 'n' => count($v)];
        }, $porajuste)];
        return $r;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Calidad del agente (encuesta breve).
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Indicadores del grupo G a partir de las encuestas. Funcion pura.
     *
     * @param array $encuestas Lista de ['fase' (3|4), 'respuestas', 'ajuste' (tipo decidido, o null)].
     * @param array $aplicadas ['3' => encuestas aplicadas en la Fase 3, '4' => en la Fase 4] (consolidaciones del periodo).
     * @return array
     */
    public static function agent(array $encuestas, array $aplicadas): array {
        $n = count($encuestas);
        $por = static function(array $filas, string $p): array {
            $v = [];
            foreach ($filas as $e) {
                if (isset($e['respuestas'][$p])) {
                    $v[] = (int) $e['respuestas'][$p];
                }
            }
            return $v;
        };
        $r = ['respondidas' => $n, 'aplicadas' => ['3' => (int) ($aplicadas[3] ?? 0), '4' => (int) ($aplicadas[4] ?? 0)]];
        $r['respuesta'] = ['3' => self::pct(count(array_filter($encuestas, static function($e) {
            return (int) $e['fase'] === 3;
        })), $r['aplicadas']['3']), '4' => self::pct(count(array_filter($encuestas, static function($e) {
            return (int) $e['fase'] === 4;
        })), $r['aplicadas']['4']),
            'total' => self::pct($n, $r['aplicadas']['3'] + $r['aplicadas']['4'])];
        $fases = [3 => [], 4 => []];
        foreach ($encuestas as $e) {
            $fases[(int) $e['fase']][] = $e;
        }
        $r['claridad'] = self::mean($por($encuestas, 'p1'));
        $r['precision'] = ['0' => self::mean($por($fases[3], 'p2')), '10' => self::mean($por($fases[4], 'p2'))];
        $r['fidelidad'] = self::mean($por($encuestas, 'p3'));
        $acuerdo = [];
        foreach ($fases[3] as $e) {
            if (isset($e['respuestas']['p4']) && !empty($e['ajuste'])) {
                $acuerdo[$e['ajuste']][] = (int) $e['respuestas']['p4'];
            }
        }
        $r['acuerdo'] = [];
        foreach ([calidad::AJUSTE_REMEDIALES, calidad::AJUSTE_PARCIAL, calidad::AJUSTE_TOTAL] as $tipo) {
            $v = $acuerdo[$tipo] ?? [];
            $r['acuerdo'][$tipo] = ['n' => count($v), 'pct' => self::pct(count(array_filter($v, static function($x) {
                return $x >= 4;
            })), count($v))];
        }
        $r['aplicabilidad'] = ['3' => self::mean($por($fases[3], 'p5')), '4' => self::mean($por($fases[4], 'p5'))];
        $r['ahorro'] = self::mean($por($encuestas, 'p6'));
        $errores = array_fill_keys(array_keys(self::error_types()), 0);
        $conerror = 0;
        foreach ($encuestas as $e) {
            if (($e['respuestas']['p7'] ?? '') === 'si') {
                $conerror++;
                $tipo = (string) ($e['respuestas']['p7tipo'] ?? 'otro');
                $errores[isset($errores[$tipo]) ? $tipo : 'otro']++;
            }
        }
        $r['errores'] = ['n' => $conerror, 'pct' => self::pct($conerror, $n), 'tipos' => $errores];
        $todas = [];
        $porpregunta = [];
        foreach (['p1', 'p2', 'p3', 'p4', 'p5', 'p6'] as $p) {
            $v = $por($encuestas, $p);
            $porpregunta[$p] = self::mean($v);
            $todas = array_merge($todas, $v);
        }
        $r['preguntas'] = $porpregunta;
        $r['indice'] = self::mean($todas);
        $r['comentarios'] = count(array_filter($encuestas, static function($e) {
            return !empty($e['respuestas']['p8']);
        }));
        return $r;
    }

    /**
     * Alertas de la administracion sobre las encuestas del periodo. Funcion pura.
     *
     * @param array $agente Salida de agent().
     * @param float|null $indiceprevio Indice de calidad del periodo anterior.
     * @return array Lista de ['tipo', 'a' (datos)].
     */
    public static function alerts(array $agente, ?float $indiceprevio): array {
        $a = [];
        if ($agente['respondidas'] > 0 && $agente['indice'] !== null) {
            if ($agente['indice'] < self::INDICE_MIN
                    || ($indiceprevio !== null && $indiceprevio - $agente['indice'] >= self::CAIDA_INDICE)) {
                $a[] = ['tipo' => 'global', 'a' => ['indice' => $agente['indice'], 'previo' => $indiceprevio]];
            }
            $bajas = array_keys(array_filter($agente['preguntas'], static function($v) {
                return $v !== null && $v < self::PREGUNTA_MIN;
            }));
            if ($bajas) {
                $a[] = ['tipo' => 'pregunta', 'a' => ['preguntas' => $bajas]];
            }
            $desacuerdo = array_keys(array_filter($agente['acuerdo'], static function($x) {
                return $x['n'] > 0 && $x['pct'] !== null && $x['pct'] < self::ACUERDO_MIN;
            }));
            if ($desacuerdo) {
                $a[] = ['tipo' => 'acuerdo', 'a' => ['tipos' => $desacuerdo]];
            }
            $repetidos = array_keys(array_filter($agente['errores']['tipos'], static function($n) {
                return $n >= self::ERRORES_REPETIDO;
            }));
            if (($agente['errores']['pct'] ?? 0) > self::ERRORES_MAX || $repetidos) {
                $a[] = ['tipo' => 'errores', 'a' => ['pct' => $agente['errores']['pct'], 'tipos' => $repetidos]];
            }
        }
        $total = $agente['aplicadas']['3'] + $agente['aplicadas']['4'];
        if ($total > 0 && $agente['respuesta']['total'] !== null && $agente['respuesta']['total'] < self::RESPUESTA_MIN) {
            $a[] = ['tipo' => 'respuesta', 'a' => ['pct' => $agente['respuesta']['total']]];
        }
        return $a;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Lectura de la base de datos.
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Plazos «X» de los avisos (ajustes del plugin; el cliente los define antes de la puesta en produccion).
     *
     * @return array ['registro', 'correccion', 'cierre', 'detenido'] en dias.
     */
    public static function plazos(): array {
        $salida = [];
        foreach (['registro' => self::PLAZO_REGISTRO, 'correccion' => self::PLAZO_CORRECCION, 'cierre' => self::PLAZO_CIERRE,
                'detenido' => self::PLAZO_DETENIDO] as $k => $defecto) {
            $v = (int) get_config('local_chatbot', 'm3plazo' . $k);
            $salida[$k] = $v > 0 ? $v : $defecto;
        }
        return $salida;
    }

    /**
     * Personas inscritas que cursan el modulo (pueden usar el asistente y no editan el curso).
     *
     * @param int $courseid
     * @param int $groupid 0 para todo el curso.
     * @return int[]
     */
    public static function enrolled(int $courseid, int $groupid = 0): array {
        return tablero_m2::enrolled($courseid, $groupid);
    }

    /**
     * Hechos de las evaluaciones de un curso (de una persona, o de un conjunto de personas).
     *
     * @param int $courseid
     * @param int[]|null $userids null para todas.
     * @param bool $conlogs Leer del registro de Moodle el primer acceso y la consulta de la guia y el ejemplo (la tarea de
     *                      avisos no los usa).
     * @return array implid => hechos.
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
        $fichas = $DB->get_records_select('local_chatbot_impl', $where, $params, 'id');
        if (!$fichas) {
            return [];
        }
        $ids = array_keys($fichas);
        [$inf, $pf] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'f');
        $agrupar = static function(array $filas, string $campo): array {
            $salida = [];
            foreach ($filas as $fila) {
                $salida[(int) $fila->$campo][] = $fila;
            }
            return $salida;
        };
        $validaciones = $agrupar($DB->get_records_select('local_chatbot_impl_val', 'fichaid ' . $inf, $pf, 'id',
            'id, fichaid, estado, condicion, huella, informe, timecreated'), 'fichaid');
        $calidad = $agrupar($DB->get_records_select('local_chatbot_calidad', 'implid ' . $inf, $pf, 'etapa'), 'implid');
        $expost = [];
        foreach ($DB->get_records_select('local_chatbot_expost', 'implid ' . $inf, $pf) as $e) {
            $expost[(int) $e->implid] = $e;
        }
        $exvals = [];
        if ($expost) {
            [$ine, $pe] = $DB->get_in_or_equal(array_column($expost, 'id'), SQL_PARAMS_NAMED, 'e');
            $exvals = $agrupar($DB->get_records_select('local_chatbot_expost_val', 'expostid ' . $ine, $pe, 'id',
                'id, expostid, estado, condicion, huella, informe, timecreated'), 'expostid');
        }
        $envios = [];
        foreach ($DB->get_records_select('local_chatbot_m3_envio', 'implid ' . $inf, $pf, 'id') as $e) {
            $envios[(int) $e->implid][$e->tipo][] = ['completo' => (bool) $e->completo,
                'faltantes' => (array) json_decode((string) $e->faltantes, true), 'time' => (int) $e->timecreated];
        }
        $encuestas = [];
        foreach ($DB->get_records_select('local_chatbot_m3_encuesta', 'implid ' . $inf, $pf) as $e) {
            $encuestas[(int) $e->implid][(int) $e->fase] = (array) json_decode((string) $e->respuestas, true);
        }
        $personas = array_unique(array_map('intval', array_column($fichas, 'userid')));
        [$accesos, $vistas] = $conlogs ? tablero_m2::load_logs($courseid, $personas) : [[], []];

        $disenos = [];
        $hechos = [];
        foreach ($fichas as $f) {
            $iid = (int) $f->id;
            $data = implementacion::clean((array) json_decode((string) $f->data, true));
            $did = (int) $f->disenoid;
            if ($did > 0 && !array_key_exists($did, $disenos)) {
                $diseno = exante::get_ficha_by_id($did, (int) $f->userid);
                $disenos[$did] = $diseno ? ['data' => $diseno->data, 'puntaje' => ruta_m2::state_for($diseno)] : null;
            }
            $dd = $disenos[$did] ?? null;
            $ref = $dd ? implementacion::design_reference($dd['data'], '') : ['criterios' => []];
            $evaluacion = $dd['puntaje']['evaluacion'] ?? null;

            $actividad = (int) $f->timemodified;
            $vals = [];
            foreach ($validaciones[$iid] ?? [] as $v) {
                $informe = (array) json_decode((string) $v->informe, true);
                $vals[] = ['id' => (int) $v->id, 'estado' => (string) $v->estado, 'condicion' => (string) $v->condicion,
                    'items' => (array) ($informe['items'] ?? []), 'time' => (int) $v->timecreated];
                $actividad = max($actividad, (int) $v->timecreated);
            }
            $filas = [];
            $et = [];
            foreach ($calidad[$iid] ?? [] as $fila) {
                $filas[(int) $fila->etapa] = $fila;
                $et[(int) $fila->etapa] = ['estado' => (string) $fila->estado, 'inicio' => (int) $fila->timecreated,
                    'cierre' => (int) $fila->timeaccepted, 'rondas' => (int) $fila->rondas,
                    'modificado' => (int) $fila->timemodified];
                $actividad = max($actividad, (int) $fila->timemodified);
            }
            $ultimaf1 = $vals ? end($vals) : null;
            $huella1 = $ultimaf1 ? (string) ($validaciones[$iid][count($validaciones[$iid]) - 1]->huella ?? '') : '';
            $filas123 = array_filter($filas, static function($k) {
                return $k <= calidad::CONSOLIDACION;
            }, ARRAY_FILTER_USE_KEY);
            $aceptadas = calidad::accepted($filas123, $huella1);
            $ajuste = calidad::decided_adjustment($filas123);
            $info3 = calidad::decision_info($filas123[3] ?? null);

            $ex = $expost[$iid] ?? null;
            $exv = [];
            $huella4 = '';
            $exdatos = null;
            if ($ex) {
                foreach ($exvals[(int) $ex->id] ?? [] as $v) {
                    $informe = (array) json_decode((string) $v->informe, true);
                    $exv[] = ['id' => (int) $v->id, 'estado' => (string) $v->estado, 'condicion' => (string) $v->condicion,
                        'items' => (array) ($informe['items'] ?? []), 'time' => (int) $v->timecreated];
                    $huella4 = (string) $v->huella;
                    $actividad = max($actividad, (int) $v->timecreated);
                }
                $actividad = max($actividad, (int) $ex->timemodified);
                $pre = $ex->precarga ? json_decode((string) $ex->precarga, true) : null;
                $etq = $ex->etiquetas ? json_decode((string) $ex->etiquetas, true) : null;
                $exdatos = ['data' => expost::clean((array) json_decode((string) $ex->data, true)),
                    'pre' => is_array($pre) ? $pre : null, 'etq' => is_array($etq) ? $etq : [],
                    'continuidad' => !empty($ex->continuidad), 'tiempo' => (int) $ex->timecontinuidad,
                    'modificado' => (int) $ex->timemodified];
            }
            $filas4 = array_filter($filas, static function($k) {
                return $k >= 11;
            }, ARRAY_FILTER_USE_KEY);
            $uid = (int) $f->userid;
            $hechos[$iid] = self::facts(['ficha' => ['id' => $iid, 'userid' => $uid, 'activa' => (int) $f->activa,
                    'organismo' => (string) ($dd['data']['organismo'] ?? ''), 'plan' => (string) ($dd['data']['plan'] ?? ($data['plan'] ?? '')),
                    'modulo' => (string) ($dd['data']['modulo'] ?? ($data['modulo'] ?? '')),
                    'etiqueta' => implementacion::evaluation_label($data), 'puntaje' => isset($evaluacion['total']) ? (int) $evaluacion['total'] : null,
                    'creada' => (int) $f->timecreated],
                'data' => $data, 'ref' => $ref, 'validaciones' => $vals, 'envios' => ['impl' => $envios[$iid]['impl'] ?? [],
                    'expost' => $envios[$iid]['expost'] ?? []],
                'etapas' => $et, 'aceptadas' => $aceptadas, 'aceptadas4' => $ex ? expost::accepted($filas4, $huella4) : [],
                'ajuste' => $ajuste, 'minimo' => $info3['minimo'],
                'continuidad_html' => isset($filas[6]) ? (string) $filas[6]->contenido : '',
                'expost' => $exdatos, 'expost_vals' => $exv, 'encuestas' => $encuestas[$iid] ?? [],
                'primer_acceso' => $accesos[$uid] ?? null, 'vistas' => $vistas[$uid] ?? [], 'actividad' => $actividad]);
        }
        return $hechos;
    }

    /**
     * Registra un envio de una ficha al control de entrada (pase o no).
     *
     * @param \stdClass|null $impl Ficha de registro de la implementacion.
     * @param string $tipo impl (ficha de registro) o expost (ficha de evaluacion ex post).
     * @param string[] $faltantes Lo que falto.
     * @return void
     */
    public static function log_submission(?\stdClass $impl, string $tipo, array $faltantes): void {
        global $DB;
        if (!$impl) {
            return;
        }
        // El registro es para el tablero: si falla, la validacion de la ficha sigue igual.
        try {
            $DB->insert_record('local_chatbot_m3_envio', (object) ['implid' => (int) $impl->id, 'userid' => (int) $impl->userid,
                'courseid' => (int) $impl->courseid, 'tipo' => $tipo === 'expost' ? 'expost' : 'impl',
                'completo' => $faltantes ? 0 : 1, 'faltantes' => json_encode(array_values($faltantes), JSON_UNESCAPED_UNICODE),
                'timecreated' => time()]);
        } catch (\Throwable $e) {
            debugging('No se pudo registrar el envio de la ficha: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Encuestas de un curso en la forma que pide agent(), con su tipo de ajuste decidido, en el periodo y el periodo anterior.
     *
     * @param int $courseid
     * @param array $hechos Hechos de las evaluaciones del curso (load_facts()).
     * @param int $desde Comienzo del periodo (0 para todo).
     * @param int $ahora
     * @return array ['actual' => lista, 'previo' => lista, 'aplicadas' => [3 => n, 4 => n]].
     */
    public static function load_surveys(int $courseid, array $hechos, int $desde, int $ahora): array {
        global $DB;
        $salida = ['actual' => [], 'previo' => [], 'aplicadas' => [3 => 0, 4 => 0]];
        $largo = $desde > 0 ? $ahora - $desde : 0;
        foreach ($hechos as $h) {
            foreach ([3 => ['fase3_ok', 'consolidacion3'], 4 => ['fase4_ok', 'consolidacion4']] as $fase => [$ok, $campo]) {
                if ($h[$ok] && ($desde <= 0 || $h[$campo] >= $desde)) {
                    $salida['aplicadas'][$fase]++;
                }
            }
        }
        $ajustes = array_column($hechos, 'ajuste', 'implid');
        foreach ($DB->get_records('local_chatbot_m3_encuesta', ['courseid' => $courseid], 'id') as $e) {
            if (!array_key_exists((int) $e->implid, $ajustes)) {
                continue;
            }
            $fila = ['fase' => (int) $e->fase, 'respuestas' => (array) json_decode((string) $e->respuestas, true),
                'ajuste' => $ajustes[(int) $e->implid] ?? null];
            if ($desde <= 0 || (int) $e->timecreated >= $desde) {
                $salida['actual'][] = $fila;
            } else if ($largo > 0 && (int) $e->timecreated >= $desde - $largo) {
                $salida['previo'][] = $fila;
            }
        }
        return $salida;
    }

    /**
     * Cantidad de diseños validados en el Modulo 2 (los que se pueden evaluar) de un conjunto de personas.
     *
     * @param int[] $userids
     * @return int
     */
    public static function count_designs(array $userids): int {
        $n = 0;
        foreach ($userids as $uid) {
            foreach (implementacion::list_designs((int) $uid) as $d) {
                $n += $d['disponible'] ? 1 : 0;
            }
        }
        return $n;
    }

    /**
     * Envia por las notificaciones de Moodle los avisos que correspondan a una persona (sus evaluaciones en uso).
     *
     * @param \stdClass $user
     * @param int $courseid
     * @param array $hechos Hechos de sus evaluaciones en el curso.
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
            $lineas[] = $textos[$a['tipo']]['nombre'] . ': ' . self::notice_detail($a) . ' ' . $textos[$a['tipo']]['sugerencia'];
        }
        $url = new \moodle_url('/local/chatbot/tablero_m3.php', ['courseid' => $courseid]);
        $mensaje = new \core\message\message();
        $mensaje->component = 'local_chatbot';
        $mensaje->name = 'avisom3';
        $mensaje->userfrom = \core_user::get_noreply_user();
        $mensaje->userto = $user;
        $mensaje->subject = get_string('m3dash_notice_subject', 'local_chatbot');
        $mensaje->fullmessage = implode("\n", array_map(static function($l) {
            return '• ' . $l;
        }, $lineas)) . "\n\n" . $url->out(false);
        $mensaje->fullmessageformat = FORMAT_PLAIN;
        $mensaje->fullmessagehtml = '<ul><li>' . implode('</li><li>', array_map('s', $lineas)) . '</li></ul><p><a href="'
            . $url->out() . '">' . s(get_string('m3dash_notice_go', 'local_chatbot')) . '</a></p>';
        $mensaje->smallmessage = get_string('m3dash_notice_subject', 'local_chatbot');
        $mensaje->notification = 1;
        $mensaje->contexturl = $url->out(false);
        $mensaje->contexturlname = get_string('m3dash_notice_go', 'local_chatbot');
        $mensaje->courseid = $courseid;
        message_send($mensaje);
        return count($pendientes);
    }
}
