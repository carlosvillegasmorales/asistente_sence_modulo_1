<?php
/**
 * Modulo 3, Fase 1: ficha de registro de la implementacion (evaluacion durante) y su validacion con el checklist.
 *
 * Prompt Maestro del Asistente de Aseguramiento de la Calidad de los Aprendizajes (Interfases, 06-11-2026): la persona elige,
 * de sus diseños guardados en el Modulo 2, el diseño de la experiencia que implemento (el asistente recupera de ahi el diseño,
 * el plan formativo y el perfil ocupacional), completa en linea la ficha y pulsa «Guardar y validar». El informe (checklist por
 * item, condicion, tiempo disponible y observaciones) aparece en esta misma pagina, y con Aprobado o Aprobado con observaciones
 * se habilita la Fase 2. La logica vive en classes/implementacion.php.
 *
 * La validacion corre en una segunda llamada (action=ejecutar) que la pagina dispara sola y luego consulta: el modelo puede
 * tardar mas que el tiempo que el servidor web espera una respuesta, y asi el informe se guarda igual aunque esa llamada se
 * corte.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_chatbot\exante;
use local_chatbot\implementacion;

$courseid = required_param('courseid', PARAM_INT);
if ((int) $courseid === (int) SITEID) {
    throw new moodle_exception('invalidcourseid');
}
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/chatbot:use', $context);

$pageurl = new moodle_url('/local/chatbot/ficha_implementacion.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('impl_title', 'local_chatbot'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('impl_title', 'local_chatbot'));
$PAGE->requires->js(new moodle_url('/local/chatbot/js/espera.js'), true);

if (!\local_chatbot\ux::on('fichaimpl')) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('impl_disabled', 'local_chatbot'), 'info');
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
        $estado = implementacion::run_validation($valid, $USER->id);
    } else {
        $estado = $DB->get_field('local_chatbot_impl_val', 'estado', ['id' => $valid, 'userid' => $USER->id]);
        if ($estado === implementacion::PROCESANDO) {
            // latest_validation da por caida la que lleva demasiado tiempo.
            $ultima = implementacion::latest_validation($USER->id, $courseid);
            $estado = ($ultima && (int) $ultima->id === $valid) ? $ultima->estado : $estado;
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['estado' => $estado ?: implementacion::ERROR]);
    exit;
}

// --- Varias evaluaciones: abrir una guardada o empezar otra (el modulo se usa una y otra vez) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['abrir', 'nuevo', 'borrar'], true)) {
    require_sesskey();
    if ($action === 'borrar') {
        // Una a la vez: quien lo pide elige cual se va y se queda con las que le sirven.
        $resultado = implementacion::delete_ficha($USER->id, $courseid, required_param('id', PARAM_INT));
        $mensajes = [implementacion::DELETED => ['impl_evals_deleted', \core\output\notification::NOTIFY_SUCCESS],
            implementacion::VALIDATING => ['impl_evals_delete_busy', \core\output\notification::NOTIFY_WARNING],
            implementacion::MISSING => ['impl_evals_delete_missing', \core\output\notification::NOTIFY_WARNING]];
        redirect($pageurl, get_string($mensajes[$resultado][0], 'local_chatbot'), null, $mensajes[$resultado][1]);
    }
    if ($action === 'abrir') {
        implementacion::activate($USER->id, $courseid, required_param('id', PARAM_INT));
        redirect($pageurl, get_string('impl_evals_opened', 'local_chatbot'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    implementacion::new_ficha($USER->id, $courseid, (bool) optional_param('copiar', 0, PARAM_BOOL));
    redirect($pageurl, get_string('impl_evals_created', 'local_chatbot'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// --- Guardar (y validar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['guardar', 'validar'], true)) {
    require_sesskey();
    $raw = [];
    foreach (implementacion::field_keys() as $clave => $tipo) {
        $raw[$clave] = optional_param($clave, '', PARAM_TEXT);
    }
    $data = implementacion::clean($raw);
    // El diseño elegido: solo uno de la propia persona.
    $disenoid = optional_param('disenoid', 0, PARAM_INT);
    $elegido = $disenoid > 0 ? implementacion::get_design($disenoid, $USER->id) : null;
    $fichaid = implementacion::save_ficha($USER->id, $courseid, $data, $elegido ? $disenoid : null);

    $tipo = \core\output\notification::NOTIFY_SUCCESS;
    $mensaje = get_string('impl_saved', 'local_chatbot');
    $destino = $pageurl;
    if ($action === 'validar') {
        $guardada = implementacion::get_ficha($USER->id, $courseid);
        $diseno = (int) $guardada->disenoid > 0 ? implementacion::get_design((int) $guardada->disenoid, $USER->id) : null;
        $falta = implementacion::missing_inputs($data, $diseno !== null && $diseno['disponible']);
        if ($diseno !== null && !$diseno['disponible']) {
            // Prompt Maestro: si el diseño no esta disponible o no tiene su plan formativo, se informa y no se inicia.
            $tipo = \core\output\notification::NOTIFY_WARNING;
            $mensaje = get_string('impl_design_unavailable_' . $diseno['motivo'], 'local_chatbot');
        } else if ($falta) {
            // Control de entrada del Prompt Maestro: no se avanza y se pide lo que falta, en concreto.
            $tipo = \core\output\notification::NOTIFY_WARNING;
            $mensaje = get_string('impl_missing', 'local_chatbot', implode(' · ', $falta));
        } else if (!\local_chatbot\ai\provider_factory::is_configured()) {
            $tipo = \core\output\notification::NOTIFY_ERROR;
            $mensaje = get_string('impl_error_sinclave', 'local_chatbot');
        } else {
            implementacion::start_validation($guardada);
            $mensaje = '';
            $destino = new moodle_url($pageurl, [], 'informe');
            // Avisos con reglas fijas: se muestran y la validacion sigue.
            $avisosform = \local_chatbot\ux::on('avisosimpl') ? implementacion::form_warnings($data) : [];
            if ($avisosform) {
                $tipo = \core\output\notification::NOTIFY_WARNING;
                $mensaje = get_string('impl_warn_validating', 'local_chatbot', implode(' · ', $avisosform));
            }
        }
    }
    redirect($destino, $mensaje, null, $tipo);
}

// --- Pagina ---
$ficha = implementacion::get_ficha($USER->id, $courseid);
$data = $ficha ? $ficha->data : implementacion::clean([]);
$val = implementacion::latest_validation($USER->id, $courseid);
$vigente = $val && $ficha && $val->huella === implementacion::ficha_fingerprint($ficha);
$listo = $val && $val->estado === implementacion::LISTO && is_array($val->informe);
$avanza = $listo && $vigente && implementacion::can_advance($val->condicion);
$disenos = implementacion::list_designs($USER->id);
$elegido = ($ficha && (int) $ficha->disenoid > 0) ? implementacion::get_design((int) $ficha->disenoid, $USER->id) : null;

// Las fases del Modulo 3 son secciones del curso que se llaman «Fase 1: ...», «Fase 2: ...».
$nombres = [];
foreach (get_fast_modinfo($course)->get_section_info_all() as $seccion) {
    $nombres[$seccion->section] = get_section_name($course, $seccion);
}
$fase1 = exante::phase_section($nombres, 1);
$fase2 = exante::phase_section($nombres, 2);
$urlcurso = new moodle_url('/course/view.php', ['id' => $courseid]);
// course_get_url respeta el formato del curso (pestañas); sin seccion, la portada.
$urlfase1 = ($fase1 !== null ? course_get_url($course, $fase1) : null) ?: $urlcurso;
$urlfase2 = ($fase2 !== null ? course_get_url($course, $fase2) : null) ?: $urlcurso;

$estadoclase = [implementacion::CUMPLE => 'success', implementacion::PARCIAL => 'warning', implementacion::NO_CUMPLE => 'danger'];
$condclase = [implementacion::APROBADO => 'success', implementacion::OBSERVACIONES => 'warning', implementacion::NO_APROBADO => 'danger'];

/**
 * Pinta un campo de la ficha.
 *
 * @param array $campo
 * @param array $data
 * @param bool $oculto Criterio agregado que aun no se muestra.
 * @return void
 */
