<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Guarda los prompts de cada modo del asistente como versiones sucesivas en
 * lugar de un solo campo editable.
 *
 * Lo pide directamente la regla de gobernanza: "versionar prompts, rubricas,
 * reglas de recomendacion y bases documentales para asegurar trazabilidad de
 * cambios". Cada interaccion registrada guarda con que version de prompt se
 * produjo, de modo que si una recomendacion resulta problematica se puede
 * saber exactamente que instruccion la genero.
 *
 * Editar un prompt nunca sobrescribe: desactiva la version vigente y crea la
 * siguiente.
 */
class prompt_store {

    /**
     * @param string $mode Uno de assistant::MODE_*.
     * @return \stdClass|false La version activa del prompt de ese modo.
     */
    public static function get_active(string $mode) {
        global $DB;
        return $DB->get_record('local_chatbot_prompt', ['mode' => $mode, 'active' => 1]);
    }

    /**
     * @param string $mode
     * @return string Texto del prompt activo, o cadena vacia si no hay ninguno.
     */
    public static function get_text(string $mode): string {
        $record = self::get_active($mode);
        return $record ? $record->prompttext : '';
    }

    /**
     * @param string $mode
     * @return int Numero de version activa (0 si el modo no tiene prompt cargado).
     */
    public static function get_version(string $mode): int {
        $record = self::get_active($mode);
        return $record ? (int) $record->version : 0;
    }

    /**
     * Crea una version nueva del prompt de un modo y retira la anterior.
     *
     * @param string $mode
     * @param string $text
     * @param int $userid Quien hace el cambio.
     * @return int Numero de la version recien creada.
     */
    public static function save_new_version(string $mode, string $text, int $userid): int {
        global $DB;

        $current = self::get_active($mode);
        $nextversion = $current ? ((int) $current->version + 1) : 1;

        if ($current) {
            $DB->set_field('local_chatbot_prompt', 'active', 0, ['id' => $current->id]);
        }

        $record = new \stdClass();
        $record->mode = $mode;
        $record->prompttext = $text;
        $record->version = $nextversion;
        $record->active = 1;
        $record->usermodified = $userid;
        $record->timecreated = time();
        $DB->insert_record('local_chatbot_prompt', $record);

        return $nextversion;
    }

    /**
     * @param string $mode
     * @return \stdClass[] Historial completo de versiones, de la mas reciente a la mas antigua.
     */
    public static function get_history(string $mode): array {
        global $DB;
        return $DB->get_records('local_chatbot_prompt', ['mode' => $mode], 'version DESC');
    }
}
