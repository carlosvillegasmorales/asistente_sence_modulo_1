<?php
/**
 * Mi Plan de Desarrollo (componente 3 del Modulo 1).
 *
 * Muestra las metas propuestas por el Modo B (Coach) junto a las escritas por
 * la propia persona, y deja constancia de cada decision. Una meta propuesta
 * por la IA no forma parte del plan hasta que la persona la acepta o la
 * modifica: esa es la regla de gobernanza sobre revision de recomendaciones,
 * aplicada al flujo real y no solo declarada.
 */

require_once(__DIR__ . '/../../config.php');

[$courseid, $context] = \local_chatbot\course_bridge::setup_page('/local/chatbot/pdp.php',
    'pdp_title', 'pdp_heading');

if (!\local_chatbot\profile::has_consent($USER->id, $courseid)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('error_needsconsent', 'local_chatbot'), 'warning');
    echo $OUTPUT->single_button(
        new moodle_url('/local/chatbot/index.php', ['courseid' => $courseid]),
        get_string('action_gotostart', 'local_chatbot'), 'get');
    echo $OUTPUT->footer();
    exit;
}

$plan = \local_chatbot\pdp::get_or_create($USER->id, $courseid);
$message = '';
$messagetype = 'info';

// --- Pedir propuestas al coach ---
if (optional_param('propose', 0, PARAM_INT) === 1 && confirm_sesskey()) {
    // Con autopdp, pedir metas rehace la version del plan en vez de sumar metas repetidas.
    $result = \local_chatbot\ux::on('autopdp')
        ? \local_chatbot\pdp::sync_from_diagnostic($USER->id, $courseid)
        : \local_chatbot\pdp::propose_goals($USER->id, $courseid);
    $message = $result['message'];
    $messagetype = $result['ok'] ? 'success' : 'warning';
}

// --- Version anterior o siguiente del plan ---
$paso = optional_param('version', 0, PARAM_INT);
if (($paso === -1 || $paso === 1) && confirm_sesskey()) {
    if (\local_chatbot\pdp::move_version($USER->id, $courseid, $paso)) {
        $message = get_string('pdpver_moved', 'local_chatbot');
        $messagetype = 'info';
    }
}

/**
 * Convierte el valor de un input type="date" (AAAA-MM-DD) en timestamp.
 *
 * @param string $value
 * @return int|null null si viene vacio o no es una fecha valida.
 */
function local_chatbot_pdp_parse_date(string $value): ?int {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    return make_timestamp((int) $m[1], (int) $m[2], (int) $m[3], 12);
}

// --- Apoyos adaptativos (seccion 5 del spec) ---
// Todas las operaciones verifican que el apoyo sea de quien opera: adaptive::get()
// y adaptive::propose() filtran por $USER->id y por el plan de la persona.
$adaptaction = optional_param('adaptaction', '', PARAM_ALPHA);
$focusadapt = 0; // Apoyo recien tocado: la pagina se desplaza hasta el.
if ($adaptaction !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if ($adaptaction === 'propose') {
        $result = \local_chatbot\adaptive::propose($USER->id, $courseid, required_param('goal', PARAM_INT));
        $message = $result['message'];
        $messagetype = $result['ok'] ? 'success' : 'warning';
        $focusadapt = (int) ($result['adaptid'] ?? 0);
    } else if ($adaptaction === 'start') {
        $focusadapt = required_param('adapt', PARAM_INT);
        \local_chatbot\adaptive::start($focusadapt, $USER->id);
    } else if ($adaptaction === 'submit') {
        // La evidencia puede venir como texto, como PDF o como ambos
        // (RU-ADAPT-003). El PDF se envia al modelo y no se guarda: del
        // registro solo queda la sintesis.
        $pdfpath = null;
        if (isset($_FILES['evidence_file']) && $_FILES['evidence_file']['error'] === UPLOAD_ERR_OK
                && mime_content_type($_FILES['evidence_file']['tmp_name']) === 'application/pdf') {
            $pdfpath = $_FILES['evidence_file']['tmp_name'];
        }
        $focusadapt = required_param('adapt', PARAM_INT);
        $result = \local_chatbot\adaptive::submit_evidence($focusadapt, $USER->id,
            optional_param('evidence', '', PARAM_TEXT), $pdfpath);
        $message = $result['ok'] ? get_string('adapt_reviewed', 'local_chatbot') : $result['message'];
        $messagetype = $result['ok'] ? 'success' : 'warning';
    } else if ($adaptaction === 'confirm') {
        $focusadapt = (int) $DB->get_field('local_chatbot_adapt_review', 'adaptid', ['id' => required_param('review', PARAM_INT)]);
        \local_chatbot\adaptive::confirm(required_param('review', PARAM_INT), $USER->id,
            optional_param('accept', 0, PARAM_INT) === 1);
        $message = get_string('pdp_decision_saved', 'local_chatbot');
        $messagetype = 'success';
    }
}

/**
 * Bloque de apoyos adaptativos de una meta activa.
 *
 * @param stdClass $goal
 * @param int $courseid
 * @return string HTML.
 */
