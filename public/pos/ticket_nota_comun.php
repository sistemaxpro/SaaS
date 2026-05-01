<?php
/**
 * Ticket ESC/POS — NOTA DE CONTROL (sin validez fiscal)
 * 
 * Genera comandos ESC/POS en base64 para impresión directa.
 * No incluye timbrado, CDC ni QR. Incluye efectivo/vuelto.
 * 
 * Parámetros GET:
 *   - id: ID de la factura
 *   - id_empresa: ID de empresa
 *   - width: ancho en columnas (32 o 48, default 32)
 * 
 * Respuesta JSON: { success, data (base64), format, tipo }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/db_config.php';
require_once __DIR__ . '/lib/escpos_logo_helper.php';

function fetchSerialesPorFacturaNota(PDO $pdo, string $dbName, int $idFactura): array
{
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

try {
    $id_factura = (int)($_GET['id'] ?? 0);
    $nro_factura_req = trim((string)($_GET['nro'] ?? ''));
    $id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
    $width = (int)($_GET['width'] ?? 48);

    if ($width < 32) $width = 32;
    if ($width > 80) $width = 80;

    if (!$id_factura) {
        throw new Exception('ID de factura requerido');
    }

    // Conexión
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $masterPdo = $conn['masterPdo'];
    $empresa = $conn['config'];
    $dbName = $conn['dbName'];
    $empresaNombreMaster = '';
    try {
        $stmtEmp = $masterPdo->prepare("SELECT * FROM " . MASTER_DB . ".empresa WHERE id_empresa = ? LIMIT 1");
        $stmtEmp->execute([$id_empresa]);
        $empresaMaster = $stmtEmp->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($empresaMaster)) {
            $empresa = array_merge($empresaMaster, $empresa);
            $empresaNombreMaster = trim((string)($empresaMaster['empresa'] ?? ''));
        }
    } catch (Throwable $e) {}

    // Datos de la venta con cliente (con fallback a conexión master/dbase real)
    $fetchVenta = function ($pdoConn, $db) use ($id_factura, $nro_factura_req) {
        $sql = "
            SELECT fv.*, 
                   c.nombre AS cliente_nombre, 
                   c.numero AS cliente_ruc,
                   c.direccion AS cliente_direccion
            FROM {$db}.factura_ventas fv
            LEFT JOIN {$db}.clientes c ON c.id = fv.id_cliente
            WHERE fv.id_factura = :id
        ";
        if ($nro_factura_req !== '') {
            $sql .= " OR fv.nro_factura = :nro";
        }
        $sql .= " ORDER BY fv.id_factura DESC LIMIT 1";

        $stmt = $pdoConn->prepare($sql);
        $stmt->bindValue(':id', $id_factura, PDO::PARAM_INT);
        if ($nro_factura_req !== '') {
            $stmt->bindValue(':nro', $nro_factura_req, PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $venta = $fetchVenta($pdo, $dbName);
    if (!$venta) {
        $stmtDb = $masterPdo->prepare("SELECT dbase FROM " . MASTER_DB . ".empresa WHERE id_empresa = ? LIMIT 1");
        $stmtDb->execute([$id_empresa]);
        $dbMaster = (string)$stmtDb->fetchColumn();
        if ($dbMaster && $dbMaster !== $dbName) {
            $pdo = $masterPdo;
            $dbName = $dbMaster;
            $venta = $fetchVenta($pdo, $dbName);
        }
    }

    if (!$venta) {
        throw new Exception('Venta no encontrada');
    }

    // Items: usar SIEMPRE el id_factura real encontrado y aplicar fallback robusto
    $idFacturaReal = (int)($venta['id_factura'] ?? $id_factura);
    $items = [];

    // 1) Fuente principal: extracto_productos (tolerante a idfactura/id_factura)
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
                MAX(COALESCE(ep.idproducto, 0)) AS idproducto,
                COALESCE(NULLIF(p.cve_producto,''), ep.codigo, '') AS codigo_barra,
                COALESCE(ep.descripcion, p.desproducto, 'Producto') AS producto_nombre,
                COALESCE(ep.precio, 0) AS precio,
                SUM(COALESCE(ep.salida, 0)) AS cantidad
            FROM {$dbName}.extracto_productos ep
            LEFT JOIN {$dbName}.tblproductos p ON p.idproducto = ep.idproducto
            WHERE ep.{$idFacturaCol} = :id AND ep.salida > 0
            GROUP BY codigo_barra, producto_nombre, precio
        ");
        $stmtItems->execute([':id' => $idFacturaReal]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $items = [];
    }

    // 2) Fallback legacy: item_mercaderia_venta
    if (empty($items)) {
        try {
            $stmtLegacy = $pdo->prepare("
                SELECT
                    COALESCE(imv.id_referencia, tp.idproducto, 0) AS idproducto,
                    COALESCE(imv.codigo, '') AS codigo_barra,
                    COALESCE(tp.desproducto, tp.descripcion, m.descripcion, imv.codigo, 'Producto') AS producto_nombre,
                    COALESCE(imv.precio, 0) AS precio,
                    COALESCE(imv.cantidad, 1) AS cantidad
                FROM {$dbName}.item_mercaderia_venta imv
                LEFT JOIN {$dbName}.mercaderias m
                    ON m.codigo = imv.codigo OR m.id = imv.id_referencia
                LEFT JOIN {$dbName}.tblproductos tp
                    ON tp.cve_producto = imv.codigo
                    OR tp.referencia = imv.codigo
                    OR tp.idproducto = imv.id_referencia
                WHERE imv.id_factura = :id
                ORDER BY imv.id
            ");
            $stmtLegacy->execute([':id' => $idFacturaReal]);
            $items = $stmtLegacy->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            // continuar
        }
    }

    // 3) Fallback genérico a tablas de detalle comunes
    if (empty($items)) {
        $tablasDetalle = ['factura_ventas_items', 'factura_ventas_detalle', 'factura_venta_detalle', 'detalle_factura', 'facturas_detalle', 'tblitemfacturas'];
        foreach ($tablasDetalle as $td) {
            try {
                $stmtCols = $pdo->query("DESCRIBE {$dbName}.{$td}");
                $cols = [];
                while ($c = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = strtolower((string)$c['Field']);
                }
                $idCol = in_array('id_factura', $cols, true) ? 'id_factura' : (in_array('idfactura', $cols, true) ? 'idfactura' : '');
                if ($idCol === '') continue;

                $descExpr = in_array('descripcion', $cols, true) ? 'd.descripcion' : (in_array('producto', $cols, true) ? 'd.producto' : (in_array('detalle', $cols, true) ? 'd.detalle' : "'Producto'"));
                $precioExpr = in_array('precio', $cols, true) ? 'd.precio' : (in_array('precio_unitario', $cols, true) ? 'd.precio_unitario' : (in_array('precio_venta', $cols, true) ? 'd.precio_venta' : '0'));
                $cantidadExpr = in_array('cantidad', $cols, true) ? 'd.cantidad' : (in_array('cant', $cols, true) ? 'd.cant' : (in_array('qty', $cols, true) ? 'd.qty' : '1'));
                $codigoExpr = in_array('codigo', $cols, true) ? 'd.codigo' : "''";
                $idProdExpr = in_array('idproducto', $cols, true) ? 'd.idproducto' : (in_array('id_producto', $cols, true) ? 'd.id_producto' : 'NULL');

                $sql = "
                    SELECT
                        {$idProdExpr} AS idproducto,
                        COALESCE({$codigoExpr}, p.cve_producto, '') AS codigo_barra,
                        COALESCE({$descExpr}, p.desproducto, 'Producto') AS producto_nombre,
                        COALESCE({$precioExpr}, 0) AS precio,
                        COALESCE({$cantidadExpr}, 1) AS cantidad
                    FROM {$dbName}.{$td} d
                    LEFT JOIN {$dbName}.tblproductos p
                        ON p.idproducto = {$idProdExpr}
                    WHERE d.{$idCol} = :id
                ";
                $stmtDet = $pdo->prepare($sql);
                $stmtDet->execute([':id' => $idFacturaReal]);
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

    $serialesMap = fetchSerialesPorFacturaNota($pdo, $dbName, $idFacturaReal);
    if (!empty($serialesMap)) {
        foreach ($items as &$item) {
            $idproducto = (int)($item['idproducto'] ?? 0);
            $item['seriales'] = $idproducto > 0 ? (string)($serialesMap[$idproducto] ?? '') : '';
        }
        unset($item);
    }

    // Parsear efectivo recibido / vuelto de firma_digital
    $firmaDigital = $venta['firma_digital'] ?? '';
    $efectivoRecibido = 0;
    $vuelto = 0;
    if (!empty($firmaDigital) && stripos($firmaDigital, 'RECIBIDO:') !== false) {
        if (preg_match('/RECIBIDO:\s*([\d.,]+)/i', $firmaDigital, $m)) {
            $efectivoRecibido = floatval(str_replace(['.', ','], ['', '.'], $m[1]));
        }
        if (preg_match('/VUELTO:\s*([\d.,]+)/i', $firmaDigital, $m)) {
            $vuelto = floatval(str_replace(['.', ','], ['', '.'], $m[1]));
        }
    }
    // Priorizar datos de pago efectivo persistidos por POS.
    $cashPayment = getCashPaymentData($pdo, $dbName, (int)($venta['id_factura'] ?? $id_factura));
    if (is_array($cashPayment)) {
        $efectivoRecibido = max($efectivoRecibido, (float)($cashPayment['cash_received'] ?? 0));
        $vuelto = max($vuelto, (float)($cashPayment['cash_change'] ?? 0));
    }

    // Logo deshabilitado para máxima compatibilidad de impresión.
    $allowRasterLogo = false;
    // Logo: ruta del archivo para convertir a ESC/POS raster (GS v 0)
    $logoPath = '';
    if ($allowRasterLogo) {
        $logoFile = $empresa['logos'] ?? '';
        if (!empty($logoFile)) {
            $lp = dirname(__DIR__) . '/_lib/file/img/empresa/' . $logoFile;
            if (file_exists($lp) && filesize($lp) > 0) {
                $logoPath = $lp;
            }
        }
    }


    // URL QR de consulta (multiempresa)
    $qrUrl = "https://sistemax.pro/consultas/nota.php?id_factura={$id_factura}&id_empresa={$id_empresa}";

    $latestPayment = getLatestPosPaymentData($pdo, $dbName, (int)($venta['id_factura'] ?? $id_factura));
    $cardPayment = getCardPaymentData($pdo, $dbName, (int)($venta['id_factura'] ?? $id_factura));
    $pixPayment = getPixPaymentData($pdo, $dbName, (int)($venta['id_factura'] ?? $id_factura));
    $creditPayment = getCreditPaymentData($latestPayment);
    $creditInstallments = getCreditInstallments($pdo, $dbName, (int)($venta['id_factura'] ?? $id_factura));

    // Usar nombre del emisor desde serproc1.empresa.empresa (requerimiento explícito).
    $empresaNombreResuelto = $empresaNombreMaster;
    if ($empresaNombreResuelto === '') {
        $empresaNombreResuelto = 'EMPRESA EMISORA';
    }
    $empresa['empresa_nombre_resuelto'] = $empresaNombreResuelto;
    error_log('CONTROL Ticket Emisor: id_factura=' . (int)($venta['id_factura'] ?? $id_factura) . ' | emisor=' . $empresaNombreResuelto);

    // Generar ESC/POS (logo incluido como raster bitmap)
    $escpos = generateNotaComun(
        $venta,
        $items,
        $empresa,
        $width,
        $efectivoRecibido,
        $vuelto,
        $qrUrl,
        $id_factura,
        $logoPath,
        $cardPayment,
        $pixPayment,
        !empty($creditInstallments)
            ? 'credito'
            : ($latestPayment['metodo'] ?? ($venta['medio_cobro'] ?? $venta['forma_pago'] ?? '')),
        $creditPayment,
        $creditInstallments
    );

    echo json_encode([
        'success' => true,
        'data' => base64_encode($escpos),
        'format' => 'escpos',
        'tipo' => 'nota_comun',
        'print_mode' => 'single',
        'qr_url' => $qrUrl,
        'items_count' => count($items)
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// ═══════════════════════════════════════════════════
// GENERADOR ESC/POS — NOTA DE CONTROL
// ═══════════════════════════════════════════════════

function generateNotaComun($venta, $items, $empresa, $width, $efectivoRecibido, $vuelto, $qrUrl, $id_factura, $logoPath = '', $cardPayment = null, $pixPayment = null, $paymentMethodRaw = '', $creditPayment = null, $creditInstallments = []) {
    $ESC = "\x1B";
    $GS  = "\x1D";
    $LF  = "\x0A";
    $o   = '';

    // ── Inicializar impresora ──
    $o .= $ESC . "@";               // Reset
    $o .= $ESC . "t\x10";           // Codepage WPC1252 (acentos)
    $o .= $GS . "!\x00";            // Tamaño normal
    $nombreEmpresa = trim((string)($empresa['empresa_nombre_resuelto'] ?? ''));
    if ($nombreEmpresa === '') {
        $nombreEmpresa = 'EMPRESA EMISORA';
    }
    $nombreCabecera = strtoupper(clean($nombreEmpresa, (int)$width));
    if (trim($nombreCabecera) === '') {
        $nombreCabecera = 'EMPRESA EMISORA';
    }

    // ── ENCABEZADO EMPRESA ──
    // Logo opcional.
    smxEscposAppendLogo($o, $empresa, (int)$width, $LF);

    // Imprimir una sola vez, en negrita.
    $o .= $ESC . "a\x01";
    $o .= $ESC . "!\x00";
    $ruc = trim(($empresa['ruc'] ?? '') . '-' . ($empresa['dv'] ?? ''));
    // Bloque principal de emisor en zona media (más estable en impresoras térmicas).
    $o .= $ESC . "a\x01";
    $o .= $ESC . "E\x01";
    $o .= $ESC . "!\x10";           // Doble alto para nombre de empresa
    $o .= $ESC . "E\x01";           // Reforzar negrita tras cambio de modo
    $o .= $nombreCabecera . $LF;
    $o .= $ESC . "!\x00";           // Volver a normal
    $o .= $ESC . "E\x00";
    if ($ruc !== '-' && $ruc !== '') {
        $o .= "RUC: " . $ruc . $LF;
    }
    if (!empty($empresa['direccion'])) {
        $o .= clean($empresa['direccion'], $width) . $LF;
    }
    if (!empty($empresa['telefono'])) {
        $o .= "Tel: " . trim($empresa['telefono']) . $LF;
    }
    if (!empty($empresa['email'])) {
        $o .= "Email: " . clean($empresa['email'], $width - 7) . $LF;
    }
    $o .= str_repeat('=', $width) . $LF;

    // ── TIPO DE DOCUMENTO ──
    $o .= $ESC . "!\x18";           // Doble alto + negrita
    $o .= "NOTA DE CONTROL" . $LF;
    $o .= $ESC . "!\x00";           // Normal

    // Número de factura
    $nro = $venta['nro_factura'] ?? ('INT-' . ($venta['id_factura'] ?? $id_factura));
    $o .= $ESC . "E\x01";           // Negrita
    $o .= "Nro: " . $nro . $LF;
    $o .= $ESC . "E\x00";

    // Fecha
    $fecha = date('d/m/Y H:i', strtotime($venta['fecha'] ?? 'now'));
    $o .= "Fecha: " . $fecha . $LF;

    $o .= str_repeat('-', $width) . $LF;

    // ── DATOS CLIENTE ──
    $o .= $ESC . "a\x00";           // Izquierda
    $cliente = $venta['cliente_nombre'] ?? $venta['cliente'] ?? 'CONSUMIDOR FINAL';
    $o .= "Cliente: " . clean($cliente, $width - 9) . $LF;

    $rucCli = $venta['cliente_ruc'] ?? $venta['ruc_cliente'] ?? '';
    if (!empty($rucCli) && $rucCli !== '0') {
        $o .= "RUC/CI: " . $rucCli . $LF;
    }

    // Sin "Pago" en cabecera (se muestra en "DETALLE DE COBRO")
    $formaPago = $paymentMethodRaw !== '' ? $paymentMethodRaw : ($venta['forma_pago'] ?? '');
    $pagoTxt = paymentLabel($formaPago);

    $o .= str_repeat('-', $width) . $LF;

    // ── DETALLE DE PRODUCTOS ──
    if (!empty($items)) {
        $totalCalc = 0;
        foreach ($items as $item) {
            $cant = (float)($item['cantidad'] ?? 1);
            $precio = (float)($item['precio'] ?? 0);
            $desc = $item['producto_nombre'] ?? $item['descripcion'] ?? 'Producto';
            $codBarra = trim($item['codigo_barra'] ?? '');
            $seriales = trim((string)($item['seriales'] ?? ''));
            $lineTotal = $precio * $cant;
            $totalCalc += $lineTotal;

            // Línea 1: Código de barras (negrita)
            if (!empty($codBarra)) {
                $o .= $ESC . "E\x01";
                $o .= $codBarra . $LF;
                $o .= $ESC . "E\x00";
            }

            // Línea 2: descripcion (izq) + cant x precio = total (der)
            // Todo en la misma línea, auto-ajustado al ancho
            $cantStr = fmtCant($cant);
            $precioStr = fmtNum($precio);
            $totalStr = fmtNum($lineTotal);
            $rightPart = $cantStr . "x" . $precioStr . "=" . $totalStr;
            $rightLen = strlen($rightPart);

            // Descripción ocupa lo que queda: width - rightLen - 1 espacio
            $descMaxLen = $width - $rightLen - 1;
            if ($descMaxLen < 8) $descMaxLen = 8;

            $descClean = clean($desc, $descMaxLen);
            // Línea completa: desc (pad izq) + espacio + right (pad der)
            $o .= str_pad($descClean, $width - $rightLen) . $rightPart . $LF;
            if ($seriales !== '') {
                $o .= clean('IMEI: ' . $seriales, $width) . $LF;
            }
        }
    } else {
        $o .= "(Sin detalle)" . $LF;
        $totalCalc = 0;
    }

    $o .= str_repeat('=', $width) . $LF;

    // ── TOTAL ──
    $total = (float)($venta['total'] ?? $totalCalc);
    $o .= $ESC . "a\x02";           // Derecha
    $o .= $ESC . "!\x30";           // Grande (doble alto + doble ancho)
    $o .= "TOTAL: " . fmtNum($total) . $LF;
    $o .= $ESC . "!\x00";           // Normal
    $o .= $ESC . "a\x00";           // Izquierda

    // ── DETALLE DE COBRO (debajo del total) ──
    $o .= str_repeat('-', $width) . $LF;
    $o .= $ESC . "a\x00";
    $o .= $ESC . "E\x01";
    $o .= "DETALLE DE COBRO" . $LF;
    $o .= $ESC . "E\x00";
    $o .= "Condicion: " . clean(mb_strtoupper($pagoTxt ?: 'N/D'), $width - 11) . $LF;

    if (normalizePaymentMethod($formaPago) === 'efectivo') {
        $entrega = $efectivoRecibido > 0 ? $efectivoRecibido : $total;
        $vueltoCalc = $vuelto > 0 ? $vuelto : max(0, $entrega - $total);
        $o .= str_pad("Entrega:", $width - strlen(fmtNum($entrega) . " Gs")) . fmtNum($entrega) . " Gs" . $LF;
        $o .= $ESC . "E\x01";
        $o .= str_pad("VUELTO:", $width - strlen(fmtNum($vueltoCalc) . " Gs")) . fmtNum($vueltoCalc) . " Gs" . $LF;
        $o .= $ESC . "E\x00";
    } elseif (normalizePaymentMethod($formaPago) === 'credito') {
        if (is_array($creditPayment)) {
            $cuotas = max(1, (int)($creditPayment['installments'] ?? 1));
            $vencFmt = formatDatePy((string)($creditPayment['due_date'] ?? ''));
            $o .= "Cuotas: " . $cuotas . $LF;
            if ($vencFmt !== '') {
                $o .= "Venc. 1ra: " . $vencFmt . $LF;
            }
        }
    } else {
        $o .= str_pad("Importe:", $width - strlen(fmtNum($total) . " Gs")) . fmtNum($total) . " Gs" . $LF;
    }

    if (normalizePaymentMethod($formaPago) === 'credito' && !empty($creditInstallments)) {
        $o .= str_repeat('-', $width) . $LF;
        $o .= $ESC . "E\x01";
        $o .= "PLAN DE CUOTAS" . $LF;
        $o .= $ESC . "E\x00";
        foreach ($creditInstallments as $cuota) {
            $label = trim((string)($cuota['cantidad_cuota'] ?? ''));
            if ($label === '') {
                $label = 'Cuota #' . (int)($cuota['numero'] ?? 0);
            }
            $vencCuotaFmt = formatDatePy((string)($cuota['fecha_vencimiento'] ?? ''));
            $montoCuota = fmtNum((float)($cuota['total'] ?? $cuota['pendiente'] ?? 0));
            $left = $label . ($vencCuotaFmt !== '' ? ' ' . $vencCuotaFmt : '');
            $right = $montoCuota . " Gs";
            $o .= str_pad(clean($left, max(8, $width - strlen($right) - 1)), $width - strlen($right)) . $right . $LF;
        }
        $o .= $ESC . "E\x01";
        $o .= str_pad("TOTAL:", $width - strlen(fmtNum($total) . " Gs")) . fmtNum($total) . " Gs" . $LF;
        $o .= $ESC . "E\x00";
    }

    // ── QR CODE (ESC/POS nativo GS(k) ──
    if (!empty($qrUrl)) {
        $o .= $LF;
        $o .= $ESC . "a\x01";       // Centrar

        $qrData = $qrUrl;
        $qrLen = strlen($qrData);

        // QR: Seleccionar modelo 2
        $o .= $GS . "(k\x04\x00\x31\x41\x32\x00";
        // QR: Tamaño del módulo (6 puntos)
        $o .= $GS . "(k\x03\x00\x31\x43\x06";
        // QR: Nivel de corrección L
        $o .= $GS . "(k\x03\x00\x31\x45\x30";
        // QR: Almacenar datos
        $pL = ($qrLen + 3) % 256;
        $pH = intdiv($qrLen + 3, 256);
        $o .= $GS . "(k" . chr($pL) . chr($pH) . "\x31\x50\x30" . $qrData;
        // QR: Imprimir
        $o .= $GS . "(k\x03\x00\x31\x51\x30";

        $o .= $LF;

        // Mostrar el URL debajo del QR, cortado en varias líneas si es necesario
        $maxLen = $width;
        $urlLines = str_split($qrUrl, $maxLen);
        foreach ($urlLines as $line) {
            $o .= $ESC . "a\x01"; // Centrar
            $o .= $line . $LF;
        }
        $o .= $LF;
    }

    // ── PIE ──
    $o .= $ESC . "a\x01";           // Centrar
    if (normalizePaymentMethod($formaPago) === 'tarjeta' && is_array($cardPayment)) {
        $o .= str_repeat('-', $width) . $LF;
        $o .= $ESC . "a\x00";
        $o .= $ESC . "E\x01";
        $o .= "COBRO TARJETA" . $LF;
        $o .= $ESC . "E\x00";
        if (!empty($cardPayment['card_brand'])) {
            $o .= "Marca: " . clean($cardPayment['card_brand'], $width - 7) . $LF;
        }
        if (!empty($cardPayment['card_terminal_reference']) || !empty($cardPayment['voucher_number'])) {
            $ref = $cardPayment['card_terminal_reference'] ?: $cardPayment['voucher_number'];
            $o .= "Ref: " . clean($ref, $width - 5) . $LF;
        }
        if (!empty($cardPayment['card_auth_code'])) {
            $o .= "Aut: " . clean($cardPayment['card_auth_code'], $width - 5) . $LF;
        }
        if (!empty($cardPayment['card_nsu'])) {
            $o .= "NSU: " . clean($cardPayment['card_nsu'], $width - 5) . $LF;
        }
        if (!empty($cardPayment['card_rrn'])) {
            $o .= "RRN: " . clean($cardPayment['card_rrn'], $width - 5) . $LF;
        }
        if (!empty($cardPayment['card_batch'])) {
            $o .= "Lote: " . clean($cardPayment['card_batch'], $width - 6) . $LF;
        }
        $o .= $ESC . "a\x01";
    }
    if (normalizePaymentMethod($formaPago) === 'pix' && is_array($pixPayment)) {
        $o .= str_repeat('-', $width) . $LF;
        $o .= $ESC . "a\x00";
        $o .= $ESC . "E\x01";
        $o .= "COBRO PIX" . $LF;
        $o .= $ESC . "E\x00";
        if (!empty($pixPayment['txid'])) {
            $o .= "TXID: " . clean($pixPayment['txid'], $width - 6) . $LF;
        } elseif (!empty($pixPayment['qr_transaction_code'])) {
            $o .= "Ref: " . clean($pixPayment['qr_transaction_code'], $width - 5) . $LF;
        }
        if (!empty($pixPayment['status'])) {
            $o .= "Estado: " . clean(strtoupper((string)$pixPayment['status']), $width - 8) . $LF;
        }
        if (!empty($pixPayment['paid_at'])) {
            $o .= "Pagado: " . clean((string)$pixPayment['paid_at'], $width - 8) . $LF;
        }
        $o .= $ESC . "a\x01";
    }
    if (normalizePaymentMethod($formaPago) === 'pendiente') {
        $o .= str_repeat('=', $width) . $LF;
        $o .= $ESC . "E\x01";
        $o .= $ESC . "a\x01";           // Centrar
        $o .= "** FACTURA PENDIENTE DE PAGO **" . $LF;
        $o .= $ESC . "E\x00";
        $o .= $ESC . "a\x00";
        $o .= str_repeat('=', $width) . $LF;
    }
    $o .= $ESC . "E\x01";           // Negrita
    $o .= "SIN VALIDEZ FISCAL" . $LF;
    $o .= $ESC . "E\x00";
    $o .= "Gracias por su compra!" . $LF;
    $o .= "[WWW] sistemax.pro" . $LF;
    $o .= $LF;

    // ID interno (font B, más pequeño)
    $o .= $ESC . "!\x01";
    $o .= "ID: " . $id_factura . " | " . date('d/m/Y H:i:s') . $LF;
    $o .= $ESC . "!\x00";

    // Corte de papel
    $o .= $LF . $LF . $LF;
    $o .= $GS . "V\x00";            // Corte total
    // Abrir cajón (pulso pin 2)
    $o .= $ESC . "p\x00\x19\x19";

    return $o;
}

// ═══════════════════════════════════════════════════
// UTILIDADES
// ═══════════════════════════════════════════════════

/**
 * Convierte una imagen (PNG/JPG) a comandos ESC/POS raster bitmap (GS v 0)
 * Compatible con impresoras térmicas ESC/POS.
 * 
 * @param string $imagePath Ruta absoluta al archivo de imagen
 * @param int $widthCols Ancho en columnas de texto (para calcular dots)
 * @return string Datos binarios ESC/POS con la imagen, o '' si falla
 */
