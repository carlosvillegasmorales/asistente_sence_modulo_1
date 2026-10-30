<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/kb_router.php');

/**
 * Stores the chatbot's knowledge base as individual documents in the database
 * (local_chatbot_kb_documents), one row per uploaded PDF.
 *
 * This replaces an earlier design that appended every PDF's extracted text
 * into a single ever-growing knowledge_base.txt file: that file could only
 * grow, had no way to remove a document that was uploaded by mistake or went
 * out of date, and (in an even earlier version) lived inside the plugin's
 * public web directory, making it downloadable by anyone who guessed the URL.
 * A real table gives per-document listing and deletion, and lives in the
 * database like the rest of the plugin's data.
 */
class kb_store {

    /** @var string Ambito del Tutor del Modulo 1 (y de los documentos anteriores a los ambitos). */
    const AMBITO_M1 = 'm1';

    /** @var string Ambito del Asistente de Diseño Instruccional (Modulo 2): su libreria de conocimientos. */
    const AMBITO_M2 = 'm2';

    /**
     * Ambitos validos, en el orden del panel.
     *
     * @return string[]
     */
    public static function ambitos(): array {
        return [self::AMBITO_M1, self::AMBITO_M2];
    }

    /** @var bool|null Si la tabla ya tiene el campo «ambito» (se averigua una vez por pedido). */
    private static $conambito = null;

    /**
     * Si la base ya tiene el campo «ambito». Antes de la migracion (los verificadores corren antes de upgrade.php) no
     * lo tiene: entonces todo es del Modulo 1 y la libreria del Modulo 2 esta vacia.
     *
     * @return bool
     */
    private static function has_ambito(): bool {
        global $DB;
        if (self::$conambito === null) {
            self::$conambito = $DB->get_manager()->field_exists('local_chatbot_kb_documents', 'ambito');
        }
        return self::$conambito;
    }

    /**
     * Condicion de ambito para una consulta: [sql, params]. Sin el campo, el Modulo 1 es todo y el 2 nada.
     *
     * @param string $ambito
     * @return array
     */
    private static function ambito_where(string $ambito): array {
        $ambito = self::normalize_ambito($ambito);
        if (!self::has_ambito()) {
            return $ambito === self::AMBITO_M1 ? ['1 = 1', []] : ['1 = 0', []];
        }
        return ['ambito = ?', [$ambito]];
    }

    /**
     * Ambito valido; cualquier otro valor cae en el del Modulo 1. Funcion pura.
     *
     * @param mixed $ambito
     * @return string
     */
    public static function normalize_ambito($ambito): string {
        return in_array($ambito, self::ambitos(), true) ? $ambito : self::AMBITO_M1;
    }

    /** @var int En cola: el PDF esta guardado pero todavia no se le extrajo el texto. */
    const STATUS_PENDING = 0;
    /** @var int Listo: el texto esta extraido y el documento puede alimentar al asistente. */
    const STATUS_READY = 1;
    /** @var int Fallido: la extraccion no prospero; el motivo queda en statusmsg. */
    const STATUS_ERROR = 2;

    /**
     * @return array local_chatbot_kb_documents records ordered newest first
     *         (id, filename, sizebytes, timecreated) — content omitted, callers
     *         that only need to list/manage documents don't need the full text.
     */
    public static function list_documents(): array {
        global $DB;
        self::migrate_legacy_file();
        return $DB->get_records('local_chatbot_kb_documents', ['active' => 1], 'timecreated DESC',
            'id, filename, sizebytes, version, timecreated' . (self::has_ambito() ? ', ambito' : ''));
    }

