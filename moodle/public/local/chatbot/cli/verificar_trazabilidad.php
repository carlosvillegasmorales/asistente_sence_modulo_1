<?php
/**
 * Verifica las reglas de trazabilidad del Anexo G que no dependen del modelo:
 *
 *   §2.1  codigos de objeto (IA-T-U1-000001, EV-U1-..., PDP-UPD-...) y de unidad
 *   §5.1  reglas de activacion desde los patrones del quiz
 *   §11.4 conteo de recursos inventados por el modelo
 *   E §11 etiquetas de concepto, fuentes citadas e intencion de la consulta al Tutor
 *
 * Uso:  php cli/verificar_trazabilidad.php
 * Sale con codigo 1 ante cualquier caso fallido, para encadenarlo a CI.
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/trace.php');
require_once(__DIR__ . '/../classes/quiz_patterns.php');
require_once(__DIR__ . '/../classes/coach_context.php');
require_once(__DIR__ . '/../classes/coach_report.php');

use local_chatbot\trace;
use local_chatbot\quiz_patterns;
use local_chatbot\coach_report;

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

// --- §2.1 codigos ---
local_chatbot_verificar(trace::code('IA-T', 'U1', 1) === 'IA-T-U1-000001', 'codigo: interaccion Tutor');
local_chatbot_verificar(trace::code('EV', 'U1', 45) === 'EV-U1-000045', 'codigo: evidencia conversacional');
local_chatbot_verificar(trace::code('VI', 'U2', 7) === 'VI-U2-000007', 'codigo: verificacion IA');
local_chatbot_verificar(trace::code('PDP-UPD', '', 12) === 'PDP-UPD-000012', 'codigo: actualizacion PDP sin unidad');
local_chatbot_verificar(trace::unit_from_hint('U1-CA-01') === 'U1', 'unidad: desde idnumber de recurso');
local_chatbot_verificar(trace::unit_from_hint('Unidad 3') === 'U3', 'unidad: desde "Unidad 3"');
local_chatbot_verificar(trace::unit_from_hint('page:415') === 'UX', 'unidad: sin indicio queda UX');

// --- Anexo G §4: recorrido por los recursos fijos ---
foreach (['U1-LF-01' => 'LF', 'U1-CA-01' => 'CA', 'U1-ER-01' => 'ER', 'U1-QF-01' => 'QF',
        'U4-LF-02' => 'LF'] as $idnumber => $esperado) {
    local_chatbot_verificar(trace::resource_kind($idnumber) === $esperado,
        "recurso: {$idnumber} se reconoce como {$esperado}");
}
foreach (['', 'page:415', 'quiz-unidad-1', 'U1-XX-01'] as $ajeno) {
    local_chatbot_verificar(trace::resource_kind($ajeno) === '',
        'recurso: "' . $ajeno . '" no es un recurso fijo del Anexo G');
}
// Los diez codigos de recorrido del Anexo G, tal como los nombra el documento.
$esperados = [
    'EVT-U1-LF-VIEW' => [trace::RES_VIEW, 'LF'], 'EVT-U1-LF-COMPLETE' => [trace::RES_COMPLETE, 'LF'],
    'EVT-U1-CA-VIEW' => [trace::RES_VIEW, 'CA'], 'EVT-U1-CA-COMPLETE' => [trace::RES_COMPLETE, 'CA'],
    'EVT-U1-ER-VIEW' => [trace::RES_VIEW, 'ER'], 'EVT-U1-ER-COMPLETE' => [trace::RES_COMPLETE, 'ER'],
];
foreach ($esperados as $codigo => [$plantilla, $kind]) {
    local_chatbot_verificar(
        str_replace('{U}', 'U1', trace::resource_event($plantilla, $kind)) === $codigo,
        "codigo del Anexo G: {$codigo}");
}
foreach (['EVT-U1-QF-START' => trace::QUIZ_STARTED, 'EVT-U1-QF-SUBMIT' => trace::QUIZ_SUBMITTED,
        'EVT-U1-QF-REVIEW' => trace::QUIZ_REVIEWED, 'EVT-U1-QF-COMPLETE' => trace::QUIZ_COMPLETED] as $codigo => $plantilla) {
    local_chatbot_verificar(str_replace('{U}', 'U1', $plantilla) === $codigo, "codigo del Anexo G: {$codigo}");
}
// El catalogo del Anexo G no tiene QF-VIEW: abrir el quiz no es un evento propio.
$catalogo = ['EVT-U1-LF-VIEW', 'EVT-U1-LF-COMPLETE', 'EVT-U1-CA-VIEW', 'EVT-U1-CA-COMPLETE',
    'EVT-U1-ER-VIEW', 'EVT-U1-ER-COMPLETE', 'EVT-U1-QF-START', 'EVT-U1-QF-SUBMIT',
    'EVT-U1-QF-REVIEW', 'EVT-U1-QF-COMPLETE'];
local_chatbot_verificar(!in_array('EVT-U1-QF-VIEW', $catalogo, true)
    && str_replace('{U}', 'U1', trace::resource_event(trace::RES_VIEW, 'QF')) === 'EVT-U1-QF-VIEW',
    'el codigo QF-VIEW existiria como plantilla, y por eso el observador lo descarta');
$fuente = file_get_contents(__DIR__ . '/../classes/observer.php');
local_chatbot_verificar(strpos($fuente, "\$kind === 'QF' && \$plantilla === trace::RES_VIEW") !== false,
    'el observador no emite QF-VIEW, que no esta en el catalogo del Anexo G');

// Los observadores tienen que estar registrados, o los codigos no se emiten nunca.
$registrados = array_column(
    (function() {
        $observers = [];
        include(__DIR__ . '/../db/events.php');
        return $observers;
    })(), 'eventname');
foreach (['\core\event\course_module_viewed', '\core\event\course_module_completion_updated',
        '\mod_quiz\event\attempt_started', '\mod_quiz\event\attempt_reviewed',
        '\mod_quiz\event\attempt_graded'] as $evento) {
    local_chatbot_verificar(in_array($evento, $registrados, true), "observador registrado: {$evento}");
}

// --- Anexo E §11: registro del Tutor ---
$tags = trace::concept_tags('¿Qué diferencia hay entre evidencia e instrumento en un aprendizaje esperado?');
local_chatbot_verificar(in_array('evidencia', $tags, true) && in_array('instrumento', $tags, true)
    && in_array('aprendizaje_esperado', $tags, true), 'conceptos: evidencia, instrumento y aprendizaje esperado');
local_chatbot_verificar(trace::concept_tags('Hola, ¿cómo estás?') === [], 'conceptos: saludo sin etiquetas');
$fuentes = trace::sources_referred('Revisa el Documento de Lectura (U1-LF-01, apartado 3.5) y el caso U1-CA-01.');
local_chatbot_verificar(in_array('U1-LF-01-seccion-3.5', $fuentes, true), 'fuentes: codigo con apartado');
local_chatbot_verificar(in_array('U1-CA-01', $fuentes, true), 'fuentes: codigo de recurso');
local_chatbot_verificar(in_array('U?-ER', trace::sources_referred('Mira el Ejemplo de Resolución comentado.'), true),
    'fuentes: recurso citado por su nombre sin codigo ni unidad');
// Forma en que cita el Tutor con el prompt del Anexo E (re-prueba v1.6.0).
$citas = trace::sources_referred('revisa los apartados 3.5, 3.6 y 3.7 del Documento de Lectura de la Unidad 1, y el'
    . ' análisis de condiciones en el apartado 2.3 del **Ejemplo de Resolución Comentado** de la Unidad 1.');
local_chatbot_verificar(in_array('U1-LF-01-seccion-3.5', $citas, true) && in_array('U1-LF-01-seccion-3.7', $citas, true),
    'fuentes: apartados citados por nombre se convierten a U1-LF-01-seccion-N');
local_chatbot_verificar(in_array('U1-ER-01-seccion-2.3', $citas, true),
    'fuentes: el Ejemplo de Resolución con apartado y unidad');
local_chatbot_verificar(in_array('U1-CA-01', trace::sources_referred('Observa el Caso de Análisis de la Unidad 1.'), true),
    'fuentes: recurso por nombre con unidad y sin apartado');
local_chatbot_verificar(trace::tutor_intent('Dime la respuesta correcta de la pregunta 4 del quiz') === 'apoyo_quiz',
    'intencion: apoyo_quiz');
local_chatbot_verificar(trace::tutor_intent('Mi curso es de turismo, ¿puedo usar el caso?') === 'transferencia_sectorial',
    'intencion: transferencia_sectorial');
local_chatbot_verificar(trace::tutor_intent('Necesito mejorar mi meta de diseño') === 'orientacion_PDP',
    'intencion: orientacion_PDP');
local_chatbot_verificar(trace::tutor_intent('¿Qué es una actividad situada?') === 'analisis_caso'
    || trace::tutor_intent('¿Qué es una competencia?') === 'aclaracion_conceptual', 'intencion: aclaracion o analisis');
local_chatbot_verificar(trace::refers_to_coach('Ese trabajo se hace con el Coach desde tu PDP.'), 'coach: referencia detectada');

// --- §5.1 reglas de activacion ---
$act = quiz_patterns::activations(['U1-QF-01-P05', 'U1-QF-01-P09']);
local_chatbot_verificar(count($act) === 1 && $act[0]['activada_por'] === ['P05', 'P09'],
    'quiz: P05 y P09 activan la regla evidencia/instrumento');
$act = quiz_patterns::activations(['U1-QF-01-P01']);
local_chatbot_verificar(count($act) === 1 && $act[0]['foco'] === 'D1.1', 'quiz: P01 solo activa D1.1 ("y/o" del Anexo G)');
local_chatbot_verificar(quiz_patterns::activations([]) === [], 'quiz: sin errores no hay reglas');
local_chatbot_verificar(count(quiz_patterns::activations(['P03', 'P08', 'P10'])) === 3, 'quiz: tres reglas distintas');

// --- §11.4 recursos inventados ---
$catalogo = ['Lectura: Formación con enfoque en competencias', 'U1-CA-01 Caso de Análisis Unidad 1'];
$n = coach_report::invented_resources(['ambitos' => [['recurso' => 'lectura: formación con enfoque en competencias'],
    ['recurso' => 'Microcápsula de evaluación']], 'prioridades' => [['recurso' => ''], ['recurso' => 'Video inventado']]],
    $catalogo);
local_chatbot_verificar($n === 2, 'calidad: dos recursos fuera del catalogo (hay ' . $n . ')');

// --- Cierre del Tutor (EVT-IA-T-U1-CLOSE, RU-TRZ-003) ---
local_chatbot_verificar(trace::T_CLOSE === 'EVT-IA-T-{U}-CLOSE', 'el codigo de cierre es el del catalogo del Anexo G');
$cierre = trace::close_data('reglas_quiz, integridad', 'U1-LF-01, U1-CA-01', 'conceptual');
local_chatbot_verificar($cierre['sintesis']['conceptos'] === ['reglas_quiz', 'integridad']
    && $cierre['sintesis']['intencion'] === 'conceptual', 'el cierre resume etiquetas e intencion, no texto');
local_chatbot_verificar($cierre['recurso_recomendado'] === 'U1-LF-01', 'el recurso recomendado es el primero citado');
local_chatbot_verificar(trace::close_data('', '', '')['recurso_recomendado'] === null
    && trace::close_data('', '', '')['sintesis']['conceptos'] === [], 'sin etiquetas ni fuentes el cierre queda vacio, sin inventar');
local_chatbot_verificar(trace::should_log_close(2000, 1000), 'hay consultas tras el ultimo cierre: se registra');
local_chatbot_verificar(!trace::should_log_close(1000, 2000), 'sin consultas nuevas tras el cierre no se duplica');
local_chatbot_verificar(!trace::should_log_close(1000, 1000), 'un cierre en el mismo instante no se duplica');
local_chatbot_verificar(trace::should_log_close(500, 0), 'primer cierre: se registra');
local_chatbot_verificar(!trace::should_log_close(0, 0), 'sin consultas no hay nada que cerrar');

printf("Casos verificados: %d | Fallidos: %d\n", $casos, $fallidos);
if ($fallidos === 0) {
    echo "Trazabilidad conforme al Anexo G (codigos, reglas del quiz, calidad) y al registro del Anexo E §11.\n";
    exit(0);
}
echo "Trazabilidad NO conforme: revisar classes/trace.php y classes/quiz_patterns.php.\n";
exit(1);
