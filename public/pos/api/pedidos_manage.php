<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';

$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'list')));
$idEmpresa = (int)($_GET['id_empresa'] ?? $_POST['id_empresa'] ?? $_SESSION['id_empresa'] ?? 0);

try {
    if ($idEmpresa <= 0) {
        throw new Exception('Empresa no definida');
    }

    $conn = getEmpresaConnection($idEmpresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];
    ensurePedidoTablesCompat($pdo, $dbName);

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
        $query = trim((string)($_GET['q'] ?? ''));
        $estado = strtolower(trim((string)($_GET['estado'] ?? 'activo')));
        $periodo = strtolower(trim((string)($_GET['periodo'] ?? 'mes')));
        $fechaDesde = trim((string)($_GET['fecha_desde'] ?? ''));
        $fechaHasta = trim((string)($_GET['fecha_hasta'] ?? ''));
        $limit = min(300, max(20, (int)($_GET['limit'] ?? 150)));

        $where = [];
        $params = [];

        if ($estado === 'activo') {
            $where[] = 'COALESCE(p.estado, 1) = 1';
        } elseif ($estado === 'anulado') {
            $where[] = 'COALESCE(p.estado, 1) = 0';
        }

        if ($query !== '') {
            $where[] = "(CAST(p.id_pedido AS CHAR) LIKE :q OR c.nombre LIKE :q OR c.numero LIKE :q OR CAST(p.importe AS CHAR) LIKE :q)";
            $params[':q'] = '%' . $query . '%';
        }

        switch ($periodo) {
            case 'hoy':
                $where[] = 'DATE(p.fecha) = CURDATE()';
                break;
            case 'semana':
                $where[] = 'DATE(p.fecha) >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)';
                break;
            case 'anio':
                $where[] = 'YEAR(p.fecha) = YEAR(CURDATE())';
                break;
            case 'custom':
                if ($fechaDesde !== '') {
                    $where[] = 'DATE(p.fecha) >= :fecha_desde';
                    $params[':fecha_desde'] = $fechaDesde;
                }
                if ($fechaHasta !== '') {
                    $where[] = 'DATE(p.fecha) <= :fecha_hasta';
                    $params[':fecha_hasta'] = $fechaHasta;
                }
                break;
            case 'todo':
                break;
            case 'mes':
            default:
                $where[] = 'YEAR(p.fecha) = YEAR(CURDATE()) AND MONTH(p.fecha) = MONTH(CURDATE())';
                break;
        }

        $sql = "
            SELECT
                p.id_pedido AS id_factura,
                CONCAT('PED-', LPAD(p.id_pedido, 7, '0')) AS nro_factura,
                p.fecha,
                p.importe AS total,
                COALESCE(p.estado, 1) AS estado,
                COALESCE(c.nombre, 'Proveedor pendiente') AS cliente_nombre,
                COALESCE(c.numero, '-') AS cliente_ruc
            FROM {$dbName}.pedidos p
            LEFT JOIN {$dbName}.clientes c ON c.id = p.id_cliente
        ";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY p.id_pedido DESC LIMIT {$limit}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode([
            'success' => true,
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'load') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('ID inválido');
        }

        $stmtHead = $pdo->prepare("
            SELECT
                p.id_pedido, p.fecha, p.importe, p.id_cliente,
                c.id AS cliente_id, c.nombre AS cliente_nombre, c.numero AS cliente_ruc,
                c.email AS cliente_email, c.telefono AS cliente_telefono
            FROM {$dbName}.pedidos p
            LEFT JOIN {$dbName}.clientes c ON c.id = p.id_cliente
            WHERE p.id_pedido = :id
            LIMIT 1
        ");
        $stmtHead->execute([':id' => $id]);
        $doc = $stmtHead->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new Exception('Pedido no encontrado');
        }

        $stmtItems = $pdo->prepare("
            SELECT
                ip.id_item,
                ip.id_producto,
                ip.cantidad,
                ip.precio,
                ip.importe,
                tp.cve_producto AS codigo,
                tp.desproducto AS descripcion,
                tp.iva AS tasa_iva,
                tp.saldo AS stock_actual,
                tp.controla_stock,
                tp.vende_sin_stock
            FROM {$dbName}.item_pedidos ip
            LEFT JOIN {$dbName}.tblproductos tp ON tp.idproducto = ip.id_producto
            WHERE ip.id_pedido = :id
            ORDER BY ip.id_item ASC
        ");
        $stmtItems->execute([':id' => $id]);
        $items = [];
        foreach (($stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: []) as $item) {
            $items[] = [
                'id' => (int)($item['id_producto'] ?? 0),
                'extracto_id' => (int)($item['id_item'] ?? 0),
                'codigo' => (string)($item['codigo'] ?? ''),
                'descripcion' => (string)($item['descripcion'] ?? ('Producto #' . (int)($item['id_producto'] ?? 0))),
                'cantidad' => (float)($item['cantidad'] ?? 0),
                'precio' => (float)($item['precio'] ?? 0),
                'costo' => 0.0,
                'tasa_iva' => (int)normalizePedidoIvaRate($item['tasa_iva'] ?? 10),
                'stock' => (float)($item['stock_actual'] ?? 0),
                'controla_stock' => $item['controla_stock'] ?? -1,
                'vende_sin_stock' => $item['vende_sin_stock'] ?? 1,
            ];
        }

        $cliente = null;
        if ((int)($doc['id_cliente'] ?? 0) > 0) {
            $cliente = [
                'id' => (int)($doc['cliente_id'] ?? 0),
                'nombre' => (string)($doc['cliente_nombre'] ?? ''),
                'ruc' => (string)($doc['cliente_ruc'] ?? ''),
                'email' => (string)($doc['cliente_email'] ?? ''),
                'telefono' => (string)($doc['cliente_telefono'] ?? '')
            ];
        }

        echo json_encode([
            'success' => true,
            'venta' => [
                'id_factura' => $id,
                'nro_factura' => 'PED-' . str_pad((string)$id, 7, '0', STR_PAD_LEFT),
                'fecha' => (string)($doc['fecha'] ?? ''),
                'total' => (float)($doc['importe'] ?? 0),
                'tipo_documento' => 'pedido',
                'forma_pago' => 'pedido',
                'id_cliente' => (int)($doc['id_cliente'] ?? 0)
            ],
            'cliente' => $cliente,
            'items' => $items
        ]);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'anular') {
        $id = (int)($payload['id_factura'] ?? 0);
        if ($id <= 0) {
            throw new Exception('ID inválido');
        }
        $stmt = $pdo->prepare("UPDATE {$dbName}.pedidos SET estado = 0, fecha_anulacion = NOW() WHERE id_pedido = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Pedido anulado correctamente']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
        $id = (int)($payload['id_factura'] ?? 0);
        $items = $payload['items'] ?? [];
        if ($id <= 0) {
            throw new Exception('ID inválido');
        }
        if (!is_array($items) || !$items) {
            throw new Exception('El pedido está vacío');
        }

        $stmtCurrent = $pdo->prepare("SELECT * FROM {$dbName}.pedidos WHERE id_pedido = :id LIMIT 1");
        $stmtCurrent->execute([':id' => $id]);
        $current = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            throw new Exception('Pedido no encontrado');
        }

        $idCliente = (int)($payload['id_cliente'] ?? 0);
        $clienteNombre = '';
        $clienteRuc = '';
        if ($idCliente > 0) {
            $stmtCli = $pdo->prepare("SELECT nombre, numero FROM {$dbName}.clientes WHERE id = :id LIMIT 1");
            $stmtCli->execute([':id' => $idCliente]);
            $cli = $stmtCli->fetch(PDO::FETCH_ASSOC) ?: [];
            $clienteNombre = trim((string)($cli['nombre'] ?? ''));
            $clienteRuc = trim((string)($cli['numero'] ?? ''));
        }
        if ($clienteNombre === '') $clienteNombre = 'Proveedor pendiente';
        if ($clienteRuc === '') $clienteRuc = '-';

        $total = 0.0;
        foreach ($items as $item) {
            $cantidad = (float)($item['cantidad'] ?? 0);
            $precio = (float)($item['precio'] ?? 0);
            if ($cantidad <= 0) continue;
            $total += round($cantidad * $precio, 2);
        }
        if ($total <= 0) {
            throw new Exception('El total del pedido es inválido');
        }

        $pdo->beginTransaction();
        try {
            $stmtUp = $pdo->prepare("
                UPDATE {$dbName}.pedidos
                SET id_cliente = :id_cliente,
                    importe = :importe,
                    estado = 1
                WHERE id_pedido = :id
            ");
            $stmtUp->execute([
                ':id_cliente' => $idCliente > 0 ? $idCliente : null,
                ':importe' => round($total, 2),
                ':id' => $id
            ]);

            $pdo->prepare("DELETE FROM {$dbName}.item_pedidos WHERE id_pedido = :id")->execute([':id' => $id]);
            $stmtItem = $pdo->prepare("
                INSERT INTO {$dbName}.item_pedidos
                    (id_pedido, id_sucursal, id_cliente, fecha, id_producto, cantidad, precio, importe, id_login)
                VALUES
                    (:id_pedido, :id_sucursal, :id_cliente, :fecha, :id_producto, :cantidad, :precio, :importe, :id_login)
            ");

            foreach ($items as $item) {
                $cantidad = (float)($item['cantidad'] ?? 0);
                if ($cantidad <= 0) continue;
                $precio = (float)($item['precio'] ?? 0);
                $importe = round($cantidad * $precio, 2);
                $stmtItem->execute([
                    ':id_pedido' => $id,
                    ':id_sucursal' => (int)($current['id_sucursal'] ?? ($_SESSION['id_sucursal'] ?? 1)),
                    ':id_cliente' => $idCliente > 0 ? $idCliente : null,
                    ':fecha' => (string)($current['fecha'] ?? date('Y-m-d H:i:s')),
                    ':id_producto' => ((int)($item['id'] ?? 0)) > 0 ? (int)$item['id'] : null,
                    ':cantidad' => $cantidad,
                    ':precio' => round($precio, 2),
                    ':importe' => $importe,
                    ':id_login' => (int)($_SESSION['id_login'] ?? 0),
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Pedido actualizado correctamente',
            'data' => [
                'id_factura' => $id,
                'id_pedido' => $id,
                'nro_factura' => 'PED-' . str_pad((string)$id, 7, '0', STR_PAD_LEFT),
                'fecha' => (string)($current['fecha'] ?? date('Y-m-d H:i:s')),
                'total' => round($total, 2),
                'cliente' => $clienteNombre,
                'cliente_ruc' => $clienteRuc,
                'tipo_documento' => 'pedido',
                'medio_cobro' => 'pedido',
                'items' => $items
            ]
        ]);
        exit;
    }

    throw new Exception('Acción no válida');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function normalizePedidoIvaRate($raw): int
{
    $v = (int)$raw;
    if ($v === 1 || $v === 0) return 0;
    if ($v === 2 || $v === 5) return 5;
    if ($v === 3 || $v === 10) return 10;
    return 10;
}

function ensurePedidoTablesCompat(PDO $pdo, string $dbName): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$dbName}.pedidos (
            id_pedido int NOT NULL AUTO_INCREMENT,
            fecha datetime DEFAULT NULL,
            id_sucursal int DEFAULT NULL,
            id_login int DEFAULT NULL,
            id_cliente int DEFAULT NULL,
            importe decimal(12,2) DEFAULT NULL,
            estado tinyint(1) DEFAULT 1,
            fecha_anulacion datetime DEFAULT NULL,
            PRIMARY KEY (id_pedido)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1
    ");

    $cols = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM {$dbName}.pedidos")->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $cols[strtolower((string)$col['Field'])] = true;
        }
    } catch (Throwable $e) {
    }
    if (!isset($cols['estado'])) {
        $pdo->exec("ALTER TABLE {$dbName}.pedidos ADD COLUMN estado tinyint(1) DEFAULT 1");
    }
    if (!isset($cols['fecha_anulacion'])) {
        $pdo->exec("ALTER TABLE {$dbName}.pedidos ADD COLUMN fecha_anulacion datetime DEFAULT NULL");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$dbName}.item_pedidos (
            id_item int NOT NULL AUTO_INCREMENT,
            id_pedido int DEFAULT NULL,
            id_sucursal int DEFAULT NULL,
            id_cliente int DEFAULT NULL,
            fecha datetime DEFAULT NULL,
            id_producto int DEFAULT NULL,
            cantidad decimal(12,2) DEFAULT NULL,
            precio decimal(12,2) DEFAULT NULL,
            importe decimal(12,2) DEFAULT NULL,
            id_login int DEFAULT NULL,
            PRIMARY KEY (id_item)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1
    ");
}
