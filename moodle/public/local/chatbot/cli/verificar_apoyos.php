<?php
/**
 * Verifica el motor de apoyos adaptativos (seccion 5 del spec).
 *
 *   RU-ADAPT-001  ningun apoyo crea una actividad de Moodle (propiedad estructural)
 *   RU-ADAPT-004  la revision distingue, propone y pide confirmacion
 *   RU-ADAPT-005  la revision no se presenta como calificacion ni certificacion
 *   RU-ADAPT-006  vocabulario controlado de estados, y solo esos
 *   NFR-SEC-005   rechazo de evidencia con motivo acotado
 *
 * Como en los otros verificadores, lo que se prueba es que las reglas no
 * dependen del modelo: se le entregan revisiones que las violan y se comprueba
 * que lo que queda es conforme.
 *
 * Uso:  php cli/verificar_apoyos.php
 * Sale con codigo 1 ante cualquier caso fallido, para encadenarlo a CI.
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/pdp.php');
require_once(__DIR__ . '/../classes/adaptive.php');
require_once(__DIR__ . '/../classes/coach_context.php');

use local_chatbot\adaptive;

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

// --- RU-ADAPT-006: vocabulario controlado, transcrito del documento ---
$documento = ['propuesta IA', 'actividad iniciada', 'evidencia presentada', 'evidencia revisada por IA',
    'requiere profundización', 'avance registrado', 'meta actualizada', 'propuesta de cierre de meta',
    'meta cerrada'];
local_chatbot_verificar(array_values(adaptive::state_labels_plain()) === $documento,
    'vocabulario: los nueve estados del documento, en su orden y con su redaccion');
local_chatbot_verificar(adaptive::states() === array_keys(adaptive::state_labels_plain()),
    'vocabulario: la tabla de transiciones cubre exactamente los nueve estados');

// --- Transiciones ---
$permitidas = [
    [adaptive::STATE_PROPUESTA_IA, adaptive::STATE_ACTIVIDAD_INICIADA],
    [adaptive::STATE_ACTIVIDAD_INICIADA, adaptive::STATE_EVIDENCIA_PRESENTADA],
    [adaptive::STATE_EVIDENCIA_PRESENTADA, adaptive::STATE_EVIDENCIA_REVISADA_IA],
    [adaptive::STATE_EVIDENCIA_REVISADA_IA, adaptive::STATE_REQUIERE_PROFUNDIZACION],
    [adaptive::STATE_EVIDENCIA_REVISADA_IA, adaptive::STATE_AVANCE_REGISTRADO],
    [adaptive::STATE_EVIDENCIA_REVISADA_IA, adaptive::STATE_PROPUESTA_CIERRE_META],
    [adaptive::STATE_REQUIERE_PROFUNDIZACION, adaptive::STATE_EVIDENCIA_PRESENTADA],
    [adaptive::STATE_AVANCE_REGISTRADO, adaptive::STATE_META_ACTUALIZADA],
    [adaptive::STATE_PROPUESTA_CIERRE_META, adaptive::STATE_META_CERRADA],
    [adaptive::STATE_PROPUESTA_CIERRE_META, adaptive::STATE_REQUIERE_PROFUNDIZACION],
];
foreach ($permitidas as $t) {
    local_chatbot_verificar(adaptive::can_transition($t[0], $t[1]), "transicion permitida {$t[0]} -> {$t[1]}");
}

// Los saltos que harian mentir al registro: cerrar sin revision, revisar sin
// evidencia, cerrar sin que la persona pase por la propuesta, reabrir lo cerrado.
$prohibidas = [
    [adaptive::STATE_PROPUESTA_IA, adaptive::STATE_META_CERRADA],
    [adaptive::STATE_ACTIVIDAD_INICIADA, adaptive::STATE_EVIDENCIA_REVISADA_IA],
    [adaptive::STATE_EVIDENCIA_REVISADA_IA, adaptive::STATE_META_CERRADA],
    [adaptive::STATE_AVANCE_REGISTRADO, adaptive::STATE_META_CERRADA],
    [adaptive::STATE_META_CERRADA, adaptive::STATE_ACTIVIDAD_INICIADA],
    [adaptive::STATE_META_ACTUALIZADA, adaptive::STATE_EVIDENCIA_PRESENTADA],
    [adaptive::STATE_PROPUESTA_IA, 'aprobado'],
];
foreach ($prohibidas as $t) {
    local_chatbot_verificar(!adaptive::can_transition($t[0], $t[1]), "transicion prohibida {$t[0]} -> {$t[1]}");
}

local_chatbot_verificar(adaptive::is_terminal(adaptive::STATE_META_CERRADA)
    && adaptive::is_terminal(adaptive::STATE_META_ACTUALIZADA)
    && !adaptive::is_terminal(adaptive::STATE_AVANCE_REGISTRADO),
    'estados terminales: meta cerrada y meta actualizada, y solo esos');
local_chatbot_verificar(!adaptive::accepts_evidence(adaptive::STATE_PROPUESTA_IA)
    && adaptive::accepts_evidence(adaptive::STATE_ACTIVIDAD_INICIADA)
    && adaptive::accepts_evidence(adaptive::STATE_REQUIERE_PROFUNDIZACION)
    && !adaptive::accepts_evidence(adaptive::STATE_PROPUESTA_CIERRE_META),
    'evidencia: solo se acepta con la actividad iniciada o pidiendo profundizar');

// --- Revision: el modelo propone, el codigo decide ---
$r = adaptive::validate_review(['estado' => 'aprobado', 'propuesta' => 'cerrar_meta']);
local_chatbot_verificar($r['estado'] === adaptive::STATE_REQUIERE_PROFUNDIZACION,
    'revision: un estado fuera del vocabulario cae en requiere profundizacion, no en avance');
local_chatbot_verificar($r['propuesta'] === adaptive::PROPOSAL_NONE,
    'revision: no se propone cerrar sin estado de propuesta de cierre');

$r = adaptive::validate_review(['estado' => adaptive::STATE_PROPUESTA_CIERRE_META, 'propuesta' => 'ninguna']);
local_chatbot_verificar($r['propuesta'] === adaptive::PROPOSAL_CLOSE,
    'revision: una propuesta de cierre siempre pide confirmar el cierre');

$r = adaptive::validate_review(['estado' => adaptive::STATE_REQUIERE_PROFUNDIZACION,
    'propuesta' => 'actualizar_meta', 'propuesta_texto' => 'Nuevas acciones']);
local_chatbot_verificar($r['propuesta'] === adaptive::PROPOSAL_NONE && $r['propuesta_texto'] === '',
    'revision: no se actualiza la meta sin avance registrado');

$r = adaptive::validate_review(['estado' => adaptive::STATE_AVANCE_REGISTRADO,
    'propuesta' => 'actualizar_meta', 'propuesta_texto' => '']);
local_chatbot_verificar($r['propuesta'] === adaptive::PROPOSAL_NONE,
    'revision: no se actualiza la meta sin decir que cambia');

$r = adaptive::validate_review(['estado' => adaptive::STATE_AVANCE_REGISTRADO,
    'propuesta' => 'actualizar_meta', 'propuesta_texto' => 'Aplicar la pauta en dos sesiones']);
local_chatbot_verificar($r['propuesta'] === adaptive::PROPOSAL_UPDATE,
    'revision: actualizacion coherente se conserva y queda para confirmar');

$r = adaptive::validate_review(['criterios_revisados' => array_fill(0, 30, 'criterio'), 'estado' => 'x']);
local_chatbot_verificar(count($r['criterios_revisados']) === 10, 'revision: la lista de criterios se acota');

// --- NFR-SEC-005: rechazo con motivo acotado ---
$r = adaptive::validate_review(['rechazo' => ['motivo' => 'datos_de_terceros']]);
local_chatbot_verificar($r['rechazo'] === 'datos_de_terceros', 'rechazo: motivo reconocido se conserva');
$r = adaptive::validate_review(['rechazo' => ['motivo' => 'lo que el modelo quiera escribir']]);
local_chatbot_verificar($r['rechazo'] === 'intento_de_explotacion',
    'rechazo: un motivo libre no entra al registro, se normaliza');
$r = adaptive::validate_review(['rechazo' => null, 'estado' => adaptive::STATE_AVANCE_REGISTRADO]);
local_chatbot_verificar($r['rechazo'] === null, 'rechazo: sin rechazo no se inventa uno');

// --- RU-ADAPT-004/005: texto para la persona ---
$review = adaptive::validate_review(['estado' => adaptive::STATE_PROPUESTA_CIERRE_META,
    'fortaleza_observada' => 'Criterios observables', 'por_que_importa' => 'Permite retroalimentar',
    'siguiente_accion' => 'Aplicala en tu proximo curso']);
$md = adaptive::render_review($review, 'Diseñar criterios de desempeño.');
// Formato de cinco campos del SPEC v5 (RU-GEN-003).
foreach (['Fortaleza observada', 'Aspecto a profundizar', 'Por qué importa', 'Siguiente acción sugerida',
        'Propuesta de actualización de tu meta'] as $bloque) {
    local_chatbot_verificar(strpos($md, "**{$bloque}:**") !== false, "revision: incluye el campo v5 '{$bloque}'");
}
local_chatbot_verificar(strpos($md, 'no es una calificación ni una certificación') !== false,
    'revision: declara que no es calificacion ni certificacion (RU-ADAPT-005)');
local_chatbot_verificar(strpos($md, '¿Lo confirmas?') !== false,
    'revision: una propuesta sobre la meta pide confirmacion (RU-ADAPT-004)');
local_chatbot_verificar($review['propuesta_actualizacion'] === 'proponer_cierre',
    'revision: el tipo de propuesta se deriva del estado, no lo decide el modelo');
$sinpropuesta = adaptive::render_review(adaptive::validate_review(['estado' => adaptive::STATE_REQUIERE_PROFUNDIZACION]), 'Meta');
local_chatbot_verificar(strpos($sinpropuesta, 'postergar la meta') !== false,
    'revision: sin propuesta igual cierra pidiendo una decision de la persona');
$antiguo = adaptive::validate_review(['estado' => adaptive::STATE_AVANCE_REGISTRADO, 'fortalezas' => 'X',
    'a_profundizar' => 'Y', 'accion_siguiente' => 'Z']);
local_chatbot_verificar($antiguo['fortaleza_observada'] === 'X' && $antiguo['aspecto_a_profundizar'] === 'Y'
    && $antiguo['accion_siguiente'] === 'Z' && $antiguo['propuesta_actualizacion'] === 'registrar_avance',
    'revision: las claves anteriores del modelo siguen funcionando');

// --- RU-COA-010 (SPEC v5): plantilla de propuesta del Anexo F §5.3 ---
$configurados = ['D1.1 Distingue evidencia de instrumento', 'D1.2 Es viable dentro de las condiciones descritas',
    'D1.2 Incorpora progresion', 'D1.2 Define una evidencia de avance coherente', 'D1.1 Explica relaciones'];
$prop = adaptive::compose_proposal([
    'nombre' => 'Mapa de componentes', 'proposito' => 'Distinguir componentes', 'consigna' => 'Describe una situacion...',
    'recurso_previo' => 'lectura de la unidad 1', 'evidencia' => 'Tabla de cinco partes', 'tiempo' => '20 a 30 minutos',
    'criterios' => ['d1.1 distingue evidencia de instrumento.', 'Un criterio que el modelo invento',
        'D1.2 Es viable dentro de las condiciones descritas', 'D1.2 Incorpora progresion',
        'D1.2 Define una evidencia de avance coherente', 'D1.1 Explica relaciones'],
], $configurados, 'Meta X', ['Lectura de la Unidad 1']);
foreach (['Actividad propuesta', 'Meta vinculada', 'Propósito', 'Consigna', 'Antes de comenzar',
        'Evidencia que revisaremos', 'Criterios de revisión', 'Tiempo orientativo'] as $campo) {
    local_chatbot_verificar(strpos($prop['texto'], "**{$campo}:**") !== false, "propuesta: incluye '{$campo}'");
}
local_chatbot_verificar(strpos($prop['texto'], 'ajustarla a tu contexto o postergarla') !== false,
    'propuesta: termina preguntando si la realiza, la ajusta o la posterga');
local_chatbot_verificar(count($prop['criterios']) === 4, 'propuesta: como maximo 4 criterios de revision');
local_chatbot_verificar(!in_array('Un criterio que el modelo invento', $prop['criterios'], true),
    'propuesta: un criterio que no esta configurado se descarta');
local_chatbot_verificar(strpos($prop['texto'], '«Lectura de la Unidad 1»') !== false,
    'propuesta: el recurso previo se resuelve contra el catalogo');
$pocos = adaptive::compose_proposal(['consigna' => 'Haz algo', 'criterios' => ['inventado']], $configurados, 'M', []);
local_chatbot_verificar(count($pocos['criterios']) >= 2 && count($pocos['criterios']) <= 4,
    'propuesta: si el modelo no copia criterios validos, se usan entre 2 y 4 configurados');

// --- RU-PRIV-008 (SPEC v5): datos de terceros detectados sin modelo ---
local_chatbot_verificar(in_array('rut', adaptive::third_party_data_markers('cliente RUT 12.345.678-5'), true),
    'terceros: RUT con puntos y guion');
local_chatbot_verificar(in_array('rut', adaptive::third_party_data_markers('rut 12345678-k'), true),
    'terceros: RUT sin puntos con K');
local_chatbot_verificar(in_array('telefono', adaptive::third_party_data_markers('llamar al +56 9 8765 4321'), true),
    'terceros: movil con codigo de pais');
local_chatbot_verificar(in_array('telefono', adaptive::third_party_data_markers('su numero es 987654321'), true),
    'terceros: movil sin espacios');
local_chatbot_verificar(in_array('correo', adaptive::third_party_data_markers('escribir a juan.perez@empresa.cl'), true),
    'terceros: correo');
local_chatbot_verificar(adaptive::third_party_data_markers(
    'Actividad de 90 minutos para 12 personas con 3 carros y 6 dispositivos; evidencia en la estacion 2.') === [],
    'terceros: una evidencia con cifras normales no es un falso positivo');

// --- RU-ADAPT-006 (SPEC v5): los 12 estados de meta, derivados ---
$v5 = fn($g, $activo, $a, $pend) => \local_chatbot\pdp::goal_state_v5($g, $activo, $a, $pend);
local_chatbot_verificar($v5(\local_chatbot\pdp::GOAL_PROPUESTO, true, null, false) === 'propuesta', 'estado v5: propuesta');
local_chatbot_verificar($v5(\local_chatbot\pdp::GOAL_ACEPTADO, false, null, false) === 'propuesta',
    'estado v5: aceptada en un plan sin validar sigue como propuesta');
local_chatbot_verificar($v5(\local_chatbot\pdp::GOAL_ACEPTADO, true, null, false) === 'activa', 'estado v5: activa');
local_chatbot_verificar($v5(\local_chatbot\pdp::GOAL_ACEPTADO, true, adaptive::STATE_ACTIVIDAD_INICIADA, false) === 'en_desarrollo',
    'estado v5: en desarrollo');
local_chatbot_verificar($v5(\local_chatbot\pdp::GOAL_MODIFICADO, true, adaptive::STATE_REQUIERE_PROFUNDIZACION, false)
    === 'requiere_profundizacion', 'estado v5: requiere profundizacion');
local_chatbot_verificar($v5(\local_chatbot\pdp::GOAL_ACEPTADO, true, adaptive::STATE_AVANCE_REGISTRADO, true) === 'en_revision',
    'estado v5: propuesta de actualizacion pendiente = en revision');
local_chatbot_verificar($v5(\local_chatbot\pdp::GOAL_ACEPTADO, true, adaptive::STATE_PROPUESTA_CIERRE_META, true)
    === 'propuesta_cierre', 'estado v5: propuesta de cierre pendiente');
local_chatbot_verificar($v5(\local_chatbot\pdp::GOAL_POSTERGADO, true, null, false) === 'postergada', 'estado v5: postergada');
local_chatbot_verificar($v5(\local_chatbot\pdp::GOAL_COMPLETADO, true, null, false) === 'cerrada', 'estado v5: cerrada');
local_chatbot_verificar($v5(\local_chatbot\pdp::GOAL_RECHAZADO, true, null, false) === 'rechazada', 'estado v5: rechazada');
local_chatbot_verificar(count(\local_chatbot\pdp::GOAL_STATES_V5) === 12, 'estado v5: el vocabulario tiene 12 estados');

// --- RU-ADAPT-001: propiedad estructural ---
// No se prueba ejecutando: se verifica que el codigo que podria crear una
// actividad de Moodle no existe en el motor, igual que GR-09 con las notas.
$fuente = file_get_contents(__DIR__ . '/../classes/adaptive.php');
$prohibido = ['add_moduleinfo', 'create_module', "insert_record('course_modules'", 'course_add_cm_to_section'];
$hallados = array_filter($prohibido, function(string $api) use ($fuente): bool {
    return stripos($fuente, $api) !== false;
});
local_chatbot_verificar(empty($hallados),
    'RU-ADAPT-001: el motor no invoca APIs de creacion de actividades (' . implode(', ', $hallados) . ')');

// --- RU-ADAPT-007: registro estructurado de la intervencion del Coach ---
// Se arma con datos de prueba, sin tocar la base: lo que se verifica es el
// contrato del Anexo F 10, no el almacenamiento.
$review = (object) [
    'id' => 7, 'adaptid' => 3, 'evidenceid' => 'EV-U1-000007', 'evidenceformat' => 'texto',
    'evidencesynthesis' => 'Analisis de una pauta de observacion propia.',
    'criteriareviewed' => "Distingue evidencia de instrumento\nJustifica con el criterio",
    'reviewstatus' => 'avance_registrado',
    'feedbacksynthesis' => 'Avanza.', 'strength' => 'Relaciona criterio y evidencia.',
    'aspecttodeepen' => 'Precisar el instrumento.',
    'whyitmatters' => 'Sin instrumento la evidencia no se valora igual.',
    'nextaction' => 'Redactar la pauta con tres niveles.',
    'updateproposal' => 'Agregar la pauta como evidencia.',
    'proposal' => \local_chatbot\adaptive::PROPOSAL_UPDATE, 'proposaltext' => 'Agregar la pauta',
    'personconfirmed' => 1, 'pdpstatebefore' => 'aceptado', 'pdpstateafter' => 'modificado',
    'timedelivered' => 1000, 'timeconfirmed' => 2000,
];
$adapt = (object) [
    'id' => 3, 'courseid' => 0, 'goalid' => 11, 'unidad' => 'U1', 'estado' => 'avance_registrado',
    'activitytype' => 'analisis', 'activityversion' => 'v1.0', 'privacyclass' => 'pseudonimizado',
    'retentionversion' => 'r1', 'dimensionid' => 0,
];
// El contexto se pasa armado: build_record no consulta nada, y por eso se puede
// verificar aqui, sin arrancar Moodle.
$contexto = [
    'pseudoid' => 'facilitador-a1b2c3', 'verification_id' => 'VI-U1-000007', 'unit' => 'U1',
    'dimension' => 'D1', 'goal_origin' => 'ia', 'pdp_id' => 5, 'prompt_version' => 2,
    'kb_version' => '4docs-v1.1.1.2', 'consent_version' => 'abc123def456',
    'retention_months' => 12, 'pdp_version_before' => 4, 'pdp_version_after' => 5,
];
$registro = \local_chatbot\adaptive::build_record($review, $adapt, $contexto);
foreach (['user_id_pseudonimizado', 'evidence_id', 'verification_id', 'pdp_goal_id', 'unit',
        'prompt_version', 'kb_version', 'consent_version', 'retention_version',
        'pdp_version_before', 'pdp_version_after', 'evidence_summary', 'verification_criteria',
        'strength_observed', 'aspect_to_deepen', 'why_it_matters', 'next_action',
        'update_proposal', 'person_decision', 'pdp_state_before', 'pdp_state_after',
        'delivered_at', 'confirmed_at', 'privacy_class'] as $campo) {
    local_chatbot_verificar(array_key_exists($campo, $registro), "registro Anexo F: incluye {$campo}");
}
local_chatbot_verificar($registro['verification_criteria']
    === ['Distingue evidencia de instrumento', 'Justifica con el criterio'],
    'registro: los criterios verificados van como lista');
local_chatbot_verificar($registro['person_decision'] === 'aceptada',
    'registro: la decision de la persona queda en palabras');
local_chatbot_verificar($registro['pdp_version_before'] === 4 && $registro['pdp_version_after'] === 5,
    'registro: quedan las versiones del plan antes y despues de la decision');
local_chatbot_verificar($registro['verification_id'] === 'VI-U1-000007' && $registro['unit'] === 'U1',
    'registro: codigo de verificacion y unidad con la convencion del Anexo G');
local_chatbot_verificar($registro['subdimension'] === $registro['dimension'],
    'registro: la subdimension sigue a la dimension (desvio declarado del instrumento)');
local_chatbot_verificar(\local_chatbot\adaptive::decision_label(-1) === 'pendiente'
    && \local_chatbot\adaptive::decision_label(0) === 'declinada',
    'registro: pendiente y declinada se nombran bien');
local_chatbot_verificar(strpos(json_encode($registro, JSON_UNESCAPED_UNICODE), 'Analisis de una pauta') !== false,
    'registro: lleva la sintesis de la evidencia');
local_chatbot_verificar($registro['privacy_class'] === 'pseudonimizado',
    'registro: declara su clasificacion de privacidad');

printf("Casos verificados: %d | Fallidos: %d\n", $casos, $fallidos);

if ($fallidos === 0) {
    echo "Motor de apoyos adaptativos conforme a RU-ADAPT-001, 004, 005 y 006, y a NFR-SEC-005.\n";
    exit(0);
}
echo "Motor de apoyos NO conforme: revisar classes/adaptive.php antes de desplegar.\n";
exit(1);
