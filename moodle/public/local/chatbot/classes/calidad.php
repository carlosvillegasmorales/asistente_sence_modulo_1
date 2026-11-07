<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Modulo 3, Fases 2 y 3: evaluacion de la implementacion (etapas 1 a 3) y ajuste de la experiencia de aprendizaje
 * (etapas 4 a 8), segun el Prompt Maestro del Asistente de Aseguramiento de la Calidad de los Aprendizajes.
 *
 * Como en el Modulo 2, cada etapa es una hoja de trabajo: la IA propone el producto a partir de la ficha validada en la
 * Fase 1, del diseño elegido y de las etapas ya aceptadas; la persona lo edita, pide ajustes, lo acepta y lo descarga en
 * Word. No se avanza a la etapa siguiente sin aceptar la anterior («no avanzar automaticamente entre etapas»), ni se entra
 * sin la ficha validada («este asistente NO trabaja sin el diseño seleccionado, su plan formativo y la ficha enviada»).
 *
 * Lo que es calculo no lo deja el codigo en manos del modelo: logro por criterio, logro promedio, cobertura evaluativa,
 * criticidad de las brechas, distribucion de participantes, tiempo restante y la decision que indican los datos medibles
 * (analysis()) se entregan al modelo como datos, y la suma de los tiempos de la etapa 4 se revisa contra el tiempo restante
 * (time_check()). El tipo de ajuste que decide el modelo en la etapa 3 nunca puede ser menos exigente que ese minimo
 * (decision_warning()).
 *
 * Lo que no lleva prefijo de BD es funcion pura y lo ejercita cli/verificar_calidad.php.
 */
class calidad {

    /** @var string Version del prompt: se guarda con cada producto (RU-PRIV-006). */
    const PROMPT_VERSION = 'M3-F2-etapas-v1';

    /** Estado del producto. */
    const BORRADOR = 'borrador';
    const ACEPTADO = 'aceptado';

    /** @var int Tope de caracteres de cada etapa previa y de cada producto del diseño que viajan al modelo. */
    const PREVIA_MAX = 20000;

    /** @var int La etapa 8 consolida todo y no se edita. */
    const CONSOLIDACION = 8;

    /** @var int Segundos que puede tardar la consolidacion con el asistente (un documento largo, en extenso). */
    const CONSOLIDACION_MAX = 900;

    /** @var float Parte minima del texto de las etapas que debe conservar la consolidacion para darla por completa. */
    const CONSOLIDACION_MIN = 0.5;

    /** Tipos de ajuste (etapa 3). */
    const AJUSTE_REMEDIALES = 'acciones_remediales';
    const AJUSTE_PARCIAL = 'rediseno_parcial';
    const AJUSTE_TOTAL = 'rediseno_total';

    /** @var float Logro desde el que un criterio no tiene brecha, y limites de criticidad (Prompt Maestro, etapa 2). */
    const LOGRO_SIN_BRECHA = 90.0;
    const LOGRO_MEDIA = 60.0;
    const LOGRO_ALTA = 30.0;

    /** @var float Cobertura evaluativa minima para acciones remediales. */
    const COBERTURA_MIN = 50.0;

    /** @var int[][] Reparto del tiempo restante por criticidad, en %: minimo y maximo (Prompt Maestro, etapa 4). */
    const REPARTO = ['alta' => [50, 70], 'media' => [20, 40], 'baja' => [0, 20]];

    /**
     * Las etapas 1 a 7, con lo que el Prompt Maestro pide que incluya cada una.
     *
     * @return array numero => ['titulo', 'archivo', 'incluye' (string[]), 'comprobar' (string[], opcional), 'indicaciones'
     *         (string[], solo para la IA), 'nota' (string)].
     */
    public static function etapas(): array {
        return [
            1 => ['titulo' => 'Análisis de la implementación', 'archivo' => 'Etapa_1_Analisis_de_la_implementacion',
                'incluye' => [
                    'Resultado de la validación (Etapa 0) y observaciones no resueltas, incorporadas como restricciones del análisis',
                    'Coherencia entre diseño y ejecución (sección 3 de la ficha)',
                    'Registro de cambios en la implementación, en una tabla con: elemento del diseño afectado, tipo de cambio '
                        . '(omisión, modificación, sustitución o incorporación), causa (tiempo, características del grupo, '
                        . 'recursos, decisión de la persona facilitadora u otra) y efecto sobre el logro del criterio relacionado '
                        . '(mejoró la experiencia, neutro o explica una brecha)',
                    'Desempeño por criterio de evaluación, en una tabla con: criterio, evidencias, número de participantes por '
                        . 'nivel, logro (%) y patrones de desempeño (errores frecuentes y fortalezas)',
                    'Logro promedio de los criterios trabajados',
                    'Cobertura evaluativa y listado de pendientes: cobertura = criterios trabajados ÷ criterios del diseño × 100; '
                        . 'pendientes = criterios no trabajados y evaluaciones no aplicadas',
                    'Condiciones del entorno y recursos (sección 5 de la ficha) y su influencia en la implementación',
                    'Contraste entre el juicio fundado de la persona facilitadora (sección 6) y la evidencia registrada',
                ],
                'indicaciones' => [
                    'Los datos numéricos (logro por criterio, logro promedio, cobertura evaluativa y tiempo restante) los calcula '
                        . 'la plataforma y vienen en «DATOS CALCULADOS POR LA PLATAFORMA»: transcríbelos sin recalcularlos.',
                    'Registro de cambios: una fila por cada cambio declarado en 2.2 y en los comentarios de la sección 3 y por '
                        . 'cada cambio no declarado que detectes al comparar el diseño con la ficha (por ejemplo, un criterio del '
                        . 'diseño que no aparece en 2.1 ni en 2.2): en ese caso márcalo como «no declarado» y pide la explicación '
                        . 'a la persona facilitadora.',
                    'Los cambios son decisiones de la persona facilitadora y se analizan como hallazgos para fundamentar la '
                        . 'mejora o el rediseño; nunca como errores.',
                ],
                'nota' => ''],
            2 => ['titulo' => 'Identificación de brechas y pendientes', 'archivo' => 'Etapa_2_Brechas_y_pendientes',
                'incluye' => [
                    'Clasificación de brechas y pendientes: brechas de aprendizaje, brechas de diseño, brechas de implementación '
                        . 'y pendientes',
                    'Tabla de brechas y pendientes, con: tipo, criterio o componente, evidencia, magnitud (logro o descripción) y '
                        . 'criticidad',
                    'Distribución de participantes que requieren nivelación (niveles Insuficiente y Básico) y que pueden '
                        . 'profundizar (niveles Adecuado y Avanzado), por criterio',
                ],
                'indicaciones' => [
                    'Brechas de aprendizaje: cada criterio trabajado con logro inferior a 90 %. Brechas de diseño: componentes '
                        . 'del diseño (secuencia, tiempos, estrategia, materiales o evaluación) que la evidencia muestra como no '
                        . 'viables o no pertinentes. Brechas de implementación: cambios o condiciones de la implementación que '
                        . 'explican un menor logro. Pendientes: criterios del diseño no trabajados o no evaluados y evaluaciones no '
                        . 'aplicadas.',
                    'Criticidad de cada brecha de aprendizaje y de cada pendiente: Alta (logro inferior a 30 %, o criterio '
                        . 'pendiente vinculado al desempeño central del aprendizaje esperado), Media (logro entre 30 % y menos de '
                        . '60 %, o pendiente de carácter complementario) y Baja (logro entre 60 % y menos de 90 %). La criticidad '
                        . 'de las brechas de aprendizaje ya viene calculada por la plataforma: úsala tal cual.',
                    'Los criterios no trabajados o no evaluados no cuentan como brecha ni como logro: son pendientes.',
                ],
                'nota' => ''],
            3 => ['titulo' => 'Decisión del tipo de ajuste', 'archivo' => 'Etapa_3_Decision_del_ajuste',
                'incluye' => [
                    'Tabla de decisión, con: dimensión, dato de la ficha, condición que se cumple y ajuste que indica',
                    'Tipo de ajuste decidido y justificación',
                    'Implicancias para la Fase 3',
                ],
                'indicaciones' => [
                    'La decisión integra tres dimensiones: (1) logro promedio de los criterios trabajados, (2) cobertura evaluativa '
                        . 'y pendientes, y (3) realización de lo diseñado (preguntas 3.1 y 3.3 de la ficha).',
                    'Acciones remediales: se cumplen las tres condiciones siguientes: logro igual o superior a 60 % con brechas '
                        . 'acotadas (de criticidad baja o, como máximo, una brecha relevante); cobertura igual o superior a 50 % y '
                        . 'pendientes que caben en el tiempo restante; y diseño realizado en lo sustancial (3.1 y 3.3 valoradas en 3 '
                        . 'o 4).',
                    'Rediseño parcial: se cumple al menos una de las siguientes condiciones: logro igual o superior a 30 % y '
                        . 'menor que 60 %; logro igual o superior a 60 % con más de una brecha relevante (criticidad media o alta); '
                        . 'cobertura inferior a 50 %; pendientes que no caben en el tiempo restante; u omisiones o modificaciones '
                        . 'que muestran que la secuencia o los tiempos del diseño no son viables (3.1 valorada en 1 o 2).',
                    'Rediseño total: logro inferior a 30 %, o estrategia aplicada y no pertinente.',
                    'Prevalece la condición más exigente: basta una condición de rediseño parcial o total para que corresponda ese '
                        . 'ajuste. Antes de concluir que el diseño no es pertinente, verifica si se implementó en lo sustancial: si no '
                        . 'se implementó, distingue si el problema está en la viabilidad del diseño o en las condiciones de la '
                        . 'implementación, y fundamenta la decisión en consecuencia. En todos los casos, los pendientes se incorporan '
                        . 'a la experiencia de aprendizaje de continuidad.',
                ],
                'nota' => 'La plataforma calcula la decisión que indican los datos medibles; tu decisión no puede ser menos '
                    . 'exigente que ella (solo puedes agravarla por lo que no es medible: si los pendientes caben en el tiempo '
                    . 'restante o si la estrategia aplicada fue pertinente).'],
            4 => ['titulo' => 'Acciones remediales o plan de rediseño', 'archivo' => 'Etapa_4_Acciones_remediales_o_rediseno',
                'incluye' => [], 'nota' => ''],
            5 => ['titulo' => 'Mejora del diseño y buenas prácticas', 'archivo' => 'Etapa_5_Mejora_del_diseno',
                'incluye' => [
                    'Ajustes al diseño de la experiencia de aprendizaje para futuras implementaciones: secuencia, tiempos, '
                        . 'estrategias, evaluación y materiales',
                    'Buenas prácticas: cambios de implementación que mejoraron la experiencia y aspectos pertinentes registrados '
                        . 'en 6.2, indicando qué funcionó, por qué y en qué contextos puede replicarse',
                    'Decisiones de replicabilidad y escalabilidad del diseño ajustado',
                ],
                'nota' => ''],
            6 => ['titulo' => 'Diseño de la experiencia de aprendizaje de continuidad',
                'archivo' => 'Etapa_6_Experiencia_de_continuidad',
                'incluye' => [
                    'Nombre de la experiencia de aprendizaje de continuidad',
                    'Propósito',
                    'Aprendizaje esperado',
                    'Criterios de evaluación (los criterios con brecha y los pendientes)',
                    'Organización de las trayectorias y de las personas participantes',
                    'Secuencia instruccional (Inicio / Desarrollo / Cierre), con actividades diferenciadas por trayectoria, '
                        . 'tiempos en minutos y evidencias parciales; la suma total no debe superar el tiempo restante',
                    'Materiales de apoyo',
                    'Plan de evaluación',
                    'Instrumentos de evaluación ajustados, redactados y listos para utilizar',
                ],
                'comprobar' => ['Nombre de la experiencia', 'Propósito', 'Aprendizaje esperado', 'Criterios de evaluación',
                    'Organización de las trayectorias', 'Secuencia instruccional', 'Materiales de apoyo', 'Plan de evaluación',
                    'Instrumentos de evaluación'],
                'indicaciones' => [
                    'La experiencia de continuidad siempre la diseñas tú, cualquiera sea el tipo de ajuste: con acciones '
                        . 'remediales, las integra; con rediseño parcial o total, se construye sobre el diseño rediseñado.',
                    'Debe permitir superar las brechas de aprendizaje de todas las personas participantes mediante dos '
                        . 'trayectorias simultáneas: la trayectoria de nivelación (para quienes están en niveles Insuficiente o '
                        . 'Básico, orientada a alcanzar al menos el nivel Adecuado en los criterios con brecha) y la trayectoria de '
                        . 'profundización (para quienes están en niveles Adecuado o Avanzado, orientada a profundizar su dominio '
                        . 'mediante mayor complejidad, transferencia a nuevas situaciones o apoyo a sus pares). Los pendientes se '
                        . 'incorporan en ambas trayectorias.',
                    'La secuencia instruccional (Inicio / Desarrollo / Cierre) lleva actividades diferenciadas por trayectoria, '
                        . 'con tiempos en minutos y evidencias parciales; como las trayectorias son simultáneas, el tiempo total de '
                        . 'la experiencia (Inicio + Desarrollo + Cierre) no debe superar el tiempo restante.',
                ],
                'nota' => ''],
            7 => ['titulo' => 'Síntesis', 'archivo' => 'Etapa_7_Sintesis',
                'incluye' => [
                    'Principales hallazgos',
                    'Brechas y pendientes',
                    'Tipo de ajuste decidido y su justificación',
                    'Acciones definidas',
                    'Recomendaciones para la persona facilitadora',
                ],
                'nota' => ''],
        ];
    }

