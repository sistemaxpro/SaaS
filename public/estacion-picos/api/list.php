<?php
require_once __DIR__ . '/common.php';

try {
    Session::requireLogin();
    Permission::requireAccess('app_grid_estacion');

    $idEmpresa = (int)($_GET['id_empresa'] ?? Session::getIdEmpresa() ?? 0);
    if ($idEmpresa <= 0) {
        throw new Exception('Empresa no válida');
    }

    $conn = sx_picos_get_conn($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $surtidorMeta = sx_picos_resolve_table_columns($pdo, $db, 'estacion_surtidores');
    $tanqueMeta = sx_picos_resolve_table_columns($pdo, $db, 'estacion_tanques');
    $combustibleMeta = sx_picos_resolve_table_columns($pdo, $db, 'estacion_combustibles');

    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;
    $search = trim((string)($_GET['search'] ?? ''));

    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = "(p.nombre LIKE :search OR CONCAT('Pico ', p.nro_pico) LIKE :search OR s.nombre LIKE :search OR t.nombre LIKE :search OR c.nombre LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$db}.estacion_picos p
        LEFT JOIN {$db}.estacion_surtidores s ON s.{$surtidorMeta['id']} = p.id_surtidor
        LEFT JOIN {$db}.estacion_tanques t ON t.{$tanqueMeta['id']} = p.id_tanque
        LEFT JOIN {$db}.estacion_combustibles c ON c.{$combustibleMeta['id']} = p.id_combustible
        {$whereSql}");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $sql = "
        SELECT
            p.id, p.id_surtidor, p.nro_pico, p.nombre, p.id_combustible, p.id_tanque,
            p.totalizador_actual, p.activo, p.created_at, p.updated_at,
            COALESCE(s.{$surtidorMeta['name']}, CONCAT('Surtidor ', p.id_surtidor)) AS surtidor,
            COALESCE(t.{$tanqueMeta['name']}, CONCAT('Tanque ', p.id_tanque)) AS tanque,
            COALESCE(c.{$combustibleMeta['name']}, CONCAT('Combustible ', p.id_combustible)) AS combustible
        FROM {$db}.estacion_picos p
        LEFT JOIN {$db}.estacion_surtidores s ON s.{$surtidorMeta['id']} = p.id_surtidor
        LEFT JOIN {$db}.estacion_tanques t ON t.{$tanqueMeta['id']} = p.id_tanque
        LEFT JOIN {$db}.estacion_combustibles c ON c.{$combustibleMeta['id']} = p.id_combustible
        {$whereSql}
        ORDER BY p.id_surtidor, p.nro_pico
        LIMIT {$perPage} OFFSET {$offset}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok' => true,
        'data' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => max(1, (int)ceil($total / $perPage)),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
