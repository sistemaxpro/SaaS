<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../productos/config/db_config.php';

Session::requireLogin('/public/login.php');
Permission::requireAccess('app_grid_mercaderias');

header('Content-Type: application/json; charset=utf-8');

$idEmpresa = (int)($_GET['id_empresa'] ?? Session::get('id_empresa', 169));
$idTraslado = (int)($_GET['id'] ?? 0);
$width = max(32, min(56, (int)($_GET['width'] ?? 48)));

if ($idTraslado <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Traslado requerido']);
    exit;
}

function escpos_pad(string $text, int $width, string $align = 'left'): string
{
    $text = trim($text);
    $len = function_exists('mb_strwidth') ? mb_strwidth($text, 'UTF-8') : strlen($text);
    if ($len > $width) {
        return $text;
    }
    $pad = $width - $len;
    if ($align === 'right') return str_repeat(' ', $pad) . $text;
    if ($align === 'center') {
        $left = intdiv($pad, 2);
        return str_repeat(' ', $left) . $text;
    }
    return $text;
}

function escpos_wrap(string $text, int $width): array
{
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if ($text === '') return [''];
    $words = preg_split('/\s+/', $text) ?: [];
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $test = $line === '' ? $word : ($line . ' ' . $word);
        $len = function_exists('mb_strwidth') ? mb_strwidth($test, 'UTF-8') : strlen($test);
        if ($len <= $width) {
            $line = $test;
            continue;
        }
        if ($line !== '') $lines[] = $line;
        $line = $word;
    }
    if ($line !== '') $lines[] = $line;
    return $lines ?: [''];
}

function trasladoSignToken(int $idEmpresa, int $idTraslado, string $referencia, int $idSucursalDestino): string
{
    $payload = $idEmpresa . '|' . $idTraslado . '|' . $referencia . '|' . $idSucursalDestino;
    return hash_hmac('sha256', $payload, MASTER_DB . '|inventario-traslado-qr-v1');
}

function escposQrNative(string $data, int $moduleSize = 6, int $ecLevel = 49): string
{
    $GS = "\x1D";
    $size = max(3, min(8, $moduleSize));
    $storeLen = strlen($data) + 3;
    $pL = chr($storeLen % 256);
    $pH = chr(intdiv($storeLen, 256));

    return
        $GS . "(k" . chr(4) . chr(0) . chr(49) . chr(65) . chr(50) . chr(0) .
        $GS . "(k" . chr(3) . chr(0) . chr(49) . chr(67) . chr($size) .
        $GS . "(k" . chr(3) . chr(0) . chr(49) . chr(69) . chr($ecLevel) .
        $GS . "(k" . $pL . $pH . chr(49) . chr(80) . chr(48) . $data .
        $GS . "(k" . chr(3) . chr(0) . chr(49) . chr(81) . chr(48);
}

