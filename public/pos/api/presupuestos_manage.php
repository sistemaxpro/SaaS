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
require_once __DIR__ . '/schema_compat.php';

$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'list')));
$idEmpresa = (int)($_GET['id_empresa'] ?? $_POST['id_empresa'] ?? $_SESSION['id_empresa'] ?? 0);

try {
    if ($idEmpresa <= 0) {
        throw new Exception('Empresa no definida');
    }

    $conn = getEmpresaConnection($idEmpresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];
    ensurePresupuestoTablesCompat($pdo, $dbName);
    $hasPresupuestoNroFactura = tableHasColumnCompat($pdo, $dbName, 'presupuesto', 'nro_factura');
    $presupuestoNroExpr = $hasPresupuestoNroFactura
        ? 'p.nro_factura'
        : "CONCAT('PRES-', LPAD(p.id_factura, 7, '0'))";

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
            $where[] = 'COALESCE(p.estado, 1) = 1 AND p.converted_at IS NULL';
        } elseif ($estado === 'anulado') {
            $where[] = 'COALESCE(p.estado, 1) = 0';
        } elseif ($estado === 'convertido') {
            $where[] = 'p.converted_at IS NOT NULL';
        }

        if ($query !== '') {
            $where[] = "({$presupuestoNroExpr} LIKE :q OR c.nombre LIKE :q OR c.numero LIKE :q OR CAST(p.total AS CHAR) LIKE :q)";
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
                p.id_factura,
                {$presupuestoNroExpr} AS nro_factura,
                p.fecha,
                p.total,
                p.estado,
                p.converted_at,
                p.converted_factura_id,
                COALESCE(c.nombre, 'Cliente ocasional') AS cliente_nombre,
                COALESCE(c.numero, p.ruc, '-') AS cliente_ruc
            FROM {$dbName}.presupuesto p
            LEFT JOIN {$dbName}.clientes c ON c.id = p.id_cliente
        ";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY p.id_factura DESC LIMIT {$limit}";

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
                p.id_factura, {$presupuestoNroExpr} AS nro_factura, p.fecha, p.total, p.tipo_documento, p.id_cliente, p.forma_pago,
                p.converted_at, p.converted_factura_id,
                p.ruc,
                c.id AS cliente_id, c.nombre AS cliente_nombre, c.numero AS cliente_ruc,
                c.email AS cliente_email, c.telefono AS cliente_telefono,
                COALESCE(c.linea_credito, 0) AS cliente_linea_credito,
                COALESCE(c.saldo_guaranies, 0) AS cliente_saldo_guaranies
            FROM {$dbName}.presupuesto p
            LEFT JOIN {$dbName}.clientes c ON c.id = p.id_cliente
            WHERE p.id_factura = :id
            LIMIT 1
        ");
        $stmtHead->execute([':id' => $id]);
        $doc = $stmtHead->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            throw new Exception('Presupuesto no encontrado');
        }

        $stmtItems = $pdo->prepare("
            SELECT
                pi.id,
                pi.idproducto,
                pi.codigo,
                pi.descripcion,
                pi.salida AS cantidad,
                pi.precio,
                pi.costo,
                tp.iva AS tasa_iva,
                tp.saldo AS stock_actual,
                tp.controla_stock,
                tp.vende_sin_stock
            FROM {$dbName}.presupuesto_item pi
            LEFT JOIN {$dbName}.tblproductos tp ON tp.idproducto = pi.idproducto
            WHERE pi.idfactura = :id
            ORDER BY pi.id ASC
        ");
        $stmtItems->execute([':id' => $id]);
        $items = [];
        foreach (($stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: []) as $item) {
            $items[] = [
                'id' => (int)($item['idproducto'] ?? 0),
                'extracto_id' => (int)($item['id'] ?? 0),
                'codigo' => (string)($item['codigo'] ?? ''),
                'descripcion' => (string)($item['descripcion'] ?? 'Producto'),
                'cantidad' => (float)($item['cantidad'] ?? 0),
                'precio' => (float)($item['precio'] ?? 0),
                'costo' => (float)($item['costo'] ?? 0),
                'tasa_iva' => (int)normalizeDocumentoIvaRate($item['tasa_iva'] ?? 10),
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
                'telefono' => (string)($doc['cliente_telefono'] ?? ''),
                'linea_credito' => (float)($doc['cliente_linea_credito'] ?? 0),
                'saldo_guaranies' => (float)($doc['cliente_saldo_guaranies'] ?? 0),
            ];
        }

        echo json_encode([
            'success' => true,
            'venta' => [
                'id_factura' => (int)$doc['id_factura'],
                'nro_factura' => (string)$doc['nro_factura'],
                'fecha' => (string)$doc['fecha'],
                'total' => (float)$doc['total'],
                'tipo_documento' => 'presupuesto',
                'forma_pago' => (string)($doc['forma_pago'] ?? '1'),
                'id_cliente' => (int)($doc['id_cliente'] ?? 0),
                'converted_at' => $doc['converted_at'] ?? null,
                'converted_factura_id' => (int)($doc['converted_factura_id'] ?? 0)
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
        $stmt = $pdo->prepare("UPDATE {$dbName}.presupuesto SET estado = 0 WHERE id_factura = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Presupuesto anulado correctamente']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'convert') {
        $id = (int)($payload['id_factura'] ?? 0);
        $convertedFacturaId = (int)($payload['converted_factura_id'] ?? 0);
        if ($id <= 0 || $convertedFacturaId <= 0) {
            throw new Exception('Datos de conversión inválidos');
        }

        $stmt = $pdo->prepare("
            UPDATE {$dbName}.presupuesto
            SET converted_at = NOW(),
                converted_factura_id = :factura_id
            WHERE id_factura = :id
            LIMIT 1
        ");
        $stmt->execute([
            ':factura_id' => $convertedFacturaId,
            ':id' => $id
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Presupuesto marcado como convertido',
            'data' => [
                'id_factura' => $id,
                'converted_factura_id' => $convertedFacturaId
            ]
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
        $id = (int)($payload['id_factura'] ?? 0);
        $items = $payload['items'] ?? [];
        if ($id <= 0) {
            throw new Exception('ID inválido');
        }
        if (!is_array($items) || !$items) {
            throw new Exception('El presupuesto está vacío');
        }

        $stmtCurrent = $pdo->prepare("SELECT * FROM {$dbName}.presupuesto WHERE id_factura = :id LIMIT 1");
        $stmtCurrent->execute([':id' => $id]);
        $current = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            throw new Exception('Presupuesto no encontrado');
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
        if ($clienteNombre === '') $clienteNombre = 'Cliente ocasional';
        if ($clienteRuc === '') $clienteRuc = '-';

        $totals = computePresupuestoTotals($items);
        if ($totals['subtotal'] <= 0) {
            throw new Exception('El total del presupuesto es inválido');
        }

        $pdo->beginTransaction();
        try {
            $stmtUp = $pdo->prepare("
                UPDATE {$dbName}.presupuesto
                SET id_cliente = :id_cliente,
                    ruc = :ruc,
                    cantidad = :cantidad,
                    exenta = :exenta,
                    iva5 = :iva5,
                    iva10 = :iva10,
                    piva10 = :piva10,
                    piva5 = :piva5,
                    importe_gs = :importe_gs,
                    pendiente = :pendiente,
                    total = :total,
                    costo = :costo,
                    estado = 1
                WHERE id_factura = :id
            ");
            $stmtUp->execute([
                ':id_cliente' => $idCliente > 0 ? $idCliente : null,
                ':ruc' => substr($clienteRuc, 0, 20),
                ':cantidad' => round($totals['cantidadTotal'], 2),
                ':exenta' => round($totals['exenta'], 2),
                ':iva5' => round($totals['iva5'], 2),
                ':iva10' => round($totals['iva10'], 2),
                ':piva10' => round($totals['gravada10'], 2),
                ':piva5' => round($totals['gravada5'], 2),
                ':importe_gs' => round($totals['subtotal'], 2),
                ':pendiente' => round($totals['subtotal'], 2),
                ':total' => round($totals['subtotal'], 2),
                ':costo' => round($totals['costoTotal'], 2),
                ':id' => $id,
            ]);

            $pdo->prepare("DELETE FROM {$dbName}.presupuesto_item WHERE idfactura = :id")->execute([':id' => $id]);
            insertPresupuestoItems($pdo, $dbName, $id, (int)($current['id_sucursal'] ?? ($_SESSION['id_sucursal'] ?? 1)), (int)($_SESSION['id_login'] ?? 0), (string)($current['fecha'] ?? date('Y-m-d')), $items);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Presupuesto actualizado correctamente',
            'data' => [
                'id_factura' => $id,
                'nro_factura' => (string)($current['nro_factura'] ?? ('PRES-' . $id)),
                'total' => round($totals['subtotal'], 2),
                'fecha' => (string)($current['fecha'] ?? date('Y-m-d H:i:s')),
                'cliente' => $clienteNombre,
                'cliente_ruc' => $clienteRuc,
                'tipo_documento' => 'presupuesto',
                'medio_cobro' => 'presupuesto',
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

function normalizeDocumentoIvaRate($raw): int
{
    $v = (int)$raw;
    if ($v === 1 || $v === 0) return 0;
    if ($v === 2 || $v === 5) return 5;
    if ($v === 3 || $v === 10) return 10;
    return 10;
}

function computePresupuestoTotals(array $items): array
{
    $subtotal = 0.0;
    $cantidadTotal = 0.0;
    $iva5 = 0.0;
    $iva10 = 0.0;
    $exenta = 0.0;
    $gravada5 = 0.0;
    $gravada10 = 0.0;
    $costoTotal = 0.0;

    foreach ($items as $item) {
        $precio = (float)($item['precio'] ?? 0);
        $cantidad = (float)($item['cantidad'] ?? 0);
        if ($cantidad <= 0) continue;
        $tasa = normalizeDocumentoIvaRate($item['tasa_iva'] ?? 10);
        $importe = round($precio * $cantidad, 2);
        $subtotal += $importe;
        $cantidadTotal += $cantidad;
        $costoTotal += ((float)($item['costo'] ?? 0) * $cantidad);
        if ($tasa === 10) {
            $gravada10 += $importe;
            $iva10 += round($importe / 11, 2);
        } elseif ($tasa === 5) {
            $gravada5 += $importe;
            $iva5 += round($importe / 21, 2);
        } else {
            $exenta += $importe;
        }
    }

    return compact('subtotal', 'cantidadTotal', 'iva5', 'iva10', 'exenta', 'gravada5', 'gravada10', 'costoTotal');
}

function insertPresupuestoItems(PDO $pdo, string $dbName, int $id, int $idSucursal, int $idLogin, string $fecha, array $items): void
{
    $stmtItem = $pdo->prepare("
        INSERT INTO {$dbName}.presupuesto_item
        (
            idproducto, estado, id_sucursal, fecha, referencia, codigo, descripcion,
            entrada, salida, id_moneda, cambio, costo_gs, costo, precio_gs, precio,
            importe_gs, tipo_iva, importe, exenta, iva5, iva10, idfactura, id_login,
            id_sesion, obs, id_precio, descuento, total
        ) VALUES (
            :idproducto, 1, :id_sucursal, :fecha_item, NULL, :codigo, :descripcion,
            0, :salida, 1, 1, :costo_gs, :costo_unit, :precio_gs, :precio_unit,
            :importe_gs, :tipo_iva, :importe_total, :exenta, :iva5, :iva10, :idfactura, :id_login,
            :id_sesion, :obs, NULL, 0, :total
        )
    ");

    foreach ($items as $item) {
        $cantidad = (float)($item['cantidad'] ?? 0);
        if ($cantidad <= 0) continue;
        $precio = (float)($item['precio'] ?? 0);
        $costo = (float)($item['costo'] ?? 0);
        $importe = round($precio * $cantidad, 2);
        $tasa = normalizeDocumentoIvaRate($item['tasa_iva'] ?? 10);
        $tipoIva = $tasa === 5 ? 2 : ($tasa === 10 ? 3 : 1);
        $itemExenta = 0.0;
        $itemIva5 = 0.0;
        $itemIva10 = 0.0;
        if ($tasa === 10) {
            $itemIva10 = round($importe / 11, 2);
        } elseif ($tasa === 5) {
            $itemIva5 = round($importe / 21, 2);
        } else {
            $itemExenta = round($importe, 2);
        }

        $stmtItem->execute([
            ':idproducto' => (int)($item['id'] ?? $item['id_producto'] ?? 0),
            ':id_sucursal' => $idSucursal,
            ':fecha_item' => substr($fecha, 0, 10),
            ':codigo' => substr((string)($item['codigo'] ?? ''), 0, 20),
            ':descripcion' => substr((string)($item['descripcion'] ?? 'Producto'), 0, 100),
            ':salida' => $cantidad,
            ':costo_gs' => round($costo, 2),
            ':costo_unit' => round($costo, 2),
            ':precio_gs' => round($precio, 2),
            ':precio_unit' => round($precio, 2),
            ':importe_gs' => $importe,
            ':importe_total' => $importe,
            ':tipo_iva' => $tipoIva,
            ':exenta' => $itemExenta,
            ':iva5' => $itemIva5,
            ':iva10' => $itemIva10,
            ':idfactura' => $id,
            ':id_login' => $idLogin,
            ':id_sesion' => (int)(preg_replace('/\D+/', '', (string)session_id()) ?: 0),
            ':obs' => '',
            ':total' => $importe,
        ]);
    }
}

function ensurePresupuestoTablesCompat(PDO $pdo, string $dbName): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$dbName}.presupuesto (
            forma_pago int DEFAULT '1',
            estado int DEFAULT '1',
            tipo_venta int DEFAULT NULL,
            tipo_documento int DEFAULT '1',
            id_empresa int DEFAULT NULL,
            id_factura int NOT NULL AUTO_INCREMENT,
            id_sucursal int DEFAULT NULL,
            id_login int DEFAULT NULL,
            fecha date DEFAULT NULL,
            vencimiento date DEFAULT NULL,
            nro_factura varchar(20) DEFAULT NULL,
            tipo_cliente int DEFAULT '1',
            id_cliente int DEFAULT NULL,
            ruc varchar(20) DEFAULT NULL,
            cantidad decimal(12,2) DEFAULT NULL,
            converted_at datetime DEFAULT NULL,
            converted_factura_id int DEFAULT NULL,
            id_moneda int DEFAULT NULL,
            cambio decimal(12,2) DEFAULT NULL,
            exenta decimal(12,2) DEFAULT NULL,
            iva5 decimal(12,2) DEFAULT NULL,
            iva10 decimal(12,2) DEFAULT NULL,
            piva10 decimal(12,2) DEFAULT NULL,
            piva5 decimal(12,2) DEFAULT NULL,
            importe_gs decimal(12,2) DEFAULT NULL,
            timbrado varchar(20) DEFAULT NULL,
            vencimiento_timbrado date DEFAULT NULL,
            pagado decimal(12,2) DEFAULT '0.00',
            pendiente decimal(12,2) DEFAULT '0.00',
            tipo_precio int DEFAULT '1',
            descuento decimal(12,2) DEFAULT '0.00',
            total decimal(12,2) DEFAULT '0.00',
            costo decimal(12,2) DEFAULT '0.00',
            PRIMARY KEY (id_factura)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$dbName}.presupuesto_item (
            id int NOT NULL AUTO_INCREMENT,
            idproducto int DEFAULT NULL,
            estado int DEFAULT '1',
            id_sucursal int DEFAULT NULL,
            fecha date DEFAULT NULL,
            referencia int DEFAULT NULL,
            codigo varchar(20) DEFAULT NULL,
            descripcion varchar(100) DEFAULT NULL,
            entrada decimal(12,4) DEFAULT '0.0000',
            salida decimal(12,4) DEFAULT '0.0000',
            id_moneda int DEFAULT '1',
            cambio decimal(12,4) DEFAULT '1.0000',
            costo_gs decimal(12,2) DEFAULT '0.00',
            costo decimal(12,2) DEFAULT '0.00',
            precio_gs decimal(12,2) DEFAULT '0.00',
            precio decimal(12,2) DEFAULT '0.00',
            importe_gs decimal(12,2) DEFAULT '0.00',
            tipo_iva int DEFAULT '3',
            importe decimal(12,2) DEFAULT '0.00',
            exenta decimal(12,2) DEFAULT '0.00',
            iva5 decimal(12,2) DEFAULT '0.00',
            iva10 decimal(12,2) DEFAULT '0.00',
            idfactura int DEFAULT NULL,
            id_login int DEFAULT NULL,
            id_sesion int DEFAULT NULL,
            obs varchar(255) DEFAULT NULL,
            id_precio int DEFAULT NULL,
            descuento decimal(12,2) DEFAULT '0.00',
            total decimal(12,2) DEFAULT '0.00',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3
    ");

    $presupuestoCols = [
        'forma_pago' => "INT DEFAULT '1'",
        'estado' => "INT DEFAULT '1'",
        'tipo_venta' => "INT DEFAULT NULL",
        'tipo_documento' => "INT DEFAULT '1'",
        'id_empresa' => "INT DEFAULT NULL",
        'id_sucursal' => "INT DEFAULT NULL",
        'id_login' => "INT DEFAULT NULL",
        'fecha' => "DATE DEFAULT NULL",
        'vencimiento' => "DATE DEFAULT NULL",
        'nro_factura' => "VARCHAR(20) DEFAULT NULL",
        'tipo_cliente' => "INT DEFAULT '1'",
        'id_cliente' => "INT DEFAULT NULL",
        'ruc' => "VARCHAR(20) DEFAULT NULL",
        'cantidad' => "DECIMAL(12,2) DEFAULT NULL",
        'converted_at' => "DATETIME DEFAULT NULL",
        'converted_factura_id' => "INT DEFAULT NULL",
        'id_moneda' => "INT DEFAULT NULL",
        'cambio' => "DECIMAL(12,2) DEFAULT NULL",
        'exenta' => "DECIMAL(12,2) DEFAULT NULL",
        'iva5' => "DECIMAL(12,2) DEFAULT NULL",
        'iva10' => "DECIMAL(12,2) DEFAULT NULL",
        'piva10' => "DECIMAL(12,2) DEFAULT NULL",
        'piva5' => "DECIMAL(12,2) DEFAULT NULL",
        'importe_gs' => "DECIMAL(12,2) DEFAULT NULL",
        'timbrado' => "VARCHAR(20) DEFAULT NULL",
        'vencimiento_timbrado' => "DATE DEFAULT NULL",
        'pagado' => "DECIMAL(12,2) DEFAULT '0.00'",
        'pendiente' => "DECIMAL(12,2) DEFAULT '0.00'",
        'tipo_precio' => "INT DEFAULT '1'",
        'descuento' => "DECIMAL(12,2) DEFAULT '0.00'",
        'total' => "DECIMAL(12,2) DEFAULT '0.00'",
        'costo' => "DECIMAL(12,2) DEFAULT '0.00'",
    ];
    foreach ($presupuestoCols as $column => $definition) {
        ensureColumnCompat($pdo, $dbName, 'presupuesto', $column, $definition);
    }

    $presupuestoItemCols = [
        'idproducto' => "INT DEFAULT NULL",
        'estado' => "INT DEFAULT '1'",
        'id_sucursal' => "INT DEFAULT NULL",
        'fecha' => "DATE DEFAULT NULL",
        'referencia' => "INT DEFAULT NULL",
        'codigo' => "VARCHAR(20) DEFAULT NULL",
        'descripcion' => "VARCHAR(100) DEFAULT NULL",
        'entrada' => "DECIMAL(12,4) DEFAULT '0.0000'",
        'salida' => "DECIMAL(12,4) DEFAULT '0.0000'",
        'id_moneda' => "INT DEFAULT '1'",
        'cambio' => "DECIMAL(12,4) DEFAULT '1.0000'",
        'costo_gs' => "DECIMAL(12,2) DEFAULT '0.00'",
        'costo' => "DECIMAL(12,2) DEFAULT '0.00'",
        'precio_gs' => "DECIMAL(12,2) DEFAULT '0.00'",
        'precio' => "DECIMAL(12,2) DEFAULT '0.00'",
        'importe_gs' => "DECIMAL(12,2) DEFAULT '0.00'",
        'tipo_iva' => "INT DEFAULT '3'",
        'importe' => "DECIMAL(12,2) DEFAULT '0.00'",
        'exenta' => "DECIMAL(12,2) DEFAULT '0.00'",
        'iva5' => "DECIMAL(12,2) DEFAULT '0.00'",
        'iva10' => "DECIMAL(12,2) DEFAULT '0.00'",
        'idfactura' => "INT DEFAULT NULL",
        'id_login' => "INT DEFAULT NULL",
        'id_sesion' => "INT DEFAULT NULL",
        'obs' => "VARCHAR(255) DEFAULT NULL",
        'id_precio' => "INT DEFAULT NULL",
        'descuento' => "DECIMAL(12,2) DEFAULT '0.00'",
        'total' => "DECIMAL(12,2) DEFAULT '0.00'",
    ];
    foreach ($presupuestoItemCols as $column => $definition) {
        ensureColumnCompat($pdo, $dbName, 'presupuesto_item', $column, $definition);
    }
}

function tableHasColumnCompat(PDO $pdo, string $dbName, string $table, string $column): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$dbName`.`$table` LIKE " . $pdo->quote($column));
        return (bool)($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        return false;
    }
}
