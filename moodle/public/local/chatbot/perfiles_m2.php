<?php
/**
 * Perfiles en el Modulo 2 (pedido del cliente del 03-11-2026): como se comporta cada perfil del Modulo 1 (las respuestas
 * del perfilamiento) en el uso del Modulo 2.
 *
 * Escrito para quien sabe del area pero no de datos: conclusiones en frases con una sugerencia, cada grupo comparado con
 * el promedio del curso (verde mejor, ambar peor), seis comportamientos clave a la vista y un resumen en palabras de cada
 * perfil. Solo coordinacion (local/chatbot:viewanalytics) y solo agregados: un grupo con menos de perfiles_m2::MINIMO
 * personas con el dato no se muestra. Con «demo=1» usa una cohorte ficticia, rotulada como tal. El calculo vive en
 * classes/perfiles_m2.php. No es calificacion.
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
$todas = optional_param('todas', 0, PARAM_BOOL);
$elegido = optional_param('perfil', '', PARAM_ALPHANUMEXT);
$exportar = optional_param('exportar', 0, PARAM_BOOL);

$params = ['courseid' => $courseid, 'dim' => $dim, 'periodo' => $periodo, 'demo' => (int) $demo, 'todas' => (int) $todas];
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
$total = perfiles_m2::overall($personas);
$destacados = perfiles_m2::highlights($personas);
$totales = perfiles_m2::totals($personas);
$rubrica = perfiles_m2::rubric_cross($personas, $dim);
$rubricacurso = perfiles_m2::rubric_overall($personas);
$rdestacados = perfiles_m2::rubric_highlights($personas);
$recorridocurso = perfiles_m2::path($personas);
$criterios = \local_chatbot\diseno::rubrica();
$columnas = $todas ? array_keys(perfiles_m2::METRICAS) : perfiles_m2::CLAVE;
if (!isset($cruce[$elegido])) {
    $elegido = '';
}

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
    if ($dim === 'sector') {
        return get_string('pm2_sector_' . $respuesta, 'local_chatbot');
    }
    $prefijo = ['rol' => 'rol_', 'programa' => 'prog_', 'modalidad' => 'mod_', 'experiencia' => 'exp_',
        'familiaridad' => 'fam_', 'tiempo' => 'tiempo_', 'fortalecer' => 'fort_'][$dim];
    return get_string($prefijo . $respuesta, 'local_chatbot');
}

/**
 * Valor para la tabla: «91 %», «8,5 días», «2,7».
 *
 * @param string $metrica
 * @param float|int|null $valor
 * @return string
 */
function local_chatbot_pm2_value(string $metrica, $valor): string {
    if ($valor === null) {
        return '—';
    }
    if (perfiles_m2::METRICAS[$metrica]['tipo'] === 'pct') {
        return (int) $valor . ' %';
    }
    $texto = format_float((float) $valor, 1, true, true);
    return in_array($metrica, ['dias_inicio', 'dias_correccion', 'duracion'], true)
        ? get_string('pm2_days', 'local_chatbot', $texto) : $texto;
}

/**
 * Valor para una frase: «9 de cada 10 (91 %)», «8,5 días», «2,7 intentos», «27,3 de 36 puntos».
 *
 * @param string $metrica
 * @param float|int|null $valor
 * @return string
 */
function local_chatbot_pm2_phrase(string $metrica, $valor): string {
    if ($valor === null) {
        return '—';
    }
    if (perfiles_m2::METRICAS[$metrica]['tipo'] === 'pct') {
        $pct = (int) $valor;
        $diez = perfiles_m2::out_of_ten($pct);
        if ($pct === 100) {
            return get_string('pm2_of10_all', 'local_chatbot');
        }
        if ($diez === 0) {
            return get_string('pm2_of10_none', 'local_chatbot', $pct);
        }
        return get_string('pm2_of10', 'local_chatbot', (object) ['n' => $diez, 'pct' => $pct]);
    }
    $texto = format_float((float) $valor, 1, true, true);
    return get_string('pm2_u_' . $metrica, 'local_chatbot', $texto);
}

/**
 * Marca de cuan solida es una diferencia: «Diferencia sólida» o «A confirmar con más casos».
 *
 * @param bool $solida
 * @return string HTML.
 */
