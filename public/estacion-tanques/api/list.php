<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    Session::requireLogin();
    Permission::requireAccess('app_grid_estacion');
    $pdo = Database::getSessionEmpresaConnection();

    $page = (int)($_GET['page'] ?? 1);
    $limit = 25;
    $offset = ($page - 1) * $limit;

    $stmt = $pdo->query("SELECT COUNT(*) FROM estacion_tanques WHERE activo = 'Y'");
    $total = (int)$stmt->fetchColumn();

    $stmt = $pdo->query("SELECT t.*, c.nombre as combustible FROM estacion_tanques t LEFT JOIN estacion_combustibles c ON t.id_combustible = c.id WHERE t.activo = 'Y' ORDER BY t.nombre LIMIT $limit OFFSET $offset");
    $tanques = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Agregar última lectura a cada tanque
    foreach ($tanques as &$tanque) {
        $stmt = $pdo->prepare("SELECT litros_medidos, cms_medidos, fecha_hora FROM estacion_lecturas_tanque WHERE id_tanque = ? ORDER BY fecha_hora DESC LIMIT 1");
        $stmt->execute([$tanque['id']]);
        $lectura = $stmt->fetch(PDO::FETCH_ASSOC);
        $tanque['ultima_lectura'] = $lectura;
    }

    echo json_encode([
        'ok' => true,
        'data' => $tanques,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
