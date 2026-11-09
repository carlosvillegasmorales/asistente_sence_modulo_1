<?php
/**
 * Modulo 3, Fase 4 (evaluacion ex post), etapas 9 y 10: la ficha de evaluacion ex post y su validacion con el checklist.
 *
 * «Prompt Complementario Fase 4 - Evaluacion Ex Post» (Interfases, 07-11-2026): la Fase 4 solo se activa con las Fases 1 a 3
 * completas y consolidadas y con la experiencia de continuidad ya implementada. Al iniciarla, el asistente prepara la precarga
 * (etapa 9: identificacion, criterios con su logro inicial, pendientes, estrategias, instrumentos y buenas practicas), muestra la
 * bienvenida y espera el envio de la ficha; la persona la completa en linea y pulsa «Guardar y validar» (etapa 10). Con
 * «Aprobado» o «Aprobado con observaciones» se habilitan las etapas 11 a 15 (expost.php). La logica vive en classes/expost.php.
 *
 * Como en la Fase 1, la validacion y la preparacion de etiquetas corren en una segunda llamada que la pagina dispara sola y luego
 * consulta: el modelo puede tardar mas que el tiempo que el servidor web espera una respuesta.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_chatbot\exante;
use local_chatbot\expost;
use local_chatbot\implementacion;

$courseid = required_param('courseid', PARAM_INT);
if ((int) $courseid === (int) SITEID) {
    throw new moodle_exception('invalidcourseid');
}
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/chatbot:use', $context);

$pageurl = new moodle_url('/local/chatbot/ficha_expost.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('exp_title', 'local_chatbot'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('exp_title', 'local_chatbot'));
$PAGE->requires->js(new moodle_url('/local/chatbot/js/espera.js'), true);

if (!\local_chatbot\ux::on('fichaexpost')) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('exp_disabled', 'local_chatbot'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$action = optional_param('action', '', PARAM_ALPHA);

// --- Llamadas de la propia pagina mientras se valida o se preparan las etiquetas ---
if (in_array($action, ['ejecutar', 'estado', 'ejecutaretq', 'estadoetq'], true)) {
    $id = required_param('id', PARAM_INT);
    if ($action === 'ejecutar' || $action === 'ejecutaretq') {
        require_sesskey();
        // Sin cerrar la sesion, las consultas de estado quedarian esperando a que termine.
        \core\session\manager::write_close();
        ignore_user_abort(true);
        core_php_time_limit::raise(300);
        $estado = $action === 'ejecutar' ? expost::run_validation($id, $USER->id) : expost::run_labels($id, $USER->id);
    } else if ($action === 'estado') {
        $estado = $DB->get_field('local_chatbot_expost_val', 'estado', ['id' => $id, 'userid' => $USER->id]);
        if ($estado === implementacion::PROCESANDO) {
            $row = $DB->get_record('local_chatbot_expost', ['id' => $DB->get_field('local_chatbot_expost_val', 'expostid', ['id' => $id])]);
            $ultima = $row ? expost::latest_validation_for((int) $row->id) : null;
            $estado = ($ultima && (int) $ultima->id === $id) ? $ultima->estado : $estado;
        }
    } else {
        $fila = $DB->get_record('local_chatbot_expost', ['id' => $id, 'userid' => $USER->id]);
        $estado = $fila ? expost::labels_state(expost::get_row((int) $fila->implid)) : implementacion::ERROR;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['estado' => $estado ?: implementacion::ERROR]);
    exit;
}

$s = expost::load($USER->id, $courseid);
$impl = $s['impl'];
$activa = $impl !== null && $s['activacion'] === null;

// --- Confirmar que la experiencia de continuidad ya se implemento (condicion de activacion) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'continuidad' && $impl && $s['fases']) {
    require_sesskey();
    if (optional_param('confirmo', 0, PARAM_BOOL)) {
        $row = expost::ensure_row($impl);
        expost::confirm_continuity((int) $row->id);
        redirect($pageurl);
    }
    redirect($pageurl, get_string('exp_continuity_needed', 'local_chatbot'), null, \core\output\notification::NOTIFY_WARNING);
}

// --- Volver a pedir las etiquetas (si la preparacion fallo) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'preparar' && $activa) {
    require_sesskey();
    $row = expost::ensure_row($impl);
    if (!\local_chatbot\ai\provider_factory::is_configured()) {
        redirect($pageurl, get_string('impl_error_sinclave', 'local_chatbot'), null, \core\output\notification::NOTIFY_ERROR);
    }
    expost::request_labels((int) $row->id);
    redirect($pageurl);
}

// --- Guardar (y validar) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['guardar', 'validar'], true) && $activa) {
    require_sesskey();
    $row = expost::refresh_preload($impl, expost::ensure_row($impl));
    $raw = [];
    foreach (expost::field_keys() as $clave => $tipo) {
        $raw[$clave] = optional_param($clave, '', PARAM_TEXT);
    }
    $data = expost::normalize_pending(expost::clean($raw), (array) $row->pre);
    expost::save_data((int) $row->id, $data);
    $row = expost::get_row((int) $impl->id);

    $tipo = \core\output\notification::NOTIFY_SUCCESS;
    $mensaje = get_string('exp_saved', 'local_chatbot');
    $destino = $pageurl;
    if ($action === 'validar') {
        $falta = $row->pre ? expost::missing_inputs($data, $row->pre, $row->etq) : [];
        if (!$row->pre || $row->etiq_estado !== implementacion::LISTO) {
            $tipo = \core\output\notification::NOTIFY_WARNING;
            $mensaje = get_string('exp_labels_needed', 'local_chatbot');
        } else if ($falta) {
            // Control de entrada del prompt: no se aplica el checklist y se pide lo que falta, con la seccion y el campo.
            $tipo = \core\output\notification::NOTIFY_WARNING;
            $mensaje = get_string('exp_missing', 'local_chatbot', implode(' · ', $falta));
        } else if (!\local_chatbot\ai\provider_factory::is_configured()) {
            $tipo = \core\output\notification::NOTIFY_ERROR;
            $mensaje = get_string('impl_error_sinclave', 'local_chatbot');
        } else {
            expost::start_validation($row);
            $mensaje = '';
            $destino = new moodle_url($pageurl, [], 'informe');
            $avisos = expost::form_warnings($data, $row->pre);
            if ($avisos) {
                $tipo = \core\output\notification::NOTIFY_WARNING;
                $mensaje = get_string('exp_warn_validating', 'local_chatbot', implode(' · ', $avisos));
            }
        }
    }
    redirect($destino, $mensaje, null, $tipo);
}

// --- Pagina ---
$row = null;
$etiquetas = implementacion::ERROR;
if ($activa && $s['row']) {
    $row = expost::refresh_preload($impl, $s['row']);
    if ($row->pre !== null && $row->etiq_estado === '') {
        if (\local_chatbot\ai\provider_factory::is_configured()) {
            // Etapa 9: la precarga de las etiquetas se pide sola, sin revision previa de la persona.
            expost::request_labels((int) $row->id);
            $row = expost::get_row((int) $impl->id);
        }
    }
    $etiquetas = expost::labels_state($row);
    $s = expost::load($USER->id, $courseid);
    $row = $s['row'];
}
$val = $s['val'];
$data = $row ? $row->data : expost::clean([]);
$pre = $row ? $row->pre : null;
$etq = $row ? $row->etq : ['didacticas' => [], 'evaluativas' => [], 'practicas' => []];
$listo = $val && $val->estado === implementacion::LISTO && is_array($val->informe);
$vigente = $s['vigente'];
$avanza = $listo && $s['ok'];

// Secciones del curso: la Fase 3 (para volver) y la Fase 4.
$nombres = [];
foreach (get_fast_modinfo($course)->get_section_info_all() as $seccion) {
    $nombres[$seccion->section] = get_section_name($course, $seccion);
}
$urlcurso = new moodle_url('/course/view.php', ['id' => $courseid]);
$fase4 = exante::phase_section($nombres, 4);
$urlfase4 = ($fase4 !== null ? course_get_url($course, $fase4) : null) ?: $urlcurso;

$estadoclase = [implementacion::CUMPLE => 'success', implementacion::PARCIAL => 'warning', implementacion::NO_CUMPLE => 'danger'];
$condclase = [implementacion::APROBADO => 'success', implementacion::OBSERVACIONES => 'warning', implementacion::NO_APROBADO => 'danger'];

/**
 * Pinta un grupo de alternativas con su escala (radios en fila).
 *
 * @param string $name
 * @param array $opciones clave => texto
 * @param string $elegida
 * @return void
 */
