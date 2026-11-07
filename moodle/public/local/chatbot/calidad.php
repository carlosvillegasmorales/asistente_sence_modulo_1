<?php
/**
 * Modulo 3, Fases 2 y 3: hoja de trabajo de cada etapa del aseguramiento de la calidad de los aprendizajes.
 *
 * Prompt Maestro del Asistente de Aseguramiento de la Calidad de los Aprendizajes (Interfases, 06-11-2026): la Fase 2
 * (evaluacion de la implementacion) son las etapas 1 a 3 y la Fase 3 (ajuste de la experiencia de aprendizaje) las etapas 4 a
 * 8. El asistente propone el producto de la etapa a partir de la ficha validada en la Fase 1, del diseño elegido y de las
 * etapas ya aceptadas; la persona lo edita, pide ajustes y lo acepta, y puede descargarlo en Word. La etapa 8 consolida todo en
 * un documento unico. La logica vive en classes/calidad.php.
 *
 * Como la validacion de la ficha, la generacion corre en una segunda llamada que la pagina dispara y consulta.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/editorlib.php');

use local_chatbot\calidad;
use local_chatbot\diseno;
use local_chatbot\exante;
use local_chatbot\implementacion;

$courseid = required_param('courseid', PARAM_INT);
if ((int) $courseid === (int) SITEID) {
    throw new moodle_exception('invalidcourseid');
}
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/chatbot:use', $context);

$etapa = optional_param('etapa', 0, PARAM_INT);

$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('cal_title', 'local_chatbot'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js(new moodle_url('/local/chatbot/js/espera.js'), true);

if (!\local_chatbot\ux::on('calidadfase2')) {
    $PAGE->set_url(new moodle_url('/local/chatbot/calidad.php', ['courseid' => $courseid]));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('cal_disabled', 'local_chatbot'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$action = optional_param('action', '', PARAM_ALPHA);

// --- Llamadas de la propia pagina mientras se genera ---
if ($action === 'ejecutar' || $action === 'estado') {
    $id = required_param('id', PARAM_INT);
    if ($action === 'ejecutar') {
        require_sesskey();
        \core\session\manager::write_close();
        ignore_user_abort(true);
        // La consolidacion con el asistente (etapa 8) es un documento largo: puede tardar mas que una etapa.
        core_php_time_limit::raise(calidad::CONSOLIDACION_MAX);
        $estado = calidad::run($id, $USER->id);
    } else {
        // Da por caida la generacion que lleva demasiado tiempo.
        calidad::get_all($USER->id, $courseid);
        $estado = $DB->get_field('local_chatbot_calidad', 'generacion', ['id' => $id, 'userid' => $USER->id]);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['estado' => $estado ?: implementacion::ERROR]);
    exit;
}

$fase1 = implementacion::fase1_ok($USER->id, $courseid);
$ficha = implementacion::get_ficha($USER->id, $courseid);
$filas = calidad::get_all($USER->id, $courseid);
$huellaval = $fase1 ? (string) $fase1->huella : '';
$aceptadas = calidad::accepted($filas, $huellaval);
$ajuste = calidad::decided_adjustment($filas);
$etapas = calidad::todas($ajuste);
if (!isset($etapas[$etapa])) {
    $etapa = calidad::entry_stage(2, $aceptadas);
}
$pageurl = new moodle_url('/local/chatbot/calidad.php', ['courseid' => $courseid, 'etapa' => $etapa]);
$PAGE->set_url($pageurl);
$fase = calidad::phase_of($etapa);
$consolida = $etapa === calidad::CONSOLIDACION;
$grupo = array_intersect_key($etapas, array_flip(calidad::stages_of_phase($fase)));
$titulofase = get_string($fase === 3 ? 'cal_title3' : 'cal_title2', 'local_chatbot');
$PAGE->navbar->add($titulofase);
$abierta = calidad::can_open($etapa, (bool) $fase1, $aceptadas, $ajuste);
$fila = $filas[$etapa] ?? null;
$diseno = ($ficha && (int) $ficha->disenoid > 0) ? implementacion::get_design((int) $ficha->disenoid, $USER->id) : null;
// Los datos calculados salen de la ficha vigente (la que se valido) y del diseño elegido.
$analisis = ($fase1 && $diseno) ? calidad::analysis($ficha->data, $diseno['referencia']) : null;
$restante = $analisis ? $analisis['tiempos']['restante'] : null;

// --- Descarga en Word de una etapa ---
if ($action === 'word' && $abierta && !$consolida && $fila && $fila->contenido) {
    $ruta = make_request_directory() . '/producto.docx';
    $titulo = 'Etapa ' . $etapa . ': ' . $etapas[$etapa]['titulo'];
    $subtitulo = format_string($course->fullname) . ' · ' . userdate(time(), get_string('strftimedate', 'langconfig'));
    $producto = diseno::normalize_product((string) $fila->contenido);
    // Con «docetapa», el Word de la etapa sale con el mismo diseño del documento final (sin portada ni indice).
    $conformato = \local_chatbot\ux::on('docetapa') && \local_chatbot\documento_final::build($ruta,
        ['portada' => false, 'encabezado' => $titulo . ' · ' . $subtitulo], diseno::stage_document_html($titulo, $producto),
        $CFG->dirroot . '/local/chatbot/pix/logo_interfases.png');
    if ($conformato || diseno::html_to_docx($ruta, $titulo, $subtitulo, $producto)) {
        send_file($ruta, $etapas[$etapa]['archivo'] . '.docx', 0, 0, false, true,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
    throw new moodle_exception('diseno_word_error', 'local_chatbot');
}

// --- Documento final: la Etapa 0 y las etapas 1 a 7 consolidadas ---
if ($action === 'final' && $fase1 && $ficha && in_array(calidad::CONSOLIDACION, $aceptadas, true)) {
    $ruta = make_request_directory() . '/final.docx';
    $partes = [0 => calidad::validation_html((string) $fase1->condicion, (array) $fase1->informe)];
    foreach (array_keys(calidad::etapas()) as $n) {
        $partes[$n] = (string) ($filas[$n]->contenido ?? '');
    }
    $html = diseno::normalize_product(diseno::complete_consolidation((string) $filas[calidad::CONSOLIDACION]->contenido, $partes, 7));
    $fecha = userdate($filas[calidad::CONSOLIDACION]->timeaccepted, get_string('strftimedate', 'langconfig'));
    $portada = calidad::final_cover($ficha->data, $ajuste, $fecha);
    $conformato = \local_chatbot\documento_final::build($ruta, $portada, $html, $CFG->dirroot . '/local/chatbot/pix/logo_interfases.png');
    if ($conformato || diseno::html_to_docx($ruta, $portada['titulo'], $portada['subtitulo'], $html)) {
        send_file($ruta, calidad::etapa_consolidacion()['archivo'] . '.docx', 0, 0, false, true,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
    throw new moodle_exception('diseno_word_error', 'local_chatbot');
}

// --- Etapa 8: pedir la consolidacion al asistente o confirmarla ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $abierta && $consolida && in_array($action, ['confirmar', 'consolidar'], true)) {
    require_sesskey();
    $destino = new moodle_url($pageurl, [], 'producto');
    if ($action === 'consolidar' && \local_chatbot\ux::on('consolidacionm3')) {
        if (!\local_chatbot\ai\provider_factory::is_configured()) {
            redirect($destino, get_string('impl_error_sinclave', 'local_chatbot'), null, \core\output\notification::NOTIFY_ERROR);
        }
        calidad::request($USER->id, $courseid, calidad::CONSOLIDACION, (int) $fase1->id);
        redirect($destino);
    }
    if ($action === 'confirmar') {
        $contenidos = [];
        foreach (array_keys(calidad::etapas()) as $n) {
            $contenidos[$n] = (string) $filas[$n]->contenido;
        }
        // Se confirma lo que se muestra: la consolidacion del asistente si esta al dia, o la completa por codigo.
        $etapa0 = calidad::validation_html((string) $fase1->condicion, (array) $fase1->informe);
        $huellaetapas = calidad::stages_fingerprint($filas, $huellaval);
        [$html, $porasistente] = calidad::consolidation_shown($filas[calidad::CONSOLIDACION] ?? null, $etapa0, $contenidos, $huellaetapas);
        calidad::confirm_consolidation($USER->id, $courseid, $html, $huellaetapas,
            $porasistente ? (string) $filas[calidad::CONSOLIDACION]->promptversion : null);
        redirect($destino, get_string('cal_confirmed', 'local_chatbot'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($destino);
}

// --- Acciones del formulario de una etapa ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $abierta && !$consolida
        && in_array($action, ['generar', 'ajustar', 'completar', 'guardar', 'aceptar', 'ajuste'], true)) {
    require_sesskey();
    $destino = new moodle_url($pageurl, [], 'producto');
    if ($action === 'ajuste' && $etapa === 3) {
        // El tipo de ajuste: el del asistente, o el que la persona elige (nunca menos exigente que lo que indican los datos).
        $ok = calidad::set_adjustment($USER->id, $courseid, optional_param('ajuste', '', PARAM_ALPHAEXT));
        redirect($destino, get_string($ok ? 'cal_adjustment_saved' : 'cal_adjustment_invalid', 'local_chatbot'), null,
            $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
    }
    if ($action === 'guardar' || $action === 'aceptar') {
        if ($action === 'aceptar' && $etapa === 3 && !calidad::decision_valid(calidad::decision_info($fila))) {
            // Sin tipo de ajuste, o con uno menos exigente que lo que indican los datos, no se puede abrir la Fase 3: se pide
            // elegirlo antes de aceptar.
            calidad::save($USER->id, $courseid, $etapa, optional_param('contenido', '', PARAM_RAW), false);
            redirect($destino, get_string('cal_adjustment_needed', 'local_chatbot'), null, \core\output\notification::NOTIFY_WARNING);
        }
        calidad::save($USER->id, $courseid, $etapa, optional_param('contenido', '', PARAM_RAW), $action === 'aceptar');
        redirect($destino, get_string($action === 'aceptar' ? 'diseno_accepted' : 'diseno_saved', 'local_chatbot'),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if (!\local_chatbot\ai\provider_factory::is_configured()) {
        redirect($destino, get_string('impl_error_sinclave', 'local_chatbot'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $ajustes = $action === 'ajustar' ? trim(optional_param('ajustes', '', PARAM_TEXT)) : '';
    // «Pedir lo que falta»: el ajuste lo arma la revision automatica con lo que no encontro en el producto.
    if ($action === 'completar' && $fila) {
        $producto = diseno::normalize_product((string) optional_param('contenido', $fila->contenido, PARAM_RAW));
        $faltan = calidad::missing_items($etapa, $producto, $ajuste);
        $pedidos = $faltan ? [get_string('diseno_check_prompt', 'local_chatbot', implode('; ', $faltan))] : [];
        // «Pedir ajuste de tiempos»: la etapa 4 se pasa del tiempo restante.
        if ($etapa === 4) {
            $tc = calidad::time_check($producto, $restante);
            if ($tc['estado'] === 'excede') {
                $pedidos[] = get_string('cal_time_prompt', 'local_chatbot', (object) ['suma' => $tc['suma'],
                    'restante' => max(0, (int) $restante)]);
            }
        }
        $ajustes = implode(' ', $pedidos);
    }
    if ($action === 'ajustar' || $action === 'completar') {
        if ($ajustes === '') {
            redirect($destino, get_string('diseno_adjust_empty', 'local_chatbot'), null, \core\output\notification::NOTIFY_WARNING);
        }
        // Lo editado a mano no se pierde: el ajuste parte de la version que la persona tiene en pantalla.
        $editado = optional_param('contenido', null, PARAM_RAW);
        if ($editado !== null && $fila) {
            calidad::save($USER->id, $courseid, $etapa, $editado, false);
        }
    }
    calidad::request($USER->id, $courseid, $etapa, (int) $fase1->id, $ajustes);
    redirect($destino);
}

// Secciones del curso de las fases (por su nombre).
$nombres = [];
foreach (get_fast_modinfo($course)->get_section_info_all() as $seccion) {
    $nombres[$seccion->section] = get_section_name($course, $seccion);
}
$urlcurso = new moodle_url('/course/view.php', ['id' => $courseid]);
$urlfase = static function(int $f) use ($course, $nombres, $urlcurso) {
    $num = exante::phase_section($nombres, $f);
    return ($num !== null ? course_get_url($course, $num) : null) ?: $urlcurso;
};
$generando = $fila && in_array($fila->generacion, [implementacion::PENDIENTE, implementacion::PROCESANDO], true);
$hayproducto = $fila && trim(strip_tags((string) $fila->contenido)) !== '';
$aceptada = $consolida ? in_array($etapa, $aceptadas, true) : ($fila && $fila->estado === calidad::ACEPTADO && $hayproducto);

if ($hayproducto && !$generando && !$consolida) {
    editors_get_preferred_editor(FORMAT_HTML)->use_editor('calidad-contenido',
        ['context' => $context, 'autosave' => false, 'enable_filemanagement' => false]);
}

/**
 * Los datos que calcula la plataforma, en tablas (etapas 1 a 3).
 *
 * @param array $a calidad::analysis().
 * @param bool $conDecision Si incluye la tabla de decision (etapa 3).
 * @return void
 */
