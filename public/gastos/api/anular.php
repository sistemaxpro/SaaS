<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requirePermission('app_grid_caja', 'priv_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id_empresa = (int)($_SESSION['id_empresa'] ?? 169);
$id_gasto = (int)($input['id_gasto'] ?? 0);

if ($id_gasto <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID de gasto inválido']);
    exit;
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    $stmt = $pdo->prepare("UPDATE {$db}.gastos_empresa SET estado = 'ANULADO' WHERE id_gasto = :id AND id_empresa = :id_empresa");
    $stmt->execute([':id' => $id_gasto, ':id_empresa' => $id_empresa]);

    echo json_encode(['ok' => true, 'msg' => 'Gasto anulado']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
