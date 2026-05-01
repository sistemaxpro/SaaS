<?php
require_once __DIR__ . '/common.php';

try {
    Session::requireLogin();
    Permission::requireAccess('app_grid_estacion');

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('JSON inválido');
    }

    $idEmpresa = (int)($input['id_empresa'] ?? Session::getIdEmpresa() ?? 0);
    $id = (int)($input['id'] ?? 0);
    if ($idEmpresa <= 0 || $id <= 0) {
        throw new Exception('Datos inválidos');
    }

    $conn = sx_picos_get_conn($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$db}.estacion_despachos WHERE id_pico = ?");
    $stmt->execute([$id]);
    if ((int)$stmt->fetchColumn() > 0) {
        $stmt = $pdo->prepare("UPDATE {$db}.estacion_picos SET activo = 'N' WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true, 'message' => 'Pico desactivado porque tiene despachos asociados'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM {$db}.estacion_picos WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode(['ok' => true, 'message' => 'Pico eliminado'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
