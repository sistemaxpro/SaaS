<?php
/**
 * API: Listar surtidores
 */

require_once __DIR__ . '/../../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    Session::requireLogin();
    Permission::requireAccess('app_grid_estacion');

    $pdo = Database::getSessionEmpresaConnection();

    $page = (int)($_GET['page'] ?? 1);
    $per_page = (int)($_GET['per_page'] ?? 25);
    $offset = ($page - 1) * $per_page;
    $sucursalCols = $pdo->query("SHOW COLUMNS FROM sucursales")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $sucursalIdCol = in_array('id_sucursal', $sucursalCols, true) ? 'id_sucursal' : (in_array('id', $sucursalCols, true) ? 'id' : 'id_sucursal');
    $sucursalNameCol = in_array('nombre', $sucursalCols, true)
        ? 'nombre'
        : (in_array('sucursal', $sucursalCols, true) ? 'sucursal' : $sucursalIdCol);

    // Contar total
    $stmt = $pdo->query("SELECT COUNT(*) FROM estacion_surtidores");
    $total = (int)$stmt->fetchColumn();

    // Obtener surtidores con paginación
    $stmt = $pdo->query("SELECT
        s.id, s.nombre, s.nro_surtidor, s.id_sucursal,
        s.tipo_control, s.controladora_ip, s.controladora_puerto,
        s.controladora_protocolo, s.activo,
        COALESCE(suc.{$sucursalNameCol}, 'N/A') AS sucursal,
        COUNT(p.id) AS cant_picos
    FROM estacion_surtidores s
    LEFT JOIN sucursales suc ON s.id_sucursal = suc.{$sucursalIdCol}
    LEFT JOIN estacion_picos p ON s.id = p.id_surtidor
    GROUP BY s.id
    ORDER BY s.nro_surtidor
    LIMIT $offset, $per_page");

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok' => true,
        'data' => $data,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $per_page)
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
