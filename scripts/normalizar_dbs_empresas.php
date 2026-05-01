#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta por CLI.\n");
    exit(1);
}

if (!defined('SISTEMAX_V1')) {
    define('SISTEMAX_V1', true);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../public/shared/schema_module_compat.php';

$options = getopt('', [
    'empresa::',
    'solo-activas::',
    'apply',
    'sync-master',
    'no-sync-master',
    'migrate-data',
    'no-migrate-data',
    'source::',
]);

$empresaFiltro = isset($options['empresa']) ? (int)$options['empresa'] : 0;
$soloActivas = !array_key_exists('solo-activas', $options) || filter_var($options['solo-activas'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
$apply = array_key_exists('apply', $options);
$syncMaster = array_key_exists('sync-master', $options) || ($apply && !array_key_exists('no-sync-master', $options));
$migrateData = array_key_exists('migrate-data', $options) || ($apply && !array_key_exists('no-migrate-data', $options));
$sourceOverride = trim((string)($options['source'] ?? ''));

function normPrint(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function normSchemaExists(PDO $pdo, string $schema): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.SCHEMATA
        WHERE SCHEMA_NAME = :schema
        LIMIT 1
    ");
    $stmt->execute([':schema' => $schema]);
    return (bool)$stmt->fetchColumn();
}

function normGetBaseTables(PDO $pdo, string $schema): array
{
    $stmt = $pdo->prepare("
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME
    ");
    $stmt->execute([':schema' => $schema]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function normTableCount(PDO $pdo, string $schema, string $table): int
{
    try {
        $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM `{$schema}`.`{$table}`");
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return -1;
    }
}

function normCopyLegacyDatabase(PDO $pdo, string $legacyDb, string $targetDb, array &$log): void
{
    if ($legacyDb === '' || $legacyDb === $targetDb || !normSchemaExists($pdo, $legacyDb)) {
        return;
    }

    $tables = normGetBaseTables($pdo, $legacyDb);
    if (empty($tables)) {
        return;
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($tables as $table) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS `{$targetDb}`.`{$table}` LIKE `{$legacyDb}`.`{$table}`");
                $destCount = normTableCount($pdo, $targetDb, $table);
                if ($destCount === 0) {
                    $pdo->exec("INSERT INTO `{$targetDb}`.`{$table}` SELECT * FROM `{$legacyDb}`.`{$table}`");
                    $log[] = "datos copiados {$legacyDb}.{$table}";
                } else {
                    $log[] = "tabla existente {$targetDb}.{$table} (filas={$destCount})";
                }
            } catch (Throwable $e) {
                $log[] = "error {$table}: " . $e->getMessage();
            }
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}

function normSyncMasterDbase(PDO $master, int $idEmpresa, string $dbName): void
{
    $stmt = $master->prepare("UPDATE empresa SET dbase = :dbase WHERE id_empresa = :id");
    $stmt->execute([
        ':dbase' => $dbName,
        ':id' => $idEmpresa,
    ]);
}

try {
    $master = Database::getMasterConnection();

    $sql = "
        SELECT id_empresa, empresa, dbase, software, activo
        FROM empresa
        WHERE 1=1
    ";
    $params = [];
    if ($empresaFiltro > 0) {
        $sql .= ' AND id_empresa = :id_empresa';
        $params[':id_empresa'] = $empresaFiltro;
    }
    if ($soloActivas) {
        $sql .= ' AND activo = 1';
    }
    $sql .= ' ORDER BY id_empresa ASC';

    $stmt = $master->prepare($sql);
    $stmt->execute($params);
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    normPrint('NORMALIZADOR DE BASES DE EMPRESAS');
    normPrint('Modo: ' . ($apply ? 'apply' : 'dry-run'));
    normPrint('Sincronizar master dbase: ' . ($syncMaster ? 'si' : 'no'));
    normPrint('Migrar datos legacy: ' . ($migrateData ? 'si' : 'no'));
    normPrint('Solo activas: ' . ($soloActivas ? 'si' : 'no'));
    normPrint('Empresas encontradas: ' . count($companies));

    if (empty($companies)) {
        exit(0);
    }

    $summary = [
        'companies' => 0,
        'to_fix' => 0,
        'created' => 0,
        'synced' => 0,
        'legacy_data_copied' => 0,
        'errors' => 0,
    ];

    foreach ($companies as $company) {
        $summary['companies']++;
        $idEmpresa = (int)($company['id_empresa'] ?? 0);
        $name = trim((string)($company['empresa'] ?? ''));
        $currentDb = trim((string)($company['dbase'] ?? ''));
        $expectedDb = 'empresa_' . $idEmpresa;
        $software = (int)($company['software'] ?? 1);
        $needsFix = $currentDb !== $expectedDb;

        normPrint('');
        normPrint("[{$idEmpresa}] " . ($name !== '' ? $name : 'Sin nombre'));
        normPrint("  actual: " . ($currentDb !== '' ? $currentDb : '(vacía)'));
        normPrint("  esperado: {$expectedDb}");
        normPrint("  software: {$software}");
        normPrint('  accion: ' . ($needsFix ? 'normalizar' : 'verificar'));

        if (!$needsFix && !$apply) {
            continue;
        }

        if ($needsFix) {
            $summary['to_fix']++;
        }

        $empresaInfo = Database::getEmpresaInfo($idEmpresa) ?: [];
        $empresaInfo['software'] = $software;
        $sourceDb = sxResolveCompanySourceDbCompat($master, $empresaInfo, $sourceOverride);
        if ($sourceDb === '') {
            $summary['errors']++;
            normPrint('  error: no se pudo resolver una BD fuente');
            continue;
        }

        if (!$apply) {
            normPrint("  fuente: {$sourceDb}");
            continue;
        }

        try {
            $result = sxEnsureCompanyDatabaseCompat($master, $idEmpresa, $empresaInfo, $sourceOverride);
            $legacyLog = [];

            if ($migrateData && $currentDb !== '' && $currentDb !== $expectedDb) {
                normCopyLegacyDatabase($master, $currentDb, $expectedDb, $legacyLog);
                if (!empty($legacyLog)) {
                    $summary['legacy_data_copied'] += count(array_filter($legacyLog, static fn(string $line): bool => str_starts_with($line, 'datos copiados ')));
                    normPrint('  legado: ' . implode(' | ', $legacyLog));
                }
            }

            if ($syncMaster) {
                normSyncMasterDbase($master, $idEmpresa, $expectedDb);
                $summary['synced']++;
            }

            $summary['created'] += (int)($result['db_created'] ?? false);
            normPrint('  db: ' . ($result['db_name'] ?? $expectedDb));
            normPrint('  fuente: ' . ($result['db_source'] ?? $sourceDb));
            normPrint('  tablas clonadas: ' . (int)($result['tables_created'] ?? 0));
            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    normPrint('  warn: ' . $error);
                }
            }
        } catch (Throwable $e) {
            $summary['errors']++;
            normPrint('  error: ' . $e->getMessage());
        }
    }

    normPrint('');
    normPrint('RESUMEN');
    normPrint('Empresas procesadas: ' . $summary['companies']);
    normPrint('Empresas a normalizar: ' . $summary['to_fix']);
    normPrint('Bases creadas/verificadas: ' . $summary['created']);
    normPrint('Master dbase sincronizados: ' . $summary['synced']);
    normPrint('Datos legacy copiados: ' . $summary['legacy_data_copied']);
    normPrint('Errores: ' . $summary['errors']);

    exit($summary['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, '❌ Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
