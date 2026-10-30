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
require_once(__DIR__ . '/../classes/modulo2_setup.php');
require_once(__DIR__ . '/../classes/ruta_m2.php');

use local_chatbot\diseno;
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
    && in_array('Alcance de cada fase (descripción de lo que se espera lograr en la fase, sin formularlo como objetivo '
        . 'específico)', $et[3]['incluye'], true) && !in_array('Objetivos específicos', $et[3]['incluye'], true)
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
printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Fases 2 y 3 del Módulo 2 conformes: etapas del Prompt Maestro, sin saltos, rúbrica textual y Word válido.\n";
}
exit($fallidos === 0 ? 0 : 1);
