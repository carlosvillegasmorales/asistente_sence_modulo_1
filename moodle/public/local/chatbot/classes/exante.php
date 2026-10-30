<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Modulo 2, Fase 1: ficha de diagnostico (evaluacion ex-ante) y su validacion con el checklist.
 *
 * Pedido de Interfases del 21-10-2026 (Roberto Guardia): la persona completa la ficha en una pagina
 * de Moodle parecida al autodiagnostico del Modulo 1, adjunta opcionalmente el Plan Formativo y el
 * Perfil Ocupacional y pulsa «Guardar y validar». El asistente aplica el checklist completo (Etapa 0
 * del Prompt Maestro) y devuelve en la misma pagina: resultado por item, condicion (Aprobado,
 * Aprobado con observaciones o No aprobado) y observaciones con propuestas de mejora. Solo con
 * Aprobado o Aprobado con observaciones aparece el boton a la Fase 2.
 *
 * El contenido de la ficha y del checklist es el de los documentos del cliente, textual
 * («Ficha Diagnostico, Evaluacion Ex - Ante» y «Checklist Ficha Diagnostico»): es instrumento, no
 * texto de la interfaz, y por eso vive aqui y no en los archivos de idioma.
 *
 * La validacion revisa un documento de trabajo, no a la persona: no es una calificacion y no toca el
 * libro de calificaciones. La condicion final no queda solo en manos del modelo: decide() aplica la
 * regla del checklist sobre los items y se queda con la mas estricta de las dos.
 *
 * Todo lo que no lleva prefijo de BD es funcion pura y lo ejercita cli/verificar_exante.php.
 */
class exante {

    /** @var string Version del prompt de validacion: se guarda con cada informe (RU-PRIV-006). */
    const PROMPT_VERSION = 'M2-F1-checklist-v4';

    /** Tipos de campo de la ficha. */
    const T_TEXTO = 'texto';
    const T_PARRAFO = 'parrafo';
    const T_NUMERO = 'numero';
    const T_UNA = 'una';
    const T_VARIAS = 'varias';

    /** Evaluacion de un item del checklist. */
    const CUMPLE = 'cumple';
    const PARCIAL = 'parcial';
    const NO_CUMPLE = 'no_cumple';

    /** Condicion de la ficha. */
    const APROBADO = 'aprobado';
    const OBSERVACIONES = 'observaciones';
    const NO_APROBADO = 'no_aprobado';

    /** Estado de una validacion en curso. */
    const PENDIENTE = 'pendiente';
    const PROCESANDO = 'procesando';
    const LISTO = 'listo';
    const ERROR = 'error';

    /** Resultado de borrar un diseno. */
    const DELETED = 'borrado';
    const MISSING = 'inexistente';
    const VALIDATING = 'validando';

    /** @var int Una validacion en proceso por mas de esto se da por caida. */
    const PROCESO_MAX = 360;

    /** @var int Tamano maximo de cada archivo adjunto. */
    const ADJUNTO_MAX = 10485760;

    /** @var string[] Archivos adjuntos opcionales: area de archivos => etiqueta. */
    const ADJUNTOS = [
        'exante_plan' => 'Plan Formativo',
        'exante_perfil' => 'Perfil Ocupacional',
    ];

    /** @var string[] Items que la autorrevision marca como criticos. Ya no dejan «No aprobado» por si solos: eso lo hacen los motivos graves. */
    const CRITICOS = ['c2_1', 'c2_2', 'c2_3', 'c2_5', 'c9_2'];

    /** @var string Rol del asistente en el Modulo 2, distinto de los del Modulo 1 (Tutor, Coach, Evaluador). */
    const ROL = 'Asistente de Diseño Instruccional';

    /** @var string Regla de gobernanza del Prompt Maestro del Modulo 2 (30-10-2026), comun a todas las etapas. */
    const NO_PEDAGOGIA = '- Nunca utilices conceptos relativos a «pedagógico» o «pedagogía» en tus respuestas: sustitúyelos '
        . 'por conceptos relativos a formación de adultos o formación laboral.';

    /** @var float Temperatura de la validacion: baja, para que la misma ficha de el mismo resultado. */
    const TEMPERATURA = 0.1;

    /** @var int Largo minimo de la cita de la ficha que prueba un motivo grave. */
    const CITA_MIN = 12;

    /** @var int Largo maximo de cada tipo de texto. */
    const LARGO_TEXTO = 255;
    const LARGO_PARRAFO = 4000;

    /** @var int Criterios de evaluacion que se piden como minimo; la ficha muestra estos de partida. */
    const CRITERIOS_MIN = 3;

    /**
     * @var int Tope tecnico de criterios de evaluacion. El cliente no fija un maximo («Agregue todos los que sean
     * necesarios», 29-10-2026): la persona agrega de a uno; el tope solo acota lo que se guarda.
     */
    const CRITERIOS_MAX = 30;

    /** @var int Casillas de criterio que se guardan siempre, aunque esten vacias (antes eran 5 fijas). */
    const CRITERIOS_FIJOS = 5;

