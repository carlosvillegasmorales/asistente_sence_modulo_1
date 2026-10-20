<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Plan Personal de Desarrollo (componente 3 del Modulo 1).
 *
 * Traduce la matriz de brechas en metas con objetivo, acciones y evidencia de
 * avance. Dos reglas de gobernanza mandan sobre el diseno de esta clase:
 *
 * 1. "Toda recomendacion relevante del bot debe permitir revision, aceptacion,
 *    edicion o rechazo por la persona facilitadora": por eso una meta creada
 *    por la IA nace en estado "propuesto" y no cuenta como parte del plan
 *    hasta que la persona la acepta o la modifica. La IA no puede escribir
 *    directamente en el plan de nadie.
 *
 * 2. "Los criterios de ajuste del PDP deben quedar trazados: que cambio, por
 *    que cambio y a partir de que evidencia": por eso cada operacion escribe
 *    una fila en local_chatbot_pdp_history. El historial no se borra ni se
 *    reescribe.
 */
class pdp {

    /** @var string Meta sugerida por la IA, todavia sin decision de la persona. */
    const GOAL_PROPUESTO = 'propuesto';

    /** @var string La persona acepto la meta tal como fue propuesta. */
    const GOAL_ACEPTADO = 'aceptado';

    /** @var string La persona acepto la meta pero cambio su contenido. */
    const GOAL_MODIFICADO = 'modificado';

    /**
     * @var string La persona no descarta la meta pero la deja para mas adelante.
     *
     * RU-COA-004 enumera cuatro decisiones posibles sobre cada recomendacion:
     * aceptar, editar, POSTERGAR o rechazar. Postergar no es rechazar a medias:
     * la meta sigue viva en el plan, y el Coach puede retomarla cuando cambien
     * el tiempo disponible o la evidencia de avance.
     */
    const GOAL_POSTERGADO = 'postergado';

    /** @var string La persona descarto la meta. */
    const GOAL_RECHAZADO = 'rechazado';

    /** @var string La persona declara la meta cumplida y con evidencia. */
    const GOAL_COMPLETADO = 'completado';

    /**
     * @var int Tope de prioridades que el Coach puede proponer de una vez.
     *
     * RU-COA-003 fija el maximo en tres. Se aplica recortando la respuesta del
     * modelo, no solo pidiendoselo en el prompt: un modelo que devuelve cinco
     * metas no esta ignorando una preferencia de estilo, esta rompiendo un
     * limite pedagogico. Mas de tres frentes abiertos a la vez es precisamente
     * lo que el modulo quiere evitarle a una persona que ademas trabaja.
     */
    const MAX_PRIORIDADES = 3;

    /** @var string Plan en construccion: todavia no es un compromiso. */
    const PLAN_BORRADOR = 'borrador';

    /**
     * @var string Plan activo: la persona valido metas, acciones, plazos y
     * evidencias (RU-COA-PDP-FLOW-001).
     *
     * El valor guardado sigue siendo 'validado', el que ya usaban los planes
     * existentes: cambiar la cadena habria obligado a migrar datos para decir
     * lo mismo con otra palabra.
     */
    const PLAN_ACTIVO = 'validado';

    /** @var string Origen de una meta creada por el Modo B del asistente. */
    const ORIGIN_IA = 'ia';

    /** @var string Origen de una meta escrita por la propia persona facilitadora. */
    const ORIGIN_USUARIO = 'usuario';

    /**
     * Devuelve el PDP de la persona, creandolo vacio si todavia no existe.
     *
     * @param int $userid
     * @param int $courseid
     * @return \stdClass
     */
    public static function get_or_create(int $userid, int $courseid): \stdClass {
        global $DB;

        $record = $DB->get_record('local_chatbot_pdp', ['userid' => $userid, 'courseid' => $courseid]);
        if ($record) {
            return $record;
        }

        $now = time();
        $record = new \stdClass();
        $record->userid = $userid;
        $record->courseid = $courseid;
        $record->status = self::PLAN_BORRADOR;
        $record->version = 1;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->ronda = 0;
        $record->rondamax = 0;
        $record->id = $DB->insert_record('local_chatbot_pdp', $record);

        return $record;
    }

    /**
     * @param int $pdpid
     * @param string[]|null $statuses Filtra por estado; null trae todas las metas.
     * @return \stdClass[]
     */
    public static function list_goals(int $pdpid, ?array $statuses = null): array {
        global $DB;

        $params = ['pdpid' => $pdpid];
        $where = 'pdpid = :pdpid';
        // Solo la version del plan que se esta mostrando (ver goal_visible()).
        $ronda = (int) $DB->get_field('local_chatbot_pdp', 'ronda', ['id' => $pdpid]);
        if ($ronda > 0) {
            $where .= ' AND (ronda IS NULL OR ronda = :ronda)';
            $params['ronda'] = $ronda;
        }

        if ($statuses !== null && !empty($statuses)) {
            list($insql, $inparams) = $DB->get_in_or_equal($statuses, SQL_PARAMS_NAMED, 'st');
            $where .= " AND status $insql";
            $params = array_merge($params, $inparams);
        }

        return $DB->get_records_select('local_chatbot_pdp_goal', $where, $params, 'sortorder ASC, id ASC');
    }

