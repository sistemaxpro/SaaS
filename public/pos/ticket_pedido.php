<?php
header('Access-Control-Allow-Origin: *');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$id_pedido = (int)($_GET['id'] ?? 0);
$id_empresa = (int)($_GET['id_empresa'] ?? ($_SESSION['id_empresa'] ?? 169));
$forcePdf = isset($_GET['pdf']) && (string)$_GET['pdf'] === '1';
$inModal = isset($_GET['in_modal']) && (string)$_GET['in_modal'] === '1';
$autoPrint = isset($_GET['autoprint']) && (string)$_GET['autoprint'] === '1';
$paper = strtolower(trim((string)($_GET['paper'] ?? 'a4')));
if ($paper !== 'a5') {
    $paper = 'a4';
}

require_once __DIR__ . '/config/db_config.php';
require_once __DIR__ . '/lib/escpos_logo_helper.php';

function pedidoFormatMoney($amount): string
{
    return number_format((float)$amount, 0, ',', '.');
}

function pedidoFormatDate($raw): string
{
    $ts = strtotime((string)$raw);
    return $ts !== false ? date('d/m/Y H:i', $ts) : '';
}

function pedidoEscClean(string $text, int $maxLen = 0): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text));
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    return $maxLen > 0 ? substr($text, 0, $maxLen) : $text;
}

function pedidoPad(string $left, string $right, int $width): string
{
    $left = pedidoEscClean($left);
    $right = pedidoEscClean($right);
    $space = max(1, $width - strlen($left) - strlen($right));
    return $left . str_repeat(' ', $space) . $right;
}

function pedidoCompanyLogoDataUri(array $empresa): string
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

function pedidoCompanyLogoPath(array $empresa): string
{
    $logoFile = trim((string)($empresa['logos'] ?? ''));
    if ($logoFile === '') return '';
    $logoPath = dirname(__DIR__) . '/_lib/file/img/empresa/' . ltrim($logoFile, '/');
    if (!is_file($logoPath) || filesize($logoPath) <= 0) return '';
    return $logoPath;
}

