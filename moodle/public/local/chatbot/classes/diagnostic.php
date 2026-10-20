<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Diagnostico auto aplicado y matriz individual de brechas (componente 1 del
 * Modulo 1).
 *
 * El instrumento tiene dos clases de item, y la diferencia importa:
 *
 * - Los items DECLARATIVOS preguntan por la practica habitual de la persona en
 *   una escala de dominio de 1 a 5. Su promedio por dimension es la "practica
 *   declarada" (PDd).
 * - Los items SITUACIONALES presentan una situacion con alternativas y tienen
 *   respuesta correcta. Su proporcion de acierto por dimension se convierte a
 *   la misma escala 1 a 5 y forma el "componente situacional" (CSd).
 *
 * El resultado que consume el Modo B (Coach) no es ninguno de los dos por
 * separado, sino el puntaje compuesto de orientacion PCd (RU-CALC-001), que
 * pondera 70% lo que la persona declara hacer y 30% lo que resuelve cuando se
 * le pone una situacion delante. La ponderacion existe porque la
 * autodeclaracion sola es fragil —se responde lo deseable, no lo habitual— y
 * la resolucion situacional sola es demasiado estrecha para un instrumento de
 * cinco dimensiones.
 *
 * Advertencia que acompana al numero en todas partes: PCd es una regla
 * funcional para priorizar apoyos. No es una escala psicometrica ni una
 * calificacion del desempeno profesional, y la gobernanza del modulo prohibe
 * usarlo con consecuencias administrativas, laborales o certificadoras
 * (RU-GOB-001).
 *
 * El instrumento se aplica dos veces: al inicio (etapa "entrada", que fija la
 * linea base) y al cierre (etapa "salida"), para poder mostrar variacion real
 * en vez de solo actividad completada.
 */
class diagnostic {

    /** @var string Aplicacion inicial: fija la linea base de la persona. */
    const STAGE_ENTRADA = 'entrada';

    /** @var string Aplicacion breve de cierre, para contrastar progreso. */
    const STAGE_SALIDA = 'salida';

    /** @var string Item de practica declarada: escala de dominio, sin respuesta correcta. */
    const KIND_DECLARATIVA = 'declarativa';

    /** @var string Item situacional: alternativas con una respuesta correcta. */
    const KIND_SITUACIONAL = 'situacional';

    /** @var int Puntaje minimo de la escala de dominio autodeclarado. */
    const SCORE_MIN = 1;

    /**
     * @var int Puntaje maximo de la escala de dominio autodeclarado.
     *
     * Son 5 y no 4 porque RU-CALC-001 pondera la practica declarada con el
     * componente situacional, y este ultimo se define explicitamente en escala
     * 1 a 5. Ponderar dos numeros en escalas distintas daria un resultado sin
     * significado.
     */
    const SCORE_MAX = 5;

    /** @var string Nivel de orientacion: PCd entre 1,00 y 2,24 (RU-COA-NIVEL-001). */
    const LEVEL_PRIORIDAD_ALTA = 'prioridad_alta';

    /** @var string Nivel de orientacion: PCd entre 2,25 y 3,24. */
    const LEVEL_EN_DESARROLLO = 'en_desarrollo';

    /** @var string Nivel de orientacion: PCd entre 3,25 y 4,24. */
    const LEVEL_FUNCIONAL = 'funcional';

    /** @var string Nivel de orientacion: PCd entre 4,25 y 5,00. */
    const LEVEL_CONSOLIDADO = 'consolidado';

    /**
     * @var float[] Limite inferior de cada nivel, tal como lo fija Doc 3 §6.5.
     *
     * Hasta M1-v1.1.0 los cortes eran tercios del recorrido de la escala
     * (2,33 y 3,67) con tres niveles, porque la matriz v2 no fijaba cortes y
     * habia que elegir alguno. Doc 3 los fija, y fija cuatro: se reemplazan tal
     * cual, sin interpretarlos. La clasificacion se hace sobre el puntaje ya
     * redondeado a dos decimales, porque la tabla del documento salta de 2,24
     * a 2,25 y un valor sin redondear como 2,2449 caeria en un hueco que el
     * documento no contempla.
     */
    const LEVEL_FLOORS = [
        self::LEVEL_CONSOLIDADO => 4.25,
        self::LEVEL_FUNCIONAL => 3.25,
        self::LEVEL_EN_DESARROLLO => 2.25,
        self::LEVEL_PRIORIDAD_ALTA => 1.00,
    ];

