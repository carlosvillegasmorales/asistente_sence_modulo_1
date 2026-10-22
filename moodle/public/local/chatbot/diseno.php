<?php
/**
 * Modulo 2, Fase 2: hoja de trabajo de cada etapa del diseno de la experiencia de aprendizaje.
 *
 * Pedido de Interfases del 21-10-2026: seis etapas (Prompt Maestro, 1 a 6), cada una en su pagina. El asistente
 * propone el producto de la etapa; la persona lo edita, pide ajustes y lo acepta; puede descargarlo en Word, y al
 * final estan «Regresar al curso» y «Avanzar a la etapa siguiente». La logica vive en classes/diseno.php.
 *
 * Como la validacion de la ficha, la generacion corre en una segunda llamada que la pagina dispara y consulta.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/editorlib.php');

use local_chatbot\diseno;
use local_chatbot\exante;

$courseid = required_param('courseid', PARAM_INT);
if ((int) $courseid === (int) SITEID) {
    throw new moodle_exception('invalidcourseid');
}
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/chatbot:use', $context);

$etapas = diseno::todas();
$etapa = optional_param('etapa', 0, PARAM_INT);

$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('diseno_title', 'local_chatbot'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js(new moodle_url('/local/chatbot/js/espera.js'), true);

if (!\local_chatbot\ux::on('disenofase2')) {
    $PAGE->set_url(new moodle_url('/local/chatbot/diseno.php', ['courseid' => $courseid]));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('diseno_disabled', 'local_chatbot'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$action = optional_param('action', '', PARAM_ALPHA);

// --- Llamadas de la propia pagina mientras se genera ---
if ($action === 'ejecutar' || $action === 'estado') {
    $id = required_param('id', PARAM_INT);
    if ($action === 'ejecutar') {
        require_sesskey();
        \core\session\manager::write_close();
        ignore_user_abort(true);
        core_php_time_limit::raise(360);
        $estado = diseno::run($id, $USER->id);
    } else {
        diseno::get_all($USER->id, $courseid);
        $estado = $DB->get_field('local_chatbot_diseno', 'generacion', ['id' => $id, 'userid' => $USER->id]);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['estado' => $estado ?: exante::ERROR]);
    exit;
}

$fase1 = exante::fase1_ok($USER->id, $courseid);
$filas = diseno::get_all($USER->id, $courseid);
$aceptadas = diseno::accepted($filas);
if (!isset($etapas[$etapa])) {
    $etapa = diseno::current_stage($aceptadas);
}
$pageurl = new moodle_url('/local/chatbot/diseno.php', ['courseid' => $courseid, 'etapa' => $etapa]);
$PAGE->set_url($pageurl);
$fase3 = $etapa >= diseno::CONSOLIDACION;
$grupo = $fase3 ? diseno::etapas_fase3() : diseno::etapas();
$titulofase = get_string($fase3 ? 'diseno_title3' : 'diseno_title', 'local_chatbot');
$PAGE->navbar->add($titulofase);
$abierta = diseno::can_open($etapa, (bool) $fase1, $aceptadas);
$fila = $filas[$etapa] ?? null;
$evaluacion = diseno::current_evaluation($filas);
$aprobado = $evaluacion && !empty($evaluacion['banda']['aprobado']);

// --- Descarga en Word ---
if ($action === 'word' && $abierta && !$fase3 && $fila && $fila->contenido) {
    $ruta = make_request_directory() . '/producto.docx';
    $titulo = 'Etapa ' . $etapa . ': ' . $etapas[$etapa]['titulo'];
    $subtitulo = format_string($course->fullname) . ' · ' . userdate(time(), get_string('strftimedate', 'langconfig'));
    if (diseno::html_to_docx($ruta, $titulo, $subtitulo, (string) $fila->contenido)) {
        send_file($ruta, $etapas[$etapa]['archivo'] . '.docx', 0, 0, false, true,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
    throw new moodle_exception('diseno_word_error', 'local_chatbot');
}

// --- Documento final: el diseno consolidado con su evaluacion, solo si quedo aprobado ---
if ($action === 'final' && $fase1 && $aprobado) {
    $ruta = make_request_directory() . '/final.docx';
    $nombre = diseno::experience_name(html_to_text((string) ($filas[2]->contenido ?? ''), 0, false));
    $titulo = $nombre !== '' ? $nombre : get_string('diseno_final_title', 'local_chatbot');
    $subtitulo = get_string('diseno_final_subtitle', 'local_chatbot', (object) ['curso' => format_string($course->fullname),
        'total' => $evaluacion['total'], 'banda' => $evaluacion['banda']['texto'],
        'fecha' => userdate($evaluacion['fecha'], get_string('strftimedate', 'langconfig'))]);
    $html = (string) $filas[diseno::CONSOLIDACION]->contenido . '<h1>' . s(get_string('diseno_final_annex', 'local_chatbot'))
        . '</h1>' . diseno::evaluation_html($evaluacion);
    if (diseno::html_to_docx($ruta, $titulo, $subtitulo, $html)) {
        send_file($ruta, diseno::etapas_fase3()[diseno::EVALUACION]['archivo'] . '.docx', 0, 0, false, true,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
    throw new moodle_exception('diseno_word_error', 'local_chatbot');
}

// --- Fase 3: confirmar la consolidacion y pedir la evaluacion ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $abierta && $fase3 && in_array($action, ['confirmar', 'evaluar'], true)) {
    require_sesskey();
    $destino = new moodle_url($pageurl, [], 'producto');
    if ($action === 'confirmar' && $etapa === diseno::CONSOLIDACION) {
        $contenidos = [];
        foreach (array_keys(diseno::etapas()) as $n) {
            $contenidos[$n] = (string) $filas[$n]->contenido;
        }
        diseno::confirm_consolidation($USER->id, $courseid, diseno::consolidate($contenidos),
            diseno::stages_fingerprint($filas));
        redirect(new moodle_url($pageurl, ['etapa' => diseno::EVALUACION]), get_string('diseno_confirmed', 'local_chatbot'),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'evaluar' && $etapa === diseno::EVALUACION && !$evaluacion) {
        if (!\local_chatbot\ai\provider_factory::is_configured()) {
            redirect($destino, get_string('exante_error_sinclave', 'local_chatbot'), null,
                \core\output\notification::NOTIFY_ERROR);
        }
        diseno::request($USER->id, $courseid, diseno::EVALUACION, (int) $fase1->id);
    }
    redirect($destino);
}

// --- Acciones del formulario ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $abierta && !$fase3
        && in_array($action, ['generar', 'ajustar', 'guardar', 'aceptar'], true)) {
    require_sesskey();
    $destino = new moodle_url($pageurl, [], 'producto');
    if ($action === 'guardar' || $action === 'aceptar') {
        diseno::save($USER->id, $courseid, $etapa, optional_param('contenido', '', PARAM_RAW), $action === 'aceptar');
        redirect($destino, get_string($action === 'aceptar' ? 'diseno_accepted' : 'diseno_saved', 'local_chatbot'),
            null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if (!\local_chatbot\ai\provider_factory::is_configured()) {
        redirect($destino, get_string('exante_error_sinclave', 'local_chatbot'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    $ajustes = $action === 'ajustar' ? trim(optional_param('ajustes', '', PARAM_TEXT)) : '';
    if ($action === 'ajustar') {
        if ($ajustes === '') {
            redirect($destino, get_string('diseno_adjust_empty', 'local_chatbot'), null,
                \core\output\notification::NOTIFY_WARNING);
        }
        // Lo editado a mano no se pierde: el ajuste parte de la version que la persona tiene en pantalla.
        $editado = optional_param('contenido', null, PARAM_RAW);
        if ($editado !== null && $fila) {
            diseno::save($USER->id, $courseid, $etapa, $editado, false);
        }
    }
    diseno::request($USER->id, $courseid, $etapa, (int) $fase1->id, $ajustes);
    redirect($destino);
}

// Secciones del curso de las fases (por su nombre).
$nombres = [];
foreach (get_fast_modinfo($course)->get_section_info_all() as $seccion) {
    $nombres[$seccion->section] = get_section_name($course, $seccion);
}
$urlcurso = new moodle_url('/course/view.php', ['id' => $courseid]);
$urlfase = static function(int $fase) use ($course, $nombres, $urlcurso) {
    $num = exante::phase_section($nombres, $fase);
    return ($num !== null ? course_get_url($course, $num) : null) ?: $urlcurso;
};
$generando = $fila && in_array($fila->generacion, [exante::PENDIENTE, exante::PROCESANDO], true);
$hayproducto = $fila && trim(strip_tags((string) $fila->contenido)) !== '';
$aceptada = $fase3 ? in_array($etapa, $aceptadas, true) : ($fila && $fila->estado === diseno::ACEPTADO && $hayproducto);

if ($hayproducto && !$generando && !$fase3) {
    editors_get_preferred_editor(FORMAT_HTML)->use_editor('diseno-contenido',
        ['context' => $context, 'autosave' => false, 'enable_filemanagement' => false]);
}

echo $OUTPUT->header();
?>

<div class="container-fluid" style="max-width: 980px;">

    <h3 class="mb-1"><?php echo $titulofase; ?></h3>
    <p class="text-muted mb-3"><?php echo get_string($fase3 ? 'diseno_subtitle3' : 'diseno_subtitle', 'local_chatbot'); ?></p>

    <?php // Las tres fases del modulo, con el mismo estilo que la ruta del Modulo 1. ?>
    <?php echo \local_chatbot\ruta_m2::render($USER->id, $courseid, $fase3 ? 3 : 2, $etapa); ?>

    <?php // Las seis etapas, con su estado: la persona sabe donde esta y que falta. ?>
    <ol class="chatbot-diseno-etapas list-unstyled d-flex flex-wrap gap-2 mb-4">
        <?php foreach ($grupo as $n => $e):
            $ok = in_array($n, $aceptadas, true);
            $puede = diseno::can_open($n, (bool) $fase1, $aceptadas);
            $clase = $n === $etapa ? 'actual' : ($ok ? 'hecha' : ($puede ? 'abierta' : 'cerrada'));
            $texto = ($ok ? '✔ ' : $n . '. ') . s($e['titulo']); ?>
            <li class="chatbot-diseno-etapa chatbot-diseno-<?php echo $clase; ?>">
                <?php if ($puede && $n !== $etapa): ?>
                    <a href="<?php echo (new moodle_url($pageurl, ['etapa' => $n]))->out(false); ?>"><?php echo $texto; ?></a>
                <?php else: ?>
                    <span<?php echo $n === $etapa ? ' aria-current="step"' : ''; ?>><?php echo $texto; ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php if (!$fase1): ?>
        <?php // Regla critica del Prompt Maestro: este asistente no trabaja sin ficha validada. ?>
        <div class="alert alert-warning">
            <?php echo get_string('diseno_needs_fase1', 'local_chatbot'); ?>
            <div class="mt-2"><a class="chatbot-gatebtn" href="<?php echo (new moodle_url('/local/chatbot/ficha_exante.php',
                ['courseid' => $courseid]))->out(false); ?>"><?php echo get_string('diseno_go_fase1', 'local_chatbot'); ?></a></div>
        </div>
    <?php elseif (!$abierta): ?>
        <div class="alert alert-info">
            <?php echo get_string('diseno_locked', 'local_chatbot', $etapa - 1); ?>
            <div class="mt-2"><a class="chatbot-gatebtn" href="<?php echo (new moodle_url($pageurl,
                ['etapa' => diseno::current_stage($aceptadas)]))->out(false); ?>"><?php
                echo get_string('diseno_go_current', 'local_chatbot'); ?></a></div>
        </div>
    <?php else: ?>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <h4 class="mb-2"><?php echo get_string('diseno_stage', 'local_chatbot', (object) ['n' => $etapa,
                    'titulo' => $etapas[$etapa]['titulo']]); ?></h4>
                <p class="small text-muted mb-1"><?php echo get_string('diseno_includes', 'local_chatbot'); ?></p>
                <ul class="small mb-2">
                    <?php foreach ($etapas[$etapa]['incluye'] as $item): ?><li><?php echo s($item); ?></li><?php endforeach; ?>
                </ul>
                <?php if ($etapas[$etapa]['nota'] !== ''): ?>
                    <p class="small text-muted mb-0"><?php echo s($etapas[$etapa]['nota']); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($fase3): ?>
            <?php require(__DIR__ . '/diseno_fase3.php'); ?>
        <?php else: ?>
        <div id="producto">
        <?php if ($generando): ?>
            <div class="card shadow-sm border-0 mb-4" id="diseno-generando" role="status"
                 data-id="<?php echo (int) $fila->id; ?>" data-estado="<?php echo s($fila->generacion); ?>"
                 data-url="<?php echo s((new moodle_url('/local/chatbot/diseno.php', ['courseid' => $courseid]))->out(false)); ?>"
                 data-sesskey="<?php echo sesskey(); ?>"
                 data-volver="<?php echo s((new moodle_url($pageurl, ['g' => time()], 'producto'))->out(false)); ?>">
                <div class="card-body">
                    <h5 class="mb-2"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?php
                        echo get_string('diseno_generating', 'local_chatbot'); ?></h5>
                    <p class="small text-muted mb-0"><?php echo get_string('diseno_generating_long', 'local_chatbot'); ?></p>
                </div>
            </div>
        <?php else: ?>
            <?php if ($fila && $fila->generacion === exante::ERROR): ?>
                <div class="alert alert-warning"><?php
                    echo get_string('exante_error_' . ($fila->error ?: 'servicio'), 'local_chatbot') . ' '
                        . get_string('diseno_error_retry', 'local_chatbot'); ?></div>
            <?php endif; ?>

            <?php if (!$hayproducto): ?>
                <form method="post" action="<?php echo $pageurl->out(false); ?>" class="mb-4">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <p><?php echo get_string('diseno_generate_help', 'local_chatbot'); ?></p>
                    <button type="submit" name="action" value="generar" class="btn btn-primary px-4 py-2"
                            data-espera="<?php echo s(get_string('diseno_generating', 'local_chatbot')); ?>">
                        <i class="fa fa-magic me-1"></i> <?php echo get_string('diseno_generate', 'local_chatbot'); ?>
                    </button>
                </form>
            <?php else: ?>
                <form method="post" action="<?php echo $pageurl->out(false); ?>" id="diseno-form">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <h5 class="mb-0"><?php echo get_string('diseno_product', 'local_chatbot'); ?></h5>
                        <?php if ($aceptada): ?>
                            <span class="badge text-bg-success"><?php echo get_string('diseno_status_accepted', 'local_chatbot',
                                userdate($fila->timeaccepted, get_string('strftimedatetimeshort', 'langconfig'))); ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-warning"><?php echo get_string('diseno_status_draft', 'local_chatbot'); ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="small text-muted"><?php echo get_string('diseno_edit_help', 'local_chatbot'); ?></p>
                    <textarea id="diseno-contenido" name="contenido" rows="24" class="form-control mb-3"><?php
                        echo s((string) $fila->contenido); ?></textarea>
                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <?php if (!$aceptada): ?>
                            <button type="submit" name="action" value="aceptar" class="btn btn-success px-4"
                                    data-espera="<?php echo s(get_string('exante_saving', 'local_chatbot')); ?>">
                                <i class="fa fa-check me-1"></i> <?php echo get_string('diseno_accept', 'local_chatbot'); ?>
                            </button>
                        <?php endif; ?>
                        <button type="submit" name="action" value="guardar" class="btn btn-outline-primary px-4"
                                data-espera="<?php echo s(get_string('exante_saving', 'local_chatbot')); ?>">
                            <i class="fa fa-save me-1"></i> <?php echo get_string('diseno_save', 'local_chatbot'); ?>
                        </button>
                    </div>

                    <?php // Modo de interaccion del Prompt Maestro: ajustes iterativos antes de avanzar. ?>
                    <div class="card border-0 bg-light mb-4">
                        <div class="card-body">
                            <label class="form-label fw-bold" for="diseno-ajustes"><?php
                                echo get_string('diseno_adjust_title', 'local_chatbot'); ?></label>
                            <p class="small text-muted mb-2"><?php echo get_string('diseno_adjust_help', 'local_chatbot'); ?></p>
                            <textarea id="diseno-ajustes" name="ajustes" rows="3" class="form-control mb-2" maxlength="4000"></textarea>
                            <button type="submit" name="action" value="ajustar" class="btn btn-outline-secondary"
                                    data-espera="<?php echo s(get_string('diseno_generating', 'local_chatbot')); ?>">
                                <i class="fa fa-commenting-o me-1"></i> <?php echo get_string('diseno_adjust', 'local_chatbot'); ?>
                            </button>
                        </div>
                    </div>
                </form>

                <p class="mb-4">
                    <a class="btn btn-outline-dark" href="<?php echo (new moodle_url($pageurl, ['action' => 'word']))->out(false); ?>">
                        <i class="fa fa-file-word-o me-1"></i> <?php echo get_string('diseno_word', 'local_chatbot'); ?>
                    </a>
                    <span class="small text-muted ms-2"><?php echo get_string('diseno_word_help', 'local_chatbot'); ?></span>
                </p>
            <?php endif; ?>
        <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php // Al termino de cada pagina: regresar al curso y avanzar a la etapa siguiente. ?>
    <div class="d-flex flex-wrap gap-2 mb-4 border-top pt-3">
        <a class="btn btn-outline-secondary" href="<?php echo $urlfase($fase3 ? 3 : 2)->out(false); ?>"><?php
            echo get_string('diseno_back', 'local_chatbot'); ?></a>
        <?php if ($etapa !== max(array_keys(diseno::etapas())) && isset($grupo[$etapa + 1])):
            $siguiente = new moodle_url($pageurl, ['etapa' => $etapa + 1]); ?>
            <?php if ($aceptada): ?>
                <a class="chatbot-backbtn chatbot-nextphase" href="<?php echo $siguiente->out(false); ?>"><?php
                    echo get_string('diseno_next', 'local_chatbot', $etapas[$etapa + 1]['titulo']); ?>
                    <span class="chatbot-backbtn-arrow" aria-hidden="true">&rarr;</span></a>
            <?php else: ?>
                <span class="btn btn-secondary disabled" aria-disabled="true" title="<?php
                    echo s(get_string('diseno_next_locked', 'local_chatbot')); ?>"><?php
                    echo get_string('diseno_next', 'local_chatbot', $etapas[$etapa + 1]['titulo']); ?></span>
            <?php endif; ?>
        <?php elseif (!$fase3 && $aceptada): ?>
            <a class="chatbot-backbtn chatbot-nextphase" href="<?php echo $urlfase(3)->out(false); ?>"><?php
                echo get_string('diseno_next_fase3', 'local_chatbot'); ?>
                <span class="chatbot-backbtn-arrow" aria-hidden="true">&rarr;</span></a>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    var caja = document.getElementById('diseno-generando');
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
        // Si el servidor web corta esta llamada, la generacion sigue y la consulta de estado la recoge.
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
