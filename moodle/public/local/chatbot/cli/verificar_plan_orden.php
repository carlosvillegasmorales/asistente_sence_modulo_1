<?php
/**
 * Verifica el orden en que se muestran las metas del plan: lo que se trabaja ahora primero,
 * luego lo postergado y al final lo completado; dentro de cada grupo, por plazo, dimension y
 * antigüedad. Evita que una meta nueva o reactivada caiga en cualquier lugar de la lista.
 *
 * Uso:  php cli/verificar_plan_orden.php
 */

define('MOODLE_INTERNAL', true);

require_once(__DIR__ . '/../classes/pdp.php');

use local_chatbot\pdp;

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

function local_chatbot_meta(int $id, string $status, ?int $plazo = null, int $dimension = 0): stdClass {
    return (object) ['id' => $id, 'status' => $status, 'plazo' => $plazo, 'dimensionid' => $dimension];
}

function local_chatbot_ids(array $metas): string {
    return implode(',', array_map(fn($m) => $m->id, $metas));
}

$dim = [10 => 1, 11 => 2, 12 => 3];

// --- Grupos ---
local_chatbot_verificar(pdp::goal_group(pdp::GOAL_ACEPTADO) === 'curso', 'una meta aceptada está en curso');
local_chatbot_verificar(pdp::goal_group(pdp::GOAL_MODIFICADO) === 'curso', 'una meta editada está en curso');
local_chatbot_verificar(pdp::goal_group(pdp::GOAL_POSTERGADO) === 'postergadas', 'una meta postergada va en su grupo');
local_chatbot_verificar(pdp::goal_group(pdp::GOAL_COMPLETADO) === 'completadas', 'una meta completada va en su grupo');
local_chatbot_verificar(pdp::goal_group('otro') === 'curso', 'un estado desconocido no se pierde: cae en curso');

// --- El problema: una meta nueva caía entre medio de las postergadas y las completadas ---
$metas = [
    local_chatbot_meta(1, pdp::GOAL_COMPLETADO),
    local_chatbot_meta(2, pdp::GOAL_POSTERGADO),
    local_chatbot_meta(3, pdp::GOAL_ACEPTADO),
    local_chatbot_meta(9, pdp::GOAL_ACEPTADO), // La que se acaba de agregar.
];
local_chatbot_verificar(local_chatbot_ids(pdp::order_goals($metas, $dim)) === '3,9,2,1',
    'en curso primero (la nueva con las demás en curso), luego postergadas, al final completadas');

// --- Plazo: la más cercana primero; sin plazo, al final del grupo ---
$metas = [
    local_chatbot_meta(1, pdp::GOAL_ACEPTADO, null),
    local_chatbot_meta(2, pdp::GOAL_ACEPTADO, 2000),
    local_chatbot_meta(3, pdp::GOAL_ACEPTADO, 1000),
];
local_chatbot_verificar(local_chatbot_ids(pdp::order_goals($metas, $dim)) === '3,2,1',
    'dentro del grupo, el plazo más cercano primero y sin plazo al final');

// --- Misma fecha: dimensión, y a igualdad, antigüedad ---
$metas = [
    local_chatbot_meta(5, pdp::GOAL_ACEPTADO, 1000, 12),
    local_chatbot_meta(6, pdp::GOAL_ACEPTADO, 1000, 10),
    local_chatbot_meta(4, pdp::GOAL_ACEPTADO, 1000, 10),
];
local_chatbot_verificar(local_chatbot_ids(pdp::order_goals($metas, $dim)) === '4,6,5',
    'con el mismo plazo manda el orden de la dimensión y luego la antigüedad');
local_chatbot_verificar(local_chatbot_ids(pdp::order_goals([local_chatbot_meta(2, pdp::GOAL_ACEPTADO),
    local_chatbot_meta(1, pdp::GOAL_ACEPTADO)])) === '1,2', 'sin datos de dimensión ni plazo se ordena por antigüedad');

// --- Estable: ordenar dos veces da lo mismo, y no pierde ni duplica metas ---
$metas = [
    local_chatbot_meta(7, pdp::GOAL_POSTERGADO, 500, 11),
    local_chatbot_meta(3, pdp::GOAL_ACEPTADO, null, 10),
    local_chatbot_meta(8, pdp::GOAL_COMPLETADO, 100, 10),
    local_chatbot_meta(2, pdp::GOAL_MODIFICADO, 900, 12),
];
$una = pdp::order_goals($metas, $dim);
local_chatbot_verificar(local_chatbot_ids($una) === local_chatbot_ids(pdp::order_goals($una, $dim)), 'ordenar dos veces da el mismo resultado');
local_chatbot_verificar(count($una) === 4, 'no se pierde ninguna meta');
local_chatbot_verificar(pdp::order_goals([], $dim) === [], 'una lista vacía sigue vacía');
local_chatbot_verificar(array_keys($una) === [0, 1, 2, 3], 'las claves quedan reiniciadas');