    /** @var string[] Grupos del plan en el orden en que se muestran, y los estados que cada uno reune. */
    const GOAL_GROUPS = [
        'curso' => [self::GOAL_ACEPTADO, self::GOAL_MODIFICADO],
        'postergadas' => [self::GOAL_POSTERGADO],
        'completadas' => [self::GOAL_COMPLETADO],
    ];

    /**
     * Grupo de una meta del plan. Funcion pura.
     *
     * @param string $status Estado guardado de la meta.
     * @return string curso, postergadas o completadas (lo desconocido cae en curso).
     */
    public static function goal_group(string $status): string {
        foreach (self::GOAL_GROUPS as $grupo => $estados) {
            if (in_array($status, $estados, true)) {
                return $grupo;
            }
        }
        return 'curso';
    }

    /**
     * Ordena las metas para que se puedan seguir: primero las que se estan trabajando, luego las
     * postergadas y al final las completadas; dentro de cada grupo, la de plazo mas cercano primero
     * (sin plazo, al final), despues por dimension y, a igualdad, en el orden en que se crearon.
     * Antes salian por orden de creacion mezclando estados, asi que cada meta nueva o reactivada
     * caia en cualquier lugar. Funcion pura.
     *
     * @param \stdClass[] $goals Metas (status, plazo, dimensionid, id).
     * @param int[] $dimensionorder Dimension (id) => su orden.
     * @return \stdClass[] Metas ordenadas, con claves reiniciadas.
     */
    public static function order_goals(array $goals, array $dimensionorder = []): array {
        $orden = array_keys(self::GOAL_GROUPS);
        $lista = array_values($goals);
        usort($lista, static function ($a, $b) use ($orden, $dimensionorder) {
            $ga = array_search(self::goal_group((string) $a->status), $orden, true);
            $gb = array_search(self::goal_group((string) $b->status), $orden, true);
            if ($ga !== $gb) {
                return $ga <=> $gb;
            }
            $pa = !empty($a->plazo) ? (int) $a->plazo : PHP_INT_MAX;
            $pb = !empty($b->plazo) ? (int) $b->plazo : PHP_INT_MAX;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            $da = $dimensionorder[(int) ($a->dimensionid ?? 0)] ?? PHP_INT_MAX;
            $db = $dimensionorder[(int) ($b->dimensionid ?? 0)] ?? PHP_INT_MAX;
            if ($da !== $db) {
                return $da <=> $db;
            }
            return (int) $a->id <=> (int) $b->id;
        });
        return $lista;
    }

    /**
     * «Mi avance» de una meta: que paso y cuando, cuantas evidencias se entregaron y que dice la
     * ultima revision sobre lo logrado y lo que falta. Funcion pura.
     *
     * Responde «¿se logro mejorar algo?» con lo que la persona hizo, no con una nota: el
     * asistente no califica.
     *
     * @param \stdClass $goal Meta (timecreated, timemodified, status).
     * @param array $supports Apoyos de la meta: ['timegenerated', 'timestarted', 'reviews' => [revisiones
     *              con timedelivered, reviewstatus, strength, aspecttodeepen, rejected]].
     * @return array events (lista de ['time', 'key', 'a'] en orden), evidences (int), logro, falta,
     *               estado (estado de la ultima revision valida, o null).
     */
    public static function goal_progress(\stdClass $goal, array $supports): array {
        $eventos = [['time' => (int) $goal->timecreated, 'key' => 'creada', 'a' => null]];
        $evidencias = 0;
        $ultima = null;
        $actual = null;
        foreach ($supports as $apoyo) {
            // Estado de hoy del apoyo mas reciente: puede no ser el que sugirio la ultima revision
            // (p. ej. la persona no acepto cerrar la meta y el apoyo volvio a profundizacion).
            if (!empty($apoyo['estado']) && ($actual === null || (int) $apoyo['timegenerated'] >= $actual[0])) {
                $actual = [(int) $apoyo['timegenerated'], (string) $apoyo['estado']];
            }
            $eventos[] = ['time' => (int) $apoyo['timegenerated'], 'key' => 'apoyo_propuesto', 'a' => null];
            if (!empty($apoyo['timestarted'])) {
                $eventos[] = ['time' => (int) $apoyo['timestarted'], 'key' => 'apoyo_iniciado', 'a' => null];
            }
            foreach ($apoyo['reviews'] ?? [] as $rev) {
                $rev = (object) $rev;
                if (!empty($rev->rejected)) {
                    $eventos[] = ['time' => (int) $rev->timedelivered, 'key' => 'evidencia_rechazada', 'a' => null];
                    continue;
                }
                $evidencias++;
                $eventos[] = ['time' => (int) $rev->timedelivered, 'key' => 'evidencia',
                    'a' => ['n' => $evidencias, 'estado' => (string) $rev->reviewstatus]];
                if ($ultima === null || (int) $rev->timedelivered >= (int) $ultima->timedelivered) {
                    $ultima = $rev;
                }
            }
        }
        if ($goal->status === self::GOAL_COMPLETADO) {
            $eventos[] = ['time' => (int) $goal->timemodified, 'key' => 'completada', 'a' => null];
        }
        usort($eventos, static function ($a, $b) {
            return $a['time'] <=> $b['time'];
        });
        $texto = static function ($valor): ?string {
            $valor = trim((string) $valor);
            return $valor === '' ? null : $valor;
        };
        return [
            'events' => $eventos,
            'evidences' => $evidencias,
            'logro' => $ultima ? $texto($ultima->strength ?? '') : null,
            'falta' => $ultima ? $texto($ultima->aspecttodeepen ?? '') : null,
            'estado' => $ultima ? (string) $ultima->reviewstatus : null,
            'actual' => $actual ? $actual[1] : null,
        ];
    }

