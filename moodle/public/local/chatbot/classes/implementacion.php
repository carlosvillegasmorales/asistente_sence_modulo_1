<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/kb_router.php');

/**
 * Modulo 3, Fase 1: ficha de registro de la implementacion (evaluacion durante) y su validacion con el checklist.
 *
 * Insumos del Prompt Maestro del Asistente de Aseguramiento de la Calidad de los Aprendizajes (Interfases, 06-11-2026):
 * el diseño instruccional validado de la experiencia, que la persona elige de sus diseños guardados en el Modulo 2 (junto
 * con su plan formativo y, si existe, el perfil ocupacional: no se vuelven a cargar), y la ficha de registro de la
 * implementacion, que completa en linea y que el asistente valida en la Etapa 0 con el checklist. Solo con «Aprobado» o
 * «Aprobado con observaciones» se habilita la Fase 2.
 *
 * El contenido de la ficha y del checklist es el de los documentos del cliente, textual («Ficha de registro de la
 * implementacion · Evaluacion durante», su guia de llenado y el «Checklist de validacion»): es instrumento, no texto de la
 * interfaz, y por eso vive aqui y no en los archivos de idioma.
 *
 * La validacion revisa un documento de trabajo, no a la persona: no es una calificacion y no toca el libro de
 * calificaciones. La condicion final no queda en manos del modelo: decide() aplica la regla de decision del checklist sobre
 * los items. Lo que es aritmetico (presentes, suma por nivel, logro %, tiempos del diseño) lo calcula el codigo y manda
 * sobre lo que diga el modelo (system_checks()).
 *
 * Todo lo que no lleva prefijo de BD es funcion pura y lo ejercita cli/verificar_implementacion.php.
 */
class implementacion {

    /** @var string Version del prompt de validacion: se guarda con cada informe (RU-PRIV-006). */
    const PROMPT_VERSION = 'M3-F1-checklist-v1';

    /** @var string Rol del asistente en el Modulo 3. */
    const ROL = 'Asistente de Aseguramiento de la Calidad de los Aprendizajes';

    /** Tipos de campo (los mismos de la ficha del Modulo 2, mas el porcentaje). */
    const T_TEXTO = 'texto';
    const T_PARRAFO = 'parrafo';
    const T_NUMERO = 'numero';
    const T_UNA = 'una';
    const T_PORCENTAJE = 'porcentaje';

    /** Evaluacion de un item del checklist y condicion de la ficha: son los mismos valores del Modulo 2. */
    const CUMPLE = 'cumple';
    const PARCIAL = 'parcial';
    const NO_CUMPLE = 'no_cumple';
    const APROBADO = 'aprobado';
    const OBSERVACIONES = 'observaciones';
    const NO_APROBADO = 'no_aprobado';

    /** Estado de una validacion en curso. */
    const PENDIENTE = 'pendiente';
    const PROCESANDO = 'procesando';
    const LISTO = 'listo';
    const ERROR = 'error';

    /** Resultado de borrar una evaluacion. */
    const DELETED = 'borrado';
    const MISSING = 'inexistente';
    const VALIDATING = 'validando';

    /** @var int Una validacion en proceso por mas de esto (segundos) se da por caida. */
    const PROCESO_MAX = 360;

    /** @var float Temperatura de la validacion: baja, para que la misma ficha de el mismo resultado. */
    const TEMPERATURA = 0.1;

    /** @var int Largo maximo de cada tipo de texto. */
    const LARGO_TEXTO = 255;
    const LARGO_PARRAFO = 4000;

    /** @var int Casillas de criterio que la ficha muestra de partida y tope (el formulario del cliente trae 15 filas). */
    const CRITERIOS_MIN = 3;
    const CRITERIOS_MAX = 15;

    /** @var int Filas de la subseccion 4.2 (observaciones sobre participantes o subgrupos). */
    const OBSERVACIONES_FILAS = 5;

    /** @var float Diferencia aceptada entre el logro declarado y el calculado (el redondeo de la persona). */
    const LOGRO_TOLERANCIA = 1.0;

    /** @var string[] Niveles de desempeño de la tabla 4.1: clave de campo => nombre (guia de llenado). */
    const NIVELES = ['ins' => 'Insuficiente', 'bas' => 'Básico', 'ade' => 'Adecuado', 'ava' => 'Avanzado'];

    /**
     * @var string[] Items marcados CRÍTICO en el checklist del cliente: plan, modulo, experiencia y tiempos contra el
     * diseño (1.2 y 1.4), aprendizaje esperado y criterios (2.1 y 2.2), fila, evidencias y suma por nivel en la tabla 4.1
     * (4.1 a 4.3) y coherencia global (8.2). Un ❌, o un ⚠ que no sea una diferencia de forma, deja la ficha «No aprobado».
     */
    const CRITICOS = ['c1_2', 'c1_4', 'c2_1', 'c2_2', 'c4_1', 'c4_2', 'c4_3', 'c8_2'];

    /** @var string[] Items que resuelve el codigo con los datos de la ficha (system_checks()), no el modelo. */
    const POR_CALCULO = ['c1_3', 'c1_4', 'c4_1', 'c4_3', 'c4_4'];

    /** @var string Regla de gobernanza del Prompt Maestro, comun a todas las etapas. */
    const NO_PEDAGOGIA = '- Nunca utilices conceptos relativos a «pedagógico» o «pedagogía» en tus respuestas: sustitúyelos '
        . 'por conceptos relativos a formación de personas adultas o formación laboral.';

    /** @var string Respuesta textual del Prompt Maestro cuando no hay diseño seleccionado o ficha enviada. */
    const REGLA_CRITICA = 'Para utilizar este asistente, primero debes seleccionar el diseño de la experiencia de aprendizaje '
        . 'implementada, desde tus diseños guardados en el Asistente de Diseño de Experiencias de Aprendizaje, y completar en '
        . 'línea la ficha de registro de la implementación. La validación de la ficha se realizará en la Etapa 0 mediante el '
        . 'checklist de validación. Este paso es obligatorio dentro del proceso.';

    // ------------------------------------------------------------------------------------------
    // La ficha (documento del cliente, textual).
    // ------------------------------------------------------------------------------------------

    /**
     * Escala de valoracion de las secciones 3 y 5.
     *
     * @return string[] clave => texto.
     */
    public static function scale(): array {
        return ['1' => '1 – Muy bajo', '2' => '2 – Bajo', '3' => '3 – Alto', '4' => '4 – Muy alto'];
    }

    /**
     * Preguntas valoradas de las secciones 3 y 5 con sus descriptores (guia de llenado, textuales).
     *
     * @param int $seccion 3 o 5.
     * @return array id de la valoracion => ['num', 'label', 'comentario' (ayuda), 'descriptores' (4..1 => texto)].
     */
    private static function rated(int $seccion): array {
        if ($seccion === 3) {
            return [
                'val31' => ['3.1', 'Las actividades realizadas se ajustaron a la secuencia instruccional diseñada',
                    'Actividades no realizadas, modificadas, sustituidas o incorporadas, y su motivo.',
                    [4 => 'todas las actividades planificadas se realizaron en el orden previsto y con los tiempos asignados.',
                        3 => 'la mayoría de las actividades se realizaron, con ajustes menores en tiempos u orden.',
                        2 => 'varias actividades no se realizaron o se modificaron significativamente, y el orden de la secuencia cambió.',
                        1 => 'la mayoría de las actividades no se realizaron o se reemplazaron por actividades no previstas.']],
                'val32' => ['3.2', 'Se trabajaron efectivamente los aprendizajes esperados previstos para la experiencia',
                    'Aspectos del aprendizaje esperado no abordados o abordados parcialmente, y su motivo.',
                    [4 => 'el aprendizaje esperado se trabajó en profundidad, con actividades pertinentes y tiempo suficiente.',
                        3 => 'el aprendizaje esperado se trabajó, aunque con menor profundidad o tiempo del previsto.',
                        2 => 'el aprendizaje esperado se abordó de manera superficial o parcial.',
                        1 => 'el aprendizaje esperado no se trabajó o no se alcanzó a abordar.']],
                'val33' => ['3.3', 'Se aplicaron las estrategias didácticas planificadas',
                    'Si la estrategia se modificó o sustituyó, indique cuál se usó y por qué.',
                    [4 => 'la estrategia se aplicó completamente, con todas sus fases y características.',
                        3 => 'la estrategia se aplicó con ajustes menores.',
                        2 => 'la estrategia se aplicó parcialmente o se simplificó significativamente.',
                        1 => 'la estrategia no se aplicó o se reemplazó por otra.']],
                'val34' => ['3.4', 'Se aplicó el plan de evaluación diseñado (momentos, evidencias e instrumentos)',
                    'Momentos, evidencias o instrumentos no aplicados, modificados o incorporados, y su motivo.',
                    [4 => 'todos los momentos, evidencias e instrumentos se aplicaron según lo planificado.',
                        3 => 'la mayoría de los momentos e instrumentos se aplicaron, con ajustes menores.',
                        2 => 'algunos momentos o instrumentos no se aplicaron o se aplicaron de manera simplificada.',
                        1 => 'el plan de evaluación no se aplicó o se reemplazó por estrategias no previstas.']],
            ];
        }
        return [
            'val51' => ['5.1', 'Adecuación del espacio físico', 'Comente brevemente.',
                [4 => 'el espacio fue completamente adecuado, sin problemas de tamaño, disposición, ruido o comodidad.',
                    3 => 'el espacio fue adecuado con ajustes menores, como reorganizar el mobiliario o ventilar.',
                    2 => 'el espacio presentó limitaciones significativas, como tamaño insuficiente, ruido externo o temperatura inadecuada.',
                    1 => 'el espacio fue inadecuado y afectó significativamente el desarrollo de la experiencia.']],
            'val52' => ['5.2', 'Funcionamiento de los recursos tecnológicos', 'Comente brevemente.',
                [4 => 'todos los recursos tecnológicos funcionaron sin problemas durante la implementación.',
                    3 => 'hubo interrupciones menores que no afectaron significativamente la experiencia.',
                    2 => 'hubo fallas significativas que obligaron a ajustar actividades.',
                    1 => 'los recursos no funcionaron o fueron insuficientes, y afectaron gravemente la implementación.']],
            'val53' => ['5.3', 'Suficiencia y pertinencia de los materiales didácticos', 'Comente brevemente.',
                [4 => 'todos los materiales estuvieron disponibles y fueron suficientes y pertinentes.',
                    3 => 'los materiales estuvieron disponibles con ajustes menores, como copias adicionales o adaptación de formato.',
                    2 => 'faltaron materiales o fueron insuficientes para el número de participantes.',
                    1 => 'los materiales no estuvieron disponibles o no fueron pertinentes.']],
            'val54' => ['5.4', 'Clima de aula (participación, respeto y colaboración)', 'Describa brevemente.',
                [4 => 'participación activa y generalizada, colaboración efectiva, respeto mutuo y foco constante en la tarea.',
                    3 => 'buena participación y colaboración, con distracciones leves o participación desigual.',
                    2 => 'participación limitada, conflictos menores, distracciones frecuentes o baja colaboración.',
                    1 => 'clima negativo, conflictos significativos o desinterés generalizado.']],
        ];
    }

    /**
     * Campos de una seccion de valoraciones: cada pregunta con su comentario.
     *
     * @param int $seccion 3 o 5.
     * @return array
     */
    private static function rated_fields(int $seccion): array {
        $campos = [];
        foreach (self::rated($seccion) as $id => [$num, $label, $ayuda, $descriptores]) {
            $campos[] = ['id' => $id, 'tipo' => self::T_UNA, 'num' => $num, 'label' => $label, 'opciones' => self::scale(),
                'descriptores' => $descriptores];
            $campos[] = ['id' => 'com' . substr($id, 3), 'tipo' => self::T_PARRAFO, 'label' => 'Comentario', 'ayuda' => $ayuda,
                'de' => $id, 'num_de' => $num];
        }
        return $campos;
    }

