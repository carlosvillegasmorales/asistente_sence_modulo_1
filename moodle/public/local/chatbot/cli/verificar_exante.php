<?php
/**
 * Verifica la ficha de diagnostico ex-ante del Modulo 2 y su validacion con el checklist.
 *
 * Pedido de Interfases del 21-10-2026: la ficha y el checklist van textuales (documentos del
 * cliente), el control de entrada del Prompt Maestro detiene la validacion si falta lo esencial, el
 * informe del modelo se usa solo si trae todos los items, y la condicion final nunca es mas laxa que
 * la regla del checklist (un incumplimiento critico no se aprueba).
 *
 * Uso:  php cli/verificar_exante.php
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/guardrails.php');
require_once(__DIR__ . '/../classes/exante.php');
require_once(__DIR__ . '/../classes/modulo2_setup.php');

use local_chatbot\exante;
use local_chatbot\modulo2_setup;
use local_chatbot\guardrails;

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

// --- La ficha es la del documento del cliente ---
$secciones = exante::sections();
local_chatbot_verificar(array_keys($secciones) === range(1, 8), 'la ficha tiene 8 secciones en orden (sin la 9, Validación)');
$cerradas = [];
foreach ($secciones as $seccion) {
    foreach ($seccion['campos'] as $campo) {
        if (in_array($campo['tipo'], [exante::T_UNA, exante::T_VARIAS], true)) {
            $cerradas[$campo['num']] = count($campo['opciones']);
        }
    }
}
// La guia de llenado: «9 secciones con 24 preguntas cerradas»; la 9 (tabla de validacion) no tenia ninguna.
local_chatbot_verificar(count($cerradas) === 24, '24 preguntas cerradas, como dice la guía (hay ' . count($cerradas) . ')');
$esperadas = ['3.1' => 4, '3.2' => 5, '3.3' => 3, '3.4' => 3, '4.1' => 3, '4.2' => 3, '4.3' => 7, '4.4' => 3,
    '5.1' => 3, '5.2' => 5, '5.3' => 3, '5.4' => 3, '6.1' => 3, '6.2' => 3, '6.3' => 3, '6.4' => 5, '6.5' => 3,
    '7.1' => 3, '7.2' => 5, '7.3' => 5, '7.4' => 5, '8.1' => 4, '8.2' => 2, '8.3' => 5];
local_chatbot_verificar($cerradas === $esperadas, 'cada pregunta cerrada tiene las alternativas de la ficha');
local_chatbot_verificar(exante::field('alineacion')['opciones']['total'] === 'Totalmente alineado con el perfil '
    . 'ocupacional (los aprendizajes esperados, contenidos y criterios de evaluación son coherentes con el perfil)',
    '3.3 conserva el texto completo de la primera alternativa');
$claves = exante::field_keys();
foreach (['criterio1', 'criterio5', 'tiempo_total', 'tiempo_experiencia', 'obs_evaluacion', 'necesidades_otro',
        'riesgo_otro', 'obs_finales'] as $clave) {
    local_chatbot_verificar(isset($claves[$clave]), "la ficha guarda «{$clave}»");
}
local_chatbot_verificar(!array_filter(array_keys($claves), static function($c) {
    return strpos($c, 'val') === 0;
}), 'la ficha ya no guarda la tabla de validación (sección 9)');

// --- Opcionales: solo las observaciones breves de las secciones 3 a 7 (formulario del cliente del 30-10-2026) ---
$opcionales = exante::optional_fields();
local_chatbot_verificar(array_keys($opcionales) === ['obs3', 'obs4', 'obs5', 'obs6', 'obs7'],
    'son opcionales solo las observaciones breves de las secciones 3 a 7; 2.4 y las observaciones finales son obligatorias');
local_chatbot_verificar($opcionales['obs3'] === 'Sección 3: Observaciones breves' && $opcionales['obs7'] === 'Sección 7: Observaciones breves',
    'cada observación opcional se nombra por su sección');
local_chatbot_verificar(strpos(exante::field('obs_evaluacion')['ayuda'], '(obligatorio)') !== false
    && exante::field('obs_finales')['label'] === 'Observaciones finales (obligatorio):'
    && strpos(exante::field('obs_finales')['ayuda'], 'justificar la selección de modalidad y estrategia') !== false
    && strpos(exante::field('vinculo')['antes'], 'Nota: Si no existe un perfil ocupacional del Catálogo Nacional de ChileValora') === 0
    && exante::field('obs3')['ayuda'] === 'Si no existe un perfil ocupacional de referencia para el plan formativo, '
        . 'indíquelo y justifique las respuestas marcadas en las preguntas anteriores.'
    && exante::field('participantes')['label'] === 'N.º de participantes',
    'textos del formulario del cliente: 2.4 y observaciones finales obligatorias, nota de la sección 3 y su instrucción');

// --- El checklist es el del documento del cliente ---
$checklist = exante::checklist();
local_chatbot_verificar(array_keys($checklist) === range(1, 9), 'el checklist tiene las 9 secciones');
local_chatbot_verificar(array_map(static function($s) {
    return count($s['items']);
}, array_values($checklist)) === [3, 5, 4, 4, 3, 4, 4, 3, 3], 'cada sección del checklist tiene sus ítems');
local_chatbot_verificar(count(exante::checklist_ids()) === 33, 'el checklist tiene 33 ítems (checklist del 30-10-2026)');
local_chatbot_verificar(array_keys($checklist[1]['items']) === ['c1_1', 'c1_3', 'c1_2']
    && $checklist[1]['items']['c1_3'] === 'La competencia del módulo y el nivel de cualificación corresponden a lo '
        . 'declarado en el plan formativo'
    && $checklist[2]['items']['c2_1'] === 'El aprendizaje esperado está claramente definido y corresponde al declarado en '
        . 'el plan formativo'
    && $checklist[2]['items']['c2_2'] === 'Los criterios de evaluación están completos y corresponden a los declarados en '
        . 'el plan formativo'
    && $checklist[3]['items']['c3_1'] === 'El aprendizaje se vincula con el perfil ocupacional (si no existe perfil, se '
        . 'justifica en las observaciones)'
    && $checklist[9]['titulo'] === 'Validación global' && strpos($checklist[9]['items']['c9_2'], 'La información es '
        . 'coherente globalmente: los compromisos formativos de las secciones 1, 2 y 3 son consistentes') === 0,
    'ítems nuevos y modificados, textuales; el nuevo de la sección 1 va segundo con id c1_3 (los informes viejos no cambian)');
local_chatbot_verificar(exante::CRITICOS === ['c1_3', 'c2_1', 'c2_2', 'c2_3', 'c9_2'],
    'los ítems CRÍTICO son los del checklist del cliente (competencia y nivel, aprendizaje, criterios, coherencia global)');
local_chatbot_verificar($checklist[2]['items']['c2_3'] === 'Los criterios no han sido modificados arbitrariamente',
    'los ítems van textuales');
local_chatbot_verificar($checklist[6]['items']['c6_4'] === 'La accesibilidad está considerada', 'ítem 6.4 textual');
foreach (exante::CRITICOS as $id) {
    local_chatbot_verificar(in_array($id, exante::checklist_ids(), true), "el ítem crítico {$id} existe");
}

// --- Limpieza de lo que llega del formulario ---
$limpio = exante::clean([
    'vinculo' => 'inventada', 'complejidad' => 'avanzado', 'desempeno' => ['colaborativo', 'rutinarias', 'hackeo'],
    'necesidades' => ['practico'], 'necesidades_otro' => 'Algo que no se marcó', 'riesgo' => 'otro',
    'riesgo_otro' => 'Rotación del equipo', 'participantes' => 'veinte', 'tiempo_total' => '360',
    'aprendizaje' => str_repeat('a', 5000), 'clave_desconocida' => 'x', 'organismo' => ['no', 'texto'],
]);
local_chatbot_verificar($limpio['vinculo'] === '', 'una alternativa inexistente no se guarda');
local_chatbot_verificar($limpio['complejidad'] === 'avanzado', 'una alternativa válida se guarda');
local_chatbot_verificar($limpio['desempeno'] === ['rutinarias', 'colaborativo'],
    'en «marcar todas» se descartan las inexistentes y se usa el orden de la ficha');
local_chatbot_verificar($limpio['necesidades_otro'] === '', '«Otra» sin marcar no guarda su texto');
local_chatbot_verificar($limpio['riesgo_otro'] === 'Rotación del equipo', '«Otro» marcado guarda su texto');
local_chatbot_verificar($limpio['participantes'] === '' && $limpio['tiempo_total'] === '360',
    'los números solo aceptan dígitos');
local_chatbot_verificar(mb_strlen($limpio['aprendizaje']) === exante::LARGO_PARRAFO, 'los párrafos tienen tope');
local_chatbot_verificar(!isset($limpio['clave_desconocida']), 'una clave desconocida no se guarda');
local_chatbot_verificar($limpio['organismo'] === '', 'un arreglo en un campo de texto no se guarda');
local_chatbot_verificar(array_keys(exante::clean([])) === array_values(array_filter(array_keys($claves), static function($c) {
    return (!preg_match('/^criterio(\d+)$/', $c, $m) || (int) $m[1] <= exante::CRITERIOS_FIJOS)
        && empty(exante::field($c)['reciente']);
})), 'la ficha vacía tiene todas las claves (las preguntas recientes y los criterios del 6 en adelante, solo si traen texto)');
// Seccion 1: «Plan formativo», «Módulo» y «Competencia del módulo», con los rotulos del Word del cliente (30-10-2026).
$ids1 = array_column($secciones[1]['campos'], 'id');
local_chatbot_verificar(array_slice($ids1, 1, 3) === ['plan', 'modulo', 'competencia']
    && exante::field('plan')['label'] === 'Plan formativo' && exante::field('modulo')['label'] === 'Módulo'
    && exante::field('competencia')['label'] === 'Competencia del módulo',
    'sección 1: Plan formativo, y a continuación Módulo y Competencia del módulo (rótulos del Word)');
$conmodulo = exante::clean(['modulo' => 'Comunicación efectiva', 'competencia' => 'Comunicar información en contextos laborales.']);
local_chatbot_verificar($conmodulo['modulo'] === 'Comunicación efectiva' && $conmodulo['competencia'] !== ''
    && !isset(exante::clean([])['modulo']) && !isset(exante::clean([])['competencia']),
    'las preguntas nuevas se guardan con texto; vacías no cambian la huella de una ficha ya validada');

// --- Ejemplo de llenado del cliente (competencia «Comunicación efectiva en contextos laborales») ---
$ejemplo = exante::clean([
    'organismo' => 'OTEC Formación Integral Ltda.', 'plan' => 'Módulo "Comunicación para el trabajo"',
    'modulo' => 'Comunicación efectiva', 'competencia' => 'Comunicar información en contextos laborales.',
    'obs_finales' => 'La simulación permite trabajar con la conectividad intermitente.',
    'nivel' => 'Nivel 3', 'facilitador' => 'María González', 'fechas' => '19 de marzo de 2026', 'participantes' => '24',
    'aprendizaje' => 'Comunicar información de manera clara, respetuosa y pertinente en contextos laborales, adaptando '
        . 'el lenguaje al interlocutor y al propósito comunicativo.',
    'criterio1' => 'Redacta mensajes profesionales con claridad, coherencia y corrección ortográfica.',
    'criterio2' => 'Adapta el registro lingüístico al contexto y al interlocutor.',
    'criterio3' => 'Comunica instrucciones sin ambigüedad, asegurando comprensión del receptor.',
    'criterio4' => 'Mantiene trato respetuoso y cordial en interacciones laborales.',
    'tiempo_total' => '360', 'tiempo_experiencia' => '180',
    'obs_evaluacion' => 'Los criterios son claros y observables.',
    'vinculo' => 'integracion', 'desempeno' => ['rutinarias', 'tecnicas', 'colaborativo'], 'alineacion' => 'parcial',
    'complejidad' => 'intermedio', 'dominio' => 'medio', 'heterogeneidad' => 'alta',
    'necesidades' => ['practico', 'equipo', 'lectora'], 'participacion' => 'regular', 'contexto' => 'simulacion',
    'estrategia_didactica' => 'casos', 'pertinencia_contexto' => 'alta', 'situaciones' => 'si',
    'espacio' => 'ajustes', 'equipamiento' => 'parcial', 'conectividad' => 'intermitente',
    'digitales' => ['plataforma', 'audiovisual'], 'accesibilidad' => 'total', 'modalidad' => 'simulacion',
    'estrategia' => 'caso', 'evidencia' => 'producto', 'instrumento' => 'rubrica', 'pertinencia_global' => 'alto',
    'ajustes' => 'no', 'riesgo' => 'recursos',
]);
local_chatbot_verificar(exante::missing_inputs($ejemplo) === [], 'el ejemplo del cliente pasa el control de entrada');
$sinobs = $ejemplo;
foreach (array_keys($opcionales) as $id) {
    $sinobs[$id] = '';
}
local_chatbot_verificar(exante::missing_inputs($sinobs) === [], 'sin las observaciones opcionales la ficha igual se valida');
local_chatbot_verificar(exante::missing_inputs($ejemplo, ['exante_plan']) === []
    && exante::missing_inputs($ejemplo, ['exante_plan', 'exante_perfil']) === [],
    'con el Plan Formativo adjunto se valida; el Perfil Ocupacional es opcional');
local_chatbot_verificar(exante::missing_inputs($ejemplo, []) === ['Plan Formativo (archivo adjunto obligatorio)']
    && exante::missing_inputs($ejemplo, ['exante_perfil']) === ['Plan Formativo (archivo adjunto obligatorio)'],
    'sin el Plan Formativo adjunto no se valida (regla del 30-10-2026)');
$incompleta = $ejemplo;
$incompleta['modulo'] = $incompleta['obs_evaluacion'] = $incompleta['obs_finales'] = '';
$incompleta['espacio'] = '';
$incompleta['riesgo'] = 'otro';
local_chatbot_verificar(exante::missing_inputs($incompleta) === ['Sección 1: Módulo',
    '2.4 Observaciones sobre la evaluación del aprendizaje esperado', '6.1 Espacio físico',
    '8.3 Riesgo principal detectado (especificar «Otro»)', 'Sección 8: Observaciones finales'],
    'se pide todo lo que no es opcional, en orden, con su número o su sección, y «Otro» sin especificar');
local_chatbot_verificar(exante::criteria_count($ejemplo) === 4, 'el ejemplo registra 4 criterios');

// --- Control de entrada del Prompt Maestro ---
$falta = exante::missing_inputs(exante::clean([]));
local_chatbot_verificar(count($falta) === 38, 'la ficha vacía pide los 38 campos obligatorios (sin las observaciones opcionales)');
local_chatbot_verificar(in_array('2.1 Aprendizaje esperado', $falta, true), 'se pide el aprendizaje esperado por su nombre');
local_chatbot_verificar(in_array('5.2 Estrategia didáctica más pertinente', $falta, true),
    'se pide la estrategia didáctica con el texto de la ficha');
// Prompt Maestro: «No utilizar menos de 3 o más de 5 criterios de evaluación».
$criterios = static function(array $llenos) use ($ejemplo) {
    $f = $ejemplo;
    for ($i = 1; $i <= 5; $i++) {
        $f['criterio' . $i] = in_array($i, $llenos, true) ? 'Criterio ' . $i : '';
    }
    return exante::missing_inputs($f);
};
// Prompt Maestro del 30-10-2026: si el plan declara menos de 3, se usan todos (vacío de información, no faltante).
local_chatbot_verificar($criterios([]) === ['2.1 Criterios de evaluación'], 'sin criterios no se valida');
local_chatbot_verificar($criterios([1]) === [] && $criterios([1, 2]) === [],
    'con 1 o 2 criterios se valida: el plan puede declarar menos de 3');
$avisocrit = exante::form_warnings(['criterio1' => 'A', 'criterio2' => 'B'] + exante::clean([]));
local_chatbot_verificar(count($avisocrit) === 1 && strpos($avisocrit[0], 'vacío de información') !== false
    && exante::form_warnings(['criterio1' => 'A', 'criterio2' => 'B', 'criterio3' => 'C'] + exante::clean([])) === [],
    'con menos de 3 criterios se avisa (sin impedir validar)');
local_chatbot_verificar($criterios([2, 4, 5]) === [], 'con 3 criterios en cualquier casilla se valida');
local_chatbot_verificar($criterios([1, 2, 3, 4, 5]) === [], 'con 5 criterios se valida');
// Criterios sin maximo (ajuste del cliente del 29-10-2026): 3 de partida y se agregan de a uno.
local_chatbot_verificar(count(exante::field_keys()) === count(array_unique(array_keys(exante::field_keys())))
    && isset(exante::field_keys()['criterio' . exante::CRITERIOS_MAX])
    && !isset(exante::field_keys()['criterio' . (exante::CRITERIOS_MAX + 1)]), 'la ficha admite criterios más allá de 5');
$muchos = [];
for ($i = 1; $i <= 8; $i++) {
    $muchos['criterio' . $i] = 'Criterio ' . $i;
}
$muchoslimpio = exante::clean($muchos);
local_chatbot_verificar(exante::criteria_count($muchoslimpio) === 8 && $muchoslimpio['criterio8'] === 'Criterio 8'
    && !isset($muchoslimpio['criterio9']), 'con 8 criterios se guardan los 8, y nada más');
local_chatbot_verificar($criterios([1, 2, 3, 4, 5, 6, 7]) === [], 'con 7 criterios se valida (no hay máximo)');
$c1 = exante::field('criterio1');
local_chatbot_verificar(strpos($c1['antes'] ?? '', 'No existe un número máximo de criterios de evaluación') !== false
    && strpos($c1['antes'], 'al menos 3') === false
    && empty($c1['ayuda']) && empty($c1['adicional']) && empty(exante::field('criterio3')['adicional'])
    && !empty(exante::field('criterio4')['adicional']),
    'el texto del cliente va arriba del criterio 1; del 4 en adelante son criterios agregados');
$sinsecc4 = $ejemplo;
$sinsecc4['necesidades'] = [];
local_chatbot_verificar(exante::missing_inputs($sinsecc4) === ['4.3 Principales necesidades del grupo (marcar todas las que apliquen)'],
    'sin necesidades del grupo no se valida');

// --- La ficha como texto para el modelo ---
$texto = exante::to_text($ejemplo);
local_chatbot_verificar(strpos($texto, 'María González') === false, 'el nombre de la persona facilitadora no viaja al modelo');
local_chatbot_verificar(strpos($texto, 'Nombre del facilitador o facilitadora: (registrado)') !== false,
    'el modelo sabe que el nombre se registró');
local_chatbot_verificar(strpos($texto, '3.2. El perfil ocupacional exige desempeño en: Tareas rutinarias; Tareas técnicas; '
    . 'Trabajo colaborativo') !== false, 'las alternativas viajan con su texto');
local_chatbot_verificar(strpos($texto, '- Criterio de evaluación 4: Mantiene trato respetuoso') !== false
    && strpos($texto, 'Criterio de evaluación 5') === false && strpos($texto, 'Criterio de evaluación 6') === false,
    'viajan los criterios registrados; los agregados vacíos no viajan como faltantes');
$pocos = $ejemplo;
$pocos['criterio3'] = $pocos['criterio4'] = $pocos['criterio5'] = '';
local_chatbot_verificar(strpos(exante::to_text($pocos), '- Criterio de evaluación 3: (sin respuesta)') !== false,
    'con menos de 3 criterios, las 3 casillas de partida vacías sí faltan');
local_chatbot_verificar(strpos($texto, '- 2.4. Observaciones sobre la evaluación del aprendizaje esperado: Los criterios son '
    . 'claros y observables.') !== false, 'una observación con información viaja al modelo');
local_chatbot_verificar(strpos($texto, '- Observaciones breves (opcional): (opcional, sin información)') !== false
    && strpos($texto, '- Observaciones finales (obligatorio): La simulación permite') !== false,
    'una observación opcional vacía viaja como opcional; las observaciones finales viajan con su texto');
local_chatbot_verificar(strpos($texto, 'Revisión del diagnóstico') === false && strpos($texto, 'Validación con equipo') === false
    && strpos($texto, "\n9. ") === false, 'la sección 9 ya no viaja al modelo');
$conotro = $ejemplo;
$conotro['riesgo'] = 'otro';
$conotro['riesgo_otro'] = 'Rotación del equipo';
local_chatbot_verificar(strpos(exante::to_text(exante::clean($conotro)), 'Riesgo principal detectado: Otro: Rotación del equipo') !== false,
    '«Otro» viaja con lo especificado');
$conotro['riesgo_otro'] = '';
local_chatbot_verificar(strpos(exante::to_text(exante::clean($conotro)), 'Otro: (sin especificar)') !== false,
    '«Otro» sin especificar se dice');

// --- Huella: cambiar la ficha o un adjunto pide validar de nuevo ---
$h = exante::fingerprint($ejemplo, ['exante_plan' => 'abc']);
local_chatbot_verificar($h === exante::fingerprint(array_reverse($ejemplo, true), ['exante_plan' => 'abc']),
    'la huella no depende del orden de las claves');
local_chatbot_verificar($h !== exante::fingerprint($ejemplo, ['exante_plan' => 'abd']), 'cambiar un adjunto cambia la huella');
local_chatbot_verificar($h !== exante::fingerprint($ejemplo, []), 'quitar un adjunto cambia la huella');
$otro = $ejemplo;
$otro['tiempo_experiencia'] = '200';
local_chatbot_verificar($h !== exante::fingerprint($otro, ['exante_plan' => 'abc']), 'cambiar la ficha cambia la huella');
// Migracion: una ficha guardada con la seccion 9 se valido con otra huella; se pone al dia sin que la persona la toque.
$vieja = $ejemplo + ['val_revision_fecha' => '20/03/2026', 'val_revision_responsable' => 'Coordinador técnico',
    'val_equipo_fecha' => '', 'val_equipo_responsable' => '', 'val_aprobacion_fecha' => '', 'val_aprobacion_responsable' => ''];
[$antes, $ahora] = exante::schema_fingerprints($vieja, ['exante_plan' => 'abc']);
local_chatbot_verificar($antes === exante::fingerprint($vieja, ['exante_plan' => 'abc']) && $ahora === $h && $antes !== $ahora,
    'migración: la huella vieja (con la sección 9) pasa a la de la ficha sin ella');
local_chatbot_verificar(exante::schema_fingerprints($ejemplo, ['exante_plan' => 'abc']) === [$h, $h],
    'migración: una ficha ya al día no cambia de huella');

// --- Instrucciones al modelo ---
$prompt = exante::build_prompt([]);
foreach ($checklist as $seccion) {
    foreach ($seccion['items'] as $id => $item) {
        if (strpos($prompt, $id . ': ' . $item) === false) {
            local_chatbot_verificar(false, "el prompt incluye {$id} con su texto");
        }
    }
}
local_chatbot_verificar(strpos($prompt, guardrails::injection_clause()) !== false, 'el prompt trae la cláusula contra inyección');
local_chatbot_verificar(strpos($prompt, 'No se adjuntó el Plan Formativo, que es documento de referencia obligatorio') !== false,
    'sin el Plan Formativo, el prompt lo dice');
$prompt1 = exante::build_prompt(['Plan Formativo']);
local_chatbot_verificar(strpos($prompt1, 'Se adjunta el Plan Formativo, documento de referencia obligatorio') !== false
    && strpos($prompt1, 'No se adjuntó el Perfil Ocupacional, que es opcional: continúa normalmente') !== false
    && strpos($prompt1, 'señala cuando alguna decisión podría beneficiarse de ese documento') !== false,
    'Prompt Maestro del Módulo 2: Plan Formativo obligatorio, Perfil Ocupacional opcional');
local_chatbot_verificar(strpos(exante::build_prompt(['Plan Formativo', 'Perfil Ocupacional']),
    'priorizando siempre la información de la ficha de diagnóstico y del Plan Formativo') !== false,
    'con el Perfil Ocupacional, se prioriza la ficha y el Plan Formativo');
local_chatbot_verificar(strpos($prompt, 'En el diseño se utilizan al menos 3 de esos criterios: si el plan formativo declara '
    . 'menos de 3, la ficha los registra todos y eso no es un incumplimiento de la ficha; infórmalo como vacío de información') !== false
    && strpos($prompt, 'ni más de 5') === false && strpos($prompt, '4 de los 9 criterios') === false,
    'regla de criterios del Prompt Maestro del 30-10: al menos 3 en el diseño; con menos en el plan, vacío de información');
local_chatbot_verificar(strpos($prompt, 'acepta las respuestas de la sección 3 de la ficha basadas en la experiencia de la persona '
    . 'facilitadora, siempre que estén justificadas en las observaciones de esa sección') !== false
    && strpos($prompt, 'Única excepción, el ítem c3_1') !== false,
    'sin perfil ocupacional, la sección 3 se acepta si está justificada en sus observaciones (ítem c3_1)');
local_chatbot_verificar(strpos($prompt, 'Completar un campo no implica aprobarlo') !== false
    && strpos($prompt, 'señalar el campo de la ficha o del plan formativo que la origina') !== false
    && strpos($prompt, 'formación de personas adultas o formación laboral') !== false,
    'control de entrada, observación con el campo que la origina y «formación de personas adultas»');
local_chatbot_verificar(strpos($prompt, '"adecuadamente"') === false && strpos($prompt, 'juicios de valor') === false,
    'se elimina la regla de juicios de valor (en rojo en el Prompt Maestro del 30-10)');
local_chatbot_verificar(strpos($prompt, 'Nunca utilices conceptos relativos a «pedagógico» o «pedagogía»') !== false
    && strpos($prompt, 'La ficha de diagnóstico y el Plan Formativo son la fuente principal') !== false
    && strpos($prompt, 'Apoyas a personas facilitadoras') !== false,
    'reglas nuevas: sin «pedagogía», ficha y Plan Formativo como fuente principal, personas facilitadoras');
local_chatbot_verificar(strpos($prompt, 'lenguaje inclusivo') !== false, 'regla de lenguaje inclusivo');
local_chatbot_verificar(strpos($prompt, 'Las observaciones breves de las secciones 3 a 7 son opcionales (Sección 3: Observaciones '
    . 'breves; Sección 4: Observaciones breves;') !== false && strpos($prompt, 'c1_1 (datos administrativos)') === false
    && strpos($prompt, 'no lo menciones en observaciones, debilidades ni sugerencias') !== false,
    'el prompt dice qué observaciones son opcionales y que vacías no se reportan');
local_chatbot_verificar(strpos($prompt, 'no incluye la tabla de validación del documento') !== false
    && strpos($prompt, 'no la reportes como faltante') !== false, 'el prompt sabe que la ficha no trae la sección 9');

// --- Lectura del informe del modelo ---
$completo = ['items' => [], 'condicion' => 'aprobado', 'debilidades' => ['d1'], 'sugerencias' => ['s1', '', 's2']];
foreach (exante::checklist_ids() as $id) {
    $completo['items'][$id] = ['estado' => 'cumple', 'observacion' => 'ok ' . $id];
}
$json = json_encode($completo, JSON_UNESCAPED_UNICODE);
$leido = exante::parse_report($json);
local_chatbot_verificar($leido !== null && count($leido['items']) === 33, 'un informe completo se lee');
local_chatbot_verificar($leido['condicion_modelo'] === exante::APROBADO, 'se lee la condición propuesta');
local_chatbot_verificar($leido['sugerencias'] === ['s1', 's2'], 'las listas descartan vacíos');
local_chatbot_verificar(exante::parse_report("```json\n{$json}\n```") !== null, 'un informe entre bloques de código se lee');
local_chatbot_verificar(exante::parse_report("Aquí va el informe:\n{$json}\nSaludos.") !== null,
    'un informe con texto alrededor se lee');
$incompleto = $completo;
unset($incompleto['items']['c7_3']);
local_chatbot_verificar(exante::parse_report(json_encode($incompleto)) === null, 'si falta un ítem, el informe no se usa');
$raro = $completo;
$raro['items']['c1_1']['estado'] = 'excelente';
local_chatbot_verificar(exante::parse_report(json_encode($raro)) === null, 'una evaluación desconocida invalida el informe');
local_chatbot_verificar(exante::parse_report(null) === null && exante::parse_report('no es json') === null,
    'sin respuesta o sin JSON no hay informe');
$sinonimos = $completo;
$sinonimos['items']['c1_1']['estado'] = 'Cumple parcialmente';
$sinonimos['items']['c1_2']['estado'] = '❌ No cumple';
$sinonimos['items']['c2_1']['estado'] = '✔';
$sinonimos['condicion'] = 'Aprobado con observaciones';
$sinonimos['debilidades'] = array_fill(0, 9, 'x');
$leido = exante::parse_report(json_encode($sinonimos, JSON_UNESCAPED_UNICODE));
local_chatbot_verificar($leido !== null && $leido['items']['c1_1']['estado'] === exante::PARCIAL
    && $leido['items']['c1_2']['estado'] === exante::NO_CUMPLE && $leido['items']['c2_1']['estado'] === exante::CUMPLE,
    'se entienden las evaluaciones escritas como en el checklist');
local_chatbot_verificar($leido['condicion_modelo'] === exante::OBSERVACIONES, 'se entiende «Aprobado con observaciones»');
local_chatbot_verificar(count($leido['debilidades']) === 6, 'a lo más 6 debilidades');

// --- Regla de decisión ---
$todo = [];
foreach (exante::checklist_ids() as $id) {
    $todo[$id] = ['estado' => exante::CUMPLE];
}
$con = static function(array $cambios) use ($todo) {
    foreach ($cambios as $id => $estado) {
        $todo[$id]['estado'] = $estado;
    }
    return $todo;
};
// Regla del checklist y del Prompt Maestro del 30-10-2026 (regla «criticos», por defecto): deciden los ítems.
local_chatbot_verificar(exante::decide($todo, exante::APROBADO) === exante::APROBADO
    && exante::decide($todo, null) === exante::APROBADO, 'todos los ítems en ✔: aprobado');
local_chatbot_verificar(exante::decide($todo, exante::OBSERVACIONES) === exante::APROBADO
    && exante::decide($todo, exante::NO_APROBADO) === exante::APROBADO,
    'la condición la fija la regla sobre los ítems, no la que propone el modelo');
local_chatbot_verificar(exante::decide($con(['c6_1' => exante::PARCIAL]), exante::APROBADO) === exante::OBSERVACIONES
    && exante::decide($con(['c6_1' => exante::NO_CUMPLE]), exante::APROBADO) === exante::OBSERVACIONES
    && exante::decide($con(['c2_5' => exante::NO_CUMPLE, 'c3_1' => exante::PARCIAL]), null) === exante::OBSERVACIONES,
    '⚠ o ❌ en ítems no críticos (c2_5 ya no es crítico): aprobado con observaciones');
foreach (exante::CRITICOS as $id) {
    local_chatbot_verificar(exante::decide($con([$id => exante::NO_CUMPLE]), exante::APROBADO) === exante::NO_APROBADO
        && exante::decide($con([$id => exante::PARCIAL]), exante::APROBADO) === exante::NO_APROBADO,
        "❌ o ⚠ en el ítem crítico {$id}: no aprobado, aunque el modelo apruebe");
}
$forma = $con(['c2_2' => exante::PARCIAL]);
$forma['c2_2']['forma'] = true;
local_chatbot_verificar(exante::decide($forma, null) === exante::OBSERVACIONES && exante::critical_failures($forma) === []
    && exante::decision_rule($forma) === 'forma',
    'única excepción: una diferencia de forma en un crítico (⚠) deja aprobado con observaciones');
$formano = $con(['c2_2' => exante::NO_CUMPLE]);
$formano['c2_2']['forma'] = true;
local_chatbot_verificar(exante::decide($formano, null) === exante::NO_APROBADO, 'un ❌ en un crítico no se excusa como forma');
local_chatbot_verificar(exante::decision_rule($todo) === 'todo_cumple'
    && exante::decision_rule($con(['c6_1' => exante::PARCIAL])) === 'no_criticos'
    && exante::decision_rule($con(['c1_3' => exante::PARCIAL, 'c6_1' => exante::NO_CUMPLE])) === 'critico_parcial'
    && exante::decision_rule($con(['c1_3' => exante::PARCIAL, 'c9_2' => exante::NO_CUMPLE])) === 'critico_no'
    && exante::critical_failures($con(['c9_2' => exante::NO_CUMPLE, 'c1_3' => exante::PARCIAL])) === ['c1_3', 'c9_2'],
    'la regla aplicada y los críticos que detienen la ficha, en el orden del checklist');
$sincritico = $todo;
unset($sincritico['c1_3']);
local_chatbot_verificar(exante::decide($sincritico, exante::APROBADO) === exante::NO_APROBADO,
    'un ítem crítico sin evaluar no aprueba');
$pend = exante::unresolved_items($con(['c6_1' => exante::PARCIAL, 'c2_4' => exante::NO_CUMPLE]));
local_chatbot_verificar(array_keys($pend) === ['c2_4', 'c6_1'] && $pend['c2_4']['seccion'] === 2
    && $pend['c6_1']['estado'] === exante::PARCIAL && exante::unresolved_items($todo) === [],
    'registro de observaciones no resueltas: los ítems en ⚠ o ❌, en orden, con su sección');
// Regla «motivos» (interruptor «reglacriticos» apagado, «iaapoyo» encendido): v1.20.6 a v1.20.12.
$m = exante::REGLA_MOTIVOS;
local_chatbot_verificar(exante::decide($todo, exante::OBSERVACIONES, null, $m) === exante::OBSERVACIONES
    && exante::decide($todo, exante::NO_APROBADO, null, $m) === exante::OBSERVACIONES
    && exante::decide($con(['c6_1' => exante::PARCIAL]), exante::APROBADO, null, $m) === exante::APROBADO
    && exante::decide($con(['c6_1' => exante::NO_CUMPLE]), exante::APROBADO, null, $m) === exante::OBSERVACIONES,
    'regla «motivos»: sin motivo grave no hay «No aprobado» y un parcial no impide aprobar');
foreach (exante::CRITICOS as $id) {
    local_chatbot_verificar(exante::decide($con([$id => exante::NO_CUMPLE]), exante::NO_APROBADO, null, $m) === exante::OBSERVACIONES,
        "regla «motivos»: incumplir {$id} sin motivo grave no deja «No aprobado»");
}
$ficha4 = "- 2.1. Aprendizaje esperado: Conocer la historia de la computación\n- Criterio de evaluación 1: Lista fechas";
$tema = ['codigo' => 'aprendizaje', 'cita' => 'Conocer la historia de la computación', 'corregir' => 'Transcríbelo del Plan Formativo'];
$val = exante::valid_reason($tema, $ficha4, exante::NO_APROBADO, true);
local_chatbot_verificar($val !== null && $val['codigo'] === 'aprendizaje'
    && exante::decide($con(['c2_1' => exante::NO_CUMPLE]), exante::NO_APROBADO, $val, $m) === exante::NO_APROBADO,
    'regla «motivos»: un motivo grave con su cita deja «No aprobado»');
local_chatbot_verificar(exante::decide($todo, exante::APROBADO, $val, $m) === exante::NO_APROBADO,
    'regla «motivos»: el motivo grave manda sobre el modelo');
local_chatbot_verificar(exante::valid_reason(['codigo' => 'aprendizaje', 'cita' => '', 'corregir' => 'x'], $ficha4, exante::NO_APROBADO, true) === null,
    'sin cita no hay motivo grave');
local_chatbot_verificar(exante::valid_reason(['codigo' => 'aprendizaje', 'cita' => 'Texto que no está en la ficha', 'corregir' => ''], $ficha4, exante::NO_APROBADO, true) === null,
    'una cita que no está en la ficha no sostiene el motivo');
local_chatbot_verificar(exante::valid_reason(['codigo' => 'tema', 'cita' => 'Conocer la historia de la computación'], $ficha4, exante::NO_APROBADO, true) === null,
    'un código fuera de los cuatro motivos no cuenta');
local_chatbot_verificar(exante::valid_reason(['codigo' => 'aprendizaje', 'cita' => 'conocer la HISTORIA de la computacion'], $ficha4, exante::NO_APROBADO, true) !== null,
    'la cita se compara sin tildes, mayúsculas ni signos');
local_chatbot_verificar(exante::valid_reason($tema, $ficha4, exante::NO_APROBADO) === null
    && exante::valid_reason(['codigo' => 'criterios', 'cita' => 'Criterio de evaluación 1: Lista fechas'], $ficha4, exante::NO_APROBADO) === null
    && exante::valid_reason(['codigo' => 'competencia', 'cita' => 'Conocer la historia de la computación'], $ficha4, exante::NO_APROBADO) === null,
    'sin el Plan Formativo adjunto no se sostienen los motivos que comparan con él');
local_chatbot_verificar(exante::valid_reason(['codigo' => 'coherencia', 'cita' => 'Conocer la historia de la computación'], $ficha4, exante::NO_APROBADO) !== null,
    'el motivo «coherencia» se sostiene con una cita de la ficha, con o sin plan');
// Bienvenida del Prompt Maestro, textual (revision del 30-10).
$bienvenida = exante::welcome();
local_chatbot_verificar(count($bienvenida) === 3
    && $bienvenida[0] === 'Bienvenido/a, soy tu asistente de apoyo en diseño instruccional por competencias.'
    && strpos($bienvenida[1], 'Te puedo ayudar a analizar fichas de diagnóstico (evaluación ex ante), diseñar experiencias') === 0
    && strpos($bienvenida[2], 'Comienza completando tu ficha de diagnóstico para trabajo de campo; puedes apoyarte en la guía de '
        . 'llenado. Después de completar la ficha, debes cargarla junto con el plan formativo') === 0
    && substr($bienvenida[2], -strlen('enriquecer el análisis y mejorar la precisión del diseño.')) === 'enriquecer el análisis y mejorar la precisión del diseño.',
    'la bienvenida es la del Prompt Maestro, textual, en 3 párrafos');
local_chatbot_verificar(exante::valid_reason($tema, $ficha4, exante::APROBADO) === null
    && exante::valid_reason($tema, $ficha4, exante::OBSERVACIONES) === null,
    'un motivo que acompaña a otra condición del modelo no detiene la ficha');
// Interruptor «iaapoyo» apagado: vuelve la regla anterior.
$e = exante::REGLA_ESTRICTA;
local_chatbot_verificar(exante::decide($con(['c2_1' => exante::NO_CUMPLE]), exante::APROBADO, null, $e) === exante::NO_APROBADO
    && exante::decide($todo, exante::NO_APROBADO, null, $e) === exante::NO_APROBADO
    && exante::decide($con(['c6_1' => exante::PARCIAL]), exante::APROBADO, null, $e) === exante::OBSERVACIONES
    && exante::decide($todo, exante::APROBADO, null, $e) === exante::APROBADO,
    'con el interruptor apagado vuelve la regla anterior (críticos y «No aprobado» del modelo detienen; parcial deja observaciones)');
local_chatbot_verificar(array_keys(exante::serious_reasons()) === ['aprendizaje', 'criterios', 'competencia', 'coherencia']
    && exante::plan_reasons() === ['aprendizaje', 'criterios', 'competencia'],
    'los motivos graves son los cuatro de la regla de decisión del Prompt Maestro del 30-10');
local_chatbot_verificar(exante::TEMPERATURA <= 0.2, 'la validación usa temperatura baja');
$pc = exante::build_prompt(['Plan Formativo']);
local_chatbot_verificar(strpos($pc, 'c1_3: La competencia del módulo y el nivel de cualificación corresponden a lo declarado en el '
    . 'plan formativo [CRÍTICO]') !== false && strpos($pc, 'c2_5: Existe coherencia entre aprendizaje y criterios [CRÍTICO]') === false
    && substr_count($pc, '[CRÍTICO]') === 5 + 1 && strpos($pc, '❌ en cualquier ítem CRÍTICO → "no_aprobado"') !== false
    && strpos($pc, 'se trata como "no_aprobado" hasta que la ficha se corrija. Única excepción: diferencias de forma') !== false
    && strpos($pc, 'Sin problemas en ítems críticos, pero con ⚠ o ❌ en ítems no críticos → "observaciones"') !== false
    && strpos($pc, 'Todos los ítems en ✔ → "aprobado"') !== false && strpos($pc, '"forma": false') !== false
    && strpos($pc, 'motivo_grave') === false && strpos($pc, 'MOTIVOS GRAVES') === false,
    'el prompt trae la regla de decisión del checklist del 30-10: ítems CRÍTICO marcados, excepción de forma y sin motivos graves');
$pm = exante::build_prompt([], false, false, exante::REGLA_MOTIVOS);
local_chatbot_verificar(strpos($pm, '[CRÍTICO]') === false && strpos($pm, 'MOTIVOS GRAVES') !== false && strpos($pm, '"motivo_grave"') !== false
    && strpos($pm, 'no hay correspondencia entre el definido en la ficha y el declarado en el Plan Formativo') !== false
    && strpos($pm, 'secciones 1, 2 y 3 y los recursos, estrategias y decisiones de diseño') !== false
    && strpos($pm, '"aprendizaje|criterios|competencia|coherencia"') !== false
    && strpos($pm, 'ningún ítem "no_cumple" (puede haber ítems "parcial")') !== false,
    'con la regla «motivos» el prompt cierra «No aprobado» en los cuatro motivos y acepta parciales en «aprobado»');
local_chatbot_verificar(strpos($pm, 'no reportes como faltantes') !== false && strpos($pm, '3.3 "Parcialmente alineado" no baja el ítem c3_4') !== false
    && strpos($pm, 'no los bajes por algo que ya señalaste') !== false && strpos($pm, '"propuesta"') !== false
    && strpos($pm, 'sin bajar por eso ningún ítem') !== false,
    'el prompt cubre criterios vacíos, sinceridad, sección 9 sin doble castigo, propuestas y el Perfil Ocupacional opcional');
$informe = exante::parse_report(json_encode(['items' => array_map(static function($id) {
    return ['estado' => 'parcial', 'observacion' => 'o', 'propuesta' => 'En 8.2 marca Sí y anota: conectividad irregular'];
}, array_combine(exante::checklist_ids(), exante::checklist_ids())), 'condicion' => 'aprobado',
    'motivo_grave' => ['codigo' => 'TEMA', 'cita' => 'Conocer la historia', 'corregir' => 'c']]));
$conforma = exante::parse_report(json_encode(['items' => array_map(static function($id) {
    return ['estado' => 'parcial', 'observacion' => 'o', 'propuesta' => 'p', 'forma' => 'sí'];
}, array_combine(exante::checklist_ids(), exante::checklist_ids())), 'condicion' => 'observaciones']));
local_chatbot_verificar(!empty($conforma['items']['c2_2']['forma']) && !isset($conforma['items']['c6_1']['forma'])
    && !isset($informe['items']['c2_2']['forma']),
    '«forma» solo se guarda en un ítem crítico parcial, y solo si el modelo lo dice');
local_chatbot_verificar($informe['items']['c1_1']['propuesta'] === 'En 8.2 marca Sí y anota: conectividad irregular'
    && $informe['motivo']['codigo'] === 'tema' && $informe['motivo']['cita'] === 'Conocer la historia',
    'el informe trae la propuesta de cada ítem y el motivo grave');
$sinmotivo = exante::parse_report(json_encode(['items' => array_map(static function($id) {
    return ['estado' => 'cumple', 'observacion' => '', 'propuesta' => 'no debería quedar'];
}, array_combine(exante::checklist_ids(), exante::checklist_ids())), 'condicion' => 'aprobado', 'motivo_grave' => null]));
local_chatbot_verificar($sinmotivo['motivo'] === null && $sinmotivo['items']['c1_1']['propuesta'] === '',
    'sin motivo grave no hay motivo, y un ítem que cumple no lleva propuesta');
// Avisos antes de validar (sin IA, no bloquean).
$base = exante::clean([]);
local_chatbot_verificar(exante::form_warnings($base) === [], 'una ficha vacía no da avisos');
local_chatbot_verificar(count(exante::form_warnings(['alineacion' => 'parcial', 'ajustes' => 'no'] + $base)) === 1
    && exante::form_warnings(['alineacion' => 'parcial', 'ajustes' => 'si'] + $base) === [],
    'aviso: 3.3 parcial con 8.2 «No» es contradictorio');
local_chatbot_verificar(count(exante::form_warnings(['tiempo_total' => '60', 'tiempo_experiencia' => '60'] + $base)) === 1
    && count(exante::form_warnings(['tiempo_total' => '60', 'tiempo_experiencia' => '90'] + $base)) === 1
    && exante::form_warnings(['tiempo_total' => '60', 'tiempo_experiencia' => '45'] + $base) === [],
    'aviso: el tiempo 2.3 debe ser menor que el 2.2');
$otro = exante::form_warnings(['necesidades' => ['practico', 'otra'], 'necesidades_otro' => ''] + $base);
local_chatbot_verificar(count($otro) === 1 && strpos($otro[0], '4.3') !== false
    && exante::form_warnings(['necesidades' => ['otra'], 'necesidades_otro' => 'Idioma'] + $base) === [],
    'aviso: «Otro» marcado sin especificar');
local_chatbot_verificar(exante::can_advance(exante::APROBADO) && exante::can_advance(exante::OBSERVACIONES)
    && !exante::can_advance(exante::NO_APROBADO) && !exante::can_advance(null), 'solo se avanza aprobado o con observaciones');

// --- Secciones de las fases del Modulo 2 ---
$nombres = [0 => 'General', 1 => 'Bienvenida', 2 => 'Fase 1: Diagnóstico y Validación',
    3 => '<span>FASE 2: Diseño</span>', 4 => 'Fase 3: Validación técnica', 5 => 'Fase 10'];
local_chatbot_verificar(exante::phase_section($nombres, 1) === 2, 'se encuentra la Fase 1');
local_chatbot_verificar(exante::phase_section($nombres, 2) === 3, 'se encuentra la Fase 2 aunque venga en mayúsculas y con HTML');
local_chatbot_verificar(exante::phase_section([5 => 'Fase 10'], 1) === null, '«Fase 10» no es la Fase 1');
local_chatbot_verificar(exante::phase_section([1 => 'Tema 1'], 2) === null, 'sin sección de la fase, null');

// --- Adjuntos ---
local_chatbot_verificar(exante::attachment_kind('application/pdf', 'Plan.PDF') === 'pdf', 'un PDF se admite');
local_chatbot_verificar(exante::attachment_kind('application/zip', 'perfil.docx') === 'docx', 'un .docx se admite');
local_chatbot_verificar(exante::attachment_kind('application/pdf', 'plan.docx') === null, 'tipo y extensión deben calzar');
local_chatbot_verificar(exante::attachment_kind('application/x-dosexec', 'plan.pdf') === null, 'un ejecutable no se admite');
local_chatbot_verificar(exante::attachment_kind('application/msword', 'plan.doc') === null, 'el .doc antiguo no se admite');

if (class_exists('ZipArchive')) {
    $ruta = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive();
    $zip->open($ruta, ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="x"><w:body>'
        . '<w:p><w:r><w:t>Perfil ocupacional</w:t></w:r></w:p><w:p><w:r><w:t>Tareas</w:t><w:tab/>'
        . '<w:t>R&amp;D «técnicas»</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
    local_chatbot_verificar(exante::docx_text($ruta) === "Perfil ocupacional\nTareas\tR&D «técnicas»",
        'el texto de un .docx se extrae por párrafos');
    file_put_contents($ruta, 'no es un zip');
    local_chatbot_verificar(exante::docx_text($ruta) === null, 'un archivo que no es .docx no da texto');
    unlink($ruta);
}

// --- Curso del Modulo 2: enlaces de la Fase 1 (puntos 1 a 4 del pedido) ---
$rec = modulo2_setup::match_resources([603 => 'Descarga o revisa aquí la Guía de Llenado de la Ficha de Diagnóstico',
    604 => 'Descarga aquí la Ficha de Diagnóstico para Trabajo de Campo',
    605 => 'Descarga o revisa aquí un ejemplo de llenado de la Ficha de Diagnóstico',
    606 => 'Descarga o revisa aquí el checklist de validación del la Ficha de Diagnóstico',
    608 => 'Descarga o revisa aquí la rúbrica de validación técnica del diseño']);
local_chatbot_verificar($rec === ['material1' => 603, 'material2' => 604, 'material3' => 605, 'checklist' => 606,
    'rubrica' => 608], 'los recursos de las Fases 1 y 3 se reconocen por su nombre, sin confundirse');
local_chatbot_verificar(modulo2_setup::linked_display(6) === 1 && modulo2_setup::linked_display(4) === null
    && modulo2_setup::linked_display(1) === null, 'un recurso en ventana emergente pasa a incrustado; descarga e incrustado no se tocan');
$mat = '<div><!-- MATERIAL 1 --><div style="background: #ffffff;"><div style="background: #20639b;">1</div>'
    . '<h4 style="x">Guía</h4><p>Texto</p></div><!-- MATERIAL 2 --><div><h4>Ficha</h4><p>T</p></div>'
    . '<p style="m">Los documentos deben estar enlazados o adjuntos en Moodle para que puedan abrirlos.</p></div>';
$con = modulo2_setup::remove_material_note(modulo2_setup::link_materials($mat, [1 => 'u1', 2 => 'u2']));
local_chatbot_verificar(substr_count($con, 'href="u1"') === 2 && substr_count($con, 'href="u2"') === 2,
    'cada material enlaza su título y un botón a su recurso');
local_chatbot_verificar(strpos($con, 'background: #20639b; color: #ffffff') !== false,
    'el botón del material usa el color de su número, no el blanco de la tarjeta');
local_chatbot_verificar(strpos($con, 'deben estar enlazados') === false, 'se quita la nota provisional de los materiales');
local_chatbot_verificar(modulo2_setup::link_materials($con, [1 => 'u1', 2 => 'u2']) === $con, 'enlazar materiales es idempotente');
// Tarjetas 4 (Plan Formativo) y 5 (Perfil Ocupacional) con boton al catalogo externo (pedido del 29-10-2026).
local_chatbot_verificar(modulo2_setup::match_urls([7 => 'Busca y descarga aquí el Perfil Ocupacional', 8 => 'Busca y descarga aquí tu Plan Formativo',
    9 => 'Otro enlace']) === [5 => 7, 4 => 8], 'los enlaces URL se reconocen por el nombre: 4 plan formativo, 5 perfil ocupacional');
$tres = '<div><h3>Materiales</h3><!-- MATERIAL 3 --><div style="display: flex; padding: 19px 20px;"><div>3</div><h4>Ejemplo</h4>'
    . '<p>T</p></div><p style="m">Los documentos deben estar enlazados o adjuntos en Moodle.</p></div><!-- AVISO --><div>Aviso</div>';
$con45 = modulo2_setup::add_catalog_cards(modulo2_setup::remove_material_note($tres),
    [4 => 'https://www.sence.cl/plan', 5 => 'https://www.chilevalora.cl/perfil']);
local_chatbot_verificar(strpos($con45, '<!-- MATERIAL 4 -->') !== false && strpos($con45, '<!-- MATERIAL 5 -->') !== false
    && strpos($con45, 'Catálogo Nacional de Planes Formativos (SENCE)') !== false
    && strpos($con45, 'Catálogo de Perfiles Laborales (ChileValora)') !== false, 'tarjetas 4 y 5 con su título y descripción');
local_chatbot_verificar(substr_count($con45, 'Buscar y descargar</a>') === 2
    && substr_count($con45, 'href="https://www.sence.cl/plan"') === 2
    && substr_count($con45, 'href="https://www.chilevalora.cl/perfil"') === 2, 'cada tarjeta enlaza su título y su botón a su catálogo');
local_chatbot_verificar(strpos($con45, 'padding: 19px 20px; margin-bottom: 12px;"><div>3') !== false
    && strpos($con45, '<!-- AVISO -->') > strpos($con45, '<!-- MATERIAL 5 -->'),
    'la tarjeta 3 recibe separación y las nuevas quedan dentro de los materiales, antes del aviso');
local_chatbot_verificar(modulo2_setup::add_catalog_cards($con45, [4 => 'https://www.sence.cl/plan',
    5 => 'https://www.chilevalora.cl/perfil']) === $con45, 'agregar las tarjetas es idempotente');
local_chatbot_verificar(strpos(modulo2_setup::add_catalog_cards($con45, [4 => 'https://otra.cl/', 5 => 'https://www.chilevalora.cl/perfil']),
    'https://otra.cl/') !== false && substr_count(modulo2_setup::add_catalog_cards($con45, [4 => 'https://otra.cl/',
    5 => 'https://www.chilevalora.cl/perfil']), '<!-- MATERIAL 4 -->') === 1, 'si cambia la URL, la tarjeta se rehace sin duplicarse');
local_chatbot_verificar(modulo2_setup::add_catalog_cards($tres, []) === $tres
    && modulo2_setup::add_catalog_cards('<div>sin materiales</div>', [4 => 'https://x.cl/']) === '<div>sin materiales</div>',
    'sin URL o sin la tarjeta 3 no cambia nada');
// Reformateadas por el editor de Moodle o con el texto ajustado por el cliente, con las mismas URL: no se rehacen.
$editadas = str_replace(['href="https://www.sence.cl/plan" target="_blank" rel="noopener" style="color: inherit; text-decoration: underline;"',
    'Buscar y descargar</a>'], ['style="color: inherit; text-decoration: underline;" href="https://www.sence.cl/plan" target="_blank" rel="noopener"',
    'Busca y descarga</a>'], $con45);
local_chatbot_verificar($editadas !== $con45 && modulo2_setup::add_catalog_cards($editadas, [4 => 'https://www.sence.cl/plan',
    5 => 'https://www.chilevalora.cl/perfil']) === $editadas,
    'tarjetas 4 y 5 ya enlazadas a sus URL no se rehacen aunque el editor las reformatee o el cliente ajuste el texto');
// Tarjetas de «Revisa los criterios antes de enviar tu ficha» con el formato de la seccion de diagnostico (29-10-2026).
$tarjeta = static function($t, $p) {
    return '<div style="flex: 1 1 260px; box-sizing: border-box; background: #f5f9fc; border: 1px solid #d5e4ef; border-radius: 10px; padding: 18px;">'
        . "\n" . '<div style="color: #20639b; font-size: 22px; margin-bottom: 8px;">✓</div>' . "\n"
        . '<h4 style="color: #173f5f; margin: 0 0 7px 0;">' . $t . '</h4>' . "\n"
        . '<p style="margin: 0; text-align: justify;">' . $p . '</p>' . "\n" . '</div>' . "\n";
};
$revisa = '<div><!-- CHECKLIST --><h3>Revisa los criterios antes de enviar tu ficha</h3><div style="display: flex;">'
    . $tarjeta('1. Aprendizaje esperado', 'Texto uno.') . $tarjeta('2. Unidad de competencia', 'Texto <strong>dos</strong>.')
    . '</div><!-- DOCUMENTACIÓN --><div style="flex: 1 1 260px; x">fuera</div></div>';
$estilo = modulo2_setup::style_review_cards($revisa);
local_chatbot_verificar(substr_count($estilo, 'border-radius: 50%') === 2 && strpos($estilo, 'background: #e8f4fc') !== false
    && strpos($estilo, 'background: #edf6ed') !== false && strpos($estilo, 'font-size: 17px') !== false,
    'las tarjetas de «Revisa los criterios» toman el formato de la sección de diagnóstico, con un color por tarjeta');
local_chatbot_verificar(strpos($estilo, '>1. Aprendizaje esperado</h4>') !== false && strpos($estilo, '>Texto <strong>dos</strong>.</p>') !== false
    && strpos($estilo, '<div style="flex: 1 1 260px; x">fuera</div>') !== false,
    'títulos y textos quedan como estaban, y lo que está fuera del bloque no se toca');
local_chatbot_verificar(modulo2_setup::style_review_cards($estilo) === $estilo
    && modulo2_setup::style_review_cards('<div>sin bloque</div>') === '<div>sin bloque</div>',
    'el formato de las tarjetas es idempotente y sin el bloque no cambia nada');
$res = '<div><div style="flex: 1 1 230px; padding: 18px;"><div style="display: inline-block; background: #267a43;">APROBADO</div>'
    . '<h4>Puedes avanzar</h4></div><div style="flex: 1; text-align: center;"><div style="display: inline-block; x">'
    . 'NO APROBADO</div></div><!-- REEMPLAZA # POR LA URL REAL DEL ARCHIVO EN MOODLE --> <a style="y" href="#">'
    . ' Consultar y descargar el checklist </a><p style="z">Nota: reemplaza el enlace provisional por la URL.</p></div>';
$res2 = modulo2_setup::add_form_button(modulo2_setup::link_checklist(modulo2_setup::center_conditions($res), 'uc'), 'uf');
local_chatbot_verificar(strpos($res2, 'padding: 18px; text-align: center;">') !== false, 'la condición enmarcada queda centrada');
local_chatbot_verificar(substr_count($res2, 'text-align: center;') === 3, 'un recuadro ya centrado no se toca');
local_chatbot_verificar(strpos($res2, 'href="uc"') !== false && strpos($res2, 'href="#"') === false
    && strpos($res2, 'REEMPLAZA') === false && strpos($res2, 'Nota: reemplaza') === false,
    'el checklist enlaza a su recurso y se van las notas provisionales');
local_chatbot_verificar(strpos($res2, 'href="uf"') !== false && substr($res2, -6) === '</div>'
    && strpos($res2, modulo2_setup::BOTON_FICHA) !== false, 'el botón a la ficha queda al final, dentro del contenedor');
local_chatbot_verificar(modulo2_setup::add_form_button(modulo2_setup::link_checklist(modulo2_setup::center_conditions($res2),
    'uc'), 'uf') === $res2, 'los ajustes de la sección del formulario son idempotentes');

// --- Recursos PDF: sin el recuadro «PDF» y nombre de archivo sin tildes ---
$opc = modulo2_setup::display_options(serialize(['popupwidth' => 620, 'showtype' => 1]));
local_chatbot_verificar($opc !== null && unserialize($opc) === ['popupwidth' => 620, 'showtype' => 0],
    'se apaga «mostrar tipo» y se conserva lo demás');
local_chatbot_verificar(modulo2_setup::display_options(serialize(['showtype' => 0])) === null
    && modulo2_setup::display_options('basura') === null, 'sin «mostrar tipo» o sin opciones válidas no se toca');
local_chatbot_verificar(modulo2_setup::ascii_filename('Checklist Ficha Diagnóstico, Evaluación Ex - Ante.pdf')
    === 'Checklist Ficha Diagnostico, Evaluacion Ex - Ante.pdf', 'el nombre del archivo queda sin tildes');
local_chatbot_verificar(modulo2_setup::ascii_filename('Rúbrica Validación Técnica Diseño.pdf') === 'Rubrica Validacion Tecnica Diseno.pdf',
    'también sin eñes');
local_chatbot_verificar(modulo2_setup::ascii_filename('Guia.pdf') === 'Guia.pdf', 'un nombre ASCII no cambia');

// --- Checklist aplicado en Moodle (autorrevision) ---
$marcas = exante::self_check_clean(['c1_1' => 'cumple', 'c1_2' => 'parcial', 'c2_1' => 'no_cumple', 'c9_9' => 'cumple',
    'c3_1' => 'excelente']);
local_chatbot_verificar($marcas === ['c1_1' => 'cumple', 'c1_2' => 'parcial', 'c2_1' => 'no_cumple'],
    'la autorrevisión guarda solo ítems y valores válidos');
$res = exante::self_check_summary($marcas);
local_chatbot_verificar($res['cumple'] === 1 && $res['parcial'] === 1 && $res['no_cumple'] === 1 && $res['sin_marcar'] === 30
    && $res['condicion'] === null && $res['criticos'] === ['c2_1'], 'con ítems sin marcar no hay condición y se avisa el crítico');
$todas = array_fill_keys(exante::checklist_ids(), exante::CUMPLE);
local_chatbot_verificar(exante::self_check_summary($todas)['condicion'] === exante::APROBADO, 'todo cumple: aprobado');
$todas['c6_1'] = exante::PARCIAL;
local_chatbot_verificar(exante::self_check_summary($todas)['condicion'] === exante::OBSERVACIONES,
    'algo parcial en un ítem no crítico: con observaciones');
$todas['c1_3'] = exante::PARCIAL;
local_chatbot_verificar(exante::self_check_summary($todas)['condicion'] === exante::NO_APROBADO
    && exante::self_check_summary($todas)['criticos'] === ['c1_3'],
    'un crítico parcial: no aprobado, y se avisa');
$todas['c1_3'] = exante::CUMPLE;
$todas['c9_2'] = exante::NO_CUMPLE;
local_chatbot_verificar(exante::self_check_summary($todas, exante::REGLA_MOTIVOS)['condicion'] === exante::OBSERVACIONES
    && exante::self_check_summary($todas)['condicion'] === exante::NO_APROBADO
    && exante::self_check_summary($todas)['criticos'] === ['c9_2'],
    'un crítico que no cumple: no aprobado con la regla del checklist; con observaciones con la regla «motivos»');
$enlazado = modulo2_setup::link_checklist('<a style="y" href="http://x/local/chatbot/checklist_exante.php?courseid=4"> '
    . 'Consultar y descargar el checklist </a>', 'http://x/mod/resource/view.php?id=606');
local_chatbot_verificar(strpos($enlazado, 'href="http://x/mod/resource/view.php?id=606"') !== false
    && strpos($enlazado, 'checklist_exante.php') === false && strpos($enlazado, 'style="y"') !== false,
    'el botón del checklist cambia de un enlace anterior (el checklist en Moodle) al recurso del checklist');

// --- Rol del Modulo 2 y fuentes de conocimiento (pedido del cliente del 30-10-2026) ---
local_chatbot_verificar(exante::ROL === 'Asistente de Diseño Instruccional', 'el rol del Módulo 2 es «Asistente de Diseño Instruccional»');
$reglas = implode("\n", exante::knowledge_rules(true, true));
local_chatbot_verificar(strpos($reglas, 'usa preferentemente la LIBRERÍA DE CONOCIMIENTOS DEL ASISTENTE DE DISEÑO INSTRUCCIONAL') !== false
    && strpos($reglas, 'enfoque de competencias') !== false && strpos($reglas, 'busca información actualizada en la web') !== false
    && strpos($reglas, 'especialidad técnica') !== false && strpos($reglas, 'No inventes datos técnicos') !== false,
    'regla: la librería para el enfoque de competencias y la web para la especialidad técnica');
$sinnada = implode("\n", exante::knowledge_rules(false, false));
local_chatbot_verificar(strpos($sinnada, 'no viene cargada') !== false && strpos($sinnada, 'busca información') === false
    && strpos($sinnada, 'debe verificar') !== false, 'sin librería ni búsqueda, la regla lo dice y pide verificar lo técnico');
local_chatbot_verificar(exante::library_parts('') === [] && exante::library_parts('  ') === []
    && strpos(exante::library_parts('Texto de la guía')[0]['text'], 'LIBRERÍA DE CONOCIMIENTOS DEL ASISTENTE DE DISEÑO INSTRUCCIONAL') === 0,
    'la librería va como parte del pedido solo si hay documentos');
$pv = exante::build_prompt([], true, true);
local_chatbot_verificar(strpos($pv, 'Eres el Asistente de Diseño Instruccional') === 0 && strpos($pv, 'FUENTES DE CONOCIMIENTO:') !== false
    && strpos(exante::build_prompt([]), 'no viene cargada') !== false, 'la validación habla como el Asistente de Diseño Instruccional, con la regla de fuentes');
printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Ficha ex-ante del Módulo 2 conforme: ficha y checklist textuales, control de entrada y regla de decisión.\n";
}
exit($fallidos === 0 ? 0 : 1);
