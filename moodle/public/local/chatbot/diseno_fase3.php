<?php
/**
 * Modulo 2, Fase 3: vista de las etapas 7 (consolidacion) y 8 (evaluacion del diseno). La incluye diseno.php,
 * que ya hizo los controles de acceso y dejo listas sus variables ($etapa, $filas, $aceptadas, $fila,
 * $generando, $evaluacion, $aprobado, $descargable, $pageurl, $courseid, $context).
 *
 * El diseno consolidado no es editable (pedido de Interfases): para ajustar algo se vuelve a la etapa de la
 * Fase 2 que corresponda, y entonces hay que confirmar de nuevo la consolidacion y volver a evaluarla.
 */

defined('MOODLE_INTERNAL') || die();

use local_chatbot\diseno;
use local_chatbot\exante;

// Enlaces a las etapas de la Fase 2, para ajustar ahi.
$enlacesfase2 = [];
foreach (diseno::etapas() as $n => $e) {
    $enlacesfase2[] = html_writer::link(new moodle_url($pageurl, ['etapa' => $n]), $n . '. ' . s($e['titulo']));
}
?>
<div id="producto">
<?php if ($etapa === diseno::CONSOLIDACION):
    $vigente = in_array(diseno::CONSOLIDACION, $aceptadas, true);
    $contenidos = [];
    foreach (array_keys(diseno::etapas()) as $n) {
        $contenidos[$n] = (string) ($filas[$n]->contenido ?? '');
    }
    // Lo que se muestra corresponde siempre a las etapas de hoy: la consolidacion del asistente (sin repetir campos
    // comunes) si esta al dia, o la completa por codigo. Si se confirma, eso queda guardado y se evalua.
    [$documento, $porasistente] = diseno::consolidation_shown($fila, $contenidos, diseno::stages_fingerprint($filas));
    $consolidaia = \local_chatbot\ux::on('consolidacionia'); ?>

    <?php if ($fila && $fila->estado === diseno::ACEPTADO && !$vigente): ?>
        <div class="alert alert-warning"><?php echo get_string('diseno_consolidation_stale', 'local_chatbot'); ?></div>
    <?php endif; ?>

    <?php if ($generando): ?>
        <div class="card shadow-sm border-0 mb-4" id="diseno-generando" role="status"
             data-id="<?php echo (int) $fila->id; ?>" data-estado="<?php echo s($fila->generacion); ?>"
             data-url="<?php echo s((new moodle_url('/local/chatbot/diseno.php', ['courseid' => $courseid]))->out(false)); ?>"
             data-sesskey="<?php echo sesskey(); ?>"
             data-volver="<?php echo s((new moodle_url($pageurl, ['g' => time()], 'producto'))->out(false)); ?>">
            <div class="card-body">
                <h5 class="mb-2"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?php
                    echo get_string('diseno_consolidating', 'local_chatbot'); ?></h5>
                <p class="small text-muted mb-0"><?php echo get_string('diseno_consolidating_long', 'local_chatbot'); ?></p>
            </div>
        </div>
    <?php elseif (!$vigente && $fila && $fila->generacion === exante::ERROR): ?>
        <div class="alert alert-warning"><?php echo get_string('exante_error_' . ($fila->error ?: 'servicio'), 'local_chatbot'); ?></div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h5 class="mb-0"><?php echo get_string('diseno_consolidated', 'local_chatbot'); ?></h5>
        <div class="d-flex flex-wrap gap-1">
        <?php if ($porasistente): ?>
            <span class="badge text-bg-info"><?php echo get_string('diseno_consolidated_ai', 'local_chatbot'); ?></span>
        <?php endif; ?>
        <?php if ($vigente): ?>
            <span class="badge text-bg-success"><?php echo get_string('diseno_status_confirmed', 'local_chatbot',
                userdate($fila->timeaccepted, get_string('strftimedatetimeshort', 'langconfig'))); ?></span>
        <?php endif; ?>
        </div>
    </div>
    <div class="alert alert-info small">
        <?php echo get_string('diseno_consolidated_help', 'local_chatbot'); ?>
        <div class="mt-1"><?php echo implode(' · ', $enlacesfase2); ?></div>
    </div>
    <div class="card border mb-3">
        <div class="card-body chatbot-diseno-doc" style="max-height: 640px; overflow-y: auto;">
            <?php echo format_text($documento, FORMAT_HTML, ['context' => $context]); ?>
        </div>
    </div>
    <?php if (!$vigente && !$generando): ?>
        <form method="post" action="<?php echo $pageurl->out(false); ?>" class="mb-4 d-flex flex-wrap gap-2">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <?php if ($consolidaia && !$porasistente): ?>
                <?php // Prompt Maestro: consolidar «en extenso», sin repetir los campos comunes (lo hace el asistente). ?>
                <button type="submit" name="action" value="consolidar" class="btn btn-primary px-4"
                        data-espera="<?php echo s(get_string('diseno_consolidating', 'local_chatbot')); ?>">
                    <i class="fa fa-magic me-1"></i> <?php echo get_string('diseno_consolidate_ai', 'local_chatbot'); ?>
                </button>
            <?php endif; ?>
            <button type="submit" name="action" value="confirmar" class="btn btn-success px-4"
                    data-espera="<?php echo s(get_string('exante_saving', 'local_chatbot')); ?>">
                <i class="fa fa-check me-1"></i> <?php echo get_string('diseno_confirm', 'local_chatbot'); ?>
            </button>
        </form>
        <?php if ($consolidaia && !$porasistente): ?>
            <p class="small text-muted"><?php echo get_string('diseno_consolidate_ai_help', 'local_chatbot'); ?></p>
        <?php endif; ?>
    <?php endif; ?>

