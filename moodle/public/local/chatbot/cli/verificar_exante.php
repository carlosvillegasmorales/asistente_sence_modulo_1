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
local_chatbot_verificar(array_keys($secciones) === range(1, 9), 'la ficha tiene las 9 secciones en orden');
$cerradas = [];
foreach ($secciones as $seccion) {
    foreach ($seccion['campos'] as $campo) {
        if (in_array($campo['tipo'], [exante::T_UNA, exante::T_VARIAS], true)) {
            $cerradas[$campo['num']] = count($campo['opciones']);
        }
    }
}
// La guia de llenado: «9 secciones con 24 preguntas cerradas».
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
        'riesgo_otro', 'val_revision_fecha', 'val_aprobacion_responsable', 'obs_finales'] as $clave) {
    local_chatbot_verificar(isset($claves[$clave]), "la ficha guarda «{$clave}»");
}
local_chatbot_verificar(!isset($claves['val']), 'la tabla de validación se guarda por celdas, no como un campo');

// --- El checklist es el del documento del cliente ---
$checklist = exante::checklist();
local_chatbot_verificar(array_keys($checklist) === range(1, 9), 'el checklist tiene las 9 secciones');
local_chatbot_verificar(array_map(static function($s) {
    return count($s['items']);
}, array_values($checklist)) === [2, 5, 4, 4, 3, 4, 4, 3, 3], 'cada sección del checklist tiene sus ítems');
local_chatbot_verificar(count(exante::checklist_ids()) === 32, 'el checklist tiene 32 ítems');
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
local_chatbot_verificar(array_keys(exante::clean([])) === array_keys($claves), 'la ficha vacía tiene todas las claves');

// --- Ejemplo de llenado del cliente (competencia «Comunicación efectiva en contextos laborales») ---
$ejemplo = exante::clean([
    'organismo' => 'OTEC Formación Integral Ltda.', 'plan' => 'Módulo "Comunicación para el trabajo"',
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
    'ajustes' => 'no', 'riesgo' => 'recursos', 'val_revision_fecha' => '20/03/2026',
    'val_revision_responsable' => 'Coordinador técnico',
]);
local_chatbot_verificar(exante::missing_inputs($ejemplo) === [], 'el ejemplo del cliente pasa el control de entrada');
local_chatbot_verificar(exante::criteria_count($ejemplo) === 4, 'el ejemplo registra 4 criterios');

// --- Control de entrada del Prompt Maestro ---
$falta = exante::missing_inputs(exante::clean([]));
local_chatbot_verificar(count($falta) === 10, 'la ficha vacía pide aprendizaje, criterios, sección 4 y sección 5');
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
local_chatbot_verificar($criterios([1]) === ['2.1 Criterios de evaluación (mínimo 3; la ficha tiene 1)'],
    'con 1 criterio no se valida y se dice cuántos tiene');
local_chatbot_verificar($criterios([1, 2]) === ['2.1 Criterios de evaluación (mínimo 3; la ficha tiene 2)'],
    'con 2 criterios no se valida');
local_chatbot_verificar($criterios([2, 4, 5]) === [], 'con 3 criterios en cualquier casilla se valida');
local_chatbot_verificar($criterios([1, 2, 3, 4, 5]) === [], 'con 5 criterios se valida');
local_chatbot_verificar(count(exante::field_keys()) === count(array_unique(array_keys(exante::field_keys())))
    && !isset(exante::field_keys()['criterio6']), 'la ficha no admite más de 5 criterios');
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
local_chatbot_verificar(strpos($texto, '- Validación con equipo de facilitadores del Plan Formativo: fecha (sin respuesta)') !== false,
    'lo vacío se dice explícitamente');
local_chatbot_verificar(strpos($texto, '- Revisión del diagnóstico: fecha 20/03/2026; responsable Coordinador técnico') !== false,
    'la tabla de validación viaja por filas');
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
local_chatbot_verificar(strpos($prompt, 'no adjuntó el Plan Formativo ni el Perfil Ocupacional') !== false,
    'sin adjuntos, el prompt lo dice');
$prompt1 = exante::build_prompt(['Plan Formativo']);
local_chatbot_verificar(strpos($prompt1, 'adjuntó: Plan Formativo') !== false
    && strpos($prompt1, 'No adjuntó: Perfil Ocupacional') !== false, 'con un adjunto, dice cuál falta');
