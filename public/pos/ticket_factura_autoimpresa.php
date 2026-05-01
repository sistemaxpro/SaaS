<?php
/**
 * Ticket ESC/POS — FACTURA AUTOGRAFIADA (Autoimpresa)
 * 
 * Genera comandos ESC/POS en base64 para impresión directa.
 * Incluye: timbrado, vigencia, desglose IVA, liquidación IVA y detalle de cobro.
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

function fetchSerialesPorFacturaAuto(PDO $pdo, string $dbName, int $idFactura): array
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
    $qrModeReq = strtolower(trim((string)($_GET['qr_mode'] ?? '')));
    $qrBinarioReq = trim((string)($_GET['qr_binario'] ?? ''));
    $safeEnd = in_array(strtolower(trim((string)($_GET['safe_end'] ?? '0'))), ['1', 'true', 'on'], true);
    $qrMode = in_array($qrModeReq, ['text', 'native', 'raster'], true) ? $qrModeReq : 'native';
    if ($qrMode === 'raster') {
        $qrMode = 'native';
    }
    if ($qrBinarioReq !== '') {
        $qrMode = ($qrBinarioReq === '1' || strtolower($qrBinarioReq) === 'true' || strtolower($qrBinarioReq) === 'on')
            ? 'native'
            : 'text';
    }

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
            $empresaNombreMaster = trim((string)($empresaMaster['empresa'] ?? ''));
            $empresa = array_merge($empresaMaster, $empresa);
        }
    } catch (Throwable $e) {}
    $empresaNombreResuelto = $empresaNombreMaster;
    if ($empresaNombreResuelto === '') {
        $empresaNombreResuelto = trim((string)($empresa['empresa'] ?? ''));
    }
    if ($empresaNombreResuelto === '') {
        $empresaNombreResuelto = 'EMPRESA';
    }
    $empresa['empresa_nombre_resuelto'] = $empresaNombreResuelto;

    // Datos de la venta con cliente (con fallback a conexión master/dbase real)
    $fetchVenta = function ($pdoConn, $db) use ($id_factura, $nro_factura_req) {
        $sql = "
            SELECT fv.*, 
                   c.nombre AS cliente_nombre, 
                   c.numero AS cliente_ruc,
                   c.direccion AS cliente_direccion,
                   c.email AS cliente_email,
                   c.telefono AS cliente_telefono
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

    // Items: usar SIEMPRE el id_factura real encontrado
    $idFacturaReal = (int)($venta['id_factura'] ?? $id_factura);
    $items = [];

    // 1) Intento principal: extracto_productos (tolerante a idfactura/id_factura)
    try {
        $idFacturaCol = 'idfactura';
        $qtyExpr = 'ep.salida';
        $priceExpr = 'ep.precio';
        $descExpr = 'ep.descripcion';
        $codeExpr = 'ep.codigo';
        $idProdExpr = 'ep.idproducto';
        $orderBy = '';
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
            $qtyExpr = in_array('salida', $cols, true) ? 'ep.salida' : (in_array('cantidad', $cols, true) ? 'ep.cantidad' : (in_array('cant', $cols, true) ? 'ep.cant' : '0'));
            $priceExpr = in_array('precio', $cols, true) ? 'ep.precio' : (in_array('precio_venta', $cols, true) ? 'ep.precio_venta' : (in_array('costo', $cols, true) ? 'ep.costo' : '0'));
            $descExpr = in_array('descripcion', $cols, true) ? 'ep.descripcion' : "'Producto'";
            $codeExpr = in_array('codigo', $cols, true) ? 'ep.codigo' : "''";
            $idProdExpr = in_array('idproducto', $cols, true) ? 'ep.idproducto' : 'NULL';
            $orderBy = in_array('id', $cols, true) ? ' ORDER BY MAX(ep.id)' : '';
        } catch (Throwable $e) {}
        $stmtItems = $pdo->prepare("
            SELECT 
                MAX(COALESCE({$idProdExpr}, 0)) AS idproducto,
                COALESCE({$codeExpr}, p.cve_producto, '') AS codigo_barra,
                COALESCE({$descExpr}, p.desproducto, 'Producto') AS producto_nombre_alt,
                COALESCE({$priceExpr}, 0) AS precio,
                SUM(COALESCE({$qtyExpr}, 0)) AS cantidad,
                SUM(COALESCE({$qtyExpr}, 0) * COALESCE({$priceExpr}, 0)) AS subtotal
            FROM {$dbName}.extracto_productos ep
            LEFT JOIN {$dbName}.tblproductos p ON p.idproducto = {$idProdExpr}
            WHERE ep.{$idFacturaCol} = :id AND COALESCE({$qtyExpr}, 0) > 0
            GROUP BY codigo_barra, producto_nombre_alt, precio
            {$orderBy}
        ");
        $stmtItems->execute([':id' => $idFacturaReal]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $items = [];
    }

    // 2) Fallback: tablas de detalle típicas
    if (empty($items)) {
        // 2.a) Fallback específico legacy: item_mercaderia_venta
        try {
            $stmtLegacy = $pdo->prepare("
                SELECT
                    COALESCE(imv.id_referencia, tp.idproducto, 0) AS idproducto,
                    imv.*,
                    COALESCE(tp.desproducto, tp.descripcion, m.descripcion, imv.codigo, 'Producto') AS producto_nombre_alt,
                    COALESCE(imv.cantidad, 1) AS cantidad,
                    COALESCE(imv.precio, 0) AS precio,
                    COALESCE(imv.importe, (COALESCE(imv.cantidad,1) * COALESCE(imv.precio,0))) AS subtotal
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
            $tmpLegacy = $stmtLegacy->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!empty($tmpLegacy)) {
                $items = $tmpLegacy;
            }
        } catch (Throwable $e) {
            // continuar con fallback genérico
        }
    }

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
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    $serialesMap = fetchSerialesPorFacturaAuto($pdo, $dbName, $idFacturaReal);
    if (!empty($serialesMap)) {
        foreach ($items as &$item) {
            $idproducto = (int)($item['idproducto'] ?? 0);
            $item['seriales'] = $idproducto > 0 ? (string)($serialesMap[$idproducto] ?? '') : '';
        }
        unset($item);
    }

    // Intentar obtener datos de timbrado de la empresa o de la tabla timbrado
    $timbrado = $venta['timbrado'] ?? '';
    $fechaInicioTimb = $venta['fecha_inicio_timbrado'] ?? '';
    $fechaFinTimb = $venta['fecha_fin_timbrado'] ?? '';

    // Si falta información fiscal en factura, intentar tabla timbrado local
    if (empty($timbrado) || empty($fechaInicioTimb) || empty($fechaFinTimb)) {
        try {
            // Intentar tabla timbrado
            $stmtTimb = $pdo->prepare("
                SELECT timbrado, fecha_inicio, fecha_fin 
                FROM {$dbName}.timbrado 
                WHERE estado = 1 OR estado = 'activo' 
                ORDER BY id DESC LIMIT 1
            ");
            $stmtTimb->execute();
            $timbData = $stmtTimb->fetch(PDO::FETCH_ASSOC);
            if ($timbData) {
                $timbrado = $timbData['timbrado'];
                $fechaInicioTimb = $timbData['fecha_inicio'] ?? '';
                $fechaFinTimb = $timbData['fecha_fin'] ?? '';
            }
        } catch (Exception $e) {
            // Intentar desde empresa
            $timbrado = $empresa['timbrado'] ?? '';
        }
    }
    // Fallback fiscal: habilitación SIFEN (timbrado y vigencia)
    if (empty($timbrado) || empty($fechaInicioTimb) || empty($fechaFinTimb)) {
        try {
            $stmtHab = $masterPdo->prepare("
                SELECT numero_timbrado, timbrado, fecha_inicio_vigencia, fecha_fin_vigencia
                FROM " . MASTER_DB . ".habilitacion_sifen
                WHERE id_empresa = :id AND activo = 1
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtHab->execute([':id' => $id_empresa]);
            $hab = $stmtHab->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($hab)) {
                if (empty($timbrado)) {
                    $timbrado = (string)($hab['numero_timbrado'] ?? $hab['timbrado'] ?? '');
                }
                if (empty($fechaInicioTimb) && !empty($hab['fecha_inicio_vigencia'])) {
                    $fechaInicioTimb = (string)$hab['fecha_inicio_vigencia'];
                }
                if (empty($fechaFinTimb) && !empty($hab['fecha_fin_vigencia'])) {
                    $fechaFinTimb = (string)$hab['fecha_fin_vigencia'];
                }
            }
        } catch (Throwable $e) {
            // no bloquear ticket
        }
    }
    // Fallback extra desde serproc1.empresa
    if (empty($timbrado) || empty($fechaInicioTimb) || empty($fechaFinTimb)) {
        try {
            $stmtEmpM = $masterPdo->prepare("SELECT * FROM " . MASTER_DB . ".empresa WHERE id_empresa = :id LIMIT 1");
            $stmtEmpM->execute([':id' => $id_empresa]);
            $empM = $stmtEmpM->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($empM)) {
                if (empty($timbrado)) {
                    $timbrado = (string)($empM['timbrado'] ?? '');
                }
                if (empty($fechaInicioTimb)) {
                    $fechaInicioTimb = (string)($empM['fecha_inicio_timbrado'] ?? $empM['fecha_inicio_vigencia'] ?? $empM['vigencia_ini'] ?? '');
                }
                if (empty($fechaFinTimb)) {
                    $fechaFinTimb = (string)($empM['fecha_fin_timbrado'] ?? $empM['fecha_fin_vigencia'] ?? $empM['vigencia_fin'] ?? $empM['vencimiento'] ?? $empM['fecha_vencimiento'] ?? '');
                }
            }
        } catch (Throwable $e) {
            // no bloquear ticket
        }
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
    $latestPayment = getLatestPosPaymentData($pdo, $dbName, (int)($venta['id_factura'] ?? $id_factura));
    // Priorizar datos de pago efectivo persistidos por POS.
    $cashPayment = getCashPaymentData($pdo, $dbName, (int)($venta['id_factura'] ?? $id_factura));
    if (is_array($cashPayment)) {
        $efectivoRecibido = max($efectivoRecibido, (float)($cashPayment['cash_received'] ?? 0));
        $vuelto = max($vuelto, (float)($cashPayment['cash_change'] ?? 0));
    }

    // Logo desactivado para factura autografiada (requerimiento: sin logotipo)
    $logoPath = '';
    $pixPayment = getPixPaymentData($pdo, $dbName, (int)($venta['id_factura'] ?? $id_factura));
    $creditInstallments = getCreditInstallments($pdo, $dbName, (int)($venta['id_factura'] ?? $id_factura));
    $consultaPayload = [
        'id_factura' => (int)($venta['id_factura'] ?? $id_factura),
        'id_empresa' => (int)$id_empresa,
        'dbase' => (string)$dbName,
        'nro_factura' => (string)($venta['nro_factura'] ?? ''),
        'fecha' => (string)($venta['fecha'] ?? ''),
        'total' => (float)($venta['total'] ?? 0),
        'timbrado' => (string)$timbrado,
        'vencimiento' => (string)$fechaFinTimb,
        'cliente' => (string)($venta['cliente_nombre'] ?? $venta['cliente'] ?? 'CONSUMIDOR FINAL'),
        'cliente_doc' => (string)($venta['cliente_ruc'] ?? $venta['ruc_cliente'] ?? ''),
        'condicion' => (string)paymentMethodLabel(normalizePaymentMethod(
            $latestPayment['metodo'] ?? ($venta['forma_pago'] ?? $venta['cod_forma_pago'] ?? $venta['condicion_venta'] ?? '')
        )),
        'tipo_documento' => (int)($venta['tipo_documento'] ?? 1),
        'empresa' => [
            'nombre' => (string)($empresa['empresa_nombre_resuelto'] ?? $empresa['empresa'] ?? ''),
            'ruc' => (string)($empresa['ruc'] ?? ''),
            'dv' => (string)($empresa['dv'] ?? ''),
            'direccion' => (string)($empresa['direccion'] ?? ''),
            'telefono' => (string)($empresa['telefono'] ?? ''),
            'email' => (string)($empresa['email'] ?? ''),
        ]
    ];
    $consultaPublicaUrl = buildPublicFacturaUrl($consultaPayload);

    // Generar ESC/POS
    $escpos = generateFacturaAutoimpresa(
        $venta, $items, $empresa, $width,
        $timbrado, $fechaInicioTimb, $fechaFinTimb,
        $efectivoRecibido, $vuelto, $logoPath, $pixPayment, $consultaPublicaUrl, $qrMode, $safeEnd
    );

    echo json_encode([
        'success' => true,
        'data' => base64_encode($escpos),
        'format' => 'escpos',
        'tipo' => 'factura_autoimpresa',
        'print_mode' => 'single',
        'qr_mode' => $qrMode,
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
// GENERADOR ESC/POS — FACTURA AUTOGRAFIADA
// ═══════════════════════════════════════════════════

function generateFacturaAutoimpresa($venta, $items, $empresa, $width, $timbrado, $fechaInicioTimb, $fechaFinTimb, $efectivoRecibido, $vuelto, $logoPath = '', $pixPayment = null, $consultaPublicaUrl = '', $qrMode = 'text', $safeEnd = false) {
    $ESC = "\x1B";
    $GS  = "\x1D";
    $LF  = "\x0A";
    $o   = '';

    // ── Inicializar impresora ──
    $o .= $ESC . "@";
    $o .= $ESC . "t\x10";
    $o .= $ESC . "a\x01";           // Centrar

    // Sin logo (autografiada)

    // ── ENCABEZADO EMPRESA ──
    $o .= $ESC . "!\x30";           // Grande
    $o .= clean(mb_strtoupper((string)($empresa['empresa_nombre_resuelto'] ?? $empresa['empresa'] ?? 'EMPRESA')), $width / 2) . $LF;
    $o .= $ESC . "!\x00";           // Normal

    $ruc = ($empresa['ruc'] ?? '') . '-' . ($empresa['dv'] ?? '');
    $o .= "RUC: " . $ruc . $LF;

    if (!empty($empresa['direccion'])) {
        $o .= clean($empresa['direccion'], $width) . $LF;
    }
    if (!empty($empresa['telefono'])) {
        $o .= "Tel: " . ($empresa['telefono']) . $LF;
    }
    if (!empty($empresa['email'])) {
        $o .= "Email: " . clean($empresa['email'], $width - 7) . $LF;
    }
    if (!empty($empresa['des_act'] ?? $empresa['actividad'] ?? '')) {
        $o .= "Act: " . clean($empresa['des_act'] ?? $empresa['actividad'] ?? '', $width - 5) . $LF;
    }

    $o .= str_repeat('-', $width) . $LF;

    // ── TIPO DE DOCUMENTO ──
    $o .= $ESC . "!\x18";           // Doble alto + negrita
    $o .= "FACTURA AUTOGRAFIADA" . $LF;
    $o .= $ESC . "!\x00";

    $fFin = '';
    if (!empty($fechaFinTimb)) {
        $fFin = formatDatePy($fechaFinTimb);
    }
    $timLine = trim((string)$timbrado);
    if ($timLine === '') $timLine = 'N/D';
    $vencLine = ($fFin !== '' ? $fFin : 'N/D');
    $o .= $ESC . "E\x01";
    $o .= "Timbrado:" . $timLine . " - Venc:" . $vencLine . $LF;
    $o .= $ESC . "E\x00";

    // Número de factura
    $o .= $ESC . "E\x01";
    $o .= "Nro: " . ($venta['nro_factura'] ?? '') . $LF;
    $o .= $ESC . "E\x00";

    // Fecha
    $fecha = date('d/m/Y H:i', strtotime($venta['fecha'] ?? 'now'));
    $o .= "Fecha: " . $fecha . $LF;

    $o .= str_repeat('-', $width) . $LF;

    // ── DATOS CLIENTE ──
    $o .= $ESC . "a\x00";           // Izquierda
    $o .= $ESC . "E\x01";
    $o .= "CLIENTE" . $LF;
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

    // Condición de pago se muestra solo en "DETALLE DE COBRO" (no repetir en cabecera).
    $formaPago = normalizePaymentMethod(
        !empty($creditInstallments)
            ? 'credito'
            : ($latestPayment['metodo'] ?? ($venta['medio_cobro'] ?? $venta['forma_pago'] ?? $venta['cod_forma_pago'] ?? $venta['condicion_venta'] ?? ''))
    );
    $formaPagoLabel = paymentMethodLabel($formaPago);
    $creditPayment = getCreditPaymentData($latestPayment);

    $o .= str_repeat('-', $width) . $LF;

    // ── DETALLE DE PRODUCTOS ──
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
            $cant = (float)($item['salida'] ?? $item['cantidad'] ?? $item['cant'] ?? $item['qty'] ?? 1);
            if ($cant <= 0) $cant = 1;
            $precio = (float)($item['precio'] ?? $item['precio_unitario'] ?? $item['precio_venta'] ?? 0);
            $desc = (string)($item['descripcion'] ?? $item['producto_nombre_alt'] ?? $item['producto'] ?? $item['producto_nombre'] ?? $item['detalle'] ?? 'Producto');
            $seriales = trim((string)($item['seriales'] ?? ''));
            $subtotalCandidate = (float)($item['subtotal'] ?? 0);
            $totalCandidate = (float)($item['total'] ?? 0);
            if ($subtotalCandidate > 0) {
                $lineTotal = $subtotalCandidate;
            } elseif ($totalCandidate > 0) {
                $lineTotal = $totalCandidate;
            } else {
                $lineTotal = $precio * $cant;
            }

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

    // ── DETALLE DE COBRO ──
    $o .= str_repeat('-', $width) . $LF;
    $o .= $ESC . "a\x00";
    $o .= $ESC . "E\x01";
    $tituloCobro = ($formaPago === 'pendiente')
        ? 'FACTURA PENDIENTE DE PAGO'
        : 'DETALLE DE COBRO';
    $o .= $tituloCobro . $LF;
    $o .= $ESC . "E\x00";

    $o .= "Condicion: " . ($formaPagoLabel !== '' ? $formaPagoLabel : 'N/D') . $LF;
    if ($formaPago === 'efectivo') {
        $entrega = $efectivoRecibido > 0 ? $efectivoRecibido : $total;
        $vueltoCalc = $vuelto > 0 ? $vuelto : max(0, $entrega - $total);
        $o .= "Entrega: " . fmtNum($entrega) . " Gs" . $LF;
        $o .= $ESC . "E\x01";
        $o .= "Vuelto:  " . fmtNum($vueltoCalc) . " Gs" . $LF;
        $o .= $ESC . "E\x00";
    } elseif ($formaPago === 'credito') {
        if (is_array($creditPayment)) {
            $o .= "Cuotas: " . max(1, (int)($creditPayment['installments'] ?? 1)) . $LF;
            $creditDueFmt = formatDatePy((string)($creditPayment['due_date'] ?? ''));
            if ($creditDueFmt !== '') {
                $o .= "Venc. 1ra: " . $creditDueFmt . $LF;
            }
        }
    } else {
        $o .= "Importe: " . fmtNum($total) . " Gs" . $LF;
    }

    if ($formaPago === 'credito' && !empty($creditInstallments)) {
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
            $o .= str_pad(clean($left, max(8, $width - strlen($montoCuota . " Gs") - 1)), $width - strlen($montoCuota . " Gs")) . $montoCuota . " Gs" . $LF;
        }
        $o .= $ESC . "E\x01";
        $o .= "TOTAL: " . fmtNum($total) . " Gs" . $LF;
        $o .= $ESC . "E\x00";
    }

    // ── VERIFICACIÓN PÚBLICA (QR) ──
    if (trim((string)$consultaPublicaUrl) !== '') {
        $o .= str_repeat('-', $width) . $LF;
        $o .= $ESC . "a\x01";
        $o .= $ESC . "E\x01";
        $o .= "VERIFICACION PUBLICA" . $LF;
        $o .= $ESC . "E\x00";

        if ($qrMode !== 'text') {
            // Mismo formato que Nota de Control (GS(k) nativo).
            $qrData = $consultaPublicaUrl;
            $qrLen = strlen($qrData);
            $o .= $GS . "(k\x04\x00\x31\x41\x32\x00";                 // Modelo 2
            $o .= $GS . "(k\x03\x00\x31\x43\x06";                     // Tamaño 6
            $o .= $GS . "(k\x03\x00\x31\x45\x30";                     // ECC L
            $pL = ($qrLen + 3) % 256;
            $pH = intdiv($qrLen + 3, 256);
            $o .= $GS . "(k" . chr($pL) . chr($pH) . "\x31\x50\x30" . $qrData; // Almacenar
            $o .= $GS . "(k\x03\x00\x31\x51\x30";                     // Imprimir
            $o .= $LF;
        }

        // Igual que nota: imprimir URL en líneas.
        $urlLines = str_split($consultaPublicaUrl, max(8, (int)$width));
        foreach ($urlLines as $line) {
            $o .= $line . $LF;
        }
    }

    // ── PIE ──
    if ($formaPago === 'pendiente') {
        $o .= str_repeat('=', $width) . $LF;
        $o .= $ESC . "E\x01";
        $o .= $ESC . "a\x01";           // Centrar
        $o .= "** FACTURA PENDIENTE DE PAGO **" . $LF;
        $o .= $ESC . "E\x00";
        $o .= $ESC . "a\x00";
        $o .= str_repeat('=', $width) . $LF;
    }
    $o .= $ESC . "a\x01";           // Centrar
    $o .= str_repeat('-', $width) . $LF;
    $o .= "Original: Cliente" . $LF;
    $o .= "Gracias por su compra!" . $LF;

    // Fin de ticket (en móvil evitar corte/cajón para no trabar impresoras BT)
    $o .= $LF . $LF . $LF;
    if (!$safeEnd) {
        $o .= $GS . "V\x00";
        // Abrir cajón (pulso pin 2)
        $o .= $ESC . "p\x00\x19\x19";
    }

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

function paymentMethodLabel(string $method): string {
    $m = normalizePaymentMethod($method);
    if ($m === 'efectivo') return 'EFECTIVO';
    if ($m === 'tarjeta') return 'TARJETA';
    if ($m === 'transferencia') return 'TRANSFERENCIA';
    if ($m === 'pix') return 'QR / PIX';
    if ($m === 'credito') return 'CREDITO';
    if ($m === 'pendiente') return 'PENDIENTE';
    return trim(mb_strtoupper($m));
}

function formatDatePy($raw): string {
    $v = trim((string)$raw);
    if ($v === '') return '';
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $v)) {
        return $v;
    }
    $d = DateTime::createFromFormat('d/m/Y H:i:s', $v);
    if ($d instanceof DateTime) return $d->format('d/m/Y');
    $d = DateTime::createFromFormat('d/m/Y H:i', $v);
    if ($d instanceof DateTime) return $d->format('d/m/Y');
    $d = DateTime::createFromFormat('d/m/Y', $v);
    if ($d instanceof DateTime) return $d->format('d/m/Y');
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $v);
    if ($d instanceof DateTime) return $d->format('d/m/Y');
    $d = DateTime::createFromFormat('Y-m-d', $v);
    if ($d instanceof DateTime) return $d->format('d/m/Y');
    $ts = strtotime($v);
    if ($ts === false) return '';
    return date('d/m/Y', $ts);
}

function buildPublicFacturaUrl(array $payload): string {
    $idFactura = (int)($payload['id_factura'] ?? 0);
    $idEmpresa = (int)($payload['id_empresa'] ?? 0);
    $dbase = trim((string)($payload['dbase'] ?? ''));

    if ($idFactura > 0 && $idEmpresa > 0) {
        $url = 'https://sistemax.pro/public/consultas/factura_publica.php?i=' . $idFactura . '&e=' . $idEmpresa;
        if ($dbase !== '') {
            $url .= '&db=' . rawurlencode($dbase);
        }
        return $url;
    }

    // Fallback legacy con payload completo si faltan IDs.
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) $json = '{}';
    $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    return 'https://sistemax.pro/public/consultas/factura_publica.php?d=' . rawurlencode($b64);
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

function logoToEscPosRaster($imagePath, $widthCols = 80, $maxWidthRatio = 0.6, $maxHeight = 150) {
    if (!function_exists('imagecreatefrompng') || !file_exists($imagePath)) return '';
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
    $maxDots = ($widthCols >= 42) ? 576 : 384;
    $origW = imagesx($img); $origH = imagesy($img);
    $logoMaxWidth = max(64, (int)intval($maxDots * $maxWidthRatio));
    $logoMaxHeight = max(64, (int)$maxHeight);
    if ($origW > $logoMaxWidth) {
        $newW = $logoMaxWidth; $newH = intval($origH * ($logoMaxWidth / $origW));
    } else {
        $newW = $origW; $newH = $origH;
    }
    if ($newH > $logoMaxHeight) {
        $newW = intval($newW * ($logoMaxHeight / $newH)); $newH = $logoMaxHeight;
    }
    $resized = imagecreatetruecolor($newW, $newH);
    $white = imagecolorallocate($resized, 255, 255, 255);
    imagefill($resized, 0, 0, $white);
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
                    $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
                    $gray = ($r * 0.299 + $g * 0.587 + $b * 0.114);
                    if ($gray < 128) $byte |= (0x80 >> $bit);
                }
            }
            $bitmapData .= chr($byte);
        }
    }
    imagedestroy($resized);
    $GS = "\x1D";
    $xL = $widthBytes % 256; $xH = intdiv($widthBytes, 256);
    $yL = $newH % 256; $yH = intdiv($newH, 256);
    return $GS . "v0" . "\x00" . chr($xL) . chr($xH) . chr($yL) . chr($yH) . $bitmapData;
}

function qrToEscPosRaster($qrData, $widthCols = 48) {
    $qrData = trim((string)$qrData);
    if ($qrData === '') return '';

    // QR liviano para evitar overflow/cuelgues en impresoras BT de bajo buffer.
    $size = ($widthCols >= 42) ? 180 : 160;
    $urls = [
        'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&ecc=M&margin=1&data=' . rawurlencode($qrData),
        'https://chart.googleapis.com/chart?cht=qr&chs=' . $size . 'x' . $size . '&chld=M|1&chl=' . rawurlencode($qrData),
    ];

    $imgBin = '';
    foreach ($urls as $url) {
        $ctx = stream_context_create([
            'http' => ['timeout' => 3],
            'https' => ['timeout' => 3]
        ]);
        $tmpBin = @file_get_contents($url, false, $ctx);
        if ($tmpBin !== false && strlen($tmpBin) >= 64) {
            $imgBin = $tmpBin;
            break;
        }
    }
    if ($imgBin === '') {
        return '';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'qr_auto_');
    if ($tmp === false) return '';

    $tmpPng = $tmp . '.png';
    @file_put_contents($tmpPng, $imgBin);
    @unlink($tmp);

    $raster = logoToEscPosRaster($tmpPng, $widthCols, 0.52, ($widthCols >= 42 ? 130 : 115));
    @unlink($tmpPng);
    return $raster ?: '';
}

function escposQrNative(string $content, int $size = 5, string $ecc = 'M'): string {
    $content = trim($content);
    if ($content === '') return '';

    $size = max(3, min(8, $size));
    $eccMap = ['L' => 48, 'M' => 49, 'Q' => 50, 'H' => 51];
    $eccByte = $eccMap[strtoupper($ecc)] ?? 49;

    $GS = "\x1D";
    $data = $content;
    $len = strlen($data);
    if ($len <= 0 || $len > 7092) return '';

    $pL = ($len + 3) % 256;
    $pH = intdiv($len + 3, 256);

    $o = '';
    $o .= $GS . "(k" . "\x04\x00\x31\x41\x32\x00";        // modelo 2
    $o .= $GS . "(k" . "\x03\x00\x31\x43" . chr($size);   // tamaño
    $o .= $GS . "(k" . "\x03\x00\x31\x45" . chr($eccByte);// ECC
    $o .= $GS . "(k" . chr($pL) . chr($pH) . "\x31\x50\x30" . $data; // almacenar
    $o .= $GS . "(k" . "\x03\x00\x31\x51\x30";            // imprimir
    return $o;
}
