<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';

function buildDocReceiptTokenEscpos(int $idEmpresa, int $idCajaOp, int $idDocumento): string
{
    $secret = hash('sha256', 'sistemax-doc-receipt|' . MASTER_DB . '|v1');
    return hash_hmac('sha256', $idEmpresa . '|' . $idCajaOp . '|' . $idDocumento, $secret);
}

function docMoney($value): string
{
    return number_format((float)$value, 0, ',', '.');
}

function docCenter(string $text, int $width): string
{
    $text = docClean($text);
    $len = strlen($text);
    if ($len >= $width) return substr($text, 0, $width);
    $pad = intdiv($width - $len, 2);
    return str_repeat(' ', $pad) . $text;
}

function docPadRight(string $text, int $width): string
{
    $text = docClean($text);
    if (strlen($text) >= $width) return substr($text, 0, $width);
    return $text . str_repeat(' ', $width - strlen($text));
}

function docPadLeft(string $text, int $width): string
{
    $text = docClean($text);
    if (strlen($text) >= $width) return substr($text, 0, $width);
    return str_repeat(' ', $width - strlen($text)) . $text;
}

function docClean(string $text): string
{
    $text = trim($text);
    $map = [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','Á'=>'A','À'=>'A','Ä'=>'A','Â'=>'A',
        'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','É'=>'E','È'=>'E','Ë'=>'E','Ê'=>'E',
        'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','Í'=>'I','Ì'=>'I','Ï'=>'I','Î'=>'I',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','Ó'=>'O','Ò'=>'O','Ö'=>'O','Ô'=>'O',
        'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','Ú'=>'U','Ù'=>'U','Ü'=>'U','Û'=>'U',
        'ñ'=>'n','Ñ'=>'N'
    ];
    return strtr($text, $map);
}

function docWrap(string $text, int $width): array
{
    $text = preg_replace('/\s+/', ' ', trim($text));
    if ($text === '') return [''];
    $words = explode(' ', $text);
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $candidate = $line === '' ? $word : ($line . ' ' . $word);
        if (strlen(docClean($candidate)) <= $width) {
            $line = $candidate;
            continue;
        }
        if ($line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $lines[] = substr(docClean($word), 0, $width);
            $line = '';
        }
    }
    if ($line !== '') $lines[] = $line;
    return $lines;
}

function docQr(string $payload, int $module = 5): string
{
    $GS = "\x1D";
    $pL = (strlen($payload) + 3) % 256;
    $pH = intdiv(strlen($payload) + 3, 256);
    return $GS . "(k\x04\x00\x31\x41\x32\x00"
        . $GS . "(k\x03\x00\x31\x43" . chr(max(3, min(8, $module)))
        . $GS . "(k\x03\x00\x31\x45\x30"
        . $GS . "(k" . chr($pL) . chr($pH) . "\x31\x50\x30" . $payload
        . $GS . "(k\x03\x00\x31\x51\x30";
}

