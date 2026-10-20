<?php
/**
 * Verifica la matriz de desarrollo del Coach (RU-COA-OUT-001) y las reglas que
 * se le imponen al modelo en codigo.
 *
 * La premisa que se prueba es que ninguna de estas reglas depende de que el
 * modelo se porte bien: se le entrega una respuesta que las viola todas a la
 * vez —prioridades que no son candidatas, repetidas, de mas, incompletas,
 * recursos inventados, plazos absurdos, un pipe que romperia la tabla— y se
 * comprueba que lo que queda es conforme.
 *
 *   RU-COA-PRIOR-002/003  solo candidatas, en orden, una por dimension
 *   RU-COA-003            tope de prioridades
 *   RU-COA-COMPLET-001    ninguna meta sobre una dimension preliminar
 *   RU-COA-LIM-001        ningun recurso fuera del catalogo
 *   RU-COA-PDP-FLOW-001   meta = verbo + capacidad + contexto, con evidencia
 *   RU-COA-OUT-001        estructura de la plantilla de Doc 3 §9.1
 *
 * Uso:  php cli/verificar_coach.php
 * Sale con codigo 1 ante cualquier caso fallido, para encadenarlo a CI.
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/diagnostic.php');
require_once(__DIR__ . '/../classes/pdp.php');
require_once(__DIR__ . '/../classes/coach_context.php');
require_once(__DIR__ . '/../classes/coach_report.php');

use local_chatbot\coach_context;
use local_chatbot\coach_report;
use local_chatbot\diagnostic;

$fallidos = 0;
$casos = 0;

/**
 * @param bool $condicion
 * @param string $desc
 * @return void
 */
function local_chatbot_verificar(bool $condicion, string $desc): void {
    global $fallidos, $casos;
    $casos++;
    if (!$condicion) {
        echo "  FALLA  {$desc}\n";
        $fallidos++;
    }
}

$fila = function(string $code, string $name, float $pc, bool $prelim) {
    return ['code' => $code, 'name' => $name, 'pc' => $pc, 'preliminar' => $prelim,
        'nivel' => diagnostic::level_for_score($pc), 'completitud' => $prelim ? 60 : 100];
};
$matriz = [
    $fila('a', 'Referentes', 2.0, false),
    $fila('b', 'Aprendizajes', 3.0, false),
    $fila('c', 'Diseno', 1.8, true),
    $fila('d', 'Evaluacion', 4.6, false),
];
$candidatas = ['a', 'b'];
$catalogo = ['Unidad 2 - Lectura', 'Pauta de cotejo'];

$meta = function(string $code, array $extra = []) {
    return array_merge(['codigo' => $code, 'meta_verbo' => 'disenar', 'meta_capacidad' => 'criterios de desempeno',
        'meta_contexto' => 'para tu curso de soldadura', 'evidencia_avance' => 'Tres criterios redactados',
        'apoyo_inicial' => 'Revisa el ejemplo resuelto', 'recurso' => '', 'plazo_semanas' => 3], $extra);
};

$respuesta = [
    'sintesis' => '  Tienes   una base   solida en evaluacion. ',
    'ambitos' => [
        ['codigo' => 'a', 'que_observamos' => 'Partes por los contenidos | no por el referente',
            'apoyo_sugerido' => 'Lectura', 'recurso' => 'unidad 2 – LECTURA', 'accion_inicial' => 'Identifica la UCL'],
        ['codigo' => 'x', 'que_observamos' => 'No existe esta dimension'],
        ['codigo' => 'b', 'recurso' => 'Recurso inventado'],
    ],
    'prioridades' => [
        $meta('c'),                                  // Preliminar: no es candidata.
        $meta('d'),                                  // Consolidada: no es candidata.
        $meta('a', ['recurso' => 'Pauta de cotejo', 'plazo_semanas' => 0]),
        $meta('a'),                                  // Repetida.
        $meta('b', ['meta_capacidad' => '']),        // Incompleta.
        $meta('b', ['recurso' => 'Un video que invente', 'plazo_semanas' => 40]),
    ],
];

$r = coach_report::validate_payload($respuesta, $matriz, $candidatas, 3, $catalogo);

// Ambitos: exactamente uno por dimension con resultado.
local_chatbot_verificar(array_keys($r['ambitos']) === ['a', 'b', 'c', 'd'],
    'ambitos: uno por dimension de la matriz, sin la inventada');
