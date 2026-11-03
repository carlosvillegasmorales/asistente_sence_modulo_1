<?php
/**
 * Verifica el calculo del tablero del Modulo 2 («Propuesta de dashboard - Indicadores, vistas y uso», Interfases v3,
 * 30-10-2026): hechos de cada ficha, indicadores agregados A a F y H, calidad del agente (G) y los seis avisos
 * automaticos con sus plazos.
 *
 * Uso:  php cli/verificar_tablero.php
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/guardrails.php');
require_once(__DIR__ . '/../classes/exante.php');
require_once(__DIR__ . '/../classes/diseno.php');
require_once(__DIR__ . '/../classes/tablero_m2.php');

use local_chatbot\diseno;
use local_chatbot\exante;
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

/**
 * Items del checklist: todos ✔ salvo los indicados.
 *
 * @param array $cambios id => estado o ['estado', 'forma'].
 * @return array
 */
function local_chatbot_items(array $cambios = []): array {
    $items = [];
    foreach (exante::checklist_ids() as $id) {
        $c = $cambios[$id] ?? exante::CUMPLE;
        $items[$id] = is_array($c) ? $c + ['observacion' => 'obs'] : ['estado' => $c, 'observacion' => ''];
    }
    return $items;
}

/**
 * Evaluacion de la Etapa 8 con los puntajes dados.
 *
 * @param int[] $p r1..r9 => puntaje.
 * @param string[] $limitados
 * @param int $fecha
 * @return array
 */
function local_chatbot_eval(array $p, array $limitados, int $fecha): array {
    $criterios = [];
    foreach ($p as $id => $v) {
        $criterios[$id] = ['puntaje' => $v, 'justificacion' => 'x'];
    }
    $total = array_sum($p);
    return ['criterios' => $criterios, 'total' => $total, 'banda' => diseno::band($total), 'limitados' => $limitados,
        'maximo' => 36 - count($limitados), 'fecha' => $fecha];
}

/**
 * Datos de una ficha para facts(), con valores por defecto.
 *
 * @param array $d
 * @return array
 */
function local_chatbot_datos(array $d): array {
    return $d + ['ficha' => ['id' => 1, 'userid' => 10, 'organismo' => 'OTEC Uno', 'activa' => 1], 'validaciones' => [],
        'envios' => [], 'etapas' => [], 'aceptadas' => [], 'evaluacion' => null, 'primer_acceso' => null, 'vistas' => [],
        'actividad' => 0, 'encuesta' => null];
}

// --- Funciones de apoyo ---
local_chatbot_verificar(tablero_m2::median([]) === null, 'mediana de nada: sin dato');
local_chatbot_verificar(tablero_m2::median([3, 1, 2]) === 2.0, 'mediana impar');
local_chatbot_verificar(tablero_m2::median([4, 1, 2, 3]) === 2.5, 'mediana par: promedio de los dos del medio');
local_chatbot_verificar(tablero_m2::mean([1, 2]) === 1.5 && tablero_m2::mean([]) === null, 'promedio con un decimal');
local_chatbot_verificar(tablero_m2::pct(1, 3) === 33 && tablero_m2::pct(1, 0) === null, 'porcentaje entero; sin base, sin dato');
local_chatbot_verificar(tablero_m2::days(T0, T0 + 36 * 3600) === 1.5 && tablero_m2::days(T0, T0 - 5) === 0.0,
    'dias con un decimal, nunca negativos');
local_chatbot_verificar(tablero_m2::bucket(0) === '0' && tablero_m2::bucket(2) === '1-2' && tablero_m2::bucket(3) === '3+',
    'tramos de observaciones no resueltas del indicador F: 0; 1 a 2; 3 o mas');

// --- Textos del cliente ---
$avisos = tablero_m2::avisos();
local_chatbot_verificar(array_keys($avisos) === ['correccion', 'critico', 'noresueltas', 'techo', 'c2nivel1', 'detenido'],
    'los seis avisos de la propuesta, en su orden');
local_chatbot_verificar($avisos['noresueltas']['sugerencia'] === 'Corregir la ficha antes de diseñar o asumir que los '
    . 'criterios vinculados quedarán limitados a 3.', 'sugerencia de «Observaciones no resueltas», textual');