    /**
     * Secciones de la ficha, con el texto del documento del cliente.
     *
     * Cada campo: id, tipo, num (numeracion de la ficha), label, ayuda (de la guia de llenado), opciones (clave => texto),
     * descriptores (que significa cada valoracion), subtitulo (titulo de subseccion que precede al campo), antes (nota
     * que precede al campo), adicional (criterio que se agrega con el boton) y de (el campo es el comentario de una
     * valoracion). La seccion 4 va en dos tablas (4.1 y 4.2): ver table_fields().
     *
     * @return array
     */
    public static function sections(): array {
        $criterios = [];
        for ($i = 1; $i <= self::CRITERIOS_MAX; $i++) {
            $criterio = ['id' => 'criterio' . $i, 'tipo' => self::T_PARRAFO, 'label' => 'Criterio de evaluación ' . $i];
            if ($i === 1) {
                $criterio['antes'] = 'Registre solo los criterios de evaluación que efectivamente se abordaron, tal como '
                    . 'figuran en el diseño instruccional validado para ese aprendizaje esperado.';
            }
            if ($i > self::CRITERIOS_MIN) {
                $criterio['adicional'] = true;
            }
            $criterios[] = $criterio;
        }
        return [
            1 => ['titulo' => 'Identificación general', 'campos' => [
                ['id' => 'plan', 'tipo' => self::T_TEXTO, 'label' => 'Plan formativo (nombre y código)',
                    'ayuda' => 'Nombre completo y código del plan formativo, tal como figuran en el diseño instruccional '
                        . 'validado. Ejemplo: Habilidades transversales para el trabajo, código PF-0245.'],
                ['id' => 'modulo', 'tipo' => self::T_TEXTO, 'label' => 'Módulo',
                    'ayuda' => 'Nombre y número del módulo en el que se implementó la experiencia de aprendizaje. '
                        . 'Ejemplo: Módulo 1: Comunicación para el trabajo.'],
                ['id' => 'experiencia', 'tipo' => self::T_TEXTO, 'label' => 'Nombre de la experiencia de aprendizaje',
                    'ayuda' => 'Nombre de la experiencia implementada, tal como figura en el diseño instruccional validado. '
                        . 'Ejemplo: Elaboración de correos profesionales con criterios de calidad.'],
                ['id' => 'facilitador', 'tipo' => self::T_TEXTO, 'label' => 'Nombre de la persona facilitadora',
                    'ayuda' => 'Nombre completo de la persona facilitadora responsable de la implementación.'],
                ['id' => 'fechas', 'tipo' => self::T_TEXTO, 'label' => 'Fecha(s)',
                    'ayuda' => 'Fecha o fechas en que se implementó la experiencia. Ejemplo: 22 de marzo de 2026.'],
                ['id' => 'horario', 'tipo' => self::T_TEXTO, 'label' => 'Horario',
                    'ayuda' => 'Hora de inicio y término de la implementación. Ejemplo: 09:00 a 12:00 h.'],
                ['id' => 'inscritos', 'tipo' => self::T_NUMERO, 'label' => 'N.º de participantes inscritos',
                    'ayuda' => 'Total de participantes inscritos en el curso. Ejemplo: 24 participantes.'],
                ['id' => 'presentes', 'tipo' => self::T_NUMERO, 'label' => 'N.º de participantes presentes',
                    'ayuda' => 'Total de participantes que asistieron a la implementación. Es la base para calcular el '
                        . 'logro (%) en la sección 4. Ejemplo: 22 participantes.'],
                ['id' => 'tiempo_total', 'tipo' => self::T_NUMERO, 'num' => '1.1',
                    'subtitulo' => '1.1 Tiempos de la experiencia de aprendizaje',
                    'antes' => 'Registre los tres tiempos en minutos. El asistente los utiliza para calcular el tiempo '
                        . 'restante disponible para acciones remediales (tiempo total disponible − tiempo efectivamente '
                        . 'utilizado).',
                    'label' => 'Tiempo total disponible para el desarrollo del aprendizaje (minutos)',
                    'ayuda' => 'Tiempo total asignado al logro del aprendizaje esperado, según el diseño instruccional '
                        . 'validado (1 hora equivale a 60 minutos). Ejemplo: 360 minutos.'],
                ['id' => 'tiempo_asignado', 'tipo' => self::T_NUMERO,
                    'nombre' => '1.1 Tiempo asignado a la experiencia de aprendizaje (según diseño)',
                    'label' => 'Tiempo asignado a la experiencia de aprendizaje (según diseño) (minutos)',
                    'ayuda' => 'Tiempo que el diseño instruccional definió para la experiencia implementada. '
                        . 'Ejemplo: 180 minutos.'],
                ['id' => 'tiempo_utilizado', 'tipo' => self::T_NUMERO,
                    'nombre' => '1.1 Tiempo efectivamente utilizado en la implementación',
                    'label' => 'Tiempo efectivamente utilizado en la implementación (minutos)',
                    'ayuda' => 'Tiempo real que tomó la experiencia, que puede ser mayor o menor que el asignado. Ejemplo: '
                        . '180 minutos; coincidió con el tiempo asignado, aunque cambió la distribución entre actividades.'],
            ]],
            2 => ['titulo' => 'Aprendizaje esperado y criterios de evaluación trabajados', 'campos' => array_merge([
                ['id' => 'aprendizaje', 'tipo' => self::T_PARRAFO, 'num' => '2.1',
                    'subtitulo' => '2.1 Aprendizaje esperado y criterios de evaluación',
                    'antes' => 'Transcriba el aprendizaje esperado y los criterios de evaluación trabajados durante la '
                        . 'implementación, tal como figuran en el diseño instruccional validado. Deje en blanco las filas '
                        . 'que no utilice.',
                    'label' => 'Aprendizaje esperado',
                    'ayuda' => 'Transcriba el aprendizaje esperado tal como está formulado en el diseño instruccional validado.'],
                ], $criterios, [
                ['id' => 'no_trabajados', 'tipo' => self::T_PARRAFO, 'num' => '2.2',
                    'subtitulo' => '2.2 Criterios previstos para la experiencia que no se trabajaron (obligatorio)',
                    'label' => 'Criterios no trabajados y motivo',
                    'ayuda' => 'Indique cada criterio previsto en el diseño para esta experiencia que no se trabajó u '
                        . 'observó, y el motivo (tiempo, características del grupo, recursos u otro). Si se trabajaron todos '
                        . 'los criterios previstos, indíquelo.'],
            ])],
            3 => ['titulo' => 'Coherencia entre diseño y ejecución',
                'nota' => 'Escala: valore cada aspecto según lo que realmente ocurrió: 1 = Muy bajo · 2 = Bajo · 3 = Alto · '
                    . '4 = Muy alto. Cambios en la implementación: registre todo cambio respecto del diseño (omisiones, '
                    . 'modificaciones, sustituciones o incorporaciones) y su motivo. El comentario es obligatorio si hubo '
                    . 'cambios o si valoró 1 o 2. Los cambios no se consideran errores: son decisiones de la persona '
                    . 'facilitadora que el asistente analiza como hallazgos para fundamentar la mejora o el rediseño.',
                'campos' => self::rated_fields(3)],
            4 => ['titulo' => 'Desempeño de las personas participantes', 'campos' => []],
            5 => ['titulo' => 'Condiciones del entorno y recursos',
                'nota' => 'Escala: valore cada aspecto según las condiciones reales durante la implementación: 1 = Muy bajo · '
                    . '2 = Bajo · 3 = Alto · 4 = Muy alto.',
                'campos' => self::rated_fields(5)],
            6 => ['titulo' => 'Juicio fundado sobre la experiencia implementada', 'campos' => [
                ['id' => 'val61', 'tipo' => self::T_UNA, 'num' => '6.1',
                    'label' => 'En qué medida la experiencia, tal como se implementó, permitió avanzar en los aprendizajes '
                        . 'esperados',
                    'opciones' => ['muy_alto' => 'Muy alto', 'alto' => 'Alto', 'medio' => 'Medio', 'bajo' => 'Bajo'],
                    'descriptores' => ['muy_alto' => 'avances significativos; la mayoría de las personas participantes alcanzó '
                        . 'niveles Adecuado o Avanzado en los criterios trabajados.',
                        'alto' => 'avances importantes, aunque con algunos aspectos que requieren refuerzo.',
                        'medio' => 'avances parciales; varias personas requieren apoyo adicional o más tiempo para alcanzar '
                            . 'el nivel esperado.',
                        'bajo' => 'sin avances significativos; se requiere replantear el diseño o la implementación.']],
                ['id' => 'fund61', 'tipo' => self::T_PARRAFO, 'nombre' => '6.1 Fundamentación', 'label' => 'Fundamentación (obligatorio)',
                    'ayuda' => 'Fundamente su juicio con ejemplos y datos concretos.'],
                ['id' => 'pert62', 'tipo' => self::T_PARRAFO, 'num' => '6.2',
                    'label' => 'Aspectos del diseño y de la implementación que resultaron especialmente pertinentes (obligatorio)',
                    'ayuda' => 'Identifique qué aspectos del diseño (actividades, estrategias, instrumentos, recursos) y de la '
                        . 'implementación (organización del grupo, uso de recursos) resultaron especialmente pertinentes para '
                        . 'este grupo y contexto, y por qué.'],
                ['id' => 'dific63', 'tipo' => self::T_PARRAFO, 'num' => '6.3',
                    'label' => 'Elementos del diseño o de la implementación que generaron mayores dificultades (obligatorio)',
                    'ayuda' => 'Señale qué elementos obstaculizaron la experiencia o el logro del aprendizaje esperado: '
                        . 'tiempos, complejidad de las actividades, claridad de las instrucciones, recursos u organización '
                        . 'del grupo.'],
            ]],
        ];
    }

    /**
     * Campos de las tablas 4.1 (una fila por criterio de la seccion 2) y 4.2 (observaciones opcionales).
     *
     * @return array clave => tipo.
     */
    public static function table_fields(): array {
        $campos = [];
        for ($i = 1; $i <= self::CRITERIOS_MAX; $i++) {
            $campos['ev' . $i] = self::T_PARRAFO;
            foreach (array_keys(self::NIVELES) as $nivel) {
                $campos[$nivel . $i] = self::T_NUMERO;
            }
            $campos['lg' . $i] = self::T_PORCENTAJE;
            $campos['cm' . $i] = self::T_PARRAFO;
        }
        for ($j = 1; $j <= self::OBSERVACIONES_FILAS; $j++) {
            $campos['ob' . $j . '_quien'] = self::T_TEXTO;
            $campos['ob' . $j . '_situacion'] = self::T_PARRAFO;
            $campos['ob' . $j . '_evidencia'] = self::T_PARRAFO;
            $campos['ob' . $j . '_comentario'] = self::T_PARRAFO;
        }
        return $campos;
    }

    /**
     * Descripcion de los niveles de desempeño (guia de llenado, textual).
     *
     * @return string[] clave de campo => descripcion.
     */
    public static function level_help(): array {
        return [
            'ins' => 'el desempeño no alcanza el estándar mínimo; no se evidencia logro del criterio.',
            'bas' => 'el desempeño alcanza parcialmente el estándar; hay logro inicial, con errores significativos o apoyo '
                . 'permanente.',
            'ade' => 'el desempeño alcanza el estándar esperado; se evidencia logro del criterio con autonomía.',
            'ava' => 'el desempeño supera el estándar esperado; se evidencia logro con transferencia o profundización.',
        ];
    }

    /**
     * Tipos de cambio de la implementacion (guia de llenado, textual).
     *
     * @return string[] tipo => descripcion.
     */
    public static function change_types(): array {
        return [
            'Omisión' => 'una actividad, estrategia, momento evaluativo o instrumento previsto no se realizó. Ejemplo: no se '
                . 'aplicó la lista de cotejo de autoevaluación por falta de tiempo.',
            'Modificación' => 'se realizó lo previsto, pero con cambios en su duración, orden, organización o alcance. '
                . 'Ejemplo: el análisis de casos en grupos se redujo de 40 a 25 minutos.',
            'Sustitución' => 'lo previsto se reemplazó por otra actividad, estrategia o instrumento. Ejemplo: el estudio de '
                . 'caso en grupos se reemplazó por una demostración guiada.',
            'Incorporación' => 'se agregó una actividad, recurso o instrumento no previsto en el diseño. Ejemplo: se '
                . 'incorporó un ejercicio breve de corrección entre pares antes de entregar el producto.',
        ];
    }

    // ------------------------------------------------------------------------------------------
    // El checklist (documento del cliente, textual).
    // ------------------------------------------------------------------------------------------

