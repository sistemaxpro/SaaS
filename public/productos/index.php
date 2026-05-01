<?php
/**
 * Productos - Lista estable
 * Rehecho sobre una sola vista con scroll infinito y edicion basica.
 */

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = preg_match('/Mobile|Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $userAgent);
$forceDesktop = isset($_GET['desktop']) || isset($_COOKIE['productos_desktop']);

if ($isMobile && !$forceDesktop && !isset($_GET['no_redirect'])) {
    header('Location: /public/productos/mobile.php');
    exit;
}

if (isset($_GET['desktop'])) {
    setcookie('productos_desktop', '1', time() + 86400 * 30, '/');
}

require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

Permission::requireAccess('app_grid_mercaderias');
$permisos = Permission::getAppPermissions('app_grid_mercaderias');

$id_empresa = Session::get('id_empresa', 169);
$masterPdo = Database::getMasterConnection();
$stmtEmpresa = $masterPdo->prepare("SELECT empresa FROM empresa WHERE id_empresa = ?");
$stmtEmpresa->execute([$id_empresa]);
$empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(SmxI18n::getLocale()) ?>" x-data="productosGridApp()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(t('productos.title')) ?> - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        window.__PERMISOS__ = <?= json_encode($permisos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
                    }
                }
            }
        };
        document.documentElement.classList.add('dark');
    </script>
    <style>
        [x-cloak] { display: none !important; }
        body {
            background:
                radial-gradient(circle at top left, rgba(34, 211, 238, 0.14), transparent 28%),
                linear-gradient(180deg, #0f172a 0%, #020617 100%);
        }
        .panel {
            border: 1px solid rgba(51, 65, 85, 0.9);
            background: rgba(15, 23, 42, 0.88);
            box-shadow: 0 24px 60px rgba(2, 6, 23, 0.35);
        }
        .toolbar-input,
        .toolbar-select {
            background: rgba(2, 6, 23, 0.8);
            border: 1px solid rgba(51, 65, 85, 0.9);
            color: #e2e8f0;
        }
        .toolbar-input::placeholder {
            color: #64748b;
        }
        .table-row:hover {
            background: rgba(8, 145, 178, 0.08);
        }
    </style>
</head>
<body class="min-h-screen font-sans text-slate-100">
<div class="min-h-screen px-4 py-6 sm:px-6 lg:px-8">
    <header class="mx-auto mb-6 flex w-full max-w-7xl items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold tracking-tight text-white"><?= htmlspecialchars(t('productos.title')) ?></h1>
            <p class="mt-1 text-sm text-slate-400"><?= htmlspecialchars((string)($empresa['empresa'] ?? t('common.company'))) ?></p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                    class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-red-500/40 bg-red-500/10 text-red-300 hover:bg-red-500/20">
                <i class="fas fa-arrow-left"></i>
            </button>
            <button x-show="permisos.priv_insert === 'Y'"
                    @click="openLegacyCreate()"
                    class="inline-flex items-center gap-2 rounded-xl bg-cyan-500 px-4 py-3 text-sm font-semibold text-slate-950 hover:bg-cyan-400">
                <i class="fas fa-plus text-xs"></i>
                Nuevo
            </button>
        </div>
    </header>

    <section class="panel mx-auto mb-5 w-full max-w-7xl rounded-3xl p-4 sm:p-5">
        <div class="grid gap-3 lg:grid-cols-[minmax(0,1.2fr),180px,180px,160px,auto]">
            <div class="relative">
                <i class="fas fa-search pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-500"></i>
                <input type="text"
                       x-model="search"
                       @input.debounce.350ms="resetAndLoad()"
                       placeholder="Buscar por codigo, descripcion o codigo de barra"
                       class="toolbar-input h-12 w-full rounded-2xl pl-11 pr-4 text-sm outline-none focus:border-cyan-500">
            </div>
            <select x-model="filtroEstado" @change="resetAndLoad()" class="toolbar-select h-12 rounded-2xl px-4 text-sm outline-none focus:border-cyan-500">
                <option value="1">Solo activos</option>
                <option value="0">Inactivos</option>
                <option value="all">Todos</option>
            </select>
            <select x-model="filtroMarca" @change="resetAndLoad()" class="toolbar-select h-12 rounded-2xl px-4 text-sm outline-none focus:border-cyan-500">
                <option value="">Todas las marcas</option>
                <template x-for="m in catMarcas" :key="'marca-' + m.id">
                    <option :value="m.id" x-text="m.marca"></option>
                </template>
            </select>
            <select x-model="filtroGrupo" @change="resetAndLoad()" class="toolbar-select h-12 rounded-2xl px-4 text-sm outline-none focus:border-cyan-500">
                <option value="">Todos los grupos</option>
                <template x-for="g in catGrupos" :key="'grupo-' + g.id">
                    <option :value="g.id" x-text="g.grupo"></option>
                </template>
            </select>
            <button type="button"
                    @click="clearFilters()"
                    class="inline-flex h-12 items-center justify-center gap-2 rounded-2xl border border-slate-700 bg-slate-950/80 px-4 text-sm font-medium text-slate-200 hover:border-slate-500 hover:text-white">
                <i class="fas fa-filter-circle-xmark text-xs"></i>
                Limpiar
            </button>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3 text-sm text-slate-300">
            <span class="rounded-full border border-cyan-500/30 bg-cyan-500/10 px-3 py-1.5 text-cyan-200">
                Mostrando <strong x-text="formatNumber(visibleProductos.length)"></strong> de <strong x-text="formatNumber(totalRecords)"></strong>
            </span>
            <span class="rounded-full border border-slate-700 bg-slate-950/80 px-3 py-1.5">
                Orden: <strong x-text="sortBy + ' / ' + sortDir"></strong>
            </span>
            <span x-show="loadingMore" class="rounded-full border border-cyan-500/30 bg-cyan-500/10 px-3 py-1.5 text-cyan-200">
                <i class="fas fa-spinner fa-spin mr-1"></i>Cargando 4 mas
            </span>
        </div>
    </section>

    <section class="panel mx-auto w-full max-w-7xl overflow-hidden rounded-3xl">
        <div x-show="loading" class="p-10 text-center">
            <i class="fas fa-spinner fa-spin text-3xl text-cyan-400"></i>
            <p class="mt-3 text-sm text-slate-400">Cargando productos...</p>
        </div>

        <div x-show="!loading" class="overflow-x-auto">
            <table class="w-full min-w-[980px]">
                <thead class="border-b border-slate-700 bg-slate-950/70">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-400"></th>
                        <th @click="setSort('cve_producto')" class="cursor-pointer px-4 py-4 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Codigo</th>
                        <th @click="setSort('desproducto')" class="cursor-pointer px-4 py-4 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Producto</th>
                        <th class="px-4 py-4 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Marca</th>
                        <th class="px-4 py-4 text-left text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Grupo</th>
                        <th @click="setSort('precio_venta')" class="cursor-pointer px-4 py-4 text-right text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Venta</th>
                        <th @click="setSort('saldo')" class="cursor-pointer px-4 py-4 text-right text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Stock</th>
                        <th class="px-4 py-4 text-center text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Estado</th>
                        <th class="px-4 py-4 text-center text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Editar</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <template x-for="(p, idx) in visibleProductos" :key="'prod-' + p.idproducto">
                        <tr class="table-row">
                            <td class="px-4 py-3">
                                <div class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-xl bg-slate-800">
                                    <img x-show="p.foto_url" :src="p.foto_url" class="h-10 w-10 object-cover" @error="$el.style.display='none'">
                                    <i class="fas fa-box text-slate-500"></i>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="font-mono text-sm font-semibold text-white" x-text="p.cve_producto || '-'"></div>
                                <div class="text-[11px] text-slate-500" x-text="p.codigo_barra ? ('CB: ' + p.codigo_barra) : ''"></div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm font-medium text-white" x-text="p.desproducto"></div>
                                <div x-show="idx === visibleProductos.length - 1"
                                     x-init="observeFooter($el)"
                                     class="h-px w-full opacity-0"></div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-300" x-text="p.marca_nombre || '-'"></td>
                            <td class="px-4 py-3 text-sm text-slate-300" x-text="p.grupo_nombre || '-'"></td>
                            <td class="px-4 py-3 text-right text-sm font-semibold text-white" x-text="'Gs. ' + formatMoney(p.precio_venta || 0)"></td>
                            <td class="px-4 py-3 text-right text-sm font-semibold" :class="Number(p.saldo || 0) > 0 ? 'text-emerald-300' : 'text-rose-300'" x-text="formatNumber(p.saldo || 0)"></td>
                            <td class="px-4 py-3 text-center">
                                <span x-show="Number(p.descontinuado || 0) === 1" class="inline-flex rounded-full bg-amber-500/15 px-2.5 py-1 text-xs font-semibold text-amber-300">Descontinuado</span>
                                <span x-show="Number(p.Estado || 0) === 1 && Number(p.descontinuado || 0) !== 1" class="inline-flex rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-semibold text-emerald-300">Activo</span>
                                <span x-show="Number(p.Estado || 0) === 0 && Number(p.descontinuado || 0) !== 1" class="inline-flex rounded-full bg-rose-500/15 px-2.5 py-1 text-xs font-semibold text-rose-300">Inactivo</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <button type="button"
                                        @click="openLegacyEdit(p.idproducto)"
                                        class="inline-flex items-center gap-2 rounded-xl bg-cyan-500 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-cyan-400">
                                    <i class="fas fa-pen text-[10px]"></i>
                                    Editar
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div x-show="!loading && visibleProductos.length === 0" class="p-12 text-center">
            <i class="fas fa-box-open text-4xl text-slate-600"></i>
            <p class="mt-3 text-lg text-slate-300">No se encontraron productos</p>
        </div>

        <div class="border-t border-slate-800 px-5 py-4 text-sm text-slate-400">
            <div class="flex items-center justify-between gap-4">
                <span>Total visible: <strong class="text-slate-200" x-text="formatNumber(visibleProductos.length)"></strong></span>
                <span x-show="!loadingMore && !hasMoreServer && bufferProductos.length === 0 && visibleProductos.length > 0">Fin de la lista</span>
            </div>
        </div>
    </section>
</div>

<div x-show="toast.show" x-cloak class="fixed bottom-5 right-5 z-[60]">
    <div class="rounded-2xl border px-4 py-3 text-sm shadow-2xl"
         :class="toast.type === 'error' ? 'border-rose-500/40 bg-rose-500/10 text-rose-200' : 'border-cyan-500/40 bg-cyan-500/10 text-cyan-100'">
        <span x-text="toast.message"></span>
    </div>
</div>

<script>
function productosGridApp() {
    return {
        idEmpresa: <?= (int)$id_empresa ?>,
        permisos: window.__PERMISOS__ || {},
        API: '/public/productos/api',
        search: '',
        filtroEstado: '1',
        filtroMarca: '',
        filtroGrupo: '',
        sortBy: 'desproducto',
        sortDir: 'ASC',
        serverPage: 1,
        serverPerPage: 20,
        visibleStep: 4,
        totalRecords: 0,
        totalPages: 1,
        hasMoreServer: false,
        loading: true,
        loadingMore: false,
        visibleProductos: [],
        bufferProductos: [],
        catGrupos: [],
        catMarcas: [],
        catModelos: [],
        catReferencias: [],
        footerObserver: null,
        toast: { show: false, message: '', type: 'success' },
        async init() {
            await this.loadCatalogos();
            await this.resetAndLoad();
        },
        async loadCatalogos() {
            const cats = ['grupos', 'marcas', 'modelos', 'referencias'];
            try {
                const responses = await Promise.all(
                    cats.map((tabla) => fetch(`${this.API}/catalogos.php?action=list&tabla=${tabla}&id_empresa=${this.idEmpresa}`).then((r) => r.json()))
                );
                this.catGrupos = Array.isArray(responses[0]?.data) ? responses[0].data : [];
                this.catMarcas = Array.isArray(responses[1]?.data) ? responses[1].data : [];
                this.catModelos = Array.isArray(responses[2]?.data) ? responses[2].data : [];
                this.catReferencias = Array.isArray(responses[3]?.data) ? responses[3].data : [];
            } catch (_) {
                this.showToast('No se pudieron cargar los catalogos', 'error');
            }
        },
        disconnectFooterObserver() {
            if (this.footerObserver) {
                try { this.footerObserver.disconnect(); } catch (_) {}
                this.footerObserver = null;
            }
        },
        observeFooter(el) {
            if (!el) return;
            this.disconnectFooterObserver();
            this.footerObserver = new IntersectionObserver((entries) => {
                if (entries[0]?.isIntersecting) {
                    this.loadNextVisibleChunk();
                }
            }, { root: null, threshold: 0.01 });
            this.footerObserver.observe(el);
        },
        async resetAndLoad() {
            this.disconnectFooterObserver();
            this.serverPage = 1;
            this.totalRecords = 0;
            this.totalPages = 1;
            this.hasMoreServer = false;
            this.visibleProductos = [];
            this.bufferProductos = [];
            await this.fetchServerPage(1, true);
        },
        async fetchServerPage(page, replaceVisible = false) {
            if (page === 1) {
                this.loading = true;
            } else {
                this.loadingMore = true;
            }
            try {
                const params = new URLSearchParams({
                    id_empresa: String(this.idEmpresa),
                    page: String(page),
                    per_page: String(this.serverPerPage),
                    fast: '1',
                    with_stats: '0',
                    search: String(this.search || '').trim(),
                    grupo: String(this.filtroGrupo || ''),
                    marca: String(this.filtroMarca || ''),
                    estado: String(this.filtroEstado || '1'),
                    sort_by: this.sortBy,
                    sort_dir: this.sortDir
                });
                const res = await fetch(`${this.API}/list.php?${params.toString()}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'No se pudo cargar productos');
                const rows = Array.isArray(data.data) ? data.data : [];
                this.serverPage = Number(data.pagination?.page || page);
                this.totalRecords = Number(data.pagination?.total || 0);
                this.totalPages = Number(data.pagination?.total_pages || 1);
                this.hasMoreServer = this.serverPage < this.totalPages;
                if (replaceVisible) {
                    this.visibleProductos = rows;
                    this.bufferProductos = [];
                } else {
                    this.bufferProductos = this.bufferProductos.concat(rows);
                }
            } catch (error) {
                console.error(error);
                this.showToast('Error al cargar productos', 'error');
            } finally {
                this.loading = false;
                this.loadingMore = false;
            }
        },
        async loadNextVisibleChunk() {
            if (this.loading || this.loadingMore) return;
            if (this.bufferProductos.length < this.visibleStep && this.hasMoreServer) {
                await this.fetchServerPage(this.serverPage + 1, false);
            }
            if (this.bufferProductos.length > 0) {
                const chunk = this.bufferProductos.splice(0, this.visibleStep);
                this.visibleProductos = this.visibleProductos.concat(chunk);
            }
        },
        setSort(col) {
            if (this.sortBy === col) {
                this.sortDir = this.sortDir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                this.sortBy = col;
                this.sortDir = 'ASC';
            }
            this.resetAndLoad();
        },
        clearFilters() {
            this.search = '';
            this.filtroEstado = '1';
            this.filtroMarca = '';
            this.filtroGrupo = '';
            this.sortBy = 'desproducto';
            this.sortDir = 'ASC';
            this.resetAndLoad();
        },
        openLegacyCreate() {
            window.location.href = `/public/productos/legacy_index.php?desktop=1&no_redirect=1`;
        },
        openLegacyEdit(id) {
            window.location.href = `/public/productos/legacy_index.php?desktop=1&no_redirect=1&edit_id=${encodeURIComponent(id)}`;
        },
        showToast(message, type = 'success') {
            this.toast = { show: true, message, type };
            setTimeout(() => { this.toast.show = false; }, 2600);
        },
        formatMoney(value) {
            return new Intl.NumberFormat('es-PY', { maximumFractionDigits: 0 }).format(Number(value || 0));
        },
        formatNumber(value) {
            return new Intl.NumberFormat('es-PY', { maximumFractionDigits: 2 }).format(Number(value || 0));
        }
    };
}
</script>
</body>
</html>
