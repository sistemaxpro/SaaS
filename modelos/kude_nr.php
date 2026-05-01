<?php

/**
 * KUDE NR - Nota de Remisión Electrónica
 * Kuatia Documento Electrónico - Representación gráfica del DE tipo 7
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$id_remision = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id_remision) {
    die('Error: ID de remisión requerido');
}

// Conexión a BD
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';
$id_empresa = isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : (isset($_SESSION['id_empresa']) && $_SESSION['id_empresa'] ? (int)$_SESSION['id_empresa'] : 169);

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");

    // Obtener base de datos de empresa
    $stmtDB = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = :id");
    $stmtDB->execute([':id' => $id_empresa]);
    $dbName = $stmtDB->fetchColumn();

    if (!$dbName) {
        throw new Exception("Base de datos no encontrada para empresa ID: $id_empresa");
    }

    // Obtener datos de empresa
    $stmtEmpresa = $pdo->prepare("SELECT * FROM empresa WHERE id_empresa = :id");
    $stmtEmpresa->execute([':id' => $id_empresa]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

    // Obtener Nota de Remisión
    $stmtNR = $pdo->prepare("SELECT * FROM $dbName.nota_remision WHERE id_remision = :id");
    $stmtNR->execute([':id' => $id_remision]);
    $nr = $stmtNR->fetch(PDO::FETCH_ASSOC);

    if (!$nr) {
        throw new Exception("Nota de Remisión no encontrada: $id_remision");
    }

    // Obtener items
    $stmtItems = $pdo->prepare("SELECT * FROM $dbName.nota_remision_items WHERE id_remision = :id ORDER BY id_item");
    $stmtItems->execute([':id' => $id_remision]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $cdc = $nr['cdc'] ?? '';

    // URL QR SIFEN
    $qrUrl = 'https://ekuatia.set.gov.py/consultas/qr?nVersion=150&Id=' . $cdc;
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}

function formatDate($date)
{
    if (empty($date)) return '-';
    return date('d/m/Y', strtotime($date));
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
        'fecha' => substr($cdc, 25, 8)
    ];
}

$cdcData = parseCDC($cdc);
$nroFormateado = ($cdcData['establecimiento'] ?? '001') . '-' . ($cdcData['punto'] ?? '001') . '-' . str_pad($nr['nro_documento'], 7, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KUDE NR - <?php echo htmlspecialchars($nroFormateado); ?></title>
    <style>
        @page {
            size: A4;
            margin: 10mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
            padding: 10mm;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        @media print {
            body {
                background: white;
            }

            .page {
                box-shadow: none;
                margin: 0;
                padding: 5mm;
            }

            .no-print {
                display: none !important;
            }
        }

        .header {
            display: flex;
            border: 2px solid #333;
            margin-bottom: 5mm;
        }

        .header-left {
            flex: 1;
            padding: 4mm;
            border-right: 1px solid #333;
        }

        .header-right {
            width: 45%;
            padding: 4mm;
            text-align: center;
        }

        .empresa-nombre {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 3mm;
            color: #1a365d;
        }

        .empresa-info {
            font-size: 10px;
            line-height: 1.5;
        }

        .doc-tipo {
            font-size: 16px;
            font-weight: bold;
            color: #2c5282;
            margin-bottom: 2mm;
            padding: 2mm;
            background: #e8f4fd;
            border-radius: 3px;
        }

        .doc-numero {
            font-size: 18px;
            font-weight: bold;
            color: #333;
            margin-bottom: 2mm;
        }

        .timbrado-info {
            font-size: 9px;
            color: #666;
        }

        .cdc-section {
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 3mm;
            margin-bottom: 5mm;
            text-align: center;
        }

        .cdc-label {
            font-size: 9px;
            color: #666;
            margin-bottom: 1mm;
        }

        .cdc-value {
            font-size: 11px;
            font-family: 'Courier New', monospace;
            word-break: break-all;
            font-weight: bold;
        }

        .section {
            border: 1px solid #ddd;
            margin-bottom: 4mm;
            border-radius: 3px;
        }

        .section-title {
            background: #e8f4fd;
            padding: 2mm 3mm;
            font-weight: bold;
            font-size: 10px;
            color: #2c5282;
            border-bottom: 1px solid #ddd;
        }

        .section-content {
            padding: 3mm;
        }

        .row {
            display: flex;
            gap: 3mm;
            margin-bottom: 2mm;
        }

        .row:last-child {
            margin-bottom: 0;
        }

        .col {
            flex: 1;
        }

        .col-2 {
            flex: 2;
        }

        .field-label {
            font-size: 8px;
            color: #666;
            text-transform: uppercase;
        }

        .field-value {
            font-size: 10px;
            font-weight: 500;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        .items-table th {
            background: #e8f4fd;
            padding: 2mm;
            text-align: left;
            font-weight: bold;
            border-bottom: 2px solid #2c5282;
            font-size: 9px;
        }

        .items-table td {
            padding: 2mm;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }

        .items-table tr:nth-child(even) {
            background: #f9f9f9;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .qr-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-top: 5mm;
            padding-top: 5mm;
            border-top: 1px solid #ddd;
        }

        .qr-code {
            text-align: center;
        }

        .qr-code img {
            width: 30mm;
            height: 30mm;
        }

        .qr-label {
            font-size: 8px;
            color: #666;
            margin-top: 1mm;
        }

        .legal-text {
            flex: 1;
            padding-left: 5mm;
            font-size: 8px;
            color: #666;
            line-height: 1.4;
        }

        .estado-badge {
            display: inline-block;
            padding: 1mm 3mm;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
        }

        .estado-aprobado {
            background: #c6f6d5;
            color: #276749;
        }

        .estado-rechazado {
            background: #fed7d7;
            color: #c53030;
        }

        .estado-pendiente {
            background: #feebc8;
            color: #c05621;
        }

        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #2c5282;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .print-btn:hover {
            background: #1a365d;
        }
    </style>
</head>

<body>
    <button class="print-btn no-print" onclick="window.print()">
        🖨️ Imprimir
    </button>

    <div class="page">
        <!-- Encabezado -->
        <div class="header">
            <div class="header-left">
                <div class="empresa-nombre"><?php echo htmlspecialchars($empresa['empresa'] ?? ''); ?></div>
                <div class="empresa-info">
                    <strong>RUC:</strong> <?php echo htmlspecialchars(($empresa['ruc'] ?? '') . '-' . ($empresa['dv'] ?? '')); ?><br>
                    <strong>Dirección:</strong> <?php echo htmlspecialchars($empresa['direccion'] ?? ''); ?><br>
                    <strong>Teléfono:</strong> <?php echo htmlspecialchars($empresa['telefono'] ?? ''); ?><br>
                    <strong>Email:</strong> <?php echo htmlspecialchars($empresa['email'] ?? ''); ?>
                </div>
            </div>
            <div class="header-right">
                <div class="doc-tipo">NOTA DE REMISIÓN ELECTRÓNICA</div>
                <div class="doc-numero"><?php echo htmlspecialchars($nroFormateado); ?></div>
                <div class="timbrado-info">
                    Timbrado: <?php echo htmlspecialchars($nr['timbrado'] ?? $empresa['timbrado'] ?? ''); ?><br>
                    Fecha: <?php echo formatDate($nr['fecha']); ?>
                </div>
                <?php
                $estadoClass = 'estado-pendiente';
                if ($nr['estado_sifen'] === 'Aprobado') $estadoClass = 'estado-aprobado';
                if ($nr['estado_sifen'] === 'Rechazado') $estadoClass = 'estado-rechazado';
                ?>
                <div class="estado-badge <?php echo $estadoClass; ?>">
                    <?php echo htmlspecialchars($nr['estado_sifen'] ?? 'Pendiente'); ?>
                </div>
            </div>
        </div>

        <!-- CDC -->
        <?php if (!empty($cdc)): ?>
            <div class="cdc-section">
                <div class="cdc-label">CÓDIGO DE CONTROL (CDC)</div>
                <div class="cdc-value"><?php echo htmlspecialchars($cdc); ?></div>
            </div>
        <?php endif; ?>

        <!-- Motivo de Rechazo (si aplica) -->
        <?php if ($nr['estado_sifen'] === 'Rechazado' && !empty($nr['mensaje_sifen'])): ?>
            <?php
            $mensajeRechazo = '';
            $codigoRechazo = '';
            try {
                $infoRechazo = json_decode($nr['mensaje_sifen'], true);
                $codigoRechazo = $infoRechazo['data']['cod'] ?? $infoRechazo['codigo'] ?? '';
                $mensajeRechazo = $infoRechazo['data']['msg'] ?? $infoRechazo['mensaje'] ?? $nr['mensaje_sifen'];
            } catch (Exception $e) {
                $mensajeRechazo = $nr['mensaje_sifen'];
            }
            ?>
            <div class="section" style="border-color: #c53030; background: #fff5f5;">
                <div class="section-title" style="background: #fed7d7; color: #c53030;">⚠️ DOCUMENTO RECHAZADO POR SIFEN</div>
                <div class="section-content">
                    <div class="row">
                        <div class="col">
                            <div class="field-label">Código de Error</div>
                            <div class="field-value" style="color: #c53030; font-weight: bold;"><?php echo htmlspecialchars($codigoRechazo); ?></div>
                        </div>
                        <div class="col-2">
                            <div class="field-label">Mensaje</div>
                            <div class="field-value" style="color: #c53030;"><?php echo htmlspecialchars($mensajeRechazo); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Datos del Traslado -->
        <div class="section">
            <div class="section-title">📦 DATOS DEL TRASLADO</div>
            <div class="section-content">
                <div class="row">
                    <div class="col">
                        <div class="field-label">Motivo de Emisión</div>
                        <div class="field-value"><?php echo htmlspecialchars($nr['motivo_descripcion'] ?? 'Traslado'); ?></div>
                    </div>
                    <div class="col">
                        <div class="field-label">Fecha Inicio Traslado</div>
                        <div class="field-value"><?php echo formatDate($nr['fecha_inicio_traslado']); ?></div>
                    </div>
                    <div class="col">
                        <div class="field-label">Fecha Fin Traslado</div>
                        <div class="field-value"><?php echo formatDate($nr['fecha_fin_traslado']); ?></div>
                    </div>
                    <div class="col">
                        <div class="field-label">Km Estimados</div>
                        <div class="field-value"><?php echo number_format((float)($nr['km_estimado'] ?? 0), 0); ?> Km</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Destinatario -->
        <div class="section">
            <div class="section-title">👤 DESTINATARIO / RECEPTOR</div>
            <div class="section-content">
                <div class="row">
                    <div class="col">
                        <div class="field-label">RUC / CI</div>
                        <div class="field-value"><?php echo htmlspecialchars(($nr['receptor_ruc'] ?? '') . '-' . ($nr['receptor_dv'] ?? '')); ?></div>
                    </div>
                    <div class="col-2">
                        <div class="field-label">Nombre / Razón Social</div>
                        <div class="field-value"><?php echo htmlspecialchars($nr['receptor_nombre'] ?? ''); ?></div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-2">
                        <div class="field-label">Dirección</div>
                        <div class="field-value"><?php echo htmlspecialchars($nr['receptor_direccion'] ?? ''); ?></div>
                    </div>
                    <div class="col">
                        <div class="field-label">Ciudad</div>
                        <div class="field-value"><?php echo htmlspecialchars($nr['receptor_ciudad_nombre'] ?? ''); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Conductor -->
        <div class="section">
            <div class="section-title">🚗 CONDUCTOR Y VEHÍCULO</div>
            <div class="section-content">
                <div class="row">
                    <div class="col">
                        <div class="field-label">Documento Conductor</div>
                        <div class="field-value"><?php echo htmlspecialchars($nr['conductor_documento'] ?? ''); ?></div>
                    </div>
                    <div class="col-2">
                        <div class="field-label">Nombre Conductor</div>
                        <div class="field-value"><?php echo htmlspecialchars($nr['conductor_nombre'] ?? ''); ?></div>
                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        <div class="field-label">Tipo Vehículo</div>
                        <div class="field-value"><?php echo htmlspecialchars($nr['vehiculo_tipo'] ?? ''); ?></div>
                    </div>
                    <div class="col">
                        <div class="field-label">Marca</div>
                        <div class="field-value"><?php echo htmlspecialchars($nr['vehiculo_marca'] ?? ''); ?></div>
                    </div>
                    <div class="col">
                        <div class="field-label">Chapa</div>
                        <div class="field-value" style="font-weight: bold; font-size: 12px;"><?php echo htmlspecialchars($nr['vehiculo_chapa'] ?? ''); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Items / Mercaderías -->
        <div class="section">
            <div class="section-title">📋 MERCADERÍAS TRANSPORTADAS</div>
            <div class="section-content" style="padding: 0;">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width: 8%;">#</th>
                            <th style="width: 15%;">Código</th>
                            <th style="width: 52%;">Descripción</th>
                            <th style="width: 10%;" class="text-center">Cant.</th>
                            <th style="width: 15%;">Unidad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="5" class="text-center" style="padding: 10mm; color: #999;">
                                    Sin mercaderías registradas
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $index => $item): ?>
                                <tr>
                                    <td class="text-center"><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($item['codigo'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($item['descripcion'] ?? ''); ?></td>
                                    <td class="text-center"><?php echo number_format((float)($item['cantidad'] ?? 0), 0); ?></td>
                                    <td><?php
                                        $unidades = ['77' => 'UNI', '78' => 'KG', '79' => 'LT', '80' => 'MT'];
                                        echo $unidades[$item['unidad_medida'] ?? '77'] ?? 'UNI';
                                        ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- QR y Texto Legal -->
        <div class="qr-section">
            <div class="qr-code">
                <?php if (!empty($cdc)): ?>
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($qrUrl); ?>" alt="QR Code">
                    <div class="qr-label">Escanear para verificar</div>
                <?php endif; ?>
            </div>
            <div class="legal-text">
                <p><strong>Este documento es una representación gráfica de un Documento Electrónico (DE)</strong></p>
                <p>El documento original se encuentra en los sistemas informáticos de la SET.</p>
                <p>Para verificar la validez de este documento, escanee el código QR o visite:</p>
                <p><a href="https://ekuatia.set.gov.py/consultas" target="_blank">https://ekuatia.set.gov.py/consultas</a></p>
                <br>
                <p><strong>Nota de Remisión Electrónica</strong> emitida conforme a la Resolución General Nº 62/2021 de la SET.</p>
                <p style="margin-top: 2mm;">Generado: <?php echo date('d/m/Y H:i:s'); ?></p>
            </div>
        </div>
    </div>
</body>

</html>