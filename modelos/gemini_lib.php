<?php

/**
 * Gemini AI Library for SistemaX
 * Integration with Google Gemini 1.5 Flash (Preview)
 */

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', 'AIzaSyDtf5P3KmZ68ZvkO-Kljwi5b-0EMjK_KJU');
}

/**
 * Sends a request to Google Gemini API
 * 
 * @param string $prompt The text prompt
 * @param string|null $imageBase64 Optional base64 encoded image
 * @param string $mimeType Mime type of the image
 * @param string $model Model name (defaults to gemini-1.5-flash)
 * @return array Response from Gemini
 */
function askGemini($prompt, $imageBase64 = null, $mimeType = 'image/jpeg', $model = 'gemini-2.0-flash', $timeoutSec = 35)
{
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

    $contents = [];
    $parts = [];

    $parts[] = ['text' => $prompt];

    if ($imageBase64) {
        $parts[] = [
            'inline_data' => [
                'mime_type' => $mimeType,
                'data' => $imageBase64
            ]
        ];
    }

    $contents[] = [
        'parts' => $parts
    ];

    $payload = [
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => 0.1,
            'topK' => 1,
            'topP' => 1,
            'maxOutputTokens' => 2048,
            'responseMimeType' => 'application/json'
        ]
    ];

    $ch = curl_init($url);
    $timeoutSec = (int)$timeoutSec;
    if ($timeoutSec < 10) $timeoutSec = 10;
    if ($timeoutSec > 45) $timeoutSec = 45;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_SSL_VERIFYPEER => false // Adjust based on environment
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Error de conexión Gemini: $curlError");
    }

    $result = json_decode($response, true);

    if ($httpCode !== 200) {
        $errorMsg = $result['error']['message'] ?? 'Error desconocido de Gemini';
        throw new Exception("Error Gemini ($httpCode): $errorMsg");
    }

    return $result;
}

/**
 * Extracts the text content from Gemini's response
 */
function getGeminiContent($result)
{
    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        return $result['candidates'][0]['content']['parts'][0]['text'];
    }
    return '';
}