<?php elseif ($generando): ?>
    <div class="card shadow-sm border-0 mb-4" id="diseno-generando" role="status"
         data-id="<?php echo (int) $fila->id; ?>" data-estado="<?php echo s($fila->generacion); ?>"
         data-url="<?php echo s((new moodle_url('/local/chatbot/diseno.php', ['courseid' => $courseid]))->out(false)); ?>"
         data-sesskey="<?php echo sesskey(); ?>"
         data-volver="<?php echo s((new moodle_url($pageurl, ['g' => time()], 'producto'))->out(false)); ?>">
        <div class="card-body">
            <h5 class="mb-2"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?php
                echo get_string('diseno_evaluating', 'local_chatbot'); ?></h5>
            <p class="small text-muted mb-0"><?php echo get_string('diseno_generating_long', 'local_chatbot'); ?></p>
        </div>
    </div>

<?php elseif ($evaluacion):
    $clase = $aprobado ? 'success' : ($evaluacion['banda']['clave'] === 'importantes' ? 'warning' : 'danger'); ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 border-bottom pb-2 mb-3">
                <h4 class="mb-0"><?php echo get_string('diseno_eval_title', 'local_chatbot'); ?></h4>
                <span class="small text-muted"><?php echo get_string('exante_report_date', 'local_chatbot',
                    userdate($evaluacion['fecha'], get_string('strftimedatetimeshort', 'langconfig'))); ?></span>
            </div>
            <div class="alert alert-<?php echo $clase; ?>">
                <strong><?php echo get_string('diseno_eval_total', 'local_chatbot', (int) $evaluacion['total']); ?></strong>
                · <?php echo s($evaluacion['banda']['texto']); ?>
                <?php if (isset($evaluacion['maximo']) && (int) $evaluacion['maximo'] < 36): ?>
                    <div class="small mt-1"><?php echo get_string('diseno_eval_max', 'local_chatbot', (int) $evaluacion['maximo']); ?></div>
                <?php endif; ?>
            </div>
            <div class="table-responsive chatbot-diseno-evaluacion">
                <?php echo diseno::evaluation_html($evaluacion); ?>
            </div>
            <p class="small text-muted mb-0"><?php echo get_string('diseno_eval_note', 'local_chatbot'); ?></p>
        </div>
    </div>
    <?php if ($aprobado): ?>
        <?php // Con esto cierra el proceso del modulo: el documento final queda disponible. ?>
        <div class="alert alert-success">
            <strong><?php echo get_string('diseno_approved', 'local_chatbot'); ?></strong>
            <div class="mt-2"><a class="chatbot-gatebtn" href="<?php echo (new moodle_url($pageurl, ['action' => 'final']))->out(false); ?>">
                <i class="fa fa-file-word-o me-1"></i> <?php echo get_string('diseno_final_download', 'local_chatbot'); ?></a></div>
        </div>
        <?php // El modulo se usa una y otra vez: se puede volver a las etapas y empezar otra experiencia. ?>
        <div class="card border-0 bg-light mb-4">
            <div class="card-body">
                <p class="mb-1"><strong><?php echo get_string('diseno_review_stages', 'local_chatbot'); ?></strong></p>
                <p class="small mb-3"><?php echo implode(' · ', $enlacesfase2); ?></p>
                <p class="mb-2"><?php echo get_string('diseno_another', 'local_chatbot'); ?></p>
                <a class="btn btn-outline-primary" href="<?php echo (new moodle_url('/local/chatbot/ficha_exante.php',
                    ['courseid' => $courseid], 'disenos'))->out(false); ?>"><i class="fa fa-plus me-1"></i><?php
                    echo get_string('diseno_go_designs', 'local_chatbot'); ?></a>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            <?php echo get_string('diseno_not_approved', 'local_chatbot'); ?>
            <div class="mt-1"><?php echo implode(' · ', $enlacesfase2); ?></div>
        </div>
        <?php if ($descargable): ?>
            <?php // La Fase 3 no bloquea: el documento sale con el puntaje y la banda impresos (punto 14). ?>
            <div class="alert alert-light border">
                <?php echo get_string('diseno_final_anyway', 'local_chatbot', s($evaluacion['banda']['texto'])); ?>
                <div class="mt-2"><a class="chatbot-gatebtn" href="<?php echo (new moodle_url($pageurl, ['action' => 'final']))->out(false); ?>">
                    <i class="fa fa-file-word-o me-1"></i> <?php echo get_string('diseno_final_download', 'local_chatbot'); ?></a></div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

<?php else: ?>
    <?php if ($fila && $fila->generacion === exante::ERROR): ?>
        <div class="alert alert-warning"><?php
            echo get_string('exante_error_' . ($fila->error ?: 'servicio'), 'local_chatbot') . ' '
                . get_string('diseno_error_retry', 'local_chatbot'); ?></div>
    <?php elseif ($fila && $fila->informe): ?>
        <div class="alert alert-warning"><?php echo get_string('diseno_eval_stale', 'local_chatbot'); ?></div>
    <?php endif; ?>
    <form method="post" action="<?php echo $pageurl->out(false); ?>" class="mb-4">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <p><?php echo get_string('diseno_evaluate_help', 'local_chatbot'); ?></p>
        <button type="submit" name="action" value="evaluar" class="btn btn-primary px-4 py-2"
                data-espera="<?php echo s(get_string('diseno_evaluating', 'local_chatbot')); ?>">
            <i class="fa fa-check-square-o me-1"></i> <?php echo get_string('diseno_evaluate', 'local_chatbot'); ?>
        </button>
    </form>
<?php endif; ?>
</div>