try {
    if ($id_pedido <= 0) {
        throw new Exception('ID de pedido requerido');
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
        SELECT p.*, c.nombre AS proveedor_nombre, c.numero AS proveedor_ruc, c.telefono AS proveedor_telefono
        FROM {$dbName}.pedidos p
        LEFT JOIN {$dbName}.clientes c ON c.id = p.id_cliente
        WHERE p.id_pedido = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id_pedido]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$pedido) {
        throw new Exception('Pedido no encontrado');
    }

    $stmtItems = $pdo->prepare("
        SELECT ip.*, tp.cve_producto AS codigo, tp.desproducto AS descripcion
        FROM {$dbName}.item_pedidos ip
        LEFT JOIN {$dbName}.tblproductos tp ON tp.idproducto = ip.id_producto
        WHERE ip.id_pedido = :id
        ORDER BY ip.id_item ASC
    ");
    $stmtItems->execute([':id' => $id_pedido]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $empresaNombre = trim((string)($empresa['empresa'] ?? 'EMPRESA'));
    $empresaRuc = trim((string)($empresa['ruc'] ?? ''));
    $empresaDv = trim((string)($empresa['dv'] ?? ''));
    $empresaDireccion = trim((string)($empresa['direccion'] ?? ''));
    $empresaTelefono = trim((string)($empresa['telefono'] ?? ''));
    $empresaEmail = trim((string)($empresa['email'] ?? ''));
    $empresaLogo = pedidoCompanyLogoDataUri($empresa);
    $empresaLogoPath = pedidoCompanyLogoPath($empresa);
    $proveedor = trim((string)($pedido['proveedor_nombre'] ?? 'Proveedor pendiente'));
    $proveedorRuc = trim((string)($pedido['proveedor_ruc'] ?? '-'));
    $nroPedido = 'PED-' . str_pad((string)$id_pedido, 7, '0', STR_PAD_LEFT);

    if (!$forcePdf && !$inModal && !$autoPrint) {
        header('Content-Type: application/json; charset=utf-8');
        $width = 48;
        $ESC = "\x1B";
        $GS = "\x1D";
        $LF = "\x0A";
        $o = '';
        $o .= $ESC . "@";
        $o .= $ESC . "t\x10";
        $o .= $GS . "!\x00";
        if ($empresaLogoPath !== '') {
            smxEscposAppendLogo($o, $empresa, (int)$width, $LF);
        }
        $o .= $ESC . "a\x01";
        $o .= $ESC . "E\x01";
        $o .= strtoupper(pedidoEscClean($empresaNombre, $width)) . $LF;
        $o .= $ESC . "E\x00";
        $o .= "PEDIDO A PROVEEDOR" . $LF;
        $o .= str_repeat('=', $width) . $LF;
        $o .= $ESC . "a\x00";
        $o .= pedidoPad('NRO', $nroPedido, $width) . $LF;
        $o .= pedidoPad('FECHA', pedidoFormatDate($pedido['fecha'] ?? ''), $width) . $LF;
        $o .= pedidoPad('PROVEEDOR', pedidoEscClean($proveedor, $width - 11), $width) . $LF;
        $o .= pedidoPad('RUC/CI', pedidoEscClean($proveedorRuc, $width - 8), $width) . $LF;
        $o .= str_repeat('-', $width) . $LF;
        foreach ($items as $item) {
            $desc = pedidoEscClean((string)($item['descripcion'] ?? ('Producto #' . (int)($item['id_producto'] ?? 0))));
            $cant = number_format((float)($item['cantidad'] ?? 0), 2, ',', '');
            $precio = pedidoFormatMoney($item['precio'] ?? 0);
            $total = pedidoFormatMoney($item['importe'] ?? 0);
            $o .= substr($desc, 0, $width) . $LF;
            $o .= pedidoPad($cant . ' x ' . $precio, $total, $width) . $LF;
        }
        $o .= str_repeat('-', $width) . $LF;
        $o .= $ESC . "E\x01";
        $o .= pedidoPad('TOTAL', pedidoFormatMoney($pedido['importe'] ?? 0) . ' Gs', $width) . $LF;
        $o .= $ESC . "E\x00";
        $o .= str_repeat('=', $width) . $LF;
        $o .= $ESC . "a\x01";
        $o .= "Documento interno de pedido" . $LF;
        $o .= $LF . $LF . $LF;
        $o .= $GS . "V\x00";

        echo json_encode([
            'success' => true,
            'data' => base64_encode($o),
            'format' => 'escpos',
            'tipo' => 'pedido',
            'items_count' => count($items),
        ]);
        exit;
    }
    $isA5 = $paper === 'a5';
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Pedido <?= htmlspecialchars($nroPedido, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        @page { size: <?= $isA5 ? 'A5 portrait' : 'A4 portrait' ?>; margin: <?= $isA5 ? '10mm' : '12mm' ?>; }
        body { font-family: Arial, sans-serif; background: #fff; color: #111; margin: 0; }
        .sheet { width: min(<?= $isA5 ? '620px' : '900px' ?>, 100%); margin: 0 auto; padding: <?= $isA5 ? '18px' : '24px' ?>; }
        .header { display:flex; justify-content:space-between; gap:<?= $isA5 ? '16px' : '24px' ?>; border-bottom:2px solid #111; padding-bottom:12px; align-items:flex-start; }
        .brand { display:flex; gap:16px; align-items:flex-start; }
        .logo-wrap { width:<?= $isA5 ? '78px' : '92px' ?>; min-width:<?= $isA5 ? '78px' : '92px' ?>; text-align:center; }
        .logo { max-width:<?= $isA5 ? '78px' : '92px' ?>; max-height:<?= $isA5 ? '78px' : '92px' ?>; object-fit:contain; }
        .title { font-size:<?= $isA5 ? '22px' : '28px' ?>; font-weight:800; margin:0 0 6px; }
        .muted { color:#555; font-size:<?= $isA5 ? '12px' : '13px' ?>; }
        .company-meta { margin-top:8px; font-size:<?= $isA5 ? '11px' : '12px' ?>; line-height:1.55; color:#444; }
        .meta { margin-top:18px; display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px 24px; font-size:<?= $isA5 ? '12px' : '14px' ?>; }
        .meta div strong { display:inline-block; min-width:90px; }
        table { width:100%; border-collapse:collapse; margin-top:22px; font-size:<?= $isA5 ? '12px' : '14px' ?>; }
        th, td { padding:<?= $isA5 ? '7px 4px' : '10px 8px' ?>; border-bottom:1px solid #ddd; vertical-align:top; }
        th { text-align:left; background:#f5f5f5; font-size:<?= $isA5 ? '11px' : '12px' ?>; text-transform:uppercase; letter-spacing:.06em; }
        td.num, th.num { text-align:right; }
        .total { margin-top:18px; text-align:right; font-size:<?= $isA5 ? '22px' : '28px' ?>; font-weight:800; }
        .footer { margin-top:28px; font-size:12px; color:#555; }
        @media print {
        .sheet { width:auto; padding:<?= $isA5 ? '8px' : '12px' ?>; }
        }
    </style>
</head>
<body<?= $autoPrint ? ' onload="window.print()"' : '' ?>>
    <div class="sheet">
        <div class="header">
            <div class="brand">
                <?php if ($empresaLogo !== ''): ?>
                    <div class="logo-wrap">
                        <img src="<?= htmlspecialchars($empresaLogo, ENT_QUOTES, 'UTF-8') ?>" alt="Logo empresa" class="logo">
                    </div>
                <?php endif; ?>
                <div>
                    <h1 class="title">Pedido a Proveedor</h1>
                    <div class="muted"><?= htmlspecialchars($empresaNombre, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="company-meta">
                        <?php if ($empresaRuc !== ''): ?>
                            <div><strong>RUC:</strong> <?= htmlspecialchars($empresaRuc . ($empresaDv !== '' ? '-' . $empresaDv : ''), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if ($empresaDireccion !== ''): ?>
                            <div><?= htmlspecialchars($empresaDireccion, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if ($empresaTelefono !== ''): ?>
                            <div><strong>Tel:</strong> <?= htmlspecialchars($empresaTelefono, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if ($empresaEmail !== ''): ?>
                            <div><strong>Email:</strong> <?= htmlspecialchars($empresaEmail, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="muted" style="text-align:right;">
                <div><strong>Nro:</strong> <?= htmlspecialchars($nroPedido, ENT_QUOTES, 'UTF-8') ?></div>
                <div><strong>Fecha:</strong> <?= htmlspecialchars(pedidoFormatDate($pedido['fecha'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

        <div class="meta">
            <div><strong>Proveedor:</strong> <?= htmlspecialchars($proveedor, ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong>RUC/CI:</strong> <?= htmlspecialchars($proveedorRuc, ENT_QUOTES, 'UTF-8') ?></div>
            <div><strong>ID Pedido:</strong> <?= (int)$id_pedido ?></div>
            <div><strong>Importe:</strong> <?= htmlspecialchars(pedidoFormatMoney($pedido['importe'] ?? 0), ENT_QUOTES, 'UTF-8') ?> Gs</div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Codigo</th>
                    <th>Descripcion</th>
                    <th class="num">Cantidad</th>
                    <th class="num">Precio</th>
                    <th class="num">Importe</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($item['codigo'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)($item['descripcion'] ?? ('Producto #' . (int)($item['id_producto'] ?? 0))), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="num"><?= htmlspecialchars(number_format((float)($item['cantidad'] ?? 0), 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="num"><?= htmlspecialchars(pedidoFormatMoney($item['precio'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="num"><?= htmlspecialchars(pedidoFormatMoney($item['importe'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total">Total: <?= htmlspecialchars(pedidoFormatMoney($pedido['importe'] ?? 0), ENT_QUOTES, 'UTF-8') ?> Gs</div>
        <div class="footer">Documento interno de pedido a proveedor.</div>
    </div>
</body>
</html>
<?php
} catch (Throwable $e) {
    http_response_code(400);
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
