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
    'id_empresa:',
    'software::',
    'apply',
    'sync-master',
    'source::',
]);

$idEmpresa = (int)($options['id_empresa'] ?? 0);
$software = (int)($options['software'] ?? 1);
$apply = array_key_exists('apply', $options);
$syncMaster = array_key_exists('sync-master', $options) || $apply;
$sourceOverride = trim((string)($options['source'] ?? ''));

if ($idEmpresa <= 0) {
    fwrite(STDERR, "Uso: php scripts/provision_empresa_db.php --id_empresa=169 [--software=1] [--apply] [--sync-master] [--source=tienda_169]\n");
    exit(1);
}

function provisionPrint(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

try {
    $master = Database::getMasterConnection();
    $empresa = Database::getEmpresaInfo($idEmpresa);

    if (!$empresa) {
        throw new RuntimeException("Empresa ID {$idEmpresa} no encontrada");
    }

    if ($software <= 0) {
        $software = (int)($empresa['software'] ?? 1);
    }

    $empresa['software'] = $software;
    $dbName = 'empresa_' . $idEmpresa;
    $sourceDb = sxResolveCompanySourceDbCompat($master, $empresa, $sourceOverride);

    provisionPrint("Empresa: {$idEmpresa}");
    provisionPrint("DB objetivo: {$dbName}");
    provisionPrint("Fuente: " . ($sourceDb !== '' ? $sourceDb : '(no resuelta)'));
    provisionPrint('Modo: ' . ($apply ? 'apply' : 'dry-run'));
    provisionPrint('Sincronizar master dbase: ' . ($syncMaster ? 'si' : 'no'));

    if ($sourceDb === '') {
        throw new RuntimeException('No se pudo resolver una base plantilla disponible');
    }

    if (!$apply) {
        $stmt = $master->prepare("
            SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :db
              AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
        ");
        $stmt->execute([':db' => $sourceDb]);
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        provisionPrint('Tablas origen: ' . count($tables));
        provisionPrint('Ejecuta con --apply para crear la BD y clonar la estructura.');
        exit(0);
    }

    $result = sxEnsureCompanyDatabaseCompat($master, $idEmpresa, $empresa, $sourceOverride);

    if ($syncMaster) {
        $stmtUpd = $master->prepare("UPDATE empresa SET dbase = :dbase WHERE id_empresa = :id");
        $stmtUpd->execute([
            ':dbase' => $dbName,
            ':id' => $idEmpresa,
        ]);
    }

    provisionPrint('✓ BD creada/verificada: ' . $result['db_name']);
    provisionPrint('✓ Base fuente: ' . ($result['db_source'] !== '' ? $result['db_source'] : '(sin fuente)'));
    provisionPrint('✓ Tablas clonadas: ' . (int)($result['tables_created'] ?? 0));

    if (!empty($result['tables_created_list'])) {
        provisionPrint('Tablas: ' . implode(', ', $result['tables_created_list']));
    }

    if (!empty($result['errors'])) {
        provisionPrint('Advertencias:');
        foreach ($result['errors'] as $error) {
            provisionPrint(' - ' . $error);
        }
    }

    if ($syncMaster) {
        provisionPrint('✓ dbase sincronizado en ' . Database::getMasterDbName() . '.empresa');
    }

    provisionPrint('✅ Provisionamiento completado');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '❌ Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
