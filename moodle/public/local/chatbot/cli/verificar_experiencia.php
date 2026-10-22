<?php
/**
 * Verifica las mejoras de experiencia de M1-v1.13.0: interruptores, «tu proximo paso», respuestas
 * en vivo (lectura del flujo y liberacion por frases) y valoracion de respuestas.
 *
 * Uso:  php cli/verificar_experiencia.php
 */

define('MOODLE_INTERNAL', true);

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/ux.php');
require_once(__DIR__ . '/../classes/closure.php');
require_once(__DIR__ . '/../classes/analytics.php');
require_once(__DIR__ . '/../classes/journey.php');
require_once(__DIR__ . '/../classes/stream.php');
require_once(__DIR__ . '/../classes/feedback.php');
require_once(__DIR__ . '/../classes/guardrails.php');
require_once(__DIR__ . '/../classes/pdp.php');
require_once(__DIR__ . '/../classes/reminders.php');
require_once(__DIR__ . '/../classes/thread.php');
require_once(__DIR__ . '/../classes/seeder.php');
require_once(__DIR__ . '/../classes/profile.php');

use local_chatbot\feedback;
use local_chatbot\guardrails;
use local_chatbot\journey;
use local_chatbot\pdp;
use local_chatbot\profile;
use local_chatbot\reminders;
use local_chatbot\seeder;
use local_chatbot\thread;
use local_chatbot\stream;
use local_chatbot\ux;

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

// --- Interruptores ---
local_chatbot_verificar(ux::resolve(false, 1) === true, 'sin valor guardado manda el valor por defecto (encendida)');
local_chatbot_verificar(ux::resolve(false, 0) === false, 'sin valor guardado manda el valor por defecto (apagada)');
local_chatbot_verificar(ux::resolve('0', 1) === false, 'apagada desde la administracion queda apagada');
local_chatbot_verificar(ux::resolve('1', 0) === true, 'encendida desde la administracion queda encendida');
local_chatbot_verificar(ux::resolve('', 1) === true, 'un valor vacio no apaga por accidente');
foreach (['screencontext', 'routebar', 'pdpguide', 'goalorder', 'gapchange', 'supportfocus', 'supporthelp',
        'nextaction', 'coursebar', 'modebyscreen', 'resourcechips', 'feedback', 'retry', 'kbcache', 'streaming'] as $f) {
    local_chatbot_verificar(array_key_exists($f, ux::DEFAULTS), "la mejora '{$f}' tiene interruptor");
}

// --- Tu proximo paso ---
$base = ['consent' => true, 'diagnostic' => true, 'plan_validated' => true, 'project' => false, 'closure' => false,
    'proposed' => 0, 'inplan' => 2, 'supports' => [], 'deadlines' => [], 'now' => 1000000];
local_chatbot_verificar(journey::next_action(['consent' => false] + $base)['key'] === 'perfil', 'sin consentimiento: perfil');
local_chatbot_verificar(journey::next_action(['diagnostic' => false] + $base)['key'] === 'diagnostico', 'sin diagnostico: diagnostico');
$a = journey::next_action(['proposed' => 3] + $base);
local_chatbot_verificar($a['key'] === 'propuestas' && $a['a'] === 3 && $a['anchor'] === 'propuestas',
    'propuestas por revisar: lleva a las propuestas, con cuantas son');
local_chatbot_verificar(journey::next_action(['inplan' => 0, 'plan_validated' => false] + $base)['key'] === 'pedir',
    'sin metas: pedir propuestas');
local_chatbot_verificar(journey::next_action(['plan_validated' => false] + $base)['key'] === 'validar', 'metas sin validar: validar');
$a = journey::next_action(['supports' => [['n' => 2, 'id' => 16, 'kind' => 'evidencia']]] + $base);
local_chatbot_verificar($a['key'] === 'apoyo_evidencia' && $a['a'] === 2 && $a['anchor'] === 'apoyo-16',
    'apoyo esperando evidencia: lleva a ese apoyo, con el numero de la meta');
