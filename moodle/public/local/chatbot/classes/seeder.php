<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Instrumento base del Modulo 1: dimensiones e items del diagnostico,
 * criterios de la rubrica del proyecto integrador y version 1 de los prompts
 * de cada modo del asistente.
 *
 * Las dimensiones siguen la cadena de alineamiento que el documento fija como
 * resultado esperado del modulo (Perfil/UCL, aprendizajes esperados, criterios
 * de desempeno, actividades, evidencias, instrumentos, retroalimentacion y
 * mejora) y se corresponden con las cuatro unidades de la ruta base.
 *
 * Todo esto es punto de partida configurable, no contenido fijo: la persona
 * con la capability manageinstrument puede editarlo desde la interfaz.
 */
class seeder {

    /**
     * Carga el instrumento base si todavia no existe. Es idempotente: se puede
     * llamar en la instalacion y en la actualizacion sin duplicar nada.
     *
     * @return void
     */
    public static function seed_defaults(): void {
        self::seed_dimensions_and_items();
        self::seed_situational_items();
        self::seed_dimension_criteria();
        self::seed_rubric();
        self::seed_prompts();
    }

    /**
     * Carga los criterios formativos base de cada dimension, donde todavia no
     * haya ninguno.
     *
     * Son el patron contra el que el motor de apoyos adaptativos revisa las
     * evidencias (RU-ADAPT-004). No los genera el modelo en cada revision: un
     * criterio que inventa el mismo que despues juzga no es un criterio. Como
     * el resto del instrumento, son punto de partida editable desde
     * instrumento.php.
     *
     * @return void
     */
    private static function seed_dimension_criteria(): void {
        global $DB;

        foreach (self::default_dimension_criteria() as $code => $criteria) {
            $dimension = $DB->get_record('local_chatbot_dimension', ['courseid' => 0, 'code' => $code]);
            if ($dimension && trim((string) ($dimension->criterios ?? '')) === '') {
                $DB->set_field('local_chatbot_dimension', 'criterios', implode("\n", $criteria),
                    ['id' => $dimension->id]);
            }
        }
    }

    /**
     * @return array Mapa codigo de dimension => criterios formativos, uno por linea.
     */
    public static function default_dimension_criteria(): array {
        return [
            'referentes' => [
                'Identifica un referente pertinente (perfil ocupacional o UCL) y lo nombra.',
                'Justifica la pertinencia del referente para el contexto formativo descrito.',
                'Distingue la competencia a desarrollar de los contenidos del curso.',
            ],
            'aprendizajes' => [
                'Formula aprendizajes esperados observables y evaluables.',
                'Cada aprendizaje se desprende del referente declarado.',
                'Los criterios de desempeno precisan el nivel exigido.',
            ],
            'diseno' => [
                'La actividad exige poner en practica la competencia, no solo explicarla.',
                'Es coherente con el aprendizaje esperado al que responde.',
                'Es viable para personas adultas en el contexto descrito.',
            ],
            'evaluacion' => [
                'Declara que evidencia demuestra el logro.',
                'El instrumento corresponde al tipo de evidencia.',
                'El instrumento evalua efectivamente el criterio de desempeno.',
            ],
            'mejora' => [
                'La retroalimentacion indica que ajustar y como hacerlo.',
                'Las decisiones de mejora se apoyan en evidencia recogida.',
                'Las decisiones quedan documentadas de forma que se pueden sostener.',
            ],
        ];
    }

    /**
     * @return void
     */
    private static function seed_dimensions_and_items(): void {
        global $DB;

        if ($DB->record_exists('local_chatbot_dimension', ['courseid' => 0])) {
            return;
        }

        $now = time();
        $order = 0;

        foreach (self::default_dimensions() as $code => $definition) {
            $dimension = new \stdClass();
            $dimension->courseid = 0;
            $dimension->code = $code;
            $dimension->name = $definition['name'];
            $dimension->description = $definition['description'];
            $dimension->sortorder = $order++;
            $dimension->timecreated = $now;
            $dimensionid = $DB->insert_record('local_chatbot_dimension', $dimension);

            $itemorder = 0;
            foreach ($definition['items'] as $statement) {
                $item = new \stdClass();
                $item->dimensionid = $dimensionid;
                $item->statement = $statement;
                $item->kind = diagnostic::KIND_DECLARATIVA;
                $item->sortorder = $itemorder++;
                $item->timecreated = $now;
                $DB->insert_record('local_chatbot_diag_item', $item);
            }
        }
    }