local_chatbot_verificar($avisos['techo']['cuando'] === 'Puntaje máximo alcanzable menor que 32.', '«Techo bajo», textual');
local_chatbot_verificar(count(tablero_m2::encuesta()) === 4, 'encuesta de cierre de cuatro preguntas');
$dims = array_count_values(array_column(tablero_m2::encuesta(), 'dimension'));
local_chatbot_verificar($dims === ['utilidad' => 2, 'claridad' => 2], 'la encuesta mide utilidad y claridad (indicador H)');

// --- Encuesta y revision experta: entradas validas ---
local_chatbot_verificar(tablero_m2::clean_survey(['p1' => '5', 'p2' => 4, 'p3' => 1, 'p4' => 3])
    === ['p1' => 5, 'p2' => 4, 'p3' => 1, 'p4' => 3], 'encuesta completa: enteros de 1 a 5');
local_chatbot_verificar(tablero_m2::clean_survey(['p1' => 5, 'p2' => 4, 'p3' => 1]) === null, 'encuesta incompleta: no se guarda');
local_chatbot_verificar(tablero_m2::clean_survey(['p1' => 6, 'p2' => 4, 'p3' => 1, 'p4' => 3]) === null, 'fuera de escala: no');
local_chatbot_verificar(tablero_m2::clean_survey(['p1' => 2.5, 'p2' => 4, 'p3' => 1, 'p4' => 3]) === null, 'decimal: no');
$nueve = array_fill_keys(array_keys(diseno::rubrica()), 3);
local_chatbot_verificar(tablero_m2::clean_scores($nueve) === $nueve, 'revision de Etapa 8: nueve puntajes de 1 a 4');
local_chatbot_verificar(tablero_m2::clean_scores(['r9' => 0] + $nueve) === null, 'un puntaje 0: no se guarda');
$ocho = $nueve;
unset($ocho['r9']);
local_chatbot_verificar(tablero_m2::clean_scores($ocho) === null, 'falta un criterio: no se guarda');

// --- Hechos de una ficha completa ---
$v1 = ['id' => 101, 'estado' => exante::LISTO, 'condicion' => exante::NO_APROBADO, 'time' => T0 + DIA,
    'items' => local_chatbot_items(['c2_1' => exante::NO_CUMPLE, 'c2_3' => ['estado' => exante::PARCIAL, 'forma' => true]])];
$v2 = ['id' => 102, 'estado' => exante::LISTO, 'condicion' => exante::OBSERVACIONES, 'time' => T0 + 4 * DIA,
    'items' => local_chatbot_items(['c2_4' => exante::PARCIAL, 'c6_1' => exante::NO_CUMPLE])];
$puntajes = ['r1' => 4, 'r2' => 1, 'r3' => 4, 'r4' => 3, 'r5' => 3, 'r6' => 4, 'r7' => 4, 'r8' => 3, 'r9' => 1];
$ev = local_chatbot_eval($puntajes, ['r4', 'r5', 'r8'], T0 + 20 * DIA);
$h = tablero_m2::facts(local_chatbot_datos([
    'validaciones' => [$v1, $v2],
    'envios' => [
        ['completo' => false, 'faltantes' => ['2.1 Criterios de evaluación', 'Plan Formativo (archivo adjunto obligatorio)'],
            'plan' => false, 'perfil' => false, 'time' => T0],
        ['completo' => true, 'faltantes' => [], 'plan' => true, 'perfil' => false, 'time' => T0 + DIA - 60],
        ['completo' => true, 'faltantes' => [], 'plan' => true, 'perfil' => false, 'time' => T0 + 4 * DIA - 60],
    ],
    'etapas' => [1 => ['estado' => diseno::ACEPTADO, 'inicio' => T0 + 5 * DIA, 'cierre' => T0 + 6 * DIA, 'rondas' => 2,
            'validacionid' => 102],
        2 => ['estado' => diseno::BORRADOR, 'inicio' => T0 + 6 * DIA, 'cierre' => 0, 'rondas' => 1, 'validacionid' => 102],
        8 => ['estado' => diseno::BORRADOR, 'inicio' => T0 + 19 * DIA, 'cierre' => 0, 'rondas' => 0, 'validacionid' => 102]],
    'aceptadas' => [1], 'evaluacion' => $ev, 'primer_acceso' => T0 - 2 * DIA,
    'vistas' => ['guia' => T0 - DIA, 'ejemplo' => T0 + 2 * DIA], 'actividad' => T0 + 20 * DIA,
    'encuesta' => ['p1' => 5, 'p2' => 4, 'p3' => 3, 'p4' => 4],
]));
local_chatbot_verificar($h['envios'] === 3 && $h['primer_envio_completo'] === false && $h['envios_incompletos'] === 1,
    'B: tres envios, el primero incompleto');
