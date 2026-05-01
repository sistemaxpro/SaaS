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
$token = trim((string)($_GET['token'] ?? ''));
$idSucursalSesion = (int)Session::get('id_sucursal', 0);

if ($idTraslado <= 0 || $token === '') {
    http_response_code(400);
    echo 'Datos de traslado incompletos';
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

$expectedToken = trasladoSignToken($idEmpresa, $idTraslado, (string)$traslado['referencia'], (int)$traslado['id_sucursal_destino']);
if (!hash_equals($expectedToken, $token)) {
    http_response_code(403);
    echo 'Token de traslado inválido';
    exit;
}

$empresaNombre = 'SistemaX';
if ($masterPdo && $masterDb !== '') {
    $stEmp = $masterPdo->prepare("SELECT empresa FROM {$masterDb}.empresa WHERE id_empresa = :id LIMIT 1");
    $stEmp->execute([':id' => $idEmpresa]);
    $empresaNombre = (string)($stEmp->fetchColumn() ?: 'SistemaX');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recepción de Traslado</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900" x-data="trasladoReceiveApp()">
<div class="max-w-2xl mx-auto px-4 py-6">
    <div class="rounded-[2rem] overflow-hidden bg-white shadow-2xl border border-slate-200">
        <div class="px-6 py-5 bg-gradient-to-r from-emerald-600 to-teal-700 text-white">
            <p class="text-xs uppercase tracking-[0.28em] font-black">Inventario</p>
            <h1 class="text-2xl font-black mt-2">Confirmación de llegada</h1>
            <p class="text-sm mt-2"><?= htmlspecialchars($empresaNombre) ?></p>
        </div>

        <div class="p-6 space-y-5">
            <div class="flex items-start gap-4">
                <div class="w-20 h-20 rounded-3xl bg-slate-100 border border-slate-200 overflow-hidden flex items-center justify-center flex-shrink-0">
                    <?php if (!empty($traslado['foto_url'])): ?>
                        <img src="<?= htmlspecialchars((string)$traslado['foto_url']) ?>" class="w-full h-full object-cover" alt="">
                    <?php else: ?>
                        <div class="text-3xl">📦</div>
                    <?php endif; ?>
                </div>
                <div class="min-w-0">
                    <p class="text-xs uppercase tracking-[0.22em] text-slate-400 font-black">Producto</p>
                    <h2 class="text-xl font-black mt-1"><?= htmlspecialchars((string)$traslado['desproducto']) ?></h2>
                    <p class="text-sm text-slate-500 mt-1"><?= htmlspecialchars((string)$traslado['cve_producto']) ?></p>
                    <p class="text-sm text-sky-700 font-bold mt-2">Cantidad: <?= number_format((float)$traslado['cantidad'], 2, ',', '.') ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="rounded-3xl border border-slate-200 p-4">
                    <p class="text-xs uppercase tracking-[0.22em] text-slate-400 font-black">Origen</p>
                    <p class="text-lg font-black mt-2"><?= htmlspecialchars((string)$traslado['sucursal_origen']) ?></p>
                </div>
                <div class="rounded-3xl border border-slate-200 p-4">
                    <p class="text-xs uppercase tracking-[0.22em] text-slate-400 font-black">Destino</p>
                    <p class="text-lg font-black mt-2"><?= htmlspecialchars((string)$traslado['sucursal_destino']) ?></p>
                </div>
            </div>

            <div class="rounded-3xl border p-4"
                 :class="canReceiveHere ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'">
                <p class="text-sm font-semibold" x-text="canReceiveHere ? 'La sucursal actual coincide con el destino del traslado.' : 'La sesión actual no está en la sucursal destino; podés revisar, pero no confirmar.'"></p>
            </div>

            <div class="rounded-3xl border border-slate-200 p-4">
                <p class="text-xs uppercase tracking-[0.22em] text-slate-400 font-black">Observación</p>
                <p class="text-sm mt-2 whitespace-pre-wrap"><?= htmlspecialchars((string)($traslado['obs'] ?: 'Sin observación')) ?></p>
            </div>

            <div class="flex items-center gap-3">
                <button @click="confirmar()" :disabled="saving || !canReceiveHere || recibido"
                        class="flex-1 rounded-2xl bg-emerald-600 text-white py-3.5 font-black disabled:opacity-50">
                    <span x-show="!saving && !recibido">Confirmar llegada</span>
                    <span x-show="saving">Procesando...</span>
                    <span x-show="recibido">Traslado recibido</span>
                </button>
                <a href="/public/inventario/index.php" class="px-5 py-3.5 rounded-2xl bg-slate-900 text-white font-black">Inventario</a>
            </div>

            <p x-show="message" class="text-sm font-semibold" :class="ok ? 'text-emerald-700' : 'text-rose-600'" x-text="message"></p>
        </div>
    </div>
</div>

<script>
function trasladoReceiveApp() {
    return {
        idEmpresa: <?= (int)$idEmpresa ?>,
        idTraslado: <?= (int)$idTraslado ?>,
        idSucursalSesion: <?= (int)$idSucursalSesion ?>,
        idSucursalDestino: <?= (int)($traslado['id_sucursal_destino'] ?? 0) ?>,
        recibido: <?= strtoupper((string)($traslado['estado'] ?? '')) === 'RECIBIDO' ? 'true' : 'false' ?>,
        saving: false,
        message: '',
        ok: false,
        get canReceiveHere() {
            return Number(this.idSucursalSesion || 0) === Number(this.idSucursalDestino || 0);
        },
        async confirmar() {
            if (this.saving || this.recibido || !this.canReceiveHere) return;
            this.saving = true;
            this.message = '';
            try {
                const res = await fetch('/public/productos/api/stock.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'recibir_traslado',
                        id_traslado: this.idTraslado,
                        id_sucursal_destino: this.idSucursalDestino
                    })
                });
                const data = await res.json();
                if (!res.ok || data.success === false) throw new Error(data.error || data.message || 'No se pudo recibir el traslado');
                this.ok = true;
                this.recibido = true;
                this.message = data.message || 'Traslado recibido correctamente';
            } catch (e) {
                this.ok = false;
                this.message = e.message || 'No se pudo recibir el traslado';
            } finally {
                this.saving = false;
            }
        }
    };
}
</script>
</body>
</html>