function local_chatbot_pm2_strength(bool $solida): string {
    $clave = $solida ? 'solida' : 'confirmar';
    return '<span class="pm2-strength is-' . $clave . '" title="' . s(get_string('pm2_strength_' . $clave . '_h', 'local_chatbot'))
        . '">' . s(get_string('pm2_strength_' . $clave, 'local_chatbot')) . '</span>';
}

/**
 * Puntaje de un criterio de la rubrica: «3,4 de 4».
 *
 * @param float|null $valor
 * @return string
 */
function local_chatbot_pm2_score($valor): string {
    return $valor === null ? '—' : get_string('pm2_of4', 'local_chatbot', format_float((float) $valor, 1, true, true));
}

// --- Exportar a CSV (se abre en Excel): el curso y cada respuesta, con los 15 comportamientos ---
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
    $filas = [get_string('pm2_total_row', 'local_chatbot') => $total];
    foreach ($cruce as $respuesta => $fila) {
        $filas[local_chatbot_pm2_answer($dim, $respuesta)] = $fila;
    }
    foreach ($filas as $etiqueta => $fila) {
        $linea = [$etiqueta, $fila['n']];
        foreach ($fila['celdas'] as $m => $c) {
            $linea[] = $c['oculto'] ? get_string('pm2_fewdata', 'local_chatbot') : local_chatbot_pm2_value($m, $c['valor']);
        }
        fputcsv($salida, $linea, ';');
    }
    fclose($salida);
    exit;
}

