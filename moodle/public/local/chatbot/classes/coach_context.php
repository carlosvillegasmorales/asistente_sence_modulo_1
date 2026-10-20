<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Contrato de datos de entrada del Modo B, Coach (RU-COA-DATA-001).
 *
 * Doc 3 §10.1 define un objeto preprocesado y recomienda usarlo "tal cual como
 * schema de integracion". Hasta M1-v1.1.0 el Coach recibia un bloque de texto
 * armado a mano. Eso cumplia la parte de privacidad —nunca llevo respuestas
 * crudas— pero no el contrato: faltaban la completitud, el estado de la
 * matriz, los patrones relevantes y el catalogo de apoyos. Sin completitud el
 * Coach no puede saber que una dimension es preliminar, y sin catalogo solo
 * puede recomendar recursos inventados.
 *
 * Las claves siguen literalmente el schema del documento. Lo que el plugin
 * agrega por su cuenta va aparte, bajo reglas_sistema, para que quien integre
 * contra Doc 3 no tenga que distinguir campo por campo que es del documento y
 * que no.
 *
 * Nada de aqui identifica a la persona: viaja el seudonimo, y el texto libre
 * del perfil se depura de correos y RUT antes de entrar al objeto.
 */
class coach_context {

    /** @var string Matriz recien calculada, sin plan validado todavia. */
    const MATRIX_INICIAL = 'inicial';

    /** @var string Matriz con un PDP ya validado y en curso. */
    const MATRIX_SEGUIMIENTO = 'en_seguimiento';

    /**
     * @var string[] Modulos del curso que no son recursos para la persona: una etiqueta
     * es texto de la pagina y el banco de preguntas es infraestructura del quiz. En la
     * prueba del 21-09-2026 el Coach recomendaba una etiqueta como "Recurso".
     */
    const NOT_A_RESOURCE = ['label', 'qbank', 'subsection'];

    /**
     * Arma el objeto de datos completo para el Coach.
     *
     * @param int $userid
     * @param int $courseid
     * @return array Estructura del contrato de Doc 3 §10.1, mas reglas_sistema.
     */
    public static function build(int $userid, int $courseid): array {
        $matrix = diagnostic::get_gap_matrix($userid, $courseid);
        $profile = profile::get($userid, $courseid);
        $catalog = self::catalog($userid, $courseid);

        $resultados = [];
        $discrepancias = [];
        foreach ($matrix as $row) {
            $resultados[$row['code']] = [
                'dimension' => $row['name'],
                'puntaje_declarado' => $row['pd'],
                'puntaje_situacional' => $row['cs'],
                'puntaje_compuesto' => $row['pc'],
                'nivel' => diagnostic::level_labels_plain()[$row['nivel']],
                'completitud' => $row['completitud'],
                'preliminar' => $row['preliminar'],
                // Doc 3 trae subdimensiones (D1.1, D1.2...). El instrumento
                // cargado no las tiene todavia: el objeto viaja vacio en vez de
                // omitir la clave, para que el schema siga siendo el mismo el
                // dia que se carguen.
                'subdimensiones' => (object) [],
            ];
            if (diagnostic::is_relevant_discrepancy($row['discrepancia'])) {
                $discrepancias[] = [
                    'dimension' => $row['code'],
                    'diferencia' => $row['discrepancia'],
                    'sentido' => $row['discrepancia'] > 0 ? 'declara_mas_de_lo_que_resuelve'
                        : 'resuelve_mas_de_lo_que_declara',
                ];
            }
        }

        $answered = array_column($matrix, 'code');
        $omisiones = [];
        foreach (diagnostic::list_dimensions($courseid) as $dimension) {
            if (!in_array($dimension->code, $answered, true)) {
                $omisiones[] = $dimension->code;
            }
        }

        $candidates = diagnostic::rank_priorities($matrix);
        $limit = diagnostic::priority_limit($matrix);

        return [
            'user_id_pseudonimizado' => pseudonymizer::for_user($userid),
            'diagnostico_version' => diagnostic::instrument_version(),
            'completitud' => self::global_completeness($userid, $courseid),
            'estado_matriz' => pdp::has_active_plan($userid, $courseid)
                ? self::MATRIX_SEGUIMIENTO : self::MATRIX_INICIAL,
            'contexto_autorizado' => self::authorized_context($profile),
            'resultados' => (object) $resultados,
            'patrones_relevantes' => [
                'discrepancias' => $discrepancias,
                'omisiones' => $omisiones,
                'resultados_preliminares' => in_array(true, array_column($matrix, 'preliminar'), true),
            ],
            // El diagnostico cargado no tiene preguntas abiertas. Las claves van
            // en null, no inventadas a partir de otro campo: cuando Doc 3 las
            // traiga al instrumento, se llenan aqui.
            'respuestas_abiertas_autorizadas' => [
                'desafio_actual' => null,
                'capacidades_prioritarias' => null,
            ],
            'catalogo_apoyos_activos' => array_column($catalog, 'nombre'),
            'reglas_sistema' => [
                'prioridades_candidatas' => array_column($candidates, 'code'),
                'limite_prioridades' => $limit,
                'escala' => diagnostic::SCORE_MIN . ' a ' . diagnostic::SCORE_MAX,
            ],
        ];
    }

