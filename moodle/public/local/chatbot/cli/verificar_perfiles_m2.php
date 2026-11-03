<?php
/**
 * Verifica el informe «Perfiles en el Modulo 2» (pedido del cliente del 03-11-2026): comportamiento de una persona en el
 * Modulo 2 a partir de los hechos del tablero, grupos por respuesta del perfil del Modulo 1, minimo de personas por
 * grupo, conclusiones y la cohorte de demostracion.
 *
 * Uso:  php cli/verificar_perfiles_m2.php
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/guardrails.php');
require_once(__DIR__ . '/../classes/exante.php');
require_once(__DIR__ . '/../classes/diseno.php');
require_once(__DIR__ . '/../classes/tablero_m2.php');
require_once(__DIR__ . '/../classes/profile.php');
require_once(__DIR__ . '/../classes/perfiles_m2.php');

use local_chatbot\diseno;
use local_chatbot\exante;
use local_chatbot\perfiles_m2;
use local_chatbot\tablero_m2;

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

const DIA = 86400;
const T0 = 1798000000;
$plazos = ['correccion' => 7, 'detenido' => 7];

/**
 * Items del checklist: todos ✔ salvo los indicados.
 *
 * @param array $cambios id => estado.
 * @return array
 */
function local_chatbot_items(array $cambios = []): array {
    $items = [];
    foreach (exante::checklist_ids() as $id) {
        $items[$id] = ['estado' => $cambios[$id] ?? exante::CUMPLE, 'observacion' => ''];
    }
    return $items;
}

/**
 * Persona sintetica para los cruces: perfil y metricas (las que no se dan quedan en null).
 *
 * @param array|null $perfil
 * @param array $metricas
 * @return array
 */
function local_chatbot_persona(?array $perfil, array $metricas): array {
    return ['perfil' => $perfil, 'metricas' => $metricas + array_fill_keys(array_keys(perfiles_m2::METRICAS), null)];
}

// --- Respuestas del perfil como grupos ---
local_chatbot_verificar(perfiles_m2::answers(null, 'rol') === [perfiles_m2::SIN_PERFIL], 'sin perfil: grupo «sin perfil»');
local_chatbot_verificar(perfiles_m2::answers(['rol' => ''], 'rol') === [perfiles_m2::SIN_RESPUESTA], 'respuesta vacia: «sin respuesta»');
local_chatbot_verificar(perfiles_m2::answers(['rol' => 'instructor_taller'], 'rol') === ['instructor_taller'], 'una respuesta, un grupo');
local_chatbot_verificar(perfiles_m2::answers(['fortalecer' => 'evaluacion, ia'], 'fortalecer') === ['evaluacion', 'ia'],
    'dos aspectos a fortalecer: la persona cuenta en ambos grupos');
local_chatbot_verificar(perfiles_m2::answers(['rol' => 'astronauta'], 'rol') === [perfiles_m2::SIN_RESPUESTA],
    'una alternativa que no existe no inventa un grupo');
local_chatbot_verificar(perfiles_m2::DIMENSIONES === ['experiencia', 'familiaridad', 'rol', 'programa', 'modalidad', 'tiempo',
    'fortalecer'], 'las siete preguntas cerradas del perfil del acta del 13-10');
foreach (perfiles_m2::DIMENSIONES as $d) {
    local_chatbot_verificar(perfiles_m2::options($d) !== [], "la pregunta «{$d}» tiene sus alternativas");
}

// --- Agregados ---
local_chatbot_verificar(perfiles_m2::aggregate([true, false, true, true], 'pct') === 75, 'porcentaje de verdaderos');
local_chatbot_verificar(perfiles_m2::aggregate([5, 1, 3], 'mediana') === 3.0, 'mediana');
local_chatbot_verificar(perfiles_m2::aggregate([1, 2], 'promedio') === 1.5, 'promedio con un decimal');
local_chatbot_verificar(perfiles_m2::aggregate([], 'pct') === null, 'sin valores: sin dato');