local_chatbot_verificar($h['plan_primer_envio'] === false && $h['perfil_primer_envio'] === false,
    'B: documentos adjuntos en el primer envio');
local_chatbot_verificar($h['faltantes'] === ['2.1 Criterios de evaluación' => 1,
    'Plan Formativo (archivo adjunto obligatorio)' => 1], 'B: campos que faltaron, por campo');
local_chatbot_verificar($h['primera_entrega'] === T0, 'la primera entrega es el primer envio, aunque no pase el control');
local_chatbot_verificar(count($h['ciclos']) === 2 && $h['decision_primera'] === exante::NO_APROBADO
    && $h['decision_final'] === exante::OBSERVACIONES, 'C: decision del primer ciclo y final');
local_chatbot_verificar($h['ciclos_aprobacion'] === 2, 'C: ciclos hasta ⚠ Aprobado con observaciones');
local_chatbot_verificar($h['correcciones'] === [3.0] && $h['correccion_pendiente'] === null,
    'C: tiempo de correccion del ❌ al reenvio (3 dias), sin correccion pendiente');
local_chatbot_verificar($h['items']['c6_1'] === exante::NO_CUMPLE && count($h['items']) === count(exante::checklist_ids()),
    'C: resultado de cada item de la ultima validacion');
local_chatbot_verificar($h['criticos_obs'] === [], 'C: la validacion final no tiene items CRITICO observados');
local_chatbot_verificar($h['val_criticos'][0] === ['obs' => true, 'parciales' => 1, 'forma' => 1]
    && $h['val_criticos'][1]['obs'] === false, 'C: ⚠ critico aceptado por forma, por ciclo');
local_chatbot_verificar($h['no_resueltas'] === 2 && $h['no_resueltas_secciones'] === [2 => 1, 6 => 1],
    'C: observaciones no resueltas al iniciar la Etapa 1, por seccion');
local_chatbot_verificar(array_keys($h['limitados_previstos']) === ['r4', 'r5', 'r8'] && $h['techo_previsto'] === 33,
    'el item de tiempo (c2_4) limita 4 y 8; recursos (c6_1) limita 5 y 8: techo previsto 33');
local_chatbot_verificar($h['etapas'][1] === ['alcanzada' => true, 'cerrada' => true, 'rondas' => 2, 'dias' => 1.0],
    'D: etapa 1 cerrada, con sus rondas de ajuste y su duracion');
local_chatbot_verificar($h['etapas'][2]['alcanzada'] && !$h['etapas'][2]['cerrada'] && $h['etapas'][2]['dias'] === null,
    'D: etapa 2 en curso, sin duracion');
local_chatbot_verificar(!$h['etapas'][3]['alcanzada'], 'D: etapa 3 sin empezar');
local_chatbot_verificar($h['etapas'][8]['cerrada'] && $h['etapas'][8]['dias'] === 1.0,
    'D: la Etapa 8 se cierra con la evaluacion vigente');
local_chatbot_verificar($h['evaluacion']['total'] === 27 && $h['evaluacion']['maximo'] === 33
    && $h['evaluacion']['brecha'] === 6 && $h['evaluacion']['banda'] === 'menores',
    'E: total, techo por diagnostico, brecha de desempeño y tramo');
local_chatbot_verificar($h['evaluacion']['nivel1'] === ['r2', 'r9'], 'E: criterios en nivel 1');
local_chatbot_verificar($h['decision_diseno'] === exante::OBSERVACIONES && $h['no_resueltas_diseno'] === 2,
    'F: decision de la Etapa 0 y observaciones con que se hizo el diseño');
