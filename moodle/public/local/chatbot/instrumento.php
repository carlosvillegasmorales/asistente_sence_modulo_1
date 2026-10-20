<?php
/**
 * Configuracion del instrumento oficial del Modulo 1: prompts versionados de
 * cada modo, criterios de la rubrica y dimensiones e items del diagnostico.
 *
 * Editar un prompt nunca sobrescribe la version anterior: crea la siguiente y
 * desactiva la vigente, de modo que se pueda saber con que instruccion se
 * genero cada recomendacion registrada.
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/chatbot:manageinstrument', $context);

$PAGE->set_url(new moodle_url('/local/chatbot/instrumento.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('instrument_title', 'local_chatbot'));
$PAGE->set_heading(get_string('instrument_heading', 'local_chatbot'));

$message = '';
$messagetype = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_ALPHA);

    if ($action === 'saveprompt') {
        $mode = required_param('mode', PARAM_ALPHA);
        $text = trim(required_param('prompttext', PARAM_RAW));
        if (\local_chatbot\assistant::is_prompt_context($mode) && $text !== '') {
            $version = \local_chatbot\prompt_store::save_new_version($mode, $text, $USER->id);
            $message = get_string('instrument_prompt_saved', 'local_chatbot', $version);
            $messagetype = 'success';
        }
    }

    if ($action === 'addcriterion') {
        $code = trim(required_param('code', PARAM_ALPHANUMEXT));

        // Reusar un codigo existente no sobrescribe: retira la version vigente
        // y crea la siguiente, igual que con los prompts. La gobernanza pide
        // versionar las rubricas, no solo los prompts.
        $previous = $DB->get_record('local_chatbot_criterion',
            ['courseid' => 0, 'code' => $code, 'active' => 1]);

        $record = new stdClass();
        $record->courseid = 0;
        $record->code = $code;
        $record->name = trim(required_param('name', PARAM_TEXT));
        $record->descriptor = trim(required_param('descriptor', PARAM_TEXT));
        $record->version = $previous ? ((int) $previous->version + 1) : 1;
        $record->active = 1;
        $record->timecreated = time();

        if ($previous) {
            $record->sortorder = $previous->sortorder;
            $DB->set_field('local_chatbot_criterion', 'active', 0, ['id' => $previous->id]);
        } else {
            $record->sortorder = $DB->count_records('local_chatbot_criterion',
                ['courseid' => 0, 'active' => 1]);
        }

        $DB->insert_record('local_chatbot_criterion', $record);
        $message = get_string('instrument_criterion_added', 'local_chatbot', $record->version);
        $messagetype = 'success';
    }

    if ($action === 'savecriteria') {
        // Criterios formativos contra los que el motor de apoyos adaptativos
        // revisa las evidencias (RU-ADAPT-004). Uno por linea.
        $dimensionid = required_param('dimensionid', PARAM_INT);
        $criterios = trim(optional_param('criterios', '', PARAM_TEXT));
        if ($DB->record_exists('local_chatbot_dimension', ['id' => $dimensionid])) {
            $DB->set_field('local_chatbot_dimension', 'criterios', $criterios, ['id' => $dimensionid]);
            $message = get_string('instrument_criteria_saved', 'local_chatbot');
            $messagetype = 'success';
        }
    }

    if ($action === 'additem') {
        $dimensionid = required_param('dimensionid', PARAM_INT);
        $statement = trim(required_param('statement', PARAM_TEXT));
        if ($DB->record_exists('local_chatbot_dimension', ['id' => $dimensionid]) && $statement !== '') {
            $record = new stdClass();
            $record->dimensionid = $dimensionid;
            $record->statement = $statement;
            $record->sortorder = $DB->count_records('local_chatbot_diag_item',
                ['dimensionid' => $dimensionid]);
            $record->timecreated = time();
            $DB->insert_record('local_chatbot_diag_item', $record);
            $message = get_string('instrument_item_added', 'local_chatbot');
            $messagetype = 'success';
        }
    }
}

$deactivate = optional_param('deactivate', 0, PARAM_INT);
if ($deactivate > 0 && confirm_sesskey()) {
    $DB->set_field('local_chatbot_criterion', 'active', 0, ['id' => $deactivate]);
    $message = get_string('instrument_criterion_deactivated', 'local_chatbot');
    $messagetype = 'success';
}

// Incluye la fase de diagnostico: no es un modo del selector, pero tiene prompt
// propio que hay que poder revisar y versionar como cualquier otro (RU-PRIV-006).
$modes = \local_chatbot\assistant::get_prompt_contexts();
$criteria = \local_chatbot\project::list_criteria(0);
$dimensions = \local_chatbot\diagnostic::list_dimensions(0);
$items = \local_chatbot\diagnostic::list_items(0);

echo $OUTPUT->header();
?>

<div class="container-fluid" style="max-width: 1000px;">

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messagetype; ?>"><?php echo s($message); ?></div>
    <?php endif; ?>

    <h5 class="mb-3"><?php echo get_string('instrument_prompts', 'local_chatbot'); ?></h5>
    <p class="small text-muted"><?php echo get_string('instrument_prompts_help', 'local_chatbot'); ?></p>

    <?php foreach ($modes as $mode => $label):
        $active = \local_chatbot\prompt_store::get_active($mode);
        $history = \local_chatbot\prompt_store::get_history($mode);
    ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0"><?php echo s($label); ?></h6>
                    <span class="badge text-bg-light">
                        <?php echo get_string('instrument_version', 'local_chatbot',
                            $active ? $active->version : 0); ?>
                    </span>
                </div>
                <form method="POST">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="action" value="saveprompt">
                    <input type="hidden" name="mode" value="<?php echo s($mode); ?>">
                    <textarea name="prompttext" class="form-control mb-2" rows="10"><?php
                        echo $active ? s($active->prompttext) : ''; ?></textarea>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <?php echo get_string('instrument_prompt_save', 'local_chatbot'); ?>
                    </button>
                </form>
                <?php if (count($history) > 1): ?>
                    <p class="small text-muted mt-2 mb-0">
                        <?php echo get_string('instrument_history', 'local_chatbot', count($history)); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <h5 class="mb-3 mt-4"><?php echo get_string('instrument_rubric', 'local_chatbot'); ?></h5>
    <div class="card shadow-sm border-0 mb-3">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th><?php echo get_string('instrument_code', 'local_chatbot'); ?></th>
                        <th><?php echo get_string('instrument_name', 'local_chatbot'); ?></th>
                        <th><?php echo get_string('instrument_descriptor', 'local_chatbot'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($criteria as $criterion): ?>
                    <tr>
                        <td class="small"><?php echo s($criterion->code); ?></td>
                        <td class="small"><?php echo s($criterion->name); ?></td>
                        <td class="small"><?php echo s($criterion->descriptor); ?></td>
                        <td>
                            <a class="btn btn-outline-danger btn-sm"
                               href="<?php echo new moodle_url('/local/chatbot/instrumento.php',
                                   ['deactivate' => $criterion->id, 'sesskey' => sesskey()]); ?>">
                                <?php echo get_string('instrument_deactivate', 'local_chatbot'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <h6 class="mb-3"><?php echo get_string('instrument_addcriterion', 'local_chatbot'); ?></h6>
            <form method="POST">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="addcriterion">
                <div class="row g-2">
                    <div class="col-md-2 mb-2">
                        <input type="text" name="code" class="form-control" required maxlength="50"
                               placeholder="<?php echo get_string('instrument_code', 'local_chatbot'); ?>">
                    </div>
                    <div class="col-md-4 mb-2">
                        <input type="text" name="name" class="form-control" required maxlength="255"
                               placeholder="<?php echo get_string('instrument_name', 'local_chatbot'); ?>">
                    </div>
                    <div class="col-md-6 mb-2">
                        <input type="text" name="descriptor" class="form-control" required
                               placeholder="<?php echo get_string('instrument_descriptor', 'local_chatbot'); ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <?php echo get_string('instrument_add', 'local_chatbot'); ?>
                </button>
            </form>
        </div>
    </div>

    <h5 class="mb-3 mt-4"><?php echo get_string('instrument_diagnostic', 'local_chatbot'); ?></h5>
    <?php foreach ($dimensions as $dimension): ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <h6 class="mb-1"><?php echo s($dimension->name); ?></h6>
                <p class="small text-muted"><?php echo s($dimension->description); ?></p>
                <ul class="small">
                    <?php foreach ($items as $item): ?>
                        <?php if ($item->dimensionid == $dimension->id): ?>
                            <li><?php echo s($item->statement); ?>
                                <?php if ($item->kind === \local_chatbot\diagnostic::KIND_SITUACIONAL): ?>
                                    <span class="badge text-bg-info"><?php echo get_string('diag_kind_situacional', 'local_chatbot'); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                <form method="POST" class="d-flex flex-wrap align-items-center gap-2">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="action" value="additem">
                    <input type="hidden" name="dimensionid" value="<?php echo $dimension->id; ?>">
                    <input type="text" name="statement" class="form-control form-control-sm me-2"
                           style="min-width: 420px;" required
                           placeholder="<?php echo get_string('instrument_newitem', 'local_chatbot'); ?>">
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <?php echo get_string('instrument_add', 'local_chatbot'); ?>
                    </button>
                </form>
                <form method="POST" class="mt-3">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="action" value="savecriteria">
                    <input type="hidden" name="dimensionid" value="<?php echo $dimension->id; ?>">
                    <label class="form-label small fw-bold"><?php echo get_string('instrument_criteria', 'local_chatbot'); ?></label>
                    <textarea name="criterios" class="form-control form-control-sm mb-2" rows="3"><?php
                        echo s($dimension->criterios ?? ''); ?></textarea>
                    <small class="text-muted d-block mb-2"><?php echo get_string('instrument_criteria_help', 'local_chatbot'); ?></small>
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        <?php echo get_string('action_save', 'local_chatbot'); ?></button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php
echo $OUTPUT->footer();
