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
    $useContactos = tallerHasTable($pdo, $db, 'clientes')
        && tallerHasColumn($pdo, $db, 'clientes', 'id')
        && tallerHasColumn($pdo, $db, 'clientes', 'nombre');

    if ($method === 'GET') {
        $search = trim((string)($_GET['search'] ?? ''));
        $params = [];

        if ($useContactos) {
            $telefonoExpr = tallerHasColumn($pdo, $db, 'clientes', 'telefono') ? "c.telefono" : "''";
            $docExpr = tallerHasColumn($pdo, $db, 'clientes', 'numero')
                ? "c.numero"
                : (tallerHasColumn($pdo, $db, 'clientes', 'documento') ? "c.documento" : "''");
            $direccionExpr = tallerHasColumn($pdo, $db, 'clientes', 'direccion') ? "c.direccion" : "''";
            $whereEstado = tallerHasColumn($pdo, $db, 'clientes', 'estado') ? "AND c.estado = 1" : "";
            $whereCuenta = tallerHasColumn($pdo, $db, 'clientes', 'cuenta') ? "AND c.cuenta = 3" : "";
            $whereSearch = "";
            if ($search !== '') {
                $whereSearch = "AND (c.nombre LIKE :q OR {$telefonoExpr} LIKE :q OR {$docExpr} LIKE :q)";
                $params[':q'] = "%{$search}%";
            }

            $sql = "SELECT c.id AS id_cliente, c.nombre, {$telefonoExpr} AS telefono, {$docExpr} AS documento, {$direccionExpr} AS direccion
                    FROM {$db}.clientes c
                    WHERE 1=1 {$whereEstado} {$whereCuenta} {$whereSearch}
                    ORDER BY c.nombre ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } else {
            $where = "WHERE c.activo = 1";
            if ($search !== '') {
                $where .= " AND (c.nombre LIKE :q OR c.telefono LIKE :q OR c.documento LIKE :q)";
                $params[':q'] = "%{$search}%";
            }
            $stmt = $pdo->prepare("SELECT c.* FROM {$db}.taller_clientes c {$where} ORDER BY c.nombre ASC");
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }
        exit;
    }

    $input = tallerJsonInput();
    $action = (string)($input['action'] ?? '');
    $item = $input['cliente'] ?? [];

    if ($action === 'create') {
        tallerCan('priv_insert');
        $nombre = trim((string)($item['nombre'] ?? ''));
        if ($nombre === '') throw new Exception('Nombre requerido');
        if ($useContactos) {
            $cols = tallerTableColumns($pdo, $db, 'clientes');
            $numero = trim((string)($item['documento'] ?? ''));
            if ($numero === '') {
                $numero = 'TALLER-' . date('YmdHis');
            }
            $documentoTipo = 11; // RUC por defecto
            $telefono = trim((string)($item['telefono'] ?? ''));
            $direccion = trim((string)($item['direccion'] ?? ''));

            $data = [
                'sucursal' => 1,
                'cuenta' => 3,
                'fecha' => date('Y-m-d'),
                'documento' => $documentoTipo,
                'numero' => $numero,
                'nombre' => $nombre,
                'direccion' => $direccion,
                'estado' => 1,
                'raiting' => 1,
                'obs' => '',
                'telefono' => $telefono,
            ];
            $insertCols = [];
            $placeholders = [];
            $params = [];
            foreach ($data as $col => $val) {
                if (in_array($col, $cols, true)) {
                    $insertCols[] = $col;
                    $ph = ':' . $col;
                    $placeholders[] = $ph;
                    $params[$ph] = $val;
                }
            }
            if (in_array('llave', $cols, true)) {
                $insertCols[] = 'llave';
                $placeholders[] = ':llave';
                $params[':llave'] = "3.{$documentoTipo}.{$numero}";
            }

            if (in_array('numero', $cols, true)) {
                $stmtDup = $pdo->prepare("SELECT id FROM {$db}.clientes WHERE numero = :n LIMIT 1");
                $stmtDup->execute([':n' => $numero]);
                if ($stmtDup->fetch()) {
                    throw new Exception("Ya existe un contacto con documento '{$numero}'");
                }
            }

            $sql = "INSERT INTO {$db}.clientes (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Cliente creado en Contactos']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO {$db}.taller_clientes (nombre, telefono, documento, direccion, activo) VALUES (:n,:t,:d,:dir,1)");
            $stmt->execute([
                ':n' => $nombre,
                ':t' => trim((string)($item['telefono'] ?? '')),
                ':d' => trim((string)($item['documento'] ?? '')),
                ':dir' => trim((string)($item['direccion'] ?? '')),
            ]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Cliente creado']);
        }
        exit;
    }

    if ($action === 'update') {
        tallerCan('priv_update');
        $id = (int)($item['id_cliente'] ?? 0);
        if ($id <= 0) throw new Exception('ID inválido');
        if ($useContactos) {
            $cols = tallerTableColumns($pdo, $db, 'clientes');
            $sets = [];
            $params = [':id' => $id];

            $map = [
                'nombre' => trim((string)($item['nombre'] ?? '')),
                'telefono' => trim((string)($item['telefono'] ?? '')),
                'direccion' => trim((string)($item['direccion'] ?? '')),
                'numero' => trim((string)($item['documento'] ?? '')),
            ];
            foreach ($map as $col => $val) {
                if (in_array($col, $cols, true)) {
                    $ph = ':' . $col;
                    $sets[] = "{$col} = {$ph}";
                    $params[$ph] = $val;
                }
            }
            if (in_array('llave', $cols, true) && in_array('numero', $cols, true)) {
                $numero = trim((string)($item['documento'] ?? ''));
                $sets[] = "llave = :llave";
                $params[':llave'] = "3.11.{$numero}";
            }
            if (empty($sets)) {
                throw new Exception('No hay campos para actualizar');
            }
            $sql = "UPDATE {$db}.clientes SET " . implode(', ', $sets) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'message' => 'Cliente actualizado en Contactos']);
        } else {
            $stmt = $pdo->prepare("UPDATE {$db}.taller_clientes SET nombre=:n, telefono=:t, documento=:d, direccion=:dir WHERE id_cliente=:id");
            $stmt->execute([
                ':id' => $id,
                ':n' => trim((string)($item['nombre'] ?? '')),
                ':t' => trim((string)($item['telefono'] ?? '')),
                ':d' => trim((string)($item['documento'] ?? '')),
                ':dir' => trim((string)($item['direccion'] ?? '')),
            ]);
            echo json_encode(['success' => true, 'message' => 'Cliente actualizado']);
        }
        exit;
    }

    if ($action === 'delete') {
        tallerCan('priv_delete');
        $id = (int)($input['id_cliente'] ?? 0);
        if ($id <= 0) throw new Exception('ID inválido');
        if ($useContactos && tallerHasColumn($pdo, $db, 'clientes', 'estado')) {
            $pdo->prepare("UPDATE {$db}.clientes SET estado = 0 WHERE id = :id")->execute([':id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Cliente desactivado en Contactos']);
        } else {
            $pdo->prepare("UPDATE {$db}.taller_clientes SET activo = 0 WHERE id_cliente = :id")->execute([':id' => $id]);
            echo json_encode(['success' => true, 'message' => 'Cliente suprimido']);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Accion no valida']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
