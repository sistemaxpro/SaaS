<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

function geoConfigHasAccess(): bool
{
    return Permission::hasAccess('geolocalizacion_config') || Permission::hasAccess('app_grid_tracking_movil');
}

if (!geoConfigHasAccess()) {
    Permission::requireAccess('geolocalizacion_config');
}

$idEmpresa = (int)Session::getIdEmpresa();
$empresaName = (string)($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa #' . $idEmpresa));
$isSupport = in_array($idEmpresa, SISTEMAX_SUPPORT_COMPANIES, true);
$trackingMode = (string)($_GET['mode'] ?? '') === 'tracking_movil';
$pageTitle = $trackingMode ? 'Tracking Móvil' : 'Configuración Geolocalización';
$pageSubtitle = $trackingMode
    ? 'Configurá los íconos de los dispositivos móviles activos de tu empresa'
    : 'Gestión de dispositivos y tracking';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100" x-data="geoConfigApp()">
    <div class="mx-auto max-w-7xl px-4 py-5">
        <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <h1 class="text-2xl font-black tracking-tight"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="text-sm text-slate-400"><?= htmlspecialchars($empresaName, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <?php if (!$trackingMode): ?>
                <button @click="issueSetupToken()" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-500">Generar token</button>
                <a href="/public/apps-moviles.php" class="rounded-xl border border-cyan-700/50 bg-cyan-500/10 px-4 py-2 text-sm font-semibold text-cyan-200 hover:bg-cyan-500/20">Instalación móvil</a>
                <?php endif; ?>
                <button type="button" onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';" class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-2 text-sm font-semibold text-slate-100 hover:bg-slate-800">Cerrar</button>
            </div>
        </div>

        <div class="mb-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-cyan-800/40 bg-slate-900 p-4">
                <div class="text-xs uppercase tracking-[0.22em] text-cyan-300">Dispositivos</div>
                <div class="mt-2 text-3xl font-black" x-text="stats.total">0</div>
            </div>
            <div class="rounded-2xl border border-emerald-800/40 bg-slate-900 p-4">
                <div class="text-xs uppercase tracking-[0.22em] text-emerald-300">Tracking Activo</div>
                <div class="mt-2 text-3xl font-black" x-text="stats.tracking_on">0</div>
            </div>
            <div class="rounded-2xl border border-amber-800/40 bg-slate-900 p-4">
                <div class="text-xs uppercase tracking-[0.22em] text-amber-300">Online</div>
                <div class="mt-2 text-3xl font-black" x-text="stats.online">0</div>
            </div>
            <div class="rounded-2xl border border-indigo-800/40 bg-slate-900 p-4">
                <div class="text-xs uppercase tracking-[0.22em] text-indigo-300"><?= $isSupport ? 'Empresas' : 'Mi Empresa' ?></div>
                <div class="mt-2 text-3xl font-black" x-text="stats.companies">0</div>
            </div>
        </div>

        <div class="mb-4 grid gap-3 <?= $trackingMode ? 'lg:grid-cols-1' : 'lg:grid-cols-[minmax(0,1fr)_340px]' ?>">
            <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_220px_auto]">
                    <input x-model="q" @input.debounce.250ms="load()" type="text" placeholder="Buscar dispositivo, usuario o empresa..." class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500">
                    <input x-show="isSupport" x-model="empresaFiltro" @input.debounce.250ms="load()" type="number" min="0" placeholder="ID empresa" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500">
                    <button @click="load(true)" class="rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm font-semibold text-slate-100 hover:bg-slate-800">Actualizar</button>
                </div>
            </div>
            <?php if (!$trackingMode): ?>
            <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                <div class="text-xs uppercase tracking-[0.2em] text-slate-400">Bootstrap Tracking</div>
                <template x-if="setupToken">
                    <div class="mt-2 space-y-2">
                        <div class="rounded-xl border border-emerald-700/30 bg-slate-950 px-3 py-2 text-xs break-all text-emerald-200" x-text="setupToken"></div>
                        <div class="text-[11px] text-slate-400">Expira: <span class="text-slate-200" x-text="setupExpiresAt"></span></div>
                    </div>
                </template>
                <template x-if="!setupToken">
                    <div class="mt-2 text-sm text-slate-400">Generá un token para registrar la app móvil con tracking.</div>
                </template>
            </div>
            <?php endif; ?>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-950/70 text-left text-xs uppercase tracking-[0.18em] text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Dispositivo</th>
                            <th class="px-4 py-3">Empresa / Usuario</th>
                            <th class="px-4 py-3">Tracking</th>
                            <th class="px-4 py-3">Icono</th>
                            <th class="px-4 py-3">Última actividad</th>
                            <th class="px-4 py-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-if="loading">
                            <tr><td colspan="6" class="px-4 py-6 text-center text-slate-400">Cargando dispositivos...</td></tr>
                        </template>
                        <template x-if="!loading && items.length === 0">
                            <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">No hay dispositivos registrados.</td></tr>
                        </template>
                        <template x-for="item in items" :key="item.id">
                            <tr class="border-t border-slate-800">
                                <td class="px-4 py-3 align-top">
                                    <div class="font-semibold text-white" x-text="item.device_name || 'Dispositivo'"></div>
                                    <div class="text-xs text-slate-400" x-text="item.device_uuid"></div>
                                    <div class="mt-1 text-xs text-cyan-300" x-text="item.marker_label || '-'"></div>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="text-slate-200" x-text="item.company_name || ('Empresa #' + item.id_empresa)"></div>
                                    <div class="text-xs text-slate-400" x-text="item.user_name || item.login_name || ('Usuario #' + item.id_login)"></div>
                                    <div class="text-xs text-slate-500" x-text="item.platform + (item.app_version ? (' · v' + item.app_version) : '')"></div>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-bold" :class="Number(item.tracking_enabled || 0) === 1 ? 'bg-emerald-500/15 text-emerald-300' : 'bg-slate-500/15 text-slate-300'" x-text="Number(item.tracking_enabled || 0) === 1 ? 'Activo' : 'Pausado'"></span>
                                    <div class="mt-2 text-xs text-slate-400">Estado: <span class="text-slate-200" x-text="item.status || 'offline'"></span></div>
                                    <div class="text-xs text-slate-500">Red: <span x-text="item.network_type || 'N/D'"></span></div>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex items-center gap-2">
                                        <span class="h-4 w-4 rounded-full border border-white/10" :style="`background:${item.icon_color || '#0ea5e9'}`"></span>
                                        <span class="text-slate-200" x-text="item.icon_type || 'auto'"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="text-slate-200" x-text="formatDate(item.last_seen_at)"></div>
                                    <div class="text-xs text-slate-500" x-text="item.last_fix_at ? ('Fix: ' + formatDate(item.last_fix_at)) : 'Sin fix GPS'"></div>
                                </td>
                                <td class="px-4 py-3 align-top text-right">
                                    <div class="flex justify-end gap-2">
                                        <button @click="editItem(item)" class="rounded-lg bg-cyan-500/15 px-3 py-1.5 text-xs font-semibold text-cyan-200 hover:bg-cyan-500/25">Editar</button>
                                        <template x-if="!trackingMode">
                                            <button @click="removeItem(item)" class="rounded-lg bg-red-500/15 px-3 py-1.5 text-xs font-semibold text-red-200 hover:bg-red-500/25">Eliminar</button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div x-show="modalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <div class="w-full max-w-lg rounded-2xl border border-slate-800 bg-slate-900 p-5 shadow-2xl">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-black">Editar dispositivo</h2>
                <button @click="modalOpen=false" class="rounded-lg border border-slate-700 px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800">Cerrar</button>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="mb-1 block text-xs uppercase tracking-[0.18em] text-slate-400">Alias</label>
                    <input x-model="form.marker_label" type="text" maxlength="24" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500">
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs uppercase tracking-[0.18em] text-slate-400">Tipo de icono</label>
                        <select x-model="form.icon_type" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-500">
                            <template x-for="opt in iconTypes" :key="opt">
                                <option :value="opt" x-text="opt"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs uppercase tracking-[0.18em] text-slate-400">Color</label>
                        <input x-model="form.icon_color" type="color" class="h-[44px] w-full rounded-xl border border-slate-700 bg-slate-950 px-2 py-1.5">
                    </div>
                </div>
                <label x-show="!trackingMode" class="flex items-center gap-3 rounded-xl border border-slate-800 bg-slate-950 px-3 py-3">
                    <input x-model="form.tracking_enabled" type="checkbox" class="h-4 w-4 rounded border-slate-600 bg-slate-900 text-cyan-500 focus:ring-cyan-500">
                    <span class="text-sm text-slate-200">Habilitar tracking para este dispositivo</span>
                </label>
            </div>
            <div class="mt-5 flex justify-end gap-2">
                <button @click="modalOpen=false" class="rounded-xl border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-slate-800">Cancelar</button>
                <button @click="saveItem()" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-500">Guardar</button>
            </div>
        </div>
    </div>

    <script>
    function geoConfigApp() {
        return {
            isSupport: <?= $isSupport ? 'true' : 'false' ?>,
            trackingMode: <?= $trackingMode ? 'true' : 'false' ?>,
            loading: false,
            q: '',
            empresaFiltro: '',
            items: [],
            stats: { total: 0, tracking_on: 0, online: 0, companies: 0 },
            setupToken: '',
            setupExpiresAt: '',
            modalOpen: false,
            form: { device_id: 0, marker_label: '', icon_type: 'auto', icon_color: '#0ea5e9', tracking_enabled: false },
            iconTypes: ['auto', 'moto', 'auto_car', 'sedan', 'suv', 'pickup', 'furgon', 'camion'],

            init() {
                this.load();
            },

            async api(url, options = {}) {
                const res = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
                    ...options,
                });
                const raw = await res.text();
                let data = null;
                try {
                    data = raw ? JSON.parse(raw) : {};
                } catch (e) {
                    throw new Error(raw ? raw.slice(0, 220) : 'Respuesta inválida del servidor');
                }
                if (!data.ok) {
                    throw new Error(data.error || 'Error de solicitud');
                }
                return data;
            },

            async load(force = false) {
                this.loading = true;
                try {
                    const params = new URLSearchParams({ action: 'list', q: this.q || '' });
                    if (this.trackingMode) {
                        params.set('online_only', '1');
                    }
                    if (this.isSupport && String(this.empresaFiltro || '').trim() !== '') {
                        params.set('id_empresa', String(this.empresaFiltro).trim());
                    }
                    if (force) {
                        params.set('_ts', String(Date.now()));
                    }
                    const data = await this.api('/public/geolocalizacion-config/api/index.php?' + params.toString(), { method: 'GET' });
                    this.items = data.data.items || [];
                    this.stats = data.data.stats || this.stats;
                } catch (e) {
                    alert(e.message || 'No se pudo cargar');
                } finally {
                    this.loading = false;
                }
            },

            editItem(item) {
                this.form = {
                    device_id: Number(item.id || 0),
                    marker_label: String(item.marker_label || ''),
                    icon_type: String(item.icon_type || 'auto'),
                    icon_color: String(item.icon_color || '#0ea5e9'),
                    tracking_enabled: Number(item.tracking_enabled || 0) === 1
                };
                this.modalOpen = true;
            },

            async saveItem() {
                try {
                    await this.api('/public/geolocalizacion-config/api/index.php?action=save', {
                        method: 'POST',
                    body: JSON.stringify(this.form)
                });
                this.modalOpen = false;
                    await this.load(true);
                } catch (e) {
                    alert(e.message || 'No se pudo guardar');
                }
            },

            async removeItem(item) {
                if (!confirm(`Eliminar ${item.device_name || 'este dispositivo'}?`)) return;
                try {
                    await this.api('/public/geolocalizacion-config/api/index.php?action=delete', {
                        method: 'POST',
                        body: JSON.stringify({ device_id: Number(item.id || 0) })
                    });
                    await this.load(true);
                } catch (e) {
                    alert(e.message || 'No se pudo eliminar');
                }
            },

            async issueSetupToken() {
                try {
                    const data = await this.api('/public/geolocalizacion-config/api/index.php?action=issue_setup_token', { method: 'POST', body: JSON.stringify({}) });
                    this.setupToken = data.data.setup_token || '';
                    this.setupExpiresAt = this.formatDate(data.data.expires_at);
                } catch (e) {
                    alert(e.message || 'No se pudo generar el token');
                }
            },

            formatDate(value) {
                if (!value) return 'N/D';
                const d = new Date(value);
                return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString('es-PY');
            }
        };
    }
    </script>
</body>
</html>