local_chatbot_verificar($r['ambitos']['a']['recurso'] === 'Unidad 2 - Lectura',
    'catalogo: una variante de mayusculas, tildes o guiones resuelve al nombre exacto');
local_chatbot_verificar($r['ambitos']['b']['recurso'] === '',
    'catalogo: un recurso inventado no se guarda (RU-COA-LIM-001)');
local_chatbot_verificar($r['sintesis'] === 'Tienes una base solida en evaluacion.',
    'texto: se normalizan espacios');

// Prioridades.
$codigos = array_column($r['prioridades'], 'codigo');
local_chatbot_verificar($codigos === ['a', 'b'],
    'prioridades: solo candidatas, una por dimension, sin incompletas (quedan a,b; salio ' . implode(',', $codigos) . ')');
local_chatbot_verificar($r['prioridades'][0]['meta'] === 'Diseñar criterios de desempeno para tu curso de soldadura.',
    'meta: verbo + capacidad + contexto, con mayuscula inicial, punto final y verbo con tilde');
local_chatbot_verificar($r['prioridades'][0]['microcapsula'] === '',
    'meta: no se guardan microcapsulas (SPEC v5, RU-COA-003)');
// Contextos sin conector, tal como los devolvio el modelo en la prueba del 21-09-2026.
local_chatbot_verificar(coach_report::compose_goal('Diseñar', 'actividades a partir de manuales de procedimientos',
    'talleres presenciales de bodega') === 'Diseñar actividades a partir de manuales de procedimientos en talleres presenciales de bodega.',
    'meta: contexto sin preposicion recibe "en"');
local_chatbot_verificar(coach_report::compose_goal('registrar', 'reflexiones estructuradas', 'el cierre de cada taller')
    === 'Registrar reflexiones estructuradas en el cierre de cada taller.',
    'meta: contexto que empieza con articulo recibe "en"');
local_chatbot_verificar(coach_report::compose_goal('disenar', 'actividades', 'en el programa mixto')
    === 'Diseñar actividades en el programa mixto.',
    'meta: contexto con preposicion no se duplica');
local_chatbot_verificar(coach_report::compose_goal('Planificar', 'simulaciones', 'durante los talleres virtuales.')
    === 'Planificar simulaciones durante los talleres virtuales.',
    'meta: "durante" es conector valido y no se duplica el punto final');
local_chatbot_verificar($r['prioridades'][0]['recurso'] === 'Pauta de cotejo',
    'prioridad: recurso del catalogo se conserva');
local_chatbot_verificar($r['prioridades'][1]['recurso'] === '',
    'prioridad: recurso inventado se descarta');
local_chatbot_verificar($r['prioridades'][0]['plazo_semanas'] === 4,
    'plazo: cero se reemplaza por el valor por defecto');
local_chatbot_verificar($r['prioridades'][1]['plazo_semanas'] === 12,
    'plazo: se recorta a 12 semanas');

// Tope de una sola prioridad (dos o mas dimensiones incompletas).
$uno = coach_report::validate_payload($respuesta, $matriz, $candidatas, 1, $catalogo);
local_chatbot_verificar(count($uno['prioridades']) === 1,
    'tope: con limite 1 queda una sola prioridad (RU-COA-COMPLET-001)');

// Respuesta vacia o rota: no revienta, devuelve estructura vacia pero valida.
$vacia = coach_report::validate_payload([], $matriz, $candidatas, 3, $catalogo);
local_chatbot_verificar(count($vacia['ambitos']) === 4 && $vacia['prioridades'] === [],
    'respuesta vacia: estructura completa, sin prioridades');

// Plantilla de Doc 3 §9.1.
$md = coach_report::render(['fecha' => '11/09/2026', 'version' => 'DIAG-M1-v1.0', 'estado' => 'inicial'],
    $r, $matriz);
local_chatbot_verificar(strpos($md, 'Tu matriz de desarrollo') !== false
    && strpos($md, 'DIAG-M1-v1.0') !== false && strpos($md, 'inicial') !== false,
    'plantilla: encabezado con fecha, version y estado');
local_chatbot_verificar(strpos($md, '| Ámbito | Nivel | Qué observamos | Apoyo sugerido | Acción inicial |') !== false,
    'plantilla: tabla con las cinco columnas de Doc 3');
