<?php
/**
 * Tablero del Modulo 2 («Propuesta de dashboard - Indicadores, vistas y uso», Interfases v3, 30-10-2026).
 *
 * Tres vistas en la misma pagina:
 *  - persona: cada persona facilitadora, solo con su informacion (sus fichas en el curso), con los avisos
 *    automaticos y la encuesta de cierre. Es su principal apoyo: no hay acompanamiento humano.
 *  - programa: coordinacion (local/chatbot:viewanalytics). Solo agregados, sin nombres: no es seguimiento individual.
 *  - agente: administracion del asistente (local/chatbot:managekb). Concordancia con la revision experta de una
 *    muestra e incidencias por tipo; la revision se registra aqui mismo.
 *
 * El calculo vive en classes/tablero_m2.php. Cada grafico lleva el valor escrito y una tabla equivalente.
 * No es calificacion: nada de esto toca el libro de calificaciones.
 */

require_once(__DIR__ . '/../../config.php');

use local_chatbot\diseno;
use local_chatbot\exante;
use local_chatbot\tablero_m2;

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
    'programa' => has_capability('local/chatbot:viewanalytics', $sistema),
    'agente' => has_capability('local/chatbot:managekb', $sistema),
];
$vista = optional_param('vista', '', PARAM_ALPHA);
if (!isset($puede[$vista]) || !$puede[$vista]) {
    $vista = $puede['persona'] && $DB->record_exists('local_chatbot_exante', ['userid' => $USER->id, 'courseid' => $courseid])
        ? 'persona' : (array_keys(array_filter($puede))[0] ?? '');
}
if ($vista === '') {
    require_capability('local/chatbot:use', $context);
}