    /**
     * Carga un item situacional por dimension, si esa dimension todavia no
     * tiene ninguno.
     *
     * Va aparte de seed_dimensions_and_items() porque tiene que poder correr
     * sobre una instalacion que ya traia el instrumento completo: los items
     * situacionales aparecieron despues, con RU-CALC-001, y sin ellos el
     * puntaje compuesto se queda en su mitad declarativa.
     *
     * Es contenido base configurable, igual que el resto del instrumento: son
     * situaciones genericas de diseno formativo, y quien tenga la capability
     * manageinstrument puede reemplazarlas por las del pilotaje real.
     *
     * @return void
     */
    private static function seed_situational_items(): void {
        global $DB;

        $dimensions = $DB->get_records('local_chatbot_dimension', ['courseid' => 0]);
        if (empty($dimensions)) {
            return;
        }

        $bycode = [];
        foreach ($dimensions as $dimension) {
            $bycode[$dimension->code] = $dimension->id;
        }

        $now = time();
        foreach (self::default_situational_items() as $code => $definition) {
            if (!isset($bycode[$code])) {
                continue;
            }
            $dimensionid = $bycode[$code];

            // Idempotente por dimension: si ya hay un situacional, no se toca.
            if ($DB->record_exists('local_chatbot_diag_item',
                    ['dimensionid' => $dimensionid, 'kind' => diagnostic::KIND_SITUACIONAL])) {
                continue;
            }

            $item = new \stdClass();
            $item->dimensionid = $dimensionid;
            $item->statement = $definition['statement'];
            $item->kind = diagnostic::KIND_SITUACIONAL;
            $item->options = json_encode($definition['options'], JSON_UNESCAPED_UNICODE);
            $item->correctoption = $definition['correct'];
            $item->sortorder = 100;
            $item->timecreated = $now;
            $DB->insert_record('local_chatbot_diag_item', $item);
        }
    }

    /**
     * @return void
     */
    private static function seed_rubric(): void {
        global $DB;

        if ($DB->record_exists('local_chatbot_criterion', ['courseid' => 0])) {
            return;
        }

        $now = time();
        $order = 0;

        foreach (self::default_criteria() as $code => $definition) {
            $criterion = new \stdClass();
            $criterion->courseid = 0;
            $criterion->code = $code;
            $criterion->name = $definition['name'];
            $criterion->descriptor = $definition['descriptor'];
            $criterion->sortorder = $order++;
            $criterion->version = 1;
            $criterion->active = 1;
            $criterion->timecreated = $now;
            $DB->insert_record('local_chatbot_criterion', $criterion);
        }
    }

    /**
     * @return void
     */
    private static function seed_prompts(): void {
        global $DB;

        foreach (self::default_prompts() as $mode => $text) {
            if ($DB->record_exists('local_chatbot_prompt', ['mode' => $mode])) {
                continue;
            }
            $record = new \stdClass();
            $record->mode = $mode;
            $record->prompttext = $text;
            $record->version = 1;
            $record->active = 1;
            $record->usermodified = 0;
            $record->timecreated = time();
            $DB->insert_record('local_chatbot_prompt', $record);
        }
    }

    /**
     * Pasa a la version vigente de default_prompts() los prompts que ninguna
     * persona edito.
     *
     * seed_prompts() solo carga lo que falta, asi que un cambio de conducta
     * exigido por la matriz —como la correccion de derivacion de RU-GEN-DERIV-001—
     * no llegaria nunca a una instalacion existente. Pero sobrescribir sin
     * mirar borraria el trabajo de quien ajusto un prompt a su curso. El
     * criterio que separa los dos casos ya esta en los datos: un prompt cargado
     * por el sistema queda con usermodified = 0, y uno editado desde la
     * interfaz lleva el id de quien lo edito.
     *
     * Nunca sobrescribe: crea la version siguiente, asi que el historial
     * conserva la anterior (RU-PRIV-006).
     *
     * @return string[] Contextos cuyo prompt se actualizo.
     */
    public static function refresh_system_prompts(): array {
        $updated = [];
        foreach (self::default_prompts() as $mode => $text) {
            $active = prompt_store::get_active($mode);
            if (!$active) {
                continue;
            }
            if ((int) $active->usermodified !== 0 || $active->prompttext === $text) {
                continue;
            }
            prompt_store::save_new_version($mode, $text, 0);
            $updated[] = $mode;
        }
        return $updated;
    }

