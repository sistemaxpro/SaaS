<?php
/**
 * API: Recibir despacho automático desde surtidor/controladora
 *
 * POST JSON:
 * {
 *   "id_empresa": 123,
 *   "id_surtidor": 5,
 *   "id_pico": 8,
 *   "id_combustible": 2,
 *   "litros": 35.123,
 *   "monto_total": 250000,
 *   "precio_unitario": 7120,
 *   "totalizador_inicio": 120000.000,
 *   "totalizador_fin": 120035.123,
 *   "nro_comprobante": "..."
 * }
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $tokenEsperado = trim((string)(getenv('ESTACION_SURTIDOR_TOKEN') ?: ''));
    $tokenRecibido = trim((string)($_SERVER['HTTP_X_ESTACION_TOKEN'] ?? ''));
    if ($tokenEsperado !== '' && !hash_equals($tokenEsperado, $tokenRecibido)) {
        throw new Exception('Token inválido');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $idEmpresa = (int)($input['id_empresa'] ?? 0);
    $idSurtidor = (int)($input['id_surtidor'] ?? 0);
    $idPico = (int)($input['id_pico'] ?? 0);
    $idCombustible = (int)($input['id_combustible'] ?? 0);
    $litros = (float)($input['litros'] ?? 0);
    $montoTotal = (float)($input['monto_total'] ?? 0);
    $precioUnitario = (float)($input['precio_unitario'] ?? 0);
    $totalizadorInicio = isset($input['totalizador_inicio']) ? (float)$input['totalizador_inicio'] : null;
    $totalizadorFin = isset($input['totalizador_fin']) ? (float)$input['totalizador_fin'] : null;
    $nroComprobante = trim((string)($input['nro_comprobante'] ?? ''));
    $observacion = trim((string)($input['observacion'] ?? 'Despacho automático desde surtidor'));

    if ($idEmpresa <= 0 || $idPico <= 0 || $idCombustible <= 0 || $litros <= 0 || $montoTotal <= 0) {
        throw new Exception('Datos incompletos');
    }

    if ($tokenEsperado === '' && !Session::isLoggedIn()) {
        throw new Exception('No autorizado: token no configurado');
    }

    if (Session::isLoggedIn()) {
        Session::requireLogin();
    }

    $pdo = Database::getEmpresaConnection($idEmpresa);

    if ($idSurtidor > 0) {
        $stmtSurtidor = $pdo->prepare("
            SELECT id, tipo_control, activo
            FROM estacion_surtidores
            WHERE id = ?
            LIMIT 1
        ");
        $stmtSurtidor->execute([$idSurtidor]);
        $surtidor = $stmtSurtidor->fetch(PDO::FETCH_ASSOC);

        if (!$surtidor) {
            throw new Exception('Surtidor no encontrado');
        }
        if (($surtidor['tipo_control'] ?? 'manual') !== 'automatico') {
            throw new Exception('El surtidor no está configurado como automático');
        }
    }

    $stmtTurno = $pdo->prepare("
        SELECT id, id_usuario
        FROM estacion_turnos
        WHERE DATE(fecha_turno) = CURDATE()
          AND estado = 'abierto'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtTurno->execute();
    $turno = $stmtTurno->fetch(PDO::FETCH_ASSOC);

    if (!$turno) {
        throw new Exception('No hay turno abierto para registrar el despacho');
    }

    $idUsuario = (int)($turno['id_usuario'] ?? 0);
    if ($idUsuario <= 0) {
        throw new Exception('El turno abierto no tiene usuario asignado');
    }

    $stmt = $pdo->prepare("
        INSERT INTO estacion_despachos
            (id_turno, id_pico, id_usuario, fecha_hora, litros, monto_total, precio_unitario, id_combustible,
             modo_registro, totalizador_inicio, totalizador_fin, nro_comprobante, estado, observacion)
        VALUES
            (?, ?, ?, NOW(), ?, ?, ?, ?, 'automatico', ?, ?, ?, 'registrado', ?)
    ");
    $stmt->execute([
        $turno['id'],
        $idPico,
        $idUsuario,
        $litros,
        $montoTotal,
        $precioUnitario,
        $idCombustible,
        $totalizadorInicio,
        $totalizadorFin,
        $nroComprobante !== '' ? $nroComprobante : null,
        $observacion !== '' ? $observacion : null,
    ]);

    echo json_encode([
        'ok' => true,
        'message' => 'Despacho automático registrado',
        'id' => (int)$pdo->lastInsertId(),
        'id_turno' => (int)$turno['id'],
        'id_usuario' => $idUsuario,
        'id_surtidor' => $idSurtidor,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