function local_chatbot_expost_radios(string $name, array $opciones, string $elegida): void {
    echo '<div class="d-flex flex-wrap" style="gap: 6px 20px;">';
    foreach ($opciones as $k => $texto) {
        $opid = 'f_' . $name . '_' . $k;
        echo '<div class="form-check"><input class="form-check-input" type="radio" name="' . $name . '" id="' . $opid . '" value="'
            . s($k) . '"' . ($elegida === (string) $k ? ' checked' : '') . '><label class="form-check-label small" for="' . $opid . '">'
            . s($texto) . '</label></div>';
    }
    echo '</div>';
}

/**
 * Pinta un campo de la ficha ex post.
 *
 * @param array $campo
 * @param array $data
 * @return void
 */
function local_chatbot_expost_campo(array $campo, array $data): void {
    $id = $campo['id'];
    $extra = '';
    if (in_array($id, ['motivo5'], true)) {
        $extra = ' data-solo-no="1"';
    } else if (in_array($id, ['f5_auto', 'f5_jefe', 'f5_seg', 'f5_prac', 'f5_otra', 'f5_otra_txt', 'momento5', 'n_seg5', 'n_apl5', 'grado54',
            'ejemplos5', 'factores5'], true)) {
        $extra = ' data-solo-si="1"';
    }
    echo '<div class="mb-3 pb-3 border-bottom"' . $extra . '>';
    if (!empty($campo['subtitulo'])) {
        echo '<h6 class="fw-bold text-dark mb-2">' . s($campo['subtitulo']) . '</h6>';
    }
    if (!empty($campo['antes'])) {
        echo '<p class="small text-muted mb-2">' . s($campo['antes']) . '</p>';
    }
    $domid = 'f_' . $id;
    if ($campo['tipo'] === expost::T_CHECK) {
        echo '<div class="form-check"><input class="form-check-input" type="checkbox" name="' . $id . '" id="' . $domid . '" value="1"'
            . (($data[$id] ?? '') === '1' ? ' checked' : '') . '><label class="form-check-label" for="' . $domid . '">'
            . s($campo['label']) . '</label></div></div>';
        return;
    }
    if ($campo['tipo'] === implementacion::T_UNA) {
        $rotulo = (!empty($campo['num']) ? $campo['num'] . '. ' : '') . $campo['label'];
        echo '<fieldset><legend class="fs-6 fw-medium mb-1">' . s($rotulo) . '</legend>';
        if (!empty($campo['ayuda'])) {
            echo '<p class="small text-muted mb-2">' . s($campo['ayuda']) . '</p>';
        }
        $escala = isset($campo['opciones']['1']);
        $elegida = (string) ($data[$id] ?? '');
        if ($escala) {
            local_chatbot_expost_radios($id, $campo['opciones'], $elegida);
        } else {
            echo '<div class="d-flex flex-column" style="gap: 6px;">';
            foreach ($campo['opciones'] as $k => $texto) {
                $opid = $domid . '_' . $k;
                echo '<div class="form-check"><input class="form-check-input" type="radio" name="' . $id . '" id="' . $opid
                    . '" value="' . s($k) . '"' . ($elegida === (string) $k ? ' checked' : '') . '><label class="form-check-label small" for="'
                    . $opid . '">' . s($texto);
                if (!empty($campo['descriptores'][$k])) {
                    echo ' <span class="text-muted">— ' . s($campo['descriptores'][$k]) . '</span>';
                }
                echo '</label></div>';
            }
            echo '</div>';
        }
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
    $rotulo = (!empty($campo['num']) && empty($campo['subtitulo']) ? $campo['num'] . '. ' : '') . $campo['label'];
    echo '<label class="form-label fw-medium mb-1" for="' . $domid . '">' . s($rotulo) . '</label>';
    if (!empty($campo['ayuda'])) {
        echo '<p class="small text-muted mb-2">' . s($campo['ayuda']) . '</p>';
    }
    $valor = s($data[$id] ?? '');
    if ($campo['tipo'] === implementacion::T_PARRAFO) {
        echo '<textarea class="form-control" id="' . $domid . '" name="' . $id . '" rows="3" maxlength="'
            . implementacion::LARGO_PARRAFO . '">' . $valor . '</textarea>';
    } else if ($campo['tipo'] === implementacion::T_NUMERO) {
        echo '<input type="number" min="0" step="1" class="form-control" style="max-width: 200px;" id="' . $domid . '" name="' . $id
            . '" value="' . $valor . '">';
    } else {
        echo '<input type="text" class="form-control" id="' . $domid . '" name="' . $id . '" maxlength="' . implementacion::LARGO_TEXTO
            . '" value="' . $valor . '">';
    }
    echo '</div>';
}

/**
 * Pinta la tabla 3.1 o 3.2: las filas precargadas (etiqueta y experiencia fijas) y las filas libres.
 *
 * @param string $p Prefijo de los campos: d (3.1) o e (3.2).
 * @param array $filas Etiquetas precargadas: lista de ['etiqueta', 'exp'].
 * @param array $data
 * @param string $cabecera Clave de idioma del titulo de la columna de efectividad.
 * @return void
 */
function local_chatbot_expost_tabla(string $p, array $filas, array $data, string $cabecera): void {
    $escala = expost::scale(true);
    foreach (expost::strategy_rows() as $j) {
        $libre = $j > expost::MAX_ETIQUETAS;
        $e = $libre ? null : ($filas[$j - 1] ?? null);
        if (!$libre && $e === null) {
            continue;
        }
        $abierta = !$libre || trim(($data[$p . $j . '_nombre'] ?? '') . ($data[$p . $j . '_ev'] ?? '') . ($data[$p . $j . '_val'] ?? '')) !== '';
        echo '<div class="border rounded p-3 mb-3' . ($libre ? ' bg-light' : '') . '">';
        if ($libre) {
            echo '<details' . ($abierta ? ' open' : '') . '><summary class="fw-medium" style="cursor: pointer;">'
                . get_string('exp_free_row', 'local_chatbot', $j - expost::MAX_ETIQUETAS) . '</summary><div class="mt-2">';
            echo '<label class="form-label small fw-medium mb-1" for="f_' . $p . $j . '_nombre">' . get_string('exp_free_name', 'local_chatbot') . '</label>';
            echo '<input type="text" class="form-control mb-2" id="f_' . $p . $j . '_nombre" name="' . $p . $j . '_nombre" maxlength="'
                . implementacion::LARGO_TEXTO . '" value="' . s($data[$p . $j . '_nombre'] ?? '') . '">';
            echo '<div class="small fw-medium mb-1">' . get_string('exp_experience', 'local_chatbot') . '</div>';
            local_chatbot_expost_radios($p . $j . '_exp', ['inicial' => get_string('exp_exp_inicial', 'local_chatbot'),
                'continuidad' => get_string('exp_exp_continuidad', 'local_chatbot')], (string) ($data[$p . $j . '_exp'] ?? ''));
            echo '<div class="mb-2"></div>';
        } else {
            echo '<div class="d-flex flex-wrap align-items-center gap-2 mb-2"><span class="fw-medium">' . s($e['etiqueta']) . '</span>'
                . '<span class="badge text-bg-light border">' . get_string('exp_preloaded', 'local_chatbot') . '</span>'
                . '<span class="badge text-bg-info">' . get_string('exp_exp_' . $e['exp'], 'local_chatbot') . '</span></div>';
        }
        echo '<div class="small fw-medium mb-1">' . get_string($cabecera, 'local_chatbot') . '</div>';
        local_chatbot_expost_radios($p . $j . '_val', $escala, (string) ($data[$p . $j . '_val'] ?? ''));
        echo '<label class="form-label small fw-medium mt-2 mb-1" for="f_' . $p . $j . '_ev">' . get_string('exp_evidence', 'local_chatbot') . '</label>';
        echo '<textarea class="form-control" id="f_' . $p . $j . '_ev" name="' . $p . $j . '_ev" rows="2" maxlength="'
            . implementacion::LARGO_PARRAFO . '">' . s($data[$p . $j . '_ev'] ?? '') . '</textarea>';
        if ($libre) {
            echo '</div></details>';
        }
        echo '</div>';
    }
}

echo $OUTPUT->header();
?>

<div class="container-fluid" style="max-width: 900px;">

    <h3 class="mb-3"><?php echo get_string('exp_title', 'local_chatbot'); ?></h3>

    <?php echo \local_chatbot\ruta_m3::render($USER->id, $courseid, 4, 0); ?>

<?php if (!$impl || $s['activacion'] === 'fases'): ?>
    <?php // Condicion de activacion del prompt, textual. ?>
    <div class="alert alert-warning">
        <?php echo s(expost::ACTIVACION_MENSAJE); ?>
        <div class="mt-2 d-flex flex-wrap gap-2">
            <a class="chatbot-gatebtn" href="<?php echo (new moodle_url($impl ? '/local/chatbot/calidad.php' : '/local/chatbot/ficha_implementacion.php',
                ['courseid' => $courseid] + ($impl ? ['etapa' => 8] : [])))->out(false); ?>"><?php
                echo get_string('exp_go_fase3', 'local_chatbot'); ?></a>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-4">
        <a class="btn btn-outline-secondary" href="<?php echo $urlfase4->out(false); ?>"><?php echo get_string('exante_back', 'local_chatbot'); ?></a>
    </div>