    /**
     * Correcciones de tildes en los nombres sembrados del instrumento y la rubrica por defecto,
     * que se cargaron sin ellas. La usa la migracion 2026100800. Funcion pura.
     *
     * @return array Tabla => [nombre sembrado => nombre corregido].
     */
    public static function accent_fixes(): array {
        return [
            'local_chatbot_dimension' => [
                'Traduccion a aprendizajes esperados y criterios de desempeno'
                    => 'Traducción a aprendizajes esperados y criterios de desempeño',
                'Diseno de actividades y experiencias de aprendizaje' => 'Diseño de actividades y experiencias de aprendizaje',
                'Evidencias e instrumentos de evaluacion' => 'Evidencias e instrumentos de evaluación',
                'Retroalimentacion, implementacion y mejora' => 'Retroalimentación, implementación y mejora',
            ],
            'local_chatbot_criterion' => [
                'Aprendizajes esperados y criterios de desempeno' => 'Aprendizajes esperados y criterios de desempeño',
                'Retroalimentacion y decisiones de mejora' => 'Retroalimentación y decisiones de mejora',
            ],
        ];
    }

    /**
     * @return array Dimensiones del diagnostico con sus items.
     */
    public static function default_dimensions(): array {
        return [
            'referentes' => [
                'name' => 'Lectura de referentes y enfoque de competencias',
                'description' => 'Uso del perfil ocupacional o la UCL como punto de partida del diseno formativo.',
                'items' => [
                    'Identifico las unidades de competencia laboral pertinentes antes de disenar un curso.',
                    'Distingo entre un contenido tematico y una competencia a desarrollar.',
                    'Analizo el contexto laboral real en el que las personas van a desempenarse.',
                    'Explico a otras personas en que se diferencia el enfoque de competencias de un enfoque por contenidos.',
                ],
            ],
            'aprendizajes' => [
                'name' => 'Traducción a aprendizajes esperados y criterios de desempeño',
                'description' => 'Paso desde el referente hacia formulaciones evaluables de lo que la persona debe lograr.',
                'items' => [
                    'Redacto aprendizajes esperados observables y evaluables.',
                    'Formulo criterios de desempeno que precisan el nivel exigido.',
                    'Verifico que cada aprendizaje esperado se desprenda del referente y no de mi preferencia personal.',
                    'Ajusto la formulacion cuando el aprendizaje esperado resulta demasiado amplio o ambiguo.',
                ],
            ],
            'diseno' => [
                'name' => 'Diseño de actividades y experiencias de aprendizaje',
                'description' => 'Coherencia entre lo que se espera lograr y lo que efectivamente se hace en la sesion.',
                'items' => [
                    'Diseno actividades que exigen poner en practica la competencia, no solo escucharla explicada.',
                    'Secuencio las actividades segun la complejidad creciente de la competencia.',
                    'Adecuo las actividades a las caracteristicas de personas adultas en trayectorias formativo-laborales.',
                    'Preveo alternativas cuando una actividad no funciona con un grupo determinado.',
                ],
            ],
            'evaluacion' => [
                'name' => 'Evidencias e instrumentos de evaluación',
                'description' => 'Decisiones sobre que evidencia recoger y con que instrumento juzgarla.',
                'items' => [
                    'Defino que evidencia concreta demostrara el logro de cada aprendizaje esperado.',
                    'Selecciono el instrumento de evaluacion segun el tipo de evidencia y no por costumbre.',
                    'Construyo rubricas o pautas con niveles de logro descritos, no solo con puntajes.',
                    'Reviso que el instrumento evalue efectivamente el criterio de desempeno declarado.',
                ],
            ],
            'mejora' => [
                'name' => 'Retroalimentación, implementación y mejora',
                'description' => 'Uso de la informacion recogida para tomar decisiones sobre el propio proceso formativo.',
                'items' => [
                    'Entrego retroalimentacion que indica que ajustar y como hacerlo, no solo si esta bien o mal.',
                    'Registro evidencia del desarrollo de la sesion para revisarla despues.',
                    'Modifico el diseno de un curso a partir de los resultados de evaluacion obtenidos.',
                    'Documento las decisiones de mejora para poder sostenerlas ante otras personas.',
                ],
            ],
        ];
    }

