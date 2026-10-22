<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Informe de los indicadores en PDF, para enviarlo sin que quien lo recibe tenga que entrar al panel.
 *
 * Solo datos agregados: nunca lleva nombres de personas, aunque el panel los muestre con el ajuste «dashpeople».
 */
class dashboard_report {

    /**
     * Reune lo que lleva el informe. Consulta la base de datos.
     *
     * @param int $courseid Curso (el que muestra el panel).
     * @return array
     */
    public static function data(int $courseid): array {
        global $DB;
        $funnel = analytics::route_funnel($courseid);
        $keys = $funnel['keys'];
        $opcionales = array_map(fn($pos) => $keys[$pos - 1], journey::optional_positions($keys));
        $six = in_array('ruta', $keys, true);
        $nombres = [];
        foreach ($keys as $clave) {
            $nombres[$clave] = get_string(course_bridge::step_name_key($clave, $six), 'local_chatbot')
                . (in_array($clave, $opcionales, true) ? ' ' . get_string('funnel_optional', 'local_chatbot') : '');
        }
        $stuck = analytics::route_stuck($courseid);
        $goals = analytics::pdp_goal_outcomes($courseid);
        $gaps = analytics::gap_distribution($courseid);
        $kept = $goals['aceptado'] + $goals['modificado'] + $goals['completado'];
        $pending = $goals['propuesto'] + $goals['postergado'];
        $decided = $kept + $goals['rechazado'];
        $interacciones = 0;
        foreach (analytics::interactions_by_mode($courseid) as $fila) {
            $interacciones += (int) $fila['total'];
        }
        $started = (int) $funnel['iniciaron'];
        $finished = (int) ($funnel['etapas']['cierre'] ?? 0);
        return [
            'curso' => $courseid > 0 ? (string) $DB->get_field('course', 'fullname', ['id' => $courseid]) : '',
            'fecha' => userdate(time(), get_string('strftimedatetimeshort', 'langconfig')),
            'started' => $started, 'finished' => $finished, 'keys' => $keys, 'optional' => $opcionales,
            'nombres' => $nombres, 'etapas' => $funnel['etapas'], 'stuck' => $stuck,
            'goals' => ['total' => $decided + $pending, 'pending' => $pending, 'decided' => $decided, 'kept' => $kept],
            'gaps' => $gaps, 'interacciones' => $interacciones, 'm2' => analytics::module2_overview(),
            'insights' => analytics::insights(['started' => $started, 'finished' => $finished, 'etapas' => $funnel['etapas'],
                'keys' => $keys, 'optional' => $opcionales, 'stuck' => $stuck,
                'goals' => ['total' => $decided + $pending, 'pending' => $pending], 'gaps' => $gaps]),
        ];
    }

    /**
     * La frase de una conclusion y la accion sugerida, con las cadenas de idioma. Las usan el panel y el informe.
     *
     * @param array $ins Una conclusion de analytics::insights().
     * @param callable $nombre Clave de etapa => nombre.
     * @return string[] [frase, accion]
     */
    public static function insight_lines(array $ins, callable $nombre): array {
        $p = (object) $ins['params'];
        if (isset($p->etapa)) {
            $p->etapa = $nombre($p->etapa);
        }
        if (isset($p->from)) {
            $p->from = $nombre($p->from);
            $p->to = $nombre($p->to);
        }
        if (isset($p->dim)) {
            $p->dim = preg_replace('/^D\d+\.\s*/', '', $p->dim);
        }
        if (isset($p->prom)) {
            // Con la coma decimal del idioma, igual que el resto del panel.
            $p->prom = format_float((float) $p->prom, 2);
        }
        return [get_string('insight_' . $ins['id'], 'local_chatbot', $p),
            get_string('insight_' . $ins['id'] . '_act', 'local_chatbot', $p)];
    }

