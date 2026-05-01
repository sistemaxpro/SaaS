<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    Session::requireLogin();
    Permission::requireAccess('app_grid_estacion');
    $pdo = Database::getSessionEmpresaConnection();
    $id_usuario = Session::getIdLogin();
    $data = json_decode(file_get_contents('php://input'), true);

    $id_tanque = (int)($data['id_tanque'] ?? 0);
    $litros_medidos = (float)($data['litros_medidos'] ?? 0);
    $cms_medidos = (float)($data['cms_medidos'] ?? 0);
    $observacion = (string)($data['observacion'] ?? '');

    if ($id_tanque <= 0 || ($litros_medidos <= 0 && $cms_medidos <= 0)) {
        throw new Exception('Datos incompletos o inválidos');
    }

    // Verificar que el tanque existe
    $stmt = $pdo->prepare("SELECT id FROM estacion_tanques WHERE id = ?");
    $stmt->execute([$id_tanque]);
    if (!$stmt->fetchColumn()) {
        throw new Exception('Tanque no existe');
    }

    $stmt = $pdo->prepare("INSERT INTO estacion_lecturas_tanque (id_tanque, fecha_hora, litros_medidos, cms_medidos, tipo, registrado_por, observacion) VALUES (?, NOW(), ?, ?, 'manual', ?, ?)");
    $stmt->execute([$id_tanque, $litros_medidos > 0 ? $litros_medidos : NULL, $cms_medidos > 0 ? $cms_medidos : NULL, $id_usuario, $observacion]);

    echo json_encode(['ok' => true, 'message' => 'Lectura registrada correctamente'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
