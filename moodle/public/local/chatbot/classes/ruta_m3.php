<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Barra «Tu ruta del Modulo 3» en las paginas del asistente: las cuatro fases con su estado, «Estas aqui», un resumen y el
 * proximo paso sugerido, con el mismo estilo que la ruta del Modulo 2 (ruta_m2) y la del Modulo 1.
 *
 * Fase 1: ficha de registro de la implementacion validada (Etapa 0). Fase 2: etapas 1 a 3 aceptadas. Fase 3: etapas 4 a 7
 * aceptadas y la consolidacion (etapa 8) confirmada. Fase 4 (evaluacion ex post): ficha ex post validada (etapas 9 y 10) y
 * etapas 11 a 14 aceptadas y la consolidacion (etapa 15) confirmada; se abre con la Fase 3 consolidada.
 *
 * model() es funcion pura y la ejercita cli/verificar_calidad.php; state() lee la base y render() arma el HTML.
 */
class ruta_m3 {

    /** Estado de la ficha de la Fase 1 (y de la ficha ex post de la Fase 4). */
    const FICHA_SIN = 'sin';
    const FICHA_PENDIENTE = 'pendiente';
    const FICHA_NO_APROBADA = 'no_aprobada';
    const FICHA_DESACTUALIZADA = 'desactualizada';
    const FICHA_OK = 'ok';

    /**
     * Modelo de la ruta.
     *
     * @param array $e ficha (FICHA_*), aceptadas (int[]), borradores (int[] con producto sin aceptar), fase y etapa de la
     *        pantalla (etapa 0 en las fichas) y expost (route de expost::route_state(): fases, continuidad, ficha, aceptadas,
     *        borradores; opcional).
     * @return array pasos (1..4 => done, here, locked, avance), resumen [clave, a] y siguiente [clave, a, fase, etapa].
     */
    public static function model(array $e): array {
        $aceptadas = $e['aceptadas'] ?? [];
        $ficha = ($e['ficha'] ?? self::FICHA_SIN) === self::FICHA_OK;
        $hechas2 = count(array_intersect(calidad::stages_of_phase(2), $aceptadas));
        $hechas3 = count(array_intersect(calidad::stages_of_phase(3), $aceptadas));
        $fase2 = $ficha && $hechas2 === count(calidad::stages_of_phase(2));
        $fase3 = $fase2 && $hechas3 === count(calidad::stages_of_phase(3));
        $pantalla = (int) ($e['fase'] ?? 1);

        // Fase 4: se abre con la Fase 3 consolidada.
        $x = (array) ($e['expost'] ?? []);
        $xficha = ($x['ficha'] ?? self::FICHA_SIN) === self::FICHA_OK;
        $xacept = $x['aceptadas'] ?? [];
        $xhechas = count(array_intersect(range(11, expost::CONSOLIDACION), $xacept));
        $fase4 = $fase3 && $xficha && $xhechas === count(range(11, expost::CONSOLIDACION));

        $pasos = [
            1 => ['done' => $ficha, 'locked' => false, 'avance' => ''],
            2 => ['done' => $fase2, 'locked' => !$ficha, 'avance' => $ficha ? $hechas2 . '/3' : ''],
            3 => ['done' => $fase3, 'locked' => !$fase2, 'avance' => $fase2 ? $hechas3 . '/5' : ''],
            4 => ['done' => $fase4, 'locked' => !$fase3, 'avance' => ($fase3 && $xficha) ? $xhechas . '/5' : ''],
        ];
        foreach ($pasos as $n => $p) {
            $pasos[$n]['here'] = $n === $pantalla;
        }

        // Proximo paso: lo primero que falta, en orden.
        if (!$ficha) {
            $clave = ['sin' => 'r3_next_ficha', 'pendiente' => 'r3_next_ficha_espera', 'no_aprobada' => 'r3_next_ficha_corregir',
                'desactualizada' => 'r3_next_ficha_revalidar'][$e['ficha'] ?? self::FICHA_SIN] ?? 'r3_next_ficha';
            $siguiente = [$clave, null, 1, 0];
        } else if ($fase3) {
            if ($fase4) {
                $siguiente = ['r3_next_expost_final', null, 4, expost::CONSOLIDACION];
            } else if (empty($x['continuidad'])) {
                $siguiente = ['r3_next_expost_continuidad', null, 4, 0];
            } else if (!$xficha) {
                $clave = ['sin' => 'r3_next_expost_ficha', 'pendiente' => 'r3_next_expost_espera',
                    'no_aprobada' => 'r3_next_expost_corregir', 'desactualizada' => 'r3_next_expost_revalidar'][$x['ficha'] ?? self::FICHA_SIN]
                    ?? 'r3_next_expost_ficha';
                $siguiente = [$clave, null, 4, 0];
            } else {
                $n = expost::current_stage($xacept);
                if ($n === expost::CONSOLIDACION) {
                    $siguiente = ['r3_next_expost_consolidar', null, 4, $n];
                } else {
                    $siguiente = [in_array($n, $x['borradores'] ?? [], true) ? 'r3_next_aceptar' : 'r3_next_generar',
                        (object) ['n' => $n, 'titulo' => expost::etapas()[$n]['titulo']], 4, $n];
                }
            }
        } else {
            $n = calidad::current_stage($aceptadas);
            if ($n === calidad::CONSOLIDACION) {
                $siguiente = ['r3_next_consolidar', null, 3, $n];
            } else {
                $siguiente = [in_array($n, $e['borradores'] ?? [], true) ? 'r3_next_aceptar' : 'r3_next_generar',
                    (object) ['n' => $n, 'titulo' => calidad::etapas()[$n]['titulo']], calidad::phase_of($n), $n];
            }
        }

        $etapa = (int) ($e['etapa'] ?? 0);
        if ($pantalla === 1) {
            $resumen = ['r3_summary_1', null];
        } else if ($pantalla === 4) {
            $resumen = $etapa >= 11
                ? ['r3_summary_etapa', (object) ['fase' => 4, 'n' => $etapa, 'titulo' => expost::titulos()[$etapa] ?? '',
                    'estado' => in_array($etapa, $xacept, true) ? 'ok' : 'pendiente']]
                : ['r3_summary_4', null];
        } else {
            $todas = calidad::todas();
            $resumen = ['r3_summary_etapa', (object) ['fase' => $pantalla, 'n' => $etapa, 'titulo' => $todas[$etapa]['titulo'] ?? '',
                'estado' => in_array($etapa, $aceptadas, true) ? 'ok' : 'pendiente']];
        }
        if ($fase4) {
            $resumen = ['r3_summary_done', null];
        } else if ($fase3 && $pantalla < 4) {
            $resumen = ['r3_summary_fase3', null];
        }
        return ['pasos' => $pasos, 'resumen' => $resumen, 'siguiente' => $siguiente];
    }

