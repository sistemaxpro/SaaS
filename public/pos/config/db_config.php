<?php
/**
 * POS API - Configuración de Base de Datos
 * Wrapper para usar el sistema de conexión de v1
 */

if (!defined('SISTEMAX_V1')) {
    require_once __DIR__ . '/../../../config/bootstrap.php';
}
require_once __DIR__ . '/../../shared/schema_module_compat.php';

Session::start();

if (!function_exists('getEmpresaConnection')) {
    function getEmpresaConnection($id_empresa)
    {
        $masterPdo = Database::getMasterConnection();
        $masterDb = Database::getMasterDbName();
        $empresa = Database::getEmpresaInfo((int)$id_empresa);

        if (!$empresa) {
            throw new Exception("Empresa ID {$id_empresa} no encontrada");
        }
        $dbName = (string)($empresa['dbase'] ?? ('empresa_' . (int)$id_empresa));
        $pdoEmpresa = Database::getEmpresaConnection((int)$id_empresa);

        $sourceDb = sxResolveCompanySourceDbCompat($masterPdo, is_array($empresa) ? $empresa : []);
        sxEnsureModuleSchemaCompat($pdoEmpresa, $dbName, ['productos'], $sourceDb);

        return [
            'pdo' => $pdoEmpresa,
            'dbName' => $dbName,
            'dbHost' => defined('SISTEMAX_IS_DEV') && SISTEMAX_IS_DEV ? '127.0.0.1' : 'localhost',
            'config' => $empresa,
            'masterPdo' => $masterPdo,
            'masterDb' => $masterDb
        ];
    }
}

if (!function_exists('getMasterConnection')) {
    function getMasterConnection() {
        return Database::getMasterConnection();
    }
}
