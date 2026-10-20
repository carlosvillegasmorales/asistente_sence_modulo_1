<?php
/**
 * Proyecto integrador y su evaluacion formativa (componente 4 del Modulo 1).
 *
 * La rubrica se muestra completa antes de que la persona entregue, tal como
 * exige la regla de gobernanza. La evaluacion del Modo C llega en el formato
 * estandar de salida —fortaleza, aspecto a mejorar, por que importa, accion
 * sugerida y vinculo con el PDP— y nunca sustituye una decision humana: cuando
 * la evidencia no alcanza, el asistente lo declara en vez de inventar un
 * veredicto.
 */

require_once(__DIR__ . '/../../config.php');

[$courseid, $context] = \local_chatbot\course_bridge::setup_page('/local/chatbot/proyecto.php',
    'project_title', 'project_heading');

if (!\local_chatbot\profile::has_consent($USER->id, $courseid)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('error_needsconsent', 'local_chatbot'), 'warning');
    echo $OUTPUT->single_button(
        new moodle_url('/local/chatbot/index.php', ['courseid' => $courseid]),
        get_string('action_gotostart', 'local_chatbot'), 'get');
    echo $OUTPUT->footer();
    exit;
}

// El documento condiciona la habilitacion del proyecto a que exista una
// primera version del PDP. El avance por las cuatro unidades se controla con
// las restricciones de acceso de Moodle sobre la seccion que enlaza aqui.
// Con la ruta de 6 etapas (ajustes del cliente del 08-10-2026) el proyecto va antes
// que el PDP, que es opcional: lo que se exige son las etapas anteriores, y eso lo
// controla course_bridge::gate_html() mas abajo.
$hasplan = \local_chatbot\ux::on('route6') || \local_chatbot\pdp::has_active_plan($USER->id, $courseid);

$message = '';
$messagetype = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey() && $hasplan) {
    $action = optional_param('action', 'save', PARAM_ALPHA);

    $data = ['title' => trim(required_param('title', PARAM_TEXT))];
    foreach (\local_chatbot\project::FIELDS as $field) {
        $data[$field] = trim(optional_param($field, '', PARAM_TEXT));
    }

    $projectid = \local_chatbot\project::save($USER->id, $courseid, $data);

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        if (!\local_chatbot\project::save_attachment($projectid,
                $_FILES['attachment']['tmp_name'], $_FILES['attachment']['name'])) {
            $message = get_string('project_error_invalidfile', 'local_chatbot');
            $messagetype = 'danger';
        }
    }

    if ($action === 'submit') {
        $DB->set_field('local_chatbot_project', 'status',
            \local_chatbot\project::STATUS_ENTREGADO, ['id' => $projectid]);
        $result = \local_chatbot\project::evaluate($projectid);
        $message = $result['message'];
        $messagetype = $result['ok'] ? 'success' : 'warning';
    } else {
        $message = get_string('project_saved', 'local_chatbot');
        $messagetype = 'success';
    }
}

$project = \local_chatbot\project::get($USER->id, $courseid);
$criteria = \local_chatbot\project::list_criteria($courseid);
$feedback = $project ? \local_chatbot\project::get_feedback($project->id) : [];

$fieldlabels = [
    'perfilucl' => get_string('field_perfilucl', 'local_chatbot'),
    'aprendizajes' => get_string('field_aprendizajes', 'local_chatbot'),
    'criterios' => get_string('field_criterios', 'local_chatbot'),
    'actividades' => get_string('field_actividades', 'local_chatbot'),
    'evidencias' => get_string('field_evidencias', 'local_chatbot'),
    'instrumentos' => get_string('field_instrumentos', 'local_chatbot'),
    'mejora' => get_string('field_mejora', 'local_chatbot'),
    'autoevaluacion' => get_string('field_autoevaluacion', 'local_chatbot'),
];

// El curso refleja lo que acaba de pasar en el recorrido: barra de progreso,
// restricciones de acceso e informes de finalizacion.
\local_chatbot\course_bridge::sync($USER->id, $courseid);

echo $OUTPUT->header();
?>

<div class="container-fluid" style="max-width: 900px;">

    <?php echo \local_chatbot\course_bridge::route_bar($USER->id, $courseid, 'proyecto'); ?>

    <?php $bloqueo = \local_chatbot\course_bridge::gate_html($USER->id, $courseid, 'proyecto'); ?>
    <?php if ($bloqueo !== ''): ?>
        <?php echo $bloqueo; ?>
