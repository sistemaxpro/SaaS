<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    Session::requireLogin();
    Permission::requireAccess('app_grid_estacion');
    $pdo = Database::getSessionEmpresaConnection();
    $data = json_decode(file_get_contents('php://input'), true);

    $id_surtidor = (int)($data['id'] ?? 0);
    if ($id_surtidor <= 0) {
        throw new Exception('ID de surtidor inválido');
    }

    // Verificar que existe
    $stmt = $pdo->prepare("SELECT id FROM estacion_surtidores WHERE id = ?");
    $stmt->execute([$id_surtidor]);
    if (!$stmt->fetchColumn()) {
        throw new Exception('Surtidor no existe');
    }

    // Desactivar (soft delete)
    $stmt = $pdo->prepare("UPDATE estacion_surtidores SET activo = 'N' WHERE id = ?");
    $stmt->execute([$id_surtidor]);

    echo json_encode(['ok' => true, 'message' => 'Surtidor desactivado'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
