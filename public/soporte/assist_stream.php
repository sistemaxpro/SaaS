<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
header('Location: /public/menu/menu.php', true, 302);
exit;

$idEmpresa = (int)Session::getIdEmpresa();
$allowed = in_array($idEmpresa, SISTEMAX_SUPPORT_COMPANIES, true);
$deviceId = (int)($_GET['device_id'] ?? 0);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Assist Stream</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100" <?= $allowed ? 'onload="startStreamPage()"' : '' ?>>
<main class="max-w-7xl mx-auto p-4 md:p-6 space-y-4">
    <div class="flex items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Assist Stream</h1>
            <p class="text-sm text-slate-400"><?= $deviceId > 0 ? 'Device #' . $deviceId : 'Sin device seleccionado' ?></p>
        </div>
        <a href="/public/soporte/assist_admin.php" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sm">Volver</a>
    </div>

    <?php if (!$allowed): ?>
        <div class="rounded-2xl border border-red-800 bg-red-950/40 p-5 text-red-200">Acceso reservado a soporte técnico.</div>
    <?php elseif ($deviceId <= 0): ?>
        <div class="rounded-2xl border border-amber-800 bg-amber-950/30 p-5 text-amber-200">Pasá `device_id` para abrir la vista en vivo.</div>
    <?php else: ?>
        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-4 space-y-4">
            <div class="flex items-center justify-between gap-3">
                <div class="text-sm text-slate-400">Monitoreo del último frame disponible del dispositivo</div>
                <div class="flex items-center gap-2">
                    <button onclick="setMode('mjpeg')" id="modeMjpegBtn" class="px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-sm font-semibold">MJPEG</button>
                    <button onclick="setMode('snapshot')" id="modeSnapshotBtn" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm font-semibold">Snapshots</button>
                    <button onclick="toggleRefresh()" id="toggleBtn" class="px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold">Auto-refresh ON</button>
                    <button onclick="refreshFrame()" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm">Actualizar</button>
                </div>
            </div>
            <div class="rounded-2xl border border-slate-800 bg-black min-h-[360px] flex items-center justify-center overflow-hidden">
                <img id="frameImg" alt="Assist Frame" class="w-full max-h-[80vh] object-contain" />
            </div>
            <div class="text-xs text-slate-500" id="statusText">Esperando frames...</div>
        </section>
    <?php endif; ?>
</main>
<?php if ($allowed && $deviceId > 0): ?>
<script>
let refreshEnabled = true;
let timer = null;
let mode = 'mjpeg';
const deviceId = <?= (int)$deviceId ?>;
const frameUrl = '/public/api/assist_frame.php?device_id=' + encodeURIComponent(String(deviceId));
const mjpegUrl = '/public/api/assist_mjpeg.php?device_id=' + encodeURIComponent(String(deviceId));

function refreshFrame() {
    if (mode !== 'snapshot') return;
    const img = document.getElementById('frameImg');
    const status = document.getElementById('statusText');
    const stamp = Date.now();
    img.onload = function() {
        status.textContent = 'Última actualización: ' + new Date().toLocaleTimeString();
    };
    img.onerror = function() {
        status.textContent = 'Todavía no hay frame disponible para este device.';
    };
    img.src = frameUrl + '&_ts=' + stamp;
}

function startStreamPage() {
    loadCurrentMode();
    timer = setInterval(() => {
        if (refreshEnabled && mode === 'snapshot') refreshFrame();
    }, 1200);
}

function toggleRefresh() {
    refreshEnabled = !refreshEnabled;
    document.getElementById('toggleBtn').textContent = refreshEnabled ? 'Auto-refresh ON' : 'Auto-refresh OFF';
    document.getElementById('toggleBtn').className = refreshEnabled
        ? 'px-3 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold'
        : 'px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm font-semibold';
}

function setMode(nextMode) {
    mode = nextMode === 'snapshot' ? 'snapshot' : 'mjpeg';
    loadCurrentMode();
}

function loadCurrentMode() {
    const img = document.getElementById('frameImg');
    const status = document.getElementById('statusText');
    const mjpegBtn = document.getElementById('modeMjpegBtn');
    const snapshotBtn = document.getElementById('modeSnapshotBtn');
    mjpegBtn.className = mode === 'mjpeg'
        ? 'px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-sm font-semibold'
        : 'px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm font-semibold';
    snapshotBtn.className = mode === 'snapshot'
        ? 'px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-sm font-semibold'
        : 'px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-sm font-semibold';

    if (mode === 'mjpeg') {
        status.textContent = 'Conectando stream MJPEG...';
        img.onload = function() {
            status.textContent = 'Stream MJPEG activo';
        };
        img.onerror = function() {
            status.textContent = 'MJPEG sin frames todavía, probando fallback si hace falta.';
        };
        img.src = mjpegUrl + '&_ts=' + Date.now();
        return;
    }

    status.textContent = 'Modo snapshots activo';
    refreshFrame();
}
</script>
<?php endif; ?>
</body>
</html>
