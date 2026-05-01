<?php

/**
 * Webhook UENO / upay
 * Recibe confirmación de pago exitoso desde los servidores de UENO.
 */

// Log de entrada para auditoría
$logFile = __DIR__ . '/../../logs/ueno_webhook_' . date('Y-m-d') . '.log';
$inputJSON = file_get_contents('php://input');
file_put_contents($logFile, "[" . date('H:i:s') . "] RAW INPUT: " . $inputJSON . "\n", FILE_APPEND);

header('Content-Type: application/json');
require_once __DIR__ . '/ueno_config.php'; // Lo moveré aquí por simplicidad de rutas
require_once __DIR__ . '/sifen_lib.php';

$data = json_decode($inputJSON, true);

// 1. Validar Token de Seguridad (Bearer o Firma en Headers)
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

// En producción UENO suele enviar un token o firma que debemos validar
// Por ahora validamos contra nuestro token configurado
if (strpos($authHeader, UENO_TOKEN) === false && UENO_ENV !== 'sandbox') {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'No autorizado']));
}

if (!$data || !isset($data['order']['id'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Datos inválidos']));
}

$id_operacion = $data['id'] ?? '';
$referencia_ueno = $data['order']['id'];
$estado_ueno = $data['status'] ?? ''; // e.g., 'paid', 'approved'
$id_factura = (int)($data['order']['reference'] ?? 0);

if ($id_factura <= 0) {
    die(json_encode(['success' => false, 'message' => 'Referencia de factura no encontrada']));
}

require_once __DIR__ . '/../config/db_config.php';

try {
    session_start();
    $id_empresa = $_SESSION['id_empresa'] ?? 169; // O extraer de metadata de la orden

    // Obtener conexión a la base de datos de la empresa
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];

    // Asumiremos empresa del sistema por ahora o la buscaremos dinámicamente:
    $dbName = 'tienda_169';
    $id_empresa = 169;

    if ($estado_ueno === 'paid' || $estado_ueno === 'approved' || UENO_ENV === 'sandbox') {

        $pdo->beginTransaction();

        // 2. Actualizar tabla de pagos UENO
        $stmtUpd = $pdo->prepare("UPDATE $dbName.pagos_ueno SET estado = 'PAGADO', payload_webhook = :payload, fecha_pago = NOW() 
                                WHERE id_operacion = :id_op OR referencia = :ref");
        $stmtUpd->execute([
            ':payload' => $inputJSON,
            ':id_op' => $id_operacion,
            ':ref' => $referencia_ueno
        ]);

        // 3. Marcar Venta como Pagada con Tarjeta Ueno
        $detalle_pago = "PAGADO - TARJETA UENO | REF: $id_operacion";
        $sqlFactura = "UPDATE $dbName.factura_ventas SET estado = 1, firma_digital = :firma, forma_pago = 2 WHERE id_factura = :id";
        $pdo->prepare($sqlFactura)->execute([':firma' => $detalle_pago, ':id' => $id_factura]);

        $pdo->commit();

        // 4. GENERAR FACTURA SIFEN
        // Llamamos a nuestra librería existente
        $sifenResult = emitirFacturaElectronica($id_factura, $pdo, $dbName, $id_empresa);

        file_put_contents($logFile, "[" . date('H:i:s') . "] SIFEN RESULT: " . json_encode($sifenResult) . "\n", FILE_APPEND);

        echo json_encode(['success' => true, 'message' => 'Pago procesado y factura emitida']);
    } else {
        // Otros estados (CANCELLED, PENDING, etc)
        $pdo->prepare("UPDATE $dbName.pagos_ueno SET estado = :est, payload_webhook = :payload WHERE referencia = :ref")
            ->execute([':est' => strtoupper($estado_ueno), ':payload' => $inputJSON, ':ref' => $referencia_ueno]);

        echo json_encode(['success' => true, 'message' => 'Estado actualizado: ' . $estado_ueno]);
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    file_put_contents($logFile, "[" . date('H:i:s') . "] ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