local_chatbot_verificar($h['duracion'] === 20.0, 'D: duracion del proceso, de la primera entrega al cierre de la Etapa 8');
local_chatbot_verificar($h['vista_guia'] === T0 - DIA && $h['vista_ejemplo'] === T0 + 2 * DIA,
    'A: primera consulta de la guia y del ejemplo');

// Una evaluacion guardada antes del limite por observaciones (sin «limitados» ni «maximo») sigue leyendose.
$vieja = local_chatbot_eval($puntajes, [], T0);
unset($vieja['limitados'], $vieja['maximo']);
$hv = tablero_m2::facts(local_chatbot_datos(['validaciones' => [$v2], 'evaluacion' => $vieja]));
local_chatbot_verificar($hv['evaluacion']['maximo'] === 36 && $hv['evaluacion']['limitados'] === [],
    'evaluacion anterior a v1.20.14: techo 36 y sin criterios limitados');

// --- Etapa actual ---
local_chatbot_verificar(tablero_m2::current_stage($h) === 2, 'etapa actual: la primera sin cerrar (2)');
$hna = tablero_m2::facts(local_chatbot_datos(['validaciones' => [$v1]]));
local_chatbot_verificar(tablero_m2::current_stage($hna) === 0, 'con ❌ No aprobado sigue en la Etapa 0');
local_chatbot_verificar(tablero_m2::current_stage(tablero_m2::facts(local_chatbot_datos([])) ) === 0,
    'sin validacion: Etapa 0');

// --- Avisos ---
$plazos = ['correccion' => 7, 'detenido' => 7];
$tipos = static function(array $avisos): array {
    return array_column($avisos, 'tipo');
};
$ahora = T0 + DIA + 10 * DIA;
$a = tablero_m2::notices($hna, $ahora, $plazos);
local_chatbot_verificar(in_array('correccion', $tipos($a), true), 'Corrección pendiente: 10 dias sin reenviar tras un ❌');
local_chatbot_verificar($a[0]['a']['dias'] === 10 && $a[0]['clave'] === 'correccion-101', 'el aviso dice los dias');
local_chatbot_verificar(!in_array('correccion', $tipos(tablero_m2::notices($hna, T0 + DIA + 5 * DIA, $plazos)), true),
    'Corrección pendiente: no antes del plazo X');
local_chatbot_verificar(in_array('critico', $tipos($a), true) && $a[1]['a']['items'] === ['c2_1', 'c2_3'],
    'Ítem CRÍTICO observado: ⚠ o ❌ en cualquier critico, aunque el ⚠ sea de forma');
local_chatbot_verificar(!in_array('detenido', $tipos($a), true), 'con una correccion pendiente no se avisa ademas «detenido»');
$a = tablero_m2::notices($h, T0 + 21 * DIA, $plazos);
local_chatbot_verificar(in_array('noresueltas', $tipos($a), true), 'Observaciones no resueltas: se inicio el diseño con ⚠/❌');
local_chatbot_verificar(in_array('c2nivel1', $tipos($a), true), 'Criterio 2 en nivel 1');
local_chatbot_verificar(!in_array('techo', $tipos($a), true), 'techo 33: no es «techo bajo»');
local_chatbot_verificar(!in_array('detenido', $tipos($a), true), 'con actividad reciente no se avisa «Proceso detenido»');
$hb = tablero_m2::facts(local_chatbot_datos(['validaciones' => [$v2], 'etapas' => [1 => ['estado' => diseno::BORRADOR,
    'inicio' => T0 + 5 * DIA, 'cierre' => 0, 'rondas' => 0, 'validacionid' => 102]], 'actividad' => T0 + 5 * DIA,
    'evaluacion' => local_chatbot_eval($puntajes, ['r1', 'r2', 'r3', 'r4', 'r5'], T0 + 9 * DIA)]));
$a = tablero_m2::notices($hb, T0 + 6 * DIA, $plazos);
local_chatbot_verificar(in_array('techo', $tipos($a), true) && $a[array_search('techo', $tipos($a))]['a']['techo'] === 31,
    'Techo bajo: puntaje maximo alcanzable 31, menor que 32');
local_chatbot_verificar(in_array('detenido', $tipos(tablero_m2::notices($hb, T0 + 13 * DIA, $plazos)), true),
    'Proceso detenido: mas de X dias sin avanzar de etapa');
