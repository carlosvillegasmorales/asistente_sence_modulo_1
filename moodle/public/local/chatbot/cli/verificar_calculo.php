<?php
/**
 * Verifica el puntaje compuesto de orientación (RU-CALC-001).
 *
 * El requisito pide implementarlo "como función pura, testeable
 * independientemente del LLM". Este script es esa prueba: comprueba la fórmula
 * contra valores calculados a mano desde el enunciado del requisito, no desde
 * el código, de modo que un cambio en la ponderación aparezca aquí.
 *
 *   CSd = 1 + 4 × P_situacional,d
 *   PCd = 0,7 × PDd + 0,3 × CSd
 *
 * Verifica además los cortes de nivel, porque la fórmula sola no decide nada:
 * lo que llega al Coach es la etiqueta de prioridad, y un corte mal puesto
 * cambia qué se le propone a la persona aunque el número esté bien.
 *
 * Uso:  php cli/verificar_calculo.php
 * Sale con código 1 ante cualquier caso fallido, para encadenarlo a CI.
 */

define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/diagnostic.php');
// priority_limit() toma su tope por defecto de pdp::MAX_PRIORIDADES.
require_once(__DIR__ . '/../classes/pdp.php');

use local_chatbot\diagnostic;

/**
 * Cada caso declara: descripción, práctica declarada, proporción situacional y
 * los tres valores esperados. null en la proporción significa que la dimensión
 * no tiene ítems situacionales respondidos.
 */
$casos = [
    // --- Extremos de la escala ---
    ['piso de la escala', 1.0, 0.0, 'pd' => 1.0, 'cs' => 1.0, 'pc' => 1.0],
    ['techo de la escala', 5.0, 1.0, 'pd' => 5.0, 'cs' => 5.0, 'pc' => 5.0],
    ['punto medio exacto', 3.0, 0.5, 'pd' => 3.0, 'cs' => 3.0, 'pc' => 3.0],

    // --- La ponderación 70/30 se nota cuando las dos fuentes discrepan ---
    // Declara mucho y resuelve poco: 0,7x4 + 0,3x2 = 2,8 + 0,6 = 3,4.
    ['declara alto, resuelve bajo', 4.0, 0.25, 'pd' => 4.0, 'cs' => 2.0, 'pc' => 3.4],
    // Declara poco y resuelve todo: 0,7x2 + 0,3x5 = 1,4 + 1,5 = 2,9.
    ['declara bajo, resuelve todo', 2.0, 1.0, 'pd' => 2.0, 'cs' => 5.0, 'pc' => 2.9],
    // Dos de tres situacionales correctos: CS = 1 + 4x0,6667 = 3,67.
    ['dos de tres situacionales', 3.5, 2 / 3, 'pd' => 3.5, 'cs' => 3.67, 'pc' => 3.55],

    // --- Sin componente situacional el compuesto es la práctica declarada ---
    ['sin ítems situacionales', 4.5, null, 'pd' => 4.5, 'cs' => null, 'pc' => 4.5],

    // --- Entradas fuera de rango se recortan en vez de propagar el error ---
    ['práctica sobre el techo', 7.0, 0.0, 'pd' => 5.0, 'cs' => 1.0, 'pc' => 3.8],
    ['proporción sobre 1', 3.0, 1.4, 'pd' => 3.0, 'cs' => 5.0, 'pc' => 3.6],
    ['proporción negativa', 3.0, -0.2, 'pd' => 3.0, 'cs' => 1.0, 'pc' => 2.4],
];

$fallidos = 0;

foreach ($casos as $caso) {
    $resultado = diagnostic::composite_score($caso[1], $caso[2]);

    foreach (['pd', 'cs', 'pc'] as $clave) {
        $esperado = $caso[$clave];
        $real = $resultado[$clave];

        if ($esperado === null) {
            $ok = ($real === null);
        } else {
            $ok = ($real !== null && abs($real - $esperado) < 0.005);
        }

        if (!$ok) {
            printf("  FALLA  %-28s %s esperado=%s real=%s\n", $caso[0], $clave,
                var_export($esperado, true), var_export($real, true));
            $fallidos++;
        }
    }

    $esperahassituacional = ($caso[2] !== null);
    if ($resultado['hassituacional'] !== $esperahassituacional) {
        printf("  FALLA  %-28s hassituacional esperado=%s real=%s\n", $caso[0],
            var_export($esperahassituacional, true), var_export($resultado['hassituacional'], true));
        $fallidos++;
    }
}

