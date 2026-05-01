<?php
/**
 * Productos API - Persistencia de estado AG Grid en sesión PHP
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db_config.php';

Permission::requireAccess('app_grid_mercaderias');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$action = strtolower((string)($_GET['action'] ?? 'get'));
$idEmpresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 0);
$idLogin = (int)($_SESSION['id_login'] ?? 0);

if ($idEmpresa <= 0 || $idLogin <= 0) {
    echo json_encode(['success' => false, 'error' => 'Sesion invalida']);
    exit;
}

if ((int)($_SESSION['id_empresa'] ?? 0) !== $idEmpresa) {
    echo json_encode(['success' => false, 'error' => 'Empresa invalida']);
    exit;
}

if (!isset($_SESSION['ag_grid_state']) || !is_array($_SESSION['ag_grid_state'])) {
    $_SESSION['ag_grid_state'] = [];
}
if (!isset($_SESSION['ag_grid_state']['productos']) || !is_array($_SESSION['ag_grid_state']['productos'])) {
    $_SESSION['ag_grid_state']['productos'] = [];
}
if (!isset($_SESSION['ag_grid_state']['productos'][$idEmpresa]) || !is_array($_SESSION['ag_grid_state']['productos'][$idEmpresa])) {
    $_SESSION['ag_grid_state']['productos'][$idEmpresa] = [];
}

if ($action === 'get') {
    $state = $_SESSION['ag_grid_state']['productos'][$idEmpresa][$idLogin] ?? null;
    echo json_encode(['success' => true, 'data' => $state], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($action === 'save') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '{}', true);
    $state = $payload['state'] ?? null;

    if (!is_array($state)) {
        echo json_encode(['success' => false, 'error' => 'Estado invalido']);
        exit;
    }

    // Limitar tamaño para no inflar $_SESSION
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || strlen($json) > 120000) {
        echo json_encode(['success' => false, 'error' => 'Estado demasiado grande']);
        exit;
    }

    $_SESSION['ag_grid_state']['productos'][$idEmpresa][$idLogin] = $state;
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Accion invalida']);

