<?php
/**
 * API - Listado de Ventas con Filtros
 * Para ventas_mobile.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';

$action = $_GET['action'] ?? '';
$id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];
    
    if ($action === 'list') {
        $query = trim($_GET['q'] ?? '');
        $periodo = $_GET['periodo'] ?? 'mes';
        $estado = $_GET['estado'] ?? 'activo';
        $fechaDesde = $_GET['fecha_desde'] ?? '';
        $fechaHasta = $_GET['fecha_hasta'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(10, (int)($_GET['limit'] ?? 30)));
        $offset = ($page - 1) * $limit;
        
        // Construir condiciones de fecha según período
        $fechaCondition = '';
        $params = [];
        
        switch ($periodo) {
            case 'hoy':
                $fechaCondition = "AND DATE(f.fecha) = CURDATE()";
                break;
            case 'semana':
                $fechaCondition = "AND f.fecha >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND f.fecha < DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)";
                break;
            case 'mes':
                $fechaCondition = "AND YEAR(f.fecha) = YEAR(CURDATE()) AND MONTH(f.fecha) = MONTH(CURDATE())";
                break;
            case 'anio':
                $fechaCondition = "AND YEAR(f.fecha) = YEAR(CURDATE())";
                break;
            case 'custom':
                if (!empty($fechaDesde) && !empty($fechaHasta)) {
                    $fechaCondition = "AND DATE(f.fecha) BETWEEN :fecha_desde AND :fecha_hasta";
                    $params[':fecha_desde'] = $fechaDesde;
                    $params[':fecha_hasta'] = $fechaHasta;
                } elseif (!empty($fechaDesde)) {
                    $fechaCondition = "AND DATE(f.fecha) >= :fecha_desde";
                    $params[':fecha_desde'] = $fechaDesde;
                } elseif (!empty($fechaHasta)) {
                    $fechaCondition = "AND DATE(f.fecha) <= :fecha_hasta";
                    $params[':fecha_hasta'] = $fechaHasta;
                }
                break;
        }
        
        // Condición de estado
        $estadoCondition = '';
        switch ($estado) {
            case 'activo':
                $estadoCondition = "AND f.estado = 0";
                break;
            case 'anulado':
                $estadoCondition = "AND f.estado = 1";
                break;
            // 'todos' no agrega condición
        }
        
        // Condición de búsqueda
        $searchCondition = '';
        if (!empty($query)) {
            $searchCondition = "AND (
                f.nro_factura LIKE :q 
                OR c.nombre LIKE :q 
                OR c.numero LIKE :q 
                OR CAST(f.total AS CHAR) LIKE :q
            )";
            $params[':q'] = '%' . $query . '%';
        }
        
        // Query principal
        $sql = "SELECT 
                    f.id_factura,
                    f.nro_factura,
                    f.fecha,
                    f.total,
                    f.tipo_documento,
                    f.estado,
                    c.nombre AS cliente,
                    c.numero AS cliente_ruc
                FROM $dbName.factura_ventas f
                LEFT JOIN $dbName.clientes c ON c.id = f.id_cliente
                WHERE 1=1
                $fechaCondition
                $estadoCondition
                $searchCondition
                ORDER BY f.fecha DESC, f.id_factura DESC
                LIMIT $limit OFFSET $offset";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Verificar si hay más resultados
        $sqlCount = "SELECT COUNT(*) FROM $dbName.factura_ventas f
                     LEFT JOIN $dbName.clientes c ON c.id = f.id_cliente
                     WHERE 1=1 $fechaCondition $estadoCondition $searchCondition";
        $stmtCount = $pdo->prepare($sqlCount);
        $stmtCount->execute($params);
        $totalCount = $stmtCount->fetchColumn();
        
        $hasMore = ($offset + count($ventas)) < $totalCount;
        
        $response = [
            'success' => true,
            'ventas' => $ventas,
            'total' => $totalCount,
            'page' => $page,
            'hasMore' => $hasMore
        ];
        if (isset($_GET['debug']) && (string)$_GET['debug'] === '1') {
            $response['_debug'] = [
                'db_host' => $conn['dbHost'] ?? null,
                'db_name' => $dbName,
                'id_empresa' => $id_empresa,
            ];
        }

        echo json_encode($response);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
