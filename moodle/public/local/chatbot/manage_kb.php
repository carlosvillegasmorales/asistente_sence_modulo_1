<?php
/**
 * Knowledge base management: upload/list/delete the PDFs whose extracted
 * text becomes context for the tutor agent in every conversation.
 */

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/chatbot:managekb', $context);

$PAGE->set_url(new moodle_url('/local/chatbot/manage_kb.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('managekb_title', 'local_chatbot'));
$PAGE->set_heading(get_string('managekb_heading', 'local_chatbot'));

$message = '';
$message_type = 'info';

// --- Eliminar un documento ---
$delete_id = optional_param('delete', 0, PARAM_INT);
if ($delete_id > 0 && confirm_sesskey()) {
    if (\local_chatbot\kb_store::delete_document($delete_id)) {
        $message = get_string('managekb_deleted', 'local_chatbot');
        $message_type = 'success';
    }
}

// --- Subir un PDF nuevo ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file']) && confirm_sesskey()) {
    $file = $_FILES['pdf_file'];

    // Validate the actual file content server-side; $_FILES['...']['type'] is
    // supplied by the browser and trivially spoofable.
    $realmime = $file['error'] === UPLOAD_ERR_OK ? mime_content_type($file['tmp_name']) : false;

    if ($file['error'] === UPLOAD_ERR_OK && $realmime === 'application/pdf') {
        if (!\local_chatbot\ai\provider_factory::is_configured()) {
            $message = get_string('managekb_error_nokey', 'local_chatbot');
            $message_type = 'danger';
        } else {
            // La extraccion del texto no se hace aqui: se guarda el PDF, se deja
            // el documento en cola y responde de inmediato. Un anexo de cien
            // paginas le toma minutos al proveedor, y el servidor web cortaba la
            // conexion mucho antes; esperar dentro del request nunca iba a andar.
            $filename = format_string($file['name']);
            $docid = \local_chatbot\kb_store::queue_document($filename);

            $fs = get_file_storage();
            $fs->create_file_from_pathname([
                'contextid' => $context->id,
                'component' => 'local_chatbot',
                'filearea' => 'kb_document',
                'itemid' => $docid,
                'filepath' => '/',
                'filename' => 'documento.pdf',
            ], $file['tmp_name']);

            $task = new \local_chatbot\task\extract_kb_document();
            $task->set_custom_data(['docid' => $docid]);
            \core\task\manager::queue_adhoc_task($task);

            $message = get_string('managekb_queued', 'local_chatbot', $filename);
            $message_type = 'success';
        }
    } else {
        $message = get_string('managekb_error_invalidpdf', 'local_chatbot');
        $message_type = 'danger';
    }
}

$documents = \local_chatbot\kb_store::list_documents();
$jobs = \local_chatbot\kb_store::list_jobs();
$total_size_kb = round(\local_chatbot\kb_store::total_size() / 1024, 1);

// Documentos que tienen el PDF original guardado (subidos desde que existe
// esta funcionalidad); los migrados desde el flat-file legado no lo tienen.
$fs = get_file_storage();
$kb_files_by_docid = [];
foreach ($fs->get_area_files($context->id, 'local_chatbot', 'kb_document', false, 'itemid', false) as $storedfile) {
    if (!$storedfile->is_directory()) {
        $kb_files_by_docid[$storedfile->get_itemid()] = $storedfile;
    }
}
// Aviso suave si la base de conocimiento empieza a pesar mucho: todo su
// contenido se reenvía completo en CADA mensaje del tutor (no hay búsqueda
// selectiva por fragmento), así que un KB muy grande encarece y ralentiza
// cada respuesta, y eventualmente puede exceder la ventana de contexto del
// modelo. 300 KB de texto plano ya son varios cientos de páginas.
$size_warning = $total_size_kb > 300;

echo $OUTPUT->header();
?>

<div class="container-fluid mb-4 mt-3" style="max-width: 900px;">

    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="fa fa-graduation-cap text-primary me-2"></i> <?php echo get_string('managekb_heading', 'local_chatbot'); ?></h2>
            <div class="alert alert-info">
                <p class="mb-2"><strong><?php echo get_string('managekb_explain_title', 'local_chatbot'); ?></strong></p>
                <p class="mb-2"><?php echo get_string('managekb_explain_body', 'local_chatbot'); ?></p>
                <p class="mb-0"><strong><?php echo get_string('managekb_explain_scope_title', 'local_chatbot'); ?></strong>
                    <?php echo get_string('managekb_explain_scope_body', 'local_chatbot'); ?></p>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show shadow-sm">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($jobs)): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-secondary text-white">
                <h4 class="mb-0 h5"><i class="fa fa-hourglass-half me-2"></i>
                    <?php echo get_string('managekb_jobs_title', 'local_chatbot'); ?></h4>
            </div>
            <div class="card-body p-0">
                <table class="table table-vcenter mb-0">
                    <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <tr>
                            <td class="ps-4">
                                <strong><?php echo s($job->filename); ?></strong>
                                <div class="text-muted small">
                                    <?php echo userdate($job->timecreated, get_string('strftimedatetimeshort')); ?>
                                </div>
                            </td>
                            <td class="text-end pe-4">
                                <?php if ((int) $job->status === \local_chatbot\kb_store::STATUS_PENDING): ?>
                                    <span class="badge text-bg-secondary">
                                        <i class="fa fa-clock-o me-1"></i>
                                        <?php echo get_string('managekb_job_pending', 'local_chatbot'); ?>
                                    </span>
                                <?php else: ?>
                                    <?php $motivo = \local_chatbot\kb_store::describe_failure($job->statusmsg); ?>
                                    <span class="badge text-bg-warning">
                                        <i class="fa fa-exclamation-triangle me-1"></i>
                                        <?php echo get_string('managekb_job_failed', 'local_chatbot'); ?>
                                    </span>
                                    <div class="text-muted small mt-1 text-start">
                                        <?php echo get_string($motivo['clave'], 'local_chatbot', $motivo['a']); ?>
                                    </div>
                                <?php endif; ?>
                                <a href="<?php echo new moodle_url('/local/chatbot/manage_kb.php',
                                    ['delete' => $job->id, 'sesskey' => sesskey()]); ?>"
                                   class="btn btn-sm btn-outline-danger ms-2"
                                   data-confirm-delete="<?php echo s($job->filename); ?>"
                                   title="<?php echo get_string('managekb_delete', 'local_chatbot'); ?>">
                                    <i class="fa fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="p-3 border-top bg-light">
                    <small class="text-muted"><?php echo get_string('managekb_jobs_desc', 'local_chatbot'); ?></small>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Columna Izquierda: Formulario de Subida -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0 h5"><i class="fa fa-upload me-2"></i> <?php echo get_string('managekb_upload_title', 'local_chatbot'); ?></h4>
                </div>
                <div class="card-body">
                    <form action="manage_kb.php" method="POST" enctype="multipart/form-data" id="upload-form">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

                        <div class="mb-3 mt-3">
                            <label for="pdf_file" class="form-label fw-bold"><?php echo get_string('managekb_selectfile', 'local_chatbot'); ?></label>
                            <!-- Bootstrap 5: el input nativo ya muestra el nombre del archivo elegido. -->
                            <input type="file" class="form-control mt-2" id="pdf_file" name="pdf_file" accept=".pdf" required>
                        </div>

                        <div class="alert alert-warning mt-4 border-warning bg-light">
                            <div class="d-flex">
                                <div class="me-3 mt-1">
                                    <i class="fa fa-info-circle fa-2x text-warning"></i>
                                </div>
                                <div>
                                    <h6 class="alert-heading fw-bold mb-1"><?php echo get_string('managekb_notice_title', 'local_chatbot'); ?></h6>
                                    <small class="mb-0"><?php echo get_string('managekb_notice_body', 'local_chatbot'); ?></small>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100 mt-4" id="submit-btn">
                            <span id="btn-text"><i class="fa fa-cogs me-2"></i> <?php echo get_string('managekb_submit', 'local_chatbot'); ?></span>
                            <span id="btn-loader" style="display:none;">
                                <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                <?php echo get_string('managekb_processing', 'local_chatbot'); ?>
                            </span>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Columna Derecha: Documentos actualmente en la base de conocimiento -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <h4 class="mb-0 h5"><i class="fa fa-database me-2"></i> <?php echo get_string('managekb_current_title', 'local_chatbot'); ?></h4>
                    <span class="badge text-bg-light text-info" title="<?php echo get_string('managekb_totalsize_title', 'local_chatbot'); ?>"><?php echo $total_size_kb; ?> KB</span>
                </div>
                <div class="card-body p-0">
                    <div class="p-3 border-bottom bg-light">
                        <p class="text-muted mb-0"><small><?php echo get_string('managekb_current_desc', 'local_chatbot'); ?></small></p>
                    </div>

                    <?php if ($size_warning): ?>
                        <div class="alert alert-warning m-3 mb-0">
                            <i class="fa fa-exclamation-triangle me-2"></i>
                            <?php echo get_string('managekb_size_warning', 'local_chatbot'); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($documents)): ?>
                        <div class="p-5 text-center">
                            <i class="fa fa-inbox fa-4x mb-3 text-light"></i>
                            <h5 class="text-muted"><?php echo get_string('managekb_empty_title', 'local_chatbot'); ?></h5>
                            <p class="text-muted small"><?php echo get_string('managekb_empty_desc', 'local_chatbot'); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                            <table class="table table-hover table-vcenter mb-0">
                                <thead class="thead-light sticky-top">
                                    <tr>
                                        <th class="border-top-0 ps-4"><?php echo get_string('managekb_col_document', 'local_chatbot'); ?></th>
                                        <th class="border-top-0"><?php echo get_string('managekb_col_date', 'local_chatbot'); ?></th>
                                        <th class="border-top-0 text-end pe-4"><?php echo get_string('managekb_col_action', 'local_chatbot'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($documents as $doc): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <i class="fa fa-file-pdf-o text-danger me-2"></i>
                                            <?php if (isset($kb_files_by_docid[$doc->id])): ?>
                                                <a href="<?php echo moodle_url::make_pluginfile_url($context->id, 'local_chatbot', 'kb_document', $doc->id, '/', 'documento.pdf')->out(false); ?>"
                                                   target="_blank" rel="noopener"
                                                   title="<?php echo get_string('managekb_view', 'local_chatbot'); ?>">
                                                    <strong><?php echo s($doc->filename); ?></strong>
                                                </a>
                                            <?php else: ?>
                                                <strong><?php echo s($doc->filename); ?></strong>
                                            <?php endif; ?>
                                            <div class="text-muted small"><?php echo round($doc->sizebytes / 1024, 1); ?> KB</div>
                                        </td>
                                        <td>
                                            <span class="text-muted small">
                                                <i class="fa fa-calendar-o me-1"></i> <?php echo userdate($doc->timecreated, get_string('strftimedatetimeshort')); ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <a href="<?php echo new moodle_url('/local/chatbot/manage_kb.php', ['delete' => $doc->id, 'sesskey' => sesskey()]); ?>"
                                               class="btn btn-sm btn-outline-danger"
                                               data-confirm-delete="<?php echo s($doc->filename); ?>"
                                               title="<?php echo get_string('managekb_delete', 'local_chatbot'); ?>">
                                                <i class="fa fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <a href="<?php echo new moodle_url('/my/'); ?>" class="btn btn-outline-secondary">
                <i class="fa fa-arrow-left me-2"></i> <?php echo get_string('managekb_back', 'local_chatbot'); ?>
            </a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mostrar spinner en el submit para dar feedback de que la IA está trabajando
    const form = document.getElementById('upload-form');
    if (form) {
        form.addEventListener('submit', function() {
            document.getElementById('btn-text').style.display = 'none';
            document.getElementById('btn-loader').style.display = 'inline-block';
            document.getElementById('submit-btn').setAttribute('disabled', 'disabled');
        });
    }

    // Confirmar antes de eliminar un documento (acción irreversible).
    document.querySelectorAll('[data-confirm-delete]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            const name = link.getAttribute('data-confirm-delete');
            const question = '<?php echo get_string('managekb_confirm_delete', 'local_chatbot'); ?>';
            if (!confirm(name + ': ' + question)) {
                e.preventDefault();
            }
        });
    });
});
</script>

<style>
/* Pequeños ajustes CSS para mejorar la apariencia en el entorno de Moodle */
.card { border-radius: 0.5rem; }
.card-header { border-radius: 0.5rem 0.5rem 0 0 !important; }
.table-vcenter td { vertical-align: middle; }
</style>

<?php
echo $OUTPUT->footer();
