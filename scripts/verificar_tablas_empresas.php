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

/**
 * Verifica que las tablas esperadas existan en las bases de datos de empresas registradas.
 *
 * Estrategia:
 * - Lee las empresas desde la BD maestra.
 * - Resuelve la base plantilla según el campo software.
 * - Compara las tablas base de la plantilla contra la BD de la empresa.
 * - Reporta tablas faltantes por empresa.
 */

$options = getopt('', [
    'empresa::',
    'db-source::',
    'solo-activas::',
    'mostrar-tablas::',
]);

$empresaFiltro = isset($options['empresa']) ? (int)$options['empresa'] : 0;
$dbSourceOverride = trim((string)($options['db-source'] ?? ''));
$soloActivas = !array_key_exists('solo-activas', $options) || filter_var($options['solo-activas'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
$mostrarTablas = array_key_exists('mostrar-tablas', $options);

function resolveMasterConfigForCli(): array
{
    $strictEnv = in_array(strtolower(trim((string)(getenv('SISTEMAX_MASTER_DB_STRICT_ENV') ?: ''))), ['1', 'true', 'yes', 'on'], true);
    $defaultDatabase = 'serproc1';

    return [
        'host' => (string)(getenv('SISTEMAX_MASTER_DB_HOST') ?: ($strictEnv ? '' : '168.231.95.50')),
        'port' => (int)(getenv('SISTEMAX_MASTER_DB_PORT') ?: ($strictEnv ? 0 : 3306)),
        'database' => $defaultDatabase,
        'username' => (string)(getenv('SISTEMAX_MASTER_DB_USER') ?: ($strictEnv ? '' : 'sistemax')),
        'password' => (string)(getenv('SISTEMAX_MASTER_DB_PASS') ?: ($strictEnv ? '' : 'Armagedon123')),
        'charset' => (string)(getenv('SISTEMAX_MASTER_DB_CHARSET') ?: 'utf8mb4'),
    ];
}

function pdoFromConfig(array $cfg): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['database'],
        $cfg['charset']
    );

    return new PDO(
        $dsn,
        $cfg['username'],
        $cfg['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        ]
    );
}

function normalizeTableName(string $raw): string
{
    $raw = trim($raw);
    $raw = trim($raw, "`\"' ");
    if ($raw === '') {
        return '';
    }

    $raw = preg_replace('/[^A-Za-z0-9_`\.]/', '', $raw) ?? $raw;

    if (str_contains($raw, '.')) {
        $parts = explode('.', $raw);
        $raw = end($parts) ?: '';
    }

    $raw = trim($raw, '`');
    return preg_match('/^[A-Za-z0-9_]+$/', $raw) === 1 ? $raw : '';
}

function resolveSourceDb(PDO $pdo, int $software, string $override = ''): string
{
    $candidatos = [];

    if ($override !== '') {
        $candidatos[] = $override;
    }

    $candidatos[] = $software === 2 ? 'flota_ovetense' : 'tienda_169';
    $candidatos[] = 'empresa_169';
    $candidatos[] = 'smx_169';
    $candidatos[] = 'tienda_169';
    $candidatos[] = 'flota_ovetense';

    $candidatos = array_values(array_unique(array_filter($candidatos)));

    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db LIMIT 1');
    foreach ($candidatos as $dbName) {
        $stmt->execute([':db' => $dbName]);
        if ($stmt->fetchColumn()) {
            return $dbName;
        }
    }

    return '';
}

function getBaseTables(PDO $pdo, string $dbName): array
{
    $stmt = $pdo->prepare("
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = :db
          AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME
    ");
    $stmt->execute([':db' => $dbName]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function schemaExists(PDO $pdo, string $dbName): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.SCHEMATA
        WHERE SCHEMA_NAME = :db
        LIMIT 1
    ");
    $stmt->execute([':db' => $dbName]);
    return (bool)$stmt->fetchColumn();
}

function getCompanySchemas(PDO $pdo, int $empresaFiltro, bool $soloActivas): array
{
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

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tableExists(PDO $pdo, string $schema, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = :table
        LIMIT 1
    ");
    $stmt->execute([
        ':schema' => $schema,
        ':table' => $table,
    ]);

    return (bool)$stmt->fetchColumn();
}

function printHeader(string $title): void
{
    echo PHP_EOL;
    echo str_repeat('=', 80) . PHP_EOL;
    echo $title . PHP_EOL;
    echo str_repeat('=', 80) . PHP_EOL;
}

try {
    $masterCfg = resolveMasterConfigForCli();
    $pdo = pdoFromConfig($masterCfg);

    $masterDb = $masterCfg['database'];
    $companies = getCompanySchemas($pdo, $empresaFiltro, $soloActivas);

    if (empty($companies)) {
        printHeader('Verificación de tablas por empresa');
        echo "No se encontraron empresas registradas con los filtros indicados." . PHP_EOL;
        exit(0);
    }

    $summary = [
        'companies' => 0,
        'ok' => 0,
        'warnings' => 0,
        'missing_total' => 0,
    ];

    printHeader('Verificación de tablas por empresa');
    echo "BD maestra: {$masterDb}" . PHP_EOL;
    echo 'Empresas a revisar: ' . count($companies) . PHP_EOL;
    echo 'Filtro empresa: ' . ($empresaFiltro > 0 ? (string)$empresaFiltro : 'ninguno') . PHP_EOL;
    echo 'Solo activas: ' . ($soloActivas ? 'sí' : 'no') . PHP_EOL;
    echo 'Override fuente: ' . ($dbSourceOverride !== '' ? $dbSourceOverride : 'auto') . PHP_EOL;

    foreach ($companies as $company) {
        $summary['companies']++;

        $idEmpresa = (int)($company['id_empresa'] ?? 0);
        $nombreEmpresa = trim((string)($company['empresa'] ?? ''));
        $dbName = trim((string)($company['dbase'] ?? ''));
        $software = (int)($company['software'] ?? 1);
        $activo = (int)($company['activo'] ?? 0);

        echo PHP_EOL;
        echo '[' . $idEmpresa . '] ' . ($nombreEmpresa !== '' ? $nombreEmpresa : 'Sin nombre') . PHP_EOL;
        echo 'DB destino: ' . ($dbName !== '' ? $dbName : '(vacía)') . PHP_EOL;
        echo 'Software: ' . $software . ' | Activo: ' . $activo . PHP_EOL;

        if ($dbName === '') {
            $summary['warnings']++;
            echo "  WARN: la empresa no tiene `dbase` configurada." . PHP_EOL;
            continue;
        }

        if (!schemaExists($pdo, $dbName)) {
            $summary['warnings']++;
            echo '  WARN: la base de datos destino no existe.' . PHP_EOL;
            continue;
        }

        $sourceDb = resolveSourceDb($pdo, $software, $dbSourceOverride);
        if ($sourceDb === '') {
            $summary['warnings']++;
            echo '  WARN: no se pudo resolver base plantilla para esta empresa.' . PHP_EOL;
            continue;
        }

        $sourceTables = getBaseTables($pdo, $sourceDb);
        $destTables = getBaseTables($pdo, $dbName);
        $destSet = array_flip($destTables);
        $missing = [];

        foreach ($sourceTables as $table) {
            if (!isset($destSet[$table])) {
                $missing[] = $table;
            }
        }

        echo 'Fuente: ' . $sourceDb . ' | Tablas plantilla: ' . count($sourceTables) . ' | Tablas destino: ' . count($destTables) . PHP_EOL;

        if ($mostrarTablas) {
            echo 'Tablas plantilla: ' . implode(', ', $sourceTables) . PHP_EOL;
            echo 'Tablas destino: ' . implode(', ', $destTables) . PHP_EOL;
        }

        if (empty($missing)) {
            $summary['ok']++;
            echo "  OK: no faltan tablas respecto a la plantilla." . PHP_EOL;
            continue;
        }

        $summary['missing_total'] += count($missing);
        echo '  FALTAN ' . count($missing) . ' tabla(s): ' . implode(', ', $missing) . PHP_EOL;
    }

    printHeader('Resumen');
    echo 'Empresas revisadas: ' . $summary['companies'] . PHP_EOL;
    echo 'Sin faltantes: ' . $summary['ok'] . PHP_EOL;
    echo 'Con avisos: ' . $summary['warnings'] . PHP_EOL;
    echo 'Tablas faltantes totales: ' . $summary['missing_total'] . PHP_EOL;

    exit($summary['missing_total'] > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
