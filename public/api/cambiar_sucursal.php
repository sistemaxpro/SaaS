<?php
/**
 * API - Cambiar sucursal activa en sesión
 * POST: { id_sucursal: int, nombre_sucursal: string }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/bootstrap.php';

Session::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id_sucursal = (int)($input['id_sucursal'] ?? 0);
$nombre = trim($input['nombre_sucursal'] ?? '');

if ($id_sucursal <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Sucursal no válida']);
    exit;
}

// Verificar que la sucursal pertenece a la empresa logueada
$id_empresa = Session::getIdEmpresa();

try {
    $masterPdo = Database::getMasterConnection();
    $stmtE = $masterPdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = ?");
    $stmtE->execute([$id_empresa]);
    $emp = $stmtE->fetch(PDO::FETCH_ASSOC);

    if (!$emp) {
        echo json_encode(['ok' => false, 'error' => 'Empresa no encontrada']);
        exit;
    }

    $pdo = Database::getEmpresaConnection((int)$id_empresa);
    $db = $emp['dbase'];

    $stmt = $pdo->prepare("SELECT SUC, NOMBRE FROM {$db}.sucursal WHERE SUC = ?");
    $stmt->execute([$id_sucursal]);
    $suc = $stmt->fetch();

    if (!$suc) {
        echo json_encode(['ok' => false, 'error' => 'Sucursal no encontrada en esta empresa']);
        exit;
    }

    // Guardar en sesión
    Session::set('id_sucursal', (int)$suc['SUC']);
    Session::set('sucursal', $suc['NOMBRE']);

    echo json_encode([
        'ok' => true,
        'msg' => 'Sucursal cambiada a: ' . $suc['NOMBRE'],
        'id_sucursal' => (int)$suc['SUC'],
        'nombre' => $suc['NOMBRE']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
