<?php
/**
 * API - Anular Venta
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/bootstrap.php';

Session::requireLogin('/public/login.php');

Permission::requirePermission('app_grid_factura_venta_global', 'priv_delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$id_factura = isset($input['id_factura']) ? (int)$input['id_factura'] : 0;
$id_empresa = (int)Session::get('id_empresa');

if (!$id_factura) {
    echo json_encode(['success' => false, 'message' => 'ID de factura requerido']);
    exit;
}
if ($id_empresa <= 0) {
    echo json_encode(['success' => false, 'message' => 'Empresa no definida en sesión']);
    exit;
}

try {
    $empresa = Database::getEmpresaInfo($id_empresa);
    if (!$empresa || empty($empresa['dbase'])) {
        throw new Exception("Empresa no encontrada");
    }
    $pdo = Database::getEmpresaConnection($id_empresa);
    $dbName = (string)$empresa['dbase'];
    
    // Verificar que la factura existe y no está anulada
    $stmt = $pdo->prepare("SELECT id_factura, estado, cdc FROM factura_ventas WHERE id_factura = :id");
    $stmt->execute([':id' => $id_factura]);
    $factura = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$factura) {
        echo json_encode(['success' => false, 'message' => 'Factura no encontrada']);
        exit;
    }
    
    if ($factura['estado'] == 1) {
        echo json_encode(['success' => false, 'message' => 'La factura ya está anulada']);
        exit;
    }
    
    // Si tiene CDC (enviada a SIFEN), necesita proceso especial
    if ($factura['cdc']) {
        // TODO: Implementar anulación en SIFEN
        // Por ahora solo marcar como anulada localmente
    }
    
    // Anular la factura (estado = 1)
    $stmtUpdate = $pdo->prepare("UPDATE factura_ventas SET estado = 1, fecha_anulacion = NOW() WHERE id_factura = :id");
    $stmtUpdate->execute([':id' => $id_factura]);
    
    // Revertir stock (opcional, según configuración)
    // TODO: Implementar reversión de stock
    
    echo json_encode([
        'success' => true,
        'message' => 'Venta anulada correctamente'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
