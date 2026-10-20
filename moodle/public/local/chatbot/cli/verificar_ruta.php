<?php
/**
 * Verifica la ruta del Modulo 1 que ve la persona: donde esta (la pantalla abierta) y que
 * sigue (lo pendiente). Cubre el desfase que reporto el cliente: estar en el PDP ya
 * validado y que el asistente dijera "estas en el Proyecto".
 *
 * Uso:  php cli/verificar_ruta.php
 */

define('MOODLE_INTERNAL', true);

require_once(__DIR__ . '/../classes/journey.php');

use local_chatbot\journey;

$fallidos = 0;
$total = 0;

function local_chatbot_verificar(bool $condicion, string $descripcion): void {
    global $fallidos, $total;
    $total++;
    if (!$condicion) {
        echo "  FALLA  {$descripcion}\n";
        $fallidos++;
    }
}

// --- Pantalla abierta ---
local_chatbot_verificar(journey::screen_step('/local/chatbot/pdp.php') === 3, 'pdp.php es el paso 3');
local_chatbot_verificar(journey::screen_step('/local/chatbot/index.php') === 1, 'index.php es el paso 1');
local_chatbot_verificar(journey::screen_step('/local/chatbot/diagnostico.php') === 2, 'diagnostico.php es el paso 2');
local_chatbot_verificar(journey::screen_step('/local/chatbot/proyecto.php') === 4, 'proyecto.php es el paso 4');
local_chatbot_verificar(journey::screen_step('/local/chatbot/cierre.php') === 5, 'cierre.php es el paso 5');
local_chatbot_verificar(journey::screen_step('/course/view.php') === 0, 'una página del curso no es una pantalla del recorrido');
local_chatbot_verificar(journey::screen_step('/otro/plugin/pdp.php') === 0, 'un pdp.php de otro plugin no cuenta');

// --- El caso del cliente: en el PDP con el plan validado ---
$hecho = [1 => true, 2 => true, 3 => true, 4 => false, 5 => false];
$v = journey::view($hecho, 3);
local_chatbot_verificar($v['focus'] === 3, 'en el PDP validado el asistente habla del PDP, no del Proyecto');
local_chatbot_verificar($v['focus_done'] === true, 'y sabe que ese paso ya está completo');
local_chatbot_verificar($v['next'] === 4, 'lo que sigue es el Proyecto');
local_chatbot_verificar($v['pending'] === 4, 'el primer pendiente sigue siendo el Proyecto');

// --- Fuera del recorrido manda lo pendiente ---
$v = journey::view($hecho, 0);
local_chatbot_verificar($v['focus'] === 4 && !$v['focus_done'], 'sin pantalla del recorrido se habla del primer pendiente');
local_chatbot_verificar($v['next'] === 5, 'y lo que sigue es el paso posterior');

// --- Volver atrás: la pantalla vuelve a mandar ---
$v = journey::view($hecho, 2);
local_chatbot_verificar($v['focus'] === 2 && $v['focus_done'], 'al volver al diagnóstico el asistente habla del diagnóstico');
local_chatbot_verificar($v['next'] === 4, 'y lo que sigue es el primer pendiente, no el paso 3 ya hecho');

// --- Adelantarse a un paso pendiente ---
$v = journey::view([1 => true, 2 => false, 3 => false, 4 => false, 5 => false], 3);
local_chatbot_verificar($v['focus'] === 3 && !$v['focus_done'], 'quien abre el PDP sin diagnóstico está en el PDP, sin completar');
local_chatbot_verificar($v['next'] === 4 && $v['pending'] === 2, 'lo que sigue es el 4; el pendiente más atrasado es el diagnóstico');
$v = journey::view([1 => true, 2 => false, 3 => false, 4 => false, 5 => false], 5);
local_chatbot_verificar($v['next'] === 2, 'en el último paso, si falta uno anterior, ese es el siguiente');

// --- Todo hecho ---
$todo = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true];
$v = journey::view($todo, 0);
local_chatbot_verificar($v['focus'] === 6 && $v['next'] === 0 && $v['pending'] === 6, 'con todo hecho la ruta está completa');
$v = journey::view($todo, 3);
local_chatbot_verificar($v['focus'] === 3 && $v['next'] === 0, 'con todo hecho, en el PDP no queda nada que sugerir');
local_chatbot_verificar(journey::view([], 0)['focus'] === 1, 'sin ningún dato se parte por el perfil');

// --- Contexto para el modelo ---
$linea = journey::context_line(journey::view($hecho, 3), 3);
local_chatbot_verificar(strpos($linea, 'Plan Personal de Desarrollo') !== false && strpos($linea, 'ya está completo') !== false,
    'el contexto dice la pantalla abierta y que ya está completa');
