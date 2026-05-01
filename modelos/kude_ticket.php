<?php

/**
 * KUDE Ticket Print - Formato Ticket según Manual SIFEN v150
 * Kuatia Documento Electrónico - Representación gráfica simplificada del DE
 * 
 * Parámetros:
 *   - id: ID de la factura
 *   - cdc: Código de Control (opcional, se obtiene de BD si no se pasa)
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Parámetros
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
// Priorizar id_empresa de GET, luego SESSION, finalmente default
$id_empresa = isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : (isset($_SESSION['id_empresa']) && $_SESSION['id_empresa'] ? (int)$_SESSION['id_empresa'] : 169);

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");

    // Obtener base de datos de la empresa
    $stmtDB = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = :id");
    $stmtDB->execute([':id' => $id_empresa]);
    $dbName = $stmtDB->fetchColumn();

    if (!$dbName) {
        throw new Exception("Base de datos no encontrada para empresa ID: $id_empresa");
    }

    // API para actualización de teléfono del cliente vía AJAX
    if (isset($_POST['action']) && $_POST['action'] === 'save_phone') {
        header('Content-Type: application/json');
        try {
            $id_c = (int)($_POST['id_cliente'] ?? 0);
            $tel = trim($_POST['phone'] ?? '');
            if ($id_c > 0 && !empty($tel)) {
                // Actualiza el teléfono del cliente con el último utilizado en WhatsApp
                $upd = $pdo->prepare("UPDATE $dbName.clientes SET telefono = :tel WHERE id = :id");
                $upd->execute([':tel' => $tel, ':id' => $id_c]);
                echo json_encode(['success' => true, 'updated' => ($upd->rowCount() > 0)]);
            } else {
                echo json_encode(['success' => false, 'error' => 'ID de cliente o teléfono inválido']);
            }
        } catch (Exception $ex) {
            echo json_encode(['success' => false, 'error' => $ex->getMessage()]);
        }
        exit;
    }

    // Obtener datos de la empresa emisora
    $stmtEmpresa = $pdo->prepare("SELECT * FROM empresa WHERE id_empresa = :id");
    $stmtEmpresa->execute([':id' => $id_empresa]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

    // Obtener factura con datos del cliente
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

    // Usar CDC del parámetro o de la BD
    $cdc = !empty($cdc_param) ? $cdc_param : ($factura['cdc'] ?? '');
    if (empty($cdc)) throw new Exception("Esta factura no tiene CDC (no es electrónica)");

    // Obtener items
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

    // Generar URL del QR
    // Generar URL del QR Oficial SIFEN
    $qrUrl = !empty($factura['qr_sifen']) ? $factura['qr_sifen'] : '';
    if (empty($qrUrl)) {
        // Fallback a URL oficial si no existe en BD
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
    return [
        'ruc' => substr($cdc, 0, 8),
        'dv' => substr($cdc, 8, 1),
        'tipo_doc' => substr($cdc, 9, 2),
        'establecimiento' => substr($cdc, 11, 3),
        'punto' => substr($cdc, 14, 3),
        'numero' => substr($cdc, 17, 7),
        'tipo_contribuyente' => substr($cdc, 24, 1),
        'fecha' => substr($cdc, 25, 8),
        'tipo_emision' => substr($cdc, 33, 1),
        'codigo_seguridad' => substr($cdc, 34, 9),
        'dv_cdc' => substr($cdc, 43, 1)
    ];
}

$cdcData = parseCDC($cdc);
$tiposDe = ['01' => 'Factura electrónica', '02' => 'Factura electrónica de exportación', '03' => 'Factura electrónica de importación', '04' => 'Autofactura electrónica', '05' => 'Nota de crédito electrónica', '06' => 'Nota de débito electrónica', '07' => 'Nota de remisión electrónica'];
$tipoDocumento = $tiposDe[$cdcData['tipo_doc'] ?? '01'] ?? 'Documento Electrónico';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=80mm">
    <title>KUDE - <?php echo htmlspecialchars($factura['nro_factura'] ?? $cdc); ?></title>
    <style>
        @page {
            size: 80mm auto;
            margin: 2mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Courier New', monospace;
            font-size: 10px;
            line-height: 1.3;
            width: 76mm;
            margin: 0 auto;
            padding: 2mm;
            background: #fff;
            color: #000;
        }

        .header {
            text-align: center;
            border-bottom: 1px dashed #000;
            padding-bottom: 3mm;
            margin-bottom: 2mm;
        }

        .empresa-nombre {
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .empresa-ruc {
            font-size: 10px;
            margin: 1mm 0;
        }

        .tipo-doc {
            font-size: 11px;
            font-weight: bold;
            background: #000;
            color: #fff;
            padding: 1mm 2mm;
            margin: 2mm 0;
            display: inline-block;
        }

        .timbrado {
            font-size: 9px;
            color: #333;
        }

        .nro-factura {
            font-size: 14px;
            font-weight: bold;
            margin: 2mm 0;
        }

        .seccion {
            margin: 2mm 0;
            padding: 1mm 0;
        }

        .seccion-titulo {
            font-weight: bold;
            font-size: 9px;
            border-bottom: 1px solid #000;
            margin-bottom: 1mm;
        }

        .fila {
            display: flex;
            justify-content: space-between;
            font-size: 9px;
        }

        .fila-label {
            font-weight: bold;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 2mm 0;
            font-size: 8px;
        }

        .items-table th {
            border-bottom: 1px solid #000;
            padding: 1mm;
            text-align: left;
            font-size: 8px;
        }

        .items-table td {
            padding: 1mm;
            vertical-align: top;
        }

        .items-table .right {
            text-align: right;
        }

        .totales {
            border-top: 1px dashed #000;
            padding-top: 2mm;
            margin-top: 2mm;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            padding: 0.5mm 0;
        }

        .total-row.grande {
            font-size: 14px;
            font-weight: bold;
            border-top: 1px solid #000;
            padding-top: 1mm;
            margin-top: 1mm;
        }

        .qr-section {
            text-align: center;
            margin: 3mm 0;
            padding: 2mm;
            border: 1px dashed #000;
        }

        .qr-code {
            width: 35mm;
            height: 35mm;
            margin: 0 auto;
        }

        .qr-code img {
            width: 100%;
            height: 100%;
        }

        .cdc-section {
            text-align: center;
            font-size: 8px;
            word-break: break-all;
            margin: 2mm 0;
            padding: 1mm;
            background: #f0f0f0;
        }

        .cdc-label {
            font-weight: bold;
            font-size: 9px;
        }

        .footer {
            text-align: center;
            font-size: 8px;
            border-top: 1px dashed #000;
            padding-top: 2mm;
            margin-top: 3mm;
        }

        .footer-legal {
            font-size: 7px;
            color: #555;
            margin-top: 2mm;
        }

        @media print {
            body {
                width: 76mm;
            }

            .no-print {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="empresa-nombre"><?php echo htmlspecialchars($empresa['empresa'] ?? 'EMPRESA'); ?></div>
        <div class="empresa-ruc">RUC: <?php echo htmlspecialchars($empresa['ruc'] ?? ''); ?>-<?php echo htmlspecialchars($empresa['dv'] ?? ''); ?></div>
        <div class="timbrado"><?php echo htmlspecialchars($empresa['direccion'] ?? ''); ?><br>Tel: <?php echo htmlspecialchars($empresa['telefono'] ?? ''); ?><br>Actividad Económica: <?php echo htmlspecialchars($empresa['des_act'] ?? $empresa['actividad'] ?? ''); ?></div>
        <div class="tipo-doc"><?php echo strtoupper($tipoDocumento); ?></div>
        <div class="timbrado">Timbrado: <?php echo htmlspecialchars($factura['timbrado'] ?? ''); ?></div>
        <div class="nro-factura"><?php echo htmlspecialchars($factura['nro_factura'] ?? ''); ?></div>
        <div class="timbrado">Fecha: <?php echo date('d/m/Y H:i:s', strtotime($factura['fecha'])); ?></div>
    </div>

    <div class="seccion">
        <div class="seccion-titulo">RECEPTOR</div>
        <div class="fila"><span class="fila-label">Nombre:</span><span><?php echo htmlspecialchars($factura['cliente_nombre'] ?? 'Sin nombre'); ?></span></div>
        <div class="fila"><span class="fila-label">RUC/CI:</span><span><?php echo htmlspecialchars($factura['cliente_ruc'] ?? $factura['ruc'] ?? ''); ?></span></div>
        <?php if (!empty($factura['forma_pago'])): ?>
            <div class="fila"><span class="fila-label">Condición:</span><span style="font-weight:bold;"><?php echo strtoupper(htmlspecialchars($factura['forma_pago'])); ?></span></div>
        <?php endif; ?>
    </div>

    <div class="seccion">
        <div class="seccion-titulo">DETALLE</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width:10%">Cant</th>
                    <th style="width:50%">Descripción</th>
                    <th style="width:20%" class="right">P.Unit</th>
                    <th style="width:20%" class="right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $totalExenta = 0;
                $totalGrav5 = 0;
                $totalGrav10 = 0;

                foreach ($items as $item):
                    $cant = $item['salida'] ?? $item['cantidad'] ?? 1;
                    $precio = $item['precio'] ?? 0;
                    $subtotal = $cant * $precio;

                    $tasa = $item['tasa_iva'] ?? 10;
                    // Sincronizar con lógica SIFEN (tipo_iva 1=Exenta, 2=5%, 3=10%)
                    if (isset($item['tipo_iva'])) {
                        if ($item['tipo_iva'] == 1) $tasa = 0;
                        elseif ($item['tipo_iva'] == 2) $tasa = 5;
                        elseif ($item['tipo_iva'] == 3) $tasa = 10;
                    }

                    if ($tasa == 5) {
                        $totalGrav5 += $subtotal;
                    } elseif ($tasa == 10) {
                        $totalGrav10 += $subtotal;
                    } else {
                        $totalExenta += $subtotal;
                    }
                ?>
                    <tr>
                        <td><?php echo number_format($cant, 0); ?></td>
                        <td><?php echo htmlspecialchars($item['producto_nombre'] ?? 'Producto'); ?></td>
                        <td class="right"><?php echo formatMoney($precio); ?></td>
                        <td class="right"><?php echo formatMoney($subtotal); ?></td>
                    </tr>
                <?php endforeach;

                $liq5 = round($totalGrav5 / 21);
                $liq10 = round($totalGrav10 / 11);
                ?>
            </tbody>
        </table>
    </div>

    <div class="totales">
        <div style="font-size:9px; border-bottom:1px solid #000; margin-bottom:1mm; padding-bottom:1mm;">
            <div class="fila"><span>Subtotal Exenta:</span><span><?php echo formatMoney($totalExenta); ?></span></div>
            <div class="fila"><span>Subtotal IVA 5%:</span><span><?php echo formatMoney($totalGrav5); ?></span></div>
            <div class="fila"><span>Subtotal IVA 10%:</span><span><?php echo formatMoney($totalGrav10); ?></span></div>
        </div>

        <div class="total-row grande"><span>TOTAL:</span><span><?php echo formatMoney($totalExenta + $totalGrav5 + $totalGrav10); ?> Gs</span></div>

        <div style="font-size:8px; margin-top:2mm; padding-top:1mm; border-top:1px dashed #000;">
            <div style="font-weight:bold; margin-bottom:1mm;">LIQUIDACIÓN DEL IVA</div>
            <div class="fila">
                <span>(5%) <?php echo formatMoney($liq5); ?></span>
                <span>(10%) <?php echo formatMoney($liq10); ?></span>
                <span style="font-weight:bold">Tot IVA: <?php echo formatMoney($liq5 + $liq10); ?></span>
            </div>
        </div>
    </div>

    <?php
    // Parse firma_digital for cash received/change info
    $firmaDigital = $factura['firma_digital'] ?? '';
    $efectivoRecibido = 0;
    $vuelto = 0;

    if (!empty($firmaDigital) && stripos($firmaDigital, 'RECIBIDO:') !== false) {
        // Extract RECIBIDO value
        if (preg_match('/RECIBIDO:\s*([\d.,]+)/i', $firmaDigital, $matches)) {
            $efectivoRecibido = floatval(str_replace(['.', ','], ['', '.'], $matches[1]));
        }
        // Extract VUELTO value
        if (preg_match('/VUELTO:\s*([\d.,]+)/i', $firmaDigital, $matches)) {
            $vuelto = floatval(str_replace(['.', ','], ['', '.'], $matches[1]));
        }
    }

    if ($efectivoRecibido > 0):
    ?>
        <div class="seccion" style="border-top: 1px dashed #000; padding-top: 2mm; margin-top: 2mm;">
            <div class="fila" style="font-size: 10px;"><span style="font-weight:bold;">Efectivo Entregado:</span><span style="font-weight:bold;"><?php echo formatMoney($efectivoRecibido); ?> Gs</span></div>
            <?php if ($vuelto > 0): ?>
                <div class="fila" style="font-size: 12px; font-weight: bold; color: #000;"><span>VUELTO:</span><span><?php echo formatMoney($vuelto); ?> Gs</span></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="cdc-section">
        <div class="cdc-label">CDC</div>
        <div style="font-family: monospace; font-size: 8px; letter-spacing: 0.5px;"><?php echo htmlspecialchars($cdc); ?></div>
    </div>

    <div class="qr-section">
        <div class="qr-code"><img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($qrUrl); ?>" alt="QR SIFEN"></div>
    </div>

    <div class="footer">
        <div class="footer-legal">Documento KuDE - Consulte en ekuatia.set.gov.py</div>
        <div style="margin-top:2mm; font-size:8px;">Generado: <?php echo date('d/m/Y H:i:s'); ?></div>
    </div>

    <script>
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