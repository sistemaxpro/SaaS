<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/Support/whatsapp_queue.php';

Session::requireLogin('/public/login.php');
$idEmpresa = (int)Session::getIdEmpresa();
if ($idEmpresa !== 169) {
    http_response_code(403);
    echo 'Sin permisos';
    exit;
}

$pdo = Database::getMasterConnection();
sx_ensure_whatsapp_outbox_table($pdo);

$runNow = isset($_GET['run']) && $_GET['run'] === '1';
$runStats = null;
if ($runNow) {
    $runStats = sx_process_whatsapp_outbox($pdo, 50);
}

$totals = [
    'pendiente' => 0,
    'enviando' => 0,
    'enviado' => 0,
    'error' => 0,
];
$stmtTotals = $pdo->query("
    SELECT estado, COUNT(*) AS c
    FROM saas_notificaciones_outbox
    WHERE canal = 'whatsapp'
    GROUP BY estado
");
foreach (($stmtTotals ? $stmtTotals->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
    $k = (string)($r['estado'] ?? '');
    if (array_key_exists($k, $totals)) {
        $totals[$k] = (int)($r['c'] ?? 0);
    }
}

$oldestPendingMins = null;
$stmtOldest = $pdo->query("
    SELECT TIMESTAMPDIFF(MINUTE, MIN(created_at), NOW()) AS mins
    FROM saas_notificaciones_outbox
    WHERE canal = 'whatsapp' AND estado IN ('pendiente','error')
");
if ($stmtOldest) {
    $v = $stmtOldest->fetchColumn();
    if ($v !== false && $v !== null) $oldestPendingMins = (int)$v;
}

$lastRows = [];
$stmtRows = $pdo->query("
    SELECT id_outbox, tipo, id_solicitud, destino, estado, intentos, max_intentos, proveedor, ultimo_error, created_at, sent_at, next_retry_at
    FROM saas_notificaciones_outbox
    WHERE canal = 'whatsapp'
    ORDER BY id_outbox DESC
    LIMIT 80
");
if ($stmtRows) {
    $lastRows = $stmtRows->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$format = strtolower(trim((string)($_GET['format'] ?? 'html')));
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'run' => $runStats,
        'totals' => $totals,
        'oldest_pending_minutes' => $oldestPendingMins,
        'rows' => $lastRows,
        'generated_at' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health WhatsApp Outbox - SistemaX</title>
    <link rel="stylesheet" href="/public/assets/tailwind.css">
</head>
<body class="bg-slate-100 text-slate-900">
    <div class="max-w-7xl mx-auto p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div>
                <h1 class="text-xl font-bold">Health Check WhatsApp Outbox</h1>
                <p class="text-sm text-slate-500">Empresa 169 · <?= h(date('Y-m-d H:i:s')) ?></p>
            </div>
            <div class="flex items-center gap-2">
                <a href="?run=1" class="px-3 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold">Procesar Ahora</a>
                <a href="?format=json" class="px-3 py-2 rounded-lg bg-slate-800 text-white text-sm font-semibold">Ver JSON</a>
            </div>
        </div>

        <?php if (is_array($runStats)): ?>
            <div class="mb-4 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                Proceso manual ejecutado: picked=<?= h($runStats['picked'] ?? 0) ?>, sent=<?= h($runStats['sent'] ?? 0) ?>, failed=<?= h($runStats['failed'] ?? 0) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-5">
            <div class="p-3 rounded-xl bg-white border border-slate-200"><p class="text-xs text-slate-500">Pendiente</p><p class="text-2xl font-bold"><?= h($totals['pendiente']) ?></p></div>
            <div class="p-3 rounded-xl bg-white border border-slate-200"><p class="text-xs text-slate-500">Enviando</p><p class="text-2xl font-bold"><?= h($totals['enviando']) ?></p></div>
            <div class="p-3 rounded-xl bg-white border border-slate-200"><p class="text-xs text-slate-500">Enviado</p><p class="text-2xl font-bold text-emerald-600"><?= h($totals['enviado']) ?></p></div>
            <div class="p-3 rounded-xl bg-white border border-slate-200"><p class="text-xs text-slate-500">Error</p><p class="text-2xl font-bold text-rose-600"><?= h($totals['error']) ?></p></div>
            <div class="p-3 rounded-xl bg-white border border-slate-200"><p class="text-xs text-slate-500">Antig. pend. (min)</p><p class="text-2xl font-bold"><?= h($oldestPendingMins ?? 0) ?></p></div>
        </div>

        <div class="bg-white border border-slate-200 rounded-xl overflow-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-slate-50 text-slate-600 uppercase tracking-wide">
                    <tr>
                        <th class="px-3 py-2 text-left">ID</th>
                        <th class="px-3 py-2 text-left">Tipo</th>
                        <th class="px-3 py-2 text-left">Solicitud</th>
                        <th class="px-3 py-2 text-left">Destino</th>
                        <th class="px-3 py-2 text-left">Estado</th>
                        <th class="px-3 py-2 text-left">Intentos</th>
                        <th class="px-3 py-2 text-left">Proveedor</th>
                        <th class="px-3 py-2 text-left">Creado</th>
                        <th class="px-3 py-2 text-left">Retry</th>
                        <th class="px-3 py-2 text-left">Enviado</th>
                        <th class="px-3 py-2 text-left">Error</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($lastRows as $r): ?>
                        <?php
                            $st = (string)($r['estado'] ?? '');
                            $stClass = 'text-slate-700';
                            if ($st === 'enviado') $stClass = 'text-emerald-600';
                            if ($st === 'error') $stClass = 'text-rose-600';
                            if ($st === 'pendiente') $stClass = 'text-amber-600';
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-3 py-2 font-mono"><?= h($r['id_outbox']) ?></td>
                            <td class="px-3 py-2"><?= h($r['tipo']) ?></td>
                            <td class="px-3 py-2"><?= h($r['id_solicitud']) ?></td>
                            <td class="px-3 py-2 font-mono"><?= h($r['destino']) ?></td>
                            <td class="px-3 py-2 font-semibold <?= h($stClass) ?>"><?= h($st) ?></td>
                            <td class="px-3 py-2"><?= h($r['intentos']) ?>/<?= h($r['max_intentos']) ?></td>
                            <td class="px-3 py-2"><?= h($r['proveedor']) ?></td>
                            <td class="px-3 py-2"><?= h($r['created_at']) ?></td>
                            <td class="px-3 py-2"><?= h($r['next_retry_at']) ?></td>
                            <td class="px-3 py-2"><?= h($r['sent_at']) ?></td>
                            <td class="px-3 py-2 max-w-sm truncate" title="<?= h($r['ultimo_error']) ?>"><?= h($r['ultimo_error']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

