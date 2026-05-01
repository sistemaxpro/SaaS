<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

// Reutiliza permisos de Configuración para evitar bloqueos por app nueva sin matriz de permisos.
const SX_SUCURSALES_APP = 'configuracion';
Permission::requireAccess(SX_SUCURSALES_APP);

function sx_suc_get_conn(int $idEmpresa): array
{
    $conn = getEmpresaConnection($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    sx_suc_ensure_table($pdo, $db);
    return $conn;
}

function sx_suc_columns(PDO $pdo, string $db): array
{
    $stmt = $pdo->query("SHOW COLUMNS FROM {$db}.sucursales");
    $cols = [];
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') $cols[$field] = true;
    }
    return $cols;
}

function sx_suc_ensure_table(PDO $pdo, string $db): void
{
    $sql = "CREATE TABLE IF NOT EXISTS {$db}.sucursales (
        id_sucursal INT NOT NULL AUTO_INCREMENT,
        sucursal VARCHAR(120) NOT NULL,
        pais VARCHAR(80) NULL,
        ciudad VARCHAR(80) NULL,
        direccion VARCHAR(180) NULL,
        telefono VARCHAR(60) NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id_sucursal)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($sql);

    $cols = sx_suc_columns($pdo, $db);
    $adds = [];
    if (!isset($cols['activo'])) $adds[] = "ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1";
    if (!isset($cols['pais'])) $adds[] = "ADD COLUMN pais VARCHAR(80) NULL";
    if (!isset($cols['ciudad'])) $adds[] = "ADD COLUMN ciudad VARCHAR(80) NULL";
    if (!isset($cols['direccion'])) $adds[] = "ADD COLUMN direccion VARCHAR(180) NULL";
    if (!isset($cols['telefono'])) $adds[] = "ADD COLUMN telefono VARCHAR(60) NULL";

    foreach ($adds as $ddl) {
        try {
            $pdo->exec("ALTER TABLE {$db}.sucursales {$ddl}");
        } catch (Throwable $e) {
            error_log('[sucursales/common] alter omitido: ' . $e->getMessage());
        }
    }
}

function sx_suc_can(string $perm): void
{
    Permission::requirePermission(SX_SUCURSALES_APP, $perm);
}
