<?php
/**
 * Indicadores iniciales del Modulo 1.
 *
 * Todo lo que se muestra aqui es agregado y anonimizado: distribuciones,
 * conteos y promedios por dimension y por modo. No expone nombres, correos ni
 * el caso individual de ninguna persona, porque lo que el documento destina a
 * gestion institucional son "datos agregados y anonimizados por perfil,
 * dimension y uso".
 *
 * La pagina es un tablero propio y no una pantalla de la administracion del
 * sitio: quien la abre es gestion del modulo, no quien configura Moodle, y las
 * pestanas de administracion solo la hacian parecer un ajuste mas.
 *
 * Cada grafico tiene un gemelo accesible: el valor va escrito junto a la
 * marca, en la leyenda o en una tabla desplegable. El tooltip complementa,
 * nunca es la unica forma de leer un numero.
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/chatbot:viewanalytics', $context);

$courseid = optional_param('courseid', 0, PARAM_INT);

// Con «Asistente solo en estos cursos» (chatonlycourses) escrito, los indicadores muestran solo esos cursos: el asistente
// existe unicamente ahi, asi que no hay «recorrido general» ni cursos de prueba que ver. Un curso ajeno a la lista
// vuelve al primero de ella.
$onlyids = \local_chatbot\closure::parse_course_ids((string) get_config('local_chatbot', 'chatonlycourses'));
if ($onlyids && !in_array($courseid, $onlyids, true)) {
    $courseid = $onlyids[0];
}

$PAGE->set_url(new moodle_url('/local/chatbot/indicadores.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
// 'report' y no 'standard': el layout estandar encajona el contenido en una
// columna angosta pensada para texto, y este panel es una rejilla de datos que
// queda desperdiciando la mitad de la pantalla.
$PAGE->set_pagelayout('report');
$PAGE->set_secondary_navigation(false);
$PAGE->add_body_class('local-chatbot-dash-page');
$PAGE->set_title(get_string('analytics_title', 'local_chatbot'));
$PAGE->set_heading(get_string('analytics_heading', 'local_chatbot'));

if ($onlyids) {
    $courses = $DB->get_records_list('course', 'id', $onlyids, 'fullname ASC', 'id, fullname');
} else {
    $courses = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname
           FROM {course} c
           JOIN {local_chatbot_profile} p ON p.courseid = c.id
       ORDER BY c.fullname ASC");
}

// Avance con la ruta vigente y el criterio de la barra de ruta de cada persona (course_bridge::step_states()).
$funnel = \local_chatbot\analytics::route_funnel($courseid);
$routesix = in_array('ruta', $funnel['keys'], true);
$optionalkeys = array_map(fn($pos) => $funnel['keys'][$pos - 1],
    \local_chatbot\journey::optional_positions($funnel['keys']));
$gaps = \local_chatbot\analytics::gap_distribution($courseid);
$variation = \local_chatbot\analytics::progress_variation($courseid);
$goals = \local_chatbot\analytics::pdp_goal_outcomes($courseid);
$interactions = \local_chatbot\analytics::interactions_by_mode($courseid);
$hotspots = \local_chatbot\analytics::rubric_hotspots($courseid);
$usage = \local_chatbot\analytics::usage_timeline($courseid);
$route = \local_chatbot\analytics::route_steps_timeline($courseid);
$annexg = \local_chatbot\analytics::annex_g_indicators($courseid);
// Registro y salud (M1-v1.6.2): el catalogo de eventos es la capa de abajo de
// los indicadores del Anexo G, y la salud del servicio no estaba a la vista en
// ninguna pantalla.
$catalog = \local_chatbot\analytics::event_catalog($courseid);
$quizpat = \local_chatbot\analytics::quiz_patterns($courseid);
$health = \local_chatbot\analytics::service_health($courseid);

$modelabels = \local_chatbot\assistant::get_prompt_contexts();
$levels = \local_chatbot\diagnostic::levels();

/**
 * Porcentaje entero, sin dividir por cero.
 *
 * @param int $part
 * @param int $whole
 * @return int
 */
function local_chatbot_dash_pct(int $part, int $whole): int {
    return $whole > 0 ? (int) round($part * 100 / $whole) : 0;
}

/**
 * Estado vacio de una tarjeta: dice cuando va a aparecer el dato, que es lo
 * que la persona necesita saber, en vez de solo "no hay datos".
 *
 * @param string $hintkey
 * @return string
 */
function local_chatbot_dash_empty(string $hintkey, string $titlekey = 'dash_empty_title'): string {
    $title = s(get_string($titlekey, 'local_chatbot'));
    $hint = s(get_string($hintkey, 'local_chatbot'));
    return '<div class="m1-empty">'
        . '<svg class="m1-empty-icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"'
        . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true" focusable="false"><path d="M3 3v16a2 2 0 0 0 2 2h16"></path>'
        . '<path d="M7 16h.01"></path><path d="M11 16h.01"></path><path d="M15 16h.01"></path></svg>'
        . '<div><div class="m1-empty-title">' . $title . '</div>'
        . '<div class="m1-empty-hint">' . $hint . '</div></div></div>';
}

/**
 * Barra apilada horizontal. Los segmentos se reparten con flex-grow para que
 * el separador de 2px no desborde la barra, y el numero va dentro solo si el
 * segmento es lo bastante ancho; si no, queda en tooltip, leyenda y tabla.
 *
 * @param array $segments Lista de [label, count, color, ink('light'|'dark')].
 * @param int $whole Total contra el que se calcula cada porcentaje.
 * @param int $widthpct Largo de la barra completa respecto de su carril.
 * @return string
 */
function local_chatbot_dash_stack(array $segments, int $whole, int $widthpct = 100): string {
    $html = '<div class="m1-stack" style="width:' . max(0, min(100, $widthpct)) . '%">';
    foreach ($segments as [$label, $count, $color, $ink]) {
        if ($count <= 0) {
            continue;
        }
        $pct = local_chatbot_dash_pct($count, $whole);
        $tip = s($label . ': ' . $count . ' (' . $pct . '%)');
        // Con menos de un 9% del ancho, dos digitos no caben con aire a los lados.
        $inside = ($pct * $widthpct / 100) >= 9 ? (string) $count : '';
        $html .= '<span class="m1-seg m1-ink-' . $ink . '" style="flex-grow:' . $count . ';background:' . $color . '"'
            . ' tabindex="0" role="img" aria-label="' . $tip . '" data-tip="' . $tip . '">' . $inside . '</span>';
    }
    return $html . '</div>';
}