// --- Comportamiento de una persona, desde los hechos del tablero ---
$sinficha = perfiles_m2::person([], T0, $plazos);
local_chatbot_verificar($sinficha['entrego'] === false && $sinficha['puntaje'] === null && $sinficha['aprob_primer'] === null,
    'inscrita sin ficha: no entrego, y lo demas no ocurrio (no cuenta en esos promedios)');

$v1 = ['id' => 101, 'estado' => exante::LISTO, 'condicion' => exante::NO_APROBADO, 'time' => T0 + DIA,
    'items' => local_chatbot_items([exante::CRITICOS[0] => exante::NO_CUMPLE])];
$v2 = ['id' => 102, 'estado' => exante::LISTO, 'condicion' => exante::APROBADO, 'time' => T0 + 4 * DIA,
    'items' => local_chatbot_items()];
$puntajes = ['r1' => 4, 'r2' => 3, 'r3' => 3, 'r4' => 3, 'r5' => 3, 'r6' => 3, 'r7' => 3, 'r8' => 3, 'r9' => 3];
$criterios = array_map(static function($p) {
    return ['puntaje' => $p, 'justificacion' => 'x'];
}, $puntajes);
$evaluacion = ['criterios' => $criterios, 'total' => 28, 'banda' => diseno::band(28), 'limitados' => [], 'maximo' => 36,
    'fecha' => T0 + 20 * DIA];
$completa = tablero_m2::facts(['ficha' => ['id' => 7, 'userid' => 10, 'organismo' => 'OTEC', 'activa' => 1],
    'validaciones' => [$v1, $v2],
    'envios' => [['completo' => false, 'faltantes' => ['x'], 'plan' => false, 'perfil' => false, 'time' => T0],
        ['completo' => true, 'faltantes' => [], 'plan' => true, 'perfil' => false, 'time' => T0 + 3 * DIA]],
    'etapas' => [1 => ['estado' => diseno::ACEPTADO, 'inicio' => T0 + 5 * DIA, 'cierre' => T0 + 6 * DIA, 'rondas' => 2,
            'validacionid' => 102],
        2 => ['estado' => diseno::ACEPTADO, 'inicio' => T0 + 6 * DIA, 'cierre' => T0 + 8 * DIA, 'rondas' => 1,
            'validacionid' => 102]],
    'aceptadas' => [1, 2], 'evaluacion' => $evaluacion, 'primer_acceso' => T0 - 2 * DIA,
    'vistas' => ['guia' => T0 - DIA], 'actividad' => T0 + 20 * DIA, 'encuesta' => ['p1' => 5, 'p2' => 4, 'p3' => 3, 'p4' => 4]]);
$p = perfiles_m2::person([$completa], T0 + 21 * DIA, $plazos);
local_chatbot_verificar($p['entrego'] === true && $p['dias_inicio'] === 2.0, 'entrego, dos dias despues de su primer ingreso');
local_chatbot_verificar($p['uso_guia'] === true, 'abrio la guia antes de entregar');
local_chatbot_verificar($p['completo'] === false, 'su primer envio estaba incompleto');
local_chatbot_verificar($p['aprob_primer'] === false && $p['ciclos'] === 2, 'No aprobado al primer ciclo; aprobo en el segundo');
local_chatbot_verificar($p['criticos'] === false, 'sin CRITICO observado en su ultima validacion (ya corrigio)');
local_chatbot_verificar($p['dias_correccion'] === 2.0, 'corrigio en dos dias (del ❌ al reenvio)');
local_chatbot_verificar($p['diseno'] === true && $p['rondas'] === 3, 'llego al diseno, con tres rondas de ajuste');
local_chatbot_verificar($p['termino'] === true && $p['puntaje'] === 28 && $p['encuesta'] === 4.0, 'evaluado: 28 puntos y percepcion 4');
local_chatbot_verificar($p['duracion'] === 20.0, 'veinte dias de la primera entrega a la evaluacion');
local_chatbot_verificar($p['detenido'] === false, 'termino: no esta detenida');

