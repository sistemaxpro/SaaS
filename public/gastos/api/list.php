<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requireAccess('app_grid_caja');

$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

function ensureGastosSchema(PDO $pdo, string $db): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$db}.gastos_empresa (
        id_gasto INT AUTO_INCREMENT PRIMARY KEY,
        id_empresa INT NOT NULL,
        id_sucursal INT NULL,
        id_caja INT NULL,
        fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        concepto VARCHAR(140) NOT NULL,
        observacion VARCHAR(255) NULL,
        monto DECIMAL(14,2) NOT NULL,
        pagado_por_tipo VARCHAR(30) NOT NULL DEFAULT 'CAJA',
        pagado_por_detalle VARCHAR(120) NULL,
        origen VARCHAR(20) NOT NULL DEFAULT 'MANUAL',
        estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
        creado_por INT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_gasto_fecha (fecha),
        INDEX idx_gasto_empresa (id_empresa),
        INDEX idx_gasto_sucursal (id_sucursal),
        INDEX idx_gasto_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    ensureGastosSchema($pdo, $db);

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(5, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $limit;

    $search = trim((string)($_GET['search'] ?? ''));
    $desde = trim((string)($_GET['desde'] ?? ''));
    $hasta = trim((string)($_GET['hasta'] ?? ''));
    $tipo = strtoupper(trim((string)($_GET['pagado_por_tipo'] ?? '')));
    $estado = strtoupper(trim((string)($_GET['estado'] ?? 'ACTIVO')));

    $where = ["g.id_empresa = :id_empresa"];
    $params = [':id_empresa' => $id_empresa];

    if ($search !== '') {
        $where[] = "(g.concepto LIKE :q1 OR g.observacion LIKE :q2 OR g.pagado_por_detalle LIKE :q3)";
        $sv = "%{$search}%";
        $params[':q1'] = $sv;
        $params[':q2'] = $sv;
        $params[':q3'] = $sv;
    }

    if ($desde !== '') {
        $where[] = "DATE(g.fecha) >= :desde";
        $params[':desde'] = $desde;
    }

    if ($hasta !== '') {
        $where[] = "DATE(g.fecha) <= :hasta";
        $params[':hasta'] = $hasta;
    }

    if ($tipo !== '') {
        $where[] = "g.pagado_por_tipo = :tipo";
        $params[':tipo'] = $tipo;
    }

    if ($estado !== 'TODOS') {
        $where[] = "g.estado = :estado";
        $params[':estado'] = $estado;
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $countSQL = "SELECT COUNT(*) FROM {$db}.gastos_empresa g {$whereSQL}";
    $stmtC = $pdo->prepare($countSQL);
    $stmtC->execute($params);
    $total = (int)$stmtC->fetchColumn();

    $sql = "SELECT
                g.*,
                c.caja AS nombre_caja,
                u.name AS usuario_nombre,
                u.login AS usuario_login
            FROM {$db}.gastos_empresa g
            LEFT JOIN {$db}.cajas c ON c.id_caja = g.id_caja
            LEFT JOIN " . MASTER_DB . ".sec_users u ON u.id_login = g.creado_por
            {$whereSQL}
            ORDER BY g.fecha DESC, g.id_gasto DESC
            LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = [
        'monto_total' => 0.0,
        'monto_mes' => 0.0,
        'cantidad' => 0,
    ];

    $stmtStats = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN estado = 'ACTIVO' THEN monto ELSE 0 END), 0) AS monto_total,
        COALESCE(SUM(CASE WHEN estado = 'ACTIVO' AND DATE_FORMAT(fecha, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN monto ELSE 0 END), 0) AS monto_mes,
        COUNT(*) AS cantidad
        FROM {$db}.gastos_empresa
        WHERE id_empresa = :id_empresa");
    $stmtStats->execute([':id_empresa' => $id_empresa]);
    $st = $stmtStats->fetch(PDO::FETCH_ASSOC);
    if ($st) {
        $stats['monto_total'] = (float)$st['monto_total'];
        $stats['monto_mes'] = (float)$st['monto_mes'];
        $stats['cantidad'] = (int)$st['cantidad'];
    }

    echo json_encode([
        'ok' => true,
        'data' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => max(1, (int)ceil($total / $limit)),
        'stats' => $stats,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