local_chatbot_verificar(!in_array('detenido', $tipos(tablero_m2::notices($hb, T0 + 11 * DIA, $plazos)), true),
    'Proceso detenido: no antes del plazo X');
$hc = tablero_m2::facts(local_chatbot_datos(['validaciones' => [$v2], 'etapas' => [1 => ['estado' => diseno::BORRADOR,
    'inicio' => T0 + 5 * DIA, 'cierre' => 0, 'rondas' => 0, 'validacionid' => 102]], 'actividad' => T0 + 5 * DIA]));
$a = tablero_m2::notices($hc, T0 + 6 * DIA, $plazos);
local_chatbot_verificar(!in_array('techo', $tipos($a), true), 'techo previsto 33 al iniciar el diseño: sin aviso');
$hd = tablero_m2::facts(local_chatbot_datos(['validaciones' => [['items' => local_chatbot_items(['c2_4' => exante::PARCIAL,
    'c3_1' => exante::PARCIAL, 'c4_1' => exante::PARCIAL, 'c6_1' => exante::PARCIAL, 'c7_4' => exante::PARCIAL])] + $v2],
    'etapas' => [1 => ['estado' => diseno::BORRADOR, 'inicio' => T0, 'cierre' => 0, 'rondas' => 0, 'validacionid' => 102]]]));
local_chatbot_verificar($hd['techo_previsto'] === 30 && in_array('techo', $tipos(tablero_m2::notices($hd, T0, $plazos)), true),
    'Techo bajo ya al iniciar el diseño (techo previsto 30), antes de la evaluacion');

// --- Envio de avisos: una vez; los de plazo se repiten cada plazo ---
$lista = [['tipo' => 'critico', 'clave' => 'critico-1', 'a' => []], ['tipo' => 'correccion', 'clave' => 'correccion-1', 'a' => []]];
local_chatbot_verificar(count(tablero_m2::due($lista, [], T0, $plazos)) === 2, 'lo que nunca se envio, se envia');
$enviados = ['critico-1' => T0 - 30 * DIA, 'correccion-1' => T0 - 3 * DIA];
local_chatbot_verificar(tablero_m2::due($lista, $enviados, T0, $plazos) === [], 'un aviso enviado no se repite antes de tiempo');
local_chatbot_verificar(array_column(tablero_m2::due($lista, ['critico-1' => T0 - 30 * DIA, 'correccion-1' => T0 - 8 * DIA],
    T0, $plazos), 'clave') === ['correccion-1'], 'solo los de plazo se repiten, cada plazo');
$reg = tablero_m2::record(['viejo' => T0 - 200 * DIA, 'critico-1' => T0 - 300 * DIA], [$lista[1]], $lista, T0);
local_chatbot_verificar($reg === ['critico-1' => T0 - 300 * DIA, 'correccion-1' => T0],
    'el registro guarda solo los avisos activos: olvida los que ya no estan y no crece sin fin');
local_chatbot_verificar(tablero_m2::due($lista, $reg, T0 + DIA, $plazos) === [],
    'un aviso de una vez sigue sin repetirse mientras este activo, aunque se haya enviado hace 300 dias');

// --- Correccion: el reenvio es el envio siguiente, pase o no el control de entrada ---
$hr = tablero_m2::facts(local_chatbot_datos(['validaciones' => [$v1], 'envios' => [
    ['completo' => true, 'faltantes' => [], 'plan' => true, 'perfil' => false, 'time' => T0 + DIA - 60],
    ['completo' => false, 'faltantes' => ['2.4 Observación'], 'plan' => true, 'perfil' => false, 'time' => T0 + 3 * DIA]]]));
local_chatbot_verificar($hr['correcciones'] === [2.0] && $hr['correccion_pendiente'] === null,
    'reenvio incompleto tras un ❌: cuenta como reenvio y no queda «Corrección pendiente»');
local_chatbot_verificar(!in_array('correccion', $tipos(tablero_m2::notices($hr, T0 + 20 * DIA, $plazos)), true),
    'sin aviso de correccion despues de un reenvio');

// --- Observaciones al iniciar la Etapa 1: la validacion vigente entonces, no la del ultimo ajuste ---
$v3 = ['id' => 103, 'estado' => exante::LISTO, 'condicion' => exante::APROBADO, 'time' => T0 + 8 * DIA,
    'items' => local_chatbot_items()];