    /**
     * Checklist de validacion, con el texto exacto del documento del cliente («Checklist de validación de la ficha de
     * registro de la implementación»: 34 items en 8 secciones, 8 de ellos CRÍTICO).
     *
     * @return array Seccion => ['titulo' => string, 'items' => [id => texto]].
     */
    public static function checklist(): array {
        return [
            1 => ['titulo' => 'Identificación general', 'items' => [
                'c1_1' => 'La ficha contiene todos los datos administrativos requeridos',
                'c1_2' => 'El plan formativo, el módulo y la experiencia de aprendizaje corresponden a los del diseño '
                    . 'instruccional validado',
                'c1_3' => 'El número de participantes presentes es coherente (mayor que cero y no superior al de inscritos)',
                'c1_4' => 'El tiempo total disponible y el tiempo asignado a la experiencia corresponden a los definidos en el '
                    . 'diseño instruccional validado',
                'c1_5' => 'El tiempo efectivamente utilizado es coherente con el horario registrado',
            ]],
            2 => ['titulo' => 'Aprendizaje esperado y criterios trabajados', 'items' => [
                'c2_1' => 'El aprendizaje esperado corresponde al declarado en el diseño instruccional validado',
                'c2_2' => 'Los criterios de evaluación registrados están transcritos tal como figuran en el diseño para ese '
                    . 'aprendizaje esperado',
                'c2_3' => 'El campo 2.2 identifica los criterios previstos para la experiencia que no se trabajaron, o indica '
                    . 'que se trabajaron todos',
                'c2_4' => 'Los criterios de las subsecciones 2.1 y 2.2, en conjunto, coinciden con los criterios del diseño '
                    . 'validado',
                'c2_5' => 'El diseño incluye al menos 3 criterios y todos los comprometidos en el plan formativo para el '
                    . 'aprendizaje esperado (si no se cumple, se registra como hallazgo del diseño, no como falta de la ficha)',
            ]],
            3 => ['titulo' => 'Coherencia entre diseño y ejecución', 'items' => [
                'c3_1' => 'Las cuatro valoraciones están registradas',
                'c3_2' => 'Toda valoración 1 (muy bajo) o 2 (bajo) tiene un comentario',
                'c3_3' => 'Las valoraciones son coherentes con lo registrado en las secciones 1.1, 4 y 6.3',
            ]],
            4 => ['titulo' => 'Desempeño de las personas participantes', 'items' => [
                'c4_1' => 'Existe una fila por cada criterio de evaluación registrado en la sección 2',
                'c4_2' => 'Cada criterio tiene evidencias observables que respaldan el número de participantes por nivel',
                'c4_3' => 'En cada criterio, la suma de participantes por nivel es igual al número de participantes presentes',
                'c4_4' => 'El logro (%) está bien calculado: (Adecuado + Avanzado) ÷ presentes × 100',
                'c4_5' => 'Los comentarios describen patrones de desempeño con datos concretos',
                'c4_6' => 'Si se completó la subsección 4.2, las observaciones son específicas y consistentes con la tabla 4.1',
            ]],
            5 => ['titulo' => 'Condiciones del entorno y recursos', 'items' => [
                'c5_1' => 'Las cuatro valoraciones están registradas',
                'c5_2' => 'Toda valoración 1 (muy bajo) o 2 (bajo) tiene un comentario que describe el incidente o la limitación',
                'c5_3' => 'Las condiciones registradas son coherentes con las dificultades señaladas en las secciones 3 y 6.3',
            ]],
            6 => ['titulo' => 'Juicio fundado', 'items' => [
                'c6_1' => 'La valoración 6.1 está fundamentada con datos concretos (porcentajes, número de participantes, '
                    . 'evidencias)',
                'c6_2' => 'La valoración 6.1 es coherente con el logro registrado en la sección 4',
                'c6_3' => 'Se identifican aspectos pertinentes específicos y explicados (6.2)',
                'c6_4' => 'Se identifican dificultades específicas, con sus causas (6.3)',
            ]],
            7 => ['titulo' => 'Registro de cambios en la implementación', 'items' => [
                'c7_1' => 'Los criterios previstos que no se trabajaron están identificados, con su motivo (2.2)',
                'c7_2' => 'Las actividades no realizadas, modificadas, sustituidas o incorporadas están descritas, con su '
                    . 'motivo (3.1)',
                'c7_3' => 'Los cambios en la estrategia didáctica están descritos, con su motivo (3.3)',
                'c7_4' => 'Los cambios en los momentos, evidencias o instrumentos de evaluación están descritos, con su '
                    . 'motivo (3.4)',
                'c7_5' => 'Si el tiempo utilizado difiere del asignado, la diferencia se explica',
            ]],
            8 => ['titulo' => 'Validación global', 'items' => [
                'c8_1' => 'La ficha está completa',
                'c8_2' => 'La información es coherente globalmente: la ficha registra la implementación de la experiencia del '
                    . 'diseño validado y los datos de las distintas secciones no se contradicen entre sí',
                'c8_3' => 'Los datos permiten calcular el logro promedio y la cobertura evaluativa, e identificar brechas por '
                    . 'criterio, pendientes y tiempo restante',
            ]],
        ];
    }

    /**
     * Todos los ids de item del checklist, en orden.
     *
     * @return string[]
     */
    public static function checklist_ids(): array {
        $ids = [];
        foreach (self::checklist() as $seccion) {
            $ids = array_merge($ids, array_keys($seccion['items']));
        }
        return $ids;
    }

    /**
     * Texto de cada item del checklist.
     *
     * @return string[] id => texto.
     */
    public static function checklist_texts(): array {
        $textos = [];
        foreach (self::checklist() as $seccion) {
            $textos += $seccion['items'];
        }
        return $textos;
    }

    // ------------------------------------------------------------------------------------------
    // Datos de la ficha: limpieza y control de entrada.
    // ------------------------------------------------------------------------------------------

    /**
     * Claves que se guardan de la ficha.
     *
     * @return array clave => tipo de dato.
     */
    public static function field_keys(): array {
        $claves = [];
        foreach (self::sections() as $seccion) {
            foreach ($seccion['campos'] as $campo) {
                $claves[$campo['id']] = $campo['tipo'];
            }
        }
        return $claves + self::table_fields();
    }

    /**
     * Campo de la ficha por id.
     *
     * @param string $id
     * @return array|null
     */
    public static function field(string $id): ?array {
        foreach (self::sections() as $seccion) {
            foreach ($seccion['campos'] as $campo) {
                if ($campo['id'] === $id) {
                    return $campo;
                }
            }
        }
        return null;
    }

