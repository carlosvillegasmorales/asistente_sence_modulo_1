<?php
/**
 * Modulo 3, Fase 4 (evaluacion ex post), etapas 11 a 15: hoja de trabajo de cada etapa del cierre del ciclo formativo.
 *
 * «Prompt Complementario Fase 4 - Evaluacion Ex Post» (Interfases, 07-11-2026): resultados del ciclo (11), analisis metacognitivo del
 * proceso (12), sistematizacion de practicas exitosas (13), recomendaciones para una nueva ejecucion (14) y consolidacion en un
 * documento Word unico (15). El asistente propone el producto de la etapa a partir de la ficha ex post validada (ficha_expost.php),
 * de los productos de las Fases 1 a 3 y de los resultados que calcula la plataforma; la persona lo edita, pide ajustes y lo acepta, y
 * no se avanza sin aceptar la etapa anterior. La logica vive en classes/expost.php; la generacion y la aceptacion usan las mismas
 * filas y metodos que calidad.php (local_chatbot_calidad, etapas 11 a 15).
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/editorlib.php');

use local_chatbot\calidad;
use local_chatbot\diseno;
use local_chatbot\exante;
use local_chatbot\expost;
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
$PAGE->set_title(get_string('exp_title', 'local_chatbot'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js(new moodle_url('/local/chatbot/js/espera.js'), true);

if (!\local_chatbot\ux::on('fichaexpost')) {
    $PAGE->set_url(new moodle_url('/local/chatbot/expost.php', ['courseid' => $courseid]));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('exp_disabled', 'local_chatbot'), 'info');
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
        core_php_time_limit::raise(calidad::CONSOLIDACION_MAX);
        $estado = calidad::run($id, $USER->id);
    } else {
        // Da por caida la generacion que lleva demasiado tiempo.
        $impl0 = implementacion::get_ficha($USER->id, $courseid);
        if ($impl0) {
            calidad::get_by_impl((int) $impl0->id);
        }
        $estado = $DB->get_field('local_chatbot_calidad', 'generacion', ['id' => $id, 'userid' => $USER->id]);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['estado' => $estado ?: implementacion::ERROR]);
    exit;
}

$s = expost::load($USER->id, $courseid);
$impl = $s['impl'];
$row = $s['row'];
$val = $s['val'];
$filas = $s['filas'];
$aceptadas = $s['aceptadas'];
$huellaval = $val ? (string) $val->huella : '';
$etapas = expost::todas();
if (!isset($etapas[$etapa])) {
    $etapa = expost::current_stage($aceptadas);
}
$pageurl = new moodle_url('/local/chatbot/expost.php', ['courseid' => $courseid, 'etapa' => $etapa]);
$PAGE->set_url($pageurl);
$PAGE->navbar->add(get_string('exp_title', 'local_chatbot'));
$consolida = $etapa === expost::CONSOLIDACION;
// Abierta: Fases 1 a 3 consolidadas, continuidad confirmada, ficha ex post validada y vigente y etapas anteriores aceptadas.
$abierta = $impl && $s['activacion'] === null && expost::can_open($etapa, $s['ok'], $aceptadas);
$fila = $filas[$etapa] ?? null;
$snap = ($val && $val->snapshot) ? (array) json_decode((string) $val->snapshot, true) : [];
$pre = (array) ($snap['pre'] ?? []);
$data = $pre ? expost::clean((array) ($snap['data'] ?? [])) : [];
$resultados = $pre ? expost::results($data, $pre) : null;

// --- Descarga en Word de una etapa ---
if ($action === 'word' && $abierta && !$consolida && $fila && $fila->contenido) {
    $ruta = make_request_directory() . '/producto.docx';
    $titulo = 'Etapa ' . $etapa . ': ' . $etapas[$etapa]['titulo'];
    $subtitulo = format_string($course->fullname) . ' · ' . userdate(time(), get_string('strftimedate', 'langconfig'));
    $producto = diseno::normalize_product((string) $fila->contenido);
    $conformato = \local_chatbot\ux::on('docetapa') && \local_chatbot\documento_final::build($ruta,
        ['portada' => false, 'encabezado' => $titulo . ' · ' . $subtitulo], diseno::stage_document_html($titulo, $producto),
        $CFG->dirroot . '/local/chatbot/pix/logo_interfases.png');
    if ($conformato || diseno::html_to_docx($ruta, $titulo, $subtitulo, $producto)) {
        send_file($ruta, $etapas[$etapa]['archivo'] . '.docx', 0, 0, false, true,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
    throw new moodle_exception('diseno_word_error', 'local_chatbot');
}

// --- Documento final de la Fase 4: la etapa 10 y las etapas 11 a 14 consolidadas ---
if ($action === 'final' && $impl && $row && $val && in_array(expost::CONSOLIDACION, $aceptadas, true)) {
    $ruta = make_request_directory() . '/final.docx';
    $partes = [10 => expost::validation_html((string) $val->condicion, (array) $val->informe)];
    foreach (expost::ETAPAS as $n) {
        $partes[$n] = (string) ($filas[$n]->contenido ?? '');
    }
    $html = diseno::normalize_product(diseno::complete_consolidation((string) $filas[expost::CONSOLIDACION]->contenido, $partes, 14));
    $fecha = userdate($filas[expost::CONSOLIDACION]->timeaccepted, get_string('strftimedate', 'langconfig'));
    $portada = expost::final_cover($pre, $data, $fecha);
    $conformato = \local_chatbot\documento_final::build($ruta, $portada, $html, $CFG->dirroot . '/local/chatbot/pix/logo_interfases.png');
    if ($conformato || diseno::html_to_docx($ruta, $portada['titulo'], $portada['subtitulo'], $html)) {
        send_file($ruta, expost::etapa_consolidacion()['archivo'] . '.docx', 0, 0, false, true,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
    throw new moodle_exception('diseno_word_error', 'local_chatbot');
}

// --- Etapa 15: pedir la consolidacion al asistente o confirmarla ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $abierta && $consolida && in_array($action, ['confirmar', 'consolidar'], true)) {
    require_sesskey();
    $destino = new moodle_url($pageurl, [], 'producto');
    if ($action === 'consolidar' && \local_chatbot\ux::on('consolidacionm3')) {
        if (!\local_chatbot\ai\provider_factory::is_configured()) {
            redirect($destino, get_string('impl_error_sinclave', 'local_chatbot'), null, \core\output\notification::NOTIFY_ERROR);
        }
        calidad::request($USER->id, $courseid, expost::CONSOLIDACION, (int) $val->id);
        redirect($destino);
    }
    if ($action === 'confirmar') {
        $contenidos = [];
        foreach (expost::ETAPAS as $n) {
            $contenidos[$n] = (string) $filas[$n]->contenido;
        }
        // Se confirma lo que se muestra: la consolidacion del asistente si esta al dia, o la completa por codigo.
        $etapa10 = expost::validation_html((string) $val->condicion, (array) $val->informe);
        $huellaetapas = expost::stages_fingerprint($filas, $huellaval);
        [$html, $porasistente] = expost::consolidation_shown($filas[expost::CONSOLIDACION] ?? null, $etapa10, $contenidos, $huellaetapas);
        expost::confirm_consolidation($impl, $html, $huellaetapas,
            $porasistente ? (string) $filas[expost::CONSOLIDACION]->promptversion : null);
        redirect($destino, get_string('exp_confirmed', 'local_chatbot'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($destino);
}

// --- Acciones del formulario de una etapa ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $abierta && !$consolida
        && in_array($action, ['generar', 'ajustar', 'completar', 'guardar', 'aceptar'], true)) {
    require_sesskey();
    $destino = new moodle_url($pageurl, [], 'producto');
    if ($action === 'guardar' || $action === 'aceptar') {
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
        $faltan = expost::missing_items($etapa, $producto);
        $ajustes = $faltan ? get_string('diseno_check_prompt', 'local_chatbot', implode('; ', $faltan)) : '';
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
    calidad::request($USER->id, $courseid, $etapa, (int) $val->id, $ajustes);
    redirect($destino);
}

// Secciones del curso: la Fase 4 (para volver).
$nombres = [];
foreach (get_fast_modinfo($course)->get_section_info_all() as $seccion) {
    $nombres[$seccion->section] = get_section_name($course, $seccion);
}
$urlcurso = new moodle_url('/course/view.php', ['id' => $courseid]);
$num4 = exante::phase_section($nombres, 4);
$urlfase4 = ($num4 !== null ? course_get_url($course, $num4) : null) ?: $urlcurso;
$generando = $fila && in_array($fila->generacion, [implementacion::PENDIENTE, implementacion::PROCESANDO], true);
$hayproducto = $fila && trim(strip_tags((string) $fila->contenido)) !== '';
$aceptada = $consolida ? in_array($etapa, $aceptadas, true) : ($fila && $fila->estado === calidad::ACEPTADO && $hayproducto);

if ($hayproducto && !$generando && !$consolida) {
    editors_get_preferred_editor(FORMAT_HTML)->use_editor('expost-contenido',
        ['context' => $context, 'autosave' => false, 'enable_filemanagement' => false]);
}

/**
 * Los resultados que calcula la plataforma, en tablas (etapas 11 a 14).
 *
 * @param array $r expost::results().
 * @return void
 */
