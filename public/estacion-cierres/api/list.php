<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    Session::requireLogin();
    Permission::requireAccess('app_grid_estacion');
    $pdo = Database::getSessionEmpresaConnection();

    $fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-01');
    $fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

    $stmt = $pdo->prepare("SELECT id, fecha, id_usuario_supervisor, hora_cierre, estado, total_litros_vendidos, total_importe FROM estacion_cierre_playa WHERE DATE(fecha) BETWEEN ? AND ? ORDER BY fecha DESC LIMIT 100");
    $stmt->execute([$fecha_desde, $fecha_hasta]);
    $cierres = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Agregar detalle por combustible a cada cierre
    foreach ($cierres as &$cierre) {
        $stmt = $pdo->prepare("SELECT id_combustible, litros_vendidos, ec.nombre as combustible FROM estacion_cierre_playa_detalle d JOIN estacion_combustibles ec ON d.id_combustible = ec.id WHERE id_cierre_playa = ?");
        $stmt->execute([$cierre['id']]);
        $cierre['detalle'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['ok' => true, 'data' => $cierres], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
