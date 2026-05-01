<?php
declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require_once __DIR__ . '/../public/productos/config/db_config.php';

function usage(): void
{
    fwrite(STDERR, "Uso:\n");
    fwrite(STDERR, "  php scripts/migrate_tblproductos_to_productos_equivalencias.php --empresa=169 [--apply]\n");
    fwrite(STDERR, "  php scripts/migrate_tblproductos_to_productos_equivalencias.php --all [--apply]\n");
    fwrite(STDERR, "\n");
    fwrite(STDERR, "Sin --apply, solo muestra preview.\n");
}

function ensureProductosEquivalenciasTable(PDO $pdo, string $db): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.`productos_equivalencias` (
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
}

$empresaIds = [];
$applyAll = false;
$apply = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--all') {
        $applyAll = true;
        continue;
    }
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }
    if (str_starts_with($arg, '--empresa=')) {
        $id = (int)substr($arg, strlen('--empresa='));
        if ($id > 0) {
            $empresaIds[] = $id;
        }
    }
}

$master = getMasterConnection();

if ($applyAll) {
    $stmt = $master->query("SELECT id_empresa FROM empresa WHERE id_empresa > 0 ORDER BY id_empresa");
    $empresaIds = array_map(static fn(array $row): int => (int)$row['id_empresa'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

$empresaIds = array_values(array_unique(array_filter($empresaIds)));

if (empty($empresaIds)) {
    usage();
    exit(1);
}

$ok = 0;
$fail = 0;

foreach ($empresaIds as $idEmpresa) {
    try {
        $conn = getEmpresaConnection($idEmpresa);
        $pdo = $conn['pdo'];
        $db = $conn['dbName'];

        ensureProductosEquivalenciasTable($pdo, $db);

        $previewSql = "
            SELECT
                p.idproducto,
                TRIM(COALESCE(p.cve_producto, '')) AS cve_producto,
                TRIM(COALESCE(p.referencia, '')) AS referencia,
                TRIM(COALESCE(m.marca, '')) AS marca_cod_conversion
            FROM {$db}.tblproductos p
            LEFT JOIN {$db}.mercaderia_marca m ON m.id = p.marca
            LEFT JOIN {$db}.productos_equivalencias e
                ON e.idproducto = p.idproducto
               AND TRIM(COALESCE(e.conversion, '')) = TRIM(COALESCE(p.referencia, ''))
               AND TRIM(COALESCE(e.marca_cod_conversion, '')) = TRIM(COALESCE(m.marca, ''))
            WHERE NULLIF(TRIM(COALESCE(p.referencia, '')), '') IS NOT NULL
              AND e.id IS NULL
            ORDER BY p.idproducto
        ";

        $rows = $pdo->query($previewSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $count = count($rows);

        echo "[EMPRESA {$idEmpresa}] DB {$db}: {$count} filas candidatas\n";

        foreach (array_slice($rows, 0, 10) as $row) {
            echo sprintf(
                "  - idproducto=%d cve_producto=%s referencia=%s marca=%s\n",
                (int)$row['idproducto'],
                $row['cve_producto'] !== '' ? $row['cve_producto'] : '(vacio)',
                $row['referencia'] !== '' ? $row['referencia'] : '(vacio)',
                $row['marca_cod_conversion'] !== '' ? $row['marca_cod_conversion'] : '(vacio)'
            );
        }
        if ($count > 10) {
            echo "  ... y " . ($count - 10) . " mas\n";
        }

        if ($apply && $count > 0) {
            $pdo->beginTransaction();
            $insertSql = "
                INSERT INTO {$db}.productos_equivalencias
                    (idproducto, marca_cod_conversion, conversion, orden, id_login)
                SELECT
                    p.idproducto,
                    NULLIF(TRIM(COALESCE(m.marca, '')), '') AS marca_cod_conversion,
                    TRIM(COALESCE(p.referencia, '')) AS conversion,
                    1 AS orden,
                    NULL AS id_login
                FROM {$db}.tblproductos p
                LEFT JOIN {$db}.mercaderia_marca m ON m.id = p.marca
                LEFT JOIN {$db}.productos_equivalencias e
                    ON e.idproducto = p.idproducto
                   AND TRIM(COALESCE(e.conversion, '')) = TRIM(COALESCE(p.referencia, ''))
                   AND TRIM(COALESCE(e.marca_cod_conversion, '')) = TRIM(COALESCE(m.marca, ''))
                WHERE NULLIF(TRIM(COALESCE(p.referencia, '')), '') IS NOT NULL
                  AND e.id IS NULL
            ";
            $inserted = $pdo->exec($insertSql);
            $pdo->commit();
            echo "  [APPLY] insertadas: " . (int)$inserted . "\n";
        } elseif ($apply) {
            echo "  [APPLY] sin filas nuevas\n";
        } else {
            echo "  [PREVIEW] sin cambios\n";
        }

        $ok++;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "[ERROR] Empresa {$idEmpresa}: {$e->getMessage()}\n");
        $fail++;
    }
}

echo "Resultado: {$ok} ok, {$fail} con error\n";
exit($fail > 0 ? 2 : 0);
