<?php
/**
 * Diagnostico auto aplicado y matriz individual de brechas (componente 1).
 *
 * La misma pagina sirve las dos aplicaciones del instrumento: la de entrada,
 * que fija la linea base, y la breve de salida, que permite contrastar
 * progreso al cierre del modulo.
 */

require_once(__DIR__ . '/../../config.php');

// Ajustes del cliente del 08-10-2026: la persona no conversa con el Coach; su matriz queda en esta pagina.
$tutoronly = \local_chatbot\ux::on('tutoronly');

$stage = optional_param('stage', \local_chatbot\diagnostic::STAGE_ENTRADA, PARAM_ALPHA);
if (!in_array($stage, [\local_chatbot\diagnostic::STAGE_ENTRADA, \local_chatbot\diagnostic::STAGE_SALIDA], true)) {
    $stage = \local_chatbot\diagnostic::STAGE_ENTRADA;
}

[$courseid, $context] = \local_chatbot\course_bridge::setup_page('/local/chatbot/diagnostico.php',
    'diag_title', 'diag_heading', ['stage' => $stage]);

// El perfilamiento y el consentimiento son condicion previa.
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

$action = optional_param('action', '', PARAM_ALPHA);

// RU-COA-OUT-001: primera entrega del Coach, con la plantilla de Doc 3.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'coachreport' && confirm_sesskey()) {
    // Con una matriz ya hecha y desactualizada, el boton rehace tambien las propuestas sin decidir.
    $result = (\local_chatbot\ux::on('diagrefresh') && \local_chatbot\coach_report::get_latest($USER->id, $courseid))
        ? \local_chatbot\coach_report::refresh_after_change($USER->id, $courseid)
        : \local_chatbot\coach_report::generate($USER->id, $courseid);
    // Primera matriz: sus prioridades pasan solas al plan (interruptor autopdp).
    if ($result['ok'] && !isset($result['proposals']) && \local_chatbot\ux::on('autopdp')) {
        $plan = \local_chatbot\coach_report::fill_plan($USER->id, $courseid);
        $result['message'] .= ' ' . $plan['message'];
    }
    $message = $result['message'];
    $messagetype = $result['ok'] ? 'success' : 'warning';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === '' && confirm_sesskey()) {
    $answers = [];
    foreach (\local_chatbot\diagnostic::list_items($courseid) as $item) {
        // Un item situacional se responde con el indice de la alternativa, y
        // ese indice empieza en 0: -1 significa "sin responder" para ambas
        // clases de item, sin colisionar con una respuesta valida.
        $value = optional_param('item_' . $item->id, -1, PARAM_INT);
        if ($value >= 0) {
            $answers[$item->id] = $value;
        }
    }

    if (empty($answers)) {
        $message = get_string('diag_error_empty', 'local_chatbot');
        $messagetype = 'danger';
    } else {
        $huellaantes = \local_chatbot\diagnostic::matrix_fingerprint(
            \local_chatbot\diagnostic::get_gap_matrix($USER->id, $courseid, $stage));
        $saved = \local_chatbot\diagnostic::save_responses($USER->id, $courseid, $answers, $stage);
        $message = get_string('diag_saved', 'local_chatbot', $saved);
        $messagetype = 'success';
        // Si cambio el resultado y ya habia matriz de desarrollo, se rehace todo lo que depende de
        // el: la matriz y las propuestas de metas sin decidir. Guardar sin cambios no gasta nada.
        if ($stage === \local_chatbot\diagnostic::STAGE_ENTRADA && \local_chatbot\ux::on('diagrefresh')
                && \local_chatbot\coach_report::get_latest($USER->id, $courseid)
                && $huellaantes !== \local_chatbot\diagnostic::matrix_fingerprint(
                    \local_chatbot\diagnostic::get_gap_matrix($USER->id, $courseid, $stage))) {
            $refresh = \local_chatbot\coach_report::refresh_after_change($USER->id, $courseid);
            $message .= ' ' . ($refresh['ok'] ? $refresh['message']
                : get_string('diag_refresh_failed', 'local_chatbot', $refresh['message']));
            $messagetype = $refresh['ok'] ? 'success' : 'warning';
        }
    }
}

