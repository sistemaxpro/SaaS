<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

// Forzar salida de errores para debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true) ?: [];

    echo json_encode([
        'success' => true,
        'message' => 'DEBUG: Conexión recibida',
        'input_received' => $input,
        'session_id' => session_id()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'DEBUG Error: ' . $e->getMessage()
    ]);
}
exit;