    /**
     * Documentos que todavia no entraron al contexto: en cola o fallidos.
     *
     * Van aparte de list_documents() a proposito: esos son los que alimentan al
     * asistente, y una carga a medio procesar no debe confundirse con ellos.
     *
     * @return array Filas con id, filename, status, statusmsg, timecreated.
     */
    public static function list_jobs(): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal([self::STATUS_PENDING, self::STATUS_ERROR], SQL_PARAMS_NAMED);
        return $DB->get_records_select('local_chatbot_kb_documents', "status $insql", $params,
            'timecreated DESC', 'id, filename, status, statusmsg, timecreated');
    }

    /**
     * Registra un documento recien subido y lo deja en cola de extraccion.
     *
     * Nace inactivo y sin contenido: hasta que la extraccion termine bien no
     * alimenta al asistente ni retira a la version anterior, de manera que una
     * extraccion fallida nunca deja al recurso sin ninguna version vigente.
     *
     * @param string $filename Nombre original del PDF.
     * @param string $ambito Rol al que alimenta (AMBITO_M1 o AMBITO_M2).
     * @return int id de la fila creada.
     */
    public static function queue_document(string $filename, string $ambito = self::AMBITO_M1): int {
        global $DB;

        $record = new \stdClass();
        $record->filename = $filename;
        if (self::has_ambito()) {
            $record->ambito = self::normalize_ambito($ambito);
        }
        $record->content = '';
        $record->sizebytes = 0;
        $record->version = 0;
        $record->active = 0;
        $record->status = self::STATUS_PENDING;
        $record->statusmsg = null;
        $record->timecreated = time();

        return $DB->insert_record('local_chatbot_kb_documents', $record);
    }

    /**
     * Decide que hacer con el resultado de una extraccion. Funcion pura.
     *
     * Separa tres desenlaces que antes se confundian en uno solo: un documento
     * con claves de evaluacion se rechaza (RU-KB-001), un corte por tiempo de
     * espera no dice nada malo del PDF, y recien despues queda el caso de un
     * documento del que de verdad no se pudo sacar texto.
     *
     * @param string|null $texto Texto devuelto por el proveedor, o null si no devolvio.
     * @param string $motivoerror Mensaje de error del proveedor ('' si no hubo).
     * @param string $finishreason finishReason del candidato, si el proveedor lo informo.
     * @return array ['estado' => 'listo'|'rechazado'|'error', 'motivo' => string]
     */
    public static function classify_extraction(?string $texto, string $motivoerror = '',
            string $finishreason = ''): array {
        if ($texto !== null && trim($texto) !== '') {
            $markers = self::answer_key_markers($texto);
            if (!empty($markers)) {
                return ['estado' => 'rechazado', 'motivo' => implode(', ', $markers)];
            }
            return ['estado' => 'listo', 'motivo' => ''];
        }

        if (strtoupper($finishreason) === 'RECITATION') {
            // El modelo se niega a reproducir textualmente material publicado.
            // Le pasa a los documentos oficiales de referencia, y no hay ajuste
            // de tiempo ni de tamano que lo cambie: no es un problema del PDF.
            return ['estado' => 'error', 'motivo' => 'recitacion'];
        }
        if ($motivoerror === 'transport_error') {
            // El proveedor no alcanzo a responder. El PDF no tiene nada malo.
            return ['estado' => 'error', 'motivo' => 'timeout'];
        }
        if ($motivoerror !== '') {
            return ['estado' => 'error', 'motivo' => $motivoerror];
        }
        return ['estado' => 'error', 'motivo' => 'sin_texto'];
    }

    /**
     * Traduce el motivo guardado de un fallo a la cadena de idioma que lo explica.
     * Funcion pura.
     *
     * Los motivos se guardan como codigos estables para no depender del idioma
     * ni del texto exacto del proveedor; aqui se decide como contarselo a quien
     * administra la base de conocimiento.
     *
     * @param string|null $statusmsg Motivo guardado en la fila.
     * @return array ['clave' => identificador de cadena, 'a' => parametro o null]
     */
    public static function describe_failure(?string $statusmsg): array {
        $statusmsg = (string) $statusmsg;

        if (strpos($statusmsg, 'claves:') === 0) {
            return ['clave' => 'managekb_error_answerkeys', 'a' => substr($statusmsg, strlen('claves:'))];
        }
        if ($statusmsg === 'timeout') {
            return ['clave' => 'managekb_error_timeout', 'a' => null];
        }
        if ($statusmsg === 'recitacion') {
            return ['clave' => 'managekb_error_recitation', 'a' => null];
        }
        if ($statusmsg === 'sin_archivo') {
            return ['clave' => 'managekb_error_nofile', 'a' => null];
        }
        if ($statusmsg === 'sin_texto' || $statusmsg === '') {
            return ['clave' => 'managekb_error_noextract', 'a' => null];
        }
        // Cualquier otro motivo viene del proveedor: se muestra tal cual.
        return ['clave' => 'managekb_error_detail', 'a' => $statusmsg];
    }

    /**
     * Da por buena la extraccion de un documento en cola y lo pone vigente.
     *
     * Aqui, y no antes, se retira la version anterior y se calcula el numero de
     * version: refleja la secuencia real de puestas en vigencia.
     *
     * @param int $id Fila en cola.
     * @param string $content Texto extraido.
     * @return void
     */
    public static function complete_document(int $id, string $content): void {
        global $DB;

        // RU-KB-001: ultimo control antes de que el texto entre al contexto del
        // asistente, venga por donde venga.
        $markers = self::answer_key_markers($content);
        if (!empty($markers)) {
            throw new \invalid_parameter_exception('El documento contiene claves de evaluacion (' .
                implode(', ', $markers) . ').');
        }

        $row = $DB->get_record('local_chatbot_kb_documents', ['id' => $id], '*', MUST_EXIST);

        // La version anterior es la del mismo nombre en el mismo ambito: un documento del Modulo 2 no retira uno del 1.
        $previous = $DB->get_record('local_chatbot_kb_documents', ['filename' => $row->filename, 'active' => 1]
            + (self::has_ambito() ? ['ambito' => self::normalize_ambito($row->ambito ?? null)] : []));

        $row->content = $content;
        $row->sizebytes = strlen($content);
        $row->version = $previous ? ((int) $previous->version + 1) : 1;
        $row->active = 1;
        $row->status = self::STATUS_READY;
        $row->statusmsg = null;
        $DB->update_record('local_chatbot_kb_documents', $row);

        if ($previous) {
            $DB->set_field('local_chatbot_kb_documents', 'active', 0, ['id' => $previous->id]);
        }
    }

    /**
     * Marca que la extraccion de un documento en cola no prospero.
     *
     * La fila queda inactiva y con el motivo, para poder explicarlo en el panel
     * en vez de dejar la carga en silencio.
     *
     * @param int $id Fila en cola.
     * @param string $motivo Codigo o mensaje del motivo.
     * @return void
     */
    public static function fail_document(int $id, string $motivo): void {
        global $DB;
        $DB->update_record('local_chatbot_kb_documents', (object) [
            'id' => $id,
            'active' => 0,
            'status' => self::STATUS_ERROR,
            'statusmsg' => \core_text::substr($motivo, 0, 255),
        ]);
    }

    /**
     * Carga un documento como recurso interno del modulo.
     *
     * Volver a subir un documento con el mismo nombre no lo pisa: retira la
     * version vigente y registra la siguiente. La gobernanza pide versionar
     * las bases documentales, no solo los prompts y las rubricas, porque una
     * respuesta del asistente puede quedar explicada por la version del
     * documento que estaba activa cuando se generó.
     *
     * @param string $filename Original PDF filename, for display purposes.
     * @param string $content Extracted plain text.
     * @param string $ambito Rol al que alimenta (AMBITO_M1 o AMBITO_M2).
     * @return int id of the new record.
     */
    public static function add_document(string $filename, string $content, string $ambito = self::AMBITO_M1): int {
        global $DB;

        // RU-KB-001: el Tutor recibe la base completa en su contexto, y ninguna instruccion
        // de prompt garantiza que no revele un dato que tiene delante. Un documento con
        // claves de evaluacion no entra, venga de la interfaz o de un script.
        $markers = self::answer_key_markers($content);
        if (!empty($markers)) {
            throw new \invalid_parameter_exception('El documento contiene claves de evaluacion (' .
                implode(', ', $markers) . '). Sube una version saneada, sin claves ni alternativas correctas.');
        }

        $ambito = self::normalize_ambito($ambito);
        $conambito = self::has_ambito() ? ['ambito' => $ambito] : [];
        $previous = $DB->get_record('local_chatbot_kb_documents', ['filename' => $filename, 'active' => 1] + $conambito);

        $record = (object) $conambito;
        $record->filename = $filename;
        $record->content = $content;
        $record->sizebytes = strlen($content);
        $record->version = $previous ? ((int) $previous->version + 1) : 1;
        $record->active = 1;
        $record->timecreated = time();

        if ($previous) {
            $DB->set_field('local_chatbot_kb_documents', 'active', 0, ['id' => $previous->id]);
        }

        return $DB->insert_record('local_chatbot_kb_documents', $record);
    }

    /**
     * Retira un documento del contexto del asistente.
     *
     * No borra la fila: la marca inactiva. Asi el historial de que documento
     * estuvo vigente en que momento sigue disponible para auditoria.
     *
     * @param int $id Document id to retire.
     * @return bool true if a record was retired.
     */
    /**
     * Documento de texto de una libreria versionada (deploy/libreria_m2/*.md): el nombre es el titulo «# ...» de la
     * primera linea con texto y el contenido, el archivo entero. Funcion pura (la usa cli/cargar_libreria.php).
     *
     * @param string $contenido Archivo tal como esta en disco.
     * @return array|null [nombre, contenido]; null si no empieza con un titulo «# ...».
     */
    public static function library_file(string $contenido): ?array {
        $texto = trim(str_replace("\r\n", "\n", preg_replace('/^\xEF\xBB\xBF/', '', $contenido)));
        if (!preg_match('/^#[ \t]+(.+)$/m', $texto, $m) || strpos($texto, $m[0]) !== 0) {
            return null;
        }
        $nombre = trim(kb_router::strip_marks($m[1]));
        if ($nombre === '') {
            return null;
        }
        return [mb_substr($nombre, 0, 255, 'UTF-8'), $texto];
    }

    /**
     * Marcas de claves de evaluacion en un texto. Funcion pura.
     *
     * Son las que usan los documentos de Interfases: el Anexo D marca cada pregunta con
     * "SOLO CONFIGURACION MOODLE · Alternativa correcta: C" y el Doc 2 con "Clave: C".
     *
     * @param string $content
     * @return string[] Descripcion de las marcas encontradas; vacio si no hay ninguna.
     */
    public static function answer_key_markers(string $content): array {
        $patterns = [
            'alternativa correcta' => '/alternativa\s+correcta\s*:\s*[A-E]\b/iu',
            'respuesta correcta' => '/respuesta\s+correcta\s*:\s*[A-E]\b/iu',
            'clave' => '/\bclave\s*:\s*[A-E]\b/iu',
            'solo configuracion' => '/solo\s+configuraci[oó]n/iu',
            'no visible para la persona' => '/no\s+visible\s+para\s+la\s+persona/iu',
        ];
        $found = [];
        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $content)) {
                $found[] = $label;
            }
        }
        return $found;
    }

    public static function delete_document(int $id): bool {
        global $DB;
        if (!$DB->record_exists('local_chatbot_kb_documents', ['id' => $id])) {
            return false;
        }
        $DB->set_field('local_chatbot_kb_documents', 'active', 0, ['id' => $id]);
        return true;
    }

    /**
     * Concatenates every stored document into a single context block for the
     * AI prompt, each clearly labelled so the model (and anyone debugging a
     * bad answer) can tell which source a fact came from.
     *
     * @return string '' if no documents have been uploaded yet.
     */
    /**
     * Version del recurso fijo que se cito en una respuesta (RU-PRIV-006).
     *
     * El codigo llega como 'U1-LF-01' o 'U1-LF-01-seccion-3.5'; el documento se
     * guarda con el codigo al inicio del nombre.
     *
     * @param string $codigo Codigo del recurso citado.
     * @return string Version como 'v2', o cadena vacia si no hay documento con ese codigo.
     */
    public static function resource_version(string $codigo): string {
        global $DB;
        $codigo = strtoupper(trim($codigo));
        if (!preg_match('/^(U\d+-(?:LF|CA|ER|QF)-\d+)/', $codigo, $m)) {
            return '';
        }
        $version = $DB->get_field_select('local_chatbot_kb_documents', 'version',
            'active = 1 AND ' . $DB->sql_like('filename', ':patron', false),
            ['patron' => $m[1] . '%'], IGNORE_MULTIPLE);
        return $version ? 'v' . (int) $version : '';
    }

    /**
     * Huella de la base de conocimiento que se le paso al modelo (RU-PRIV-006).
     *
     * No es la version de un documento sino la del conjunto: con ella se puede
     * saber si dos respuestas se construyeron sobre el mismo material.
     *
     * @param string $ambito Rol cuya base se pasa (por defecto, la del Tutor del Modulo 1).
     * @return string Por ejemplo '4docs-v1.1.1.2', o cadena vacia si no hay documentos.
     */
    public static function context_version(string $ambito = self::AMBITO_M1): string {
        global $DB;
        [$where, $params] = self::ambito_where($ambito);
        $versiones = $DB->get_fieldset_select('local_chatbot_kb_documents', 'version',
            'active = 1 AND ' . $where, $params, 'timecreated ASC');
        if (empty($versiones)) {
            return '';
        }
        return count($versiones) . 'docs-v' . implode('.', array_map('intval', $versiones));
    }

    /**
     * Documentos vigentes de un ambito, en el mismo orden que get_combined_context (timecreated ASC), para enrutar
     * sus secciones por tarea.
     *
     * @param string $ambito Rol cuya libreria se consulta.
     * @return array Lista de ['nombre' => string, 'contenido' => string, 'version' => int].
     */
    public static function documents(string $ambito): array {
        global $DB;
        self::migrate_legacy_file();
        [$where, $params] = self::ambito_where($ambito);
        $docs = $DB->get_records_select('local_chatbot_kb_documents', 'active = 1 AND ' . $where, $params,
            'timecreated ASC', 'id, filename, content, version, timecreated');
        $lista = [];
        foreach ($docs as $doc) {
            $lista[] = [
                'nombre' => (string) $doc->filename,
                'contenido' => (string) $doc->content,
                'version' => (int) $doc->version,
            ];
        }
        return $lista;
    }

    /**
     * Texto de los documentos vigentes de un ambito, cada uno con su encabezado.
     *
     * @param string $ambito Rol cuya base se pasa (por defecto, la del Tutor del Modulo 1: la libreria del Modulo 2 no
     *        le llega al Tutor, ni la del Tutor al Asistente de Diseño Instruccional).
     * @return string '' si no hay documentos.
     */
    public static function get_combined_context(string $ambito = self::AMBITO_M1): string {
        global $DB;
        self::migrate_legacy_file();
        [$where, $params] = self::ambito_where($ambito);
        $docs = $DB->get_records_select('local_chatbot_kb_documents', 'active = 1 AND ' . $where, $params,
            'timecreated ASC', 'id, filename, content, version, timecreated');
        if (empty($docs)) {
            return '';
        }
        $parts = [];
        foreach ($docs as $doc) {
            $parts[] = "=== DOCUMENTO: {$doc->filename} (v{$doc->version}, "
                . date('Y-m-d H:i', $doc->timecreated) . ") ===\n" . $doc->content;
        }
        return implode("\n\n", $parts);
    }

    /**
     * @return int Combined size in bytes of every stored document.
     */
    public static function total_size(): int {
        global $DB;
        self::migrate_legacy_file();
        $sum = $DB->get_field_sql('SELECT SUM(sizebytes) FROM {local_chatbot_kb_documents} WHERE active = 1');
        return (int) $sum;
    }

    /**
     * One-time import for sites upgrading from the flat-file knowledge base:
     * picks up whichever legacy file exists (dataroot copy from the
     * intermediate version, or the original one directly under the plugin's
     * webroot) and turns it into a single "legacy" document, then removes the
     * file so this only ever runs once. No-ops once the table has any rows
     * or no legacy file is found.
     */
    private static function migrate_legacy_file(): void {
        global $DB;
        if ($DB->record_exists_select('local_chatbot_kb_documents', '1=1')) {
            return;
        }

        $candidates = [];
        try {
            $candidates[] = make_upload_directory('local_chatbot', false) . '/knowledge_base.txt';
        } catch (\Exception $e) {
            // Dataroot subdirectory couldn't be created; fall through to the webroot candidate.
        }
        $candidates[] = __DIR__ . '/../knowledge_base.txt';

        foreach ($candidates as $legacypath) {
            if (file_exists($legacypath) && filesize($legacypath) > 0) {
                $content = file_get_contents($legacypath);
                self::add_document('knowledge_base.txt (migrado)', $content);
                unlink($legacypath);
                return;
            }
        }
    }
}