/**
 * Leyenda: la muestra de color va junto al texto, el texto nunca se pinta con
 * el color de la serie.
 *
 * @param array $items Lista de [label, color, value|null].
 * @return string
 */
/**
 * Semanas en las que alguna serie registro actividad. Funcion pura.
 *
 * Una linea que une un solo punto no es una tendencia: dibujarla sobre doce
 * semanas planas sugiere que se midio algo y dio cero, cuando en realidad no
 * habia nada que medir todavia. Con este conteo el panel decide si el grafico
 * dice algo o conviene mostrar solo la tabla.
 *
 * @param array $series Series con su arreglo 'values'.
 * @return int Cantidad de semanas con al menos un valor distinto de cero.
 */
function local_chatbot_dash_weeks_with_data(array $series): int {
    $semanas = [];
    foreach ($series as $serie) {
        foreach ($serie['values'] as $i => $valor) {
            if ((int) $valor !== 0) {
                $semanas[$i] = true;
            }
        }
    }
    return count($semanas);
}

function local_chatbot_dash_legend(array $items): string {
    $html = '<ul class="m1-legend">';
    foreach ($items as [$label, $color, $value]) {
        $html .= '<li><span class="m1-key" style="background:' . $color . '"></span>' . s($label)
            . ($value !== null ? ' <strong>' . s($value) . '</strong>' : '') . '</li>';
    }
    return $html . '</ul>';
}

/**
 * Techo "redondo" del eje vertical, para que las marcas caigan en numeros
 * limpios (5, 10, 25, 50...) y no en el maximo crudo de los datos.
 *
 * @param int $max
 * @return int
 */
function local_chatbot_dash_nice_max(int $max): int {
    if ($max <= 1) {
        return 1;
    }
    $pow = pow(10, max(0, (int) floor(log10($max))));
    foreach ([1, 2, 2.5, 5, 10] as $mult) {
        if ($max <= $mult * $pow) {
            return (int) ceil($mult * $pow);
        }
    }
    return $max;
}

/**
 * Grafico de lineas en SVG, sin librerias: son doce puntos por serie y una
 * dependencia externa costaria mas que el dibujo.
 *
 * La marca es la linea; el valor se puede leer en la etiqueta del final, en el
 * recorrido con el teclado y en la tabla desplegable. El tooltip solo
 * complementa.
 *
 * @param array $series Lista de ['name' => string, 'color' => string, 'values' => int[]].
 * @param array $weeks Marcas de tiempo del lunes de cada semana.
 * @param string $label Nombre accesible del grafico.
 * @return string
 */
function local_chatbot_dash_linechart(array $series, array $weeks, string $label): string {
    $n = count($weeks);
    if ($n === 0) {
        return '';
    }

    $w = 760;
    $h = 250;
    $padl = 42;
    $padr = 58;
    $padt = 14;
    $padb = 30;
    $plotw = $w - $padl - $padr;
    $ploth = $h - $padt - $padb;

    $max = 0;
    foreach ($series as $serie) {
        $max = max($max, empty($serie['values']) ? 0 : max($serie['values']));
    }
    $ymax = local_chatbot_dash_nice_max((int) $max);

    $x = fn(int $i) => $n > 1 ? $padl + ($i * $plotw / ($n - 1)) : $padl + $plotw / 2;
    $y = fn(float $v) => $padt + $ploth - ($v / $ymax * $ploth);

    $svg = '<svg class="m1-chart-svg" viewBox="0 0 ' . $w . ' ' . $h . '" role="group" aria-label="' . s($label) . '">';

    // Marcas y lineas guia: hairline solida, un paso por sobre la superficie.
    $ticks = array_values(array_unique([0, (int) round($ymax / 2), $ymax]));
    foreach ($ticks as $tick) {
        $ty = $y((float) $tick);
        $svg .= '<line class="m1-chart-grid" x1="' . $padl . '" y1="' . $ty . '" x2="' . ($padl + $plotw) . '" y2="' . $ty . '"></line>'
            . '<text class="m1-chart-tick" x="' . ($padl - 8) . '" y="' . ($ty + 4) . '" text-anchor="end">' . $tick . '</text>';
    }

    // Etiquetas del eje horizontal: una de cada tantas, y siempre la ultima.
    $step = max(1, (int) ceil($n / 6));
    foreach ($weeks as $i => $ts) {
        $islast = ($i === $n - 1);
        if (!$islast && ($i % $step !== 0 || ($n - 1 - $i) < $step)) {
            // La ultima semana siempre se rotula; la anterior se omite si
            // quedaria pegada a ella.
            continue;
        }
        $svg .= '<text class="m1-chart-tick" x="' . $x($i) . '" y="' . ($h - 10) . '" text-anchor="middle">'
            . s(userdate($ts, '%d/%m')) . '</text>';
    }

    // Lineas y punto final de cada serie.
    $endlabels = [];
    foreach ($series as $serie) {
        $points = [];
        foreach ($serie['values'] as $i => $value) {
            $points[] = ($i === 0 ? 'M' : 'L') . round($x($i), 1) . ' ' . round($y((float) $value), 1);
        }
        $svg .= '<path class="m1-chart-line" d="' . implode(' ', $points) . '" stroke="' . $serie['color'] . '"></path>';

        $lastindex = $n - 1;
        $lastvalue = (float) end($serie['values']);
        $svg .= '<circle class="m1-chart-dot" cx="' . round($x($lastindex), 1) . '" cy="' . round($y($lastvalue), 1)
            . '" r="4.5" fill="' . $serie['color'] . '"></circle>';
        $endlabels[] = ['y' => $y($lastvalue), 'text' => (string) (int) $lastvalue];
    }

    // Etiqueta al final de cada linea, salvo cuando dos quedan tan juntas que
    // se pisarian: ahi el numero queda en el tooltip y en la tabla.
    usort($endlabels, fn($a, $b) => $a['y'] <=> $b['y']);
    $placed = [];
    foreach ($endlabels as $index => $labelinfo) {
        $prev = $endlabels[$index - 1] ?? null;
        $next = $endlabels[$index + 1] ?? null;
        // 22 unidades del lienzo: el alto de la etiqueta mas aire, contando
        // que en pantallas chicas el texto se agranda.
        $tooclose = ($prev && abs($labelinfo['y'] - $prev['y']) < 22)
            || ($next && abs($labelinfo['y'] - $next['y']) < 22);
        if (!$tooclose) {
            $placed[] = $labelinfo;
        }
    }
    foreach ($placed as $labelinfo) {
        $svg .= '<text class="m1-chart-endlabel" x="' . ($padl + $plotw + 8) . '" y="' . ($labelinfo['y'] + 4) . '">'
            . s($labelinfo['text']) . '</text>';
    }

    // Capa de lectura: una franja por semana, con todas las series a la vez.
    $svg .= '<line class="m1-chart-cross" x1="0" y1="' . $padt . '" x2="0" y2="' . ($padt + $ploth) . '" hidden></line>';
    $bandw = $n > 1 ? $plotw / ($n - 1) : $plotw;
    foreach ($weeks as $i => $ts) {
        $tip = get_string('timeline_week', 'local_chatbot', userdate($ts, '%d/%m/%Y'));
        foreach ($series as $serie) {
            $tip .= "\n" . $serie['name'] . ': ' . (int) $serie['values'][$i];
        }
        $left = max($padl, $x($i) - $bandw / 2);
        $right = min($padl + $plotw, $x($i) + $bandw / 2);
        $svg .= '<rect class="m1-chart-hit" x="' . round($left, 1) . '" y="' . $padt . '" width="' . round($right - $left, 1)
            . '" height="' . $ploth . '" tabindex="0" role="img" aria-label="' . s(str_replace("\n", '. ', $tip)) . '"'
            . ' data-tip="' . s($tip) . '" data-x="' . round($x($i), 1) . '"></rect>';
    }

    return '<div class="m1-chart">' . $svg . '</svg></div>';
}

