<?php
/**
 * Verifica el objeto de datos del Coach para una meta (RU-COA-DATA-002 y
 * RU-COA-001, momento b) contra el contrato del Anexo F §4.
 *
 * El objeto va al modelo en cada intervencion sobre una meta, asi que aqui se
 * comprueba lo que importa del contrato: que esten todas las claves del
 * documento, que ninguna lleve identidad directa (nombre, correo, RUT) ni
 * respuestas crudas, y que un objeto que no cumple se detecte antes de viajar.
 *
 * Uso:  php cli/verificar_coach_meta.php
 * Sale con codigo 1 ante cualquier caso fallido, para encadenarlo a CI.
 */

define('MOODLE_INTERNAL', true);

require_once(__DIR__ . '/../classes/pseudonymizer.php');
require_once(__DIR__ . '/../classes/coach_goal.php');

use local_chatbot\coach_goal;

$fallidos = 0;
$total = 0;

/**
 * @param bool $condicion
 * @param string $descripcion
 * @return void
 */
function local_chatbot_verificar(bool $condicion, string $descripcion): void {
    global $fallidos, $total;
    $total++;
    if (!$condicion) {
        echo "  FALLA  {$descripcion}\n";
        $fallidos++;
    }
}

/** @return array Entrada de una meta como la deja el recolector. */
function local_chatbot_entrada(): array {
    return [
        'pseudoid' => 'FAC-9f3a1c22',
        'pdp' => ['id' => 10, 'version' => 2],
        'goal' => [
            'id' => 19, 'version' => '2', 'title' => 'Diseñar una actividad de práctica contextualizada',
            'dimension' => 'D1', 'subdimension' => 'D1', 'orientation_level' => 'En desarrollo',
            'priority_source' => null, 'source_evidence_summary' => 'D1 | puntaje compuesto 2.83, completitud 100%',
            'statement' => 'Diseñar una actividad de práctica contextualizada', 'status' => 'en_desarrollo',
            'stored_status' => 'aceptado', 'timeframe' => '30/10/2026',
        ],
        'context' => ['rol' => 'facilitador', 'tipo_programa' => 'capacitación laboral', 'modalidad' => 'online',
            'sector_especialidad' => 'comercio', 'participantes' => null, 'tiempo_disponible' => 'menos de 1 hora',
            'restricciones' => null],
        'fixed_resources' => ['U1-LF-01', 'U1-CA-01', 'U1-ER-01', 'U1-QF-01'],
        'progress' => ['consulted' => ['U1-LF-01'], 'quiz_summary' => 'Ultimo intento enviado: sin reglas activadas.',
            'previous_evidence' => ['Propone usar una rúbrica como evidencia.']],
        'privacy' => ['consent_version' => 'a1b2c3d4e5f6', 'retention_version' => 'RET-12m',
            'permissions' => ['profile:contract', 'gaps:contract', 'pdp']],
        'goal_state' => ['activity' => 'Iniciada: elabora una propuesta breve', 'evidence' => 'Propone usar una rúbrica.',
            'feedback' => 'La rúbrica es el instrumento, no la evidencia.', 'next_action' => 'Describe el caso.',
            'decisions' => ['aceptar']],
        'plugin' => ['origin' => 'ia', 'unit' => 'Unidad 1', 'resource' => 'U1-LF-01', 'activity' => null,
            'actions' => 'Revisar el apartado 4.', 'committed_evidence' => 'Ficha de la actividad'],
    ];
}

// --- Un objeto completo cumple el contrato ---
$objeto = coach_goal::assemble(local_chatbot_entrada());
$problemas = coach_goal::violations($objeto);
local_chatbot_verificar($problemas === [], 'un objeto completo cumple el contrato: ' . implode('; ', $problemas));