    /**
     * Definicion de una etapa. La etapa 4 depende del tipo de ajuste decidido en la etapa 3.
     *
     * @param int $etapa 1 a 8.
     * @param string|null $ajuste AJUSTE_* decidido (solo cambia la etapa 4).
     * @return array Mismo formato que etapas().
     */
    public static function stage_def(int $etapa, ?string $ajuste = null): array {
        if ($etapa === self::CONSOLIDACION) {
            return self::etapa_consolidacion();
        }
        $def = self::etapas()[$etapa] ?? [];
        if ($etapa !== 4) {
            return $def;
        }
        $tiempo = 'Distribución del tiempo restante, en una tabla con las columnas «Acción o componente», «Criticidad» y '
            . '«Minutos» (una fila por acción o componente y una fila final «Total»); la suma no debe superar el tiempo restante';
        if ($ajuste === self::AJUSTE_REMEDIALES) {
            $def['incluye'] = [
                'Acciones remediales: una acción remedial por cada brecha de aprendizaje y por cada pendiente, cada una con: '
                    . 'nombre, aprendizaje esperado, criterio de evaluación, criticidad, propósito, estrategia, actividades, '
                    . 'evidencia, instrumento, tiempo en minutos y recursos',
                $tiempo,
                'Justificación de la distribución del tiempo restante',
            ];
            $def['comprobar'] = ['Acciones remediales', 'Distribución del tiempo restante', 'Justificación de la distribución'];
        } else {
            $def['incluye'] = [
                'Componentes del diseño que deben rediseñarse (secuencia, tiempos, estrategia, materiales o evaluación), '
                    . 'fundamentados en la evidencia de la ficha',
                $ajuste === self::AJUSTE_TOTAL
                    ? 'Rediseño completo de la experiencia de aprendizaje (modalidad, estrategia, secuencia, materiales y '
                        . 'evaluación), manteniendo el aprendizaje esperado y los criterios de evaluación del plan formativo'
                    : 'Nueva versión de cada componente rediseñado, manteniendo los componentes que funcionaron',
                $tiempo,
                'Justificación de la distribución del tiempo restante',
            ];
            $def['comprobar'] = ['Componentes del diseño que deben rediseñarse',
                $ajuste === self::AJUSTE_TOTAL ? 'Rediseño completo' : 'Nueva versión de cada componente rediseñado',
                'Distribución del tiempo restante', 'Justificación de la distribución'];
        }
        $def['indicaciones'] = [
            $ajuste === self::AJUSTE_REMEDIALES
                ? 'El tipo de ajuste decidido en la etapa 3 es ACCIONES REMEDIALES: diseña una acción remedial por cada brecha de '
                    . 'aprendizaje y pendiente, con todos los datos que pide la lista.'
                : ($ajuste === self::AJUSTE_TOTAL
                    ? 'El tipo de ajuste decidido en la etapa 3 es REDISEÑO TOTAL: rediseña completamente la experiencia de '
                        . 'aprendizaje (modalidad, estrategia, secuencia, materiales y evaluación), fundamentando cada decisión en '
                        . 'la evidencia de la ficha y manteniendo el aprendizaje esperado y los criterios de evaluación del plan '
                        . 'formativo.'
                    : 'El tipo de ajuste decidido en la etapa 3 es REDISEÑO PARCIAL: identifica los componentes del diseño que '
                        . 'deben rediseñarse (secuencia, tiempos, estrategia, materiales o evaluación), fundamenta cada cambio en '
                        . 'la evidencia de la ficha y propone su nueva versión, manteniendo los componentes que funcionaron.'),
            'En los rediseños, el diseño resultante se implementa como experiencia de aprendizaje de continuidad (etapa 6), '
                . 'dentro del tiempo restante.',
            'REGLA DE TIEMPO: tiempo restante = tiempo total disponible − tiempo efectivamente utilizado (lo calcula la '
                . 'plataforma). Distribuye el tiempo restante según la criticidad: Alta 50–70 %, Media 20–40 %, Baja 0–20 %. La '
                . 'suma de los tiempos no debe superar el tiempo restante; justifica la distribución. Si el tiempo no alcanza, '
                . 'prioriza la criticidad alta e informa lo que queda sin cubrir. Si el tiempo restante es igual o inferior a '
                . 'cero, infórmalo y propone alternativas de priorización a la persona facilitadora; no asignes tiempo '
                . 'inexistente.',
        ];
        $def['nota'] = '';
        return $def;
    }

    /**
     * La etapa 8: consolidacion de todos los entregables en un documento unico.
     *
     * @return array
     */
    public static function etapa_consolidacion(): array {
        return ['titulo' => 'Consolidación', 'archivo' => 'Informe_de_aseguramiento_de_la_calidad',
            'incluye' => ['Validación de la ficha y tiempo disponible', 'Análisis de la implementación', 'Brechas y pendientes',
                'Decisión del tipo de ajuste', 'Acciones remediales o plan de rediseño', 'Mejora del diseño y buenas prácticas',
                'Experiencia de aprendizaje de continuidad, con sus instrumentos', 'Síntesis'],
            'nota' => 'Todos los entregables anteriores, en extenso, en un documento único listo para descargar en Word. La '
                . 'consolidación no es editable: si quieres ajustar algo, hazlo en la etapa que corresponda.'];
    }

    /**
     * Las ocho etapas del Prompt Maestro.
     *
     * @param string|null $ajuste AJUSTE_* decidido (cambia la etapa 4).
     * @return array
     */
    public static function todas(?string $ajuste = null): array {
        $todas = [];
        foreach (array_keys(self::etapas()) as $n) {
            $todas[$n] = self::stage_def($n, $ajuste);
        }
        $todas[self::CONSOLIDACION] = self::etapa_consolidacion();
        return $todas;
    }

    /**
     * Fase a la que pertenece una etapa: la 2 (etapas 1 a 3) o la 3 (etapas 4 a 8). La Fase 1 es la ficha (Etapa 0).
     *
     * @param int $etapa
     * @return int
     */
    public static function phase_of(int $etapa): int {
        return $etapa <= 3 ? 2 : 3;
    }

    /**
     * Etapas de una fase.
     *
     * @param int $fase 2 o 3.
     * @return int[]
     */
    public static function stages_of_phase(int $fase): array {
        return $fase === 2 ? [1, 2, 3] : [4, 5, 6, 7, 8];
    }

    // ------------------------------------------------------------------------------------------
    // Calculos de las etapas 1 a 3 (aritmetica y reglas que manda el codigo).
    // ------------------------------------------------------------------------------------------

    /**
     * Criticidad de una brecha de aprendizaje segun el logro (Prompt Maestro, etapa 2). Funcion pura.
     *
     * @param float $logro Logro del criterio, en %.
     * @return string|null null sin brecha (logro desde 90 %); baja, media o alta.
     */
    public static function criticality(float $logro): ?string {
        if ($logro >= self::LOGRO_SIN_BRECHA) {
            return null;
        }
        if ($logro >= self::LOGRO_MEDIA) {
            return 'baja';
        }
        return $logro >= self::LOGRO_ALTA ? 'media' : 'alta';
    }

    /**
     * Logro promedio de los criterios trabajados: promedio simple de sus logros. Funcion pura.
     *
     * @param array $perf implementacion::performance().
     * @return float|null null sin criterios.
     */
    public static function average(array $perf): ?float {
        if (!$perf) {
            return null;
        }
        return round(array_sum(array_column($perf, 'logro')) / count($perf), 1);
    }

    /**
     * Cobertura evaluativa = criterios trabajados ÷ criterios del diseño × 100, con tope 100. Funcion pura.
     *
     * @param int $trabajados Criterios registrados en 2.1.
     * @param int $diseno Criterios que declara el diseño para el aprendizaje esperado.
     * @return float
     */
    public static function coverage(int $trabajados, int $diseno): float {
        if ($diseno <= 0) {
            return 100.0;
        }
        return round(min(100.0, 100 * $trabajados / $diseno), 1);
    }

    /**
     * Jerarquia de los tipos de ajuste: rediseño total > parcial > acciones remediales. Funcion pura.
     *
     * @param string $ajuste
     * @return int
     */
    public static function adjustment_rank(string $ajuste): int {
        return [self::AJUSTE_REMEDIALES => 1, self::AJUSTE_PARCIAL => 2, self::AJUSTE_TOTAL => 3][$ajuste] ?? 0;
    }