function logoToEscPosRaster($imagePath, $widthCols = 80) {
    if (!function_exists('imagecreatefrompng') || !file_exists($imagePath)) {
        return '';
    }

    // Cargar imagen según tipo
    $info = @getimagesize($imagePath);
    if (!$info) return '';

    $mime = $info['mime'];
    switch ($mime) {
        case 'image/png':  $img = @imagecreatefrompng($imagePath); break;
        case 'image/jpeg': $img = @imagecreatefromjpeg($imagePath); break;
        case 'image/gif':  $img = @imagecreatefromgif($imagePath); break;
        default: return '';
    }
    if (!$img) return '';

    // Ancho máximo en dots según columnas
    // Font A = 12 dots/char, Font B = 9 dots/char
    // 80 col ≈ 576 dots (80mm), 48 col ≈ 576 dots, 32 col ≈ 384 dots
    if ($widthCols >= 64) {
        $maxDots = 576; // Impresora 80mm
    } elseif ($widthCols >= 42) {
        $maxDots = 576; // Impresora 80mm Font A
    } else {
        $maxDots = 384; // Impresora 58mm
    }

    $origW = imagesx($img);
    $origH = imagesy($img);

    // Escalar logo a max 60% del ancho de impresión (centrado se hace con ESC a)
    $logoMaxWidth = intval($maxDots * 0.6);
    // Limitar alto máximo a 150 dots para que no sea gigante
    $logoMaxHeight = 150;

    if ($origW > $logoMaxWidth) {
        $newW = $logoMaxWidth;
        $newH = intval($origH * ($logoMaxWidth / $origW));
    } else {
        $newW = $origW;
        $newH = $origH;
    }

    if ($newH > $logoMaxHeight) {
        $newW = intval($newW * ($logoMaxHeight / $newH));
        $newH = $logoMaxHeight;
    }

    // Redimensionar
    $resized = imagecreatetruecolor($newW, $newH);
    // Fondo blanco (para transparencia PNG)
    $white = imagecolorallocate($resized, 255, 255, 255);
    imagefill($resized, 0, 0, $white);
    // Mantener transparencia
    if ($mime === 'image/png') {
        imagealphablending($resized, true);
    }
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    imagedestroy($img);

    // Ancho debe ser múltiplo de 8
    $widthBytes = intval(ceil($newW / 8));

    // Convertir a bitmap monocromático (1 bit por pixel)
    $bitmapData = '';
    for ($y = 0; $y < $newH; $y++) {
        for ($xByte = 0; $xByte < $widthBytes; $xByte++) {
            $byte = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $x = $xByte * 8 + $bit;
                if ($x < $newW) {
                    $rgb = imagecolorat($resized, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    // Luminancia: pixel oscuro = imprimir (1)
                    $gray = ($r * 0.299 + $g * 0.587 + $b * 0.114);
                    if ($gray < 128) {
                        $byte |= (0x80 >> $bit);
                    }
                }
            }
            $bitmapData .= chr($byte);
        }
    }
    imagedestroy($resized);

    // Comando GS v 0 (raster bit image)
    // Format: GS v 0 m xL xH yL yH d1...dk
    // m=0 (normal), xL/xH = bytes por línea, yL/yH = líneas
    $GS = "\x1D";
    $xL = $widthBytes % 256;
    $xH = intdiv($widthBytes, 256);
    $yL = $newH % 256;
    $yH = intdiv($newH, 256);

    return $GS . "v0" . "\x00" . chr($xL) . chr($xH) . chr($yL) . chr($yH) . $bitmapData;
}