$hi = tablero_m2::facts(local_chatbot_datos(['validaciones' => [$v1, $v2, $v3], 'etapas' => [1 => ['estado' => diseno::BORRADOR,
    'inicio' => T0 + 5 * DIA, 'cierre' => 0, 'rondas' => 1, 'validacionid' => 103]]]));
local_chatbot_verificar($hi['no_resueltas'] === 2 && $hi['validacion_diseno'] === 102,
    'un ajuste pedido despues de corregir la ficha no borra las observaciones con que se inicio la Etapa 1');

// --- Revision experta vigente ---
local_chatbot_verificar(tablero_m2::agent_scores($ev) === $puntajes, 'puntajes del agente desde el informe de la Etapa 8');
local_chatbot_verificar(tablero_m2::same_scores(['r2' => '1', 'r1' => 4], ['r1' => 4, 'r2' => 1])
    && !tablero_m2::same_scores(['r1' => 4], ['r1' => 3]), 'la revision corresponde a la evaluacion si los puntajes son los mismos');
local_chatbot_verificar(tablero_m2::facts(local_chatbot_datos(['ficha' => ['id' => 9, 'userid' => 1,
    'etiqueta' => 'Soldadura MIG']]))['etiqueta'] === 'Soldadura MIG', 'el nombre del diseño viaja en los hechos');

// --- Detalle de los avisos ---
local_chatbot_verificar(strpos(tablero_m2::notice_detail(['tipo' => 'noresueltas', 'a' => ['n' => 2,
    'criterios' => ['r4', 'r8']]]), '4. Secuencia instruccional, 8. Factibilidad de implementación') !== false,
    'el aviso nombra los criterios que quedaran limitados');
local_chatbot_verificar(strpos(tablero_m2::notice_detail(['tipo' => 'detenido', 'a' => ['etapa' => 3, 'dias' => 9]]),
    'en la Etapa 3') !== false, 'proceso detenido: dice en que etapa');

// --- Indicadores agregados ---
$otra = tablero_m2::facts(local_chatbot_datos([
    'ficha' => ['id' => 2, 'userid' => 11, 'organismo' => ' otec  uno', 'activa' => 1],
    'validaciones' => [['id' => 201, 'estado' => exante::LISTO, 'condicion' => exante::APROBADO, 'time' => T0 + 2 * DIA,
        'items' => local_chatbot_items()]],
    'envios' => [['completo' => true, 'faltantes' => [], 'plan' => true, 'perfil' => true, 'time' => T0 + 2 * DIA - 60]],
    'etapas' => [1 => ['estado' => diseno::ACEPTADO, 'inicio' => T0 + 3 * DIA, 'cierre' => T0 + 4 * DIA, 'rondas' => 0,
        'validacionid' => 201]],
    'aceptadas' => [1], 'evaluacion' => local_chatbot_eval(array_fill_keys(array_keys(diseno::rubrica()), 4), [], T0 + 9 * DIA),
    'primer_acceso' => T0, 'vistas' => ['guia' => T0 + DIA], 'encuesta' => ['p1' => 3, 'p2' => 2, 'p3' => 5, 'p4' => 4],
]));
$prueba = tablero_m2::facts(local_chatbot_datos(['ficha' => ['id' => 3, 'userid' => 99, 'organismo' => '', 'activa' => 1],
    'validaciones' => [['id' => 301, 'estado' => exante::ERROR, 'condicion' => '', 'time' => T0, 'items' => []]]]));
$r = tablero_m2::program([$h, $otra, $prueba], [10, 11, 12]);
local_chatbot_verificar($r['a']['tasa_inicio'] === 67 && $r['externos'] === 1,
    'A: tasa de inicio sobre las personas inscritas; quien no esta inscrito queda aparte');
local_chatbot_verificar($r['a']['uso_guia_ejemplo'] === 0 && $r['a']['uso_guia'] === 67 && $r['a']['uso_ejemplo'] === 0,
    'A: guia y ejemplo consultados antes de la primera entrega (el ejemplo, despues, no cuenta)');
