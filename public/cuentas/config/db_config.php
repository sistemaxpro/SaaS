<?php
/**
 * Contactos - Configuración de Base de Datos
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
        $stmt = $masterPdo->prepare("SELECT * FROM empresa WHERE id_empresa = :id");
        $stmt->execute([':id' => $id_empresa]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$empresa) {
            throw new Exception("Empresa ID {$id_empresa} no encontrada");
        }

        $dbHost = !empty($empresa['server']) ? $empresa['server'] : 'localhost';
        $dbUser = !empty($empresa['user']) ? $empresa['user'] : 'sistemax';
        $dbPass = 'Armagedon123';
        $dbName = 'empresa_' . (int)$id_empresa;

        $pdoEmpresa = new PDO(
            "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        $sourceDb = sxResolveCompanySourceDbCompat($masterPdo, is_array($empresa) ? $empresa : []);
        sxEnsureModuleSchemaCompat($pdoEmpresa, (string)$dbName, ['cuentas', 'contactos'], $sourceDb);

        return [
            'pdo' => $pdoEmpresa,
            'dbName' => $dbName,
            'empresa' => $empresa,
        ];
    }
}