try {
    $conn = getEmpresaConnection($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $masterPdo = $conn['masterPdo'] ?? null;
    $masterDb = (string)($conn['masterDb'] ?? '');

    $stmtEmpresa = $masterPdo->prepare("SELECT empresa FROM {$masterDb}.empresa WHERE id_empresa = :id LIMIT 1");
    $stmtEmpresa->execute([':id' => $idEmpresa]);
    $empresaNombre = (string)($stmtEmpresa->fetchColumn() ?: 'SistemaX');

    $stmt = $pdo->prepare("
        SELECT
            t.*,
            COALESCE(p.cve_producto, '') AS cve_producto,
            COALESCE(p.desproducto, '') AS desproducto,
            COALESCE(so.sucursal, CONCAT('Suc. ', t.id_sucursal_origen)) AS sucursal_origen,
            COALESCE(sd.sucursal, CONCAT('Suc. ', t.id_sucursal_destino)) AS sucursal_destino
        FROM {$db}.inventario_traslados t
        INNER JOIN {$db}.tblproductos p ON p.idproducto = t.id_producto
        LEFT JOIN {$db}.sucursales so ON so.id_sucursal = t.id_sucursal_origen
        LEFT JOIN {$db}.sucursales sd ON sd.id_sucursal = t.id_sucursal_destino
        WHERE t.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $idTraslado]);
    $traslado = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$traslado) {
        throw new RuntimeException('Traslado no encontrado');
    }

    $operadores = [];
    $ids = array_values(array_filter([
        (int)($traslado['id_login_salida'] ?? 0),
        (int)($traslado['id_login_recepcion'] ?? 0),
    ]));
    if ($masterPdo && $masterDb !== '' && !empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $uStmt = $masterPdo->prepare("SELECT id_login, COALESCE(NULLIF(name,''), NULLIF(login,''), CONCAT('Usuario ', id_login)) AS nombre FROM {$masterDb}.sec_users WHERE id_login IN ({$ph})");
        $uStmt->execute($ids);
        foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $operadores[(int)$row['id_login']] = (string)$row['nombre'];
        }
    }

    $lines = [];
    $nl = "\n";
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $receiveToken = trasladoSignToken($idEmpresa, $idTraslado, (string)$traslado['referencia'], (int)$traslado['id_sucursal_destino']);
    $receiveUrl = $scheme . '://' . $host . '/public/inventario/traslado_receive.php?id=' . $idTraslado . '&id_empresa=' . $idEmpresa . '&token=' . urlencode($receiveToken);

    $lines[] = chr(27) . '@';
    $lines[] = chr(27) . 'a' . chr(1);
    $lines[] = escpos_pad($empresaNombre, $width, 'center') . $nl;
    $lines[] = escpos_pad('COMPROBANTE DE TRASLADO', $width, 'center') . $nl;
    $lines[] = str_repeat('-', $width) . $nl;
    $lines[] = chr(27) . 'a' . chr(0);
    $lines[] = 'REF: ' . ($traslado['referencia'] ?? '') . $nl;
    $lines[] = 'ESTADO: ' . ($traslado['estado'] ?? '') . $nl;
    $lines[] = 'FECHA: ' . ($traslado['fecha_salida'] ?? '') . $nl;
    $lines[] = str_repeat('-', $width) . $nl;
    foreach (escpos_wrap((string)($traslado['desproducto'] ?? ''), $width) as $line) {
        $lines[] = $line . $nl;
    }
    $lines[] = 'COD: ' . ($traslado['cve_producto'] ?? '') . $nl;
    $lines[] = 'CANT: ' . number_format((float)($traslado['cantidad'] ?? 0), 2, ',', '.') . $nl;
    $lines[] = str_repeat('-', $width) . $nl;
    $lines[] = 'ORIGEN:' . $nl;
    foreach (escpos_wrap((string)($traslado['sucursal_origen'] ?? ''), $width) as $line) {
        $lines[] = '  ' . $line . $nl;
    }
    $lines[] = 'SALIDA: ' . ($operadores[(int)($traslado['id_login_salida'] ?? 0)] ?? ('Usuario ' . (int)($traslado['id_login_salida'] ?? 0))) . $nl;
    $lines[] = str_repeat('-', $width) . $nl;
    $lines[] = 'DESTINO:' . $nl;
    foreach (escpos_wrap((string)($traslado['sucursal_destino'] ?? ''), $width) as $line) {
        $lines[] = '  ' . $line . $nl;
    }
    $rec = (int)($traslado['id_login_recepcion'] ?? 0) > 0 ? ($operadores[(int)$traslado['id_login_recepcion']] ?? ('Usuario ' . (int)$traslado['id_login_recepcion'])) : 'Pendiente';
    $lines[] = 'RECEP.: ' . $rec . $nl;
    if (!empty($traslado['fecha_recepcion'])) {
        $lines[] = 'F. RECEP: ' . $traslado['fecha_recepcion'] . $nl;
    }
    $obs = trim((string)($traslado['obs'] ?? ''));
    if ($obs !== '') {
        $lines[] = str_repeat('-', $width) . $nl;
        $lines[] = 'OBS:' . $nl;
        foreach (escpos_wrap($obs, $width) as $line) {
            $lines[] = $line . $nl;
        }
    }
    $lines[] = str_repeat('-', $width) . $nl;
    $lines[] = chr(27) . 'a' . chr(1);
    $lines[] = 'ESCANEE PARA RECEPCION' . $nl;
    $lines[] = escposQrNative($receiveUrl, 5, 49);
    $lines[] = $nl;
    foreach (escpos_wrap($receiveUrl, $width) as $line) {
        $lines[] = $line . $nl;
    }
    $lines[] = str_repeat('-', $width) . $nl;
    $lines[] = chr(27) . 'a' . chr(1);
    $lines[] = escpos_pad('SistemaX Inventario', $width, 'center') . $nl;
    $lines[] = $nl . $nl . $nl;
    $lines[] = chr(29) . 'V' . chr(66) . chr(0);

    echo json_encode([
        'success' => true,
        'tipo' => 'traslado',
        'data' => base64_encode(implode('', $lines)),
        'referencia' => (string)$traslado['referencia'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
