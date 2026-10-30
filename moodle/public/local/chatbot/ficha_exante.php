<?php
/**
 * Modulo 2, Fase 1: ficha de diagnostico (evaluacion ex-ante) y su validacion con el checklist.
 *
 * Pedido de Interfases del 21-10-2026: la persona completa la ficha, adjunta si quiere el Plan
 * Formativo y el Perfil Ocupacional y pulsa «Guardar y validar». El informe (checklist por item,
 * condicion y propuestas de mejora) aparece en esta misma pagina, y con Aprobado o Aprobado con
 * observaciones se habilita el boton a la Fase 2. La logica vive en classes/exante.php.
 *
 * La validacion corre en una segunda llamada (action=ejecutar) que la pagina dispara sola y luego
 * consulta: con los adjuntos el modelo puede tardar mas que el tiempo que el servidor web espera
 * una respuesta, y asi el informe se guarda igual aunque esa llamada se corte.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_chatbot\exante;

$courseid = required_param('courseid', PARAM_INT);
if ((int) $courseid === (int) SITEID) {
    throw new moodle_exception('invalidcourseid');
}
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/chatbot:use', $context);

$pageurl = new moodle_url('/local/chatbot/ficha_exante.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('exante_title', 'local_chatbot'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('exante_title', 'local_chatbot'));
$PAGE->requires->js(new moodle_url('/local/chatbot/js/espera.js'), true);

if (!\local_chatbot\ux::on('fichaexante')) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('exante_disabled', 'local_chatbot'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$action = optional_param('action', '', PARAM_ALPHA);

// --- Llamadas de la propia pagina mientras se valida ---
if ($action === 'ejecutar' || $action === 'estado') {
    $valid = required_param('id', PARAM_INT);
    if ($action === 'ejecutar') {
        require_sesskey();
        // Sin cerrar la sesion, las consultas de estado quedarian esperando a que termine.
        \core\session\manager::write_close();
        ignore_user_abort(true);
        core_php_time_limit::raise(300);
        $estado = exante::run_validation($valid, $USER->id);
    } else {
        $estado = $DB->get_field('local_chatbot_exante_val', 'estado', ['id' => $valid, 'userid' => $USER->id]);
        if ($estado === exante::PROCESANDO) {
            // latest_validation da por caida la que lleva demasiado tiempo.
            $ultima = exante::latest_validation($USER->id, $courseid);
            $estado = ($ultima && (int) $ultima->id === $valid) ? $ultima->estado : $estado;
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['estado' => $estado ?: exante::ERROR]);
    exit;
}

// --- Varios disenos: abrir uno guardado o empezar otro (el modulo se usa una y otra vez) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['abrir', 'nuevo', 'borrar'], true)) {
    require_sesskey();
    if ($action === 'borrar') {
        // Uno a la vez: quien lo pide elige cual se va y se queda con los que le sirven.
        $resultado = exante::delete_ficha($USER->id, $courseid, required_param('id', PARAM_INT));
        $mensajes = [exante::DELETED => ['designs_deleted', \core\output\notification::NOTIFY_SUCCESS],
            exante::VALIDATING => ['designs_delete_busy', \core\output\notification::NOTIFY_WARNING],
            exante::MISSING => ['designs_delete_missing', \core\output\notification::NOTIFY_WARNING]];
        redirect($pageurl, get_string($mensajes[$resultado][0], 'local_chatbot'), null, $mensajes[$resultado][1]);
    }
    if ($action === 'abrir') {
        exante::activate($USER->id, $courseid, required_param('id', PARAM_INT));
        redirect($pageurl, get_string('designs_opened', 'local_chatbot'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    exante::new_ficha($USER->id, $courseid, (bool) optional_param('copiar', 0, PARAM_BOOL));
    redirect($pageurl, get_string('designs_created', 'local_chatbot'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// --- Guardar (y validar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['guardar', 'validar'], true)) {
    require_sesskey();
    $raw = [];
    foreach (exante::field_keys() as $clave => $tipo) {
        $raw[$clave] = $tipo === exante::T_VARIAS
            ? optional_param_array($clave, [], PARAM_ALPHANUMEXT)
            : optional_param($clave, '', PARAM_TEXT);
    }
    $data = exante::clean($raw);
    $fichaid = exante::save_ficha($USER->id, $courseid, $data);

    $avisos = [];
    foreach (exante::ADJUNTOS as $area => $etiqueta) {
        if (optional_param('quitar_' . $area, 0, PARAM_BOOL)) {
            exante::set_attachment($fichaid, $area, null);
        }
        $subido = $_FILES[$area] ?? null;
        if (!$subido || ($subido['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $nombre = clean_param((string) $subido['name'], PARAM_FILE);
        // El tipo se comprueba por el contenido: el que declara el navegador se puede falsear.
        $mime = $subido['error'] === UPLOAD_ERR_OK ? (string) mime_content_type($subido['tmp_name']) : '';
        $tipoarchivo = exante::attachment_kind($mime, $nombre);
        if ($subido['error'] !== UPLOAD_ERR_OK || $subido['size'] > exante::ADJUNTO_MAX || $tipoarchivo === null
                || ($tipoarchivo === 'docx' && exante::docx_text($subido['tmp_name']) === null)) {
            $avisos[] = get_string('exante_attach_invalid', 'local_chatbot', s($nombre));
            continue;
        }
        exante::set_attachment($fichaid, $area, $subido['tmp_name'], $nombre);
    }

    $tipo = \core\output\notification::NOTIFY_SUCCESS;
    $mensaje = get_string('exante_saved', 'local_chatbot');
    $destino = $pageurl;
    if ($action === 'validar') {
        $falta = exante::missing_inputs($data);
        if ($falta) {
            // Control de entrada del Prompt Maestro: no se avanza y se pide lo que falta, en concreto.
            $tipo = \core\output\notification::NOTIFY_WARNING;
            $mensaje = get_string('exante_missing', 'local_chatbot', implode(' · ', $falta));
        } else if (!\local_chatbot\ai\provider_factory::is_configured()) {
            $tipo = \core\output\notification::NOTIFY_ERROR;
            $mensaje = get_string('exante_error_sinclave', 'local_chatbot');
        } else {
            exante::start_validation(exante::get_ficha($USER->id, $courseid));
            $mensaje = '';
            $destino = new moodle_url($pageurl, [], 'informe');
            // Avisos con reglas fijas: se muestran y la validacion sigue (pedido del 29-10-2026).
            $avisosform = \local_chatbot\ux::on('avisosficha') ? exante::form_warnings($data) : [];
            if ($avisosform) {
                $tipo = \core\output\notification::NOTIFY_WARNING;
                $mensaje = get_string('exante_warn_validating', 'local_chatbot', implode(' · ', $avisosform));
            }
        }
    }
    if ($avisos) {
        $tipo = \core\output\notification::NOTIFY_WARNING;
        $mensaje = trim($mensaje . ' ' . implode(' ', $avisos));
    }
    redirect($destino, $mensaje, null, $tipo);
}

// --- Pagina ---
$ficha = exante::get_ficha($USER->id, $courseid);
$data = $ficha ? $ficha->data : exante::clean([]);
$adjuntos = $ficha ? exante::attachments((int) $ficha->id) : [];
$val = exante::latest_validation($USER->id, $courseid);
$vigente = $val && $ficha && $val->huella === exante::ficha_fingerprint($ficha);
$listo = $val && $val->estado === exante::LISTO && is_array($val->informe);
$avanza = $listo && $vigente && exante::can_advance($val->condicion);

// Las fases del Modulo 2 son secciones del curso que se llaman «Fase 1: ...», «Fase 2: ...».
$nombres = [];
foreach (get_fast_modinfo($course)->get_section_info_all() as $seccion) {
    $nombres[$seccion->section] = get_section_name($course, $seccion);
}
$fase1 = exante::phase_section($nombres, 1);
$fase2 = exante::phase_section($nombres, 2);
$urlcurso = new moodle_url('/course/view.php', ['id' => $courseid]);
// course_get_url respeta el formato del curso (pestanas en el Modulo 2); sin seccion, la portada.
$urlfase1 = ($fase1 !== null ? course_get_url($course, $fase1) : null) ?: $urlcurso;
$urlfase2 = ($fase2 !== null ? course_get_url($course, $fase2) : null) ?: $urlcurso;

$estadoclase = [exante::CUMPLE => 'success', exante::PARCIAL => 'warning', exante::NO_CUMPLE => 'danger'];
$condclase = [exante::APROBADO => 'success', exante::OBSERVACIONES => 'warning', exante::NO_APROBADO => 'danger'];

/**
 * Pinta un campo de la ficha.
 *
 * @param array $campo
 * @param array $data
 * @return void
 */