function local_chatbot_expost_datos(array $r): void {
    $pc = static function(?float $v): string {
        return $v === null ? '—' : s(implementacion::pct($v));
    };
    ?>
    <details class="card border-0 bg-light mb-3" open>
        <summary class="card-body py-2 fw-bold" style="cursor: pointer;"><?php echo get_string('exp_data_title', 'local_chatbot'); ?></summary>
        <div class="card-body pt-0">
            <p class="small text-muted"><?php echo get_string('exp_data_help', 'local_chatbot'); ?></p>
            <div class="row g-2 mb-3 text-center">
                <?php foreach ([['exp_data_avg_ini', $pc($r['promedio_inicial'])], ['exp_data_avg_fin', $pc($r['promedio_final'])],
                        ['exp_data_avg_pend', $pc($r['promedio_pendientes'])], ['exp_data_cov_ini', $pc($r['cobertura_inicial'])],
                        ['exp_data_cov_fin', $pc($r['cobertura_final'])]] as [$clave, $valor]): ?>
                    <div class="col-6 col-md"><div class="border rounded p-2 bg-white"><div class="small text-muted"><?php
                        echo get_string($clave, 'local_chatbot'); ?></div><div class="fw-bold fs-5"><?php echo $valor; ?></div></div></div>
                <?php endforeach; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-2 bg-white small">
                    <thead><tr><th><?php echo get_string('cal_data_criterion', 'local_chatbot'); ?></th>
                        <th class="text-center"><?php echo get_string('exp_data_initial', 'local_chatbot'); ?></th>
                        <th class="text-center"><?php echo get_string('exp_data_final', 'local_chatbot'); ?></th>
                        <th class="text-center"><?php echo get_string('exp_data_change', 'local_chatbot'); ?></th>
                        <th class="text-center"><?php echo get_string('exp_data_leveling', 'local_chatbot'); ?></th>
                        <th class="text-center"><?php echo get_string('exp_data_deepening', 'local_chatbot'); ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($r['filas'] as $f): ?>
                        <tr><td><?php echo s($f['k'] . '. ' . $f['criterio']); ?></td>
                            <?php if ($f['tipo'] === 'pendiente_persiste'): ?>
                                <td colspan="5" class="text-center text-muted"><?php echo get_string('exp_data_persists', 'local_chatbot',
                                    s(\local_chatbot\expost::answers()[$f['pr']] ?? '—')); ?></td>
                            <?php else: ?>
                                <td class="text-center"><?php echo $pc($f['inicial']); ?></td>
                                <td class="text-center fw-bold"><?php echo $pc($f['final']); ?></td>
                                <td class="text-center"><?php echo $f['variacion'] === null ? '—' : s(($f['variacion'] > 0 ? '+' : '')
                                    . str_replace('.', ',', (string) $f['variacion'])) . ' pp'; ?></td>
                                <td class="text-center"><?php echo $f['nivelacion'] === null ? '—' : (int) $f['nivelacion']['mov'] . ' / '
                                    . (int) $f['nivelacion']['tot']; ?></td>
                                <td class="text-center"><?php echo $f['profundizacion'] === null ? '—' : (int) $f['profundizacion']; ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small mb-1"><?php echo get_string('exp_data_totals', 'local_chatbot', (object) ['mov' => $r['nivelacion']['mov'],
                'tot' => $r['nivelacion']['tot'], 'prof' => $r['profundizacion']]); ?></p>
            <p class="small text-muted mb-0"><?php echo get_string('exp_data_estimate', 'local_chatbot'); ?>
                <?php echo $r['aviso'] !== null ? s($r['aviso']) : ''; ?></p>
        </div>
    </details>
    <?php
}