    /**
     * Limpia lo que llega del formulario: solo claves conocidas, opciones validas y largos acotados.
     *
     * @param array $raw clave => string tal como llega.
     * @return array clave => string (todas las claves, vacias si no vinieron).
     */
    public static function clean(array $raw): array {
        $limpio = [];
        foreach (self::field_keys() as $clave => $tipo) {
            $valor = $raw[$clave] ?? null;
            switch ($tipo) {
                case self::T_UNA:
                    $opciones = self::field($clave)['opciones'];
                    $valor = is_scalar($valor) ? (string) $valor : '';
                    $limpio[$clave] = array_key_exists($valor, $opciones) ? $valor : '';
                    break;
                case self::T_NUMERO:
                    $valor = is_scalar($valor) ? trim((string) $valor) : '';
                    $limpio[$clave] = preg_match('/^\d{1,7}$/', $valor) ? (string) (int) $valor : '';
                    break;
                case self::T_PORCENTAJE:
                    $valor = is_scalar($valor) ? str_replace(',', '.', trim((string) $valor)) : '';
                    $limpio[$clave] = (preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $valor) && (float) $valor <= 100.0)
                        ? rtrim(rtrim(number_format((float) $valor, 2, '.', ''), '0'), '.') : '';
                    if ($limpio[$clave] === '' && $valor !== '' && preg_match('/^0+(?:\.0+)?$/', $valor)) {
                        $limpio[$clave] = '0';
                    }
                    break;
                default:
                    $valor = is_scalar($valor) ? trim(str_replace("\r\n", "\n", (string) $valor)) : '';
                    $largo = $tipo === self::T_PARRAFO ? self::LARGO_PARRAFO : self::LARGO_TEXTO;
                    $limpio[$clave] = \core_text::substr($valor, 0, $largo);
            }
        }
        return $limpio;
    }

    /**
     * Si un valor guardado esta vacio.
     *
     * @param mixed $valor
     * @return bool
     */
    private static function vacio($valor): bool {
        return trim((string) $valor) === '';
    }

    /**
     * Posiciones (1 a CRITERIOS_MAX) de los criterios de evaluacion que la ficha registra en 2.1.
     *
     * @param array $data Ficha ya limpia.
     * @return int[]
     */
    public static function criteria_indexes(array $data): array {
        $indices = [];
        for ($i = 1; $i <= self::CRITERIOS_MAX; $i++) {
            if (!self::vacio($data['criterio' . $i] ?? '')) {
                $indices[] = $i;
            }
        }
        return $indices;
    }

    /**
     * Cuantos criterios de evaluacion se registraron.
     *
     * @param array $data
     * @return int
     */
    public static function criteria_count(array $data): int {
        return count(self::criteria_indexes($data));
    }

    /**
     * Control de entrada: sin esto no se valida (Prompt Maestro y checklist del cliente).
     *
     * Sección 1: todos los campos de identificacion y los tres tiempos. Sección 2: aprendizaje esperado, al menos un
     * criterio y el campo 2.2. Secciones 3 y 5: las ocho valoraciones, con comentario en toda valoracion 1 o 2. Sección 4.1:
     * una fila por cada criterio de la sección 2, con evidencias, numero de participantes por nivel y logro. Sección 6:
     * valoracion 6.1 con su fundamentacion y los campos 6.2 y 6.3. Opcionales: 4.2 y los comentarios de las valoraciones
     * 3 y 4 sin cambios. Completar no es aprobar: la correspondencia con el diseño la evaluan los items CRÍTICO.
     *
     * @param array $data Ficha ya limpia.
     * @param bool $condiseno Si hay un diseño del Modulo 2 seleccionado, disponible y con su plan formativo.
     * @return string[] Lo que falta, con el texto de la ficha.
     */
    public static function missing_inputs(array $data, bool $condiseno): array {
        $falta = [];
        if (!$condiseno) {
            $falta[] = 'Diseño de la experiencia implementada (selecciónalo de tus diseños guardados en el Asistente de '
                . 'Diseño de Experiencias de Aprendizaje)';
        }
        foreach (self::sections() as $n => $seccion) {
            foreach ($seccion['campos'] as $campo) {
                $id = $campo['id'];
                if (strpos($id, 'criterio') === 0) {
                    continue;
                }
                if (isset($campo['de'])) {
                    // Comentario de una valoracion: obligatorio con 1 o 2 (los de 3 y 4 son opcionales).
                    if (in_array($data[$campo['de']] ?? '', ['1', '2'], true) && self::vacio($data[$id] ?? '')) {
                        $falta[] = $campo['num_de'] . ' Comentario (obligatorio con valoración 1 o 2)';
                    }
                    continue;
                }
                if (self::vacio($data[$id] ?? '')) {
                    $falta[] = self::field_name($campo, $n);
                }
            }
            if ($n === 2 && self::criteria_count($data) === 0) {
                $falta[] = '2.1 Criterios de evaluación (al menos uno)';
            }
        }
        foreach (self::criteria_indexes($data) as $i) {
            $fila = 'Tabla 4.1, criterio ' . $i;
            if (self::vacio($data['ev' . $i] ?? '')) {
                $falta[] = $fila . ': evidencias observadas';
            }
            foreach (self::NIVELES as $clave => $nombre) {
                if (self::vacio($data[$clave . $i] ?? '')) {
                    $falta[] = $fila . ': N.º de participantes en nivel ' . $nombre;
                }
            }
            if (self::vacio($data['lg' . $i] ?? '')) {
                $falta[] = $fila . ': logro (%)';
            }
        }
        return $falta;
    }

    /**
     * Nombre de un campo para decir que falta: «1.1 Tiempo total...» o «Sección 1: Plan formativo».
     *
     * @param array $campo
     * @param int $seccion
     * @return string
     */
    private static function field_name(array $campo, int $seccion): string {
        if (!empty($campo['nombre'])) {
            return $campo['nombre'];
        }
        $rotulo = rtrim(str_replace(' (obligatorio)', '', $campo['label']), ':');
        return !empty($campo['num']) ? $campo['num'] . ' ' . $rotulo : 'Sección ' . $seccion . ': ' . $rotulo;
    }

    // ------------------------------------------------------------------------------------------
    // Calculos de la ficha (aritmetica que manda el codigo).
    // ------------------------------------------------------------------------------------------

    /**
     * Desempeño por criterio, con el logro recalculado. Funcion pura.
     *
     * @param array $data Ficha ya limpia.
     * @return array Lista, un elemento por criterio de 2.1: n, criterio, niveles (clave => int), suma, presentes,
     *         logro_declarado (float|null), logro (float: el calculado con los presentes), suma_ok, logro_ok, completa.
     */
    public static function performance(array $data): array {
        $presentes = (int) ($data['presentes'] ?? 0);
        $filas = [];
        foreach (self::criteria_indexes($data) as $i) {
            $niveles = [];
            foreach (array_keys(self::NIVELES) as $clave) {
                $niveles[$clave] = (int) ($data[$clave . $i] ?? 0);
            }
            $suma = array_sum($niveles);
            $logro = $presentes > 0 ? round(100 * ($niveles['ade'] + $niveles['ava']) / $presentes, 1) : 0.0;
            $declarado = self::vacio($data['lg' . $i] ?? '') ? null : (float) $data['lg' . $i];
            $completa = !self::vacio($data['ev' . $i] ?? '') && $declarado !== null;
            foreach (array_keys(self::NIVELES) as $clave) {
                $completa = $completa && !self::vacio($data[$clave . $i] ?? '');
            }
            $filas[] = ['n' => $i, 'criterio' => (string) $data['criterio' . $i], 'niveles' => $niveles, 'suma' => $suma,
                'presentes' => $presentes, 'logro_declarado' => $declarado, 'logro' => $logro,
                'suma_ok' => $presentes > 0 && $suma === $presentes,
                'logro_ok' => $declarado !== null && abs($declarado - $logro) <= self::LOGRO_TOLERANCIA,
                'completa' => $completa];
        }
        return $filas;
    }

    /**
     * Los tiempos de la subseccion 1.1 y el tiempo restante (total − utilizado), base de las acciones remediales.
     *
     * @param array $data Ficha ya limpia.
     * @return array total, asignado, utilizado (int|null), restante (int|null) y estado: ok, cero (nada restante),
     *         negativo (se uso mas que el total) o sin_datos.
     */
    public static function times(array $data): array {
        $t = static function($v): ?int {
            return trim((string) $v) === '' ? null : (int) $v;
        };
        $total = $t($data['tiempo_total'] ?? '');
        $utilizado = $t($data['tiempo_utilizado'] ?? '');
        $restante = ($total !== null && $utilizado !== null) ? $total - $utilizado : null;
        $estado = $restante === null ? 'sin_datos' : ($restante > 0 ? 'ok' : ($restante === 0 ? 'cero' : 'negativo'));
        return ['total' => $total, 'asignado' => $t($data['tiempo_asignado'] ?? ''), 'utilizado' => $utilizado,
            'restante' => $restante, 'estado' => $estado];
    }

    /**
     * Texto sin tildes ni signos y en minusculas, para comparar transcripciones.
     *
     * @param string $texto
     * @return string
     */
    public static function norm(string $texto): string {
        $t = \core_text::strtolower(\core_text::specialtoascii($texto));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $t));
    }

    /**
     * Parecido entre dos textos (0 a 1): 1 si son iguales tras normalizar; si no, coeficiente de Dice entre los conjuntos
     * de palabras. Sirve para decirle al modelo si una transcripcion es textual, parecida o distinta. Funcion pura.
     *
     * @param string $a
     * @param string $b
     * @return float
     */
    public static function similarity(string $a, string $b): float {
        $a = self::norm($a);
        $b = self::norm($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $pa = array_unique(explode(' ', $a));
        $pb = array_unique(explode(' ', $b));
        return round(2 * count(array_intersect($pa, $pb)) / (count($pa) + count($pb)), 3);
    }

    /**
     * Como se dice el parecido de una transcripcion.
     *
     * @param float $parecido De similarity().
     * @return string textual, casi, parecida o distinta.
     */
    public static function similarity_label(float $parecido): string {
        if ($parecido >= 1.0) {
            return 'textual';
        }
        return $parecido >= 0.9 ? 'casi' : ($parecido >= 0.6 ? 'parecida' : 'distinta');
    }

    /**
     * Datos del diseño del Modulo 2 que sirven de referencia: los que la ficha de diagnostico y la etapa 2 del diseño
     * declaran. Funcion pura.
     *
     * @param array $m2 Ficha de diagnostico del Modulo 2 (exante::clean).
     * @param string $experiencia Nombre de la experiencia (etapa 2 del Modulo 2); '' si no se pudo leer.
     * @return array plan, modulo, experiencia, aprendizaje (string), criterios (string[]), tiempo_total y
     *         tiempo_asignado (int|null).
     */
    public static function design_reference(array $m2, string $experiencia): array {
        $criterios = [];
        for ($i = 1; $i <= 30; $i++) {
            if (!self::vacio($m2['criterio' . $i] ?? '')) {
                $criterios[] = trim((string) $m2['criterio' . $i]);
            }
        }
        $t = static function($v): ?int {
            return preg_match('/^\d+$/', trim((string) $v)) ? (int) $v : null;
        };
        return ['plan' => trim((string) ($m2['plan'] ?? '')), 'modulo' => trim((string) ($m2['modulo'] ?? '')),
            'experiencia' => trim($experiencia), 'aprendizaje' => trim((string) ($m2['aprendizaje'] ?? '')),
            'criterios' => $criterios, 'tiempo_total' => $t($m2['tiempo_total'] ?? ''),
            'tiempo_asignado' => $t($m2['tiempo_experiencia'] ?? '')];
    }

    /**
     * Compara los criterios de la ficha con los del diseño: cada criterio de la ficha con el mas parecido del diseño, y los
     * del diseño que ninguno de la ficha recoge (los pendientes por estructura). Funcion pura.
     *
     * @param string[] $ficha Criterios de 2.1, por posicion (n => texto).
     * @param string[] $diseno Criterios del diseño.
     * @return array ficha (n => ['parecido' => float, 'etiqueta' => string, 'diseno' => int|null (posicion 1..)]) y
     *         sin_ficha (posiciones 1.. del diseño que ningun criterio de la ficha recoge).
     */
    public static function match_criteria(array $ficha, array $diseno): array {
        $usados = [];
        $resultado = [];
        foreach ($ficha as $n => $texto) {
            $mejor = 0.0;
            $pos = null;
            foreach (array_values($diseno) as $k => $referencia) {
                if (isset($usados[$k])) {
                    continue;
                }
                $p = self::similarity($texto, $referencia);
                if ($p > $mejor) {
                    $mejor = $p;
                    $pos = $k;
                }
            }
            if ($pos !== null && $mejor >= 0.6) {
                $usados[$pos] = true;
            } else {
                $pos = null;
            }
            $resultado[$n] = ['parecido' => $mejor, 'etiqueta' => self::similarity_label($mejor),
                'diseno' => $pos === null ? null : $pos + 1];
        }
        $sin = [];
        foreach (array_keys(array_values($diseno)) as $k) {
            if (!isset($usados[$k])) {
                $sin[] = $k + 1;
            }
        }
        return ['ficha' => $resultado, 'sin_ficha' => $sin];
    }

    /**
     * Verificaciones aritmeticas y de correspondencia que hace la plataforma antes (y despues) del modelo.
     *
     * Decide por calculo los items c1_3 (presentes), c1_4 (tiempos contra el diseño, si el diseño los declara), c4_1 (fila
     * por criterio), c4_3 (suma por nivel) y c4_4 (logro): el codigo es la autoridad, no el modelo. Funcion pura.
     *
     * @param array $data Ficha ya limpia.
     * @param array|null $ref design_reference() del diseño seleccionado (null: no se compara con el diseño).
     * @return array items (id => ['estado', 'observacion', 'propuesta']) y hechos (string[]: lineas para el modelo).
     */
    public static function system_checks(array $data, ?array $ref): array {
        $items = [];
        $hechos = [];
        $inscritos = self::vacio($data['inscritos'] ?? '') ? null : (int) $data['inscritos'];
        $presentes = self::vacio($data['presentes'] ?? '') ? null : (int) $data['presentes'];

        // c1_3: presentes mayor que cero y no superior a los inscritos.
        if ($presentes !== null && $inscritos !== null) {
            $ok = $presentes > 0 && $presentes <= $inscritos;
            $items['c1_3'] = ['estado' => $ok ? self::CUMPLE : self::NO_CUMPLE,
                'observacion' => $ok ? '' : ($presentes <= 0
                    ? 'La ficha registra ' . $presentes . ' participantes presentes: debe ser mayor que cero.'
                    : 'La ficha registra ' . $presentes . ' participantes presentes y ' . $inscritos . ' inscritos: los '
                        . 'presentes no pueden superar a los inscritos.'),
                'propuesta' => $ok ? '' : 'Revisa en la sección 1 los campos «N.º de participantes inscritos» y «N.º de '
                    . 'participantes presentes».'];
            $hechos[] = 'Participantes: ' . $inscritos . ' inscritos y ' . $presentes . ' presentes → '
                . ($ok ? 'coherente (presentes mayor que cero y no superior a los inscritos).' : 'INCOHERENTE.');
        }

        // c1_4: tiempos contra el diseño.
        $tiempos = self::times($data);
        if ($ref !== null && $ref['tiempo_total'] !== null && $ref['tiempo_asignado'] !== null
                && $tiempos['total'] !== null && $tiempos['asignado'] !== null) {
            $okt = $tiempos['total'] === $ref['tiempo_total'];
            $oka = $tiempos['asignado'] === $ref['tiempo_asignado'];
            $dif = [];
            if (!$okt) {
                $dif[] = 'el tiempo total disponible es ' . $tiempos['total'] . ' minutos en la ficha y ' . $ref['tiempo_total']
                    . ' en el diseño (subsección 2.2 de la ficha de diagnóstico del Módulo 2)';
            }
            if (!$oka) {
                $dif[] = 'el tiempo asignado a la experiencia es ' . $tiempos['asignado'] . ' minutos en la ficha y '
                    . $ref['tiempo_asignado'] . ' en el diseño (subsección 2.3 de la ficha de diagnóstico del Módulo 2)';
            }
            $items['c1_4'] = ['estado' => $dif ? self::NO_CUMPLE : self::CUMPLE,
                'observacion' => $dif ? 'No corresponden al diseño: ' . implode('; ', $dif) . '.' : '',
                'propuesta' => $dif ? 'En 1.1 registra los tiempos que define el diseño (' . $ref['tiempo_total'] . ' minutos de '
                    . 'tiempo total y ' . $ref['tiempo_asignado'] . ' minutos asignados a la experiencia).' : ''];
            $hechos[] = 'Tiempos de referencia del diseño: total ' . $ref['tiempo_total'] . ' min y asignado '
                . $ref['tiempo_asignado'] . ' min. En la ficha: total ' . $tiempos['total'] . ' min y asignado '
                . $tiempos['asignado'] . ' min → ' . ($dif ? 'NO corresponden.' : 'corresponden.');
        }

        // c4_1, c4_3 y c4_4: tabla 4.1.
        $filas = self::performance($data);
        if ($filas) {
            $sinfila = [];
            $malsuma = [];
            $mallogro = [];
            foreach ($filas as $f) {
                $niveles = [];
                foreach (self::NIVELES as $clave => $nombre) {
                    $niveles[] = $nombre . ' ' . $f['niveles'][$clave];
                }
                $linea = 'Criterio ' . $f['n'] . ': ' . implode(', ', $niveles) . ' → suma ' . $f['suma'] . ' de '
                    . $f['presentes'] . ' presentes (' . ($f['suma_ok'] ? 'coincide' : 'NO coincide') . '). Logro declarado '
                    . ($f['logro_declarado'] === null ? '(vacío)' : self::pct($f['logro_declarado'])) . ', calculado ('
                    . ($f['niveles']['ade'] + $f['niveles']['ava']) . ' ÷ ' . $f['presentes'] . ' × 100) = '
                    . self::pct($f['logro']) . ' (' . ($f['logro_ok'] ? 'bien calculado' : 'MAL calculado') . ').';
                $hechos[] = $linea;
                if (!$f['completa']) {
                    $sinfila[] = $f['n'];
                }
                if (!$f['suma_ok']) {
                    $malsuma[] = 'criterio ' . $f['n'] . ' (suma ' . $f['suma'] . ', presentes ' . $f['presentes'] . ')';
                }
                if (!$f['logro_ok']) {
                    $mallogro[] = 'criterio ' . $f['n'] . ': la ficha dice ' . ($f['logro_declarado'] === null ? 'nada'
                        : self::pct($f['logro_declarado'])) . ' y corresponde ' . self::pct($f['logro']);
                }
            }
            $items['c4_1'] = ['estado' => $sinfila ? self::NO_CUMPLE : self::CUMPLE,
                'observacion' => $sinfila ? 'Falta la fila completa de la tabla 4.1 de los criterios: ' . implode(', ', $sinfila) . '.' : '',
                'propuesta' => $sinfila ? 'Completa la tabla 4.1 con una fila por cada criterio de la sección 2.' : ''];
            $items['c4_3'] = ['estado' => $malsuma ? self::NO_CUMPLE : self::CUMPLE,
                'observacion' => $malsuma ? 'La suma de participantes por nivel no es igual al número de presentes en: '
                    . implode('; ', $malsuma) . '.' : '',
                'propuesta' => $malsuma ? 'Corrige en la tabla 4.1 el número de participantes por nivel para que sumen los '
                    . 'presentes.' : ''];
            $items['c4_4'] = ['estado' => $mallogro ? self::PARCIAL : self::CUMPLE,
                'observacion' => $mallogro ? 'Logro (%) mal calculado, recalculado con los datos de la tabla: '
                    . implode('; ', $mallogro) . '.' : '',
                'propuesta' => $mallogro ? 'Corrige el logro (%) con la fórmula (Adecuado + Avanzado) ÷ presentes × 100.' : ''];
        }
        return ['items' => $items, 'hechos' => $hechos];
    }

    /**
     * Un porcentaje como lo lee una persona («81,8 %»).
     *
     * @param float $valor
     * @return string
     */
    public static function pct(float $valor): string {
        return str_replace('.', ',', rtrim(rtrim(number_format($valor, 1, '.', ''), '0'), '.')) . ' %';
    }

    /**
     * Comparaciones de la ficha con el diseño, como lineas para el modelo y para el informe. Funcion pura.
     *
     * @param array $data Ficha ya limpia.
     * @param array $ref design_reference().
     * @return string[]
     */
    public static function reference_facts(array $data, array $ref): array {
        $dic = ['textual' => 'idéntico tras normalizar tildes y mayúsculas', 'casi' => 'casi idéntico (diferencias de forma)',
            'parecida' => 'parecido pero con diferencias de contenido', 'distinta' => 'distinto'];
        $lineas = [];
        foreach ([['Plan formativo', 'plan', 'plan'], ['Módulo', 'modulo', 'modulo'],
                ['Nombre de la experiencia', 'experiencia', 'experiencia'],
                ['Aprendizaje esperado', 'aprendizaje', 'aprendizaje']] as [$rotulo, $clave, $refclave]) {
            if ($ref[$refclave] === '') {
                $lineas[] = $rotulo . ': el diseño no lo declara de forma que se pueda comparar; evalúalo con el diseño adjunto.';
                continue;
            }
            $parecido = self::similarity((string) ($data[$clave] ?? ''), $ref[$refclave]);
            $lineas[] = $rotulo . ' de la ficha frente al del diseño: ' . $dic[self::similarity_label($parecido)] . '.';
        }
        $criterios = [];
        foreach (self::criteria_indexes($data) as $i) {
            $criterios[$i] = (string) $data['criterio' . $i];
        }
        $cmp = self::match_criteria($criterios, $ref['criterios']);
        foreach ($cmp['ficha'] as $n => $c) {
            $lineas[] = 'Criterio ' . $n . ' de la ficha: ' . ($c['diseno'] === null
                ? 'no se parece a ningún criterio del diseño.'
                : $dic[$c['etiqueta']] . ' respecto del criterio ' . $c['diseno'] . ' del diseño.');
        }
        $total = count($ref['criterios']);
        $lineas[] = 'El diseño declara ' . $total . ' criterio(s) de evaluación para el aprendizaje esperado; la ficha registra '
            . count($criterios) . ' en 2.1.' . ($cmp['sin_ficha'] ? ' Criterios del diseño que ninguno de 2.1 recoge: '
                . implode(', ', $cmp['sin_ficha']) . ' (deben estar en 2.2 con su motivo).' : '');
        return $lineas;
    }

    /**
     * Avisos antes de validar, con reglas fijas (sin IA, sin gastar un intento). Son avisos: no impiden validar.
     *
     * @param array $data Ficha ya limpia.
     * @return string[] Un texto por aviso.
     */
    public static function form_warnings(array $data): array {
        $avisos = [];
        $comprobacion = self::system_checks($data, null)['items'];
        foreach (['c1_3', 'c4_3', 'c4_4'] as $id) {
            if (isset($comprobacion[$id]) && $comprobacion[$id]['estado'] !== self::CUMPLE) {
                $avisos[] = $comprobacion[$id]['observacion'] . ' ' . $comprobacion[$id]['propuesta'];
            }
        }
        $t = self::times($data);
        if ($t['total'] !== null && $t['asignado'] !== null && $t['asignado'] > $t['total']) {
            $avisos[] = 'El tiempo asignado a la experiencia (' . $t['asignado'] . ' min) supera el tiempo total disponible ('
                . $t['total'] . ' min).';
        }
        if ($t['estado'] === 'negativo') {
            $avisos[] = 'El tiempo efectivamente utilizado (' . $t['utilizado'] . ' min) supera el tiempo total disponible ('
                . $t['total'] . ' min): no quedaría tiempo restante para acciones remediales.';
        } else if ($t['estado'] === 'cero') {
            $avisos[] = 'El tiempo efectivamente utilizado es igual al tiempo total disponible: no queda tiempo restante para '
                . 'acciones remediales.';
        }
        foreach (array_keys(self::rated(3) + self::rated(5)) as $id) {
            if (in_array($data[$id] ?? '', ['1', '2'], true)) {
                $campo = self::field('com' . substr($id, 3));
                if (self::vacio($data['com' . substr($id, 3)] ?? '')) {
                    $avisos[] = 'La valoración ' . $campo['num_de'] . ' es ' . $data[$id] . ' y no tiene comentario.';
                }
            }
        }
        return $avisos;
    }

    /**
     * Mensaje de bienvenida del Prompt Maestro, textual («Al iniciar, debes mostrar exactamente el siguiente mensaje»).
     *
     * @return string[] Los parrafos.
     */
    public static function welcome(): array {
        return [
            'Bienvenido/a, soy tu asistente de aseguramiento de la calidad de los aprendizajes.',
            'Te puedo ayudar a analizar experiencias de aprendizaje implementadas, identificar hallazgos y brechas, decidir el '
                . 'tipo de ajuste que corresponde, proponer acciones remediales o mejoras al diseño y diseñar la experiencia de '
                . 'aprendizaje de continuidad.',
            'Comienza seleccionando, del listado de tus diseños guardados en el Asistente de Diseño de Experiencias de '
                . 'Aprendizaje, el diseño cuya implementación quieres evaluar. Recuperaré desde ese módulo el diseño, el plan '
                . 'formativo y, si existe, el perfil ocupacional. Luego completa en línea la ficha de registro de la '
                . 'implementación; puedes apoyarte en la guía de llenado. Cuando envíes la ficha, la revisaré y validaré antes '
                . 'de iniciar el análisis.',
        ];
    }

    /**
     * La ficha como texto para el modelo, en el orden y con los rotulos del documento.
     *
     * El nombre de la persona facilitadora no viaja: el checklist solo necesita saber si esta. La tabla 4.1 va una fila por
     * criterio, con la suma por nivel; los nombres que se escriban en 4.2 los decide quien llena la ficha.
     *
     * @param array $data Ficha ya limpia.
     * @return string
     */
    public static function to_text(array $data): string {
        $sin = '(sin respuesta)';
        $lineas = [];
        foreach (self::sections() as $n => $seccion) {
            $lineas[] = '';
            $lineas[] = 'SECCIÓN ' . $n . '. ' . $seccion['titulo'];
            $escala = self::scale();
            foreach ($seccion['campos'] as $campo) {
                $id = $campo['id'];
                if (!empty($campo['adicional']) && self::vacio($data[$id] ?? '')) {
                    continue;
                }
                if (!empty($campo['subtitulo'])) {
                    $lineas[] = $campo['subtitulo'];
                }
                if (isset($campo['de'])) {
                    $valor = trim((string) ($data[$id] ?? ''));
                    $lineas[] = '    Comentario de ' . $campo['num_de'] . ': ' . ($valor !== '' ? $valor : '(sin comentario)');
                    continue;
                }
                $rotulo = (!empty($campo['num']) && empty($campo['subtitulo']) ? $campo['num'] . '. ' : '')
                    . rtrim($campo['label'], ':');
                if ($campo['tipo'] === self::T_UNA) {
                    $k = (string) ($data[$id] ?? '');
                    $valor = isset($campo['opciones'][$k]) ? $campo['opciones'][$k] : $sin;
                } else {
                    $valor = trim((string) ($data[$id] ?? ''));
                    if ($id === 'facilitador' && $valor !== '') {
                        $valor = '(registrado)';
                    }
                    if ($valor === '') {
                        $valor = $sin;
                    }
                }
                $lineas[] = '- ' . $rotulo . ': ' . $valor;
            }
            if ($n === 4) {
                $lineas[] = '4.1 Síntesis de desempeño del grupo por criterio de evaluación';
                foreach (self::performance($data) as $f) {
                    $i = $f['n'];
                    $niv = [];
                    foreach (self::NIVELES as $clave => $nombre) {
                        $niv[] = $nombre . ' ' . (self::vacio($data[$clave . $i] ?? '') ? '(sin dato)' : $data[$clave . $i]);
                    }
                    $lineas[] = '- Criterio ' . $i . ': ' . $f['criterio'];
                    $lineas[] = '    Evidencias observadas: ' . (self::vacio($data['ev' . $i] ?? '') ? $sin : $data['ev' . $i]);
                    $lineas[] = '    N.º de participantes por nivel: ' . implode('; ', $niv);
                    $lineas[] = '    Logro (%) declarado en la ficha: ' . (self::vacio($data['lg' . $i] ?? '') ? $sin
                        : str_replace('.', ',', $data['lg' . $i]) . ' %');
                    $lineas[] = '    Comentario de la persona facilitadora: '
                        . (self::vacio($data['cm' . $i] ?? '') ? '(sin comentario)' : $data['cm' . $i]);
                }
                $lineas[] = '4.2 Observaciones específicas sobre participantes o subgrupos (opcional)';
                $hay = false;
                for ($j = 1; $j <= self::OBSERVACIONES_FILAS; $j++) {
                    $fila = [];
                    foreach (['quien' => 'Participante(s) / subgrupo', 'situacion' => 'Situación observada',
                            'evidencia' => 'Evidencia asociada', 'comentario' => 'Comentario'] as $c => $rotulo) {
                        if (!self::vacio($data['ob' . $j . '_' . $c] ?? '')) {
                            $fila[] = $rotulo . ': ' . $data['ob' . $j . '_' . $c];
                        }
                    }
                    if ($fila) {
                        $hay = true;
                        $lineas[] = '- ' . implode(' | ', $fila);
                    }
                }
                if (!$hay) {
                    $lineas[] = '- (opcional, sin información)';
                }
            }
        }
        return trim(implode("\n", $lineas));
    }

    /**
     * Huella de lo que se valida: la ficha, el diseño elegido y su contenido. Cambiar cualquiera pide validar de nuevo.
     *
     * @param array $data Ficha ya limpia.
     * @param int $disenoid Ficha de diagnostico (Modulo 2) del diseño elegido.
     * @param string $diseno Huella del contenido del diseño (design_fingerprint()).
     * @return string
     */
    public static function fingerprint(array $data, int $disenoid, string $diseno): string {
        ksort($data);
        return sha1(json_encode([$data, $disenoid, $diseno], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Huella del contenido de un diseño del Modulo 2: su ficha de diagnostico y las etapas 2 a 6 aceptadas.
     *
     * @param array $m2 Ficha de diagnostico del Modulo 2 (ya limpia).
     * @param string[] $etapas Etapa => producto aceptado (HTML).
     * @return string
     */
    public static function design_fingerprint(array $m2, array $etapas): string {
        ksort($m2);
        ksort($etapas);
        return sha1(json_encode([$m2, $etapas], JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------------------------------------------------------
    // Pedido al modelo.
    // ------------------------------------------------------------------------------------------

    /**
     * Regla de fuentes del Asistente de Aseguramiento de la Calidad de los Aprendizajes: para el enfoque de competencias,
     * preferentemente su libreria de conocimientos; para la especialidad tecnica de la persona, la web. Funcion pura.
     *
     * @param bool $conlibreria Si la libreria del rol viene en el pedido.
     * @param bool $conbusqueda Si el modelo puede buscar en la web.
     * @return string[] Lineas de las reglas.
     */
    public static function knowledge_rules(bool $conlibreria, bool $conbusqueda): array {
        $l = ['FUENTES DE CONOCIMIENTO:'];
        $l[] = $conlibreria
            ? '- Para todo lo relacionado con la aplicación del enfoque de competencias (aprendizajes esperados, criterios '
                . 'de evaluación, estrategias didácticas, evaluación por competencias y diseño instruccional), usa '
                . 'preferentemente la LIBRERÍA DE CONOCIMIENTOS DEL ' . \core_text::strtoupper(self::ROL) . ' que viene en este '
                . 'pedido: basa tus decisiones en ella antes que en conocimiento general.'
            : '- Para todo lo relacionado con la aplicación del enfoque de competencias, la fuente preferente es la librería de '
                . 'conocimientos del ' . self::ROL . '; en este pedido no viene cargada, así que aplica el enfoque de '
                . 'competencias con criterios generales y prudencia.';
        $l[] = $conbusqueda
            ? '- Para lo relacionado con la especialidad técnica de la persona (el oficio, sus procedimientos, equipos, '
                . 'normativa y terminología del sector), busca información actualizada en la web. No inventes datos técnicos: '
                . 'si no encuentras información confiable, dilo.'
            : '- Para lo relacionado con la especialidad técnica de la persona (el oficio, sus procedimientos, equipos, '
                . 'normativa y terminología del sector), usa tu conocimiento general con prudencia, no inventes datos técnicos '
                . 'y señala lo que la persona debe verificar en fuentes del sector.';
        $l[] = '- La librería y la web complementan la ficha de registro, el diseño instruccional validado y el plan formativo, '
            . 'que siguen siendo la fuente principal.';
        if ($conlibreria) {
            $l[] = '- Si la librería trae la guía de llenado, el formato de la ficha, el checklist o un ejemplo de llenado, '
                . 'úsalos para interpretar cada campo y orientar tu evaluación. El ejemplo de llenado es ilustrativo y con '
                . 'datos ficticios: no lo confundas con la ficha de la persona ni tomes de él sus datos.';
        }
        return $l;
    }

    /**
     * Uso del plan formativo y del perfil ocupacional segun el Prompt Maestro del Modulo 3: se recuperan del diseño elegido,
     * el plan es obligatorio y el perfil, opcional. Funcion pura.
     *
     * @param string[] $adjuntos Etiquetas de los documentos recuperados ('Plan Formativo', 'Perfil Ocupacional').
     * @return string
     */
    public static function documents_rule(array $adjuntos): string {
        $plan = in_array('Plan Formativo', $adjuntos, true);
        $perfil = in_array('Perfil Ocupacional', $adjuntos, true);
        $texto = $plan
            ? 'Se adjunta el Plan Formativo, recuperado del diseño seleccionado (no se vuelve a cargar). Es obligatorio: úsalo '
                . 'para verificar que el diseño incluye todos los criterios de evaluación comprometidos para el aprendizaje '
                . 'esperado y que los ajustes propuestos no alteran el aprendizaje esperado ni los criterios.'
            : 'No se pudo recuperar el Plan Formativo del diseño seleccionado, que es obligatorio: indícalo.';
        $texto .= $perfil
            ? ' Se adjunta el Perfil Ocupacional (opcional): úsalo para validar la pertinencia de los ajustes y de la '
                . 'experiencia de continuidad respecto del desempeño laboral esperado, priorizando siempre la información del '
                . 'diseño instruccional validado y de la ficha de registro.'
            : ' No hay Perfil Ocupacional asociado al diseño, que es opcional: continúa normalmente, sin bajar por eso ningún '
                . 'ítem, y señala cuando alguna decisión podría beneficiarse de ese documento.';
        return $texto;
    }

    /**
     * El diseño del Modulo 2 como texto para el modelo: su ficha de diagnostico (referencia) y los productos de las etapas
     * pedidas. Funcion pura.
     *
     * @param array $ref design_reference().
     * @param string[] $etapas Numero de etapa => producto aceptado (texto).
     * @param int $tope Largo maximo de cada producto.
     * @return string
     */
    public static function design_text(array $ref, array $etapas, int $tope = 20000): string {
        $l = ['DISEÑO INSTRUCCIONAL VALIDADO (recuperado del Asistente de Diseño de Experiencias de Aprendizaje):',
            '- Plan formativo: ' . ($ref['plan'] !== '' ? $ref['plan'] : '(no declarado)'),
            '- Módulo: ' . ($ref['modulo'] !== '' ? $ref['modulo'] : '(no declarado)'),
            '- Nombre de la experiencia de aprendizaje: ' . ($ref['experiencia'] !== '' ? $ref['experiencia'] : '(no declarado)'),
            '- Aprendizaje esperado: ' . ($ref['aprendizaje'] !== '' ? $ref['aprendizaje'] : '(no declarado)'),
            '- Tiempo total disponible para el aprendizaje: ' . ($ref['tiempo_total'] !== null ? $ref['tiempo_total'] . ' minutos' : '(no declarado)'),
            '- Tiempo asignado a la experiencia de aprendizaje: ' . ($ref['tiempo_asignado'] !== null
                ? $ref['tiempo_asignado'] . ' minutos' : '(no declarado)'),
            '- Criterios de evaluación comprometidos en el plan formativo para ese aprendizaje esperado (' . count($ref['criterios']) . '):'];
        foreach ($ref['criterios'] as $k => $criterio) {
            $l[] = '    ' . ($k + 1) . '. ' . $criterio;
        }
        ksort($etapas);
        foreach ($etapas as $n => $texto) {
            $l[] = '';
            $l[] = 'PRODUCTO ACEPTADO DE LA ETAPA ' . $n . ' DEL DISEÑO (' . (self::design_stage_titles()[$n] ?? '') . '):';
            $l[] = \core_text::substr(trim((string) $texto), 0, $tope);
        }
        return implode("\n", $l);
    }

    /**
     * Titulos de las etapas del diseño del Modulo 2 (Prompt Maestro del Modulo 2).
     *
     * @return string[]
     */
    public static function design_stage_titles(): array {
        return [1 => 'Análisis diagnóstico', 2 => 'Diseño de la experiencia', 3 => 'Secuencia instruccional',
            4 => 'Materiales instruccionales de apoyo al aprendizaje', 5 => 'Plan de evaluación', 6 => 'Instrumentos evaluativos'];
    }

    /**
     * La libreria del rol como parte del pedido al modelo.
     *
     * @param string $libreria Texto de la libreria; '' si no hay.
     * @return array Partes del pedido ([] sin libreria).
     */
    public static function library_parts(string $libreria): array {
        if (trim($libreria) === '') {
            return [];
        }
        return [['text' => 'LIBRERÍA DE CONOCIMIENTOS DEL ' . \core_text::strtoupper(self::ROL)
            . " (referencia preferente para el enfoque de competencias):\n" . $libreria]];
    }

    /**
     * Arma las partes del pedido al modelo (igual que el Modulo 2): con «libreriaruta» la libreria va primero para
     * aprovechar la cache implicita; si no, al final. Funcion pura.
     *
     * @param string $prompt Instrucciones de la tarea y contenido principal.
     * @param array $adjuntos Partes de los documentos adjuntos.
     * @param string $libreria Texto de la libreria ya seleccionado ('' si no hay).
     * @param bool $primero Si la libreria debe ir al comienzo del pedido.
     * @return array Lista de partes.
     */
    public static function request_parts(string $prompt, array $adjuntos, string $libreria, bool $primero): array {
        $lib = self::library_parts($libreria);
        $resto = array_merge([['text' => $prompt]], $adjuntos);
        return $primero ? array_merge($lib, $resto) : array_merge($resto, $lib);
    }

    /**
     * Libreria del rol para un pedido: vacia con «libreriam3» apagado; con «libreriaruta» y tarea indicada, solo las secciones
     * que esa tarea necesita; si no, completa y sin marcas.
     *
     * @param string $tarea Tarea del Modulo 3 ('m3f1', 'm3e1'..'m3e7').
     * @param string $nivel Nivel de cualificacion que declara el diseño (campo «nivel» de su ficha de diagnostico).
     * @return string
     */
    public static function role_library(string $tarea = '', string $nivel = ''): string {
        if (!ux::on('libreriam3')) {
            return '';
        }
        if (ux::on('libreriaruta') && trim($tarea) !== '') {
            return kb_router::select(kb_store::documents(kb_store::AMBITO_M3), $tarea, kb_router::level_of($nivel))['texto'];
        }
        return kb_router::strip_marks(kb_store::get_combined_context(kb_store::AMBITO_M3));
    }

    /**
     * Instrucciones de validacion: Etapa 0 del Prompt Maestro del Modulo 3 con el checklist completo.
     *
     * @param string[] $adjuntos Etiquetas de los documentos que se adjuntaron (plan formativo, perfil ocupacional).
     * @param bool $conlibreria Si la libreria del rol viene en el pedido.
     * @param bool $conbusqueda Si el modelo puede buscar en la web.
     * @return string
     */
    public static function build_prompt(array $adjuntos, bool $conlibreria = false, bool $conbusqueda = false): string {
        $checklist = [];
        foreach (self::checklist() as $n => $seccion) {
            $checklist[] = $n . '. ' . \core_text::strtoupper($seccion['titulo']);
            foreach ($seccion['items'] as $id => $texto) {
                $checklist[] = '   ' . $id . ': ' . $texto . (in_array($id, self::CRITICOS, true) ? ' [CRÍTICO]' : '');
            }
        }
        return implode("\n", [
            'Eres el ' . self::ROL . ': un asistente experto en diseño instruccional con enfoque de competencias, '
                . 'especializado en formación de personas adultas en contextos de capacitación laboral. Apoyas a personas '
                . 'facilitadoras en el aseguramiento de la calidad de los aprendizajes, analizando experiencias de aprendizaje '
                . 'implementadas para identificar hallazgos y brechas, decidir el tipo de ajuste y diseñar la experiencia de '
                . 'aprendizaje de continuidad. Utilizas como insumos obligatorios el diseño instruccional validado de la '
                . 'experiencia de aprendizaje, el plan formativo asociado a ese diseño y la ficha de registro de la '
                . 'implementación (evaluación durante) que la persona facilitadora completa en línea y que tú validas en la '
                . 'Etapa 0 mediante el checklist de validación. El perfil ocupacional es un insumo opcional. Tu comportamiento '
                . 'es estricto, estructurado, basado en evidencia y orientado a la mejora continua.',
            '',
            'TAREA: aplica la ETAPA 0, VALIDACIÓN DE LA FICHA DE REGISTRO, a la ficha de registro de la implementación que '
                . 'viene más abajo. Aplica el checklist completo, sin omitir secciones ni ítems. No completes la ficha en lugar '
                . 'de la persona facilitadora, no analices la implementación y no avances a otras etapas: solo evalúas lo que '
                . 'la persona registró.',
            'La ficha ya pasó el control de entrada: todos sus campos obligatorios tienen respuesta. Completar un campo no '
                . 'implica aprobarlo: su correspondencia con el diseño instruccional validado y la consistencia de los datos se '
                . 'evalúan en los ítems CRÍTICO del checklist. El checklist valida la calidad del registro, no la fidelidad de la '
                . 'implementación.',
            '',
            'Para cada ítem: evalúalo como "cumple" (✔ Cumple), "parcial" (⚠ Cumple parcialmente) o "no_cumple" (❌ No cumple). '
                . 'Registra una observación breve y específica cuando la evaluación sea "parcial" o "no_cumple", señalando el '
                . 'campo de la ficha, del diseño o del plan formativo que la origina. Cuando el ítem no es "cumple", agrega '
                . 'además una "propuesta": una mejora concreta, con la frase lista para copiar en la ficha e indicando en qué '
                . 'campo va. El texto de cada ítem y su condición de CRÍTICO los muestra la plataforma: no los transcribas.',
            '',
            'REGLAS DE GOBERNANZA:',
            '- La ficha de registro, el diseño instruccional validado y el plan formativo son la fuente principal. '
                . self::documents_rule($adjuntos),
            '- Los cambios en la implementación (criterios no observados y actividades, estrategias o instrumentos omitidos, '
                . 'modificados, sustituidos o incorporados) son decisiones de la persona facilitadora: se verifican como '
                . 'hallazgos declarados y nunca son, por sí mismos, motivo de rechazo de la ficha ni de detención del proceso.',
            '- Si detectas un cambio de implementación no declarado (por ejemplo, un criterio del diseño que no aparece ni en '
                . '2.1 ni en 2.2), márcalo "parcial" en el ítem no crítico correspondiente y solicita la explicación en la '
                . 'observación.',
            '- No inventes aprendizajes esperados ni criterios de evaluación. Fundamenta todo juicio en evidencia concreta '
                . 'registrada en la ficha (datos, porcentajes, evidencias y comentarios), sin atribuir intenciones a la persona '
                . 'facilitadora.',
            '- Los comentarios de las valoraciones 3 (alto) y 4 (muy alto) sin cambios respecto del diseño y la subsección 4.2 '
                . 'son opcionales: si vienen vacíos, no es un vacío de información y no bajas por eso ningún ítem. Sí es '
                . 'obligatorio el comentario cuando hay cambios respecto del diseño.',
            '- Una valoración baja o un cambio declarado con su motivo no baja otros ítems: la sinceridad no se castiga '
                . 'mientras la ficha sea coherente consigo misma.',
            '- Los ítems c8_1 y c8_3 resumen lo ya evaluado: no los bajes por algo que ya señalaste en otro ítem; evalúalos '
                . 'solo sobre lo que sigue sin resolver.',
            '- Los ítems c1_3, c1_4, c4_1, c4_3 y c4_4 los calcula la plataforma con las VERIFICACIONES DE LA PLATAFORMA que '
                . 'vienen más abajo: en esos ítems repite el resultado de esa verificación. Úsalas también como datos para '
                . 'tus otros ítems: no recalcules.',
            self::NO_PEDAGOGIA,
            '- Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género.',
            '- No repitas en tus respuestas nombres de personas participantes: refiérete a ellas por su subgrupo o con "una '
                . 'persona participante".',
            '- Evalúas la ficha, no a la persona: no asignes notas ni puntajes.',
            '',
            implode("\n", self::knowledge_rules($conlibreria, $conbusqueda)),
            '',
            'CHECKLIST (id: ítem):',
            implode("\n", $checklist),
            '',
            implode("\n", self::critical_rule_lines()),
            '',
            guardrails::injection_clause(),
            '',
            'FORMATO DE RESPUESTA: únicamente un objeto JSON válido, sin texto antes ni después y sin bloques de código, con '
                . 'esta forma:',
            '{"items": {"c1_1": {"estado": "cumple|parcial|no_cumple", "observacion": "...", "propuesta": "frase lista para '
                . 'copiar (vacía si cumple)", "forma": false}, ... un elemento por cada id del checklist ...}, '
                . '"debilidades": ["principales debilidades, máximo 6"], '
                . '"sugerencias": ["sugerencias de mejora concretas para la ficha, máximo 6"]}',
        ]);
    }

    /**
     * Regla de decision del checklist y del Prompt Maestro del Modulo 3, para el prompt. Funcion pura.
     *
     * @return string[]
     */
    public static function critical_rule_lines(): array {
        return [
            'REGLA DE DECISIÓN DE LA ETAPA 0 (aplícala de forma explícita). Los aspectos críticos son los ítems marcados '
                . '[CRÍTICO]:',
            '- Trazabilidad con el diseño (c1_2, c1_4, c2_1, c2_2): el plan formativo, el módulo, la experiencia, los tiempos de '
                . 'referencia o el aprendizaje esperado no corresponden a los del diseño instruccional validado, o los criterios '
                . 'registrados no están transcritos tal como figuran en él.',
            '- Respaldo del desempeño (c4_1, c4_2): falta la fila de algún criterio en la tabla 4.1 o un criterio no tiene '
                . 'evidencias observables que respalden los conteos por nivel.',
            '- Consistencia de los datos de desempeño (c4_3): la suma de participantes por nivel no coincide con el número de '
                . 'presentes.',
            '- Coherencia general (c8_2): hay contradicciones profundas entre lo registrado en las distintas secciones de la '
                . 'ficha.',
            'Las diferencias entre lo diseñado y lo implementado no son motivo de rechazo: se registran como hallazgos para la '
                . 'Etapa 1.',
            'Aplicación:',
            '- ❌ en cualquier ítem CRÍTICO → NO APROBADO.',
            '- ⚠ en cualquier ítem CRÍTICO → se trata como NO APROBADO hasta que la ficha se corrija. Única excepción: '
                . 'diferencias de forma que no alteran el contenido del diseño validado (por ejemplo, errores de tipeo en la '
                . 'transcripción): evalúa ese ítem "parcial" con "forma": true y regístralo en la observación; permiten '
                . '«Aprobado con observaciones».',
            '- Sin problemas en ítems críticos, pero con ⚠ o ❌ en ítems no críticos → APROBADO CON OBSERVACIONES.',
            '- Todos los ítems en ✔ → APROBADO.',
            '- "forma" va true solo en un ítem crítico "parcial" por una diferencia de forma; en todos los demás, false.',
            '- La condición final la fija la plataforma con esta regla a partir de tus ítems: no la incluyas en tu respuesta.',
            '- Si el resultado es NO APROBADO, la "propuesta" de cada ítem crítico que no cumple indica el ajuste específico: '
                . 'la persona corrige la ficha en el formulario en línea y se vuelve a aplicar el checklist.',
        ];
    }

    /**
     * Lee la respuesta del modelo. Si falta un item o alguno no se entiende, el informe no sirve: no se completa a medias lo
     * que el checklist pide evaluar entero.
     *
     * @param string|null $texto
     * @return array|null items (id => estado, observacion, propuesta, forma?), debilidades y sugerencias.
     */
    public static function parse_report(?string $texto): ?array {
        $json = exante::decode_json($texto);
        if (!$json || !isset($json['items']) || !is_array($json['items'])) {
            return null;
        }
        $items = [];
        foreach (self::checklist_ids() as $id) {
            $item = $json['items'][$id] ?? null;
            if (!is_array($item)) {
                return null;
            }
            $estado = exante::normalize_status($item['estado'] ?? null);
            if ($estado === null) {
                return null;
            }
            $items[$id] = ['estado' => $estado, 'observacion' => self::short_text($item['observacion'] ?? '', 600),
                'propuesta' => $estado === self::CUMPLE ? '' : self::short_text($item['propuesta'] ?? '', 600)];
            if ($estado === self::PARCIAL && in_array($id, self::CRITICOS, true) && self::is_true($item['forma'] ?? false)) {
                $items[$id]['forma'] = true;
            }
        }
        return ['items' => $items, 'debilidades' => self::text_list($json['debilidades'] ?? []),
            'sugerencias' => self::text_list($json['sugerencias'] ?? [])];
    }

    /**
     * @param mixed $valor
     * @return bool
     */
    private static function is_true($valor): bool {
        if (is_bool($valor)) {
            return $valor;
        }
        return is_scalar($valor) && in_array(\core_text::strtolower(trim((string) $valor)), ['true', 'si', 'sí', '1'], true);
    }

    /**
     * @param mixed $valor
     * @param int $largo
     * @return string
     */
    private static function short_text($valor, int $largo): string {
        return is_scalar($valor) ? \core_text::substr(trim((string) $valor), 0, $largo) : '';
    }

    /**
     * @param mixed $lista
     * @return string[]
     */
    private static function text_list($lista): array {
        $salida = [];
        foreach ((array) $lista as $valor) {
            $t = self::short_text($valor, 600);
            if ($t !== '') {
                $salida[] = $t;
            }
        }
        return array_slice($salida, 0, 6);
    }

    /**
     * Aplica al informe del modelo lo que decide el codigo: los items POR_CALCULO que system_checks() resolvio reemplazan a
     * los del modelo, con su observacion y su propuesta. Funcion pura.
     *
     * @param array $items Items del informe (id => estado, observacion, propuesta).
     * @param array $calculo items de system_checks().
     * @return array Items con los resultados del codigo.
     */
    public static function apply_system_checks(array $items, array $calculo): array {
        foreach ($calculo as $id => $item) {
            if (!in_array($id, self::POR_CALCULO, true)) {
                continue;
            }
            $items[$id] = ['estado' => $item['estado'], 'observacion' => $item['observacion'],
                'propuesta' => $item['propuesta'], 'origen' => 'calculo'];
        }
        return $items;
    }

    /**
     * Items CRÍTICO que detienen la ficha: en ❌, o en ⚠ que no sea una diferencia de forma. Funcion pura.
     *
     * @param array $items id => ['estado' => ..., 'forma' => bool].
     * @return string[] ids, en el orden del checklist.
     */
    public static function critical_failures(array $items): array {
        $ids = [];
        foreach (self::checklist_ids() as $id) {
            if (!in_array($id, self::CRITICOS, true)) {
                continue;
            }
            $estado = $items[$id]['estado'] ?? self::NO_CUMPLE;
            if ($estado === self::NO_CUMPLE || ($estado === self::PARCIAL && empty($items[$id]['forma']))) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Que parte de la regla del cliente decidio la condicion, para mostrarla («aplicación explícita de la regla de
     * decisión»). Funcion pura.
     *
     * @param array $items id => ['estado' => ..., 'forma' => bool].
     * @return string critico_no (❌ en un critico), critico_parcial (⚠ en un critico), forma (solo diferencias de forma en
     *         criticos), no_criticos (⚠ o ❌ en items no criticos) o todo_cumple.
     */
    public static function decision_rule(array $items): string {
        foreach (self::critical_failures($items) as $id) {
            if (($items[$id]['estado'] ?? self::NO_CUMPLE) === self::NO_CUMPLE) {
                return 'critico_no';
            }
        }
        if (self::critical_failures($items)) {
            return 'critico_parcial';
        }
        $forma = false;
        $otros = false;
        foreach (self::checklist_ids() as $id) {
            if (($items[$id]['estado'] ?? self::NO_CUMPLE) === self::CUMPLE) {
                continue;
            }
            if (in_array($id, self::CRITICOS, true)) {
                $forma = true;
            } else {
                $otros = true;
            }
        }
        return $otros ? 'no_criticos' : ($forma ? 'forma' : 'todo_cumple');
    }

    /**
     * Registro de observaciones no resueltas de la Etapa 0: items en ⚠ o ❌, con su observacion. Se usa en la Etapa 1 y en
     * la Etapa 7. Funcion pura.
     *
     * @param array $items id => ['estado' => ..., 'observacion' => ...].
     * @return array id => ['seccion' => int, 'texto' => string, 'estado' => string, 'observacion' => string].
     */
    public static function unresolved_items(array $items): array {
        $salida = [];
        foreach (self::checklist() as $n => $seccion) {
            foreach ($seccion['items'] as $id => $texto) {
                $estado = $items[$id]['estado'] ?? null;
                if ($estado === null || $estado === self::CUMPLE) {
                    continue;
                }
                $salida[$id] = ['seccion' => $n, 'texto' => $texto, 'estado' => $estado,
                    'observacion' => trim((string) ($items[$id]['observacion'] ?? ''))];
            }
        }
        return $salida;
    }

    /**
     * Condicion final, con la regla del cliente solo sobre los items:
     *  - ❌ en un item CRÍTICO, o ⚠ que no sea una diferencia de forma: «No aprobado».
     *  - Sin problemas en los criticos, pero con ⚠ o ❌ en otro item (o una diferencia de forma): «Aprobado con observaciones».
     *  - Todos los items en ✔: «Aprobado».
     *
     * @param array $items id => ['estado' => ..., 'forma' => bool].
     * @return string
     */
    public static function decide(array $items): string {
        if (self::critical_failures($items)) {
            return self::NO_APROBADO;
        }
        foreach (self::checklist_ids() as $id) {
            if (($items[$id]['estado'] ?? self::NO_CUMPLE) !== self::CUMPLE) {
                return self::OBSERVACIONES;
            }
        }
        return self::APROBADO;
    }

    /**
     * Si la condicion permite pasar a la Fase 2.
     *
     * @param string|null $condicion
     * @return bool
     */
    public static function can_advance(?string $condicion): bool {
        return in_array($condicion, [self::APROBADO, self::OBSERVACIONES], true);
    }

    /**
     * Resumen de un informe de validacion para los productos de las etapas: condicion, items sin resolver y tiempo. Funcion
     * pura.
     *
     * @param string $condicion
     * @param array $informe Informe de la validacion (items, debilidades, sugerencias).
     * @return string
     */
    public static function report_summary(string $condicion, array $informe): string {
        $nombres = [self::APROBADO => '✔ Aprobado', self::OBSERVACIONES => '⚠ Aprobado con observaciones',
            self::NO_APROBADO => '❌ No aprobado'];
        $l = ['RESULTADO DE LA VALIDACIÓN DE LA FICHA (ETAPA 0): ' . ($nombres[$condicion] ?? $condicion) . '.'];
        $pend = self::unresolved_items((array) ($informe['items'] ?? []));
        if ($pend) {
            $l[] = 'Registro de observaciones no resueltas de la Etapa 0 (ítems ⚠ o ❌):';
            foreach ($pend as $id => $p) {
                $l[] = '- Sección ' . $p['seccion'] . ', ' . $id . ' «' . $p['texto'] . '»: '
                    . ($p['estado'] === self::NO_CUMPLE ? 'no cumple' : 'cumple parcialmente')
                    . ($p['observacion'] !== '' ? ' — ' . $p['observacion'] : '');
            }
        } else {
            $l[] = 'La validación de la ficha (Etapa 0) no dejó observaciones no resueltas.';
        }
        foreach (array_merge((array) ($informe['debilidades'] ?? []), (array) ($informe['sugerencias'] ?? [])) as $o) {
            $l[] = '- ' . $o;
        }
        return implode("\n", $l);
    }

    // ------------------------------------------------------------------------------------------
    // Los diseños del Modulo 2 (insumo de la ficha).
    // ------------------------------------------------------------------------------------------

    /**
     * Si un diseño del Modulo 2 se puede evaluar: el diseño tiene sus seis etapas aceptadas y su plan formativo asociado.
     * Prompt Maestro: «si el diseño seleccionado no está disponible o no tiene asociado el plan formativo, infórmalo y no
     * inicies el proceso». Funcion pura.
     *
     * @param int[] $aceptadas Etapas aceptadas del diseño.
     * @param bool $conplan Si tiene el plan formativo asociado.
     * @return array [disponible (bool), motivo (string: '', sinplan o incompleto)].
     */
    public static function design_availability(array $aceptadas, bool $conplan): array {
        if (count(array_intersect(range(1, 6), $aceptadas)) < 6) {
            return [false, 'incompleto'];
        }
        return $conplan ? [true, ''] : [false, 'sinplan'];
    }

    /**
     * Los diseños del Modulo 2 de la persona, en cualquier curso, con su disponibilidad.
     *
     * @param int $userid
     * @return array[] Una entrada por diseño (el mas reciente primero): ficha (stdClass), nombre, disponible, motivo,
     *         aprobado (bool), puntaje (int|null), curso (id del curso del Modulo 2).
     */
    public static function list_designs(int $userid): array {
        global $DB;
        $salida = [];
        foreach ($DB->get_records('local_chatbot_exante', ['userid' => $userid], 'timemodified DESC, id DESC') as $ficha) {
            $ficha->data = exante::clean((array) json_decode((string) $ficha->data, true));
            $estado = ruta_m2::state_for($ficha);
            [$disponible, $motivo] = self::design_availability($estado['aceptadas'],
                isset(exante::attachments((int) $ficha->id)['exante_plan']));
            $salida[] = ['ficha' => $ficha, 'nombre' => exante::design_label($ficha->data, $estado['experiencia']),
                'disponible' => $disponible, 'motivo' => $motivo, 'aprobado' => !empty($estado['aprobado']),
                'puntaje' => isset($estado['evaluacion']['total']) ? (int) $estado['evaluacion']['total'] : null,
                'curso' => (int) $ficha->courseid];
        }
        return $salida;
    }

    /**
     * Un diseño del Modulo 2 de la persona, con lo que la ficha del Modulo 3 necesita de el.
     *
     * @param int $fichaid Ficha de diagnostico del Modulo 2.
     * @param int $userid
     * @return array|null ficha (stdClass con data), filas (etapa => fila), referencia (design_reference()), etapas (numero
     *         => producto aceptado en texto), huella, disponible, motivo; null si no es de la persona.
     */
    public static function get_design(int $fichaid, int $userid): ?array {
        $ficha = exante::get_ficha_by_id($fichaid, $userid);
        if (!$ficha) {
            return null;
        }
        $filas = diseno::get_by_ficha($fichaid);
        $etapas = [];
        foreach (array_keys(diseno::etapas()) as $n) {
            if (!empty($filas[$n]) && $filas[$n]->estado === diseno::ACEPTADO && trim((string) $filas[$n]->contenido) !== '') {
                $etapas[$n] = html_to_text((string) $filas[$n]->contenido, 0, false);
            }
        }
        $experiencia = isset($etapas[2]) ? diseno::experience_name($etapas[2]) : '';
        $aceptadas = array_keys($etapas);
        [$disponible, $motivo] = self::design_availability($aceptadas, isset(exante::attachments($fichaid)['exante_plan']));
        return ['ficha' => $ficha, 'filas' => $filas, 'referencia' => self::design_reference($ficha->data, $experiencia),
            'etapas' => $etapas, 'huella' => self::design_fingerprint($ficha->data, $etapas), 'disponible' => $disponible,
            'motivo' => $motivo];
    }

    // ------------------------------------------------------------------------------------------
    // Base de datos.
    // ------------------------------------------------------------------------------------------

    /**
     * La ficha en uso de la persona en el curso.
     *
     * Como en el Modulo 2, una persona puede evaluar varias experiencias en el mismo curso: cada una es una ficha con su
     * validacion y sus etapas; una esta en uso (activa = 1) y es la que muestran todas las paginas.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|null Con data ya decodificada.
     */
    public static function get_ficha(int $userid, int $courseid): ?\stdClass {
        global $DB;
        $filas = $DB->get_records('local_chatbot_impl', ['userid' => $userid, 'courseid' => $courseid],
            'activa DESC, id DESC', '*', 0, 1);
        if (!$filas) {
            return null;
        }
        $ficha = reset($filas);
        $ficha->data = self::clean((array) json_decode((string) $ficha->data, true));
        return $ficha;
    }

    /**
     * Ficha por id, solo si es de la persona.
     *
     * @param int $fichaid
     * @param int $userid
     * @return \stdClass|null
     */
    public static function get_ficha_by_id(int $fichaid, int $userid): ?\stdClass {
        global $DB;
        $ficha = $DB->get_record('local_chatbot_impl', ['id' => $fichaid, 'userid' => $userid]);
        if (!$ficha) {
            return null;
        }
        $ficha->data = self::clean((array) json_decode((string) $ficha->data, true));
        return $ficha;
    }

    /**
     * Todas las evaluaciones de la persona en el curso, la mas reciente primero.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass[]
     */
    public static function list_fichas(int $userid, int $courseid): array {
        global $DB;
        $fichas = $DB->get_records('local_chatbot_impl', ['userid' => $userid, 'courseid' => $courseid], 'id DESC');
        foreach ($fichas as $ficha) {
            $ficha->data = self::clean((array) json_decode((string) $ficha->data, true));
        }
        return $fichas;
    }

    /**
     * Guarda la ficha en uso (la crea si la persona no tiene ninguna) y el diseño elegido.
     *
     * @param int $userid
     * @param int $courseid
     * @param array $data Ficha ya limpia.
     * @param int|null $disenoid Diseño del Modulo 2 elegido; null deja el que ya tenia.
     * @return int id de la ficha.
     */
    public static function save_ficha(int $userid, int $courseid, array $data, ?int $disenoid = null): int {
        global $DB;
        $now = time();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $actual = self::get_ficha($userid, $courseid);
        if ($actual) {
            $campos = ['id' => $actual->id, 'data' => $json, 'activa' => 1, 'timemodified' => $now];
            if ($disenoid !== null) {
                $campos['disenoid'] = $disenoid;
            }
            $DB->update_record('local_chatbot_impl', (object) $campos);
            return (int) $actual->id;
        }
        return (int) $DB->insert_record('local_chatbot_impl', (object) ['userid' => $userid, 'courseid' => $courseid,
            'disenoid' => (int) $disenoid, 'data' => $json, 'activa' => 1, 'timecreated' => $now, 'timemodified' => $now]);
    }

    /**
     * Deja en uso una evaluacion de la persona (las demas quedan guardadas, sin borrarse).
     *
     * @param int $userid
     * @param int $courseid
     * @param int $fichaid
     * @return bool false si la ficha no es de la persona en ese curso.
     */
    public static function activate(int $userid, int $courseid, int $fichaid): bool {
        global $DB;
        if (!$DB->record_exists('local_chatbot_impl', ['id' => $fichaid, 'userid' => $userid, 'courseid' => $courseid])) {
            return false;
        }
        $DB->set_field('local_chatbot_impl', 'activa', 0, ['userid' => $userid, 'courseid' => $courseid]);
        $DB->set_field('local_chatbot_impl', 'activa', 1, ['id' => $fichaid]);
        return true;
    }

    /**
     * Borra una evaluacion de la persona (una sola): su ficha, sus validaciones y sus etapas. Las demas no se tocan. Si era
     * la que estaba en uso, pasa a usarse la mas reciente de las que quedan. No se borra mientras se esta validando.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $fichaid
     * @return string DELETED, MISSING o VALIDATING.
     */
    public static function delete_ficha(int $userid, int $courseid, int $fichaid): string {
        global $DB;
        $ficha = $DB->get_record('local_chatbot_impl', ['id' => $fichaid, 'userid' => $userid, 'courseid' => $courseid]);
        if (!$ficha) {
            return self::MISSING;
        }
        $ultima = self::latest_validation_for($fichaid);
        if ($ultima && in_array($ultima->estado, [self::PENDIENTE, self::PROCESANDO], true)) {
            return self::VALIDATING;
        }
        $ids = array_keys($DB->get_records('local_chatbot_impl', ['userid' => $userid, 'courseid' => $courseid], '', 'id'));
        $siguiente = exante::next_in_use($ids, $fichaid, (int) $ficha->activa === 1 ? $fichaid : 0);

        $tx = $DB->start_delegated_transaction();
        $DB->delete_records('local_chatbot_calidad', ['implid' => $fichaid]);
        $DB->delete_records('local_chatbot_impl_val', ['fichaid' => $fichaid]);
        $DB->delete_records('local_chatbot_impl', ['id' => $fichaid]);
        $tx->allow_commit();

        if ($siguiente !== null && (int) $ficha->activa === 1) {
            self::activate($userid, $courseid, $siguiente);
        }
        return self::DELETED;
    }

    /**
     * Empieza una evaluacion nueva y la deja en uso. Con $copiar, parte de los datos de la que estaba en uso (mismo grupo y
     * mismo diseño), sin su validacion ni sus etapas, que son de cada experiencia.
     *
     * @param int $userid
     * @param int $courseid
     * @param bool $copiar
     * @return int id de la ficha nueva.
     */
    public static function new_ficha(int $userid, int $courseid, bool $copiar): int {
        global $DB;
        $base = $copiar ? self::get_ficha($userid, $courseid) : null;
        $data = $base ? $base->data : self::clean([]);
        $now = time();
        $DB->set_field('local_chatbot_impl', 'activa', 0, ['userid' => $userid, 'courseid' => $courseid]);
        return (int) $DB->insert_record('local_chatbot_impl', (object) ['userid' => $userid, 'courseid' => $courseid,
            'disenoid' => $base ? (int) $base->disenoid : 0, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'activa' => 1, 'timecreated' => $now, 'timemodified' => $now]);
    }

    /**
     * Nombre con que se reconoce una evaluacion en la lista: el de la experiencia, si no el aprendizaje esperado. Funcion
     * pura.
     *
     * @param array $data Ficha ya limpia.
     * @return string
     */
    public static function evaluation_label(array $data): string {
        foreach ([(string) ($data['experiencia'] ?? ''), (string) ($data['aprendizaje'] ?? ''), (string) ($data['plan'] ?? '')] as $texto) {
            $texto = trim(preg_replace('/\s+/u', ' ', $texto));
            if ($texto !== '') {
                return \core_text::strlen($texto) > 90 ? rtrim(\core_text::substr($texto, 0, 87)) . '…' : $texto;
            }
        }
        return '';
    }

    /**
     * Huella de una ficha guardada con el contenido de su diseño elegido.
     *
     * @param \stdClass $ficha
     * @return string
     */
    public static function ficha_fingerprint(\stdClass $ficha): string {
        $diseno = (int) $ficha->disenoid > 0 ? self::get_design((int) $ficha->disenoid, (int) $ficha->userid) : null;
        return self::fingerprint($ficha->data, (int) $ficha->disenoid, $diseno ? $diseno['huella'] : '');
    }

    /**
     * Deja una validacion pendiente; si ya hay una en curso para la ficha, devuelve esa.
     *
     * @param \stdClass $ficha
     * @return int id de la validacion.
     */
    public static function start_validation(\stdClass $ficha): int {
        global $DB;
        $encurso = $DB->get_records_select('local_chatbot_impl_val',
            'fichaid = ? AND estado IN (?, ?)', [$ficha->id, self::PENDIENTE, self::PROCESANDO], 'id DESC', 'id', 0, 1);
        if ($encurso) {
            return (int) reset($encurso)->id;
        }
        $now = time();
        return (int) $DB->insert_record('local_chatbot_impl_val', (object) [
            'fichaid' => $ficha->id, 'userid' => $ficha->userid, 'courseid' => $ficha->courseid,
            'disenoid' => (int) $ficha->disenoid, 'estado' => self::PENDIENTE, 'huella' => self::ficha_fingerprint($ficha),
            'snapshot' => json_encode($ficha->data, JSON_UNESCAPED_UNICODE), 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /**
     * Ultima validacion de la evaluacion en uso de la persona en el curso.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|null Con informe decodificado.
     */
    public static function latest_validation(int $userid, int $courseid): ?\stdClass {
        $ficha = self::get_ficha($userid, $courseid);
        return $ficha ? self::latest_validation_for((int) $ficha->id) : null;
    }

    /**
     * Ultima validacion de una ficha. Una que quedo procesando demasiado se da por caida.
     *
     * @param int $fichaid
     * @return \stdClass|null Con informe decodificado.
     */
    public static function latest_validation_for(int $fichaid): ?\stdClass {
        global $DB;
        $filas = $DB->get_records('local_chatbot_impl_val', ['fichaid' => $fichaid], 'id DESC', '*', 0, 1);
        if (!$filas) {
            return null;
        }
        $val = reset($filas);
        if ($val->estado === self::PROCESANDO && $val->timemodified < time() - self::PROCESO_MAX) {
            $val->estado = self::ERROR;
            $val->error = 'tiempo';
            $DB->update_record('local_chatbot_impl_val', (object) ['id' => $val->id, 'estado' => $val->estado,
                'error' => $val->error, 'timemodified' => time()]);
        }
        $val->informe = $val->informe ? json_decode((string) $val->informe, true) : null;
        return $val;
    }

    /**
     * Ejecuta una validacion pendiente: arma el pedido, llama al modelo y guarda el informe.
     *
     * Corre una sola vez aunque la pagina la pida dos veces (un candado por validacion y el estado).
     *
     * @param int $valid
     * @param int $userid Solo su duena puede ejecutarla.
     * @return string Estado final.
     */
    public static function run_validation(int $valid, int $userid): string {
        global $DB;
        $candado = \core\lock\lock_config::get_lock_factory('local_chatbot_impl')->get_lock('val' . $valid, 0);
        if (!$candado) {
            return self::PROCESANDO;
        }
        try {
            $val = $DB->get_record('local_chatbot_impl_val', ['id' => $valid, 'userid' => $userid]);
            if (!$val || $val->estado !== self::PENDIENTE) {
                return $val ? $val->estado : self::ERROR;
            }
            $DB->update_record('local_chatbot_impl_val', (object) ['id' => $valid, 'estado' => self::PROCESANDO,
                'timemodified' => time()]);
            [$estado, $campos] = self::validate($val);
            $DB->update_record('local_chatbot_impl_val', (object) (['id' => $valid, 'estado' => $estado,
                'timemodified' => time()] + $campos));
            return $estado;
        } finally {
            $candado->release();
        }
    }

    /**
     * La validacion que habilita la Fase 2: la ultima, lista, Aprobada o Aprobada con observaciones, de la ficha tal como
     * esta hoy y con el diseño elegido disponible y sin cambios.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function fase1_ok(int $userid, int $courseid): ?\stdClass {
        $ficha = self::get_ficha($userid, $courseid);
        $val = self::latest_validation($userid, $courseid);
        if (!$ficha || !$val || $val->estado !== self::LISTO || !self::can_advance($val->condicion)
                || self::criteria_count($ficha->data) === 0 || $val->huella !== self::ficha_fingerprint($ficha)) {
            return null;
        }
        // Prompt Maestro: «No inicies el proceso sin el diseño seleccionado, su plan formativo y la ficha enviada».
        $diseno = (int) $ficha->disenoid > 0 ? self::get_design((int) $ficha->disenoid, (int) $ficha->userid) : null;
        return ($diseno && $diseno['disponible']) ? $val : null;
    }

    /**
     * Llama al modelo con la ficha de la validacion, el diseño elegido y sus documentos.
     *
     * @param \stdClass $val
     * @return array [estado, campos a guardar].
     */
    private static function validate(\stdClass $val): array {
        if (!ai\provider_factory::is_configured()) {
            return [self::ERROR, ['error' => 'sinclave']];
        }
        $data = self::clean((array) json_decode((string) $val->snapshot, true));
        $diseno = self::get_design((int) $val->disenoid, (int) $val->userid);
        if (!$diseno || !$diseno['disponible']) {
            return [self::ERROR, ['error' => 'sindiseno']];
        }
        $ref = $diseno['referencia'];

        // El plan formativo y el perfil ocupacional se leen del diseño elegido: no se vuelven a cargar.
        [$partes, , $nombres] = exante::attachment_parts((int) $val->disenoid);

        $calculo = self::system_checks($data, $ref);
        $tiempo = self::times($data);
        $hechos = array_merge($calculo['hechos'], self::reference_facts($data, $ref),
            ['Tiempos de la ficha (1.1): total ' . ($tiempo['total'] ?? '—') . ' min; asignado ' . ($tiempo['asignado'] ?? '—')
                . ' min; utilizado ' . ($tiempo['utilizado'] ?? '—') . ' min; tiempo restante (total − utilizado) '
                . ($tiempo['restante'] ?? '—') . ' min.']);
        $fichatexto = pseudonymizer::scrub(self::to_text($data));
        $libreria = self::role_library('m3f1', (string) ($diseno['ficha']->data['nivel'] ?? ''));
        $buscar = exante::web_search_available();
        $prompt = self::build_prompt(array_keys($nombres), $libreria !== '', $buscar)
            . "\n\nVERIFICACIONES DE LA PLATAFORMA (cálculos y comparaciones exactas; úsalos, no los recalcules):\n- "
            . implode("\n- ", $hechos)
            . "\n\n" . pseudonymizer::scrub(self::design_text($ref, array_intersect_key($diseno['etapas'], [2 => 1, 3 => 1, 5 => 1])))
            . "\n\nFICHA DE REGISTRO DE LA IMPLEMENTACIÓN:\n" . guardrails::isolate_user_content($fichatexto);
        $contenido = [['role' => 'user', 'parts' => self::request_parts($prompt, $partes, $libreria, ux::on('libreriaruta'))]];

        $modelo = ai\provider_factory::get_configured_model();
        $informe = null;
        $fuentes = [];
        // Un segundo intento solo si la respuesta no se pudo leer: el checklist va entero o no va. El segundo va sin
        // busqueda web, por si la respuesta con fuentes no vino como JSON limpio.
        for ($intento = 0; $intento < 2 && $informe === null; $intento++) {
            $resultado = exante::generate_steady($contenido, 150, $modelo, $buscar && $intento === 0);
            if (isset($resultado['error'])) {
                return [self::ERROR, ['error' => 'servicio']];
            }
            $informe = self::parse_report(gemini_client::extract_text($resultado));
            $fuentes = gemini_client::extract_sources($resultado);
        }
        if ($informe === null) {
            return [self::ERROR, ['error' => 'ilegible']];
        }

        $informe['items'] = self::apply_system_checks($informe['items'], $calculo['items']);
        $informe['adjuntos'] = $nombres;
        $informe['fuentes_web'] = array_slice($fuentes, 0, 8);
        $informe['libreria'] = $libreria !== '' ? kb_store::context_version(kb_store::AMBITO_M3) : '';
        $informe['regla'] = self::decision_rule($informe['items']);
        $informe['tiempo'] = $tiempo;
        $informe['verificaciones'] = $hechos;
        return [self::LISTO, [
            'condicion' => self::decide($informe['items']),
            'informe' => json_encode($informe, JSON_UNESCAPED_UNICODE),
            'modelo' => (string) $modelo,
            'promptversion' => self::PROMPT_VERSION,
            'error' => null,
        ]];
    }
}
