<?php

/**
 * KUDE A4 Print - Formato A4 según Manual SIFEN v150
 * Kuatia Documento Electrónico - Representación gráfica completa del DE
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$id_factura = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cdc_param = isset($_GET['cdc']) ? trim($_GET['cdc']) : '';

if (!$id_factura) {
    die('Error: ID de factura requerido');
}

// Conexión a BD
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';
$id_empresa = isset($_SESSION['id_empresa']) && $_SESSION['id_empresa'] ? (int)$_SESSION['id_empresa'] : 169;

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");

    $stmtDB = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = :id");
    $stmtDB->execute([':id' => $id_empresa]);
    $dbName = $stmtDB->fetchColumn();

    if (!$dbName) {
        throw new Exception("Base de datos no encontrada para empresa ID: $id_empresa");
    }

    $stmtEmpresa = $pdo->prepare("SELECT * FROM empresa WHERE id_empresa = :id");
    $stmtEmpresa->execute([':id' => $id_empresa]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

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

    if (!$factura) {
        throw new Exception("Factura no encontrada: $id_factura");
    }

    $cdc = !empty($cdc_param) ? $cdc_param : ($factura['cdc'] ?? '');

    if (empty($cdc)) {
        throw new Exception("Esta factura no tiene CDC (no es electrónica)");
    }

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

    // URL Oficial SIFEN
    $qrUrl = !empty($factura['qr_sifen']) ? $factura['qr_sifen'] : '';
    if (empty($qrUrl)) {
        $qrUrl = 'https://ekuatia.set.gov.py/consultas/qr?nVersion=150&Id=' . $cdc;
    }
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}

function formatMoney($amount)
{
    return number_format((float)$amount, 0, ',', '.');
}

function parseCDC($cdc)
{
    if (strlen($cdc) < 44) return [];
    $tiposDe = [
        '01' => 'Factura electrónica',
        '02' => 'Factura electrónica de exportación',
        '03' => 'Factura electrónica de importación',
        '04' => 'Autofactura electrónica',
        '05' => 'Nota de crédito electrónica',
        '06' => 'Nota de débito electrónica',
        '07' => 'Nota de remisión electrónica'
    ];
    return [
        'ruc' => substr($cdc, 0, 8),
        'dv' => substr($cdc, 8, 1),
        'tipo_doc' => substr($cdc, 9, 2),
        'establecimiento' => substr($cdc, 11, 3),
        'punto' => substr($cdc, 14, 3),
        'numero' => substr($cdc, 17, 7),
        'fecha' => substr($cdc, 25, 8),
        'tipo_nombre' => $tiposDe[substr($cdc, 9, 2)] ?? 'Documento Electrónico'
    ];
}

$cdcData = parseCDC($cdc);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KUDE A4 - <?php echo htmlspecialchars($factura['nro_factura'] ?? $cdc); ?></title>
    <style>
        @page {
            size: A4;
            margin: 15mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
            background: #f5f5f5;
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: white;
            padding: 15mm;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        }

        /* Header */
        .header {
            display: grid;
            grid-template-columns: 1fr 200px;
            gap: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #1a365d;
            margin-bottom: 15px;
        }

        .empresa-info h1 {
            font-size: 20px;
            color: #1a365d;
            margin-bottom: 5px;
        }

        .empresa-info p {
            color: #666;
            font-size: 10px;
            margin-bottom: 2px;
        }

        .documento-tipo {
            text-align: right;
        }

        .documento-tipo .badge {
            display: inline-block;
            background: linear-gradient(135deg, #1a365d, #2c5282);
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            font-weight: bold;
            font-size: 12px;
            margin-bottom: 10px;
        }

        .documento-tipo .numero {
            font-size: 18px;
            font-weight: bold;
            color: #1a365d;
        }

        .documento-tipo .timbrado {
            font-size: 9px;
            color: #666;
            margin-top: 5px;
        }

        /* Datos principales */
        .datos-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        .datos-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
        }

        .datos-box h3 {
            font-size: 10px;
            color: #1a365d;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
            padding-bottom: 5px;
            border-bottom: 1px solid #e2e8f0;
        }

        .datos-box .row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
        }

        .datos-box .label {
            color: #666;
            font-size: 10px;
        }

        .datos-box .value {
            font-weight: 600;
            color: #333;
            text-align: right;
        }

        /* Tabla de items */
        .items-section {
            margin-bottom: 15px;
        }

        .items-section h3 {
            font-size: 11px;
            color: #1a365d;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        .items-table thead th {
            background: #1a365d;
            color: white;
            padding: 8px 10px;
            text-align: left;
            font-weight: 600;
        }

        .items-table thead th:first-child {
            border-radius: 5px 0 0 0;
        }

        .items-table thead th:last-child {
            border-radius: 0 5px 0 0;
        }

        .items-table thead th.right {
            text-align: right;
        }

        .items-table tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        .items-table tbody td {
            padding: 8px 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        .items-table tbody td.right {
            text-align: right;
        }

        .items-table tbody td.center {
            text-align: center;
        }

        /* Totales */
        .totales-section {
            display: grid;
            /* grid-template-columns: 1fr 300px; */
            gap: 20px;
            margin-bottom: 20px;
        }

        .qr-container {
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .qr-code {
            width: 100px;
            height: 100px;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            padding: 5px;
            background: white;
        }

        .qr-code img {
            width: 100%;
            height: 100%;
        }

        .qr-info {
            font-size: 9px;
            color: #666;
        }

        .qr-info p {
            margin-bottom: 3px;
        }

        .totales-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
        }

        .totales-box .row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .totales-box .row:last-child {
            border-bottom: none;
        }

        .totales-box .row.total {
            font-size: 16px;
            font-weight: bold;
            color: #1a365d;
            padding-top: 10px;
            margin-top: 5px;
            border-top: 2px solid #1a365d;
        }

        .totales-box .label {
            color: #666;
        }

        .totales-box .value {
            font-weight: 600;
        }

        /* CDC */
        .cdc-section {
            background: #f0f4f8;
            border: 1px solid #cbd5e0;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
            text-align: center;
        }

        .cdc-section .label {
            font-size: 9px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }

        .cdc-section .value {
            font-family: 'Courier New', monospace;
            font-size: 11px;
            letter-spacing: 1px;
            color: #1a365d;
            word-break: break-all;
        }

        /* Footer */
        .footer {
            border-top: 1px solid #e2e8f0;
            padding-top: 10px;
            font-size: 9px;
            color: #666;
            text-align: center;
        }

        .footer p {
            margin-bottom: 3px;
        }

        /* Botones de acción (no se imprimen) */
        .actions {
            position: fixed;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 10px;
            z-index: 100;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-print {
            background: linear-gradient(135deg, #1a365d, #2c5282);
            color: white;
        }

        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(26, 54, 93, 0.3);
        }

        .btn-ticket {
            background: #f3f4f6;
            color: #374151;
        }

        .btn-ticket:hover {
            background: #e5e7eb;
        }

        @media print {
            body {
                background: white;
            }

            .page {
                box-shadow: none;
                margin: 0;
                padding: 0;
            }

            .actions {
                display: none;
            }
        }

        @media screen and (max-width: 800px) {
            .page {
                width: 100%;
                padding: 10px;
            }

            .header {
                grid-template-columns: 1fr;
            }

            .documento-tipo {
                text-align: left;
            }

            .datos-grid {
                grid-template-columns: 1fr;
            }

            .totales-section {
                /* grid-template-columns: 1fr; */
            }
        }

        /* Botones Email y WhatsApp */
        .btn-email {
            background: linear-gradient(135deg, #059669, #10b981);
            color: white;
        }

        .btn-whatsapp {
            background: #25D366;
            color: white;
        }

        .btn-email:hover,
        .btn-whatsapp:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            padding: 25px;
            border-radius: 12px;
            width: 400px;
            max-width: 90%;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
        }

        .modal-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #1a365d;
        }

        .modal-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 15px;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .modal-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }

        .modal-btn-cancel {
            background: #f3f4f6;
            color: #374151;
        }

        .modal-btn-send {
            background: linear-gradient(135deg, #059669, #10b981);
            color: white;
        }

        .modal-btn-wa {
            background: #25D366;
            color: white;
        }
    </style>
</head>

<body>






    <div class="page">
        <!-- Header -->
        <div class="header">
            <div class="empresa-info">
                <h1><?php echo htmlspecialchars($empresa['empresa'] ?? 'EMPRESA'); ?></h1>
                <p><strong>RUC:</strong> <?php echo htmlspecialchars($empresa['ruc'] ?? ''); ?>-<?php echo htmlspecialchars($empresa['dv'] ?? ''); ?></p>
                <p><?php echo htmlspecialchars($empresa['direccion'] ?? ''); ?></p>
                <p>Tel: <?php echo htmlspecialchars($empresa['telefono'] ?? ''); ?> | Email: <?php echo htmlspecialchars($empresa['email'] ?? ''); ?></p>
                <p><strong>Actividad Económica:</strong> <?php echo htmlspecialchars($empresa['des_act'] ?? $empresa['actividad'] ?? ''); ?></p>
            </div>
            <div class="documento-tipo">
                <div class="badge"><?php echo strtoupper($cdcData['tipo_nombre']); ?></div>
                <div class="numero"><?php echo htmlspecialchars($factura['nro_factura'] ?? ''); ?></div>
                <div class="timbrado">
                    Timbrado: <?php echo htmlspecialchars($factura['timbrado'] ?? ''); ?>
                </div>
            </div>
        </div>

        <!-- Datos del documento -->
        <div class="datos-grid">
            <div class="datos-box">
                <h3>Datos del Documento</h3>
                <div class="row">
                    <span class="label">Fecha de Emisión:</span>
                    <span class="value"><?php echo date('d/m/Y H:i', strtotime($factura['fecha'])); ?></span>
                </div>
                <div class="row">
                    <span class="label">Condición de Venta:</span>
                    <span class="value"><?php
                                        $condicion = $factura['forma_pago'] ?? $factura['tipo_venta'] ?? 1;
                                        echo is_numeric($condicion) ? ($condicion == 1 ? 'CONTADO' : 'CRÉDITO') : strtoupper($condicion);
                                        ?></span>
                </div>
                <div class="row">
                    <span class="label">Moneda:</span>
                    <span class="value">Guaraníes (PYG)</span>
                </div>
            </div>
            <div class="datos-box">
                <h3>Datos del Receptor</h3>
                <div class="row">
                    <span class="label">Razón Social:</span>
                    <span class="value"><?php echo htmlspecialchars($factura['cliente_nombre'] ?? 'Sin nombre'); ?></span>
                </div>
                <div class="row">
                    <span class="label">RUC/CI:</span>
                    <span class="value"><?php echo htmlspecialchars($factura['cliente_ruc'] ?? $factura['ruc'] ?? ''); ?></span>
                </div>
                <div class="row">
                    <span class="label">Dirección:</span>
                    <span class="value"><?php echo htmlspecialchars($factura['cliente_direccion'] ?? ''); ?></span>
                </div>
            </div>
        </div>

        <!-- Detalle de Items -->
        <div class="items-section">
            <!-- <h3>📦 Detalle de la Operación</h3> -->
            <table class="items-table">
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
                    <?php
                    $totalExenta = 0;
                    $totalIva5 = 0;
                    $totalIva10 = 0;

                    if (!empty($items)):
                        foreach ($items as $item):
                            $cantidad = $item['salida'] ?? $item['cantidad'] ?? 1;
                            $subtotal = $cantidad * ($item['precio'] ?? 0);

                            $tasaIva = $item['tasa_iva'] ?? 10;
                            // Sincronizar con lógica SIFEN (tipo_iva 1=Exenta, 2=5%, 3=10%)
                            if (isset($item['tipo_iva'])) {
                                if ($item['tipo_iva'] == 1) $tasaIva = 0;
                                elseif ($item['tipo_iva'] == 2) $tasaIva = 5;
                                elseif ($item['tipo_iva'] == 3) $tasaIva = 10;
                            }

                            if ($tasaIva == 0) $totalExenta += $subtotal;
                            elseif ($tasaIva == 5) $totalIva5 += $subtotal;
                            else $totalIva10 += $subtotal;
                    ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['producto_codigo'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($item['producto_nombre'] ?? $item['descripcion'] ?? 'Producto'); ?></td>
                                <td class="center"><?php echo number_format($cantidad, 0); ?></td>
                                <td class="right"><?php echo formatMoney($item['precio'] ?? 0); ?></td>
                                <td class="center"><?php echo ($tasaIva == 0 ? 'Exenta' : $tasaIva . '%'); ?></td>
                                <td class="right"><?php echo formatMoney($subtotal); ?></td>
                            </tr>
                        <?php
                        endforeach;
                    else:
                        $totalIva10 = (float)($factura['total'] ?? 0);
                        ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:20px; color:#666;">Detalle no disponible</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Totales y QR -->
        <div class="totales-section">
            <div class="qr-container" style="display: none;">
                <div class="qr-code">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($qrUrl); ?>" alt="QR">
                </div>
                <div class="qr-info">
                    <p><strong>Verificar documento:</strong></p>
                    <p>Escanee el código QR o ingrese a:</p>
                    <p style="color:#1a365d; font-weight:bold;">ekuatia.set.gov.py/consultas</p>
                    <p style="margin-top:5px;">e ingrese el CDC mostrado abajo.</p>
                </div>
            </div>
            <div class="totales-box">
                <div class="row">
                    <span class="label">Subtotal Exenta:</span>
                    <span class="value"><?php echo formatMoney($totalExenta); ?> Gs</span>
                </div>
                <div class="row">
                    <span class="label">Subtotal IVA 5%:</span>
                    <span class="value"><?php echo formatMoney($totalIva5); ?> Gs</span>
                </div>
                <div class="row">
                    <span class="label">Subtotal IVA 10%:</span>
                    <span class="value"><?php echo formatMoney($totalIva10); ?> Gs</span>
                </div>

                <?php
                $liq5 = (float)($totalIva5 / 21);
                $liq10 = (float)($totalIva10 / 11);
                ?>

                <div class="row" style="background:#f0fff4; border-top:1px dashed #ccc; margin-top:5px; padding-top:5px;">
                    <span class="label">Liquidación IVA 5%:</span>
                    <span class="value"><?php echo formatMoney($liq5); ?> Gs</span>
                </div>
                <div class="row" style="background:#f0fff4;">
                    <span class="label">Liquidación IVA 10%:</span>
                    <span class="value"><?php echo formatMoney($liq10); ?> Gs</span>
                </div>
                <div class="row" style="background:#f0fff4; font-weight:bold;">
                    <span class="label">Total Liquidación IVA:</span>
                    <span class="value"><?php echo formatMoney($liq5 + $liq10); ?> Gs</span>
                </div>

                <div class="row total">
                    <span class="label">TOTAL:</span>
                    <span class="value"><?php echo formatMoney($totalExenta + $totalIva5 + $totalIva10); ?> Gs</span>
                </div>
            </div>
        </div>

        <?php
        // Parse firma_digital for cash received/change info
        $firmaDigital = $factura['firma_digital'] ?? '';
        $efectivoRecibido = 0;
        $vuelto = 0;

        if (!empty($firmaDigital) && stripos($firmaDigital, 'RECIBIDO:') !== false) {
            if (preg_match('/RECIBIDO:\s*([\d.,]+)/i', $firmaDigital, $matches)) {
                $efectivoRecibido = floatval(str_replace(['.', ','], ['', '.'], $matches[1]));
            }
            if (preg_match('/VUELTO:\s*([\d.,]+)/i', $firmaDigital, $matches)) {
                $vuelto = floatval(str_replace(['.', ','], ['', '.'], $matches[1]));
            }
        }

        if ($efectivoRecibido > 0):
        ?>
            <div class="totals-grid" style="margin-top: 10px; padding-top: 10px; border-top: 1px dashed #ccc;">
                <div class="totals-summary">
                    <div class="row">
                        <span class="label" style="font-weight:bold;">Efectivo Entregado:</span>
                        <span class="value" style="font-weight:bold;"><?php echo formatMoney($efectivoRecibido); ?> Gs</span>
                    </div>
                    <?php if ($vuelto > 0): ?>
                        <div class="row total" style="font-size: 18px;">
                            <span class="label">VUELTO:</span>
                            <span class="value"><?php echo formatMoney($vuelto); ?> Gs</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- CDC -->
        <div class="cdc-section">
            <div class="label">Código de Control del Documento Electrónico (CDC)</div>
            <div class="value"><?php echo htmlspecialchars($cdc); ?></div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Este documento es la representación gráfica de un Documento Tributario Electrónico (KuDE)</p>
            <p>Consulte la validez en: <strong>ekuatia.set.gov.py/consultas</strong></p>
            <p style="margin-top:5px; color:#999;">Generado: <?php echo date('d/m/Y H:i:s'); ?> | Sistemax Gestión empresarial | www.sistemax.com.py</p>
        </div>
    </div>

    <script>
        // Autoprint
        if (window.location.search.includes('autoprint=1')) {
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            };
        }
    </script>
</body>

</html>