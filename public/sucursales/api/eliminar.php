<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/common.php';

sx_suc_can('priv_delete');

$input = json_decode((string)file_get_contents('php://input'), true);
$idSucursal = (int)($input['id_sucursal'] ?? 0);
$idEmpresa = (int)($input['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

if ($idSucursal <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID inválido']);
    exit;
}

try {
    $conn = sx_suc_get_conn($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $cols = sx_suc_columns($pdo, $db);

    if (isset($cols['activo'])) {
        $st = $pdo->prepare("UPDATE {$db}.sucursales SET activo = 0 WHERE id_sucursal = :id");
        $st->execute([':id' => $idSucursal]);
        echo json_encode(['ok' => true, 'msg' => 'Sucursal desactivada']);
        exit;
    }

    $st = $pdo->prepare("DELETE FROM {$db}.sucursales WHERE id_sucursal = :id");
    $st->execute([':id' => $idSucursal]);
    echo json_encode(['ok' => true, 'msg' => 'Sucursal eliminada']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
