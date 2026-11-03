<?php
/**
 * Perfiles en el Modulo 2 (pedido del cliente del 03-11-2026): como se comporta cada perfil del Modulo 1 (las respuestas
 * del perfilamiento) en el uso del Modulo 2.
 *
 * Solo coordinacion (local/chatbot:viewanalytics) y solo agregados: un grupo con menos de perfiles_m2::MINIMO personas
 * con el dato no se muestra. Con «demo=1» usa una cohorte ficticia, rotulada como tal, para mostrar el informe antes de
 * tener datos. El calculo vive en classes/perfiles_m2.php. No es calificacion.
 */

require_once(__DIR__ . '/../../config.php');

use local_chatbot\perfiles_m2;
use local_chatbot\tablero_m2;

require_login();
$sistema = context_system::instance();
require_capability('local/chatbot:viewanalytics', $sistema);

$cursos = perfiles_m2::m2_courses();
$courseid = optional_param('courseid', 0, PARAM_INT);
if (!isset($cursos[$courseid])) {
    $courseid = (int) (array_key_first($cursos) ?? 0);
}
$dim = optional_param('dim', perfiles_m2::DIMENSIONES[0], PARAM_ALPHA);
if (!in_array($dim, perfiles_m2::DIMENSIONES, true)) {
    $dim = perfiles_m2::DIMENSIONES[0];
}
$periodo = optional_param('periodo', 'todo', PARAM_ALPHA);
if (!isset(tablero_m2::PERIODOS[$periodo])) {
    $periodo = 'todo';
}
$demo = optional_param('demo', 0, PARAM_BOOL);
$exportar = optional_param('exportar', 0, PARAM_BOOL);

$params = ['courseid' => $courseid, 'dim' => $dim, 'periodo' => $periodo, 'demo' => (int) $demo];
$pageurl = new moodle_url('/local/chatbot/perfiles_m2.php', $params);
$PAGE->set_url($pageurl);
$PAGE->set_context($sistema);
$PAGE->set_pagelayout('report');
$PAGE->add_body_class('local-chatbot-dash-page');
$PAGE->set_title(get_string('pm2_title', 'local_chatbot'));
$PAGE->set_heading(get_string('pm2_title', 'local_chatbot'));
$PAGE->navbar->add(get_string('pm2_title', 'local_chatbot'));