    /**
     * Items situacionales base, uno por dimension.
     *
     * A diferencia de los declarativos, estos no preguntan que suele hacer la
     * persona sino que hace frente a una situacion concreta. Esa es toda la
     * razon de su existencia: la autodeclaracion tiende a lo deseable, y el
     * componente situacional (30% del compuesto) le pone un contrapeso que no
     * depende de como la persona se percibe a si misma.
     *
     * @return array Mapa codigo de dimension => enunciado, alternativas e indice correcto.
     */
    public static function default_situational_items(): array {
        return [
            'referentes' => [
                'statement' => 'Te encargan capacitar en "atencion de clientes" para un area de'
                    . ' servicio tecnico. No te entregan mas antecedentes. Que haces primero?',
                'options' => [
                    'Armo el temario con los contenidos habituales de atencion de clientes.',
                    'Busco el perfil ocupacional o la UCL pertinente y reviso que desempenos exige.',
                    'Pregunto cuantas horas hay disponibles y ajusto la extension del curso.',
                    'Aplico una encuesta de satisfaccion previa al grupo.',
                ],
                'correct' => 1,
            ],
            'aprendizajes' => [
                'statement' => 'Un aprendizaje esperado dice: "Conocer la importancia de la seguridad'
                    . ' en el trabajo". Cual es el problema principal de esa formulacion?',
                'options' => [
                    'Es demasiado breve y deberia redactarse en mas lineas.',
                    'Menciona seguridad, que corresponde a otra unidad de competencia.',
                    'Describe un estado interno y no un desempeno observable y evaluable.',
                    'No indica la cantidad de horas que requiere.',
                ],
                'correct' => 2,
            ],
            'diseno' => [
                'statement' => 'El aprendizaje esperado es "elaborar un presupuesto de obra menor".'
                    . ' Cual de estas actividades es la mas coherente con ese aprendizaje?',
                'options' => [
                    'Una exposicion del facilitador sobre los componentes de un presupuesto.',
                    'Un video con casos de presupuestos mal elaborados, seguido de comentarios.',
                    'Una lectura del reglamento de presupuestos con preguntas al final.',
                    'Elaborar un presupuesto a partir de un encargo real con planos y precios.',
                ],
                'correct' => 3,
            ],
            'evaluacion' => [
                'statement' => 'El criterio de desempeno exige "ejecutar la soldadura segun norma".'
                    . ' Que combinacion de evidencia e instrumento corresponde?',
                'options' => [
                    'Evidencia de producto y desempeno, con pauta de cotejo aplicada en taller.',
                    'Evidencia de conocimiento, con prueba escrita de alternativas.',
                    'Evidencia de actitud, con escala de apreciacion de puntualidad.',
                    'Evidencia documental, con revision del certificado de asistencia.',
                ],
                'correct' => 0,
            ],
            'mejora' => [
                'statement' => 'Una persona entrega un trabajo incompleto. Cual de estas'
                    . ' retroalimentaciones cumple mejor su proposito formativo?',
                'options' => [
                    'Insuficiente. Debe rehacerlo completo antes de la proxima sesion.',
                    'Buen intento, se nota el esfuerzo. Sigue asi.',
                    'Faltan los criterios de desempeno de dos aprendizajes: agregalos tomando el'
                        . ' formato del ejemplo 2, y revisa que cada uno precise el nivel exigido.',
                    'Obtuviste 4,0. Revisa la rubrica para entender por que.',
                ],
                'correct' => 2,
            ],
        ];
    }

    /**
     * @return array Criterios de la rubrica del proyecto integrador.
     */
    public static function default_criteria(): array {
        return [
            'R1' => [
                'name' => 'Pertinencia del referente',
                'descriptor' => 'El proyecto identifica un perfil ocupacional o UCL pertinente y justifica'
                    . ' por que es el referente adecuado para el proceso formativo descrito.',
            ],
            'R2' => [
                'name' => 'Aprendizajes esperados y criterios de desempeño',
                'descriptor' => 'Los aprendizajes esperados se desprenden del referente, son observables, y'
                    . ' cada uno cuenta con criterios de desempeno que precisan el nivel exigido.',
            ],
            'R3' => [
                'name' => 'Coherencia de las actividades',
                'descriptor' => 'Las actividades propuestas permiten desarrollar efectivamente los'
                    . ' aprendizajes esperados declarados y son viables en el contexto descrito.',
            ],
            'R4' => [
                'name' => 'Evidencias e instrumentos',
                'descriptor' => 'Se declara que evidencia demostrara cada aprendizaje esperado y con que'
                    . ' instrumento sera evaluada, con correspondencia clara entre ambos.',
            ],
            'R5' => [
                'name' => 'Retroalimentación y decisiones de mejora',
                'descriptor' => 'El proyecto describe como se entregara retroalimentacion y que decisiones'
                    . ' de mejora se tomarian a partir de los resultados obtenidos.',
            ],
            'R6' => [
                'name' => 'Alineamiento global',
                'descriptor' => 'La cadena completa (perfil, aprendizajes, criterios, actividades,'
                    . ' evidencias, instrumentos, mejora) se sostiene sin rupturas ni elementos sueltos.',
            ],
        ];
    }