$filasdatos = array_filter(explode("\n", $md), function(string $linea): bool {
    return strpos($linea, '| ') === 0 && strpos($linea, '| Ámbito') !== 0;
});
local_chatbot_verificar(count($filasdatos) === count($matriz),
    'plantilla: una fila de datos por dimension (hay ' . count($filasdatos) . ')');
local_chatbot_verificar(strpos($md, 'Diseno | Prioridad alta (preliminar)') !== false,
    'plantilla: la dimension preliminar se marca como tal');
local_chatbot_verificar(strpos($md, 'contenidos / no por el referente') !== false,
    'plantilla: un pipe en el texto no rompe la tabla');
local_chatbot_verificar(strpos($md, 'Tus prioridades iniciales') !== false
    && strpos($md, '**Meta sugerida:**') !== false && strpos($md, '**Evidencia de avance:**') !== false,
    'plantilla: bloque de prioridades con meta, apoyo y evidencia');
local_chatbot_verificar(strpos($md, '¿Quieres mantener estas prioridades, ajustar alguna o incorporar otra necesidad?') !== false,
    'plantilla: pregunta de cierre pidiendo la decision de la persona');
local_chatbot_verificar(strpos($md, 'no una calificación') !== false,
    'plantilla: advertencia de que el nivel no es calificacion (RU-COA-NIVEL-001)');

$sinprioridades = coach_report::render(['fecha' => '', 'version' => '', 'estado' => ''], $vacia, $matriz);
local_chatbot_verificar(strpos($sinprioridades, 'no hay una prioridad') !== false,
    'plantilla: sin prioridades lo dice, en vez de dejar el bloque vacio');

// Catalogo.
local_chatbot_verificar(coach_context::match_catalog('', $catalogo) === '',
    'catalogo: nombre vacio no calza con nada');
local_chatbot_verificar(coach_context::match_catalog('Pauta de cotejo extendida', $catalogo) === '',
    'catalogo: un nombre parecido pero distinto no calza');

// Matriz de desarrollo al dia con el diagnostico (v1.16.5, interruptor diagrefresh).
$huella = diagnostic::matrix_fingerprint($matriz);
local_chatbot_verificar($huella !== '' && $huella === diagnostic::matrix_fingerprint(array_reverse($matriz)),
    'huella: el mismo resultado da la misma huella, sin importar el orden de las filas');
local_chatbot_verificar(diagnostic::matrix_fingerprint([]) === '', 'huella: sin resultado no hay huella');
$otra = $matriz;
$otra[1] = $fila('b', 'Aprendizajes', 3.4, false);
local_chatbot_verificar(diagnostic::matrix_fingerprint($otra) !== $huella,
    'huella: si cambia un puntaje, cambia la huella');
$otra = $matriz;
$otra[2] = $fila('c', 'Diseno', 1.8, false);
local_chatbot_verificar(diagnostic::matrix_fingerprint($otra) !== $huella,
    'huella: si una dimension deja de ser preliminar, cambia la huella');
$otra = $matriz;
$otra[0]['name'] = 'Referentes de trabajo';
local_chatbot_verificar(diagnostic::matrix_fingerprint($otra) === $huella,
    'huella: renombrar una dimension no la cambia (la matriz no se rehace por eso)');
local_chatbot_verificar(!coach_report::is_stale(['huella' => $huella], $matriz),
    'matriz de desarrollo hecha con el resultado actual: al dia');
local_chatbot_verificar(coach_report::is_stale(['huella' => $huella], $otra) === false
    && coach_report::is_stale(['huella' => $huella], [$fila('a', 'Referentes', 4.0, false)] + $matriz),
    'matriz de desarrollo hecha con otro resultado: desactualizada');
local_chatbot_verificar(!coach_report::is_stale([], $matriz),
    'matriz anterior a la huella: no se da por vieja (se renueva cuando cambian las respuestas)');

printf("Casos verificados: %d | Fallidos: %d\n", $casos, $fallidos);

if ($fallidos === 0) {
    echo "Matriz de desarrollo conforme a Doc 3 (RU-COA-OUT-001) y a sus reglas de seleccion.\n";
    exit(0);
}
echo "Matriz de desarrollo NO conforme: revisar coach_report::validate_payload y render.\n";
exit(1);
