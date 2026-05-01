<?php
/**
 * Módulo Gestión de Gastos - Desktop
 */

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = preg_match('/Mobile|Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $userAgent);
$forceDesktop = isset($_GET['desktop']) || isset($_COOKIE['gastos_desktop']);

if ($isMobile && !$forceDesktop && !isset($_GET['no_redirect'])) {
    header('Location: /public/gastos/mobile.php');
    exit;
}

if (isset($_GET['desktop'])) {
    setcookie('gastos_desktop', '1', time() + 86400 * 30, '/');
}

require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

// Usamos permiso de cajas para habilitar el módulo de gastos sin cambios extra en catálogo de permisos.
Permission::requireAccess('app_grid_caja');
$permisos = Permission::getAppPermissions('app_grid_caja');

$id_empresa = Session::get('id_empresa', 169);
$masterPdo = Database::getMasterConnection();
$stmtE = $masterPdo->prepare("SELECT empresa FROM empresa WHERE id_empresa = ?");
$stmtE->execute([$id_empresa]);
$empresaNombre = $stmtE->fetchColumn() ?: 'Empresa';
?>
<!DOCTYPE html>
<html lang="es" x-data="gastosApp()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gastos - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } }
        };
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-slate-900 min-h-screen font-sans antialiased">
<script>window.__PERMISOS__ = <?= json_encode($permisos) ?>;</script>

<header class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 sticky top-0 z-30">
    <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                    class="w-10 h-10 rounded-lg bg-red-50 border border-red-200 text-red-600 flex items-center justify-center">
                <i class="fas fa-arrow-left"></i>
            </button>
            <div>
                <h1 class="text-xl font-bold text-gray-900 dark:text-white"><i class="fas fa-file-invoice-dollar text-emerald-600 mr-2"></i>Gestión de Gastos</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($empresaNombre) ?></p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="mobile.php" class="px-3 py-2 rounded-lg bg-gray-100 dark:bg-slate-700 text-xs text-gray-600 dark:text-gray-300">
                <i class="fas fa-mobile-alt mr-1"></i>Modo móvil
            </a>
        </div>
    </div>
</header>

