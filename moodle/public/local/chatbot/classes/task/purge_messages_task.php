<?php
namespace local_chatbot\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Tarea programada para purgar el historial conversacional según la política de retención (ERTF v3.0).
 *
 * Elimina registros de mdl_local_chatbot_messages con más de 12 meses de antigüedad (365 días),
 * conservando intactos los registros de auditoría en mdl_local_chatbot_interaction.
 */
class purge_messages_task extends \core\task\scheduled_task {

    /**
     * @return string Nombre legible de la tarea.
     */
    public function get_name(): string {
        return get_string('task_purge_messages', 'local_chatbot');
    }

    /**
     * Ejecución de la tarea programada.
     */
    public function execute(): void {
        global $DB;

        // Retención configurable en meses (por defecto 12 meses = 365 días).
        $retentionmonths = (int) get_config('local_chatbot', 'message_retention_months') ?: 12;
        $cutoff = time() - ($retentionmonths * 30 * 86400);

        mtrace("local_chatbot: Iniciando purga de mensajes anteriores a " . userdate($cutoff));

        $count = $DB->count_records_select('local_chatbot_messages', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
        if ($count > 0) {
            $DB->delete_records_select('local_chatbot_messages', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
            mtrace("local_chatbot: Se eliminaron {$count} mensajes del historial conversacional.");
        } else {
            mtrace("local_chatbot: No hay mensajes que superen la ventana de retención.");
        }
    }
}
