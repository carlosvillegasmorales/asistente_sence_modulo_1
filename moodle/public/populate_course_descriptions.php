<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/config.php');

mtrace("Iniciando la generación de descripciones para todos los cursos con IA (Modo Rápido)...");

$apikey = get_config('local_chatbot', 'gemini_api_key');
if (empty($apikey)) {
    die("No API key configurada. Usa texto genérico en su lugar.");
}

$courses = $DB->get_records_select('course', 'id != 1');
$courses_array = array_values($courses);

$batch_size = 10;
$total_courses = count($courses_array);
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=' . $apikey;

for ($i = 0; $i < $total_courses; $i += $batch_size) {
    $batch = array_slice($courses_array, $i, $batch_size);
    mtrace(sprintf("Procesando lote %d a %d de %d cursos...", $i + 1, min($i + $batch_size, $total_courses), $total_courses));
    
    $mh = curl_multi_init();
    $curl_handles = [];
    
    // Preparar todas las peticiones del lote
    foreach ($batch as $index => $course) {
        $prompt = "Actúa como un diseñador instruccional experto. Escribe una descripción completa, atractiva y moderna en formato HTML (con <h3>, <p>, <ul>) para un curso llamado: '" . $course->fullname . "'. 
La descripción debe contener:
1. Una introducción motivadora.
2. Una lista de 3 objetivos de aprendizaje principales.
3. A quién va dirigido.
Trata de ser profesional y muy detallado. No uses Markdown, usa etiquetas HTML puras.";

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

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        curl_multi_add_handle($mh, $ch);
        $curl_handles[$index] = [
            'ch' => $ch,
            'course' => $course
        ];
    }
    
    // Ejecutar peticiones en paralelo
    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    
    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh) != -1) {
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }
    
    // Procesar las respuestas
    foreach ($curl_handles as $handle_info) {
        $ch = $handle_info['ch'];
        $course = $handle_info['course'];
        
        $response = curl_multi_getcontent($ch);
        $result = json_decode($response, true);
        
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $summary = $result['candidates'][0]['content']['parts'][0]['text'];
            
            // Limpiar backticks si la IA los pone por error
            $summary = str_replace(['```html', '```'], '', $summary);
            
            // Actualizar en BD
            $update = new stdClass();
            $update->id = $course->id;
            $update->summary = trim($summary);
            $update->summaryformat = FORMAT_HTML;
            
            $DB->update_record('course', $update);
            mtrace("  -> Descripción guardada: " . $course->fullname);
        } else {
            mtrace("  -> Error al generar descripción para: " . $course->fullname . " (" . (isset($result['error']['message']) ? $result['error']['message'] : 'Respuesta inesperada') . ")");
        }
        
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    
    curl_multi_close($mh);
    
    // Pequeña pausa entre lotes si hay más lotes pendientes
    if ($i + $batch_size < $total_courses) {
        sleep(1);
    }
}

mtrace("¡Todas las descripciones han sido actualizadas con la IA!");
