<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    Session::requireLogin();
    Permission::requireAccess('app_playero_estacion');
    $pdo = Database::getSessionEmpresaConnection();
    $id_usuario = Session::getIdLogin();
    $data = json_decode(file_get_contents('php://input'), true);

    // Obtener turno abierto
    $stmt = $pdo->prepare("SELECT id FROM estacion_turnos WHERE id_usuario = ? AND DATE(fecha_turno) = CURDATE() AND estado = 'abierto'");
    $stmt->execute([$id_usuario]);
    $turno = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$turno) {
        throw new Exception('No hay turno abierto');
    }

    $efectivo_cierre = (float)($data['efectivo_cierre'] ?? 0);
    $observacion = trim($data['observacion'] ?? '');

    // Actualizar turno a cerrado
    $stmt = $pdo->prepare("UPDATE estacion_turnos SET estado = 'cerrado', hora_cierre = NOW(), efectivo_cierre = ?, observacion_cierre = ? WHERE id = ?");
    $stmt->execute([$efectivo_cierre, $observacion, $turno['id']]);

    // Calcular despachos del turno
    $stmt = $pdo->prepare("SELECT SUM(monto_total) as total FROM estacion_despachos WHERE id_turno = ?");
    $stmt->execute([$turno['id']]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_despachos = (float)($res['total'] ?? 0);

    // Registrar en extracto_caja
    $stmt = $pdo->prepare("INSERT INTO extracto_caja (fecha, concepto, descripcion, credito, debito, saldo, login, estado) VALUES (NOW(), 'INGRESO TURNO', ?, ?, 0, 0, ?, 'registrado')");
    $stmt->execute([$total_despachos, Session::get('usuario')]);

    echo json_encode(['ok' => true, 'message' => 'Turno cerrado', 'total_despachos' => $total_despachos], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
