<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    Session::requireLogin();
    Permission::requireAccess('app_grid_estacion');
    $pdo = Database::getSessionEmpresaConnection();
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    $nombre = trim($data['nombre'] ?? '');
    $id_combustible = (int)($data['id_combustible'] ?? 0);
    $capacidad_litros = (float)($data['capacidad_litros'] ?? 0);
    $nivel_minimo_alerta = (float)($data['nivel_minimo_alerta'] ?? 0);
    $tipo_medicion = in_array($data['tipo_medicion'] ?? '', ['manual', 'sensor']) ? $data['tipo_medicion'] : 'manual';
    $sensor_ip = trim($data['sensor_ip'] ?? '');
    $sensor_puerto = !empty($data['sensor_puerto']) ? (int)$data['sensor_puerto'] : null;

    if ($nombre === '' || $id_combustible <= 0 || $capacidad_litros <= 0) {
        throw new Exception('Datos incompletos');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE estacion_tanques SET nombre = ?, id_combustible = ?, capacidad_litros = ?, nivel_minimo_alerta = ?, tipo_medicion = ?, sensor_ip = ?, sensor_puerto = ? WHERE id = ?");
        $stmt->execute([$nombre, $id_combustible, $capacidad_litros, $nivel_minimo_alerta, $tipo_medicion, $sensor_ip ?: null, $sensor_puerto, $id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO estacion_tanques (nombre, id_combustible, capacidad_litros, nivel_minimo_alerta, tipo_medicion, sensor_ip, sensor_puerto) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$nombre, $id_combustible, $capacidad_litros, $nivel_minimo_alerta, $tipo_medicion, $sensor_ip ?: null, $sensor_puerto]);
    }
    echo json_encode(['ok' => true, 'message' => 'Tanque guardado'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