function local_chatbot_pdp_adaptive_block(stdClass $goal, int $courseid): string {
    $url = new moodle_url('/local/chatbot/pdp.php', ['courseid' => $courseid]);
    $sesskey = sesskey();
    $labels = \local_chatbot\adaptive::state_labels_plain();
    $html = '<div class="border-top mt-3 pt-3">';

    $supports = \local_chatbot\adaptive::list_for_goal((int) $goal->id);
    $open = array_filter($supports, function($adapt) {
        return !\local_chatbot\adaptive::is_terminal($adapt->estado);
    });

    if (empty($open)) {
        $html .= '<form method="POST" action="' . $url . '" class="d-inline">'
            . '<input type="hidden" name="sesskey" value="' . $sesskey . '">'
            . '<input type="hidden" name="adaptaction" value="propose">'
            . '<input type="hidden" name="goal" value="' . (int) $goal->id . '">'
            . '<button type="submit" class="btn btn-outline-primary btn-sm" data-espera="' . s(get_string('espera_apoyo', 'local_chatbot')) . '" data-espera-larga="' . s(get_string('espera_apoyo_larga', 'local_chatbot')) . '">'
            . get_string('adapt_ask', 'local_chatbot') . '</button></form>'
            . '<p class="small text-muted mt-2 mb-0">' . get_string('adapt_ask_help', 'local_chatbot') . '</p>';
    }

    foreach ($open as $adapt) {
        $html .= '<div class="bg-light rounded p-3 mb-2 border border-info chatbot-apoyo" id="apoyo-' . (int) $adapt->id . '">'
            . '<div class="d-flex justify-content-between align-items-start mb-2">'
            . '<strong class="small">' . get_string('adapt_title', 'local_chatbot') . '</strong>'
            . '<span class="badge text-bg-info">' . s($labels[$adapt->estado]) . '</span></div>'
            . '<div class="small mb-2">' . format_text($adapt->consigna, FORMAT_MARKDOWN) . '</div>';

        // Ayuda a mano: preguntarle al Tutor sobre esta actividad sin salir de aqui. La
        // consigna viaja en un atributo y el script la lee de ahi, no se interpola en codigo.
        if (\local_chatbot\ux::on('supporthelp')) {
            $consigna = shorten_text(trim(strip_tags(format_text($adapt->consigna, FORMAT_MARKDOWN))), 280);
            $html .= '<div class="mb-2"><span class="small text-muted me-2">' . get_string('adapt_help_label', 'local_chatbot')
                . ':</span>';
            foreach (['how', 'example', 'evidence'] as $ayuda) {
                $html .= '<button type="button" class="btn btn-outline-primary btn-sm me-1 mb-1 chatbot-apoyo-ayuda"'
                    . ' data-consulta="' . s(get_string('adapt_help_q_' . $ayuda, 'local_chatbot', $consigna)) . '">'
                    . get_string('adapt_help_' . $ayuda, 'local_chatbot') . '</button>';
            }
            $html .= '</div>';
        }

        $reviews = \local_chatbot\adaptive::list_reviews((int) $adapt->id);
        $last = $reviews ? end($reviews) : null;
        if ($last && !(int) $last->rejected) {
            $html .= '<div class="small border-start ps-2 mb-2">'
                . '<strong>' . get_string('adapt_feedback', 'local_chatbot') . ':</strong> ' . s($last->feedbacksynthesis)
                . '<br><strong>' . get_string('adapt_next', 'local_chatbot') . ':</strong> ' . s($last->nextaction)
                . '</div>';
        }

        // Una propuesta sobre la meta espera la decision de la persona (RU-ADAPT-004).
        if ($last && (int) $last->personconfirmed === -1) {
            $question = $last->proposal === \local_chatbot\adaptive::PROPOSAL_CLOSE
                ? get_string('adapt_confirm_close', 'local_chatbot')
                : get_string('adapt_confirm_update', 'local_chatbot', s($last->proposaltext));
            $html .= '<p class="small mb-2"><strong>' . $question . '</strong></p>';
            foreach ([1 => ['action_accept', 'btn-success'], 0 => ['adapt_not_now', 'btn-outline-secondary']] as $value => $btn) {
                $html .= '<form method="POST" action="' . $url . '" class="d-inline me-1">'
                    . '<input type="hidden" name="sesskey" value="' . $sesskey . '">'
                    . '<input type="hidden" name="adaptaction" value="confirm">'
                    . '<input type="hidden" name="review" value="' . (int) $last->id . '">'
                    . '<input type="hidden" name="accept" value="' . $value . '">'
                    . '<button type="submit" class="btn btn-sm ' . $btn[1] . '">'
                    . get_string($btn[0], 'local_chatbot') . '</button></form>';
            }
        } else if ($adapt->estado === \local_chatbot\adaptive::STATE_PROPUESTA_IA) {
            $html .= '<form method="POST" action="' . $url . '">'
                . '<input type="hidden" name="sesskey" value="' . $sesskey . '">'
                . '<input type="hidden" name="adaptaction" value="start">'
                . '<input type="hidden" name="adapt" value="' . (int) $adapt->id . '">'
                . '<button type="submit" class="btn btn-primary btn-sm">'
                . get_string('adapt_start', 'local_chatbot') . '</button></form>';
        } else if (\local_chatbot\adaptive::accepts_evidence($adapt->estado)) {
            $html .= '<form method="POST" action="' . $url . '" enctype="multipart/form-data">'
                . '<input type="hidden" name="sesskey" value="' . $sesskey . '">'
                . '<input type="hidden" name="adaptaction" value="submit">'
                . '<input type="hidden" name="adapt" value="' . (int) $adapt->id . '">'
                . '<p class="small fw-semibold mb-1">' . get_string('adapt_where_to_write', 'local_chatbot') . '</p>'
                . '<textarea autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="evidence" class="form-control form-control-sm mb-2" rows="4" placeholder="'
                . s(get_string('adapt_evidence_ph', 'local_chatbot')) . '"></textarea>'
                . '<input type="file" name="evidence_file" accept=".pdf" class="form-control mb-2">'
                . '<button type="submit" class="btn btn-primary btn-sm" data-espera="' . s(get_string('espera_evidencia', 'local_chatbot')) . '" data-espera-larga="' . s(get_string('espera_evidencia_larga', 'local_chatbot')) . '">'
                . get_string('adapt_submit', 'local_chatbot') . '</button>'
                . (\local_chatbot\ux::on('supporthelp')
                    ? '<button type="button" class="btn btn-outline-primary btn-sm ms-2 chatbot-borrador"'
                        . ' data-intro="' . s(get_string('adapt_draft_q', 'local_chatbot')) . '"'
                        . ' data-vacio="' . s(get_string('adapt_draft_empty', 'local_chatbot')) . '">'
                        . get_string('adapt_draft_btn', 'local_chatbot') . '</button>'
                        . '<small class="text-muted d-block mt-1">' . get_string('adapt_draft_note', 'local_chatbot') . '</small>'
                    : '')
                . '<small class="text-muted d-block mt-1">' . get_string('adapt_submit_help', 'local_chatbot') . '</small>'
                . '</form>';
        }
        $html .= '</div>';
    }

    return $html . '</div>';
}

