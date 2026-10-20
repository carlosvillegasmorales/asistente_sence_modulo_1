<?php
/**
 * Puerta de entrada del Modulo 1 (componente 0: bienvenida y perfilamiento).
 *
 * Recoge el perfil de la persona facilitadora y su aceptacion informada de las
 * condiciones de uso de IA, y muestra el estado de su recorrido por los cinco
 * componentes del modulo. Mientras no exista consentimiento, el asistente no
 * opera: es condicion previa, no un aviso.
 */

require_once(__DIR__ . '/../../config.php');

[$courseid, $context] = \local_chatbot\course_bridge::setup_page('/local/chatbot/index.php',
    'start_title', 'start_heading');

$message = '';
$messagetype = 'info';

// Pagina de perfil segun el acta de la reunion con Interfases del 13-10-2026 (interruptor perfilacta).
$acta = \local_chatbot\ux::on('perfilacta');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $data = [
        'rol' => optional_param('rol', '', PARAM_ALPHANUMEXT),
        'rolotro' => trim(optional_param('rolotro', '', PARAM_TEXT)),
        'programa' => optional_param('programa', '', PARAM_ALPHANUMEXT),
        'programaotro' => trim(optional_param('programaotro', '', PARAM_TEXT)),
        'sector' => trim(optional_param('sector', '', PARAM_TEXT)),
        'modalidad' => optional_param('modalidad', '', PARAM_ALPHANUMEXT),
        'experiencia' => optional_param('experiencia', '', PARAM_ALPHANUMEXT),
        'familiaridad' => optional_param('familiaridad', '', PARAM_ALPHANUMEXT),
        'aplicacion' => optional_param('aplicacion_nr', 0, PARAM_INT)
            ? 'nr' : trim(optional_param('aplicacion', '', PARAM_TEXT)),
        'fortalecer' => optional_param_array('fortalecer', [], PARAM_ALPHANUMEXT),
        'fortalecerotro' => trim(optional_param('fortalecerotro', '', PARAM_TEXT)),
        'tiempo' => optional_param('tiempo', '', PARAM_ALPHANUM),
        'consent' => optional_param('consent', 0, PARAM_INT),
    ];
    $errores = \local_chatbot\profile::validate($data);

    // El consentimiento no se puede dar por supuesto ni heredar de otra sesion.
    if ($errores) {
        $message = implode(' ', array_map(fn($e) => get_string($e, 'local_chatbot'), $errores));
        $messagetype = 'danger';
    } else if (empty($data['consent']) && !\local_chatbot\profile::has_consent($USER->id, $courseid)) {
        $message = get_string('start_error_consent', 'local_chatbot');
        $messagetype = 'danger';
    } else {
        \local_chatbot\profile::save($USER->id, $courseid, $data);
        $message = get_string('start_saved', 'local_chatbot');
        $messagetype = 'success';
    }
}

$profile = \local_chatbot\profile::get($USER->id, $courseid);
$hasconsent = \local_chatbot\profile::has_consent($USER->id, $courseid);

$options = \local_chatbot\profile::get_options();

// El curso refleja lo que acaba de pasar en el recorrido: barra de progreso,
// restricciones de acceso e informes de finalizacion.
\local_chatbot\course_bridge::sync($USER->id, $courseid);

echo $OUTPUT->header();
?>

