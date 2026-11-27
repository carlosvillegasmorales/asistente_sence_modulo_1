<?php
/**
 * Modulo 2, Centro de produccion de recursos de aprendizaje (Etapas 9 a 12).
 *
 * Funciona de manera independiente al proceso de diseno y validacion: despliega los disenos validados (>= 25 pts) de
 * la persona facilitadora y permite producir uno a la vez los materiales especificados en la Etapa 4, en Modo A
 * (texto/HTML Moodle), Modo B (Word, Excel, PPTX, PDF A4/A3/A2/tarjeta, GIFT + Google Forms) y Modo C (curaduria con
 * enlaces verificados). La logica pura y de BD vive en classes/centro_recursos.php y classes/recurso_export.php.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/editorlib.php');

use local_chatbot\centro_recursos;
use local_chatbot\diseno;
use local_chatbot\exante;
use local_chatbot\recurso_export;

$courseid = required_param('courseid', PARAM_INT);
if ((int) $courseid === (int) SITEID) {
    throw new moodle_exception('invalidcourseid');
}
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/chatbot:use', $context);

$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('centro_title', 'local_chatbot'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js(new moodle_url('/local/chatbot/js/espera.js'), true);

if (!\local_chatbot\ux::on('centrorecursos')) {
    $PAGE->set_url(new moodle_url('/local/chatbot/centro_recursos.php', ['courseid' => $courseid]));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('centro_disabled', 'local_chatbot'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$fichaid = optional_param('fichaid', 0, PARAM_INT);
$recursoid = optional_param('recursoid', 0, PARAM_INT);

// --- Llamadas asincronas de ejecucion y consulta de estado ---
if (in_array($action, ['ejecutar_plan', 'estado_plan', 'ejecutar_rec', 'estado_rec'], true)) {
    $id = required_param('id', PARAM_INT);
    if ($action === 'ejecutar_plan' || $action === 'ejecutar_rec') {
        require_sesskey();
        \core\session\manager::write_close();
        ignore_user_abort(true);
        core_php_time_limit::raise(centro_recursos::PROCESO_MAX);
        $estado = $action === 'ejecutar_plan'
            ? centro_recursos::run_plan($id, $USER->id)
            : centro_recursos::run_resource($id, $USER->id);
    } else if ($action === 'estado_plan') {
        $p = $DB->get_record('local_chatbot_recurso_plan', ['id' => $id, 'userid' => $USER->id]);
        if ($p) {
            $p = centro_recursos::get_plan((int) $p->fichaid);
        }
        $estado = $p ? (string) $p->generacion : exante::ERROR;
    } else {
        $r = centro_recursos::get_resource($id, $USER->id);
        $estado = $r ? (string) $r->generacion : exante::ERROR;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['estado' => $estado ?: exante::ERROR]);
    exit;
}

// Disenos validados disponibles (>= 25 pts).
$elegibles = centro_recursos::list_eligible_designs($USER->id, $courseid);
$elegiblesById = [];
foreach ($elegibles as $item) {
    $elegiblesById[$item['fichaid']] = $item;
}
$disenoSel = ($fichaid > 0 && isset($elegiblesById[$fichaid])) ? $elegiblesById[$fichaid] : null;
$baseurl = new moodle_url('/local/chatbot/centro_recursos.php', ['courseid' => $courseid]);
$planurl = $disenoSel ? new moodle_url('/local/chatbot/centro_recursos.php',
    ['courseid' => $courseid, 'fichaid' => $disenoSel['fichaid']]) : $baseurl;
$recursourl = ($disenoSel && $recursoid > 0) ? new moodle_url('/local/chatbot/centro_recursos.php',
    ['courseid' => $courseid, 'fichaid' => $disenoSel['fichaid'], 'recursoid' => $recursoid]) : $planurl;
$PAGE->set_url($recursourl);
$PAGE->navbar->add(get_string('centro_title', 'local_chatbot'));

// --- Descarga de archivo individual de un material ---
if ($action === 'descargar' && $disenoSel && $recursoid > 0) {
    $tipo = required_param('tipo', PARAM_ALPHANUMEXT);
    $rec = centro_recursos::get_resource($recursoid, $USER->id);
    $plan = centro_recursos::get_plan($disenoSel['fichaid']);
    if ($rec && (int) $rec->fichaid === $disenoSel['fichaid'] && trim((string) $rec->contenido) !== '') {
        $dir = make_request_directory();
        $impresion = (string) ($plan->datos_arr['impresion'] ?? 'Carta');
        $logo = $CFG->dirroot . '/local/chatbot/pix/logo_interfases.png';
        $res = centro_recursos::export_material_file($rec, $tipo, $dir, $impresion, $logo);
        if ($res !== null) {
            send_file($res[0], $res[1], 0, 0, false, true, $res[2]);
        }
    }
    throw new moodle_exception('centro_export_error', 'local_chatbot');
}

// --- Descarga del Repertorio completo en .zip (Etapa 12) ---
if ($action === 'descargar_zip' && $disenoSel) {
    $plan = centro_recursos::get_plan($disenoSel['fichaid']);
    $recursos = centro_recursos::get_resources($disenoSel['fichaid']);
    $refs = centro_recursos::list_references($disenoSel['fichaid']);
    $dir = make_request_directory();
    $impresion = (string) ($plan->datos_arr['impresion'] ?? 'Carta');
    $logo = $CFG->dirroot . '/local/chatbot/pix/logo_interfases.png';
    $meta = [
        'experiencia' => $disenoSel['experiencia'],
        'plan' => $disenoSel['plan'],
        'modulo' => $disenoSel['modulo'],
        'organismo' => $plan->datos_arr['organismo'] ?? '',
    ];
    $repHtml = centro_recursos::repertory_html($meta, $recursos, $refs);
    $archivos = [];
    $repDocx = $dir . '/00_Repertorio_de_recursos.docx';
    if (recurso_export::build_docx($repDocx, 'Repertorio de recursos del diseño', $disenoSel['experiencia'], $repHtml, $logo)) {
        $archivos['00_Repertorio_de_recursos.docx'] = ['ruta' => $repDocx];
    }
    $repPdf = $dir . '/00_Repertorio_de_recursos.pdf';
    if (recurso_export::build_pdf($repPdf, 'Repertorio de recursos del diseño', $disenoSel['experiencia'], $repHtml, 'PDF A4', $impresion, $logo)) {
        $archivos['00_Repertorio_de_recursos.pdf'] = ['ruta' => $repPdf];
    }
    $archivos['00_Repertorio_de_recursos.html'] = ['texto' => recurso_export::to_moodle_html($repHtml, 'Repertorio de recursos')];

    foreach ($recursos as $r) {
        if (!in_array($r->estado, [centro_recursos::MAT_ENTREGADO, centro_recursos::MAT_ENTREGADO_PARCIAL], true)
                || trim((string) $r->contenido) === '') {
            continue;
        }
        $fmts = centro_recursos::detect_formats((string) $r->modo, (string) $r->formato, (string) $r->nombre,
            (string) ($r->plataforma_quiz ?? ''));
        foreach ($fmts as $fmt) {
            if ($fmt === 'curaduria') {
                continue;
            }
            $exp = centro_recursos::export_material_file($r, $fmt, $dir, $impresion, $logo);
            if ($exp !== null) {
                $archivos['materiales/' . $exp[1]] = ['ruta' => $exp[0]];
            }
        }
    }
    $zipPath = $dir . '/Repertorio_recursos.zip';
    if (recurso_export::build_repertory_zip($zipPath, $archivos)) {
        send_file($zipPath, 'Repertorio_recursos_M2.zip', 0, 0, false, true, 'application/zip');
    }
    throw new moodle_exception('centro_export_error', 'local_chatbot');
}

// --- Acciones POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $disenoSel) {
    require_sesskey();
    $fid = $disenoSel['fichaid'];

    if ($action === 'generar_plan') {
        centro_recursos::request_plan($fid, $USER->id, $courseid, (array) $disenoSel['ficha']->data);
        redirect($planurl);
    }
    if ($action === 'guardar_datos') {
        centro_recursos::ensure_plan($fid, $USER->id, $courseid, (array) $disenoSel['ficha']->data);
        centro_recursos::save_plan_meta($fid, $USER->id, [
            'organismo' => optional_param('organismo', '', PARAM_TEXT),
            'curso' => optional_param('curso', '', PARAM_TEXT),
            'facilitador' => optional_param('facilitador', '', PARAM_TEXT),
            'impresion' => optional_param('impresion', 'Carta', PARAM_TEXT),
        ]);
        redirect($planurl, get_string('centro_datos_saved', 'local_chatbot'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    // Acciones de un material (Etapas 10 y 11), compartidas con la produccion del Modulo 3.
    \local_chatbot\material_ui::handle_post($action, $recursoid, (int) $USER->id, (int) $courseid, $fid, $recursourl, $planurl,
        centro_recursos::CIERRE_ETAPA_11);
    if ($action === 'cerrar_centro') {
        centro_recursos::close_center($fid, $USER->id);
        redirect($planurl, centro_recursos::CIERRE_ETAPA_12, null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'reabrir_centro') {
        centro_recursos::reopen_center($fid, $USER->id);
        redirect($planurl);
    }
}

echo $OUTPUT->header();
$courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);
$plan = $disenoSel ? centro_recursos::get_plan($disenoSel['fichaid']) : null;
$recursos = $disenoSel ? centro_recursos::get_resources($disenoSel['fichaid']) : [];
$refs = $disenoSel ? centro_recursos::list_references($disenoSel['fichaid']) : [];
$recActual = ($disenoSel && $recursoid > 0) ? centro_recursos::get_resource($recursoid, $USER->id) : null;
if ($recActual && (int) $recActual->fichaid !== $disenoSel['fichaid']) {
    $recActual = null;
}

$estadoEtiqueta = [
    centro_recursos::MAT_PENDIENTE => [get_string('centro_st_pendiente', 'local_chatbot'), 'bg-secondary'],
    centro_recursos::MAT_ESTRUCTURA => [get_string('centro_st_estructura', 'local_chatbot'), 'bg-info text-dark'],
    centro_recursos::MAT_BORRADOR => [get_string('centro_st_borrador', 'local_chatbot'), 'bg-warning text-dark'],
    centro_recursos::MAT_ENTREGADO => [get_string('centro_st_entregado', 'local_chatbot'), 'bg-success'],
    centro_recursos::MAT_ENTREGADO_PARCIAL => [get_string('centro_st_parcial', 'local_chatbot'), 'bg-primary'],
    centro_recursos::MAT_NO_PRODUCIBLE => [get_string('centro_st_noproducible', 'local_chatbot'), 'bg-light text-dark border'],
    centro_recursos::MAT_INSTRUMENTO => [get_string('centro_st_instrumento', 'local_chatbot'), 'bg-light text-dark border'],
];
?>
<div class="container-fluid px-0" style="max-width: 1120px;">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h2 class="mb-1"><?php echo get_string('centro_title', 'local_chatbot'); ?></h2>
            <p class="text-muted mb-0"><?php echo get_string('centro_subtitle', 'local_chatbot'); ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($disenoSel): ?>
                <a class="btn btn-outline-secondary btn-sm" href="<?php echo $baseurl->out(false); ?>">
                    <?php echo get_string('centro_change_design', 'local_chatbot'); ?>
                </a>
            <?php endif; ?>
            <a class="chatbot-backbtn" href="<?php echo $courseurl->out(false); ?>">
                &larr; <?php echo get_string('back_to_course_clear', 'local_chatbot'); ?>
            </a>
        </div>
    </div>

    <?php if (!$disenoSel): ?>
        <!-- SELECCION DEL DISENO -->
        <div class="card mb-4 border-primary-subtle">
            <div class="card-body">
                <?php foreach (centro_recursos::BIENVENIDA as $parrafo): ?>
                    <p class="mb-2"><?php echo s($parrafo); ?></p>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!$elegibles): ?>
            <div class="alert alert-info">
                <p class="mb-2"><strong><?php echo s(centro_recursos::SIN_DISENOS); ?></strong></p>
                <a class="chatbot-gatebtn" href="<?php echo (new moodle_url('/local/chatbot/diseno.php',
                    ['courseid' => $courseid, 'etapa' => diseno::EVALUACION]))->out(false); ?>">
                    <?php echo get_string('centro_go_fase3', 'local_chatbot'); ?>
                </a>
            </div>
        <?php else: ?>
            <div class="card mb-4">
                <div class="card-header bg-light"><strong><?php echo get_string('centro_select_title', 'local_chatbot'); ?></strong></div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th><?php echo get_string('centro_col_num', 'local_chatbot'); ?></th>
                                <th><?php echo get_string('centro_col_experiencia', 'local_chatbot'); ?></th>
                                <th><?php echo get_string('centro_col_plan', 'local_chatbot'); ?></th>
                                <th><?php echo get_string('centro_col_validacion', 'local_chatbot'); ?></th>
                                <th><?php echo get_string('centro_col_puntaje', 'local_chatbot'); ?></th>
                                <th><?php echo get_string('centro_col_estado_centro', 'local_chatbot'); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($elegibles as $idx => $d): ?>
                                <tr>
                                    <td><?php echo $idx + 1; ?></td>
                                    <td><strong><?php echo s($d['experiencia']); ?></strong></td>
                                    <td>
                                        <?php echo s($d['plan']); ?>
                                        <?php if ($d['modulo'] !== ''): ?><br><small class="text-muted"><?php echo s($d['modulo']); ?></small><?php endif; ?>
                                        <?php if ($d['aprendizaje'] !== ''): ?><br><small><?php echo s(mb_substr($d['aprendizaje'], 0, 120, 'UTF-8')); ?></small><?php endif; ?>
                                    </td>
                                    <td><?php echo userdate($d['fecha'], '%d-%m-%Y'); ?></td>
                                    <td><span class="badge bg-success"><?php echo $d['puntaje']; ?> / 36</span></td>
                                    <td><?php echo s($d['estado_cr']); ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-primary btn-sm" href="<?php echo (new moodle_url('/local/chatbot/centro_recursos.php',
                                            ['courseid' => $courseid, 'fichaid' => $d['fichaid']]))->out(false); ?>">
                                            <?php echo get_string('centro_work_design', 'local_chatbot'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white"><strong><?php echo s(centro_recursos::PREGUNTA_DISENO); ?></strong></div>
            </div>
        <?php endif; ?>

        <details class="card mb-4">
            <summary class="card-header bg-light" style="cursor:pointer;"><strong><?php echo get_string('centro_guide_title', 'local_chatbot'); ?></strong></summary>
            <div class="card-body small">
                <ul>
                    <li><?php echo get_string('centro_guide_1', 'local_chatbot'); ?></li>
                    <li><?php echo get_string('centro_guide_2', 'local_chatbot'); ?></li>
                    <li><?php echo get_string('centro_guide_3', 'local_chatbot'); ?></li>
                    <li><?php echo get_string('centro_guide_4', 'local_chatbot'); ?></li>
                </ul>
            </div>
        </details>

    <?php elseif ($recActual): ?>
        <!-- ETAPAS 10 Y 11: PRODUCCION, REVISION Y ENTREGA DE UN MATERIAL -->
        <?php
        centro_recursos::ensure_plan($disenoSel['fichaid'], $USER->id, $courseid, (array) $disenoSel['ficha']->data);
        echo \local_chatbot\material_ui::render([
            'rec' => $recActual,
            'refs' => $refs,
            'recursourl' => $recursourl,
            'planurl' => $planurl,
            'baseurl' => $baseurl,
            'descarga' => static fn(string $fmt) => new moodle_url('/local/chatbot/centro_recursos.php', [
                'courseid' => $courseid, 'fichaid' => $disenoSel['fichaid'], 'recursoid' => $recActual->id,
                'action' => 'descargar', 'tipo' => $fmt]),
            'estados' => $estadoEtiqueta,
            'sin_web' => !\local_chatbot\ux::on('webcentro'),
            'txt' => [
                'volver' => get_string('centro_back_plan', 'local_chatbot'),
                'referencias' => centro_recursos::PREGUNTA_REFERENCIAS,
                'rechazo' => centro_recursos::RESPUESTA_NO_MODIFICA,
                'revision' => centro_recursos::PREGUNTA_REVISION,
                'titulo_propuesta' => get_string('centro_structure_title', 'local_chatbot'),
                'proponer' => get_string('centro_propose', 'local_chatbot'),
                'aprobar_propuesta' => get_string('centro_approve_structure', 'local_chatbot'),
                'ajustar_propuesta' => get_string('centro_adjust_structure', 'local_chatbot'),
            ],
        ]);
        ?>

    <?php else: ?>
        <!-- ETAPA 9 (PLAN DE PRODUCCION) Y ETAPA 12 (CIERRE / REPERTORIO) -->
        <?php
        $plan = centro_recursos::ensure_plan($disenoSel['fichaid'], $USER->id, $courseid, (array) $disenoSel['ficha']->data);
        $planEnCurso = in_array($plan->generacion, [exante::PENDIENTE, exante::PROCESANDO], true);
        $filasDiseno = diseno::get_by_ficha($disenoSel['fichaid']);
        $huellaActual = (string) ($filasDiseno[diseno::CONSOLIDACION]->huella ?? '');
        $desactualizado = $plan->huella !== '' && $huellaActual !== '' && $plan->huella !== $huellaActual;
        ?>
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h3 class="h5 mb-1"><?php echo s($disenoSel['experiencia']); ?></h3>
                    <div class="small text-muted">
                        <?php echo s($disenoSel['plan']); ?> · <?php echo get_string('centro_lbl_puntaje', 'local_chatbot'); ?> <strong><?php echo $disenoSel['puntaje']; ?> / 36</strong>
                        (<?php echo s($disenoSel['banda']); ?>) · <?php echo get_string('centro_lbl_estado', 'local_chatbot'); ?> <strong><?php echo s($disenoSel['estado_cr']); ?></strong>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($plan->estado === centro_recursos::PLAN_CERRADO): ?>
                        <form method="post" action="<?php echo $planurl->out(false); ?>">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="action" value="reabrir_centro">
                            <button type="submit" class="btn btn-outline-primary btn-sm"><?php echo get_string('centro_reopen', 'local_chatbot'); ?></button>
                        </form>
                    <?php elseif ($recursos): ?>
                        <form method="post" action="<?php echo $planurl->out(false); ?>">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="action" value="cerrar_centro">
                            <button type="submit" class="btn btn-outline-success btn-sm"><?php echo get_string('centro_close', 'local_chatbot'); ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($desactualizado): ?>
            <div class="alert alert-warning small">
                <?php echo get_string('centro_plan_stale', 'local_chatbot'); ?>
            </div>
        <?php endif; ?>

        <?php if ($planEnCurso): ?>
            <div id="centro-generando" class="alert alert-info d-flex align-items-center gap-3"
                 data-id="<?php echo (int) $plan->id; ?>"
                 data-estado="<?php echo s($plan->generacion); ?>"
                 data-tipo="plan"
                 data-sesskey="<?php echo sesskey(); ?>"
                 data-url="<?php echo $baseurl->out(false); ?>"
                 data-volver="<?php echo $planurl->out(false); ?>">
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                <div><?php echo get_string('centro_plan_building', 'local_chatbot'); ?></div>
            </div>
        <?php elseif ($plan->estado === centro_recursos::PLAN_CERRADO): ?>
            <!-- ETAPA 12: REPERTORIO DE RECURSOS -->
            <?php
            $repHtml = centro_recursos::repertory_html([
                'experiencia' => $disenoSel['experiencia'],
                'plan' => $disenoSel['plan'],
                'modulo' => $disenoSel['modulo'],
                'organismo' => $plan->datos_arr['organismo'] ?? '',
            ], $recursos, $refs);
            $zipurl = new moodle_url('/local/chatbot/centro_recursos.php', [
                'courseid' => $courseid, 'fichaid' => $disenoSel['fichaid'], 'action' => 'descargar_zip',
            ]);
            ?>
            <div class="card mb-4">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <strong><?php echo get_string('centro_repertory_title', 'local_chatbot'); ?></strong>
                    <a class="btn btn-success btn-sm" href="<?php echo $zipurl->out(false); ?>"><?php echo get_string('centro_zip', 'local_chatbot'); ?></a>
                </div>
                <div class="card-body">
                    <?php echo recurso_export::to_moodle_html($repHtml, 'Repertorio de recursos del diseño'); ?>
                    <p class="mt-3 mb-0 text-muted small"><?php echo s(centro_recursos::CIERRE_ETAPA_12); ?></p>
                </div>
            </div>
        <?php else: ?>
            <?php if ($plan->generacion === exante::ERROR): ?>
                <div class="alert alert-danger"><?php echo get_string('centro_gen_error', 'local_chatbot'); ?></div>
            <?php endif; ?>

            <!-- DATOS NO DE DISENO (1 VEZ POR DISENO) -->
            <form method="post" action="<?php echo $planurl->out(false); ?>" class="card mb-3">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="guardar_datos">
                <div class="card-header bg-light"><strong><?php echo get_string('centro_data_title', 'local_chatbot'); ?></strong></div>
                <div class="card-body row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="cr_org"><?php echo get_string('centro_data_org', 'local_chatbot'); ?></label>
                        <input type="text" class="form-control form-control-sm" id="cr_org" name="organismo" value="<?php echo s($plan->datos_arr['organismo'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="cr_curso"><?php echo get_string('centro_data_course', 'local_chatbot'); ?></label>
                        <input type="text" class="form-control form-control-sm" id="cr_curso" name="curso" value="<?php echo s($plan->datos_arr['curso'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="cr_fac"><?php echo get_string('centro_data_fac', 'local_chatbot'); ?></label>
                        <input type="text" class="form-control form-control-sm" id="cr_fac" name="facilitador" value="<?php echo s($plan->datos_arr['facilitador'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="cr_imp"><?php echo get_string('centro_data_print', 'local_chatbot'); ?></label>
                        <select class="form-select form-select-sm" id="cr_imp" name="impresion">
                            <option value="Carta" <?php echo ($plan->datos_arr['impresion'] ?? 'Carta') === 'Carta' ? 'selected' : ''; ?>><?php echo get_string('centro_data_letter', 'local_chatbot'); ?></option>
                            <option value="A4" <?php echo ($plan->datos_arr['impresion'] ?? '') === 'A4' ? 'selected' : ''; ?>>A4</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-outline-primary btn-sm w-100"><?php echo get_string('centro_save', 'local_chatbot'); ?></button>
                    </div>
                </div>
            </form>

            <!-- ETAPA 9: TABLA DEL PLAN DE PRODUCCION -->
            <div class="card mb-4">
                <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <strong><?php echo get_string('centro_plan_title', 'local_chatbot'); ?></strong>
                    <form method="post" action="<?php echo $planurl->out(false); ?>">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="action" value="generar_plan">
                        <button type="submit" class="btn btn-primary btn-sm" data-espera="<?php echo get_string('centro_plan_generating', 'local_chatbot'); ?>">
                            <?php echo $recursos ? get_string('centro_plan_update', 'local_chatbot') : get_string('centro_plan_generate', 'local_chatbot'); ?>
                        </button>
                    </form>
                </div>
                <?php if (!$recursos): ?>
                    <div class="card-body">
                        <p class="mb-0 text-muted"><?php echo get_string('centro_plan_empty', 'local_chatbot'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead>
                                <tr>
                                    <th><?php echo get_string('centro_col_num', 'local_chatbot'); ?></th>
                                    <th><?php echo get_string('centro_col_codigo', 'local_chatbot'); ?></th>
                                    <th><?php echo get_string('centro_col_material', 'local_chatbot'); ?></th>
                                    <th><?php echo get_string('centro_col_fase', 'local_chatbot'); ?></th>
                                    <th><?php echo get_string('centro_col_modo', 'local_chatbot'); ?></th>
                                    <th><?php echo get_string('centro_col_formato', 'local_chatbot'); ?></th>
                                    <th><?php echo get_string('centro_col_espec', 'local_chatbot'); ?></th>
                                    <th><?php echo get_string('centro_col_estado', 'local_chatbot'); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $gruposcr = centro_recursos::material_groups($recursos);
                                foreach ($recursos as $idx => $r):
                                    [$etTxt, $etCls] = $estadoEtiqueta[$r->estado] ?? [$r->estado, 'bg-secondary'];
                                    $producible = !in_array($r->estado, [centro_recursos::MAT_NO_PRODUCIBLE, centro_recursos::MAT_INSTRUMENTO], true);
                                    $rurl = new moodle_url('/local/chatbot/centro_recursos.php', [
                                        'courseid' => $courseid, 'fichaid' => $disenoSel['fichaid'], 'recursoid' => $r->id,
                                    ]);
                                ?>
                                    <tr>
                                        <td><?php echo $idx + 1; ?></td>
                                        <td><strong><?php echo s($r->codigo); ?></strong></td>
                                        <td><?php echo s($r->nombre); ?></td>
                                        <td><?php echo s($r->fase); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo s($r->modo); ?></span></td>
                                        <td><?php echo s($r->formato); ?></td>
                                        <td>
                                            <?php echo $r->especificacion === 'incompleta' ? get_string('centro_spec_incomplete', 'local_chatbot') : get_string('centro_spec_complete', 'local_chatbot'); ?>
                                            <?php if ($r->espec_detalle): ?><br><small class="text-muted"><?php echo s($r->espec_detalle); ?></small><?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $etCls; ?>"><?php echo s($etTxt); ?></span>
                                            <?php if ((int) $r->version > 0): ?><br><small>v<?php echo (int) $r->version; ?></small><?php endif; ?>
                                            <?php if ((int) $r->revisar_count > 0): ?><br><small class="text-warning-emphasis">[REVISAR]: <?php echo (int) $r->revisar_count; ?></small><?php endif; ?>
                                            <?php if (centro_recursos::part_letter((string) $r->codigo) !== ''):
                                                $estgrupo = $gruposcr[centro_recursos::base_code((string) $r->codigo)]['estado'] ?? ''; ?>
                                                <br><small class="text-muted"><?php echo get_string('centro_group_state', 'local_chatbot',
                                                    $estadoEtiqueta[$estgrupo][0] ?? $estgrupo); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($producible): ?>
                                                <a class="btn btn-outline-primary btn-sm" href="<?php echo $rurl->out(false); ?>">
                                                    <?php echo in_array($r->estado, [centro_recursos::MAT_ENTREGADO, centro_recursos::MAT_ENTREGADO_PARCIAL], true)
                                                        ? get_string('centro_view_version', 'local_chatbot') : get_string('centro_produce', 'local_chatbot'); ?>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-white small">
                        <p class="mb-1"><strong><?php echo s(centro_recursos::CIERRE_ETAPA_9); ?></strong></p>
                        <?php if (!empty($plan->datos_arr['excluidos_fisicos'])): ?>
                            <p class="mb-0 text-muted"><em><?php echo get_string('centro_physical', 'local_chatbot'); ?></em>
                                <?php echo s(implode('; ', (array) $plan->datos_arr['excluidos_fisicos'])); ?>.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php echo \local_chatbot\material_ui::script(); ?>
<?php
echo $OUTPUT->footer();

