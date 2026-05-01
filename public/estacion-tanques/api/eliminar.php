<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    Session::requireLogin();
    Permission::requireAccess('app_grid_estacion');
    $pdo = Database::getSessionEmpresaConnection();
    $data = json_decode(file_get_contents('php://input'), true);

    $id_tanque = (int)($data['id'] ?? 0);
    if ($id_tanque <= 0) {
        throw new Exception('ID de tanque inválido');
    }

    // Verificar que existe
    $stmt = $pdo->prepare("SELECT id FROM estacion_tanques WHERE id = ?");
    $stmt->execute([$id_tanque]);
    if (!$stmt->fetchColumn()) {
        throw new Exception('Tanque no existe');
    }

    // Desactivar
    $stmt = $pdo->prepare("UPDATE estacion_tanques SET activo = 'N' WHERE id = ?");
    $stmt->execute([$id_tanque]);

    echo json_encode(['ok' => true, 'message' => 'Tanque desactivado'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
