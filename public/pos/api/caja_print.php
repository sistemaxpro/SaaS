<?php
/**
 * API: Genera comprobante ESC/POS para operaciones de caja.
 * Respuesta: JSON { success, data(base64), format, tipo }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/db_config.php';

try {
    $id = (int)($_GET['id'] ?? 0);
    $id_empresa = (int)($_GET['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
    $width = (int)($_GET['width'] ?? 48);

    if ($id <= 0) {
        throw new Exception('ID de operacion requerido');
    }

    if ($width < 32) $width = 32;
    if ($width > 80) $width = 80;

    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];
    $masterPdo = $conn['masterPdo'];

    $sql = "SELECT
                e.id,
                e.fecha,
                e.concepto,
                e.debito,
                e.credito,
                e.comprobante,
                e.medio_cobro,
                e.estado,
                e.codigo AS id_caja,
                e.login,
                e.operacion,
                e.referencia,
                o.operacion AS operacion_nombre,
                r.referencia AS referencia_nombre,
                c.caja AS caja_nombre
            FROM {$dbName}.extracto_caja e
            LEFT JOIN " . MASTER_DB . ".operaciones o ON o.id = e.operacion
            LEFT JOIN " . MASTER_DB . ".referencia r ON r.id = e.referencia
            LEFT JOIN {$dbName}.cajas c ON c.id_caja = e.codigo
            WHERE e.id = :id
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $op = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$op) {
        throw new Exception('Operacion no encontrada');
    }

    $empresaNombre = 'SISTEMAX';
    try {
        $stmtEmp = $masterPdo->prepare("SELECT empresa FROM " . MASTER_DB . ".empresa WHERE id_empresa = :id LIMIT 1");
        $stmtEmp->execute([':id' => $id_empresa]);
        $empresaNombre = trim((string)($stmtEmp->fetchColumn() ?: 'SISTEMAX'));
    } catch (Throwable $e) {
    }

    $escpos = buildCajaReceipt($op, $empresaNombre, $id_empresa, $width);

    echo json_encode([
        'success' => true,
        'data' => base64_encode($escpos),
        'format' => 'escpos',
        'tipo' => 'caja',
        'id' => (int)$op['id']
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function buildCajaReceipt(array $op, string $empresa, int $idEmpresa, int $width): string
{
    $ESC = "\x1B";
    $GS = "\x1D";
    $LF = "\x0A";

    $referenciaNombre = strtoupper(trim((string)($op['referencia_nombre'] ?? '')));
    $concepto = trim((string)($op['concepto'] ?? ''));
    $conceptoUp = strtoupper($concepto);

    $isApertura = ((int)($op['referencia'] ?? 0) === 16)
        || (strpos($referenciaNombre, 'APERTURA DE CAJA') !== false)
        || (strpos($conceptoUp, 'APERTURA DE CAJA') !== false);

    $credito = (float)($op['credito'] ?? 0);
    $debito = (float)($op['debito'] ?? 0);
    $monto = $credito > 0 ? $credito : $debito;
    $tipo = $credito > 0 ? 'ENTRADA' : 'SALIDA';
    $fechaFmt = '-';
    if (!empty($op['fecha'])) {
        $ts = strtotime((string)$op['fecha']);
        if ($ts) $fechaFmt = date('d/m/Y H:i', $ts);
    }

    $title = $isApertura ? 'RECIBO DE DINERO' : 'COMPROBANTE DE CAJA';
    $subTitle = $isApertura ? 'REFERENCIA: APERTURA DE CAJA' : ('MOVIMIENTO: ' . $tipo);

    $qrPayload = implode('|', [
        'EMP:' . $idEmpresa,
        'OP:' . (int)($op['id'] ?? 0),
        'CAJA:' . trim((string)($op['id_caja'] ?? '')),
        'TIPO:' . $tipo,
        'MONTO:' . number_format($monto, 0, '', ''),
        'FECHA:' . $fechaFmt
    ]);

    $o = '';
    $o .= $ESC . "@";
    $o .= $ESC . "t\x10";
    $o .= $GS . "!\x00";

    $o .= $ESC . "a\x01";
    $o .= $ESC . "E\x01";
    $o .= str_repeat('=', $width) . $LF;
    $o .= centerText(cleanEscpos($empresa), $width) . $LF;
    $o .= $ESC . "E\x00";
    $o .= centerText($title, $width) . $LF;
    $o .= centerText($subTitle, $width) . $LF;
    $o .= str_repeat('=', $width) . $LF;

    $o .= $ESC . "a\x00";
    $o .= 'Operacion ID : #' . (int)($op['id'] ?? 0) . $LF;
    $o .= 'Fecha/Hora   : ' . $fechaFmt . $LF;
    $o .= 'Caja         : ' . cleanEscpos((string)($op['caja_nombre'] ?: ('Caja #' . (int)($op['id_caja'] ?? 0)))) . $LF;
    $o .= 'Cajero       : ' . cleanEscpos((string)($op['login'] ?? '-')) . $LF;
    $o .= 'Operacion    : ' . cleanEscpos((string)($op['operacion_nombre'] ?? $tipo)) . $LF;
    $o .= 'Referencia   : ' . cleanEscpos(normalizeAperturaLabel((string)($op['referencia_nombre'] ?? '-'))) . $LF;
    $o .= 'Medio pago   : ' . cleanEscpos(mapMedioCobro((string)($op['medio_cobro'] ?? ''))) . $LF;
    if (!empty($op['comprobante'])) {
        $o .= 'Comprobante  : ' . cleanEscpos((string)$op['comprobante']) . $LF;
    }
    $o .= str_repeat('-', $width) . $LF;

    foreach (wrapText('Concepto: ' . ($concepto !== '' ? $concepto : '-'), $width) as $line) {
        $o .= cleanEscpos($line) . $LF;
    }

    $o .= str_repeat('-', $width) . $LF;
    $o .= $ESC . "E\x01";
    $o .= padRight($tipo . ' (Gs):', $width - 14) . padLeft(number_format($monto, 0, ',', '.'), 14) . $LF;
    $o .= $ESC . "E\x00";
    $o .= str_repeat('=', $width) . $LF;

    $o .= $ESC . "a\x01";
    $o .= 'Estado: ' . ((int)($op['estado'] ?? 1) === 1 ? 'ACTIVO' : 'ANULADO') . $LF;
    $o .= $LF;

    if (!$isApertura) {
        $o .= escposQr($qrPayload, 5);
        $o .= centerText('QR de verificacion', $width) . $LF;
        $o .= $LF;
    }

    if ($isApertura) {
        $o .= $ESC . "a\x00";
        $o .= 'Firma Cajero:      ______________________' . $LF;
        $o .= 'Firma Supervisor:  ______________________' . $LF;
        $o .= $LF;
    }

    $o .= $ESC . "a\x01";
    $o .= 'Documento emitido por SistemaX' . $LF;
    $o .= date('d/m/Y H:i:s') . $LF;
    $o .= $LF . $LF . $LF;
    $o .= $GS . "V\x00";

    return $o;
}

function normalizeAperturaLabel(string $label): string
{
    $label = trim($label);
    if ($label === '') return '-';
    $up = strtoupper($label);
    if ($up === 'APERURA DE CAJA' || $up === 'APERTURA DE CJA') {
        return 'Apertura de caja';
    }
    return $label;
}

function mapMedioCobro(string $medio): string
{
    $m = strtoupper(trim($medio));
    if ($m === '' || $m === '1' || $m === 'EFECTIVO') return 'EFECTIVO';
    if ($m === '2' || $m === 'TARJETA') return 'TARJETA';
    if ($m === '3' || $m === 'TRANSFER' || $m === 'TRANSFERENCIA') return 'TRANSFERENCIA';
    if ($m === '4' || $m === 'QR' || $m === 'PIX') return 'QR';
    if ($m === '5' || $m === 'CREDITO') return 'CREDITO';
    return $medio !== '' ? $medio : 'EFECTIVO';
}

function centerText(string $text, int $width): string
{
    $text = cleanEscpos($text);
    $len = strlen($text);
    if ($len >= $width) {
        return substr($text, 0, $width);
    }
    $pad = intdiv($width - $len, 2);
    return str_repeat(' ', $pad) . $text;
}

function padLeft(string $text, int $width): string
{
    $text = cleanEscpos($text);
    if (strlen($text) >= $width) return substr($text, 0, $width);
    return str_repeat(' ', $width - strlen($text)) . $text;
}

function padRight(string $text, int $width): string
{
    $text = cleanEscpos($text);
    if (strlen($text) >= $width) return substr($text, 0, $width);
    return $text . str_repeat(' ', $width - strlen($text));
}

function wrapText(string $text, int $width): array
{
    $text = preg_replace('/\s+/', ' ', trim($text));
    if ($text === '') return [''];

    $words = explode(' ', $text);
    $lines = [];
    $line = '';

    foreach ($words as $word) {
        $candidate = ($line === '') ? $word : ($line . ' ' . $word);
        if (strlen(cleanEscpos($candidate)) <= $width) {
            $line = $candidate;
            continue;
        }

        if ($line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $lines[] = substr($word, 0, $width);
            $line = '';
        }
    }

    if ($line !== '') {
        $lines[] = $line;
    }

    return $lines;
}

function cleanEscpos(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($converted !== false && $converted !== '') {
        $s = $converted;
    }
    $s = preg_replace('/[^\x20-\x7E]/', '', $s);
    return $s;
}

function escposQr(string $content, int $size = 5): string
{
    $GS = "\x1D";
    $LF = "\x0A";

    $data = cleanEscpos($content);
    if ($data === '') return '';

    $storeLen = strlen($data) + 3;
    $pL = chr($storeLen % 256);
    $pH = chr(intdiv($storeLen, 256));

    $o = '';
    $o .= $GS . "(k" . "\x04\x00" . "1A" . chr(max(3, min(8, $size)));
    $o .= $GS . "(k" . "\x03\x00" . "1C\x31";
    $o .= $GS . "(k" . $pL . $pH . "1P0" . $data;
    $o .= $GS . "(k" . "\x03\x00" . "1Q0";
    $o .= $LF;
    return $o;
}
