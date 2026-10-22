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
    const PROMPT_VERSION = 'M2-F1-checklist-v1';

    /** Tipos de campo de la ficha. */
    const T_TEXTO = 'texto';
    const T_PARRAFO = 'parrafo';
    const T_NUMERO = 'numero';
    const T_UNA = 'una';
    const T_VARIAS = 'varias';
    const T_VALIDACION = 'validacion';

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

    /** @var int Una validacion en proceso por mas de esto se da por caida. */
    const PROCESO_MAX = 360;

    /** @var int Tamano maximo de cada archivo adjunto. */
    const ADJUNTO_MAX = 10485760;

    /** @var string[] Archivos adjuntos opcionales: area de archivos => etiqueta. */
    const ADJUNTOS = [
        'exante_plan' => 'Plan Formativo',
        'exante_perfil' => 'Perfil Ocupacional',
    ];

    /** @var string[] Items criticos: si alguno no se cumple, la ficha no se aprueba. */
    const CRITICOS = ['c2_1', 'c2_2', 'c2_3', 'c2_5', 'c9_2'];

    /** @var int Largo maximo de cada tipo de texto. */
    const LARGO_TEXTO = 255;
    const LARGO_PARRAFO = 4000;

    /**
     * Secciones de la ficha, con el texto del documento del cliente.
     *
     * Cada campo: id, tipo, num (numeracion de la ficha), label, ayuda (de la guia de llenado),
     * opciones (clave => texto) y otro (clave de la opcion que abre un campo de texto «id_otro»).
     *
     * @return array
     */
    public static function sections(): array {
        $mo = ['bajo' => 'Bajo', 'medio' => 'Medio', 'alto' => 'Alto'];
        return [
            1 => ['titulo' => 'Identificación general', 'campos' => [
                ['id' => 'organismo', 'tipo' => self::T_TEXTO, 'label' => 'Organismo ejecutor de capacitación'],
                ['id' => 'plan', 'tipo' => self::T_TEXTO, 'label' => 'Plan formativo'],
                ['id' => 'nivel', 'tipo' => self::T_TEXTO, 'label' => 'Nivel de cualificación',
                    'ayuda' => 'Según el Marco Nacional de Cualificaciones.'],
                ['id' => 'facilitador', 'tipo' => self::T_TEXTO, 'label' => 'Nombre del facilitador o facilitadora'],
                ['id' => 'fechas', 'tipo' => self::T_TEXTO, 'label' => 'Fecha(s)',
                    'ayuda' => 'Fecha(s) de aplicación del diagnóstico.'],
                ['id' => 'participantes', 'tipo' => self::T_NUMERO, 'label' => 'Nº de participantes',
                    'ayuda' => 'Total de participantes matriculados en el curso.'],
            ]],
            2 => ['titulo' => 'Aprendizaje esperado y criterios de evaluación del plan formativo', 'campos' => [
                ['id' => 'aprendizaje', 'tipo' => self::T_PARRAFO, 'num' => '2.1', 'label' => 'Aprendizaje esperado',
                    'ayuda' => 'Transcriba el aprendizaje esperado completo tal como está formulado en el plan formativo.'],
                ['id' => 'criterio1', 'tipo' => self::T_PARRAFO, 'label' => 'Criterio de evaluación 1',
                    'ayuda' => 'Registre los criterios de evaluación tal como están definidos en el plan formativo. '
                        . 'Si el aprendizaje esperado tiene menos de cinco, deje en blanco los espacios restantes.'],
                ['id' => 'criterio2', 'tipo' => self::T_PARRAFO, 'label' => 'Criterio de evaluación 2'],
                ['id' => 'criterio3', 'tipo' => self::T_PARRAFO, 'label' => 'Criterio de evaluación 3'],
                ['id' => 'criterio4', 'tipo' => self::T_PARRAFO, 'label' => 'Criterio de evaluación 4'],
                ['id' => 'criterio5', 'tipo' => self::T_PARRAFO, 'label' => 'Criterio de evaluación 5'],
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
                        . 'en el plan formativo para el aprendizaje esperado.'],
            ]],
            3 => ['titulo' => 'Perfil ocupacional y plan formativo', 'campos' => [
                ['id' => 'vinculo', 'tipo' => self::T_UNA, 'num' => '3.1',
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
                ['id' => 'obs3', 'tipo' => self::T_PARRAFO, 'label' => 'Observaciones breves (opcional):'],
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
                ['id' => 'obs4', 'tipo' => self::T_PARRAFO, 'label' => 'Observaciones breves (opcional):'],
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
                ['id' => 'obs5', 'tipo' => self::T_PARRAFO, 'label' => 'Observaciones breves (opcional):'],
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
                ['id' => 'obs6', 'tipo' => self::T_PARRAFO, 'label' => 'Observaciones breves (opcional):'],
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
                ['id' => 'obs7', 'tipo' => self::T_PARRAFO, 'label' => 'Observaciones breves (opcional):'],
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
                    'label' => 'Observaciones finales (una línea por criterio relevante):'],
            ]],
            9 => ['titulo' => 'Validación', 'campos' => [
                ['id' => 'val', 'tipo' => self::T_VALIDACION, 'label' => 'Aspecto',
                    'filas' => ['revision' => 'Revisión del diagnóstico',
                        'equipo' => 'Validación con equipo de facilitadores del Plan Formativo',
                        'aprobacion' => 'Aprobación final']],
            ]],
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
     * Claves que se guardan de la ficha: los campos, sus «_otro» y las celdas de la tabla de validacion.
     *
     * @return array clave => tipo de dato (texto, parrafo, numero, una, varias).
     */
    public static function field_keys(): array {
        $claves = [];
        foreach (self::sections() as $seccion) {
            foreach ($seccion['campos'] as $campo) {
                if ($campo['tipo'] === self::T_VALIDACION) {
                    foreach (array_keys($campo['filas']) as $fila) {
                        $claves[$campo['id'] . '_' . $fila . '_fecha'] = self::T_TEXTO;
                        $claves[$campo['id'] . '_' . $fila . '_responsable'] = self::T_TEXTO;
                    }
                    continue;
                }
                $claves[$campo['id']] = $campo['tipo'];
                if (!empty($campo['otro'])) {
                    $claves[$campo['id'] . '_otro'] = self::T_TEXTO;
                }
            }
        }
        return $claves;
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
     * Control de entrada del Prompt Maestro: sin esto no se valida.
     *
     * Pide aprendizaje esperado, al menos un criterio, la informacion del contexto (seccion 5) y las
     * caracteristicas de los participantes (seccion 4). El resto lo juzga el checklist.
     *
     * @param array $data Ficha ya limpia.
     * @return string[] Lo que falta, con el texto de la ficha.
     */
    public static function missing_inputs(array $data): array {
        $falta = [];
        if (self::vacio($data['aprendizaje'] ?? '')) {
            $falta[] = '2.1 Aprendizaje esperado';
        }
        if (self::criteria_count($data) === 0) {
            $falta[] = '2.1 Criterios de evaluación (al menos uno)';
        }
        foreach (['dominio', 'heterogeneidad', 'necesidades', 'participacion',
                'contexto', 'estrategia_didactica', 'pertinencia_contexto', 'situaciones'] as $id) {
            if (self::vacio($data[$id] ?? '')) {
                $campo = self::field($id);
                $falta[] = $campo['num'] . ' ' . rtrim($campo['label'], ':');
            }
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
        for ($i = 1; $i <= 5; $i++) {
            if (!self::vacio($data['criterio' . $i] ?? '')) {
                $n++;
            }
        }
        return $n;
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
                $rotulo = (!empty($campo['num']) ? $campo['num'] . '. ' : '') . rtrim($campo['label'], ':');
                switch ($campo['tipo']) {
                    case self::T_VALIDACION:
                        foreach ($campo['filas'] as $fila => $texto) {
                            $fecha = trim((string) ($data[$id . '_' . $fila . '_fecha'] ?? ''));
                            $resp = trim((string) ($data[$id . '_' . $fila . '_responsable'] ?? ''));
                            $lineas[] = '- ' . $texto . ': fecha ' . ($fecha !== '' ? $fecha : $sin)
                                . '; responsable ' . ($resp !== '' ? $resp : $sin);
                        }
                        continue 2;
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
                            $valor = $sin;
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
     * Instrucciones de validacion: Etapa 0 del Prompt Maestro con el checklist completo.
     *
     * @param string[] $adjuntos Etiquetas de los documentos complementarios que se adjuntaron.
     * @return string
     */
    public static function build_prompt(array $adjuntos): string {
        $checklist = [];
        foreach (self::checklist() as $n => $seccion) {
            $checklist[] = $n . '. ' . \core_text::strtoupper($seccion['titulo']);
            foreach ($seccion['items'] as $id => $texto) {
                $checklist[] = '   ' . $id . ': ' . $texto;
            }
        }
        $complementarios = $adjuntos
            ? 'La persona adjuntó: ' . implode(' y ', $adjuntos) . '. Úsalos solo para validar coherencia, '
                . 'profundidad y alineación; ante una diferencia, prioriza la ficha y señálalo.'
            : 'La persona no adjuntó el Plan Formativo ni el Perfil Ocupacional. Continúa con la ficha y señala '
                . 'en la observación cuando un ítem podría beneficiarse de esos documentos.';
        $faltan = array_diff(['Plan Formativo', 'Perfil Ocupacional'], $adjuntos);
        if ($adjuntos && $faltan) {
            $complementarios .= ' No adjuntó: ' . implode(' ni ', $faltan) . '.';
        }

        return implode("\n", [
            'Eres un asistente experto en diseño instruccional con enfoque de competencias, especializado en '
                . 'formación de personas adultas en contextos de capacitación laboral. Apoyas a un equipo de '
                . 'capacitación en la Fase 1 (Diagnóstico y Validación) del diseño de experiencias de aprendizaje.',
            '',
            'TAREA: aplica la ETAPA 0, VALIDACIÓN DEL DIAGNÓSTICO, a la ficha de diagnóstico (evaluación ex-ante) '
                . 'que viene más abajo. Aplica el checklist completo, sin omitir secciones ni ítems. No construyas '
                . 'ni completes la ficha, no diseñes la experiencia de aprendizaje y no avances a otras etapas: solo '
                . 'evalúas lo que la persona registró. Tu comportamiento es estricto, estructurado y orientado a '
                . 'asegurar una calidad mínima antes de avanzar.',
            '',
            'Evalúa cada ítem con uno de estos valores: "cumple" (✔ Cumple), "parcial" (⚠ Cumple parcialmente) o '
                . '"no_cumple" (❌ No cumple). Agrega una observación breve y específica, que se refiera a lo '
                . 'registrado en la ficha; es obligatoria cuando el ítem no es "cumple".',
            '',
            'REGLAS DE GOBERNANZA:',
            '- La ficha es la fuente principal. ' . $complementarios,
            '- No inventes aprendizajes esperados ni criterios de evaluación.',
            '- El diseño trabajará con no menos de 3 ni más de 5 criterios de evaluación: si la ficha registra '
                . 'menos de 3, indícalo.',
            '- Señala si el aprendizaje esperado o los criterios usan conceptos que remiten a juicios de valor, '
                . 'como "adecuadamente" u otros.',
            '- El tiempo asignado a la experiencia (2.3) debe ser menor que el tiempo total disponible (2.2).',
            '- Una opción "Otro" u "Otra" marcada debe venir especificada.',
            '- Detecta vacíos de información y repórtalos. Propón soluciones viables sin alterar el plan formativo.',
            '- Usa terminología de formación por competencias y lenguaje inclusivo con perspectiva de género.',
            '- Evalúas la ficha, no a la persona: no asignes notas ni puntajes.',
            '',
            'CHECKLIST (id: ítem):',
            implode("\n", $checklist),
            '',
            'REGLA DE DECISIÓN:',
            '- Si hay incumplimientos críticos en el aprendizaje esperado, en los criterios de evaluación o en la '
                . 'coherencia general: "no_aprobado" (debe corregirse antes de diseñar).',
            '- "aprobado": el diagnóstico es suficiente para diseñar.',
            '- "observaciones": aprobado con observaciones; puede avanzar, pero requiere ajustes menores.',
            '',
            guardrails::injection_clause(),
            '',
            'FORMATO DE RESPUESTA: únicamente un objeto JSON válido, sin texto antes ni después y sin bloques de '
                . 'código, con esta forma:',
            '{"items": {"c1_1": {"estado": "cumple|parcial|no_cumple", "observacion": "..."}, ... un elemento por '
                . 'cada id del checklist ...}, "condicion": "aprobado|observaciones|no_aprobado", '
                . '"debilidades": ["principales debilidades, máximo 6"], '
                . '"sugerencias": ["sugerencias de mejora concretas para la ficha, máximo 6"]}',
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
     * @return array cumple, parcial, no_cumple, sin_marcar (int), condicion (string|null), criticos (ids criticos en ❌).
     */
    public static function self_check_summary(array $marcas): array {
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
            'condicion' => count($marcas) === $total ? self::decide($items, null) : null,
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
            ];
        }

        return [
            'items' => $items,
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
     * Condicion final: la mas estricta entre la del modelo y la regla del checklist.
     *
     * Un item critico que no se cumple deja la ficha no aprobada. Con todo cumplido, manda el modelo
     * (puede ser mas exigente, nunca mas laxo). Con algo parcial o no cumplido, a lo mas «Aprobado con
     * observaciones».
     *
     * @param array $items id => ['estado' => ...].
     * @param string|null $modelo Condicion que propuso el modelo.
     * @return string
     */
    public static function decide(array $items, ?string $modelo): string {
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
     * La ficha de la persona en el curso.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|null Con data ya decodificada.
     */
    public static function get_ficha(int $userid, int $courseid): ?\stdClass {
        global $DB;
        $ficha = $DB->get_record('local_chatbot_exante', ['userid' => $userid, 'courseid' => $courseid]);
        if (!$ficha) {
            return null;
        }
        $ficha->data = self::clean((array) json_decode((string) $ficha->data, true));
        return $ficha;
    }

    /**
     * Guarda la ficha (una por persona y curso).
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
        $id = $DB->get_field('local_chatbot_exante', 'id', ['userid' => $userid, 'courseid' => $courseid]);
        if ($id) {
            $DB->update_record('local_chatbot_exante', (object) ['id' => $id, 'data' => $json, 'timemodified' => $now]);
            return (int) $id;
        }
        return (int) $DB->insert_record('local_chatbot_exante', (object) ['userid' => $userid,
            'courseid' => $courseid, 'data' => $json, 'timecreated' => $now, 'timemodified' => $now]);
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
     * Ultima validacion de la persona en el curso. Una que quedo procesando demasiado se da por caida.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|null Con informe decodificado.
     */
    public static function latest_validation(int $userid, int $courseid): ?\stdClass {
        global $DB;
        $filas = $DB->get_records('local_chatbot_exante_val', ['userid' => $userid, 'courseid' => $courseid],
            'id DESC', '*', 0, 1);
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
        if (!$ficha || !$val || $val->estado !== self::LISTO || !self::can_advance($val->condicion)
                || $val->huella !== self::ficha_fingerprint($ficha)) {
            return null;
        }
        return $val;
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

        $prompt = self::build_prompt(array_keys($nombres)) . "\n\nFICHA DE DIAGNÓSTICO:\n"
            . guardrails::isolate_user_content(pseudonymizer::scrub(self::to_text($data)));
        $contenido = [['role' => 'user', 'parts' => array_merge([['text' => $prompt]], $partes)]];

        $modelo = ai\provider_factory::get_configured_model();
        $informe = null;
        // Un segundo intento solo si la respuesta no se pudo leer: el checklist va entero o no va.
        for ($intento = 0; $intento < 2 && $informe === null; $intento++) {
            $resultado = ai\provider_factory::get()->generate_content($contenido, 150, 2, $modelo);
            if (isset($resultado['error'])) {
                return [self::ERROR, ['error' => 'servicio']];
            }
            $informe = self::parse_report(gemini_client::extract_text($resultado));
        }
        if ($informe === null) {
            return [self::ERROR, ['error' => 'ilegible']];
        }

        $informe['adjuntos'] = $nombres;
        $condicion = self::decide($informe['items'], $informe['condicion_modelo']);
        return [self::LISTO, [
            'condicion' => $condicion,
            'informe' => json_encode($informe, JSON_UNESCAPED_UNICODE),
            'modelo' => (string) $modelo,
            'promptversion' => self::PROMPT_VERSION,
            'error' => null,
        ]];
    }
}