<main class="max-w-7xl mx-auto px-6 py-6 space-y-6">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
            <p class="text-xs text-gray-500">Total Gastos</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-white" x-text="stats.cantidad"></p>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
            <p class="text-xs text-emerald-600">Monto del Mes</p>
            <p class="text-2xl font-bold text-emerald-600" x-text="formatMoney(stats.monto_mes)"></p>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
            <p class="text-xs text-indigo-600">Monto Histórico</p>
            <p class="text-2xl font-bold text-indigo-600" x-text="formatMoney(stats.monto_total)"></p>
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <section class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 xl:col-span-1">
            <h2 class="font-bold text-gray-900 dark:text-white mb-4">Nuevo gasto</h2>
            <div class="space-y-3">
                <div>
                    <label class="text-xs text-gray-500 dark:text-gray-400">Concepto *</label>
                    <input x-model="form.concepto" type="text" placeholder="Ej: Luz oficina"
                           class="mt-1 w-full px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500">
                </div>
                <div>
                    <label class="text-xs text-gray-500 dark:text-gray-400">Monto *</label>
                    <input x-model="form.monto" type="number" step="0.01" min="0" placeholder="35000"
                           class="mt-1 w-full px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500">
                </div>
                <div>
                    <label class="text-xs text-gray-500 dark:text-gray-400">Pagado por</label>
                    <select x-model="form.pagado_por_tipo"
                            class="mt-1 w-full px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600 text-gray-900 dark:text-white">
                        <template x-for="t in tiposPago" :key="t"><option :value="t" x-text="t"></option></template>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-gray-500 dark:text-gray-400">Detalle de pago</label>
                    <input x-model="form.pagado_por_detalle" list="sugeridosPagadoPor" type="text" placeholder="Caja, Tarjeta Continental, Proveedor X"
                           class="mt-1 w-full px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500">
                    <datalist id="sugeridosPagadoPor">
                        <template x-for="item in pagadoPorSugeridos" :key="item"><option :value="item"></option></template>
                    </datalist>
                </div>
                <div>
                    <label class="text-xs text-gray-500 dark:text-gray-400">Caja (opcional)</label>
                    <select x-model="form.id_caja"
                            class="mt-1 w-full px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600 text-gray-900 dark:text-white">
                        <option value="">Sin caja</option>
                        <template x-for="c in cajas" :key="c.id_caja"><option :value="c.id_caja" x-text="c.caja"></option></template>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-gray-500 dark:text-gray-400">Observación</label>
                    <textarea x-model="form.observacion" rows="2" class="mt-1 w-full px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500"></textarea>
                </div>
                <button @click="registrar()" :disabled="saving || permisos.priv_insert !== 'Y'"
                        class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-sm font-semibold disabled:opacity-50">
                    <span x-show="!saving"><i class="fas fa-save mr-1"></i> Guardar gasto</span>
                    <span x-show="saving">Guardando...</span>
                </button>
            </div>
        </section>

        <section class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 xl:col-span-2">
            <div class="flex flex-wrap gap-2 mb-4">
                <input x-model.debounce.350ms="filtros.search" @input="page=1; loadGastos()" type="text" placeholder="Buscar concepto, detalle..."
                       class="flex-1 min-w-[220px] px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500">
                <input x-model="filtros.desde" @change="page=1; loadGastos()" type="date" class="px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600 text-gray-900 dark:text-white dark:[color-scheme:dark]">
                <input x-model="filtros.hasta" @change="page=1; loadGastos()" type="date" class="px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600 text-gray-900 dark:text-white dark:[color-scheme:dark]">
                <select x-model="filtros.pagado_por_tipo" @change="page=1; loadGastos()" class="px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600 text-gray-900 dark:text-white">
                    <option value="">Todos</option>
                    <template x-for="t in tiposPago" :key="t"><option :value="t" x-text="t"></option></template>
                </select>
                <select x-model="filtros.estado" @change="page=1; loadGastos()" class="px-3 py-2.5 border rounded-xl bg-white dark:bg-slate-900 border-gray-300 dark:border-slate-600 text-gray-900 dark:text-white">
                    <option value="ACTIVO">Activos</option>
                    <option value="ANULADO">Anulados</option>
                    <option value="TODOS">Todos</option>
                </select>
            </div>

            <div x-show="loading" class="py-12 text-center text-gray-500">Cargando...</div>

            <div x-show="!loading" class="space-y-3">
                <template x-for="g in gastos" :key="g.id_gasto">
                    <div class="border rounded-xl border-gray-200 dark:border-slate-700 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-gray-900 dark:text-white" x-text="g.concepto"></h3>
                                <p class="text-xs text-gray-500 mt-1" x-text="formatDate(g.fecha)"></p>
                                <p class="text-xs text-gray-500" x-text="(g.pagado_por_tipo || '') + (g.pagado_por_detalle ? ' · ' + g.pagado_por_detalle : '')"></p>
                                <p class="text-xs text-gray-500" x-text="g.nombre_caja ? ('Caja: ' + g.nombre_caja) : ''"></p>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-red-600" x-text="formatMoney(g.monto)"></p>
                                <span class="text-[11px] px-2 py-0.5 rounded-full"
                                      :class="g.estado === 'ACTIVO' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-600'"
                                      x-text="g.estado"></span>
                            </div>
                        </div>
                        <div class="mt-3 flex justify-end" x-show="g.estado === 'ACTIVO' && permisos.priv_delete === 'Y'">
                            <button @click="anular(g)" class="px-3 py-1.5 rounded-lg bg-red-50 text-red-600 text-xs font-semibold">
                                <i class="fas fa-ban mr-1"></i>Anular
                            </button>
                        </div>
                    </div>
                </template>

                <div x-show="gastos.length === 0" class="py-10 text-center text-gray-400">Sin gastos registrados</div>
            </div>

            <div class="mt-4 flex items-center justify-between" x-show="pages > 1">
                <button @click="if(page>1){page--;loadGastos();}" class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700">Anterior</button>
                <p class="text-xs text-gray-500 dark:text-gray-400" x-text="'Página ' + page + ' de ' + pages"></p>
                <button @click="if(page<pages){page++;loadGastos();}" class="px-3 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-slate-700">Siguiente</button>
            </div>
        </section>
    </div>
