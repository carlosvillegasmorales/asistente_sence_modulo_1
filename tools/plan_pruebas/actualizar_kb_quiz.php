<?php
/**
 * Reemplaza en la base de conocimiento el documento del quiz por la versión que solo lleva
 * criterios por pregunta (sin alternativas). Retira cualquier versión U1-QF-01 anterior.
 * Uso: php actualizar_kb_quiz.php /tmp/kb_qf.txt
 */
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
global $DB;
$content = file_get_contents($argv[1]);
if (strpos($content, 'Alternativa correcta') !== false || preg_match('/^[A-D]\. /m', $content)) {
    fwrite(STDERR, "ABORTO: el documento contiene alternativas o claves\n");
    exit(1);
}
$DB->set_field_select('local_chatbot_kb_documents', 'active', 0, "filename LIKE 'U1-QF-01%'");
$id = \local_chatbot\kb_store::add_document('U1-QF-01 Quiz Formativo Unidad 1', $content);
foreach ($DB->get_records('local_chatbot_kb_documents', null, 'id', 'id, filename, active, version, sizebytes') as $d) {
    echo "{$d->id} | {$d->filename} | activo={$d->active} | v{$d->version} | {$d->sizebytes}B\n";
}
