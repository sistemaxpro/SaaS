<?php
/**
 * API - Listado de Ventas
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/bootstrap.php';

Session::start();

Permission::requireAccess('app_grid_factura_venta_global');

$id_empresa = (int)Session::get('id_empresa');
if ($id_empresa <= 0) {
    throw new Exception('Empresa no definida en sesión');
}

// Parámetros de paginación y filtros
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? min(100, max(10, (int)$_GET['per_page'])) : 20;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$fechaDesde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$fechaHasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';
$estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$filterIdLogin = isset($_GET['id_login']) ? (int)$_GET['id_login'] : 0;
$filterIdCaja = isset($_GET['id_caja']) ? (int)$_GET['id_caja'] : 0;

$offset = ($page - 1) * $perPage;

try {
    $empresa = Database::getEmpresaInfo($id_empresa);
    if (!$empresa || empty($empresa['dbase'])) {
        throw new Exception("Empresa no encontrada");
    }
    $pdo = Database::getEmpresaConnection($id_empresa);
    $dbName = (string)$empresa['dbase'];

    $ventasTable = null;
    foreach (['factura_ventas', 'factura_venta'] as $candidateTable) {
        $stTbl = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($candidateTable));
        if ($stTbl->fetchColumn()) {
            $ventasTable = $candidateTable;
            break;
        }
    }
    if ($ventasTable === null) {
        throw new Exception("No existe tabla de ventas compatible en {$dbName}");
    }
    
    // Columnas opcionales (según estructura de cada empresa)
    $columns = [];
    $stCols = $pdo->query("DESCRIBE {$ventasTable}");
    while ($col = $stCols->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = strtolower((string)($col['Field'] ?? ''));
    }
    $hasTipoDocumento = in_array('tipo_documento', $columns, true);
    $hasEstadoSifen = in_array('estado_sifen', $columns, true);

    $estadoProfileSql = "SELECT
            SUM(CASE WHEN LOWER(TRIM(CAST(fv.estado AS CHAR))) = '0' THEN 1 ELSE 0 END) AS count_0,
            SUM(CASE WHEN LOWER(TRIM(CAST(fv.estado AS CHAR))) = '1' THEN 1 ELSE 0 END) AS count_1,
            SUM(CASE WHEN LOWER(TRIM(CAST(fv.estado AS CHAR))) IN ('activo', 'activa') THEN 1 ELSE 0 END) AS count_activo_texto,
            SUM(CASE WHEN LOWER(TRIM(CAST(fv.estado AS CHAR))) IN ('anulado', 'anulada') THEN 1 ELSE 0 END) AS count_anulado_texto,
            SUM(CASE WHEN fv.fecha_anulacion IS NOT NULL THEN 1 ELSE 0 END) AS count_fecha_anulacion,
            SUM(CASE WHEN fv.fecha_anulacion IS NOT NULL AND LOWER(TRIM(CAST(fv.estado AS CHAR))) = '0' THEN 1 ELSE 0 END) AS count_fecha_0,
            SUM(CASE WHEN fv.fecha_anulacion IS NOT NULL AND LOWER(TRIM(CAST(fv.estado AS CHAR))) = '1' THEN 1 ELSE 0 END) AS count_fecha_1
        FROM {$ventasTable} fv";
    $estadoProfile = $pdo->query($estadoProfileSql)->fetch(PDO::FETCH_ASSOC) ?: [];
    $countEstado0 = (int)($estadoProfile['count_0'] ?? 0);
    $countEstado1 = (int)($estadoProfile['count_1'] ?? 0);
    $countAnuladoTexto = (int)($estadoProfile['count_anulado_texto'] ?? 0);
    $countFechaAnulacion = (int)($estadoProfile['count_fecha_anulacion'] ?? 0);
    $countFecha0 = (int)($estadoProfile['count_fecha_0'] ?? 0);
    $countFecha1 = (int)($estadoProfile['count_fecha_1'] ?? 0);
    $activeNumericStates = [];
    $anuladoNumericStates = [];
    if ($countFecha0 > $countFecha1) {
        $activeNumericStates = ['1'];
        $anuladoNumericStates = ['0'];
    } elseif ($countFecha1 > $countFecha0) {
        $activeNumericStates = ['0'];
        $anuladoNumericStates = ['1'];
    } elseif ($countFechaAnulacion === 0 && $countAnuladoTexto === 0) {
        if ($countEstado0 > 0) {
            $activeNumericStates[] = '0';
        }
        if ($countEstado1 > 0) {
            $activeNumericStates[] = '1';
        }
    } elseif ($countEstado0 > 0 || $countEstado1 > 0) {
        $activeNumericStates[] = $countEstado1 > $countEstado0 ? '1' : '0';
        $anuladoCandidate = $activeNumericStates[0] === '1' ? '0' : '1';
        if (($anuladoCandidate === '0' && $countEstado0 > 0) || ($anuladoCandidate === '1' && $countEstado1 > 0)) {
            $anuladoNumericStates[] = $anuladoCandidate;
        }
    }
    $estadoSqlExpr = static function (string $field, string $target, array $activeNumericStates, array $anuladoNumericStates): string {
        $fieldTxt = "LOWER(TRIM(CAST({$field} AS CHAR)))";
        $parts = [];
        if ($target === 'activo') {
            foreach ($activeNumericStates as $state) {
                $parts[] = "{$fieldTxt} = '{$state}'";
            }
            $parts[] = "{$field} IS NULL";
            $parts[] = "{$fieldTxt} = ''";
            $parts[] = "{$fieldTxt} IN ('activo', 'activa')";
        } else {
            foreach ($anuladoNumericStates as $state) {
                $parts[] = "{$fieldTxt} = '{$state}'";
            }
            $parts[] = "{$fieldTxt} IN ('anulado', 'anulada')";
        }
        return '(' . implode(' OR ', array_unique($parts)) . ')';
    };
    $estadoActivoExpr = $estadoSqlExpr('fv.estado', 'activo', $activeNumericStates, $anuladoNumericStates);
    $estadoAnuladoExpr = $estadoSqlExpr('fv.estado', 'anulado', $activeNumericStates, $anuladoNumericStates);
    $estadoUiExpr = "CASE
        WHEN {$estadoActivoExpr} THEN 'activo'
        WHEN {$estadoAnuladoExpr} THEN 'anulado'
        ELSE 'activo'
    END";

    $clienteJoin = "LEFT JOIN clientes c ON c.id = fv.id_cliente";
    $clienteNombreExpr = "c.nombre";
    $clienteRucExpr = "c.numero";
    $searchFields = ["fv.nro_factura"];
    $hasClientesTable = (bool)$pdo->query("SHOW TABLES LIKE 'clientes'")->fetchColumn();
    if ($hasClientesTable) {
        $clientColumns = [];
        $stClientCols = $pdo->query("DESCRIBE clientes");
        while ($col = $stClientCols->fetch(PDO::FETCH_ASSOC)) {
            $clientColumns[] = strtolower((string)($col['Field'] ?? ''));
        }
        $clienteIdCol = in_array('id', $clientColumns, true) ? 'id' : (in_array('id_cliente', $clientColumns, true) ? 'id_cliente' : null);
        $clienteNombreCol = in_array('nombre', $clientColumns, true) ? 'nombre' : (in_array('razon_social', $clientColumns, true) ? 'razon_social' : null);
        $clienteNumeroCol = in_array('numero', $clientColumns, true) ? 'numero' : (in_array('ruc', $clientColumns, true) ? 'ruc' : null);

        if ($clienteIdCol !== null && in_array('id_cliente', $columns, true)) {
            $clienteNombreAgg = $clienteNombreCol !== null
                ? "COALESCE(
                    MAX(CASE
                        WHEN NULLIF(TRIM({$clienteNombreCol}), '') IS NOT NULL
                         AND UPPER(TRIM({$clienteNombreCol})) NOT IN ('SIN NOMBRE', 'CONSUMIDOR FINAL')
                        THEN TRIM({$clienteNombreCol})
                    END),
                    MAX(NULLIF(TRIM({$clienteNombreCol}), '')),
                    'Consumidor Final'
                )"
                : "'Consumidor Final'";
            $clienteRucAgg = $clienteNumeroCol !== null
                ? "COALESCE(
                    MAX(CASE
                        WHEN NULLIF(TRIM({$clienteNumeroCol}), '') IS NOT NULL
                         AND UPPER(TRIM({$clienteNumeroCol})) NOT IN ('X', '0')
                        THEN TRIM({$clienteNumeroCol})
                    END),
                    MAX(NULLIF(TRIM({$clienteNumeroCol}), ''))
                )"
                : "NULL";
            $clienteJoin = "LEFT JOIN (
                    SELECT
                        {$clienteIdCol} AS cliente_id_ref,
                        {$clienteNombreAgg} AS cliente_nombre,
                        {$clienteRucAgg} AS cliente_ruc
                    FROM clientes
                    GROUP BY {$clienteIdCol}
                ) c ON c.cliente_id_ref = fv.id_cliente";
            $clienteNombreExpr = "c.cliente_nombre";
            $clienteRucExpr = "c.cliente_ruc";
            $searchFields[] = $clienteNombreExpr;
            $searchFields[] = $clienteRucExpr;
        } else {
            $clienteJoin = "";
            $clienteNombreExpr = "NULL";
            $clienteRucExpr = "NULL";
        }
    } else {
        $clienteJoin = "";
        $clienteNombreExpr = "NULL";
        $clienteRucExpr = "NULL";
    }
    
    // Construir WHERE
    $where = ['1=1'];
    $params = [];
    
    if ($search) {
        $searchConditions = [];
        foreach ($searchFields as $idx => $field) {
            $paramKey = ':search' . $idx;
            $searchConditions[] = "{$field} LIKE {$paramKey}";
            $params[$paramKey] = "%{$search}%";
        }
        $where[] = '(' . implode(' OR ', $searchConditions) . ')';
    }
    
    if ($fechaDesde) {
        $where[] = "DATE(fv.fecha) >= :fecha_desde";
        $params[':fecha_desde'] = $fechaDesde;
    }
    
    if ($fechaHasta) {
        $where[] = "DATE(fv.fecha) <= :fecha_hasta";
        $params[':fecha_hasta'] = $fechaHasta;
    }
    
    if ($estado === 'activo') {
        $where[] = $estadoActivoExpr;
    } elseif ($estado === 'anulado') {
        $where[] = $estadoAnuladoExpr;
    }

    if ($filterIdLogin > 0 && in_array('id_login', $columns, true)) {
        $where[] = "fv.id_login = :filter_id_login";
        $params[':filter_id_login'] = $filterIdLogin;
    }
    if ($filterIdCaja > 0 && in_array('id_caja', $columns, true)) {
        $where[] = "fv.id_caja = :filter_id_caja";
        $params[':filter_id_caja'] = $filterIdCaja;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Contar total
    $countSql = "SELECT COUNT(DISTINCT fv.id_factura) FROM {$ventasTable} fv
                 {$clienteJoin}
                 WHERE {$whereClause}";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();
    
    // Obtener ventas
    $sql = "SELECT 
                fv.id_factura,
                fv.nro_factura,
                fv.fecha,
                fv.total,
                fv.estado,
                {$estadoUiExpr} AS estado_ui,
                fv.forma_pago,
                " . ($hasTipoDocumento ? "fv.tipo_documento" : "0 AS tipo_documento") . ",
                " . ($hasEstadoSifen ? "fv.estado_sifen" : "NULL AS estado_sifen") . ",
                fv.fecha_anulacion,
                {$clienteNombreExpr} AS cliente_nombre,
                {$clienteRucExpr} AS cliente_ruc
            FROM {$ventasTable} fv
            {$clienteJoin}
            WHERE {$whereClause}
            ORDER BY fv.id_factura DESC, fv.fecha DESC
            LIMIT {$perPage} OFFSET {$offset}";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas
    $statsSql = "SELECT 
                    COUNT(*) as total_ventas,
                    COALESCE(SUM(total), 0) as monto_total,
                    SUM(CASE WHEN DATE(fecha) = CURDATE() THEN 1 ELSE 0 END) as ventas_hoy
                 FROM {$ventasTable} fv
                 WHERE {$estadoActivoExpr}";
    
    // Aplicar filtros de fecha a stats si existen
    $statsParams = [];
    if ($fechaDesde) {
        $statsSql .= " AND DATE(fv.fecha) >= :fecha_desde";
        $statsParams[':fecha_desde'] = $fechaDesde;
    }
    if ($fechaHasta) {
        $statsSql .= " AND DATE(fv.fecha) <= :fecha_hasta";
        $statsParams[':fecha_hasta'] = $fechaHasta;
    }
    
    $stmtStats = $pdo->prepare($statsSql);
    $stmtStats->execute($statsParams);
    $statsRow = $stmtStats->fetch(PDO::FETCH_ASSOC);
    
    $stats = [
        'totalVentas' => (int)$statsRow['total_ventas'],
        'montoTotal' => (float)$statsRow['monto_total'],
        'ventasHoy' => (int)$statsRow['ventas_hoy'],
        'promedio' => $statsRow['total_ventas'] > 0 ? round($statsRow['monto_total'] / $statsRow['total_ventas']) : 0
    ];
    
    echo json_encode([
        'success' => true,
        'ventas' => $ventas,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'stats' => $stats
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
