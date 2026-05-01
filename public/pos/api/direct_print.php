<?php
/**
 * API: Impresión Directa a Impresora CUPS
 * Envía el ticket directamente a la impresora por defecto del sistema
 * sin pasar por el navegador.
 * 
 * POST /api/direct_print.php
 * Body JSON: { id_factura, id_empresa, id_caja }
 * 
 * Flujo:
 *  1. Obtiene la impresora por defecto de CUPS
 *  2. Genera contenido ESC/POS del ticket
 *  3. Envía directo a CUPS con lp -d <impresora>
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/escpos_logo_helper.php';

try {
    // ─── Leer parámetros ───
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id_factura = (int)($input['id_factura'] ?? $_GET['id_factura'] ?? 0);
    $id_empresa = (int)($input['id_empresa'] ?? $_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
    $id_caja    = (int)($input['id_caja']    ?? $_GET['id_caja']    ?? $_SESSION['id_caja_def'] ?? 0);
    
    if (!$id_factura) {
        throw new Exception('ID de factura requerido');
    }
    // ─── Conexión BD ───
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $masterPdo = $conn['masterPdo'];
    $empresa = $conn['config'];
    $dbName = $conn['dbName'];

    // ─── 1. Obtener impresora por defecto del sistema (CUPS) ───
    $lpDefaultOutput = trim((string)(shell_exec("lpstat -d 2>&1") ?? ''));
    if ($lpDefaultOutput === '' || stripos($lpDefaultOutput, 'no system default destination') !== false) {
        throw new Exception('No hay impresora por defecto configurada en el sistema (CUPS). Configure una impresora predeterminada e intente nuevamente.');
    }
    if (!preg_match('/destination:\s*(.+)$/i', $lpDefaultOutput, $mDefault)) {
        throw new Exception('No se pudo determinar la impresora por defecto del sistema.');
    }
    $printerName = trim($mDefault[1] ?? '');
    if ($printerName === '') {
        throw new Exception('No se pudo determinar la impresora por defecto del sistema.');
    }
    
    // ─── 2. Verificar que la impresora existe en CUPS ───
    $lpstatOutput = shell_exec("lpstat -p " . escapeshellarg($printerName) . " 2>&1") ?? '';
    $printerExists = (stripos($lpstatOutput, $printerName) !== false);
    
    // Si no existe exactamente, buscar por coincidencia parcial
    if (!$printerExists) {
        $allPrinters = shell_exec("lpstat -a 2>/dev/null") ?? '';
        if (stripos($allPrinters, $printerName) !== false) {
            $printerExists = true;
        }
    }
    
    if (!$printerExists) {
        throw new Exception("Impresora '{$printerName}' no encontrada en el sistema. Verifique que esté instalada en CUPS.");
    }

    // ─── 3. Obtener datos de la venta ───
    $stmtVenta = $pdo->prepare("
        SELECT fv.*, c.nombre AS cliente_nombre, c.numero AS cliente_ruc, 
               c.direccion AS cliente_direccion
        FROM {$dbName}.factura_ventas fv
        LEFT JOIN {$dbName}.clientes c ON c.id = fv.id_cliente
        WHERE fv.id_factura = ?
    ");
    $stmtVenta->execute([$id_factura]);
    $venta = $stmtVenta->fetch(PDO::FETCH_ASSOC);
    
    if (!$venta) {
        throw new Exception('Venta #' . $id_factura . ' no encontrada');
    }

    $cashPayment = null;
    foreach (['factura_ventas_pagos', 'factura_venta_pagos', 'pagos_factura_ventas'] as $paymentTable) {
        try {
            $stmtPay = $pdo->prepare("
                SELECT *
                FROM {$dbName}.{$paymentTable}
                WHERE id_factura = :id_factura AND LOWER(COALESCE(metodo, '')) = 'efectivo'
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtPay->execute([':id_factura' => $id_factura]);
            $cashPayment = $stmtPay->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($cashPayment) {
                break;
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    // ─── 4. Obtener items de la venta (robusto) ───
    $items = [];
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

        $stmtItems = $pdo->prepare("
            SELECT 
                MAX(i.id) as id,
                i.codigo, 
                i.descripcion AS producto_nombre, 
                i.precio, 
                SUM(i.salida) as cantidad
            FROM {$dbName}.extracto_productos i
            WHERE i.{$idFacturaCol} = ? AND i.salida > 0
            GROUP BY i.codigo, i.descripcion, i.precio
        ");
        $stmtItems->execute([$id_factura]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $items = [];
    }

    if (empty($items)) {
        $tablasDetalle = ['factura_ventas_items', 'factura_ventas_detalle', 'factura_venta_detalle', 'detalle_factura', 'facturas_detalle', 'tblitemfacturas'];
        foreach ($tablasDetalle as $td) {
            try {
                $stmtCols = $pdo->query("DESCRIBE {$dbName}.{$td}");
                $cols = [];
                while ($c = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = strtolower((string)($c['Field'] ?? ''));
                }
                $idCol = in_array('id_factura', $cols, true) ? 'id_factura' : (in_array('idfactura', $cols, true) ? 'idfactura' : '');
                if ($idCol === '') continue;

                $sql = "
                    SELECT
                        COALESCE(d.codigo, '') AS codigo,
                        COALESCE(d.descripcion, d.producto, d.detalle, 'Producto') AS producto_nombre,
                        COALESCE(d.precio, d.precio_unitario, d.precio_venta, 0) AS precio,
                        COALESCE(d.cantidad, d.cant, d.qty, 1) AS cantidad
                    FROM {$dbName}.{$td} d
                    WHERE d.{$idCol} = :id
                ";
                $stmtDet = $pdo->prepare($sql);
                $stmtDet->execute([':id' => $id_factura]);
                $tmp = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($tmp)) {
                    $items = $tmp;
                    break;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    $cardPayment = getCardPaymentData($pdo, $dbName, $id_factura);

    // ─── 5. Generar comandos ESC/POS ───
    $width = 32; // Ancho estándar 80mm
    $escpos = generateDirectTicket($empresa, $venta, $items, $width, $cardPayment);

    // ─── 6. Enviar a la impresora via CUPS ───
    $tmpFile = tempnam(sys_get_temp_dir(), 'ticket_');
    file_put_contents($tmpFile, $escpos);
    
    // lp -d <impresora> -o raw <archivo>
    $cmd = sprintf(
        'lp -d %s -o raw %s 2>&1',
        escapeshellarg($printerName),
        escapeshellarg($tmpFile)
    );
    
    $output = trim(shell_exec($cmd) ?? '');
    
    // lp devuelve "request id is PRINTER-NNN" en éxito, 
    // o cadena vacía/sin "error" también puede ser éxito
    $hasError = (stripos($output, 'error') !== false || stripos($output, 'not found') !== false 
                 || stripos($output, 'no such') !== false || stripos($output, 'unknown') !== false);
    $success = !$hasError && (stripos($output, 'request id') !== false || $output === '' || stripos($output, $printerName) !== false);
    
    // Limpiar archivo temporal
    @unlink($tmpFile);
    
    echo json_encode([
        'success' => $success,
        'message' => $success 
            ? "Ticket enviado a impresora '{$printerName}'" 
            : "Error al enviar a '{$printerName}': " . ($output ?: 'Sin respuesta del sistema de impresión'),
        'printer' => $printerName,
        'id_factura' => $id_factura,
        'debug' => [
            'cmd' => "lp -d {$printerName} -o raw ...",
            'cmd_output' => $output,
            'printer_status' => trim($lpstatOutput),
            'default_printer_raw' => $lpDefaultOutput
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// ═══════════════════════════════════════════════════
// Generador de ticket ESC/POS para impresión directa
// ═══════════════════════════════════════════════════
function generateDirectTicket($empresa, $venta, $items, $width = 32, $cardPayment = null) {
    $ESC = "\x1B";
    $GS  = "\x1D";
    $LF  = "\x0A";
    
    // Inicializar impresora
    $o = $ESC . "@";
    $o .= $GS . "!\x00";
    smxEscposAppendLogo($o, is_array($empresa) ? $empresa : [], (int)$width, $LF);

    // ═══ ENCABEZADO ═══
    $o .= $ESC . "a\x01"; // Centrar
    $o .= $ESC . "E\x01"; // Negrita ON
    $o .= $ESC . "!\x10"; // Doble alto
    $o .= esc_text(mb_strtoupper($empresa['empresa'] ?? 'EMPRESA'), $width) . $LF;
    $o .= $ESC . "!\x00"; // Normal
    $o .= $ESC . "E\x00"; // Negrita OFF
    
    $ruc = ($empresa['ruc'] ?? '') . '-' . ($empresa['dv'] ?? '');
    $o .= "RUC: " . $ruc . $LF;
    
    if (!empty($empresa['direccion'])) {
        $o .= esc_text($empresa['direccion'], $width) . $LF;
    }
    if (!empty($empresa['telefono'])) {
        $o .= "Tel: " . ($empresa['telefono']) . $LF;
    }
    
    $o .= str_repeat('-', $width) . $LF;
    
    // ═══ TIPO DOCUMENTO ═══
    $o .= $ESC . "E\x01";
    
    $hasCDC = !empty($venta['cdc']) && strlen($venta['cdc']) > 10;
    $tipoDoc = $hasCDC ? 'FACTURA ELECTRONICA' : 'NOTA DE CONTROL';
    $o .= esc_text($tipoDoc, $width) . $LF;
    
    $o .= "Nro: " . ($venta['nro_factura'] ?? 'INT-' . ($venta['id_factura'] ?? '')) . $LF;
    $o .= $ESC . "E\x00";
    
    $fecha = date('d/m/Y H:i', strtotime($venta['fecha'] ?? 'now'));
    $o .= "Fecha: " . $fecha . $LF;
    
    // Timbrado si existe
    if (!empty($venta['timbrado'])) {
        $o .= "Timbrado: " . $venta['timbrado'] . $LF;
    }
    
    $o .= str_repeat('-', $width) . $LF;
    
    // ═══ CLIENTE ═══
    $o .= $ESC . "a\x00"; // Izquierda
    $clienteNombre = $venta['cliente_nombre'] ?? $venta['cliente'] ?? 'CONSUMIDOR FINAL';
    $o .= "Cliente: " . esc_text($clienteNombre, $width - 9) . $LF;
    
    $clienteRuc = $venta['cliente_ruc'] ?? $venta['ruc_cliente'] ?? '';
    if (!empty($clienteRuc)) {
        $o .= "RUC/CI: " . $clienteRuc . $LF;
    }

    // Condición de venta
    $formaPago = normalizePaymentMethod($venta['forma_pago'] ?? $venta['cod_forma_pago'] ?? '');
    if (!empty($formaPago)) {
        $o .= "Condicion: " . mb_strtoupper(esc_text($formaPago, $width - 11)) . $LF;
    }
    
    $o .= str_repeat('-', $width) . $LF;
    
    // ═══ DETALLE ═══
    // Header de columnas
    $o .= str_pad("Cant", 5) . str_pad("Desc.", $width - 16) . str_pad("Total", 11, ' ', STR_PAD_LEFT) . $LF;
    $o .= str_repeat('-', $width) . $LF;
    
    $subtotal = 0;
    if (!empty($items)) {
        foreach ($items as $item) {
            $cant = (float)($item['cantidad'] ?? 1);
            if ($cant <= 0) $cant = 1;
            $precio = (float)($item['precio'] ?? 0);
            $desc = $item['producto_nombre'] ?? 'Producto';
            $lineTotal = $precio * $cant;
            $subtotal += $lineTotal;
            
            // Línea 1: descripción
            $o .= esc_text($desc, $width) . $LF;
            // Línea 2: cant x precio = total (alineado derecha)
            $detalle = number_format($cant, 0) . " x " . number_format($precio, 0, ',', '.') . " = " . number_format($lineTotal, 0, ',', '.');
            $o .= str_pad($detalle, $width, ' ', STR_PAD_LEFT) . $LF;
        }
    } else {
        $o .= "(Sin detalle)" . $LF;
    }
    
    $o .= str_repeat('=', $width) . $LF;
    
    // ═══ TOTAL ═══
    $o .= $ESC . "a\x02"; // Derecha
    $total = (float)($venta['total'] ?? $subtotal);
    
    $o .= $ESC . "E\x01"; // Negrita
    $o .= $ESC . "!\x10"; // Doble alto
    $o .= "TOTAL: Gs " . number_format($total, 0, ',', '.') . $LF;
    $o .= $ESC . "!\x00"; // Normal
    $o .= $ESC . "E\x00";
    
    // ═══ EFECTIVO / VUELTO ═══
    $firmaDigital = $venta['firma_digital'] ?? '';
    $efectivoRecibido = (float)($cashPayment['cash_received'] ?? 0);
    $vuelto = (float)($cashPayment['cash_change'] ?? 0);

    if (($efectivoRecibido <= 0 || $vuelto < 0) && !empty($firmaDigital)) {
        if (preg_match('/RECIBIDO:\s*([\d.,]+)/i', $firmaDigital, $m)) {
            $efectivoRecibido = floatval(str_replace(['.', ','], ['', '.'], $m[1]));
        }
        if (preg_match('/VUELTO:\s*([\d.,]+)/i', $firmaDigital, $m)) {
            $vuelto = floatval(str_replace(['.', ','], ['', '.'], $m[1]));
        }
    }
    
    if ($efectivoRecibido > 0) {
        $o .= $ESC . "a\x02"; // Derecha
        $o .= "Recibido: Gs " . number_format($efectivoRecibido, 0, ',', '.') . $LF;
        if ($vuelto > 0) {
            $o .= $ESC . "E\x01";
            $o .= "VUELTO: Gs " . number_format($vuelto, 0, ',', '.') . $LF;
            $o .= $ESC . "E\x00";
        }
    }
    
    // ═══ PIE ═══
    $o .= $ESC . "a\x01"; // Centrar
    $o .= str_repeat('-', $width) . $LF;
    
    if (!$hasCDC) {
        $o .= "SIN VALIDEZ FISCAL" . $LF;
    } else {
        $o .= "CDC: " . $venta['cdc'] . $LF;
    }

    if ($formaPago === 'tarjeta' && is_array($cardPayment)) {
        $o .= "Cobro Tarjeta" . $LF;
        if (!empty($cardPayment['card_brand'])) {
            $o .= "Marca: " . esc_text($cardPayment['card_brand'], $width - 7) . $LF;
        }
        if (!empty($cardPayment['card_terminal_reference']) || !empty($cardPayment['voucher_number'])) {
            $ref = $cardPayment['card_terminal_reference'] ?: $cardPayment['voucher_number'];
            $o .= "Ref: " . esc_text($ref, $width - 5) . $LF;
        }
        if (!empty($cardPayment['card_auth_code'])) {
            $o .= "Aut: " . esc_text($cardPayment['card_auth_code'], $width - 5) . $LF;
        }
        if (!empty($cardPayment['card_nsu'])) {
            $o .= "NSU: " . esc_text($cardPayment['card_nsu'], $width - 5) . $LF;
        }
        if (!empty($cardPayment['card_rrn'])) {
            $o .= "RRN: " . esc_text($cardPayment['card_rrn'], $width - 5) . $LF;
        }
        if (!empty($cardPayment['card_batch'])) {
            $o .= "Lote: " . esc_text($cardPayment['card_batch'], $width - 6) . $LF;
        }
    }
    
    $o .= $LF;
    $o .= "Gracias por su compra!" . $LF;
    $o .= "SistemaX POS" . $LF;
    $o .= $LF;
    
    // Avance de papel y corte
    $o .= $LF . $LF . $LF;
    $o .= $GS . "V\x00"; // Corte total
    
    return $o;
}

function esc_text($text, $maxLen = 32) {
    $text = trim($text ?? '');
    // Remover acentos para compatibilidad ESC/POS
    $unwanted = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
        'ñ' => 'n', 'Ñ' => 'N', 'ü' => 'u', 'Ü' => 'U'
    ];
    $text = strtr($text, $unwanted);
    if (mb_strlen($text) > $maxLen) {
        return mb_substr($text, 0, $maxLen - 1) . '.';
    }
    return $text;
}

function normalizePaymentMethod($raw) {
    $val = strtolower(trim((string)$raw));
    if ($val === '1' || $val === 'efectivo' || $val === 'contado') return 'efectivo';
    if ($val === '2' || $val === 'tarjeta') return 'tarjeta';
    if ($val === '3' || $val === 'transferencia' || $val === 'transfer') return 'transferencia';
    if ($val === '4' || $val === 'pix' || $val === 'qr') return 'pix';
    if ($val === '5' || $val === 'credito' || $val === 'crédito') return 'credito';
    return $val;
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
