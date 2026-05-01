<?php

/**
 * POS API - Detalle de Producto
 * Obtiene información completa de un producto:
 * - Foto
 * - Todos los precios habilitados
 * - Stock en todas las sucursales
 * - Productos equivalentes (misma referencia)
 * - Clientes que compraron el producto
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';

$idProducto = (int)($_GET['id'] ?? 0);
$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$detailScope = strtolower(trim((string)($_GET['scope'] ?? 'full')));
$isLiteScope = $detailScope === 'lite';

if (!$idProducto) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de producto requerido']);
    exit;
}

try {
    // Obtener conexión maestra
    $masterConn = getMasterConnection();

    // 1. Priorizar id_empresa desde sec_users (Fuente de Verdad)
    $id_login = (int)($_SESSION['id_login'] ?? $_SESSION['id_usuario'] ?? 0);
    if ($id_login > 0) {
        $stmt_user = $masterConn->prepare("SELECT id_empresa FROM sec_users WHERE id_login = :id");
        $stmt_user->execute([':id' => $id_login]);
        $user_empresa = (int)$stmt_user->fetchColumn();
        if ($user_empresa > 0) {
            $id_empresa = $user_empresa;
        }
    }

    // 2. Obtener DB de la empresa usando configuración centralizada
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];
    $baseFs = dirname(__DIR__, 2);
    $productosDir = $baseFs . "/_lib/file/img/productos/{$dbName}/";
    $productosPath = "/public/_lib/file/img/productos/{$dbName}/";
    $cacheDir = $baseFs . "/_lib/file/img/productos_cache/{$dbName}/";
    $cachePath = "/public/_lib/file/img/productos_cache/{$dbName}/";

    if (!isset($_SESSION['pos_schema_cache']) || !is_array($_SESSION['pos_schema_cache'])) {
        $_SESSION['pos_schema_cache'] = [];
    }
    if (!isset($_SESSION['pos_schema_cache'][$dbName]) || !is_array($_SESSION['pos_schema_cache'][$dbName])) {
        $_SESSION['pos_schema_cache'][$dbName] = ['tables' => [], 'columns' => []];
    }

    // 1. INFORMACIÓN BÁSICA DEL PRODUCTO
    $stmt = $pdo->prepare("
        SELECT 
            p.idproducto,
            p.cve_producto AS codigo,
            p.desproducto AS descripcion,
            p.Estado,
            COALESCE(p.descontinuado, 0) AS descontinuado,
            p.referencia,
            p.precio_compra AS costo,
            p.precio_venta,
            p.saldo AS stock_global,
            p.controla_stock,
            COALESCE(p.vende_sin_stock, 0) AS vende_sin_stock,
            p.edita_precio,
            p.editable,
            COALESCE(p.usaserial, 0) AS usaserial,
            p.iva AS tasa_iva,
            p.stock_minimo,
            p.stock_maximo,
            g.grupo AS categoria,
            m.marca AS marca_nombre,
            mo.modelo AS modelo_nombre
        FROM $dbName.tblproductos p
        LEFT JOIN $dbName.mercaderia_grupo g ON g.id = p.grupo
        LEFT JOIN $dbName.mercaderia_marca m ON m.id = p.marca
        LEFT JOIN $dbName.mercaderia_modelo mo ON mo.id = p.modelo
        WHERE p.idproducto = :id and p.estado = 1
    ");
    $stmt->execute([':id' => $idProducto]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$producto) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado']);
        exit;
    }

    $tableExists = static function (PDO $pdo, string $schema, string $table): bool {
        if (isset($_SESSION['pos_schema_cache'][$schema]['tables'][$table])) {
            return (bool)$_SESSION['pos_schema_cache'][$schema]['tables'][$table];
        }
        try {
            $stmt = $pdo->query("SHOW TABLES FROM `$schema` LIKE " . $pdo->quote($table));
            $exists = (bool)($stmt && $stmt->fetch(PDO::FETCH_NUM));
            $_SESSION['pos_schema_cache'][$schema]['tables'][$table] = $exists ? 1 : 0;
            return $exists;
        } catch (Throwable $e) {
            return false;
        }
    };
    $columnExists = static function (PDO $pdo, string $schema, string $table, string $column): bool {
        if (isset($_SESSION['pos_schema_cache'][$schema]['columns'][$table][$column])) {
            return (bool)$_SESSION['pos_schema_cache'][$schema]['columns'][$table][$column];
        }
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$schema`.`$table` LIKE " . $pdo->quote($column));
            $exists = (bool)($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
            if (!isset($_SESSION['pos_schema_cache'][$schema]['columns'][$table])) {
                $_SESSION['pos_schema_cache'][$schema]['columns'][$table] = [];
            }
            $_SESSION['pos_schema_cache'][$schema]['columns'][$table][$column] = $exists ? 1 : 0;
            return $exists;
        } catch (Throwable $e) {
            return false;
        }
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

    // 2. FOTO DEL PRODUCTO (legacy opcional)
    $stmt = $pdo->prepare("SELECT foto FROM $dbName.mercaderia_foto WHERE codigo = :codigo LIMIT 1");
    $stmt->execute([':codigo' => $producto['codigo']]);
    $fotoRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $producto['foto'] = $fotoRow['foto'] ?? null;
    $producto['imagen_url'] = null;
    $producto['imagen_updated'] = null;
    $producto['imagen_source'] = null;
    $producto['imagenes'] = [];

    $appendImage = static function (array &$images, string $fullUrl = '', string $thumbUrl = '', int $principal = 0, ?int $updatedAt = null, string $fileId = ''): void {
        $fullUrl = trim($fullUrl);
        $thumbUrl = trim($thumbUrl);
        if ($fullUrl === '' && $thumbUrl === '') {
            return;
        }
        if ($fullUrl === '') {
            $fullUrl = $thumbUrl;
        }
        if ($thumbUrl === '') {
            $thumbUrl = $fullUrl;
        }
        foreach ($images as $existing) {
            if (($existing['url'] ?? '') === $fullUrl) {
                return;
            }
        }
        $images[] = [
            'url' => $fullUrl,
            'thumb_url' => $thumbUrl,
            'principal' => $principal ? 1 : 0,
            'updated_at' => $updatedAt,
            'file_id' => $fileId,
        ];
    };

    // Prioridad de imagen:
    // 1) Google Drive (tabla producto_imagenes)
    // 2) URL en tblproductos.foto_url
    // 3) Campo legacy mercaderia_foto /uploads/productos
    // 4) Fallback legado local/cache
    // 5) Fallback automático por imagen_proxy
    if ($tableExists($pdo, $dbName, 'producto_imagenes')) {
        try {
            $stmt = $pdo->prepare("
                SELECT *
                FROM {$dbName}.producto_imagenes
                WHERE idproducto = :id
                ORDER BY principal DESC, orden ASC, id ASC
            ");
            $stmt->execute([':id' => $idProducto]);
            $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($images as $index => $img) {
                $fullUrl = (string)($img['large_url'] ?? $img['medium_url'] ?? $img['url'] ?? '');
                $thumbUrl = (string)($img['thumb_url'] ?? $img['small_url'] ?? $img['url'] ?? '');
                $updatedTs = isset($img['updated_at']) && $img['updated_at'] ? strtotime((string)$img['updated_at']) : null;
                if (!$updatedTs) {
                    $updatedTs = isset($img['created_at']) && $img['created_at'] ? strtotime((string)$img['created_at']) : time();
                }
                $appendImage($producto['imagenes'], $fullUrl, $thumbUrl, (int)($img['principal'] ?? ($index === 0 ? 1 : 0)), $updatedTs ?: null, (string)($img['drive_file_id'] ?? ''));
            }
            $img = $images[0] ?? null;
            if ($img && !empty($img['url'])) {
                $producto['imagen_url'] = (string)$img['url'];
                $producto['imagen_updated'] = isset($img['created_at']) ? (strtotime((string)$img['created_at']) ?: time()) : time();
                $producto['imagen_source'] = 'drive';
            }
        } catch (Throwable $e) {
            // Silencioso.
        }
    }

    if (
        !$producto['imagen_url'] &&
        $tableExists($pdo, $dbName, 'tblproductos') &&
        $columnExists($pdo, $dbName, 'tblproductos', 'foto_url')
    ) {
        try {
            $stmt = $pdo->prepare("SELECT foto_url FROM {$dbName}.tblproductos WHERE idproducto = :id LIMIT 1");
            $stmt->execute([':id' => $idProducto]);
            $fotoUrl = trim((string)$stmt->fetchColumn());
            if ($fotoUrl !== '') {
                $producto['imagen_url'] = $fotoUrl;
                $producto['imagen_updated'] = time();
                $producto['imagen_source'] = 'foto_url';
                $appendImage($producto['imagenes'], $fotoUrl, $fotoUrl, 1, $producto['imagen_updated']);
            }
        } catch (Throwable $e) {
            // Silencioso.
        }
    }

    if (!$producto['imagen_url'] && !empty($producto['foto'])) {
        $foto = trim((string)$producto['foto']);
        if (preg_match('/^https?:\/\//i', $foto)) {
            $producto['imagen_url'] = $foto;
            $producto['imagen_source'] = 'legacy_url';
            $appendImage($producto['imagenes'], $foto, $foto, 1, $producto['imagen_updated']);
        } else {
            $legacyFs = $baseFs . '/uploads/productos/' . $foto;
            if (is_file($legacyFs)) {
                $producto['imagen_url'] = '/public/uploads/productos/' . rawurlencode($foto);
                $producto['imagen_updated'] = @filemtime($legacyFs) ?: null;
                $producto['imagen_source'] = 'legacy_file';
                $appendImage($producto['imagenes'], $producto['imagen_url'], $producto['imagen_url'], 1, $producto['imagen_updated']);
            }
        }
    }

    if (!$producto['imagen_url'] && is_dir($productosDir)) {
        $files = glob($productosDir . $idProducto . "_*");
        if (!empty($files) && is_file($files[0])) {
            $producto['imagen_url'] = $productosPath . basename($files[0]);
            $producto['imagen_updated'] = @filemtime($files[0]) ?: null;
            $producto['imagen_source'] = 'local';
        }
        foreach ($files ?: [] as $file) {
            if (is_file($file)) {
                $url = $productosPath . basename($file);
                $appendImage($producto['imagenes'], $url, $url, 0, @filemtime($file) ?: null);
            }
        }
    }

    if (!$producto['imagen_url']) {
        $cacheFile = $cacheDir . $idProducto . '.webp';
        if (is_file($cacheFile)) {
            $producto['imagen_url'] = $cachePath . $idProducto . '.webp';
            $producto['imagen_updated'] = @filemtime($cacheFile) ?: null;
            $producto['imagen_source'] = 'cache';
            $appendImage($producto['imagenes'], $producto['imagen_url'], $producto['imagen_url'], 1, $producto['imagen_updated']);
        }
    }

    if (!$producto['imagen_url']) {
        $q = rawurlencode((string)($producto['descripcion'] ?? 'producto'));
        $producto['imagen_url'] = "/public/pos/api/imagen_proxy.php?id={$idProducto}&id_empresa={$id_empresa}&prefer=bing_catalog&q={$q}";
        $producto['imagen_source'] = 'bing_catalog_fallback';
        $appendImage($producto['imagenes'], $producto['imagen_url'], $producto['imagen_url'], 1, $producto['imagen_updated']);
    }
    if ($producto['imagen_url']) {
        $appendImage($producto['imagenes'], $producto['imagen_url'], $producto['imagen_url'], 1, $producto['imagen_updated']);
    }
    // Compatibilidad con render de productos frecuentes (mismo nombre de campo).
    $producto['imagen'] = $producto['imagen_url'];
    $producto['detalle_cargado'] = 1;

    $precios = [];
    $stockPorSucursal = [];
    $equivalentes = [];
    $ventasRegistradas = 0;
    $clientesCompraron = [];
    $codigosBarra = [];

    if ($isLiteScope) {
        $precios[] = [
            'tipo' => 0,
            'nombre_tipo' => 'Precio Base',
            'precio' => $producto['precio_venta'],
            'porcentaje' => null,
            'moneda' => 1
        ];
    } else {
        // 3. TODOS LOS PRECIOS HABILITADOS
        $stmt = $pdo->prepare("
            SELECT 
                mp.tipo,
                tp.tipo AS nombre_tipo,
                mp.precio,
                mp.porcentaje,
                tp.moneda
            FROM $dbName.mercaderia_precio mp
            LEFT JOIN $dbName.tipo_precio tp ON tp.id = mp.tipo
            WHERE mp.codigo = :id AND mp.precio > 0
            ORDER BY mp.tipo
        ");
        $stmt->execute([':id' => $idProducto]);
        $precios = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($precios)) {
            $precios[] = [
                'tipo' => 0,
                'nombre_tipo' => 'Precio Base',
                'precio' => $producto['precio_venta'],
                'porcentaje' => null,
                'moneda' => 1
            ];
        }

        // 4. STOCK POR SUCURSAL
        $stockBySucursalSql = $canUseProductoStock
            ? "
            SELECT 
                s.id_sucursal,
                s.sucursal AS nombre_sucursal,
                s.ciudad,
                COALESCE(ep.stock, 0) AS stock
            FROM $dbName.sucursales s
            LEFT JOIN (
                SELECT id_sucursal, MAX(COALESCE({$productoStockValueColumn}, 0)) AS stock 
                FROM $dbName.producto_stock 
                WHERE idproducto = :id
                GROUP BY id_sucursal
            ) ep ON ep.id_sucursal = s.id_sucursal
            ORDER BY s.sucursal
        "
            : "
            SELECT 
                s.id_sucursal,
                s.sucursal AS nombre_sucursal,
                s.ciudad,
                COALESCE(ep.stock, 0) AS stock
            FROM $dbName.sucursales s
            LEFT JOIN (
                SELECT id_sucursal, SUM(entrada - salida) AS stock 
                FROM $dbName.extracto_productos 
                WHERE idproducto = :id AND estado = 1
                GROUP BY id_sucursal
            ) ep ON ep.id_sucursal = s.id_sucursal
            ORDER BY s.sucursal
        ";
        $stmt = $pdo->prepare($stockBySucursalSql);
        $stmt->execute([':id' => $idProducto]);
        $stockPorSucursal = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 5. PRODUCTOS EQUIVALENTES (misma referencia)
        if (!empty($producto['referencia'])) {
            $stmt = $pdo->prepare("
                SELECT 
                    p.idproducto AS id,
                    p.cve_producto AS codigo,
                    p.desproducto AS descripcion,
                    p.precio_venta,
                    p.saldo AS stock
                FROM $dbName.tblproductos p
                WHERE p.referencia = :ref 
                  AND p.idproducto != :id 
                  AND p.Estado = 1
                ORDER BY p.desproducto
                LIMIT 20
            ");
            $stmt->execute([':ref' => $producto['referencia'], ':id' => $idProducto]);
            $equivalentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $ventasProductoIds = [$idProducto];
        foreach ($equivalentes as $eq) {
            $eqId = (int)($eq['id'] ?? 0);
            if ($eqId > 0 && !in_array($eqId, $ventasProductoIds, true)) {
                $ventasProductoIds[] = $eqId;
            }
        }
        $ventasPlaceholders = implode(',', array_fill(0, count($ventasProductoIds), '?'));

        // 6. VENTAS REALES DESDE EXTRACTO_PRODUCTOS
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM $dbName.extracto_productos ep
            WHERE ep.idproducto IN ($ventasPlaceholders)
              AND ep.salida > 0
              AND ep.estado = 1
        ");
        $stmt->execute($ventasProductoIds);
        $ventasRegistradas = (int)$stmt->fetchColumn();

        // 7. HISTORICO DE VENTAS AGRUPADO POR CLIENTE / REFERENCIA
        $stmt = $pdo->prepare("
            SELECT 
                c.id AS id_cliente,
                c.nombre AS cliente_nombre,
                c.numero AS cliente_ruc,
                MAX(ep.fecha) AS fecha,
                SUM(COALESCE(ep.salida, 0)) AS cantidad,
                ROUND(AVG(COALESCE(ep.precio_gs, ep.precio)), 2) AS precio,
                SUM(COALESCE(ep.importe_gs, ep.importe)) AS importe,
                COUNT(*) AS ventas_count,
                GROUP_CONCAT(DISTINCT p.desproducto ORDER BY p.desproducto SEPARATOR ' | ') AS producto_descripcion
            FROM $dbName.extracto_productos ep
            INNER JOIN $dbName.clientes c ON c.id = ep.id_cliente
            INNER JOIN $dbName.tblproductos p ON p.idproducto = ep.idproducto
            WHERE ep.idproducto IN ($ventasPlaceholders)
              AND ep.salida > 0 
              AND ep.estado = 1
              AND ep.id_cliente IS NOT NULL
              AND ep.id_cliente > 0
            GROUP BY c.id, c.nombre, c.numero
            ORDER BY importe DESC, cantidad DESC, fecha DESC
            LIMIT 30
        ");
        $stmt->execute($ventasProductoIds);
        $clientesCompraron = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        // 8. CÓDIGOS DE BARRA
        $stmt = $pdo->prepare("SELECT codigo_barra FROM $dbName.codigo_barra WHERE id_producto = :id");
        $stmt->execute([':id' => $idProducto]);
        $codigosBarra = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Respuesta
    echo json_encode([
        'success' => true,
        'scope' => $isLiteScope ? 'lite' : 'full',
        'heavy_loaded' => !$isLiteScope,
        'producto' => $producto,
        'codigos_barra' => $codigosBarra,
        'precios' => $precios,
        'stock_por_sucursal' => $stockPorSucursal,
        'equivalentes' => $equivalentes,
        'clientes_compraron' => $clientesCompraron,
        'ventas_registradas' => $ventasRegistradas
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
