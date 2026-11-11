<?php
/**
 * Verifica las hojas de trabajo de la Fase 2 del Modulo 2 (diseno en seis etapas, Prompt Maestro 1 a 6).
 *
 * Pedido de Interfases del 21-10-2026: cada etapa pide lo que el Prompt Maestro dice, no se entra sin la ficha
 * validada ni se salta una etapa sin aceptar la anterior, los ajustes rehacen el producto completo y el producto
 * se descarga como un Word valido.
 *
 * Uso:  php cli/verificar_diseno.php
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/guardrails.php');
require_once(__DIR__ . '/../classes/exante.php');
require_once(__DIR__ . '/../classes/diseno.php');
require_once(__DIR__ . '/../classes/diagrama.php');
require_once(__DIR__ . '/../classes/documento_final.php');
require_once(__DIR__ . '/../classes/modulo2_setup.php');
require_once(__DIR__ . '/../classes/ruta_m2.php');

use local_chatbot\diagrama;
use local_chatbot\diseno;
use local_chatbot\documento_final;
use local_chatbot\exante;
use local_chatbot\guardrails;
use local_chatbot\modulo2_setup;
use local_chatbot\ruta_m2;

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

// --- Las etapas son las del Prompt Maestro ---
$etapas = diseno::etapas();
local_chatbot_verificar(array_keys($etapas) === range(1, 6), 'la Fase 2 tiene las etapas 1 a 6');
local_chatbot_verificar(array_keys(diseno::todas()) === range(1, 8), 'con la Fase 3 son las ocho etapas del Prompt Maestro');
local_chatbot_verificar(array_column($etapas, 'titulo') === ['Análisis diagnóstico', 'Diseño de la experiencia',
    'Secuencia instruccional', 'Materiales instruccionales de apoyo al aprendizaje', 'Plan de evaluación',
    'Instrumentos evaluativos'], 'los nombres de las etapas son los del Prompt Maestro');
local_chatbot_verificar($etapas[2]['incluye'] === ['Nombre de la experiencia de aprendizaje', 'Propósito',
    'Aprendizaje esperado', 'Criterios de evaluación', 'Contexto de aplicación', 'Modalidad', 'Estrategia didáctica',
    'Recursos', 'Condiciones de implementación'], 'la etapa 2 incluye los nueve elementos obligatorios');
local_chatbot_verificar(count($etapas[4]['incluye']) === 9 && strpos($etapas[4]['nota'], 'No incluye instrumentos') === 0,
    'la etapa 4 incluye sus nueve elementos y deja los instrumentos para la etapa 6');
local_chatbot_verificar(strpos($etapas[5]['incluye'][0], 'indicador de logro, momento de evaluación y responsable') !== false,
    'el plan de evaluación pide la tabla con sus siete columnas');
local_chatbot_verificar(count($etapas[6]['incluye']) === 5, 'la etapa 6 cubre los cinco tipos de instrumento');
foreach ($etapas as $n => $e) {
    local_chatbot_verificar(preg_match('/^Etapa_' . $n . '_[A-Za-z_]+$/', $e['archivo']) === 1,
        "el archivo Word de la etapa {$n} tiene un nombre sin tildes ni espacios");
}

// --- No se trabaja sin ficha ni se salta una etapa ---
local_chatbot_verificar(!diseno::can_open(1, false, []), 'sin la Fase 1 aprobada no se abre ni la etapa 1');
local_chatbot_verificar(diseno::can_open(1, true, []) && !diseno::can_open(2, true, []),
    'con la Fase 1 aprobada se abre solo la etapa 1');
local_chatbot_verificar(diseno::can_open(3, true, [1, 2]) && !diseno::can_open(4, true, [1, 2]),
    'aceptadas 1 y 2 se abre la 3, no la 4');
local_chatbot_verificar(!diseno::can_open(3, true, [1, 3]), 'sin la 2 aceptada la 3 no se abre aunque la 3 lo esté');
local_chatbot_verificar(diseno::can_open(7, true, [1, 2, 3, 4, 5, 6]) && !diseno::can_open(7, true, [1, 2, 3, 4, 5]),
    'la consolidación se abre con las seis etapas aceptadas');
local_chatbot_verificar(!diseno::can_open(8, true, [1, 2, 3, 4, 5, 6]) && diseno::can_open(8, true, [1, 2, 3, 4, 5, 6, 7]),
    'la evaluación se abre con la consolidación confirmada');
local_chatbot_verificar(!diseno::can_open(9, true, range(1, 8)) && !diseno::can_open(0, true, []), 'no hay etapas fuera de 1 a 8');
local_chatbot_verificar(diseno::current_stage([]) === 1 && diseno::current_stage([1, 2]) === 3
    && diseno::current_stage([1, 3]) === 2 && diseno::current_stage([1, 2, 3, 4, 5, 6]) === 7
    && diseno::current_stage(range(1, 7)) === 8,
    'la etapa en curso es la primera sin aceptar');

// --- Volver a las etapas con el diseno terminado (Interfases, 26-10-2026) ---
local_chatbot_verificar(diseno::entry_stage([]) === 1 && diseno::entry_stage([1, 2]) === 3,
    'sin etapa pedida se abre la primera sin aceptar de la Fase 2');
local_chatbot_verificar(diseno::entry_stage(range(1, 8)) === 1 && diseno::entry_stage(range(1, 6)) === 1,
    'con la Fase 2 completa se abre la etapa 1 para recorrer el diseño, no la etapa 8');
local_chatbot_verificar(diseno::entry_stage([1, 3, 4, 5, 6]) === 2, 'una etapa sin aceptar en medio es la que se abre');

// --- Varios disenos: nombre y estado en la lista ---
local_chatbot_verificar(exante::design_label(['aprendizaje' => 'Comunicar información'], 'Comunicando en el trabajo')
    === 'Comunicando en el trabajo', 'el diseño se nombra por su experiencia');
local_chatbot_verificar(exante::design_label(['aprendizaje' => "Comunicar\ninformación  clara", 'plan' => 'PF']) === 'Comunicar información clara',
    'sin experiencia, por el aprendizaje esperado');
local_chatbot_verificar(exante::design_label(['aprendizaje' => '', 'plan' => 'Plan X']) === 'Plan X'
    && exante::design_label([]) === '', 'luego por el plan formativo, y vacío si no hay nada');
local_chatbot_verificar(mb_strlen(exante::design_label(['aprendizaje' => str_repeat('palabra ', 30)])) <= 90, 'un nombre largo se acorta');

// --- Borrar un diseno: uno solo, y queda en uso el que corresponde ---
local_chatbot_verificar(exante::next_in_use([5, 9, 12], 5, 12) === 12 && exante::next_in_use([5, 9, 12], 9, 12) === 12,
    'borrar uno que no estaba en uso no cambia el que está en uso');
local_chatbot_verificar(exante::next_in_use([5, 9, 12], 12, 12) === 9 && exante::next_in_use([12, 9, 5], 12, 12) === 9,
    'borrar el que estaba en uso deja en uso el más reciente de los que quedan');
local_chatbot_verificar(exante::next_in_use([7], 7, 7) === null && exante::next_in_use([], 7, 0) === null,
    'borrar el único diseño no deja ninguno en uso');
local_chatbot_verificar(exante::next_in_use([5, 9], 5, 0) === 9, 'sin uno en uso, queda el más reciente');
local_chatbot_verificar(array_sum(array_map(static function($c) {
    return strpos($c, 'borrado') === false ? 0 : 1;
}, [exante::DELETED, exante::MISSING, exante::VALIDATING])) === 1, 'los tres resultados de borrar son distintos');
local_chatbot_verificar(ruta_m2::design_status(['ficha' => ruta_m2::FICHA_SIN])[0] === 'r2_status_fase1'
    && ruta_m2::design_status(['ficha' => ruta_m2::FICHA_OK, 'aceptadas' => [1, 2]]) === ['r2_status_fase2', 2]
    && ruta_m2::design_status(['ficha' => ruta_m2::FICHA_OK, 'aceptadas' => range(1, 6)])[0] === 'r2_status_fase3'
    && ruta_m2::design_status(['ficha' => ruta_m2::FICHA_OK, 'aceptadas' => range(1, 7), 'aprobado' => true,
        'evaluacion' => ['total' => 35]]) === ['r2_status_done', 35], 'cada diseño de la lista dice en qué fase va');

// --- Instrucciones al modelo ---
$ficha = "2. Aprendizaje esperado\n- 2.1. Aprendizaje esperado: Comunicar información...";
$informe = ['debilidades' => ['Falta evidencia de desempeño.'], 'sugerencias' => ['Agregar desempeño en 7.3.']];
$p1 = diseno::build_prompt(1, $ficha, $informe, [], []);
local_chatbot_verificar(strpos($p1, 'ETAPA 1: ANÁLISIS DIAGNÓSTICO') !== false, 'el prompt nombra la etapa');
foreach ($etapas[1]['incluye'] as $item) {
    if (strpos($p1, '- ' . $item) === false) {
        local_chatbot_verificar(false, "el prompt de la etapa 1 pide «{$item}»");
    }
}
local_chatbot_verificar(strpos($p1, guardrails::isolate_user_content($ficha)) !== false, 'la ficha viaja aislada');
local_chatbot_verificar(strpos($p1, guardrails::injection_clause()) !== false, 'el prompt trae la cláusula contra inyección');
local_chatbot_verificar(strpos($p1, '- Falta evidencia de desempeño.') !== false
    && strpos($p1, '- Agregar desempeño en 7.3.') !== false, 'las observaciones de la validación viajan');
local_chatbot_verificar(strpos($p1, '"adecuadamente"') === false && strpos($p1, 'Nunca utilices conceptos relativos a «pedagógico»') !== false
    && strpos($p1, 'Usa como fuente principal la ficha de diagnóstico y el Plan Formativo') !== false,
    'Prompt Maestro del 30-10: sin la regla de juicios de valor, sin «pedagogía» y con el Plan Formativo como fuente principal');
foreach (['No inventes aprendizajes esperados ni criterios', 'Utiliza al menos 3 de los criterios de evaluación declarados',
        'Si el plan formativo declara menos de 3, utiliza todos los disponibles, infórmalo como vacío de información',
        'alineación constructiva', 'lenguaje inclusivo', 'sin alterar el plan formativo', 'Verificación de calidad',
        'consistencia con el tiempo asignado a la experiencia (2.3) y con el tiempo total disponible (2.2)',
        'formación de personas adultas o formación laboral'] as $regla) {
    local_chatbot_verificar(strpos($p1, $regla) !== false, "el prompt trae la regla «{$regla}»");
}
local_chatbot_verificar(strpos($p1, 'No se adjuntó el Plan Formativo, que es documento de referencia obligatorio') !== false,
    'sin el Plan Formativo el prompt lo dice');
local_chatbot_verificar(strpos($p1, 'AJUSTES QUE PIDE') === false, 'sin ajustes no hay sección de ajustes');
local_chatbot_verificar(strpos($p1, '## Etapa 1: Análisis diagnóstico') !== false, 'el producto empieza con el título de la etapa');

$p3 = diseno::build_prompt(3, $ficha, [], [1 => 'Síntesis: grupo heterogéneo.', 2 => 'Nombre: Comunicando en el trabajo.'],
    ['Plan Formativo'], 'Versión anterior', 'Agregar una actividad de cierre');
local_chatbot_verificar(strpos($p3, 'PRODUCTO ACEPTADO DE LA ETAPA 1 (Análisis diagnóstico)') !== false
    && strpos($p3, 'PRODUCTO ACEPTADO DE LA ETAPA 2 (Diseño de la experiencia)') !== false,
    'las etapas aceptadas viajan con su nombre');
local_chatbot_verificar(strpos($p3, 'la suma total no debe superar el tiempo asignado a la experiencia de aprendizaje '
    . '(ficha, subsección 2.3)') !== false, 'la secuencia, en minutos, no supera el tiempo de la ficha (2.3)');
// Prompt Maestro del 30-10-2026 (15:00): la etapa 1 recibe el resultado de la Etapa 0 y su registro de observaciones.
$p1v = diseno::build_prompt(1, $ficha, ['condicion' => 'observaciones', 'debilidades' => [],
    'items' => ['c6_1' => ['estado' => 'parcial', 'observacion' => 'Falta una cama clínica'], 'c1_1' => ['estado' => 'cumple']]],
    [], []);
local_chatbot_verificar(strpos($p1v, 'RESULTADO DE LA VALIDACIÓN DE LA FICHA (ETAPA 0): ⚠ Aprobado con observaciones.') !== false
    && strpos($p1v, 'Registro de observaciones no resueltas de la Etapa 0') !== false
    && strpos($p1v, 'Falta una cama clínica') !== false && strpos($p1v, 'c1_1') === false
    && strpos($p1v, 'incorpóralas como restricciones o prioridades de diseño') !== false,
    'la etapa 1 recibe el resultado de la Etapa 0 y las observaciones no resueltas, como restricciones o prioridades');
local_chatbot_verificar(strpos($p1, 'RESULTADO DE LA VALIDACIÓN') === false, 'sin condición no se inventa un resultado');
local_chatbot_verificar(strpos($p3, 'Se adjunta el Plan Formativo, documento de referencia obligatorio') !== false,
    'con el Plan Formativo el prompt lo usa como referencia obligatoria');
$et = diseno::etapas();
local_chatbot_verificar(in_array('Nota de uso de documentos complementarios (plan formativo y perfil ocupacional)', $et[1]['incluye'], true)
    && in_array('Resultado de la validación (Etapa 0) y observaciones no resueltas, incorporadas como restricciones o '
        . 'prioridades de diseño', $et[1]['incluye'], true)
    && in_array('Síntesis de la observación obligatoria 2.4 (pertinencia y factibilidad de los criterios) y de las '
        . 'observaciones finales de la ficha', $et[1]['incluye'], true)
    && in_array('Alcance de cada fase', $et[3]['incluye'], true) && !in_array('Objetivos específicos', $et[3]['incluye'], true)
    && strpos(implode(' ', $et[3]['incluye']), 'sin formularlo') === false
    && strpos(diseno::build_prompt(3, 'F', [], [], []), 'sin formularlo como objetivo específico') !== false
    && in_array('Actividades (persona facilitadora / participantes)', $et[3]['incluye'], true)
    && in_array('Uso didáctico', $et[4]['incluye'], true) && strpos(implode(' ', $et[4]['incluye']), 'pedagóg') === false
    && in_array('Alcance', $et[4]['incluye'], true)
    && $et[4]['nota'] === 'No incluye instrumentos de evaluación: estos se desarrollan en la Etapa 6.',
    'etapas 1, 3 y 4 con los textos del Prompt Maestro del 30-10 (15:00)');
$pend = diseno::pending_observations(['items' => ['c2_4' => ['estado' => 'parcial', 'observacion' => 'Tiempo justo'],
    'c1_1' => ['estado' => 'cumple', 'observacion' => '']]]);
local_chatbot_verificar(strpos($pend, 'c2_4') !== false && strpos($pend, 'Tiempo justo') !== false && strpos($pend, 'c1_1') === false
    && strpos($pend, 'Registro de observaciones no resueltas de la Etapa 0') === 0
    && diseno::pending_observations([]) === 'La validación de la ficha (Etapa 0) no dejó observaciones no resueltas.',
    'la evaluación recibe el registro de observaciones no resueltas de la Etapa 0');
// --- Etapa 8: límite por observaciones no resueltas (ajuste del cliente del 30-10-2026) ---
$v = diseno::rubric_links();
local_chatbot_verificar(count($v) === 33 && $v['c1_3'] === ['r1'] && $v['c1_2'] === ['r1'] && $v['c2_1'] === ['r2', 'r6']
    && $v['c2_5'] === ['r2', 'r6'] && $v['c2_4'] === ['r4', 'r8'] && $v['c3_1'] === ['r3'] && $v['c4_2'] === ['r1', 'r4']
    && $v['c5_3'] === ['r3', 'r8'] && $v['c6_4'] === ['r5', 'r8'] && $v['c7_1'] === ['r3'] && $v['c7_2'] === ['r3']
    && $v['c7_3'] === ['r6'] && $v['c7_4'] === ['r7'] && $v['c8_2'] === ['r8'] && $v['c9_2'] === ['r1'],
    'vínculo sección o ítem del checklist → criterios de la rúbrica, como lo pide el cliente (tiempo → 4 y 8; sección 7 por ítem)');
$todos = array_merge(...array_values($v));
local_chatbot_verificar(!in_array('r9', $todos, true), 'el criterio 9 (Calidad formal del documento) no se limita');
$val = ['items' => ['c1_1' => ['estado' => 'cumple'], 'c2_4' => ['estado' => 'parcial', 'observacion' => 'Tiempo justo'],
    'c6_1' => ['estado' => 'no_cumple'], 'c6_2' => ['estado' => 'parcial'], 'c9_1' => ['estado' => 'parcial']]];
$lims = diseno::rubric_limits($val);
local_chatbot_verificar($lims === ['r1' => ['c9_1'], 'r4' => ['c2_4'], 'r5' => ['c6_1', 'c6_2'], 'r8' => ['c2_4', 'c6_1', 'c6_2']]
    && diseno::rubric_limits([]) === [] && diseno::rubric_limits(['items' => ['c1_1' => ['estado' => 'cumple']]]) === [],
    'criterios limitados en el orden de la rúbrica, una sola vez cada uno, con los ítems que los originan');
local_chatbot_verificar(diseno::limit_origins(['c2_4']) === 'Sección 2, «El tiempo para el desarrollo y evaluación del '
    . 'aprendizaje es suficiente»', 'el origen del límite se dice con la sección y el ítem del checklist');
$pe8 = diseno::build_eval_prompt('D', 'F', [], false, false, $val);
local_chatbot_verificar(strpos($pe8, 'LÍMITE POR OBSERVACIONES NO RESUELTAS') !== false
    && strpos($pe8, 'que no fueron corregidos en la ficha antes de iniciar la Etapa 1') !== false
    && strpos($pe8, '- r4 (Secuencia instruccional): Sección 2, «El tiempo para el desarrollo') !== false
    && strpos($pe8, 'El límite es un máximo, no un puntaje asignado') !== false
    && strpos($pe8, 'Puntaje máximo alcanzable: 32 de 36.') !== false && strpos($pe8, '"limitados"') === false
    && strpos($pe8, 'el criterio 9 (Calidad formal del documento) no se limita') !== false,
    'etapa 8: el prompt trae los criterios limitados ya calculados, su origen y el máximo alcanzable');
local_chatbot_verificar(strpos(diseno::build_eval_prompt('D', 'F', [], false, false, []),
    'ningún criterio queda limitado: el puntaje máximo alcanzable es 36') !== false, 'sin observaciones no resueltas, 36');
// El tope 3 lo aplica el código con los límites calculados; lo que diga el modelo no cuenta.
$evlim = ['criterios' => [], 'limitados' => ['r9'], 'fortalezas' => [], 'debilidades' => [], 'recomendaciones' => ['a', 'b']];
foreach (array_keys(diseno::rubrica()) as $id) {
    $evlim['criterios'][$id] = ['puntaje' => 4, 'justificacion' => str_repeat('Evidencia concreta del diseño. ', 4)];
}
$evlim['criterios']['r5']['puntaje'] = 2;
$lim = diseno::parse_eval(json_encode($evlim), true, $lims + ['r99' => ['c1_1']]);
local_chatbot_verificar($lim !== null && $lim['limitados'] === ['r1', 'r4', 'r5', 'r8'] && $lim['maximo'] === 32
    && $lim['criterios']['r1']['puntaje'] === 3 && $lim['criterios']['r5']['puntaje'] === 2
    && $lim['criterios']['r9']['puntaje'] === 4 && $lim['total'] === 3 + 4 + 4 + 3 + 2 + 4 + 4 + 3 + 4,
    'tope 3 en los criterios limitados (el límite es un máximo: un 2 queda en 2); ids desconocidos y los del modelo no cuentan');
local_chatbot_verificar(diseno::parse_eval(json_encode($evlim))['maximo'] === 36 && diseno::parse_eval(json_encode($evlim))['limitados'] === [],
    'sin límites, el máximo alcanzable es 36');
$h = diseno::evaluation_html($lim);
local_chatbot_verificar(strpos($h, 'Puntaje máximo alcanzable</th><th>32 / 36') !== false
    && strpos($h, 'Puntaje máximo 3 por observaciones no resueltas: Sección 6, «Los recursos son suficientes') !== false
    && substr_count($h, '3 (limitado)') === 3 && substr_count($h, '2 / 4 (máx. 3)') === 1
    && strpos($h, 'O bien: cumple el nivel 4, pero el criterio está limitado') !== false
    && substr_count($h, 'O bien:') === 3,
    'el informe de la etapa 8 da «3 (limitado)» con su origen y el máximo alcanzable (pauta 3 de la rúbrica)');
$sinlim = diseno::evaluation_html(['criterios' => array_fill_keys(array_keys(diseno::rubrica()),
    ['puntaje' => 3, 'justificacion' => 'x']), 'total' => 27, 'banda' => diseno::band(27)]);
local_chatbot_verificar(strpos($sinlim, 'O bien:') === false && strpos($sinlim, '3 / 4') !== false
    && strpos($sinlim, 'Incorpora la información de la ficha con omisiones menores; las decisiones principales son coherentes con ella.</td>') !== false,
    'un 3 sin límite no muestra el caso «O bien» del nivel 3');
$pe8b = diseno::build_eval_prompt('D', 'F', [], false, false, []);
local_chatbot_verificar(strpos($pe8b, 'PAUTAS DE APLICACIÓN DE LA RÚBRICA:') !== false
    && strpos($pe8b, 'Si el diseño cumple solo parte del nivel 4, corresponde el nivel 3 o inferior.') !== false
    && strpos($pe8b, 'Es «importante» cuando exige rediseñar una fase, actividad, evidencia o instrumento.') !== false
    && strpos($pe8b, 'el puntaje se informa como «3 (limitado)»') !== false
    && strpos($pe8b, 'PAUTAS DE APLICACIÓN') < strpos($pe8b, 'RÚBRICA (id. criterio):'),
    'la etapa 8 recibe las tres pautas de aplicación de la rúbrica, textuales');
$vieja = diseno::parse_eval(json_encode($evlim));
unset($vieja['limites'], $vieja['maximo']);
$vieja['limitados'] = ['r2'];
local_chatbot_verificar(strpos(diseno::evaluation_html(diseno::parse_eval(json_encode($evlim))), 'máximo alcanzable') === false
    && strpos(diseno::evaluation_html($vieja), 'Puntaje máximo 3 por observaciones no resueltas.</em>') !== false
    && strpos(diseno::evaluation_html($vieja), 'Puntaje máximo alcanzable</th><th>35 / 36') !== false,
    'sin límites no aparece el máximo; una evaluación de la v1.20.13 (solo «limitados») se sigue mostrando');
local_chatbot_verificar(diseno::band(32)['texto'] === 'Aprobado con alto estándar técnico'
    && diseno::band(25)['texto'] === 'Aprobado con ajustes menores'
    && diseno::band(18)['texto'] === 'Requiere ajustes importantes antes de aprobación' && diseno::band(17)['texto'] === 'No aprobado',
    'interpretación con las denominaciones exactas del Prompt Maestro');
local_chatbot_verificar(in_array('Puntaje total (máx. 36) y puntaje máximo alcanzable', diseno::etapas_fase3()[8]['incluye'], true),
    'la etapa 8 entrega el puntaje total y el máximo alcanzable');
local_chatbot_verificar(strpos($p3, guardrails::isolate_user_content('Agregar una actividad de cierre')) !== false
    && strpos($p3, guardrails::isolate_user_content('Versión anterior')) !== false
    && strpos($p3, 'entrega el producto completo de la etapa') !== false,
    'los ajustes viajan aislados junto a la versión actual y piden el producto completo');
$larga = diseno::build_prompt(2, $ficha, [], [1 => str_repeat('x', diseno::PREVIA_MAX + 500)], []);
local_chatbot_verificar(strpos($larga, str_repeat('x', diseno::PREVIA_MAX + 1)) === false, 'una etapa previa muy larga se recorta');

// --- Limpieza de la respuesta ---
local_chatbot_verificar(diseno::clean_markdown("```markdown\n## Etapa 1\nTexto\n```") === "## Etapa 1\nTexto",
    'se quita el bloque de código que envuelve la respuesta');
local_chatbot_verificar(diseno::clean_markdown("## Etapa 1\n```\ncodigo\n```\nfin") === "## Etapa 1\n```\ncodigo\n```\nfin",
    'un bloque de código interno se respeta');
local_chatbot_verificar(diseno::clean_markdown(null) === '', 'sin respuesta queda vacío');

// --- Word ---
if (class_exists('ZipArchive')) {
    $ruta = tempnam(sys_get_temp_dir(), 'dis') . '.docx';
    $html = '<h2>Etapa 5: Plan de evaluación</h2><p>Texto con <strong>negrita</strong> y <em>cursiva</em> &amp; símbolos &lt;x&gt;.</p>'
        . '<ul><li>Uno<ul><li>Uno punto uno</li></ul></li><li>Dos</li></ul><ol><li>Primero</li></ol>'
        . '<table><thead><tr><th>Criterio</th><th>Instrumento</th></tr></thead><tbody><tr><td>Redacta mensajes</td>'
        . '<td><p>Rúbrica</p></td></tr></tbody></table>Texto suelto final';
    local_chatbot_verificar(diseno::html_to_docx($ruta, 'Etapa 5: Plan de evaluación', 'Curso · 21-10-2026', $html),
        'se genera el Word');
    $zip = new ZipArchive();
    $zip->open($ruta);
    $partes = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $partes[] = $zip->getNameIndex($i);
    }
    $doc = $zip->getFromName('word/document.xml');
    $estilos = $zip->getFromName('word/styles.xml');
    $zip->close();
    sort($partes);
    local_chatbot_verificar($partes === ['[Content_Types].xml', '_rels/.rels', 'word/_rels/document.xml.rels',
        'word/document.xml', 'word/styles.xml'], 'el Word tiene las partes de un .docx');
    $xml = new DOMDocument();
    local_chatbot_verificar(@$xml->loadXML($doc) && @(new DOMDocument())->loadXML($estilos), 'el XML del Word está bien formado');
    local_chatbot_verificar(strpos($doc, '<w:pStyle w:val="Heading2"/>') !== false && strpos($doc, '<w:pStyle w:val="Title"/>') !== false,
        'los títulos usan los estilos de Word');
    local_chatbot_verificar(strpos($doc, '<w:b/></w:rPr><w:t xml:space="preserve">negrita') !== false
        && strpos($doc, '<w:i/></w:rPr><w:t xml:space="preserve">cursiva') !== false, 'negritas y cursivas se conservan');
    local_chatbot_verificar(substr_count($doc, '<w:tbl>') === 1 && substr_count($doc, '<w:tc>') === 4
        && strpos($doc, 'w:fill="E8F0F7"') !== false, 'la tabla sale con sus celdas y el encabezado sombreado');
    local_chatbot_verificar(strpos($doc, '<w:b/></w:rPr><w:t xml:space="preserve">Criterio') !== false, 'el encabezado de la tabla va en negrita');
    local_chatbot_verificar(strpos($doc, 'w:left="1134"') !== false, 'la lista anidada lleva más sangría');
    $texto = exante::docx_text($ruta);
    $orden = ['Etapa 5: Plan de evaluación', 'Curso · 21-10-2026', 'negrita', '& símbolos <x>.', '• Uno', '• Uno punto uno',
        '• Dos', '1. Primero', 'Criterio', 'Redacta mensajes', 'Rúbrica', 'Texto suelto final'];
    $pos = -1;
    $enorden = true;
    foreach ($orden as $pieza) {
        $p = strpos((string) $texto, $pieza, $pos + 1);
        if ($p === false) {
            $enorden = false;
            echo "    falta en el Word: {$pieza}\n";
            break;
        }
        $pos = $p;
    }
    local_chatbot_verificar($enorden, 'todo el contenido llega al Word, en orden y con los símbolos escapados');
    @unlink($ruta);
}

// --- Boton de la pestana Fase 2 ---
$vacia = modulo2_setup::add_design_button('', 'u2');
local_chatbot_verificar(strpos($vacia, 'href="u2"') !== false && strpos($vacia, modulo2_setup::BOTON_DISENO) !== false,
    'el botón al asistente de la Fase 2 se agrega aunque la pestaña no tenga texto');
local_chatbot_verificar(modulo2_setup::add_design_button($vacia, 'u2') === $vacia, 'agregar el botón de la Fase 2 es idempotente');
local_chatbot_verificar(substr(modulo2_setup::add_design_button('<div>Texto</div>', 'u2'), -6) === '</div>',
    'con texto, el botón queda al final dentro del contenedor');

local_chatbot_verificar(modulo2_setup::remove_design_button(modulo2_setup::add_design_button('<div>Texto</div>', 'u2'))
    === '<div>Texto</div>' && modulo2_setup::remove_design_button($vacia) === '', 'el botón de la Fase 2 se puede quitar para moverlo');
$curso = [3 => ['name' => 'Fase 1: Diagnóstico', 'level' => 0], 4 => ['name' => 'Diagnóstico', 'level' => 2],
    6 => ['name' => 'Fase 2: Diseño de la Experiencia de Aprendizaje', 'level' => 0],
    7 => ['name' => 'Diseña tu Experiencia de Aprendizaje', 'level' => 2],
    8 => ['name' => 'Fase 3: Validación Técnica', 'level' => 0], 9 => ['name' => 'Valida el Diseño', 'level' => 2]];
local_chatbot_verificar(modulo2_setup::tab_last_section($curso, 2) === 7, 'el final de la pestaña Fase 2 es su última subsección');
local_chatbot_verificar(modulo2_setup::tab_last_section($curso, 1) === 4, 'el final de la pestaña Fase 1 es su última subsección');
unset($curso[7]);
local_chatbot_verificar(modulo2_setup::tab_last_section($curso, 2) === 6, 'una pestaña sin subsecciones usa su propia sección');
local_chatbot_verificar(modulo2_setup::tab_last_section($curso, 4) === null, 'sin la fase no hay sección');

// --- Boton al final de la bienvenida: lleva al comienzo de la Fase 1 ---
$real = [0 => ['name' => 'Bienvenida e Instrucciones Generales', 'level' => 0], 1 => ['name' => 'Descripción', 'level' => 2],
    2 => ['name' => 'Trabajo con Asisistente IA', 'level' => 2], 3 => ['name' => 'Fase 1: Diagnóstico y Validación', 'level' => 0],
    4 => ['name' => 'Diagnóstico (evaluación ex-ante)', 'level' => 2]];
local_chatbot_verificar(modulo2_setup::welcome_last_section($real) === 2, 'el final de la bienvenida es la subsección anterior a la Fase 1');
local_chatbot_verificar(modulo2_setup::welcome_last_section([3 => ['name' => 'Fase 1: X', 'level' => 0]]) === null
    && modulo2_setup::welcome_last_section([0 => ['name' => 'Bienvenida', 'level' => 0]]) === null,
    'sin pestaña Fase 1 o sin nada antes de ella no hay sección');
$b = modulo2_setup::add_phase1_button('<div><p>Texto</p></div>', 'uf1');
local_chatbot_verificar(strpos($b, 'href="uf1"') !== false && strpos($b, modulo2_setup::BOTON_FASE1) !== false
    && substr($b, -6) === '</div>' && strpos($b, '<!-- IR A FASE 1 -->') !== false,
    'el botón a la Fase 1 queda al final, dentro del contenedor');
local_chatbot_verificar(modulo2_setup::add_phase1_button($b, 'uf1') === $b, 'agregar el botón a la Fase 1 es idempotente');

// --- Fase 3: rubrica, consolidacion y evaluacion ---
$rub = diseno::rubrica();
local_chatbot_verificar(array_keys($rub) === ['r1', 'r2', 'r3', 'r4', 'r5', 'r6', 'r7', 'r8', 'r9'], 'la rúbrica tiene sus 9 criterios');
local_chatbot_verificar($rub['r2']['niveles'][1] === 'No hay alineación, o se modifican, omiten o inventan el aprendizaje '
    . 'esperado o los criterios.' && $rub['r9']['nombre'] === 'Calidad formal del documento'
    && $rub['r4']['niveles'][4] === 'Progresión clara de inicio, desarrollo y cierre; cada fase tiene alcance, actividades, '
    . 'recursos, tiempo en minutos y evidencias parciales. El tiempo total no supera el asignado (2.3).'
    && $rub['r8']['niveles'][3] === 'Viable con ajustes menores (por ejemplo, reorganizar espacios o turnos) que no '
    . 'requieren recursos adicionales relevantes. O bien: cumple el nivel 4, pero el criterio está limitado por una '
    . 'observación no resuelta del diagnóstico (sección 2 (ítem de tiempo) y secciones 5, 6 y 8 del checklist).'
    && strpos($rub['r9']['niveles'][3], 'O bien') === false,
    'la rúbrica va textual (versión del cliente del 30-10-2026; el criterio 9 no se limita)');
$niveles = true;
foreach ($rub as $c) {
    $niveles = $niveles && array_keys($c['niveles']) === [4, 3, 2, 1];
}
local_chatbot_verificar($niveles, 'cada criterio tiene los niveles 4 a 1');
foreach ([36 => 'alto', 32 => 'alto', 31 => 'menores', 25 => 'menores', 24 => 'importantes', 18 => 'importantes',
        17 => 'no', 9 => 'no'] as $puntaje => $clave) {
    local_chatbot_verificar(diseno::band($puntaje)['clave'] === $clave, "{$puntaje} puntos es «{$clave}», como dice la rúbrica");
}
local_chatbot_verificar(diseno::band(25)['aprobado'] && !diseno::band(24)['aprobado'], 'se aprueba desde 25 puntos');
// Punto 14 (29-10-2026): el documento final se descarga siempre que haya evaluacion; los 25 puntos quedan como informacion.
local_chatbot_verificar(diseno::can_download(['banda' => diseno::band(12)], true) && diseno::can_download(['banda' => diseno::band(30)], true)
    && !diseno::can_download(null, true), 'con el interruptor, el documento final se descarga con cualquier puntaje (no sin evaluación)');
local_chatbot_verificar(!diseno::can_download(['banda' => diseno::band(24)], false) && diseno::can_download(['banda' => diseno::band(25)], false),
    'con el interruptor apagado, solo desde 25 puntos, como antes');
local_chatbot_verificar(diseno::band(31)['texto'] === 'Aprobado con ajustes menores', 'la interpretación va con el texto de la rúbrica');

$respuesta = ['criterios' => [], 'total' => 99, 'fortalezas' => ['F1'], 'debilidades' => [], 'recomendaciones' => ['R1', '']];
foreach (array_keys($rub) as $i => $id) {
    $respuesta['criterios'][$id] = ['puntaje' => $i < 4 ? 4 : 3, 'justificacion' => 'Porque <b>sí</b>: el diseño cita la evidencia concreta en la etapa 3 y en la actividad 2.'];
}
$ev = diseno::parse_eval(json_encode($respuesta));
local_chatbot_verificar($ev !== null && $ev['total'] === 31, 'el total lo suma el código (31), no el que diga el modelo');
local_chatbot_verificar($ev['banda']['clave'] === 'menores' && $ev['recomendaciones'] === ['R1'], 'banda y listas limpias');
// Evaluacion honesta (Fase 3): un 4 sin evidencia citada baja a 3; el prompt pide ojo critico y 2 recomendaciones.
local_chatbot_verificar(diseno::supported_score(4, 'Bien') === 3 && diseno::supported_score(4, $respuesta['criterios']['r1']['justificacion']) === 4
    && diseno::supported_score(3, 'Bien') === 3 && diseno::supported_score(2, '') === 2, 'un puntaje 4 sin evidencia citada baja a 3');
$sinevidencia = $respuesta;
$sinevidencia['criterios']['r1']['justificacion'] = 'Bien';
local_chatbot_verificar(diseno::parse_eval(json_encode($sinevidencia))['total'] === 30, 'el total ya descuenta el 4 sin evidencia');
local_chatbot_verificar(diseno::parse_eval(json_encode($sinevidencia), false)['total'] === 31,
    'con el interruptor «iaapoyo» apagado el 4 sin evidencia se respeta');
local_chatbot_verificar(strpos(diseno::build_eval_prompt('D', 'F', []), 'OJO CRÍTICO') !== false
    && strpos(diseno::build_eval_prompt('D', 'F', []), 'al menos 2 recomendaciones concretas') !== false,
    'la evaluación pide ojo crítico, evidencia en cada 4 y 2 recomendaciones aunque sea 36');
local_chatbot_verificar(diseno::parse_eval("```json\n" . json_encode($respuesta) . "\n```") !== null, 'se lee entre bloques de código');
$malo = $respuesta;
unset($malo['criterios']['r5']);
local_chatbot_verificar(diseno::parse_eval(json_encode($malo)) === null, 'sin un criterio la evaluación no se usa');
$malo = $respuesta;
$malo['criterios']['r1']['puntaje'] = 5;
local_chatbot_verificar(diseno::parse_eval(json_encode($malo)) === null, 'un puntaje fuera de 1 a 4 invalida la evaluación');
$malo['criterios']['r1']['puntaje'] = 2.5;
local_chatbot_verificar(diseno::parse_eval(json_encode($malo)) === null, 'un puntaje con decimales invalida la evaluación');
$malo['criterios']['r1']['puntaje'] = '3';
local_chatbot_verificar(diseno::parse_eval(json_encode($malo)) !== null, 'un puntaje entero escrito como texto se acepta');
$html = diseno::evaluation_html($ev);
local_chatbot_verificar(strpos($html, '31 / 36') !== false && strpos($html, 'Aprobado con ajustes menores') !== false
    && strpos($html, 'Porque &lt;b&gt;sí&lt;/b&gt;') !== false && strpos($html, $rub['r1']['niveles'][4]) !== false,
    'la evaluación se muestra con total, banda, nivel y justificación escapada');

$pe = diseno::build_eval_prompt('DISEÑO X', 'FICHA Y', []);
$todos = true;
foreach ($rub as $id => $c) {
    foreach ($c['niveles'] as $n => $t) {
        $todos = $todos && strpos($pe, 'Nivel ' . $n . ' (' . $n . ' pts): ' . $t) !== false;
    }
}
local_chatbot_verificar($todos, 'el prompt de evaluación trae los 36 descriptores de la rúbrica');
local_chatbot_verificar(strpos($pe, guardrails::isolate_user_content('DISEÑO X')) !== false
    && strpos($pe, guardrails::isolate_user_content('FICHA Y')) !== false, 'diseño y ficha viajan aislados');

$filas = [];
foreach ([1, 2, 3, 4, 5, 6] as $n) {
    $filas[$n] = (object) ['etapa' => $n, 'estado' => diseno::ACEPTADO, 'contenido' => '<h2>Etapa ' . $n . '</h2>', 'huella' => null];
}
$cons = diseno::consolidate(array_map(static function($f) {
    return $f->contenido;
}, array_reverse($filas, true)) + [7 => 'no va']);
local_chatbot_verificar(strpos($cons, 'Etapa 1') < strpos($cons, 'Etapa 6') && strpos($cons, 'no va') === false,
    'la consolidación lleva las seis etapas en orden y nada más');
$h = diseno::stages_fingerprint($filas);
$filas[7] = (object) ['etapa' => 7, 'estado' => diseno::ACEPTADO, 'contenido' => $cons, 'huella' => $h,
    'informe' => null, 'timemodified' => 1];
local_chatbot_verificar(in_array(7, diseno::accepted($filas), true), 'la consolidación confirmada cuenta como aceptada');
$filas[8] = (object) ['etapa' => 8, 'estado' => diseno::BORRADOR, 'contenido' => null, 'huella' => $h,
    'informe' => json_encode($ev), 'timemodified' => 5];
local_chatbot_verificar(diseno::current_evaluation($filas)['total'] === 31, 'la evaluación vigente se lee');
$filas[3]->contenido = '<h2>Etapa 3 corregida</h2>';
local_chatbot_verificar(!in_array(7, diseno::accepted($filas), true), 'si cambia una etapa, la consolidación deja de valer');
local_chatbot_verificar(diseno::current_evaluation($filas) === null, 'y la evaluación anterior tampoco vale');
local_chatbot_verificar(diseno::experience_name("Nombre de la experiencia de aprendizaje: Comunicando en el trabajo\nPropósito")
    === 'Comunicando en el trabajo', 'se toma el nombre de la experiencia para el documento final');
local_chatbot_verificar(diseno::experience_name('Sin nombre') === '', 'sin nombre queda vacío');
local_chatbot_verificar(diseno::experience_name("Nombre de la experiencia: Comunicación Laboral Efectiva (7 palabras).\n")
    === 'Comunicación Laboral Efectiva', 'el nombre se toma sin acotaciones del modelo al final');

// --- Pestana Fase 3 ---
$f3 = modulo2_setup::add_validation_buttons('<p>Texto</p>', 'ur', 'u7');
local_chatbot_verificar(strpos($f3, 'href="ur"') < strpos($f3, 'href="u7"') && strpos($f3, modulo2_setup::BOTON_RUBRICA) !== false
    && strpos($f3, modulo2_setup::BOTON_VALIDACION) !== false, 'la pestaña Fase 3 enlaza la rúbrica y termina con el botón al asistente');
local_chatbot_verificar(modulo2_setup::add_validation_buttons($f3, 'ur', 'u7') === $f3, 'los enlaces de la Fase 3 son idempotentes');
local_chatbot_verificar(strpos(modulo2_setup::add_validation_buttons('', '', 'u7'), 'RUBRICA') === false,
    'sin recurso de rúbrica solo va el botón');

// --- Textos rediseñados por el cliente (24-10): sus propios botones con «#» se enlazan, no se duplican ---
$f2cli = '<div><!-- REEMPLAZA # POR EL ENLACE REAL AL ASISTENTE IA --> <a style="display: inline-block;" href="#"> Acceder al asistente IA </a></div>';
$f2 = modulo2_setup::add_design_button($f2cli, 'u2');
local_chatbot_verificar(strpos($f2, 'href="u2"> Acceder al asistente IA </a>') !== false && strpos($f2, 'REEMPLAZA') === false
    && strpos($f2, modulo2_setup::BOTON_DISENO) === false, 'Fase 2: se enlaza el botón del cliente y no se agrega otro');
local_chatbot_verificar(modulo2_setup::add_design_button($f2, 'u2') === $f2, 'enlazar el botón del cliente es idempotente');
$f3cli = '<div><!-- REEMPLAZA # POR LA URL REAL DEL ARCHIVO EN MOODLE --> <a style="x" href="#"> Revisar y descargar la rúbrica </a>'
    . '<p>Cierre</p></div>';
$f3b = modulo2_setup::add_validation_buttons($f3cli, 'ur', 'u7');
local_chatbot_verificar(strpos($f3b, 'href="ur"> Revisar y descargar la rúbrica </a>') !== false
    && strpos($f3b, modulo2_setup::BOTON_RUBRICA) === false && strpos($f3b, 'href="u7"') > strpos($f3b, 'Cierre')
    && strpos($f3b, 'REEMPLAZA') === false, 'Fase 3: se enlaza la rúbrica del cliente y el botón al asistente va al final');
local_chatbot_verificar(modulo2_setup::add_validation_buttons($f3b, 'ur', 'u7') === $f3b, 'los ajustes de la Fase 3 del cliente son idempotentes');

// --- Diagramas de cajas y flechas (pedido del 02-11-2026): tabla editable, no dibujo de caracteres ---
$d4 = diagrama::parse("modo: ciclo\ntitulo: Rotación por estaciones\nEstación A / Aseo (1.5, 1.6)\n- Estación B / Vestimenta\n"
    . "Estación C / Cama | Estación D / Registro");
local_chatbot_verificar($d4 !== null && $d4['modo'] === 'ciclo' && $d4['titulo'] === 'Rotación por estaciones'
    && count($d4['cajas']) === 4 && $d4['cajas'][0] === ['titulo' => 'Estación A', 'detalle' => 'Aseo (1.5, 1.6)']
    && $d4['cajas'][1]['titulo'] === 'Estación B' && $d4['cajas'][3]['titulo'] === 'Estación D',
    'el bloque «diagrama» se lee: modo, título, «Título / detalle», marcas de lista y varias cajas con «|»');
local_chatbot_verificar(diagrama::parse("solo una") === null && diagrama::parse(implode("\n", range(1, 13))) === null,
    'menos de 2 o más de 12 cajas no es un diagrama');
$texto = static function(array $g): string {
    return implode('|', array_map(static function($fila) {
        return implode('', array_map(static function($c) {
            return $c['t'] === 'caja' ? 'C' : ($c['t'] === 'flecha' ? $c['c'] : '.');
        }, $fila));
    }, $g));
};
local_chatbot_verificar($texto(diagrama::grid($d4)) === 'C→C|↑.↓|C←C',
    'ciclo de 4: dos filas en zigzag, flecha que baja a la derecha y flecha que vuelve a la primera caja');
$flujo = diagrama::parse("A\nB\nC");
local_chatbot_verificar($texto(diagrama::grid($flujo)) === 'C→C→C', 'flujo de 3: una fila con flechas entre cajas');
$flujo6 = diagrama::parse("A\nB\nC\nD\nE\nF");
local_chatbot_verificar($texto(diagrama::grid($flujo6)) === 'C→C→C|....↓|C←C←C',
    'flujo de 6: tres por fila, baja al final de la fila y la siguiente va de derecha a izquierda');
local_chatbot_verificar($texto(diagrama::grid(diagrama::parse("A\nB\nC\nD\nE"))) === 'C→C→C|....↓|..C←C',
    'flujo de 5: la última fila incompleta queda pegada al lado por donde entra el flujo');
local_chatbot_verificar($texto(diagrama::grid(diagrama::parse("modo: ciclo\nA\nB\nC"))) === 'C→C|..↓|..C',
    'ciclo de 3: sin flecha de cierre cuando la última fila queda incompleta');
$html = diagrama::render_html($d4);
local_chatbot_verificar(strpos($html, 'class="m2-diagrama"') !== false && substr_count($html, 'background-color: #E8F0F7') === 4
    && strpos($html, '<strong>Estación A</strong><br>Aseo (1.5, 1.6)') !== false && strpos($html, '<p><strong>Rotación por estaciones</strong></p>') === 0,
    'la tabla lleva su título, cuatro cajas con color de fondo y el detalle bajo el título');
local_chatbot_verificar(strpos(diagrama::render_html(diagrama::parse("A <b>x</b> / d\nB")), '<b>') === false,
    'el texto de las cajas se escapa');

[$md, $tablas] = diagrama::extract("Antes\n\n```diagrama\nA\nB\n```\n\nDespués");
local_chatbot_verificar(count($tablas) === 1 && strpos($md, '@@DIAGRAMA_0@@') !== false && strpos($md, '```') === false,
    'un bloque «diagrama» queda como marcador mientras se pasa el Markdown a HTML');
local_chatbot_verificar(diagrama::insert("<p>Antes</p>\n<p>@@DIAGRAMA_0@@</p>\n<p>Después</p>", $tablas)
    === "<p>Antes</p>\n" . $tablas[0] . "\n<p>Después</p>", 'la tabla reemplaza al marcador sin dejar un párrafo vacío');
$arte = "```\n  [ESTACIÓN A]      [ESTACIÓN B]\n       │                 │\n       ▼                 ▼\n"
    . "┌───────────┐    ┌───────────┐\n│Subgrupo 1 │ ──►│Subgrupo 2 │\n└───────────┘    └───────────┘\n```";
[$md2, $t2] = diagrama::extract("Texto\n\n" . $arte . "\n\nFin");
local_chatbot_verificar(count($t2) === 1 && strpos($md2, '│') === false && strpos($md2, '@@DIAGRAMA_0@@') !== false
    && strpos($t2[0], '<li>[ESTACIÓN A]</li><li>[ESTACIÓN B]</li>') !== false && strpos($t2[0], '<li>Subgrupo 1</li><li>Subgrupo 2</li>') !== false,
    'un dibujo de caracteres se vuelve lista con lo que decía cada caja');
// Tabla dibujada en ASCII (revision del cliente del 02-11: la Etapa 1 la mostraba como viñetas «| Plan Formativo...»).
$ascii = "+------------------------------------------+\n|       SÍNTESIS DIAGNÓSTICA GENERAL       |\n"
    . "+--------------------------+---------------+\n| Plan Formativo / Código  | PF0960        |\n"
    . "| Grupo Participante       | 20 personas   |\n| Aprendizaje Esperado (AE)| 1. Asistir a  |\n"
    . "|                          | las personas  |\n+--------------------------+---------------+";
[$md4, $t4] = diagrama::extract("```\n" . $ascii . "\n```");
local_chatbot_verificar(count($t4) === 1 && strpos($t4[0], '<th colspan="2"><p><strong>SÍNTESIS DIAGNÓSTICA GENERAL</strong></p></th>') !== false
    && strpos($t4[0], '<td><p>Plan Formativo / Código</p></td><td><p>PF0960</p></td>') !== false
    && strpos($t4[0], '<p>1. Asistir a las personas</p>') !== false && strpos($t4[0], '|') === false,
    'una tabla dibujada en ASCII (+---+, |) se vuelve tabla, con las celdas de varias lineas unidas');
[$md5] = diagrama::extract("```\n| A | B |\n|---|---|\n| 1 | 2 |\n```");
local_chatbot_verificar(strpos($md5, '```') === false && strpos($md5, "| A | B |\n|---|---|\n| 1 | 2 |") !== false,
    'una tabla Markdown dentro de un bloque de código queda como tabla');
$mal = '<p>x</p><ul><li>|</li><li>SÍNTESIS DIAGNÓSTICA GENERAL</li><li>| Plan Formativo / Código</li>'
    . '<li>| Servicios de cuidados básicos / PF0960 |</li><li>| Nivel de Cualificación</li><li>| Nivel 2 MCTP</li>'
    . '<li>| Aprendizaje Esperado (AE)| 1. Asistir a las personas mayores en la mantención de las condiciones</li>'
    . '<li>| de higiene personal y confort, de acuerdo con plan de acción, normas</li><li>| de higiene y seguridad vigentes.</li></ul>'
    . '<ul><li>Viñeta normal</li><li>Otra</li></ul>';
$rep = diseno::repair_art_lists($mal);
local_chatbot_verificar(strpos($rep, '<th colspan="2">SÍNTESIS DIAGNÓSTICA GENERAL</th>') !== false
    && strpos($rep, '<tr><td><strong>Plan Formativo / Código</strong></td><td>Servicios de cuidados básicos / PF0960</td></tr>') !== false
    && strpos($rep, '<tr><td><strong>Nivel de Cualificación</strong></td><td>Nivel 2 MCTP</td></tr>') !== false
    && strpos($rep, '<td><strong>Aprendizaje Esperado (AE)</strong></td><td>1. Asistir a las personas mayores en la mantención de las '
        . 'condiciones de higiene personal y confort, de acuerdo con plan de acción, normas de higiene y seguridad vigentes.</td>') !== false
    && strpos($rep, '<ul><li>Viñeta normal</li><li>Otra</li></ul>') !== false && strpos($rep, '<li>|') === false,
    'la tabla ASCII que quedó guardada como viñetas «| ...» vuelve a ser tabla; las demás listas no cambian');
// Caso real de la Etapa 1 de Roberto (02-11): encabezado de columnas y continuaciones que empiezan con «(».
$verif = '<ul><li>|</li><li>VERIFICACIÓN DE CALIDAD DE LA ETAPA 1</li><li>| Criterio de Verificación</li><li>| Estado / Resultado</li>'
    . '<li>| Inclusión de criterios de evaluación de la ficha</li><li>| Verificado: Se incluyen los 9 criterios</li>'
    . '<li>| (1.1 al 1.9) sin invención de enunciados.</li><li>| Viabilidad del tiempo asignado (Subsección 2.3)</li>'
    . '<li>| Verificado: Experiencia diseñada para 540</li><li>| min (reserva 300 min para nivelación).</li></ul>';
$rv = diseno::repair_art_lists($verif);
local_chatbot_verificar(strpos($rv, '<tr><th>Criterio de Verificación</th><th>Estado / Resultado</th></tr></thead>') !== false
    && strpos($rv, '<td>Verificado: Se incluyen los 9 criterios (1.1 al 1.9) sin invención de enunciados.</td>') !== false
    && strpos($rv, '<td><strong>Viabilidad del tiempo asignado (Subsección 2.3)</strong></td><td>Verificado: Experiencia diseñada para 540 '
        . 'min (reserva 300 min para nivelación).</td>') !== false,
    'tabla ASCII guardada como viñetas: encabezado de columnas y continuaciones con «(» o minúscula en su fila');
$perdidos = documento_final::pre_html("[SECCIÓN 1: RUTINAS] (Marque con ✔)\n  [?] Aseo Corporal Total ........ [ ] Realizado  [ ] No requerido\n"
    . "  [?] Aseo Bucal y Dental .......... [ ] Realizado  [ ] No requerido\nEstado Anímico:  [?] Tranquilo/a   [?] Serio/a");
local_chatbot_verificar(strpos($perdidos, '<td>Aseo Corporal Total</td><td>☐</td><td>☐</td>') !== false
    && strpos($perdidos, '<strong>Estado Anímico:</strong> ☐ Tranquilo/a') !== false && strpos($perdidos, '?') === false,
    'iconos perdidos al guardar («[?]»): en un item de cotejo se quitan y como opcion pasan a casilla ☐ (caso real de Roberto)');
// Parrafo en ingles en la Etapa 5 de Roberto (02-11).
$ingles = '<h3>5. Vacíos de información</h3><p>During the formulation of the Assessment Plan, the following technical gaps and '
    . 'operational constraints were identified, and viable solutions were defined while strictly adhering to the Plan Formativo.</p>'
    . '<p>El plan de evaluación se elaboró con la ficha y el plan formativo, según las orientaciones del manual.</p>';
local_chatbot_verificar(diseno::english_paragraphs($ingles) === ['During the formulation of the Assessment Plan, the following']
    && in_array('Texto en español (hay un párrafo en inglés: «During the formulation of the Assessment Plan, the following…»)',
        diseno::missing_items(5, $ingles, false), true)
    && diseno::english_paragraphs('<p>Se aplica el check-list de la Unidad de Competencia Laboral con feedback inmediato del grupo.</p>') === [],
    'revision: avisa un parrafo en ingles, no un parrafo en español con alguna palabra en ingles');
local_chatbot_verificar(strpos(diseno::build_prompt(5, 'F', [], [], []), 'Escribe todo el producto en español de Chile') !== false
    && strpos(diseno::build_consolidation_prompt([1 => '<p>x</p>']), 'Escribe todo el producto en español de Chile') !== false,
    'los prompts piden todo el producto en español');
local_chatbot_verificar(strpos(documento_final::pre_html("Ítem A ........ [ ] Sí  [ ] No\n[??] Arreglo de cama ........ [ ] Sí  [ ] No"), '?') === false,
    'un icono perdido con varios signos «[??]» tampoco queda a la vista');
local_chatbot_verificar(strpos(diseno::build_prompt(6, 'F', [], [], []), 'No uses emojis ni íconos') !== false,
    'el prompt pide no usar emojis, que la plataforma no guarda');
local_chatbot_verificar(diseno::normalize_product('<p>Según el manual [1], [2, 3] y la guía [4-6].</p><ul><li>[ ] A) Sí</li></ul>')
    === '<p>Según el manual, y la guía.</p><ul><li>☐ A) Sí</li></ul>',
    'en lo guardado se quitan las marcas de cita y las casillas «[ ]» se muestran ☐');
[$md3, $t3] = diagrama::extract("```diagrama\nsolo una caja\n```");
local_chatbot_verificar($t3 === [] && trim($md3) === '- solo una caja', 'un bloque «diagrama» ilegible se entrega como lista');
$codigo = "```php\n\$a = 1;\n```";
local_chatbot_verificar(diagrama::extract($codigo) === [$codigo, []], 'un bloque de código normal no se toca');
local_chatbot_verificar(diagrama::has_art("│ │ │") && !diagrama::has_art('Solo texto con - guiones y → una flecha'),
    'se reconoce un dibujo de caracteres y no un texto con una flecha');

// El Word: cajas con fondo, borde y texto centrado; flechas grandes; sin bordes de tabla; dibujos de caracteres como lista.
$rutad = sys_get_temp_dir() . '/m2_diagrama_' . getmypid() . '.docx';
local_chatbot_verificar(diseno::html_to_docx($rutad, 'Etapa 3', '', '<p>Antes</p>' . $html
    . '<pre>' . htmlspecialchars("┌──────┐\n│ Caja │\n└──────┘\n ▼\n┌──────┐\n│ Otra │\n└──────┘") . '</pre>'), 'el Word con diagrama se genera');
$zipd = new ZipArchive();
$zipd->open($rutad);
$docd = (string) $zipd->getFromName('word/document.xml');
$zipd->close();
@unlink($rutad);
local_chatbot_verificar(simplexml_load_string($docd) !== false, 'el Word con diagrama es XML válido');
local_chatbot_verificar(substr_count($docd, 'w:fill="E8F0F7"') === 4 && substr_count($docd, '<w:tcBorders>') === 4
    && substr_count($docd, 'w:color="20639B"') >= 4 && strpos($docd, '<w:jc w:val="center"/>') !== false,
    'en el Word las cajas llevan fondo, borde y centrado, y las flechas el azul institucional');
local_chatbot_verificar(strpos($docd, '<w:sz w:val="36"/>') !== false && strpos($docd, '<w:tblBorders><w:top w:val="nil"') !== false,
    'las flechas van grandes y la tabla del diagrama no dibuja bordes propios');
local_chatbot_verificar(preg_match('/[│─┌┐└┘▼]/u', $docd) === 0 && strpos($docd, '• Caja') !== false && strpos($docd, '• Otra') !== false,
    'un <pre> con dibujo de caracteres sale en el Word como lista, sin trazos');

// --- Citas numericas: no van dentro del texto, las referencias van al final (pedido del 02-11-2026) ---
local_chatbot_verificar(diseno::strip_citations('Usa pictogramas [1]. Organiza estaciones [1, 2], y tutoría [3][4] entre pares [5-7].')
    === 'Usa pictogramas. Organiza estaciones, y tutoría entre pares.', 'se quitan las marcas [n], [n, m], [n][m] y [n-m] con su espacio');
local_chatbot_verificar(diseno::strip_citations('Ver [Manual EDECOMP](http://x.cl) y [1](http://y.cl); ítem [CRÍTICO] y matriz [A1]')
    === 'Ver [Manual EDECOMP](http://x.cl) y [1](http://y.cl); ítem [CRÍTICO] y matriz [A1]',
    'no se tocan los enlaces Markdown ni los corchetes con letras');
local_chatbot_verificar(diseno::clean_markdown("```markdown\n## Etapa 4\nTexto [1].\n```") === "## Etapa 4\nTexto.",
    'clean_markdown (etapas 1 a 7) también quita las marcas');
// Formulas en LaTeX (pedido del cliente del 02-11: la Etapa 6 mostraba $$\text{...} \times 0.20$$ sin dibujar).
$latex = '$$\text{Porcentaje Final de Logro} = (\% \text{Inst. 1} \times 0.20) + (\% \text{Inst. 3} \times 0.60) + (\% \text{Inst. 5} \times 0.20)$$';
local_chatbot_verificar(diseno::plain_math($latex)
    === 'Porcentaje Final de Logro = (% Inst. 1 × 0.20) + (% Inst. 3 × 0.60) + (% Inst. 5 × 0.20)',
    'una formula en LaTeX pasa a texto legible con × y %');
local_chatbot_verificar(diseno::plain_math('<p>Logro: \( \frac{\text{Obtenido}}{\text{Ideal}} \times 100 \)</p>')
    === '<p>Logro: (Obtenido ÷ Ideal) × 100</p>', 'fraccion y delimitadores \( \) dentro de HTML guardado');
local_chatbot_verificar(diseno::plain_math('Valor $ 5.000 y $ 3.000 por persona') === 'Valor $ 5.000 y $ 3.000 por persona'
    && diseno::plain_math('Sin fórmulas') === 'Sin fórmulas', 'los precios con $ y el texto sin comandos no cambian');
local_chatbot_verificar(diseno::plain_math('$x \leq 60\%$ y \text{Total} = A \times B') === 'x ≤ 60% y Total = A × B',
    'una formula entre $...$ y los comandos sueltos tambien se convierten');
local_chatbot_verificar(diseno::clean_markdown("## Etapa 6\n" . $latex) === "## Etapa 6\nPorcentaje Final de Logro = (% Inst. 1 × 0.20) + (% Inst. 3 × 0.60) + (% Inst. 5 × 0.20)",
    'clean_markdown convierte las formulas de los productos nuevos');
local_chatbot_verificar(strpos(diseno::build_prompt(6, 'F', [], [], []), 'Nunca uses LaTeX') !== false
    && strpos(diseno::build_consolidation_prompt(array_fill(1, 6, 'x')), 'Nunca uses LaTeX') !== false,
    'los prompts de las etapas y de la consolidacion piden formulas en texto, sin LaTeX');
// Formularios en bloque preformateado (revision del cliente del 02-11: la Etapa 6 entregaba la hoja de registro con
// lineas de puntos y casillas alineadas, y el Word la mostraba con otro formato).
$hoja = "------------------------------------------------\n"
    . "[SECCIÓN 1: RUTINAS DE HIGIENE PERSONAL EJECUTADAS] (Marque con ✓ el ícono realizado)\n"
    . "  [🚿] Aseo Corporal Total / Baño en Cama ........... [ ] Realizado  [ ] No requerido\n"
    . "  [🪥] Aseo Bucal y Dental ............................ [ ] Realizado  [ ] No requerido\n"
    . "------------------------------------------------\n"
    . "Estado Anímico:  [😊] Tranquilo/a   [😐] Serio/a\n"
    . "Novedades / Observaciones clave (redacción breve o símbolo):\n" . str_repeat('_', 60);
$ph = documento_final::pre_html($hoja);
local_chatbot_verificar(strpos($ph, '<p><strong>SECCIÓN 1: RUTINAS DE HIGIENE PERSONAL EJECUTADAS (Marque con ✓ el ícono realizado)</strong></p>') !== false
    && strpos($ph, '<table><thead><tr><th>Ítem</th><th>Realizado</th><th>No requerido</th></tr></thead>') !== false
    && strpos($ph, '<tr><td>🚿 Aseo Corporal Total / Baño en Cama</td><td>☐</td><td>☐</td></tr>') !== false
    && strpos($ph, '<td>🪥 Aseo Bucal y Dental</td>') !== false,
    'formulario preformateado: el titulo entre corchetes en negrita y los items de cotejo en una tabla con una columna por opcion');
local_chatbot_verificar(strpos($ph, '<strong>Estado Anímico:</strong> 😊 Tranquilo/a') !== false && strpos($ph, '-----') === false
    && strpos($ph, '.....') === false && strpos($ph, '<strong>Novedades / Observaciones clave (redacción breve o símbolo):</strong>') !== false,
    'formulario preformateado: campos con el rotulo en negrita, sin separadores ni lineas de puntos');
$conpre = '<h2>Instrumento 3</h2><pre><code>' . htmlspecialchars($hoja) . '</code></pre><p>Fin</p>';
$normal = diseno::normalize_product($conpre);
local_chatbot_verificar(stripos($normal, '<pre') === false && strpos($normal, '<h2>Instrumento 3</h2>') === 0 && substr($normal, -10) === '<p>Fin</p>'
    && strpos($normal, '<th>Realizado</th>') !== false, 'un producto ya guardado con el bloque se muestra y se descarga como HTML comun');
local_chatbot_verificar(diseno::normalize_product('<p>Sin bloques</p>') === '<p>Sin bloques</p>', 'un producto sin bloques no cambia');
local_chatbot_verificar(strpos(documento_final::pre_html("Rol: [ ] COEVALUACIÓN ENTRE PARES     [ ] HETEROEVALUACIÓN\n"
    . "- Estado de Aprobación (Mínimo 60%): [  ] APROBADO   [  ] EN PROCESO"), '<table') === false,
    'un campo o una viñeta con casillas, sin linea de puntos, no se vuelve tabla de cotejo');
local_chatbot_verificar(strpos(diseno::build_prompt(6, 'F', [], [], []), 'Nunca en bloques de código, con líneas de puntos') !== false,
    'el prompt pide los instrumentos en tablas Markdown, sin bloques de codigo ni lineas de puntos');
$pe = diseno::build_prompt(4, 'F', [], [], []);
local_chatbot_verificar(strpos($pe, '«### Referencias consultadas»') !== false && strpos($pe, 'No uses citas numéricas') !== false,
    'el prompt de cada etapa pide la lista «Referencias consultadas» al final y no las marcas en el texto');

// --- Que la etapa entregue todo lo que corresponde (revision del cliente del 02-11) ---
local_chatbot_verificar(strpos($pe, 'ESTRUCTURA OBLIGATORIA') !== false
    && strpos($pe, '«### Registro de vacíos de información y propuestas de solución» (si no hay vacíos, dilo en una línea), '
        . '«### Verificación de calidad de la etapa» y «### Referencias consultadas»') !== false,
    'el prompt exige cada elemento de la etapa con su nombre y el cierre comun: vacios, verificacion y referencias');
local_chatbot_verificar(strpos(diseno::build_consolidation_prompt([1 => '<p>x</p>']), 'Cada parte conserva todas las secciones de su etapa') !== false,
    'la consolidacion conserva las secciones de cada etapa, con sus vacios y su verificacion');
local_chatbot_verificar(diseno::prompt_number('M2-F2-etapas-v11') === 11 && diseno::prompt_number('M2-F2-etapas-v9') === 9
    && diseno::prompt_number(null) === 0 && diseno::prompt_number('') === 0, 'numero de la version del prompt');
local_chatbot_verificar(diseno::item_stems('Resultado de la validación (Etapa 0) y observaciones no resueltas, incorporadas como restricciones')
    === ['result', 'valida', 'observ', 'resuel'] && diseno::item_stems('Tabla de síntesis') === ['sintes'],
    'raices de un elemento: lo anterior al parentesis o la coma, sin tildes ni palabras cortas');
$e1 = '<h2>Etapa 1: Análisis diagnóstico</h2><h3>1. Síntesis diagnóstica</h3><p>x</p><h3>2. Resultado de la validación y observaciones '
    . 'no resueltas</h3><h3>3. Síntesis de las observaciones obligatorias de la ficha</h3><h3>4. Prioridades de diseño</h3>'
    . '<h3>5. Restricciones</h3><h3>7. Recomendación didáctica</h3><h3>8. Nota de uso de documentos complementarios</h3>'
    . '<p>Las oportunidades se verán después.</p><h3>Verificación de calidad de la etapa</h3><p>ok</p>';
local_chatbot_verificar(diseno::missing_items(1, $e1, false) === ['Oportunidades'],
    'revision: falta «Oportunidades» (mencionarla en un parrafo no cuenta como seccion)');
local_chatbot_verificar(diseno::missing_items(1, $e1, true) === ['Oportunidades', 'Registro de vacíos de información y propuestas de solución',
    'Referencias consultadas'], 'revision con el cierre nuevo: tambien el registro de vacios y las referencias');
$e5 = '<h2>Etapa 5: Plan de evaluación</h2><table><thead><tr><th>Aprendizaje esperado</th><th>Criterio de evaluación</th>'
    . '<th>Evidencia</th><th>Instrumento</th><th>Indicador de logro</th><th>Momento de evaluación</th></tr></thead></table>'
    . '<h3>Verificación de calidad de la etapa</h3>';
local_chatbot_verificar(diseno::missing_items(5, $e5, false) === ['Responsable'], 'revision de la Etapa 5: falta la columna «Responsable»');
local_chatbot_verificar(diseno::missing_items(6, '<h2>Etapa 6</h2><h3>Instrumento 1: Lista de cotejo</h3>', false)
    === ['Verificación de calidad de la etapa'] && diseno::missing_items(2, '', true) === [],
    'revision de la Etapa 6: los tipos de instrumento no se exigen; sin producto, nada que revisar');
$cons = '<h1>DATOS GENERALES</h1><p>d</p><h1>PARTE 1: ANÁLISIS</h1><h2>1. Síntesis</h2><p>a</p>'
    . '<h1>PARTE 2: DISEÑO</h1><h2>1. Propósito</h2><h2>Verificación de calidad de la etapa</h2><p>v2</p>'
    . '<h1>PARTE 3: SECUENCIA</h1><p>c</p><h1>PARTE 4: MATERIALES</h1><p>m</p><h1>PARTE 5: EVALUACIÓN</h1><p>e</p>'
    . '<h1>Referencias consultadas</h1><ul><li>Plan Formativo</li></ul>';
local_chatbot_verificar(diseno::missing_items(7, $cons, false) === ['Etapa 6: Instrumentos evaluativos']
    && in_array('Verificación de calidad de la etapa (Etapa 1)', diseno::missing_items(7, $cons, true), true)
    && !in_array('Verificación de calidad de la etapa (Etapa 2)', diseno::missing_items(7, $cons, true), true),
    'revision de la consolidacion: la etapa que falta y la verificacion de cada parte');
$prod1 = '<h2>Etapa 1: Análisis diagnóstico</h2><h3>1. Síntesis</h3><p>s</p><h3>Registro de vacíos de información y propuestas de '
    . 'solución</h3><p>VACIOS UNO</p><h3>Verificación de calidad de la etapa</h3><ul><li>VERIF UNO</li></ul><h3>Referencias consultadas</h3><p>REF</p>';
$prod3 = '<h2>Etapa 3</h2><h3>Verificación de calidad de la etapa</h3><p>VERIF TRES</p>';
$completa = diseno::complete_consolidation($cons, [1 => $prod1, 3 => $prod3]);
$p1 = strpos($completa, 'PARTE 1');
$p2 = strpos($completa, 'PARTE 2');
local_chatbot_verificar(strpos($completa, '<h2>Registro de vacíos de información y propuestas de solución</h2><p>VACIOS UNO</p>') > $p1
    && strpos($completa, '<h2>Verificación de calidad de la etapa</h2><ul><li>VERIF UNO</li></ul>') < $p2
    && strpos($completa, 'REF</p>') === false && substr_count($completa, 'v2</p>') === 1
    && strpos($completa, 'VERIF TRES') > strpos($completa, 'PARTE 3') && strpos($completa, 'VERIF TRES') < strpos($completa, 'PARTE 4'),
    'la consolidacion se completa con los vacios y la verificacion de cada etapa, al final de su parte y con el nivel de sus secciones');
local_chatbot_verificar(diseno::complete_consolidation('<p>sin partes</p>', [1 => $prod1]) === '<p>sin partes</p>'
    && diseno::complete_consolidation($completa, [1 => $prod1, 3 => $prod3]) === $completa,
    'completar es idempotente y no toca un documento sin partes');
// Consolidacion por codigo de Roberto (02-11): la Etapa 3 trae su verificacion al mismo nivel que su titulo; no se duplica.
$porcodigo = '<h2>Etapa 3: Secuencia</h2><h3>1. Fases</h3><p>f</p><h2>Verificación de calidad de la etapa</h2><p>VERIF TRES</p>'
    . '<h2>Referencias consultadas</h2><p>R3</p><h2>Etapa 4: Materiales</h2><h3>1. Materiales</h3><p>m</p>'
    . '<h3>Referencias consultadas</h3><p>R4</p>';
$prod4 = '<h2>Etapa 4</h2><h3>Verificación de calidad de la etapa</h3><p>VERIF CUATRO</p>';
$pc = diseno::complete_consolidation($porcodigo, [3 => $prod3, 4 => $prod4]);
local_chatbot_verificar(substr_count($pc, 'VERIF TRES') === 1 && strpos($pc, '<h3>Verificación de calidad de la etapa</h3><p>VERIF CUATRO</p><h3>Referencias consultadas</h3>') !== false,
    'completar: una verificacion al nivel del titulo de la etapa no se duplica, y la que falta va antes de las referencias de la etapa');
[$mostrada] = diseno::consolidation_shown((object) ['contenido' => $cons, 'huella' => 'h', 'promptversion' => 'v'], [1 => $prod1], 'h');
local_chatbot_verificar(strpos($mostrada, 'VERIF UNO') !== false, 'la consolidacion del asistente se muestra ya completada');
local_chatbot_verificar(strpos($pe, '```diagrama') !== false && strpos($pe, 'nunca dibujes cajas') !== false
    && strpos(diseno::build_consolidation_prompt([1 => '<p>x</p>']), 'etiqueta «diagrama»') !== false,
    'los prompts de las etapas y de la consolidación piden los diagramas como bloque «diagrama»');
local_chatbot_verificar(strpos(diseno::build_consolidation_prompt([1 => '<p>x</p>']), 'sola sección «Referencias consultadas»') !== false,
    'la consolidación reúne las referencias en una sola sección');

// --- Tablero del Modulo 2 debajo del boton de la Fase 3 (pedido del 01-11-2026) ---
$tb = modulo2_setup::add_dashboard_block($f3b, 'ut');
local_chatbot_verificar(strpos($tb, 'href="ut"') > strpos($tb, 'href="u7"') && strpos($tb, modulo2_setup::BOTON_TABLERO) !== false
    && strpos($tb, modulo2_setup::TABLERO_TITULO) !== false && substr($tb, -6) === '</div>',
    'el tablero va con su explicación debajo del botón de la Fase 3, dentro del recuadro de la pestaña');
local_chatbot_verificar(modulo2_setup::add_dashboard_block($tb, 'ut') === $tb, 'agregar el tablero es idempotente');
$tb2 = modulo2_setup::add_dashboard_block($tb, 'otra');
local_chatbot_verificar(substr_count($tb2, '<!-- TABLERO M2 -->') === 1 && strpos($tb2, 'href="otra"') !== false
    && strpos($tb2, 'href="ut"') === false, 'con otra URL el bloque se reemplaza, no se duplica');
local_chatbot_verificar(modulo2_setup::remove_dashboard_block($tb) === $f3b, 'con el interruptor apagado se quita sin dejar rastro');
local_chatbot_verificar(strpos(modulo2_setup::add_validation_buttons($tb, 'ur', 'u7'), 'href="u7"') === strpos($tb, 'href="u7"'),
    'volver a pasar los botones de la Fase 3 no los mueve debajo del tablero');
$chk = modulo2_setup::link_checklist('<!-- IMPORTANTE: REEMPLAZA # POR LA URL REAL DEL ARCHIVO EN MOODLE -->'
    . '<a href="#"> Consultar y descargar el checklist </a>', 'uc');
local_chatbot_verificar(strpos($chk, 'REEMPLAZA') === false && strpos($chk, 'href="uc"') !== false,
    'también se quita el comentario «IMPORTANTE: REEMPLAZA #…» del cliente');

// --- Barra «Tu ruta del Modulo 2» ---
$r = ruta_m2::model(['ficha' => ruta_m2::FICHA_SIN, 'aceptadas' => [], 'fase' => 1]);
local_chatbot_verificar(!$r['pasos'][1]['done'] && $r['pasos'][1]['here'] && $r['pasos'][2]['locked'] && $r['pasos'][3]['locked'],
    'sin ficha validada: Fase 1 en curso y las otras bloqueadas');
local_chatbot_verificar($r['siguiente'][0] === 'r2_next_ficha' && $r['siguiente'][2] === 1, 'el próximo paso es la ficha');
foreach ([ruta_m2::FICHA_NO_APROBADA => 'r2_next_ficha_corregir', ruta_m2::FICHA_DESACTUALIZADA => 'r2_next_ficha_revalidar',
        ruta_m2::FICHA_PENDIENTE => 'r2_next_ficha_espera'] as $estado => $clave) {
    local_chatbot_verificar(ruta_m2::model(['ficha' => $estado, 'fase' => 1])['siguiente'][0] === $clave,
        "ficha «{$estado}»: el próximo paso lo dice");
}
$r = ruta_m2::model(['ficha' => ruta_m2::FICHA_OK, 'aceptadas' => [1, 2], 'borradores' => [3], 'fase' => 2, 'etapa' => 3]);
local_chatbot_verificar($r['pasos'][1]['done'] && !$r['pasos'][2]['done'] && $r['pasos'][2]['avance'] === '2/6'
    && $r['pasos'][2]['here'] && $r['pasos'][3]['locked'], 'en la Fase 2 se ve el avance de etapas y la Fase 3 sigue bloqueada');
local_chatbot_verificar($r['siguiente'][0] === 'r2_next_aceptar' && $r['siguiente'][3] === 3, 'con borrador, el paso es aceptar la etapa 3');
local_chatbot_verificar(ruta_m2::model(['ficha' => ruta_m2::FICHA_OK, 'aceptadas' => [1, 2], 'fase' => 2])['siguiente'][0]
    === 'r2_next_generar', 'sin borrador, el paso es generar la propuesta');
$r = ruta_m2::model(['ficha' => ruta_m2::FICHA_OK, 'aceptadas' => range(1, 6), 'fase' => 3, 'etapa' => 7]);
local_chatbot_verificar($r['pasos'][2]['done'] && !$r['pasos'][3]['locked'] && $r['pasos'][3]['avance'] === '0/2'
    && $r['siguiente'][0] === 'r2_next_consolidar', 'con las 6 etapas aceptadas, toca consolidar');
$r = ruta_m2::model(['ficha' => ruta_m2::FICHA_OK, 'aceptadas' => range(1, 7), 'evaluado' => true, 'aprobado' => false, 'fase' => 3, 'etapa' => 8]);
local_chatbot_verificar($r['siguiente'][0] === 'r2_next_ajustar' && $r['siguiente'][2] === 2 && !$r['pasos'][3]['done'],
    'no aprobado: el paso es ajustar en la Fase 2');
$r = ruta_m2::model(['ficha' => ruta_m2::FICHA_OK, 'aceptadas' => range(1, 7), 'evaluado' => true, 'aprobado' => true, 'fase' => 3, 'etapa' => 8]);
local_chatbot_verificar($r['pasos'][3]['done'] && $r['siguiente'][0] === 'r2_next_final' && $r['resumen'][0] === 'r2_summary_done',
    'aprobado: las tres fases completas y el paso es el documento final');

// --- Rol del Modulo 2 y fuentes de conocimiento (30-10-2026) ---
$pe2 = diseno::build_prompt(1, 'FICHA', [], [], [], '', '', true, true);
local_chatbot_verificar(strpos($pe2, 'Eres el Asistente de Diseño Instruccional') === 0 && strpos($pe2, 'FUENTES DE CONOCIMIENTO:') !== false
    && strpos($pe2, 'busca información actualizada en la web') !== false, 'las etapas hablan como el Asistente de Diseño Instruccional, con la regla de fuentes');
$pev = diseno::build_eval_prompt('D', 'F', [], true, false);
local_chatbot_verificar(strpos($pev, 'Eres el Asistente de Diseño Instruccional') === 0 && strpos($pev, 'LIBRERÍA DE CONOCIMIENTOS') !== false
    && strpos($pev, 'busca información') === false, 'la evaluación también, y sin búsqueda no la promete');
$md = diseno::sources_markdown([['title' => 'sence.cl', 'uri' => 'https://sence.cl/x'], ['title' => 'malo', 'uri' => 'javascript:alert(1)'],
    ['title' => 'a [b]', 'uri' => 'http://ej.cl']]);
local_chatbot_verificar(strpos($md, '- [sence.cl](https://sence.cl/x)') !== false && strpos($md, 'javascript') === false
    && strpos($md, '- [a b](http://ej.cl)') !== false && diseno::sources_markdown([]) === '',
    'las fuentes web van al final de la etapa, solo con enlaces http(s)');
// --- Etapa 7 con el asistente: en extenso, sin repetir campos comunes (Prompt Maestro del 30-10-2026) ---
$pc = diseno::build_consolidation_prompt([1 => '<p>Análisis X</p>', 2 => '<p>Diseño Y</p>']);
local_chatbot_verificar(strpos($pc, 'ETAPA 7: CONSOLIDACIÓN') !== false && strpos($pc, '"en extenso"') !== false
    && strpos($pc, 'sin repetir aquellos campos que puedan ser comunes') !== false
    && strpos($pc, 'Análisis, Diseño, Secuencia, Materiales, Evaluación, Instrumentos') !== false
    && strpos($pc, 'No resumas ni acortes') !== false && strpos($pc, guardrails::isolate_user_content('<p>Diseño Y</p>')) !== false,
    'la consolidación pide el documento en extenso, sin repetir campos comunes, con las etapas aisladas');
$et7 = [1 => str_repeat('palabra ', 500), 2 => str_repeat('otra ', 500)];
local_chatbot_verificar(diseno::consolidation_complete(str_repeat('palabra ', 400) . str_repeat('otra ', 300), $et7, 'STOP')
    && !diseno::consolidation_complete(str_repeat('corto ', 100), $et7, 'STOP')
    && !diseno::consolidation_complete(str_repeat('palabra ', 900), $et7, 'MAX_TOKENS'),
    'una consolidación cortada o mucho más corta que las etapas no se usa');
$c7 = (object) ['contenido' => '<p>Del asistente</p>', 'huella' => 'h1', 'promptversion' => 'M2-F2-etapas-v4'];
local_chatbot_verificar(diseno::consolidation_shown($c7, [1 => '<p>E1</p>'], 'h1') === ['<p>Del asistente</p>', true]
    && diseno::consolidation_shown($c7, [1 => '<p>E1</p>'], 'h2')[1] === false
    && strpos(diseno::consolidation_shown($c7, [1 => '<p>E1</p>'], 'h2')[0], 'E1') !== false
    && diseno::consolidation_shown(null, [1 => '<p>E1</p>'], 'h1')[1] === false,
    'se muestra la consolidación del asistente solo si está al día; si no, la completa por código');
local_chatbot_verificar(diseno::max_seconds(diseno::CONSOLIDACION) === diseno::CONSOLIDACION_MAX && diseno::max_seconds(3) === exante::PROCESO_MAX,
    'la consolidación tiene más tiempo que una etapa');
// --- Ajuste del Prompt Maestro del 10-11-2026: control de cambios entre etapas ---
$cc = diseno::build_prompt(3, 'F', [], [1 => 'E1', 2 => 'E2'], [], 'ACTUAL', 'cambia las estaciones', false, false, true,
    [4 => 'PRODUCTO DE LA ETAPA 4', 5 => 'PRODUCTO DE LA ETAPA 5'], '10-11-2026');
$sincc = diseno::build_prompt(3, 'F', [], [1 => 'E1', 2 => 'E2'], [], 'ACTUAL', 'cambia las estaciones', false, false, false,
    [4 => 'PRODUCTO DE LA ETAPA 4'], '10-11-2026');
local_chatbot_verificar(strpos($cc, 'CONTROL DE CAMBIOS ENTRE ETAPAS:') !== false && strpos($sincc, 'CONTROL DE CAMBIOS') === false,
    'el bloque 1 (control de cambios entre etapas) va solo con el interruptor encendido');
$textuales1 = ['Las etapas del diseño son interdependientes: un ajuste en una etapa puede afectar a las anteriores y a las siguientes.',
    '- Distribución de los tiempos y su suma respecto del tiempo asignado a la experiencia.',
    '4. Aplica el ajuste en la etapa solicitada y actualiza todas las etapas afectadas, entregando la versión corregida de cada una.',
    '6. No avances a la etapa siguiente mientras alguna etapa afectada no esté actualizada y aprobada por la persona facilitadora.',
    'regístralo en el registro de ajustes como inconsistencia aceptada, explica su efecto en la implementación y mantenla visible hasta la Etapa 8.',
    'no declares que un ajuste fue «integrado» o «incorporado plenamente» si no verificaste'];
$todos1 = true;
foreach ($textuales1 as $t) {
    $todos1 = $todos1 && strpos($cc, $t) !== false;
}
local_chatbot_verificar($todos1, 'el bloque 1 va textual: elementos de dependencia, los seis pasos, la inconsistencia aceptada y la regla de la verificación');
local_chatbot_verificar(strpos($cc, '«### Registro de ajustes»') !== false && strpos($cc, 'Pendiente de actualizar') !== false
    && strpos($cc, 'Fecha de hoy: 10-11-2026.') !== false,
    'en la plataforma el registro de ajustes es una sección del producto, con estado de cada etapa afectada y la fecha');
local_chatbot_verificar(strpos($cc, 'PRODUCTO DE LA ETAPA 4') !== false && strpos($cc, 'PRODUCTO DE LA ETAPA 5') !== false
    && strpos($cc, 'POSTERIOR A ESTA') !== false && strpos($sincc, 'PRODUCTO DE LA ETAPA 4') === false,
    'con control de cambios el asistente ve también las etapas siguientes ya aceptadas (sin él, no)');
local_chatbot_verificar(strpos($cc, '- Verificar que los elementos de dependencia de la etapa (fases, actividades o estaciones; organización del grupo; tiempos; criterios; evidencias e instrumentos) coinciden con los de las etapas ya aprobadas.') !== false
    && strpos($cc, '- Revisar el registro de ajustes y confirmar que ningún ajuste quedó sin propagar a las etapas afectadas.') !== false
    && strpos($cc, 'qué ajuste se necesita.') < strpos($cc, '- Verificar que los elementos de dependencia') && strpos($sincc, 'sin propagar') === false,
    'el bloque 2 va textual en el control de calidad, después de detectar inconsistencias');
local_chatbot_verificar(strpos($cc, 'REGLAS DE GOBERNANZA') < strpos($cc, 'CONTROL DE CAMBIOS ENTRE ETAPAS:')
    && strpos($cc, 'CONTROL DE CAMBIOS ENTRE ETAPAS:') < strpos($cc, 'CONTROL DE CALIDAD:'),
    'el control de cambios va a continuación de las reglas de interacción y gobernanza');

$pc7 = diseno::build_consolidation_prompt([1 => '<p>A</p>', 2 => '<p>B</p>'], true);
$pc7sin = diseno::build_consolidation_prompt([1 => '<p>A</p>', 2 => '<p>B</p>'], false);
local_chatbot_verificar(strpos($pc7, 'Antes de consolidar, debes realizar una verificación de consistencia entre las Etapas 2 a 6') !== false
    && strpos($pc7, '- Evidencias e instrumentos: instrumento y momento de evaluación de cada criterio en las Etapas 5 y 6, y su correspondencia con las actividades de la Etapa 3.') !== false
    && strpos($pc7, '- Si hay al menos una inconsistencia, detén la consolidación, propone la corrección de cada etapa afectada y espera la aprobación de la persona facilitadora.') !== false
    && strpos($pc7, 'como anexo previo a la evaluación técnica, la tabla de verificación de consistencia y el registro de ajustes.') !== false,
    'el bloque 3 va textual en la consolidación');
local_chatbot_verificar(strpos($pc7, 'VERIFICACIÓN DE CONSISTENCIA') < strpos($pc7, 'REGLAS:') && strpos($pc7, '# ' . diseno::ANEXO_CONSISTENCIA) !== false
    && strpos($pc7, '«Corrección propuesta»') !== false && strpos($pc7, 'sus filas pasan al anexo') !== false
    && strpos($pc7sin, 'VERIFICACIÓN DE CONSISTENCIA') === false && strpos($pc7sin, 'sus filas pasan al anexo') === false,
    'la verificación va antes de las reglas de consolidación, con el anexo al final; sin el interruptor, nada de eso');
$pe8cc = diseno::build_eval_prompt('D', 'F', [], false, false, [], true);
local_chatbot_verificar(strpos($pe8cc, 'INCONSISTENCIAS ACEPTADAS') !== false && strpos($pe8cc, 'sin modificar la rúbrica') !== false
    && strpos(diseno::build_eval_prompt('D', 'F', []), 'INCONSISTENCIAS ACEPTADAS') === false,
    'la etapa 8 considera las inconsistencias aceptadas en debilidades, recomendaciones y puntaje, sin cambiar la rúbrica');
local_chatbot_verificar(diseno::PROMPT_VERSION === 'M2-F2-etapas-v12' && diseno::prompt_number(diseno::PROMPT_VERSION) >= diseno::PROMPT_CIERRE,
    'la versión del prompt sube a la v12 y mantiene el cierre de cada etapa');

// Lectores de las tablas: lo que la página avisa.
$registro = '<h3>Registro de ajustes</h3><table><thead><tr><th>Etapa de origen</th><th>Cambio realizado</th><th>Etapas afectadas</th>'
    . '<th>Corrección que corresponde</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>'
    . '<tr><td>Etapa 3</td><td>4 estaciones en vez de 3</td><td>Etapa 4</td><td>Agregar la ficha de la estación D</td><td>Pendiente de actualizar</td><td>10-11-2026</td></tr>'
    . '<tr><td>Etapa 3</td><td>4 estaciones</td><td>Etapa 2</td><td>Nada más</td><td>Actualizada</td><td>10-11-2026</td></tr>'
    . '<tr><td>Etapa 3</td><td>4 estaciones</td><td>Etapa 6</td><td>Lista de cotejo de la estación D</td><td>Inconsistencia aceptada</td><td>10-11-2026</td></tr>'
    . '</tbody></table><h3>Registro de vacíos de información y propuestas de solución</h3><table><tr><th>Estado</th></tr><tr><td>Pendiente</td></tr></table>';
local_chatbot_verificar(diseno::pending_adjustments($registro) === ['Etapa 4: Agregar la ficha de la estación D'],
    'avisa solo las correcciones pendientes del registro de ajustes (no las actualizadas, las aceptadas ni otras tablas)');
local_chatbot_verificar(diseno::pending_adjustments('<h3>Verificación de calidad de la etapa</h3><p>Todo bien.</p>') === []
    && diseno::pending_adjustments('') === [], 'sin registro de ajustes no hay aviso');
$consistencia = '<h1>Anexo: Verificación de consistencia entre etapas y registro de ajustes</h1>'
    . '<h2>Verificación de consistencia entre etapas</h2><table><thead><tr><th>Elemento</th><th>Resultado</th><th>Etapas y diferencia</th>'
    . '<th>Corrección propuesta</th></tr></thead><tbody>'
    . '<tr><td>Fases, actividades o estaciones</td><td>Consistente</td><td>—</td><td>—</td></tr>'
    . '<tr><td>Tiempos</td><td>Inconsistente</td><td>Etapa 3 suma 560 min; Etapa 2 dice 540</td><td>Ajustar la Etapa 3</td></tr>'
    . '</tbody></table>';
local_chatbot_verificar(diseno::inconsistencies($consistencia) === ['Tiempos: Etapa 3 suma 560 min; Etapa 2 dice 540'],
    'avisa las filas «Inconsistente» de la verificación (y no confunde «Consistente» con «Inconsistente»)');
$conlargo = str_replace('<td>Tiempos</td>', '<td>Tiempos: tiempo por fase y suma total en cada etapa.</td>', $consistencia);
local_chatbot_verificar(diseno::inconsistencies($conlargo) === ['Tiempos: Etapa 3 suma 560 min; Etapa 2 dice 540'],
    'el aviso usa el nombre corto del elemento, sin la descripción de la fila (como la escribió el asistente en producción)');
local_chatbot_verificar(diseno::inconsistencies(str_replace('<td>Inconsistente</td>', '<td>Consistente</td>', $consistencia)) === []
    && count(diseno::section_rows($consistencia, '/verificacion de consistencia/')) === 2,
    'con todo consistente no hay aviso, y la tabla se reconoce igual');
// El aviso tambien en las etapas afectadas, hasta que se vuelven a aceptar despues del ajuste de origen.
local_chatbot_verificar(diseno::stage_numbers('Etapa 3 (Secuencia instruccional)') === [3]
    && diseno::stage_numbers('Etapas 3, 4 y 5') === [3, 4, 5] && diseno::stage_numbers('Etapas 3 a 6') === [3, 4, 5, 6]
    && diseno::stage_numbers('Etapa 5 (Plan de evaluación) y Etapa 6') === [5, 6] && diseno::stage_numbers('5 subgrupos de 4') === []
    && diseno::stage_numbers('Etapa 8') === [],
    'lee las etapas afectadas (una, lista, rango) y no confunde otros números');
$fila3 = '<h3>Registro de ajustes</h3><table><tr><th>Etapa de origen</th><th>Cambio realizado</th><th>Etapas afectadas</th>'
    . '<th>Corrección que corresponde</th><th>Estado</th><th>Fecha</th></tr>'
    . '<tr><td>Etapa 2</td><td>5 subgrupos de 4</td><td>Etapas 3 y 4</td><td>Usar 5 subgrupos de 4</td><td>Pendiente de actualizar</td><td>10-11-2026</td></tr></table>';
$base = [2 => ['contenido' => $fila3, 'aceptada' => true, 'timeaccepted' => 1000, 'timemodified' => 1000],
    3 => ['contenido' => '<p>x</p>', 'aceptada' => true, 'timeaccepted' => 500, 'timemodified' => 500],
    4 => ['contenido' => '<p>y</p>', 'aceptada' => true, 'timeaccepted' => 600, 'timemodified' => 600]];
$pc = diseno::pending_changes($base);
local_chatbot_verificar(count($pc) === 1 && $pc[0]['origen'] === 2 && $pc[0]['etapas'] === [3, 4] && $pc[0]['correccion'] === 'Usar 5 subgrupos de 4',
    'la corrección de la Etapa 2 queda pendiente para las Etapas 3 y 4, aceptadas antes del ajuste');
$pc2 = diseno::pending_changes([3 => ['contenido' => '<p>x</p>', 'aceptada' => true, 'timeaccepted' => 1500, 'timemodified' => 1500]] + $base);
local_chatbot_verificar(count($pc2) === 1 && $pc2[0]['etapas'] === [4], 'al volver a aceptar la Etapa 3 después del ajuste, solo queda pendiente la 4');
$pc3 = diseno::pending_changes([3 => ['contenido' => '<p>x</p>', 'aceptada' => false, 'timeaccepted' => 0, 'timemodified' => 1600],
    4 => ['contenido' => '<p>y</p>', 'aceptada' => true, 'timeaccepted' => 1700, 'timemodified' => 1700]] + $base);
local_chatbot_verificar(count($pc3) === 1 && $pc3[0]['etapas'] === [3], 'una etapa afectada en borrador sigue pendiente hasta que se acepta');
$pc4 = diseno::pending_changes([3 => ['contenido' => '<p>x</p>', 'aceptada' => true, 'timeaccepted' => 1500, 'timemodified' => 1500],
    4 => ['contenido' => '<p>y</p>', 'aceptada' => true, 'timeaccepted' => 1700, 'timemodified' => 1700]] + $base);
local_chatbot_verificar($pc4 === [], 'con las dos afectadas aceptadas después del ajuste, no queda nada pendiente (ni en la etapa de origen)');
$pc5 = diseno::pending_changes([2 => ['contenido' => $fila3, 'aceptada' => false, 'timeaccepted' => 0, 'timemodified' => 2000]] + $base);
local_chatbot_verificar(count($pc5) === 1 && $pc5[0]['etapas'] === [3, 4], 'con la etapa de origen aún en borrador, cuenta desde su última modificación');
local_chatbot_verificar(diseno::pending_changes([2 => ['contenido' => str_replace('Pendiente de actualizar', 'Inconsistencia aceptada', $fila3),
    'aceptada' => true, 'timeaccepted' => 1000, 'timemodified' => 1000]] + $base) === [], 'una inconsistencia aceptada no se avisa como pendiente');
$t = documento_final::parse_stage_title(diseno::ANEXO_CONSISTENCIA);
local_chatbot_verificar(($t['etiqueta'] ?? '') === 'ANEXO', 'en el Word final el anexo de consistencia es un anexo con su página y entrada en el índice');
$conregistro = '<h2>Etapa 3: Secuencia instruccional</h2>' . $registro;
local_chatbot_verificar(diseno::missing_items(3, $conregistro, true) === diseno::missing_items(3, '<h2>Etapa 3: Secuencia instruccional</h2>' . substr($registro, strpos($registro, '<h3>Registro de vacíos')), true),
    'la sección «Registro de ajustes» no cambia la revisión automática de la etapa');

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Fases 2 y 3 del Módulo 2 conformes: etapas del Prompt Maestro, sin saltos, rúbrica textual y Word válido.\n";
}
exit($fallidos === 0 ? 0 : 1);
