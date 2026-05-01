<?php
/**
 * API: Guardar surtidor (crear/editar)
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    Session::requireLogin();
    Permission::requireAccess('app_grid_estacion');

    $pdo = Database::getSessionEmpresaConnection();

    $data = json_decode(file_get_contents('php://input'), true);

    $id = (int)($data['id'] ?? 0);
    $nro_surtidor = (int)($data['nro_surtidor'] ?? 0);
    $nombre = trim($data['nombre'] ?? '');
    $tipo_control = in_array($data['tipo_control'] ?? '', ['manual', 'automatico']) ? $data['tipo_control'] : 'manual';
    $id_sucursal = !empty($data['id_sucursal']) ? (int)$data['id_sucursal'] : null;
    $controladora_ip = trim($data['controladora_ip'] ?? '');
    $controladora_puerto = !empty($data['controladora_puerto']) ? (int)$data['controladora_puerto'] : null;
    $controladora_protocolo = trim($data['controladora_protocolo'] ?? '');
    $activo = in_array($data['activo'] ?? 'Y', ['Y', 'N']) ? $data['activo'] : 'Y';

    if ($nro_surtidor <= 0 || $nombre === '') {
        throw new Exception('Número de surtidor y nombre son requeridos');
    }

    if ($id > 0) {
        // Editar
        $stmt = $pdo->prepare("UPDATE estacion_surtidores SET
            nro_surtidor = ?,
            nombre = ?,
            tipo_control = ?,
            id_sucursal = ?,
            controladora_ip = ?,
            controladora_puerto = ?,
            controladora_protocolo = ?,
            activo = ?
        WHERE id = ?");

        $stmt->execute([
            $nro_surtidor, $nombre, $tipo_control, $id_sucursal,
            $controladora_ip ?: null, $controladora_puerto, $controladora_protocolo ?: null,
            $activo, $id
        ]);
    } else {
        // Crear
        $stmt = $pdo->prepare("INSERT INTO estacion_surtidores
            (nro_surtidor, nombre, tipo_control, id_sucursal, controladora_ip, controladora_puerto, controladora_protocolo, activo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $nro_surtidor, $nombre, $tipo_control, $id_sucursal,
            $controladora_ip ?: null, $controladora_puerto, $controladora_protocolo ?: null,
            $activo
        ]);
    }

    echo json_encode([
        'ok' => true,
        'message' => $id > 0 ? 'Surtidor actualizado' : 'Surtidor creado'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