$a = journey::next_action(['supports' => [['n' => 1, 'id' => 5, 'kind' => 'iniciar'], ['n' => 3, 'id' => 9, 'kind' => 'decidir']]] + $base);
local_chatbot_verificar($a['key'] === 'apoyo_decidir' && $a['anchor'] === 'apoyo-9',
    'decidir una propuesta del apoyo va antes que iniciar otro');
$a = journey::next_action(['deadlines' => [['n' => 1, 'id' => 7, 'plazo' => 1000000 + 3 * 86400],
    ['n' => 2, 'id' => 8, 'plazo' => 1000000 + 86400]]] + $base);
local_chatbot_verificar($a['key'] === 'plazo' && $a['a']['n'] === 2 && $a['anchor'] === 'meta-8',
    'de dos plazos cercanos avisa el que vence primero');
local_chatbot_verificar(journey::next_action(['deadlines' => [['n' => 1, 'id' => 7, 'plazo' => 1000000 + 30 * 86400]]]
    + $base)['key'] === 'proyecto', 'un plazo lejano no desplaza al proyecto');
local_chatbot_verificar(journey::next_action(['deadlines' => [['n' => 1, 'id' => 7, 'plazo' => 1000000 - 86400]]]
    + $base)['key'] === 'proyecto', 'un plazo ya vencido no se anuncia como proximo');
local_chatbot_verificar(journey::next_action($base)['key'] === 'proyecto', 'plan al dia: el proyecto');
local_chatbot_verificar(journey::next_action(['project' => true] + $base)['key'] === 'cierre', 'proyecto hecho: el cierre');
$a = journey::next_action(['project' => true, 'closure' => true] + $base);
local_chatbot_verificar($a['key'] === 'completo' && $a['step'] === 0, 'todo hecho: ruta completa, sin destino');
local_chatbot_verificar(journey::next_action(['supports' => [['n' => 1, 'id' => 5, 'kind' => '']]] + $base)['key'] === 'proyecto',
    'un apoyo que no espera a la persona no se anuncia');

// --- Proyecto antes que el PDP (ajustes del cliente del 08-10-2026) ---
$primero = ['project_first' => true] + $base;
local_chatbot_verificar(journey::next_action(['proposed' => 3] + $primero)['key'] === 'proyecto',
    'con el proyecto primero, un proyecto pendiente va antes que las propuestas del plan');
local_chatbot_verificar(journey::next_action(['inplan' => 0, 'plan_validated' => false] + $primero)['key'] === 'proyecto',
    'con el proyecto primero, no se pide armar el plan antes del proyecto');
local_chatbot_verificar(journey::next_action(['project' => true, 'proposed' => 2] + $primero)['key'] === 'propuestas',
    'con el proyecto hecho, se sugiere el plan');
local_chatbot_verificar(journey::next_action(['project' => true] + $primero)['key'] === 'cierre',
    'con el proyecto hecho y el plan al dia, se sugiere el cierre');
local_chatbot_verificar(journey::next_action(['diagnostic' => false] + $primero)['key'] === 'diagnostico',
    'el diagnostico sigue antes que el proyecto');

// --- Respuestas en vivo: lectura del flujo ---
$buffer = "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Hola\"}]}}]}\n\ndata: {\"candidates\"";
$eventos = stream::parse_sse($buffer);
local_chatbot_verificar(count($eventos) === 1 && $eventos[0]['candidates'][0]['content']['parts'][0]['text'] === 'Hola',
    'un evento completo se lee');
local_chatbot_verificar($buffer === 'data: {"candidates"', 'lo incompleto queda esperando en el buffer');
$buffer .= ":[]}\r\n\r\n";
local_chatbot_verificar(count(stream::parse_sse($buffer)) === 1 && $buffer === '', 'al completarse se lee, tambien con CRLF');
$buffer = "data: no-es-json\n\n";
local_chatbot_verificar(stream::parse_sse($buffer) === [], 'un evento ilegible se descarta');