</div>
        <?php echo $OUTPUT->footer(); exit; ?>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messagetype; ?>"><?php echo s($message); ?></div>
    <?php endif; ?>

    <?php if (!$hasplan): ?>
        <div class="alert alert-warning">
            <?php echo get_string('project_needs_pdp', 'local_chatbot'); ?>
            <a href="<?php echo new moodle_url('/local/chatbot/pdp.php', ['courseid' => $courseid]); ?>"
               class="alert-link"><?php echo get_string('step_pdp', 'local_chatbot'); ?></a>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <h5 class="mb-3"><?php echo get_string('project_rubric_title', 'local_chatbot'); ?></h5>
            <p class="small text-muted"><?php echo get_string('project_rubric_help', 'local_chatbot'); ?></p>
            <?php if (empty($criteria)): ?>
                <div class="alert alert-warning mb-0">
                    <?php echo get_string('project_error_norubric', 'local_chatbot'); ?></div>
            <?php else: ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($criteria as $criterion): ?>
                        <li class="mb-2">
                            <strong><?php echo s($criterion->code . ' · ' . $criterion->name); ?></strong><br>
                            <span class="small text-muted"><?php echo s($criterion->descriptor); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($feedback)): ?>
        <h5 class="mb-3"><?php
            // Con el chat solo Tutor el Evaluador no se nombra: «se toma el Moodle» sin verse (ajustes del 08-10-2026).
            echo get_string(\local_chatbot\ux::on('tutoronly') ? 'project_feedback_title_neutral' : 'project_feedback_title',
                'local_chatbot'); ?></h5>
        <p class="small text-muted"><?php echo get_string('project_feedback_help', 'local_chatbot'); ?></p>

        <?php foreach ($criteria as $criterion):
            if (!isset($feedback[$criterion->id])) {
                continue;
            }
            $fb = $feedback[$criterion->id];
        ?>
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="mb-0"><?php echo s($criterion->code . ' · ' . $criterion->name); ?></h6>
                        <?php if ($fb->needshuman): ?>
                            <span class="badge text-bg-warning">
                                <?php echo get_string('project_needshuman', 'local_chatbot'); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php
                    $blocks = [
                        'fb_fortaleza' => $fb->fortaleza,
                        'fb_mejora' => $fb->mejora,
                        'fb_porque' => $fb->porque,
                        'fb_accion' => $fb->accion,
                        'fb_pdp' => $fb->pdpvinculo,
                    ];
                    foreach ($blocks as $key => $value):
                        if (trim((string) $value) === '') {
                            continue;
                        }
                    ?>
                        <p class="small mb-1">
                            <strong><?php echo get_string($key, 'local_chatbot'); ?>:</strong>
                            <?php echo s($value); ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($hasplan): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <h5 class="mb-3"><?php echo get_string('project_form_title', 'local_chatbot'); ?></h5>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">

                    <div class="mb-3">
                        <label for="title" class="form-label"><?php echo get_string('field_title', 'local_chatbot'); ?></label>
                        <input type="text" autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="title" id="title" class="form-control" required maxlength="255"
                               value="<?php echo $project ? s($project->title) : ''; ?>">
                    </div>

                    <?php foreach ($fieldlabels as $field => $label): ?>
                        <div class="mb-3">
                            <label class="form-label" for="<?php echo $field; ?>"><?php echo s($label); ?></label>
                            <textarea autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="<?php echo $field; ?>" id="<?php echo $field; ?>"
                                      class="form-control" rows="3"><?php
                                echo $project ? s($project->{$field}) : ''; ?></textarea>
                        </div>
                    <?php endforeach; ?>

                    <div class="mb-3">
                        <label for="attachment" class="form-label"><?php echo get_string('field_attachment', 'local_chatbot'); ?></label>
                        <input type="file" name="attachment" id="attachment" class="form-control"
                               accept=".pdf,.jpg,.jpeg,.png">
                        <?php if ($project && !empty($project->attachment)): ?>
                            <p class="small text-muted mt-1">
                                <?php echo get_string('field_attachment_current', 'local_chatbot'); ?>:
                                <a href="<?php echo moodle_url::make_pluginfile_url(
                                    context_system::instance()->id, 'local_chatbot', 'project_attachment',
                                    $project->id, '/', $project->attachment); ?>">
                                    <?php echo s($project->attachment); ?></a>
                            </p>
                        <?php endif; ?>
                    </div>

                    <button type="submit" name="action" value="save" class="btn btn-outline-primary">
                        <?php echo get_string('action_savedraft', 'local_chatbot'); ?>
                    </button>
                    <button type="submit" name="action" value="submit" class="btn btn-primary"
                            data-espera="<?php echo s(get_string('espera_proyecto', 'local_chatbot')); ?>"
                            data-espera-larga="<?php echo s(get_string('espera_proyecto_larga', 'local_chatbot')); ?>">
                        <?php echo get_string('project_submit', 'local_chatbot'); ?>
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php // Terminar un paso y no saber que sigue es donde la gente se detiene. ?>
    <?php echo \local_chatbot\course_bridge::next_step_card($USER->id, $courseid, 'proyecto'); ?>

    <?php echo \local_chatbot\course_bridge::back_to_course_button($courseid, 'proyecto'); ?>
    <a href="<?php echo new moodle_url('/local/chatbot/index.php', ['courseid' => $courseid]); ?>"
       class="btn btn-outline-secondary mb-4"><?php echo get_string('action_back', 'local_chatbot'); ?></a>
</div>

<?php
echo $OUTPUT->footer();