<div class="container-fluid" style="max-width: 900px;">

    <?php echo \local_chatbot\course_bridge::route_bar($USER->id, $courseid, 'perfil'); ?>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messagetype; ?>"><?php echo s($message); ?></div>
    <?php endif; ?>

    <?php // El acta del 13-10-2026 elimina «Comienza aqui» y «Estado de tu recorrido». ?>
    <?php if (!$acta): ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <h4 class="mb-2"><?php echo get_string('start_welcome_title', 'local_chatbot'); ?></h4>
            <p class="text-muted mb-0"><?php echo get_string('start_welcome_body', 'local_chatbot'); ?></p>
        </div>
    </div>

    <h5 class="mb-3"><?php echo get_string('start_route', 'local_chatbot'); ?></h5>
    <div class="list-group mb-4">
        <?php
        // Mismas etapas, orden y estado que la barra de ruta (route_model()), para que nunca digan
        // cosas distintas: con la ruta de 6 etapas trae la Ruta Formativa Base y el PDP opcional.
        $nombres = ['perfil' => 'step_profile', 'diagnostico' => 'step_diagnostic', 'ruta' => 'step_ruta',
            'pdp' => 'step_pdp', 'proyecto' => 'step_project', 'cierre' => 'step_closing'];
        $modelo = \local_chatbot\course_bridge::route_model($USER->id, $courseid, 1);
        $seis = in_array('ruta', $modelo['keys'], true);
        $steps = [];
        foreach ($modelo['keys'] as $i => $clave) {
            $steps[] = [
                'done' => $modelo['done'][$i + 1],
                // Con la ruta de 6 etapas, los mismos nombres del documento del cliente que la barra.
                'label' => get_string($seis ? \local_chatbot\course_bridge::step_name_key($clave, true) : $nombres[$clave],
                    'local_chatbot'),
                'url' => $clave === 'perfil' ? null : \local_chatbot\course_bridge::step_url($courseid, $clave),
                'optional' => in_array($i + 1, $modelo['optional'], true),
                'locked' => !empty($modelo['locked'][$i + 1]),
            ];
        }
        foreach ($steps as $index => $step):
            $icon = $step['done'] ? '&#10003;' : ($step['locked'] ? '🔒' : ($index + 1));
            $badgeclass = $step['done'] ? 'bg-success' : 'bg-secondary';
        ?>
            <div class="list-group-item d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <span class="badge <?php echo $badgeclass; ?> rounded-circle me-3"
                          style="width:28px;height:28px;line-height:20px;"><?php echo $icon; ?></span>
                    <span><?php echo s($step['label']); ?></span>
                    <?php if ($step['optional']): ?>
                        <span class="chatbot-routebar-optional ms-2"><?php echo get_string('route_optional', 'local_chatbot'); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($step['url'] && $hasconsent && !$step['locked']): ?>
                    <a href="<?php echo $step['url']; ?>" class="btn btn-sm btn-outline-primary">
                        <?php echo get_string($step['done'] ? 'action_review' : 'action_start', 'local_chatbot'); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <h5 class="mb-3"><?php echo get_string($acta ? 'start_profile_title_acta' : 'start_profile_title', 'local_chatbot'); ?></h5>
            <p class="text-muted small"><?php echo get_string('start_profile_help', 'local_chatbot'); ?></p>

            <form method="POST">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

                <?php
                // Las 9 preguntas del perfilamiento del acta del 13-10-2026.
                $valor = static function (string $campo) use ($profile): string {
                    return $profile ? (string) ($profile->$campo ?? '') : '';
                };
                $radios = static function (string $campo, string $prefijo) use ($options, $valor): void {
                    foreach ($options[$campo] as $opcion) {
                        $id = $campo . '_' . $opcion;
                        echo '<div class="form-check"><input class="form-check-input" type="radio" name="' . $campo
                            . '" id="' . $id . '" value="' . $opcion . '"' . ($valor($campo) === $opcion ? ' checked' : '') . '>'
                            . '<label class="form-check-label" for="' . $id . '">' . get_string($prefijo . $opcion, 'local_chatbot')
                            . '</label></div>';
                    }
                };
                $marcados = array_filter(explode(',', $valor('fortalecer')));
                ?>
                <div class="mb-3">
                    <label for="rol" class="form-label fw-semibold">1. <?php echo get_string('field_rol_acta', 'local_chatbot'); ?></label>
                    <select name="rol" id="rol" class="form-select" required>
                        <option value=""><?php echo get_string('choose'); ?></option>
                        <?php foreach ($options['rol'] as $opcion): ?>
                            <option value="<?php echo $opcion; ?>" <?php echo $valor('rol') === $opcion ? 'selected' : ''; ?>>
                                <?php echo get_string('rol_' . $opcion, 'local_chatbot'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="rolotro" class="form-control mt-2" maxlength="255" autocomplete="off"
                           placeholder="<?php echo s(get_string('field_otro_especificar', 'local_chatbot')); ?>"
                           value="<?php echo s($valor('rolotro')); ?>">
                </div>

                <div class="mb-3">
                    <label for="programa" class="form-label fw-semibold">2. <?php echo get_string('field_programa', 'local_chatbot'); ?></label>
                    <select name="programa" id="programa" class="form-select" required>
                        <option value=""><?php echo get_string('choose'); ?></option>
                        <?php foreach ($options['programa'] as $opcion): ?>
                            <option value="<?php echo $opcion; ?>" <?php echo $valor('programa') === $opcion ? 'selected' : ''; ?>>
                                <?php echo get_string('prog_' . $opcion, 'local_chatbot'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="programaotro" class="form-control mt-2" maxlength="255" autocomplete="off"
                           placeholder="<?php echo s(get_string('field_otro_especificar', 'local_chatbot')); ?>"
                           value="<?php echo s($valor('programaotro')); ?>">
                </div>

                <div class="mb-3">
                    <label for="sector" class="form-label fw-semibold">3. <?php echo get_string('field_sector_acta', 'local_chatbot'); ?></label>
                    <input type="text" autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="sector" id="sector" class="form-control" maxlength="255"
                           placeholder="<?php echo s(get_string('field_sector_help', 'local_chatbot')); ?>"
                           value="<?php echo s($valor('sector')); ?>">
                </div>

                <fieldset class="mb-3">
                    <legend class="form-label fs-6 fw-semibold">4. <?php echo get_string('field_modalidad_acta', 'local_chatbot'); ?></legend>
                    <?php $radios('modalidad', 'mod_'); ?>
                </fieldset>

                <fieldset class="mb-3">
                    <legend class="form-label fs-6 fw-semibold">5. <?php echo get_string('field_experiencia_acta', 'local_chatbot'); ?></legend>
                    <?php $radios('experiencia', 'exp_'); ?>
                </fieldset>

                <fieldset class="mb-3">
                    <legend class="form-label fs-6 fw-semibold">6. <?php echo get_string('field_familiaridad', 'local_chatbot'); ?></legend>
                    <?php $radios('familiaridad', 'fam_'); ?>
                </fieldset>

                <div class="mb-3">
                    <label for="aplicacion" class="form-label fw-semibold">7. <?php echo get_string('field_aplicacion', 'local_chatbot'); ?></label>
                    <textarea name="aplicacion" id="aplicacion" class="form-control" rows="3" autocomplete="off"><?php
                        echo $valor('aplicacion') === 'nr' ? '' : s($valor('aplicacion')); ?></textarea>
                    <div class="form-check mt-1">
                        <input class="form-check-input" type="checkbox" name="aplicacion_nr" id="aplicacion_nr" value="1"
                            <?php echo $valor('aplicacion') === 'nr' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="aplicacion_nr"><?php echo get_string('prefiero_no_responder', 'local_chatbot'); ?></label>
                    </div>
                </div>

                <fieldset class="mb-3" id="fortalecer-grupo">
                    <legend class="form-label fs-6 fw-semibold">8. <?php echo get_string('field_fortalecer', 'local_chatbot'); ?></legend>
                    <?php foreach ($options['fortalecer'] as $opcion): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="fortalecer[]" id="fort_<?php echo $opcion; ?>"
                                   value="<?php echo $opcion; ?>" <?php echo in_array($opcion, $marcados, true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="fort_<?php echo $opcion; ?>"><?php echo get_string('fort_' . $opcion, 'local_chatbot'); ?></label>
                        </div>
                    <?php endforeach; ?>
                    <input type="text" name="fortalecerotro" class="form-control mt-2" maxlength="255" autocomplete="off"
                           placeholder="<?php echo s(get_string('field_otro_especificar', 'local_chatbot')); ?>"
                           value="<?php echo s($valor('fortalecerotro')); ?>">
                    <script>
                    // Maximo dos aspectos: al marcar el segundo, los demas se desactivan.
                    (function() {
                        var cajas = document.querySelectorAll('#fortalecer-grupo input[type=checkbox]');
                        var revisar = function() {
                            var n = Array.prototype.filter.call(cajas, function(c) { return c.checked; }).length;
                            cajas.forEach(function(c) { c.disabled = !c.checked && n >= 2; });
                        };
                        cajas.forEach(function(c) { c.addEventListener('change', revisar); });
                        revisar();
                    })();
                    </script>
                </fieldset>

                <fieldset class="mb-3">
                    <legend class="form-label fs-6 fw-semibold">9. <?php echo get_string('field_tiempo_acta', 'local_chatbot'); ?></legend>
                    <?php $radios('tiempo', 'tiempo_'); ?>
                </fieldset>

                <?php if (!$hasconsent): ?>
                    <div class="alert alert-light border">
                        <h6><?php echo get_string('consent_title', 'local_chatbot'); ?></h6>
                        <?php // RU-CONS-002: si la declaracion cambio, se dice por que se pregunta de nuevo. ?>
                        <?php if (\local_chatbot\profile::consent_status($USER->id, $courseid) === 'cambio'): ?>
                            <p class="small fw-bold mb-2"><?php
                                echo get_string('consent_changed', 'local_chatbot'); ?></p>
                        <?php endif; ?>
                        <p class="small mb-2"><?php echo get_string('consent_body', 'local_chatbot'); ?></p>

                        <?php // RU-CONS-004: quien procesa, donde, por cuanto y a quien reclamar. ?>
                        <dl class="row small mb-2">
                            <?php foreach (\local_chatbot\profile::data_disclosure() as $field): ?>
                                <dt class="col-sm-5 fw-bold"><?php echo s($field['label']); ?></dt>
                                <dd class="col-sm-7 <?php echo $field['declared'] ? '' : 'text-danger'; ?>">
                                    <?php echo s($field['value']); ?>
                                </dd>
                            <?php endforeach; ?>
                        </dl>
                        <?php if (!\local_chatbot\profile::disclosure_is_complete()): ?>
                            <p class="small text-danger mb-2"><?php
                                echo get_string('disclosure_incomplete', 'local_chatbot'); ?></p>
                        <?php endif; ?>

                        <?php if (!$acta): ?>
                        <div class="form-check">
                            <input type="checkbox" name="consent" id="consent" value="1" class="form-check-input">
                            <label class="form-check-label" for="consent">
                                <?php echo get_string('consent_accept', 'local_chatbot'); ?>
                            </label>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="small text-success">
                        &#10003; <?php echo get_string('consent_given', 'local_chatbot',
                            userdate($profile->consenttime, get_string('strftimedatetimeshort'))); ?>
                    </p>
                <?php endif; ?>

                <?php if ($acta && !$hasconsent): ?>
                    <button type="submit" name="consent" value="1" class="btn btn-primary">
                        <?php echo get_string('profile_accept_save', 'local_chatbot'); ?>
                    </button>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary">
                        <?php echo get_string('action_save', 'local_chatbot'); ?>
                    </button>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php // Terminar un paso y no saber que sigue es donde la gente se detiene. ?>
    <?php if ($acta && $hasconsent): ?>
        <?php // Acta del 13-10-2026: siempre lleva a la etapa siguiente (el autodiagnostico), aunque ya este hecho. ?>
        <div class="card border-success shadow-sm mt-4 mb-4"><div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
            <h6 class="mb-0"><?php echo get_string('next_title_perfil', 'local_chatbot'); ?></h6>
            <div class="d-flex flex-wrap gap-2">
                <?php // Solo en ventana emergente (revision final del 17-10-2026): la cierra y recarga el curso. ?>
                <button type="button" class="btn btn-outline-secondary text-nowrap chatbot-cerrar-emergente d-none">
                    <?php echo get_string('profile_close_popup', 'local_chatbot'); ?></button>
                <a href="<?php echo \local_chatbot\course_bridge::step_url($courseid, 'diagnostico'); ?>" class="btn btn-success text-nowrap">
                    <?php echo get_string('profile_next_stage', 'local_chatbot'); ?></a>
            </div>
        </div></div>
    <?php elseif (!$acta): ?>
        <?php echo \local_chatbot\course_bridge::next_step_card($USER->id, $courseid, 'perfil'); ?>
    <?php endif; ?>

    <div class="chatbot-back-abajo">
        <?php echo \local_chatbot\course_bridge::back_to_course_button($courseid, 'perfil'); ?>
    </div>
</div>

<?php if (\local_chatbot\ux::on('perfilpopup')): ?>
<script>
// La ficha se abre como ventana emergente desde el curso (revision final del 17-10-2026): ahi
// «Regresar al curso» abriria el curso dentro de la ventana emergente. Se ocultan esos botones
// y aparece «Cerrar», que cierra la ventana y recarga el curso para que ya se vea la ficha completada.
(function() {
    var emergente = false;
    try { emergente = !!(window.opener && !window.opener.closed && window.opener !== window); } catch (e) { emergente = false; }
    if (!emergente) { return; }
    document.querySelectorAll('.chatbot-backtocourse, .chatbot-back-abajo').forEach(function(el) { el.style.display = 'none'; });
    document.querySelectorAll('.chatbot-cerrar-emergente').forEach(function(boton) {
        boton.classList.remove('d-none');
        boton.addEventListener('click', function() {
            try { window.opener.location.reload(); } catch (e) { /* otra pestana u otro dominio */ }
            window.close();
        });
    });
})();
</script>
<?php endif; ?>

<?php
echo $OUTPUT->footer();