function local_chatbot_calidad_datos(array $a, bool $conDecision): void {
    $t = $a['tiempos'];
    ?>
    <details class="card border-0 bg-light mb-3" open>
        <summary class="card-body py-2 fw-bold" style="cursor: pointer;"><?php echo get_string('cal_data_title', 'local_chatbot'); ?></summary>
        <div class="card-body pt-0">
            <p class="small text-muted"><?php echo get_string('cal_data_help', 'local_chatbot'); ?></p>
            <div class="row g-2 mb-3 text-center">
                <?php foreach ([['cal_data_total', $t['total']], ['cal_data_used', $t['utilizado']], ['cal_data_left', $t['restante']],
                        ['cal_data_avg', $a['promedio'] === null ? null : $a['promedio']], ['cal_data_coverage', $a['cobertura']]]
                        as [$clave, $valor]): ?>
                    <div class="col-6 col-md"><div class="border rounded p-2 bg-white"><div class="small text-muted"><?php
                        echo get_string($clave, 'local_chatbot'); ?></div><div class="fw-bold fs-5"><?php
                        if ($valor === null) {
                            echo '—';
                        } else if (in_array($clave, ['cal_data_avg', 'cal_data_coverage'], true)) {
                            echo s(implementacion::pct((float) $valor));
                        } else {
                            echo (int) $valor . ' min';
                        } ?></div></div></div>
                <?php endforeach; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-2 bg-white small">
                    <thead><tr><th><?php echo get_string('cal_data_criterion', 'local_chatbot'); ?></th>
                        <?php foreach (implementacion::NIVELES as $nombre): ?><th class="text-center"><?php echo s($nombre); ?></th><?php endforeach; ?>
                        <th class="text-center"><?php echo get_string('cal_data_logro', 'local_chatbot'); ?></th>
                        <th class="text-center"><?php echo get_string('cal_data_gap', 'local_chatbot'); ?></th>
                        <th class="text-center"><?php echo get_string('cal_data_level', 'local_chatbot'); ?></th>
                        <th class="text-center"><?php echo get_string('cal_data_deepen', 'local_chatbot'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($a['criterios'] as $c): ?>
                        <tr><td><?php echo s($c['n'] . '. ' . $c['criterio']); ?></td>
                            <?php foreach (array_keys(implementacion::NIVELES) as $k): ?><td class="text-center"><?php echo (int) $c['niveles'][$k]; ?></td><?php endforeach; ?>
                            <td class="text-center fw-bold"><?php echo s(implementacion::pct($c['logro'])); ?></td>
                            <td class="text-center"><?php echo $c['criticidad'] === null ? get_string('cal_data_nogap', 'local_chatbot')
                                : get_string('cal_crit_' . $c['criticidad'], 'local_chatbot'); ?></td>
                            <td class="text-center"><?php echo (int) $c['nivelacion']; ?></td>
                            <td class="text-center"><?php echo (int) $c['profundizacion']; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small mb-2"><?php echo get_string('cal_data_pending', 'local_chatbot', (object) ['trabajados' => $a['trabajados'],
                'diseno' => $a['diseno'], 'pendientes' => $a['pendientes']]); ?></p>
            <?php if ($conDecision): ?>
                <h6 class="mt-3"><?php echo get_string('cal_data_decision', 'local_chatbot', s(calidad::adjustment_name($a['decision']['minimo']))); ?></h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-2 bg-white small">
                        <thead><tr><th><?php echo get_string('cal_data_dimension', 'local_chatbot'); ?></th>
                            <th><?php echo get_string('cal_data_datum', 'local_chatbot'); ?></th>
                            <th><?php echo get_string('cal_data_condition', 'local_chatbot'); ?></th>
                            <th><?php echo get_string('cal_data_indicates', 'local_chatbot'); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ($a['decision']['filas'] as $f): ?>
                            <tr><td><?php echo s($f['dimension']); ?></td><td><?php echo s($f['dato']); ?></td>
                                <td><?php echo s($f['condicion']); ?></td><td class="fw-bold"><?php echo s(calidad::adjustment_name($f['ajuste'])); ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <ul class="small mb-0">
                    <?php foreach ($a['decision']['cualitativas'] as $q): ?><li><?php echo s($q); ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </details>
    <?php
}

