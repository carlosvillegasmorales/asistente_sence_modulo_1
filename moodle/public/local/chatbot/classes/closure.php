<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Seguimiento y cierre (componente 5 del Modulo 1).
 *
 * El documento fija cuatro condiciones para dar por cerrado el modulo: que
 * exista una version revisada del proyecto, que se haya recibido
 * retroalimentacion, que la persona haya reflexionado y que el PDP este
 * ajustado. Esta clase las comprueba una por una en vez de dejar el cierre a
 * criterio de quien mira la pantalla.
 *
 * Lo que la persona declara aqui —reflexion, plan de continuidad y acciones
 * priorizadas— es lo que el documento destina a la derivacion hacia el Modulo
 * 2, el Modulo 3 y el soporte institucional.
 */
class closure {

    /**
     * @param int $userid
     * @param int $courseid
     * @return \stdClass|false
     */
    public static function get(int $userid, int $courseid) {
        global $DB;
        return $DB->get_record('local_chatbot_closure', ['userid' => $userid, 'courseid' => $courseid]);
    }

    /**
     * Evalua las cuatro condiciones de cierre del documento.
     *
     * @param int $userid
     * @param int $courseid
     * @return array Mapa condicion => bool.
     */
    public static function get_conditions(int $userid, int $courseid): array {
        global $DB;

        $project = project::get($userid, $courseid);
        $record = self::get($userid, $courseid);

        $condiciones = [
            'proyecto' => $project && $project->status !== project::STATUS_BORRADOR,
            'retroalimentacion' => $project
                && $DB->record_exists('local_chatbot_project_fb', ['projectid' => $project->id]),
            'reflexion' => $record && trim((string) $record->reflexion) !== '',
            'pdp' => pdp::has_active_plan($userid, $courseid),
        ];
        // Ruta de 6 etapas (ajustes del cliente del 08-10-2026): el PDP es opcional, no condiciona el cierre.
        if (ux::on('route6')) {
            unset($condiciones['pdp']);
        }
        return $condiciones;
    }

    /**
     * Ids de curso de una lista escrita en la configuracion («18, 19»). Funcion pura.
     *
     * @param string $raw
     * @return int[] Ids validos, sin repetir, en el orden escrito.
     */
    public static function parse_course_ids(string $raw): array {
        $ids = [];
        foreach (preg_split('/[\s,;]+/', trim($raw)) as $parte) {
            if (ctype_digit($parte) && (int) $parte > 1 && !in_array((int) $parte, $ids, true)) {
                $ids[] = (int) $parte;
            }
        }
        return $ids;
    }

    /**
     * Abre los modulos siguientes a quien cerro el Modulo 1: lo matricula como estudiante en los
     * cursos configurados (ajustes del cliente del 08-10-2026: «ambos modulos quedaran disponibles
     * al cierre del Modulo 1»). Idempotente: si ya esta matriculada, no hace nada.
     *
     * @param int $userid
     * @return array Lista de ['id', 'name', 'visible'] de los cursos a los que la persona tiene acceso.
     */
    public static function unlock_next_modules(int $userid): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');
        $ids = self::parse_course_ids((string) get_config('local_chatbot', 'nextmodules'));
        if (empty($ids) || !ux::on('unlockmodules')) {
            return [];
        }
        $plugin = enrol_get_plugin('manual');
        $rol = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        $abiertos = [];
        foreach ($ids as $id) {
            $curso = $DB->get_record('course', ['id' => $id], 'id, fullname, visible');
            if (!$curso) {
                continue;
            }
            $contexto = \context_course::instance($id);
            if (!is_enrolled($contexto, $userid)) {
                $instancia = $DB->get_record('enrol', ['courseid' => $id, 'enrol' => 'manual', 'status' => ENROL_INSTANCE_ENABLED]);
                if (!$plugin || !$instancia || !$rol) {
                    continue;
                }
                $plugin->enrol_user($instancia, $userid, $rol);
            }
            $abiertos[] = ['id' => (int) $curso->id, 'name' => format_string($curso->fullname), 'visible' => (bool) $curso->visible];
        }
        return $abiertos;
    }

    /**
     * @param int $userid
     * @param int $courseid
     * @return bool Si se cumplen las cuatro condiciones.
     */
    public static function is_complete(int $userid, int $courseid): bool {
        foreach (self::get_conditions($userid, $courseid) as $met) {
            if (!$met) {
                return false;
            }
        }
        return true;
    }

    /**
     * Guarda la declaracion de cierre de la persona.
     *
     * @param int $userid
     * @param int $courseid
     * @param array $data Claves: reflexion, continuidad, acciones.
     * @return int id del registro.
     */
    public static function save(int $userid, int $courseid, array $data): int {
        global $DB;

        $now = time();
        $record = self::get($userid, $courseid);
        $isnew = ($record === false);

        if ($isnew) {
            $record = new \stdClass();
            $record->userid = $userid;
            $record->courseid = $courseid;
            $record->timecreated = $now;
        }

        foreach (['reflexion', 'continuidad', 'acciones'] as $field) {
            if (isset($data[$field])) {
                $record->{$field} = $data[$field];
            }
        }
        $record->timemodified = $now;
        $record->completed = 0;

        if ($isnew) {
            $record->id = $DB->insert_record('local_chatbot_closure', $record);
        } else {
            $DB->update_record('local_chatbot_closure', $record);
        }

        // El estado de completado se recalcula despues de guardar, porque la
        // reflexion recien escrita puede ser justamente la condicion que
        // faltaba.
        $complete = self::is_complete($userid, $courseid) ? 1 : 0;
        $DB->set_field('local_chatbot_closure', 'completed', $complete, ['id' => $record->id]);

        return (int) $record->id;
    }
}
