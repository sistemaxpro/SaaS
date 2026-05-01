<?php
/**
 * Auto-compatibilidad de esquema por módulo/empresa.
 * Agrega columnas faltantes de forma idempotente (best-effort).
 */

if (!function_exists('sxEnsureModuleSchemaCompat')) {
    function sxEnsureModuleSchemaCompat(PDO $pdo, string $dbName, array $modules = [], string $sourceDb = ''): void
    {
        static $done = [];
        $db = trim($dbName);
        if ($db === '') return;
        $key = $db . '|' . implode(',', $modules) . '|' . trim($sourceDb);
        if (isset($done[$key])) return;
        $done[$key] = true;

        $modules = array_values(array_unique(array_map('strtolower', $modules)));
        $sourceDb = trim($sourceDb);

        if (in_array('cuentas', $modules, true)) {
            sxEnsureTableLikeCompat($pdo, $db, 'cuentas', $sourceDb);
            // Tabla base de cuentas
            if (!sxTableExistsCompat($pdo, $db, 'cuentas')) {
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.`cuentas` (
                        `id` INT NOT NULL AUTO_INCREMENT,
                        `cuenta` VARCHAR(120) NOT NULL,
                        `fijo` TINYINT(1) NOT NULL DEFAULT 0,
                        `estado` TINYINT(1) NOT NULL DEFAULT 1,
                        `fecha` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                } catch (Throwable $e) {
                    error_log("SchemaCompat módulos: no se pudo crear {$db}.cuentas: " . $e->getMessage());
                }
            }

            sxEnsureColumnCompat($pdo, $db, 'cuentas', 'cuenta', 'VARCHAR(120) NOT NULL');
            sxEnsureColumnCompat($pdo, $db, 'cuentas', 'fijo', 'TINYINT(1) NOT NULL DEFAULT 0');
            sxEnsureColumnCompat($pdo, $db, 'cuentas', 'estado', 'TINYINT(1) NOT NULL DEFAULT 1');
            sxEnsureColumnCompat($pdo, $db, 'cuentas', 'fecha', 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
        }

        if (in_array('contactos', $modules, true)) {
            sxEnsureTableLikeCompat($pdo, $db, 'clientes', $sourceDb);
            // clientes (solo si tabla existe)
            sxEnsureColumnCompat($pdo, $db, 'clientes', 'cuenta', 'INT NULL DEFAULT 3');
            sxEnsureColumnCompat($pdo, $db, 'clientes', 'estado', 'INT NULL DEFAULT 1');
            sxEnsureColumnCompat($pdo, $db, 'clientes', 'numero', 'VARCHAR(60) NULL');
            sxEnsureColumnCompat($pdo, $db, 'clientes', 'nombre', 'VARCHAR(255) NULL');
            sxEnsureColumnCompat($pdo, $db, 'clientes', 'direccion', 'VARCHAR(255) NULL');
            sxEnsureColumnCompat($pdo, $db, 'clientes', 'telefono', 'VARCHAR(60) NULL');
            sxEnsureColumnCompat($pdo, $db, 'clientes', 'email', 'VARCHAR(160) NULL');
            sxEnsureColumnCompat($pdo, $db, 'clientes', 'obs', 'TEXT NULL');
            sxEnsureColumnCompat($pdo, $db, 'clientes', 'llave', 'VARCHAR(160) NULL');
        }

        if (in_array('productos', $modules, true)) {
            sxEnsureTableLikeCompat($pdo, $db, 'tblproductos', $sourceDb);
            sxEnsureTableLikeCompat($pdo, $db, 'producto_imagenes', $sourceDb);
            sxEnsureTableLikeCompat($pdo, $db, 'producto_series', $sourceDb);
            sxEnsureTableLikeCompat($pdo, $db, 'producto_aplicaciones', $sourceDb);
            sxEnsureTableLikeCompat($pdo, $db, 'productos_aplicaciones', $sourceDb);
            // tblproductos (solo si tabla existe)
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'Estado', 'INT NULL DEFAULT 1');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'cve_producto', 'VARCHAR(60) NULL');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'desproducto', 'VARCHAR(255) NULL');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'referencia', 'VARCHAR(255) NULL');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'precio_compra', 'DECIMAL(14,2) NULL DEFAULT 0');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'precio_venta', 'DECIMAL(14,2) NULL DEFAULT 0');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'impuesto', 'DECIMAL(8,2) NULL DEFAULT 10');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'iva', 'INT NULL DEFAULT 1');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'grupo', 'INT NULL DEFAULT 0');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'marca', 'INT NULL DEFAULT 0');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'modelo', 'INT NULL DEFAULT 0');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'stock_minimo', 'DECIMAL(14,3) NULL DEFAULT 0');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'stock_maximo', 'DECIMAL(14,3) NULL DEFAULT 0');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'saldo', 'DECIMAL(14,3) NULL DEFAULT 0');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'controla_stock', 'INT NULL DEFAULT 1');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'edita_precio', 'INT NULL DEFAULT -1');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'vende_sin_stock', 'INT NULL DEFAULT 1');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'editable', 'INT NULL DEFAULT -1');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'usaserial', 'INT NULL DEFAULT -1');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'usavencimiento', 'INT NULL DEFAULT -1');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'obs', 'TEXT NULL');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'tipo', 'INT NULL DEFAULT 1');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'codigo_barra', 'VARCHAR(80) NULL');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'descontinuado', 'INT NULL DEFAULT 0');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'fecha_descontinuado', 'DATETIME NULL');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'motivo_descontinuado', 'VARCHAR(255) NULL');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'foto_url', 'VARCHAR(500) NULL');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'garantia_meses', 'INT NULL DEFAULT 0');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'proveedor_principal', 'VARCHAR(160) NULL');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'ubicacion_fisica', 'VARCHAR(160) NULL');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'condicion_producto', 'VARCHAR(40) NULL');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'capacidad', 'VARCHAR(80) NULL');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'ram', 'VARCHAR(80) NULL');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'compatibilidad', 'TEXT NULL');
            sxEnsureColumnCompat($pdo, $db, 'tblproductos', 'incluye', 'TEXT NULL');

            // Índice FULLTEXT para búsqueda rápida en POS (~5ms vs ~300ms con LIKE)
            try {
                $pdo->exec("ALTER TABLE `{$db}`.`tblproductos` ADD FULLTEXT INDEX `ft_search` (`desproducto`, `cve_producto`, `referencia`)");
            } catch (Throwable $e) { /* ya existe */ }

            // Tabla de imágenes de productos (Google Drive)
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.`producto_imagenes` (
                    `id` INT NOT NULL AUTO_INCREMENT,
                    `idproducto` INT NOT NULL,
                    `drive_file_id` VARCHAR(100) NOT NULL,
                    `url` VARCHAR(500) NOT NULL,
                    `filename` VARCHAR(255) NULL,
                    `orden` INT NOT NULL DEFAULT 1,
                    `principal` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    INDEX `idx_producto` (`idproducto`),
                    INDEX `idx_drive_file` (`drive_file_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } catch (Throwable $e) {
                error_log("SchemaCompat: no se pudo crear {$db}.producto_imagenes: " . $e->getMessage());
            }

            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.`producto_series` (
                    `id` INT NOT NULL AUTO_INCREMENT,
                    `idproducto` INT NOT NULL,
                    `serie` VARCHAR(120) NOT NULL,
                    `tipo` VARCHAR(30) NULL DEFAULT 'SERIAL',
                    `estado` TINYINT(1) NOT NULL DEFAULT 1,
                    `id_sucursal` INT NULL DEFAULT NULL,
                    `fecha_ingreso` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                    `fecha_salida` DATETIME NULL DEFAULT NULL,
                    `id_login` INT NULL DEFAULT NULL,
                    `obs` VARCHAR(255) NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_producto_serie` (`idproducto`, `serie`),
                    KEY `idx_producto_estado` (`idproducto`, `estado`),
                    KEY `idx_serie` (`serie`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } catch (Throwable $e) {
                error_log("SchemaCompat: no se pudo crear {$db}.producto_series: " . $e->getMessage());
            }

            sxEnsureColumnCompat($pdo, $db, 'producto_series', 'tipo', "VARCHAR(30) NULL DEFAULT 'SERIAL'");
            sxEnsureColumnCompat($pdo, $db, 'producto_series', 'estado', 'TINYINT(1) NOT NULL DEFAULT 1');
            sxEnsureColumnCompat($pdo, $db, 'producto_series', 'id_sucursal', 'INT NULL DEFAULT NULL');
            sxEnsureColumnCompat($pdo, $db, 'producto_series', 'fecha_ingreso', 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
            sxEnsureColumnCompat($pdo, $db, 'producto_series', 'fecha_salida', 'DATETIME NULL DEFAULT NULL');
            sxEnsureColumnCompat($pdo, $db, 'producto_series', 'id_login', 'INT NULL DEFAULT NULL');
            sxEnsureColumnCompat($pdo, $db, 'producto_series', 'obs', 'VARCHAR(255) NULL DEFAULT NULL');
            sxEnsureColumnCompat($pdo, $db, 'producto_series', 'id_factura', 'INT NULL DEFAULT NULL');

            $tablaAplicaciones = sxTableExistsCompat($pdo, $db, 'productos_aplicaciones')
                ? 'productos_aplicaciones'
                : (sxTableExistsCompat($pdo, $sourceDb, 'productos_aplicaciones') ? 'productos_aplicaciones' : 'producto_aplicaciones');
            if (!sxTableExistsCompat($pdo, $db, $tablaAplicaciones)) {
                sxEnsureTableLikeCompat($pdo, $db, $tablaAplicaciones, $sourceDb);
            }

            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.`{$tablaAplicaciones}` (
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
            } catch (Throwable $e) {
                error_log("SchemaCompat: no se pudo crear {$db}.{$tablaAplicaciones}: " . $e->getMessage());
            }

            sxEnsureColumnCompat($pdo, $db, $tablaAplicaciones, 'conversion', 'VARCHAR(120) NULL DEFAULT NULL');
            sxEnsureColumnCompat($pdo, $db, $tablaAplicaciones, 'vehiculo_marca', 'VARCHAR(120) NULL DEFAULT NULL');
            sxEnsureColumnCompat($pdo, $db, $tablaAplicaciones, 'vehiculo_modelo', 'VARCHAR(120) NULL DEFAULT NULL');
            sxEnsureColumnCompat($pdo, $db, $tablaAplicaciones, 'anio', 'VARCHAR(40) NULL DEFAULT NULL');
            sxEnsureColumnCompat($pdo, $db, $tablaAplicaciones, 'motor', 'VARCHAR(120) NULL DEFAULT NULL');
            sxEnsureColumnCompat($pdo, $db, $tablaAplicaciones, 'codigo_motor', 'VARCHAR(120) NULL DEFAULT NULL');
            sxEnsureColumnCompat($pdo, $db, $tablaAplicaciones, 'orden', 'INT NOT NULL DEFAULT 1');
            sxEnsureColumnCompat($pdo, $db, $tablaAplicaciones, 'id_login', 'INT NULL DEFAULT NULL');
            sxEnsureColumnCompat($pdo, $db, $tablaAplicaciones, 'created_at', 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
            sxEnsureColumnCompat($pdo, $db, $tablaAplicaciones, 'updated_at', 'DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        }

        if (in_array('estacion', $modules, true)) {
            sxEnsureEstacionSchemaCompat($pdo, $db);
        }

        if (in_array('alquileres', $modules, true)) {
            sxEnsureAlquileresSchemaCompat($pdo, $db);
        }

        if (in_array('taller', $modules, true)) {
            sxEnsureTallerSchemaCompat($pdo, $db);
        }
    }
}

if (!function_exists('sxEnsureEstacionSchemaCompat')) {
    function sxEnsureEstacionSchemaCompat(PDO $pdo, string $db): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`estacion_combustibles` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `nombre` VARCHAR(255) NOT NULL,
              `unidad_medida` ENUM('L', 'm3', 'kg') NOT NULL DEFAULT 'L',
              `precio_venta` DECIMAL(10, 2) NOT NULL DEFAULT 0,
              `precio_costo` DECIMAL(10, 2) NOT NULL DEFAULT 0,
              `color_hex` VARCHAR(7),
              `activo` ENUM('Y', 'N') NOT NULL DEFAULT 'Y',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY `unique_nombre` (`nombre`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        sxSeedDefaultEstacionCombustiblesCompat($pdo, $db);

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`estacion_tanques` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `nombre` VARCHAR(255) NOT NULL,
              `id_combustible` INT NOT NULL,
              `capacidad_litros` DECIMAL(10, 2) NOT NULL,
              `nivel_minimo_alerta` DECIMAL(10, 2) DEFAULT 100,
              `tipo_medicion` ENUM('manual', 'sensor') NOT NULL DEFAULT 'manual',
              `sensor_ip` VARCHAR(15),
              `sensor_puerto` INT,
              `sensor_protocolo` VARCHAR(50) COMMENT 'Ej: Modbus, Veeder-Root',
              `activo` ENUM('Y', 'N') NOT NULL DEFAULT 'Y',
              `id_sucursal` INT,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_combustible` (`id_combustible`),
              KEY `idx_sucursal` (`id_sucursal`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`estacion_lecturas_tanque` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `id_tanque` INT NOT NULL,
              `fecha_hora` DATETIME NOT NULL,
              `litros_medidos` DECIMAL(10, 2) NOT NULL,
              `cms_medidos` INT COMMENT 'Centímetros de altura en el varillado',
              `tipo` ENUM('apertura', 'cierre', 'automatica', 'manual') NOT NULL,
              `registrado_por` VARCHAR(255),
              `observacion` TEXT,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              KEY `idx_tanque` (`id_tanque`),
              KEY `idx_fecha` (`fecha_hora`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`estacion_surtidores` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `nombre` VARCHAR(255) NOT NULL,
              `nro_surtidor` INT NOT NULL,
              `id_sucursal` INT,
              `tipo_control` ENUM('manual', 'automatico') NOT NULL DEFAULT 'manual',
              `controladora_ip` VARCHAR(15),
              `controladora_puerto` INT,
              `controladora_protocolo` VARCHAR(50),
              `activo` ENUM('Y', 'N') NOT NULL DEFAULT 'Y',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_sucursal` (`id_sucursal`),
              UNIQUE KEY `unique_surtidor_sucursal` (`nro_surtidor`, `id_sucursal`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`estacion_picos` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `id_surtidor` INT NOT NULL,
              `nro_pico` INT NOT NULL COMMENT 'Número del pico en el surtidor (1, 2, 3, etc)',
              `nombre` VARCHAR(255),
              `id_combustible` INT NOT NULL,
              `id_tanque` INT NOT NULL,
              `totalizador_actual` DECIMAL(15, 3) DEFAULT 0 COMMENT 'Lectura acumulada del surtidor',
              `activo` ENUM('Y', 'N') NOT NULL DEFAULT 'Y',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_surtidor` (`id_surtidor`),
              KEY `idx_combustible` (`id_combustible`),
              KEY `idx_tanque` (`id_tanque`),
              UNIQUE KEY `unique_pico_surtidor` (`id_surtidor`, `nro_pico`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`estacion_lecturas_surtidor` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `id_pico` INT NOT NULL,
              `fecha_hora` DATETIME NOT NULL,
              `totalizador_litros` DECIMAL(15, 3),
              `totalizador_importe` DECIMAL(15, 2),
              `fuente` ENUM('poll', 'evento') NOT NULL DEFAULT 'poll',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              KEY `idx_pico` (`id_pico`),
              KEY `idx_fecha` (`fecha_hora`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`estacion_precios_historial` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `id_combustible` INT NOT NULL,
              `precio_anterior` DECIMAL(10, 2) NOT NULL,
              `precio_nuevo` DECIMAL(10, 2) NOT NULL,
              `fecha_cambio` DATETIME NOT NULL,
              `id_usuario` INT,
              `motivo` VARCHAR(255),
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              KEY `idx_combustible` (`id_combustible`),
              KEY `idx_fecha` (`fecha_cambio`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`estacion_turnos` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `id_usuario` INT NOT NULL,
              `id_sucursal` INT,
              `fecha_turno` DATE NOT NULL,
              `hora_apertura` DATETIME NOT NULL,
              `hora_cierre` DATETIME,
              `estado` ENUM('abierto', 'cerrado', 'conciliado') NOT NULL DEFAULT 'abierto',
              `efectivo_apertura` DECIMAL(15, 2) NOT NULL DEFAULT 0,
              `efectivo_cierre` DECIMAL(15, 2),
              `observacion_apertura` TEXT,
              `observacion_cierre` TEXT,
              `cerrado_por` VARCHAR(255),
              `conciliado_por` VARCHAR(255),
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_usuario` (`id_usuario`),
              KEY `idx_fecha` (`fecha_turno`),
              KEY `idx_estado` (`estado`),
              UNIQUE KEY `unique_turno_usuario_fecha` (`id_usuario`, `fecha_turno`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`estacion_turno_picos` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `id_turno` INT NOT NULL,
              `id_pico` INT NOT NULL,
              `totalizador_apertura` DECIMAL(15, 3),
              `totalizador_cierre` DECIMAL(15, 3),
              `litros_vendidos_sistema` DECIMAL(10, 2) COMMENT 'Calculado por sistema en cierre',
              `litros_vendidos_pico` DECIMAL(10, 2) COMMENT 'Por diferencia de totalizador',
              `diferencia` DECIMAL(10, 2) COMMENT 'Variación entre sistema y pico',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              KEY `idx_turno` (`id_turno`),
              KEY `idx_pico` (`id_pico`),
              UNIQUE KEY `unique_turno_pico` (`id_turno`, `id_pico`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`estacion_despachos` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `id_turno` INT NOT NULL,
              `id_pico` INT NOT NULL,
              `id_usuario` INT NOT NULL,
              `fecha_hora` DATETIME NOT NULL,
              `litros` DECIMAL(10, 3) NOT NULL,
              `monto_total` DECIMAL(15, 2) NOT NULL,
              `precio_unitario` DECIMAL(10, 2) NOT NULL,
              `id_combustible` INT NOT NULL,
              `modo_registro` ENUM('manual', 'automatico') NOT NULL DEFAULT 'manual',
              `totalizador_inicio` DECIMAL(15, 3),
              `totalizador_fin` DECIMAL(15, 3),
              `id_factura` INT COMMENT 'Referencia a factura SIFEN si aplica',
              `nro_comprobante` VARCHAR(50),
              `id_cliente` INT COMMENT 'Cliente si venta a persona específica',
              `estado` ENUM('registrado', 'facturado', 'anulado') NOT NULL DEFAULT 'registrado',
              `observacion` TEXT,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_turno` (`id_turno`),
              KEY `idx_pico` (`id_pico`),
              KEY `idx_fecha` (`fecha_hora`),
              KEY `idx_estado` (`estado`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`estacion_cierre_playa` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `fecha` DATE NOT NULL,
              `id_sucursal` INT,
              `id_usuario_supervisor` INT,
              `hora_cierre` DATETIME,
              `estado` ENUM('abierto', 'cerrado') NOT NULL DEFAULT 'abierto',
              `total_litros_vendidos` DECIMAL(15, 3),
              `total_importe` DECIMAL(15, 2),
              `diferencia_tanque` TEXT COMMENT 'JSON con diferencias por combustible',
              `observacion` TEXT,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY `idx_fecha` (`fecha`),
              KEY `idx_estado` (`estado`),
              UNIQUE KEY `unique_fecha_sucursal` (`fecha`, `id_sucursal`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`estacion_cierre_playa_detalle` (
              `id` INT AUTO_INCREMENT PRIMARY KEY,
              `id_cierre_playa` INT NOT NULL,
              `id_combustible` INT NOT NULL,
              `litros_apertura_tanque` DECIMAL(10, 2),
              `litros_cierre_tanque` DECIMAL(10, 2),
              `litros_vendidos` DECIMAL(10, 2),
              `diferencia` DECIMAL(10, 2),
              `porcentaje_diferencia` DECIMAL(5, 2),
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              KEY `idx_cierre` (`id_cierre_playa`),
              KEY `idx_combustible` (`id_combustible`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('sxSeedDefaultEstacionCombustiblesCompat')) {
    function sxSeedDefaultEstacionCombustiblesCompat(PDO $pdo, string $db): void
    {
        $defaults = [
            ['Nafta Común', 'L', '#FFD700'],
            ['Nafta Super', 'L', '#FF6347'],
            ['Nafta Premium', 'L', '#1E90FF'],
            ['Diésel/Gas Oil', 'L', '#8B4513'],
            ['GNV (Gas Natural Vehicular)', 'm3', '#32CD32'],
            ['GLP (Gas Licuado de Petróleo)', 'kg', '#FF4500'],
        ];

        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$db}`.`estacion_combustibles`");
            $count = (int)($stmt ? $stmt->fetchColumn() : 0);
            if ($count <= 0) {
                $insert = $pdo->prepare("
                    INSERT INTO `{$db}`.`estacion_combustibles`
                        (nombre, unidad_medida, color_hex, activo)
                    VALUES
                        (?, ?, ?, 'Y')
                    ON DUPLICATE KEY UPDATE
                        activo = 'Y'
                ");

                foreach ($defaults as $item) {
                    $insert->execute($item);
                }
                return;
            }

            $insert = $pdo->prepare("
                INSERT INTO `{$db}`.`estacion_combustibles`
                    (nombre, unidad_medida, color_hex, activo)
                VALUES
                    (?, ?, ?, 'Y')
                ON DUPLICATE KEY UPDATE
                    activo = 'Y'
            ");

            foreach ($defaults as $item) {
                $insert->execute($item);
            }
        } catch (Throwable $e) {
            error_log("SchemaCompat: no se pudo sembrar {$db}.estacion_combustibles: " . $e->getMessage());
        }
    }
}

if (!function_exists('sxEnsureAlquileresSchemaCompat')) {
    function sxEnsureAlquileresSchemaCompat(PDO $pdo, string $db): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`alq_propiedades` (
                `id_propiedad` INT NOT NULL AUTO_INCREMENT,
                `codigo` VARCHAR(40) NOT NULL,
                `nombre` VARCHAR(160) NOT NULL,
                `tipo_inmueble` VARCHAR(60) NOT NULL DEFAULT 'departamento',
                `direccion` VARCHAR(255) NULL,
                `ciudad` VARCHAR(120) NULL,
                `matricula` VARCHAR(80) NULL,
                `padron` VARCHAR(80) NULL,
                `area_m2` DECIMAL(12,2) NULL DEFAULT 0,
                `moneda` VARCHAR(10) NOT NULL DEFAULT 'PYG',
                `canon_mensual` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `deposito_garantia` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `dia_vencimiento` TINYINT NOT NULL DEFAULT 10,
                `mora_diaria` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `iva_incluido` TINYINT(1) NOT NULL DEFAULT 1,
                `estado` VARCHAR(20) NOT NULL DEFAULT 'disponible',
                `observacion` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_propiedad`),
                UNIQUE KEY `uk_alq_propiedad_codigo` (`codigo`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`alq_inquilinos` (
                `id_inquilino` INT NOT NULL AUTO_INCREMENT,
                `tipo_persona` VARCHAR(20) NOT NULL DEFAULT 'fisica',
                `nombre_razon` VARCHAR(180) NOT NULL,
                `documento` VARCHAR(40) NULL,
                `ruc` VARCHAR(30) NULL,
                `telefono` VARCHAR(40) NULL,
                `email` VARCHAR(160) NULL,
                `whatsapp` VARCHAR(40) NULL,
                `domicilio` VARCHAR(255) NULL,
                `consentimiento_whatsapp` TINYINT(1) NOT NULL DEFAULT 0,
                `consentimiento_fecha` DATETIME NULL,
                `observacion` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_inquilino`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`alq_contratos` (
                `id_contrato` INT NOT NULL AUTO_INCREMENT,
                `numero_contrato` VARCHAR(40) NOT NULL,
                `id_propiedad` INT NOT NULL,
                `id_inquilino` INT NOT NULL,
                `fecha_firma` DATE NOT NULL,
                `fecha_inicio` DATE NOT NULL,
                `fecha_fin` DATE NOT NULL,
                `destino_inmueble` VARCHAR(120) NOT NULL DEFAULT 'vivienda',
                `moneda` VARCHAR(10) NOT NULL DEFAULT 'PYG',
                `canon_mensual` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `expensas_mensuales` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `servicios_mensuales` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `deposito_garantia` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `dia_vencimiento` TINYINT NOT NULL DEFAULT 10,
                `mora_fija` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `incremento_tipo` VARCHAR(30) NOT NULL DEFAULT 'manual',
                `incremento_valor` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `ajuste_observacion` VARCHAR(255) NULL,
                `firmado_en` VARCHAR(120) NULL DEFAULT 'Asunción',
                `estado` VARCHAR(20) NOT NULL DEFAULT 'activo',
                `clausulas_json` LONGTEXT NULL,
                `observacion` TEXT NULL,
                `created_by` INT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_contrato`),
                UNIQUE KEY `uk_alq_contrato_numero` (`numero_contrato`),
                KEY `idx_alq_contrato_estado` (`estado`, `fecha_fin`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`alq_facturas` (
                `id_factura` INT NOT NULL AUTO_INCREMENT,
                `id_contrato` INT NOT NULL,
                `periodo` CHAR(7) NOT NULL,
                `fecha_emision` DATE NOT NULL,
                `fecha_vencimiento` DATE NOT NULL,
                `concepto` VARCHAR(255) NOT NULL,
                `monto_alquiler` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `monto_expensas` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `monto_servicios` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `monto_mora` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `monto_descuento` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `total` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `estado` VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                `fe_estado` VARCHAR(20) NOT NULL DEFAULT 'borrador',
                `fe_payload_json` LONGTEXT NULL,
                `fe_cdc` VARCHAR(80) NULL,
                `fe_numero` VARCHAR(60) NULL,
                `observacion` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_factura`),
                UNIQUE KEY `uk_alq_factura_periodo` (`id_contrato`, `periodo`),
                KEY `idx_alq_factura_venc` (`estado`, `fecha_vencimiento`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`alq_gastos_propiedad` (
                `id_gasto` INT NOT NULL AUTO_INCREMENT,
                `id_propiedad` INT NOT NULL,
                `id_contrato` INT NULL,
                `fecha_gasto` DATE NOT NULL,
                `periodo_aplicable` CHAR(7) NOT NULL,
                `categoria` VARCHAR(60) NOT NULL DEFAULT 'mantenimiento',
                `concepto` VARCHAR(180) NOT NULL,
                `proveedor` VARCHAR(160) NULL,
                `comprobante` VARCHAR(80) NULL,
                `moneda` VARCHAR(10) NOT NULL DEFAULT 'PYG',
                `monto` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `pagado_por` VARCHAR(30) NOT NULL DEFAULT 'propietario',
                `trasladar_inquilino` TINYINT(1) NOT NULL DEFAULT 0,
                `estado_facturacion` VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                `id_factura` INT NULL,
                `observacion` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_gasto`),
                KEY `idx_alq_gasto_propiedad` (`id_propiedad`, `periodo_aplicable`),
                KEY `idx_alq_gasto_facturacion` (`trasladar_inquilino`, `estado_facturacion`, `id_contrato`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`alq_avisos` (
                `id_aviso` INT NOT NULL AUTO_INCREMENT,
                `id_contrato` INT NOT NULL,
                `id_factura` INT NULL,
                `tipo` VARCHAR(30) NOT NULL DEFAULT 'vencimiento',
                `canal` VARCHAR(20) NOT NULL DEFAULT 'whatsapp',
                `destinatario` VARCHAR(40) NOT NULL,
                `mensaje` TEXT NOT NULL,
                `programado_para` DATETIME NOT NULL,
                `enviado_en` DATETIME NULL,
                `estado` VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                `provider` VARCHAR(40) NULL,
                `resultado_json` LONGTEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_aviso`),
                KEY `idx_alq_aviso_estado` (`estado`, `programado_para`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`alq_propiedad_imagenes` (
                `id_imagen` INT NOT NULL AUTO_INCREMENT,
                `id_propiedad` INT NOT NULL,
                `origen` VARCHAR(20) NOT NULL DEFAULT 'local',
                `fuente` VARCHAR(40) NULL,
                `titulo` VARCHAR(180) NULL,
                `url_original` VARCHAR(700) NOT NULL,
                `url_preview` VARCHAR(700) NULL,
                `url_cover` VARCHAR(700) NULL,
                `archivo_local` VARCHAR(255) NULL,
                `principal` TINYINT(1) NOT NULL DEFAULT 0,
                `orden` INT NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_imagen`),
                KEY `idx_alq_propiedad_imagen_propiedad` (`id_propiedad`, `principal`, `orden`, `id_imagen`),
                KEY `idx_alq_propiedad_imagen_origen` (`origen`, `fuente`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('sxEnsureTallerSchemaCompat')) {
    function sxEnsureTallerSchemaCompat(PDO $pdo, string $db): void
    {
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
                `mensaje` TEXT NOT NULL,
                `id_usuario` INT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_chat`),
                INDEX `idx_orden_chat` (`id_orden`, `id_chat`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('sxEnsureTableLikeCompat')) {
    function sxEnsureTableLikeCompat(PDO $pdo, string $db, string $table, string $sourceDb = ''): bool
    {
        $db = trim($db);
        $table = trim($table);
        $sourceDb = trim($sourceDb);
        if ($db === '' || $table === '') {
            return false;
        }

        try {
            if (sxTableExistsCompat($pdo, $db, $table)) {
                return true;
            }

            if ($sourceDb === '' || !sxTableExistsCompat($pdo, $sourceDb, $table)) {
                return false;
            }

            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.`{$table}` LIKE `{$sourceDb}`.`{$table}`");
            return sxTableExistsCompat($pdo, $db, $table);
        } catch (Throwable $e) {
            error_log("SchemaCompat módulos: no se pudo clonar {$db}.{$table} desde {$sourceDb}: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sxEnsureCompanyDatabaseCompat')) {
    function sxEnsureCompanyDatabaseCompat(PDO $masterPdo, int $idEmpresa, array $empresa = [], string $preferSource = ''): array
    {
        $dbName = 'empresa_' . $idEmpresa;
        $software = (int)($empresa['software'] ?? 1);
        $sourceDb = sxResolveCompanySourceDbCompat($masterPdo, array_merge($empresa, ['software' => $software]), $preferSource);
        $result = [
            'db_name' => $dbName,
            'db_source' => $sourceDb,
            'db_created' => false,
            'tables_created' => 0,
            'tables_created_list' => [],
            'errors' => [],
        ];

        try {
            $masterPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $result['db_created'] = true;
        } catch (Throwable $e) {
            $result['errors'][] = 'No se pudo crear la BD ' . $dbName . ': ' . $e->getMessage();
            return $result;
        }

        if ($sourceDb === '') {
            $result['errors'][] = 'No se pudo resolver una BD plantilla para ' . $dbName;
            return $result;
        }

        try {
            $stmt = $masterPdo->prepare("
                SELECT TABLE_NAME
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = :db
                  AND TABLE_TYPE = 'BASE TABLE'
                ORDER BY TABLE_NAME
            ");
            $stmt->execute([':db' => $sourceDb]);
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            foreach ($tables as $table) {
                try {
                    $masterPdo->exec("CREATE TABLE IF NOT EXISTS `{$dbName}`.`{$table}` LIKE `{$sourceDb}`.`{$table}`");
                    if (sxTableExistsCompat($masterPdo, $dbName, $table)) {
                        $result['tables_created']++;
                        $result['tables_created_list'][] = $table;
                    }
                } catch (Throwable $e) {
                    $result['errors'][] = "Error creando {$dbName}.{$table}: " . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $result['errors'][] = 'No se pudo copiar la estructura desde ' . $sourceDb . ': ' . $e->getMessage();
        }

        return $result;
    }
}

if (!function_exists('sxResolveExistingSchemaCompat')) {
    function sxResolveExistingSchemaCompat(PDO $pdo, array $candidates): string
    {
        $candidates = array_values(array_unique(array_filter(array_map('trim', $candidates))));
        if (empty($candidates)) {
            return '';
        }

        foreach ($candidates as $dbName) {
            try {
                $stmt = $pdo->prepare("SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db LIMIT 1");
                $stmt->execute([':db' => $dbName]);
                if ($stmt->fetchColumn()) {
                    return $dbName;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return '';
    }
}

if (!function_exists('sxResolveCompanySourceDbCompat')) {
    function sxResolveCompanySourceDbCompat(PDO $masterPdo, array $empresa = [], string $prefer = ''): string
    {
        $software = (int)($empresa['software'] ?? 1);
        $candidates = [];

        if ($prefer !== '') {
            $candidates[] = $prefer;
        }

        $candidates[] = $software === 2 ? 'flota_ovetense' : 'tienda_169';
        $candidates[] = 'empresa_169';
        $candidates[] = 'smx_169';
        $candidates[] = 'tienda_169';
        $candidates[] = 'flota_ovetense';

        return sxResolveExistingSchemaCompat($masterPdo, $candidates);
    }
}

if (!function_exists('sxEnsureBootstrapModuleSchemasCompat')) {
    function sxEnsureBootstrapModuleSchemasCompat(): void
    {
        if (PHP_SAPI === 'cli' || !class_exists('Database') || !class_exists('Session')) {
            return;
        }

        $script = (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
        $script = str_replace('\\', '/', $script);

        $moduleMap = [
            '/public/pos/' => ['productos'],
            '/public/ventas/' => ['productos', 'contactos', 'cuentas'],
            '/public/productos/' => ['productos'],
            '/public/contactos/' => ['contactos', 'cuentas'],
            '/public/cuentas/' => ['cuentas', 'contactos'],
            '/public/taller/' => ['taller'],
            '/public/alquileres/' => ['alquileres'],
            '/public/estacion' => ['estacion'],
        ];

        $modules = [];
        foreach ($moduleMap as $prefix => $candidateModules) {
            if (strpos($script, $prefix) !== false) {
                $modules = array_values(array_unique(array_merge($modules, $candidateModules)));
            }
        }

        if (empty($modules)) {
            return;
        }

        try {
            $idEmpresa = (int)Session::getIdEmpresa();
            if ($idEmpresa <= 0) {
                return;
            }

            $masterPdo = Database::getMasterConnection();
            $empresa = Database::getEmpresaInfo($idEmpresa) ?: [];
            $sourceDb = sxResolveCompanySourceDbCompat($masterPdo, is_array($empresa) ? $empresa : []);
            $empresaConn = Database::getSessionEmpresaConnection();
            $dbName = (string)Session::getDbase();
            if ($dbName === '') {
                return;
            }

            sxEnsureModuleSchemaCompat($empresaConn, $dbName, $modules, $sourceDb);
        } catch (Throwable $e) {
            error_log('SchemaCompat bootstrap: ' . $e->getMessage());
        }
    }
}

if (!function_exists('sxEnsureColumnCompat')) {
    function sxEnsureColumnCompat(PDO $pdo, string $db, string $table, string $column, string $definition): void
    {
        try {
            if (!sxTableExistsCompat($pdo, $db, $table)) return;
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$db}`.`{$table}` LIKE " . $pdo->quote($column));
            $exists = (bool)($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
            if ($exists) return;
            $pdo->exec("ALTER TABLE `{$db}`.`{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (Throwable $e) {
            error_log("SchemaCompat módulos: no se pudo agregar {$db}.{$table}.{$column}: " . $e->getMessage());
        }
    }
}

if (!function_exists('sxTableExistsCompat')) {
    function sxTableExistsCompat(PDO $pdo, string $db, string $table): bool
    {
        try {
            $stmt = $pdo->query("SHOW TABLES FROM `{$db}` LIKE " . $pdo->quote($table));
            return (bool)($stmt && $stmt->fetch(PDO::FETCH_NUM));
        } catch (Throwable $e) {
            return false;
        }
    }
}