$items = \local_chatbot\diagnostic::list_items($courseid);
$matrix = \local_chatbot\diagnostic::get_gap_matrix($USER->id, $courseid, $stage);
$contrast = \local_chatbot\diagnostic::get_progress_contrast($USER->id, $courseid);

// Respuestas ya dadas, para que el formulario se muestre con lo que la persona
// contesto. Se trae tambien la alternativa elegida, pero nunca cual era la
// correcta: esa columna no sale de la capa de datos (RU-DIAG-002).
$existing = $DB->get_records('local_chatbot_diag_response',
    ['userid' => $USER->id, 'courseid' => $courseid, 'stage' => $stage],
    '', 'itemid, score, chosenoption');

$levellabels = [];
foreach (\local_chatbot\diagnostic::levels() as $level) {
    $levellabels[$level] = get_string('level_' . $level, 'local_chatbot');
}
$levelclasses = [
    \local_chatbot\diagnostic::LEVEL_PRIORIDAD_ALTA => 'danger',
    \local_chatbot\diagnostic::LEVEL_EN_DESARROLLO => 'warning',
    \local_chatbot\diagnostic::LEVEL_FUNCIONAL => 'info',
    \local_chatbot\diagnostic::LEVEL_CONSOLIDADO => 'success',
];

// El curso refleja lo que acaba de pasar en el recorrido: barra de progreso,
// restricciones de acceso e informes de finalizacion.
\local_chatbot\course_bridge::sync($USER->id, $courseid);

echo $OUTPUT->header();
?>

