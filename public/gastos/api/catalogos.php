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

    $cajas = [];
    try {
        $stmtC = $pdo->query("SELECT id_caja, caja FROM {$db}.cajas ORDER BY caja");
        $cajas = $stmtC->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cajas = [];
    }

    $pagadoPorSugeridos = [];
    try {
        $stmtS = $pdo->query("SELECT pagado_por_detalle, COUNT(*) AS c
            FROM {$db}.gastos_empresa
            WHERE pagado_por_detalle IS NOT NULL AND pagado_por_detalle <> ''
            GROUP BY pagado_por_detalle
            ORDER BY c DESC
            LIMIT 20");
        $pagadoPorSugeridos = array_values(array_filter(array_map(static function ($r) {
            return $r['pagado_por_detalle'] ?? '';
        }, $stmtS->fetchAll(PDO::FETCH_ASSOC))));
    } catch (Throwable $e) {
        $pagadoPorSugeridos = [];
    }

    echo json_encode([
        'ok' => true,
        'cajas' => $cajas,
        'pagado_por_sugeridos' => $pagadoPorSugeridos,
        'tipos_pago' => ['CAJA', 'TARJETA', 'TRANSFERENCIA', 'PROVEEDOR', 'OTRO'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