<?php elseif ($s['activacion'] === 'continuidad'): ?>
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <h5 class="mb-2"><?php echo get_string('exp_continuity_title', 'local_chatbot'); ?></h5>
            <p><?php echo get_string('exp_continuity_help', 'local_chatbot'); ?></p>
            <form method="post" action="<?php echo $pageurl->out(false); ?>">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="confirmo" id="f_confirmo" value="1">
                    <label class="form-check-label" for="f_confirmo"><?php echo get_string('exp_continuity_confirm', 'local_chatbot'); ?></label>
                </div>
                <button type="submit" name="action" value="continuidad" class="btn btn-primary px-4"
                        data-espera="<?php echo s(get_string('exante_saving', 'local_chatbot')); ?>">
                    <i class="fa fa-check me-1"></i> <?php echo get_string('exp_continuity_start', 'local_chatbot'); ?>
                </button>
            </form>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-4">
        <a class="btn btn-outline-secondary" href="<?php echo $urlfase4->out(false); ?>"><?php echo get_string('exante_back', 'local_chatbot'); ?></a>
    </div>
<?php elseif (in_array($etiquetas, [implementacion::PENDIENTE, implementacion::PROCESANDO], true)): ?>
    <?php // Etapa 9: la plataforma prepara la precarga antes de mostrar la bienvenida y la ficha. ?>
    <div class="card shadow-sm border-0 mb-4" id="exp-etiquetas" role="status" data-id="<?php echo (int) $row->id; ?>"
         data-estado="<?php echo s($etiquetas); ?>" data-url="<?php echo s($pageurl->out(false)); ?>" data-sesskey="<?php echo sesskey(); ?>"
         data-volver="<?php echo s((new moodle_url($pageurl, ['e' => time()]))->out(false)); ?>">
        <div class="card-body">
            <h5 class="mb-2"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?php
                echo get_string('exp_preparing', 'local_chatbot'); ?></h5>
            <p class="small text-muted mb-0"><?php echo get_string('exp_preparing_long', 'local_chatbot'); ?></p>
        </div>
    </div>