local_chatbot_verificar($r['a']['dias_primera'] === 2.0, 'A: tiempo hasta la primera entrega, mediana desde el primer acceso');
local_chatbot_verificar($r['b']['fichas_envio'] === 2 && $r['b']['primer_intento'] === 50 && $r['b']['plan'] === 50
    && $r['b']['perfil'] === 50, 'B: completas al primer intento y adjuntos en el primer envio');
local_chatbot_verificar($r['b']['faltantes']['2.1 Criterios de evaluación'] === ['n' => 1, 'pct' => 100],
    'B: campos faltantes sobre los envios incompletos');
local_chatbot_verificar($r['c']['validadas'] === 2 && $r['c']['primera'][exante::NO_APROBADO] === 1
    && $r['c']['final'][exante::APROBADO] === 1 && $r['c']['final'][exante::OBSERVACIONES] === 1,
    'C: decision del primer ciclo y final (una validacion con error no es un ciclo)');
local_chatbot_verificar($r['c']['ciclos_promedio'] === 1.5 && $r['c']['correccion_mediana'] === 3.0,
    'C: ciclos promedio hasta la aprobacion y mediana del tiempo de correccion');
local_chatbot_verificar($r['c']['items']['c6_1'] === ['cumple' => 67, 'parcial' => 0, 'no_cumple' => 33, 'obs' => 1],
    'C: resultado por item sobre todas las validaciones');
local_chatbot_verificar($r['c']['criticos'] === 33 && $r['c']['forma'] === 100,
    'C: validaciones con un critico observado; ⚠ criticos aceptados por forma');
local_chatbot_verificar($r['c']['no_resueltas_promedio'] === 1.0 && $r['c']['secciones'][2] === ['n' => 1, 'pct' => 50],
    'C: observaciones no resueltas, promedio y frecuencia por seccion');
local_chatbot_verificar(count($r['c']['otec']) === 1 && $r['c']['otec'][0]['fichas'] === 2,
    'C: el OTEC se agrupa sin distinguir mayusculas ni espacios');
local_chatbot_verificar($r['d']['aprobadas'] === 2 && $r['d']['embudo'][1] === ['n' => 2, 'pct' => 100]
    && $r['d']['embudo'][2] === ['n' => 1, 'pct' => 50] && $r['d']['embudo'][8] === ['n' => 1, 'pct' => 50],
    'D: embudo de etapas sobre las fichas aprobadas en la Etapa 0');
local_chatbot_verificar($r['d']['iteraciones'][1] === 1.0 && $r['d']['iteraciones'][2] === null,
    'D: rondas de ajuste por ficha que cerro la etapa');
local_chatbot_verificar($r['e']['evaluadas'] === 2 && $r['e']['total_promedio'] === 31.5
    && $r['e']['bandas']['alto'] === ['n' => 1, 'pct' => 50], 'E: puntaje promedio y tramos');
local_chatbot_verificar($r['e']['criterios']['r2'] === 2.5 && $r['e']['con_limite'] === 50 && $r['e']['techo_bajo'] === 0
    && $r['e']['brecha_promedio'] === 3.0 && $r['e']['nivel1'] === 50 && $r['e']['c2nivel1'] === 1,
    'E: por criterio, limitados, techo menor que 32, brecha y nivel 1');
local_chatbot_verificar($r['f']['aprobado'] === ['promedio' => 36.0, 'n' => 1]
    && $r['f']['observaciones'] === ['promedio' => 27.0, 'n' => 1] && $r['f']['diferencia'] === 9.0,
    'F: puntaje segun la decision de la Etapa 0');
local_chatbot_verificar($r['f']['tramos']['0'] === ['promedio' => 36.0, 'n' => 1]
    && $r['f']['tramos']['1-2'] === ['promedio' => 27.0, 'n' => 1] && $r['f']['tramos']['3+']['n'] === 0,
    'F: puntaje segun observaciones no resueltas');
local_chatbot_verificar($r['h']['n'] === 2 && $r['h']['preguntas']['p1'] === 4.0 && $r['h']['utilidad'] === 4.0
    && $r['h']['claridad'] === 3.5, 'H: utilidad y claridad percibidas');
$vacio = tablero_m2::program([], [10]);
local_chatbot_verificar($vacio['a']['tasa_inicio'] === 0 && $vacio['c']['validadas'] === 0 && $vacio['e']['total_promedio'] === null,
    'sin fichas: todo en cero o sin dato, sin errores');