local_chatbot_verificar(strpos($linea, 'Proyecto integrador') !== false && strpos($linea, 'lo que sigue') !== false,
    'el contexto presenta el Proyecto como lo que sigue, no como dónde está');
local_chatbot_verificar(journey::context_line(journey::view($hecho, 0), 0) === '', 'sin pantalla del recorrido no hay línea de ubicación');
local_chatbot_verificar(strpos(journey::context_line(journey::view($hecho, 4), 4), 'en curso') !== false,
    'un paso sin completar se dice en curso');

// --- Guía del PDP ---
$g = journey::pdp_guide(0, 0, false);
local_chatbot_verificar($g[0]['current'] && !$g[0]['done'] && !$g[1]['done'] && !$g[2]['done'], 'sin metas, lo primero es pedir propuestas');
$g = journey::pdp_guide(3, 0, false);
local_chatbot_verificar($g[0]['done'] && $g[1]['current'] && $g[1]['count'] === 3, 'con propuestas, lo siguiente es revisarlas (3 por revisar)');
$g = journey::pdp_guide(0, 2, false);
local_chatbot_verificar($g[1]['done'] && $g[2]['current'], 'sin propuestas por revisar y con metas, lo siguiente es validar');
$g = journey::pdp_guide(1, 2, true);
local_chatbot_verificar($g[2]['done'] && $g[1]['current'] && !$g[1]['done'], 'un plan validado con una propuesta nueva vuelve a pedir revisarla');
$g = journey::pdp_guide(0, 2, true);
local_chatbot_verificar(count(array_filter($g, fn($i) => $i['current'])) === 0, 'con todo hecho ningún paso queda como actual');

// --- Barra de ruta de cada pantalla ---
$pasos = journey::route_steps([1 => true, 2 => true, 3 => true, 4 => false, 5 => false], 2);
local_chatbot_verificar(count($pasos) === 5, 'la barra tiene los cinco pasos');
local_chatbot_verificar($pasos[1]['here'] && $pasos[1]['done'], 'el paso abierto se marca «aquí» aunque esté hecho');
local_chatbot_verificar(count(array_filter($pasos, fn($p) => $p['here'])) === 1, 'solo un paso está marcado como «aquí»');
local_chatbot_verificar($pasos[0]['done'] && !$pasos[0]['here'] && !$pasos[3]['done'], 'los demás conservan su estado de hecho o pendiente');
local_chatbot_verificar(count(array_filter(journey::route_steps([], 0), fn($p) => $p['here'])) === 0, 'sin pantalla del recorrido no se marca ninguno');

// --- Ruta de 6 etapas (ajustes del cliente del 08-10-2026) ---
$r6 = journey::ROUTE6;
local_chatbot_verificar($r6 === ['perfil', 'diagnostico', 'ruta', 'proyecto', 'pdp', 'cierre'],
    'la ruta de 6 sigue el orden del cliente: perfil, diagnostico, ruta formativa, proyecto, PDP, cierre');
local_chatbot_verificar(journey::optional_positions($r6) === [5] && journey::optional_positions(journey::ROUTE5) === [],
    'el PDP es opcional solo en la ruta de 6');
local_chatbot_verificar(journey::screen_step('/local/chatbot/pdp.php', $r6) === 5
    && journey::screen_step('/local/chatbot/proyecto.php', $r6) === 4
    && journey::screen_step('/local/chatbot/cierre.php', $r6) === 6, 'en la ruta de 6 cada pantalla cae en su etapa');
local_chatbot_verificar(journey::screen_step('/mod/resource/view.php', $r6, 'U1-LF-01') === 3,
    'abrir la lectura de la unidad es estar en la Ruta Formativa Base');
local_chatbot_verificar(journey::screen_step('/mod/quiz/view.php', $r6, 'U2-QF-01') === 3,
    'el quiz de otra unidad tambien es la Ruta Formativa');
local_chatbot_verificar(journey::screen_step('/mod/resource/view.php', journey::ROUTE5, 'U1-LF-01') === 0,
    'en la ruta de 5 los recursos de la unidad no son un paso');
local_chatbot_verificar(journey::screen_step('/mod/page/view.php', $r6, 'U1-QF-01-P03') === 0,
    'el codigo de una pregunta no es un recurso de la ruta');

