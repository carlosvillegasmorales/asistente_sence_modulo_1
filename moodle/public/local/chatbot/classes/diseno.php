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
    const PROMPT_VERSION = 'M2-F2-etapas-v8';

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
                'incluye' => ['Síntesis diagnóstica',
                    'Resultado de la validación (Etapa 0) y observaciones no resueltas, incorporadas como restricciones o '
                        . 'prioridades de diseño',
                    'Síntesis de la observación obligatoria 2.4 (pertinencia y factibilidad de los criterios) y de las '
                        . 'observaciones finales de la ficha',
                    'Prioridades de diseño', 'Restricciones', 'Oportunidades', 'Recomendación didáctica',
                    'Nota de uso de documentos complementarios (plan formativo y perfil ocupacional)'],
                'nota' => ''],
            2 => ['titulo' => 'Diseño de la experiencia', 'archivo' => 'Etapa_2_Diseno_de_la_experiencia',
                'incluye' => ['Nombre de la experiencia de aprendizaje', 'Propósito', 'Aprendizaje esperado',
                    'Criterios de evaluación', 'Contexto de aplicación', 'Modalidad', 'Estrategia didáctica', 'Recursos',
                    'Condiciones de implementación'],
                'nota' => 'El nombre de la experiencia debe ser claro, breve y representativo del desempeño esperado, '
                    . 'estar alineado con el aprendizaje esperado y ser utilizable como título formal del diseño.'],
            3 => ['titulo' => 'Secuencia instruccional', 'archivo' => 'Etapa_3_Secuencia_instruccional',
                'incluye' => ['Inicio / Desarrollo / Cierre',
                    'Alcance de cada fase (descripción de lo que se espera lograr en la fase, sin formularlo como objetivo '
                        . 'específico)',
                    'Actividades (persona facilitadora / participantes)', 'Recursos',
                    'Tiempo estimado en minutos por actividad y por fase; la suma total no debe superar el tiempo asignado '
                        . 'a la experiencia de aprendizaje (ficha, subsección 2.3)',
                    'Evidencias parciales'],
                'nota' => ''],
            4 => ['titulo' => 'Materiales instruccionales de apoyo al aprendizaje',
                'archivo' => 'Etapa_4_Materiales_instruccionales',
                'incluye' => ['Materiales por fase', 'Alcance', 'Contenidos', 'Formato', 'Uso didáctico',
                    'Evidencias asociadas', 'Tabla de síntesis', 'Factibilidad', 'Vacíos y soluciones'],
                'nota' => 'No incluye instrumentos de evaluación: estos se desarrollan en la Etapa 6.'],
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

    /** @var int Segundos que puede tardar la consolidacion con el asistente (un documento largo, en extenso). */
    const CONSOLIDACION_MAX = 900;

    /** @var float Parte minima del texto de las etapas que debe conservar la consolidacion para darla por completa. */
    const CONSOLIDACION_MIN = 0.5;

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
                'incluye' => ['Puntaje por criterio, indicando para cada criterio limitado la sección y el ítem del '
                    . 'checklist que originan el límite', 'Puntaje total (máx. 36) y puntaje máximo alcanzable',
                    'Fortalezas', 'Debilidades', 'Recomendaciones'],
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
     * Rubrica de validacion tecnica del diseno, con el texto del documento del cliente (version del 30-10-2026,
     * «Rúbrica Validación Técnica Diseño - Interfases»). El nivel 3 de los criterios 1 a 8 incluye el caso «O bien»
     * del limite por observaciones no resueltas; el informe lo muestra solo en un criterio limitado.
     *
     * @return array id => ['nombre' => string, 'niveles' => [4 => string, 3 =>, 2 =>, 1 =>]].
     */
    public static function rubrica(): array {
        return [
            'r1' => ['nombre' => 'Coherencia con la ficha diagnóstica', 'niveles' => [
                4 => 'El diseño incorpora la información relevante de la ficha validada (características y '
                    . 'necesidades del grupo, contexto y decisiones de diseño) y responde a las observaciones 2.4 y '
                    . 'finales. Las diferencias con la ficha están justificadas.',
                3 => 'Incorpora la información de la ficha con omisiones menores; las decisiones principales son '
                    . 'coherentes con ella. O bien: cumple el nivel 4, pero el criterio está limitado por una '
                    . 'observación no resuelta del diagnóstico (secciones 1, 4 y 9 del checklist).',
                2 => 'Usa la ficha de forma parcial: omite información relevante del grupo o del contexto, o se '
                    . 'aparta de sus decisiones sin justificarlo.',
                1 => 'No considera la ficha o la contradice.']],
            'r2' => ['nombre' => 'Alineación con aprendizaje esperado y criterios', 'niveles' => [
                4 => 'Todas las actividades y evidencias se vinculan con el aprendizaje esperado y con cada criterio '
                    . 'utilizado (al menos 3 del plan formativo, o todos si declara menos), sin modificaciones.',
                3 => 'Se alinea con el aprendizaje esperado y con la mayoría de los criterios utilizados; algún '
                    . 'criterio tiene una vinculación débil con actividades o evidencias. Sin modificaciones. O '
                    . 'bien: cumple el nivel 4, pero el criterio está limitado por una observación no resuelta del '
                    . 'diagnóstico (sección 2 del checklist).',
                2 => 'Alineación incompleta: uno o más criterios sin actividades o evidencias asociadas, o se usan '
                    . 'menos de 3 criterios cuando el plan formativo declara más.',
                1 => 'No hay alineación, o se modifican, omiten o inventan el aprendizaje esperado o los criterios.']],
            'r3' => ['nombre' => 'Calidad de la experiencia de aprendizaje', 'niveles' => [
                4 => 'Nombre claro, breve y representativo del desempeño; propósito preciso; modalidad y estrategia '
                    . 'coherentes con la sección 7 de la ficha o con un cambio justificado.',
                3 => 'Experiencia bien formulada; uno de sus elementos (nombre, propósito, modalidad o estrategia) '
                    . 'requiere una mejora menor. O bien: cumple el nivel 4, pero el criterio está limitado por una '
                    . 'observación no resuelta del diagnóstico (secciones 3, 5 y 7 del checklist).',
                2 => 'Dos o más elementos presentan debilidades de claridad o pertinencia, o la modalidad o '
                    . 'estrategia difieren de la ficha sin justificación.',
                1 => 'Experiencia mal formulada o no pertinente para el aprendizaje esperado.']],
            'r4' => ['nombre' => 'Secuencia instruccional', 'niveles' => [
                4 => 'Progresión clara de inicio, desarrollo y cierre; cada fase tiene alcance, actividades, '
                    . 'recursos, tiempo en minutos y evidencias parciales. El tiempo total no supera el asignado '
                    . '(2.3).',
                3 => 'Secuencia clara y completa, con ajustes menores en actividades, tiempos o evidencias; tiempo '
                    . 'total dentro de lo asignado. O bien: cumple el nivel 4, pero el criterio está limitado por '
                    . 'una observación no resuelta del diagnóstico (sección 2 (ítem de tiempo) y sección 4 del '
                    . 'checklist).',
                2 => 'Desajustes en la progresión, fases incompletas o tiempo total que supera el asignado.',
                1 => 'Sin progresión clara o secuencia incoherente con el aprendizaje esperado.']],
            'r5' => ['nombre' => 'Materiales instruccionales', 'niveles' => [
                4 => 'Materiales definidos por fase (alcance, contenidos, formato y uso), pertinentes, alineados con '
                    . 'las evidencias y factibles con los recursos de la ficha.',
                3 => 'Materiales pertinentes y alineados, con mejoras menores en su definición o factibilidad. O '
                    . 'bien: cumple el nivel 4, pero el criterio está limitado por una observación no resuelta del '
                    . 'diagnóstico (sección 6 del checklist).',
                2 => 'Materiales genéricos, poco claros o sin vínculo explícito con las evidencias.',
                1 => 'Materiales no pertinentes, desconectados de la secuencia o ausentes.']],
            'r6' => ['nombre' => 'Plan de evaluación', 'niveles' => [
                4 => 'Cada criterio utilizado tiene evidencia, instrumento, indicador de logro, momento de '
                    . 'evaluación y responsable, con relaciones claras entre ellos.',
                3 => 'Plan coherente; algún elemento requiere un ajuste menor sin afectar la cobertura de los '
                    . 'criterios. O bien: cumple el nivel 4, pero el criterio está limitado por una observación no '
                    . 'resuelta del diagnóstico (secciones 2 y 7 del checklist).',
                2 => 'Relaciones incompletas o poco claras: uno o más criterios sin evidencia, instrumento o '
                    . 'indicador.',
                1 => 'Plan desalineado con el aprendizaje esperado y los criterios, o ausente.']],
            'r7' => ['nombre' => 'Instrumentos evaluativos', 'niveles' => [
                4 => 'Un instrumento por cada evidencia del plan, coherente con su tipo, con indicadores, escala y '
                    . 'pautas de uso; redactado y listo para aplicar.',
                3 => 'Instrumentos coherentes con el plan, con ajustes menores de redacción o escala. O bien: cumple '
                    . 'el nivel 4, pero el criterio está limitado por una observación no resuelta del diagnóstico '
                    . '(sección 7 del checklist).',
                2 => 'Instrumentos poco claros o incompletos (sin escala, indicadores o pautas), o alguna evidencia '
                    . 'del plan sin instrumento.',
                1 => 'Instrumentos no válidos para las evidencias, mal formulados o ausentes.']],
            'r8' => ['nombre' => 'Factibilidad de implementación', 'niveles' => [
                4 => 'Implementable con los recursos, el tiempo y el contexto registrados en la ficha, y aborda los '
                    . 'riesgos identificados en la sección 8.',
                3 => 'Viable con ajustes menores (por ejemplo, reorganizar espacios o turnos) que no requieren '
                    . 'recursos adicionales relevantes. O bien: cumple el nivel 4, pero el criterio está limitado '
                    . 'por una observación no resuelta del diagnóstico (sección 2 (ítem de tiempo) y secciones 5, 6 '
                    . 'y 8 del checklist).',
                2 => 'Factibilidad limitada: requiere recursos, tiempo o condiciones no disponibles según la ficha, '
                    . 'sin proponer alternativas.',
                1 => 'No viable en el contexto real registrado en la ficha.']],
            'r9' => ['nombre' => 'Calidad formal del documento', 'niveles' => [
                4 => 'Documento único, ordenado y sin repeticiones, con lenguaje claro e inclusivo y terminología de '
                    . 'formación por competencias; listo para uso institucional.',
                3 => 'Presentación adecuada, con mejoras menores de formato o redacción.',
                2 => 'Problemas de claridad, organización o terminología que dificultan su uso.',
                1 => 'Documento deficiente, incompleto o poco usable.']],
        ];
    }

    /**
     * Criterios de la rubrica vinculados a cada item del checklist de la Etapa 0, para el limite por observaciones no
     * resueltas (Etapa 8 del Prompt Maestro, ajuste del cliente del 30-10-2026). Por seccion, salvo el item de tiempo
     * (c2_4: criterios 4 y 8, en lugar de 2 y 6) y la seccion 7, que va por item. El criterio 9 no se limita. Funcion pura.
     *
     * @return array id de item => ids de criterio.
     */
    public static function rubric_links(): array {
        $porseccion = [1 => ['r1'], 2 => ['r2', 'r6'], 3 => ['r3'], 4 => ['r1', 'r4'], 5 => ['r3', 'r8'],
            6 => ['r5', 'r8'], 8 => ['r8'], 9 => ['r1']];
        // Seccion 7: modalidad o estrategia metodologica → 3; evidencia → 6; instrumento → 7.
        $poritem = ['c2_4' => ['r4', 'r8'], 'c7_1' => ['r3'], 'c7_2' => ['r3'], 'c7_3' => ['r6'], 'c7_4' => ['r7']];
        $vinculos = [];
        foreach (exante::checklist() as $n => $seccion) {
            foreach (array_keys($seccion['items']) as $id) {
                $vinculos[$id] = $poritem[$id] ?? ($porseccion[$n] ?? []);
            }
        }
        return $vinculos;
    }

    /**
     * Criterios con puntaje maximo 3 por las observaciones no resueltas de la Etapa 0 (items ⚠ o ❌ de la validacion
     * vigente: la que habilito la Fase 2), con los items que originan cada limite. Cada criterio se limita una sola vez.
     * Funcion pura.
     *
     * @param array $validacion Informe de la validacion (['items' => [id => ['estado' => ...]]]).
     * @return array id de criterio => ids de item que lo limitan, en el orden de la rubrica.
     */
    public static function rubric_limits(array $validacion): array {
        $vinculos = self::rubric_links();
        $limites = [];
        foreach (array_keys(exante::unresolved_items($validacion['items'] ?? [])) as $id) {
            foreach ($vinculos[$id] ?? [] as $criterio) {
                $limites[$criterio][] = $id;
            }
        }
        $ordenado = [];
        foreach (array_keys(self::rubrica()) as $criterio) {
            if (isset($limites[$criterio])) {
                $ordenado[$criterio] = $limites[$criterio];
            }
        }
        return $ordenado;
    }

    /**
     * Seccion e item del checklist que originan un limite, como texto. Funcion pura.
     *
     * @param string[] $ids Items del checklist.
     * @return string Por ejemplo «Sección 2, «El tiempo para ...»; Sección 6, «...»».
     */
    public static function limit_origins(array $ids): string {
        $textos = [];
        foreach (exante::checklist() as $n => $seccion) {
            foreach ($seccion['items'] as $id => $texto) {
                if (in_array($id, $ids, true)) {
                    $textos[] = 'Sección ' . $n . ', «' . $texto . '»';
                }
            }
        }
        return implode('; ', $textos);
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
     * @param bool $conlibreria Si la libreria del rol viene en el pedido.
     * @param bool $conbusqueda Si el modelo puede buscar en la web.
     * @param array $validacion Informe de la validacion de la ficha (items con estado y observacion), para que la
     *        evaluacion sea consistente con la calidad del diagnostico.
     * @return string
     */
    public static function build_eval_prompt(string $diseno, string $ficha, array $adjuntos, bool $conlibreria = false,
            bool $conbusqueda = false, array $validacion = []): string {
        $rubrica = [];
        foreach (self::rubrica() as $id => $c) {
            $rubrica[] = $id . '. ' . $c['nombre'];
            foreach ($c['niveles'] as $nivel => $texto) {
                $rubrica[] = '   Nivel ' . $nivel . ' (' . $nivel . ' pts): ' . $texto;
            }
        }
        // Limite por observaciones no resueltas: lo calcula el codigo con el vinculo seccion → criterio del cliente.
        $limites = self::rubric_limits($validacion);
        $limitados = [];
        foreach ($limites as $criterio => $items) {
            $limitados[] = '- ' . $criterio . ' (' . self::rubrica()[$criterio]['nombre'] . '): ' . self::limit_origins($items);
        }
        $limite = $limitados
            ? "En este diseño, los criterios con puntaje máximo 3 son:\n" . implode("\n", $limitados) . "\n"
                . 'El límite es un máximo, no un puntaje asignado: si el diseño merece un puntaje menor, asigna el que '
                . 'corresponda según la rúbrica. En la justificación de cada criterio limitado indica la sección y el ítem '
                . 'del checklist que originan el límite. Puntaje máximo alcanzable: ' . (36 - count($limites)) . ' de 36.'
            : 'En este diseño ningún criterio queda limitado: el puntaje máximo alcanzable es 36.';
        return implode("\n", [
            'Eres el ' . exante::ROL . ': un asistente experto en diseño instruccional con enfoque de competencias. Tu '
                . 'comportamiento es estricto, estructurado y orientado a asegurar calidad.',
            '',
            'TAREA: ETAPA 8, EVALUACIÓN DEL DISEÑO. Evalúa cada criterio (1 a 4) utilizando la rúbrica de validación '
                . 'técnica del diseño: asigna a cada criterio el puntaje del nivel cuyo descriptor mejor corresponda, con '
                . 'una justificación breve y específica que cite el diseño. No reescribas el diseño.',
            'Usa la ficha de diagnóstico validada como referencia para la coherencia y la factibilidad.'
                . ($adjuntos ? ' Se adjuntan ' . implode(' y ', $adjuntos) . ' para validar la alineación.' : ''),
            'Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género. Evalúas el '
                . 'diseño, no a la persona.',
            exante::NO_PEDAGOGIA,
            '',
            'LÍMITE POR OBSERVACIONES NO RESUELTAS: la evaluación del diseño debe ser consistente con la calidad del '
                . 'diagnóstico validado. Se consideran observaciones no resueltas los ítems evaluados ⚠ o ❌ en la Etapa 0 '
                . 'que no fueron corregidos en la ficha antes de iniciar la Etapa 1, según el registro de la Etapa 0. Cuando '
                . 'una sección del checklist tiene al menos una observación no resuelta, el puntaje máximo de los criterios '
                . 'de la rúbrica vinculados a esa sección es 3; cada criterio se limita una sola vez, y el criterio 9 '
                . '(Calidad formal del documento) no se limita. ' . self::pending_observations($validacion),
            $limite,
            '',
            'EVALÚA CON OJO CRÍTICO: este diseño se elaboró con apoyo de una IA, así que no lo des por bueno porque '
                . 'esté bien redactado: revísalo como lo haría una persona revisora externa. Cada puntaje 4 debe '
                . 'citar, en la justificación, la evidencia concreta del diseño que lo prueba (el elemento o fragmento '
                . 'textual); si no puedes citarla, el puntaje es 3. Antes de dar un 4, comprueba uno por uno los elementos del '
                . 'descriptor del nivel 4 contra el diseño: si falta alguno, el puntaje es 3 y la justificación dice cuál '
                . 'falta. El 4 es el nivel de excelencia: no es el valor por defecto. Aunque el total llegue a 36, entrega al menos 2 '
                . 'recomendaciones concretas de mejora.',
            '',
            implode("\n", exante::knowledge_rules($conlibreria, $conbusqueda)),
            '',
            'PAUTAS DE APLICACIÓN DE LA RÚBRICA:',
            '1. Asignación del nivel: se asigna el nivel cuyo descriptor se cumple íntegramente. Si el diseño cumple '
                . 'solo parte del nivel 4, corresponde el nivel 3 o inferior.',
            '2. Ajuste u omisión «menor»: no afecta la alineación con el aprendizaje esperado y los criterios ni la '
                . 'factibilidad, y puede corregirse sin rediseñar la experiencia. Es «importante» cuando exige rediseñar '
                . 'una fase, actividad, evidencia o instrumento.',
            '3. Criterios limitados: cuando corresponda aplicar el límite por observaciones no resueltas del '
                . 'diagnóstico, el puntaje se informa como «3 (limitado)», con la sección y el ítem del checklist que lo '
                . 'originan. Si el desempeño corresponde a un nivel inferior, se asigna ese nivel. (En el JSON el puntaje '
                . 'va como número: 3; el informe agrega «(limitado)».)',
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
     * @param array $limites Criterios con tope 3 por observaciones no resueltas (rubric_limits()): id => items.
     * @return array|null criterios (id => [puntaje, justificacion]), total, banda, limitados (ids con tope 3), limites
     *         (id => items que lo originan), maximo (36 menos los limitados), fortalezas, debilidades, recomendaciones.
     */
    public static function parse_eval(?string $texto, bool $exigeevidencia = true, array $limites = []): ?array {
        $json = exante::decode_json($texto);
        if (!$json || !isset($json['criterios']) || !is_array($json['criterios'])) {
            return null;
        }
        // Etapa 8 del Prompt Maestro (ajuste del 30-10-2026): el tope 3 lo fija el codigo, no lo que diga el modelo.
        $limites = array_intersect_key($limites, self::rubrica());
        $limitados = array_keys($limites);
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
            if (in_array($id, $limitados, true)) {
                $p = min($p, 3);
            }
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
        return ['criterios' => $criterios, 'total' => $total, 'banda' => self::band($total), 'limitados' => $limitados,
            'limites' => $limites, 'maximo' => 36 - count($limitados),
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
     * Items del checklist de la validacion de la ficha que no quedaron «cumple», para la evaluacion de la etapa 8.
     * Funcion pura.
     *
     * @param array $validacion Informe de la validacion (['items' => [id => ['estado', 'observacion']]]).
     * @return string
     */
    public static function pending_observations(array $validacion): string {
        $lineas = [];
        foreach (exante::checklist() as $n => $seccion) {
            foreach ($seccion['items'] as $id => $texto) {
                $item = $validacion['items'][$id] ?? null;
                if (!$item || ($item['estado'] ?? '') === exante::CUMPLE) {
                    continue;
                }
                $lineas[] = '- Sección ' . $n . ' (' . $seccion['titulo'] . '), ' . $id . ' «' . $texto . '»: '
                    . (($item['estado'] ?? '') === exante::NO_CUMPLE ? 'no cumple' : 'cumple parcialmente')
                    . (trim((string) ($item['observacion'] ?? '')) !== '' ? ' — ' . trim((string) $item['observacion']) : '');
            }
        }
        return $lineas
            ? "Registro de observaciones no resueltas de la Etapa 0 (ítems ⚠ o ❌ de la validación vigente de la ficha):\n"
                . implode("\n", $lineas)
            : 'La validación de la ficha (Etapa 0) no dejó observaciones no resueltas.';
    }

    /**
     * Segundos que puede tardar la generacion de una etapa antes de darla por caida.
     *
     * @param int $etapa
     * @return int
     */
    public static function max_seconds(int $etapa): int {
        return $etapa === self::CONSOLIDACION ? self::CONSOLIDACION_MAX : exante::PROCESO_MAX;
    }

    /**
     * Instrucciones de la etapa 7 con el asistente (Prompt Maestro del 30-10-2026): consolidar todos los entregables
     * «en extenso» en un documento unico, sin repetir los campos comunes. Funcion pura.
     *
     * @param string[] $etapas Numero de etapa (1 a 6) => producto aceptado (HTML).
     * @return string
     */
    public static function build_consolidation_prompt(array $etapas): string {
        $l = [];
        $l[] = 'Eres el ' . exante::ROL . ': un asistente experto en diseño instruccional con enfoque de competencias, '
            . 'especializado en formación de personas adultas en contextos de capacitación laboral.';
        $l[] = '';
        $l[] = 'TAREA: ETAPA 7: CONSOLIDACIÓN. Consolida todos los entregables anteriores "en extenso" en un documento único '
            . 'listo para descargar en Word, sin repetir aquellos campos que puedan ser comunes. El documento tiene estas '
            . 'partes, en este orden: Análisis, Diseño, Secuencia, Materiales, Evaluación, Instrumentos.';
        $l[] = '';
        $l[] = 'REGLAS:';
        $l[] = '- "En extenso": conserva todo el contenido de cada etapa: actividades, tiempos, recursos, tablas, materiales, '
            . 'indicadores e instrumentos completos, con todos sus niveles, ítems y criterios. No resumas ni acortes.';
        $l[] = '- Sin repetir campos comunes: los datos que se repiten entre etapas (nombre de la experiencia, aprendizaje '
            . 'esperado, criterios de evaluación, contexto, modalidad, tiempos, participantes) van una sola vez, en una '
            . 'sección inicial de datos generales; en las partes siguientes no los vuelvas a transcribir.';
        $l[] = '- No agregues contenido nuevo ni cambies decisiones de diseño: solo reorganizas lo que la persona aceptó.';
        $l[] = '- Transcribe el aprendizaje esperado y los criterios de evaluación tal como están.';
        $l[] = exante::NO_PEDAGOGIA;
        $l[] = '- Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género.';
        $l[] = '';
        $l[] = guardrails::injection_clause();
        $l[] = '';
        $l[] = 'FORMATO DE RESPUESTA: el documento completo en Markdown (títulos con #, listas y tablas Markdown), sin texto '
            . 'antes ni después del documento.';
        foreach ($etapas as $n => $html) {
            $l[] = '';
            $l[] = 'PRODUCTO ACEPTADO DE LA ETAPA ' . $n . ' (' . (self::etapas()[$n]['titulo'] ?? '') . '):';
            $l[] = guardrails::isolate_user_content((string) $html);
        }
        return implode("\n", $l);
    }

    /**
     * Si la consolidacion del asistente esta completa: no se corto por largo y conserva una parte razonable del texto de
     * las etapas (quitar lo repetido acorta, resumir acorta mucho mas). Funcion pura.
     *
     * @param string $consolidado Texto devuelto por el modelo.
     * @param string[] $etapas Productos aceptados (HTML).
     * @param string|null $fin finishReason del modelo.
     * @return bool
     */
    public static function consolidation_complete(string $consolidado, array $etapas, ?string $fin): bool {
        if ($fin !== null && strtoupper($fin) === 'MAX_TOKENS') {
            return false;
        }
        $largo = static function(string $t): int {
            return \core_text::strlen(trim(preg_replace('/\s+/u', ' ', strip_tags($t))));
        };
        $original = 0;
        foreach ($etapas as $html) {
            $original += $largo((string) $html);
        }
        return $original === 0 || $largo($consolidado) >= self::CONSOLIDACION_MIN * $original;
    }

    /**
     * Fuentes web que uso el modelo, como lista al final de la etapa (para que la persona las revise). Funcion pura.
     *
     * @param array $fuentes [['title' => string, 'uri' => string], ...] (gemini_client::extract_sources()).
     * @return string Markdown; '' sin fuentes.
     */
    public static function sources_markdown(array $fuentes): string {
        $lineas = [];
        foreach (array_slice($fuentes, 0, 8) as $f) {
            $uri = (string) ($f['uri'] ?? '');
            if (!preg_match('#^https?://#i', $uri)) {
                continue;
            }
            $titulo = trim(str_replace(['[', ']'], '', (string) ($f['title'] ?? ''))) ?: $uri;
            $lineas[] = '- [' . $titulo . '](' . $uri . ')';
        }
        return $lineas ? "\n\n**Fuentes web consultadas para la especialidad técnica:**\n\n" . implode("\n", $lineas) : '';
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
        // Limites por observaciones no resueltas (desde v1.20.14 con su origen; las evaluaciones anteriores no lo traen).
        $limites = (array) ($informe['limites'] ?? array_fill_keys((array) ($informe['limitados'] ?? []), []));
        $html = '<table class="table table-sm align-middle"><thead><tr><th>Criterio</th><th>Puntaje</th>'
            . '<th>Nivel</th><th>Justificación</th></tr></thead><tbody>';
        foreach (self::rubrica() as $id => $c) {
            $p = (int) ($informe['criterios'][$id]['puntaje'] ?? 0);
            $limite = isset($limites[$id]) ? '<br><em>Puntaje máximo 3 por observaciones no resueltas'
                . ($limites[$id] ? ': ' . $e(self::limit_origins((array) $limites[$id])) : '') . '.</em>' : '';
            $nivel = (string) ($c['niveles'][$p] ?? '');
            if (!isset($limites[$id])) {
                $nivel = (string) preg_replace('/\s*O bien: cumple el nivel 4.*$/su', '', $nivel);
            }
            $puntaje = isset($limites[$id]) ? ($p === 3 ? '3 (limitado)' : $p . ' / 4 (máx. 3)') : $p . ' / 4';
            $html .= '<tr><td>' . $e(substr($id, 1) . '. ' . $c['nombre']) . '</td><td>' . $puntaje
                . '</td><td>' . $e($nivel) . '</td><td>'
                . $e($informe['criterios'][$id]['justificacion'] ?? '') . $limite . '</td></tr>';
        }
        $html .= '<tr><th>Puntaje total</th><th>' . (int) $informe['total'] . ' / 36</th><th colspan="2">'
            . $e($informe['banda']['texto']) . '</th></tr>';
        if ($limites) {
            $html .= '<tr><th>Puntaje máximo alcanzable</th><th>' . (36 - count($limites)) . ' / 36</th><th colspan="2">'
                . count($limites) . ' criterio(s) limitado(s) por observaciones no resueltas del diagnóstico</th></tr>';
        }
        $html .= '</tbody></table>';
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
            string $actual = '', string $ajustes = '', bool $conlibreria = false, bool $conbusqueda = false): string {
        $e = self::etapas()[$etapa];
        $l = [];
        $l[] = 'Eres el ' . exante::ROL . ': un asistente experto en diseño instruccional con enfoque de competencias, especializado en formación '
            . 'de personas adultas en contextos de capacitación laboral. Apoyas a personas facilitadoras en el diseño de '
            . 'experiencias de aprendizaje, utilizando como insumo principal y obligatorio una ficha de diagnóstico '
            . '(evaluación ex ante) para trabajo de campo, completada por la persona facilitadora y validada por este '
            . 'asistente en la Etapa 0 mediante el checklist de validación; el plan formativo, como documento de referencia '
            . 'obligatorio, y opcionalmente el perfil ocupacional. Tu comportamiento es estricto, estructurado y orientado a '
            . 'asegurar calidad.';
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
        $l[] = '- Usa como fuente principal la ficha de diagnóstico y el Plan Formativo. ' . exante::documents_rule($adjuntos);
        $l[] = '- No inventes aprendizajes esperados ni criterios de evaluación: transcríbelos tal como están en la ficha.';
        $l[] = '- Utiliza al menos 3 de los criterios de evaluación declarados en el plan formativo para el aprendizaje '
            . 'esperado (los que registra la ficha). Si el plan formativo declara menos de 3, utiliza todos los '
            . 'disponibles, infórmalo como vacío de información y no crees criterios nuevos.';
        $l[] = exante::NO_PEDAGOGIA;
        $l[] = '- Mantén la alineación constructiva con las etapas ya aceptadas y con los criterios de evaluación.';
        $l[] = '- Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género.';
        $l[] = '- Detecta vacíos de información y repórtalos. Propón soluciones viables sin alterar el plan formativo.';
        $l[] = '';
        foreach (exante::knowledge_rules($conlibreria, $conbusqueda) as $regla) {
            $l[] = $regla;
        }
        $l[] = '- Considera el resultado de la validación de la ficha (Etapa 0) y su registro de observaciones no '
            . 'resueltas: incorpóralas como restricciones o prioridades de diseño.';
        $l[] = '';
        $l[] = 'CONTROL DE CALIDAD: antes de cerrar la etapa verifica la coherencia con la ficha, la alineación con el '
            . 'aprendizaje esperado y los criterios de evaluación, la aplicabilidad real y la consistencia con el tiempo '
            . 'asignado a la experiencia (2.3) y con el tiempo total disponible (2.2). Termina el documento con una '
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
        if (!empty($informe['condicion'])) {
            $condiciones = [exante::APROBADO => '✔ Aprobado', exante::OBSERVACIONES => '⚠ Aprobado con observaciones',
                exante::NO_APROBADO => '❌ No aprobado'];
            $l[] = '';
            $l[] = 'RESULTADO DE LA VALIDACIÓN DE LA FICHA (ETAPA 0): ' . ($condiciones[$informe['condicion']] ?? '') . '.';
        }
        if (!empty($informe['items'])) {
            $l[] = self::pending_observations($informe);
        }
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
            if ($fila->generacion === exante::PROCESANDO && $fila->timemodified < time() - self::max_seconds((int) $fila->etapa)) {
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
    public static function confirm_consolidation(int $userid, int $courseid, string $html, string $huella,
            ?string $promptversion = null): void {
        global $DB;
        $fila = self::row($userid, $courseid, self::CONSOLIDACION);
        // promptversion queda solo si lo confirmado es la consolidacion del asistente (para mostrarlo asi).
        $DB->update_record('local_chatbot_diseno', (object) ['id' => $fila->id, 'contenido' => $html, 'huella' => $huella,
            'estado' => self::ACEPTADO, 'promptversion' => $promptversion, 'timeaccepted' => time(), 'timemodified' => time()]);
    }

    /**
     * La consolidacion que se muestra y se confirma: la guardada (del asistente o ya confirmada) si corresponde a las
     * etapas de hoy; si no, la completa por codigo. Funcion pura.
     *
     * @param \stdClass|null $fila Fila de la etapa 7.
     * @param string[] $contenidos Productos de las etapas 1 a 6 (HTML).
     * @param string $huella Huella de las etapas de hoy (stages_fingerprint()).
     * @return array [html, si es del asistente (bool)].
     */
    public static function consolidation_shown(?\stdClass $fila, array $contenidos, string $huella): array {
        if ($fila && trim((string) $fila->contenido) !== '' && (string) $fila->huella === $huella) {
            return [(string) $fila->contenido, !empty($fila->promptversion)];
        }
        return [self::consolidate($contenidos), false];
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
        // Resultado de la Etapa 0 para la Etapa 1 (Prompt Maestro del 30-10-2026).
        $informe['condicion'] = (string) $val->condicion;

        if ((int) $fila->etapa === self::EVALUACION) {
            return self::evaluate($fila, $ficha, (int) $val->fichaid, $informe);
        }
        if ((int) $fila->etapa === self::CONSOLIDACION) {
            return self::consolidate_ai($fila);
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
        // Libreria del rol para el enfoque de competencias y busqueda web para la especialidad tecnica (30-10-2026).
        $libreria = exante::role_library('e' . (int) $fila->etapa, (string) ($ficha['nivel'] ?? ''));
        $buscar = exante::web_search_available();
        $prompt = self::build_prompt((int) $fila->etapa, pseudonymizer::scrub(exante::to_text($ficha)), $informe,
            $previas, $etiquetas, $actual, (string) $fila->ajustes, $libreria !== '', $buscar);
        $contenido = [['role' => 'user', 'parts' => exante::request_parts($prompt, $partes, $libreria,
            ux::on('libreriaruta'))]];

        $modelo = ai\provider_factory::get_configured_model();
        $resultado = exante::generate_steady($contenido, 240, $modelo, $buscar);
        if (isset($resultado['error'])) {
            return [exante::ERROR, ['error' => 'servicio']];
        }
        $markdown = self::clean_markdown(gemini_client::extract_text($resultado));
        if ($markdown === '') {
            return [exante::ERROR, ['error' => 'ilegible']];
        }
        $markdown .= self::sources_markdown(gemini_client::extract_sources($resultado));
        // Una propuesta nueva o ajustada vuelve a borrador: la persona la revisa y la acepta de nuevo.
        return [exante::LISTO, ['contenido' => clean_text(markdown_to_html($markdown), FORMAT_HTML),
            'estado' => self::BORRADOR, 'modelo' => (string) $modelo, 'promptversion' => self::PROMPT_VERSION,
            'error' => null]];
    }

    /**
     * Etapa 7 con el asistente: consolida las seis etapas aceptadas en extenso, sin repetir campos comunes. Si la
     * respuesta llega cortada o mucho mas corta que las etapas, no se usa: queda la consolidacion completa por codigo.
     *
     * @param \stdClass $fila Fila de la etapa 7.
     * @return array [estado de la generacion, campos a guardar].
     */
    private static function consolidate_ai(\stdClass $fila): array {
        $filas = self::get_by_ficha((int) $fila->fichaid);
        $etapas = [];
        foreach (array_keys(self::etapas()) as $n) {
            if (empty($filas[$n]) || $filas[$n]->estado !== self::ACEPTADO || trim((string) $filas[$n]->contenido) === '') {
                return [exante::ERROR, ['error' => 'sinetapas']];
            }
            $etapas[$n] = (string) $filas[$n]->contenido;
        }
        $huella = self::stages_fingerprint($filas);
        $contenido = [['role' => 'user', 'parts' => [['text' => self::build_consolidation_prompt($etapas)]]]];
        $modelo = ai\provider_factory::get_configured_model();
        $resultado = exante::generate_steady($contenido, 600, $modelo);
        if (isset($resultado['error'])) {
            return [exante::ERROR, ['error' => 'servicio']];
        }
        $markdown = self::clean_markdown(gemini_client::extract_text($resultado));
        $fin = $resultado['candidates'][0]['finishReason'] ?? null;
        if ($markdown === '' || !self::consolidation_complete($markdown, $etapas, $fin)) {
            return [exante::ERROR, ['error' => 'incompleta']];
        }
        // Queda como borrador: la persona la revisa y la confirma (o confirma la consolidacion completa por codigo).
        return [exante::LISTO, ['contenido' => clean_text(markdown_to_html($markdown), FORMAT_HTML),
            'estado' => self::BORRADOR, 'huella' => $huella, 'modelo' => (string) $modelo,
            'promptversion' => self::PROMPT_VERSION, 'error' => null]];
    }

    /**
     * Etapa 8: el modelo aplica la rubrica al diseno consolidado confirmado.
     *
     * @param \stdClass $fila
     * @param array $ficha
     * @param int $fichaid
     * @param array $validacion Informe de la validacion de la ficha.
     * @return array [estado de la generacion, campos a guardar].
     */
    private static function evaluate(\stdClass $fila, array $ficha, int $fichaid, array $validacion = []): array {
        global $DB;
        $c = $DB->get_record('local_chatbot_diseno', ['fichaid' => $fila->fichaid, 'etapa' => self::CONSOLIDACION]);
        if (!$c || $c->estado !== self::ACEPTADO || !$c->contenido) {
            return [exante::ERROR, ['error' => 'sinconsolidado']];
        }
        [$partes, $etiquetas] = exante::attachment_parts($fichaid);
        $libreria = exante::role_library('e8', (string) ($ficha['nivel'] ?? ''));
        $buscar = exante::web_search_available();
        $prompt = self::build_eval_prompt(html_to_text((string) $c->contenido, 0, false),
            pseudonymizer::scrub(exante::to_text($ficha)), $etiquetas, $libreria !== '', $buscar, $validacion);
        $contenido = [['role' => 'user', 'parts' => exante::request_parts($prompt, $partes, $libreria,
            ux::on('libreriaruta'))]];
        $modelo = ai\provider_factory::get_configured_model();
        $informe = null;
        $primero = null;
        // Un segundo intento solo si la respuesta no se pudo leer: la rubrica va entera o no va.
        for ($intento = 0; $intento < 2 && $informe === null; $intento++) {
            // El segundo intento va sin busqueda web, por si la respuesta con fuentes no vino como JSON limpio.
            $resultado = exante::generate_steady($contenido, 240, $modelo, $buscar && $intento === 0);
            if (isset($resultado['error'])) {
                if ($primero !== null) {
                    break;
                }
                return [exante::ERROR, ['error' => 'servicio']];
            }
            $informe = self::parse_eval(gemini_client::extract_text($resultado), ux::on('iaapoyo'),
                self::rubric_limits($validacion));
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
