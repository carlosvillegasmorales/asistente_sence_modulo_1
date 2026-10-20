<?php
/**
 * Prepara un curso para el recorrido del Modulo 1.
 *
 * Crea una actividad por cada paso (perfil, diagnostico, PDP, proyecto y cierre)
 * que enlaza con la pagina del asistente y se marca sola cuando la persona
 * completa ese paso. Con eso el curso y el asistente dejan de ser dos mundos: la
 * barra de progreso avanza, las restricciones de acceso funcionan y los informes
 * de finalizacion de Moodle muestran el recorrido completo.
 *
 * Es idempotente: si un paso ya tiene su actividad, la deja como esta.
 *
 * Uso (como el usuario del servidor web):
 *   php preparar_curso.php --curso=6 [--seccion=perfil:0,diagnostico:1,pdp:2,proyecto:4,cierre:5]
 *                          [--encadenar] [--simular]
 *
 * Opciones:
 *   --seccion     Seccion del curso para cada paso. Por defecto, todos en la 0.
 *   --encadenar   Cada paso se abre al completar el anterior (restriccion de acceso).
 *   --criterios   El curso se da por completado cuando estan los pasos obligatorios.
 *
 * Con la ruta de 6 etapas (interruptor route6) el PDP es opcional: no entra en los
 * criterios y en el encadenado solo pide el diagnostico. La Ruta Formativa Base no
 * tiene actividad del puente: son los recursos de la unidad, en su propia seccion.
 *   --simular     Muestra lo que haria, sin escribir nada.
 *
 * @package local_chatbot
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
// Define RESOURCELIB_DISPLAY_NEW, que usa el modulo URL para abrir en otra pestana.
require_once($CFG->dirroot . '/lib/resourcelib.php');

use local_chatbot\course_bridge;
use local_chatbot\journey;

[$options, $unrecognised] = cli_get_params(
    ['curso' => 0, 'seccion' => '', 'encadenar' => false, 'criterios' => false,
        'simular' => false, 'help' => false],
    ['c' => 'curso', 's' => 'seccion', 'h' => 'help']);

if ($options['help'] || empty($options['curso'])) {
    echo "Prepara un curso para el recorrido del Modulo 1.\n\n"
        . "  --curso=ID        curso donde se crean los pasos (obligatorio)\n"
        . "  --seccion=LISTA   seccion por paso: perfil:0,diagnostico:1,pdp:2,proyecto:4,cierre:5\n"
        . "  --encadenar       cada paso se abre al completar el anterior\n"
        . "  --simular         muestra lo que haria, sin escribir\n";
    exit(0);
}

// create_module comprueba permisos sobre el curso: esta herramienta la corre el
// equipo que administra el sitio, asi que opera con la cuenta de administracion.
\core\session\manager::set_user(get_admin());

$course = $DB->get_record('course', ['id' => (int) $options['curso']], '*', MUST_EXIST);
$simular = (bool) $options['simular'];
$log = function(string $m) use ($simular) {
    echo ($simular ? '[simulacion] ' : '') . $m . "\n";
};

// Secciones por paso.
$secciones = array_fill_keys(array_keys(course_bridge::STEPS), 0);
foreach (array_filter(explode(',', (string) $options['seccion'])) as $par) {
    [$paso, $num] = array_pad(explode(':', trim($par), 2), 2, '0');
    if (!isset($secciones[$paso])) {
        cli_error("Paso desconocido en --seccion: {$paso}. Validos: " . implode(', ', array_keys($secciones)));
    }
    $secciones[$paso] = (int) $num;
}

// La finalizacion tiene que estar activa: sin ella el puente no puede marcar nada.
if (!$course->enablecompletion) {
    $log('La finalizacion de actividades estaba desactivada en el curso: se activa (el puente la necesita).');
    if (!$simular) {
        $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);
        $course->enablecompletion = 1;
        rebuild_course_cache($course->id, true);
    }
}

$existentes = course_bridge::step_cms($course->id);
$creados = [];
$cmids = [];

foreach (course_bridge::STEPS as $paso => $def) {
    if (isset($existentes[$paso])) {
        $cmids[$paso] = (int) $existentes[$paso]->id;
        $log("Paso {$paso}: ya existe ({$def['code']}), se deja como esta.");
        continue;
    }
    $seccion = $secciones[$paso];
    $url = (new moodle_url($def['path'], ['courseid' => $course->id]))->out(false);
    $nombre = get_string($def['label'], 'local_chatbot');
    $intro = '<p>' . get_string('bridge_desc_' . $paso, 'local_chatbot') . '</p>'
        . '<p class="text-muted"><em>' . get_string('bridge_auto_note', 'local_chatbot') . '</em></p>';

    $log("Paso {$paso}: crear \"{$nombre}\" en la seccion {$seccion} -> {$url}");
    if ($simular) {
        $creados[] = $paso;
        continue;
    }

    course_create_sections_if_missing($course, $seccion);
    $datos = (object) [
        'modulename' => 'url',
        'course' => $course->id,
        'section' => $seccion,
        'name' => $nombre,
        // add_moduleinfo lee el codigo de la actividad como 'cmidnumber': con
        // 'idnumber' lo ignora en silencio y la actividad queda sin codigo, que
        // es justo lo que el puente necesita para reconocerla.
        'cmidnumber' => $def['code'],
        'externalurl' => $url,
        'display' => RESOURCELIB_DISPLAY_NEW,
        // create_module espera el campo del editor, no intro/introformat sueltos.
        'introeditor' => ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => 0],
        'showdescription' => 1,
        'visible' => 1,
        'printintro' => 1,
        // Manual: el puente la marca desde el asistente, no un clic de la persona.
        'completion' => COMPLETION_TRACKING_MANUAL,
        'completionexpected' => 0,
        'timemodified' => time(),
    ];
    $modulo = create_module($datos);
    $cmids[$paso] = (int) $modulo->coursemodule;
    // Se comprueba, en vez de confiar: sin codigo la actividad no sirve al puente.
    if ($DB->get_field('course_modules', 'idnumber', ['id' => $cmids[$paso]]) !== $def['code']) {
        set_coursemodule_idnumber($cmids[$paso], $def['code']);
    }
    $creados[] = $paso;
}

// Ruta vigente y sus pasos opcionales (el PDP en la ruta de 6 etapas).
$ruta = course_bridge::route_keys();
$opcionales = in_array('ruta', $ruta, true) ? journey::OPTIONAL6 : [];

// Encadenado: cada paso se abre cuando el anterior obligatorio esta completo; un paso
// opcional se abre con el que pide (journey::REQUIRES) y no frena a los que siguen.
if ($options['encadenar'] && count($cmids) === count(course_bridge::STEPS)) {
    $anterior = '';
    foreach ($ruta as $paso) {
        if (!isset($cmids[$paso])) {
            continue;
        }
        $opcional = in_array($paso, $opcionales, true);
        $requisito = $opcional ? (journey::REQUIRES[$paso] ?? $anterior) : $anterior;
        if ($requisito !== '') {
            $log("Restriccion: {$paso} se abre al completar {$requisito}.");
            if (!$simular) {
                $DB->set_field('course_modules', 'availability', course_bridge::availability_after($cmids[$requisito]),
                    ['id' => $cmids[$paso]]);
            }
        }
        if (!$opcional) {
            $anterior = $paso;
        }
    }
}

// Criterios de finalizacion del curso: sin ellos Moodle no calcula progreso y la
// barra del curso queda vacia aunque los pasos esten completos.
if ($options['criterios'] && count($cmids) === count(course_bridge::STEPS)) {
    $obligatorios = array_diff_key($cmids, array_flip($opcionales));
    $log('Finalizacion del curso: se completa cuando esten ' . implode(', ', array_keys($obligatorios)) . '.');
    if (!$simular) {
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');
        require_once($CFG->dirroot . '/completion/completion_aggregation.php');
        $DB->delete_records('course_completion_criteria',
            ['course' => $course->id, 'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY]);
        $datos = (object) ['id' => $course->id, 'criteria_activity' => array_fill_keys(array_values($obligatorios), 1)];
        $criterio = new completion_criteria_activity();
        $criterio->update_config($datos);
        // Todos los pasos, no cualquiera de ellos.
        $aggregation = new completion_aggregation(['course' => $course->id, 'criteriatype' => null]);
        $aggregation->setMethod(COMPLETION_AGGREGATION_ALL);
        $aggregation->save();
    }
}

if (!$simular) {
    rebuild_course_cache($course->id, true);
}

echo "\n";
$log('Pasos creados: ' . ($creados ? implode(', ', $creados) : 'ninguno (ya estaban todos)'));
$log('Curso: ' . format_string($course->fullname));
if (!$simular) {
    echo "\nEl recorrido ya esta enlazado. Al entrar al curso, cada persona vera sus pasos\n"
        . "y se marcaran solos a medida que los complete en el asistente.\n";
}
exit(0);
