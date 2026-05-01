<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    Session::requireLogin();
    Permission::requireAccess('app_playero_estacion');
    $pdo = Database::getSessionEmpresaConnection();
    $id_usuario = Session::getIdLogin();

    $stmt = $pdo->prepare("SELECT id, fecha_turno, hora_apertura, hora_cierre, estado, efectivo_apertura, efectivo_cierre FROM estacion_turnos WHERE id_usuario = ? AND DATE(fecha_turno) = CURDATE() AND estado = 'abierto' LIMIT 1");
    $stmt->execute([$id_usuario]);
    $turno = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($turno) {
        // Agregar despachos del turno
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_despachos, SUM(litros) as total_litros, SUM(monto_total) as total_monto FROM estacion_despachos WHERE id_turno = ?");
        $stmt->execute([$turno['id']]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        $turno['stats'] = $stats;
    }

    echo json_encode(['ok' => true, 'data' => $turno], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