<div class="container-fluid" style="max-width: 900px;">

    <?php echo \local_chatbot\course_bridge::route_bar($USER->id, $courseid, 'diagnostico'); ?>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messagetype; ?>"><?php echo s($message); ?></div>
    <?php endif; ?>

    <?php if (!empty($saved)): ?>
        <div class="card shadow-sm border-0 mb-4 bg-success-subtle border-success" style="border-left: 4px solid #059669 !important;">
            <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <div>
                    <h5 class="fw-bold text-success mb-1">
                        <i class="fa fa-check-circle me-1"></i> <?php echo get_string('diag_ai_coach_ready_title', 'local_chatbot'); ?>
                    </h5>
                    <p class="small text-muted mb-0"><?php echo get_string('diag_ai_coach_ready_desc', 'local_chatbot'); ?></p>
                </div>
                <?php if (!$tutoronly): ?>
                <button type="button" class="btn btn-success btn-sm text-nowrap" onclick="chatbotOpenCoach()">
                    <i class="fa fa-commenting-o me-1"></i> <?php echo get_string('diag_ai_coach_open_btn', 'local_chatbot'); ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (\local_chatbot\ux::on('diagacta')): ?>
        <?php // Acta del 13-10-2026: texto instructivo que explica como navegar la pagina y modificar respuestas. ?>
        <div class="alert alert-info">
            <strong><?php echo get_string('diag_guide_title', 'local_chatbot'); ?>.</strong>
            <?php echo get_string(empty($matrix) ? 'diag_guide_first' : 'diag_guide_done', 'local_chatbot'); ?>
            <?php if (!empty($matrix)): ?>
                <div class="mt-2"><a class="chatbot-gatebtn" href="#diagnostic-form"><?php
                    echo get_string('diag_guide_go_form', 'local_chatbot'); ?></a></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p class="text-muted"><?php echo get_string('diag_explain_' . $stage, 'local_chatbot'); ?></p>

    <?php if (!empty($matrix)): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <h5 class="mb-3"><?php echo get_string('diag_matrix_title', 'local_chatbot'); ?></h5>
                <p class="small text-muted"><?php echo get_string('diag_matrix_help', 'local_chatbot'); ?></p>
                <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th><?php echo get_string('diag_dimension', 'local_chatbot'); ?></th>
                            <th style="width:110px;"><?php echo get_string('diag_pd', 'local_chatbot'); ?></th>
                            <th style="width:110px;"><?php echo get_string('diag_cs', 'local_chatbot'); ?></th>
                            <th style="width:110px;"><?php echo get_string('diag_pc', 'local_chatbot'); ?></th>
                            <th style="width:200px;"><?php echo get_string('diag_level', 'local_chatbot'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $scalemax = \local_chatbot\diagnostic::SCORE_MAX; ?>
                    <?php foreach ($matrix as $row): ?>
                        <tr>
                            <td><?php echo s($row['name']); ?></td>
                            <td><?php echo $row['pd']; ?> / <?php echo $scalemax; ?></td>
                            <td>
                                <?php if ($row['hassituacional']): ?>
                                    <?php echo $row['cs']; ?> / <?php echo $scalemax; ?>
                                <?php else: ?>
                                    <span class="text-muted" title="<?php
                                        echo s(get_string('diag_nosituacional', 'local_chatbot')); ?>">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo $row['pc']; ?> / <?php echo $scalemax; ?></strong></td>
                            <td>
                                <span class="badge text-bg-<?php echo $levelclasses[$row['nivel']]; ?>">
                                    <?php echo s($levellabels[$row['nivel']]); ?>
                                </span>
                                <?php if (!empty($row['preliminar'])): ?>
                                    <?php // RU-COA-COMPLET-001: se muestra, pero no admite conclusiones. ?>
                                    <span class="badge text-bg-light border ms-1" title="<?php
                                        echo s(get_string('diag_preliminar_help', 'local_chatbot', $row['completitud'])); ?>">
                                        <?php echo get_string('diag_preliminar', 'local_chatbot'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="small text-muted mt-3 mb-0"><?php
                    echo get_string('diag_composite_note', 'local_chatbot'); ?></p>
            </div>
        </div>

        <?php if ($stage === \local_chatbot\diagnostic::STAGE_ENTRADA): ?>
            <?php $report = \local_chatbot\coach_report::get_latest($USER->id, $courseid); ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <?php if ($report): ?>
                        <?php if (\local_chatbot\ux::on('diagrefresh') && \local_chatbot\coach_report::is_stale(
                                (array) json_decode((string) $report->payload, true), $matrix)): ?>
                        <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <span><?php echo get_string('coach_report_stale', 'local_chatbot'); ?></span>
                            <form method="POST" class="m-0">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="action" value="coachreport">
                                <button type="submit" class="chatbot-gatebtn border-0"
                                        data-espera="<?php echo s(get_string('espera_matriz', 'local_chatbot')); ?>"
                                        data-espera-larga="<?php echo s(get_string('espera_matriz_larga', 'local_chatbot')); ?>">
                                    <?php echo get_string('coach_report_refresh_btn', 'local_chatbot'); ?></button>
                            </form>
                        </div>
                        <?php endif; ?>
                        <div class="local-chatbot-coach-report">
                            <?php
                            // Acta del 13-10-2026: «Tus prioridades» en vez de «Tus prioridades iniciales», tambien en
                            // las matrices ya guardadas.
                            $contenido = \local_chatbot\ux::on('diagacta')
                                ? str_replace('#### Tus prioridades iniciales', '#### ' . get_string('diag_priorities_heading', 'local_chatbot'),
                                    $report->content)
                                : $report->content;
                            echo format_text($contenido, FORMAT_MARKDOWN, ['context' => $context]); ?>
                        </div>
                        <?php if (!$tutoronly): ?>
                        <p class="small text-muted mt-3"><?php echo get_string('coach_report_continue', 'local_chatbot'); ?></p>
                        <?php endif; ?>
                        <?php if (\local_chatbot\ux::on('autopdp')): ?>
                        <?php // Las metas ya pasaron solas al plan: el boton solo lleva a verlas. ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if (in_array('ruta', \local_chatbot\course_bridge::route_keys(), true)): ?>
                            <a class="btn btn-primary btn-sm" href="<?php echo \local_chatbot\course_bridge::step_url($courseid, 'ruta'); ?>">
                                <?php echo get_string('coach_report_go_ruta', 'local_chatbot'); ?></a>
                            <?php endif; ?>
                            <a class="btn btn-primary btn-sm" href="<?php echo new moodle_url('/local/chatbot/pdp.php',
                                ['courseid' => $courseid]); ?>">
                                <?php echo get_string('coach_report_view_pdp', 'local_chatbot'); ?></a>
                        </div>
                        <?php else: ?>
                        <a class="btn btn-primary btn-sm" href="<?php echo new moodle_url('/local/chatbot/pdp.php',
                            ['courseid' => $courseid, 'propose' => 1, 'sesskey' => sesskey()]); ?>">
                            <?php echo get_string('coach_report_to_pdp', 'local_chatbot'); ?></a>
                        <?php endif; ?>
                    <?php else: ?>
                        <h5 class="mb-2"><?php echo get_string('coach_report_title', 'local_chatbot'); ?></h5>
                        <p class="small text-muted"><?php echo get_string('coach_report_explain', 'local_chatbot'); ?></p>
                        <form method="POST">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="action" value="coachreport">
                            <button type="submit" class="btn btn-primary btn-sm"
                                    data-espera="<?php echo s(get_string('espera_matriz', 'local_chatbot')); ?>"
                                    data-espera-larga="<?php echo s(get_string('espera_matriz_larga', 'local_chatbot')); ?>">
                                <?php echo get_string('coach_report_generate', 'local_chatbot'); ?></button>
                        </form>
                    <?php endif; ?>
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
    <?php endif; ?>

    <?php if (empty($items)): ?>
        <div class="alert alert-warning"><?php echo get_string('diag_noinstrument', 'local_chatbot'); ?></div>
    <?php else: ?>
        <?php if ($stage === \local_chatbot\diagnostic::STAGE_ENTRADA): ?>
            <div class="card shadow-sm border-0 mb-4 local-chatbot-diag-intro-card" style="background: linear-gradient(135deg, #EEF2FF 0%, #F8FAFC 100%); border-left: 4px solid #4F46E5 !important;">
                <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="rounded-circle p-2 bg-white shadow-sm d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; min-width: 48px;">
                            <img src="<?php echo new moodle_url('/local/chatbot/pix/interfases_logo.png'); ?>" alt="Asistente IA" style="width: 28px; height: 28px; object-fit: contain;">
                        </div>
                        <div>
                            <h6 class="fw-bold mb-1 text-primary"><?php echo get_string('diag_ai_card_title', 'local_chatbot'); ?></h6>
                            <p class="small text-muted mb-0"><?php echo get_string('diag_ai_card_desc', 'local_chatbot'); ?></p>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm text-nowrap align-self-start align-self-md-center shadow-sm" onclick="chatbotAskInitialHelp()">
                        <i class="fa fa-comments me-1"></i> <?php echo get_string('diag_ai_card_btn', 'local_chatbot'); ?>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" id="diagnostic-form">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="stage" value="<?php echo s($stage); ?>">

            <?php
            $currentdimension = null;
            foreach ($items as $item):
                if ($currentdimension !== $item->dimensionid):
                    if ($currentdimension !== null) {
                        echo '</div></div>';
                    }
                    $currentdimension = $item->dimensionid;
                    ?>
                    <div class="card shadow-sm border-0 mb-3">
                        <div class="card-body">
                            <h6 class="mb-3 fw-bold text-dark border-bottom pb-2"><?php echo s($item->dimensionname); ?></h6>
                <?php endif; ?>

                <?php $issituacional = ($item->kind === \local_chatbot\diagnostic::KIND_SITUACIONAL); ?>
                <div class="mb-3 pb-3 border-bottom">
                    <?php if ($issituacional): ?>
                        <span class="badge text-bg-info mb-2">
                            <?php echo get_string('diag_kind_situacional', 'local_chatbot'); ?>
                        </span>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <p class="mb-0 fw-medium flex-grow-1"><?php echo s($item->statement); ?></p>
                        <?php // El enunciado viaja como atributo escapado: con json_encode dentro del
                              // onclick, sus comillas cerraban el atributo y el boton quedaba muerto. ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary border-0 py-0 px-2 text-primary chatbot-inline-help-btn"
                                title="<?php echo s(get_string('diag_ai_item_help', 'local_chatbot')); ?>"
                                data-enunciado="<?php echo s($item->statement); ?>">
                            <i class="fa fa-lightbulb-o text-warning me-1"></i>
                            <span class="small d-none d-sm-inline"><?php echo get_string('diag_ai_item_help', 'local_chatbot'); ?></span>
                        </button>
                    </div>

                    <?php if ($issituacional): ?>
                        <?php $options = \local_chatbot\diagnostic::item_options($item); ?>
                        <div class="d-flex flex-column" style="gap: 8px;">
                            <?php foreach ($options as $index => $option):
                                $checked = isset($existing[$item->id])
                                    && $existing[$item->id]->chosenoption !== null
                                    && (int) $existing[$item->id]->chosenoption === $index;
                            ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio"
                                           name="item_<?php echo $item->id; ?>"
                                           id="item_<?php echo $item->id; ?>_<?php echo $index; ?>"
                                           value="<?php echo $index; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                    <label class="form-check-label small"
                                           for="item_<?php echo $item->id; ?>_<?php echo $index; ?>">
                                        <?php echo s($option); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="d-flex flex-wrap" style="gap: 14px;">
                            <?php for ($score = \local_chatbot\diagnostic::SCORE_MIN;
                                       $score <= \local_chatbot\diagnostic::SCORE_MAX; $score++):
                                $checked = isset($existing[$item->id])
                                    && (int) $existing[$item->id]->score === $score;
                            ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio"
                                           name="item_<?php echo $item->id; ?>"
                                           id="item_<?php echo $item->id; ?>_<?php echo $score; ?>"
                                           value="<?php echo $score; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                    <label class="form-check-label small"
                                           for="item_<?php echo $item->id; ?>_<?php echo $score; ?>">
                                        <?php echo get_string('scale_' . $score, 'local_chatbot'); ?>
                                    </label>
                                </div>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($currentdimension !== null) { echo '</div></div>'; } ?>

            <button type="submit" id="diag-submit-btn" class="btn btn-primary mb-4 px-4 py-2 shadow-sm">
                <i class="fa fa-save me-1"></i> <?php echo get_string('diag_submit', 'local_chatbot'); ?>
            </button>
        </form>
    <?php endif; ?>

    <?php // Terminar un paso y no saber que sigue es donde la gente se detiene. ?>
    <?php echo \local_chatbot\course_bridge::next_step_card($USER->id, $courseid, 'diagnostico'); ?>

    <?php echo \local_chatbot\course_bridge::back_to_course_button($courseid, 'diagnostico'); ?>
    <a href="<?php echo new moodle_url('/local/chatbot/index.php', ['courseid' => $courseid]); ?>"
       class="btn btn-outline-secondary mb-4"><?php echo get_string('action_back', 'local_chatbot'); ?></a>