// Bloqueos: «obligatorio para poder continuar» la primera vez, navegacion libre hacia atras.
$b = journey::locked([1 => true, 2 => true, 3 => false, 4 => false, 5 => false, 6 => false], $r6);
local_chatbot_verificar(!$b[3] && $b[4] && $b[6], 'sin la Ruta Formativa, el proyecto y el cierre quedan bloqueados');
local_chatbot_verificar(!$b[5], 'el PDP opcional se abre con el diagnostico, aunque falte la ruta formativa');
$b = journey::locked([1 => true, 2 => false, 3 => false, 4 => false, 5 => false, 6 => false], $r6);
local_chatbot_verificar($b[5], 'sin diagnostico el PDP queda bloqueado (no hay matriz para proponer metas)');
$b = journey::locked([1 => true, 2 => true, 3 => true, 4 => true, 5 => false, 6 => false], $r6);
local_chatbot_verificar(!$b[6], 'el cierre se abre sin el PDP: el plan es opcional');
$b = journey::locked([1 => true, 2 => false, 3 => true, 4 => true, 5 => false, 6 => false], $r6);
local_chatbot_verificar(!$b[3] && !$b[4], 'una etapa ya hecha nunca se bloquea: se puede volver a ella');
$b = journey::locked([], journey::ROUTE5);
local_chatbot_verificar($b[2] && !$b[1], 'en la ruta de 5 rige la misma regla de orden');

// Donde esta y que sigue, sin contar el opcional.
$v = journey::view([1 => true, 2 => true, 3 => true, 4 => true, 5 => false, 6 => false], 0, 6, [5]);
local_chatbot_verificar($v['pending'] === 6 && $v['focus'] === 6, 'con el PDP sin hacer, lo pendiente es el cierre');
$v = journey::view([1 => true, 2 => true, 3 => true, 4 => true, 5 => false, 6 => true], 0, 6, [5]);
local_chatbot_verificar($v['pending'] === 7 && $v['next'] === 0, 'con lo obligatorio hecho la ruta esta completa aunque falte el PDP');
$h = [1 => true, 2 => true, 3 => false, 4 => false, 5 => false, 6 => false];
$v = journey::view($h, 5, 6, [5], journey::locked($h, $r6));
local_chatbot_verificar($v['focus'] === 5 && $v['next'] === 3, 'en el PDP, lo que falta es la Ruta Formativa');

// Ruta Formativa Base a partir de sus recursos.
$rs = journey::ruta_status([['code' => 'U1-LF-01', 'kind' => 'LF', 'done' => true], ['code' => 'U1-CA-01', 'kind' => 'CA', 'done' => false],
    ['code' => 'U1-ER-01', 'kind' => 'ER', 'done' => true], ['code' => 'U1-QF-01', 'kind' => 'QF', 'done' => false]]);
local_chatbot_verificar(!$rs['done'] && $rs['missing'] === ['CA', 'QF'], 'falta el caso y el quiz, en ese orden');
local_chatbot_verificar(journey::ruta_status([['code' => 'U1-QF-01', 'kind' => 'QF', 'done' => true]])['done'],
    'con todo recorrido la Ruta Formativa queda hecha');
local_chatbot_verificar(journey::ruta_status([])['done'], 'un curso sin recursos fijos no exige la Ruta Formativa');

// Proximo paso en la ruta de 6.
$h6 = ['route6' => true, 'consent' => true, 'diagnostic' => true, 'ruta' => false, 'ruta_missing' => ['CA', 'QF'],
    'project' => false, 'closure' => false, 'plan_validated' => false, 'proposed' => 0, 'inplan' => 0];
$a = journey::next_action($h6);
local_chatbot_verificar($a['key'] === 'ruta' && $a['a'] === ['CA', 'QF'] && $a['stepkey'] === 'ruta' && $a['step'] === 3,
    'tras el diagnostico se sugiere la Ruta Formativa con lo que falta');
local_chatbot_verificar(journey::next_action(['ruta' => true] + $h6)['key'] === 'proyecto', 'con la ruta hecha, el proyecto');
local_chatbot_verificar(journey::next_action(['ruta' => true, 'project' => true] + $h6)['key'] === 'cierre',
    'sin usar el PDP, despues del proyecto viene el cierre (no se pide armar un plan)');
$a = journey::next_action(['ruta' => true, 'project' => true, 'proposed' => 2] + $h6);
local_chatbot_verificar($a['key'] === 'propuestas' && $a['step'] === 5, 'quien ya usa el plan ve lo pendiente del plan antes del cierre');
local_chatbot_verificar(journey::next_action(['ruta' => true, 'project' => true, 'closure' => true] + $h6)['key'] === 'completo',
    'con el cierre hecho la ruta esta completa');

// Contexto del modelo en la ruta de 6.
$linea = journey::context_line(journey::view([1 => true, 2 => true, 3 => true, 4 => false, 5 => false, 6 => false], 3, 6, [5]), 3, $r6);
local_chatbot_verificar(strpos($linea, 'Ruta Formativa Base') !== false && strpos($linea, 'Proyecto Integrador') !== false
    && strpos($linea, 'etapa') !== false, 'el contexto nombra la Ruta Formativa Base y la etapa que sigue');
