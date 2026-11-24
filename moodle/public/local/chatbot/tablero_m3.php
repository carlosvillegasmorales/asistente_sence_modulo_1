<?php
/**
 * Tablero del Modulo 3 («Propuesta de Dashboard · Módulo 3: indicadores, vistas y uso», Interfases).
 *
 * Tres vistas en la misma pagina:
 *  - persona: cada persona facilitadora, solo con su informacion (sus evaluaciones en el curso), con los avisos automaticos
 *    y la encuesta breve. Es su principal apoyo: no hay acompanamiento humano.
 *  - acumulada: coordinacion (local/chatbot:viewanalytics). Solo agregados, sin nombres: no es seguimiento individual.
 *  - agente: administracion del asistente (local/chatbot:managekb). Indicadores y alertas de la encuesta breve.
 *
 * El calculo vive en classes/tablero_m3.php. Cada grafico lleva el valor escrito y una tabla equivalente.
 * No es calificacion: nada de esto toca el libro de calificaciones.
 */

require_once(__DIR__ . '/../../config.php');

use local_chatbot\calidad;
use local_chatbot\expost;
use local_chatbot\implementacion;
use local_chatbot\tablero_m3;

$courseid = required_param('courseid', PARAM_INT);
if ((int) $courseid === (int) SITEID) {
    throw new moodle_exception('invalidcourseid');
}
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
$sistema = context_system::instance();

$puede = [
    'persona' => has_capability('local/chatbot:use', $context),
    'acumulada' => has_capability('local/chatbot:viewanalytics', $sistema),
    'agente' => has_capability('local/chatbot:managekb', $sistema),
];
$vista = optional_param('vista', '', PARAM_ALPHA);
if (!isset($puede[$vista]) || !$puede[$vista]) {
    $vista = $puede['persona'] && $DB->record_exists('local_chatbot_impl', ['userid' => $USER->id, 'courseid' => $courseid])
        ? 'persona' : (array_keys(array_filter($puede))[0] ?? '');
}
if ($vista === '') {
    require_capability('local/chatbot:use', $context);
}

