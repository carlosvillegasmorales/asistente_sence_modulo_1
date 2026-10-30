<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Modulo 2, Fase 2: diseno de la experiencia de aprendizaje en seis etapas (Prompt Maestro, etapas 1 a 6).
 *
 * Pedido de Interfases del 21-10-2026: cada etapa es una pagina del asistente donde la IA propone el producto
 * de la etapa a partir de la ficha validada en la Fase 1 y de las etapas ya aceptadas; la persona lo edita, pide
 * ajustes, lo acepta y lo descarga en Word. No se avanza a la etapa siguiente sin aceptar la anterior («no
 * avanzar automaticamente entre etapas»), ni se entra sin la ficha validada («este asistente no trabaja sin ficha»).
 *
 * El texto de cada etapa es el del Prompt Maestro del cliente. Lo que no lleva prefijo de BD es funcion pura y lo
 * ejercita cli/verificar_diseno.php.
 */
class diseno {

    /** @var string Version del prompt de diseno: se guarda con cada producto (RU-PRIV-006). */
    const PROMPT_VERSION = 'M2-F2-etapas-v2';

    /** Estado del producto. */
    const BORRADOR = 'borrador';
    const ACEPTADO = 'aceptado';

    /** @var int Tope de caracteres de cada etapa previa que viaja al modelo. */
    const PREVIA_MAX = 20000;

    /**
     * Las seis etapas de la Fase 2, con lo que el Prompt Maestro pide que incluya cada una.
     *
     * @return array numero => ['titulo' => string, 'archivo' => string, 'incluye' => string[], 'nota' => string].
     */
    public static function etapas(): array {
        return [
            1 => ['titulo' => 'Análisis diagnóstico', 'archivo' => 'Etapa_1_Analisis_diagnostico',
                'incluye' => ['Síntesis diagnóstica', 'Prioridades de diseño', 'Restricciones', 'Oportunidades',
                    'Recomendación didáctica', 'Nota de uso de documentos complementarios (si fueron proporcionados)'],
                'nota' => ''],
            2 => ['titulo' => 'Diseño de la experiencia', 'archivo' => 'Etapa_2_Diseno_de_la_experiencia',
                'incluye' => ['Nombre de la experiencia de aprendizaje', 'Propósito', 'Aprendizaje esperado',
                    'Criterios de evaluación', 'Contexto de aplicación', 'Modalidad', 'Estrategia didáctica', 'Recursos',
                    'Condiciones de implementación'],
                'nota' => 'El nombre de la experiencia debe ser claro, breve y representativo del desempeño esperado, '
                    . 'estar alineado con el aprendizaje esperado y ser utilizable como título formal del diseño.'],
            3 => ['titulo' => 'Secuencia instruccional', 'archivo' => 'Etapa_3_Secuencia_instruccional',
                'incluye' => ['Inicio / Desarrollo / Cierre', 'Objetivos específicos',
                    'Actividades (facilitador / participantes)', 'Recursos', 'Tiempo estimado', 'Evidencias parciales'],
                'nota' => 'La suma de los tiempos estimados debe ajustarse al tiempo asignado a la experiencia de '
                    . 'aprendizaje en la ficha (2.3).'],
            4 => ['titulo' => 'Materiales instruccionales de apoyo al aprendizaje',
                'archivo' => 'Etapa_4_Materiales_instruccionales',
                'incluye' => ['Materiales por fase', 'Objetivo', 'Contenidos', 'Formato', 'Uso pedagógico',
                    'Evidencias asociadas', 'Tabla de síntesis', 'Factibilidad', 'Vacíos y soluciones'],
                'nota' => 'No incluye instrumentos de evaluación: se desarrollan en la etapa 6.'],
            5 => ['titulo' => 'Plan de evaluación', 'archivo' => 'Etapa_5_Plan_de_evaluacion',
                'incluye' => ['Tabla con: aprendizaje esperado, criterio de evaluación, evidencia, instrumento, '
                    . 'indicador de logro, momento de evaluación y responsable'],
                'nota' => 'Una fila por criterio de evaluación.'],
            6 => ['titulo' => 'Instrumentos evaluativos', 'archivo' => 'Etapa_6_Instrumentos_evaluativos',
                'incluye' => ['Rúbricas', 'Listas de cotejo', 'Escalas de apreciación', 'Pautas de observación',
                    'Pruebas aplicadas'],
                'nota' => 'Desarrolla solo los instrumentos que el plan de evaluación (etapa 5) definió. Todos los '
                    . 'instrumentos deben estar redactados y listos para implementación.'],
        ];
    }

    /** Etapas de la Fase 3 (Prompt Maestro, etapas 7 y 8). */
    const CONSOLIDACION = 7;
    const EVALUACION = 8;

    /** @var int Puntaje desde el que el diseño queda aprobado (rubrica: 25 a 31 «Aprobado con ajustes menores»). */
    const APROBADO_DESDE = 25;

    /** @var int Recomendaciones que se piden siempre, aunque el total sea 36. */
    const RECOMENDACIONES_MIN = 2;

    /** @var int Largo minimo de la justificacion de un puntaje 4: sin evidencia citada, baja a 3. */
    const EVIDENCIA_MIN = 60;

    /**
     * Las etapas de la Fase 3: consolidacion (no editable) y evaluacion con la rubrica.
     *
     * @return array Mismo formato que etapas().
     */
    public static function etapas_fase3(): array {
        return [
            self::CONSOLIDACION => ['titulo' => 'Consolidación del diseño', 'archivo' => 'Diseno_consolidado',
                'incluye' => ['Análisis', 'Diseño', 'Secuencia', 'Materiales', 'Evaluación', 'Instrumentos'],
                'nota' => 'Todos los entregables anteriores, en extenso, en un documento único. El diseño consolidado '
                    . 'no es editable: si quieres ajustar algo, hazlo en la etapa que corresponda de la Fase 2.'],
            self::EVALUACION => ['titulo' => 'Evaluación del diseño', 'archivo' => 'Diseno_final_validado',
                'incluye' => ['Puntaje por criterio', 'Puntaje total (máx 36)', 'Fortalezas', 'Debilidades',
                    'Recomendaciones'],
                'nota' => 'El asistente aplica la rúbrica de validación técnica del diseño. Con 25 puntos o más el '
                    . 'diseño queda aprobado. El documento final lleva impresos el puntaje y su interpretación.'],
        ];
    }

