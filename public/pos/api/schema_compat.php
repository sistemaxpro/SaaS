<?php
/**
 * Compatibilidad de esquema POS por empresa.
 * Crea columnas faltantes en tablas clave de forma segura (best-effort).
 */

require_once __DIR__ . '/../../shared/schema_module_compat.php';

function ensurePosSchemaCompatibility(PDO $pdo, string $dbName): void
{
    static $done = [];
    $key = strtolower(trim($dbName));
    if ($key === '' || isset($done[$key])) {
        return;
    }
    $done[$key] = true;

    $defs = [
        'extracto_caja' => [
            'medio_cobro' => "VARCHAR(20) NULL",
            'comprobante' => "VARCHAR(20) NULL",
            'tabla_relacion' => "VARCHAR(60) NULL",
            'id_relacion' => "INT NULL"
        ],
        'factura_ventas' => [
            'medio_cobro' => "VARCHAR(20) NULL",
            'forma_pago' => "INT NULL",
            'condicion' => "VARCHAR(20) NULL",
            'pendiente' => "DECIMAL(15,2) NOT NULL DEFAULT 0"
        ],
        'cajas' => [
            'impresora' => "VARCHAR(255) NULL"
        ]
    ];

    foreach ($defs as $table => $cols) {
        if (!tableExistsCompat($pdo, $dbName, $table)) {
            continue;
        }
        foreach ($cols as $col => $definition) {
            ensureColumnCompat($pdo, $dbName, $table, $col, $definition);
        }
    }

    $sourceDb = '';
    try {
        if (class_exists('Database')) {
            $masterPdo = Database::getMasterConnection();
            $empresa = [];
            $idEmpresa = (int)($_SESSION['id_empresa'] ?? 0);
            if ($idEmpresa > 0) {
                $empresa = Database::getEmpresaInfo($idEmpresa) ?: [];
            }
            $sourceDb = sxResolveCompanySourceDbCompat($masterPdo, is_array($empresa) ? $empresa : []);
        }
    } catch (Throwable $e) {
        $sourceDb = '';
    }

    sxEnsureModuleSchemaCompat($pdo, $dbName, ['productos'], $sourceDb);
}

function tableExistsCompat(PDO $pdo, string $dbName, string $table): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES FROM `$dbName` LIKE " . $pdo->quote($table));
        return (bool)($stmt && $stmt->fetch(PDO::FETCH_NUM));
    } catch (Throwable $e) {
        return false;
    }
}

function ensureColumnCompat(PDO $pdo, string $dbName, string $table, string $column, string $definition): void
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$dbName`.`$table` LIKE " . $pdo->quote($column));
        $exists = (bool)($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
        if ($exists) {
            return;
        }
        $pdo->exec("ALTER TABLE `$dbName`.`$table` ADD COLUMN `$column` $definition");
    } catch (Throwable $e) {
        error_log("SchemaCompat: no se pudo agregar $dbName.$table.$column: " . $e->getMessage());
    }
}
