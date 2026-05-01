<?php
/**
 * Gastos - Configuración de Base de Datos
 */

if (!defined('SISTEMAX_V1')) {
    require_once __DIR__ . '/../../../config/bootstrap.php';
}

Session::start();

if (!function_exists('getEmpresaConnection')) {
    function getEmpresaConnection($id_empresa)
    {
        $masterPdo = Database::getMasterConnection();
        $empresa = Database::getEmpresaInfo((int)$id_empresa);

        if (!$empresa) {
            throw new Exception("Empresa ID {$id_empresa} no encontrada");
        }

        $dbName = (string)($empresa['dbase'] ?? ('empresa_' . (int)$id_empresa));
        $pdoEmpresa = Database::getEmpresaConnection((int)$id_empresa);

        return [
            'pdo' => $pdoEmpresa,
            'dbName' => $dbName,
            'empresa' => $empresa,
        ];
    }
}
