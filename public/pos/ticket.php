<?php
/**
 * Ticket de Venta - Vista PDF/Web para impresión
 * Replica estructura de datos del ticket ESC/POS.
 */

$id_factura = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$id_empresa = isset($_GET['id_empresa']) ? (int)$_GET['id_empresa'] : 169;
$forcePdf = isset($_GET['pdf']) && (string)$_GET['pdf'] === '1';
$inModal = isset($_GET['in_modal']) && (string)$_GET['in_modal'] === '1';
$autoPrint = isset($_GET['autoprint']) && (string)$_GET['autoprint'] === '1';

if (!$id_factura) {
    die('Error: ID de venta requerido');
}

require_once __DIR__ . '/config/db_config.php';

function formatMoney($amount): string {
    return number_format((float)$amount, 0, ',', '.');
}

function formatDatePyTicket($raw): string {
    $v = trim((string)$raw);
    if ($v === '') return '';
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $v)) return $v;
    $formats = ['Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'd/m/Y H:i:s', 'd/m/Y H:i'];
    foreach ($formats as $f) {
        $d = DateTime::createFromFormat($f, $v);
        if ($d instanceof DateTime) return $d->format('d/m/Y');
    }
    $ts = strtotime($v);
    return $ts !== false ? date('d/m/Y', $ts) : '';
}

function normalizePaymentMethod($raw): string {
    $val = strtolower(trim((string)$raw));
    if ($val === '1' || $val === 'efectivo' || $val === 'contado') return 'efectivo';
    if ($val === '2' || $val === 'tarjeta') return 'tarjeta';
    if ($val === '3' || $val === 'transferencia' || $val === 'transfer') return 'transferencia';
    if ($val === '4' || $val === 'pix' || $val === 'qr') return 'pix';
    if ($val === '5' || $val === 'credito' || $val === 'crédito') return 'credito';
    if ($val === '6' || $val === 'pendiente') return 'pendiente';
    return $val;
}

function paymentLabel(string $raw): string {
    $m = normalizePaymentMethod($raw);
    if ($m === 'efectivo') return 'EFECTIVO';
    if ($m === 'tarjeta') return 'TARJETA';
    if ($m === 'transferencia') return 'TRANSFERENCIA';
    if ($m === 'pix') return 'QR / PIX';
    if ($m === 'credito') return 'CREDITO';
    if ($m === 'pendiente') return 'PENDIENTE';
    return strtoupper(trim((string)$raw));
}

