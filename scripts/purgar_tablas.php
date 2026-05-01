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

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../public/shared/schema_module_compat.php';

/**
 * Purgador seguro de tablas.
 *
 * Objetivo:
 * - Detectar tablas que no están cubiertas por el inventario oficial del proyecto.
 * - Por defecto solo reporta (dry-run).
 * - Solo borra si se usa --apply y se confirma o se agrega --force.
 * - El modo seguro por defecto es `prefijo`: solo tablas huérfanas con nombres temporales/derivados.
 *
 * Notas:
 * - No toca la master DB (`serproc1`) salvo que se especifique explícitamente una base distinta.
 * - La decisión de borrado se basa en un allowlist de tablas conocidas por plantilla/módulo.
 * - Se excluyen tablas de sistema y tablas con prefijos claramente temporales por defecto.
 */

$options = getopt('', [
    'db::',
    'all',
    'apply',
    'force',
    'mode::',
    'prefix::',
    'include-temp',
    'show-all',
]);

$targetDb = trim((string)($options['db'] ?? ''));
$apply = array_key_exists('apply', $options);
$force = array_key_exists('force', $options);
$mode = strtolower(trim((string)($options['mode'] ?? 'prefix')));
$customPrefixRaw = trim((string)($options['prefix'] ?? ''));
$includeTemp = array_key_exists('include-temp', $options);
$showAll = array_key_exists('show-all', $options);
$applyAll = array_key_exists('all', $options);