    /**
     * Como cambio la brecha de una meta desde que se creo. Funcion pura.
     *
     * La meta guarda la brecha como texto con el nivel entre parentesis, p. ej.
     * "Estrategias didacticas (En desarrollo)". Se lee ese nivel y se compara con el actual.
     * Es informacion de apoyo sobre la orientacion de la persona, no una calificacion.
     *
     * @param string $brecha Texto guardado en la meta.
     * @param string $now Nivel actual de la dimension (clave de nivel), vacio si se desconoce.
     * @param string[] $labels Clave de nivel => etiqueta, como se escribio en la meta.
     * @param string[] $order Claves de nivel, del que mas apoyo pide al que menos.
     * @return array before (clave o null), now (clave o null), trend: sube, igual, baja o desconocido.
     */
    public static function gap_change(string $brecha, string $now, array $labels, array $order): array {
        $before = null;
        if (preg_match('/\(([^()]+)\)\s*$/u', trim($brecha), $m)) {
            $texto = mb_strtolower(trim($m[1]));
            foreach ($labels as $clave => $etiqueta) {
                if (mb_strtolower($etiqueta) === $texto) {
                    $before = $clave;
                }
            }
        }
        $now = in_array($now, $order, true) ? $now : null;
        if ($before === null || $now === null) {
            return ['before' => $before, 'now' => $now, 'trend' => 'desconocido'];
        }
        $a = array_search($before, $order, true);
        $b = array_search($now, $order, true);
        return ['before' => $before, 'now' => $now, 'trend' => $b > $a ? 'sube' : ($b < $a ? 'baja' : 'igual')];
    }

    /**
     * Crea una meta. Si la origina la IA queda en estado propuesto, a la
     * espera de que la persona decida.
     *
     * @param int $pdpid
     * @param array $data Claves: dimensionid, brecha, objetivo, acciones, evidencia,
     *        unidad, recurso, microcapsula y actividad.
     * @param string $origin
     * @param int $userid Quien queda registrado en el historial.
     * @return int id de la meta creada.
     */
    public static function add_goal(int $pdpid, array $data, string $origin, int $userid): int {
        global $DB;

        $now = time();
        $record = new \stdClass();
        $record->pdpid = $pdpid;
        $record->dimensionid = !empty($data['dimensionid']) ? (int) $data['dimensionid'] : null;
        $record->brecha = $data['brecha'] ?? '';
        $record->objetivo = $data['objetivo'] ?? '';
        $record->acciones = $data['acciones'] ?? '';
        $record->evidencia = $data['evidencia'] ?? '';
        // Los cuatro vinculos que RU-COA-003 exige por prioridad, ademas de la evidencia.
        $record->unidad = $data['unidad'] ?? null;
        $record->recurso = $data['recurso'] ?? null;
        $record->microcapsula = $data['microcapsula'] ?? null;
        $record->actividad = $data['actividad'] ?? null;
        // Fecha comprometida (RU-COA-PDP-FLOW-001). La propone el Coach en
        // semanas; la persona la confirma o la cambia antes de validar el plan.
        $record->plazo = !empty($data['plazo']) ? (int) $data['plazo'] : null;
        $record->status = ($origin === self::ORIGIN_IA) ? self::GOAL_PROPUESTO : self::GOAL_ACEPTADO;
        $record->origin = $origin;
        // Una meta del asistente pertenece a la version del plan vigente; una propia, a todas.
        $ronda = ($origin === self::ORIGIN_IA) ? (int) $DB->get_field('local_chatbot_pdp', 'ronda', ['id' => $pdpid]) : 0;
        $record->ronda = $ronda > 0 ? $ronda : null;
        $record->sortorder = $DB->count_records('local_chatbot_pdp_goal', ['pdpid' => $pdpid]);
        $record->timecreated = $now;
        $record->timemodified = $now;

        $goalid = $DB->insert_record('local_chatbot_pdp_goal', $record);

        self::log_change($pdpid, $goalid, 'crear', null, $record->objetivo,
            ($origin === self::ORIGIN_IA)
                ? 'Meta propuesta por el asistente en modo coach, a la espera de decision de la persona'
                : 'Meta escrita directamente por la persona facilitadora',
            $data['evidence'] ?? '', $userid);

        return $goalid;
    }

