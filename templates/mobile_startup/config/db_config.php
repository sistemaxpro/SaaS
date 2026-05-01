<?php
/**
 * Template de arranque para apps mobile nuevas.
 */

if (!defined('SISTEMAX_V1')) {
    require_once __DIR__ . '/../../../config/bootstrap.php';
}

require_once __DIR__ . '/../../../public/shared/schema_module_compat.php';

Session::start();

if (!function_exists('getEmpresaConnection')) {
    function getEmpresaConnection($id_empresa)
    {
        $masterPdo = Database::getMasterConnection();
        $empresa = Database::getEmpresaInfo((int)$id_empresa);

        if (!$empresa) {
            throw new Exception("Empresa ID {$id_empresa} no encontrada");
        }

        $dbName = (string)($empresa['dbase'] ?? '');
        if ($dbName === '') {
            throw new Exception("Empresa ID {$id_empresa} sin dbase configurada");
        }

        $pdoEmpresa = Database::getEmpresaConnection((int)$id_empresa);
        $sourceDb = sxResolveCompanySourceDbCompat($masterPdo, is_array($empresa) ? $empresa : []);

        // Ajustar los modulos que consume esta app mobile nueva.
        $modules = ['productos'];
        sxEnsureModuleSchemaCompat($pdoEmpresa, $dbName, $modules, $sourceDb);

        return [
            'pdo' => $pdoEmpresa,
            'dbName' => $dbName,
            'config' => $empresa,
            'masterPdo' => $masterPdo,
            'masterDb' => Database::getMasterDbName(),
        ];
    }
}

if (!function_exists('getMasterConnection')) {
    function getMasterConnection()
    {
        return Database::getMasterConnection();
    }
}