// --- Decidir sobre una meta ---
$decidegoal = optional_param('goal', 0, PARAM_INT);
$decision = optional_param('decision', '', PARAM_ALPHA);
if ($decidegoal > 0 && $decision !== '' && confirm_sesskey()) {
    // La meta debe pertenecer al plan de quien esta operando.
    $goal = $DB->get_record('local_chatbot_pdp_goal', ['id' => $decidegoal, 'pdpid' => $plan->id]);
    if ($goal) {
        $changes = [];
        if ($decision === \local_chatbot\pdp::GOAL_MODIFICADO) {
            foreach (['objetivo', 'acciones', 'evidencia',
                    'unidad', 'recurso', 'microcapsula', 'actividad'] as $field) {
                $value = optional_param($field, null, PARAM_TEXT);
                if ($value !== null) {
                    $changes[$field] = trim($value);
                }
            }
            $plazo = local_chatbot_pdp_parse_date(optional_param('plazo', '', PARAM_RAW_TRIMMED));
            if ($plazo !== null) {
                $changes['plazo'] = $plazo;
            }
        }
        $reason = trim(optional_param('reason', '', PARAM_TEXT));
        // Una contrasena autocompletada por el navegador no entra al plan (ni, desde ahi, al modelo).
        $parececlave = \local_chatbot\guardrails::looks_like_password($reason);
        foreach ($changes as $value) {
            $parececlave = $parececlave || (is_string($value) && \local_chatbot\guardrails::looks_like_password($value));
        }
        if ($parececlave) {
            $message = get_string('error_looks_like_password', 'local_chatbot');
            $messagetype = 'warning';
        } else {
            if ($reason === '') {
                $reason = get_string('pdp_reason_default', 'local_chatbot');
            }
            \local_chatbot\pdp::decide_goal($decidegoal, $decision, $USER->id, $reason, $changes);
            $message = get_string('pdp_decision_saved', 'local_chatbot');
            $messagetype = 'success';
        }
    }
}

// --- Agregar meta propia ---
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && optional_param('action', '', PARAM_ALPHA) === 'addgoal' && confirm_sesskey()) {
    $objetivo = trim(required_param('objetivo', PARAM_TEXT));
    $parececlave = false;
    foreach ([$objetivo, optional_param('acciones', '', PARAM_TEXT), optional_param('evidencia', '', PARAM_TEXT),
            optional_param('brecha', '', PARAM_TEXT)] as $campo) {
        $parececlave = $parececlave || \local_chatbot\guardrails::looks_like_password((string) $campo);
    }
    if ($parececlave) {
        $message = get_string('error_looks_like_password', 'local_chatbot');
        $messagetype = 'warning';
    } else if ($objetivo !== '') {
        \local_chatbot\pdp::add_goal($plan->id, [
            'brecha' => trim(optional_param('brecha', '', PARAM_TEXT)),
            'objetivo' => $objetivo,
            'acciones' => trim(optional_param('acciones', '', PARAM_TEXT)),
            'evidencia' => trim(optional_param('evidencia', '', PARAM_TEXT)),
            'plazo' => local_chatbot_pdp_parse_date(optional_param('plazo', '', PARAM_RAW_TRIMMED)),
        ], \local_chatbot\pdp::ORIGIN_USUARIO, $USER->id);
        $message = get_string('pdp_goal_added', 'local_chatbot');
        $messagetype = 'success';
    }
}

// --- Validar el plan ---
if (optional_param('validate', 0, PARAM_INT) === 1 && confirm_sesskey()) {
    $validation = \local_chatbot\pdp::validate($plan->id, $USER->id);
    if ($validation['ok']) {
        $message = get_string('pdp_validated', 'local_chatbot');
        $messagetype = 'success';
    } else if (empty($validation['incomplete'])) {
        $message = get_string('pdp_validate_nogoals', 'local_chatbot');
        $messagetype = 'warning';
    } else {
        // RU-COA-PDP-FLOW-001: el plan no pasa a activo con metas incompletas.
        $message = get_string('pdp_validate_incomplete', 'local_chatbot', count($validation['incomplete']));
        $messagetype = 'warning';
    }
    $plan = \local_chatbot\pdp::get_or_create($USER->id, $courseid);
}
// Las acciones de arriba (version nueva, cambio de version, validar) cambian el plan: se relee.
$plan = \local_chatbot\pdp::get_or_create($USER->id, $courseid);

$proposed = \local_chatbot\pdp::list_goals($plan->id, [\local_chatbot\pdp::GOAL_PROPUESTO]);
// Las postergadas se listan junto a las activas: la persona las dejo para mas
// adelante, no las descarto, y tiene que poder volver a ellas (RU-COA-004).
$active = \local_chatbot\pdp::list_goals($plan->id, [
    \local_chatbot\pdp::GOAL_ACEPTADO,
    \local_chatbot\pdp::GOAL_MODIFICADO,
    \local_chatbot\pdp::GOAL_POSTERGADO,
    \local_chatbot\pdp::GOAL_COMPLETADO,
]);
// Interruptores de experiencia (classes/ux.php): cada parte se puede apagar desde la administracion.
$uxorder = \local_chatbot\ux::on('goalorder');
// Ajustes del cliente del 08-10-2026: la persona no conversa con el Coach; el Coach trabaja en esta pagina.
$tutoronly = \local_chatbot\ux::on('tutoronly');
$uxgap = \local_chatbot\ux::on('gapchange');
$uxfocus = \local_chatbot\ux::on('supportfocus');
if (!$uxfocus) {
    $focusadapt = 0;
}
// Orden para seguir el plan: lo que se trabaja ahora, luego lo postergado, al final lo completado.
// Las propuestas por revisar van agrupadas por dimension, en el orden de la matriz.
$dimorder = $DB->get_records_menu('local_chatbot_dimension', null, '', 'id, sortorder');
if ($uxorder) {
    $active = \local_chatbot\pdp::order_goals($active, $dimorder);
    usort($proposed, static function ($a, $b) use ($dimorder) {
        return [$dimorder[(int) $a->dimensionid] ?? PHP_INT_MAX, (int) $a->id]
            <=> [$dimorder[(int) $b->dimensionid] ?? PHP_INT_MAX, (int) $b->id];
    });
} else {
    $active = array_values($active);
}
// Cambio de la brecha de cada meta desde que se creo: se compara con la matriz mas reciente
// (la de salida si existe, si no la de entrada). Solo informa; no califica.
$matrizahora = \local_chatbot\diagnostic::get_gap_matrix($USER->id, $courseid, \local_chatbot\diagnostic::STAGE_SALIDA)
    ?: \local_chatbot\diagnostic::get_gap_matrix($USER->id, $courseid);