    /**
     * Registra la decision de la persona sobre una meta propuesta por la IA.
     *
     * @param int $goalid
     * @param string $decision Uno de GOAL_ACEPTADO, GOAL_MODIFICADO, GOAL_POSTERGADO,
     *        GOAL_RECHAZADO o GOAL_COMPLETADO.
     * @param int $userid
     * @param string $reason Por que cambio.
     * @param array $changes Campos editados cuando la decision es modificar.
     * @return bool
     */
    public static function decide_goal(int $goalid, string $decision, int $userid,
            string $reason = '', array $changes = []): bool {
        global $DB;

        $valid = [self::GOAL_ACEPTADO, self::GOAL_MODIFICADO, self::GOAL_POSTERGADO,
            self::GOAL_RECHAZADO, self::GOAL_COMPLETADO];
        if (!in_array($decision, $valid, true)) {
            return false;
        }

        $goal = $DB->get_record('local_chatbot_pdp_goal', ['id' => $goalid]);
        if (!$goal) {
            return false;
        }

        $oldvalue = $goal->objetivo;
        $goal->status = $decision;

        foreach (['brecha', 'objetivo', 'acciones', 'evidencia',
                'unidad', 'recurso', 'microcapsula', 'actividad', 'plazo'] as $field) {
            if (isset($changes[$field])) {
                $goal->{$field} = $changes[$field];
            }
        }

        $goal->timemodified = time();
        $DB->update_record('local_chatbot_pdp_goal', $goal);

        $historyid = self::log_change($goal->pdpid, $goalid, $decision, $oldvalue, $goal->objetivo,
            $reason, $changes['evidence'] ?? ($goal->evidencia ?? ''), $userid);

        self::touch($goal->pdpid);

        // Decision de la persona sobre la meta, con codigo de actualizacion PDP-UPD (Anexo G §2.1).
        $plan = $DB->get_record('local_chatbot_pdp', ['id' => $goal->pdpid], 'id, courseid');
        trace::log(trace::PDP_DECISION, $userid, $plan ? (int) $plan->courseid : 0,
            trace::unit_from_hint((string) ($goal->unidad ?? '')), ['meta' => $goalid, 'decision' => $decision,
            'origen' => $goal->origin], trace::code('PDP-UPD', '', $historyid));

        \local_chatbot\event\pdp_goal_decided::create([
            'context' => \context_system::instance(),
            'userid' => $userid,
            'objectid' => $goalid,
            'other' => ['decision' => $decision, 'origin' => $goal->origin],
        ])->trigger();

        return true;
    }

    /**
     * Deja constancia de un cambio. El historial es solo de escritura: nunca
     * se actualiza ni se elimina una entrada anterior.
     *
     * @param int $pdpid
     * @param int|null $goalid
     * @param string $changetype
     * @param string|null $oldvalue
     * @param string|null $newvalue
     * @param string $reason
     * @param string $evidence
     * @param int $userid
     * @return void
     */
    public static function log_change(int $pdpid, ?int $goalid, string $changetype,
            ?string $oldvalue, ?string $newvalue, string $reason, string $evidence, int $userid): int {
        global $DB;

        $record = new \stdClass();
        $record->pdpid = $pdpid;
        $record->goalid = $goalid;
        $record->changetype = $changetype;
        $record->oldvalue = $oldvalue;
        $record->newvalue = $newvalue;
        $record->reason = $reason;
        $record->evidence = $evidence;
        $record->userid = $userid;
        $record->timecreated = time();

        return (int) $DB->insert_record('local_chatbot_pdp_history', $record);
    }

    /**
     * @param int $pdpid
     * @return \stdClass[] Historial de cambios, del mas reciente al mas antiguo.
     */
    public static function get_history(int $pdpid): array {
        global $DB;
        return $DB->get_records('local_chatbot_pdp_history', ['pdpid' => $pdpid], 'timecreated DESC, id DESC');
    }

    /**
     * Lo que le falta a una meta para poder formar parte de un plan activo.
     * Funcion pura.
     *
     * RU-COA-PDP-FLOW-001: el PDP solo pasa a estado activo cuando la persona
     * valida metas, acciones, plazos y evidencias. Una meta sin plazo no es un
     * compromiso sino una intencion, y una sin evidencia no se puede seguir.
     *
     * @param \stdClass $goal
     * @return string[] Nombres de los campos que faltan; vacio si esta completa.
     */
    public static function missing_for_activation(\stdClass $goal): array {
        $missing = [];
        foreach (['objetivo', 'acciones', 'evidencia'] as $field) {
            if (trim((string) ($goal->{$field} ?? '')) === '') {
                $missing[] = $field;
            }
        }
        if (empty($goal->plazo)) {
            $missing[] = 'plazo';
        }
        return $missing;
    }

