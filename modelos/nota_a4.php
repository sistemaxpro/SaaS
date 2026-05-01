<?php

/**
 * Nota A4 Print - Formato A4 Moderno para Notas Comunes
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
$id_empresa = isset($_SESSION['id_empresa']) && $_SESSION['id_empresa'] ? (int)$_SESSION['id_empresa'] : 169;

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
    <title>NOTA - <?php echo htmlspecialchars($factura['nro_factura'] ?? $id_factura); ?></title>
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
            font-family: 'Poppins', Roboto, sans-serif;
            font-size: 11px;
            line-height: 1.5;
            color: #334155;
            background: #f8fafc;
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto;
            background: #fff;
            padding: 20mm;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }

        .header {
            display: grid;
            grid-template-columns: 1fr 200px;
            gap: 20px;
            border-bottom: 2px solid #3b82f6;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }

        .empresa-info h1 {
            font-size: 24px;
            color: #1e3a8a;
            margin-bottom: 5px;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .empresa-info p {
            color: #64748b;
            font-size: 11px;
            margin-bottom: 2px;
        }

        .doc-tipo {
            text-align: right;
        }

        .badge {
            display: inline-block;
            background: #3b82f6;
            color: #fff;
            padding: 6px 15px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12px;
            margin-bottom: 10px;
        }

        .numero {
            font-size: 20px;
            font-weight: 800;
            color: #1e3a8a;
        }

        .fecha {
            font-size: 11px;
            color: #64748b;
            margin-top: 5px;
        }

        .client-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-card {
            background: #f1f5f9;
            border-radius: 10px;
            padding: 15px;
            border: 1px solid #e2e8f0;
        }

        .info-card h3 {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            margin-bottom: 10px;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 5px;
        }

        .row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }

        .label {
            color: #64748b;
        }

        .value {
            font-weight: 600;
            color: #1e293b;
            text-align: right;
        }

        .items-section {
            margin-bottom: 30px;
        }

        .items-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .items-table th {
            background: #1e3a8a;
            color: #fff;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            font-size: 10px;
            text-transform: uppercase;
        }

        .items-table th:first-child {
            border-radius: 8px 0 0 0;
        }

        .items-table th:last-child {
            border-radius: 0 8px 0 0;
        }

        .items-table tr:nth-child(even) {
            background: #f8fafc;
        }

        .items-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 11px;
        }

        .right {
            text-align: right;
        }

        .center {
            text-align: center;
        }

        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .totals-table {
            width: 300px;
        }

        .totals-table tr td {
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .totals-table tr.total td {
            border-bottom: none;
            padding-top: 15px;
        }

        .totals-table .total-label {
            font-size: 18px;
            font-weight: 800;
            color: #1e3a8a;
        }

        .totals-table .total-value {
            font-size: 22px;
            font-weight: 800;
            color: #1e3a8a;
            text-align: right;
        }

        .footer {
            margin-top: 50px;
            border-top: 1px solid #e2e8f0;
            padding-top: 20px;
            text-align: center;
        }

        .legal {
            font-size: 10px;
            color: #94a3b8;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .system {
            font-size: 9px;
            color: #cbd5e1;
        }

        @media print {
            body {
                background: #fff;
            }

            .page {
                box-shadow: none;
                margin: 0;
                padding: 10mm;
            }

            .no-print {
                display: none;
            }
        }

        .actions {
            position: fixed;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            font-size: 14px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-blue {
            background: #1e3a8a;
            color: #fff;
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.2);
        }

        .btn-blue:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(30, 58, 138, 0.3);
        }

        .btn-gray {
            background: #f1f5f9;
            color: #475569;
        }
    </style>
</head>

<body>
    <div class="page">
        <header class="header">
            <div class="empresa-info">
                <h1><?php echo htmlspecialchars($empresa['empresa'] ?? 'EMPRESA'); ?></h1>
                <p><strong>RUC:</strong> <?php echo htmlspecialchars($empresa['ruc'] ?? ''); ?>-<?php echo htmlspecialchars($empresa['dv'] ?? ''); ?></p>
                <p><?php echo htmlspecialchars($empresa['direccion'] ?? ''); ?></p>
                <p>Tel: <?php echo htmlspecialchars($empresa['telefono'] ?? ''); ?></p>
            </div>
            <div class="doc-tipo">
                <div class="badge">VENTA</div>
                <div class="numero"><?php echo htmlspecialchars($factura['nro_factura'] ?? 'Interno #' . $id_factura); ?></div>
                <div class="fecha">Fecha: <?php echo date('d/m/Y H:i', strtotime($factura['fecha'])); ?></div>
            </div>
        </header>

        <section class="client-section">
            <div class="info-card">
                <h3>Detalles del Documento</h3>
                <div class="row"><span class="label">Condición:</span><span class="value"><?php echo ($factura['forma_pago'] == 1 ? 'CONTADO' : 'CRÉDITO'); ?></span></div>
                <div class="row"><span class="label">Vendedor:</span><span class="value"><?php echo htmlspecialchars($factura['usuario'] ?? 'POS'); ?></span></div>
                <div class="row"><span class="label">Moneda:</span><span class="value">Guaraníes (PYG)</span></div>
            </div>
            <div class="info-card">
                <h3>Información del Cliente</h3>
                <div class="row"><span class="label">Nombre:</span><span class="value"><?php echo htmlspecialchars($factura['cliente_nombre'] ?? 'Consumidor Final'); ?></span></div>
                <div class="row"><span class="label">RUC/CI:</span><span class="value"><?php echo htmlspecialchars($factura['cliente_ruc'] ?? '4444440-1'); ?></span></div>
                <div class="row"><span class="label">Teléfono:</span><span class="value"><?php echo htmlspecialchars($factura['cliente_telefono'] ?? ''); ?></span></div>
            </div>
        </section>

        <section class="items-section">
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 100px;">Código</th>
                        <th>Descripción del Producto</th>
                        <th style="width: 80px;" class="center">Cant</th>
                        <th style="width: 120px;" class="right">P. Unitario</th>
                        <th style="width: 130px;" class="right">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): $cantidad = ($item['salida'] ?? 1);
                        $subtotal = $cantidad * ($item['precio'] ?? 0); ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['codigo'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($item['producto_nombre'] ?? 'Producto'); ?></td>
                            <td class="center"><?php echo number_format($cantidad, 0); ?></td>
                            <td class="right"><?php echo formatMoney($item['precio'] ?? 0); ?></td>
                            <td class="right"><?php echo formatMoney($subtotal); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <section class="totals-section">
            <table class="totals-table">
                <tr class="total">
                    <td class="total-label">TOTAL GENERAL</td>
                    <td class="total-value"><?php echo formatMoney($factura['total'] ?? 0); ?> Gs</td>
                </tr>
            </table>
        </section>

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
            <section class="totals-section" style="margin-top: 10px; border-top: 1px dashed #ccc; padding-top: 10px;">
                <table class="totals-table">
                    <tr>
                        <td class="total-label" style="font-weight: bold;">Efectivo Entregado</td>
                        <td class="total-value" style="font-weight: bold;"><?php echo formatMoney($efectivoRecibido); ?> Gs</td>
                    </tr>
                    <?php if ($vuelto > 0): ?>
                        <tr class="total">
                            <td class="total-label" style="font-size: 18px; font-weight: bold;">VUELTO</td>
                            <td class="total-value" style="font-size: 18px; font-weight: bold;"><?php echo formatMoney($vuelto); ?> Gs</td>
                        </tr>
                    <?php endif; ?>
                </table>
            </section>
        <?php endif; ?>

        <footer class="footer">
            <div class="legal">SIN VALIDEZ FISCAL - COMPROBANTE DE USO INTERNO</div>
            <div class="system">Documento generado el <?php echo date('d/m/Y H:i:s'); ?> | Sistema Gestión empresarial | www.sistemax.com.py</div>
        </footer>
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