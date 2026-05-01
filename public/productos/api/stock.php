<?php
/**
 * Inventario / Stock API
 * GET:
 *   ?action=dashboard
 *   ?action=sucursales
 *   ?action=buscar&q=...
 *   ?action=producto&id=123
 *   ?action=stock&id=123
 *   ?action=movimientos&id=123
 * POST JSON:
 *   { action: "ajuste", id_producto, id_sucursal, cantidad, tipo, obs }
 *   { action: "traslado", id_producto, id_sucursal_origen, id_sucursal_destino, cantidad, obs }
 *   { action: "recibir_traslado", id_traslado, obs }
 *   { action: "conteo", id_producto, id_sucursal, stock_fisico, obs }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../../../lib/producto_stock_snapshot.php';

Permission::requireAccess('app_grid_mercaderias');

$idEmpresa = (int)($_GET['id_empresa'] ?? $_POST['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$idLogin = (int)($_SESSION['id_login'] ?? 0);
$idSucursalSesion = (int)($_SESSION['id_sucursal'] ?? 0);
$stockPermisos = Permission::getAppPermissions('app_grid_mercaderias');

function stockJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function stockCan(array $permisos, string $key): bool
{
    return strtoupper((string)($permisos[$key] ?? 'N')) === 'Y';
}

function stockRequirePermission(array $permisos, string $key, string $message = 'No tiene permisos para esta operacion'): void
{
    if (!stockCan($permisos, $key)) {
        stockJson(['success' => false, 'error' => $message], 403);
    }
}

function stockTableExists(PDO $pdo, string $db, string $table): bool
{
    static $cache = [];
    $cacheKey = $db . '.' . $table;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = :db
          AND table_name = :table
        LIMIT 1
    ");
    $stmt->execute([':db' => $db, ':table' => $table]);
    $cache[$cacheKey] = (bool)$stmt->fetchColumn();
    return $cache[$cacheKey];
}

function stockColumnExists(PDO $pdo, string $db, string $table, string $column): bool
{
    static $cache = [];
    $cacheKey = $db . '.' . $table . '.' . $column;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = :db
          AND table_name = :table
          AND column_name = :column
        LIMIT 1
    ");
    $stmt->execute([':db' => $db, ':table' => $table, ':column' => $column]);
    $cache[$cacheKey] = (bool)$stmt->fetchColumn();
    return $cache[$cacheKey];
}

function stockEnsureTransferTable(PDO $pdo, string $db): bool
{
    static $ready = [];
    if (!empty($ready[$db])) {
        return $ready[$db] === true;
    }

    if (stockTableExists($pdo, $db, 'inventario_traslados')) {
        $ready[$db] = true;
        return true;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$db}.inventario_traslados (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                referencia VARCHAR(64) NOT NULL,
                id_producto BIGINT NOT NULL,
                id_sucursal_origen INT NOT NULL,
                id_sucursal_destino INT NOT NULL,
                cantidad DECIMAL(18,4) NOT NULL DEFAULT 0,
                estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
                obs VARCHAR(255) NOT NULL DEFAULT '',
                fecha_salida DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                fecha_recepcion DATETIME NULL DEFAULT NULL,
                id_login_salida BIGINT NOT NULL DEFAULT 0,
                id_login_recepcion BIGINT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_inventario_traslado_ref (referencia),
                KEY idx_inventario_traslado_estado_destino (estado, id_sucursal_destino),
                KEY idx_inventario_traslado_estado_origen (estado, id_sucursal_origen),
                KEY idx_inventario_traslado_producto (id_producto)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('[inventario/stock] inventario_traslados no disponible: ' . $e->getMessage());
        $ready[$db] = false;
        return false;
    }

    $ready[$db] = stockTableExists($pdo, $db, 'inventario_traslados');
    return $ready[$db] === true;
}

function stockSnapshotReady(PDO $pdo, string $db): bool
{
    return sxProductoStockSnapshotReady($pdo, $db);
}

function stockSnapshotTable(PDO $pdo, string $db): ?string
{
    return sxProductoStockSnapshotTable($pdo, $db);
}

function stockSnapshotValueColumn(PDO $pdo, string $db): string
{
    $table = stockSnapshotTable($pdo, $db);
    if ($table !== null && stockColumnExists($pdo, $db, $table, 'stock_disponible')) {
        return 'stock_disponible';
    }
    return 'stock_actual';
}

function stockSnapshotAdjust(PDO $pdo, string $db, int $idProducto, int $idSucursal, float $delta): void
{
    sxProductoStockSnapshotAdjust($pdo, $db, $idProducto, $idSucursal, $delta);
}

function stockSucursales(PDO $pdo, string $db): array
{
    static $cache = [];
    if (isset($cache[$db])) {
        return $cache[$db];
    }

    $rows = [];
    $hasEstado = stockColumnExists($pdo, $db, 'sucursales', 'estado');
    $hasActivo = stockColumnExists($pdo, $db, 'sucursales', 'activo');
    try {
        if ($hasEstado) {
            $sql = "SELECT id_sucursal, sucursal, COALESCE(estado, 1) AS activo FROM {$db}.sucursales WHERE COALESCE(estado, 1) = 1 ORDER BY id_sucursal";
        } elseif ($hasActivo) {
            $sql = "SELECT id_sucursal, sucursal, COALESCE(activo, 1) AS activo FROM {$db}.sucursales WHERE COALESCE(activo, 1) = 1 ORDER BY id_sucursal";
        } else {
            $sql = "SELECT id_sucursal, sucursal, 1 AS activo FROM {$db}.sucursales ORDER BY id_sucursal";
        }
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    if (empty($rows)) {
        try {
            if ($hasEstado) {
                $sql = "SELECT id_sucursal, sucursal, COALESCE(estado, 1) AS activo FROM {$db}.sucursales ORDER BY id_sucursal";
            } elseif ($hasActivo) {
                $sql = "SELECT id_sucursal, sucursal, COALESCE(activo, 1) AS activo FROM {$db}.sucursales ORDER BY id_sucursal";
            } else {
                $sql = "SELECT id_sucursal, sucursal, 1 AS activo FROM {$db}.sucursales ORDER BY id_sucursal";
            }
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }
    }

    $cache[$db] = array_map(static function ($row): array {
        return [
            'id_sucursal' => (int)($row['id_sucursal'] ?? 0),
            'sucursal' => trim((string)($row['sucursal'] ?? '')) ?: ('Suc. ' . (int)($row['id_sucursal'] ?? 0)),
            'activo' => (int)($row['activo'] ?? 1),
        ];
    }, $rows);

    return $cache[$db];
}

function stockProductoBase(PDO $pdo, string $db, int $idProducto): ?array
{
    $has = static fn(string $col): bool => stockColumnExists($pdo, $db, 'tblproductos', $col);
    $select = [
        "p.idproducto",
        $has('cve_producto') ? "p.cve_producto" : "'' AS cve_producto",
        $has('referencia') ? "p.referencia" : "'' AS referencia",
        $has('desproducto') ? "p.desproducto" : "'' AS desproducto",
        $has('precio_venta') ? "p.precio_venta" : "0 AS precio_venta",
        $has('precio_compra') ? "p.precio_compra" : "0 AS precio_compra",
        $has('stock_minimo') ? "p.stock_minimo" : "0 AS stock_minimo",
        $has('stock_maximo') ? "p.stock_maximo" : "0 AS stock_maximo",
        $has('controla_stock') ? "p.controla_stock" : "1 AS controla_stock",
        $has('vende_sin_stock') ? "p.vende_sin_stock" : "0 AS vende_sin_stock",
        $has('edita_precio') ? "p.edita_precio" : "0 AS edita_precio",
        $has('editable') ? "p.editable" : "0 AS editable",
        $has('saldo') ? "p.saldo" : "0 AS saldo",
        $has('foto_url') ? "p.foto_url" : "'' AS foto_url",
        $has('foto') ? "p.foto" : "'' AS foto",
        $has('Estado') ? "p.Estado" : "1 AS Estado",
        $has('descontinuado') ? "p.descontinuado" : "0 AS descontinuado",
        stockTableExists($pdo, $db, 'mercaderia_grupo') ? "COALESCE(g.grupo, '') AS grupo_nombre" : "'' AS grupo_nombre",
    ];

    $joinMarca = '';
    if (stockTableExists($pdo, $db, 'mercaderia_marca')) {
        $select[] = "COALESCE(m.marca, '') AS marca_nombre";
        $joinMarca = "LEFT JOIN {$db}.mercaderia_marca m ON m.id = p.marca";
    } elseif (stockTableExists($pdo, $db, 'marca')) {
        $select[] = "COALESCE(m.marca, '') AS marca_nombre";
        $joinMarca = "LEFT JOIN {$db}.marca m ON m.id = p.marca";
    } else {
        $select[] = "'' AS marca_nombre";
    }

    $joinGrupo = stockTableExists($pdo, $db, 'mercaderia_grupo')
        ? "LEFT JOIN {$db}.mercaderia_grupo g ON g.id = p.grupo"
        : '';

    $stmt = $pdo->prepare("
        SELECT
            " . implode(",\n            ", $select) . "
        FROM {$db}.tblproductos p
        {$joinGrupo}
        {$joinMarca}
        WHERE p.idproducto = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $idProducto]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return null;
    }

    return [
        'idproducto' => (int)$row['idproducto'],
        'cve_producto' => (string)($row['cve_producto'] ?? ''),
        'referencia' => (string)($row['referencia'] ?? ''),
        'desproducto' => (string)($row['desproducto'] ?? ''),
        'precio_venta' => (float)($row['precio_venta'] ?? 0),
        'precio_compra' => (float)($row['precio_compra'] ?? 0),
        'stock_minimo' => (float)($row['stock_minimo'] ?? 0),
        'stock_maximo' => (float)($row['stock_maximo'] ?? 0),
        'controla_stock' => (int)($row['controla_stock'] ?? 1),
        'vende_sin_stock' => (int)($row['vende_sin_stock'] ?? -1),
        'edita_precio' => (int)($row['edita_precio'] ?? -1),
        'editable' => (int)($row['editable'] ?? -1),
        'saldo' => (float)($row['saldo'] ?? 0),
        'foto_url' => (string)($row['foto_url'] ?? ''),
        'foto' => (string)($row['foto'] ?? ''),
        'Estado' => (int)($row['Estado'] ?? 1),
        'descontinuado' => (int)($row['descontinuado'] ?? 0),
        'grupo_nombre' => (string)($row['grupo_nombre'] ?? ''),
        'marca_nombre' => (string)($row['marca_nombre'] ?? ''),
    ];
}

function stockPrecioMayorMercaderia(PDO $pdo, string $db, string $codigoProducto, float $fallback = 0.0): float
{
    $codigoProducto = trim($codigoProducto);
    if ($codigoProducto === '' || !stockTableExists($pdo, $db, 'mercaderia_precio')) {
        return $fallback;
    }
    if (!stockColumnExists($pdo, $db, 'mercaderia_precio', 'codigo') || !stockColumnExists($pdo, $db, 'mercaderia_precio', 'precio')) {
        return $fallback;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(MAX(precio), 0) AS precio_max
            FROM {$db}.mercaderia_precio
            WHERE codigo = :codigo
        ");
        $stmt->execute([':codigo' => $codigoProducto]);
        $precioMax = (float)($stmt->fetchColumn() ?: 0);
        return $precioMax > 0 ? $precioMax : $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function stockProductoSucursales(PDO $pdo, string $db, int $idProducto, array $sucursales): array
{
    $map = [];
    if ($idProducto > 0) {
        if (stockSnapshotReady($pdo, $db)) {
            $snapshotTable = stockSnapshotTable($pdo, $db);
            $snapshotValue = stockSnapshotValueColumn($pdo, $db);
            $stmt = $pdo->prepare("
                SELECT id_sucursal, COALESCE({$snapshotValue}, 0) AS stock
                FROM {$db}.{$snapshotTable}
                WHERE idproducto = :id
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT id_sucursal, COALESCE(SUM(entrada) - SUM(salida), 0) AS stock
                FROM {$db}.extracto_productos
                WHERE idproducto = :id AND estado = 1
                GROUP BY id_sucursal
            ");
        }
        $stmt->execute([':id' => $idProducto]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['id_sucursal']] = (float)($row['stock'] ?? 0);
        }
    }

    $out = [];
    foreach ($sucursales as $sucursal) {
        $idSucursal = (int)($sucursal['id_sucursal'] ?? 0);
        $out[] = [
            'id_sucursal' => $idSucursal,
            'sucursal' => (string)($sucursal['sucursal'] ?? ('Suc. ' . $idSucursal)),
            'stock' => (float)($map[$idSucursal] ?? 0),
        ];
    }

    return $out;
}

function stockMovimientoLabel(array $row): string
{
    $entrada = (float)($row['entrada'] ?? 0);
    $salida = (float)($row['salida'] ?? 0);
    $tipoDocumento = (int)($row['tipo_documento'] ?? 0);
    $obs = strtoupper(trim((string)($row['obs'] ?? '')));

    if (str_contains($obs, 'TRASLADO')) {
        return $entrada > 0 ? 'Traslado recibido' : 'Traslado enviado';
    }
    if (str_contains($obs, 'CONTEO')) {
        return 'Conteo / regularizacion';
    }
    if ($tipoDocumento === 99) {
        return $entrada > 0 ? 'Ajuste de entrada' : 'Ajuste de salida';
    }
    if ($tipoDocumento === 1 || $tipoDocumento === 3) {
        return 'Venta';
    }
    if ($entrada > 0 && $salida <= 0) {
        return 'Entrada';
    }
    if ($salida > 0 && $entrada <= 0) {
        return 'Salida';
    }
    return 'Movimiento';
}

function stockMovimientos(PDO $pdo, string $db, int $idProducto = 0, int $idSucursal = 0, int $limit = 30, int $sinceId = 0): array
{
    $limit = max(1, min(200, $limit));
    $where = ["ep.estado = 1"];
    $params = [];

    if ($idProducto > 0) {
        $where[] = "ep.idproducto = :id_producto";
        $params[':id_producto'] = $idProducto;
    }
    if ($idSucursal > 0) {
        $where[] = "ep.id_sucursal = :id_sucursal";
        $params[':id_sucursal'] = $idSucursal;
    }
    if ($sinceId > 0) {
        $where[] = "ep.id > :since_id";
        $params[':since_id'] = $sinceId;
    }

    $sql = "
        SELECT
            ep.id,
            ep.idproducto,
            ep.id_sucursal,
            ep.fecha,
            ep.referencia,
            ep.codigo,
            ep.descripcion,
            ep.entrada,
            ep.salida,
            ep.obs,
            ep.stock_anterior,
            ep.stock_actual,
            ep.tipo_documento,
            ep.id_login,
            ep.idfactura,
            ep.id_cliente,
            COALESCE(s.sucursal, CONCAT('Suc. ', ep.id_sucursal)) AS nombre_sucursal
        FROM {$db}.extracto_productos ep
        LEFT JOIN {$db}.sucursales s ON s.id_sucursal = ep.id_sucursal
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ep.id DESC
        LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        $entrada = (float)($row['entrada'] ?? 0);
        $salida = (float)($row['salida'] ?? 0);
        return [
            'id' => (int)($row['id'] ?? 0),
            'idproducto' => (int)($row['idproducto'] ?? 0),
            'id_sucursal' => (int)($row['id_sucursal'] ?? 0),
            'fecha' => (string)($row['fecha'] ?? ''),
            'referencia' => (string)($row['referencia'] ?? ''),
            'codigo' => (string)($row['codigo'] ?? ''),
            'descripcion' => (string)($row['descripcion'] ?? ''),
            'entrada' => $entrada,
            'salida' => $salida,
            'cantidad' => $entrada > 0 ? $entrada : $salida,
            'direccion' => $entrada > 0 ? 'entrada' : 'salida',
            'obs' => (string)($row['obs'] ?? ''),
            'stock_anterior' => (float)($row['stock_anterior'] ?? 0),
            'stock_actual' => (float)($row['stock_actual'] ?? 0),
            'tipo_documento' => (int)($row['tipo_documento'] ?? 0),
            'id_login' => (int)($row['id_login'] ?? 0),
            'idfactura' => (int)($row['idfactura'] ?? 0),
            'id_cliente' => (int)($row['id_cliente'] ?? 0),
            'nombre_sucursal' => (string)($row['nombre_sucursal'] ?? ''),
            'movimiento' => stockMovimientoLabel($row),
        ];
    }, $rows);
}

function stockEnrichMovimientos(array $movimientos, PDO $pdo, string $db, ?PDO $masterPdo = null, string $masterDb = ''): array
{
    if (empty($movimientos)) {
        return [];
    }

    $loginIds = [];
    $ventasIds = [];
    $comprasIds = [];
    $clienteIds = [];

    foreach ($movimientos as $mov) {
        $idLogin = (int)($mov['id_login'] ?? 0);
        $idFactura = (int)($mov['idfactura'] ?? 0);
        $idCliente = (int)($mov['id_cliente'] ?? 0);
        $entrada = (float)($mov['entrada'] ?? 0);
        $salida = (float)($mov['salida'] ?? 0);
        $referencia = trim((string)($mov['referencia'] ?? ''));

        if ($idLogin > 0) $loginIds[$idLogin] = $idLogin;
        if ($idCliente > 0) $clienteIds[$idCliente] = $idCliente;
        if ($idFactura > 0) {
            if ($salida > 0) {
                $ventasIds[$idFactura] = $idFactura;
            } elseif ($entrada > 0 && ($referencia === '2' || (int)($mov['tipo_documento'] ?? 0) === 2)) {
                $comprasIds[$idFactura] = $idFactura;
            }
        }
    }

    $operadores = [];
    if ($masterPdo && $masterDb !== '' && !empty($loginIds)) {
        $placeholders = implode(',', array_fill(0, count($loginIds), '?'));
        $stmt = $masterPdo->prepare("
            SELECT id_login, COALESCE(NULLIF(name, ''), NULLIF(login, ''), CONCAT('Usuario ', id_login)) AS operador
            FROM {$masterDb}.sec_users
            WHERE id_login IN ({$placeholders})
        ");
        $stmt->execute(array_values($loginIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $operadores[(int)$row['id_login']] = (string)($row['operador'] ?? '');
        }
    }

    $clientes = [];
    if (!empty($clienteIds) && stockTableExists($pdo, $db, 'clientes')) {
        $placeholders = implode(',', array_fill(0, count($clienteIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id, COALESCE(NULLIF(nombre, ''), CONCAT('Cliente ', id)) AS nombre, COALESCE(numero, '') AS documento
            FROM {$db}.clientes
            WHERE id IN ({$placeholders})
        ");
        $stmt->execute(array_values($clienteIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $clientes[(int)$row['id']] = [
                'nombre' => (string)($row['nombre'] ?? ''),
                'documento' => (string)($row['documento'] ?? ''),
            ];
        }
    }

    $ventas = [];
    if (!empty($ventasIds) && stockTableExists($pdo, $db, 'factura_ventas')) {
        $placeholders = implode(',', array_fill(0, count($ventasIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id_factura, COALESCE(nro_factura, '') AS nro_factura, COALESCE(id_cliente, 0) AS id_cliente, COALESCE(ruc, '') AS ruc
            FROM {$db}.factura_ventas
            WHERE id_factura IN ({$placeholders})
        ");
        $stmt->execute(array_values($ventasIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ventas[(int)$row['id_factura']] = [
                'nro_factura' => (string)($row['nro_factura'] ?? ''),
                'id_cliente' => (int)($row['id_cliente'] ?? 0),
                'ruc' => (string)($row['ruc'] ?? ''),
            ];
            if ((int)($row['id_cliente'] ?? 0) > 0) {
                $clienteIds[(int)$row['id_cliente']] = (int)$row['id_cliente'];
            }
        }
    }

    $compras = [];
    if (!empty($comprasIds) && stockTableExists($pdo, $db, 'factura_compras')) {
        $placeholders = implode(',', array_fill(0, count($comprasIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id_factura, COALESCE(nro_factura, '') AS nro_factura, COALESCE(id_cliente, 0) AS id_cliente
            FROM {$db}.factura_compras
            WHERE id_factura IN ({$placeholders})
        ");
        $stmt->execute(array_values($comprasIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $compras[(int)$row['id_factura']] = [
                'nro_factura' => (string)($row['nro_factura'] ?? ''),
                'id_cliente' => (int)($row['id_cliente'] ?? 0),
            ];
            if ((int)($row['id_cliente'] ?? 0) > 0) {
                $clienteIds[(int)$row['id_cliente']] = (int)$row['id_cliente'];
            }
        }
    }

    if (!empty($clienteIds) && stockTableExists($pdo, $db, 'clientes')) {
        $faltantes = array_values(array_diff(array_values($clienteIds), array_keys($clientes)));
        if (!empty($faltantes)) {
            $placeholders = implode(',', array_fill(0, count($faltantes), '?'));
            $stmt = $pdo->prepare("
                SELECT id, COALESCE(NULLIF(nombre, ''), CONCAT('Cliente ', id)) AS nombre, COALESCE(numero, '') AS documento
                FROM {$db}.clientes
                WHERE id IN ({$placeholders})
            ");
            $stmt->execute($faltantes);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $clientes[(int)$row['id']] = [
                    'nombre' => (string)($row['nombre'] ?? ''),
                    'documento' => (string)($row['documento'] ?? ''),
                ];
            }
        }
    }

    foreach ($movimientos as &$mov) {
        $idLogin = (int)($mov['id_login'] ?? 0);
        $idFactura = (int)($mov['idfactura'] ?? 0);
        $entrada = (float)($mov['entrada'] ?? 0);
        $salida = (float)($mov['salida'] ?? 0);
        $tipoDocumento = (int)($mov['tipo_documento'] ?? 0);
        $referencia = trim((string)($mov['referencia'] ?? ''));

        $mov['operador_nombre'] = $operadores[$idLogin] ?? ($idLogin > 0 ? ('Usuario ' . $idLogin) : '');
        $mov['documento_tipo'] = '';
        $mov['documento_nro'] = '';
        $mov['cliente_nombre'] = '';

        if ($idFactura > 0 && $salida > 0 && isset($ventas[$idFactura])) {
            $venta = $ventas[$idFactura];
            $mov['documento_tipo'] = 'Venta';
            $mov['documento_nro'] = $venta['nro_factura'];
            $cliente = $clientes[(int)($venta['id_cliente'] ?? 0)] ?? null;
            $mov['cliente_nombre'] = $cliente['nombre'] ?? '';
            if ($mov['cliente_nombre'] === '' && !empty($venta['ruc'])) {
                $mov['cliente_nombre'] = $venta['ruc'];
            }
        } elseif ($idFactura > 0 && $entrada > 0 && isset($compras[$idFactura]) && ($referencia === '2' || $tipoDocumento === 2)) {
            $compra = $compras[$idFactura];
            $mov['documento_tipo'] = 'Compra';
            $mov['documento_nro'] = $compra['nro_factura'];
            $cliente = $clientes[(int)($compra['id_cliente'] ?? 0)] ?? null;
            $mov['cliente_nombre'] = $cliente['nombre'] ?? '';
        }
    }
    unset($mov);

    return $movimientos;
}

function stockTrasladosPendientes(PDO $pdo, string $db, int $idSucursal, int $limit = 20): array
{
    if ($idSucursal <= 0) {
        return [];
    }

    if (!stockEnsureTransferTable($pdo, $db) || !stockTableExists($pdo, $db, 'inventario_traslados')) {
        return [];
    }

    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.referencia,
            t.id_producto,
            t.id_sucursal_origen,
            t.id_sucursal_destino,
            t.cantidad,
            t.estado,
            t.obs,
            t.fecha_salida,
            t.id_login_salida,
            COALESCE(p.cve_producto, '') AS cve_producto,
            COALESCE(p.desproducto, '') AS desproducto,
            COALESCE(p.foto_url, '') AS foto_url,
            COALESCE(so.sucursal, CONCAT('Suc. ', t.id_sucursal_origen)) AS sucursal_origen,
            COALESCE(sd.sucursal, CONCAT('Suc. ', t.id_sucursal_destino)) AS sucursal_destino
        FROM {$db}.inventario_traslados t
        INNER JOIN {$db}.tblproductos p ON p.idproducto = t.id_producto
        LEFT JOIN {$db}.sucursales so ON so.id_sucursal = t.id_sucursal_origen
        LEFT JOIN {$db}.sucursales sd ON sd.id_sucursal = t.id_sucursal_destino
        WHERE t.estado = 'PENDIENTE'
          AND (t.id_sucursal_origen = :id_sucursal_origen_match OR t.id_sucursal_destino = :id_sucursal_destino_match)
        ORDER BY t.fecha_salida DESC, t.id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([
        ':id_sucursal_origen_match' => $idSucursal,
        ':id_sucursal_destino_match' => $idSucursal,
    ]);

    return array_map(static function (array $row) use ($idSucursal): array {
        $destinoActual = (int)($row['id_sucursal_destino'] ?? 0) === $idSucursal;
        return [
            'id' => (int)($row['id'] ?? 0),
            'referencia' => (string)($row['referencia'] ?? ''),
            'id_producto' => (int)($row['id_producto'] ?? 0),
            'id_sucursal_origen' => (int)($row['id_sucursal_origen'] ?? 0),
            'id_sucursal_destino' => (int)($row['id_sucursal_destino'] ?? 0),
            'cantidad' => (float)($row['cantidad'] ?? 0),
            'estado' => (string)($row['estado'] ?? 'PENDIENTE'),
            'obs' => (string)($row['obs'] ?? ''),
            'fecha_salida' => (string)($row['fecha_salida'] ?? ''),
            'id_login_salida' => (int)($row['id_login_salida'] ?? 0),
            'cve_producto' => (string)($row['cve_producto'] ?? ''),
            'desproducto' => (string)($row['desproducto'] ?? ''),
            'foto_url' => (string)($row['foto_url'] ?? ''),
            'sucursal_origen' => (string)($row['sucursal_origen'] ?? ''),
            'sucursal_destino' => (string)($row['sucursal_destino'] ?? ''),
            'direccion' => $destinoActual ? 'entrada' : 'salida',
            'puede_recibir' => $destinoActual,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function stockProductoPayload(PDO $pdo, string $db, int $idProducto, int $idSucursalSesion, ?PDO $masterPdo = null, string $masterDb = ''): ?array
{
    $producto = stockProductoBase($pdo, $db, $idProducto);
    if (!$producto) {
        return null;
    }

    $sucursales = stockSucursales($pdo, $db);
    $stockSucursales = stockProductoSucursales($pdo, $db, $idProducto, $sucursales);
    $stockActual = 0.0;
    foreach ($stockSucursales as $item) {
        if ((int)$item['id_sucursal'] === $idSucursalSesion) {
            $stockActual = (float)$item['stock'];
            break;
        }
    }
    $producto['stock_sucursales'] = $stockSucursales;
    $producto['stock_actual_sucursal'] = $stockActual;
    $producto['precio_venta'] = stockPrecioMayorMercaderia($pdo, $db, (string)($producto['cve_producto'] ?? ''), (float)($producto['precio_venta'] ?? 0));
    $producto['movimientos_sucursal'] = $idSucursalSesion > 0
        ? stockMovimientos($pdo, $db, $idProducto, $idSucursalSesion, 20, 0)
        : [];
    $producto['movimientos_globales'] = stockMovimientos($pdo, $db, $idProducto, 0, 20, 0);
    $producto['movimientos_sucursal'] = stockEnrichMovimientos($producto['movimientos_sucursal'], $pdo, $db, $masterPdo, $masterDb);
    $producto['movimientos_globales'] = stockEnrichMovimientos($producto['movimientos_globales'], $pdo, $db, $masterPdo, $masterDb);
    $producto['movimientos_recientes'] = $producto['movimientos_globales'];

    return $producto;
}

function stockActualizarSaldoGlobal(PDO $pdo, string $db, int $idProducto): float
{
    if (stockSnapshotReady($pdo, $db)) {
        $snapshotTable = stockSnapshotTable($pdo, $db);
        $snapshotValue = stockSnapshotValueColumn($pdo, $db);
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM({$snapshotValue}), 0) AS saldo
            FROM {$db}.{$snapshotTable}
            WHERE idproducto = :id
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(entrada) - SUM(salida), 0) AS saldo
            FROM {$db}.extracto_productos
            WHERE idproducto = :id AND estado = 1
        ");
    }
    $stmt->execute([':id' => $idProducto]);
    $saldo = (float)($stmt->fetchColumn() ?: 0);

    $stmtUpd = $pdo->prepare("UPDATE {$db}.tblproductos SET saldo = :saldo WHERE idproducto = :id");
    $stmtUpd->execute([':saldo' => $saldo, ':id' => $idProducto]);

    return $saldo;
}

try {
    $conn = getEmpresaConnection($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $hasTransferTable = stockEnsureTransferTable($pdo, $db);
    $masterPdo = $conn['masterPdo'] ?? null;
    $masterDb = (string)($conn['masterDb'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = trim((string)($_GET['action'] ?? 'dashboard'));
        $idProducto = (int)($_GET['id'] ?? $_GET['id_producto'] ?? 0);

        if ($action === 'sucursales') {
            stockJson(['success' => true, 'data' => stockSucursales($pdo, $db)]);
        }

        if ($action === 'dashboard') {
            $idSucursal = (int)($_GET['id_sucursal'] ?? $idSucursalSesion);
            $snapshotReady = stockSnapshotReady($pdo, $db);
            $snapshotTable = $snapshotReady ? stockSnapshotTable($pdo, $db) : null;
            $snapshotValue = $snapshotReady ? stockSnapshotValueColumn($pdo, $db) : 'stock_actual';

            $sqlStats = "
                SELECT
                    COUNT(*) AS total_productos,
                    SUM(CASE WHEN p.Estado = 1 THEN 1 ELSE 0 END) AS activos,
                    SUM(CASE WHEN p.Estado = 1 AND p.controla_stock = 1 AND COALESCE(st.stock, 0) <= 0 THEN 1 ELSE 0 END) AS sin_stock,
                    SUM(CASE WHEN p.Estado = 1 AND p.controla_stock = 1 AND p.stock_minimo > 0 AND COALESCE(st.stock, 0) > 0 AND COALESCE(st.stock, 0) <= p.stock_minimo THEN 1 ELSE 0 END) AS stock_bajo
                FROM {$db}.tblproductos p
                LEFT JOIN (
                    SELECT idproducto, COALESCE(SUM(" . ($snapshotReady ? $snapshotValue : '(entrada - salida)') . "), 0) AS stock
                    FROM {$db}." . ($snapshotReady ? $snapshotTable : 'extracto_productos') . "
                    WHERE " . ($snapshotReady ? '1=1' : 'estado = 1') . " " . ($idSucursal > 0 ? "AND id_sucursal = :id_sucursal" : "") . "
                    GROUP BY idproducto
                ) st ON st.idproducto = p.idproducto
            ";
            $stmtStats = $pdo->prepare($sqlStats);
            if ($idSucursal > 0) {
                $stmtStats->bindValue(':id_sucursal', $idSucursal, PDO::PARAM_INT);
            }
            $stmtStats->execute();
            $stats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: [];

            $movimientos = stockEnrichMovimientos(
                stockMovimientos($pdo, $db, 0, $idSucursal, 15, (int)($_GET['since_id'] ?? 0)),
                $pdo,
                $db,
                $masterPdo,
                $masterDb
            );

            stockJson([
                'success' => true,
                'data' => [
                    'stats' => [
                        'total_productos' => (int)($stats['total_productos'] ?? 0),
                        'activos' => (int)($stats['activos'] ?? 0),
                        'sin_stock' => (int)($stats['sin_stock'] ?? 0),
                        'stock_bajo' => (int)($stats['stock_bajo'] ?? 0),
                    ],
                    'movimientos' => $movimientos,
                    'traslados_pendientes' => $hasTransferTable ? stockTrasladosPendientes($pdo, $db, $idSucursal, 20) : [],
                    'id_sucursal' => $idSucursal,
                    'live_ts' => date('Y-m-d H:i:s'),
                ]
            ]);
        }

        if ($action === 'buscar') {
            $q = trim((string)($_GET['q'] ?? ''));
            $limit = max(1, min(60, (int)($_GET['limit'] ?? 30)));
            $idSucursal = (int)($_GET['id_sucursal'] ?? $idSucursalSesion);
            $snapshotReady = stockSnapshotReady($pdo, $db);
            $snapshotTable = $snapshotReady ? stockSnapshotTable($pdo, $db) : null;
            $snapshotValue = $snapshotReady ? stockSnapshotValueColumn($pdo, $db) : 'stock_actual';

            $sql = "
                SELECT
                    p.idproducto,
                    p.cve_producto,
                    p.referencia,
                    p.desproducto,
                    p.precio_venta,
                    p.stock_minimo,
                    p.controla_stock,
                    p.vende_sin_stock,
                    p.foto_url,
                    p.Estado,
                    COALESCE(st.stock, 0) AS stock_actual_sucursal,
                    p.saldo AS stock_global
                FROM {$db}.tblproductos p
                LEFT JOIN (
                    SELECT idproducto, COALESCE(SUM(" . ($snapshotReady ? $snapshotValue : '(entrada - salida)') . "), 0) AS stock
                    FROM {$db}." . ($snapshotReady ? $snapshotTable : 'extracto_productos') . "
                    WHERE " . ($snapshotReady ? '1=1' : 'estado = 1') . " " . ($idSucursal > 0 ? "AND id_sucursal = :id_sucursal" : "") . "
                    GROUP BY idproducto
                ) st ON st.idproducto = p.idproducto
                WHERE p.Estado = 1
            ";

            $params = [];
            if ($idSucursal > 0) {
                $params[':id_sucursal'] = $idSucursal;
            }

            if ($q !== '') {
                $sql .= " AND (
                    p.cve_producto LIKE :q_cve_prefix OR
                    p.referencia LIKE :q_ref_prefix OR
                    p.desproducto LIKE :q_like OR
                    p.codigo_barra LIKE :q_barcode_prefix
                )";
                $params[':q_cve_prefix'] = $q . '%';
                $params[':q_ref_prefix'] = $q . '%';
                $params[':q_like'] = '%' . $q . '%';
                $params[':q_barcode_prefix'] = $q . '%';
                $sql .= " ORDER BY
                    CASE
                        WHEN p.cve_producto = :q_cve_exact THEN 0
                        WHEN p.referencia = :q_ref_exact THEN 1
                        WHEN p.codigo_barra = :q_barcode_exact THEN 2
                        WHEN p.cve_producto LIKE :q_cve_prefix_exact THEN 3
                        WHEN p.referencia LIKE :q_ref_prefix_exact THEN 4
                        ELSE 5
                    END,
                    p.desproducto
                    LIMIT {$limit}";
                $params[':q_cve_exact'] = $q;
                $params[':q_ref_exact'] = $q;
                $params[':q_barcode_exact'] = $q;
                $params[':q_cve_prefix_exact'] = $q . '%';
                $params[':q_ref_prefix_exact'] = $q . '%';
            } else {
                $sql .= " ORDER BY p.desproducto LIMIT {$limit}";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $productos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            stockJson([
                'success' => true,
                'data' => array_map(function (array $row) use ($pdo, $db): array {
                    $codigoProducto = (string)($row['cve_producto'] ?? '');
                    $precioFallback = (float)($row['precio_venta'] ?? 0);
                    return [
                        'idproducto' => (int)$row['idproducto'],
                        'cve_producto' => $codigoProducto,
                        'referencia' => (string)($row['referencia'] ?? ''),
                        'desproducto' => (string)($row['desproducto'] ?? ''),
                        'precio_venta' => stockPrecioMayorMercaderia($pdo, $db, $codigoProducto, $precioFallback),
                        'stock_minimo' => (float)($row['stock_minimo'] ?? 0),
                        'controla_stock' => (int)($row['controla_stock'] ?? 1),
                        'vende_sin_stock' => (int)($row['vende_sin_stock'] ?? -1),
                        'foto_url' => (string)($row['foto_url'] ?? ''),
                        'Estado' => (int)($row['Estado'] ?? 1),
                        'stock_actual_sucursal' => (float)($row['stock_actual_sucursal'] ?? 0),
                        'stock_global' => (float)($row['stock_global'] ?? 0),
                    ];
                }, $productos),
            ]);
        }

        if ($action === 'producto' || $action === 'stock') {
            if ($idProducto <= 0) {
                stockJson(['success' => false, 'error' => 'ID requerido'], 422);
            }

            $idSucursal = (int)($_GET['id_sucursal'] ?? $idSucursalSesion);
            $producto = stockProductoPayload($pdo, $db, $idProducto, $idSucursal, $masterPdo, $masterDb);
            if (!$producto) {
                stockJson(['success' => false, 'error' => 'Producto no encontrado'], 404);
            }

            stockJson(['success' => true, 'data' => $producto]);
        }

        if ($action === 'movimientos') {
            $limit = max(1, min(200, (int)($_GET['limit'] ?? 30)));
            $idSucursal = (int)($_GET['id_sucursal'] ?? 0);
            $sinceId = (int)($_GET['since_id'] ?? 0);
            stockJson([
                'success' => true,
                'data' => stockEnrichMovimientos(
                    stockMovimientos($pdo, $db, $idProducto, $idSucursal, $limit, $sinceId),
                    $pdo,
                    $db,
                    $masterPdo,
                    $masterDb
                ),
            ]);
        }

        stockJson(['success' => false, 'error' => 'Accion GET no soportada'], 404);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = trim((string)($input['action'] ?? ''));

        if ($action === 'ajuste') {
            stockRequirePermission($stockPermisos, 'priv_insert', 'No tiene permisos para ajustar inventario');
            $idProducto = (int)($input['id_producto'] ?? 0);
            $idSucursal = (int)($input['id_sucursal'] ?? $idSucursalSesion);
            $cantidad = (float)($input['cantidad'] ?? 0);
            $tipo = trim((string)($input['tipo'] ?? 'entrada'));
            $obs = trim((string)($input['obs'] ?? 'Ajuste manual de inventario'));

            if ($idProducto <= 0 || $idSucursal <= 0 || $cantidad <= 0) {
                stockJson(['success' => false, 'error' => 'Producto, sucursal y cantidad requeridos'], 422);
            }
            if (!in_array($tipo, ['entrada', 'salida'], true)) {
                stockJson(['success' => false, 'error' => 'Tipo de ajuste invalido'], 422);
            }

            $producto = stockProductoBase($pdo, $db, $idProducto);
            if (!$producto) {
                stockJson(['success' => false, 'error' => 'Producto no encontrado'], 404);
            }

            $stockSucursales = stockProductoSucursales($pdo, $db, $idProducto, stockSucursales($pdo, $db));
            $stockAnteriorSucursal = 0.0;
            foreach ($stockSucursales as $row) {
                if ((int)$row['id_sucursal'] === $idSucursal) {
                    $stockAnteriorSucursal = (float)$row['stock'];
                    break;
                }
            }

            if ($tipo === 'salida' && (int)$producto['controla_stock'] === 1 && (int)$producto['vende_sin_stock'] === 0 && $cantidad > $stockAnteriorSucursal) {
                stockJson(['success' => false, 'error' => 'Stock insuficiente en la sucursal'], 422);
            }

            $entrada = $tipo === 'entrada' ? $cantidad : 0.0;
            $salida = $tipo === 'salida' ? $cantidad : 0.0;
            $stockActualSucursal = $stockAnteriorSucursal + $entrada - $salida;
            $referencia = 'AJ-' . date('YmdHis') . '-' . $idLogin;

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO {$db}.extracto_productos
                (idproducto, estado, id_sucursal, fecha, referencia, codigo, descripcion, entrada, salida, obs, id_login, stock_anterior, stock_actual, tipo_documento)
                VALUES
                (:idproducto, 1, :id_sucursal, NOW(), :referencia, :codigo, :descripcion, :entrada, :salida, :obs, :id_login, :stock_anterior, :stock_actual, 99)
            ");
            $stmt->execute([
                ':idproducto' => $idProducto,
                ':id_sucursal' => $idSucursal,
                ':referencia' => $referencia,
                ':codigo' => $producto['cve_producto'],
                ':descripcion' => $producto['desproducto'],
                ':entrada' => $entrada,
                ':salida' => $salida,
                ':obs' => $obs,
                ':id_login' => $idLogin,
                ':stock_anterior' => $stockAnteriorSucursal,
                ':stock_actual' => $stockActualSucursal,
            ]);
            stockSnapshotAdjust($pdo, $db, $idProducto, $idSucursal, $entrada - $salida);
            $saldoGlobal = stockActualizarSaldoGlobal($pdo, $db, $idProducto);
            $pdo->commit();

            stockJson([
                'success' => true,
                'message' => 'Ajuste registrado',
                'data' => [
                    'id_producto' => $idProducto,
                    'id_sucursal' => $idSucursal,
                    'stock_anterior_sucursal' => $stockAnteriorSucursal,
                    'stock_actual_sucursal' => $stockActualSucursal,
                    'saldo_global' => $saldoGlobal,
                    'referencia' => $referencia,
                ]
            ]);
        }

        if ($action === 'traslado') {
            stockRequirePermission($stockPermisos, 'priv_insert', 'No tiene permisos para emitir traslados');
            if (!$hasTransferTable || !stockTableExists($pdo, $db, 'inventario_traslados')) {
                stockJson(['success' => false, 'error' => 'Traslados pendientes no disponibles en esta empresa. Falta habilitar estructura.'], 422);
            }
            $idProducto = (int)($input['id_producto'] ?? 0);
            $idSucursalOrigen = (int)($input['id_sucursal_origen'] ?? 0);
            $idSucursalDestino = (int)($input['id_sucursal_destino'] ?? 0);
            $cantidad = (float)($input['cantidad'] ?? 0);
            $obs = trim((string)($input['obs'] ?? 'Traslado entre sucursales'));

            if ($idProducto <= 0 || $idSucursalOrigen <= 0 || $idSucursalDestino <= 0 || $cantidad <= 0) {
                stockJson(['success' => false, 'error' => 'Datos incompletos para traslado'], 422);
            }
            if ($idSucursalOrigen === $idSucursalDestino) {
                stockJson(['success' => false, 'error' => 'Origen y destino deben ser distintos'], 422);
            }

            $producto = stockProductoBase($pdo, $db, $idProducto);
            if (!$producto) {
                stockJson(['success' => false, 'error' => 'Producto no encontrado'], 404);
            }

            $stockSucursales = stockProductoSucursales($pdo, $db, $idProducto, stockSucursales($pdo, $db));
            $stockOrigen = 0.0;
            foreach ($stockSucursales as $row) {
                if ((int)$row['id_sucursal'] === $idSucursalOrigen) {
                    $stockOrigen = (float)$row['stock'];
                }
            }

            if ((int)$producto['controla_stock'] === 1 && (int)$producto['vende_sin_stock'] === 0 && $cantidad > $stockOrigen) {
                stockJson(['success' => false, 'error' => 'Stock insuficiente en sucursal origen'], 422);
            }

            $referencia = 'TR-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $obsBase = $obs !== '' ? $obs : 'Traslado entre sucursales';

            $pdo->beginTransaction();
            $stmtTransfer = $pdo->prepare("
                INSERT INTO {$db}.inventario_traslados
                (referencia, id_producto, id_sucursal_origen, id_sucursal_destino, cantidad, estado, obs, fecha_salida, id_login_salida)
                VALUES
                (:referencia, :id_producto, :id_sucursal_origen, :id_sucursal_destino, :cantidad, 'PENDIENTE', :obs, NOW(), :id_login_salida)
            ");
            $stmtTransfer->execute([
                ':referencia' => $referencia,
                ':id_producto' => $idProducto,
                ':id_sucursal_origen' => $idSucursalOrigen,
                ':id_sucursal_destino' => $idSucursalDestino,
                ':cantidad' => $cantidad,
                ':obs' => $obsBase,
                ':id_login_salida' => $idLogin,
            ]);
            $idTraslado = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO {$db}.extracto_productos
                (idproducto, estado, id_sucursal, fecha, referencia, codigo, descripcion, entrada, salida, obs, id_login, stock_anterior, stock_actual, tipo_documento)
                VALUES
                (:idproducto, 1, :id_sucursal, NOW(), :referencia, :codigo, :descripcion, :entrada, :salida, :obs, :id_login, :stock_anterior, :stock_actual, 98)
            ");

            $stmt->execute([
                ':idproducto' => $idProducto,
                ':id_sucursal' => $idSucursalOrigen,
                ':referencia' => $referencia,
                ':codigo' => $producto['cve_producto'],
                ':descripcion' => $producto['desproducto'],
                ':entrada' => 0,
                ':salida' => $cantidad,
                ':obs' => 'TRASLADO PENDIENTE SALIDA | ' . $obsBase,
                ':id_login' => $idLogin,
                ':stock_anterior' => $stockOrigen,
                ':stock_actual' => $stockOrigen - $cantidad,
            ]);
            stockSnapshotAdjust($pdo, $db, $idProducto, $idSucursalOrigen, -1 * $cantidad);
            $pdo->commit();

            stockJson([
                'success' => true,
                'message' => 'Traslado enviado y pendiente de recepción',
                'data' => [
                    'id_traslado' => $idTraslado,
                    'referencia' => $referencia,
                    'estado' => 'PENDIENTE',
                    'print_url' => '/public/inventario/traslado_print.php?id=' . $idTraslado . '&id_empresa=' . $idEmpresa . '&autoprint=1',
                    'label_url' => '/public/inventario/traslado_label.php?id=' . $idTraslado . '&id_empresa=' . $idEmpresa . '&autoprint=1',
                    'label_escpos_url' => '/public/inventario/traslado_label_escpos.php?id=' . $idTraslado . '&id_empresa=' . $idEmpresa . '&width=32',
                ]
            ]);
        }

        if ($action === 'recibir_traslado') {
            stockRequirePermission($stockPermisos, 'priv_update', 'No tiene permisos para recibir traslados');
            if (!$hasTransferTable || !stockTableExists($pdo, $db, 'inventario_traslados')) {
                stockJson(['success' => false, 'error' => 'Recepción de traslados no disponible en esta empresa.'], 422);
            }
            $idTraslado = (int)($input['id_traslado'] ?? 0);
            $idSucursalDestinoActual = (int)($input['id_sucursal_destino'] ?? $idSucursalSesion);
            $obsRecepcion = trim((string)($input['obs'] ?? ''));

            if ($idTraslado <= 0) {
                stockJson(['success' => false, 'error' => 'Traslado requerido'], 422);
            }

            $stmt = $pdo->prepare("
                SELECT *
                FROM {$db}.inventario_traslados
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $idTraslado]);
            $traslado = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$traslado) {
                stockJson(['success' => false, 'error' => 'Traslado no encontrado'], 404);
            }
            if (strtoupper((string)($traslado['estado'] ?? '')) !== 'PENDIENTE') {
                stockJson(['success' => false, 'error' => 'El traslado ya fue recibido o cerrado'], 422);
            }

            $idProducto = (int)($traslado['id_producto'] ?? 0);
            $idSucursalDestino = (int)($traslado['id_sucursal_destino'] ?? 0);
            $cantidad = (float)($traslado['cantidad'] ?? 0);
            if ($idSucursalDestino <= 0 || $cantidad <= 0) {
                stockJson(['success' => false, 'error' => 'Traslado inválido'], 422);
            }
            if ($idSucursalDestinoActual > 0 && $idSucursalDestinoActual !== $idSucursalDestino) {
                stockJson(['success' => false, 'error' => 'El traslado no pertenece a la sucursal seleccionada'], 422);
            }

            $producto = stockProductoBase($pdo, $db, $idProducto);
            if (!$producto) {
                stockJson(['success' => false, 'error' => 'Producto no encontrado'], 404);
            }

            $stockDestino = 0.0;
            foreach (stockProductoSucursales($pdo, $db, $idProducto, stockSucursales($pdo, $db)) as $row) {
                if ((int)$row['id_sucursal'] === $idSucursalDestino) {
                    $stockDestino = (float)$row['stock'];
                    break;
                }
            }

            $obsBase = trim((string)($traslado['obs'] ?? ''));
            if ($obsRecepcion !== '') {
                $obsBase = trim($obsBase . ' | RECEPCION: ' . $obsRecepcion, ' |');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO {$db}.extracto_productos
                (idproducto, estado, id_sucursal, fecha, referencia, codigo, descripcion, entrada, salida, obs, id_login, stock_anterior, stock_actual, tipo_documento)
                VALUES
                (:idproducto, 1, :id_sucursal, NOW(), :referencia, :codigo, :descripcion, :entrada, :salida, :obs, :id_login, :stock_anterior, :stock_actual, 98)
            ");
            $stmt->execute([
                ':idproducto' => $idProducto,
                ':id_sucursal' => $idSucursalDestino,
                ':referencia' => (string)$traslado['referencia'],
                ':codigo' => $producto['cve_producto'],
                ':descripcion' => $producto['desproducto'],
                ':entrada' => $cantidad,
                ':salida' => 0,
                ':obs' => 'TRASLADO RECIBIDO | ' . $obsBase,
                ':id_login' => $idLogin,
                ':stock_anterior' => $stockDestino,
                ':stock_actual' => $stockDestino + $cantidad,
            ]);
            stockSnapshotAdjust($pdo, $db, $idProducto, $idSucursalDestino, $cantidad);

            $stmtUpd = $pdo->prepare("
                UPDATE {$db}.inventario_traslados
                SET estado = 'RECIBIDO',
                    fecha_recepcion = NOW(),
                    id_login_recepcion = :id_login_recepcion,
                    obs = :obs
                WHERE id = :id
                  AND estado = 'PENDIENTE'
            ");
            $stmtUpd->execute([
                ':id_login_recepcion' => $idLogin,
                ':obs' => $obsBase,
                ':id' => $idTraslado,
            ]);
            $pdo->commit();

            stockJson([
                'success' => true,
                'message' => 'Traslado recibido correctamente',
                'data' => [
                    'id_traslado' => $idTraslado,
                    'referencia' => (string)$traslado['referencia'],
                    'stock_actual_sucursal' => $stockDestino + $cantidad,
                ],
            ]);
        }

        if ($action === 'conteo') {
            stockRequirePermission($stockPermisos, 'priv_insert', 'No tiene permisos para aplicar conteos');
            $idProducto = (int)($input['id_producto'] ?? 0);
            $idSucursal = (int)($input['id_sucursal'] ?? $idSucursalSesion);
            $stockFisico = (float)($input['stock_fisico'] ?? 0);
            $obs = trim((string)($input['obs'] ?? 'Conteo fisico'));

            if ($idProducto <= 0 || $idSucursal <= 0 || $stockFisico < 0) {
                stockJson(['success' => false, 'error' => 'Datos invalidos para conteo'], 422);
            }

            $producto = stockProductoBase($pdo, $db, $idProducto);
            if (!$producto) {
                stockJson(['success' => false, 'error' => 'Producto no encontrado'], 404);
            }

            $stockAnteriorSucursal = 0.0;
            foreach (stockProductoSucursales($pdo, $db, $idProducto, stockSucursales($pdo, $db)) as $row) {
                if ((int)$row['id_sucursal'] === $idSucursal) {
                    $stockAnteriorSucursal = (float)$row['stock'];
                    break;
                }
            }

            $diferencia = $stockFisico - $stockAnteriorSucursal;
            if (abs($diferencia) < 0.00001) {
                stockJson([
                    'success' => true,
                    'message' => 'Sin diferencia entre sistema y conteo',
                    'data' => [
                        'stock_anterior_sucursal' => $stockAnteriorSucursal,
                        'stock_actual_sucursal' => $stockFisico,
                        'saldo_global' => (float)$producto['saldo'],
                    ]
                ]);
            }

            $entrada = $diferencia > 0 ? $diferencia : 0.0;
            $salida = $diferencia < 0 ? abs($diferencia) : 0.0;
            $referencia = 'CO-' . date('YmdHis') . '-' . $idLogin;

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO {$db}.extracto_productos
                (idproducto, estado, id_sucursal, fecha, referencia, codigo, descripcion, entrada, salida, obs, id_login, stock_anterior, stock_actual, tipo_documento)
                VALUES
                (:idproducto, 1, :id_sucursal, NOW(), :referencia, :codigo, :descripcion, :entrada, :salida, :obs, :id_login, :stock_anterior, :stock_actual, 97)
            ");
            $stmt->execute([
                ':idproducto' => $idProducto,
                ':id_sucursal' => $idSucursal,
                ':referencia' => $referencia,
                ':codigo' => $producto['cve_producto'],
                ':descripcion' => $producto['desproducto'],
                ':entrada' => $entrada,
                ':salida' => $salida,
                ':obs' => 'CONTEO | ' . $obs,
                ':id_login' => $idLogin,
                ':stock_anterior' => $stockAnteriorSucursal,
                ':stock_actual' => $stockFisico,
            ]);
            $saldoGlobal = stockActualizarSaldoGlobal($pdo, $db, $idProducto);
            $pdo->commit();

            stockJson([
                'success' => true,
                'message' => 'Conteo aplicado',
                'data' => [
                    'stock_anterior_sucursal' => $stockAnteriorSucursal,
                    'stock_actual_sucursal' => $stockFisico,
                    'saldo_global' => $saldoGlobal,
                    'diferencia' => $diferencia,
                    'referencia' => $referencia,
                ]
            ]);
        }

        stockJson(['success' => false, 'error' => 'Accion POST no soportada'], 404);
    }

    stockJson(['success' => false, 'error' => 'Metodo no soportado'], 405);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    stockJson(['success' => false, 'error' => $e->getMessage()], 500);
}