    /**
     * Secciones de la ficha, con el texto del documento del cliente.
     *
     * Cada campo: id, tipo, num (numeracion de la ficha), label, ayuda (de la guia de llenado),
     * opciones (clave => texto), otro (clave de la opcion que abre un campo de texto «id_otro») y opcional (las
     * observaciones: si traen informacion aportan, si vienen vacias no son un vacio de la ficha).
     *
     * @return array
     */
    public static function sections(): array {
        $mo = ['bajo' => 'Bajo', 'medio' => 'Medio', 'alto' => 'Alto'];
        // Criterios de evaluacion: 3 de partida y los que la persona agregue, sin maximo (ajuste del 29-10-2026).
        $criterios = [];
        for ($i = 1; $i <= self::CRITERIOS_MAX; $i++) {
            $criterio = ['id' => 'criterio' . $i, 'tipo' => self::T_PARRAFO, 'label' => 'Criterio de evaluación ' . $i];
            if ($i === 1) {
                $criterio['antes'] = 'Registre los criterios de evaluación tal como están definidos en el plan formativo. '
                    . 'Se necesitan al menos 3 y no existe un número máximo de criterios de evaluación definidos por '
                    . 'aprendizaje esperado. Agregue todos los que sean necesarios para completar los que se establecen '
                    . 'en el Plan Formativo.';
            }
            if ($i > self::CRITERIOS_MIN) {
                $criterio['adicional'] = true;
            }
            $criterios[] = $criterio;
        }
        return [
            1 => ['titulo' => 'Identificación general', 'campos' => [
                ['id' => 'organismo', 'tipo' => self::T_TEXTO, 'label' => 'Organismo ejecutor de capacitación'],
                ['id' => 'plan', 'tipo' => self::T_TEXTO, 'label' => 'Plan formativo'],
                // Preguntas agregadas a pedido del cliente (29-10-2026; rotulos del Word del 30-10); «reciente»: ver clean().
                ['id' => 'modulo', 'tipo' => self::T_TEXTO, 'label' => 'Módulo', 'reciente' => true],
                ['id' => 'competencia', 'tipo' => self::T_PARRAFO, 'label' => 'Competencia del módulo', 'reciente' => true],
                ['id' => 'nivel', 'tipo' => self::T_TEXTO, 'label' => 'Nivel de cualificación',
                    'ayuda' => 'Según el Marco Nacional de Cualificaciones.'],
                ['id' => 'facilitador', 'tipo' => self::T_TEXTO, 'label' => 'Nombre del facilitador o facilitadora'],
                ['id' => 'fechas', 'tipo' => self::T_TEXTO, 'label' => 'Fecha(s)',
                    'ayuda' => 'Fecha(s) de aplicación del diagnóstico.'],
                ['id' => 'participantes', 'tipo' => self::T_NUMERO, 'label' => 'Nº de participantes',
                    'ayuda' => 'Total de participantes matriculados en el curso.'],
            ]],
            2 => ['titulo' => 'Aprendizaje esperado y criterios de evaluación del plan formativo', 'campos' => array_merge([
                ['id' => 'aprendizaje', 'tipo' => self::T_PARRAFO, 'num' => '2.1', 'label' => 'Aprendizaje esperado',
                    'ayuda' => 'Transcriba el aprendizaje esperado completo tal como está formulado en el plan formativo.'],
                ], $criterios, [
                ['id' => 'tiempo_total', 'tipo' => self::T_NUMERO, 'num' => '2.2',
                    'label' => 'Tiempo total disponible para el desarrollo del aprendizaje (en minutos)',
                    'ayuda' => 'Tiempo total asignado al logro del aprendizaje esperado, considerando las horas '
                        . 'totales contempladas para el módulo.'],
                ['id' => 'tiempo_experiencia', 'tipo' => self::T_NUMERO, 'num' => '2.3',
                    'label' => 'Tiempo asignado a la experiencia de aprendizaje a diseñar (en minutos)',
                    'ayuda' => 'Debe ser menor al tiempo total disponible: tras la implementación debieran '
                        . 'realizarse ajustes o acciones remediales para cerrar brechas.'],
                ['id' => 'obs_evaluacion', 'tipo' => self::T_PARRAFO, 'num' => '2.4',
                    'label' => 'Observaciones sobre la evaluación del aprendizaje esperado',
                    'ayuda' => 'Síntesis de la pertinencia y factibilidad de los criterios de evaluación definidos '
                        . 'en el plan formativo para el aprendizaje esperado (obligatorio).'],
            ])],
            3 => ['titulo' => 'Perfil ocupacional y plan formativo', 'campos' => [
                ['id' => 'vinculo', 'tipo' => self::T_UNA, 'num' => '3.1',
                    'antes' => 'Nota: si no existe Perfil Ocupacional, en el Catálogo Nacional ChileValora, asociado al '
                        . 'Plan Formativo, responda las siguientes preguntas respecto de su experiencia y conocimiento del '
                        . 'campo laboral en el cual se desempeñarán las personas participantes que egresen del Plan de '
                        . 'Formación.',
                    'label' => 'El aprendizaje esperado se vincula con:',
                    'ayuda' => 'Marque la dimensión predominante. En formación por competencias lo más frecuente '
                        . 'es la integración de las tres.',
                    'opciones' => ['saber' => 'Saber', 'saber_hacer' => 'Saber hacer', 'saber_ser' => 'Saber ser',
                        'integracion' => 'Integración de los tres']],
                ['id' => 'desempeno', 'tipo' => self::T_VARIAS, 'num' => '3.2',
                    'label' => 'El perfil ocupacional exige desempeño en:',
                    'ayuda' => 'Marque todas las opciones que apliquen según el análisis del perfil ocupacional.',
                    'opciones' => ['rutinarias' => 'Tareas rutinarias', 'tecnicas' => 'Tareas técnicas',
                        'decisiones' => 'Toma de decisiones', 'problemas' => 'Resolución de problemas',
                        'colaborativo' => 'Trabajo colaborativo']],
                ['id' => 'alineacion', 'tipo' => self::T_UNA, 'num' => '3.3', 'label' => 'El plan formativo está:',
                    'opciones' => ['total' => 'Totalmente alineado con el perfil ocupacional (los aprendizajes '
                        . 'esperados, contenidos y criterios de evaluación son coherentes con el perfil)',
                        'parcial' => 'Parcialmente alineado', 'ajustes' => 'Requiere ajustes importantes']],
                ['id' => 'complejidad', 'tipo' => self::T_UNA, 'num' => '3.4', 'label' => 'Nivel de complejidad esperado:',
                    'ayuda' => 'Nivel de dominio que se espera alcanzar al finalizar la experiencia de aprendizaje.',
                    'opciones' => ['inicial' => 'Inicial', 'intermedio' => 'Intermedio', 'avanzado' => 'Avanzado']],
                ['id' => 'obs3', 'tipo' => self::T_PARRAFO, 'opcional' => true,
                    'label' => 'Observaciones breves (opcional):',
                    'ayuda' => 'Si no existe Perfil Ocupacional de referencia para el Plan Formativo, señale esto en las '
                        . 'observaciones y justifique las respuestas que marcó en las preguntas anteriores.'],
            ]],
            4 => ['titulo' => 'Caracterización de los participantes', 'campos' => [
                ['id' => 'dominio', 'tipo' => self::T_UNA, 'num' => '4.1',
                    'label' => 'Nivel de dominio previo del grupo de participantes:', 'opciones' => $mo],
                ['id' => 'heterogeneidad', 'tipo' => self::T_UNA, 'num' => '4.2', 'label' => 'Heterogeneidad del grupo:',
                    'opciones' => ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta']],
                ['id' => 'necesidades', 'tipo' => self::T_VARIAS, 'num' => '4.3',
                    'label' => 'Principales necesidades del grupo (marcar todas las que apliquen):',
                    'opciones' => ['conceptual' => 'Refuerzo conceptual', 'practico' => 'Desarrollo práctico',
                        'autonomia' => 'Autonomía', 'equipo' => 'Trabajo en equipo', 'tecnologia' => 'Uso de tecnología',
                        'lectora' => 'Comprensión lectora', 'otra' => 'Otra:'], 'otro' => 'otra'],
                ['id' => 'participacion', 'tipo' => self::T_UNA, 'num' => '4.4',
                    'label' => 'Condición general de participación:',
                    'opciones' => ['regular' => 'Regular', 'intermitente' => 'Intermitente', 'irregular' => 'Irregular']],
                ['id' => 'obs4', 'tipo' => self::T_PARRAFO, 'opcional' => true,
                    'label' => 'Observaciones breves (opcional):'],
            ]],
            5 => ['titulo' => 'Contexto de aplicación', 'campos' => [
                ['id' => 'contexto', 'tipo' => self::T_UNA, 'num' => '5.1', 'label' => 'El aprendizaje se relaciona mejor con:',
                    'opciones' => ['real' => 'Contexto real', 'simulacion' => 'Simulación',
                        'teorico' => 'Teórico-práctico']],
                ['id' => 'estrategia_didactica', 'tipo' => self::T_UNA, 'num' => '5.2',
                    'label' => 'Estrategia didáctica más pertinente:',
                    'opciones' => ['proyecto' => 'Proyecto', 'problemas' => 'Resolución de problemas',
                        'casos' => 'Estudio de casos', 'demostracion' => 'Demostración guiada',
                        'taller' => 'Taller práctico']],
                ['id' => 'pertinencia_contexto', 'tipo' => self::T_UNA, 'num' => '5.3',
                    'label' => 'Pertinencia del contexto territorial o laboral:',
                    'opciones' => ['alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja']],
                ['id' => 'situaciones', 'tipo' => self::T_UNA, 'num' => '5.4',
                    'label' => 'Existen situaciones reales del entorno que pueden servir como referencia:',
                    'opciones' => ['si' => 'Sí', 'no' => 'No', 'parcial' => 'Parcialmente']],
                ['id' => 'obs5', 'tipo' => self::T_PARRAFO, 'opcional' => true,
                    'label' => 'Observaciones breves (opcional):'],
            ]],
            6 => ['titulo' => 'Recursos disponibles', 'campos' => [
                ['id' => 'espacio', 'tipo' => self::T_UNA, 'num' => '6.1', 'label' => 'Espacio físico:',
                    'opciones' => ['adecuado' => 'Adecuado', 'ajustes' => 'Adecuado con ajustes',
                        'insuficiente' => 'Insuficiente']],
                ['id' => 'equipamiento', 'tipo' => self::T_UNA, 'num' => '6.2', 'label' => 'Equipamiento técnico:',
                    'opciones' => ['completo' => 'Completo', 'parcial' => 'Parcial', 'insuficiente' => 'Insuficiente']],
                ['id' => 'conectividad', 'tipo' => self::T_UNA, 'num' => '6.3', 'label' => 'Acceso a tecnología:',
                    'opciones' => ['estable' => 'Internet estable', 'intermitente' => 'Internet intermitente',
                        'sin' => 'Sin conectividad']],
                ['id' => 'digitales', 'tipo' => self::T_VARIAS, 'num' => '6.4',
                    'label' => 'Recursos digitales disponibles (marcar todos los que apliquen):',
                    'opciones' => ['plataforma' => 'Plataforma virtual', 'software' => 'Software especializado',
                        'audiovisual' => 'Material audiovisual', 'simuladores' => 'Simuladores',
                        'ninguno' => 'Ninguno de los anteriores']],
                ['id' => 'accesibilidad', 'tipo' => self::T_UNA, 'num' => '6.5', 'label' => 'Accesibilidad:',
                    'opciones' => ['total' => 'Total', 'parcial' => 'Parcial', 'adecuaciones' => 'Requiere adecuaciones']],
                ['id' => 'obs6', 'tipo' => self::T_PARRAFO, 'opcional' => true,
                    'label' => 'Observaciones breves (opcional):'],
            ]],
            7 => ['titulo' => 'Decisión de diseño', 'campos' => [
                ['id' => 'modalidad', 'tipo' => self::T_UNA, 'num' => '7.1', 'label' => 'Modalidad prioritaria de trabajo:',
                    'opciones' => ['reales' => 'Condiciones reales', 'simulacion' => 'Simulación',
                        'teorico' => 'Teórico-práctica']],
                ['id' => 'estrategia', 'tipo' => self::T_UNA, 'num' => '7.2', 'label' => 'Estrategia principal:',
                    'opciones' => ['proyecto' => 'Proyecto', 'caso' => 'Caso', 'problema' => 'Problema',
                        'taller' => 'Taller', 'mixta' => 'Mixta']],
                ['id' => 'evidencia', 'tipo' => self::T_UNA, 'num' => '7.3', 'label' => 'Evidencia principal esperada:',
                    'opciones' => ['producto' => 'Producto', 'desempeno' => 'Desempeño', 'informe' => 'Informe',
                        'presentacion' => 'Presentación', 'portafolio' => 'Portafolio']],
                ['id' => 'instrumento', 'tipo' => self::T_UNA, 'num' => '7.4',
                    'label' => 'Instrumento evaluativo más adecuado:',
                    'opciones' => ['rubrica' => 'Rúbrica', 'cotejo' => 'Lista de cotejo',
                        'apreciacion' => 'Escala de apreciación', 'observacion' => 'Pauta de observación',
                        'prueba' => 'Prueba aplicada']],
                ['id' => 'obs7', 'tipo' => self::T_PARRAFO, 'opcional' => true,
                    'label' => 'Observaciones breves (opcional):'],
            ]],
            8 => ['titulo' => 'Juicio fundado', 'campos' => [
                ['id' => 'pertinencia_global', 'tipo' => self::T_UNA, 'num' => '8.1',
                    'label' => 'Grado de pertinencia global del diseño:',
                    'opciones' => ['muy_alto' => 'Muy alto', 'alto' => 'Alto', 'medio' => 'Medio', 'bajo' => 'Bajo']],
                ['id' => 'ajustes', 'tipo' => self::T_UNA, 'num' => '8.2',
                    'label' => 'Necesita ajustes antes de implementarse:',
                    'ayuda' => 'Si la respuesta es «Sí», especifique en las observaciones finales qué ajustes son necesarios.',
                    'opciones' => ['si' => 'Sí', 'no' => 'No']],
                ['id' => 'riesgo', 'tipo' => self::T_UNA, 'num' => '8.3', 'label' => 'Riesgo principal detectado:',
                    'opciones' => ['recursos' => 'Falta de recursos', 'dominio' => 'Bajo dominio del grupo',
                        'alineacion' => 'Escasa alineación curricular', 'tiempo' => 'Tiempo insuficiente',
                        'otro' => 'Otro:'], 'otro' => 'otro'],
                ['id' => 'obs_finales', 'tipo' => self::T_PARRAFO,
                    'label' => 'Observaciones finales (obligatorio):',
                    'ayuda' => 'Use este espacio para fundamentar brevemente las decisiones, justificar la selección de '
                        . 'modalidad y estrategia, o alertar sobre condiciones que requieren atención.'],
            ]],
            // La seccion 9 del documento («Validación»: fechas y responsables de revisión, validación con el
            // equipo y aprobación final) no va en el formulario en línea, a pedido del equipo (26-10-2026).
        ];
    }

    /**
     * Checklist de validacion, con el texto exacto del documento del cliente.
     *
     * @return array Seccion => ['titulo' => string, 'items' => [id => texto]].
     */
    public static function checklist(): array {
        return [
            1 => ['titulo' => 'Identificación general', 'items' => [
                'c1_1' => 'La ficha contiene todos los datos administrativos requeridos',
                'c1_2' => 'La información es clara y consistente',
            ]],
            2 => ['titulo' => 'Aprendizaje esperado y criterios', 'items' => [
                'c2_1' => 'El aprendizaje esperado está claramente definido',
                'c2_2' => 'Los criterios de evaluación están completos',
                'c2_3' => 'Los criterios no han sido modificados arbitrariamente',
                'c2_4' => 'El tiempo para el desarrollo y evaluación del aprendizaje es suficiente',
                'c2_5' => 'Existe coherencia entre aprendizaje y criterios',
            ]],
            3 => ['titulo' => 'Perfil ocupacional y plan formativo', 'items' => [
                'c3_1' => 'El aprendizaje se vincula con el perfil ocupacional',
                'c3_2' => 'Se identifica correctamente el tipo de saber',
                'c3_3' => 'El nivel de complejidad es adecuado',
                'c3_4' => 'Existe alineación con el plan formativo',
            ]],
            4 => ['titulo' => 'Caracterización de participantes', 'items' => [
                'c4_1' => 'Se identifica nivel previo del grupo',
                'c4_2' => 'Se reconoce heterogeneidad',
                'c4_3' => 'Se identifican necesidades formativas',
                'c4_4' => 'La información es realista (no idealizada)',
            ]],
            5 => ['titulo' => 'Contexto de aplicación', 'items' => [
                'c5_1' => 'El contexto es coherente con el aprendizaje',
                'c5_2' => 'La estrategia didáctica es pertinente',
                'c5_3' => 'Existen condiciones para aplicar el aprendizaje',
            ]],
            6 => ['titulo' => 'Recursos disponibles', 'items' => [
                'c6_1' => 'Los recursos son suficientes para el diseño',
                'c6_2' => 'Se identifican limitaciones reales',
                'c6_3' => 'La conectividad está considerada',
                'c6_4' => 'La accesibilidad está considerada',
            ]],
            7 => ['titulo' => 'Decisiones de diseño', 'items' => [
                'c7_1' => 'La modalidad es coherente con el contexto',
                'c7_2' => 'La estrategia metodológica es adecuada',
                'c7_3' => 'La evidencia de evaluación es pertinente',
                'c7_4' => 'El instrumento es coherente con la evidencia',
            ]],
            8 => ['titulo' => 'Juicio fundado', 'items' => [
                'c8_1' => 'Se identifica nivel de pertinencia',
                'c8_2' => 'Se identifican riesgos',
                'c8_3' => 'Se justifica la decisión de diseño',
            ]],
            9 => ['titulo' => 'Validación', 'items' => [
                'c9_1' => 'La ficha está completa',
                'c9_2' => 'La información es coherente globalmente',
                'c9_3' => 'El diagnóstico permite diseñar',
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
     * Claves que se guardan de la ficha: los campos y sus «_otro».
     *
     * @return array clave => tipo de dato (texto, parrafo, numero, una, varias).
     */
    public static function field_keys(): array {
        $claves = [];
        foreach (self::sections() as $seccion) {
            foreach ($seccion['campos'] as $campo) {
                $claves[$campo['id']] = $campo['tipo'];
                if (!empty($campo['otro'])) {
                    $claves[$campo['id'] . '_otro'] = self::T_TEXTO;
                }
            }
        }
        return $claves;
    }

    /**
     * Campos opcionales de la ficha, con su rotulo para el modelo: las observaciones breves de las secciones 3 a 7
     * (formulario del cliente del 30-10-2026). Vacios no bajan ningun item.
     *
     * @return string[] id => rotulo («Sección 3: Observaciones breves», ...).
     */
    public static function optional_fields(): array {
        $opcionales = [];
        foreach (self::sections() as $n => $seccion) {
            foreach ($seccion['campos'] as $campo) {
                if (!empty($campo['opcional'])) {
                    $rotulo = rtrim(str_replace(' (opcional)', '', $campo['label']), ':');
                    $opcionales[$campo['id']] = !empty($campo['num']) ? $campo['num'] . ' ' . $rotulo
                        : 'Sección ' . $n . ': ' . $rotulo;
                }
            }
        }
        return $opcionales;
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
     * @param array $raw clave => string|string[] tal como llega.
     * @return array clave => string|string[] (las listas de opciones como arreglo).
     */
    public static function clean(array $raw): array {
        $limpio = [];
        foreach (self::field_keys() as $clave => $tipo) {
            $valor = $raw[$clave] ?? null;
            switch ($tipo) {
                case self::T_VARIAS:
                    $opciones = self::field($clave)['opciones'];
                    $lista = is_array($valor) ? $valor : [];
                    // Se conserva el orden de la ficha, no el del envio.
                    $limpio[$clave] = array_values(array_filter(array_keys($opciones),
                        static function($k) use ($lista) {
                            return in_array($k, $lista, true);
                        }));
                    break;
                case self::T_UNA:
                    $opciones = self::field($clave)['opciones'];
                    $valor = is_string($valor) ? $valor : '';
                    $limpio[$clave] = array_key_exists($valor, $opciones) ? $valor : '';
                    break;
                case self::T_NUMERO:
                    $valor = is_scalar($valor) ? trim((string) $valor) : '';
                    $limpio[$clave] = preg_match('/^\d{1,7}$/', $valor) ? (string) (int) $valor : '';
                    break;
                default:
                    $valor = is_scalar($valor) ? trim(str_replace("\r\n", "\n", (string) $valor)) : '';
                    $largo = $tipo === self::T_PARRAFO ? self::LARGO_PARRAFO : self::LARGO_TEXTO;
                    $limpio[$clave] = \core_text::substr($valor, 0, $largo);
            }
        }
        // Las preguntas agregadas despues de que hubo fichas validadas («reciente») se guardan solo si traen texto:
        // asi una ficha anterior queda igual y su huella no cambia (sus validaciones siguen vigentes).
        foreach (self::sections() as $seccion) {
            foreach ($seccion['campos'] as $campo) {
                if (!empty($campo['reciente']) && ($limpio[$campo['id']] ?? '') === '') {
                    unset($limpio[$campo['id']]);
                }
            }
        }
        // Lo mismo con los criterios agregados (del 6 en adelante).
        for ($i = self::CRITERIOS_FIJOS + 1; $i <= self::CRITERIOS_MAX; $i++) {
            if ($limpio['criterio' . $i] === '') {
                unset($limpio['criterio' . $i]);
            }
        }
        // «Otra» sin marcar no guarda texto: la ficha no queda diciendo algo que no se eligio.
        foreach (self::sections() as $seccion) {
            foreach ($seccion['campos'] as $campo) {
                if (empty($campo['otro'])) {
                    continue;
                }
                $elegido = $limpio[$campo['id']];
                $marcado = is_array($elegido) ? in_array($campo['otro'], $elegido, true) : $elegido === $campo['otro'];
                if (!$marcado) {
                    $limpio[$campo['id'] . '_otro'] = '';
                }
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
        return is_array($valor) ? empty($valor) : trim((string) $valor) === '';
    }

    /**
     * Control de entrada: sin esto no se valida.
     *
     * Regla del cliente (30-10-2026): todas las secciones completas salvo los campos marcados como opcionales (las
     * observaciones breves de las secciones 3 a 7), al menos 3 criterios de evaluacion, «Otro» especificado si se
     * marco, y el Plan Formativo adjunto. El Perfil Ocupacional es opcional.
     *
     * @param array $data Ficha ya limpia.
     * @param string[]|null $adjuntos Areas de los archivos adjuntos (exante_plan, exante_perfil); null no los revisa.
     * @return string[] Lo que falta, con el texto de la ficha.
     */
    public static function missing_inputs(array $data, ?array $adjuntos = null): array {
        // Regla del cliente (30-10-2026): para validar se completan todas las secciones, salvo los campos marcados
        // como opcionales, y se adjunta el Plan Formativo (el Perfil Ocupacional es opcional).
        $falta = [];
        foreach (self::sections() as $n => $seccion) {
            foreach ($seccion['campos'] as $campo) {
                $id = $campo['id'];
                if ($id === 'criterio1') {
                    // Al menos 3 criterios, sin maximo (ajuste del cliente del 29-10-2026, que reemplaza el «no mas
                    // de 5» del Prompt Maestro).
                    $criterios = self::criteria_count($data);
                    if ($criterios < self::CRITERIOS_MIN) {
                        $falta[] = '2.1 Criterios de evaluación (mínimo ' . self::CRITERIOS_MIN . '; la ficha tiene '
                            . $criterios . ')';
                    }
                }
                if (!empty($campo['opcional']) || strpos($id, 'criterio') === 0) {
                    continue;
                }
                $nombre = !empty($campo['num']) ? $campo['num'] . ' ' . rtrim($campo['label'], ':')
                    : 'Sección ' . $n . ': ' . rtrim(str_replace(' (obligatorio)', '', $campo['label']), ':');
                if (self::vacio($data[$id] ?? '')) {
                    $falta[] = $nombre;
                } else if (!empty($campo['otro']) && in_array($campo['otro'], (array) $data[$id], true)
                        && self::vacio($data[$id . '_otro'] ?? '')) {
                    $falta[] = $nombre . ' (especificar «' . rtrim($campo['opciones'][$campo['otro']], ':') . '»)';
                }
            }
        }
        if ($adjuntos !== null && !in_array('exante_plan', $adjuntos, true)) {
            $falta[] = 'Plan Formativo (archivo adjunto obligatorio)';
        }
        return $falta;
    }

    /**
     * Cuantos criterios de evaluacion se registraron.
     *
     * @param array $data
     * @return int
     */
    public static function criteria_count(array $data): int {
        $n = 0;
        for ($i = 1; $i <= self::CRITERIOS_MAX; $i++) {
            if (!self::vacio($data['criterio' . $i] ?? '')) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Regla de fuentes del Asistente de Diseño Instruccional (pedido del cliente del 30-10-2026): para el enfoque de
     * competencias, preferentemente su libreria de conocimientos; para la especialidad tecnica de la persona, la web.
     * Funcion pura: la usan la validacion (Fase 1), las etapas (Fase 2) y la evaluacion (Fase 3).
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
                . 'preferentemente la LIBRERÍA DE CONOCIMIENTOS DEL ' . \core_text::strtoupper(self::ROL) . ' que viene en '
                . 'este pedido: basa tus decisiones en ella antes que en conocimiento general.'
            : '- Para todo lo relacionado con la aplicación del enfoque de competencias, la fuente preferente es la '
                . 'librería de conocimientos del ' . self::ROL . '; en este pedido no viene cargada, así que aplica el '
                . 'enfoque de competencias con criterios generales y prudencia.';
        $l[] = $conbusqueda
            ? '- Para lo relacionado con la especialidad técnica de la persona (el oficio, sus procedimientos, equipos, '
                . 'normativa y terminología del sector), busca información actualizada en la web. No inventes datos '
                . 'técnicos: si no encuentras información confiable, dilo.'
            : '- Para lo relacionado con la especialidad técnica de la persona (el oficio, sus procedimientos, equipos, '
                . 'normativa y terminología del sector), usa tu conocimiento general con prudencia, no inventes datos '
                . 'técnicos y señala lo que la persona debe verificar en fuentes del sector.';
        $l[] = '- La librería y la web complementan la ficha de diagnóstico y el Plan Formativo, que siguen siendo la fuente '
            . 'principal.';
        return $l;
    }

    /**
     * Uso de los documentos complementarios segun el Prompt Maestro del Modulo 2: el Plan Formativo es documento de
     * referencia obligatorio; el Perfil Ocupacional, opcional. Funcion pura (la usan la validacion y las etapas).
     *
     * @param string[] $adjuntos Etiquetas de los documentos adjuntos ('Plan Formativo', 'Perfil Ocupacional').
     * @return string
     */
    public static function documents_rule(array $adjuntos): string {
        $plan = in_array('Plan Formativo', $adjuntos, true);
        $perfil = in_array('Perfil Ocupacional', $adjuntos, true);
        $texto = $plan
            ? 'Se adjunta el Plan Formativo, documento de referencia obligatorio: úsalo para verificar que el aprendizaje '
                . 'esperado, los criterios de evaluación, la competencia del módulo y el nivel de cualificación de la ficha '
                . 'correspondan a lo que declara.'
            : 'No se adjuntó el Plan Formativo, que es documento de referencia obligatorio: indícalo.';
        $texto .= $perfil
            ? ' Se adjunta el Perfil Ocupacional (opcional): úsalo para validar coherencia, profundidad y alineación, '
                . 'priorizando siempre la información de la ficha de diagnóstico y del Plan Formativo.'
            : ' No se adjuntó el Perfil Ocupacional, que es opcional: continúa normalmente, sin bajar por eso ningún ítem, '
                . 'y señala cuando alguna decisión podría beneficiarse de ese documento.';
        return $texto;
    }

    /**
     * La libreria del rol como parte del pedido al modelo.
     *
     * @param string $libreria Texto de la libreria (kb_store::get_combined_context(AMBITO_M2)); '' si no hay.
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
     * Si el modelo puede buscar en la web: interruptor «busquedaweb» y proveedor que la ofrece (solo Gemini).
     *
     * @return bool
     */
    public static function web_search_available(): bool {
        return ux::on('busquedaweb') && ai\provider_factory::get() instanceof ai\gemini_provider;
    }

    /**
     * Libreria del rol para un pedido: vacia con el interruptor «libreriam2» apagado.
     *
     * @return string
     */
    public static function role_library(): string {
        return ux::on('libreriam2') ? kb_store::get_combined_context(kb_store::AMBITO_M2) : '';
    }

    /**
     * Mensaje de bienvenida del Prompt Maestro, textual («Al iniciar, debes mostrar exactamente el siguiente
     * mensaje»). Se muestra al comienzo de la ficha.
     *
     * @return string[] Los parrafos.
     */
    public static function welcome(): array {
        return [
            'Bienvenido/a, soy tu asistente de apoyo en diseño instruccional por competencias.',
            'Te puedo ayudar a analizar fichas de diagnóstico (evaluación ex-ante), diseñar experiencias de aprendizaje, '
                . 'construir secuencias instruccionales, proponer materiales de apoyo y desarrollar planes e instrumentos '
                . 'de evaluación.',
            'Comienza completando tu ficha de diagnóstico. Después de completar la ficha, debes cargar también el Plan de '
                . 'Formación para el cual estás diseñando la experiencia de aprendizaje y, opcionalmente, el Perfil '
                . 'Ocupacional al cual tributa el Plan de Formación, lo que permitirá enriquecer el análisis y mejorar la '
                . 'precisión del diseño.',
        ];
    }

    /**
     * Avisos antes de validar, con reglas fijas (sin IA, sin gastar un intento). Son avisos: no impiden validar.
     *
     * @param array $data Ficha ya limpia.
     * @return string[] Un texto por aviso.
     */
    public static function form_warnings(array $data): array {
        $avisos = [];
        // La Guia de llenado: «parcialmente alineado» (3.3) y «no necesita ajustes» (8.2) se contradicen.
        if (($data['alineacion'] ?? '') === 'parcial' && ($data['ajustes'] ?? '') === 'no') {
            $avisos[] = 'En 3.3 marcaste «Parcialmente alineado» y en 8.2 «No» necesita ajustes. Según la Guía de llenado '
                . 'esas dos respuestas se contradicen: revisa si en 8.2 corresponde «Sí» y anota qué ajustes hacen falta.';
        }
        $total = trim((string) ($data['tiempo_total'] ?? ''));
        $experiencia = trim((string) ($data['tiempo_experiencia'] ?? ''));
        if ($total !== '' && $experiencia !== '' && (int) $experiencia >= (int) $total) {
            $avisos[] = 'El tiempo de la experiencia (2.3) debe ser menor que el tiempo total disponible (2.2): '
                . 'tras la implementación se necesitan ajustes o acciones remediales.';
        }
        foreach (self::sections() as $seccion) {
            foreach ($seccion['campos'] as $campo) {
                if (empty($campo['otro'])) {
                    continue;
                }
                $elegido = (array) ($data[$campo['id']] ?? []);
                if (in_array($campo['otro'], $elegido, true) && trim((string) ($data[$campo['id'] . '_otro'] ?? '')) === '') {
                    $avisos[] = 'En ' . $campo['num'] . ' marcaste «' . rtrim($campo['opciones'][$campo['otro']], ':')
                        . '» sin especificar cuál.';
                }
            }
        }
        return $avisos;
    }

    /**
     * Los motivos graves, los unicos que dejan una ficha en «No aprobado» (cierre de la regla del Prompt Maestro:
     * «problemas en aprendizaje esperado, criterios de evaluacion, coherencia general»).
     *
     * @return string[] codigo => descripcion.
     */
    public static function serious_reasons(): array {
        // Regla de decision del Prompt Maestro del Modulo 2 (version del 30-10-2026).
        return [
            'aprendizaje' => 'Aprendizaje esperado: no hay correspondencia entre el definido en la ficha y el declarado en '
                . 'el Plan Formativo.',
            'criterios' => 'Criterios de evaluación: no hay correspondencia entre los definidos en la ficha y los '
                . 'declarados en el Plan Formativo.',
            'competencia' => 'Competencia del módulo y nivel de cualificación: no hay correspondencia entre lo definido en '
                . 'la ficha y lo declarado en el Plan Formativo.',
            'coherencia' => 'Coherencia general: hay inconsistencias profundas entre los compromisos formativos que se '
                . 'establecen en los ítems de las secciones 1, 2 y 3 y los recursos, estrategias y decisiones de diseño que '
                . 'se establecen en los ítems de las secciones 5, 6 y 7.',
        ];
    }

    /**
     * Motivos graves que se comprueban contra el Plan Formativo: sin el plan adjunto no se sostienen.
     *
     * @return string[]
     */
    public static function plan_reasons(): array {
        return ['aprendizaje', 'criterios', 'competencia'];
    }

    /**
     * Texto sin tildes ni signos y en minusculas, para comparar una cita con la ficha.
     *
     * @param string $texto
     * @return string
     */
    private static function compare_text(string $texto): string {
        $t = \core_text::strtolower(\core_text::specialtoascii($texto));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $t));
    }

    /**
     * Motivo grave que propuso el modelo, solo si se sostiene: es uno de los cuatro y trae una cita que esta en la
     * ficha (con el motivo «plan» la cita puede venir del Plan Formativo adjunto, que aqui no se puede comparar).
     *
     * El modelo tiene que haber propuesto «No aprobado»: un motivo que acompana a otra condicion no detiene la ficha.
     * Los motivos que comparan con el Plan Formativo (plan_reasons()) exigen el plan adjunto; la cita es siempre el
     * fragmento de la ficha que no corresponde (o que es inconsistente).
     *
     * @param array|null $motivo ['codigo' => string, 'cita' => string] tal como salio de parse_report().
     * @param string $fichatexto La ficha tal como se le entrego al modelo (seudonimizada).
     * @param string|null $modelo Condicion que propuso el modelo.
     * @param bool $conplan Si la validacion llevo el Plan Formativo adjunto.
     * @return array|null El motivo, o null si no se sostiene.
     */
    public static function valid_reason(?array $motivo, string $fichatexto, ?string $modelo, bool $conplan = false): ?array {
        $codigo = (string) ($motivo['codigo'] ?? '');
        $cita = trim((string) ($motivo['cita'] ?? ''));
        if ($modelo !== self::NO_APROBADO || !isset(self::serious_reasons()[$codigo])
                || \core_text::strlen($cita) < self::CITA_MIN) {
            return null;
        }
        if (in_array($codigo, self::plan_reasons(), true) && !$conplan) {
            return null;
        }
        if (strpos(self::compare_text($fichatexto), self::compare_text($cita)) === false) {
            return null;
        }
        return ['codigo' => $codigo, 'cita' => $cita, 'corregir' => (string) ($motivo['corregir'] ?? '')];
    }

    /**
     * La ficha como texto para el modelo, en el orden y con los rotulos del documento.
     *
     * El nombre de la persona facilitadora no viaja: el checklist solo necesita saber si esta.
     *
     * @param array $data Ficha ya limpia.
     * @return string
     */
    public static function to_text(array $data): string {
        $sin = '(sin respuesta)';
        $lineas = [];
        foreach (self::sections() as $n => $seccion) {
            $lineas[] = '';
            $lineas[] = $n . '. ' . $seccion['titulo'];
            foreach ($seccion['campos'] as $campo) {
                $id = $campo['id'];
                // Los criterios agregados que estan vacios no son un faltante: no viajan.
                if (!empty($campo['adicional']) && trim((string) ($data[$id] ?? '')) === '') {
                    continue;
                }
                $rotulo = (!empty($campo['num']) ? $campo['num'] . '. ' : '') . rtrim($campo['label'], ':');
                switch ($campo['tipo']) {
                    case self::T_UNA:
                    case self::T_VARIAS:
                        $elegidos = (array) ($data[$id] ?? []);
                        $textos = [];
                        foreach ($elegidos as $k) {
                            if ($k === '' || !isset($campo['opciones'][$k])) {
                                continue;
                            }
                            $t = rtrim($campo['opciones'][$k], ':');
                            if (!empty($campo['otro']) && $k === $campo['otro']) {
                                $otro = trim((string) ($data[$id . '_otro'] ?? ''));
                                $t .= ': ' . ($otro !== '' ? $otro : '(sin especificar)');
                            }
                            $textos[] = $t;
                        }
                        $valor = $textos ? implode('; ', $textos) : $sin;
                        break;
                    default:
                        $valor = trim((string) ($data[$id] ?? ''));
                        if ($id === 'facilitador' && $valor !== '') {
                            $valor = '(registrado)';
                        }
                        if ($valor === '') {
                            $valor = !empty($campo['opcional']) ? '(opcional, sin información)' : $sin;
                        }
                }
                $lineas[] = '- ' . $rotulo . ': ' . $valor;
            }
        }
        return trim(implode("\n", $lineas));
    }

    /**
     * Huella de lo que se valida: la ficha y los archivos adjuntos. Cambiar cualquiera pide validar de nuevo.
     *
     * @param array $data Ficha ya limpia.
     * @param string[] $adjuntos Huellas de contenido de los archivos, por area.
     * @return string
     */
    public static function fingerprint(array $data, array $adjuntos): string {
        ksort($data);
        ksort($adjuntos);
        return sha1(json_encode([$data, $adjuntos], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Huella de una ficha guardada tal como se valido y con el esquema vigente. Si se quita un campo de la ficha
     * (la seccion 9, 26-10-2026) la huella cambia aunque la persona no haya tocado nada: con esto la migracion pone
     * al dia la de sus validaciones en vez de dejarlas desactualizadas.
     *
     * @param array $guardada Ficha tal como esta en la base (limpia con el esquema con que se guardo).
     * @param string[] $adjuntos Huellas de contenido de los archivos, por area.
     * @return string[] [huella con que se valido, huella con el esquema vigente].
     */
    public static function schema_fingerprints(array $guardada, array $adjuntos): array {
        return [self::fingerprint($guardada, $adjuntos), self::fingerprint(self::clean($guardada), $adjuntos)];
    }

    /**
     * Instrucciones de validacion: Etapa 0 del Prompt Maestro con el checklist completo.
     *
     * @param string[] $adjuntos Etiquetas de los documentos complementarios que se adjuntaron.
     * @param bool $conlibreria Si la libreria del rol viene en el pedido.
     * @param bool $conbusqueda Si el modelo puede buscar en la web.
     * @return string
     */
    public static function build_prompt(array $adjuntos, bool $conlibreria = false, bool $conbusqueda = false): string {
        $checklist = [];
        foreach (self::checklist() as $n => $seccion) {
            $checklist[] = $n . '. ' . \core_text::strtoupper($seccion['titulo']);
            foreach ($seccion['items'] as $id => $texto) {
                $checklist[] = '   ' . $id . ': ' . $texto;
            }
        }
        $complementarios = self::documents_rule($adjuntos);
        $motivos = [];
        foreach (self::serious_reasons() as $codigo => $texto) {
            $motivos[] = '  "' . $codigo . '": ' . $texto;
        }

        return implode("\n", [
            'Eres el ' . self::ROL . ': un asistente experto en diseño instruccional con enfoque de competencias, '
                . 'especializado en formación de personas adultas en contextos de capacitación laboral. Apoyas a personas '
                . 'facilitadoras en la Fase 1 (Diagnóstico y Validación) del diseño de experiencias de aprendizaje, con la '
                . 'ficha de diagnóstico como insumo principal y obligatorio, el Plan Formativo como documento de '
                . 'referencia obligatorio y, opcionalmente, el Perfil Ocupacional.',
            '',
            'TAREA: aplica la ETAPA 0, VALIDACIÓN DEL DIAGNÓSTICO, a la ficha de diagnóstico (evaluación ex-ante) '
                . 'que viene más abajo. Aplica el checklist completo, sin omitir secciones ni ítems. No construyas '
                . 'ni completes la ficha, no diseñes la experiencia de aprendizaje y no avances a otras etapas: solo '
                . 'evalúas lo que la persona registró. Tu comportamiento es estricto, estructurado y orientado a '
                . 'asegurar una calidad mínima antes de avanzar.',
            '',
            'Evalúa cada ítem con uno de estos valores: "cumple" (✔ Cumple), "parcial" (⚠ Cumple parcialmente) o '
                . '"no_cumple" (❌ No cumple). Agrega una observación breve y específica, que se refiera a lo '
                . 'registrado en la ficha; es obligatoria cuando el ítem no es "cumple". Cuando el ítem no es '
                . '"cumple", agrega además una "propuesta": una mejora concreta con la frase lista para copiar en la '
                . 'ficha, indicando en qué campo va (por ejemplo: "En 8.2 marca Sí y anota: conectividad irregular → '
                . 'materiales descargables").',
            '',
            'REGLAS DE GOBERNANZA:',
            '- La ficha de diagnóstico y el Plan Formativo son la fuente principal. ' . $complementarios,
            '- No inventes aprendizajes esperados ni criterios de evaluación.',
            '- No se utilizan menos de 3 criterios de evaluación en el diseño de la experiencia de aprendizaje, y no hay '
                . 'un número máximo. Si la ficha registra menos de 3, indícalo; si registra más de 5, es normal: no lo '
                . 'señales.',
            self::NO_PEDAGOGIA,
            '- La ficha lista solo los criterios de evaluación que la persona registró: no reportes como faltantes '
                . 'casillas de criterios que no aparecen.',
            '- Una decisión razonable que la persona no explicó (por ejemplo, usar 4 de los 9 criterios de la '
                . 'unidad o marcar "Parcialmente alineado") se evalúa "cumple"; puedes sugerir explicarla.',
            '- La sinceridad no se castiga: reconocer una alineación parcial (3.3), una limitación de recursos o un '
                . 'grupo heterogéneo no baja otros ítems (por ejemplo, 3.3 "Parcialmente alineado" no baja el ítem '
                . 'c3_4) mientras la ficha sea coherente consigo misma.',
            '- Los ítems de la sección 9 resumen lo ya evaluado: no los bajes por algo que ya señalaste en otro ítem; '
                . 'evalúalos solo sobre lo que sigue sin resolver.',
            '- El tiempo asignado a la experiencia (2.3) debe ser menor que el tiempo total disponible (2.2).',
            '- Una opción "Otro" u "Otra" marcada debe venir especificada.',
            '- Detecta vacíos de información y repórtalos. Propón soluciones viables sin alterar el plan formativo.',
            '- Las observaciones breves de las secciones 3 a 7 son opcionales (' . implode('; ', self::optional_fields())
                . '). Si traen información, considérala como aporte a la ficha. Si vienen vacías ("opcional, sin '
                . 'información"), no es un vacío de información: no lo menciones en observaciones, debilidades ni '
                . 'sugerencias, y no bajes por eso la evaluación de ningún ítem.',
            '- La ficha en línea no incluye la tabla de validación del documento (fechas y responsables de la '
                . 'revisión, de la validación con el equipo y de la aprobación final): no la reportes como faltante. '
                . 'Los ítems de la sección 9 del checklist se evalúan sobre la ficha completa.',
            '- Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género.',
            '- Evalúas la ficha, no a la persona: no asignes notas ni puntajes.',
            '',
            implode("\n", self::knowledge_rules($conlibreria, $conbusqueda)),
            '',
            'CHECKLIST (id: ítem):',
            implode("\n", $checklist),
            '',
            'REGLA DE DECISIÓN:',
            '- Tu apoyo es ayudar a que el diagnóstico sirva para diseñar, no a detener a la persona. La ficha se '
                . 'detiene ("no_aprobado") únicamente por uno de estos MOTIVOS GRAVES, y debes nombrarlo en '
                . '"motivo_grave" con su código y citar, textual, el fragmento de la ficha que lo prueba (el que no '
                . 'corresponde al Plan Formativo o el que es inconsistente):',
            implode("\n", $motivos),
            '- Cualquier otra debilidad (ítems "parcial" o "no_cumple" que no son uno de esos cuatro motivos) nunca '
                . 'deja la ficha en "no_aprobado".',
            '- "aprobado": ningún ítem "no_cumple" (puede haber ítems "parcial"). El diagnóstico es suficiente para '
                . 'diseñar.',
            '- "observaciones": al menos un ítem "no_cumple" que no es un motivo grave. La persona puede avanzar; '
                . 'las sugerencias le ayudarán en el diseño.',
            '- "no_aprobado": solo con un motivo grave, citado de la ficha. Sin motivo grave no uses "no_aprobado".',
            '',
            guardrails::injection_clause(),
            '',
            'FORMATO DE RESPUESTA: únicamente un objeto JSON válido, sin texto antes ni después y sin bloques de '
                . 'código, con esta forma:',
            '{"items": {"c1_1": {"estado": "cumple|parcial|no_cumple", "observacion": "...", "propuesta": "frase lista '
                . 'para copiar (vacía si cumple)"}, ... un elemento por cada id del checklist ...}, '
                . '"condicion": "aprobado|observaciones|no_aprobado", '
                . '"motivo_grave": null, o {"codigo": "' . implode('|', array_keys(self::serious_reasons())) . '", "cita": '
                . '"fragmento textual de la ficha que lo prueba", "corregir": "cómo corregirlo"}, '
                . '"debilidades": ["principales debilidades, máximo 6"], '
                . '"sugerencias": ["sugerencias de mejora concretas para enriquecer el diseño, máximo 6"]}',
        ]);
    }

    /**
     * Autorrevision con el checklist (la persona marca cada item antes de validar): solo items y valores validos.
     *
     * @param array $raw id => valor tal como llega.
     * @return string[] id => CUMPLE, PARCIAL o NO_CUMPLE (sin los que no se marcaron).
     */
    public static function self_check_clean(array $raw): array {
        $limpio = [];
        foreach (self::checklist_ids() as $id) {
            $v = $raw[$id] ?? '';
            if (in_array($v, [self::CUMPLE, self::PARCIAL, self::NO_CUMPLE], true)) {
                $limpio[$id] = $v;
            }
        }
        return $limpio;
    }

    /**
     * Resumen de la autorrevision. Con los 32 items marcados, la condicion sale de la misma regla que aplica el
     * asistente (decide), sin condicion propuesta por el modelo.
     *
     * @param string[] $marcas Resultado de self_check_clean().
     * @param bool $estricto Regla anterior a v1.20.6 (interruptor «iaapoyo» apagado), como en decide().
     * @return array cumple, parcial, no_cumple, sin_marcar (int), condicion (string|null), criticos (ids criticos en ❌).
     */
    public static function self_check_summary(array $marcas, bool $estricto = false): array {
        $cuenta = array_count_values($marcas);
        $total = count(self::checklist_ids());
        $items = [];
        foreach ($marcas as $id => $estado) {
            $items[$id] = ['estado' => $estado];
        }
        return [
            'cumple' => $cuenta[self::CUMPLE] ?? 0,
            'parcial' => $cuenta[self::PARCIAL] ?? 0,
            'no_cumple' => $cuenta[self::NO_CUMPLE] ?? 0,
            'sin_marcar' => $total - count($marcas),
            'condicion' => count($marcas) === $total ? self::decide($items, null, null, $estricto) : null,
            'criticos' => array_values(array_filter(self::CRITICOS, static function($id) use ($marcas) {
                return ($marcas[$id] ?? '') === self::NO_CUMPLE;
            })),
        ];
    }

    /**
     * Normaliza la evaluacion de un item tal como la escriba el modelo.
     *
     * @param mixed $valor
     * @return string|null Una de CUMPLE, PARCIAL o NO_CUMPLE; null si no se reconoce.
     */
    public static function normalize_status($valor): ?string {
        if (!is_string($valor)) {
            return null;
        }
        $v = \core_text::strtolower(trim($valor));
        // El simbolo solo decide si no viene la palabra («✔», a secas); con «❌ No cumple» manda el texto.
        $simbolo = null;
        foreach (['✔' => self::CUMPLE, '✓' => self::CUMPLE, '⚠' => self::PARCIAL, '❌' => self::NO_CUMPLE] as $s => $e) {
            if (strpos($v, $s) !== false) {
                $simbolo = $e;
            }
        }
        $v = trim(preg_replace('/[^\p{L}]+/u', '_', $v), '_');
        if ($v === '') {
            return $simbolo;
        }
        if (in_array($v, ['cumple'], true)) {
            return self::CUMPLE;
        }
        if (in_array($v, ['parcial', 'cumple_parcialmente', 'parcialmente'], true)) {
            return self::PARCIAL;
        }
        if (in_array($v, ['no_cumple', 'nocumple', 'no'], true)) {
            return self::NO_CUMPLE;
        }
        return null;
    }

    /**
     * Normaliza la condicion propuesta por el modelo.
     *
     * @param mixed $valor
     * @return string|null
     */
    public static function normalize_condition($valor): ?string {
        if (!is_string($valor)) {
            return null;
        }
        $v = str_replace([' ', '-'], '_', \core_text::strtolower(trim($valor)));
        if ($v === 'aprobado') {
            return self::APROBADO;
        }
        if (in_array($v, ['observaciones', 'aprobado_con_observaciones', 'con_observaciones'], true)) {
            return self::OBSERVACIONES;
        }
        if (in_array($v, ['no_aprobado', 'noaprobado', 'rechazado'], true)) {
            return self::NO_APROBADO;
        }
        return null;
    }

    /**
     * Lee la respuesta del modelo. Si falta un item o alguno no se entiende, el informe no sirve:
     * no se completa a medias lo que el checklist pide evaluar entero.
     *
     * @param string|null $texto
     * @return array|null items (id => [estado, observacion]), condicion_modelo, debilidades, sugerencias.
     */
    public static function parse_report(?string $texto): ?array {
        $json = self::decode_json($texto);
        if (!$json || !isset($json['items']) || !is_array($json['items'])) {
            return null;
        }

        $items = [];
        foreach (self::checklist_ids() as $id) {
            $item = $json['items'][$id] ?? null;
            if (!is_array($item)) {
                return null;
            }
            $estado = self::normalize_status($item['estado'] ?? null);
            if ($estado === null) {
                return null;
            }
            $items[$id] = [
                'estado' => $estado,
                'observacion' => self::short_text($item['observacion'] ?? '', 600),
                'propuesta' => $estado === self::CUMPLE ? '' : self::short_text($item['propuesta'] ?? '', 600),
            ];
        }

        $motivo = null;
        if (isset($json['motivo_grave']) && is_array($json['motivo_grave'])) {
            $codigo = self::short_text($json['motivo_grave']['codigo'] ?? '', 30);
            $motivo = ['codigo' => \core_text::strtolower($codigo),
                'cita' => self::short_text($json['motivo_grave']['cita'] ?? '', 600),
                'corregir' => self::short_text($json['motivo_grave']['corregir'] ?? '', 600)];
        }

        return [
            'items' => $items,
            'motivo' => $motivo,
            'condicion_modelo' => self::normalize_condition($json['condicion'] ?? null),
            'debilidades' => self::text_list($json['debilidades'] ?? []),
            'sugerencias' => self::text_list($json['sugerencias'] ?? []),
        ];
    }

    /**
     * El objeto JSON de una respuesta del modelo, aunque venga entre bloques de codigo o con texto alrededor.
     *
     * @param string|null $texto
     * @return array|null
     */
    public static function decode_json(?string $texto): ?array {
        if ($texto === null) {
            return null;
        }
        $texto = trim(preg_replace('/^```(json)?|```$/m', '', $texto));
        $json = json_decode($texto, true);
        if (!is_array($json)) {
            $ini = strpos($texto, '{');
            $fin = strrpos($texto, '}');
            if ($ini === false || $fin === false || $fin <= $ini) {
                return null;
            }
            $json = json_decode(substr($texto, $ini, $fin - $ini + 1), true);
        }
        return is_array($json) ? $json : null;
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
     * Lista de textos breves, sin vacios y con tope.
     *
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
     * Condicion final. La ultima palabra la tiene esta regla fija y no la variacion del modelo:
     *
     *  - «No aprobado» solo con un motivo grave sostenido (uno de los cuatro, con cita de la ficha). Ni un item critico
     *    sin cumplir ni que el modelo lo proponga lo dejan asi sin motivo: pasa a «Aprobado con observaciones».
     *  - Un item «no cumple» (sin motivo grave) deja «Aprobado con observaciones».
     *  - «Aprobado» se acepta aunque haya items «parcial», si no hay ningun «no cumple» y el modelo no pidio observaciones.
     *
     * @param array $items id => ['estado' => ...].
     * @param string|null $modelo Condicion que propuso el modelo.
     * Con $estricto (interruptor «iaapoyo» apagado) vuelve la regla anterior a v1.20.6: un item critico que no se
     * cumple o un «No aprobado» del modelo dejan la ficha no aprobada, y algo parcial deja observaciones.
     *
     * @param array|null $motivo Motivo grave ya comprobado con valid_reason(); null si no hay.
     * @param bool $estricto Regla anterior.
     * @return string
     */
    public static function decide(array $items, ?string $modelo, ?array $motivo = null, bool $estricto = false): string {
        if ($estricto) {
            foreach (self::CRITICOS as $id) {
                if (($items[$id]['estado'] ?? self::NO_CUMPLE) === self::NO_CUMPLE) {
                    return self::NO_APROBADO;
                }
            }
            if ($modelo === self::NO_APROBADO) {
                return self::NO_APROBADO;
            }
            foreach ($items as $item) {
                if ($item['estado'] !== self::CUMPLE) {
                    return self::OBSERVACIONES;
                }
            }
            return $modelo === self::OBSERVACIONES ? self::OBSERVACIONES : self::APROBADO;
        }
        if ($motivo !== null && isset(self::serious_reasons()[$motivo['codigo'] ?? ''])) {
            return self::NO_APROBADO;
        }
        foreach ($items as $item) {
            if ($item['estado'] === self::NO_CUMPLE) {
                return self::OBSERVACIONES;
            }
        }
        // Un «no aprobado» del modelo sin motivo grave queda como observaciones: puede avanzar con sugerencias.
        return in_array($modelo, [self::OBSERVACIONES, self::NO_APROBADO], true) ? self::OBSERVACIONES : self::APROBADO;
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
     * Seccion del curso de una fase, por su nombre («Fase 2: Diseño...»).
     *
     * @param string[] $nombres numero de seccion => nombre.
     * @param int $fase
     * @return int|null Numero de seccion; null si ninguna se llama asi.
     */
    public static function phase_section(array $nombres, int $fase): ?int {
        foreach ($nombres as $num => $nombre) {
            $n = \core_text::strtolower(trim(strip_tags((string) $nombre)));
            if (preg_match('/^fase\s*' . $fase . '\b/u', $n)) {
                return (int) $num;
            }
        }
        return null;
    }

    /**
     * Texto de un .docx (Word): los parrafos de word/document.xml, sin formato.
     *
     * @param string $ruta Archivo en disco.
     * @return string|null null si no es un .docx legible.
     */
    public static function docx_text(string $ruta): ?string {
        if (!class_exists('\ZipArchive')) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($ruta) !== true) {
            return null;
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            return null;
        }
        $xml = preg_replace(['/<w:tab\/>/', '/<\/w:p>/', '/<w:br\/>/'], ["\t", "\n", "\n"], $xml);
        $texto = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $texto = trim(preg_replace("/\n{3,}/", "\n\n", $texto));
        return $texto === '' ? null : $texto;
    }

    // ------------------------------------------------------------------------------------------
    // Base de datos y archivos.
    // ------------------------------------------------------------------------------------------

    /**
     * La ficha del diseno en uso de la persona en el curso.
     *
     * Desde v1.20.0 una persona puede tener varios disenos en el mismo curso (pedido de Interfases del 26-10-2026:
     * el modulo se usa repetidamente para distintas experiencias). Cada diseno es una ficha con su validacion y sus
     * etapas; uno esta en uso (activa = 1) y es el que muestran todas las paginas.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|null Con data ya decodificada.
     */
    public static function get_ficha(int $userid, int $courseid): ?\stdClass {
        global $DB;
        $filas = $DB->get_records('local_chatbot_exante', ['userid' => $userid, 'courseid' => $courseid],
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
        $ficha = $DB->get_record('local_chatbot_exante', ['id' => $fichaid, 'userid' => $userid]);
        if (!$ficha) {
            return null;
        }
        $ficha->data = self::clean((array) json_decode((string) $ficha->data, true));
        return $ficha;
    }

    /**
     * Todos los disenos de la persona en el curso, el mas reciente primero.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass[]
     */
    public static function list_fichas(int $userid, int $courseid): array {
        global $DB;
        $fichas = $DB->get_records('local_chatbot_exante', ['userid' => $userid, 'courseid' => $courseid], 'id DESC');
        foreach ($fichas as $ficha) {
            $ficha->data = self::clean((array) json_decode((string) $ficha->data, true));
        }
        return $fichas;
    }

    /**
     * Guarda la ficha del diseno en uso (la crea si la persona no tiene ninguna).
     *
     * @param int $userid
     * @param int $courseid
     * @param array $data Ficha ya limpia.
     * @return int id de la ficha.
     */
    public static function save_ficha(int $userid, int $courseid, array $data): int {
        global $DB;
        $now = time();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $actual = self::get_ficha($userid, $courseid);
        if ($actual) {
            $DB->update_record('local_chatbot_exante', (object) ['id' => $actual->id, 'data' => $json,
                'activa' => 1, 'timemodified' => $now]);
            return (int) $actual->id;
        }
        return (int) $DB->insert_record('local_chatbot_exante', (object) ['userid' => $userid,
            'courseid' => $courseid, 'data' => $json, 'activa' => 1, 'timecreated' => $now, 'timemodified' => $now]);
    }

    /**
     * Deja en uso un diseno de la persona (los demas quedan guardados, sin borrarse).
     *
     * @param int $userid
     * @param int $courseid
     * @param int $fichaid
     * @return bool false si la ficha no es de la persona en ese curso.
     */
    public static function activate(int $userid, int $courseid, int $fichaid): bool {
        global $DB;
        if (!$DB->record_exists('local_chatbot_exante', ['id' => $fichaid, 'userid' => $userid, 'courseid' => $courseid])) {
            return false;
        }
        $DB->set_field('local_chatbot_exante', 'activa', 0, ['userid' => $userid, 'courseid' => $courseid]);
        $DB->set_field('local_chatbot_exante', 'activa', 1, ['id' => $fichaid]);
        return true;
    }

    /**
     * Diseno que queda en uso al borrar otro: si se borra el que estaba en uso, el mas reciente de los que quedan;
     * si se borra otro, el de uso no cambia. Funcion pura.
     *
     * @param int[] $ids Ids de los disenos de la persona en el curso, antes de borrar.
     * @param int $borrado
     * @param int $enuso Id del que estaba en uso (0 si ninguno).
     * @return int|null Id del que debe quedar en uso; null si no queda ninguno.
     */
    public static function next_in_use(array $ids, int $borrado, int $enuso): ?int {
        $quedan = array_values(array_filter(array_map('intval', $ids), static function($id) use ($borrado) {
            return $id !== $borrado;
        }));
        if (!$quedan) {
            return null;
        }
        if ($enuso !== $borrado && in_array($enuso, $quedan, true)) {
            return $enuso;
        }
        return max($quedan);
    }

    /**
     * Borra un diseno de la persona (uno solo): su ficha, sus validaciones, sus etapas, sus adjuntos y su
     * autorrevision. Los demas no se tocan. Si era el que estaba en uso, pasa a usarse el mas reciente de los que quedan.
     * No se borra mientras se esta validando: la validacion terminaria escribiendo sobre algo que ya no existe.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $fichaid
     * @return string DELETED, MISSING o VALIDATING.
     */
    public static function delete_ficha(int $userid, int $courseid, int $fichaid): string {
        global $DB;
        $ficha = $DB->get_record('local_chatbot_exante', ['id' => $fichaid, 'userid' => $userid, 'courseid' => $courseid]);
        if (!$ficha) {
            return self::MISSING;
        }
        $ultima = self::latest_validation_for($fichaid);
        if ($ultima && in_array($ultima->estado, [self::PENDIENTE, self::PROCESANDO], true)) {
            return self::VALIDATING;
        }
        $ids = array_keys($DB->get_records('local_chatbot_exante', ['userid' => $userid, 'courseid' => $courseid], '', 'id'));
        $siguiente = self::next_in_use($ids, $fichaid, (int) $ficha->activa === 1 ? $fichaid : 0);

        $tx = $DB->start_delegated_transaction();
        $DB->delete_records('local_chatbot_diseno', ['fichaid' => $fichaid]);
        $DB->delete_records('local_chatbot_exante_val', ['fichaid' => $fichaid]);
        $DB->delete_records('local_chatbot_exante', ['id' => $fichaid]);
        $tx->allow_commit();

        foreach (array_keys(self::ADJUNTOS) as $area) {
            self::set_attachment($fichaid, $area, null);
        }
        unset_user_preference('local_chatbot_autocheck_' . $courseid . '_' . $fichaid, $userid);
        if ($siguiente !== null && (int) $ficha->activa === 1) {
            self::activate($userid, $courseid, $siguiente);
        }
        return self::DELETED;
    }

    /**
     * Empieza un diseno nuevo y lo deja en uso. Con $copiar, parte de los datos y adjuntos del diseno en uso (mismo
     * organismo, grupo y contexto), sin su validacion ni sus etapas, que son de cada experiencia.
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
        $DB->set_field('local_chatbot_exante', 'activa', 0, ['userid' => $userid, 'courseid' => $courseid]);
        $id = (int) $DB->insert_record('local_chatbot_exante', (object) ['userid' => $userid, 'courseid' => $courseid,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'activa' => 1, 'timecreated' => $now,
            'timemodified' => $now]);
        if ($base) {
            $fs = get_file_storage();
            foreach (self::attachments((int) $base->id) as $area => $archivo) {
                $fs->create_file_from_storedfile(['itemid' => $id], $archivo);
            }
        }
        return $id;
    }

    /**
     * Nombre con que se reconoce un diseno en la lista: el de la experiencia si ya lo tiene (etapa 2), si no el
     * aprendizaje esperado o el plan formativo. Funcion pura.
     *
     * @param array $data Ficha ya limpia.
     * @param string $experiencia Nombre de la experiencia (vacio si aun no hay etapa 2).
     * @return string
     */
    public static function design_label(array $data, string $experiencia = ''): string {
        foreach ([$experiencia, (string) ($data['aprendizaje'] ?? ''), (string) ($data['plan'] ?? '')] as $texto) {
            $texto = trim(preg_replace('/\s+/u', ' ', $texto));
            if ($texto !== '') {
                return \core_text::strlen($texto) > 90 ? rtrim(\core_text::substr($texto, 0, 87)) . '…' : $texto;
            }
        }
        return '';
    }

    /**
     * Archivos adjuntos de una ficha.
     *
     * @param int $fichaid
     * @return \stored_file[] area => archivo.
     */
    public static function attachments(int $fichaid): array {
        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;
        $salida = [];
        foreach (array_keys(self::ADJUNTOS) as $area) {
            $archivos = $fs->get_area_files($contextid, 'local_chatbot', $area, $fichaid, 'id', false);
            if ($archivos) {
                $salida[$area] = reset($archivos);
            }
        }
        return $salida;
    }

    /**
     * Reemplaza (o quita, con $ruta null) el archivo de un area.
     *
     * @param int $fichaid
     * @param string $area
     * @param string|null $ruta
     * @param string $nombre
     * @return void
     */
    public static function set_attachment(int $fichaid, string $area, ?string $ruta, string $nombre = ''): void {
        if (!isset(self::ADJUNTOS[$area])) {
            return;
        }
        $fs = get_file_storage();
        $contextid = \context_system::instance()->id;
        $fs->delete_area_files($contextid, 'local_chatbot', $area, $fichaid);
        if ($ruta !== null) {
            $fs->create_file_from_pathname(['contextid' => $contextid, 'component' => 'local_chatbot',
                'filearea' => $area, 'itemid' => $fichaid, 'filepath' => '/',
                'filename' => clean_param($nombre, PARAM_FILE) ?: 'documento'], $ruta);
        }
    }

    /**
     * Tipo de archivo admitido como adjunto, por su contenido real.
     *
     * @param string $mime El que detecta el servidor, no el que declara el navegador.
     * @param string $nombre
     * @return string|null 'pdf', 'docx' o null si no se admite.
     */
    public static function attachment_kind(string $mime, string $nombre): ?string {
        $ext = \core_text::strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        if ($mime === 'application/pdf' && $ext === 'pdf') {
            return 'pdf';
        }
        // Segun la version de libmagic, un .docx se detecta como zip, como Word o sin tipo: quien sube revisa
        // ademas que docx_text() lo pueda leer.
        if ($ext === 'docx' && in_array($mime, ['application/zip', 'application/octet-stream',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
            return 'docx';
        }
        return null;
    }

    /**
     * Huella de la ficha guardada con sus adjuntos.
     *
     * @param \stdClass $ficha
     * @return string
     */
    public static function ficha_fingerprint(\stdClass $ficha): string {
        $huellas = [];
        foreach (self::attachments((int) $ficha->id) as $area => $archivo) {
            $huellas[$area] = $archivo->get_contenthash();
        }
        return self::fingerprint($ficha->data, $huellas);
    }

    /**
     * Deja una validacion pendiente; si ya hay una en curso para la ficha, devuelve esa.
     *
     * @param \stdClass $ficha
     * @return int id de la validacion.
     */
    public static function start_validation(\stdClass $ficha): int {
        global $DB;
        $encurso = $DB->get_records_select('local_chatbot_exante_val',
            'fichaid = ? AND estado IN (?, ?)', [$ficha->id, self::PENDIENTE, self::PROCESANDO], 'id DESC', 'id', 0, 1);
        if ($encurso) {
            return (int) reset($encurso)->id;
        }
        $now = time();
        return (int) $DB->insert_record('local_chatbot_exante_val', (object) [
            'fichaid' => $ficha->id, 'userid' => $ficha->userid, 'courseid' => $ficha->courseid,
            'estado' => self::PENDIENTE, 'huella' => self::ficha_fingerprint($ficha),
            'snapshot' => json_encode($ficha->data, JSON_UNESCAPED_UNICODE),
            'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    /**
     * Ultima validacion del diseno en uso de la persona en el curso.
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
        $filas = $DB->get_records('local_chatbot_exante_val', ['fichaid' => $fichaid], 'id DESC', '*', 0, 1);
        if (!$filas) {
            return null;
        }
        $val = reset($filas);
        if ($val->estado === self::PROCESANDO && $val->timemodified < time() - self::PROCESO_MAX) {
            $val->estado = self::ERROR;
            $val->error = 'tiempo';
            $DB->update_record('local_chatbot_exante_val', (object) ['id' => $val->id, 'estado' => $val->estado,
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
        $factory = \core\lock\lock_config::get_lock_factory('local_chatbot_exante');
        $candado = $factory->get_lock('val' . $valid, 0);
        if (!$candado) {
            return self::PROCESANDO;
        }
        try {
            $val = $DB->get_record('local_chatbot_exante_val', ['id' => $valid, 'userid' => $userid]);
            if (!$val || $val->estado !== self::PENDIENTE) {
                return $val ? $val->estado : self::ERROR;
            }
            $DB->update_record('local_chatbot_exante_val', (object) ['id' => $valid,
                'estado' => self::PROCESANDO, 'timemodified' => time()]);

            [$estado, $campos] = self::validate($val);
            $DB->update_record('local_chatbot_exante_val', (object) (['id' => $valid, 'estado' => $estado,
                'timemodified' => time()] + $campos));
            return $estado;
        } finally {
            $candado->release();
        }
    }

    /**
     * Los adjuntos de una ficha como partes del pedido al modelo: el PDF viaja tal cual y del .docx va el texto.
     * Lo usan la validacion (Fase 1) y las etapas de diseno (Fase 2).
     *
     * @param int $fichaid
     * @return array [partes, etiquetas (string[]), etiqueta => nombre de archivo].
     */
    public static function attachment_parts(int $fichaid): array {
        $partes = [];
        $nombres = [];
        foreach (self::attachments($fichaid) as $area => $archivo) {
            $etiqueta = self::ADJUNTOS[$area];
            $nombres[$etiqueta] = $archivo->get_filename();
            $aviso = guardrails::attachment_notice() . "\nDocumento complementario: " . $etiqueta . '.';
            if (self::attachment_kind($archivo->get_mimetype(), $archivo->get_filename()) === 'pdf') {
                $partes[] = ['text' => $aviso];
                $partes[] = ['inlineData' => ['mimeType' => 'application/pdf',
                    'data' => base64_encode($archivo->get_content())]];
                continue;
            }
            $ruta = $archivo->copy_content_to_temp();
            $texto = $ruta ? self::docx_text($ruta) : null;
            if ($ruta) {
                @unlink($ruta);
            }
            if ($texto !== null) {
                $partes[] = ['text' => $aviso . "\n" . guardrails::isolate_user_content(
                    \core_text::substr(pseudonymizer::scrub($texto), 0, 60000))];
            }
        }
        return [$partes, array_keys($nombres), $nombres];
    }

    /**
     * La validacion que habilita la Fase 2: la ultima, lista, Aprobada o Aprobada con observaciones y de la ficha
     * tal como esta hoy.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function fase1_ok(int $userid, int $courseid): ?\stdClass {
        $ficha = self::get_ficha($userid, $courseid);
        $val = self::latest_validation($userid, $courseid);
        // Una validacion anterior a la exigencia de 3 criterios tampoco habilita el diseno.
        if (!$ficha || !$val || $val->estado !== self::LISTO || !self::can_advance($val->condicion)
                || $val->huella !== self::ficha_fingerprint($ficha) || self::criteria_count($ficha->data) < self::CRITERIOS_MIN) {
            return null;
        }
        // Prompt Maestro: «Este asistente NO trabaja sin ficha y Plan Formativo».
        if (!isset(self::attachments((int) $ficha->id)['exante_plan'])) {
            return null;
        }
        return $val;
    }

    /**
     * Pide una respuesta al modelo con temperatura baja: la misma ficha debe dar el mismo resultado. Si el modelo no
     * admite el parametro, se repite el pedido sin el.
     *
     * @param array $contenido Contenido canonico del pedido.
     * @param int $timeout Segundos.
     * @param string|null $modelo
     * @return array Respuesta con forma de Gemini, o ['error' => ...].
     */
    public static function generate_steady(array $contenido, int $timeout, ?string $modelo, bool $buscar = false): array {
        $proveedor = ai\provider_factory::get();
        $resultado = $proveedor->generate_content($contenido, $timeout, 2, $modelo, $buscar, self::TEMPERATURA);
        if (isset($resultado['error']) && stripos((string) json_encode($resultado['error']), 'temperature') !== false) {
            $resultado = $proveedor->generate_content($contenido, $timeout, 2, $modelo, $buscar);
        }
        // Si el modelo rechaza la busqueda web (pedido invalido), se repite sin ella.
        if ($buscar && isset($resultado['error']) && (int) ($resultado['error']['code'] ?? 0) === 400) {
            $resultado = $proveedor->generate_content($contenido, $timeout, 2, $modelo, false, self::TEMPERATURA);
        }
        return $resultado;
    }

    /**
     * Llama al modelo con la ficha de la validacion y sus adjuntos.
     *
     * @param \stdClass $val
     * @return array [estado, campos a guardar].
     */
    private static function validate(\stdClass $val): array {
        if (!ai\provider_factory::is_configured()) {
            return [self::ERROR, ['error' => 'sinclave']];
        }
        $data = self::clean((array) json_decode((string) $val->snapshot, true));

        [$partes, , $nombres] = self::attachment_parts((int) $val->fichaid);

        $fichatexto = pseudonymizer::scrub(self::to_text($data));
        // Libreria del rol para el enfoque de competencias y busqueda web para la especialidad tecnica (30-10-2026).
        $libreria = self::role_library();
        $buscar = self::web_search_available();
        $prompt = self::build_prompt(array_keys($nombres), $libreria !== '', $buscar) . "\n\nFICHA DE DIAGNÓSTICO:\n"
            . guardrails::isolate_user_content($fichatexto);
        $contenido = [['role' => 'user', 'parts' => array_merge([['text' => $prompt]], $partes, self::library_parts($libreria))]];

        $modelo = ai\provider_factory::get_configured_model();
        $informe = null;
        $fuentes = [];
        // Un segundo intento solo si la respuesta no se pudo leer: el checklist va entero o no va. El segundo va sin
        // busqueda web, por si la respuesta con fuentes no vino como JSON limpio.
        for ($intento = 0; $intento < 2 && $informe === null; $intento++) {
            $resultado = self::generate_steady($contenido, 150, $modelo, $buscar && $intento === 0);
            if (isset($resultado['error'])) {
                return [self::ERROR, ['error' => 'servicio']];
            }
            $informe = self::parse_report(gemini_client::extract_text($resultado));
            $fuentes = gemini_client::extract_sources($resultado);
        }
        if ($informe === null) {
            return [self::ERROR, ['error' => 'ilegible']];
        }

        $informe['adjuntos'] = $nombres;
        $informe['fuentes_web'] = array_slice($fuentes, 0, 8);
        $informe['libreria'] = $libreria !== '' ? kb_store::context_version(kb_store::AMBITO_M2) : '';
        // Un motivo grave solo cuenta si es uno de los cuatro y su cita esta en la ficha que vio el modelo.
        $motivo = self::valid_reason($informe['motivo'], $fichatexto, $informe['condicion_modelo'],
            isset($nombres[self::ADJUNTOS['exante_plan']]));
        $informe['motivo'] = $motivo;
        $condicion = self::decide($informe['items'], $informe['condicion_modelo'], $motivo, !ux::on('iaapoyo'));
        return [self::LISTO, [
            'condicion' => $condicion,
            'informe' => json_encode($informe, JSON_UNESCAPED_UNICODE),
            'modelo' => (string) $modelo,
            'promptversion' => self::PROMPT_VERSION,
            'error' => null,
        ]];
    }
}