</main>

<script>
function gastosApp() {
    return {
        idEmpresa: <?= (int) $id_empresa ?>,
        isDark: document.documentElement.classList.contains('dark'),
        permisos: window.__PERMISOS__ || {},
        loading: false,
        saving: false,
        gastos: [],
        page: 1,
        pages: 1,
        stats: { monto_total: 0, monto_mes: 0, cantidad: 0 },
        cajas: [],
        tiposPago: ['CAJA', 'TARJETA', 'TRANSFERENCIA', 'PROVEEDOR', 'OTRO'],
        pagadoPorSugeridos: [],
        offlineQueue: [],
        filtros: { search: '', desde: '', hasta: '', pagado_por_tipo: '', estado: 'ACTIVO' },
        form: {
            concepto: '',
            monto: '',
            pagado_por_tipo: 'CAJA',
            pagado_por_detalle: '',
            id_caja: '',
            observacion: '',
        },

        offlineKey(suffix) {
            return `smx:gastos:desktop:${this.idEmpresa || 'na'}:${suffix}`;
        },
        readStorage(key, fallback = null) {
            return window.SmxOfflineDb?.getSync(key, fallback) ?? fallback;
        },
        writeStorage(key, data) {
            return window.SmxOfflineDb?.set(key, data);
        },
        buildOfflineGasto(payload) {
            const now = new Date();
            return {
                id_gasto: `offline-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                concepto: payload.concepto,
                monto: Number(payload.monto || 0),
                pagado_por_tipo: payload.pagado_por_tipo || 'CAJA',
                pagado_por_detalle: payload.pagado_por_detalle || '',
                nombre_caja: this.cajas.find(c => Number(c.id_caja) === Number(payload.id_caja || 0))?.caja || '',
                observacion: payload.observacion || '',
                fecha: now.toISOString(),
                estado: 'ACTIVO',
                offline_pending: true,
            };
        },
        mergeQueuedGastos(items) {
            const list = Array.isArray(items) ? items.slice() : [];
            const offlineItems = this.offlineQueue
                .map(entry => entry.preview)
                .filter(Boolean)
                .filter((g) => {
                    if ((this.filtros.estado || 'ACTIVO') === 'ANULADO') return false;
                    if (this.filtros.pagado_por_tipo && String(g.pagado_por_tipo || '') !== String(this.filtros.pagado_por_tipo)) return false;
                    const haystack = `${g.concepto || ''} ${g.pagado_por_detalle || ''}`.toLowerCase();
                    if (this.filtros.search && !haystack.includes(String(this.filtros.search).toLowerCase())) return false;
                    const fecha = String(g.fecha || '').slice(0, 10);
                    if (this.filtros.desde && fecha < this.filtros.desde) return false;
                    if (this.filtros.hasta && fecha > this.filtros.hasta) return false;
                    return true;
                });
            const merged = [...offlineItems, ...list];
            const seen = new Set();
            return merged.filter((row) => {
                const key = String(row.id_gasto || '');
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            });
        },
        async syncOfflineQueue() {
            if (!navigator.onLine || !this.offlineQueue.length) return;
            const pending = [...this.offlineQueue];
            const remaining = [];
            for (const entry of pending) {
                try {
                    const res = await fetch('./api/registrar.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(entry.payload),
                    });
                    const data = await res.json();
                    if (!data.ok) throw new Error(data.error || 'No se pudo sincronizar gasto offline');
                } catch (_) {
                    remaining.push(entry);
                }
            }
            this.offlineQueue = remaining;
            this.writeStorage(this.offlineKey('queue'), remaining);
            await this.loadCatalogos();
            await this.loadGastos();
        },

        async init() {
            await (window.SmxOfflineDb?.ready || Promise.resolve());
            this.offlineQueue = this.readStorage(this.offlineKey('queue'), []);
            window.addEventListener('online', () => this.syncOfflineQueue());
            await this.loadCatalogos();
            await this.loadGastos();
            await this.syncOfflineQueue();
        },

        async loadCatalogos() {
            try {
                const res = await fetch('./api/catalogos.php');
                const data = await res.json();
                if (!data.ok) return;
                this.cajas = data.cajas || [];
                this.pagadoPorSugeridos = data.pagado_por_sugeridos || [];
                this.tiposPago = data.tipos_pago || this.tiposPago;
                this.writeStorage(this.offlineKey('catalogos'), {
                    cajas: this.cajas,
                    pagadoPorSugeridos: this.pagadoPorSugeridos,
                    tiposPago: this.tiposPago,
                });
            } catch (e) {
                const cached = this.readStorage(this.offlineKey('catalogos'));
                if (cached) {
                    this.cajas = cached.cajas || [];
                    this.pagadoPorSugeridos = cached.pagadoPorSugeridos || [];
                    this.tiposPago = cached.tiposPago || this.tiposPago;
                    return;
                }
                console.error(e);
            }
        },

        async loadGastos() {
            this.loading = true;
            const qs = new URLSearchParams({
                page: this.page,
                per_page: 20,
                search: this.filtros.search,
                desde: this.filtros.desde,
                hasta: this.filtros.hasta,
                pagado_por_tipo: this.filtros.pagado_por_tipo,
                estado: this.filtros.estado,
            });
            try {
                const res = await fetch('./api/list.php?' + qs.toString());
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'Error');
                this.gastos = this.mergeQueuedGastos(data.data || []);
                this.stats = data.stats || this.stats;
                this.pages = data.pages || 1;
                this.writeStorage(this.offlineKey(`list:${qs.toString()}`), {
                    gastos: data.data || [],
                    stats: this.stats,
                    pages: this.pages,
                });
            } catch (e) {
                const cached = this.readStorage(this.offlineKey(`list:${qs.toString()}`));
                if (cached) {
                    this.gastos = this.mergeQueuedGastos(cached.gastos || []);
                    this.stats = cached.stats || this.stats;
                    this.pages = cached.pages || 1;
                    alert('Mostrando gastos desde cache offline');
                } else {
                    alert(e.message || 'No se pudo cargar gastos');
                }
            } finally {
                this.loading = false;
            }
        },

        async registrar() {
            if ((this.form.concepto || '').trim() === '') return alert('Concepto obligatorio');
            if (Number(this.form.monto || 0) <= 0) return alert('Monto inválido');

            this.saving = true;
            try {
                const payload = {
                    concepto: this.form.concepto,
                    monto: Number(this.form.monto),
                    pagado_por_tipo: this.form.pagado_por_tipo,
                    pagado_por_detalle: this.form.pagado_por_detalle,
                    id_caja: this.form.id_caja ? Number(this.form.id_caja) : 0,
                    observacion: this.form.observacion,
                    origen: 'MANUAL',
                };
                if (!navigator.onLine) {
                    this.offlineQueue.unshift({ payload, preview: this.buildOfflineGasto(payload) });
                    this.writeStorage(this.offlineKey('queue'), this.offlineQueue);
                    this.form.concepto = '';
                    this.form.monto = '';
                    this.form.pagado_por_detalle = '';
                    this.form.observacion = '';
                    await this.loadGastos();
                    alert('Gasto guardado offline. Se sincronizará al volver internet.');
                    return;
                }
                const res = await fetch('./api/registrar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'No se pudo guardar');
                this.form.concepto = '';
                this.form.monto = '';
                this.form.pagado_por_detalle = '';
                this.form.observacion = '';
                await this.loadGastos();
                await this.loadCatalogos();
                alert(data.msg || 'Gasto registrado');
            } catch (e) {
                alert(e.message || 'Error al guardar');
            } finally {
                this.saving = false;
            }
        },

        async anular(g) {
            if (!confirm(`¿Anular gasto #${g.id_gasto}?`)) return;
            try {
                const res = await fetch('./api/anular.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id_gasto: Number(g.id_gasto) }),
                });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || 'No se pudo anular');
                await this.loadGastos();
            } catch (e) {
                alert(e.message || 'Error al anular');
            }
        },

        formatMoney(v) {
            return new Intl.NumberFormat('es-PY', { style: 'currency', currency: 'PYG', maximumFractionDigits: 0 }).format(Number(v || 0));
        },
        formatDate(v) {
            const d = new Date(v);
            if (Number.isNaN(d.getTime())) return v || '';
            return d.toLocaleString('es-PY');
        },
    };
}
</script>
</body>
</html>
