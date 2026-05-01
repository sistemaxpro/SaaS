<?php
/**
 * Productos API - Detalle completo de un producto
 * GET: ?id=123
 * Incluye: datos principales, precios, stock por sucursal, códigos de barra, series, colores, medidas, referencias
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../../../lib/producto_stock_snapshot.php';
require_once __DIR__ . '/../../../src/Services/ImageVariantService.php';

function detalleTableExists(PDO $pdo, string $db, string $table): bool
{
    static $cache = [];
    $key = $db . '.' . $table;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = :db AND table_name = :tbl LIMIT 1");
    $stmt->execute([':db' => $db, ':tbl' => $table]);
    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

function detalleColumnExists(PDO $pdo, string $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $db . '.' . $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = :db AND table_name = :tbl AND column_name = :col LIMIT 1");
    $stmt->execute([':db' => $db, ':tbl' => $table, ':col' => $column]);
    $cache[$key] = (bool)$stmt->fetchColumn();
    return $cache[$key];
}

function detalleEnsureProductoAplicaciones(PDO $pdo, string $db): void
{
    static $done = [];
    $table = detalleResolveProductoAplicacionesTable($pdo, $db);
    $doneKey = $db . '.' . $table;
    if (isset($done[$doneKey])) return;
    $done[$doneKey] = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.`{$table}` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `idproducto` INT NOT NULL,
            `marca_cod_conversion` VARCHAR(120) NULL DEFAULT NULL,
            `conversion` VARCHAR(120) NULL DEFAULT NULL,
            `marca_aplicacion` VARCHAR(120) NULL DEFAULT NULL,
            `vehiculo_marca` VARCHAR(120) NULL DEFAULT NULL,
            `vehiculo_modelo` VARCHAR(120) NULL DEFAULT NULL,
            `anio` VARCHAR(40) NULL DEFAULT NULL,
            `motor` VARCHAR(120) NULL DEFAULT NULL,
            `codigo_motor` VARCHAR(120) NULL DEFAULT NULL,
            `orden` INT NOT NULL DEFAULT 1,
            `id_login` INT NULL DEFAULT NULL,
            `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_producto_orden` (`idproducto`, `orden`),
            KEY `idx_producto_marca_modelo` (`idproducto`, `vehiculo_marca`, `vehiculo_modelo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $columns = [
            'marca_cod_conversion' => 'VARCHAR(120) NULL DEFAULT NULL',
            'conversion' => 'VARCHAR(120) NULL DEFAULT NULL',
            'marca_aplicacion' => 'VARCHAR(120) NULL DEFAULT NULL',
            'vehiculo_marca' => 'VARCHAR(120) NULL DEFAULT NULL',
            'vehiculo_modelo' => 'VARCHAR(120) NULL DEFAULT NULL',
            'anio' => 'VARCHAR(40) NULL DEFAULT NULL',
            'motor' => 'VARCHAR(120) NULL DEFAULT NULL',
            'codigo_motor' => 'VARCHAR(120) NULL DEFAULT NULL',
            'orden' => 'INT NOT NULL DEFAULT 1',
            'id_login' => 'INT NULL DEFAULT NULL',
        ];
        foreach ($columns as $column => $definition) {
            $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = :db AND table_name = :tbl AND column_name = :col LIMIT 1");
            $stmt->execute([':db' => $db, ':tbl' => $table, ':col' => $column]);
            if ($stmt->fetchColumn()) continue;
            $pdo->exec("ALTER TABLE `{$db}`.`{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    } catch (Throwable $e) {
        error_log('[productos/detalle] producto_aplicaciones omitido: ' . $e->getMessage());
    }
}

function detalleResolveProductoAplicacionesTable(PDO $pdo, string $db): string
{
    if (detalleTableExists($pdo, $db, 'productos_aplicaciones')) {
        return 'productos_aplicaciones';
    }
    if (detalleTableExists($pdo, $db, 'producto_aplicaciones')) {
        return 'producto_aplicaciones';
    }
    return 'producto_aplicaciones';
}

function detalleResolveProductoEquivalenciasTable(PDO $pdo, string $db): string
{
    if (detalleTableExists($pdo, $db, 'productos_equivalencias')) {
        return 'productos_equivalencias';
    }
    if (detalleTableExists($pdo, $db, 'producto_equivalencias')) {
        return 'producto_equivalencias';
    }
    return 'producto_equivalencias';
}

function detalleEnsureProductoEquivalencias(PDO $pdo, string $db): void
{
    static $done = [];
    $table = detalleResolveProductoEquivalenciasTable($pdo, $db);
    $doneKey = $db . '.' . $table;
    if (isset($done[$doneKey])) return;
    $done[$doneKey] = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.`{$table}` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `idproducto` INT NOT NULL,
            `marca_cod_conversion` VARCHAR(120) NULL DEFAULT NULL,
            `conversion` VARCHAR(120) NULL DEFAULT NULL,
            `orden` INT NOT NULL DEFAULT 1,
            `id_login` INT NULL DEFAULT NULL,
            `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_producto_orden` (`idproducto`, `orden`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $columns = [
            'marca_cod_conversion' => 'VARCHAR(120) NULL DEFAULT NULL',
            'conversion' => 'VARCHAR(120) NULL DEFAULT NULL',
            'orden' => 'INT NOT NULL DEFAULT 1',
            'id_login' => 'INT NULL DEFAULT NULL',
        ];
        foreach ($columns as $column => $definition) {
            if (detalleColumnExists($pdo, $db, $table, $column)) continue;
            $pdo->exec("ALTER TABLE `{$db}`.`{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    } catch (Throwable $e) {
        error_log('[productos/detalle] producto_equivalencias omitido: ' . $e->getMessage());
    }
}

Permission::requireAccess('app_grid_mercaderias');

$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$action = $_GET['action'] ?? 'detalle';

// Acción: tipos de precio (no requiere idproducto)
if ($action === 'tipos_precio') {
    try {
        $conn = getEmpresaConnection($id_empresa);
        $pdo = $conn['pdo'];
        $db = $conn['dbName'];
        $stmt = $pdo->query("SELECT id, tipo, porcentaje, moneda, descuento FROM {$db}.tipo_precio ORDER BY id");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$idproducto = (int)($_GET['id'] ?? 0);

if ($idproducto <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de producto requerido']);
    exit;
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    detalleEnsureProductoAplicaciones($pdo, $db);
    detalleEnsureProductoEquivalencias($pdo, $db);
    $tablaAplicaciones = detalleResolveProductoAplicacionesTable($pdo, $db);
    $tablaEquivalencias = detalleResolveProductoEquivalenciasTable($pdo, $db);
    $productoStockTable = sxProductoStockSnapshotTable($pdo, $db);
    $hasProductoStockTable = sxProductoStockSnapshotReady($pdo, $db);
    $productoStockValueExpr = ($productoStockTable !== null && detalleColumnExists($pdo, $db, $productoStockTable, 'stock_disponible'))
        ? 'stock_disponible'
        : 'stock_actual';

    // 1. Datos del producto
    $stmt = $pdo->prepare("SELECT p.*, 
                g.grupo AS grupo_nombre, m.marca AS marca_nombre, mo.modelo AS modelo_nombre
            FROM {$db}.tblproductos p
            LEFT JOIN {$db}.mercaderia_grupo g ON g.id = p.grupo
            LEFT JOIN {$db}.mercaderia_marca m ON m.id = p.marca
            LEFT JOIN {$db}.mercaderia_modelo mo ON mo.id = p.modelo
            WHERE p.idproducto = :id");
    $stmt->execute([':id' => $idproducto]);
    $producto = $stmt->fetch();

    if (!$producto) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Producto no encontrado']);
        exit;
    }

    // Normalizar campos que pueden no existir en este tenant
    $defaults = [
        'codigo_barra' => '', 'foto_url' => null, 'descripcion_larga' => '',
        'ncm' => '', 'origen' => 1, 'peso' => 0, 'ancho' => 0, 'alto' => 0,
        'largo' => 0, 'web' => 0, 'publicar_web' => 0, 'unidad_medida' => '',
        'moneda' => 1, 'tipo_producto_sifen' => 1, 'descontinuado' => 0,
        'color' => 0, 'equivalencia' => '', 'garantia_meses' => 0,
        'proveedor_principal' => '', 'ubicacion_fisica' => '', 'condicion_producto' => '',
        'ubicacion' => '', 'gondola' => '', 'fila' => '', 'celda' => '',
        'capacidad' => '', 'ram' => '', 'compatibilidad' => '', 'incluye' => ''
    ];
    foreach ($defaults as $col => $defVal) {
        if (!array_key_exists($col, $producto)) $producto[$col] = $defVal;
    }

    // 2. Precios múltiples
    // Compatibilidad: algunos tenants guardan mercaderia_precio.codigo con cve_producto
    // y otros con idproducto.
    $lookupCodigos = [];
    $lookupCodigos[] = (int)$producto['idproducto'];
    $cveRaw = trim((string)($producto['cve_producto'] ?? ''));
    if ($cveRaw !== '' && ctype_digit($cveRaw)) {
        $lookupCodigos[] = (int)$cveRaw;
    }
    $lookupCodigos = array_values(array_unique(array_filter($lookupCodigos)));

    $precios = [];
    if (!empty($lookupCodigos)) {
        $inPrecios = implode(',', array_fill(0, count($lookupCodigos), '?'));
        $stmt = $pdo->prepare("SELECT mp.id, mp.codigo, mp.tipo, mp.costo, mp.porcentaje, mp.precio, mp.moneda, mp.descuento,
                    tp.tipo AS tipo_nombre, tp.porcentaje AS tipo_porcentaje_default, tp.moneda AS tipo_moneda
                FROM {$db}.mercaderia_precio mp
                LEFT JOIN {$db}.tipo_precio tp ON tp.id = mp.tipo
                WHERE mp.codigo IN ({$inPrecios})
                ORDER BY mp.tipo, mp.codigo DESC");
        $stmt->execute($lookupCodigos);
        $preciosRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $preciosByTipo = [];
        foreach ($preciosRaw as $pr) {
            $tipoKey = (string)($pr['tipo'] ?? '');
            if ($tipoKey === '' || isset($preciosByTipo[$tipoKey])) {
                continue;
            }
            $preciosByTipo[$tipoKey] = [
                'id' => (int)($pr['id'] ?? 0),
                'codigo' => (string)($pr['codigo'] ?? ''),
                'tipo' => (int)($pr['tipo'] ?? 0),
                'costo' => (float)($pr['costo'] ?? 0),
                'porcentaje' => (float)($pr['porcentaje'] ?? $pr['tipo_porcentaje_default'] ?? 0),
                'precio' => (float)($pr['precio'] ?? 0),
                'moneda' => (string)($pr['moneda'] ?? $pr['tipo_moneda'] ?? 'PYG'),
                'descuento' => (float)($pr['descuento'] ?? 0),
                'tipo_nombre' => (string)($pr['tipo_nombre'] ?? ('Precio ' . $tipoKey)),
                'tipo_porcentaje_default' => (float)($pr['tipo_porcentaje_default'] ?? 0),
                'tipo_moneda' => (string)($pr['tipo_moneda'] ?? 'PYG'),
            ];
        }
        $precios = array_values($preciosByTipo);
    }

    // 3. Stock por sucursal
    $stockSql = $hasProductoStockTable
        ? "SELECT ps.id_sucursal,
                  s.sucursal AS nombre_sucursal,
                  0 AS total_entrada,
                  0 AS total_salida,
                  COALESCE(ps.{$productoStockValueExpr}, 0) AS stock_actual
           FROM {$db}.{$productoStockTable} ps
           LEFT JOIN {$db}.sucursales s ON s.id_sucursal = ps.id_sucursal
           WHERE ps.idproducto = :id
           ORDER BY ps.id_sucursal"
        : "SELECT ep.id_sucursal, s.sucursal AS nombre_sucursal,
                  SUM(ep.entrada) AS total_entrada, SUM(ep.salida) AS total_salida,
                  (SUM(ep.entrada) - SUM(ep.salida)) AS stock_actual
           FROM {$db}.extracto_productos ep
           LEFT JOIN {$db}.sucursales s ON s.id_sucursal = ep.id_sucursal
           WHERE ep.idproducto = :id AND ep.estado = 1
           GROUP BY ep.id_sucursal
           ORDER BY ep.id_sucursal";
    $stmt = $pdo->prepare($stockSql);
    $stmt->execute([':id' => $idproducto]);
    $stock = $stmt->fetchAll();
    $stockGlobal = 0.0;
    foreach ($stock as $stockRow) {
        $stockGlobal += (float)($stockRow['stock_actual'] ?? 0);
    }

    // 4. Códigos de barra
    $stmt = $pdo->prepare("SELECT id, codigo_barra FROM {$db}.codigo_barra WHERE id_producto = :id ORDER BY id");
    $stmt->execute([':id' => $idproducto]);
    $codigos_barra = $stmt->fetchAll();

    // 5. Series
    $series = [];
    try {
        $stmt = $pdo->prepare("SELECT id, serie, tipo, estado, id_sucursal, fecha_ingreso, fecha_salida, obs
            FROM {$db}.producto_series
            WHERE idproducto = :id
            ORDER BY estado DESC, serie ASC");
        $stmt->execute([':id' => $idproducto]);
        $series = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $series = [];
    }

    // 6. Imagen — prioridad: R2/BD, luego archivos locales
    $imagen = null;
    $fotoUrl = $producto['foto_url'] ?? null;
    if (!empty($fotoUrl)) {
        $variantsProd = ImageVariantService::deriveVariantUrls('', (string)$fotoUrl);
        $producto['foto_thumb_url'] = $variantsProd['thumb'] ?? $fotoUrl;
        $producto['foto_small_url'] = $variantsProd['small'] ?? $fotoUrl;
        $producto['foto_medium_url'] = $variantsProd['medium'] ?? $fotoUrl;
        $producto['foto_large_url'] = $variantsProd['large'] ?? $fotoUrl;
        $imagen = $producto['foto_medium_url'] ?: $fotoUrl;
    } else {
        $imgBaseDir = dirname(__DIR__, 2) . "/_lib/file/img/productos/{$db}/";
        $imgBasePath = "/public/_lib/file/img/productos/{$db}/";
        $cacheBaseDir = dirname(__DIR__, 2) . "/_lib/file/img/productos_cache/{$db}/";
        $cacheBasePath = "/public/_lib/file/img/productos_cache/{$db}/";

        if (is_dir($imgBaseDir)) {
            $files = glob($imgBaseDir . $idproducto . "_*");
            if (!empty($files)) {
                $imagen = $imgBasePath . basename($files[0]) . '?t=' . filemtime($files[0]);
            }
        }
        if (!$imagen && file_exists($cacheBaseDir . $idproducto . '.webp')) {
            $imagen = $cacheBasePath . $idproducto . '.webp?t=' . filemtime($cacheBaseDir . $idproducto . '.webp');
        }
        $producto['foto_thumb_url'] = $imagen;
        $producto['foto_small_url'] = $imagen;
        $producto['foto_medium_url'] = $imagen;
        $producto['foto_large_url'] = $imagen;
    }

    // 6b. Lista de imágenes del producto (desde producto_imagenes si existe)
    $imagenes = [];
    try {
        $stmtImg = $pdo->prepare("
            SELECT id, drive_file_id, url, filename, orden, principal, created_at
            FROM {$db}.producto_imagenes
            WHERE idproducto = :id
            ORDER BY principal DESC, orden ASC
        ");
        $stmtImg->execute([':id' => $idproducto]);
        $imagenes = $stmtImg->fetchAll();
        foreach ($imagenes as &$img) {
            $variants = ImageVariantService::deriveVariantUrls((string)($img['drive_file_id'] ?? ''), (string)($img['url'] ?? ''));
            $img['variants'] = $variants;
            $img['thumb_url'] = $variants['thumb'] ?? ($img['url'] ?? '');
            $img['small_url'] = $variants['small'] ?? ($img['url'] ?? '');
            $img['medium_url'] = $variants['medium'] ?? ($img['url'] ?? '');
            $img['large_url'] = $variants['large'] ?? ($img['url'] ?? '');
            if (isset($variants['medium']) && $variants['medium'] !== '') {
                $img['url'] = $variants['medium'];
            }
        }
        unset($img);
    } catch (Throwable $e) {
        // tabla puede no existir todavía
        $imagenes = [];
    }

    // 7. Últimos movimientos (extracto)
    $equivalencias = [];
    try {
        $stmtEquiv = $pdo->prepare("SELECT id, marca_cod_conversion, conversion, orden
            FROM {$db}.{$tablaEquivalencias}
            WHERE idproducto = :id
            ORDER BY orden ASC, id ASC");
        $stmtEquiv->execute([':id' => $idproducto]);
        $equivalencias = $stmtEquiv->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $equivalencias = [];
    }

    $aplicaciones = [];
    try {
        $stmtAplic = $pdo->prepare("SELECT id, marca_cod_conversion, conversion, marca_aplicacion, vehiculo_marca, vehiculo_modelo, anio, motor, codigo_motor, orden
            FROM {$db}.{$tablaAplicaciones}
            WHERE idproducto = :id
            ORDER BY orden ASC, id ASC");
        $stmtAplic->execute([':id' => $idproducto]);
        $aplicaciones = array_values(array_filter($stmtAplic->fetchAll(PDO::FETCH_ASSOC) ?: [], static function ($row) {
            return trim((string)($row['marca_aplicacion'] ?? '')) !== ''
                || trim((string)($row['vehiculo_marca'] ?? '')) !== ''
                || trim((string)($row['vehiculo_modelo'] ?? '')) !== ''
                || trim((string)($row['anio'] ?? '')) !== ''
                || trim((string)($row['motor'] ?? '')) !== ''
                || trim((string)($row['codigo_motor'] ?? '')) !== '';
        }));
    } catch (Throwable $e) {
        $aplicaciones = [];
    }

    if (empty($equivalencias)) {
        try {
            $stmtLegacyEquiv = $pdo->prepare("SELECT id, marca_cod_conversion, conversion, orden
                FROM {$db}.{$tablaAplicaciones}
                WHERE idproducto = :id
                AND (COALESCE(marca_cod_conversion, '') <> '' OR COALESCE(conversion, '') <> '')
                ORDER BY orden ASC, id ASC");
            $stmtLegacyEquiv->execute([':id' => $idproducto]);
            $equivalencias = $stmtLegacyEquiv->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $equivalencias = [];
        }
    }

    $aplicacionesLegacy = array_merge(
        array_map(static function ($row) {
            return [
                'id' => $row['id'] ?? null,
                'marca_cod_conversion' => $row['marca_cod_conversion'] ?? '',
                'conversion' => $row['conversion'] ?? '',
                'marca_aplicacion' => '',
                'vehiculo_marca' => '',
                'vehiculo_modelo' => '',
                'anio' => '',
                'motor' => '',
                'codigo_motor' => '',
                'orden' => $row['orden'] ?? 1,
            ];
        }, $equivalencias),
        $aplicaciones
    );

    // 8. Últimos movimientos (extracto)
    $stmt = $pdo->prepare("SELECT ep.id, ep.fecha, ep.descripcion, ep.entrada, ep.salida,
                ep.precio_gs, ep.importe_gs, ep.tipo_documento,
                s.sucursal AS nombre_sucursal
            FROM {$db}.extracto_productos ep
            LEFT JOIN {$db}.sucursales s ON s.id_sucursal = ep.id_sucursal
            WHERE ep.idproducto = :id AND ep.estado = 1
            ORDER BY ep.fecha DESC, ep.id DESC
            LIMIT 50");
    $stmt->execute([':id' => $idproducto]);
    $movimientos = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'data' => [
            'producto' => $producto,
            'precios' => $precios,
            'stock_global' => $stockGlobal,
            'stock_sucursal' => array_map(function($s) { return ['id_sucursal' => $s['id_sucursal'], 'sucursal' => $s['nombre_sucursal'], 'stock' => $s['stock_actual']]; }, $stock),
            'codigos_barra' => $codigos_barra,
            'series' => $series,
            'equivalentes' => $equivalencias,
            'aplicaciones' => $aplicacionesLegacy,
            'aplicaciones_detalle' => $aplicaciones,
            'imagen' => $imagen,
            'imagenes' => $imagenes,
            'movimientos' => $movimientos
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
