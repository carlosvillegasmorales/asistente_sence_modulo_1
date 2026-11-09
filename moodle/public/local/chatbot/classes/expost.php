<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/kb_router.php');

/**
 * Modulo 3, Fase 4: evaluacion ex post, el cierre del ciclo formativo.
 *
 * Insumos: el «Prompt Complementario Fase 4 - Evaluacion Ex Post» y los documentos de conocimientos de Interfases (07-11-2026):
 * la ficha de evaluacion ex post, su guia y su ejemplo de llenado, y el checklist de validacion. El prompt complementa el
 * Prompt Maestro del Asistente de Aseguramiento de la Calidad de los Aprendizajes y no modifica las Fases 1 a 3.
 *
 * Etapas (numeradas a continuacion de las ocho del Prompt Maestro):
 *   9  Preparacion de la ficha ex post (precarga): los datos de las fases anteriores pasan solos al formulario.
 *   10 Validacion de la ficha ex post con el checklist (25 items en 8 secciones, 3 CRITICO).
 *   11 Resultados del ciclo formativo.      12 Analisis metacognitivo del proceso.
 *   13 Sistematizacion de practicas exitosas.  14 Recomendaciones para una nueva ejecucion.
 *   15 Consolidacion en un documento Word unico.
 *
 * Como en las otras fases, lo que es calculo no queda en manos del modelo: el logro final, el principio de no regresion, los
 * pendientes, la cobertura evaluativa y los movimientos de nivel los calcula el codigo (rows(), results(), system_checks()) y
 * se entregan al modelo como datos. La condicion de la validacion la fija decide() con la regla del cliente.
 *
 * Lo que no lleva prefijo de BD es funcion pura y lo ejercita cli/verificar_expost.php.
 */
class expost {

    /** @var string Version del prompt: se guarda con cada informe y producto (RU-PRIV-006). */
    const PROMPT_VERSION = 'M3-F4-expost-v1';

    /** Etapas de la Fase 4. */
    const ETAPA_PRECARGA = 9;
    const ETAPA_VALIDACION = 10;
    const CONSOLIDACION = 15;

    /** @var int[] Etapas que son hojas de trabajo (producto de la IA, editable y aceptable). */
    const ETAPAS = [11, 12, 13, 14];

    /** @var int Tope de caracteres de cada producto previo que viaja al modelo. */
    const PREVIA_MAX = 14000;

    /** @var int Segundos que puede tardar la consolidacion con el asistente. */
    const CONSOLIDACION_MAX = 900;

    /** @var int Criterios que la tabla 2.1 admite (el diseño puede traer mas de los 12 del formulario impreso). */
    const CRITERIOS_MAX = 20;

    /** @var int Etiquetas precargadas por tabla (prompt: «máximo 4 etiquetas por tabla») y palabras por etiqueta. */
    const MAX_ETIQUETAS = 4;
    const MAX_PALABRAS = 10;

    /** @var int Filas libres de las tablas 3.1 y 3.2 (la ficha trae dos). */
    const FILAS_LIBRES = 2;

    /** @var float Diferencia aceptada entre el logro declarado y el calculado (el redondeo de la persona). */
    const LOGRO_TOLERANCIA = 1.0;

    /** @var string[] Items marcados CRÍTICO: suma por nivel, no regresion y coherencia general. */
    const CRITICOS = ['c2_1', 'c2_2', 'c8_1'];

    /** @var string[] Items que resuelve el codigo con los datos de la ficha (system_checks()), no el modelo. */
    const POR_CALCULO = ['c1_2', 'c2_1', 'c2_2', 'c2_3', 'c2_4', 'c5_2'];

    /** Tipos de campo: los de la ficha de implementacion, mas la casilla de verificacion. */
    const T_CHECK = 'check';

    /** Respuesta de un pendiente (2.2) y de la oportunidad de transferencia (5.1). */
    const SI = 'si';
    const PARCIAL = 'parcial';
    const NO = 'no';

    /** @var string Respuesta textual del prompt cuando no se cumple la condicion de activacion. */
    const ACTIVACION_MENSAJE = 'La evaluación ex post se realiza al cierre del ciclo formativo. Para iniciarla, deben estar '
        . 'completas las Fases 1 a 3 y debe haberse implementado la experiencia de aprendizaje de continuidad.';

    /**
     * Bienvenida de la Fase 4, textual del prompt («debes mostrar exactamente el siguiente mensaje»).
     *
     * @return string[] Los parrafos.
     */
    public static function welcome(): array {
        return [
            'Bienvenido/a a la evaluación ex post. Cerraremos el ciclo formativo de tu experiencia de aprendizaje: analizaremos '
                . 'los resultados finales, la efectividad de las estrategias, la reacción de las personas participantes y la '
                . 'transferencia al trabajo, y elaboraremos un análisis metacognitivo del proceso con recomendaciones para una '
                . 'nueva ejecución del curso.',
            'Ya preparé tu ficha de evaluación ex post con los datos de las fases anteriores. Complétala en línea; puedes '
                . 'apoyarte en la guía de llenado y en el ejemplo. Cuando la envíes, la revisaré y validaré antes de iniciar el '
                . 'análisis.',
        ];
    }

    // ------------------------------------------------------------------------------------------
    // Las etapas.
    // ------------------------------------------------------------------------------------------

    /**
     * Las etapas 11 a 14 con lo que el prompt pide que incluya cada una.
     *
     * @return array numero => ['titulo', 'archivo', 'incluye' (string[]), 'comprobar' (string[]), 'indicaciones' (string[],
     *         solo para la IA), 'nota' (string), 'previas' (int[]: etapas de la Fase 2 y 3 que viajan al modelo)].
     */
    public static function etapas(): array {
        return [
            11 => ['titulo' => 'Resultados del ciclo formativo', 'archivo' => 'Etapa_11_Resultados_del_ciclo',
                'incluye' => [
                    'Tabla de logro por criterio: logro inicial, logro final y variación en puntos porcentuales; los pendientes '
                        . 'marcados «Parcialmente» o «No» se informan como pendientes que persisten',
                    'Logro promedio inicial y final de los criterios trabajados en la experiencia inicial, y logro de los '
                        . 'pendientes resueltos',
                    'Cobertura evaluativa al cierre, comparada con la cobertura de la experiencia inicial',
                    'Movimientos de nivel por criterio: nivelación (de Insuficiente o Básico a Adecuado o Avanzado, sobre el '
                        . 'total de personas en nivelación) y profundización (de Adecuado a Avanzado)',
                    'Efectividad de las estrategias didácticas y evaluativas (sección 3), contrastada con los resultados de logro',
                    'Reacción de las personas participantes (sección 4), con sus datos de respaldo',
                    'Transferencia al contexto laboral (sección 5), o el motivo por el que no hubo oportunidad',
                    'Efectividad de los ajustes (sección 6), contrastada con los movimientos de nivel, y buenas prácticas '
                        . 'confirmadas y descartadas',
                ],
                'comprobar' => ['Tabla de logro por criterio', 'Logro promedio', 'Cobertura evaluativa', 'Movimientos de nivel',
                    'Efectividad de las estrategias', 'Reacción de las personas participantes', 'Transferencia',
                    'Efectividad de los ajustes'],
                'indicaciones' => [
                    'Los datos numéricos (logro inicial y final por criterio, variación, promedios, cobertura evaluativa y '
                        . 'movimientos de nivel) los calcula la plataforma y vienen en «DATOS CALCULADOS POR LA PLATAFORMA»: '
                        . 'transcríbelos sin recalcularlos.',
                    'Los movimientos de nivel se estiman con los totales por nivel (la ficha no registra a cada persona): '
                        . 'preséntalos como lo entrega la plataforma, con esa aclaración.',
                    'Contrasta cada valoración de la sección 3 con los resultados de logro de los criterios en que se usó la '
                        . 'estrategia o el instrumento, y señala si la evidencia la respalda.',
                    'Como el logro final nunca es menor que el inicial, valora la efectividad de los ajustes por los cambios de '
                        . 'nivel de las personas, no solo por el aumento del logro.',
                    'Los resultados desfavorables (logros bajos, reacciones negativas, ausencia de transferencia o buenas '
                        . 'prácticas descartadas) son hallazgos valiosos: preséntalos con la misma claridad que los favorables.',
                    'La transferencia solo se analiza si hubo oportunidad de aplicar lo aprendido; si no la hubo, informa el motivo.',
                ],
                'nota' => '', 'previas' => [3, 4, 5, 6]],
            12 => ['titulo' => 'Análisis metacognitivo del proceso', 'archivo' => 'Etapa_12_Analisis_metacognitivo',
                'incluye' => [
                    'Trayectoria del ciclo: tabla con cada momento (diseño, implementación, decisión del tipo de ajuste, '
                        . 'experiencia de continuidad y resultados), lo que ocurrió y su efecto en los aprendizajes',
                    'Análisis de las decisiones clave: para cada decisión relevante (de diseño, cambios de implementación, tipo '
                        . 'de ajuste y organización de las trayectorias), qué se decidió, con qué evidencia, qué resultado tuvo y '
                        . 'si resultó acertada, parcialmente acertada o no acertada, y por qué',
                    'Contraste entre la reflexión de la persona facilitadora (sección 7) y la evidencia del ciclo: coincidencias, '
                        . 'aspectos que la evidencia refuerza y aspectos que la reflexión no consideró',
                    'Lecciones aprendidas, organizadas en: diseño, implementación, evaluación y ajustes',
                    'Preguntas para la reflexión de la persona facilitadora: dos o tres preguntas abiertas que la inviten a '
                        . 'profundizar en su propia práctica a partir de los hallazgos del ciclo',
                ],
                'comprobar' => ['Trayectoria del ciclo', 'Análisis de las decisiones clave', 'Contraste entre la reflexión',
                    'Lecciones aprendidas', 'Preguntas para la reflexión'],
                'indicaciones' => [
                    'El análisis metacognitivo examina cómo se tomaron las decisiones a lo largo del ciclo y qué aprendizajes '
                        . 'deja el proceso, para la persona facilitadora y para la formación como sistema.',
                    'Usa un lenguaje respetuoso y constructivo, centrado en las decisiones y en la evidencia, no en juicios sobre '
                        . 'la persona.',
                    'Cada decisión se califica como acertada, parcialmente acertada o no acertada, con su porqué y la evidencia '
                        . 'del ciclo (datos de las Fases 1 a 3, de la ficha ex post y de los resultados calculados).',
                    'Las lecciones aprendidas van en cuatro grupos: diseño, implementación, evaluación y ajustes.',
                    'Las preguntas para la reflexión son dos o tres, abiertas, y parten de los hallazgos del ciclo.',
                ],
                'nota' => '', 'previas' => [1, 2, 3, 4, 5, 6, 7]],
            13 => ['titulo' => 'Sistematización de prácticas exitosas', 'archivo' => 'Etapa_13_Practicas_exitosas',
                'incluye' => [
                    'Nombre de la práctica',
                    'Descripción breve',
                    'Situación o problema que resolvió',
                    'Evidencia de su efectividad (datos del ciclo)',
                    'Condiciones que favorecieron su éxito',
                    'Cómo replicarla en otra ejecución',
                    'Precauciones o límites de su aplicación',
                    'Prácticas descartadas, informadas brevemente con el motivo del descarte',
                ],
                'comprobar' => ['Nombre de la práctica', 'Descripción breve', 'Situación o problema', 'Evidencia de su efectividad',
                    'Condiciones que favorecieron', 'Cómo replicarla', 'Precauciones', 'Prácticas descartadas'],
                'indicaciones' => [
                    'Sistematiza las buenas prácticas confirmadas en la subsección 6.4 y las prácticas que los resultados del '
                        . 'ciclo respaldan con claridad. Para cada práctica entrega, con estos rótulos en negrita, los siete '
                        . 'elementos de la lista (el último es «Precauciones o límites de su aplicación»).',
                    'Las prácticas descartadas se informan brevemente, con el motivo del descarte, en una sección propia '
                        . '«Prácticas descartadas»; si no hubo ninguna, dilo en una línea.',
                    'No incluyas como exitosa una práctica que la persona descartó en 6.4 ni una que los resultados no respalden.',
                ],
                'nota' => '', 'previas' => [5, 6, 7]],
            14 => ['titulo' => 'Recomendaciones para una nueva ejecución', 'archivo' => 'Etapa_14_Recomendaciones',
                'incluye' => [
                    'Diagnóstico: qué aspectos del grupo, del contexto y de los recursos conviene diagnosticar especialmente',
                    'Diseño: qué considerar en el diseño de la experiencia (tiempos, estrategias, materiales y evaluación), sin '
                        . 'elaborar un nuevo diseño',
                    'Implementación: qué cuidar durante la ejecución',
                    'Evaluación: ajustes a los instrumentos y a la retroalimentación',
                    'Seguimiento de la transferencia: cómo recoger información sobre la aplicación de lo aprendido en el trabajo',
                    'Evidencia del ciclo que fundamenta cada recomendación y su prioridad (alta, media o baja)',
                    'Recomendaciones de la persona facilitadora (pregunta 7.4), indicando si la evidencia las respalda',
                    'Cierre que señala que estas recomendaciones son insumos para el diagnóstico de una nueva ejecución y que '
                        . 'no reemplazan ese diagnóstico',
                ],
                'comprobar' => ['Diagnóstico', 'Diseño', 'Implementación', 'Evaluación', 'Seguimiento de la transferencia',
                    'Evidencia', 'Prioridad', 'Recomendaciones de la persona facilitadora'],
                'indicaciones' => [
                    'Organiza las recomendaciones por ámbito, en cinco secciones: Diagnóstico, Diseño, Implementación, Evaluación '
                        . 'y Seguimiento de la transferencia. En cada una, una tabla con las columnas «Recomendación», '
                        . '«Evidencia del ciclo» y «Prioridad» (alta, media o baja).',
                    'Las recomendaciones son insumos para el diagnóstico de una nueva ejecución. No elabores una nueva versión del '
                        . 'diseño: una nueva ejecución requiere un nuevo diagnóstico, porque el grupo y el contexto serán distintos.',
                    'Incluye las recomendaciones de la persona facilitadora (pregunta 7.4) en la sección «Recomendaciones de la '
                        . 'persona facilitadora», e indica para cada una si la evidencia del ciclo la respalda.',
                    'Termina el cuerpo con el cierre: «Estas recomendaciones son insumos para el diagnóstico de una nueva '
                        . 'ejecución y no reemplazan ese diagnóstico».',
                ],
                'nota' => '', 'previas' => [5, 6, 7]],
        ];
    }

    /**
     * La etapa 15: consolidacion de todos los entregables de la Fase 4 en un documento unico.
     *
     * @return array
     */
    public static function etapa_consolidacion(): array {
        return ['titulo' => 'Consolidación', 'archivo' => 'Informe_de_evaluacion_ex_post',
            'incluye' => ['Validación de la ficha ex post', 'Resultados del ciclo formativo', 'Análisis metacognitivo del proceso',
                'Prácticas exitosas sistematizadas', 'Recomendaciones para una nueva ejecución'],
            'nota' => 'Todos los entregables de la Fase 4, en extenso, en un documento único listo para descargar en Word. La '
                . 'consolidación no es editable: si quieres ajustar algo, hazlo en la etapa que corresponda.'];
    }

    /**
     * Las etapas con hoja de trabajo (9 y 10 son la ficha) y la consolidacion.
     *
     * @return array
     */
    public static function todas(): array {
        return self::etapas() + [self::CONSOLIDACION => self::etapa_consolidacion()];
    }

    /**
     * Titulos de las siete etapas de la Fase 4, para la barra y los avisos.
     *
     * @return string[]
     */
    public static function titulos(): array {
        $t = [self::ETAPA_PRECARGA => 'Preparación de la ficha ex post (precarga)',
            self::ETAPA_VALIDACION => 'Validación de la ficha ex post'];
        foreach (self::todas() as $n => $e) {
            $t[$n] = $e['titulo'];
        }
        return $t;
    }

    // ------------------------------------------------------------------------------------------
    // La ficha (documento del cliente, textual).
    // ------------------------------------------------------------------------------------------

    /**
     * Escala de las secciones 3 y 4 (femenina: «efectividad», «reacción») y de las secciones 5 y 6 (masculina: «grado»).
     *
     * @param bool $femenina
     * @return string[] clave => texto.
     */
    public static function scale(bool $femenina = true): array {
        return $femenina ? ['1' => '1 – Muy baja', '2' => '2 – Baja', '3' => '3 – Alta', '4' => '4 – Muy alta']
            : ['1' => '1 – Muy bajo', '2' => '2 – Bajo', '3' => '3 – Alto', '4' => '4 – Muy alto'];
    }

    /**
     * Respuestas de una pregunta con alternativas (sí / parcialmente / no).
     *
     * @return string[] clave => texto.
     */
    public static function answers(): array {
        return [self::SI => 'Sí', self::PARCIAL => 'Parcialmente', self::NO => 'No'];
    }