    /**
     * Valida el plan, si esta en condiciones de activarse.
     *
     * No valida a medias: si alguna meta aceptada esta incompleta, el plan
     * sigue en borrador y se devuelve que falta en cada una, para que la
     * persona lo complete en vez de encontrarse despues con un plan "activo"
     * que no se puede seguir.
     *
     * @param int $pdpid
     * @param int $userid
     * @return array Con claves ok (bool) e incomplete (array goalid => campos faltantes).
     */
    public static function validate(int $pdpid, int $userid): array {
        global $DB;

        $record = $DB->get_record('local_chatbot_pdp', ['id' => $pdpid]);
        if (!$record) {
            return ['ok' => false, 'incomplete' => []];
        }

        $goals = self::list_goals($pdpid, [self::GOAL_ACEPTADO, self::GOAL_MODIFICADO]);
        if (empty($goals)) {
            return ['ok' => false, 'incomplete' => []];
        }

        $incomplete = [];
        foreach ($goals as $goal) {
            $missing = self::missing_for_activation($goal);
            if (!empty($missing)) {
                $incomplete[(int) $goal->id] = $missing;
            }
        }
        if (!empty($incomplete)) {
            return ['ok' => false, 'incomplete' => $incomplete];
        }

        $record->status = self::PLAN_ACTIVO;
        $record->version = (int) $record->version + 1;
        $record->timemodified = time();
        $DB->update_record('local_chatbot_pdp', $record);

        self::log_change($pdpid, null, 'validar', null, 'version ' . $record->version,
            'La persona valido metas, acciones, plazos y evidencias de esta version del plan', '', $userid);

        return ['ok' => true, 'incomplete' => []];
    }

    /**
     * @param int $userid
     * @param int $courseid
     * @return bool Si existe un PDP con al menos una meta vigente, condicion para habilitar el proyecto.
     */
    public static function has_active_plan(int $userid, int $courseid): bool {
        global $DB;

        $record = $DB->get_record('local_chatbot_pdp', ['userid' => $userid, 'courseid' => $courseid]);
        if (!$record) {
            return false;
        }

        // Un plan con metas aceptadas pero sin validar todavia no es un plan
        // activo (RU-COA-PDP-FLOW-001): le pueden faltar plazos o evidencias.
        if ($record->status !== self::PLAN_ACTIVO) {
            return false;
        }

        $where = 'pdpid = :pdpid AND status IN (:aceptado, :modificado, :completado)';
        $params = ['pdpid' => $record->id, 'aceptado' => self::GOAL_ACEPTADO,
            'modificado' => self::GOAL_MODIFICADO, 'completado' => self::GOAL_COMPLETADO];
        if ((int) $record->ronda > 0) {
            $where .= ' AND (ronda IS NULL OR ronda = :ronda)';
            $params['ronda'] = (int) $record->ronda;
        }
        return $DB->record_exists_select('local_chatbot_pdp_goal', $where, $params);
    }

    /**
     * Version en texto del plan vigente, para dar contexto al asistente sin
     * exponer ningun dato identificatorio.
     *
     * @param int $userid
     * @param int $courseid
     * @return string
     */
    /**
     * Los 12 estados de meta del SPEC v5 (RU-ADAPT-006, Doc 5 §10.1, Anexo F §8), con el
     * texto que ve la persona. Clave => etiqueta.
     */
    const GOAL_STATES_V5 = [
        'propuesta' => 'Propuesta',
        'activa' => 'Activa',
        'en_desarrollo' => 'En desarrollo',
        'evidencia_presentada' => 'Evidencia presentada',
        'evidencia_revisada' => 'Evidencia revisada por IA',
        'requiere_profundizacion' => 'Requiere profundización',
        'avance_registrado' => 'Avance registrado',
        'en_revision' => 'En revisión',
        'postergada' => 'Postergada',
        'propuesta_cierre' => 'Propuesta de cierre',
        'cerrada' => 'Cerrada',
        'rechazada' => 'Rechazada',
    ];