    /**
     * El objeto como bloque de prompt.
     *
     * @param int $userid
     * @param int $courseid
     * @return string Vacio si la persona todavia no aplica el diagnostico.
     */
    public static function as_prompt_context(int $userid, int $courseid): string {
        if (empty(diagnostic::get_gap_matrix($userid, $courseid))) {
            return '';
        }

        $json = json_encode(self::build($userid, $courseid),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return "OBJETO DE DATOS AUTORIZADO DEL COACH (Doc 3, 10.1):\n" . $json . "\n"
            . "Lee reglas_sistema como restricciones, no como sugerencias: prioridades_candidatas"
            . " viene ya ordenada por menor puntaje compuesto y discrepancia relevante, y"
            . " limite_prioridades es el maximo que puedes proponer. Una dimension con"
            . " preliminar = true no admite conclusiones ni metas.";
    }

    /**
     * Contexto autorizado del perfil, con el texto libre depurado.
     *
     * @param \stdClass|false $profile
     * @return array
     */
    public static function authorized_context($profile): array {
        if (!$profile) {
            return ['rol' => null, 'tipo_programa' => null, 'modalidad' => null,
                'sector' => null, 'tiempo_disponible' => null, 'prioridades_declaradas' => []];
        }

        // El perfil en palabras (profile::describe), con todo texto libre depurado de correos y RUT.
        $p = profile::describe($profile);
        $limpio = static function (?string $texto): ?string {
            return $texto === null ? null : (trim(pseudonymizer::scrub($texto)) ?: null);
        };

        return [
            'rol' => $limpio($p['rol']),
            'tipo_programa' => $limpio($p['programa']),
            'modalidad' => $p['modalidad'],
            'sector' => $limpio($p['sector']),
            'tiempo_disponible' => $p['tiempo'],
            'prioridades_declaradas' => array_values(array_filter(array_map($limpio, $p['fortalecer']))),
            'experiencia' => $p['experiencia'],
            'familiaridad_competencias' => $p['familiaridad'],
            'curso_donde_aplicara' => $limpio($p['aplicacion']),
        ];
    }

    /**
     * Completitud global del diagnostico de entrada, en porcentaje.
     *
     * @param int $userid
     * @param int $courseid
     * @return int
     */
    private static function global_completeness(int $userid, int $courseid): int {
        global $DB;

        $total = count(diagnostic::list_items($courseid));
        if ($total === 0) {
            return 0;
        }
        $answered = $DB->count_records('local_chatbot_diag_response',
            ['userid' => $userid, 'courseid' => $courseid, 'stage' => diagnostic::STAGE_ENTRADA]);

        return (int) round(100 * min($answered, $total) / $total);
    }

    /**
     * Catalogo de apoyos activos: lo unico que el Coach puede recomendar por su
     * nombre (RU-COA-LIM-001).
     *
     * Son los recursos internos del modulo y las actividades del curso que la
     * persona efectivamente puede ver. Esto cierra, de paso, el limite que
     * docs/CUMPLIMIENTO.md declaraba: el Coach nombraba actividades desde su
     * propio conocimiento y podia nombrar una que no existiera.
     *
     * Van nombres, no contenido: la matriz de acceso le sigue negando al Coach
     * el contenido de las unidades.
     *
     * @param int $userid
     * @param int $courseid
     * @return array[] Filas con nombre y tipo.
     */
    public static function catalog(int $userid, int $courseid): array {
        $catalog = [];

        foreach (kb_store::list_documents() as $doc) {
            $catalog[] = [
                'nombre' => preg_replace('/\.[a-z0-9]{2,4}$/i', '', (string) $doc->filename),
                'tipo' => 'recurso_interno',
            ];
        }

        if ($courseid > 0) {
            try {
                $modinfo = get_fast_modinfo($courseid, $userid);
                foreach ($modinfo->get_cms() as $cm) {
                    if (!$cm->uservisible || $cm->deletioninprogress
                            || in_array($cm->modname, self::NOT_A_RESOURCE, true)) {
                        continue;
                    }
                    $catalog[] = [
                        'nombre' => format_string($cm->name, true, ['context' => $cm->context]),
                        'tipo' => $cm->modname,
                    ];
                }
            } catch (\Throwable $e) {
                // Un curso que no se puede leer no es motivo para dejar al Coach
                // sin los recursos internos: se sigue con lo que hay.
                debugging('local_chatbot: catalogo sin actividades del curso: ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
        }

        return $catalog;
    }

    /**
     * Resuelve un nombre propuesto por el modelo contra el catalogo. Funcion pura.
     *
     * Es la barrera de codigo de RU-COA-LIM-001 para las salidas estructuradas:
     * si el nombre no esta en el catalogo, no se guarda. La comparacion ignora
     * mayusculas, tildes y espacios repetidos, porque un modelo que escribe
     * "Unidad 2 - Lectura" en vez de "Unidad 2 – Lectura" no esta inventando.
     *
     * @param string $proposed Nombre que devolvio el modelo.
     * @param string[] $names Nombres del catalogo.
     * @return string El nombre exacto del catalogo, o cadena vacia si no calza.
     */
    public static function match_catalog(string $proposed, array $names): string {
        $normalize = function(string $text): string {
            $text = \core_text::strtolower(trim($text));
            $text = \core_text::specialtoascii($text);
            return preg_replace('/[^a-z0-9]+/', ' ', $text);
        };

        $target = trim($normalize($proposed));
        if ($target === '') {
            return '';
        }
        foreach ($names as $name) {
            if (trim($normalize((string) $name)) === $target) {
                return (string) $name;
            }
        }
        return '';
    }
}
