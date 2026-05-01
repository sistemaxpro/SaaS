<?php
/**
 * API para generar comandos ESC/POS para impresión directa
 * Compatible con POS Steward, Golink, RawBT y otras apps de impresión
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/escpos_logo_helper.php';

try {
    $id_factura = (int)($_GET['id'] ?? 0);
    $id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
    $format = $_GET['format'] ?? 'escpos';
    $width = (int)($_GET['width'] ?? 32);
    $tipo = $_GET['tipo'] ?? 'ticket'; // ticket, nota, factura
    
    if (!$id_factura) {
        throw new Exception('ID de factura requerido');
    }
    
    // Usar getEmpresaConnection que devuelve array con pdo y masterPdo
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $masterPdo = $conn['masterPdo'];
    $empresa = $conn['config'];
    $dbName = $conn['dbName'];
    
    // Obtener datos de la venta (tabla factura_ventas)
    $stmt = $pdo->prepare("SELECT * FROM {$dbName}.factura_ventas WHERE id_factura = ?");
    $stmt->execute([$id_factura]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$venta) {
        throw new Exception('Venta no encontrada');
    }
    
    // Intentar obtener nombre de cliente si existe la columna
    $venta['cliente_nombre'] = $venta['cliente'] ?? $venta['nombre_cliente'] ?? $venta['razon_social'] ?? 'CONSUMIDOR FINAL';
    $venta['cliente_ruc'] = $venta['ruc_cliente'] ?? $venta['ci_cliente'] ?? '';
    
    // Agregar datos de empresa a venta
    $venta['nombre_empresa'] = $empresa['nombre_empresa'] ?? $empresa['razon_social'] ?? 'EMPRESA';
    $venta['empresa_ruc'] = $empresa['ruc'] ?? '';
    $venta['empresa_tel'] = $empresa['telefono'] ?? '';
    $venta['empresa_dir'] = $empresa['direccion'] ?? '';
    
    $detalles = [];

    // Fuente principal: extracto_productos (tolerante a idfactura/id_factura)
    try {
        $idFacturaCol = 'idfactura';
        try {
            $stCols = $pdo->query("SHOW COLUMNS FROM {$dbName}.extracto_productos");
            $cols = [];
            while ($c = $stCols->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = strtolower((string)($c['Field'] ?? ''));
            }
            if (in_array('id_factura', $cols, true)) {
                $idFacturaCol = 'id_factura';
            } elseif (in_array('idfactura', $cols, true)) {
                $idFacturaCol = 'idfactura';
            }
        } catch (Throwable $e) {}

        $stmtMain = $pdo->prepare("
            SELECT
                COALESCE(ep.descripcion, p.desproducto, 'Producto') AS producto_nombre,
                COALESCE(ep.precio, 0) AS precio,
                SUM(COALESCE(ep.salida, 0)) AS cantidad,
                COALESCE(MAX(ep.tasa_iva), 10) AS tasa_iva
            FROM {$dbName}.extracto_productos ep
            LEFT JOIN {$dbName}.tblproductos p ON p.idproducto = ep.idproducto
            WHERE ep.{$idFacturaCol} = :id AND ep.salida > 0
            GROUP BY producto_nombre, precio
        ");
        $stmtMain->execute([':id' => $id_factura]);
        $detalles = $stmtMain->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $detalles = [];
    }

    // Fallback a tablas de detalle
    if (empty($detalles)) {
        $tablas_detalle = ['factura_ventas_items', 'factura_ventas_detalle', 'factura_venta_detalle', 'detalle_factura', 'facturas_detalle', 'tblitemfacturas'];
        foreach ($tablas_detalle as $t) {
            try {
                $stmtCols = $pdo->query("DESCRIBE {$dbName}.{$t}");
                $cols = [];
                while ($c = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = strtolower((string)($c['Field'] ?? ''));
                }
                $idCol = in_array('id_factura', $cols, true) ? 'id_factura' : (in_array('idfactura', $cols, true) ? 'idfactura' : '');
                if ($idCol === '') continue;

                $descExpr = in_array('descripcion', $cols, true) ? 'fd.descripcion' : (in_array('producto', $cols, true) ? 'fd.producto' : (in_array('detalle', $cols, true) ? 'fd.detalle' : "'Producto'"));
                $precioExpr = in_array('precio', $cols, true) ? 'fd.precio' : (in_array('precio_unitario', $cols, true) ? 'fd.precio_unitario' : (in_array('precio_venta', $cols, true) ? 'fd.precio_venta' : '0'));
                $cantidadExpr = in_array('cantidad', $cols, true) ? 'fd.cantidad' : (in_array('cant', $cols, true) ? 'fd.cant' : (in_array('qty', $cols, true) ? 'fd.qty' : '1'));
                $ivaExpr = in_array('tasa_iva', $cols, true) ? 'fd.tasa_iva' : (in_array('iva', $cols, true) ? 'fd.iva' : '10');

                $stmt = $pdo->prepare("
                    SELECT
                        COALESCE({$descExpr}, 'Producto') AS producto_nombre,
                        COALESCE({$precioExpr}, 0) AS precio,
                        COALESCE({$cantidadExpr}, 1) AS cantidad,
                        COALESCE({$ivaExpr}, 10) AS tasa_iva
                    FROM {$dbName}.{$t} fd
                    WHERE fd.{$idCol} = :id
                ");
                $stmt->execute([':id' => $id_factura]);
                $tmp = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($tmp)) {
                    $detalles = $tmp;
                    break;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
    }
    
    $latestPayment = getLatestPosPaymentData($pdo, $dbName, $id_factura);
    $cardPayment = getCardPaymentData($pdo, $dbName, $id_factura);
    $creditInstallments = getCreditInstallments($pdo, $dbName, $id_factura);
    $creditPayment = getCreditPaymentData($latestPayment);

    // Generar comandos ESC/POS según tipo
    $escpos = generateESCPOS($venta, $detalles, $width, $tipo, $cardPayment, $empresa);
    
    echo json_encode([
        'success' => true,
        'data' => base64_encode($escpos),
        'format' => 'escpos',
        'encoding' => 'base64',
        'tipo' => $tipo,
        'width' => $width
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function generateESCPOS($venta, $detalles, $width = 32, $tipo = 'ticket', $cardPayment = null, $empresa = []) {
    $ESC = "\x1B";
    $GS = "\x1D";
    $LF = "\x0A";
    
    // Inicializar impresora
    $output = $ESC . "@";
    $output .= $GS . "!\x00";
    smxEscposAppendLogo($output, is_array($empresa) ? $empresa : [], (int)$width, $LF);
    $output .= $ESC . "a\x01"; // Centrar
    
    // === ENCABEZADO EMPRESA ===
    $output .= $ESC . "E\x01"; // Negrita ON
    $output .= mb_strtoupper(truncateText($venta['nombre_empresa'] ?? 'EMPRESA', $width)) . $LF;
    $output .= $ESC . "E\x00"; // Negrita OFF
    
    if (!empty($venta['empresa_ruc'])) {
        $output .= "RUC: " . $venta['empresa_ruc'] . $LF;
    }
    if (!empty($venta['empresa_dir'])) {
        $output .= truncateText($venta['empresa_dir'], $width) . $LF;
    }
    if (!empty($venta['empresa_tel'])) {
        $output .= "Tel: " . $venta['empresa_tel'] . $LF;
    }
    
    $output .= str_repeat('-', $width) . $LF;
    
    // === TIPO DE DOCUMENTO ===
    $output .= $ESC . "E\x01"; // Negrita
    
    // Determinar título según tipo
    switch ($tipo) {
        case 'factura':
            $tipoDoc = ($venta['tipo_factura'] ?? '') == 'FE' ? 'FACTURA ELECTRONICA' : 'FACTURA LEGAL';
            break;
        case 'nota':
            $tipoDoc = 'NOTA DE VENTA';
            break;
        default:
            $tipoDoc = 'TICKET DE VENTA';
            if (($venta['tipo_factura'] ?? '') == 'FE') {
                $tipoDoc = 'FACTURA ELECTRONICA';
            }
    }
    
    $output .= truncateText($tipoDoc, $width) . $LF;
    $output .= "Nro: " . ($venta['nro_factura'] ?? $venta['id_factura']) . $LF;
    $output .= $ESC . "E\x00";
    
    $fecha = date('d/m/Y H:i', strtotime($venta['fecha'] ?? 'now'));
    $output .= "Fecha: " . $fecha . $LF;
    
    // === TIMBRADO (solo para factura legal) ===
    if ($tipo === 'factura') {
        $tim = trim((string)($venta['timbrado'] ?? ''));
        $vencRaw = trim((string)($venta['fecha_fin_timbrado'] ?? $venta['vigencia_fin'] ?? $venta['vencimiento'] ?? $venta['fecha_vencimiento'] ?? ''));
        if ($tim !== '' || $vencRaw !== '') {
            $output .= "Timbrado:" . ($tim !== '' ? $tim : 'N/D') . " - Venc:" . (formatDatePrintTicket($vencRaw) ?: 'N/D') . $LF;
        } else if (!empty($venta['fecha_inicio_timbrado']) && !empty($venta['fecha_fin_timbrado'])) {
            $output .= "Vig: " . date('d/m/Y', strtotime($venta['fecha_inicio_timbrado'])) .
                       " al " . date('d/m/Y', strtotime($venta['fecha_fin_timbrado'])) . $LF;
        }
    }
    
    $output .= str_repeat('-', $width) . $LF;
    
    // === DATOS CLIENTE ===
    $output .= $ESC . "a\x00"; // Alinear izquierda
    $clienteNombre = $venta['cliente_nombre'] ?? 'CONSUMIDOR FINAL';
    $output .= "Cliente: " . truncateText($clienteNombre, $width - 9) . $LF;
    
    if (!empty($venta['cliente_ruc'])) {
        $output .= "RUC/CI: " . $venta['cliente_ruc'] . $LF;
    }
    
    // Dirección cliente (para factura legal)
    if ($tipo === 'factura' && !empty($venta['direccion_cliente'])) {
        $output .= "Dir: " . truncateText($venta['direccion_cliente'], $width - 5) . $LF;
    }
    
    $output .= str_repeat('-', $width) . $LF;
    
    // === DETALLE DE PRODUCTOS ===
    if ($width >= 40) {
        // Formato de 40 columnas: más espacio para descripción
        // Cant | Descripcion          | Precio   | Total
        $output .= $ESC . "a\x00";
        $output .= str_pad("Cant", 4) . str_pad("Descripcion", $width - 22) . str_pad("P.Unit", 9) . str_pad("Total", 9, ' ', STR_PAD_LEFT) . $LF;
        $output .= str_repeat('-', $width) . $LF;
    }
    
    $subtotal = 0;
    $iva5 = 0;
    $iva10 = 0;
    
    if (!empty($detalles)) {
        foreach ($detalles as $item) {
            $cant = (int)($item['cantidad'] ?? 1);
            if ($cant <= 0) $cant = 1;
            $precio = (float)($item['precio'] ?? 0);
            $desc = $item['producto_nombre'] ?? $item['descripcion'] ?? 'Producto';
            $lineTotal = $precio * $cant;
            $subtotal += $lineTotal;
            
            // Calcular IVA
            $tasa_iva = (int)($item['tasa_iva'] ?? 10);
            if ($tasa_iva == 5) {
                $iva5 += $lineTotal - ($lineTotal / 1.05);
            } else {
                $iva10 += $lineTotal - ($lineTotal / 1.1);
            }
            
            if ($width >= 40) {
                // Formato tabla 40 col
                $cantStr = str_pad($cant, 4);
                $descStr = truncateText($desc, $width - 22);
                $precioStr = str_pad(number_format($precio, 0, '', '.'), 9, ' ', STR_PAD_LEFT);
                $totalStr = str_pad(number_format($lineTotal, 0, '', '.'), 9, ' ', STR_PAD_LEFT);
                
                $output .= $cantStr . str_pad($descStr, $width - 22) . $precioStr . $totalStr . $LF;
            } else {
                // Formato original 32 col
                $output .= truncateText($desc, $width) . $LF;
                $detalleLine = $cant . " x " . number_format($precio, 0, '', '.') . " = " . number_format($lineTotal, 0, '', '.');
                $output .= str_pad($detalleLine, $width, ' ', STR_PAD_LEFT) . $LF;
            }
        }
    } else {
        $output .= "(Sin detalle)" . $LF;
    }
    
    $output .= str_repeat('=', $width) . $LF;
    
    // === TOTALES ===
    $output .= $ESC . "a\x02"; // Alinear derecha
    
    $total = $venta['total'] ?? $subtotal;
    
    // Para factura legal, mostrar desglose IVA
    if ($tipo === 'factura') {
        $output .= "Subtotal: Gs " . number_format($subtotal, 0, '', '.') . $LF;
        if ($iva5 > 0) {
            $output .= "IVA 5%: Gs " . number_format(round($iva5), 0, '', '.') . $LF;
        }
        if ($iva10 > 0) {
            $output .= "IVA 10%: Gs " . number_format(round($iva10), 0, '', '.') . $LF;
        }
        $output .= str_repeat('-', $width) . $LF;
    }
    
    $output .= $ESC . "E\x01"; // Negrita
    $output .= "TOTAL: Gs " . number_format($total, 0, '', '.') . $LF;
    $output .= $ESC . "E\x00";
    
    // Forma de pago
    $formaPagoRaw = !empty($creditInstallments)
        ? 'credito'
        : ($latestPayment['metodo'] ?? ($venta['medio_cobro'] ?? $venta['forma_pago'] ?? $venta['cod_forma_pago'] ?? 'efectivo'));
    $formaPago = normalizePaymentMethod($formaPagoRaw);
    $output .= "Condicion: " . ucfirst($formaPago) . $LF;
    if ($formaPago === 'credito' && is_array($creditPayment)) {
        $output .= "Cuotas: " . max(1, (int)($creditPayment['installments'] ?? 1)) . $LF;
        $dueFmt = formatDatePrintTicket((string)($creditPayment['due_date'] ?? ''));
        if ($dueFmt !== '') {
            $output .= "Venc. 1ra: " . $dueFmt . $LF;
        }
    } elseif ($formaPago !== 'credito') {
        $output .= "Importe: Gs " . number_format($total, 0, '', '.') . $LF;
    }
    if ($formaPago === 'credito' && !empty($creditInstallments)) {
        $output .= str_repeat('-', $width) . $LF;
        $output .= "Plan de cuotas" . $LF;
        foreach ($creditInstallments as $cuota) {
            $label = trim((string)($cuota['cantidad_cuota'] ?? ''));
            if ($label === '') {
                $label = 'Cuota #' . (int)($cuota['numero'] ?? 0);
            }
            $dueFmt = formatDatePrintTicket((string)($cuota['fecha_vencimiento'] ?? ''));
            $amount = number_format((float)($cuota['total'] ?? $cuota['pendiente'] ?? 0), 0, '', '.');
            $left = trim($label . ' ' . $dueFmt);
            $right = 'Gs ' . $amount;
            $output .= truncateText($left, max(8, $width - strlen($right) - 1));
            $output .= str_repeat(' ', max(1, $width - strlen(truncateText($left, max(8, $width - strlen($right) - 1))) - strlen($right)));
            $output .= $right . $LF;
        }
        $output .= "TOTAL: Gs " . number_format($total, 0, '', '.') . $LF;
    }
    
    // === PIE ===
    $output .= $ESC . "a\x01"; // Centrar
    $output .= str_repeat('-', $width) . $LF;
    
    if ($tipo === 'factura') {
        $output .= "Original: Cliente" . $LF;
        if (!empty($venta['cdc'])) {
            $output .= "CDC: " . $venta['cdc'] . $LF;
        }
    }

    if ($formaPago === 'tarjeta' && is_array($cardPayment)) {
        $output .= "Cobro Tarjeta:" . $LF;
        if (!empty($cardPayment['card_brand'])) {
            $output .= "Marca: " . truncateText($cardPayment['card_brand'], $width - 7) . $LF;
        }
        if (!empty($cardPayment['card_terminal_reference']) || !empty($cardPayment['voucher_number'])) {
            $ref = $cardPayment['card_terminal_reference'] ?: $cardPayment['voucher_number'];
            $output .= "Ref: " . truncateText($ref, $width - 5) . $LF;
        }
        if (!empty($cardPayment['card_auth_code'])) {
            $output .= "Aut: " . truncateText($cardPayment['card_auth_code'], $width - 5) . $LF;
        }
        if (!empty($cardPayment['card_nsu'])) {
            $output .= "NSU: " . truncateText($cardPayment['card_nsu'], $width - 5) . $LF;
        }
        if (!empty($cardPayment['card_rrn'])) {
            $output .= "RRN: " . truncateText($cardPayment['card_rrn'], $width - 5) . $LF;
        }
        if (!empty($cardPayment['card_batch'])) {
            $output .= "Lote: " . truncateText($cardPayment['card_batch'], $width - 6) . $LF;
        }
    }
    
    $output .= "Gracias por su compra!" . $LF;
    $output .= "SistemaX POS" . $LF;
    
    // Corte de papel
    $output .= $LF . $LF . $LF;
    $output .= $GS . "V\x00";
    
    return $output;
}

function truncateText($text, $maxLen) {
    $text = trim($text ?? '');
    $text = removeAccents($text);
    if (mb_strlen($text) > $maxLen) {
        return mb_substr($text, 0, $maxLen - 1) . '.';
    }
    return $text;
}

function removeAccents($string) {
    $unwanted = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'ñ' => 'n', 'Ñ' => 'N', 'ü' => 'u', 'Ü' => 'U'
    ];
    return strtr($string, $unwanted);
}

function normalizePaymentMethod($raw) {
    $val = strtolower(trim((string)$raw));
    if ($val === '1' || $val === 'efectivo' || $val === 'contado') return 'efectivo';
    if ($val === '2' || $val === 'tarjeta') return 'tarjeta';
    if ($val === '3' || $val === 'transferencia' || $val === 'transfer') return 'transferencia';
    if ($val === '4' || $val === 'pix' || $val === 'qr') return 'pix';
    if ($val === '5' || $val === 'credito' || $val === 'crédito') return 'credito';
    return $val !== '' ? $val : 'efectivo';
}

function formatDatePrintTicket($raw) {
    $v = trim((string)$raw);
    if ($v === '') return '';
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $v)) return $v;
    foreach (['Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'd/m/Y H:i:s', 'd/m/Y H:i'] as $f) {
        $d = DateTime::createFromFormat($f, $v);
        if ($d instanceof DateTime) return $d->format('d/m/Y');
    }
    $ts = strtotime($v);
    return $ts === false ? '' : date('d/m/Y', $ts);
}

function getCardPaymentData(PDO $pdo, string $dbName, int $idFactura): ?array {
    try {
        $pdo->query("SELECT 1 FROM {$dbName}.factura_ventas_pagos LIMIT 1");
    } catch (Throwable $e) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT metodo, voucher_number, card_terminal_reference, card_auth_code, card_nsu, card_rrn, card_batch, card_brand
            FROM {$dbName}.factura_ventas_pagos
            WHERE id_factura = :id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':id' => $idFactura]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) return null;
        if (normalizePaymentMethod($row['metodo'] ?? '') !== 'tarjeta') return null;
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function getLatestPosPaymentData(PDO $pdo, string $dbName, int $idFactura): ?array {
    try {
        $pdo->query("SELECT 1 FROM {$dbName}.factura_ventas_pagos LIMIT 1");
    } catch (Throwable $e) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT metodo, card_capture_payload_json
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
    return [
        'installments' => max(1, (int)($raw['credit_installments'] ?? $raw['installments'] ?? 1)),
        'due_date' => trim((string)($raw['credit_due_date'] ?? $raw['due_date'] ?? ''))
    ];
}

function getCreditInstallments(PDO $pdo, string $dbName, int $idFactura): array {
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