$singuia = perfiles_m2::person([array_merge($completa, ['vista_guia' => T0 + DIA, 'vista_ejemplo' => null])], T0, $plazos);
local_chatbot_verificar($singuia['uso_guia'] === false, 'abrir la guia despues de la primera entrega no cuenta');

$detenida = tablero_m2::facts(['ficha' => ['id' => 8, 'userid' => 11, 'organismo' => '', 'activa' => 1],
    'validaciones' => [$v2], 'envios' => [], 'etapas' => [1 => ['estado' => diseno::BORRADOR, 'inicio' => T0 + 5 * DIA,
        'cierre' => 0, 'rondas' => 0, 'validacionid' => 102]], 'aceptadas' => [], 'evaluacion' => null,
    'primer_acceso' => null, 'vistas' => [], 'actividad' => T0 + 5 * DIA, 'encuesta' => null]);
$d = perfiles_m2::person([$detenida], T0 + 30 * DIA, $plazos);
local_chatbot_verificar($d['detenido'] === true && $d['termino'] === false && $d['puntaje'] === null,
    'sin avanzar mas que el plazo: detenida, sin puntaje');
local_chatbot_verificar($d['dias_inicio'] === null, 'sin registro del primer ingreso: sin dias hasta entregar');
local_chatbot_verificar(perfiles_m2::main_ficha([$detenida, $completa])['fichaid'] === 7,
    'con dos fichas cuenta la mas avanzada');

// --- Cruce: grupos, minimo de personas y orden ---
$personas = [];
foreach (range(1, 5) as $i) {
    $personas[] = local_chatbot_persona(['experiencia' => 'mas7'], ['entrego' => true, 'aprob_primer' => true, 'puntaje' => 32]);
    $personas[] = local_chatbot_persona(['experiencia' => 'menos1'], ['entrego' => true, 'aprob_primer' => false, 'puntaje' => 22]);
}
$personas[] = local_chatbot_persona(['experiencia' => 'entre1y3'], ['entrego' => true, 'aprob_primer' => true]);
$personas[] = local_chatbot_persona(['experiencia' => 'entre1y3'], ['entrego' => false]);
foreach (range(1, 3) as $i) {
    $personas[] = local_chatbot_persona(['experiencia' => 'nr'], ['entrego' => true, 'aprob_primer' => true, 'puntaje' => 36]);
    $personas[] = local_chatbot_persona(null, ['entrego' => true, 'aprob_primer' => true, 'puntaje' => 36]);
}
$cruce = perfiles_m2::cross($personas, 'experiencia');
local_chatbot_verificar(array_keys($cruce) === ['menos1', 'entre1y3', 'mas7', 'nr', perfiles_m2::SIN_PERFIL],
    'grupos en el orden del formulario, con «sin perfil» al final');
local_chatbot_verificar($cruce['mas7']['n'] === 5 && $cruce['mas7']['celdas']['aprob_primer']['valor'] === 100
    && $cruce['menos1']['celdas']['aprob_primer']['valor'] === 0, 'porcentaje por grupo');
local_chatbot_verificar($cruce['entre1y3']['celdas']['entrego']['oculto'] === true
    && $cruce['entre1y3']['celdas']['entrego']['valor'] === null, 'grupo de dos personas: no se muestra');
local_chatbot_verificar($cruce['mas7']['celdas']['ciclos']['oculto'] === true,
    'sin el dato en nadie del grupo: no se muestra');
local_chatbot_verificar($cruce['mas7']['celdas']['puntaje']['valor'] === 32.0, 'promedio del puntaje del grupo');

// --- Colores: mejor y peor de cada columna, segun la direccion ---
$rangos = perfiles_m2::ranges($cruce);
local_chatbot_verificar(isset($rangos['aprob_primer']) && !isset($rangos['entrego']),
    'solo colorea columnas con al menos dos valores distintos');
local_chatbot_verificar(perfiles_m2::score(100, $rangos['aprob_primer'], 'aprob_primer') === 1.0
    && perfiles_m2::score(0, $rangos['aprob_primer'], 'aprob_primer') === 0.0, 'mas aprobacion, mejor');