function local_chatbot_exante_campo(array $campo, array $data, bool $oculto = false): void {
    $id = $campo['id'];
    $rotulo = (!empty($campo['num']) ? $campo['num'] . '. ' : '') . $campo['label'];
    // Los criterios agregados llevan su marca; los que aun no se agregan quedan ocultos hasta pulsar «Agregar».
    echo '<div class="mb-3 pb-3 border-bottom"' . (!empty($campo['adicional']) ? ' data-criterio-adicional="1"' : '')
        . ($oculto ? ' hidden' : '') . '>';
    if (!empty($campo['antes'])) {
        echo '<p class="small text-muted mb-3">' . s($campo['antes']) . '</p>';
    }
    if (in_array($campo['tipo'], [exante::T_UNA, exante::T_VARIAS], true)) {
        $varias = $campo['tipo'] === exante::T_VARIAS;
        echo '<fieldset><legend class="fs-6 fw-medium mb-1">' . s($rotulo) . '</legend>';
        if (!empty($campo['ayuda'])) {
            echo '<p class="small text-muted mb-2">' . s($campo['ayuda']) . '</p>';
        }
        $elegidos = (array) ($data[$id] ?? []);
        echo '<div class="d-flex flex-column" style="gap: 6px;">';
        foreach ($campo['opciones'] as $k => $texto) {
            $domid = 'f_' . $id . '_' . $k;
            echo '<div class="form-check"><input class="form-check-input" type="' . ($varias ? 'checkbox' : 'radio')
                . '" name="' . $id . ($varias ? '[]' : '') . '" id="' . $domid . '" value="' . s($k) . '"'
                . (in_array($k, $elegidos, true) ? ' checked' : '') . '>';
            echo '<label class="form-check-label small" for="' . $domid . '">' . s($texto) . '</label>';
            if (!empty($campo['otro']) && $k === $campo['otro']) {
                echo '<input type="text" class="form-control form-control-sm mt-1" style="max-width: 420px;" name="'
                    . $id . '_otro" maxlength="' . exante::LARGO_TEXTO . '" aria-label="' . s($texto) . '" placeholder="'
                    . s(get_string('exante_other_placeholder', 'local_chatbot')) . '" value="'
                    . s($data[$id . '_otro'] ?? '') . '">';
            }
            echo '</div>';
        }
        echo '</div></fieldset></div>';
        return;
    }

    $domid = 'f_' . $id;
    // Las observaciones son opcionales; las que no lo dicen en el texto de la ficha lo muestran aparte.
    $opcional = !empty($campo['opcional']) && \core_text::strpos(\core_text::strtolower($rotulo), 'opcional') === false
        ? ' <span class="fw-normal text-muted">(' . get_string('exante_optional', 'local_chatbot') . ')</span>' : '';
    echo '<label class="form-label fw-medium mb-1" for="' . $domid . '">' . s($rotulo) . $opcional . '</label>';
    if (!empty($campo['ayuda'])) {
        echo '<p class="small text-muted mb-2">' . s($campo['ayuda']) . '</p>';
    }
    $valor = s($data[$id] ?? '');
    if ($campo['tipo'] === exante::T_PARRAFO) {
        echo '<textarea class="form-control" id="' . $domid . '" name="' . $id . '" rows="3" maxlength="'
            . exante::LARGO_PARRAFO . '">' . $valor . '</textarea>';
    } else if ($campo['tipo'] === exante::T_NUMERO) {
        echo '<input type="number" min="0" step="1" class="form-control" style="max-width: 200px;" id="' . $domid
            . '" name="' . $id . '" value="' . $valor . '">';
    } else {
        echo '<input type="text" class="form-control" id="' . $domid . '" name="' . $id . '" maxlength="'
            . exante::LARGO_TEXTO . '" value="' . $valor . '">';
    }
    echo '</div>';
}