echo $OUTPUT->header();
?>

<div class="container-fluid" style="max-width: 980px;">

    <h3 class="mb-1"><?php echo $titulofase; ?></h3>
    <p class="text-muted mb-3"><?php echo get_string($fase === 3 ? 'cal_subtitle3' : 'cal_subtitle2', 'local_chatbot'); ?></p>

    <?php // Las tres fases del modulo, con el mismo estilo que la ruta de los otros modulos. ?>
    <?php echo \local_chatbot\ruta_m3::render($USER->id, $courseid, $fase, $etapa); ?>

    <?php // Las etapas de la fase, con su estado: la persona sabe donde esta y que falta. ?>
    <ol class="chatbot-diseno-etapas list-unstyled d-flex flex-wrap gap-2 mb-4">
        <?php foreach ($grupo as $n => $e):
            $ok = in_array($n, $aceptadas, true);
            $puede = calidad::can_open($n, (bool) $fase1, $aceptadas, $ajuste);
            $clase = $n === $etapa ? 'actual' : ($ok ? 'hecha' : ($puede ? 'abierta' : 'cerrada'));
            $texto = ($ok ? '✔ ' : $n . '. ') . s($e['titulo']); ?>
            <li class="chatbot-diseno-etapa chatbot-diseno-<?php echo $clase; ?>">
                <?php if ($puede && $n !== $etapa): ?>
                    <a href="<?php echo (new moodle_url($pageurl, ['etapa' => $n]))->out(false); ?>"><?php echo $texto; ?></a>
                <?php else: ?>
                    <span<?php echo $n === $etapa ? ' aria-current="step"' : ''; ?>><?php echo $texto; ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php if (!$fase1): ?>
        <?php // Regla critica del Prompt Maestro: este asistente no trabaja sin diseño seleccionado ni ficha validada. ?>
        <div class="alert alert-warning">
            <?php echo s(implementacion::REGLA_CRITICA); ?>
            <div class="mt-2"><a class="chatbot-gatebtn" href="<?php echo (new moodle_url('/local/chatbot/ficha_implementacion.php',
                ['courseid' => $courseid]))->out(false); ?>"><?php echo get_string('cal_go_fase1', 'local_chatbot'); ?></a></div>
        </div>
    <?php elseif (!$abierta && $etapa >= 4 && $ajuste === null && in_array(3, $aceptadas, true)): ?>
        <div class="alert alert-info">
            <?php echo get_string('cal_needs_adjustment', 'local_chatbot'); ?>
            <div class="mt-2"><a class="chatbot-gatebtn" href="<?php echo (new moodle_url($pageurl, ['etapa' => 3]))->out(false); ?>"><?php
                echo get_string('diseno_go_current', 'local_chatbot'); ?></a></div>
        </div>
    <?php elseif (!$abierta): ?>
        <div class="alert alert-info">
            <?php echo get_string('diseno_locked', 'local_chatbot', $etapa - 1); ?>
            <div class="mt-2"><a class="chatbot-gatebtn" href="<?php echo (new moodle_url($pageurl,
                ['etapa' => calidad::current_stage($aceptadas)]))->out(false); ?>"><?php
                echo get_string('diseno_go_current', 'local_chatbot'); ?></a></div>
        </div>
    <?php else: ?>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <h4 class="mb-2"><?php echo get_string('diseno_stage', 'local_chatbot', (object) ['n' => $etapa,
                    'titulo' => $etapas[$etapa]['titulo']]); ?></h4>
                <p class="small text-muted mb-1"><?php echo get_string('diseno_includes', 'local_chatbot'); ?></p>
                <ul class="small mb-2">
                    <?php foreach ($etapas[$etapa]['incluye'] as $item): ?><li><?php echo s($item); ?></li><?php endforeach; ?>
                </ul>
                <?php if (($etapas[$etapa]['nota'] ?? '') !== ''): ?>
                    <p class="small text-muted mb-0"><?php echo s($etapas[$etapa]['nota']); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($analisis && $etapa <= 3): ?>
            <?php local_chatbot_calidad_datos($analisis, $etapa === 3); ?>
        <?php endif; ?>

        <div id="producto">
        <?php if ($consolida): ?>
            <?php
            $vigente = $aceptada;
            $contenidos = [];
            foreach (array_keys(calidad::etapas()) as $n) {
                $contenidos[$n] = (string) ($filas[$n]->contenido ?? '');
            }
            $etapa0 = calidad::validation_html((string) $fase1->condicion, (array) $fase1->informe);
            // Lo que se muestra corresponde siempre a las etapas de hoy: la consolidacion del asistente (sin repetir campos
            // comunes) si esta al dia, o la completa por codigo. Si se confirma, eso queda guardado.
            [$documento, $porasistente] = calidad::consolidation_shown($fila, $etapa0, $contenidos,
                calidad::stages_fingerprint($filas, $huellaval));
            $consolidaia = \local_chatbot\ux::on('consolidacionm3');
            $enlacesetapas = [];
            foreach (calidad::etapas() as $n => $e) {
                $enlacesetapas[] = html_writer::link(new moodle_url($pageurl, ['etapa' => $n]), $n . '. ' . s($e['titulo']));
            } ?>
            <?php if ($fila && $fila->estado === calidad::ACEPTADO && !$vigente): ?>
                <div class="alert alert-warning"><?php echo get_string('cal_consolidation_stale', 'local_chatbot'); ?></div>
            <?php endif; ?>
            <?php if ($generando): ?>
                <div class="card shadow-sm border-0 mb-4" id="calidad-generando" role="status"
                     data-id="<?php echo (int) $fila->id; ?>" data-estado="<?php echo s($fila->generacion); ?>"
                     data-url="<?php echo s((new moodle_url('/local/chatbot/calidad.php', ['courseid' => $courseid]))->out(false)); ?>"
                     data-sesskey="<?php echo sesskey(); ?>"
                     data-volver="<?php echo s((new moodle_url($pageurl, ['g' => time()], 'producto'))->out(false)); ?>">
                    <div class="card-body">
                        <h5 class="mb-2"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?php
                            echo get_string('cal_consolidating', 'local_chatbot'); ?></h5>
                        <p class="small text-muted mb-0"><?php echo get_string('diseno_consolidating_long', 'local_chatbot'); ?></p>
                    </div>
                </div>
            <?php elseif (!$vigente && $fila && $fila->generacion === implementacion::ERROR): ?>
                <div class="alert alert-warning"><?php echo get_string('impl_error_' . ($fila->error ?: 'servicio'), 'local_chatbot'); ?></div>
            <?php endif; ?>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h5 class="mb-0"><?php echo get_string('cal_consolidated', 'local_chatbot'); ?></h5>
                <div class="d-flex flex-wrap gap-1">
                    <?php if ($porasistente): ?>
                        <span class="badge text-bg-info"><?php echo get_string('diseno_consolidated_ai', 'local_chatbot'); ?></span>
                    <?php endif; ?>
                    <?php if ($vigente): ?>
                        <span class="badge text-bg-success"><?php echo get_string('diseno_status_confirmed', 'local_chatbot',
                            userdate($fila->timeaccepted, get_string('strftimedatetimeshort', 'langconfig'))); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="alert alert-info small">
                <?php echo get_string('cal_consolidated_help', 'local_chatbot'); ?>
                <div class="mt-1"><?php echo implode(' · ', $enlacesetapas); ?></div>
            </div>
            <div class="card border mb-3">
                <div class="card-body chatbot-diseno-doc" style="max-height: 640px; overflow-y: auto;">
                    <?php echo format_text(diseno::normalize_product($documento), FORMAT_HTML, ['context' => $context]); ?>
                </div>
            </div>
            <?php $faltan8 = \local_chatbot\ux::on('revisioncontenido')
                ? calidad::missing_items(calidad::CONSOLIDACION, diseno::normalize_product($documento)) : [];
            if ($faltan8): ?>
                <div class="alert alert-warning small">
                    <?php echo get_string('cal_check_missing_consolidation', 'local_chatbot'); ?>
                    <ul class="mb-0 mt-1"><?php foreach ($faltan8 as $falta): ?><li><?php echo s($falta); ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
            <?php if (!$vigente && !$generando): ?>
                <form method="post" action="<?php echo $pageurl->out(false); ?>" class="mb-4 d-flex flex-wrap gap-2">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <?php if ($consolidaia && (!$porasistente || $faltan8)): ?>
                        <?php // Prompt Maestro: consolidar «en extenso», sin repetir los campos comunes (lo hace el asistente). ?>
                        <button type="submit" name="action" value="consolidar" class="btn btn-primary px-4"
                                data-espera="<?php echo s(get_string('cal_consolidating', 'local_chatbot')); ?>">
                            <i class="fa fa-magic me-1"></i> <?php echo get_string('diseno_consolidate_ai', 'local_chatbot'); ?>
                        </button>
                    <?php endif; ?>
                    <button type="submit" name="action" value="confirmar" class="btn btn-success px-4"
                            data-espera="<?php echo s(get_string('exante_saving', 'local_chatbot')); ?>">
                        <i class="fa fa-check me-1"></i> <?php echo get_string('cal_confirm', 'local_chatbot'); ?>
                    </button>
                </form>
                <?php if ($consolidaia && !$porasistente): ?>
                    <p class="small text-muted"><?php echo get_string('cal_consolidate_ai_help', 'local_chatbot'); ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($vigente): ?>
                <?php // Con esto cierra el proceso del modulo: el documento final queda disponible. ?>
                <div class="alert alert-success">
                    <strong><?php echo get_string('cal_done', 'local_chatbot'); ?></strong>
                    <div class="mt-2"><a class="chatbot-gatebtn" href="<?php echo (new moodle_url($pageurl, ['action' => 'final']))->out(false); ?>">
                        <i class="fa fa-file-word-o me-1"></i> <?php echo get_string('cal_final_download', 'local_chatbot'); ?></a></div>
                </div>
                <?php // El modulo se usa una y otra vez: se puede volver a las etapas y evaluar otra implementacion. ?>
                <div class="card border-0 bg-light mb-4">
                    <div class="card-body">
                        <p class="mb-2"><?php echo get_string('cal_another', 'local_chatbot'); ?></p>
                        <a class="btn btn-outline-primary" href="<?php echo (new moodle_url('/local/chatbot/ficha_implementacion.php',
                            ['courseid' => $courseid], 'evaluaciones'))->out(false); ?>"><i class="fa fa-plus me-1"></i><?php
                            echo get_string('cal_go_evaluations', 'local_chatbot'); ?></a>
                    </div>
                </div>
            <?php endif; ?>

        <?php elseif ($generando): ?>
            <div class="card shadow-sm border-0 mb-4" id="calidad-generando" role="status"
                 data-id="<?php echo (int) $fila->id; ?>" data-estado="<?php echo s($fila->generacion); ?>"
                 data-url="<?php echo s((new moodle_url('/local/chatbot/calidad.php', ['courseid' => $courseid]))->out(false)); ?>"
                 data-sesskey="<?php echo sesskey(); ?>"
                 data-volver="<?php echo s((new moodle_url($pageurl, ['g' => time()], 'producto'))->out(false)); ?>">
                <div class="card-body">
                    <h5 class="mb-2"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?php
                        echo get_string('diseno_generating', 'local_chatbot'); ?></h5>
                    <p class="small text-muted mb-0"><?php echo get_string('diseno_generating_long', 'local_chatbot'); ?></p>
                </div>
            </div>
        <?php else: ?>
            <?php if ($fila && $fila->generacion === implementacion::ERROR): ?>
                <div class="alert alert-warning"><?php
                    echo get_string('impl_error_' . ($fila->error ?: 'servicio'), 'local_chatbot') . ' '
                        . get_string('diseno_error_retry', 'local_chatbot'); ?></div>
            <?php endif; ?>

            <?php if (!$hayproducto): ?>
                <form method="post" action="<?php echo $pageurl->out(false); ?>" class="mb-4">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <p><?php echo get_string('diseno_generate_help', 'local_chatbot'); ?></p>
                    <button type="submit" name="action" value="generar" class="btn btn-primary px-4 py-2"
                            data-espera="<?php echo s(get_string('diseno_generating', 'local_chatbot')); ?>">
                        <i class="fa fa-magic me-1"></i> <?php echo get_string('diseno_generate', 'local_chatbot'); ?>
                    </button>
                </form>
            <?php else: ?>
                <?php
                $producto = diseno::normalize_product((string) $fila->contenido);
                $decision = $etapa === 3 ? calidad::decision_info($fila) : null;
                $tiempos = ($etapa === 4 && $analisis) ? calidad::time_check($producto, $restante) : null; ?>
                <form method="post" action="<?php echo $pageurl->out(false); ?>" id="calidad-form">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <h5 class="mb-0"><?php echo get_string('diseno_product', 'local_chatbot'); ?></h5>
                        <?php if ($aceptada): ?>
                            <span class="badge text-bg-success"><?php echo get_string('diseno_status_accepted', 'local_chatbot',
                                userdate($fila->timeaccepted, get_string('strftimedatetimeshort', 'langconfig'))); ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-warning"><?php echo get_string('diseno_status_draft', 'local_chatbot'); ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="small text-muted"><?php echo get_string('diseno_edit_help', 'local_chatbot'); ?></p>

                    <?php // Etapa 4: la suma de los tiempos no puede superar el tiempo restante. ?>
                    <?php if ($tiempos && $tiempos['estado'] !== 'sin_datos'): ?>
                        <?php if ($tiempos['estado'] === 'excede'): ?>
                            <div class="alert alert-danger small">
                                <?php echo get_string('cal_time_exceeds', 'local_chatbot', (object) ['suma' => $tiempos['suma'],
                                    'restante' => max(0, (int) $restante)]); ?>
                                <div class="mt-2"><button type="submit" name="action" value="completar" class="btn btn-sm chatbot-gatebtn"
                                        data-espera="<?php echo s(get_string('diseno_generating', 'local_chatbot')); ?>">
                                    <i class="fa fa-clock-o me-1"></i> <?php echo get_string('cal_time_request', 'local_chatbot'); ?></button></div>
                            </div>
                        <?php elseif ($tiempos['estado'] === 'ok'): ?>
                            <p class="small text-success mb-2"><i class="fa fa-check-circle me-1"></i><?php
                                echo get_string('cal_time_ok', 'local_chatbot', (object) ['suma' => $tiempos['suma'],
                                    'restante' => max(0, (int) $restante)]); ?>
                                <?php if ($tiempos['reparto']): ?><span class="text-muted"> ·
                                    <?php echo get_string('cal_time_split', 'local_chatbot', (object) [
                                        'alta' => (int) ($tiempos['reparto']['alta'] ?? 0), 'media' => (int) ($tiempos['reparto']['media'] ?? 0),
                                        'baja' => (int) ($tiempos['reparto']['baja'] ?? 0)]); ?></span><?php endif; ?></p>
                        <?php endif; ?>
                    <?php elseif ($tiempos): ?>
                        <p class="small text-muted mb-2"><?php echo get_string('cal_time_none', 'local_chatbot'); ?></p>
                    <?php endif; ?>

                    <?php // Revision automatica: lo que pide la etapa y no aparece en el producto. ?>
                    <?php if (\local_chatbot\ux::on('revisioncontenido')):
                        $faltan = calidad::missing_items($etapa, $producto, $ajuste); ?>
                        <?php if ($faltan): ?>
                            <div class="alert alert-warning small">
                                <?php echo get_string('diseno_check_missing', 'local_chatbot'); ?>
                                <ul class="mb-2 mt-1"><?php foreach ($faltan as $falta): ?><li><?php echo s($falta); ?></li><?php endforeach; ?></ul>
                                <button type="submit" name="action" value="completar" class="btn btn-sm chatbot-gatebtn"
                                        data-espera="<?php echo s(get_string('diseno_generating', 'local_chatbot')); ?>">
                                    <i class="fa fa-plus-circle me-1"></i> <?php echo get_string('diseno_check_request', 'local_chatbot'); ?>
                                </button>
                            </div>
                        <?php else: ?>
                            <p class="small text-success mb-2"><i class="fa fa-check-circle me-1"></i><?php
                                echo get_string('diseno_check_ok', 'local_chatbot'); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>

                    <textarea id="calidad-contenido" name="contenido" rows="24" class="form-control mb-3"><?php
                        echo s($producto); ?></textarea>

                    <?php // Etapa 3: el tipo de ajuste decide lo que sigue; la regla de decision es del cliente. ?>
                    <?php if ($decision && $analisis):
                        $minimo = $analisis['decision']['minimo'];
                        $permitidos = calidad::allowed_adjustments($minimo); ?>
                        <div class="card border mb-3" id="ajuste">
                            <div class="card-body">
                                <h6 class="mb-2"><?php echo get_string('cal_adjustment_title', 'local_chatbot'); ?></h6>
                                <p class="small mb-1"><?php echo get_string('cal_adjustment_min', 'local_chatbot', s(calidad::adjustment_name($minimo))); ?></p>
                                <p class="small mb-2"><?php echo $decision['ajuste'] === null
                                    ? get_string('cal_adjustment_none', 'local_chatbot')
                                    : get_string($decision['manual'] ? 'cal_adjustment_manual' : 'cal_adjustment_ai', 'local_chatbot',
                                        s(calidad::adjustment_name($decision['ajuste']))); ?></p>
                                <?php if ($decision['aviso'] === 'menos_exigente'): ?>
                                    <div class="alert alert-warning small"><?php echo get_string('cal_adjustment_warn_less', 'local_chatbot'); ?></div>
                                <?php elseif ($decision['aviso'] === 'sin_marca' && $decision['ajuste'] === null): ?>
                                    <div class="alert alert-warning small"><?php echo get_string('cal_adjustment_warn_none', 'local_chatbot'); ?></div>
                                <?php endif; ?>
                                <div class="d-flex flex-wrap align-items-center gap-3">
                                    <?php foreach ($permitidos as $opcion): ?>
                                        <div class="form-check mb-0">
                                            <input class="form-check-input" type="radio" name="ajuste" id="f_ajuste_<?php echo $opcion; ?>"
                                                   value="<?php echo $opcion; ?>"<?php echo $decision['ajuste'] === $opcion ? ' checked' : ''; ?>>
                                            <label class="form-check-label" for="f_ajuste_<?php echo $opcion; ?>"><?php
                                                echo s(calidad::adjustment_name($opcion)); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                    <button type="submit" name="action" value="ajuste" class="btn btn-sm btn-outline-primary"
                                            data-espera="<?php echo s(get_string('exante_saving', 'local_chatbot')); ?>"><?php
                                        echo get_string('cal_adjustment_save', 'local_chatbot'); ?></button>
                                </div>
                                <p class="small text-muted mt-2 mb-0"><?php echo get_string('cal_adjustment_help', 'local_chatbot'); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <?php if (!$aceptada): ?>
                            <button type="submit" name="action" value="aceptar" class="btn btn-success px-4"
                                    data-espera="<?php echo s(get_string('exante_saving', 'local_chatbot')); ?>">
                                <i class="fa fa-check me-1"></i> <?php echo get_string('diseno_accept', 'local_chatbot'); ?>
                            </button>
                        <?php endif; ?>
                        <button type="submit" name="action" value="guardar" class="btn btn-outline-primary px-4"
                                data-espera="<?php echo s(get_string('exante_saving', 'local_chatbot')); ?>">
                            <i class="fa fa-save me-1"></i> <?php echo get_string('diseno_save', 'local_chatbot'); ?>
                        </button>
                    </div>

                    <?php // Modo de interaccion del Prompt Maestro: ajustes iterativos antes de avanzar. ?>
                    <div class="card border-0 bg-light mb-4">
                        <div class="card-body">
                            <label class="form-label fw-bold" for="calidad-ajustes"><?php
                                echo get_string('diseno_adjust_title', 'local_chatbot'); ?></label>
                            <p class="small text-muted mb-2"><?php echo get_string('diseno_adjust_help', 'local_chatbot'); ?></p>
                            <textarea id="calidad-ajustes" name="ajustes" rows="3" class="form-control mb-2" maxlength="4000"></textarea>
                            <button type="submit" name="action" value="ajustar" class="btn btn-outline-secondary"
                                    data-espera="<?php echo s(get_string('diseno_generating', 'local_chatbot')); ?>">
                                <i class="fa fa-commenting-o me-1"></i> <?php echo get_string('diseno_adjust', 'local_chatbot'); ?>
                            </button>
                        </div>
                    </div>
                </form>

                <p class="mb-4">
                    <a class="btn btn-outline-dark" href="<?php echo (new moodle_url($pageurl, ['action' => 'word']))->out(false); ?>">
                        <i class="fa fa-file-word-o me-1"></i> <?php echo get_string('diseno_word', 'local_chatbot'); ?>
                    </a>
                    <span class="small text-muted ms-2"><?php echo get_string('diseno_word_help', 'local_chatbot'); ?></span>
                </p>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php // Al termino de cada pagina: regresar al curso y avanzar a la etapa siguiente. ?>
    <div class="d-flex flex-wrap gap-2 mb-4 border-top pt-3">
        <a class="btn btn-outline-secondary" href="<?php echo $urlfase($fase)->out(false); ?>"><?php
            echo get_string('diseno_back', 'local_chatbot'); ?></a>
        <?php if (isset($etapas[$etapa + 1]) && ($etapa + 1 <= calidad::CONSOLIDACION)):
            $siguiente = new moodle_url($pageurl, ['etapa' => $etapa + 1]); ?>
            <?php if ($aceptada && (calidad::phase_of($etapa + 1) === $fase)): ?>
                <a class="chatbot-backbtn chatbot-nextphase" href="<?php echo $siguiente->out(false); ?>"><?php
                    echo get_string('diseno_next', 'local_chatbot', $etapas[$etapa + 1]['titulo']); ?>
                    <span class="chatbot-backbtn-arrow" aria-hidden="true">&rarr;</span></a>
            <?php elseif (calidad::phase_of($etapa + 1) === $fase): ?>
                <span class="btn btn-secondary disabled" aria-disabled="true" title="<?php
                    echo s(get_string('diseno_next_locked', 'local_chatbot')); ?>"><?php
                    echo get_string('diseno_next', 'local_chatbot', $etapas[$etapa + 1]['titulo']); ?></span>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($fase === 2 && $etapa === 3 && $aceptada && $ajuste !== null): ?>
            <a class="chatbot-backbtn chatbot-nextphase" href="<?php echo (new moodle_url($pageurl, ['etapa' => 4]))->out(false); ?>"><?php
                echo get_string('cal_next_fase3', 'local_chatbot'); ?>
                <span class="chatbot-backbtn-arrow" aria-hidden="true">&rarr;</span></a>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    var caja = document.getElementById('calidad-generando');
    if (!caja) {
        return;
    }
    var id = caja.getAttribute('data-id');
    var url = caja.getAttribute('data-url');
    var terminar = function(datos) {
        if (datos && (datos.estado === 'listo' || datos.estado === 'error')) {
            window.location.replace(caja.getAttribute('data-volver'));
            return true;
        }
        return false;
    };
    var revisar = function() {
        fetch(url + '&action=estado&id=' + encodeURIComponent(id), {credentials: 'same-origin'})
            .then(function(r) { return r.json(); })
            .then(function(datos) {
                if (!terminar(datos)) {
                    window.setTimeout(revisar, 4000);
                }
            })
            .catch(function() { window.setTimeout(revisar, 6000); });
    };
    if (caja.getAttribute('data-estado') === 'pendiente') {
        var cuerpo = new URLSearchParams();
        cuerpo.append('action', 'ejecutar');
        cuerpo.append('id', id);
        cuerpo.append('sesskey', caja.getAttribute('data-sesskey'));
        // Si el servidor web corta esta llamada, la generacion sigue y la consulta de estado la recoge.
        fetch(url, {method: 'POST', credentials: 'same-origin', body: cuerpo})
            .then(function(r) { return r.json(); })
            .then(function(datos) {
                if (!terminar(datos)) {
                    window.setTimeout(revisar, 4000);
                }
            })
            .catch(function() { window.setTimeout(revisar, 4000); });
    } else {
        window.setTimeout(revisar, 4000);
    }
})();
</script>

<?php
echo $OUTPUT->footer();
