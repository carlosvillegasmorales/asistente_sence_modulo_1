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
local_chatbot_verificar(perfiles_m2::DIMENSIONES === ['sector', 'experiencia', 'familiaridad', 'rol', 'programa', 'modalidad',
    'tiempo', 'fortalecer'], 'el sector productivo y las siete preguntas cerradas del perfil del acta del 13-10');
foreach (perfiles_m2::DIMENSIONES as $d) {
    local_chatbot_verificar(perfiles_m2::options($d) !== [], "la pregunta «{$d}» tiene sus alternativas");
}

// --- Sector productivo: la pregunta abierta agrupada al modo de ChileValora ---
foreach (perfiles_m2::DEMO_SECTORES as $sector => $textos) {
    foreach ($textos as $texto) {
        local_chatbot_verificar(perfiles_m2::sector_of($texto) === $sector, "«{$texto}» es del sector «{$sector}»");
    }
}
local_chatbot_verificar(perfiles_m2::sector_of('  TÉCNICO EN ENFERMERÍA ') === 'salud', 'sin importar mayusculas, tildes ni espacios');
$ordenlectura = perfiles_m2::options('sector');
sort($ordenlectura);
$reconocidos = array_keys(perfiles_m2::SECTORES);
sort($reconocidos);
local_chatbot_verificar($ordenlectura === $reconocidos, 'el orden de lectura trae los mismos sectores que se reconocen');
local_chatbot_verificar(perfiles_m2::sector_of('Pintura intensiva') === 'construccion', '«tens» no se lee dentro de «intensiva»');
local_chatbot_verificar(perfiles_m2::sector_of('Control de inventario') === 'logistica', '«venta» no se lee dentro de «inventario»');
local_chatbot_verificar(perfiles_m2::sector_of('Soldadura en obra') === 'mecanica', 'el primer sector gana: soldadura es metalmecanica');
local_chatbot_verificar(perfiles_m2::sector_of('') === '' && perfiles_m2::sector_of('Malabarismo') === 'otro',
    'sin texto: sin sector; texto no reconocido: «otros sectores»');
local_chatbot_verificar(perfiles_m2::answers(['sector' => 'TENS'], 'sector') === ['salud']
    && perfiles_m2::answers(['sector' => ''], 'sector') === [perfiles_m2::SIN_RESPUESTA], 'el sector como grupo del informe');
$audit = perfiles_m2::sector_audit([['perfil' => ['sector' => 'TENS']], ['perfil' => ['sector' => 'TENS']],
    ['perfil' => ['sector' => 'Obras civiles']], ['perfil' => null], ['perfil' => ['sector' => '']]]);
local_chatbot_verificar($audit === ['salud' => ['TENS' => 2], 'construccion' => ['Obras civiles' => 1]],
    'como se agruparon los sectores: texto escrito y cuantas personas');

// --- Solidez de una diferencia ---
local_chatbot_verificar(perfiles_m2::solid('pct', array_fill(0, 20, true), array_fill(0, 20, false)),
    '20 de 20 frente a 0 de 20: diferencia solida');
local_chatbot_verificar(!perfiles_m2::solid('pct', [true, true, true, false, true], [true, false, true, false, false]),
    '4 de 5 frente a 2 de 5: a confirmar');
local_chatbot_verificar(perfiles_m2::solid('promedio', [30, 31, 32, 33, 34, 30], [20, 21, 22, 20, 21, 22]),
    'puntajes muy separados y parejos: solida');
local_chatbot_verificar(!perfiles_m2::solid('promedio', [20, 36, 25, 30, 18], [22, 34, 19, 28, 26]),
    'promedios parecidos y muy dispersos: a confirmar');
local_chatbot_verificar(!perfiles_m2::solid('pct', [true], [false]), 'con una persona por grupo nunca es solida');

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
local_chatbot_verificar(max(array_count_values(array_column(perfiles_m2::highlights(perfiles_m2::demo()), 'dim'))) <= 2,
    'hasta dos conclusiones por pregunta, para que se vean las demas');
$varias = perfiles_m2::varied([['dim' => 'a', 'm' => 1], ['dim' => 'a', 'm' => 2], ['dim' => 'a', 'm' => 3],
    ['dim' => 'b', 'm' => 1], ['dim' => 'b', 'm' => 4]], 'm', 10);
local_chatbot_verificar(array_column($varias, 'm') === [1, 2, 4], 'variadas: una por metrica y dos por pregunta');
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