$nivelahora = [];
foreach ($matrizahora as $fila) {
    $nivelahora[(int) $fila['dimensionid']] = $fila['nivel'];
}
$brechacambio = [];
$cambiaron = 0;
foreach ($active as $meta) {
    $cambio = \local_chatbot\pdp::gap_change((string) $meta->brecha, $nivelahora[(int) $meta->dimensionid] ?? '',
        \local_chatbot\diagnostic::level_labels_plain(), \local_chatbot\diagnostic::levels());
    $brechacambio[(int) $meta->id] = $cambio;
    if ($meta->status !== \local_chatbot\pdp::GOAL_COMPLETADO && in_array($cambio['trend'], ['sube', 'baja'], true)) {
        $cambiaron++;
    }
}
// Apoyos en curso por meta: la guia del plan los lista arriba con un enlace, porque el bloque
// de cada apoyo queda al pie de su meta y, con varias metas, se perdia de vista.
$apoyopormeta = [];
$apoyosguia = [];
foreach ($active as $indice => $meta) {
    if (!in_array($meta->status, [\local_chatbot\pdp::GOAL_ACEPTADO, \local_chatbot\pdp::GOAL_MODIFICADO], true)) {
        continue;
    }
    foreach (\local_chatbot\adaptive::list_for_goal((int) $meta->id) as $apoyo) {
        if (!\local_chatbot\adaptive::is_terminal($apoyo->estado)) {
            $apoyopormeta[(int) $meta->id] = (int) $apoyo->id;
            $apoyosguia[] = ['n' => $indice + 1, 'id' => (int) $apoyo->id, 'estado' => $apoyo->estado];
            break;
        }
    }
}
$history = \local_chatbot\pdp::get_history($plan->id);
// Consulta del historial de metas (Doc 6.0 §10.2): una por carga de la pagina, solo con el plan.
\local_chatbot\trace::log(\local_chatbot\trace::PDP_HISTORY_VIEW, $USER->id, $courseid,
    \local_chatbot\trace::unit_for_course($courseid), ['metas' => count($active), 'version_plan' => (int) $plan->version]);

// Estados de meta con el vocabulario del SPEC v5 (RU-ADAPT-006). Se derivan de la
// decision de la persona, del plan y del ultimo apoyo adaptativo; se muestran como
// texto y no solo como color (RU-UX-001).
$statesv5 = \local_chatbot\pdp::goal_states_v5_for($plan, $active);

// Los cuatro vinculos que RU-COA-003 exige por prioridad, para no repetir el
// mismo bloque de markup en la vista de decision y en la de metas activas.
$goallinks = [
    'unidad' => 'goal_unidad',
    'recurso' => 'goal_recurso',
    // Sin microcapsulas (SPEC v5, RU-COA-003): el campo queda en la tabla por los planes
    // anteriores, pero ya no se muestra ni se propone.
    'actividad' => 'goal_actividad',
];

// El curso refleja lo que acaba de pasar en el recorrido: barra de progreso,
// restricciones de acceso e informes de finalizacion.
\local_chatbot\course_bridge::sync($USER->id, $courseid);

echo $OUTPUT->header();
?>

<div class="container-fluid" style="max-width: 900px;">

    <?php echo \local_chatbot\course_bridge::route_bar($USER->id, $courseid, 'pdp'); ?>

    <?php $bloqueo = \local_chatbot\course_bridge::gate_html($USER->id, $courseid, 'pdp'); ?>
    <?php if ($bloqueo !== ''): ?>
        <?php echo $bloqueo; ?>
