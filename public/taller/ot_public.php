<?php
define('TALLER_SKIP_PERMISSION_CHECK', true);
require_once __DIR__ . '/api/common.php';
header('Content-Type: text/html; charset=UTF-8');

$idEmpresa = (int)($_GET['id_empresa'] ?? 0);
$token = trim((string)($_GET['token'] ?? ''));
$orden = null;
$error = '';

try {
    if ($idEmpresa <= 0 || $token === '') {
        throw new Exception('Enlace inválido');
    }
    $conn = tallerConn($idEmpresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $masterDb = $conn['masterDb'] ?? MASTER_DB;
    $useContactos = tallerHasTable($pdo, $db, 'clientes')
        && tallerHasColumn($pdo, $db, 'clientes', 'id')
        && tallerHasColumn($pdo, $db, 'clientes', 'nombre');
    $joinClientes = $useContactos
        ? "LEFT JOIN {$db}.clientes c ON c.id = o.id_cliente"
        : "LEFT JOIN {$db}.taller_clientes c ON c.id_cliente = o.id_cliente";
    $joinMecanico = "LEFT JOIN {$masterDb}.sec_users mu ON mu.id_login = o.id_mecanico";
    $stmt = $pdo->prepare("SELECT o.*, c.nombre AS cliente, c.telefono AS telefono_cliente,
                                  COALESCE(NULLIF(TRIM(mu.name), ''), NULLIF(TRIM(mu.login), ''), '') AS mecanico,
                                  v.marca, v.modelo, v.chapa, v.color, v.anio, v.km_actual
                           FROM {$db}.taller_ordenes o
                           {$joinClientes}
                           {$joinMecanico}
                           LEFT JOIN {$db}.taller_vehiculos v ON v.id_vehiculo = o.id_vehiculo
                           WHERE o.public_token = :token AND o.is_public = 1
                           LIMIT 1");
    $stmt->execute([':token' => $token]);
    $orden = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$orden) {
        throw new Exception('La OT no está disponible.');
    }
    $it = $pdo->prepare("SELECT i.*, s.servicio
                         FROM {$db}.taller_orden_items i
                         LEFT JOIN {$db}.taller_servicios s ON s.id_servicio = i.id_servicio
                         WHERE i.id_orden = :id
                         ORDER BY i.id_item ASC");
    $it->execute([':id' => (int)$orden['id_orden']]);
    $orden['items'] = $it->fetchAll(PDO::FETCH_ASSOC);
    $fotosStmt = $pdo->prepare("
        SELECT id_foto, ruta, storage, file_id, url, titulo, created_at
        FROM {$db}.taller_vehiculo_fotos
        WHERE id_vehiculo = :id
        ORDER BY id_foto DESC
    ");
    $fotosStmt->execute([':id' => (int)($orden['id_vehiculo'] ?? 0)]);
    $orden['fotos'] = [];
    foreach (($fotosStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $foto) {
        $storage = strtolower(trim((string)($foto['storage'] ?? 'local')));
        $url = trim((string)($foto['url'] ?? ''));
        $ruta = trim((string)($foto['ruta'] ?? ''));
        if ($storage === 'r2' && $url !== '') {
            $foto['url'] = $url;
        } elseif ($url !== '' && preg_match('~^https?://~i', $url)) {
            $foto['url'] = $url;
        } elseif ($ruta !== '') {
            $foto['url'] = "/public/_lib/file/img/taller_vehiculos/e{$idEmpresa}/v" . (int)($orden['id_vehiculo'] ?? 0) . '/' . rawurlencode($ruta);
        } else {
            $foto['url'] = '';
        }
        if ($foto['url'] !== '') {
            $orden['fotos'][] = $foto;
        }
    }
    $chatStmt = $pdo->prepare("
        SELECT id_chat, sender_type, sender_name, mensaje, created_at
        FROM {$db}.taller_ot_chat
        WHERE id_orden = :id
        ORDER BY id_chat ASC
        LIMIT 300
    ");
    $chatStmt->execute([':id' => (int)$orden['id_orden']]);
    $orden['chat'] = $chatStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OT Pública</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
    <main class="w-full px-0 md:px-4 xl:px-6 py-0 md:py-6">
        <?php if ($error !== ''): ?>
            <div class="mx-auto max-w-3xl rounded-none md:rounded-2xl border border-red-800 bg-red-950/40 p-6">
                <h1 class="text-xl font-bold">OT no disponible</h1>
                <p class="mt-2 text-red-200"><?= htmlspecialchars($error) ?></p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,3fr)_minmax(280px,1fr)] gap-0 xl:gap-6 items-start">
                <div class="w-full min-h-screen md:min-h-0 rounded-none md:rounded-3xl border-0 md:border border-slate-800 bg-slate-900/95 shadow-none md:shadow-2xl overflow-hidden">
                    <div class="px-4 md:px-6 py-5 border-b border-slate-800 bg-slate-900">
                        <p class="text-xs uppercase tracking-[0.3em] text-orange-400">Orden de Trabajo</p>
                        <h1 class="text-2xl font-bold mt-1"><?= htmlspecialchars((string)($orden['nro_ot'] ?? '')) ?></h1>
                        <p class="text-sm text-slate-400 mt-1"><?= htmlspecialchars((string)($orden['cliente'] ?? 'Cliente')) ?></p>
                    </div>
                    <div class="p-4 md:p-6 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 border-b border-slate-800">
                    <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-4">
                        <p class="text-xs text-slate-400">Fecha</p>
                        <p class="text-lg font-semibold"><?= htmlspecialchars((string)($orden['fecha'] ?? '')) ?></p>
                    </div>
                    <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-4">
                        <p class="text-xs text-slate-400">Término estimado</p>
                        <p class="text-lg font-semibold"><?= htmlspecialchars((string)($orden['fecha_entrega_estimada'] ?? '-')) ?></p>
                    </div>
                    <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-4">
                        <p class="text-xs text-slate-400">Estado</p>
                        <p class="text-lg font-semibold capitalize"><?= htmlspecialchars((string)($orden['estado'] ?? '')) ?></p>
                    </div>
                    <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-4">
                        <p class="text-xs text-slate-400">Total estimado</p>
                        <p class="text-lg font-semibold">Gs <?= number_format((float)($orden['total'] ?? 0), 0, ',', '.') ?></p>
                    </div>
                    </div>
                    <div class="p-4 md:p-6 grid grid-cols-1 xl:grid-cols-[1.15fr_0.85fr] gap-6 border-b border-slate-800">
                        <div>
                            <h2 class="text-sm font-semibold text-slate-300 mb-2">Vehículo</h2>
                            <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-4 space-y-1">
                                <p class="font-semibold"><?= htmlspecialchars(trim(((string)($orden['marca'] ?? '')) . ' ' . ((string)($orden['modelo'] ?? '')))) ?></p>
                                <p class="text-sm text-slate-400">Chapa: <?= htmlspecialchars((string)($orden['chapa'] ?? '-')) ?></p>
                                <p class="text-sm text-slate-400">Año: <?= htmlspecialchars((string)($orden['anio'] ?? '-')) ?></p>
                                <p class="text-sm text-slate-400">Color: <?= htmlspecialchars((string)($orden['color'] ?? '-')) ?></p>
                            </div>
                            <?php if (!empty($orden['fotos'])): ?>
                                <div class="mt-4">
                                    <div class="flex items-center justify-between gap-3 mb-2">
                                        <h2 class="text-sm font-semibold text-slate-300">Galería del vehículo</h2>
                                        <span class="text-xs text-slate-500"><?= count($orden['fotos']) ?> foto(s)</span>
                                    </div>
                                    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3">
                                        <?php foreach (($orden['fotos'] ?? []) as $foto): ?>
                                            <a href="<?= htmlspecialchars((string)$foto['url']) ?>" target="_blank" rel="noopener" class="group block overflow-hidden rounded-2xl border border-slate-800 bg-slate-950/60">
                                                <img src="<?= htmlspecialchars((string)$foto['url']) ?>" alt="Foto del vehículo" class="h-28 w-full object-cover transition duration-200 group-hover:scale-[1.03]">
                                                <div class="px-3 py-2 text-xs text-slate-400">
                                                    <?= htmlspecialchars((string)($foto['titulo'] ?: 'Ver foto')) ?>
                                                </div>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <h2 class="text-sm font-semibold text-slate-300 mb-2">Problema reportado</h2>
                                <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-4 text-slate-200 whitespace-pre-wrap"><?= htmlspecialchars((string)($orden['problema'] ?? '')) ?></div>
                            </div>
                            <div>
                                <h2 class="text-sm font-semibold text-slate-300 mb-2">Diagnóstico / trabajo a realizar</h2>
                                <div class="rounded-2xl bg-slate-950/70 border border-slate-800 p-4 text-slate-200 whitespace-pre-wrap"><?= htmlspecialchars((string)($orden['diagnostico'] ?? '')) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 md:p-6">
                        <div class="flex items-center justify-between gap-3 mb-3">
                            <h2 class="text-lg font-semibold">Prefactura pendiente</h2>
                            <span class="text-sm text-slate-400"><?= count($orden['items'] ?? []) ?> ítems</span>
                        </div>
                        <div class="overflow-x-auto rounded-2xl border border-slate-800">
                            <table class="min-w-full text-sm">
                                <thead class="bg-slate-950/80 text-slate-400">
                                    <tr>
                                        <th class="text-left px-4 py-3">Descripción</th>
                                        <th class="text-right px-4 py-3">Cant.</th>
                                        <th class="text-right px-4 py-3">Precio</th>
                                        <th class="text-right px-4 py-3">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800">
                                    <?php foreach (($orden['items'] ?? []) as $item): ?>
                                        <tr class="bg-slate-900/60">
                                            <td class="px-4 py-3"><?= htmlspecialchars((string)($item['descripcion'] ?? $item['servicio'] ?? '')) ?></td>
                                            <td class="px-4 py-3 text-right"><?= number_format((float)($item['cantidad'] ?? 0), 0, ',', '.') ?></td>
                                            <td class="px-4 py-3 text-right">Gs <?= number_format((float)($item['precio'] ?? 0), 0, ',', '.') ?></td>
                                            <td class="px-4 py-3 text-right font-semibold">Gs <?= number_format((float)($item['subtotal'] ?? 0), 0, ',', '.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="p-4 md:p-6 border-t border-slate-800">
                        <div class="flex items-center justify-between gap-3 mb-3">
                            <div>
                                <h2 class="text-lg font-semibold">Chat con el mecánico</h2>
                                <p class="text-sm text-slate-400"><?= htmlspecialchars((string)($orden['mecanico'] ?? 'Taller')) ?></p>
                            </div>
                        </div>
                        <div id="ot-chat-wrap" class="space-y-3">
                            <div id="ot-chat-list" class="max-h-[40vh] overflow-y-auto space-y-2 rounded-2xl border border-slate-800 bg-slate-950/50 p-3">
                                <?php foreach (($orden['chat'] ?? []) as $msg): ?>
                                    <div class="rounded-xl px-3 py-2 border <?= (($msg['sender_type'] ?? '') === 'cliente') ? 'bg-slate-900 border-slate-700 text-slate-100' : 'bg-blue-950/30 border-blue-900/50 text-blue-100' ?>">
                                        <div class="flex items-center justify-between gap-3 mb-1">
                                            <span class="text-xs font-semibold uppercase tracking-wide"><?= htmlspecialchars((string)($msg['sender_name'] ?? '')) ?></span>
                                            <span class="text-[11px] opacity-70"><?= htmlspecialchars((string)($msg['created_at'] ?? '')) ?></span>
                                        </div>
                                        <div class="text-sm whitespace-pre-wrap"><?= htmlspecialchars((string)($msg['mensaje'] ?? '')) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="flex gap-2">
                                <textarea id="ot-chat-message" rows="2" placeholder="Escribí tu mensaje al mecánico..." class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-950/70 text-slate-100"></textarea>
                                <button id="ot-chat-send" type="button" class="px-4 py-2 rounded-xl bg-sky-600 text-white hover:bg-sky-700 shrink-0">Enviar</button>
                            </div>
                        </div>
                    </div>
                </div>
                <aside class="px-4 py-5 md:px-0 xl:py-0">
                    <div class="xl:sticky xl:top-6 rounded-none md:rounded-3xl overflow-hidden border border-slate-800 bg-[radial-gradient(circle_at_top,#0f766e_0%,#0f172a_44%,#020617_100%)] shadow-2xl">
                        <div class="p-6 md:p-7">
                            <div class="inline-flex items-center gap-2 rounded-full border border-cyan-400/30 bg-cyan-400/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.24em] text-cyan-200">
                                sistemax.pro
                            </div>
                            <h2 class="mt-5 text-2xl font-black leading-tight text-white">Probá el ERP que sigue tu taller en tiempo real.</h2>
                            <p class="mt-3 text-sm leading-6 text-slate-300">
                                Ventas, stock, OT, SIFEN, clientes, POS y app móvil en una sola plataforma. Si esta experiencia te gustó, podés probar Sistemax.Pro con tu negocio.
                            </p>
                            <div class="mt-6 grid grid-cols-2 gap-3 text-xs">
                                <div class="rounded-2xl border border-white/10 bg-white/5 p-3">
                                    <p class="text-slate-400">Módulos</p>
                                    <p class="mt-1 text-lg font-bold text-white">+20</p>
                                </div>
                                <div class="rounded-2xl border border-white/10 bg-white/5 p-3">
                                    <p class="text-slate-400">Activación</p>
                                    <p class="mt-1 text-lg font-bold text-white">On demand</p>
                                </div>
                            </div>
                            <div class="mt-6 space-y-3">
                                <a href="/public/suscribete.php?registro=1&v=20260327183227" class="block w-full rounded-2xl bg-cyan-400 px-4 py-3 text-center text-sm font-bold text-slate-950 transition hover:bg-cyan-300">Probar ahora</a>
                                <a href="/public/video_demo_sistemax_stock.html" target="_blank" rel="noopener" class="block w-full rounded-2xl border border-white/15 bg-white/5 px-4 py-3 text-center text-sm font-semibold text-white transition hover:bg-white/10">Ver video demo</a>
                                <a href="https://cliente.sistemax.pro" target="_blank" rel="noopener" class="block w-full rounded-2xl border border-white/10 px-4 py-3 text-center text-sm font-semibold text-slate-300 transition hover:border-cyan-300/40 hover:text-white">Ingresar al sistema</a>
                            </div>
                            <div class="mt-6 rounded-2xl border border-amber-300/15 bg-amber-300/10 p-4">
                                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-200">Experiencia interactiva</p>
                                <p class="mt-2 text-sm text-amber-50">
                                    El cliente ve OT, fotos, prefactura y chat en vivo. Este mismo esquema puede quedar activo en tu empresa.
                                </p>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    </main>
    <?php if ($error === '' && $orden): ?>
    <script>
        (function() {
            const token = <?= json_encode($token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const idEmpresa = <?= (int)$idEmpresa ?>;
            const listEl = document.getElementById('ot-chat-list');
            const msgEl = document.getElementById('ot-chat-message');
            const sendBtn = document.getElementById('ot-chat-send');

            function escapeHtml(value) {
                return String(value || '').replace(/[&<>"']/g, function (m) {
                    return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'})[m] || m;
                });
            }

            function renderMessages(items) {
                const rows = Array.isArray(items) ? items : [];
                if (!listEl) return;
                if (rows.length === 0) {
                    listEl.innerHTML = '<div class="rounded-xl border border-dashed border-slate-700 px-3 py-4 text-sm text-slate-400">Sin mensajes todavía.</div>';
                    return;
                }
                listEl.innerHTML = rows.map(function(msg) {
                    const fromClient = String(msg.sender_type || '') === 'cliente';
                    const cls = fromClient
                        ? 'bg-slate-900 border-slate-700 text-slate-100'
                        : 'bg-blue-950/30 border-blue-900/50 text-blue-100';
                    return '<div class="rounded-xl px-3 py-2 border ' + cls + '">'
                        + '<div class="flex items-center justify-between gap-3 mb-1">'
                        + '<span class="text-xs font-semibold uppercase tracking-wide">' + escapeHtml(msg.sender_name || '') + '</span>'
                        + '<span class="text-[11px] opacity-70">' + escapeHtml(msg.created_at || '') + '</span>'
                        + '</div>'
                        + '<div class="text-sm whitespace-pre-wrap">' + escapeHtml(msg.mensaje || '') + '</div>'
                        + '</div>';
                }).join('');
                listEl.scrollTop = listEl.scrollHeight;
            }

            async function loadChat() {
                const res = await fetch('/public/taller/api/ordenes.php?action=public_chat&id_empresa=' + encodeURIComponent(idEmpresa) + '&token=' + encodeURIComponent(token));
                const data = await res.json();
                if (!data.success) return;
                renderMessages(data.data || []);
            }

            async function sendChat() {
                const mensaje = String((msgEl && msgEl.value) || '').trim();
                if (!mensaje) return;
                sendBtn.disabled = true;
                try {
                    const res = await fetch('/public/taller/api/ordenes.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'public_chat_send', id_empresa: idEmpresa, token: token, mensaje: mensaje })
                    });
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'No se pudo enviar el mensaje');
                    msgEl.value = '';
                    renderMessages(data.data || []);
                } catch (err) {
                    alert(err && err.message ? err.message : 'No se pudo enviar el mensaje');
                } finally {
                    sendBtn.disabled = false;
                }
            }

            if (sendBtn) sendBtn.addEventListener('click', sendChat);
            if (msgEl) {
                msgEl.addEventListener('keydown', function(ev) {
                    if (ev.key === 'Enter' && !ev.shiftKey) {
                        ev.preventDefault();
                        sendChat();
                    }
                });
            }
            setInterval(loadChat, 10000);
            loadChat();
        })();
    </script>
    <?php endif; ?>
</body>
</html>
