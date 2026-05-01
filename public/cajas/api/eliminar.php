<?php
/**
 * Cajas API - Eliminar caja
 * POST: { id_caja: int }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requirePermission('app_grid_caja', 'priv_delete');

$input = json_decode(file_get_contents('php://input'), true);
$id_caja = (int)($input['id_caja'] ?? 0);
$id_empresa = (int)($input['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

if ($id_caja <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID de caja inválido']);
    exit;
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    // Verificar si tiene movimientos
    try {
        $stmtMov = $pdo->prepare("SELECT COUNT(*) as total FROM {$db}.caja_movimientos WHERE caja = ?");
        $stmtMov->execute([$id_caja]);
        $totalMov = (int)$stmtMov->fetch()['total'];
        if ($totalMov > 0) {
            echo json_encode(['ok' => false, 'error' => "No se puede eliminar: la caja tiene {$totalMov} movimientos registrados"]);
            exit;
        }
    } catch (Exception $e) {
        // tabla puede no existir
    }

    $pdo->beginTransaction();

    // Eliminar usuarios asignados
    try {
        $pdo->prepare("DELETE FROM {$db}.cajas_usuarios WHERE id_caja = ?")->execute([$id_caja]);
    } catch (Exception $e) {}

    // Eliminar caja
    $stmt = $pdo->prepare("DELETE FROM {$db}.cajas WHERE id_caja = ?");
    $stmt->execute([$id_caja]);

    $pdo->commit();

    echo json_encode(['ok' => true, 'msg' => 'Caja eliminada exitosamente']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
