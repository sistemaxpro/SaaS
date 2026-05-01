<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requirePermission('app_grid_caja', 'priv_insert');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    exit;
}

$id_empresa = (int)($input['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$id_login = (int)($_SESSION['id_login'] ?? 0);
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 1);

$concepto = trim((string)($input['concepto'] ?? ''));
$monto = (float)($input['monto'] ?? 0);
$pagadoPorTipo = strtoupper(trim((string)($input['pagado_por_tipo'] ?? 'CAJA')));
$pagadoPorDetalle = trim((string)($input['pagado_por_detalle'] ?? ''));
$idCaja = (int)($input['id_caja'] ?? 0);
$observacion = trim((string)($input['observacion'] ?? ''));
$origen = strtoupper(trim((string)($input['origen'] ?? 'MANUAL')));

$tiposPermitidos = ['CAJA', 'TARJETA', 'TRANSFERENCIA', 'PROVEEDOR', 'OTRO'];
if ($concepto === '') {
    echo json_encode(['ok' => false, 'error' => 'El concepto es obligatorio']);
    exit;
}
if ($monto <= 0) {
    echo json_encode(['ok' => false, 'error' => 'El monto debe ser mayor a 0']);
    exit;
}
if (!in_array($pagadoPorTipo, $tiposPermitidos, true)) {
    $pagadoPorTipo = 'OTRO';
}
if (!in_array($origen, ['MANUAL', 'VOZ', 'IMPORTADO'], true)) {
    $origen = 'MANUAL';
}

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

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO {$db}.gastos_empresa (
        id_empresa, id_sucursal, id_caja, concepto, observacion, monto,
        pagado_por_tipo, pagado_por_detalle, origen, estado, creado_por
    ) VALUES (
        :id_empresa, :id_sucursal, :id_caja, :concepto, :observacion, :monto,
        :pagado_por_tipo, :pagado_por_detalle, :origen, 'ACTIVO', :creado_por
    )");

    $stmt->execute([
        ':id_empresa' => $id_empresa,
        ':id_sucursal' => $id_sucursal,
        ':id_caja' => $idCaja > 0 ? $idCaja : null,
        ':concepto' => mb_substr($concepto, 0, 140),
        ':observacion' => $observacion !== '' ? mb_substr($observacion, 0, 255) : null,
        ':monto' => $monto,
        ':pagado_por_tipo' => $pagadoPorTipo,
        ':pagado_por_detalle' => $pagadoPorDetalle !== '' ? mb_substr($pagadoPorDetalle, 0, 120) : null,
        ':origen' => $origen,
        ':creado_por' => $id_login > 0 ? $id_login : null,
    ]);

    $idGasto = (int)$pdo->lastInsertId();

    // Registrar salida en extracto_caja si corresponde y existe caja
    if ($idCaja > 0 && $pagadoPorTipo === 'CAJA') {
        try {
            $hasExtracto = (bool)$pdo->query("SHOW TABLES LIKE 'extracto_caja'")->fetchColumn();
            if ($hasExtracto) {
                $opCode = 2; // Operación genérica de caja
                $refCode = 15; // Pago varios
                $stmtExt = $pdo->prepare("INSERT INTO {$db}.extracto_caja (
                    sucursal, operacion, codigo, concepto, descripcion,
                    medio_cobro, credito, debito, saldo, login,
                    comprobante, beneficiario, fecha, cantidad, moneda, cambio,
                    referencia, tabla_relacion, extracto_relacion, codigo_relacion,
                    estado, conciliado
                ) VALUES (
                    :sucursal, :operacion, :codigo, :concepto, :descripcion,
                    'EFECTIVO', 0, :debito, 0, :login,
                    NULL, :beneficiario, NOW(), :cantidad, 1, 1,
                    :referencia, 'gastos_empresa', NULL, :codigo_relacion,
                    1, 0
                )");
                $stmtExt->execute([
                    ':sucursal' => $id_sucursal,
                    ':operacion' => $opCode,
                    ':codigo' => $idCaja,
                    ':concepto' => mb_substr('GASTO | ' . $concepto, 0, 60),
                    ':descripcion' => mb_substr($observacion, 0, 80),
                    ':debito' => $monto,
                    ':login' => $id_login,
                    ':beneficiario' => $pagadoPorDetalle !== '' ? mb_substr($pagadoPorDetalle, 0, 80) : null,
                    ':cantidad' => $monto,
                    ':referencia' => $refCode,
                    ':codigo_relacion' => $idGasto,
                ]);
            }
        } catch (Throwable $eExtracto) {
            error_log('[Gastos] No se pudo registrar en extracto_caja: ' . $eExtracto->getMessage());
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'msg' => 'Gasto ingresado con éxito',
        'id_gasto' => $idGasto,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