    /** @var float Minimo de items declarativos respondidos para un resultado no preliminar. */
    const COMPLETENESS_DECLARATIVA = 0.8;

    /**
     * @var float Diferencia entre practica declarada y componente situacional
     * que se considera discrepancia relevante (RU-COA-PRIOR-002, criterio 2).
     *
     * Doc 3 nombra el criterio pero la matriz no trae el umbral. Un punto
     * completo de escala es la menor diferencia que no se explica por una sola
     * respuesta distinta en una dimension de cuatro items. Es un supuesto de
     * implementacion declarado, no una regla del documento: si Doc 3 trae otro
     * valor, se cambia aqui y el verificador lo detecta.
     */
    const DISCREPANCY_THRESHOLD = 1.0;

    /** @var float Peso de la practica declarada en el puntaje compuesto (RU-CALC-001). */
    const WEIGHT_DECLARADA = 0.7;

    /** @var float Peso del componente situacional en el puntaje compuesto (RU-CALC-001). */
    const WEIGHT_SITUACIONAL = 0.3;

    /**
     * @param int $courseid
     * @return \stdClass[] Dimensiones del instrumento, ordenadas.
     */
    public static function list_dimensions(int $courseid = 0): array {
        global $DB;
        // Se prefiere el instrumento propio del curso; si no existe, se usa el base del sitio.
        $dimensions = $DB->get_records('local_chatbot_dimension', ['courseid' => $courseid], 'sortorder ASC, id ASC');
        if (empty($dimensions) && $courseid != 0) {
            $dimensions = $DB->get_records('local_chatbot_dimension', ['courseid' => 0], 'sortorder ASC, id ASC');
        }
        return $dimensions;
    }

