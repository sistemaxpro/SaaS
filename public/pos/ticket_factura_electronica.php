<?php
/**
 * Ticket ESC/POS — FACTURA ELECTRÓNICA (KuDE)
 * 
 * Genera comandos ESC/POS en base64 para impresión directa.
 * Incluye: timbrado, CDC, QR SIFEN, desglose IVA, liquidación IVA.
 * Cumple formato KuDE según Manual SIFEN v150.
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
error_reporting(E_ERROR | E_PARSE);
@ini_set('display_errors', '0');
set_error_handler(static function () {
    return true;
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/db_config.php';
require_once __DIR__ . '/lib/escpos_logo_helper.php';

function fetchSerialesPorFacturaFe(PDO $pdo, string $dbName, int $idFactura): array
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
    $width = (int)($_GET['width'] ?? 32);

    if (!$id_factura) {
        throw new Exception('ID de factura requerido');
    }

    // Conexión
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $masterPdo = $conn['masterPdo'];
    $empresa = $conn['config'];
    $dbName = $conn['dbName'];
    // Completar datos de empresa desde master (nombre, ruc, dv, direccion, telefono, email, logos)
    $empresaNombreMaster = '';
    try {
        $stmtEmp = $masterPdo->prepare("SELECT * FROM " . MASTER_DB . ".empresa WHERE id_empresa = ? LIMIT 1");
        $stmtEmp->execute([$id_empresa]);
        $empresaMaster = $stmtEmp->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($empresaMaster)) {
            $empresa = array_merge($empresaMaster, $empresa);
            $empresaNombreMaster = trim((string)($empresaMaster['empresa'] ?? ''));
        }
    } catch (Throwable $e) {
        // continuar con config mínima
    }

    // Datos de la venta con cliente (con fallback a conexión master/dbase real)
    $fetchVenta = function ($pdoConn, $db) use ($id_factura, $nro_factura_req) {
        $sql = "
            SELECT fv.*, 
                   c.nombre AS cliente_nombre, 
                   c.numero AS cliente_ruc,
                   c.direccion AS cliente_direccion,
                   c.email AS cliente_email,
                   c.telefono AS cliente_telefono,
                   fe.ruc_emisor,
                   fe.dv_emisor,
                   fe.razon_social_emisor,
                   fe.direccion_emisor,
                   fe.telefono_emisor,
                   fe.email_emisor
            FROM {$db}.factura_ventas fv
            LEFT JOIN {$db}.clientes c ON c.id = fv.id_cliente
            LEFT JOIN {$db}.fe fe ON fe.id_factura = fv.id_factura
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

    // CDC obligatorio para FE
    $cdc = $venta['cdc'] ?? '';
    if (empty($cdc)) {
        throw new Exception('Esta factura no tiene CDC (no es electrónica)');
    }

    // Items: usar SIEMPRE el id_factura real encontrado (puede diferir del GET por fallback nro)
    $idFacturaReal = (int)($venta['id_factura'] ?? $id_factura);
    $items = [];

    // 1) Intento principal: extracto_productos (compatibilidad POS actual; tolera idfactura/id_factura)
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
                ep.*,
                p.desproducto AS producto_nombre_alt
            FROM {$dbName}.extracto_productos ep
            LEFT JOIN {$dbName}.tblproductos p ON p.idproducto = ep.idproducto
            WHERE ep.{$idFacturaCol} = :id AND ep.salida > 0
            ORDER BY ep.id
        ");
        $stmtItems->execute([':id' => $idFacturaReal]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $items = [];
    }

    // 2) Fallback: tablas de detalle típicas
    if (empty($items)) {
        $tablasDetalle = ['factura_ventas_items', 'factura_ventas_detalle', 'factura_venta_detalle', 'detalle_factura', 'facturas_detalle', 'tblitemfacturas'];
        foreach ($tablasDetalle as $td) {
            try {
                $stmtCols = $pdo->query("DESCRIBE {$dbName}.{$td}");
                $cols = [];
                while ($c = $stmtCols->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = strtolower($c['Field']);
                }
                $idCol = in_array('id_factura', $cols, true) ? 'id_factura' : (in_array('idfactura', $cols, true) ? 'idfactura' : '');
                if ($idCol === '') {
                    continue;
                }

                $descExpr = in_array('descripcion', $cols, true) ? 'd.descripcion' : (in_array('producto', $cols, true) ? 'd.producto' : (in_array('detalle', $cols, true) ? 'd.detalle' : "'Producto'"));
                $cantidadExpr = in_array('cantidad', $cols, true) ? 'd.cantidad' : (in_array('cant', $cols, true) ? 'd.cant' : (in_array('qty', $cols, true) ? 'd.qty' : '1'));
                $precioExpr = in_array('precio', $cols, true) ? 'd.precio' : (in_array('precio_unitario', $cols, true) ? 'd.precio_unitario' : (in_array('precio_venta', $cols, true) ? 'd.precio_venta' : '0'));
                $idProdExpr = in_array('idproducto', $cols, true) ? 'd.idproducto' : (in_array('id_producto', $cols, true) ? 'd.id_producto' : 'NULL');

                $sql = "
                    SELECT d.*,
                           {$idProdExpr} AS idproducto,
                           COALESCE(p.desproducto, {$descExpr}, 'Producto') AS producto_nombre_alt,
                           COALESCE({$cantidadExpr}, 1) AS cantidad,
                           COALESCE({$precioExpr}, 0) AS precio
                    FROM {$dbName}.{$td} d
                    LEFT JOIN {$dbName}.tblproductos p ON p.idproducto = {$idProdExpr}
                    WHERE d.{$idCol} = :id
                ";
                $stmtDet = $pdo->prepare($sql);
                $stmtDet->execute([':id' => $idFacturaReal]);
                $tmp = $stmtDet->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($tmp)) {
                    $items = $tmp;
                    break;
                }
            } catch (Exception $e) {
                continue;
            }
        }
    }

    $serialesMap = fetchSerialesPorFacturaFe($pdo, $dbName, $idFacturaReal);
    if (!empty($serialesMap)) {
        foreach ($items as &$item) {
            $idproducto = (int)($item['idproducto'] ?? 0);
            $item['seriales'] = $idproducto > 0 ? (string)($serialesMap[$idproducto] ?? '') : '';
        }
        unset($item);
    }

    // Parsear efectivo recibido / vuelto
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

    // URL QR SIFEN
    $qrUrl = !empty($venta['qr_sifen']) ? (string)$venta['qr_sifen'] : '';
    if ($qrUrl !== '') {
        // Normalizar entidades HTML guardadas (&amp;) para que el QR sea válido.
        $qrUrl = html_entity_decode($qrUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $qrUrl = str_replace('&amp;', '&', $qrUrl);
        $qrUrl = trim($qrUrl);
    }
    if (empty($qrUrl)) {
        $qrUrl = 'https://ekuatia.set.gov.py/consultas/qr?nVersion=150&Id=' . $cdc;
    }

    // Logo deshabilitado para máxima compatibilidad de impresión.
    $allowRasterLogo = false;
    // Logo empresa para cabecera FE
    $logoPath = '';
    if ($allowRasterLogo) {
        $logoFile = trim((string)($empresa['logos'] ?? ''));
        if ($logoFile !== '') {
            $lp = dirname(__DIR__) . '/_lib/file/img/empresa/' . ltrim($logoFile, '/');
            if (file_exists($lp) && filesize($lp) > 0) {
                $logoPath = $lp;
            }
        }
    }

    $cardPayment = getCardPaymentData($pdo, $dbName, (int)($venta['id_factura'] ?? $id_factura));
    $pixPayment = getPixPaymentData($pdo, $dbName, (int)($venta['id_factura'] ?? $id_factura));

    // Usar nombre del emisor desde serproc1.empresa.empresa (requerimiento explícito).
    $empresaNombreResuelto = $empresaNombreMaster;
    if ($empresaNombreResuelto === '') {
        $empresaNombreResuelto = 'EMPRESA EMISORA';
    }
    $empresa['empresa_nombre_resuelto'] = $empresaNombreResuelto;
    error_log('FE Ticket Emisor: id_factura=' . (int)($venta['id_factura'] ?? $id_factura) . ' | emisor=' . $empresaNombreResuelto);

    // Generar ESC/POS
    $escpos = generateFacturaElectronica($venta, $items, $empresa, $cdc, $qrUrl, $width, $efectivoRecibido, $vuelto, $logoPath, $cardPayment, $pixPayment);

    echo json_encode([
        'success' => true,
        'data' => base64_encode($escpos),
        'format' => 'escpos',
        'tipo' => 'factura_electronica'
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// ═══════════════════════════════════════════════════
// GENERADOR ESC/POS — FACTURA ELECTRÓNICA (KuDE)
// ═══════════════════════════════════════════════════

function generateFacturaElectronica($venta, $items, $empresa, $cdc, $qrUrl, $width, $efectivoRecibido, $vuelto, $logoPath = '', $cardPayment = null, $pixPayment = null) {
    $ESC = "\x1B";
    $GS  = "\x1D";
    $LF  = "\x0A";
    $o   = '';

    // ── Inicializar impresora ──
    $o .= $ESC . "@";
    $o .= $ESC . "t\x10";           // Codepage WPC1252 (acentos)
    $o .= $ESC . "a\x01";           // Centrar

    // Nombre emisor en modo básico (máxima compatibilidad ESC/POS).
    $razonEmisor = trim((string)($empresa['empresa_nombre_resuelto'] ?? ''));
    if ($razonEmisor === '') {
        $razonEmisor = 'EMPRESA EMISORA';
    }
    $nombreCabecera = strtoupper(clean($razonEmisor, (int)$width));
    if (trim($nombreCabecera) === '') {
        $nombreCabecera = 'EMPRESA EMISORA';
    }
    // ── LOGO EMPRESA (si existe) ──
    smxEscposAppendLogo($o, $empresa, (int)$width, $LF);

    // ── ENCABEZADO EMPRESA ──
    // Imprimir una sola vez, en negrita.
    $o .= $ESC . "a\x01";
    $o .= $ESC . "!\x00";
    $rucEmisor = trim((string)($venta['ruc_emisor'] ?? $empresa['ruc'] ?? ''));
    $dvEmisor = trim((string)($venta['dv_emisor'] ?? $empresa['dv'] ?? ''));
    $ruc = $rucEmisor !== '' ? ($rucEmisor . ($dvEmisor !== '' ? '-' . $dvEmisor : '')) : '';
    // Bloque principal de emisor en zona media (más estable en impresoras térmicas).
    $o .= $ESC . "a\x01";
    $o .= $ESC . "E\x01";
    $o .= $ESC . "!\x10";           // Doble alto para nombre de empresa
    $o .= $ESC . "E\x01";           // Reforzar negrita tras cambio de modo
    $o .= $nombreCabecera . $LF;
    $o .= $ESC . "!\x00";           // Volver a normal
    $o .= $ESC . "E\x00";
    if ($ruc !== '') {
        $o .= "RUC: " . $ruc . $LF;
    }
    $direccionEmisor = trim((string)($venta['direccion_emisor'] ?? $empresa['direccion'] ?? ''));
    if ($direccionEmisor !== '') {
        $o .= clean($direccionEmisor, $width) . $LF;
    }
    $telefonoEmisor = trim((string)($venta['telefono_emisor'] ?? $empresa['telefono'] ?? ''));
    if ($telefonoEmisor !== '') {
        $o .= "Tel: " . $telefonoEmisor . $LF;
    }
    $emailEmisor = trim((string)($venta['email_emisor'] ?? $empresa['email'] ?? ''));
    if ($emailEmisor !== '') {
        $o .= "Email: " . clean($emailEmisor, $width - 7) . $LF;
    }
    if (!empty($empresa['des_act'] ?? $empresa['actividad'] ?? '')) {
        $o .= "Act: " . clean($empresa['des_act'] ?? $empresa['actividad'] ?? '', $width - 5) . $LF;
    }
    $o .= str_repeat('-', $width) . $LF;

    // ── TIPO DE DOCUMENTO ──
    // Parsear tipo desde CDC
    $tipoDoc = 'FACTURA ELECTRONICA';
    if (strlen($cdc) >= 44) {
        $tipoCdc = substr($cdc, 9, 2);
        $tiposDE = [
            '01' => 'FACTURA ELECTRONICA',
            '05' => 'NOTA DE CREDITO ELECTRONICA',
            '06' => 'NOTA DE DEBITO ELECTRONICA',
            '07' => 'NOTA DE REMISION ELECTRONICA'
        ];
        $tipoDoc = $tiposDE[$tipoCdc] ?? 'DOCUMENTO ELECTRONICO';
    }

    $o .= $ESC . "!\x18";           // Doble alto + negrita
    $o .= $tipoDoc . $LF;
    $o .= $ESC . "!\x00";

    // Timbrado
    if (!empty($venta['timbrado'])) {
        $o .= "Timbrado: " . $venta['timbrado'] . $LF;
    }

    // Número de factura
    $o .= $ESC . "E\x01";
    $o .= "Nro: " . ($venta['nro_factura'] ?? '') . $LF;
    $o .= $ESC . "E\x00";

    // Fecha
    $fecha = date('d/m/Y H:i', strtotime($venta['fecha'] ?? 'now'));
    $o .= "Fecha: " . $fecha . $LF;

    $o .= str_repeat('-', $width) . $LF;

    // ── RECEPTOR ──
    $o .= $ESC . "a\x00";           // Izquierda
    $o .= $ESC . "E\x01";
    $o .= "RECEPTOR" . $LF;
    $o .= $ESC . "E\x00";

    $cliente = $venta['cliente_nombre'] ?? $venta['cliente'] ?? 'CONSUMIDOR FINAL';
    $o .= "Nombre: " . clean($cliente, $width - 8) . $LF;

    $rucCli = $venta['cliente_ruc'] ?? $venta['ruc_cliente'] ?? '';
    if (!empty($rucCli)) {
        $o .= "RUC/CI: " . $rucCli . $LF;
    }

    $dirCli = $venta['cliente_direccion'] ?? $venta['direccion_cliente'] ?? '';
    if (!empty($dirCli)) {
        $o .= "Dir: " . clean($dirCli, $width - 5) . $LF;
    }

    // Condición de pago: cond=1 => Contado, cond>1 => Crédito
    $condVal = null;
    if (isset($venta['cond']) && $venta['cond'] !== '') {
        $condVal = (int)$venta['cond'];
    } elseif (isset($venta['forma_venta']) && $venta['forma_venta'] !== '') {
        $condVal = (int)$venta['forma_venta'];
    } elseif (isset($venta['condicion_venta']) && $venta['condicion_venta'] !== '') {
        $condVal = (int)$venta['condicion_venta'];
    }
    if ($condVal !== null) {
        $condText = ($condVal === 1) ? 'Contado' : 'Crédito';
    } else {
        // Fallback legacy por forma_pago
        $fp = (int)($venta['forma_pago'] ?? 1);
        $condText = ($fp === 5) ? 'Crédito' : 'Contado';
    }
    $o .= "Cond.: " . $condText . $LF;

    $o .= str_repeat('-', $width) . $LF;

    // ── DETALLE ──
    $o .= $ESC . "E\x01";
    $o .= "DETALLE" . $LF;
    $o .= $ESC . "E\x00";

    if ($width >= 42) {
        $o .= formatLine42("Cant", "Descripcion", "P.Unit", "Total", $width) . $LF;
    } else {
        $o .= formatLine32("Cant", "Descripcion", "Precio", "Total") . $LF;
    }
    $o .= str_repeat('-', $width) . $LF;

    $totalExenta = 0;
    $totalGrav5 = 0;
    $totalGrav10 = 0;

    if (!empty($items)) {
        foreach ($items as $item) {
            $cant = (float)($item['salida'] ?? $item['cantidad'] ?? 1);
            if ($cant <= 0) $cant = 1;
            $precio = (float)($item['precio'] ?? 0);
            $desc = $item['descripcion'] ?? $item['producto_nombre_alt'] ?? 'Producto';
            $seriales = trim((string)($item['seriales'] ?? ''));
            $lineTotal = $precio * $cant;

            // Determinar tasa IVA
            $tasa = (int)($item['tasa_iva'] ?? 10);
            if (isset($item['tipo_iva'])) {
                if ($item['tipo_iva'] == 1) $tasa = 0;
                elseif ($item['tipo_iva'] == 2) $tasa = 5;
                elseif ($item['tipo_iva'] == 3) $tasa = 10;
            }

            if ($tasa == 5) {
                $totalGrav5 += $lineTotal;
            } elseif ($tasa == 10) {
                $totalGrav10 += $lineTotal;
            } else {
                $totalExenta += $lineTotal;
            }

            if ($width >= 42) {
                $o .= formatLine42(
                    number_format($cant, 0),
                    clean($desc, $width - 28),
                    fmtNum($precio),
                    fmtNum($lineTotal),
                    $width
                ) . $LF;
            } else {
                $o .= clean($desc, $width) . $LF;
                $detalle = $cant . " x " . fmtNum($precio) . " = " . fmtNum($lineTotal);
                $o .= str_pad($detalle, $width, ' ', STR_PAD_LEFT) . $LF;
            }
            if ($seriales !== '') {
                $o .= clean('IMEI: ' . $seriales, $width) . $LF;
            }
        }
    } else {
        $o .= "(Sin detalle)" . $LF;
    }

    $o .= str_repeat('=', $width) . $LF;

    // ── SUBTOTALES IVA ──
    $o .= $ESC . "a\x02";           // Derecha
    $o .= "Sub. Exenta: " . fmtNum($totalExenta) . $LF;
    $o .= "Sub. IVA 5%: " . fmtNum($totalGrav5) . $LF;
    $o .= "Sub. IVA 10%: " . fmtNum($totalGrav10) . $LF;
    $o .= str_repeat('-', $width) . $LF;

    // ── TOTAL ──
    $totalGeneral = $totalExenta + $totalGrav5 + $totalGrav10;
    $total = (float)($venta['total'] ?? $totalGeneral);
    $o .= $ESC . "!\x30";           // Grande
    $o .= "TOTAL: " . fmtNum($total) . " Gs" . $LF;
    $o .= $ESC . "!\x00";

    // ── LIQUIDACIÓN IVA ──
    $liq5  = round($totalGrav5 / 21);
    $liq10 = round($totalGrav10 / 11);
    $o .= str_repeat('-', $width) . $LF;
    $o .= $ESC . "a\x00";           // Izquierda
    $o .= $ESC . "E\x01";
    $o .= "LIQUIDACION DEL IVA" . $LF;
    $o .= $ESC . "E\x00";
    $o .= "IVA 5%: " . fmtNum($liq5) . " | IVA 10%: " . fmtNum($liq10) . $LF;
    $o .= "Total IVA: " . fmtNum($liq5 + $liq10) . $LF;

    // ── EFECTIVO / VUELTO ──
    if ($efectivoRecibido > 0) {
        $o .= str_repeat('-', $width) . $LF;
        $o .= $ESC . "a\x02";
        $o .= "Efectivo: " . fmtNum($efectivoRecibido) . " Gs" . $LF;
        if ($vuelto > 0) {
            $o .= $ESC . "E\x01";
            $o .= "VUELTO: " . fmtNum($vuelto) . " Gs" . $LF;
            $o .= $ESC . "E\x00";
        }
    }

    // ── QR CODE (CONSULTA) ──
    $o .= $ESC . "a\x01";           // Centrar
    $o .= str_repeat('-', $width) . $LF;
    $o .= $ESC . "E\x01";
    $o .= "QR DE CONSULTA" . $LF;
    $o .= $ESC . "E\x00";
    $o .= $LF;
    $qrData = (string)$qrUrl;
    $qrPrinted = false;

    // Compatibilidad: algunos modelos no imprimen GS(k) QR.
    // Primero intentamos raster (imagen), luego fallback al QR nativo ESC/POS.
    $qrRaster = qrToEscPosRaster($qrData, $width);
    if ($qrRaster !== '') {
        $o .= $qrRaster . $LF;
        $qrPrinted = true;
    }

    if (!$qrPrinted) {
        $qrLen = strlen($qrData);
        // QR: Seleccionar modelo 2
        $o .= $GS . "(k\x04\x00\x31\x41\x32\x00";
        // QR: Tamaño del módulo (doble aprox. vs valores anteriores)
        $moduleSize = ($width >= 42) ? 12 : 10;
        $o .= $GS . "(k\x03\x00\x31\x43" . chr($moduleSize);
        // QR: Nivel de corrección L
        $o .= $GS . "(k\x03\x00\x31\x45\x30";
        // QR: Almacenar datos
        $pL = ($qrLen + 3) % 256;
        $pH = intdiv($qrLen + 3, 256);
        $o .= $GS . "(k" . chr($pL) . chr($pH) . "\x31\x50\x30" . $qrData;
        // QR: Imprimir
        $o .= $GS . "(k\x03\x00\x31\x51\x30";
        $o .= $LF;
    }

    // Sin detalle textual del QR (solo gráfico QR).
    $o .= $LF;

    // ── CDC ──
    $o .= $ESC . "a\x01";           // Centrar
    $o .= str_repeat('-', $width) . $LF;
    $o .= $ESC . "E\x01";
    $o .= "CDC" . $LF;
    $o .= $ESC . "E\x00";

    // Imprimir CDC en líneas según ancho
    $cdcLen = strlen($cdc);
    for ($i = 0; $i < $cdcLen; $i += $width) {
        $o .= substr($cdc, $i, $width) . $LF;
    }

    // ── PIE ──
    if (normalizePaymentMethod($venta['forma_pago'] ?? '') === 'tarjeta' && is_array($cardPayment)) {
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
    }
    if (normalizePaymentMethod($venta['forma_pago'] ?? '') === 'pix' && is_array($pixPayment)) {
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
    }
    if (normalizePaymentMethod($venta['forma_pago'] ?? '') === 'pendiente') {
        $o .= str_repeat('=', $width) . $LF;
        $o .= $ESC . "E\x01";
        $o .= $ESC . "a\x01";           // Centrar
        $o .= "** FACTURA PENDIENTE DE PAGO **" . $LF;
        $o .= $ESC . "E\x00";
        $o .= $ESC . "a\x00";
        $o .= str_repeat('=', $width) . $LF;
    }
    $o .= $ESC . "a\x01";
    $o .= "Original: Cliente" . $LF;
    $o .= "[WWW] sistemax.pro" . $LF;

    // Corte de papel
    $o .= $LF . $LF . $LF;
    $o .= $GS . "V\x00";
    // Abrir cajón (pulso pin 2)
    $o .= $ESC . "p\x00\x19\x19";

    return $o;
}

// ═══════════════════════════════════════════════════
// UTILIDADES
// ═══════════════════════════════════════════════════

function clean($text, $maxLen = 32) {
    $text = trim($text ?? '');
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

function formatLine32($cant, $desc, $precio, $total) {
    return str_pad($cant, 4) . str_pad(clean($desc, 12), 12) . str_pad($precio, 8, ' ', STR_PAD_LEFT) . str_pad($total, 8, ' ', STR_PAD_LEFT);
}

function formatLine42($cant, $desc, $precio, $total, $w) {
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
            SELECT metodo, monto, card_capture_payload_json, created_at
            FROM {$dbName}.factura_ventas_pagos
            WHERE id_factura = :id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':id' => $idFactura]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) return null;
        if (normalizePaymentMethod($row['metodo'] ?? '') !== 'efectivo') return null;

        $cashReceived = 0.0;
        $cashChange = 0.0;
        $payload = json_decode((string)($row['card_capture_payload_json'] ?? ''), true);
        if (is_array($payload) && isset($payload['raw']) && is_array($payload['raw'])) {
            $raw = $payload['raw'];
            $cashReceived = (float)($raw['cash_received'] ?? 0);
            $cashChange = (float)($raw['cash_change'] ?? 0);
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

/**
 * Convierte una imagen (PNG/JPG) a comandos ESC/POS raster bitmap (GS v 0).
 */