<?php elseif (!$row || !$row->pre): ?>
    <div class="alert alert-warning"><?php echo get_string('impl_error_sindiseno', 'local_chatbot'); ?></div>
<?php else: ?>

    <?php // Bienvenida de la Fase 4, textual del prompt: es lo primero que dice el asistente. ?>
    <div class="card border-0 shadow-sm mb-3" style="background: #eef5fb; border-left: 5px solid #20639b !important;">
        <div class="card-body d-flex gap-3 align-items-start">
            <div aria-hidden="true" style="flex: 0 0 42px; width: 42px; height: 42px; line-height: 42px; border-radius: 50%;
                background: #20639b; color: #ffffff; text-align: center; font-size: 20px;"><i class="fa fa-comments"></i></div>
            <div>
                <div class="small text-uppercase fw-bold mb-1" style="color: #20639b; letter-spacing: .5px;"><?php
                    echo s(implementacion::ROL); ?></div>
                <?php foreach (expost::welcome() as $i => $parrafo): ?>
                    <p class="<?php echo $i === 0 ? 'fw-bold mb-2' : 'mb-2'; ?>"><?php echo s($parrafo); ?></p>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if ($etiquetas !== implementacion::LISTO): ?>
        <div class="alert alert-warning">
            <?php echo get_string('impl_error_' . ($row->etiq_error ?: ($etiquetas === '' ? 'sinclave' : 'servicio')), 'local_chatbot'); ?>
            <?php echo get_string('exp_labels_error', 'local_chatbot'); ?>
            <form method="post" action="<?php echo $pageurl->out(false); ?>" class="mt-2">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <button type="submit" name="action" value="preparar" class="btn btn-sm chatbot-gatebtn"
                        data-espera="<?php echo s(get_string('exp_preparing', 'local_chatbot')); ?>"><i class="fa fa-refresh me-1"></i><?php
                    echo get_string('exp_labels_retry', 'local_chatbot'); ?></button>
            </form>
        </div>
    <?php endif; ?>

    <div class="alert alert-info">
        <strong><?php echo get_string('exp_intro_title', 'local_chatbot'); ?>.</strong>
        <?php echo get_string('exp_intro', 'local_chatbot'); ?>
        <?php if ($val): ?>
            <div class="mt-2"><a class="chatbot-gatebtn" href="#informe"><?php echo get_string('exante_report_link', 'local_chatbot'); ?></a></div>
        <?php endif; ?>
    </div>

    <form method="post" action="<?php echo $pageurl->out(false); ?>" id="exp-form">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <?php $sec = expost::sections(); $s1 = $pre['s1']; ?>

        <?php // --- Seccion 1 --- ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <h5 class="mb-3 fw-bold text-dark border-bottom pb-2">1. <?php echo s($sec[1]['titulo']); ?></h5>
                <table class="table table-sm small mb-3">
                    <?php foreach ([['exp_s1_plan', $s1['plan']], ['exp_s1_modulo', $s1['modulo']], ['exp_s1_inicial', $s1['exp_inicial']],
                            ['exp_s1_continuidad', $s1['exp_continuidad']], ['exp_s1_facilitador', $s1['facilitador']],
                            ['exp_s1_fechas', $s1['fechas_ini']], ['exp_s1_presentes', $s1['presentes_ini'] === null ? '' : $s1['presentes_ini']],
                            ['exp_s1_fecha', $pre['fecha']]] as [$clave, $valor]): ?>
                        <tr><th class="text-nowrap pe-3" style="width: 1%;"><?php echo get_string($clave, 'local_chatbot'); ?></th>
                            <td><?php echo $valor !== '' ? s($valor) : '—'; ?>
                                <span class="badge text-bg-light border ms-1"><?php echo get_string('exp_preloaded', 'local_chatbot'); ?></span></td></tr>
                    <?php endforeach; ?>
                </table>
                <?php foreach ($sec[1]['campos'] as $campo) {
                    if (!empty($campo['condicional']) && $s1['exp_continuidad'] !== '') {
                        continue;
                    }
                    local_chatbot_expost_campo($campo, $data);
                } ?>
            </div>
        </div>

        <?php // --- Seccion 2 --- ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <h5 class="mb-3 fw-bold text-dark border-bottom pb-2">2. <?php echo s($sec[2]['titulo']); ?></h5>
                <h6 class="fw-bold text-dark mb-2">2.1 <?php echo get_string('exp_t21_title', 'local_chatbot'); ?></h6>
                <p class="small text-muted mb-2"><?php echo get_string('exp_t21_help', 'local_chatbot'); ?></p>
                <details class="small mb-3">
                    <summary class="text-primary" style="cursor: pointer;"><?php echo get_string('impl_levels_help', 'local_chatbot'); ?></summary>
                    <ul class="mt-1 mb-0">
                        <?php foreach (implementacion::level_help() as $clave => $descripcion): ?>
                            <li><strong><?php echo s(implementacion::NIVELES[$clave]); ?>:</strong> <?php echo s($descripcion); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
                <?php foreach (array_values($pre['criterios']) as $i => $c):
                    $k = $i + 1;
                    $pend = !empty($c['pendiente']);
                    $ini = $c['inicial']; ?>
                    <div class="border rounded p-3 mb-3" data-fila="<?php echo $k; ?>" data-pendiente="<?php echo $pend ? 1 : 0; ?>">
                        <div class="fw-bold mb-1"><?php echo get_string('exp_t21_criterion', 'local_chatbot', $k); ?>
                            <span class="fw-normal text-muted"><?php echo s($c['texto']); ?></span></div>
                        <div class="small mb-2"><?php echo get_string('exp_t21_initial', 'local_chatbot'); ?>:
                            <strong><?php echo $ini === null ? '—' : s(implementacion::pct((float) $ini['logro'])); ?></strong>
                            <span class="badge text-bg-light border ms-1"><?php echo get_string('exp_preloaded', 'local_chatbot'); ?></span>
                            <?php if ($ini !== null): ?>
                                <span class="text-muted ms-1">(<?php echo s(implode(' · ', array_map(static function($clave) use ($ini) {
                                    return implementacion::NIVELES[$clave] . ' ' . $ini[$clave];
                                }, array_keys(implementacion::NIVELES)))); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($pend): ?>
                            <div class="bg-light rounded p-2 mb-2">
                                <div class="small fw-medium mb-1"><?php echo get_string('exp_t22_question', 'local_chatbot'); ?></div>
                                <?php local_chatbot_expost_radios('pr' . $k, expost::answers(), (string) ($data['pr' . $k] ?? '')); ?>
                                <label class="form-label small mt-2 mb-1" for="f_pc<?php echo $k; ?>"><?php echo get_string('exp_t22_comment', 'local_chatbot'); ?></label>
                                <textarea class="form-control form-control-sm" id="f_pc<?php echo $k; ?>" name="pc<?php echo $k; ?>" rows="2"
                                          maxlength="<?php echo implementacion::LARGO_PARRAFO; ?>"><?php echo s($data['pc' . $k] ?? ''); ?></textarea>
                                <p class="small text-muted mt-1 mb-0"><?php echo get_string('exp_t22_note', 'local_chatbot'); ?></p>
                            </div>
                        <?php endif; ?>
                        <div data-niveles="<?php echo $k; ?>">
                            <div class="d-flex flex-wrap gap-3 mb-2 align-items-end">
                                <?php foreach (implementacion::NIVELES as $clave => $nombrenivel): ?>
                                    <div>
                                        <label class="form-label small fw-medium mb-1" for="f_<?php echo $clave . $k; ?>"
                                               title="<?php echo s(implementacion::level_help()[$clave]); ?>"><?php echo s($nombrenivel); ?></label>
                                        <input type="number" min="0" step="1" class="form-control form-control-sm" style="width: 105px;"
                                               id="f_<?php echo $clave . $k; ?>" name="<?php echo $clave . $k; ?>" data-nivel="<?php echo $k; ?>"
                                               value="<?php echo s($data[$clave . $k] ?? ''); ?>">
                                    </div>
                                <?php endforeach; ?>
                                <div>
                                    <label class="form-label small fw-medium mb-1" for="f_lg<?php echo $k; ?>"><?php
                                        echo get_string('exp_t21_final', 'local_chatbot'); ?></label>
                                    <input type="text" inputmode="decimal" class="form-control form-control-sm" style="width: 105px;"
                                           id="f_lg<?php echo $k; ?>" name="lg<?php echo $k; ?>" data-logro="<?php echo $k; ?>"
                                           value="<?php echo s(str_replace('.', ',', $data['lg' . $k] ?? '')); ?>">
                                </div>
                                <div class="small text-muted" data-suma="<?php echo $k; ?>" role="status"></div>
                            </div>
                        </div>
                        <label class="form-label small fw-medium mb-1" for="f_cm<?php echo $k; ?>"><?php
                            echo get_string('exp_t21_comment', 'local_chatbot'); ?> <span class="fw-normal text-muted">(<?php
                            echo get_string('exante_optional', 'local_chatbot'); ?>)</span></label>
                        <textarea class="form-control" id="f_cm<?php echo $k; ?>" name="cm<?php echo $k; ?>" rows="2"
                                  maxlength="<?php echo implementacion::LARGO_PARRAFO; ?>"><?php echo s($data['cm' . $k] ?? ''); ?></textarea>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php // --- Seccion 3 --- ?>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <h5 class="mb-3 fw-bold text-dark border-bottom pb-2">3. <?php echo s($sec[3]['titulo']); ?></h5>
                <p class="small text-muted mb-3"><?php echo get_string('exp_s3_note', 'local_chatbot'); ?></p>
                <h6 class="fw-bold text-dark mb-2">3.1 <?php echo get_string('exp_t31_title', 'local_chatbot'); ?></h6>
                <?php if (!$etq['didacticas']): ?><p class="small text-muted"><?php echo get_string('exp_labels_none', 'local_chatbot'); ?></p><?php endif; ?>
                <?php local_chatbot_expost_tabla('d', $etq['didacticas'], $data, 'exp_t31_scale'); ?>
                <h6 class="fw-bold text-dark mt-4 mb-2">3.2 <?php echo get_string('exp_t32_title', 'local_chatbot'); ?></h6>
                <?php if (!$etq['evaluativas']): ?><p class="small text-muted"><?php echo get_string('exp_labels_none', 'local_chatbot'); ?></p><?php endif; ?>
                <?php local_chatbot_expost_tabla('e', $etq['evaluativas'], $data, 'exp_t32_scale'); ?>
                <h6 class="fw-bold text-dark mt-4 mb-2">3.3 <?php echo get_string('exp_t33_title', 'local_chatbot'); ?></h6>
                <?php foreach ($sec[3]['campos'] as $campo) {
                    local_chatbot_expost_campo($campo, $data);
                } ?>
            </div>
        </div>

        <?php // --- Secciones 4 a 7 --- ?>
        <?php foreach ([4, 5, 6, 7] as $n): ?>
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <h5 class="mb-3 fw-bold text-dark border-bottom pb-2"><?php echo $n . '. ' . s($sec[$n]['titulo']); ?></h5>
                    <?php if (!empty($sec[$n]['nota'])): ?><p class="small text-muted mb-3"><?php echo s($sec[$n]['nota']); ?></p><?php endif; ?>
                    <?php foreach ($sec[$n]['campos'] as $campo) {
                        local_chatbot_expost_campo($campo, $data);
                    } ?>
                    <?php if ($n === 6): ?>
                        <h6 class="fw-bold text-dark mb-2">6.4 <?php echo get_string('exp_t64_title', 'local_chatbot'); ?></h6>
                        <p class="small text-muted mb-2"><?php echo get_string('exp_t64_help', 'local_chatbot'); ?></p>
                        <?php if (!$etq['practicas']): ?><p class="small text-muted"><?php echo get_string('exp_labels_none', 'local_chatbot'); ?></p><?php endif; ?>
                        <?php foreach (array_values($etq['practicas']) as $i => $practica): $j = $i + 1; ?>
                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><span class="fw-medium"><?php echo s($practica); ?></span>
                                    <span class="badge text-bg-light border"><?php echo get_string('exp_preloaded', 'local_chatbot'); ?></span></div>
                                <?php local_chatbot_expost_radios('bp' . $j . '_dec', expost::options('bp' . $j . '_dec'), (string) ($data['bp' . $j . '_dec'] ?? '')); ?>
                                <label class="form-label small fw-medium mt-2 mb-1" for="f_bp<?php echo $j; ?>_fund"><?php
                                    echo get_string('exp_t64_reason', 'local_chatbot'); ?></label>
                                <textarea class="form-control" id="f_bp<?php echo $j; ?>_fund" name="bp<?php echo $j; ?>_fund" rows="2"
                                          maxlength="<?php echo implementacion::LARGO_PARRAFO; ?>"><?php echo s($data['bp' . $j . '_fund'] ?? ''); ?></textarea>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <p class="small text-muted"><?php echo get_string('exp_privacy', 'local_chatbot'); ?></p>
        <?php $avisosform = $pre ? expost::form_warnings($data, $pre) : []; ?>
        <?php if ($avisosform): ?>
            <div class="alert alert-warning small" role="alert">
                <strong><?php echo get_string('exante_warn_title', 'local_chatbot'); ?></strong>
                <ul class="mb-1"><?php foreach ($avisosform as $aviso): ?><li><?php echo s($aviso); ?></li><?php endforeach; ?></ul>
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
        <div class="card shadow-sm border-0 mb-4" id="exp-validando" role="status"
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
                    <h4 class="mb-0"><?php echo get_string('exp_report_title', 'local_chatbot'); ?></h4>
                    <span class="small text-muted"><?php echo get_string('exante_report_date', 'local_chatbot',
                        userdate($val->timemodified, get_string('strftimedatetimeshort', 'langconfig'))); ?></span>
                </div>
                <?php if (!$vigente): ?>
                    <div class="alert alert-warning"><?php echo get_string('exp_report_stale', 'local_chatbot'); ?></div>
                <?php endif; ?>

                <h5 class="mb-2">1. <?php echo get_string('impl_report_sections', 'local_chatbot'); ?></h5>
                <div class="table-responsive mb-4">
                <table class="table table-sm align-middle mb-0 chatbot-exante-checklist">
                    <thead><tr>
                        <th><?php echo get_string('exante_report_item', 'local_chatbot'); ?></th>
                        <th style="width: 190px;"><?php echo get_string('exante_report_eval', 'local_chatbot'); ?></th>
                        <th><?php echo get_string('exante_report_obs', 'local_chatbot'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach (expost::checklist() as $n => $seccion): ?>
                        <tr class="table-light"><th colspan="3" class="small text-uppercase"><?php echo $n . '. ' . s($seccion['titulo']); ?></th></tr>
                        <?php foreach ($seccion['items'] as $id => $texto):
                            $item = $informe['items'][$id] ?? null; ?>
                            <tr>
                                <td class="small"><?php echo s($texto); ?>
                                    <?php if (in_array($id, expost::CRITICOS, true)): ?>
                                        <span class="badge text-bg-light border text-danger ms-1"><?php echo get_string('autocheck_critical', 'local_chatbot'); ?></span>
                                    <?php endif; ?>
                                    <?php if (($item['origen'] ?? '') === 'calculo'): ?>
                                        <span class="badge text-bg-light border text-secondary ms-1" title="<?php
                                            echo s(get_string('impl_report_calculated_help', 'local_chatbot')); ?>"><?php
                                            echo get_string('impl_report_calculated', 'local_chatbot'); ?></span>
                                    <?php endif; ?></td>
                                <?php if ($item === null): ?>
                                    <td><span class="badge text-bg-light border"><?php echo get_string('exante_status_none', 'local_chatbot'); ?></span></td>
                                    <td class="small text-muted"><?php echo get_string('exante_status_none_help', 'local_chatbot'); ?></td></tr>
                                    <?php continue; ?>
                                <?php endif; ?>
                                <td><span class="badge text-bg-<?php echo $estadoclase[$item['estado']]; ?>"><?php
                                    echo get_string('exante_status_' . $item['estado'], 'local_chatbot'); ?></span></td>
                                <td class="small"><?php echo s($item['observacion']); ?>
                                    <?php if (!empty($item['forma'])): ?>
                                        <div class="mt-1 text-muted"><em><?php echo get_string('exp_report_form', 'local_chatbot'); ?></em></div>
                                    <?php endif; ?>
                                    <?php if (!empty($item['propuesta'])): ?>
                                        <div class="mt-1 text-success-emphasis"><strong><?php echo get_string('exante_report_howto', 'local_chatbot'); ?></strong>
                                            <?php echo s($item['propuesta']); ?></div>
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
                    <?php echo get_string('exp_cond_' . $val->condicion . '_desc', 'local_chatbot'); ?>
                    <?php if (!empty($informe['regla'])): // Aplicacion explicita de la regla de decision. ?>
                        <div class="small mt-2"><strong><?php echo get_string('exante_rule_label', 'local_chatbot'); ?></strong>
                            <?php echo get_string('exante_rule_' . $informe['regla'], 'local_chatbot'); ?></div>
                    <?php endif; ?>
                </div>
                <?php $detienen = !empty($informe['regla']) && $val->condicion === implementacion::NO_APROBADO
                    ? expost::critical_failures($informe['items']) : []; ?>
                <?php if ($detienen): ?>
                    <div class="alert alert-danger mb-4">
                        <h6 class="mb-2"><?php echo get_string('exante_report_mustfix', 'local_chatbot'); ?></h6>
                        <ul class="small mb-0">
                            <?php foreach ($detienen as $id): $item = $informe['items'][$id] ?? []; ?>
                                <li class="mb-1"><strong><?php echo s(expost::checklist_texts()[$id]); ?></strong>
                                    <?php if (!empty($item['observacion'])): ?><br><?php echo s($item['observacion']); ?><?php endif; ?>
                                    <?php if (!empty($item['propuesta'])): ?><br><strong><?php echo get_string('exante_report_howto', 'local_chatbot'); ?></strong>
                                        <?php echo s($item['propuesta']); ?><?php endif; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <h5 class="mb-2">3. <?php echo get_string('exante_report_weak', 'local_chatbot'); ?></h5>
                <?php if (!empty($informe['debilidades'])): ?>
                    <ul class="small"><?php foreach ($informe['debilidades'] as $texto): ?><li><?php echo s($texto); ?></li><?php endforeach; ?></ul>
                <?php else: ?>
                    <p class="small"><?php echo get_string('impl_report_none', 'local_chatbot'); ?></p>
                <?php endif; ?>

                <h5 class="mb-2 mt-3">4. <?php echo get_string('impl_report_suggest', 'local_chatbot'); ?></h5>
                <?php if (!empty($informe['sugerencias'])): ?>
                    <ul class="small"><?php foreach ($informe['sugerencias'] as $texto): ?><li><?php echo s($texto); ?></li><?php endforeach; ?></ul>
                <?php else: ?>
                    <p class="small"><?php echo get_string('impl_report_none', 'local_chatbot'); ?></p>
                <?php endif; ?>

                <?php $pendientes = expost::unresolved_items($informe['items'] ?? []); ?>
                <h5 class="mb-2 mt-3">5. <?php echo get_string('exante_report_unresolved', 'local_chatbot'); ?></h5>
                <?php if ($pendientes): ?>
                    <p class="small text-muted mb-1"><?php echo get_string('exp_report_unresolved_help', 'local_chatbot'); ?></p>
                    <ul class="small">
                        <?php foreach ($pendientes as $p): ?>
                            <li><?php echo get_string('exante_report_section', 'local_chatbot', $p['seccion']); ?>: <?php echo s($p['texto']); ?>
                                <span class="badge text-bg-<?php echo $estadoclase[$p['estado']]; ?>"><?php
                                    echo get_string('exante_status_' . $p['estado'], 'local_chatbot'); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="small"><?php echo get_string('exante_report_unresolved_none', 'local_chatbot'); ?></p>
                <?php endif; ?>

                <?php if (!empty($informe['verificaciones'])): ?>
                    <details class="small mb-3">
                        <summary class="text-primary" style="cursor: pointer;"><?php echo get_string('exp_report_checks', 'local_chatbot'); ?></summary>
                        <ul class="mt-1 mb-0"><?php foreach ($informe['verificaciones'] as $linea): ?><li><?php echo s($linea); ?></li><?php endforeach; ?></ul>
                    </details>
                <?php endif; ?>

                <p class="small text-muted mb-1"><?php echo empty($informe['adjuntos'])
                    ? get_string('impl_report_nodocs', 'local_chatbot')
                    : get_string('exante_report_docs', 'local_chatbot', s(implode(', ', array_map(static function($etiqueta, $nombre) {
                        return $etiqueta . ' (' . $nombre . ')';
                    }, array_keys($informe['adjuntos']), $informe['adjuntos'])))); ?></p>
                <?php if (!empty($informe['fuentes_web'])): ?>
                    <p class="small text-muted mb-1"><?php echo get_string('exante_report_websources', 'local_chatbot'); ?>
                        <?php echo implode(', ', array_map(static function($f) {
                            return html_writer::link($f['uri'], s($f['title'] ?: $f['uri']), ['target' => '_blank', 'rel' => 'noopener']);
                        }, $informe['fuentes_web'])); ?></p>
                <?php endif; ?>
                <p class="small text-muted mb-0"><?php echo get_string('exp_report_note', 'local_chatbot'); ?></p>
            </div>
        </div>
        <?php if (!$avanza && $vigente): ?>
            <div class="alert alert-info"><?php echo get_string('exp_blocked', 'local_chatbot'); ?></div>
        <?php endif; ?>
    <?php endif; ?>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <?php if ($avanza): ?>
            <a class="chatbot-backbtn chatbot-nextphase" href="<?php echo (new moodle_url('/local/chatbot/expost.php',
                ['courseid' => $courseid, 'etapa' => 11]))->out(false); ?>">
                <?php echo get_string('exp_next', 'local_chatbot'); ?>
                <span class="chatbot-backbtn-arrow" aria-hidden="true">&rarr;</span>
            </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?php echo $urlfase4->out(false); ?>"><?php echo get_string('exante_back', 'local_chatbot'); ?></a>
    </div>