/**
 * Tabla equivalente de una linea de tiempo: el gemelo accesible del grafico,
 * y la forma de leer un valor sin apuntar a nada.
 *
 * @param array $series
 * @param array $weeks
 * @param string $tablelabel
 * @return string
 */
function local_chatbot_dash_timeline_table(array $series, array $weeks, string $tablelabel): string {
    $html = '<details class="m1-details"><summary>' . $tablelabel . '</summary>'
        . '<div class="table-responsive"><table class="m1-table"><thead><tr>'
        . '<th scope="col">' . s(get_string('timeline_weekcol', 'local_chatbot')) . '</th>';
    foreach ($series as $serie) {
        $html .= '<th scope="col">' . s($serie['name']) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($weeks as $i => $ts) {
        $html .= '<tr><th scope="row">' . s(userdate($ts, '%d/%m/%Y')) . '</th>';
        foreach ($series as $serie) {
            $html .= '<td>' . (int) $serie['values'][$i] . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div></details>';
}

// Paleta. Una sola serie usa el azul base; lo ordenado (niveles, estados que
// se mantienen, entrada/salida) usa pasos de la misma rampa azul, validados
// como ordinales. El naranja solo marca lo descartado. Los niveles de brecha
// van en azul y no en rojo/verde a proposito: la gobernanza del modulo pide
// evitar clasificaciones estigmatizantes, y "prioridad alta" en rojo lo seria.
$c = [
    'series' => '#2a78d6',
    'discard' => '#eb6834',
    'neutral' => '#c3c2b7',
    'b250' => '#86b6ef',
    'b350' => '#5598e7',
    'b500' => '#256abf',
    'b600' => '#184f95',
    'b650' => '#104281',
];
// Los tres modos son identidades distintas, no una escala: van en las tres
// primeras ranuras categoricas. Las etapas del recorrido si son una escala, y
// van en la rampa azul, de la primera a la ultima.
$modecolors = [
    \local_chatbot\assistant::MODE_TUTOR => $c['series'],
    \local_chatbot\assistant::MODE_COACH => $c['discard'],
    \local_chatbot\assistant::MODE_EVALUADOR => '#1baf7a',
];
// Una rampa azul de la primera a la ultima etapa, en el orden de la ruta vigente.
$stageramp = [$c['b250'], $c['b350'], $c['series'], '#1c5cab', '#14457f', '#0d2f57'];
$stagecolors = [];
foreach ($funnel['keys'] as $i => $clave) {
    $stagecolors[$clave] = $stageramp[min($i, count($stageramp) - 1)];
}
$stagename = function (string $clave) use ($routesix, $optionalkeys): string {
    $nombre = get_string(\local_chatbot\course_bridge::step_name_key($clave, $routesix), 'local_chatbot');
    return in_array($clave, $optionalkeys, true) ? $nombre . ' ' . get_string('funnel_optional', 'local_chatbot') : $nombre;
};
$levelcolors = [
    \local_chatbot\diagnostic::LEVEL_PRIORIDAD_ALTA => [$c['b250'], 'dark'],
    \local_chatbot\diagnostic::LEVEL_EN_DESARROLLO => [$c['b350'], 'dark'],
    \local_chatbot\diagnostic::LEVEL_FUNCIONAL => [$c['b500'], 'light'],
    \local_chatbot\diagnostic::LEVEL_CONSOLIDADO => [$c['b650'], 'light'],
];

// Indicadores clave.
$started = (int) $funnel['iniciaron'];
$finished = (int) ($funnel['etapas']['cierre'] ?? 0);

$goalskept = $goals['aceptado'] + $goals['modificado'] + $goals['completado'];
$goalspending = $goals['propuesto'] + $goals['postergado'];
$goalsdecided = $goalskept + $goals['rechazado'];
$goalstotal = $goalsdecided + $goalspending;

$interactionstotal = 0;
$interactionsmax = 0;
foreach ($interactions as $row) {
    $interactionstotal += $row['total'];
    $interactionsmax = max($interactionsmax, $row['total']);
}

$hotspotsmax = 0;
foreach ($hotspots as $row) {
    $hotspotsmax = max($hotspotsmax, $row['observaciones']);
}

$scoremax = \local_chatbot\diagnostic::SCORE_MAX;

$kpis = [
    [
        'label' => get_string('kpi_started', 'local_chatbot'),
        'value' => (string) $started,
        'sub' => get_string('kpi_started_sub', 'local_chatbot'),
        'icon' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle>'
            . '<path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
    ],
    [
        'label' => get_string('kpi_finished', 'local_chatbot'),
        'value' => (string) $finished,
        'sub' => $started > 0
            ? get_string('kpi_finished_sub', 'local_chatbot', local_chatbot_dash_pct($finished, $started))
            : get_string('kpi_finished_none', 'local_chatbot'),
        'icon' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path>'
            . '<line x1="4" y1="22" x2="4" y2="15"></line>',
    ],
    [
        // Primero cuantas metas se decidieron: un «100 % se mantienen» sobre 2 de 9 escondia que 7 seguian sin decidir.
        'label' => get_string('kpi_goals', 'local_chatbot'),
        'value' => $goalstotal > 0 ? local_chatbot_dash_pct($goalsdecided, $goalstotal) . '%' : '—',
        'sub' => $goalstotal > 0
            ? get_string('kpi_goals_sub', 'local_chatbot', (object) [
                'decididas' => $goalsdecided, 'total' => $goalstotal, 'pendientes' => $goalspending,
                'mantienen' => $goalsdecided > 0 ? local_chatbot_dash_pct($goalskept, $goalsdecided) . '%' : '—'])
            : get_string('kpi_goals_none', 'local_chatbot'),
        'icon' => '<circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle>'
            . '<circle cx="12" cy="12" r="2"></circle>',
    ],
    [
        'label' => get_string('kpi_interactions', 'local_chatbot'),
        'value' => (string) $interactionstotal,
        'sub' => get_string('kpi_interactions_sub', 'local_chatbot'),
        'icon' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>',
    ],
];

$tablelabel = s(get_string('dash_table', 'local_chatbot'));

echo $OUTPUT->header();
?>

<div class="m1-dash">

    <header class="m1-dash-head">
        <div class="m1-dash-titleblock">
            <h1 class="m1-dash-title"><?php echo s(get_string('analytics_heading', 'local_chatbot')); ?></h1>
            <p class="m1-dash-subtitle"><?php echo s(get_string('dash_subtitle', 'local_chatbot')); ?></p>
            <div class="m1-dash-meta">
                <span class="m1-badge">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true" focusable="false"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    <?php echo s(get_string('dash_privacy', 'local_chatbot')); ?>
                </span>
                <span class="m1-dash-updated"><?php echo s(get_string('dash_updated', 'local_chatbot',
                    userdate(time(), get_string('strftimedatetimeshort', 'langconfig')))); ?></span>
            </div>
        </div>

        <?php if ($onlyids && count($courses) === 1): $unico = reset($courses); ?>
        <?php /* Un solo curso: no hay nada que elegir, asi que se muestra su nombre completo y grande, sin selector. */ ?>
        <div class="m1-dash-course">
            <span class="m1-dash-course-label"><?php echo s(get_string('analytics_course', 'local_chatbot')); ?></span>
            <h2 class="m1-dash-course-name"><?php echo format_string($unico->fullname); ?></h2>
        </div>
        <?php else: ?>
        <form method="GET" class="m1-dash-filter">
            <label for="m1-courseid"><?php echo s(get_string('analytics_course', 'local_chatbot')); ?></label>
            <div class="m1-select-wrap">
                <select name="courseid" id="m1-courseid" onchange="this.form.submit()">
                    <?php if (!$onlyids): ?>
                        <option value="0"><?php echo s(get_string('analytics_sitewide', 'local_chatbot')); ?></option>
                    <?php endif; ?>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?php echo (int) $course->id; ?>"
                            <?php echo $course->id == $courseid ? 'selected' : ''; ?>>
                            <?php echo format_string($course->fullname); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button type="submit" class="btn btn-primary btn-sm"><?php
                echo s(get_string('dash_apply', 'local_chatbot')); ?></button></noscript>
        </form>
        <?php endif; ?>
    </header>

    <section class="m1-kpis" aria-label="<?php echo s(get_string('analytics_heading', 'local_chatbot')); ?>">
        <?php foreach ($kpis as $kpi): ?>
            <div class="m1-kpi">
                <div class="m1-kpi-top">
                    <span class="m1-kpi-label"><?php echo s($kpi['label']); ?></span>
                    <span class="m1-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             aria-hidden="true" focusable="false"><?php echo $kpi['icon']; ?></svg>
                    </span>
                </div>
                <div class="m1-kpi-value"><?php echo s($kpi['value']); ?></div>
                <div class="m1-kpi-sub"><?php echo s($kpi['sub']); ?></div>
            </div>
        <?php endforeach; ?>
    </section>

    <div class="m1-grid">

        <?php
        // Linea de tiempo del uso: tres series con identidad propia (los modos).
        $usageseries = [];
        $usagetotal = 0;
        foreach ($usage['series'] as $mode => $values) {
            $usageseries[] = [
                'name' => get_string('mode_' . $mode . '_short', 'local_chatbot'),
                'color' => $modecolors[$mode],
                'values' => $values,
            ];
            $usagetotal += array_sum($values);
        }

        // Linea de tiempo del recorrido: las etapas de la ruta vigente, en orden, rampa azul.
        $routeseries = [];
        $routetotal = 0;
        foreach ($route['series'] as $stage => $values) {
            $routeseries[] = [
                'name' => $stagename($stage),
                'color' => $stagecolors[$stage],
                'values' => $values,
            ];
            $routetotal += array_sum($values);
        }
        ?>

        <section class="m1-card m1-span-6">
            <h2 class="m1-card-title"><?php echo s(get_string('timeline_usage', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('timeline_usage_help', 'local_chatbot')); ?></p>
            <?php if ($usagetotal === 0): ?>
                <?php echo local_chatbot_dash_empty('timeline_usage_empty'); ?>
            <?php elseif (local_chatbot_dash_weeks_with_data($usageseries) < 2): ?>
                <?php // Hay actividad, pero en una sola semana: se muestran los
                      // numeros sin dibujar una tendencia que no existe. ?>
                <?php echo local_chatbot_dash_empty('timeline_usage_thin', 'dash_thin_title'); ?>
                <?php echo local_chatbot_dash_timeline_table($usageseries, $usage['weeks'], $tablelabel); ?>
            <?php else: ?>
                <?php echo local_chatbot_dash_legend(array_map(
                    fn($serie) => [$serie['name'], $serie['color'], null], $usageseries)); ?>
                <?php echo local_chatbot_dash_linechart($usageseries, $usage['weeks'],
                    get_string('timeline_usage', 'local_chatbot')); ?>
                <?php echo local_chatbot_dash_timeline_table($usageseries, $usage['weeks'], $tablelabel); ?>
            <?php endif; ?>
        </section>

        <section class="m1-card m1-span-6">
            <h2 class="m1-card-title"><?php echo s(get_string('timeline_route', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('timeline_route_help', 'local_chatbot')); ?></p>
            <?php if ($routetotal === 0): ?>
                <?php echo local_chatbot_dash_empty('timeline_route_empty'); ?>
            <?php elseif (local_chatbot_dash_weeks_with_data($routeseries) < 2): ?>
                <?php echo local_chatbot_dash_empty('timeline_route_thin', 'dash_thin_title'); ?>
                <?php echo local_chatbot_dash_timeline_table($routeseries, $route['weeks'], $tablelabel); ?>
            <?php else: ?>
                <?php echo local_chatbot_dash_legend(array_map(
                    fn($serie) => [$serie['name'], $serie['color'], null], $routeseries)); ?>
                <?php echo local_chatbot_dash_linechart($routeseries, $route['weeks'],
                    get_string('timeline_route', 'local_chatbot')); ?>
                <?php echo local_chatbot_dash_timeline_table($routeseries, $route['weeks'], $tablelabel); ?>
            <?php endif; ?>
        </section>

        <?php // Embudo: una serie, cinco etapas ordenadas. Cada barra lleva su numero. ?>
        <section class="m1-card m1-span-7">
            <h2 class="m1-card-title"><?php echo s(get_string('analytics_funnel', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('funnel_help', 'local_chatbot')); ?></p>
            <?php if ($started === 0): ?>
                <?php echo local_chatbot_dash_empty('funnel_empty'); ?>
            <?php else: ?>
                <ol class="m1-funnel">
                    <?php foreach ($funnel['etapas'] as $key => $count):
                        $pct = local_chatbot_dash_pct((int) $count, $started); ?>
                        <li class="m1-funnel-row">
                            <span class="m1-funnel-label"><?php echo s($stagename($key)); ?></span>
                            <span class="m1-track">
                                <span class="m1-fill" style="width:<?php echo min(100, $pct); ?>%;background:<?php echo $c['series']; ?>"></span>
                            </span>
                            <span class="m1-funnel-value">
                                <strong><?php echo (int) $count; ?></strong>
                                <span class="m1-muted"><?php echo $pct; ?>%</span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <?php // Metas del asistente: que se mantiene, que queda pendiente, que se descarta. ?>
        <section class="m1-card m1-span-5">
            <h2 class="m1-card-title"><?php echo s(get_string('analytics_goals', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('analytics_goals_help', 'local_chatbot')); ?></p>
            <?php if ($goalstotal === 0): ?>
                <?php echo local_chatbot_dash_empty('goals_empty'); ?>
            <?php else:
                $goalsegments = [
                    [get_string('goal_completado', 'local_chatbot'), $goals['completado'], $c['b600'], 'light'],
                    [get_string('goal_aceptado', 'local_chatbot'), $goals['aceptado'], $c['series'], 'light'],
                    [get_string('goal_modificado', 'local_chatbot'), $goals['modificado'], $c['b250'], 'dark'],
                    [get_string('goals_pending', 'local_chatbot'), $goalspending, $c['neutral'], 'dark'],
                    [get_string('goal_rechazado', 'local_chatbot'), $goals['rechazado'], $c['discard'], 'dark'],
                ]; ?>
                <div class="m1-goals-head">
                    <span class="m1-goals-big"><?php echo $goalsdecided > 0
                        ? local_chatbot_dash_pct($goalskept, $goalsdecided) . '%' : '—'; ?></span>
                    <span class="m1-goals-caption">
                        <strong><?php echo s(get_string('goals_kept', 'local_chatbot')); ?></strong><br>
                        <span class="m1-muted"><?php echo s(get_string('goals_total', 'local_chatbot',
                            (object) ['decididas' => $goalsdecided, 'total' => $goalstotal])); ?></span>
                        <?php if ($goalspending > 0): ?><br>
                            <span class="m1-goals-pending"><?php echo s(get_string('goals_undecided', 'local_chatbot',
                                (object) ['n' => $goalspending, 'pct' => local_chatbot_dash_pct($goalspending, $goalstotal)])); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php echo local_chatbot_dash_stack($goalsegments, $goalstotal); ?>
                <?php echo local_chatbot_dash_legend(array_map(
                    fn($seg) => [$seg[0], $seg[2], (string) $seg[1]], $goalsegments)); ?>
                <details class="m1-details">
                    <summary><?php echo $tablelabel; ?></summary>
                    <table class="m1-table">
                        <tbody>
                        <?php foreach ($goals as $status => $count): ?>
                            <tr>
                                <th scope="row"><?php echo s(get_string('goal_' . $status, 'local_chatbot')); ?></th>
                                <td><?php echo (int) $count; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>
        </section>

        <?php // Brechas: parte de un todo sobre una escala ordenada de cuatro niveles. ?>
        <section class="m1-card m1-span-12">
            <h2 class="m1-card-title"><?php echo s(get_string('analytics_gaps', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('gaps_help', 'local_chatbot')); ?></p>
            <?php if (empty($gaps)): ?>
                <?php echo local_chatbot_dash_empty('gaps_empty'); ?>
            <?php else: ?>
                <?php echo local_chatbot_dash_legend(array_map(
                    fn($level) => [get_string('level_' . $level, 'local_chatbot'), $levelcolors[$level][0], null], $levels)); ?>
                <div class="m1-gaps">
                    <?php foreach ($gaps as $row):
                        $segments = [];
                        foreach ($levels as $level) {
                            $segments[] = [get_string('level_' . $level, 'local_chatbot'), (int) $row[$level],
                                $levelcolors[$level][0], $levelcolors[$level][1]];
                        } ?>
                        <div class="m1-gap-row">
                            <div class="m1-gap-name"><?php echo s($row['name']); ?></div>
                            <?php echo local_chatbot_dash_stack($segments, (int) $row['personas']); ?>
                            <div class="m1-gap-meta">
                                <strong><?php echo s(get_string('gaps_avg', 'local_chatbot', format_float($row['promedio'], 2))); ?></strong>
                                <span class="m1-muted">/ <?php echo $scoremax; ?> · <?php
                                    echo s(get_string('gaps_people', 'local_chatbot', (int) $row['personas'])); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <details class="m1-details">
                    <summary><?php echo $tablelabel; ?></summary>
                    <div class="table-responsive">
                        <table class="m1-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo s(get_string('diag_dimension', 'local_chatbot')); ?></th>
                                    <?php foreach ($levels as $level): ?>
                                        <th scope="col"><?php echo s(get_string('level_' . $level, 'local_chatbot')); ?></th>
                                    <?php endforeach; ?>
                                    <th scope="col"><?php echo s(get_string('analytics_groupavg', 'local_chatbot')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($gaps as $row): ?>
                                <tr>
                                    <th scope="row"><?php echo s($row['name']); ?></th>
                                    <?php foreach ($levels as $level): ?>
                                        <td><?php echo (int) $row[$level]; ?></td>
                                    <?php endforeach; ?>
                                    <td><?php echo format_float($row['promedio'], 2); ?> / <?php echo $scoremax; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endif; ?>
        </section>

        <?php // Variacion: antes y despues por dimension, en una misma escala 1-5 (mancuerna). ?>
        <section class="m1-card m1-span-12">
            <h2 class="m1-card-title"><?php echo s(get_string('analytics_variation', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('variation_help', 'local_chatbot')); ?></p>
            <?php if (empty($variation)): ?>
                <?php echo local_chatbot_dash_empty('variation_empty'); ?>
            <?php else: ?>
                <?php echo local_chatbot_dash_legend([
                    [get_string('diag_entrada', 'local_chatbot'), $c['b250'], null],
                    [get_string('diag_salida', 'local_chatbot'), $c['b600'], null],
                ]); ?>
                <div class="m1-dumbbells">
                    <div class="m1-dumbbell-row m1-dumbbell-axis" aria-hidden="true">
                        <span></span>
                        <span class="m1-dumbbell-scale">
                            <?php for ($i = 1; $i <= $scoremax; $i++): ?>
                                <span style="left:<?php echo ($i - 1) * 100 / ($scoremax - 1); ?>%"><?php echo $i; ?></span>
                            <?php endfor; ?>
                        </span>
                        <span></span>
                    </div>
                    <?php foreach ($variation as $row):
                        $pos = fn($v) => max(0, min(100, ($v - 1) * 100 / ($scoremax - 1)));
                        $from = $pos($row['entrada']);
                        $to = $pos($row['salida']);
                        $delta = (float) $row['variacion'];
                        $deltatext = ($delta > 0 ? '+' : '') . format_float($delta, 2);
                        $entratip = s(get_string('diag_entrada', 'local_chatbot') . ': ' . format_float($row['entrada'], 2));
                        $saltip = s(get_string('diag_salida', 'local_chatbot') . ': ' . format_float($row['salida'], 2)); ?>
                        <div class="m1-dumbbell-row">
                            <span class="m1-gap-name"><?php echo s($row['name']); ?></span>
                            <span class="m1-dumbbell-track">
                                <span class="m1-dumbbell-link" style="left:<?php echo min($from, $to); ?>%;width:<?php
                                    echo abs($to - $from); ?>%"></span>
                                <span class="m1-dot" style="left:<?php echo $from; ?>%;background:<?php echo $c['b250']; ?>"
                                      tabindex="0" role="img" aria-label="<?php echo $entratip; ?>" data-tip="<?php echo $entratip; ?>"></span>
                                <span class="m1-dot" style="left:<?php echo $to; ?>%;background:<?php echo $c['b600']; ?>"
                                      tabindex="0" role="img" aria-label="<?php echo $saltip; ?>" data-tip="<?php echo $saltip; ?>"></span>
                            </span>
                            <span class="m1-delta <?php echo $delta > 0 ? 'is-up' : ($delta < 0 ? 'is-down' : ''); ?>">
                                <span aria-hidden="true"><?php echo $delta > 0 ? '▲' : ($delta < 0 ? '▼' : '='); ?></span>
                                <?php echo $deltatext; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <details class="m1-details">
                    <summary><?php echo $tablelabel; ?></summary>
                    <table class="m1-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php echo s(get_string('diag_dimension', 'local_chatbot')); ?></th>
                                <th scope="col"><?php echo s(get_string('diag_entrada', 'local_chatbot')); ?></th>
                                <th scope="col"><?php echo s(get_string('diag_salida', 'local_chatbot')); ?></th>
                                <th scope="col"><?php echo s(get_string('diag_variacion', 'local_chatbot')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($variation as $row): ?>
                            <tr>
                                <th scope="row"><?php echo s($row['name']); ?></th>
                                <td><?php echo format_float($row['entrada'], 2); ?></td>
                                <td><?php echo format_float($row['salida'], 2); ?></td>
                                <td><?php echo ($row['variacion'] > 0 ? '+' : '') . format_float($row['variacion'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>
        </section>

        <?php // Interacciones: largo = uso del modo; segmentos = que hizo la persona. ?>
        <section class="m1-card m1-span-6">
            <h2 class="m1-card-title"><?php echo s(get_string('analytics_interactions', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('interactions_help', 'local_chatbot')); ?></p>
            <?php if (empty($interactions)): ?>
                <?php echo local_chatbot_dash_empty('interactions_empty'); ?>
            <?php else: ?>
                <?php echo local_chatbot_dash_legend([
                    [get_string('analytics_accepted', 'local_chatbot'), $c['series'], null],
                    [get_string('analytics_rejected', 'local_chatbot'), $c['discard'], null],
                    [get_string('interactions_other', 'local_chatbot') . ' (' .
                        get_string('interactions_other_help', 'local_chatbot') . ')', $c['neutral'], null],
                ]); ?>
                <div class="m1-bars">
                    <?php foreach ($interactions as $row):
                        $other = $row['total'] - $row['aceptadas'] - $row['rechazadas'];
                        $segments = [
                            [get_string('analytics_accepted', 'local_chatbot'), $row['aceptadas'], $c['series'], 'light'],
                            [get_string('analytics_rejected', 'local_chatbot'), $row['rechazadas'], $c['discard'], 'dark'],
                            [get_string('interactions_other', 'local_chatbot'), $other, $c['neutral'], 'dark'],
                        ]; ?>
                        <div class="m1-bar-row">
                            <span class="m1-bar-label"><?php echo s($modelabels[$row['mode']] ?? $row['mode']); ?></span>
                            <span class="m1-bar-lane">
                                <?php echo local_chatbot_dash_stack($segments, $row['total'],
                                    local_chatbot_dash_pct($row['total'], $interactionsmax)); ?>
                                <strong class="m1-bar-value"><?php echo $row['total']; ?></strong>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <details class="m1-details">
                    <summary><?php echo $tablelabel; ?></summary>
                    <div class="table-responsive">
                        <table class="m1-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo s(get_string('analytics_mode', 'local_chatbot')); ?></th>
                                    <th scope="col"><?php echo s(get_string('analytics_total', 'local_chatbot')); ?></th>
                                    <th scope="col"><?php echo s(get_string('analytics_accepted', 'local_chatbot')); ?></th>
                                    <th scope="col"><?php echo s(get_string('analytics_rejected', 'local_chatbot')); ?></th>
                                    <th scope="col"><?php echo s(get_string('analytics_modified', 'local_chatbot')); ?></th>
                                    <th scope="col"><?php echo s(get_string('analytics_postponed', 'local_chatbot')); ?></th>
                                    <th scope="col"><?php echo s(get_string('analytics_pending', 'local_chatbot')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($interactions as $row): ?>
                                <tr>
                                    <th scope="row"><?php echo s($modelabels[$row['mode']] ?? $row['mode']); ?></th>
                                    <td><?php echo $row['total']; ?></td>
                                    <td><?php echo $row['aceptadas']; ?></td>
                                    <td><?php echo $row['rechazadas']; ?></td>
                                    <td><?php echo $row['modificadas']; ?></td>
                                    <td><?php echo $row['postergadas']; ?></td>
                                    <td><?php echo $row['pendientes']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endif; ?>
        </section>

        <?php // Rubrica: donde se concentran las observaciones de mejora. Una serie, valor en la punta. ?>
        <section class="m1-card m1-span-6">
            <h2 class="m1-card-title"><?php echo s(get_string('analytics_hotspots', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('analytics_hotspots_help', 'local_chatbot')); ?></p>
            <?php if (empty($hotspots)): ?>
                <?php echo local_chatbot_dash_empty('hotspots_empty'); ?>
            <?php else: ?>
                <div class="m1-bars">
                    <?php foreach ($hotspots as $row): ?>
                        <div class="m1-bar-row m1-bar-row-stacked">
                            <span class="m1-bar-label"><?php echo s($row['criterio']); ?></span>
                            <span class="m1-bar-lane">
                                <span class="m1-stack" style="width:<?php
                                    echo local_chatbot_dash_pct($row['observaciones'], $hotspotsmax); ?>%">
                                    <span class="m1-seg" style="flex-grow:1;background:<?php echo $c['series']; ?>"></span>
                                </span>
                                <strong class="m1-bar-value"><?php echo $row['observaciones']; ?></strong>
                            </span>
                            <?php if ($row['revisionhumana'] > 0): ?>
                                <span class="m1-bar-note"><?php
                                    echo s(get_string('hotspots_needshuman', 'local_chatbot', $row['revisionhumana'])); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php
        // Indicadores del Anexo G §11: tutoria, desarrollo adaptativo y PDP, y calidad del
        // asistente. Tabla con nombre, valor y definicion; los valores van como texto.
        $annexsections = [
            'tutoria' => get_string('annexg_tutoria', 'local_chatbot'),
            'desarrollo' => get_string('annexg_desarrollo', 'local_chatbot'),
            'calidad' => get_string('annexg_calidad', 'local_chatbot'),
        ];
        ?>
        <?php foreach ($annexsections as $key => $title): ?>
            <section class="m1-card m1-span-12">
                <h2 class="m1-card-title"><?php echo s($title); ?></h2>
                <p class="m1-card-help"><?php echo s(get_string('annexg_help', 'local_chatbot')); ?></p>
                <table class="table table-sm mb-0">
                    <thead><tr>
                        <th scope="col"><?php echo s(get_string('annexg_col_indicator', 'local_chatbot')); ?></th>
                        <th scope="col"><?php echo s(get_string('annexg_col_value', 'local_chatbot')); ?></th>
                        <th scope="col"><?php echo s(get_string('annexg_col_definition', 'local_chatbot')); ?></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($annexg[$key] as [$name, $value, $definition]): ?>
                            <tr>
                                <td><?php echo s($name); ?></td>
                                <td><strong><?php echo s($value); ?></strong></td>
                                <td class="small text-muted"><?php echo s($definition); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($key === 'tutoria' && !empty($annexg['conceptos'])): ?>
                            <tr>
                                <td><?php echo s(get_string('annexg_concepts', 'local_chatbot')); ?></td>
                                <td colspan="2"><?php
                                    $parts = [];
                                    foreach ($annexg['conceptos'] as $tag => $n) {
                                        $parts[] = s(str_replace('_', ' ', $tag)) . ' (' . (int) $n . ')';
                                    }
                                    echo implode(' · ', $parts); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php endforeach; ?>

        <?php // Catalogo de eventos: un bloque por familia, ordenado por uso. ?>
        <section class="m1-card m1-span-7">
            <h2 class="m1-card-title"><?php echo s(get_string('catalog_title', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('catalog_help', 'local_chatbot')); ?></p>
            <?php if (empty($catalog)): ?>
                <?php echo local_chatbot_dash_empty('catalog_empty'); ?>
            <?php else: ?>
                <?php
                $catalogmax = 0;
                foreach ($catalog as $familia) {
                    foreach ($familia['codes'] as $evento) {
                        $catalogmax = max($catalogmax, $evento['total']);
                    }
                }
                ?>
                <?php foreach ($catalog as $familia): ?>
                    <h3 class="m1-subtitle"><?php echo s($familia['label']); ?>
                        <span class="m1-subtitle-note"><?php
                            echo s(get_string('catalog_family_total', 'local_chatbot', $familia['total'])); ?></span>
                    </h3>
                    <div class="m1-bars">
                        <?php foreach ($familia['codes'] as $evento): ?>
                            <div class="m1-bar-row">
                                <span class="m1-bar-label m1-code"><?php
                                    echo s(str_replace('EVT-', '', $evento['code'])); ?></span>
                                <span class="m1-bar-lane">
                                    <?php echo local_chatbot_dash_stack(
                                        [[$evento['code'], $evento['total'], $c['series'], 'light']],
                                        $evento['total'],
                                        local_chatbot_dash_pct($evento['total'], $catalogmax)); ?>
                                    <strong class="m1-bar-value"><?php echo (int) $evento['total']; ?></strong>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <details class="m1-details">
                    <summary><?php echo $tablelabel; ?></summary>
                    <div class="table-responsive">
                        <table class="m1-table">
                            <thead><tr>
                                <th scope="col"><?php echo s(get_string('catalog_col_code', 'local_chatbot')); ?></th>
                                <th scope="col"><?php echo s(get_string('catalog_col_events', 'local_chatbot')); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($catalog as $familia): ?>
                                <?php foreach ($familia['codes'] as $evento): ?>
                                    <tr>
                                        <td class="m1-code"><?php echo s($evento['code']); ?></td>
                                        <td><?php echo (int) $evento['total']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endif; ?>
        </section>

        <?php // Quiz formativo: correctas e incorrectas por subdimension. ?>
        <section class="m1-card m1-span-5">
            <h2 class="m1-card-title"><?php echo s(get_string('quizpat_title', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('quizpat_help', 'local_chatbot')); ?></p>
            <?php if (empty($quizpat)): ?>
                <?php echo local_chatbot_dash_empty('quizpat_empty'); ?>
            <?php else: ?>
                <?php echo local_chatbot_dash_legend([
                    [get_string('quizpat_correct', 'local_chatbot'), $c['series'], null],
                    [get_string('quizpat_incorrect', 'local_chatbot'), $c['discard'], null],
                ]); ?>
                <?php $quizmax = max(array_map(function($r) {
                    return $r['total'];
                }, $quizpat)); ?>
                <div class="m1-bars">
                    <?php foreach ($quizpat as $fila): ?>
                        <div class="m1-bar-row">
                            <span class="m1-bar-label"><?php echo s($fila['subdimension']); ?></span>
                            <span class="m1-bar-lane">
                                <?php echo local_chatbot_dash_stack([
                                    [get_string('quizpat_correct', 'local_chatbot'),
                                        $fila['correctas'], $c['series'], 'light'],
                                    [get_string('quizpat_incorrect', 'local_chatbot'),
                                        $fila['incorrectas'], $c['discard'], 'dark'],
                                ], $fila['total'], local_chatbot_dash_pct($fila['total'], $quizmax)); ?>
                                <strong class="m1-bar-value"><?php echo (int) $fila['total']; ?></strong>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <details class="m1-details">
                    <summary><?php echo $tablelabel; ?></summary>
                    <div class="table-responsive">
                        <table class="m1-table">
                            <thead><tr>
                                <th scope="col"><?php echo s(get_string('quizpat_col_sub', 'local_chatbot')); ?></th>
                                <th scope="col"><?php echo s(get_string('quizpat_correct', 'local_chatbot')); ?></th>
                                <th scope="col"><?php echo s(get_string('quizpat_incorrect', 'local_chatbot')); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($quizpat as $fila): ?>
                                <tr>
                                    <td><?php echo s($fila['subdimension']); ?></td>
                                    <td><?php echo (int) $fila['correctas']; ?></td>
                                    <td><?php echo (int) $fila['incorrectas']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
            <?php endif; ?>
        </section>

        <?php // Salud del servicio: estados, latencia por modo y consumo. ?>
        <section class="m1-card m1-span-12">
            <h2 class="m1-card-title"><?php echo s(get_string('health_title', 'local_chatbot')); ?></h2>
            <p class="m1-card-help"><?php echo s(get_string('health_help', 'local_chatbot')); ?></p>
            <?php if (empty($health['estados'])): ?>
                <?php echo local_chatbot_dash_empty('health_empty'); ?>
            <?php else: ?>
                <?php
                $estados = $health['estados'];
                $tokens = (object) [
                    'entrada' => number_format($health['tokens']['entrada'] / 1000, 1, ',', '.'),
                    'salida' => number_format($health['tokens']['salida'] / 1000, 1, ',', '.'),
                ];
                $resumen = [
                    ['health_ok', (int) ($estados['ok'] ?? 0), 'ok'],
                    ['health_error', (int) ($estados['error'] ?? 0), 'alerta'],
                    ['health_blocked', (int) ($estados['blocked'] ?? 0), 'neutro'],
                ];
                ?>
                <ul class="m1-healthlist">
                    <?php foreach ($resumen as [$clave, $valor, $tono]): ?>
                        <li class="m1-health m1-health-<?php echo $tono; ?>">
                            <strong><?php echo $valor; ?></strong>
                            <span><?php echo s(get_string($clave, 'local_chatbot')); ?></span>
                        </li>
                    <?php endforeach; ?>
                    <li class="m1-health m1-health-neutro">
                        <strong><?php echo s(get_string('health_tokens_value', 'local_chatbot', $tokens)); ?></strong>
                        <span><?php echo s(get_string('health_tokens', 'local_chatbot')); ?></span>
                    </li>
                </ul>
                <?php if (!empty($health['latencia'])): ?>
                    <h3 class="m1-subtitle"><?php echo s(get_string('health_latency', 'local_chatbot')); ?></h3>
                    <div class="table-responsive">
                        <table class="m1-table">
                            <thead><tr>
                                <th scope="col"><?php echo s(get_string('analytics_mode', 'local_chatbot')); ?></th>
                                <th scope="col"><?php echo s(get_string('health_col_avg', 'local_chatbot')); ?></th>
                                <th scope="col"><?php echo s(get_string('health_col_worst', 'local_chatbot')); ?></th>
                                <th scope="col"><?php echo s(get_string('health_col_calls', 'local_chatbot')); ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($health['latencia'] as $fila): ?>
                                <tr>
                                    <td><?php echo s($modelabels[$fila['mode']] ?? $fila['mode']); ?></td>
                                    <td><?php echo s(get_string('health_seconds', 'local_chatbot',
                                        number_format($fila['media'], 1, ',', '.'))); ?></td>
                                    <td><?php echo s(get_string('health_seconds', 'local_chatbot',
                                        number_format($fila['peor'], 1, ',', '.'))); ?></td>
                                    <td><?php echo (int) $fila['total']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

    </div>

    <div class="m1-tip" role="tooltip" hidden></div>
</div>

<script>
// Tooltip unico para barras y puntos. Mismo contenido con el mouse y con el
// teclado; el texto va con textContent, nunca como HTML.
(function() {
    var root = document.querySelector('.m1-dash');
    if (!root) {
        return;
    }
    var tip = root.querySelector('.m1-tip');
    function crosshair(el) {
        // En los gráficos de líneas, la franja de la semana mueve la guía
        // vertical: se apunta a una fecha, no a una línea de 2px.
        var svg = el.ownerSVGElement;
        if (!svg) {
            return;
        }
        var line = svg.querySelector('.m1-chart-cross');
        var x = el.getAttribute('data-x');
        if (line && x) {
            line.setAttribute('x1', x);
            line.setAttribute('x2', x);
            line.removeAttribute('hidden');
        }
    }
    function clearCrosshairs() {
        root.querySelectorAll('.m1-chart-cross').forEach(function(line) {
            line.setAttribute('hidden', 'hidden');
        });
    }
    function show(el) {
        if (el.classList.contains('m1-chart-hit')) {
            crosshair(el);
        }
        tip.textContent = el.getAttribute('data-tip');
        tip.hidden = false;
        var r = el.getBoundingClientRect();
        var t = tip.getBoundingClientRect();
        var left = Math.max(8, Math.min(window.innerWidth - t.width - 8, r.left + r.width / 2 - t.width / 2));
        var top = r.top - t.height - 8;
        if (top < 8) {
            top = r.bottom + 8;
        }
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
    }
    function hide() {
        tip.hidden = true;
        clearCrosshairs();
    }
    root.addEventListener('pointerover', function(e) {
        var el = e.target.closest('[data-tip]');
        if (el) {
            show(el);
        }
    });
    root.addEventListener('pointerout', function(e) {
        if (e.target.closest('[data-tip]')) {
            hide();
        }
    });
    root.addEventListener('focusin', function(e) {
        var el = e.target.closest('[data-tip]');
        if (el) {
            show(el);
        }
    });
    root.addEventListener('focusout', hide);
    window.addEventListener('scroll', hide, {passive: true});
})();
</script>

<?php
echo $OUTPUT->footer();