function getCardPaymentData(PDO $pdo, string $dbName, int $idFactura): ?array {
    if ($dbName === '') return null;
    try {
        $pdo->query("SELECT 1 FROM {$dbName}.factura_ventas_pagos LIMIT 1");
    } catch (Throwable $e) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("\n            SELECT metodo, voucher_number, card_terminal_reference, card_auth_code, card_nsu, card_rrn, card_batch, card_brand\n            FROM {$dbName}.factura_ventas_pagos\n            WHERE id_factura = :id\n            ORDER BY id DESC\n            LIMIT 1\n        ");
        $stmt->execute([':id' => $idFactura]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) return null;
        if (normalizePaymentMethod($row['metodo'] ?? '') !== 'tarjeta') return null;
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function getPixPaymentData(PDO $pdo, string $dbName, int $idFactura): ?array {
    if ($dbName === '') return null;
    try {
        $pdo->query("SELECT 1 FROM {$dbName}.factura_ventas_pagos LIMIT 1");
    } catch (Throwable $e) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("\n            SELECT metodo, qr_transaction_code, created_at\n            FROM {$dbName}.factura_ventas_pagos\n            WHERE id_factura = :id\n            ORDER BY id DESC\n            LIMIT 1\n        ");
        $stmt->execute([':id' => $idFactura]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) return null;
        if (normalizePaymentMethod($row['metodo'] ?? '') !== 'pix') return null;

        $txid = trim((string)($row['qr_transaction_code'] ?? ''));
        $row['txid'] = $txid;
        $row['status'] = 'PENDIENTE';
        $row['paid_at'] = '';

        try {
            $pdo->query("SELECT 1 FROM {$dbName}.pagos_pix LIMIT 1");
            $st = $pdo->prepare("\n                SELECT txid, status, paid_at\n                FROM {$dbName}.pagos_pix\n                WHERE (id_factura = :id OR txid = :txid)\n                ORDER BY id DESC\n                LIMIT 1\n            ");
            $st->execute([':id' => $idFactura, ':txid' => $txid]);
            $px = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($px) {
                if (!empty($px['txid'])) $row['txid'] = (string)$px['txid'];
                if (!empty($px['status'])) $row['status'] = (string)$px['status'];
                if (!empty($px['paid_at'])) $row['paid_at'] = (string)$px['paid_at'];
            }
        } catch (Throwable $e) {
            // tabla opcional
        }
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function getCashPaymentData(PDO $pdo, string $dbName, int $idFactura): ?array {
    if ($dbName === '') return null;
    try {
        $pdo->query("SELECT 1 FROM {$dbName}.factura_ventas_pagos LIMIT 1");
    } catch (Throwable $e) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("\n            SELECT metodo, monto, cash_received, cash_change, card_capture_payload_json\n            FROM {$dbName}.factura_ventas_pagos\n            WHERE id_factura = :id AND LOWER(COALESCE(metodo, '')) = 'efectivo'\n            ORDER BY id DESC\n            LIMIT 1\n        ");
        $stmt->execute([':id' => $idFactura]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) return null;

        $cashReceived = (float)($row['cash_received'] ?? 0);
        $cashChange = (float)($row['cash_change'] ?? 0);
        $payload = json_decode((string)($row['card_capture_payload_json'] ?? ''), true);
        if (($cashReceived <= 0 || $cashChange <= 0) && is_array($payload) && isset($payload['raw']) && is_array($payload['raw'])) {
            $raw = $payload['raw'];
            if ($cashReceived <= 0) {
                $cashReceived = (float)($raw['cash_received'] ?? 0);
            }
            if ($cashChange <= 0) {
                $cashChange = (float)($raw['cash_change'] ?? 0);
            }
        }
        if ($cashReceived <= 0) {
            $cashReceived = (float)($row['monto'] ?? 0);
        }

        return [
            'cash_received' => $cashReceived,
            'cash_change' => max(0, $cashChange),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function getLatestPosPaymentData(PDO $pdo, string $dbName, int $idFactura): ?array {
    if ($dbName === '') return null;
    try {
        $pdo->query("SELECT 1 FROM {$dbName}.factura_ventas_pagos LIMIT 1");
    } catch (Throwable $e) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT metodo, monto, card_capture_payload_json, created_at
            FROM {$dbName}.factura_ventas_pagos
            WHERE id_factura = :id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':id' => $idFactura]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function getCreditPaymentData(?array $paymentRow): ?array {
    if (!is_array($paymentRow)) return null;
    if (normalizePaymentMethod($paymentRow['metodo'] ?? '') !== 'credito') return null;
    $payload = json_decode((string)($paymentRow['card_capture_payload_json'] ?? ''), true);
    $raw = is_array($payload['raw'] ?? null) ? $payload['raw'] : [];
    $installments = max(1, (int)($raw['credit_installments'] ?? $raw['installments'] ?? 1));
    $dueDate = trim((string)($raw['credit_due_date'] ?? $raw['due_date'] ?? ''));
    return [
        'installments' => $installments,
        'due_date' => $dueDate,
    ];
}

function getCreditInstallments(PDO $pdo, string $dbName, int $idFactura): array {
    if ($dbName === '' || $idFactura <= 0) return [];
    try {
        $pdo->query("SELECT 1 FROM {$dbName}.documentos LIMIT 1");
    } catch (Throwable $e) {
        return [];
    }
    try {
        $stmt = $pdo->prepare("
            SELECT numero, cantidad_cuota, fecha_vencimiento, total, pendiente, pagado
            FROM {$dbName}.documentos
            WHERE id_factura = :id
            ORDER BY fecha_vencimiento ASC, numero ASC
            LIMIT 24
        ");
        $stmt->execute([':id' => $idFactura]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function resolveIvaRate(array $item): int {
    $tasa = (int)($item['tasa_iva'] ?? 10);
    if (isset($item['tipo_iva'])) {
        if ((int)$item['tipo_iva'] === 1) $tasa = 0;
        elseif ((int)$item['tipo_iva'] === 2) $tasa = 5;
        elseif ((int)$item['tipo_iva'] === 3) $tasa = 10;
    }
    if (!in_array($tasa, [0, 5, 10], true)) $tasa = 10;
    return $tasa;
}

function fetchSerialesPorFacturaTicket(PDO $pdo, string $dbName, int $idFactura): array {
    if ($dbName === '' || $idFactura <= 0) return [];
    try {
        $stmt = $pdo->prepare("
            SELECT idproducto, GROUP_CONCAT(serie ORDER BY serie SEPARATOR ' | ') AS seriales
            FROM {$dbName}.producto_series
            WHERE id_factura = :id
            GROUP BY idproducto
        ");
        $stmt->execute([':id' => $idFactura]);
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $idproducto = (int)($row['idproducto'] ?? 0);
            if ($idproducto > 0) {
                $map[$idproducto] = trim((string)($row['seriales'] ?? ''));
            }
        }
        return $map;
    } catch (Throwable $e) {
        return [];
    }
}

function attachSerialesToItemsTicket(array &$items, array $serialesMap): void {
    if (empty($items) || empty($serialesMap)) return;
    foreach ($items as &$item) {
        $idproducto = (int)($item['idproducto'] ?? $item['id_producto'] ?? 0);
        $item['seriales'] = $idproducto > 0 ? (string)($serialesMap[$idproducto] ?? '') : '';
    }
    unset($item);
}

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $masterPdo = $conn['masterPdo'] ?? null;
    $dbName = $conn['dbName'] ?? '';
    $empresa = $conn['config'];

    if ($masterPdo instanceof PDO) {
        try {
            $stmtEmpMaster = $masterPdo->prepare("SELECT * FROM " . MASTER_DB . ".empresa WHERE id_empresa = :id LIMIT 1");
            $stmtEmpMaster->execute([':id' => $id_empresa]);
            $empresaMaster = $stmtEmpMaster->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($empresaMaster)) {
                $empresa = array_merge($empresaMaster, $empresa);
            }
        } catch (Throwable $e) {
            // continuar
        }
    }

    $selectFeQr = ", '' AS fe_qr_sifen";
    $joinFe = '';
    try {
        $hasFeTable = (bool)$pdo->query("SHOW TABLES LIKE 'fe'")->fetch(PDO::FETCH_NUM);
        if ($hasFeTable) {
            $hasFeQr = (bool)$pdo->query("SHOW COLUMNS FROM fe LIKE 'qr_sifen'")->fetch(PDO::FETCH_NUM);
            if ($hasFeQr) {
                $selectFeQr = ', fe.qr_sifen AS fe_qr_sifen';
                $joinFe = ' LEFT JOIN fe ON fe.id_factura = fv.id_factura ';
            }
        }
    } catch (Throwable $e) {
        // continuar sin dependencia de tabla/columna fe
    }

    $sqlFactura = "SELECT fv.*, c.nombre AS cliente_nombre, c.numero AS cliente_ruc,
               c.direccion AS cliente_direccion, c.email AS cliente_email,
               c.telefono AS cliente_telefono
               {$selectFeQr}
        FROM factura_ventas fv
        LEFT JOIN clientes c ON c.id = fv.id_cliente
        {$joinFe}
        WHERE fv.id_factura = :id";
    $stmtFactura = $pdo->prepare($sqlFactura);
    $stmtFactura->execute([':id' => $id_factura]);
    $factura = $stmtFactura->fetch(PDO::FETCH_ASSOC);

    if (!$factura) {
        die('Error: Factura no encontrada');
    }

    $idFacturaCol = 'idfactura';
    try {
        $stCols = $pdo->query("SHOW COLUMNS FROM extracto_productos");
        $cols = [];
        while ($c = $stCols->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = strtolower((string)($c['Field'] ?? ''));
        }
        if (in_array('id_factura', $cols, true)) {
            $idFacturaCol = 'id_factura';
        }
    } catch (Throwable $e) {
        // por defecto idfactura
    }

    $items = [];
    try {
        $stmtItems = $pdo->prepare("\n            SELECT\n                MAX(COALESCE(ep.idproducto, 0)) AS idproducto,\n                COALESCE(NULLIF(ep.codigo,''), '') AS codigo_barra,\n                COALESCE(ep.descripcion, 'Producto') AS producto_nombre,\n                COALESCE(ep.precio, 0) AS precio,\n                SUM(COALESCE(ep.salida, 0)) AS cantidad,\n                MAX(COALESCE(ep.tasa_iva, 10)) AS tasa_iva,\n                MAX(COALESCE(ep.tipo_iva, 3)) AS tipo_iva\n            FROM extracto_productos ep\n            WHERE ep.{$idFacturaCol} = :id AND ep.salida > 0\n            GROUP BY codigo_barra, producto_nombre, precio\n            ORDER BY producto_nombre\n        ");
        $stmtItems->execute([':id' => $id_factura]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $items = [];
    }

    if (empty($items)) {
        try {
            $stmtLegacy = $pdo->prepare("\n                SELECT\n                    COALESCE(imv.id_referencia, tp.idproducto, 0) AS idproducto,\n                    COALESCE(imv.codigo, '') AS codigo_barra,\n                    COALESCE(imv.descripcion, tp.desproducto, tp.descripcion, m.descripcion, 'Producto') AS producto_nombre,\n                    COALESCE(imv.precio, 0) AS precio,\n                    COALESCE(imv.cantidad, 1) AS cantidad,\n                    COALESCE(imv.tasa_iva, 10) AS tasa_iva,\n                    COALESCE(imv.tipo_iva, 3) AS tipo_iva\n                FROM item_mercaderia_venta imv\n                LEFT JOIN mercaderias m ON m.codigo = imv.codigo OR m.id = imv.id_referencia\n                LEFT JOIN tblproductos tp ON tp.cve_producto = imv.codigo OR tp.idproducto = imv.id_referencia\n                WHERE imv.id_factura = :id\n                ORDER BY imv.id\n            ");
            $stmtLegacy->execute([':id' => $id_factura]);
            $items = $stmtLegacy->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $items = [];
        }
    }

} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}

$serialesMap = fetchSerialesPorFacturaTicket($pdo, $dbName, (int)$id_factura);
attachSerialesToItemsTicket($items, $serialesMap);

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

$cashPayment = getCashPaymentData($pdo, $dbName, (int)$id_factura);
if (is_array($cashPayment)) {
    $efectivoRecibido = max($efectivoRecibido, (float)($cashPayment['cash_received'] ?? 0));
    $vuelto = max($vuelto, (float)($cashPayment['cash_change'] ?? 0));
}

$tipoDocumentoRaw = (string)($factura['tipo_documento'] ?? '');
$esAutoimpresa = ((int)$tipoDocumentoRaw === 1 || strtolower($tipoDocumentoRaw) === 'auto');
$esElectronica = ((int)$tipoDocumentoRaw === 3 || strtolower($tipoDocumentoRaw) === 'electro' || trim((string)($factura['cdc'] ?? '')) !== '');
$esNotaControl = !$esAutoimpresa && !$esElectronica;

$docTitulo = $esNotaControl ? 'NOTA DE CONTROL' : ($esAutoimpresa ? 'FACTURA AUTOGRAFIADA' : 'FACTURA ELECTRONICA');
$docSinValidez = $esNotaControl;

$timbradoAuto = trim((string)($factura['timbrado'] ?? ''));
$vencimientoAuto = trim((string)($factura['fecha_fin_timbrado'] ?? $factura['vencimiento'] ?? ''));
if ($esAutoimpresa && (empty($timbradoAuto) || empty($vencimientoAuto))) {
    try {
        if ($dbName !== '') {
            $stmtT = $pdo->prepare("SELECT timbrado, fecha_fin FROM {$dbName}.timbrado WHERE estado = 1 OR estado = 'activo' ORDER BY id DESC LIMIT 1");
            $stmtT->execute();
            $rowT = $stmtT->fetch(PDO::FETCH_ASSOC) ?: [];
            if (empty($timbradoAuto)) $timbradoAuto = trim((string)($rowT['timbrado'] ?? ''));
            if (empty($vencimientoAuto)) $vencimientoAuto = trim((string)($rowT['fecha_fin'] ?? ''));
        }
    } catch (Throwable $e) {
        // continuar
    }
}
if ($esAutoimpresa && $masterPdo instanceof PDO && (empty($timbradoAuto) || empty($vencimientoAuto))) {
    try {
        $stmtHab = $masterPdo->prepare("\n            SELECT numero_timbrado, timbrado, fecha_fin_vigencia\n            FROM " . MASTER_DB . ".habilitacion_sifen\n            WHERE id_empresa = :id AND activo = 1\n            ORDER BY id DESC\n            LIMIT 1\n        ");
        $stmtHab->execute([':id' => $id_empresa]);
        $hab = $stmtHab->fetch(PDO::FETCH_ASSOC) ?: [];
        if (empty($timbradoAuto)) $timbradoAuto = trim((string)($hab['numero_timbrado'] ?? $hab['timbrado'] ?? ''));
        if (empty($vencimientoAuto)) $vencimientoAuto = trim((string)($hab['fecha_fin_vigencia'] ?? ''));
    } catch (Throwable $e) {
        // continuar
    }
}
$vencimientoAutoFmt = formatDatePyTicket($vencimientoAuto);

$latestPayment = getLatestPosPaymentData($pdo, $dbName, (int)$id_factura);
$creditInstallments = getCreditInstallments($pdo, $dbName, (int)$id_factura);
$formaPagoSource = !empty($creditInstallments)
    ? 'credito'
    : ($latestPayment['metodo'] ?? ($factura['medio_cobro'] ?? $factura['forma_pago'] ?? $factura['cod_forma_pago'] ?? $factura['condicion_venta'] ?? ''));
$formaPagoNorm = normalizePaymentMethod($formaPagoSource);
$formaPagoLabel = paymentLabel($formaPagoNorm);

$cardPayment = getCardPaymentData($pdo, $dbName, (int)$id_factura);
$pixPayment = getPixPaymentData($pdo, $dbName, (int)$id_factura);
$creditPayment = getCreditPaymentData($latestPayment);

$lineas = [];
$totalExenta = 0.0;
$totalGrav5 = 0.0;
$totalGrav10 = 0.0;
$totalCalc = 0.0;

foreach ($items as $item) {
    $cantidad = (float)($item['cantidad'] ?? $item['salida'] ?? 1);
    if ($cantidad <= 0) $cantidad = 1;
    $precio = (float)($item['precio'] ?? 0);
    $desc = (string)($item['producto_nombre'] ?? $item['descripcion'] ?? 'Producto');
    $codigo = trim((string)($item['codigo_barra'] ?? $item['codigo'] ?? ''));
    $subtotal = $cantidad * $precio;
    $totalCalc += $subtotal;

    $tasa = resolveIvaRate($item);
    if ($tasa === 5) $totalGrav5 += $subtotal;
    elseif ($tasa === 10) $totalGrav10 += $subtotal;
    else $totalExenta += $subtotal;

    $lineas[] = [
        'codigo' => $codigo,
        'descripcion' => $desc,
        'seriales' => (string)($item['seriales'] ?? ''),
        'cantidad' => $cantidad,
        'precio' => $precio,
        'subtotal' => $subtotal,
    ];
}

$totalFactura = (float)($factura['total'] ?? 0);
if ($totalFactura <= 0) {
    $totalFactura = $totalCalc;
}

$liq5 = round($totalGrav5 / 21);
$liq10 = round($totalGrav10 / 11);

$clienteNombre = trim((string)($factura['cliente_nombre'] ?? $factura['cliente'] ?? 'CONSUMIDOR FINAL'));
if ($clienteNombre === '') $clienteNombre = 'CONSUMIDOR FINAL';
$clienteRuc = trim((string)($factura['cliente_ruc'] ?? $factura['ruc_cliente'] ?? ''));
$clienteDir = trim((string)($factura['cliente_direccion'] ?? $factura['direccion_cliente'] ?? ''));

$rucEmpresa = trim((string)($empresa['ruc'] ?? ''));
$dvEmpresa = trim((string)($empresa['dv'] ?? ''));
$rucEmpresaFmt = trim($rucEmpresa . ($dvEmpresa !== '' ? '-' . $dvEmpresa : ''));
$empresaNombre = trim((string)($empresa['empresa'] ?? 'EMPRESA'));
$empresaDireccion = trim((string)($empresa['direccion'] ?? ''));
$empresaTelefono = trim((string)($empresa['telefono'] ?? ''));
$empresaEmail = trim((string)($empresa['email'] ?? ''));
$empresaActividad = trim((string)($empresa['des_act'] ?? $empresa['actividad'] ?? ''));

$fechaVentaFmt = date('d/m/Y H:i', strtotime((string)($factura['fecha'] ?? 'now')));
$nroFactura = trim((string)($factura['nro_factura'] ?? ''));
if ($nroFactura === '') $nroFactura = 'INT-' . (int)$id_factura;

$notaQrUrl = "https://sistemax.pro/consultas/nota.php?id_factura={$id_factura}&id_empresa={$id_empresa}";
$cdc = trim((string)($factura['cdc'] ?? ''));
$qrSifen = trim((string)($factura['qr_sifen'] ?? $factura['fe_qr_sifen'] ?? ''));
if ($qrSifen !== '') {
    $qrSifen = html_entity_decode($qrSifen, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $qrSifen = str_replace('&amp;', '&', $qrSifen);
}
if ($qrSifen === '' && $cdc !== '') {
    $qrSifen = 'https://ekuatia.set.gov.py/consultas/qr?nVersion=150&Id=' . $cdc;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=80mm">
    <title><?php echo htmlspecialchars($docTitulo . ' - ' . $nroFactura); ?></title>
    <?php if ($forcePdf): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <?php endif; ?>
    <style>
        @page { size: 80mm auto; margin: 2mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 2mm;
            width: 76mm;
            font-family: "Courier New", monospace;
            font-size: 10px;
            line-height: 1.28;
            color: #000;
            background: #fff;
        }
        .no-print { margin-bottom: 10px; }
        .print-btn {
            display: block;
            width: 100%;
            padding: 10px;
            background: #3b82f6;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            margin: 10px 0;
        }
        .print-btn.alt { background: #0ea5e9; }
        .print-btn:active { background: #2563eb; }

        .ticket { text-align: left; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .doc-title {
            margin: 4px 0;
            padding: 2px 0;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            text-align: center;
            font-weight: bold;
            font-size: 12px;
        }
        .doc-nro {
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            margin: 4px 0;
        }
        .sep { border-top: 1px dashed #000; margin: 5px 0; }
        .block-title {
            font-weight: bold;
            margin-bottom: 2px;
        }
        .line {
            display: flex;
            justify-content: space-between;
            gap: 6px;
        }
        .line span:first-child { flex: 1; }
        .line span:last-child { text-align: right; }
        .line-wrap {
            margin-bottom: 2px;
            word-break: break-word;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }
        th {
            text-align: left;
            border-bottom: 1px solid #000;
            padding: 2px 1px;
        }
        td {
            padding: 2px 1px;
            vertical-align: top;
            border-bottom: 1px dotted #ddd;
        }
        td.right, th.right { text-align: right; }
        .total {
            margin-top: 4px;
            padding-top: 3px;
            border-top: 1px solid #000;
            font-size: 15px;
            font-weight: bold;
        }
        .qr {
            text-align: center;
            margin-top: 4px;
        }
        .qr img {
            width: 28mm;
            height: 28mm;
            object-fit: contain;
            border: 1px solid #ddd;
            padding: 2px;
            background: #fff;
        }
        .cdc {
            text-align: center;
            font-size: 9px;
            word-break: break-all;
            margin-top: 4px;
        }
        .small { font-size: 8px; }

        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="print-btn" onclick="<?php echo $forcePdf ? 'generarPdfEImprimirWeb()' : 'window.print()'; ?>">🖨️ IMPRIMIR</button>
        <?php if (!$forcePdf): ?>
        <button class="print-btn alt" onclick="generarPdfWeb()">📄 GENERAR PDF</button>
        <?php endif; ?>
    </div>

    <div id="ticketDocument" class="ticket">
        <div class="center bold" style="font-size:13px; text-transform: uppercase;"><?php echo htmlspecialchars($empresaNombre); ?></div>
        <?php if ($rucEmpresaFmt !== ''): ?><div class="center">RUC: <?php echo htmlspecialchars($rucEmpresaFmt); ?></div><?php endif; ?>
        <?php if ($empresaDireccion !== ''): ?><div class="center line-wrap"><?php echo htmlspecialchars($empresaDireccion); ?></div><?php endif; ?>
        <?php if ($empresaTelefono !== ''): ?><div class="center">Tel: <?php echo htmlspecialchars($empresaTelefono); ?></div><?php endif; ?>
        <?php if ($empresaEmail !== ''): ?><div class="center line-wrap">Email: <?php echo htmlspecialchars($empresaEmail); ?></div><?php endif; ?>
        <?php if ($esAutoimpresa && $empresaActividad !== ''): ?><div class="center line-wrap">Act: <?php echo htmlspecialchars($empresaActividad); ?></div><?php endif; ?>

        <div class="doc-title"><?php echo htmlspecialchars($docTitulo); ?></div>
        <div class="doc-nro">Nro: <?php echo htmlspecialchars($nroFactura); ?></div>

        <?php if ($esAutoimpresa): ?>
        <div class="center bold">Timbrado: <?php echo htmlspecialchars($timbradoAuto !== '' ? $timbradoAuto : 'N/D'); ?> - Venc: <?php echo htmlspecialchars($vencimientoAutoFmt !== '' ? $vencimientoAutoFmt : 'N/D'); ?></div>
        <?php elseif ($esElectronica && trim((string)($factura['timbrado'] ?? '')) !== ''): ?>
        <div class="center">Timbrado: <?php echo htmlspecialchars((string)$factura['timbrado']); ?></div>
        <?php endif; ?>

        <div class="center">Fecha: <?php echo htmlspecialchars($fechaVentaFmt); ?></div>

        <div class="sep"></div>

        <div class="block-title"><?php echo $esElectronica ? 'RECEPTOR' : 'CLIENTE'; ?></div>
        <div class="line"><span>Nombre:</span><span><?php echo htmlspecialchars($clienteNombre); ?></span></div>
        <?php if ($clienteRuc !== ''): ?><div class="line"><span>RUC/CI:</span><span><?php echo htmlspecialchars($clienteRuc); ?></span></div><?php endif; ?>
        <?php if ($clienteDir !== ''): ?><div class="line-wrap">Dir: <?php echo htmlspecialchars($clienteDir); ?></div><?php endif; ?>
        <?php if ($formaPagoLabel !== ''): ?>
        <div class="line"><span><?php echo $esNotaControl ? 'Pago:' : 'Cond.:'; ?></span><span><?php echo htmlspecialchars($formaPagoLabel); ?></span></div>
        <?php endif; ?>
        <?php if ($formaPagoNorm === 'pendiente'): ?>
        <div style="text-align:center; font-weight:bold; font-size:13px; border:2px solid #000; padding:4px 2px; margin:6px 0; letter-spacing:1px;">⚠ FACTURA PENDIENTE DE PAGO ⚠</div>
        <?php endif; ?>

        <div class="sep"></div>

        <div class="block-title">DETALLE</div>
        <table>
            <thead>
                <tr>
                    <th style="width:13%;">Cant</th>
                    <th style="width:47%;">Descripcion</th>
                    <th style="width:20%;" class="right">P.Unit</th>
                    <th style="width:20%;" class="right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($lineas)): ?>
                <tr><td colspan="4">(Sin detalle)</td></tr>
                <?php else: ?>
                <?php foreach ($lineas as $ln): ?>
                <tr>
                    <td><?php echo ((float)$ln['cantidad'] == (int)$ln['cantidad']) ? (int)$ln['cantidad'] : number_format((float)$ln['cantidad'], 2, ',', '.'); ?></td>
                    <td>
                        <?php if ($esNotaControl && $ln['codigo'] !== ''): ?><div class="bold small"><?php echo htmlspecialchars($ln['codigo']); ?></div><?php endif; ?>
                        <?php echo htmlspecialchars((string)$ln['descripcion']); ?>
                        <?php if (!empty($ln['seriales'])): ?><div class="small">IMEI/Serie: <?php echo htmlspecialchars((string)$ln['seriales']); ?></div><?php endif; ?>
                    </td>
                    <td class="right"><?php echo formatMoney($ln['precio']); ?></td>
                    <td class="right"><?php echo formatMoney($ln['subtotal']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($esAutoimpresa || $esElectronica): ?>
        <div class="sep"></div>
        <div class="line"><span>Sub. Exenta:</span><span><?php echo formatMoney($totalExenta); ?></span></div>
        <div class="line"><span>Sub. IVA 5%:</span><span><?php echo formatMoney($totalGrav5); ?></span></div>
        <div class="line"><span>Sub. IVA 10%:</span><span><?php echo formatMoney($totalGrav10); ?></span></div>
        <?php endif; ?>

        <div class="total line"><span>TOTAL:</span><span><?php echo formatMoney($totalFactura); ?> Gs</span></div>

        <?php if ($esAutoimpresa || $esElectronica): ?>
        <div class="sep"></div>
        <div class="block-title">LIQUIDACION DEL IVA</div>
        <div class="line"><span>IVA 5%:</span><span><?php echo formatMoney($liq5); ?></span></div>
        <div class="line"><span>IVA 10%:</span><span><?php echo formatMoney($liq10); ?></span></div>
        <div class="line"><span>Total IVA:</span><span><?php echo formatMoney($liq5 + $liq10); ?></span></div>
        <?php endif; ?>

        <?php if ($esAutoimpresa): ?>
        <div class="sep"></div>
        <div class="block-title">DETALLE DE COBRO</div>
        <div class="line"><span>Condicion:</span><span><?php echo htmlspecialchars($formaPagoLabel !== '' ? $formaPagoLabel : 'N/D'); ?></span></div>
        <?php if ($formaPagoNorm === 'efectivo'): ?>
            <?php
                $entrega = $efectivoRecibido > 0 ? $efectivoRecibido : $totalFactura;
                $vueltoCalc = $vuelto > 0 ? $vuelto : max(0, $entrega - $totalFactura);
            ?>
            <div class="line"><span>Entrega:</span><span><?php echo formatMoney($entrega); ?> Gs</span></div>
            <div class="line bold"><span>Vuelto:</span><span><?php echo formatMoney($vueltoCalc); ?> Gs</span></div>
        <?php else: ?>
            <?php if ($formaPagoNorm === 'credito' && is_array($creditPayment)): ?>
            <div class="line"><span>Cuotas:</span><span><?php echo (int)($creditPayment['installments'] ?? 1); ?></span></div>
            <?php $creditDueFmt = formatDatePyTicket((string)($creditPayment['due_date'] ?? '')); ?>
            <?php if ($creditDueFmt !== ''): ?>
            <div class="line"><span>Venc. 1ra:</span><span><?php echo htmlspecialchars($creditDueFmt); ?></span></div>
            <?php endif; ?>
            <?php else: ?>
            <div class="line"><span>Importe:</span><span><?php echo formatMoney($totalFactura); ?> Gs</span></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($formaPagoNorm === 'credito' && !empty($creditInstallments)): ?>
        <div class="sep"></div>
        <div class="block-title">PLAN DE CUOTAS</div>
        <?php foreach ($creditInstallments as $cuota): ?>
        <div class="line">
            <span>
                <?php echo htmlspecialchars((string)($cuota['cantidad_cuota'] ?? ('Cuota #' . (int)($cuota['numero'] ?? 0)))); ?>
                <?php $vencCuotaFmt = formatDatePyTicket((string)($cuota['fecha_vencimiento'] ?? '')); ?>
                <?php if ($vencCuotaFmt !== ''): ?>
                <?php echo ' - ' . htmlspecialchars($vencCuotaFmt); ?>
                <?php endif; ?>
            </span>
            <span><?php echo formatMoney((float)($cuota['total'] ?? $cuota['pendiente'] ?? 0)); ?> Gs</span>
        </div>
        <?php endforeach; ?>
        <div class="line bold"><span>Total:</span><span><?php echo formatMoney($totalFactura); ?> Gs</span></div>
        <?php endif; ?>
        <?php else: ?>
            <?php if ($efectivoRecibido > 0): ?>
            <div class="sep"></div>
            <div class="line"><span>Efectivo:</span><span><?php echo formatMoney($efectivoRecibido); ?> Gs</span></div>
            <?php if ($vuelto > 0): ?>
            <div class="line bold"><span>VUELTO:</span><span><?php echo formatMoney($vuelto); ?> Gs</span></div>
            <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($formaPagoNorm === 'tarjeta' && is_array($cardPayment)): ?>
        <div class="sep"></div>
        <div class="block-title">COBRO TARJETA</div>
        <?php if (!empty($cardPayment['card_brand'])): ?><div class="line-wrap">Marca: <?php echo htmlspecialchars((string)$cardPayment['card_brand']); ?></div><?php endif; ?>
        <?php
            $ref = trim((string)($cardPayment['card_terminal_reference'] ?? ''));
            if ($ref === '') $ref = trim((string)($cardPayment['voucher_number'] ?? ''));
        ?>
        <?php if ($ref !== ''): ?><div class="line-wrap">Ref: <?php echo htmlspecialchars($ref); ?></div><?php endif; ?>
        <?php if (!empty($cardPayment['card_auth_code'])): ?><div class="line-wrap">Aut: <?php echo htmlspecialchars((string)$cardPayment['card_auth_code']); ?></div><?php endif; ?>
        <?php if (!empty($cardPayment['card_nsu'])): ?><div class="line-wrap">NSU: <?php echo htmlspecialchars((string)$cardPayment['card_nsu']); ?></div><?php endif; ?>
        <?php if (!empty($cardPayment['card_rrn'])): ?><div class="line-wrap">RRN: <?php echo htmlspecialchars((string)$cardPayment['card_rrn']); ?></div><?php endif; ?>
        <?php if (!empty($cardPayment['card_batch'])): ?><div class="line-wrap">Lote: <?php echo htmlspecialchars((string)$cardPayment['card_batch']); ?></div><?php endif; ?>
        <?php endif; ?>

        <?php if ($formaPagoNorm === 'pix' && is_array($pixPayment)): ?>
        <div class="sep"></div>
        <div class="block-title">COBRO PIX</div>
        <?php if (!empty($pixPayment['txid'])): ?><div class="line-wrap">TXID: <?php echo htmlspecialchars((string)$pixPayment['txid']); ?></div><?php endif; ?>
        <?php if (!empty($pixPayment['status'])): ?><div class="line-wrap">Estado: <?php echo htmlspecialchars(strtoupper((string)$pixPayment['status'])); ?></div><?php endif; ?>
        <?php if (!empty($pixPayment['paid_at'])): ?><div class="line-wrap">Pagado: <?php echo htmlspecialchars((string)$pixPayment['paid_at']); ?></div><?php endif; ?>
        <?php endif; ?>

        <?php if ($esNotaControl): ?>
        <div class="sep"></div>
        <div class="qr">
            <div class="bold">QR DE CONSULTA</div>
            <img alt="QR" src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&ecc=M&margin=1&data=<?php echo rawurlencode($notaQrUrl); ?>">
        </div>
        <?php elseif ($esElectronica && $qrSifen !== ''): ?>
        <div class="sep"></div>
        <div class="qr">
            <div class="bold">QR DE CONSULTA</div>
            <img alt="QR" src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&ecc=M&margin=1&data=<?php echo rawurlencode($qrSifen); ?>">
        </div>
        <?php endif; ?>

        <?php if ($esElectronica && $cdc !== ''): ?>
        <div class="sep"></div>
        <div class="cdc">
            <div class="bold">CDC</div>
            <?php echo htmlspecialchars($cdc); ?>
        </div>
        <?php endif; ?>

        <div class="sep"></div>
        <?php if ($formaPagoNorm === 'pendiente'): ?>
        <div style="text-align:center; font-weight:bold; font-size:14px; border:2px solid #000; padding:6px 2px; margin:6px 0; letter-spacing:1px;">⚠ FACTURA PENDIENTE DE PAGO ⚠</div>
        <?php endif; ?>
        <div class="center bold"><?php echo $docSinValidez ? 'SIN VALIDEZ FISCAL' : 'Original: Cliente'; ?></div>
        <div class="center">Gracias por su compra!</div>
        <div class="center small">[WWW] sistemax.pro</div>
        <div class="center small" style="margin-top: 3px;">ID: <?php echo (int)$id_factura; ?> | <?php echo date('d/m/Y H:i:s'); ?></div>
    </div>

    <script>
    function buildPdfOptions() {
        return {
            margin: [2, 2, 2, 2],
            filename: 'ticket-<?php echo (int)$id_factura; ?>.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
            jsPDF: { unit: 'mm', format: [80, 260], orientation: 'portrait' },
            pagebreak: { mode: ['css', 'legacy'] }
        };
    }

    function loadHtml2PdfLib() {
        return new Promise((resolve, reject) => {
            if (typeof html2pdf !== 'undefined') {
                resolve();
                return;
            }
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    async function generarPdfWeb() {
        const root = document.getElementById('ticketDocument');
        if (!root) return;
        try {
            await loadHtml2PdfLib();
            await html2pdf().set(buildPdfOptions()).from(root).save();
        } catch (e) {
            console.error('Error al generar PDF:', e);
            window.print();
        }
    }

    async function generarPdfEImprimirWeb() {
        const root = document.getElementById('ticketDocument');
        if (!root) {
            window.print();
            return;
        }
        try {
            await loadHtml2PdfLib();
            const blobUrl = await html2pdf().set(buildPdfOptions()).from(root).outputPdf('bloburl');
            const inModal = <?php echo $inModal ? 'true' : 'false'; ?>;
            if (inModal) {
                window.location.href = blobUrl;
                return;
            }
            const pdfWindow = window.open(blobUrl, '_blank');
            if (pdfWindow) {
                setTimeout(() => {
                    try { pdfWindow.focus(); } catch (_) {}
                    try { pdfWindow.print(); } catch (_) {}
                }, 700);
            } else {
                window.print();
            }
        } catch (e) {
            console.error('No se pudo generar PDF, usando impresión normal:', e);
            window.print();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const forcePdf = <?php echo $forcePdf ? 'true' : 'false'; ?>;
        const inModal = <?php echo $inModal ? 'true' : 'false'; ?>;
        const autoPrint = <?php echo $autoPrint ? 'true' : 'false'; ?>;
        if (forcePdf && !inModal) {
            setTimeout(generarPdfEImprimirWeb, 350);
            return;
        }
        if (autoPrint) {
            setTimeout(() => window.print(), 350);
        }
    });
    </script>
</body>
</html>
