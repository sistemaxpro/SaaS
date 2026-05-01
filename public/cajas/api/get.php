<?php
/**
 * Cajas API - Obtener detalle por ID
 * GET: ?id_caja=1
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requireAccess('app_grid_caja');

$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$id_caja = (int)($_GET['id_caja'] ?? 0);

if ($id_caja <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'ID de caja inválido']);
    exit;
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    $stmt = $pdo->prepare("SELECT * FROM {$db}.cajas WHERE id_caja = :id LIMIT 1");
    $stmt->execute([':id' => $id_caja]);
    $caja = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$caja) {
        echo json_encode(['ok' => false, 'error' => 'Caja no encontrada']);
        exit;
    }

    $impresora = trim((string)($caja['impresora'] ?? ''));
    if ($impresora === '' && isset($caja['impresor'])) {
        $impresora = trim((string)$caja['impresor']);
    }
    $caja['impresora'] = $impresora;
    if (!isset($caja['impresor'])) {
        $caja['impresor'] = $impresora;
    }

    echo json_encode(['ok' => true, 'data' => $caja]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

