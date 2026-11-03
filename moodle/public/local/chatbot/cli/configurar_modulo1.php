<?php
/**
 * Configura un Moodle para el Modulo 1: lo que el plugin no trae en su codigo.
 *
 * Instalar el plugin deja el asistente funcionando con sus valores por defecto. Este guion aplica el
 * resto de la configuracion del Modulo 1 (la misma que tienen hoy los dos servidores), para repetirla
 * en otro VPS o PC con un solo comando y sin depender de la memoria de quien la hizo:
 *
 *  - Ajustes del plugin: asistente solo en este curso, PDP automatico, sin barras de ruta, modulos siguientes.
 *  - Sitio: actividades URL ocultas pero accesibles (allowstealth) y HTML adicional (texto justificado, manito
 *    en las pestanas, subsecciones abiertas, salto directo de las URL del recorrido, sin boton manual de
 *    consentimiento y sin las tarjetas que el cliente pidio ocultar).
 *  - Curso: sin foro «Avisos» ni anuncios, enlaces del asistente apuntando a este sitio y a este curso
 *    (necesario despues de restaurar el curso desde una copia de otro servidor) y consentimiento que se marca solo.
 *  - Fecha de apertura de la ruta (--apertura): hasta ese dia solo la Bienvenida y Perfilamiento. Sin la opcion se
 *    respeta lo que ya tengan los ajustes, y las pestanas del curso se ponen de acuerdo con ellos (tambien tras restaurar).
 *  - Boton al Modulo 2 desde el perfil mientras la ruta esta cerrada (--modulo2=ID): ajuste, interruptor y curso visible.
 *
 * Es idempotente: se puede correr de nuevo sin duplicar nada. Con --simular muestra lo que haria.
 * No toca claves ni proveedores de IA: esos datos son de cada servidor.
 *
 * Uso (como el usuario del servidor web):
 *   php cli/configurar_modulo1.php --curso=6 [--siguientes=4,5] [--apertura=2027-01-01|no] [--modulo2=ID|no] [--sin-sitio]
 *       [--simular]
 *
 * @package local_chatbot
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_chatbot\course_bridge;
use local_chatbot\site_setup;

[$options, $unrecognised] = cli_get_params(
    ['curso' => 0, 'siguientes' => '', 'apertura' => '', 'modulo2' => '', 'sin-sitio' => false, 'simular' => false,
        'help' => false],
    ['c' => 'curso', 'h' => 'help']);

if ($options['help'] || empty($options['curso'])) {
    echo "Configura un Moodle para el Modulo 1 (lo que el plugin no trae en su codigo).\n\n"
        . "  --curso=ID         curso del Modulo 1 (obligatorio)\n"
        . "  --siguientes=A,B   cursos de los Modulos 2 y 3 (se abren al cerrar el Modulo 1)\n"
        . "  --apertura=FECHA   AAAA-MM-DD: hasta ese dia solo la Bienvenida y Perfilamiento; «no» lo quita\n"
        . "  --modulo2=ID       mientras tanto, al terminar el perfil: matricula y boton al curso ID (lo deja visible); «no» lo quita\n"
        . "  --sin-sitio        solo el curso: no toca ajustes ni HTML adicional del sitio\n"
        . "  --simular          muestra lo que haria, sin escribir\n";
    exit(0);
}

\core\session\manager::set_user(get_admin());
global $DB, $CFG;

$simular = (bool) $options['simular'];
$course = $DB->get_record('course', ['id' => (int) $options['curso']], '*', MUST_EXIST);
$cursoid = (int) $course->id;

/**
 * Escribe un ajuste (o lo muestra si es una simulacion) e informa del cambio.
 *
 * @param string $plugin Componente ('' = nucleo).
 * @param string $name
 * @param string $value
 * @param bool $simular
 * @return void
 */
function chatbot_cfg(string $plugin, string $name, string $value, bool $simular): void {
    $actual = (string) get_config($plugin === '' ? 'core' : $plugin, $name);
    $etiqueta = ($plugin === '' ? 'core' : $plugin) . '/' . $name;
    if ($actual === $value) {
        echo "  = $etiqueta ya vale '$value'\n";
        return;
    }
    echo "  " . ($simular ? '~' : '*') . " $etiqueta: '" . mb_strimwidth($actual, 0, 40, '…') . "' -> '$value'\n";
    if (!$simular) {
        set_config($name, $value, $plugin === '' ? null : $plugin);
    }
}

echo "== Curso {$cursoid}: {$course->fullname}" . ($simular ? "  [SIMULACION]" : '') . "\n";

