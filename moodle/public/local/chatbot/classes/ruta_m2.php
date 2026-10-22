<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Barra «Tu ruta del Modulo 2» en las paginas del asistente: las tres fases con su estado, «Estas aqui», un
 * resumen y el proximo paso sugerido, con el mismo estilo que la ruta del Modulo 1 (course_bridge::route_bar).
 *
 * model() es funcion pura y la ejercita cli/verificar_diseno.php; state() lee la base y render() arma el HTML.
 */
class ruta_m2 {

    /** Estado de la ficha de la Fase 1. */
    const FICHA_SIN = 'sin';
    const FICHA_PENDIENTE = 'pendiente';
    const FICHA_NO_APROBADA = 'no_aprobada';
    const FICHA_DESACTUALIZADA = 'desactualizada';
    const FICHA_OK = 'ok';

    /**
     * Modelo de la ruta.
     *
     * @param array $e ficha (FICHA_*), aceptadas (int[]), borradores (int[] con producto sin aceptar),
     *        evaluado (bool, evaluacion vigente), aprobado (bool), fase y etapa de la pantalla (etapa 0 en la ficha).
     * @return array pasos (1..3 => done, here, locked, avance), resumen [clave, a] y siguiente [clave, a, fase, etapa].
     */
    public static function model(array $e): array {
        $aceptadas = $e['aceptadas'] ?? [];
        $ficha = ($e['ficha'] ?? self::FICHA_SIN) === self::FICHA_OK;
        $hechas2 = count(array_intersect(array_keys(diseno::etapas()), $aceptadas));
        $fase2 = $ficha && $hechas2 === count(diseno::etapas());
        $fase3 = $fase2 && !empty($e['aprobado']);
        $pantalla = (int) ($e['fase'] ?? 1);

        $pasos = [
            1 => ['done' => $ficha, 'locked' => false, 'avance' => ''],
            2 => ['done' => $fase2, 'locked' => !$ficha, 'avance' => $ficha ? $hechas2 . '/6' : ''],
            3 => ['done' => $fase3, 'locked' => !$fase2, 'avance' => $fase2 ? (in_array(diseno::CONSOLIDACION,
                $aceptadas, true) ? (empty($e['evaluado']) ? '1/2' : '2/2') : '0/2') : ''],
        ];
        foreach ($pasos as $n => $p) {
            $pasos[$n]['here'] = $n === $pantalla;
        }

        // Proximo paso: lo primero que falta, en orden.
        if (!$ficha) {
            $clave = ['sin' => 'r2_next_ficha', 'pendiente' => 'r2_next_ficha_espera', 'no_aprobada' => 'r2_next_ficha_corregir',
                'desactualizada' => 'r2_next_ficha_revalidar'][$e['ficha'] ?? self::FICHA_SIN] ?? 'r2_next_ficha';
            $siguiente = [$clave, null, 1, 0];
        } else if (!$fase2) {
            $n = diseno::current_stage($aceptadas);
            $titulo = diseno::etapas()[$n]['titulo'];
            $siguiente = [in_array($n, $e['borradores'] ?? [], true) ? 'r2_next_aceptar' : 'r2_next_generar',
                (object) ['n' => $n, 'titulo' => $titulo], 2, $n];
        } else if (!in_array(diseno::CONSOLIDACION, $aceptadas, true)) {
            $siguiente = ['r2_next_consolidar', null, 3, diseno::CONSOLIDACION];
        } else if (empty($e['evaluado'])) {
            $siguiente = ['r2_next_evaluar', null, 3, diseno::EVALUACION];
        } else if (empty($e['aprobado'])) {
            $siguiente = ['r2_next_ajustar', null, 2, 1];
        } else {
            $siguiente = ['r2_next_final', null, 3, diseno::EVALUACION];
        }

        $etapa = (int) ($e['etapa'] ?? 0);
        if ($pantalla === 1) {
            $resumen = ['r2_summary_1', null];
        } else {
            $todas = diseno::todas();
            $resumen = ['r2_summary_etapa', (object) ['fase' => $pantalla, 'n' => $etapa,
                'titulo' => $todas[$etapa]['titulo'] ?? '', 'estado' => in_array($etapa, $aceptadas, true) ? 'ok' : 'pendiente']];
        }
        if ($fase3) {
            $resumen = ['r2_summary_done', null];
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
        $ficha = exante::get_ficha($userid, $courseid);
        $val = exante::latest_validation($userid, $courseid);
        if (!$val) {
            $estado = self::FICHA_SIN;
        } else if (in_array($val->estado, [exante::PENDIENTE, exante::PROCESANDO], true)) {
            $estado = self::FICHA_PENDIENTE;
        } else if ($val->estado !== exante::LISTO || !exante::can_advance($val->condicion)) {
            $estado = $val->estado === exante::LISTO ? self::FICHA_NO_APROBADA : self::FICHA_SIN;
        } else {
            $estado = ($ficha && $val->huella === exante::ficha_fingerprint($ficha)) ? self::FICHA_OK : self::FICHA_DESACTUALIZADA;
        }
        $filas = diseno::get_all($userid, $courseid);
        $borradores = [];
        foreach ($filas as $n => $fila) {
            if ($fila->estado !== diseno::ACEPTADO && trim(strip_tags((string) $fila->contenido)) !== '') {
                $borradores[] = (int) $n;
            }
        }
        $evaluacion = diseno::current_evaluation($filas);
        return ['ficha' => $estado, 'aceptadas' => diseno::accepted($filas), 'borradores' => $borradores,
            'evaluado' => $evaluacion !== null, 'aprobado' => $evaluacion && !empty($evaluacion['banda']['aprobado'])];
    }

    /**
     * La barra en HTML.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $fase Fase de la pantalla.
     * @param int $etapa Etapa de la pantalla (0 en la ficha).
     * @return string
     */
    public static function render(int $userid, int $courseid, int $fase, int $etapa = 0): string {
        $modelo = self::model(self::state($userid, $courseid) + ['fase' => $fase, 'etapa' => $etapa]);
        $url = static function(int $f, int $n) use ($courseid) {
            return $f === 1 ? new \moodle_url('/local/chatbot/ficha_exante.php', ['courseid' => $courseid])
                : new \moodle_url('/local/chatbot/diseno.php', ['courseid' => $courseid] + ($n ? ['etapa' => $n] : []));
        };
        $items = '';
        foreach ($modelo['pasos'] as $n => $p) {
            $clase = $p['here'] ? 'is-here' : ($p['done'] ? 'is-done' : ($p['locked'] ? 'is-locked' : 'is-pending'));
            $clase .= $p['done'] ? ' is-complete' : '';
            $contenido = '<span class="chatbot-routebar-dot">' . ($p['done'] ? '✓' : ($p['locked'] ? '🔒' : $n)) . '</span>'
                . '<span class="chatbot-routebar-label">' . get_string('r2_fase' . $n, 'local_chatbot') . '</span>'
                . ($p['avance'] !== '' && !$p['done'] ? '<span class="chatbot-routebar-optional">'
                    . get_string('r2_avance', 'local_chatbot', $p['avance']) . '</span>' : '')
                . ($p['here'] ? '<span class="chatbot-routebar-here">' . get_string('route_here', 'local_chatbot') . '</span>' : '');
            $enlace = $p['locked']
                ? '<span title="' . s(get_string('r2_locked_hint', 'local_chatbot')) . '">' . $contenido . '</span>'
                : '<a href="' . $url($n, $n === 3 ? diseno::CONSOLIDACION : 0)->out() . '"'
                    . ($p['here'] ? ' aria-current="step"' : '') . '>' . $contenido . '</a>';
            $items .= '<li class="chatbot-routebar-step ' . $clase . '">' . $enlace . '</li>';
        }
        [$clave, $a] = $modelo['resumen'];
        if ($clave === 'r2_summary_etapa') {
            $a->estado = get_string('r2_estado_' . $a->estado, 'local_chatbot');
        }
        [$sclave, $sa, $sfase, $setapa] = $modelo['siguiente'];
        $siguiente = '<div class="chatbot-nextaction"><span class="chatbot-nextaction-label">👉 '
            . get_string('na_label', 'local_chatbot') . ':</span> <strong>' . s(get_string($sclave, 'local_chatbot', $sa))
            . '</strong> <a class="btn btn-sm btn-primary ms-1 chatbot-nextaction-go" href="' . $url($sfase, $setapa)->out()
            . '">' . get_string('na_go', 'local_chatbot') . '</a></div>';
        return '<nav class="chatbot-routebar chatbot-routebar-m2" aria-label="' . s(get_string('r2_title', 'local_chatbot')) . '">'
            . '<div class="chatbot-routebar-title">' . get_string('r2_title', 'local_chatbot') . '</div>'
            . '<ol class="chatbot-routebar-steps">' . $items . '</ol>'
            . '<p class="chatbot-routebar-summary">' . s(get_string($clave, 'local_chatbot', $a)) . '</p>'
            . $siguiente . '</nav>';
    }
}
