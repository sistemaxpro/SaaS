<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    Session::requireLogin();
    Permission::requireAccess('app_playero_estacion');
    $pdo = Database::getSessionEmpresaConnection();
    $id_usuario = Session::getIdLogin();
    $data = json_decode(file_get_contents('php://input'), true);
    $modo_registro = in_array(($data['modo_registro'] ?? 'manual'), ['manual', 'automatico'], true) ? $data['modo_registro'] : 'manual';

    $stmt = $pdo->prepare("SELECT id FROM estacion_turnos WHERE id_usuario = ? AND DATE(fecha_turno) = CURDATE() AND estado = 'abierto'");
    $stmt->execute([$id_usuario]);
    $turno = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$turno) throw new Exception('No hay turno abierto');

    $id_pico = (int)($data['id_pico'] ?? 0);
    $id_combustible = (int)($data['id_combustible'] ?? 0);
    $litros = (float)($data['litros'] ?? 0);
    $monto_total = (float)($data['monto_total'] ?? 0);
    $precio_unitario = (float)($data['precio_unitario'] ?? 0);

    if ($id_pico <= 0 || $id_combustible <= 0 || $litros <= 0 || $monto_total <= 0) {
        throw new Exception('Datos incompletos');
    }

    $stmt = $pdo->prepare("INSERT INTO estacion_despachos (id_turno, id_pico, id_usuario, fecha_hora, litros, monto_total, precio_unitario, id_combustible, modo_registro) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?)");
    $stmt->execute([$turno['id'], $id_pico, $id_usuario, $litros, $monto_total, $precio_unitario, $id_combustible, $modo_registro]);

    echo json_encode([
        'ok' => true,
        'message' => 'Despacho registrado',
        'modo_registro' => $modo_registro
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
