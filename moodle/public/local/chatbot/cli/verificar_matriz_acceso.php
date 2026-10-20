<?php
/**
 * Verifica que la matriz de acceso a datos por modo implementada en
 * local_chatbot\assistant coincida con la tabla §7.3 de la ERTF.
 *
 * La ERTF califica esa matriz como "una regla de seguridad, no una sugerencia"
 * y pide que "se verifica en pruebas automatizadas". Este script es la version
 * ejecutable de esa verificacion: la tabla de abajo esta transcrita del
 * documento, no leida del codigo, asi que si alguien relaja un permiso en
 * assistant.php el desvio aparece aqui.
 *
 * No requiere base de datos ni bootstrap de Moodle: solo lee la constante.
 *
 * Uso:  php cli/verificar_matriz_acceso.php
 * Sale con codigo 1 si encuentra cualquier desvio, para poder encadenarlo a CI.
 */

define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/assistant.php');

use local_chatbot\assistant;

/**
 * @var array<string, array<string, string>> Tabla §7.3 tal como aparece en la
 * ERTF, mas la columna de la fase de diagnostico.
 *
 * Esa columna no viene de la ERTF sino de RU-DIAG-001/002: mientras el
 * instrumento no se envia el asistente no accede a nada de la persona, asi que
 * es la unica columna enteramente en "no". Se verifica igual que las otras
 * porque es donde mas caro sale un permiso relajado por descuido: contamina la
 * linea base sobre la que despues se construye todo el modulo.
 */
$ertf = [
    // Coach en perfil y brechas: la ERTF §7.3 dice "si", pero RU-COA-DATA-001
    // (matriz v4) lo estrecha al objeto de datos de Doc 3 §10.1, que deja fuera
    // la experiencia declarada. Es "parcial" por decision documentada, no por
    // descuido: si vuelve a "si", el Coach recupera datos que v4 le quito.
    'profile'            => ['diagnostico' => 'no', 'tutor' => 'parcial', 'coach' => 'parcial', 'evaluador' => 'parcial'],
    'gaps'               => ['diagnostico' => 'no', 'tutor' => 'no',      'coach' => 'parcial', 'evaluador' => 'parcial'],
    'diag_responses'     => ['diagnostico' => 'no', 'tutor' => 'no',      'coach' => 'parcial', 'evaluador' => 'no'],
    'unit_content'       => ['diagnostico' => 'no', 'tutor' => 'si',      'coach' => 'no',      'evaluador' => 'no'],
    'quiz_pending_items' => ['diagnostico' => 'no', 'tutor' => 'no',      'coach' => 'no',      'evaluador' => 'no'],
    'quiz_results'       => ['diagnostico' => 'no', 'tutor' => 'parcial', 'coach' => 'parcial', 'evaluador' => 'no'],
    'pdp'                => ['diagnostico' => 'no', 'tutor' => 'no',      'coach' => 'si',      'evaluador' => 'si'],
    'project'            => ['diagnostico' => 'no', 'tutor' => 'no',      'coach' => 'parcial', 'evaluador' => 'si'],
    'rubric'             => ['diagnostico' => 'no', 'tutor' => 'no',      'coach' => 'no',      'evaluador' => 'si'],
    // Objetos que agrega la matriz v4 y no estan en la ERTF. El catalogo son
    // nombres de recursos, no su contenido (RU-COA-LIM-001); el Coach lo recibe
    // dentro del objeto de datos. De los apoyos adaptativos el Tutor solo ve la
    // consigna vigente, no la meta del PDP que la matriz le niega.
    'resource_catalog'   => ['diagnostico' => 'no', 'tutor' => 'si',      'coach' => 'parcial', 'evaluador' => 'no'],
    'adaptive_supports'  => ['diagnostico' => 'no', 'tutor' => 'parcial', 'coach' => 'si',      'evaluador' => 'no'],
];

/**
 * Traduce el valor de la matriz al vocabulario de la tabla del documento.
 *
 * @param bool|string $value
 * @return string
 */
function local_chatbot_clasificar($value): string {
    if ($value === false) {
        return 'no';
    }
    if ($value === true) {
        return 'si';
    }
    return 'parcial';
}

$desvios = 0;
$celdas = 0;

foreach ($ertf as $objeto => $pormodo) {
    foreach ($pormodo as $modo => $esperado) {
        $celdas++;
        $real = local_chatbot_clasificar(assistant::can_access($modo, $objeto));
        if ($real !== $esperado) {
            printf("  DESVIO  %-19s %-10s esperado=%-8s real=%s\n", $objeto, $modo, $esperado, $real);
            $desvios++;
        }
    }
}

printf("Celdas verificadas: %d | Desvios: %d\n", $celdas, $desvios);

// Reglas absolutas de la tabla: valen para los tres modos y para la fase de
// diagnostico, sin excepcion.
foreach ([assistant::PHASE_DIAGNOSTICO, assistant::MODE_TUTOR,
        assistant::MODE_COACH, assistant::MODE_EVALUADOR] as $modo) {
    if (assistant::can_access($modo, 'quiz_pending_items') !== false) {
        echo "  DESVIO CRITICO: el modo {$modo} accede a items de quiz pendientes\n";
        $desvios++;
    }
    if (assistant::can_access($modo, 'otros_participantes') !== false) {
        echo "  DESVIO CRITICO: el modo {$modo} accede a datos de otros participantes\n";
        $desvios++;
    }
}

if ($desvios === 0) {
    echo "Matriz conforme a la ERTF §7.3.\n";
    exit(0);
}

echo "Matriz NO conforme: revisar assistant::ACCESS_MATRIX antes de desplegar.\n";
exit(1);