echo $OUTPUT->header();
?>

<div class="container-fluid" style="max-width: 900px;">

    <h3 class="mb-3"><?php echo get_string('exante_title', 'local_chatbot'); ?></h3>

    <?php // Las tres fases del modulo, con el mismo estilo que la ruta del Modulo 1. ?>
    <?php echo \local_chatbot\ruta_m2::render($USER->id, $courseid, 1); ?>

    <?php // Tus disenos: cada experiencia es una ficha con su validacion y sus etapas; una esta en uso. ?>
    <?php $disenos = exante::list_fichas($USER->id, $courseid); ?>
    <?php if ($disenos): ?>
        <div class="card shadow-sm border-0 mb-4" id="disenos">
            <div class="card-body">
                <h5 class="mb-1"><?php echo get_string('designs_title', 'local_chatbot'); ?></h5>
                <p class="small text-muted mb-3"><?php echo get_string('designs_help', 'local_chatbot'); ?></p>
                <ul class="list-unstyled mb-3">
                    <?php foreach ($disenos as $d):
                        $estado = \local_chatbot\ruta_m2::state_for($d);
                        $nombre = exante::design_label($d->data, $estado['experiencia']);
                        [$clave, $a] = \local_chatbot\ruta_m2::design_status($estado);
                        $enuso = (int) $d->id === (int) $ficha->id; ?>
                        <li class="d-flex flex-wrap align-items-center gap-2 border-bottom py-2">
                            <div class="flex-grow-1" style="min-width: 220px;">
                                <div class="fw-medium"><?php echo $nombre !== '' ? s($nombre) : get_string('designs_untitled', 'local_chatbot'); ?></div>
                                <div class="small text-muted"><?php echo get_string($clave, 'local_chatbot', $a); ?> ·
                                    <?php echo userdate($d->timemodified, get_string('strftimedatetimeshort', 'langconfig')); ?></div>
                            </div>
                            <?php if ($enuso): ?>
                                <span class="badge text-bg-primary"><?php echo get_string('designs_current', 'local_chatbot'); ?></span>
                            <?php else: ?>
                                <form method="post" action="<?php echo $pageurl->out(false); ?>" class="m-0">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $d->id; ?>">
                                    <button type="submit" name="action" value="abrir" class="btn btn-sm btn-outline-primary"><?php
                                        echo get_string('designs_open', 'local_chatbot'); ?></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?php echo $pageurl->out(false); ?>" class="m-0"
                                  onsubmit="return confirm(this.dataset.confirm);"
                                  data-confirm="<?php echo s(get_string('designs_delete_confirm', 'local_chatbot')); ?>">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="id" value="<?php echo (int) $d->id; ?>">
                                <button type="submit" name="action" value="borrar" class="btn btn-sm btn-outline-danger"
                                        aria-label="<?php echo s(get_string('designs_delete_for', 'local_chatbot',
                                            $nombre !== '' ? $nombre : get_string('designs_untitled', 'local_chatbot'))); ?>"><i
                                        class="fa fa-trash me-1"></i><?php echo get_string('designs_delete', 'local_chatbot'); ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" action="<?php echo $pageurl->out(false); ?>" class="d-flex flex-wrap gap-2 m-0">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <button type="submit" name="action" value="nuevo" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i><?php
                        echo get_string('designs_new', 'local_chatbot'); ?></button>
                    <button type="submit" name="action" value="nuevo" class="btn btn-outline-primary btn-sm"
                            onclick="this.form.copiar.value = 1;"><i class="fa fa-copy me-1"></i><?php
                        echo get_string('designs_new_copy', 'local_chatbot'); ?></button>
                    <input type="hidden" name="copiar" value="0">
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="alert alert-info">
        <strong><?php echo get_string('exante_intro_title', 'local_chatbot'); ?>.</strong>
        <?php echo get_string('exante_intro', 'local_chatbot'); ?>
        <?php if ($val): ?>
            <div class="mt-2"><a class="chatbot-gatebtn" href="#informe"><?php
                echo get_string('exante_report_link', 'local_chatbot'); ?></a></div>
        <?php endif; ?>
    </div>

    <form method="post" action="<?php echo $pageurl->out(false); ?>" enctype="multipart/form-data" id="exante-form">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

        <?php foreach (exante::sections() as $n => $seccion): ?>
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <h5 class="mb-3 fw-bold text-dark border-bottom pb-2"><?php echo $n . '. ' . s($seccion['titulo']); ?></h5>
                    <?php if ($n === 2): ?>
                        <p class="small text-muted"><?php echo get_string('exante_required_note', 'local_chatbot'); ?></p>
                    <?php endif; ?>
                    <?php
                    // Criterios: los 3 de partida y los agregados hasta el ultimo con texto; el resto, ocultos.
                    $ultimocriterio = exante::CRITERIOS_MIN;
                    for ($i = exante::CRITERIOS_MAX; $i > exante::CRITERIOS_MIN; $i--) {
                        if (trim((string) ($data['criterio' . $i] ?? '')) !== '') {
                            $ultimocriterio = $i;
                            break;
                        }
                    }
                    foreach ($seccion['campos'] as $campo) {
                        $numcriterio = strpos($campo['id'], 'criterio') === 0 ? (int) substr($campo['id'], 8) : 0;
                        local_chatbot_exante_campo($campo, $data, $numcriterio > $ultimocriterio);
                        if ($numcriterio === exante::CRITERIOS_MAX) {
                            echo '<div class="mb-3 pb-3 border-bottom"><button type="button" class="btn btn-outline-primary btn-sm"'
                                . ' id="exante-agregar-criterio"' . ($ultimocriterio >= exante::CRITERIOS_MAX ? ' hidden' : '') . '>'
                                . '<i class="fa fa-plus me-1" aria-hidden="true"></i>'
                                . get_string('exante_add_criterion', 'local_chatbot') . '</button></div>';
                        }
                    } ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <h5 class="mb-2 fw-bold text-dark border-bottom pb-2"><?php
                    echo get_string('exante_attachments_title', 'local_chatbot'); ?></h5>
                <p class="small text-muted"><?php echo get_string('exante_attachments_help', 'local_chatbot'); ?></p>
                <?php foreach (exante::ADJUNTOS as $area => $etiqueta): ?>
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="f_<?php echo $area; ?>"><?php
                            echo get_string('exante_attach_' . substr($area, strlen('exante_')), 'local_chatbot'); ?></label>
                        <?php if (isset($adjuntos[$area])):
                            $archivo = $adjuntos[$area];
                            $enlace = moodle_url::make_pluginfile_url($archivo->get_contextid(), 'local_chatbot', $area,
                                $archivo->get_itemid(), '/', $archivo->get_filename(), true); ?>
                            <p class="small mb-1"><i class="fa fa-paperclip me-1"></i><?php
                                echo get_string('exante_attach_current', 'local_chatbot',
                                    html_writer::link($enlace, s($archivo->get_filename()))); ?></p>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="quitar_<?php echo $area; ?>"
                                       id="quitar_<?php echo $area; ?>" value="1">
                                <label class="form-check-label small" for="quitar_<?php echo $area; ?>"><?php
                                    echo get_string('exante_attach_remove', 'local_chatbot'); ?></label>
                            </div>
                        <?php endif; ?>
                        <input type="file" class="form-control" id="f_<?php echo $area; ?>" name="<?php echo $area; ?>"
                               accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <p class="small mb-2"><i class="fa fa-list-check me-1"></i><a href="<?php echo (new moodle_url('/local/chatbot/checklist_exante.php',
            ['courseid' => $courseid]))->out(false); ?>"><?php echo get_string('autocheck_link', 'local_chatbot'); ?></a></p>
        <p class="small text-muted"><?php echo get_string('exante_privacy', 'local_chatbot'); ?></p>
        <?php $avisosform = \local_chatbot\ux::on('avisosficha') ? exante::form_warnings($data) : []; ?>
        <?php if ($avisosform): ?>
            <div class="alert alert-warning small" role="alert">
                <strong><?php echo get_string('exante_warn_title', 'local_chatbot'); ?></strong>
                <ul class="mb-1">
                    <?php foreach ($avisosform as $aviso): ?><li><?php echo s($aviso); ?></li><?php endforeach; ?>
                </ul>
                <?php echo get_string('exante_warn_note', 'local_chatbot'); ?>
            </div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <button type="submit" name="action" value="validar" class="btn btn-primary px-4 py-2 shadow-sm"
                    data-espera="<?php echo s(get_string('exante_saving', 'local_chatbot')); ?>">
                <i class="fa fa-check-square-o me-1"></i> <?php echo get_string('exante_validate', 'local_chatbot'); ?>
            </button>
            <button type="submit" name="action" value="guardar" class="btn btn-outline-primary px-4 py-2"
                    data-espera="<?php echo s(get_string('exante_saving', 'local_chatbot')); ?>">
                <i class="fa fa-save me-1"></i> <?php echo get_string('exante_save', 'local_chatbot'); ?>
            </button>

        </div>
    </form>

    <div id="informe">
    <?php if ($val && in_array($val->estado, [exante::PENDIENTE, exante::PROCESANDO], true)): ?>
        <div class="card shadow-sm border-0 mb-4" id="exante-validando" role="status"
             data-id="<?php echo (int) $val->id; ?>" data-estado="<?php echo s($val->estado); ?>"
             data-url="<?php echo s($pageurl->out(false)); ?>" data-sesskey="<?php echo sesskey(); ?>"
             data-volver="<?php echo s((new moodle_url($pageurl, ['v' => $val->id], 'informe'))->out(false)); ?>">
            <div class="card-body">
                <h5 class="mb-2"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?php
                    echo get_string('exante_validating', 'local_chatbot'); ?></h5>
                <p class="small text-muted mb-0"><?php echo get_string('exante_validating_long', 'local_chatbot'); ?></p>
            </div>
        </div>
    <?php elseif ($val && $val->estado === exante::ERROR): ?>
        <div class="alert alert-warning">
            <?php echo get_string('exante_error_' . ($val->error ?: 'servicio'), 'local_chatbot'); ?>
            <?php echo get_string('exante_error_retry', 'local_chatbot'); ?>
        </div>
    <?php elseif ($listo): ?>
        <?php $informe = $val->informe; ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 border-bottom pb-2 mb-3">
                    <h4 class="mb-0"><?php echo get_string('exante_report_title', 'local_chatbot'); ?></h4>
                    <span class="small text-muted"><?php echo get_string('exante_report_date', 'local_chatbot',
                        userdate($val->timemodified, get_string('strftimedatetimeshort', 'langconfig'))); ?></span>
                </div>

                <?php if (!$vigente): ?>
                    <div class="alert alert-warning"><?php echo get_string('exante_report_stale', 'local_chatbot'); ?></div>
                <?php endif; ?>

                <h5 class="mb-2">a) <?php echo get_string('exante_report_checklist', 'local_chatbot'); ?></h5>
                <div class="table-responsive mb-4">
                <table class="table table-sm align-middle mb-0 chatbot-exante-checklist">
                    <thead>
                        <tr>
                            <th><?php echo get_string('exante_report_item', 'local_chatbot'); ?></th>
                            <th style="width: 190px;"><?php echo get_string('exante_report_eval', 'local_chatbot'); ?></th>
                            <th><?php echo get_string('exante_report_obs', 'local_chatbot'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (exante::checklist() as $n => $seccion): ?>
                        <tr class="table-light"><th colspan="3" class="small text-uppercase"><?php
                            echo $n . '. ' . s($seccion['titulo']); ?></th></tr>
                        <?php foreach ($seccion['items'] as $id => $texto):
                            $item = $informe['items'][$id] ?? ['estado' => exante::NO_CUMPLE, 'observacion' => '']; ?>
                            <tr>
                                <td class="small"><?php echo s($texto); ?></td>
                                <td><span class="badge text-bg-<?php echo $estadoclase[$item['estado']]; ?>"><?php
                                    echo get_string('exante_status_' . $item['estado'], 'local_chatbot'); ?></span></td>
                                <td class="small"><?php echo s($item['observacion']); ?>
                                    <?php if (!empty($item['propuesta'])): ?>
                                        <div class="mt-1 text-success-emphasis"><strong><?php
                                            echo get_string('exante_report_howto', 'local_chatbot'); ?></strong> <?php
                                            echo s($item['propuesta']); ?></div>
                                    <?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <h5 class="mb-2">b) <?php echo get_string('exante_report_condition', 'local_chatbot'); ?></h5>
                <div class="alert alert-<?php echo $condclase[$val->condicion]; ?> mb-4">
                    <strong><?php echo get_string('exante_cond_' . $val->condicion, 'local_chatbot'); ?></strong>.
                    <?php echo get_string('exante_cond_' . $val->condicion . '_desc', 'local_chatbot'); ?>
                </div>
                <?php if (!empty($informe['motivo'])): ?>
                    <div class="alert alert-danger mb-4">
                        <h6 class="mb-2"><?php echo get_string('exante_report_mustfix', 'local_chatbot'); ?></h6>
                        <p class="mb-1"><strong><?php
                            echo get_string('exante_reason_' . $informe['motivo']['codigo'], 'local_chatbot'); ?></strong></p>
                        <p class="small mb-1"><?php echo get_string('exante_report_quote', 'local_chatbot'); ?> «<?php
                            echo s($informe['motivo']['cita']); ?>»</p>
                        <?php if (!empty($informe['motivo']['corregir'])): ?>
                            <p class="small mb-0"><strong><?php echo get_string('exante_report_howto', 'local_chatbot'); ?></strong> <?php
                                echo s($informe['motivo']['corregir']); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <h5 class="mb-2">c) <?php echo get_string('exante_report_suggest', 'local_chatbot'); ?></h5>
                <?php if (!empty($informe['debilidades'])): ?>
                    <h6 class="mt-3"><?php echo get_string('exante_report_weak', 'local_chatbot'); ?></h6>
                    <ul class="small">
                        <?php foreach ($informe['debilidades'] as $texto): ?><li><?php echo s($texto); ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (!empty($informe['sugerencias'])): ?>
                    <h6 class="mt-3"><?php echo get_string('exante_report_proposals', 'local_chatbot'); ?></h6>
                    <ul class="small">
                        <?php foreach ($informe['sugerencias'] as $texto): ?><li><?php echo s($texto); ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <p class="small text-muted mb-1"><?php echo empty($informe['adjuntos'])
                    ? get_string('exante_report_nodocs', 'local_chatbot')
                    : get_string('exante_report_docs', 'local_chatbot', s(implode(', ', array_map(
                        static function($etiqueta, $nombre) {
                            return $etiqueta . ' (' . $nombre . ')';
                        }, array_keys($informe['adjuntos']), $informe['adjuntos'])))); ?></p>
                <p class="small text-muted mb-0"><?php echo get_string('exante_report_note', 'local_chatbot'); ?></p>
            </div>
        </div>

        <?php if (!$avanza && $vigente): ?>
            <div class="alert alert-info"><?php echo get_string('exante_blocked', 'local_chatbot'); ?></div>
        <?php endif; ?>
    <?php endif; ?>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <?php if ($avanza): ?>
            <a class="chatbot-backbtn chatbot-nextphase" href="<?php echo $urlfase2->out(false); ?>">
                <?php echo get_string('exante_next', 'local_chatbot'); ?>
                <span class="chatbot-backbtn-arrow" aria-hidden="true">&rarr;</span>
            </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?php echo $urlfase1->out(false); ?>"><?php
            echo get_string('exante_back', 'local_chatbot'); ?></a>
    </div>
</div>

<script>
(function() {
    // «Agregar criterio de evaluación»: muestra el siguiente criterio oculto, de a uno.
    var agregar = document.getElementById('exante-agregar-criterio');
    if (!agregar) {
        return;
    }
    agregar.addEventListener('click', function() {
        var ocultos = document.querySelectorAll('[data-criterio-adicional][hidden]');
        if (!ocultos.length) {
            return;
        }
        ocultos[0].hidden = false;
        var texto = ocultos[0].querySelector('textarea');
        if (texto) {
            texto.focus();
        }
        if (ocultos.length === 1) {
            agregar.hidden = true;
        }
    });
})();
(function() {
    var caja = document.getElementById('exante-validando');
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
        // Si el servidor web corta esta llamada antes de que el modelo responda, la validacion sigue
        // en el servidor y la consulta de estado la recoge.
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
