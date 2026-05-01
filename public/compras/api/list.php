<?php
/**
 * API - Listado de Compras
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/bootstrap.php';

Session::start();

Permission::requireAccess('app_grid_factura_compras');

$id_empresa = isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : Session::get('id_empresa', 169);

// Parámetros de paginación y filtros
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? min(100, max(10, (int)$_GET['per_page'])) : 20;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$fechaDesde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$fechaHasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';
$estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$sortBy = isset($_GET['sort_by']) ? trim((string)$_GET['sort_by']) : 'fecha';
$sortDir = strtolower(trim((string)($_GET['sort_dir'] ?? 'desc')));
$sortDir = $sortDir === 'asc' ? 'ASC' : 'DESC';

$offset = ($page - 1) * $perPage;

try {
    // Obtener datos de la empresa desde master
    $masterPdo = Database::getMasterConnection();
    $stmt = $masterPdo->prepare("SELECT * FROM empresa WHERE id_empresa = :id");
    $stmt->execute([':id' => $id_empresa]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$empresa) {
        throw new Exception("Empresa no encontrada");
    }
    
    // Conectar a la base de datos de la empresa
    $dbHost = !empty($empresa['server']) ? $empresa['server'] : 'localhost';
    $dbUser = !empty($empresa['user']) ? $empresa['user'] : 'sistemax';
    $dbPass = 'Armagedon123';
    $dbName = !empty($empresa['dbase']) ? $empresa['dbase'] : 'serproc1';
    
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser, $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    
    // Construir WHERE
    $where = ['1=1'];
    $params = [];
    
    if ($search) {
        $where[] = "(fc.nro_factura LIKE :search OR p.nombre LIKE :search2 OR p.numero LIKE :search3)";
        $params[':search'] = "%{$search}%";
        $params[':search2'] = "%{$search}%";
        $params[':search3'] = "%{$search}%";
    }
    
    if ($fechaDesde) {
        $where[] = "DATE(fc.fecha) >= :fecha_desde";
        $params[':fecha_desde'] = $fechaDesde;
    }
    
    if ($fechaHasta) {
        $where[] = "DATE(fc.fecha) <= :fecha_hasta";
        $params[':fecha_hasta'] = $fechaHasta;
    }
    
    if ($estado === 'activo') {
        $where[] = "fc.estado = 1";
    } elseif ($estado === 'anulado') {
        $where[] = "fc.estado = 0";
    }
    
    $whereClause = implode(' AND ', $where);
    $allowedSorts = [
        'id_factura' => 'fc.id_factura',
        'nro_factura' => 'fc.nro_factura',
        'fecha' => 'fc.fecha',
        'proveedor' => 'p.nombre',
        'proveedor_nombre' => 'p.nombre',
        'total' => 'fc.total',
        'pendiente' => 'fc.pendiente',
        'estado' => 'fc.estado',
    ];
    $orderBy = $allowedSorts[$sortBy] ?? 'fc.fecha';
    
    // Contar total
    $countSql = "SELECT COUNT(*) FROM factura_compras fc 
                 LEFT JOIN clientes p ON p.id = fc.id_cliente 
                 WHERE {$whereClause}";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();
    
    // Obtener compras
    $sql = "SELECT 
                fc.id_factura,
                fc.nro_factura,
                fc.fecha,
                fc.timbrado,
                fc.vencimiento,
                fc.total,
                fc.pagado,
                fc.pendiente,
                fc.estado,
                fc.forma_pago,
                fc.exenta,
                fc.iva5,
                fc.iva10,
                fc.nota,
                p.nombre AS proveedor_nombre,
                p.numero AS proveedor_ruc
            FROM factura_compras fc
            LEFT JOIN clientes p ON p.id = fc.id_cliente
            WHERE {$whereClause}
            ORDER BY {$orderBy} {$sortDir}, fc.id_factura DESC
            LIMIT {$perPage} OFFSET {$offset}";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $compras = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas
    $statsSql = "SELECT 
                    COUNT(*) as total_compras,
                    COALESCE(SUM(total), 0) as monto_total,
                    SUM(CASE WHEN DATE(fecha) = CURDATE() THEN 1 ELSE 0 END) as compras_hoy,
                    COALESCE(SUM(pendiente), 0) as total_pendiente
                 FROM factura_compras fc
                 WHERE fc.estado = 1";
    
    // Aplicar filtros de fecha a stats si existen
    $statsParams = [];
    if ($fechaDesde) {
        $statsSql .= " AND DATE(fc.fecha) >= :fecha_desde";
        $statsParams[':fecha_desde'] = $fechaDesde;
    }
    if ($fechaHasta) {
        $statsSql .= " AND DATE(fc.fecha) <= :fecha_hasta";
        $statsParams[':fecha_hasta'] = $fechaHasta;
    }
    
    $stmtStats = $pdo->prepare($statsSql);
    $stmtStats->execute($statsParams);
    $statsRow = $stmtStats->fetch(PDO::FETCH_ASSOC);
    
    $stats = [
        'totalCompras' => (int)$statsRow['total_compras'],
        'montoTotal' => (float)$statsRow['monto_total'],
        'comprasHoy' => (int)$statsRow['compras_hoy'],
        'totalPendiente' => (float)$statsRow['total_pendiente']
    ];
    
    echo json_encode([
        'success' => true,
        'compras' => $compras,
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