// --- Respuestas en vivo: liberacion por frases ---
local_chatbot_verificar(stream::release_point('Hola. Esto sigue', 0, false) === 5, 'se libera hasta el fin de la frase completa');
local_chatbot_verificar(stream::release_point('Una frase sin terminar', 0, false) === 0, 'una frase sin terminar no se libera');
local_chatbot_verificar(stream::release_point('El valor 3.5 sube', 0, false) === 0, 'un punto decimal no corta la frase');
local_chatbot_verificar(stream::release_point("Linea uno\nlinea dos", 0, false) === 10, 'un salto de linea cierra la frase');
local_chatbot_verificar(stream::release_point('¿Listo? Si', 0, false) === strlen('¿Listo?'), 'el signo de interrogacion cierra la frase (UTF-8)');
local_chatbot_verificar(stream::release_point('Hola. Mundo. Fin', 6, false) === 12, 'parte desde lo ya liberado');
local_chatbot_verificar(stream::release_point('Hola. Mundo', 6, false) === 6, 'sin frase nueva no avanza');
local_chatbot_verificar(stream::release_point('Todo el resto', 0, true) === 13, 'al terminar se libera todo');
$texto = 'Primera frase. La respuesta correcta es la b. Fin';
$corte = stream::release_point(substr($texto, 0, 24), 0, false);
local_chatbot_verificar($corte === 14 && strpos(substr($texto, 0, $corte), 'correcta') === false,
    'mientras la frase sensible no termina, no se muestra nada de ella');

// --- Valoracion ---
local_chatbot_verificar(feedback::normalize(1, 'larga') === ['vote' => 1, 'reason' => null], 'un voto util no lleva motivo');
local_chatbot_verificar(feedback::normalize(-1, 'larga') === ['vote' => -1, 'reason' => 'larga'], 'un voto negativo lleva su motivo');
local_chatbot_verificar(feedback::normalize(-1, 'texto libre de la persona') === ['vote' => -1, 'reason' => null],
    'un motivo fuera de la lista se descarta: no se guarda texto de la persona');
local_chatbot_verificar(feedback::normalize(0, '') === null && feedback::normalize(5, '') === null, 'un voto que no es 1 ni -1 se rechaza');
local_chatbot_verificar(feedback::summary(0, 0) === 'Sin valoraciones', 'sin votos lo dice');
local_chatbot_verificar(feedback::summary(3, 1) === '3 de 4 (75%)', 'resumen de votos utiles');

// --- Contrasenas autocompletadas por el navegador ---
local_chatbot_verificar(guardrails::looks_like_password('Ejemplo.Clave2026!'), 'una contrasena tipica se reconoce');
local_chatbot_verificar(guardrails::looks_like_password('  Prueba.2026!  '), 'con espacios alrededor tambien');
local_chatbot_verificar(!guardrails::looks_like_password('Diseñar una actividad práctica para mi curso'), 'una meta real no se bloquea');
local_chatbot_verificar(!guardrails::looks_like_password('U1-LF-01'), 'un codigo del Anexo G no se bloquea');
local_chatbot_verificar(!guardrails::looks_like_password('¿Qué es un criterio de desempeño?'), 'una pregunta no se bloquea');
local_chatbot_verificar(!guardrails::looks_like_password('Revisar'), 'una palabra corta no se bloquea');
local_chatbot_verificar(!guardrails::looks_like_password('https://Sitio.cl/a1'), 'un enlace no se bloquea');
local_chatbot_verificar(!guardrails::looks_like_password('persona@Correo1.cl'), 'un correo no se trata como contrasena');
local_chatbot_verificar(!guardrails::looks_like_password('Evidencia2026'), 'sin simbolo no se bloquea');
local_chatbot_verificar(!guardrails::looks_like_password('meta.2026!'), 'sin mayuscula no se bloquea');

// --- Mi avance por meta (M1-v1.14.0) ---
$meta = (object) ['timecreated' => 100, 'timemodified' => 900, 'status' => pdp::GOAL_ACEPTADO];
$apoyos = [['timegenerated' => 200, 'timestarted' => 300, 'reviews' => [
    ['timedelivered' => 400, 'reviewstatus' => 'requiere_profundizacion', 'strength' => 'Situación clara',
        'aspecttodeepen' => 'Falta la evidencia observable', 'rejected' => 0],
    ['timedelivered' => 350, 'reviewstatus' => 'rechazada', 'strength' => '', 'aspecttodeepen' => '', 'rejected' => 1],
    ['timedelivered' => 600, 'reviewstatus' => 'avance_registrado', 'strength' => 'Evidencia observable definida',
        'aspecttodeepen' => '', 'rejected' => 0],
]]];
$av = pdp::goal_progress($meta, $apoyos);
local_chatbot_verificar(array_column($av['events'], 'key') === ['creada', 'apoyo_propuesto', 'apoyo_iniciado',
    'evidencia_rechazada', 'evidencia', 'evidencia'], 'la linea de tiempo va en orden de fecha');