    /**
     * Estado de una meta con el vocabulario del SPEC v5. Funcion pura.
     *
     * No es una columna nueva: se deriva de lo que ya se guarda (la decision de la persona
     * sobre la meta, si el plan esta validado, el estado del ultimo apoyo adaptativo y si hay
     * una propuesta de actualizacion esperando confirmacion). Asi los planes existentes
     * pasan a mostrarse con los 12 estados sin migrar datos ni cambiar la logica de decision.
     *
     * @param string $goalstatus Estado guardado de la meta (GOAL_*).
     * @param bool $planactive Si el plan esta validado.
     * @param string|null $adaptstate Estado del apoyo adaptativo mas reciente de la meta, si hay.
     * @param bool $pendingupdate Si hay una propuesta de actualizacion o cierre sin confirmar.
     * @return string Clave de GOAL_STATES_V5.
     */
    public static function goal_state_v5(string $goalstatus, bool $planactive, ?string $adaptstate,
            bool $pendingupdate): string {
        switch ($goalstatus) {
            case self::GOAL_PROPUESTO:
                return 'propuesta';
            case self::GOAL_RECHAZADO:
                return 'rechazada';
            case self::GOAL_POSTERGADO:
                return 'postergada';
            case self::GOAL_COMPLETADO:
                return 'cerrada';
        }
        // Aceptada o modificada: solo es "activa" dentro de un plan validado.
        if (!$planactive) {
            return 'propuesta';
        }
        $byadapt = [
            adaptive::STATE_ACTIVIDAD_INICIADA => 'en_desarrollo',
            adaptive::STATE_EVIDENCIA_PRESENTADA => 'evidencia_presentada',
            adaptive::STATE_EVIDENCIA_REVISADA_IA => 'evidencia_revisada',
            adaptive::STATE_REQUIERE_PROFUNDIZACION => 'requiere_profundizacion',
            adaptive::STATE_AVANCE_REGISTRADO => 'avance_registrado',
            adaptive::STATE_PROPUESTA_CIERRE_META => 'propuesta_cierre',
            adaptive::STATE_META_CERRADA => 'cerrada',
        ];
        if ($adaptstate === adaptive::STATE_PROPUESTA_CIERRE_META && $pendingupdate) {
            return 'propuesta_cierre';
        }
        if ($pendingupdate) {
            return 'en_revision';
        }
        return $byadapt[$adaptstate] ?? 'activa';
    }

    /**
     * Estado v5 de cada meta de un plan, leyendo el ultimo apoyo y las revisiones pendientes.
     *
     * @param \stdClass $plan
     * @param \stdClass[] $goals
     * @return array goalid => clave de GOAL_STATES_V5.
     */
    public static function goal_states_v5_for(\stdClass $plan, array $goals): array {
        global $DB;
        $states = [];
        foreach ($goals as $goal) {
            $adapts = $DB->get_records('local_chatbot_adapt', ['goalid' => $goal->id], 'id DESC', 'id, estado', 0, 1);
            $last = $adapts ? reset($adapts) : null;
            $pending = $last && $DB->record_exists_select('local_chatbot_adapt_review',
                'adaptid = ? AND personconfirmed = -1', [$last->id]);
            $states[(int) $goal->id] = self::goal_state_v5((string) $goal->status, $plan->status === self::PLAN_ACTIVO,
                $last ? (string) $last->estado : null, $pending);
        }
        return $states;
    }

    public static function as_prompt_context(int $userid, int $courseid): string {
        global $DB;

        $record = $DB->get_record('local_chatbot_pdp', ['userid' => $userid, 'courseid' => $courseid]);
        if (!$record) {
            return '';
        }

        // Las postergadas entran a proposito: RU-COA-005 pide que el Coach
        // ajuste prioridades cuando llega nueva evidencia, y una meta que la
        // persona dejo para mas adelante es justamente la candidata a retomar.
        $goals = self::list_goals($record->id,
            [self::GOAL_ACEPTADO, self::GOAL_MODIFICADO, self::GOAL_POSTERGADO, self::GOAL_COMPLETADO]);
        if (empty($goals)) {
            return '';
        }

        $lines = [];
        $statesv5 = self::goal_states_v5_for($record, $goals);
        foreach ($goals as $goal) {
            $estado = self::GOAL_STATES_V5[$statesv5[(int) $goal->id]] ?? $goal->status;
            $lines[] = "- [Estado: {$estado}] Objetivo: {$goal->objetivo}"
                . (!empty($goal->unidad) ? " | Unidad: {$goal->unidad}" : '')
                . (!empty($goal->recurso) ? " | Recurso: {$goal->recurso}" : '')
                . (!empty($goal->actividad) ? " | Actividad: {$goal->actividad}" : '')
                . (!empty($goal->plazo) ? ' | Plazo: ' . userdate($goal->plazo, '%d/%m/%Y') : '')
                . (!empty($goal->acciones) ? " | Acciones: {$goal->acciones}" : '')
                . (!empty($goal->evidencia) ? " | Evidencia comprometida: {$goal->evidencia}" : '');
        }

        return "PDP VIGENTE DE LA PERSONA:\n" . implode("\n", $lines)
            . "\nLas metas marcadas como postergadas siguen vigentes: la persona las dejo para mas"
            . " adelante, no las descarto.";
    }

