<?php
/**
 * Contactos API - Listado con paginación y filtros
 * Compatible con esquemas variados (multi-tenant)
 * GET: ?page=1&per_page=25&search=&cuenta=&estado=&sort_by=&sort_dir=
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requireAccess('app_grid_clientes');

$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    // Detectar columnas disponibles
    $colsStmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'clientes'");
    $availableCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
    $extractoColsStmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'extracto_cliente'");
    $extractoCols = $extractoColsStmt->fetchAll(PDO::FETCH_COLUMN);

    // Helper para chequear columna
    $has = function($col) use ($availableCols) { return in_array($col, $availableCols); };
    $hasExtracto = function($col) use ($extractoCols) { return in_array($col, $extractoCols); };

    // Parámetros
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(100, max(5, (int)($_GET['per_page'] ?? $_GET['limit'] ?? 25)));
    $offset = ($page - 1) * $limit;
    $search = trim($_GET['search'] ?? '');
    $fullTableSearch = ($search !== '');
    $cuenta = $_GET['cuenta'] ?? '';
    $estado = $_GET['estado'] ?? '1';
    $fetchAll = (int)($_GET['all'] ?? 0) === 1;
    $order  = $_GET['sort_by'] ?? $_GET['order'] ?? 'nombre';
    $dir    = strtoupper($_GET['sort_dir'] ?? $_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

    // Columnas permitidas para ordenar
    $allowedOrder = ['id', 'nombre', 'numero', 'direccion', 'saldo', 'fecha', 'cuenta'];
    if (!in_array($order, $allowedOrder)) $order = 'nombre';

    // WHERE
    $where = [];
    $params = [];

    if ($estado !== '' && $estado !== 'all' && $has('estado')) {
        $estadoInt = (int)$estado;
        if ($estadoInt === 1) {
            $where[] = "COALESCE(c.estado, 1) = :estado";
        } else {
            $where[] = "c.estado = :estado";
        }
        $params[':estado'] = $estadoInt;
    }

    if ($cuenta !== '' && $cuenta !== 'all' && $has('cuenta')) {
        $where[] = "c.cuenta = :cuenta";
        $params[':cuenta'] = (int)$cuenta;
    }

    $searchTerms = [];
    $searchBlobExpr = "TRIM(CONCAT_WS(' ', c.nombre, c.numero";
    if ($has('direccion')) $searchBlobExpr .= ", c.direccion";
    if ($has('telefono'))  $searchBlobExpr .= ", c.telefono";
    if ($has('email'))     $searchBlobExpr .= ", c.email";
    $searchBlobExpr .= "))";

    if ($search !== '') {
        $searchTerms = preg_split('/\s+/', $search) ?: [];
        $searchTerms = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $searchTerms), static fn($v) => $v !== ''));
        if (empty($searchTerms)) {
            $searchTerms = [$search];
        }

        $searchableColumns = ["c.nombre", "c.numero"];
        if ($has('direccion')) $searchableColumns[] = "c.direccion";
        if ($has('telefono'))  $searchableColumns[] = "c.telefono";
        if ($has('email'))     $searchableColumns[] = "c.email";

        foreach ($searchTerms as $idx => $term) {
            $termClauses = [];
            foreach ($searchableColumns as $colIdx => $columnExpr) {
                $paramKey = ":st_{$idx}_{$colIdx}";
                $termClauses[] = "{$columnExpr} LIKE {$paramKey}";
                $params[$paramKey] = '%' . $term . '%';
            }
            $blobParamKey = ":st_blob_{$idx}";
            $termClauses[] = "{$searchBlobExpr} LIKE {$blobParamKey}";
            $params[$blobParamKey] = '%' . $term . '%';
            $where[] = "(" . implode(" OR ", $termClauses) . ")";
        }
    }

    $whereSQL = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    // Order mapping - solo columnas que existen
    $orderMap = ['nombre' => 'c.nombre', 'id' => 'c.id', 'cuenta' => 'c.cuenta'];
    if ($has('numero'))    $orderMap['numero'] = 'c.numero';
    if ($has('direccion')) $orderMap['direccion'] = 'c.direccion';
    if ($hasExtracto('codigo')) {
        $orderMap['saldo'] = 'COALESCE(xs.saldo_ledger, c.saldo_guaranies, c.saldo, 0)';
    } elseif ($has('saldo')) {
        $orderMap['saldo'] = 'c.saldo';
    }
    if ($has('fecha'))     $orderMap['fecha'] = 'c.fecha';
    $orderCol = $orderMap[$order] ?? 'c.nombre';

    // Count
    $countSQL = "SELECT COUNT(*) as total FROM {$db}.clientes c {$whereSQL}";
    $stmtCount = $pdo->prepare($countSQL);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetch()['total'];

    // SELECT dinámico - solo columnas que existen
    $selectCols = ["c.id", "c.nombre"];

    // Columnas opcionales (todas)
    $optionalCols = [
        'cuenta', 'sucursal', 'fecha', 'documento', 'numero', 'direccion', 'estado',
        'saldo', 'saldo_guaranies', 'saldo_dolares', 'saldo_reales',
        'raiting', 'obs', 'llave', 'telefono', 'email',
        'ciudad', 'pais', 'fecha_nacimiento', 'linea_credito',
        'vendedor_asignado', 'complemento_direccion1', 'complemento_direccion2',
        'numero_casa'
    ];
    foreach ($optionalCols as $col) {
        if ($has($col)) $selectCols[] = "c.{$col}";
    }

    // Cuenta nombre (JOIN cuentas si existe tabla Y columna cuenta)
    $hasCuentasTable = false;
    try {
        $chk = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'cuentas' LIMIT 1");
        $hasCuentasTable = (bool)$chk->fetch();
    } catch(Exception $e) {}

    $joinSQL = '';
    if ($hasCuentasTable && $has('cuenta')) {
        $selectCols[] = "cu.cuenta AS cuenta_nombre";
        $joinSQL = "LEFT JOIN {$db}.cuentas cu ON cu.id = c.cuenta";
    }

    if ($hasExtracto('codigo')) {
        $saldoExpr = 'SUM(COALESCE(ec.debito, 0) - COALESCE(ec.credito, 0))';
        $saldoWhere = '';
        if ($hasExtracto('estado')) {
            $saldoWhere = 'WHERE COALESCE(ec.estado, 0) = 0';
        }
        $joinSQL .= " LEFT JOIN (
            SELECT
                ec.codigo,
                {$saldoExpr} AS saldo_ledger
            FROM {$db}.extracto_cliente ec
            {$saldoWhere}
            GROUP BY ec.codigo
        ) xs ON xs.codigo = c.id";
        $selectCols[] = "COALESCE(xs.saldo_ledger, c.saldo_guaranies, c.saldo, 0) AS saldo_guaranies";
        $selectCols[] = "COALESCE(xs.saldo_ledger, c.saldo, 0) AS saldo";
    }

    $relevanceOrderSql = '';
    if ($search !== '') {
        $searchExact = ":search_exact";
        $searchPrefix = ":search_prefix";
        $params[$searchExact] = $search;
        $params[$searchPrefix] = $search . '%';

        $relevanceParts = [
            "CASE WHEN c.nombre = {$searchExact} THEN 0 ELSE 1 END",
            "CASE WHEN c.numero = {$searchExact} THEN 0 ELSE 1 END",
            "CASE WHEN c.nombre LIKE {$searchPrefix} THEN 0 ELSE 1 END",
            "CASE WHEN c.numero LIKE {$searchPrefix} THEN 0 ELSE 1 END",
            "CASE WHEN {$searchBlobExpr} LIKE {$searchPrefix} THEN 0 ELSE 1 END"
        ];

        foreach ($searchTerms as $idx => $term) {
            $paramPrefix = ":search_term_prefix_{$idx}";
            $paramAnywhere = ":search_term_any_{$idx}";
            $params[$paramPrefix] = $term . '%';
            $params[$paramAnywhere] = '%' . $term . '%';
            $relevanceParts[] = "CASE WHEN c.nombre LIKE {$paramPrefix} THEN 0 ELSE 1 END";
            $relevanceParts[] = "CASE WHEN c.numero LIKE {$paramPrefix} THEN 0 ELSE 1 END";
            $relevanceParts[] = "CASE WHEN c.nombre LIKE {$paramAnywhere} THEN 0 ELSE 1 END";
            $relevanceParts[] = "CASE WHEN {$searchBlobExpr} LIKE {$paramAnywhere} THEN 0 ELSE 1 END";
        }

        $relevanceOrderSql = implode(', ', $relevanceParts) . ', ';
    }

    $sql = "SELECT " . implode(", ", $selectCols) . "
            FROM {$db}.clientes c
            {$joinSQL}
            {$whereSQL}
            ORDER BY {$relevanceOrderSql}{$orderCol} {$dir}";

    if (!$fullTableSearch && !$fetchAll) {
        $sql .= "
            LIMIT :lim OFFSET :off";
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    if (!$fullTableSearch && !$fetchAll) {
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $contactos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalizar defaults
    $defaults = [
        'cuenta' => 3, 'sucursal' => 1, 'fecha' => null, 'documento' => '', 'numero' => '', 
        'direccion' => '', 'estado' => 1, 'saldo' => 0, 'saldo_guaranies' => 0,
        'saldo_dolares' => 0, 'saldo_reales' => 0, 'raiting' => 1, 'obs' => '',
        'llave' => '', 'telefono' => '', 'email' => '', 'ciudad' => '', 'pais' => '',
        'fecha_nacimiento' => null, 'linea_credito' => 0, 'vendedor_asignado' => 0,
        'complemento_direccion1' => '', 'complemento_direccion2' => '', 'numero_casa' => '',
        'cuenta_nombre' => ''
    ];
    foreach ($contactos as &$c) {
        foreach ($defaults as $key => $def) {
            if (!isset($c[$key])) $c[$key] = $def;
        }
        $cuentaMap = [3 => 'Cliente', 4 => 'Proveedor', 5 => 'Empleado'];
        $c['cuenta_tipo'] = $cuentaMap[(int)$c['cuenta']] ?? ($c['cuenta_nombre'] ?: 'Otro');
    }
    unset($c);

    // Stats
    $statsSelect = "COUNT(*) as total";
    if ($has('estado')) {
        $statsSelect .= ", SUM(CASE WHEN estado = 1 THEN 1 ELSE 0 END) as activos";
    }
    if ($has('cuenta')) {
        $statsSelect .= ", SUM(CASE WHEN cuenta = 3 THEN 1 ELSE 0 END) as clientes, SUM(CASE WHEN cuenta = 4 THEN 1 ELSE 0 END) as proveedores, SUM(CASE WHEN cuenta = 5 THEN 1 ELSE 0 END) as empleados";
    }
    $statsSQL = "SELECT {$statsSelect} FROM {$db}.clientes";
    $statsRow = $pdo->query($statsSQL)->fetch(PDO::FETCH_ASSOC);

    $effectivePerPage = ($fullTableSearch || $fetchAll) ? max($total, 1) : $limit;
    $effectivePage = ($fullTableSearch || $fetchAll) ? 1 : $page;
    $totalPages = ($fullTableSearch || $fetchAll) ? 1 : max(1, (int)ceil($total / $limit));

    echo json_encode([
        'success' => true,
        'data' => $contactos,
        'pagination' => [
            'page' => $effectivePage,
            'per_page' => $effectivePerPage,
            'total' => $total,
            'total_pages' => $totalPages
        ],
        'stats' => [
            'total'       => (int)($statsRow['total'] ?? 0),
            'activos'     => (int)($statsRow['activos'] ?? 0),
            'clientes'    => (int)($statsRow['clientes'] ?? 0),
            'proveedores' => (int)($statsRow['proveedores'] ?? 0),
            'empleados'   => (int)($statsRow['empleados'] ?? 0),
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
