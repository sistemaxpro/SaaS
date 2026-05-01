<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

$idEmpresa = (int)Session::getIdEmpresa();
$idLogin = (int)Session::getIdLogin();
$empresaName = (string)($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa #' . $idEmpresa));
$userName = (string)($_SESSION['user_name'] ?? $_SESSION['usuario'] ?? ('Usuario #' . $idLogin));
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Offline DB</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100" x-data="offlineDbMonitor()" x-init="init()">
<div class="mx-auto max-w-7xl px-4 py-4">
    <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <h1 class="text-2xl font-black tracking-tight">Offline DB</h1>
            <p class="text-sm text-slate-400"><?= htmlspecialchars($empresaName, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" @click="refresh()" class="rounded-xl border border-cyan-700/40 bg-cyan-500/10 px-3 py-2 text-sm font-semibold text-cyan-200 hover:bg-cyan-500/20">Actualizar</button>
            <button type="button" @click="clearOfflineData()" class="rounded-xl border border-red-700/40 bg-red-500/10 px-3 py-2 text-sm font-semibold text-red-200 hover:bg-red-500/20">Limpiar offline</button>
            <button type="button" onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';" class="rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-semibold text-slate-100 hover:bg-slate-800">Cerrar</button>
        </div>
    </div>

    <div class="mb-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Cuota estimada</div>
            <div class="mt-2 text-2xl font-black text-white" x-text="humanBytes(storage.quota)">-</div>
        </div>
        <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Uso estimado</div>
            <div class="mt-2 text-2xl font-black text-white" x-text="humanBytes(storage.usage)">-</div>
        </div>
        <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Claves offline</div>
            <div class="mt-2 text-2xl font-black text-white" x-text="entries.length">0</div>
        </div>
        <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
            <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Pendientes</div>
            <div class="mt-2 text-2xl font-black text-amber-300" x-text="queueCount">0</div>
        </div>
    </div>

    <div class="mb-4 grid gap-3 lg:grid-cols-3">
        <template x-for="mod in modules" :key="mod.key">
            <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-sm font-bold text-white" x-text="mod.label"></div>
                        <div class="text-xs text-slate-400" x-text="mod.prefix"></div>
                    </div>
                    <div class="text-right">
                        <div class="text-lg font-black text-white" x-text="statsByPrefix[mod.key]?.count || 0"></div>
                        <div class="text-[11px] text-slate-400" x-text="humanBytes(statsByPrefix[mod.key]?.size || 0)"></div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div class="rounded-2xl border border-slate-800 bg-slate-900">
        <div class="border-b border-slate-800 px-4 py-3">
            <input x-model.trim="filter" type="text" placeholder="Filtrar por clave..."
                   class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-cyan-500">
        </div>
        <div class="overflow-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-950/80 text-slate-400">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold">Clave</th>
                    <th class="px-4 py-3 text-left font-semibold">Tipo</th>
                    <th class="px-4 py-3 text-right font-semibold">Items</th>
                    <th class="px-4 py-3 text-right font-semibold">Tamaño</th>
                </tr>
                </thead>
                <tbody>
                <template x-if="loading">
                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-400">Leyendo IndexedDB...</td></tr>
                </template>
                <template x-if="!loading && filteredEntries().length === 0">
                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-400">Sin datos offline guardados.</td></tr>
                </template>
                <template x-for="row in filteredEntries()" :key="row.key">
                    <tr class="border-t border-slate-800">
                        <td class="px-4 py-3 font-mono text-[12px] text-cyan-300" x-text="row.key"></td>
                        <td class="px-4 py-3 text-slate-300" x-text="row.kind"></td>
                        <td class="px-4 py-3 text-right text-slate-200" x-text="row.count"></td>
                        <td class="px-4 py-3 text-right text-slate-200" x-text="humanBytes(row.size)"></td>
                    </tr>
                </template>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function offlineDbMonitor() {
    return {
        loading: false,
        filter: '',
        entries: [],
        storage: { quota: 0, usage: 0 },
        queueCount: 0,
        statsByPrefix: {},
        modules: [
            { key: 'productos', label: 'Productos', prefix: 'sx_productos_' },
            { key: 'inventario', label: 'Inventario', prefix: 'sx_inventario_' },
            { key: 'compras', label: 'Compras', prefix: 'smx:compras:' },
            { key: 'gastos', label: 'Gastos', prefix: 'smx:gastos:' }
        ],

        async init() {
            await this.refresh();
        },

        inferKind(value) {
            if (Array.isArray(value)) return 'array';
            if (value && typeof value === 'object' && Array.isArray(value.items)) return 'snapshot';
            if (value && typeof value === 'object') return 'object';
            return typeof value;
        },

        inferCount(value) {
            if (Array.isArray(value)) return value.length;
            if (value && typeof value === 'object' && Array.isArray(value.items)) return value.items.length;
            return value && typeof value === 'object' ? Object.keys(value).length : 1;
        },

        async refresh() {
            this.loading = true;
            try {
                await (window.SmxOfflineDb?.ready || Promise.resolve());
                const rawEntries = await (window.SmxOfflineDb?.entries?.() || Promise.resolve([]));
                this.entries = rawEntries.map((row) => ({
                    key: row.key,
                    size: Number(row.size || 0),
                    kind: this.inferKind(row.value),
                    count: this.inferCount(row.value),
                }));
                this.queueCount = this.entries
                    .filter((row) => row.key.includes(':queue'))
                    .reduce((sum, row) => sum + Number(row.count || 0), 0);
                this.statsByPrefix = this.modules.reduce((acc, mod) => {
                    const rows = this.entries.filter((row) => row.key.includes(mod.prefix));
                    acc[mod.key] = {
                        count: rows.length,
                        size: rows.reduce((sum, row) => sum + Number(row.size || 0), 0)
                    };
                    return acc;
                }, {});
                if (navigator.storage && navigator.storage.estimate) {
                    const estimate = await navigator.storage.estimate();
                    this.storage.quota = Number(estimate.quota || 0);
                    this.storage.usage = Number(estimate.usage || 0);
                }
            } finally {
                this.loading = false;
            }
        },

        filteredEntries() {
            const q = String(this.filter || '').toLowerCase();
            if (!q) return this.entries;
            return this.entries.filter((row) => String(row.key || '').toLowerCase().includes(q));
        },

        humanBytes(value) {
            const num = Number(value || 0);
            if (!num) return '0 B';
            const units = ['B', 'KB', 'MB', 'GB'];
            let idx = 0;
            let current = num;
            while (current >= 1024 && idx < units.length - 1) {
                current /= 1024;
                idx += 1;
            }
            return `${current.toFixed(current >= 10 || idx === 0 ? 0 : 1)} ${units[idx]}`;
        },

        async clearOfflineData() {
            if (!confirm('Esto elimina snapshots y colas offline del navegador para este origen.')) return;
            this.loading = true;
            try {
                await (window.SmxOfflineDb?.clearPrefix?.('sx_') || Promise.resolve());
                await (window.SmxOfflineDb?.clearPrefix?.('smx:compras:') || Promise.resolve());
                await (window.SmxOfflineDb?.clearPrefix?.('smx:gastos:') || Promise.resolve());
                await this.refresh();
            } finally {
                this.loading = false;
            }
        }
    };
}
</script>
</body>
</html>