local_chatbot_verificar(perfiles_m2::score(1, ['min' => 1, 'max' => 3], 'ciclos') === 1.0, 'menos ciclos, mejor');

// --- Conclusiones ---
$hl = perfiles_m2::highlights($personas);
$porm = array_column($hl, null, 'metrica');
local_chatbot_verificar(isset($porm['aprob_primer']) && $porm['aprob_primer']['dim'] === 'experiencia'
    && $porm['aprob_primer']['mejor']['respuesta'] === 'mas7' && $porm['aprob_primer']['peor']['respuesta'] === 'menos1',
    'conclusion: con mas de 7 anos aprueban al primer ciclo; con menos de 1, no');
local_chatbot_verificar(isset($porm['puntaje']) && $porm['puntaje']['mejor']['respuesta'] === 'mas7',
    'el mejor puntaje es de un perfil real: «prefiero no responder» y «sin perfil» no compiten');
local_chatbot_verificar(!isset($porm['entrego']), 'sin diferencia (todos entregaron) no hay conclusion');
local_chatbot_verificar(count($hl) === count(array_unique(array_column($hl, 'metrica'))), 'una conclusion por comportamiento');
$chica = [];
foreach (range(1, 5) as $i) {
    $chica[] = local_chatbot_persona(['experiencia' => 'mas7'], ['puntaje' => 26]);
    $chica[] = local_chatbot_persona(['experiencia' => 'menos1'], ['puntaje' => 25]);
}
local_chatbot_verificar(perfiles_m2::highlights($chica) === [], 'un punto de diferencia en el puntaje no es una conclusion');
local_chatbot_verificar(perfiles_m2::highlights(array_slice($personas, 0, 8)) === [],
    'con grupos de 4 personas se ven en la tabla, pero no dan conclusiones (minimo 5)');
$otro = [];
foreach (range(1, 5) as $i) {
    $otro[] = local_chatbot_persona(['rol' => 'otro'], ['aprob_primer' => false]);
    $otro[] = local_chatbot_persona(['rol' => 'coordinador'], ['aprob_primer' => true]);
}
local_chatbot_verificar(perfiles_m2::highlights($otro) === [], '«Otro» no es un perfil que se pueda describir: no compite');

// --- Claridad: todo el curso como referencia, mejor / parecido / peor, y el resumen de un perfil ---
$curso = perfiles_m2::overall($personas);
local_chatbot_verificar($curso['n'] === 18 && $curso['celdas']['aprob_primer']['valor'] === 71,
    'todo el curso: 12 de 17 aprueban al primer intento (71 %)');
local_chatbot_verificar(perfiles_m2::compare(100, 65, 'aprob_primer') === 'mejor'
    && perfiles_m2::compare(0, 65, 'aprob_primer') === 'peor' && perfiles_m2::compare(70, 65, 'aprob_primer') === 'igual',
    'mas aprobacion es mejor; cinco puntos de diferencia es «parecido»');
local_chatbot_verificar(perfiles_m2::compare(90, 40, 'detenido') === 'peor' && perfiles_m2::compare(3.0, 5.0, 'dias_inicio') === 'mejor',
    'mas detenidos es peor; menos dias para entregar es mejor');
local_chatbot_verificar(perfiles_m2::compare(null, 65, 'aprob_primer') === '', 'sin dato no se compara');
$dif = perfiles_m2::differences($cruce['menos1'], $curso);
local_chatbot_verificar($dif && $dif[0]['metrica'] === 'aprob_primer' && $dif[0]['sentido'] === 'peor',
    'resumen de «menos de 1 año»: lo mas marcado es que aprueban menos al primer intento');
local_chatbot_verificar(!in_array('entrego', array_column($dif, 'metrica'), true), 'lo que es igual al curso no se menciona');
$solodias = perfiles_m2::differences(['celdas' => ['dias_inicio' => ['valor' => 9.0], 'detenido' => ['valor' => 60]]
    + array_fill_keys(array_keys(perfiles_m2::METRICAS), ['valor' => null])],
    ['celdas' => ['dias_inicio' => ['valor' => 3.0], 'detenido' => ['valor' => 45]]
    + array_fill_keys(array_keys(perfiles_m2::METRICAS), ['valor' => null])]);
