<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../productos/config/db_config.php';

Session::requireLogin('/public/login.php');
Permission::requireAccess('app_grid_mercaderias');

header('Content-Type: application/json; charset=utf-8');

$idEmpresa = (int)($_GET['id_empresa'] ?? Session::get('id_empresa', 169));
$idTraslado = (int)($_GET['id'] ?? 0);
$width = max(32, min(48, (int)($_GET['width'] ?? 32)));

if ($idTraslado <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Traslado requerido']);
    exit;
}

function trasladoLabelSignToken(int $idEmpresa, int $idTraslado, string $referencia, int $idSucursalDestino): string
{
    $payload = $idEmpresa . '|' . $idTraslado . '|' . $referencia . '|' . $idSucursalDestino;
    return hash_hmac('sha256', $payload, MASTER_DB . '|inventario-traslado-qr-v1');
}

function trasladoLabelWrap(string $text, int $width): array
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

function trasladoLabelQrNative(string $data, int $moduleSize = 4, int $ecLevel = 49): string
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
    if (!$traslado) throw new RuntimeException('Traslado no encontrado');

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $receiveToken = trasladoLabelSignToken($idEmpresa, $idTraslado, (string)$traslado['referencia'], (int)$traslado['id_sucursal_destino']);
    $receiveUrl = $scheme . '://' . $host . '/public/inventario/traslado_receive.php?id=' . $idTraslado . '&id_empresa=' . $idEmpresa . '&token=' . urlencode($receiveToken);

    $ESC = "\x1B";
    $GS = "\x1D";
    $LF = "\x0A";
    $o = '';
    $o .= $ESC . "@";
    $o .= $ESC . "t\x10";
    $o .= $ESC . "a\x01";
    $o .= $ESC . "!\x10";
    $o .= "TRASLADO" . $LF;
    $o .= $ESC . "!\x00";
    $o .= "REF " . ($traslado['referencia'] ?? '') . $LF;
    $o .= str_repeat('-', $width) . $LF;
    $o .= $ESC . "a\x00";
    foreach (trasladoLabelWrap((string)($traslado['desproducto'] ?? ''), $width) as $line) {
        $o .= $line . $LF;
    }
    $o .= "COD: " . ($traslado['cve_producto'] ?? '') . $LF;
    $o .= "CANT: " . number_format((float)($traslado['cantidad'] ?? 0), 2, ',', '.') . $LF;
    $o .= "ORG: " . ($traslado['sucursal_origen'] ?? '') . $LF;
    $o .= "DST: " . ($traslado['sucursal_destino'] ?? '') . $LF;
    $o .= str_repeat('-', $width) . $LF;
    $o .= $ESC . "a\x01";
    $o .= "RECEPCION" . $LF;
    $o .= trasladoLabelQrNative($receiveUrl, 4, 49);
    $o .= $LF . $LF;
    $o .= $GS . "V" . chr(66) . chr(0);

    echo json_encode([
        'success' => true,
        'tipo' => 'traslado_label',
        'data' => base64_encode($o),
        'referencia' => (string)$traslado['referencia'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