function clean($text, $maxLen = 32) {
    $text = trim($text ?? '');
    // Reemplazar acentos para compatibilidad ESC/POS
    $unwanted = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U',
        'ñ'=>'n','Ñ'=>'N','ü'=>'u','Ü'=>'U'
    ];
    $text = strtr($text, $unwanted);
    if (mb_strlen($text) > $maxLen) {
        return mb_substr($text, 0, $maxLen - 1) . '.';
    }
    return $text;
}

function fmtNum($n) {
    return number_format((float)$n, 0, '', '.');
}

function formatDatePy($raw) {
    $v = trim((string)$raw);
    if ($v === '') return '';
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $v)) return $v;
    $formats = ['Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'd/m/Y H:i:s', 'd/m/Y H:i'];
    foreach ($formats as $f) {
        $d = DateTime::createFromFormat($f, $v);
        if ($d instanceof DateTime) {
            return $d->format('d/m/Y');
        }
    }
    $ts = strtotime($v);
    return $ts !== false ? date('d/m/Y', $ts) : '';
}

function fmtCant($n) {
    $n = (float)$n;
    return ($n == intval($n)) ? (string)intval($n) : number_format($n, 2, '.', '');
}

function formatLine32($cant, $desc, $precio, $total) {
    // 32 col: 4 + 12 + 8 + 8
    return str_pad($cant, 4) . str_pad(clean($desc, 12), 12) . str_pad($precio, 8, ' ', STR_PAD_LEFT) . str_pad($total, 8, ' ', STR_PAD_LEFT);
}