    /**
     * Nombre legible de un tipo de ajuste.
     *
     * @param string $ajuste
     * @return string
     */
    public static function adjustment_name(string $ajuste): string {
        return [self::AJUSTE_REMEDIALES => 'Acciones remediales', self::AJUSTE_PARCIAL => 'Rediseño parcial',
            self::AJUSTE_TOTAL => 'Rediseño total'][$ajuste] ?? $ajuste;
    }

    /**
     * Analisis de la implementacion con todo lo que se puede calcular, incluida la decision que indican los datos medibles
     * (criterios de decision del Prompt Maestro, etapa 3). Funcion pura.
     *
     * @param array $data Ficha ya limpia (implementacion::clean).
     * @param array $ref implementacion::design_reference() del diseño elegido.
     * @return array tiempos, criterios (filas con criticidad, nivelacion, profundizacion), promedio, brechas, relevantes,
     *         cobertura, trabajados, diseno, sin_ficha (criterios del diseño que 2.1 no recoge), pendientes (int),
     *         v31, v33 (int|null) y decision (minimo, filas, cualitativas).
     */
    public static function analysis(array $data, array $ref): array {
        $perf = implementacion::performance($data);
        $tiempos = implementacion::times($data);
        $filas = [];
        $brechas = 0;
        $relevantes = 0;
        foreach ($perf as $p) {
            $crit = self::criticality($p['logro']);
            if ($crit !== null) {
                $brechas++;
                if ($crit !== 'baja') {
                    $relevantes++;
                }
            }
            $filas[] = $p + ['criticidad' => $crit,
                'nivelacion' => $p['niveles']['ins'] + $p['niveles']['bas'],
                'profundizacion' => $p['niveles']['ade'] + $p['niveles']['ava']];
        }
        $criterios = [];
        foreach (implementacion::criteria_indexes($data) as $i) {
            $criterios[$i] = (string) $data['criterio' . $i];
        }
        $cmp = implementacion::match_criteria($criterios, $ref['criterios'] ?? []);
        $total = count($ref['criterios'] ?? []);
        $trabajados = count($criterios);
        // Si la ficha registra mas criterios que el diseño, el diseño de referencia es el mayor de los dos.
        $disenototal = max($total, $trabajados);
        $cobertura = self::coverage($trabajados, $disenototal);
        $pendientes = count($cmp['sin_ficha']);
        $v31 = ($data['val31'] ?? '') === '' ? null : (int) $data['val31'];
        $v33 = ($data['val33'] ?? '') === '' ? null : (int) $data['val33'];
        $promedio = self::average($perf);
        $decision = self::decision_table($promedio, $relevantes, $cobertura, $pendientes, $tiempos['restante'], $v31, $v33);
        return ['tiempos' => $tiempos, 'criterios' => $filas, 'promedio' => $promedio, 'brechas' => $brechas,
            'relevantes' => $relevantes, 'cobertura' => $cobertura, 'trabajados' => $trabajados, 'diseno' => $disenototal,
            'sin_ficha' => $cmp['sin_ficha'], 'pendientes' => $pendientes, 'v31' => $v31, 'v33' => $v33,
            'decision' => $decision, 'coincidencia' => $cmp['ficha']];
    }

    /**
     * Tabla de decision con lo medible: una fila por dimension (logro, cobertura y pendientes, realizacion de lo diseñado)
     * y el ajuste minimo que exigen los datos. Lo que solo se puede juzgar con el diseño (si los pendientes caben en el
     * tiempo restante, si la estrategia aplicada fue pertinente) va aparte, para el modelo. Funcion pura.
     *
     * @param float|null $promedio Logro promedio de los criterios trabajados.
     * @param int $relevantes Brechas de criticidad media o alta.
     * @param float $cobertura Cobertura evaluativa, en %.
     * @param int $pendientes Criterios del diseño que la ficha no recoge como trabajados.
     * @param int|null $restante Tiempo restante en minutos.
     * @param int|null $v31 Valoracion 3.1.
     * @param int|null $v33 Valoracion 3.3.
     * @return array minimo (AJUSTE_*), filas (dimension, dato, condicion, ajuste) y cualitativas (string[]).
     */
    public static function decision_table(?float $promedio, int $relevantes, float $cobertura, int $pendientes,
            ?int $restante, ?int $v31, ?int $v33): array {
        $filas = [];
        $pct = static function(float $v): string {
            return implementacion::pct($v);
        };
        // 1. Logro promedio y brechas.
        if ($promedio === null) {
            $logro = [self::AJUSTE_PARCIAL, 'sin criterios trabajados', 'no hay logro que medir'];
        } else if ($promedio < self::LOGRO_ALTA) {
            $logro = [self::AJUSTE_TOTAL, 'logro promedio ' . $pct($promedio), 'logro inferior a 30 %'];
        } else if ($promedio < self::LOGRO_MEDIA) {
            $logro = [self::AJUSTE_PARCIAL, 'logro promedio ' . $pct($promedio), 'logro igual o superior a 30 % y menor que 60 %'];
        } else if ($relevantes > 1) {
            $logro = [self::AJUSTE_PARCIAL, 'logro promedio ' . $pct($promedio) . ' con ' . $relevantes . ' brechas relevantes',
                'logro igual o superior a 60 % con más de una brecha relevante (criticidad media o alta)'];
        } else {
            $logro = [self::AJUSTE_REMEDIALES, 'logro promedio ' . $pct($promedio) . ' con ' . $relevantes
                . ' brecha(s) relevante(s)', 'logro igual o superior a 60 % con brechas acotadas (de criticidad baja o una sola '
                . 'media o alta)'];
        }
        $filas[] = ['dimension' => 'Logro promedio de los criterios trabajados', 'dato' => $logro[1], 'condicion' => $logro[2],
            'ajuste' => $logro[0]];

        // 2. Cobertura evaluativa y pendientes.
        if ($cobertura < self::COBERTURA_MIN) {
            $cob = [self::AJUSTE_PARCIAL, 'cobertura evaluativa ' . $pct($cobertura), 'cobertura inferior a 50 %'];
        } else if ($pendientes > 0 && $restante !== null && $restante <= 0) {
            $cob = [self::AJUSTE_PARCIAL, 'cobertura evaluativa ' . $pct($cobertura) . ' y ' . $pendientes . ' pendiente(s) sin '
                . 'tiempo restante', 'pendientes que no caben en el tiempo restante'];
        } else {
            $cob = [self::AJUSTE_REMEDIALES, 'cobertura evaluativa ' . $pct($cobertura) . ' y ' . $pendientes . ' pendiente(s)',
                'cobertura igual o superior a 50 % y pendientes que caben en el tiempo restante'];
        }
        $filas[] = ['dimension' => 'Cobertura evaluativa y pendientes', 'dato' => $cob[1], 'condicion' => $cob[2],
            'ajuste' => $cob[0]];

        // 3. Realizacion de lo diseñado (3.1 y 3.3).
        $dato = '3.1 = ' . ($v31 ?? 'sin dato') . ' y 3.3 = ' . ($v33 ?? 'sin dato');
        if ($v31 !== null && $v31 <= 2) {
            $real = [self::AJUSTE_PARCIAL, 'omisiones o modificaciones que muestran que la secuencia o los tiempos no son viables '
                . '(3.1 valorada en 1 o 2)'];
        } else if ($v31 !== null && $v33 !== null && $v31 >= 3 && $v33 >= 3) {
            $real = [self::AJUSTE_REMEDIALES, 'diseño realizado en lo sustancial (3.1 y 3.3 valoradas en 3 o 4)'];
        } else {
            $real = [self::AJUSTE_PARCIAL, 'el diseño no se realizó en lo sustancial (3.3 valorada en 1 o 2, o sin dato): las '
                . 'acciones remediales exigen 3.1 y 3.3 en 3 o 4'];
        }
        $filas[] = ['dimension' => 'Realización de lo diseñado (preguntas 3.1 y 3.3)', 'dato' => $dato, 'condicion' => $real[1],
            'ajuste' => $real[0]];

        $minimo = self::AJUSTE_REMEDIALES;
        foreach ($filas as $f) {
            if (self::adjustment_rank($f['ajuste']) > self::adjustment_rank($minimo)) {
                $minimo = $f['ajuste'];
            }
        }
        $cualitativas = [];
        if ($pendientes > 0 && $restante !== null && $restante > 0) {
            $cualitativas[] = 'Hay ' . $pendientes . ' pendiente(s) y quedan ' . $restante . ' minutos: estima cuánto tiempo '
                . 'requiere cada pendiente y decide si caben en el tiempo restante; si no caben, corresponde al menos rediseño '
                . 'parcial.';
        }
        if ($v33 !== null && $v33 >= 3) {
            $cualitativas[] = 'La estrategia se aplicó (3.3 en 3 o 4): si la evidencia muestra que no fue pertinente para este '
                . 'grupo y aprendizaje, corresponde rediseño total.';
        } else {
            $cualitativas[] = 'La estrategia no se aplicó en lo sustancial (3.3 en 1 o 2): no puedes afirmar que no es '
                . 'pertinente; distingue si el problema está en la viabilidad del diseño o en las condiciones de la '
                . 'implementación.';
        }
        return ['minimo' => $minimo, 'filas' => $filas, 'cualitativas' => $cualitativas];
    }

