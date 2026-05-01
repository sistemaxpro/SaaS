#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta por CLI.\n");
    exit(1);
}

if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'CLI';
}

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../public/pos/config/db_config.php';
require_once __DIR__ . '/../src/Services/R2StorageService.php';
require_once __DIR__ . '/../src/Services/ImageVariantService.php';

$options = getopt('', ['id_empresa:', 'limit::', 'dry-run']);
$idEmpresa = isset($options['id_empresa']) ? (int)$options['id_empresa'] : 0;
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 0;
$dryRun = array_key_exists('dry-run', $options);

if ($idEmpresa <= 0) {
    fwrite(STDERR, "Uso: php scripts/migrate_product_images_to_r2.php --id_empresa=169 [--limit=500] [--dry-run]\n");
    exit(1);
}

if (!R2StorageService::isConfigured()) {
    fwrite(STDERR, "R2 no configurado. Defina variables R2_* antes de ejecutar.\n");
    exit(1);
}

$conn = getEmpresaConnection($idEmpresa);
$pdo = $conn['pdo'];
$db = $conn['dbName'];
$r2 = new R2StorageService();

$localDir = __DIR__ . '/../public/_lib/file/img/productos/' . $db;
if (!is_dir($localDir)) {
    fwrite(STDERR, "No existe directorio local de imágenes: {$localDir}\n");
    exit(1);
}

ensureProductoImagenesTable($pdo, $db);

$files = glob($localDir . '/*_*.*') ?: [];
sort($files, SORT_STRING);

$grouped = [];
foreach ($files as $file) {
    if (!is_file($file)) {
        continue;
    }
    $name = basename($file);
    if (!preg_match('/^(\d+)_([0-9]+)\./', $name, $m)) {
        continue;
    }
    $idProducto = (int)$m[1];
    $orden = (int)$m[2];
    if ($idProducto <= 0) {
        continue;
    }
    if (!isset($grouped[$idProducto])) {
        $grouped[$idProducto] = [];
    }
    $grouped[$idProducto][] = ['path' => $file, 'name' => $name, 'orden' => $orden];
}

foreach ($grouped as &$items) {
    usort($items, static fn(array $a, array $b) => $a['orden'] <=> $b['orden']);
}
unset($items);

if ($limit > 0) {
    $grouped = array_slice($grouped, 0, $limit, true);
}

$totalProducts = count($grouped);
$totalUploaded = 0;
$productsOk = 0;
$productsFail = 0;

fwrite(STDOUT, "Empresa {$idEmpresa} ({$db})\n");
fwrite(STDOUT, "Productos a migrar: {$totalProducts}" . ($dryRun ? " (dry-run)" : '') . "\n\n");

foreach ($grouped as $idProducto => $images) {
    try {
        $stmtProd = $pdo->prepare("SELECT idproducto FROM {$db}.tblproductos WHERE idproducto = :id LIMIT 1");
        $stmtProd->execute([':id' => $idProducto]);
        if (!$stmtProd->fetch(PDO::FETCH_ASSOC)) {
            fwrite(STDOUT, "[SKIP] Producto {$idProducto} no existe en tblproductos\n");
            continue;
        }

        if ($dryRun) {
            fwrite(STDOUT, "[DRY] Producto {$idProducto}: " . count($images) . " imagen(es)\n");
            continue;
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM {$db}.producto_imagenes WHERE idproducto = :id")
            ->execute([':id' => $idProducto]);

        $principalUrl = null;
        $ordenOut = 1;

        foreach ($images as $img) {
            $path = (string)$img['path'];
            $name = (string)$img['name'];
            $binary = (string)file_get_contents($path);
            if ($binary === '') {
                throw new RuntimeException("No se pudo leer {$name}");
            }
            $baseKey = buildMigratedBaseKey($idEmpresa, $db, (int)$idProducto, $ordenOut);
            $variantBin = ImageVariantService::generateWebpVariantsFromBinary($binary);
            $variantUrls = [];
            foreach ($variantBin as $variantName => $variantData) {
                $key = ImageVariantService::r2VariantKey($baseKey, $variantName);
                $upload = $r2->putObject($key, (string)$variantData['binary'], 'image/webp', [
                    'cache-control' => 'public, max-age=31536000, immutable',
                ]);
                $variantUrls[$variantName] = (string)$upload['url'];
            }

            $isPrincipal = ($ordenOut === 1) ? 1 : 0;
            if ($isPrincipal === 1) {
                $principalUrl = (string)($variantUrls['medium'] ?? ($variantUrls['large'] ?? ''));
            }

            $stmtIns = $pdo->prepare("INSERT INTO {$db}.producto_imagenes (idproducto, drive_file_id, url, filename, orden, principal, created_at)
                VALUES (:idproducto, :file_id, :url, :filename, :orden, :principal, NOW())");
            $stmtIns->execute([
                ':idproducto' => $idProducto,
                ':file_id' => 'r2:' . $baseKey,
                ':url' => (string)($variantUrls['medium'] ?? ($variantUrls['large'] ?? '')),
                ':filename' => $name,
                ':orden' => $ordenOut,
                ':principal' => $isPrincipal,
            ]);

            $ordenOut++;
            $totalUploaded++;
        }

        updateFotoUrl($pdo, $db, (int)$idProducto, $principalUrl);

        $pdo->commit();
        $productsOk++;
        fwrite(STDOUT, "[OK] Producto {$idProducto}: " . count($images) . " imagen(es)\n");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $productsFail++;
        fwrite(STDOUT, "[FAIL] Producto {$idProducto}: {$e->getMessage()}\n");
    }
}

fwrite(STDOUT, "\nResumen:\n");
fwrite(STDOUT, "- Productos OK: {$productsOk}\n");
fwrite(STDOUT, "- Productos con error: {$productsFail}\n");
fwrite(STDOUT, "- Imágenes subidas: {$totalUploaded}\n");

exit(0);

function ensureProductoImagenesTable(PDO $pdo, string $db): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$db}`.`producto_imagenes` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `idproducto` INT NOT NULL,
        `drive_file_id` VARCHAR(255) NOT NULL,
        `url` VARCHAR(500) NOT NULL,
        `filename` VARCHAR(255) NULL,
        `orden` INT NOT NULL DEFAULT 1,
        `principal` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_producto` (`idproducto`),
        INDEX `idx_drive_file` (`drive_file_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function updateFotoUrl(PDO $pdo, string $db, int $idProducto, ?string $url): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$db}`.`tblproductos` LIKE 'foto_url'");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
        return;
    }

    $upd = $pdo->prepare("UPDATE {$db}.tblproductos SET foto_url = :url WHERE idproducto = :id");
    $upd->execute([
        ':url' => $url,
        ':id' => $idProducto,
    ]);
}

function buildMigratedBaseKey(int $idEmpresa, string $db, int $idProducto, int $orden): string
{
    $dbSafe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $db);
    return 'e' . $idEmpresa . '/p/' . $dbSafe . '/' . $idProducto . '/migrated_' . $orden;
}