function local_chatbot_impl_campo(array $campo, array $data, bool $oculto = false): void {
    $id = $campo['id'];
    echo '<div class="mb-3 pb-3 border-bottom"' . (!empty($campo['adicional']) ? ' data-criterio-adicional="1"' : '')
        . (strpos($id, 'criterio') === 0 ? ' data-criterio="' . (int) substr($id, 8) . '"' : '') . ($oculto ? ' hidden' : '') . '>';
    if (!empty($campo['subtitulo'])) {
        echo '<h6 class="fw-bold text-dark mb-2">' . s($campo['subtitulo']) . '</h6>';
    }
    if (!empty($campo['antes'])) {
        echo '<p class="small text-muted mb-3">' . s($campo['antes']) . '</p>';
    }
    $domid = 'f_' . $id;
    if ($campo['tipo'] === implementacion::T_UNA) {
        $rotulo = (!empty($campo['num']) ? $campo['num'] . '. ' : '') . $campo['label'];
        $escala = isset($campo['opciones']['1']);
        echo '<fieldset><legend class="fs-6 fw-medium mb-1">' . s($rotulo) . '</legend>';
        $elegida = (string) ($data[$id] ?? '');
        echo '<div class="d-flex ' . ($escala ? 'flex-wrap' : 'flex-column') . '" style="gap: ' . ($escala ? '8px 22px' : '6px') . ';">';
        foreach ($campo['opciones'] as $k => $texto) {
            $opid = $domid . '_' . $k;
            echo '<div class="form-check"><input class="form-check-input" type="radio" name="' . $id . '" id="' . $opid
                . '" value="' . s($k) . '"' . ($elegida === (string) $k ? ' checked' : '') . '>';
            echo '<label class="form-check-label small" for="' . $opid . '">' . s($texto);
            if (!$escala && !empty($campo['descriptores'][$k])) {
                echo ' <span class="text-muted">— ' . s($campo['descriptores'][$k]) . '</span>';
            }
            echo '</label></div>';
        }
        echo '</div>';
        if ($escala && !empty($campo['descriptores'])) {
            echo '<details class="small mt-2"><summary class="text-primary" style="cursor: pointer;">'
                . get_string('impl_how_to_rate', 'local_chatbot') . '</summary><table class="table table-sm table-borderless mb-0 mt-1">';
            foreach ($campo['descriptores'] as $valor => $descripcion) {
                echo '<tr><th class="text-nowrap pe-2" style="width: 1%;">' . s($campo['opciones'][(string) $valor]) . '</th><td>'
                    . s($descripcion) . '</td></tr>';
            }
            echo '</table></details>';
        }
        echo '</fieldset></div>';
        return;
    }
    $rotulo = isset($campo['de']) ? get_string('impl_comment_for', 'local_chatbot', $campo['num_de'])
        : (!empty($campo['num']) && empty($campo['subtitulo']) ? $campo['num'] . '. ' : '') . $campo['label'];
    echo '<label class="form-label fw-medium mb-1" for="' . $domid . '">' . s($rotulo) . '</label>';
    if (!empty($campo['ayuda'])) {
        echo '<p class="small text-muted mb-2">' . s($campo['ayuda']) . '</p>';
    }
    $valor = s($data[$id] ?? '');
    if ($campo['tipo'] === implementacion::T_PARRAFO) {
        echo '<textarea class="form-control" id="' . $domid . '" name="' . $id . '" rows="3" maxlength="'
            . implementacion::LARGO_PARRAFO . '">' . $valor . '</textarea>';
    } else if ($campo['tipo'] === implementacion::T_NUMERO) {
        echo '<input type="number" min="0" step="1" class="form-control" style="max-width: 200px;" id="' . $domid
            . '" name="' . $id . '" value="' . $valor . '">';
    } else {
        echo '<input type="text" class="form-control" id="' . $domid . '" name="' . $id . '" maxlength="'
            . implementacion::LARGO_TEXTO . '" value="' . $valor . '">';
    }
    echo '</div>';
}