function logoToEscPosRaster($imagePath, $widthCols = 80, $maxWidthRatio = 0.6, $maxHeight = 150) {
    if (!function_exists('imagecreatefrompng') || !file_exists($imagePath)) {
        return '';
    }

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

    if ($widthCols >= 64) {
        $maxDots = 576;
    } elseif ($widthCols >= 42) {
        $maxDots = 576;
    } else {
        $maxDots = 384;
    }

    $origW = imagesx($img);
    $origH = imagesy($img);

    $logoMaxWidth = max(64, (int)intval($maxDots * $maxWidthRatio));
    $logoMaxHeight = max(64, (int)$maxHeight);

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

    $resized = imagecreatetruecolor($newW, $newH);
    $white = imagecolorallocate($resized, 255, 255, 255);
    imagefill($resized, 0, 0, $white);
    if ($mime === 'image/png') {
        imagealphablending($resized, true);
    }
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    imagedestroy($img);

    $widthBytes = intval(ceil($newW / 8));
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

    $GS = "\x1D";
    $xL = $widthBytes % 256;
    $xH = intdiv($widthBytes, 256);
    $yL = $newH % 256;
    $yH = intdiv($newH, 256);

    return $GS . "v0" . "\x00" . chr($xL) . chr($xH) . chr($yL) . chr($yH) . $bitmapData;
}