echo $OUTPUT->header();
?>

<div class="container-fluid" style="max-width: 980px;">

    <h3 class="mb-1"><?php echo get_string('exp_title', 'local_chatbot'); ?></h3>
    <p class="text-muted mb-3"><?php echo get_string('exp_subtitle', 'local_chatbot'); ?></p>

    <?php echo \local_chatbot\ruta_m3::render($USER->id, $courseid, 4, $etapa); ?>

    <?php // Las etapas de la fase, con su estado: la persona sabe donde esta y que falta. ?>
    <ol class="chatbot-diseno-etapas list-unstyled d-flex flex-wrap gap-2 mb-4">
        <li class="chatbot-diseno-etapa chatbot-diseno-<?php echo $s['ok'] ? 'hecha' : 'abierta'; ?>">
            <a href="<?php echo (new moodle_url('/local/chatbot/ficha_expost.php', ['courseid' => $courseid]))->out(false); ?>"><?php
                echo ($s['ok'] ? '✔ ' : '9-10. ') . s(get_string('exp_stage_ficha', 'local_chatbot')); ?></a>
        </li>
        <?php foreach ($etapas as $n => $e):
            $ok = in_array($n, $aceptadas, true);
            $puede = $impl && $s['activacion'] === null && expost::can_open($n, $s['ok'], $aceptadas);
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

    <?php if (!$impl || $s['activacion'] !== null): ?>
        <div class="alert alert-warning">
            <?php echo s(expost::ACTIVACION_MENSAJE); ?>
            <div class="mt-2"><a class="chatbot-gatebtn" href="<?php echo (new moodle_url('/local/chatbot/ficha_expost.php',
                ['courseid' => $courseid]))->out(false); ?>"><?php echo get_string('exp_go_ficha', 'local_chatbot'); ?></a></div>
        </div>
    <?php elseif (!$s['ok']): ?>
        <div class="alert alert-warning">
            <?php echo get_string('exp_needs_ficha', 'local_chatbot'); ?>
            <div class="mt-2"><a class="chatbot-gatebtn" href="<?php echo (new moodle_url('/local/chatbot/ficha_expost.php',
                ['courseid' => $courseid]))->out(false); ?>"><?php echo get_string('exp_go_ficha', 'local_chatbot'); ?></a></div>
        </div>
    <?php elseif (!$abierta): ?>
        <div class="alert alert-info">
            <?php echo get_string('diseno_locked', 'local_chatbot', $etapa - 1); ?>
            <div class="mt-2"><a class="chatbot-gatebtn" href="<?php echo (new moodle_url($pageurl,
                ['etapa' => expost::current_stage($aceptadas)]))->out(false); ?>"><?php echo get_string('diseno_go_current', 'local_chatbot'); ?></a></div>
        </div>
    <?php else: ?>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <h4 class="mb-2"><?php echo get_string('diseno_stage', 'local_chatbot', (object) ['n' => $etapa, 'titulo' => $etapas[$etapa]['titulo']]); ?></h4>
                <p class="small text-muted mb-1"><?php echo get_string('diseno_includes', 'local_chatbot'); ?></p>
                <ul class="small mb-2">
                    <?php foreach ($etapas[$etapa]['incluye'] as $item): ?><li><?php echo s($item); ?></li><?php endforeach; ?>
                </ul>
                <?php if (($etapas[$etapa]['nota'] ?? '') !== ''): ?>
                    <p class="small text-muted mb-0"><?php echo s($etapas[$etapa]['nota']); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($resultados && !$consolida): ?>
            <?php local_chatbot_expost_datos($resultados); ?>
        <?php endif; ?>

        <div id="producto">
        <?php if ($consolida): ?>
            <?php
            $vigente = $aceptada;
            $contenidos = [];
            foreach (expost::ETAPAS as $n) {
                $contenidos[$n] = (string) ($filas[$n]->contenido ?? '');
            }
            $etapa10 = expost::validation_html((string) $val->condicion, (array) $val->informe);
            // Lo que se muestra corresponde siempre a las etapas de hoy: la consolidacion del asistente (sin repetir campos comunes)
            // si esta al dia, o la completa por codigo. Si se confirma, eso queda guardado.
            [$documento, $porasistente] = expost::consolidation_shown($fila, $etapa10, $contenidos, expost::stages_fingerprint($filas, $huellaval));
            $consolidaia = \local_chatbot\ux::on('consolidacionm3');
            $enlacesetapas = [];
            foreach (expost::etapas() as $n => $e) {
                $enlacesetapas[] = html_writer::link(new moodle_url($pageurl, ['etapa' => $n]), $n . '. ' . s($e['titulo']));
            } ?>
            <?php if ($fila && $fila->estado === calidad::ACEPTADO && !$vigente): ?>
                <div class="alert alert-warning"><?php echo get_string('exp_consolidation_stale', 'local_chatbot'); ?></div>
            <?php endif; ?>
            <?php if ($generando): ?>
                <div class="card shadow-sm border-0 mb-4" id="expost-generando" role="status" data-id="<?php echo (int) $fila->id; ?>"
                     data-estado="<?php echo s($fila->generacion); ?>"
                     data-url="<?php echo s((new moodle_url('/local/chatbot/expost.php', ['courseid' => $courseid]))->out(false)); ?>"
                     data-sesskey="<?php echo sesskey(); ?>"
                     data-volver="<?php echo s((new moodle_url($pageurl, ['g' => time()], 'producto'))->out(false)); ?>">
                    <div class="card-body">
                        <h5 class="mb-2"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?php
                            echo get_string('exp_consolidating', 'local_chatbot'); ?></h5>
                        <p class="small text-muted mb-0"><?php echo get_string('diseno_consolidating_long', 'local_chatbot'); ?></p>
                    </div>
                </div>
            <?php elseif (!$vigente && $fila && $fila->generacion === implementacion::ERROR): ?>
                <div class="alert alert-warning"><?php echo get_string('impl_error_' . ($fila->error ?: 'servicio'), 'local_chatbot'); ?></div>
            <?php endif; ?>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h5 class="mb-0"><?php echo get_string('exp_consolidated', 'local_chatbot'); ?></h5>
                <div class="d-flex flex-wrap gap-1">
                    <?php if ($porasistente): ?><span class="badge text-bg-info"><?php echo get_string('diseno_consolidated_ai', 'local_chatbot'); ?></span><?php endif; ?>
                    <?php if ($vigente): ?>
                        <span class="badge text-bg-success"><?php echo get_string('diseno_status_confirmed', 'local_chatbot',
                            userdate($fila->timeaccepted, get_string('strftimedatetimeshort', 'langconfig'))); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="alert alert-info small">
                <?php echo get_string('exp_consolidated_help', 'local_chatbot'); ?>
                <div class="mt-1"><?php echo implode(' · ', $enlacesetapas); ?></div>
            </div>
            <div class="card border mb-3">
                <div class="card-body chatbot-diseno-doc" style="max-height: 640px; overflow-y: auto;">
                    <?php echo format_text(diseno::normalize_product($documento), FORMAT_HTML, ['context' => $context]); ?>
                </div>
            </div>
            <?php $faltan15 = \local_chatbot\ux::on('revisioncontenido') ? expost::missing_items(expost::CONSOLIDACION, diseno::normalize_product($documento)) : [];
            if ($faltan15): ?>
                <div class="alert alert-warning small">
                    <?php echo get_string('cal_check_missing_consolidation', 'local_chatbot'); ?>
                    <ul class="mb-0 mt-1"><?php foreach ($faltan15 as $falta): ?><li><?php echo s($falta); ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
            <?php if (!$vigente && !$generando): ?>
                <form method="post" action="<?php echo $pageurl->out(false); ?>" class="mb-4 d-flex flex-wrap gap-2">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <?php if ($consolidaia && (!$porasistente || $faltan15)): ?>
                        <button type="submit" name="action" value="consolidar" class="btn btn-primary px-4"
                                data-espera="<?php echo s(get_string('exp_consolidating', 'local_chatbot')); ?>">
                            <i class="fa fa-magic me-1"></i> <?php echo get_string('diseno_consolidate_ai', 'local_chatbot'); ?>
                        </button>
                    <?php endif; ?>
                    <button type="submit" name="action" value="confirmar" class="btn btn-success px-4"
                            data-espera="<?php echo s(get_string('exante_saving', 'local_chatbot')); ?>">
                        <i class="fa fa-check me-1"></i> <?php echo get_string('exp_confirm', 'local_chatbot'); ?>
                    </button>
                </form>
                <?php if ($consolidaia && !$porasistente): ?>
                    <p class="small text-muted"><?php echo get_string('cal_consolidate_ai_help', 'local_chatbot'); ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($vigente): ?>
                <div class="alert alert-success">
                    <strong><?php echo get_string('exp_done', 'local_chatbot'); ?></strong>
                    <div class="mt-2"><a class="chatbot-gatebtn" href="<?php echo (new moodle_url($pageurl, ['action' => 'final']))->out(false); ?>">
                        <i class="fa fa-file-word-o me-1"></i> <?php echo get_string('exp_final_download', 'local_chatbot'); ?></a></div>
                </div>
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
            <div class="card shadow-sm border-0 mb-4" id="expost-generando" role="status" data-id="<?php echo (int) $fila->id; ?>"
                 data-estado="<?php echo s($fila->generacion); ?>"
                 data-url="<?php echo s((new moodle_url('/local/chatbot/expost.php', ['courseid' => $courseid]))->out(false)); ?>"
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
                <div class="alert alert-warning"><?php echo get_string('impl_error_' . ($fila->error ?: 'servicio'), 'local_chatbot') . ' '
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
                <?php $producto = diseno::normalize_product((string) $fila->contenido); ?>
                <form method="post" action="<?php echo $pageurl->out(false); ?>" id="expost-form">
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

                    <?php // Revision automatica: lo que pide la etapa y no aparece en el producto. ?>
                    <?php if (\local_chatbot\ux::on('revisioncontenido')):
                        $faltan = expost::missing_items($etapa, $producto); ?>
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
                            <p class="small text-success mb-2"><i class="fa fa-check-circle me-1"></i><?php echo get_string('diseno_check_ok', 'local_chatbot'); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>

                    <textarea id="expost-contenido" name="contenido" rows="24" class="form-control mb-3"><?php echo s($producto); ?></textarea>

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
                            <label class="form-label fw-bold" for="expost-ajustes"><?php echo get_string('diseno_adjust_title', 'local_chatbot'); ?></label>
                            <p class="small text-muted mb-2"><?php echo get_string('diseno_adjust_help', 'local_chatbot'); ?></p>
                            <textarea id="expost-ajustes" name="ajustes" rows="3" class="form-control mb-2" maxlength="4000"></textarea>
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
        <a class="btn btn-outline-secondary" href="<?php echo $urlfase4->out(false); ?>"><?php echo get_string('diseno_back', 'local_chatbot'); ?></a>
        <?php if (isset($etapas[$etapa + 1])):
            $siguiente = new moodle_url($pageurl, ['etapa' => $etapa + 1]); ?>
            <?php if ($aceptada): ?>
                <a class="chatbot-backbtn chatbot-nextphase" href="<?php echo $siguiente->out(false); ?>"><?php
                    echo get_string('diseno_next', 'local_chatbot', $etapas[$etapa + 1]['titulo']); ?>
                    <span class="chatbot-backbtn-arrow" aria-hidden="true">&rarr;</span></a>
            <?php else: ?>
                <span class="btn btn-secondary disabled" aria-disabled="true" title="<?php echo s(get_string('diseno_next_locked', 'local_chatbot')); ?>"><?php
                    echo get_string('diseno_next', 'local_chatbot', $etapas[$etapa + 1]['titulo']); ?></span>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    var caja = document.getElementById('expost-generando');
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
            .then(function(datos) { if (!terminar(datos)) { window.setTimeout(revisar, 4000); } })
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
            .then(function(datos) { if (!terminar(datos)) { window.setTimeout(revisar, 4000); } })
            .catch(function() { window.setTimeout(revisar, 4000); });
    } else {
        window.setTimeout(revisar, 4000);
    }
})();
</script>

<?php
echo $OUTPUT->footer();
