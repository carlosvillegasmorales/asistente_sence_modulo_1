<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Vista y acciones de un material en produccion, compartidas por el Centro de produccion del Modulo 2
 * (centro_recursos.php, Etapas 10 y 11) y la produccion de los materiales de la experiencia de continuidad del Modulo 3
 * (produccion_m3.php, Etapas P2 y P3): materiales de referencia, propuesta o estructura, contenido con sus descargas,
 * marcas [REVISAR] y fragmentos web con «Aceptar» y «Editar», curaduria (Modo C), ajustes por seccion, edicion manual y
 * aprobacion con registro versionado.
 */
class material_ui {

    /**
     * Atiende las acciones POST de un material. Redirige siempre que reconoce la accion; si no, no hace nada.
     *
     * @param string $action
     * @param int $recursoid
     * @param int $userid
     * @param int $courseid
     * @param int $fichaRefs Clave donde se guardan los materiales de referencia nuevos (diseño o −id de la implementacion).
     * @param \moodle_url $recursourl
     * @param \moodle_url $planurl
     * @param string $cierreAprobacion Mensaje al aprobar (cierre de la Etapa 11 o de la P3).
     * @param int[] $refsBorrables Ids de referencias que se pueden quitar desde esta pagina (null = todas las propias).
     */
    public static function handle_post(string $action, int $recursoid, int $userid, int $courseid, int $fichaRefs,
            \moodle_url $recursourl, \moodle_url $planurl, string $cierreAprobacion, ?array $refsBorrables = null): void {
        if ($action === 'subir_ref') {
            $secciones = optional_param('ref_secciones', '', PARAM_TEXT);
            if (!empty($_FILES['ref_archivo']['tmp_name']) && is_uploaded_file($_FILES['ref_archivo']['tmp_name'])) {
                $tmp = $_FILES['ref_archivo']['tmp_name'];
                $nombre = (string) ($_FILES['ref_archivo']['name'] ?? 'archivo');
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = (string) $finfo->file($tmp);
                $idRef = centro_recursos::add_reference($fichaRefs, $userid, $courseid, $tmp, $nombre, $mime, $secciones);
                if ($idRef === null) {
                    redirect($recursourl, get_string('centro_ref_invalid', 'local_chatbot'), null,
                        \core\output\notification::NOTIFY_ERROR);
                }
                redirect($recursourl, get_string('centro_ref_added', 'local_chatbot'), null,
                    \core\output\notification::NOTIFY_SUCCESS);
            }
            redirect($recursourl);
        }
        if ($action === 'borrar_ref') {
            $refid = required_param('refid', PARAM_INT);
            if ($refsBorrables === null || in_array($refid, $refsBorrables, true)) {
                centro_recursos::delete_reference($refid, $userid);
            }
            redirect($recursourl, get_string('centro_ref_deleted', 'local_chatbot'), null,
                \core\output\notification::NOTIFY_INFO);
        }
        if ($recursoid <= 0) {
            return;
        }
        if ($action === 'proponer_estructura') {
            $refIds = optional_param_array('ref_ids', [], PARAM_INT);
            $ajusteEst = optional_param('ajuste_estructura', '', PARAM_TEXT);
            $platQuiz = optional_param('plataforma_quiz', '', PARAM_ALPHA);
            centro_recursos::request_resource($recursoid, $userid, centro_recursos::GEN_ESTRUCTURA, $ajusteEst, $refIds,
                $platQuiz);
            redirect($recursourl);
        }
        if ($action === 'producir_contenido') {
            $refIds = optional_param_array('ref_ids', [], PARAM_INT);
            $platQuiz = optional_param('plataforma_quiz', '', PARAM_ALPHA);
            centro_recursos::request_resource($recursoid, $userid, centro_recursos::GEN_CONTENIDO, '', $refIds, $platQuiz);
            redirect($recursourl);
        }
        if ($action === 'ajustar_material') {
            $ajuste = trim(optional_param('ajuste_material', '', PARAM_TEXT));
            if ($ajuste !== '') {
                centro_recursos::request_resource($recursoid, $userid, centro_recursos::GEN_AJUSTE, $ajuste);
            }
            redirect($recursourl);
        }
        if ($action === 'resolver_marca') {
            $hecho = centro_recursos::save_resolved_mark($recursoid, $userid, required_param('indice', PARAM_INT),
                optional_param('reemplazo', '', PARAM_TEXT));
            redirect(new \moodle_url($recursourl, [], 'cr-marcas'), get_string($hecho ? 'centro_mark_resolved'
                : 'centro_mark_missing', 'local_chatbot'), null, $hecho ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_WARNING);
        }
        if ($action === 'web_bloque') {
            // Fragmento obtenido de la web: «Aceptar» lo deja tal cual; «Editar» cambia solo ese fragmento.
            $editar = optional_param('modo', 'aceptar', PARAM_ALPHA) === 'editar';
            $reemplazo = $editar ? trim(optional_param('reemplazo', '', PARAM_TEXT)) : null;
            $hecho = !($editar && $reemplazo === '') && centro_recursos::save_web_block($recursoid, $userid,
                required_param('bloque', PARAM_INT), $reemplazo);
            redirect(new \moodle_url($recursourl, [], 'cr-web'), get_string($hecho ? 'centro_web_done' : 'centro_web_missing',
                'local_chatbot'), null, $hecho ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_WARNING);
        }
        if ($action === 'guardar_edicion') {
            centro_recursos::save_manual_edit($recursoid, $userid, optional_param('contenido_edit', '', PARAM_RAW));
            redirect($recursourl, get_string('centro_edit_saved', 'local_chatbot'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'cur_aceptar' || $action === 'cur_quitar') {
            // Modo C: «Aceptar» agrega la opcion a los recursos que se usaran; «Quitar» la saca.
            $recSel = centro_recursos::get_resource($recursoid, $userid);
            $selActual = $recSel ? array_map('intval', (array) (json_decode((string) ($recSel->seleccionados ?? '[]'), true)
                ?: [])) : [];
            $op = required_param('opcion', PARAM_INT);
            $selNueva = $action === 'cur_aceptar' ? array_merge($selActual, [$op]) : array_diff($selActual, [$op]);
            sort($selNueva);
            centro_recursos::select_curated($recursoid, $userid, array_values(array_unique($selNueva)));
            redirect(new \moodle_url($recursourl, [], 'cr-cur'), get_string('centro_cur_saved', 'local_chatbot'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'cur_editar') {
            $hecho = centro_recursos::save_curated_option($recursoid, $userid, required_param('opcion', PARAM_INT), [
                'momento' => optional_param('momento', '', PARAM_TEXT),
                'justificacion' => optional_param('justificacion', '', PARAM_TEXT),
                'advertencias' => optional_param('advertencias', '', PARAM_TEXT),
            ]);
            redirect(new \moodle_url($recursourl, [], 'cr-cur'), get_string($hecho ? 'centro_cur_edited' : 'centro_web_missing',
                'local_chatbot'), null, $hecho ? \core\output\notification::NOTIFY_SUCCESS
                : \core\output\notification::NOTIFY_WARNING);
        }
        if ($action === 'seleccionar_curaduria') {
            centro_recursos::select_curated($recursoid, $userid, optional_param_array('cur_sel', [], PARAM_INT));
            redirect($recursourl, get_string('centro_cur_saved', 'local_chatbot'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($action === 'aprobar_material') {
            centro_recursos::approve_material($recursoid, $userid, optional_param('parcial', 0, PARAM_INT) === 1);
            redirect($planurl, $cierreAprobacion, null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }

    /**
     * Vista de un material (sin la cabecera de la pagina).
     *
     * @param array $v Claves: rec (fila), refs (filas de referencias disponibles), refs_borrables (ids que se pueden quitar),
     *        recursourl, planurl, baseurl (\moodle_url), descarga (callable fn(string $fmt): \moodle_url), estados (estado =>
     *        [etiqueta, clase]), txt (referencias, rechazo, revision, titulo_propuesta, proponer, aprobar_propuesta,
     *        ajustar_propuesta, volver), cabecera_extra (HTML bajo el titulo), solo_lectura (bool: material disponible en el
     *        repertorio del diseño, sin produccion).
     * @return string HTML.
     */
    public static function render(array $v): string {
        $recActual = $v['rec'];
        $refs = (array) ($v['refs'] ?? []);
        $borrables = $v['refs_borrables'] ?? null;
        $recursourl = $v['recursourl'];
        $planurl = $v['planurl'];
        $baseurl = $v['baseurl'];
        $txt = (array) $v['txt'];
        $estadoEtiqueta = (array) $v['estados'];
        $soloLectura = !empty($v['solo_lectura']);
        $enCurso = in_array($recActual->generacion, [exante::PENDIENTE, exante::PROCESANDO], true);
        $refUsadas = (array) json_decode((string) ($recActual->refs_usadas ?? '[]'), true);
        $esQuiz = centro_recursos::is_quiz_material((string) $recActual->nombre, (string) $recActual->formato);
        $esModoC = strtoupper(trim((string) $recActual->modo)) === 'C';
        $formatosDescarga = centro_recursos::detect_formats((string) $recActual->modo, (string) $recActual->formato,
            (string) $recActual->nombre, (string) ($recActual->plataforma_quiz ?? ''));
        [$etTxt, $etCls] = $estadoEtiqueta[$recActual->estado] ?? [$recActual->estado, 'bg-secondary'];
        $sk = sesskey();
        ob_start();
        ?>
        <div class="mb-3">
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo $planurl->out(false); ?>">&larr; <?php echo s($txt['volver']); ?></a>
        </div>

        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h3 class="h5 mb-1"><?php echo s($recActual->codigo . ' · ' . $recActual->nombre); ?></h3>
                    <div class="small text-muted">
                        <strong><?php echo get_string('centro_lbl_fase', 'local_chatbot'); ?></strong> <?php echo s($recActual->fase ?: get_string('centro_lbl_segun', 'local_chatbot')); ?> ·
                        <strong><?php echo get_string('centro_lbl_modo', 'local_chatbot'); ?></strong> <?php echo s($recActual->modo); ?> ·
                        <strong><?php echo get_string('centro_lbl_formato', 'local_chatbot'); ?></strong> <?php echo s($recActual->formato); ?>
                        <?php if ((int) $recActual->version > 0): ?> · <strong><?php echo get_string('centro_lbl_version', 'local_chatbot'); ?></strong> v<?php echo (int) $recActual->version; ?><?php endif; ?>
                    </div>
                    <?php echo (string) ($v['cabecera_extra'] ?? ''); ?>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge <?php echo $etCls; ?>"><?php echo s($etTxt); ?></span>
                    <?php if ((int) $recActual->revisar_count > 0): ?>
                        <span class="badge bg-warning text-dark"><?php echo get_string('centro_marks', 'local_chatbot', (int) $recActual->revisar_count); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($enCurso): ?>
            <div id="centro-generando" class="alert alert-info d-flex align-items-center gap-3"
                 data-id="<?php echo (int) $recActual->id; ?>"
                 data-estado="<?php echo s($recActual->generacion); ?>"
                 data-tipo="rec"
                 data-sesskey="<?php echo $sk; ?>"
                 data-url="<?php echo $baseurl->out(false); ?>"
                 data-volver="<?php echo $recursourl->out(false); ?>">
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                <div><?php echo get_string('centro_producing', 'local_chatbot'); ?></div>
            </div>
        <?php else: ?>
            <?php if ($recActual->error === 'no_modifica'): ?>
                <div class="alert alert-warning"><?php echo s($txt['rechazo']); ?></div>
            <?php elseif ($recActual->generacion === exante::ERROR && $recActual->error === 'seccion'): ?>
                <div class="alert alert-warning"><?php echo get_string('centro_section_error', 'local_chatbot'); ?></div>
            <?php elseif ($recActual->generacion === exante::ERROR): ?>
                <div class="alert alert-danger"><?php echo get_string('centro_gen_error', 'local_chatbot'); ?></div>
            <?php endif; ?>

            <?php if (!$soloLectura): ?>
            <!-- MATERIALES DE REFERENCIA -->
            <div class="card mb-3">
                <div class="card-header bg-light"><strong><?php echo get_string('centro_ref_title', 'local_chatbot'); ?></strong></div>
                <div class="card-body">
                    <p class="mb-1"><?php echo s($txt['referencias']); ?></p>
                    <p class="small text-muted mb-2"><?php echo get_string('centro_ref_help', 'local_chatbot'); ?></p>
                    <form method="post" action="<?php echo $recursourl->out(false); ?>" enctype="multipart/form-data" class="row g-2 align-items-end mb-3">
                        <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
                        <input type="hidden" name="action" value="subir_ref">
                        <div class="col-md-5">
                            <label class="form-label small mb-1" for="ref_archivo"><?php echo get_string('centro_ref_file', 'local_chatbot'); ?></label>
                            <input type="file" class="form-control form-control-sm" id="ref_archivo" name="ref_archivo" accept=".pdf,.docx,.xlsx,.xls,.png,.jpg,.jpeg,.webp" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small mb-1" for="ref_secciones"><?php echo get_string('centro_ref_sections', 'local_chatbot'); ?></label>
                            <input type="text" class="form-control form-control-sm" id="ref_secciones" name="ref_secciones" placeholder="<?php echo get_string('centro_ref_sections_ph', 'local_chatbot'); ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-outline-primary btn-sm w-100"><?php echo get_string('centro_upload', 'local_chatbot'); ?></button>
                        </div>
                    </form>
                    <?php if ($refs): ?>
                        <div class="small">
                            <strong><?php echo get_string('centro_refs_available', 'local_chatbot'); ?></strong>
                            <ul class="list-unstyled mt-1 mb-0">
                                <?php foreach ($refs as $rf): ?>
                                    <li class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                        <span><?php echo s($rf->filename); ?> <small class="text-muted">(<?php echo round($rf->sizebytes / 1024); ?> KB<?php
                                            echo $rf->secciones ? ' · ' . s($rf->secciones) : ''; ?><?php
                                            echo !empty($rf->etiqueta) ? ' · ' . s($rf->etiqueta) : ''; ?>)</small></span>
                                        <?php if ($borrables === null || in_array((int) $rf->id, $borrables, true)): ?>
                                        <form method="post" action="<?php echo $recursourl->out(false); ?>" class="d-inline">
                                            <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
                                            <input type="hidden" name="action" value="borrar_ref">
                                            <input type="hidden" name="refid" value="<?php echo (int) $rf->id; ?>">
                                            <button type="submit" class="btn btn-link btn-sm text-danger p-0"><?php echo get_string('centro_remove', 'local_chatbot'); ?></button>
                                        </form>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- PROPUESTA O ESTRUCTURA -->
            <div class="card mb-3">
                <div class="card-header bg-light"><strong><?php echo s($txt['titulo_propuesta']); ?></strong></div>
                <div class="card-body">
                    <?php if (trim((string) $recActual->estructura) === ''): ?>
                        <form method="post" action="<?php echo $recursourl->out(false); ?>">
                            <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
                            <input type="hidden" name="action" value="proponer_estructura">
                            <?php if ($refs): ?>
                                <div class="mb-2 small">
                                    <div class="fw-bold mb-1"><?php echo get_string('centro_refs_pick', 'local_chatbot'); ?></div>
                                    <?php foreach ($refs as $rf): ?>
                                        <label class="me-3"><input type="checkbox" name="ref_ids[]" value="<?php echo (int) $rf->id; ?>"
                                            <?php echo in_array((int) $rf->id, $refUsadas, true) ? 'checked' : ''; ?>> <?php echo s($rf->filename); ?></label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($esQuiz): ?>
                                <div class="mb-3" style="max-width:340px;">
                                    <label class="form-label small fw-bold" for="plataforma_quiz"><?php echo get_string('centro_quiz_where', 'local_chatbot'); ?></label>
                                    <select class="form-select form-select-sm" id="plataforma_quiz" name="plataforma_quiz">
                                        <option value="todas"><?php echo get_string('centro_quiz_all', 'local_chatbot'); ?></option>
                                        <option value="moodle" <?php echo $recActual->plataforma_quiz === 'moodle' ? 'selected' : ''; ?>><?php echo get_string('centro_quiz_moodle', 'local_chatbot'); ?></option>
                                        <option value="google" <?php echo $recActual->plataforma_quiz === 'google' ? 'selected' : ''; ?>><?php echo get_string('centro_quiz_google', 'local_chatbot'); ?></option>
                                        <option value="impreso" <?php echo $recActual->plataforma_quiz === 'impreso' ? 'selected' : ''; ?>><?php echo get_string('centro_quiz_print', 'local_chatbot'); ?></option>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary" data-espera="<?php echo get_string('centro_proposing', 'local_chatbot'); ?>"><?php echo s($txt['proponer']); ?></button>
                        </form>
                    <?php else: ?>
                        <div class="border rounded p-3 bg-light mb-3"><?php echo centro_recursos::highlight_review_marks((string) $recActual->estructura); ?></div>
                        <div class="d-flex flex-wrap gap-2 align-items-start">
                            <form method="post" action="<?php echo $recursourl->out(false); ?>" class="d-inline">
                                <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
                                <input type="hidden" name="action" value="producir_contenido">
                                <?php foreach ($refUsadas as $rid): ?>
                                    <input type="hidden" name="ref_ids[]" value="<?php echo (int) $rid; ?>">
                                <?php endforeach; ?>
                                <button type="submit" class="btn btn-success" data-espera="<?php echo get_string('centro_producing_full', 'local_chatbot'); ?>">
                                    <?php echo trim((string) $recActual->contenido) === '' ? s($txt['aprobar_propuesta']) : s($txt['regenerar'] ?? get_string('centro_regenerate', 'local_chatbot')); ?>
                                </button>
                            </form>
                            <details class="flex-grow-1">
                                <summary class="btn btn-outline-secondary btn-sm"><?php echo s($txt['ajustar_propuesta']); ?></summary>
                                <form method="post" action="<?php echo $recursourl->out(false); ?>" class="mt-2">
                                    <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
                                    <input type="hidden" name="action" value="proponer_estructura">
                                    <?php if ($refs): ?>
                                        <div class="mb-2 small">
                                            <?php foreach ($refs as $rf): ?>
                                                <label class="me-3"><input type="checkbox" name="ref_ids[]" value="<?php echo (int) $rf->id; ?>"
                                                    <?php echo in_array((int) $rf->id, $refUsadas, true) ? 'checked' : ''; ?>> <?php echo s($rf->filename); ?></label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <textarea class="form-control form-control-sm mb-2" name="ajuste_estructura" rows="2" placeholder="<?php echo get_string('centro_adjust_structure_ph', 'local_chatbot'); ?>"></textarea>
                                    <button type="submit" class="btn btn-outline-primary btn-sm" data-espera="<?php echo get_string('centro_adjusting_structure', 'local_chatbot'); ?>"><?php echo get_string('centro_apply_structure', 'local_chatbot'); ?></button>
                                </form>
                            </details>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- CONTENIDO, REVISION, ENTREGA Y DESCARGAS -->
            <?php if (trim((string) $recActual->contenido) !== ''): ?>
                <div class="card mb-3">
                    <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <strong><?php echo s($txt['titulo_material'] ?? get_string('centro_material_title', 'local_chatbot')); ?></strong>
                        <div class="d-flex flex-wrap gap-1">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="cr-copy-txt"><?php echo get_string('centro_copy_text', 'local_chatbot'); ?></button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="cr-copy-html"><?php echo get_string('centro_copy_html', 'local_chatbot'); ?></button>
                            <?php
                            $etiquetasDesc = [
                                'docx' => 'Word (.docx)',
                                'quiz_docx' => get_string('centro_dl_quiz', 'local_chatbot'),
                                'xlsx' => 'Excel (.xlsx)',
                                'pptx' => get_string('centro_dl_pptx', 'local_chatbot'),
                                'pdf' => 'PDF (' . s($recActual->formato) . ')',
                                'gift' => 'Moodle GIFT (.txt UTF-8)',
                                'gs' => 'Google Forms (.gs)',
                                'html' => 'HTML (.html)',
                            ];
                            foreach ($formatosDescarga as $fmt):
                                if ($fmt === 'curaduria' || !isset($etiquetasDesc[$fmt])) {
                                    continue;
                                }
                            ?>
                                <a class="btn btn-outline-primary btn-sm" href="<?php echo $v['descarga']($fmt)->out(false); ?>">
                                    <?php echo $etiquetasDesc[$fmt]; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php
                        // Marcas [REVISAR]: se aceptan o corrigen una por una en todo material (texto o estructurado).
                        $marcascr = $soloLectura ? [] : (empty($recActual->estructurado)
                            ? centro_recursos::extract_review_marks((string) $recActual->contenido)
                            : ($esModoC ? [] : centro_recursos::json_review_marks((string) $recActual->estructurado)));
                        ?>
                        <?php if ((int) $recActual->revisar_count > 0 || $marcascr): ?>
                            <div class="alert alert-warning small py-2" id="cr-marcas">
                                <?php echo get_string('centro_marks_warning', 'local_chatbot', max((int) $recActual->revisar_count, count($marcascr))); ?>
                                <?php if ($marcascr): ?>
                                    <ol class="mt-2 mb-0 ps-3">
                                    <?php foreach ($marcascr as $im => $marca): ?>
                                        <li class="mb-2">
                                            <span class="fw-bold"><?php echo s($marca); ?></span>
                                            <div class="d-flex flex-wrap gap-2 align-items-start mt-1">
                                                <form method="post" action="<?php echo $recursourl->out(false); ?>" class="d-inline">
                                                    <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
                                                    <input type="hidden" name="action" value="resolver_marca">
                                                    <input type="hidden" name="indice" value="<?php echo (int) $im; ?>">
                                                    <button type="submit" class="btn btn-success btn-sm"><i class="fa fa-check me-1"></i><?php
                                                        echo get_string('centro_mark_ok', 'local_chatbot'); ?></button>
                                                </form>
                                                <details>
                                                    <summary class="btn btn-outline-secondary btn-sm"><i class="fa fa-pencil me-1"></i><?php
                                                        echo get_string('centro_mark_fix', 'local_chatbot'); ?></summary>
                                                    <form method="post" action="<?php echo $recursourl->out(false); ?>" class="d-flex flex-wrap gap-2 mt-2">
                                                        <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
                                                        <input type="hidden" name="action" value="resolver_marca">
                                                        <input type="hidden" name="indice" value="<?php echo (int) $im; ?>">
                                                        <input type="text" name="reemplazo" required class="form-control form-control-sm" style="min-width: 320px;"
                                                               placeholder="<?php echo s(get_string('centro_mark_fix_ph', 'local_chatbot')); ?>">
                                                        <button type="submit" class="btn btn-primary btn-sm"><?php
                                                            echo get_string('centro_mark_replace', 'local_chatbot'); ?></button>
                                                    </form>
                                                </details>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                    </ol>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php $bloquesweb = $soloLectura || !empty($v['sin_web']) || $esModoC ? []
                            : (empty($recActual->estructurado) ? centro_recursos::web_blocks((string) $recActual->contenido)
                                : centro_recursos::web_blocks_json((array) json_decode((string) $recActual->estructurado, true))); ?>
                        <?php if ($bloquesweb): ?>
                            <div class="alert alert-info small py-2" id="cr-web">
                                <div class="fw-bold"><i class="fa fa-globe me-1"></i><?php
                                    echo get_string('centro_web_title', 'local_chatbot', count($bloquesweb)); ?></div>
                                <div class="mb-2"><?php echo get_string('centro_web_help', 'local_chatbot'); ?></div>
                                <ol class="mb-0 ps-3">
                                <?php foreach ($bloquesweb as $nb => $bw): ?>
                                    <li class="mb-3" value="<?php echo (int) $nb; ?>">
                                        <div class="border rounded bg-white p-2 mb-1"><?php echo s($bw['texto']); ?></div>
                                        <div class="mb-1"><span class="text-muted"><?php echo get_string('centro_web_source', 'local_chatbot'); ?></span>
                                            <?php if ($bw['url'] !== ''): ?>
                                                <a href="<?php echo s($bw['url']); ?>" target="_blank" rel="noopener"><?php
                                                    echo s($bw['titulo'] !== '' ? $bw['titulo'] : $bw['url']); ?></a>
                                            <?php else: ?>
                                                <?php echo get_string('centro_web_nolink', 'local_chatbot'); ?>
                                            <?php endif; ?>
                                            <?php if ($bw['origen'] === 'chile'): ?>
                                                <span class="badge bg-success"><?php echo get_string('centro_web_chile', 'local_chatbot'); ?></span>
                                            <?php elseif ($bw['origen'] === 'extranjera'): ?>
                                                <span class="badge bg-warning text-dark"><?php echo get_string('centro_web_foreign', 'local_chatbot'); ?></span>
                                            <?php elseif ($bw['origen'] === 'plataforma'): ?>
                                                <span class="badge bg-secondary"><?php echo get_string('centro_web_platform', 'local_chatbot'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex flex-wrap gap-2 align-items-start">
                                            <form method="post" action="<?php echo $recursourl->out(false); ?>" class="d-inline">
                                                <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
                                                <input type="hidden" name="action" value="web_bloque">
                                                <input type="hidden" name="bloque" value="<?php echo (int) $nb; ?>">
                                                <input type="hidden" name="modo" value="aceptar">
                                                <button type="submit" class="btn btn-success btn-sm"><i class="fa fa-check me-1"></i><?php
                                                    echo get_string('centro_web_accept', 'local_chatbot'); ?></button>
                                            </form>
                                            <details class="flex-grow-1">
                                                <summary class="btn btn-outline-secondary btn-sm"><i class="fa fa-pencil me-1"></i><?php
                                                    echo get_string('centro_web_edit', 'local_chatbot'); ?></summary>
                                                <form method="post" action="<?php echo $recursourl->out(false); ?>" class="mt-2">
                                                    <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
                                                    <input type="hidden" name="action" value="web_bloque">
                                                    <input type="hidden" name="bloque" value="<?php echo (int) $nb; ?>">
                                                    <input type="hidden" name="modo" value="editar">
                                                    <label class="form-label small text-muted mb-1"><?php
                                                        echo get_string('centro_web_edit_help', 'local_chatbot'); ?></label>
                                                    <textarea name="reemplazo" required rows="3" class="form-control form-control-sm mb-2"><?php
                                                        echo s($bw['texto']); ?></textarea>
                                                    <button type="submit" class="btn btn-primary btn-sm"><?php
                                                        echo get_string('centro_web_save', 'local_chatbot'); ?></button>
                                                </form>
                                            </details>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                                </ol>
                            </div>
                        <?php endif; ?>

                        <div id="cr-material-vista" class="mb-3">
                            <?php
                            $htmlModerno = recurso_export::to_moodle_html(diagrama::list_flows((string) $recActual->contenido),
                                (string) $recActual->nombre, (string) $recActual->codigo);
                            echo $bloquesweb ? centro_recursos::render_web_marks($htmlModerno) : centro_recursos::strip_web_marks($htmlModerno);
                            ?>
                        </div>
                        <textarea id="cr-html-fuente" class="d-none"><?php echo s(centro_recursos::strip_web_marks($htmlModerno)); ?></textarea>

                        <?php if ($esModoC && !empty($recActual->estructurado) && !$soloLectura): ?>
                            <?php
                            $cur = json_decode((string) $recActual->estructurado, true);
                            $selCur = json_decode((string) ($recActual->seleccionados ?? '[]'), true) ?: [];
                            ?>
                            <?php if (!empty($cur['opciones'])): ?>
                                <div class="border rounded p-3 bg-light mb-3 small" id="cr-cur">
                                    <div class="fw-bold mb-2"><i class="fa fa-globe me-1"></i><?php echo get_string('centro_cur_pick', 'local_chatbot'); ?></div>
                                    <ol class="mb-0 ps-3">
                                    <?php foreach ($cur['opciones'] as $ci => $op): ?>
                                        <?php $origenCur = centro_recursos::source_origin((string) ($op['enlace'] ?? ''),
                                            (string) ($op['fuente'] ?? '') . ' ' . (string) ($op['titulo'] ?? '')); ?>
                                        <li class="mb-3">
                                            <strong><?php echo s($op['titulo']); ?></strong>
                                            (<?php echo s($op['fuente'] . ' · ' . $op['duracion']); ?>)
                                            <?php if (in_array($ci, $selCur, true)): ?>
                                                <span class="badge bg-success"><?php echo get_string('centro_cur_accepted', 'local_chatbot'); ?></span>
                                            <?php endif; ?>
                                            <?php if ($origenCur === 'chile'): ?>
                                                <span class="badge bg-success-subtle text-success-emphasis"><?php echo get_string('centro_web_chile', 'local_chatbot'); ?></span>
                                            <?php elseif ($origenCur === 'extranjera'): ?>
                                                <span class="badge bg-warning text-dark"><?php echo get_string('centro_web_foreign', 'local_chatbot'); ?></span>
                                            <?php elseif ($origenCur === 'plataforma'): ?>
                                                <span class="badge bg-secondary"><?php echo get_string('centro_web_platform', 'local_chatbot'); ?></span>
                                            <?php endif; ?>
                                            <div class="d-flex flex-wrap gap-2 align-items-start mt-1">
                                                <a class="btn btn-outline-primary btn-sm" href="<?php echo s((string) $op['enlace']); ?>" target="_blank" rel="noopener"><i class="fa fa-external-link me-1"></i><?php
                                                    echo get_string('centro_cur_open', 'local_chatbot'); ?></a>
                                                <form method="post" action="<?php echo $recursourl->out(false); ?>" class="d-inline">
                                                    <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
                                                    <input type="hidden" name="opcion" value="<?php echo (int) $ci; ?>">
                                                    <?php if (in_array($ci, $selCur, true)): ?>
                                                        <input type="hidden" name="action" value="cur_quitar">
                                                        <button type="submit" class="btn btn-outline-secondary btn-sm"><?php
                                                            echo get_string('centro_cur_remove', 'local_chatbot'); ?></button>
                                                    <?php else: ?>
                                                        <input type="hidden" name="action" value="cur_aceptar">
                                                        <button type="submit" class="btn btn-success btn-sm"><i class="fa fa-check me-1"></i><?php
                                                            echo get_string('centro_web_accept', 'local_chatbot'); ?></button>
                                                    <?php endif; ?>
                                                </form>
                                                <details class="flex-grow-1">
                                                    <summary class="btn btn-outline-secondary btn-sm"><i class="fa fa-pencil me-1"></i><?php
                                                        echo get_string('centro_web_edit', 'local_chatbot'); ?></summary>
                                                    <form method="post" action="<?php echo $recursourl->out(false); ?>" class="mt-2">
                                                        <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
                                                        <input type="hidden" name="action" value="cur_editar">
                                                        <input type="hidden" name="opcion" value="<?php echo (int) $ci; ?>">
                                                        <div class="text-muted mb-1"><?php echo get_string('centro_cur_edit_help', 'local_chatbot'); ?></div>
                                                        <?php foreach (['momento' => 'centro_cur_moment', 'justificacion' => 'centro_cur_reason',
                                                                'advertencias' => 'centro_cur_warnings'] as $campoCur => $etqCur): ?>
                                                            <label class="form-label mb-0"><?php echo get_string($etqCur, 'local_chatbot'); ?></label>
                                                            <textarea name="<?php echo $campoCur; ?>" rows="2" class="form-control form-control-sm mb-2"><?php
                                                                echo s((string) ($op[$campoCur] ?? '')); ?></textarea>
                                                        <?php endforeach; ?>
                                                        <button type="submit" class="btn btn-primary btn-sm"><?php
                                                            echo get_string('centro_web_save', 'local_chatbot'); ?></button>
                                                    </form>
                                                </details>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                    </ol>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (!$soloLectura): ?>
                        <!-- AJUSTE POR SECCION O EDICION MANUAL -->
                        <p class="fw-bold mb-2"><?php echo s($txt['revision']); ?></p>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <form method="post" action="<?php echo $recursourl->out(false); ?>" class="border rounded p-3 h-100">
                                    <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
                                    <input type="hidden" name="action" value="ajustar_material">
                                    <label class="form-label fw-bold small" for="ajuste_material"><?php echo s($txt['ajuste_titulo'] ?? get_string('centro_adjust_title', 'local_chatbot')); ?></label>
                                    <textarea class="form-control form-control-sm mb-2" id="ajuste_material" name="ajuste_material" rows="3"
                                              placeholder="<?php echo s($txt['ajuste_ph'] ?? get_string('centro_adjust_ph', 'local_chatbot')); ?>"></textarea>
                                    <button type="submit" class="btn btn-outline-primary btn-sm" data-espera="<?php echo get_string('centro_adjusting', 'local_chatbot'); ?>"><?php echo get_string('centro_adjust_btn', 'local_chatbot'); ?></button>
                                </form>
                            </div>
                            <div class="col-md-6">
                                <?php if ($bloquesweb): ?>
                                <div class="border rounded p-3 h-100 small text-muted">
                                    <div class="fw-bold text-body mb-1"><?php echo get_string('centro_edit_title', 'local_chatbot'); ?></div>
                                    <i class="fa fa-lock me-1"></i><?php echo get_string('centro_web_edit_locked', 'local_chatbot', count($bloquesweb)); ?>
                                </div>
                                <?php else: ?>
                                <details class="border rounded p-3 h-100">
                                    <summary class="fw-bold small" style="cursor:pointer;"><?php echo get_string('centro_edit_title', 'local_chatbot'); ?></summary>
                                    <form method="post" action="<?php echo $recursourl->out(false); ?>" class="mt-2">
                                        <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
                                        <input type="hidden" name="action" value="guardar_edicion">
                                        <textarea class="form-control form-control-sm mb-2 font-monospace" name="contenido_edit" rows="6"><?php echo s((string) $recActual->contenido); ?></textarea>
                                        <button type="submit" class="btn btn-outline-secondary btn-sm"><?php echo get_string('centro_edit_save', 'local_chatbot'); ?></button>
                                    </form>
                                </details>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- APROBACION Y REGISTRO -->
                        <form method="post" action="<?php echo $recursourl->out(false); ?>" class="d-flex flex-wrap gap-2 align-items-center border-top pt-3">
                            <input type="hidden" name="sesskey" value="<?php echo $sk; ?>">
                            <input type="hidden" name="action" value="aprobar_material">
                            <?php if (centro_recursos::can_approve((string) $recActual->estado)): ?>
                                <button type="submit" class="btn btn-success">
                                    <?php echo get_string('centro_approve', 'local_chatbot', ((int) $recActual->version) + 1); ?>
                                </button>
                            <?php else: ?>
                                <div class="alert alert-success mb-0 w-100" role="status"><?php echo isset($txt['entregado_nota']) ? str_replace('{$a}', (string) (int) $recActual->version,
                                    $txt['entregado_nota']) : get_string('centro_delivered_note', 'local_chatbot', (int) $recActual->version); ?></div>
                            <?php endif; ?>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Guion de la pagina: copiar texto y HTML del material y seguir una generacion en curso (plan o material).
     *
     * @return string HTML.
     */
    public static function script(): string {
        $copiado = json_encode(get_string('centro_copied', 'local_chatbot'));
        return <<<HTML
<script>
(function() {
    var copiar = function(btn, texto) {
        if (!navigator.clipboard) {
            return;
        }
        navigator.clipboard.writeText(texto).then(function() {
            var orig = btn.textContent;
            btn.textContent = {$copiado};
            window.setTimeout(function() { btn.textContent = orig; }, 1800);
        });
    };
    var bTxt = document.getElementById('cr-copy-txt');
    var vista = document.getElementById('cr-material-vista');
    if (bTxt && vista) {
        bTxt.addEventListener('click', function() { copiar(bTxt, vista.innerText || ''); });
    }
    var bHtml = document.getElementById('cr-copy-html');
    var fuente = document.getElementById('cr-html-fuente');
    if (bHtml && fuente) {
        bHtml.addEventListener('click', function() { copiar(bHtml, fuente.value || ''); });
    }

    var caja = document.getElementById('centro-generando');
    if (!caja) {
        return;
    }
    var id = caja.getAttribute('data-id');
    var tipo = caja.getAttribute('data-tipo');
    var url = caja.getAttribute('data-url');
    var actEjec = tipo === 'plan' ? 'ejecutar_plan' : 'ejecutar_rec';
    var actEst = tipo === 'plan' ? 'estado_plan' : 'estado_rec';
    var terminar = function(datos) {
        if (datos && (datos.estado === 'listo' || datos.estado === 'error')) {
            window.location.replace(caja.getAttribute('data-volver'));
            return true;
        }
        return false;
    };
    var revisar = function() {
        fetch(url + '&action=' + actEst + '&id=' + encodeURIComponent(id), {credentials: 'same-origin'})
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
        cuerpo.append('action', actEjec);
        cuerpo.append('id', id);
        cuerpo.append('sesskey', caja.getAttribute('data-sesskey'));
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
HTML;
    }
}