    /**
     * Los datos calculados como texto para el modelo (y para mostrar). Funcion pura.
     *
     * @param array $a Resultado de analysis().
     * @return string
     */
    public static function analysis_text(array $a): string {
        $t = $a['tiempos'];
        $l = ['DATOS CALCULADOS POR LA PLATAFORMA (cálculos exactos con los datos de la ficha; úsalos tal cual, no los recalcules):'];
        $l[] = '- Tiempos (1.1): total disponible ' . ($t['total'] ?? '—') . ' min; asignado a la experiencia '
            . ($t['asignado'] ?? '—') . ' min; efectivamente utilizado ' . ($t['utilizado'] ?? '—') . ' min; TIEMPO RESTANTE '
            . '(total − utilizado) = ' . ($t['restante'] ?? '—') . ' min'
            . ($t['estado'] === 'cero' ? ' (no queda tiempo restante)' : ($t['estado'] === 'negativo'
                ? ' (se usó más que el tiempo total disponible: no queda tiempo restante)' : '')) . '.';
        $l[] = '- Desempeño por criterio (logro = (Adecuado + Avanzado) ÷ presentes × 100):';
        foreach ($a['criterios'] as $c) {
            $niv = [];
            foreach (implementacion::NIVELES as $clave => $nombre) {
                $niv[] = $nombre . ' ' . $c['niveles'][$clave];
            }
            $l[] = '    Criterio ' . $c['n'] . ' «' . $c['criterio'] . '»: ' . implode(', ', $niv) . ' (presentes '
                . $c['presentes'] . '); logro ' . implementacion::pct($c['logro']) . '; brecha de aprendizaje: '
                . ($c['criticidad'] === null ? 'no (logro desde 90 %)' : 'sí, criticidad ' . $c['criticidad'])
                . '; requieren nivelación (Insuficiente + Básico): ' . $c['nivelacion']
                . '; pueden profundizar (Adecuado + Avanzado): ' . $c['profundizacion'] . '.';
        }
        $l[] = '- Logro promedio de los criterios trabajados: ' . ($a['promedio'] === null ? '—' : implementacion::pct($a['promedio']))
            . ' (promedio simple de los logros por criterio).';
        $l[] = '- Brechas de aprendizaje: ' . $a['brechas'] . '; relevantes (criticidad media o alta): ' . $a['relevantes'] . '.';
        $l[] = '- Cobertura evaluativa = criterios trabajados ÷ criterios del diseño × 100 = ' . $a['trabajados'] . ' ÷ '
            . $a['diseno'] . ' × 100 = ' . implementacion::pct($a['cobertura']) . '.' . ($a['sin_ficha']
                ? ' Criterios del diseño (posiciones ' . implode(', ', $a['sin_ficha']) . ') que 2.1 no recoge como trabajados: '
                    . 'son pendientes (verifica que 2.2 los explique).' : '');
        $l[] = '- Realización de lo diseñado: 3.1 = ' . ($a['v31'] ?? 'sin dato') . '; 3.3 = ' . ($a['v33'] ?? 'sin dato') . '.';
        $l[] = '- DECISIÓN QUE INDICAN LOS DATOS MEDIBLES (mínimo exigido): ' . self::adjustment_name($a['decision']['minimo'])
            . '. Prevalece la condición más exigente. Tabla de decisión con lo medible:';
        foreach ($a['decision']['filas'] as $f) {
            $l[] = '    ' . $f['dimension'] . ' | ' . $f['dato'] . ' | ' . $f['condicion'] . ' | ' . self::adjustment_name($f['ajuste']);
        }
        foreach ($a['decision']['cualitativas'] as $q) {
            $l[] = '- A juicio tuyo, con el diseño y la ficha: ' . $q;
        }
        return implode("\n", $l);
    }

    /**
     * Lee el tipo de ajuste que el modelo declara al final de la etapa 3 («[[AJUSTE: rediseno_parcial]]») y lo quita del
     * texto. Funcion pura.
     *
     * @param string|null $markdown
     * @return array [texto sin la marca, AJUSTE_* o null si no vino o no se entiende].
     */
    public static function parse_decision(?string $markdown): array {
        $texto = (string) $markdown;
        $ajuste = null;
        if (preg_match('/\[\[\s*AJUSTE\s*:\s*([^\]]+?)\s*\]\]/iu', $texto, $m)) {
            $v = str_replace([' ', '-', 'ñ'], ['_', '_', 'n'], \core_text::strtolower(trim($m[1])));
            if (strpos($v, 'remedial') !== false) {
                $ajuste = self::AJUSTE_REMEDIALES;
            } else if (strpos($v, 'total') !== false) {
                $ajuste = self::AJUSTE_TOTAL;
            } else if (strpos($v, 'parcial') !== false) {
                $ajuste = self::AJUSTE_PARCIAL;
            }
        }
        $texto = trim(preg_replace('/^[ \t]*\[\[\s*AJUSTE\s*:[^\]]*\]\][ \t]*\R?/imu', '', $texto));
        return [$texto, $ajuste];
    }

    /**
     * Aviso cuando el ajuste decidido es menos exigente que lo que indican los datos medibles. Funcion pura.
     *
     * @param string|null $decidido AJUSTE_* decidido por el modelo.
     * @param string $minimo AJUSTE_* minimo que indican los datos.
     * @return string|null null si esta bien; sin_marca (no declaro el ajuste) o menos_exigente.
     */
    public static function decision_warning(?string $decidido, string $minimo): ?string {
        if ($decidido === null) {
            return 'sin_marca';
        }
        return self::adjustment_rank($decidido) < self::adjustment_rank($minimo) ? 'menos_exigente' : null;
    }

    /**
     * Tipo de ajuste vigente: el de la etapa 3 aceptada. Funcion pura.
     *
     * @param \stdClass[] $filas etapa => fila.
     * @return string|null
     */
    public static function decided_adjustment(array $filas): ?string {
        $f = $filas[3] ?? null;
        if (!$f || $f->estado !== self::ACEPTADO) {
            return null;
        }
        $info = self::decision_info($f);
        // Un ajuste menos exigente que lo que indican los datos medibles no vale, aunque la etapa este aceptada.
        return self::decision_valid($info) ? $info['ajuste'] : null;
    }

    /**
     * Si la decision de la etapa 3 sirve para abrir la Fase 3: hay un tipo de ajuste y no es menos exigente que lo que indican
     * los datos medibles. Funcion pura.
     *
     * @param array $info Resultado de decision_info().
     * @return bool
     */
    public static function decision_valid(array $info): bool {
        if ($info['ajuste'] === null) {
            return false;
        }
        return $info['minimo'] === null || self::adjustment_rank($info['ajuste']) >= self::adjustment_rank($info['minimo']);
    }

    /**
     * La decision guardada en la fila de la etapa 3 (aunque la etapa aun no este aceptada). Funcion pura.
     *
     * @param \stdClass|null $fila Fila de la etapa 3.
     * @return array ajuste (AJUSTE_* o null), minimo (AJUSTE_* o null), aviso (sin_marca, menos_exigente o null) y manual
     *         (si la persona la eligio a mano).
     */
    public static function decision_info(?\stdClass $fila): array {
        $informe = ($fila && $fila->informe) ? json_decode((string) $fila->informe, true) : null;
        $informe = is_array($informe) ? $informe : [];
        $validos = [self::AJUSTE_REMEDIALES, self::AJUSTE_PARCIAL, self::AJUSTE_TOTAL];
        return ['ajuste' => in_array($informe['ajuste'] ?? null, $validos, true) ? $informe['ajuste'] : null,
            'minimo' => in_array($informe['minimo'] ?? null, $validos, true) ? $informe['minimo'] : null,
            'aviso' => $informe['aviso'] ?? null, 'manual' => !empty($informe['manual'])];
    }

    /**
     * Tipos de ajuste que la persona puede elegir: el que indican los datos medibles o uno mas exigente (el juicio de la
     * persona agrava, no relaja, la regla de decision del cliente). Funcion pura.
     *
     * @param string $minimo AJUSTE_* minimo.
     * @return string[] AJUSTE_* permitidos, del menos al mas exigente.
     */
    public static function allowed_adjustments(string $minimo): array {
        return array_values(array_filter([self::AJUSTE_REMEDIALES, self::AJUSTE_PARCIAL, self::AJUSTE_TOTAL],
            static function(string $a) use ($minimo): bool {
                return self::adjustment_rank($a) >= self::adjustment_rank($minimo);
            }));
    }

    // ------------------------------------------------------------------------------------------
    // Revision de la etapa 4: tiempos contra el tiempo restante.
    // ------------------------------------------------------------------------------------------

