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

$opts = getopt('', ['id_empresa:', 'limit::', 'dry-run']);
$idEmpresa = isset($opts['id_empresa']) ? (int)$opts['id_empresa'] : 0;
$limit = isset($opts['limit']) ? max(1, (int)$opts['limit']) : 0;
$dryRun = array_key_exists('dry-run', $opts);

if ($idEmpresa <= 0) {
    fwrite(STDERR, "Uso: php scripts/repair_r2_missing_variants.php --id_empresa=169 [--limit=500] [--dry-run]\n");
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

$sql = "SELECT id, idproducto, drive_file_id, url, principal
        FROM {$db}.producto_imagenes
        WHERE drive_file_id LIKE 'r2:%'
        ORDER BY id ASC";
if ($limit > 0) {
    $sql .= " LIMIT " . $limit;
}
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$checked = 0;
$needRepair = 0;
$repaired = 0;
$failed = 0;
$skippedLegacy = 0;

fwrite(STDOUT, "Empresa {$idEmpresa} ({$db})\n");
fwrite(STDOUT, "Registros a evaluar: " . count($rows) . ($dryRun ? " (dry-run)" : '') . "\n\n");

foreach ($rows as $row) {
    $checked++;
    $idProducto = (int)($row['idproducto'] ?? 0);
    $fileId = (string)($row['drive_file_id'] ?? '');
    $rowUrl = (string)($row['url'] ?? '');
    $baseKey = parseR2BaseFromFileId($fileId);

    if ($baseKey === null) {
        $skippedLegacy++;
        fwrite(STDOUT, "[SKIP] id={$row['id']} p={$idProducto} file_id legacy/no-base\n");
        continue;
    }

    $variants = ImageVariantService::deriveVariantUrls($fileId, $rowUrl);
    if (empty($variants)) {
        $failed++;
        fwrite(STDOUT, "[FAIL] id={$row['id']} p={$idProducto} sin URLs de variantes\n");
        continue;
    }

    $missing = [];
    foreach (array_keys(ImageVariantService::sizes()) as $name) {
        $u = (string)($variants[$name] ?? '');
        if ($u === '' || !remoteExists($u)) {
            $missing[] = $name;
        }
    }

    if (empty($missing)) {
        continue;
    }

    $needRepair++;
    $sourceBinary = '';
    $sourceName = '';
    foreach (['large', 'medium', 'small', 'thumb'] as $candidate) {
        $u = (string)($variants[$candidate] ?? '');
        if ($u === '') {
            continue;
        }
        $bin = downloadBinary($u);
        if ($bin !== '') {
            $sourceBinary = $bin;
            $sourceName = $candidate;
            break;
        }
    }

    if ($sourceBinary === '') {
        $failed++;
        fwrite(STDOUT, "[FAIL] id={$row['id']} p={$idProducto} faltan [" . implode(',', $missing) . "] y no hay fuente descargable\n");
        continue;
    }

    if ($dryRun) {
        fwrite(STDOUT, "[DRY] id={$row['id']} p={$idProducto} faltan [" . implode(',', $missing) . "] fuente={$sourceName}\n");
        continue;
    }

    try {
        $generated = ImageVariantService::generateWebpVariantsFromBinary($sourceBinary);
        foreach ($missing as $variantName) {
            if (!isset($generated[$variantName])) {
                continue;
            }
            $key = ImageVariantService::r2VariantKey($baseKey, $variantName);
            $r2->putObject($key, (string)$generated[$variantName]['binary'], 'image/webp', [
                'cache-control' => 'public, max-age=31536000, immutable',
            ]);
        }

        // Mantener url de registro apuntando a medium para estandarizar.
        $mediumUrl = (string)($variants['medium'] ?? '');
        if ($mediumUrl !== '') {
            $pdo->prepare("UPDATE {$db}.producto_imagenes SET url = :u WHERE id = :id")
                ->execute([':u' => $mediumUrl, ':id' => (int)$row['id']]);
            if ((int)($row['principal'] ?? 0) === 1 && hasFotoUrlColumn($pdo, $db)) {
                $pdo->prepare("UPDATE {$db}.tblproductos SET foto_url = :u WHERE idproducto = :p")
                    ->execute([':u' => $mediumUrl, ':p' => $idProducto]);
            }
        }

        $repaired++;
        fwrite(STDOUT, "[OK] id={$row['id']} p={$idProducto} reparadas [" . implode(',', $missing) . "]\n");
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDOUT, "[FAIL] id={$row['id']} p={$idProducto} " . $e->getMessage() . "\n");
    }
}

fwrite(STDOUT, "\nResumen:\n");
fwrite(STDOUT, "- Evaluados: {$checked}\n");
fwrite(STDOUT, "- Con faltantes: {$needRepair}\n");
fwrite(STDOUT, "- Reparados: {$repaired}\n");
fwrite(STDOUT, "- Fallidos: {$failed}\n");
fwrite(STDOUT, "- Legacy/omitidos: {$skippedLegacy}\n");

exit(0);

function parseR2BaseFromFileId(string $fileId): ?string
{
    $fileId = trim($fileId);
    if ($fileId === '' || !str_starts_with($fileId, 'r2:')) {
        return null;
    }
    $key = ltrim(substr($fileId, 3), '/');
    if ($key === '') {
        return null;
    }
    if (preg_match('/_(thumb|small|medium|large)\.webp$/i', $key)) {
        return (string)preg_replace('/_(thumb|small|medium|large)\.webp$/i', '', $key);
    }
    if (preg_match('/\.webp$/i', $key)) {
        // formato legacy r2:path/principal.webp -> no tenemos base confiable.
        return null;
    }
    return $key;
}

function remoteExists(string $url): bool
{
    $ch = curl_init($url);
    if ($ch === false) {
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'SistemaX-R2-Repair/1.0',
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function downloadBinary(string $url): string
{
    $ch = curl_init($url);
    if ($ch === false) {
        return '';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'SistemaX-R2-Repair/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300 || !is_string($body) || $body === '') {
        return '';
    }
    return $body;
}

function hasFotoUrlColumn(PDO $pdo, string $db): bool
{
    static $cache = [];
    $k = $db . '.tblproductos.foto_url';
    if (isset($cache[$k])) {
        return $cache[$k];
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$db}`.`tblproductos` LIKE 'foto_url'");
        $cache[$k] = (bool)($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        $cache[$k] = false;
    }
    return $cache[$k];
}

