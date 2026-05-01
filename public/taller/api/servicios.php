<?php
require_once __DIR__ . '/common.php';

$method = $_SERVER['REQUEST_METHOD'];
$idEmpresa = (int)(($method === 'GET' ? ($_GET['id_empresa'] ?? 0) : null) ?? ($_SESSION['id_empresa'] ?? 0));
if ($idEmpresa <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'id_empresa requerido']);
    exit;
}

try {
    $conn = tallerConn($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT * FROM {$db}.taller_servicios WHERE activo = 1 ORDER BY servicio ASC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    $input = tallerJsonInput();
    $action = (string)($input['action'] ?? '');
    $item = $input['servicio'] ?? [];

    if ($action === 'create') {
        tallerCan('priv_insert');
        $nombre = trim((string)($item['servicio'] ?? ''));
        if ($nombre === '') throw new Exception('Servicio requerido');
        $stmt = $pdo->prepare("INSERT INTO {$db}.taller_servicios (servicio, descripcion, costo_base, duracion_horas, activo)
                               VALUES (:s,:d,:c,:h,1)");
        $stmt->execute([
            ':s' => $nombre,
            ':d' => trim((string)($item['descripcion'] ?? '')),
            ':c' => (float)($item['costo_base'] ?? 0),
            ':h' => (float)($item['duracion_horas'] ?? 1),
        ]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Servicio creado']);
        exit;
    }

    if ($action === 'update') {
        tallerCan('priv_update');
        $id = (int)($item['id_servicio'] ?? 0);
        if ($id <= 0) throw new Exception('ID inválido');
        $stmt = $pdo->prepare("UPDATE {$db}.taller_servicios
                               SET servicio=:s, descripcion=:d, costo_base=:c, duracion_horas=:h
                               WHERE id_servicio=:id");
        $stmt->execute([
            ':id' => $id,
            ':s' => trim((string)($item['servicio'] ?? '')),
            ':d' => trim((string)($item['descripcion'] ?? '')),
            ':c' => (float)($item['costo_base'] ?? 0),
            ':h' => (float)($item['duracion_horas'] ?? 1),
        ]);
        echo json_encode(['success' => true, 'message' => 'Servicio actualizado']);
        exit;
    }

    if ($action === 'delete') {
        tallerCan('priv_delete');
        $id = (int)($input['id_servicio'] ?? 0);
        if ($id <= 0) throw new Exception('ID inválido');
        $pdo->prepare("UPDATE {$db}.taller_servicios SET activo = 0 WHERE id_servicio = :id")->execute([':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Servicio suprimido']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Accion no valida']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