// La ponderación debe sumar 1: si alguien ajusta un peso y olvida el otro, el
// resultado deja de estar en la escala 1 a 5 y nadie lo nota mirando un número.
if (abs((diagnostic::WEIGHT_DECLARADA + diagnostic::WEIGHT_SITUACIONAL) - 1.0) > 0.0001) {
    echo "  FALLA  los pesos de RU-CALC-001 no suman 1\n";
    $fallidos++;
}
if (abs(diagnostic::WEIGHT_DECLARADA - 0.7) > 0.0001) {
    echo "  FALLA  el peso de la práctica declarada no es 70%\n";
    $fallidos++;
}

// Niveles de orientacion de Doc 3 §6.5 (RU-COA-NIVEL-001), transcritos del
// documento: Prioridad alta 1,00-2,24; En desarrollo 2,25-3,24; Funcional
// 3,25-4,24; Consolidado 4,25-5,00. Cada borde se prueba por los dos lados.
$cortes = [
    ['piso de la escala', 1.00, diagnostic::LEVEL_PRIORIDAD_ALTA],
    ['techo de prioridad alta', 2.24, diagnostic::LEVEL_PRIORIDAD_ALTA],
    ['piso de en desarrollo', 2.25, diagnostic::LEVEL_EN_DESARROLLO],
    ['techo de en desarrollo', 3.24, diagnostic::LEVEL_EN_DESARROLLO],
    ['piso de funcional', 3.25, diagnostic::LEVEL_FUNCIONAL],
    ['techo de funcional', 4.24, diagnostic::LEVEL_FUNCIONAL],
    ['piso de consolidado', 4.25, diagnostic::LEVEL_CONSOLIDADO],
    ['techo de la escala', 5.00, diagnostic::LEVEL_CONSOLIDADO],
    // Sin redondear, 2,2449 caeria en el hueco entre 2,24 y 2,25.
    ['valor sin redondear en el hueco', 2.2449, diagnostic::LEVEL_PRIORIDAD_ALTA],
    ['valor que redondea hacia arriba', 2.2451, diagnostic::LEVEL_EN_DESARROLLO],
];

foreach ($cortes as $corte) {
    $real = diagnostic::level_for_score($corte[1]);
    if ($real !== $corte[2]) {
        printf("  FALLA  nivel %-32s puntaje=%s esperado=%s real=%s\n",
            $corte[0], $corte[1], $corte[2], $real);
        $fallidos++;
    }
}

// Correspondencia con las tres categorias de RU-COA-002.
$grupos = [
    [diagnostic::LEVEL_PRIORIDAD_ALTA, 'prioritaria'],
    [diagnostic::LEVEL_EN_DESARROLLO, 'profundizar'],
    [diagnostic::LEVEL_FUNCIONAL, 'fortaleza'],
    [diagnostic::LEVEL_CONSOLIDADO, 'fortaleza'],
];
foreach ($grupos as $grupo) {
    if (diagnostic::level_group($grupo[0]) !== $grupo[1]) {
        printf("  FALLA  grupo de %s esperado=%s real=%s\n", $grupo[0], $grupo[1], diagnostic::level_group($grupo[0]));
        $fallidos++;
    }
}

// Completitud (RU-COA-COMPLET-001): 80% de declarativos Y todos los situacionales.
$completitudes = [
    ['todo respondido', [4, 4, 1, 1], false, 100],
    ['80% exacto de declarativos', [4, 5, 1, 1], false, 83],
    ['bajo 80% de declarativos', [3, 5, 1, 1], true, 67],
    ['falta el unico situacional', [4, 4, 0, 1], true, 80],
    ['dimension sin situacionales en el instrumento', [4, 4, 0, 0], false, 100],
    ['nada respondido', [0, 4, 0, 1], true, 0],
];
foreach ($completitudes as $c) {
    $r = diagnostic::completeness($c[1][0], $c[1][1], $c[1][2], $c[1][3]);
    if ($r['preliminar'] !== $c[2] || $r['porcentaje'] !== $c[3]) {
        printf("  FALLA  completitud %-44s esperado=%s/%d real=%s/%d\n", $c[0],
            var_export($c[2], true), $c[3], var_export($r['preliminar'], true), $r['porcentaje']);
        $fallidos++;
    }
}