    /**
     * Secciones de la ficha con los campos que no dependen de los datos precargados. Las tablas 2.1, 2.2, 3.1, 3.2 y 6.4 se
     * arman con la precarga (rows(), labels()). Cada campo: id, tipo, num, label, ayuda (de la guia de llenado), opciones,
     * descriptores, subtitulo, antes, opcional y condicional (solo se pide si la precarga no trae el dato).
     *
     * @return array
     */
    public static function sections(): array {
        $f = self::scale(true);
        $m = self::scale(false);
        $valorado = static function(string $id, string $num, string $label, string $ayuda = '', bool $fem = true): array {
            return ['id' => $id, 'tipo' => implementacion::T_UNA, 'num' => $num, 'label' => $label,
                'opciones' => self::scale($fem), 'ayuda' => $ayuda];
        };
        return [
            1 => ['titulo' => 'Identificación general', 'campos' => [
                ['id' => 'exp_cont', 'tipo' => implementacion::T_TEXTO, 'label' => 'Nombre de la experiencia de aprendizaje de continuidad',
                    'condicional' => true,
                    'ayuda' => 'Se precarga desde la etapa 6 (experiencia de continuidad). Complétalo solo si no se pudo precargar.'],
                ['id' => 'fechas_cont', 'tipo' => implementacion::T_TEXTO, 'label' => 'Fecha(s) de la experiencia de continuidad',
                    'ayuda' => 'Fecha o fechas en que se implementó la experiencia de continuidad. Ejemplo: 29 de marzo de 2026.'],
                ['id' => 'presentes_cont', 'tipo' => implementacion::T_NUMERO,
                    'label' => 'N.º de participantes presentes en la experiencia de continuidad',
                    'ayuda' => 'Total de participantes que asistieron a la experiencia de continuidad. Ejemplo: 22 participantes.'],
                ['id' => 'evaluados', 'tipo' => implementacion::T_NUMERO, 'label' => 'N.º de participantes evaluados al cierre del ciclo',
                    'ayuda' => 'Participantes con al menos una evidencia de nivel en el ciclo. Es la base del logro final (sección 2). '
                        . 'Normalmente coincide con el número de presentes en la experiencia inicial, porque quienes no asistieron a '
                        . 'la experiencia de continuidad conservan el nivel que tenían. Ejemplo: 22 participantes.'],
            ]],
            2 => ['titulo' => 'Logro final de los aprendizajes', 'campos' => []],
            3 => ['titulo' => 'Efectividad de las estrategias didácticas y evaluativas', 'campos' => [
                $valorado('v331', '3.3.1', 'Las estrategias didácticas favorecieron el logro de los aprendizajes esperados'),
                $valorado('v332', '3.3.2', 'Las estrategias didácticas fueron adecuadas a las características del grupo'),
                $valorado('v333', '3.3.3', 'La evaluación permitió evidenciar el desempeño respecto de los criterios'),
                $valorado('v334', '3.3.4', 'La retroalimentación entregada orientó la mejora del desempeño'),
                $valorado('v335', '3.3.5', 'Los instrumentos evaluativos fueron claros y aplicables'),
                $valorado('v336', '3.3.6', 'La información evaluativa fue útil para decidir los ajustes'),
                ['id' => 'fund33', 'tipo' => implementacion::T_PARRAFO, 'nombre' => '3.3 Fundamentación', 'label' => 'Fundamentación (obligatorio)',
                    'ayuda' => 'Fundamente las valoraciones con datos concretos: logro alcanzado, evidencias, comentarios de las personas '
                        . 'participantes o situaciones observadas. Ejemplo: «Las estrategias favorecieron el logro: el promedio de '
                        . 'logro subió de 73 % a 94 % en los criterios trabajados inicialmente. La rúbrica del correo requiere '
                        . 'precisar el indicador de registro lingüístico».'],
            ]],
            4 => ['titulo' => 'Reacción de las personas participantes', 'campos' => [
                ['id' => 'f4_enc', 'tipo' => self::T_CHECK, 'num' => '4.1', 'subtitulo' => '4.1 Fuente de la información sobre la reacción de las personas participantes',
                    'antes' => 'Marque todas las que correspondan.', 'label' => 'Encuesta aplicada por la persona facilitadora'],
                ['id' => 'f4_obs', 'tipo' => self::T_CHECK, 'label' => 'Observación y comentarios del grupo'],
                ['id' => 'f4_otra', 'tipo' => self::T_CHECK, 'label' => 'Otra'],
                ['id' => 'f4_otra_txt', 'tipo' => implementacion::T_TEXTO, 'label' => 'Otra fuente (indique cuál)', 'condicional' => true],
                ['id' => 'n_enc', 'tipo' => implementacion::T_NUMERO, 'label' => 'N.º de participantes que respondieron la encuesta (si corresponde)',
                    'ayuda' => 'Si aplicó una encuesta, registre cuántas personas la respondieron. Ejemplo: 20 de 22 participantes.',
                    'condicional' => true],
                $valorado('v42', '4.2', 'Satisfacción general con la formación recibida', 'Valoración global de la formación recibida.'),
                $valorado('v43', '4.3', 'Pertinencia de la formación para el trabajo',
                    'Relación que las personas percibieron entre lo aprendido y su trabajo actual o futuro.'),
                $valorado('v44', '4.4', 'Actividades y metodología de trabajo',
                    'Valoración de la forma de trabajo, las actividades y la participación.'),
                $valorado('v45', '4.5', 'Materiales y recursos', 'Claridad, utilidad y disponibilidad de guías, ejemplos y recursos.'),
                $valorado('v46', '4.6', 'Organización y tiempos', 'Horarios, duración de las actividades y ritmo de trabajo.'),
                $valorado('v47', '4.7', 'Apoyo de la persona facilitadora',
                    'Disposición, claridad de las explicaciones y retroalimentación recibida.'),
                ['id' => 'datos4', 'tipo' => implementacion::T_PARRAFO, 'nombre' => '4 Datos que respaldan las valoraciones',
                    'label' => 'Datos que respaldan las valoraciones (obligatorio)',
                    'ayuda' => 'Resultados de la encuesta (promedios, porcentajes) o situaciones y comentarios observados que '
                        . 'fundamentan las valoraciones 4.2 a 4.7. Ejemplo: «Encuesta respondida por 20 de 22 participantes. '
                        . 'Satisfacción general: promedio 3,7 de 4; el 95 % recomendaría el curso. Organización y tiempos: 2,9; 7 '
                        . 'personas indicaron que la actividad de redacción fue muy breve».'],
                ['id' => 'mas48', 'tipo' => implementacion::T_PARRAFO, 'num' => '4.8',
                    'label' => 'Aspectos más valorados por las personas participantes (obligatorio)',
                    'ayuda' => 'Registre los aspectos que las personas destacaron, idealmente con sus propias palabras.'],
                ['id' => 'crit49', 'tipo' => implementacion::T_PARRAFO, 'num' => '4.9',
                    'label' => 'Críticas y sugerencias de las personas participantes (obligatorio)',
                    'ayuda' => 'Registre las críticas o sugerencias que formularon, idealmente con sus propias palabras.'],
            ]],
            5 => ['titulo' => 'Transferencia de los aprendizajes al contexto laboral', 'campos' => [
                ['id' => 'op51', 'tipo' => implementacion::T_UNA, 'num' => '5.1',
                    'label' => '¿Hubo oportunidad de aplicar los aprendizajes en un contexto laboral?', 'opciones' => self::answers(),
                    'descriptores' => [self::SI => 'la mayoría de las personas tuvo oportunidad de aplicar lo aprendido.',
                        self::PARCIAL => 'solo una parte del grupo tuvo oportunidad de aplicarlo.',
                        self::NO => 'no hubo oportunidad. Indique el motivo en el recuadro y continúe en la sección 6.']],
                ['id' => 'motivo5', 'tipo' => implementacion::T_PARRAFO, 'label' => 'Motivo (si respondió «No»)', 'condicional' => true,
                    'ayuda' => 'Si respondió «No», indique el motivo (por ejemplo, participantes sin empleo ni práctica laboral al '
                        . 'momento del seguimiento) y continúe en la sección 6. No es una falta de la ficha.'],
                ['id' => 'f5_auto', 'tipo' => self::T_CHECK, 'num' => '5.2', 'subtitulo' => '5.2 Fuente de la información sobre la transferencia',
                    'antes' => 'Marque todas las que correspondan. Mientras más fuentes combine, más confiable será la información.',
                    'label' => 'Autorreporte de participantes'],
                ['id' => 'f5_jefe', 'tipo' => self::T_CHECK, 'label' => 'Jefatura o empleador'],
                ['id' => 'f5_seg', 'tipo' => self::T_CHECK, 'label' => 'Seguimiento de la persona facilitadora'],
                ['id' => 'f5_prac', 'tipo' => self::T_CHECK, 'label' => 'Práctica laboral'],
                ['id' => 'f5_otra', 'tipo' => self::T_CHECK, 'label' => 'Otra'],
                ['id' => 'f5_otra_txt', 'tipo' => implementacion::T_TEXTO, 'label' => 'Otra fuente (indique cuál)', 'condicional' => true],
                ['id' => 'momento5', 'tipo' => implementacion::T_TEXTO, 'num' => '5.3', 'subtitulo' => '5.3 Alcance del seguimiento',
                    'label' => 'Momento de la recogida de información (tiempo transcurrido desde el término de la formación)',
                    'ayuda' => 'Ejemplo: 6 semanas.', 'condicional' => true],
                ['id' => 'n_seg5', 'tipo' => implementacion::T_NUMERO, 'label' => 'N.º de participantes con información de seguimiento',
                    'ayuda' => 'Ejemplo: 14 participantes.', 'condicional' => true],
                ['id' => 'n_apl5', 'tipo' => implementacion::T_NUMERO, 'label' => 'N.º de participantes que aplicaron lo aprendido en su trabajo',
                    'ayuda' => 'Ejemplo: 10 participantes.', 'condicional' => true],
                ['id' => 'grado54', 'tipo' => implementacion::T_UNA, 'num' => '5.4',
                    'label' => 'Grado de transferencia de los aprendizajes al contexto laboral', 'opciones' => $m, 'condicional' => true,
                    'descriptores' => ['4' => 'la mayoría de las personas con información aplica lo aprendido de manera habitual y con buenos resultados.',
                        '3' => 'varias personas aplican lo aprendido, aunque no siempre o con apoyo.',
                        '2' => 'pocas personas aplican lo aprendido o lo hacen de manera ocasional.',
                        '1' => 'no se observa aplicación de lo aprendido.']],
                ['id' => 'ejemplos5', 'tipo' => implementacion::T_PARRAFO, 'nombre' => '5.4 Ejemplos de aplicación',
                    'label' => 'Ejemplos de aplicación (obligatorio)', 'condicional' => true,
                    'ayuda' => 'Describa situaciones concretas en que las personas participantes aplicaron lo aprendido y con qué resultado.'],
                ['id' => 'factores5', 'tipo' => implementacion::T_PARRAFO, 'nombre' => '5.4 Factores que facilitaron u obstaculizaron la transferencia',
                    'label' => 'Factores que facilitaron u obstaculizaron la transferencia (obligatorio)', 'condicional' => true,
                    'ayuda' => 'Por ejemplo: apoyo de la jefatura, oportunidades en el puesto de trabajo, recursos disponibles o tiempo transcurrido.'],
            ]],
            6 => ['titulo' => 'Efectividad de los ajustes', 'campos' => [
                $valorado('v61', '6.1', 'La trayectoria de nivelación permitió alcanzar el nivel de logro mínimo',
                    'Las personas en niveles Insuficiente o Básico alcanzaron el nivel de logro mínimo.', false),
                $valorado('v62', '6.2', 'La trayectoria de profundización permitió profundizar el dominio',
                    'Quienes ya habían logrado los criterios profundizaron su dominio.', false),
                $valorado('v63', '6.3', 'Las acciones remediales o el rediseño resultaron efectivos',
                    'Las acciones remediales o el rediseño resultaron efectivos para cerrar las brechas y resolver los pendientes.', false),
                ['id' => 'fund6', 'tipo' => implementacion::T_PARRAFO, 'nombre' => '6 Fundamentación de las valoraciones 6.1 a 6.3',
                    'label' => 'Fundamentación (obligatorio)',
                    'ayuda' => 'Por el principio de no regresión, el logro final nunca es menor que el inicial: para valorar la efectividad no '
                        . 'basta con el aumento del logro. Fundamente con cuántas personas pasaron de Insuficiente o Básico a Adecuado, y '
                        . 'cuántas de Adecuado a Avanzado, con datos de la sección 2 y de la implementación de la experiencia de continuidad.'],
            ]],
            7 => ['titulo' => 'Reflexión de la persona facilitadora', 'nota' => 'Esta sección es la base del análisis metacognitivo del '
                . 'proceso. Reflexione sobre todo el ciclo: diseño, implementación, experiencia de continuidad y resultados. Los cuatro campos '
                . 'son obligatorios. Reconocer lo que no funcionó es tan valioso como identificar lo que funcionó.', 'campos' => [
                ['id' => 'r71', 'tipo' => implementacion::T_PARRAFO, 'num' => '7.1', 'label' => 'Decisiones que resultaron acertadas y por qué (obligatorio)',
                    'ayuda' => 'Identifique decisiones de diseño o de implementación que contribuyeron al logro, y explique por qué funcionaron.'],
                ['id' => 'r72', 'tipo' => implementacion::T_PARRAFO, 'num' => '7.2', 'label' => 'Qué haría distinto (obligatorio)',
                    'ayuda' => 'Señale qué cambiaría si volviera a implementar la experiencia, y por qué.'],
                ['id' => 'r73', 'tipo' => implementacion::T_PARRAFO, 'num' => '7.3', 'label' => 'Qué aprendió sobre el grupo, la estrategia y el contexto (obligatorio)',
                    'ayuda' => 'Registre aprendizajes sobre las características del grupo, la estrategia utilizada y las condiciones del contexto '
                        . 'que conviene tener presentes en el futuro.'],
                ['id' => 'r74', 'tipo' => implementacion::T_PARRAFO, 'num' => '7.4', 'label' => 'Recomendaciones para una nueva ejecución del curso (obligatorio)',
                    'ayuda' => 'Formule recomendaciones concretas para una nueva ejecución. Una nueva ejecución requerirá un nuevo diagnóstico, por lo '
                        . 'que las recomendaciones son insumos para ese diagnóstico y no un nuevo diseño.'],
            ]],
        ];
    }

    /**
     * Alternativas de un campo de la ficha con respuesta de eleccion unica (incluidos los de las tablas).
     *
     * @param string $clave
     * @return string[]|null clave => texto; null si el campo no es de eleccion unica.
     */
    public static function options(string $clave): ?array {
        if (preg_match('/^pr\d+$/', $clave)) {
            return self::answers();
        }
        if (preg_match('/^[de]\d+_val$/', $clave)) {
            return self::scale(true);
        }
        if (preg_match('/^[de]\d+_exp$/', $clave)) {
            return ['inicial' => 'Inicial', 'continuidad' => 'Continuidad'];
        }
        if (preg_match('/^bp\d+_dec$/', $clave)) {
            return ['confirma' => 'Se confirma', 'descarta' => 'Se descarta'];
        }
        foreach (self::sections() as $seccion) {
            foreach ($seccion['campos'] as $campo) {
                if ($campo['id'] === $clave && $campo['tipo'] === implementacion::T_UNA) {
                    return $campo['opciones'];
                }
            }
        }
        return null;
    }