</div>

<script>
function chatbotAskConcept(statement) {
    if (typeof window.chatbotAsk === 'function') {
        var query = 'Hola. En el diagnóstico estoy respondiendo sobre: "' + statement + '". ¿Podrías explicarme los conceptos clave de este enunciado con ejemplos generales de formación de adultos? (Recuerda no revelarme la respuesta ni sugerir alternativas).';
        window.chatbotAsk(query, 'tutor');
    } else if (typeof window.chatbotOpen === 'function') {
        window.chatbotOpen();
    }
}

function chatbotAskInitialHelp() {
    if (typeof window.chatbotAsk === 'function') {
        var query = 'Hola. Estoy por responder el diagnóstico de brechas. ¿Podrías explicarme cómo funciona la actividad y qué conceptos metodológicos aborda?';
        window.chatbotAsk(query, 'tutor');
    } else if (typeof window.chatbotOpen === 'function') {
        window.chatbotOpen();
    }
}

function chatbotOpenCoach() {
    if (typeof window.chatbotSetMode === 'function' && typeof window.chatbotOpen === 'function') {
        window.chatbotSetMode('coach');
        window.chatbotOpen();
    } else if (typeof window.chatbotOpen === 'function') {
        window.chatbotOpen();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Cada boton lleva su enunciado en un atributo, no en el onclick.
    document.querySelectorAll('.chatbot-inline-help-btn[data-enunciado]').forEach(function(boton) {
        boton.addEventListener('click', function() {
            chatbotAskConcept(boton.getAttribute('data-enunciado') || '');
        });
    });

    var diagForm = document.getElementById('diagnostic-form');
    if (diagForm) {
        diagForm.addEventListener('submit', function() {
            var submitBtn = document.getElementById('diag-submit-btn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span><?php echo s(get_string('diag_submitting', 'local_chatbot')); ?>';
            }
        });
    }

    <?php if (!empty($saved) && !$tutoronly): ?>
    setTimeout(function() {
        chatbotOpenCoach();
    }, 900);
    <?php endif; ?>
});
</script>

<?php
echo $OUTPUT->footer();
