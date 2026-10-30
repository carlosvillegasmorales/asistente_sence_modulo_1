<?php
/**
 * Carga en la base de conocimiento de un rol los documentos de texto de una libreria versionada, sin pasar por la
 * extraccion con IA del panel «Gestionar base de conocimiento» (que puede fallar por RECITATION y no deja el mismo
 * texto en los dos servidores).
 *
 * La libreria del Asistente de Diseño Instruccional del Modulo 2 esta en deploy/libreria_m2/: el checklist, la ficha,
 * la guia de llenado y el ejemplo de llenado que entrego Interfases el 30-10-2026, transcritos a Markdown. Cada archivo
 * empieza con «# Nombre del documento»: ese es el nombre con que aparece en el panel. Un documento con el mismo nombre
 * reemplaza al anterior (queda la version nueva); si el texto no cambio, no se toca. Es idempotente. Con --simular
 * muestra lo que haria.
 *
 * Uso (como el usuario del servidor web; la carpeta se sube aparte, no va con el plugin):
 *   php cli/cargar_libreria.php --dir=/ruta/a/libreria_m2 [--ambito=m2] [--simular]
 *
 * @package local_chatbot
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_chatbot\kb_store;

[$options, $unrecognised] = cli_get_params(['dir' => '', 'ambito' => kb_store::AMBITO_M2, 'simular' => false,
    'help' => false], ['h' => 'help']);

if ($options['help'] || $options['dir'] === '') {
    echo "Carga los documentos .md de una carpeta en la base de conocimiento de un rol.\n\n"
        . "  --dir=RUTA     carpeta con los .md (obligatorio)\n"
        . "  --ambito=m2    m2 (Asistente de Diseño Instruccional, por defecto) o m1 (Tutor)\n"
        . "  --simular      muestra lo que haria, sin escribir\n";
    exit(0);
}

if (!in_array($options['ambito'], kb_store::ambitos(), true)) {
    cli_error('Ámbito desconocido: ' . $options['ambito']);
}
if (!$DB->get_manager()->field_exists('local_chatbot_kb_documents', 'ambito')) {
    cli_error('La base no tiene el campo «ambito»: corre admin/cli/upgrade.php antes de cargar la librería.');
}
$archivos = glob(rtrim($options['dir'], '/\\') . '/*.md') ?: [];
sort($archivos);
if (!$archivos) {
    cli_error('No hay archivos .md en ' . $options['dir']);
}

$cambios = 0;
foreach ($archivos as $ruta) {
    $doc = kb_store::library_file((string) file_get_contents($ruta));
    if ($doc === null) {
        cli_writeln('  omitido (la primera línea no es un título «# ...»): ' . basename($ruta));
        continue;
    }
    [$nombre, $texto] = $doc;
    $vigente = $DB->get_record('local_chatbot_kb_documents',
        ['filename' => $nombre, 'active' => 1, 'ambito' => $options['ambito']]);
    if ($vigente && $vigente->content === $texto) {
        cli_writeln('  sin cambios: ' . $nombre);
        continue;
    }
    $cambios++;
    if ($options['simular']) {
        cli_writeln('  cargaría (' . ($vigente ? 'versión ' . ((int) $vigente->version + 1) : 'nuevo') . ', '
            . strlen($texto) . ' bytes): ' . $nombre);
        continue;
    }
    $id = kb_store::add_document($nombre, $texto, $options['ambito']);
    cli_writeln('  cargado (id ' . $id . ', ' . strlen($texto) . ' bytes): ' . $nombre);
}
cli_writeln(($options['simular'] ? 'Simulación: ' : '') . $cambios . ' documento(s) ' . ($options['simular'] ? 'por cargar' : 'cargados')
    . '. Versión de la librería: ' . (kb_store::context_version($options['ambito']) ?: '(vacía)'));