echo $OUTPUT->header();
?>

<div class="container-fluid" style="max-width: 900px;">

    <h3 class="mb-3"><?php echo get_string('impl_title', 'local_chatbot'); ?></h3>

    <?php // Las tres fases del modulo, con el mismo estilo que la ruta de los otros modulos. ?>
    <?php echo \local_chatbot\ruta_m3::render($USER->id, $courseid, 1); ?>

    <?php if (\local_chatbot\ux::on('bienvenidam3')): ?>
        <?php // Bienvenida del Prompt Maestro, textual: es lo primero que dice el asistente. ?>
        <div class="card border-0 shadow-sm mb-3" style="background: #eef5fb; border-left: 5px solid #20639b !important;">
            <div class="card-body d-flex gap-3 align-items-start">
                <div aria-hidden="true" style="flex: 0 0 42px; width: 42px; height: 42px; line-height: 42px; border-radius: 50%;
                    background: #20639b; color: #ffffff; text-align: center; font-size: 20px;"><i class="fa fa-comments"></i></div>
                <div>
                    <div class="small text-uppercase fw-bold mb-1" style="color: #20639b; letter-spacing: .5px;"><?php
                        echo s(implementacion::ROL); ?></div>
                    <?php foreach (implementacion::welcome() as $i => $parrafo): ?>
                        <p class="<?php echo $i === 0 ? 'fw-bold mb-2' : 'mb-2'; ?>"><?php echo s($parrafo); ?></p>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php // Tus evaluaciones: cada experiencia es una ficha con su validacion y sus etapas; una esta en uso. ?>
    <?php $evaluaciones = implementacion::list_fichas($USER->id, $courseid); ?>
    <?php if ($evaluaciones): ?>
        <div class="card shadow-sm border-0 mb-4" id="evaluaciones">
            <div class="card-body">
                <h5 class="mb-1"><?php echo get_string('impl_evals_title', 'local_chatbot'); ?></h5>
                <p class="small text-muted mb-3"><?php echo get_string('impl_evals_help', 'local_chatbot'); ?></p>
                <ul class="list-unstyled mb-3">
                    <?php foreach ($evaluaciones as $d):
                        $estado = \local_chatbot\ruta_m3::state_for($d);
                        $nombre = implementacion::evaluation_label($d->data);
                        [$clave, $a] = \local_chatbot\ruta_m3::evaluation_status($estado);
                        $enuso = (int) $d->id === (int) $ficha->id; ?>
                        <li class="d-flex flex-wrap align-items-center gap-2 border-bottom py-2">
                            <div class="flex-grow-1" style="min-width: 220px;">
                                <div class="fw-medium"><?php echo $nombre !== '' ? s($nombre) : get_string('impl_evals_untitled', 'local_chatbot'); ?></div>
                                <div class="small text-muted"><?php echo get_string($clave, 'local_chatbot', $a); ?> ·
                                    <?php echo userdate($d->timemodified, get_string('strftimedatetimeshort', 'langconfig')); ?></div>
                            </div>
                            <?php if ($enuso): ?>
                                <span class="badge text-bg-primary"><?php echo get_string('impl_evals_current', 'local_chatbot'); ?></span>
                            <?php else: ?>
                                <form method="post" action="<?php echo $pageurl->out(false); ?>" class="m-0">
                                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $d->id; ?>">
                                    <button type="submit" name="action" value="abrir" class="btn btn-sm btn-outline-primary"><?php
                                        echo get_string('impl_evals_open', 'local_chatbot'); ?></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?php echo $pageurl->out(false); ?>" class="m-0"
                                  onsubmit="return confirm(this.dataset.confirm);"
                                  data-confirm="<?php echo s(get_string('impl_evals_delete_confirm', 'local_chatbot')); ?>">
                                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="id" value="<?php echo (int) $d->id; ?>">
                                <button type="submit" name="action" value="borrar" class="btn btn-sm btn-outline-danger"
                                        aria-label="<?php echo s(get_string('impl_evals_delete_for', 'local_chatbot',
                                            $nombre !== '' ? $nombre : get_string('impl_evals_untitled', 'local_chatbot'))); ?>"><i
                                        class="fa fa-trash me-1"></i><?php echo get_string('impl_evals_delete', 'local_chatbot'); ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" action="<?php echo $pageurl->out(false); ?>" class="d-flex flex-wrap gap-2 m-0">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <button type="submit" name="action" value="nuevo" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i><?php
                        echo get_string('impl_evals_new', 'local_chatbot'); ?></button>
                    <button type="submit" name="action" value="nuevo" class="btn btn-outline-primary btn-sm"
                            onclick="this.form.copiar.value = 1;"><i class="fa fa-copy me-1"></i><?php
                        echo get_string('impl_evals_new_copy', 'local_chatbot'); ?></button>
                    <input type="hidden" name="copiar" value="0">
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="alert alert-info">
        <strong><?php echo get_string('impl_intro_title', 'local_chatbot'); ?>.</strong>
        <?php echo get_string('impl_intro', 'local_chatbot'); ?>
        <?php if ($val): ?>
            <div class="mt-2"><a class="chatbot-gatebtn" href="#informe"><?php
                echo get_string('exante_report_link', 'local_chatbot'); ?></a></div>
        <?php endif; ?>
    </div>

    <form method="post" action="<?php echo $pageurl->out(false); ?>" id="impl-form">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

        <?php // Primero, el diseño que se evalua: de ahi vienen el diseño, el plan formativo y el perfil ocupacional. ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <h5 class="mb-3 fw-bold text-dark border-bottom pb-2"><?php echo get_string('impl_design_title', 'local_chatbot'); ?></h5>
                <p class="small text-muted"><?php echo get_string('impl_design_help', 'local_chatbot'); ?></p>
                <?php if (!$disenos): ?>
                    <div class="alert alert-warning mb-0"><?php echo get_string('impl_design_none', 'local_chatbot'); ?></div>
                <?php else: ?>
                    <div class="d-flex flex-column" style="gap: 8px;">
                        <?php foreach ($disenos as $d):
                            $ok = $d['disponible'];
                            $seleccionado = $ficha && (int) $ficha->disenoid === (int) $d['ficha']->id;
                            $domid = 'f_disenoid_' . (int) $d['ficha']->id; ?>
                            <div class="form-check border rounded p-2 ps-4 <?php echo $ok ? '' : 'bg-light'; ?>">
                                <input class="form-check-input" type="radio" name="disenoid" id="<?php echo $domid; ?>"
                                       value="<?php echo (int) $d['ficha']->id; ?>"<?php echo $seleccionado ? ' checked' : ''; ?>
                                       <?php echo $ok ? '' : ' disabled'; ?>>
                                <label class="form-check-label" for="<?php echo $domid; ?>">
                                    <span class="fw-medium"><?php echo $d['nombre'] !== '' ? s($d['nombre']) : get_string('impl_design_untitled', 'local_chatbot'); ?></span>
                                    <span class="d-block small text-muted"><?php
                                        if ($ok) {
                                            echo $d['aprobado'] ? get_string('impl_design_approved', 'local_chatbot', (int) $d['puntaje'])
                                                : get_string('impl_design_unrated', 'local_chatbot');
                                        } else {
                                            echo get_string('impl_design_unavailable_' . $d['motivo'], 'local_chatbot');
                                        } ?> · <?php echo userdate($d['ficha']->timemodified, get_string('strftimedatetimeshort', 'langconfig')); ?></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($elegido && $elegido['disponible']):
                        $ref = $elegido['referencia']; ?>
                        <details class="mt-3" open>
                            <summary class="fw-medium text-primary" style="cursor: pointer;"><?php
                                echo get_string('impl_design_reference', 'local_chatbot'); ?></summary>
                            <p class="small text-muted mt-2 mb-2"><?php echo get_string('impl_design_reference_help', 'local_chatbot'); ?></p>
                            <table class="table table-sm small mb-2">
                                <?php foreach ([['impl_ref_plan', $ref['plan']], ['impl_ref_modulo', $ref['modulo']],
                                        ['impl_ref_experiencia', $ref['experiencia']], ['impl_ref_aprendizaje', $ref['aprendizaje']],
                                        ['impl_ref_tiempo_total', $ref['tiempo_total'] === null ? '' : $ref['tiempo_total'] . ' min'],
                                        ['impl_ref_tiempo_asignado', $ref['tiempo_asignado'] === null ? '' : $ref['tiempo_asignado'] . ' min']]
                                        as [$clave, $valor]): ?>
                                    <tr><th class="text-nowrap pe-3" style="width: 1%;"><?php echo get_string($clave, 'local_chatbot'); ?></th>
                                        <td><?php echo $valor !== '' ? s($valor) : '—'; ?></td></tr>
                                <?php endforeach; ?>
                                <tr><th class="text-nowrap pe-3"><?php echo get_string('impl_ref_criterios', 'local_chatbot'); ?></th>
                                    <td><?php if ($ref['criterios']): ?><ol class="mb-0 ps-3"><?php foreach ($ref['criterios'] as $c): ?><li><?php
                                        echo s($c); ?></li><?php endforeach; ?></ol><?php else: ?>—<?php endif; ?></td></tr>
                            </table>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php foreach (implementacion::sections() as $n => $seccion): ?>
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <h5 class="mb-3 fw-bold text-dark border-bottom pb-2"><?php echo $n . '. ' . s($seccion['titulo']); ?></h5>
                    <?php if (!empty($seccion['nota'])): ?>
                        <p class="small text-muted mb-3"><?php echo s($seccion['nota']); ?></p>
                    <?php endif; ?>
                    <?php if ($n === 3): ?>
                        <details class="small mb-3">
                            <summary class="text-primary" style="cursor: pointer;"><?php echo get_string('impl_change_types', 'local_chatbot'); ?></summary>
                            <ul class="mt-1 mb-0">
                                <?php foreach (implementacion::change_types() as $tipo => $descripcion): ?>
                                    <li><strong><?php echo s($tipo); ?>:</strong> <?php echo s($descripcion); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                    <?php
                    // Criterios: los 3 de partida y los agregados hasta el ultimo con texto; el resto, ocultos.
                    $ultimocriterio = implementacion::CRITERIOS_MIN;
                    for ($i = implementacion::CRITERIOS_MAX; $i > implementacion::CRITERIOS_MIN; $i--) {
                        if (trim((string) ($data['criterio' . $i] ?? '')) !== '') {
                            $ultimocriterio = $i;
                            break;
                        }
                    }
                    foreach ($seccion['campos'] as $campo) {
                        $numcriterio = strpos($campo['id'], 'criterio') === 0 ? (int) substr($campo['id'], 8) : 0;
                        local_chatbot_impl_campo($campo, $data, $numcriterio > $ultimocriterio);
                        if ($numcriterio === implementacion::CRITERIOS_MAX) {
                            echo '<div class="mb-3 pb-3 border-bottom"><button type="button" class="btn btn-outline-primary btn-sm"'
                                . ' id="impl-agregar-criterio"' . ($ultimocriterio >= implementacion::CRITERIOS_MAX ? ' hidden' : '') . '>'
                                . '<i class="fa fa-plus me-1" aria-hidden="true"></i>'
                                . get_string('impl_add_criterion', 'local_chatbot') . '</button></div>';
                        }
                    } ?>

                    <?php if ($n === 4): ?>
                        <?php // 4.1: una fila por cada criterio de la seccion 2; 4.2: observaciones opcionales. ?>
                        <h6 class="fw-bold text-dark mb-2">4.1 <?php echo get_string('impl_table41_title', 'local_chatbot'); ?></h6>
                        <p class="small text-muted mb-2"><?php echo get_string('impl_table41_help', 'local_chatbot'); ?></p>
                        <details class="small mb-3">
                            <summary class="text-primary" style="cursor: pointer;"><?php echo get_string('impl_levels_help', 'local_chatbot'); ?></summary>
                            <ul class="mt-1 mb-0">
                                <?php foreach (implementacion::level_help() as $clave => $descripcion): ?>
                                    <li><strong><?php echo s(implementacion::NIVELES[$clave]); ?>:</strong> <?php echo s($descripcion); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                        <p class="small text-muted" id="impl-sin-criterios"<?php echo implementacion::criteria_count($data) ? ' hidden' : ''; ?>><?php
                            echo get_string('impl_table41_none', 'local_chatbot'); ?></p>
                        <?php for ($i = 1; $i <= implementacion::CRITERIOS_MAX; $i++):
                            $texto = trim((string) ($data['criterio' . $i] ?? '')); ?>
                            <div class="border rounded p-3 mb-3" data-fila="<?php echo $i; ?>"<?php echo $texto === '' ? ' hidden' : ''; ?>>
                                <div class="fw-bold mb-2"><?php echo get_string('impl_table41_criterion', 'local_chatbot', $i); ?>
                                    <span class="fw-normal text-muted" data-criterio-texto="<?php echo $i; ?>"><?php echo s($texto); ?></span></div>
                                <label class="form-label small fw-medium mb-1" for="f_ev<?php echo $i; ?>"><?php
                                    echo get_string('impl_table41_evidence', 'local_chatbot'); ?></label>
                                <textarea class="form-control mb-2" id="f_ev<?php echo $i; ?>" name="ev<?php echo $i; ?>" rows="2"
                                          maxlength="<?php echo implementacion::LARGO_PARRAFO; ?>"><?php echo s($data['ev' . $i] ?? ''); ?></textarea>
                                <div class="d-flex flex-wrap gap-3 mb-2 align-items-end">
                                    <?php foreach (implementacion::NIVELES as $clave => $nombrenivel): ?>
                                        <div>
                                            <label class="form-label small fw-medium mb-1" for="f_<?php echo $clave . $i; ?>"
                                                   title="<?php echo s(implementacion::level_help()[$clave]); ?>"><?php echo s($nombrenivel); ?></label>
                                            <input type="number" min="0" step="1" class="form-control form-control-sm" style="width: 105px;"
                                                   id="f_<?php echo $clave . $i; ?>" name="<?php echo $clave . $i; ?>" data-nivel="<?php echo $i; ?>"
                                                   data-clave="<?php echo $clave; ?>" value="<?php echo s($data[$clave . $i] ?? ''); ?>">
                                        </div>
                                    <?php endforeach; ?>
                                    <div>
                                        <label class="form-label small fw-medium mb-1" for="f_lg<?php echo $i; ?>"><?php
                                            echo get_string('impl_table41_logro', 'local_chatbot'); ?></label>
                                        <input type="text" inputmode="decimal" class="form-control form-control-sm" style="width: 105px;"
                                               id="f_lg<?php echo $i; ?>" name="lg<?php echo $i; ?>" data-logro="<?php echo $i; ?>"
                                               value="<?php echo s(str_replace('.', ',', $data['lg' . $i] ?? '')); ?>">
                                    </div>
                                    <div class="small text-muted" data-suma="<?php echo $i; ?>" role="status"></div>
                                </div>
                                <label class="form-label small fw-medium mb-1" for="f_cm<?php echo $i; ?>"><?php
                                    echo get_string('impl_table41_comment', 'local_chatbot'); ?></label>
                                <textarea class="form-control" id="f_cm<?php echo $i; ?>" name="cm<?php echo $i; ?>" rows="2"
                                          maxlength="<?php echo implementacion::LARGO_PARRAFO; ?>"><?php echo s($data['cm' . $i] ?? ''); ?></textarea>
                            </div>
                        <?php endfor; ?>

                        <h6 class="fw-bold text-dark mt-4 mb-2">4.2 <?php echo get_string('impl_table42_title', 'local_chatbot'); ?>
                            <span class="fw-normal text-muted">(<?php echo get_string('exante_optional', 'local_chatbot'); ?>)</span></h6>
                        <p class="small text-muted mb-2"><?php echo get_string('impl_table42_help', 'local_chatbot'); ?></p>
                        <?php for ($j = 1; $j <= implementacion::OBSERVACIONES_FILAS; $j++):
                            $conteo = false;
                            foreach (['quien', 'situacion', 'evidencia', 'comentario'] as $c) {
                                $conteo = $conteo || trim((string) ($data['ob' . $j . '_' . $c] ?? '')) !== '';
                            } ?>
                            <details class="border rounded p-2 mb-2"<?php echo $conteo ? ' open' : ''; ?>>
                                <summary class="small fw-medium" style="cursor: pointer;"><?php
                                    echo get_string('impl_table42_row', 'local_chatbot', $j); ?></summary>
                                <div class="row g-2 mt-1">
                                    <?php foreach (['quien' => 'impl_table42_who', 'situacion' => 'impl_table42_situation',
                                            'evidencia' => 'impl_table42_evidence', 'comentario' => 'impl_table42_comment'] as $c => $clave): ?>
                                        <div class="col-md-6">
                                            <label class="form-label small mb-1" for="f_ob<?php echo $j . '_' . $c; ?>"><?php
                                                echo get_string($clave, 'local_chatbot'); ?></label>
                                            <textarea class="form-control form-control-sm" id="f_ob<?php echo $j . '_' . $c; ?>"
                                                      name="ob<?php echo $j . '_' . $c; ?>" rows="2"
                                                      maxlength="<?php echo implementacion::LARGO_PARRAFO; ?>"><?php
                                                echo s($data['ob' . $j . '_' . $c] ?? ''); ?></textarea>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endfor; ?>
                        <p class="small text-muted"><?php echo get_string('impl_table42_privacy', 'local_chatbot'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <p class="small text-muted"><?php echo get_string('impl_privacy', 'local_chatbot'); ?></p>
        <?php $avisosform = \local_chatbot\ux::on('avisosimpl') ? implementacion::form_warnings($data) : []; ?>
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
    <?php if ($val && in_array($val->estado, [implementacion::PENDIENTE, implementacion::PROCESANDO], true)): ?>
        <div class="card shadow-sm border-0 mb-4" id="impl-validando" role="status"
             data-id="<?php echo (int) $val->id; ?>" data-estado="<?php echo s($val->estado); ?>"
             data-url="<?php echo s($pageurl->out(false)); ?>" data-sesskey="<?php echo sesskey(); ?>"
             data-volver="<?php echo s((new moodle_url($pageurl, ['v' => $val->id], 'informe'))->out(false)); ?>">
            <div class="card-body">
                <h5 class="mb-2"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?php
                    echo get_string('exante_validating', 'local_chatbot'); ?></h5>
                <p class="small text-muted mb-0"><?php echo get_string('exante_validating_long', 'local_chatbot'); ?></p>
            </div>
        </div>
    <?php elseif ($val && $val->estado === implementacion::ERROR): ?>
        <div class="alert alert-warning">
            <?php echo get_string('impl_error_' . ($val->error ?: 'servicio'), 'local_chatbot'); ?>
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
                    <div class="alert alert-warning"><?php echo get_string('impl_report_stale', 'local_chatbot'); ?></div>
                <?php endif; ?>

                <h5 class="mb-2">1. <?php echo get_string('impl_report_sections', 'local_chatbot'); ?></h5>
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
                    <?php foreach (implementacion::checklist() as $n => $seccion): ?>
                        <tr class="table-light"><th colspan="3" class="small text-uppercase"><?php
                            echo $n . '. ' . s($seccion['titulo']); ?></th></tr>
                        <?php foreach ($seccion['items'] as $id => $texto):
                            $item = $informe['items'][$id] ?? null; ?>
                            <tr>
                                <td class="small"><?php echo s($texto); ?>
                                    <?php if (in_array($id, implementacion::CRITICOS, true)): ?>
                                        <span class="badge text-bg-light border text-danger ms-1"><?php
                                            echo get_string('autocheck_critical', 'local_chatbot'); ?></span>
                                    <?php endif; ?>
                                    <?php if (($item['origen'] ?? '') === 'calculo'): ?>
                                        <span class="badge text-bg-light border text-secondary ms-1" title="<?php
                                            echo s(get_string('impl_report_calculated_help', 'local_chatbot')); ?>"><?php
                                            echo get_string('impl_report_calculated', 'local_chatbot'); ?></span>
                                    <?php endif; ?></td>
                                <?php if ($item === null): ?>
                                    <td><span class="badge text-bg-light border"><?php
                                        echo get_string('exante_status_none', 'local_chatbot'); ?></span></td>
                                    <td class="small text-muted"><?php echo get_string('exante_status_none_help', 'local_chatbot'); ?></td>
                            </tr>
                                    <?php continue; ?>
                                <?php endif; ?>
                                <td><span class="badge text-bg-<?php echo $estadoclase[$item['estado']]; ?>"><?php
                                    echo get_string('exante_status_' . $item['estado'], 'local_chatbot'); ?></span></td>
                                <td class="small"><?php echo s($item['observacion']); ?>
                                    <?php if (!empty($item['forma'])): ?>
                                        <div class="mt-1 text-muted"><em><?php echo get_string('impl_report_form', 'local_chatbot'); ?></em></div>
                                    <?php endif; ?>
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

                <h5 class="mb-2">2. <?php echo get_string('impl_report_decision', 'local_chatbot'); ?></h5>
                <div class="alert alert-<?php echo $condclase[$val->condicion]; ?> mb-4">
                    <strong><?php echo get_string('exante_cond_' . $val->condicion, 'local_chatbot'); ?></strong>.
                    <?php echo get_string('impl_cond_' . $val->condicion . '_desc', 'local_chatbot'); ?>
                    <?php if (!empty($informe['regla'])): // Aplicacion explicita de la regla de decision. ?>
                        <div class="small mt-2"><strong><?php echo get_string('exante_rule_label', 'local_chatbot'); ?></strong>
                            <?php echo get_string('exante_rule_' . $informe['regla'], 'local_chatbot'); ?></div>
                    <?php endif; ?>
                </div>
                <?php $detienen = !empty($informe['regla']) && $val->condicion === implementacion::NO_APROBADO
                    ? implementacion::critical_failures($informe['items']) : []; ?>
                <?php if ($detienen): ?>
                    <div class="alert alert-danger mb-4">
                        <h6 class="mb-2"><?php echo get_string('exante_report_mustfix', 'local_chatbot'); ?></h6>
                        <ul class="small mb-0">
                            <?php foreach ($detienen as $id): $item = $informe['items'][$id] ?? []; ?>
                                <li class="mb-1"><strong><?php echo s(implementacion::checklist_texts()[$id]); ?></strong>
                                    <?php if (!empty($item['observacion'])): ?><br><?php echo s($item['observacion']); ?><?php endif; ?>
                                    <?php if (!empty($item['propuesta'])): ?><br><strong><?php
                                        echo get_string('exante_report_howto', 'local_chatbot'); ?></strong> <?php
                                        echo s($item['propuesta']); ?><?php endif; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php $t = (array) ($informe['tiempo'] ?? []); ?>
                <h5 class="mb-2">3. <?php echo get_string('impl_report_time', 'local_chatbot'); ?></h5>
                <div class="row g-2 mb-2 text-center">
                    <div class="col-md-4"><div class="border rounded p-2"><div class="small text-muted"><?php
                        echo get_string('impl_report_time_total', 'local_chatbot'); ?></div><div class="fs-5 fw-bold"><?php
                        echo isset($t['total']) ? (int) $t['total'] : '—'; ?> min</div></div></div>
                    <div class="col-md-4"><div class="border rounded p-2"><div class="small text-muted"><?php
                        echo get_string('impl_report_time_used', 'local_chatbot'); ?></div><div class="fs-5 fw-bold"><?php
                        echo isset($t['utilizado']) ? (int) $t['utilizado'] : '—'; ?> min</div></div></div>
                    <div class="col-md-4"><div class="border rounded p-2 <?php echo (($t['estado'] ?? 'ok') === 'ok') ? '' : 'border-warning'; ?>">
                        <div class="small text-muted"><?php echo get_string('impl_report_time_left', 'local_chatbot'); ?></div>
                        <div class="fs-5 fw-bold"><?php echo isset($t['restante']) ? (int) $t['restante'] : '—'; ?> min</div></div></div>
                </div>
                <?php if (in_array($t['estado'] ?? 'ok', ['cero', 'negativo'], true)): ?>
                    <p class="small text-warning-emphasis mb-3"><?php echo get_string('impl_report_time_none', 'local_chatbot'); ?></p>
                <?php else: ?>
                    <p class="small text-muted mb-3"><?php echo get_string('impl_report_time_help', 'local_chatbot'); ?></p>
                <?php endif; ?>

                <h5 class="mb-2">4. <?php echo get_string('exante_report_weak', 'local_chatbot'); ?></h5>
                <?php if (!empty($informe['debilidades'])): ?>
                    <ul class="small">
                        <?php foreach ($informe['debilidades'] as $texto): ?><li><?php echo s($texto); ?></li><?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="small"><?php echo get_string('impl_report_none', 'local_chatbot'); ?></p>
                <?php endif; ?>

                <h5 class="mb-2 mt-3">5. <?php echo get_string('impl_report_suggest', 'local_chatbot'); ?></h5>
                <?php if (!empty($informe['sugerencias'])): ?>
                    <ul class="small">
                        <?php foreach ($informe['sugerencias'] as $texto): ?><li><?php echo s($texto); ?></li><?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="small"><?php echo get_string('impl_report_none', 'local_chatbot'); ?></p>
                <?php endif; ?>

                <?php $pendientes = implementacion::unresolved_items($informe['items'] ?? []); ?>
                <h5 class="mb-2 mt-3">6. <?php echo get_string('exante_report_unresolved', 'local_chatbot'); ?></h5>
                <?php if ($pendientes): ?>
                    <p class="small text-muted mb-1"><?php echo get_string('impl_report_unresolved_help', 'local_chatbot'); ?></p>
                    <ul class="small">
                        <?php foreach ($pendientes as $p): ?>
                            <li><?php echo get_string('exante_report_section', 'local_chatbot', $p['seccion']); ?>:
                                <?php echo s($p['texto']); ?>
                                <span class="badge text-bg-<?php echo $estadoclase[$p['estado']]; ?>"><?php
                                    echo get_string('exante_status_' . $p['estado'], 'local_chatbot'); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="small"><?php echo get_string('exante_report_unresolved_none', 'local_chatbot'); ?></p>
                <?php endif; ?>

                <?php if (!empty($informe['verificaciones'])): ?>
                    <details class="small mb-3">
                        <summary class="text-primary" style="cursor: pointer;"><?php echo get_string('impl_report_checks', 'local_chatbot'); ?></summary>
                        <ul class="mt-1 mb-0"><?php foreach ($informe['verificaciones'] as $linea): ?><li><?php echo s($linea); ?></li><?php endforeach; ?></ul>
                    </details>
                <?php endif; ?>

                <p class="small text-muted mb-1"><?php echo empty($informe['adjuntos'])
                    ? get_string('impl_report_nodocs', 'local_chatbot')
                    : get_string('exante_report_docs', 'local_chatbot', s(implode(', ', array_map(
                        static function($etiqueta, $nombre) {
                            return $etiqueta . ' (' . $nombre . ')';
                        }, array_keys($informe['adjuntos']), $informe['adjuntos'])))); ?></p>
                <?php if (!empty($informe['fuentes_web'])): ?>
                    <p class="small text-muted mb-1"><?php echo get_string('exante_report_websources', 'local_chatbot'); ?>
                        <?php echo implode(', ', array_map(static function($f) {
                            return html_writer::link($f['uri'], s($f['title'] ?: $f['uri']), ['target' => '_blank', 'rel' => 'noopener']);
                        }, $informe['fuentes_web'])); ?></p>
                <?php endif; ?>
                <p class="small text-muted mb-0"><?php echo get_string('impl_report_note', 'local_chatbot'); ?></p>
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
                <?php echo get_string('impl_next', 'local_chatbot'); ?>
                <span class="chatbot-backbtn-arrow" aria-hidden="true">&rarr;</span>
            </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?php echo $urlfase1->out(false); ?>"><?php
            echo get_string('exante_back', 'local_chatbot'); ?></a>
    </div>
</div>

<script>
(function() {
    var form = document.getElementById('impl-form');
    if (!form) {
        return;
    }
    var num = function(campo) {
        var v = campo ? parseInt(campo.value, 10) : NaN;
        return isNaN(v) ? null : v;
    };
    var presentes = form.elements['presentes'];

    // «Agregar criterio de evaluación»: muestra el siguiente criterio oculto, de a uno.
    var agregar = document.getElementById('impl-agregar-criterio');
    if (agregar) {
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
    }

    // La tabla 4.1 tiene una fila por cada criterio de la sección 2: aparece al escribir el criterio y repite su texto.
    var sincriterios = document.getElementById('impl-sin-criterios');
    var sincronizar = function() {
        var hay = 0;
        form.querySelectorAll('[data-fila]').forEach(function(fila) {
            var i = fila.getAttribute('data-fila');
            var campo = form.elements['criterio' + i];
            var texto = campo ? campo.value.trim() : '';
            fila.hidden = texto === '';
            hay += texto === '' ? 0 : 1;
            var eco = fila.querySelector('[data-criterio-texto]');
            if (eco) {
                eco.textContent = texto;
            }
        });
        if (sincriterios) {
            sincriterios.hidden = hay > 0;
        }
    };
    form.querySelectorAll('[data-criterio] textarea').forEach(function(campo) {
        campo.addEventListener('input', sincronizar);
    });

    // La suma por nivel debe ser igual a los presentes; el logro se calcula con la fórmula de la ficha.
    var revisar = function(i, escribirlogro) {
        var n = {};
        var suma = 0;
        var vacio = false;
        ['ins', 'bas', 'ade', 'ava'].forEach(function(c) {
            var v = num(form.elements[c + i]);
            n[c] = v === null ? 0 : v;
            vacio = vacio || v === null;
            suma += n[c];
        });
        var p = num(presentes);
        var aviso = form.querySelector('[data-suma="' + i + '"]');
        var logro = form.elements['lg' + i];
        if (p !== null && p > 0 && !vacio) {
            var calculado = Math.round(1000 * (n.ade + n.ava) / p) / 10;
            if (escribirlogro && logro && (logro.value === '' || logro.dataset.auto === '1')) {
                logro.value = String(calculado).replace('.', ',');
                logro.dataset.auto = '1';
            }
            if (aviso) {
                aviso.textContent = suma === p ? <?php echo json_encode(get_string('impl_js_sum_ok', 'local_chatbot')); ?>
                    : <?php echo json_encode(get_string('impl_js_sum_bad', 'local_chatbot')); ?>.replace('{suma}', suma).replace('{presentes}', p);
                aviso.className = 'small ' + (suma === p ? 'text-success' : 'text-danger');
            }
        } else if (aviso) {
            aviso.textContent = '';
        }
    };
    form.querySelectorAll('[data-nivel]').forEach(function(campo) {
        campo.addEventListener('input', function() {
            revisar(campo.getAttribute('data-nivel'), true);
        });
    });
    form.querySelectorAll('[data-logro]').forEach(function(campo) {
        campo.addEventListener('input', function() {
            campo.dataset.auto = '0';
        });
    });
    if (presentes) {
        presentes.addEventListener('input', function() {
            form.querySelectorAll('[data-fila]').forEach(function(fila) {
                revisar(fila.getAttribute('data-fila'), true);
            });
        });
    }
    form.querySelectorAll('[data-fila]').forEach(function(fila) {
        revisar(fila.getAttribute('data-fila'), false);
    });
})();
(function() {
    var caja = document.getElementById('impl-validando');
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