    /**
     * Prompts version 1 de cada modo. Recogen literalmente las conductas que
     * el documento de arquitectura exige a cada modo, incluidas sus
     * prohibiciones.
     *
     * @return string[] Mapa modo => prompt.
     */
    public static function default_prompts(): array {
        return [
            assistant::PHASE_DIAGNOSTICO =>
                "Eres el asistente del Sistema de Autoaprendizaje Adaptativo para Personas"
                . " Facilitadoras, operando en fase de DIAGNOSTICO: la persona esta respondiendo el"
                . " instrumento y todavia no lo ha enviado.\n\n"
                . "Puedes:\n"
                . "- Explicar el significado de terminos metodologicos (criterio de desempeno,"
                . " evidencia, instrumento, retroalimentacion, coevaluacion) con ejemplos generales.\n"
                . "- Explicar como funciona la actividad de diagnostico.\n"
                . "- Explicar la diferencia entre componentes conceptuales.\n"
                . "- Invitar a la persona a responder segun su practica habitual, no segun lo deseable.\n\n"
                . "NUNCA debes, mientras el diagnostico no haya sido enviado:\n"
                . "- Sugerir que alternativa elegir en un item.\n"
                . "- Interpretar o adelantar el resultado de las respuestas.\n"
                . "- Sugerir puntajes ni inferir resultados parciales.\n"
                . "- Revelar la respuesta correcta de un item situacional.\n\n"
                . "Las dudas sobre terminos o sobre como funciona la actividad las resuelves tu:"
                . " nunca las derives a una persona. Solo una dificultad tecnica (acceso, conectividad,"
                . " la plataforma que no responde) va a soporte humano.",

            // Tutor y Coach: prompts operativos de los Anexos E y F (classes/operative_prompts.php).
            assistant::MODE_TUTOR => operative_prompts::tutor(),

            assistant::MODE_COACH => operative_prompts::coach(),

            assistant::MODE_EVALUADOR =>
                "Eres el Evaluador formativo del Modulo 1. Analizas el proyecto integrador de la persona"
                . " facilitadora contra la rubrica oficial del modulo.\n\n"
                . "Debes:\n"
                . "- Leer exclusivamente los campos y evidencias del proyecto que se te entregan.\n"
                . "- Aplicar la rubrica criterio por criterio, sin saltarte ninguno.\n"
                . "- Identificar evidencia explicita, ausencia de evidencia y posibles inconsistencias.\n"
                . "- Entregar comentarios descriptivos, accionables y respetuosos.\n"
                . "- Diferenciar con claridad entre una observacion, una inferencia y una recomendacion.\n"
                . "- Sugerir ajustes especificos y viables en el proyecto.\n"
                . "- Proponer la actualizacion correspondiente del PDP.\n"
                . "- Declarar cuando se requiere revision humana: evidencia insuficiente, contexto que"
                . " exige conocimiento especializado o ambiguedades relevantes.\n\n"
                . "FORMATO DE TODA RETROALIMENTACION que entregues, en estos cinco campos y en este orden:\n"
                . "1. Fortaleza observada\n"
                . "2. Aspecto a mejorar\n"
                . "3. Por que importa\n"
                . "4. Accion sugerida\n"
                . "5. Vinculo con el PDP\n\n"
                . "Tu unica fuente de verdad es la rubrica oficial vigente que se te entrega: no evalues"
                . " contra criterios propios. Tu retroalimentacion es formativa y no sustituye una"
                . " decision humana formal. No decides ni comunicas aprobacion, calificacion ni"
                . " certificacion final: esa decision es siempre humana e institucional.",
        ];
    }
}
