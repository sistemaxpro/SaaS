<?php
/**
 * Ticket ESC/POS — COMPROBANTE DE PAGO PENDIENTE
 * 
 * Ticket simplificado para ventas con cobro pendiente.
 * NO es el ticket de venta normal — solo un comprobante para caja.
 * Incluye: empresa, fecha/hora, cliente, vendedor, importe (doble tamaño), QR con datos.
 * 
 * Parámetros GET:
 *   - id: ID de la factura
 *   - id_empresa: ID de empresa
 *   - width: ancho en columnas (32 o 48, default 48)
 * 
 * Respuesta JSON: { success, data (base64), format, tipo }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/db_config.php';
require_once __DIR__ . '/lib/escpos_logo_helper.php';

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

    // Nombre empresa desde master
    $empresaNombre = '';
    try {
        $stmtEmp = $masterPdo->prepare("SELECT empresa, ruc, dv, direccion, telefono, logos FROM " . MASTER_DB . ".empresa WHERE id_empresa = ? LIMIT 1");
        $stmtEmp->execute([$id_empresa]);
        $empresaMaster = $stmtEmp->fetch(PDO::FETCH_ASSOC) ?: [];
        $empresaNombre = trim((string)($empresaMaster['empresa'] ?? ''));
        $empresa = array_merge($empresa, $empresaMaster);
    } catch (Throwable $e) {}

    if ($empresaNombre === '') $empresaNombre = 'EMPRESA';

    // Datos de la venta con cliente
    $sql = "
        SELECT fv.*, 
               c.nombre AS cliente_nombre,
               c.numero AS cliente_ruc
        FROM {$dbName}.factura_ventas fv
        LEFT JOIN {$dbName}.clientes c ON c.id = fv.id_cliente
        WHERE fv.id_factura = :id
    ";
    if ($nro_factura_req !== '') {
        $sql .= " OR fv.nro_factura = :nro";
    }
    $sql .= " ORDER BY fv.id_factura DESC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id_factura, PDO::PARAM_INT);
    if ($nro_factura_req !== '') {
        $stmt->bindValue(':nro', $nro_factura_req, PDO::PARAM_STR);
    }
    $stmt->execute();
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
        throw new Exception('Venta no encontrada');
    }

    // Vendedor
    $vendedor = '';
    try {
        $idLogin = (int)($venta['id_login'] ?? $venta['id_vendedor'] ?? $_SESSION['id_login'] ?? 0);
        if ($idLogin > 0) {
            $stmtV = $masterPdo->prepare("SELECT COALESCE(NULLIF(TRIM(name),''), login) AS nombre FROM " . MASTER_DB . ".sec_users WHERE id_login = ? LIMIT 1");
            $stmtV->execute([$idLogin]);
            $vendedor = (string)($stmtV->fetchColumn() ?: '');
        }
    } catch (Throwable $e) {}
    if ($vendedor === '') {
        $vendedor = $_SESSION['user_name'] ?? $_SESSION['usuario'] ?? 'VENDEDOR';
    }

    // Datos para el QR
    $idFacturaReal = (int)($venta['id_factura'] ?? $id_factura);
    $nroFactura = $venta['nro_factura'] ?? ('P-' . $idFacturaReal);
    $total = (float)($venta['total'] ?? 0);
    $cliente = $venta['cliente_nombre'] ?? $venta['cliente'] ?? 'CONSUMIDOR FINAL';
    $clienteRuc = $venta['cliente_ruc'] ?? '';
    $fecha = date('d/m/Y H:i', strtotime($venta['fecha'] ?? 'now'));

    $qrPayload = json_encode([
        'id' => $idFacturaReal,
        'emp' => $id_empresa,
        'nro' => $nroFactura,
        'total' => $total,
        'cli' => mb_substr($cliente, 0, 40),
        'ruc' => $clienteRuc,
        'f' => date('Y-m-d H:i:s', strtotime($venta['fecha'] ?? 'now')),
        't' => 'PENDIENTE'
    ], JSON_UNESCAPED_UNICODE);

    // Generar ESC/POS
    $escpos = generateComprobantePendiente($empresaNombre, $empresa, $venta, $cliente, $clienteRuc, $vendedor, $total, $fecha, $nroFactura, $idFacturaReal, $width, $qrPayload);

    echo json_encode([
        'success' => true,
        'data' => base64_encode($escpos),
        'format' => 'escpos',
        'tipo' => 'comprobante_pendiente',
        'print_mode' => 'single'
    ]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// ═══════════════════════════════════════════════════
// GENERADOR ESC/POS — COMPROBANTE PENDIENTE
// ═══════════════════════════════════════════════════

function generateComprobantePendiente($empresaNombre, $empresa, $venta, $cliente, $clienteRuc, $vendedor, $total, $fecha, $nroFactura, $idFactura, $width, $qrPayload) {
    $ESC = "\x1B";
    $GS  = "\x1D";
    $LF  = "\x0A";
    $o   = '';

    // ── Inicializar impresora ──
    $o .= $ESC . "@";               // Reset
    $o .= $ESC . "t\x10";           // Codepage WPC1252 (acentos)
    $o .= $GS . "!\x00";            // Tamaño normal
    smxEscposAppendLogo($o, $empresa, (int)$width, $LF);

    // ═══════════════════════════════════════════
    // ENCABEZADO EMPRESA
    // ═══════════════════════════════════════════
    $o .= $ESC . "a\x01";           // Centrar

    // Nombre empresa en doble alto + negrita
    $o .= $ESC . "!\x10";           // Doble alto
    $o .= $ESC . "E\x01";           // Negrita
    $o .= strtoupper(pClean($empresaNombre, $width)) . $LF;
    $o .= $ESC . "!\x00";           // Normal
    $o .= $ESC . "E\x00";

    $ruc = trim(($empresa['ruc'] ?? '') . '-' . ($empresa['dv'] ?? ''));
    if ($ruc !== '-' && $ruc !== '') {
        $o .= "RUC: " . $ruc . $LF;
    }
    if (!empty($empresa['direccion'])) {
        $o .= pClean($empresa['direccion'], $width) . $LF;
    }
    if (!empty($empresa['telefono'])) {
        $o .= "Tel: " . trim($empresa['telefono']) . $LF;
    }
    $o .= str_repeat('=', $width) . $LF;

    // ═══════════════════════════════════════════
    // TÍTULO: COMPROBANTE PENDIENTE
    // ═══════════════════════════════════════════
    $o .= $LF;
    $o .= $ESC . "a\x01";           // Centrar
    $o .= $ESC . "!\x38";           // Doble ancho + doble alto + negrita
    $o .= "PAGO PENDIENTE" . $LF;
    $o .= $ESC . "!\x00";           // Normal
    $o .= $LF;

    // ═══════════════════════════════════════════
    // DATOS DE LA OPERACIÓN
    // ═══════════════════════════════════════════
    $o .= $ESC . "a\x00";           // Izquierda
    $o .= str_repeat('-', $width) . $LF;

    // Nro comprobante
    $o .= $ESC . "E\x01";
    $o .= "Nro: " . $nroFactura . $LF;
    $o .= $ESC . "E\x00";

    // Fecha y hora
    $o .= "Fecha: " . $fecha . $LF;

    // Cliente
    $o .= str_repeat('-', $width) . $LF;
    $o .= $ESC . "E\x01";
    $o .= "CLIENTE:" . $LF;
    $o .= $ESC . "E\x00";
    $o .= pClean($cliente, $width) . $LF;
    if (!empty($clienteRuc)) {
        $o .= "RUC/CI: " . pClean($clienteRuc, $width - 8) . $LF;
    }

    // Vendedor
    $o .= str_repeat('-', $width) . $LF;
    $o .= "Vendedor: " . pClean($vendedor, $width - 10) . $LF;

    // Observación de pendiente (si existe)
    $pendienteNotes = '';
    try {
        // Intentar obtener notas de pendiente desde factura_ventas_pagos
        $stmtP = $GLOBALS['_pdo_pendiente'] ?? null;
        // Usar los datos de firma_digital como fallback
        $firmaDigital = $venta['firma_digital'] ?? '';
        if (stripos($firmaDigital, 'PENDIENTE') !== false) {
            if (preg_match('/PENDIENTE[:\s]*(.+)/i', $firmaDigital, $m)) {
                $pendienteNotes = trim($m[1]);
            }
        }
        // Buscar en observación del comprobante
        $obs = trim((string)($venta['observacion'] ?? $venta['observaciones'] ?? ''));
        if ($obs !== '' && $pendienteNotes === '') {
            $pendienteNotes = $obs;
        }
    } catch (Throwable $e) {}

    if ($pendienteNotes !== '') {
        $o .= "Obs: " . pClean($pendienteNotes, $width - 5) . $LF;
    }

    // ═══════════════════════════════════════════
    // IMPORTE — DOBLE ALTURA Y ANCHO
    // ═══════════════════════════════════════════
    $o .= $LF;
    $o .= str_repeat('=', $width) . $LF;
    $o .= $ESC . "a\x01";           // Centrar
    $o .= $ESC . "!\x00";
    $o .= "IMPORTE A COBRAR" . $LF;

    // Importe en doble ancho + doble alto + negrita (0x30 = doble ancho+alto, 0x08 = negrita)
    $o .= $ESC . "!\x38";           // Doble ancho + doble alto + negrita
    $importeStr = pFmtNum($total) . " Gs";
    $o .= $importeStr . $LF;
    $o .= $ESC . "!\x00";           // Normal

    $o .= str_repeat('=', $width) . $LF;
    $o .= $LF;

    // ═══════════════════════════════════════════
    // QR CODE con datos para caja
    // ═══════════════════════════════════════════
    $o .= $ESC . "a\x01";           // Centrar

    $qrData = $qrPayload;
    $qrLen = strlen($qrData);

    // QR: Seleccionar modelo 2
    $o .= $GS . "(k\x04\x00\x31\x41\x32\x00";
    // QR: Tamaño del módulo (8 puntos — más grande para escaneo fácil)
    $o .= $GS . "(k\x03\x00\x31\x43\x08";
    // QR: Nivel de corrección M (medio)
    $o .= $GS . "(k\x03\x00\x31\x45\x31";
    // QR: Almacenar datos
    $pL = ($qrLen + 3) % 256;
    $pH = intdiv($qrLen + 3, 256);
    $o .= $GS . "(k" . chr($pL) . chr($pH) . "\x31\x50\x30" . $qrData;
    // QR: Imprimir
    $o .= $GS . "(k\x03\x00\x31\x51\x30";

    $o .= $LF;

    // Texto debajo del QR
    $o .= $ESC . "a\x01";           // Centrar
    $o .= $ESC . "!\x01";           // Font B (más pequeño)
    $o .= "Escanear en caja para cobrar" . $LF;
    $o .= "ID: " . $idFactura . " | " . date('d/m/Y H:i:s') . $LF;
    $o .= $ESC . "!\x00";           // Normal

    // ═══════════════════════════════════════════
    // PIE
    // ═══════════════════════════════════════════
    $o .= $LF;
    $o .= $ESC . "a\x01";           // Centrar
    $o .= str_repeat('-', $width) . $LF;
    $o .= $ESC . "E\x01";
    $o .= "ESTE NO ES UN TICKET DE VENTA" . $LF;
    $o .= "Comprobante de pago pendiente" . $LF;
    $o .= $ESC . "E\x00";
    $o .= str_repeat('-', $width) . $LF;
    $o .= $LF;

    // Corte de papel
    $o .= $LF . $LF . $LF;
    $o .= $GS . "V\x00";            // Corte total

    return $o;
}

// ═══════════════════════════════════════════════════
// UTILIDADES
// ═══════════════════════════════════════════════════

function pClean($str, $maxLen = 999) {
    $str = trim((string)$str);
    // Transliterar acentos a codepage WPC1252
    $map = [
        'á' => "\xe1", 'é' => "\xe9", 'í' => "\xed", 'ó' => "\xf3", 'ú' => "\xfa",
        'Á' => "\xc1", 'É' => "\xc9", 'Í' => "\xcd", 'Ó' => "\xd3", 'Ú' => "\xda",
        'ñ' => "\xf1", 'Ñ' => "\xd1", 'ü' => "\xfc", 'Ü' => "\xdc",
        '¿' => "\xbf", '¡' => "\xa1",
    ];
    $str = strtr($str, $map);
    // Eliminar caracteres multi-byte restantes que no están en WPC1252
    $str = preg_replace('/[\x{0100}-\x{FFFF}]/u', '', $str);
    if ($str === null) {
        $str = '';
    }
    if (mb_strlen($str) > $maxLen) {
        $str = mb_substr($str, 0, $maxLen);
    }
    return $str;
}

function pFmtNum($n) {
    return number_format((float)$n, 0, ',', '.');
}
