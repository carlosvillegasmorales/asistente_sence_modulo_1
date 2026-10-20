<?php
namespace local_chatbot\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Extrae el texto de un PDF recien subido a la base de conocimiento.
 *
 * Corre fuera del request web a proposito. El proveedor de IA no envia nada
 * hasta terminar de procesar el documento completo, y un anexo de cien paginas
 * tarda varios minutos: por el navegador, el servidor web cortaba la conexion
 * antes (60 s con la configuracion por defecto de nginx) y la carga fallaba
 * siempre, sin importar cuanto se subiera el tiempo de espera del plugin.
 *
 * Por CLI no hay gateway que corte, asi que el limite lo pone solo el proveedor.
 */
class extract_kb_document extends \core\task\adhoc_task {

    /** @var int Segundos de espera para el proveedor. Sin navegador esperando, puede ser generoso. */
    const TIMEOUT = 600;

    /**
     * @return string Nombre legible de la tarea.
     */
    public function get_name(): string {
        return get_string('task_extract_kb', 'local_chatbot');
    }

    /**
     * Ejecución de la tarea.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $docid = (int) ($data->docid ?? 0);
        if ($docid <= 0) {
            mtrace('local_chatbot: tarea de extraccion sin docid; se descarta.');
            return;
        }

        $context = \context_system::instance();
        $fs = get_file_storage();
        $file = $fs->get_file($context->id, 'local_chatbot', 'kb_document', $docid, '/', 'documento.pdf');
        if (!$file) {
            mtrace("local_chatbot: no se encontro el PDF del documento {$docid}.");
            \local_chatbot\kb_store::fail_document($docid, 'sin_archivo');
            return;
        }

        mtrace("local_chatbot: extrayendo texto del documento {$docid} (" .
            round($file->get_filesize() / 1024) . " KB)...");

        $contents = [[
            'parts' => [
                ['inlineData' => [
                    'mimeType' => 'application/pdf',
                    'data' => base64_encode($file->get_content()),
                ]],
                ['text' => 'Por favor, extrae TODO el texto de este documento PDF respetando su '
                    . 'estructura y secciones. No omitas información, no resumas y no agregues '
                    . 'ningún tipo de comentario extra tuyo. Solo devuelve el texto puro extraído.'],
            ],
        ]];

        $result = \local_chatbot\ai\provider_factory::get()->generate_content(
            $contents, self::TIMEOUT, 2, \local_chatbot\ai\provider_factory::get_configured_model());

        $texto = \local_chatbot\gemini_client::extract_text($result);
        $motivoerror = (string) ($result['error']['message'] ?? '');
        $finishreason = (string) ($result['candidates'][0]['finishReason'] ?? '');
        $decision = \local_chatbot\kb_store::classify_extraction($texto, $motivoerror, $finishreason);

        switch ($decision['estado']) {
            case 'listo':
                \local_chatbot\kb_store::complete_document($docid, $texto);
                mtrace("local_chatbot: documento {$docid} incorporado (" .
                    round(strlen($texto) / 1024, 1) . " KB de texto).");
                break;

            case 'rechazado':
                // RU-KB-001: un documento con claves de evaluacion no entra.
                \local_chatbot\kb_store::fail_document($docid, 'claves:' . $decision['motivo']);
                mtrace("local_chatbot: documento {$docid} rechazado por claves de evaluacion ({$decision['motivo']}).");
                break;

            default:
                \local_chatbot\kb_store::fail_document($docid, $decision['motivo']);
                mtrace("local_chatbot: fallo la extraccion del documento {$docid}: {$decision['motivo']}.");
                break;
        }
    }
}
