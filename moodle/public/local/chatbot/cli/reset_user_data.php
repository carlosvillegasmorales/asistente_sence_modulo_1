<?php
/**
 * CLI script to reset all user activity and test data in local_chatbot back to 0.
 * Preserves catalog, items, rubric criteria and versioned prompts.
 *
 * Usage: php moodle/public/local/chatbot/cli/reset_user_data.php
 *
 * @package    local_chatbot
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

cli_heading('Reset de Datos de Usuario - Asistente IA Modulo 1');

global $DB;

$userTables = [
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
];

$totalDeleted = 0;
foreach ($userTables as $table) {
    $count = $DB->count_records($table);
    if ($count > 0) {
        $DB->delete_records($table);
        cli_writeln("  - {$table}: {$count} registros eliminados.");
        $totalDeleted += $count;
    } else {
        cli_writeln("  - {$table}: 0 registros (ya limpio).");
    }
}

// Limpiar cache de ratelimit si existe.
try {
    $cache = \cache::make('local_chatbot', 'ratelimit');
    $cache->purge();
    cli_writeln("  - Cache de ratelimit purgada.");
} catch (\Throwable $e) {
    // Ignorar si la cache no esta inicializada.
}

cli_writeln("\nTotal de registros eliminados: {$totalDeleted}");
cli_writeln("Todo el progreso y actividad de usuario ha quedado en 0.");