function purgePrint(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function purgeError(string $msg): void
{
    fwrite(STDERR, $msg . PHP_EOL);
}

function purgeIsSystemDb(string $db): bool
{
    return in_array($db, ['information_schema', 'mysql', 'performance_schema', 'sys', 'serproc1'], true);
}

function purgeIsTempTable(string $table): bool
{
    return (bool)preg_match('/(?:^|_)(tmp|temp|bak|backup|old|copy\d*|debug|test)(?:_|$)/i', $table);
}

function purgeCustomPrefixMatches(string $table, array $prefixes): bool
{
    if (empty($prefixes)) {
        return false;
    }

    foreach ($prefixes as $prefix) {
        $prefix = trim((string)$prefix);
        if ($prefix === '') {
            continue;
        }
        if (str_starts_with($table, $prefix)) {
            return true;
        }
    }

    return false;
}

function purgeResolveMasterPdo(): PDO
{
    return Database::getMasterConnection();
}

function purgeGetCompanyRows(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id_empresa, empresa, dbase, software, activo
        FROM empresa
        WHERE dbase IS NOT NULL AND dbase <> ''
        ORDER BY id_empresa ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function purgeGetSchemas(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT SCHEMA_NAME
        FROM information_schema.SCHEMATA
        WHERE SCHEMA_NAME NOT IN ('information_schema', 'mysql', 'performance_schema', 'sys')
        ORDER BY SCHEMA_NAME
    ");
    return array_map(static fn(array $row): string => (string)$row['SCHEMA_NAME'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

function purgeGetTables(PDO $pdo, string $db): array
{
    $stmt = $pdo->prepare("
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = :db
          AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME
    ");
    $stmt->execute([':db' => $db]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function purgeTableExists(PDO $pdo, string $db, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = :db
          AND TABLE_NAME = :table
        LIMIT 1
    ");
    $stmt->execute([':db' => $db, ':table' => $table]);
    return (bool)$stmt->fetchColumn();
}

function purgeBuildAllowlist(PDO $pdo): array
{
    $allowed = [];
    $sourceDbs = ['tienda_169', 'flota_ovetense', 'empresa_169', 'smx_169'];

    foreach ($sourceDbs as $sourceDb) {
        if (!purgeTableExists($pdo, $sourceDb, 'empresa') && !purgeIsSystemDb($sourceDb)) {
            // No-op; solo para mantener la lógica explícita.
        }
        if (!purgeDatabaseExists($pdo, $sourceDb)) {
            continue;
        }
        foreach (purgeGetTables($pdo, $sourceDb) as $table) {
            $allowed[$table] = true;
        }
    }

    $masterTables = ['empresa', 'sec_users', 'sec_users_groups', 'sec_groups', 'sec_groups_apps', 'sec_apps', 'habilitacion_sifen', 'habilitacion_sifen_sucursales', 'habilitacion_sifen_puntos_expedicion', 'habilitacion_sifen_documentos_cajas', 'saas_apps_catalogo', 'saas_suscripcion', 'saas_suscripcion_apps', 'saas_pagos_historial', 'saas_negocio_templates', 'saas_negocios_tipos', 'saas_notificaciones_outbox'];
    foreach ($masterTables as $table) {
        $allowed[$table] = true;
    }

    return array_keys($allowed);
}

function purgeDatabaseExists(PDO $pdo, string $db): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.SCHEMATA
        WHERE SCHEMA_NAME = :db
        LIMIT 1
    ");
    $stmt->execute([':db' => $db]);
    return (bool)$stmt->fetchColumn();
}

function purgeTablesToDrop(PDO $pdo, string $db, array $allowlist, bool $includeTemp): array
{
    $tables = purgeGetTables($pdo, $db);
    $allowSet = array_flip($allowlist);
    $candidates = [];

    foreach ($tables as $table) {
        if (isset($allowSet[$table])) {
            continue;
        }

        if (!$includeTemp && purgeIsTempTable($table)) {
            continue;
        }

        $candidates[] = $table;
    }

    return $candidates;
}

function purgeTablesToDropByMode(PDO $pdo, string $db, array $allowlist, string $mode, array $customPrefixes, bool $includeTemp): array
{
    $candidates = purgeTablesToDrop($pdo, $db, $allowlist, $includeTemp);

    if ($mode === 'all') {
        return $candidates;
    }

    $filtered = [];
    foreach ($candidates as $table) {
        if (purgeIsTempTable($table) || purgeCustomPrefixMatches($table, $customPrefixes)) {
            $filtered[] = $table;
        }
    }

    return $filtered;
}

function purgeAskConfirm(string $db, array $tables): bool
{
    purgePrint('');
    purgePrint("Base: {$db}");
    purgePrint('Tablas candidatas: ' . count($tables));
    purgePrint(implode(', ', array_slice($tables, 0, 40)) . (count($tables) > 40 ? ' ...' : ''));
    purgePrint('');
    purgePrint("Escribe 'SI' para borrar estas tablas.");
    $handle = fopen('php://stdin', 'r');
    if (!$handle) {
        return false;
    }
    $line = trim((string)fgets($handle));
    fclose($handle);
    return strtoupper($line) === 'SI';
}

function purgeFormatTableList(array $tables, bool $showAll): string
{
    if ($showAll || count($tables) <= 20) {
        return implode(', ', $tables);
    }

    return implode(', ', array_slice($tables, 0, 20)) . ' ...';
}

try {
    $pdo = purgeResolveMasterPdo();
    $allowlist = purgeBuildAllowlist($pdo);
    $customPrefixes = array_values(array_filter(array_map('trim', $customPrefixRaw !== '' ? preg_split('/[,\s]+/', $customPrefixRaw) : [])));

    if (!in_array($mode, ['prefix', 'all'], true)) {
        purgeError("Modo inválido: use --mode=prefix o --mode=all");
        exit(1);
    }

    $dbs = [];
    if ($applyAll) {
        $dbs = array_filter(purgeGetSchemas($pdo), static fn(string $db): bool => !purgeIsSystemDb($db));
    } elseif ($targetDb !== '') {
        $dbs = [$targetDb];
    } else {
        $dbs = array_values(array_filter(
            array_map(static fn(array $row): string => (string)$row['dbase'], purgeGetCompanyRows($pdo)),
            static fn(string $db): bool => $db !== ''
        ));
    }

    if (empty($dbs)) {
        purgeError("No se encontraron bases de datos destino.");
        exit(1);
    }

    purgePrint('Purgador de tablas');
    purgePrint('Modo: ' . ($apply ? 'apply' : 'dry-run'));
    purgePrint('Force: ' . ($force ? 'si' : 'no'));
    purgePrint('Mode set: ' . $mode);
    purgePrint('Prefixes: ' . (!empty($customPrefixes) ? implode(', ', $customPrefixes) : '(default temp patterns)'));
    purgePrint('Include temp: ' . ($includeTemp ? 'si' : 'no'));
    purgePrint('Bases a revisar: ' . count($dbs));
    purgePrint('');

    $totalCandidates = 0;
    $totalDropped = 0;
    $report = [];

    foreach ($dbs as $db) {
        if ($db === '' || purgeIsSystemDb($db)) {
            continue;
        }
        if (!purgeDatabaseExists($pdo, $db)) {
            $report[] = "[WARN] {$db}: base no existe";
            continue;
        }

        $candidates = purgeTablesToDropByMode($pdo, $db, $allowlist, $mode, $customPrefixes, $includeTemp);
        $totalCandidates += count($candidates);

        if (empty($candidates)) {
            $report[] = "[OK] {$db}: sin tablas candidatas";
            continue;
        }

        $report[] = "[CAND] {$db}: " . count($candidates) . ' tabla(s) -> ' . purgeFormatTableList($candidates, $showAll);

        if (!$apply) {
            continue;
        }

        if ($mode === 'all' && !$force) {
            $report[] = "[SKIP] {$db}: el modo all requiere --force";
            continue;
        }

        if (!$force && !purgeAskConfirm($db, $candidates)) {
            $report[] = "[SKIP] {$db}: cancelado por el usuario";
            continue;
        }

        foreach ($candidates as $table) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS `{$db}`.`{$table}`");
                $totalDropped++;
            } catch (Throwable $e) {
                $report[] = "[ERR] {$db}.{$table}: " . $e->getMessage();
            }
        }

        $report[] = "[DROP] {$db}: " . count($candidates) . ' tabla(s) eliminadas';
    }

    foreach ($report as $line) {
        purgePrint($line);
    }

    purgePrint('');
    purgePrint('Resumen');
    purgePrint('Tablas candidatas: ' . $totalCandidates);
    purgePrint('Tablas eliminadas: ' . $totalDropped);

    if ($apply && $totalDropped === 0) {
        exit(2);
    }

    exit(0);
} catch (Throwable $e) {
    purgeError('ERROR: ' . $e->getMessage());
    exit(1);
}