$bloque = journey::progress_block($r6, [1 => true, 2 => true, 3 => false, 4 => false, 5 => false, 6 => false]);
local_chatbot_verificar(strpos($bloque, 'Etapa 5: PDP y Apoyos Adaptativos (opcional)') !== false
    && strpos($bloque, 'Hito actual pendiente de la persona: Etapa 3') !== false, 'el avance para el modelo marca el PDP como opcional');

// Nombres: los del documento del cliente, en su orden («esta estructura debe ser la misma que aparece en
// la viñeta Tu ruta del módulo 1»). El Tutor nombra las etapas igual que la barra.
$documento = ['Bienvenida y Perfilamiento', 'Diagnóstico Autoaplicado', 'Ruta Formativa Base', 'Proyecto Integrador',
    'PDP y Apoyos Adaptativos', 'Seguimiento y Cierre'];
local_chatbot_verificar(array_map(static function (string $k) use ($r6): string {
    return journey::step_name($k, $r6);
}, $r6) === $documento, 'las 6 etapas llevan los nombres y el orden del documento del cliente');
local_chatbot_verificar(strpos($bloque, 'Etapa 1: Bienvenida y Perfilamiento') !== false
    && strpos($bloque, 'Etapa 6: Seguimiento y Cierre') !== false, 'el avance para el modelo usa los nombres del documento');
local_chatbot_verificar(journey::step_name('perfil', journey::ROUTE5) === 'Perfil y consentimiento'
    && strpos(journey::progress_block(journey::ROUTE5, [1 => true]), 'Paso 2: Diagnóstico') !== false,
    'la ruta de 5 conserva sus nombres y sus «pasos»: el interruptor la devuelve tal cual');

// Que se abre al cerrar: el Tutor no debe inventar la continuacion.
$m23 = ['Módulo 2: Diseño', 'Módulo 3: Calidad'];
local_chatbot_verificar(strpos(journey::next_modules_line($m23, false), '«Módulo 2: Diseño» y «Módulo 3: Calidad»') !== false
    && strpos(journey::next_modules_line($m23, false), 'Al completar') !== false,
    'antes del cierre, el Tutor sabe que al cerrar se abren los Módulos 2 y 3');
local_chatbot_verificar(strpos(journey::next_modules_line($m23, true), 'ya tiene acceso') !== false,
    'despues del cierre, el Tutor sabe que ya tiene acceso');
local_chatbot_verificar(strpos(journey::next_modules_line([], false), 'no inventes') !== false,
    'sin modulos configurados, el Tutor no inventa una continuacion');

// --- Linea de resumen de la barra (la del curso tiene la misma estructura que la de las pantallas) ---
$h6 = [1 => true, 2 => false, 3 => false, 4 => false, 5 => false, 6 => false];
$b6 = [3 => true, 4 => true, 6 => true];
$r = journey::summary_state(journey::view($h6, 0, 6, [5], $b6), 0, $b6, 6);
local_chatbot_verificar($r === ['n' => 2, 'state' => 'pending'],
    'en la portada del curso, el resumen habla de la etapa en la que va la persona (el diagnóstico)');
$r = journey::summary_state(journey::view($h6, 1, 6, [5], $b6), 1, $b6, 6);
local_chatbot_verificar($r === ['n' => 1, 'state' => 'done'], 'en la pantalla del perfil ya hecho: etapa 1, completa');
$r = journey::summary_state(journey::view($h6, 4, 6, [5], $b6), 4, $b6, 6);
local_chatbot_verificar($r === ['n' => 4, 'state' => 'locked'], 'en la pantalla del proyecto bloqueado: no está en curso');
$r = journey::summary_state(journey::view($h6, 2, 6, [5], $b6), 2, $b6, 6);
local_chatbot_verificar($r === ['n' => 2, 'state' => 'open'], 'en la pantalla del diagnóstico pendiente: en curso');
$todo6 = [1 => true, 2 => true, 3 => true, 4 => true, 5 => false, 6 => true];
$r = journey::summary_state(journey::view($todo6, 0, 6, [5]), 0, [], 6);
local_chatbot_verificar($r['state'] === 'alldone', 'en la portada con todo lo obligatorio hecho (sin el PDP opcional): ruta completa');

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Ruta del módulo conforme: la pantalla abierta manda para decir dónde está la persona.\n";
    exit(0);
}
echo "Ruta NO conforme: revisar classes/journey.php.\n";
exit(1);