    /**
     * Las ocho etapas del Prompt Maestro.
     *
     * @return array
     */
    public static function todas(): array {
        return self::etapas() + self::etapas_fase3();
    }

    /**
     * Rubrica de validacion tecnica del diseno, con el texto del documento del cliente.
     *
     * @return array id => ['nombre' => string, 'niveles' => [4 => string, 3 =>, 2 =>, 1 =>]].
     */
    public static function rubrica(): array {
        return [
            'r1' => ['nombre' => 'Coherencia con la ficha diagnóstica', 'niveles' => [
                4 => 'El diseño responde completamente a la ficha validada. Total coherencia con contexto, recursos y participantes.',
                3 => 'Coherencia general con la ficha, con omisiones menores.',
                2 => 'Uso parcial de la ficha. Decisiones poco justificadas.',
                1 => 'No hay coherencia o contradice la ficha.']],
            'r2' => ['nombre' => 'Alineación con aprendizaje esperado y criterios', 'niveles' => [
                4 => 'Total alineación con aprendizaje y todos los criterios. No se modifican.',
                3 => 'Alineación adecuada con la mayoría de criterios.',
                2 => 'Alineación incompleta o poco clara.',
                1 => 'No hay alineación o se alteran criterios.']],
            'r3' => ['nombre' => 'Calidad de la experiencia de aprendizaje', 'niveles' => [
                4 => 'Nombre claro, propósito preciso, estrategia y modalidad pertinentes y coherentes.',
                3 => 'Experiencia bien formulada con mejoras menores posibles.',
                2 => 'Presenta debilidades en claridad o pertinencia.',
                1 => 'Experiencia mal formulada o poco pertinente.']],
            'r4' => ['nombre' => 'Secuencia instruccional', 'niveles' => [
                4 => 'Progresión clara (inicio-desarrollo-cierre), coherente y bien articulada.',
                3 => 'Secuencia clara con pequeños ajustes necesarios.',
                2 => 'Desajustes en actividades, tiempos o evidencias.',
                1 => 'Sin progresión clara o incoherente.']],
            'r5' => ['nombre' => 'Materiales instruccionales', 'niveles' => [
                4 => 'Materiales pertinentes, bien definidos y alineados con evidencias.',
                3 => 'Materiales adecuados con leves mejoras necesarias.',
                2 => 'Materiales genéricos o poco claros.',
                1 => 'Materiales no pertinentes o desconectados.']],
            'r6' => ['nombre' => 'Plan de evaluación', 'niveles' => [
                4 => 'Relación clara entre aprendizaje, criterios, evidencias, instrumentos e indicadores.',
                3 => 'Plan coherente con pequeños ajustes necesarios.',
                2 => 'Relaciones incompletas o poco claras.',
                1 => 'Plan desalineado o incompleto.']],
            'r7' => ['nombre' => 'Instrumentos evaluativos', 'niveles' => [
                4 => 'Instrumentos completos, claros y listos para aplicar.',
                3 => 'Instrumentos adecuados con ajustes menores.',
                2 => 'Instrumentos poco claros o incompletos.',
                1 => 'Instrumentos no válidos o mal formulados.']],
            'r8' => ['nombre' => 'Factibilidad de implementación', 'niveles' => [
                4 => 'Totalmente viable según recursos, tiempo y contexto.',
                3 => 'Viable con ajustes menores.',
                2 => 'Factibilidad limitada.',
                1 => 'No viable en el contexto real.']],
            'r9' => ['nombre' => 'Calidad formal del documento', 'niveles' => [
                4 => 'Documento claro, ordenado y listo para uso institucional.',
                3 => 'Presentación adecuada con mejoras menores.',
                2 => 'Problemas de claridad u organización.',
                1 => 'Documento deficiente o poco usable.']],
        ];
    }

    /**
     * Interpretacion del puntaje total, con el texto de la rubrica.
     *
     * @param int $total
     * @return array ['clave' => alto|menores|importantes|no, 'texto' => string, 'aprobado' => bool].
     */
    public static function band(int $total): array {
        if ($total >= 32) {
            return ['clave' => 'alto', 'texto' => 'Aprobado con alto estándar técnico', 'aprobado' => true];
        }
        if ($total >= self::APROBADO_DESDE) {
            return ['clave' => 'menores', 'texto' => 'Aprobado con ajustes menores', 'aprobado' => true];
        }
        if ($total >= 18) {
            return ['clave' => 'importantes', 'texto' => 'Requiere ajustes importantes antes de aprobación',
                'aprobado' => false];
        }
        return ['clave' => 'no', 'texto' => 'No aprobado', 'aprobado' => false];
    }