local_chatbot_verificar($av['evidences'] === 2, 'una evidencia rechazada no cuenta como entregada');
local_chatbot_verificar($av['logro'] === 'Evidencia observable definida' && $av['estado'] === 'avance_registrado',
    'lo logrado sale de la ultima revision valida');
local_chatbot_verificar($av['falta'] === null, 'sin nada por profundizar no se inventa');
$av = pdp::goal_progress((object) ['timecreated' => 100, 'timemodified' => 500, 'status' => pdp::GOAL_COMPLETADO], []);
local_chatbot_verificar(array_column($av['events'], 'key') === ['creada', 'completada'] && $av['evidences'] === 0
    && $av['logro'] === null, 'una meta completada sin apoyos muestra creacion y cierre');

// --- Recordatorios ---
$dia = 86400;
$ahora = 10000000;
$hechos = [
    'goals' => [['n' => 1, 'id' => 5, 'plazo' => $ahora + 2 * $dia], ['n' => 2, 'id' => 6, 'plazo' => $ahora + 10 * $dia],
        ['n' => 3, 'id' => 7, 'plazo' => $ahora - $dia]],
    'supports' => [['n' => 1, 'id' => 9, 'waiting' => true, 'idle_since' => $ahora - 4 * $dia],
        ['n' => 2, 'id' => 10, 'waiting' => true, 'idle_since' => $ahora - $dia],
        ['n' => 3, 'id' => 11, 'waiting' => false, 'idle_since' => $ahora - 9 * $dia]],
    'proposals' => [['id' => 1, 'since' => $ahora - 3 * $dia], ['id' => 2, 'since' => $ahora - 3 * $dia],
        ['id' => 3, 'since' => $ahora - 3600]],
];
$avisos = reminders::due($hechos, $ahora, []);
local_chatbot_verificar(array_column($avisos, 'key') === ['plazo-5', 'apoyo-9', 'propuestas'],
    'avisa el plazo cercano, el apoyo detenido y las propuestas viejas; nada mas');
local_chatbot_verificar($avisos[2]['a'] === 2, 'cuenta solo las propuestas que llevan dias sin revisar');
$enviados = reminders::record([], $avisos, $ahora);
local_chatbot_verificar(reminders::due($hechos, $ahora + $dia, $enviados) === [], 'al dia siguiente no repite lo mismo');
local_chatbot_verificar(count(reminders::due($hechos, $ahora + 3 * $dia, $enviados)) >= 1,
    'pasados los dias de espera vuelve a recordar lo que siga pendiente');
local_chatbot_verificar(reminders::due(['goals' => [], 'supports' => [], 'proposals' => []], $ahora, []) === [],
    'sin pendientes no hay recordatorio');
local_chatbot_verificar(!array_key_exists('viejo', reminders::record(['viejo' => $ahora - 90 * $dia], [], $ahora)),
    'el registro de avisos no crece sin fin');

// --- Conversacion por meta ---
local_chatbot_verificar(thread::where(0) === ['goalid IS NULL', []], 'el chat general son los mensajes sin meta');
local_chatbot_verificar(thread::where(42) === ['goalid = :threadgoal', ['threadgoal' => 42]], 'un hilo son los mensajes de esa meta');

// --- Tildes del instrumento por defecto ---
$tildes = seeder::accent_fixes();
local_chatbot_verificar(isset($tildes['local_chatbot_dimension']['Evidencias e instrumentos de evaluacion'])
    && $tildes['local_chatbot_dimension']['Evidencias e instrumentos de evaluacion'] === 'Evidencias e instrumentos de evaluación',
    'la correccion de tildes cubre las dimensiones');