// --- Reactivar una postergada la sube al grupo en curso ---
$metas = [local_chatbot_meta(1, pdp::GOAL_ACEPTADO, 3000), local_chatbot_meta(2, pdp::GOAL_POSTERGADO, 1000)];
local_chatbot_verificar(local_chatbot_ids(pdp::order_goals($metas, $dim)) === '1,2', 'la postergada va después de la que se trabaja');
$metas[1]->status = pdp::GOAL_ACEPTADO;
local_chatbot_verificar(local_chatbot_ids(pdp::order_goals($metas, $dim)) === '2,1', 'al reactivarla se ordena por su plazo con las de en curso');

// --- Cambio de la brecha desde que se creo la meta ---
$etq = ['prioridad_alta' => 'Prioridad alta', 'en_desarrollo' => 'En desarrollo', 'funcional' => 'Funcional', 'consolidado' => 'Consolidado'];
$ord = array_keys($etq);
$c = pdp::gap_change('Estrategias didácticas (En desarrollo)', 'funcional', $etq, $ord);
local_chatbot_verificar($c['trend'] === 'sube' && $c['before'] === 'en_desarrollo' && $c['now'] === 'funcional', 'de En desarrollo a Funcional sube');
local_chatbot_verificar(pdp::gap_change('Reflexión (Funcional)', 'en_desarrollo', $etq, $ord)['trend'] === 'baja', 'de Funcional a En desarrollo baja');
local_chatbot_verificar(pdp::gap_change('Diseño (Funcional)', 'funcional', $etq, $ord)['trend'] === 'igual', 'el mismo nivel queda sin cambio');
local_chatbot_verificar(pdp::gap_change('Diseño (funcional)', 'consolidado', $etq, $ord)['before'] === 'funcional', 'las mayúsculas no importan al leer el nivel');
local_chatbot_verificar(pdp::gap_change('Una brecha escrita a mano', 'funcional', $etq, $ord)['trend'] === 'desconocido', 'un texto sin nivel no inventa el cambio');
local_chatbot_verificar(pdp::gap_change('Una brecha escrita a mano', 'funcional', $etq, $ord)['now'] === 'funcional', 'aun sin nivel previo se conoce el actual');
local_chatbot_verificar(pdp::gap_change('Diseño (Funcional)', '', $etq, $ord)['trend'] === 'desconocido', 'sin matriz actual no hay comparación');
local_chatbot_verificar(pdp::gap_change('Diseño (Nivel raro)', 'funcional', $etq, $ord)['before'] === null, 'un nivel que no existe no se adivina');
local_chatbot_verificar(pdp::gap_change('Diseño (Funcional) extra', 'funcional', $etq, $ord)['before'] === null, 'solo se lee el nivel al final del texto');

// --- Versiones del plan (v1.16.6, interruptor autopdp) ---
local_chatbot_verificar(pdp::goal_visible(null, 0) && pdp::goal_visible(null, 3), 'una meta propia se ve en cualquier versión del plan');
local_chatbot_verificar(pdp::goal_visible(2, 2), 'una meta del asistente se ve en su versión');
local_chatbot_verificar(!pdp::goal_visible(1, 2), 'una meta de una versión anterior no se ve en la vigente');
local_chatbot_verificar(pdp::goal_visible(4, 0), 'un plan sin versiones lo muestra todo');
local_chatbot_verificar(pdp::version_target(3, 3, -1) === 2, '«Versión anterior» baja una versión');
local_chatbot_verificar(pdp::version_target(1, 3, -1) === 1, 'desde la primera versión no hay anterior');
local_chatbot_verificar(pdp::version_target(2, 3, 1) === 3, '«Versión siguiente» sube una versión');
local_chatbot_verificar(pdp::version_target(3, 3, 1) === 3, 'desde la última versión no hay siguiente');
local_chatbot_verificar(pdp::version_target(0, 0, -1) === 0, 'un plan sin versiones no se mueve');

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Orden del plan conforme: las metas se siguen por estado, plazo y dimensión.\n";
    exit(0);
}
echo "Orden del plan NO conforme: revisar pdp::order_goals().\n";
exit(1);
