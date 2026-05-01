<?php
/**
 * Productos - Configuración de Base de Datos
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
        $id_empresa = (int)$id_empresa;
        $dbName = Session::getDbase() ?: ('empresa_' . $id_empresa);
        $empresa = null;
        $masterPdo = null;
        $pdoEmpresa = null;

        try {
            $masterPdo = Database::getMasterConnection();
            $stmt = $masterPdo->prepare("SELECT * FROM empresa WHERE id_empresa = :id");
            $stmt->execute([':id' => $id_empresa]);
            $empresa = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $empresa = null;
        }

        try {
            $pdoEmpresa = Database::getEmpresaConnection($id_empresa);
        } catch (Throwable $e) {
            $host = (string)(getenv('SISTEMAX_MASTER_DB_HOST') ?: '127.0.0.1');
            $port = (int)(getenv('SISTEMAX_MASTER_DB_PORT') ?: 3306);
            $user = (string)(getenv('SISTEMAX_MASTER_DB_USER') ?: 'sistemax');
            $pass = (string)(getenv('SISTEMAX_MASTER_DB_PASS') ?: 'Armagedon123');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
            $pdoEmpresa = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ]);
        }

        if (!$pdoEmpresa) {
            throw new Exception("Empresa ID {$id_empresa} no encontrada");
        }

        if ($masterPdo) {
            $sourceDb = sxResolveCompanySourceDbCompat($masterPdo, is_array($empresa) ? $empresa : []);
            sxEnsureModuleSchemaCompat($pdoEmpresa, (string)$dbName, ['productos'], $sourceDb);
        }

        return [
            'pdo' => $pdoEmpresa,
            'dbName' => $dbName,
            'config' => $empresa,
            'masterPdo' => $masterPdo,
            'masterDb' => 'serproc1'
        ];
    }
}

if (!function_exists('getMasterConnection')) {
    function getMasterConnection() {
        return Database::getMasterConnection();
    }
}
