<?php

/**
 * POS API - Productos
 * Búsqueda de productos y categorías
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../../../src/Services/ImageVariantService.php';

$action = $_GET['action'] ?? '';
$requestedEmpresa = (int)($_GET['id_empresa'] ?? 0);
$sessionEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
$id_empresa = $requestedEmpresa > 0 ? $requestedEmpresa : ($sessionEmpresa > 0 ? $sessionEmpresa : 169);
$fastMode = (int)($_GET['fast'] ?? 0) === 1;

try {
    // Obtener conexión maestray de empresa
    $masterConn = getMasterConnection();

    // 1. Priorizar id_empresa y precio_def desde sec_users (Fuente de Verdad)
    $id_login = (int)($_SESSION['id_login'] ?? $_SESSION['id_usuario'] ?? 0);
    $precioTipo = 1; // Default: precio 1

    if ($id_login > 0) {
        $stmtUser = $masterConn->prepare("SELECT id_empresa, precio_def FROM sec_users WHERE id_login = :id");
        $stmtUser->execute([':id' => $id_login]);
        $userData = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if ($userData) {
            // Respetar empresa enviada por POS/sesión activa; solo usar sec_users como fallback.
            if ($requestedEmpresa <= 0 && $sessionEmpresa <= 0) {
                $id_empresa = (int)$userData['id_empresa'];
            }
            $precioTipo = (int)($userData['precio_def'] ?: 1);
        }
    }

    // Override precio tipo si viene por parámetro
    if (isset($_GET['tipo_precio']) && (int)$_GET['tipo_precio'] > 0) {
        $precioTipo = (int)$_GET['tipo_precio'];
    }

    // 2. Obtener DB de la empresa usando configuración centralizada
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];

    // Auto-optimización de índices (1 vez por día por sesión y empresa)
    $ensureIndex = static function (PDO $pdo, string $schema, string $table, string $indexName, string $columns): void {
        try {
            $stmt = $pdo->prepare("
                SELECT 1
                FROM information_schema.statistics
                WHERE table_schema = :schema
                  AND table_name = :table
                  AND index_name = :index
                LIMIT 1
            ");
            $stmt->execute([
                ':schema' => $schema,
                ':table' => $table,
                ':index' => $indexName,
            ]);
            if ($stmt->fetchColumn()) {
                return;
            }

            $sql = "CREATE INDEX `{$indexName}` ON `{$schema}`.`{$table}` ({$columns})";
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Silencioso: no romper flujo del POS por un índice ya existente o permisos limitados.
        }
    };

    $idxSessionKey = 'pos_idx_opt_' . $dbName . '_' . date('Ymd');
    if (empty($_SESSION[$idxSessionKey])) {
        $ensureIndex($pdo, $dbName, 'tblproductos', 'idx_pos_estado_codigo', '`Estado`, `cve_producto`');
        $ensureIndex($pdo, $dbName, 'tblproductos', 'idx_pos_estado_referencia', '`Estado`, `referencia`');
        $ensureIndex($pdo, $dbName, 'tblproductos', 'idx_pos_estado_desproducto', '`Estado`, `desproducto`(120)');
        $ensureIndex($pdo, $dbName, 'tblproductos', 'idx_pos_estado_grupo', '`Estado`, `grupo`');

        $ensureIndex($pdo, $dbName, 'codigo_barra', 'idx_pos_cb_codigo', '`codigo_barra`');
        $ensureIndex($pdo, $dbName, 'codigo_barra', 'idx_pos_cb_producto', '`id_producto`');

        $ensureIndex($pdo, $dbName, 'mercaderia_precio', 'idx_pos_mp_codigo_tipo', '`codigo`, `tipo`');
        $ensureIndex($pdo, $dbName, 'extracto_productos', 'idx_pos_ep_estado_sucursal_producto', '`estado`, `id_sucursal`, `idproducto`');
        $ensureIndex($pdo, $dbName, 'extracto_productos', 'idx_pos_ep_salida_fecha_producto', '`salida`, `fecha`, `idproducto`');
        $_SESSION[$idxSessionKey] = 1;
    }

    // Ruta base de imágenes de productos (locales y caché)
    $imgBasePath = "/public/_lib/file/img/productos/{$dbName}/";
    $imgBaseDir = dirname(__DIR__, 2) . "/_lib/file/img/productos/{$dbName}/";
    $cacheBasePath = "/public/_lib/file/img/productos_cache/{$dbName}/";
    $cacheBaseDir = dirname(__DIR__, 2) . "/_lib/file/img/productos_cache/{$dbName}/";

    // Fallback legado: imagen local o caché.
    $getLocalProductImage = function ($idproducto) use ($imgBaseDir, $imgBasePath, $cacheBaseDir, $cacheBasePath) {
        // 1. Buscar imagen local subida por el usuario
        if (is_dir($imgBaseDir)) {
            $files = glob($imgBaseDir . $idproducto . "_*");
            if (!empty($files)) {
                $filePath = $files[0];
                $mtime = filemtime($filePath);
                return [
                    'url' => $imgBasePath . basename($filePath),
                    'mtime' => $mtime
                ];
            }
        }

        // 2. Buscar imagen cacheada del proxy
        $cachedFile = $cacheBaseDir . $idproducto . '.webp';
        if (file_exists($cachedFile)) {
            $mtime = filemtime($cachedFile);
            return [
                'url' => $cacheBasePath . $idproducto . '.webp',
                'mtime' => $mtime
            ];
        }

        return null;
    };

    // Sucursal del usuario
    $id_sucursal = (int)($_SESSION['id_sucursal'] ?? 1);

    $tableExists = static function (PDO $pdo, string $schema, string $table): bool {
        try {
            $stmt = $pdo->query("SHOW TABLES FROM `$schema` LIKE " . $pdo->quote($table));
            return (bool)($stmt && $stmt->fetch(PDO::FETCH_NUM));
        } catch (Throwable $e) {
            return false;
        }
    };
    $columnExists = static function (PDO $pdo, string $schema, string $table, string $column): bool {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$schema`.`$table` LIKE " . $pdo->quote($column));
            return (bool)($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return false;
        }
    };
    $getTableColumns = static function (PDO $pdo, string $schema, string $table): array {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$schema`.`$table`");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $set = [];
            foreach ($rows as $r) {
                $f = (string)($r['Field'] ?? '');
                if ($f !== '') $set[$f] = true;
            }
            return $set;
        } catch (Throwable $e) {
            return [];
        }
    };
    $resolveProductsSource = static function (PDO $pdo, string $schema) use ($tableExists, $getTableColumns): ?array {
        $candidateTables = ['tblproductos', 'mercaderias', 'productos'];
        foreach ($candidateTables as $table) {
            if (!$tableExists($pdo, $schema, $table)) continue;
            $cols = $getTableColumns($pdo, $schema, $table);
            if (empty($cols)) continue;
            $idCol = isset($cols['idproducto']) ? 'idproducto' : (isset($cols['id']) ? 'id' : null);
            if (!$idCol) continue;
            return ['table' => $table, 'cols' => $cols, 'id_col' => $idCol];
        }
        return null;
    };

    $attachProductImages = static function (array &$items, string $idKey = 'id', bool $allowProxyFallback = true) use ($pdo, $dbName, $tableExists, $columnExists, $getLocalProductImage): void {
        if (empty($items)) {
            return;
        }

        $ids = [];
        foreach ($items as $row) {
            $id = (int)($row[$idKey] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if (empty($ids)) {
            return;
        }

        $imageMap = [];
        $imageMetaMap = [];
        $imageListMap = [];
        $mtimeMap = [];

        // 1) Prioridad R2 desde producto_imagenes.
        if ($tableExists($pdo, $dbName, 'producto_imagenes')) {
            try {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $sql = "SELECT idproducto, drive_file_id, url, UNIX_TIMESTAMP(created_at) AS ts
                        FROM {$dbName}.producto_imagenes
                        WHERE idproducto IN ($ph)
                        ORDER BY idproducto, principal DESC, orden ASC, id ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($ids);
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $pid = (int)($r['idproducto'] ?? 0);
                    if ($pid <= 0) {
                        continue;
                    }
                    $url = trim((string)($r['url'] ?? ''));
                    if ($url === '') {
                        continue;
                    }
                    $variants = ImageVariantService::deriveVariantUrls((string)($r['drive_file_id'] ?? ''), $url);
                    $smallUrl = $variants['small'] ?? $url;
                    if (!isset($imageListMap[$pid])) {
                        $imageListMap[$pid] = [];
                    }
                    $imageListMap[$pid][] = $smallUrl;
                    if (!isset($imageMap[$pid])) {
                        $imageMap[$pid] = $smallUrl;
                        $imageMetaMap[$pid] = $variants;
                        $mtimeMap[$pid] = (int)($r['ts'] ?? time());
                    }
                }
            } catch (Throwable $e) {
                // No romper el POS por error de hidratación de imágenes.
            }
        }

        $isUsableFotoUrl = static function (string $url) use ($dbName): bool {
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
        };

        // 2) Fallback cloud/server desde tblproductos.foto_url.
        if (count($imageMap) < count($ids) && $tableExists($pdo, $dbName, 'tblproductos') && $columnExists($pdo, $dbName, 'tblproductos', 'foto_url')) {
            try {
                $missing = [];
                foreach ($ids as $pid) {
                    if (!isset($imageMap[$pid])) $missing[] = $pid;
                }
                if (!empty($missing)) {
                    $ph = implode(',', array_fill(0, count($missing), '?'));
                    $sql = "SELECT idproducto, foto_url
                            FROM {$dbName}.tblproductos
                            WHERE idproducto IN ($ph)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($missing);
                    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $pid = (int)($r['idproducto'] ?? 0);
                        if ($pid <= 0 || isset($imageMap[$pid])) {
                            continue;
                        }
                        $url = trim((string)($r['foto_url'] ?? ''));
                        if ($url === '') {
                            continue;
                        }
                        if (!$isUsableFotoUrl($url)) {
                            continue;
                        }
                        $variants = ImageVariantService::deriveVariantUrls('', $url);
                        $imageMap[$pid] = $variants['small'] ?? $url;
                        $imageMetaMap[$pid] = $variants;
                        $mtimeMap[$pid] = time();
                    }
                }
            } catch (Throwable $e) {
                // No romper el POS por error de hidratación de imágenes.
            }
        }

        // 3) Último fallback: local/cache legado.
        foreach ($items as &$row) {
            $pid = (int)($row[$idKey] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            if (isset($imageMap[$pid])) {
                $row['imagen'] = $imageMap[$pid];
                $row['imagen_thumb'] = $imageMetaMap[$pid]['thumb'] ?? $row['imagen'];
                $row['imagen_small'] = $imageMetaMap[$pid]['small'] ?? $row['imagen'];
                $row['imagen_medium'] = $imageMetaMap[$pid]['medium'] ?? $row['imagen'];
                $row['imagen_large'] = $imageMetaMap[$pid]['large'] ?? $row['imagen'];
                $row['imagenes'] = array_values(array_unique($imageListMap[$pid] ?? [$row['imagen']]));
                $row['imagen_updated'] = $mtimeMap[$pid] ?? time();
                continue;
            }
            $local = $getLocalProductImage($pid);
            if ($local) {
                $row['imagen'] = $local['url'];
                $row['imagen_thumb'] = $local['url'];
                $row['imagen_small'] = $local['url'];
                $row['imagen_medium'] = $local['url'];
                $row['imagen_large'] = $local['url'];
                $row['imagenes'] = [$local['url']];
                $row['imagen_updated'] = $local['mtime'];
                continue;
            }

            // 4) Último fallback opcional: proxy ilustrativo externo.
            if (!$allowProxyFallback) {
                continue;
            }

            $desc = trim((string)($row['descripcion'] ?? $row['desproducto'] ?? ''));
            if ($desc !== '' && $pid > 0) {
                $proxyUrl = '/public/pos/api/imagen_proxy.php?id=' . $pid . '&q=' . urlencode($desc);
                $row['imagen'] = $proxyUrl;
                $row['imagen_thumb'] = $proxyUrl;
                $row['imagen_small'] = $proxyUrl;
                $row['imagen_medium'] = $proxyUrl;
                $row['imagen_large'] = $proxyUrl;
                $row['imagenes'] = [$proxyUrl];
                $row['imagen_updated'] = time();
            }
        }
        unset($row);
    };

    $attachLocalCachedProductImages = static function (array &$items, string $idKey = 'id') use ($getLocalProductImage): void {
        if (empty($items)) {
            return;
        }
        foreach ($items as &$row) {
            $pid = (int)($row[$idKey] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $local = $getLocalProductImage($pid);
            if (!$local) {
                continue;
            }
            $row['imagen'] = $local['url'];
            $row['imagen_thumb'] = $local['url'];
            $row['imagen_small'] = $local['url'];
            $row['imagen_medium'] = $local['url'];
            $row['imagen_large'] = $local['url'];
            $row['imagenes'] = [$local['url']];
            $row['imagen_updated'] = $local['mtime'];
        }
        unset($row);
    };

    $canUseProductoStock = $tableExists($pdo, $dbName, 'producto_stock')
        && $columnExists($pdo, $dbName, 'producto_stock', 'idproducto')
        && $columnExists($pdo, $dbName, 'producto_stock', 'id_sucursal')
        && (
            $columnExists($pdo, $dbName, 'producto_stock', 'stock_disponible')
            || $columnExists($pdo, $dbName, 'producto_stock', 'stock_actual')
        );
    $productoStockValueColumn = $columnExists($pdo, $dbName, 'producto_stock', 'stock_disponible')
        ? 'stock_disponible'
        : 'stock_actual';

    $fetchSimpleProducts = static function (PDO $pdo, string $schema, int $limit, string $q = '', int $estado = 1, bool $attachImages = true, bool $useHighestMercPrice = false) use ($attachProductImages, $resolveProductsSource, $tableExists, $columnExists, $precioTipo, $id_sucursal, $canUseProductoStock, $productoStockValueColumn): array {
        $src = $resolveProductsSource($pdo, $schema);
        if (!$src) {
            return [];
        }
        $table = (string)$src['table'];
        $cols = (array)$src['cols'];
        $idCol = (string)$src['id_col'];
        $hasEstado = isset($cols['Estado']);
        $codigoExpr = isset($cols['cve_producto']) ? 'p.cve_producto' : (isset($cols['codigo']) ? 'p.codigo' : "''");
        $refExpr = isset($cols['referencia']) ? 'p.referencia' : "''";
        $descExpr = isset($cols['desproducto']) ? 'p.desproducto' : (isset($cols['descripcion']) ? 'p.descripcion' : "''");
        $basePrecioExpr = isset($cols['precio_venta']) ? 'p.precio_venta' : (isset($cols['precio']) ? 'p.precio' : '0');
        $baseStockExpr = isset($cols['saldo']) ? 'p.saldo' : (isset($cols['stock']) ? 'p.stock' : '0');
        $ivaExpr = isset($cols['iva']) ? 'p.iva' : '10';
        $controlaExpr = isset($cols['controla_stock']) ? 'p.controla_stock' : '0';
        $editaPrecioExpr = isset($cols['edita_precio']) ? 'p.edita_precio' : '0';
        $editableExpr = isset($cols['editable']) ? 'p.editable' : '0';
        $precioMinExpr = isset($cols['precio_compra']) ? 'p.precio_compra' : '0';
        $vendeSinStockExpr = isset($cols['vende_sin_stock']) ? 'p.vende_sin_stock' : '0';
        $usaSerialExpr = isset($cols['usaserial']) ? 'p.usaserial' : '0';
        $canJoinMercPrice = ($table === 'tblproductos') &&
            $tableExists($pdo, $schema, 'mercaderia_precio') &&
            $columnExists($pdo, $schema, 'mercaderia_precio', 'codigo') &&
            $columnExists($pdo, $schema, 'mercaderia_precio', 'tipo') &&
            $columnExists($pdo, $schema, 'mercaderia_precio', 'precio');
        $canJoinExtracto = !$canUseProductoStock && ($table === 'tblproductos') &&
            $tableExists($pdo, $schema, 'extracto_productos') &&
            $columnExists($pdo, $schema, 'extracto_productos', 'idproducto') &&
            $columnExists($pdo, $schema, 'extracto_productos', 'entrada') &&
            $columnExists($pdo, $schema, 'extracto_productos', 'salida') &&
            $columnExists($pdo, $schema, 'extracto_productos', 'estado') &&
            $columnExists($pdo, $schema, 'extracto_productos', 'id_sucursal');
        $precioExpr = $canJoinMercPrice ? "COALESCE(mp.precio, {$basePrecioExpr})" : "COALESCE({$basePrecioExpr}, 0)";
        $stockExpr = $canUseProductoStock
            ? "COALESCE(ps.stock, {$baseStockExpr})"
            : ($canJoinExtracto ? "COALESCE(ep.stock, {$baseStockExpr})" : "COALESCE({$baseStockExpr}, 0)");
        $joinSql = '';
        $params = [];
        if ($canJoinMercPrice) {
            if ($useHighestMercPrice) {
                $joinSql .= " LEFT JOIN (
                    SELECT codigo, MAX(precio) AS precio
                    FROM `$schema`.mercaderia_precio
                    GROUP BY codigo
                ) mp ON mp.codigo = p.`{$idCol}`";
            } else {
                $joinSql .= " LEFT JOIN (
                    SELECT codigo, precio
                    FROM `$schema`.mercaderia_precio
                    WHERE tipo = :mp_tipo
                    GROUP BY codigo
                ) mp ON mp.codigo = p.`{$idCol}`";
                $params[':mp_tipo'] = (int)$precioTipo;
            }
        }
        if ($canUseProductoStock && $table === 'tblproductos') {
            $joinSql .= " LEFT JOIN (
                SELECT idproducto, COALESCE({$productoStockValueColumn}, 0) AS stock
                FROM `$schema`.producto_stock
                WHERE id_sucursal = :id_sucursal
            ) ps ON ps.idproducto = p.`{$idCol}`";
            $params[':id_sucursal'] = (int)$id_sucursal;
        }
        if ($canJoinExtracto) {
            $joinSql .= " LEFT JOIN (
                SELECT idproducto, SUM(entrada - salida) AS stock
                FROM `$schema`.extracto_productos
                WHERE estado = 1 AND id_sucursal = :id_sucursal
                GROUP BY idproducto
            ) ep ON ep.idproducto = p.`{$idCol}`";
            $params[':id_sucursal'] = (int)$id_sucursal;
        }

        $sql = "SELECT
                    p.`{$idCol}` AS id,
                    {$codigoExpr} AS codigo,
                    {$refExpr} AS referencia,
                    {$descExpr} AS descripcion,
                    {$precioExpr} AS precio,
                    {$stockExpr} AS stock,
                    COALESCE({$ivaExpr}, 10) AS tasa_iva,
                    COALESCE({$controlaExpr}, 0) AS controla_stock,
                    COALESCE({$editaPrecioExpr}, 0) AS edita_precio,
                    COALESCE({$editableExpr}, 0) AS editable,
                    COALESCE({$precioMinExpr}, 0) AS precio_min,
                    COALESCE({$vendeSinStockExpr}, 0) AS vende_sin_stock,
                    COALESCE({$usaSerialExpr}, 0) AS usaserial
                FROM `$schema`.`{$table}` p
                {$joinSql}
                WHERE 1=1";
        if ($hasEstado) {
            $sql .= " AND p.Estado = :estado";
            $params[':estado'] = $estado;
        }
        if ($q !== '') {
            $sql .= " AND (
                {$codigoExpr} LIKE :q1
                OR {$refExpr} LIKE :q2
                OR {$descExpr} LIKE :q3
            )";
            $params[':q1'] = $q . '%';
            $params[':q2'] = $q . '%';
            $params[':q3'] = '%' . $q . '%';
        }
        $sql .= " ORDER BY p.`{$idCol}` DESC LIMIT " . max(1, min(100, $limit));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['detalle_cargado'] = 1;
            $row['precio_pendiente'] = 0;
            $row['stock_pendiente'] = 0;
        }
        unset($row);
        if ($attachImages) {
            $attachProductImages($rows, 'id');
        }
        return $rows;
    };

    $fetchUltraFastProducts = static function (
        PDO $pdo,
        string $schema,
        int $limit,
        string $q = '',
        int $estado = 1,
        int $category = 0,
        string $priceOp = '',
        float $priceVal = 0
    ) use ($attachProductImages, $id_sucursal): array {
        $validOps = ['<' => '<', '>' => '>', '=' => '='];
        $params = [':estado' => $estado];
        $sql = "SELECT
                    p.idproducto AS id,
                    p.cve_producto AS codigo,
                    p.referencia,
                    p.desproducto AS descripcion,
                    NULL AS precio,
                    NULL AS stock,
                    0 AS has_other_stock,
                    p.iva AS tasa_iva,
                    p.controla_stock,
                    p.edita_precio,
                    p.editable,
                    p.precio_compra AS precio_min,
                    p.vende_sin_stock,
                    COALESCE(p.usaserial, 0) AS usaserial,
                    p.grupo AS categoria_id,
                    1 AS precio_pendiente,
                    1 AS stock_pendiente,
                    0 AS detalle_cargado
                FROM {$schema}.tblproductos p
                WHERE p.Estado = :estado";
        if ($category > 0) {
            $sql .= " AND p.grupo = :cat";
            $params[':cat'] = $category;
        }
        if ($priceOp !== '' && $priceVal > 0 && isset($validOps[$priceOp])) {
            $sql .= " AND p.precio_venta {$validOps[$priceOp]} :price";
            $params[':price'] = $priceVal;
        }
        if ($q !== '') {
            $sql .= " AND (
                p.cve_producto LIKE :q_code
                OR p.referencia LIKE :q_ref
                OR p.desproducto LIKE :q_desc
            )";
            $params[':q_code'] = $q . '%';
            $params[':q_ref'] = $q . '%';
            $params[':q_desc'] = '%' . $q . '%';
            $sql .= " ORDER BY
                CASE
                    WHEN p.cve_producto = :q_exact_code THEN 0
                    WHEN p.referencia = :q_exact_ref THEN 1
                    WHEN p.cve_producto LIKE :q_prefix THEN 2
                    WHEN p.referencia LIKE :q_prefix_ref THEN 3
                    ELSE 4
                END,
                p.desproducto
                LIMIT " . max(1, min(100, $limit));
            $params[':q_exact_code'] = $q;
            $params[':q_exact_ref'] = $q;
            $params[':q_prefix'] = $q . '%';
            $params[':q_prefix_ref'] = $q . '%';
        } else {
            $sql .= " ORDER BY p.idproducto DESC LIMIT " . max(1, min(100, $limit));
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $attachProductImages($rows, 'id', true);
        return $rows;
    };

    $fetchRecentProductIds = static function (PDO $pdo, string $schema, int $limit, int $estado = 1): array {
        $limit = max(1, min(100, $limit));
        $stmt = $pdo->prepare("
            SELECT p.idproducto
            FROM {$schema}.tblproductos p
            WHERE p.Estado = :estado
            ORDER BY p.idproducto DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':estado' => $estado]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    };

    $normalizeSearchText = static function (?string $value): string {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            $text = mb_strtolower($text, 'UTF-8');
        } else {
            $text = strtolower($text);
        }

        if (class_exists('Transliterator')) {
            $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($tr) {
                $text = $tr->transliterate($text);
            }
        } else {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }

        $text = preg_replace('/[^a-z0-9]+/i', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        return trim($text);
    };

    $tokenizeSearchText = static function (?string $value) use ($normalizeSearchText): array {
        $normalized = $normalizeSearchText($value);
        if ($normalized === '') {
            return [];
        }
        return array_values(array_filter(explode(' ', $normalized), static fn($token) => $token !== ''));
    };

    $similarityPercent = static function (string $left, string $right): float {
        if ($left === '' || $right === '') {
            return 0.0;
        }
        similar_text($left, $right, $percent);
        return (float)$percent;
    };

    $buildSpellSuggestion = static function (string $query, array $candidateRows) use ($normalizeSearchText, $tokenizeSearchText, $similarityPercent): ?array {
        $originalTokens = $tokenizeSearchText($query);
        if (empty($originalTokens)) {
            return null;
        }

        $dictionary = [];
        foreach ($candidateRows as $row) {
            foreach ($tokenizeSearchText((string)($row['descripcion'] ?? '')) as $token) {
                if (strlen($token) < 3 || is_numeric($token)) {
                    continue;
                }
                $dictionary[$token] = true;
            }
        }
        $dictionary = array_keys($dictionary);
        if (empty($dictionary)) {
            return null;
        }

        $correctedTokens = [];
        $changed = false;

        foreach ($originalTokens as $token) {
            if (strlen($token) < 3 || is_numeric($token)) {
                $correctedTokens[] = $token;
                continue;
            }

            $bestToken = $token;
            $bestDistance = PHP_INT_MAX;
            $bestSimilarity = 0.0;

            foreach ($dictionary as $candidateToken) {
                if ($candidateToken === $token) {
                    $bestToken = $token;
                    $bestDistance = 0;
                    $bestSimilarity = 100.0;
                    break;
                }

                $lenDiff = abs(strlen($candidateToken) - strlen($token));
                if ($lenDiff > 3) {
                    continue;
                }

                $distance = levenshtein($token, $candidateToken);
                $similarity = $similarityPercent($token, $candidateToken);
                if ($distance < $bestDistance || ($distance === $bestDistance && $similarity > $bestSimilarity)) {
                    $bestToken = $candidateToken;
                    $bestDistance = $distance;
                    $bestSimilarity = $similarity;
                }
            }

            $maxDistance = max(1, (int)floor(strlen($token) * 0.34));
            if ($bestToken !== $token && $bestDistance <= $maxDistance && $bestSimilarity >= 55.0) {
                $correctedTokens[] = $bestToken;
                $changed = true;
            } else {
                $correctedTokens[] = $token;
            }
        }

        if (!$changed) {
            return null;
        }

        $suggestion = trim(implode(' ', $correctedTokens));
        if ($suggestion === '' || $normalizeSearchText($suggestion) === $normalizeSearchText($query)) {
            return null;
        }

        return [
            'text' => $suggestion,
            'reason' => 'spell'
        ];
    };

    $scoreSearchCandidate = static function (array $row, string $query, array $queryTokens) use ($normalizeSearchText, $tokenizeSearchText, $similarityPercent): float {
        $ref = trim((string)($row['referencia'] ?? ''));
        $barcode = trim((string)($row['barcode'] ?? ''));
        $desc = trim((string)($row['descripcion'] ?? ''));
        $normalizedQuery = $normalizeSearchText($query);
        $normalizedRef = $normalizeSearchText($ref);
        $normalizedBarcode = $normalizeSearchText($barcode);
        $normalizedDesc = $normalizeSearchText($desc);
        $descTokens = $tokenizeSearchText($desc);
        $lastQueryToken = !empty($queryTokens) ? (string)$queryTokens[count($queryTokens) - 1] : '';

        $score = 0.0;

        if ($normalizedQuery !== '') {
            if ($normalizedRef === $normalizedQuery) $score += 2600;
            if ($normalizedBarcode === $normalizedQuery) $score += 2500;
            if ($normalizedDesc === $normalizedQuery) $score += 2400;
            if ($normalizedRef !== '' && str_starts_with($normalizedRef, $normalizedQuery)) $score += 1300;
            if ($normalizedBarcode !== '' && str_starts_with($normalizedBarcode, $normalizedQuery)) $score += 1200;
            if ($normalizedDesc !== '' && str_starts_with($normalizedDesc, $normalizedQuery)) $score += 1100;
            if ($normalizedDesc !== '' && strpos($normalizedDesc, $normalizedQuery) !== false) $score += 700;
            if ($normalizedBarcode !== '' && strpos($normalizedBarcode, $normalizedQuery) !== false) $score += 620;

            $descSimilarity = $similarityPercent($normalizedQuery, $normalizedDesc);
            if ($descSimilarity > 0) {
                $score += $descSimilarity * 6;
            }
        }

        foreach ($queryTokens as $token) {
            if ($token === '') {
                continue;
            }

            if ($normalizedRef === $token) $score += 620;
            if ($normalizedBarcode === $token) $score += 640;
            if (in_array($token, $descTokens, true)) {
                $score += 520;
                continue;
            }
            if ($normalizedRef !== '' && str_contains($normalizedRef, $token)) $score += 280;
            if ($normalizedBarcode !== '' && str_contains($normalizedBarcode, $token)) $score += 260;
            if ($normalizedDesc !== '' && str_contains($normalizedDesc, $token)) $score += 240;

            foreach ($descTokens as $descToken) {
                if (strlen($token) < 3 || strlen($descToken) < 3) {
                    continue;
                }
                $distance = levenshtein($token, $descToken);
                $similarity = $similarityPercent($token, $descToken);
                $maxDistance = max(1, (int)floor(max(strlen($token), strlen($descToken)) * 0.25));
                if ($distance <= $maxDistance && $similarity >= 60.0) {
                    $score += 120 - ($distance * 18) + ($similarity * 0.3);
                    break;
                }
            }
        }

        if ($lastQueryToken !== '') {
            foreach ($descTokens as $descToken) {
                if ($descToken !== '' && str_starts_with($descToken, $lastQueryToken)) {
                    $score += 680;
                    break;
                }
            }
            if ($normalizedRef !== '' && str_starts_with($normalizedRef, $lastQueryToken)) $score += 620;
            if ($normalizedBarcode !== '' && str_starts_with($normalizedBarcode, $lastQueryToken)) $score += 600;
        }

        $score += max(0, 40 - strlen($normalizedDesc) * 0.15);
        return $score;
    };

    $enrichPopularAvailability = static function (array &$rows, string $idKey = 'id') use ($pdo, $dbName, $tableExists, $columnExists, $id_sucursal, $canUseProductoStock, $productoStockValueColumn): void {
        if (empty($rows)) {
            return;
        }
        if (
            !$canUseProductoStock &&
            (
                !$tableExists($pdo, $dbName, 'extracto_productos') ||
                !$columnExists($pdo, $dbName, 'extracto_productos', 'idproducto') ||
                !$columnExists($pdo, $dbName, 'extracto_productos', 'entrada') ||
                !$columnExists($pdo, $dbName, 'extracto_productos', 'salida') ||
                !$columnExists($pdo, $dbName, 'extracto_productos', 'estado') ||
                !$columnExists($pdo, $dbName, 'extracto_productos', 'id_sucursal')
            )
        ) {
            return;
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row[$idKey] ?? 0);
            if ($id > 0) $ids[$id] = true;
        }
        $ids = array_keys($ids);
        if (empty($ids)) {
            return;
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $localMap = [];
        $otherMap = [];

        try {
            if ($canUseProductoStock) {
                $stmtLocal = $pdo->prepare("
                    SELECT idproducto, COALESCE({$productoStockValueColumn}, 0) AS stock
                    FROM {$dbName}.producto_stock
                    WHERE id_sucursal = ? AND idproducto IN ($ph)
                ");
                $stmtLocal->execute(array_merge([$id_sucursal], $ids));
            } else {
                $stmtLocal = $pdo->prepare("
                    SELECT idproducto, SUM(entrada - salida) AS stock
                    FROM {$dbName}.extracto_productos
                    WHERE estado = 1 AND id_sucursal = ? AND idproducto IN ($ph)
                    GROUP BY idproducto
                ");
                $stmtLocal->execute(array_merge([$id_sucursal], $ids));
            }
            while ($r = $stmtLocal->fetch(PDO::FETCH_ASSOC)) {
                $localMap[(int)$r['idproducto']] = (float)($r['stock'] ?? 0);
            }

            if ($canUseProductoStock) {
                $stmtOther = $pdo->prepare("
                    SELECT idproducto, SUM(COALESCE({$productoStockValueColumn}, 0)) AS stock
                    FROM {$dbName}.producto_stock
                    WHERE id_sucursal != ? AND idproducto IN ($ph)
                    GROUP BY idproducto
                ");
                $stmtOther->execute(array_merge([$id_sucursal], $ids));
            } else {
                $stmtOther = $pdo->prepare("
                    SELECT idproducto, SUM(entrada - salida) AS stock
                    FROM {$dbName}.extracto_productos
                    WHERE estado = 1 AND id_sucursal != ? AND idproducto IN ($ph)
                    GROUP BY idproducto
                ");
                $stmtOther->execute(array_merge([$id_sucursal], $ids));
            }
            while ($r = $stmtOther->fetch(PDO::FETCH_ASSOC)) {
                $otherMap[(int)$r['idproducto']] = (float)($r['stock'] ?? 0);
            }
        } catch (Throwable $e) {
            return;
        }

        foreach ($rows as &$row) {
            $pid = (int)($row[$idKey] ?? 0);
            if ($pid <= 0) continue;
            if ((int)($row['controla_stock'] ?? 0) === -1) {
                $row['has_other_stock'] = 0;
                continue;
            }
            $row['stock'] = $localMap[$pid] ?? (float)($row['stock'] ?? 0);
            $row['has_other_stock'] = (($otherMap[$pid] ?? 0) > 0) ? 1 : 0;
        }
        unset($row);
    };

    $filterPopularVisibleProducts = static function (array $rows): array {
        return array_values(array_filter($rows, static function (array $row): bool {
            $controlaStock = (int)($row['controla_stock'] ?? 0);
            $vendeSinStock = (int)($row['vende_sin_stock'] ?? 0);
            $localStock = (float)($row['stock'] ?? 0);
            $hasOtherStock = (int)($row['has_other_stock'] ?? 0) === 1;

            if ($controlaStock === -1) {
                return true;
            }
            if ($vendeSinStock === 1) {
                return true;
            }
            return $localStock > 0 || $hasOtherStock;
        }));
    };

    $canUseHighestMercPrice = $tableExists($pdo, $dbName, 'mercaderia_precio')
        && $columnExists($pdo, $dbName, 'mercaderia_precio', 'codigo')
        && $columnExists($pdo, $dbName, 'mercaderia_precio', 'precio');
    $canUseProductSeries = $tableExists($pdo, $dbName, 'producto_series')
        && $columnExists($pdo, $dbName, 'producto_series', 'id')
        && $columnExists($pdo, $dbName, 'producto_series', 'idproducto')
        && $columnExists($pdo, $dbName, 'producto_series', 'serie')
        && $columnExists($pdo, $dbName, 'producto_series', 'estado');
    $highestMercPriceJoinSql = $canUseHighestMercPrice
        ? "LEFT JOIN (
                SELECT codigo, MAX(precio) AS precio
                FROM $dbName.mercaderia_precio
                GROUP BY codigo
            ) mp ON mp.codigo = p.idproducto"
        : '';
    $highestMercPriceExpr = $canUseHighestMercPrice
        ? 'COALESCE(mp.precio, p.precio_venta, 0)'
        : 'COALESCE(p.precio_venta, 0)';
    $stockLocalJoinSql = $canUseProductoStock
        ? "LEFT JOIN (
                SELECT idproducto, COALESCE({$productoStockValueColumn}, 0) AS stock
                FROM $dbName.producto_stock
                WHERE id_sucursal = ?"
        : "LEFT JOIN (
                SELECT idproducto, SUM(entrada - salida) AS stock
                FROM $dbName.extracto_productos
                WHERE estado = 1
                  AND id_sucursal = ?";
    $stockLocalJoinSqlClose = $canUseProductoStock
        ? "
            ) ep ON ep.idproducto = p.idproducto AND p.controla_stock != -1"
        : "
                GROUP BY idproducto
            ) ep ON ep.idproducto = p.idproducto AND p.controla_stock != -1";
    $stockOtherJoinSql = $canUseProductoStock
        ? "LEFT JOIN (
                SELECT idproducto, SUM(COALESCE({$productoStockValueColumn}, 0)) AS stock
                FROM $dbName.producto_stock
                WHERE id_sucursal != ?"
        : "LEFT JOIN (
                SELECT idproducto, SUM(entrada - salida) AS stock
                FROM $dbName.extracto_productos
                WHERE estado = 1
                  AND id_sucursal != ?";
    $stockOtherJoinSqlClose = $canUseProductoStock
        ? "
                GROUP BY idproducto
            ) ep_other ON ep_other.idproducto = p.idproducto AND p.controla_stock != -1"
        : "
                GROUP BY idproducto
            ) ep_other ON ep_other.idproducto = p.idproducto AND p.controla_stock != -1";
    $singleSucursalStockJoinSql = $canUseProductoStock
        ? "LEFT JOIN (
                SELECT idproducto, MAX(COALESCE({$productoStockValueColumn}, 0)) AS stock
                FROM $dbName.producto_stock
                WHERE id_sucursal = :id_sucursal
                GROUP BY idproducto
            ) ep ON ep.idproducto = p.idproducto AND p.controla_stock != -1"
        : "LEFT JOIN (
                SELECT idproducto, SUM(entrada - salida) AS stock
                FROM $dbName.extracto_productos
                WHERE estado = 1 AND id_sucursal = :id_sucursal
                GROUP BY idproducto
            ) ep ON ep.idproducto = p.idproducto AND p.controla_stock != -1";

    switch ($action) {
        case 'categories':
            // Obtener categorías/grupos
            if ($tableExists($pdo, $dbName, 'mercaderia_grupo')) {
                $stmt = $pdo->query("SELECT id, grupo AS nombre FROM $dbName.mercaderia_grupo ORDER BY grupo LIMIT 20");
                $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $categories = [];
            }
            echo json_encode(['categories' => $categories]);
            break;

        case 'popular':
            if (!$tableExists($pdo, $dbName, 'tblproductos')) {
                echo json_encode(['productos' => []]);
                break;
            }
            // Productos frecuentes ahora prioriza los últimos productos cargados.
            // Usamos idproducto DESC como proxy de alta reciente.
            $limitPopular = 24;
            $useFastCache = ($id_empresa === 118);
            $cacheFile = sys_get_temp_dir() . "/sistemax_popular_{$id_empresa}_s{$id_sucursal}_p{$precioTipo}.json";
            $cacheTtl = $useFastCache ? 180 : 20;

            if ($useFastCache && is_file($cacheFile) && (time() - (int)filemtime($cacheFile) <= $cacheTtl)) {
                $cached = @file_get_contents($cacheFile);
                $cachedData = $cached ? json_decode($cached, true) : null;
                if (is_array($cachedData) && isset($cachedData['productos']) && is_array($cachedData['productos'])) {
                    echo json_encode($cachedData);
                    break;
                }
            }

            $ids = $fetchRecentProductIds($pdo, $dbName, $limitPopular, 1);
            if (empty($ids)) {
                $payload = ['productos' => $fetchSimpleProducts($pdo, $dbName, $limitPopular, '', 1, true, true)];
                if ($useFastCache) @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
                echo json_encode($payload);
                break;
            }

            $idsPlaceholder = implode(',', array_fill(0, count($ids), '?'));
            $orderByField = implode(',', array_map('intval', $ids));

            // Params: id_sucursal, ids(local), id_sucursal(other), ids(other), ids(main)
            $paramsDetails = [$id_sucursal];
            $paramsDetails = array_merge($paramsDetails, $ids);
            $paramsDetails[] = $id_sucursal;
            $paramsDetails = array_merge($paramsDetails, $ids);
            $paramsDetails = array_merge($paramsDetails, $ids);

            $sqlDetails = "SELECT
                    p.idproducto AS id,
                    p.cve_producto AS codigo,
                    p.referencia,
                    p.desproducto AS descripcion,
                    {$highestMercPriceExpr} AS precio,
                    COALESCE(ep.stock, 0) AS stock,
                    CASE WHEN COALESCE(ep_other.stock, 0) > 0 THEN 1 ELSE 0 END AS has_other_stock,
                    p.iva AS tasa_iva,
                    p.controla_stock,
                    p.edita_precio,
                    p.editable,
                    p.precio_compra AS precio_min,
                    p.vende_sin_stock,
                    COALESCE(p.usaserial, 0) AS usaserial
                FROM $dbName.tblproductos p
                {$highestMercPriceJoinSql}
                {$stockLocalJoinSql}
                      AND idproducto IN ($idsPlaceholder)
                {$stockLocalJoinSqlClose}
                {$stockOtherJoinSql}
                      AND idproducto IN ($idsPlaceholder)
                {$stockOtherJoinSqlClose}
                WHERE p.Estado = 1
                  AND p.idproducto IN ($idsPlaceholder)
                ORDER BY FIELD(p.idproducto, $orderByField)";

            $stmtDetails = $pdo->prepare($sqlDetails);
            $stmtDetails->execute($paramsDetails);
            $popular = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);
            $popular = $filterPopularVisibleProducts($popular);

            // Fallback defensivo: si el top calculado quedó vacío (IDs huérfanos, datos legacy, etc.),
            // devolver productos simples para no dejar "Productos Frecuentes" en blanco en POS móvil.
            if (empty($popular)) {
                $rows = $fetchSimpleProducts($pdo, $dbName, $limitPopular, '', 1, true, true);
                $enrichPopularAvailability($rows, 'id');
                $payload = ['productos' => $filterPopularVisibleProducts($rows)];
                if ($useFastCache) {
                    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
                }
                echo json_encode($payload);
                break;
            }

            $attachProductImages($popular, 'id', true);

            $payload = ['productos' => $popular];
            if ($useFastCache) {
                @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
            }
            echo json_encode($payload);
            break;

        case 'popular_simple':
            if ($fastMode) {
                $rows = $fetchUltraFastProducts($pdo, $dbName, 24, '', 1);
                $enrichPopularAvailability($rows, 'id');
                echo json_encode(['productos' => $filterPopularVisibleProducts($rows)]);
            } else {
                $recentIds = $fetchRecentProductIds($pdo, $dbName, 24, 1);
                if (empty($recentIds)) {
                    echo json_encode(['productos' => []]);
                    break;
                }
                $idsPlaceholder = implode(',', array_fill(0, count($recentIds), '?'));
                $orderByField = implode(',', array_map('intval', $recentIds));
                $paramsDetails = [$id_sucursal];
                $paramsDetails = array_merge($paramsDetails, $recentIds);
                $sqlRecent = "SELECT
                        p.idproducto AS id,
                        p.cve_producto AS codigo,
                        p.referencia,
                        p.desproducto AS descripcion,
                        {$highestMercPriceExpr} AS precio,
                        COALESCE(ep.stock, p.saldo) AS stock,
                        p.iva AS tasa_iva,
                        p.controla_stock,
                        p.edita_precio,
                        p.editable,
                        p.precio_compra AS precio_min,
                        p.vende_sin_stock,
                        COALESCE(p.usaserial, 0) AS usaserial
                    FROM $dbName.tblproductos p
                    {$highestMercPriceJoinSql}
                    {$stockLocalJoinSql} AND idproducto IN ($idsPlaceholder)
                    {$stockLocalJoinSqlClose}
                    WHERE p.Estado = 1
                      AND p.idproducto IN ($idsPlaceholder)
                    ORDER BY FIELD(p.idproducto, $orderByField)";
                $paramsDetails = array_merge($paramsDetails, $recentIds);
                $stmtRecent = $pdo->prepare($sqlRecent);
                $stmtRecent->execute($paramsDetails);
                $rows = $stmtRecent->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $enrichPopularAvailability($rows, 'id');
                $rows = $filterPopularVisibleProducts($rows);
                $attachProductImages($rows, 'id', true);
                echo json_encode(['productos' => $rows]);
            }
            break;

        case 'popular_mobile':
            // Endpoint dedicado para móvil: consulta mínima y robusta, sin joins pesados.
            $rows = $fetchSimpleProducts($pdo, $dbName, 24, '', 1, true, true);
            $enrichPopularAvailability($rows, 'id');
            echo json_encode(['productos' => $filterPopularVisibleProducts($rows)]);
            break;

        case 'search_dropdown':
            $q = trim($_GET['q'] ?? '');
            $estado = (int)($_GET['estado'] ?? 1);
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $limit = 50;

            if ($q === '' || !$tableExists($pdo, $dbName, 'tblproductos')) {
                echo json_encode(['productos' => []]);
                break;
            }

            $words = $tokenizeSearchText($q);
            if (empty($words)) {
                echo json_encode(['productos' => [], 'suggestion' => null]);
                break;
            }
            if (count($words) > 6) {
                $words = array_slice($words, 0, 6);
            }

            $joinCategorySql = '';
            $categoryNameExpr = "''";
            if (
                $tableExists($pdo, $dbName, 'mercaderia_grupo') &&
                $columnExists($pdo, $dbName, 'mercaderia_grupo', 'id') &&
                $columnExists($pdo, $dbName, 'mercaderia_grupo', 'grupo')
            ) {
                $joinCategorySql = " LEFT JOIN {$dbName}.mercaderia_grupo mg ON mg.id = p.grupo ";
                $categoryNameExpr = "COALESCE(mg.grupo, '')";
            }

            $descColumnExpr = 'p.desproducto';
            if ($columnExists($pdo, $dbName, 'tblproductos', 'des_producto')) {
                $descColumnExpr = 'p.des_producto';
            } elseif ($columnExists($pdo, $dbName, 'tblproductos', 'desproducto')) {
                $descColumnExpr = 'p.desproducto';
            } elseif ($columnExists($pdo, $dbName, 'tblproductos', 'descripcion')) {
                $descColumnExpr = 'p.descripcion';
            }

            $candidateLimit = $offset > 0 ? 90 : 140;
            $params = [
                ':estado' => $estado,
                ':exact_ref' => $q,
                ':exact_desc' => $q,
                ':exact_ref_prefix' => $q . '%',
                ':exact_desc_prefix' => $q . '%',
                ':exact_barcode' => $q,
                ':exact_barcode_prefix' => $q . '%',
            ];
            $wordConditions = [];
            $matchCountParts = [];

            foreach ($words as $i => $word) {
                $paramAnywhere = ":q_any_{$i}";
                $paramPrefix = ":q_prefix_{$i}";
                $paramWord = ":q_word_{$i}";

                $params[$paramAnywhere] = '%' . $word . '%';
                $params[$paramPrefix] = $word . '%';
                $params[$paramWord] = $word;

                $wordConditions[] = "(
                    {$descColumnExpr} LIKE {$paramAnywhere}
                    OR p.referencia LIKE {$paramAnywhere}
                    OR EXISTS (
                        SELECT 1
                        FROM {$dbName}.codigo_barra cbw
                        WHERE cbw.id_producto = p.idproducto
                          AND cbw.codigo_barra LIKE {$paramAnywhere}
                    )
                )";
                $matchCountParts[] = "(
                    CASE
                        WHEN p.referencia = {$paramWord} THEN 10
                        WHEN EXISTS (
                            SELECT 1
                            FROM {$dbName}.codigo_barra cbm1
                            WHERE cbm1.id_producto = p.idproducto
                              AND cbm1.codigo_barra = {$paramWord}
                        ) THEN 9
                        WHEN p.referencia LIKE {$paramAnywhere} THEN 7
                        WHEN EXISTS (
                            SELECT 1
                            FROM {$dbName}.codigo_barra cbm2
                            WHERE cbm2.id_producto = p.idproducto
                              AND cbm2.codigo_barra LIKE {$paramAnywhere}
                        ) THEN 6
                        WHEN {$descColumnExpr} LIKE {$paramAnywhere} THEN 4
                        ELSE 0
                    END
                )";
            }

            $sql = "SELECT
                        p.idproducto AS id,
                        p.cve_producto AS codigo,
                        p.referencia,
                        {$descColumnExpr} AS descripcion,
                        p.precio_venta AS precio,
                        COALESCE(p.saldo, 0) AS stock,
                        p.iva AS tasa_iva,
                        p.controla_stock,
                        p.edita_precio,
                        p.editable,
                        p.precio_compra AS precio_min,
                        p.vende_sin_stock,
                        COALESCE(p.usaserial, 0) AS usaserial,
                        COALESCE(p.grupo, 0) AS categoria_id,
                        (
                            SELECT cb.codigo_barra
                            FROM {$dbName}.codigo_barra cb
                            WHERE cb.id_producto = p.idproducto
                            ORDER BY cb.codigo_barra ASC
                            LIMIT 1
                        ) AS barcode,
                        {$categoryNameExpr} AS categoria_nombre
                    FROM {$dbName}.tblproductos p
                    {$joinCategorySql}
                    WHERE p.Estado = :estado
                      AND (" . implode(' OR ', $wordConditions) . ")
                    ORDER BY
                        CASE
                            WHEN p.referencia = :exact_ref THEN 0
                            WHEN EXISTS (
                                SELECT 1
                                FROM {$dbName}.codigo_barra cbe1
                                WHERE cbe1.id_producto = p.idproducto
                                  AND cbe1.codigo_barra = :exact_barcode
                            ) THEN 1
                            WHEN {$descColumnExpr} = :exact_desc THEN 2
                            WHEN p.referencia LIKE :exact_ref_prefix THEN 3
                            WHEN EXISTS (
                                SELECT 1
                                FROM {$dbName}.codigo_barra cbe2
                                WHERE cbe2.id_producto = p.idproducto
                                  AND cbe2.codigo_barra LIKE :exact_barcode_prefix
                            ) THEN 4
                            WHEN {$descColumnExpr} LIKE :exact_desc_prefix THEN 5
                            ELSE 6
                        END,
                        (" . implode(' + ', $matchCountParts) . ") DESC,
                        CHAR_LENGTH({$descColumnExpr}) ASC,
                        {$descColumnExpr} ASC
                    LIMIT {$candidateLimit}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $candidateRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($candidateRows) && $offset === 0) {
                $fallbackParams = [':estado' => $estado];
                $fallbackParts = [];
                foreach ($words as $i => $word) {
                    $root = substr($word, 0, min(4, strlen($word)));
                    if ($root === '') {
                        continue;
                    }
                    $paramRoot = ":fallback_root_{$i}";
                    $fallbackParams[$paramRoot] = '%' . $root . '%';
                    $fallbackParts[] = "{$descColumnExpr} LIKE {$paramRoot}";
                    $fallbackParts[] = "p.referencia LIKE {$paramRoot}";
                    $fallbackParts[] = "EXISTS (
                        SELECT 1
                        FROM {$dbName}.codigo_barra cbf
                        WHERE cbf.id_producto = p.idproducto
                          AND cbf.codigo_barra LIKE {$paramRoot}
                    )";
                }

                if (!empty($fallbackParts)) {
                    $fallbackSql = "SELECT
                            p.idproducto AS id,
                            p.cve_producto AS codigo,
                            p.referencia,
                            {$descColumnExpr} AS descripcion,
                            p.precio_venta AS precio,
                            COALESCE(p.saldo, 0) AS stock,
                            p.iva AS tasa_iva,
                            p.controla_stock,
                            p.edita_precio,
                            p.editable,
                            p.precio_compra AS precio_min,
                            p.vende_sin_stock,
                            COALESCE(p.usaserial, 0) AS usaserial,
                            COALESCE(p.grupo, 0) AS categoria_id,
                            (
                                SELECT cb.codigo_barra
                                FROM {$dbName}.codigo_barra cb
                                WHERE cb.id_producto = p.idproducto
                                ORDER BY cb.codigo_barra ASC
                                LIMIT 1
                            ) AS barcode,
                            {$categoryNameExpr} AS categoria_nombre
                        FROM {$dbName}.tblproductos p
                        {$joinCategorySql}
                        WHERE p.Estado = :estado
                          AND (" . implode(' OR ', $fallbackParts) . ")
                        ORDER BY CHAR_LENGTH({$descColumnExpr}) ASC, {$descColumnExpr} ASC
                        LIMIT 60";
                    $fallbackStmt = $pdo->prepare($fallbackSql);
                    $fallbackStmt->execute($fallbackParams);
                    $candidateRows = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            }

            $scored = [];
            foreach ($candidateRows as $row) {
                $row['_score'] = $scoreSearchCandidate($row, $q, $words);
                $scored[(int)$row['id']] = $row;
            }

            $sortFn = static function (array $left, array $right): int {
                $scoreCmp = ($right['_score'] <=> $left['_score']);
                if ($scoreCmp !== 0) return $scoreCmp;
                $lenCmp = strlen((string)($left['descripcion'] ?? '')) <=> strlen((string)($right['descripcion'] ?? ''));
                if ($lenCmp !== 0) return $lenCmp;
                return strcmp((string)($left['descripcion'] ?? ''), (string)($right['descripcion'] ?? ''));
            };
            usort($scored, $sortFn);

            $suggestion = null;
            if ($offset === 0) {
                $suggestion = $buildSpellSuggestion($q, array_slice($scored, 0, 20));
            }

            $productos = array_slice(array_map(static function (array $row): array {
                unset($row['_score']);
                return $row;
            }, $scored), $offset, $limit);

            echo json_encode([
                'productos' => array_values($productos),
                'suggestion' => $suggestion,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'search':
            $q = trim($_GET['q'] ?? '');
            $category = (int)($_GET['category'] ?? 0);
            $priceOp = $_GET['price_op'] ?? '';
            $priceVal = (float)($_GET['price_val'] ?? 0);
            $estado = (int)($_GET['estado'] ?? 1);

            if ($fastMode && $tableExists($pdo, $dbName, 'tblproductos')) {
                echo json_encode([
                    'productos' => $fetchUltraFastProducts($pdo, $dbName, 30, $q, $estado, $category, $priceOp, $priceVal)
                ]);
                break;
            }

            if (
                !$tableExists($pdo, $dbName, 'tblproductos') ||
                !$tableExists($pdo, $dbName, 'codigo_barra') ||
                !$tableExists($pdo, $dbName, 'extracto_productos') ||
                !$tableExists($pdo, $dbName, 'mercaderia_precio') ||
                !$tableExists($pdo, $dbName, 'mercaderia_grupo')
            ) {
                echo json_encode(['productos' => $fetchSimpleProducts($pdo, $dbName, 30, $q, $estado)]);
                break;
            }

            $useSearchCache = ($id_empresa === 118);
            $qNorm = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
            $searchCacheKey = md5(json_encode([
                'q' => $qNorm,
                'category' => $category,
                'price_op' => $priceOp,
                'price_val' => $priceVal,
                'estado' => $estado,
                'precio_tipo' => $precioTipo,
                'sucursal' => $id_sucursal
            ], JSON_UNESCAPED_UNICODE));
            $searchCacheFile = sys_get_temp_dir() . "/sistemax_search_{$id_empresa}_{$searchCacheKey}.json";
            $searchCacheTtl = 45;

            if ($useSearchCache && is_file($searchCacheFile) && (time() - (int)filemtime($searchCacheFile) <= $searchCacheTtl)) {
                $cached = @file_get_contents($searchCacheFile);
                $cachedData = $cached ? json_decode($cached, true) : null;
                if (is_array($cachedData) && isset($cachedData['productos']) && is_array($cachedData['productos'])) {
                    echo json_encode($cachedData);
                    break;
                }
            }

            // PASO 1: Obtener IDs con FULLTEXT (rápido ~5ms) + fallback LIKE
            // FULLTEXT es ~50x más rápido que LIKE '%word%' en 43K+ productos.
            // Soporta multi-palabra independiente del orden: "cola reten" = "reten cola"

            // Auto-crear índice FULLTEXT si no existe (1 vez por sesión/empresa)
            $ftKey = "ft_idx_{$dbName}";
            if (empty($_SESSION[$ftKey])) {
                try {
                    $pdo->exec("ALTER TABLE $dbName.tblproductos ADD FULLTEXT INDEX ft_search (desproducto, cve_producto, referencia)");
                } catch (\PDOException $e) { /* ya existe */ }
                $_SESSION[$ftKey] = true;
            }

            $ids = [];

            // Helper: agregar filtros de categoría y precio a query + params
            $addFilters = function (&$sql, &$params, $named = true) use ($category, $priceOp, $priceVal) {
                if ($category > 0) {
                    if ($named) { $sql .= " AND p.grupo = :cat"; $params[':cat'] = $category; }
                    else { $sql .= " AND p.grupo = ?"; $params[] = $category; }
                }
                if ($priceOp && $priceVal > 0) {
                    $validOps = ['<' => '<', '>' => '>', '=' => '='];
                    if (isset($validOps[$priceOp])) {
                        if ($named) { $sql .= " AND p.precio_venta {$validOps[$priceOp]} :price"; $params[':price'] = $priceVal; }
                        else { $sql .= " AND p.precio_venta {$validOps[$priceOp]} ?"; $params[] = $priceVal; }
                    }
                }
            };

            if (!empty($q)) {
                $words = array_values(array_filter(array_map('trim', preg_split('/\s+/', $q)), fn($w) => $w !== ''));

                // Construir FULLTEXT boolean: "+word1* +word2*"
                $ftParts = [];
                foreach ($words as $w) {
                    $clean = preg_replace('/[+\-><()~*"@]+/', '', $w);
                    if ($clean !== '') $ftParts[] = '+' . $clean . '*';
                }
                $ftQuery = implode(' ', $ftParts);

                // === BÚSQUEDA FULLTEXT (primaria, ~5-10ms) ===
                if (!empty($ftQuery)) {
                    $sqlIds = "SELECT p.idproducto
                               FROM $dbName.tblproductos p
                               WHERE p.Estado = :estado
                               AND MATCH(p.desproducto, p.cve_producto, p.referencia) AGAINST(:ft IN BOOLEAN MODE)";
                    $params = [':estado' => $estado, ':ft' => $ftQuery];
                    $addFilters($sqlIds, $params, true);

                    // Ordenar por: código/ref exacto primero, luego relevancia FULLTEXT
                    // Evita LIKE en ORDER BY que fuerza evaluación en todas las filas
                    $params[':q_exact1'] = $q;
                    $params[':q_exact2'] = $q;
                    $params[':ft2'] = $ftQuery;
                    $sqlIds .= " ORDER BY
                        CASE
                            WHEN p.cve_producto = :q_exact1 THEN 0
                            WHEN p.referencia = :q_exact2 THEN 1
                            ELSE 2
                        END,
                        MATCH(p.desproducto, p.cve_producto, p.referencia) AGAINST(:ft2 IN BOOLEAN MODE) DESC,
                        p.desproducto
                        LIMIT 30";

                    try {
                        $stmtIds = $pdo->prepare($sqlIds);
                        $stmtIds->execute($params);
                        $ids = $stmtIds->fetchAll(PDO::FETCH_COLUMN);
                    } catch (\PDOException $e) {
                        $ids = []; // FULLTEXT no disponible, caerá al fallback LIKE
                    }
                }

                // === BARCODE supplement (prefijo, solo si 1 palabra y pocos resultados) ===
                if (count($ids) < 30 && count($words) === 1) {
                    $excludeIds = empty($ids) ? '0' : implode(',', array_map('intval', $ids));
                    $limitCb = 30 - count($ids);
                    $sqlCb = "SELECT DISTINCT cb.id_producto
                              FROM $dbName.codigo_barra cb
                              INNER JOIN $dbName.tblproductos p ON p.idproducto = cb.id_producto AND p.Estado = ?
                              WHERE cb.codigo_barra LIKE ?
                              AND cb.id_producto NOT IN ($excludeIds)
                              LIMIT $limitCb";
                    $stmtCb = $pdo->prepare($sqlCb);
                    $stmtCb->execute([$estado, $q . '%']);
                    $cbIds = $stmtCb->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($cbIds)) $ids = array_merge($ids, $cbIds);
                }

                // === FALLBACK LIKE solo si FULLTEXT+barcode no encontraron NADA ===
                // Captura sub-cadenas que FULLTEXT no indexa (ej: búsqueda dentro de palabra)
                if (empty($ids)) {
                    $excludeIds = empty($ids) ? '0' : implode(',', array_map('intval', $ids));
                    $limitFb = 30 - count($ids);
                    $sqlFb = "SELECT p.idproducto FROM $dbName.tblproductos p WHERE p.Estado = ?";
                    $fbParams = [$estado];
                    foreach ($words as $w) {
                        $sqlFb .= " AND (p.desproducto LIKE ? OR p.cve_producto LIKE ? OR p.referencia LIKE ?)";
                        $val = '%' . $w . '%';
                        $fbParams[] = $val;
                        $fbParams[] = $val;
                        $fbParams[] = $val;
                    }
                    $sqlFb .= " AND p.idproducto NOT IN ($excludeIds)";
                    $addFilters($sqlFb, $fbParams, false);
                    $sqlFb .= " ORDER BY p.desproducto LIMIT $limitFb";
                    $stmtFb = $pdo->prepare($sqlFb);
                    $stmtFb->execute($fbParams);
                    $fbIds = $stmtFb->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($fbIds)) $ids = array_merge($ids, $fbIds);
                }

            } else {
                // Sin término de búsqueda - listar por nombre
                $sqlIds = "SELECT p.idproducto FROM $dbName.tblproductos p WHERE p.Estado = :estado";
                $params = [':estado' => $estado];
                $addFilters($sqlIds, $params, true);
                $sqlIds .= " ORDER BY p.desproducto LIMIT 30";
                $stmtIds = $pdo->prepare($sqlIds);
                $stmtIds->execute($params);
                $ids = $stmtIds->fetchAll(PDO::FETCH_COLUMN);
            }

            if (empty($ids)) {
                $payload = ['productos' => []];
                if ($useSearchCache) {
                    @file_put_contents($searchCacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
                }
                echo json_encode($payload);
                break;
            }

            // PASO 2: Obtener detalles completos solo para los IDs encontrados
            $idsPlaceholder = implode(',', array_fill(0, count($ids), '?'));

            // Reconstruir params para Paso 2: precio_tipo, ids(mp), id_sucursal, ids (stock local), id_sucursal (stock other), ids (stock other), ids (where)
            $paramsDetails = [$precioTipo];
            $paramsDetails = array_merge($paramsDetails, $ids); // ids para mp subquery
            $paramsDetails[] = $id_sucursal;
            $paramsDetails = array_merge($paramsDetails, $ids); // ids para local stock
            $paramsDetails[] = $id_sucursal; // id_sucursal para NOT equal
            $paramsDetails = array_merge($paramsDetails, $ids); // ids para other stock
            $paramsDetails = array_merge($paramsDetails, $ids); // ids para WHERE principal

            // Adjust SQL select list to include the flag
            $sqlDetails = "SELECT 
                        p.idproducto AS id, 
                        p.cve_producto AS codigo, 
                        p.referencia,
                        (SELECT codigo_barra FROM $dbName.codigo_barra WHERE id_producto = p.idproducto LIMIT 1) AS barcode,
                        p.desproducto AS descripcion, 
                        COALESCE(mp.precio, p.precio_venta) AS precio,
                        COALESCE(ep.stock, p.saldo) AS stock,
                        CASE WHEN COALESCE(ep_other.stock, 0) > 0 THEN 1 ELSE 0 END as has_other_stock,
                        p.iva AS tasa_iva,
                        p.controla_stock,
                        p.edita_precio,
                        p.editable,
                        p.precio_compra AS precio_min,
                        p.vende_sin_stock,
                        COALESCE(p.usaserial, 0) AS usaserial,
                        g.grupo AS categoria
                    FROM $dbName.tblproductos p
                    LEFT JOIN $dbName.mercaderia_grupo g ON g.id = p.grupo
                    LEFT JOIN (
                        SELECT codigo, precio
                        FROM $dbName.mercaderia_precio
                        WHERE tipo = ? AND codigo IN ($idsPlaceholder)
                        GROUP BY codigo
                    ) mp ON mp.codigo = p.idproducto
                    {$stockLocalJoinSql} AND idproducto IN ($idsPlaceholder)
                    {$stockLocalJoinSqlClose}
                    {$stockOtherJoinSql} AND idproducto IN ($idsPlaceholder)
                    {$stockOtherJoinSqlClose}
                    WHERE p.idproducto IN ($idsPlaceholder)";

            $orderByField = implode(',', array_map('intval', $ids));
            $sqlDetails .= " ORDER BY FIELD(p.idproducto, $orderByField)";

            $stmtDetails = $pdo->prepare($sqlDetails);
            $stmtDetails->execute($paramsDetails);
            $productos = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

            // Agregar imágenes a los productos
            $attachProductImages($productos, 'id');

            $payload = ['productos' => $productos];
            if ($useSearchCache) {
                @file_put_contents($searchCacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
            }
            echo json_encode($payload);
            break;

        case 'series':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0 || !$canUseProductSeries) {
                echo json_encode(['series' => []]);
                break;
            }

            $stmt = $pdo->prepare("
                SELECT id, serie, COALESCE(tipo, 'SERIAL') AS tipo, id_sucursal, obs
                FROM $dbName.producto_series
                WHERE idproducto = :idproducto
                  AND estado = 1
                ORDER BY serie ASC, id ASC
            ");
            $stmt->execute([':idproducto' => $id]);
            echo json_encode(['series' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
            break;

        case 'barcode':
            $codigo = trim($_GET['cod'] ?? '');
            if (empty($codigo)) {
                echo json_encode(['producto' => null]);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT p.idproducto AS id, p.cve_producto AS codigo, 
                       cb.codigo_barra AS barcode,
                       p.desproducto AS descripcion, 
                       COALESCE(mp.precio, p.precio_venta) AS precio, 
                       COALESCE(ep.stock, p.saldo) AS stock, 
                       p.iva AS tasa_iva,
                       p.controla_stock,
                       p.edita_precio,
                       p.editable,
                       p.precio_compra AS precio_min,
                       p.vende_sin_stock,
                       COALESCE(p.usaserial, 0) AS usaserial
                FROM $dbName.tblproductos p
                LEFT JOIN (
                    SELECT codigo, precio
                    FROM $dbName.mercaderia_precio
                    WHERE tipo = :precio_tipo
                    GROUP BY codigo
                ) mp ON mp.codigo = p.idproducto
                {$singleSucursalStockJoinSql}
                LEFT JOIN $dbName.codigo_barra cb ON cb.id_producto = p.idproducto
                WHERE (p.cve_producto = :cod OR cb.codigo_barra = :cod2) AND p.Estado = 1
                LIMIT 1
            ");
            $stmt->execute([':cod' => $codigo, ':cod2' => $codigo, ':precio_tipo' => $precioTipo, ':id_sucursal' => $id_sucursal]);
            $producto = $stmt->fetch(PDO::FETCH_ASSOC);

            // Agregar imagen al producto
            if ($producto) {
                $tmp = [$producto];
                $attachProductImages($tmp, 'id');
                $producto = $tmp[0];
                $producto['detalle_cargado'] = 1;
                $producto['precio_pendiente'] = 0;
                $producto['stock_pendiente'] = 0;
            }

            echo json_encode(['producto' => $producto ?: null]);
            break;

        case 'by_id':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['producto' => null]);
                exit;
            }
            $stmt = $pdo->prepare("
                SELECT p.idproducto AS id, p.cve_producto AS codigo, 
                       p.desproducto AS descripcion, 
                       COALESCE(mp.precio, p.precio_venta) AS precio, 
                       COALESCE(ep.stock, p.saldo) AS stock, 
                       p.iva AS tasa_iva,
                       p.controla_stock,
                       p.edita_precio,
                       p.editable,
                       p.precio_compra AS precio_min,
                       p.vende_sin_stock,
                       COALESCE(p.usaserial, 0) AS usaserial
                FROM $dbName.tblproductos p
                LEFT JOIN (
                    SELECT codigo, precio
                    FROM $dbName.mercaderia_precio
                    WHERE tipo = :precio_tipo
                    GROUP BY codigo
                ) mp ON mp.codigo = p.idproducto
                {$singleSucursalStockJoinSql}
                WHERE p.idproducto = :id AND p.Estado = 1
                LIMIT 1
            ");
            $stmt->execute([':id' => $id, ':precio_tipo' => $precioTipo, ':id_sucursal' => $id_sucursal]);
            $producto = $stmt->fetch(PDO::FETCH_ASSOC);

            // Agregar imagen al producto
            if ($producto) {
                $tmp = [$producto];
                $attachProductImages($tmp, 'id');
                $producto = $tmp[0];
                $producto['detalle_cargado'] = 1;
                $producto['precio_pendiente'] = 0;
                $producto['stock_pendiente'] = 0;
            }

            echo json_encode(['producto' => $producto ?: null]);
            break;

        case 'get_prices':
            // Obtener precios para múltiples productos según tipo de precio
            $idsStr = $_GET['ids'] ?? '';
            if (empty($idsStr)) {
                echo json_encode(['precios' => []]);
                exit;
            }

            $ids = array_filter(array_map('intval', explode(',', $idsStr)));
            if (empty($ids)) {
                echo json_encode(['precios' => []]);
                exit;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $stmt = $pdo->prepare("
                SELECT p.idproducto AS id, COALESCE(mp.precio, p.precio_venta) AS precio
                FROM $dbName.tblproductos p
                LEFT JOIN (
                    SELECT codigo, precio
                    FROM $dbName.mercaderia_precio
                    WHERE tipo = ? AND codigo IN ($placeholders)
                    GROUP BY codigo
                ) mp ON mp.codigo = p.idproducto
                WHERE p.idproducto IN ($placeholders)
            ");
            $params = array_merge([$precioTipo], $ids, $ids);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $precios = [];
            foreach ($rows as $row) {
                $precios[$row['id']] = (float)$row['precio'];
            }

            echo json_encode(['precios' => $precios]);
            break;

        case 'hydrate':
            $idsStr = $_GET['ids'] ?? '';
            $ids = array_values(array_filter(array_map('intval', explode(',', $idsStr))));
            $ids = array_values(array_unique(array_filter($ids, static fn($id) => $id > 0)));

            if (empty($ids)) {
                echo json_encode(['productos' => []]);
                break;
            }

            if (
                !$tableExists($pdo, $dbName, 'tblproductos') ||
                !$tableExists($pdo, $dbName, 'codigo_barra') ||
                !$tableExists($pdo, $dbName, 'extracto_productos') ||
                !$tableExists($pdo, $dbName, 'mercaderia_precio') ||
                !$tableExists($pdo, $dbName, 'mercaderia_grupo')
            ) {
                $productos = $fetchSimpleProducts($pdo, $dbName, max(1, count($ids)), '', 1);
                $map = [];
                foreach ($productos as $item) {
                    $map[(int)($item['id'] ?? 0)] = $item;
                }
                $ordered = [];
                foreach ($ids as $id) {
                    if (isset($map[$id])) {
                        $row = $map[$id];
                        $row['detalle_cargado'] = 1;
                        $row['precio_pendiente'] = 0;
                        $row['stock_pendiente'] = 0;
                        $ordered[] = $row;
                    }
                }
                echo json_encode(['productos' => $ordered]);
                break;
            }

            $idsPlaceholder = implode(',', array_fill(0, count($ids), '?'));
            $paramsDetails = [$precioTipo];
            $paramsDetails = array_merge($paramsDetails, $ids);
            $paramsDetails[] = $id_sucursal;
            $paramsDetails = array_merge($paramsDetails, $ids);
            $paramsDetails[] = $id_sucursal;
            $paramsDetails = array_merge($paramsDetails, $ids);
            $paramsDetails = array_merge($paramsDetails, $ids);

            $sqlDetails = "SELECT 
                        p.idproducto AS id, 
                        p.cve_producto AS codigo, 
                        p.referencia,
                        (SELECT codigo_barra FROM $dbName.codigo_barra WHERE id_producto = p.idproducto LIMIT 1) AS barcode,
                        p.desproducto AS descripcion, 
                        COALESCE(mp.precio, p.precio_venta) AS precio,
                        COALESCE(ep.stock, 0) AS stock,
                        CASE WHEN COALESCE(ep_other.stock, 0) > 0 THEN 1 ELSE 0 END as has_other_stock,
                        p.iva AS tasa_iva,
                        p.controla_stock,
                        p.edita_precio,
                        p.editable,
                        p.precio_compra AS precio_min,
                        p.vende_sin_stock,
                        COALESCE(p.usaserial, 0) AS usaserial,
                        g.grupo AS categoria,
                        1 AS detalle_cargado,
                        0 AS precio_pendiente,
                        0 AS stock_pendiente
                    FROM $dbName.tblproductos p
                    LEFT JOIN $dbName.mercaderia_grupo g ON g.id = p.grupo
                    LEFT JOIN (
                        SELECT codigo, precio
                        FROM $dbName.mercaderia_precio
                        WHERE tipo = ? AND codigo IN ($idsPlaceholder)
                        GROUP BY codigo
                    ) mp ON mp.codigo = p.idproducto
                    {$stockLocalJoinSql} AND idproducto IN ($idsPlaceholder)
                    {$stockLocalJoinSqlClose}
                    {$stockOtherJoinSql} AND idproducto IN ($idsPlaceholder)
                    {$stockOtherJoinSqlClose}
                    WHERE p.idproducto IN ($idsPlaceholder)";

            $orderByField = implode(',', array_map('intval', $ids));
            $sqlDetails .= " ORDER BY FIELD(p.idproducto, $orderByField)";

            $stmtDetails = $pdo->prepare($sqlDetails);
            $stmtDetails->execute($paramsDetails);
            $productos = $stmtDetails->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $attachProductImages($productos, 'id');

            echo json_encode(['productos' => $productos]);
            break;

        default:
            echo json_encode(['error' => 'Acción no válida']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