$pageurl = new moodle_url('/local/chatbot/tablero_m2.php', ['courseid' => $courseid, 'vista' => $vista]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->add_body_class('local-chatbot-dash-page');
$PAGE->set_title(get_string('m2dash_title', 'local_chatbot'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('m2dash_title', 'local_chatbot'));

if (!\local_chatbot\ux::on('tablerom2')) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('m2dash_disabled', 'local_chatbot'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$action = optional_param('action', '', PARAM_ALPHA);

// --- Encuesta de cierre (vista persona): una por ficha evaluada, de la propia persona ---
if ($action === 'encuesta' && $vista === 'persona' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $fichaid = required_param('fichaid', PARAM_INT);
    $ficha = exante::get_ficha_by_id($fichaid, $USER->id);
    $destino = new moodle_url($pageurl, ['fichaid' => $fichaid], 'encuesta');
    $raw = [];
    foreach (array_keys(tablero_m2::encuesta()) as $codigo) {
        $raw[$codigo] = optional_param($codigo, 0, PARAM_INT);
    }
    $respuestas = tablero_m2::clean_survey($raw);
    $evaluada = $ficha && diseno::current_evaluation(diseno::get_by_ficha($fichaid)) !== null;
    if (!$ficha || (int) $ficha->courseid !== (int) $courseid || !$evaluada) {
        redirect($destino);
    }
    if ($respuestas === null) {
        redirect($destino, get_string('m2dash_survey_incomplete', 'local_chatbot'), null,
            \core\output\notification::NOTIFY_WARNING);
    }
    if (!$DB->record_exists('local_chatbot_m2_encuesta', ['fichaid' => $fichaid])) {
        $DB->insert_record('local_chatbot_m2_encuesta', (object) ['fichaid' => $fichaid, 'userid' => $USER->id,
            'courseid' => $courseid, 'respuestas' => json_encode($respuestas), 'timecreated' => time()]);
    }
    redirect($destino, get_string('m2dash_survey_thanks', 'local_chatbot'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// --- Revision experta (vista agente) ---
if ($action === 'guardarrevision' && $vista === 'agente' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $tipo = required_param('tipo', PARAM_ALPHANUM);
    $ref = required_param('ref', PARAM_INT);
    $destino = new moodle_url($pageurl, ['revisar' => $tipo . '-' . $ref]);
    $incidencias = array_values(array_intersect(array_keys(tablero_m2::incidencias()),
        optional_param_array('incidencias', [], PARAM_ALPHA)));
    if ($tipo === 'etapa0') {
        $fila = $DB->get_record('local_chatbot_exante_val', ['id' => $ref, 'courseid' => $courseid, 'estado' => exante::LISTO],
            'id, fichaid, condicion', MUST_EXIST);
        $agente = (string) $fila->condicion;
        $experto = optional_param('decision', '', PARAM_ALPHAEXT);
        $experto = in_array($experto, [exante::APROBADO, exante::OBSERVACIONES, exante::NO_APROBADO], true) ? $experto : null;
    } else if ($tipo === 'etapa8') {
        $fila = $DB->get_record('local_chatbot_diseno', ['id' => $ref, 'courseid' => $courseid,
            'etapa' => diseno::EVALUACION], 'id, fichaid, informe', MUST_EXIST);
        $agente = tablero_m2::agent_scores((array) json_decode((string) $fila->informe, true));
        $raw = [];
        foreach (array_keys(diseno::rubrica()) as $id) {
            $raw[$id] = optional_param('p_' . $id, 0, PARAM_INT);
        }
        $experto = tablero_m2::clean_scores($raw);
    } else {
        throw new moodle_exception('invalidparameter');
    }
    if ($experto === null) {
        redirect($destino, get_string('m2dash_review_incomplete', 'local_chatbot'), null,
            \core\output\notification::NOTIFY_WARNING);
    }
    $registro = (object) ['tipo' => $tipo, 'refid' => $ref, 'fichaid' => (int) $fila->fichaid, 'courseid' => $courseid,
        'revisorid' => $USER->id, 'agente' => json_encode($agente), 'experto' => json_encode($experto),
        'incidencias' => json_encode($incidencias), 'comentario' => optional_param('comentario', '', PARAM_TEXT),
        'timemodified' => time()];
    $previa = $DB->get_record('local_chatbot_m2_revision', ['tipo' => $tipo, 'refid' => $ref]);
    if ($previa) {
        $registro->id = $previa->id;
        $DB->update_record('local_chatbot_m2_revision', $registro);
    } else {
        $registro->timecreated = time();
        $DB->insert_record('local_chatbot_m2_revision', $registro);
    }
    redirect($pageurl, get_string('m2dash_review_saved', 'local_chatbot'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// --- Piezas de dibujo ---

/** @var string[] Colores de estado (fijos): bueno, advertencia, grave, critico. Siempre con icono y texto. */
const M2D_ESTADO = ['bueno' => '#0ca30c', 'advertencia' => '#fab219', 'grave' => '#ec835a', 'critico' => '#d03b3b'];
/** @var string Serie unica (azul 450). */
const M2D_SERIE = '#2a78d6';
/** @var string[] Rampa ordinal de los tramos de la rubrica (azul 650, 500, 350, 250; validada con --ordinal). */
const M2D_TRAMOS = ['alto' => '#104281', 'menores' => '#256abf', 'importantes' => '#5598e7', 'no' => '#86b6ef'];

/**
 * Numero o raya si no hay dato.
 *
 * @param float|int|null $v
 * @param int $decimales
 * @param string $sufijo
 * @return string
 */
function local_chatbot_m2d_num($v, int $decimales = 0, string $sufijo = ''): string {
    if ($v === null) {
        return '—';
    }
    return format_float((float) $v, $decimales, true, true) . $sufijo;
}

/**
 * Condicion de la Etapa 0 con icono y texto (el color nunca va solo).
 *
 * @param string|null $cond
 * @return string HTML.
 */
function local_chatbot_m2d_cond(?string $cond): string {
    $color = [exante::APROBADO => 'bueno', exante::OBSERVACIONES => 'advertencia', exante::NO_APROBADO => 'critico'];
    if (!isset($color[$cond])) {
        return '<span class="m1-muted">—</span>';
    }
    return '<span class="m2d-chip m2d-' . $color[$cond] . '">' . s(get_string('m2dash_cond_' . $cond, 'local_chatbot'))
        . '</span>';
}

/**
 * Barra apilada con su leyenda: segmentos [texto, cantidad, color, tinta (light|dark)].
 *
 * @param array $segmentos
 * @param int $total
 * @return string HTML.
 */
function local_chatbot_m2d_stack(array $segmentos, int $total): string {
    $html = '<div class="m1-stack" style="width:100%">';
    foreach ($segmentos as [$texto, $n, $color, $tinta]) {
        if ($n <= 0) {
            continue;
        }
        $pct = $total > 0 ? (int) round($n * 100 / $total) : 0;
        $tip = s($texto . ': ' . $n . ' (' . $pct . '%)');
        $html .= '<span class="m1-seg m1-ink-' . $tinta . '" style="flex-grow:' . $n . ';background:' . $color . '"'
            . ' tabindex="0" role="img" aria-label="' . $tip . '" data-tip="' . $tip . '">' . ($pct >= 9 ? $n : '')
            . '</span>';
    }
    return $html . '</div>';
}

/**
 * Leyenda: muestra de color junto al texto; el texto no lleva el color.
 *
 * @param array $items Lista de [texto, color, valor|null].
 * @return string HTML.
 */
function local_chatbot_m2d_legend(array $items): string {
    $html = '<ul class="m1-legend">';
    foreach ($items as [$texto, $color, $valor]) {
        $html .= '<li><span class="m1-key" style="background:' . $color . '"></span>' . s($texto)
            . ($valor !== null ? ' <strong>' . s($valor) . '</strong>' : '') . '</li>';
    }
    return $html . '</ul>';
}

/**
 * Fila de barra horizontal de una serie, con el valor escrito en la punta.
 *
 * @param string $etiqueta
 * @param float|null $valor
 * @param float $maximo
 * @param string $texto Valor escrito.
 * @param float|null $tope Marca de tope (por ejemplo, 3 en un criterio limitado).
 * @param string $nota
 * @return string HTML.
 */
function local_chatbot_m2d_bar(string $etiqueta, ?float $valor, float $maximo, string $texto, ?float $tope = null,
        string $nota = ''): string {
    $ancho = $valor !== null && $maximo > 0 ? max(0, min(100, $valor * 100 / $maximo)) : 0;
    $tip = s($etiqueta . ': ' . $texto);
    $html = '<div class="m1-bar-row"><span class="m1-bar-label">' . s($etiqueta)
        . ($nota !== '' ? ' <span class="m1-bar-note">' . s($nota) . '</span>' : '') . '</span>'
        . '<span class="m1-bar-lane m2d-lane"><span class="m2d-track">';
    if ($valor !== null && $ancho > 0) {
        $html .= '<span class="m2d-bar" style="width:' . round($ancho, 1) . '%;background:' . M2D_SERIE . '" tabindex="0"'
            . ' role="img" aria-label="' . $tip . '" data-tip="' . $tip . '"></span>';
    }
    if ($tope !== null && $maximo > 0) {
        // Tope (criterio limitado a 3): una raya sobre la escala, con su explicacion en la nota de la etiqueta.
        $html .= '<span class="m2d-tope" style="left:' . round($tope * 100 / $maximo, 1) . '%" aria-hidden="true"></span>';
    }
    return $html . '</span><span class="m1-bar-value m2d-value">' . s($texto) . '</span></span></div>';
}

/**
 * Estado vacio con lo que hace falta para que aparezca el dato.
 *
 * @param string $clave
 * @return string HTML.
 */
function local_chatbot_m2d_empty(string $clave): string {
    return '<div class="m1-empty"><div><div class="m1-empty-title">'
        . s(get_string('dash_empty_title', 'local_chatbot')) . '</div><div class="m1-empty-hint">'
        . s(get_string($clave, 'local_chatbot')) . '</div></div></div>';
}

/**
 * Tarjeta de indicador (KPI).
 *
 * @param string $etiqueta
 * @param string $valor
 * @param string $sub
 * @return string HTML.
 */
function local_chatbot_m2d_kpi(string $etiqueta, string $valor, string $sub = ''): string {
    return '<div class="m1-kpi"><div class="m1-kpi-top"><span class="m1-kpi-label">' . s($etiqueta) . '</span></div>'
        . '<div class="m1-kpi-value">' . $valor . '</div>'
        . ($sub !== '' ? '<div class="m1-kpi-sub">' . s($sub) . '</div>' : '') . '</div>';
}

/**
 * Nombre corto de una etapa (1 a 8).
 *
 * @param int $n
 * @return string
 */
function local_chatbot_m2d_etapa(int $n): string {
    $todas = diseno::todas();
    return get_string('m2_etapa', 'local_chatbot', $n) . ' · ' . ($todas[$n]['titulo'] ?? '');
}

$condiciones = [exante::APROBADO => ['bueno', 'light'], exante::OBSERVACIONES => ['advertencia', 'dark'],
    exante::NO_APROBADO => ['critico', 'light']];
$textositems = exante::checklist_texts();
$rubrica = diseno::rubrica();
$bandatexto = ['alto' => diseno::band(32)['texto'], 'menores' => diseno::band(25)['texto'],
    'importantes' => diseno::band(18)['texto'], 'no' => diseno::band(9)['texto']];

echo $OUTPUT->header();
?>
<div class="m1-dash m2d">
    <header class="m1-dash-head">
        <div class="m1-dash-titleblock">
            <h1 class="m1-dash-title"><?php echo s(get_string('m2dash_title', 'local_chatbot')); ?></h1>
            <p class="m1-dash-subtitle"><?php echo s(get_string('m2dash_sub_' . $vista, 'local_chatbot')); ?></p>
            <div class="m1-dash-meta">
                <span class="m1-badge"><?php echo s(get_string('m2dash_privacy_' . $vista, 'local_chatbot')); ?></span>
                <span class="m1-dash-updated"><?php echo s(get_string('dash_updated', 'local_chatbot',
                    userdate(time(), get_string('strftimedatetimeshort', 'langconfig')))); ?></span>
            </div>
        </div>
        <div class="m1-dash-course">
            <span class="m1-dash-course-label"><?php echo s(get_string('analytics_course', 'local_chatbot')); ?></span>
            <h2 class="m1-dash-course-name"><?php echo format_string($course->fullname); ?></h2>
        </div>
    </header>

    <?php if (count(array_filter($puede)) > 1): ?>
        <nav class="m2d-tabs" aria-label="<?php echo s(get_string('m2dash_views', 'local_chatbot')); ?>">
            <?php foreach (array_keys(array_filter($puede)) as $v): ?>
                <a class="m2d-tab<?php echo $v === $vista ? ' is-active' : ''; ?>"
                   href="<?php echo (new moodle_url('/local/chatbot/tablero_m2.php', ['courseid' => $courseid, 'vista' => $v]))->out(); ?>"
                   <?php echo $v === $vista ? 'aria-current="page"' : ''; ?>><?php
                    echo s(get_string('m2dash_view_' . $v, 'local_chatbot')); ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

<?php
// =====================================================================================================================
// Vista individual
// =====================================================================================================================
if ($vista === 'persona'):
    $mias = tablero_m2::load_facts($courseid, [(int) $USER->id]);
    $fichaid = optional_param('fichaid', 0, PARAM_INT);
    $activa = exante::get_ficha($USER->id, $courseid);
    if (!isset($mias[$fichaid])) {
        $fichaid = $activa && isset($mias[(int) $activa->id]) ? (int) $activa->id : (int) (array_key_last($mias) ?? 0);
    }
    $h = $mias[$fichaid] ?? null;
    $fichaurl = new moodle_url('/local/chatbot/ficha_exante.php', ['courseid' => $courseid]);
    if (!$h): ?>
        <section class="m1-card">
            <?php echo local_chatbot_m2d_empty('m2dash_empty_persona'); ?>
            <p class="m2d-actions"><a class="btn btn-primary" href="<?php echo $fichaurl->out(); ?>"><?php
                echo s(get_string('m2dash_go_ficha', 'local_chatbot')); ?></a></p>
        </section>
    <?php else:
        $plazos = tablero_m2::plazos();
        $avisos = tablero_m2::notices($h, time(), $plazos);
        $textosaviso = tablero_m2::avisos();
        $etapa = tablero_m2::current_stage($h);
        $ev = $h['evaluacion'];
        $etiqueta = $h['etiqueta'];
        $materiales = tablero_m2::material_cms($courseid);
        $adjuntos = array_keys(exante::attachments($fichaid));
        $disenourl = new moodle_url('/local/chatbot/diseno.php', ['courseid' => $courseid]);
        ?>
        <?php if (count($mias) > 1): ?>
            <form method="GET" class="m1-dash-filter m2d-filter">
                <input type="hidden" name="courseid" value="<?php echo (int) $courseid; ?>">
                <input type="hidden" name="vista" value="persona">
                <label for="m2d-ficha"><?php echo s(get_string('m2dash_design', 'local_chatbot')); ?></label>
                <div class="m1-select-wrap">
                    <select name="fichaid" id="m2d-ficha" onchange="this.form.submit()">
                        <?php foreach ($mias as $id => $otra): ?>
                            <option value="<?php echo (int) $id; ?>" <?php echo $id === $fichaid ? 'selected' : ''; ?>><?php
                                echo s($otra['etiqueta'] ?: get_string('m2dash_design_n', 'local_chatbot', $id)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        <?php endif; ?>

        <section class="m1-card m2d-section" aria-labelledby="m2d-avisos">
            <h2 class="m1-card-title" id="m2d-avisos"><?php echo s(get_string('m2dash_notices', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('m2dash_notices_help', 'local_chatbot')); ?></p>
            <?php if (!$avisos): ?>
                <p class="m2d-ok"><span class="m2d-chip m2d-bueno"><?php
                    echo s(get_string('m2dash_notices_none', 'local_chatbot')); ?></span></p>
            <?php else: ?>
                <ul class="m2d-avisos">
                    <?php foreach ($avisos as $a):
                        $grave = in_array($a['tipo'], ['critico', 'c2nivel1', 'correccion'], true); ?>
                        <li class="m2d-aviso <?php echo $grave ? 'm2d-aviso-critico' : 'm2d-aviso-advertencia'; ?>">
                            <span class="m2d-aviso-icon" aria-hidden="true"><?php echo $grave ? '❌' : '⚠'; ?></span>
                            <div>
                                <strong><?php echo s($textosaviso[$a['tipo']]['nombre']); ?></strong>
                                <span class="m2d-aviso-detalle"><?php echo s(\core_text::strtoupper(
                                    \core_text::substr(tablero_m2::notice_detail($a), 0, 1))
                                    . \core_text::substr(tablero_m2::notice_detail($a), 1)); ?></span>
                                <span class="m2d-aviso-sug"><?php echo s($textosaviso[$a['tipo']]['sugerencia']); ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <div class="m1-kpis">
            <?php
            echo local_chatbot_m2d_kpi(get_string('m2dash_k_stage', 'local_chatbot'),
                s($etapa === 0 ? get_string('m2dash_stage0', 'local_chatbot') : ($etapa === 9
                    ? get_string('m2dash_stage_done', 'local_chatbot') : get_string('m2_etapa', 'local_chatbot', $etapa))),
                $etiqueta);
            echo local_chatbot_m2d_kpi(get_string('m2dash_k_decision', 'local_chatbot'),
                local_chatbot_m2d_cond($h['decision_final']),
                get_string('m2dash_k_cycles', 'local_chatbot', count($h['ciclos'])));
            echo local_chatbot_m2d_kpi(get_string('m2dash_k_unresolved', 'local_chatbot'),
                s(local_chatbot_m2d_num($h['no_resueltas'] ?? ($h['decision_final'] ? count(array_filter($h['items'],
                    static fn($e) => $e !== exante::CUMPLE)) : null))),
                $h['techo_previsto'] !== null ? get_string('m2dash_k_ceiling', 'local_chatbot', $h['techo_previsto'])
                    : get_string('m2dash_k_unresolved_sub', 'local_chatbot'));
            echo local_chatbot_m2d_kpi(get_string('m2dash_k_score', 'local_chatbot'),
                $ev ? s($ev['total']) . '<span class="m2d-de"> / 36</span>' : '—',
                $ev ? $bandatexto[$ev['banda']] : get_string('m2dash_k_score_none', 'local_chatbot'));
            ?>
        </div>

        <div class="m1-grid">
            <section class="m1-card m1-span-6">
                <h2 class="m1-card-title"><?php echo s(get_string('m2dash_p_etapa0', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m2dash_p_etapa0_help', 'local_chatbot')); ?></p>
                <?php if (!$h['ciclos']): ?>
                    <?php echo local_chatbot_m2d_empty('m2dash_empty_cycles'); ?>
                <?php else: ?>
                    <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_cycles', 'local_chatbot')); ?></h3>
                    <ol class="m2d-ciclos">
                        <?php foreach ($h['ciclos'] as $i => $c): ?>
                            <li><span class="m2d-ciclo-n"><?php echo s(get_string('m2dash_cycle', 'local_chatbot', $i + 1)); ?></span>
                                <?php echo local_chatbot_m2d_cond($c['condicion']); ?>
                                <span class="m1-muted"><?php echo s(userdate($c['time'], get_string('strftimedatetimeshort',
                                    'langconfig'))); ?></span></li>
                        <?php endforeach; ?>
                    </ol>
                    <p><?php echo s(get_string('m2dash_cycles_to_ok', 'local_chatbot',
                        $h['ciclos_aprobacion'] ?? get_string('m2dash_not_yet', 'local_chatbot'))); ?>
                        <?php if ($h['correcciones']): ?>
                            · <?php echo s(get_string('m2dash_correction_time', 'local_chatbot',
                                local_chatbot_m2d_num(tablero_m2::median($h['correcciones']), 1))); ?>
                        <?php endif; ?></p>
                    <?php
                    $cuenta = array_count_values($h['items']);
                    $n = count($h['items']);
                    echo local_chatbot_m2d_stack([
                        [get_string('exante_status_cumple', 'local_chatbot'), $cuenta[exante::CUMPLE] ?? 0, M2D_ESTADO['bueno'], 'light'],
                        [get_string('exante_status_parcial', 'local_chatbot'), $cuenta[exante::PARCIAL] ?? 0, M2D_ESTADO['advertencia'], 'dark'],
                        [get_string('exante_status_no_cumple', 'local_chatbot'), $cuenta[exante::NO_CUMPLE] ?? 0, M2D_ESTADO['critico'], 'light'],
                    ], $n);
                    echo local_chatbot_m2d_legend([
                        [get_string('exante_status_cumple', 'local_chatbot'), M2D_ESTADO['bueno'], (string) ($cuenta[exante::CUMPLE] ?? 0)],
                        [get_string('exante_status_parcial', 'local_chatbot'), M2D_ESTADO['advertencia'], (string) ($cuenta[exante::PARCIAL] ?? 0)],
                        [get_string('exante_status_no_cumple', 'local_chatbot'), M2D_ESTADO['critico'], (string) ($cuenta[exante::NO_CUMPLE] ?? 0)],
                    ]);
                    $observados = array_filter($h['items'], static fn($e) => $e !== exante::CUMPLE);
                    ?>
                    <?php if ($observados): ?>
                        <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_items_to_fix', 'local_chatbot')); ?></h3>
                        <ul class="m2d-items">
                            <?php foreach ($observados as $id => $estado): ?>
                                <li><span class="m2d-chip m2d-<?php echo $estado === exante::PARCIAL ? 'advertencia' : 'critico'; ?>"><?php
                                    echo s(get_string('exante_status_' . $estado, 'local_chatbot')); ?></span>
                                    <?php echo s($textositems[$id] ?? $id); ?>
                                    <?php if (in_array($id, exante::CRITICOS, true)): ?>
                                        <span class="m1-m2-critico"><?php echo s(get_string('m2_critico', 'local_chatbot')); ?></span>
                                    <?php endif; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($h['limitados_previstos']): ?>
                        <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_limited_preview', 'local_chatbot')); ?></h3>
                        <ul class="m2d-items">
                            <?php foreach ($h['limitados_previstos'] as $rid => $origen): ?>
                                <li><strong><?php echo s(substr($rid, 1) . '. ' . $rubrica[$rid]['nombre']); ?></strong>
                                    <span class="m1-muted"><?php echo s(diseno::limit_origins($origen)); ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p class="m2d-actions"><a class="btn btn-secondary" href="<?php echo $fichaurl->out(); ?>"><?php
                        echo s(get_string('m2dash_go_ficha', 'local_chatbot')); ?></a></p>
                <?php endif; ?>
            </section>

            <section class="m1-card m1-span-6">
                <h2 class="m1-card-title"><?php echo s(get_string('m2dash_p_entrada', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m2dash_p_entrada_help', 'local_chatbot')); ?></p>
                <table class="m1-table">
                    <tbody>
                        <tr><th scope="row"><?php echo s(get_string('m2dash_plan_attached', 'local_chatbot')); ?></th>
                            <td><?php echo in_array('exante_plan', $adjuntos, true)
                                ? '<span class="m2d-chip m2d-bueno">' . s(get_string('yes')) . '</span>'
                                : '<span class="m2d-chip m2d-critico">' . s(get_string('m2dash_missing', 'local_chatbot')) . '</span>'; ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m2dash_profile_attached', 'local_chatbot')); ?></th>
                            <td><?php echo s(in_array('exante_perfil', $adjuntos, true) ? get_string('yes')
                                : get_string('m2dash_optional_no', 'local_chatbot')); ?></td></tr>
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
                        <tr><th scope="row"><?php echo s(get_string('m2dash_submissions', 'local_chatbot')); ?></th>
                            <td><?php echo (int) $h['envios']; ?></td></tr>
                    </tbody>
                </table>
                <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_missing_fields', 'local_chatbot')); ?></h3>
                <?php if (!$h['faltantes']): ?>
                    <p class="m1-muted"><?php echo s(get_string($h['envios'] ? 'm2dash_missing_none'
                        : 'm2dash_missing_norecord', 'local_chatbot')); ?></p>
                <?php else: ?>
                    <ul class="m2d-items">
                        <?php foreach ($h['faltantes'] as $campo => $veces): ?>
                            <li><?php echo s($campo); ?> <span class="m1-muted"><?php
                                echo s(get_string('m2dash_times', 'local_chatbot', $veces)); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="m1-card m1-span-12">
                <h2 class="m1-card-title"><?php echo s(get_string('m2dash_p_avance', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m2dash_p_avance_help', 'local_chatbot')); ?></p>
                <table class="m1-table">
                    <thead><tr><th scope="col"><?php echo s(get_string('m2dash_col_stage', 'local_chatbot')); ?></th>
                        <th scope="col"><?php echo s(get_string('m2dash_col_state', 'local_chatbot')); ?></th>
                        <th scope="col"><?php echo s(get_string('m2dash_col_rounds', 'local_chatbot')); ?></th>
                        <th scope="col"><?php echo s(get_string('m2dash_col_days', 'local_chatbot')); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($h['etapas'] as $n => $e):
                            $estado = $e['cerrada'] ? 'closed' : ($e['alcanzada'] ? 'open' : 'pending'); ?>
                            <tr><th scope="row"><a href="<?php echo (new moodle_url($disenourl, ['etapa' => $n]))->out(); ?>"><?php
                                echo s(local_chatbot_m2d_etapa($n)); ?></a></th>
                                <td><?php echo s(get_string('m2dash_state_' . $estado, 'local_chatbot')); ?></td>
                                <td><?php echo $n <= 6 ? (int) $e['rondas'] : '—'; ?></td>
                                <td><?php echo s(local_chatbot_m2d_num($e['dias'], 1)); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="m1-muted"><?php echo s(get_string('m2dash_duration_total', 'local_chatbot',
                    local_chatbot_m2d_num($h['duracion'], 1))); ?></p>
            </section>

            <section class="m1-card m1-span-12">
                <h2 class="m1-card-title"><?php echo s(get_string('m2dash_p_etapa8', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m2dash_p_etapa8_help', 'local_chatbot')); ?></p>
                <?php if (!$ev): ?>
                    <?php echo local_chatbot_m2d_empty('m2dash_empty_eval'); ?>
                <?php else: ?>
                    <div class="m2d-cifras">
                        <div><span class="m1-m2-num"><?php echo (int) $ev['total']; ?></span><?php
                            echo s(get_string('m2dash_total', 'local_chatbot')); ?></div>
                        <div><span class="m1-m2-num"><?php echo (int) $ev['maximo']; ?></span><?php
                            echo s(get_string('m2dash_ceiling', 'local_chatbot')); ?></div>
                        <div><span class="m1-m2-num"><?php echo (int) $ev['brecha']; ?></span><?php
                            echo s(get_string('m2dash_gap', 'local_chatbot')); ?></div>
                        <div><span class="m1-m2-num"><?php echo count($ev['limitados']); ?></span><?php
                            echo s(get_string('m2dash_limited', 'local_chatbot')); ?></div>
                    </div>
                    <p><?php echo s(get_string('m2dash_band', 'local_chatbot', $bandatexto[$ev['banda']])); ?>
                        <?php echo s(get_string('m2dash_gap_help', 'local_chatbot', (object) ['techo' => 36 - $ev['maximo'],
                            'brecha' => $ev['brecha']])); ?></p>
                    <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_by_criterion', 'local_chatbot')); ?></h3>
                    <div class="m1-bars m2d-bars">
                        <?php foreach ($rubrica as $rid => $c):
                            $p = $ev['criterios'][$rid] ?? null;
                            $limitado = in_array($rid, $ev['limitados'], true);
                            $nota = $limitado ? get_string('m2dash_limited_tag', 'local_chatbot')
                                : ($p === 1 ? get_string('m2dash_level1_tag', 'local_chatbot') : '');
                            echo local_chatbot_m2d_bar(substr($rid, 1) . '. ' . $c['nombre'], $p, 4,
                                $p === null ? '—' : $p . ' / 4', $limitado ? 3.0 : null, $nota);
                        endforeach; ?>
                    </div>
                    <?php if ($ev['nivel1']): ?>
                        <p class="m2d-alerta"><?php echo s(get_string(in_array('r2', $ev['nivel1'], true)
                            ? 'm2dash_level1_c2' : 'm2dash_level1', 'local_chatbot', count($ev['nivel1']))); ?></p>
                    <?php endif; ?>
                    <p class="m2d-actions"><a class="btn btn-secondary" href="<?php
                        echo (new moodle_url($disenourl, ['etapa' => diseno::EVALUACION]))->out(); ?>"><?php
                        echo s(get_string('m2dash_go_eval', 'local_chatbot')); ?></a></p>
                <?php endif; ?>
            </section>

            <?php if ($ev): ?>
                <section class="m1-card m1-span-12" id="encuesta">
                    <h2 class="m1-card-title"><?php echo s(get_string('m2dash_survey', 'local_chatbot')); ?></h2>
                    <?php if ($h['encuesta']): ?>
                        <p><span class="m2d-chip m2d-bueno"><?php echo s(get_string('m2dash_survey_done', 'local_chatbot')); ?></span></p>
                    <?php else: ?>
                        <p class="m1-card-help"><?php echo s(get_string('m2dash_survey_help', 'local_chatbot')); ?></p>
                        <form method="post" action="<?php echo $pageurl->out(); ?>" class="m2d-survey">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="action" value="encuesta">
                            <input type="hidden" name="fichaid" value="<?php echo (int) $fichaid; ?>">
                            <?php foreach (tablero_m2::encuesta() as $codigo => $pregunta): ?>
                                <fieldset class="m2d-q">
                                    <legend><?php echo s($pregunta['texto']); ?></legend>
                                    <div class="m2d-scale">
                                        <?php for ($v = 1; $v <= 5; $v++): ?>
                                            <label><input type="radio" name="<?php echo $codigo; ?>" value="<?php echo $v; ?>" required>
                                                <span><?php echo $v; ?></span></label>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="m2d-scale-ends m1-muted"><span><?php
                                        echo s(get_string('m2dash_scale_1', 'local_chatbot')); ?></span><span><?php
                                        echo s(get_string('m2dash_scale_5', 'local_chatbot')); ?></span></div>
                                </fieldset>
                            <?php endforeach; ?>
                            <button type="submit" class="btn btn-primary"><?php echo s(get_string('m2dash_survey_send', 'local_chatbot')); ?></button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php
// =====================================================================================================================
// Vista agregada (coordinacion del programa)
// =====================================================================================================================
elseif ($vista === 'programa'):
    $grupos = groups_get_all_groups($courseid);
    $grupo = optional_param('grupo', 0, PARAM_INT);
    $grupo = isset($grupos[$grupo]) ? $grupo : 0;
    // Periodo: fichas con actividad en la ultima semana, mes, semestre o año (pedido del cliente del 02-11).
    $periodo = optional_param('periodo', 'todo', PARAM_ALPHA);
    $periodo = isset(tablero_m2::PERIODOS[$periodo]) ? $periodo : 'todo';
    $inscritos = tablero_m2::enrolled($courseid, $grupo);
    $todas = tablero_m2::load_facts($courseid, $grupo ? $inscritos : null);
    $hechos = tablero_m2::in_period($todas, tablero_m2::period_start($periodo, time()));
    $r = tablero_m2::program(array_values($hechos), $inscritos);
    ?>
    <form method="GET" class="m1-dash-filter m2d-filter">
        <input type="hidden" name="courseid" value="<?php echo (int) $courseid; ?>">
        <input type="hidden" name="vista" value="programa">
        <label for="m2d-periodo"><?php echo s(get_string('m2dash_period', 'local_chatbot')); ?></label>
        <div class="m1-select-wrap">
            <select name="periodo" id="m2d-periodo" onchange="this.form.submit()">
                <?php foreach (array_merge(['todo'], array_keys(tablero_m2::PERIODOS)) as $p): ?>
                    <option value="<?php echo $p; ?>" <?php echo $p === $periodo ? 'selected' : ''; ?>><?php
                        echo s(get_string('m2dash_period_' . $p, 'local_chatbot')); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($grupos): ?>
            <label for="m2d-grupo"><?php echo s(get_string('m2dash_cohort', 'local_chatbot')); ?></label>
            <div class="m1-select-wrap">
                <select name="grupo" id="m2d-grupo" onchange="this.form.submit()">
                    <option value="0"><?php echo s(get_string('m2dash_cohort_all', 'local_chatbot')); ?></option>
                    <?php foreach ($grupos as $g): ?>
                        <option value="<?php echo (int) $g->id; ?>" <?php echo (int) $g->id === $grupo ? 'selected' : ''; ?>><?php
                            echo format_string($g->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </form>
    <?php if ($periodo !== 'todo'): ?>
        <p class="m2d-nota"><?php echo s(get_string('m2dash_period_note', 'local_chatbot', (object) ['n' => count($hechos),
            'de' => count($todas)])); ?></p>
    <?php endif; ?>

    <?php if ($r['externos']): ?>
        <p class="m2d-nota"><?php echo s(get_string('m2dash_external', 'local_chatbot', $r['externos'])); ?></p>
    <?php endif; ?>
    <div class="m1-kpis">
        <?php
        $aprobables = $r['c']['final'][exante::APROBADO] + $r['c']['final'][exante::OBSERVACIONES];
        echo local_chatbot_m2d_kpi(get_string('m2dash_a_start', 'local_chatbot'),
            s(local_chatbot_m2d_num($r['a']['tasa_inicio'], 0, '%')),
            get_string('m2dash_a_start_sub', 'local_chatbot', (object) ['n' => $r['a']['personas_entrega'], 'de' => $r['inscritos']]));
        echo local_chatbot_m2d_kpi(get_string('m2dash_b_first', 'local_chatbot'),
            s(local_chatbot_m2d_num($r['b']['primer_intento'], 0, '%')),
            get_string('m2dash_of_n_fichas', 'local_chatbot', $r['b']['fichas_envio']));
        echo local_chatbot_m2d_kpi(get_string('m2dash_c_ok', 'local_chatbot'),
            s(local_chatbot_m2d_num(tablero_m2::pct($aprobables, $r['c']['validadas']), 0, '%')),
            get_string('m2dash_of_n_fichas', 'local_chatbot', $r['c']['validadas']));
        echo local_chatbot_m2d_kpi(get_string('m2dash_e_mean', 'local_chatbot'),
            $r['e']['total_promedio'] !== null ? s(local_chatbot_m2d_num($r['e']['total_promedio'], 1))
                . '<span class="m2d-de"> / 36</span>' : '—',
            get_string('m2dash_of_n_designs', 'local_chatbot', $r['e']['evaluadas']));
        ?>
    </div>

    <div class="m1-grid">
        <section class="m1-card m1-span-6">
            <h2 class="m1-card-title"><?php echo s(get_string('m2dash_g_a', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('m2dash_g_a_help', 'local_chatbot')); ?></p>
            <div class="m1-bars">
                <?php
                echo local_chatbot_m2d_bar(get_string('m2dash_a_start', 'local_chatbot'), $r['a']['tasa_inicio'], 100,
                    local_chatbot_m2d_num($r['a']['tasa_inicio'], 0, '%'));
                echo local_chatbot_m2d_bar(get_string('m2dash_a_both', 'local_chatbot'), $r['a']['uso_guia_ejemplo'], 100,
                    local_chatbot_m2d_num($r['a']['uso_guia_ejemplo'], 0, '%'));
                echo local_chatbot_m2d_bar(get_string('m2dash_used_guia', 'local_chatbot'), $r['a']['uso_guia'], 100,
                    local_chatbot_m2d_num($r['a']['uso_guia'], 0, '%'));
                echo local_chatbot_m2d_bar(get_string('m2dash_used_ejemplo', 'local_chatbot'), $r['a']['uso_ejemplo'], 100,
                    local_chatbot_m2d_num($r['a']['uso_ejemplo'], 0, '%'));
                ?>
            </div>
            <p class="m2d-dato"><?php echo s(get_string('m2dash_a_days', 'local_chatbot',
                local_chatbot_m2d_num($r['a']['dias_primera'], 1))); ?></p>
        </section>

        <section class="m1-card m1-span-6">
            <h2 class="m1-card-title"><?php echo s(get_string('m2dash_g_b', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('m2dash_g_b_help', 'local_chatbot')); ?></p>
            <?php if (!$r['b']['fichas_envio']): ?>
                <?php echo local_chatbot_m2d_empty('m2dash_empty_b'); ?>
            <?php else: ?>
                <div class="m1-bars">
                    <?php
                    echo local_chatbot_m2d_bar(get_string('m2dash_b_first', 'local_chatbot'), $r['b']['primer_intento'], 100,
                        local_chatbot_m2d_num($r['b']['primer_intento'], 0, '%'));
                    echo local_chatbot_m2d_bar(get_string('m2dash_b_plan', 'local_chatbot'), $r['b']['plan'], 100,
                        local_chatbot_m2d_num($r['b']['plan'], 0, '%'));
                    echo local_chatbot_m2d_bar(get_string('m2dash_b_profile', 'local_chatbot'), $r['b']['perfil'], 100,
                        local_chatbot_m2d_num($r['b']['perfil'], 0, '%'));
                    ?>
                </div>
                <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_b_fields', 'local_chatbot', $r['b']['envios_incompletos'])); ?></h3>
                <?php if (!$r['b']['faltantes']): ?>
                    <p class="m1-muted"><?php echo s(get_string('m2dash_missing_none', 'local_chatbot')); ?></p>
                <?php else: ?>
                    <div class="m1-bars">
                        <?php foreach (array_slice($r['b']['faltantes'], 0, 8, true) as $campo => $f) {
                            echo local_chatbot_m2d_bar($campo, $f['pct'], 100, $f['pct'] . '% (' . $f['n'] . ')');
                        } ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="m1-card m1-span-12">
            <h2 class="m1-card-title"><?php echo s(get_string('m2dash_g_c', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('m2dash_g_c_help', 'local_chatbot')); ?></p>
            <?php if (!$r['c']['validadas']): ?>
                <?php echo local_chatbot_m2d_empty('m2dash_empty_c'); ?>
            <?php else: ?>
                <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_c_decision', 'local_chatbot')); ?></h3>
                <div class="m1-bars">
                    <?php foreach (['primera', 'final'] as $momento):
                        $segs = [];
                        foreach ($condiciones as $cond => [$color, $tinta]) {
                            $segs[] = [get_string('m2dash_cond_' . $cond, 'local_chatbot'), $r['c'][$momento][$cond],
                                M2D_ESTADO[$color], $tinta];
                        } ?>
                        <div class="m1-bar-row"><span class="m1-bar-label"><?php
                            echo s(get_string('m2dash_c_' . $momento, 'local_chatbot')); ?></span>
                            <span class="m1-bar-lane"><?php echo local_chatbot_m2d_stack($segs, $r['c']['validadas']); ?></span></div>
                    <?php endforeach; ?>
                </div>
                <?php
                $leyenda = [];
                foreach ($condiciones as $cond => [$color]) {
                    $leyenda[] = [get_string('m2dash_cond_' . $cond, 'local_chatbot'), M2D_ESTADO[$color],
                        $r['c']['primera'][$cond] . ' → ' . $r['c']['final'][$cond]];
                }
                echo local_chatbot_m2d_legend($leyenda);
                ?>
                <div class="m2d-cifras">
                    <div><span class="m1-m2-num"><?php echo s(local_chatbot_m2d_num($r['c']['ciclos_promedio'], 1)); ?></span><?php
                        echo s(get_string('m2dash_c_cycles', 'local_chatbot')); ?></div>
                    <div><span class="m1-m2-num"><?php echo s(local_chatbot_m2d_num($r['c']['correccion_mediana'], 1)); ?></span><?php
                        echo s(get_string('m2dash_c_correction', 'local_chatbot')); ?></div>
                    <div><span class="m1-m2-num"><?php echo s(local_chatbot_m2d_num($r['c']['criticos'], 0, '%')); ?></span><?php
                        echo s(get_string('m2dash_c_critical', 'local_chatbot')); ?></div>
                    <div><span class="m1-m2-num"><?php echo s(local_chatbot_m2d_num($r['c']['forma'], 0, '%')); ?></span><?php
                        echo s(get_string('m2dash_c_form', 'local_chatbot', $r['c']['parciales_criticos'])); ?></div>
                    <div><span class="m1-m2-num"><?php echo s(local_chatbot_m2d_num($r['c']['no_resueltas_promedio'], 1)); ?></span><?php
                        echo s(get_string('m2dash_c_unresolved', 'local_chatbot', $r['c']['iniciaron'])); ?></div>
                </div>

                <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_c_items', 'local_chatbot', $r['c']['validaciones'])); ?></h3>
                <?php
                $items = $r['c']['items'];
                uasort($items, static fn($a, $b) => $b['obs'] <=> $a['obs']);
                ?>
                <div class="m1-bars">
                    <?php foreach (array_slice($items, 0, 8, true) as $id => $it):
                        $segs = [[get_string('exante_status_cumple', 'local_chatbot'), (int) $it['cumple'], M2D_ESTADO['bueno'], 'light'],
                            [get_string('exante_status_parcial', 'local_chatbot'), (int) $it['parcial'], M2D_ESTADO['advertencia'], 'dark'],
                            [get_string('exante_status_no_cumple', 'local_chatbot'), (int) $it['no_cumple'], M2D_ESTADO['critico'], 'light']]; ?>
                        <div class="m1-bar-row m1-bar-row-stacked"><span class="m1-bar-label"><?php echo s($textositems[$id] ?? $id);
                            if (in_array($id, exante::CRITICOS, true)): ?> <span class="m1-m2-critico"><?php
                                echo s(get_string('m2_critico', 'local_chatbot')); ?></span><?php endif; ?></span>
                            <span class="m1-bar-lane"><?php echo local_chatbot_m2d_stack($segs, 100); ?>
                                <span class="m1-bar-value"><?php echo (int) $it['parcial'] + (int) $it['no_cumple']; ?>%</span></span></div>
                    <?php endforeach; ?>
                </div>
                <?php echo local_chatbot_m2d_legend([
                    [get_string('exante_status_cumple', 'local_chatbot'), M2D_ESTADO['bueno'], null],
                    [get_string('exante_status_parcial', 'local_chatbot'), M2D_ESTADO['advertencia'], null],
                    [get_string('exante_status_no_cumple', 'local_chatbot'), M2D_ESTADO['critico'], null]]); ?>
                <details class="m1-details">
                    <summary><?php echo s(get_string('m2dash_c_items_all', 'local_chatbot')); ?></summary>
                    <table class="m1-table">
                        <thead><tr><th scope="col"><?php echo s(get_string('m2_items_col', 'local_chatbot')); ?></th>
                            <th scope="col">✔ %</th><th scope="col">⚠ %</th><th scope="col">❌ %</th></tr></thead>
                        <tbody>
                            <?php foreach ($r['c']['items'] as $id => $it): ?>
                                <tr><th scope="row"><?php echo s($textositems[$id] ?? $id); ?></th>
                                    <td><?php echo s(local_chatbot_m2d_num($it['cumple'])); ?></td>
                                    <td><?php echo s(local_chatbot_m2d_num($it['parcial'])); ?></td>
                                    <td><?php echo s(local_chatbot_m2d_num($it['no_cumple'])); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>

                <?php if ($r['c']['secciones']): $checklist = exante::checklist(); ?>
                    <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_c_sections', 'local_chatbot')); ?></h3>
                    <div class="m1-bars">
                        <?php foreach ($r['c']['secciones'] as $sec => $f) {
                            echo local_chatbot_m2d_bar($sec . '. ' . ($checklist[$sec]['titulo'] ?? ''), $f['pct'], 100,
                                $f['pct'] . '% (' . $f['n'] . ')');
                        } ?>
                    </div>
                <?php endif; ?>

                <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_c_otec', 'local_chatbot')); ?></h3>
                <table class="m1-table">
                    <thead><tr><th scope="col"><?php echo s(get_string('m2dash_otec', 'local_chatbot')); ?></th>
                        <th scope="col"><?php echo s(get_string('m2_fichas', 'local_chatbot')); ?></th>
                        <?php foreach (array_keys($condiciones) as $cond): ?>
                            <th scope="col"><?php echo s(get_string('m2dash_cond_' . $cond, 'local_chatbot')); ?></th>
                        <?php endforeach; ?></tr></thead>
                    <tbody>
                        <?php foreach ($r['c']['otec'] as $o): ?>
                            <tr><th scope="row"><?php echo s($o['nombre']); ?></th><td><?php echo (int) $o['fichas']; ?></td>
                                <?php foreach (array_keys($condiciones) as $cond): ?>
                                    <td><?php echo (int) ($o[$cond] ?? 0); ?></td>
                                <?php endforeach; ?></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="m1-card m1-span-12">
            <h2 class="m1-card-title"><?php echo s(get_string('m2dash_g_d', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('m2dash_g_d_help', 'local_chatbot', $r['d']['aprobadas'])); ?></p>
            <?php if (!$r['d']['aprobadas']): ?>
                <?php echo local_chatbot_m2d_empty('m2dash_empty_d'); ?>
            <?php else: ?>
                <div class="m1-bars">
                    <?php foreach ($r['d']['embudo'] as $n => $f) {
                        echo local_chatbot_m2d_bar(local_chatbot_m2d_etapa($n), $f['pct'], 100, $f['pct'] . '% (' . $f['n'] . ')');
                    } ?>
                </div>
                <details class="m1-details" open>
                    <summary><?php echo s(get_string('m2dash_d_table', 'local_chatbot')); ?></summary>
                    <table class="m1-table">
                        <thead><tr><th scope="col"><?php echo s(get_string('m2dash_col_stage', 'local_chatbot')); ?></th>
                            <th scope="col"><?php echo s(get_string('m2dash_d_reach', 'local_chatbot')); ?></th>
                            <th scope="col"><?php echo s(get_string('m2dash_d_rounds', 'local_chatbot')); ?></th>
                            <th scope="col"><?php echo s(get_string('m2dash_d_days', 'local_chatbot')); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ($r['d']['embudo'] as $n => $f): ?>
                                <tr><th scope="row"><?php echo s(local_chatbot_m2d_etapa($n)); ?></th>
                                    <td><?php echo (int) $f['n']; ?> (<?php echo s(local_chatbot_m2d_num($f['pct'], 0, '%')); ?>)</td>
                                    <td><?php echo $n <= 6 ? s(local_chatbot_m2d_num($r['d']['iteraciones'][$n], 1)) : '—'; ?></td>
                                    <td><?php echo s(local_chatbot_m2d_num($r['d']['duracion_etapa'][$n], 1)); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
                <p class="m2d-dato"><?php echo s(get_string('m2dash_d_total', 'local_chatbot',
                    local_chatbot_m2d_num($r['d']['duracion_total'], 1))); ?></p>
            <?php endif; ?>
        </section>

        <section class="m1-card m1-span-6">
            <h2 class="m1-card-title"><?php echo s(get_string('m2dash_g_e', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('m2dash_g_e_help', 'local_chatbot')); ?></p>
            <?php if (!$r['e']['evaluadas']): ?>
                <?php echo local_chatbot_m2d_empty('m2dash_empty_e'); ?>
            <?php else:
                $segs = [];
                $leyenda = [];
                foreach (M2D_TRAMOS as $b => $color) {
                    $tinta = in_array($b, ['alto', 'menores'], true) ? 'light' : 'dark';
                    $segs[] = [$bandatexto[$b], $r['e']['bandas'][$b]['n'], $color, $tinta];
                    $leyenda[] = [$bandatexto[$b], $color, $r['e']['bandas'][$b]['n'] . ' ('
                        . local_chatbot_m2d_num($r['e']['bandas'][$b]['pct'], 0, '%') . ')'];
                }
                echo local_chatbot_m2d_stack($segs, $r['e']['evaluadas']);
                echo local_chatbot_m2d_legend($leyenda);
                ?>
                <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_e_criteria', 'local_chatbot')); ?></h3>
                <div class="m1-bars m2d-bars">
                    <?php foreach ($rubrica as $rid => $c) {
                        $p = $r['e']['criterios'][$rid] ?? null;
                        echo local_chatbot_m2d_bar(substr($rid, 1) . '. ' . $c['nombre'], $p, 4, local_chatbot_m2d_num($p, 1));
                    } ?>
                </div>
                <table class="m1-table">
                    <tbody>
                        <tr><th scope="row"><?php echo s(get_string('m2dash_e_limited', 'local_chatbot')); ?></th>
                            <td><?php echo s(local_chatbot_m2d_num($r['e']['con_limite'], 0, '%')); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m2dash_e_lowceiling', 'local_chatbot')); ?></th>
                            <td><?php echo s(local_chatbot_m2d_num($r['e']['techo_bajo'], 0, '%')); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m2dash_e_ceiling', 'local_chatbot')); ?></th>
                            <td><?php echo s(local_chatbot_m2d_num($r['e']['techo_promedio'], 1)); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m2dash_e_gap', 'local_chatbot')); ?></th>
                            <td><?php echo s(local_chatbot_m2d_num($r['e']['brecha_promedio'], 1)); ?></td></tr>
                        <tr><th scope="row"><?php echo s(get_string('m2dash_e_level1', 'local_chatbot')); ?></th>
                            <td><?php echo s(local_chatbot_m2d_num($r['e']['nivel1'], 0, '%')); ?>
                                · <?php echo s(get_string('m2dash_e_c2', 'local_chatbot', $r['e']['c2nivel1'])); ?></td></tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="m1-card m1-span-6">
            <h2 class="m1-card-title"><?php echo s(get_string('m2dash_g_f', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('m2dash_g_f_help', 'local_chatbot')); ?></p>
            <?php if (!$r['e']['evaluadas']): ?>
                <?php echo local_chatbot_m2d_empty('m2dash_empty_e'); ?>
            <?php else: ?>
                <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_f_decision', 'local_chatbot')); ?></h3>
                <div class="m1-bars">
                    <?php foreach ([exante::APROBADO, exante::OBSERVACIONES] as $cond) {
                        $f = $r['f'][$cond === exante::APROBADO ? 'aprobado' : 'observaciones'];
                        echo local_chatbot_m2d_bar(get_string('m2dash_cond_' . $cond, 'local_chatbot'), $f['promedio'], 36,
                            local_chatbot_m2d_num($f['promedio'], 1) . ' (n=' . $f['n'] . ')');
                    } ?>
                </div>
                <p class="m2d-dato"><?php echo s(get_string('m2dash_f_diff', 'local_chatbot',
                    local_chatbot_m2d_num($r['f']['diferencia'], 1))); ?></p>
                <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_f_unresolved', 'local_chatbot')); ?></h3>
                <div class="m1-bars">
                    <?php foreach ($r['f']['tramos'] as $tramo => $f) {
                        echo local_chatbot_m2d_bar(get_string('m2dash_f_bucket_' . str_replace(['-', '+'], ['a', 'mas'], $tramo),
                            'local_chatbot'), $f['promedio'], 36, local_chatbot_m2d_num($f['promedio'], 1) . ' (n=' . $f['n'] . ')');
                    } ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="m1-card m1-span-12">
            <h2 class="m1-card-title"><?php echo s(get_string('m2dash_g_h', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('m2dash_g_h_help', 'local_chatbot', $r['h']['n'])); ?></p>
            <?php if (!$r['h']['n']): ?>
                <?php echo local_chatbot_m2d_empty('m2dash_empty_h'); ?>
            <?php else: ?>
                <div class="m2d-cifras">
                    <div><span class="m1-m2-num"><?php echo s(local_chatbot_m2d_num($r['h']['utilidad'], 1)); ?></span><?php
                        echo s(get_string('m2dash_h_utility', 'local_chatbot')); ?></div>
                    <div><span class="m1-m2-num"><?php echo s(local_chatbot_m2d_num($r['h']['claridad'], 1)); ?></span><?php
                        echo s(get_string('m2dash_h_clarity', 'local_chatbot')); ?></div>
                </div>
                <div class="m1-bars">
                    <?php foreach (tablero_m2::encuesta() as $codigo => $pregunta) {
                        echo local_chatbot_m2d_bar($pregunta['texto'], $r['h']['preguntas'][$codigo], 5,
                            local_chatbot_m2d_num($r['h']['preguntas'][$codigo], 1) . ' / 5');
                    } ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

<?php
// =====================================================================================================================
// Vista calidad del agente (administracion del asistente)
// =====================================================================================================================
elseif ($vista === 'agente'):
    $revisar = optional_param('revisar', '', PARAM_ALPHANUMEXT);
    if ($revisar !== '' && preg_match('/^(etapa0|etapa8)-(\d+)$/', $revisar, $m)):
        [, $tipo, $ref] = $m;
        $ref = (int) $ref;
        $previa = $DB->get_record('local_chatbot_m2_revision', ['tipo' => $tipo, 'refid' => $ref]);
        $previoexperto = $previa ? json_decode((string) $previa->experto, true) : null;
        $previasinc = $previa ? (array) json_decode((string) $previa->incidencias, true) : [];
        ?>
        <section class="m1-card m2d-review">
            <h2 class="m1-card-title"><?php echo s(get_string('m2dash_review_' . $tipo, 'local_chatbot', $ref)); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('m2dash_review_help', 'local_chatbot')); ?></p>
            <?php if ($tipo === 'etapa0'):
                $val = $DB->get_record('local_chatbot_exante_val', ['id' => $ref, 'courseid' => $courseid, 'estado' => exante::LISTO],
                    '*', MUST_EXIST);
                $snapshot = exante::clean((array) json_decode((string) $val->snapshot, true));
                $snapshot['facilitador'] = '';
                $informe = (array) json_decode((string) $val->informe, true);
                ?>
                <div class="m1-grid">
                    <div class="m1-span-6">
                        <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_review_ficha', 'local_chatbot')); ?></h3>
                        <div class="m2d-doc"><?php echo nl2br(s(\local_chatbot\pseudonymizer::scrub(exante::to_text($snapshot)))); ?></div>
                    </div>
                    <div class="m1-span-6">
                        <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_review_agent', 'local_chatbot')); ?>
                            <?php echo local_chatbot_m2d_cond($val->condicion); ?></h3>
                        <table class="m1-table">
                            <tbody>
                                <?php foreach ((array) ($informe['items'] ?? []) as $id => $item): ?>
                                    <tr><th scope="row"><?php echo s($textositems[$id] ?? $id); ?></th>
                                        <td><?php echo s(get_string('exante_status_' . ($item['estado'] ?? exante::NO_CUMPLE), 'local_chatbot'));
                                            echo !empty($item['forma']) ? ' · ' . s(get_string('m2dash_form', 'local_chatbot')) : ''; ?>
                                            <div class="m1-muted"><?php echo s((string) ($item['observacion'] ?? '')); ?></div></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else:
                $fila = $DB->get_record('local_chatbot_diseno', ['id' => $ref, 'courseid' => $courseid,
                    'etapa' => diseno::EVALUACION], '*', MUST_EXIST);
                $informe = (array) json_decode((string) $fila->informe, true);
                $consolidado = $DB->get_field('local_chatbot_diseno', 'contenido', ['fichaid' => $fila->fichaid,
                    'etapa' => diseno::CONSOLIDACION]);
                // Una revision de puntajes que ya no son los de la evaluacion vigente no se precarga: se revisa de nuevo.
                if ($previa && !tablero_m2::same_scores((array) json_decode((string) $previa->agente, true),
                        tablero_m2::agent_scores($informe))) {
                    $previoexperto = null;
                    echo $OUTPUT->notification(get_string('m2dash_review_stale', 'local_chatbot'), 'info');
                }
                ?>
                <div class="m1-grid">
                    <div class="m1-span-6">
                        <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_review_design', 'local_chatbot')); ?></h3>
                        <div class="m2d-doc"><?php echo format_text((string) $consolidado, FORMAT_HTML, ['context' => $context]); ?></div>
                    </div>
                    <div class="m1-span-6">
                        <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_review_agent', 'local_chatbot')); ?></h3>
                        <?php echo diseno::evaluation_html($informe); ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo $pageurl->out(); ?>" class="m2d-review-form">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="guardarrevision">
                <input type="hidden" name="tipo" value="<?php echo s($tipo); ?>">
                <input type="hidden" name="ref" value="<?php echo (int) $ref; ?>">
                <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_review_expert', 'local_chatbot')); ?></h3>
                <?php if ($tipo === 'etapa0'): ?>
                    <label for="m2d-decision"><?php echo s(get_string('m2dash_review_decision', 'local_chatbot')); ?></label>
                    <select name="decision" id="m2d-decision" class="custom-select" required>
                        <option value=""></option>
                        <?php foreach (array_keys($condiciones) as $cond): ?>
                            <option value="<?php echo $cond; ?>" <?php echo $previoexperto === $cond ? 'selected' : ''; ?>><?php
                                echo s(get_string('m2dash_cond_' . $cond, 'local_chatbot')); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <div class="m2d-scores">
                        <?php foreach ($rubrica as $rid => $c): ?>
                            <label><?php echo s(substr($rid, 1) . '. ' . $c['nombre']); ?>
                                <select name="p_<?php echo $rid; ?>" class="custom-select" required>
                                    <option value=""></option>
                                    <?php for ($v = 4; $v >= 1; $v--): ?>
                                        <option value="<?php echo $v; ?>" <?php echo (int) ($previoexperto[$rid] ?? 0) === $v ? 'selected' : ''; ?>><?php
                                            echo $v; ?></option>
                                    <?php endfor; ?>
                                </select></label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <fieldset class="m2d-incid">
                    <legend><?php echo s(get_string('m2dash_review_incidents', 'local_chatbot')); ?></legend>
                    <?php foreach (tablero_m2::incidencias() as $codigo => $texto): ?>
                        <label><input type="checkbox" name="incidencias[]" value="<?php echo $codigo; ?>"
                            <?php echo in_array($codigo, $previasinc, true) ? 'checked' : ''; ?>> <?php echo s($texto); ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <label for="m2d-comentario"><?php echo s(get_string('m2dash_review_comment', 'local_chatbot')); ?></label>
                <textarea name="comentario" id="m2d-comentario" rows="3" class="form-control"><?php
                    echo s($previa->comentario ?? ''); ?></textarea>
                <p class="m2d-actions">
                    <button type="submit" class="btn btn-primary"><?php echo s(get_string('m2dash_review_save', 'local_chatbot')); ?></button>
                    <a class="btn btn-secondary" href="<?php echo $pageurl->out(); ?>"><?php echo s(get_string('cancel')); ?></a>
                </p>
            </form>
        </section>
    <?php else:
        $g = tablero_m2::agent(tablero_m2::load_reviews($courseid));
        $muestra = tablero_m2::load_sample($courseid);
        ?>
        <div class="m1-kpis">
            <?php
            echo local_chatbot_m2d_kpi(get_string('m2dash_g_conc0', 'local_chatbot'),
                s(local_chatbot_m2d_num($g['concordancia0'], 0, '%')),
                get_string('m2dash_g_reviewed', 'local_chatbot', $g['etapa0']));
            echo local_chatbot_m2d_kpi(get_string('m2dash_g_conc8', 'local_chatbot'),
                s(local_chatbot_m2d_num($g['concordancia8'], 0, '%')),
                get_string('m2dash_g_criteria', 'local_chatbot', (object) ['c' => $g['criterios'], 'd' => $g['etapa8']]));
            echo local_chatbot_m2d_kpi(get_string('m2dash_g_total', 'local_chatbot'), s((string) $g['revisadas']),
                get_string('m2dash_g_total_sub', 'local_chatbot', (object) ['v' => count($muestra['etapa0']),
                    'e' => count($muestra['etapa8'])]));
            $totalinc = array_sum(array_column($g['incidencias'], 'n'));
            echo local_chatbot_m2d_kpi(get_string('m2dash_g_inc', 'local_chatbot'),
                s($g['revisadas'] ? local_chatbot_m2d_num($totalinc / $g['revisadas'], 2) : '—'),
                get_string('m2dash_g_inc_sub', 'local_chatbot', $totalinc));
            ?>
        </div>
        <div class="m1-grid">
            <section class="m1-card m1-span-6">
                <h2 class="m1-card-title"><?php echo s(get_string('m2dash_g_inc_title', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m2dash_g_inc_help', 'local_chatbot')); ?></p>
                <?php if (!$g['revisadas']): ?>
                    <?php echo local_chatbot_m2d_empty('m2dash_empty_g'); ?>
                <?php else:
                    $maxinc = max(1, max(array_column($g['incidencias'], 'n'))); ?>
                    <div class="m1-bars">
                        <?php foreach (tablero_m2::incidencias() as $codigo => $texto) {
                            $i = $g['incidencias'][$codigo];
                            echo local_chatbot_m2d_bar($texto, $i['n'], $maxinc, $i['n'] . ' (' . local_chatbot_m2d_num($i['tasa'], 2)
                                . ')');
                        } ?>
                    </div>
                <?php endif; ?>
            </section>
            <section class="m1-card m1-span-6">
                <h2 class="m1-card-title"><?php echo s(get_string('m2dash_g_detail', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m2dash_g_detail_help', 'local_chatbot')); ?></p>
                <?php if (!$g['revisadas']): ?>
                    <?php echo local_chatbot_m2d_empty('m2dash_empty_g'); ?>
                <?php else: ?>
                    <?php if ($g['confusion']): ?>
                        <table class="m1-table">
                            <thead><tr><th scope="col"><?php echo s(get_string('m2dash_g_agent', 'local_chatbot')); ?></th>
                                <th scope="col"><?php echo s(get_string('m2dash_g_expert', 'local_chatbot')); ?></th>
                                <th scope="col">n</th></tr></thead>
                            <tbody>
                                <?php foreach ($g['confusion'] as $par => $n): [$ag, $ex] = explode('>', $par); ?>
                                    <tr><td><?php echo local_chatbot_m2d_cond($ag); ?></td><td><?php echo local_chatbot_m2d_cond($ex); ?></td>
                                        <td><?php echo (int) $n; ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php if ($g['sesgo']): ?>
                        <table class="m1-table">
                            <thead><tr><th scope="col"><?php echo s(get_string('m2dash_g_criterion', 'local_chatbot')); ?></th>
                                <th scope="col"><?php echo s(get_string('m2dash_g_bias', 'local_chatbot')); ?></th></tr></thead>
                            <tbody>
                                <?php foreach ($rubrica as $rid => $c): if (!isset($g['sesgo'][$rid])) { continue; } ?>
                                    <tr><th scope="row"><?php echo s(substr($rid, 1) . '. ' . $c['nombre']); ?></th>
                                        <td><?php echo s(($g['sesgo'][$rid] > 0 ? '+' : '') . local_chatbot_m2d_num($g['sesgo'][$rid], 1)); ?></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
            <section class="m1-card m1-span-12">
                <h2 class="m1-card-title"><?php echo s(get_string('m2dash_g_sample', 'local_chatbot')); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('m2dash_g_sample_help', 'local_chatbot')); ?></p>
                <?php foreach (['etapa0', 'etapa8'] as $tipo): ?>
                    <h3 class="m1-m2-sub"><?php echo s(get_string('m2dash_g_sample_' . $tipo, 'local_chatbot')); ?></h3>
                    <?php if (!$muestra[$tipo]): ?>
                        <p class="m1-muted"><?php echo s(get_string('m2dash_g_sample_empty', 'local_chatbot')); ?></p>
                    <?php else: ?>
                        <table class="m1-table">
                            <thead><tr><th scope="col"><?php echo s(get_string('m2dash_g_ref', 'local_chatbot')); ?></th>
                                <th scope="col"><?php echo s(get_string('m2dash_g_date', 'local_chatbot')); ?></th>
                                <th scope="col"><?php echo s(get_string('m2dash_g_agent', 'local_chatbot')); ?></th>
                                <th scope="col"><?php echo s(get_string('m2dash_g_state', 'local_chatbot')); ?></th>
                                <th scope="col"></th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($muestra[$tipo], 0, 40) as $it): ?>
                                    <tr><th scope="row"><?php echo s(get_string('m2dash_design_n', 'local_chatbot', $it['fichaid'])
                                        . ' · #' . $it['ref']); ?></th>
                                        <td><?php echo s(userdate($it['time'], get_string('strftimedatetimeshort', 'langconfig'))); ?></td>
                                        <td><?php echo $tipo === 'etapa0' ? local_chatbot_m2d_cond($it['agente'])
                                            : s($it['agente'] . ' / 36'); ?></td>
                                        <td><?php echo $it['revision'] ? '<span class="m2d-chip m2d-bueno">'
                                            . s(get_string('m2dash_g_done', 'local_chatbot')) . '</span>'
                                            : '<span class="m1-muted">' . s(get_string(!empty($it['anterior'])
                                                ? 'm2dash_g_stale' : 'm2dash_g_pending', 'local_chatbot')) . '</span>'; ?></td>
                                        <td><a href="<?php echo (new moodle_url($pageurl, ['revisar' => $tipo . '-' . $it['ref']]))->out(); ?>"><?php
                                            echo s(get_string($it['revision'] ? 'm2dash_g_edit' : 'm2dash_g_review', 'local_chatbot')); ?></a></td></tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endforeach; ?>
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
<?php
echo $OUTPUT->footer();
