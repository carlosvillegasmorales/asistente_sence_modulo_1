<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
$apikey = get_config('local_chatbot', 'gemini_api_key');
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=' . $apikey;

$prompt = "Hola, esto es una prueba.";
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

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if(curl_errno($ch)){
    echo "CURL ERROR: " . curl_error($ch) . "\n";
}
curl_close($ch);

echo "HTTP CODE: $http_code\n";
echo "RESPONSE: $response\n";
