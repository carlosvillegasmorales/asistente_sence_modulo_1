<?php
/**
 * Modulo 3, Fase 3: produccion de los materiales de la experiencia de continuidad (Etapas P1 a P4, opcional).
 *
 * Ajuste de Interfases del 26-11-2026 («Ajuste Prompt Maestro M3 - Produccion de Materiales de la Experiencia de
 * Continuidad», bloque 4). Se habilita con la Etapa 8 consolidada: P1 plan de produccion (recupera la lista de materiales de
 * la Etapa 6 y el estado de cada material de origen en el Centro del Modulo 2), P2 produccion o ajuste de cada material
 * (referencias, propuesta, contenido y ajustes), P3 entrega y registro, y P4 cierre con el repertorio de la continuidad.
 * El motor y la vista de cada material son los del Centro del Modulo 2 (centro_recursos y material_ui); las reglas y el
 * contexto, los de classes/produccion_m3.php.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_chatbot\calidad;
use local_chatbot\centro_recursos;
use local_chatbot\exante;
use local_chatbot\material_ui;
use local_chatbot\produccion_m3;
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
$PAGE->set_title(get_string('pm3_title', 'local_chatbot'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js(new moodle_url('/local/chatbot/js/espera.js'), true);

$baseurl = new moodle_url('/local/chatbot/produccion_m3.php', ['courseid' => $courseid]);
if (!\local_chatbot\ux::on('produccionm3')) {
    $PAGE->set_url($baseurl);
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('pm3_disabled', 'local_chatbot'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$recursoid = optional_param('recursoid', 0, PARAM_INT);

// --- Llamadas asincronas de ejecucion y consulta de estado ---
if (in_array($action, ['ejecutar_plan', 'estado_plan', 'ejecutar_rec', 'estado_rec'], true)) {
    $id = required_param('id', PARAM_INT);
    if ($action === 'ejecutar_plan' || $action === 'ejecutar_rec') {
        require_sesskey();
        \core\session\manager::write_close();
        ignore_user_abort(true);
        core_php_time_limit::raise(centro_recursos::PROCESO_MAX);
        $estado = $action === 'ejecutar_plan' ? produccion_m3::run_plan($id, $USER->id)
            : centro_recursos::run_resource($id, $USER->id);
    } else if ($action === 'estado_plan') {
        $p = $DB->get_record('local_chatbot_recurso_plan', ['id' => $id, 'userid' => $USER->id]);
        $p = $p ? centro_recursos::get_plan((int) $p->fichaid) : null;
        $estado = $p ? (string) $p->generacion : exante::ERROR;
    } else {
        $r = centro_recursos::get_resource($id, $USER->id);
        $estado = $r ? (string) $r->generacion : exante::ERROR;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['estado' => $estado ?: exante::ERROR]);
    exit;
}

$act = produccion_m3::activation($USER->id, $courseid);
$PAGE->set_url($recursoid > 0 ? new moodle_url($baseurl, ['recursoid' => $recursoid]) : $baseurl);
$PAGE->navbar->add(get_string('pm3_title', 'local_chatbot'));
$calidadurl = new moodle_url('/local/chatbot/calidad.php', ['courseid' => $courseid, 'etapa' => calidad::CONSOLIDACION]);

if (!$act['ok']) {
    echo $OUTPUT->header();
    echo html_writer::tag('h2', get_string('pm3_title', 'local_chatbot'));
    echo html_writer::div(html_writer::tag('p', get_string('pm3_not_ready', 'local_chatbot'))
        . html_writer::link($calidadurl, get_string('pm3_go_stage8', 'local_chatbot'), ['class' => 'chatbot-gatebtn']),
        'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

$ficha = $act['ficha'];
$implid = (int) $act['implid'];
$clave = produccion_m3::plan_key($implid);
$disenoid = (int) $ficha->disenoid;
$planurl = $baseurl;
$recursourl = $recursoid > 0 ? new moodle_url($baseurl, ['recursoid' => $recursoid]) : $baseurl;
$plan = produccion_m3::ensure_plan($implid, $USER->id, $courseid, (array) $ficha->data);
$recursos = centro_recursos::get_resources($clave);
$refsPropias = centro_recursos::list_references($clave);
$refsDiseno = $disenoid > 0 ? centro_recursos::list_references($disenoid) : [];
$experiencia = '';
if (!empty($act['filas'][calidad::OPERATIVO]->contenido)) {
    // Nombre de la experiencia de continuidad, tal como quedo en su documento operativo.
    $experiencia = \local_chatbot\documento_continuidad::general_value((string) $act['filas'][calidad::OPERATIVO]->contenido,
        'Experiencia de continuidad');
}
$experiencia = $experiencia !== '' ? $experiencia : get_string('pm3_experience', 'local_chatbot');
$meta = ['experiencia' => $experiencia, 'plan' => (string) ($ficha->data['plan'] ?? ''),
    'modulo' => (string) ($ficha->data['modulo'] ?? ''), 'organismo' => (string) ($plan->datos_arr['organismo'] ?? '')];

// --- Descarga de un material ---
if ($action === 'descargar' && $recursoid > 0) {
    $rec = centro_recursos::get_resource($recursoid, $USER->id);
    if ($rec && (int) $rec->fichaid === $clave && trim((string) $rec->contenido) !== '') {
        $res = centro_recursos::export_material_file($rec, required_param('tipo', PARAM_ALPHANUMEXT), make_request_directory(),
            (string) ($plan->datos_arr['impresion'] ?? 'Carta'), $CFG->dirroot . '/local/chatbot/pix/logo_interfases.png');
        if ($res !== null) {
            send_file($res[0], $res[1], 0, 0, false, true, $res[2]);
        }
    }
    throw new moodle_exception('centro_export_error', 'local_chatbot');
}

// --- Repertorio de la continuidad en .zip (P4) ---
if ($action === 'descargar_zip') {
    $dir = make_request_directory();
    $impresion = (string) ($plan->datos_arr['impresion'] ?? 'Carta');
    $logo = $CFG->dirroot . '/local/chatbot/pix/logo_interfases.png';
    $repHtml = produccion_m3::repertory_html($meta, $recursos);
    $titulo = 'Repertorio de la experiencia de continuidad';
    $archivos = [];
    if (recurso_export::build_docx($dir . '/rep.docx', $titulo, $experiencia, $repHtml, $logo)) {
        $archivos['00_Repertorio_continuidad.docx'] = ['ruta' => $dir . '/rep.docx'];
    }
    if (recurso_export::build_pdf($dir . '/rep.pdf', $titulo, $experiencia, $repHtml, 'PDF A4', $impresion, $logo)) {
        $archivos['00_Repertorio_continuidad.pdf'] = ['ruta' => $dir . '/rep.pdf'];
    }
    $archivos['00_Repertorio_continuidad.html'] = ['texto' => recurso_export::to_moodle_html($repHtml, $titulo)];
    foreach ($recursos as $r) {
        if (!in_array((string) $r->estado, [produccion_m3::MAT_DISPONIBLE, centro_recursos::MAT_ENTREGADO,
                centro_recursos::MAT_ENTREGADO_PARCIAL], true) || trim((string) $r->contenido) === '') {
            continue;
        }
        foreach (centro_recursos::detect_formats((string) $r->modo, (string) $r->formato, (string) $r->nombre,
                (string) ($r->plataforma_quiz ?? '')) as $fmt) {
            if ($fmt === 'curaduria') {
                continue;
            }
            $exp = centro_recursos::export_material_file($r, $fmt, $dir, $impresion, $logo);
            if ($exp !== null) {
                $archivos['materiales/' . $exp[1]] = ['ruta' => $exp[0]];
            }
        }
    }
    if (recurso_export::build_repertory_zip($dir . '/rep.zip', $archivos)) {
        send_file($dir . '/rep.zip', 'Repertorio_continuidad_M3.zip', 0, 0, false, true, 'application/zip');
    }
    throw new moodle_exception('centro_export_error', 'local_chatbot');
}

// --- Acciones POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if ($action === 'generar_plan') {
        produccion_m3::request_plan($implid, $USER->id, $courseid, (array) $ficha->data);
        redirect($planurl);
    }
    if ($action === 'guardar_datos') {
        produccion_m3::save_meta($implid, $USER->id, [
            'organismo' => optional_param('organismo', '', PARAM_TEXT),
            'curso' => optional_param('curso', '', PARAM_TEXT),
            'facilitador' => optional_param('facilitador', '', PARAM_TEXT),
            'fechas' => optional_param('fechas', '', PARAM_TEXT),
            'impresion' => optional_param('impresion', 'Carta', PARAM_TEXT),
        ]);
        redirect($planurl, get_string('centro_datos_saved', 'local_chatbot'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'cerrar_produccion') {
        centro_recursos::close_center($clave, $USER->id);
        redirect($planurl, produccion_m3::CIERRE_P4, null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'reabrir_produccion') {
        centro_recursos::reopen_center($clave, $USER->id);
        redirect($planurl);
    }
    // Un material de otro plan no se toca desde aqui.
    $recPost = $recursoid > 0 ? centro_recursos::get_resource($recursoid, $USER->id) : null;
    if ($recursoid === 0 || ($recPost && (int) $recPost->fichaid === $clave
            && (string) $recPost->estado !== produccion_m3::MAT_DISPONIBLE)) {
        // Los materiales de referencia del diseño (Modulo 2) se usan, pero no se quitan desde aqui (solo lectura).
        material_ui::handle_post($action, $recursoid, (int) $USER->id, (int) $courseid, $clave, $recursourl, $planurl,
            produccion_m3::CIERRE_P3, array_map(static fn($f) => (int) $f->id, $refsPropias));
    }
}

$recActual = $recursoid > 0 ? centro_recursos::get_resource($recursoid, $USER->id) : null;
if ($recActual && (int) $recActual->fichaid !== $clave) {
    $recActual = null;
}
$estadoEtiqueta = [
    centro_recursos::MAT_PENDIENTE => [get_string('centro_st_pendiente', 'local_chatbot'), 'bg-secondary'],
    centro_recursos::MAT_ESTRUCTURA => [get_string('pm3_st_propuesta', 'local_chatbot'), 'bg-info text-dark'],
    centro_recursos::MAT_BORRADOR => [get_string('centro_st_borrador', 'local_chatbot'), 'bg-warning text-dark'],
    centro_recursos::MAT_ENTREGADO => [get_string('centro_st_entregado', 'local_chatbot'), 'bg-success'],
    centro_recursos::MAT_ENTREGADO_PARCIAL => [get_string('centro_st_parcial', 'local_chatbot'), 'bg-primary'],
    produccion_m3::MAT_DISPONIBLE => [get_string('pm3_st_disponible', 'local_chatbot'), 'bg-success-subtle text-success-emphasis border'],
];
$c8 = $act['filas'][calidad::CONSOLIDACION] ?? null;
$desactualizado = $plan->huella !== '' && $c8 && (string) $c8->huella !== '' && $plan->huella !== (string) $c8->huella;
$courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);

echo $OUTPUT->header();
?>
<div class="container-fluid px-0" style="max-width: 1120px;">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h2 class="mb-1"><?php echo get_string('pm3_title', 'local_chatbot'); ?></h2>
            <p class="text-muted mb-0"><?php echo s($experiencia); ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo $calidadurl->out(false); ?>"><?php
                echo get_string('pm3_back_stage8', 'local_chatbot'); ?></a>
            <a class="chatbot-backbtn" href="<?php echo $courseurl->out(false); ?>">&larr; <?php
                echo get_string('back_to_course_clear', 'local_chatbot'); ?></a>
        </div>
    </div>

<?php if ($recActual): ?>
    <?php
    $origen = (array) json_decode((string) ($recActual->origen ?? ''), true);
    $extra = '<div class="small mt-1"><span class="badge bg-light text-dark border">'
        . s(produccion_m3::category_label((string) $recActual->categoria)) . '</span>';
    if (($recActual->categoria ?? '') !== produccion_m3::CAT_NUEVO && !empty($origen['origen_codigo'])) {
        $extra .= ' ' . get_string('pm3_origin', 'local_chatbot', s($origen['origen_codigo']
            . (!empty($origen['origen_version']) ? ' ' . $origen['origen_version'] : '') . ' · '
            . get_string($origen['fuente'] === produccion_m3::FUENTE_REPERTORIO ? 'pm3_src_repertorio' : 'pm3_src_espec',
                'local_chatbot')));
    }
    if (!empty($origen['nota'])) {
        $extra .= '<br><span class="text-muted">' . s($origen['nota']) . '</span>';
    }
    if (!empty($origen['regla8'])) {
        $extra .= '<br><span class="text-warning-emphasis">' . get_string('pm3_rule8', 'local_chatbot', s($origen['regla8']))
            . '</span>';
    }
    $extra .= '</div>';
    $refsVista = $refsPropias;
    foreach ($refsDiseno as $rf) {
        $rf->etiqueta = get_string('pm3_ref_design', 'local_chatbot');
        $refsVista[] = $rf;
    }
    echo material_ui::render([
        'rec' => $recActual,
        'refs' => $refsVista,
        'refs_borrables' => array_map(static fn($f) => (int) $f->id, $refsPropias),
        'recursourl' => $recursourl,
        'planurl' => $planurl,
        'baseurl' => $baseurl,
        'descarga' => static fn(string $fmt) => new moodle_url($baseurl, ['recursoid' => $recActual->id, 'action' => 'descargar',
            'tipo' => $fmt]),
        'estados' => $estadoEtiqueta,
        'solo_lectura' => (string) $recActual->estado === produccion_m3::MAT_DISPONIBLE,
        'cabecera_extra' => $extra,
        'txt' => [
            'volver' => get_string('pm3_back_plan', 'local_chatbot'),
            'referencias' => produccion_m3::PREGUNTA_REFERENCIAS,
            'rechazo' => produccion_m3::RESPUESTA_NO_MODIFICA,
            'revision' => produccion_m3::PREGUNTA_REVISION,
            'titulo_propuesta' => get_string('pm3_proposal_title', 'local_chatbot'),
            'proponer' => get_string('pm3_propose', 'local_chatbot'),
            'aprobar_propuesta' => get_string('pm3_approve_proposal', 'local_chatbot'),
            'ajustar_propuesta' => get_string('pm3_adjust_proposal', 'local_chatbot'),
            'titulo_material' => get_string('pm3_material_title', 'local_chatbot'),
            'regenerar' => get_string('pm3_regenerate', 'local_chatbot'),
            'ajuste_titulo' => get_string('pm3_adjust_title', 'local_chatbot'),
            'ajuste_ph' => get_string('pm3_adjust_ph', 'local_chatbot'),
            'entregado_nota' => get_string('pm3_delivered_note', 'local_chatbot'),
        ],
    ]);
    if ((string) $recActual->estado === produccion_m3::MAT_DISPONIBLE): ?>
        <div class="alert alert-success small"><?php echo get_string('pm3_available_note', 'local_chatbot'); ?></div>
    <?php endif; ?>

<?php else: ?>
    <div class="card mb-3 border-primary-subtle">
        <div class="card-body"><p class="mb-0"><?php echo s(produccion_m3::MENSAJE_INICIO); ?></p></div>
    </div>

    <div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
        <?php if ($plan->estado === centro_recursos::PLAN_CERRADO): ?>
            <form method="post" action="<?php echo $planurl->out(false); ?>">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="reabrir_produccion">
                <button type="submit" class="btn btn-outline-primary btn-sm"><?php echo get_string('pm3_reopen', 'local_chatbot'); ?></button>
            </form>
        <?php elseif ($recursos): ?>
            <form method="post" action="<?php echo $planurl->out(false); ?>">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="cerrar_produccion">
                <button type="submit" class="btn btn-outline-success btn-sm"><?php echo get_string('pm3_close', 'local_chatbot'); ?></button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($desactualizado): ?>
        <div class="alert alert-warning small"><?php echo get_string('pm3_plan_stale', 'local_chatbot'); ?></div>
    <?php endif; ?>

    <?php if (in_array($plan->generacion, [exante::PENDIENTE, exante::PROCESANDO], true)): ?>
        <div id="centro-generando" class="alert alert-info d-flex align-items-center gap-3"
             data-id="<?php echo (int) $plan->id; ?>" data-estado="<?php echo s($plan->generacion); ?>" data-tipo="plan"
             data-sesskey="<?php echo sesskey(); ?>" data-url="<?php echo $baseurl->out(false); ?>"
             data-volver="<?php echo $planurl->out(false); ?>">
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <div><?php echo get_string('pm3_plan_building', 'local_chatbot'); ?></div>
        </div>
    <?php elseif ($plan->estado === centro_recursos::PLAN_CERRADO): ?>
        <!-- ETAPA P4: REPERTORIO DE LA CONTINUIDAD -->
        <div class="card mb-4">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong><?php echo get_string('pm3_repertory_title', 'local_chatbot'); ?></strong>
                <a class="btn btn-success btn-sm" href="<?php echo (new moodle_url($baseurl, ['action' => 'descargar_zip']))->out(false); ?>"><?php
                    echo get_string('centro_zip', 'local_chatbot'); ?></a>
            </div>
            <div class="card-body">
                <?php echo recurso_export::to_moodle_html(produccion_m3::repertory_html($meta, $recursos),
                    'Repertorio de la experiencia de continuidad'); ?>
                <p class="mt-3 mb-0 text-muted small"><?php echo s(produccion_m3::CIERRE_P4); ?></p>
            </div>
        </div>
    <?php else: ?>
        <?php if ($plan->generacion === exante::ERROR): ?>
            <div class="alert alert-danger"><?php echo get_string('centro_gen_error', 'local_chatbot'); ?></div>
        <?php endif; ?>

        <!-- P1: DATOS QUE NO SON DE DISENO (UNA SOLA VEZ) -->
        <form method="post" action="<?php echo $planurl->out(false); ?>" class="card mb-3">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="guardar_datos">
            <div class="card-header bg-light"><strong><?php echo get_string('pm3_data_title', 'local_chatbot'); ?></strong></div>
            <div class="card-body row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="pm_org"><?php echo get_string('centro_data_org', 'local_chatbot'); ?></label>
                    <input type="text" class="form-control form-control-sm" id="pm_org" name="organismo" value="<?php echo s($plan->datos_arr['organismo'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="pm_fechas"><?php echo get_string('pm3_data_dates', 'local_chatbot'); ?></label>
                    <input type="text" class="form-control form-control-sm" id="pm_fechas" name="fechas" value="<?php echo s($plan->datos_arr['fechas'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="pm_fac"><?php echo get_string('centro_data_fac', 'local_chatbot'); ?></label>
                    <input type="text" class="form-control form-control-sm" id="pm_fac" name="facilitador" value="<?php echo s($plan->datos_arr['facilitador'] ?? ''); ?>">
                    <input type="hidden" name="curso" value="<?php echo s($plan->datos_arr['curso'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1" for="pm_imp"><?php echo get_string('centro_data_print', 'local_chatbot'); ?></label>
                    <select class="form-select form-select-sm" id="pm_imp" name="impresion">
                        <option value="Carta" <?php echo ($plan->datos_arr['impresion'] ?? 'Carta') === 'Carta' ? 'selected' : ''; ?>><?php echo get_string('centro_data_letter', 'local_chatbot'); ?></option>
                        <option value="A4" <?php echo ($plan->datos_arr['impresion'] ?? '') === 'A4' ? 'selected' : ''; ?>>A4</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100"><?php echo get_string('centro_save', 'local_chatbot'); ?></button>
                </div>
            </div>
        </form>

        <!-- ETAPA P1: PLAN DE PRODUCCION -->
        <div class="card mb-4">
            <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center gap-2">
                <strong><?php echo get_string('pm3_plan_title', 'local_chatbot'); ?></strong>
                <form method="post" action="<?php echo $planurl->out(false); ?>">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="action" value="generar_plan">
                    <button type="submit" class="btn btn-primary btn-sm" data-espera="<?php echo get_string('pm3_plan_building', 'local_chatbot'); ?>">
                        <?php echo get_string($recursos ? 'pm3_plan_update' : 'pm3_plan_generate', 'local_chatbot'); ?>
                    </button>
                </form>
            </div>
            <?php if (!$recursos): ?>
                <div class="card-body"><p class="mb-0 text-muted"><?php echo get_string('pm3_plan_empty', 'local_chatbot'); ?></p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead>
                            <tr>
                                <th><?php echo get_string('centro_col_num', 'local_chatbot'); ?></th>
                                <th><?php echo get_string('centro_col_codigo', 'local_chatbot'); ?></th>
                                <th><?php echo get_string('centro_col_material', 'local_chatbot'); ?></th>
                                <th><?php echo get_string('pm3_col_category', 'local_chatbot'); ?></th>
                                <th><?php echo get_string('pm3_col_origin', 'local_chatbot'); ?></th>
                                <th><?php echo get_string('pm3_col_block', 'local_chatbot'); ?></th>
                                <th><?php echo get_string('centro_col_modo', 'local_chatbot'); ?></th>
                                <th><?php echo get_string('centro_col_formato', 'local_chatbot'); ?></th>
                                <th><?php echo get_string('centro_col_estado', 'local_chatbot'); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recursos as $idx => $r):
                                $o = (array) json_decode((string) ($r->origen ?? ''), true);
                                [$etTxt, $etCls] = $estadoEtiqueta[$r->estado] ?? [$r->estado, 'bg-secondary'];
                                $origenTxt = ($r->categoria ?? '') === produccion_m3::CAT_NUEVO || empty($o['origen_codigo']) ? '—'
                                    : $o['origen_codigo'] . (!empty($o['origen_version']) ? ' ' . $o['origen_version'] : '') . ' · '
                                        . get_string(($o['fuente'] ?? '') === produccion_m3::FUENTE_REPERTORIO ? 'pm3_src_repertorio'
                                            : 'pm3_src_espec', 'local_chatbot');
                                $rurl = new moodle_url($baseurl, ['recursoid' => $r->id]);
                            ?>
                                <tr>
                                    <td><?php echo $idx + 1; ?></td>
                                    <td><strong><?php echo s($r->codigo); ?></strong>
                                        <?php if (!empty($o['regla8'])): ?><br><small class="text-warning-emphasis" title="<?php echo s($o['regla8']); ?>"><?php
                                            echo get_string('pm3_rule8_short', 'local_chatbot'); ?></small><?php endif; ?></td>
                                    <td><?php echo s($r->nombre); ?></td>
                                    <td><?php echo s(produccion_m3::category_label((string) $r->categoria)); ?></td>
                                    <td><?php echo s($origenTxt); ?></td>
                                    <td><?php echo s($r->fase); ?></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo s($r->modo); ?></span></td>
                                    <td><?php echo s($r->formato); ?></td>
                                    <td>
                                        <span class="badge <?php echo $etCls; ?>"><?php echo s($etTxt); ?></span>
                                        <?php if ((int) $r->version > 0 && $r->estado !== produccion_m3::MAT_DISPONIBLE): ?><br><small>v<?php echo (int) $r->version; ?></small><?php endif; ?>
                                        <?php if ((int) $r->revisar_count > 0): ?><br><small class="text-warning-emphasis">[REVISAR]: <?php echo (int) $r->revisar_count; ?></small><?php endif; ?>
                                        <?php if (!empty($o['nota'])): ?><br><small class="text-muted"><?php echo s($o['nota']); ?></small><?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a class="btn btn-outline-primary btn-sm" href="<?php echo $rurl->out(false); ?>">
                                            <?php echo get_string($r->estado === produccion_m3::MAT_DISPONIBLE ? 'pm3_view'
                                                : (in_array($r->estado, [centro_recursos::MAT_ENTREGADO, centro_recursos::MAT_ENTREGADO_PARCIAL], true)
                                                    ? 'centro_view_version' : 'centro_produce'), 'local_chatbot'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white small">
                    <p class="mb-1"><strong><?php echo s(produccion_m3::CIERRE_P1); ?></strong></p>
                    <?php if (!empty($plan->datos_arr['recursos_entorno'])): ?>
                        <p class="mb-0 text-muted"><em><?php echo get_string('pm3_environment', 'local_chatbot'); ?></em>
                            <?php echo s(implode('; ', (array) $plan->datos_arr['recursos_entorno'])); ?>.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php
echo material_ui::script();
echo $OUTPUT->footer();
