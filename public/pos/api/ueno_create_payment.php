<?php

/**
 * API - Crear Pago UENO
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../config/ueno.php';
require_once __DIR__ . '/../config/db_config.php';

session_start();

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

$id_factura = (int)($input['id_factura'] ?? 0);
$id_empresa = (int)($input['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);

if ($id_factura <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de factura inválido']);
    exit;
}

try {
    // Obtener conexión a la base de datos de la empresa
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];

    $sqlVenta = "SELECT f.id_factura, f.total, c.nombre as cliente_nombre, c.numero as cliente_ruc, f.nro_factura
                 FROM $dbName.factura_ventas f
                 LEFT JOIN $dbName.clientes c ON c.id = f.id_cliente
                 WHERE f.id_factura = :id";
    $stmtVenta = $pdo->prepare($sqlVenta);
    $stmtVenta->execute([':id' => $id_factura]);
    $venta = $stmtVenta->fetch(PDO::FETCH_ASSOC);

    if (!$venta) throw new Exception("Venta no encontrada");

    $referencia = "POS-" . $venta['nro_factura'] . "-" . time();
    $amount = (float)$venta['total'];

    $payload = [
        'order' => [
            'id' => $referencia,
            'description' => "Pago Factura " . $venta['nro_factura'],
            'amount' => $amount,
            'currency' => UENO_CURRENCY,
            'reference' => (string)$id_factura
        ],
        'notificationType' => 'link',
        'returnUrl' => UENO_RETURN_URL . "&id_factura=" . $id_factura,
        'cancelUrl' => UENO_CANCEL_URL . "&id_factura=" . $id_factura,
        'webhookUrl' => UENO_WEBHOOK_URL
    ];

    $ch = curl_init(UENO_API_URL . '/invoices');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . UENO_TOKEN
    ]);

    $responseJSON = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $response = json_decode($responseJSON, true);

    if ($httpCode >= 200 && $httpCode < 300 && isset($response['url'])) {
        $sqlInsert = "INSERT INTO $dbName.pagos_ueno (id_factura, id_operacion, referencia, monto, payload_init) 
                      VALUES (:id_f, :id_op, :ref, :monto, :payload)";
        $pdo->prepare($sqlInsert)->execute([
            ':id_f' => $id_factura,
            ':id_op' => $response['id'] ?? $referencia,
            ':ref' => $referencia,
            ':monto' => $amount,
            ':payload' => $responseJSON
        ]);

        echo json_encode([
            'success' => true,
            'checkout_url' => $response['url'],
            'id_operacion' => $response['id'] ?? ''
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => $response['message'] ?? 'Error al conectar con UENO',
            'debug' => UENO_ENV === 'sandbox' ? $response : null
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
