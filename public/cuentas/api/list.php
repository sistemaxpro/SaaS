<?php
/**
 * Cuentas API - Listado de cuentas (tabla cuentas)
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requireAccess('cuentas');

$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 0);
if ($id_empresa <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Sin sesión']);
    exit;
}

function ensureCuentasTable(PDO $pdo, string $db): void {
    $sql = "CREATE TABLE IF NOT EXISTS {$db}.cuentas (
        id INT NOT NULL AUTO_INCREMENT,
        cuenta VARCHAR(120) NOT NULL,
        fijo TINYINT(1) NOT NULL DEFAULT 0,
        estado TINYINT(1) NOT NULL DEFAULT 1,
        fecha DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($sql);
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

    ensureCuentasTable($pdo, $db);
    $cols = getColumns($pdo, $db, 'cuentas');

    $search = trim((string)($_GET['search'] ?? ''));
    $estado = (string)($_GET['estado'] ?? 'all');
    $fijo = (string)($_GET['fijo'] ?? 'all');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(10, min(100, (int)($_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;

    $hasEstado = in_array('estado', $cols, true);
    $hasFijo = in_array('fijo', $cols, true);
    $hasFecha = in_array('fecha', $cols, true);

    $where = ['1=1'];
    $params = [];

    if ($search !== '') {
        $where[] = '(c.cuenta LIKE :s OR CAST(c.id AS CHAR) LIKE :s2)';
        $params[':s'] = '%' . $search . '%';
        $params[':s2'] = '%' . $search . '%';
    }

    if ($hasEstado && ($estado === '1' || $estado === '0')) {
        $where[] = 'c.estado = :estado';
        $params[':estado'] = (int)$estado;
    }

    if ($hasFijo && ($fijo === '1' || $fijo === '0')) {
        $where[] = 'c.fijo = :fijo';
        $params[':fijo'] = (int)$fijo;
    }

    $whereSQL = implode(' AND ', $where);

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM {$db}.cuentas c WHERE {$whereSQL}");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    $select = ['c.id', 'c.cuenta'];
    $select[] = $hasFijo ? 'c.fijo' : '0 AS fijo';
    $select[] = $hasEstado ? 'c.estado' : '1 AS estado';
    $select[] = $hasFecha ? 'c.fecha' : 'NULL AS fecha';

    $sql = "SELECT " . implode(', ', $select) . "
            FROM {$db}.cuentas c
            WHERE {$whereSQL}
            ORDER BY c.cuenta ASC
            LIMIT {$limit} OFFSET {$offset}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = [
        'total' => $total,
        'activas' => 0,
        'inactivas' => 0,
        'fijas' => 0,
    ];

    if ($hasEstado || $hasFijo) {
        $selStats = 'COUNT(*) AS total';
        if ($hasEstado) {
            $selStats .= ', SUM(CASE WHEN estado = 1 THEN 1 ELSE 0 END) AS activas';
            $selStats .= ', SUM(CASE WHEN estado = 0 THEN 1 ELSE 0 END) AS inactivas';
        }
        if ($hasFijo) {
            $selStats .= ', SUM(CASE WHEN fijo = 1 THEN 1 ELSE 0 END) AS fijas';
        }
        $stmtStats = $pdo->query("SELECT {$selStats} FROM {$db}.cuentas");
        $statsRaw = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['total'] = (int)($statsRaw['total'] ?? $total);
        $stats['activas'] = (int)($statsRaw['activas'] ?? $stats['total']);
        $stats['inactivas'] = (int)($statsRaw['inactivas'] ?? 0);
        $stats['fijas'] = (int)($statsRaw['fijas'] ?? 0);
    } else {
        $stats['activas'] = $stats['total'];
    }

    echo json_encode([
        'ok' => true,
        'data' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => (int)ceil($total / $limit),
        'stats' => $stats,
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
