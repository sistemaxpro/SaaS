<?php

/**
 * Emitir Recibo de Dinero - Controller
 * Para cobros de facturas electrónicas a crédito
 * 
 * Método: POST JSON
 * Parámetros:
 *   - id_factura: ID de la factura
 *   - monto: Monto cobrado
 *   - forma_pago: Efectivo, Transferencia, Cheque, etc.
 *   - observacion: Nota opcional
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Recibir datos
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (empty($input)) $input = $_POST;
if (empty($input)) $input = $_GET;

// Obtener parámetros
$id_factura = (int)($input['id_factura'] ?? 0);
$monto = (float)($input['monto'] ?? 0);
$forma_pago = trim($input['forma_pago'] ?? 'Efectivo');
$observacion = trim($input['observacion'] ?? '');

// Validar
if (!$id_factura) {
    echo json_encode(['success' => false, 'message' => 'ID de factura requerido']);
    exit;
}

if ($monto <= 0) {
    echo json_encode(['success' => false, 'message' => 'El monto debe ser mayor a 0']);
    exit;
}

// Conexión a BD
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';
$id_empresa = isset($_SESSION['id_empresa']) && $_SESSION['id_empresa'] ? (int)$_SESSION['id_empresa'] : 169;
$id_usuario = isset($_SESSION['id_usuario']) ? (int)$_SESSION['id_usuario'] : 0;

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");

    // Obtener base de datos de la empresa
    $stmtDB = $pdo->prepare("SELECT dbase, empresa FROM empresa WHERE id_empresa = :id");
    $stmtDB->execute([':id' => $id_empresa]);
    $empresaRow = $stmtDB->fetch(PDO::FETCH_ASSOC);

    if (!$empresaRow || !$empresaRow['dbase']) {
        throw new Exception("Base de datos no encontrada para empresa ID: $id_empresa");
    }

    $dbName = $empresaRow['dbase'];
    $nombreEmpresa = $empresaRow['empresa'];

    // Obtener datos de la factura
    $stmtFactura = $pdo->prepare("
        SELECT 
            fv.id_factura, fv.nro_factura, fv.fecha, fv.total, fv.cdc,
            fv.id_cliente, fv.forma_pago AS tipo_venta,
            c.nombre AS cliente_nombre, c.numero AS cliente_ruc,
            c.direccion AS cliente_direccion, c.telefono AS cliente_telefono
        FROM $dbName.factura_ventas fv
        LEFT JOIN $dbName.clientes c ON c.id = fv.id_cliente
        WHERE fv.id_factura = :id
    ");
    $stmtFactura->execute([':id' => $id_factura]);
    $factura = $stmtFactura->fetch(PDO::FETCH_ASSOC);

    if (!$factura) {
        throw new Exception("Factura no encontrada: $id_factura");
    }

    // Verificar si la tabla recibos_cobro existe, si no, crearla
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS $dbName.recibos_cobro (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nro_recibo VARCHAR(20),
            id_factura INT,
            nro_factura VARCHAR(50),
            cdc VARCHAR(44),
            id_cliente INT,
            cliente_nombre VARCHAR(200),
            monto DECIMAL(15,2),
            forma_pago VARCHAR(50),
            observacion TEXT,
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
            id_usuario INT,
            id_empresa INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_factura (id_factura),
            INDEX idx_cliente (id_cliente),
            INDEX idx_fecha (fecha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8
    ");

    // Generar número de recibo
    $stmtMax = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM $dbName.recibos_cobro");
    $nextId = $stmtMax->fetchColumn();
    $nroRecibo = 'REC-' . str_pad($nextId, 7, '0', STR_PAD_LEFT);

    // Insertar recibo
    $stmtInsert = $pdo->prepare("
        INSERT INTO $dbName.recibos_cobro 
        (nro_recibo, id_factura, nro_factura, cdc, id_cliente, cliente_nombre, monto, forma_pago, observacion, id_usuario, id_empresa)
        VALUES 
        (:nro_recibo, :id_factura, :nro_factura, :cdc, :id_cliente, :cliente_nombre, :monto, :forma_pago, :observacion, :id_usuario, :id_empresa)
    ");

    $stmtInsert->execute([
        ':nro_recibo' => $nroRecibo,
        ':id_factura' => $id_factura,
        ':nro_factura' => $factura['nro_factura'] ?? '',
        ':cdc' => $factura['cdc'] ?? '',
        ':id_cliente' => $factura['id_cliente'] ?? 0,
        ':cliente_nombre' => $factura['cliente_nombre'] ?? '',
        ':monto' => $monto,
        ':forma_pago' => $forma_pago,
        ':observacion' => $observacion,
        ':id_usuario' => $id_usuario,
        ':id_empresa' => $id_empresa
    ]);

    $idRecibo = $pdo->lastInsertId();

    // Log
    $logFile = __DIR__ . '/logs/recibos_' . date('Y-m') . '.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    $logEntry = date('Y-m-d H:i:s') . " | RECIBO: $nroRecibo | Factura: {$factura['nro_factura']} | Monto: $monto | $forma_pago\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);

    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'message' => 'Recibo emitido correctamente',
        'data' => [
            'id_recibo' => $idRecibo,
            'nro_recibo' => $nroRecibo,
            'id_factura' => $id_factura,
            'nro_factura' => $factura['nro_factura'],
            'cliente' => $factura['cliente_nombre'],
            'monto' => $monto,
            'forma_pago' => $forma_pago,
            'fecha' => date('Y-m-d H:i:s'),
            'empresa' => $nombreEmpresa
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null
    ]);
}