$nombres = array_column(seeder::default_dimensions(), 'name');
local_chatbot_verificar(count(array_intersect($nombres, array_keys($tildes['local_chatbot_dimension']))) === 0,
    'una instalacion nueva ya siembra los nombres con tilde');


// --- Perfilamiento segun el acta del 13-10-2026 (v1.16.8) ---
$o = profile::get_options();
local_chatbot_verificar(count($o['rol']) === 5 && count($o['programa']) === 5 && count($o['modalidad']) === 3
    && count($o['experiencia']) === 5 && count($o['familiaridad']) === 5 && count($o['fortalecer']) === 8
    && count($o['tiempo']) === 4, 'el perfil tiene las alternativas del acta');
local_chatbot_verificar(profile::legacy_map('rol', 'relator') === 'docente_aula'
    && profile::legacy_map('experiencia', 'entre3y10') === 'entre4y7' && profile::legacy_map('modalidad', 'mixta') === 'hibrida'
    && profile::legacy_map('tiempo', 'entre3y4') === 'entre3y5', 'las respuestas antiguas pasan a la alternativa vigente');
local_chatbot_verificar(profile::legacy_map('rol', 'coordinador') === 'coordinador', 'una alternativa vigente no cambia');
$ok = ['rol' => 'coordinador', 'programa' => 'oficios', 'modalidad' => 'virtual', 'fortalecer' => ['ia', 'plan']];
local_chatbot_verificar(profile::validate($ok) === [], 'un perfil completo pasa');
local_chatbot_verificar(profile::validate(['programa' => 'oficios', 'modalidad' => 'virtual']) === ['profile_error_rol'],
    'el rol es obligatorio');
local_chatbot_verificar(in_array('profile_error_rolotro', profile::validate(['rol' => 'otro'] + $ok), true),
    'Otro en rol exige especificar');
local_chatbot_verificar(profile::validate(['rol' => 'otro', 'rolotro' => 'Tutor de practica'] + $ok) === [],
    'Otro especificado pasa');
local_chatbot_verificar(in_array('profile_error_fortalecer', profile::validate(['fortalecer' => ['ia', 'plan', 'ucl']] + $ok), true),
    'no se pueden marcar mas de dos aspectos a fortalecer');
$d = profile::describe((object) ['rol' => 'otro', 'rolotro' => 'Tutor de practica', 'programa' => '', 'contexto' => 'OTEC',
    'fortalecer' => '', 'necesidad' => 'evaluar en taller', 'aplicacion' => 'nr', 'experiencia' => 'nr', 'tiempo' => 'menos1']);
local_chatbot_verificar($d['rol'] === '[[rol_otro]]: Tutor de practica' && $d['programa'] === 'OTEC'
    && $d['fortalecer'] === ['evaluar en taller'] && $d['aplicacion'] === null && $d['experiencia'] === null,
    'el perfil en palabras: Otro especificado, y lo de perfiles anteriores se conserva');

// Cursos sin asistente (ajuste nochatcourses): lista de ids; el curso del sitio nunca se oculta.
local_chatbot_verificar(\local_chatbot\ux::is_listed(18, '18,19') && \local_chatbot\ux::is_listed(19, '18,19'),
    'un curso de la lista se oculta');
local_chatbot_verificar(!\local_chatbot\ux::is_listed(20, '18,19') && !\local_chatbot\ux::is_listed(6, ''),
    'un curso fuera de la lista, o con la lista vacia, se ve');
local_chatbot_verificar(\local_chatbot\ux::is_listed(4, ' 4 ; 5 ,6') && \local_chatbot\ux::is_listed(5, '4 5'),
    'la lista admite coma, punto y coma y espacios');
local_chatbot_verificar(!\local_chatbot\ux::is_listed(1, '1,18') && !\local_chatbot\ux::is_listed(0, '0,18'),
    'el curso del sitio y el id 0 nunca se ocultan');