    /**
     * Lleva al PDP las prioridades de la matriz de desarrollo del Coach.
     *
     * Hasta M1-v1.1.0 esto hacia su propia llamada al modelo y pedia metas en
     * JSON. Ahora las prioridades salen de una sola fuente, la matriz de
     * desarrollo (coach_report), que ya aplico en codigo el orden de
     * priorizacion, el limite de tres —o de una si el diagnostico esta
     * incompleto—, el catalogo de apoyos y la forma verbo + capacidad +
     * contexto + evidencia. Dos llamadas independientes podian proponer
     * prioridades distintas a la misma persona el mismo dia.
     *
     * Si la matriz todavia no existe, se genera primero. Las metas entran como
     * propuestas: la persona decide sobre cada una.
     *
     * @param int $userid
     * @param int $courseid
     * @return array Con claves ok (bool), created (int) y message (string).
     */
    public static function propose_goals(int $userid, int $courseid): array {
        $report = coach_report::get_latest($userid, $courseid);
        // Una matriz redactada con respuestas anteriores no sirve de base: se rehace primero.
        if ($report && ux::on('diagrefresh') && coach_report::is_stale(
                (array) json_decode((string) $report->payload, true), diagnostic::get_gap_matrix($userid, $courseid))) {
            $report = false;
        }
        if (!$report) {
            $generated = coach_report::generate($userid, $courseid);
            if (!$generated['ok']) {
                return ['ok' => false, 'created' => 0, 'message' => $generated['message']];
            }
            $report = coach_report::get_latest($userid, $courseid);
        }

        $payload = json_decode((string) $report->payload, true);
        if (empty($payload['prioridades'])) {
            return ['ok' => false, 'created' => 0,
                'message' => get_string('pdp_error_nopriorities', 'local_chatbot')];
        }

        $dimensions = [];
        foreach (diagnostic::get_gap_matrix($userid, $courseid) as $row) {
            $dimensions[$row['code']] = $row;
        }
        $labels = diagnostic::level_labels_plain();

        $plan = self::get_or_create($userid, $courseid);
        $created = 0;
        foreach ($payload['prioridades'] as $prioridad) {
            $row = $dimensions[$prioridad['codigo']] ?? null;
            self::add_goal($plan->id, [
                'dimensionid' => $row ? $row['dimensionid'] : null,
                'brecha' => $row ? "{$row['name']} ({$labels[$row['nivel']]})" : '',
                'objetivo' => $prioridad['meta'],
                'acciones' => $prioridad['apoyo_inicial'],
                'evidencia' => $prioridad['evidencia_avance'],
                'unidad' => $prioridad['unidad'],
                'recurso' => $prioridad['recurso'],
                'microcapsula' => $prioridad['microcapsula'],
                'actividad' => $prioridad['actividad'],
                'plazo' => time() + ((int) $prioridad['plazo_semanas'] * WEEKSECS),
                'evidence' => get_string('pdp_evidence_diagnostic', 'local_chatbot'),
            ], self::ORIGIN_IA, $userid);
            $created++;
        }

        interaction::record($userid, $courseid, assistant::MODE_COACH, 'propuesta_pdp', [],
            get_string('pdp_recommendation_logged', 'local_chatbot', $created));

        return ['ok' => true, 'created' => $created,
            'message' => get_string('pdp_proposed', 'local_chatbot', $created)];
    }

    /**
     * Si una meta se ve en la version del plan que se esta mostrando. Funcion pura; es la misma
     * regla que aplica list_goals() en SQL.
     *
     * @param int|null $goalronda Version de la meta (null: meta propia o anterior a las versiones).
     * @param int $planronda Version que se muestra (0: el plan todavia no tiene versiones).
     * @return bool
     */
    public static function goal_visible(?int $goalronda, int $planronda): bool {
        return $planronda <= 0 || $goalronda === null || $goalronda === $planronda;
    }

    /**
     * A que version se mueve el plan con «atras» (-1) o «adelante» (+1). Funcion pura.
     *
     * @param int $actual
     * @param int $max
     * @param int $paso
     * @return int La misma version si no hay hacia donde moverse.
     */
    public static function version_target(int $actual, int $max, int $paso): int {
        if ($max < 1) {
            return $actual;
        }
        return max(1, min($max, $actual + $paso));
    }

