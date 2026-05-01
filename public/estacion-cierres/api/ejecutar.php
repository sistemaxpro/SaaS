<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    Session::requireLogin();
    Permission::requireAccess('app_grid_estacion');
    $pdo = Database::getSessionEmpresaConnection();
    $id_usuario = Session::getIdLogin();

    // Verificar que no existe cierre para hoy
    $stmt = $pdo->query("SELECT id FROM estacion_cierre_playa WHERE DATE(fecha) = CURDATE() AND estado = 'cerrado'");
    if ($stmt->fetchColumn()) {
        throw new Exception('La playa ya fue cerrada hoy');
    }

    // Obtener todos los despachos del día
    $stmt = $pdo->query("SELECT ed.id_combustible, SUM(ed.litros) as litros_totales, SUM(ed.monto_total) as monto_total FROM estacion_despachos ed WHERE DATE(ed.fecha_hora) = CURDATE() GROUP BY ed.id_combustible");
    $despachosPorCombustible = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Calcular totales
    $totalLitros = array_sum(array_column($despachosPorCombustible, 'litros_totales'));
    $totalImporte = array_sum(array_column($despachosPorCombustible, 'monto_total'));

    // Crear cierre de playa
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO estacion_cierre_playa (fecha, id_usuario_supervisor, hora_cierre, estado, total_litros_vendidos, total_importe) VALUES (CURDATE(), ?, NOW(), 'cerrado', ?, ?)");
    $stmt->execute([$id_usuario, $totalLitros, $totalImporte]);
    $idCierre = $pdo->lastInsertId();

    // Crear detalle por combustible
    foreach ($despachosPorCombustible as $despacho) {
        $stmt = $pdo->prepare("INSERT INTO estacion_cierre_playa_detalle (id_cierre_playa, id_combustible, litros_vendidos) VALUES (?, ?, ?)");
        $stmt->execute([$idCierre, $despacho['id_combustible'], $despacho['litros_totales']]);
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'message' => 'Cierre de playa ejecutado correctamente', 'id_cierre' => $idCierre], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
