<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/common.php';

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    exit;
}

$action = (string)($input['action'] ?? 'create');
if ($action === 'create') sx_suc_can('priv_insert');
else sx_suc_can('priv_update');

$idEmpresa = (int)($input['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$s = (array)($input['sucursal'] ?? []);

$nombre = trim((string)($s['sucursal'] ?? ''));
if ($nombre === '') {
    echo json_encode(['ok' => false, 'error' => 'El nombre de sucursal es obligatorio']);
    exit;
}

try {
    $conn = sx_suc_get_conn($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $cols = sx_suc_columns($pdo, $db);

    $idSucursal = (int)($s['id_sucursal'] ?? 0);
    $pais = trim((string)($s['pais'] ?? ''));
    $ciudad = trim((string)($s['ciudad'] ?? ''));
    $direccion = trim((string)($s['direccion'] ?? ''));
    $telefono = trim((string)($s['telefono'] ?? ''));
    $activo = (int)($s['activo'] ?? 1) === 1 ? 1 : 0;

    if ($action === 'create') {
        $fields = ['sucursal'];
        $values = [':sucursal'];
        $params = [':sucursal' => $nombre];

        foreach (['pais','ciudad','direccion','telefono'] as $c) {
            if (isset($cols[$c])) {
                $fields[] = $c;
                $values[] = ':' . $c;
                $params[':' . $c] = ${$c};
            }
        }
        if (isset($cols['activo'])) {
            $fields[] = 'activo';
            $values[] = ':activo';
            $params[':activo'] = $activo;
        }

        $sql = "INSERT INTO {$db}.sucursales (" . implode(',', $fields) . ") VALUES (" . implode(',', $values) . ")";
        $st = $pdo->prepare($sql);
        $st->execute($params);

        echo json_encode(['ok' => true, 'msg' => 'Sucursal creada', 'id_sucursal' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($idSucursal <= 0) {
        echo json_encode(['ok' => false, 'error' => 'ID de sucursal inválido']);
        exit;
    }

    $sets = ['sucursal = :sucursal'];
    $params = [':sucursal' => $nombre, ':id' => $idSucursal];
    foreach (['pais','ciudad','direccion','telefono'] as $c) {
        if (isset($cols[$c])) {
            $sets[] = "{$c} = :{$c}";
            $params[':' . $c] = ${$c};
        }
    }
    if (isset($cols['activo'])) {
        $sets[] = 'activo = :activo';
        $params[':activo'] = $activo;
    }

    $sql = "UPDATE {$db}.sucursales SET " . implode(', ', $sets) . " WHERE id_sucursal = :id";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    echo json_encode(['ok' => true, 'msg' => 'Sucursal actualizada']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
