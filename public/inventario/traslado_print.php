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
$masterPdo = $conn['masterPdo'] ?? null;
$masterDb = (string)($conn['masterDb'] ?? '');

$stmt = $pdo->prepare("
    SELECT
        t.*,
        COALESCE(p.cve_producto, '') AS cve_producto,
        COALESCE(p.desproducto, '') AS desproducto,
        COALESCE(p.foto_url, '') AS foto_url,
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

$usuarios = [];
if ($masterPdo && $masterDb !== '') {
    $ids = array_values(array_filter([
        (int)($traslado['id_login_salida'] ?? 0),
        (int)($traslado['id_login_recepcion'] ?? 0),
    ]));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $uStmt = $masterPdo->prepare("
            SELECT id_login, COALESCE(NULLIF(name, ''), NULLIF(login, ''), CONCAT('Usuario ', id_login)) AS nombre
            FROM {$masterDb}.sec_users
            WHERE id_login IN ({$placeholders})
        ");
        $uStmt->execute($ids);
        foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $usuarios[(int)$row['id_login']] = (string)($row['nombre'] ?? '');
        }
    }
}

$operadorSalida = $usuarios[(int)($traslado['id_login_salida'] ?? 0)] ?? ('Usuario ' . (int)($traslado['id_login_salida'] ?? 0));
$operadorRecepcion = (int)($traslado['id_login_recepcion'] ?? 0) > 0
    ? ($usuarios[(int)$traslado['id_login_recepcion']] ?? ('Usuario ' . (int)$traslado['id_login_recepcion']))
    : '';

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
    <title>Comprobante de Traslado</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            .sheet { box-shadow: none !important; margin: 0 !important; width: 100% !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900">
    <div class="no-print max-w-4xl mx-auto pt-6 px-4">
        <button onclick="window.print()" class="px-4 py-2 rounded-xl bg-slate-900 text-white font-bold">Imprimir</button>
    </div>

    <main class="sheet max-w-4xl mx-auto my-6 bg-white rounded-[2rem] shadow-2xl border border-slate-200 overflow-hidden">
        <header class="px-8 py-6 bg-gradient-to-r from-amber-500 to-orange-500 text-white">
            <p class="text-xs uppercase tracking-[0.3em] font-black">Inventario</p>
            <h1 class="text-3xl font-black mt-2">Comprobante de Traslado</h1>
            <p class="text-sm mt-2">Referencia: <?= htmlspecialchars((string)$traslado['referencia']) ?></p>
        </header>

        <section class="px-8 py-6 grid grid-cols-12 gap-6">
            <div class="col-span-12 md:col-span-8">
                <div class="flex items-start gap-4">
                    <div class="w-24 h-24 rounded-3xl bg-slate-100 border border-slate-200 overflow-hidden flex items-center justify-center">
                        <?php if (!empty($traslado['foto_url'])): ?>
                            <img src="<?= htmlspecialchars((string)$traslado['foto_url']) ?>" class="w-full h-full object-cover" alt="">
                        <?php else: ?>
                            <div class="text-4xl">📦</div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-[0.25em] text-slate-400 font-black">Producto</p>
                        <h2 class="text-2xl font-black mt-1"><?= htmlspecialchars((string)$traslado['desproducto']) ?></h2>
                        <p class="text-sm text-slate-500 mt-1"><?= htmlspecialchars((string)$traslado['cve_producto']) ?></p>
                    </div>
                </div>
            </div>
            <div class="col-span-12 md:col-span-4">
                <div class="rounded-3xl bg-slate-900 text-white p-5">
                    <p class="text-xs uppercase tracking-[0.25em] text-slate-400 font-black">Cantidad</p>
                    <p class="text-4xl font-black mt-2"><?= number_format((float)$traslado['cantidad'], 2, ',', '.') ?></p>
                    <p class="text-sm mt-3">Estado: <strong><?= htmlspecialchars((string)$traslado['estado']) ?></strong></p>
                </div>
            </div>
        </section>

        <section class="px-8 pb-8 grid grid-cols-12 gap-6">
            <div class="col-span-12 md:col-span-6 rounded-3xl border border-slate-200 p-5">
                <p class="text-xs uppercase tracking-[0.25em] text-slate-400 font-black">Salida</p>
                <p class="text-xl font-black mt-2"><?= htmlspecialchars((string)$traslado['sucursal_origen']) ?></p>
                <p class="text-sm text-slate-500 mt-3">Operador: <?= htmlspecialchars($operadorSalida) ?></p>
                <p class="text-sm text-slate-500 mt-1">Fecha: <?= htmlspecialchars((string)$traslado['fecha_salida']) ?></p>
            </div>
            <div class="col-span-12 md:col-span-6 rounded-3xl border border-slate-200 p-5">
                <p class="text-xs uppercase tracking-[0.25em] text-slate-400 font-black">Recepción</p>
                <p class="text-xl font-black mt-2"><?= htmlspecialchars((string)$traslado['sucursal_destino']) ?></p>
                <p class="text-sm text-slate-500 mt-3">Operador: <?= htmlspecialchars($operadorRecepcion !== '' ? $operadorRecepcion : 'Pendiente') ?></p>
                <p class="text-sm text-slate-500 mt-1">Fecha: <?= htmlspecialchars((string)($traslado['fecha_recepcion'] ?: 'Pendiente')) ?></p>
            </div>
            <div class="col-span-12 rounded-3xl border border-slate-200 p-5">
                <p class="text-xs uppercase tracking-[0.25em] text-slate-400 font-black">Observación</p>
                <p class="text-sm mt-3 whitespace-pre-wrap"><?= htmlspecialchars((string)($traslado['obs'] ?: 'Sin observación')) ?></p>
            </div>
            <div class="col-span-12 rounded-3xl border border-slate-200 p-5">
                <div class="grid grid-cols-1 md:grid-cols-[220px_1fr] gap-6 items-center">
                    <div class="flex justify-center">
                        <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR recepción traslado" class="w-[220px] h-[220px] border border-slate-200 rounded-2xl bg-white p-3">
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-[0.25em] text-slate-400 font-black">QR de recepción</p>
                        <p class="text-sm mt-3">Escaneá este código en la sucursal destino para abrir directamente la confirmación de llegada del producto.</p>
                        <p class="text-xs text-slate-500 mt-4 break-all"><?= htmlspecialchars($receiveUrl) ?></p>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <?php if ($autoPrint): ?>
    <script>
        window.addEventListener('load', () => setTimeout(() => window.print(), 350));
    </script>
    <?php endif; ?>
</body>
</html>
