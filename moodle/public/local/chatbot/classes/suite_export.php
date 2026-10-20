<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Insumos que el Modulo 1 entrega al resto de la suite.
 *
 * El documento no deja esto para despues: "su arquitectura debe prever estas
 * relaciones desde la fase de diseno" y enumera seis destinos con la
 * informacion que cada uno recibe. Esta clase materializa esos contratos como
 * estructuras de datos estables, para que cuando exista el transporte real
 * —web service, cola, tarea programada— solo haya que conectarlo y no
 * rediseniar que se envia.
 *
 * Cada metodo devuelve exactamente lo que su destino necesita y nada mas, que
 * es tambien la forma de cumplir la regla de minimizacion: la capa usuaria no
 * recibe evidencias del proyecto, y gestion institucional no recibe identidades.
 */
class suite_export {

    /**
     * Capa usuaria: perfil, estado de avance, alertas y proximos pasos, para
     * personalizar la navegacion y el tablero individual de la persona.
     *
     * @param int $userid
     * @param int $courseid
     * @return array
     */
    public static function for_user_layer(int $userid, int $courseid): array {
        $conditions = closure::get_conditions($userid, $courseid);
        $pending = [];
        foreach ($conditions as $key => $met) {
            if (!$met) {
                $pending[] = $key;
            }
        }

        return [
            'persona' => pseudonymizer::for_user($userid),
            'perfilcompleto' => profile::has_consent($userid, $courseid),
            'diagnosticoaplicado' => diagnostic::has_responses($userid, $courseid),
            'pdpvigente' => pdp::has_active_plan($userid, $courseid),
            'modulocerrado' => closure::is_complete($userid, $courseid),
            'proximospasos' => $pending,
        ];
    }

    /**
     * Modulo 2 (laboratorio de diseno instruccional): brechas y objetivos de
     * desarrollo vinculados a diseno, evaluacion y alineamiento, para priorizar
     * los apoyos al formular planes y paquetes evaluativos.
     *
     * @param int $userid
     * @param int $courseid
     * @return array
     */
    public static function for_module_two(int $userid, int $courseid): array {
        global $DB;

        $brechas = [];
        foreach (diagnostic::get_gap_matrix($userid, $courseid) as $row) {
            // Al Modulo 2 viajan las brechas, no las fortalezas: se agrupa con
            // la misma correspondencia que usa el Coach (diagnostic::level_group).
            if (diagnostic::level_group($row['nivel']) === 'fortaleza') {
                continue;
            }
            $brechas[] = [
                'dimension' => $row['code'],
                'nivel' => $row['nivel'],
                'promedio' => $row['promedio'],
            ];
        }

        $objetivos = [];
        $plan = $DB->get_record('local_chatbot_pdp', ['userid' => $userid, 'courseid' => $courseid]);
        if ($plan) {
            foreach (pdp::list_goals($plan->id, [pdp::GOAL_ACEPTADO, pdp::GOAL_MODIFICADO]) as $goal) {
                $objetivos[] = [
                    'dimension' => $goal->dimensionid,
                    'objetivo' => $goal->objetivo,
                    'evidencia' => $goal->evidencia,
                ];
            }
        }

        return [
            'persona' => pseudonymizer::for_user($userid),
            'brechas' => $brechas,
            'objetivos' => $objetivos,
        ];
    }

    /**
     * Modulo 3 (aseguramiento de la calidad): PDP, criterios de seguimiento y
     * proyecto integrador, para contrastar diseno declarado contra
     * implementacion observada.
     *
     * @param int $userid
     * @param int $courseid
     * @return array
     */
    public static function for_module_three(int $userid, int $courseid): array {
        global $DB;

        $project = project::get($userid, $courseid);
        $criterios = [];

        if ($project) {
            foreach (project::get_feedback($project->id) as $criterionid => $feedback) {
                $criterion = $DB->get_record('local_chatbot_criterion', ['id' => $criterionid]);
                $criterios[] = [
                    'criterio' => $criterion ? $criterion->code : (string) $criterionid,
                    'brechadeclarada' => $feedback->mejora,
                    'accionsugerida' => $feedback->accion,
                    'requiererevisionhumana' => (bool) $feedback->needshuman,
                ];
            }
        }

        return [
            'persona' => pseudonymizer::for_user($userid),
            'proyectoentregado' => $project && $project->status !== project::STATUS_BORRADOR,
            'versionproyecto' => $project ? (int) $project->version : 0,
            'criterios' => $criterios,
            'compromisos' => self::commitments($userid, $courseid),
        ];
    }

    /**
     * Capa analitica: resultados diagnosticos, avance, uso de IA, PDP,
     * proyecto y rubrica, agregados por curso.
     *
     * @param int $courseid
     * @return array
     */
    public static function for_analytics_layer(int $courseid): array {
        return [
            'curso' => pseudonymizer::for_course($courseid),
            'avance' => analytics::completion_funnel($courseid),
            'brechas' => analytics::gap_distribution($courseid),
            'variacion' => analytics::progress_variation($courseid),
            'metas' => analytics::pdp_goal_outcomes($courseid),
            'interacciones' => analytics::interactions_by_mode($courseid),
            'rubrica' => analytics::rubric_hotspots($courseid),
        ];
    }

    /**
     * Gestion institucional: datos agregados y anonimizados por perfil,
     * dimension y uso. A diferencia de la capa analitica, aqui no viaja nada
     * atribuible a un curso concreto.
     *
     * @return array
     */
    public static function for_institutional_management(): array {
        global $DB;

        $porrol = $DB->get_records_sql(
            "SELECT rol, COUNT(id) AS total
               FROM {local_chatbot_profile}
              WHERE consentaccepted = 1
           GROUP BY rol");

        $pormodalidad = $DB->get_records_sql(
            "SELECT modalidad, COUNT(id) AS total
               FROM {local_chatbot_profile}
              WHERE consentaccepted = 1
           GROUP BY modalidad");

        return [
            'personasporrol' => array_map(function($row) {
                return ['rol' => $row->rol, 'total' => (int) $row->total];
            }, array_values($porrol)),
            'personaspormodalidad' => array_map(function($row) {
                return ['modalidad' => $row->modalidad, 'total' => (int) $row->total];
            }, array_values($pormodalidad)),
            'avancesitio' => analytics::completion_funnel(0),
        ];
    }

    /**
     * @param int $userid
     * @param int $courseid
     * @return array Acciones priorizadas que la persona declaro al cerrar.
     */
    private static function commitments(int $userid, int $courseid): array {
        $record = closure::get($userid, $courseid);
        if (!$record) {
            return [];
        }

        return [
            'continuidad' => (string) $record->continuidad,
            'acciones' => (string) $record->acciones,
        ];
    }
}