// --- Calidad del agente ---
$g = tablero_m2::agent([
    ['tipo' => 'etapa0', 'agente' => exante::APROBADO, 'experto' => exante::APROBADO, 'incidencias' => []],
    ['tipo' => 'etapa0', 'agente' => exante::OBSERVACIONES, 'experto' => exante::NO_APROBADO,
        'incidencias' => ['critico', 'evidencia']],
    ['tipo' => 'etapa8', 'agente' => $puntajes, 'experto' => ['r3' => 2] + $puntajes, 'incidencias' => ['resultado', 'x']],
]);
local_chatbot_verificar($g['revisadas'] === 3 && $g['concordancia0'] === 50, 'G: concordancia de la decision en la Etapa 0');
local_chatbot_verificar($g['concordancia8'] === 89 && $g['criterios'] === 9, 'G: Etapa 8, criterios con 1 punto o menos');
local_chatbot_verificar($g['sesgo']['r3'] === 2.0 && $g['sesgo']['r1'] === 0.0, 'G: diferencia agente menos experto');
local_chatbot_verificar($g['incidencias']['critico'] === ['n' => 1, 'tasa' => 0.33] && !isset($g['incidencias']['x']),
    'G: incidencias por tipo sobre las evaluaciones revisadas; un tipo desconocido no cuenta');
local_chatbot_verificar($g['confusion'] === ['aprobado>aprobado' => 1, 'observaciones>no_aprobado' => 1],
    'G: decision del agente frente a la revision experta');
local_chatbot_verificar(tablero_m2::agent([])['concordancia0'] === null, 'G: sin revisiones, sin dato');

// Boton flotante de indicadores en el curso del Modulo 2 (pedido del cliente del 02-11: «igual que el Modulo 1»).
local_chatbot_verificar(tablero_m2::fab_view(true, true, false) === 'programa' && tablero_m2::fab_view(true, true, true) === 'programa'
    && tablero_m2::fab_view(true, false, true) === 'persona' && tablero_m2::fab_view(true, false, false) === null
    && tablero_m2::fab_view(false, true, true) === null,
    'boton flotante: coordinacion al programa, quien tiene ficha a su avance, nadie mas; fuera del Modulo 2, el de siempre');

// Filtro por periodo de la vista «Programa» (pedido del cliente del 02-11: semanal, mensual, semestral y anual).
$ahora = 1800000000;
local_chatbot_verificar(tablero_m2::period_start('semana', $ahora) === $ahora - 7 * 86400
    && tablero_m2::period_start('mes', $ahora) === $ahora - 30 * 86400 && tablero_m2::period_start('semestre', $ahora) === $ahora - 182 * 86400
    && tablero_m2::period_start('anio', $ahora) === $ahora - 365 * 86400 && tablero_m2::period_start('todo', $ahora) === 0
    && tablero_m2::period_start('otro', $ahora) === 0, 'periodo: semana, mes, semestre y año hacia atras; todo o desconocido, sin limite');
$porfecha = [1 => ['actividad' => $ahora - 2 * 86400], 2 => ['actividad' => $ahora - 20 * 86400],
    3 => ['actividad' => $ahora - 100 * 86400], 4 => ['actividad' => $ahora - 300 * 86400], 5 => ['actividad' => 0]];
local_chatbot_verificar(array_keys(tablero_m2::in_period($porfecha, tablero_m2::period_start('semana', $ahora))) === [1]
    && array_keys(tablero_m2::in_period($porfecha, tablero_m2::period_start('mes', $ahora))) === [1, 2]
    && array_keys(tablero_m2::in_period($porfecha, tablero_m2::period_start('semestre', $ahora))) === [1, 2, 3]
    && array_keys(tablero_m2::in_period($porfecha, tablero_m2::period_start('anio', $ahora))) === [1, 2, 3, 4]
    && count(tablero_m2::in_period($porfecha, 0)) === 5, 'periodo: quedan las fichas con actividad dentro del periodo; «todo» las deja todas');

echo "Casos verificados: {$total} | Fallidos: {$fallidos}\n";
exit($fallidos > 0 ? 1 : 0);
