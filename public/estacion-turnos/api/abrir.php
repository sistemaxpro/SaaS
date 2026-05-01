<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    Session::requireLogin();
    Permission::requireAccess('app_playero_estacion');
    $pdo = Database::getSessionEmpresaConnection();
    $id_usuario = Session::getIdLogin();
    $data = json_decode(file_get_contents('php://input'), true);

    // Verificar que no haya otro turno abierto hoy
    $stmt = $pdo->prepare("SELECT id FROM estacion_turnos WHERE id_usuario = ? AND DATE(fecha_turno) = CURDATE() AND estado = 'abierto'");
    $stmt->execute([$id_usuario]);
    if ($stmt->fetch()) {
        throw new Exception('Ya tienes un turno abierto hoy');
    }

    $efectivo_apertura = (float)($data['efectivo_apertura'] ?? 0);
    $observacion = trim($data['observacion'] ?? '');

    $stmt = $pdo->prepare("INSERT INTO estacion_turnos (id_usuario, fecha_turno, hora_apertura, estado, efectivo_apertura, observacion_apertura) VALUES (?, CURDATE(), NOW(), 'abierto', ?, ?)");
    $stmt->execute([$id_usuario, $efectivo_apertura, $observacion]);

    echo json_encode(['ok' => true, 'message' => 'Turno abierto'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