    /**
     * Estado de la persona, leido de la base.
     *
     * @param int $userid
     * @param int $courseid
     * @return array Entrada de model() sin la pantalla.
     */
    public static function state(int $userid, int $courseid): array {
        return self::state_for(implementacion::get_ficha($userid, $courseid));
    }

    /**
     * Estado de una evaluacion (la ficha, su validacion y sus etapas), leido de la base.
     *
     * @param \stdClass|null $ficha
     * @return array Entrada de model() sin la pantalla, mas 'experiencia' (nombre para la lista).
     */
    public static function state_for(?\stdClass $ficha): array {
        $val = $ficha ? implementacion::latest_validation_for((int) $ficha->id) : null;
        if (!$val) {
            $estado = self::FICHA_SIN;
        } else if (in_array($val->estado, [implementacion::PENDIENTE, implementacion::PROCESANDO], true)) {
            $estado = self::FICHA_PENDIENTE;
        } else if ($val->estado !== implementacion::LISTO || !implementacion::can_advance($val->condicion)) {
            $estado = $val->estado === implementacion::LISTO ? self::FICHA_NO_APROBADA : self::FICHA_SIN;
        } else {
            $estado = ($ficha && $val->huella === implementacion::ficha_fingerprint($ficha)) ? self::FICHA_OK
                : self::FICHA_DESACTUALIZADA;
        }
        $filas = $ficha ? calidad::get_by_impl((int) $ficha->id) : [];
        $borradores = [];
        foreach ($filas as $n => $fila) {
            if ($n <= calidad::CONSOLIDACION && $fila->estado !== calidad::ACEPTADO && trim(strip_tags((string) $fila->contenido)) !== '') {
                $borradores[] = (int) $n;
            }
        }
        return ['ficha' => $estado, 'aceptadas' => array_values(array_filter(calidad::accepted($filas, $val ? (string) $val->huella : ''),
            static function(int $n): bool {
                return $n <= calidad::CONSOLIDACION;
            })), 'borradores' => $borradores, 'ajuste' => calidad::decided_adjustment($filas),
            'expost' => expost::route_state($ficha)];
    }

