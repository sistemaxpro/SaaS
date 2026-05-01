<?php
/**
 * Taller - Configuracion de Base de Datos
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
        $stmt = $masterPdo->prepare("SELECT * FROM empresa WHERE id_empresa = :id");
        $stmt->execute([':id' => $id_empresa]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$empresa) {
            throw new Exception("Empresa ID {$id_empresa} no encontrada");
        }

        $dbName = 'empresa_' . (int)$id_empresa;
        $pdoEmpresa = Database::getEmpresaConnection((int)$id_empresa);

        sxEnsureModuleSchemaCompat($pdoEmpresa, (string)$dbName, ['taller']);

        return [
            'pdo' => $pdoEmpresa,
            'dbName' => $dbName,
            'config' => $empresa,
            'masterPdo' => $masterPdo,
            'masterDb' => 'serproc1'
        ];
    }
}
