<?php

/**
 * KUDE Email Sender - Usando mail() nativo de PHP
 * Envía el ticket KUDE por email como archivo adjunto PDF
 */

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Cargar TCPDF
require_once __DIR__ . '/_lib/prod/third/tcpdf/tcpdf.php';

// Recibir datos JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
    exit;
}

$email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
$id_factura = (int)($input['id_factura'] ?? 0);
$cdc = $input['cdc'] ?? '';
$nro_factura = $input['nro_factura'] ?? '';
$cliente = $input['cliente'] ?? '';
$total = $input['total'] ?? '';
$html_content = $input['html_content'] ?? '';

if (!$email) {
    echo json_encode(['success' => false, 'error' => 'Email inválido']);
    exit;
}

if (!$id_factura) {
    echo json_encode(['success' => false, 'error' => 'ID de factura requerido']);
    exit;
}

// CDC es opcional para comprobantes internos (Notas)
$cdc = $input['cdc'] ?? '';

// Conexión a BD para obtener datos de empresa
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';
$id_empresa = isset($_SESSION['id_empresa']) && $_SESSION['id_empresa'] ? (int)$_SESSION['id_empresa'] : 169;

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");

    // Obtener base de datos de la empresa
    $stmtDB = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = :id");
    $stmtDB->execute([':id' => $id_empresa]);
    $dbName = $stmtDB->fetchColumn();

    // Obtener datos completos de la empresa
    $stmtEmpresa = $pdo->prepare("SELECT * FROM empresa WHERE id_empresa = :id");
    $stmtEmpresa->execute([':id' => $id_empresa]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

    $empresaNombre = $empresa['empresa'] ?? 'EMPRESA';

    // Obtener datos completos de la factura
    if ($dbName) {
        $stmtFactura = $pdo->prepare("
            SELECT 
                fv.*,
                c.nombre AS cliente_nombre,
                c.numero AS cliente_ruc,
                c.direccion AS cliente_direccion,
                c.email AS cliente_email,
                c.telefono AS cliente_telefono
            FROM $dbName.factura_ventas fv
            LEFT JOIN $dbName.clientes c ON c.id = fv.id_cliente
            WHERE fv.id_factura = :id
        ");
        $stmtFactura->execute([':id' => $id_factura]);
        $factura = $stmtFactura->fetch(PDO::FETCH_ASSOC);
    } else {
        $factura = null;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error de conexión BD: ' . $e->getMessage()]);
    exit;
}

// Obtener items de la factura
try {
    if ($dbName) {
        $stmtItems = $pdo->prepare("
            SELECT 
                ep.*,
                p.desproducto AS producto_nombre,
                p.idproducto AS producto_codigo
            FROM $dbName.extracto_productos ep
            LEFT JOIN $dbName.tblproductos p ON p.idproducto = ep.idproducto
            WHERE ep.idfactura = :id
            ORDER BY ep.id
        ");
        $stmtItems->execute([':id' => $id_factura]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $items = [];
    }
} catch (Exception $e) {
    $items = [];
}

// Funciones auxiliares
function formatMoney($amount) {
    return number_format((float)$amount, 0, ',', '.');
}

function parseCDC($cdc) {
    if (strlen($cdc) < 44) return [];
    $tiposDe = [
        '01' => 'Factura electrónica',
        '04' => 'Autofactura electrónica',
        '05' => 'Nota de crédito electrónica',
        '07' => 'Nota de remisión electrónica'
    ];
    return [
        'ruc' => substr($cdc, 0, 8),
        'dv' => substr($cdc, 8, 1),
        'tipo_doc' => substr($cdc, 9, 2),
        'tipo_nombre' => $tiposDe[substr($cdc, 9, 2)] ?? 'Documento Electrónico'
    ];
}

$cdcData = parseCDC($cdc);

// URL del QR oficial SIFEN
$qrUrl = !empty($factura['qr_sifen']) ? $factura['qr_sifen'] : 'https://ekuatia.set.gov.py/consultas/qr?nVersion=150&Id=' . $cdc;

// Construir tabla de items para PDF
$itemsHtml = '';
$totalExenta = 0;
$totalIva5 = 0;
$totalIva10 = 0;
$itemIndex = 0;

if (!empty($items)) {
    foreach ($items as $item) {
        $cantidad = $item['salida'] ?? $item['cantidad'] ?? 1;
        $precio = $item['precio'] ?? 0;
        $subtotal = $cantidad * $precio;
        
        $tasaIva = $item['tasa_iva'] ?? 10;
        if (isset($item['tipo_iva'])) {
            if ($item['tipo_iva'] == 1) $tasaIva = 0;
            elseif ($item['tipo_iva'] == 2) $tasaIva = 5;
            elseif ($item['tipo_iva'] == 3) $tasaIva = 10;
        }
        
        if ($tasaIva == 0) $totalExenta += $subtotal;
        elseif ($tasaIva == 5) $totalIva5 += $subtotal;
        else $totalIva10 += $subtotal;
        
        $bgColor = ($itemIndex % 2 == 1) ? '#f8fafc' : 'white';
        $itemsHtml .= '<tr style="background:' . $bgColor . ';">
            <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0;">' . htmlspecialchars($item['producto_codigo'] ?? '-') . '</td>
            <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0;">' . htmlspecialchars($item['producto_nombre'] ?? $item['descripcion'] ?? 'Producto') . '</td>
            <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0; text-align:center;">' . number_format($cantidad, 0) . '</td>
            <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0; text-align:right;">' . formatMoney($precio) . '</td>
            <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0; text-align:center;">' . ($tasaIva == 0 ? 'Exenta' : $tasaIva . '%') . '</td>
            <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0; text-align:right;">' . formatMoney($subtotal) . '</td>
        </tr>';
        $itemIndex++;
    }
} else {
    $totalIva10 = (float)str_replace(['.', ','], ['', '.'], $total);
    $itemsHtml = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#666;">Detalle no disponible</td></tr>';
}

$liq5 = (float)($totalIva5 / 21);
$liq10 = (float)($totalIva10 / 11);

// Crear HTML completo para PDF en formato A4 - 100% homologado con kude_a4.php (sin emojis)
$htmlContent = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; font-size: 11px; line-height: 1.4; color: #333; }
        
        /* Header */
        .header { display: table; width: 100%; border-bottom: 2px solid #1a365d; padding-bottom: 15px; margin-bottom: 15px; }
        .empresa-info { display: table-cell; width: 60%; vertical-align: top; }
        .empresa-info h1 { font-size: 20px; color: #1a365d; margin-bottom: 5px; }
        .empresa-info p { color: #666; font-size: 10px; margin-bottom: 2px; }
        .documento-tipo { display: table-cell; width: 40%; text-align: right; vertical-align: top; }
        .badge { display: inline-block; background: #1a365d; color: white; padding: 8px 15px; border-radius: 5px; font-weight: bold; font-size: 12px; margin-bottom: 10px; }
        .numero { font-size: 18px; font-weight: bold; color: #1a365d; }
        .timbrado { font-size: 9px; color: #666; margin-top: 5px; }
        
        /* Datos */
        .datos-grid { margin-bottom: 15px; }
        .datos-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 10px; }
        .datos-box h3 { font-size: 10px; color: #1a365d; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; padding-bottom: 5px; border-bottom: 1px solid #e2e8f0; }
        .datos-box .row { margin-bottom: 4px; }
        .datos-box .label { color: #666; font-size: 10px; display: inline-block; width: 40%; }
        .datos-box .value { font-weight: 600; color: #333; }
        
        /* Items */
        .items-section { margin-bottom: 15px; }
        .items-section h3 { font-size: 11px; color: #1a365d; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 10px; }
        thead th { background: #1a365d; color: white; padding: 8px 10px; text-align: left; font-weight: 600; }
        thead th.right { text-align: right; }
        thead th.center { text-align: center; }
        tbody td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
        tbody td.right { text-align: right; }
        tbody td.center { text-align: center; }
        
        /* Totales */
        .totales-section { margin-top: 20px; }
        .totales-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; }
        .totales-box .row { padding: 5px 0; border-bottom: 1px solid #e2e8f0; }
        .totales-box .row:last-child { border-bottom: none; }
        .totales-box .row.total { font-size: 16px; font-weight: bold; color: #1a365d; padding-top: 10px; margin-top: 5px; border-top: 2px solid #1a365d; }
        .totales-box .label { color: #666; }
        .totales-box .value { font-weight: 600; float: right; }
        
        /* CDC */
        .cdc-section { background: #f0f4f8; border: 1px solid #cbd5e0; border-radius: 8px; padding: 12px; margin: 15px 0; text-align: center; }
        .cdc-section .label { font-size: 9px; color: #666; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
        .cdc-section .value { font-family: "Courier New", monospace; font-size: 11px; letter-spacing: 1px; color: #1a365d; word-break: break-all; }
        
        /* Footer */
        .footer { border-top: 1px solid #e2e8f0; padding-top: 10px; margin-top: 15px; font-size: 9px; color: #666; text-align: center; }
        .footer p { margin-bottom: 3px; }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="empresa-info">
            <h1>' . htmlspecialchars($empresaNombre) . '</h1>
            <p><strong>RUC:</strong> ' . htmlspecialchars($empresa['ruc'] ?? '') . '-' . htmlspecialchars($empresa['dv'] ?? '') . '</p>
            <p>' . htmlspecialchars($empresa['direccion'] ?? '') . '</p>
            <p>Tel: ' . htmlspecialchars($empresa['telefono'] ?? '') . ' | Email: ' . htmlspecialchars($empresa['email'] ?? '') . '</p>
            <p><strong>Actividad Económica:</strong> ' . htmlspecialchars($empresa['des_act'] ?? $empresa['actividad'] ?? '') . '</p>
        </div>
        <div class="documento-tipo">
            <div class="badge">' . strtoupper($cdcData['tipo_nombre']) . '</div>
            <div class="numero">' . htmlspecialchars($nro_factura) . '</div>
            <div class="timbrado">Timbrado: ' . htmlspecialchars($factura['timbrado'] ?? $empresa['timbrado'] ?? '') . '</div>
        </div>
    </div>
    
    <!-- Datos del documento -->
    <div class="datos-grid">
        <div class="datos-box">
            <h3>DATOS DEL DOCUMENTO</h3>
            <div class="row">
                <span class="label">Fecha de Emisión:</span>
                <span class="value">' . date('d/m/Y H:i', strtotime($factura['fecha'] ?? 'now')) . '</span>
            </div>
            <div class="row">
                <span class="label">Condición de Venta:</span>
                <span class="value">' . (isset($factura['forma_pago']) && $factura['forma_pago'] == 1 ? 'CONTADO' : 'CRÉDITO') . '</span>
            </div>
            <div class="row">
                <span class="label">Moneda:</span>
                <span class="value">Guaraníes (PYG)</span>
            </div>
        </div>
        
        <div class="datos-box">
            <h3>DATOS DEL RECEPTOR</h3>
            <div class="row">
                <span class="label">Razón Social:</span>
                <span class="value">' . htmlspecialchars($factura['cliente_nombre'] ?? $cliente) . '</span>
            </div>
            <div class="row">
                <span class="label">RUC/CI:</span>
                <span class="value">' . htmlspecialchars($factura['cliente_ruc'] ?? $input['ruc'] ?? '') . '</span>
            </div>
            <div class="row">
                <span class="label">Dirección:</span>
                <span class="value">' . htmlspecialchars($factura['cliente_direccion'] ?? '') . '</span>
            </div>
        </div>
    </div>
    
    <!-- Detalle de Items -->
    <div class="items-section">
        <h3>DETALLE DE LA OPERACIÓN</h3>
        <table>
            <thead>
                <tr>
                    <th style="width:60px;">Código</th>
                    <th>Descripción</th>
                    <th style="width:50px;" class="center">Cant.</th>
                    <th style="width:80px;" class="right">P. Unitario</th>
                    <th style="width:60px;" class="center">IVA</th>
                    <th style="width:100px;" class="right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                ' . $itemsHtml . '
            </tbody>
        </table>
    </div>
    
    <!-- Totales -->
    <div class="totales-section">
        <div class="totales-box">
            <div class="row">
                <span class="label">Subtotal Exenta:</span>
                <span class="value">' . formatMoney($totalExenta) . ' Gs</span>
            </div>
            <div class="row">
                <span class="label">Subtotal IVA 5%:</span>
                <span class="value">' . formatMoney($totalIva5) . ' Gs</span>
            </div>
            <div class="row">
                <span class="label">Subtotal IVA 10%:</span>
                <span class="value">' . formatMoney($totalIva10) . ' Gs</span>
            </div>
            <div class="row" style="background:#f0fff4; border-top:1px dashed #ccc; margin-top:5px; padding-top:5px;">
                <span class="label">Liquidación IVA 5%:</span>
                <span class="value">' . formatMoney($liq5) . ' Gs</span>
            </div>
            <div class="row" style="background:#f0fff4;">
                <span class="label">Liquidación IVA 10%:</span>
                <span class="value">' . formatMoney($liq10) . ' Gs</span>
            </div>
            <div class="row" style="background:#f0fff4; font-weight:bold;">
                <span class="label">Total Liquidación IVA:</span>
                <span class="value">' . formatMoney($liq5 + $liq10) . ' Gs</span>
            </div>
            <div class="row total">
                <span class="label">TOTAL:</span>
                <span class="value">' . formatMoney($totalExenta + $totalIva5 + $totalIva10) . ' Gs</span>
            </div>
        </div>
    </div>
    
    <!-- CDC -->
    <div class="cdc-section">
        <div class="label">Código de Control del Documento Electrónico (CDC)</div>
        <div class="value">' . htmlspecialchars($cdc) . '</div>
    </div>
    
    <!-- Footer -->
    <div class="footer">
        <p>Este documento es la representación gráfica de un Documento Tributario Electrónico (KuDE)</p>
        <p>Consulte la validez en: <strong>ekuatia.set.gov.py/consultas</strong></p>
        <p style="margin-top:5px; color:#999;">Generado: ' . date('d/m/Y H:i:s') . ' | Powered by SistemaX</p>
    </div>
</body>
</html>';

// Generar PDF con TCPDF
try {
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('SistemaX');
    $pdf->SetAuthor($empresaNombre);
    $pdf->SetTitle('KUDE - ' . $nro_factura);
    $pdf->SetSubject('Comprobante Electrónico');

    // Configurar márgenes
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);

    // Remover header y footer por defecto
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Agregar página
    $pdf->AddPage();

    // Escribir contenido HTML
    $pdf->writeHTML($htmlContent, true, false, true, false, '');

    // Obtener contenido PDF como string
    $pdfContent = $pdf->Output('', 'S');

    // Nombre del archivo PDF
    $filename = 'KUDE_' . preg_replace('/[^a-zA-Z0-9]/', '_', $nro_factura) . '_' . date('Ymd') . '.pdf';
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Error generando PDF: ' . $e->getMessage()]);
    exit;
}

// Cuerpo del email en HTML
$emailBody = "
<html>
<body style='font-family: Arial, sans-serif; color: #333; background: #f9fafb;'>
    <div style='max-width: 600px; margin: 0 auto; padding: 20px; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);'>
        <div style='text-align: center; padding-bottom: 20px; border-bottom: 2px solid #e5e7eb;'>
            <h1 style='color: #2563eb; margin: 0;'>📄 Comprobante Electrónico</h1>
            <p style='color: #6b7280; margin: 5px 0;'>$empresaNombre</p>
        </div>
        
        <p style='margin-top: 20px;'>Estimado/a <strong>" . htmlspecialchars($cliente) . "</strong>,</p>
        <p>Adjunto encontrará el comprobante electrónico (KUDE) de su factura:</p>
        
        <table style='width: 100%; border-collapse: collapse; margin: 20px 0; border-radius: 8px; overflow: hidden;'>
            <tr style='background: #f3f4f6;'>
                <td style='padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: bold;'>Nro. Factura:</td>
                <td style='padding: 12px 15px; border: 1px solid #e5e7eb;'>" . htmlspecialchars($nro_factura) . "</td>
            </tr>
            <tr>
                <td style='padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: bold;'>Total:</td>
                <td style='padding: 12px 15px; border: 1px solid #e5e7eb; font-size: 18px; color: #059669; font-weight: bold;'>" . htmlspecialchars($total) . " Gs</td>
            </tr>
            <tr style='background: #f3f4f6;'>
                <td style='padding: 12px 15px; border: 1px solid #e5e7eb; font-weight: bold;'>CDC:</td>
                <td style='padding: 12px 15px; border: 1px solid #e5e7eb; font-family: monospace; font-size: 10px; word-break: break-all;'>" . htmlspecialchars($cdc) . "</td>
            </tr>
        </table>
        
        <div style='background: #ecfdf5; padding: 15px; border-radius: 8px; border-left: 4px solid #10b981; margin: 20px 0;'>
            <strong style='color: #059669;'>✅ Verificar Documento</strong><br>
            <p style='margin: 10px 0; font-size: 14px;'>Haga clic en el siguiente enlace para ver los detalles del documento:</p>
            <a href='https://sistemax.com.py/verificar.php?cdc=" . urlencode($cdc) . "' 
               style='display: inline-block; background: #059669; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: bold;'>
                🔗 Ver Comprobante
            </a>
            <p style='margin: 15px 0 0 0; font-size: 12px; color: #666;'>También puede verificar en SIFEN: ekuatia.set.gov.py/consultas</p>
        </div>
        
        <hr style='border: none; border-top: 1px solid #e5e7eb; margin: 30px 0;'>
        
        <p style='color: #9ca3af; font-size: 11px; text-align: center;'>
            Este es un mensaje automático generado por el sistema de facturación electrónica.<br>
            Por favor no responda a este correo.
        </p>
    </div>
</body>
</html>
";

try {
    // Crear contenido del email
    $boundary = md5(time());

    // Headers
    $headers = "From: $empresaNombre <noreply@sistemax.com.py>\r\n";
    $headers .= "Reply-To: noreply@sistemax.com.py\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

    // Asunto
    $subject = "KUDE - Factura Electrónica $nro_factura - $empresaNombre";

    // Cuerpo del mensaje
    $message = "--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $emailBody . "\r\n";

    // Adjuntar archivo PDF
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
    $message .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $message .= chunk_split(base64_encode($pdfContent)) . "\r\n";
    $message .= "--{$boundary}--";

    // Enviar email
    $sent = mail($email, $subject, $message, $headers);

    if ($sent) {
        // Guardar log de envío exitoso
        try {
            $logFile = __DIR__ . '/logs/kude_email_' . date('Y-m') . '.log';
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $logEntry = date('Y-m-d H:i:s') . " | OK | ID: $id_factura | Email: $email | CDC: $cdc\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);
        } catch (Exception $e) {
            // Ignorar error de log
        }

        echo json_encode(['success' => true, 'message' => 'Email enviado correctamente a ' . $email]);
    } else {
        throw new Exception('Error al enviar el email');
    }
} catch (Exception $e) {
    // Guardar log de error
    try {
        $logFile = __DIR__ . '/logs/kude_email_' . date('Y-m') . '.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logEntry = date('Y-m-d H:i:s') . " | ERROR | ID: $id_factura | Email: $email | Error: " . $e->getMessage() . "\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    } catch (Exception $e2) {
        // Ignorar
    }

    echo json_encode([
        'success' => false,
        'error' => 'Error al enviar: ' . $e->getMessage()
    ]);
}