// Discrepancia (RU-COA-PRIOR-002, criterio 2): el signo se conserva.
$discrepancias = [
    ['declara mas de lo que resuelve', 4.0, 2.0, 2.0, true],
    ['resuelve mas de lo que declara', 2.0, 4.0, -2.0, true],
    ['diferencia bajo el umbral', 3.0, 3.5, -0.5, false],
    ['justo en el umbral', 3.0, 4.0, -1.0, true],
    ['sin componente situacional', 3.0, null, null, false],
];
foreach ($discrepancias as $d) {
    $valor = diagnostic::discrepancy($d[1], $d[2]);
    $relevante = diagnostic::is_relevant_discrepancy($valor);
    if ($valor !== $d[3] || $relevante !== $d[4]) {
        printf("  FALLA  discrepancia %-34s esperado=%s/%s real=%s/%s\n", $d[0],
            var_export($d[3], true), var_export($d[4], true), var_export($valor, true), var_export($relevante, true));
        $fallidos++;
    }
}

// Priorizacion (RU-COA-PRIOR-002): menor compuesto primero; empate por
// discrepancia relevante; fuera las preliminares y las consolidadas.
$fila = function(string $code, float $pc, ?float $disc, bool $prelim) {
    return ['code' => $code, 'pc' => $pc, 'discrepancia' => $disc, 'preliminar' => $prelim,
        'nivel' => diagnostic::level_for_score($pc)];
};
$matriz = [
    $fila('a', 3.0, 0.2, false),
    $fila('b', 2.0, 0.0, false),
    $fila('c', 3.0, 1.5, false),   // Empata con a, gana por discrepancia relevante.
    $fila('d', 1.5, 0.0, true),    // La mas baja, pero preliminar: no es candidata.
    $fila('e', 4.6, 0.0, false),   // Consolidada: no hay brecha que priorizar.
];
$orden = array_column(diagnostic::rank_priorities($matriz), 'code');
if ($orden !== ['b', 'c', 'a']) {
    printf("  FALLA  priorizacion esperado=b,c,a real=%s\n", implode(',', $orden));
    $fallidos++;
}

// Tope (RU-COA-003 y RU-COA-COMPLET-001): tres, o uno si hay dos o mas
// dimensiones incompletas.
$limites = [
    ['ninguna preliminar', [$fila('a', 2, 0, false), $fila('b', 2, 0, false)], 3],
    ['una preliminar', [$fila('a', 2, 0, true), $fila('b', 2, 0, false)], 3],
    ['dos preliminares', [$fila('a', 2, 0, true), $fila('b', 2, 0, true)], 1],
];
foreach ($limites as $l) {
    $real = diagnostic::priority_limit($l[1]);
    if ($real !== $l[2]) {
        printf("  FALLA  tope %-20s esperado=%d real=%d\n", $l[0], $l[2], $real);
        $fallidos++;
    }
}
if (\local_chatbot\pdp::MAX_PRIORIDADES !== 3) {
    echo "  FALLA  el tope general de prioridades no es 3 (RU-COA-003)\n";
    $fallidos++;
}

$total = count($casos) + count($cortes) + count($grupos) + count($completitudes)
    + count($discrepancias) + 1 + count($limites) + 3;
printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);

if ($fallidos === 0) {
    echo "Puntaje compuesto conforme a RU-CALC-001; niveles, completitud y priorizacion conformes a Doc 3.\n";
    echo "Recordatorio de gobernanza: es una regla funcional de priorización de apoyos,\n";
    echo "no una escala psicométrica ni una calificación del desempeño (RU-GOB-001).\n";
    exit(0);
}

echo "Cálculo NO conforme: revisar diagnostic.php antes de desplegar.\n";
exit(1);