<?php endif; ?>
</div>

<script>
(function() {
    var form = document.getElementById('exp-form');
    if (form) {
        var num = function(campo) {
            var v = campo ? parseInt(campo.value, 10) : NaN;
            return isNaN(v) ? null : v;
        };
        var evaluados = form.elements['evaluados'];

        // La suma por nivel debe ser igual a los evaluados al cierre; el logro final se calcula con la formula de la ficha.
        var revisar = function(k, escribirlogro) {
            var n = {}, suma = 0, vacio = false;
            ['ins', 'bas', 'ade', 'ava'].forEach(function(c) {
                var v = num(form.elements[c + k]);
                n[c] = v === null ? 0 : v;
                vacio = vacio || v === null;
                suma += n[c];
            });
            var p = num(evaluados);
            var aviso = form.querySelector('[data-suma="' + k + '"]');
            var logro = form.elements['lg' + k];
            if (p !== null && p > 0 && !vacio) {
                var calculado = Math.round(1000 * (n.ade + n.ava) / p) / 10;
                if (escribirlogro && logro && (logro.value === '' || logro.dataset.auto === '1')) {
                    logro.value = String(calculado).replace('.', ',');
                    logro.dataset.auto = '1';
                }
                if (aviso) {
                    aviso.textContent = suma === p ? <?php echo json_encode(get_string('exp_js_sum_ok', 'local_chatbot')); ?>
                        : <?php echo json_encode(get_string('exp_js_sum_bad', 'local_chatbot')); ?>.replace('{suma}', suma).replace('{evaluados}', p);
                    aviso.className = 'small ' + (suma === p ? 'text-success' : 'text-danger');
                }
            } else if (aviso) {
                aviso.textContent = '';
            }
        };
        form.querySelectorAll('[data-nivel]').forEach(function(campo) {
            campo.addEventListener('input', function() { revisar(campo.getAttribute('data-nivel'), true); });
        });
        form.querySelectorAll('[data-logro]').forEach(function(campo) {
            campo.addEventListener('input', function() { campo.dataset.auto = '0'; });
        });
        if (evaluados) {
            evaluados.addEventListener('input', function() {
                form.querySelectorAll('[data-fila]').forEach(function(fila) { revisar(fila.getAttribute('data-fila'), true); });
            });
        }

        // Pendientes: solo los marcados «Si» tienen niveles; los demas quedan sin niveles y siguen pendientes.
        var pendientes = function() {
            form.querySelectorAll('[data-pendiente="1"]').forEach(function(fila) {
                var k = fila.getAttribute('data-fila');
                var marcado = form.querySelector('input[name="pr' + k + '"]:checked');
                var caja = fila.querySelector('[data-niveles="' + k + '"]');
                if (caja) { caja.hidden = !(marcado && marcado.value === 'si'); }
            });
        };
        form.querySelectorAll('[data-pendiente="1"] input[type="radio"]').forEach(function(r) {
            r.addEventListener('change', pendientes);
        });
        pendientes();
        form.querySelectorAll('[data-fila]').forEach(function(fila) { revisar(fila.getAttribute('data-fila'), false); });

        // Transferencia: si no hubo oportunidad (5.1 = No), solo se pide el motivo.
        var transferencia = function() {
            var marcado = form.querySelector('input[name="op51"]:checked');
            var no = marcado && marcado.value === 'no';
            form.querySelectorAll('[data-solo-si]').forEach(function(b) { b.hidden = no; });
            form.querySelectorAll('[data-solo-no]').forEach(function(b) { b.hidden = !no; });
        };
        form.querySelectorAll('input[name="op51"]').forEach(function(r) { r.addEventListener('change', transferencia); });
        transferencia();
    }

    // Validacion y preparacion de etiquetas: la pagina dispara la segunda llamada y consulta el estado.
    var vigilar = function(id, accionejecutar, accionestado) {
        var caja = document.getElementById(id);
        if (!caja) {
            return;
        }
        var cid = caja.getAttribute('data-id');
        var url = caja.getAttribute('data-url');
        var terminar = function(datos) {
            if (datos && (datos.estado === 'listo' || datos.estado === 'error')) {
                window.location.replace(caja.getAttribute('data-volver'));
                return true;
            }
            return false;
        };
        var revisar = function() {
            fetch(url + '&action=' + accionestado + '&id=' + encodeURIComponent(cid), {credentials: 'same-origin'})
                .then(function(r) { return r.json(); })
                .then(function(datos) { if (!terminar(datos)) { window.setTimeout(revisar, 4000); } })
                .catch(function() { window.setTimeout(revisar, 6000); });
        };
        if (caja.getAttribute('data-estado') === 'pendiente') {
            var cuerpo = new URLSearchParams();
            cuerpo.append('action', accionejecutar);
            cuerpo.append('id', cid);
            cuerpo.append('sesskey', caja.getAttribute('data-sesskey'));
            // Si el servidor web corta esta llamada, el trabajo sigue y la consulta de estado lo recoge.
            fetch(url, {method: 'POST', credentials: 'same-origin', body: cuerpo})
                .then(function(r) { return r.json(); })
                .then(function(datos) { if (!terminar(datos)) { window.setTimeout(revisar, 4000); } })
                .catch(function() { window.setTimeout(revisar, 4000); });
        } else {
            window.setTimeout(revisar, 4000);
        }
    };
    vigilar('exp-validando', 'ejecutar', 'estado');
    vigilar('exp-etiquetas', 'ejecutaretq', 'estadoetq');
})();
</script>

<?php
echo $OUTPUT->footer();