echo $OUTPUT->header();
?>
<div class="m1-dash pm2">
    <header class="m1-dash-head">
        <div class="m1-dash-titleblock">
            <h1 class="m1-dash-title"><?php echo s(get_string('pm2_title', 'local_chatbot')); ?>
                <span class="pm2-modules"><?php echo s(get_string('pm2_modules', 'local_chatbot')); ?></span></h1>
            <p class="m1-dash-subtitle"><?php echo s(get_string('pm2_intro', 'local_chatbot')); ?></p>
            <div class="m1-dash-meta">
                <span class="m1-badge"><?php echo s(get_string('pm2_privacy', 'local_chatbot', perfiles_m2::MINIMO)); ?></span>
                <span class="m1-dash-updated"><?php echo s(get_string('dash_updated', 'local_chatbot',
                    userdate(time(), get_string('strftimedatetimeshort', 'langconfig')))); ?></span>
            </div>
        </div>
        <form method="GET" class="pm2-controls">
            <input type="hidden" name="demo" value="<?php echo (int) $demo; ?>">
            <input type="hidden" name="todas" value="<?php echo (int) $todas; ?>">
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

    <section class="pm2-howto" aria-labelledby="pm2-howto-title">
        <h2 class="pm2-howto-title" id="pm2-howto-title"><?php echo s(get_string('pm2_howto_title', 'local_chatbot')); ?></h2>
        <ol class="pm2-howto-list">
            <li><?php echo s(get_string('pm2_howto_1', 'local_chatbot')); ?></li>
            <li><?php echo s(get_string('pm2_howto_2', 'local_chatbot')); ?></li>
            <li><?php echo s(get_string('pm2_howto_3', 'local_chatbot', perfiles_m2::MINIMO)); ?></li>
        </ol>
    </section>

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
                <?php if ($k !== 'inscritos' && $totales['inscritos'] > 0):
                    $pct = tablero_m2::pct($totales[$k], $totales['inscritos']); ?>
                    <span class="pm2-kpi-pct"><?php echo s(get_string('pm2_kpi_of10', 'local_chatbot',
                        (object) ['n' => perfiles_m2::out_of_ten($pct), 'pct' => $pct])); ?></span>
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
                <?php foreach ($destacados as $h): $m = $h['metrica']; ?>
                    <li class="pm2-hl">
                        <span class="pm2-hl-head"><a class="pm2-hl-q" href="<?php echo (new moodle_url($pageurl, ['dim' => $h['dim'],
                            'perfil' => $h['peor']['respuesta']], 'pm2-perfil'))->out(); ?>"><?php
                            echo s(get_string('pm2_dim_' . $h['dim'], 'local_chatbot')); ?></a>
                            <?php echo local_chatbot_pm2_strength($h['solida']); ?></span>
                        <p class="pm2-hl-title"><?php echo s(get_string('pm2_s_' . $m, 'local_chatbot',
                            local_chatbot_pm2_answer($h['dim'], $h['peor']['respuesta']))); ?></p>
                        <p class="pm2-hl-detail"><?php echo s(get_string('pm2_vs_detail', 'local_chatbot', (object) [
                            'vpeor' => local_chatbot_pm2_phrase($m, $h['peor']['valor']), 'npeor' => $h['peor']['n'],
                            'vmejor' => local_chatbot_pm2_phrase($m, $h['mejor']['valor']), 'nmejor' => $h['mejor']['n'],
                            'mejor' => local_chatbot_pm2_answer($h['dim'], $h['mejor']['respuesta'])])); ?></p>
                        <p class="pm2-hl-do"><span aria-hidden="true">👉 </span><?php
                            echo s(get_string('pm2_do_' . $m, 'local_chatbot')); ?></p>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <?php // Calidad del diseno por perfil: los 9 criterios de la rubrica de la Etapa 8 por respuesta. ?>
    <section class="m1-card pm2-card" id="pm2-rubrica" aria-labelledby="pm2-rub-title">
        <h2 class="m1-card-title" id="pm2-rub-title"><?php echo s(get_string('pm2_rub_title', 'local_chatbot')); ?></h2>
        <p class="m1-card-help"><?php echo s(get_string('pm2_rub_help', 'local_chatbot')); ?></p>
        <?php if ($rdestacados): ?>
            <ul class="pm2-rhl-list">
                <?php foreach ($rdestacados as $h): $nombre = $criterios[$h['criterio']]['nombre']; ?>
                    <li class="pm2-hl pm2-rhl">
                        <span class="pm2-hl-head"><span class="pm2-hl-q"><?php echo s(get_string('pm2_dim_' . $h['dim'], 'local_chatbot')); ?></span>
                            <?php echo local_chatbot_pm2_strength($h['solida']); ?></span>
                        <p class="pm2-hl-title"><?php echo s(get_string('pm2_rub_s', 'local_chatbot', (object) [
                            'mejor' => local_chatbot_pm2_answer($h['dim'], $h['mejor']['respuesta']),
                            'peor' => local_chatbot_pm2_answer($h['dim'], $h['peor']['respuesta']), 'criterio' => $nombre])); ?></p>
                        <p class="pm2-hl-detail"><?php echo s(get_string('pm2_rub_detail', 'local_chatbot', (object) [
                            'vmejor' => local_chatbot_pm2_score($h['mejor']['valor']), 'nmejor' => $h['mejor']['n'],
                            'vpeor' => local_chatbot_pm2_score($h['peor']['valor']), 'npeor' => $h['peor']['n']])); ?></p>
                        <p class="pm2-hl-do"><span aria-hidden="true">👉 </span><?php echo s(get_string('pm2_rdo_' . $h['criterio'],
                            'local_chatbot', local_chatbot_pm2_answer($h['dim'], $h['peor']['respuesta']))); ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="m1-muted"><?php echo s(get_string('pm2_rub_none', 'local_chatbot', perfiles_m2::MINIMO_CONCLUSION)); ?></p>
        <?php endif; ?>
        <h3 class="pm2-subtitle"><?php echo s(get_string('pm2_rub_table', 'local_chatbot', get_string('pm2_dim_' . $dim, 'local_chatbot'))); ?></h3>
        <div class="pm2-scroll" tabindex="0" role="region" aria-labelledby="pm2-rub-title">
            <table class="pm2-table pm2-rtable">
                <thead>
                    <tr>
                        <th scope="col" class="pm2-answer-col"><?php echo s(get_string('pm2_answer', 'local_chatbot')); ?></th>
                        <th scope="col" class="pm2-n-col"><?php echo s(get_string('pm2_evaluated', 'local_chatbot')); ?></th>
                        <?php foreach (perfiles_m2::CRITERIOS as $c): ?>
                            <th scope="col" class="pm2-metric" title="<?php echo s($criterios[$c]['nombre']); ?>"><?php
                                echo s($criterios[$c]['nombre']); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr class="is-total">
                        <th scope="row" class="pm2-answer"><?php echo s(get_string('pm2_total_row', 'local_chatbot')); ?></th>
                        <td class="pm2-n"><?php echo (int) $rubricacurso['n']; ?></td>
                        <?php foreach (perfiles_m2::CRITERIOS as $c): $v = $rubricacurso['celdas'][$c]; ?>
                            <td class="pm2-cell"><?php echo $v['oculto'] ? '<span class="pm2-few">'
                                . s(get_string('pm2_fewdata', 'local_chatbot')) . '</span>'
                                : s(format_float((float) $v['valor'], 1, true, true)); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php foreach ($rubrica as $respuesta => $fila): ?>
                        <tr class="<?php echo in_array($respuesta, [perfiles_m2::SIN_PERFIL, perfiles_m2::SIN_RESPUESTA], true) ? 'is-aside' : ''; ?>">
                            <th scope="row" class="pm2-answer"><?php echo s(local_chatbot_pm2_answer($dim, $respuesta)); ?></th>
                            <td class="pm2-n"><?php echo (int) $fila['n']; ?></td>
                            <?php foreach (perfiles_m2::CRITERIOS as $c): $v = $fila['celdas'][$c]; ?>
                                <?php if ($v['oculto']): ?>
                                    <td class="pm2-cell is-hidden"><span class="pm2-few"><?php echo s(get_string('pm2_fewdata', 'local_chatbot')); ?></span></td>
                                <?php else:
                                    $t = $rubricacurso['celdas'][$c]['valor'];
                                    $sentido = $t === null ? '' : (abs($v['valor'] - $t) < perfiles_m2::TOLERANCIA_RUBRICA ? 'igual'
                                        : ($v['valor'] > $t ? 'mejor' : 'peor')); ?>
                                    <td class="pm2-cell<?php echo $sentido ? ' is-' . $sentido : ''; ?>"><?php
                                        echo s(format_float((float) $v['valor'], 1, true, true));
                                        if ($sentido === 'mejor' || $sentido === 'peor'): ?><span class="pm2-mark" aria-hidden="true"><?php
                                            echo $sentido === 'mejor' ? '▲' : '▼'; ?></span><span class="visually-hidden"> (<?php
                                            echo s(get_string('pm2_leg_' . $sentido, 'local_chatbot')); ?>)</span><?php endif; ?></td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="m1-card-help mb-0 mt-2"><?php echo s(get_string('pm2_rub_scale', 'local_chatbot')); ?></p>
    </section>

    <?php // Recorrido por los modulos: el curso y, si se eligio una respuesta, ese grupo. ?>
    <?php
    $grupo = $elegido !== '' ? perfiles_m2::members($personas, $dim, $elegido) : [];
    $recorridogrupo = count($grupo) >= perfiles_m2::MINIMO ? perfiles_m2::path($grupo) : null;
    $caida = perfiles_m2::biggest_drop($recorridogrupo ?? $recorridocurso);
    $quien = $recorridogrupo ? get_string('pm2_path_group', 'local_chatbot', local_chatbot_pm2_answer($dim, $elegido))
        : get_string('pm2_total_row', 'local_chatbot');
    ?>
    <section class="m1-card pm2-card" id="pm2-recorrido" aria-labelledby="pm2-path-title">
        <h2 class="m1-card-title" id="pm2-path-title"><?php echo s(get_string('pm2_path_title', 'local_chatbot')); ?></h2>
        <p class="m1-card-help"><?php echo s(get_string('pm2_path_help', 'local_chatbot')); ?></p>
        <?php if ($caida): ?>
            <p class="pm2-path-drop"><?php echo s(get_string('pm2_path_drop', 'local_chatbot', (object) ['quien' => $quien,
                'desde' => get_string('pm2_p_' . $caida['desde'], 'local_chatbot'),
                'hasta' => get_string('pm2_p_' . $caida['hasta'], 'local_chatbot'),
                'antes' => local_chatbot_pm2_phrase('entrego', $caida['pct_desde']),
                'despues' => local_chatbot_pm2_phrase('entrego', $caida['pct_hasta'])])); ?></p>
        <?php endif; ?>
        <div class="pm2-path-legend">
            <span><span class="pm2-swatch is-curso" aria-hidden="true"></span><?php echo s(get_string('pm2_total_row', 'local_chatbot')); ?></span>
            <?php if ($recorridogrupo): ?>
                <span><span class="pm2-swatch is-grupo" aria-hidden="true"></span><?php echo s($quien); ?></span>
            <?php else: ?>
                <span class="m1-muted"><?php echo s(get_string('pm2_path_pick', 'local_chatbot')); ?></span>
            <?php endif; ?>
        </div>
        <ol class="pm2-path">
            <?php foreach (perfiles_m2::RECORRIDO as $i => $paso):
                $modulo = substr($paso, 0, 2);
                if ($i === 0 || substr(perfiles_m2::RECORRIDO[$i - 1], 0, 2) !== $modulo): ?>
                    <li class="pm2-path-module"><?php echo s(get_string('pm2_path_' . $modulo, 'local_chatbot')); ?></li>
                <?php endif; ?>
                <li class="pm2-path-step">
                    <span class="pm2-path-name"><?php echo s(get_string('pm2_p_' . $paso, 'local_chatbot')); ?></span>
                    <span class="pm2-path-bars">
                        <span class="pm2-path-bar is-curso"><span style="width:<?php echo (int) $recorridocurso[$paso]['pct']; ?>%"></span>
                            <em><?php echo (int) $recorridocurso[$paso]['pct']; ?> %</em></span>
                        <?php if ($recorridogrupo): ?>
                            <span class="pm2-path-bar is-grupo"><span style="width:<?php echo (int) $recorridogrupo[$paso]['pct']; ?>%"></span>
                                <em><?php echo (int) $recorridogrupo[$paso]['pct']; ?> %</em></span>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
            <li class="pm2-path-module is-soon"><?php echo s(get_string('pm2_path_m3', 'local_chatbot')); ?></li>
        </ol>
    </section>

    <section class="m1-card pm2-card" id="pm2-tabla" aria-labelledby="pm2-table-title">
        <h2 class="m1-card-title" id="pm2-table-title"><?php echo s(get_string('pm2_table_title', 'local_chatbot',
            get_string('pm2_dim_' . $dim, 'local_chatbot'))); ?></h2>
        <p class="m1-card-help"><?php echo s(get_string('pm2_table_help', 'local_chatbot')); ?></p>
        <nav class="pm2-dims" aria-label="<?php echo s(get_string('pm2_dim', 'local_chatbot')); ?>">
            <?php foreach (perfiles_m2::DIMENSIONES as $d): ?>
                <a class="pm2-dim<?php echo $d === $dim ? ' is-active' : ''; ?>"
                   href="<?php echo (new moodle_url($pageurl, ['dim' => $d], 'pm2-tabla'))->out(); ?>"
                   <?php echo $d === $dim ? 'aria-current="true"' : ''; ?>><?php
                    echo s(get_string('pm2_dim_' . $d, 'local_chatbot')); ?></a>
            <?php endforeach; ?>
        </nav>
        <ul class="pm2-legend" aria-label="<?php echo s(get_string('pm2_legend_label', 'local_chatbot')); ?>">
            <li><span class="pm2-swatch is-mejor" aria-hidden="true"></span><?php echo s(get_string('pm2_leg_mejor', 'local_chatbot')); ?></li>
            <li><span class="pm2-swatch is-igual" aria-hidden="true"></span><?php echo s(get_string('pm2_leg_igual', 'local_chatbot')); ?></li>
            <li><span class="pm2-swatch is-peor" aria-hidden="true"></span><?php echo s(get_string('pm2_leg_peor', 'local_chatbot')); ?></li>
            <li><span class="pm2-few"><?php echo s(get_string('pm2_fewdata', 'local_chatbot')); ?></span><?php
                echo s(get_string('pm2_leg_few', 'local_chatbot', perfiles_m2::MINIMO)); ?></li>
        </ul>

        <?php if ($elegido !== ''):
            $fila = $cruce[$elegido];
            $dif = perfiles_m2::differences($fila, $total);
            // Lo mas marcado, no todo: cuatro hacia peor y tres hacia mejor (ya vienen de mas a menos marcado).
            $mejores = array_slice(array_values(array_filter($dif, fn($x) => $x['sentido'] === 'mejor')), 0, 3);
            $peores = array_slice(array_values(array_filter($dif, fn($x) => $x['sentido'] === 'peor')), 0, 4); ?>
            <article class="pm2-profile" id="pm2-perfil" aria-labelledby="pm2-profile-title">
                <a class="pm2-profile-close" href="<?php echo (new moodle_url($pageurl, [], 'pm2-tabla'))->out(); ?>"
                   aria-label="<?php echo s(get_string('pm2_card_close', 'local_chatbot')); ?>">×</a>
                <h3 class="pm2-profile-title" id="pm2-profile-title"><?php echo s(get_string('pm2_card_title', 'local_chatbot',
                    (object) ['quien' => local_chatbot_pm2_answer($dim, $elegido), 'n' => $fila['n'],
                    'pregunta' => get_string('pm2_dim_' . $dim, 'local_chatbot')])); ?></h3>
                <?php if (!$dif): ?>
                    <p class="mb-0"><?php echo s(get_string('pm2_card_same', 'local_chatbot', perfiles_m2::MINIMO)); ?></p>
                <?php else: ?>
                    <?php foreach (['peor' => $peores, 'mejor' => $mejores] as $sentido => $lista): if (!$lista) { continue; } ?>
                        <p class="pm2-profile-sub is-<?php echo $sentido; ?>"><?php echo s(get_string('pm2_card_' . $sentido, 'local_chatbot')); ?></p>
                        <ul class="pm2-profile-list">
                            <?php foreach ($lista as $x): ?>
                                <li><strong><?php echo s(get_string('pm2_m_' . $x['metrica'], 'local_chatbot')); ?>:</strong>
                                    <?php echo s(get_string('pm2_card_line', 'local_chatbot', (object) [
                                        'valor' => local_chatbot_pm2_phrase($x['metrica'], $x['valor']),
                                        'total' => local_chatbot_pm2_phrase($x['metrica'], $x['total'])])); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                    <p class="pm2-profile-rest mb-0"><?php echo s(get_string('pm2_card_rest', 'local_chatbot')); ?></p>
                <?php endif; ?>
            </article>
        <?php else: ?>
            <p class="pm2-pick"><?php echo s(get_string('pm2_pick', 'local_chatbot')); ?></p>
        <?php endif; ?>

        <div class="pm2-scroll<?php echo $todas ? ' is-all' : ''; ?>" tabindex="0" role="region" aria-labelledby="pm2-table-title">
            <table class="pm2-table">
                <thead>
                    <tr>
                        <th scope="col" class="pm2-answer-col"><?php echo s(get_string('pm2_answer', 'local_chatbot')); ?></th>
                        <th scope="col" class="pm2-n-col"><?php echo s(get_string('pm2_people', 'local_chatbot')); ?></th>
                        <?php foreach ($columnas as $m): ?>
                            <th scope="col" class="pm2-metric pm2-group-<?php echo perfiles_m2::METRICAS[$m]['grupo']; ?>"
                                title="<?php echo s(get_string('pm2_h_' . $m, 'local_chatbot')); ?>"><?php
                                echo s(get_string('pm2_m_' . $m, 'local_chatbot')); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr class="is-total">
                        <th scope="row" class="pm2-answer"><?php echo s(get_string('pm2_total_row', 'local_chatbot')); ?></th>
                        <td class="pm2-n"><?php echo (int) $total['n']; ?></td>
                        <?php foreach ($columnas as $m): $c = $total['celdas'][$m]; ?>
                            <td class="pm2-cell"><?php echo $c['oculto'] ? '<span class="pm2-few">'
                                . s(get_string('pm2_fewdata', 'local_chatbot')) . '</span>' : s(local_chatbot_pm2_value($m, $c['valor'])); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php foreach ($cruce as $respuesta => $fila):
                        $aparte = in_array($respuesta, [perfiles_m2::SIN_PERFIL, perfiles_m2::SIN_RESPUESTA], true); ?>
                        <tr class="<?php echo trim(($aparte ? 'is-aside ' : '') . ($respuesta === $elegido ? 'is-picked' : '')); ?>">
                            <th scope="row" class="pm2-answer"><a href="<?php echo (new moodle_url($pageurl, ['perfil' => $respuesta],
                                'pm2-perfil'))->out(); ?>"><?php echo s(local_chatbot_pm2_answer($dim, $respuesta)); ?></a></th>
                            <td class="pm2-n"><?php echo (int) $fila['n']; ?></td>
                            <?php foreach ($columnas as $m): $c = $fila['celdas'][$m]; ?>
                                <?php if ($c['oculto']): ?>
                                    <td class="pm2-cell is-hidden" title="<?php echo s(get_string('pm2_hidden', 'local_chatbot',
                                        (object) ['n' => $c['n'], 'min' => perfiles_m2::MINIMO])); ?>"><span class="pm2-few"><?php
                                        echo s(get_string('pm2_fewdata', 'local_chatbot')); ?></span></td>
                                <?php else:
                                    $sentido = perfiles_m2::compare($c['valor'], $total['celdas'][$m]['valor'], $m); ?>
                                    <td class="pm2-cell<?php echo $sentido ? ' is-' . $sentido : ''; ?>"
                                        title="<?php echo s(get_string('pm2_cell_' . ($sentido ?: 'igual'), 'local_chatbot', (object) [
                                            'n' => $c['n'], 'total' => local_chatbot_pm2_value($m, $total['celdas'][$m]['valor'])])); ?>"><?php
                                        echo s(local_chatbot_pm2_value($m, $c['valor']));
                                        if ($sentido === 'mejor' || $sentido === 'peor'): ?><span class="pm2-mark" aria-hidden="true"><?php
                                            echo $sentido === 'mejor' ? '▲' : '▼'; ?></span><span class="visually-hidden"> (<?php
                                            echo s(get_string('pm2_leg_' . $sentido, 'local_chatbot')); ?>)</span><?php endif; ?></td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="pm2-more"><a href="<?php echo (new moodle_url($pageurl, ['todas' => $todas ? 0 : 1], 'pm2-tabla'))->out(); ?>"><?php
            echo s(get_string($todas ? 'pm2_show_key' : 'pm2_show_all', 'local_chatbot', count(perfiles_m2::METRICAS))); ?></a></p>
    </section>

    <?php if ($dim === 'sector' && ($auditoria = perfiles_m2::sector_audit($personas))): ?>
        <details class="m1-card pm2-card pm2-glossary pm2-audit">
            <summary class="m1-card-title"><?php echo s(get_string('pm2_audit_title', 'local_chatbot')); ?></summary>
            <p class="m1-card-help"><?php echo s(get_string('pm2_audit_help', 'local_chatbot')); ?></p>
            <dl class="pm2-gl">
                <?php foreach ($auditoria as $sector => $textos): ?>
                    <div><dt><?php echo s(get_string('pm2_sector_' . $sector, 'local_chatbot')); ?></dt>
                        <dd><?php echo s(implode(' · ', array_map(static function($t, $n) {
                            return $t . ' (' . $n . ')';
                        }, array_keys($textos), $textos))); ?></dd></div>
                <?php endforeach; ?>
            </dl>
        </details>
    <?php endif; ?>

    <details class="m1-card pm2-card pm2-glossary">
        <summary class="m1-card-title"><?php echo s(get_string('pm2_glossary', 'local_chatbot')); ?></summary>
        <dl class="pm2-gl">
            <?php foreach (array_keys(perfiles_m2::METRICAS) as $m): ?>
                <div><dt><?php echo s(get_string('pm2_m_' . $m, 'local_chatbot')); ?></dt>
                    <dd><?php echo s(get_string('pm2_h_' . $m, 'local_chatbot')); ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <p class="m1-card-help mb-0"><?php echo s(get_string('pm2_note_source', 'local_chatbot')); ?></p>
    </details>

    <?php endif; ?>
</div>
<?php if (\local_chatbot\ux::on('pdfm2')): ?>
<script src="<?php echo (new moodle_url('/local/chatbot/js/pdf_tablero.js', ['v' => (string) get_config('local_chatbot', 'version')]))->out(); ?>"></script>
<?php endif; ?>
<?php
echo $OUTPUT->footer();
