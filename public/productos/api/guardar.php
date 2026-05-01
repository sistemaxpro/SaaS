<?php
/**
 * Productos API - Guardar (Crear/Actualizar)
 * POST: { action: "create"|"update", producto: {...}, precios: [...], codigos_barra: [...] }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../../../config/bootstrap.php';

if (!function_exists('posProductosMercaderiasBypassAllowed')) {
    function posProductosMercaderiasBypassAllowed(): bool
    {
        try {
            $idLogin = (int)($_SESSION['id_login'] ?? $_SESSION['user_id'] ?? 0);
            $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
            if ($idLogin <= 0 || $idEmpresa <= 0) {
                return false;
            }

            $pdo = Database::getMasterConnection();
            $masterDb = Database::getMasterDbName();
            $stmt = $pdo->prepare("
                SELECT priv_admin, role
                FROM {$masterDb}.sec_users
                WHERE id_login = :id_login
                  AND id_empresa = :id_empresa
                LIMIT 1
            ");
            $stmt->execute([':id_login' => $idLogin, ':id_empresa' => $idEmpresa]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) {
                return false;
            }
            $privAdmin = strtoupper(trim((string)($row['priv_admin'] ?? 'N')));
            $role = strtoupper(trim((string)($row['role'] ?? '')));
            return in_array($privAdmin, ['Y', 'S', '1', 'TRUE'], true)
                || in_array($role, ['ADMIN', 'SUPERADMIN', 'ROOT', 'SUPERVISOR'], true)
                || strpos($role, 'ADMIN') !== false
                || strpos($role, 'SUPERVIS') !== false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

// Verificar permisos según acción
$rawInput = file_get_contents('php://input');
$tempInput = json_decode($rawInput, true);
$actionProd = $tempInput['action'] ?? 'create';
if (!posProductosMercaderiasBypassAllowed()) {
    if ($actionProd === 'create') {
        Permission::requirePermission('app_grid_mercaderias', 'priv_insert');
    } else {
        Permission::requirePermission('app_grid_mercaderias', 'priv_update');
    }
}

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../../../lib/producto_stock_snapshot.php';

if (!function_exists('guardarProductoStockSnapshotReady')) {
    function guardarProductoStockSnapshotReady(PDO $pdo, string $db): bool
    {
        return sxProductoStockSnapshotReady($pdo, $db);
    }
}

if (!function_exists('guardarProductoStockSnapshotAdjust')) {
    function guardarProductoStockSnapshotAdjust(PDO $pdo, string $db, int $idProducto, int $idSucursal, float $delta): void
    {
        sxProductoStockSnapshotAdjust($pdo, $db, $idProducto, $idSucursal, $delta);
    }
}

if (!function_exists('guardarProductoAplicacionesEnsureTable')) {
    function guardarProductoAplicacionesTableExists(PDO $pdo, string $db, string $table): bool
    {
        static $cache = [];
        $key = $db . '.' . $table;
        if (array_key_exists($key, $cache)) return $cache[$key];
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = :db AND table_name = :tbl LIMIT 1");
        $stmt->execute([':db' => $db, ':tbl' => $table]);
        return $cache[$key] = (bool)$stmt->fetchColumn();
    }

    function guardarProductoAplicacionesEnsureColumn(PDO $pdo, string $db, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = :db AND table_name = :tbl AND column_name = :col LIMIT 1");
        $stmt->execute([':db' => $db, ':tbl' => $table, ':col' => $column]);
        if ($stmt->fetchColumn()) return;
        $pdo->exec("ALTER TABLE `{$db}`.`{$table}` ADD COLUMN `{$column}` {$definition}");
    }

    function guardarProductoAplicacionesResolveTable(PDO $pdo, string $db): string
    {
        if (guardarProductoAplicacionesTableExists($pdo, $db, 'productos_aplicaciones')) {
            return 'productos_aplicaciones';
        }
        if (guardarProductoAplicacionesTableExists($pdo, $db, 'producto_aplicaciones')) {
            return 'producto_aplicaciones';
        }
        return 'producto_aplicaciones';
    }

    function guardarProductoAplicacionesEnsureTable(PDO $pdo, string $db): void
    {
        static $done = [];
        $table = guardarProductoAplicacionesResolveTable($pdo, $db);
        $doneKey = $db . '.' . $table;
        if (isset($done[$doneKey])) return;
        $done[$doneKey] = true;

        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.`{$table}` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `idproducto` INT NOT NULL,
            `conversion` VARCHAR(120) NULL DEFAULT NULL,
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

        guardarProductoAplicacionesEnsureColumn($pdo, $db, $table, 'conversion', 'VARCHAR(120) NULL DEFAULT NULL');
        guardarProductoAplicacionesEnsureColumn($pdo, $db, $table, 'vehiculo_marca', 'VARCHAR(120) NULL DEFAULT NULL');
        guardarProductoAplicacionesEnsureColumn($pdo, $db, $table, 'vehiculo_modelo', 'VARCHAR(120) NULL DEFAULT NULL');
        guardarProductoAplicacionesEnsureColumn($pdo, $db, $table, 'anio', 'VARCHAR(40) NULL DEFAULT NULL');
        guardarProductoAplicacionesEnsureColumn($pdo, $db, $table, 'motor', 'VARCHAR(120) NULL DEFAULT NULL');
        guardarProductoAplicacionesEnsureColumn($pdo, $db, $table, 'codigo_motor', 'VARCHAR(120) NULL DEFAULT NULL');
        guardarProductoAplicacionesEnsureColumn($pdo, $db, $table, 'orden', 'INT NOT NULL DEFAULT 1');
        guardarProductoAplicacionesEnsureColumn($pdo, $db, $table, 'id_login', 'INT NULL DEFAULT NULL');
        guardarProductoAplicacionesEnsureColumn($pdo, $db, $table, 'marca_cod_conversion', 'VARCHAR(120) NULL DEFAULT NULL');
        guardarProductoAplicacionesEnsureColumn($pdo, $db, $table, 'marca_aplicacion', 'VARCHAR(120) NULL DEFAULT NULL');
    }
}

if (!function_exists('guardarProductoAplicacionesNormalizeRows')) {
    function guardarProductoAplicacionesNormalizeRows($rows): array
    {
        $out = [];
        $seen = [];
        foreach (is_array($rows) ? $rows : [] as $idx => $row) {
            $item = is_array($row) ? $row : [];
            $normalized = [
                'marca_cod_conversion' => trim((string)($item['marca_cod_conversion'] ?? $item['conversion_marca'] ?? $item['marca_conversion'] ?? '')),
                'conversion' => trim((string)($item['conversion'] ?? '')),
                'marca_aplicacion' => trim((string)($item['marca_aplicacion'] ?? '')),
                'vehiculo_marca' => trim((string)($item['vehiculo_marca'] ?? '')),
                'vehiculo_modelo' => trim((string)($item['vehiculo_modelo'] ?? '')),
                'anio' => trim((string)($item['anio'] ?? '')),
                'motor' => trim((string)($item['motor'] ?? '')),
                'codigo_motor' => trim((string)($item['codigo_motor'] ?? '')),
                'orden' => max(1, (int)($item['orden'] ?? ($idx + 1))),
            ];
            $hasData = false;
            foreach (['marca_cod_conversion', 'conversion', 'marca_aplicacion', 'vehiculo_marca', 'vehiculo_modelo', 'anio', 'motor', 'codigo_motor'] as $field) {
                if ($normalized[$field] !== '') {
                    $hasData = true;
                    break;
                }
            }
            if (!$hasData) {
                continue;
            }

            $dedupeKey = implode('|', [
                mb_strtolower($normalized['marca_cod_conversion'], 'UTF-8'),
                mb_strtolower($normalized['conversion'], 'UTF-8'),
                mb_strtolower($normalized['marca_aplicacion'], 'UTF-8'),
                mb_strtolower($normalized['vehiculo_marca'], 'UTF-8'),
                mb_strtolower($normalized['vehiculo_modelo'], 'UTF-8'),
                mb_strtolower($normalized['anio'], 'UTF-8'),
                mb_strtolower($normalized['motor'], 'UTF-8'),
                mb_strtolower($normalized['codigo_motor'], 'UTF-8'),
            ]);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $out[] = $normalized;
        }
        return $out;
    }
}

if (!function_exists('guardarProductoEquivalenciasTableExists')) {
    function guardarProductoEquivalenciasTableExists(PDO $pdo, string $db, string $table): bool
    {
        static $cache = [];
        $key = $db . '.' . $table;
        if (array_key_exists($key, $cache)) return $cache[$key];
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = :db AND table_name = :tbl LIMIT 1");
        $stmt->execute([':db' => $db, ':tbl' => $table]);
        return $cache[$key] = (bool)$stmt->fetchColumn();
    }

    function guardarProductoEquivalenciasResolveTable(PDO $pdo, string $db): string
    {
        if (guardarProductoEquivalenciasTableExists($pdo, $db, 'productos_equivalencias')) {
            return 'productos_equivalencias';
        }
        if (guardarProductoEquivalenciasTableExists($pdo, $db, 'producto_equivalencias')) {
            return 'producto_equivalencias';
        }
        return 'producto_equivalencias';
    }

    function guardarProductoEquivalenciasEnsureColumn(PDO $pdo, string $db, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = :db AND table_name = :tbl AND column_name = :col LIMIT 1");
        $stmt->execute([':db' => $db, ':tbl' => $table, ':col' => $column]);
        if ($stmt->fetchColumn()) return;
        $pdo->exec("ALTER TABLE `{$db}`.`{$table}` ADD COLUMN `{$column}` {$definition}");
    }

    function guardarProductoEquivalenciasEnsureTable(PDO $pdo, string $db): void
    {
        static $done = [];
        $table = guardarProductoEquivalenciasResolveTable($pdo, $db);
        $doneKey = $db . '.' . $table;
        if (isset($done[$doneKey])) return;
        $done[$doneKey] = true;

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

        guardarProductoEquivalenciasEnsureColumn($pdo, $db, $table, 'marca_cod_conversion', 'VARCHAR(120) NULL DEFAULT NULL');
        guardarProductoEquivalenciasEnsureColumn($pdo, $db, $table, 'conversion', 'VARCHAR(120) NULL DEFAULT NULL');
        guardarProductoEquivalenciasEnsureColumn($pdo, $db, $table, 'orden', 'INT NOT NULL DEFAULT 1');
        guardarProductoEquivalenciasEnsureColumn($pdo, $db, $table, 'id_login', 'INT NULL DEFAULT NULL');
    }

    function guardarProductoEquivalenciasNormalizeRows($rows): array
    {
        $out = [];
        $seen = [];
        foreach (is_array($rows) ? $rows : [] as $idx => $row) {
            $item = is_array($row) ? $row : [];
            $normalized = [
                'marca_cod_conversion' => trim((string)($item['marca_cod_conversion'] ?? $item['conversion_marca'] ?? $item['marca_conversion'] ?? '')),
                'conversion' => trim((string)($item['conversion'] ?? '')),
                'orden' => max(1, (int)($item['orden'] ?? ($idx + 1))),
            ];
            if ($normalized['marca_cod_conversion'] === '' && $normalized['conversion'] === '') continue;
            $dedupeKey = implode('|', [
                mb_strtolower($normalized['marca_cod_conversion'], 'UTF-8'),
                mb_strtolower($normalized['conversion'], 'UTF-8'),
            ]);
            if (isset($seen[$dedupeKey])) continue;
            $seen[$dedupeKey] = true;
            $out[] = $normalized;
        }
        return $out;
    }

    function guardarProductoAplicacionesNormalizeAppRows($rows): array
    {
        $out = [];
        $seen = [];
        foreach (is_array($rows) ? $rows : [] as $idx => $row) {
            $item = is_array($row) ? $row : [];
            $normalized = [
                'marca_aplicacion' => trim((string)($item['marca_aplicacion'] ?? '')),
                'vehiculo_marca' => trim((string)($item['vehiculo_marca'] ?? '')),
                'vehiculo_modelo' => trim((string)($item['vehiculo_modelo'] ?? '')),
                'anio' => trim((string)($item['anio'] ?? '')),
                'motor' => trim((string)($item['motor'] ?? '')),
                'codigo_motor' => trim((string)($item['codigo_motor'] ?? '')),
                'orden' => max(1, (int)($item['orden'] ?? ($idx + 1))),
            ];
            $hasData = false;
            foreach (['marca_aplicacion', 'vehiculo_marca', 'vehiculo_modelo', 'anio', 'motor', 'codigo_motor'] as $field) {
                if ($normalized[$field] !== '') {
                    $hasData = true;
                    break;
                }
            }
            if (!$hasData) continue;
            $dedupeKey = implode('|', [
                mb_strtolower($normalized['marca_aplicacion'], 'UTF-8'),
                mb_strtolower($normalized['vehiculo_marca'], 'UTF-8'),
                mb_strtolower($normalized['vehiculo_modelo'], 'UTF-8'),
                mb_strtolower($normalized['anio'], 'UTF-8'),
                mb_strtolower($normalized['motor'], 'UTF-8'),
                mb_strtolower($normalized['codigo_motor'], 'UTF-8'),
            ]);
            if (isset($seen[$dedupeKey])) continue;
            $seen[$dedupeKey] = true;
            $out[] = $normalized;
        }
        return $out;
    }
}

$input = $tempInput;
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON inválido']);
    exit;
}

$id_empresa = (int)($input['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$id_login = (int)($_SESSION['id_login'] ?? 0);

$action = $input['action'] ?? 'create';
$prod = $input['producto'] ?? null;
if (!is_array($prod) || count($prod) === 0) {
    // Compatibilidad: clientes que envían el producto en raíz del JSON.
    $prod = $input;
}

$stage = 'init';

try {
    $stage = 'connect';
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    guardarProductoAplicacionesEnsureTable($pdo, $db);
    guardarProductoEquivalenciasEnsureTable($pdo, $db);
    $tablaAplicaciones = guardarProductoAplicacionesResolveTable($pdo, $db);
    $tablaEquivalencias = guardarProductoEquivalenciasResolveTable($pdo, $db);

    $stage = 'begin_transaction';
    $pdo->beginTransaction();

    // Detectar columnas disponibles en tblproductos para este tenant
    $stage = 'inspect_tblproductos';
    $colsStmt = $pdo->query("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'tblproductos'");
    $colsMeta = $colsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $availableCols = [];
    $columnMaxLen = [];
    foreach ($colsMeta as $cm) {
        $colName = (string)($cm['COLUMN_NAME'] ?? '');
        if ($colName === '') continue;
        $availableCols[] = $colName;
        $maxLen = $cm['CHARACTER_MAXIMUM_LENGTH'];
        if ($maxLen !== null) {
            $columnMaxLen[$colName] = (int)$maxLen;
        }
    }

    // Columnas que pueden no existir en tenants con esquema antiguo
    $hasCodBarra     = in_array('codigo_barra', $availableCols);
    $hasFotoUrl      = in_array('foto_url', $availableCols);
    $hasDescLarga    = in_array('descripcion_larga', $availableCols);
    $hasNcm          = in_array('ncm', $availableCols);
    $hasOrigen       = in_array('origen', $availableCols);
    $hasPeso         = in_array('peso', $availableCols);
    $hasAncho        = in_array('ancho', $availableCols);
    $hasAlto         = in_array('alto', $availableCols);
    $hasLargo        = in_array('largo', $availableCols);
    $hasWeb          = in_array('web', $availableCols);
    $hasPublicarWeb  = in_array('publicar_web', $availableCols);
    $hasUnidadMedida = in_array('unidad_medida', $availableCols);
    $hasMoneda       = in_array('moneda', $availableCols);
    $hasPorc         = in_array('porc', $availableCols);
    $hasTipoSifen    = in_array('tipo_producto_sifen', $availableCols);
    $hasColor        = in_array('color', $availableCols);
    $hasEquivalencia = in_array('equivalencia', $availableCols);
    $hasDescontinuado = in_array('descontinuado', $availableCols);
    $hasFechaDescontinuado = in_array('fecha_descontinuado', $availableCols);
    $hasGarantiaMeses = in_array('garantia_meses', $availableCols);
    $hasProveedorPrincipal = in_array('proveedor_principal', $availableCols);
    $hasUbicacionFisica = in_array('ubicacion_fisica', $availableCols);
    $hasUbicacion = in_array('ubicacion', $availableCols);
    $hasGondola = in_array('gondola', $availableCols);
    $hasFila = in_array('fila', $availableCols);
    $hasCelda = in_array('celda', $availableCols);
    $hasCondicionProducto = in_array('condicion_producto', $availableCols);
    $hasCapacidad = in_array('capacidad', $availableCols);
    $hasRam = in_array('ram', $availableCols);
    $hasCompatibilidad = in_array('compatibilidad', $availableCols);
    $hasIncluye = in_array('incluye', $availableCols);

    if (!$hasEquivalencia) {
        try {
            $pdo->exec("ALTER TABLE {$db}.tblproductos ADD COLUMN equivalencia VARCHAR(255) NULL AFTER modelo");
            $hasEquivalencia = true;
            $availableCols[] = 'equivalencia';
            $columnMaxLen['equivalencia'] = 255;
        } catch (Throwable $e) {
            $hasEquivalencia = false;
        }
    }

    $descontinuadoInput = ((int)($prod['descontinuado'] ?? 0) === 1 ? 1 : 0);
    $estadoInput = ((int)($prod['activo'] ?? $prod['Estado'] ?? 1) === 1 ? 1 : 0);
    $estadoFinal = $descontinuadoInput === 1 ? 0 : $estadoInput;

    // Construir columnas/valores dinámicamente
    // Columnas que SIEMPRE existen
    $baseCols = [
        'cve_producto', 'desproducto', 'referencia', 'precio_compra', 'precio_venta',
        'impuesto', 'iva', 'grupo', 'marca', 'modelo',
        'stock_minimo', 'stock_maximo', 'controla_stock', 'edita_precio',
        'vende_sin_stock', 'obs', 'tipo',
        'editable', 'usaserial', 'usavencimiento'
    ];
    $baseParams = [
        ':cve'    => trim($prod['cve_producto'] ?? ''),
        ':des'    => trim($prod['desproducto'] ?? ''),
        ':ref'    => trim($prod['referencia'] ?? ''),
        ':pc'     => (float)($prod['precio_compra'] ?? 0),
        ':pv'     => (float)($prod['precio_venta'] ?? 0),
        ':imp'    => (float)($prod['impuesto'] ?? 10),
        ':iva'    => (int)($prod['iva'] ?? 1),
        ':grp'    => (int)($prod['grupo'] ?? 0),
        ':mar'    => (int)($prod['marca'] ?? 0),
        ':mod'    => (int)($prod['modelo'] ?? 0),
        ':smin'   => (float)($prod['stock_minimo'] ?? 0),
        ':smax'   => (float)($prod['stock_maximo'] ?? 0),
        ':cstock' => (int)($prod['controla_stock'] ?? 1),
        ':eprecio'=> (int)($prod['edita_precio'] ?? -1),
        ':vss'    => (int)($prod['vende_sin_stock'] ?? 1),
        ':obs'    => trim($prod['obs'] ?? ''),
        ':tipo'   => (int)($prod['tipo'] ?? 1),
        ':editable' => (int)($prod['editable'] ?? -1),
        ':usaserial' => (int)($prod['usaserial'] ?? -1),
        ':usavenc' => (int)($prod['usavencimiento'] ?? -1),
    ];
    $colParamMap = [
        'cve_producto' => ':cve', 'desproducto' => ':des', 'referencia' => ':ref',
        'precio_compra' => ':pc', 'precio_venta' => ':pv',
        'impuesto' => ':imp', 'iva' => ':iva', 'grupo' => ':grp', 'marca' => ':mar', 'modelo' => ':mod',
        'color' => ':color',
        'stock_minimo' => ':smin', 'stock_maximo' => ':smax',
        'controla_stock' => ':cstock', 'edita_precio' => ':eprecio',
        'vende_sin_stock' => ':vss', 'obs' => ':obs', 'equivalencia' => ':equiv', 'tipo' => ':tipo',
        'editable' => ':editable', 'usaserial' => ':usaserial', 'usavencimiento' => ':usavenc',
    ];

    // Agregar columnas opcionales si existen
    $optionalMap = [
        [$hasMoneda,       'moneda',             ':mon',    (int)($prod['moneda'] ?? 1)],
        [$hasPorc,         'porc',               ':porc',   (float)($prod['porc'] ?? 30)],
        [$hasCodBarra,     'codigo_barra',       ':cb',     trim($prod['codigo_barra'] ?? '')],
        [$hasUnidadMedida, 'unidad_medida',      ':um',     $prod['unidad_medida'] ?? null],
        [$hasDescLarga,    'descripcion_larga',   ':dl',     trim($prod['descripcion_larga'] ?? '')],
        [$hasWeb,          'web',                ':web',    (int)($prod['web'] ?? 0)],
        [$hasPublicarWeb,  'publicar_web',       ':pweb',   (int)($prod['publicar_web'] ?? 0)],
        [$hasColor,        'color',              ':color',  (int)($prod['color'] ?? 0)],
        [$hasEquivalencia, 'equivalencia',       ':equiv',  trim((string)($prod['equivalencia'] ?? ''))],
        [$hasGarantiaMeses, 'garantia_meses',    ':garantia', max(0, (int)($prod['garantia_meses'] ?? 0))],
        [$hasProveedorPrincipal, 'proveedor_principal', ':provp', trim((string)($prod['proveedor_principal'] ?? ''))],
        [$hasUbicacionFisica, 'ubicacion_fisica', ':ubicf', trim((string)($prod['ubicacion_fisica'] ?? ''))],
        [$hasUbicacion, 'ubicacion', ':ubicacion', trim((string)($prod['ubicacion'] ?? ''))],
        [$hasGondola, 'gondola', ':gondola', trim((string)($prod['gondola'] ?? ''))],
        [$hasFila, 'fila', ':fila', trim((string)($prod['fila'] ?? ''))],
        [$hasCelda, 'celda', ':celda', trim((string)($prod['celda'] ?? ''))],
        [$hasCondicionProducto, 'condicion_producto', ':condp', trim((string)($prod['condicion_producto'] ?? ''))],
        [$hasCapacidad,    'capacidad',          ':cap',    trim((string)($prod['capacidad'] ?? ''))],
        [$hasRam,          'ram',                ':ram',    trim((string)($prod['ram'] ?? ''))],
        [$hasCompatibilidad, 'compatibilidad',   ':compat', trim((string)($prod['compatibilidad'] ?? ''))],
        [$hasIncluye,      'incluye',            ':incluye', trim((string)($prod['incluye'] ?? ''))],
        [$hasPeso,         'peso',               ':peso',   (float)($prod['peso'] ?? 0)],
        [$hasAncho,        'ancho',              ':ancho',  (float)($prod['ancho'] ?? 0)],
        [$hasAlto,         'alto',               ':alto',   (float)($prod['alto'] ?? 0)],
        [$hasLargo,        'largo',              ':largo',  (float)($prod['largo'] ?? 0)],
        [$hasNcm,          'ncm',                ':ncm',    trim($prod['ncm'] ?? '')],
        [$hasOrigen,       'origen',             ':origen', (int)($prod['origen'] ?? 1)],
        [$hasTipoSifen,    'tipo_producto_sifen',':tps',    (int)($prod['tipo_producto_sifen'] ?? 1)],
        [$hasFotoUrl,      'foto_url',           ':furl',   trim($prod['foto_url'] ?? '')],
        [$hasDescontinuado,'descontinuado',      ':descnt', $descontinuadoInput],
    ];

    foreach ($optionalMap as [$exists, $col, $param, $val]) {
        if ($exists) {
            $baseCols[] = $col;
            $baseParams[$param] = $val;
            $colParamMap[$col] = $param;
        }
    }

    $desproducto = trim((string)($prod['desproducto'] ?? ''));
    if ($desproducto === '') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'La descripción del producto es obligatoria']);
        exit;
    }
    if (isset($columnMaxLen['desproducto']) && mb_strlen($desproducto) > $columnMaxLen['desproducto']) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error' => "La descripción supera el máximo de {$columnMaxLen['desproducto']} caracteres"
        ]);
        exit;
    }
    $cveLen = isset($columnMaxLen['cve_producto']) ? $columnMaxLen['cve_producto'] : null;
    $cveRaw = trim((string)($prod['cve_producto'] ?? ''));
    if ($cveLen !== null && $cveRaw !== '' && mb_strlen($cveRaw) > $cveLen) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => "El código supera el máximo de {$cveLen} caracteres"]);
        exit;
    }
    if ($hasCodBarra) {
        $cbLen = isset($columnMaxLen['codigo_barra']) ? $columnMaxLen['codigo_barra'] : null;
        $cbRaw = trim((string)($prod['codigo_barra'] ?? ''));
        if ($cbLen !== null && $cbRaw !== '' && mb_strlen($cbRaw) > $cbLen) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => "El código de barra supera el máximo de {$cbLen} caracteres"]);
            exit;
        }
    }

    if ($action === 'create') {
        $stage = 'create_producto';
        // Generar código automático si no viene
        $cve = trim($prod['cve_producto'] ?? '');
        if (empty($cve)) {
            $stmt = $pdo->query("SELECT IFNULL(MAX(CAST(cve_producto AS UNSIGNED)), 0) + 1 AS next_code FROM {$db}.tblproductos WHERE cve_producto REGEXP '^[0-9]+$'");
            $cve = str_pad($stmt->fetch()['next_code'], 6, '0', STR_PAD_LEFT);
        }

        // Validar código único
        $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM {$db}.tblproductos WHERE cve_producto = :c");
        $stmt->execute([':c' => $cve]);
        if ($stmt->fetch()['c'] > 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => "El código '{$cve}' ya existe"]);
            exit;
        }

        $stmtDupCombo = $pdo->prepare("SELECT idproducto
            FROM {$db}.tblproductos
            WHERE cve_producto = :cve AND desproducto = :des
            LIMIT 1");
        $stmtDupCombo->execute([
            ':cve' => $cve,
            ':des' => $desproducto,
        ]);
        $dupComboId = (int)($stmtDupCombo->fetchColumn() ?: 0);
        if ($dupComboId > 0) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'error' => "Ya existe un producto con el código '{$cve}' y la descripción '{$desproducto}'"
            ]);
            exit;
        }

        $baseParams[':cve'] = $cve;

        // Construir INSERT dinámico. Si se marca descontinuado, crear como inactivo.
        $insertCols = $baseCols;
        $insertCols[] = 'Estado';
        if ($hasFechaDescontinuado) $insertCols[] = 'fecha_descontinuado';
        $insertParams = $baseParams;
        $insertPlaceholders = [];
        foreach ($insertCols as $col) {
            if ($col === 'Estado') {
                $insertPlaceholders[] = (string)$estadoFinal;
            } elseif ($col === 'fecha_descontinuado') {
                $insertPlaceholders[] = ($descontinuadoInput === 1 ? 'NOW()' : 'NULL');
            } else {
                $insertPlaceholders[] = $colParamMap[$col];
            }
        }

        $sql = "INSERT INTO {$db}.tblproductos (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertPlaceholders) . ")";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($insertParams);

        $idproducto = (int)$pdo->lastInsertId();

    } elseif ($action === 'update') {
        $stage = 'update_producto';
        $idproducto = (int)($prod['idproducto'] ?? 0);
        if ($idproducto <= 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'ID de producto requerido']);
            exit;
        }

        // Validar código único (excluyendo el actual)
        $cve = trim($prod['cve_producto'] ?? '');
        if (!empty($cve)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM {$db}.tblproductos WHERE cve_producto = :c AND idproducto != :id");
            $stmt->execute([':c' => $cve, ':id' => $idproducto]);
            if ($stmt->fetch()['c'] > 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => "El código '{$cve}' ya existe en otro producto"]);
                exit;
            }
        }

        $stmtDupCombo = $pdo->prepare("SELECT idproducto
            FROM {$db}.tblproductos
            WHERE cve_producto = :cve
              AND desproducto = :des
              AND idproducto != :id
            LIMIT 1");
        $stmtDupCombo->execute([
            ':cve' => $cve,
            ':des' => $desproducto,
            ':id' => $idproducto,
        ]);
        $dupComboId = (int)($stmtDupCombo->fetchColumn() ?: 0);
        if ($dupComboId > 0) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'error' => "Ya existe un producto con el código '{$cve}' y la descripción '{$desproducto}'"
            ]);
            exit;
        }

        $baseParams[':cve'] = $cve ?: null;

        // Construir UPDATE dinámico
        $setClauses = [];
        foreach ($baseCols as $col) {
            $setClauses[] = "{$col} = {$colParamMap[$col]}";
        }
        $setClauses[] = "Estado = :estado_final";
        $baseParams[':estado_final'] = $estadoFinal;
        if ($hasFechaDescontinuado) {
            $setClauses[] = "fecha_descontinuado = CASE WHEN :descontinuado_flag = 1 THEN COALESCE(fecha_descontinuado, NOW()) ELSE NULL END";
            $baseParams[':descontinuado_flag'] = $descontinuadoInput;
        }
        $baseParams[':id'] = $idproducto;

        $sql = "UPDATE {$db}.tblproductos SET " . implode(', ', $setClauses) . " WHERE idproducto = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($baseParams);
    } else {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
        exit;
    }

    // Guardar precios en mercaderia_precio.
    // Si no vienen precios explícitos, autogenerar desde tipo_precio habilitado.
    $precios = $input['precios'] ?? [];
    $cve_producto = trim((string)($prod['cve_producto'] ?? ''));
    if ($cve_producto === '') {
        $stmt = $pdo->prepare("SELECT cve_producto FROM {$db}.tblproductos WHERE idproducto = :id");
        $stmt->execute([':id' => $idproducto]);
        $cve_producto = trim((string)($stmt->fetchColumn() ?: ''));
    }

    $priceLookupCodes = [(int)$idproducto];
    if ($cve_producto !== '' && ctype_digit($cve_producto)) {
        $priceLookupCodes[] = (int)$cve_producto;
    }
    $priceLookupCodes = array_values(array_unique(array_filter($priceLookupCodes, static fn($value) => (int)$value > 0)));
    $priceStorageCode = (int)$idproducto > 0 ? (int)$idproducto : (!empty($priceLookupCodes) ? (int)$priceLookupCodes[0] : 0);

    if ($priceStorageCode > 0) {
        $stage = 'sync_precios';
        $hasMercaderiaPrecio = false;
        $hasTipoPrecio = false;
        try {
            $chk = $pdo->query("SELECT 1 FROM {$db}.mercaderia_precio LIMIT 1");
            $hasMercaderiaPrecio = (bool)$chk;
        } catch (Throwable $e) {
            $hasMercaderiaPrecio = false;
        }
        try {
            $chk = $pdo->query("SELECT 1 FROM {$db}.tipo_precio LIMIT 1");
            $hasTipoPrecio = (bool)$chk;
        } catch (Throwable $e) {
            $hasTipoPrecio = false;
        }

        if ($hasMercaderiaPrecio && $hasTipoPrecio) {
            $rowsToPersist = [];
            $mapMonedaValue = static function ($value): int {
                $raw = strtoupper(trim((string)$value));
                if ($raw === 'USD' || $raw === '2') return 2;
                if ($raw === 'BRL' || $raw === '3') return 3;
                return 1;
            };
            $tiposPrecioMap = [];
            $stmtTiposAll = $pdo->query("SELECT id, porcentaje, moneda, descuento FROM {$db}.tipo_precio ORDER BY id");
            foreach (($stmtTiposAll ? $stmtTiposAll->fetchAll(PDO::FETCH_ASSOC) : []) as $tp) {
                $tipoId = (int)($tp['id'] ?? 0);
                if ($tipoId <= 0) continue;
                $tiposPrecioMap[$tipoId] = [
                    'porcentaje' => (float)($tp['porcentaje'] ?? 0),
                    'moneda' => $mapMonedaValue($tp['moneda'] ?? 1),
                    'descuento' => (float)($tp['descuento'] ?? 0),
                ];
            }

            if (!empty($precios)) {
                foreach ($precios as $p) {
                    $tipo = (int)($p['tipo'] ?? 0);
                    if ($tipo <= 0) {
                        continue;
                    }
                    $tipoDefaults = $tiposPrecioMap[$tipo] ?? ['porcentaje' => 0, 'moneda' => 1, 'descuento' => 0];
                    $costo = max(0, (float)($p['costo'] ?? 0));
                    $porcentaje = isset($p['porcentaje']) && $p['porcentaje'] !== '' ? max(0, (float)$p['porcentaje']) : max(0, (float)$tipoDefaults['porcentaje']);
                    $precioValor = isset($p['precio']) && $p['precio'] !== '' ? max(0, (float)$p['precio']) : round($costo * (1 + ($porcentaje / 100)), 2);
                    $rowsToPersist[] = [
                        'tipo' => $tipo,
                        'costo' => $costo,
                        'porcentaje' => $porcentaje,
                        'precio' => $precioValor,
                        'moneda' => $mapMonedaValue($p['moneda'] ?? $tipoDefaults['moneda']),
                        'descuento' => isset($p['descuento']) && $p['descuento'] !== '' ? max(0, (float)$p['descuento']) : max(0, (float)$tipoDefaults['descuento']),
                    ];
                }
            } else {
                // Sin precios enviados: autogenerar desde tipos habilitados.
                $tipoColsStmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'tipo_precio'");
                $tipoCols = $tipoColsStmt->fetchAll(PDO::FETCH_COLUMN);
                $hasEstadoTipo = in_array('estado', $tipoCols, true);
                $hasActivoTipo = in_array('activo', $tipoCols, true);

                $where = [];
                if ($hasEstadoTipo) $where[] = "estado = 1";
                if ($hasActivoTipo) $where[] = "activo = 1";
                $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

                $stmtTipos = $pdo->query("SELECT id, porcentaje, moneda, descuento FROM {$db}.tipo_precio {$whereSql} ORDER BY id");
                $tipos = $stmtTipos->fetchAll(PDO::FETCH_ASSOC);
                if (empty($tipos)) {
                    // Fallback mínimo para no dejar producto sin precio por tipo.
                    $tipos = [['id' => 1, 'porcentaje' => 0, 'moneda' => 1, 'descuento' => 0]];
                }

                $baseCosto = (float)($prod['precio_compra'] ?? 0);
                $baseVenta = (float)($prod['precio_venta'] ?? 0);
                foreach ($tipos as $tp) {
                    $rowsToPersist[] = [
                        'tipo' => (int)($tp['id'] ?? 1),
                        'costo' => $baseCosto,
                        'porcentaje' => (float)($tp['porcentaje'] ?? 0),
                        'precio' => $baseVenta,
                        'moneda' => (int)($tp['moneda'] ?? 1),
                        'descuento' => (float)($tp['descuento'] ?? 0),
                    ];
                }
            }

            // Reemplazar precios del producto por los calculados/recibidos.
            $deleteSql = "DELETE FROM {$db}.mercaderia_precio WHERE codigo = :cod0";
            $deleteParams = [':cod0' => $priceStorageCode];
            foreach ($priceLookupCodes as $idx => $code) {
                if ((int)$code === $priceStorageCode) {
                    continue;
                }
                $param = ':cod' . ($idx + 1);
                $deleteSql .= " OR codigo = {$param}";
                $deleteParams[$param] = (int)$code;
            }
            $pdo->prepare($deleteSql)->execute($deleteParams);

            $stmtPrecio = $pdo->prepare("INSERT INTO {$db}.mercaderia_precio (codigo, tipo, costo, porcentaje, precio, moneda, id_login, descuento) VALUES (:cod, :tipo, :costo, :porc, :precio, :mon, :login, :desc)");
            foreach ($rowsToPersist as $p) {
                $stmtPrecio->execute([
                    ':cod'    => $priceStorageCode,
                    ':tipo'   => (int)$p['tipo'],
                    ':costo'  => (float)$p['costo'],
                    ':porc'   => (float)$p['porcentaje'],
                    ':precio' => (float)$p['precio'],
                    ':mon'    => (int)$p['moneda'],
                    ':login'  => $id_login,
                    ':desc'   => (float)$p['descuento'],
                ]);
            }
        }
    }

    // Guardar códigos de barra si vienen
    $codigos = $input['codigos_barra'] ?? [];
    if (isset($input['codigos_barra'])) {
        $stage = 'sync_codigos_barra';
        // Eliminar anteriores y re-insertar
        $pdo->prepare("DELETE FROM {$db}.codigo_barra WHERE id_producto = :id")->execute([':id' => $idproducto]);

        $stmtCB = $pdo->prepare("INSERT INTO {$db}.codigo_barra (id_producto, codigo_barra, id_login) VALUES (:id, :cb, :login)");
        foreach ($codigos as $cb) {
            $codigoBarra = trim(is_array($cb) ? ($cb['codigo_barra'] ?? '') : $cb);
            if (empty($codigoBarra)) continue;

            // Validar unicidad global
            $stmtCheck = $pdo->prepare("SELECT id_producto FROM {$db}.codigo_barra WHERE codigo_barra = :cb AND id_producto != :id");
            $stmtCheck->execute([':cb' => $codigoBarra, ':id' => $idproducto]);
            $existing = $stmtCheck->fetch();
            if ($existing) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => "El código de barra '{$codigoBarra}' ya está asignado al producto #{$existing['id_producto']}"]);
                exit;
            }

            $stmtCB->execute([':id' => $idproducto, ':cb' => $codigoBarra, ':login' => $id_login]);
        }
    }

    $series = $input['series'] ?? [];
    try {
        $stage = 'sync_series';
        $pdo->prepare("DELETE FROM {$db}.producto_series WHERE idproducto = :id")->execute([':id' => $idproducto]);
        if (is_array($series) && !empty($series)) {
            $stmtSerie = $pdo->prepare("INSERT INTO {$db}.producto_series
                (idproducto, serie, tipo, estado, id_sucursal, fecha_ingreso, fecha_salida, id_login, obs)
                VALUES (:idproducto, :serie, :tipo, :estado, :id_sucursal, :fecha_ingreso, :fecha_salida, :id_login, :obs)");
            $seenSeries = [];
            foreach ($series as $serieRow) {
                $serie = strtoupper(trim((string)($serieRow['serie'] ?? '')));
                if ($serie === '' || isset($seenSeries[$serie])) {
                    continue;
                }
                $seenSeries[$serie] = true;
                $tipoSerie = strtoupper(trim((string)($serieRow['tipo'] ?? 'SERIAL')));
                if (!in_array($tipoSerie, ['SERIAL', 'IMEI'], true)) {
                    $tipoSerie = 'SERIAL';
                }
                $estadoSerie = ((int)($serieRow['estado'] ?? 1) === 1) ? 1 : 0;
                $fechaIngreso = trim((string)($serieRow['fecha_ingreso'] ?? ''));
                $fechaSalida = trim((string)($serieRow['fecha_salida'] ?? ''));
                $obsSerie = trim((string)($serieRow['obs'] ?? ''));
                $stmtSerie->execute([
                    ':idproducto' => $idproducto,
                    ':serie' => $serie,
                    ':tipo' => $tipoSerie,
                    ':estado' => $estadoSerie,
                    ':id_sucursal' => ($serieRow['id_sucursal'] ?? '') !== '' ? (int)$serieRow['id_sucursal'] : null,
                    ':fecha_ingreso' => $fechaIngreso !== '' ? $fechaIngreso : date('Y-m-d H:i:s'),
                    ':fecha_salida' => $fechaSalida !== '' ? $fechaSalida : null,
                    ':id_login' => $id_login > 0 ? $id_login : null,
                    ':obs' => $obsSerie !== '' ? $obsSerie : null,
                ]);
            }
        }
    } catch (Throwable $e) {
        // No romper guardado principal si el tenant todavía no soporta series nuevas.
        error_log('[productos/guardar] sync_series omitido: ' . $e->getMessage());
    }

    try {
        $stage = 'sync_equivalencias';
        $legacyRows = guardarProductoAplicacionesNormalizeRows($input['aplicaciones'] ?? []);
        $equivalencias = array_key_exists('equivalentes', $input)
            ? guardarProductoEquivalenciasNormalizeRows($input['equivalentes'] ?? [])
            : guardarProductoEquivalenciasNormalizeRows($legacyRows);
        $pdo->prepare("DELETE FROM {$db}.{$tablaEquivalencias} WHERE idproducto = :id")->execute([':id' => $idproducto]);
        if (!empty($equivalencias)) {
            $stmtEquiv = $pdo->prepare("INSERT INTO {$db}.{$tablaEquivalencias}
                (idproducto, marca_cod_conversion, conversion, orden, id_login)
                VALUES (:idproducto, :marca_cod_conversion, :conversion, :orden, :id_login)");
            foreach ($equivalencias as $idx => $equiv) {
                $stmtEquiv->execute([
                    ':idproducto' => $idproducto,
                    ':marca_cod_conversion' => $equiv['marca_cod_conversion'] !== '' ? $equiv['marca_cod_conversion'] : null,
                    ':conversion' => $equiv['conversion'] !== '' ? $equiv['conversion'] : null,
                    ':orden' => max(1, (int)($equiv['orden'] ?? ($idx + 1))),
                    ':id_login' => $id_login > 0 ? $id_login : null,
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log('[productos/guardar] sync_equivalencias omitido: ' . $e->getMessage());
    }

    try {
        $stage = 'sync_aplicaciones';
        $legacyRows = isset($legacyRows) ? $legacyRows : guardarProductoAplicacionesNormalizeRows($input['aplicaciones'] ?? []);
        $aplicaciones = array_key_exists('equivalentes', $input)
            ? guardarProductoAplicacionesNormalizeAppRows($input['aplicaciones'] ?? [])
            : guardarProductoAplicacionesNormalizeAppRows($legacyRows);
        $pdo->prepare("DELETE FROM {$db}.{$tablaAplicaciones} WHERE idproducto = :id")->execute([':id' => $idproducto]);
        if (!empty($aplicaciones)) {
            $stmtAplic = $pdo->prepare("INSERT INTO {$db}.{$tablaAplicaciones}
                (idproducto, marca_cod_conversion, conversion, marca_aplicacion, vehiculo_marca, vehiculo_modelo, anio, motor, codigo_motor, orden, id_login)
                VALUES (:idproducto, :marca_cod_conversion, :conversion, :marca_aplicacion, :vehiculo_marca, :vehiculo_modelo, :anio, :motor, :codigo_motor, :orden, :id_login)");
            foreach ($aplicaciones as $idx => $aplic) {
                $stmtAplic->execute([
                    ':idproducto' => $idproducto,
                    ':marca_cod_conversion' => null,
                    ':conversion' => null,
                    ':marca_aplicacion' => $aplic['marca_aplicacion'] !== '' ? $aplic['marca_aplicacion'] : null,
                    ':vehiculo_marca' => $aplic['vehiculo_marca'] !== '' ? $aplic['vehiculo_marca'] : null,
                    ':vehiculo_modelo' => $aplic['vehiculo_modelo'] !== '' ? $aplic['vehiculo_modelo'] : null,
                    ':anio' => $aplic['anio'] !== '' ? $aplic['anio'] : null,
                    ':motor' => $aplic['motor'] !== '' ? $aplic['motor'] : null,
                    ':codigo_motor' => $aplic['codigo_motor'] !== '' ? $aplic['codigo_motor'] : null,
                    ':orden' => max(1, (int)($aplic['orden'] ?? ($idx + 1))),
                    ':id_login' => $id_login > 0 ? $id_login : null,
                ]);
            }
        }
    } catch (Throwable $e) {
        error_log('[productos/guardar] sync_aplicaciones omitido: ' . $e->getMessage());
    }

    $stage = 'commit_main';
    $pdo->commit();

    // Stock inicial (solo en creación, después del commit principal)
    if ($action === 'create') {
        $stockInicial = (float)($input['stock_inicial'] ?? 0);
        $idSucursalStock = (int)($input['id_sucursal_stock'] ?? 1);
        if ($stockInicial > 0) {
            try {
                $stage = 'stock_inicial';
                $pdo->beginTransaction();
                $cveForStock = $cve ?? '';
                if (empty($cveForStock)) {
                    $stmtCve = $pdo->prepare("SELECT cve_producto, desproducto FROM {$db}.tblproductos WHERE idproducto = :id");
                    $stmtCve->execute([':id' => $idproducto]);
                    $prodInfo = $stmtCve->fetch();
                    $cveForStock = $prodInfo['cve_producto'] ?? '';
                    $desForStock = $prodInfo['desproducto'] ?? '';
                } else {
                    $desForStock = $desproducto;
                }

                $pdo->prepare("INSERT INTO {$db}.extracto_productos 
                        (idproducto, estado, id_sucursal, fecha, codigo, descripcion, entrada, salida, 
                         obs, id_login, stock_anterior, stock_actual, tipo_documento)
                        VALUES (:id, 1, :suc, NOW(), :cod, :desc, :ent, 0, :obs, :login, 0, :sn, 99)")
                    ->execute([
                        ':id'    => $idproducto,
                        ':suc'   => $idSucursalStock,
                        ':cod'   => $cveForStock,
                        ':desc'  => $desForStock,
                        ':ent'   => $stockInicial,
                        ':obs'   => 'Stock inicial',
                        ':login' => $id_login,
                        ':sn'    => $stockInicial,
                    ]);

                $pdo->prepare("UPDATE {$db}.tblproductos SET saldo = :s WHERE idproducto = :id")
                    ->execute([':s' => $stockInicial, ':id' => $idproducto]);
                guardarProductoStockSnapshotAdjust($pdo, $db, (int)$idproducto, (int)$idSucursalStock, (float)$stockInicial);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                // No fallar la creación del producto por error de stock
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $action === 'create' ? 'Producto creado correctamente' : 'Producto actualizado correctamente',
        'idproducto' => $idproducto
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('PRODUCTOS guardar error: ' . json_encode([
        'stage' => $stage,
        'id_empresa' => $id_empresa,
        'id_login' => $id_login,
        'action' => $action,
        'idproducto' => (int)($prod['idproducto'] ?? 0),
        'cve_producto' => (string)($prod['cve_producto'] ?? ''),
        'message' => $e->getMessage(),
        'sqlstate' => ($e instanceof PDOException ? (string)($e->errorInfo[0] ?? '') : ''),
        'code' => ($e instanceof PDOException ? (string)($e->errorInfo[1] ?? '') : (string)$e->getCode()),
    ], JSON_UNESCAPED_UNICODE));
    if ($e instanceof PDOException) {
        $sqlState = (string)($e->errorInfo[0] ?? '');
        if ($sqlState === '22001') {
            echo json_encode(['success' => false, 'error' => 'Uno de los campos supera el largo permitido']);
            exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'No se pudo guardar el producto: ' . $e->getMessage()]);
}
