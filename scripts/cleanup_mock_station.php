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

require_once __DIR__ . '/../public/shared/schema_module_compat.php';

/**
 * Limpia la base mock de estacion de servicio.
 *
 * Por defecto solo hace dry-run.
 * Con --apply elimina el contenido mock y recrea el esquema minimo.
 */

$options = getopt('', [
    'db::',
    'apply',
    'force',
    'recreate-schema',
    'keep-schema',
]);

$envFile = __DIR__ . '/../.env.mock';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$database = trim((string)($options['db'] ?? ($_ENV['MOCK_DB_NAME'] ?? 'empresa_1027')));
$apply = array_key_exists('apply', $options);
$force = array_key_exists('force', $options);
$recreateSchema = array_key_exists('recreate-schema', $options) || !array_key_exists('keep-schema', $options);

$host = $_ENV['MOCK_DB_HOST'] ?? 'localhost';
$port = (int)($_ENV['MOCK_DB_PORT'] ?? 3307);
$user = $_ENV['MOCK_DB_USER'] ?? 'desarrollo';
$password = $_ENV['MOCK_DB_PASS'] ?? 'desarrollo123';
$allowedPort = (int)($_ENV['MOCK_ALLOWED_PORT'] ?? 3307);

if ($port !== $allowedPort) {
    fwrite(STDERR, "❌ ERROR: Puerto no coincide con configuración de desarrollo\n");
    exit(1);
}

function cleanupPrint(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function cleanupConfirm(string $database): bool
{
    cleanupPrint('');
    cleanupPrint("Base objetivo: {$database}");
    cleanupPrint("Escribe 'SI' para vaciarla por completo.");
    $handle = fopen('php://stdin', 'r');
    if (!$handle) {
        return false;
    }
    $line = trim((string)fgets($handle));
    fclose($handle);
    return strtoupper($line) === 'SI';
}

$tableOrder = [
    'estacion_cierre_playa_detalle',
    'estacion_cierre_playa',
    'estacion_despachos',
    'estacion_turno_picos',
    'estacion_turnos',
    'estacion_lecturas_surtidor',
    'estacion_precios_historial',
    'estacion_picos',
    'estacion_lecturas_tanque',
    'estacion_surtidores',
    'estacion_tanques',
    'estacion_combustibles',
];

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    cleanupPrint("✓ Conectado a BD: {$database}@{$host}:{$port}");
    cleanupPrint('Modo: ' . ($apply ? 'apply' : 'dry-run'));
    cleanupPrint('Recrear esquema: ' . ($recreateSchema ? 'si' : 'no'));
    cleanupPrint('');

    $existing = [];
    foreach ($tableOrder as $table) {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :db
              AND TABLE_NAME = :tbl
            LIMIT 1
        ");
        $stmt->execute([':db' => $database, ':tbl' => $table]);
        if ($stmt->fetchColumn()) {
            $existing[] = $table;
        }
    }

    if (empty($existing)) {
        cleanupPrint('[OK] No hay tablas de estación para limpiar.');
        exit(0);
    }

    cleanupPrint('Tablas a limpiar: ' . implode(', ', $existing));

    if (!$apply) {
        cleanupPrint('');
        cleanupPrint('Dry-run completado. Ejecuta con --apply para borrar.');
        exit(0);
    }

    if (!$force && !cleanupConfirm($database)) {
        cleanupPrint('[SKIP] Cancelado por el usuario.');
        exit(1);
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tableOrder as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `{$database}`.`{$table}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    cleanupPrint('✓ Tablas mock eliminadas.');

    if ($recreateSchema) {
        sxEnsureEstacionSchemaCompat($pdo, $database);
        cleanupPrint('✓ Esquema de estación recreado.');
    }

    cleanupPrint('✅ Limpieza finalizada.');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '❌ Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
