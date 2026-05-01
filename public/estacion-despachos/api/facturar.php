<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    Session::requireLogin();
    Permission::requireAccess('app_playero_estacion');
    $pdo = Database::getSessionEmpresaConnection();
    $id_usuario = Session::getIdLogin();
    $data = json_decode(file_get_contents('php://input'), true);

    $id_despacho = (int)($data['id'] ?? 0);
    if ($id_despacho <= 0) {
        throw new Exception('ID de despacho inválido');
    }

    // Obtener despacho
    $stmt = $pdo->prepare("SELECT ed.*, ec.nombre as combustible, ec.precio_venta FROM estacion_despachos ed JOIN estacion_combustibles ec ON ed.id_combustible = ec.id WHERE ed.id = ?");
    $stmt->execute([$id_despacho]);
    $despacho = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$despacho) {
        throw new Exception('Despacho no encontrado');
    }

    // Verificar que pertenece al usuario actual
    if ((int)$despacho['id_usuario'] !== $id_usuario) {
        throw new Exception('No tienes permiso para facturar este despacho');
    }

    // Verificar que no está ya facturado
    if ($despacho['id_factura']) {
        throw new Exception('Este despacho ya fue facturado');
    }

    // TODO: Integración con POS/SIFEN
    // Por ahora, generamos un número de comprobante y marcamos como facturado
    $nro_comprobante = '001-001-' . str_pad($id_despacho, 7, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("UPDATE estacion_despachos SET nro_comprobante = ?, estado = 'facturado', id_factura = ? WHERE id = ?");
    $stmt->execute([$nro_comprobante, $nro_comprobante, $id_despacho]);

    echo json_encode([
        'ok' => true,
        'message' => 'Despacho facturado',
        'nro_comprobante' => $nro_comprobante,
        'monto' => $despacho['monto_total']
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