$pageurl = new moodle_url('/local/chatbot/tablero_m3.php', ['courseid' => $courseid, 'vista' => $vista]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->add_body_class('local-chatbot-dash-page');
$PAGE->set_title(get_string('m3dash_title', 'local_chatbot'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('m3dash_title', 'local_chatbot'));

if (!\local_chatbot\ux::on('tablerom3')) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('m3dash_disabled', 'local_chatbot'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$action = optional_param('action', '', PARAM_ALPHA);

// --- Encuesta breve (vista persona): una por evaluacion y fase, de la propia persona, al consolidar la fase ---
if ($action === 'encuesta' && $vista === 'persona' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $implid = required_param('implid', PARAM_INT);
    $fase = required_param('fase', PARAM_INT);
    $destino = new moodle_url($pageurl, ['implid' => $implid], 'encuesta-' . $fase);
    $impl = implementacion::get_ficha_by_id($implid, $USER->id);
    $mias = ($impl && (int) $impl->courseid === (int) $courseid && in_array($fase, tablero_m3::FASES_ENCUESTA, true))
        ? tablero_m3::load_facts($courseid, [(int) $USER->id], false) : [];
    $h = $mias[$implid] ?? null;
    if (!$h || !$h[$fase === 3 ? 'fase3_ok' : 'fase4_ok']) {
        redirect($destino);
    }
    $raw = [];
    foreach (array_keys(tablero_m3::survey($fase)) as $codigo) {
        $raw[$codigo] = optional_param($codigo, 0, PARAM_INT);
    }
    $raw['p7'] = optional_param('p7', '', PARAM_ALPHA);
    $raw['p7tipo'] = optional_param('p7tipo', '', PARAM_ALPHA);
    $raw['p8'] = optional_param('p8', '', PARAM_TEXT);
    $respuestas = tablero_m3::clean_survey($raw, $fase);
    if ($respuestas === null) {
        redirect($destino, get_string('m3dash_survey_incomplete', 'local_chatbot'), null,
            \core\output\notification::NOTIFY_WARNING);
    }
    if (!$DB->record_exists('local_chatbot_m3_encuesta', ['implid' => $implid, 'fase' => $fase])) {
        $DB->insert_record('local_chatbot_m3_encuesta', (object) ['implid' => $implid, 'userid' => $USER->id,
            'courseid' => $courseid, 'fase' => $fase, 'respuestas' => json_encode($respuestas, JSON_UNESCAPED_UNICODE),
            'timecreated' => time()]);
    }
    redirect($destino, get_string('m3dash_survey_thanks', 'local_chatbot'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// --- Piezas de dibujo ---

/** @var string[] Colores de estado (fijos): bueno, advertencia, grave, critico. Siempre con icono y texto. */
const M3D_ESTADO = ['bueno' => '#0ca30c', 'advertencia' => '#fab219', 'grave' => '#ec835a', 'critico' => '#d03b3b'];
/** @var string Serie unica (azul 450) y serie de comparacion (azul 250). */
const M3D_SERIE = '#2a78d6';
const M3D_SERIE2 = '#86b6ef';

/**
 * Numero o raya si no hay dato.
 *
 * @param float|int|null $v
 * @param int $decimales
 * @param string $sufijo
 * @return string
 */
function local_chatbot_m3d_num($v, int $decimales = 0, string $sufijo = ''): string {
    if ($v === null) {
        return '—';
    }
    return format_float((float) $v, $decimales, true, true) . $sufijo;
}

/**
 * Porcentaje entero o raya.
 *
 * @param int|float|null $v
 * @return string
 */
function local_chatbot_m3d_pct($v): string {
    return $v === null ? '—' : local_chatbot_m3d_num($v, is_float($v) ? 1 : 0, ' %');
}

/**
 * Condicion de una validacion con icono y texto (el color nunca va solo).
 *
 * @param string|null $cond
 * @return string HTML.
 */
function local_chatbot_m3d_cond(?string $cond): string {
    $color = [implementacion::APROBADO => 'bueno', implementacion::OBSERVACIONES => 'advertencia',
        implementacion::NO_APROBADO => 'critico'];
    if (!isset($color[$cond])) {
        return '<span class="m1-muted">—</span>';
    }
    return '<span class="m2d-chip m2d-' . $color[$cond] . '">' . s(get_string('exante_cond_' . $cond, 'local_chatbot')) . '</span>';
}

/**
 * Criticidad de una brecha con texto.
 *
 * @param string|null $crit
 * @return string HTML.
 */
function local_chatbot_m3d_crit(?string $crit): string {
    $color = ['alta' => 'critico', 'media' => 'grave', 'baja' => 'advertencia'];
    if (!isset($color[$crit])) {
        return '<span class="m2d-chip m2d-bueno">' . s(get_string('m3dash_nogap', 'local_chatbot')) . '</span>';
    }
    return '<span class="m2d-chip m2d-' . $color[$crit] . '">' . s(get_string('m3dash_crit_' . $crit, 'local_chatbot')) . '</span>';
}

/**
 * Barra apilada con su leyenda: segmentos [texto, cantidad, color, tinta (light|dark)].
 *
 * @param array $segmentos
 * @param int $total
 * @return string HTML.
 */
function local_chatbot_m3d_stack(array $segmentos, int $total): string {
    $html = '<div class="m1-stack" style="width:100%">';
    foreach ($segmentos as [$texto, $n, $color, $tinta]) {
        if ($n <= 0) {
            continue;
        }
        $pct = $total > 0 ? (int) round($n * 100 / $total) : 0;
        $tip = s($texto . ': ' . $n . ' (' . $pct . '%)');
        $html .= '<span class="m1-seg m1-ink-' . $tinta . '" style="flex-grow:' . $n . ';background:' . $color . '"'
            . ' tabindex="0" role="img" aria-label="' . $tip . '" data-tip="' . $tip . '">' . ($pct >= 9 ? $n : '') . '</span>';
    }
    return $html . '</div>';
}

/**
 * Leyenda: muestra de color junto al texto; el texto no lleva el color.
 *
 * @param array $items Lista de [texto, color, valor|null].
 * @return string HTML.
 */
function local_chatbot_m3d_legend(array $items): string {
    $html = '<ul class="m1-legend">';
    foreach ($items as [$texto, $color, $valor]) {
        $html .= '<li><span class="m1-key" style="background:' . $color . '"></span>' . s($texto)
            . ($valor !== null ? ' <strong>' . s($valor) . '</strong>' : '') . '</li>';
    }
    return $html . '</ul>';
}

/**
 * Fila de barra horizontal con el valor escrito en la punta.
 *
 * @param string $etiqueta
 * @param float|null $valor
 * @param float $maximo
 * @param string $texto Valor escrito.
 * @param string $color
 * @param string $nota
 * @return string HTML.
 */
function local_chatbot_m3d_bar(string $etiqueta, ?float $valor, float $maximo, string $texto, string $color = M3D_SERIE,
        string $nota = ''): string {
    $ancho = $valor !== null && $maximo > 0 ? max(0, min(100, $valor * 100 / $maximo)) : 0;
    $tip = s($etiqueta . ': ' . $texto);
    $html = '<div class="m1-bar-row"><span class="m1-bar-label">' . s($etiqueta)
        . ($nota !== '' ? ' <span class="m1-bar-note">' . s($nota) . '</span>' : '') . '</span>'
        . '<span class="m1-bar-lane m2d-lane"><span class="m2d-track">';
    if ($valor !== null && $ancho > 0) {
        $html .= '<span class="m2d-bar" style="width:' . round($ancho, 1) . '%;background:' . $color . '" tabindex="0"'
            . ' role="img" aria-label="' . $tip . '" data-tip="' . $tip . '"></span>';
    }
    return $html . '</span><span class="m1-bar-value m2d-value">' . s($texto) . '</span></span></div>';
}

/**
 * Estado vacio con lo que hace falta para que aparezca el dato.
 *
 * @param string $clave
 * @return string HTML.
 */
function local_chatbot_m3d_empty(string $clave): string {
    return '<div class="m1-empty"><div><div class="m1-empty-title">' . s(get_string('dash_empty_title', 'local_chatbot'))
        . '</div><div class="m1-empty-hint">' . s(get_string($clave, 'local_chatbot')) . '</div></div></div>';
}

/**
 * Tarjeta de indicador (KPI).
 *
 * @param string $etiqueta
 * @param string $valor HTML.
 * @param string $sub
 * @return string HTML.
 */
function local_chatbot_m3d_kpi(string $etiqueta, string $valor, string $sub = ''): string {
    return '<div class="m1-kpi"><div class="m1-kpi-top"><span class="m1-kpi-label">' . s($etiqueta) . '</span></div>'
        . '<div class="m1-kpi-value">' . $valor . '</div>'
        . ($sub !== '' ? '<div class="m1-kpi-sub">' . s($sub) . '</div>' : '') . '</div>';
}

/**
 * Titulo de una etapa (0 a 15) con su numero.
 *
 * @param int $n
 * @return string
 */
function local_chatbot_m3d_etapa(int $n): string {
    if ($n === 0) {
        return get_string('m3dash_stage_0', 'local_chatbot');
    }
    $titulos = $n <= calidad::CONSOLIDACION ? array_map(static function($e) {
        return $e['titulo'];
    }, calidad::todas()) : expost::titulos();
    return get_string('m2_etapa', 'local_chatbot', $n) . ' · ' . ($titulos[$n] ?? '');
}

/**
 * Pagina a la que lleva una etapa.
 *
 * @param int $n
 * @param int $courseid
 * @return moodle_url
 */
function local_chatbot_m3d_etapa_url(int $n, int $courseid): moodle_url {
    if ($n === 0) {
        return new moodle_url('/local/chatbot/ficha_implementacion.php', ['courseid' => $courseid]);
    }
    if ($n <= calidad::CONSOLIDACION) {
        return new moodle_url('/local/chatbot/calidad.php', ['courseid' => $courseid, 'etapa' => $n]);
    }
    if ($n <= expost::ETAPA_VALIDACION) {
        return new moodle_url('/local/chatbot/ficha_expost.php', ['courseid' => $courseid]);
    }
    return new moodle_url('/local/chatbot/expost.php', ['courseid' => $courseid, 'etapa' => $n]);
}

$ajustes = [calidad::AJUSTE_REMEDIALES => ['bueno', 'light'], calidad::AJUSTE_PARCIAL => ['advertencia', 'dark'],
    calidad::AJUSTE_TOTAL => ['critico', 'light']];
$condiciones = [implementacion::APROBADO => ['bueno', 'light'], implementacion::OBSERVACIONES => ['advertencia', 'dark'],
    implementacion::NO_APROBADO => ['critico', 'light']];
$textos1 = implementacion::checklist_texts();
$textos4 = expost::checklist_texts();
$tipoajuste = static function(?string $a): string {
    return $a === null ? '—' : calidad::adjustment_name($a);
};

echo $OUTPUT->header();
?>
<div class="m1-dash m2d">
    <header class="m1-dash-head">
        <div class="m1-dash-titleblock">
            <h1 class="m1-dash-title"><?php echo s(get_string('m3dash_title', 'local_chatbot')); ?></h1>
            <p class="m1-dash-subtitle"><?php echo s(get_string('m3dash_sub_' . $vista, 'local_chatbot')); ?></p>
            <div class="m1-dash-meta">
                <span class="m1-badge"><?php echo s(get_string('m3dash_privacy_' . $vista, 'local_chatbot')); ?></span>
                <span class="m1-dash-updated"><?php echo s(get_string('dash_updated', 'local_chatbot',
                    userdate(time(), get_string('strftimedatetimeshort', 'langconfig')))); ?></span>
            </div>
        </div>
        <div class="m1-dash-course">
            <span class="m1-dash-course-label"><?php echo s(get_string('analytics_course', 'local_chatbot')); ?></span>
            <h2 class="m1-dash-course-name"><?php echo format_string($course->fullname); ?></h2>
        </div>
    </header>

    <?php if (\local_chatbot\ux::on('pdfm2')): ?>
        <div class="m2d-tools">
            <button type="button" class="m1-export m1-export-pdf m2d-pdf" id="m2d-pdf"
                    data-name="<?php echo s('Tablero_Modulo3_' . $vista . '_' . date('Y-m-d')); ?>"
                    data-wait="<?php echo s(get_string('m2dash_pdf_wait', 'local_chatbot')); ?>"
                    data-html2canvas="<?php echo (new moodle_url('/local/chatbot/js/vendor/html2canvas.min.js'))->out(); ?>"
                    data-jspdf="<?php echo (new moodle_url('/local/chatbot/js/vendor/jspdf.umd.min.js'))->out(); ?>"><?php
                echo s(get_string('m2dash_pdf', 'local_chatbot')); ?></button>
            <span class="m2d-tools-help"><?php echo s(get_string('m2dash_pdf_help', 'local_chatbot')); ?></span>
        </div>
    <?php endif; ?>

    <?php if (count(array_filter($puede)) > 1): ?>
        <nav class="m2d-tabs" aria-label="<?php echo s(get_string('m3dash_views', 'local_chatbot')); ?>">
            <?php foreach (array_keys(array_filter($puede)) as $v): ?>
                <a class="m2d-tab<?php echo $v === $vista ? ' is-active' : ''; ?>"
                   href="<?php echo (new moodle_url('/local/chatbot/tablero_m3.php', ['courseid' => $courseid, 'vista' => $v]))->out(); ?>"
                   <?php echo $v === $vista ? 'aria-current="page"' : ''; ?>><?php
                    echo s(get_string('m3dash_view_' . $v, 'local_chatbot')); ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

<?php
// =====================================================================================================================
// Vista individual
// =====================================================================================================================
if ($vista === 'persona'):
    $mias = tablero_m3::load_facts($courseid, [(int) $USER->id]);
    $implid = optional_param('implid', 0, PARAM_INT);
    $activa = implementacion::get_ficha($USER->id, $courseid);
    if (!isset($mias[$implid])) {
        $implid = $activa && isset($mias[(int) $activa->id]) ? (int) $activa->id : (int) (array_key_last($mias) ?? 0);
    }
    $h = $mias[$implid] ?? null;
    $fichaurl = new moodle_url('/local/chatbot/ficha_implementacion.php', ['courseid' => $courseid]);
    if (!$h): ?>
        <section class="m1-card">
            <?php echo local_chatbot_m3d_empty('m3dash_empty_persona'); ?>
            <p class="m2d-actions"><a class="btn btn-primary" href="<?php echo $fichaurl->out(); ?>"><?php
                echo s(get_string('m3dash_go_ficha', 'local_chatbot')); ?></a></p>
        </section>
    <?php else:
        $plazos = tablero_m3::plazos();
        $avisos = tablero_m3::notices($h, time(), $plazos);
        $textosaviso = tablero_m3::avisos();
        $etapa = $h['etapa'];
        $an = $h['analisis'];
        $cierre = $h['cierre'];
        $materiales = \local_chatbot\tablero_m2::material_cms($courseid);
        ?>
        <?php if (count($mias) > 1): ?>
            <form method="GET" class="m1-dash-filter m2d-filter">
                <input type="hidden" name="courseid" value="<?php echo (int) $courseid; ?>">
                <input type="hidden" name="vista" value="persona">
                <label for="m3d-ficha"><?php echo s(get_string('m3dash_evaluation', 'local_chatbot')); ?></label>
                <div class="m1-select-wrap">
                    <select name="implid" id="m3d-ficha" onchange="this.form.submit()">
                        <?php foreach ($mias as $id => $otra): ?>
                            <option value="<?php echo (int) $id; ?>" <?php echo $id === $implid ? 'selected' : ''; ?>><?php
                                echo s($otra['etiqueta'] ?: get_string('m3dash_evaluation_n', 'local_chatbot', $id)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        <?php endif; ?>

        <section class="m1-card m2d-section" aria-labelledby="m3d-avisos">
            <h2 class="m1-card-title" id="m3d-avisos"><?php echo s(get_string('m3dash_notices', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('m3dash_notices_help', 'local_chatbot')); ?></p>
            <?php if (!$avisos): ?>
                <p class="m2d-ok"><span class="m2d-chip m2d-bueno"><?php echo s(get_string('m3dash_notices_none', 'local_chatbot')); ?></span></p>
            <?php else: ?>
                <ul class="m2d-avisos">
                    <?php foreach ($avisos as $a):
                        $grave = in_array($a['tipo'], ['critico', 'correccion', 'brecha', 'tiempo'], true);
                        $detalle = tablero_m3::notice_detail($a); ?>
                        <li class="m2d-aviso <?php echo $grave ? 'm2d-aviso-critico' : 'm2d-aviso-advertencia'; ?>">
                            <span class="m2d-aviso-icon" aria-hidden="true"><?php echo $grave ? '❌' : '⚠'; ?></span>
                            <div>
                                <strong><?php echo s($textosaviso[$a['tipo']]['nombre']); ?></strong>
                                <span class="m2d-aviso-detalle"><?php echo s(\core_text::strtoupper(\core_text::substr($detalle, 0, 1))
                                    . \core_text::substr($detalle, 1)); ?></span>
                                <span class="m2d-aviso-sug"><?php echo s($textosaviso[$a['tipo']]['sugerencia']); ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <div class="m1-kpis">
            <?php
            echo local_chatbot_m3d_kpi(get_string('m3dash_k_stage', 'local_chatbot'),
                s($etapa === 16 ? get_string('m3dash_stage_done', 'local_chatbot') : get_string('m3dash_stage_n', 'local_chatbot', $etapa)),
                $h['etiqueta']);
            echo local_chatbot_m3d_kpi(get_string('m3dash_k_decision', 'local_chatbot'),
                local_chatbot_m3d_cond($h['f1']['decision_final']),
                get_string('m3dash_k_cycles', 'local_chatbot', count($h['f1']['ciclos'])));
            echo local_chatbot_m3d_kpi(get_string('m3dash_k_adjust', 'local_chatbot'), s($tipoajuste($h['ajuste'])),
                $h['minimo'] ? get_string('m3dash_k_adjust_min', 'local_chatbot', calidad::adjustment_name($h['minimo']))
                    : get_string('m3dash_k_adjust_none', 'local_chatbot'));
            echo local_chatbot_m3d_kpi(get_string('m3dash_k_logro', 'local_chatbot'),
                $cierre && $cierre['final'] !== null ? s(local_chatbot_m3d_num($cierre['final'], 1, ' %'))
                    : s(local_chatbot_m3d_pct($an['promedio'] ?? null)),
                $cierre && $cierre['ganancia'] !== null
                    ? get_string('m3dash_k_gain', 'local_chatbot', ($cierre['ganancia'] > 0 ? '+' : '') . local_chatbot_m3d_num($cierre['ganancia'], 1))
                    : get_string('m3dash_k_logro_ini', 'local_chatbot'));
            ?>
        </div>

        <div class="m1-grid">
            <section class="m1-card m1-span-12">
                <h2 class="m1-card-title"><?php echo s(get_string('m3dash_p_avance', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m3dash_p_avance_help', 'local_chatbot')); ?></p>
                <table class="m1-table">
                    <thead><tr><th scope="col"><?php echo s(get_string('m2dash_col_stage', 'local_chatbot')); ?></th>
                        <th scope="col"><?php echo s(get_string('m2dash_col_state', 'local_chatbot')); ?></th>
                        <th scope="col"><?php echo s(get_string('m2dash_col_rounds', 'local_chatbot')); ?></th>
                        <th scope="col"><?php echo s(get_string('m2dash_col_days', 'local_chatbot')); ?></th></tr></thead>
                    <tbody>
                        <tr><th scope="row"><a href="<?php echo local_chatbot_m3d_etapa_url(0, $courseid)->out(); ?>"><?php
                            echo s(local_chatbot_m3d_etapa(0)); ?></a></th>
                            <td><?php echo s(get_string('m2dash_state_' . ($h['fase1_ok'] ? 'closed' : ($h['f1']['envios'] || $h['f1']['ciclos'] ? 'open' : 'pending')), 'local_chatbot')); ?></td>
                            <td>—</td><td>—</td></tr>
                        <?php foreach ($h['etapas'] as $n => $e):
                            $estado = $e['cerrada'] ? 'closed' : ($e['alcanzada'] ? 'open' : 'pending'); ?>
                            <?php if ($n === 11): ?>
                                <tr><th scope="row"><a href="<?php echo local_chatbot_m3d_etapa_url(10, $courseid)->out(); ?>"><?php
                                    echo s(local_chatbot_m3d_etapa(10)); ?></a></th>
                                    <td><?php echo s(get_string('m2dash_state_' . ($h['expost_ok'] ? 'closed' : ($h['f4']['envios'] || $h['f4']['ciclos'] ? 'open' : 'pending')), 'local_chatbot')); ?></td>
                                    <td>—</td><td>—</td></tr>
                            <?php endif; ?>
                            <tr><th scope="row"><a href="<?php echo local_chatbot_m3d_etapa_url($n, $courseid)->out(); ?>"><?php
                                echo s(local_chatbot_m3d_etapa($n)); ?></a></th>
                                <td><?php echo s(get_string('m2dash_state_' . $estado, 'local_chatbot')); ?></td>
                                <td><?php echo $n === calidad::CONSOLIDACION || $n === expost::CONSOLIDACION ? '—' : (int) $e['rondas']; ?></td>
                                <td><?php echo s(local_chatbot_m3d_num($e['dias'], 1)); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="m1-muted"><?php echo s(get_string('m3dash_duration_total', 'local_chatbot', local_chatbot_m3d_num($h['duracion'], 1))); ?></p>
            </section>

            <?php foreach (['f1' => ['m3dash_p_etapa0', 'm3dash_p_etapa0_help', $textos1, 0],
                    'f4' => ['m3dash_p_etapa10', 'm3dash_p_etapa10_help', $textos4, 10]] as $clave => [$titulo, $ayuda, $textos, $n0]):
                $f = $h[$clave]; ?>
                <section class="m1-card m1-span-6">
                    <h2 class="m1-card-title"><?php echo s(get_string($titulo, 'local_chatbot')); ?></h2>
                    <p class="m1-card-help"><?php echo s(get_string($ayuda, 'local_chatbot')); ?></p>
                    <?php if (!$f['ciclos']): ?>
                        <?php echo local_chatbot_m3d_empty($clave === 'f1' ? 'm3dash_empty_cycles' : 'm3dash_empty_cycles4'); ?>
                    <?php else: ?>
                        <ol class="m2d-ciclos">
                            <?php foreach ($f['ciclos'] as $i => $c): ?>
                                <li><span class="m2d-ciclo-n"><?php echo s(get_string('m2dash_cycle', 'local_chatbot', $i + 1)); ?></span>
                                    <?php echo local_chatbot_m3d_cond($c['condicion']); ?>
                                    <span class="m1-muted"><?php echo s(userdate($c['time'], get_string('strftimedatetimeshort', 'langconfig'))); ?></span></li>
                            <?php endforeach; ?>
                        </ol>
                        <p><?php echo s(get_string('m2dash_cycles_to_ok', 'local_chatbot',
                            $f['ciclos_aprobacion'] ?? get_string('m2dash_not_yet', 'local_chatbot'))); ?>
                            <?php if ($f['correcciones']): ?>
                                · <?php echo s(get_string('m2dash_correction_time', 'local_chatbot',
                                    local_chatbot_m3d_num(tablero_m3::median($f['correcciones']), 1))); ?>
                            <?php endif; ?></p>
                        <?php if ($f['criticos_obs']): ?>
                            <h3 class="m1-m2-sub"><?php echo s(get_string('m3dash_critical_obs', 'local_chatbot')); ?></h3>
                            <ul class="m2d-items">
                                <?php foreach ($f['criticos_obs'] as $id): ?>
                                    <li><?php echo s($textos[$id] ?? $id); ?>
                                        <span class="m1-m2-critico"><?php echo s(get_string('m2_critico', 'local_chatbot')); ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="m1-muted"><?php echo s(get_string('m3dash_critical_none', 'local_chatbot')); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_missing_fields', 'local_chatbot')); ?></h3>
                    <?php if (!$f['faltantes']): ?>
                        <p class="m1-muted"><?php echo s(get_string($f['envios'] ? 'm2dash_missing_none' : 'm2dash_missing_norecord', 'local_chatbot')); ?></p>
                    <?php else: ?>
                        <ul class="m2d-items">
                            <?php foreach ($f['faltantes'] as $campo => $veces): ?>
                                <li><?php echo s($campo); ?> <span class="m1-muted"><?php echo s(get_string('m2dash_times', 'local_chatbot', $veces)); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p class="m1-muted"><?php echo s(get_string('m2dash_submissions', 'local_chatbot')) . ': ' . (int) $f['envios']; ?></p>
                    <?php if ($clave === 'f1'): ?>
                        <table class="m1-table">
                            <tbody>
                                <?php foreach (['guia', 'ejemplo'] as $m): ?>
                                    <tr><th scope="row"><?php echo s(get_string('m2dash_used_' . $m, 'local_chatbot')); ?></th>
                                        <td><?php echo $h['vista_' . $m]
                                            ? '<span class="m2d-chip m2d-bueno">' . s(get_string('m2dash_consulted', 'local_chatbot')) . '</span>'
                                            : '<span class="m1-muted">' . s(get_string('m2dash_not_consulted', 'local_chatbot')) . '</span>';
                                        if (!empty($materiales[$m])): ?>
                                            · <a href="<?php echo (new moodle_url('/mod/resource/view.php', ['id' => $materiales[$m]]))->out(); ?>"><?php
                                                echo s(get_string('m2dash_open', 'local_chatbot')); ?></a>
                                        <?php endif; ?></td></tr>
                                <?php endforeach; ?>
                                <tr><th scope="row"><?php echo s(get_string('m3dash_timeliness', 'local_chatbot')); ?></th>
                                    <td><?php echo $h['oportunidad'] === null ? '<span class="m1-muted">' . s(get_string('m3dash_timeliness_none', 'local_chatbot')) . '</span>'
                                        : s(get_string('m3dash_days', 'local_chatbot', local_chatbot_m3d_num($h['oportunidad'], 1))); ?></td></tr>
                                <tr><th scope="row"><?php echo s(get_string('m3dash_recalc', 'local_chatbot')); ?></th>
                                    <td><?php echo s($h['logros']['recalculados'] . ' / ' . $h['logros']['total']); ?></td></tr>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <p class="m2d-actions"><a class="btn btn-secondary" href="<?php echo local_chatbot_m3d_etapa_url($n0 === 0 ? 0 : 10, $courseid)->out(); ?>"><?php
                        echo s(get_string($clave === 'f1' ? 'm3dash_go_ficha' : 'm3dash_go_expost', 'local_chatbot')); ?></a></p>
                </section>
            <?php endforeach; ?>

            <section class="m1-card m1-span-12">
                <h2 class="m1-card-title"><?php echo s(get_string('m3dash_p_result', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m3dash_p_result_help', 'local_chatbot')); ?></p>
                <?php if (!$an): ?>
                    <?php echo local_chatbot_m3d_empty('m3dash_empty_result'); ?>
                <?php else: ?>
                    <div class="m2d-cifras">
                        <div><span class="m1-m2-num"><?php echo s(local_chatbot_m3d_num($an['promedio'], 1)); ?></span><?php
                            echo s(get_string('m3dash_avg', 'local_chatbot')); ?></div>
                        <div><span class="m1-m2-num"><?php echo s(local_chatbot_m3d_num($an['cobertura'], 1)); ?></span><?php
                            echo s(get_string('m3dash_cover', 'local_chatbot', $an['trabajados'] . '/' . $an['diseno'])); ?></div>
                        <div><span class="m1-m2-num"><?php echo s(array_sum($an['brechas'])); ?></span><?php
                            echo s(get_string('m3dash_gaps', 'local_chatbot', $an['brechas']['alta'] . ' · ' . $an['brechas']['media'] . ' · ' . $an['brechas']['baja'])); ?></div>
                        <div><span class="m1-m2-num"><?php echo (int) $an['pendientes']; ?></span><?php
                            echo s(get_string('m3dash_pending', 'local_chatbot')); ?></div>
                    </div>
                    <h3 class="m1-m2-sub"><?php echo s(get_string('m3dash_by_criterion', 'local_chatbot')); ?></h3>
                    <div class="m1-bars m2d-bars">
                        <?php foreach ($an['criterios'] as $c):
                            echo local_chatbot_m3d_bar(get_string('impl_table41_criterion', 'local_chatbot', $c['n']) . ' ' . shorten_text($c['criterio'], 80),
                                (float) $c['logro'], 100.0, local_chatbot_m3d_num($c['logro'], 1, ' %'), M3D_SERIE,
                                $c['criticidad'] === null ? '' : get_string('m3dash_crit_' . $c['criticidad'], 'local_chatbot'));
                        endforeach; ?>
                    </div>
                    <p class="m1-muted"><?php echo s(get_string('m3dash_level_split', 'local_chatbot', local_chatbot_m3d_num($an['nivelacion_pct'], 1))); ?>
                        · <?php echo s(get_string('m3dash_fidelity', 'local_chatbot', local_chatbot_m3d_num($h['fidelidad'], 1))); ?></p>
                    <p class="m1-muted"><?php echo s(get_string('m3dash_changes_nodata', 'local_chatbot')); ?></p>
                <?php endif; ?>
            </section>

            <section class="m1-card m1-span-6">
                <h2 class="m1-card-title"><?php echo s(get_string('m3dash_p_adjust', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m3dash_p_adjust_help', 'local_chatbot')); ?></p>
                <?php if ($h['ajuste'] === null): ?>
                    <?php echo local_chatbot_m3d_empty('m3dash_empty_adjust'); ?>
                <?php else: ?>
                    <p><strong><?php echo s($tipoajuste($h['ajuste'])); ?></strong></p>
                    <p><?php echo s(get_string('m3dash_determinant', 'local_chatbot', implode(', ', array_map(static function($k) {
                        return get_string('m3dash_det_' . $k, 'local_chatbot');
                    }, (array) ($an['determinantes'] ?? []))) ?: '—')); ?></p>
                    <table class="m1-table">
                        <tbody>
                            <tr><th scope="row"><?php echo s(get_string('m3dash_remaining', 'local_chatbot')); ?></th>
                                <td><?php echo s(local_chatbot_m3d_num($an['restante'] ?? null, 0, ' min')); ?></td></tr>
                            <tr><th scope="row"><?php echo s(get_string('m3dash_continuity', 'local_chatbot')); ?></th>
                                <td><?php echo s(local_chatbot_m3d_num($h['continuidad_min'], 0, ' min')); ?>
                                    <?php echo $h['uso_tiempo'] !== null ? '<span class="m1-muted">(' . (int) $h['uso_tiempo'] . ' %)</span>' : ''; ?></td></tr>
                        </tbody>
                    </table>
                    <?php if ($h['tiempo_excede']): ?>
                        <p class="m2d-alerta"><?php echo s(get_string('m3dash_time_exceeds', 'local_chatbot')); ?></p>
                    <?php endif; ?>
                <?php endif; ?>
                <p class="m2d-actions"><a class="btn btn-secondary" href="<?php echo (new moodle_url('/local/chatbot/calidad.php', ['courseid' => $courseid]))->out(); ?>"><?php
                    echo s(get_string('m3dash_go_calidad', 'local_chatbot')); ?></a></p>
            </section>

            <section class="m1-card m1-span-6">
                <h2 class="m1-card-title"><?php echo s(get_string('m3dash_p_close', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m3dash_p_close_help', 'local_chatbot')); ?></p>
                <?php if (!$cierre): ?>
                    <?php echo local_chatbot_m3d_empty('m3dash_empty_close'); ?>
                <?php else: ?>
                    <div class="m2d-cifras">
                        <div><span class="m1-m2-num"><?php echo s(local_chatbot_m3d_num($cierre['inicial'], 1)); ?></span><?php echo s(get_string('m3dash_initial', 'local_chatbot')); ?></div>
                        <div><span class="m1-m2-num"><?php echo s(local_chatbot_m3d_num($cierre['final'], 1)); ?></span><?php echo s(get_string('m3dash_final', 'local_chatbot')); ?></div>
                        <div><span class="m1-m2-num"><?php echo s(($cierre['ganancia'] > 0 ? '+' : '') . local_chatbot_m3d_num($cierre['ganancia'], 1)); ?></span><?php echo s(get_string('m3dash_gain', 'local_chatbot')); ?></div>
                    </div>
                    <div class="m1-bars m2d-bars">
                        <?php foreach ($cierre['por_criterio'] as $c):
                            $etq = get_string('impl_table41_criterion', 'local_chatbot', $c['k']) . ' ' . shorten_text($c['criterio'], 60);
                            if ($c['inicial'] !== null) {
                                echo local_chatbot_m3d_bar($etq . ' · ' . get_string('m3dash_initial', 'local_chatbot'), (float) $c['inicial'], 100.0,
                                    local_chatbot_m3d_num($c['inicial'], 1, ' %'), M3D_SERIE2);
                            }
                            echo local_chatbot_m3d_bar($etq . ' · ' . get_string('m3dash_final', 'local_chatbot'), $c['final'] === null ? null : (float) $c['final'], 100.0,
                                $c['final'] === null ? get_string('m3dash_pending_persists', 'local_chatbot') : local_chatbot_m3d_num($c['final'], 1, ' %'));
                        endforeach; ?>
                    </div>
                    <table class="m1-table">
                        <tbody>
                            <tr><th scope="row"><?php echo s(get_string('m3dash_levelling', 'local_chatbot')); ?></th>
                                <td><?php echo s($cierre['nivelacion']['mov'] . ' / ' . $cierre['nivelacion']['tot']); ?>
                                    <span class="m1-muted"><?php echo s(get_string('m3dash_estimate', 'local_chatbot')); ?></span></td></tr>
                            <tr><th scope="row"><?php echo s(get_string('m3dash_deepening', 'local_chatbot')); ?></th>
                                <td><?php echo (int) $cierre['profundizacion']; ?></td></tr>
                            <tr><th scope="row"><?php echo s(get_string('m3dash_persistent', 'local_chatbot')); ?></th>
                                <td><?php echo s(get_string('m3dash_persistent_val', 'local_chatbot', (object) ['b' => $cierre['bajo_adecuado'],
                                    'i' => $cierre['insuficientes'], 'p' => $cierre['persisten']])); ?></td></tr>
                            <tr><th scope="row"><?php echo s(get_string('m3dash_reaction', 'local_chatbot')); ?></th>
                                <td><?php echo s(local_chatbot_m3d_num($cierre['reaccion'], 1, ' / 4')); ?></td></tr>
                            <tr><th scope="row"><?php echo s(get_string('m3dash_transfer', 'local_chatbot')); ?></th>
                                <td><?php echo s(local_chatbot_m3d_pct($cierre['transferencia'])); ?></td></tr>
                            <tr><th scope="row"><?php echo s(get_string('m3dash_practices', 'local_chatbot')); ?></th>
                                <td><?php echo s($cierre['bp_confirmadas'] . ' / ' . $cierre['bp_propuestas']); ?></td></tr>
                        </tbody>
                    </table>
                <?php endif; ?>
                <p class="m2d-actions"><a class="btn btn-secondary" href="<?php echo (new moodle_url('/local/chatbot/expost.php', ['courseid' => $courseid]))->out(); ?>"><?php
                    echo s(get_string('m3dash_go_expost', 'local_chatbot')); ?></a></p>
            </section>

            <?php foreach (tablero_m3::FASES_ENCUESTA as $fase):
                $listo = $h[$fase === 3 ? 'fase3_ok' : 'fase4_ok'];
                if (!$listo) {
                    continue;
                }
                $hecha = $h['encuestas'][$fase]; ?>
                <section class="m1-card m1-span-12" id="encuesta-<?php echo $fase; ?>">
                    <h2 class="m1-card-title"><?php echo s(get_string('m3dash_survey_f' . $fase, 'local_chatbot')); ?></h2>
                    <?php if ($hecha): ?>
                        <p><span class="m2d-chip m2d-bueno"><?php echo s(get_string('m3dash_survey_done', 'local_chatbot')); ?></span></p>
                    <?php else: ?>
                        <p class="m1-card-help"><?php echo s(get_string('m3dash_survey_help', 'local_chatbot')); ?></p>
                        <form method="post" action="<?php echo $pageurl->out(); ?>" class="m2d-survey">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="action" value="encuesta">
                            <input type="hidden" name="implid" value="<?php echo (int) $implid; ?>">
                            <input type="hidden" name="fase" value="<?php echo $fase; ?>">
                            <?php foreach (tablero_m3::survey($fase) as $codigo => $pregunta): ?>
                                <fieldset class="m2d-q">
                                    <legend><?php echo s($pregunta['texto']); ?></legend>
                                    <div class="m2d-scale">
                                        <?php for ($v = 1; $v <= 5; $v++): ?>
                                            <label><input type="radio" name="<?php echo $codigo; ?>" value="<?php echo $v; ?>" required>
                                                <span><?php echo $v; ?></span></label>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="m2d-scale-ends m1-muted"><span><?php echo s(get_string('m2dash_scale_1', 'local_chatbot')); ?></span><span><?php
                                        echo s(get_string('m2dash_scale_5', 'local_chatbot')); ?></span></div>
                                </fieldset>
                            <?php endforeach; ?>
                            <fieldset class="m2d-q">
                                <legend><?php echo s(get_string('m3dash_survey_p7', 'local_chatbot')); ?></legend>
                                <div class="m2d-scale">
                                    <label><input type="radio" name="p7" value="no" required> <span><?php echo s(get_string('no')); ?></span></label>
                                    <label><input type="radio" name="p7" value="si" required> <span><?php echo s(get_string('yes')); ?></span></label>
                                </div>
                                <div class="m3d-p7tipo">
                                    <label for="m3d-p7tipo-<?php echo $fase; ?>"><?php echo s(get_string('m3dash_survey_p7tipo', 'local_chatbot')); ?></label>
                                    <select name="p7tipo" id="m3d-p7tipo-<?php echo $fase; ?>">
                                        <option value=""><?php echo s(get_string('m3dash_survey_p7none', 'local_chatbot')); ?></option>
                                        <?php foreach (tablero_m3::error_types() as $codigo => $texto): ?>
                                            <option value="<?php echo $codigo; ?>"><?php echo s($texto); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </fieldset>
                            <fieldset class="m2d-q">
                                <legend><?php echo s(get_string('m3dash_survey_p8', 'local_chatbot')); ?></legend>
                                <textarea name="p8" rows="2" class="form-control" maxlength="<?php echo tablero_m3::COMENTARIO_MAX; ?>"></textarea>
                            </fieldset>
                            <button type="submit" class="btn btn-primary"><?php echo s(get_string('m3dash_survey_send', 'local_chatbot')); ?></button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php
// =====================================================================================================================
// Vista acumulada (coordinacion del programa)
// =====================================================================================================================
elseif ($vista === 'acumulada'):
    $grupos = groups_get_all_groups($courseid);
    $grupo = optional_param('grupo', 0, PARAM_INT);
    $grupo = isset($grupos[$grupo]) ? $grupo : 0;
    $periodo = optional_param('periodo', 'todo', PARAM_ALPHA);
    $periodo = isset(tablero_m3::PERIODOS[$periodo]) ? $periodo : 'todo';
    $inscritos = tablero_m3::enrolled($courseid, $grupo);
    $todos = tablero_m3::load_facts($courseid, null, true);
    // Filtros: cohorte (grupo del curso), organismo ejecutor, plan formativo, modulo y periodo.
    $opciones = ['organismo' => [], 'plan' => [], 'modulo' => []];
    foreach ($todos as $x) {
        foreach (array_keys($opciones) as $k) {
            if ($x[$k] !== '') {
                $opciones[$k][$x[$k]] = $x[$k];
            }
        }
    }
    $filtro = [];
    foreach (array_keys($opciones) as $k) {
        $v = optional_param($k, '', PARAM_RAW_TRIMMED);
        $filtro[$k] = isset($opciones[$k][$v]) ? $v : '';
    }
    $hechos = array_filter($todos, static function(array $x) use ($inscritos, $filtro, $grupo): bool {
        foreach ($filtro as $k => $v) {
            if ($v !== '' && $x[$k] !== $v) {
                return false;
            }
        }
        return $grupo === 0 || in_array($x['userid'], $inscritos, true);
    });
    $hechos = tablero_m3::in_period($hechos, tablero_m3::period_start($periodo, time()));
    $disenos = tablero_m3::count_designs($inscritos);
    $r = tablero_m3::program($hechos, $inscritos, $disenos);
    ?>
    <form method="GET" class="m1-dash-filter m2d-filter m3d-filters">
        <input type="hidden" name="courseid" value="<?php echo (int) $courseid; ?>">
        <input type="hidden" name="vista" value="acumulada">
        <?php if ($grupos): ?>
            <label for="m3d-grupo"><?php echo s(get_string('m3dash_f_cohort', 'local_chatbot')); ?></label>
            <div class="m1-select-wrap"><select name="grupo" id="m3d-grupo" onchange="this.form.submit()">
                <option value="0"><?php echo s(get_string('m3dash_f_all', 'local_chatbot')); ?></option>
                <?php foreach ($grupos as $g): ?>
                    <option value="<?php echo (int) $g->id; ?>" <?php echo (int) $g->id === $grupo ? 'selected' : ''; ?>><?php echo s(format_string($g->name)); ?></option>
                <?php endforeach; ?>
            </select></div>
        <?php endif; ?>
        <?php foreach (['organismo' => 'm3dash_f_org', 'plan' => 'm3dash_f_plan', 'modulo' => 'm3dash_f_module'] as $k => $rotulo): ?>
            <label for="m3d-<?php echo $k; ?>"><?php echo s(get_string($rotulo, 'local_chatbot')); ?></label>
            <div class="m1-select-wrap"><select name="<?php echo $k; ?>" id="m3d-<?php echo $k; ?>" onchange="this.form.submit()">
                <option value=""><?php echo s(get_string('m3dash_f_all', 'local_chatbot')); ?></option>
                <?php foreach ($opciones[$k] as $v): ?>
                    <option value="<?php echo s($v); ?>" <?php echo $v === $filtro[$k] ? 'selected' : ''; ?>><?php echo s(\core_text::substr($v, 0, 70)); ?></option>
                <?php endforeach; ?>
            </select></div>
        <?php endforeach; ?>
        <label for="m3d-periodo"><?php echo s(get_string('m3dash_f_period', 'local_chatbot')); ?></label>
        <div class="m1-select-wrap"><select name="periodo" id="m3d-periodo" onchange="this.form.submit()">
            <option value="todo"><?php echo s(get_string('m3dash_f_all', 'local_chatbot')); ?></option>
            <?php foreach (array_keys(tablero_m3::PERIODOS) as $p): ?>
                <option value="<?php echo $p; ?>" <?php echo $p === $periodo ? 'selected' : ''; ?>><?php echo s(get_string('m3dash_f_period_' . $p, 'local_chatbot')); ?></option>
            <?php endforeach; ?>
        </select></div>
    </form>
    <?php if (!$hechos): ?>
        <section class="m1-card"><?php echo local_chatbot_m3d_empty('m3dash_empty_acumulada'); ?></section>
    <?php else:
        $a = $r['a'];
        $b = $r['b'];
        $c = $r['c'];
        $d = $r['d'];
        $e = $r['e'];
        $f = $r['f'];
        ?>
        <div class="m1-kpis">
            <?php
            echo local_chatbot_m3d_kpi(get_string('m3dash_a_start', 'local_chatbot'), s(local_chatbot_m3d_pct($a['tasa_inicio'])),
                get_string('m3dash_a_start_sub', 'local_chatbot', (object) ['n' => $a['con_ficha'], 'd' => $a['disenos']]));
            echo local_chatbot_m3d_kpi(get_string('m3dash_c_avg', 'local_chatbot'), s(local_chatbot_m3d_pct($c['logro_promedio'])),
                get_string('m3dash_cycles_n', 'local_chatbot', $c['ciclos']));
            echo local_chatbot_m3d_kpi(get_string('m3dash_e_gain', 'local_chatbot'),
                s(($e['ganancia'] > 0 ? '+' : '') . local_chatbot_m3d_num($e['ganancia'], 1) . ' pp'),
                get_string('m3dash_cycles_closed', 'local_chatbot', $e['ciclos']));
            echo local_chatbot_m3d_kpi(get_string('m3dash_d_done', 'local_chatbot'), s(local_chatbot_m3d_num($d['duracion_total'], 1, ' d')),
                get_string('m3dash_d_done_sub', 'local_chatbot'));
            ?>
        </div>
        <div class="m1-grid">
            <section class="m1-card m1-span-6">
                <h2 class="m1-card-title"><?php echo s(get_string('m3dash_g_a', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m3dash_g_a_help', 'local_chatbot')); ?></p>
                <table class="m1-table"><tbody>
                    <tr><th scope="row"><?php echo s(get_string('m3dash_a_start', 'local_chatbot')); ?></th>
                        <td><?php echo s(local_chatbot_m3d_pct($a['tasa_inicio'])); ?> <span class="m1-muted">(<?php echo (int) $a['con_ficha'] . ' / ' . (int) $a['disenos']; ?>)</span></td></tr>
                    <tr><th scope="row"><?php echo s(get_string('m3dash_a_guide', 'local_chatbot')); ?></th>
                        <td><?php echo s(local_chatbot_m3d_pct($a['uso_guia_ejemplo'])); ?> <span class="m1-muted">(<?php echo (int) $a['personas_entrega']; ?>)</span></td></tr>
                    <tr><th scope="row"><?php echo s(get_string('m3dash_a_timely', 'local_chatbot')); ?></th>
                        <td><?php echo s(local_chatbot_m3d_num($a['oportunidad'], 1, ' d')); ?> <span class="m1-muted">(<?php echo (int) $a['con_oportunidad']; ?>)</span></td></tr>
                </tbody></table>
            </section>
            <section class="m1-card m1-span-6">
                <h2 class="m1-card-title"><?php echo s(get_string('m3dash_g_b', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m3dash_g_b_help', 'local_chatbot')); ?></p>
                <table class="m1-table">
                    <thead><tr><th scope="col"></th><th scope="col"><?php echo s(get_string('m3dash_b_registro', 'local_chatbot')); ?></th>
                        <th scope="col"><?php echo s(get_string('m3dash_b_expost', 'local_chatbot')); ?></th></tr></thead>
                    <tbody>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_b_first', 'local_chatbot')); ?></th>
                            <td><?php echo s(local_chatbot_m3d_pct($b['f1']['primer_intento'])); ?></td>
                            <td><?php echo s(local_chatbot_m3d_pct($b['f4']['primer_intento'])); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_b_cycles', 'local_chatbot')); ?></th>
                            <td><?php echo s(local_chatbot_m3d_num($b['f1']['ciclos_promedio'], 1)); ?></td>
                            <td><?php echo s(local_chatbot_m3d_num($b['f4']['ciclos_promedio'], 1)); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_b_critical', 'local_chatbot')); ?></th>
                            <td><?php echo s(local_chatbot_m3d_pct($b['f1']['criticos'])); ?></td>
                            <td><?php echo s(local_chatbot_m3d_pct($b['f4']['criticos'])); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_b_recalc', 'local_chatbot')); ?></th>
                            <td colspan="2"><?php echo s(local_chatbot_m3d_pct($b['recalculados']['pct'])); ?>
                                <span class="m1-muted">(<?php echo (int) $b['recalculados']['n'] . ' / ' . (int) $b['recalculados']['total']; ?>)</span></td></tr>
                    </tbody>
                </table>
            </section>
            <?php foreach (['f1' => ['m3dash_b_decision0', $textos1], 'f4' => ['m3dash_b_decision10', $textos4]] as $clave => [$titulo, $textos]):
                $x = $b[$clave]; ?>
                <section class="m1-card m1-span-6">
                    <h2 class="m1-card-title"><?php echo s(get_string($titulo, 'local_chatbot')); ?></h2>
                    <?php if (!$x['validadas']): ?>
                        <?php echo local_chatbot_m3d_empty('m3dash_empty_validations'); ?>
                    <?php else: ?>
                        <?php foreach (['primera' => 'm3dash_first_cycle', 'final' => 'm3dash_final_cycle'] as $k => $rotulo): ?>
                            <h3 class="m1-m2-sub"><?php echo s(get_string($rotulo, 'local_chatbot')); ?></h3>
                            <?php echo local_chatbot_m3d_stack([
                                [get_string('exante_cond_aprobado', 'local_chatbot'), $x[$k][implementacion::APROBADO], M3D_ESTADO['bueno'], 'light'],
                                [get_string('exante_cond_observaciones', 'local_chatbot'), $x[$k][implementacion::OBSERVACIONES], M3D_ESTADO['advertencia'], 'dark'],
                                [get_string('exante_cond_no_aprobado', 'local_chatbot'), $x[$k][implementacion::NO_APROBADO], M3D_ESTADO['critico'], 'light'],
                            ], $x['validadas']); ?>
                        <?php endforeach; ?>
                        <?php echo local_chatbot_m3d_legend([
                            [get_string('exante_cond_aprobado', 'local_chatbot'), M3D_ESTADO['bueno'], null],
                            [get_string('exante_cond_observaciones', 'local_chatbot'), M3D_ESTADO['advertencia'], null],
                            [get_string('exante_cond_no_aprobado', 'local_chatbot'), M3D_ESTADO['critico'], null]]); ?>
                        <h3 class="m1-m2-sub"><?php echo s(get_string('m3dash_critical_items', 'local_chatbot')); ?></h3>
                        <div class="m1-bars m2d-bars">
                            <?php foreach ($x['items_criticos'] as $id => $it):
                                echo local_chatbot_m3d_bar(shorten_text($textos[$id] ?? $id, 95), (float) ($it['pct'] ?? 0), 100.0,
                                    local_chatbot_m3d_pct($it['pct']) . ' (' . $it['n'] . ')');
                            endforeach; ?>
                        </div>
                        <?php if ($x['faltantes']): ?>
                            <h3 class="m1-m2-sub"><?php echo s(get_string('m3dash_missing_fields', 'local_chatbot')); ?></h3>
                            <ul class="m2d-items">
                                <?php foreach (array_slice($x['faltantes'], 0, 8, true) as $campo => $y): ?>
                                    <li><?php echo s($campo); ?> <span class="m1-muted"><?php echo s(local_chatbot_m3d_pct($y['pct']) . ' (' . $y['n'] . ')'); ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <section class="m1-card m1-span-6">
                <h2 class="m1-card-title"><?php echo s(get_string('m3dash_g_c', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m3dash_g_c_help', 'local_chatbot')); ?></p>
                <?php if (!$c['ciclos']): ?>
                    <?php echo local_chatbot_m3d_empty('m3dash_empty_result'); ?>
                <?php else: ?>
                    <?php echo local_chatbot_m3d_stack([
                        [get_string('m3dash_tramo_menor30', 'local_chatbot'), $c['tramos']['menor30'], M3D_ESTADO['critico'], 'light'],
                        [get_string('m3dash_tramo_30a59', 'local_chatbot'), $c['tramos']['30a59'], M3D_ESTADO['grave'], 'dark'],
                        [get_string('m3dash_tramo_60a89', 'local_chatbot'), $c['tramos']['60a89'], M3D_ESTADO['advertencia'], 'dark'],
                        [get_string('m3dash_tramo_90mas', 'local_chatbot'), $c['tramos']['90mas'], M3D_ESTADO['bueno'], 'light'],
                    ], $c['ciclos']); ?>
                    <?php echo local_chatbot_m3d_legend([
                        [get_string('m3dash_tramo_menor30', 'local_chatbot'), M3D_ESTADO['critico'], (string) $c['tramos']['menor30']],
                        [get_string('m3dash_tramo_30a59', 'local_chatbot'), M3D_ESTADO['grave'], (string) $c['tramos']['30a59']],
                        [get_string('m3dash_tramo_60a89', 'local_chatbot'), M3D_ESTADO['advertencia'], (string) $c['tramos']['60a89']],
                        [get_string('m3dash_tramo_90mas', 'local_chatbot'), M3D_ESTADO['bueno'], (string) $c['tramos']['90mas']]]); ?>
                    <table class="m1-table"><tbody>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_c_avg', 'local_chatbot')); ?></th><td><?php echo s(local_chatbot_m3d_pct($c['logro_promedio'])); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_c_cover', 'local_chatbot')); ?></th>
                            <td><?php echo s(local_chatbot_m3d_pct($c['cobertura_promedio'])); ?> <span class="m1-muted"><?php
                                echo s(get_string('m3dash_c_cover_lt', 'local_chatbot', local_chatbot_m3d_pct($c['cobertura_menor']))); ?></span></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_c_gaps', 'local_chatbot')); ?></th>
                            <td><?php echo s(get_string('m3dash_c_gaps_val', 'local_chatbot', (object) ['a' => $c['brechas']['alta'], 'm' => $c['brechas']['media'],
                                'b' => $c['brechas']['baja'], 'p' => local_chatbot_m3d_num($c['brechas_promedio'], 1), 'c' => local_chatbot_m3d_pct($c['con_brecha_alta'])])); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_c_level', 'local_chatbot')); ?></th><td><?php echo s(local_chatbot_m3d_pct($c['nivelacion_promedio'])); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_c_fidelity', 'local_chatbot')); ?></th><td><?php echo s(local_chatbot_m3d_num($c['fidelidad_promedio'], 1, ' / 4')); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_c_changes', 'local_chatbot')); ?></th><td class="m1-muted"><?php echo s(get_string('m3dash_changes_nodata', 'local_chatbot')); ?></td></tr>
                    </tbody></table>
                <?php endif; ?>
            </section>

            <section class="m1-card m1-span-6">
                <h2 class="m1-card-title"><?php echo s(get_string('m3dash_g_d', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m3dash_g_d_help', 'local_chatbot')); ?></p>
                <?php if (!$d['con_decision']): ?>
                    <?php echo local_chatbot_m3d_empty('m3dash_empty_adjust'); ?>
                <?php else: ?>
                    <?php echo local_chatbot_m3d_stack([
                        [calidad::adjustment_name(calidad::AJUSTE_REMEDIALES), $d['tipos'][calidad::AJUSTE_REMEDIALES]['n'], M3D_ESTADO['bueno'], 'light'],
                        [calidad::adjustment_name(calidad::AJUSTE_PARCIAL), $d['tipos'][calidad::AJUSTE_PARCIAL]['n'], M3D_ESTADO['advertencia'], 'dark'],
                        [calidad::adjustment_name(calidad::AJUSTE_TOTAL), $d['tipos'][calidad::AJUSTE_TOTAL]['n'], M3D_ESTADO['critico'], 'light'],
                    ], $d['con_decision']); ?>
                    <?php echo local_chatbot_m3d_legend([
                        [calidad::adjustment_name(calidad::AJUSTE_REMEDIALES), M3D_ESTADO['bueno'], local_chatbot_m3d_pct($d['tipos'][calidad::AJUSTE_REMEDIALES]['pct'])],
                        [calidad::adjustment_name(calidad::AJUSTE_PARCIAL), M3D_ESTADO['advertencia'], local_chatbot_m3d_pct($d['tipos'][calidad::AJUSTE_PARCIAL]['pct'])],
                        [calidad::adjustment_name(calidad::AJUSTE_TOTAL), M3D_ESTADO['critico'], local_chatbot_m3d_pct($d['tipos'][calidad::AJUSTE_TOTAL]['pct'])]]); ?>
                    <h3 class="m1-m2-sub"><?php echo s(get_string('m3dash_d_determinant', 'local_chatbot')); ?></h3>
                    <div class="m1-bars m2d-bars">
                        <?php foreach ($d['determinantes'] as $k => $y):
                            echo local_chatbot_m3d_bar(get_string('m3dash_det_' . $k, 'local_chatbot'), (float) ($y['pct'] ?? 0), 100.0,
                                local_chatbot_m3d_pct($y['pct']) . ' (' . $y['n'] . ')');
                        endforeach; ?>
                    </div>
                    <p class="m1-muted"><?php echo s(get_string('m3dash_d_time', 'local_chatbot', (object) ['u' => local_chatbot_m3d_pct($d['uso_tiempo'] !== null ? (int) round($d['uso_tiempo']) : null),
                        'r' => local_chatbot_m3d_pct($d['poco_reserva'])])); ?></p>
                <?php endif; ?>
            </section>

            <section class="m1-card m1-span-12">
                <h2 class="m1-card-title"><?php echo s(get_string('m3dash_d_flow', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m3dash_d_flow_help', 'local_chatbot', $d['aprobadas'])); ?></p>
                <table class="m1-table">
                    <thead><tr><th scope="col"><?php echo s(get_string('m2dash_col_stage', 'local_chatbot')); ?></th>
                        <th scope="col"><?php echo s(get_string('m3dash_col_reach', 'local_chatbot')); ?></th>
                        <th scope="col"><?php echo s(get_string('m3dash_col_rounds_avg', 'local_chatbot')); ?></th>
                        <th scope="col"><?php echo s(get_string('m3dash_col_days_med', 'local_chatbot')); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($d['embudo'] as $n => $y): ?>
                            <tr><th scope="row"><?php echo s(local_chatbot_m3d_etapa($n)); ?></th>
                                <td><?php echo s(local_chatbot_m3d_pct($y['pct']) . ' (' . $y['n'] . ')'); ?></td>
                                <td><?php echo s(local_chatbot_m3d_num($d['iteraciones'][$n], 1)); ?></td>
                                <td><?php echo s(local_chatbot_m3d_num($d['duracion_etapa'][$n], 1)); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="m1-card m1-span-6">
                <h2 class="m1-card-title"><?php echo s(get_string('m3dash_g_e', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m3dash_g_e_help', 'local_chatbot')); ?></p>
                <?php if (!$e['ciclos']): ?>
                    <?php echo local_chatbot_m3d_empty('m3dash_empty_close'); ?>
                <?php else: ?>
                    <div class="m1-bars m2d-bars">
                        <?php echo local_chatbot_m3d_bar(get_string('m3dash_initial', 'local_chatbot'), $e['logro_inicial'], 100.0,
                            local_chatbot_m3d_pct($e['logro_inicial']), M3D_SERIE2);
                        echo local_chatbot_m3d_bar(get_string('m3dash_final', 'local_chatbot'), $e['logro_final'], 100.0, local_chatbot_m3d_pct($e['logro_final'])); ?>
                    </div>
                    <table class="m1-table"><tbody>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_gain', 'local_chatbot')); ?></th><td><?php echo s(($e['ganancia'] > 0 ? '+' : '') . local_chatbot_m3d_num($e['ganancia'], 1) . ' pp'); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_e_level', 'local_chatbot')); ?></th>
                            <td><?php echo s(local_chatbot_m3d_pct($e['nivelacion']['pct']) . ' (' . $e['nivelacion']['mov'] . ' / ' . $e['nivelacion']['tot'] . ')'); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_deepening', 'local_chatbot')); ?></th><td><?php echo (int) $e['profundizacion']; ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_persistent', 'local_chatbot')); ?></th>
                            <td><?php echo s(get_string('m3dash_persistent_val', 'local_chatbot', (object) ['b' => $e['bajo_adecuado'], 'i' => $e['insuficientes'], 'p' => $e['persisten']])); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_reaction', 'local_chatbot')); ?></th><td><?php echo s(local_chatbot_m3d_num($e['reaccion'], 1, ' / 4')); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_transfer', 'local_chatbot')); ?></th><td><?php echo s(local_chatbot_m3d_pct($e['transferencia'])); ?> <span class="m1-muted">(<?php echo (int) $e['transferencia_n']; ?>)</span></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m3dash_practices', 'local_chatbot')); ?></th>
                            <td><?php echo s(local_chatbot_m3d_pct($e['bp']['pct']) . ' (' . $e['bp']['confirmadas'] . ' / ' . $e['bp']['propuestas'] . ')'); ?></td></tr>
                    </tbody></table>
                <?php endif; ?>
            </section>

            <section class="m1-card m1-span-6">
                <h2 class="m1-card-title"><?php echo s(get_string('m3dash_g_f', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m3dash_g_f_help', 'local_chatbot')); ?></p>
                <h3 class="m1-m2-sub"><?php echo s(get_string('m3dash_f_score', 'local_chatbot')); ?></h3>
                <table class="m1-table"><tbody>
                    <?php foreach ($f['tramos'] as $banda => $y): ?>
                        <tr><th scope="row"><?php echo s(\local_chatbot\diseno::band(['alto' => 32, 'menores' => 25, 'importantes' => 18, 'no' => 9][$banda])['texto']); ?></th>
                            <td><?php echo s(local_chatbot_m3d_pct($y['promedio'])); ?> <span class="m1-muted">(<?php echo (int) $y['n']; ?>)</span></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
                <h3 class="m1-m2-sub"><?php echo s(get_string('m3dash_f_adjust', 'local_chatbot')); ?></h3>
                <table class="m1-table"><tbody>
                    <?php foreach ($f['ajustes'] as $tipo => $y): ?>
                        <tr><th scope="row"><?php echo s(calidad::adjustment_name($tipo)); ?></th>
                            <td><?php echo s($y['promedio'] === null ? '—' : (($y['promedio'] > 0 ? '+' : '') . local_chatbot_m3d_num($y['promedio'], 1) . ' pp')); ?>
                                <span class="m1-muted">(<?php echo (int) $y['n']; ?>)</span></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
            </section>
        </div>
    <?php endif; ?>

<?php
// =====================================================================================================================
// Vista calidad del agente (administracion del asistente)
// =====================================================================================================================
elseif ($vista === 'agente'):
    $periodo = optional_param('periodo', 'todo', PARAM_ALPHA);
    $periodo = isset(tablero_m3::PERIODOS[$periodo]) ? $periodo : 'todo';
    $ahora = time();
    $desde = tablero_m3::period_start($periodo, $ahora);
    $todos = tablero_m3::load_facts($courseid, null, false);
    $enc = tablero_m3::load_surveys($courseid, $todos, $desde, $ahora);
    $g = tablero_m3::agent($enc['actual'], $enc['aplicadas']);
    $previo = $enc['previo'] ? tablero_m3::agent($enc['previo'], [3 => 0, 4 => 0])['indice'] : null;
    $alertas = tablero_m3::alerts($g, $previo);
    $textosalerta = tablero_m3::alertas();
    ?>
    <form method="GET" class="m1-dash-filter m2d-filter">
        <input type="hidden" name="courseid" value="<?php echo (int) $courseid; ?>">
        <input type="hidden" name="vista" value="agente">
        <label for="m3d-periodo"><?php echo s(get_string('m3dash_f_period', 'local_chatbot')); ?></label>
        <div class="m1-select-wrap"><select name="periodo" id="m3d-periodo" onchange="this.form.submit()">
            <option value="todo"><?php echo s(get_string('m3dash_f_all', 'local_chatbot')); ?></option>
            <?php foreach (array_keys(tablero_m3::PERIODOS) as $p): ?>
                <option value="<?php echo $p; ?>" <?php echo $p === $periodo ? 'selected' : ''; ?>><?php echo s(get_string('m3dash_f_period_' . $p, 'local_chatbot')); ?></option>
            <?php endforeach; ?>
        </select></div>
    </form>

    <section class="m1-card m2d-section">
        <h2 class="m1-card-title"><?php echo s(get_string('m3dash_alerts', 'local_chatbot')); ?></h2>
        <p class="m1-card-help"><?php echo s(get_string('m3dash_alerts_help', 'local_chatbot')); ?></p>
        <?php if (!$alertas): ?>
            <p class="m2d-ok"><span class="m2d-chip m2d-bueno"><?php echo s(get_string($g['respondidas'] ? 'm3dash_alerts_none' : 'm3dash_alerts_nodata', 'local_chatbot')); ?></span></p>
        <?php else: ?>
            <ul class="m2d-avisos">
                <?php foreach ($alertas as $al): $x = $al['a']; ?>
                    <li class="m2d-aviso <?php echo $al['tipo'] === 'respuesta' ? 'm2d-aviso-advertencia' : 'm2d-aviso-critico'; ?>">
                        <span class="m2d-aviso-icon" aria-hidden="true"><?php echo $al['tipo'] === 'respuesta' ? '⚠' : '❌'; ?></span>
                        <div><strong><?php echo s($textosalerta[$al['tipo']]['nombre']); ?></strong>
                            <span class="m2d-aviso-detalle"><?php echo s(get_string('m3dash_alert_' . $al['tipo'], 'local_chatbot', (object) [
                                'indice' => local_chatbot_m3d_num($x['indice'] ?? null, 1), 'previo' => local_chatbot_m3d_num($x['previo'] ?? null, 1),
                                'preguntas' => implode(', ', array_map('strtoupper', $x['preguntas'] ?? [])),
                                'tipos' => implode(', ', array_map(static function($t) {
                                    return calidad::adjustment_name($t) !== $t ? calidad::adjustment_name($t)
                                        : (tablero_m3::error_types()[$t] ?? $t);
                                }, $x['tipos'] ?? [])), 'pct' => local_chatbot_m3d_num($x['pct'] ?? null, 0)])); ?></span>
                            <span class="m2d-aviso-sug"><?php echo s($textosalerta[$al['tipo']]['cuando']); ?></span></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <div class="m1-kpis">
        <?php
        echo local_chatbot_m3d_kpi(get_string('m3dash_g_index', 'local_chatbot'), s(local_chatbot_m3d_num($g['indice'], 1) . ' / 5'),
            get_string('m3dash_g_index_sub', 'local_chatbot', $g['respondidas']));
        echo local_chatbot_m3d_kpi(get_string('m3dash_g_rate', 'local_chatbot'), s(local_chatbot_m3d_pct($g['respuesta']['total'])),
            get_string('m3dash_g_rate_sub', 'local_chatbot', (object) ['r' => $g['respondidas'], 'a' => $g['aplicadas']['3'] + $g['aplicadas']['4']]));
        echo local_chatbot_m3d_kpi(get_string('m3dash_g_errors', 'local_chatbot'), s(local_chatbot_m3d_pct($g['errores']['pct'])),
            get_string('m3dash_g_errors_sub', 'local_chatbot', $g['errores']['n']));
        echo local_chatbot_m3d_kpi(get_string('m3dash_g_time', 'local_chatbot'), s(local_chatbot_m3d_num($g['ahorro'], 1) . ' / 5'),
            get_string('m3dash_g_time_sub', 'local_chatbot'));
        ?>
    </div>
    <?php if (!$g['respondidas']): ?>
        <section class="m1-card"><?php echo local_chatbot_m3d_empty('m3dash_empty_agente'); ?></section>
    <?php else: ?>
        <div class="m1-grid">
            <section class="m1-card m1-span-6">
                <h2 class="m1-card-title"><?php echo s(get_string('m3dash_g_scores', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m3dash_g_scores_help', 'local_chatbot')); ?></p>
                <div class="m1-bars m2d-bars">
                    <?php foreach ([['m3dash_g_clarity', $g['claridad']], ['m3dash_g_precision0', $g['precision']['0']],
                            ['m3dash_g_precision10', $g['precision']['10']], ['m3dash_g_fidelity', $g['fidelidad']],
                            ['m3dash_g_applic3', $g['aplicabilidad']['3']], ['m3dash_g_applic4', $g['aplicabilidad']['4']],
                            ['m3dash_g_time', $g['ahorro']]] as [$rotulo, $v]):
                        echo local_chatbot_m3d_bar(get_string($rotulo, 'local_chatbot'), $v === null ? null : (float) $v, 5.0,
                            $v === null ? '—' : local_chatbot_m3d_num($v, 1) . ' / 5');
                    endforeach; ?>
                </div>
            </section>
            <section class="m1-card m1-span-6">
                <h2 class="m1-card-title"><?php echo s(get_string('m3dash_g_agree', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m3dash_g_agree_help', 'local_chatbot')); ?></p>
                <table class="m1-table"><tbody>
                    <?php foreach ($g['acuerdo'] as $tipo => $y): ?>
                        <tr><th scope="row"><?php echo s(calidad::adjustment_name($tipo)); ?></th>
                            <td><?php echo s(local_chatbot_m3d_pct($y['pct'])); ?> <span class="m1-muted">(<?php echo (int) $y['n']; ?>)</span></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
                <h3 class="m1-m2-sub"><?php echo s(get_string('m3dash_g_errtypes', 'local_chatbot')); ?></h3>
                <table class="m1-table"><tbody>
                    <?php foreach ($g['errores']['tipos'] as $tipo => $n): ?>
                        <tr><th scope="row"><?php echo s(tablero_m3::error_types()[$tipo]); ?></th><td><?php echo (int) $n; ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
                <p class="m1-muted"><?php echo s(get_string('m3dash_g_comments', 'local_chatbot', $g['comentarios'])); ?></p>
            </section>
            <section class="m1-card m1-span-12">
                <h2 class="m1-card-title"><?php echo s(get_string('m3dash_g_comments_title', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m3dash_g_comments_help', 'local_chatbot')); ?></p>
                <?php $comentarios = array_filter($enc['actual'], static function($x) {
                    return !empty($x['respuestas']['p8']);
                }); ?>
                <?php if (!$comentarios): ?>
                    <p class="m1-muted"><?php echo s(get_string('m3dash_g_comments_none', 'local_chatbot')); ?></p>
                <?php else: ?>
                    <ul class="m2d-items">
                        <?php foreach (array_slice($comentarios, -20) as $x): ?>
                            <li><span class="m1-muted"><?php echo s(get_string('m3dash_phase_n', 'local_chatbot', $x['fase'])); ?></span>
                                <?php echo s($x['respuestas']['p8']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
<?php endif; ?>
    <div class="m1-tip" role="tooltip" hidden></div>
</div>
<script>
// Tooltip unico para barras y segmentos: mismo contenido con el mouse y con el teclado; el texto va con textContent.
(function() {
    var root = document.querySelector('.m2d');
    var tip = root && root.querySelector('.m1-tip');
    if (!tip) {
        return;
    }
    function show(el) {
        tip.textContent = el.getAttribute('data-tip');
        tip.hidden = false;
        var r = el.getBoundingClientRect();
        var t = tip.getBoundingClientRect();
        tip.style.left = Math.max(8, Math.min(window.innerWidth - t.width - 8, r.left + r.width / 2 - t.width / 2)) + 'px';
        tip.style.top = (r.top - t.height - 8 < 8 ? r.bottom + 8 : r.top - t.height - 8) + 'px';
    }
    function hide() {
        tip.hidden = true;
    }
    root.addEventListener('pointerover', function(e) {
        var el = e.target.closest('[data-tip]');
        if (el) {
            show(el);
        }
    });
    root.addEventListener('pointerout', hide);
    root.addEventListener('focusin', function(e) {
        if (e.target.matches('[data-tip]')) {
            show(e.target);
        }
    });
    root.addEventListener('focusout', hide);
})();
</script>
<?php if (\local_chatbot\ux::on('pdfm2')): ?>
<script src="<?php echo (new moodle_url('/local/chatbot/js/pdf_tablero.js', ['v' => (string) get_config('local_chatbot', 'version')]))->out(); ?>"></script>
<?php endif;
echo $OUTPUT->footer();