function formatLine42($cant, $desc, $precio, $total, $w) {
    // Dinámico: 5 + (w-27) + 11 + 11
    $dw = $w - 27;
    return str_pad($cant, 5) . str_pad(clean($desc, $dw), $dw) . str_pad($precio, 11, ' ', STR_PAD_LEFT) . str_pad($total, 11, ' ', STR_PAD_LEFT);
}

function normalizePaymentMethod($raw) {
    $val = strtolower(trim((string)$raw));
    if ($val === '1' || $val === 'efectivo' || $val === 'contado') return 'efectivo';
    if ($val === '2' || $val === 'tarjeta') return 'tarjeta';
    if ($val === '3' || $val === 'transferencia' || $val === 'transfer') return 'transferencia';
    if ($val === '4' || $val === 'pix' || $val === 'qr') return 'pix';
    if ($val === '5' || $val === 'credito' || $val === 'crédito') return 'credito';
    if ($val === '6' || $val === 'pendiente') return 'pendiente';
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

function getPixPaymentData(PDO $pdo, string $dbName, int $idFactura): ?array {
    try {
        $pdo->query("SELECT 1 FROM {$dbName}.factura_ventas_pagos LIMIT 1");
    } catch (Throwable $e) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT metodo, qr_transaction_code, monto, created_at
            FROM {$dbName}.factura_ventas_pagos
            WHERE id_factura = :id
            ORDER BY id DESC
            LIMIT 1
        ");
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
            $st = $pdo->prepare("
                SELECT txid, status, paid_at
                FROM {$dbName}.pagos_pix
                WHERE (id_factura = :id OR txid = :txid)
                ORDER BY id DESC
                LIMIT 1
            ");
            $st->execute([':id' => $idFactura, ':txid' => $txid]);
            $px = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($px) {
                if (!empty($px['txid'])) $row['txid'] = (string)$px['txid'];
                if (!empty($px['status'])) $row['status'] = (string)$px['status'];
                if (!empty($px['paid_at'])) $row['paid_at'] = (string)$px['paid_at'];
            }
        } catch (Throwable $e) {
            // tabla pagos_pix opcional
        }
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function getCashPaymentData(PDO $pdo, string $dbName, int $idFactura): ?array {
    try {
        $pdo->query("SELECT 1 FROM {$dbName}.factura_ventas_pagos LIMIT 1");
    } catch (Throwable $e) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT metodo, monto, cash_received, cash_change, card_capture_payload_json, created_at
            FROM {$dbName}.factura_ventas_pagos
            WHERE id_factura = :id AND LOWER(COALESCE(metodo, '')) = 'efectivo'
            ORDER BY id DESC
            LIMIT 1
        ");
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
            'created_at' => (string)($row['created_at'] ?? '')
        ];
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

function paymentLabel($raw): string {
    $n = normalizePaymentMethod($raw);
    if ($n === 'efectivo') return 'Efectivo';
    if ($n === 'tarjeta') return 'Tarjeta';
    if ($n === 'transferencia') return 'Transferencia';
    if ($n === 'pix') return 'Pix';
    if ($n === 'credito') return 'Credito';
    if ($n === 'pendiente') return 'Pendiente';
    return (string)$raw;
}
