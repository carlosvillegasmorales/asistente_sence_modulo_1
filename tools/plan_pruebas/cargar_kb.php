<?php
/**
 * Carga o actualiza los documentos de la Base de Conocimiento (kb_store)
 * a partir de paquete_u1.json (o un archivo JSON equivalente).
 *
 * No modifica cursos, estudiantes, quizzes ni instrumentos. Es idempotente:
 * si un documento ya está vigente con el mismo contenido exacto, lo conserva
 * sin generar una nueva versión.
 *
 * Uso (CLI):
 *   php cargar_kb.php [ruta/paquete_u1.json]
 */
define('CLI_SCRIPT', true);

if (file_exists('/var/www/html/config.php')) {
    require('/var/www/html/config.php');
} else if (file_exists(__DIR__ . '/../../moodle/public/config.php')) {
    require(__DIR__ . '/../../moodle/public/config.php');
} else {
    fwrite(STDERR, "Error: no se encontró config.php de Moodle.\n");
    exit(1);
}

require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/chatbot/classes/kb_store.php');

$paquetepath = $argv[1] ?? (__DIR__ . '/paquete_u1.json');
if (!file_exists($paquetepath)) {
    $paquetepath = '/tmp/paquete_u1.json';
}

if (!file_exists($paquetepath)) {
    cli_error("No se encontró el archivo de paquete JSON en: {$paquetepath}\nEjecuta primero: python construir_paquete.py");
}

$paquete = json_decode(file_get_contents($paquetepath), true);
if (!$paquete || empty($paquete['kb'])) {
    cli_error("El archivo JSON no contiene la sección 'kb' con documentos válidos.");
}

cli_heading("Sincronizando Base de Conocimiento del Asistente IA (kb_store)");
cli_writeln("Leyendo paquete: " . $paquetepath);
cli_writeln("Documentos en paquete: " . count($paquete['kb']));
cli_writeln("");

global $DB;
$cargados = 0;
$mantenidos = 0;

foreach ($paquete['kb'] as $doc) {
    $filename = $doc['filename'];
    $content = $doc['content'];

    $activo = $DB->get_record('local_chatbot_kb_documents', ['filename' => $filename, 'active' => 1]);
    if ($activo && $activo->content === $content) {
        cli_writeln("  [VIGENTE] «{$filename}» (v{$activo->version}, " . round($activo->sizebytes / 1024, 1) . " KB)");
        $mantenidos++;
        continue;
    }

    try {
        $id = \local_chatbot\kb_store::add_document($filename, $content);
        $nuevo = $DB->get_record('local_chatbot_kb_documents', ['id' => $id]);
        $tipo = $activo ? "NUEVA VERSIÓN v{$nuevo->version}" : "NUEVO v1";
        cli_writeln("  [CARGADO] «{$filename}» ({$tipo}, " . round($nuevo->sizebytes / 1024, 1) . " KB)");
        $cargados++;
    } catch (\Exception $e) {
        cli_writeln("  [ERROR] Al cargar «{$filename}»: " . $e->getMessage());
    }
}

cli_writeln("");
cli_heading("Estado actual de la Base de Conocimiento");
$docs = \local_chatbot\kb_store::list_documents();
cli_writeln(sprintf("%-5s | %-6s | %-10s | %-16s | %s", "ID", "VER", "TAMAÑO", "FECHA", "DOCUMENTO"));
cli_writeln(str_repeat("-", 80));

foreach ($docs as $d) {
    $fecha = date('Y-m-d H:i', $d->timecreated);
    $kb_size = round($d->sizebytes / 1024, 1) . " KB";
    cli_writeln(sprintf("%-5d | v%-5d | %-10s | %-16s | %s", $d->id, $d->version, $kb_size, $fecha, $d->filename));
}

cli_writeln(str_repeat("-", 80));
cli_writeln("Huella context_version: " . \local_chatbot\kb_store::context_version());
cli_writeln("Tamaño total activo: " . round(\local_chatbot\kb_store::total_size() / 1024, 1) . " KB (" . count($docs) . " documentos activos)");
cli_writeln("");
