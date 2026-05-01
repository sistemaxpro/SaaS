<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    Session::requireLogin();
    Permission::requireAccess('app_playero_estacion');
    $pdo = Database::getSessionEmpresaConnection();
    $id_usuario = Session::getIdLogin();

    $fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-01');
    $fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');

    $stmt = $pdo->prepare("SELECT id, fecha_turno, hora_apertura, hora_cierre, estado, efectivo_apertura, efectivo_cierre FROM estacion_turnos WHERE id_usuario = ? AND DATE(fecha_turno) BETWEEN ? AND ? ORDER BY fecha_turno DESC LIMIT 100");
    $stmt->execute([$id_usuario, $fecha_desde, $fecha_hasta]);
    $turnos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Agregar despachos del día a cada turno actual
    foreach ($turnos as &$turno) {
        if ($turno['estado'] === 'abierto') {
            $stmt = $pdo->prepare("SELECT COUNT(*) as total_despachos, SUM(litros) as total_litros, SUM(monto_total) as total_monto FROM estacion_despachos WHERE id_turno = ?");
            $stmt->execute([$turno['id']]);
            $despachos = $stmt->fetch(PDO::FETCH_ASSOC);
            $turno['despachos'] = $despachos;
        }
    }

    echo json_encode(['ok' => true, 'data' => $turnos], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