local_chatbot_verificar(array_column($solodias, 'metrica') === ['detenido', 'dias_inicio'],
    'en el resumen va primero un comportamiento clave (detenido) que un detalle mas marcado (dias)');
local_chatbot_verificar(perfiles_m2::out_of_ten(91) === 9 && perfiles_m2::out_of_ten(4) === 0 && perfiles_m2::out_of_ten(null) === null,
    'porcentaje dicho como «X de cada 10»');
local_chatbot_verificar(array_diff(perfiles_m2::CLAVE, array_keys(perfiles_m2::METRICAS)) === [] && count(perfiles_m2::CLAVE) === 6,
    'los 6 comportamientos clave existen');

// --- Cifras de cabecera ---
$t = perfiles_m2::totals($personas);
local_chatbot_verificar($t['inscritos'] === 18 && $t['con_perfil'] === 15 && $t['entregaron'] === 17,
    'inscritos, con perfil y que entregaron');

// --- Cohorte de demostracion ---
$demo = perfiles_m2::demo();
local_chatbot_verificar(count($demo) === 60 && $demo === perfiles_m2::demo(), 'demostracion: 60 personas, siempre las mismas');
local_chatbot_verificar(in_array(null, array_column($demo, 'perfil'), true), 'la demostracion incluye personas sin perfil');
local_chatbot_verificar(array_keys($demo[0]['metricas']) === array_keys(perfiles_m2::METRICAS), 'con todas las metricas');
local_chatbot_verificar(count(perfiles_m2::highlights($demo)) >= 4, 'la demostracion muestra conclusiones');
$todos = true;
foreach ($demo as $persona) {
    $m = $persona['metricas'];
    if (($m['puntaje'] !== null && ($m['puntaje'] < 9 || $m['puntaje'] > 36)) || ($m['termino'] && !$m['diseno'])
            || ($m['puntaje'] !== null && !$m['termino']) || ($m['entrego'] === false && $m['diseno'] !== null)) {
        $todos = false;
    }
}
local_chatbot_verificar($todos, 'demostracion coherente: puntajes de 9 a 36, nadie termina sin disenar ni disena sin entregar');

// --- Cadenas de idioma de cada pregunta y comportamiento ---
$es = file_get_contents(__DIR__ . '/../lang/es/local_chatbot.php');
foreach (array_keys(perfiles_m2::METRICAS) as $m) {
    local_chatbot_verificar(strpos($es, "\$string['pm2_m_{$m}']") !== false && strpos($es, "\$string['pm2_h_{$m}']") !== false,
        "nombre y explicacion de «{$m}»");
    local_chatbot_verificar(strpos($es, "\$string['pm2_s_{$m}']") !== false && strpos($es, "\$string['pm2_do_{$m}']") !== false,
        "conclusion en frase y sugerencia de «{$m}»");
    if (perfiles_m2::METRICAS[$m]['tipo'] !== 'pct') {
        local_chatbot_verificar(strpos($es, "\$string['pm2_u_{$m}']") !== false, "unidad de «{$m}» para las frases");
    }
}
foreach (perfiles_m2::DIMENSIONES as $d) {
    local_chatbot_verificar(strpos($es, "\$string['pm2_dim_{$d}']") !== false, "nombre de la pregunta «{$d}»");
}
foreach (array_unique(array_column(perfiles_m2::METRICAS, 'grupo')) as $g) {
    local_chatbot_verificar(strpos($es, "\$string['pm2_grp_{$g}']") !== false, "nombre del grupo «{$g}»");
}

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Perfiles en el Modulo 2 conformes: grupos por perfil, minimo por grupo y conclusiones.\n";
    exit(0);
}
echo "Perfiles en el Modulo 2 NO conformes: revisar classes/perfiles_m2.php.\n";
exit(1);
