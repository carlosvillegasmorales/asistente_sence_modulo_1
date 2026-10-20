<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

header('Content-Type: application/json');

require_login();
$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);

$context = context_course::instance($courseid);
require_capability('moodle/course:view', $context);

file_put_contents(__DIR__ . '/debug_summary.log', "INICIO AJAX\n", FILE_APPEND);

$apikey = get_config('local_chatbot', 'gemini_api_key');

if (empty($apikey)) {
    echo json_encode(['error' => 'API Key no configurada.']);
    exit;
}

// Implementar caché basado en archivos para que sea instantáneo después del primer uso
$cachedir = $CFG->cachedir . '/theme_sence_ai';
if (!is_dir($cachedir)) {
    @mkdir($cachedir, 0777, true);
}
$cachefile = $cachedir . '/course_' . $courseid . '.txt';

// Si el caché existe y tiene menos de 7 días, lo usamos
if (file_exists($cachefile) && (time() - filemtime($cachefile)) < 86400 * 7) {
    $summary = file_get_contents($cachefile);
    echo json_encode(['success' => true, 'summary' => $summary]);
    exit;
}

// Recopilar módulos del curso para el resumen
$modinfo = get_fast_modinfo($course);
$sections = $modinfo->get_section_info_all();
$course_content_text = "";
foreach ($sections as $section) {
    if (!empty($section->name)) {
        $course_content_text .= "Sección: " . format_string($section->name) . "\n";
    }
    if (!empty($modinfo->sections[$section->section])) {
        foreach ($modinfo->sections[$section->section] as $cmid) {
            $cm = $modinfo->cms[$cmid];
            $course_content_text .= " - Módulo: " . format_string($cm->name) . "\n";
        }
    }
}

// Limitar longitud del texto para evitar demoras innecesarias (reducido a 4000)
$course_content_text = mb_substr($course_content_text, 0, 4000, 'UTF-8');

file_put_contents(__DIR__ . '/debug_summary.log', "Preparando payload JSON\n", FILE_APPEND);

$prompt = "Eres un asistente académico experto. A continuación te doy el contenido de un curso (secciones y módulos). Crea un resumen muy breve (máximo 2 párrafos) y motivador sobre lo que el estudiante aprenderá, dirigido directamente al estudiante en tono amigable y profesional.\n\nContenido:\n" . $course_content_text;

// Modelo rápido y económico recomendado por Google
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent?key=' . $apikey;

$data = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.2,
        'maxOutputTokens' => 800
    ]
];

$max_retries = 2;
$retry_count = 0;
$result = null;

file_put_contents(__DIR__ . '/debug_summary.log', "Iniciando cURL loop\n", FILE_APPEND);

while ($retry_count < $max_retries) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Añadimos un timeout para que no se quede colgado
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    file_put_contents(__DIR__ . '/debug_summary.log', "Ejecutando cURL intento " . $retry_count . "\n", FILE_APPEND);
    $response = curl_exec($ch);
    file_put_contents(__DIR__ . '/debug_summary.log', "cURL finalizado. Respuesta: " . substr($response, 0, 100) . "\n", FILE_APPEND);
    
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($http_code == 503 || (isset($result['error']['code']) && $result['error']['code'] == 503) || $http_code >= 500) {
        $retry_count++;
        if ($retry_count < $max_retries) {
            sleep(1);
            continue;
        }
    }
    
    break;
}

if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
    $summary = $result['candidates'][0]['content']['parts'][0]['text'];
    $summary = str_replace(['```html', '```'], '', $summary);
    $final_summary = $result['candidates'][0]['content']['parts'][0]['text'];
    
    file_put_contents(__DIR__ . '/debug_summary.log', "Guardando en cache y respondiendo success\n", FILE_APPEND);
    // Guardar en caché
    @file_put_contents($cachefile, $final_summary);
    
    echo json_encode(['success' => true, 'summary' => $final_summary]);
} else {
    file_put_contents(__DIR__ . '/debug_summary.log', "Respondiendo error\n", FILE_APPEND);
    
    // Capturar error 429 específico
    if (isset($result['error']['code']) && $result['error']['code'] == 429) {
        $errmsg = '¡Vaya! La Inteligencia Artificial ha alcanzado su límite de respuestas gratuitas por minuto. Por favor, espera unos 60 segundos e inténtalo de nuevo.';
    } else {
        $errmsg = isset($result['error']['message']) ? $result['error']['message'] : 'Respuesta inesperada de la IA.';
    }
    
    echo json_encode(['error' => $errmsg]);
}
?>
