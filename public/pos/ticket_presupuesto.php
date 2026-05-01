<?php
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$id_factura = (int)($_GET['id'] ?? 0);
$id_empresa = (int)($_GET['id_empresa'] ?? ($_SESSION['id_empresa'] ?? 169));
$width = (int)($_GET['width'] ?? 48);
$forcePdf = isset($_GET['pdf']) && (string)$_GET['pdf'] === '1';
$inModal = isset($_GET['in_modal']) && (string)$_GET['in_modal'] === '1';
$autoPrint = isset($_GET['autoprint']) && (string)$_GET['autoprint'] === '1';
$paper = strtolower(trim((string)($_GET['paper'] ?? 'a4')));
if ($paper !== 'a5') {
    $paper = 'a4';
}

require_once __DIR__ . '/config/db_config.php';
require_once __DIR__ . '/lib/escpos_logo_helper.php';

function presupuestoFormatMoney($amount): string
{
    return number_format((float)$amount, 0, ',', '.');
}

function presupuestoFormatDate($raw): string
{
    $ts = strtotime((string)$raw);
    return $ts !== false ? date('d/m/Y', $ts) : '';
}

function presupuestoEscClean(string $text, int $maxLen = 0): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text));
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    return $maxLen > 0 ? substr($text, 0, $maxLen) : $text;
}

function presupuestoPad(string $left, string $right, int $width): string
{
    $left = presupuestoEscClean($left);
    $right = presupuestoEscClean($right);
    $space = max(1, $width - strlen($left) - strlen($right));
    return $left . str_repeat(' ', $space) . $right;
}

function presupuestoCenter(string $text, int $width): string
{
    $text = presupuestoEscClean($text, $width);
    $len = strlen($text);
    if ($len >= $width) return $text;
    $left = (int)floor(($width - $len) / 2);
    return str_repeat(' ', max(0, $left)) . $text;
}

function presupuestoLine(string $char, int $width): string
{
    return str_repeat(substr($char, 0, 1) ?: '-', max(1, $width));
}

function presupuestoCompanyLogoDataUri(array $empresa): string
{
    $logoFile = trim((string)($empresa['logos'] ?? ''));
    if ($logoFile === '') return '';
    $logoPath = dirname(__DIR__) . '/_lib/file/img/empresa/' . $logoFile;
    if (!is_file($logoPath) || filesize($logoPath) <= 0) return '';
    $mime = function_exists('mime_content_type') ? (mime_content_type($logoPath) ?: 'image/png') : 'image/png';
    $raw = @file_get_contents($logoPath);
    if ($raw === false || $raw === '') return '';
    return 'data:' . $mime . ';base64,' . base64_encode($raw);
}

function presupuestoCompanyLogoPath(array $empresa): string
{
    $logoFile = trim((string)($empresa['logos'] ?? ''));
    if ($logoFile === '') return '';
    $logoPath = dirname(__DIR__) . '/_lib/file/img/empresa/' . ltrim($logoFile, '/');
    if (!is_file($logoPath) || filesize($logoPath) <= 0) return '';
    return $logoPath;
}