local_chatbot_verificar(strpos($prompt, 'no menos de 3 ni más de 5 criterios') !== false, 'regla de 3 a 5 criterios');
local_chatbot_verificar(strpos($prompt, '"adecuadamente"') !== false, 'regla de juicios de valor');
local_chatbot_verificar(strpos($prompt, 'lenguaje inclusivo') !== false, 'regla de lenguaje inclusivo');

// --- Lectura del informe del modelo ---
$completo = ['items' => [], 'condicion' => 'aprobado', 'debilidades' => ['d1'], 'sugerencias' => ['s1', '', 's2']];
foreach (exante::checklist_ids() as $id) {
    $completo['items'][$id] = ['estado' => 'cumple', 'observacion' => 'ok ' . $id];
}
$json = json_encode($completo, JSON_UNESCAPED_UNICODE);
$leido = exante::parse_report($json);
local_chatbot_verificar($leido !== null && count($leido['items']) === 32, 'un informe completo se lee');
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
local_chatbot_verificar(exante::decide($todo, exante::APROBADO) === exante::APROBADO, 'todo cumple y el modelo aprueba: aprobado');
local_chatbot_verificar(exante::decide($todo, null) === exante::APROBADO, 'todo cumple sin condición del modelo: aprobado');
local_chatbot_verificar(exante::decide($todo, exante::OBSERVACIONES) === exante::OBSERVACIONES,
    'el modelo puede ser más exigente que la regla');
local_chatbot_verificar(exante::decide($todo, exante::NO_APROBADO) === exante::NO_APROBADO, 'el modelo puede no aprobar');
local_chatbot_verificar(exante::decide($con(['c6_1' => exante::PARCIAL]), exante::APROBADO) === exante::OBSERVACIONES,
    'con un ítem parcial no se aprueba sin observaciones');
local_chatbot_verificar(exante::decide($con(['c6_1' => exante::NO_CUMPLE]), exante::APROBADO) === exante::OBSERVACIONES,
    'un incumplimiento no crítico deja observaciones');
foreach (exante::CRITICOS as $id) {
    local_chatbot_verificar(exante::decide($con([$id => exante::NO_CUMPLE]), exante::APROBADO) === exante::NO_APROBADO,
        "incumplir {$id} (crítico) no se aprueba aunque el modelo apruebe");
}
local_chatbot_verificar(exante::decide($con(['c2_1' => exante::PARCIAL]), exante::APROBADO) === exante::OBSERVACIONES,
    'un crítico parcial deja observaciones');
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

// --- Checklist aplicado en Moodle (autorrevision) ---
$marcas = exante::self_check_clean(['c1_1' => 'cumple', 'c1_2' => 'parcial', 'c2_1' => 'no_cumple', 'c9_9' => 'cumple',
    'c3_1' => 'excelente']);
local_chatbot_verificar($marcas === ['c1_1' => 'cumple', 'c1_2' => 'parcial', 'c2_1' => 'no_cumple'],
    'la autorrevisión guarda solo ítems y valores válidos');
$res = exante::self_check_summary($marcas);
local_chatbot_verificar($res['cumple'] === 1 && $res['parcial'] === 1 && $res['no_cumple'] === 1 && $res['sin_marcar'] === 29
    && $res['condicion'] === null && $res['criticos'] === ['c2_1'], 'con ítems sin marcar no hay condición y se avisa el crítico');
$todas = array_fill_keys(exante::checklist_ids(), exante::CUMPLE);
local_chatbot_verificar(exante::self_check_summary($todas)['condicion'] === exante::APROBADO, 'todo cumple: aprobado');
$todas['c6_1'] = exante::PARCIAL;
local_chatbot_verificar(exante::self_check_summary($todas)['condicion'] === exante::OBSERVACIONES, 'algo parcial: con observaciones');
$todas['c9_2'] = exante::NO_CUMPLE;
local_chatbot_verificar(exante::self_check_summary($todas)['condicion'] === exante::NO_APROBADO,
    'un crítico que no cumple: no aprobado, con la misma regla del asistente');
$enlazado = modulo2_setup::link_checklist('<a style="y" href="http://x/mod/resource/view.php?id=606"> Consultar y descargar el checklist </a>', 'uc2');
local_chatbot_verificar(strpos($enlazado, 'href="uc2"') !== false && strpos($enlazado, 'id=606') === false,
    'el botón del checklist cambia de un enlace anterior al checklist en Moodle');

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);
if ($fallidos === 0) {
    echo "Ficha ex-ante del Módulo 2 conforme: ficha y checklist textuales, control de entrada y regla de decisión.\n";
}
exit($fallidos === 0 ? 0 : 1);
