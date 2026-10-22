<?php
/**
 * Modulo 2, Fase 1: el checklist de validacion para aplicarlo en Moodle, sin descargar el PDF.
 *
 * A pedido del usuario (21-10-2026): «Consultar y descargar el checklist» lleva a esta pagina, donde la persona
 * marca cada item del checklist sobre su propia ficha antes de validarla (✔ / ⚠ / ❌). El resumen aplica la misma
 * regla de decision que el asistente; si la ficha ya se valido, se muestra al lado lo que dijo el asistente.
 * No llama a la IA. Las marcas se guardan como preferencia de la persona, por curso.
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

$pageurl = new moodle_url('/local/chatbot/checklist_exante.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('autocheck_title', 'local_chatbot'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('autocheck_title', 'local_chatbot'));
$PAGE->requires->js(new moodle_url('/local/chatbot/js/espera.js'), true);

if (!\local_chatbot\ux::on('fichaexante')) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('exante_disabled', 'local_chatbot'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$preferencia = 'local_chatbot_autocheck_' . $courseid;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $raw = [];
    foreach (exante::checklist_ids() as $id) {
        $raw[$id] = optional_param($id, '', PARAM_ALPHAEXT);
    }
    if (optional_param('limpiar', 0, PARAM_BOOL)) {
        unset_user_preference($preferencia);
    } else {
        set_user_preference($preferencia, json_encode(exante::self_check_clean($raw)));
    }
    redirect(new moodle_url($pageurl, [], 'resumen'), get_string('autocheck_saved', 'local_chatbot'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$marcas = exante::self_check_clean((array) json_decode((string) get_user_preferences($preferencia, ''), true));
$resumen = exante::self_check_summary($marcas);

// Lo que dijo el asistente, si la ficha ya se valido.
$val = exante::latest_validation($USER->id, $courseid);
$asistente = ($val && $val->estado === exante::LISTO && is_array($val->informe)) ? $val->informe['items'] : [];

// El PDF del checklist sigue disponible para quien quiera descargarlo.
$pdf = null;
$nombres = [];
foreach (get_fast_modinfo($course)->get_instances_of('resource') as $cm) {
    $nombres[$cm->id] = $cm->name;
}
$recursos = \local_chatbot\modulo2_setup::match_resources($nombres);
if (isset($recursos['checklist'])) {
    $pdf = new moodle_url('/mod/resource/view.php', ['id' => $recursos['checklist']]);
}

$opciones = [exante::CUMPLE => 'success', exante::PARCIAL => 'warning', exante::NO_CUMPLE => 'danger'];
$condclase = [exante::APROBADO => 'success', exante::OBSERVACIONES => 'warning', exante::NO_APROBADO => 'danger'];

echo $OUTPUT->header();
?>

<div class="container-fluid" style="max-width: 980px;">

    <h3 class="mb-3"><?php echo get_string('autocheck_title', 'local_chatbot'); ?></h3>

    <?php echo \local_chatbot\ruta_m2::render($USER->id, $courseid, 1); ?>

    <div class="alert alert-info">
        <?php echo get_string('autocheck_intro', 'local_chatbot'); ?>
        <?php if ($pdf): ?>
            <div class="mt-2 small"><a href="<?php echo $pdf->out(false); ?>"><i class="fa fa-file-pdf-o me-1"></i><?php
                echo get_string('autocheck_pdf', 'local_chatbot'); ?></a></div>
        <?php endif; ?>
    </div>

    <form method="post" action="<?php echo $pageurl->out(false); ?>">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

        <?php foreach (exante::checklist() as $n => $seccion): ?>
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <h5 class="mb-3 fw-bold text-dark border-bottom pb-2"><?php echo $n . '. ' . s($seccion['titulo']); ?></h5>
                    <?php foreach ($seccion['items'] as $id => $texto): ?>
                        <fieldset class="chatbot-autocheck-item mb-3 pb-3 border-bottom">
                            <legend class="fs-6 fw-medium mb-2"><?php echo s($texto); ?>
                                <?php if (in_array($id, exante::CRITICOS, true)): ?>
                                    <span class="badge text-bg-light border ms-1" title="<?php
                                        echo s(get_string('autocheck_critical_help', 'local_chatbot')); ?>"><?php
                                        echo get_string('autocheck_critical', 'local_chatbot'); ?></span>
                                <?php endif; ?>
                            </legend>
                            <div class="d-flex flex-wrap align-items-center gap-3">
                                <?php foreach ($opciones as $valor => $color): $domid = $id . '_' . $valor; ?>
                                    <div class="form-check form-check-inline me-0">
                                        <input class="form-check-input" type="radio" name="<?php echo $id; ?>" id="<?php echo $domid; ?>"
                                               value="<?php echo $valor; ?>"<?php echo ($marcas[$id] ?? '') === $valor ? ' checked' : ''; ?>>
                                        <label class="form-check-label small" for="<?php echo $domid; ?>"><?php
                                            echo get_string('exante_status_' . $valor, 'local_chatbot'); ?></label>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (isset($asistente[$id])): ?>
                                    <span class="small text-muted ms-md-auto"><?php echo get_string('autocheck_assistant', 'local_chatbot'); ?>
                                        <span class="badge text-bg-<?php echo $opciones[$asistente[$id]['estado']]; ?>"
                                              title="<?php echo s($asistente[$id]['observacion']); ?>"><?php
                                            echo get_string('exante_status_' . $asistente[$id]['estado'], 'local_chatbot'); ?></span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <button type="submit" class="btn btn-primary px-4" data-espera="<?php echo s(get_string('exante_saving', 'local_chatbot')); ?>">
                <i class="fa fa-save me-1"></i> <?php echo get_string('autocheck_save', 'local_chatbot'); ?>
            </button>
            <button type="submit" name="limpiar" value="1" class="btn btn-outline-secondary"><?php
                echo get_string('autocheck_clear', 'local_chatbot'); ?></button>
        </div>
    </form>

    <div id="resumen" class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <h5 class="mb-2"><?php echo get_string('autocheck_summary', 'local_chatbot'); ?></h5>
            <p class="mb-2">
                <span class="badge text-bg-success"><?php echo get_string('exante_status_cumple', 'local_chatbot'); ?>: <?php echo $resumen['cumple']; ?></span>
                <span class="badge text-bg-warning"><?php echo get_string('exante_status_parcial', 'local_chatbot'); ?>: <?php echo $resumen['parcial']; ?></span>
                <span class="badge text-bg-danger"><?php echo get_string('exante_status_no_cumple', 'local_chatbot'); ?>: <?php echo $resumen['no_cumple']; ?></span>
                <span class="badge text-bg-light border"><?php echo get_string('autocheck_unmarked', 'local_chatbot', $resumen['sin_marcar']); ?></span>
            </p>
            <?php if ($resumen['condicion']): ?>
                <div class="alert alert-<?php echo $condclase[$resumen['condicion']]; ?> mb-2">
                    <?php echo get_string('autocheck_condition', 'local_chatbot',
                        get_string('exante_cond_' . $resumen['condicion'], 'local_chatbot')); ?>
                    <?php echo get_string('exante_cond_' . $resumen['condicion'] . '_desc', 'local_chatbot'); ?>
                </div>
            <?php else: ?>
                <p class="small text-muted mb-2"><?php echo get_string('autocheck_incomplete', 'local_chatbot'); ?></p>
            <?php endif; ?>
            <?php if ($resumen['criticos']): ?>
                <p class="small mb-2"><?php echo get_string('autocheck_critical_warning', 'local_chatbot'); ?></p>
            <?php endif; ?>
            <p class="small text-muted mb-0"><?php echo get_string('autocheck_note', 'local_chatbot'); ?></p>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <a class="chatbot-backbtn chatbot-nextphase" href="<?php echo (new moodle_url('/local/chatbot/ficha_exante.php',
            ['courseid' => $courseid]))->out(false); ?>"><?php echo get_string('autocheck_go_ficha', 'local_chatbot'); ?>
            <span class="chatbot-backbtn-arrow" aria-hidden="true">&rarr;</span></a>
        <?php
        $nombressec = [];
        foreach (get_fast_modinfo($course)->get_section_info_all() as $seccion) {
            $nombressec[$seccion->section] = get_section_name($course, $seccion);
        }
        $fase1 = exante::phase_section($nombressec, 1);
        $urlfase1 = ($fase1 !== null ? course_get_url($course, $fase1) : null) ?: new moodle_url('/course/view.php', ['id' => $courseid]);
        ?>
        <a class="btn btn-outline-secondary" href="<?php echo $urlfase1->out(false); ?>"><?php
            echo get_string('exante_back', 'local_chatbot'); ?></a>
    </div>
</div>

<?php
echo $OUTPUT->footer();
