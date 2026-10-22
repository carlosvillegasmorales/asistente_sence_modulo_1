<?php
/**
 * Verifica las reglas de integridad del cuestionario que no pueden depender de que el
 * modelo se porte bien:
 *
 *   RU-TUT-007 (SPEC v5)  la clave queda protegida hasta que Moodle la libera: sin
 *                         intentos, con un intento en curso y entre intentos
 *   RU-KB-001  (SPEC v5)  un documento con claves de evaluacion no entra a la base
 *                         de conocimiento
 *
 * Origen: prueba automatica del 21-09-2026 (docs/RESULTADOS_PRUEBAS_AUTOMATICAS.md,
 * incidencia I-1), donde el Tutor dejaba deducible la alternativa correcta entre el
 * primer y el segundo intento.
 *
 * Uso:  php cli/verificar_integridad_quiz.php
 * Sale con codigo 1 ante cualquier caso fallido, para encadenarlo a CI.
 */

require_once(__DIR__ . '/_puro.php');
require_once(__DIR__ . '/../classes/assistant.php');
require_once(__DIR__ . '/../classes/kb_store.php');

use local_chatbot\assistant;
use local_chatbot\kb_store;

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

// Mascaras de revision de "Respuesta correcta" (mod_quiz\question\display_options).
$soloalcierre = 0x00010;          // Configuracion del Anexo D: clave explicita solo al cierre.
$inmediata = 0x01000 | 0x00100 | 0x00010;
$ahora = 1_800_000_000;
$e = fn(int $fin, int $abiertos, int $max, int $rev, int $cierre = 0) =>
    assistant::quiz_key_state($fin, $abiertos, $max, $rev, $cierre, $ahora);

local_chatbot_verificar($e(0, 0, 2, $soloalcierre) === assistant::QUIZ_KEY_PENDING,
    'sin intentos: la clave esta protegida');
local_chatbot_verificar($e(0, 1, 2, $soloalcierre) === assistant::QUIZ_KEY_IN_PROGRESS,
    'primer intento en curso: protegida');
local_chatbot_verificar($e(1, 1, 2, $inmediata) === assistant::QUIZ_KEY_IN_PROGRESS,
    'segundo intento en curso: protegida aunque la revision muestre la clave');
local_chatbot_verificar($e(1, 0, 2, $soloalcierre) === assistant::QUIZ_KEY_BETWEEN,
    'entre el intento 1 y el 2 con clave solo al cierre: protegida (caso F-42)');
local_chatbot_verificar($e(2, 0, 2, $soloalcierre) === assistant::QUIZ_KEY_RELEASED,
    'intentos agotados: liberada');
local_chatbot_verificar($e(1, 0, 2, $inmediata) === assistant::QUIZ_KEY_RELEASED,
    'revision que muestra la clave tras el intento: liberada');
local_chatbot_verificar($e(1, 0, 0, $soloalcierre) === assistant::QUIZ_KEY_BETWEEN,
    'intentos ilimitados y clave solo al cierre: protegida');
local_chatbot_verificar($e(1, 0, 0, $soloalcierre, $ahora - 10) === assistant::QUIZ_KEY_RELEASED,
    'cuestionario cerrado con clave al cierre: liberada');
local_chatbot_verificar($e(1, 0, 0, 0x01000, $ahora - 10) === assistant::QUIZ_KEY_BETWEEN,
    'cerrado, pero la clave solo se mostraba inmediatamente despues: protegida');

// RU-KB-001: marcas de clave en los formatos reales de los documentos de Interfases.
local_chatbot_verificar(kb_store::answer_key_markers('SOLO CONFIGURACIÓN MOODLE · Alternativa correcta: C.') !== [],
    'kb: marca del Anexo D se detecta');
local_chatbot_verificar(kb_store::answer_key_markers('NO VISIBLE PARA LA PERSONA PARTICIPANTE · Clave: B.') !== [],
    'kb: marca del Doc 2 se detecta');
local_chatbot_verificar(kb_store::answer_key_markers('Respuesta correcta: A') !== [],
    'kb: "respuesta correcta: A" se detecta');
local_chatbot_verificar(kb_store::answer_key_markers(
    "### [U1-QF-01-P01] Pregunta 1. Del desempeño al aprendizaje esperado\nCriterio que trabaja: Distingue ...") === [],
    'kb: documento de criterios sin claves se acepta');
local_chatbot_verificar(kb_store::answer_key_markers(
    'La clave de una buena actividad es la evidencia. Una respuesta correcta requiere criterio.') === [],
    'kb: la palabra "clave" en prosa no es un falso positivo');

// Diagnostico (RU-DIAG-002): un item situacional tiene respuesta correcta; explicar que enfoque conviene la revela.
$diag = \local_chatbot\assistant::diagnostic_phase_clause();
local_chatbot_verificar(strpos($diag, 'SITUACIONALES') !== false && strpos($diag, 'NO le recuerdes') !== false,
    'diagnostico: en un item situacional no se recuerda la practica habitual');
local_chatbot_verificar(strpos($diag, 'ni compares enfoques') !== false && strpos($diag, 'eso ES la respuesta') !== false,
    'diagnostico: en un item situacional no se dice que enfoque es mas adecuado');
local_chatbot_verificar(strpos($diag, 'DECLARATIVOS') !== false && strpos($diag, 'Solo en estos recuerdale') !== false,
    'diagnostico: el recordatorio de practica habitual queda solo para los declarativos');
// Precision conceptual y de citas (errores vistos el 21-10-2026).
$conceptos = \local_chatbot\assistant::concepts_clause();
local_chatbot_verificar(strpos($conceptos, 'Desempeño esperado') !== false && strpos($conceptos, 'Aprendizaje esperado') !== false
    && strpos($conceptos, 'suelen describir el aprendizaje esperado') !== false, 'conceptos: desempeño y aprendizaje esperado no se intercambian');
local_chatbot_verificar(strpos($conceptos, 'CITAS') !== false && strpos($conceptos, 'estos cinco criterios') !== false,
    'conceptos: no se atribuyen a un apartado listas o cantidades que no contiene');

printf("Casos verificados: %d | Fallidos: %d\n", $casos, $fallidos);
if ($fallidos === 0) {
    echo "Integridad del cuestionario conforme a RU-TUT-007 y RU-KB-001 (SPEC v5).\n";
    exit(0);
}
echo "Integridad del cuestionario NO conforme: revisar assistant::quiz_key_state y kb_store::answer_key_markers.\n";
exit(1);
