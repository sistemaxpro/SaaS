<?php
/**
 * Health check simple para Vcorta (OpenAI/Ollama).
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
Session::requireLogin();

header('Content-Type: application/json; charset=utf-8');

$openAiKey = trim((string)getenv('OPENAI_API_KEY'));
if ($openAiKey === '') {
    $cfgFile = __DIR__ . '/../../../config/openai.php';
    if (is_file($cfgFile)) {
        $cfg = include $cfgFile;
        if (is_array($cfg)) {
            $openAiKey = trim((string)($cfg['api_key'] ?? ''));
        }
    }
}

$openAiReachable = false;
$openAiErr = '';
if ($openAiKey !== '') {
    try {
        $ch = curl_init('https://api.openai.com/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $openAiKey
            ]
        ]);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($cerr) {
            $openAiErr = $cerr;
        } else {
            $openAiReachable = ($http >= 200 && $http < 300);
            if (!$openAiReachable) $openAiErr = 'HTTP ' . $http;
        }
    } catch (Throwable $e) {
        $openAiErr = $e->getMessage();
    }
}

$useOllamaEnv = strtolower(trim((string)getenv('VCORTA_USE_OLLAMA')));
$useOllama = !in_array($useOllamaEnv, ['0', 'false', 'no', 'off'], true);
$ollamaUrl = trim((string)getenv('OLLAMA_URL'));
if ($ollamaUrl === '') $ollamaUrl = 'http://127.0.0.1:11434/api/chat';
$ollamaBase = preg_replace('#/api/(chat|generate)$#', '', $ollamaUrl);
$ollamaTags = rtrim((string)$ollamaBase, '/') . '/api/tags';

$ollamaReachable = false;
$ollamaErr = '';
if ($useOllama) {
    try {
        $ch = curl_init($ollamaTags);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2
        ]);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($cerr) {
            $ollamaErr = $cerr;
        } else {
            $ollamaReachable = ($http >= 200 && $http < 300);
            if (!$ollamaReachable) $ollamaErr = 'HTTP ' . $http;
        }
    } catch (Throwable $e) {
        $ollamaErr = $e->getMessage();
    }
}

echo json_encode([
    'success' => true,
    'openai' => [
        'configured' => ($openAiKey !== ''),
        'reachable' => $openAiReachable,
        'error' => $openAiErr
    ],
    'ollama' => [
        'enabled' => $useOllama,
        'reachable' => $useOllama ? $ollamaReachable : false,
        'error' => $ollamaErr
    ]
]);