    /**
     * Huella de lo que se consolida: el contenido de las etapas 1 a 6 aceptadas. Si cambia, hay que consolidar
     * y evaluar de nuevo.
     *
     * @param \stdClass[] $filas etapa => fila.
     * @return string
     */
    public static function stages_fingerprint(array $filas): string {
        $partes = [];
        foreach (array_keys(self::etapas()) as $n) {
            $fila = $filas[$n] ?? null;
            $partes[$n] = ($fila && $fila->estado === self::ACEPTADO) ? (string) $fila->contenido : '';
        }
        return sha1(json_encode($partes, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Nombre de la experiencia, tomado del diseno (etapa 2), para el titulo del documento final.
     *
     * @param string $texto Etapa 2 como texto.
     * @return string Vacio si no se encuentra.
     */
    public static function experience_name(string $texto): string {
        if (preg_match('/Nombre de la experiencia(?: de aprendizaje)?\s*:?\**\s*:?\s*\n*\s*([^\n]{4,160})/iu', $texto, $m)) {
            // Sin las acotaciones que a veces agrega el modelo al final («(7 palabras).»).
            $nombre = preg_replace('/\s*\([^()]*\)\s*\.?\s*$/u', '', trim($m[1]));
            return trim($nombre, " \t*:.\"«»");
        }
        return '';
    }

    /**
     * Documento consolidado: las seis etapas aceptadas, tal cual, en orden («transcribir en extenso»).
     *
     * @param string[] $contenidos etapa => HTML del producto aceptado.
     * @return string HTML.
     */
    public static function consolidate(array $contenidos): string {
        ksort($contenidos);
        $html = '';
        foreach ($contenidos as $n => $contenido) {
            if (isset(self::etapas()[$n])) {
                $html .= "\n" . trim((string) $contenido) . "\n<hr>";
            }
        }
        return trim($html);
    }

    /**
     * Instrucciones para la etapa 8: aplicar la rubrica al diseno consolidado.
     *
     * @param string $diseno Diseno consolidado como texto.
     * @param string $ficha Ficha como texto.
     * @param string[] $adjuntos
     * @return string
     */
    public static function build_eval_prompt(string $diseno, string $ficha, array $adjuntos): string {
        $rubrica = [];
        foreach (self::rubrica() as $id => $c) {
            $rubrica[] = $id . '. ' . $c['nombre'];
            foreach ($c['niveles'] as $nivel => $texto) {
                $rubrica[] = '   Nivel ' . $nivel . ' (' . $nivel . ' pts): ' . $texto;
            }
        }
        return implode("\n", [
            'Eres un asistente experto en diseño instruccional con enfoque de competencias. Tu comportamiento es '
                . 'estricto, estructurado y orientado a asegurar calidad.',
            '',
            'TAREA: ETAPA 8, EVALUACIÓN DEL DISEÑO. Evalúa el diseño instruccional consolidado con la rúbrica de '
                . 'validación técnica: asigna a cada criterio un puntaje de 1 a 4 según el nivel cuyo descriptor mejor '
                . 'corresponda, con una justificación breve y específica que cite el diseño. No reescribas el diseño.',
            'Usa la ficha de diagnóstico validada como referencia para la coherencia y la factibilidad.'
                . ($adjuntos ? ' Se adjuntan ' . implode(' y ', $adjuntos) . ' para validar la alineación.' : ''),
            'Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género. Evalúas el '
                . 'diseño, no a la persona.',
            '',
            'EVALÚA CON OJO CRÍTICO: este diseño se elaboró con apoyo de una IA, así que no lo des por bueno porque '
                . 'esté bien redactado: revísalo como lo haría una persona revisora externa. Cada puntaje 4 debe '
                . 'citar, en la justificación, la evidencia concreta del diseño que lo prueba (el elemento o fragmento '
                . 'textual); si no puedes citarla, el puntaje es 3. Antes de dar un 4, comprueba uno por uno los elementos del '
                . 'descriptor del nivel 4 contra el diseño: si falta alguno, el puntaje es 3 y la justificación dice cuál '
                . 'falta. El 4 es el nivel de excelencia: no es el valor por defecto. Aunque el total llegue a 36, entrega al menos 2 '
                . 'recomendaciones concretas de mejora.',
            '',
            'RÚBRICA (id. criterio):',
            implode("\n", $rubrica),
            '',
            guardrails::injection_clause(),
            '',
            'FORMATO DE RESPUESTA: únicamente un objeto JSON válido, sin texto antes ni después y sin bloques de código:',
            '{"criterios": {"r1": {"puntaje": 1-4, "justificacion": "..."}, ... un elemento por cada criterio r1 a r9 ...}, '
                . '"fortalezas": ["máximo 6"], "debilidades": ["máximo 6"], "recomendaciones": ["máximo 6, concretas, '
                . 'indicando la etapa de la Fase 2 donde aplicarlas"]}',
            '',
            'FICHA DE DIAGNÓSTICO VALIDADA:',
            guardrails::isolate_user_content($ficha),
            '',
            'DISEÑO CONSOLIDADO A EVALUAR:',
            guardrails::isolate_user_content($diseno),
        ]);
    }

    /**
     * Lee la evaluacion del modelo. Sin los nueve criterios con puntaje de 1 a 4, no sirve. El total y la
     * interpretacion los calcula el codigo, no el modelo.
     *
     * @param string|null $texto
     * @param bool $exigeevidencia Un 4 sin evidencia citada cuenta como 3 (interruptor «iaapoyo»).
     * @return array|null criterios (id => [puntaje, justificacion]), total, banda, fortalezas, debilidades, recomendaciones.
     */
    public static function parse_eval(?string $texto, bool $exigeevidencia = true): ?array {
        $json = exante::decode_json($texto);
        if (!$json || !isset($json['criterios']) || !is_array($json['criterios'])) {
            return null;
        }
        $criterios = [];
        $total = 0;
        foreach (array_keys(self::rubrica()) as $id) {
            $c = $json['criterios'][$id] ?? null;
            $p = is_array($c) && isset($c['puntaje']) && is_numeric($c['puntaje']) ? (int) $c['puntaje'] : 0;
            if ($p < 1 || $p > 4 || (float) $c['puntaje'] !== (float) $p) {
                return null;
            }
            $justificacion = \core_text::substr(trim((string) ($c['justificacion'] ?? '')), 0, 800);
            $p = $exigeevidencia ? self::supported_score($p, $justificacion) : $p;
            $criterios[$id] = ['puntaje' => $p, 'justificacion' => $justificacion];
            $total += $p;
        }
        $lista = static function($v) {
            $salida = [];
            foreach ((array) $v as $t) {
                $t = is_scalar($t) ? \core_text::substr(trim((string) $t), 0, 600) : '';
                if ($t !== '') {
                    $salida[] = $t;
                }
            }
            return array_slice($salida, 0, 6);
        };
        return ['criterios' => $criterios, 'total' => $total, 'banda' => self::band($total),
            'fortalezas' => $lista($json['fortalezas'] ?? []), 'debilidades' => $lista($json['debilidades'] ?? []),
            'recomendaciones' => $lista($json['recomendaciones'] ?? [])];
    }

    /**
     * Si se puede descargar el documento final. Con el interruptor «descargafinal» (punto 14, 29-10-2026), siempre que
     * haya una evaluacion vigente: el documento lleva impresos el puntaje y la banda, y los 25 puntos quedan como
     * informacion. Apagado, solo con el diseno aprobado (25 puntos o mas), como antes.
     *
     * @param array|null $evaluacion Evaluacion vigente (current_evaluation()).
     * @param bool $siempre Interruptor «descargafinal».
     * @return bool
     */
    public static function can_download(?array $evaluacion, bool $siempre): bool {
        if ($evaluacion === null) {
            return false;
        }
        return $siempre || !empty($evaluacion['banda']['aprobado']);
    }

    /**
     * Un puntaje 4 necesita la evidencia del diseno citada en la justificacion; sin ella baja a 3.
     *
     * @param int $puntaje
     * @param string $justificacion
     * @return int
     */
    public static function supported_score(int $puntaje, string $justificacion): int {
        return $puntaje === 4 && \core_text::strlen(trim($justificacion)) < self::EVIDENCIA_MIN ? 3 : $puntaje;
    }

    /**
     * La evaluacion como HTML (para la pagina y para el anexo del documento final).
     *
     * @param array $informe Resultado de parse_eval().
     * @return string
     */
    public static function evaluation_html(array $informe): string {
        $e = static function($t) {
            return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        };
        $html = '<table class="table table-sm align-middle"><thead><tr><th>Criterio</th><th>Puntaje</th>'
            . '<th>Nivel</th><th>Justificación</th></tr></thead><tbody>';
        foreach (self::rubrica() as $id => $c) {
            $p = (int) ($informe['criterios'][$id]['puntaje'] ?? 0);
            $html .= '<tr><td>' . $e(substr($id, 1) . '. ' . $c['nombre']) . '</td><td>' . $p . ' / 4</td><td>'
                . $e($c['niveles'][$p] ?? '') . '</td><td>' . $e($informe['criterios'][$id]['justificacion'] ?? '')
                . '</td></tr>';
        }
        $html .= '<tr><th>Puntaje total</th><th>' . (int) $informe['total'] . ' / 36</th><th colspan="2">'
            . $e($informe['banda']['texto']) . '</th></tr></tbody></table>';
        foreach (['fortalezas' => 'Fortalezas', 'debilidades' => 'Debilidades', 'recomendaciones' => 'Recomendaciones']
                as $clave => $titulo) {
            if (!empty($informe[$clave])) {
                $html .= '<h3>' . $titulo . '</h3><ul>';
                foreach ($informe[$clave] as $t) {
                    $html .= '<li>' . $e($t) . '</li>';
                }
                $html .= '</ul>';
            }
        }
        return $html;
    }

    /**
     * Si la persona puede abrir una etapa: necesita la Fase 1 aprobada y todas las anteriores aceptadas.
     *
     * @param int $etapa
     * @param bool $fase1 Ficha validada con Aprobado o Aprobado con observaciones, vigente.
     * @param int[] $aceptadas Numeros de etapa aceptados.
     * @return bool
     */
    public static function can_open(int $etapa, bool $fase1, array $aceptadas): bool {
        if (!$fase1 || !isset(self::todas()[$etapa])) {
            return false;
        }
        for ($i = 1; $i < $etapa; $i++) {
            if (!in_array($i, $aceptadas, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Etapa en la que corresponde trabajar: la primera no aceptada (o la ultima si todas lo estan).
     *
     * @param int[] $aceptadas
     * @return int
     */
    public static function current_stage(array $aceptadas): int {
        foreach (array_keys(self::todas()) as $n) {
            if (!in_array($n, $aceptadas, true)) {
                return $n;
            }
        }
        return max(array_keys(self::todas()));
    }

    /**
     * Etapa que abre la pagina de la Fase 2 cuando no se pide una: la primera sin aceptar de la Fase 2, o la etapa 1
     * si las seis estan aceptadas, para recorrer el diseno. Antes caia en la etapa 8 y, con el modulo terminado, no
     * habia como volver a las etapas (Interfases, 26-10-2026). La Fase 3 se abre con su propio enlace (etapa 7).
     *
     * @param int[] $aceptadas
     * @return int
     */
    public static function entry_stage(array $aceptadas): int {
        foreach (array_keys(self::etapas()) as $n) {
            if (!in_array($n, $aceptadas, true)) {
                return $n;
            }
        }
        return 1;
    }

    /**
     * Instrucciones al modelo para producir (o ajustar) el producto de una etapa.
     *
     * @param int $etapa
     * @param string $ficha Ficha como texto (exante::to_text).
     * @param array $informe Informe de la validacion de la Fase 1 (debilidades y sugerencias).
     * @param string[] $previas Numero de etapa => texto del producto aceptado.
     * @param string[] $adjuntos Etiquetas de los documentos complementarios que se adjuntan.
     * @param string $actual Producto actual de la etapa, si se piden ajustes.
     * @param string $ajustes Ajustes que pide la persona.
     * @return string
     */
    public static function build_prompt(int $etapa, string $ficha, array $informe, array $previas, array $adjuntos,
            string $actual = '', string $ajustes = ''): string {
        $e = self::etapas()[$etapa];
        $l = [];
        $l[] = 'Eres un asistente experto en diseño instruccional con enfoque de competencias, especializado en formación '
            . 'de personas adultas en contextos de capacitación laboral. Apoyas a un equipo de capacitación en el diseño '
            . 'de experiencias de aprendizaje, usando como insumo principal y obligatorio la ficha de diagnóstico '
            . '(evaluación ex-ante) ya validada. Tu comportamiento es estricto, estructurado y orientado a asegurar calidad.';
        $l[] = '';
        $l[] = 'TAREA: ETAPA ' . $etapa . ': ' . \core_text::strtoupper($e['titulo']) . '.';
        $l[] = 'Desarrolla solo esta etapa; no adelantes las siguientes. Debe incluir obligatoriamente:';
        foreach ($e['incluye'] as $item) {
            $l[] = '- ' . $item;
        }
        if ($e['nota'] !== '') {
            $l[] = $e['nota'];
        }
        $l[] = '';
        $l[] = 'REGLAS DE GOBERNANZA:';
        $l[] = '- Usa como fuente principal la ficha de diagnóstico. ' . ($adjuntos
            ? 'Se adjuntan ' . implode(' y ', $adjuntos) . ': úsalos para validar coherencia, profundidad y alineación, '
                . 'priorizando siempre la ficha.'
            : 'No se adjuntaron el Plan Formativo ni el Perfil Ocupacional: continúa y señala cuando una decisión podría '
                . 'beneficiarse de ellos.');
        $l[] = '- No inventes aprendizajes esperados ni criterios de evaluación: transcríbelos tal como están en la ficha.';
        $l[] = '- Trabaja con todos los criterios de evaluación que registra la ficha (al menos 3; no hay un número máximo).';
        $l[] = '- No utilices en los aprendizajes o criterios conceptos que remitan a juicios de valor, como '
            . '"adecuadamente". Si la ficha los trae, no alteres el plan formativo: señálalo en una observación.';
        $l[] = '- Mantén la alineación constructiva con las etapas ya aceptadas y con los criterios de evaluación.';
        $l[] = '- Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género.';
        $l[] = '- Detecta vacíos de información y repórtalos. Propón soluciones viables sin alterar el plan formativo.';
        $l[] = '- Considera las observaciones de la validación de la ficha.';
        $l[] = '';
        $l[] = 'CONTROL DE CALIDAD: antes de cerrar la etapa verifica la alineación con el aprendizaje esperado, la '
            . 'coherencia con los criterios de evaluación y la aplicabilidad práctica. Termina el documento con una '
            . 'sección breve «Verificación de calidad de la etapa» que diga qué verificaste y, si detectas una '
            . 'inconsistencia, qué ajuste se necesita.';
        $l[] = '';
        $l[] = 'FORMATO: entrega el producto en Markdown, estructurado, institucional y aplicable, listo para copiar en '
            . 'un documento Word: títulos con ## y ###, listas y tablas Markdown cuando ayuden. Empieza directamente '
            . 'con el título «## Etapa ' . $etapa . ': ' . $e['titulo'] . '». No agregues saludos, preguntas al final '
            . 'ni comentarios fuera del producto: la plataforma ya le pregunta a la persona si quiere ajustes.';
        $l[] = '';
        $l[] = guardrails::injection_clause();
        $l[] = '';
        $l[] = 'FICHA DE DIAGNÓSTICO VALIDADA:';
        $l[] = guardrails::isolate_user_content($ficha);
        $obs = array_merge($informe['debilidades'] ?? [], $informe['sugerencias'] ?? []);
        if ($obs) {
            $l[] = '';
            $l[] = 'OBSERVACIONES DE LA VALIDACIÓN DE LA FICHA:';
            foreach ($obs as $o) {
                $l[] = '- ' . $o;
            }
        }
        foreach ($previas as $n => $texto) {
            $l[] = '';
            $l[] = 'PRODUCTO ACEPTADO DE LA ETAPA ' . $n . ' (' . self::etapas()[$n]['titulo'] . '):';
            $l[] = guardrails::isolate_user_content(\core_text::substr($texto, 0, self::PREVIA_MAX));
        }
        if (trim($ajustes) !== '') {
            $l[] = '';
            $l[] = 'PRODUCTO ACTUAL DE ESTA ETAPA (versión que la persona quiere ajustar):';
            $l[] = guardrails::isolate_user_content(\core_text::substr($actual, 0, self::PREVIA_MAX));
            $l[] = '';
            $l[] = 'AJUSTES QUE PIDE LA PERSONA: aplícalos y entrega el producto completo de la etapa, no solo los cambios. '
                . 'Si un ajuste contradice la ficha, el plan formativo o las reglas, no lo apliques y explícalo en la '
                . 'verificación de calidad.';
            $l[] = guardrails::isolate_user_content($ajustes);
        }
        return implode("\n", $l);
    }

    /**
     * Quita bloques de codigo que envuelvan toda la respuesta (```markdown ... ```).
     *
     * @param string|null $texto
     * @return string
     */
    public static function clean_markdown(?string $texto): string {
        $texto = trim((string) $texto);
        if (preg_match('/^```[a-zA-Z]*\s*\n(.*)\n```$/s', $texto, $m)) {
            $texto = trim($m[1]);
        }
        return $texto;
    }

    // ------------------------------------------------------------------------------------------
    // Word (.docx) a partir del HTML del producto. Funcion pura (escribe en la ruta indicada).
    // ------------------------------------------------------------------------------------------

    /**
     * Arma un .docx con el titulo y el HTML del producto (titulos, parrafos, listas, tablas, negritas, cursivas).
     *
     * @param string $ruta Archivo de salida.
     * @param string $titulo
     * @param string $subtitulo
     * @param string $html
     * @return bool
     */
    public static function html_to_docx(string $ruta, string $titulo, string $subtitulo, string $html): bool {
        if (!class_exists('\ZipArchive')) {
            return false;
        }
        $cuerpo = self::w_parrafo([self::w_run($titulo)], 'Title')
            . ($subtitulo !== '' ? self::w_parrafo([self::w_run($subtitulo, ['i' => true])], 'Subtitle') : '');
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        $body = $dom->getElementsByTagName('body')->item(0);
        $cuerpo .= $body ? self::w_bloques($body, 0) : '';

        $zip = new \ZipArchive();
        if ($zip->open($ruta, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.'
            . 'wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.'
            . 'wordprocessingml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'
            . 'officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" '
            . 'Target="styles.xml"/></Relationships>');
        $zip->addFromString('word/styles.xml', self::w_styles());
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . $cuerpo . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1418" w:right="1418" '
            . 'w:bottom="1418" w:left="1418" w:header="709" w:footer="709" w:gutter="0"/></w:sectPr></w:body></w:document>');
        return $zip->close();
    }

    /**
     * Estilos minimos: normal, titulo, subtitulo y titulos 1 a 3.
     *
     * @return string
     */
    private static function w_styles(): string {
        $estilo = static function(string $id, string $nombre, int $sz, bool $b, string $color, int $antes, int $despues) {
            return '<w:style w:type="paragraph" w:styleId="' . $id . '"><w:name w:val="' . $nombre . '"/>'
                . '<w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/>'
                . '<w:spacing w:before="' . $antes . '" w:after="' . $despues . '"/></w:pPr><w:rPr>' . ($b ? '<w:b/>' : '')
                . '<w:color w:val="' . $color . '"/><w:sz w:val="' . $sz . '"/></w:rPr></w:style>';
        };
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
            . '<w:sz w:val="22"/><w:lang w:val="es-CL"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr>'
            . '<w:spacing w:after="120" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . $estilo('Title', 'Title', 36, true, '173F5F', 0, 120)
            . $estilo('Subtitle', 'Subtitle', 24, false, '54656F', 0, 240)
            . $estilo('Heading1', 'heading 1', 30, true, '173F5F', 360, 120)
            . $estilo('Heading2', 'heading 2', 26, true, '20639B', 280, 100)
            . $estilo('Heading3', 'heading 3', 23, true, '20639B', 200, 80)
            . '</w:styles>';
    }

    /**
     * @param string $texto
     * @param array $fmt b, i, u => bool.
     * @return string
     */
    private static function w_run(string $texto, array $fmt = []): string {
        $rpr = (!empty($fmt['b']) ? '<w:b/>' : '') . (!empty($fmt['i']) ? '<w:i/>' : '') . (!empty($fmt['u']) ? '<w:u w:val="single"/>' : '');
        return '<w:r>' . ($rpr ? '<w:rPr>' . $rpr . '</w:rPr>' : '') . '<w:t xml:space="preserve">'
            . htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r>';
    }

    /**
     * @param string[] $runs
     * @param string $estilo
     * @param int $sangria Twips.
     * @return string
     */
    private static function w_parrafo(array $runs, string $estilo = '', int $sangria = 0): string {
        $ppr = ($estilo ? '<w:pStyle w:val="' . $estilo . '"/>' : '') . ($sangria ? '<w:ind w:left="' . $sangria
            . '" w:hanging="283"/>' : '');
        return '<w:p>' . ($ppr ? '<w:pPr>' . $ppr . '</w:pPr>' : '') . implode('', $runs) . '</w:p>';
    }

    /**
     * Runs de un nodo en linea (texto con negritas, cursivas y saltos).
     *
     * @param \DOMNode $nodo
     * @param array $fmt
     * @return string[]
     */
    private static function w_runs(\DOMNode $nodo, array $fmt = []): array {
        $runs = [];
        foreach ($nodo->childNodes as $hijo) {
            if ($hijo instanceof \DOMText) {
                $t = preg_replace('/\s+/u', ' ', $hijo->nodeValue);
                if ($t !== '') {
                    $runs[] = self::w_run($t, $fmt);
                }
                continue;
            }
            if (!$hijo instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($hijo->nodeName);
            if ($tag === 'br') {
                $runs[] = '<w:r><w:br/></w:r>';
                continue;
            }
            if (in_array($tag, ['ul', 'ol', 'table'], true)) {
                continue;
            }
            $f = $fmt;
            if (in_array($tag, ['strong', 'b', 'th'], true)) {
                $f['b'] = true;
            } else if (in_array($tag, ['em', 'i'], true)) {
                $f['i'] = true;
            } else if ($tag === 'u') {
                $f['u'] = true;
            }
            $runs = array_merge($runs, self::w_runs($hijo, $f));
        }
        return $runs;
    }

    /**
     * Bloques (titulos, parrafos, listas, tablas) de un contenedor.
     *
     * @param \DOMNode $padre
     * @param int $nivel Nivel de lista, para la sangria.
     * @param array $fmt Formato heredado (negrita en las celdas de encabezado).
     * @return string
     */
    private static function w_bloques(\DOMNode $padre, int $nivel, array $fmt = []): string {
        $xml = '';
        $suelto = [];
        $vaciar = static function() use (&$suelto, &$xml) {
            $runs = array_filter($suelto, static function($r) {
                return trim(strip_tags($r)) !== '' || strpos($r, '<w:br/>') !== false;
            });
            if ($runs) {
                $xml .= self::w_parrafo($suelto);
            }
            $suelto = [];
        };
        foreach ($padre->childNodes as $hijo) {
            $tag = $hijo instanceof \DOMElement ? strtolower($hijo->nodeName) : '';
            if ($hijo instanceof \DOMText || in_array($tag, ['strong', 'b', 'em', 'i', 'u', 'span', 'a', 'code', 'br'], true)) {
                $suelto = array_merge($suelto, self::w_runs(self::envolver($hijo), $fmt));
                continue;
            }
            $vaciar();
            if (preg_match('/^h([1-6])$/', $tag, $m)) {
                $xml .= self::w_parrafo(self::w_runs($hijo, $fmt), 'Heading' . min(3, (int) $m[1]));
            } else if (in_array($tag, ['p', 'blockquote', 'pre'], true)) {
                $runs = self::w_runs($hijo, $fmt);
                if ($runs) {
                    $xml .= self::w_parrafo($runs);
                }
            } else if ($tag === 'ul' || $tag === 'ol') {
                $i = 0;
                foreach ($hijo->childNodes as $li) {
                    if (!$li instanceof \DOMElement || strtolower($li->nodeName) !== 'li') {
                        continue;
                    }
                    $i++;
                    $marca = $tag === 'ol' ? $i . '. ' : '• ';
                    $xml .= self::w_parrafo(array_merge([self::w_run($marca, $fmt)], self::w_runs($li, $fmt)), '', 567 * ($nivel + 1));
                    foreach ($li->childNodes as $sub) {
                        if ($sub instanceof \DOMElement && in_array(strtolower($sub->nodeName), ['ul', 'ol'], true)) {
                            $xml .= self::w_bloques(self::envolver($sub), $nivel + 1, $fmt);
                        }
                    }
                }
            } else if ($tag === 'table') {
                $xml .= self::w_tabla($hijo);
            } else if ($tag === 'hr') {
                $xml .= '<w:p/>';
            } else if ($tag !== '') {
                $xml .= self::w_bloques($hijo, $nivel, $fmt);
            }
        }
        $vaciar();
        return $xml;
    }

    /**
     * Nodo envuelto en un contenedor propio, para recorrerlo como bloque.
     *
     * @param \DOMNode $nodo
     * @return \DOMNode
     */
    private static function envolver(\DOMNode $nodo): \DOMNode {
        $caja = $nodo->ownerDocument->createElement('div');
        $caja->appendChild($nodo->cloneNode(true));
        return $caja;
    }

    /**
     * Tabla con bordes simples; la fila de encabezado en negrita y sombreada.
     *
     * @param \DOMElement $tabla
     * @return string
     */
    private static function w_tabla(\DOMElement $tabla): string {
        $filas = [];
        $cols = 0;
        foreach ($tabla->getElementsByTagName('tr') as $tr) {
            $celdas = [];
            foreach ($tr->childNodes as $td) {
                if ($td instanceof \DOMElement && in_array(strtolower($td->nodeName), ['td', 'th'], true)) {
                    $celdas[] = $td;
                }
            }
            $cols = max($cols, count($celdas));
            $filas[] = $celdas;
        }
        if (!$filas || !$cols) {
            return '';
        }
        $ancho = (int) floor(9360 / $cols);
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $b) {
            $xml .= '<w:' . $b . ' w:val="single" w:sz="4" w:space="0" w:color="A6B4C0"/>';
        }
        $xml .= '</w:tblBorders><w:tblCellMar><w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/>'
            . '</w:tblCellMar></w:tblPr><w:tblGrid>' . str_repeat('<w:gridCol w:w="' . $ancho . '"/>', $cols) . '</w:tblGrid>';
        foreach ($filas as $celdas) {
            $xml .= '<w:tr>';
            for ($c = 0; $c < $cols; $c++) {
                $td = $celdas[$c] ?? null;
                $th = $td && strtolower($td->nodeName) === 'th';
                $contenido = $td ? self::w_bloques($td, 0, $th ? ['b' => true] : []) : '';
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $ancho . '" w:type="dxa"/>'
                    . ($th ? '<w:shd w:val="clear" w:color="auto" w:fill="E8F0F7"/>' : '') . '</w:tcPr>'
                    . ($contenido !== '' ? $contenido : '<w:p/>') . '</w:tc>';
            }
            $xml .= '</w:tr>';
        }
        return $xml . '</w:tbl><w:p/>';
    }

    // ------------------------------------------------------------------------------------------
    // Base de datos.
    // ------------------------------------------------------------------------------------------

    /**
     * Productos del diseno en uso de la persona en el curso.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass[] etapa => fila.
     */
    public static function get_all(int $userid, int $courseid): array {
        $ficha = exante::get_ficha($userid, $courseid);
        return $ficha ? self::get_by_ficha((int) $ficha->id) : [];
    }

    /**
     * Productos de un diseno (cada ficha tiene sus propias etapas).
     *
     * @param int $fichaid
     * @return \stdClass[] etapa => fila.
     */
    public static function get_by_ficha(int $fichaid): array {
        global $DB;
        $salida = [];
        foreach ($DB->get_records('local_chatbot_diseno', ['fichaid' => $fichaid]) as $fila) {
            if ($fila->generacion === exante::PROCESANDO && $fila->timemodified < time() - exante::PROCESO_MAX) {
                $fila->generacion = exante::ERROR;
                $fila->error = 'tiempo';
                $DB->update_record('local_chatbot_diseno', (object) ['id' => $fila->id, 'generacion' => $fila->generacion,
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
     * @return int[]
     */
    public static function accepted(array $filas): array {
        $salida = [];
        $huella = self::stages_fingerprint($filas);
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
     * Fila de una etapa del diseno en uso (la crea vacia si no existe).
     *
     * @param int $userid
     * @param int $courseid
     * @param int $etapa
     * @return \stdClass
     */
    private static function row(int $userid, int $courseid, int $etapa): \stdClass {
        global $DB;
        $ficha = exante::get_ficha($userid, $courseid);
        if (!$ficha) {
            throw new \moodle_exception('invalidrecord', 'error', '', 'local_chatbot_exante');
        }
        $fila = $DB->get_record('local_chatbot_diseno', ['fichaid' => $ficha->id, 'etapa' => $etapa]);
        if ($fila) {
            return $fila;
        }
        $now = time();
        $fila = (object) ['fichaid' => (int) $ficha->id, 'userid' => $userid, 'courseid' => $courseid, 'etapa' => $etapa,
            'contenido' => null, 'estado' => self::BORRADOR, 'generacion' => '', 'timecreated' => $now, 'timemodified' => $now];
        $fila->id = $DB->insert_record('local_chatbot_diseno', $fila);
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
        if (in_array($fila->generacion, [exante::PENDIENTE, exante::PROCESANDO], true)) {
            return (int) $fila->id;
        }
        $DB->update_record('local_chatbot_diseno', (object) ['id' => $fila->id, 'generacion' => exante::PENDIENTE,
            'ajustes' => \core_text::substr(trim($ajustes), 0, 4000), 'validacionid' => $validacionid, 'error' => null,
            'timemodified' => time()]);
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
        $candado = \core\lock\lock_config::get_lock_factory('local_chatbot_diseno')->get_lock('gen' . $id, 0);
        if (!$candado) {
            return exante::PROCESANDO;
        }
        try {
            $fila = $DB->get_record('local_chatbot_diseno', ['id' => $id, 'userid' => $userid]);
            if (!$fila || $fila->generacion !== exante::PENDIENTE) {
                return $fila ? (string) $fila->generacion : exante::ERROR;
            }
            $DB->update_record('local_chatbot_diseno', (object) ['id' => $id, 'generacion' => exante::PROCESANDO,
                'timemodified' => time()]);
            [$estado, $campos] = self::generate($fila);
            $DB->update_record('local_chatbot_diseno', (object) (['id' => $id, 'generacion' => $estado,
                'timemodified' => time()] + $campos));
            return $estado;
        } finally {
            $candado->release();
        }
    }

    /**
     * Confirma el diseno consolidado (etapa 7): guarda el documento y la huella de las etapas que lo forman.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $html
     * @param string $huella
     * @return void
     */
    public static function confirm_consolidation(int $userid, int $courseid, string $html, string $huella): void {
        global $DB;
        $fila = self::row($userid, $courseid, self::CONSOLIDACION);
        $DB->update_record('local_chatbot_diseno', (object) ['id' => $fila->id, 'contenido' => $html, 'huella' => $huella,
            'estado' => self::ACEPTADO, 'timeaccepted' => time(), 'timemodified' => time()]);
    }

    /**
     * Evaluacion vigente (etapa 8): lista y hecha sobre la consolidacion confirmada que sigue al dia.
     *
     * @param \stdClass[] $filas
     * @return array|null Resultado de parse_eval(), con 'fecha'.
     */
    public static function current_evaluation(array $filas): ?array {
        $c = $filas[self::CONSOLIDACION] ?? null;
        $e = $filas[self::EVALUACION] ?? null;
        if (!$c || !$e || !in_array(self::CONSOLIDACION, self::accepted($filas), true) || !$e->informe
                || (string) $e->huella !== (string) $c->huella) {
            return null;
        }
        $informe = json_decode((string) $e->informe, true);
        if (!is_array($informe)) {
            return null;
        }
        $informe['fecha'] = (int) $e->timemodified;
        return $informe;
    }

    /**
     * Llama al modelo con la ficha, la validacion, los adjuntos y las etapas aceptadas.
     *
     * @param \stdClass $fila
     * @return array [estado de la generacion, campos a guardar].
     */
    private static function generate(\stdClass $fila): array {
        global $DB;
        if (!ai\provider_factory::is_configured()) {
            return [exante::ERROR, ['error' => 'sinclave']];
        }
        $val = $DB->get_record('local_chatbot_exante_val', ['id' => $fila->validacionid, 'userid' => $fila->userid]);
        if (!$val) {
            return [exante::ERROR, ['error' => 'sinficha']];
        }
        $ficha = exante::clean((array) json_decode((string) $val->snapshot, true));
        $informe = (array) json_decode((string) $val->informe, true);

        if ((int) $fila->etapa === self::EVALUACION) {
            return self::evaluate($fila, $ficha, (int) $val->fichaid);
        }

        $previas = [];
        // Las etapas del mismo diseno, aunque la persona haya cambiado de diseno mientras esto corria.
        foreach (self::get_by_ficha((int) $fila->fichaid) as $n => $otra) {
            if ($n < (int) $fila->etapa && $otra->estado === self::ACEPTADO && $otra->contenido) {
                $previas[$n] = html_to_text((string) $otra->contenido, 0, false);
            }
        }
        ksort($previas);

        [$partes, $etiquetas] = exante::attachment_parts((int) $val->fichaid);
        $actual = $fila->contenido ? html_to_text((string) $fila->contenido, 0, false) : '';
        $prompt = self::build_prompt((int) $fila->etapa, pseudonymizer::scrub(exante::to_text($ficha)), $informe,
            $previas, $etiquetas, $actual, (string) $fila->ajustes);
        $contenido = [['role' => 'user', 'parts' => array_merge([['text' => $prompt]], $partes)]];

        $modelo = ai\provider_factory::get_configured_model();
        $resultado = ai\provider_factory::get()->generate_content($contenido, 240, 2, $modelo);
        if (isset($resultado['error'])) {
            return [exante::ERROR, ['error' => 'servicio']];
        }
        $markdown = self::clean_markdown(gemini_client::extract_text($resultado));
        if ($markdown === '') {
            return [exante::ERROR, ['error' => 'ilegible']];
        }
        // Una propuesta nueva o ajustada vuelve a borrador: la persona la revisa y la acepta de nuevo.
        return [exante::LISTO, ['contenido' => clean_text(markdown_to_html($markdown), FORMAT_HTML),
            'estado' => self::BORRADOR, 'modelo' => (string) $modelo, 'promptversion' => self::PROMPT_VERSION,
            'error' => null]];
    }

    /**
     * Etapa 8: el modelo aplica la rubrica al diseno consolidado confirmado.
     *
     * @param \stdClass $fila
     * @param array $ficha
     * @param int $fichaid
     * @return array [estado de la generacion, campos a guardar].
     */
    private static function evaluate(\stdClass $fila, array $ficha, int $fichaid): array {
        global $DB;
        $c = $DB->get_record('local_chatbot_diseno', ['fichaid' => $fila->fichaid, 'etapa' => self::CONSOLIDACION]);
        if (!$c || $c->estado !== self::ACEPTADO || !$c->contenido) {
            return [exante::ERROR, ['error' => 'sinconsolidado']];
        }
        [$partes, $etiquetas] = exante::attachment_parts($fichaid);
        $prompt = self::build_eval_prompt(html_to_text((string) $c->contenido, 0, false),
            pseudonymizer::scrub(exante::to_text($ficha)), $etiquetas);
        $contenido = [['role' => 'user', 'parts' => array_merge([['text' => $prompt]], $partes)]];
        $modelo = ai\provider_factory::get_configured_model();
        $informe = null;
        $primero = null;
        // Un segundo intento solo si la respuesta no se pudo leer: la rubrica va entera o no va.
        for ($intento = 0; $intento < 2 && $informe === null; $intento++) {
            $resultado = exante::generate_steady($contenido, 240, $modelo);
            if (isset($resultado['error'])) {
                if ($primero !== null) {
                    break;
                }
                return [exante::ERROR, ['error' => 'servicio']];
            }
            $informe = self::parse_eval(gemini_client::extract_text($resultado), ux::on('iaapoyo'));
            // Con menos de 2 recomendaciones se pide una vez mas; si sigue igual, se conserva la lectura valida.
            if ($informe !== null && count($informe['recomendaciones']) < self::RECOMENDACIONES_MIN && $intento === 0) {
                $primero = $informe;
                $informe = null;
            }
        }
        $informe = $informe ?? $primero;
        if ($informe === null) {
            return [exante::ERROR, ['error' => 'ilegible']];
        }
        return [exante::LISTO, ['informe' => json_encode($informe, JSON_UNESCAPED_UNICODE), 'huella' => $c->huella,
            'modelo' => (string) $modelo, 'promptversion' => self::PROMPT_VERSION, 'error' => null]];
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
        $DB->update_record('local_chatbot_diseno', (object) $campos);
    }
}