/**
 * Genera un QR como raster ESC/POS para impresoras que no soportan GS(k) QR.
 * Usa api.qrserver.com y devuelve '' si no se pudo obtener/convertir la imagen.
 */
function qrToEscPosRaster($qrData, $widthCols = 48) {
    $qrData = trim((string)$qrData);
    if ($qrData === '') return '';

    // QR más grande para mejorar tasa de lectura en lectores físicos (doble aprox.).
    $size = ($widthCols >= 42) ? 600 : 480;
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size .
           '&ecc=M&margin=1&data=' . rawurlencode($qrData);

    $ctx = stream_context_create([
        'http' => ['timeout' => 3],
        'https' => ['timeout' => 3]
    ]);

    $imgBin = @file_get_contents($url, false, $ctx);
    if ($imgBin === false || strlen($imgBin) < 64) {
        return '';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'qr_fe_');
    if ($tmp === false) return '';

    $tmpPng = $tmp . '.png';
    @file_put_contents($tmpPng, $imgBin);
    @unlink($tmp);

    // A diferencia del logo, el QR debe ocupar casi todo el ancho útil.
    $raster = logoToEscPosRaster($tmpPng, $widthCols, 0.95, ($widthCols >= 42 ? 360 : 300));
    @unlink($tmpPng);
    return $raster ?: '';
}