    /**
     * Donde va una evaluacion, en una frase corta para la lista «Tus evaluaciones». Funcion pura.
     *
     * @param array $e Resultado de state_for().
     * @return array [clave de idioma, parametro].
     */
    public static function evaluation_status(array $e): array {
        $aceptadas = $e['aceptadas'] ?? [];
        $x = (array) ($e['expost'] ?? []);
        if (in_array(calidad::CONSOLIDACION, $aceptadas, true)) {
            if (in_array(expost::CONSOLIDACION, $x['aceptadas'] ?? [], true)) {
                return ['r3_status_done', null];
            }
            $hechas = count(array_intersect(range(11, 15), $x['aceptadas'] ?? []));
            return ($x['ficha'] ?? self::FICHA_SIN) === self::FICHA_OK ? ['r3_status_fase4', $hechas] : ['r3_status_fase3_done', null];
        }
        if (($e['ficha'] ?? self::FICHA_SIN) !== self::FICHA_OK) {
            return ['r3_status_fase1', null];
        }
        $hechas = count(array_intersect(range(1, 3), $aceptadas));
        if ($hechas < 3) {
            return ['r3_status_fase2', $hechas];
        }
        return ['r3_status_fase3', count(array_intersect(range(4, 8), $aceptadas))];
    }

    /**
     * La barra en HTML.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $fase Fase de la pantalla.
     * @param int $etapa Etapa de la pantalla (0 en las fichas).
     * @return string
     */
    public static function render(int $userid, int $courseid, int $fase, int $etapa = 0): string {
        $modelo = self::model(self::state($userid, $courseid) + ['fase' => $fase, 'etapa' => $etapa]);
        $url = static function(int $f, int $n) use ($courseid) {
            if ($f === 1) {
                return new \moodle_url('/local/chatbot/ficha_implementacion.php', ['courseid' => $courseid]);
            }
            if ($f === 4) {
                return $n >= 11 ? new \moodle_url('/local/chatbot/expost.php', ['courseid' => $courseid, 'etapa' => $n])
                    : new \moodle_url('/local/chatbot/ficha_expost.php', ['courseid' => $courseid]);
            }
            return new \moodle_url('/local/chatbot/calidad.php', ['courseid' => $courseid] + ($n ? ['etapa' => $n] : []));
        };
        $items = '';
        foreach ($modelo['pasos'] as $n => $p) {
            $clase = $p['here'] ? 'is-here' : ($p['done'] ? 'is-done' : ($p['locked'] ? 'is-locked' : 'is-pending'));
            $clase .= $p['done'] ? ' is-complete' : '';
            $contenido = '<span class="chatbot-routebar-dot">' . ($p['done'] ? '✓' : ($p['locked'] ? '🔒' : $n)) . '</span>'
                . '<span class="chatbot-routebar-label">' . get_string('r3_fase' . $n, 'local_chatbot') . '</span>'
                . ($p['avance'] !== '' && !$p['done'] ? '<span class="chatbot-routebar-optional">'
                    . get_string('r2_avance', 'local_chatbot', $p['avance']) . '</span>' : '')
                . ($p['here'] ? '<span class="chatbot-routebar-here">' . get_string('route_here', 'local_chatbot') . '</span>' : '');
            $enlace = $p['locked']
                ? '<span title="' . s(get_string('r3_locked_hint', 'local_chatbot')) . '">' . $contenido . '</span>'
                : '<a href="' . $url($n, $n === 3 ? 4 : 0)->out() . '"'
                    . ($p['here'] ? ' aria-current="step"' : '') . '>' . $contenido . '</a>';
            $items .= '<li class="chatbot-routebar-step ' . $clase . '">' . $enlace . '</li>';
        }
        [$clave, $a] = $modelo['resumen'];
        if ($clave === 'r3_summary_etapa') {
            $a->estado = get_string('r2_estado_' . $a->estado, 'local_chatbot');
        }
        [$sclave, $sa, $sfase, $setapa] = $modelo['siguiente'];
        $siguiente = '<div class="chatbot-nextaction"><span class="chatbot-nextaction-label">👉 '
            . get_string('na_label', 'local_chatbot') . ':</span> <strong>' . s(get_string($sclave, 'local_chatbot', $sa))
            . '</strong> <a class="btn btn-sm btn-primary ms-1 chatbot-nextaction-go" href="' . $url($sfase, $setapa)->out()
            . '">' . get_string('na_go', 'local_chatbot') . '</a></div>';
        return '<nav class="chatbot-routebar chatbot-routebar-m2" aria-label="' . s(get_string('r3_title', 'local_chatbot')) . '">'
            . '<div class="chatbot-routebar-title">' . get_string('r3_title', 'local_chatbot') . '</div>'
            . '<ol class="chatbot-routebar-steps">' . $items . '</ol>'
            . '<p class="chatbot-routebar-summary">' . s(get_string($clave, 'local_chatbot', $a)) . '</p>'
            . $siguiente . '</nav>';
    }
}
