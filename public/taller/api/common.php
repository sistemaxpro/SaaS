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
require_once __DIR__ . '/../../../src/Services/R2StorageService.php';
require_once __DIR__ . '/../../../src/Services/ImageVariantService.php';

if (!defined('TALLER_SKIP_PERMISSION_CHECK') || TALLER_SKIP_PERMISSION_CHECK !== true) {
    Permission::requireAccess('app_grid_taller_mantenimiento');
}

function tallerConn(int $idEmpresa): array
{
    $conn = getEmpresaConnection($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    tallerEnsureSchema($pdo, $db);
    return $conn;
}

function tallerEnsureSchema(PDO $pdo, string $db): void
{
    static $done = [];
    if (isset($done[$db])) return;
    $done[$db] = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`taller_clientes` (
            `id_cliente` INT NOT NULL AUTO_INCREMENT,
            `nombre` VARCHAR(160) NOT NULL,
            `telefono` VARCHAR(40) NULL,
            `documento` VARCHAR(30) NULL,
            `direccion` VARCHAR(255) NULL,
            `activo` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_cliente`),
            INDEX `idx_nombre` (`nombre`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`taller_vehiculos` (
            `id_vehiculo` INT NOT NULL AUTO_INCREMENT,
            `id_cliente` INT NOT NULL,
            `marca` VARCHAR(80) NOT NULL,
            `modelo` VARCHAR(80) NOT NULL,
            `anio` VARCHAR(10) NULL,
            `chapa` VARCHAR(30) NOT NULL,
            `vin` VARCHAR(40) NULL,
            `chassis` VARCHAR(60) NULL,
            `color` VARCHAR(40) NULL,
            `km_actual` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `activo` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_vehiculo`),
            UNIQUE KEY `uk_chapa` (`chapa`),
            INDEX `idx_cliente` (`id_cliente`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!tallerHasColumn($pdo, $db, 'taller_vehiculos', 'chassis')) {
        $pdo->exec("ALTER TABLE `{$db}`.`taller_vehiculos` ADD COLUMN `chassis` VARCHAR(60) NULL AFTER `vin`");
    }
    if (!tallerHasColumn($pdo, $db, 'taller_vehiculos', 'color')) {
        $pdo->exec("ALTER TABLE `{$db}`.`taller_vehiculos` ADD COLUMN `color` VARCHAR(40) NULL AFTER `chassis`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`taller_vehiculo_fotos` (
            `id_foto` INT NOT NULL AUTO_INCREMENT,
            `id_vehiculo` INT NOT NULL,
            `ruta` VARCHAR(255) NOT NULL,
            `storage` VARCHAR(20) NOT NULL DEFAULT 'local',
            `file_id` VARCHAR(255) NULL,
            `url` TEXT NULL,
            `titulo` VARCHAR(120) NULL,
            `created_by` INT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_foto`),
            INDEX `idx_vehiculo` (`id_vehiculo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!tallerHasColumn($pdo, $db, 'taller_vehiculo_fotos', 'storage')) {
        $pdo->exec("ALTER TABLE `{$db}`.`taller_vehiculo_fotos` ADD COLUMN `storage` VARCHAR(20) NOT NULL DEFAULT 'local' AFTER `ruta`");
    }
    if (!tallerHasColumn($pdo, $db, 'taller_vehiculo_fotos', 'file_id')) {
        $pdo->exec("ALTER TABLE `{$db}`.`taller_vehiculo_fotos` ADD COLUMN `file_id` VARCHAR(255) NULL AFTER `storage`");
    }
    if (!tallerHasColumn($pdo, $db, 'taller_vehiculo_fotos', 'url')) {
        $pdo->exec("ALTER TABLE `{$db}`.`taller_vehiculo_fotos` ADD COLUMN `url` TEXT NULL AFTER `file_id`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`taller_servicios` (
            `id_servicio` INT NOT NULL AUTO_INCREMENT,
            `servicio` VARCHAR(160) NOT NULL,
            `descripcion` VARCHAR(255) NULL,
            `costo_base` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `duracion_horas` DECIMAL(8,2) NOT NULL DEFAULT 1,
            `activo` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_servicio`),
            INDEX `idx_servicio` (`servicio`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`taller_ordenes` (
            `id_orden` INT NOT NULL AUTO_INCREMENT,
            `nro_ot` VARCHAR(30) NOT NULL,
            `fecha` DATE NOT NULL,
            `id_cliente` INT NOT NULL,
            `id_vehiculo` INT NOT NULL,
            `problema` TEXT NULL,
            `diagnostico` TEXT NULL,
            `estado` VARCHAR(20) NOT NULL DEFAULT 'abierta',
            `prioridad` VARCHAR(20) NOT NULL DEFAULT 'media',
            `km_ingreso` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `fecha_entrega` DATE NULL,
            `total` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `created_by` INT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_orden`),
            UNIQUE KEY `uk_nro_ot` (`nro_ot`),
            INDEX `idx_fecha` (`fecha`),
            INDEX `idx_estado` (`estado`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if (!tallerHasColumn($pdo, $db, 'taller_ordenes', 'is_public')) {
        $pdo->exec("ALTER TABLE `{$db}`.`taller_ordenes` ADD COLUMN `is_public` TINYINT(1) NOT NULL DEFAULT 0 AFTER `total`");
    }
    if (!tallerHasColumn($pdo, $db, 'taller_ordenes', 'public_token')) {
        $pdo->exec("ALTER TABLE `{$db}`.`taller_ordenes` ADD COLUMN `public_token` VARCHAR(64) NULL AFTER `is_public`");
        $pdo->exec("ALTER TABLE `{$db}`.`taller_ordenes` ADD UNIQUE KEY `uk_public_token` (`public_token`)");
    }
    if (!tallerHasColumn($pdo, $db, 'taller_ordenes', 'public_shared_at')) {
        $pdo->exec("ALTER TABLE `{$db}`.`taller_ordenes` ADD COLUMN `public_shared_at` DATETIME NULL AFTER `public_token`");
    }
    if (!tallerHasColumn($pdo, $db, 'taller_ordenes', 'hora_ingreso')) {
        $pdo->exec("ALTER TABLE `{$db}`.`taller_ordenes` ADD COLUMN `hora_ingreso` TIME NULL AFTER `fecha`");
    }
    if (!tallerHasColumn($pdo, $db, 'taller_ordenes', 'fecha_entrega_estimada')) {
        $pdo->exec("ALTER TABLE `{$db}`.`taller_ordenes` ADD COLUMN `fecha_entrega_estimada` DATETIME NULL AFTER `fecha_entrega`");
    }
    if (!tallerHasColumn($pdo, $db, 'taller_ordenes', 'id_mecanico')) {
        $pdo->exec("ALTER TABLE `{$db}`.`taller_ordenes` ADD COLUMN `id_mecanico` INT NULL AFTER `id_vehiculo`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`taller_orden_items` (
            `id_item` INT NOT NULL AUTO_INCREMENT,
            `id_orden` INT NOT NULL,
            `tipo` VARCHAR(20) NOT NULL DEFAULT 'servicio',
            `id_servicio` INT NULL,
            `id_producto` INT NULL,
            `descripcion` VARCHAR(255) NOT NULL,
            `cantidad` DECIMAL(12,2) NOT NULL DEFAULT 1,
            `precio` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (`id_item`),
            INDEX `idx_orden` (`id_orden`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if (!tallerHasColumn($pdo, $db, 'taller_orden_items', 'tipo')) {
        $pdo->exec("ALTER TABLE `{$db}`.`taller_orden_items` ADD COLUMN `tipo` VARCHAR(20) NOT NULL DEFAULT 'servicio' AFTER `id_orden`");
    }
    if (!tallerHasColumn($pdo, $db, 'taller_orden_items', 'id_producto')) {
        $pdo->exec("ALTER TABLE `{$db}`.`taller_orden_items` ADD COLUMN `id_producto` INT NULL AFTER `id_servicio`");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`taller_ot_eventos` (
            `id_evento` INT NOT NULL AUTO_INCREMENT,
            `id_orden` INT NULL,
            `id_vehiculo` INT NOT NULL,
            `chapa` VARCHAR(30) NOT NULL,
            `tipo` VARCHAR(40) NOT NULL DEFAULT 'estado',
            `titulo` VARCHAR(160) NOT NULL,
            `mensaje` TEXT NULL,
            `estado` VARCHAR(40) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_evento`),
            INDEX `idx_chapa_id` (`chapa`, `id_evento`),
            INDEX `idx_vehiculo_evento` (`id_vehiculo`, `id_evento`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`taller_app_devices` (
            `id_device` INT NOT NULL AUTO_INCREMENT,
            `chapa` VARCHAR(30) NOT NULL,
            `device_uuid` VARCHAR(120) NOT NULL,
            `platform` VARCHAR(20) NULL,
            `device_name` VARCHAR(120) NULL,
            `push_token` VARCHAR(255) NULL,
            `last_seen_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_device`),
            UNIQUE KEY `uk_chapa_device` (`chapa`, `device_uuid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `{$db}`.`taller_ot_chat` (
            `id_chat` INT NOT NULL AUTO_INCREMENT,
            `id_orden` INT NOT NULL,
            `sender_type` VARCHAR(20) NOT NULL DEFAULT 'interno',
            `id_login` INT NULL,
            `sender_name` VARCHAR(160) NOT NULL,
            `mensaje` TEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_chat`),
            INDEX `idx_ot_chat` (`id_orden`, `id_chat`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function tallerJsonInput(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $source = $GLOBALS['__taller_raw_input'] ?? file_get_contents('php://input');
    $raw = json_decode((string)$source, true);
    $cached = is_array($raw) ? $raw : [];
    return $cached;
}

function tallerCan(string $perm): void
{
    Permission::requirePermission('app_grid_taller_mantenimiento', $perm);
}

function tallerTableColumns(PDO $pdo, string $db, string $table): array
{
    static $cache = [];
    $key = $db . '.' . $table;
    if (isset($cache[$key])) return $cache[$key];

    try {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tb
        ");
        $stmt->execute([':db' => $db, ':tb' => $table]);
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $cache[$key] = is_array($cols) ? $cols : [];
    } catch (Throwable $e) {
        $cache[$key] = [];
    }
    return $cache[$key];
}

function tallerHasTable(PDO $pdo, string $db, string $table): bool
{
    static $cache = [];
    $key = $db . '.' . $table;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tb
            LIMIT 1
        ");
        $stmt->execute([':db' => $db, ':tb' => $table]);
        $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function tallerHasColumn(PDO $pdo, string $db, string $table, string $column): bool
{
    return in_array($column, tallerTableColumns($pdo, $db, $table), true);
}

function tallerNormalizeChapa(string $value): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)));
}

function tallerFindVehiculoByChapa(PDO $pdo, string $db, string $chapa): ?array
{
    $norm = tallerNormalizeChapa($chapa);
    if ($norm === '') return null;
    $stmt = $pdo->prepare("
        SELECT v.*, c.nombre AS cliente, c.telefono AS telefono_cliente, c.documento AS documento_cliente
        FROM `{$db}`.`taller_vehiculos` v
        LEFT JOIN `{$db}`.`taller_clientes` c ON c.id_cliente = v.id_cliente
        WHERE REPLACE(REPLACE(UPPER(v.chapa), '-', ''), ' ', '') = :chapa
        LIMIT 1
    ");
    $stmt->execute([':chapa' => $norm]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function tallerPushEventoOt(PDO $pdo, string $db, int $idOrden, int $idVehiculo, string $chapa, string $tipo, string $titulo, string $mensaje = '', ?string $estado = null): void
{
    $stmt = $pdo->prepare("
        INSERT INTO `{$db}`.`taller_ot_eventos`
            (id_orden, id_vehiculo, chapa, tipo, titulo, mensaje, estado)
        VALUES
            (:id_orden, :id_vehiculo, :chapa, :tipo, :titulo, :mensaje, :estado)
    ");
    $stmt->execute([
        ':id_orden' => $idOrden > 0 ? $idOrden : null,
        ':id_vehiculo' => $idVehiculo,
        ':chapa' => trim($chapa),
        ':tipo' => trim($tipo) !== '' ? trim($tipo) : 'estado',
        ':titulo' => trim($titulo) !== '' ? trim($titulo) : 'Actualización de OT',
        ':mensaje' => trim($mensaje),
        ':estado' => $estado !== null && trim($estado) !== '' ? trim($estado) : null,
    ]);
}
