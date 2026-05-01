<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    Session::requireLogin();
    Permission::requireAccess('app_grid_estacion');
    $pdo = Database::getSessionEmpresaConnection();

    // Turnos activos
    $stmt = $pdo->query("SELECT COUNT(*) FROM estacion_turnos WHERE DATE(fecha_turno) = CURDATE() AND estado = 'abierto'");
    $turnosActivos = (int)$stmt->fetchColumn();

    // Despachos del día
    $stmt = $pdo->query("SELECT COUNT(*) FROM estacion_despachos WHERE DATE(fecha_hora) = CURDATE()");
    $despachosHoy = (int)$stmt->fetchColumn();

    // Total vendido
    $stmt = $pdo->query("SELECT SUM(monto_total) FROM estacion_despachos WHERE DATE(fecha_hora) = CURDATE()");
    $totalVendido = (float)($stmt->fetchColumn() ?? 0);

    // Surtidores activos
    $stmt = $pdo->query("SELECT COUNT(*) FROM estacion_surtidores WHERE activo = 'Y'");
    $surtidoresActivos = (int)$stmt->fetchColumn();

    // Tanques
    $stmt = $pdo->query("SELECT t.id, t.nombre, COUNT(p.id) as picos, (SELECT COUNT(*) FROM estacion_lecturas_tanque WHERE id_tanque = t.id AND DATE(fecha_hora) = CURDATE()) as lecturas_hoy FROM estacion_tanques t LEFT JOIN estacion_picos p ON t.id = p.id_tanque WHERE t.activo = 'Y' GROUP BY t.id");
    $tanques = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Despachos por combustible
    $stmt = $pdo->query("SELECT ec.nombre, SUM(ed.litros) as litros, SUM(ed.monto_total) as monto FROM estacion_despachos ed JOIN estacion_combustibles ec ON ed.id_combustible = ec.id WHERE DATE(ed.fecha_hora) = CURDATE() GROUP BY ed.id_combustible");
    $porCombustible = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok' => true,
        'data' => [
            'turnosActivos' => $turnosActivos,
            'despachosHoy' => $despachosHoy,
            'totalVendido' => $totalVendido,
            'surtidoresActivos' => $surtidoresActivos,
            'tanques' => $tanques,
            'porCombustible' => $porCombustible,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
