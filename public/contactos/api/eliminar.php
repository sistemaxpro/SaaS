<?php
/**
 * Contactos API - Eliminar (cambiar estado)
 * POST: { id: 123, action: "deactivate"|"activate"|"delete" }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requirePermission('app_grid_clientes', 'priv_delete');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON inválido']);
    exit;
}

$id_empresa = (int)($input['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$id = (int)($input['id'] ?? 0);
$action = $input['action'] ?? 'deactivate';

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID requerido']);
    exit;
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM {$db}.clientes WHERE id = :id")->execute([':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Contacto eliminado']);
    } elseif ($action === 'activate') {
        $pdo->prepare("UPDATE {$db}.clientes SET estado = 1 WHERE id = :id")->execute([':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Contacto activado']);
    } else {
        $pdo->prepare("UPDATE {$db}.clientes SET estado = -1 WHERE id = :id")->execute([':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Contacto desactivado']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