    /**
     * La tabla «Distribución del tiempo restante» de la etapa 4: la tabla con una columna «Minutos» y una de acción o
     * componente. Se suman los minutos de sus filas, sin la fila «Total». Funcion pura.
     *
     * @param string $html Producto de la etapa 4.
     * @return array|null filas (accion, criticidad, minutos), suma, total_declarado (int|null) y por_criticidad; null si no hay
     *         tabla.
     */
    public static function time_table(string $html): ?array {
        if (stripos($html, '<table') === false) {
            return null;
        }
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        foreach ($dom->getElementsByTagName('table') as $tabla) {
            $encabezados = [];
            $cuerpo = [];
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
                if ($conth && !$encabezados) {
                    $encabezados = $celdas;
                } else {
                    $cuerpo[] = $celdas;
                }
            }
            $pm = $pa = $pc = null;
            foreach ($encabezados as $k => $h) {
                $h = diseno::plain_lower($h);
                if ($pm === null && strpos($h, 'minuto') !== false) {
                    $pm = $k;
                } else if ($pa === null && (strpos($h, 'accion') !== false || strpos($h, 'componente') !== false)) {
                    $pa = $k;
                } else if ($pc === null && strpos($h, 'criticidad') !== false) {
                    $pc = $k;
                }
            }
            if ($pm === null || $pa === null) {
                continue;
            }
            $filas = [];
            $suma = 0;
            $total = null;
            $por = ['alta' => 0, 'media' => 0, 'baja' => 0];
            foreach ($cuerpo as $celdas) {
                $minutos = preg_match('/\d+/', (string) ($celdas[$pm] ?? ''), $m) ? (int) $m[0] : 0;
                if (preg_match('/^\W*total\b/u', diseno::plain_lower((string) ($celdas[$pa] ?? '')))) {
                    $total = $minutos;
                    continue;
                }
                $crit = $pc === null ? '' : diseno::plain_lower((string) ($celdas[$pc] ?? ''));
                $clave = strpos($crit, 'alta') !== false ? 'alta' : (strpos($crit, 'media') !== false ? 'media'
                    : (strpos($crit, 'baja') !== false ? 'baja' : ''));
                $filas[] = ['accion' => (string) $celdas[$pa], 'criticidad' => $clave, 'minutos' => $minutos];
                $suma += $minutos;
                if ($clave !== '') {
                    $por[$clave] += $minutos;
                }
            }
            return ['filas' => $filas, 'suma' => $suma, 'total_declarado' => $total, 'por_criticidad' => $por];
        }
        return null;
    }

    /**
     * Revision de los tiempos de la etapa 4 contra el tiempo restante (Prompt Maestro: «la suma de los tiempos no debe superar
     * el tiempo restante»). Funcion pura.
     *
     * @param string $html Producto de la etapa 4.
     * @param int|null $restante Tiempo restante, en minutos.
     * @return array estado (ok, excede, sin_tabla o sin_datos), suma, restante y reparto (criticidad => % de la suma).
     */
    public static function time_check(string $html, ?int $restante): array {
        $tabla = self::time_table($html);
        if ($tabla === null) {
            return ['estado' => 'sin_tabla', 'suma' => 0, 'restante' => $restante, 'reparto' => []];
        }
        $reparto = [];
        if ($tabla['suma'] > 0) {
            foreach ($tabla['por_criticidad'] as $clave => $minutos) {
                $reparto[$clave] = round(100 * $minutos / $tabla['suma']);
            }
        }
        if ($restante === null) {
            return ['estado' => 'sin_datos', 'suma' => $tabla['suma'], 'restante' => null, 'reparto' => $reparto];
        }
        $estado = $tabla['suma'] > max($restante, 0) ? 'excede' : 'ok';
        return ['estado' => $estado, 'suma' => $tabla['suma'], 'restante' => $restante, 'reparto' => $reparto];
    }

    // ------------------------------------------------------------------------------------------
    // Pedido al modelo.
    // ------------------------------------------------------------------------------------------

    /**
     * Instrucciones al modelo para producir (o ajustar) el producto de una etapa (1 a 7).
     *
     * @param int $etapa
     * @param array $c Contexto: ficha (texto), datos (texto de analysis_text()), informe (informe de la Etapa 0),
     *        condicion (de la validacion), diseno (texto del diseño), previas (etapa => texto aceptado), adjuntos
     *        (etiquetas), ajuste (AJUSTE_* decidido), actual (producto actual), ajustes (lo que pide la persona),
     *        conlibreria y conbusqueda (bool).
     * @return string
     */
    public static function build_prompt(int $etapa, array $c): string {
        $e = self::stage_def($etapa, $c['ajuste'] ?? null);
        $l = [];
        $l[] = 'Eres el ' . implementacion::ROL . ': un asistente experto en diseño instruccional con enfoque de competencias, '
            . 'especializado en formación de personas adultas en contextos de capacitación laboral. Apoyas a personas '
            . 'facilitadoras en el aseguramiento de la calidad de los aprendizajes, analizando experiencias de aprendizaje '
            . 'implementadas para identificar hallazgos y brechas, decidir el tipo de ajuste y diseñar la experiencia de '
            . 'aprendizaje de continuidad. Utilizas como insumos obligatorios el diseño instruccional validado de la experiencia '
            . 'de aprendizaje, el plan formativo asociado a ese diseño y la ficha de registro de la implementación, validada en '
            . 'la Etapa 0 mediante el checklist; el perfil ocupacional es opcional. Tu comportamiento es estricto, estructurado, '
            . 'basado en evidencia y orientado a la mejora continua.';
        $l[] = '';
        $l[] = 'TAREA: ETAPA ' . $etapa . ': ' . \core_text::strtoupper($e['titulo']) . '.';
        $l[] = 'Desarrolla solo esta etapa; no adelantes las siguientes. Debe incluir obligatoriamente:';
        foreach ($e['incluye'] as $item) {
            $l[] = '- ' . $item;
        }
        foreach ($e['indicaciones'] ?? [] as $indicacion) {
            $l[] = $indicacion;
        }
        if (($e['nota'] ?? '') !== '') {
            $l[] = $e['nota'];
        }
        if ($etapa === 3) {
            $l[] = 'Después de la sección «Referencias consultadas», agrega como última línea del producto, sola y exacta, la '
                . 'marca con el tipo de ajuste que decidiste: [[AJUSTE: acciones_remediales]], [[AJUSTE: rediseno_parcial]] o '
                . '[[AJUSTE: rediseno_total]]. La plataforma la usa para habilitar la Fase 3 y la quita del texto.';
        }
        $l[] = '';
        $l[] = 'PRINCIPIOS DE ANÁLISIS:';
        $l[] = '- La unidad de análisis es siempre la experiencia de aprendizaje: la diseñada, la implementada y la de '
            . 'continuidad. Una experiencia de aprendizaje puede desarrollarse en una o más sesiones; no uses la sesión como '
            . 'unidad de análisis ni de diseño.';
        $l[] = '- El diseño de cada experiencia de aprendizaje incluye todos los criterios de evaluación comprometidos en el '
            . 'plan formativo para el aprendizaje esperado, con un mínimo de 3. Si en la implementación se trabajan menos, la '
            . 'diferencia es un pendiente originado en la implementación o en el propio diseño.';
        $l[] = '- Los cambios en la implementación (criterios no observados y actividades, estrategias o instrumentos omitidos, '
            . 'modificados, sustituidos o incorporados) son decisiones de la persona facilitadora. Se analizan como hallazgos '
            . 'para fundamentar la mejora o el rediseño y nunca son, por sí mismos, motivo de rechazo de la ficha ni de detención '
            . 'del proceso.';
        $l[] = '- Todo criterio trabajado con logro inferior a 90 % presenta una brecha de aprendizaje: un logro igual o superior '
            . 'a 60 % no implica ausencia de brechas. Se consideran brechas relevantes las de criticidad media o alta (logro '
            . 'inferior a 60 %).';
        $l[] = '- Los criterios no trabajados o no evaluados no cuentan como brecha ni como logro: son pendientes, y siempre se '
            . 'incorporan a la experiencia de aprendizaje de continuidad.';
        $l[] = '- La no pertinencia de una estrategia solo puede afirmarse si la estrategia efectivamente se aplicó. Si se '
            . 'sustituyó, analiza si la sustitución se debió a que el diseño no era viable o a una contingencia de la '
            . 'implementación.';
        $l[] = '';
        $l[] = 'REGLAS DE GOBERNANZA:';
        $l[] = '- Usa como fuente principal la ficha de registro, el diseño instruccional validado y el plan formativo. '
            . implementacion::documents_rule($c['adjuntos'] ?? []);
        $l[] = '- No inventes aprendizajes esperados ni criterios de evaluación: transcríbelos tal como están en la ficha y en '
            . 'el diseño.';
        $l[] = '- Fundamenta todo juicio en evidencia concreta registrada en la ficha (datos, porcentajes, evidencias y '
            . 'comentarios); no emitas juicios sin fundamento ni atribuyas intenciones a la persona facilitadora.';
        $l[] = '- Mantén la alineación constructiva con el aprendizaje esperado, los criterios de evaluación y las etapas ya '
            . 'aceptadas.';
        $l[] = '- Propón soluciones viables dentro del tiempo restante y de los recursos disponibles, sin alterar el aprendizaje '
            . 'esperado ni los criterios de evaluación del plan formativo.';
        $l[] = implementacion::NO_PEDAGOGIA;
        $l[] = '- Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género.';
        $l[] = '- No repitas nombres de personas participantes: refiérete a ellas por su subgrupo o como «personas '
            . 'participantes».';
        $l[] = '- Escribe todo el producto en español de Chile, también las tablas, los vacíos y la verificación: nunca en '
            . 'inglés ni en otro idioma.';
        $l[] = '- Detecta vacíos de información y repórtalos.';
        $l[] = '';
        foreach (implementacion::knowledge_rules(!empty($c['conlibreria']), !empty($c['conbusqueda'])) as $regla) {
            $l[] = $regla;
        }
        $l[] = '- No uses citas numéricas ni marcas entre corchetes dentro del texto (como [1] o [2]). Al final del producto, '
            . 'después de la «Verificación de calidad de la etapa», agrega la sección «### Referencias consultadas» con una '
            . 'lista de viñetas de los documentos que usaste (de la librería de conocimientos, el Plan Formativo o el Perfil '
            . 'Ocupacional), por su nombre. Si no usaste ninguno aparte de la ficha y el diseño, escribe «Sin referencias '
            . 'adicionales a la ficha». Las fuentes web las agrega la plataforma.';
        $l[] = '- Diagramas: nunca dibujes cajas, líneas o flechas con caracteres (│ ─ ┌ ▼ →) ni texto alineado con espacios. Si '
            . 'un diagrama ayuda, entrégalo en un bloque de código con la etiqueta «diagrama»: una línea por caja, con «Título / '
            . 'detalle»; puedes agregar «titulo: ...» y «modo: ciclo» si los pasos se repiten en círculo. Entre 2 y 12 cajas.';
        $l[] = diseno::REGLA_FORMULAS;
        $l[] = '';
        $l[] = 'CONTROL DE CALIDAD: antes de cerrar la etapa verifica la alineación con el aprendizaje esperado y los criterios '
            . 'de evaluación, que cada juicio esté fundamentado en la evidencia de la ficha, la coherencia entre brechas, '
            . 'decisión y acciones, la consistencia con el tiempo restante y la aplicabilidad real. Termina el documento con una '
            . 'sección breve «Verificación de calidad de la etapa» que diga qué verificaste y, si detectas una inconsistencia, '
            . 'qué ajuste se necesita.';
        $l[] = '';
        $l[] = 'FORMATO: entrega el producto en Markdown, estructurado, institucional y aplicable, listo para copiar en un '
            . 'documento Word: títulos con ## y ###, listas y tablas Markdown cuando ayuden. Empieza directamente con el título '
            . '«## Etapa ' . $etapa . ': ' . $e['titulo'] . '». No agregues saludos, preguntas al final ni comentarios fuera '
            . 'del producto: la plataforma ya le pregunta a la persona si quiere ajustes.';
        $l[] = 'ESTRUCTURA OBLIGATORIA (la plataforma revisa que esté todo): cada elemento de la lista «Debe incluir '
            . 'obligatoriamente» aparece con su nombre, como sección «### N. Nombre» cuando es un apartado del producto, o como '
            . 'columna de tabla o rótulo en negrita cuando es un dato de cada fila o acción. No omitas ninguno. El producto '
            . 'termina siempre con estas tres secciones, en este orden: «### ' . diseno::SECCION_VACIOS . '» (si no hay vacíos, '
            . 'dilo en una línea), «### ' . diseno::SECCION_VERIFICACION . '» y «### ' . diseno::SECCION_REFERENCIAS . '».';
        $l[] = '';
        $l[] = guardrails::injection_clause();
        $l[] = '';
        $l[] = 'FICHA DE REGISTRO DE LA IMPLEMENTACIÓN (VALIDADA EN LA ETAPA 0):';
        $l[] = guardrails::isolate_user_content((string) ($c['ficha'] ?? ''));
        if (!empty($c['condicion'])) {
            $l[] = '';
            $l[] = implementacion::report_summary((string) $c['condicion'], (array) ($c['informe'] ?? []));
        }
        $l[] = '';
        $l[] = (string) ($c['datos'] ?? '');
        $l[] = '';
        $l[] = (string) ($c['diseno'] ?? '');
        if ($etapa >= 4 && !empty($c['ajuste'])) {
            $l[] = '';
            $l[] = 'TIPO DE AJUSTE DECIDIDO EN LA ETAPA 3 (aceptado por la persona): ' . \core_text::strtoupper(
                self::adjustment_name($c['ajuste'])) . '.';
        }
        foreach (($c['previas'] ?? []) as $n => $texto) {
            $l[] = '';
            $l[] = 'PRODUCTO ACEPTADO DE LA ETAPA ' . $n . ' (' . (self::etapas()[$n]['titulo'] ?? '') . '):';
            $l[] = guardrails::isolate_user_content(\core_text::substr($texto, 0, self::PREVIA_MAX));
        }
        if (trim((string) ($c['ajustes'] ?? '')) !== '') {
            $l[] = '';
            $l[] = 'PRODUCTO ACTUAL DE ESTA ETAPA (versión que la persona quiere ajustar):';
            $l[] = guardrails::isolate_user_content(\core_text::substr((string) ($c['actual'] ?? ''), 0, self::PREVIA_MAX));
            $l[] = '';
            $l[] = 'AJUSTES QUE PIDE LA PERSONA: aplícalos y entrega el producto completo de la etapa, no solo los cambios. Si un '
                . 'ajuste contradice la ficha, el plan formativo, los datos calculados o las reglas, no lo apliques y explícalo en '
                . 'la verificación de calidad.';
            $l[] = guardrails::isolate_user_content((string) $c['ajustes']);
        }
        return implode("\n", $l);
    }

    /**
     * Instrucciones de la etapa 8 con el asistente: consolidar todos los entregables «en extenso» en un documento unico,
     * sin repetir los campos comunes. Funcion pura.
     *
     * @param string[] $partes Numero de parte (0 a 7) => producto (HTML).
     * @return string
     */
    public static function build_consolidation_prompt(array $partes): string {
        $titulos = [0 => 'Validación de la ficha y tiempo disponible'] + array_map(static function(array $e): string {
            return $e['titulo'];
        }, self::etapas());
        $l = [];
        $l[] = 'Eres el ' . implementacion::ROL . ': un asistente experto en diseño instruccional con enfoque de competencias, '
            . 'especializado en formación de personas adultas en contextos de capacitación laboral.';
        $l[] = '';
        $l[] = 'TAREA: ETAPA 8: CONSOLIDACIÓN (OBLIGATORIA). Consolida todos los entregables anteriores "en extenso" en un '
            . 'documento único listo para descargar en Word, sin repetir aquellos campos que puedan ser comunes. El documento '
            . 'tiene estas partes, en este orden: Etapa 0 (validación de la ficha y tiempo disponible), análisis de la '
            . 'implementación, brechas y pendientes, decisión del tipo de ajuste, acciones remediales o plan de rediseño, mejora '
            . 'del diseño y buenas prácticas, experiencia de aprendizaje de continuidad con sus instrumentos, y síntesis. Titula '
            . 'cada parte «Etapa N: Nombre» (de la 0 a la 7).';
        $l[] = '';
        $l[] = 'REGLAS:';
        $l[] = '- "En extenso": conserva todo el contenido de cada etapa: tablas, acciones, actividades, tiempos, recursos, '
            . 'materiales e instrumentos completos, con todos sus niveles, ítems y criterios. No resumas ni acortes.';
        $l[] = '- Sin repetir campos comunes: los datos que se repiten entre etapas (nombre de la experiencia, aprendizaje '
            . 'esperado, criterios de evaluación, tiempos, participantes) van una sola vez, en una sección inicial de datos '
            . 'generales; en las partes siguientes no los vuelvas a transcribir.';
        $l[] = '- No agregues contenido nuevo ni cambies decisiones: solo reorganizas lo que la persona aceptó.';
        $l[] = '- Cada parte conserva todas las secciones de su etapa, con sus nombres, incluidas «' . diseno::SECCION_VACIOS
            . '» y «' . diseno::SECCION_VERIFICACION . '» de esa etapa, al final de la parte.';
        $l[] = '- No uses citas numéricas ni marcas entre corchetes dentro del texto (como [1] o [2]). Reúne las referencias de '
            . 'las etapas (incluidas las fuentes web) en una sola sección «Referencias consultadas» al final del documento, sin '
            . 'repetirlas.';
        $l[] = '- Diagramas: conserva las tablas de cajas con flechas de las etapas como bloques de código con la etiqueta '
            . '«diagrama» (una línea por caja, «Título / detalle»), sin dibujarlas nunca con caracteres.';
        $l[] = diseno::REGLA_FORMULAS . ' Si una etapa trae una fórmula en LaTeX, pásala a texto.';
        $l[] = implementacion::NO_PEDAGOGIA;
        $l[] = '- Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género.';
        $l[] = '- Escribe todo el producto en español de Chile: nunca en inglés ni en otro idioma.';
        $l[] = '';
        $l[] = guardrails::injection_clause();
        $l[] = '';
        $l[] = 'FORMATO DE RESPUESTA: el documento completo en Markdown (títulos con #, listas y tablas Markdown), sin texto antes '
            . 'ni después del documento.';
        ksort($partes);
        foreach ($partes as $n => $html) {
            $l[] = '';
            $l[] = 'PRODUCTO ACEPTADO DE LA ETAPA ' . $n . ' (' . ($titulos[$n] ?? '') . '):';
            $l[] = guardrails::isolate_user_content((string) $html);
        }
        return implode("\n", $l);
    }

    /**
     * El informe de la validacion de la ficha (Etapa 0) como HTML, con las seis salidas obligatorias del Prompt Maestro:
     * resultados por seccion, decision con la regla aplicada, tiempo disponible, debilidades, sugerencias y registro de
     * observaciones no resueltas. Es la parte «Etapa 0» del documento consolidado. Funcion pura.
     *
     * @param string $condicion aprobado, observaciones o no_aprobado.
     * @param array $informe Informe de la validacion.
     * @return string
     */
    public static function validation_html(string $condicion, array $informe): string {
        $e = static function($t) {
            return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        };
        $estados = [implementacion::CUMPLE => '✔ Cumple', implementacion::PARCIAL => '⚠ Cumple parcialmente',
            implementacion::NO_CUMPLE => '❌ No cumple'];
        $condiciones = [implementacion::APROBADO => '✔ Aprobado', implementacion::OBSERVACIONES => '⚠ Aprobado con observaciones',
            implementacion::NO_APROBADO => '❌ No aprobado'];
        $reglas = ['critico_no' => 'Hay al menos un ítem CRÍTICO en ❌: corresponde No aprobado.',
            'critico_parcial' => 'Hay al menos un ítem CRÍTICO en ⚠ que no es una diferencia de forma: se trata como No aprobado '
                . 'hasta que la ficha se corrija.',
            'forma' => 'Los ítems críticos solo tienen diferencias de forma: Aprobado con observaciones.',
            'no_criticos' => 'Los ítems críticos cumplen; hay ⚠ o ❌ en ítems no críticos: Aprobado con observaciones.',
            'todo_cumple' => 'Todos los ítems cumplen: Aprobado.'];
        $h = '<h2>Etapa 0: Validación de la ficha y tiempo disponible</h2>';
        $h .= '<h3>1. Resultados por sección</h3>';
        foreach (implementacion::checklist() as $n => $seccion) {
            $h .= '<h4>Sección ' . $n . '. ' . $e($seccion['titulo']) . '</h4><table><thead><tr><th>Ítem</th><th>Evaluación</th>'
                . '<th>Observación</th></tr></thead><tbody>';
            foreach ($seccion['items'] as $id => $texto) {
                $item = $informe['items'][$id] ?? null;
                $obs = $item ? trim((string) ($item['observacion'] ?? '')) : '';
                if ($item && !empty($item['propuesta'])) {
                    $obs .= ($obs !== '' ? ' ' : '') . 'Cómo corregirlo: ' . $item['propuesta'];
                }
                $h .= '<tr><td>' . $e($texto) . (in_array($id, implementacion::CRITICOS, true) ? ' <strong>(CRÍTICO)</strong>' : '')
                    . '</td><td>' . $e($item ? ($estados[$item['estado']] ?? $item['estado']) : 'Sin evaluar') . '</td><td>'
                    . $e($obs) . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }
        $h .= '<h3>2. Decisión</h3><p><strong>' . $e($condiciones[$condicion] ?? $condicion) . '</strong></p>';
        if (!empty($informe['regla']) && isset($reglas[$informe['regla']])) {
            $h .= '<p>' . $e($reglas[$informe['regla']]) . '</p>';
        }
        $t = (array) ($informe['tiempo'] ?? []);
        $h .= '<h3>3. Tiempo disponible</h3><table><thead><tr><th>Tiempo total disponible (1.1)</th>'
            . '<th>Tiempo efectivamente utilizado (1.1)</th><th>Tiempo restante (total − utilizado)</th></tr></thead><tbody><tr>'
            . '<td>' . $e($t['total'] ?? '—') . ' minutos</td><td>' . $e($t['utilizado'] ?? '—') . ' minutos</td><td>'
            . $e($t['restante'] ?? '—') . ' minutos</td></tr></tbody></table>';
        foreach (['debilidades' => '4. Principales debilidades', 'sugerencias' => '5. Sugerencias de mejora'] as $clave => $titulo) {
            $h .= '<h3>' . $titulo . '</h3>';
            $lista = (array) ($informe[$clave] ?? []);
            $h .= $lista ? '<ul><li>' . implode('</li><li>', array_map($e, $lista)) . '</li></ul>'
                : '<p>No se registran.</p>';
        }
        $h .= '<h3>6. Registro de observaciones no resueltas</h3>';
        $pend = implementacion::unresolved_items((array) ($informe['items'] ?? []));
        if ($pend) {
            $h .= '<ul>';
            foreach ($pend as $id => $p) {
                $h .= '<li>Sección ' . $p['seccion'] . ': ' . $e($p['texto']) . ' — ' . ($p['estado'] === implementacion::NO_CUMPLE
                    ? 'no cumple' : 'cumple parcialmente') . ($p['observacion'] !== '' ? '. ' . $e($p['observacion']) : '') . '</li>';
            }
            $h .= '</ul>';
        } else {
            $h .= '<p>No quedaron observaciones sin resolver.</p>';
        }
        return $h;
    }

    // ------------------------------------------------------------------------------------------
    // Revision del producto y documentos.
    // ------------------------------------------------------------------------------------------

    /**
     * Revision automatica de una etapa: lo que pide la etapa y no aparece en el producto (como titulo, columna de tabla o
     * rotulo en negrita), mas el cierre comun (vacios, verificacion y referencias). Para la consolidacion (etapa 8): las
     * partes 0 a 7. Funcion pura.
     *
     * @param int $etapa 1 a 8.
     * @param string $html Producto (ya normalizado).
     * @param string|null $ajuste AJUSTE_* decidido (la etapa 4 pide cosas distintas segun el ajuste).
     * @return string[] Nombres de lo que falta, para mostrarlos y pedirlos.
     */
    public static function missing_items(int $etapa, string $html, ?string $ajuste = null): array {
        if (trim(strip_tags($html)) === '') {
            return [];
        }
        if ($etapa === self::CONSOLIDACION) {
            return array_merge(self::missing_parts($html), array_map(static function(string $frase): string {
                return 'Texto en español (hay un párrafo en inglés: «' . $frase . '…»)';
            }, diseno::english_paragraphs($html)));
        }
        $e = self::stage_def($etapa, $ajuste);
        if (!$e) {
            return [];
        }
        $estructura = diseno::structure_text($html);
        $faltan = [];
        foreach ($e['comprobar'] ?? $e['incluye'] as $item) {
            if (!diseno::has_item($item, $estructura)) {
                $faltan[] = trim(preg_split('/[,;:]/u', $item)[0]);
            }
        }
        foreach (diseno::english_paragraphs($html) as $frase) {
            $faltan[] = 'Texto en español (hay un párrafo en inglés: «' . $frase . '…»)';
        }
        foreach ([diseno::SECCION_VACIOS => '/vacios? (?:de informacion|y soluciones)|registro de vacios/',
                diseno::SECCION_VERIFICACION => '/verificacion de (?:la )?calidad/',
                diseno::SECCION_REFERENCIAS => '/referencias/'] as $seccion => $clave) {
            if (!preg_match($clave, $estructura)) {
                $faltan[] = $seccion;
            }
        }
        return $faltan;
    }

    /**
     * Partes que faltan en la consolidacion: cada una de las etapas 0 a 7 (titulo «Etapa N» o «Parte N»). Funcion pura.
     *
     * @param string $html
     * @return string[]
     */
    private static function missing_parts(string $html): array {
        preg_match_all('#<h[1-3]\b[^>]*>\s*(?:<[^>]+>\s*)*(?:parte|etapa)\s+(\d+)\b#iu', $html, $m);
        $hay = array_map('intval', $m[1]);
        $titulos = [0 => 'Validación de la ficha y tiempo disponible'] + array_map(static function(array $e): string {
            return $e['titulo'];
        }, self::etapas());
        $faltan = [];
        foreach ($titulos as $n => $titulo) {
            if (!in_array($n, $hay, true)) {
                $faltan[] = 'Etapa ' . $n . ': ' . $titulo;
            }
        }
        return $faltan;
    }

    /**
     * Huella de lo que se consolida: el contenido de las etapas 1 a 7 aceptadas. Si cambia, hay que consolidar de nuevo.
     *
     * @param \stdClass[] $filas etapa => fila.
     * @param string $validacion Huella de la validacion de la ficha (la Etapa 0 tambien se consolida).
     * @return string
     */
    public static function stages_fingerprint(array $filas, string $validacion = ''): string {
        $partes = [];
        foreach (array_keys(self::etapas()) as $n) {
            $fila = $filas[$n] ?? null;
            $partes[$n] = ($fila && $fila->estado === self::ACEPTADO) ? (string) $fila->contenido : '';
        }
        return sha1(json_encode([$partes, $validacion], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Documento consolidado por codigo: la Etapa 0 y las etapas 1 a 7 aceptadas, tal cual, en orden («en extenso»).
     *
     * @param string $etapa0 HTML de la Etapa 0 (validation_html()).
     * @param string[] $contenidos etapa => HTML del producto aceptado.
     * @return string HTML.
     */
    public static function consolidate(string $etapa0, array $contenidos): string {
        ksort($contenidos);
        $html = trim($etapa0) . "\n<hr>";
        foreach ($contenidos as $n => $contenido) {
            if (isset(self::etapas()[$n])) {
                $html .= "\n" . trim((string) $contenido) . "\n<hr>";
            }
        }
        return trim($html);
    }

    /**
     * Datos de la portada del documento final (documento_final::build()). Funcion pura.
     *
     * @param array $ficha Respuestas de la ficha (plan, modulo, experiencia, aprendizaje, fechas).
     * @param string|null $ajuste AJUSTE_* decidido.
     * @param string $fecha Fecha de la consolidacion, ya con formato.
     * @return array
     */
    public static function final_cover(array $ficha, ?string $ajuste, string $fecha): array {
        $limpio = static function($v): string {
            return trim(rtrim(trim((string) $v), '.'));
        };
        $lineas = [];
        foreach ([['Plan formativo', $limpio($ficha['plan'] ?? '')], ['Módulo', $limpio($ficha['modulo'] ?? '')],
                ['Aprendizaje esperado', \core_text::substr($limpio($ficha['aprendizaje'] ?? ''), 0, 300)],
                ['Fecha(s) de implementación', $limpio($ficha['fechas'] ?? '')]] as $l) {
            if ($l[1] !== '') {
                $lineas[] = $l;
            }
        }
        if ($ajuste !== null) {
            $lineas[] = ['Tipo de ajuste decidido', self::adjustment_name($ajuste)];
        }
        if ($fecha !== '') {
            $lineas[] = ['Fecha del informe', $fecha];
        }
        $titulo = $limpio($ficha['experiencia'] ?? '');
        return ['etiqueta' => 'Aseguramiento de la calidad de los aprendizajes',
            'titulo' => $titulo !== '' ? $titulo : 'Experiencia de aprendizaje implementada',
            'subtitulo' => 'Informe de evaluación de la implementación y ajuste de la experiencia de aprendizaje',
            'lineas' => $lineas,
            'encabezado' => 'Aseguramiento de la calidad de los aprendizajes · Informe consolidado'];
    }

    /**
     * Si la persona puede abrir una etapa: necesita la Fase 1 aprobada y todas las anteriores aceptadas, y desde la 4 el
     * tipo de ajuste decidido. Funcion pura.
     *
     * @param int $etapa
     * @param bool $fase1 Ficha validada con Aprobado o Aprobado con observaciones, vigente.
     * @param int[] $aceptadas Numeros de etapa aceptados.
     * @param string|null $ajuste AJUSTE_* decidido (null: aun no hay).
     * @return bool
     */
    public static function can_open(int $etapa, bool $fase1, array $aceptadas, ?string $ajuste = null): bool {
        if (!$fase1 || $etapa < 1 || $etapa > self::CONSOLIDACION) {
            return false;
        }
        for ($i = 1; $i < $etapa; $i++) {
            if (!in_array($i, $aceptadas, true)) {
                return false;
            }
        }
        return $etapa < 4 || $ajuste !== null;
    }

    /**
     * Etapa en la que corresponde trabajar: la primera no aceptada (o la ultima si todas lo estan). Funcion pura.
     *
     * @param int[] $aceptadas
     * @return int
     */
    public static function current_stage(array $aceptadas): int {
        for ($n = 1; $n <= self::CONSOLIDACION; $n++) {
            if (!in_array($n, $aceptadas, true)) {
                return $n;
            }
        }
        return self::CONSOLIDACION;
    }

    /**
     * Etapa que abre la pagina de una fase cuando no se pide una: la primera sin aceptar de esa fase, o la primera de la fase
     * si estan todas aceptadas (para recorrerlas). Funcion pura.
     *
     * @param int $fase 2 o 3.
     * @param int[] $aceptadas
     * @return int
     */
    public static function entry_stage(int $fase, array $aceptadas): int {
        foreach (self::stages_of_phase($fase) as $n) {
            if (!in_array($n, $aceptadas, true)) {
                return $n;
            }
        }
        return self::stages_of_phase($fase)[0];
    }

    /**
     * Segundos que puede tardar la generacion de una etapa antes de darla por caida. Funcion pura.
     *
     * @param int $etapa
     * @return int
     */
    public static function max_seconds(int $etapa): int {
        return $etapa === self::CONSOLIDACION ? self::CONSOLIDACION_MAX : implementacion::PROCESO_MAX;
    }

    /**
     * Numero de una version del prompt («M3-F2-etapas-v1» → 1). Funcion pura.
     *
     * @param string|null $version
     * @return int
     */
    public static function prompt_number(?string $version): int {
        return diseno::prompt_number($version);
    }

    // ------------------------------------------------------------------------------------------
    // Base de datos.
    // ------------------------------------------------------------------------------------------

    /**
     * Productos de la evaluacion en uso de la persona en el curso.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass[] etapa => fila.
     */
    public static function get_all(int $userid, int $courseid): array {
        $ficha = implementacion::get_ficha($userid, $courseid);
        return $ficha ? self::get_by_impl((int) $ficha->id) : [];
    }

    /**
     * Productos de una evaluacion (cada ficha tiene sus propias etapas).
     *
     * @param int $implid
     * @return \stdClass[] etapa => fila.
     */
    public static function get_by_impl(int $implid): array {
        global $DB;
        $salida = [];
        foreach ($DB->get_records('local_chatbot_calidad', ['implid' => $implid]) as $fila) {
            if ($fila->generacion === implementacion::PROCESANDO
                    && $fila->timemodified < time() - self::max_seconds((int) $fila->etapa)) {
                $fila->generacion = implementacion::ERROR;
                $fila->error = 'tiempo';
                $DB->update_record('local_chatbot_calidad', (object) ['id' => $fila->id, 'generacion' => $fila->generacion,
                    'error' => $fila->error, 'timemodified' => time()]);
            }
            $salida[(int) $fila->etapa] = $fila;
        }
        return $salida;
    }

    /**
     * Etapas aceptadas.
     *
     * @param \stdClass[] $filas
     * @param string $validacion Huella de la validacion vigente (la consolidacion cuenta solo si sigue al dia).
     * @return int[]
     */
    public static function accepted(array $filas, string $validacion = ''): array {
        $salida = [];
        $huella = self::stages_fingerprint($filas, $validacion);
        foreach ($filas as $n => $fila) {
            if ($fila->estado !== self::ACEPTADO) {
                continue;
            }
            // La consolidacion cuenta solo si las etapas no cambiaron despues de confirmarla.
            if ((int) $n === self::CONSOLIDACION && (string) $fila->huella !== $huella) {
                continue;
            }
            $salida[] = (int) $n;
        }
        sort($salida);
        return $salida;
    }

    /**
     * Fila de una etapa de la evaluacion en uso (la crea vacia si no existe).
     *
     * @param int $userid
     * @param int $courseid
     * @param int $etapa
     * @return \stdClass
     */
    private static function row(int $userid, int $courseid, int $etapa): \stdClass {
        global $DB;
        $ficha = implementacion::get_ficha($userid, $courseid);
        if (!$ficha) {
            throw new \moodle_exception('invalidrecord', 'error', '', 'local_chatbot_impl');
        }
        $fila = $DB->get_record('local_chatbot_calidad', ['implid' => $ficha->id, 'etapa' => $etapa]);
        if ($fila) {
            return $fila;
        }
        $now = time();
        $fila = (object) ['implid' => (int) $ficha->id, 'userid' => $userid, 'courseid' => $courseid, 'etapa' => $etapa,
            'contenido' => null, 'estado' => self::BORRADOR, 'generacion' => '', 'timecreated' => $now, 'timemodified' => $now];
        $fila->id = $DB->insert_record('local_chatbot_calidad', $fila);
        return $fila;
    }

    /**
     * Pide al asistente la propuesta de la etapa (o sus ajustes). Queda pendiente; la pagina la ejecuta.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $etapa
     * @param int $validacionid Validacion de la Fase 1 en que se basa.
     * @param string $ajustes
     * @return int id de la fila.
     */
    public static function request(int $userid, int $courseid, int $etapa, int $validacionid, string $ajustes = ''): int {
        global $DB;
        $fila = self::row($userid, $courseid, $etapa);
        if (in_array($fila->generacion, [implementacion::PENDIENTE, implementacion::PROCESANDO], true)) {
            return (int) $fila->id;
        }
        $DB->update_record('local_chatbot_calidad', (object) ['id' => $fila->id, 'generacion' => implementacion::PENDIENTE,
            'ajustes' => \core_text::substr(trim($ajustes), 0, 4000), 'validacionid' => $validacionid, 'error' => null,
            'rondas' => (int) ($fila->rondas ?? 0) + (trim($ajustes) !== '' ? 1 : 0), 'timemodified' => time()]);
        return (int) $fila->id;
    }

    /**
     * Ejecuta una generacion pendiente. Corre una sola vez aunque la pagina la pida dos veces.
     *
     * @param int $id
     * @param int $userid
     * @return string Estado de la generacion.
     */
    public static function run(int $id, int $userid): string {
        global $DB;
        $candado = \core\lock\lock_config::get_lock_factory('local_chatbot_calidad')->get_lock('gen' . $id, 0);
        if (!$candado) {
            return implementacion::PROCESANDO;
        }
        try {
            $fila = $DB->get_record('local_chatbot_calidad', ['id' => $id, 'userid' => $userid]);
            if (!$fila || $fila->generacion !== implementacion::PENDIENTE) {
                return $fila ? (string) $fila->generacion : implementacion::ERROR;
            }
            $DB->update_record('local_chatbot_calidad', (object) ['id' => $id, 'generacion' => implementacion::PROCESANDO,
                'timemodified' => time()]);
            [$estado, $campos] = self::generate($fila);
            $DB->update_record('local_chatbot_calidad', (object) (['id' => $id, 'generacion' => $estado,
                'timemodified' => time()] + $campos));
            return $estado;
        } finally {
            $candado->release();
        }
    }

    /**
     * Guarda lo que la persona edito; con $aceptar, ademas la etapa queda aceptada.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $etapa
     * @param string $html
     * @param bool $aceptar
     * @return void
     */
    public static function save(int $userid, int $courseid, int $etapa, string $html, bool $aceptar): void {
        global $DB;
        $fila = self::row($userid, $courseid, $etapa);
        $campos = ['id' => $fila->id, 'contenido' => clean_text($html, FORMAT_HTML), 'timemodified' => time()];
        if ($aceptar) {
            $campos['estado'] = self::ACEPTADO;
            $campos['timeaccepted'] = time();
        }
        $DB->update_record('local_chatbot_calidad', (object) $campos);
    }

    /**
     * Fija a mano el tipo de ajuste de la etapa 3 (si el asistente no lo declaro o la persona quiere agravarlo). No puede ser
     * menos exigente que lo que indican los datos medibles.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $ajuste AJUSTE_*.
     * @return bool false si no es un tipo valido, no hay evaluacion o es menos exigente que el minimo.
     */
    public static function set_adjustment(int $userid, int $courseid, string $ajuste): bool {
        global $DB;
        if (!in_array($ajuste, [self::AJUSTE_REMEDIALES, self::AJUSTE_PARCIAL, self::AJUSTE_TOTAL], true)) {
            return false;
        }
        $ficha = implementacion::get_ficha($userid, $courseid);
        $diseno = ($ficha && (int) $ficha->disenoid > 0) ? implementacion::get_design((int) $ficha->disenoid, $userid) : null;
        if (!$ficha || !$diseno) {
            return false;
        }
        $minimo = self::analysis($ficha->data, $diseno['referencia'])['decision']['minimo'];
        if (!in_array($ajuste, self::allowed_adjustments($minimo), true)) {
            return false;
        }
        $fila = self::row($userid, $courseid, 3);
        $DB->update_record('local_chatbot_calidad', (object) ['id' => $fila->id, 'timemodified' => time(),
            'informe' => json_encode(['ajuste' => $ajuste, 'minimo' => $minimo, 'aviso' => null, 'manual' => true],
                JSON_UNESCAPED_UNICODE)]);
        return true;
    }

    /**
     * Confirma la consolidacion (etapa 8): guarda el documento y la huella de las etapas que lo forman.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $html
     * @param string $huella
     * @param string|null $promptversion Solo si lo confirmado es la consolidacion del asistente.
     * @return void
     */
    public static function confirm_consolidation(int $userid, int $courseid, string $html, string $huella,
            ?string $promptversion = null): void {
        global $DB;
        $fila = self::row($userid, $courseid, self::CONSOLIDACION);
        $DB->update_record('local_chatbot_calidad', (object) ['id' => $fila->id, 'contenido' => $html, 'huella' => $huella,
            'estado' => self::ACEPTADO, 'promptversion' => $promptversion, 'timeaccepted' => time(), 'timemodified' => time()]);
    }

    /**
     * La consolidacion que se muestra y se confirma: la guardada (del asistente o ya confirmada) si corresponde a las etapas
     * de hoy; si no, la completa por codigo. Funcion pura.
     *
     * @param \stdClass|null $fila Fila de la etapa 8.
     * @param string $etapa0 HTML de la Etapa 0.
     * @param string[] $contenidos Productos de las etapas 1 a 7 (HTML).
     * @param string $huella Huella de las etapas de hoy (stages_fingerprint()).
     * @return array [html, si es del asistente (bool)].
     */
    public static function consolidation_shown(?\stdClass $fila, string $etapa0, array $contenidos, string $huella): array {
        if ($fila && trim((string) $fila->contenido) !== '' && (string) $fila->huella === $huella) {
            $partes = [0 => $etapa0] + $contenidos;
            return [diseno::complete_consolidation((string) $fila->contenido, $partes, 7), !empty($fila->promptversion)];
        }
        return [self::consolidate($etapa0, $contenidos), false];
    }

    /**
     * Llama al modelo con la ficha, la validacion, el diseño, los adjuntos y las etapas aceptadas.
     *
     * @param \stdClass $fila
     * @return array [estado de la generacion, campos a guardar].
     */
    private static function generate(\stdClass $fila): array {
        global $DB;
        if (!ai\provider_factory::is_configured()) {
            return [implementacion::ERROR, ['error' => 'sinclave']];
        }
        $val = $DB->get_record('local_chatbot_impl_val', ['id' => $fila->validacionid, 'userid' => $fila->userid]);
        if (!$val) {
            return [implementacion::ERROR, ['error' => 'sinficha']];
        }
        $data = implementacion::clean((array) json_decode((string) $val->snapshot, true));
        $informe = (array) json_decode((string) $val->informe, true);
        $diseno = implementacion::get_design((int) $val->disenoid, (int) $val->userid);
        if (!$diseno || !$diseno['disponible']) {
            return [implementacion::ERROR, ['error' => 'sindiseno']];
        }
        $filas = self::get_by_impl((int) $fila->implid);
        if ((int) $fila->etapa === self::CONSOLIDACION) {
            return self::consolidate_ai($fila, $val, $filas);
        }

        $ajuste = self::decided_adjustment($filas);
        if ((int) $fila->etapa >= 4 && $ajuste === null) {
            return [implementacion::ERROR, ['error' => 'sinajuste']];
        }
        $previas = [];
        foreach ($filas as $n => $otra) {
            if ($n < (int) $fila->etapa && $otra->estado === self::ACEPTADO && $otra->contenido) {
                $previas[$n] = html_to_text((string) $otra->contenido, 0, false);
            }
        }
        ksort($previas);

        [$partes, $etiquetas] = exante::attachment_parts((int) $val->disenoid);
        $actual = $fila->contenido ? html_to_text((string) $fila->contenido, 0, false) : '';
        $analisis = self::analysis($data, $diseno['referencia']);
        $libreria = implementacion::role_library('m3e' . (int) $fila->etapa,
            (string) ($diseno['ficha']->data['nivel'] ?? ''));
        $buscar = exante::web_search_available();
        $prompt = self::build_prompt((int) $fila->etapa, [
            'ficha' => pseudonymizer::scrub(implementacion::to_text($data)),
            'datos' => self::analysis_text($analisis),
            'informe' => $informe, 'condicion' => (string) $val->condicion,
            'diseno' => pseudonymizer::scrub(implementacion::design_text($diseno['referencia'],
                array_intersect_key($diseno['etapas'], array_flip([2, 3, 4, 5, 6])))),
            'previas' => $previas, 'adjuntos' => $etiquetas, 'ajuste' => $ajuste, 'actual' => $actual,
            'ajustes' => (string) $fila->ajustes, 'conlibreria' => $libreria !== '', 'conbusqueda' => $buscar]);
        $contenido = [['role' => 'user', 'parts' => implementacion::request_parts($prompt, $partes, $libreria,
            ux::on('libreriaruta'))]];

        $modelo = ai\provider_factory::get_configured_model();
        $resultado = exante::generate_steady($contenido, 240, $modelo, $buscar);
        if (isset($resultado['error'])) {
            return [implementacion::ERROR, ['error' => 'servicio']];
        }
        $markdown = diseno::clean_markdown(gemini_client::extract_text($resultado));
        if ($markdown === '') {
            return [implementacion::ERROR, ['error' => 'ilegible']];
        }
        $campos = [];
        if ((int) $fila->etapa === 3) {
            [$markdown, $decidido] = self::parse_decision($markdown);
            $campos['informe'] = json_encode(['ajuste' => $decidido, 'minimo' => $analisis['decision']['minimo'],
                'aviso' => self::decision_warning($decidido, $analisis['decision']['minimo'])], JSON_UNESCAPED_UNICODE);
        }
        $markdown .= diseno::sources_markdown(gemini_client::extract_sources($resultado));
        // Una propuesta nueva o ajustada vuelve a borrador: la persona la revisa y la acepta de nuevo.
        return [implementacion::LISTO, $campos + ['contenido' => diseno::product_html($markdown), 'estado' => self::BORRADOR,
            'modelo' => (string) $modelo, 'promptversion' => self::PROMPT_VERSION, 'error' => null]];
    }

    /**
     * Etapa 8 con el asistente: consolida la Etapa 0 y las etapas 1 a 7 aceptadas en extenso, sin repetir campos comunes. Si
     * la respuesta llega cortada o mucho mas corta que las etapas, no se usa: queda la consolidacion completa por codigo.
     *
     * @param \stdClass $fila Fila de la etapa 8.
     * @param \stdClass $val Validacion de la Fase 1.
     * @param \stdClass[] $filas Etapas de la evaluacion.
     * @return array [estado de la generacion, campos a guardar].
     */
    private static function consolidate_ai(\stdClass $fila, \stdClass $val, array $filas): array {
        $partes = [0 => self::validation_html((string) $val->condicion, (array) json_decode((string) $val->informe, true))];
        foreach (array_keys(self::etapas()) as $n) {
            if (empty($filas[$n]) || $filas[$n]->estado !== self::ACEPTADO || trim((string) $filas[$n]->contenido) === '') {
                return [implementacion::ERROR, ['error' => 'sinetapas']];
            }
            $partes[$n] = (string) $filas[$n]->contenido;
        }
        $huella = self::stages_fingerprint($filas, (string) $val->huella);
        $contenido = [['role' => 'user', 'parts' => [['text' => self::build_consolidation_prompt($partes)]]]];
        $modelo = ai\provider_factory::get_configured_model();
        $resultado = exante::generate_steady($contenido, 600, $modelo);
        if (isset($resultado['error'])) {
            return [implementacion::ERROR, ['error' => 'servicio']];
        }
        $markdown = diseno::clean_markdown(gemini_client::extract_text($resultado));
        $fin = $resultado['candidates'][0]['finishReason'] ?? null;
        if ($markdown === '' || !diseno::consolidation_complete($markdown, $partes, $fin)) {
            return [implementacion::ERROR, ['error' => 'incompleta']];
        }
        // Queda como borrador: la persona la revisa y la confirma (o confirma la consolidacion completa por codigo).
        return [implementacion::LISTO, ['contenido' => diseno::product_html($markdown), 'estado' => self::BORRADOR,
            'huella' => $huella, 'modelo' => (string) $modelo, 'promptversion' => self::PROMPT_VERSION, 'error' => null]];
    }
}
