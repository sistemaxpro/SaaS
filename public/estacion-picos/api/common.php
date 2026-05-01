<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../../config/bootstrap.php';

function sx_picos_can(string $perm): void
{
    Permission::requireAccess('app_grid_estacion');
}

function sx_picos_get_conn(int $idEmpresa): array
{
    $pdo = Database::getEmpresaConnection($idEmpresa);
    $empresaInfo = Database::getEmpresaInfo($idEmpresa) ?: [];
    $dbName = trim((string)($empresaInfo['dbase'] ?? ''));
    if ($dbName === '') {
        $dbName = 'empresa_' . $idEmpresa;
    }
    $conn = [
        'pdo' => $pdo,
        'dbName' => $dbName,
    ];
    sx_picos_ensure_schema($pdo, $conn['dbName']);
    return $conn;
}

function sx_picos_columns(PDO $pdo, string $db, string $table): array
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM {$db}.{$table}");
        $cols = [];
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $field = (string)($row['Field'] ?? '');
            if ($field !== '') {
                $cols[$field] = true;
            }
        }
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

function sx_picos_resolve_table_columns(PDO $pdo, string $db, string $table): array
{
    $cols = sx_picos_columns($pdo, $db, $table);
    $idCol = isset($cols['id']) ? 'id' : (isset($cols['id_sucursal']) ? 'id_sucursal' : (isset($cols['id_tanque']) ? 'id_tanque' : 'id'));
    $nameCol = isset($cols['nombre']) ? 'nombre' : (isset($cols['sucursal']) ? 'sucursal' : $idCol);

    return [
        'id' => $idCol,
        'name' => $nameCol,
        'cols' => $cols,
    ];
}

function sx_picos_ensure_schema(PDO $pdo, string $db): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$db}.estacion_picos (
              id INT AUTO_INCREMENT PRIMARY KEY,
              id_surtidor INT NOT NULL,
              nro_pico INT NOT NULL COMMENT 'Número del pico en el surtidor (1, 2, 3, etc)',
              nombre VARCHAR(255),
              id_combustible INT NOT NULL,
              id_tanque INT NOT NULL,
              totalizador_actual DECIMAL(15, 3) DEFAULT 0 COMMENT 'Lectura acumulada del surtidor',
              activo ENUM('Y', 'N') NOT NULL DEFAULT 'Y',
              created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_surtidor (id_surtidor),
              KEY idx_combustible (id_combustible),
              KEY idx_tanque (id_tanque),
              UNIQUE KEY unique_pico_surtidor (id_surtidor, nro_pico)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('[estacion-picos] schema: ' . $e->getMessage());
    }
}