local_chatbot_verificar(!\local_chatbot\ux::is_listed(18, '180,118,x18'), 'solo cuenta el id exacto');
// Lista de cursos de un ajuste (la usan los indicadores): ids validos, sin repetir y en el orden escrito.
local_chatbot_verificar(\local_chatbot\closure::parse_course_ids('20, 18;19 20') === [20, 18, 19], 'la lista de cursos admite separadores y no repite');
local_chatbot_verificar(\local_chatbot\closure::parse_course_ids('') === [] && \local_chatbot\closure::parse_course_ids('  ') === [], 'una lista vacia da cero cursos');
local_chatbot_verificar(\local_chatbot\closure::parse_course_ids('0,1,x,-5,7') === [7], 'el sitio, el texto y los negativos se descartan');
// Indicadores: embudo con la ruta vigente (6 etapas) y el criterio de la barra de ruta.
$ruta6 = ['perfil', 'diagnostico', 'ruta', 'proyecto', 'pdp', 'cierre'];
$estados = [
    1 => ['perfil' => true, 'diagnostico' => true, 'ruta' => true, 'proyecto' => false, 'pdp' => true, 'cierre' => false],
    2 => ['perfil' => true, 'diagnostico' => true, 'ruta' => false, 'proyecto' => false, 'pdp' => false, 'cierre' => false],
    3 => ['perfil' => false, 'diagnostico' => true, 'ruta' => false, 'proyecto' => false, 'pdp' => false, 'cierre' => false],
    4 => ['perfil' => false, 'diagnostico' => false, 'ruta' => false, 'proyecto' => false, 'pdp' => false, 'cierre' => false],
];
$embudo = \local_chatbot\analytics::funnel_counts($estados, $ruta6);
local_chatbot_verificar($embudo['iniciaron'] === 3, 'iniciaron son quienes tienen al menos una etapa (sin perfil vigente tambien cuenta)');
local_chatbot_verificar($embudo['etapas']['diagnostico'] === 3 && $embudo['etapas']['perfil'] === 2
    && $embudo['etapas']['ruta'] === 1 && $embudo['etapas']['cierre'] === 0, 'cada etapa cuenta a quien la tiene hecha');
local_chatbot_verificar(max($embudo['etapas']) <= $embudo['iniciaron'], 'ninguna etapa supera el 100 % de quienes iniciaron');
local_chatbot_verificar(array_keys($embudo['etapas']) === $ruta6, 'el embudo sigue el orden de la ruta vigente, con la Ruta Formativa Base');
$semana = 7 * 24 * 3600;
local_chatbot_verificar(\local_chatbot\analytics::cumulative_weeks([0, 50, 1000 + $semana, 1000 + 5 * $semana], 1000, 3) === [2, 3, 3],
    'el acumulado semanal pone lo anterior o sin fecha en el punto de partida y descarta lo posterior');
// «Solo en estos cursos» (chatonlycourses): manda cuando tiene algo y deja fuera lo que no este en la lista.
local_chatbot_verificar(!\local_chatbot\ux::is_hidden(6, '', '6') && \local_chatbot\ux::is_hidden(4, '', '6')
    && \local_chatbot\ux::is_hidden(99, '', '6'), 'con lista unica, solo ese curso muestra el asistente');
local_chatbot_verificar(!\local_chatbot\ux::is_hidden(4, '', '') && !\local_chatbot\ux::is_hidden(4, '', '  '),
    'sin lista unica no se restringe nada');
local_chatbot_verificar(\local_chatbot\ux::is_hidden(6, '6', '6'), 'una exclusion expresa gana sobre la lista unica');
local_chatbot_verificar(\local_chatbot\ux::is_hidden(0, '', '6') && \local_chatbot\ux::is_hidden(1, '', '6'),
    'con lista unica, fuera de los cursos (Area personal, sitio) tambien se oculta');
local_chatbot_verificar(!\local_chatbot\ux::is_hidden(0, '18,19', '') && !\local_chatbot\ux::is_hidden(1, '18', ''),
    'sin lista unica, fuera de los cursos nunca se oculta');

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Mejoras de experiencia conformes: interruptores, proximo paso, respuestas en vivo y valoracion.\n";
    exit(0);
}
echo "Mejoras de experiencia NO conformes.\n";
exit(1);
