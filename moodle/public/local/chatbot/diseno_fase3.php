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
use local_chatbot\documento_operativo;
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
            <?php echo format_text(diseno::normalize_product($documento), FORMAT_HTML, ['context' => $context]); ?>
        </div>
    </div>
    <?php // Revision automatica: las seis etapas y la verificacion de calidad de cada una.
    $faltan7 = \local_chatbot\ux::on('revisioncontenido')
        ? diseno::missing_items(diseno::CONSOLIDACION, diseno::normalize_product($documento), true) : [];
    if ($faltan7): ?>
        <div class="alert alert-warning small">
            <?php echo get_string('diseno_check_missing_consolidation', 'local_chatbot'); ?>
            <ul class="mb-0 mt-1"><?php foreach ($faltan7 as $falta): ?><li><?php echo s($falta); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php // Inconsistencias aceptadas que registraron las etapas: siguen visibles hasta la Etapa 8 (bloque 1).
    $aceptadascc = \local_chatbot\ux::on('controlcambios') ? diseno::accepted_inconsistencies($contenidos) : [];
    if ($aceptadascc): ?>
        <div class="alert alert-info small" role="status">
            <?php echo get_string('diseno_cc_accepted', 'local_chatbot'); ?>
            <ul class="mb-0 mt-1"><?php foreach ($aceptadascc as $a): ?><li><?php echo s($a); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php // Hallazgos objetivos detectados por codigo en las etapas 2 a 6 (interruptor «auditoriam2»).
    $docnorm = diseno::normalize_product($documento);
    $auditoriaon = \local_chatbot\ux::on('auditoriam2');
    $exigeanexo = $consolidaia && \local_chatbot\ux::on('controlcambios') && $auditoriaon;
    $tieneanexo = diseno::has_consistency_annex($docnorm);
    $puedeconfirmar = diseno::can_confirm_consolidation($docnorm, $exigeanexo);
    $hallazgosphp = $auditoriaon ? diseno::design_audit_facts($contenidos, $fichadata ?? []) : [];
    if ($hallazgosphp): ?>
        <div class="alert alert-warning small" role="status">
            <?php echo get_string('diseno_audit_facts', 'local_chatbot'); ?>
            <ul class="mb-0 mt-1"><?php foreach ($hallazgosphp as $h): ?><li><?php echo s($h); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php // Verificacion de consistencia entre etapas (ajuste del Prompt Maestro del 10-11-2026).
    if (\local_chatbot\ux::on('controlcambios') && ($porasistente || $tieneanexo)):
        $inconsistencias = diseno::inconsistencies($docnorm);
        if ($inconsistencias): ?>
            <div class="alert alert-warning small" role="status">
                <?php echo get_string('diseno_cc_inconsistent', 'local_chatbot'); ?>
                <ul class="mb-0 mt-1"><?php foreach ($inconsistencias as $i): ?><li><?php echo s($i); ?></li><?php endforeach; ?></ul>
            </div>
        <?php elseif ($tieneanexo): ?>
            <p class="small text-success mb-2"><i class="fa fa-check-circle me-1"></i><?php
                echo get_string('diseno_cc_consistent', 'local_chatbot'); ?></p>
        <?php endif;
    endif; ?>
    <?php if (!$vigente && !$generando): ?>
        <?php if (!$puedeconfirmar): ?>
            <div class="alert alert-info small" role="status">
                <?php echo get_string('diseno_audit_required', 'local_chatbot'); ?>
            </div>
        <?php endif; ?>
        <form method="post" action="<?php echo $pageurl->out(false); ?>" class="mb-4 d-flex flex-wrap gap-2">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <?php // Control de cambios: tambien si la consolidacion no trae la verificacion de consistencia o no marca las
            // inconsistencias aceptadas que registraron las etapas.
            $rehacercc = \local_chatbot\ux::on('controlcambios') && ($porasistente || $tieneanexo)
                && diseno::needs_reconsolidation($docnorm, $aceptadascc); ?>
            <?php if ($consolidaia && (!$puedeconfirmar || !$porasistente || $faltan7 || $rehacercc)): ?>
                <?php // Prompt Maestro: consolidar «en extenso», sin repetir los campos comunes + auditoria de consistencia. ?>
                <button type="submit" name="action" value="consolidar" class="btn btn-primary px-4"
                        data-espera="<?php echo s(get_string('diseno_consolidating', 'local_chatbot')); ?>">
                    <i class="fa fa-magic me-1"></i> <?php echo get_string($exigeanexo ? 'diseno_consolidate_audit' : 'diseno_consolidate_ai', 'local_chatbot'); ?>
                </button>
            <?php endif; ?>
            <?php if ($puedeconfirmar): ?>
                <button type="submit" name="action" value="confirmar" class="btn btn-success px-4"
                        data-espera="<?php echo s(get_string('exante_saving', 'local_chatbot')); ?>">
                    <i class="fa fa-check me-1"></i> <?php echo get_string('diseno_confirm', 'local_chatbot'); ?>
                </button>
            <?php endif; ?>
        </form>
        <?php if ($consolidaia && !$porasistente && $puedeconfirmar): ?>
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
    <?php if ($aprobado):
        $opon = \local_chatbot\ux::on('docoperativo');
        $filaop = $opon ? ($filas[diseno::OPERATIVO] ?? null) : null;
        $huella7 = (string) ($filas[diseno::CONSOLIDACION]->huella ?? '');
        $opgenerando = $filaop && in_array((string) $filaop->generacion, [exante::PENDIENTE, exante::PROCESANDO], true);
        $opvigente = $opon && documento_operativo::is_current($filaop, $huella7);
        $opver = $opvigente ? documento_operativo::version_number($filaop) : 0;
        $opmarcas = $opvigente ? documento_operativo::review_marks_count((string) $filaop->contenido) : 0; ?>
        <?php // Con esto cierra el proceso del modulo: el documento final y el documento operativo quedan disponibles. ?>
        <div class="alert alert-success">
            <strong><?php echo get_string('diseno_approved', 'local_chatbot'); ?></strong>
            <div class="mt-2 d-flex flex-wrap gap-2">
                <a class="chatbot-gatebtn" href="<?php echo (new moodle_url($pageurl, ['action' => 'final']))->out(false); ?>">
                    <i class="fa fa-file-word-o me-1"></i> <?php echo get_string('diseno_final_download', 'local_chatbot'); ?></a>
                <?php if ($opvigente): ?>
                    <a class="chatbot-gatebtn" href="<?php echo (new moodle_url($pageurl, ['action' => 'operativo']))->out(false); ?>">
                        <i class="fa fa-file-text-o me-1"></i> <?php echo get_string('diseno_operativo_download', 'local_chatbot', $opver); ?></a>
                <?php endif; ?>
                <?php if (\local_chatbot\ux::on('centrorecursos')): ?>
                    <a class="chatbot-gatebtn" href="<?php echo (new moodle_url('/local/chatbot/centro_recursos.php',
                        ['courseid' => $courseid, 'fichaid' => (int) (exante::get_ficha($USER->id, $courseid)->id ?? 0)]))->out(false); ?>">
                        <i class="fa fa-cubes me-1"></i> <?php echo get_string('centro_link_fase3', 'local_chatbot'); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($opon): ?>
            <?php if ($opgenerando): ?>
                <div class="card shadow-sm border-0 mb-4" id="diseno-generando" role="status"
                     data-id="<?php echo (int) $filaop->id; ?>" data-estado="<?php echo s($filaop->generacion); ?>"
                     data-url="<?php echo s((new moodle_url('/local/chatbot/diseno.php', ['courseid' => $courseid]))->out(false)); ?>"
                     data-sesskey="<?php echo sesskey(); ?>"
                     data-volver="<?php echo s((new moodle_url($pageurl, ['g' => time()], 'producto'))->out(false)); ?>">
                    <div class="card-body">
                        <h5 class="mb-2"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?php
                            echo get_string('diseno_operativo_generating', 'local_chatbot'); ?></h5>
                        <p class="small text-muted mb-0"><?php echo get_string('diseno_generating_long', 'local_chatbot'); ?></p>
                    </div>
                </div>
            <?php elseif ($opvigente): ?>
                <div class="card border mb-4">
                    <div class="card-body py-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <p class="small text-muted mb-0"><?php echo get_string('diseno_operativo_help', 'local_chatbot'); ?></p>
                            <form method="post" action="<?php echo $pageurl->out(false); ?>" class="m-0">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <button type="submit" name="action" value="generaroperativo" class="btn btn-outline-secondary btn-sm"
                                        data-espera="<?php echo s(get_string('diseno_operativo_generating', 'local_chatbot')); ?>">
                                    <i class="fa fa-refresh me-1"></i> <?php echo get_string('diseno_operativo_regenerate', 'local_chatbot'); ?>
                                </button>
                            </form>
                        </div>
                        <?php if ($opmarcas > 0): ?>
                            <div class="alert alert-warning small mt-2 mb-0 py-2">
                                <?php echo get_string('diseno_operativo_revisar', 'local_chatbot', $opmarcas); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="card border mb-4">
                    <div class="card-body py-3">
                        <?php if ($filaop && $filaop->generacion === exante::ERROR): ?>
                            <div class="alert alert-warning small mb-2 py-2"><?php
                                echo get_string('exante_error_' . ($filaop->error ?: 'servicio'), 'local_chatbot') . ' '
                                    . get_string('diseno_error_retry', 'local_chatbot'); ?></div>
                        <?php endif; ?>
                        <p class="small text-muted mb-2"><?php echo get_string('diseno_operativo_help', 'local_chatbot'); ?></p>
                        <form method="post" action="<?php echo $pageurl->out(false); ?>" class="m-0">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <button type="submit" name="action" value="generaroperativo" class="btn btn-primary btn-sm"
                                    data-espera="<?php echo s(get_string('diseno_operativo_generating', 'local_chatbot')); ?>">
                                <i class="fa fa-file-text-o me-1"></i> <?php echo get_string('diseno_operativo_generate', 'local_chatbot'); ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
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
