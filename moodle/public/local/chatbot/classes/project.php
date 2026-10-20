<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Proyecto integrador y evaluacion formativa contra rubrica (componente 4 del
 * Modulo 1, y Modo C del asistente).
 *
 * El proyecto no es un archivo suelto: son campos estructurados que siguen la
 * cadena de alineamiento que el documento fija como resultado esperado del
 * modulo —Perfil/UCL, aprendizajes esperados, criterios de desempeno,
 * actividades, evidencias, instrumentos y mejora—. Tenerlos como campos
 * separados es lo que permite que la evaluacion sea criterio por criterio en
 * vez de una impresion general sobre un texto largo.
 *
 * La retroalimentacion se guarda en el formato estandar de salida que exige el
 * documento: fortaleza observada, aspecto a mejorar, por que importa, accion
 * sugerida y vinculo con el PDP. Ese formato es lo que hace la interaccion
 * auditable y reutilizable para analitica.
 */
class project {

    /** @var string Aun editable por la persona. */
    const STATUS_BORRADOR = 'borrador';

    /** @var string Entregado y a la espera de evaluacion. */
    const STATUS_ENTREGADO = 'entregado';

    /** @var string Con retroalimentacion del Modo C ya emitida. */
    const STATUS_EVALUADO = 'evaluado';

    /** @var string[] Campos estructurados que componen la cadena de alineamiento. */
    const FIELDS = ['perfilucl', 'aprendizajes', 'criterios', 'actividades',
        'evidencias', 'instrumentos', 'mejora', 'autoevaluacion'];

    /**
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|false
     */
    public static function get(int $userid, int $courseid) {
        global $DB;
        return $DB->get_record('local_chatbot_project', ['userid' => $userid, 'courseid' => $courseid]);
    }

    /**
     * Crea o actualiza el proyecto de la persona.
     *
     * @param int $userid
     * @param int $courseid
     * @param array $data Titulo mas los campos de FIELDS.
     * @return int id del proyecto.
     */
    public static function save(int $userid, int $courseid, array $data): int {
        global $DB;

        $now = time();
        $record = self::get($userid, $courseid);
        $isnew = ($record === false);

        if ($isnew) {
            $record = new \stdClass();
            $record->userid = $userid;
            $record->courseid = $courseid;
            $record->version = 1;
            $record->status = self::STATUS_BORRADOR;
            $record->timecreated = $now;
        }

        $record->title = $data['title'] ?? get_string('project_untitled', 'local_chatbot');
        foreach (self::FIELDS as $field) {
            if (isset($data[$field])) {
                $record->{$field} = $data[$field];
            }
        }
        $record->timemodified = $now;

        if ($isnew) {
            $record->id = $DB->insert_record('local_chatbot_project', $record);
        } else {
            $DB->update_record('local_chatbot_project', $record);
        }

        return (int) $record->id;
    }

