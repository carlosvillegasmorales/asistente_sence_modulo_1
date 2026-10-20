<?php
/**
 * Seguimiento y cierre del Modulo 1 (componente 5).
 *
 * Muestra las cuatro condiciones de cierre que fija el documento y recoge la
 * declaracion de la persona: su reflexion, su plan de continuidad y las
 * acciones que prioriza. Eso es lo que despues alimenta la derivacion hacia
 * los modulos siguientes.
 */

require_once(__DIR__ . '/../../config.php');

[$courseid, $context] = \local_chatbot\course_bridge::setup_page('/local/chatbot/cierre.php',
    'closure_title', 'closure_heading');

if (!\local_chatbot\profile::has_consent($USER->id, $courseid)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('error_needsconsent', 'local_chatbot'), 'warning');
    echo $OUTPUT->single_button(
        new moodle_url('/local/chatbot/index.php', ['courseid' => $courseid]),
        get_string('action_gotostart', 'local_chatbot'), 'get');
    echo $OUTPUT->footer();
    exit;
}

$message = '';
$messagetype = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    \local_chatbot\closure::save($USER->id, $courseid, [
        'reflexion' => trim(optional_param('reflexion', '', PARAM_TEXT)),
        'continuidad' => trim(optional_param('continuidad', '', PARAM_TEXT)),
        'acciones' => trim(optional_param('acciones', '', PARAM_TEXT)),
    ]);
    $message = get_string('closure_saved', 'local_chatbot');
    $messagetype = 'success';
}

$record = \local_chatbot\closure::get($USER->id, $courseid);
$conditions = \local_chatbot\closure::get_conditions($USER->id, $courseid);
$iscomplete = \local_chatbot\closure::is_complete($USER->id, $courseid);
$contrast = \local_chatbot\diagnostic::get_progress_contrast($USER->id, $courseid);

// El curso refleja lo que acaba de pasar en el recorrido: barra de progreso,
// restricciones de acceso e informes de finalizacion.
\local_chatbot\course_bridge::sync($USER->id, $courseid);

echo $OUTPUT->header();
?>

<div class="container-fluid" style="max-width: 900px;">

    <?php echo \local_chatbot\course_bridge::route_bar($USER->id, $courseid, 'cierre'); ?>

    <?php $bloqueo = \local_chatbot\course_bridge::gate_html($USER->id, $courseid, 'cierre'); ?>
    <?php if ($bloqueo !== ''): ?>
        <?php echo $bloqueo; ?>
</div>
        <?php echo $OUTPUT->footer(); exit; ?>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messagetype; ?>"><?php echo s($message); ?></div>
    <?php endif; ?>

    <p class="text-muted"><?php echo get_string('closure_explain', 'local_chatbot'); ?></p>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <h5 class="mb-3"><?php echo get_string('closure_conditions_title', 'local_chatbot'); ?></h5>
            <ul class="list-unstyled mb-0">
                <?php foreach ($conditions as $key => $met): ?>
                    <li class="mb-2">
                        <span class="badge text-bg-<?php echo $met ? 'success' : 'secondary'; ?> me-2">
                            <?php echo $met ? '&#10003;' : '&mdash;'; ?>
                        </span>
                        <?php echo get_string('closure_cond_' . $key, 'local_chatbot'); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <?php if ($iscomplete): ?>
        <div class="alert alert-success"><?php echo get_string('closure_complete', 'local_chatbot'); ?></div>
        <?php
        // Ajustes del cliente del 08-10-2026: al cerrar el Modulo 1 quedan disponibles los Modulos 2 y 3.
        $siguientes = \local_chatbot\closure::unlock_next_modules((int) $USER->id);
        ?>
        <?php if (!empty($siguientes)): ?>
            <div class="card border-success shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="mb-2"><?php echo get_string('nextmodules_title', 'local_chatbot'); ?></h5>
                    <p class="small text-muted"><?php echo get_string('nextmodules_body', 'local_chatbot'); ?></p>
                    <?php foreach ($siguientes as $mod): ?>
                        <?php if ($mod['visible']): ?>
                            <a class="btn btn-success me-2 mb-2" href="<?php
                                echo new moodle_url('/course/view.php', ['id' => $mod['id']]); ?>"><?php echo s($mod['name']); ?></a>
                        <?php else: ?>
                            <p class="small mb-1"><?php echo get_string('nextmodules_hidden', 'local_chatbot', s($mod['name'])); ?></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($contrast)): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <h5 class="mb-3"><?php echo get_string('diag_contrast_title', 'local_chatbot'); ?></h5>
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th><?php echo get_string('diag_dimension', 'local_chatbot'); ?></th>
                            <th><?php echo get_string('diag_entrada', 'local_chatbot'); ?></th>
                            <th><?php echo get_string('diag_salida', 'local_chatbot'); ?></th>
                            <th><?php echo get_string('diag_variacion', 'local_chatbot'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($contrast as $row): ?>
                        <tr>
                            <td><?php echo s($row['name']); ?></td>
                            <td><?php echo $row['entrada']; ?></td>
                            <td><?php echo $row['salida']; ?></td>
                            <td class="<?php echo $row['variacion'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo ($row['variacion'] > 0 ? '+' : '') . $row['variacion']; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-light border">
            <?php echo get_string('closure_needs_exit', 'local_chatbot'); ?>
            <a class="alert-link" href="<?php echo new moodle_url('/local/chatbot/diagnostico.php',
                ['courseid' => $courseid, 'stage' => 'salida']); ?>">
                <?php echo get_string('step_closing', 'local_chatbot'); ?></a>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <h5 class="mb-3"><?php echo get_string('closure_form_title', 'local_chatbot'); ?></h5>
            <form method="POST">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">

                <div class="mb-3">
                    <label for="reflexion" class="form-label"><?php echo get_string('closure_reflexion', 'local_chatbot'); ?></label>
                    <textarea autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="reflexion" id="reflexion" class="form-control" rows="4"><?php
                        echo $record ? s($record->reflexion) : ''; ?></textarea>
                </div>
                <div class="mb-3">
                    <label for="continuidad" class="form-label"><?php echo get_string('closure_continuidad', 'local_chatbot'); ?></label>
                    <textarea autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="continuidad" id="continuidad" class="form-control" rows="3"><?php
                        echo $record ? s($record->continuidad) : ''; ?></textarea>
                </div>
                <div class="mb-3">
                    <label for="acciones" class="form-label"><?php echo get_string('closure_acciones', 'local_chatbot'); ?></label>
                    <textarea autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="acciones" id="acciones" class="form-control" rows="3"><?php
                        echo $record ? s($record->acciones) : ''; ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary">
                    <?php echo get_string('action_save', 'local_chatbot'); ?>
                </button>
            </form>
        </div>
    </div>

    <?php // Terminar un paso y no saber que sigue es donde la gente se detiene. ?>
    <?php echo \local_chatbot\course_bridge::next_step_card($USER->id, $courseid, 'cierre'); ?>

    <?php echo \local_chatbot\course_bridge::back_to_course_button($courseid, 'cierre'); ?>
    <a href="<?php echo new moodle_url('/local/chatbot/index.php', ['courseid' => $courseid]); ?>"
       class="btn btn-outline-secondary mb-4"><?php echo get_string('action_back', 'local_chatbot'); ?></a>
</div>

<?php
echo $OUTPUT->footer();
