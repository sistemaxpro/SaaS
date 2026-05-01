<?php

/**
 * Nota Ticket Print - Replica simplificada de KUDE (Sin QR/IVA/CDC)
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$id_factura = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id_factura) die('Error: ID de venta requerido');

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

    $stmtDB = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = :id");
    $stmtDB->execute([':id' => $id_empresa]);
    $dbName = $stmtDB->fetchColumn();

    $stmtEmpresa = $pdo->prepare("SELECT * FROM empresa WHERE id_empresa = :id");
    $stmtEmpresa->execute([':id' => $id_empresa]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

    $stmtFactura = $pdo->prepare("
        SELECT fv.*, c.nombre AS cliente_nombre, c.numero AS cliente_ruc, c.direccion AS cliente_direccion, c.email AS cliente_email, c.telefono AS cliente_telefono
        FROM $dbName.factura_ventas fv
        LEFT JOIN $dbName.clientes c ON c.id = fv.id_cliente
        WHERE fv.id_factura = :id
    ");
    $stmtFactura->execute([':id' => $id_factura]);
    $factura = $stmtFactura->fetch(PDO::FETCH_ASSOC);

    $stmtItems = $pdo->prepare("
        SELECT 
            MAX(i.id) as id,
            i.codigo, 
            i.descripcion AS producto_nombre, 
            i.precio, 
            SUM(i.salida) as salida 
        FROM $dbName.extracto_productos i
        WHERE i.idfactura = :id AND i.salida > 0
        GROUP BY i.codigo, i.descripcion, i.precio
    ");
    $stmtItems->execute([':id' => $id_factura]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}

function formatMoney($amount)
{
    return number_format((float)$amount, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=80mm">
    <title>NOTA - <?php echo htmlspecialchars($factura['nro_factura'] ?? $id_factura); ?></title>
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

        .total-row.grande {
            font-size: 14px;
            font-weight: bold;
            border-top: 1px solid #000;
            padding-top: 1mm;
            margin-top: 1mm;
        }

        .footer {
            text-align: center;
            font-size: 8px;
            border-top: 1px dashed #000;
            padding-top: 2mm;
            margin-top: 3mm;
        }

        @media print {
            .no-print {
                display: none !important;
            }
            html, body {
                width: 80mm;
                margin: 0;
                padding: 0;
            }
            body {
                padding: 1mm;
            }
            @page {
                size: 80mm auto;
                margin: 0;
            }
        }
    </style>
</head>

<body>
    <div class="header">
        <div class="empresa-nombre"><?php echo htmlspecialchars($empresa['empresa'] ?? 'EMPRESA'); ?></div>
        <div class="empresa-ruc">RUC: <?php echo htmlspecialchars($empresa['ruc'] ?? ''); ?>-<?php echo htmlspecialchars($empresa['dv'] ?? ''); ?></div>
        <div class="tipo-doc">NOTA DE VENTA</div>
        <div class="nro-factura"><?php echo htmlspecialchars($factura['nro_factura'] ?? 'Interno #' . $id_factura); ?></div>
        <div class="timbrado">Fecha: <?php echo date('d/m/Y H:i:s', strtotime($factura['fecha'])); ?></div>
    </div>
    <div class="seccion">
        <div class="seccion-titulo">CLIENTE</div>
        <div class="fila"><span>Nombre:</span><span><?php echo htmlspecialchars($factura['cliente_nombre'] ?? 'Consumidor Final'); ?></span></div>
        <div class="fila"><span>RUC/CI:</span><span><?php echo htmlspecialchars($factura['cliente_ruc'] ?? '4444440-1'); ?></span></div>
        <?php if (!empty($factura['forma_pago'])): ?>
            <div class="fila"><span>Condición:</span><span style="font-weight:bold;"><?php echo strtoupper(htmlspecialchars($factura['forma_pago'])); ?></span></div>
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
                <?php foreach ($items as $item): $cantidad = ($item['salida'] ?? 1);
                    $subtotal = $cantidad * ($item['precio'] ?? 0); ?>
                    <tr>
                        <td><?php echo number_format($cantidad, 0); ?></td>
                        <td><?php echo htmlspecialchars($item['producto_nombre'] ?? 'Producto'); ?></td>
                        <td class="right"><?php echo formatMoney($item['precio'] ?? 0); ?></td>
                        <td class="right"><?php echo formatMoney($subtotal); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="totales">
        <div class="total-row grande"><span>TOTAL:</span><span><?php echo formatMoney($factura['total'] ?? 0); ?> Gs</span></div>
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

    <div class="footer">
        <div class="footer-legal">SIN VALIDEZ FISCAL</div>
    </div>
    <script>
        // Auto-print: si viene con autoprint=1, imprime y cierra
        if (window.location.search.includes('autoprint=1')) {
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                    // Cerrar popup después de imprimir (si es popup)
                    if (window.opener) {
                        setTimeout(function() { window.close(); }, 800);
                    }
                }, 400);
            };
        }
    </script>
</body>

</html>