    /**
     * Guarda el archivo de respaldo del proyecto (el objeto de datos del
     * documento lista "archivo" junto a los campos estructurados).
     *
     * Se acepta un solo archivo por proyecto: al subir uno nuevo se reemplaza
     * el anterior, porque lo que se evalua es la version vigente.
     *
     * @param int $projectid
     * @param string $tmppath Ruta temporal de la subida.
     * @param string $filename Nombre original.
     * @return bool
     */
    public static function save_attachment(int $projectid, string $tmppath, string $filename): bool {
        global $DB;

        $realmime = mime_content_type($tmppath);
        if (!in_array($realmime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            return false;
        }

        $filename = clean_param($filename, PARAM_FILE);
        $contextid = \context_system::instance()->id;

        $fs = get_file_storage();
        $fs->delete_area_files($contextid, 'local_chatbot', 'project_attachment', $projectid);
        $fs->create_file_from_pathname([
            'contextid' => $contextid,
            'component' => 'local_chatbot',
            'filearea' => 'project_attachment',
            'itemid' => $projectid,
            'filepath' => '/',
            'filename' => $filename,
        ], $tmppath);

        $DB->set_field('local_chatbot_project', 'attachment', $filename, ['id' => $projectid]);

        return true;
    }

    /**
     * Criterios activos de la rubrica. Se prefiere la rubrica propia del curso
     * y, si no existe, la del sitio.
     *
     * @param int $courseid
     * @return \stdClass[]
     */
    public static function list_criteria(int $courseid = 0): array {
        global $DB;

        $criteria = $DB->get_records('local_chatbot_criterion',
            ['courseid' => $courseid, 'active' => 1], 'sortorder ASC, id ASC');

        if (empty($criteria) && $courseid != 0) {
            $criteria = $DB->get_records('local_chatbot_criterion',
                ['courseid' => 0, 'active' => 1], 'sortorder ASC, id ASC');
        }

        return $criteria;
    }

    /**
     * @param int $courseid
     * @return string La rubrica en texto para el prompt del Modo C.
     */
    public static function rubric_as_prompt_context(int $courseid): string {
        $criteria = self::list_criteria($courseid);
        if (empty($criteria)) {
            return '';
        }

        $lines = [];
        foreach ($criteria as $criterion) {
            $lines[] = "[{$criterion->code}] {$criterion->name}: {$criterion->descriptor}";
        }

        return "RUBRICA OFICIAL DEL PROYECTO INTEGRADOR:\n" . implode("\n", $lines);
    }

    /**
     * Evalua el proyecto contra la rubrica, criterio por criterio, y guarda la
     * retroalimentacion en el formato estandar de salida.
     *
     * Devuelve siempre algo utilizable: si la IA no esta disponible o su
     * respuesta no se puede interpretar, marca los criterios como pendientes
     * de revision humana en vez de inventar un veredicto. El documento pide
     * exactamente eso: que el asistente indique cuando una revision humana es
     * necesaria.
     *
     * @param int $projectid
     * @return array Con claves ok (bool) y message (string).
     */
    public static function evaluate(int $projectid): array {
        global $DB;

        $project = $DB->get_record('local_chatbot_project', ['id' => $projectid], '*', MUST_EXIST);
        $criteria = self::list_criteria((int) $project->courseid);

        if (empty($criteria)) {
            return ['ok' => false, 'message' => get_string('project_error_norubric', 'local_chatbot')];
        }

        if (!\local_chatbot\ai\provider_factory::is_configured()) {
            self::store_needs_human($project, $criteria, get_string('project_error_nokey', 'local_chatbot'));
            return ['ok' => false, 'message' => get_string('project_error_nokey', 'local_chatbot')];
        }

        $context = assistant::build_context(assistant::MODE_EVALUADOR,
            (int) $project->userid, (int) $project->courseid);

        $prompt = $context['prompt'] . "\n\n"
            . "PROYECTO INTEGRADOR ENTREGADO POR LA PERSONA:\n"
            . self::as_text($project) . "\n\n"
            . "Evalua el proyecto criterio por criterio de la rubrica. Responde UNICAMENTE"
            . " con un objeto JSON, sin texto adicional ni bloques de codigo, con esta forma:\n"
            . '{"criterios": [{"code": "codigo del criterio", "fortaleza": "...", "mejora": "...",'
            . ' "porque": "...", "accion": "...", "pdp": "...", "revision_humana": true o false}]}' . "\n"
            . "Reglas: 'fortaleza' describe que esta bien resuelto y en que evidencia del proyecto te"
            . " basas; 'mejora' senala el criterio con brecha o ausencia de evidencia; 'porque' explica"
            . " la consecuencia pedagogica o evaluativa; 'accion' propone un ajuste concreto y viable;"
            . " 'pdp' indica que objetivo del plan se crea, actualiza o prioriza. Marca"
            . " revision_humana en true si la evidencia es insuficiente, el contexto requiere"
            . " conocimiento especializado o hay ambiguedades relevantes. Distingue observacion,"
            . " inferencia y recomendacion, y usa un tono descriptivo y respetuoso.";

        $contents = [['role' => 'user', 'parts' => [['text' => $prompt]]]];

        $result = \local_chatbot\ai\provider_factory::get()->generate_content($contents, 90, 2,
            \local_chatbot\ai\provider_factory::get_configured_model());
        $text = gemini_client::extract_text($result);

        if ($text === null) {
            self::store_needs_human($project, $criteria, get_string('project_error_noresponse', 'local_chatbot'));
            return ['ok' => false, 'message' => get_string('project_error_noresponse', 'local_chatbot')];
        }

        $clean = trim(preg_replace('/^```(json)?|```$/m', '', $text));
        $decoded = json_decode($clean, true);

        if (!is_array($decoded) || empty($decoded['criterios'])) {
            self::store_needs_human($project, $criteria, get_string('project_error_unparsed', 'local_chatbot'));
            return ['ok' => false, 'message' => get_string('project_error_unparsed', 'local_chatbot')];
        }

        // La evaluacion siempre reemplaza la de la version anterior del
        // proyecto: la persona debe ver la retroalimentacion de lo que
        // entrego ahora, no un historial mezclado.
        $DB->delete_records('local_chatbot_project_fb', ['projectid' => $projectid]);

        $bycode = [];
        foreach ($criteria as $criterion) {
            $bycode[$criterion->code] = $criterion;
        }

        $now = time();
        $stored = 0;
        foreach ($decoded['criterios'] as $entry) {
            $code = $entry['code'] ?? '';
            if (!isset($bycode[$code])) {
                continue;
            }

            $feedback = new \stdClass();
            $feedback->projectid = $projectid;
            $feedback->criterionid = $bycode[$code]->id;
            $feedback->fortaleza = (string) ($entry['fortaleza'] ?? '');
            $feedback->mejora = (string) ($entry['mejora'] ?? '');
            $feedback->porque = (string) ($entry['porque'] ?? '');
            $feedback->accion = (string) ($entry['accion'] ?? '');
            $feedback->pdpvinculo = (string) ($entry['pdp'] ?? '');
            $feedback->needshuman = !empty($entry['revision_humana']) ? 1 : 0;
            $feedback->projectversion = (int) $project->version;
            $feedback->timecreated = $now;

            $DB->insert_record('local_chatbot_project_fb', $feedback);
            $stored++;
        }

        if ($stored === 0) {
            self::store_needs_human($project, $criteria, get_string('project_error_unparsed', 'local_chatbot'));
            return ['ok' => false, 'message' => get_string('project_error_unparsed', 'local_chatbot')];
        }

        $project->status = self::STATUS_EVALUADO;
        $project->timemodified = $now;
        $DB->update_record('local_chatbot_project', $project);

        interaction::record((int) $project->userid, (int) $project->courseid,
            assistant::MODE_EVALUADOR, 'evaluacion_proyecto',
            $context['resources'], get_string('project_recommendation_logged', 'local_chatbot'));

        $needshuman = $DB->record_exists('local_chatbot_project_fb',
            ['projectid' => $projectid, 'needshuman' => 1]);

        \local_chatbot\event\project_evaluated::create([
            'context' => \context_system::instance(),
            'userid' => (int) $project->userid,
            'objectid' => $projectid,
            'other' => ['needshuman' => $needshuman ? 1 : 0, 'criterios' => $stored],
        ])->trigger();

        return ['ok' => true, 'message' => get_string('project_evaluated', 'local_chatbot')];
    }

    /**
     * @param int $projectid
     * @return \stdClass[] Retroalimentacion vigente, indexada por criterionid.
     */
    public static function get_feedback(int $projectid): array {
        global $DB;

        $rows = $DB->get_records('local_chatbot_project_fb', ['projectid' => $projectid]);
        $bycriterion = [];
        foreach ($rows as $row) {
            $bycriterion[$row->criterionid] = $row;
        }

        return $bycriterion;
    }

    /**
     * Cuando no se puede evaluar, se deja constancia por criterio de que la
     * revision queda en manos humanas. Nunca se registra un juicio negativo
     * por una falla tecnica.
     *
     * @param \stdClass $project
     * @param \stdClass[] $criteria
     * @param string $reason
     * @return void
     */
    private static function store_needs_human(\stdClass $project, array $criteria, string $reason): void {
        global $DB;

        $DB->delete_records('local_chatbot_project_fb', ['projectid' => $project->id]);
        $now = time();

        foreach ($criteria as $criterion) {
            $feedback = new \stdClass();
            $feedback->projectid = $project->id;
            $feedback->criterionid = $criterion->id;
            $feedback->fortaleza = '';
            $feedback->mejora = '';
            $feedback->porque = '';
            $feedback->accion = $reason;
            $feedback->pdpvinculo = '';
            $feedback->needshuman = 1;
            $feedback->projectversion = (int) $project->version;
            $feedback->timecreated = $now;
            $DB->insert_record('local_chatbot_project_fb', $feedback);
        }
    }

    /**
     * @param \stdClass $project
     * @return string El proyecto en texto plano para enviarlo al modelo.
     */
    private static function as_text(\stdClass $project): string {
        $labels = [
            'perfilucl' => 'Perfil ocupacional / UCL de referencia',
            'aprendizajes' => 'Aprendizajes esperados',
            'criterios' => 'Criterios de desempeno',
            'actividades' => 'Actividades de aprendizaje',
            'evidencias' => 'Evidencias',
            'instrumentos' => 'Instrumentos de evaluacion',
            'mejora' => 'Retroalimentacion y mejora',
            'autoevaluacion' => 'Autoevaluacion de la persona',
        ];

        $parts = ['Titulo: ' . $project->title];
        foreach ($labels as $field => $label) {
            $value = trim((string) ($project->{$field} ?? ''));
            $parts[] = $label . ': ' . ($value !== '' ? $value : '(sin contenido entregado)');
        }

        return pseudonymizer::scrub(implode("\n\n", $parts));
    }
}
