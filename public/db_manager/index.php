<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../src/Modules/Empresas/SuscripcionController.php';

Session::requireLogin('/public/login.php');

function dbManagerHasAccessPage(): bool
{
    if (strtoupper((string)($_SESSION['usr_priv_admin'] ?? 'N')) === 'Y') {
        return true;
    }
    if (Permission::hasAccess('app_grid_db_manager') || Permission::hasAccess('app_grid_db_migrador')) {
        return true;
    }
    $appsEmpresa = SuscripcionController::getAppsEmpresa((int)Session::getIdEmpresa());
    if (($appsEmpresa['success'] ?? false) !== true) {
        return false;
    }
    foreach (($appsEmpresa['data'] ?? []) as $app) {
        $codigo = (string)($app['codigo'] ?? '');
        if ($codigo === 'db_manager' || $codigo === 'db_migrador') {
            return true;
        }
    }
    return false;
}

if (!dbManagerHasAccessPage()) {
    http_response_code(403);
    echo 'Acceso restringido. App no asignada o sin permiso.';
    exit;
}
?>
<!doctype html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DB Manager - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak]{display:none!important}
        html, body { height: 100%; }
        .navicat-scroll::-webkit-scrollbar { width: 10px; height: 10px; }
        .navicat-scroll::-webkit-scrollbar-thumb { background: #334155; border-radius: 999px; }
        .navicat-scroll::-webkit-scrollbar-track { background: #0f172a; }
    </style>
</head>
<body class="h-screen overflow-hidden bg-slate-950 text-slate-100">
<div x-data="dbManagerApp()" x-init="init()" class="h-screen flex flex-col">
    <header class="h-14 flex items-center justify-between border-b border-slate-800 bg-slate-900/95 px-4 shadow-lg">
        <div class="flex items-center gap-3 min-w-0">
            <div class="h-9 w-9 rounded-xl bg-blue-600/20 border border-blue-500/30 flex items-center justify-center text-blue-300 font-black">DB</div>
            <div>
                <div class="font-bold leading-tight">SistemaX DB Manager</div>
                <div class="text-xs text-slate-400">Workbench estilo Navicat para MySQL/MariaDB</div>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button @click="refreshAll()" class="px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-sm">Refrescar</button>
            <button @click="openQueryTab()" class="px-3 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-sm font-semibold">Nueva consulta</button>
            <button @click="openConnectionModal()" class="px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-sm font-semibold">Conexiones</button>
        </div>
    </header>

    <main class="flex-1 min-h-0 grid grid-cols-[310px_minmax(0,1fr)]">
        <aside class="min-h-0 border-r border-slate-800 bg-slate-900/80 flex flex-col">
            <div class="p-3 border-b border-slate-800">
                <input x-model="treeFilter" class="w-full px-3 py-2 rounded-lg bg-slate-950 border border-slate-700 text-sm outline-none focus:border-blue-500" placeholder="Filtrar conexiones, bases, tablas...">
            </div>
            <div class="flex-1 overflow-auto navicat-scroll p-2 space-y-2">
                <template x-if="servers.length === 0">
                    <div class="text-sm text-slate-400 p-3">No hay conexiones guardadas. Use Conexiones.</div>
                </template>
                <template x-for="srv in filteredServers()" :key="srv.id_server">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/60 overflow-hidden">
                        <button @click="toggleServer(srv)" class="w-full flex items-center gap-2 px-3 py-2 hover:bg-slate-800/80 text-left">
                            <span class="text-cyan-300">●</span>
                            <span class="font-semibold truncate" x-text="srv.nombre"></span>
                            <span class="ml-auto text-xs text-slate-500" x-text="srv.host + ':' + srv.port"></span>
                        </button>
                        <div x-show="srv.open" x-cloak class="pb-2">
                            <template x-if="srv.loading"><div class="px-8 py-2 text-xs text-slate-500">Cargando bases...</div></template>
                            <template x-for="db in srv.databases" :key="srv.id_server + ':' + db.name">
                                <div>
                                    <button @click="toggleDatabase(srv, db)" class="w-full flex items-center gap-2 px-6 py-1.5 text-sm hover:bg-slate-800/70 text-left">
                                        <span class="text-amber-300">▣</span>
                                        <span class="truncate" x-text="db.name"></span>
                                    </button>
                                    <div x-show="db.open" x-cloak class="ml-7 border-l border-slate-800 pl-2">
                                        <template x-if="db.loading"><div class="py-1 text-xs text-slate-500">Cargando objetos...</div></template>
                                        <div class="text-[11px] uppercase text-slate-500 mt-1">Tablas</div>
                                        <template x-for="tb in db.tables" :key="tb.name">
                                            <button @click="openTable(srv, db, tb)" class="w-full flex items-center gap-2 py-1 px-2 rounded text-sm hover:bg-blue-500/15 text-left">
                                                <span class="text-blue-300">▦</span>
                                                <span class="truncate" x-text="tb.name"></span>
                                                <span class="ml-auto text-[10px] text-slate-500" x-text="formatNumber(tb.rows)"></span>
                                            </button>
                                        </template>
                                        <template x-if="db.views.length">
                                            <div class="text-[11px] uppercase text-slate-500 mt-2">Vistas</div>
                                        </template>
                                        <template x-for="vw in db.views" :key="vw.name">
                                            <button @click="openTable(srv, db, vw)" class="w-full flex items-center gap-2 py-1 px-2 rounded text-sm hover:bg-purple-500/15 text-left">
                                                <span class="text-purple-300">◈</span>
                                                <span class="truncate" x-text="vw.name"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </aside>

        <section class="min-w-0 min-h-0 flex flex-col bg-slate-950">
            <div class="h-11 flex items-end gap-1 px-2 border-b border-slate-800 bg-slate-900/70 overflow-x-auto navicat-scroll">
                <template x-for="tab in tabs" :key="tab.id">
                    <button @click="activeTabId = tab.id" class="group max-w-[260px] flex items-center gap-2 px-3 py-2 rounded-t-lg border text-sm" :class="activeTabId === tab.id ? 'bg-slate-950 border-slate-700 border-b-slate-950 text-white' : 'bg-slate-900 border-transparent text-slate-400 hover:text-white'">
                        <span class="truncate" x-text="tab.title"></span>
                        <span @click.stop="closeTab(tab.id)" class="text-slate-500 group-hover:text-red-300">×</span>
                    </button>
                </template>
            </div>

            <div class="flex-1 min-h-0 overflow-hidden">
                <template x-if="tabs.length === 0">
                    <div class="h-full flex items-center justify-center text-center text-slate-500">
                        <div>
                            <div class="text-5xl mb-4">▤</div>
                            <div class="font-semibold text-slate-300">Seleccione una tabla o abra una consulta</div>
                            <div class="text-sm">Explore conexiones, edite SQL y vea estructura/datos.</div>
                        </div>
                    </div>
                </template>

                <template x-for="tab in tabs" :key="tab.id">
                    <div x-show="activeTabId === tab.id" x-cloak class="h-full min-h-0 flex flex-col">
                        <template x-if="tab.type === 'table'">
                            <div class="h-full min-h-0 flex flex-col">
                                <div class="h-12 flex items-center justify-between border-b border-slate-800 px-4 bg-slate-950">
                                    <div class="min-w-0">
                                        <div class="font-semibold truncate" x-text="tab.database + '.' + tab.table"></div>
                                        <div class="text-xs text-slate-500" x-text="tab.server.nombre + ' · ' + tab.server.host"></div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button @click="loadTableData(tab)" class="px-3 py-1.5 rounded bg-blue-600 hover:bg-blue-500 text-sm">Datos</button>
                                        <button @click="loadTableStructure(tab)" class="px-3 py-1.5 rounded bg-slate-800 hover:bg-slate-700 text-sm">Estructura</button>
                                        <button @click="confirmTableAction(tab, 'truncate')" class="px-3 py-1.5 rounded bg-amber-700 hover:bg-amber-600 text-sm">Truncate</button>
                                        <button @click="confirmTableAction(tab, 'drop')" class="px-3 py-1.5 rounded bg-red-700 hover:bg-red-600 text-sm">Drop</button>
                                    </div>
                                </div>
                                <div class="h-10 flex items-center gap-2 px-4 border-b border-slate-800 bg-slate-900/50">
                                    <input x-model="tab.where" @keydown.enter="loadTableData(tab)" class="flex-1 px-3 py-1.5 rounded bg-slate-950 border border-slate-700 text-xs font-mono" placeholder="WHERE opcional, ej: id > 10">
                                    <select x-model.number="tab.limit" class="px-2 py-1.5 rounded bg-slate-950 border border-slate-700 text-xs">
                                        <option :value="50">50</option><option :value="100">100</option><option :value="250">250</option><option :value="500">500</option>
                                    </select>
                                </div>
                                <div class="flex-1 min-h-0 grid grid-rows-[minmax(0,1fr)_230px]">
                                    <div class="overflow-auto navicat-scroll">
                                        <table class="min-w-full text-xs font-mono">
                                            <thead class="sticky top-0 bg-slate-900 text-slate-300">
                                            <tr><template x-for="col in tableColumns(tab.rows)"><th class="px-3 py-2 text-left border-b border-slate-800" x-text="col"></th></template></tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-900">
                                            <template x-for="(row, i) in tab.rows" :key="i">
                                                <tr class="hover:bg-slate-900/80"><template x-for="col in tableColumns(tab.rows)"><td class="px-3 py-1.5 max-w-[320px] truncate text-slate-300" x-text="formatCell(row[col])"></td></template></tr>
                                            </template>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="border-t border-slate-800 grid grid-cols-2 min-h-0">
                                        <div class="overflow-auto navicat-scroll border-r border-slate-800">
                                            <div class="px-3 py-2 text-xs uppercase text-slate-500 bg-slate-900/60">Columnas</div>
                                            <table class="w-full text-xs"><tbody><template x-for="c in tab.columns"><tr class="border-b border-slate-900"><td class="px-3 py-1.5 text-blue-300" x-text="c.Field"></td><td class="px-3 py-1.5 text-slate-300" x-text="c.Type"></td><td class="px-3 py-1.5 text-slate-500" x-text="c.Key"></td></tr></template></tbody></table>
                                        </div>
                                        <div class="overflow-auto navicat-scroll">
                                            <div class="px-3 py-2 text-xs uppercase text-slate-500 bg-slate-900/60">DDL</div>
                                            <pre class="p-3 text-xs text-slate-300 whitespace-pre-wrap" x-text="tab.createSql || 'Sin DDL cargado'"></pre>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <template x-if="tab.type === 'query'">
                            <div class="h-full min-h-0 grid grid-rows-[280px_minmax(0,1fr)]">
                                <div class="border-b border-slate-800 flex flex-col">
                                    <div class="h-11 flex items-center gap-2 px-3 bg-slate-900/50 border-b border-slate-800">
                                        <select x-model="tab.serverId" @change="queryServerChanged(tab)" class="px-2 py-1.5 rounded bg-slate-950 border border-slate-700 text-sm">
                                            <option value="">Servidor</option>
                                            <template x-for="srv in servers" :key="srv.id_server"><option :value="String(srv.id_server)" x-text="srv.nombre"></option></template>
                                        </select>
                                        <select x-model="tab.database" class="px-2 py-1.5 rounded bg-slate-950 border border-slate-700 text-sm">
                                            <option value="">Sin DB</option>
                                            <template x-for="db in tab.databases" :key="db"><option :value="db" x-text="db"></option></template>
                                        </select>
                                        <button @click="runQuery(tab)" class="px-4 py-1.5 rounded bg-emerald-600 hover:bg-emerald-500 text-sm font-semibold">Ejecutar</button>
                                    </div>
                                    <textarea x-model="tab.sql" spellcheck="false" class="flex-1 w-full bg-slate-950 text-slate-100 font-mono text-sm p-4 outline-none resize-none" placeholder="SELECT * FROM tabla LIMIT 100;"></textarea>
                                </div>
                                <div class="overflow-auto navicat-scroll">
                                    <template x-for="(res, idx) in tab.results" :key="idx">
                                        <div class="border-b border-slate-800">
                                            <div class="px-3 py-2 text-xs bg-slate-900/60 text-slate-400"><span x-text="res.elapsed_ms + ' ms'"></span> · <span x-text="res.type === 'rows' ? ((res.rows||[]).length + ' filas') : (res.affected_rows + ' afectadas')"></span></div>
                                            <template x-if="res.type === 'rows'">
                                                <table class="min-w-full text-xs font-mono"><thead class="bg-slate-900"><tr><template x-for="col in tableColumns(res.rows)"><th class="px-3 py-2 text-left border-b border-slate-800" x-text="col"></th></template></tr></thead><tbody><template x-for="(row, i) in res.rows"><tr class="hover:bg-slate-900/80"><template x-for="col in tableColumns(res.rows)"><td class="px-3 py-1.5 max-w-[320px] truncate" x-text="formatCell(row[col])"></td></template></tr></template></tbody></table>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </section>
    </main>

    <div x-show="connModal.open" x-cloak class="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4">
        <div @click.away="connModal.open=false" class="w-full max-w-4xl rounded-2xl bg-slate-900 border border-slate-700 shadow-2xl overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-800 flex justify-between"><div class="font-bold">Conexiones guardadas</div><button @click="connModal.open=false" class="text-slate-400 hover:text-white">×</button></div>
            <div class="p-5 grid grid-cols-2 gap-4">
                <div class="space-y-2 max-h-[420px] overflow-auto navicat-scroll">
                    <template x-for="srv in servers" :key="srv.id_server"><button @click="editServer(srv)" class="w-full text-left p-3 rounded-xl bg-slate-950 hover:bg-slate-800 border border-slate-800"><div class="font-semibold" x-text="srv.nombre"></div><div class="text-xs text-slate-500" x-text="srv.user + '@' + srv.host + ':' + srv.port"></div></button></template>
                </div>
                <div class="space-y-3">
                    <input x-model="serverForm.nombre" class="w-full px-3 py-2 rounded bg-slate-950 border border-slate-700" placeholder="Nombre">
                    <div class="grid grid-cols-3 gap-2"><input x-model="serverForm.host" class="col-span-2 px-3 py-2 rounded bg-slate-950 border border-slate-700" placeholder="Host/IP"><input x-model.number="serverForm.port" type="number" class="px-3 py-2 rounded bg-slate-950 border border-slate-700" placeholder="Puerto"></div>
                    <div class="grid grid-cols-2 gap-2"><input x-model="serverForm.user" class="px-3 py-2 rounded bg-slate-950 border border-slate-700" placeholder="Usuario"><input x-model="serverForm.password" type="password" class="px-3 py-2 rounded bg-slate-950 border border-slate-700" placeholder="Password opcional"></div>
                    <input x-model="serverForm.database_default" class="w-full px-3 py-2 rounded bg-slate-950 border border-slate-700" placeholder="Base por defecto">
                    <textarea x-model="serverForm.observacion" rows="2" class="w-full px-3 py-2 rounded bg-slate-950 border border-slate-700" placeholder="Observación"></textarea>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" x-model="serverForm.activo"> Activo</label>
                    <div class="flex gap-2"><button @click="saveServer()" class="px-4 py-2 rounded bg-emerald-600 hover:bg-emerald-500 font-semibold">Guardar</button><button @click="resetServerForm()" class="px-4 py-2 rounded bg-slate-700 hover:bg-slate-600">Nuevo</button></div>
                </div>
            </div>
        </div>
    </div>

    <div x-show="modal.open" x-cloak class="fixed inset-0 z-[60] bg-black/60 flex items-center justify-center p-4">
        <div @click.away="modal.open=false" class="w-full max-w-md rounded-2xl bg-slate-900 border border-slate-700 shadow-2xl">
            <div class="px-5 py-4 border-b border-slate-800 font-bold" x-text="modal.title"></div>
            <div class="p-5 text-sm whitespace-pre-line text-slate-200" x-text="modal.message"></div>
            <div class="px-5 pb-5"><button @click="modal.open=false" class="w-full px-4 py-2 rounded bg-indigo-600 hover:bg-indigo-500 font-semibold">Entendido</button></div>
        </div>
    </div>
</div>

<script>
function dbManagerApp() {
    return {
        servers: [],
        tabs: [],
        activeTabId: '',
        treeFilter: '',
        connModal: { open: false },
        modal: { open: false, title: '', message: '' },
        serverForm: { id_server: 0, nombre: '', host: '127.0.0.1', port: 3306, user: 'root', password: '', database_default: '', observacion: '', activo: true },

        init() { this.loadServers(); },
        api(action, payload) {
            return fetch('api/db_manager.php?action=' + encodeURIComponent(action), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload || {}) })
                .then(async res => { const raw = await res.text(); let data; try { data = raw ? JSON.parse(raw) : null; } catch(e) { throw new Error(raw || 'Respuesta no válida'); } if (!data) throw new Error('Respuesta vacía'); if (!data.ok) throw new Error(data.error || 'Operación fallida'); return data; });
        },
        showModal(title, message) { this.modal = { open: true, title: title || 'Aviso', message: message || '' }; },
        formatNumber(v) { return Number(v || 0).toLocaleString('es-PY'); },
        formatCell(v) { if (v === null) return 'NULL'; if (typeof v === 'object') return JSON.stringify(v); return String(v); },
        tableColumns(rows) { return rows && rows.length ? Object.keys(rows[0]) : []; },
        filteredServers() { const q = this.treeFilter.trim().toLowerCase(); if (!q) return this.servers; return this.servers.filter(s => JSON.stringify(s).toLowerCase().includes(q)); },
        refreshAll() { this.loadServers(); const tab = this.activeTab(); if (tab && tab.type === 'table') { this.loadTableStructure(tab); this.loadTableData(tab); } },
        activeTab() { return this.tabs.find(t => t.id === this.activeTabId); },

        async loadServers() {
            try {
                const data = await this.api('list_servers', {});
                this.servers = (data.data || []).map(s => ({ ...s, open: false, loading: false, databases: [] }));
            } catch (e) { this.showModal('Error', e.message); }
        },
        openConnectionModal() { this.connModal.open = true; this.loadServers(); },
        resetServerForm() { this.serverForm = { id_server: 0, nombre: '', host: '127.0.0.1', port: 3306, user: 'root', password: '', database_default: '', observacion: '', activo: true }; },
        editServer(s) { this.serverForm = { id_server: Number(s.id_server || 0), nombre: s.nombre || '', host: s.host || '', port: Number(s.port || 3306), user: s.user || '', password: s.password || '', database_default: s.database_default || '', observacion: s.observacion || '', activo: Number(s.activo) === 1 }; },
        async saveServer() {
            try {
                const res = await fetch('../db_migrador/api/migrador.php?action=save_server', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(this.serverForm) });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'No se pudo guardar');
                this.resetServerForm(); await this.loadServers(); this.showModal('Guardado', 'Conexión guardada correctamente.');
            } catch (e) { this.showModal('Error', e.message); }
        },

        async toggleServer(srv) {
            srv.open = !srv.open;
            if (!srv.open || srv.databases.length) return;
            srv.loading = true;
            try {
                const data = await this.api('list_databases', { server_id: srv.id_server });
                srv.databases = (data.data || []).map(name => ({ name, open: false, loading: false, tables: [], views: [], routines: [] }));
            } catch (e) { this.showModal('Error', e.message); }
            finally { srv.loading = false; }
        },
        async toggleDatabase(srv, db) {
            db.open = !db.open;
            if (!db.open || db.tables.length || db.views.length) return;
            db.loading = true;
            try {
                const data = await this.api('list_objects', { server_id: srv.id_server, database: db.name });
                db.tables = data.data.tables || []; db.views = data.data.views || []; db.routines = data.data.routines || [];
            } catch (e) { this.showModal('Error', e.message); }
            finally { db.loading = false; }
        },
        openTable(srv, db, obj) {
            const id = `table:${srv.id_server}:${db.name}:${obj.name}`;
            let tab = this.tabs.find(t => t.id === id);
            if (!tab) {
                tab = { id, type: 'table', title: obj.name, server: srv, serverId: srv.id_server, database: db.name, table: obj.name, rows: [], columns: [], indexes: [], createSql: '', where: '', limit: 100, offset: 0 };
                this.tabs.push(tab);
                this.loadTableStructure(tab);
                this.loadTableData(tab);
            }
            this.activeTabId = id;
        },
        async loadTableStructure(tab) {
            try {
                const data = await this.api('table_columns', { server_id: tab.serverId, database: tab.database, table: tab.table });
                tab.columns = data.data.columns || []; tab.indexes = data.data.indexes || []; tab.createSql = data.data.create_sql || '';
            } catch (e) { this.showModal('Error', e.message); }
        },
        async loadTableData(tab) {
            try {
                const data = await this.api('table_data', { server_id: tab.serverId, database: tab.database, table: tab.table, where: tab.where, limit: tab.limit, offset: tab.offset });
                tab.rows = data.data.rows || [];
            } catch (e) { this.showModal('Error', e.message); }
        },
        async confirmTableAction(tab, op) {
            const label = op === 'drop' ? 'eliminar' : 'vaciar';
            const confirmText = prompt(`Para ${label} ${tab.table}, escriba exactamente: ${tab.table}`);
            if (confirmText !== tab.table) return;
            try {
                await this.api('table_action', { server_id: tab.serverId, database: tab.database, table: tab.table, op, confirm: confirmText });
                this.showModal('OK', 'Operación ejecutada.');
                if (op === 'drop') this.closeTab(tab.id); else this.loadTableData(tab);
            } catch (e) { this.showModal('Error', e.message); }
        },
        openQueryTab() {
            const id = 'query:' + Date.now();
            this.tabs.push({ id, type: 'query', title: 'Consulta SQL', serverId: this.servers[0] ? String(this.servers[0].id_server) : '', database: '', databases: [], sql: 'SELECT NOW() AS now;', results: [] });
            this.activeTabId = id;
            const tab = this.activeTab();
            if (tab && tab.serverId) this.queryServerChanged(tab);
        },
        async queryServerChanged(tab) {
            tab.databases = [];
            if (!tab.serverId) return;
            try {
                const data = await this.api('list_databases', { server_id: tab.serverId });
                tab.databases = data.data || [];
            } catch (e) { this.showModal('Error', e.message); }
        },
        async runQuery(tab) {
            try {
                const data = await this.api('run_query', { server_id: tab.serverId, database: tab.database, sql: tab.sql });
                tab.results = data.data || [];
            } catch (e) { this.showModal('SQL Error', e.message); }
        },
        closeTab(id) { this.tabs = this.tabs.filter(t => t.id !== id); if (this.activeTabId === id) this.activeTabId = this.tabs[0]?.id || ''; },
    };
}
</script>
</body>
</html>
