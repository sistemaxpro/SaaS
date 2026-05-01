<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../productos/config/db_config.php';

Session::requireLogin('/public/login.php');
Permission::requireAccess('app_grid_mercaderias');

function trasladoSignToken(int $idEmpresa, int $idTraslado, string $referencia, int $idSucursalDestino): string
{
    $payload = $idEmpresa . '|' . $idTraslado . '|' . $referencia . '|' . $idSucursalDestino;
    return hash_hmac('sha256', $payload, MASTER_DB . '|inventario-traslado-qr-v1');
}

$idEmpresa = (int)($_GET['id_empresa'] ?? Session::get('id_empresa', 169));
$idTraslado = (int)($_GET['id'] ?? 0);
$autoPrint = (int)($_GET['autoprint'] ?? 0) === 1;

if ($idTraslado <= 0) {
    http_response_code(400);
    echo 'Traslado requerido';
    exit;
}

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

if (!$traslado) {
    http_response_code(404);
    echo 'Traslado no encontrado';
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$receiveToken = trasladoSignToken($idEmpresa, $idTraslado, (string)$traslado['referencia'], (int)$traslado['id_sucursal_destino']);
$receiveUrl = $scheme . '://' . $host . '/public/inventario/traslado_receive.php?id=' . $idTraslado . '&id_empresa=' . $idEmpresa . '&token=' . urlencode($receiveToken);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=' . rawurlencode($receiveUrl);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etiqueta de Traslado</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: #e5e7eb; }
        .label-sheet {
            width: 100mm;
            min-height: 70mm;
        }
        @page {
            size: 100mm 70mm;
            margin: 0;
        }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .label-sheet {
                width: 100mm;
                min-height: 70mm;
                margin: 0;
                box-shadow: none !important;
                border: 0 !important;
                border-radius: 0 !important;
            }
        }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center p-4">
    <div class="no-print mb-4 flex gap-2">
        <button onclick="window.print()" class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold">Imprimir etiqueta</button>
        <a href="/public/inventario/traslado_print.php?id=<?= (int)$idTraslado ?>&id_empresa=<?= (int)$idEmpresa ?>" class="px-4 py-2 rounded-xl bg-white border border-slate-300 font-bold">Comprobante</a>
    </div>

    <main class="label-sheet bg-white rounded-[18px] shadow-2xl border border-slate-300 overflow-hidden">
        <div class="bg-slate-900 text-white px-4 py-2.5 flex items-center justify-between">
            <div>
                <div class="text-[10px] tracking-[0.25em] uppercase font-black text-slate-300">Traslado</div>
                <div class="text-lg font-black"><?= htmlspecialchars((string)$traslado['referencia']) ?></div>
            </div>
            <div class="text-right">
                <div class="text-[10px] tracking-[0.18em] uppercase text-slate-300">Cantidad</div>
                <div class="text-xl font-black"><?= number_format((float)$traslado['cantidad'], 2, ',', '.') ?></div>
            </div>
        </div>

        <div class="grid grid-cols-[1fr_110px] gap-3 p-4 items-start">
            <div class="min-w-0">
                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-400 font-black">Producto</div>
                <div class="mt-1 text-base leading-tight font-black break-words"><?= htmlspecialchars((string)$traslado['desproducto']) ?></div>
                <div class="mt-1 text-sm text-slate-600 font-bold"><?= htmlspecialchars((string)$traslado['cve_producto']) ?></div>

                <div class="mt-3 space-y-1.5">
                    <div class="text-[10px] uppercase tracking-[0.18em] text-slate-400 font-black">Origen</div>
                    <div class="text-sm font-bold"><?= htmlspecialchars((string)$traslado['sucursal_origen']) ?></div>
                    <div class="text-[10px] uppercase tracking-[0.18em] text-slate-400 font-black pt-1">Destino</div>
                    <div class="text-sm font-bold"><?= htmlspecialchars((string)$traslado['sucursal_destino']) ?></div>
                </div>
            </div>

            <div class="flex flex-col items-center">
                <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR recepción" class="w-[100px] h-[100px] border border-slate-200 rounded-xl bg-white p-1.5">
                <div class="mt-2 text-[9px] text-center font-bold text-slate-500 leading-tight">Escanear para confirmar llegada</div>
            </div>
        </div>
    </main>

    <?php if ($autoPrint): ?>
    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 250));
    </script>
    <?php endif; ?>
</body>
</html>
