<?php

/**
 * Middleware de Timeout de Sesión por Inactividad
 * Se ejecuta en cada request para validar la inactividad de la sesión
 */

if (!defined('SISTEMAX_V1')) {
    die('Acceso no autorizado');
}

/**
 * Configuración de timeout de sesión
 * En minutos (por defecto 20)
 */
define('SESSION_TIMEOUT_MINUTES', 20);

/**
 * Rutas excluidas del control de inactividad
 * (login, API sin sesión, etc.)
 */
$excludedPaths = [
    '/public/login.php',
    '/public/forgot-password.php',
    '/public/reset-password.php',
    '/api/auth/login',
    '/api/auth/check-session',
    '/api/auth/register-activity',
    '/api/auth/refresh-activity',
    '/public/manifest.json',
    '/public/assets/',
    '/.well-known/',
];

/**
 * Verificar si la ruta actual está excluida
 */
function isPathExcluded($path, $exclusions)
{
    foreach ($exclusions as $exclusion) {
        if (strpos($path, $exclusion) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Obtener la ruta actual
 */
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Ejecutar validación de inactividad si:
// 1. La sesión está activa
// 2. El usuario está logueado
// 3. No está en una ruta excluida
if (
    session_status() === PHP_SESSION_ACTIVE &&
    isset($_SESSION['id_login']) &&
    isset($_SESSION['id_empresa']) &&
    !isPathExcluded($currentPath, $excludedPaths)
) {
    // Validar inactividad
    if (!Session::checkInactivity(SESSION_TIMEOUT_MINUTES)) {
        // La sesión fue destruida por inactividad
        // Redirigir al login con mensaje

        // Evitar redirección en requests AJAX/API
        $isAjaxRequest = (
            (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) ||
            (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0)
        );

        if ($isAjaxRequest) {
            // Responder con JSON para AJAX
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Su sesión ha expirado por inactividad. Por favor, inicie sesión nuevamente.',
                'code' => 'SESSION_TIMEOUT',
                'timeout_reason' => 'inactivity'
            ]);
            exit;
        } else {
            // Redirigir a login para requests normales
            $redirectUrl = '/public/login.php?timeout=inactivity';
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
}
