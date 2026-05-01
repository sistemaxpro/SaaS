<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

$idEmpresa = (int)Session::getIdEmpresa();
$idLogin = (int)Session::getIdLogin();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard FE - Empresa</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen">
<div class="max-w-7xl mx-auto p-4 md:p-6">
    <div class="mb-5 flex items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                    class="w-10 h-10 rounded-lg bg-red-600/10 border border-red-500/40 flex items-center justify-center text-red-400 hover:bg-red-600/20 active:scale-95 transition-all cursor-pointer"
                    title="Salir">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15m-3 0-3-3m0 0 3-3m-3 3H15" />
                </svg>
            </button>
            <div>
                <h1 class="text-2xl md:text-3xl font-black">Dashboard Factura Electrónica</h1>
                <p class="text-sm text-slate-400">Empresa #<?= $idEmpresa ?> | Usuario #<?= $idLogin ?></p>
            </div>
        </div>
        <div class="flex gap-2">
            <button id="btnProcess" class="px-3 py-2 rounded-lg bg-amber-600 hover:bg-amber-500 text-xs font-bold">Procesar Cola</button>
            <button id="btnReload" class="px-3 py-2 rounded-lg bg-sky-700 hover:bg-sky-600 text-xs font-bold">Actualizar</button>
        </div>
    </div>

    <div id="msg" class="text-xs text-slate-300 mb-3"></div>

    <div class="bg-slate-900 border border-slate-800 rounded-xl p-3 mb-4">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div class="md:col-span-2">
                <label for="fltQ" class="block text-[11px] uppercase tracking-wide text-slate-400 mb-1">Buscar</label>
                <input id="fltQ" type="text" placeholder="Nro, CDC, estado, mensaje..."
                       class="w-full rounded-lg bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-100 outline-none focus:border-sky-500">
            </div>
            <div>
                <label for="fltFrom" class="block text-[11px] uppercase tracking-wide text-slate-400 mb-1">Desde</label>
                <input id="fltFrom" type="date"
                       class="w-full rounded-lg bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-100 outline-none focus:border-sky-500">
            </div>
            <div>
                <label for="fltTo" class="block text-[11px] uppercase tracking-wide text-slate-400 mb-1">Hasta</label>
                <input id="fltTo" type="date"
                       class="w-full rounded-lg bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-100 outline-none focus:border-sky-500">
            </div>
        </div>
        <div class="flex flex-wrap gap-2 mt-3">
            <button id="btnQuickMonth" type="button" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-xs font-bold text-slate-100">Este mes</button>
            <button id="btnQuickToday" type="button" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-xs font-bold text-slate-100">Hoy</button>
            <button id="btnQuick7d" type="button" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-xs font-bold text-slate-100">Últimos 7 días</button>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-3 mb-4" id="kpiGrid"></div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
            <h2 class="font-bold mb-3">Últimas FE</h2>
            <div class="overflow-auto max-h-[65vh]">
                <table class="w-full text-xs">
                    <thead class="text-slate-400 border-b border-slate-800 sticky top-0 bg-slate-900">
                    <tr>
                        <th class="text-left py-2">ID</th>
                        <th class="text-left py-2">Nro</th>
                        <th class="text-left py-2">Fecha</th>
                        <th class="text-left py-2">Estado</th>
                        <th class="text-left py-2">CDC</th>
                        <th class="text-left py-2">Lote</th>
                    </tr>
                    </thead>
                    <tbody id="feBody"></tbody>
                </table>
            </div>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
            <h2 class="font-bold mb-3">Estado de Envío de Facturas Electrónicas</h2>
            <div id="queueSummary" class="text-xs text-slate-300 mb-2"></div>
            <div class="overflow-auto max-h-[65vh]">
                <table class="w-full text-xs">
                    <thead class="text-slate-400 border-b border-slate-800 sticky top-0 bg-slate-900">
                    <tr>
                        <th class="text-left py-2">QID</th>
                        <th class="text-left py-2">Factura</th>
                        <th class="text-left py-2">Acción</th>
                        <th class="text-left py-2">Estado</th>
                        <th class="text-left py-2">Intentos</th>
                        <th class="text-left py-2">Próximo</th>
                    </tr>
                    </thead>
                    <tbody id="queueBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const API = '/public/empresa/api_fe_dashboard.php';
const ID_EMPRESA = <?= $idEmpresa ?>;
const $ = (id) => document.getElementById(id);
let filterDebounce = null;

