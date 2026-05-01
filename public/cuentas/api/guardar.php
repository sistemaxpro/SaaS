<?php
/**
 * Cuentas API - Crear / Editar cuenta
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

$id_empresa = (int)($_SESSION['id_empresa'] ?? 0);
if ($id_empresa <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Sin sesión']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
    exit;
}

$id = (int)($input['id'] ?? 0);
$cuenta = trim((string)($input['cuenta'] ?? ''));
$fijo = !empty($input['fijo']) ? 1 : 0;
$estado = (isset($input['estado']) && (string)$input['estado'] === '0') ? 0 : 1;

if ($cuenta === '') {
    echo json_encode(['ok' => false, 'error' => 'Nombre de cuenta requerido']);
    exit;
}

function getColumns(PDO $pdo, string $db, string $table): array {
    $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = :db AND table_name = :tb");
    $stmt->execute([':db' => $db, ':tb' => $table]);
    return array_map('strtolower', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'column_name'));
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$db}.cuentas (
        id INT NOT NULL AUTO_INCREMENT,
        cuenta VARCHAR(120) NOT NULL,
        fijo TINYINT(1) NOT NULL DEFAULT 0,
        estado TINYINT(1) NOT NULL DEFAULT 1,
        fecha DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $cols = getColumns($pdo, $db, 'cuentas');
    $hasFijo = in_array('fijo', $cols, true);
    $hasEstado = in_array('estado', $cols, true);

    if ($id > 0) {
        Permission::requirePermission('cuentas', 'priv_update');

        $sets = ['cuenta = :cuenta'];
        $params = [':id' => $id, ':cuenta' => $cuenta];
        if ($hasFijo) {
            $sets[] = 'fijo = :fijo';
            $params[':fijo'] = $fijo;
        }
        if ($hasEstado) {
            $sets[] = 'estado = :estado';
            $params[':estado'] = $estado;
        }

        $sql = "UPDATE {$db}.cuentas SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['ok' => true, 'msg' => 'Cuenta actualizada']);
        exit;
    }

    Permission::requirePermission('cuentas', 'priv_insert');

    $fields = ['cuenta'];
    $marks = [':cuenta'];
    $params = [':cuenta' => $cuenta];

    if ($hasFijo) {
        $fields[] = 'fijo';
        $marks[] = ':fijo';
        $params[':fijo'] = $fijo;
    }
    if ($hasEstado) {
        $fields[] = 'estado';
        $marks[] = ':estado';
        $params[':estado'] = $estado;
    }

    $sql = "INSERT INTO {$db}.cuentas (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $marks) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['ok' => true, 'msg' => 'Cuenta creada', 'id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
