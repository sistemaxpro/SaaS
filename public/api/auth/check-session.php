<?php

/**
 * API Endpoint: Verificar estado de la sesión
 * GET /api/auth/check-session
 */

require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json');

// Verificar que sea una solicitud AJAX
if (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') !== 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$response = [
    'success' => false,
    'isLoggedIn' => false,
    'timeRemaining' => null,
    'code' => 'NOT_LOGGED_IN'
];

// Verificar si hay sesión activa
if (Session::isLoggedIn()) {
    $response['isLoggedIn'] = true;

    // Obtener tiempo restante antes del timeout
    $timeRemaining = Session::getTimeRemainingBeforeTimeout();

    if ($timeRemaining === null) {
        // Sesión activa pero sin registro de actividad (nueva sesión)
        $response['success'] = true;
        $response['timeRemaining'] = 20 * 60; // 20 minutos en segundos
    } else {
        $response['success'] = true;
        $response['timeRemaining'] = $timeRemaining;

        if ($timeRemaining <= 0) {
            // Sesión ya expirada
            $response['success'] = false;
            $response['code'] = 'SESSION_TIMEOUT';
            http_response_code(401);
        }
    }
} else {
    http_response_code(401);
    $response['code'] = 'NOT_LOGGED_IN';
}

echo json_encode($response);