if (!\local_chatbot\ux::on('perfilesm2')) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('pm2_disabled', 'local_chatbot'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$personas = $demo ? perfiles_m2::demo() : ($courseid ? perfiles_m2::load($courseid,
    tablero_m2::period_start($periodo, time())) : []);
$cruce = perfiles_m2::cross($personas, $dim);
$rangos = perfiles_m2::ranges($cruce);
$destacados = perfiles_m2::highlights($personas);
$totales = perfiles_m2::totals($personas);

/**
 * Nombre de una respuesta del perfil.
 *
 * @param string $dim
 * @param string $respuesta
 * @return string
 */
function local_chatbot_pm2_answer(string $dim, string $respuesta): string {
    if (in_array($respuesta, [perfiles_m2::SIN_PERFIL, perfiles_m2::SIN_RESPUESTA], true)) {
        return get_string('pm2_' . $respuesta, 'local_chatbot');
    }
    $prefijo = ['rol' => 'rol_', 'programa' => 'prog_', 'modalidad' => 'mod_', 'experiencia' => 'exp_',
        'familiaridad' => 'fam_', 'tiempo' => 'tiempo_', 'fortalecer' => 'fort_'][$dim];
    return get_string($prefijo . $respuesta, 'local_chatbot');
}

/**
 * Valor de una metrica en texto: «85 %», «3,5 días», «2,4».
 *
 * @param string $metrica
 * @param float|int|null $valor
 * @return string
 */
function local_chatbot_pm2_value(string $metrica, $valor): string {
    if ($valor === null) {
        return '—';
    }
    $tipo = perfiles_m2::METRICAS[$metrica]['tipo'];
    if ($tipo === 'pct') {
        return (int) $valor . ' %';
    }
    $texto = format_float((float) $valor, 1, true, true);
    return in_array($metrica, ['dias_inicio', 'dias_correccion', 'duracion'], true)
        ? get_string('pm2_days', 'local_chatbot', $texto) : $texto;
}

// --- Exportar el cruce a CSV (se abre en Excel): una fila por respuesta, una columna por comportamiento ---
if ($exportar) {
    require_sesskey();
    $nombre = 'Perfiles_Modulo2_' . $dim . ($demo ? '_DEMO' : '') . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF");
    $cabecera = [get_string('pm2_dim_' . $dim, 'local_chatbot'), get_string('pm2_people', 'local_chatbot')];
    foreach (array_keys(perfiles_m2::METRICAS) as $m) {
        $cabecera[] = get_string('pm2_m_' . $m, 'local_chatbot');
    }
    fputcsv($salida, $cabecera, ';');
    foreach ($cruce as $respuesta => $fila) {
        $linea = [local_chatbot_pm2_answer($dim, $respuesta), $fila['n']];
        foreach ($fila['celdas'] as $m => $c) {
            $linea[] = $c['oculto'] ? get_string('pm2_hidden_short', 'local_chatbot') : local_chatbot_pm2_value($m, $c['valor']);
        }
        fputcsv($salida, $linea, ';');
    }
    fclose($salida);
    exit;
}

/**
 * Color de fondo de una celda segun su posicion en la columna (verde el mejor, ambar el peor).
 *
 * @param float $t 1 el mejor, 0 el peor.
 * @return string
 */
function local_chatbot_pm2_color(float $t): string {
    // Ambar -> crema -> verde, suaves para que el texto oscuro se lea en los tres.
    $paradas = [[0.0, [253, 226, 204]], [0.5, [250, 246, 232]], [1.0, [207, 238, 220]]];
    for ($i = 1; $i < count($paradas); $i++) {
        [$t1, $c1] = $paradas[$i - 1];
        [$t2, $c2] = $paradas[$i];
        if ($t <= $t2) {
            $f = ($t - $t1) / ($t2 - $t1);
            return sprintf('rgb(%d,%d,%d)', $c1[0] + ($c2[0] - $c1[0]) * $f, $c1[1] + ($c2[1] - $c1[1]) * $f,
                $c1[2] + ($c2[2] - $c1[2]) * $f);
        }
    }
    return 'rgb(207,238,220)';
}

$grupos = [];
foreach (perfiles_m2::METRICAS as $m => $def) {
    $grupos[$def['grupo']][] = $m;
}

echo $OUTPUT->header();
?>
<div class="m1-dash pm2">
    <header class="m1-dash-head">
        <div class="m1-dash-titleblock">
            <h1 class="m1-dash-title"><?php echo s(get_string('pm2_title', 'local_chatbot')); ?></h1>
            <p class="m1-dash-subtitle"><?php echo s(get_string('pm2_intro', 'local_chatbot')); ?></p>
            <div class="m1-dash-meta">
                <span class="m1-badge"><?php echo s(get_string('pm2_privacy', 'local_chatbot', perfiles_m2::MINIMO)); ?></span>
                <span class="m1-dash-updated"><?php echo s(get_string('dash_updated', 'local_chatbot',
                    userdate(time(), get_string('strftimedatetimeshort', 'langconfig')))); ?></span>
            </div>
        </div>
        <form method="GET" class="pm2-controls">
            <input type="hidden" name="demo" value="<?php echo (int) $demo; ?>">
            <?php if (!$demo && count($cursos) > 1): ?>
                <label class="pm2-control"><span><?php echo s(get_string('pm2_course', 'local_chatbot')); ?></span>
                    <select name="courseid" onchange="this.form.submit()">
                        <?php foreach ($cursos as $id => $nombre): ?>
                            <option value="<?php echo (int) $id; ?>" <?php echo $id === $courseid ? 'selected' : ''; ?>><?php
                                echo s($nombre); ?></option>
                        <?php endforeach; ?>
                    </select></label>
            <?php else: ?>
                <input type="hidden" name="courseid" value="<?php echo (int) $courseid; ?>">
            <?php endif; ?>
            <input type="hidden" name="dim" value="<?php echo s($dim); ?>">
            <?php if (!$demo): ?>
                <label class="pm2-control"><span><?php echo s(get_string('m2dash_period', 'local_chatbot')); ?></span>
                    <select name="periodo" onchange="this.form.submit()">
                        <?php foreach (array_merge(['todo'], array_keys(tablero_m2::PERIODOS)) as $p): ?>
                            <option value="<?php echo $p; ?>" <?php echo $p === $periodo ? 'selected' : ''; ?>><?php
                                echo s(get_string('m2dash_period_' . $p, 'local_chatbot')); ?></option>
                        <?php endforeach; ?>
                    </select></label>
            <?php endif; ?>
            <noscript><button type="submit" class="btn btn-primary btn-sm"><?php
                echo s(get_string('dash_apply', 'local_chatbot')); ?></button></noscript>
        </form>
    </header>

    <?php if ($demo): ?>
        <div class="pm2-demo" role="note">
            <strong><?php echo s(get_string('pm2_demo_title', 'local_chatbot')); ?></strong>
            <span><?php echo s(get_string('pm2_demo_banner', 'local_chatbot', count($personas))); ?></span>
            <a href="<?php echo (new moodle_url($pageurl, ['demo' => 0]))->out(); ?>"><?php
                echo s(get_string('pm2_demo_off', 'local_chatbot')); ?></a>
        </div>
    <?php endif; ?>

    <div class="pm2-tools">
        <?php if (!$demo): ?>
            <a class="m1-export" href="<?php echo (new moodle_url($pageurl, ['demo' => 1]))->out(); ?>"><?php
                echo s(get_string('pm2_demo_on', 'local_chatbot')); ?></a>
        <?php endif; ?>
        <a class="m1-export" href="<?php echo (new moodle_url($pageurl, ['exportar' => 1, 'sesskey' => sesskey()]))->out(); ?>"><?php
            echo s(get_string('pm2_export', 'local_chatbot')); ?></a>
        <?php if (\local_chatbot\ux::on('pdfm2')): ?>
            <button type="button" class="m1-export m1-export-pdf m2d-pdf" id="m2d-pdf"
                    data-name="<?php echo s('Perfiles_Modulo2_' . $dim . ($demo ? '_DEMO' : '') . '_' . date('Y-m-d')); ?>"
                    data-wait="<?php echo s(get_string('m2dash_pdf_wait', 'local_chatbot')); ?>"
                    data-html2canvas="<?php echo (new moodle_url('/local/chatbot/js/vendor/html2canvas.min.js'))->out(); ?>"
                    data-jspdf="<?php echo (new moodle_url('/local/chatbot/js/vendor/jspdf.umd.min.js'))->out(); ?>"><?php
                echo s(get_string('m2dash_pdf', 'local_chatbot')); ?></button>
        <?php endif; ?>
    </div>

    <?php if (!$demo && !$courseid): ?>
        <div class="m1-card"><p class="m1-muted mb-0"><?php echo s(get_string('pm2_nocourse', 'local_chatbot')); ?></p></div>
    <?php elseif (!$personas): ?>
        <div class="m1-card"><p class="m1-muted mb-0"><?php echo s(get_string('pm2_nodata', 'local_chatbot')); ?></p></div>
    <?php else: ?>

    <section class="m1-kpis pm2-kpis" aria-label="<?php echo s(get_string('pm2_kpis', 'local_chatbot')); ?>">
        <?php foreach (['inscritos', 'con_perfil', 'entregaron', 'terminaron'] as $k): ?>
            <div class="pm2-kpi">
                <span class="pm2-kpi-value"><?php echo (int) $totales[$k]; ?></span>
                <span class="pm2-kpi-label"><?php echo s(get_string('pm2_kpi_' . $k, 'local_chatbot')); ?></span>
                <?php if ($k !== 'inscritos' && $totales['inscritos'] > 0): ?>
                    <span class="pm2-kpi-pct"><?php echo (int) tablero_m2::pct($totales[$k], $totales['inscritos']); ?> %</span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="m1-insights pm2-highlights" aria-labelledby="pm2-hl-title">
        <h2 class="m1-insights-title" id="pm2-hl-title"><?php echo s(get_string('pm2_highlights', 'local_chatbot')); ?></h2>
        <p class="m1-card-help"><?php echo s(get_string('pm2_highlights_help', 'local_chatbot',
            perfiles_m2::MINIMO_CONCLUSION)); ?></p>
        <?php if (!$destacados): ?>
            <p class="m1-muted mb-0"><?php echo s(get_string('pm2_highlights_none', 'local_chatbot', perfiles_m2::MINIMO_CONCLUSION)); ?></p>
        <?php else: ?>
            <ol class="pm2-hl-list">
                <?php foreach ($destacados as $h): ?>
                    <li class="pm2-hl">
                        <a class="pm2-hl-q" href="<?php echo (new moodle_url($pageurl, ['dim' => $h['dim']], 'pm2-tabla'))->out(); ?>"><?php
                            echo s(get_string('pm2_dim_' . $h['dim'], 'local_chatbot')); ?></a>
                        <span class="pm2-hl-metric"><?php echo s(get_string('pm2_m_' . $h['metrica'], 'local_chatbot')); ?></span>
                        <span class="pm2-hl-pair">
                            <span class="pm2-hl-side is-good"><span class="pm2-hl-who"><?php
                                echo s(local_chatbot_pm2_answer($h['dim'], $h['mejor']['respuesta'])); ?></span>
                                <strong><?php echo s(local_chatbot_pm2_value($h['metrica'], $h['mejor']['valor'])); ?></strong>
                                <span class="pm2-hl-n"><?php echo s(get_string('pm2_n', 'local_chatbot', $h['mejor']['n'])); ?></span></span>
                            <span class="pm2-hl-vs"><?php echo s(get_string('pm2_vs', 'local_chatbot')); ?></span>
                            <span class="pm2-hl-side is-bad"><span class="pm2-hl-who"><?php
                                echo s(local_chatbot_pm2_answer($h['dim'], $h['peor']['respuesta'])); ?></span>
                                <strong><?php echo s(local_chatbot_pm2_value($h['metrica'], $h['peor']['valor'])); ?></strong>
                                <span class="pm2-hl-n"><?php echo s(get_string('pm2_n', 'local_chatbot', $h['peor']['n'])); ?></span></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <section class="m1-card pm2-card" id="pm2-tabla" aria-labelledby="pm2-table-title">
        <h2 class="m1-card-title" id="pm2-table-title"><?php echo s(get_string('pm2_table_title', 'local_chatbot',
            get_string('pm2_dim_' . $dim, 'local_chatbot'))); ?></h2>
        <nav class="pm2-dims" aria-label="<?php echo s(get_string('pm2_dim', 'local_chatbot')); ?>">
            <?php foreach (perfiles_m2::DIMENSIONES as $d): ?>
                <a class="pm2-dim<?php echo $d === $dim ? ' is-active' : ''; ?>"
                   href="<?php echo (new moodle_url($pageurl, ['dim' => $d], 'pm2-tabla'))->out(); ?>"
                   <?php echo $d === $dim ? 'aria-current="true"' : ''; ?>><?php
                    echo s(get_string('pm2_dim_' . $d, 'local_chatbot')); ?></a>
            <?php endforeach; ?>
        </nav>
        <p class="m1-card-help"><?php echo s(get_string('pm2_legend', 'local_chatbot', perfiles_m2::MINIMO)); ?></p>
        <div class="pm2-scroll" tabindex="0" role="region" aria-labelledby="pm2-table-title">
            <table class="pm2-table">
                <thead>
                    <tr>
                        <th rowspan="2" scope="col" class="pm2-answer-col"><?php echo s(get_string('pm2_answer', 'local_chatbot')); ?></th>
                        <th rowspan="2" scope="col" class="pm2-n-col"><?php echo s(get_string('pm2_people', 'local_chatbot')); ?></th>
                        <?php foreach ($grupos as $g => $metricas): ?>
                            <th colspan="<?php echo count($metricas); ?>" scope="colgroup" class="pm2-group pm2-group-<?php echo $g; ?>"><?php
                                echo s(get_string('pm2_grp_' . $g, 'local_chatbot')); ?></th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <?php foreach ($grupos as $g => $metricas): ?>
                            <?php foreach ($metricas as $m): ?>
                                <th scope="col" class="pm2-metric" title="<?php echo s(get_string('pm2_h_' . $m, 'local_chatbot')); ?>"><?php
                                    echo s(get_string('pm2_m_' . $m, 'local_chatbot')); ?><span class="pm2-dir"><?php
                                    echo perfiles_m2::METRICAS[$m]['mejor'] === 'alto' ? '↑' : '↓'; ?></span></th>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cruce as $respuesta => $fila): ?>
                        <tr class="<?php echo in_array($respuesta, [perfiles_m2::SIN_PERFIL, perfiles_m2::SIN_RESPUESTA], true) ? 'is-aside' : ''; ?>">
                            <th scope="row" class="pm2-answer"><?php echo s(local_chatbot_pm2_answer($dim, $respuesta)); ?></th>
                            <td class="pm2-n"><?php echo (int) $fila['n']; ?></td>
                            <?php foreach ($grupos as $metricas): ?>
                                <?php foreach ($metricas as $m): $c = $fila['celdas'][$m]; ?>
                                    <?php if ($c['oculto']): ?>
                                        <td class="pm2-cell is-hidden" title="<?php echo s(get_string('pm2_hidden', 'local_chatbot',
                                            (object) ['n' => $c['n'], 'min' => perfiles_m2::MINIMO])); ?>">·</td>
                                    <?php else:
                                        $estilo = isset($rangos[$m]) ? ' style="background:' . local_chatbot_pm2_color(
                                            perfiles_m2::score((float) $c['valor'], $rangos[$m], $m)) . '"' : ''; ?>
                                        <td class="pm2-cell"<?php echo $estilo; ?> title="<?php echo s(get_string('pm2_cell_n', 'local_chatbot', $c['n'])); ?>"><?php
                                            echo s(local_chatbot_pm2_value($m, $c['valor'])); ?></td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="m1-card pm2-card pm2-glossary" aria-labelledby="pm2-gl-title">
        <h2 class="m1-card-title" id="pm2-gl-title"><?php echo s(get_string('pm2_glossary', 'local_chatbot')); ?></h2>
        <dl class="pm2-gl">
            <?php foreach (array_keys(perfiles_m2::METRICAS) as $m): ?>
                <div><dt><?php echo s(get_string('pm2_m_' . $m, 'local_chatbot')); ?></dt>
                    <dd><?php echo s(get_string('pm2_h_' . $m, 'local_chatbot')); ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <p class="m1-card-help mb-0"><?php echo s(get_string('pm2_note_source', 'local_chatbot')); ?></p>
    </section>

    <?php endif; ?>
</div>
<?php if (\local_chatbot\ux::on('pdfm2')): ?>
<script src="<?php echo (new moodle_url('/local/chatbot/js/pdf_tablero.js', ['v' => (string) get_config('local_chatbot', 'version')]))->out(); ?>"></script>
<?php endif; ?>
<?php
echo $OUTPUT->footer();
