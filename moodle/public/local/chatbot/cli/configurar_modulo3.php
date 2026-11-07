<?php
/**
 * Configura las cuatro hojas del curso del Modulo 3 (pedido de Interfases del 06-11-2026): al final de cada una, el boton
 * con el texto que pidio el cliente. Ver classes/modulo3_setup.php.
 *
 *  - Bienvenida e Instrucciones Generales: «Ir a la Fase 1: Registro y Validación» (pestaña Fase 1 del curso).
 *  - Fase 1: «Completar y validar la ficha de implementación» (ficha_implementacion.php).
 *  - Fase 2: «Acceder al asistente IA» (calidad.php, etapas 1 a 3).
 *  - Fase 3: «Ir al ajuste de la experiencia de aprendizaje - Fase 3» (calidad.php, etapa 4).
 *
 * Los textos del curso son del cliente y los carga él: el guion no los toca. Si una hoja ya trae un boton con ese mismo texto
 * (con «#» provisional), se enlaza ese; si no, se agrega una actividad «Texto y medios» al final de la seccion con el boton.
 * Las secciones se buscan por su nombre («Fase N: ...»), no por id: el mismo guion sirve en cualquier servidor y en
 * cualquier curso del Modulo 3 (el 19 en el VPS propio). Es idempotente. Con --simular muestra lo que haria.
 *
 * Uso (como el usuario del servidor web):
 *   php cli/configurar_modulo3.php --curso=19 [--simular]
 *
 * @package local_chatbot
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');

use local_chatbot\modulo3_setup;

[$options, $unrecognised] = cli_get_params(['curso' => 0, 'simular' => false, 'help' => false],
    ['c' => 'curso', 'h' => 'help']);

if ($options['help'] || empty($options['curso'])) {
    echo "Pone al final de las cuatro hojas del curso del Modulo 3 su boton (Fase 1, ficha, asistente IA y ajuste).\n\n"
        . "  --curso=ID   curso del Modulo 3 (obligatorio)\n"
        . "  --simular    muestra lo que haria, sin escribir\n";
    exit(0);
}

\core\session\manager::set_user(get_admin());
global $DB, $CFG;

$simular = (bool) $options['simular'];
$course = $DB->get_record('course', ['id' => (int) $options['curso']], '*', MUST_EXIST);
$cursoid = (int) $course->id;
echo ($simular ? '[SIMULACION] ' : '') . "Curso {$cursoid}: " . format_string($course->fullname) . "\n";

// Secciones, con su nivel (con pestañas, 0 es una pestaña y mas es una subseccion).
$modinfo = get_fast_modinfo($course);
$secciones = [];
foreach ($modinfo->get_section_info_all() as $info) {
    $nivel = $DB->get_field('course_format_options', 'value', ['sectionid' => $info->id, 'name' => 'level']);
    $secciones[$info->section] = ['name' => get_section_name($course, $info), 'level' => (int) $nivel];
}

// Lo que hay en el curso: el resumen de cada seccion y las etiquetas («Texto y medios»).
$items = [];
foreach ($DB->get_records('course_sections', ['course' => $cursoid], 'section') as $seccion) {
    $items[] = ['seccion' => (int) $seccion->section, 'tipo' => 'resumen', 'id' => (int) $seccion->id,
        'html' => (string) $seccion->summary];
}
foreach ($modinfo->get_instances_of('label') as $cm) {
    $items[] = ['seccion' => (int) $cm->sectionnum, 'tipo' => 'label', 'id' => (int) $cm->id,
        'html' => (string) $DB->get_field('label', 'intro', ['id' => $cm->instance])];
}

// Destinos de los botones.
$fase1 = \local_chatbot\exante::phase_section(array_map(static function($s) {
    return $s['name'];
}, $secciones), 1);
$urls = [
    'fase1' => $fase1 === null ? '' : (new moodle_url('/course/view.php', ['id' => $cursoid, 'section' => $fase1]))->out(false),
    'ficha' => (new moodle_url('/local/chatbot/ficha_implementacion.php', ['courseid' => $cursoid]))->out(false),
    'asistente' => (new moodle_url('/local/chatbot/calidad.php', ['courseid' => $cursoid]))->out(false),
    'ajuste' => (new moodle_url('/local/chatbot/calidad.php', ['courseid' => $cursoid, 'etapa' => 4]))->out(false),
];

$botones = modulo3_setup::buttons();
$cambios = 0;
foreach (modulo3_setup::plan($secciones, $items, $urls) as $accion) {
    $b = $botones[$accion['boton']];
    $hoja = $accion['seccion'] === null ? '?' : $accion['seccion'] . ' «' . $secciones[$accion['seccion']]['name'] . '»';
    if ($accion['accion'] === 'sin_hoja') {
        echo "  «{$b['texto']}»: el curso no tiene la hoja donde va (revisar los nombres de las secciones: «Fase N: ...»)\n";
        continue;
    }
    if ($accion['accion'] === 'ok') {
        echo "  «{$b['texto']}»: ya esta en la seccion {$hoja}\n";
        continue;
    }
    $cambios++;
    $que = ['crear' => 'se agrega al final de la seccion', 'actualizar' => 'se actualiza su enlace en la seccion',
        'enlazar' => 'se enlaza el boton del texto del cliente en la seccion'][$accion['accion']];
    echo "  «{$b['texto']}»: {$que} {$hoja} -> " . $urls[$accion['boton']] . "\n";
    if ($simular) {
        continue;
    }
    if ($accion['accion'] === 'crear') {
        create_module((object) ['modulename' => 'label', 'course' => $cursoid, 'section' => (int) $accion['seccion'],
            'name' => $b['texto'], 'introeditor' => ['text' => $accion['html'], 'format' => FORMAT_HTML, 'itemid' => 0],
            'visible' => 1]);
    } else if ($accion['tipo'] === 'label') {
        $cm = $modinfo->get_cm((int) $accion['id']);
        $DB->update_record('label', (object) ['id' => $cm->instance, 'intro' => $accion['html'], 'timemodified' => time()]);
    } else {
        $DB->set_field('course_sections', 'summary', $accion['html'], ['id' => (int) $accion['id']]);
    }
}
if (!$simular && $cambios) {
    rebuild_course_cache($cursoid, true);
}
echo $cambios ? ($simular ? "Simulacion: {$cambios} cambio(s) por hacer.\n" : "Listo: {$cambios} cambio(s).\n")
    : "Botones sin cambios.\n";
