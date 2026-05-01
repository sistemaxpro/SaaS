<?php
/**
 * API para enviar KUDE por Email
 * POST: { id_factura, id_empresa, email }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/bootstrap.php';

// Obtener datos del request
$input = json_decode(file_get_contents('php://input'), true);

$id_factura = isset($input['id_factura']) ? (int)$input['id_factura'] : 0;
$id_empresa = isset($input['id_empresa']) ? (int)$input['id_empresa'] : 0;
$email = isset($input['email']) ? trim($input['email']) : '';

// Validaciones
if (!$id_factura || !$id_empresa) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Email inválido']);
    exit;
}

// Conexión directa a BD (sin bootstrap para evitar problemas de sesión)
try {
    // Conexión master
    $masterPdo = Database::getMasterConnection();
    
    // Obtener datos de empresa
    $stmtEmpresa = $masterPdo->prepare("SELECT * FROM empresa WHERE id_empresa = ?");
    $stmtEmpresa->execute([$id_empresa]);
    $empresa = $stmtEmpresa->fetch();
    
    if (!$empresa) {
        throw new Exception('Empresa no encontrada');
    }
    
    // Conexión a BD de empresa
    $pdo = Database::getEmpresaConnection($id_empresa);
    
    // Obtener factura
    $stmt = $pdo->prepare("
        SELECT fv.*, c.nombre AS cliente_nombre, c.numero AS cliente_ruc
        FROM factura_ventas fv
        LEFT JOIN clientes c ON c.id = fv.id_cliente
        WHERE fv.id_factura = :id
    ");
    $stmt->execute([':id' => $id_factura]);
    $factura = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$factura) {
        throw new Exception('Factura no encontrada');
    }
    
    $cdc = $factura['cdc'] ?? '';
    if (empty($cdc)) {
        throw new Exception('Esta factura no tiene CDC');
    }
    
    // Generar URL del KUDE para el email
    $kudeUrl = "https://sistemax.pro/public/pos/kude.php?id={$id_factura}&id_empresa={$id_empresa}";
    $qrUrl = !empty($factura['qr_sifen']) ? $factura['qr_sifen'] : "https://ekuatia.set.gov.py/consultas/qr?nVersion=150&Id={$cdc}";
    
    // Preparar el email
    $empresaNombre = htmlspecialchars($empresa['empresa'] ?? 'Empresa');
    $nroFactura = htmlspecialchars($factura['nro_factura'] ?? '');
    $clienteNombre = htmlspecialchars($factura['cliente_nombre'] ?? 'Cliente');
    $total = number_format((float)($factura['total'] ?? 0), 0, ',', '.');
    $fecha = date('d/m/Y', strtotime($factura['fecha']));
    
    // HTML del email
    $htmlEmail = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #1e40af, #3b82f6); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { background: #f8fafc; padding: 30px; border: 1px solid #e2e8f0; }
        .info-box { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f1f5f9; }
        .info-label { color: #64748b; }
        .info-value { font-weight: bold; color: #1e293b; }
        .total-row { font-size: 18px; color: #1e40af; border: none; padding-top: 15px; }
        .btn { display: inline-block; padding: 15px 30px; background: #1e40af; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 10px 5px; }
        .btn-secondary { background: #64748b; }
        .footer { text-align: center; padding: 20px; color: #64748b; font-size: 12px; }
        .cdc { font-family: monospace; font-size: 11px; word-break: break-all; background: #f1f5f9; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📄 Documento Electrónico</h1>
            <p style="margin: 10px 0 0; opacity: 0.9;">KUDE - Kuatia Documento Electrónico</p>
        </div>
        
        <div class="content">
            <p>Estimado/a <strong>{$clienteNombre}</strong>,</p>
            <p>Adjunto encontrará el comprobante de su transacción con <strong>{$empresaNombre}</strong>.</p>
            
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Factura N°:</span>
                    <span class="info-value">{$nroFactura}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Fecha:</span>
                    <span class="info-value">{$fecha}</span>
                </div>
                <div class="info-row total-row">
                    <span class="info-label">Total:</span>
                    <span class="info-value">{$total} Gs</span>
                </div>
            </div>
            
            <p><strong>CDC (Código de Control):</strong></p>
            <div class="cdc">{$cdc}</div>
            
            <div style="text-align: center; margin-top: 25px;">
                <a href="{$kudeUrl}" class="btn">📥 Ver KUDE</a>
                <a href="{$qrUrl}" class="btn btn-secondary">✓ Verificar en SET</a>
            </div>
        </div>
        
        <div class="footer">
            <p>Este documento es la representación gráfica de un Documento Tributario Electrónico.</p>
            <p>Puede verificar la validez en: <strong>ekuatia.set.gov.py/consultas</strong></p>
            <p style="margin-top: 15px;">{$empresaNombre}</p>
        </div>
    </div>
</body>
</html>
HTML;

    // Configurar y enviar email
    $to = $email;
    $subject = "KUDE - Factura {$nroFactura} - {$empresaNombre}";
    
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        "From: {$empresaNombre} <noreply@sistemax.pro>",
        'Reply-To: ' . ($empresa['email'] ?? 'info@sistemax.pro'),
        'X-Mailer: PHP/' . phpversion()
    ];
    
    $enviado = mail($to, $subject, $htmlEmail, implode("\r\n", $headers));
    
    if ($enviado) {
        // Log de envío exitoso
        error_log("[KUDE_EMAIL] Enviado a {$email} - Factura {$id_factura} - Empresa {$id_empresa}");
        
        echo json_encode([
            'success' => true,
            'message' => 'Email enviado correctamente'
        ]);
    } else {
        throw new Exception('Error al enviar el email');
    }
    
} catch (Exception $e) {
    error_log("[KUDE_EMAIL_ERROR] " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