// --- Calidad del diseno por perfil (criterios de la rubrica) ---
$r = [];
foreach (range(1, 6) as $i) {
    $r[] = ['perfil' => ['sector' => 'TENS'], 'metricas' => [],
        'criterios' => ['r6' => 4, 'r7' => 4] + array_fill_keys(perfiles_m2::CRITERIOS, 3)];
    $r[] = ['perfil' => ['sector' => 'Maestro constructor'], 'metricas' => [],
        'criterios' => ['r6' => 2, 'r7' => 1 + $i % 2] + array_fill_keys(perfiles_m2::CRITERIOS, 3)];
}
$r[] = ['perfil' => ['sector' => 'Artesanía'], 'metricas' => [], 'criterios' => null];
$rc = perfiles_m2::rubric_cross($r, 'sector');
local_chatbot_verificar(array_keys($rc) === ['salud', 'construccion'], 'rubrica por sector: solo grupos con disenos evaluados');
local_chatbot_verificar($rc['salud']['celdas']['r7']['valor'] === 4.0 && $rc['construccion']['celdas']['r7']['valor'] === 1.5
    && $rc['salud']['n'] === 6, 'promedio del criterio por grupo');
local_chatbot_verificar(perfiles_m2::rubric_overall($r)['celdas']['r1']['valor'] === 3.0, 'rubrica de todo el curso');
$porc = array_column(perfiles_m2::rubric_highlights($r), null, 'criterio');
local_chatbot_verificar(isset($porc['r7']) && $porc['r7']['dim'] === 'sector' && $porc['r7']['mejor']['respuesta'] === 'salud'
    && $porc['r7']['peor']['respuesta'] === 'construccion' && $porc['r7']['solida'],
    'la TENS disena mejores instrumentos de evaluacion que el constructor, y es una diferencia solida');
local_chatbot_verificar(!isset($porc['r1']), 'sin diferencia en un criterio no hay conclusion');

// --- Recorrido por los modulos ---
local_chatbot_verificar(perfiles_m2::m2_path(['entrego' => true, 'aprob_primer' => false, 'ciclos' => 2, 'diseno' => true,
    'termino' => true, 'puntaje' => 24]) === ['m2_entrego' => true, 'm2_aprobada' => true, 'm2_diseno' => true,
    'm2_termino' => true, 'm2_aprobado' => false], 'Modulo 2: aprobo la ficha al segundo intento; diseno con 24, no aprobado');
$camino = [['recorrido' => ['m1_perfil' => true, 'm1_diagnostico' => true, 'm1_ruta' => true]],
    ['recorrido' => ['m1_perfil' => true, 'm1_diagnostico' => true]], ['recorrido' => ['m1_perfil' => true]],
    ['recorrido' => ['m1_perfil' => true]]];
$pc = perfiles_m2::path($camino);
local_chatbot_verificar($pc['m1_perfil']['pct'] === 100 && $pc['m1_diagnostico']['pct'] === 50 && $pc['m1_ruta']['pct'] === 25
    && $pc['m2_entrego']['n'] === 0, 'cuantas personas llegan a cada paso');
$caida = perfiles_m2::biggest_drop($pc);
local_chatbot_verificar($caida['desde'] === 'm1_perfil' && $caida['hasta'] === 'm1_diagnostico',
    'la mayor perdida: del perfil al diagnostico');
local_chatbot_verificar(perfiles_m2::biggest_drop(['a' => ['pct' => 50], 'b' => ['pct' => 50]]) === null,
    'sin perdida no hay caida');
local_chatbot_verificar(count(perfiles_m2::members($r, 'sector', 'salud')) === 6, 'personas de un grupo');

// --- Cifras de cabecera ---
$t = perfiles_m2::totals($personas);
local_chatbot_verificar($t['inscritos'] === 18 && $t['con_perfil'] === 15 && $t['entregaron'] === 17,
    'inscritos, con perfil y que entregaron');

// --- Cohorte de demostracion ---
$demo = perfiles_m2::demo();
local_chatbot_verificar(count($demo) === 90 && $demo === perfiles_m2::demo(), 'demostracion: 90 personas, siempre las mismas');
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
$coherente = true;
$ordenm1 = ['m1_perfil', 'm1_diagnostico', 'm1_ruta', 'm1_proyecto', 'm1_cierre'];
foreach ($demo as $persona) {
    for ($i = 1; $i < 5; $i++) {
        $coherente = $coherente && (!$persona['recorrido'][$ordenm1[$i]] || $persona['recorrido'][$ordenm1[$i - 1]]);
    }
    if ($persona['criterios'] !== null && array_sum($persona['criterios']) !== $persona['metricas']['puntaje']) {
        $coherente = false;
    }
}
local_chatbot_verificar($coherente, 'demostracion: el Modulo 1 en orden y el puntaje es la suma de los criterios');
local_chatbot_verificar(count(perfiles_m2::rubric_highlights($demo)) >= 2,
    'la demostracion muestra diferencias en la calidad del diseno');
$sectorial = array_filter(perfiles_m2::rubric_highlights($demo), static function($h) {
    return $h['dim'] === 'sector';
});
local_chatbot_verificar((bool) $sectorial, 'la demostracion compara la calidad del diseno entre sectores');

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
