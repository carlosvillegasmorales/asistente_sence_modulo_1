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
    if ($action === 'subir_ref') {
        $secciones = optional_param('ref_secciones', '', PARAM_TEXT);
        if (!empty($_FILES['ref_archivo']['tmp_name']) && is_uploaded_file($_FILES['ref_archivo']['tmp_name'])) {
            $tmp = $_FILES['ref_archivo']['tmp_name'];
            $nombre = (string) ($_FILES['ref_archivo']['name'] ?? 'archivo');
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) $finfo->file($tmp);
            $idRef = centro_recursos::add_reference($fid, $USER->id, $courseid, $tmp, $nombre, $mime, $secciones);
            if ($idRef === null) {
                redirect($recursourl, get_string('centro_ref_invalid', 'local_chatbot'), null,
                    \core\output\notification::NOTIFY_ERROR);
            }
            redirect($recursourl, get_string('centro_ref_added', 'local_chatbot'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
        redirect($recursourl);
    }
    if ($action === 'borrar_ref') {
        $refid = required_param('refid', PARAM_INT);
        centro_recursos::delete_reference($refid, $USER->id);
        redirect($recursourl, get_string('centro_ref_deleted', 'local_chatbot'), null,
            \core\output\notification::NOTIFY_INFO);
    }
    if ($action === 'proponer_estructura' && $recursoid > 0) {
        $refIds = optional_param_array('ref_ids', [], PARAM_INT);
        $ajusteEst = optional_param('ajuste_estructura', '', PARAM_TEXT);
        $platQuiz = optional_param('plataforma_quiz', '', PARAM_ALPHA);
        centro_recursos::request_resource($recursoid, $USER->id, centro_recursos::GEN_ESTRUCTURA, $ajusteEst, $refIds, $platQuiz);
        redirect($recursourl);
    }
    if ($action === 'producir_contenido' && $recursoid > 0) {
        $refIds = optional_param_array('ref_ids', [], PARAM_INT);
        $platQuiz = optional_param('plataforma_quiz', '', PARAM_ALPHA);
        centro_recursos::request_resource($recursoid, $USER->id, centro_recursos::GEN_CONTENIDO, '', $refIds, $platQuiz);
        redirect($recursourl);
    }
    if ($action === 'ajustar_material' && $recursoid > 0) {
        $ajuste = trim(optional_param('ajuste_material', '', PARAM_TEXT));
        if ($ajuste !== '') {
            centro_recursos::request_resource($recursoid, $USER->id, centro_recursos::GEN_AJUSTE, $ajuste);
        }
        redirect($recursourl);
    }
    if ($action === 'resolver_marca' && $recursoid > 0) {
        $ok = centro_recursos::save_resolved_mark($recursoid, $USER->id, required_param('indice', PARAM_INT),
            optional_param('reemplazo', '', PARAM_TEXT));
        redirect(new moodle_url($recursourl, [], 'cr-marcas'), get_string($ok ? 'centro_mark_resolved' : 'centro_mark_missing',
            'local_chatbot'), null, $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
    }
    if ($action === 'web_bloque' && $recursoid > 0) {
        // Fragmento obtenido de la web: «Aceptar» lo deja tal cual; «Editar» cambia solo ese fragmento.
        $editar = optional_param('modo', 'aceptar', PARAM_ALPHA) === 'editar';
        $reemplazo = $editar ? trim(optional_param('reemplazo', '', PARAM_TEXT)) : null;
        $ok = !($editar && $reemplazo === '') && centro_recursos::save_web_block($recursoid, $USER->id,
            required_param('bloque', PARAM_INT), $reemplazo);
        redirect(new moodle_url($recursourl, [], 'cr-web'), get_string($ok ? 'centro_web_done' : 'centro_web_missing',
            'local_chatbot'), null, $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
    }
    if ($action === 'guardar_edicion' && $recursoid > 0) {
        $htmlEdit = optional_param('contenido_edit', '', PARAM_RAW);
        centro_recursos::save_manual_edit($recursoid, $USER->id, $htmlEdit);
        redirect($recursourl, get_string('centro_edit_saved', 'local_chatbot'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    if (($action === 'cur_aceptar' || $action === 'cur_quitar') && $recursoid > 0) {
        // Modo C: «Aceptar» agrega la opcion a los recursos que se usaran; «Quitar» la saca.
        $recSel = centro_recursos::get_resource($recursoid, $USER->id);
        $selActual = $recSel ? array_map('intval', (array) (json_decode((string) ($recSel->seleccionados ?? '[]'), true) ?: [])) : [];
        $op = required_param('opcion', PARAM_INT);
        $selNueva = $action === 'cur_aceptar' ? array_merge($selActual, [$op]) : array_diff($selActual, [$op]);
        sort($selNueva);
        centro_recursos::select_curated($recursoid, $USER->id, array_values(array_unique($selNueva)));
        redirect(new moodle_url($recursourl, [], 'cr-cur'), get_string('centro_cur_saved', 'local_chatbot'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'cur_editar' && $recursoid > 0) {
        $ok = centro_recursos::save_curated_option($recursoid, $USER->id, required_param('opcion', PARAM_INT), [
            'momento' => optional_param('momento', '', PARAM_TEXT),
            'justificacion' => optional_param('justificacion', '', PARAM_TEXT),
            'advertencias' => optional_param('advertencias', '', PARAM_TEXT),
        ]);
        redirect(new moodle_url($recursourl, [], 'cr-cur'), get_string($ok ? 'centro_cur_edited' : 'centro_web_missing',
            'local_chatbot'), null, $ok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
    }
    if ($action === 'seleccionar_curaduria' && $recursoid > 0) {
        $sel = optional_param_array('cur_sel', [], PARAM_INT);
        centro_recursos::select_curated($recursoid, $USER->id, $sel);
        redirect($recursourl, get_string('centro_cur_saved', 'local_chatbot'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'aprobar_material' && $recursoid > 0) {
        $parcial = optional_param('parcial', 0, PARAM_INT) === 1;
        centro_recursos::approve_material($recursoid, $USER->id, $parcial);
        redirect($planurl, centro_recursos::CIERRE_ETAPA_11, null, \core\output\notification::NOTIFY_SUCCESS);
    }
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
        $plan = centro_recursos::ensure_plan($disenoSel['fichaid'], $USER->id, $courseid, (array) $disenoSel['ficha']->data);
        $enCurso = in_array($recActual->generacion, [exante::PENDIENTE, exante::PROCESANDO], true);
        $refUsadas = (array) json_decode((string) ($recActual->refs_usadas ?? '[]'), true);
        $esQuiz = centro_recursos::is_quiz_material((string) $recActual->nombre, (string) $recActual->formato);
        $esModoC = strtoupper(trim((string) $recActual->modo)) === 'C';
        $formatosDescarga = centro_recursos::detect_formats((string) $recActual->modo, (string) $recActual->formato,
            (string) $recActual->nombre, (string) ($recActual->plataforma_quiz ?? ''));
        [$etTxt, $etCls] = $estadoEtiqueta[$recActual->estado] ?? [$recActual->estado, 'bg-secondary'];
        ?>
        <div class="mb-3">
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo $planurl->out(false); ?>">&larr; <?php echo get_string('centro_back_plan', 'local_chatbot'); ?></a>
        </div>

        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h3 class="h5 mb-1"><?php echo s($recActual->codigo . ' · ' . $recActual->nombre); ?></h3>
                    <div class="small text-muted">
                        <strong><?php echo get_string('centro_lbl_fase', 'local_chatbot'); ?></strong> <?php echo s($recActual->fase ?: get_string('centro_lbl_segun', 'local_chatbot')); ?> ·
                        <strong><?php echo get_string('centro_lbl_modo', 'local_chatbot'); ?></strong> <?php echo s($recActual->modo); ?> ·
                        <strong><?php echo get_string('centro_lbl_formato', 'local_chatbot'); ?></strong> <?php echo s($recActual->formato); ?>
                        <?php if ((int) $recActual->version > 0): ?> · <strong><?php echo get_string('centro_lbl_version', 'local_chatbot'); ?></strong> v<?php echo (int) $recActual->version; ?><?php endif; ?>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge <?php echo $etCls; ?>"><?php echo s($etTxt); ?></span>
                    <?php if ((int) $recActual->revisar_count > 0): ?>
                        <span class="badge bg-warning text-dark"><?php echo get_string('centro_marks', 'local_chatbot', (int) $recActual->revisar_count); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($enCurso): ?>
            <div id="centro-generando" class="alert alert-info d-flex align-items-center gap-3"
                 data-id="<?php echo (int) $recActual->id; ?>"
                 data-estado="<?php echo s($recActual->generacion); ?>"
                 data-tipo="rec"
                 data-sesskey="<?php echo sesskey(); ?>"
                 data-url="<?php echo $baseurl->out(false); ?>"
                 data-volver="<?php echo $recursourl->out(false); ?>">
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                <div><?php echo get_string('centro_producing', 'local_chatbot'); ?></div>
            </div>
        <?php else: ?>
            <?php if ($recActual->error === 'no_modifica'): ?>
                <div class="alert alert-warning"><?php echo s(centro_recursos::RESPUESTA_NO_MODIFICA); ?></div>
            <?php elseif ($recActual->generacion === exante::ERROR && $recActual->error === 'seccion'): ?>
                <div class="alert alert-warning"><?php echo get_string('centro_section_error', 'local_chatbot'); ?></div>
            <?php elseif ($recActual->generacion === exante::ERROR): ?>
                <div class="alert alert-danger"><?php echo get_string('centro_gen_error', 'local_chatbot'); ?></div>
            <?php endif; ?>

            <!-- 10.1 MATERIALES DE REFERENCIA -->
            <div class="card mb-3">
                <div class="card-header bg-light"><strong><?php echo get_string('centro_ref_title', 'local_chatbot'); ?></strong></div>
                <div class="card-body">
                    <p class="mb-1"><?php echo s(centro_recursos::PREGUNTA_REFERENCIAS); ?></p>
                    <p class="small text-muted mb-2"><?php echo get_string('centro_ref_help', 'local_chatbot'); ?></p>
                    <form method="post" action="<?php echo $recursourl->out(false); ?>" enctype="multipart/form-data" class="row g-2 align-items-end mb-3">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="action" value="subir_ref">
                        <div class="col-md-5">
                            <label class="form-label small mb-1" for="ref_archivo"><?php echo get_string('centro_ref_file', 'local_chatbot'); ?></label>
                            <input type="file" class="form-control form-control-sm" id="ref_archivo" name="ref_archivo" accept=".pdf,.docx,.xlsx,.xls,.png,.jpg,.jpeg,.webp" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small mb-1" for="ref_secciones"><?php echo get_string('centro_ref_sections', 'local_chatbot'); ?></label>
                            <input type="text" class="form-control form-control-sm" id="ref_secciones" name="ref_secciones" placeholder="<?php echo get_string('centro_ref_sections_ph', 'local_chatbot'); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-outline-primary btn-sm w-100"><?php echo get_string('centro_upload', 'local_chatbot'); ?></button>
                        </div>
                    </form>
                    <?php if ($refs): ?>
                        <div class="small">
                            <strong><?php echo get_string('centro_refs_available', 'local_chatbot'); ?></strong>
                            <ul class="list-unstyled mt-1 mb-0">
                                <?php foreach ($refs as $rf): ?>
                                    <li class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                        <span><?php echo s($rf->filename); ?> <small class="text-muted">(<?php echo round($rf->sizebytes / 1024); ?> KB<?php
                                            echo $rf->secciones ? ' · ' . s($rf->secciones) : ''; ?>)</small></span>
                                        <form method="post" action="<?php echo $recursourl->out(false); ?>" class="d-inline">
                                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                            <input type="hidden" name="action" value="borrar_ref">
                                            <input type="hidden" name="refid" value="<?php echo (int) $rf->id; ?>">
                                            <button type="submit" class="btn btn-link btn-sm text-danger p-0"><?php echo get_string('centro_remove', 'local_chatbot'); ?></button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 10.2 ESTRUCTURA -->
            <div class="card mb-3">
                <div class="card-header bg-light"><strong><?php echo get_string('centro_structure_title', 'local_chatbot'); ?></strong></div>
                <div class="card-body">
                    <?php if (trim((string) $recActual->estructura) === ''): ?>
                        <form method="post" action="<?php echo $recursourl->out(false); ?>">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="action" value="proponer_estructura">
                            <?php if ($refs): ?>
                                <div class="mb-2 small">
                                    <div class="fw-bold mb-1"><?php echo get_string('centro_refs_pick', 'local_chatbot'); ?></div>
                                    <?php foreach ($refs as $rf): ?>
                                        <label class="me-3"><input type="checkbox" name="ref_ids[]" value="<?php echo (int) $rf->id; ?>"
                                            <?php echo in_array((int) $rf->id, $refUsadas, true) ? 'checked' : ''; ?>> <?php echo s($rf->filename); ?></label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($esQuiz): ?>
                                <div class="mb-3" style="max-width:340px;">
                                    <label class="form-label small fw-bold" for="plataforma_quiz"><?php echo get_string('centro_quiz_where', 'local_chatbot'); ?></label>
                                    <select class="form-select form-select-sm" id="plataforma_quiz" name="plataforma_quiz">
                                        <option value="todas"><?php echo get_string('centro_quiz_all', 'local_chatbot'); ?></option>
                                        <option value="moodle" <?php echo $recActual->plataforma_quiz === 'moodle' ? 'selected' : ''; ?>><?php echo get_string('centro_quiz_moodle', 'local_chatbot'); ?></option>
                                        <option value="google" <?php echo $recActual->plataforma_quiz === 'google' ? 'selected' : ''; ?>><?php echo get_string('centro_quiz_google', 'local_chatbot'); ?></option>
                                        <option value="impreso" <?php echo $recActual->plataforma_quiz === 'impreso' ? 'selected' : ''; ?>><?php echo get_string('centro_quiz_print', 'local_chatbot'); ?></option>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary" data-espera="<?php echo get_string('centro_proposing', 'local_chatbot'); ?>"><?php echo get_string('centro_propose', 'local_chatbot'); ?></button>
                        </form>
                    <?php else: ?>
                        <div class="border rounded p-3 bg-light mb-3"><?php echo centro_recursos::highlight_review_marks((string) $recActual->estructura); ?></div>
                        <div class="d-flex flex-wrap gap-2 align-items-start">
                            <form method="post" action="<?php echo $recursourl->out(false); ?>" class="d-inline">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="action" value="producir_contenido">
                                <?php foreach ($refUsadas as $rid): ?>
                                    <input type="hidden" name="ref_ids[]" value="<?php echo (int) $rid; ?>">
                                <?php endforeach; ?>
                                <button type="submit" class="btn btn-success" data-espera="<?php echo get_string('centro_producing_full', 'local_chatbot'); ?>">
                                    <?php echo trim((string) $recActual->contenido) === '' ? get_string('centro_approve_structure', 'local_chatbot') : get_string('centro_regenerate', 'local_chatbot'); ?>
                                </button>
                            </form>
                            <details class="flex-grow-1">
                                <summary class="btn btn-outline-secondary btn-sm"><?php echo get_string('centro_adjust_structure', 'local_chatbot'); ?></summary>
                                <form method="post" action="<?php echo $recursourl->out(false); ?>" class="mt-2">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="action" value="proponer_estructura">
                                    <?php if ($refs): ?>
                                        <div class="mb-2 small">
                                            <?php foreach ($refs as $rf): ?>
                                                <label class="me-3"><input type="checkbox" name="ref_ids[]" value="<?php echo (int) $rf->id; ?>"
                                                    <?php echo in_array((int) $rf->id, $refUsadas, true) ? 'checked' : ''; ?>> <?php echo s($rf->filename); ?></label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <textarea class="form-control form-control-sm mb-2" name="ajuste_estructura" rows="2" placeholder="<?php echo get_string('centro_adjust_structure_ph', 'local_chatbot'); ?>"></textarea>
                                    <button type="submit" class="btn btn-outline-primary btn-sm" data-espera="<?php echo get_string('centro_adjusting_structure', 'local_chatbot'); ?>"><?php echo get_string('centro_apply_structure', 'local_chatbot'); ?></button>
                                </form>
                            </details>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 10.3, 10.4 Y ETAPA 11: CONTENIDO, REVISION, ENTREGA Y DESCARGAS -->
            <?php if (trim((string) $recActual->contenido) !== ''): ?>
                <div class="card mb-3">
                    <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <strong><?php echo get_string('centro_material_title', 'local_chatbot'); ?></strong>
                        <div class="d-flex flex-wrap gap-1">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="cr-copy-txt"><?php echo get_string('centro_copy_text', 'local_chatbot'); ?></button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="cr-copy-html"><?php echo get_string('centro_copy_html', 'local_chatbot'); ?></button>
                            <?php
                            $etiquetasDesc = [
                                'docx' => 'Word (.docx)',
                                'quiz_docx' => get_string('centro_dl_quiz', 'local_chatbot'),
                                'xlsx' => 'Excel (.xlsx)',
                                'pptx' => get_string('centro_dl_pptx', 'local_chatbot'),
                                'pdf' => 'PDF (' . s($recActual->formato) . ')',
                                'gift' => 'Moodle GIFT (.txt UTF-8)',
                                'gs' => 'Google Forms (.gs)',
                                'html' => 'HTML (.html)',
                            ];
                            foreach ($formatosDescarga as $fmt):
                                if ($fmt === 'curaduria' || !isset($etiquetasDesc[$fmt])) {
                                    continue;
                                }
                                $durl = new moodle_url('/local/chatbot/centro_recursos.php', [
                                    'courseid' => $courseid, 'fichaid' => $disenoSel['fichaid'],
                                    'recursoid' => $recActual->id, 'action' => 'descargar', 'tipo' => $fmt,
                                ]);
                            ?>
                                <a class="btn btn-outline-primary btn-sm" href="<?php echo $durl->out(false); ?>">
                                    <?php echo $etiquetasDesc[$fmt]; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ((int) $recActual->revisar_count > 0): ?>
                            <div class="alert alert-warning small py-2" id="cr-marcas">
                                <?php echo get_string('centro_marks_warning', 'local_chatbot', (int) $recActual->revisar_count); ?>
                                <?php $marcascr = empty($recActual->estructurado)
                                    ? centro_recursos::extract_review_marks((string) $recActual->contenido) : []; ?>
                                <?php if ($marcascr): ?>
                                    <ol class="mt-2 mb-0 ps-3">
                                    <?php foreach ($marcascr as $im => $marca): ?>
                                        <li class="mb-2">
                                            <span class="fw-bold"><?php echo s($marca); ?></span>
                                            <div class="d-flex flex-wrap gap-2 align-items-start mt-1">
                                                <form method="post" action="<?php echo $recursourl->out(false); ?>" class="d-inline">
                                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                                    <input type="hidden" name="action" value="resolver_marca">
                                                    <input type="hidden" name="indice" value="<?php echo (int) $im; ?>">
                                                    <button type="submit" class="btn btn-success btn-sm"><i class="fa fa-check me-1"></i><?php
                                                        echo get_string('centro_mark_ok', 'local_chatbot'); ?></button>
                                                </form>
                                                <details>
                                                    <summary class="btn btn-outline-secondary btn-sm"><i class="fa fa-pencil me-1"></i><?php
                                                        echo get_string('centro_mark_fix', 'local_chatbot'); ?></summary>
                                                    <form method="post" action="<?php echo $recursourl->out(false); ?>" class="d-flex flex-wrap gap-2 mt-2">
                                                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                                        <input type="hidden" name="action" value="resolver_marca">
                                                        <input type="hidden" name="indice" value="<?php echo (int) $im; ?>">
                                                        <input type="text" name="reemplazo" required class="form-control form-control-sm" style="min-width: 320px;"
                                                               placeholder="<?php echo s(get_string('centro_mark_fix_ph', 'local_chatbot')); ?>">
                                                        <button type="submit" class="btn btn-primary btn-sm"><?php
                                                            echo get_string('centro_mark_replace', 'local_chatbot'); ?></button>
                                                    </form>
                                                </details>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                    </ol>
                                <?php elseif (!empty($recActual->estructurado)): ?>
                                    <div class="mt-1"><?php echo get_string('centro_marks_structured', 'local_chatbot'); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php $bloquesweb = !\local_chatbot\ux::on('webcentro') || $esModoC ? []
                            : (empty($recActual->estructurado) ? centro_recursos::web_blocks((string) $recActual->contenido)
                                : centro_recursos::web_blocks_json((array) json_decode((string) $recActual->estructurado, true))); ?>
                        <?php if ($bloquesweb): ?>
                            <div class="alert alert-info small py-2" id="cr-web">
                                <div class="fw-bold"><i class="fa fa-globe me-1"></i><?php
                                    echo get_string('centro_web_title', 'local_chatbot', count($bloquesweb)); ?></div>
                                <div class="mb-2"><?php echo get_string('centro_web_help', 'local_chatbot'); ?></div>
                                <ol class="mb-0 ps-3">
                                <?php foreach ($bloquesweb as $nb => $bw): ?>
                                    <li class="mb-3" value="<?php echo (int) $nb; ?>">
                                        <div class="border rounded bg-white p-2 mb-1"><?php echo s($bw['texto']); ?></div>
                                        <div class="mb-1"><span class="text-muted"><?php echo get_string('centro_web_source', 'local_chatbot'); ?></span>
                                            <?php if ($bw['url'] !== ''): ?>
                                                <a href="<?php echo s($bw['url']); ?>" target="_blank" rel="noopener"><?php
                                                    echo s($bw['titulo'] !== '' ? $bw['titulo'] : $bw['url']); ?></a>
                                            <?php else: ?>
                                                <?php echo get_string('centro_web_nolink', 'local_chatbot'); ?>
                                            <?php endif; ?>
                                            <?php if ($bw['origen'] === 'chile'): ?>
                                                <span class="badge bg-success"><?php echo get_string('centro_web_chile', 'local_chatbot'); ?></span>
                                            <?php elseif ($bw['origen'] === 'extranjera'): ?>
                                                <span class="badge bg-warning text-dark"><?php echo get_string('centro_web_foreign', 'local_chatbot'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2 align-items-start">
                                            <form method="post" action="<?php echo $recursourl->out(false); ?>" class="d-inline">
                                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                                <input type="hidden" name="action" value="web_bloque">
                                                <input type="hidden" name="bloque" value="<?php echo (int) $nb; ?>">
                                                <input type="hidden" name="modo" value="aceptar">
                                                <button type="submit" class="btn btn-success btn-sm"><i class="fa fa-check me-1"></i><?php
                                                    echo get_string('centro_web_accept', 'local_chatbot'); ?></button>
                                            </form>
                                            <details class="flex-grow-1">
                                                <summary class="btn btn-outline-secondary btn-sm"><i class="fa fa-pencil me-1"></i><?php
                                                    echo get_string('centro_web_edit', 'local_chatbot'); ?></summary>
                                                <form method="post" action="<?php echo $recursourl->out(false); ?>" class="mt-2">
                                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                                    <input type="hidden" name="action" value="web_bloque">
                                                    <input type="hidden" name="bloque" value="<?php echo (int) $nb; ?>">
                                                    <input type="hidden" name="modo" value="editar">
                                                    <label class="form-label small text-muted mb-1"><?php
                                                        echo get_string('centro_web_edit_help', 'local_chatbot'); ?></label>
                                                    <textarea name="reemplazo" required rows="3" class="form-control form-control-sm mb-2"><?php
                                                        echo s($bw['texto']); ?></textarea>
                                                    <button type="submit" class="btn btn-primary btn-sm"><?php
                                                        echo get_string('centro_web_save', 'local_chatbot'); ?></button>
                                                </form>
                                            </details>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                                </ol>
                            </div>
                        <?php endif; ?>

                        <div id="cr-material-vista" class="mb-3">
                            <?php
                            $htmlModerno = recurso_export::to_moodle_html(\local_chatbot\diagrama::list_flows((string) $recActual->contenido), (string) $recActual->nombre, (string) $recActual->codigo);
                            echo $bloquesweb ? centro_recursos::render_web_marks($htmlModerno) : centro_recursos::strip_web_marks($htmlModerno);
                            ?>
                        </div>
                        <textarea id="cr-html-fuente" class="d-none"><?php echo s(centro_recursos::strip_web_marks($htmlModerno)); ?></textarea>

                        <?php if ($esModoC && !empty($recActual->estructurado)): ?>
                            <?php
                            $cur = json_decode((string) $recActual->estructurado, true);
                            $selCur = json_decode((string) ($recActual->seleccionados ?? '[]'), true) ?: [];
                            ?>
                            <?php if (!empty($cur['opciones'])): ?>
                                <div class="border rounded p-3 bg-light mb-3 small" id="cr-cur">
                                    <div class="fw-bold mb-2"><i class="fa fa-globe me-1"></i><?php echo get_string('centro_cur_pick', 'local_chatbot'); ?></div>
                                    <ol class="mb-0 ps-3">
                                    <?php foreach ($cur['opciones'] as $ci => $op): ?>
                                        <?php $origenCur = centro_recursos::source_origin((string) ($op['enlace'] ?? '')); ?>
                                        <li class="mb-3">
                                            <strong><?php echo s($op['titulo']); ?></strong>
                                            (<?php echo s($op['fuente'] . ' · ' . $op['duracion']); ?>)
                                            <?php if (in_array($ci, $selCur, true)): ?>
                                                <span class="badge bg-success"><?php echo get_string('centro_cur_accepted', 'local_chatbot'); ?></span>
                                            <?php endif; ?>
                                            <?php if ($origenCur === 'chile'): ?>
                                                <span class="badge bg-success-subtle text-success-emphasis"><?php echo get_string('centro_web_chile', 'local_chatbot'); ?></span>
                                            <?php elseif ($origenCur === 'extranjera'): ?>
                                                <span class="badge bg-warning text-dark"><?php echo get_string('centro_web_foreign', 'local_chatbot'); ?></span>
                                            <?php endif; ?>
                                            <div class="d-flex flex-wrap gap-2 align-items-start mt-1">
                                                <a class="btn btn-outline-primary btn-sm" href="<?php echo s((string) $op['enlace']); ?>" target="_blank" rel="noopener"><i class="fa fa-external-link me-1"></i><?php
                                                    echo get_string('centro_cur_open', 'local_chatbot'); ?></a>
                                                <form method="post" action="<?php echo $recursourl->out(false); ?>" class="d-inline">
                                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                                    <input type="hidden" name="opcion" value="<?php echo (int) $ci; ?>">
                                                    <?php if (in_array($ci, $selCur, true)): ?>
                                                        <input type="hidden" name="action" value="cur_quitar">
                                                        <button type="submit" class="btn btn-outline-secondary btn-sm"><?php
                                                            echo get_string('centro_cur_remove', 'local_chatbot'); ?></button>
                                                    <?php else: ?>
                                                        <input type="hidden" name="action" value="cur_aceptar">
                                                        <button type="submit" class="btn btn-success btn-sm"><i class="fa fa-check me-1"></i><?php
                                                            echo get_string('centro_web_accept', 'local_chatbot'); ?></button>
                                                    <?php endif; ?>
                                                </form>
                                                <details class="flex-grow-1">
                                                    <summary class="btn btn-outline-secondary btn-sm"><i class="fa fa-pencil me-1"></i><?php
                                                        echo get_string('centro_web_edit', 'local_chatbot'); ?></summary>
                                                    <form method="post" action="<?php echo $recursourl->out(false); ?>" class="mt-2">
                                                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                                        <input type="hidden" name="action" value="cur_editar">
                                                        <input type="hidden" name="opcion" value="<?php echo (int) $ci; ?>">
                                                        <div class="text-muted mb-1"><?php echo get_string('centro_cur_edit_help', 'local_chatbot'); ?></div>
                                                        <?php foreach (['momento' => 'centro_cur_moment', 'justificacion' => 'centro_cur_reason',
                                                                'advertencias' => 'centro_cur_warnings'] as $campoCur => $etqCur): ?>
                                                            <label class="form-label mb-0"><?php echo get_string($etqCur, 'local_chatbot'); ?></label>
                                                            <textarea name="<?php echo $campoCur; ?>" rows="2" class="form-control form-control-sm mb-2"><?php
                                                                echo s((string) ($op[$campoCur] ?? '')); ?></textarea>
                                                        <?php endforeach; ?>
                                                        <button type="submit" class="btn btn-primary btn-sm"><?php
                                                            echo get_string('centro_web_save', 'local_chatbot'); ?></button>
                                                    </form>
                                                </details>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                    </ol>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- 10.4 AJUSTE POR SECCION O EDICION MANUAL -->
                        <p class="fw-bold mb-2"><?php echo s(centro_recursos::PREGUNTA_REVISION); ?></p>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <form method="post" action="<?php echo $recursourl->out(false); ?>" class="border rounded p-3 h-100">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="action" value="ajustar_material">
                                    <label class="form-label fw-bold small" for="ajuste_material"><?php echo get_string('centro_adjust_title', 'local_chatbot'); ?></label>
                                    <textarea class="form-control form-control-sm mb-2" id="ajuste_material" name="ajuste_material" rows="3"
                                              placeholder="<?php echo get_string('centro_adjust_ph', 'local_chatbot'); ?>"></textarea>
                                    <button type="submit" class="btn btn-outline-primary btn-sm" data-espera="<?php echo get_string('centro_adjusting', 'local_chatbot'); ?>"><?php echo get_string('centro_adjust_btn', 'local_chatbot'); ?></button>
                                </form>
                            </div>
                            <div class="col-md-6">
                                <?php if ($bloquesweb): ?>
                                <div class="border rounded p-3 h-100 small text-muted">
                                    <div class="fw-bold text-body mb-1"><?php echo get_string('centro_edit_title', 'local_chatbot'); ?></div>
                                    <i class="fa fa-lock me-1"></i><?php echo get_string('centro_web_edit_locked', 'local_chatbot', count($bloquesweb)); ?>
                                </div>
                                <?php else: ?>
                                <details class="border rounded p-3 h-100">
                                    <summary class="fw-bold small" style="cursor:pointer;"><?php echo get_string('centro_edit_title', 'local_chatbot'); ?></summary>
                                    <form method="post" action="<?php echo $recursourl->out(false); ?>" class="mt-2">
                                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                        <input type="hidden" name="action" value="guardar_edicion">
                                        <textarea class="form-control form-control-sm mb-2 font-monospace" name="contenido_edit" rows="6"><?php echo s((string) $recActual->contenido); ?></textarea>
                                        <button type="submit" class="btn btn-outline-secondary btn-sm"><?php echo get_string('centro_edit_save', 'local_chatbot'); ?></button>
                                    </form>
                                </details>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- APROBACION ETAPA 11 -->
                        <form method="post" action="<?php echo $recursourl->out(false); ?>" class="d-flex flex-wrap gap-2 align-items-center border-top pt-3">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="action" value="aprobar_material">
                            <?php if (centro_recursos::can_approve((string) $recActual->estado)): ?>
                                <button type="submit" class="btn btn-success">
                                    <?php echo get_string('centro_approve', 'local_chatbot', ((int) $recActual->version) + 1); ?>
                                </button>
                            <?php else: ?>
                                <div class="alert alert-success mb-0 w-100" role="status"><?php echo get_string('centro_delivered_note',
                                    'local_chatbot', (int) $recActual->version); ?></div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

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

<script>
(function() {
    var copiar = function(btn, texto) {
        if (!navigator.clipboard) {
            return;
        }
        navigator.clipboard.writeText(texto).then(function() {
            var orig = btn.textContent;
            btn.textContent = <?php echo json_encode(get_string('centro_copied', 'local_chatbot')); ?>;
            window.setTimeout(function() { btn.textContent = orig; }, 1800);
        });
    };
    var bTxt = document.getElementById('cr-copy-txt');
    var vista = document.getElementById('cr-material-vista');
    if (bTxt && vista) {
        bTxt.addEventListener('click', function() { copiar(bTxt, vista.innerText || ''); });
    }
    var bHtml = document.getElementById('cr-copy-html');
    var fuente = document.getElementById('cr-html-fuente');
    if (bHtml && fuente) {
        bHtml.addEventListener('click', function() { copiar(bHtml, fuente.value || ''); });
    }

    var caja = document.getElementById('centro-generando');
    if (!caja) {
        return;
    }
    var id = caja.getAttribute('data-id');
    var tipo = caja.getAttribute('data-tipo');
    var url = caja.getAttribute('data-url');
    var actEjec = tipo === 'plan' ? 'ejecutar_plan' : 'ejecutar_rec';
    var actEst = tipo === 'plan' ? 'estado_plan' : 'estado_rec';
    var terminar = function(datos) {
        if (datos && (datos.estado === 'listo' || datos.estado === 'error')) {
            window.location.replace(caja.getAttribute('data-volver'));
            return true;
        }
        return false;
    };
    var revisar = function() {
        fetch(url + '&action=' + actEst + '&id=' + encodeURIComponent(id), {credentials: 'same-origin'})
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
        cuerpo.append('action', actEjec);
        cuerpo.append('id', id);
        cuerpo.append('sesskey', caja.getAttribute('data-sesskey'));
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