foreach (coach_goal::DOC_KEYS as $clave) {
    local_chatbot_verificar(array_key_exists($clave, $objeto), "el objeto trae la clave del documento '{$clave}'");
}
foreach (coach_goal::GOAL_KEYS as $clave) {
    local_chatbot_verificar(array_key_exists($clave, $objeto['pdp_goal']), "pdp_goal trae '{$clave}'");
}
foreach (coach_goal::CONTEXT_KEYS as $clave) {
    local_chatbot_verificar(array_key_exists($clave, $objeto['contexto_autorizado']),
        "contexto_autorizado trae '{$clave}'");
}
foreach (coach_goal::PROGRESS_KEYS as $clave) {
    local_chatbot_verificar(array_key_exists($clave, $objeto['progreso']), "progreso trae '{$clave}'");
}
foreach (coach_goal::PRIVACY_KEYS as $clave) {
    local_chatbot_verificar(array_key_exists($clave, $objeto['privacy']), "privacy trae '{$clave}'");
}

// --- Los valores llegan donde el documento los pone ---
local_chatbot_verificar($objeto['user_id_pseudonimizado'] === 'FAC-9f3a1c22', 'viaja el seudónimo');
local_chatbot_verificar($objeto['pdp_id'] === 10 && $objeto['pdp_goal']['pdp_goal_id'] === 19,
    'ids técnicos del PDP y de la meta');
local_chatbot_verificar($objeto['pdp_goal']['goal_status'] === 'en_desarrollo', 'estado de la meta en el vocabulario v5');
local_chatbot_verificar($objeto['recursos_fijos'] === ['U1-LF-01', 'U1-CA-01', 'U1-ER-01', 'U1-QF-01'],
    'recursos fijos del Anexo G');
local_chatbot_verificar($objeto['progreso']['recursos_consultados'] === ['U1-LF-01'], 'recursos consultados');
local_chatbot_verificar($objeto['privacy']['consent_version'] === 'a1b2c3d4e5f6'
    && $objeto['privacy']['retention_policy_version'] === 'RET-12m', 'versiones de consentimiento y retención');
local_chatbot_verificar($objeto['estado_meta']['siguiente_accion'] === 'Describe el caso.',
    'los campos mínimos de estado de meta que el ejemplo del documento no muestra');
local_chatbot_verificar($objeto['pdp_goal']['priority_source'] === null,
    'la prioridad de origen no se inventa: va en null');

// --- learner_decision usa el vocabulario del documento ---
$decisiones = ['aceptado' => 'aceptada', 'completado' => 'aceptada', 'modificado' => 'editada',
    'postergado' => 'postergada', 'rechazado' => 'rechazada', 'propuesto' => 'pendiente', '' => 'pendiente',
    'otro_estado' => 'pendiente'];
foreach ($decisiones as $guardado => $esperada) {
    local_chatbot_verificar(coach_goal::learner_decision($guardado) === $esperada,
        "estado guardado '{$guardado}' se traduce a '{$esperada}'");
}

// --- Privacidad: ni identidad directa ni datos personales en los textos ---
$sucia = local_chatbot_entrada();
$sucia['goal']['title'] = 'Escribir a maria.perez@empresa.cl sobre la actividad';
$sucia['goal_state']['evidence'] = 'Hice la simulación con el cliente Juan Pérez, RUT 12.345.678-5';
$sucia['plugin']['actions'] = 'Llamar al +56 9 8765 4321';
$limpio = coach_goal::assemble($sucia);
$json = json_encode($limpio, JSON_UNESCAPED_UNICODE);
local_chatbot_verificar(strpos($json, 'maria.perez@empresa.cl') === false, 'un correo escrito en la meta se omite');
local_chatbot_verificar(strpos($json, '12.345.678-5') === false, 'un RUT escrito en la evidencia se omite');
local_chatbot_verificar(strpos($json, '8765 4321') === false, 'un teléfono escrito en las acciones se omite');
local_chatbot_verificar(coach_goal::violations($limpio) === [], 'tras el barrido el objeto vuelve a cumplir el contrato');

// --- Un objeto que no cumple se detecta antes de viajar ---
$roto = $objeto;
$roto['nombre'] = 'María Pérez';
local_chatbot_verificar(in_array("campo de identidad 'nombre'", coach_goal::violations($roto), true),
    'un campo de identidad en el primer nivel se detecta');

