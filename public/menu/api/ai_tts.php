<?php
/**
 * TTS para Vcorta usando ElevenLabs (voz servidor).
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
Session::requireLogin();

function tts_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    tts_json(['success' => false, 'error' => 'Método no permitido'], 405);
}

$raw = file_get_contents('php://input');
$input = json_decode((string)$raw, true);
$text = trim((string)($input['text'] ?? ''));

if ($text === '') {
    tts_json(['success' => false, 'error' => 'Texto vacío'], 400);
}

if (mb_strlen($text, 'UTF-8') > 1800) {
    $text = mb_substr($text, 0, 1800, 'UTF-8');
}

$apiKey = trim((string)(getenv('VCORTA_ELEVENLABS_API_KEY') ?: getenv('ELEVENLABS_API_KEY')));
$voiceId = trim((string)(getenv('VCORTA_ELEVEN_VOICE_ID') ?: getenv('ELEVENLABS_VOICE_ID') ?: 'dlGxemPxFMTY7iXagmOj'));
$modelId = trim((string)(getenv('VCORTA_ELEVEN_MODEL') ?: 'eleven_multilingual_v2'));

if ($apiKey === '') {
    $cfgFile = __DIR__ . '/../../../config/elevenlabs.php';
    if (is_file($cfgFile)) {
        $cfg = include $cfgFile;
        if (is_array($cfg)) {
            $apiKey = trim((string)($cfg['api_key'] ?? ''));
            if (trim((string)$voiceId) === '' || $voiceId === 'dlGxemPxFMTY7iXagmOj') {
                $voiceId = trim((string)($cfg['voice_id'] ?? $voiceId));
            }
            $modelId = trim((string)($cfg['model_id'] ?? $modelId));
        }
    }
}

if ($apiKey === '') {
    // Soft-fail: no devolvemos 5xx para evitar ruido de bug-reporting global.
    tts_json(['success' => false, 'error' => 'TTS no configurado']);
}

$payload = [
    'text' => $text,
    'model_id' => $modelId,
    'voice_settings' => [
        'stability' => 0.4,
        'similarity_boost' => 0.75,
        'style' => 0.25,
        'use_speaker_boost' => true
    ]
];

$ch = curl_init("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'xi-api-key: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: audio/mpeg'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 25,
    CURLOPT_CONNECTTIMEOUT => 6
]);

$audio = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    tts_json(['success' => false, 'error' => 'TTS no disponible']);
}

if ($http < 200 || $http >= 300 || !is_string($audio) || $audio === '') {
    tts_json(['success' => false, 'error' => 'TTS temporalmente no disponible']);
}

header('Content-Type: audio/mpeg');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo $audio;
