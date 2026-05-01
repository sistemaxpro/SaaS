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
    if ($idEmpresa <= 0) {
        throw new Exception('Empresa no válida');
    }

    $conn = sx_picos_get_conn($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    $id = (int)($input['id'] ?? 0);
    $idSurtidor = (int)($input['id_surtidor'] ?? 0);
    $nroPico = (int)($input['nro_pico'] ?? 0);
    $nombre = trim((string)($input['nombre'] ?? ''));
    $idCombustible = (int)($input['id_combustible'] ?? 0);
    $idTanque = (int)($input['id_tanque'] ?? 0);
    $totalizadorActual = (float)($input['totalizador_actual'] ?? 0);
    $activo = trim((string)($input['activo'] ?? 'Y')) === 'N' ? 'N' : 'Y';

    if ($idSurtidor <= 0 || $nroPico <= 0 || $idCombustible <= 0 || $idTanque <= 0) {
        throw new Exception('Datos incompletos');
    }
    if ($nombre === '') {
        $nombre = 'Pico ' . $nroPico;
    }

    $stmt = $pdo->prepare("SELECT id FROM {$db}.estacion_surtidores WHERE id = ? LIMIT 1");
    $stmt->execute([$idSurtidor]);
    if (!$stmt->fetchColumn()) {
        throw new Exception('Surtidor no encontrado');
    }

    $stmt = $pdo->prepare("SELECT id FROM {$db}.estacion_tanques WHERE id = ? LIMIT 1");
    $stmt->execute([$idTanque]);
    if (!$stmt->fetchColumn()) {
        throw new Exception('Tanque no encontrado');
    }

    $stmt = $pdo->prepare("SELECT id FROM {$db}.estacion_combustibles WHERE id = ? LIMIT 1");
    $stmt->execute([$idCombustible]);
    if (!$stmt->fetchColumn()) {
        throw new Exception('Combustible no encontrado');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE {$db}.estacion_picos
            SET id_surtidor = ?, nro_pico = ?, nombre = ?, id_combustible = ?, id_tanque = ?, totalizador_actual = ?, activo = ?
            WHERE id = ?
        ");
        $stmt->execute([$idSurtidor, $nroPico, $nombre, $idCombustible, $idTanque, $totalizadorActual, $activo, $id]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO {$db}.estacion_picos
                (id_surtidor, nro_pico, nombre, id_combustible, id_tanque, totalizador_actual, activo)
            VALUES
                (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$idSurtidor, $nroPico, $nombre, $idCombustible, $idTanque, $totalizadorActual, $activo]);
    }

    echo json_encode(['ok' => true, 'message' => 'Pico guardado'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
