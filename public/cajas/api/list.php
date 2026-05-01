<?php
/**
 * Cajas API - Listado con paginación y filtros
 * GET: ?page=1&per_page=25&search=&sucursal=&sort_by=&sort_dir=
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requireAccess('app_grid_caja');

$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    // Parámetros
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(100, max(5, (int)($_GET['per_page'] ?? $_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');
    $sucursal = $_GET['sucursal'] ?? '';
    $filterIdCaja = isset($_GET['id_caja']) ? (int)$_GET['id_caja'] : 0;
    $order  = $_GET['sort_by'] ?? 'caja';
    $dir    = strtoupper($_GET['sort_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

    // Columnas permitidas para ordenar
    $allowedOrder = ['id_caja', 'caja', 'id_sucursal', 'timbrado', 'saldo_maximo'];
    if (!in_array($order, $allowedOrder)) $order = 'caja';

    // WHERE
    $where = [];
    $params = [];

    if ($sucursal !== '' && $sucursal !== 'all') {
        $where[] = "c.id_sucursal = :sucursal";
        $params[':sucursal'] = (int)$sucursal;
    }

    if ($search !== '') {
        $where[] = "(c.caja LIKE :s1 OR c.timbrado LIKE :s2)";
        $sv = "%{$search}%";
        $params[':s1'] = $sv;
        $params[':s2'] = $sv;
    }

    if ($filterIdCaja > 0) {
        $where[] = "c.id_caja = :filter_id_caja";
        $params[':filter_id_caja'] = $filterIdCaja;
    }

    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count
    $countSQL = "SELECT COUNT(*) as total FROM {$db}.cajas c {$whereSQL}";
    $stmtCount = $pdo->prepare($countSQL);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetch()['total'];

    // Select con sucursal
    $sql = "SELECT c.*, s.NOMBRE as nombre_sucursal
            FROM {$db}.cajas c
            LEFT JOIN {$db}.sucursal s ON s.SUC = c.id_sucursal
            {$whereSQL}
            ORDER BY c.{$order} {$dir}
            LIMIT {$limit} OFFSET {$offset}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cajas = $stmt->fetchAll();

    // Obtener usuarios asignados por caja
    $cajaIds = array_column($cajas, 'id_caja');
    $usuariosPorCaja = [];
    if (count($cajaIds) > 0) {
        $masterPdo = Database::getMasterConnection();
        $placeholders = implode(',', array_fill(0, count($cajaIds), '?'));
        
        // Buscar en tabla local cajas_usuarios
        try {
            $stmtU = $pdo->prepare("SELECT cu.id_caja, cu.id_login FROM {$db}.cajas_usuarios cu WHERE cu.id_caja IN ({$placeholders})");
            $stmtU->execute($cajaIds);
            $asignaciones = $stmtU->fetchAll();
            
            // Obtener nombres de login de serproc1
            $loginIds = array_unique(array_column($asignaciones, 'id_login'));
            $nombresLogin = [];
            if (count($loginIds) > 0) {
                $ph2 = implode(',', array_fill(0, count($loginIds), '?'));
                $stmtNames = $masterPdo->prepare("SELECT id_login, name, login FROM sec_users WHERE id_login IN ({$ph2})");
                $stmtNames->execute($loginIds);
                while ($row = $stmtNames->fetch()) {
                    $nombresLogin[$row['id_login']] = $row['name'] ?: $row['login'];
                }
            }
            
            foreach ($asignaciones as $a) {
                $usuariosPorCaja[$a['id_caja']][] = [
                    'id_login' => $a['id_login'],
                    'nombre' => $nombresLogin[$a['id_login']] ?? 'Usuario #' . $a['id_login']
                ];
            }
        } catch (Exception $e) {
            // tabla cajas_usuarios puede no existir
        }
    }

    // Agregar usuarios asignados a cada caja
    foreach ($cajas as &$caja) {
        // Compatibilidad entre esquemas: algunas BD usan "impresor" en lugar de "impresora".
        $impresoraVal = trim((string)($caja['impresora'] ?? ''));
        if ($impresoraVal === '' && isset($caja['impresor'])) {
            $impresoraVal = trim((string)$caja['impresor']);
        }
        $caja['impresora'] = $impresoraVal;
        if (!isset($caja['impresor'])) {
            $caja['impresor'] = $impresoraVal;
        }
        $caja['usuarios_asignados'] = $usuariosPorCaja[$caja['id_caja']] ?? [];
    }

    // Stats
    $stmtStats = $pdo->query("SELECT COUNT(*) as total FROM {$db}.cajas");
    $statsTotal = (int)$stmtStats->fetch()['total'];

    echo json_encode([
        'ok' => true,
        'data' => $cajas,
        'total' => $total,
        'page' => $page,
        'pages' => max(1, ceil($total / $limit)),
        'stats' => [
            'total' => $statsTotal
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