    /**
     * El informe como HTML sencillo (lo que TCPDF sabe dibujar). Sin consultas: solo get_string() sobre $d.
     *
     * @param array $d Resultado de data().
     * @return string
     */
    public static function html(array $d): string {
        $e = fn(string $s): string => s($s);
        $h = '<h1 style="color:#0b4582;">' . $e(get_string('report_title', 'local_chatbot')) . '</h1>';
        $h .= '<p><b>' . $e($d['curso']) . '</b><br>' . $e(get_string('report_generated', 'local_chatbot', $d['fecha']))
            . '<br><i>' . $e(get_string('dash_privacy', 'local_chatbot')) . '</i></p>';

        $h .= '<h2>' . $e(get_string('insights_title', 'local_chatbot')) . '</h2><ul>';
        foreach ($d['insights'] as $ins) {
            [$frase, $accion] = self::insight_lines($ins, fn($k) => $d['nombres'][$k] ?? $k);
            $h .= '<li><b>' . $e($frase) . '</b><br>' . $e($accion) . '</li>';
        }
        $h .= '</ul>';

        $pct = fn(int $a, int $b): string => $b > 0 ? round(100 * $a / $b) . ' %' : '—';
        $h .= '<h2>' . $e(get_string('report_numbers', 'local_chatbot')) . '</h2>'
            . '<table border="1" cellpadding="4"><tr><td width="70%">' . $e(get_string('kpi_started', 'local_chatbot'))
            . '</td><td width="30%"><b>' . (int) $d['started'] . '</b></td></tr>'
            . '<tr><td>' . $e(get_string('kpi_finished', 'local_chatbot')) . '</td><td><b>' . (int) $d['finished']
            . '</b> (' . $pct((int) $d['finished'], (int) $d['started']) . ')</td></tr>'
            . '<tr><td>' . $e(get_string('kpi_goals', 'local_chatbot')) . '</td><td><b>' . (int) $d['goals']['decided']
            . ' / ' . (int) $d['goals']['total'] . '</b></td></tr>'
            . '<tr><td>' . $e(get_string('kpi_interactions', 'local_chatbot')) . '</td><td><b>' . (int) $d['interacciones']
            . '</b></td></tr></table>';

        $h .= '<h2>' . $e(get_string('analytics_funnel', 'local_chatbot')) . '</h2>'
            . '<table border="1" cellpadding="4"><tr bgcolor="#e8f0fa"><td width="60%"><b>' . $e(get_string('report_stage', 'local_chatbot'))
            . '</b></td><td width="20%"><b>' . $e(get_string('report_people', 'local_chatbot')) . '</b></td><td width="20%"><b>%</b></td></tr>';
        foreach ($d['etapas'] as $clave => $n) {
            $h .= '<tr><td>' . $e($d['nombres'][$clave] ?? $clave) . '</td><td>' . (int) $n . '</td><td>'
                . $pct((int) $n, (int) $d['started']) . '</td></tr>';
        }
        $h .= '</table>';

        $h .= '<h2>' . $e(get_string('stuck_title', 'local_chatbot')) . '</h2>'
            . '<table border="1" cellpadding="4"><tr bgcolor="#e8f0fa"><td width="50%"><b>' . $e(get_string('stuck_col_stage', 'local_chatbot'))
            . '</b></td><td width="25%"><b>' . $e(get_string('report_people', 'local_chatbot')) . '</b></td><td width="25%"><b>'
            . $e(get_string('stuck_col_idle', 'local_chatbot', $d['stuck']['dias'])) . '</b></td></tr>';
        foreach ($d['stuck']['etapas'] as $clave => $fila) {
            $h .= '<tr><td>' . $e($d['nombres'][$clave] ?? $clave) . '</td><td>' . (int) $fila['total'] . '</td><td>'
                . (int) $fila['inactivas'] . '</td></tr>';
        }
        $h .= '</table><p>' . $e(get_string('stuck_finished', 'local_chatbot', $d['stuck']['terminaron'])) . '</p>';

        if ($d['gaps']) {
            $h .= '<h2>' . $e(get_string('analytics_gaps', 'local_chatbot')) . '</h2>'
                . '<table border="1" cellpadding="4"><tr bgcolor="#e8f0fa"><td width="64%"><b>' . $e(get_string('diag_dimension', 'local_chatbot'))
                . '</b></td><td width="36%"><b>' . $e(get_string('analytics_groupavg', 'local_chatbot')) . '</b></td></tr>';
            foreach ($d['gaps'] as $g) {
                $nivel = get_string('level_' . diagnostic::level_for_score((float) $g['promedio']), 'local_chatbot');
                $h .= '<tr><td>' . $e($g['name']) . '</td><td>' . $e(format_float($g['promedio'], 2)) . ' / ' . diagnostic::SCORE_MAX
                    . ' · ' . $e($nivel) . '</td></tr>';
            }
            $h .= '</table>';
        }

        foreach ($d['m2'] as $m) {
            $h .= '<h2>' . $e(get_string('m2_title', 'local_chatbot', $m['nombre'])) . '</h2><p>'
                . $e(get_string('m2_fichas', 'local_chatbot')) . ': <b>' . (int) $m['fichas'] . '</b> · '
                . $e(get_string('m2_validadas', 'local_chatbot')) . ': <b>' . (int) $m['validadas'] . '</b><br>'
                . $e(get_string('m2_eval_body', 'local_chatbot', (object) ['evaluadas' => $m['evaluadas'],
                    'aprobadas' => $m['aprobadas'], 'promedio' => $m['promedio'] ?? '—'])) . '</p>';
        }
        return $h;
    }

    /**
     * El informe como PDF.
     *
     * @param array $d Resultado de data().
     * @return string Contenido del PDF.
     */
    public static function pdf(array $d): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');
        $pdf = new \pdf();
        $pdf->SetCreator('Moodle');
        $pdf->SetTitle(get_string('report_title', 'local_chatbot'));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(16, 16, 16);
        $pdf->SetAutoPageBreak(true, 16);
        $pdf->AddPage();
        // freesans trae las tildes, la ñ y los simbolos que usan los textos del panel.
        $pdf->SetFont('freesans', '', 10);
        $pdf->writeHTML(self::html($d), true, false, true, false, '');
        return $pdf->Output('', 'S');
    }
}
