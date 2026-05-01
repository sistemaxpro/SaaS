<?php
/**
 * POS Popular Debug Logger
 * Solo para empresa 169 y usuarios admin.
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido']);
    exit;
}

$idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
$idLogin = (int)($_SESSION['id_login'] ?? 0);
$usuario = trim((string)($_SESSION['login'] ?? $_SESSION['usuario'] ?? ''));

if ($idEmpresa !== 169 || $idLogin <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No autorizado']);
    exit;
}

try {
    $pdo = getMasterConnection();
    $stmt = $pdo->prepare("SELECT role FROM sec_users WHERE id_login = :id LIMIT 1");
    $stmt->execute([':id' => $idLogin]);
    $role = strtolower(trim((string)($stmt->fetchColumn() ?: '')));
    if ($role === '' || strpos($role, 'admin') === false) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Solo admin']);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo validar usuario']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) $input = [];

$event = trim((string)($input['event'] ?? 'unknown'));
$detail = $input['detail'] ?? null;
$page = trim((string)($input['page'] ?? ''));
$ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

$row = [
    'ts' => date('Y-m-d H:i:s'),
    'id_empresa' => $idEmpresa,
    'id_login' => $idLogin,
    'usuario' => $usuario,
    'event' => $event,
    'detail' => $detail,
    'page' => $page,
    'ua' => $ua,
];

$logFile = rtrim(sys_get_temp_dir(), '/\\') . '/sistemax_popular_debug_169.log';
$line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
@file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

echo json_encode(['ok' => true]);

