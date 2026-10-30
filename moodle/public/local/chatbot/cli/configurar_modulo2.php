<?php
/**
 * Configura la pestana «Fase 1: Diagnostico y Validacion» del curso del Modulo 2 (pedido de Interfases del
 * 21-10-2026, puntos 1 a 4). Ver classes/modulo2_setup.php.
 *
 *  - Los materiales de apoyo (Guia, Ficha de campo, Ejemplo) y el checklist enlazan a sus recursos, que quedan
 *    disponibles pero no se muestran en la pagina del curso (requiere allowstealth, que se activa si falta).
 *  - La condicion de los recuadros de resultado queda centrada.
 *  - Al final de «Completa y valida el formulario» va el boton a la ficha ex-ante del asistente.
 *  - Al final de la pestana «Fase 2» va el boton a las hojas de trabajo del asistente (diseno.php).
 *  - Al final de la pestana «Fase 3» van el enlace a la rubrica (recurso oculto) y el boton a las etapas 7 y 8.
 *
 * Los recursos y las secciones se buscan por su nombre y por las marcas del texto del cliente, no por id: el
 * mismo guion sirve en el VPS propio (curso 18) y en el del cliente (curso 4). Es idempotente. Con --simular
 * muestra lo que haria.
 *
 * Uso (como el usuario del servidor web):
 *   php cli/configurar_modulo2.php --curso=18 [--simular]
 *
 * @package local_chatbot
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_chatbot\modulo2_setup;

[$options, $unrecognised] = cli_get_params(['curso' => 0, 'simular' => false, 'help' => false],
    ['c' => 'curso', 'h' => 'help']);

if ($options['help'] || empty($options['curso'])) {
    echo "Configura la Fase 1 del Modulo 2 (enlaces a los materiales, recursos ocultos y boton a la ficha).\n\n"
        . "  --curso=ID   curso del Modulo 2 (obligatorio)\n"
        . "  --simular    muestra lo que haria, sin escribir\n";
    exit(0);
}

\core\session\manager::set_user(get_admin());
global $DB, $CFG;

$simular = (bool) $options['simular'];
$course = $DB->get_record('course', ['id' => (int) $options['curso']], '*', MUST_EXIST);
$cursoid = (int) $course->id;
echo ($simular ? '[SIMULACION] ' : '') . "Curso {$cursoid}: " . format_string($course->fullname) . "\n";

// Recursos de la Fase 1, por nombre.
$modinfo = get_fast_modinfo($course);
$nombres = [];
foreach ($modinfo->get_instances_of('resource') as $cm) {
    $nombres[$cm->id] = $cm->name;
}
$recursos = modulo2_setup::match_resources($nombres);
foreach (['material1', 'material2', 'material3', 'checklist'] as $clave) {
    if (!isset($recursos[$clave])) {
        cli_error("No se encontro el recurso de «{$clave}» en el curso: revisar los nombres de los recursos.");
    }
}
$url = static function(int $cmid) use ($CFG) {
    return $CFG->wwwroot . '/mod/resource/view.php?id=' . $cmid;
};

// Enlaces a los catalogos externos (Plan Formativo y Perfil Ocupacional): actividades URL ya configuradas en el curso.
$nombresurl = [];
foreach ($modinfo->get_instances_of('url') as $cm) {
    $nombresurl[$cm->id] = $cm->name;
}
$catalogos = [];
foreach (modulo2_setup::match_urls($nombresurl) as $n => $cmid) {
    $externa = trim((string) $DB->get_field('url', 'externalurl', ['id' => $modinfo->get_cm($cmid)->instance]));
    if ($externa !== '' && preg_match('#^https?://#i', $externa)) {
        $catalogos[$n] = $externa;
    }
}
foreach ([4 => 'Plan Formativo', 5 => 'Perfil Ocupacional'] as $n => $nombre) {
    if (!isset($catalogos[$n])) {
        echo "  (sin enlace URL de «{$nombre}» en el curso: la tarjeta {$n} no se agrega)\n";
    }
}

if (!isset($recursos['rubrica'])) {
    echo "  (sin recurso de la rubrica: la pestana Fase 3 queda sin ese enlace)\n";
}

// Recursos disponibles pero no mostrados en la pagina del curso.
if (empty($CFG->allowstealth)) {
    echo "  allowstealth: 0 -> 1\n";
    if (!$simular) {
        set_config('allowstealth', 1);
    }
}
foreach ($recursos as $clave => $cmid) {
    $cm = $modinfo->get_cm($cmid);
    if ((int) $cm->visible === 1 && (int) $cm->visibleoncoursepage === 0) {
        continue;
    }
    echo "  recurso {$clave} (cm {$cmid}): visible={$cm->visible} en pagina={$cm->visibleoncoursepage} -> disponible sin mostrarse\n";
    if (!$simular) {
        set_coursemodule_visible($cmid, 1, 0);
    }
}

// Los recursos se abren por enlace directo: la ventana emergente deja una pagina intermedia, se incrustan.
$cambiodisplay = false;
foreach ($recursos as $clave => $cmid) {
    $instancia = $modinfo->get_cm($cmid)->instance;
    $display = (int) $DB->get_field('resource', 'display', ['id' => $instancia]);
    $nuevo = modulo2_setup::linked_display($display);
    if ($nuevo === null) {
        continue;
    }
    echo "  recurso {$clave} (cm {$cmid}): modo {$display} (ventana emergente) -> {$nuevo} (incrustado)\n";
    if (!$simular) {
        $DB->set_field('resource', 'display', $nuevo, ['id' => $instancia]);
        $cambiodisplay = true;
    }
}
// Sin el recuadro «PDF» sobre el visor, y nombres de archivo sin tildes (el visor los mostraba como «DiagnÃ³stico»).
$fs = get_file_storage();
foreach ($recursos as $clave => $cmid) {
    $cm = $modinfo->get_cm($cmid);
    $opciones = (string) $DB->get_field('resource', 'displayoptions', ['id' => $cm->instance]);
    $nuevas = modulo2_setup::display_options($opciones);
    if ($nuevas !== null) {
        echo "  recurso {$clave} (cm {$cmid}): sin el recuadro del tipo de archivo\n";
        if (!$simular) {
            $DB->set_field('resource', 'displayoptions', $nuevas, ['id' => $cm->instance]);
            $cambiodisplay = true;
        }
    }
    $contexto = context_module::instance($cmid);
    foreach ($fs->get_area_files($contexto->id, 'mod_resource', 'content', 0, 'sortorder DESC, id', false) as $archivo) {
        $nombre = modulo2_setup::ascii_filename($archivo->get_filename());
        if ($nombre === $archivo->get_filename()
                || $fs->file_exists($contexto->id, 'mod_resource', 'content', 0, $archivo->get_filepath(), $nombre)) {
            continue;
        }
        echo "  recurso {$clave} (cm {$cmid}): archivo «{$archivo->get_filename()}» -> «{$nombre}»\n";
        if (!$simular) {
            $archivo->rename($archivo->get_filepath(), $nombre);
            $cambiodisplay = true;
        }
    }
}
if ($cambiodisplay) {
    rebuild_course_cache($cursoid, true);
}

// Textos de las subsecciones de la Fase 1 y del final de las pestanas Fase 2 y Fase 3.
$fichaurl = (new moodle_url('/local/chatbot/ficha_exante.php', ['courseid' => $cursoid]))->out(false);
$disenourl = (new moodle_url('/local/chatbot/diseno.php', ['courseid' => $cursoid]))->out(false);
$secciones = [];
foreach ($modinfo->get_section_info_all() as $info) {
    // Con pestanas (multitopic) cada seccion guarda su nivel: 0 es una pestana, mas es una subseccion.
    $nivel = $DB->get_field('course_format_options', 'value', ['sectionid' => $info->id, 'name' => 'level']);
    $secciones[$info->section] = ['name' => get_section_name($course, $info), 'level' => (int) $nivel];
}
// El boton va al final de la pestana Fase 2: en su ultima subseccion, no en la seccion de la pestana.
$fase2 = modulo2_setup::tab_last_section($secciones, 2);
$fase3 = modulo2_setup::tab_last_section($secciones, 3);
$validacionurl = (new moodle_url('/local/chatbot/diseno.php', ['courseid' => $cursoid, 'etapa' => 7]))->out(false);
// Al final de la bienvenida, el boton que lleva al comienzo de la pestana «Fase 1».
$bienvenida = modulo2_setup::welcome_last_section($secciones);
$fase1 = \local_chatbot\exante::phase_section(array_map(static function($s) {
    return $s['name'];
}, $secciones), 1);
$fase1url = $fase1 === null ? '' : (new moodle_url('/course/view.php', ['id' => $cursoid, 'section' => $fase1]))->out(false);
$cambios = 0;
foreach ($DB->get_records('course_sections', ['course' => $cursoid], 'section') as $seccion) {
    $antes = (string) $seccion->summary;
    $despues = $antes;
    if ($bienvenida !== null && $fase1url !== '' && (int) $seccion->section === $bienvenida) {
        $despues = modulo2_setup::add_phase1_button($despues, $fase1url);
    }
    if ($fase2 !== null && (int) $seccion->section === $fase2) {
        $despues = modulo2_setup::add_design_button($despues, $disenourl);
    } else {
        $despues = modulo2_setup::remove_design_button($despues);
    }
    if ($fase3 !== null && (int) $seccion->section === $fase3) {
        $despues = modulo2_setup::add_validation_buttons($despues,
            isset($recursos['rubrica']) ? $url($recursos['rubrica']) : '', $validacionurl);
    }
    if (strpos($antes, '<!-- MATERIAL 1 -->') !== false) {
        $despues = modulo2_setup::link_materials($despues, [1 => $url($recursos['material1']),
            2 => $url($recursos['material2']), 3 => $url($recursos['material3'])]);
        $despues = modulo2_setup::remove_material_note($despues);
        $despues = modulo2_setup::add_catalog_cards($despues, $catalogos);
    }
    if (strpos($antes, 'Consultar y descargar el checklist') !== false) {
        $despues = modulo2_setup::center_conditions($despues);
        // El boton lleva al recurso del checklist (oculto), como los materiales (pedido del 26-10-2026). El checklist
        // para marcar en Moodle (checklist_exante.php) sigue enlazado desde la ficha.
        $despues = modulo2_setup::link_checklist($despues, $url($recursos['checklist']));
        $despues = modulo2_setup::add_form_button($despues, $fichaurl);
    }
    if ($despues === $antes) {
        continue;
    }
    $cambios++;
    echo "  seccion {$seccion->section} «{$seccion->name}»: texto actualizado (" . strlen($antes) . ' -> '
        . strlen($despues) . " caracteres)\n";
    if (!$simular) {
        $DB->set_field('course_sections', 'summary', $despues, ['id' => $seccion->id]);
    }
}
if (!$simular) {
    rebuild_course_cache($cursoid, true);
}
echo $cambios ? "Listo.\n" : "Textos sin cambios.\n";