function toYmd(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function initMonthDefault() {
    const now = new Date();
    const start = new Date(now.getFullYear(), now.getMonth(), 1);
    $('fltFrom').value = toYmd(start);
    $('fltTo').value = toYmd(now);
}

function setQuickRange(type) {
    const now = new Date();
    if (type === 'today') {
        const d = toYmd(now);
        $('fltFrom').value = d;
        $('fltTo').value = d;
        return;
    }
    if (type === '7d') {
        const from = new Date(now);
        from.setDate(from.getDate() - 6);
        $('fltFrom').value = toYmd(from);
        $('fltTo').value = toYmd(now);
        return;
    }
    initMonthDefault();
}

function getFilters() {
    return {
        q: ($('fltQ').value || '').trim(),
        date_from: $('fltFrom').value || '',
        date_to: $('fltTo').value || ''
    };
}

function setMsg(text, ok = true) {
    const el = $('msg');
    el.textContent = text || '';
    el.className = 'text-xs mb-3 ' + (ok ? 'text-emerald-300' : 'text-rose-300');
}

function kpiCard(label, value, cls) {
    return `<div class="rounded-lg border border-slate-800 bg-slate-900 p-3">
        <div class="text-[10px] uppercase tracking-wide text-slate-400">${label}</div>
        <div class="text-xl font-black ${cls || 'text-white'}">${value ?? 0}</div>
    </div>`;
}

function statusBadge(s) {
    const v = String(s || '').toLowerCase();
    if (v === 'aprobado') return '<span class="text-emerald-300">Aprobado</span>';
    if (v === 'pendiente') return '<span class="text-amber-300">Pendiente</span>';
    if (v === 'rechazado') return '<span class="text-rose-300">Rechazado</span>';
    if (v === 'guardado local') return '<span class="text-sky-300">Guardado Local</span>';
    return `<span class="text-slate-300">${s || '-'}</span>`;
}

async function loadDashboard() {
    setMsg('Cargando dashboard...');
    const f = getFilters();
    const qs = new URLSearchParams({
        action: 'summary',
        id_empresa: String(ID_EMPRESA),
        q: f.q,
        date_from: f.date_from,
        date_to: f.date_to
    });
    const res = await fetch(`${API}?${qs.toString()}`, { cache: 'no-store' });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error cargando dashboard');

    const d = json.data || {};
    const k = d.kpi || {};
    $('kpiGrid').innerHTML = [
        kpiCard('FE Total', k.total_fe, 'text-white'),
        kpiCard('Aprobadas', k.aprobadas, 'text-emerald-300'),
        kpiCard('Pendientes', k.pendientes, 'text-amber-300'),
        kpiCard('Rechazadas', k.rechazadas, 'text-rose-300'),
        kpiCard('Guardado Local', k.guardado_local, 'text-sky-300'),
        kpiCard('Sin CDC', k.sin_cdc, 'text-violet-300'),
        kpiCard('Hoy', k.hoy, 'text-cyan-300')
    ].join('');

    $('feBody').innerHTML = (d.ultimas_fe || []).map(f => {
        const hasCdc = String(f.cdc || '').trim() !== '' ? 'SI' : 'NO';
        const hasLote = String(f.prot_cons_lote_sifen || '').trim() !== '' ? 'SI' : 'NO';
        return `<tr class="border-b border-slate-800/70">
            <td class="py-2">${f.id_factura}</td>
            <td class="py-2">${f.nro_factura || '-'}</td>
            <td class="py-2">${f.fecha || '-'}</td>
            <td class="py-2">${statusBadge(f.estado_sifen)}</td>
            <td class="py-2">${hasCdc}</td>
            <td class="py-2">${hasLote}</td>
        </tr>
        ${f.mensaje_sifen ? `<tr><td colspan="6" class="pb-2 text-[11px] text-slate-400">${f.mensaje_sifen}</td></tr>` : ''}`;
    }).join('') || '<tr><td colspan="6" class="py-3 text-slate-500">Sin FE registradas.</td></tr>';

    $('queueBody').innerHTML = (d.queue || []).map(q => `<tr class="border-b border-slate-800/70">
        <td class="py-2">${q.id}</td>
        <td class="py-2">${q.id_factura}</td>
        <td class="py-2">${q.accion}</td>
        <td class="py-2">${q.estado}</td>
        <td class="py-2">${q.intentos}/${q.max_intentos}</td>
        <td class="py-2">${q.next_retry_at || '-'}</td>
    </tr>
    ${(q.last_message || q.last_error) ? `<tr><td colspan="6" class="pb-2 text-[11px] text-slate-400">${q.last_message || q.last_error}</td></tr>` : ''}`).join('') || '<tr><td colspan="6" class="py-3 text-slate-500">Cola vacía.</td></tr>';

    $('queueSummary').textContent = (d.queue_summary || []).map(x => `${x.estado}: ${x.cantidad}`).join(' | ') || 'Sin elementos en cola';
    setMsg(`Actualizado: ${d.timestamp || '-'}`);
}

async function processQueueNow() {
    setMsg('Procesando cola FE...');
    const res = await fetch(`${API}?action=process_queue&id_empresa=${ID_EMPRESA}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ limit: 12 })
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Error procesando cola');
    const count = json.data?.processed || 0;
    setMsg(`Procesados: ${count}`);
    await loadDashboard();
}

document.addEventListener('DOMContentLoaded', async () => {
    initMonthDefault();

    $('btnReload').addEventListener('click', async () => {
        try { await loadDashboard(); } catch (e) { setMsg(e.message, false); }
    });
    $('btnProcess').addEventListener('click', async () => {
        try { await processQueueNow(); } catch (e) { setMsg(e.message, false); }
    });
    const runFilter = () => {
        if (filterDebounce) clearTimeout(filterDebounce);
        filterDebounce = setTimeout(() => {
            loadDashboard().catch((e) => setMsg(e.message, false));
        }, 250);
    };
    $('fltQ').addEventListener('input', runFilter);
    $('fltFrom').addEventListener('change', runFilter);
    $('fltTo').addEventListener('change', runFilter);
    $('fltQ').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (filterDebounce) clearTimeout(filterDebounce);
            loadDashboard().catch((err) => setMsg(err.message, false));
        }
    });
    $('btnQuickMonth').addEventListener('click', () => {
        setQuickRange('month');
        loadDashboard().catch((e) => setMsg(e.message, false));
    });
    $('btnQuickToday').addEventListener('click', () => {
        setQuickRange('today');
        loadDashboard().catch((e) => setMsg(e.message, false));
    });
    $('btnQuick7d').addEventListener('click', () => {
        setQuickRange('7d');
        loadDashboard().catch((e) => setMsg(e.message, false));
    });

    try {
        await loadDashboard();
        setInterval(() => { loadDashboard().catch(() => {}); }, 30000);
    } catch (e) {
        setMsg(e.message, false);
    }
});
</script>
</body>
</html>