    /**
     * Filas de las tablas 3.1 y 3.2: las precargadas y las libres.
     *
     * @return int[] Posiciones 1.. (MAX_ETIQUETAS + FILAS_LIBRES).
     */
    public static function strategy_rows(): array {
        return range(1, self::MAX_ETIQUETAS + self::FILAS_LIBRES);
    }

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
        for ($k = 1; $k <= self::CRITERIOS_MAX; $k++) {
            foreach (array_keys(implementacion::NIVELES) as $nivel) {
                $claves[$nivel . $k] = implementacion::T_NUMERO;
            }
            $claves['lg' . $k] = implementacion::T_PORCENTAJE;
            $claves['cm' . $k] = implementacion::T_PARRAFO;
            $claves['pr' . $k] = implementacion::T_UNA;
            $claves['pc' . $k] = implementacion::T_PARRAFO;
        }
        foreach (['d', 'e'] as $tabla) {
            foreach (self::strategy_rows() as $j) {
                $claves[$tabla . $j . '_val'] = implementacion::T_UNA;
                $claves[$tabla . $j . '_ev'] = implementacion::T_PARRAFO;
                if ($j > self::MAX_ETIQUETAS) {
                    $claves[$tabla . $j . '_nombre'] = implementacion::T_TEXTO;
                    $claves[$tabla . $j . '_exp'] = implementacion::T_UNA;
                }
            }
        }
        for ($j = 1; $j <= self::MAX_ETIQUETAS; $j++) {
            $claves['bp' . $j . '_dec'] = implementacion::T_UNA;
            $claves['bp' . $j . '_fund'] = implementacion::T_PARRAFO;
        }
        return $claves;
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
                case implementacion::T_UNA:
                    $valor = is_scalar($valor) ? (string) $valor : '';
                    $limpio[$clave] = array_key_exists($valor, (array) self::options($clave)) ? $valor : '';
                    break;
                case self::T_CHECK:
                    $limpio[$clave] = (is_scalar($valor) && in_array((string) $valor, ['1', 'on', 'true'], true)) ? '1' : '';
                    break;
                case implementacion::T_NUMERO:
                    $valor = is_scalar($valor) ? trim((string) $valor) : '';
                    $limpio[$clave] = preg_match('/^\d{1,7}$/', $valor) ? (string) (int) $valor : '';
                    break;
                case implementacion::T_PORCENTAJE:
                    $valor = is_scalar($valor) ? str_replace(',', '.', trim((string) $valor)) : '';
                    $limpio[$clave] = (preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $valor) && (float) $valor <= 100.0)
                        ? rtrim(rtrim(number_format((float) $valor, 2, '.', ''), '0'), '.') : '';
                    if ($limpio[$clave] === '' && $valor !== '' && preg_match('/^0+(?:\.0+)?$/', $valor)) {
                        $limpio[$clave] = '0';
                    }
                    break;
                default:
                    $valor = is_scalar($valor) ? trim(str_replace("\r\n", "\n", (string) $valor)) : '';
                    $largo = $tipo === implementacion::T_PARRAFO ? implementacion::LARGO_PARRAFO : implementacion::LARGO_TEXTO;
                    $limpio[$clave] = \core_text::substr($valor, 0, $largo);
            }
        }
        return $limpio;
    }

    /**
     * @param mixed $valor
     * @return bool
     */
    private static function vacio($valor): bool {
        return trim((string) $valor) === '';
    }

    /**
     * Un numero guardado como entero, o null si esta vacio.
     *
     * @param mixed $valor
     * @return int|null
     */
    private static function entero($valor): ?int {
        return self::vacio($valor) ? null : (int) $valor;
    }

    // ------------------------------------------------------------------------------------------
    // El checklist (documento del cliente, textual).
    // ------------------------------------------------------------------------------------------

    /**
     * Checklist de validacion, con el texto exacto del documento del cliente («Checklist de validación de la ficha de evaluación
     * ex post»: 25 items en 8 secciones, 3 de ellos CRÍTICO).
     *
     * @return array Seccion => ['titulo' => string, 'items' => [id => texto]].
     */
    public static function checklist(): array {
        return [
            1 => ['titulo' => 'Identificación general', 'items' => [
                'c1_1' => 'Las fechas de la experiencia de continuidad son posteriores a las de la experiencia inicial',
                'c1_2' => 'El número de participantes evaluados al cierre es coherente con los presentes en la experiencia inicial y '
                    . 'en la de continuidad',
            ]],
            2 => ['titulo' => 'Logro final de los aprendizajes', 'items' => [
                'c2_1' => 'En cada criterio, la suma de participantes por nivel es igual al número de participantes evaluados al '
                    . 'cierre del ciclo',
                'c2_2' => 'Se respeta el principio de no regresión: en ningún criterio disminuye el número de participantes en niveles '
                    . 'Adecuado o Avanzado respecto de la experiencia inicial',
                'c2_3' => 'El logro final (%) está bien calculado: (Adecuado + Avanzado) ÷ evaluados al cierre × 100',
                'c2_4' => 'La respuesta de cada pendiente es coherente con la tabla 2.1: los marcados «Sí» tienen niveles registrados y '
                    . 'los marcados «Parcialmente» o «No» no los tienen',
            ]],
            3 => ['titulo' => 'Efectividad de las estrategias didácticas y evaluativas', 'items' => [
                'c3_1' => 'Las evidencias registradas para cada estrategia e instrumento son concretas y verificables',
                'c3_2' => 'Las valoraciones son coherentes con los resultados de la sección 2',
                'c3_3' => 'Si se usaron filas libres, corresponden a estrategias o instrumentos no declarados en las fases anteriores',
                'c3_4' => 'La fundamentación de la valoración global (3.3) se apoya en datos concretos',
            ]],
            4 => ['titulo' => 'Reacción de las personas participantes', 'items' => [
                'c4_1' => 'La fuente declarada es coherente con los datos registrados (por ejemplo, si se marcó la encuesta, se informan '
                    . 'sus resultados)',
                'c4_2' => 'Las valoraciones 4.2 a 4.7 están respaldadas por datos (resultados de la encuesta, comentarios o situaciones '
                    . 'observadas)',
                'c4_3' => 'Los aspectos más valorados y las críticas y sugerencias son específicos',
            ]],
            5 => ['titulo' => 'Transferencia al contexto laboral', 'items' => [
                'c5_1' => 'Si no hubo oportunidad de transferencia, el motivo es concreto',
                'c5_2' => 'Los números del seguimiento son coherentes: quienes aplicaron lo aprendido no superan a quienes tienen '
                    . 'información, y estos no superan a los evaluados al cierre',
                'c5_3' => 'El grado de transferencia es coherente con los datos del seguimiento y se ilustra con ejemplos concretos',
                'c5_4' => 'Los factores que facilitaron u obstaculizaron la transferencia son concretos',
            ]],
            6 => ['titulo' => 'Efectividad de los ajustes', 'items' => [
                'c6_1' => 'Las valoraciones 6.1 a 6.3 se fundamentan en cambios de nivel de las personas participantes, no solo en el '
                    . 'aumento del logro',
                'c6_2' => 'Las valoraciones son coherentes con los resultados de la sección 2',
                'c6_3' => 'La decisión sobre cada buena práctica (se confirma o se descarta) es coherente con los resultados del ciclo',
            ]],
            7 => ['titulo' => 'Reflexión de la persona facilitadora', 'items' => [
                'c7_1' => 'La reflexión reconoce tanto las decisiones acertadas como los aspectos por mejorar',
                'c7_2' => 'La reflexión se refiere a decisiones y situaciones concretas del ciclo, no a afirmaciones generales',
                'c7_3' => 'Las recomendaciones para una nueva ejecución son insumos para un nuevo diagnóstico y no un nuevo diseño',
            ]],
            8 => ['titulo' => 'Validación global', 'items' => [
                'c8_1' => 'La información es coherente globalmente: los datos y valoraciones de las distintas secciones no se contradicen '
                    . 'entre sí',
                'c8_2' => 'Los datos permiten realizar el análisis metacognitivo: trayectoria del ciclo, resultados, lecciones '
                    . 'aprendidas, prácticas exitosas y recomendaciones',
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
    // Etapa 9: precarga (lo que pasa solo desde las fases anteriores).
    // ------------------------------------------------------------------------------------------

    /**
     * Nombre de la experiencia de continuidad, tomado de la etapa 6 aceptada. Funcion pura.
     *
     * @param string $texto Etapa 6 como texto.
     * @return string Vacio si no se encuentra.
     */
    public static function continuity_name(string $texto): string {
        if (preg_match('/Nombre de la experiencia(?: de aprendizaje)?(?: de continuidad)?\s*:?\**\s*:?\s*\n*\s*([^\n]{4,160})/iu', $texto, $m)) {
            $nombre = preg_replace('/\s*\([^()]*\)\s*\.?\s*$/u', '', trim($m[1]));
            $nombre = trim($nombre, " \t*:.\"«»#-");
            // Si el modelo escribio el rotulo y el nombre en dos lineas, el rotulo no es el nombre.
            return preg_match('/^(?:nombre|prop[oó]sito)\b/iu', $nombre) ? '' : $nombre;
        }
        return '';
    }

    /**
     * La precarga de la ficha: identificacion y tabla 2.1 (criterios del diseño con su logro inicial; en blanco para los
     * pendientes). Funcion pura.
     *
     * @param array $impl Ficha de registro de la implementacion ya limpia (implementacion::clean).
     * @param array $ref implementacion::design_reference() del diseño elegido.
     * @param string $continuidad Nombre de la experiencia de continuidad ('' si no se pudo leer).
     * @param string $fecha Fecha del registro ex post, ya con formato.
     * @return array s1 (plan, modulo, exp_inicial, exp_continuidad, facilitador, fechas_ini, presentes_ini), criterios (filas:
     *         texto, pendiente, inicial [ins, bas, ade, ava, presentes, logro] o null), trabajados_ini y diseno_total.
     */
    public static function preload(array $impl, array $ref, string $continuidad, string $fecha): array {
        $perf = [];
        $criterios = [];
        foreach (implementacion::performance($impl) as $p) {
            $perf[$p['n']] = $p;
            $criterios[$p['n']] = (string) $p['criterio'];
        }
        $cmp = implementacion::match_criteria($criterios, $ref['criterios'] ?? []);
        $porposicion = [];
        foreach ($cmp['ficha'] as $n => $c) {
            if ($c['diseno'] !== null) {
                $porposicion[$c['diseno']] = $n;
            }
        }
        $inicial = static function(array $p): array {
            return ['ins' => $p['niveles']['ins'], 'bas' => $p['niveles']['bas'], 'ade' => $p['niveles']['ade'],
                'ava' => $p['niveles']['ava'], 'presentes' => $p['presentes'], 'logro' => $p['logro']];
        };
        $filas = [];
        $usados = [];
        foreach (array_values($ref['criterios'] ?? []) as $k => $texto) {
            $n = $porposicion[$k + 1] ?? null;
            if ($n !== null) {
                $usados[$n] = true;
            }
            $filas[] = ['texto' => (string) $texto, 'pendiente' => $n === null, 'inicial' => $n === null ? null : $inicial($perf[$n])];
        }
        // Criterios de la ficha que no se parecen a ninguno del diseño: tambien tienen logro inicial y se informan.
        foreach ($perf as $n => $p) {
            if (!isset($usados[$n])) {
                $filas[] = ['texto' => (string) $p['criterio'], 'pendiente' => false, 'inicial' => $inicial($p)];
            }
        }
        $filas = array_slice($filas, 0, self::CRITERIOS_MAX);
        $trabajados = count(array_filter($filas, static function(array $f): bool {
            return $f['inicial'] !== null;
        }));
        $texto = static function($v): string {
            return trim(rtrim(trim((string) $v), '.'));
        };
        return ['fecha' => $fecha,
            's1' => ['plan' => $ref['plan'] !== '' ? $ref['plan'] : $texto($impl['plan'] ?? ''),
                'modulo' => $ref['modulo'] !== '' ? $ref['modulo'] : $texto($impl['modulo'] ?? ''),
                'exp_inicial' => $ref['experiencia'] !== '' ? $ref['experiencia'] : $texto($impl['experiencia'] ?? ''),
                'exp_continuidad' => $continuidad, 'facilitador' => trim((string) ($impl['facilitador'] ?? '')),
                'fechas_ini' => trim((string) ($impl['fechas'] ?? '')),
                'presentes_ini' => self::entero($impl['presentes'] ?? '')],
            'criterios' => $filas, 'trabajados_ini' => $trabajados, 'diseno_total' => count($filas)];
    }

    /**
     * Huella de lo que alimenta la precarga: la ficha de la implementacion, el diseño y las etapas 5 y 6. Si cambia, la
     * precarga se rehace. Funcion pura.
     *
     * @param array $impl
     * @param int $disenoid
     * @param string $diseno Huella del contenido del diseño.
     * @param string[] $etapas Etapa => producto aceptado (5 y 6).
     * @return string
     */
    public static function preload_fingerprint(array $impl, int $disenoid, string $diseno, array $etapas): string {
        ksort($impl);
        ksort($etapas);
        return sha1(json_encode([$impl, $disenoid, $diseno, $etapas], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Recorta una etiqueta a MAX_PALABRAS palabras y la limpia. Funcion pura.
     *
     * @param mixed $valor
     * @return string
     */
    public static function short_label($valor): string {
        if (!is_scalar($valor)) {
            return '';
        }
        $t = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $valor)), " \t\n\r\"«»*-•.");
        $palabras = preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY);
        return implode(' ', array_slice($palabras, 0, self::MAX_PALABRAS));
    }

    /**
     * Lee las etiquetas que devuelve el modelo (JSON): estrategias didacticas y evaluativas con su experiencia, y buenas
     * practicas. Aplica las reglas del prompt: una etiqueta de hasta 10 palabras, sin repetidas y un maximo de 4 por tabla.
     * Funcion pura.
     *
     * @param string|null $texto
     * @return array|null didacticas y evaluativas (listas de ['etiqueta', 'exp']) y practicas (string[]); null si no se lee.
     */
    public static function parse_labels(?string $texto): ?array {
        $json = exante::decode_json($texto);
        if (!$json) {
            return null;
        }
        $lista = static function($origen, bool $conexp): array {
            $salida = [];
            $vistas = [];
            foreach ((array) $origen as $e) {
                $etiqueta = self::short_label(is_array($e) ? ($e['etiqueta'] ?? '') : $e);
                $clave = implementacion::norm($etiqueta);
                if ($etiqueta === '' || isset($vistas[$clave])) {
                    continue;
                }
                $vistas[$clave] = true;
                if ($conexp) {
                    $exp = is_array($e) ? \core_text::strtolower(trim((string) ($e['experiencia'] ?? $e['exp'] ?? ''))) : '';
                    $salida[] = ['etiqueta' => $etiqueta, 'exp' => strpos($exp, 'contin') === 0 ? 'continuidad' : 'inicial'];
                } else {
                    $salida[] = $etiqueta;
                }
            }
            return array_slice($salida, 0, self::MAX_ETIQUETAS);
        };
        $r = ['didacticas' => $lista($json['didacticas'] ?? [], true), 'evaluativas' => $lista($json['evaluativas'] ?? [], true),
            'practicas' => $lista($json['practicas'] ?? ($json['buenas_practicas'] ?? []), false)];
        return ($r['didacticas'] || $r['evaluativas'] || $r['practicas']) ? $r : null;
    }

    // ------------------------------------------------------------------------------------------
    // Calculos de la ficha (aritmetica que manda el codigo).
    // ------------------------------------------------------------------------------------------

    /**
     * La tabla 2.1 con lo que el codigo calcula: por criterio, los niveles al cierre, su suma, el logro final recalculado y
     * la respuesta al pendiente. Funcion pura.
     *
     * @param array $data Ficha ex post ya limpia.
     * @param array $pre preload().
     * @return array Una fila por criterio: k, criterio, pendiente, pr, inicial (o null), niveles (clave => int), con_niveles
     *         (alguna casilla de nivel llena), completa (cuatro niveles y logro), suma, logro_declarado, logro, suma_ok, logro_ok.
     */
    public static function rows(array $data, array $pre): array {
        $evaluados = self::entero($data['evaluados'] ?? '');
        $filas = [];
        foreach (array_values($pre['criterios'] ?? []) as $i => $c) {
            $k = $i + 1;
            $niveles = [];
            $con = false;
            $completa = true;
            foreach (array_keys(implementacion::NIVELES) as $nivel) {
                $v = $data[$nivel . $k] ?? '';
                $niveles[$nivel] = self::vacio($v) ? 0 : (int) $v;
                $con = $con || !self::vacio($v);
                $completa = $completa && !self::vacio($v);
            }
            $declarado = self::vacio($data['lg' . $k] ?? '') ? null : (float) $data['lg' . $k];
            $completa = $completa && $declarado !== null;
            $suma = array_sum($niveles);
            $logro = ($evaluados !== null && $evaluados > 0) ? round(100 * ($niveles['ade'] + $niveles['ava']) / $evaluados, 1) : 0.0;
            $filas[] = ['k' => $k, 'criterio' => (string) $c['texto'], 'pendiente' => !empty($c['pendiente']),
                'pr' => (string) ($data['pr' . $k] ?? ''), 'inicial' => $c['inicial'], 'niveles' => $niveles, 'con_niveles' => $con,
                'completa' => $completa, 'suma' => $suma, 'logro_declarado' => $declarado, 'logro' => $logro,
                'suma_ok' => $evaluados !== null && $evaluados > 0 && $suma === $evaluados,
                'logro_ok' => $declarado !== null && abs($declarado - $logro) <= self::LOGRO_TOLERANCIA];
        }
        return $filas;
    }

    /**
     * Los pendientes marcados «Parcialmente» o «No» quedan sin niveles (ficha ex post, subseccion 2.2): se vacian los niveles y el
     * logro de esas filas. Funcion pura.
     *
     * @param array $data Ficha ex post ya limpia.
     * @param array $pre preload().
     * @return array La ficha sin niveles en esos pendientes.
     */
    public static function normalize_pending(array $data, array $pre): array {
        foreach (array_values($pre['criterios'] ?? []) as $i => $c) {
            $k = $i + 1;
            if (!empty($c['pendiente']) && ($data['pr' . $k] ?? '') !== self::SI) {
                foreach (array_keys(implementacion::NIVELES) as $nivel) {
                    $data[$nivel . $k] = '';
                }
                $data['lg' . $k] = '';
            }
        }
        return $data;
    }

    /**
     * Si la fila de un criterio tiene niveles al cierre que cuentan: un criterio trabajado en la experiencia inicial, o un
     * pendiente marcado «Sí». Los pendientes «Parcialmente» o «No» quedan sin niveles y siguen pendientes. Funcion pura.
     *
     * @param array $fila Fila de rows().
     * @return bool
     */
    public static function counts(array $fila): bool {
        return !$fila['pendiente'] || $fila['pr'] === self::SI;
    }

    /**
     * Resultados del ciclo (etapa 11) calculados por el codigo. Funcion pura.
     *
     * Principio de no regresion: quien ya logro un criterio lo conserva, asi que la nivelacion es el aumento de personas en
     * Adecuado o Avanzado y la profundizacion, el aumento de personas en Avanzado. Con los totales por nivel (la ficha no
     * registra a cada persona) son estimaciones; se indican como tales.
     *
     * @param array $data Ficha ex post ya limpia.
     * @param array $pre preload().
     * @return array filas (por criterio), promedio_inicial, promedio_final, promedio_pendientes, cobertura_inicial,
     *         cobertura_final, con_niveles_inicial, con_niveles_final, diseno_total, persisten (pendientes que siguen),
     *         nivelacion y profundizacion (totales), evaluados, presentes_ini y aviso (string|null).
     */
    public static function results(array $data, array $pre): array {
        $filas = [];
        $persisten = [];
        $ini = [];
        $fin = [];
        $pend = [];
        $nivel = ['mov' => 0, 'tot' => 0];
        $prof = 0;
        $confinal = 0;
        foreach (self::rows($data, $pre) as $f) {
            $cuenta = self::counts($f);
            $tipo = !$f['pendiente'] ? 'trabajado' : ($f['pr'] === self::SI ? 'pendiente_resuelto' : 'pendiente_persiste');
            $final = $cuenta ? $f['logro'] : null;
            $i = $f['inicial'];
            $var = ($i !== null && $final !== null) ? round($final - $i['logro'], 1) : null;
            $nl = null;
            $pf = null;
            if ($i !== null && $final !== null) {
                $mov = max(0, ($f['niveles']['ade'] + $f['niveles']['ava']) - ($i['ade'] + $i['ava']));
                $tot = $i['ins'] + $i['bas'];
                $mov = min($mov, $tot);
                $nl = ['mov' => $mov, 'tot' => $tot];
                $pf = max(0, $f['niveles']['ava'] - $i['ava']);
                $nivel['mov'] += $mov;
                $nivel['tot'] += $tot;
                $prof += $pf;
                $ini[] = $i['logro'];
                $fin[] = $final;
            }
            if ($tipo === 'pendiente_resuelto' && $final !== null) {
                $pend[] = $final;
            }
            if ($tipo === 'pendiente_persiste') {
                $persisten[] = ['k' => $f['k'], 'criterio' => $f['criterio'], 'pr' => $f['pr']];
            }
            $confinal += $cuenta ? 1 : 0;
            $filas[] = ['k' => $f['k'], 'criterio' => $f['criterio'], 'tipo' => $tipo, 'pr' => $f['pr'],
                'inicial' => $i === null ? null : $i['logro'], 'final' => $final, 'variacion' => $var, 'nivelacion' => $nl,
                'profundizacion' => $pf, 'niveles_i' => $i === null ? null : array_intersect_key($i, implementacion::NIVELES),
                'niveles_f' => $cuenta ? $f['niveles'] : null];
        }
        $total = count($filas);
        $media = static function(array $v): ?float {
            return $v ? round(array_sum($v) / count($v), 1) : null;
        };
        $evaluados = self::entero($data['evaluados'] ?? '');
        $presentes = $pre['s1']['presentes_ini'] ?? null;
        return ['filas' => $filas, 'promedio_inicial' => $media($ini), 'promedio_final' => $media($fin),
            'promedio_pendientes' => $media($pend), 'diseno_total' => $total,
            'con_niveles_inicial' => (int) ($pre['trabajados_ini'] ?? 0), 'con_niveles_final' => $confinal,
            'cobertura_inicial' => calidad::coverage((int) ($pre['trabajados_ini'] ?? 0), $total),
            'cobertura_final' => calidad::coverage($confinal, $total), 'persisten' => $persisten,
            'nivelacion' => $nivel, 'profundizacion' => $prof, 'evaluados' => $evaluados, 'presentes_ini' => $presentes,
            'aviso' => ($evaluados !== null && $presentes !== null && $evaluados !== (int) $presentes)
                ? 'Los participantes evaluados al cierre (' . $evaluados . ') no coinciden con los presentes en la experiencia '
                    . 'inicial (' . $presentes . '): los movimientos de nivel son una estimación aún más aproximada.' : null];
    }

    /**
     * Los resultados como texto para el modelo. Funcion pura.
     *
     * @param array $r results().
     * @return string
     */
    public static function results_text(array $r): string {
        $p = static function(?float $v): string {
            return $v === null ? '—' : implementacion::pct($v);
        };
        $pp = static function(?float $v): string {
            return $v === null ? '—' : (($v > 0 ? '+' : '') . str_replace('.', ',', rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.')) . ' puntos porcentuales');
        };
        $l = ['DATOS CALCULADOS POR LA PLATAFORMA (cálculos exactos con los datos de la ficha ex post; úsalos tal cual, no los recalcules):'];
        $l[] = '- Participantes evaluados al cierre: ' . ($r['evaluados'] ?? '—') . '; presentes en la experiencia inicial: '
            . ($r['presentes_ini'] ?? '—') . '.';
        $l[] = '- Logro por criterio (logro final = (Adecuado + Avanzado) ÷ evaluados al cierre × 100):';
        foreach ($r['filas'] as $f) {
            $niv = [];
            foreach ($f['niveles_f'] ?? [] as $clave => $n) {
                $niv[] = implementacion::NIVELES[$clave] . ' ' . $n;
            }
            $linea = '    Criterio ' . $f['k'] . ' «' . $f['criterio'] . '»: ';
            if ($f['tipo'] === 'pendiente_persiste') {
                $linea .= 'PENDIENTE QUE PERSISTE (marcado «' . (self::answers()[$f['pr']] ?? 'sin respuesta') . '» en 2.2; sin niveles).';
            } else {
                $linea .= ($f['tipo'] === 'pendiente_resuelto' ? 'pendiente resuelto (marcado «Sí»); sin logro inicial; ' : 'logro inicial '
                    . $p($f['inicial']) . '; ') . 'logro final ' . $p($f['final']) . ($f['variacion'] === null ? '' : '; variación '
                    . $pp($f['variacion'])) . '; niveles al cierre: ' . implode(', ', $niv) . '.';
                if ($f['nivelacion'] !== null) {
                    $linea .= ' Nivelación: ' . $f['nivelacion']['mov'] . ' de ' . $f['nivelacion']['tot'] . ' personas en nivelación '
                        . 'alcanzaron Adecuado o Avanzado; profundización: ' . $f['profundizacion'] . ' persona(s) más en Avanzado.';
                }
            }
            $l[] = $linea;
        }
        $l[] = '- Logro promedio de los criterios trabajados en la experiencia inicial: inicial ' . $p($r['promedio_inicial'])
            . '; final ' . $p($r['promedio_final']) . '. Logro promedio de los pendientes resueltos: ' . $p($r['promedio_pendientes']) . '.';
        $l[] = '- Cobertura evaluativa = criterios con niveles registrados ÷ criterios del diseño × 100: al cierre '
            . $r['con_niveles_final'] . ' ÷ ' . $r['diseno_total'] . ' = ' . $p($r['cobertura_final']) . '; en la experiencia inicial '
            . $r['con_niveles_inicial'] . ' ÷ ' . $r['diseno_total'] . ' = ' . $p($r['cobertura_inicial']) . '.';
        $l[] = '- Nivelación total (personas que pasaron de Insuficiente o Básico a Adecuado o Avanzado, sobre las personas en '
            . 'nivelación): ' . $r['nivelacion']['mov'] . ' de ' . $r['nivelacion']['tot'] . '. Profundización total (personas más en '
            . 'Avanzado): ' . $r['profundizacion'] . '. Son estimaciones hechas con los totales por nivel de cada criterio.';
        if ($r['persisten']) {
            $l[] = '- Pendientes que persisten (no se registran niveles; se informan como pendientes): '
                . implode('; ', array_map(static function(array $x): string {
                    return 'criterio ' . $x['k'] . ' (' . (self::answers()[$x['pr']] ?? 'sin respuesta') . ')';
                }, $r['persisten'])) . '.';
        } else {
            $l[] = '- No quedan pendientes sin resolver.';
        }
        if ($r['aviso'] !== null) {
            $l[] = '- ' . $r['aviso'];
        }
        return implode("\n", $l);
    }

    /**
     * Verificaciones aritmeticas y de coherencia que hace la plataforma antes (y despues) del modelo.
     *
     * Decide por calculo los items c1_2 (evaluados contra presentes), c2_1 (suma por nivel), c2_2 (no regresion), c2_3 (logro
     * final), c2_4 (pendientes contra la tabla 2.1) y c5_2 (numeros del seguimiento): el codigo es la autoridad, no el modelo.
     * Funcion pura.
     *
     * @param array $data Ficha ex post ya limpia.
     * @param array $pre preload().
     * @return array items (id => ['estado', 'observacion', 'propuesta']) y hechos (string[]: lineas para el modelo).
     */
    public static function system_checks(array $data, array $pre): array {
        $items = [];
        $hechos = [];
        $ini = isset($pre['s1']['presentes_ini']) ? (int) $pre['s1']['presentes_ini'] : null;
        $cont = self::entero($data['presentes_cont'] ?? '');
        $ev = self::entero($data['evaluados'] ?? '');
        $fila = static function(string $estado, string $obs, string $propuesta): array {
            return ['estado' => $estado, 'observacion' => $estado === implementacion::CUMPLE ? '' : $obs,
                'propuesta' => $estado === implementacion::CUMPLE ? '' : $propuesta];
        };

        // c1_2: evaluados entre el mayor de los presentes y su suma (todos los de cada experiencia tienen evidencia de nivel).
        if ($ev !== null && $cont !== null && $ini !== null && $ini > 0) {
            $min = max($ini, $cont);
            $max = $ini + $cont;
            $ok = $ev > 0 && $ev >= $min && $ev <= $max;
            $items['c1_2'] = $fila($ok ? implementacion::CUMPLE : implementacion::PARCIAL,
                'La ficha registra ' . $ev . ' evaluados al cierre, ' . $ini . ' presentes en la experiencia inicial y ' . $cont
                    . ' en la de continuidad: los evaluados deberían estar entre ' . $min . ' y ' . $max . ' (quienes no asistieron a '
                    . 'la continuidad conservan su nivel inicial).',
                'Revisa en la sección 1 los campos «N.º de participantes presentes en la experiencia de continuidad» y «N.º de '
                    . 'participantes evaluados al cierre del ciclo».');
            $hechos[] = 'Participantes: ' . $ini . ' presentes en la experiencia inicial, ' . $cont . ' en la de continuidad y ' . $ev
                . ' evaluados al cierre (esperable entre ' . $min . ' y ' . $max . ') → ' . ($ok ? 'coherente.' : 'INCOHERENTE.');
        }

        $filas = self::rows($data, $pre);
        // c2_1, c2_2 y c2_3: tabla 2.1.
        $malsuma = [];
        $regresion = [];
        $mallogro = [];
        $conteo = 0;
        foreach ($filas as $f) {
            if (!self::counts($f) || !$f['con_niveles']) {
                continue;
            }
            $conteo++;
            $niv = [];
            foreach (implementacion::NIVELES as $clave => $nombre) {
                $niv[] = $nombre . ' ' . $f['niveles'][$clave];
            }
            $linea = 'Criterio ' . $f['k'] . ': ' . implode(', ', $niv) . ' → suma ' . $f['suma'] . ' de ' . ($ev ?? '—') . ' evaluados ('
                . ($f['suma_ok'] ? 'coincide' : 'NO coincide') . '). Logro final declarado ' . ($f['logro_declarado'] === null ? '(vacío)'
                    : implementacion::pct($f['logro_declarado'])) . ', calculado (' . ($f['niveles']['ade'] + $f['niveles']['ava']) . ' ÷ '
                . ($ev ?? '—') . ' × 100) = ' . implementacion::pct($f['logro']) . ' (' . ($f['logro_ok'] ? 'bien calculado' : 'MAL calculado') . ').';
            if (!$f['suma_ok']) {
                $malsuma[] = 'criterio ' . $f['k'] . ' (suma ' . $f['suma'] . ', evaluados ' . ($ev ?? '—') . ')';
            }
            if (!$f['logro_ok']) {
                $mallogro[] = 'criterio ' . $f['k'] . ': la ficha dice ' . ($f['logro_declarado'] === null ? 'nada'
                    : implementacion::pct($f['logro_declarado'])) . ' y corresponde ' . implementacion::pct($f['logro']);
            }
            if ($f['inicial'] !== null) {
                $antes = $f['inicial']['ade'] + $f['inicial']['ava'];
                $ahora = $f['niveles']['ade'] + $f['niveles']['ava'];
                $linea .= ' No regresión: ' . $antes . ' personas en Adecuado o Avanzado en la experiencia inicial y ' . $ahora
                    . ' al cierre → ' . ($ahora >= $antes ? 'se respeta.' : 'SE VIOLA (disminuye).');
                if ($ahora < $antes) {
                    $regresion[] = 'criterio ' . $f['k'] . ' (' . $antes . ' al inicio y ' . $ahora . ' al cierre)';
                }
            }
            $hechos[] = $linea;
        }
        if ($conteo > 0) {
            $items['c2_1'] = $fila($malsuma ? implementacion::NO_CUMPLE : implementacion::CUMPLE,
                'La suma de participantes por nivel no es igual al número de evaluados al cierre en: ' . implode('; ', $malsuma) . '.',
                'Corrige en la tabla 2.1 el número de participantes por nivel para que sumen los evaluados al cierre del ciclo.');
            $items['c2_2'] = $fila($regresion ? implementacion::NO_CUMPLE : implementacion::CUMPLE,
                'Disminuye el número de personas en niveles Adecuado o Avanzado respecto de la experiencia inicial en: '
                    . implode('; ', $regresion) . '.',
                'Aplica el principio de no regresión: quien ya logró un criterio lo conserva, y una evidencia posterior de menor nivel no '
                    . 'se considera. Revisa los niveles de esos criterios en la tabla 2.1.');
            $items['c2_3'] = $fila($mallogro ? implementacion::PARCIAL : implementacion::CUMPLE,
                'Logro final (%) mal calculado, recalculado con los datos de la tabla: ' . implode('; ', $mallogro) . '.',
                'Corrige el logro final (%) con la fórmula (Adecuado + Avanzado) ÷ evaluados al cierre × 100.');
        }

        // c2_4: pendientes.
        $problemas = [];
        $hay = false;
        foreach ($filas as $f) {
            if (!$f['pendiente']) {
                continue;
            }
            $hay = true;
            $resp = self::answers()[$f['pr']] ?? 'sin respuesta';
            $hechos[] = 'Pendiente (criterio ' . $f['k'] . '): respuesta en 2.2 «' . $resp . '»; la tabla 2.1 '
                . ($f['con_niveles'] ? 'tiene niveles registrados.' : 'no tiene niveles registrados.');
            if ($f['pr'] === '') {
                $problemas[] = 'criterio ' . $f['k'] . ': sin respuesta en 2.2';
            } else if ($f['pr'] === self::SI && !$f['completa']) {
                $problemas[] = 'criterio ' . $f['k'] . ': marcado «Sí» pero sin niveles completos en 2.1';
            } else if ($f['pr'] !== self::SI && $f['con_niveles']) {
                $problemas[] = 'criterio ' . $f['k'] . ': marcado «' . $resp . '» pero con niveles registrados en 2.1';
            }
        }
        if ($hay) {
            $items['c2_4'] = $fila($problemas ? implementacion::NO_CUMPLE : implementacion::CUMPLE,
                'La respuesta de algún pendiente no es coherente con la tabla 2.1: ' . implode('; ', $problemas) . '.',
                'Los pendientes marcados «Sí» deben tener sus cuatro niveles y el logro en la tabla 2.1; los marcados «Parcialmente» o '
                    . '«No» deben quedar sin niveles.');
        } else {
            $hechos[] = 'No hay criterios pendientes de la experiencia inicial.';
        }

        // c5_2: numeros del seguimiento.
        $op = (string) ($data['op51'] ?? '');
        if ($op === self::NO) {
            $items['c5_2'] = $fila(implementacion::CUMPLE, '', '');
            $hechos[] = 'Transferencia: no hubo oportunidad de aplicar lo aprendido (5.1 = No); no se piden los números del seguimiento.';
        } else if ($op !== '') {
            $seg = self::entero($data['n_seg5'] ?? '');
            $apl = self::entero($data['n_apl5'] ?? '');
            if ($seg !== null && $apl !== null) {
                $ok = $apl <= $seg && ($ev === null || $seg <= $ev);
                $items['c5_2'] = $fila($ok ? implementacion::CUMPLE : implementacion::NO_CUMPLE,
                    'Los números del seguimiento no son coherentes: ' . $apl . ' participantes aplicaron lo aprendido, ' . $seg
                        . ' tienen información de seguimiento y ' . ($ev ?? '—') . ' fueron evaluados al cierre (deben ir de menor a '
                        . 'mayor en ese orden).',
                    'Revisa en 5.3 los campos «N.º de participantes con información de seguimiento» y «N.º de participantes que '
                        . 'aplicaron lo aprendido en su trabajo».');
                $hechos[] = 'Seguimiento: ' . $apl . ' aplicaron lo aprendido, ' . $seg . ' con información y ' . ($ev ?? '—')
                    . ' evaluados al cierre → ' . ($ok ? 'coherente.' : 'INCOHERENTE.');
            }
        }
        return ['items' => $items, 'hechos' => $hechos];
    }

    /**
     * Avisos antes de validar, con reglas fijas (sin IA, sin gastar un intento). Son avisos: no impiden validar. Funcion pura.
     *
     * @param array $data Ficha ex post ya limpia.
     * @param array $pre preload().
     * @return string[] Un texto por aviso.
     */
    public static function form_warnings(array $data, array $pre): array {
        $avisos = [];
        foreach (self::system_checks($data, $pre)['items'] as $item) {
            if ($item['estado'] !== implementacion::CUMPLE) {
                $avisos[] = trim($item['observacion'] . ' ' . $item['propuesta']);
            }
        }
        return $avisos;
    }

    // ------------------------------------------------------------------------------------------
    // Control de entrada.
    // ------------------------------------------------------------------------------------------

    /**
     * Control de entrada: sin esto no se valida (prompt de la Fase 4 y checklist del cliente).
     *
     * Sección 1: fechas y presentes de la continuidad y evaluados al cierre. Sección 2: niveles y logro de cada criterio trabajado
     * en el ciclo y respuesta a cada pendiente. Sección 3: valoración y evidencia de cada estrategia e instrumento precargado,
     * matriz 3.3 y fundamentación. Sección 4: fuente, numero de encuestados si hubo encuesta, valoraciones 4.2 a 4.7, datos, 4.8 y
     * 4.9. Sección 5: 5.1, el motivo si es «No» y, si no, 5.2 a 5.4 con ejemplos y factores. Secciones 6 y 7. Opcionales: los
     * comentarios de 2.1 y las filas libres de 3.1 y 3.2.
     *
     * @param array $data Ficha ex post ya limpia.
     * @param array $pre preload().
     * @param array $etq Etiquetas precargadas (didacticas, evaluativas, practicas).
     * @return string[] Lo que falta, con el nombre del campo y su seccion.
     */
    public static function missing_inputs(array $data, array $pre, array $etq): array {
        $falta = [];
        $pide = static function(string $clave, string $nombre) use ($data, &$falta): void {
            if (self::vacio($data[$clave] ?? '')) {
                $falta[] = $nombre;
            }
        };
        if (self::vacio($pre['s1']['exp_continuidad'] ?? '')) {
            $pide('exp_cont', 'Sección 1: Nombre de la experiencia de aprendizaje de continuidad');
        }
        $pide('fechas_cont', 'Sección 1: Fecha(s) de la experiencia de continuidad');
        $pide('presentes_cont', 'Sección 1: N.º de participantes presentes en la experiencia de continuidad');
        $pide('evaluados', 'Sección 1: N.º de participantes evaluados al cierre del ciclo');

        foreach (array_values($pre['criterios'] ?? []) as $i => $c) {
            $k = $i + 1;
            $fila = 'Tabla 2.1, criterio ' . $k;
            if (!empty($c['pendiente'])) {
                if (self::vacio($data['pr' . $k] ?? '')) {
                    $falta[] = 'Subsección 2.2, criterio ' . $k . ': ¿se trabajó y evaluó en la experiencia de continuidad?';
                    continue;
                }
                if ($data['pr' . $k] !== self::SI) {
                    continue;
                }
            }
            foreach (implementacion::NIVELES as $clave => $nombre) {
                $pide($clave . $k, $fila . ': N.º de participantes en nivel ' . $nombre);
            }
            $pide('lg' . $k, $fila . ': logro final (%)');
        }

        foreach (['d' => ['3.1', 'didacticas', 'estrategia didáctica'], 'e' => ['3.2', 'evaluativas', 'estrategia o instrumento evaluativo']]
                as $p => [$num, $lista, $que]) {
            $n = count($etq[$lista] ?? []);
            foreach (self::strategy_rows() as $j) {
                $libre = $j > self::MAX_ETIQUETAS;
                $usada = $libre ? (!self::vacio($data[$p . $j . '_nombre'] ?? '') || !self::vacio($data[$p . $j . '_val'] ?? '')
                    || !self::vacio($data[$p . $j . '_ev'] ?? '') || !self::vacio($data[$p . $j . '_exp'] ?? '')) : $j <= $n;
                if (!$usada) {
                    continue;
                }
                $rotulo = 'Subsección ' . $num . ($libre ? ', fila libre ' . ($j - self::MAX_ETIQUETAS) : ', ' . $que . ' ' . $j);
                if ($libre) {
                    $pide($p . $j . '_nombre', $rotulo . ': nombre');
                    $pide($p . $j . '_exp', $rotulo . ': experiencia');
                }
                $pide($p . $j . '_val', $rotulo . ': efectividad');
                $pide($p . $j . '_ev', $rotulo . ': evidencia que respalda la valoración');
            }
        }
        foreach (['v331', 'v332', 'v333', 'v334', 'v335', 'v336'] as $id) {
            $pide($id, 'Subsección 3.3.' . substr($id, 3) . ': valoración');
        }
        $pide('fund33', 'Subsección 3.3: Fundamentación');

        if (self::vacio($data['f4_enc'] ?? '') && self::vacio($data['f4_obs'] ?? '') && self::vacio($data['f4_otra'] ?? '')) {
            $falta[] = '4.1 Fuente de la información sobre la reacción (marca al menos una)';
        }
        if (!self::vacio($data['f4_otra'] ?? '')) {
            $pide('f4_otra_txt', '4.1 Otra fuente (indica cuál)');
        }
        if (!self::vacio($data['f4_enc'] ?? '')) {
            $pide('n_enc', 'Sección 4: N.º de participantes que respondieron la encuesta');
        }
        foreach (['v42' => '4.2 Satisfacción general', 'v43' => '4.3 Pertinencia de la formación para el trabajo',
                'v44' => '4.4 Actividades y metodología de trabajo', 'v45' => '4.5 Materiales y recursos',
                'v46' => '4.6 Organización y tiempos', 'v47' => '4.7 Apoyo de la persona facilitadora'] as $id => $nombre) {
            $pide($id, $nombre);
        }
        $pide('datos4', 'Sección 4: Datos que respaldan las valoraciones');
        $pide('mas48', '4.8 Aspectos más valorados por las personas participantes');
        $pide('crit49', '4.9 Críticas y sugerencias de las personas participantes');

        $pide('op51', '5.1 ¿Hubo oportunidad de aplicar los aprendizajes en un contexto laboral?');
        if (($data['op51'] ?? '') === self::NO) {
            $pide('motivo5', '5.1 Motivo por el que no hubo oportunidad de transferencia');
        } else if (($data['op51'] ?? '') !== '') {
            if (self::vacio($data['f5_auto'] ?? '') && self::vacio($data['f5_jefe'] ?? '') && self::vacio($data['f5_seg'] ?? '')
                    && self::vacio($data['f5_prac'] ?? '') && self::vacio($data['f5_otra'] ?? '')) {
                $falta[] = '5.2 Fuente de la información sobre la transferencia (marca al menos una)';
            }
            if (!self::vacio($data['f5_otra'] ?? '')) {
                $pide('f5_otra_txt', '5.2 Otra fuente (indica cuál)');
            }
            $pide('momento5', '5.3 Momento de la recogida de información');
            $pide('n_seg5', '5.3 N.º de participantes con información de seguimiento');
            $pide('n_apl5', '5.3 N.º de participantes que aplicaron lo aprendido en su trabajo');
            $pide('grado54', '5.4 Grado de transferencia de los aprendizajes');
            $pide('ejemplos5', '5.4 Ejemplos de aplicación');
            $pide('factores5', '5.4 Factores que facilitaron u obstaculizaron la transferencia');
        }

        foreach (['v61' => '6.1', 'v62' => '6.2', 'v63' => '6.3'] as $id => $num) {
            $pide($id, 'Subsección ' . $num . ': valoración');
        }
        $pide('fund6', 'Sección 6: Fundamentación de las valoraciones 6.1 a 6.3');
        for ($j = 1; $j <= count($etq['practicas'] ?? []); $j++) {
            $pide('bp' . $j . '_dec', 'Subsección 6.4, buena práctica ' . $j . ': ¿se confirma o se descarta?');
            $pide('bp' . $j . '_fund', 'Subsección 6.4, buena práctica ' . $j . ': fundamentación');
        }
        foreach (['r71' => '7.1 Decisiones que resultaron acertadas y por qué', 'r72' => '7.2 Qué haría distinto',
                'r73' => '7.3 Qué aprendió sobre el grupo, la estrategia y el contexto',
                'r74' => '7.4 Recomendaciones para una nueva ejecución del curso'] as $id => $nombre) {
            $pide($id, $nombre);
        }
        return $falta;
    }

    // ------------------------------------------------------------------------------------------
    // La ficha como texto para el modelo.
    // ------------------------------------------------------------------------------------------

    /**
     * La ficha ex post como texto para el modelo, en el orden y con los rotulos del documento (con lo precargado incluido).
     *
     * El nombre de la persona facilitadora no viaja. Funcion pura.
     *
     * @param array $data Ficha ex post ya limpia.
     * @param array $pre preload().
     * @param array $etq Etiquetas precargadas.
     * @return string
     */
    public static function to_text(array $data, array $pre, array $etq): string {
        $sin = '(sin respuesta)';
        $v = static function(string $id) use ($data, $sin): string {
            $x = trim((string) ($data[$id] ?? ''));
            return $x === '' ? $sin : $x;
        };
        $op = static function(string $id) use ($data, $sin): string {
            $o = self::options($id);
            $x = (string) ($data[$id] ?? '');
            return isset($o[$x]) ? $o[$x] : $sin;
        };
        $s1 = $pre['s1'];
        $l = ['SECCIÓN 1. Identificación general',
            '- Plan formativo (nombre y código) [precargado]: ' . ($s1['plan'] !== '' ? $s1['plan'] : $sin),
            '- Módulo [precargado]: ' . ($s1['modulo'] !== '' ? $s1['modulo'] : $sin),
            '- Nombre de la experiencia de aprendizaje inicial [precargado]: ' . ($s1['exp_inicial'] !== '' ? $s1['exp_inicial'] : $sin),
            '- Nombre de la experiencia de aprendizaje de continuidad: ' . ($s1['exp_continuidad'] !== '' ? $s1['exp_continuidad']
                : $v('exp_cont')),
            '- Nombre de la persona facilitadora [precargado]: ' . ($s1['facilitador'] !== '' ? '(registrado)' : $sin),
            '- Fecha(s) de la experiencia inicial [precargado]: ' . ($s1['fechas_ini'] !== '' ? $s1['fechas_ini'] : $sin),
            '- Fecha(s) de la experiencia de continuidad: ' . $v('fechas_cont'),
            '- N.º de participantes presentes en la experiencia inicial [precargado]: ' . ($s1['presentes_ini'] ?? $sin),
            '- N.º de participantes presentes en la experiencia de continuidad: ' . $v('presentes_cont'),
            '- N.º de participantes evaluados al cierre del ciclo: ' . $v('evaluados'),
            '- Fecha del registro ex post [precargado]: ' . ($pre['fecha'] ?? $sin),
            '',
            'SECCIÓN 2. Logro final de los aprendizajes',
            '2.1 Logro por criterio de evaluación al cierre del ciclo'];
        foreach (self::rows($data, $pre) as $f) {
            $i = $f['inicial'];
            $l[] = '- Criterio ' . $f['k'] . ': ' . $f['criterio'];
            $l[] = '    Logro inicial (%) [precargado]: ' . ($i === null ? '(en blanco: criterio pendiente)' : implementacion::pct($i['logro']));
            $niv = [];
            foreach (implementacion::NIVELES as $clave => $nombre) {
                $niv[] = $nombre . ' ' . (self::vacio($data[$clave . $f['k']] ?? '') ? '(sin dato)' : $data[$clave . $f['k']]);
            }
            $l[] = '    N.º de participantes por nivel al cierre: ' . implode('; ', $niv);
            $l[] = '    Logro final (%) declarado en la ficha: ' . (self::vacio($data['lg' . $f['k']] ?? '') ? $sin
                : str_replace('.', ',', $data['lg' . $f['k']]) . ' %');
            $l[] = '    Comentario de la persona facilitadora: ' . (self::vacio($data['cm' . $f['k']] ?? '') ? '(sin comentario)' : $data['cm' . $f['k']]);
        }
        $l[] = '2.2 Pendientes de la experiencia inicial';
        $hay = false;
        foreach (self::rows($data, $pre) as $f) {
            if ($f['pendiente']) {
                $hay = true;
                $l[] = '- Criterio ' . $f['k'] . ' «' . $f['criterio'] . '»: ¿se trabajó y evaluó en la experiencia de continuidad? '
                    . $op('pr' . $f['k']) . '. Comentario: ' . (self::vacio($data['pc' . $f['k']] ?? '') ? '(sin comentario)' : $data['pc' . $f['k']]);
            }
        }
        if (!$hay) {
            $l[] = '- (no hubo pendientes)';
        }

        $l[] = '';
        $l[] = 'SECCIÓN 3. Efectividad de las estrategias didácticas y evaluativas';
        foreach (['d' => ['3.1 Efectividad de las estrategias didácticas', 'didacticas'],
                'e' => ['3.2 Efectividad de las estrategias e instrumentos evaluativos', 'evaluativas']] as $p => [$titulo, $lista]) {
            $l[] = $titulo;
            $hayfila = false;
            foreach (self::strategy_rows() as $j) {
                $libre = $j > self::MAX_ETIQUETAS;
                if ($libre) {
                    if (self::vacio($data[$p . $j . '_nombre'] ?? '') && self::vacio($data[$p . $j . '_val'] ?? '')
                            && self::vacio($data[$p . $j . '_ev'] ?? '')) {
                        continue;
                    }
                    $nombre = $v($p . $j . '_nombre') . ' (fila libre)';
                    $exp = $op($p . $j . '_exp');
                } else {
                    $e = ($etq[$lista] ?? [])[$j - 1] ?? null;
                    if ($e === null) {
                        continue;
                    }
                    $nombre = $e['etiqueta'] . ' [precargada]';
                    $exp = $e['exp'] === 'continuidad' ? 'Continuidad' : 'Inicial';
                }
                $hayfila = true;
                $l[] = '- ' . $nombre . ' | Experiencia: ' . $exp . ' | Efectividad: ' . $op($p . $j . '_val') . ' | Evidencia: ' . $v($p . $j . '_ev');
            }
            if (!$hayfila) {
                $l[] = '- (sin filas)';
            }
        }
        $l[] = '3.3 Valoración global de las estrategias';
        foreach (['v331', 'v332', 'v333', 'v334', 'v335', 'v336'] as $id) {
            $campo = self::sections()[3]['campos'][array_search($id, ['v331', 'v332', 'v333', 'v334', 'v335', 'v336'], true)];
            $l[] = '- ' . $campo['num'] . ' ' . $campo['label'] . ': ' . $op($id);
        }
        $l[] = '- Fundamentación: ' . $v('fund33');

        $l[] = '';
        $l[] = 'SECCIÓN 4. Reacción de las personas participantes';
        $fuentes = [];
        foreach (['f4_enc' => 'Encuesta aplicada por la persona facilitadora', 'f4_obs' => 'Observación y comentarios del grupo'] as $id => $t) {
            if (!self::vacio($data[$id] ?? '')) {
                $fuentes[] = $t;
            }
        }
        if (!self::vacio($data['f4_otra'] ?? '')) {
            $fuentes[] = 'Otra: ' . $v('f4_otra_txt');
        }
        $l[] = '- 4.1 Fuente de la información: ' . ($fuentes ? implode('; ', $fuentes) : $sin);
        $l[] = '- N.º de participantes que respondieron la encuesta: ' . (self::vacio($data['f4_enc'] ?? '') ? '(no se aplicó encuesta)' : $v('n_enc'));
        foreach (['v42' => '4.2 Satisfacción general con la formación recibida', 'v43' => '4.3 Pertinencia de la formación para el trabajo',
                'v44' => '4.4 Actividades y metodología de trabajo', 'v45' => '4.5 Materiales y recursos', 'v46' => '4.6 Organización y tiempos',
                'v47' => '4.7 Apoyo de la persona facilitadora'] as $id => $t) {
            $l[] = '- ' . $t . ': ' . $op($id);
        }
        $l[] = '- Datos que respaldan las valoraciones: ' . $v('datos4');
        $l[] = '- 4.8 Aspectos más valorados: ' . $v('mas48');
        $l[] = '- 4.9 Críticas y sugerencias: ' . $v('crit49');

        $l[] = '';
        $l[] = 'SECCIÓN 5. Transferencia de los aprendizajes al contexto laboral';
        $l[] = '- 5.1 ¿Hubo oportunidad de aplicar los aprendizajes en un contexto laboral?: ' . $op('op51');
        if (($data['op51'] ?? '') === self::NO) {
            $l[] = '- Motivo: ' . $v('motivo5');
        } else {
            $fuentes = [];
            foreach (['f5_auto' => 'Autorreporte de participantes', 'f5_jefe' => 'Jefatura o empleador',
                    'f5_seg' => 'Seguimiento de la persona facilitadora', 'f5_prac' => 'Práctica laboral'] as $id => $t) {
                if (!self::vacio($data[$id] ?? '')) {
                    $fuentes[] = $t;
                }
            }
            if (!self::vacio($data['f5_otra'] ?? '')) {
                $fuentes[] = 'Otra: ' . $v('f5_otra_txt');
            }
            $l[] = '- 5.2 Fuente de la información: ' . ($fuentes ? implode('; ', $fuentes) : $sin);
            $l[] = '- 5.3 Momento de la recogida de información: ' . $v('momento5');
            $l[] = '- 5.3 N.º de participantes con información de seguimiento: ' . $v('n_seg5');
            $l[] = '- 5.3 N.º de participantes que aplicaron lo aprendido en su trabajo: ' . $v('n_apl5');
            $l[] = '- 5.4 Grado de transferencia: ' . $op('grado54');
            $l[] = '- Ejemplos de aplicación: ' . $v('ejemplos5');
            $l[] = '- Factores que facilitaron u obstaculizaron la transferencia: ' . $v('factores5');
        }

        $l[] = '';
        $l[] = 'SECCIÓN 6. Efectividad de los ajustes';
        $l[] = '- 6.1 La trayectoria de nivelación permitió alcanzar el nivel de logro mínimo: ' . $op('v61');
        $l[] = '- 6.2 La trayectoria de profundización permitió profundizar el dominio: ' . $op('v62');
        $l[] = '- 6.3 Las acciones remediales o el rediseño resultaron efectivos: ' . $op('v63');
        $l[] = '- Fundamentación: ' . $v('fund6');
        $l[] = '6.4 Buenas prácticas propuestas en la Fase 3';
        foreach (array_values($etq['practicas'] ?? []) as $i => $practica) {
            $j = $i + 1;
            $l[] = '- ' . $practica . ' [precargada] | ' . $op('bp' . $j . '_dec') . ' | Fundamentación: ' . $v('bp' . $j . '_fund');
        }
        if (empty($etq['practicas'])) {
            $l[] = '- (sin buenas prácticas precargadas)';
        }

        $l[] = '';
        $l[] = 'SECCIÓN 7. Reflexión de la persona facilitadora';
        $l[] = '- 7.1 Decisiones que resultaron acertadas y por qué: ' . $v('r71');
        $l[] = '- 7.2 Qué haría distinto: ' . $v('r72');
        $l[] = '- 7.3 Qué aprendió sobre el grupo, la estrategia y el contexto: ' . $v('r73');
        $l[] = '- 7.4 Recomendaciones para una nueva ejecución del curso: ' . $v('r74');
        return implode("\n", $l);
    }

    /**
     * Huella de lo que se valida: la ficha ex post, la precarga y las etiquetas. Cambiar cualquiera pide validar de nuevo.
     *
     * @param array $data
     * @param string $precarga Huella de la precarga.
     * @param array $etq
     * @return string
     */
    public static function fingerprint(array $data, string $precarga, array $etq): string {
        ksort($data);
        return sha1(json_encode([$data, $precarga, $etq], JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------------------------------------------------------
    // Pedidos al modelo.
    // ------------------------------------------------------------------------------------------

    /**
     * Principios de la Fase 4 del prompt, comunes a todos los pedidos. Funcion pura.
     *
     * @return string[]
     */
    public static function principles(): array {
        return [
            'PRINCIPIOS DE LA FASE 4:',
            '- La evaluación ex post busca la mejora continua para futuras ejecuciones del curso, con otros grupos de participantes. '
                . 'No es una mejora de rápida implementación.',
            '- Principio de no regresión: un criterio que una persona ya logró (nivel Adecuado o Avanzado) se mantiene como logrado; '
                . 'una evidencia posterior de menor nivel no se considera. El nivel al cierre del ciclo es el nivel más alto demostrado '
                . 'por cada persona. Quien no participó en la experiencia de continuidad conserva el nivel de la experiencia inicial.',
            '- Como el logro final nunca es menor que el inicial, la efectividad de los ajustes se valora por los cambios de nivel de '
                . 'las personas, no solo por el aumento del logro.',
            '- Pendientes: solo los pendientes marcados «Sí» en la subsección 2.2 tienen niveles registrados. Los marcados '
                . '«Parcialmente» o «No» siguen pendientes y se informan como tales.',
            '- Los resultados desfavorables (logros bajos, reacciones negativas, ausencia de transferencia o buenas prácticas '
                . 'descartadas) son hallazgos valiosos para el análisis, nunca motivo de rechazo de la ficha.',
            '- La reacción de las personas participantes es un juicio de la persona facilitadora apoyado en datos; la transferencia solo '
                . 'se analiza cuando hubo oportunidad de aplicar lo aprendido.',
            '- Las recomendaciones son insumos para el diagnóstico de una nueva ejecución. No elabores una nueva versión del diseño: una '
                . 'nueva ejecución requiere un nuevo diagnóstico, porque el grupo y el contexto serán distintos.',
            '- No modifiques el aprendizaje esperado ni los criterios de evaluación del plan formativo.',
        ];
    }

    /**
     * Encabezado del rol, comun a los pedidos de la Fase 4. Funcion pura.
     *
     * @return string
     */
    private static function role_line(): string {
        return 'Eres el ' . implementacion::ROL . ': un asistente experto en diseño instruccional con enfoque de competencias, '
            . 'especializado en formación de personas adultas en contextos de capacitación laboral. En la Fase 4 (evaluación ex post) '
            . 'cierras el ciclo formativo de una experiencia de aprendizaje (experiencia inicial y experiencia de continuidad): validas la '
            . 'ficha de evaluación ex post, analizas los resultados del ciclo y elaboras un análisis metacognitivo del proceso que '
            . 'retroalimenta a la persona facilitadora, sistematiza las prácticas exitosas y entrega recomendaciones para una nueva '
            . 'ejecución del curso con otros grupos de participantes. Mantienes el rol, las reglas de gobernanza y el modo de interacción '
            . 'del prompt maestro. Tu comportamiento es estricto, estructurado, basado en evidencia y orientado a la mejora continua.';
    }

    /**
     * Instrucciones de la etapa 9 con el asistente: las etiquetas de estrategias, instrumentos y buenas practicas que se
     * precargan en las tablas 3.1, 3.2 y 6.4. Funcion pura.
     *
     * @param array $c diseno (texto del diseño), implementacion (ficha de registro como texto), previas (etapa => texto: 5 y 6),
     *        adjuntos (etiquetas), conlibreria y conbusqueda (bool).
     * @return string
     */
    public static function build_labels_prompt(array $c): string {
        $l = [];
        $l[] = self::role_line();
        $l[] = '';
        $l[] = 'TAREA: ETAPA 9: PREPARACIÓN DE LA FICHA EX POST (PRECARGA DE ETIQUETAS). Prepara las etiquetas que se precargan en el '
            . 'formulario en línea: las estrategias didácticas del ciclo (subsección 3.1), las estrategias o instrumentos evaluativos '
            . 'del ciclo (subsección 3.2) y las buenas prácticas identificadas en la Etapa 5 (subsección 6.4). Los demás datos ya los '
            . 'precarga la plataforma: no los prepares.';
        $l[] = '';
        $l[] = 'REGLAS PARA LAS ETIQUETAS DE ESTRATEGIAS, INSTRUMENTOS Y BUENAS PRÁCTICAS:';
        $l[] = '- Fuentes: estrategia didáctica del diseño; plan de evaluación e instrumentos del diseño; sustituciones e incorporaciones '
            . 'declaradas en las preguntas 3.1, 3.3 y 3.4 de la ficha de registro de la implementación; estrategias e instrumentos de la '
            . 'experiencia de continuidad (Etapa 6); buenas prácticas de la Etapa 5.';
        $l[] = '- Estrategias didácticas: una etiqueta por estrategia, no por actividad. Incluye la estrategia principal del diseño, las '
            . 'sustituidas o incorporadas en la implementación y las de la experiencia de continuidad.';
        $l[] = '- Estrategias e instrumentos evaluativos: una etiqueta por instrumento efectivamente aplicado. No precargues instrumentos '
            . 'omitidos.';
        $l[] = '- Formato: máximo ' . self::MAX_PALABRAS . ' palabras. Didáctica: «tipo de estrategia – foco principal». Evaluativa: '
            . '«instrumento – evidencia (criterios)». Buena práctica: una frase breve que nombre la práctica.';
        $l[] = '- Máximo ' . self::MAX_ETIQUETAS . ' etiquetas por tabla, priorizando las de mayor peso en el ciclo.';
        $l[] = '- Cada estrategia e instrumento lleva la experiencia a la que corresponde: «inicial» o «continuidad».';
        $l[] = '- Transcribe los nombres como figuran en los productos; no inventes estrategias, instrumentos ni prácticas.';
        $l[] = implementacion::NO_PEDAGOGIA;
        $l[] = '- Escribe las etiquetas en español de Chile.';
        $l[] = '';
        foreach (implementacion::knowledge_rules(!empty($c['conlibreria']), !empty($c['conbusqueda'])) as $regla) {
            $l[] = $regla;
        }
        $l[] = '- ' . implementacion::documents_rule($c['adjuntos'] ?? []);
        $l[] = '';
        $l[] = guardrails::injection_clause();
        $l[] = '';
        $l[] = 'FORMATO DE RESPUESTA: únicamente un objeto JSON válido, sin texto antes ni después y sin bloques de código, con esta forma:';
        $l[] = '{"didacticas": [{"etiqueta": "...", "experiencia": "inicial|continuidad"}], "evaluativas": [{"etiqueta": "...", '
            . '"experiencia": "inicial|continuidad"}], "practicas": ["..."]}';
        $l[] = '';
        $l[] = (string) ($c['diseno'] ?? '');
        $l[] = '';
        $l[] = 'FICHA DE REGISTRO DE LA IMPLEMENTACIÓN (VALIDADA EN LA ETAPA 0):';
        $l[] = guardrails::isolate_user_content((string) ($c['implementacion'] ?? ''));
        foreach (($c['previas'] ?? []) as $n => $texto) {
            $l[] = '';
            $l[] = 'PRODUCTO ACEPTADO DE LA ETAPA ' . $n . ' (' . (calidad::etapas()[$n]['titulo'] ?? '') . '):';
            $l[] = guardrails::isolate_user_content(\core_text::substr($texto, 0, self::PREVIA_MAX));
        }
        return implode("\n", $l);
    }

    /**
     * Regla de decision de la etapa 10, para el prompt. Funcion pura.
     *
     * @return string[]
     */
    public static function critical_rule_lines(): array {
        return [
            'REGLA DE DECISIÓN DE LA ETAPA 10 (aplícala de forma explícita). El checklist valida la calidad del registro, no los '
                . 'resultados del ciclo. Los aspectos críticos son los ítems marcados [CRÍTICO]:',
            '- Consistencia de los datos de logro (c2_1): en algún criterio, la suma de participantes por nivel no coincide con el número '
                . 'de participantes evaluados al cierre del ciclo.',
            '- Principio de no regresión (c2_2): en algún criterio, el número de participantes en niveles Adecuado o Avanzado al cierre es '
                . 'menor que en la experiencia inicial.',
            '- Coherencia general (c8_1): hay contradicciones profundas entre lo registrado en las distintas secciones de la ficha.',
            'Aplicación:',
            '- ❌ en cualquier ítem CRÍTICO → NO APROBADO.',
            '- ⚠ en cualquier ítem CRÍTICO → se trata como NO APROBADO hasta que la ficha se corrija. Única excepción: diferencias de forma '
                . 'que no alteran los datos (por ejemplo, errores de tipeo): evalúa ese ítem "parcial" con "forma": true y regístralo como '
                . 'observación.',
            '- Sin problemas en ítems críticos, pero con ⚠ o ❌ en ítems no críticos → APROBADO CON OBSERVACIONES.',
            '- Todos los ítems en ✔ → APROBADO.',
            '- "forma" va true solo en un ítem crítico "parcial" por una diferencia de forma; en todos los demás, false.',
            '- Los resultados desfavorables (logros bajos, reacción negativa, transferencia nula o buenas prácticas descartadas) no son motivo '
                . 'de rechazo: son hallazgos para el análisis metacognitivo. Una valoración sin datos que la respalden se marca ⚠ en un ítem '
                . 'no crítico.',
            '- La condición final la fija la plataforma con esta regla a partir de tus ítems: no la incluyas en tu respuesta.',
            '- Si el resultado es NO APROBADO, la "propuesta" de cada ítem crítico que no cumple indica el ajuste específico: la persona '
                . 'corrige la ficha en el formulario en línea y se vuelve a aplicar el checklist.',
        ];
    }

    /**
     * Instrucciones de validacion: etapa 10 del prompt de la Fase 4 con el checklist completo. Funcion pura.
     *
     * @param string[] $adjuntos Etiquetas de los documentos adjuntos (plan formativo, perfil ocupacional).
     * @param bool $conlibreria
     * @param bool $conbusqueda
     * @return string
     */
    public static function build_validation_prompt(array $adjuntos, bool $conlibreria = false, bool $conbusqueda = false): string {
        $checklist = [];
        foreach (self::checklist() as $n => $seccion) {
            $checklist[] = $n . '. ' . \core_text::strtoupper($seccion['titulo']);
            foreach ($seccion['items'] as $id => $texto) {
                $checklist[] = '   ' . $id . ': ' . $texto . (in_array($id, self::CRITICOS, true) ? ' [CRÍTICO]' : '');
            }
        }
        return implode("\n", [
            self::role_line(),
            '',
            'TAREA: aplica la ETAPA 10, VALIDACIÓN DE LA FICHA EX POST, a la ficha de evaluación ex post que viene más abajo. Aplica el '
                . 'checklist completo, sin omitir secciones ni ítems. No completes la ficha en lugar de la persona facilitadora y no '
                . 'avances a otras etapas: solo evalúas lo que la persona registró.',
            'La ficha ya pasó el control de entrada: todos sus campos obligatorios tienen respuesta. Completar un campo no implica '
                . 'aprobarlo: la consistencia de los datos y el respaldo de las valoraciones se evalúan en los ítems del checklist.',
            '',
            'Para cada ítem: evalúalo como "cumple" (✔ Cumple), "parcial" (⚠ Cumple parcialmente) o "no_cumple" (❌ No cumple). Registra '
                . 'una observación breve y específica cuando la evaluación sea "parcial" o "no_cumple", señalando el campo de la ficha que '
                . 'la origina. Cuando el ítem no es "cumple", agrega además una "propuesta": una mejora concreta, con la frase lista para '
                . 'copiar en la ficha e indicando en qué campo va. El texto de cada ítem y su condición de CRÍTICO los muestra la '
                . 'plataforma: no los transcribas.',
            '',
            'REGLAS DE GOBERNANZA:',
            '- La ficha ex post y los productos de las Fases 1 a 3 son la fuente principal. ' . implementacion::documents_rule($adjuntos),
            '- Los resultados desfavorables (logros bajos, reacciones negativas, ausencia de transferencia o buenas prácticas descartadas) '
                . 'son hallazgos valiosos para el análisis, nunca motivo de rechazo de la ficha: no bajes ningún ítem por ellos.',
            '- Los comentarios de la tabla 2.1 y las filas libres de las tablas 3.1 y 3.2 son opcionales: si vienen vacíos, no es un vacío de '
                . 'información. Si no hubo oportunidad de transferencia (5.1 = No), el ítem c5_1 se evalúa por la concreción del motivo y '
                . 'los ítems c5_3 y c5_4 no aplican: márcalos "cumple" y dilo en la observación. Si no se marcó la encuesta como fuente '
                . '(4.1), no se exigen sus resultados.',
            '- Una valoración sin datos que la respalden, o una justificación genérica, se marca "parcial" en el ítem no crítico que '
                . 'corresponda; la sinceridad no se castiga mientras la ficha sea coherente consigo misma.',
            '- El ítem c8_2 resume lo ya evaluado: no lo bajes por algo que ya señalaste en otro ítem; evalúalo solo sobre lo que sigue sin '
                . 'resolver.',
            '- Los ítems c1_2, c2_1, c2_2, c2_3, c2_4 y c5_2 los calcula la plataforma con las VERIFICACIONES DE LA PLATAFORMA que vienen '
                . 'más abajo: en esos ítems repite el resultado de esa verificación. Úsalas también como datos para tus otros ítems: no '
                . 'recalcules. Si el logro final (%) de algún criterio está mal calculado, la plataforma ya lo recalculó: menciónalo.',
            '- En c1_1 compara las fechas de la experiencia de continuidad con las de la experiencia inicial (precargada).',
            implementacion::NO_PEDAGOGIA,
            '- Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género.',
            '- No repitas en tus respuestas nombres de personas participantes: refiérete a ellas por su subgrupo o con "una persona '
                . 'participante".',
            '- Evalúas la ficha, no a la persona: no asignes notas ni puntajes.',
            '',
            implode("\n", self::principles()),
            '',
            implode("\n", implementacion::knowledge_rules($conlibreria, $conbusqueda)),
            '',
            'CHECKLIST (id: ítem):',
            implode("\n", $checklist),
            '',
            implode("\n", self::critical_rule_lines()),
            '',
            guardrails::injection_clause(),
            '',
            'FORMATO DE RESPUESTA: únicamente un objeto JSON válido, sin texto antes ni después y sin bloques de código, con esta forma:',
            '{"items": {"c1_1": {"estado": "cumple|parcial|no_cumple", "observacion": "...", "propuesta": "frase lista para copiar '
                . '(vacía si cumple)", "forma": false}, ... un elemento por cada id del checklist ...}, '
                . '"debilidades": ["principales debilidades, máximo 6"], '
                . '"sugerencias": ["sugerencias de mejora concretas para la ficha, máximo 6"]}',
        ]);
    }

    /**
     * Lee la respuesta del modelo en la validacion. Si falta un item o alguno no se entiende, el informe no sirve. Funcion pura.
     *
     * @param string|null $texto
     * @return array|null items (id => estado, observacion, propuesta, forma?), debilidades y sugerencias.
     */
    public static function parse_report(?string $texto): ?array {
        $json = exante::decode_json($texto);
        if (!$json || !isset($json['items']) || !is_array($json['items'])) {
            return null;
        }
        $corto = static function($valor, int $largo): string {
            return is_scalar($valor) ? \core_text::substr(trim((string) $valor), 0, $largo) : '';
        };
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
            $items[$id] = ['estado' => $estado, 'observacion' => $corto($item['observacion'] ?? '', 600),
                'propuesta' => $estado === implementacion::CUMPLE ? '' : $corto($item['propuesta'] ?? '', 600)];
            $forma = $item['forma'] ?? false;
            $esforma = is_bool($forma) ? $forma : (is_scalar($forma)
                && in_array(\core_text::strtolower(trim((string) $forma)), ['true', 'si', 'sí', '1'], true));
            if ($estado === implementacion::PARCIAL && in_array($id, self::CRITICOS, true) && $esforma) {
                $items[$id]['forma'] = true;
            }
        }
        $lista = static function($origen) use ($corto): array {
            $salida = [];
            foreach ((array) $origen as $valor) {
                $t = $corto($valor, 600);
                if ($t !== '') {
                    $salida[] = $t;
                }
            }
            return array_slice($salida, 0, 6);
        };
        return ['items' => $items, 'debilidades' => $lista($json['debilidades'] ?? []), 'sugerencias' => $lista($json['sugerencias'] ?? [])];
    }

    /**
     * Aplica al informe del modelo lo que decide el codigo: los items POR_CALCULO que system_checks() resolvio reemplazan a los del
     * modelo. Funcion pura.
     *
     * @param array $items Items del informe.
     * @param array $calculo items de system_checks().
     * @return array
     */
    public static function apply_system_checks(array $items, array $calculo): array {
        foreach ($calculo as $id => $item) {
            if (in_array($id, self::POR_CALCULO, true)) {
                $items[$id] = ['estado' => $item['estado'], 'observacion' => $item['observacion'],
                    'propuesta' => $item['propuesta'], 'origen' => 'calculo'];
            }
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
            $estado = $items[$id]['estado'] ?? implementacion::NO_CUMPLE;
            if ($estado === implementacion::NO_CUMPLE || ($estado === implementacion::PARCIAL && empty($items[$id]['forma']))) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Que parte de la regla del cliente decidio la condicion, para mostrarla. Funcion pura.
     *
     * @param array $items
     * @return string critico_no, critico_parcial, forma, no_criticos o todo_cumple.
     */
    public static function decision_rule(array $items): string {
        foreach (self::critical_failures($items) as $id) {
            if (($items[$id]['estado'] ?? implementacion::NO_CUMPLE) === implementacion::NO_CUMPLE) {
                return 'critico_no';
            }
        }
        if (self::critical_failures($items)) {
            return 'critico_parcial';
        }
        $forma = false;
        $otros = false;
        foreach (self::checklist_ids() as $id) {
            if (($items[$id]['estado'] ?? implementacion::NO_CUMPLE) === implementacion::CUMPLE) {
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
     * Condicion final, con la regla del cliente solo sobre los items. Funcion pura.
     *
     * @param array $items
     * @return string implementacion::APROBADO, OBSERVACIONES o NO_APROBADO.
     */
    public static function decide(array $items): string {
        if (self::critical_failures($items)) {
            return implementacion::NO_APROBADO;
        }
        foreach (self::checklist_ids() as $id) {
            if (($items[$id]['estado'] ?? implementacion::NO_CUMPLE) !== implementacion::CUMPLE) {
                return implementacion::OBSERVACIONES;
            }
        }
        return implementacion::APROBADO;
    }

    /**
     * Registro de observaciones no resueltas de la etapa 10: items en ⚠ o ❌. Se usa en las etapas 11 y 12. Funcion pura.
     *
     * @param array $items
     * @return array id => ['seccion', 'texto', 'estado', 'observacion'].
     */
    public static function unresolved_items(array $items): array {
        $salida = [];
        foreach (self::checklist() as $n => $seccion) {
            foreach ($seccion['items'] as $id => $texto) {
                $estado = $items[$id]['estado'] ?? null;
                if ($estado === null || $estado === implementacion::CUMPLE) {
                    continue;
                }
                $salida[$id] = ['seccion' => $n, 'texto' => $texto, 'estado' => $estado,
                    'observacion' => trim((string) ($items[$id]['observacion'] ?? ''))];
            }
        }
        return $salida;
    }

    /**
     * Resumen del informe de validacion para los productos de las etapas 11 a 14. Funcion pura.
     *
     * @param string $condicion
     * @param array $informe
     * @return string
     */
    public static function report_summary(string $condicion, array $informe): string {
        $nombres = [implementacion::APROBADO => '✔ Aprobado', implementacion::OBSERVACIONES => '⚠ Aprobado con observaciones',
            implementacion::NO_APROBADO => '❌ No aprobado'];
        $l = ['RESULTADO DE LA VALIDACIÓN DE LA FICHA EX POST (ETAPA 10): ' . ($nombres[$condicion] ?? $condicion) . '.'];
        $pend = self::unresolved_items((array) ($informe['items'] ?? []));
        if ($pend) {
            $l[] = 'Registro de observaciones no resueltas de la Etapa 10 (ítems ⚠ o ❌), para considerarlas en las etapas 11 y 12:';
            foreach ($pend as $id => $p) {
                $l[] = '- Sección ' . $p['seccion'] . ', ' . $id . ' «' . $p['texto'] . '»: '
                    . ($p['estado'] === implementacion::NO_CUMPLE ? 'no cumple' : 'cumple parcialmente')
                    . ($p['observacion'] !== '' ? ' — ' . $p['observacion'] : '');
            }
        } else {
            $l[] = 'La validación de la ficha ex post (Etapa 10) no dejó observaciones no resueltas.';
        }
        foreach (array_merge((array) ($informe['debilidades'] ?? []), (array) ($informe['sugerencias'] ?? [])) as $o) {
            $l[] = '- ' . $o;
        }
        return implode("\n", $l);
    }

    /**
     * Instrucciones al modelo para producir (o ajustar) el producto de una etapa (11 a 14). Funcion pura.
     *
     * @param int $etapa
     * @param array $c Contexto: ficha (texto de la ficha ex post), datos (results_text()), informe y condicion (de la validacion),
     *        implementacion (ficha de registro), diseno (texto), previas (etapa => texto aceptado), adjuntos, actual, ajustes,
     *        conlibreria y conbusqueda.
     * @return string
     */
    public static function build_prompt(int $etapa, array $c): string {
        $e = self::etapas()[$etapa];
        $l = [];
        $l[] = self::role_line();
        $l[] = '';
        $l[] = 'TAREA: ETAPA ' . $etapa . ': ' . \core_text::strtoupper($e['titulo']) . '.';
        $l[] = 'Desarrolla solo esta etapa; no adelantes las siguientes. Debe incluir obligatoriamente:';
        foreach ($e['incluye'] as $item) {
            $l[] = '- ' . $item;
        }
        foreach ($e['indicaciones'] as $indicacion) {
            $l[] = $indicacion;
        }
        $l[] = '';
        $l[] = implode("\n", self::principles());
        $l[] = '';
        $l[] = 'REGLAS DE GOBERNANZA:';
        $l[] = '- Usa como fuente principal la ficha de evaluación ex post validada, los resultados calculados por la plataforma y los '
            . 'productos de las Fases 1 a 3 del mismo ciclo. ' . implementacion::documents_rule($c['adjuntos'] ?? []);
        $l[] = '- Fundamenta todo juicio en evidencia concreta del ciclo (datos, porcentajes, evidencias y comentarios); no emitas juicios '
            . 'sin fundamento ni atribuyas intenciones a la persona facilitadora.';
        $l[] = '- No inventes aprendizajes esperados ni criterios de evaluación: transcríbelos tal como están en el diseño y en la ficha.';
        $l[] = implementacion::NO_PEDAGOGIA;
        $l[] = '- Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género.';
        $l[] = '- No repitas nombres de personas participantes: refiérete a ellas por su subgrupo o como «personas participantes».';
        $l[] = '- Escribe todo el producto en español de Chile, también las tablas, los vacíos y la verificación: nunca en inglés ni en otro '
            . 'idioma.';
        $l[] = '- Detecta vacíos de información y repórtalos.';
        $l[] = '';
        foreach (implementacion::knowledge_rules(!empty($c['conlibreria']), !empty($c['conbusqueda'])) as $regla) {
            $l[] = $regla;
        }
        $l[] = '- No uses citas numéricas ni marcas entre corchetes dentro del texto (como [1] o [2]). Al final del producto, después de la '
            . '«Verificación de calidad de la etapa», agrega la sección «### Referencias consultadas» con una lista de viñetas de los '
            . 'documentos que usaste (de la librería de conocimientos, el Plan Formativo o el Perfil Ocupacional), por su nombre. Si no '
            . 'usaste ninguno aparte de la ficha y los productos del ciclo, escribe «Sin referencias adicionales a la ficha». Las fuentes '
            . 'web las agrega la plataforma.';
        $l[] = '- Diagramas: nunca dibujes cajas, líneas o flechas con caracteres (│ ─ ┌ ▼ →) ni texto alineado con espacios. Si un diagrama '
            . 'ayuda, entrégalo en un bloque de código con la etiqueta «diagrama»: una línea por caja, con «Título / detalle»; puedes agregar '
            . '«titulo: ...» y «modo: ciclo» si los pasos se repiten en círculo. Entre 2 y 12 cajas.';
        $l[] = diseno::REGLA_FORMULAS;
        $l[] = '';
        $l[] = 'CONTROL DE CALIDAD ENTRE ETAPAS: antes de cerrar la etapa verifica la coherencia con la ficha ex post y con los productos de '
            . 'las Fases 1 a 3, que los cálculos respeten el principio de no regresión y el tratamiento de los pendientes, que cada juicio '
            . 'esté fundamentado en evidencia del ciclo, que las recomendaciones no constituyan un nuevo diseño y detecta inconsistencias. '
            . 'Termina el documento con una sección breve «Verificación de calidad de la etapa» que diga qué verificaste y, si detectas una '
            . 'inconsistencia, qué ajuste se necesita.';
        $l[] = '';
        $l[] = 'FORMATO: entrega el producto en Markdown, estructurado, institucional y aplicable, listo para copiar en un documento Word: '
            . 'títulos con ## y ###, listas y tablas Markdown cuando ayuden. Empieza directamente con el título «## Etapa ' . $etapa . ': '
            . $e['titulo'] . '». No agregues saludos, preguntas al final ni comentarios fuera del producto: la plataforma ya le pregunta a la '
            . 'persona si quiere ajustes.';
        $l[] = 'ESTRUCTURA OBLIGATORIA (la plataforma revisa que esté todo): cada elemento de la lista «Debe incluir obligatoriamente» aparece '
            . 'con su nombre, como sección «### N. Nombre» cuando es un apartado del producto, o como columna de tabla o rótulo en negrita '
            . 'cuando es un dato de cada fila o práctica. No omitas ninguno. El producto termina siempre con estas tres secciones, en este '
            . 'orden: «### ' . diseno::SECCION_VACIOS . '» (si no hay vacíos, dilo en una línea), «### ' . diseno::SECCION_VERIFICACION
            . '» y «### ' . diseno::SECCION_REFERENCIAS . '».';
        $l[] = '';
        $l[] = guardrails::injection_clause();
        $l[] = '';
        $l[] = 'FICHA DE EVALUACIÓN EX POST (VALIDADA EN LA ETAPA 10):';
        $l[] = guardrails::isolate_user_content((string) ($c['ficha'] ?? ''));
        if (!empty($c['condicion'])) {
            $l[] = '';
            $l[] = self::report_summary((string) $c['condicion'], (array) ($c['informe'] ?? []));
        }
        $l[] = '';
        $l[] = (string) ($c['datos'] ?? '');
        $l[] = '';
        $l[] = (string) ($c['diseno'] ?? '');
        if (trim((string) ($c['implementacion'] ?? '')) !== '') {
            $l[] = '';
            $l[] = 'FICHA DE REGISTRO DE LA IMPLEMENTACIÓN (FASE 1, VALIDADA EN LA ETAPA 0):';
            $l[] = guardrails::isolate_user_content((string) $c['implementacion']);
        }
        $titulos = array_map(static function(array $x): string {
            return $x['titulo'];
        }, calidad::etapas()) + array_map(static function(array $x): string {
            return $x['titulo'];
        }, self::etapas());
        foreach (($c['previas'] ?? []) as $n => $texto) {
            $l[] = '';
            $l[] = 'PRODUCTO ACEPTADO DE LA ETAPA ' . $n . ' (' . ($titulos[$n] ?? '') . '):';
            $l[] = guardrails::isolate_user_content(\core_text::substr($texto, 0, self::PREVIA_MAX));
        }
        if (trim((string) ($c['ajustes'] ?? '')) !== '') {
            $l[] = '';
            $l[] = 'PRODUCTO ACTUAL DE ESTA ETAPA (versión que la persona quiere ajustar):';
            $l[] = guardrails::isolate_user_content(\core_text::substr((string) ($c['actual'] ?? ''), 0, self::PREVIA_MAX));
            $l[] = '';
            $l[] = 'AJUSTES QUE PIDE LA PERSONA: aplícalos y entrega el producto completo de la etapa, no solo los cambios. Si un ajuste '
                . 'contradice la ficha, los datos calculados o las reglas, no lo apliques y explícalo en la verificación de calidad.';
            $l[] = guardrails::isolate_user_content((string) $c['ajustes']);
        }
        return implode("\n", $l);
    }

    /**
     * Instrucciones de la etapa 15 con el asistente: consolidar los entregables «en extenso» sin repetir los campos comunes.
     *
     * @param string[] $partes Numero de parte (10 a 14) => producto (HTML).
     * @return string
     */
    public static function build_consolidation_prompt(array $partes): string {
        $titulos = self::titulos();
        $l = [];
        $l[] = self::role_line();
        $l[] = '';
        $l[] = 'TAREA: ETAPA 15: CONSOLIDACIÓN (OBLIGATORIA). Consolida todos los entregables de la Fase 4 "en extenso" en un documento '
            . 'único listo para descargar en Word, sin repetir aquellos campos que puedan ser comunes. El documento tiene estas partes, en '
            . 'este orden: validación de la ficha ex post, resultados del ciclo formativo, análisis metacognitivo del proceso, prácticas '
            . 'exitosas sistematizadas y recomendaciones para una nueva ejecución. Titula cada parte «Etapa N: Nombre» (de la 10 a la 14).';
        $l[] = '';
        $l[] = 'REGLAS:';
        $l[] = '- "En extenso": conserva todo el contenido de cada etapa: tablas, datos, análisis, prácticas y recomendaciones completas. No '
            . 'resumas ni acortes.';
        $l[] = '- Sin repetir campos comunes: los datos que se repiten entre etapas (nombre de la experiencia, plan formativo, participantes, '
            . 'criterios de evaluación) van una sola vez, en una sección inicial de datos generales; en las partes siguientes no los vuelvas '
            . 'a transcribir.';
        $l[] = '- No agregues contenido nuevo ni cambies decisiones: solo reorganizas lo que la persona aceptó. Las recomendaciones siguen '
            . 'siendo insumos para el diagnóstico de una nueva ejecución, no un nuevo diseño.';
        $l[] = '- Cada parte conserva todas las secciones de su etapa, con sus nombres, incluidas «' . diseno::SECCION_VACIOS . '» y «'
            . diseno::SECCION_VERIFICACION . '» de esa etapa, al final de la parte.';
        $l[] = '- No uses citas numéricas ni marcas entre corchetes dentro del texto (como [1] o [2]). Reúne las referencias de las etapas '
            . '(incluidas las fuentes web) en una sola sección «Referencias consultadas» al final del documento, sin repetirlas.';
        $l[] = '- Diagramas: conserva las tablas de cajas con flechas de las etapas como bloques de código con la etiqueta «diagrama» (una '
            . 'línea por caja, «Título / detalle»), sin dibujarlas nunca con caracteres.';
        $l[] = diseno::REGLA_FORMULAS . ' Si una etapa trae una fórmula en LaTeX, pásala a texto.';
        $l[] = implementacion::NO_PEDAGOGIA;
        $l[] = '- Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género.';
        $l[] = '- Escribe todo el producto en español de Chile: nunca en inglés ni en otro idioma.';
        $l[] = '';
        $l[] = guardrails::injection_clause();
        $l[] = '';
        $l[] = 'FORMATO DE RESPUESTA: el documento completo en Markdown (títulos con #, listas y tablas Markdown), sin texto antes ni después '
            . 'del documento.';
        ksort($partes);
        foreach ($partes as $n => $html) {
            $l[] = '';
            $l[] = 'PRODUCTO ACEPTADO DE LA ETAPA ' . $n . ' (' . ($titulos[$n] ?? '') . '):';
            $l[] = guardrails::isolate_user_content((string) $html);
        }
        return implode("\n", $l);
    }

    // ------------------------------------------------------------------------------------------
    // El informe de validacion, la revision del producto y los documentos.
    // ------------------------------------------------------------------------------------------

    /**
     * El informe de la validacion de la ficha (etapa 10) como HTML, con las cinco salidas obligatorias del prompt: resultados por
     * seccion, decision con la regla aplicada, principales debilidades, sugerencias y registro de observaciones no resueltas. Es la
     * parte «Etapa 10» del documento consolidado. Funcion pura.
     *
     * @param string $condicion aprobado, observaciones o no_aprobado.
     * @param array $informe
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
            'critico_parcial' => 'Hay al menos un ítem CRÍTICO en ⚠ que no es una diferencia de forma: se trata como No aprobado hasta '
                . 'que la ficha se corrija.',
            'forma' => 'Los ítems críticos solo tienen diferencias de forma: Aprobado con observaciones.',
            'no_criticos' => 'Los ítems críticos cumplen; hay ⚠ o ❌ en ítems no críticos: Aprobado con observaciones.',
            'todo_cumple' => 'Todos los ítems cumplen: Aprobado.'];
        $h = '<h2>Etapa 10: Validación de la ficha ex post</h2>';
        $h .= '<h3>1. Resultados por sección</h3>';
        foreach (self::checklist() as $n => $seccion) {
            $h .= '<h4>Sección ' . $n . '. ' . $e($seccion['titulo']) . '</h4><table><thead><tr><th>Ítem</th><th>Evaluación</th>'
                . '<th>Observación</th></tr></thead><tbody>';
            foreach ($seccion['items'] as $id => $texto) {
                $item = $informe['items'][$id] ?? null;
                $obs = $item ? trim((string) ($item['observacion'] ?? '')) : '';
                if ($item && !empty($item['propuesta'])) {
                    $obs .= ($obs !== '' ? ' ' : '') . 'Cómo corregirlo: ' . $item['propuesta'];
                }
                $h .= '<tr><td>' . $e($texto) . (in_array($id, self::CRITICOS, true) ? ' <strong>(CRÍTICO)</strong>' : '')
                    . '</td><td>' . $e($item ? ($estados[$item['estado']] ?? $item['estado']) : 'Sin evaluar') . '</td><td>'
                    . $e($obs) . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }
        $h .= '<h3>2. Decisión</h3><p><strong>' . $e($condiciones[$condicion] ?? $condicion) . '</strong></p>';
        if (!empty($informe['regla']) && isset($reglas[$informe['regla']])) {
            $h .= '<p>' . $e($reglas[$informe['regla']]) . '</p>';
        }
        foreach (['debilidades' => '3. Principales debilidades', 'sugerencias' => '4. Sugerencias de mejora'] as $clave => $titulo) {
            $h .= '<h3>' . $titulo . '</h3>';
            $lista = (array) ($informe[$clave] ?? []);
            $h .= $lista ? '<ul><li>' . implode('</li><li>', array_map($e, $lista)) . '</li></ul>' : '<p>No se registran.</p>';
        }
        $h .= '<h3>5. Registro de observaciones no resueltas</h3>';
        $pend = self::unresolved_items((array) ($informe['items'] ?? []));
        if ($pend) {
            $h .= '<ul>';
            foreach ($pend as $p) {
                $h .= '<li>Sección ' . $p['seccion'] . ': ' . $e($p['texto']) . ' — ' . ($p['estado'] === implementacion::NO_CUMPLE
                    ? 'no cumple' : 'cumple parcialmente') . ($p['observacion'] !== '' ? '. ' . $e($p['observacion']) : '') . '</li>';
            }
            $h .= '</ul>';
        } else {
            $h .= '<p>No quedaron observaciones sin resolver.</p>';
        }
        return $h;
    }

    /**
     * Revision automatica de una etapa: lo que pide la etapa y no aparece en el producto, mas el cierre comun (vacios,
     * verificacion y referencias). Para la consolidacion (etapa 15): las partes 10 a 14. Funcion pura.
     *
     * @param int $etapa 11 a 15.
     * @param string $html Producto (ya normalizado).
     * @return string[] Nombres de lo que falta.
     */
    public static function missing_items(int $etapa, string $html): array {
        if (trim(strip_tags($html)) === '') {
            return [];
        }
        if ($etapa === self::CONSOLIDACION) {
            return array_merge(self::missing_parts($html), array_map(static function(string $frase): string {
                return 'Texto en español (hay un párrafo en inglés: «' . $frase . '…»)';
            }, diseno::english_paragraphs($html)));
        }
        $e = self::etapas()[$etapa] ?? null;
        if (!$e) {
            return [];
        }
        $estructura = diseno::structure_text($html);
        $faltan = [];
        foreach ($e['comprobar'] as $item) {
            if (!diseno::has_item($item, $estructura)) {
                $faltan[] = $item;
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
     * Partes que faltan en la consolidacion: cada una de las etapas 10 a 14 (titulo «Etapa N» o «Parte N»). Funcion pura.
     *
     * @param string $html
     * @return string[]
     */
    private static function missing_parts(string $html): array {
        preg_match_all('#<h[1-3]\b[^>]*>\s*(?:<[^>]+>\s*)*(?:parte|etapa)\s+(\d+)\b#iu', $html, $m);
        $hay = array_map('intval', $m[1]);
        $faltan = [];
        foreach ([10, 11, 12, 13, 14] as $n) {
            if (!in_array($n, $hay, true)) {
                $faltan[] = 'Etapa ' . $n . ': ' . self::titulos()[$n];
            }
        }
        return $faltan;
    }

    /**
     * Huella de lo que se consolida: las etapas 11 a 14 aceptadas y la validacion. Si cambia, hay que consolidar de nuevo.
     *
     * @param \stdClass[] $filas etapa => fila.
     * @param string $validacion Huella de la validacion de la ficha ex post.
     * @return string
     */
    public static function stages_fingerprint(array $filas, string $validacion = ''): string {
        $partes = [];
        foreach (self::ETAPAS as $n) {
            $fila = $filas[$n] ?? null;
            $partes[$n] = ($fila && $fila->estado === calidad::ACEPTADO) ? (string) $fila->contenido : '';
        }
        return sha1(json_encode([$partes, $validacion], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Etapas de la Fase 4 aceptadas. La consolidacion cuenta solo si las etapas no cambiaron despues de confirmarla.
     *
     * @param \stdClass[] $filas
     * @param string $validacion Huella de la validacion vigente.
     * @return int[]
     */
    public static function accepted(array $filas, string $validacion = ''): array {
        $salida = [];
        $huella = self::stages_fingerprint($filas, $validacion);
        foreach (array_merge(self::ETAPAS, [self::CONSOLIDACION]) as $n) {
            $fila = $filas[$n] ?? null;
            if (!$fila || $fila->estado !== calidad::ACEPTADO) {
                continue;
            }
            if ($n === self::CONSOLIDACION && (string) $fila->huella !== $huella) {
                continue;
            }
            $salida[] = $n;
        }
        return $salida;
    }

    /**
     * Documento consolidado por codigo: la etapa 10 y las etapas 11 a 14 aceptadas, tal cual, en orden («en extenso»).
     *
     * @param string $etapa10 HTML de la etapa 10 (validation_html()).
     * @param string[] $contenidos etapa => HTML del producto aceptado.
     * @return string HTML.
     */
    public static function consolidate(string $etapa10, array $contenidos): string {
        ksort($contenidos);
        $html = trim($etapa10) . "\n<hr>";
        foreach ($contenidos as $n => $contenido) {
            if (in_array($n, self::ETAPAS, true)) {
                $html .= "\n" . trim((string) $contenido) . "\n<hr>";
            }
        }
        return trim($html);
    }

    /**
     * La consolidacion que se muestra y se confirma: la guardada si corresponde a las etapas de hoy; si no, la completa por
     * codigo. Funcion pura.
     *
     * @param \stdClass|null $fila Fila de la etapa 15.
     * @param string $etapa10 HTML de la etapa 10.
     * @param string[] $contenidos Productos de las etapas 11 a 14 (HTML).
     * @param string $huella Huella de las etapas de hoy.
     * @return array [html, si es del asistente (bool)].
     */
    public static function consolidation_shown(?\stdClass $fila, string $etapa10, array $contenidos, string $huella): array {
        if ($fila && trim((string) $fila->contenido) !== '' && (string) $fila->huella === $huella) {
            return [diseno::complete_consolidation((string) $fila->contenido, [10 => $etapa10] + $contenidos, 14),
                !empty($fila->promptversion)];
        }
        return [self::consolidate($etapa10, $contenidos), false];
    }

    /**
     * Datos de la portada del documento final (documento_final::build()). Funcion pura.
     *
     * @param array $pre preload().
     * @param array $data Ficha ex post ya limpia.
     * @param string $fecha Fecha de la consolidacion, ya con formato.
     * @return array
     */
    public static function final_cover(array $pre, array $data, string $fecha): array {
        $limpio = static function($v): string {
            return trim(rtrim(trim((string) $v), '.'));
        };
        $s1 = $pre['s1'] ?? [];
        $lineas = [];
        foreach ([['Plan formativo', $limpio($s1['plan'] ?? '')], ['Módulo', $limpio($s1['modulo'] ?? '')],
                ['Experiencia de continuidad', $limpio(($s1['exp_continuidad'] ?? '') !== '' ? $s1['exp_continuidad'] : ($data['exp_cont'] ?? ''))],
                ['Fecha(s) de la experiencia inicial', $limpio($s1['fechas_ini'] ?? '')],
                ['Fecha(s) de la experiencia de continuidad', $limpio($data['fechas_cont'] ?? '')],
                ['Fecha del registro ex post', $limpio($pre['fecha'] ?? '')]] as $x) {
            if ($x[1] !== '') {
                $lineas[] = $x;
            }
        }
        if ($fecha !== '') {
            $lineas[] = ['Fecha del informe', $fecha];
        }
        $titulo = $limpio($s1['exp_inicial'] ?? '');
        return ['etiqueta' => 'Evaluación ex post del ciclo formativo',
            'titulo' => $titulo !== '' ? $titulo : 'Experiencia de aprendizaje evaluada',
            'subtitulo' => 'Informe de evaluación ex post, análisis metacognitivo y recomendaciones para una nueva ejecución',
            'lineas' => $lineas,
            'encabezado' => 'Evaluación ex post · Informe consolidado'];
    }

    // ------------------------------------------------------------------------------------------
    // Reglas de acceso y de avance.
    // ------------------------------------------------------------------------------------------

    /**
     * Condicion de activacion del prompt: las Fases 1 a 3 completas y consolidadas, y la experiencia de continuidad ya
     * implementada. Funcion pura.
     *
     * @param bool $fases Fases 1 a 3 completas y consolidadas (la consolidacion vigente).
     * @param bool $continuidad Si la persona confirmo que implemento la experiencia de continuidad.
     * @return string|null null si se puede iniciar; 'fases' o 'continuidad' segun lo que falta.
     */
    public static function activation(bool $fases, bool $continuidad): ?string {
        if (!$fases) {
            return 'fases';
        }
        return $continuidad ? null : 'continuidad';
    }

    /**
     * Si la persona puede abrir una hoja de trabajo (11 a 15): necesita la ficha ex post validada y vigente y todas las etapas
     * anteriores de la fase aceptadas. Funcion pura.
     *
     * @param int $etapa
     * @param bool $ficha Ficha validada con Aprobado o Aprobado con observaciones, vigente.
     * @param int[] $aceptadas
     * @return bool
     */
    public static function can_open(int $etapa, bool $ficha, array $aceptadas): bool {
        if (!$ficha || $etapa < 11 || $etapa > self::CONSOLIDACION) {
            return false;
        }
        for ($i = 11; $i < $etapa; $i++) {
            if (!in_array($i, $aceptadas, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Etapa en la que corresponde trabajar: la primera no aceptada (o la ultima si todas lo estan). Funcion pura.
     *
     * @param int[] $aceptadas
     * @return int
     */
    public static function current_stage(array $aceptadas): int {
        for ($n = 11; $n <= self::CONSOLIDACION; $n++) {
            if (!in_array($n, $aceptadas, true)) {
                return $n;
            }
        }
        return self::CONSOLIDACION;
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
     * Si una etapa pertenece a la Fase 4 (las ocho del Prompt Maestro del Modulo 3 son las anteriores). Funcion pura.
     *
     * @param int $etapa
     * @return bool
     */
    public static function is_phase4(int $etapa): bool {
        return $etapa >= self::ETAPA_PRECARGA && $etapa <= self::CONSOLIDACION;
    }

    // ------------------------------------------------------------------------------------------
    // Base de datos.
    // ------------------------------------------------------------------------------------------

    /**
     * La ficha ex post de una evaluacion (una por ficha de implementacion), con sus datos decodificados.
     *
     * @param int $implid Ficha de registro de la implementacion.
     * @return \stdClass|null Con data (ficha limpia), pre (precarga o null) y etq (etiquetas, [] si aun no hay).
     */
    public static function get_row(int $implid): ?\stdClass {
        global $DB;
        $r = $DB->get_record('local_chatbot_expost', ['implid' => $implid]);
        return $r ? self::decode_row($r) : null;
    }

    /**
     * @param \stdClass $r Fila de la base.
     * @return \stdClass
     */
    private static function decode_row(\stdClass $r): \stdClass {
        $r->data = self::clean((array) json_decode((string) $r->data, true));
        $pre = $r->precarga ? json_decode((string) $r->precarga, true) : null;
        $r->pre = is_array($pre) ? $pre : null;
        $etq = $r->etiquetas ? json_decode((string) $r->etiquetas, true) : null;
        $r->etq = is_array($etq) ? $etq + ['didacticas' => [], 'evaluativas' => [], 'practicas' => []]
            : ['didacticas' => [], 'evaluativas' => [], 'practicas' => []];
        return $r;
    }

    /**
     * La ficha ex post de la evaluacion; la crea vacia si no existe.
     *
     * @param \stdClass $impl Ficha de registro de la implementacion.
     * @return \stdClass
     */
    public static function ensure_row(\stdClass $impl): \stdClass {
        global $DB;
        $r = self::get_row((int) $impl->id);
        if ($r) {
            return $r;
        }
        $now = time();
        $DB->insert_record('local_chatbot_expost', (object) ['implid' => (int) $impl->id, 'userid' => (int) $impl->userid,
            'courseid' => (int) $impl->courseid, 'data' => '{}', 'continuidad' => 0, 'timecontinuidad' => 0, 'timecreated' => $now,
            'timemodified' => $now]);
        return self::get_row((int) $impl->id);
    }

    /**
     * La persona declara que la experiencia de continuidad ya se implemento (condicion de activacion del prompt).
     *
     * @param int $exid
     * @return void
     */
    public static function confirm_continuity(int $exid): void {
        global $DB;
        $DB->update_record('local_chatbot_expost', (object) ['id' => $exid, 'continuidad' => 1, 'timecontinuidad' => time(),
            'timemodified' => time()]);
    }

    /**
     * Guarda las respuestas de la ficha ex post.
     *
     * @param int $exid
     * @param array $data Ficha ya limpia.
     * @return void
     */
    public static function save_data(int $exid, array $data): void {
        global $DB;
        $DB->update_record('local_chatbot_expost', (object) ['id' => $exid, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'timemodified' => time()]);
    }

    /**
     * Estado de la persona en la Fase 4, leido de la base: la ficha de implementacion en uso, la validacion de la Fase 1, las etapas
     * de las Fases 2 a 4, si las Fases 1 a 3 estan completas y consolidadas, y la ficha ex post con su validacion.
     *
     * @param int $userid
     * @param int $courseid
     * @return array impl, fase1, filas, fases (bool), row, val, vigente, ok (la validacion habilita las etapas 11 a 15), aceptadas
     *         (etapas 11 a 15), activacion (null o 'fases' / 'continuidad').
     */
    public static function load(int $userid, int $courseid): array {
        $impl = implementacion::get_ficha($userid, $courseid);
        $fase1 = implementacion::fase1_ok($userid, $courseid);
        $filas = $impl ? calidad::get_by_impl((int) $impl->id) : [];
        $aceptadas3 = calidad::accepted($filas, $fase1 ? (string) $fase1->huella : '');
        $fases = $fase1 && count(array_intersect(range(1, 8), $aceptadas3)) === 8;
        $row = $impl ? self::get_row((int) $impl->id) : null;
        $val = $row ? self::latest_validation_for((int) $row->id) : null;
        $vigente = $row && $val && $val->huella === self::row_fingerprint($row);
        $ok = $vigente && $val->estado === implementacion::LISTO && implementacion::can_advance($val->condicion);
        return ['impl' => $impl, 'fase1' => $fase1, 'filas' => $filas, 'fases' => (bool) $fases, 'row' => $row, 'val' => $val,
            'vigente' => (bool) $vigente, 'ok' => (bool) $ok,
            'aceptadas' => $row ? self::accepted($filas, $val ? (string) $val->huella : '') : [],
            'activacion' => self::activation((bool) $fases, $row && !empty($row->continuidad))];
    }

    /**
     * Estado de la Fase 4 de una evaluacion para la barra de ruta y la lista de evaluaciones.
     *
     * @param \stdClass|null $impl Ficha de registro de la implementacion.
     * @return array fases (bool), continuidad (bool), ficha (ruta_m3::FICHA_*), aceptadas (int[]), borradores (int[]).
     */
    public static function route_state(?\stdClass $impl): array {
        $vacio = ['fases' => false, 'continuidad' => false, 'ficha' => ruta_m3::FICHA_SIN, 'aceptadas' => [], 'borradores' => []];
        if (!$impl) {
            return $vacio;
        }
        $fase1 = implementacion::latest_validation_for((int) $impl->id);
        $filas = calidad::get_by_impl((int) $impl->id);
        $a3 = calidad::accepted($filas, $fase1 ? (string) $fase1->huella : '');
        $fases = $fase1 && $fase1->estado === implementacion::LISTO && implementacion::can_advance($fase1->condicion)
            && $fase1->huella === implementacion::ficha_fingerprint($impl) && count(array_intersect(range(1, 8), $a3)) === 8;
        $row = self::get_row((int) $impl->id);
        if (!$row) {
            return ['fases' => (bool) $fases] + $vacio;
        }
        $val = self::latest_validation_for((int) $row->id);
        if (!$val) {
            $ficha = ruta_m3::FICHA_SIN;
        } else if (in_array($val->estado, [implementacion::PENDIENTE, implementacion::PROCESANDO], true)) {
            $ficha = ruta_m3::FICHA_PENDIENTE;
        } else if ($val->estado !== implementacion::LISTO || !implementacion::can_advance($val->condicion)) {
            $ficha = $val->estado === implementacion::LISTO ? ruta_m3::FICHA_NO_APROBADA : ruta_m3::FICHA_SIN;
        } else {
            $ficha = $val->huella === self::row_fingerprint($row) ? ruta_m3::FICHA_OK : ruta_m3::FICHA_DESACTUALIZADA;
        }
        $borradores = [];
        foreach (array_merge(self::ETAPAS, [self::CONSOLIDACION]) as $n) {
            if (!empty($filas[$n]) && $filas[$n]->estado !== calidad::ACEPTADO && trim(strip_tags((string) $filas[$n]->contenido)) !== '') {
                $borradores[] = $n;
            }
        }
        return ['fases' => (bool) $fases, 'continuidad' => !empty($row->continuidad), 'ficha' => $ficha,
            'aceptadas' => self::accepted($filas, $val ? (string) $val->huella : ''), 'borradores' => $borradores];
    }

    /**
     * Etapa 9, parte de la plataforma: calcula la precarga y la guarda. Si ya estaba y lo que la alimenta no cambio, no hace nada;
     * si cambio, la rehace (las etiquetas se piden de nuevo) y conserva lo que la persona ya escribio.
     *
     * @param \stdClass $impl Ficha de registro de la implementacion.
     * @param \stdClass $row Ficha ex post (ensure_row()).
     * @return \stdClass La ficha ex post al dia.
     */
    public static function refresh_preload(\stdClass $impl, \stdClass $row): \stdClass {
        global $DB;
        $diseno = (int) $impl->disenoid > 0 ? implementacion::get_design((int) $impl->disenoid, (int) $impl->userid) : null;
        if (!$diseno || !$diseno['disponible']) {
            return $row;
        }
        $filas = calidad::get_by_impl((int) $impl->id);
        $etapas = [];
        foreach ([5, 6] as $n) {
            if (!empty($filas[$n]) && $filas[$n]->estado === calidad::ACEPTADO && trim((string) $filas[$n]->contenido) !== '') {
                $etapas[$n] = html_to_text((string) $filas[$n]->contenido, 0, false);
            }
        }
        $huella = self::preload_fingerprint($impl->data, (int) $impl->disenoid, $diseno['huella'], $etapas);
        if ($row->pre !== null && (string) $row->huella === $huella) {
            return $row;
        }
        $fecha = $row->pre['fecha'] ?? userdate(time(), get_string('strftimedate', 'langconfig'));
        $pre = self::preload($impl->data, $diseno['referencia'], self::continuity_name($etapas[6] ?? ''), $fecha);
        $DB->update_record('local_chatbot_expost', (object) ['id' => $row->id, 'precarga' => json_encode($pre, JSON_UNESCAPED_UNICODE),
            'huella' => $huella, 'etiquetas' => null, 'etiq_estado' => '', 'etiq_error' => null, 'timemodified' => time()]);
        return self::get_row((int) $impl->id);
    }

    /**
     * Deja pendiente la preparacion de las etiquetas (etapa 9, parte del asistente).
     *
     * @param int $exid
     * @return void
     */
    public static function request_labels(int $exid): void {
        global $DB;
        $r = $DB->get_record('local_chatbot_expost', ['id' => $exid], 'id, etiq_estado');
        if (!$r || in_array($r->etiq_estado, [implementacion::PENDIENTE, implementacion::PROCESANDO], true)) {
            return;
        }
        $DB->update_record('local_chatbot_expost', (object) ['id' => $exid, 'etiq_estado' => implementacion::PENDIENTE,
            'etiq_error' => null, 'timemodified' => time()]);
    }

    /**
     * Estado de las etiquetas; una que quedo procesando demasiado se da por caida.
     *
     * @param \stdClass $row
     * @return string implementacion::PENDIENTE, PROCESANDO, LISTO, ERROR o '' (aun no se pidieron).
     */
    public static function labels_state(\stdClass $row): string {
        global $DB;
        if ($row->etiq_estado === implementacion::PROCESANDO && $row->timemodified < time() - implementacion::PROCESO_MAX) {
            $DB->update_record('local_chatbot_expost', (object) ['id' => $row->id, 'etiq_estado' => implementacion::ERROR,
                'etiq_error' => 'tiempo', 'timemodified' => time()]);
            return implementacion::ERROR;
        }
        return (string) $row->etiq_estado;
    }

    /**
     * Ejecuta la preparacion de etiquetas pendiente. Corre una sola vez aunque la pagina la pida dos veces.
     *
     * @param int $exid
     * @param int $userid
     * @return string Estado final.
     */
    public static function run_labels(int $exid, int $userid): string {
        global $DB;
        $candado = \core\lock\lock_config::get_lock_factory('local_chatbot_expost')->get_lock('etq' . $exid, 0);
        if (!$candado) {
            return implementacion::PROCESANDO;
        }
        try {
            $r = $DB->get_record('local_chatbot_expost', ['id' => $exid, 'userid' => $userid]);
            if (!$r || $r->etiq_estado !== implementacion::PENDIENTE) {
                return $r ? (string) $r->etiq_estado : implementacion::ERROR;
            }
            $DB->update_record('local_chatbot_expost', (object) ['id' => $exid, 'etiq_estado' => implementacion::PROCESANDO,
                'timemodified' => time()]);
            [$estado, $campos] = self::generate_labels(self::decode_row($r));
            $DB->update_record('local_chatbot_expost', (object) (['id' => $exid, 'etiq_estado' => $estado,
                'timemodified' => time()] + $campos));
            return $estado;
        } finally {
            $candado->release();
        }
    }

    /**
     * Pide al modelo las etiquetas de las tablas 3.1, 3.2 y 6.4.
     *
     * @param \stdClass $row
     * @return array [estado, campos a guardar].
     */
    private static function generate_labels(\stdClass $row): array {
        global $DB;
        if (!ai\provider_factory::is_configured()) {
            return [implementacion::ERROR, ['etiq_error' => 'sinclave']];
        }
        $impl = $DB->get_record('local_chatbot_impl', ['id' => $row->implid]);
        $diseno = $impl ? implementacion::get_design((int) $impl->disenoid, (int) $impl->userid) : null;
        if (!$diseno || !$diseno['disponible']) {
            return [implementacion::ERROR, ['etiq_error' => 'sindiseno']];
        }
        $impl->data = implementacion::clean((array) json_decode((string) $impl->data, true));
        $filas = calidad::get_by_impl((int) $impl->id);
        $previas = [];
        foreach ([5, 6] as $n) {
            if (!empty($filas[$n]) && $filas[$n]->estado === calidad::ACEPTADO && trim((string) $filas[$n]->contenido) !== '') {
                $previas[$n] = html_to_text((string) $filas[$n]->contenido, 0, false);
            }
        }
        [$partes, , $nombres] = exante::attachment_parts((int) $impl->disenoid);
        $libreria = implementacion::role_library('m3x9', (string) ($diseno['ficha']->data['nivel'] ?? ''));
        $buscar = exante::web_search_available();
        $prompt = self::build_labels_prompt(['diseno' => pseudonymizer::scrub(implementacion::design_text($diseno['referencia'],
            array_intersect_key($diseno['etapas'], array_flip([2, 3, 5, 6])))),
            'implementacion' => pseudonymizer::scrub(implementacion::to_text($impl->data)), 'previas' => $previas,
            'adjuntos' => array_keys($nombres), 'conlibreria' => $libreria !== '', 'conbusqueda' => $buscar]);
        $contenido = [['role' => 'user', 'parts' => implementacion::request_parts($prompt, $partes, $libreria, ux::on('libreriaruta'))]];
        $modelo = ai\provider_factory::get_configured_model();
        $etq = null;
        for ($intento = 0; $intento < 2 && $etq === null; $intento++) {
            $resultado = exante::generate_steady($contenido, 120, $modelo, $buscar && $intento === 0);
            if (isset($resultado['error'])) {
                return [implementacion::ERROR, ['etiq_error' => 'servicio']];
            }
            $etq = self::parse_labels(gemini_client::extract_text($resultado));
        }
        if ($etq === null) {
            return [implementacion::ERROR, ['etiq_error' => 'ilegible']];
        }
        return [implementacion::LISTO, ['etiquetas' => json_encode($etq, JSON_UNESCAPED_UNICODE), 'etiq_error' => null]];
    }

    /**
     * Huella de la ficha ex post tal como esta hoy (respuestas, precarga y etiquetas).
     *
     * @param \stdClass $row
     * @return string
     */
    public static function row_fingerprint(\stdClass $row): string {
        return self::fingerprint($row->data, (string) $row->huella, $row->etq);
    }

    /**
     * Deja una validacion pendiente; si ya hay una en curso para la ficha, devuelve esa.
     *
     * @param \stdClass $row
     * @return int id de la validacion.
     */
    public static function start_validation(\stdClass $row): int {
        global $DB;
        $encurso = $DB->get_records_select('local_chatbot_expost_val', 'expostid = ? AND estado IN (?, ?)',
            [$row->id, implementacion::PENDIENTE, implementacion::PROCESANDO], 'id DESC', 'id', 0, 1);
        if ($encurso) {
            return (int) reset($encurso)->id;
        }
        $now = time();
        return (int) $DB->insert_record('local_chatbot_expost_val', (object) ['expostid' => (int) $row->id, 'userid' => (int) $row->userid,
            'courseid' => (int) $row->courseid, 'estado' => implementacion::PENDIENTE, 'huella' => self::row_fingerprint($row),
            'snapshot' => json_encode(['data' => $row->data, 'pre' => $row->pre, 'etq' => $row->etq], JSON_UNESCAPED_UNICODE),
            'timecreated' => $now, 'timemodified' => $now]);
    }

    /**
     * Ultima validacion de una ficha ex post. Una que quedo procesando demasiado se da por caida.
     *
     * @param int $exid
     * @return \stdClass|null Con informe decodificado.
     */
    public static function latest_validation_for(int $exid): ?\stdClass {
        global $DB;
        $filas = $DB->get_records('local_chatbot_expost_val', ['expostid' => $exid], 'id DESC', '*', 0, 1);
        if (!$filas) {
            return null;
        }
        $val = reset($filas);
        if ($val->estado === implementacion::PROCESANDO && $val->timemodified < time() - implementacion::PROCESO_MAX) {
            $val->estado = implementacion::ERROR;
            $val->error = 'tiempo';
            $DB->update_record('local_chatbot_expost_val', (object) ['id' => $val->id, 'estado' => $val->estado, 'error' => $val->error,
                'timemodified' => time()]);
        }
        $val->informe = $val->informe ? json_decode((string) $val->informe, true) : null;
        return $val;
    }

    /**
     * Ejecuta una validacion pendiente. Corre una sola vez aunque la pagina la pida dos veces.
     *
     * @param int $valid
     * @param int $userid
     * @return string Estado final.
     */
    public static function run_validation(int $valid, int $userid): string {
        global $DB;
        $candado = \core\lock\lock_config::get_lock_factory('local_chatbot_expost')->get_lock('val' . $valid, 0);
        if (!$candado) {
            return implementacion::PROCESANDO;
        }
        try {
            $val = $DB->get_record('local_chatbot_expost_val', ['id' => $valid, 'userid' => $userid]);
            if (!$val || $val->estado !== implementacion::PENDIENTE) {
                return $val ? $val->estado : implementacion::ERROR;
            }
            $DB->update_record('local_chatbot_expost_val', (object) ['id' => $valid, 'estado' => implementacion::PROCESANDO,
                'timemodified' => time()]);
            [$estado, $campos] = self::validate($val);
            $DB->update_record('local_chatbot_expost_val', (object) (['id' => $valid, 'estado' => $estado,
                'timemodified' => time()] + $campos));
            return $estado;
        } finally {
            $candado->release();
        }
    }

    /**
     * Llama al modelo con la ficha ex post de la validacion, el diseño y sus documentos.
     *
     * @param \stdClass $val
     * @return array [estado, campos a guardar].
     */
    private static function validate(\stdClass $val): array {
        global $DB;
        if (!ai\provider_factory::is_configured()) {
            return [implementacion::ERROR, ['error' => 'sinclave']];
        }
        $snap = (array) json_decode((string) $val->snapshot, true);
        $data = self::clean((array) ($snap['data'] ?? []));
        $pre = (array) ($snap['pre'] ?? []);
        $etq = (array) ($snap['etq'] ?? []);
        $row = $DB->get_record('local_chatbot_expost', ['id' => $val->expostid]);
        $impl = $row ? $DB->get_record('local_chatbot_impl', ['id' => $row->implid]) : null;
        $diseno = $impl ? implementacion::get_design((int) $impl->disenoid, (int) $val->userid) : null;
        if (!$diseno || !$diseno['disponible'] || !$pre) {
            return [implementacion::ERROR, ['error' => 'sindiseno']];
        }
        [$partes, , $nombres] = exante::attachment_parts((int) $impl->disenoid);
        $calculo = self::system_checks($data, $pre);
        $hechos = $calculo['hechos'];
        $libreria = implementacion::role_library('m3x10', (string) ($diseno['ficha']->data['nivel'] ?? ''));
        $buscar = exante::web_search_available();
        $prompt = self::build_validation_prompt(array_keys($nombres), $libreria !== '', $buscar)
            . "\n\nVERIFICACIONES DE LA PLATAFORMA (cálculos y comparaciones exactas; úsalos, no los recalcules):\n- "
            . ($hechos ? implode("\n- ", $hechos) : '(sin verificaciones numéricas)')
            . "\n\n" . pseudonymizer::scrub(implementacion::design_text($diseno['referencia'], array_intersect_key($diseno['etapas'], [2 => 1, 5 => 1])))
            . "\n\nFICHA DE EVALUACIÓN EX POST:\n" . guardrails::isolate_user_content(pseudonymizer::scrub(self::to_text($data, $pre, $etq)));
        $contenido = [['role' => 'user', 'parts' => implementacion::request_parts($prompt, $partes, $libreria, ux::on('libreriaruta'))]];
        $modelo = ai\provider_factory::get_configured_model();
        $informe = null;
        $fuentes = [];
        for ($intento = 0; $intento < 2 && $informe === null; $intento++) {
            $resultado = exante::generate_steady($contenido, 150, $modelo, $buscar && $intento === 0);
            if (isset($resultado['error'])) {
                return [implementacion::ERROR, ['error' => 'servicio']];
            }
            $informe = self::parse_report(gemini_client::extract_text($resultado));
            $fuentes = gemini_client::extract_sources($resultado);
        }
        if ($informe === null) {
            return [implementacion::ERROR, ['error' => 'ilegible']];
        }
        $informe['items'] = self::apply_system_checks($informe['items'], $calculo['items']);
        $informe['adjuntos'] = $nombres;
        $informe['fuentes_web'] = array_slice($fuentes, 0, 8);
        $informe['libreria'] = $libreria !== '' ? kb_store::context_version(kb_store::AMBITO_M3) : '';
        $informe['regla'] = self::decision_rule($informe['items']);
        $informe['verificaciones'] = $hechos;
        return [implementacion::LISTO, ['condicion' => self::decide($informe['items']),
            'informe' => json_encode($informe, JSON_UNESCAPED_UNICODE), 'modelo' => (string) $modelo,
            'promptversion' => self::PROMPT_VERSION, 'error' => null]];
    }

    /**
     * Fila de una etapa de la Fase 4 (la crea vacia si no existe).
     *
     * @param \stdClass $impl
     * @param int $etapa
     * @return \stdClass
     */
    private static function stage_row(\stdClass $impl, int $etapa): \stdClass {
        global $DB;
        $fila = $DB->get_record('local_chatbot_calidad', ['implid' => $impl->id, 'etapa' => $etapa]);
        if ($fila) {
            return $fila;
        }
        $now = time();
        $fila = (object) ['implid' => (int) $impl->id, 'userid' => (int) $impl->userid, 'courseid' => (int) $impl->courseid,
            'etapa' => $etapa, 'contenido' => null, 'estado' => calidad::BORRADOR, 'generacion' => '', 'timecreated' => $now,
            'timemodified' => $now];
        $fila->id = $DB->insert_record('local_chatbot_calidad', $fila);
        return $fila;
    }

    /**
     * Confirma la consolidacion (etapa 15): guarda el documento y la huella de las etapas que lo forman.
     *
     * @param \stdClass $impl
     * @param string $html
     * @param string $huella
     * @param string|null $promptversion Solo si lo confirmado es la consolidacion del asistente.
     * @return void
     */
    public static function confirm_consolidation(\stdClass $impl, string $html, string $huella, ?string $promptversion = null): void {
        global $DB;
        $fila = self::stage_row($impl, self::CONSOLIDACION);
        $DB->update_record('local_chatbot_calidad', (object) ['id' => $fila->id, 'contenido' => $html, 'huella' => $huella,
            'estado' => calidad::ACEPTADO, 'promptversion' => $promptversion, 'timeaccepted' => time(), 'timemodified' => time()]);
    }

    /**
     * Genera el producto de una etapa de la Fase 4 (11 a 14) o la consolidacion (15). La llama calidad::run().
     *
     * @param \stdClass $fila Fila de local_chatbot_calidad.
     * @return array [estado de la generacion, campos a guardar].
     */
    public static function generate(\stdClass $fila): array {
        global $DB;
        if (!ai\provider_factory::is_configured()) {
            return [implementacion::ERROR, ['error' => 'sinclave']];
        }
        $val = $DB->get_record('local_chatbot_expost_val', ['id' => $fila->validacionid, 'userid' => $fila->userid]);
        $impl = $DB->get_record('local_chatbot_impl', ['id' => $fila->implid]);
        if (!$val || !$impl) {
            return [implementacion::ERROR, ['error' => 'sinficha']];
        }
        $impl->data = implementacion::clean((array) json_decode((string) $impl->data, true));
        $filas = calidad::get_by_impl((int) $fila->implid);
        $etapa = (int) $fila->etapa;
        if ($etapa === self::CONSOLIDACION) {
            return self::consolidate_ai($fila, $val, $filas);
        }
        $diseno = implementacion::get_design((int) $impl->disenoid, (int) $fila->userid);
        if (!$diseno || !$diseno['disponible']) {
            return [implementacion::ERROR, ['error' => 'sindiseno']];
        }
        $snap = (array) json_decode((string) $val->snapshot, true);
        $data = self::clean((array) ($snap['data'] ?? []));
        $pre = (array) ($snap['pre'] ?? []);
        $etq = (array) ($snap['etq'] ?? []);
        $e = self::etapas()[$etapa] ?? null;
        if (!$e || !$pre) {
            return [implementacion::ERROR, ['error' => 'sinficha']];
        }
        $previas = [];
        foreach ($e['previas'] as $n) {
            if (!empty($filas[$n]) && $filas[$n]->estado === calidad::ACEPTADO && trim((string) $filas[$n]->contenido) !== '') {
                $previas[$n] = html_to_text((string) $filas[$n]->contenido, 0, false);
            }
        }
        for ($n = 11; $n < $etapa; $n++) {
            if (!empty($filas[$n]) && $filas[$n]->estado === calidad::ACEPTADO && trim((string) $filas[$n]->contenido) !== '') {
                $previas[$n] = html_to_text((string) $filas[$n]->contenido, 0, false);
            }
        }
        ksort($previas);
        [$partes, $etiquetas] = exante::attachment_parts((int) $impl->disenoid);
        $actual = $fila->contenido ? html_to_text((string) $fila->contenido, 0, false) : '';
        $libreria = implementacion::role_library('m3x' . $etapa, (string) ($diseno['ficha']->data['nivel'] ?? ''));
        $buscar = exante::web_search_available();
        $prompt = self::build_prompt($etapa, [
            'ficha' => pseudonymizer::scrub(self::to_text($data, $pre, $etq)),
            'datos' => self::results_text(self::results($data, $pre)),
            'informe' => (array) json_decode((string) $val->informe, true), 'condicion' => (string) $val->condicion,
            'implementacion' => pseudonymizer::scrub(implementacion::to_text($impl->data)),
            'diseno' => pseudonymizer::scrub(implementacion::design_text($diseno['referencia'],
                array_intersect_key($diseno['etapas'], array_flip([2, 3, 5])))),
            'previas' => $previas, 'adjuntos' => $etiquetas, 'actual' => $actual, 'ajustes' => (string) $fila->ajustes,
            'conlibreria' => $libreria !== '', 'conbusqueda' => $buscar]);
        $contenido = [['role' => 'user', 'parts' => implementacion::request_parts($prompt, $partes, $libreria, ux::on('libreriaruta'))]];
        $modelo = ai\provider_factory::get_configured_model();
        $resultado = exante::generate_steady($contenido, 240, $modelo, $buscar);
        if (isset($resultado['error'])) {
            return [implementacion::ERROR, ['error' => 'servicio']];
        }
        $markdown = diseno::clean_markdown(gemini_client::extract_text($resultado));
        if ($markdown === '') {
            return [implementacion::ERROR, ['error' => 'ilegible']];
        }
        $markdown .= diseno::sources_markdown(gemini_client::extract_sources($resultado));
        // Una propuesta nueva o ajustada vuelve a borrador: la persona la revisa y la acepta de nuevo.
        return [implementacion::LISTO, ['contenido' => diseno::product_html($markdown), 'estado' => calidad::BORRADOR,
            'modelo' => (string) $modelo, 'promptversion' => self::PROMPT_VERSION, 'error' => null]];
    }

    /**
     * Etapa 15 con el asistente: consolida la validacion y las etapas 11 a 14 aceptadas en extenso, sin repetir campos comunes. Si
     * la respuesta llega cortada o mucho mas corta que las etapas, no se usa: queda la consolidacion completa por codigo.
     *
     * @param \stdClass $fila Fila de la etapa 15.
     * @param \stdClass $val Validacion de la ficha ex post.
     * @param \stdClass[] $filas Etapas de la evaluacion.
     * @return array [estado de la generacion, campos a guardar].
     */
    private static function consolidate_ai(\stdClass $fila, \stdClass $val, array $filas): array {
        $partes = [10 => self::validation_html((string) $val->condicion, (array) json_decode((string) $val->informe, true))];
        foreach (self::ETAPAS as $n) {
            if (empty($filas[$n]) || $filas[$n]->estado !== calidad::ACEPTADO || trim((string) $filas[$n]->contenido) === '') {
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
        return [implementacion::LISTO, ['contenido' => diseno::product_html($markdown), 'estado' => calidad::BORRADOR,
            'huella' => $huella, 'modelo' => (string) $modelo, 'promptversion' => self::PROMPT_VERSION, 'error' => null]];
    }

    /**
     * Borra la ficha ex post de una evaluacion y sus validaciones (las etapas viven en local_chatbot_calidad y las borra
     * implementacion::delete_ficha()).
     *
     * @param int $implid
     * @return void
     */
    public static function delete_for(int $implid): void {
        global $DB;
        $row = $DB->get_record('local_chatbot_expost', ['implid' => $implid], 'id');
        if ($row) {
            $DB->delete_records('local_chatbot_expost_val', ['expostid' => $row->id]);
            $DB->delete_records('local_chatbot_expost', ['id' => $row->id]);
        }
    }
}