    /**
     * @param int $courseid
     * @param string|null $kind Filtra por clase de item; null los trae todos.
     * @return \stdClass[] Items del instrumento con su dimension resuelta, ordenados para presentacion.
     */
    public static function list_items(int $courseid = 0, ?string $kind = null): array {
        global $DB;

        $dimensions = self::list_dimensions($courseid);
        if (empty($dimensions)) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal(array_keys($dimensions), SQL_PARAMS_NAMED);

        $kindwhere = '';
        if ($kind !== null) {
            $kindwhere = ' AND i.kind = :kind';
            $params['kind'] = $kind;
        }

        // Los situacionales van despues de los declarativos dentro de cada
        // dimension: primero se pregunta por la practica habitual y despues se
        // pone la situacion. Al reves, la situacion condiciona la
        // autodeclaracion que viene a continuacion.
        $sql = "SELECT i.id, i.dimensionid, i.statement, i.kind, i.options, i.correctoption,
                       i.sortorder, d.name AS dimensionname, d.sortorder AS dimensionorder
                  FROM {local_chatbot_diag_item} i
                  JOIN {local_chatbot_dimension} d ON d.id = i.dimensionid
                 WHERE i.dimensionid $insql $kindwhere
              ORDER BY d.sortorder ASC, i.kind ASC, i.sortorder ASC, i.id ASC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Alternativas de un item situacional, ya decodificadas.
     *
     * @param \stdClass $item
     * @return string[] Lista indexada desde 0; vacia si el item no es situacional.
     */
    public static function item_options(\stdClass $item): array {
        if (($item->kind ?? self::KIND_DECLARATIVA) !== self::KIND_SITUACIONAL) {
            return [];
        }
        $decoded = json_decode((string) $item->options, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param int $userid
     * @param int $courseid
     * @param string $stage
     * @return bool Si la persona ya aplico el instrumento en esa etapa.
     */
    public static function has_responses(int $userid, int $courseid, string $stage = self::STAGE_ENTRADA): bool {
        global $DB;
        return $DB->record_exists('local_chatbot_diag_response',
            ['userid' => $userid, 'courseid' => $courseid, 'stage' => $stage]);
    }

    /**
     * Guarda (o reemplaza) las respuestas de una aplicacion del instrumento.
     *
     * El valor de cada respuesta se interpreta segun la clase del item: en uno
     * declarativo es el puntaje de la escala; en uno situacional es el indice
     * de la alternativa elegida, y el acierto se resuelve aqui y no en la
     * interfaz, para que la respuesta correcta nunca tenga que viajar al
     * navegador (RU-DIAG-002).
     *
     * @param int $userid
     * @param int $courseid
     * @param array $answers Mapa itemid => valor respondido.
     * @param string $stage
     * @return int Cantidad de respuestas guardadas.
     */
    public static function save_responses(int $userid, int $courseid, array $answers,
            string $stage = self::STAGE_ENTRADA): int {
        global $DB;

        $items = self::list_items($courseid);
        $now = time();
        $saved = 0;

        foreach ($answers as $itemid => $value) {
            $itemid = (int) $itemid;
            $value = (int) $value;

            if (!isset($items[$itemid])) {
                continue;
            }
            $item = $items[$itemid];
            $kind = $item->kind ?? self::KIND_DECLARATIVA;

            $chosen = null;
            $iscorrect = -1;

            if ($kind === self::KIND_SITUACIONAL) {
                $options = self::item_options($item);
                if ($value < 0 || $value >= count($options)) {
                    continue;
                }
                $chosen = $value;
                $iscorrect = ((int) $item->correctoption === $value) ? 1 : 0;
                // Un item situacional no aporta puntaje de escala, aporta acierto.
                $score = 0;
            } else {
                if ($value < self::SCORE_MIN || $value > self::SCORE_MAX) {
                    continue;
                }
                $score = $value;
            }

            $existing = $DB->get_record('local_chatbot_diag_response',
                ['userid' => $userid, 'itemid' => $itemid, 'stage' => $stage]);

            if ($existing) {
                $existing->score = $score;
                $existing->kind = $kind;
                $existing->chosenoption = $chosen;
                $existing->iscorrect = $iscorrect;
                $existing->scalemax = self::SCORE_MAX;
                $existing->timecreated = $now;
                $DB->update_record('local_chatbot_diag_response', $existing);
            } else {
                $record = new \stdClass();
                $record->userid = $userid;
                $record->courseid = $courseid;
                $record->itemid = $itemid;
                $record->dimensionid = $item->dimensionid;
                $record->score = $score;
                $record->kind = $kind;
                $record->chosenoption = $chosen;
                $record->iscorrect = $iscorrect;
                $record->scalemax = self::SCORE_MAX;
                $record->stage = $stage;
                $record->timecreated = $now;
                $DB->insert_record('local_chatbot_diag_response', $record);
            }
            $saved++;
        }

        if ($saved > 0) {
            \local_chatbot\event\diagnostic_completed::create([
                'context' => \context_system::instance(),
                'userid' => $userid,
                'other' => ['stage' => $stage, 'items' => $saved],
            ])->trigger();
        }

        return $saved;
    }

    /**
     * Puntaje compuesto de orientacion de una dimension. Funcion pura: se
     * verifica sin base de datos y sin modelo de lenguaje, que es exactamente
     * lo que pide RU-CALC-001.
     *
     *   CSd = 1 + 4 x P_situacional,d   (proporcion de acierto llevada a 1..5)
     *   PCd = 0,7 x PDd + 0,3 x CSd
     *
     * Cuando la dimension no tiene items situacionales respondidos no hay
     * componente que ponderar, y el compuesto es la practica declarada. Se
     * devuelve marcado para que la interfaz pueda advertir que ese numero
     * descansa en una sola fuente.
     *
     * @param float $declarada Promedio de items declarativos, en escala SCORE_MIN..SCORE_MAX.
     * @param float|null $proporcionsituacional Proporcion de acierto entre 0 y 1, o null si no hay items.
     * @return array Con claves pd, cs (float|null), pc (float) y hassituacional (bool).
     */
    public static function composite_score(float $declarada, ?float $proporcionsituacional): array {
        $pd = max((float) self::SCORE_MIN, min((float) self::SCORE_MAX, $declarada));

        if ($proporcionsituacional === null) {
            return [
                'pd' => round($pd, 2),
                'cs' => null,
                'pc' => round($pd, 2),
                'hassituacional' => false,
            ];
        }

        $p = max(0.0, min(1.0, $proporcionsituacional));
        $cs = self::SCORE_MIN + (self::SCORE_MAX - self::SCORE_MIN) * $p;
        $pc = (self::WEIGHT_DECLARADA * $pd) + (self::WEIGHT_SITUACIONAL * $cs);

        return [
            'pd' => round($pd, 2),
            'cs' => round($cs, 2),
            'pc' => round($pc, 2),
            'hassituacional' => true,
        ];
    }

    /**
     * Matriz individual de brechas: practica declarada, componente situacional
     * y puntaje compuesto por dimension, con su nivel.
     *
     * El nivel usa lenguaje de necesidades de apoyo y prioridades de
     * desarrollo, nunca etiquetas sobre la persona, tal como exige la regla de
     * gobernanza sobre clasificaciones estigmatizantes (RU-GOB-004).
     *
     * @param int $userid
     * @param int $courseid
     * @param string $stage
     * @return array Lista de filas por dimension con pd, cs, pc, nivel y conteos.
     */
    public static function get_gap_matrix(int $userid, int $courseid, string $stage = self::STAGE_ENTRADA): array {
        global $DB;

        $dimensions = self::list_dimensions($courseid);
        if (empty($dimensions)) {
            return [];
        }

        // Practica declarada: promedio de los items de escala.
        $sqldeclarada = "SELECT dimensionid, AVG(score) AS promedio, COUNT(id) AS respondidos
                           FROM {local_chatbot_diag_response}
                          WHERE userid = :userid AND courseid = :courseid AND stage = :stage
                                AND kind = :kind
                       GROUP BY dimensionid";
        $declaradas = $DB->get_records_sql($sqldeclarada, [
            'userid' => $userid, 'courseid' => $courseid, 'stage' => $stage,
            'kind' => self::KIND_DECLARATIVA,
        ]);

        // Componente situacional: proporcion de acierto.
        $sqlsituacional = "SELECT dimensionid, SUM(iscorrect) AS aciertos, COUNT(id) AS respondidos
                             FROM {local_chatbot_diag_response}
                            WHERE userid = :userid AND courseid = :courseid AND stage = :stage
                                  AND kind = :kind AND iscorrect >= 0
                         GROUP BY dimensionid";
        $situacionales = $DB->get_records_sql($sqlsituacional, [
            'userid' => $userid, 'courseid' => $courseid, 'stage' => $stage,
            'kind' => self::KIND_SITUACIONAL,
        ]);

        // Cuantos items tiene cada dimension, por clase: el denominador de la
        // completitud (RU-COA-COMPLET-001).
        $totals = self::item_totals($courseid);

        $matrix = [];
        foreach ($dimensions as $dimension) {
            if (!isset($declaradas[$dimension->id])) {
                // Sin ninguna respuesta declarativa no hay puntaje que calcular.
                // La dimension no desaparece: queda como omision en el contrato
                // de datos del Coach (ver coach_context::build).
                continue;
            }

            $proporcion = null;
            $situacionalcount = 0;
            if (isset($situacionales[$dimension->id]) && (int) $situacionales[$dimension->id]->respondidos > 0) {
                $situacionalcount = (int) $situacionales[$dimension->id]->respondidos;
                $proporcion = (float) $situacionales[$dimension->id]->aciertos / $situacionalcount;
            }

            $score = self::composite_score((float) $declaradas[$dimension->id]->promedio, $proporcion);
            $declaradacount = (int) $declaradas[$dimension->id]->respondidos;
            $completeness = self::completeness(
                $declaradacount, $totals[$dimension->id][self::KIND_DECLARATIVA] ?? 0,
                $situacionalcount, $totals[$dimension->id][self::KIND_SITUACIONAL] ?? 0
            );

            $matrix[] = [
                'dimensionid' => (int) $dimension->id,
                'code' => $dimension->code,
                'name' => $dimension->name,
                'description' => $dimension->description,
                'pd' => $score['pd'],
                'cs' => $score['cs'],
                'pc' => $score['pc'],
                'hassituacional' => $score['hassituacional'],
                // Se mantiene "promedio" como alias del compuesto para no romper
                // a quien ya consumia la matriz esperando esa clave.
                'promedio' => $score['pc'],
                'nivel' => self::level_for_score($score['pc']),
                'completitud' => $completeness['porcentaje'],
                'preliminar' => $completeness['preliminar'],
                'discrepancia' => self::discrepancy($score['pd'], $score['cs']),
                'respondidos' => $declaradacount + $situacionalcount,
                'situacionales' => $situacionalcount,
            ];
        }

        return $matrix;
    }

    /**
     * Huella del resultado del diagnostico: cambia si cambia algo de lo que lee la matriz de
     * desarrollo (puntajes, nivel, completitud), y no si la persona guarda sin cambiar nada.
     * Funcion pura.
     *
     * @param array $matrix Filas de get_gap_matrix().
     * @return string Vacia si no hay resultado.
     */
    public static function matrix_fingerprint(array $matrix): string {
        if (empty($matrix)) {
            return '';
        }
        $filas = [];
        foreach ($matrix as $row) {
            $filas[(string) $row['code']] = [
                round((float) ($row['pd'] ?? 0), 3), ($row['cs'] ?? null) === null ? null : round((float) $row['cs'], 3),
                round((float) ($row['pc'] ?? 0), 3), (string) ($row['nivel'] ?? ''),
                (int) ($row['completitud'] ?? 0), !empty($row['preliminar']),
            ];
        }
        ksort($filas);
        return sha1(json_encode($filas));
    }

    /**
     * @param int $courseid
     * @return array Mapa dimensionid => [kind => cantidad de items].
     */
    public static function item_totals(int $courseid): array {
        $totals = [];
        foreach (self::list_items($courseid) as $item) {
            $kind = $item->kind ?? self::KIND_DECLARATIVA;
            $totals[$item->dimensionid][$kind] = ($totals[$item->dimensionid][$kind] ?? 0) + 1;
        }
        return $totals;
    }

    /**
     * Completitud de una dimension (RU-COA-COMPLET-001). Funcion pura.
     *
     * Doc 3 exige dos cosas distintas y las dos a la vez: al menos 80% de los
     * items declarativos, y la totalidad de los situacionales. La asimetria
     * tiene sentido: un declarativo omitido corre el promedio un poco, pero un
     * situacional omitido cambia la proporcion de acierto de golpe —en una
     * dimension con un solo situacional, omitirlo es dejar el componente en
     * blanco.
     *
     * @param int $declaradas Items declarativos respondidos.
     * @param int $totaldeclaradas Items declarativos del instrumento en esa dimension.
     * @param int $situacionales Items situacionales respondidos.
     * @param int $totalsituacionales Items situacionales del instrumento en esa dimension.
     * @return array Con claves porcentaje (int, 0-100) y preliminar (bool).
     */
    public static function completeness(int $declaradas, int $totaldeclaradas,
            int $situacionales, int $totalsituacionales): array {
        $total = $totaldeclaradas + $totalsituacionales;
        $respondidos = min($declaradas, $totaldeclaradas) + min($situacionales, $totalsituacionales);
        $porcentaje = $total > 0 ? (int) round(100 * $respondidos / $total) : 0;

        $declaradaok = $totaldeclaradas > 0
            && ($declaradas / $totaldeclaradas) >= self::COMPLETENESS_DECLARATIVA;
        $situacionalok = $situacionales >= $totalsituacionales;

        return [
            'porcentaje' => $porcentaje,
            'preliminar' => !($declaradaok && $situacionalok),
        ];
    }

    /**
     * Discrepancia entre lo que la persona declara hacer y lo que resuelve
     * frente a una situacion (RU-COA-PRIOR-002, criterio 2). Funcion pura.
     *
     * El signo importa y se conserva: declarar mucho y resolver poco
     * (positiva) sugiere una practica que la persona sobreestima; declarar poco
     * y resolver bien (negativa) sugiere un saber que no reconoce como propio.
     * Las dos son relevantes, pero no piden el mismo apoyo.
     *
     * @param float $pd Practica declarada.
     * @param float|null $cs Componente situacional, o null si no hay.
     * @return float|null PD - CS redondeado, o null si no hay componente situacional.
     */
    public static function discrepancy(float $pd, ?float $cs): ?float {
        return $cs === null ? null : round($pd - $cs, 2);
    }

    /**
     * @param float|null $discrepancia
     * @return bool Si la discrepancia alcanza el umbral de relevancia.
     */
    public static function is_relevant_discrepancy(?float $discrepancia): bool {
        return $discrepancia !== null && abs($discrepancia) >= self::DISCREPANCY_THRESHOLD;
    }

    /**
     * Candidatas a prioridad, ya ordenadas (RU-COA-PRIOR-002). Funcion pura.
     *
     * Aplica en codigo los dos criterios que se pueden calcular sin
     * interpretar: (1) menor puntaje compuesto y (2) discrepancia relevante,
     * como desempate. Los criterios 3 a 6 —necesidades declaradas, relevancia
     * contextual, relacion con el proyecto y tiempo disponible— requieren leer
     * texto libre del perfil, y quedan para el Coach, que recibe la lista ya
     * ordenada y solo puede reordenarla justificandolo. El 7 —no duplicar la
     * misma brecha— se garantiza aqui por construccion: una candidata por
     * dimension.
     *
     * Las dimensiones preliminares no son candidatas: RU-COA-COMPLET-001
     * prohibe metas automaticas sobre resultados incompletos. Tampoco lo es un
     * nivel consolidado: no hay brecha que priorizar.
     *
     * @param array $matrix Filas de get_gap_matrix().
     * @return array Filas candidatas, en orden de prioridad.
     */
    public static function rank_priorities(array $matrix): array {
        $candidates = array_values(array_filter($matrix, function(array $row): bool {
            return empty($row['preliminar']) && $row['nivel'] !== self::LEVEL_CONSOLIDADO;
        }));

        usort($candidates, function(array $a, array $b): int {
            if ($a['pc'] != $b['pc']) {
                return $a['pc'] <=> $b['pc'];
            }
            $disca = self::is_relevant_discrepancy($a['discrepancia'] ?? null) ? abs($a['discrepancia']) : 0.0;
            $discb = self::is_relevant_discrepancy($b['discrepancia'] ?? null) ? abs($b['discrepancia']) : 0.0;
            return $discb <=> $disca;
        });

        return $candidates;
    }

    /**
     * Cuantas prioridades se pueden proponer (RU-COA-003 y RU-COA-COMPLET-001).
     * Funcion pura.
     *
     * El tope general es tres. Si dos o mas dimensiones estan incompletas, Doc 3
     * lo baja a una sola: con la mitad del perfil sin medir, tres prioridades
     * serian tres apuestas, no tres prioridades.
     *
     * @param array $matrix Filas de get_gap_matrix().
     * @param int $max Tope general.
     * @return int
     */
    public static function priority_limit(array $matrix, int $max = pdp::MAX_PRIORIDADES): int {
        $preliminares = count(array_filter($matrix, function(array $row): bool {
            return !empty($row['preliminar']);
        }));
        return $preliminares >= 2 ? 1 : $max;
    }

    /**
     * Nivel de orientacion de una dimension (RU-COA-NIVEL-001). Funcion pura.
     *
     * Es una orientacion para un momento particular, no una clasificacion de la
     * persona: el mismo documento lo exige, y por eso toda salida que muestre
     * el nivel lo acompana de esa advertencia.
     *
     * @param float $puntaje Puntaje compuesto de orientacion de la dimension.
     * @return string Una de las constantes LEVEL_*.
     */
    public static function level_for_score(float $puntaje): string {
        $puntaje = round($puntaje, 2);
        foreach (self::LEVEL_FLOORS as $level => $floor) {
            if ($puntaje >= $floor) {
                return $level;
            }
        }
        return self::LEVEL_PRIORIDAD_ALTA;
    }

    /**
     * Agrupa los cuatro niveles de Doc 3 en las tres categorias de RU-COA-002
     * (fortalezas, brechas prioritarias y ambitos de profundizacion).
     *
     * Los dos requisitos conviven en la matriz v4 sin decir como se
     * corresponden. La correspondencia elegida: Prioridad alta es brecha
     * prioritaria; En desarrollo es ambito de profundizacion; Funcional y
     * Consolidado son fortalezas, porque los dos quedan sobre el punto medio de
     * la escala y describen practica que ya funciona. Es interpretacion de
     * implementacion, declarada en docs/TRAZABILIDAD_RU.md.
     *
     * @param string $level
     * @return string prioritaria, profundizar o fortaleza.
     */
    public static function level_group(string $level): string {
        switch ($level) {
            case self::LEVEL_PRIORIDAD_ALTA:
                return 'prioritaria';
            case self::LEVEL_EN_DESARROLLO:
                return 'profundizar';
            default:
                return 'fortaleza';
        }
    }

    /**
     * @return string[] Niveles en orden, del que mas apoyo pide al que menos.
     */
    public static function levels(): array {
        return [self::LEVEL_PRIORIDAD_ALTA, self::LEVEL_EN_DESARROLLO,
            self::LEVEL_FUNCIONAL, self::LEVEL_CONSOLIDADO];
    }

    /**
     * Contraste entre la aplicacion de entrada y la de salida, que es el
     * indicador de variacion que pide el documento.
     *
     * @param int $userid
     * @param int $courseid
     * @return array Filas con name, entrada, salida y variacion; vacio si falta una de las dos etapas.
     */
    public static function get_progress_contrast(int $userid, int $courseid): array {
        $entrada = self::get_gap_matrix($userid, $courseid, self::STAGE_ENTRADA);
        $salida = self::get_gap_matrix($userid, $courseid, self::STAGE_SALIDA);

        if (empty($entrada) || empty($salida)) {
            return [];
        }

        $salidabyid = [];
        foreach ($salida as $row) {
            $salidabyid[$row['dimensionid']] = $row['pc'];
        }

        $contrast = [];
        foreach ($entrada as $row) {
            if (!isset($salidabyid[$row['dimensionid']])) {
                continue;
            }
            $contrast[] = [
                'name' => $row['name'],
                'entrada' => $row['pc'],
                'salida' => $salidabyid[$row['dimensionid']],
                'variacion' => round($salidabyid[$row['dimensionid']] - $row['pc'], 2),
            ];
        }

        return $contrast;
    }

    /**
     * Version en texto de la matriz de brechas, para incluir como contexto en
     * el prompt del Modo B (Coach). No lleva ningun dato identificatorio: solo
     * dimensiones, puntajes y niveles.
     *
     * La advertencia final no es decorativa: es lo unico que impide que el
     * modelo trate el compuesto como una calificacion (RU-GOB-001).
     *
     * @param int $userid
     * @param int $courseid
     * @return string Cadena vacia si la persona todavia no aplica el diagnostico.
     */
    public static function as_prompt_context(int $userid, int $courseid): string {
        $matrix = self::get_gap_matrix($userid, $courseid);
        if (empty($matrix)) {
            return '';
        }

        $labels = self::level_labels_plain();

        $lines = [];
        foreach ($matrix as $row) {
            $line = "- {$row['name']}: compuesto {$row['pc']}/" . self::SCORE_MAX
                . " ({$labels[$row['nivel']]}"
                . (!empty($row['preliminar']) ? ", RESULTADO PRELIMINAR: completitud {$row['completitud']}%" : '')
                . ")";
            if ($row['hassituacional']) {
                $line .= " | practica declarada {$row['pd']}, componente situacional {$row['cs']}";
            } else {
                $line .= " | practica declarada {$row['pd']}, sin items situacionales respondidos";
            }
            $lines[] = $line;
        }

        return "PERFIL DE RESULTADOS POR DIMENSION (escala " . self::SCORE_MIN . " a " . self::SCORE_MAX . "):\n"
            . implode("\n", $lines) . "\n"
            . "El puntaje compuesto pondera 70% la practica que la persona declara y 30% su"
            . " resolucion de items situacionales. Es una regla funcional para priorizar apoyos:"
            . " no es una escala psicometrica ni una calificacion del desempeno profesional, y no"
            . " debes presentarlo como tal. El nivel es una orientacion para este momento, no una"
            . " clasificacion permanente de la persona. Una dimension marcada como preliminar no"
            . " admite conclusiones: tratala como orientacion general.";
    }

    /**
     * Nombres de los niveles sin tildes, para el contexto que se envia al
     * modelo. La interfaz usa las cadenas de idioma level_*.
     *
     * @return string[]
     */
    public static function level_labels_plain(): array {
        return [
            self::LEVEL_PRIORIDAD_ALTA => 'Prioridad alta',
            self::LEVEL_EN_DESARROLLO => 'En desarrollo',
            self::LEVEL_FUNCIONAL => 'Funcional',
            self::LEVEL_CONSOLIDADO => 'Consolidado',
        ];
    }

    /**
     * Version del instrumento que se aplico, para el contrato de datos del
     * Coach (RU-COA-DATA-001) y el encabezado de la matriz (RU-COA-OUT-001).
     *
     * Se configura por sitio porque el instrumento es editable: cuando cambian
     * items o dimensiones, quien administra sube la version y los resultados
     * nuevos quedan distinguibles de los anteriores.
     *
     * @return string
     */
    public static function instrument_version(): string {
        $version = trim((string) get_config('local_chatbot', 'diag_version'));
        return $version !== '' ? $version : 'DIAG-M1-v1.0';
    }
}
