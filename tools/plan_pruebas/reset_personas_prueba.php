<?php
/**
 * Devuelve a las personas del plan de pruebas a su estado de partida, sin tocar
 * nada más del sitio.
 *
 * Hace falta porque las otras dos herramientas son demasiado amplias para esto:
 * borrar_plan_prueba.php elimina también los documentos U1-* de la base de
 * conocimiento (que es del sitio, no del curso, y contiene los recursos
 * prioritarios del Tutor), y reset_user_data.php vacía los datos de todas las
 * personas del sitio, incluidas las del recorrido general que alimentan el
 * panel de indicadores.
 *
 * Aquí solo se borran las filas de las personas con idnumber PLAN-PRUEBA-M1.
 * No se tocan: la base de conocimiento, el instrumento de diagnóstico, los
 * criterios de la rúbrica, los prompts versionados, el curso, ni las cuentas.
 *
 * Uso:  MOODLE_CFG=/ruta/config.php php reset_personas_prueba.php [--confirmar]
 * Sin --confirmar solo informa lo que borraría.
 */
define('CLI_SCRIPT', true);

$cfg = getenv('MOODLE_CFG');
if (!$cfg || !file_exists($cfg)) {
    fwrite(STDERR, "Falta la variable MOODLE_CFG con la ruta de config.php\n");
    exit(1);
}
require($cfg);

global $DB;

$confirmar = in_array('--confirmar', $argv, true);

$personas = $DB->get_records('user', ['idnumber' => 'PLAN-PRUEBA-M1', 'deleted' => 0], 'username', 'id, username');
if (empty($personas)) {
    echo "No hay personas con idnumber PLAN-PRUEBA-M1. Nada que hacer.\n";
    exit(0);
}
$ids = array_keys($personas);
[$insql, $params] = $DB->get_in_or_equal($ids);

echo "Personas del plan de pruebas: " . implode(', ', array_column($personas, 'username')) . "\n\n";

// Orden inverso a las dependencias: primero lo que cuelga de un plan o un apoyo.
$tablas = [
    'local_chatbot_closure',
    'local_chatbot_coach_report',
    'local_chatbot_adapt_review',
    'local_chatbot_adapt',
    'local_chatbot_project_fb',
    'local_chatbot_project',
    'local_chatbot_pdp_history',
    'local_chatbot_pdp_goal',
    'local_chatbot_pdp',
    'local_chatbot_diag_response',
    'local_chatbot_profile',
    'local_chatbot_messages',
    'local_chatbot_interaction',
    'local_chatbot_trace',
    'local_chatbot_quiz_pattern',
];

$total = 0;
foreach ($tablas as $tabla) {
    // Varias tablas no guardan userid: cuelgan de su fila padre.
    if ($tabla === 'local_chatbot_pdp_goal' || $tabla === 'local_chatbot_pdp_history') {
        $sql = "pdpid IN (SELECT id FROM {local_chatbot_pdp} WHERE userid $insql)";
    } else if ($tabla === 'local_chatbot_project_fb') {
        $sql = "projectid IN (SELECT id FROM {local_chatbot_project} WHERE userid $insql)";
    } else if ($tabla === 'local_chatbot_adapt_review') {
        $sql = "adaptid IN (SELECT id FROM {local_chatbot_adapt} WHERE userid $insql)";
    } else {
        $sql = "userid $insql";
    }

    $n = $DB->count_records_select($tabla, $sql, $params);
    if ($n === 0) {
        echo "  " . str_pad($tabla, 32) . "0\n";
        continue;
    }
    if ($confirmar) {
        $DB->delete_records_select($tabla, $sql, $params);
    }
    echo "  " . str_pad($tabla, 32) . $n . ($confirmar ? " borrados" : " se borrarían") . "\n";
    $total += $n;
}

echo "\nTotal: {$total} registros" . ($confirmar ? " eliminados.\n" : " se eliminarían.\n");

if (!$confirmar) {
    echo "\nModo simulación. Agrega --confirmar para ejecutar.\n";
    exit(0);
}

// La base de conocimiento no se toca: se confirma para dejarlo por escrito.
echo "\nSin cambios en la base de conocimiento: "
    . count(\local_chatbot\kb_store::list_documents()) . " documentos vigentes ("
    . \local_chatbot\kb_store::context_version() . ").\n";
