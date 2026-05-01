<?php

/**
 * API Endpoint: Refrescar/Extender sesión
 * POST /api/auth/refresh-activity
 *
 * Extiende la sesión reiniciando el contador de inactividad
 */

require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json');

// Verificar que sea una solicitud AJAX
if (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') !== 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$response = [
    'success' => false,
    'isLoggedIn' => false
];

// Verificar si hay sesión activa
if (Session::isLoggedIn()) {
    // Actualizar el timestamp de actividad para extender la sesión
    Session::updateActivityTime();

    $response['success'] = true;
    $response['isLoggedIn'] = true;
    $response['message'] = 'Sesión extendida exitosamente';
    $response['timeRemaining'] = 20 * 60; // Reset: 20 minutos nuevamente

    http_response_code(200);
} else {
    http_response_code(401);
    $response['code'] = 'NOT_LOGGED_IN';
    $response['message'] = 'No hay sesión activa';
}

echo json_encode($response);