function presupuestoLogoToEscPosRaster(string $imagePath, int $widthCols = 80): string
{
    if (!function_exists('imagecreatefrompng') || !file_exists($imagePath)) {
        return '';
    }

    $info = @getimagesize($imagePath);
    if (!$info) return '';

    $mime = $info['mime'] ?? '';
    switch ($mime) {
        case 'image/png':
            $img = @imagecreatefrompng($imagePath);
            break;
        case 'image/jpeg':
            $img = @imagecreatefromjpeg($imagePath);
            break;
        case 'image/gif':
            $img = @imagecreatefromgif($imagePath);
            break;
        default:
            return '';
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

    $logoMaxWidth = intval($maxDots * 0.6);
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

    $resized = imagecreatetruecolor($newW, $newH);
    $white = imagecolorallocate($resized, 255, 255, 255);
    imagefill($resized, 0, 0, $white);
    if ($mime === 'image/png') {
        imagealphablending($resized, true);
    }
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    imagedestroy($img);

    $widthBytes = (int)ceil($newW / 8);
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
                    $gray = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
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

function presupuestoLogoToEscPosBitImage(string $imagePath, int $widthCols = 48): string
{
    if (!function_exists('imagecreatefrompng') || !file_exists($imagePath)) {
        return '';
    }

    $info = @getimagesize($imagePath);
    if (!$info) return '';

    $mime = $info['mime'] ?? '';
    switch ($mime) {
        case 'image/png':
            $img = @imagecreatefrompng($imagePath);
            break;
        case 'image/jpeg':
            $img = @imagecreatefromjpeg($imagePath);
            break;
        case 'image/gif':
            $img = @imagecreatefromgif($imagePath);
            break;
        default:
            return '';
    }
    if (!$img) return '';

    $maxDots = $widthCols >= 42 ? 576 : 384;
    $origW = imagesx($img);
    $origH = imagesy($img);
    $logoMaxWidth = intval($maxDots * 0.6);
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

    $resized = imagecreatetruecolor($newW, $newH);
    $white = imagecolorallocate($resized, 255, 255, 255);
    imagefill($resized, 0, 0, $white);
    imagealphablending($resized, true);
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    imagedestroy($img);

    $ESC = "\x1B";
    $LF = "\x0A";
    $out = '';
    $out .= $ESC . "3" . chr(24);
    $leftPadDots = max(0, (int)floor(($maxDots - $newW) / 2));
    $leftNL = $leftPadDots % 256;
    $leftNH = intdiv($leftPadDots, 256);

    for ($y = 0; $y < $newH; $y += 24) {
        $out .= $ESC . "$" . chr($leftNL) . chr($leftNH);
        $out .= $ESC . "*" . chr(33) . chr($newW % 256) . chr(intdiv($newW, 256));
        for ($x = 0; $x < $newW; $x++) {
            for ($k = 0; $k < 3; $k++) {
                $slice = 0;
                for ($b = 0; $b < 8; $b++) {
                    $yy = $y + ($k * 8) + $b;
                    if ($yy >= $newH) continue;
                    $rgb = imagecolorat($resized, $x, $yy);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $bl = $rgb & 0xFF;
                    $gray = ($r * 0.299) + ($g * 0.587) + ($bl * 0.114);
                    if ($gray < 128) {
                        $slice |= (1 << (7 - $b));
                    }
                }
                $out .= chr($slice);
            }
        }
        $out .= $LF;
    }

    $out .= $ESC . "$" . chr(0) . chr(0);
    $out .= $ESC . "2";
    imagedestroy($resized);
    return $out;
}

function presupuestoLogoDebug(string $logoPath, string $logoRaster, bool $disabledForCompatibility = false): array
{
    $debug = [
        'logo_path' => $logoPath,
        'logo_found' => $logoPath !== '' && is_file($logoPath),
        'gd_available' => function_exists('imagecreatefrompng'),
        'logo_rasterized' => $logoRaster !== '',
        'logo_bytes' => strlen($logoRaster),
        'logo_disabled_for_compatibility' => $disabledForCompatibility,
        'message' => '',
    ];

    if ($logoPath === '') {
        $debug['message'] = 'La empresa no tiene logo configurado o el archivo no existe';
        return $debug;
    }
    if ($disabledForCompatibility) {
        $debug['message'] = 'Logo desactivado en ESC/POS por compatibilidad con la impresora';
        return $debug;
    }
    if (!$debug['gd_available']) {
        $debug['message'] = 'PHP GD no esta habilitado para rasterizar el logo';
        return $debug;
    }
    if ($logoRaster === '') {
        $debug['message'] = 'No se pudo convertir el logo a raster ESC/POS';
        return $debug;
    }

    $debug['message'] = 'Logo incluido en el ticket ESC/POS';
    return $debug;
}

try {
    if ($id_factura <= 0) {
        throw new Exception('ID de presupuesto requerido');
    }

    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $masterPdo = $conn['masterPdo'];
    $empresa = $conn['config'];
    $dbName = $conn['dbName'];

    $stmtEmp = $masterPdo->prepare("SELECT * FROM " . MASTER_DB . ".empresa WHERE id_empresa = ? LIMIT 1");
    $stmtEmp->execute([$id_empresa]);
    $empresaMaster = $stmtEmp->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($empresaMaster)) {
        $empresa = array_merge($empresaMaster, $empresa);
    }

    $stmt = $pdo->prepare("
        SELECT p.*, c.nombre AS cliente_nombre, c.numero AS cliente_ruc
        FROM {$dbName}.presupuesto p
        LEFT JOIN {$dbName}.clientes c ON c.id = p.id_cliente
        WHERE p.id_factura = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id_factura]);
    $presupuesto = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$presupuesto) {
        throw new Exception('Presupuesto no encontrado');
    }

    $stmtItems = $pdo->prepare("
        SELECT codigo, descripcion, salida AS cantidad, precio, total
        FROM {$dbName}.presupuesto_item
        WHERE idfactura = :id
        ORDER BY id ASC
    ");
    $stmtItems->execute([':id' => $id_factura]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $empresaNombre = trim((string)($empresa['empresa'] ?? 'EMPRESA'));
    $empresaRuc = trim((string)($empresa['ruc'] ?? ''));
    $empresaDv = trim((string)($empresa['dv'] ?? ''));
    $empresaDireccion = trim((string)($empresa['direccion'] ?? ''));
    $empresaTelefono = trim((string)($empresa['telefono'] ?? ''));
    $empresaEmail = trim((string)($empresa['email'] ?? ''));
    $empresaLogo = presupuestoCompanyLogoDataUri($empresa);
    $empresaLogoPath = presupuestoCompanyLogoPath($empresa);
    $clienteNombre = trim((string)($presupuesto['cliente_nombre'] ?? 'Cliente ocasional'));
    $clienteRuc = trim((string)($presupuesto['cliente_ruc'] ?? $presupuesto['ruc'] ?? '-'));
    $nro = trim((string)($presupuesto['nro_factura'] ?? ('PRES-' . $id_factura)));

    if ($forcePdf || $inModal || $autoPrint) {
        $isA5 = $paper === 'a5';
        ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Presupuesto <?= htmlspecialchars($nro, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        @page { size: <?= $isA5 ? 'A5 portrait' : 'A4 portrait' ?>; margin: <?= $isA5 ? '10mm' : '12mm' ?>; }
        body { font-family: Arial, sans-serif; background: #fff; color: #111; margin: 0; }
        .ticket { width: min(<?= $isA5 ? '540px' : '900px' ?>, 100%); margin: 0 auto; padding: <?= $isA5 ? '16px 14px 28px' : '24px' ?>; }
        .header { display:flex; justify-content:space-between; gap:<?= $isA5 ? '14px' : '24px' ?>; align-items:stretch; border:1px solid #d9dfeb; border-radius:18px; padding:<?= $isA5 ? '14px' : '18px 20px' ?>; margin-bottom:16px; background:linear-gradient(135deg, #f7f9fc 0%, #eef4ff 100%); }
        .brand { display:flex; gap:<?= $isA5 ? '12px' : '18px' ?>; align-items:flex-start; flex:1; min-width:0; }
        .logo-wrap { width:<?= $isA5 ? '78px' : '96px' ?>; min-width:<?= $isA5 ? '78px' : '96px' ?>; height:<?= $isA5 ? '78px' : '96px' ?>; border-radius:22px; background:#fff; border:1px solid #d6dce8; display:flex; align-items:center; justify-content:center; box-shadow:0 10px 30px rgba(15, 23, 42, 0.08); overflow:hidden; }
        .logo { max-width:<?= $isA5 ? '62px' : '76px' ?>; max-height:<?= $isA5 ? '62px' : '76px' ?>; object-fit:contain; }
        .brand-fallback { font-size:<?= $isA5 ? '28px' : '34px' ?>; font-weight:800; color:#1e3a8a; letter-spacing:.04em; }
        .company { flex:1; min-width:0; }
        .title { font-size: <?= $isA5 ? '22px' : '30px' ?>; font-weight: 800; margin: 0 0 4px; color:#0f172a; line-height:1.1; }
        .subtitle { display:inline-flex; align-items:center; font-size: <?= $isA5 ? '10px' : '12px' ?>; color: #1d4ed8; margin-bottom: 10px; text-transform: uppercase; letter-spacing: .16em; font-weight:700; background:rgba(29, 78, 216, 0.08); border:1px solid rgba(29, 78, 216, 0.12); border-radius:999px; padding:4px 10px; }
        .company-meta { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:<?= $isA5 ? '6px 12px' : '8px 16px' ?>; font-size:<?= $isA5 ? '11px' : '12px' ?>; line-height:1.45; color:#334155; }
        .company-line { display:flex; gap:6px; align-items:flex-start; min-width:0; }
        .company-line strong { color:#0f172a; min-width:<?= $isA5 ? '46px' : '54px' ?>; }
        .company-line span { overflow-wrap:anywhere; }
        .doc-head { min-width:<?= $isA5 ? '132px' : '190px' ?>; border-left:1px solid #d9dfeb; padding-left:<?= $isA5 ? '12px' : '18px' ?>; display:flex; flex-direction:column; justify-content:space-between; }
        .doc-head-label { font-size:<?= $isA5 ? '10px' : '11px' ?>; color:#64748b; text-transform:uppercase; letter-spacing:.14em; font-weight:700; }
        .doc-head-number { font-size:<?= $isA5 ? '18px' : '24px' ?>; font-weight:800; color:#0f172a; margin-top:4px; }
        .doc-head-meta { margin-top:12px; display:grid; gap:8px; font-size:<?= $isA5 ? '11px' : '12px' ?>; color:#334155; }
        .doc-head-meta strong { display:block; color:#0f172a; margin-bottom:2px; font-size:<?= $isA5 ? '10px' : '11px' ?>; text-transform:uppercase; letter-spacing:.08em; }
        .meta { font-size: <?= $isA5 ? '12px' : '14px' ?>; line-height: 1.6; margin-bottom: 12px; display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:<?= $isA5 ? '8px 16px' : '10px 24px' ?>; }
        .hr { border-top: 1px dashed #777; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; font-size: <?= $isA5 ? '12px' : '14px' ?>; }
        th, td { padding: <?= $isA5 ? '6px 0' : '10px 8px' ?>; vertical-align: top; }
        th { text-align: left; border-bottom: 1px solid #ccc; font-size: <?= $isA5 ? '11px' : '12px' ?>; text-transform: uppercase; background: <?= $isA5 ? 'transparent' : '#f5f5f5' ?>; }
        td.num, th.num { text-align: right; }
        .total { font-size: <?= $isA5 ? '20px' : '30px' ?>; font-weight: 700; text-align: right; margin-top: 12px; }
        .foot { font-size: 11px; color: #555; text-align: center; margin-top: 14px; }
        @media (max-width: 680px) {
            .header { flex-direction:column; }
            .company-meta { grid-template-columns:1fr; }
            .doc-head { border-left:0; border-top:1px solid #d9dfeb; padding-left:0; padding-top:12px; min-width:0; }
        }
        @media print { body { margin: 0; } .ticket { width: auto; padding: <?= $isA5 ? '8px' : '12px' ?>; } }
    </style>
</head>
<body<?= $autoPrint ? ' onload="window.print()"' : '' ?>>
<div class="ticket">
    <div class="header">
        <div class="brand">
            <div class="logo-wrap">
                <?php if ($empresaLogo !== ''): ?>
                    <img src="<?= htmlspecialchars($empresaLogo, ENT_QUOTES, 'UTF-8') ?>" alt="Logo empresa" class="logo">
                <?php else: ?>
                    <span class="brand-fallback"><?= htmlspecialchars(function_exists('mb_substr') ? mb_substr($empresaNombre, 0, 2, 'UTF-8') : substr($empresaNombre, 0, 2), ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
            <div class="company">
                <h1 class="title"><?= htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') ?></h1>
                <div class="subtitle">Presupuesto</div>
                <div class="company-meta">
                    <?php if ($empresaRuc !== ''): ?>
                        <div class="company-line">
                            <strong>RUC</strong>
                            <span><?= htmlspecialchars($empresaRuc . ($empresaDv !== '' ? '-' . $empresaDv : ''), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($empresaDireccion !== ''): ?>
                        <div class="company-line">
                            <strong>Dir.</strong>
                            <span><?= htmlspecialchars($empresaDireccion, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($empresaTelefono !== ''): ?>
                        <div class="company-line">
                            <strong>Tel.</strong>
                            <span><?= htmlspecialchars($empresaTelefono, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($empresaEmail !== ''): ?>
                        <div class="company-line">
                            <strong>Email</strong>
                            <span><?= htmlspecialchars($empresaEmail, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="doc-head">
            <div>
                <div class="doc-head-label">Documento</div>
                <div class="doc-head-number"><?= htmlspecialchars($nro, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="doc-head-meta">
                <div>
                    <strong>Fecha</strong>
                    <span><?= htmlspecialchars(presupuestoFormatDate($presupuesto['fecha'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div>
                    <strong>Cliente</strong>
                    <span><?= htmlspecialchars($clienteNombre, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        </div>
    </div>
    <div class="meta">
        <div><strong>Nro:</strong> <?= htmlspecialchars($nro, ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong>Fecha:</strong> <?= htmlspecialchars(presupuestoFormatDate($presupuesto['fecha'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong>Cliente:</strong> <?= htmlspecialchars($clienteNombre, ENT_QUOTES, 'UTF-8') ?></div>
        <div><strong>RUC/CI:</strong> <?= htmlspecialchars($clienteRuc, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div class="hr"></div>
    <table>
        <thead>
            <tr>
                <th>Detalle</th>
                <th class="num">Cant.</th>
                <th class="num">Precio</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <div><?= htmlspecialchars((string)($item['descripcion'] ?? 'Producto'), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (!empty($item['codigo'])): ?>
                            <div style="font-size:11px;color:#666;"><?= htmlspecialchars((string)$item['codigo'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?= htmlspecialchars(number_format((float)($item['cantidad'] ?? 0), 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="num"><?= htmlspecialchars(presupuestoFormatMoney($item['precio'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="num"><?= htmlspecialchars(presupuestoFormatMoney($item['total'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="hr"></div>
    <div class="total">Total: <?= htmlspecialchars(presupuestoFormatMoney($presupuesto['total'] ?? 0), ENT_QUOTES, 'UTF-8') ?> Gs</div>
    <div class="foot">Documento interno de presupuesto sin validez fiscal</div>
</div>
</body>
</html>
        <?php
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    if ($width < 32) $width = 32;
    if ($width > 80) $width = 80;

    $ESC = "\x1B";
    $GS = "\x1D";
    $LF = "\x0A";
    $o = '';
    $logoRaster = '';
    $o .= $ESC . "@";
    $o .= $ESC . "t\x10";
    $o .= $GS . "!\x00";
    $logoBefore = strlen($o);
    smxEscposAppendLogo($o, $empresa, (int)$width, $LF);
    if (strlen($o) > $logoBefore) {
        $logoRaster = substr($o, $logoBefore);
    }
    $logoDebug = presupuestoLogoDebug($empresaLogoPath, $logoRaster, false);
    $o .= $ESC . "a\x01";
    $o .= $ESC . "E\x01";
    $o .= $ESC . "!\x10";
    $o .= presupuestoEscClean(strtoupper($empresaNombre), $width) . $LF;
    $o .= $ESC . "!\x00";
    $o .= $ESC . "E\x00";
    if ($empresaRuc !== '') {
        $o .= presupuestoEscClean('RUC: ' . $empresaRuc . ($empresaDv !== '' ? '-' . $empresaDv : ''), $width) . $LF;
    }
    if ($empresaTelefono !== '') {
        $o .= presupuestoEscClean('TEL: ' . $empresaTelefono, $width) . $LF;
    }
    if ($empresaDireccion !== '') {
        $o .= presupuestoEscClean($empresaDireccion, $width) . $LF;
    }
    if ($empresaEmail !== '') {
        $o .= presupuestoEscClean($empresaEmail, $width) . $LF;
    }
    $o .= $ESC . "a\x01";
    $o .= presupuestoLine('=', $width) . $LF;
    $o .= $ESC . "E\x01";
    $o .= $ESC . "!\x18";
    $o .= "PRESUPUESTO" . $LF;
    $o .= $ESC . "!\x00";
    $o .= $ESC . "E\x00";
    $o .= presupuestoLine('=', $width) . $LF;
    $o .= $ESC . "a\x00";
    $o .= presupuestoPad('NRO', $nro, $width) . $LF;
    $o .= presupuestoPad('FECHA', presupuestoFormatDate($presupuesto['fecha'] ?? ''), $width) . $LF;
    $o .= presupuestoPad('CLIENTE', presupuestoEscClean($clienteNombre, $width - 9), $width) . $LF;
    $o .= presupuestoPad('RUC/CI', presupuestoEscClean($clienteRuc, $width - 8), $width) . $LF;
    $o .= presupuestoLine('-', $width) . $LF;
    $o .= presupuestoPad('DETALLE', 'TOTAL', $width) . $LF;
    $o .= presupuestoLine('-', $width) . $LF;
    foreach ($items as $item) {
        $desc = presupuestoEscClean((string)($item['descripcion'] ?? 'Producto'));
        $cant = number_format((float)($item['cantidad'] ?? 0), 2, ',', '');
        $precio = presupuestoFormatMoney($item['precio'] ?? 0);
        $total = presupuestoFormatMoney($item['total'] ?? 0);
        if (!empty($item['codigo'])) {
            $o .= '[' . presupuestoEscClean((string)$item['codigo'], max(0, $width - 2)) . ']' . $LF;
        }
        $o .= substr($desc, 0, $width) . $LF;
        $o .= presupuestoPad($cant . ' x ' . $precio, $total, $width) . $LF;
    }
    $o .= presupuestoLine('-', $width) . $LF;
    $o .= presupuestoPad('ITEMS', (string)count($items), $width) . $LF;
    $o .= $ESC . "E\x01";
    $o .= presupuestoPad('TOTAL', presupuestoFormatMoney($presupuesto['total'] ?? 0) . ' Gs', $width) . $LF;
    $o .= $ESC . "E\x00";
    $o .= presupuestoLine('=', $width) . $LF;
    $o .= $ESC . "a\x01";
    $o .= presupuestoCenter('Gracias por su preferencia', $width) . $LF;
    $o .= presupuestoCenter('Documento interno sin validez fiscal', $width) . $LF;
    $o .= $LF . $LF . $LF;
    $o .= $GS . "V\x00";

    echo json_encode([
        'success' => true,
        'data' => base64_encode($o),
        'format' => 'escpos',
        'tipo' => 'presupuesto',
        'items_count' => count($items),
        'debug' => [
            'logo' => $logoDebug,
        ],
    ]);
} catch (Throwable $e) {
    if (!$forcePdf && !$inModal) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
    http_response_code(400);
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