// Actividad de la declaracion de consentimiento (codigo M1-CONSENTIMIENTO).
$consentcm = 0;
foreach (get_fast_modinfo($cursoid)->cms as $cm) {
    if (course_bridge::is_consent_code((string) $cm->idnumber)) {
        $consentcm = (int) $cm->id;
    }
}

if (!$options['sin-sitio']) {
    echo "\n-- Ajustes del plugin\n";
    chatbot_cfg('local_chatbot', 'chatonlycourses', (string) $cursoid, $simular);
    chatbot_cfg('local_chatbot', 'ux_autopdp', '1', $simular);
    chatbot_cfg('local_chatbot', 'ux_routebar', '0', $simular);
    chatbot_cfg('local_chatbot', 'ux_coursebar', '0', $simular);
    if ((string) $options['siguientes'] !== '') {
        $ids = \local_chatbot\closure::parse_course_ids((string) $options['siguientes']);
        chatbot_cfg('local_chatbot', 'nextmodules', implode(',', $ids), $simular);
    }
    $apertura = trim((string) $options['apertura']);
    if ($apertura === 'no') {
        chatbot_cfg('local_chatbot', 'ux_aperturaruta', '0', $simular);
    } else if ($apertura !== '') {
        if (!\local_chatbot\journey::parse_opening($apertura, \core_date::get_server_timezone_object())) {
            cli_error("--apertura debe ser una fecha AAAA-MM-DD o «no»: '$apertura'");
        }
        chatbot_cfg('local_chatbot', 'aperturafecha', $apertura, $simular);
        chatbot_cfg('local_chatbot', 'ux_aperturaruta', '1', $simular);
    }
    $modulo2 = trim((string) $options['modulo2']);
    if ($modulo2 === 'no') {
        chatbot_cfg('local_chatbot', 'ux_perfilm2', '0', $simular);
    } else if ($modulo2 !== '') {
        $curso2 = $DB->get_record('course', ['id' => (int) $modulo2]);
        if (!ctype_digit($modulo2) || !$curso2 || (int) $modulo2 === $cursoid || (int) $modulo2 <= (int) SITEID) {
            cli_error("--modulo2 debe ser el id de un curso distinto del Modulo 1, o «no»: '$modulo2'");
        }
        chatbot_cfg('local_chatbot', 'aperturam2', (string) (int) $modulo2, $simular);
        chatbot_cfg('local_chatbot', 'ux_perfilm2', '1', $simular);
        // Un curso oculto no deja entrar a quien esta matriculada como estudiante: el boton no serviria.
        if (!(int) $curso2->visible) {
            echo "  " . ($simular ? '~' : '*') . " curso {$curso2->id} «{$curso2->shortname}»: oculto -> visible\n";
            if (!$simular) {
                course_change_visibility((int) $curso2->id, true);
            }
        } else {
            echo "  = curso {$curso2->id} «{$curso2->shortname}» ya esta visible\n";
        }
    }

    echo "\n-- Sitio\n";
    chatbot_cfg('', 'allowstealth', '1', $simular);
    $actual = (string) get_config('core', 'additionalhtmlhead');
    $nuevo = site_setup::merge_head($actual, site_setup::head_blocks($consentcm, $cursoid));
    if (str_replace("\r\n", "\n", $actual) === $nuevo) {
        echo "  = HTML adicional ya esta al dia\n";
    } else {
        echo "  " . ($simular ? '~' : '*') . " HTML adicional del sitio: " . strlen($actual) . " -> " . strlen($nuevo) . " caracteres"
            . ($consentcm ? '' : " (sin el bloque del consentimiento: el curso no tiene la actividad M1-CONSENTIMIENTO)") . "\n";
        if (!$simular) {
            set_config('additionalhtmlhead', $nuevo);
        }
    }
}