</div>
        <?php echo $OUTPUT->footer(); exit; ?>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messagetype; ?>"><?php echo s($message); ?></div>
    <?php endif; ?>

    <?php if (\local_chatbot\ux::on('pdpguide')): ?>
    <?php
    // Guia del paso: donde esta la persona, que hace falta en este plan y que sigue. No usa la IA:
    // sale del estado del plan, asi que siempre dice lo mismo que la pantalla.
    $guide = \local_chatbot\journey::pdp_guide(count($proposed), count($active),
        $plan->status === \local_chatbot\pdp::PLAN_ACTIVO);
    ?>
    <div class="card border-primary shadow-sm mb-4" id="chatbot-pdp-guide">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div>
                    <h6 class="mb-0"><?php echo get_string('pdpguide_title', 'local_chatbot'); ?></h6>
                    <span class="small text-muted"><?php
                        // Posicion del plan en la ruta vigente (paso 3 de 5, o etapa 5 de 6 y opcional).
                        $rutaplan = \local_chatbot\course_bridge::route_keys();
                        echo get_string(in_array('ruta', $rutaplan, true) ? 'pdpguide_here_optional' : 'pdpguide_here_n',
                            'local_chatbot', ['n' => array_search('pdp', $rutaplan, true) + 1, 'total' => count($rutaplan)]);
                    ?></span>
                </div>
                <?php if (!$tutoronly): ?>
                <button type="button" class="btn btn-sm btn-outline-primary"
                        onclick="if (window.chatbotAsk) { window.chatbotAsk('<?php
                            echo s(addslashes_js(get_string('pdpguide_help_q', 'local_chatbot'))); ?>', 'coach'); }">
                    <?php echo get_string('pdpguide_help', 'local_chatbot'); ?>
                </button>
                <?php endif; ?>
            </div>
            <ol class="list-unstyled mb-2">
                <?php foreach ($guide as $g): ?>
                    <li class="d-flex align-items-start gap-2 mb-1<?php echo $g['current'] ? ' fw-semibold' : ''; ?>">
                        <span aria-hidden="true"><?php echo $g['done'] ? '✅' : ($g['current'] ? '👉' : '⚪'); ?></span>
                        <span>
                            <?php echo $g['key'] === 'review' && $g['count'] > 0
                                ? get_string('pdpguide_review_count', 'local_chatbot', $g['count'])
                                : get_string('pdpguide_' . $g['key'], 'local_chatbot'); ?>
                            <?php if ($g['current']): ?>
                                <span class="badge text-bg-primary ms-1"><?php echo get_string('pdpguide_now', 'local_chatbot'); ?></span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?php if ($uxfocus && !empty($apoyosguia)): ?>
                <?php $etqapoyo = \local_chatbot\adaptive::state_labels_plain(); ?>
                <div class="small mb-2"><strong><?php echo get_string('pdpguide_supports', 'local_chatbot'); ?>:</strong>
                    <?php foreach ($apoyosguia as $ap): ?>
                        <a class="badge text-bg-info text-decoration-none ms-1" href="#apoyo-<?php echo $ap['id']; ?>"><?php
                            echo get_string('pdpguide_support_link', 'local_chatbot',
                                ['n' => $ap['n'], 'state' => s($etqapoyo[$ap['estado']] ?? $ap['estado'])]); ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($uxgap && $cambiaron > 0): ?>
                <div class="alert alert-info small py-2 mb-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span><?php echo get_string('pdpguide_gap_changed', 'local_chatbot', $cambiaron); ?></span>
                    <?php if ($tutoronly): ?>
                    <a class="btn btn-sm btn-outline-primary"
                       data-espera="<?php echo s(get_string('espera_metas', 'local_chatbot')); ?>"
                       href="<?php echo new moodle_url('/local/chatbot/pdp.php',
                           ['courseid' => $courseid, 'propose' => 1, 'sesskey' => sesskey()]); ?>">
                        <?php echo get_string('pdpguide_gap_propose', 'local_chatbot'); ?>
                    </a>
                    <?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            onclick="if (window.chatbotAsk) { window.chatbotAsk('<?php
                                echo s(addslashes_js(get_string('pdpguide_gap_q', 'local_chatbot'))); ?>', 'coach'); }">
                        <?php echo get_string('pdpguide_gap_ask', 'local_chatbot'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($plan->status === \local_chatbot\pdp::PLAN_ACTIVO): ?>
                <p class="small text-muted mb-0"><?php echo get_string('pdpguide_done_note', 'local_chatbot'); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <p class="text-muted"><?php echo get_string('pdp_explain', 'local_chatbot'); ?></p>

    <div class="mb-4">
        <a class="btn btn-primary"
           data-espera="<?php echo s(get_string('espera_metas', 'local_chatbot')); ?>"
           data-espera-larga="<?php echo s(get_string('espera_metas_larga', 'local_chatbot')); ?>"
           href="<?php echo new moodle_url('/local/chatbot/pdp.php',
               ['courseid' => $courseid, 'propose' => 1, 'sesskey' => sesskey()]); ?>">
            <?php echo get_string('pdp_ask_coach', 'local_chatbot'); ?>
        </a>
        <?php if (!empty($active)): ?>
            <a class="btn btn-outline-success"
               href="<?php echo new moodle_url('/local/chatbot/pdp.php',
                   ['courseid' => $courseid, 'validate' => 1, 'sesskey' => sesskey()]); ?>">
                <?php echo get_string('pdp_validate', 'local_chatbot'); ?>
            </a>
        <?php endif; ?>
        <span class="badge text-bg-light ms-2">
            <?php echo get_string('pdp_status', 'local_chatbot',
                ['status' => $plan->status, 'version' => $plan->version]); ?>
        </span>
    </div>

    <?php if (\local_chatbot\ux::on('autopdp') && (int) $plan->rondamax > 1): ?>
    <?php
    // Versiones del plan: cada cambio del diagnostico rehace las metas del asistente en una version
    // nueva; las anteriores quedan guardadas con sus apoyos y se puede volver a ellas.
    $ver = \local_chatbot\pdp::version_info($plan);
    $verurl = static function (int $paso) use ($courseid): string {
        return (new moodle_url('/local/chatbot/pdp.php', ['courseid' => $courseid, 'version' => $paso,
            'sesskey' => sesskey()]))->out();
    };
    ?>
    <div class="chatbot-pdpver d-flex flex-wrap align-items-center gap-2 mb-4" role="navigation"
         aria-label="<?php echo s(get_string('pdpver_nav', 'local_chatbot')); ?>">
        <?php if ($ver['actual'] > 1): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo $verurl(-1); ?>">
                <?php echo get_string('pdpver_prev', 'local_chatbot'); ?></a>
        <?php endif; ?>
        <span class="small fw-bold"><?php echo get_string('pdpver_label', 'local_chatbot', ['actual' => $ver['actual'], 'total' => $ver['total']]); ?></span>
        <?php if ($ver['fecha'] > 0): ?>
            <span class="small text-muted"><?php echo userdate($ver['fecha'], get_string('strftimedatetimeshort', 'langconfig')); ?></span>
        <?php endif; ?>
        <?php if ($ver['actual'] < $ver['total']): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo $verurl(1); ?>">
                <?php echo get_string('pdpver_next', 'local_chatbot'); ?></a>
            <span class="small text-warning-emphasis"><?php echo get_string('pdpver_old', 'local_chatbot'); ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($proposed)): ?>
        <h5 class="mb-3" id="propuestas"><?php echo get_string('pdp_proposed_title', 'local_chatbot'); ?>
            <?php if ($uxorder): ?><span class="badge text-bg-warning ms-1"><?php echo count($proposed); ?></span><?php endif; ?></h5>
        <p class="small text-muted"><?php echo get_string('pdp_proposed_help', 'local_chatbot'); ?></p>

        <?php $numero = 0; ?>
        <?php foreach ($proposed as $goal): ?>
            <?php $numero++; ?>
            <div class="card shadow-sm border-warning mb-3">
                <div class="card-body">
                    <?php if ($uxorder): ?>
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge rounded-circle text-bg-warning me-2 d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:1.9rem;height:1.9rem" aria-label="<?php echo s(get_string('pdp_goal_number', 'local_chatbot', $numero)); ?>"><?php echo $numero; ?></span>
                        <span class="small text-muted"><?php echo get_string('pdp_goal_number', 'local_chatbot', $numero); ?></span>
                    </div>
                    <?php endif; ?>
                    <form method="POST" action="<?php echo new moodle_url('/local/chatbot/pdp.php'); ?>">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
                        <input type="hidden" name="goal" value="<?php echo $goal->id; ?>">

                        <?php if (!empty($goal->brecha)): ?>
                            <p class="small text-muted mb-2">
                                <strong><?php echo get_string('goal_brecha', 'local_chatbot'); ?>:</strong>
                                <?php echo s($goal->brecha); ?>
                            </p>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">
                                <?php echo get_string('goal_objetivo', 'local_chatbot'); ?></label>
                            <textarea autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="objetivo" class="form-control" rows="2"><?php
                                echo s($goal->objetivo); ?></textarea>
                        </div>
                        <div class="row g-2">
                            <?php foreach ($goallinks as $field => $stringkey): ?>
                                <div class="mb-3 col-md-6">
                                    <label class="form-label small fw-bold">
                                        <?php echo get_string($stringkey, 'local_chatbot'); ?></label>
                                    <input type="text" autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="<?php echo $field; ?>" class="form-control"
                                           value="<?php echo s($goal->{$field}); ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">
                                <?php echo get_string('goal_acciones', 'local_chatbot'); ?></label>
                            <textarea autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="acciones" class="form-control" rows="2"><?php
                                echo s($goal->acciones); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">
                                <?php echo get_string('goal_evidencia', 'local_chatbot'); ?></label>
                            <textarea autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="evidencia" class="form-control" rows="2"><?php
                                echo s($goal->evidencia); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">
                                <?php echo get_string('goal_plazo', 'local_chatbot'); ?></label>
                            <input type="date" name="plazo" class="form-control" style="max-width: 200px;"
                                   value="<?php echo !empty($goal->plazo) ? date('Y-m-d', $goal->plazo) : ''; ?>">
                            <small class="text-muted"><?php echo get_string('goal_plazo_help', 'local_chatbot'); ?></small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">
                                <?php echo get_string('goal_reason', 'local_chatbot'); ?></label>
                            <input type="text" autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="reason" class="form-control"
                                   placeholder="<?php echo get_string('goal_reason_ph', 'local_chatbot'); ?>">
                        </div>

                        <button type="submit" name="decision" class="btn btn-success btn-sm"
                                value="<?php echo \local_chatbot\pdp::GOAL_ACEPTADO; ?>">
                            <?php echo get_string('action_accept', 'local_chatbot'); ?>
                        </button>
                        <button type="submit" name="decision" class="btn btn-primary btn-sm"
                                value="<?php echo \local_chatbot\pdp::GOAL_MODIFICADO; ?>">
                            <?php echo get_string('action_modify', 'local_chatbot'); ?>
                        </button>
                        <button type="submit" name="decision" class="btn btn-outline-secondary btn-sm"
                                value="<?php echo \local_chatbot\pdp::GOAL_POSTERGADO; ?>">
                            <?php echo get_string('action_postpone', 'local_chatbot'); ?>
                        </button>
                        <button type="submit" name="decision" class="btn btn-outline-danger btn-sm"
                                value="<?php echo \local_chatbot\pdp::GOAL_RECHAZADO; ?>">
                            <?php echo get_string('action_reject', 'local_chatbot'); ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h5 class="mb-3 mt-4"><?php echo get_string('pdp_active_title', 'local_chatbot'); ?></h5>
    <?php if (empty($active)): ?>
        <div class="alert alert-light border"><?php echo get_string('pdp_empty', 'local_chatbot'); ?></div>
    <?php else: ?>
        <?php $grupoactual = ''; $numero = 0;
        $porgrupo = array_count_values(array_map(static function ($g) {
            return \local_chatbot\pdp::goal_group($g->status);
        }, $active)); ?>
        <?php foreach ($active as $goal): ?>
            <?php $grupo = \local_chatbot\pdp::goal_group($goal->status); $numero++; ?>
            <?php if ($uxorder && $grupo !== $grupoactual): $grupoactual = $grupo; ?>
                <h6 class="text-muted text-uppercase small mt-4 mb-2"><?php
                    echo get_string('pdp_group_' . $grupo, 'local_chatbot'); ?>
                    <span class="badge text-bg-secondary ms-1"><?php echo $porgrupo[$grupo]; ?></span></h6>
            <?php endif; ?>
            <div class="card shadow-sm border-0 mb-3" id="meta-<?php echo (int) $goal->id; ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <?php $apoyoabierto = $uxfocus ? ($apoyopormeta[(int) $goal->id] ?? 0) : 0; ?>
                        <h6 class="mb-2 d-flex align-items-start"><?php if ($uxorder): ?><span class="badge rounded-circle text-bg-primary me-2 d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width:1.9rem;height:1.9rem" aria-label="<?php echo s(get_string('pdp_goal_number', 'local_chatbot', $numero)); ?>"><?php echo $numero; ?></span><?php endif; ?><span><?php echo s($goal->objetivo); ?></span></h6>
                        <span class="text-end">
                        <span class="badge text-bg-secondary"><?php
                            echo s(\local_chatbot\pdp::GOAL_STATES_V5[$statesv5[(int) $goal->id] ?? ''] ?? $goal->status);
                            ?></span>
                        <?php if ($apoyoabierto): ?>
                            <a class="badge text-bg-info text-decoration-none ms-1" href="#apoyo-<?php echo (int) $apoyoabierto; ?>"><?php
                                echo get_string('adapt_open_badge', 'local_chatbot'); ?></a>
                        <?php endif; ?>
                        </span>
                    </div>
                    <?php $cambio = $brechacambio[(int) $goal->id] ?? null; ?>
                    <?php if ($uxgap && $cambio && $cambio['now'] !== null): ?>
                        <?php $etq = \local_chatbot\diagnostic::level_labels_plain(); ?>
                        <p class="small mb-1"><strong><?php echo get_string('goal_brecha', 'local_chatbot'); ?>:</strong>
                            <?php if ($cambio['before'] !== null && $cambio['trend'] === 'igual'): ?>
                                <?php // Sin cambio: una sola mencion del nivel, no «Funcional → ahora: Funcional». ?>
                                <?php echo get_string('pdp_gap_same_line', 'local_chatbot', s($etq[$cambio['now']])); ?>
                            <?php elseif ($cambio['before'] !== null): ?>
                                <?php echo get_string('pdp_gap_before_now', 'local_chatbot',
                                    ['before' => s($etq[$cambio['before']]), 'now' => s($etq[$cambio['now']])]); ?>
                                <span class="badge text-bg-<?php echo ['sube' => 'success', 'baja' => 'warning',
                                    'igual' => 'light'][$cambio['trend']] ?? 'light'; ?> ms-1"><?php
                                    echo get_string('pdp_gap_' . $cambio['trend'], 'local_chatbot'); ?></span>
                            <?php else: ?>
                                <?php echo get_string('pdp_gap_now_only', 'local_chatbot', s($etq[$cambio['now']])); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <?php foreach ($goallinks as $field => $stringkey): ?>
                        <?php if (!empty($goal->{$field})): ?>
                            <p class="small mb-1"><strong><?php
                                echo get_string($stringkey, 'local_chatbot'); ?>:</strong>
                                <?php echo s($goal->{$field}); ?></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!empty($goal->acciones)): ?>
                        <p class="small mb-1"><strong><?php
                            echo get_string('goal_acciones', 'local_chatbot'); ?>:</strong>
                            <?php echo s($goal->acciones); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($goal->evidencia)): ?>
                        <p class="small mb-2"><strong><?php
                            echo get_string('goal_evidencia', 'local_chatbot'); ?>:</strong>
                            <?php echo s($goal->evidencia); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($goal->plazo)): ?>
                        <p class="small mb-2"><strong><?php
                            echo get_string('goal_plazo', 'local_chatbot'); ?>:</strong>
                            <?php echo userdate($goal->plazo, get_string('strftimedatefullshort', 'langconfig')); ?></p>
                    <?php endif; ?>
                    <?php
                    // RU-COA-PDP-FLOW-001: lo que impide activar el plan se dice en la meta
                    // misma, no solo en un mensaje general al validar.
                    $missing = in_array($goal->status, [\local_chatbot\pdp::GOAL_ACEPTADO, \local_chatbot\pdp::GOAL_MODIFICADO], true)
                        ? \local_chatbot\pdp::missing_for_activation($goal) : [];
                    if (!empty($missing)): ?>
                        <p class="small text-warning mb-2"><?php echo get_string('goal_missing', 'local_chatbot',
                            implode(', ', array_map(function($field) {
                                return get_string('goal_' . $field, 'local_chatbot');
                            }, $missing))); ?></p>
                    <?php endif; ?>
                    <?php if ($goal->status !== \local_chatbot\pdp::GOAL_COMPLETADO): ?>
                        <a class="btn btn-outline-success btn-sm"
                           href="<?php echo new moodle_url('/local/chatbot/pdp.php', [
                               'courseid' => $courseid, 'goal' => $goal->id,
                               'decision' => \local_chatbot\pdp::GOAL_COMPLETADO,
                               'sesskey' => sesskey()]); ?>">
                            <?php echo get_string('action_complete', 'local_chatbot'); ?>
                        </a>
                    <?php endif; ?>
                    <?php // RU-UX-002: el Coach se abre desde la meta, con esa meta como contexto. ?>
                    <?php if (!$tutoronly): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm chatbot-goal-coach"
                            data-objetivo="<?php echo s($goal->objetivo); ?>"
                            data-brecha="<?php echo s($goal->brecha ?? ''); ?>"
                            data-goalid="<?php echo (int) $goal->id; ?>"
                            data-goallabel="<?php echo s(get_string('pdp_goal_number', 'local_chatbot', $numero)); ?>">
                        <?php echo get_string('goal_ask_coach', 'local_chatbot'); ?>
                    </button>
                    <?php endif; ?>
                    <?php if (\local_chatbot\ux::on('goalprogress')): ?>
                        <?php
                        // «Mi avance»: que paso con esta meta y que dice la ultima revision. Sale de lo
                        // registrado, no de la IA, y no es una nota.
                        $apoyosmeta = [];
                        foreach (\local_chatbot\adaptive::list_for_goal((int) $goal->id) as $ap) {
                            $apoyosmeta[] = ['timegenerated' => $ap->timegenerated, 'timestarted' => $ap->timestarted,
                                'estado' => $ap->estado,
                                'reviews' => array_values(\local_chatbot\adaptive::list_reviews((int) $ap->id))];
                        }
                        $avance = \local_chatbot\pdp::goal_progress($goal, $apoyosmeta);
                        $etqestado = \local_chatbot\adaptive::state_labels_plain();
                        ?>
                        <details class="chatbot-progress mt-2">
                            <summary><?php echo get_string('progress_title', 'local_chatbot'); ?>
                                <span class="text-muted small ms-1"><?php echo get_string('progress_summary', 'local_chatbot',
                                    $avance['evidences']); ?></span></summary>
                            <ol class="chatbot-progress-list">
                                <?php foreach ($avance['events'] as $ev): ?>
                                    <li><span class="chatbot-progress-date"><?php
                                        echo userdate($ev['time'], get_string('strftimedateshort', 'langconfig')); ?></span>
                                        <?php if ($ev['key'] === 'evidencia'): ?>
                                            <?php echo get_string('progress_evidencia', 'local_chatbot', [
                                                'n' => $ev['a']['n'],
                                                'estado' => s($etqestado[$ev['a']['estado']] ?? $ev['a']['estado'])]); ?>
                                        <?php else: ?>
                                            <?php echo get_string('progress_' . $ev['key'], 'local_chatbot'); ?>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                            <?php if ($avance['actual'] !== null): ?>
                                <p class="small mb-1"><strong><?php echo get_string('progress_actual', 'local_chatbot'); ?>:</strong>
                                    <?php echo s($etqestado[$avance['actual']] ?? $avance['actual']); ?></p>
                            <?php endif; ?>
                            <?php if ($avance['logro'] !== null): ?>
                                <p class="small mb-1"><strong><?php echo get_string('progress_logro', 'local_chatbot'); ?>:</strong>
                                    <?php echo s($avance['logro']); ?></p>
                            <?php endif; ?>
                            <?php if ($avance['falta'] !== null): ?>
                                <p class="small mb-1"><strong><?php echo get_string('progress_falta', 'local_chatbot'); ?>:</strong>
                                    <?php echo s($avance['falta']); ?></p>
                            <?php endif; ?>
                            <?php if ($avance['evidences'] === 0): ?>
                                <p class="small text-muted mb-1"><?php echo get_string('progress_none', 'local_chatbot'); ?></p>
                            <?php endif; ?>
                            <p class="small text-muted mb-0"><?php echo get_string('progress_note', 'local_chatbot'); ?></p>
                        </details>
                    <?php endif; ?>
                    <?php if (in_array($goal->status, [\local_chatbot\pdp::GOAL_ACEPTADO, \local_chatbot\pdp::GOAL_MODIFICADO], true)): ?>
                        <?php echo local_chatbot_pdp_adaptive_block($goal, $courseid); ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <h6 class="mb-3"><?php echo get_string('pdp_addgoal_title', 'local_chatbot'); ?></h6>
            <form method="POST">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
                <input type="hidden" name="action" value="addgoal">
                <div class="mb-3">
                    <label class="form-label small"><?php echo get_string('goal_objetivo', 'local_chatbot'); ?></label>
                    <input type="text" autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="objetivo" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small"><?php echo get_string('goal_acciones', 'local_chatbot'); ?></label>
                    <input type="text" autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="acciones" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label small"><?php echo get_string('goal_evidencia', 'local_chatbot'); ?></label>
                    <input type="text" autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-form-type="other" name="evidencia" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label small"><?php echo get_string('goal_plazo', 'local_chatbot'); ?></label>
                    <input type="date" name="plazo" class="form-control" style="max-width: 200px;">
                </div>
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <?php echo get_string('pdp_addgoal_submit', 'local_chatbot'); ?>
                </button>
            </form>
        </div>
    </div>

    <?php if (!empty($history)): ?>
        <h5 class="mb-3"><?php echo get_string('pdp_history_title', 'local_chatbot'); ?></h5>
        <p class="small text-muted"><?php echo get_string('pdp_history_help', 'local_chatbot'); ?></p>
        <div class="card shadow-sm border-0 mb-4">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th><?php echo get_string('history_date', 'local_chatbot'); ?></th>
                            <th><?php echo get_string('history_change', 'local_chatbot'); ?></th>
                            <th><?php echo get_string('history_reason', 'local_chatbot'); ?></th>
                            <th><?php echo get_string('history_evidence', 'local_chatbot'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $entry): ?>
                        <tr>
                            <td class="small"><?php echo userdate($entry->timecreated,
                                get_string('strftimedatetimeshort')); ?></td>
                            <td class="small"><?php echo s($entry->changetype); ?></td>
                            <td class="small"><?php echo s($entry->reason); ?></td>
                            <td class="small"><?php echo s($entry->evidence); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php // Terminar un paso y no saber que sigue es donde la gente se detiene. ?>
    <?php echo \local_chatbot\course_bridge::next_step_card($USER->id, $courseid, 'pdp'); ?>

    <?php echo \local_chatbot\course_bridge::back_to_course_button($courseid, 'pdp'); ?>
    <a href="<?php echo new moodle_url('/local/chatbot/index.php', ['courseid' => $courseid]); ?>"
       class="btn btn-outline-secondary mb-4"><?php echo get_string('action_back', 'local_chatbot'); ?></a>
