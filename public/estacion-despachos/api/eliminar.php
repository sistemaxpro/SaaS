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

    // Verificar que el despacho pertenece al usuario
    $stmt = $pdo->prepare("SELECT ed.id FROM estacion_despachos ed JOIN estacion_turnos et ON ed.id_turno = et.id WHERE ed.id = ? AND et.id_usuario = ? AND DATE(et.fecha_turno) = CURDATE()");
    $stmt->execute([$id_despacho, $id_usuario]);
    if (!$stmt->fetchColumn()) {
        throw new Exception('No tienes permiso para eliminar este despacho');
    }

    $stmt = $pdo->prepare("DELETE FROM estacion_despachos WHERE id = ?");
    $stmt->execute([$id_despacho]);

    echo json_encode(['ok' => true, 'message' => 'Despacho eliminado'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
