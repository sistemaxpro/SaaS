<?php
require_once __DIR__ . '/common.php';

$idEmpresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

try {
    $conn = sx_suc_get_conn($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(5, (int)($_GET['per_page'] ?? 24)));
    $offset = ($page - 1) * $limit;
    $search = trim((string)($_GET['search'] ?? ''));
    $estado = trim((string)($_GET['estado'] ?? ''));

    $cols = sx_suc_columns($pdo, $db);
    $hasActivo = isset($cols['activo']);

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(sucursal LIKE :s OR ciudad LIKE :s OR direccion LIKE :s OR telefono LIKE :s)";
        $params[':s'] = '%' . $search . '%';
    }

    if ($hasActivo && $estado !== '') {
        $where[] = "activo = :activo";
        $params[':activo'] = ((int)$estado === 1 ? 1 : 0);
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM {$db}.sucursales {$whereSql}");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

    $sql = "SELECT id_sucursal, sucursal, pais, ciudad, direccion, telefono";
    if ($hasActivo) $sql .= ", activo";
    $sql .= " FROM {$db}.sucursales {$whereSql} ORDER BY id_sucursal ASC LIMIT :lim OFFSET :off";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$r) {
        if (!isset($r['activo'])) $r['activo'] = 1;
    }
    unset($r);

    echo json_encode([
        'ok' => true,
        'data' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => max(1, (int)ceil($total / $limit)),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
