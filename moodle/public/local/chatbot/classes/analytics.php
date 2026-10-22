<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Indicadores iniciales del Modulo 1.
 *
 * El documento es explicito en dos cosas que condicionan esta clase. Primero,
 * que los indicadores "deben medir uso y valor pedagogico, no solo cantidad de
 * clics". Segundo, que lo que llega a gestion institucional son "datos
 * agregados y anonimizados por perfil, dimension y uso".
 *
 * Por eso ningun metodo de aqui devuelve identidades: todo sale contado,
 * promediado o distribuido. Quien necesite mirar el caso individual de una
 * persona lo hace acompanandola, no desde un tablero.
 */
class analytics {

    /** @var int Semanas que cubre la linea de tiempo, incluida la semana en curso. */
    const TIMELINE_WEEKS = 12;

    /** @var float Diferencia minima (en puntos de 5) para decir que una dimension es mas debil que las demas. */
    const DIM_MIN_GAP = 0.25;

    /**
     * Semanas de la linea de tiempo, de la mas antigua a la actual.
     *
     * Cada semana se identifica por el lunes que la abre. La ventana termina
     * en la semana en curso: lo que interesa de estos indicadores es si el
     * modulo se esta usando ahora, no solo cuanto se uso en total.
     *
     * @param int $weeks
     * @return array Marcas de tiempo del lunes de cada semana.
     */
    public static function timeline_weeks(int $weeks = self::TIMELINE_WEEKS): array {
        $thisweek = strtotime('monday this week', time());
        $result = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $result[] = $thisweek - ($i * WEEKSECS);
        }
        return $result;
    }

    /**
     * Interacciones con el asistente por semana y por modo funcional.
     *
     * @param int $courseid
     * @param int $weeks
     * @return array ['weeks' => marcas de tiempo, 'series' => [modo => conteos por semana]]
     */
    public static function usage_timeline(int $courseid, int $weeks = self::TIMELINE_WEEKS): array {
        global $DB;

        $weekstarts = self::timeline_weeks($weeks);
        $origin = $weekstarts[0];

        $series = [];
        foreach ([assistant::MODE_TUTOR, assistant::MODE_COACH, assistant::MODE_EVALUADOR] as $mode) {
            $series[$mode] = array_fill(0, $weeks, 0);
        }

        $rows = $DB->get_recordset_select('local_chatbot_interaction',
            'courseid = :courseid AND timecreated >= :origin',
            ['courseid' => $courseid, 'origin' => $origin],
            '', 'id, mode, timecreated');
        foreach ($rows as $row) {
            $bucket = (int) floor(($row->timecreated - $origin) / WEEKSECS);
            if ($bucket >= 0 && $bucket < $weeks && isset($series[$row->mode])) {
                $series[$row->mode][$bucket]++;
            }
        }
        $rows->close();

        return ['weeks' => $weekstarts, 'series' => $series];
    }

    /**
     * Personas acumuladas en cada etapa del recorrido, semana a semana.
     *
     * Acumulado y no conteo semanal: la pregunta de gestion es cuanta gente
     * lleva hecho cada hito, no cuanta lo hizo justo esa semana. Quien alcanzo
     * el hito antes de la ventana entra en el punto de partida, para que el
     * ultimo valor de cada linea coincida con el embudo.
     *
     * @param int $courseid
     * @param int $weeks
     * @return array ['weeks' => marcas de tiempo, 'series' => [etapa => acumulados]]
     */
    public static function route_timeline(int $courseid, int $weeks = self::TIMELINE_WEEKS): array {
        global $DB;

        $weekstarts = self::timeline_weeks($weeks);
        $origin = $weekstarts[0];
        $params = ['courseid' => $courseid];

        // Primera vez que cada persona alcanza cada hito. El proyecto no guarda
        // fecha de entrega propia, asi que se usa su ultima modificacion: es la
        // mejor aproximacion disponible y solo desplaza el punto, no el total.
        $milestones = [
            'perfilamiento' => $DB->get_records_sql(
                "SELECT userid, consenttime AS t
                   FROM {local_chatbot_profile}
                  WHERE courseid = :courseid AND consentaccepted = 1", $params),
            'diagnostico' => $DB->get_records_sql(
                "SELECT userid, MIN(timecreated) AS t
                   FROM {local_chatbot_diag_response}
                  WHERE courseid = :courseid AND stage = 'entrada'
               GROUP BY userid", $params),
            'pdp' => $DB->get_records_sql(
                "SELECT p.userid, MIN(g.timemodified) AS t
                   FROM {local_chatbot_pdp} p
                   JOIN {local_chatbot_pdp_goal} g ON g.pdpid = p.id
                  WHERE p.courseid = :courseid
                        AND g.status IN ('aceptado', 'modificado', 'completado')
                        AND (g.ronda IS NULL OR g.ronda = p.ronda)
               GROUP BY p.userid", $params),
            'proyecto' => $DB->get_records_sql(
                "SELECT userid, timemodified AS t
                   FROM {local_chatbot_project}
                  WHERE courseid = :courseid AND status IN (:entregado, :evaluado)",
                $params + ['entregado' => project::STATUS_ENTREGADO, 'evaluado' => project::STATUS_EVALUADO]),
            'cierre' => $DB->get_records_sql(
                "SELECT userid, MIN(timecreated) AS t
                   FROM {local_chatbot_diag_response}
                  WHERE courseid = :courseid AND stage = 'salida'
               GROUP BY userid", $params),
        ];

        $series = [];
        foreach ($milestones as $stage => $rows) {
            $perweek = array_fill(0, $weeks, 0);
            $before = 0;
            foreach ($rows as $row) {
                $time = (int) $row->t;
                $bucket = $time > 0 ? (int) floor(($time - $origin) / WEEKSECS) : -1;
                if ($bucket < 0) {
                    $before++;
                } else if ($bucket < $weeks) {
                    $perweek[$bucket]++;
                }
            }

            $running = $before;
            $series[$stage] = [];
            foreach ($perweek as $count) {
                $running += $count;
                $series[$stage][] = $running;
            }
        }

        return ['weeks' => $weekstarts, 'series' => $series];
    }

    /**
     * Curso que muestran los indicadores: con «Asistente solo en estos cursos» escrito, solo esos; un curso ajeno
     * vuelve al primero de la lista. Sin la lista, el curso pedido tal cual.
     *
     * @param int $pedido Curso de la direccion.
     * @return int
     */
    public static function dashboard_course(int $pedido): int {
        $ids = closure::parse_course_ids((string) get_config('local_chatbot', 'chatonlycourses'));
        return ($ids && !in_array($pedido, $ids, true)) ? $ids[0] : $pedido;
    }

    /**
     * La mayor caida entre dos etapas obligatorias consecutivas del embudo. Funcion pura.
     *
     * Solo cuenta si la etapa anterior tiene al menos 2 personas y la caida es de 30 % o mas, para no inventar
     * un problema donde hay una o dos personas. Las etapas opcionales no se comparan.
     *
     * @param array $etapas clave => personas con la etapa hecha.
     * @param string[] $keys Ruta vigente.
     * @param string[] $opcionales Claves opcionales.
     * @return array|null ['from' => clave, 'to' => clave, 'prev' => int, 'next' => int, 'pct' => int]
     */
    public static function biggest_drop(array $etapas, array $keys, array $opcionales): ?array {
        $obligatorias = array_values(array_filter($keys, fn($k) => !in_array($k, $opcionales, true)));
        $mejor = null;
        for ($i = 1; $i < count($obligatorias); $i++) {
            $antes = (int) ($etapas[$obligatorias[$i - 1]] ?? 0);
            $ahora = (int) ($etapas[$obligatorias[$i]] ?? 0);
            if ($antes < 2 || $ahora >= $antes) {
                continue;
            }
            $pct = (int) round(100 * ($antes - $ahora) / $antes);
            if ($pct >= 30 && ($mejor === null || $pct > $mejor['pct'])) {
                $mejor = ['from' => $obligatorias[$i - 1], 'to' => $obligatorias[$i], 'prev' => $antes, 'next' => $ahora, 'pct' => $pct];
            }
        }
        return $mejor;
    }

    /**
     * Conclusiones automaticas para quien mira el panel. Funcion pura, con reglas fijas y sin IA: cada frase se puede
     * comprobar contra los datos que se muestran debajo.
     *
     * Devuelve datos y no texto: la pagina arma cada frase con las cadenas de idioma `insight_<id>` y `insight_<id>_act`.
     *
     * @param array $d Con: started, finished, etapas (clave => int), keys, optional, stuck (stuck_counts()),
     *     goals (['total', 'pending']), gaps (filas con name, promedio, personas).
     * @param int $max Cuantas conclusiones devolver como maximo.
     * @return array Lista de ['id' => string, 'level' => alerta|aviso|ok|info, 'params' => array].
     */
    public static function insights(array $d, int $max = 5): array {
        $started = (int) ($d['started'] ?? 0);
        if ($started === 0) {
            return [['id' => 'none', 'level' => 'info', 'params' => []]];
        }
        $lista = [];

        $stuck = $d['stuck'] ?? ['etapas' => [], 'dias' => 7];
        $inactivas = array_sum(array_column($stuck['etapas'], 'inactivas'));
        if ($inactivas > 0) {
            $lista[] = ['id' => 'idle', 'level' => 'alerta', 'params' => ['n' => $inactivas, 'dias' => (int) $stuck['dias']]];
        }

        $top = null;
        foreach ($stuck['etapas'] as $clave => $fila) {
            if ($fila['total'] > 0 && ($top === null || $fila['total'] > $stuck['etapas'][$top]['total'])) {
                $top = $clave;
            }
        }
        if ($top !== null) {
            $lista[] = ['id' => 'stage', 'level' => 'aviso',
                'params' => ['etapa' => $top, 'n' => $stuck['etapas'][$top]['total'], 'started' => $started]];
        }

        $caida = self::biggest_drop($d['etapas'] ?? [], $d['keys'] ?? [], $d['optional'] ?? []);
        if ($caida !== null) {
            // Si nadie llego a la etapa siguiente se dice asi, no «solo 0 llegaron (100 % menos)».
            $lista[] = ['id' => $caida['next'] === 0 ? 'dropzero' : 'drop', 'level' => 'aviso', 'params' => $caida];
        }

        $metas = $d['goals'] ?? ['total' => 0, 'pending' => 0];
        if ((int) $metas['total'] >= 3 && 2 * (int) $metas['pending'] >= (int) $metas['total']) {
            $lista[] = ['id' => 'goals', 'level' => 'aviso', 'params' => ['n' => (int) $metas['pending'],
                'total' => (int) $metas['total'], 'pct' => (int) round(100 * $metas['pending'] / $metas['total'])]];
        }

        $dims = array_values(array_filter($d['gaps'] ?? [], fn($g) => (int) ($g['personas'] ?? 0) > 0));
        if (count($dims) >= 2) {
            usort($dims, fn($a, $b) => $a['promedio'] <=> $b['promedio']);
            // Solo hay una dimension «debil» si se separa de verdad del resto: al menos 0,25 puntos (de 5) por debajo del
            // promedio de las demas. Con diferencias de centesimas no hay nada que reforzar y decirlo seria inventar.
            $resto = array_slice($dims, 1);
            $promresto = array_sum(array_column($resto, 'promedio')) / count($resto);
            if ($promresto - $dims[0]['promedio'] >= self::DIM_MIN_GAP) {
                $lista[] = ['id' => 'dim', 'level' => 'info', 'params' => ['dim' => $dims[0]['name'], 'prom' => $dims[0]['promedio']]];
            }
        }

        $terminaron = (int) ($d['finished'] ?? 0);
        if ($terminaron > 0) {
            $lista[] = ['id' => 'done', 'level' => 'ok', 'params' => ['n' => $terminaron,
                'pct' => (int) round(100 * $terminaron / $started)]];
        }

        $orden = ['alerta' => 0, 'aviso' => 1, 'info' => 2, 'ok' => 3];
        usort($lista, fn($a, $b) => $orden[$a['level']] <=> $orden[$b['level']]);
        return array_slice($lista, 0, $max);
    }

    /**
     * Primera semana desde la que mostrar un grafico semanal: la primera con actividad en cualquier serie, pero siempre
     * quedan al menos `$minimo` semanas para que la linea tenga forma. Funcion pura.
     *
     * @param array[] $series Lista de listas de valores semanales.
     * @param int $minimo
     * @return int Indice de la primera semana a mostrar.
     */
    public static function first_active_week(array $series, int $minimo = 4): int {
        $semanas = $series ? count(reset($series)) : 0;
        $primera = $semanas;
        foreach ($series as $valores) {
            foreach (array_values($valores) as $i => $v) {
                if ($v > 0) {
                    $primera = min($primera, $i);
                    break;
                }
            }
        }
        return max(0, min($primera - 1, $semanas - $minimo));
    }

    /**
     * Lo que cambio en la ultima semana. Funcion pura.
     *
     * @param int[] $valores Un valor por semana.
     * @param bool $acumulado Si la serie es acumulada (cada punto incluye los anteriores) o por semana.
     * @return int
     */
    public static function week_delta(array $valores, bool $acumulado): int {
        $v = array_values($valores);
        $n = count($v);
        if ($n === 0) {
            return 0;
        }
        return $acumulado ? max(0, $v[$n - 1] - ($v[$n - 2] ?? 0)) : (int) $v[$n - 1];
    }

    /**
     * Explicacion en palabras simples de un indicador del Anexo G, por su nombre. Funcion pura.
     *
     * @param string $nombre Nombre tal como lo entrega annex_g_indicators().
     * @return string Cadena vacia si el indicador no tiene explicacion.
     */
    public static function plain_indicator(string $nombre): string {
        $texto = [
            'Tasa de uso de Tutor IA' => 'De las personas que empezaron, cuántas le han hecho al menos una pregunta al tutor.',
            'Solicitudes de quiz protegido' => 'Veces que alguien pidió las respuestas del quiz y el asistente no las dio, para cuidar la evaluación.',
            'Transferencias sectoriales' => 'Veces que alguien pidió aplicar lo aprendido a otro sector u oficio.',
            'Orientaciones hacia el Coach' => 'Veces que el tutor sugirió pasar a trabajar las metas del plan.',
            'Referencia a recursos' => 'Cuántas respuestas del tutor mandaron a leer la lectura, el caso o el ejemplo.',
            'Aceptación de apoyo IA' => 'De las actividades de apoyo que se propusieron, cuántas la persona realmente empezó.',
            'Tasa de evidencia conversacional' => 'De las metas activas, cuántas ya tienen una evidencia entregada.',
            'Tasa de evidencia revisada' => 'De las evidencias entregadas, cuántas revisó el asistente.',
            'Tasa de avance registrado' => 'De las metas activas, cuántas muestran avance después de una revisión.',
            'Tasa de profundización' => 'De las evidencias revisadas, cuántas necesitaron mejorar o profundizar.',
            'Propuesta de cierre' => 'De las evidencias revisadas, cuántas llegaron a un nivel para dar la meta por lograda.',
            'Cierre confirmado' => 'De las metas que el asistente propuso cerrar, cuántas confirmó la persona.',
            'Tiempo a primera evidencia' => 'Cuánto tarda una persona, desde que recibe la actividad, en entregar su primera evidencia.',
            'Respuestas valoradas como útiles' => 'De las respuestas que las personas valoraron, cuántas marcaron 👍.',
            'Recursos inventados por IA' => 'Veces que la IA mencionó un recurso que no existe. Debe ser 0 (se descartan antes de mostrarse).',
            'Cambios sin confirmación' => 'Decisiones sobre las metas tomadas por alguien distinto de la persona. Debe ser 0.',
            'Interacciones con datos no permitidos' => 'Veces que una evidencia traía datos personales de terceros y se bloqueó antes de llegar a la IA.',
            'Coherencia meta–actividad' => 'Cuántas actividades de apoyo están bien ligadas a una meta y a sus criterios.',
            'Coherencia evidencia–meta' => 'Cuántas revisiones se hicieron con los criterios de la meta.',
        ];
        return $texto[$nombre] ?? '';
    }

    /**
     * Nombre en lenguaje simple de un evento de trazabilidad del Anexo G. Funcion pura.
     *
     * @param string $codigo Por ejemplo EVT-IA-T-U1-CONSULT o U1-QF-START (con o sin el prefijo EVT-).
     * @return string Cadena vacia si no se reconoce (la pagina muestra solo el codigo).
     */
    public static function event_label(string $codigo): string {
        $c = preg_replace('/^EVT-/', '', strtoupper(trim($codigo)));
        $unidad = static function(string $u): string {
            return preg_match('/^U(\d+)$/', $u, $m) ? ' (Unidad ' . $m[1] . ')' : '';
        };
        if (preg_match('/^IA-T-(U\d+|UX)-(\w+)$/', $c, $m)) {
            $acciones = ['CONSULT' => 'Consulta al tutor', 'OPEN' => 'Abrieron el chat del tutor', 'CLOSE' => 'Cerraron el chat del tutor',
                'QUIZ-GUARD' => 'Pidieron respuestas del quiz (el asistente no las dio)', 'TRANSFER' => 'Pidieron aplicarlo a otro sector',
                'COACH-REF' => 'El tutor sugirió pasar al plan de metas'];
            return isset($acciones[$m[2]]) ? $acciones[$m[2]] . $unidad($m[1]) : '';
        }
        if (preg_match('/^IA-C-(U\d+|UX)-(\w+)$/', $c, $m)) {
            $acciones = ['DECISION' => 'Decisión sobre una meta propuesta', 'PROPOSE' => 'El asistente propuso una meta',
                'SUPPORT' => 'El asistente propuso una actividad de apoyo', 'REVIEW' => 'El asistente revisó una evidencia'];
            return isset($acciones[$m[2]]) ? $acciones[$m[2]] . $unidad($m[1]) : '';
        }
        $fijos = [
            'PDP-HISTORIAL-VIEW' => 'Consultaron el historial de su plan',
            'PDP-DECISION' => 'Decidieron sobre una meta (aceptar, modificar o rechazar)',
        ];
        if (isset($fijos[$c])) {
            return $fijos[$c];
        }
        if (preg_match('/^(U\d+)-(LF|CA|ER)-(VIEW|COMPLETE)$/', $c, $m)) {
            $recurso = ['LF' => 'la lectura', 'CA' => 'el caso', 'ER' => 'el ejemplo'][$m[2]];
            return ($m[3] === 'VIEW' ? 'Abrieron ' : 'Terminaron ') . $recurso . $unidad($m[1]);
        }
        if (preg_match('/^(U\d+)-QF-(START|SUBMIT|REVIEW|COMPLETE)$/', $c, $m)) {
            $acciones = ['START' => 'Empezaron el quiz', 'SUBMIT' => 'Enviaron el quiz', 'REVIEW' => 'Revisaron sus respuestas del quiz',
                'COMPLETE' => 'Terminaron el quiz'];
            return $acciones[$m[2]] . $unidad($m[1]);
        }
        return '';
    }

    /**
     * Personas con alguna actividad del recorrido en el curso (perfil, diagnostico, PDP o proyecto).
     *
     * @param int $courseid
     * @return int[]
     */
    public static function route_people(int $courseid): array {
        global $DB;
        $ids = [];
        foreach (['local_chatbot_profile', 'local_chatbot_diag_response', 'local_chatbot_pdp', 'local_chatbot_project'] as $tabla) {
            foreach ($DB->get_fieldset_sql("SELECT DISTINCT userid FROM {{$tabla}} WHERE courseid = ?", [$courseid]) as $uid) {
                $ids[(int) $uid] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * Avance por etapa con la ruta vigente y el mismo criterio que la barra de ruta de cada persona
     * (course_bridge::step_states()). Reemplaza en los indicadores a completion_funnel(), que contaba cinco
     * etapas con consultas propias: con la ruta de 6 faltaba la Ruta Formativa Base y los porcentajes podian
     * pasar de 100 (el diagnostico se contaba sin exigir el consentimiento vigente y la base si lo exigia).
     *
     * @param int $courseid
     * @return array ['keys' => ruta vigente, 'iniciaron' => int, 'etapas' => clave => int]
     */
    public static function route_funnel(int $courseid): array {
        $keys = course_bridge::route_keys();
        $estados = [];
        foreach (self::route_people($courseid) as $uid) {
            $estados[$uid] = course_bridge::step_states($uid, $courseid);
        }
        return ['keys' => $keys] + self::funnel_counts($estados, $keys);
    }

    /**
     * Cuenta cuantas personas tienen hecha cada etapa. Funcion pura.
     *
     * «Iniciaron» son quienes tienen al menos una etapa hecha: asi ninguna etapa supera el 100 %.
     *
     * @param array $estados Persona => (clave => bool), como lo devuelve step_states().
     * @param string[] $keys Ruta vigente.
     * @return array ['iniciaron' => int, 'etapas' => clave => int]
     */
    public static function funnel_counts(array $estados, array $keys): array {
        $etapas = array_fill_keys($keys, 0);
        $iniciaron = 0;
        foreach ($estados as $estado) {
            $alguna = false;
            foreach ($keys as $clave) {
                if (!empty($estado[$clave])) {
                    $etapas[$clave]++;
                    $alguna = true;
                }
            }
            if ($alguna) {
                $iniciaron++;
            }
        }
        return ['iniciaron' => $iniciaron, 'etapas' => $etapas];
    }

    /**
     * Personas en cada etapa de la ruta vigente, semana a semana (acumulado).
     *
     * Solo cuenta a quien tiene la etapa hecha segun step_states(), asi el ultimo punto de cada linea
     * coincide con route_funnel(). La fecha es la primera en que la etapa quedo hecha; si no se puede
     * saber, la persona entra en el punto de partida.
     *
     * @param int $courseid
     * @param int $weeks
     * @return array ['weeks' => marcas de tiempo, 'keys' => ruta, 'series' => clave => acumulados]
     */
    public static function route_steps_timeline(int $courseid, int $weeks = self::TIMELINE_WEEKS): array {
        global $DB;
        $weekstarts = self::timeline_weeks($weeks);
        $keys = course_bridge::route_keys();
        $p = ['courseid' => $courseid];
        $fechas = [
            'perfil' => $DB->get_records_sql_menu("SELECT userid, MAX(consenttime) FROM {local_chatbot_profile}
                WHERE courseid = :courseid GROUP BY userid", $p),
            'diagnostico' => $DB->get_records_sql_menu("SELECT userid, MIN(timecreated) FROM {local_chatbot_diag_response}
                WHERE courseid = :courseid GROUP BY userid", $p),
            'pdp' => $DB->get_records_sql_menu("SELECT p.userid, MIN(g.timemodified) FROM {local_chatbot_pdp} p
                JOIN {local_chatbot_pdp_goal} g ON g.pdpid = p.id
                WHERE p.courseid = :courseid AND g.status IN ('aceptado', 'modificado', 'completado')
                      AND (g.ronda IS NULL OR g.ronda = p.ronda) GROUP BY p.userid", $p),
            'proyecto' => $DB->get_records_sql_menu("SELECT userid, MAX(timemodified) FROM {local_chatbot_project}
                WHERE courseid = :courseid GROUP BY userid", $p),
            'cierre' => $DB->get_records_sql_menu("SELECT userid, MAX(timemodified) FROM {local_chatbot_closure}
                WHERE courseid = :courseid GROUP BY userid", $p),
        ];
        $tiempos = array_fill_keys($keys, []);
        foreach (self::route_people($courseid) as $uid) {
            $estado = course_bridge::step_states($uid, $courseid);
            foreach ($keys as $clave) {
                if (empty($estado[$clave])) {
                    continue;
                }
                $tiempos[$clave][] = $clave === 'ruta'
                    ? self::ruta_time($uid, $courseid)
                    : (int) ($fechas[$clave][$uid] ?? 0);
            }
        }
        $series = [];
        foreach ($keys as $clave) {
            $series[$clave] = self::cumulative_weeks($tiempos[$clave], $weekstarts[0], $weeks);
        }
        return ['weeks' => $weekstarts, 'keys' => $keys, 'series' => $series];
    }

    /**
     * Acumulado semanal de una lista de fechas. Funcion pura.
     *
     * Una fecha anterior a la ventana (o desconocida, 0) entra en el punto de partida; una posterior, no cuenta.
     *
     * @param int[] $tiempos
     * @param int $origen Inicio de la primera semana.
     * @param int $weeks
     * @return int[] Un valor por semana.
     */
    public static function cumulative_weeks(array $tiempos, int $origen, int $weeks): array {
        $porsemana = array_fill(0, $weeks, 0);
        $antes = 0;
        foreach ($tiempos as $t) {
            // 7 * 86400 y no WEEKSECS: es funcion pura y el verificador corre sin Moodle.
            $semana = $t > 0 ? (int) floor(($t - $origen) / (7 * 86400)) : -1;
            if ($semana < 0) {
                $antes++;
            } else if ($semana < $weeks) {
                $porsemana[$semana]++;
            }
        }
        $acumulado = $antes;
        $serie = [];
        foreach ($porsemana as $n) {
            $acumulado += $n;
            $serie[] = $acumulado;
        }
        return $serie;
    }

    /**
     * Cuando una persona termino la Ruta Formativa Base: la fecha en que recorrio el ultimo de sus recursos
     * (lectura, caso y ejemplo al abrirse o completarse; el quiz al terminar un intento), con el mismo criterio
     * que course_bridge::ruta_resources().
     *
     * @param int $userid
     * @param int $courseid
     * @return int Marca de tiempo, o 0 si no se puede saber.
     */
    protected static function ruta_time(int $userid, int $courseid): int {
        global $DB;
        $recursos = course_bridge::ruta_resources($userid, $courseid);
        if (!$recursos) {
            return 0;
        }
        $primeros = $DB->get_records_sql_menu("SELECT eventcode, MIN(timecreated) FROM {local_chatbot_trace}
            WHERE userid = ? AND courseid = ? AND eventcode LIKE 'EVT-U%' GROUP BY eventcode", [$userid, $courseid]);
        $cms = get_fast_modinfo($courseid)->cms;
        $ultimo = 0;
        foreach ($recursos as $r) {
            $unidad = explode('-', $r['code'])[0];
            $sufijos = $r['kind'] === 'QF' ? ['SUBMIT', 'COMPLETE'] : ['VIEW', 'COMPLETE'];
            $t = 0;
            foreach ($sufijos as $sufijo) {
                $e = (int) ($primeros["EVT-{$unidad}-{$r['kind']}-{$sufijo}"] ?? 0);
                if ($e > 0 && ($t === 0 || $e < $t)) {
                    $t = $e;
                }
            }
            if ($r['kind'] === 'QF' && isset($cms[$r['cmid']])) {
                $q = (int) $DB->get_field_sql("SELECT MIN(timefinish) FROM {quiz_attempts}
                    WHERE quiz = ? AND userid = ? AND state = 'finished'", [$cms[$r['cmid']]->instance, $userid]);
                if ($q > 0 && ($t === 0 || $q < $t)) {
                    $t = $q;
                }
            }
            $ultimo = max($ultimo, $t);
        }
        return $ultimo;
    }

    /**
     * Donde se detienen las personas: en que etapa obligatoria esta cada una y cuantas llevan dias sin actividad.
     *
     * @param int $courseid
     * @param int $dias Dias sin actividad para considerar a alguien detenido.
     * @return array Ver stuck_counts().
     */
    public static function route_stuck(int $courseid, int $dias = 7): array {
        global $DB;
        $keys = course_bridge::route_keys();
        $opcionales = array_map(fn($pos) => $keys[$pos - 1], journey::optional_positions($keys));
        $estados = [];
        foreach (self::route_people($courseid) as $uid) {
            $estados[$uid] = course_bridge::step_states($uid, $courseid);
        }
        return self::stuck_counts($estados, $keys, $opcionales, self::last_activity($courseid), time(), $dias);
    }

    /**
     * Ultima actividad de cada persona en el curso: lo mas reciente entre trazas, mensajes al asistente y respuestas
     * del diagnostico.
     *
     * @param int $courseid
     * @return int[] Persona => marca de tiempo.
     */
    public static function last_activity(int $courseid): array {
        global $DB;
        $ultimas = [];
        foreach (['local_chatbot_trace', 'local_chatbot_messages', 'local_chatbot_diag_response'] as $tabla) {
            foreach ($DB->get_records_sql_menu("SELECT userid, MAX(timecreated) FROM {{$tabla}}
                    WHERE courseid = ? GROUP BY userid", [$courseid]) as $uid => $t) {
                $ultimas[(int) $uid] = max($ultimas[(int) $uid] ?? 0, (int) $t);
            }
        }
        return $ultimas;
    }

    /**
     * Quienes estan detenidos: nombre, etapa obligatoria pendiente y dias sin actividad. SOLO para quien gestiona el
     * curso y solo si el ajuste «dashpeople» esta encendido: es la unica parte del panel que no es anonima, y nunca va
     * a la exportacion ni al informe PDF.
     *
     * @param int $courseid
     * @param int $limite
     * @return array Lista de ['userid', 'nombre', 'etapa', 'dias' (null si nunca tuvo actividad)], de mas a menos dias.
     */
    public static function stuck_people(int $courseid, int $limite = 30): array {
        global $DB;
        if (!get_config('local_chatbot', 'dashpeople')) {
            return [];
        }
        $keys = course_bridge::route_keys();
        $opcionales = array_map(fn($pos) => $keys[$pos - 1], journey::optional_positions($keys));
        $obligatorias = array_values(array_filter($keys, fn($k) => !in_array($k, $opcionales, true)));
        $ultimas = self::last_activity($courseid);
        $ahora = time();
        $filas = [];
        foreach (self::route_people($courseid) as $uid) {
            $estado = course_bridge::step_states($uid, $courseid);
            if (!array_filter($estado)) {
                continue;
            }
            $pendiente = null;
            foreach ($obligatorias as $clave) {
                if (empty($estado[$clave])) {
                    $pendiente = $clave;
                    break;
                }
            }
            if ($pendiente === null) {
                continue;
            }
            $u = $DB->get_record('user', ['id' => $uid, 'deleted' => 0]);
            if (!$u) {
                continue;
            }
            $t = (int) ($ultimas[$uid] ?? 0);
            $filas[] = ['userid' => $uid, 'nombre' => fullname($u), 'etapa' => $pendiente,
                'dias' => $t > 0 ? (int) floor(($ahora - $t) / 86400) : null];
        }
        usort($filas, fn($a, $b) => ($b['dias'] ?? PHP_INT_MAX) <=> ($a['dias'] ?? PHP_INT_MAX));
        return array_slice($filas, 0, $limite);
    }

    /**
     * Cuenta en que etapa obligatoria pendiente esta cada persona que inicio, y cuantas llevan mas de N dias sin
     * actividad. Funcion pura.
     *
     * La etapa de una persona es la primera obligatoria que le falta, en el orden de la ruta. Quien tiene todas las
     * obligatorias hechas cuenta como «terminaron». Quien no tiene ninguna etapa hecha no inicio y no cuenta.
     *
     * @param array $estados Persona => (clave => bool).
     * @param string[] $keys Ruta vigente.
     * @param string[] $opcionales Claves opcionales (no detienen a nadie).
     * @param array $ultimas Persona => marca de tiempo de su ultima actividad.
     * @param int $ahora
     * @param int $dias
     * @return array ['etapas' => clave => ['total' => int, 'inactivas' => int], 'terminaron' => int, 'dias' => int]
     */
    public static function stuck_counts(array $estados, array $keys, array $opcionales, array $ultimas,
            int $ahora, int $dias): array {
        $etapas = [];
        foreach ($keys as $clave) {
            if (!in_array($clave, $opcionales, true)) {
                $etapas[$clave] = ['total' => 0, 'inactivas' => 0];
            }
        }
        $terminaron = 0;
        foreach ($estados as $uid => $estado) {
            if (!array_filter($estado)) {
                continue;
            }
            $pendiente = null;
            foreach (array_keys($etapas) as $clave) {
                if (empty($estado[$clave])) {
                    $pendiente = $clave;
                    break;
                }
            }
            if ($pendiente === null) {
                $terminaron++;
                continue;
            }
            $etapas[$pendiente]['total']++;
            $ultima = (int) ($ultimas[$uid] ?? 0);
            if ($ultima === 0 || $ahora - $ultima > $dias * 86400) {
                $etapas[$pendiente]['inactivas']++;
            }
        }
        return ['etapas' => $etapas, 'terminaron' => $terminaron, 'dias' => $dias];
    }

    /**
     * Indicadores del Modulo 2 (ficha ex-ante, diseño en 6 etapas y evaluacion con la rubrica), por curso.
     *
     * Solo agrega: cuantas personas, en que condicion, que items fallan mas. Ningun nombre.
     *
     * @return array Lista de cursos con: courseid, nombre, fichas, condiciones (aprobado/observaciones/no_aprobado),
     *               items (los que mas fallan), etapas (1 a 7 => personas con la etapa aceptada), evaluadas,
     *               aprobadas, promedio.
     */
    public static function module2_overview(): array {
        global $DB;
        // Un servidor sin el Modulo 2 todavia (sin su codigo o sin sus tablas) muestra la pestaña vacia, no un error.
        if (!class_exists(exante::class) || !class_exists(diseno::class)
                || !$DB->get_manager()->table_exists('local_chatbot_exante')
                || !$DB->get_manager()->table_exists('local_chatbot_diseno')) {
            return [];
        }
        $cursos = $DB->get_fieldset_sql("SELECT DISTINCT courseid FROM {local_chatbot_exante} WHERE courseid > 0");
        $textos = [];
        foreach (exante::checklist() as $seccion) {
            foreach ($seccion['items'] as $id => $texto) {
                $textos[$id] = $texto;
            }
        }
        $resultado = [];
        foreach ($cursos as $cid) {
            $cid = (int) $cid;
            // La validacion vigente de cada persona es la ultima terminada.
            $ultimas = [];
            foreach ($DB->get_records_select('local_chatbot_exante_val', 'courseid = ? AND estado = ?',
                    [$cid, exante::LISTO], 'id ASC', 'id, userid, condicion, informe') as $v) {
                $ultimas[(int) $v->userid] = $v;
            }
            $condiciones = [exante::APROBADO => 0, exante::OBSERVACIONES => 0, exante::NO_APROBADO => 0];
            $itemsporpersona = [];
            foreach ($ultimas as $v) {
                if (isset($condiciones[$v->condicion])) {
                    $condiciones[$v->condicion]++;
                }
                $informe = (array) json_decode((string) $v->informe, true);
                $itemsporpersona[] = array_map(fn($i) => (string) ($i['estado'] ?? ''), (array) ($informe['items'] ?? []));
            }
            $items = [];
            foreach (self::top_failing($itemsporpersona, 5) as $id => $n) {
                $items[] = ['id' => $id, 'texto' => $textos[$id] ?? $id, 'fallan' => $n,
                    'critico' => in_array($id, exante::CRITICOS, true)];
            }
            $etapas = array_fill(1, diseno::CONSOLIDACION, 0);
            $evaluadas = 0;
            $aprobadas = 0;
            $suma = 0;
            foreach ($DB->get_records('local_chatbot_diseno', ['courseid' => $cid], '', 'id, userid, etapa, estado, informe')
                    as $fila) {
                $n = (int) $fila->etapa;
                if ($n >= 1 && $n <= diseno::CONSOLIDACION && $fila->estado === diseno::ACEPTADO) {
                    $etapas[$n]++;
                }
                if ($n === diseno::EVALUACION && $fila->informe) {
                    $informe = (array) json_decode((string) $fila->informe, true);
                    if (isset($informe['total'])) {
                        $evaluadas++;
                        $suma += (int) $informe['total'];
                        if ((int) $informe['total'] >= diseno::APROBADO_DESDE) {
                            $aprobadas++;
                        }
                    }
                }
            }
            $resultado[] = ['courseid' => $cid, 'nombre' => (string) $DB->get_field('course', 'fullname', ['id' => $cid]),
                'fichas' => $DB->count_records_select('local_chatbot_exante', 'courseid = ?', [$cid]),
                'validadas' => count($ultimas), 'condiciones' => $condiciones, 'items' => $items, 'etapas' => $etapas,
                'evaluadas' => $evaluadas, 'aprobadas' => $aprobadas,
                'promedio' => $evaluadas > 0 ? round($suma / $evaluadas, 1) : null];
        }
        return $resultado;
    }

    /**
     * Items del checklist que mas personas no cumplen o cumplen a medias, de mas a menos. Funcion pura.
     *
     * @param array $itemsporpersona Lista de (id => estado) por persona.
     * @param int $n Cuantos devolver.
     * @return array id => cantidad de personas.
     */
    public static function top_failing(array $itemsporpersona, int $n): array {
        $cuenta = [];
        foreach ($itemsporpersona as $items) {
            foreach ($items as $id => $estado) {
                if (in_array($estado, ['no_cumple', 'parcial'], true)) {
                    $cuenta[$id] = ($cuenta[$id] ?? 0) + 1;
                }
            }
        }
        // De mas a menos; a igual cantidad, en el orden del checklist (c1_1, c1_2, c2_1...).
        uksort($cuenta, fn($a, $b) => ($cuenta[$b] <=> $cuenta[$a]) ?: strnatcmp((string) $a, (string) $b));
        return array_slice($cuenta, 0, $n, true);
    }

    /**
     * Embudo de avance por componente del modulo.
     *
     * @param int $courseid
     * @return array Mapa etapa => cantidad de personas.
     */
    public static function completion_funnel(int $courseid): array {
        global $DB;

        $params = ['courseid' => $courseid];

        return [
            'perfilamiento' => $DB->count_records('local_chatbot_profile',
                ['courseid' => $courseid, 'consentaccepted' => 1]),
            'diagnostico' => $DB->count_records_sql(
                "SELECT COUNT(DISTINCT userid) FROM {local_chatbot_diag_response}
                  WHERE courseid = :courseid AND stage = 'entrada'", $params),
            'pdp' => $DB->count_records_sql(
                "SELECT COUNT(DISTINCT p.userid)
                   FROM {local_chatbot_pdp} p
                   JOIN {local_chatbot_pdp_goal} g ON g.pdpid = p.id
                  WHERE p.courseid = :courseid AND g.status IN ('aceptado', 'modificado', 'completado')
                        AND (g.ronda IS NULL OR g.ronda = p.ronda)",
                $params),
            'proyecto' => $DB->count_records_select('local_chatbot_project',
                'courseid = :courseid AND status IN (:entregado, :evaluado)',
                ['courseid' => $courseid, 'entregado' => project::STATUS_ENTREGADO,
                 'evaluado' => project::STATUS_EVALUADO]),
            'cierre' => $DB->count_records_sql(
                "SELECT COUNT(DISTINCT userid) FROM {local_chatbot_diag_response}
                  WHERE courseid = :courseid AND stage = 'salida'", $params),
        ];
    }

    /**
     * Distribucion de brechas por dimension diagnostica: cuantas personas
     * quedan en cada nivel, sin decir quienes.
     *
     * @param int $courseid
     * @param string $stage
     * @return array Filas con name, una columna por nivel de orientacion (RU-COA-NIVEL-001) y promedio del grupo.
     */
    public static function gap_distribution(int $courseid, string $stage = diagnostic::STAGE_ENTRADA): array {
        global $DB;

        // El nivel de cada persona se calcula con el mismo puntaje compuesto que
        // ve ella en su matriz de brechas (RU-CALC-001). Promediar todas las
        // respuestas juntas daria otro numero: los items situacionales guardan
        // acierto, no puntaje de escala, y entrarian al promedio como ceros.
        // Que el indicador agregado y la pantalla individual clasifiquen
        // distinto a la misma persona seria peor que no tener el indicador.
        $sql = "SELECT d.id, d.name, d.sortorder, r.userid,
                       AVG(CASE WHEN r.kind = :kinddecl THEN r.score END) AS declarada,
                       SUM(CASE WHEN r.kind = :kindsit AND r.iscorrect = 1 THEN 1 ELSE 0 END) AS aciertos,
                       SUM(CASE WHEN r.kind = :kindsit2 AND r.iscorrect >= 0 THEN 1 ELSE 0 END) AS situacionales
                  FROM {local_chatbot_diag_response} r
                  JOIN {local_chatbot_dimension} d ON d.id = r.dimensionid
                 WHERE r.courseid = :courseid AND r.stage = :stage
              GROUP BY d.id, d.name, d.sortorder, r.userid
              ORDER BY d.sortorder ASC";

        $rows = $DB->get_recordset_sql($sql, [
            'courseid' => $courseid,
            'stage' => $stage,
            'kinddecl' => diagnostic::KIND_DECLARATIVA,
            'kindsit' => diagnostic::KIND_SITUACIONAL,
            'kindsit2' => diagnostic::KIND_SITUACIONAL,
        ]);

        $bydimension = [];
        foreach ($rows as $row) {
            if ($row->declarada === null) {
                // La persona solo respondio situacionales en esta dimension: sin
                // practica declarada no hay compuesto que calcular.
                continue;
            }
            if (!isset($bydimension[$row->id])) {
                $bydimension[$row->id] = [
                    'name' => $row->name,
                    diagnostic::LEVEL_PRIORIDAD_ALTA => 0,
                    diagnostic::LEVEL_EN_DESARROLLO => 0,
                    diagnostic::LEVEL_FUNCIONAL => 0,
                    diagnostic::LEVEL_CONSOLIDADO => 0,
                    'suma' => 0.0,
                    'personas' => 0,
                ];
            }

            $situacionales = (int) $row->situacionales;
            $proporcion = $situacionales > 0 ? ((int) $row->aciertos / $situacionales) : null;
            $compuesto = diagnostic::composite_score((float) $row->declarada, $proporcion)['pc'];

            $bydimension[$row->id][diagnostic::level_for_score($compuesto)]++;
            $bydimension[$row->id]['suma'] += $compuesto;
            $bydimension[$row->id]['personas']++;
        }
        $rows->close();

        $result = [];
        foreach ($bydimension as $entry) {
            $entry['promedio'] = $entry['personas'] > 0
                ? round($entry['suma'] / $entry['personas'], 2) : 0;
            unset($entry['suma']);
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Variacion entre el diagnostico de entrada y el contraste de salida, a
     * nivel de grupo.
     *
     * @param int $courseid
     * @return array Filas con name, entrada, salida y variacion.
     */
    public static function progress_variation(int $courseid): array {
        $entrada = self::gap_distribution($courseid, diagnostic::STAGE_ENTRADA);
        $salida = self::gap_distribution($courseid, diagnostic::STAGE_SALIDA);

        if (empty($entrada) || empty($salida)) {
            return [];
        }

        $salidabyname = [];
        foreach ($salida as $row) {
            $salidabyname[$row['name']] = $row['promedio'];
        }

        $result = [];
        foreach ($entrada as $row) {
            if (!isset($salidabyname[$row['name']])) {
                continue;
            }
            $result[] = [
                'name' => $row['name'],
                'entrada' => $row['promedio'],
                'salida' => $salidabyname[$row['name']],
                'variacion' => round($salidabyname[$row['name']] - $row['promedio'], 2),
            ];
        }

        return $result;
    }

    /**
     * Que pasa con las metas que propone el asistente: cuantas se aceptan,
     * cuantas se modifican y cuantas se descartan. Es el indicador que mide si
     * las recomendaciones son utiles o solo abundantes.
     *
     * @param int $courseid
     * @return array Mapa estado => cantidad.
     */
    public static function pdp_goal_outcomes(int $courseid): array {
        global $DB;

        $sql = "SELECT g.status, COUNT(g.id) AS total
                  FROM {local_chatbot_pdp_goal} g
                  JOIN {local_chatbot_pdp} p ON p.id = g.pdpid
                 WHERE p.courseid = :courseid AND g.origin = :origin
                       AND (g.ronda IS NULL OR g.ronda = p.ronda)
              GROUP BY g.status";

        $rows = $DB->get_records_sql($sql, ['courseid' => $courseid, 'origin' => pdp::ORIGIN_IA]);

        // Postergado va al final para no alterar el orden de las claves que ya
        // consume suite_export; sin el, las metas postergadas desaparecian del
        // indicador y los totales no cuadraban con los PDP.
        $outcomes = [
            pdp::GOAL_PROPUESTO => 0,
            pdp::GOAL_ACEPTADO => 0,
            pdp::GOAL_MODIFICADO => 0,
            pdp::GOAL_RECHAZADO => 0,
            pdp::GOAL_COMPLETADO => 0,
            pdp::GOAL_POSTERGADO => 0,
        ];

        foreach ($rows as $row) {
            if (isset($outcomes[$row->status])) {
                $outcomes[$row->status] = (int) $row->total;
            }
        }

        return $outcomes;
    }

    /**
     * Interacciones con el asistente por modo funcional.
     *
     * @param int $courseid
     * @return array Filas con mode, total, aceptadas, rechazadas y el resto de
     *     decisiones (modificadas, postergadas, pendientes), para que los
     *     totales cuadren.
     */
    public static function interactions_by_mode(int $courseid): array {
        global $DB;

        $sql = "SELECT mode,
                       COUNT(id) AS total,
                       SUM(CASE WHEN useraccepted = 1 THEN 1 ELSE 0 END) AS aceptadas,
                       SUM(CASE WHEN useraccepted = 0 THEN 1 ELSE 0 END) AS rechazadas,
                       SUM(CASE WHEN useraccepted = 2 THEN 1 ELSE 0 END) AS modificadas,
                       SUM(CASE WHEN useraccepted = 3 THEN 1 ELSE 0 END) AS postergadas,
                       SUM(CASE WHEN useraccepted = -1 THEN 1 ELSE 0 END) AS pendientes
                  FROM {local_chatbot_interaction}
                 WHERE courseid = :courseid
              GROUP BY mode";

        $rows = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'mode' => $row->mode,
                'total' => (int) $row->total,
                'aceptadas' => (int) $row->aceptadas,
                'rechazadas' => (int) $row->rechazadas,
                'modificadas' => (int) $row->modificadas,
                'postergadas' => (int) $row->postergadas,
                'pendientes' => (int) $row->pendientes,
            ];
        }

        return $result;
    }

    /**
     * Brechas persistentes tras la retroalimentacion del proyecto: en que
     * criterios de rubrica se concentran las observaciones de mejora y cuantas
     * veces la IA declaro que hacia falta revision humana.
     *
     * @param int $courseid
     * @return array Filas con criterio, observaciones y revisionhumana.
     */
    public static function rubric_hotspots(int $courseid): array {
        global $DB;

        $sql = "SELECT c.id, c.code, c.name,
                       COUNT(f.id) AS observaciones,
                       SUM(f.needshuman) AS revisionhumana
                  FROM {local_chatbot_project_fb} f
                  JOIN {local_chatbot_criterion} c ON c.id = f.criterionid
                  JOIN {local_chatbot_project} p ON p.id = f.projectid
                 WHERE p.courseid = :courseid AND f.mejora <> :vacio
              GROUP BY c.id, c.code, c.name
              ORDER BY observaciones DESC";

        $rows = $DB->get_records_sql($sql, ['courseid' => $courseid, 'vacio' => '']);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'criterio' => $row->code . ' · ' . $row->name,
                'observaciones' => (int) $row->observaciones,
                'revisionhumana' => (int) $row->revisionhumana,
            ];
        }

        return $result;
    }

    /**
     * Indicadores del Anexo G §11.2 (tutoria), §11.3 (desarrollo adaptativo y PDP) y §11.4
     * (coherencia y calidad del asistente). Los nombres son los del anexo.
     *
     * @param int $courseid 0 = todos los cursos.
     * @return array Con claves tutoria, desarrollo y calidad (filas [nombre, valor, definicion])
     *     y conceptos (etiqueta => consultas).
     */
    public static function annex_g_indicators(int $courseid): array {
        global $DB;

        $c = $courseid > 0 ? 'courseid = :courseid' : '1 = 1';
        $pc = $courseid > 0 ? 'p.courseid = :courseid' : '1 = 1';
        $ac = $courseid > 0 ? 'a.courseid = :courseid' : '1 = 1';
        $params = $courseid > 0 ? ['courseid' => $courseid] : [];
        $pct = function(int $part, int $whole): string {
            return $whole > 0 ? round(100 * $part / $whole) . ' % (' . $part . '/' . $whole . ')' : 'sin datos';
        };
        $trace = function(string $pattern) use ($DB, $c, $params): int {
            return (int) $DB->count_records_select('local_chatbot_trace', "$c AND " . $DB->sql_like('eventcode', ':ev'),
                $params + ['ev' => $pattern]);
        };

        // Tutoria (§11.2).
        $activos = (int) $DB->count_records_select('local_chatbot_profile', "$c AND consentaccepted = 1", $params);
        $contutor = (int) $DB->get_field_sql("SELECT COUNT(DISTINCT userid) FROM {local_chatbot_interaction}
            WHERE $c AND mode = 'tutor'", $params);
        $tutorturnos = (int) $DB->count_records_select('local_chatbot_interaction', "$c AND mode = 'tutor'", $params);
        $conref = (int) $DB->count_records_select('local_chatbot_interaction',
            "$c AND mode = 'tutor' AND sourcesreferred IS NOT NULL AND sourcesreferred <> ''", $params);
        $conceptos = [];
        foreach ($DB->get_fieldset_select('local_chatbot_interaction', 'concepttags',
                "$c AND mode = 'tutor' AND concepttags IS NOT NULL AND concepttags <> ''", $params) as $tags) {
            foreach (explode(',', (string) $tags) as $tag) {
                $conceptos[$tag] = ($conceptos[$tag] ?? 0) + 1;
            }
        }
        arsort($conceptos);
        $tutoria = [
            ['Tasa de uso de Tutor IA', $pct($contutor, $activos),
                'Personas con al menos una interacción Tutor / personas activas'],
            ['Solicitudes de quiz protegido', (string) $trace('EVT-IA-T-%-QUIZ-GUARD'),
                'Eventos QUIZ-GUARD: se aplicó la regla de integridad del quiz'],
            ['Transferencias sectoriales', (string) $trace('EVT-IA-T-%-TRANSFER'),
                'Consultas de transferencia de la lógica de diseño a otro sector'],
            ['Orientaciones hacia el Coach', (string) $trace('EVT-IA-T-%-COACH-REF'),
                'Respuestas del Tutor que orientan el trabajo de metas hacia el Coach'],
            ['Referencia a recursos', $pct($conref, $tutorturnos),
                'Interacciones del Tutor que remiten a lectura, caso o ejemplo'],
        ];

        // Desarrollo adaptativo y PDP (§11.3).
        $apoyos = (int) $DB->count_records_select('local_chatbot_adapt', $c, $params);
        $iniciados = (int) $DB->count_records_select('local_chatbot_adapt', "$c AND timestarted > 0", $params);
        $activas = (int) $DB->get_field_sql("SELECT COUNT(g.id) FROM {local_chatbot_pdp_goal} g
            JOIN {local_chatbot_pdp} p ON p.id = g.pdpid
            WHERE $pc AND p.status = 'validado' AND g.status IN ('aceptado', 'modificado', 'completado')
            AND (g.ronda IS NULL OR g.ronda = p.ronda)", $params);
        $conevidencia = (int) $DB->get_field_sql("SELECT COUNT(DISTINCT a.goalid) FROM {local_chatbot_adapt} a
            JOIN {local_chatbot_adapt_review} r ON r.adaptid = a.id WHERE $ac", $params);
        $revisiones = $DB->get_records_sql("SELECT r.id, r.rejected, r.reviewstatus, r.proposal, r.personconfirmed,
            r.criteriareviewed, r.timedelivered, a.goalid, a.timegenerated
            FROM {local_chatbot_adapt_review} r JOIN {local_chatbot_adapt} a ON a.id = r.adaptid WHERE $ac", $params);
        $validas = array_filter($revisiones, fn($r) => !(int) $r->rejected);
        $avance = count(array_unique(array_map(fn($r) => $r->goalid,
            array_filter($validas, fn($r) => $r->reviewstatus === 'avance_registrado'))));
        $profundiza = count(array_filter($validas, fn($r) => $r->reviewstatus === 'requiere_profundizacion'));
        $cierres = array_filter($validas, fn($r) => $r->proposal === 'cerrar_meta');
        $confirmados = count(array_filter($cierres, fn($r) => (int) $r->personconfirmed === 1));
        $primeras = [];
        foreach ($validas as $r) {
            $gap = (int) $r->timedelivered - (int) $r->timegenerated;
            if ($gap >= 0 && (!isset($primeras[$r->goalid]) || $gap < $primeras[$r->goalid])) {
                $primeras[$r->goalid] = $gap;
            }
        }
        $primeras = array_values($primeras);
        sort($primeras);
        $mediana = empty($primeras) ? 'sin datos'
            : round($primeras[intdiv(count($primeras), 2)] / 3600, 1) . ' h (mediana)';
        $desarrollo = [
            ['Aceptación de apoyo IA', $pct($iniciados, $apoyos), 'Apoyos iniciados / apoyos propuestos'],
            ['Tasa de evidencia conversacional', $pct($conevidencia, $activas),
                'Metas con evidencia presentada / metas activas'],
            ['Tasa de evidencia revisada', $pct(count($validas), count($revisiones)),
                'Evidencias revisadas / evidencias presentadas (las bloqueadas por datos no permitidos no se revisan)'],
            ['Tasa de avance registrado', $pct($avance, $activas), 'Metas con avance registrado / metas activas'],
            ['Tasa de profundización', $pct($profundiza, count($validas)),
                'Revisiones que piden profundizar / evidencias revisadas'],
            ['Propuesta de cierre', $pct(count($cierres), count($validas)),
                'Revisiones que proponen cierre / evidencias revisadas'],
            ['Cierre confirmado', $pct($confirmados, count($cierres)),
                'Cierres confirmados por la persona / propuestas de cierre'],
            ['Tiempo a primera evidencia', $mediana, 'Desde la propuesta del apoyo hasta la primera evidencia'],
        ];

        // Coherencia y calidad IA (§11.4).
        $sinconfirmar = (int) $DB->get_field_sql("SELECT COUNT(h.id) FROM {local_chatbot_pdp_history} h
            JOIN {local_chatbot_pdp} p ON p.id = h.pdpid
            WHERE $pc AND h.changetype IN ('aceptado', 'modificado', 'postergado', 'rechazado', 'completado')
              AND h.userid <> p.userid", $params);
        $inventados = 0;
        foreach ($DB->get_fieldset_select('local_chatbot_trace', 'data',
                "$c AND eventcode = 'EVT-CALIDAD-RECURSO-INVENTADO'", $params) as $json) {
            $d = json_decode((string) $json, true);
            $inventados += (int) ($d['cantidad'] ?? 1);
        }
        $coherentes = (int) $DB->count_records_select('local_chatbot_adapt',
            "$c AND goalid > 0 AND dimensionid IS NOT NULL AND criterios IS NOT NULL AND criterios <> ''", $params);
        $concriterio = count(array_filter($validas, fn($r) => trim((string) $r->criteriareviewed) !== ''));
        $votos = $DB->get_records_sql_menu("SELECT f.vote, COUNT(f.id) FROM {local_chatbot_feedback} f
            JOIN {local_chatbot_interaction} i ON i.id = f.interactionid WHERE "
            . ($courseid > 0 ? 'i.courseid = :courseid' : '1 = 1') . " GROUP BY f.vote", $params);
        $calidad = [
            ['Respuestas valoradas como útiles', \local_chatbot\feedback::summary((int) ($votos[1] ?? 0), (int) ($votos[-1] ?? 0)),
                'Votos 👍 sobre el total de valoraciones de las respuestas del asistente'],
            ['Recursos inventados por IA', (string) $inventados,
                'Recursos nombrados por el modelo sin código activo en el catálogo (se descartan antes de mostrarse)'],
            ['Cambios sin confirmación', (string) $sinconfirmar,
                'Decisiones sobre metas registradas por alguien distinto de la persona. Debe ser 0'],
            ['Interacciones con datos no permitidos', (string) $trace('EVT-CALIDAD-DATOS-NO-PERMITIDOS'),
                'Evidencias con datos personales de terceros, bloqueadas antes de llegar al modelo'],
            ['Coherencia meta–actividad', $pct($coherentes, $apoyos),
                'Apoyos con meta, dimensión y criterios de verificación registrados'],
            ['Coherencia evidencia–meta', $pct($concriterio, count($validas)),
                'Evidencias revisadas con criterios aplicados registrados'],
        ];

        return ['tutoria' => $tutoria, 'desarrollo' => $desarrollo, 'calidad' => $calidad,
            'conceptos' => array_slice($conceptos, 0, 8, true)];
    }

    /**
     * Catalogo de eventos de trazabilidad registrados, por codigo y familia.
     *
     * El §11 del Anexo G define indicadores derivados; esto es la capa de
     * abajo: que se registro y cuantas veces. Es lo que permite auditar el
     * registro sin abrir la base de datos.
     *
     * @param int $courseid 0 para el recorrido hecho fuera de un curso.
     * @return array Familias en orden fijo, cada una con sus codigos y total.
     */
    public static function event_catalog(int $courseid): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT eventcode, COUNT(*) AS total
               FROM {local_chatbot_trace}
              WHERE courseid = :courseid
           GROUP BY eventcode
           ORDER BY total DESC, eventcode ASC", ['courseid' => $courseid]);

        // La familia sale del propio codigo: IA-T son del Tutor, IA-C del
        // Coach, PDP del plan, y el resto queda como registro del sistema.
        $familias = [
            'tutor' => ['label' => get_string('catalog_fam_tutor', 'local_chatbot'), 'codes' => [], 'total' => 0],
            'coach' => ['label' => get_string('catalog_fam_coach', 'local_chatbot'), 'codes' => [], 'total' => 0],
            'pdp' => ['label' => get_string('catalog_fam_pdp', 'local_chatbot'), 'codes' => [], 'total' => 0],
            'sistema' => ['label' => get_string('catalog_fam_system', 'local_chatbot'), 'codes' => [], 'total' => 0],
        ];
        foreach ($rows as $row) {
            $code = (string) $row->eventcode;
            if (strpos($code, 'EVT-IA-T-') === 0) {
                $key = 'tutor';
            } else if (strpos($code, 'EVT-IA-C-') === 0) {
                $key = 'coach';
            } else if (strpos($code, 'EVT-PDP-') === 0) {
                $key = 'pdp';
            } else {
                $key = 'sistema';
            }
            $familias[$key]['codes'][] = ['code' => $code, 'total' => (int) $row->total];
            $familias[$key]['total'] += (int) $row->total;
        }

        return array_filter($familias, fn($f) => $f['total'] > 0);
    }

    /**
     * Respuestas del quiz formativo por subdimension, segun el patron registrado.
     *
     * La tabla se llena con el observador de intentos calificados; aqui se
     * agrega para ver donde se concentran los errores, sin exponer que
     * respondio cada persona.
     *
     * @param int $courseid
     * @return array Filas con subdimension, correctas, incorrectas y total.
     */
    public static function quiz_patterns(int $courseid): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT subdimension, responsestatus, COUNT(*) AS total
               FROM {local_chatbot_quiz_pattern}
              WHERE courseid = :courseid
           GROUP BY subdimension, responsestatus
           ORDER BY subdimension ASC", ['courseid' => $courseid]);

        $porsub = [];
        foreach ($rows as $row) {
            $sub = (string) $row->subdimension;
            if (!isset($porsub[$sub])) {
                $porsub[$sub] = ['subdimension' => $sub, 'correctas' => 0, 'incorrectas' => 0, 'total' => 0];
            }
            $campo = ($row->responsestatus === 'correcta') ? 'correctas' : 'incorrectas';
            $porsub[$sub][$campo] += (int) $row->total;
            $porsub[$sub]['total'] += (int) $row->total;
        }

        return array_values($porsub);
    }

    /**
     * Salud del servicio de IA: como respondio el proveedor.
     *
     * Sin esto, una degradacion del proveedor solo se nota porque alguien se
     * queja. El consumo va en miles de tokens porque la cifra exacta no
     * aporta a la decision y si estorba la lectura.
     *
     * @param int $courseid
     * @return array Con claves 'estados', 'latencia' y 'tokens'.
     */
    public static function service_health(int $courseid): array {
        global $DB;

        $estados = [];
        foreach ($DB->get_records_sql(
                "SELECT status, COUNT(*) AS total
                   FROM {local_chatbot_interaction}
                  WHERE courseid = :courseid
               GROUP BY status", ['courseid' => $courseid]) as $row) {
            $estados[(string) $row->status] = (int) $row->total;
        }

        $latencia = [];
        foreach ($DB->get_records_sql(
                "SELECT mode, AVG(latency_ms) AS media, MAX(latency_ms) AS peor, COUNT(*) AS total
                   FROM {local_chatbot_interaction}
                  WHERE courseid = :courseid AND latency_ms > 0
               GROUP BY mode
               ORDER BY media DESC", ['courseid' => $courseid]) as $row) {
            $latencia[] = ['mode' => (string) $row->mode, 'media' => round($row->media / 1000, 1),
                'peor' => round($row->peor / 1000, 1), 'total' => (int) $row->total];
        }

        $tokens = $DB->get_record_sql(
            "SELECT COALESCE(SUM(input_tokens), 0) AS entrada, COALESCE(SUM(output_tokens), 0) AS salida
               FROM {local_chatbot_interaction}
              WHERE courseid = :courseid", ['courseid' => $courseid]);

        return [
            'estados' => $estados,
            'latencia' => $latencia,
            'tokens' => ['entrada' => (int) $tokens->entrada, 'salida' => (int) $tokens->salida],
        ];
    }
}
