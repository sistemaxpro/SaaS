<?php
/**
 * Productos API - Listado con paginación y filtros
 * Compatible con esquemas variados de tblproductos (multi-tenant)
 * GET: ?page=1&per_page=25&search=&grupo=&marca=&estado=&sort_by=&sort_dir=
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../../../src/Services/ImageVariantService.php';

Permission::requireAccess('app_grid_mercaderias');

$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

function sx_norm_text($value): string
{
    $s = trim((string)$value);
    if ($s === '') return '';
    if (function_exists('mb_strtolower')) {
        $s = mb_strtolower($s, 'UTF-8');
    } else {
        $s = strtolower($s);
    }
    $from = ['á','à','ä','â','ã','å','é','è','ë','ê','í','ì','ï','î','ó','ò','ö','ô','õ','ú','ù','ü','û','ñ','ç'];
    $to   = ['a','a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','n','c'];
    $s = str_replace($from, $to, $s);
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim((string)$s);
}

function sx_index_exists(PDO $pdo, string $db, string $table, string $index): bool
{
    $sql = "SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = :db
              AND table_name = :tbl
              AND index_name = :idx
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':db' => $db, ':tbl' => $table, ':idx' => $index]);
    return (bool)$st->fetchColumn();
}

function sx_table_exists(PDO $pdo, string $db, string $table): bool
{
    $sql = "SELECT 1
            FROM information_schema.tables
            WHERE table_schema = :db
              AND table_name = :tbl
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':db' => $db, ':tbl' => $table]);
    return (bool)$st->fetchColumn();
}

function sx_stock_snapshot_ready(PDO $pdo, string $db): bool
{
    static $cache = [];
    if (array_key_exists($db, $cache)) {
        return $cache[$db];
    }
    $table = sx_stock_snapshot_table($pdo, $db);
    $cache[$db] = $table !== null
        && sx_column_exists($pdo, $db, $table, 'idproducto')
        && sx_column_exists($pdo, $db, $table, 'id_sucursal')
        && (
            sx_column_exists($pdo, $db, $table, 'stock_disponible')
            || sx_column_exists($pdo, $db, $table, 'stock_actual')
        );
    return $cache[$db];
}

function sx_stock_snapshot_table(PDO $pdo, string $db): ?string
{
    static $cache = [];
    if (array_key_exists($db, $cache)) {
        return $cache[$db];
    }

    foreach (['producto_stock', 'productos_stock'] as $candidate) {
        if (sx_table_exists($pdo, $db, $candidate)) {
            $cache[$db] = $candidate;
            return $candidate;
        }
    }

    $cache[$db] = null;
    return null;
}

function sx_column_exists(PDO $pdo, string $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $db . '.' . $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $sql = "SELECT 1
            FROM information_schema.columns
            WHERE table_schema = :db
              AND table_name = :tbl
              AND column_name = :col
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':db' => $db, ':tbl' => $table, ':col' => $column]);
    $cache[$key] = (bool)$st->fetchColumn();
    return $cache[$key];
}

function sx_ensure_productos_indexes(PDO $pdo, string $db, array $availableCols): void
{
    $defs = [];
    $estadoCol = in_array('estado', $availableCols, true) ? 'estado' : (in_array('Estado', $availableCols, true) ? 'Estado' : '');

    // tblproductos: filtros + búsqueda + orden
    if ($estadoCol !== '' && !sx_index_exists($pdo, $db, 'tblproductos', 'idx_tblproductos_estado_grupo_marca')) {
        $defs[] = "ALTER TABLE {$db}.tblproductos
                   ADD INDEX idx_tblproductos_estado_grupo_marca ({$estadoCol}, grupo, marca)";
    }
    if ($estadoCol !== '' && !sx_index_exists($pdo, $db, 'tblproductos', 'idx_tblproductos_estado_desproducto')) {
        $defs[] = "ALTER TABLE {$db}.tblproductos
                   ADD INDEX idx_tblproductos_estado_desproducto ({$estadoCol}, desproducto)";
    }
    if ($estadoCol !== '' && !sx_index_exists($pdo, $db, 'tblproductos', 'idx_tblproductos_estado_cve_producto')) {
        $defs[] = "ALTER TABLE {$db}.tblproductos
                   ADD INDEX idx_tblproductos_estado_cve_producto ({$estadoCol}, cve_producto)";
    }
    if (!sx_index_exists($pdo, $db, 'tblproductos', 'idx_tblproductos_desproducto')) {
        $defs[] = "ALTER TABLE {$db}.tblproductos
                   ADD INDEX idx_tblproductos_desproducto (desproducto)";
    }
    if (!sx_index_exists($pdo, $db, 'tblproductos', 'idx_tblproductos_cve_producto')) {
        $defs[] = "ALTER TABLE {$db}.tblproductos
                   ADD INDEX idx_tblproductos_cve_producto (cve_producto)";
    }
    if (!sx_index_exists($pdo, $db, 'tblproductos', 'idx_tblproductos_referencia')) {
        $defs[] = "ALTER TABLE {$db}.tblproductos
                   ADD INDEX idx_tblproductos_referencia (referencia)";
    }
    if (in_array('codigo_barra', $availableCols, true) && !sx_index_exists($pdo, $db, 'tblproductos', 'idx_tblproductos_codigo_barra')) {
        $defs[] = "ALTER TABLE {$db}.tblproductos
                   ADD INDEX idx_tblproductos_codigo_barra (codigo_barra)";
    }
    if (in_array('descontinuado', $availableCols, true) && $estadoCol !== '' && !sx_index_exists($pdo, $db, 'tblproductos', 'idx_tblproductos_descontinuado_estado')) {
        $defs[] = "ALTER TABLE {$db}.tblproductos
                   ADD INDEX idx_tblproductos_descontinuado_estado (descontinuado, {$estadoCol})";
    }

    // codigo_barra: búsqueda por código y lookup por producto
    if (sx_table_exists($pdo, $db, 'codigo_barra')) {
        if (!sx_index_exists($pdo, $db, 'codigo_barra', 'idx_codigo_barra_id_producto')) {
            $defs[] = "ALTER TABLE {$db}.codigo_barra
                       ADD INDEX idx_codigo_barra_id_producto (id_producto)";
        }
        if (!sx_index_exists($pdo, $db, 'codigo_barra', 'idx_codigo_barra_codigo')) {
            $defs[] = "ALTER TABLE {$db}.codigo_barra
                       ADD INDEX idx_codigo_barra_codigo (codigo_barra)";
        }
    }

    // mercaderia_precio: lookup por código y tipo
    if (sx_table_exists($pdo, $db, 'mercaderia_precio') && !sx_index_exists($pdo, $db, 'mercaderia_precio', 'idx_mercaderia_precio_codigo_tipo')) {
        $defs[] = "ALTER TABLE {$db}.mercaderia_precio
                   ADD INDEX idx_mercaderia_precio_codigo_tipo (codigo, tipo)";
    }

    // extracto_productos: agregación de stock por producto/sucursal
    if (sx_table_exists($pdo, $db, 'extracto_productos') && !sx_index_exists($pdo, $db, 'extracto_productos', 'idx_extracto_producto_estado_sucursal')) {
        $defs[] = "ALTER TABLE {$db}.extracto_productos
                   ADD INDEX idx_extracto_producto_estado_sucursal (idproducto, estado, id_sucursal)";
    }

    foreach ($defs as $ddl) {
        try {
            $pdo->exec($ddl);
        } catch (Throwable $e) {
            // No romper el endpoint por un índice no aplicable en algún tenant
            error_log('[productos/list] índice omitido: ' . $e->getMessage());
        }
    }
}

function sx_is_usable_foto_url(string $url): bool
{
    $url = trim($url);
    if ($url === '') return false;
    if (preg_match('/^https?:\/\//i', $url)) return true;
    if (str_starts_with($url, '/_lib/')) {
        $abs = dirname(__DIR__, 2) . $url;
        return is_file($abs);
    }
    if (str_starts_with($url, '/public/_lib/')) {
        $abs = dirname(__DIR__, 3) . $url;
        return is_file($abs);
    }
    return true;
}

function sx_parse_tipos_precio_asignados($raw): array
{
    if (is_array($raw)) {
        $vals = $raw;
    } else {
        $txt = trim((string)$raw);
        if ($txt === '') return [];

        $decoded = null;
        if (str_starts_with($txt, '[') || str_starts_with($txt, '{')) {
            $decoded = json_decode($txt, true);
        }
        if (is_array($decoded)) {
            $vals = $decoded;
        } else {
            $vals = preg_split('/\s*,\s*/', $txt) ?: [];
        }
    }

    $out = [];
    foreach ($vals as $v) {
        $id = (int)$v;
        if ($id > 0) $out[] = $id;
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

function sx_fast_count_cache_key(int $idEmpresa, string $db, string $estado, string $grupo, string $marca): string
{
    return 'productos_fast_count_' . $idEmpresa . '_' . md5($db . '|' . $estado . '|' . $grupo . '|' . $marca);
}

function sx_build_fast_filter_sql(string $estadoCol, string $estado, string $grupo, string $marca, array &$paramsOut): string
{
    $where = [];
    $params = [];
    if ($estado !== '' && $estado !== 'all') {
        $where[] = "p.{$estadoCol} = :estado";
        $params[':estado'] = (int)$estado;
    }
    if ($grupo !== '' && $grupo !== 'all') {
        $where[] = "p.grupo = :grupo";
        $params[':grupo'] = (int)$grupo;
    }
    if ($marca !== '' && $marca !== 'all') {
        $where[] = "p.marca = :marca";
        $params[':marca'] = (int)$marca;
    }
    $paramsOut = $params;
    return !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $masterPdo = $conn['masterPdo'] ?? null;
    $idLogin = (int)($_SESSION['id_login'] ?? 0);
    $idSucursalSesion = (int)($_SESSION['id_sucursal'] ?? $_SESSION['sucursal'] ?? 0);

    // Detectar columnas disponibles en tblproductos para este tenant
    $colsStmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'tblproductos'");
    $availableCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
    $hasCodBarra     = in_array('codigo_barra', $availableCols);
    $hasFotoUrl      = in_array('foto_url', $availableCols);
    $hasWeb          = in_array('web', $availableCols);
    $hasPublicarWeb  = in_array('publicar_web', $availableCols);
    $hasUnidadMedida = in_array('unidad_medida', $availableCols);
    $hasMoneda       = in_array('moneda', $availableCols);
    $hasDescontinuado = in_array('descontinuado', $availableCols);
    $hasColor        = in_array('color', $availableCols);
    $estadoCol       = in_array('estado', $availableCols, true) ? 'estado' : (in_array('Estado', $availableCols, true) ? 'Estado' : '');

    // Asegurar índices de performance solo bajo demanda (evitar latencia en primer render).
    $runEnsureIndexes = ((int)($_GET['ensure_indexes'] ?? 0) === 1);
    if ($runEnsureIndexes) {
        $idxKey = 'productos_indexes_' . $id_empresa . '_' . date('Ymd');
        if (empty($_SESSION[$idxKey])) {
            sx_ensure_productos_indexes($pdo, $db, $availableCols);
            $_SESSION[$idxKey] = 1;
        }
    }

    // Detectar si tabla codigo_barra existe
    $hasCBTable = false;
    try {
        $pdo->query("SELECT 1 FROM {$db}.codigo_barra LIMIT 1");
        $hasCBTable = true;
    } catch (Exception $e) {
        // tabla no existe
    }
    $hasProductoImagenesTable = sx_table_exists($pdo, $db, 'producto_imagenes');
    $hasExtractoTable = sx_table_exists($pdo, $db, 'extracto_productos');
    $hasProductoStockTable = sx_stock_snapshot_ready($pdo, $db);
    $productoStockTable = sx_stock_snapshot_table($pdo, $db);
    $productoStockValueExpr = ($productoStockTable !== null && sx_column_exists($pdo, $db, $productoStockTable, 'stock_disponible'))
        ? 'stock_disponible'
        : 'stock_actual';

    // Parámetros (aceptar nombres alternativos)
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(100, max(5, (int)($_GET['per_page'] ?? $_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;
    $action = trim((string)($_GET['action'] ?? ''));
    $withStats = ((int)($_GET['with_stats'] ?? 1) === 1);
    $fastMode = ((int)($_GET['fast'] ?? 0) === 1);
    $deferHydration = ((int)($_GET['defer_hydration'] ?? 0) === 1);
    $deferCount = ((int)($_GET['defer_count'] ?? 0) === 1);
    $search = trim($_GET['search'] ?? '');
    $grupo  = $_GET['grupo'] ?? '';
    $marca  = $_GET['marca'] ?? '';
    $estado = $_GET['estado'] ?? '1';
    $order  = $_GET['sort_by'] ?? $_GET['order'] ?? 'desproducto';
    $dir    = strtoupper($_GET['sort_dir'] ?? $_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
    $allowedOrder = ['idproducto', 'cve_producto', 'desproducto', 'precio_venta', 'precio_compra', 'saldo', 'grupo', 'marca'];
    if (!in_array($order, $allowedOrder, true)) {
        $order = 'desproducto';
    }

    $tiposPrecioPermitidos = [];
    if ($idLogin > 0 && $masterPdo instanceof PDO) {
        try {
            $stmtUserPrecios = $masterPdo->prepare("
                SELECT tipos_precio_asignados
                FROM sec_users
                WHERE id_login = :id_login AND id_empresa = :id_empresa
                LIMIT 1
            ");
            $stmtUserPrecios->execute([
                ':id_login' => $idLogin,
                ':id_empresa' => $id_empresa,
            ]);
            $tiposRaw = $stmtUserPrecios->fetchColumn();
            $tiposPrecioPermitidos = sx_parse_tipos_precio_asignados($tiposRaw);
        } catch (Throwable $e) {
            $tiposPrecioPermitidos = [];
        }
    }
    $precioTipoPreferido = !empty($tiposPrecioPermitidos) ? (int)$tiposPrecioPermitidos[0] : 1;

    if ($action === 'count' && $estadoCol !== '' && sx_table_exists($pdo, $db, 'tblproductos')) {
        $paramsCount = [];
        $whereCountSQL = sx_build_fast_filter_sql($estadoCol, (string)$estado, (string)$grupo, (string)$marca, $paramsCount);
        $countCacheKey = sx_fast_count_cache_key($id_empresa, $db, (string)$estado, (string)$grupo, (string)$marca);
        $countCache = $_SESSION[$countCacheKey] ?? null;
        if (is_array($countCache) && (($countCache['ts'] ?? 0) + 300) >= time()) {
            $total = (int)($countCache['total'] ?? 0);
        } else {
            $countSQL = "SELECT COUNT(*) AS total
                         FROM {$db}.tblproductos p
                         {$whereCountSQL}";
            $stmtCount = $pdo->prepare($countSQL);
            $stmtCount->execute($paramsCount);
            $total = (int)($stmtCount->fetchColumn() ?: 0);
            $_SESSION[$countCacheKey] = ['ts' => time(), 'total' => $total];
        }
        echo json_encode([
            'success' => true,
            'data' => [
                'total' => $total,
                'total_pages' => max(1, (int)ceil(max(1, $total) / $limit)),
            ],
        ]);
        exit;
    }

    if ($action === 'hydrate') {
        $rawIds = trim((string)($_GET['ids'] ?? ''));
        $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', $rawIds) ?: []), static fn($id) => $id > 0)));
        if (empty($ids)) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }
        $ids = array_slice($ids, 0, 60);
        $inPH = implode(',', array_fill(0, count($ids), '?'));

        $stockSesionMap = [];
        if ($hasProductoStockTable || $hasExtractoTable) {
            $stockSql = $hasProductoStockTable
                ? "SELECT ps.idproducto, COALESCE(ps.{$productoStockValueExpr}, 0) AS stock
                   FROM {$db}.{$productoStockTable} ps
                   WHERE ps.idproducto IN ({$inPH})
                     " . ($idSucursalSesion > 0 ? "AND ps.id_sucursal = ?" : "") . ""
                : "SELECT ep.idproducto, SUM(ep.entrada) - SUM(ep.salida) AS stock
                   FROM {$db}.extracto_productos ep
                   WHERE ep.idproducto IN ({$inPH}) AND ep.estado = 1
                     " . ($idSucursalSesion > 0 ? "AND ep.id_sucursal = ?" : "") . "
                   GROUP BY ep.idproducto";
            $stmtSt = $pdo->prepare($stockSql);
            $stockParams = $ids;
            if ($idSucursalSesion > 0) {
                $stockParams[] = $idSucursalSesion;
            }
            $stmtSt->execute($stockParams);
            foreach ($stmtSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $idProd = (int)($row['idproducto'] ?? 0);
                if ($idProd <= 0) continue;
                $stockSesionMap[$idProd] = (float)($row['stock'] ?? 0);
            }
        }

        $payload = [];
        foreach ($ids as $idProd) {
            $payload[] = [
                'idproducto' => $idProd,
                'stock_sucursales' => $idSucursalSesion > 0 ? [[
                    'id_sucursal' => (int)$idSucursalSesion,
                    'sucursal' => 'Sesion',
                    'stock' => (float)($stockSesionMap[$idProd] ?? 0),
                ]] : [],
                'stock_sesion' => (float)($stockSesionMap[$idProd] ?? 0),
                'hydrated' => true,
            ];
        }

        echo json_encode(['success' => true, 'data' => $payload]);
        exit;
    }

    $isFastGridPage = $fastMode
        && !$withStats
        && $search === ''
        && $estadoCol !== ''
        && sx_table_exists($pdo, $db, 'tblproductos')
        && in_array($order, ['idproducto', 'cve_producto', 'desproducto', 'precio_venta', 'precio_compra'], true);

    if ($isFastGridPage) {
        $paramsFastWhere = [];
        $whereFastSQL = sx_build_fast_filter_sql($estadoCol, (string)$estado, (string)$grupo, (string)$marca, $paramsFastWhere);

        $total = null;
        if (!$deferCount) {
            $countCacheKey = sx_fast_count_cache_key($id_empresa, $db, (string)$estado, (string)$grupo, (string)$marca);
            $countCache = $_SESSION[$countCacheKey] ?? null;
            if (is_array($countCache) && (($countCache['ts'] ?? 0) + 60) >= time()) {
                $total = (int)($countCache['total'] ?? 0);
            } else {
                $countSQL = "SELECT COUNT(*) AS total
                             FROM {$db}.tblproductos p
                             {$whereFastSQL}";
                $stmtCount = $pdo->prepare($countSQL);
                $stmtCount->execute($paramsFastWhere);
                $total = (int)($stmtCount->fetchColumn() ?: 0);
                $_SESSION[$countCacheKey] = ['ts' => time(), 'total' => $total];
            }
        }

        $fastOrderMap = [
            'idproducto' => 'p.idproducto',
            'cve_producto' => 'p.cve_producto',
            'desproducto' => 'p.desproducto',
            'precio_venta' => 'p.precio_venta',
            'precio_compra' => 'p.precio_compra',
        ];
        $fastOrderCol = $fastOrderMap[$order] ?? 'p.desproducto';
        $sqlPageIds = "SELECT p.idproducto
                       FROM {$db}.tblproductos p
                       {$whereFastSQL}
                       ORDER BY {$fastOrderCol} {$dir}, p.idproducto {$dir}
                       LIMIT :lim OFFSET :off";
        $stmtPageIds = $pdo->prepare($sqlPageIds);
        foreach ($paramsFastWhere as $key => $value) {
            $stmtPageIds->bindValue($key, $value);
        }
        $stmtPageIds->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmtPageIds->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmtPageIds->execute();
        $pageIds = array_map('intval', $stmtPageIds->fetchAll(PDO::FETCH_COLUMN) ?: []);

        if (empty($pageIds)) {
            echo json_encode([
                'success' => true,
                'data' => [],
                'pagination' => [
                    'page' => $page,
                    'per_page' => $limit,
                    'total' => $total,
                    'total_pages' => $total !== null ? max(1, (int)ceil(max(1, $total) / $limit)) : null,
                ],
                'stats' => [
                    'total' => 0,
                    'activos' => 0,
                    'stockBajo' => 0,
                    'sinStock' => 0,
                ],
            ]);
            exit;
        }

        $idsPlaceholder = implode(',', array_fill(0, count($pageIds), '?'));
        $joinFastStock = $hasProductoStockTable
            ? "LEFT JOIN (
                    SELECT ps.idproducto, SUM(COALESCE(ps.{$productoStockValueExpr}, 0)) AS saldo_calc
                    FROM {$db}.{$productoStockTable} ps
                    WHERE ps.idproducto IN ({$idsPlaceholder})
                    GROUP BY ps.idproducto
               ) stk ON stk.idproducto = p.idproducto"
            : ($hasExtractoTable
                ? "LEFT JOIN (
                        SELECT ep.idproducto, SUM(COALESCE(ep.entrada, 0) - COALESCE(ep.salida, 0)) AS saldo_calc
                        FROM {$db}.extracto_productos ep
                        WHERE ep.estado = 1
                          AND ep.idproducto IN ({$idsPlaceholder})
                        GROUP BY ep.idproducto
                   ) stk ON stk.idproducto = p.idproducto"
                : '');
        $joinFastStockSesion = $idSucursalSesion > 0
            ? (
                $hasProductoStockTable
                    ? "LEFT JOIN (
                            SELECT ps.idproducto, COALESCE(ps.{$productoStockValueExpr}, 0) AS stock_sesion_calc
                            FROM {$db}.{$productoStockTable} ps
                            WHERE ps.id_sucursal = ?
                              AND ps.idproducto IN ({$idsPlaceholder})
                       ) stks ON stks.idproducto = p.idproducto"
                    : ($hasExtractoTable
                        ? "LEFT JOIN (
                                SELECT ep.idproducto, SUM(COALESCE(ep.entrada, 0) - COALESCE(ep.salida, 0)) AS stock_sesion_calc
                                FROM {$db}.extracto_productos ep
                                WHERE ep.estado = 1
                                  AND ep.id_sucursal = ?
                                  AND ep.idproducto IN ({$idsPlaceholder})
                                GROUP BY ep.idproducto
                           ) stks ON stks.idproducto = p.idproducto"
                        : '')
            )
            : '';
        $joinFastPrice = sx_table_exists($pdo, $db, 'mercaderia_precio')
            ? "LEFT JOIN (
                    SELECT mp.codigo, MAX(mp.precio) AS precio
                    FROM {$db}.mercaderia_precio mp
                    WHERE mp.tipo = ?
                      AND mp.codigo IN ({$idsPlaceholder})
                    GROUP BY mp.codigo
               ) mp ON mp.codigo = p.idproducto"
            : '';

        $sqlFastPage = "SELECT
                p.idproducto,
                p.cve_producto,
                p.desproducto,
                p.referencia,
                p.precio_compra,
                COALESCE(" . ($joinFastPrice !== '' ? "mp.precio" : "p.precio_venta") . ", p.precio_venta) AS precio_venta,
                COALESCE(" . ($joinFastStock !== '' ? "stk.saldo_calc" : "p.saldo") . ", 0) AS saldo,
                " . ($joinFastStockSesion !== '' ? "COALESCE(stks.stock_sesion_calc, 0)" : "COALESCE(" . ($joinFastStock !== '' ? "stk.saldo_calc" : "p.saldo") . ", 0)") . " AS stock_sesion,
                p.{$estadoCol} AS Estado,
                p.iva,
                p.impuesto,
                p.stock_minimo,
                p.stock_maximo,
                p.controla_stock,
                p.edita_precio,
                p.vende_sin_stock,
                p.grupo AS grupo_id,
                p.marca AS marca_id,
                p.modelo AS modelo_id,
                " . ($hasMoneda ? "p.moneda" : "'PYG' AS moneda") . ",
                " . ($hasCodBarra ? "p.codigo_barra" : "'' AS codigo_barra") . ",
                " . ($hasUnidadMedida ? "p.unidad_medida" : "'' AS unidad_medida") . ",
                " . ($hasFotoUrl ? "p.foto_url" : "NULL AS foto_url") . ",
                " . ($hasWeb ? "p.web" : "'' AS web") . ",
                " . ($hasPublicarWeb ? "p.publicar_web" : "0 AS publicar_web") . ",
                " . ($hasDescontinuado ? "p.descontinuado" : "0 AS descontinuado") . ",
                g.grupo AS grupo_nombre,
                m.marca AS marca_nombre,
                mo.modelo AS modelo_nombre
                " . ($hasColor ? ", c.color AS color_nombre" : "") . "
            FROM {$db}.tblproductos p
            LEFT JOIN {$db}.mercaderia_grupo g ON g.id = p.grupo
            LEFT JOIN {$db}.mercaderia_marca m ON m.id = p.marca
            LEFT JOIN {$db}.mercaderia_modelo mo ON mo.id = p.modelo
            " . ($hasColor ? "LEFT JOIN {$db}.mercaderia_color c ON c.id = p.color" : "") . "
            {$joinFastPrice}
            {$joinFastStock}
            {$joinFastStockSesion}
            WHERE p.idproducto IN ({$idsPlaceholder})
            ORDER BY FIELD(p.idproducto, " . implode(',', array_map('intval', $pageIds)) . ")";

        $paramsFastPage = [];
        if ($joinFastPrice !== '') {
            $paramsFastPage[] = $precioTipoPreferido;
            $paramsFastPage = array_merge($paramsFastPage, $pageIds);
        }
        if ($joinFastStock !== '') {
            $paramsFastPage = array_merge($paramsFastPage, $pageIds);
        }
        if ($joinFastStockSesion !== '') {
            $paramsFastPage[] = $idSucursalSesion;
            $paramsFastPage = array_merge($paramsFastPage, $pageIds);
        }
        $paramsFastPage = array_merge($paramsFastPage, $pageIds);

        $stmtFastPage = $pdo->prepare($sqlFastPage);
        $stmtFastPage->execute($paramsFastPage);
        $productos = $stmtFastPage->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $tipoPrecioNombre = 'Precio ' . $precioTipoPreferido;
        if (sx_table_exists($pdo, $db, 'tipo_precio')) {
            try {
                $stmtTipo = $pdo->prepare("SELECT tipo FROM {$db}.tipo_precio WHERE id = :id LIMIT 1");
                $stmtTipo->execute([':id' => $precioTipoPreferido]);
                $tipoPrecioNombre = trim((string)$stmtTipo->fetchColumn()) ?: $tipoPrecioNombre;
            } catch (Throwable $e) {
                // fallback
            }
        }

        $sucursalesCatalog = [];
        if (sx_table_exists($pdo, $db, 'sucursales')) {
            try {
                $sucCols = $pdo->query("SHOW COLUMNS FROM {$db}.sucursales")->fetchAll(PDO::FETCH_COLUMN);
                $idCol = in_array('id_sucursal', $sucCols, true) ? 'id_sucursal' : 'id';
                $nameCol = in_array('sucursal', $sucCols, true) ? 'sucursal' : (in_array('nombre', $sucCols, true) ? 'nombre' : $idCol);
                $stSuc = $pdo->query("SELECT {$idCol} AS id_sucursal, {$nameCol} AS sucursal FROM {$db}.sucursales ORDER BY {$idCol}");
                foreach (($stSuc->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
                    $idS = (int)($r['id_sucursal'] ?? 0);
                    if ($idS <= 0) continue;
                    $sucursalesCatalog[$idS] = trim((string)($r['sucursal'] ?? '')) ?: ('Suc. ' . $idS);
                }
            } catch (Throwable $e) {
                $sucursalesCatalog = [];
            }
        }

        $stockByProdSuc = [];
        if (!empty($productos) && ($hasProductoStockTable || $hasExtractoTable)) {
            $stockSql = $hasProductoStockTable
                ? "SELECT ps.idproducto, ps.id_sucursal, COALESCE(ps.{$productoStockValueExpr}, 0) AS stock
                   FROM {$db}.{$productoStockTable} ps
                   WHERE ps.idproducto IN ({$idsPlaceholder})
                   ORDER BY ps.id_sucursal"
                : "SELECT ep.idproducto, ep.id_sucursal, SUM(ep.entrada) - SUM(ep.salida) AS stock
                   FROM {$db}.extracto_productos ep
                   WHERE ep.idproducto IN ({$idsPlaceholder}) AND ep.estado = 1
                   GROUP BY ep.idproducto, ep.id_sucursal
                   ORDER BY ep.id_sucursal";
            $stmtSt = $pdo->prepare($stockSql);
            $stmtSt->execute($pageIds);
            foreach ($stmtSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $idProd = (int)($row['idproducto'] ?? 0);
                $idSuc = (int)($row['id_sucursal'] ?? 0);
                if ($idProd <= 0 || $idSuc <= 0) continue;
                $stockByProdSuc[$idProd][$idSuc] = (float)($row['stock'] ?? 0);
                if (!isset($sucursalesCatalog[$idSuc])) {
                    $sucursalesCatalog[$idSuc] = 'Suc. ' . $idSuc;
                }
            }
        }

        ksort($sucursalesCatalog, SORT_NUMERIC);
        if ($idSucursalSesion > 0 && isset($sucursalesCatalog[$idSucursalSesion])) {
            $primeraSucursal = [$idSucursalSesion => $sucursalesCatalog[$idSucursalSesion]];
            unset($sucursalesCatalog[$idSucursalSesion]);
            $sucursalesCatalog = $primeraSucursal + $sucursalesCatalog;
        }

        foreach ($productos as &$prod) {
            $prod['precios'] = [[
                'tipo' => $precioTipoPreferido,
                'tipo_nombre' => $tipoPrecioNombre,
                'precio' => (float)($prod['precio_venta'] ?? 0),
            ]];
            $prod['codigos_barra'] = [];
            $prod['tiene_movimientos'] = false;
            $prod['stock_sucursales'] = [];
            $prod['hydrated'] = true;
            $prod['foto_thumb_url'] = $prod['foto_url'] ?? null;
            $prod['foto_small_url'] = $prod['foto_url'] ?? null;
            $prod['foto_medium_url'] = $prod['foto_url'] ?? null;
            $prod['foto_large_url'] = $prod['foto_url'] ?? null;
            if (!$deferHydration) {
                $idProd = (int)($prod['idproducto'] ?? 0);
                $saldoCalc = 0.0;
                $listaSuc = [];
                foreach ($sucursalesCatalog as $idSuc => $nombreSuc) {
                    $val = (float)($stockByProdSuc[$idProd][$idSuc] ?? 0);
                    $saldoCalc += $val;
                    $listaSuc[] = [
                        'id_sucursal' => (int)$idSuc,
                        'sucursal' => $nombreSuc,
                        'stock' => $val,
                    ];
                }
                $prod['stock_sucursales'] = $listaSuc;
                $prod['saldo'] = !empty($listaSuc) ? $saldoCalc : (float)($prod['saldo'] ?? 0);

                $fallbackServerUrl = trim((string)($prod['foto_url'] ?? ''));
                if ($fallbackServerUrl !== '' && sx_is_usable_foto_url($fallbackServerUrl)) {
                    $variants = ImageVariantService::deriveVariantUrls('', $fallbackServerUrl);
                    $prod['foto_thumb_url'] = $variants['thumb'] ?? $fallbackServerUrl;
                    $prod['foto_small_url'] = $variants['small'] ?? $fallbackServerUrl;
                    $prod['foto_medium_url'] = $variants['medium'] ?? $fallbackServerUrl;
                    $prod['foto_large_url'] = $variants['large'] ?? $fallbackServerUrl;
                    $prod['foto_url'] = $prod['foto_small_url'] ?: $fallbackServerUrl;
                } else {
                    $prod['foto_url'] = null;
                }
            } else {
                $prod['stock_sesion'] = (float)($prod['stock_sesion'] ?? $prod['saldo'] ?? 0);
            }
        }
        unset($prod);

        echo json_encode([
            'success' => true,
            'data' => $productos,
            'pagination' => [
                'page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_pages' => $total !== null ? max(1, (int)ceil(max(1, $total) / $limit)) : null,
            ],
            'stats' => [
                'total' => 0,
                'activos' => 0,
                'stockBajo' => 0,
                'sinStock' => 0,
            ],
        ]);
        exit;
    }

    // Autoasignar catálogos faltantes al iniciar (grupo/marca/modelo/color) usando desproducto.
    // Si no hay coincidencia, asigna "No definida".
    // IMPORTANTE: esta rutina es costosa en tenants grandes; se ejecuta solo si se solicita explícitamente.
    $runAutoFill = isset($_GET['autofill']) && (string)$_GET['autofill'] === '1';
    $autoFillKey = 'productos_autofill_' . $id_empresa . '_' . date('Ymd');
    $canAutoFill = ($runAutoFill && $page === 1 && $search === '' && empty($_SESSION[$autoFillKey]));
    if ($canAutoFill) {
        try {
            $fieldDefs = [
                'grupo'  => ['table' => 'mercaderia_grupo',  'field' => 'grupo'],
                'marca'  => ['table' => 'mercaderia_marca',  'field' => 'marca'],
                'modelo' => ['table' => 'mercaderia_modelo', 'field' => 'modelo'],
                'color'  => ['table' => 'mercaderia_color',  'field' => 'color'],
            ];

            $activeDefs = [];
            foreach ($fieldDefs as $col => $def) {
                if (in_array($col, $availableCols, true)) {
                    $activeDefs[$col] = $def;
                }
            }

            if (!empty($activeDefs)) {
                $catalogData = [];
                foreach ($activeDefs as $col => $def) {
                    $tbl = $def['table'];
                    $fld = $def['field'];
                    try {
                        $pdo->query("SELECT 1 FROM {$db}.{$tbl} LIMIT 1");

                        $tblCols = [];
                        $stTblCols = $pdo->query("SHOW COLUMNS FROM {$db}.{$tbl}");
                        while ($r = $stTblCols->fetch(PDO::FETCH_ASSOC)) {
                            $tblCols[] = strtolower((string)($r['Field'] ?? ''));
                        }
                        $hasEstadoCol = in_array('estado', $tblCols, true);

                        $stmtNoDef = $pdo->prepare("SELECT id FROM {$db}.{$tbl} WHERE LOWER(TRIM({$fld})) = 'no definida' LIMIT 1");
                        $stmtNoDef->execute();
                        $noDefId = (int)$stmtNoDef->fetchColumn();
                        if ($noDefId <= 0) {
                            if ($hasEstadoCol) {
                                $stmtInsNoDef = $pdo->prepare("INSERT INTO {$db}.{$tbl} ({$fld}, estado) VALUES ('No definida', 1)");
                            } else {
                                $stmtInsNoDef = $pdo->prepare("INSERT INTO {$db}.{$tbl} ({$fld}) VALUES ('No definida')");
                            }
                            $stmtInsNoDef->execute();
                            $noDefId = (int)$pdo->lastInsertId();
                            if ($noDefId <= 0) {
                                $stmtNoDef->execute();
                                $noDefId = (int)$stmtNoDef->fetchColumn();
                            }
                        }

                        $items = [];
                        $stmtCat = $pdo->query("SELECT id, {$fld} AS nombre FROM {$db}.{$tbl}");
                        while ($r = $stmtCat->fetch(PDO::FETCH_ASSOC)) {
                            $name = trim((string)($r['nombre'] ?? ''));
                            if ($name === '') continue;
                            $items[] = [
                                'id' => (int)$r['id'],
                                'nombre' => $name,
                                'norm' => sx_norm_text($name),
                            ];
                        }

                        $catalogData[$col] = [
                            'no_def_id' => $noDefId > 0 ? $noDefId : 0,
                            'items' => $items,
                        ];
                    } catch (Exception $e) {
                        // Tabla de catálogo no disponible para este tenant.
                    }
                }

                if (!empty($catalogData)) {
                    $missingConds = [];
                    foreach (array_keys($catalogData) as $col) {
                        $missingConds[] = "(p.{$col} IS NULL OR p.{$col} = 0 OR TRIM(CAST(p.{$col} AS CHAR)) = '')";
                    }

                    $selectColsFix = ["p.idproducto", "p.desproducto"];
                    foreach (array_keys($catalogData) as $col) {
                        $selectColsFix[] = "p.{$col}";
                    }
                    $sqlMissing = "SELECT " . implode(", ", $selectColsFix) . "
                        FROM {$db}.tblproductos p
                        WHERE " . implode(" OR ", $missingConds);
                    $stmtMissing = $pdo->query($sqlMissing);
                    $rowsMissing = $stmtMissing->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($rowsMissing as $row) {
                        $descNorm = sx_norm_text($row['desproducto'] ?? '');
                        $set = [];
                        $bind = [':idp' => (int)$row['idproducto']];

                        foreach ($catalogData as $col => $meta) {
                            $current = (int)($row[$col] ?? 0);
                            if ($current > 0) continue;

                            $matchId = 0;
                            if ($descNorm !== '') {
                                $bestLen = 0;
                                foreach ($meta['items'] as $item) {
                                    $nameNorm = $item['norm'] ?? '';
                                    if ($nameNorm === '' || $nameNorm === 'no definida') continue;
                                    if (strlen($nameNorm) < 2) continue;
                                    if (strpos(" {$descNorm} ", " {$nameNorm} ") !== false) {
                                        $len = strlen($nameNorm);
                                        if ($len > $bestLen) {
                                            $bestLen = $len;
                                            $matchId = (int)$item['id'];
                                        }
                                    }
                                }
                            }
                            if ($matchId <= 0) $matchId = (int)($meta['no_def_id'] ?? 0);
                            if ($matchId > 0) {
                                $set[] = "{$col} = :{$col}";
                                $bind[":{$col}"] = $matchId;
                            }
                        }

                        if (!empty($set)) {
                            $sqlUpd = "UPDATE {$db}.tblproductos SET " . implode(', ', $set) . " WHERE idproducto = :idp";
                            $stmtUpd = $pdo->prepare($sqlUpd);
                            $stmtUpd->execute($bind);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // No cortar listado si la autocorrección falla.
        }
        $_SESSION[$autoFillKey] = 1;
    }

    if ($fastMode && $search !== '' && $estadoCol !== '' && sx_table_exists($pdo, $db, 'tblproductos')) {
        $tokens = preg_split('/\s+/u', trim($search)) ?: [];
        $tokens = array_values(array_filter(array_map(static function ($token) {
            $token = trim((string)$token);
            return mb_strlen($token, 'UTF-8') > 0 ? $token : '';
        }, $tokens)));
        $tokens = array_slice($tokens, 0, 6);

        $candidateCap = min(250, max(60, $offset + $limit + 40));
        $candidateIds = [];

        $ftKey = 'productos_api_list_ft_' . $db;
        if (empty($_SESSION[$ftKey])) {
            try {
                $pdo->exec("ALTER TABLE {$db}.tblproductos ADD FULLTEXT INDEX ft_search (desproducto, cve_producto, referencia)");
            } catch (Throwable $e) {
                // índice ya existente o no soportado
            }
            $_SESSION[$ftKey] = 1;
        }

        $addFastFilters = static function (string &$sql, array &$params, bool $named = true) use ($grupo, $marca): void {
            if ($grupo !== '' && $grupo !== 'all') {
                if ($named) {
                    $sql .= " AND p.grupo = :grupo_fast";
                    $params[':grupo_fast'] = (int)$grupo;
                } else {
                    $sql .= " AND p.grupo = ?";
                    $params[] = (int)$grupo;
                }
            }
            if ($marca !== '' && $marca !== 'all') {
                if ($named) {
                    $sql .= " AND p.marca = :marca_fast";
                    $params[':marca_fast'] = (int)$marca;
                } else {
                    $sql .= " AND p.marca = ?";
                    $params[] = (int)$marca;
                }
            }
        };

        if (!empty($tokens)) {
            $ftParts = [];
            foreach ($tokens as $token) {
                $clean = preg_replace('/[+\\-><()~*\"@]+/', '', $token);
                if ($clean !== '') {
                    $ftParts[] = '+' . $clean . '*';
                }
            }
            $ftQuery = implode(' ', $ftParts);

            if ($ftQuery !== '') {
                $sqlIds = "SELECT p.idproducto
                           FROM {$db}.tblproductos p
                           WHERE p.{$estadoCol} = :estado
                             AND (
                                MATCH(p.desproducto, p.cve_producto, p.referencia) AGAINST(:ft IN BOOLEAN MODE)
                                OR CAST(p.idproducto AS CHAR) = :q_exact_id
                                OR CAST(p.idproducto AS CHAR) LIKE :q_prefix_id
                             )";
                $paramsIds = [
                    ':estado' => (int)$estado,
                    ':ft' => $ftQuery,
                    ':q_exact_id' => $search,
                    ':q_prefix_id' => $search . '%',
                ];
                $addFastFilters($sqlIds, $paramsIds, true);
                $sqlIds .= " ORDER BY
                    CASE
                        WHEN CAST(p.idproducto AS CHAR) = :q_sort_exact_id THEN 0
                        WHEN p.cve_producto = :q_exact_code THEN 1
                        WHEN p.referencia = :q_exact_ref THEN 2
                        WHEN CAST(p.idproducto AS CHAR) LIKE :q_sort_prefix_id THEN 3
                        WHEN p.cve_producto LIKE :q_prefix_code THEN 4
                        WHEN p.referencia LIKE :q_prefix_ref THEN 5
                        ELSE 6
                    END,
                    MATCH(p.desproducto, p.cve_producto, p.referencia) AGAINST(:ft_sort IN BOOLEAN MODE) DESC,
                    p.desproducto
                    LIMIT {$candidateCap}";
                $paramsIds[':q_sort_exact_id'] = $search;
                $paramsIds[':q_exact_code'] = $search;
                $paramsIds[':q_exact_ref'] = $search;
                $paramsIds[':q_sort_prefix_id'] = $search . '%';
                $paramsIds[':q_prefix_code'] = $search . '%';
                $paramsIds[':q_prefix_ref'] = $search . '%';
                $paramsIds[':ft_sort'] = $ftQuery;

                try {
                    $stmtIds = $pdo->prepare($sqlIds);
                    $stmtIds->execute($paramsIds);
                    $candidateIds = array_map('intval', $stmtIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
                } catch (Throwable $e) {
                    $candidateIds = [];
                }
            }

            if (count($candidateIds) < $candidateCap && count($tokens) === 1 && $hasCBTable) {
                $remaining = $candidateCap - count($candidateIds);
                $excludeIds = empty($candidateIds) ? '0' : implode(',', array_map('intval', $candidateIds));
                $sqlCb = "SELECT DISTINCT cb.id_producto
                          FROM {$db}.codigo_barra cb
                          INNER JOIN {$db}.tblproductos p ON p.idproducto = cb.id_producto
                          WHERE p.{$estadoCol} = ?
                            AND cb.codigo_barra LIKE ?
                            AND cb.id_producto NOT IN ({$excludeIds})";
                $paramsCb = [(int)$estado, $search . '%'];
                $addFastFilters($sqlCb, $paramsCb, false);
                $sqlCb .= " LIMIT {$remaining}";
                try {
                    $stmtCb = $pdo->prepare($sqlCb);
                    $stmtCb->execute($paramsCb);
                    $candidateIds = array_merge($candidateIds, array_map('intval', $stmtCb->fetchAll(PDO::FETCH_COLUMN) ?: []));
                } catch (Throwable $e) {
                    // fallback silencioso
                }
            }

            if (empty($candidateIds)) {
                $sqlFallback = "SELECT p.idproducto
                                FROM {$db}.tblproductos p
                                WHERE p.{$estadoCol} = ?";
                $paramsFallback = [(int)$estado];
                foreach ($tokens as $token) {
                    $sqlFallback .= " AND (
                        CAST(p.idproducto AS CHAR) LIKE ?
                        OR
                        p.cve_producto LIKE ?
                        OR p.referencia LIKE ?
                        OR p.desproducto LIKE ?
                    )";
                    $paramsFallback[] = $token . '%';
                    $paramsFallback[] = $token . '%';
                    $paramsFallback[] = $token . '%';
                    $paramsFallback[] = '%' . $token . '%';
                }
                $addFastFilters($sqlFallback, $paramsFallback, false);
                $sqlFallback .= " ORDER BY
                    CASE
                        WHEN CAST(p.idproducto AS CHAR) = ? THEN 0
                        WHEN p.cve_producto = ? THEN 1
                        WHEN p.referencia = ? THEN 2
                        ELSE 3
                    END,
                    p.desproducto
                    LIMIT {$candidateCap}";
                $paramsFallback[] = $search;
                $paramsFallback[] = $search;
                $paramsFallback[] = $search;
                $stmtFallback = $pdo->prepare($sqlFallback);
                $stmtFallback->execute($paramsFallback);
                $candidateIds = array_map('intval', $stmtFallback->fetchAll(PDO::FETCH_COLUMN) ?: []);
            }
        }

        $candidateIds = array_values(array_unique(array_filter($candidateIds, static fn($id) => $id > 0)));
        $totalFast = count($candidateIds);
        $pageIds = array_slice($candidateIds, $offset, $limit);
        if (empty($pageIds)) {
            echo json_encode([
                'success' => true,
                'data' => [],
                'pagination' => [
                    'page' => $page,
                    'per_page' => $limit,
                    'total' => $totalFast,
                    'total_pages' => max(1, (int)ceil(max(1, $totalFast) / $limit)),
                ],
                'stats' => [
                    'total' => 0,
                    'activos' => 0,
                    'stockBajo' => 0,
                    'sinStock' => 0,
                ],
            ]);
            exit;
        }

        $idsPlaceholder = implode(',', array_fill(0, count($pageIds), '?'));
        $joinFastStock = $hasProductoStockTable
            ? "LEFT JOIN (
                    SELECT ps.idproducto, SUM(COALESCE(ps.{$productoStockValueExpr}, 0)) AS saldo_calc
                    FROM {$db}.{$productoStockTable} ps
                    WHERE ps.idproducto IN ({$idsPlaceholder})
                    GROUP BY ps.idproducto
               ) stk ON stk.idproducto = p.idproducto"
            : ($hasExtractoTable
                ? "LEFT JOIN (
                        SELECT ep.idproducto, SUM(COALESCE(ep.entrada, 0) - COALESCE(ep.salida, 0)) AS saldo_calc
                        FROM {$db}.extracto_productos ep
                        WHERE ep.estado = 1
                          AND ep.id_sucursal = ?
                          AND ep.idproducto IN ({$idsPlaceholder})
                        GROUP BY ep.idproducto
                   ) stk ON stk.idproducto = p.idproducto"
                : '');
        $joinFastPrice = sx_table_exists($pdo, $db, 'mercaderia_precio')
            ? "LEFT JOIN (
                    SELECT mp.codigo, MAX(mp.precio) AS precio
                    FROM {$db}.mercaderia_precio mp
                    WHERE mp.tipo = ?
                      AND mp.codigo IN ({$idsPlaceholder})
                    GROUP BY mp.codigo
               ) mp ON mp.codigo = p.idproducto"
            : '';

        $sqlFast = "SELECT
                p.idproducto,
                p.cve_producto,
                p.desproducto,
                p.referencia,
                p.precio_compra,
                COALESCE(" . ($joinFastPrice !== '' ? "mp.precio" : "p.precio_venta") . ", p.precio_venta) AS precio_venta,
                COALESCE(" . ($joinFastStock !== '' ? "stk.saldo_calc" : "p.saldo") . ", 0) AS saldo,
                p.{$estadoCol} AS Estado,
                p.iva,
                p.impuesto,
                p.stock_minimo,
                p.stock_maximo,
                p.controla_stock,
                p.edita_precio,
                p.vende_sin_stock,
                p.grupo AS grupo_id,
                p.marca AS marca_id,
                p.modelo AS modelo_id,
                " . ($hasMoneda ? "p.moneda" : "'PYG' AS moneda") . ",
                " . ($hasCodBarra ? "p.codigo_barra" : "'' AS codigo_barra") . ",
                " . ($hasUnidadMedida ? "p.unidad_medida" : "'' AS unidad_medida") . ",
                " . ($hasFotoUrl ? "p.foto_url" : "NULL AS foto_url") . ",
                " . ($hasWeb ? "p.web" : "'' AS web") . ",
                " . ($hasPublicarWeb ? "p.publicar_web" : "0 AS publicar_web") . ",
                " . ($hasDescontinuado ? "p.descontinuado" : "0 AS descontinuado") . ",
                g.grupo AS grupo_nombre,
                m.marca AS marca_nombre,
                mo.modelo AS modelo_nombre
                " . ($hasColor ? ", c.color AS color_nombre" : "") . "
            FROM {$db}.tblproductos p
            LEFT JOIN {$db}.mercaderia_grupo g ON g.id = p.grupo
            LEFT JOIN {$db}.mercaderia_marca m ON m.id = p.marca
            LEFT JOIN {$db}.mercaderia_modelo mo ON mo.id = p.modelo
            " . ($hasColor ? "LEFT JOIN {$db}.mercaderia_color c ON c.id = p.color" : "") . "
            {$joinFastPrice}
            {$joinFastStock}
            WHERE p.idproducto IN ({$idsPlaceholder})
            ORDER BY FIELD(p.idproducto, " . implode(',', array_map('intval', $pageIds)) . ")";

        $paramsFast = [];
        if ($joinFastPrice !== '') {
            $paramsFast[] = $precioTipoPreferido;
            $paramsFast = array_merge($paramsFast, $pageIds);
        }
        if ($joinFastStock !== '') {
            $paramsFast = array_merge($paramsFast, $pageIds);
        }
        $paramsFast = array_merge($paramsFast, $pageIds);

        $stmtFast = $pdo->prepare($sqlFast);
        $stmtFast->execute($paramsFast);
        $productos = $stmtFast->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $tipoPrecioNombre = 'Precio ' . $precioTipoPreferido;
        if (sx_table_exists($pdo, $db, 'tipo_precio')) {
            try {
                $stmtTipo = $pdo->prepare("SELECT tipo FROM {$db}.tipo_precio WHERE id = :id LIMIT 1");
                $stmtTipo->execute([':id' => $precioTipoPreferido]);
                $tipoPrecioNombre = trim((string)$stmtTipo->fetchColumn()) ?: $tipoPrecioNombre;
            } catch (Throwable $e) {
                // fallback
            }
        }

        foreach ($productos as &$prod) {
            $prod['precios'] = [[
                'tipo' => $precioTipoPreferido,
                'tipo_nombre' => $tipoPrecioNombre,
                'precio' => (float)($prod['precio_venta'] ?? 0),
            ]];
            $prod['stock_sucursales'] = [];
            $prod['codigos_barra'] = [];
            $prod['tiene_movimientos'] = false;

            $fallbackServerUrl = trim((string)($prod['foto_url'] ?? ''));
            if ($fallbackServerUrl !== '' && sx_is_usable_foto_url($fallbackServerUrl)) {
                $variants = ImageVariantService::deriveVariantUrls('', $fallbackServerUrl);
                $prod['foto_thumb_url'] = $variants['thumb'] ?? $fallbackServerUrl;
                $prod['foto_small_url'] = $variants['small'] ?? $fallbackServerUrl;
                $prod['foto_medium_url'] = $variants['medium'] ?? $fallbackServerUrl;
                $prod['foto_large_url'] = $variants['large'] ?? $fallbackServerUrl;
                $prod['foto_url'] = $prod['foto_small_url'] ?: $fallbackServerUrl;
            } else {
                $prod['foto_url'] = null;
                $desc = trim((string)($prod['desproducto'] ?? ''));
                $idp = (int)($prod['idproducto'] ?? 0);
                if ($desc !== '' && $idp > 0) {
                    $proxyUrl = '/public/pos/api/imagen_proxy.php?id=' . $idp . '&q=' . urlencode($desc);
                    $prod['foto_url'] = $proxyUrl;
                    $prod['foto_thumb_url'] = $proxyUrl;
                    $prod['foto_small_url'] = $proxyUrl;
                    $prod['foto_medium_url'] = $proxyUrl;
                    $prod['foto_large_url'] = $proxyUrl;
                }
            }
        }
        unset($prod);

        echo json_encode([
            'success' => true,
            'data' => $productos,
            'pagination' => [
                'page' => $page,
                'per_page' => $limit,
                'total' => $totalFast,
                'total_pages' => max(1, (int)ceil($totalFast / $limit)),
            ],
            'stats' => [
                'total' => 0,
                'activos' => 0,
                'stockBajo' => 0,
                'sinStock' => 0,
            ],
        ]);
        exit;
    }

    // Columnas permitidas para ordenar
    $allowedOrder = ['idproducto', 'cve_producto', 'desproducto', 'precio_venta', 'precio_compra', 'saldo', 'grupo', 'marca'];
    if (!in_array($order, $allowedOrder)) $order = 'desproducto';

    // Construir WHERE
    $where = [];
    $params = [];

    if ($estado !== '' && $estado !== 'all' && $estadoCol !== '') {
        $where[] = "p.{$estadoCol} = :estado";
        $params[':estado'] = (int)$estado;
    }

    if ($grupo !== '' && $grupo !== 'all') {
        $where[] = "p.grupo = :grupo";
        $params[':grupo'] = (int)$grupo;
    }

    if ($marca !== '' && $marca !== 'all') {
        $where[] = "p.marca = :marca";
        $params[':marca'] = (int)$marca;
    }

    if ($search !== '') {
        $tokens = preg_split('/\s+/u', trim($search)) ?: [];
        $tokens = array_values(array_filter(array_map(static function ($token) {
            $token = trim((string)$token);
            return mb_strlen($token, 'UTF-8') > 0 ? $token : '';
        }, $tokens)));
        if (empty($tokens)) {
            $tokens = [$search];
        }
        $tokens = array_slice($tokens, 0, 8);

        $tokenGroups = [];
        foreach ($tokens as $idx => $token) {
            $paramBase = ':s' . $idx;
            $tokenConds = [
                "CAST(p.idproducto AS CHAR) LIKE {$paramBase}_id",
                "p.desproducto LIKE {$paramBase}_d",
                "p.cve_producto LIKE {$paramBase}_c",
                "p.referencia LIKE {$paramBase}_r",
            ];
            $params["{$paramBase}_id"] = "%{$token}%";
            $params["{$paramBase}_d"] = "%{$token}%";
            $params["{$paramBase}_c"] = "%{$token}%";
            $params["{$paramBase}_r"] = "%{$token}%";
            if ($hasCodBarra) {
                $tokenConds[] = "p.codigo_barra LIKE {$paramBase}_b";
                $params["{$paramBase}_b"] = "%{$token}%";
            }
            if ($hasCBTable) {
                $tokenConds[] = "EXISTS (
                    SELECT 1
                    FROM {$db}.codigo_barra cbx
                    WHERE cbx.id_producto = p.idproducto
                      AND cbx.codigo_barra LIKE {$paramBase}_cb
                )";
                $params["{$paramBase}_cb"] = "%{$token}%";
            }
            $tokenGroups[] = '(' . implode(' OR ', $tokenConds) . ')';
        }

        if (!empty($tokenGroups)) {
            $where[] = '(' . implode(' AND ', $tokenGroups) . ')';
        }
    }

    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    // Mapear columna de orden
    $requiresSaldoOrder = ($order === 'saldo' && ($hasProductoStockTable || $hasExtractoTable));
    $orderMap = [
        'grupo' => 'g.grupo',
        'marca' => 'm.marca',
        'desproducto' => 'p.desproducto',
        'cve_producto' => 'p.cve_producto',
        'precio_venta' => 'p.precio_venta',
        'precio_compra' => 'p.precio_compra',
        'saldo' => $requiresSaldoOrder ? 'COALESCE(stk.saldo_calc, 0)' : 'p.idproducto',
        'idproducto' => 'p.idproducto'
    ];
    $orderCol = $orderMap[$order] ?? 'p.desproducto';

    // Count total (sin JOIN/GROUP para mejor rendimiento)
    $countSQL = "SELECT COUNT(*) as total
                 FROM {$db}.tblproductos p
                 {$whereSQL}";
    $stmtCount = $pdo->prepare($countSQL);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetch()['total'];

    $saldoSelectExpr = $requiresSaldoOrder ? "COALESCE(stk.saldo_calc, 0)" : "0";

    // Construir SELECT dinámico basado en columnas existentes
    $selectCols = [
        "p.idproducto", "p.cve_producto", "p.desproducto", "p.referencia",
        "p.precio_compra", "p.precio_venta", "{$saldoSelectExpr} AS saldo", ($estadoCol !== '' ? "p.{$estadoCol} AS Estado" : "1 AS Estado"),
        "p.iva", "p.impuesto", "p.stock_minimo", "p.stock_maximo",
        "p.controla_stock", "p.edita_precio", "p.vende_sin_stock",
        "p.grupo AS grupo_id", "p.marca AS marca_id", "p.modelo AS modelo_id",
    ];
    if ($hasMoneda)       $selectCols[] = "p.moneda";
    if ($hasCodBarra)     $selectCols[] = "p.codigo_barra";
    if ($hasUnidadMedida) $selectCols[] = "p.unidad_medida";
    if ($hasFotoUrl)      $selectCols[] = "p.foto_url";
    if ($hasWeb)          $selectCols[] = "p.web";
    if ($hasPublicarWeb)  $selectCols[] = "p.publicar_web";
    if ($hasDescontinuado) $selectCols[] = "p.descontinuado";

    $selectCols[] = "g.grupo AS grupo_nombre";
    $selectCols[] = "m.marca AS marca_nombre";
    $selectCols[] = "mo.modelo AS modelo_nombre";
    if ($hasColor) $selectCols[] = "c.color AS color_nombre";
    if (sx_table_exists($pdo, $db, 'mercaderia_referencia')) {
        $selectCols[] = "ref.referencia AS referencia_nombre";
    }
    $joinStockSaldo = $requiresSaldoOrder
        ? (
            $hasProductoStockTable
                ? "LEFT JOIN (
                        SELECT ps.idproducto, SUM(COALESCE(ps.{$productoStockValueExpr}, 0)) AS saldo_calc
                        FROM {$db}.{$productoStockTable} ps
                        GROUP BY ps.idproducto
                   ) stk ON stk.idproducto = p.idproducto"
                : "LEFT JOIN (
                        SELECT ep.idproducto, SUM(ep.entrada) - SUM(ep.salida) AS saldo_calc
                        FROM {$db}.extracto_productos ep
                        WHERE ep.estado = 1
                        GROUP BY ep.idproducto
                   ) stk ON stk.idproducto = p.idproducto"
        )
        : "";
    $sql = "SELECT " . implode(", ", $selectCols) . "
            FROM {$db}.tblproductos p
            LEFT JOIN {$db}.mercaderia_grupo g ON g.id = p.grupo
            LEFT JOIN {$db}.mercaderia_marca m ON m.id = p.marca
            LEFT JOIN {$db}.mercaderia_modelo mo ON mo.id = p.modelo
            " . ($hasColor ? "LEFT JOIN {$db}.mercaderia_color c ON c.id = p.color" : "") . "
            " . (sx_table_exists($pdo, $db, 'mercaderia_referencia') ? "LEFT JOIN {$db}.mercaderia_referencia ref ON ref.id = p.referencia" : "") . "
            {$joinStockSaldo}
            {$whereSQL}
            ORDER BY {$orderCol} {$dir}
            LIMIT :lim OFFSET :off";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resolver mapa de imagen principal en R2 por producto (si existe tabla).
    $driveFotoMap = [];
    $driveFotoMetaMap = [];
    if (!$fastMode && $hasProductoImagenesTable && !empty($productos)) {
        try {
            $idsDrive = array_map(static fn($p) => (int)$p['idproducto'], $productos);
            $idsDrive = array_values(array_unique(array_filter($idsDrive)));
            if (!empty($idsDrive)) {
                $inDrive = implode(',', array_fill(0, count($idsDrive), '?'));
                $sqlDrive = "SELECT idproducto, drive_file_id, url, principal, orden, id
                             FROM {$db}.producto_imagenes
                             WHERE idproducto IN ({$inDrive})
                             ORDER BY idproducto ASC, principal DESC, orden ASC, id ASC";
                $stDrive = $pdo->prepare($sqlDrive);
                $stDrive->execute($idsDrive);
                foreach ($stDrive->fetchAll(PDO::FETCH_ASSOC) as $imgRow) {
                    $idp = (int)($imgRow['idproducto'] ?? 0);
                    $url = trim((string)($imgRow['url'] ?? ''));
                    if ($idp <= 0 || $url === '') continue;
                    if (!isset($driveFotoMap[$idp])) {
                    $driveFotoMap[$idp] = $url;
                    $driveFotoMetaMap[$idp] = [
                        'file_id' => (string)($imgRow['drive_file_id'] ?? ''),
                        'url' => $url,
                    ];
                }
            }
            }
        } catch (Throwable $e) {
            // Si falla Drive map, mantener fallback actual sin romper endpoint.
            error_log('[productos/list] drive map omitido: ' . $e->getMessage());
        }
    }

    // Normalizar campos para el frontend
    $imgBaseDir  = dirname(__DIR__, 2) . "/_lib/file/img/productos/{$db}/";
    $imgBasePath = "/public/_lib/file/img/productos/{$db}/";
    $cacheBaseDir  = dirname(__DIR__, 2) . "/_lib/file/img/productos_cache/{$db}/";
    $cacheBasePath = "/public/_lib/file/img/productos_cache/{$db}/";

    foreach ($productos as &$prod) {
        // Resolver código de barra: prioridad campo propio, luego tabla auxiliar
        if (!isset($prod['codigo_barra']) || empty($prod['codigo_barra'])) {
            $prod['codigo_barra'] = '';
        }

        // Resolver imagen
        // Prioridad: 1) R2 (producto_imagenes), 2) servidor/nube actual (foto_url),
        //            3) archivo local, 4) cache local.
        $id = (int)$prod['idproducto'];
        $fallbackServerUrl = trim((string)($prod['foto_url'] ?? ''));
        $driveUrl = trim((string)($driveFotoMap[$id] ?? ''));

        if ($driveUrl !== '') {
            $variants = ImageVariantService::deriveVariantUrls(
                (string)($driveFotoMetaMap[$id]['file_id'] ?? ''),
                $driveUrl
            );
            $prod['foto_thumb_url'] = $variants['thumb'] ?? $driveUrl;
            $prod['foto_small_url'] = $variants['small'] ?? $driveUrl;
            $prod['foto_medium_url'] = $variants['medium'] ?? $driveUrl;
            $prod['foto_large_url'] = $variants['large'] ?? $driveUrl;
            $prod['foto_url'] = $prod['foto_small_url'] ?: $driveUrl;
        } else {
            $prod['foto_url'] = null;
            if ($fallbackServerUrl !== '') {
                if (!$prod['foto_url'] && sx_is_usable_foto_url($fallbackServerUrl)) {
                    $variants = ImageVariantService::deriveVariantUrls('', $fallbackServerUrl);
                    $prod['foto_thumb_url'] = $variants['thumb'] ?? $fallbackServerUrl;
                    $prod['foto_small_url'] = $variants['small'] ?? $fallbackServerUrl;
                    $prod['foto_medium_url'] = $variants['medium'] ?? $fallbackServerUrl;
                    $prod['foto_large_url'] = $variants['large'] ?? $fallbackServerUrl;
                    $prod['foto_url'] = $prod['foto_small_url'] ?: $fallbackServerUrl;
                }
            }
            if (!$fastMode) {
                if (!$prod['foto_url'] && is_dir($imgBaseDir)) {
                    $files = glob($imgBaseDir . $id . "_*");
                    if (!empty($files)) {
                        $prod['foto_url'] = $imgBasePath . basename($files[0]) . '?t=' . filemtime($files[0]);
                    }
                }
                if (!$prod['foto_url'] && file_exists($cacheBaseDir . $id . '.webp')) {
                    $prod['foto_url'] = $cacheBasePath . $id . '.webp?t=' . filemtime($cacheBaseDir . $id . '.webp');
                }
            }
            // 5) Último fallback: proxy Pixabay/DuckDuckGo (genera URL para carga lazy)
            if (!$prod['foto_url']) {
                $desc = trim((string)($prod['desproducto'] ?? ''));
                if ($desc !== '' && $id > 0) {
                    $proxyUrl = '/public/pos/api/imagen_proxy.php?id=' . $id . '&q=' . urlencode($desc);
                    $prod['foto_url']        = $proxyUrl;
                    $prod['foto_thumb_url']  = $proxyUrl;
                    $prod['foto_small_url']  = $proxyUrl;
                    $prod['foto_medium_url'] = $proxyUrl;
                    $prod['foto_large_url']  = $proxyUrl;
                }
            }
            if (empty($prod['foto_thumb_url'])) $prod['foto_thumb_url'] = $prod['foto_url'];
            if (empty($prod['foto_small_url'])) $prod['foto_small_url'] = $prod['foto_url'];
            if (empty($prod['foto_medium_url'])) $prod['foto_medium_url'] = $prod['foto_url'];
            if (empty($prod['foto_large_url'])) $prod['foto_large_url'] = $prod['foto_url'];
        }

        // Defaults para campos que pueden no existir
        if (!isset($prod['moneda']))        $prod['moneda'] = 'PYG';
        if (!isset($prod['unidad_medida'])) $prod['unidad_medida'] = '';
        if (!isset($prod['web']))           $prod['web'] = '';
        if (!isset($prod['publicar_web']))  $prod['publicar_web'] = 0;
        if (!isset($prod['descontinuado'])) $prod['descontinuado'] = 0;
    }
    unset($prod);

    // Stats rápidas (cache corta en sesión para evitar full scan en cada apertura)
    $statsRow = ['total' => 0, 'activos' => 0, 'stock_bajo' => 0, 'sin_stock' => 0];
    if ($withStats) {
        $statsCacheKey = "productos_stats_{$id_empresa}";
        $statsNow = time();
        if (isset($_SESSION[$statsCacheKey]['ts'], $_SESSION[$statsCacheKey]['row'])) {
            if (($statsNow - (int)$_SESSION[$statsCacheKey]['ts']) <= 120) {
                $statsRow = $_SESSION[$statsCacheKey]['row'];
            }
        }
        if (!is_array($statsRow) || !isset($statsRow['total'])) {
            $statsWhere = "";
            if ($hasProductoStockTable) {
                $statsSQL = "SELECT 
                                COUNT(*) as total,
                                SUM(CASE WHEN " . ($estadoCol !== '' ? "p.{$estadoCol}" : "1") . " = 1 THEN 1 ELSE 0 END) as activos,
                                SUM(CASE WHEN " . ($estadoCol !== '' ? "p.{$estadoCol}" : "1") . " = 1 AND COALESCE(stk.saldo_calc,0) <= p.stock_minimo AND p.stock_minimo > 0 AND COALESCE(stk.saldo_calc,0) > 0 THEN 1 ELSE 0 END) as stock_bajo,
                                SUM(CASE WHEN " . ($estadoCol !== '' ? "p.{$estadoCol}" : "1") . " = 1 AND COALESCE(stk.saldo_calc,0) <= 0 THEN 1 ELSE 0 END) as sin_stock
                             FROM {$db}.tblproductos p
                             LEFT JOIN (
                                SELECT ps.idproducto, SUM(COALESCE(ps.{$productoStockValueExpr}, 0)) AS saldo_calc
                                FROM {$db}.{$productoStockTable} ps
                                GROUP BY ps.idproducto
                             ) stk ON stk.idproducto = p.idproducto";
            } elseif ($hasExtractoTable) {
                $statsSQL = "SELECT 
                                COUNT(*) as total,
                                SUM(CASE WHEN " . ($estadoCol !== '' ? "p.{$estadoCol}" : "1") . " = 1 THEN 1 ELSE 0 END) as activos,
                                SUM(CASE WHEN " . ($estadoCol !== '' ? "p.{$estadoCol}" : "1") . " = 1 AND COALESCE(stk.saldo_calc,0) <= p.stock_minimo AND p.stock_minimo > 0 AND COALESCE(stk.saldo_calc,0) > 0 THEN 1 ELSE 0 END) as stock_bajo,
                                SUM(CASE WHEN " . ($estadoCol !== '' ? "p.{$estadoCol}" : "1") . " = 1 AND COALESCE(stk.saldo_calc,0) <= 0 THEN 1 ELSE 0 END) as sin_stock
                             FROM {$db}.tblproductos p
                             LEFT JOIN (
                                SELECT ep.idproducto, SUM(ep.entrada) - SUM(ep.salida) AS saldo_calc
                                FROM {$db}.extracto_productos ep
                                WHERE ep.estado = 1
                                GROUP BY ep.idproducto
                             ) stk ON stk.idproducto = p.idproducto";
            } else {
                $statsSQL = "SELECT 
                                COUNT(*) as total,
                                SUM(CASE WHEN " . ($estadoCol !== '' ? $estadoCol : "1") . " = 1 THEN 1 ELSE 0 END) as activos,
                                0 as stock_bajo,
                                SUM(CASE WHEN " . ($estadoCol !== '' ? $estadoCol : "1") . " = 1 THEN 1 ELSE 0 END) as sin_stock
                             FROM {$db}.tblproductos
                             {$statsWhere}";
            }
            $statsRow = $pdo->query($statsSQL)->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'activos' => 0, 'stock_bajo' => 0, 'sin_stock' => 0];
            $_SESSION[$statsCacheKey] = ['ts' => $statsNow, 'row' => $statsRow];
        }
    }

    $totalPages = max(1, (int)ceil($total / $limit));

    // Cargar precios por tipo (mercaderia_precio) para cada producto
    try {
        if (!empty($productos)) {
            $lookupCodigos = [];
            foreach ($productos as $p) {
                $lookupCodigos[] = (int)$p['idproducto'];
                $cveInt = (int)$p['cve_producto'];
                if ($cveInt > 0 && $cveInt !== (int)$p['idproducto']) $lookupCodigos[] = $cveInt;
            }
            $lookupCodigos = array_values(array_unique(array_filter($lookupCodigos)));

            if (!empty($lookupCodigos)) {
                $paramsPr = $lookupCodigos;
                $inPH = implode(',', array_fill(0, count($lookupCodigos), '?'));
                $wherePr = "mp.codigo IN ({$inPH})";
                if (!empty($tiposPrecioPermitidos)) {
                    $inTipos = implode(',', array_fill(0, count($tiposPrecioPermitidos), '?'));
                    $wherePr .= " AND mp.tipo IN ({$inTipos})";
                    $paramsPr = array_merge($paramsPr, $tiposPrecioPermitidos);
                }

                $stmtPr = $pdo->prepare("SELECT mp.codigo, mp.tipo, tp.tipo AS tipo_nombre, mp.precio
                    FROM {$db}.mercaderia_precio mp
                    LEFT JOIN {$db}.tipo_precio tp ON tp.id = mp.tipo
                    WHERE {$wherePr}
                    ORDER BY mp.tipo");
                $stmtPr->execute($paramsPr);
                $preciosMap = [];
                foreach ($stmtPr->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                    $preciosMap[(int)$pr['codigo']][] = [
                        'tipo' => (int)$pr['tipo'],
                        'tipo_nombre' => $pr['tipo_nombre'] ?? ('Precio ' . $pr['tipo']),
                        'precio' => (float)$pr['precio'],
                    ];
                }
                foreach ($productos as &$prod) {
                    $id = (int)$prod['idproducto'];
                    $cveInt = (int)$prod['cve_producto'];
                    $prod['precios'] = $preciosMap[$id] ?? $preciosMap[$cveInt] ?? [];
                }
                unset($prod);
            }
        }
    } catch (Exception $e) {
        // tabla mercaderia_precio o tipo_precio no existe en este tenant
        foreach ($productos as &$prod) { $prod['precios'] = []; }
        unset($prod);
    }

    // Cargar stock por sucursal para cada producto
    try {
        if (!empty($productos)) {
            // Catálogo de sucursales para mostrar siempre todas, aunque no tengan movimientos.
            // Referencia única: {$db}.sucursales
            $sucursalesCatalog = [];
            if (sx_table_exists($pdo, $db, 'sucursales')) {
                $idCol = 'id_sucursal';
                if (!in_array($idCol, $pdo->query("SHOW COLUMNS FROM {$db}.sucursales")->fetchAll(PDO::FETCH_COLUMN), true)) {
                    $idCol = 'id';
                }
                $nameCol = 'sucursal';
                $sucCols = $pdo->query("SHOW COLUMNS FROM {$db}.sucursales")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array($nameCol, $sucCols, true)) {
                    $nameCol = in_array('nombre', $sucCols, true) ? 'nombre' : $idCol;
                }
                $stSuc = $pdo->query("SELECT {$idCol} AS id_sucursal, {$nameCol} AS sucursal FROM {$db}.sucursales ORDER BY {$idCol}");
                foreach (($stSuc->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
                    $idS = (int)($r['id_sucursal'] ?? 0);
                    if ($idS <= 0) continue;
                    $sucursalesCatalog[$idS] = trim((string)($r['sucursal'] ?? '')) ?: ('Suc. ' . $idS);
                }
            }

            $ids = array_map(fn($p) => (int)$p['idproducto'], $productos);
            $inPH = implode(',', array_fill(0, count($ids), '?'));
            $stockSql = $hasProductoStockTable
                ? "SELECT ps.idproducto, ps.id_sucursal,
                          COALESCE(ps.{$productoStockValueExpr}, 0) AS stock
                   FROM {$db}.{$productoStockTable} ps
                   WHERE ps.idproducto IN ({$inPH})
                   ORDER BY ps.id_sucursal"
                : "SELECT ep.idproducto, ep.id_sucursal,
                          SUM(ep.entrada) - SUM(ep.salida) AS stock
                   FROM {$db}.extracto_productos ep
                   WHERE ep.idproducto IN ({$inPH}) AND ep.estado = 1
                   GROUP BY ep.idproducto, ep.id_sucursal
                   ORDER BY ep.id_sucursal";
            $stmtSt = $pdo->prepare($stockSql);
            $stmtSt->execute($ids);
            $stockByProdSuc = [];
            foreach ($stmtSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $idProd = (int)$row['idproducto'];
                $idSuc = (int)$row['id_sucursal'];
                if ($idSuc <= 0) continue;
                $stockVal = (float)$row['stock'];
                $stockByProdSuc[$idProd][$idSuc] = $stockVal;
                // Si aparece una sucursal con movimiento que no estaba en catálogo, incluirla.
                if (!isset($sucursalesCatalog[$idSuc])) {
                    $sucursalesCatalog[$idSuc] = 'Suc. ' . $idSuc;
                }
            }

            ksort($sucursalesCatalog, SORT_NUMERIC);
            if ($idSucursalSesion > 0 && isset($sucursalesCatalog[$idSucursalSesion])) {
                $primeraSucursal = [$idSucursalSesion => $sucursalesCatalog[$idSucursalSesion]];
                unset($sucursalesCatalog[$idSucursalSesion]);
                $sucursalesCatalog = $primeraSucursal + $sucursalesCatalog;
            }
            foreach ($productos as &$prod) {
                $idProd = (int)$prod['idproducto'];
                $listaSuc = [];
                $saldoCalc = 0.0;
                foreach ($sucursalesCatalog as $idSuc => $nombreSuc) {
                    $val = (float)($stockByProdSuc[$idProd][$idSuc] ?? 0);
                    $saldoCalc += $val;
                    $listaSuc[] = [
                        'id_sucursal' => (int)$idSuc,
                        'sucursal'    => $nombreSuc,
                        'stock'       => $val,
                    ];
                }
                $prod['stock_sucursales'] = $listaSuc;
                $prod['saldo'] = (float)$saldoCalc;
            }
            unset($prod);
        }
    } catch (Exception $e) {
        foreach ($productos as &$prod) { $prod['stock_sucursales'] = []; }
        unset($prod);
    }

    // Cargar códigos de barra (tabla codigo_barra) para cada producto
    if ($hasCBTable) {
        try {
            if (!empty($productos)) {
                $ids = array_map(fn($p) => (int)$p['idproducto'], $productos);
                $inPH = implode(',', array_fill(0, count($ids), '?'));
                $stmtCB = $pdo->prepare("SELECT id_producto, codigo_barra FROM {$db}.codigo_barra WHERE id_producto IN ({$inPH}) ORDER BY id");
                $stmtCB->execute($ids);
                $cbMap = [];
                foreach ($stmtCB->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $cbMap[(int)$row['id_producto']][] = $row['codigo_barra'];
                }
                foreach ($productos as &$prod) {
                    $listaCodigos = $cbMap[(int)$prod['idproducto']] ?? [];
                    $prod['codigos_barra'] = $listaCodigos;
                    if ((empty($prod['codigo_barra']) || $prod['codigo_barra'] === null) && !empty($listaCodigos)) {
                        $prod['codigo_barra'] = (string)$listaCodigos[0];
                    }
                }
                unset($prod);
            }
        } catch (Exception $e) {
            foreach ($productos as &$prod) { $prod['codigos_barra'] = []; }
            unset($prod);
        }
    } else {
        foreach ($productos as &$prod) { $prod['codigos_barra'] = []; }
        unset($prod);
    }

    // Marcar si el producto tiene movimientos de stock (extracto_productos)
    try {
        if (!empty($productos) && sx_table_exists($pdo, $db, 'extracto_productos')) {
            $ids = array_map(fn($p) => (int)$p['idproducto'], $productos);
            $ids = array_values(array_unique(array_filter($ids)));
            if (!empty($ids)) {
                $inPH = implode(',', array_fill(0, count($ids), '?'));
                $stmtMv = $pdo->prepare("SELECT idproducto, COUNT(*) AS c
                    FROM {$db}.extracto_productos
                    WHERE idproducto IN ({$inPH})
                    GROUP BY idproducto");
                $stmtMv->execute($ids);
                $movMap = [];
                foreach ($stmtMv->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $movMap[(int)$row['idproducto']] = ((int)$row['c'] > 0);
                }
                foreach ($productos as &$prod) {
                    $prod['tiene_movimientos'] = (bool)($movMap[(int)$prod['idproducto']] ?? false);
                }
                unset($prod);
            }
        }
    } catch (Exception $e) {
        foreach ($productos as &$prod) { $prod['tiene_movimientos'] = false; }
        unset($prod);
    }
    foreach ($productos as &$prod) {
        if (!isset($prod['tiene_movimientos'])) $prod['tiene_movimientos'] = false;
    }
    unset($prod);

    echo json_encode([
        'success' => true,
        'data' => $productos,
        'pagination' => [
            'page' => $page,
            'per_page' => $limit,
            'total' => $total,
            'total_pages' => $totalPages
        ],
        'stats' => [
            'total'     => (int)($statsRow['total'] ?? 0),
            'activos'   => (int)($statsRow['activos'] ?? 0),
            'stockBajo' => (int)($statsRow['stock_bajo'] ?? 0),
            'sinStock'  => (int)($statsRow['sin_stock'] ?? 0),
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