echo "\n-- Curso\n";
// Sin anuncios: Moodle recrea el foro «Avisos» solo mientras haya un numero de anuncios.
if ((int) $course->newsitems !== 0) {
    echo "  " . ($simular ? '~' : '*') . " newsitems {$course->newsitems} -> 0\n";
    if (!$simular) {
        $DB->set_field('course', 'newsitems', 0, ['id' => $cursoid]);
    }
} else {
    echo "  = newsitems ya es 0\n";
}
$avisos = $DB->get_records_sql("SELECT cm.id FROM {course_modules} cm
    JOIN {modules} m ON m.id = cm.module AND m.name = 'forum'
    JOIN {forum} f ON f.id = cm.instance AND f.type = 'news'
    WHERE cm.course = ? AND cm.deletioninprogress = 0", [$cursoid]);
if (!$avisos) {
    echo "  = sin foro de avisos\n";
}
foreach ($avisos as $a) {
    echo "  " . ($simular ? '~' : '*') . " foro «Avisos» (cm {$a->id}) eliminado\n";
    if (!$simular) {
        course_delete_module((int) $a->id);
    }
}

// Enlaces del asistente: actividades URL y botones dentro de etiquetas.
$cambios = 0;
foreach ($DB->get_records('url', ['course' => $cursoid]) as $u) {
    $nuevaurl = site_setup::rewrite_url((string) $u->externalurl, $CFG->wwwroot, $cursoid);
    if ($nuevaurl !== $u->externalurl) {
        echo "  " . ($simular ? '~' : '*') . " URL «{$u->name}»: {$u->externalurl} -> $nuevaurl\n";
        $cambios++;
        if (!$simular) {
            $DB->set_field('url', 'externalurl', $nuevaurl, ['id' => $u->id]);
        }
    }
}
foreach ($DB->get_records('label', ['course' => $cursoid]) as $l) {
    $nuevaintro = site_setup::rewrite_links((string) $l->intro, $CFG->wwwroot, $cursoid);
    if ($nuevaintro !== $l->intro) {
        echo "  " . ($simular ? '~' : '*') . " enlace del asistente en la etiqueta {$l->id} reescrito\n";
        $cambios++;
        if (!$simular) {
            $DB->set_field('label', 'intro', $nuevaintro, ['id' => $l->id]);
        }
    }
}
if (!$cambios) {
    echo "  = los enlaces del asistente ya apuntan a este sitio y curso\n";
}

// El asistente marca solo las actividades del recorrido y el consentimiento (puente): para que Moodle acepte
// esas marcas, cada una debe tener la finalizacion activa. Sin ella no se registra ningun avance.
$conmarca = 0;
$activadas = 0;
foreach (get_fast_modinfo($cursoid)->cms as $cm) {
    $codigo = (string) $cm->idnumber;
    if (course_bridge::step_for_code($codigo) === '' && !course_bridge::is_consent_code($codigo)) {
        continue;
    }
    $conmarca++;
    if ((int) $DB->get_field('course_modules', 'completion', ['id' => $cm->id]) !== 1) {
        echo "  " . ($simular ? '~' : '*') . " finalizacion activada en $codigo (cm {$cm->id})\n";
        $activadas++;
        if (!$simular) {
            $DB->set_field('course_modules', 'completion', 1, ['id' => $cm->id]);
        }
    }
}
if (!$conmarca) {
    echo "  ! el curso no tiene actividades del recorrido (M1-PASO-*) ni M1-CONSENTIMIENTO: corre preparar_curso.php\n";
} else if (!$activadas) {
    echo "  = las $conmarca actividades del recorrido ya tienen finalizacion\n";
}
if (!$consentcm) {
    echo "  ! ninguna actividad lleva el codigo M1-CONSENTIMIENTO en «Numero de identificacion»: asignalo a la etiqueta de la\n"
        . "    declaracion de consentimiento y vuelve a correr este guion\n";
}
// Quien ya avanzo en el asistente queda marcado en el curso.
if ($activadas && !$simular) {
    rebuild_course_cache($cursoid, true);
    $personas = $DB->get_records_select('local_chatbot_profile', 'courseid = ? AND userid > 0', [$cursoid], '', 'DISTINCT userid');
    $marcadas = 0;
    foreach ($personas as $p) {
        if (course_bridge::sync((int) $p->userid, $cursoid)) {
            $marcadas++;
        }
    }
    echo "  * avance sincronizado para $marcadas persona(s) con perfil en este curso\n";
}

// Pestanas posteriores a la Bienvenida y Perfilamiento: cerradas hasta la fecha de apertura, o sin esa restriccion.
$fecha = course_bridge::opening_date();
$secciones = course_bridge::sync_opening($cursoid, $simular);
if ($secciones) {
    echo "  " . ($simular ? '~' : '*') . " secciones " . implode(', ', $secciones) . ": "
        . ($fecha ? 'cerradas hasta el ' . date('Y-m-d', $fecha) : 'sin fecha de apertura') . "\n";
} else {
    echo "  = las pestanas ya estan " . ($fecha ? 'cerradas hasta el ' . date('Y-m-d', $fecha) : 'sin fecha de apertura') . "\n";
}
if ($simular && trim((string) $options['apertura']) !== '') {
    echo "    (en la simulacion las pestanas se calculan con los ajustes actuales, no con --apertura)\n";
}

if (!$simular) {
    rebuild_course_cache($cursoid, true);
    purge_caches(['theme' => true]);
}
echo "\nListo" . ($simular ? ' (simulacion, no se escribio nada)' : '') . ".\n";
echo "Falta, si el curso es nuevo: php cli/preparar_curso.php --curso=$cursoid (actividades del recorrido).\n";