try {
    $idCajaOp = (int)($_GET['id'] ?? 0);
    $idDocumento = (int)($_GET['doc'] ?? 0);
    $idEmpresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
    $width = (int)($_GET['width'] ?? 48);

    if ($idCajaOp <= 0 || $idDocumento <= 0) {
        throw new Exception('ID de cobro/documento requerido');
    }

    if ($width < 32) $width = 32;
    if ($width > 80) $width = 80;

    $conn = getEmpresaConnection($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $masterPdo = $conn['masterPdo'];

    $empresa = 'SISTEMAX';
    try {
        $stmtEmp = $masterPdo->prepare("SELECT empresa FROM " . MASTER_DB . ".empresa WHERE id_empresa = :id LIMIT 1");
        $stmtEmp->execute([':id' => $idEmpresa]);
        $empresa = trim((string)($stmtEmp->fetchColumn() ?: 'SISTEMAX'));
    } catch (Throwable $e) {
    }

    $stmt = $pdo->prepare("
        SELECT
            ec.id,
            ec.fecha,
            ec.credito,
            ec.comprobante,
            ec.medio_cobro,
            ec.login,
            ec.id_login,
            COALESCE(NULLIF(TRIM(CAST(ec.login AS CHAR)), ''), NULLIF(TRIM(CAST(ec.login AS CHAR)), '0'), su.login, CONCAT('#', ec.id_login)) AS operador_nombre,
            d.numero AS id_documento,
            d.concepto AS documento_concepto,
            d.cantidad_cuota,
            d.fecha_vencimiento,
            d.total AS documento_total,
            d.pagado AS documento_pagado,
            d.pendiente AS documento_pendiente,
            d.id_factura,
            c.nombre AS cliente_nombre,
            c.numero AS cliente_ruc,
            fv.nro_factura
        FROM {$db}.extracto_caja ec
        INNER JOIN {$db}.documentos d ON d.numero = ec.id_relacion
        LEFT JOIN {$db}.clientes c ON c.id = d.id_cliente
        LEFT JOIN {$db}.factura_ventas fv ON fv.id_factura = d.id_factura
        LEFT JOIN " . MASTER_DB . ".sec_users su ON su.id_login = ec.id_login
        WHERE ec.id = :id
          AND ec.tabla_relacion = 'documentos'
          AND ec.id_relacion = :doc
        LIMIT 1
    ");
    $stmt->execute([':id' => $idCajaOp, ':doc' => $idDocumento]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Cobro de documento no encontrado');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $receiptQuery = http_build_query([
        'id_empresa' => $idEmpresa,
        'id' => $idCajaOp,
        'doc' => $idDocumento,
        'token' => buildDocReceiptTokenEscpos($idEmpresa, $idCajaOp, $idDocumento),
    ]);
    $receiptUrl = ($host !== '' ? ($scheme . '://' . $host) : '') . '/public/pos/cobro_documento_comprobante.php?' . $receiptQuery;

    $ESC = "\x1B";
    $GS = "\x1D";
    $LF = "\x0A";
    $o = $ESC . "@";
    $o .= $ESC . "t\x10";
    $o .= $ESC . "a\x01";
    $o .= $ESC . "E\x01";
    $o .= str_repeat('=', $width) . $LF;
    $o .= docCenter($empresa, $width) . $LF;
    $o .= $ESC . "E\x00";
    $o .= docCenter('RECIBO DE PAGO', $width) . $LF;
    $o .= str_repeat('=', $width) . $LF;
    $o .= $ESC . "a\x00";
    $o .= 'Recibo      : #' . (int)$row['id'] . $LF;
    $o .= 'Fecha       : ' . date('d/m/Y H:i', strtotime((string)($row['fecha'] ?? 'now'))) . $LF;
    $o .= 'Cliente     : ' . docClean((string)($row['cliente_nombre'] ?? 'Sin cliente')) . $LF;
    if (!empty($row['cliente_ruc'])) {
        $o .= 'RUC/CI      : ' . docClean((string)$row['cliente_ruc']) . $LF;
    }
    $o .= 'Documento   : ' . docClean((string)($row['cantidad_cuota'] ?: ('#' . (int)$row['id_documento']))) . $LF;
    if (!empty($row['nro_factura'])) {
        $o .= 'Factura     : ' . docClean((string)$row['nro_factura']) . $LF;
    }
    $o .= 'Medio       : ' . docClean((string)($row['medio_cobro'] ?? 'EFECTIVO')) . $LF;
    if (!empty($row['comprobante'])) {
        $o .= 'Referencia  : ' . docClean((string)$row['comprobante']) . $LF;
    }
    $o .= str_repeat('-', $width) . $LF;
    foreach (docWrap('Concepto: ' . (string)($row['documento_concepto'] ?? '-'), $width) as $line) {
        $o .= docClean($line) . $LF;
    }
    $o .= str_repeat('-', $width) . $LF;
    $o .= docPadRight('COBRADO (Gs):', $width - 14) . docPadLeft(docMoney($row['credito'] ?? 0), 14) . $LF;
    $o .= docPadRight('TOTAL DOC.:', $width - 14) . docPadLeft(docMoney($row['documento_total'] ?? 0), 14) . $LF;
    $o .= docPadRight('PAGADO:', $width - 14) . docPadLeft(docMoney($row['documento_pagado'] ?? 0), 14) . $LF;
    $o .= docPadRight('PENDIENTE:', $width - 14) . docPadLeft(docMoney($row['documento_pendiente'] ?? 0), 14) . $LF;
    $o .= str_repeat('-', $width) . $LF;
    $o .= 'Operador    : ' . docClean((string)($row['operador_nombre'] ?? $row['login'] ?? '-')) . $LF;
    $o .= $LF;
    $o .= $ESC . "a\x01";
    $o .= docQr($receiptUrl, 5) . $LF;
    $o .= docCenter('QR comprobante', $width) . $LF;
    $o .= $LF;
    $o .= docCenter('Emitido por SistemaX', $width) . $LF;
    $o .= docCenter(date('d/m/Y H:i:s'), $width) . $LF;
    $o .= $LF . $LF . $LF;
    $o .= $GS . "V\x00";

    echo json_encode([
        'success' => true,
        'data' => base64_encode($o),
        'format' => 'escpos',
        'tipo' => 'recibo_pago_documento',
        'receipt_url' => $receiptUrl,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