$roto = $objeto;
$roto['contexto_autorizado']['email'] = 'x@y.cl';
local_chatbot_verificar(count(array_filter(coach_goal::violations($roto), fn($p) => strpos($p, 'identidad') !== false)) >= 1,
    'un campo de identidad anidado se detecta');

$roto = $objeto;
unset($roto['privacy']);
local_chatbot_verificar(in_array("falta la clave 'privacy'", coach_goal::violations($roto), true),
    'falta una clave del documento');

$roto = $objeto;
unset($roto['pdp_goal']['learner_decision']);
local_chatbot_verificar(count(coach_goal::violations($roto)) >= 1, 'falta una clave anidada del documento');

$roto = $objeto;
$roto['pdp_goal']['title'] = 'Escribir a alguien@dominio.cl';
local_chatbot_verificar(count(array_filter(coach_goal::violations($roto), fn($p) => strpos($p, 'correo') !== false)) === 1,
    'un correo que llegó a un texto se detecta');

$roto = $objeto;
$roto['progreso']['previous_evidence_summary'] = ['RUT 12.345.678-5'];
local_chatbot_verificar(count(array_filter(coach_goal::violations($roto), fn($p) => strpos($p, 'RUT') !== false)) === 1,
    'un RUT que llegó a un texto se detecta');

$roto = $objeto;
$roto['user_id_pseudonimizado'] = 'maria.perez';
local_chatbot_verificar(count(array_filter(coach_goal::violations($roto), fn($p) => strpos($p, 'seudonimo') !== false)) === 1,
    'un identificador que no es un seudónimo se detecta');

$roto = $objeto;
$roto['pdp_goal']['learner_decision'] = 'quizas';
local_chatbot_verificar(count(coach_goal::violations($roto)) >= 1, 'una decisión fuera del vocabulario se detecta');

// --- El resumen del quiz ---
local_chatbot_verificar(strpos(coach_goal::quiz_summary([]), 'no se activo ninguna regla') !== false,
    'sin reglas activadas el resumen lo dice');
$resumen = coach_goal::quiz_summary([['activada_por' => ['P05', 'P08'], 'foco' => 'D1.1']]);
local_chatbot_verificar(strpos($resumen, 'P05, P08') !== false && strpos($resumen, 'D1.1') !== false
    && strpos($resumen, 'no calificacion') !== false, 'el resumen nombra las preguntas y el foco, y no es una calificación');

// --- Textos largos y vacíos ---
$largo = local_chatbot_entrada();
$largo['goal']['title'] = str_repeat('a', 900);
$largo['goal']['orientation_level'] = '   ';
$acotado = coach_goal::assemble($largo);
local_chatbot_verificar(mb_strlen($acotado['pdp_goal']['title']) <= coach_goal::TEXT_LIMIT,
    'un texto largo se acota');
local_chatbot_verificar($acotado['pdp_goal']['orientation_level'] === null, 'un texto en blanco se vuelve null');

// --- El objeto es JSON válido y no arrastra nada fuera del contrato conocido ---
$decodificado = json_decode(json_encode($objeto, JSON_UNESCAPED_UNICODE), true);
local_chatbot_verificar($decodificado === $objeto, 'el objeto sobrevive a un ida y vuelta por JSON');
$extras = array_diff(array_keys($objeto), array_merge(coach_goal::DOC_KEYS, ['estado_meta', 'detalle_plugin']));
local_chatbot_verificar($extras === [], 'las únicas claves fuera del documento son estado_meta y detalle_plugin');

printf("Casos verificados: %d | Fallidos: %d\n", $total, $fallidos);

if ($fallidos === 0) {
    echo "Objeto del Coach por meta conforme al Anexo F §4 (RU-COA-DATA-002).\n";
    exit(0);
}

echo "Objeto del Coach NO conforme: revisar classes/coach_goal.php antes de desplegar.\n";
exit(1);
