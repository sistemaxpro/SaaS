<?php
/**
 * Proxy local para Vcorta desde menú.
 * Evita bloqueos de ruta al endpoint de POS y reutiliza su lógica.
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
Session::requireLogin();

header('Content-Type: application/json; charset=utf-8');

ob_start();
try {
    include __DIR__ . '/../../pos/api/ai_chat.php';
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error' => 'Proxy vcorta: ' . $e->getMessage()
    ]);
    exit;
}

$output = trim(ob_get_clean());
if ($output === '') {
    echo json_encode([
        'success' => false,
        'error' => 'Proxy vcorta sin respuesta'
    ]);
    exit;
}

echo $output;