    /**
     * Rehace las metas del plan desde el diagnostico actual (interruptor autopdp).
     *
     * Las metas que vinieron del asistente, en cualquier estado, quedan en la version anterior
     * del plan, con sus apoyos y evidencias; las nuevas forman una version nueva. Las metas
     * propias se ven en todas las versiones. Nada se borra: se puede volver con move_version().
     *
     * @param int $userid
     * @param int $courseid
     * @return array Lo mismo que propose_goals().
     */
    public static function sync_from_diagnostic(int $userid, int $courseid): array {
        global $DB;
        $plan = self::get_or_create($userid, $courseid);
        $anterior = (int) $plan->ronda;
        $max = (int) $plan->rondamax;
        $tenia = array_filter(self::list_goals((int) $plan->id), static function ($goal) {
            return $goal->origin === self::ORIGIN_IA;
        });
        if ($max === 0 && $tenia) {
            // Las metas del asistente de antes de las versiones pasan a ser la version 1.
            $DB->execute('UPDATE {local_chatbot_pdp_goal} SET ronda = 1 WHERE pdpid = ? AND origin = ? AND ronda IS NULL',
                [$plan->id, self::ORIGIN_IA]);
            $anterior = 1;
            $max = 1;
        }
        $nueva = $max + 1;
        $DB->update_record('local_chatbot_pdp', (object) ['id' => $plan->id, 'ronda' => $nueva, 'rondamax' => $nueva,
            'status' => self::PLAN_BORRADOR, 'timemodified' => time()]);

        $resultado = self::propose_goals($userid, $courseid);
        if (!$resultado['ok']) {
            // Sin metas nuevas no hay version nueva: el plan queda como estaba.
            $DB->update_record('local_chatbot_pdp', (object) ['id' => $plan->id, 'ronda' => $anterior,
                'rondamax' => $max, 'status' => $plan->status]);
            return $resultado;
        }
        self::log_change((int) $plan->id, null, 'nueva_version', $anterior > 0 ? "Versión {$anterior}" : null,
            "Versión {$nueva}", 'Metas rehechas desde el diagnóstico actualizado', '', $userid);
        return $resultado;
    }

    /**
     * «Version anterior» o «version siguiente» del plan.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $paso -1 o +1.
     * @return bool Si cambio de version.
     */
    public static function move_version(int $userid, int $courseid, int $paso): bool {
        global $DB;
        $plan = $DB->get_record('local_chatbot_pdp', ['userid' => $userid, 'courseid' => $courseid]);
        if (!$plan) {
            return false;
        }
        $destino = self::version_target((int) $plan->ronda, (int) $plan->rondamax, $paso);
        if ($destino === (int) $plan->ronda) {
            return false;
        }
        $DB->update_record('local_chatbot_pdp', (object) ['id' => $plan->id, 'ronda' => $destino, 'timemodified' => time()]);
        self::log_change((int) $plan->id, null, 'cambio_version', "Versión {$plan->ronda}", "Versión {$destino}",
            'La persona pasó a otra versión de su plan', '', $userid);
        return true;
    }

    /**
     * Datos para el rotulo «Version 2 de 3 · fecha».
     *
     * @param \stdClass $plan
     * @return array actual, total y fecha (de la primera meta de esa version, 0 si no hay).
     */
    public static function version_info(\stdClass $plan): array {
        global $DB;
        $fecha = (int) $DB->get_field_sql('SELECT MIN(timecreated) FROM {local_chatbot_pdp_goal} WHERE pdpid = ? AND ronda = ?',
            [$plan->id, (int) $plan->ronda]);
        return ['actual' => (int) $plan->ronda, 'total' => (int) $plan->rondamax, 'fecha' => $fecha];
    }

    /**
     * Cambia las propuestas de metas que la persona todavia no decidio por otras hechas con su
     * diagnostico actual. Solo las propuestas del asistente sin decidir: las metas aceptadas,
     * modificadas, postergadas o propias son decision de la persona y no se tocan.
     *
     * @param int $userid
     * @param int $courseid
     * @return int Cantidad de propuestas nuevas (0 si no habia ninguna pendiente).
     */
    public static function replace_pending_proposals(int $userid, int $courseid): int {
        global $DB;
        $plan = $DB->get_record('local_chatbot_pdp', ['userid' => $userid, 'courseid' => $courseid]);
        if (!$plan) {
            return 0;
        }
        $pendientes = array_filter(self::list_goals((int) $plan->id, [self::GOAL_PROPUESTO]),
            static function ($goal) {
                return $goal->origin === self::ORIGIN_IA;
            });
        $retiradas = 0;
        foreach ($pendientes as $goal) {
            // Una propuesta con apoyos o conversacion propia ya tiene trabajo encima: se deja.
            if ($DB->record_exists('local_chatbot_adapt', ['goalid' => $goal->id])
                    || $DB->record_exists('local_chatbot_messages', ['goalid' => $goal->id])) {
                continue;
            }
            self::log_change((int) $plan->id, (int) $goal->id, 'reemplazada', $goal->objetivo, null,
                'Propuesta reemplazada porque cambiaron las respuestas del diagnostico', '', $userid);
            $DB->delete_records('local_chatbot_pdp_goal', ['id' => $goal->id]);
            $retiradas++;
        }
        if ($retiradas === 0) {
            return 0;
        }
        $resultado = self::propose_goals($userid, $courseid);
        return $resultado['ok'] ? (int) $resultado['created'] : 0;
    }

    /**
     * @param int $pdpid
     * @return void
     */
    private static function touch(int $pdpid): void {
        global $DB;
        $DB->set_field('local_chatbot_pdp', 'timemodified', time(), ['id' => $pdpid]);
    }
}