</div>

<script>
// RU-UX-002: conversar una meta con el Coach sin salir del plan. El texto de la
// meta se toma del atributo, no se interpola en el script: asi no hay forma de
// que lo que escribio la persona se ejecute como codigo.
document.addEventListener('DOMContentLoaded', function() {
    // Revision informal del borrador: se manda al chat con el Tutor, que ya tiene la consigna y
    // sus criterios. No entrega la evidencia ni cambia el estado del apoyo.
    document.querySelectorAll('.chatbot-borrador').forEach(function(boton) {
        boton.addEventListener('click', function() {
            var formulario = boton.closest('form');
            var cuadro = formulario ? formulario.querySelector('textarea[name="evidence"]') : null;
            var borrador = cuadro ? cuadro.value.trim() : '';
            if (borrador === '') {
                window.alert(boton.getAttribute('data-vacio') || '');
                if (cuadro) { cuadro.focus(); }
                return;
            }
            var consulta = (boton.getAttribute('data-intro') || '') + '\n\n' + borrador.substring(0, 4000);
            if (typeof window.chatbotAsk === 'function') {
                window.chatbotAsk(consulta, 'tutor');
            } else if (typeof window.chatbotOpen === 'function') {
                window.chatbotOpen();
            }
        });
    });

    document.querySelectorAll('.chatbot-apoyo-ayuda').forEach(function(boton) {
        boton.addEventListener('click', function() {
            var consulta = boton.getAttribute('data-consulta') || '';
            // Con el Tutor: ya recibe la consigna del apoyo en curso y esta hecho para ayudar a
            // entenderla y pensar como abordarla, sin producir la evidencia por la persona.
            if (typeof window.chatbotAsk === 'function') {
                window.chatbotAsk(consulta, 'tutor');
            } else if (typeof window.chatbotOpen === 'function') {
                window.chatbotOpen();
            }
        });
    });

    // Llevar a la persona hasta el apoyo que acaba de pedir o tocar, o al que enlazo la guia.
    var destino = <?php echo (int) $focusadapt; ?> ? document.getElementById('apoyo-<?php echo (int) $focusadapt; ?>') : null;
    if (!destino && window.location.hash.indexOf('#apoyo-') === 0) {
        destino = document.getElementById(window.location.hash.substring(1));
    }
    if (destino) {
        setTimeout(function() {
            destino.scrollIntoView({behavior: 'smooth', block: 'center'});
            destino.classList.add('border-3', 'shadow');
            setTimeout(function() { destino.classList.remove('border-3', 'shadow'); }, 3500);
        }, 150);
    }

    document.querySelectorAll('.chatbot-goal-coach').forEach(function(boton) {
        boton.addEventListener('click', function() {
            var objetivo = boton.getAttribute('data-objetivo') || '';
            var brecha = boton.getAttribute('data-brecha') || '';
            var consulta = 'Quiero trabajar esta meta de mi plan: "' + objetivo + '".'
                + (brecha ? ' Aborda la brecha: ' + brecha + '.' : '')
                + ' ¿Cómo la voy a lograr y qué evidencia me conviene preparar?';
            // Con conversaciones por meta, se abre (o retoma) el hilo de esta meta.
            if (typeof window.chatbotGoalThread === 'function') {
                window.chatbotGoalThread(parseInt(boton.getAttribute('data-goalid'), 10) || 0,
                    boton.getAttribute('data-goallabel') || '', consulta);
            } else if (typeof window.chatbotAsk === 'function') {
                window.chatbotAsk(consulta, 'coach');
            } else if (typeof window.chatbotOpen === 'function') {
                window.chatbotOpen();
            }
        });
    });
<?php if (\local_chatbot\ux::on('draftsave')): ?>

    // Borrador de evidencia guardado en este navegador mientras se escribe: recargar la pagina
    // o cerrarla por error ya no borra 20 minutos de trabajo. Se borra al entregar.
    var guardar = function(clave, valor) {
        try {
            if (valor.trim() === '') { window.localStorage.removeItem(clave); } else { window.localStorage.setItem(clave, valor); }
        } catch (e) { /* Sin almacenamiento local: se sigue sin guardar. */ }
    };
    document.querySelectorAll('textarea[name="evidence"]').forEach(function(cuadro) {
        var formulario = cuadro.closest('form');
        var apoyo = formulario ? formulario.querySelector('input[name="adapt"]') : null;
        if (!apoyo) { return; }
        var clave = 'chatbot-borrador-<?php echo (int) $USER->id; ?>-' + apoyo.value;
        var previo = null;
        try { previo = window.localStorage.getItem(clave); } catch (e) { previo = null; }
        if (previo && cuadro.value.trim() === '') {
            cuadro.value = previo;
            var aviso = document.createElement('div');
            aviso.className = 'small text-success mb-2';
            aviso.textContent = <?php echo json_encode(get_string('draft_restored', 'local_chatbot')); ?>;
            cuadro.parentNode.insertBefore(aviso, cuadro);
        }
        var temporizador = null;
        cuadro.addEventListener('input', function() {
            clearTimeout(temporizador);
            temporizador = setTimeout(function() { guardar(clave, cuadro.value); }, 400);
        });
        formulario.addEventListener('submit', function() {
            formulario.dataset.enviando = '1';
            guardar(clave, '');
        });
    });
<?php endif; ?>
});
</script>

<?php
echo $OUTPUT->footer();
