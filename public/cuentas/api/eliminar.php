<?php
/**
 * Cuentas API - Anular/Reactivar cuenta
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requirePermission('cuentas', 'priv_delete');

$id_empresa = (int)($_SESSION['id_empresa'] ?? 0);
if ($id_empresa <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Sin sesión']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = (int)($input['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID inválido']);
    exit;
}

function getColumns(PDO $pdo, string $db, string $table): array {
    $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = :db AND table_name = :tb");
    $stmt->execute([':db' => $db, ':tb' => $table]);
    return array_map('strtolower', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'column_name'));
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    $cols = getColumns($pdo, $db, 'cuentas');
    $hasEstado = in_array('estado', $cols, true);

    if ($hasEstado) {
        $stmtGet = $pdo->prepare("SELECT estado FROM {$db}.cuentas WHERE id = :id LIMIT 1");
        $stmtGet->execute([':id' => $id]);
        $current = $stmtGet->fetchColumn();
        if ($current === false) {
            echo json_encode(['ok' => false, 'error' => 'Cuenta no encontrada']);
            exit;
        }
        $new = ((int)$current === 1) ? 0 : 1;
        $stmt = $pdo->prepare("UPDATE {$db}.cuentas SET estado = :e WHERE id = :id");
        $stmt->execute([':e' => $new, ':id' => $id]);
        echo json_encode(['ok' => true, 'msg' => $new === 1 ? 'Cuenta reactivada' : 'Cuenta anulada', 'estado' => $new]);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM {$db}.cuentas WHERE id = :id");
    $stmt->execute([':id' => $id]);
    echo json_encode(['ok' => true, 'msg' => 'Cuenta eliminada']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
