<?php

/**
 * Recibo de Dinero - Plantilla de Impresión Ticket
 * Para cobros de facturas electrónicas a crédito
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$id_recibo = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$id_factura = isset($_GET['id_factura']) ? (int)$_GET['id_factura'] : 0;

if (!$id_recibo && !$id_factura) {
    die('Error: ID de recibo o factura requerido');
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

    // Obtener datos empresa
    $stmtEmpresa = $pdo->prepare("SELECT * FROM empresa WHERE id_empresa = :id");
    $stmtEmpresa->execute([':id' => $id_empresa]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

    $dbName = $empresa['dbase'] ?? 'dynamic';

    // Obtener recibo
    $where = $id_recibo ? "id = :id" : "id_factura = :id ORDER BY id DESC LIMIT 1";
    $stmtRecibo = $pdo->prepare("SELECT * FROM $dbName.recibos_cobro WHERE $where");
    $stmtRecibo->execute([':id' => $id_recibo ?: $id_factura]);
    $recibo = $stmtRecibo->fetch(PDO::FETCH_ASSOC);

    if (!$recibo) {
        throw new Exception("Recibo no encontrado");
    }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo <?php echo $recibo['nro_recibo']; ?></title>
    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            width: 80mm;
            padding: 5mm;
            background: #fff;
        }

        .ticket {
            width: 100%;
        }

        .header {
            text-align: center;
            padding-bottom: 10px;
            border-bottom: 1px dashed #000;
            margin-bottom: 10px;
        }

        .header h1 {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .header p {
            font-size: 10px;
            margin: 2px 0;
        }

        .titulo-recibo {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            padding: 10px 0;
            background: #000;
            color: #fff;
            margin: 10px 0;
        }

        .nro-recibo {
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 15px;
        }

        .seccion {
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #000;
        }

        .row {
            display: flex;
            justify-content: space-between;
            margin: 3px 0;
        }

        .label {
            font-weight: bold;
        }

        .monto-grande {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            padding: 15px 0;
            border: 2px solid #000;
            margin: 15px 0;
        }

        .monto-grande small {
            display: block;
            font-size: 10px;
            font-weight: normal;
        }

        .footer {
            text-align: center;
            font-size: 10px;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px dashed #000;
        }

        .footer p {
            margin: 3px 0;
        }

        .firma {
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #000;
            text-align: center;
        }

        .firma small {
            font-size: 10px;
        }

        .actions {
            position: fixed;
            top: 10px;
            right: 10px;
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }

        .btn-print {
            background: #16a34a;
            color: white;
        }

        .btn-close {
            background: #6b7280;
            color: white;
        }

        @media print {
            .actions {
                display: none;
            }

            body {
                width: 80mm;
            }
        }

        @media screen {
            body {
                max-width: 80mm;
                margin: 20px auto;
                box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
                padding: 10mm;
            }
        }
    </style>
</head>

<body>
    <div class="actions no-print">
        <button class="btn btn-print" onclick="window.print()">🖨️ Imprimir</button>
        <button class="btn btn-close" onclick="window.close()">✕ Cerrar</button>
    </div>

    <div class="ticket">
        <!-- Header Empresa -->
        <div class="header">
            <h1><?php echo htmlspecialchars($empresa['empresa'] ?? 'EMPRESA'); ?></h1>
            <p>RUC: <?php echo htmlspecialchars($empresa['ruc'] ?? ''); ?>-<?php echo htmlspecialchars($empresa['dv'] ?? ''); ?></p>
            <p><?php echo htmlspecialchars($empresa['direccion'] ?? ''); ?></p>
            <p>Tel: <?php echo htmlspecialchars($empresa['telefono'] ?? ''); ?></p>
        </div>

        <!-- Título -->
        <div class="titulo-recibo">RECIBO DE DINERO</div>
        <div class="nro-recibo"><?php echo $recibo['nro_recibo']; ?></div>

        <!-- Datos del Recibo -->
        <div class="seccion">
            <div class="row">
                <span class="label">Fecha:</span>
                <span><?php echo date('d/m/Y H:i', strtotime($recibo['fecha'])); ?></span>
            </div>
            <div class="row">
                <span class="label">Forma Pago:</span>
                <span><?php echo htmlspecialchars($recibo['forma_pago']); ?></span>
            </div>
        </div>

        <!-- Cliente -->
        <div class="seccion">
            <div class="label" style="margin-bottom:5px;">Recibí de:</div>
            <div style="font-weight:bold;"><?php echo htmlspecialchars($recibo['cliente_nombre']); ?></div>
        </div>

        <!-- Monto -->
        <div class="monto-grande">
            <small>La suma de Guaraníes</small>
            <?php echo formatMoney($recibo['monto']); ?> Gs
        </div>

        <!-- Concepto -->
        <div class="seccion">
            <div class="label">Concepto:</div>
            <div>Pago Factura: <?php echo htmlspecialchars($recibo['nro_factura']); ?></div>
            <?php if (!empty($recibo['observacion'])): ?>
                <div style="margin-top:5px; font-size:10px;"><?php echo htmlspecialchars($recibo['observacion']); ?></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($recibo['cdc'])): ?>
            <!-- CDC de la Factura -->
            <div class="seccion" style="font-size:9px; word-break:break-all;">
                <div class="label">CDC Factura:</div>
                <div><?php echo htmlspecialchars($recibo['cdc']); ?></div>
            </div>
        <?php endif; ?>

        <!-- Firma -->
        <div class="firma">
            <br><br><br>
            ________________________
            <br>
            <small>Firma y Sello</small>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>Documento no fiscal</p>
            <p>Generado: <?php echo date('d/m/Y H:i:s'); ?></p>
            <p>Powered by SistemaX</p>
        </div>
    </div>

    <script>
        // Auto-print si viene con parámetro
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