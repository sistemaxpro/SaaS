<?php
/**
 * Gestión de Productos - Desktop
 * CRUD completo con catálogos, códigos de barra, stock e importación
 * Color accent: Cyan
 */

// Modo desktop siempre activo; la vista móvil quedó deprecada.

if (isset($_GET['desktop'])) {
    setcookie('productos_desktop', '1', time() + 86400 * 30, '/');
}

require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

Permission::requireAccess('app_grid_mercaderias');
$permisos = Permission::getAppPermissions('app_grid_mercaderias');

$id_empresa = Session::get('id_empresa', 169);
$id_login = Session::get('id_login');
$id_sucursal = (int)Session::get('id_sucursal', 0);

$masterPdo = Database::getMasterConnection();
$stmtEmpresa = $masterPdo->prepare("SELECT * FROM empresa WHERE id_empresa = ?");
$stmtEmpresa->execute([$id_empresa]);
$empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Productos';
?>
<!DOCTYPE html>
<html lang="es" x-data="productosApp()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - SistemaX</title>
    
    <link rel="stylesheet" href="/public/assets/tailwind.css">
    <script defer src="/public/productos/legacy_app.js"></script>
    <script defer src="/public/assets/vendor/alpine.min.js"></script>
    <script>
        window.__ensureFontAwesome = function(){
            if (window.__fontAwesomeReady) return window.__fontAwesomeReady;
            window.__fontAwesomeReady = new Promise(function(resolve){
                if (document.querySelector('link[data-sx-fontawesome="1"]')) { resolve(); return; }
                var l = document.createElement('link');
                l.rel = 'stylesheet';
                l.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css';
                l.setAttribute('data-sx-fontawesome', '1');
                l.onload = resolve;
                l.onerror = resolve;
                document.head.appendChild(l);
            });
            return window.__fontAwesomeReady;
        };
    </script>
    <link rel="stylesheet" href="/public/_lib/ag-grid/ag-grid-enterprise/package/styles/ag-grid.css">
    <link rel="stylesheet" href="/public/_lib/ag-grid/ag-grid-enterprise/package/styles/ag-theme-quartz.css">
    <script src="/public/assets/js/ag-grid-locale.js"></script>
    <script>
        window.__agGridReady = new Promise(function(resolve){
            if (window.agGrid) { resolve(); return; }
            const s = document.createElement('script');
            s.src = '/public/_lib/ag-grid/ag-grid-enterprise/package/dist/ag-grid-enterprise.min.noStyle.js';
            s.onload = function(){
                const l = document.createElement('script');
                l.src = '/public/_lib/ag-grid/license.js';
                l.onload = resolve;
                document.head.appendChild(l);
            };
            document.head.appendChild(s);
        });
    </script>
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>
    
    <style>
        [x-cloak] { display: none !important; }
        body.productos-legacy {
            font-size: 12px;
            font-weight: 300;
            line-height: 1.35;
            letter-spacing: 0.01em;
        }
        body.productos-legacy .text-xs,
        body.productos-legacy .text-sm,
        body.productos-legacy .text-base,
        body.productos-legacy .text-lg {
            font-size: 12px !important;
        }
        body.productos-legacy .font-medium,
        body.productos-legacy .font-semibold,
        body.productos-legacy .font-bold {
            font-weight: 400 !important;
        }
        .table-row:hover { background: rgba(6, 182, 212, 0.05); }
        .dark .table-row:hover { background: rgba(6, 182, 212, 0.1); }
        .productos-grid td,
        .productos-grid th {
            font-size: 13px;
            font-weight: 300;
        }
        .productos-grid th,
        .productos-grid td {
            padding-top: 0.4rem;
            padding-bottom: 0.4rem;
        }
        .productos-grid th:nth-child(even),
        .productos-grid td:nth-child(even) {
            background: rgba(255, 255, 255, 0.03);
        }
        .dark .productos-grid th:nth-child(even),
        .dark .productos-grid td:nth-child(even) {
            background: rgba(255, 255, 255, 0.02);
        }
        .tab-active { border-bottom: 2px solid #0891b2; color: #0891b2; }
        .dark .tab-active { color: #22d3ee; border-color: #22d3ee; }
        .ag-theme-quartz,
        .ag-theme-quartz-dark {
            --ag-font-family: Inter, sans-serif;
            --ag-font-size: 13px;
            --ag-row-height: 42px;
            --ag-header-height: 44px;
            --ag-border-color: rgba(148, 163, 184, 0.18);
            --ag-row-border-color: rgba(148, 163, 184, 0.14);
            --ag-background-color: transparent;
            --ag-odd-row-background-color: transparent;
            --ag-header-background-color: rgba(248, 250, 252, 0.96);
            --ag-row-hover-color: rgba(8, 145, 178, 0.08);
            --ag-selected-row-background-color: rgba(8, 145, 178, 0.1);
            --ag-checkbox-checked-color: #0891b2;
            --ag-range-selection-border-color: #0891b2;
            --ag-input-focus-border-color: #0891b2;
            --ag-menu-background-color: rgba(255, 255, 255, 0.98);
            --ag-popup-shadow: 0 20px 45px rgba(15, 23, 42, 0.18);
            --ag-card-shadow: 0 20px 45px rgba(15, 23, 42, 0.18);
        }
        .ag-theme-quartz-dark {
            --ag-border-color: rgba(71, 85, 105, 0.45);
            --ag-row-border-color: rgba(71, 85, 105, 0.3);
            --ag-header-background-color: rgba(30, 41, 59, 0.94);
            --ag-foreground-color: rgb(226, 232, 240);
            --ag-secondary-foreground-color: rgb(148, 163, 184);
            --ag-row-hover-color: rgba(8, 145, 178, 0.12);
            --ag-selected-row-background-color: rgba(8, 145, 178, 0.16);
            --ag-menu-background-color: rgba(15, 23, 42, 0.98);
            --ag-popup-shadow: 0 22px 50px rgba(2, 6, 23, 0.55);
            --ag-card-shadow: 0 22px 50px rgba(2, 6, 23, 0.55);
        }
        .ag-theme-quartz .ag-side-bar,
        .ag-theme-quartz-dark .ag-side-bar {
            border-left: 1px solid rgba(148, 163, 184, 0.16);
            background: inherit;
        }
        .ag-theme-quartz .ag-tool-panel-wrapper,
        .ag-theme-quartz-dark .ag-tool-panel-wrapper {
            background: inherit;
        }
        .productos-grid-shell {
            height: calc(100vh - 215px);
            min-height: 520px;
        }
        .productos-ag-grid {
            width: 100%;
            height: 100%;
            border-radius: 1rem;
            overflow: hidden;
        }
        .productos-ag-grid .ag-row { font-weight: 300; }
        .productos-ag-grid .prod-stack {
            display: flex;
            flex-direction: column;
            gap: 2px;
            line-height: 1.15;
            padding: 3px 0;
        }
        .productos-ag-grid .prod-stack .main { font-weight: 400; }
        .productos-ag-grid .prod-stack .meta { font-size: 11px; color: rgb(100 116 139); }
        .dark .productos-ag-grid .prod-stack .meta { color: rgb(148 163 184); }
        .productos-ag-grid .stock-negative { color: #ef4444; font-weight: 400; }
        .productos-ag-grid .stock-low { color: #f59e0b; font-weight: 400; }
        .productos-ag-grid .stock-ok { color: #10b981; font-weight: 400; }
        .productos-ag-grid .action-btn {
            min-height: 34px;
            min-width: 34px;
            border-radius: 10px;
        }
        input[type="number"]::-webkit-inner-spin-button,
        input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type="number"] { -moz-appearance: textfield; }
        .fade-in { animation: fadeIn 0.2s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-900 min-h-screen font-sans productos-legacy" @paste.window="onPasteProductImage($event)">
<script>
window.__PERMISOS__ = <?= json_encode($permisos) ?>;
window.__PRODUCTOS_CONFIG__ = {
    idEmpresa: <?= (int)$id_empresa ?>,
    idLogin: <?= (int)$id_login ?>,
    idSucursal: <?= (int)$id_sucursal ?>
};
</script>
    
    <div class="w-full px-4 sm:px-6 lg:px-8 py-6">
        <!-- ============ TABLA ============ -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
            <!-- Loading -->
            <div x-show="loading" class="p-8 text-center">
                <i class="fas fa-spinner fa-spin text-3xl text-cyan-500 mb-3"></i>
                <p class="text-gray-500 dark:text-gray-400">Cargando productos...</p>
            </div>

            <div x-show="!loading" class="px-3 py-3 bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
                <div class="rounded-2xl bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 shadow-sm overflow-hidden">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 border-b border-gray-200 dark:border-slate-700">
                        <div class="min-w-0">
                            <h1 class="text-lg sm:text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                                <i class="fas fa-boxes text-cyan-600 dark:text-cyan-400"></i> Productos
                            </h1>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($empresa['empresa'] ?? 'Empresa') ?></p>
                        </div>
                        <div class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">
                            Mostrando <span x-text="((currentPage-1)*perPage)+1"></span> - <span x-text="Math.min(currentPage*perPage, totalRecords)"></span> de <span x-text="totalRecords"></span>
                            <span x-show="selectedCount > 0" class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-300">
                                <span x-text="selectedCount"></span> seleccionados
                            </span>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <button x-show="permisos.priv_insert === 'Y'" @click="nuevoProducto()"
                                    class="inline-flex items-center gap-2 px-4 py-2 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg font-medium transition-colors active:scale-95">
                                <i class="fas fa-plus"></i>
                                <span class="hidden sm:inline">Nuevo</span>
                            </button>
                        </div>
                    </div>

                    <div class="p-4 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-3 text-xs sm:text-sm text-gray-500 dark:text-gray-400">
                            <div class="flex items-center gap-2">
                                <span class="whitespace-nowrap">Registros por página</span>
                                <select x-model.number="perPage" @change="setPerPage(perPage)"
                                        class="px-2 py-1 rounded-lg bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 text-xs sm:text-sm text-gray-700 dark:text-gray-200">
                                    <template x-for="n in perPageOptions" :key="n">
                                        <option :value="n" x-text="n"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="flex items-center gap-2">
                                <button @click="prevPage()" :disabled="currentPage===1"
                                        :class="currentPage===1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-200 dark:hover:bg-slate-600'"
                                        class="px-3 py-1 rounded-lg bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-300">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <span class="px-3 py-1 text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap">
                                    Pág. <span x-text="currentPage"></span> de <span x-text="totalPages"></span>
                                </span>
                                <button @click="nextPage()" :disabled="currentPage===totalPages"
                                        :class="currentPage===totalPages ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-200 dark:hover:bg-slate-600'"
                                        class="px-3 py-1 rounded-lg bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-300">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>

                        <div class="relative max-w-3xl">
                            <div class="flex items-center gap-3 px-4 py-3 rounded-2xl bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 shadow-sm focus-within:ring-2 focus-within:ring-cyan-500/30">
                                <i class="fas fa-search text-gray-400"></i>
                                <input type="text"
                                       x-model="searchQuery"
                                       @input="scheduleSearch()"
                                       @focus="showSearchHints = searchHints.length > 0"
                                       @keydown.enter.prevent="runSearch()"
                                       @keydown.escape="showSearchHints = false"
                                       placeholder="Buscar por código, producto, marca, grupo, modelo, color, precio, stock o estado"
                                       class="w-full bg-transparent border-0 outline-none text-sm text-gray-900 dark:text-white placeholder:text-gray-400">
                                <button x-show="searchHasValue" @click="searchQuery=''; clearFilters()"
                                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>

                            <div x-show="showSearchHints && searchHints.length > 0" x-cloak
                                 class="absolute z-30 mt-2 w-full rounded-2xl bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 shadow-xl overflow-hidden">
                                <template x-for="hint in searchHints" :key="hint.idproducto">
                                    <button type="button" @click="applySearchHint(hint)" class="w-full px-4 py-3 text-left hover:bg-cyan-50 dark:hover:bg-slate-700 border-b border-gray-100 dark:border-slate-700 last:border-b-0">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white" x-text="hint.label"></div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="hint.meta"></div>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div x-show="!loading" x-cloak class="md:hidden p-3 space-y-2">
                <template x-for="p in visibleProductos" :key="'m-' + p.idproducto">
                    <button @click="editarProducto(p.idproducto)"
                            class="w-full text-left rounded-2xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-3 shadow-sm active:scale-[0.99] transition-transform">
                        <div class="flex items-start gap-3">
                            <div class="w-12 h-12 rounded-2xl bg-gray-100 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 flex items-center justify-center shrink-0 overflow-hidden">
                                <img x-show="p.foto_url" :src="p.foto_url" class="w-full h-full object-cover" @error="$el.style.display='none'">
                                <i x-show="!p.foto_url" class="fas fa-box text-gray-400"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate" x-text="capitalizeText(p.desproducto)"></p>
                                        <p class="text-[10px] text-gray-500 dark:text-gray-400 font-mono mt-0.5" x-text="capitalizeText(p.cve_producto)"></p>
                                    </div>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-gray-100 dark:bg-slate-700 text-gray-500 dark:text-gray-300" x-text="p.grupo_nombre || 'Sin grupo'"></span>
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-3">
                                    <div>
                                        <p class="text-[10px] uppercase tracking-[0.14em] text-gray-400">Precio</p>
                                        <p class="text-sm font-semibold text-gray-900 dark:text-white" x-text="formatMoney(precioMaximo(p))"></p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-[10px] uppercase tracking-[0.14em] text-gray-400">Stock</p>
                                        <p class="text-sm font-semibold" :class="stockClass(p)" x-text="formatNumber(stockSesion(p))"></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </button>
                </template>
                <div x-show="visibleProductos.length === 0 && !loading" class="py-10 text-center rounded-2xl border border-dashed border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800">
                    <i class="fas fa-boxes text-4xl text-gray-300 dark:text-slate-600"></i>
                    <p class="mt-3 text-gray-500 dark:text-gray-400 text-sm">No se encontraron productos</p>
                </div>
            </div>

            <div x-show="!loading" x-cloak class="hidden md:block px-3 pb-3">
                <div class="productos-grid-shell">
                    <div x-ref="productosGrid" :class="isDark ? 'ag-theme-quartz-dark' : 'ag-theme-quartz'" class="productos-ag-grid"></div>
                </div>
                <div x-show="!loading && visibleProductos.length === 0" class="p-12 text-center">
                    <i class="fas fa-boxes text-5xl text-gray-300 dark:text-slate-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400 text-lg">No se encontraron productos</p>
                    <p class="text-gray-400 dark:text-gray-500 text-sm mt-1">Intenta con otros filtros o crea uno nuevo</p>
                </div>
            </div>
            
        </div>
    </div>
    
    <!-- ============ MODAL: CREAR/EDITAR PRODUCTO ============ -->
    <div x-show="showForm" x-cloak class="fixed inset-0 z-50 overflow-y-auto"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div class="flex items-center justify-center min-h-screen px-4 py-4">
            <div class="fixed inset-0 bg-black/60" @click="closeForm()"></div>
            
            <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-[80vw] h-[80vh] max-w-none mx-2 overflow-hidden fade-in flex flex-col"
                  @click.stop>
                <!-- Header modal -->
                <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between bg-gradient-to-r from-cyan-600 to-cyan-700">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <i class="fas" :class="form.idproducto ? 'fa-edit' : 'fa-plus-circle'"></i>
                        <span x-text="form.idproducto ? 'Editar Producto' : 'Nuevo Producto'"></span>
                    </h3>
                    <div class="flex items-center gap-2">
                        <button x-show="form.idproducto" @click="abrirAjustarInventarioForm()"
                                class="px-3 py-2 rounded-lg bg-emerald-500/90 hover:bg-emerald-500 text-white text-sm font-medium transition-colors">
                            Ajustar Inventario
                        </button>
                        <button x-show="form.idproducto" @click="abrirTransferenciaInventarioForm()"
                                class="px-3 py-2 rounded-lg bg-violet-500/90 hover:bg-violet-500 text-white text-sm font-medium transition-colors">
                            Transferencia entre sucursales
                        </button>
                        <button x-show="form.idproducto" @click="anularProductoForm()"
                                class="px-3 py-2 rounded-lg bg-white/15 hover:bg-white/25 text-white text-sm font-medium transition-colors">
                            Anular
                        </button>
                        <button x-show="form.idproducto" @click="descontinuarProductoForm()"
                                class="px-3 py-2 rounded-lg bg-amber-500/90 hover:bg-amber-500 text-white text-sm font-medium transition-colors">
                            Descontinuar
                        </button>
                        <button @click="closeForm()" class="w-10 h-10 rounded-lg hover:bg-white/20 flex items-center justify-center text-white/80 hover:text-white">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Tabs -->
                <div class="px-6 border-b border-gray-200 dark:border-slate-700 flex gap-0 overflow-x-auto">
                    <template x-for="tab in formTabs" :key="tab.key">
                        <button @click="formTab = tab.key"
                                :class="formTab === tab.key ? 'tab-active font-semibold' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                                class="px-4 py-3 text-sm whitespace-nowrap border-b-2 border-transparent transition-colors flex items-center gap-2">
                            <i :class="tab.icon"></i>
                            <span x-text="tab.label"></span>
                        </button>
                    </template>
                </div>
                
                <!-- Body -->
                <div class="px-6 py-5 flex-1 min-h-0 overflow-y-auto">
                    
                    <!-- TAB: Datos Generales -->
                    <div x-show="formTab === 'general'" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Código</label>
                                <input type="text" x-model="form.cve_producto" placeholder="Auto-generado" 
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 focus:border-transparent font-mono">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Descripción *</label>
                                <input type="text" x-model="form.desproducto" required
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 focus:border-transparent">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Referencia</label>
                                    <button type="button" @click="openCatalogTab('referencias')" class="text-[11px] text-cyan-600 dark:text-cyan-400 hover:underline">Gestionar</button>
                                </div>
                                <select x-model="form.referencia"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm">
                                    <option value="0">-- Sin referencia --</option>
                                    <template x-for="r in catReferencias" :key="r.id">
                                        <option :value="r.id" x-text="r.referencia || r.nombre"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Grupo</label>
                                    <button type="button" @click="openCatalogTab('grupos')" class="text-[11px] text-cyan-600 dark:text-cyan-400 hover:underline">Gestionar</button>
                                </div>
                                <select x-model="form.grupo"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm">
                                    <option value="0">-- Sin grupo --</option>
                                    <template x-for="g in catGrupos" :key="g.id">
                                        <option :value="g.id" x-text="g.grupo"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Marca</label>
                                    <button type="button" @click="openCatalogTab('marcas')" class="text-[11px] text-cyan-600 dark:text-cyan-400 hover:underline">Gestionar</button>
                                </div>
                                <select x-model="form.marca"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm">
                                    <option value="0">-- Sin marca --</option>
                                    <template x-for="m in catMarcas" :key="m.id">
                                        <option :value="m.id" x-text="m.marca"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Modelo</label>
                                    <button type="button" @click="openCatalogTab('modelos')" class="text-[11px] text-cyan-600 dark:text-cyan-400 hover:underline">Gestionar</button>
                                </div>
                                <select x-model="form.modelo"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm">
                                    <option value="0">-- Sin modelo --</option>
                                    <template x-for="m in catModelos" :key="m.id">
                                        <option :value="m.id" x-text="m.modelo"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Color</label>
                                    <button type="button" @click="openCatalogTab('colores')" class="text-[11px] text-cyan-600 dark:text-cyan-400 hover:underline">Gestionar</button>
                                </div>
                                <select x-model="form.color"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm">
                                    <option value="0">-- Sin color --</option>
                                    <template x-for="c in catColores" :key="c.id">
                                        <option :value="c.id" x-text="c.color || c.nombre"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Unidad de Medida</label>
                                    <button type="button" @click="openCatalogTab('medidas')" class="text-[11px] text-cyan-600 dark:text-cyan-400 hover:underline">Gestionar</button>
                                </div>
                                <select x-model="form.unidad_medida"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm">
                                    <option value="">-- Unidad --</option>
                                    <template x-for="u in (catMedidas.length ? catMedidas : unidadesMedida)" :key="u.id || u">
                                        <option :value="u.medida || u.nombre || u" x-text="u.medida || u.nombre || u"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">IVA</label>
                                <select x-model="form.iva"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm">
                                    <option value="1">10%</option>
                                    <option value="2">5%</option>
                                    <option value="3">Exenta</option>
                                </select>
                            </div>
                        </div>
                        <!-- Descripción larga -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Descripción detallada</label>
                            <textarea x-model="form.descripcion_larga" rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 focus:border-transparent text-sm resize-none"></textarea>
                        </div>
                        <!-- Flags -->
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="form.controla_stock" :true-value="1" :false-value="-1" class="rounded border-gray-300 text-cyan-600 focus:ring-cyan-500">
                                <span class="text-sm text-gray-700 dark:text-gray-300">Controla stock</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="form.edita_precio" :true-value="1" :false-value="-1" class="rounded border-gray-300 text-cyan-600 focus:ring-cyan-500">
                                <span class="text-sm text-gray-700 dark:text-gray-300">Edita precio</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="form.vende_sin_stock" :true-value="1" :false-value="-1" class="rounded border-gray-300 text-cyan-600 focus:ring-cyan-500">
                                <span class="text-sm text-gray-700 dark:text-gray-300">Vende sin stock</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="form.usaserial" :true-value="1" :false-value="-1" class="rounded border-gray-300 text-cyan-600 focus:ring-cyan-500">
                                <span class="text-sm text-gray-700 dark:text-gray-300">Usa serial</span>
                            </label>
                        </div>
                    </div>

                    <!-- TAB: Imagen -->
                    <div x-show="formTab === 'imagen'" class="space-y-5">
                        <div class="rounded-2xl border border-cyan-200/60 dark:border-cyan-900/60 bg-gradient-to-br from-cyan-50 via-white to-blue-50 dark:from-slate-900 dark:via-slate-800 dark:to-slate-900 p-4">
                            <div class="flex items-start gap-3">
                                <div class="w-11 h-11 rounded-2xl bg-cyan-600 text-white flex items-center justify-center shrink-0">
                                    <i class="fas fa-camera-retro"></i>
                                </div>
                                <div class="min-w-0">
                                    <h4 class="text-base font-semibold text-gray-900 dark:text-white">Imagen comercial del producto</h4>
                                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                        Gestioná la foto principal, capturá desde cámara y prepará el producto para catálogo web, WhatsApp y búsqueda visual. Las imágenes relacionadas se almacenan en R2.
                                    </p>
                                    <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-2 text-xs text-slate-600 dark:text-slate-300">
                                        <div class="rounded-xl bg-white/80 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 px-3 py-2">Catalogo web + buscador inteligente</div>
                                        <div class="rounded-xl bg-white/80 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 px-3 py-2">Integrado con WhatsApp</div>
                                        <div class="rounded-xl bg-white/80 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 px-3 py-2">Con fotos tipo Unsplash/Shutterstock</div>
                                        <div class="rounded-xl bg-white/80 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700 px-3 py-2">Y filtros avanzados como AUTODOC</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 xl:grid-cols-[320px_minmax(0,1fr)] gap-5 items-start">
                            <div class="space-y-4">
                                <div class="rounded-2xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Foto principal</label>
                                    <div class="aspect-square rounded-2xl overflow-hidden border border-dashed border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-900 flex items-center justify-center">
                                        <img x-show="form.foto_url" :src="form.foto_url" :alt="form.desproducto || 'Producto'" class="w-full h-full object-cover" @error="$el.style.display='none'">
                                        <div x-show="!form.foto_url" class="text-center text-slate-400 dark:text-slate-500">
                                            <i class="fas fa-image text-3xl mb-2 block"></i>
                                            <span class="text-xs">Sin imagen principal</span>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Foto URL principal</label>
                                        <input type="url" x-model="form.foto_url" placeholder="https://..."
                                               class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm">
                                    </div>
                                    <label class="mt-3 flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" x-model="form.publicar_web" :true-value="1" :false-value="0" class="rounded border-gray-300 text-cyan-600 focus:ring-cyan-500">
                                        <span class="text-sm text-gray-700 dark:text-gray-300">Publicar en web</span>
                                    </label>
                                </div>
                            </div>
                            <div class="rounded-2xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4 space-y-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h5 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Fotos del producto en R2</h5>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">Galería, captura con cámara y carga desde URL para catálogo web, WhatsApp y buscador visual.</p>
                                    </div>
                                    <span x-show="imgUploading" class="text-xs text-cyan-600 dark:text-cyan-400"><i class="fas fa-spinner fa-spin"></i> Subiendo...</span>
                                </div>
                                    <div x-show="!imgDriveConfigured" class="text-xs text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg px-3 py-2">
                                        R2 no configurado en este servidor. La galería relacionada del producto no estará disponible.
                                    </div>
                                <div x-show="imgDriveConfigured" class="space-y-4">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button"
                                               @click="addImageFromProvider('google')"
                                               class="px-3 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium inline-flex items-center gap-2"
                                               :class="imgUploading ? 'opacity-50 pointer-events-none' : ''">
                                            <i class="fab fa-google"></i> Google
                                        </button>
                                        <button type="button"
                                               @click="addImageFromProvider('bing')"
                                               class="px-3 py-2 rounded-lg bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium inline-flex items-center gap-2"
                                               :class="imgUploading ? 'opacity-50 pointer-events-none' : ''">
                                            <i class="fab fa-microsoft"></i> Bing
                                        </button>
                                        <button type="button"
                                               @click="addImageFromProvider('pexels')"
                                               class="px-3 py-2 rounded-lg bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-medium inline-flex items-center gap-2"
                                               :class="imgUploading ? 'opacity-50 pointer-events-none' : ''">
                                            <i class="fas fa-leaf"></i> Pexels
                                        </button>
                                        <button type="button"
                                               @click="addImageFromProvider('pixabay')"
                                               class="px-3 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium inline-flex items-center gap-2"
                                               :class="imgUploading ? 'opacity-50 pointer-events-none' : ''">
                                            <i class="fas fa-images"></i> Pixabay
                                        </button>
                                        <button type="button"
                                               @click="addImageFromProvider('amazon')"
                                               class="px-3 py-2 rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium inline-flex items-center gap-2"
                                               :class="imgUploading ? 'opacity-50 pointer-events-none' : ''">
                                            <i class="fab fa-amazon"></i> Amazon
                                        </button>
                                        <button type="button"
                                               @click="addImageFromProvider('mercadolibre')"
                                               class="px-3 py-2 rounded-lg bg-yellow-500 hover:bg-yellow-600 text-slate-900 text-sm font-medium inline-flex items-center gap-2"
                                               :class="imgUploading ? 'opacity-50 pointer-events-none' : ''">
                                            <i class="fas fa-store"></i> Mercado Libre
                                        </button>
                                        <button type="button"
                                               @click="addImageFromProvider('shopee')"
                                               class="px-3 py-2 rounded-lg bg-orange-500 hover:bg-orange-600 text-white text-sm font-medium inline-flex items-center gap-2"
                                               :class="imgUploading ? 'opacity-50 pointer-events-none' : ''">
                                            <i class="fas fa-bag-shopping"></i> Shopee
                                        </button>
                                        <label for="desktopProductImageGallery"
                                               class="px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-medium cursor-pointer inline-flex items-center gap-2"
                                               :class="imgUploading ? 'opacity-50 pointer-events-none' : ''">
                                            <i class="fas fa-image"></i> Galería
                                        </label>
                                        <button type="button"
                                               @click="openProductCamera()"
                                               class="px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium cursor-pointer inline-flex items-center gap-2"
                                               :class="imgUploading ? 'opacity-50 pointer-events-none' : ''">
                                            <i class="fas fa-camera"></i> Cámara
                                        </button>
                                        <span x-show="!form.idproducto && pendingImages.length > 0" class="text-xs text-amber-600 dark:text-amber-400">
                                            <span x-text="pendingImages.length"></span> pendiente(s) de subir al guardar
                                        </span>
                                    </div>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                        Tip: podés copiar una imagen y pegar aquí con <span class="font-semibold">Ctrl+V</span>. La imagen quedará lista para subirse a R2 al guardar.
                                    </p>
                                    <div class="rounded-2xl border border-slate-200 dark:border-slate-700 bg-slate-50/90 dark:bg-slate-900/60 p-3">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">URL catálogo externo</label>
                                        <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">
                                            Pega tu URL, ábrela y luego copia la imagen para pegarla aquí en el producto.
                                        </p>
                                        <div class="flex items-center gap-2">
                                            <input type="url" x-model="form.catalogo_url" placeholder="https://tu-catalogo.com/producto/..."
                                                   class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm">
                                            <button type="button" @click="openCatalogoUrl()"
                                                    class="px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600 text-white text-sm font-medium inline-flex items-center gap-2">
                                                <i class="fas fa-up-right-from-square"></i> Abrir
                                            </button>
                                        </div>
                                    </div>
                                    <input id="desktopProductImageGallery" type="file" accept="image/*" multiple
                                           class="hidden"
                                           @click="$event.target.value = ''"
                                           @change="onSelectProductImage($event, 'gallery')">
                                    <input id="desktopProductImageCamera" type="file" accept="image/*" capture="environment"
                                           class="hidden"
                                           @click="$event.target.value = ''"
                                           @change="onSelectProductImage($event, 'camera')">
                                    <div x-show="!form.idproducto && pendingImages.length > 0">
                                        <p class="text-xs font-semibold text-slate-600 dark:text-slate-300 mb-2">Fotos agregadas (pendientes de subir a R2)</p>
                                        <div class="flex gap-2 flex-wrap">
                                            <template x-for="(pimg, pidx) in pendingImages" :key="'pending-'+pidx">
                                                <div class="relative group w-20 h-20 rounded-xl overflow-hidden border border-amber-300 dark:border-amber-700">
                                                    <img :src="pimg.preview" class="w-20 h-20 object-cover">
                                                    <span class="absolute left-1 bottom-1 px-1 py-0.5 text-[9px] leading-none rounded bg-black/70 text-white" x-text="pimg.source || 'img'"></span>
                                                    <button type="button" @click.stop="removePendingImage(pidx)"
                                                            class="absolute top-1 right-1 w-5 h-5 rounded bg-red-600 text-white text-[9px] flex items-center justify-center"
                                                            title="Suprimir">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                    <div x-show="form.idproducto && imgList.length > 0">
                                        <p class="text-xs font-semibold text-slate-600 dark:text-slate-300 mb-2">Fotos del producto almacenadas en R2</p>
                                        <div class="flex gap-2 flex-wrap">
                                            <template x-for="img in imgList" :key="img.id">
                                                <div class="relative group w-20 h-20 rounded-xl overflow-hidden border-2"
                                                     :class="img.principal == 1 ? 'border-cyan-500' : 'border-gray-200 dark:border-slate-600'">
                                                    <img :src="(img.thumb_url || img.url)" class="w-20 h-20 object-cover">
                                                    <div class="absolute inset-0 bg-black/45 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-1">
                                                        <button type="button" @click.stop="setImgPrincipal(img)" class="w-6 h-6 rounded bg-white/90 text-cyan-600 text-[10px]"><i class="fas fa-star"></i></button>
                                                        <button type="button" @click.stop="eliminarImg(img)" class="w-6 h-6 rounded bg-white/90 text-red-600 text-[10px]"><i class="fas fa-trash"></i></button>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                    <p x-show="imgError" class="text-xs text-red-600 dark:text-red-400" x-text="imgError"></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB: Aplicaciones -->
                    <div x-show="formTab === 'aplicaciones'" class="space-y-4">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-white">Aplicaciones del producto</h4>
                                <p class="text-sm text-cyan-600 dark:text-cyan-400">
                                    <span x-text="form.cve_producto || 'Sin codigo'"></span>
                                    <span class="mx-1">-</span>
                                    <span x-text="form.desproducto || 'Sin descripcion'"></span>
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button @click="agregarCodigoConversion()" type="button" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-violet-600 hover:bg-violet-700 text-white text-sm font-medium">
                                    <i class="fas fa-layer-group text-xs"></i>
                                    <span>Agregar equivalente</span>
                                </button>
                                <button @click="agregarAplicacion()" type="button" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-medium">
                                    <i class="fas fa-plus text-xs"></i>
                                    <span>Agregar aplicacion</span>
                                </button>
                                <button @click="openCatalogTab('marcas_cod_conversion')" type="button" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-200 text-sm">
                                    <i class="fas fa-cog text-xs"></i>
                                    <span>Gestionar catalogos</span>
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-[320px_minmax(0,1fr)] gap-4 items-start">
                            <div class="rounded-2xl border border-slate-700 bg-slate-950/95 overflow-hidden shadow-[0_20px_60px_rgba(0,0,0,0.35)]">
                                    <table class="w-full table-fixed border-collapse">
                                        <thead class="bg-[#1d1d1d] text-white sticky top-0 z-10">
                                            <tr class="text-[11px] uppercase tracking-[0.24em]">
                                                <th class="px-4 py-3 text-left font-semibold">Equivalente</th>
                                            </tr>
                                        </thead>
                                    </table>
                                    <div class="max-h-[58vh] overflow-y-auto">
                                        <table class="w-full table-fixed border-collapse">
                                            <tbody class="bg-slate-950/95 text-slate-100">
                                                <tr x-show="form.equivalentes.length === 0">
                                                    <td class="px-6 py-10 text-center text-slate-400 text-sm">
                                                        <i class="fas fa-car text-2xl mb-2 block"></i>
                                                        Sin aplicaciones cargadas
                                                    </td>
                                                </tr>
                                                <template x-for="group in equivalenciasAgrupadas" :key="`left-${group.key}`">
                                                    <tr class="border-b border-slate-700/70 align-top">
                                                        <td class="px-3 py-3">
                                                            <div class="flex items-center justify-between gap-2">
                                                                <input type="text"
                                                                       :value="group.marca_cod_conversion"
                                                                       list="catalogo-marcas-cod-conversion"
                                                                       @input="setEquivalenciaGroupField(group, 'marca_cod_conversion', $event.target.value)"
                                                                       @blur="ensureCatalogValue('marcas_cod_conversion', $event.target.value)"
                                                                       class="w-full bg-transparent text-[#7ae07d] text-lg font-medium leading-none uppercase border-0 border-b border-slate-700 px-0 py-1 focus:ring-0 focus:border-cyan-400">
                                                                <div class="flex items-center gap-1">
                                                                    <button type="button" @click="saveAplicacionesInline()" class="w-7 h-7 rounded-full border border-slate-600 text-emerald-300 hover:bg-slate-900" :disabled="saving">
                                                                        <i class="fas" :class="saving ? 'fa-spinner fa-spin text-[10px]' : 'fa-save text-[10px]'"></i>
                                                                    </button>
                                                                    <button type="button" @click="openCatalogTab('marcas_cod_conversion')" class="w-7 h-7 rounded-full border border-slate-600 text-slate-400 hover:text-cyan-300 hover:bg-slate-900 text-xs"><i class="fas fa-cog"></i></button>
                                                                    <button type="button" @click="eliminarEquivalenciaGrupo(group)" class="w-7 h-7 rounded-full border border-slate-600 text-red-300 hover:bg-slate-900">
                                                                        <i class="fas fa-minus text-[10px]"></i>
                                                                    </button>
                                                                </div>
                                                            </div>
                                                            <div class="space-y-1.5 mt-2">
                                                                <template x-for="groupRow in group.conversionRows" :key="`left-conv-${groupRow._idx}`">
                                                                    <div class="flex items-center gap-1">
                                                                        <input type="text"
                                                                               x-model="form.equivalentes[groupRow._idx].conversion"
                                                                               class="w-full bg-transparent text-slate-100 text-[15px] border-0 border-b border-slate-700/70 px-0 py-1 focus:ring-0 focus:border-cyan-400">
                                                                        <button type="button" @click="agregarCodigoConversionEnGrupo(group)" class="w-6 h-6 rounded-full border border-slate-600 text-cyan-300 hover:bg-slate-900">
                                                                            <i class="fas fa-plus text-[10px]"></i>
                                                                        </button>
                                                                        <button type="button" @click="eliminarEquivalenciaFila(groupRow._idx)" class="w-6 h-6 rounded-full border border-slate-600 text-red-300 hover:bg-slate-900">
                                                                            <i class="fas fa-minus text-[10px]"></i>
                                                                        </button>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                            </div>
                            <div class="rounded-2xl border border-slate-700 bg-slate-900 overflow-hidden shadow-[0_20px_60px_rgba(0,0,0,0.35)]">
                                <div class="max-h-[58vh] overflow-auto">
                                    <div class="sticky top-0 z-10 grid grid-cols-[1.5fr_0.8fr_1.1fr_1fr_1fr_72px] bg-[#1d1d1d] text-white text-[11px] uppercase tracking-[0.24em]">
                                        <div class="px-4 py-3 font-semibold border-r border-slate-700/80">Aplicacion</div>
                                        <div class="px-4 py-3 font-semibold border-r border-slate-700/80">Ano</div>
                                        <div class="px-4 py-3 font-semibold border-r border-slate-700/80">Modelo</div>
                                        <div class="px-4 py-3 font-semibold border-r border-slate-700/80">Motor</div>
                                        <div class="px-4 py-3 font-semibold border-r border-slate-700/80">Cod. motor</div>
                                        <div class="px-2 py-3 text-center font-semibold">Acc.</div>
                                    </div>
                                    <div class="bg-slate-900 text-slate-100">
                                        <div x-show="form.aplicaciones.length === 0" class="px-6 py-10 text-center text-slate-400 text-sm">
                                            <i class="fas fa-car text-2xl mb-2 block"></i>
                                            Sin aplicaciones cargadas
                                        </div>
                                        <div class="divide-y divide-slate-700/70">
                                            <template x-for="group in aplicacionesAgrupadas" :key="group.key">
                                                <div x-show="group.showAppGroup" class="bg-slate-950/40">
                                                    <div class="grid grid-cols-[1.5fr_0.8fr_1.1fr_1fr_1fr_72px] items-start">
                                                        <div class="px-3 py-3 border-r border-slate-700/80">
                                                            <div class="flex items-center justify-between gap-2">
                                                                <input type="text"
                                                                       :value="group.marca_aplicacion"
                                                                       list="catalogo-marcas-aplicacion"
                                                                       @input="setAplicacionGroupField(group, 'marca_aplicacion', $event.target.value)"
                                                                       @blur="ensureCatalogValue('marcas_aplicacion', $event.target.value)"
                                                                       class="w-full bg-transparent text-[#7ae07d] text-lg font-medium leading-none uppercase border-0 border-b border-slate-700 px-0 py-1 focus:ring-0 focus:border-cyan-400">
                                                                <div class="flex items-center justify-center gap-1">
                                                                    <button type="button" @click="openCatalogTab('marcas_aplicacion')" class="w-7 h-7 rounded-full border border-slate-600 text-slate-400 hover:text-cyan-300 hover:bg-slate-800 text-xs"><i class="fas fa-cog"></i></button>
                                                                    <button type="button" @click="saveAplicacionesInline()" class="w-7 h-7 rounded-full border border-slate-600 text-emerald-300 hover:bg-slate-800" :disabled="saving">
                                                                        <i class="fas" :class="saving ? 'fa-spinner fa-spin text-[10px]' : 'fa-save text-[10px]'"></i>
                                                                    </button>
                                                                    <button type="button" @click="agregarAplicacionEnGrupo(group)" class="w-7 h-7 rounded-full border border-slate-600 text-cyan-300 hover:bg-slate-800">
                                                                        <i class="fas fa-plus text-[10px]"></i>
                                                                    </button>
                                                                    <button type="button" @click="eliminarAplicacionGrupo(group)" class="w-7 h-7 rounded-full border border-slate-600 text-red-300 hover:bg-slate-800">
                                                                        <i class="fas fa-minus text-[10px]"></i>
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="px-3 py-3 border-r border-slate-700/80"><div class="h-[30px] border-b border-slate-700/50"></div></div>
                                                        <div class="px-3 py-3 border-r border-slate-700/80"><div class="h-[30px] border-b border-slate-700/50"></div></div>
                                                        <div class="px-3 py-3 border-r border-slate-700/80"><div class="h-[30px] border-b border-slate-700/50"></div></div>
                                                        <div class="px-3 py-3 border-r border-slate-700/80"><div class="h-[30px] border-b border-slate-700/50"></div></div>
                                                        <div class="px-2 py-3"></div>
                                                    </div>
                                                    <div class="divide-y divide-slate-800/70">
                                                        <template x-for="row in getAplicacionGroupRows(group)" :key="`row-${group.key}-${row._idx}`">
                                                            <div class="grid grid-cols-[1.5fr_0.8fr_1.1fr_1fr_1fr_72px] items-start">
                                                                <div class="px-3 py-2 border-r border-slate-700/80">
                                                                    <div class="flex items-center gap-2">
                                                                        <input type="text"
                                                                               x-model="form.aplicaciones[row._idx].vehiculo_marca"
                                                                               list="catalogo-marcas-aplicacion"
                                                                               @blur="ensureCatalogValue('marcas_aplicacion', form.aplicaciones[row._idx].vehiculo_marca)"
                                                                               class="w-full bg-transparent text-slate-100 text-[15px] border-0 border-b border-slate-700/70 px-0 py-1 focus:ring-0 focus:border-cyan-400">
                                                                        <button type="button" @click="eliminarAplicacionFila(row._idx)" class="w-7 h-7 shrink-0 rounded-full border border-slate-600 text-red-300 hover:bg-slate-800">
                                                                            <i class="fas fa-minus text-[10px]"></i>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                                <div class="px-3 py-2 border-r border-slate-700/80">
                                                                    <input type="text"
                                                                           x-model="form.aplicaciones[row._idx].anio"
                                                                           list="catalogo-anios-aplicacion"
                                                                           @blur="ensureCatalogValue('anios_aplicacion', form.aplicaciones[row._idx].anio)"
                                                                           class="w-full bg-transparent text-slate-100 text-[15px] border-0 border-b border-slate-700/70 px-0 py-1 focus:ring-0 focus:border-cyan-400">
                                                                </div>
                                                                <div class="px-3 py-2 border-r border-slate-700/80">
                                                                    <input type="text"
                                                                           x-model="form.aplicaciones[row._idx].vehiculo_modelo"
                                                                           list="catalogo-modelos-aplicacion"
                                                                           @blur="ensureCatalogValue('modelos_aplicacion', form.aplicaciones[row._idx].vehiculo_modelo)"
                                                                           class="w-full bg-transparent text-slate-100 text-[15px] border-0 border-b border-slate-700/70 px-0 py-1 focus:ring-0 focus:border-cyan-400">
                                                                </div>
                                                                <div class="px-3 py-2 border-r border-slate-700/80">
                                                                    <input type="text"
                                                                           x-model="form.aplicaciones[row._idx].motor"
                                                                           list="catalogo-motores-aplicacion"
                                                                           @blur="ensureCatalogValue('motores_aplicacion', form.aplicaciones[row._idx].motor)"
                                                                           class="w-full bg-transparent text-slate-100 text-[15px] border-0 border-b border-slate-700/70 px-0 py-1 focus:ring-0 focus:border-cyan-400">
                                                                </div>
                                                                <div class="px-3 py-2 border-r border-slate-700/80">
                                                                    <input type="text"
                                                                           x-model="form.aplicaciones[row._idx].codigo_motor"
                                                                           list="catalogo-codigos-motor-aplicacion"
                                                                           @blur="ensureCatalogValue('codigos_motor_aplicacion', form.aplicaciones[row._idx].codigo_motor)"
                                                                           class="w-full bg-transparent text-slate-100 text-[15px] border-0 border-b border-slate-700/70 px-0 py-1 focus:ring-0 focus:border-cyan-400">
                                                                </div>
                                                                <div class="px-2 py-2"></div>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <datalist id="catalogo-marcas-cod-conversion">
                            <template x-for="item in getCatalogoOptions('marcas_cod_conversion')" :key="`mcc-${item.id}`">
                                <option :value="item.nombre"></option>
                            </template>
                        </datalist>
                        <datalist id="catalogo-marcas-aplicacion">
                            <template x-for="item in getCatalogoOptions('marcas_aplicacion')" :key="`ma-${item.id}`">
                                <option :value="item.nombre"></option>
                            </template>
                        </datalist>
                        <datalist id="catalogo-anios-aplicacion">
                            <template x-for="item in getCatalogoOptions('anios_aplicacion')" :key="`aa-${item.id}`">
                                <option :value="item.nombre"></option>
                            </template>
                        </datalist>
                        <datalist id="catalogo-modelos-aplicacion">
                            <template x-for="item in getCatalogoOptions('modelos_aplicacion')" :key="`moa-${item.id}`">
                                <option :value="item.nombre"></option>
                            </template>
                        </datalist>
                        <datalist id="catalogo-motores-aplicacion">
                            <template x-for="item in getCatalogoOptions('motores_aplicacion')" :key="`mta-${item.id}`">
                                <option :value="item.nombre"></option>
                            </template>
                        </datalist>
                        <datalist id="catalogo-codigos-motor-aplicacion">
                            <template x-for="item in getCatalogoOptions('codigos_motor_aplicacion')" :key="`cma-${item.id}`">
                                <option :value="item.nombre"></option>
                            </template>
                        </datalist>
                        <datalist id="catalogo-tipos-precio">
                            <template x-for="tp in catTiposPrecio" :key="`tp-${tp.id}`">
                                <option :value="tp.tipo"></option>
                            </template>
                        </datalist>
                    </div>
                    
                    <!-- TAB: Precios por tipo -->
                    <div x-show="formTab === 'precios'" class="space-y-4">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Precios por tipo</h4>
                            <button @click="agregarPrecio()" class="text-cyan-600 hover:text-cyan-700 text-sm font-medium flex items-center gap-1">
                                <i class="fas fa-plus text-xs"></i> Agregar precio
                            </button>
                        </div>
                        <template x-for="(precio, idx) in form.precios" :key="idx">
                            <div class="flex items-end gap-3 p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
                                <div class="flex-1">
                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Tipo</label>
                                    <input type="text"
                                           x-model="precio.tipo_nombre"
                                           list="catalogo-tipos-precio"
                                           placeholder="Escribir o seleccionar tipo..."
                                           @focus="if (!precio.tipo_nombre && precio.tipo) precio.tipo_nombre = precioTipoNombre(precio.tipo)"
                                           @change="syncPrecioTipo(precio)"
                                           @blur="syncPrecioTipo(precio)"
                                           class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500">
                                </div>
                                <div class="w-28">
                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Costo</label>
                                    <input type="number" x-model.number="precio.costo" min="0" step="1"
                                           class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm text-right focus:ring-2 focus:ring-cyan-500">
                                </div>
                                <div class="w-20">
                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">%</label>
                                    <input type="number" x-model.number="precio.porcentaje" step="0.5"
                                           @input="precio.precio = Math.round(precio.costo * (1 + precio.porcentaje/100))"
                                           class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm text-right focus:ring-2 focus:ring-cyan-500">
                                </div>
                                <div class="w-32">
                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Precio</label>
                                    <input type="number" x-model.number="precio.precio" min="0" step="1"
                                           class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm text-right focus:ring-2 focus:ring-cyan-500">
                                </div>
                                <div class="w-24">
                                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Moneda</label>
                                    <select x-model="precio.moneda"
                                            class="w-full px-2 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500">
                                        <option value="PYG">PYG</option>
                                        <option value="USD">USD</option>
                                        <option value="BRL">BRL</option>
                                    </select>
                                </div>
                                <button @click="form.precios.splice(idx, 1)" class="w-8 h-8 rounded-lg bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:hover:bg-red-900/40 text-red-500 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-trash text-xs"></i>
                                </button>
                            </div>
                        </template>
                        <div x-show="form.precios.length === 0" class="p-6 text-center text-gray-400 dark:text-gray-500 text-sm">
                            <i class="fas fa-tag text-2xl mb-2 block"></i> Sin precios por tipo configurados
                        </div>
                    </div>
                    
                    <!-- TAB: Códigos de Barra -->
                    <div x-show="formTab === 'codigos'" class="space-y-4">
                        <div class="flex items-center justify-between mb-2">
                            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Códigos de Barra adicionales</h4>
                            <button @click="agregarCodigoBarra()" class="text-cyan-600 hover:text-cyan-700 text-sm font-medium flex items-center gap-1">
                                <i class="fas fa-plus text-xs"></i> Agregar código
                            </button>
                        </div>
                        <!-- Principal -->
                        <div class="flex items-center gap-3 p-3 bg-cyan-50 dark:bg-cyan-900/20 rounded-lg">
                            <i class="fas fa-barcode text-cyan-600 text-lg"></i>
                            <div class="flex-1">
                                <span class="text-xs text-cyan-600 dark:text-cyan-400 font-medium">Principal</span>
                                <input type="text" x-model="form.codigo_barra" placeholder="Código principal..."
                                       class="w-full mt-1 px-3 py-2 border border-cyan-200 dark:border-cyan-800 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white font-mono text-sm focus:ring-2 focus:ring-cyan-500">
                            </div>
                            <button @click="form.codigo_barra = ''" type="button" title="Eliminar principal"
                                    class="w-8 h-8 rounded-lg bg-red-50 hover:bg-red-100 dark:bg-red-900/20 text-red-500 flex items-center justify-center">
                                <i class="fas fa-trash text-xs"></i>
                            </button>
                        </div>
                        <!-- Adicionales -->
                        <template x-for="(cb, idx) in form.codigos_barra_extra" :key="idx">
                            <div class="flex items-center gap-3 p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
                                <i class="fas fa-barcode text-gray-400"></i>
                                <input type="text" x-model="cb.codigo" placeholder="Escanear o ingresar código..."
                                       class="flex-1 px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white font-mono text-sm focus:ring-2 focus:ring-cyan-500">
                                <button @click="form.codigos_barra_extra.splice(idx, 1)" class="w-8 h-8 rounded-lg bg-red-50 hover:bg-red-100 dark:bg-red-900/20 text-red-500 flex items-center justify-center">
                                    <i class="fas fa-trash text-xs"></i>
                                </button>
                            </div>
                        </template>
                    </div>
                    
                    <!-- TAB: Stock -->
                    <div x-show="formTab === 'stock'" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Stock Mínimo</label>
                                <input type="number" x-model.number="form.stock_minimo" min="0"
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-right">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Stock Máximo</label>
                                <input type="number" x-model.number="form.stock_maximo" min="0"
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-right">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Stock Actual</label>
                                <div class="px-3 py-2 bg-gray-50 dark:bg-slate-700 rounded-lg text-right text-lg font-bold text-gray-900 dark:text-white" x-text="formatNumber(form.saldo_actual || 0)"></div>
                            </div>
                        </div>
                        <div x-show="!form.idproducto" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Stock Inicial</label>
                                <input type="number" x-model.number="form.stock_inicial" min="0" step="1"
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-right">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Sucursal Inicial</label>
                                <select x-model="form.id_sucursal"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500">
                                    <option value="">Seleccionar sucursal...</option>
                                    <template x-for="s in sucursalesList" :key="s.id_sucursal">
                                        <option :value="s.id_sucursal" x-text="s.sucursal"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                        <!-- Stock por sucursal (solo en edición) -->
                        <div x-show="form.idproducto && stockSucursalesActivas.length > 0">
                            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-2">
                                <i class="fas fa-warehouse"></i> Stock por Sucursal
                            </h4>
                            <div class="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 dark:bg-slate-700/50">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Sucursal</th>
                                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Stock</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                                        <template x-for="ss in stockSucursalesActivas" :key="ss.id_sucursal">
                                            <tr>
                                                <td class="px-4 py-2 text-gray-900 dark:text-white" x-text="ss.sucursal"></td>
                                                <td class="px-4 py-2 text-right font-semibold" :class="ss.stock > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-500'" x-text="formatNumber(ss.stock)"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- TAB: Extras (web, sifen, dimensiones) -->
                    <div x-show="formTab === 'extras'" class="space-y-4">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Información adicional</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">NCM (Nomenclatura)</label>
                                <input type="text" x-model="form.ncm"
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 font-mono text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Origen</label>
                                <input type="text" x-model="form.origen" placeholder="PY, BR, CN..."
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Peso (kg)</label>
                                <input type="number" x-model.number="form.peso" step="0.01" min="0"
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-right text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Ancho (cm)</label>
                                <input type="number" x-model.number="form.ancho" step="0.1" min="0"
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-right text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Alto (cm)</label>
                                <input type="number" x-model.number="form.alto" step="0.1" min="0"
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-right text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Largo (cm)</label>
                                <input type="number" x-model.number="form.largo" step="0.1" min="0"
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-right text-sm">
                            </div>
                        </div>
                        <div class="bg-gray-50 dark:bg-slate-700/30 rounded-xl border border-gray-200 dark:border-slate-700 p-4 space-y-3">
                            <div class="flex items-center justify-between gap-3">
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 flex items-center gap-2">
                                    <i class="fas fa-map-location-dot"></i> Ubicación en góndola
                                </h4>
                                <button type="button" @click="sugerirUbicacion()" class="text-sm text-cyan-600 dark:text-cyan-400 hover:underline">
                                    Sugerir ubicación
                                </button>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Ubicación</label>
                                    <input type="text" x-model="form.ubicacion" placeholder="MOTOR / FRENOS / GENERAL"
                                           class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm uppercase">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Góndola</label>
                                    <input type="text" x-model="form.gondola" placeholder="12"
                                           class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm text-right">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Fila</label>
                                    <input type="text" x-model="form.fila" placeholder="A"
                                           class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm text-center uppercase">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Celda</label>
                                    <input type="text" x-model="form.celda" placeholder="01"
                                           class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm text-right">
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Observaciones</label>
                                <input type="text" x-model="form.obs"
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="px-6 py-4 bg-gray-50 dark:bg-slate-700/50 border-t border-gray-200 dark:border-slate-700 flex justify-between">
                    <button @click="closeForm()" class="px-4 py-2 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-600 font-medium transition-colors">
                        Cancelar
                    </button>
                    <button @click="guardarProducto()" 
                            :disabled="saving"
                            class="px-6 py-2 bg-cyan-600 hover:bg-cyan-700 disabled:opacity-50 text-white rounded-lg font-medium transition-colors inline-flex items-center gap-2">
                        <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                        <span x-text="saving ? 'Guardando...' : 'Guardar'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ MODAL: CÁMARA PRODUCTO (DESKTOP) ============ -->
    <div x-show="showProductCameraModal" x-cloak class="fixed inset-0 z-[70] overflow-y-auto"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div class="flex items-center justify-center min-h-screen px-4 py-4">
            <div class="fixed inset-0 bg-black/70" @click="closeProductCamera()"></div>
            <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-full max-w-3xl overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white"><i class="fas fa-camera text-emerald-600 mr-2"></i>Cámara del equipo</h3>
                    <button type="button" @click="closeProductCamera()" class="w-9 h-9 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 text-gray-500">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="p-4 space-y-3">
                    <div class="relative rounded-xl overflow-hidden bg-black aspect-video">
                        <video x-ref="desktopProductCameraVideo" autoplay playsinline muted class="w-full h-full object-cover"></video>
                    </div>
                    <p x-show="productCameraError" class="text-xs text-red-600 dark:text-red-400" x-text="productCameraError"></p>
                    <div class="flex items-center justify-end gap-2">
                        <button type="button" @click="closeProductCamera()"
                                class="px-4 py-2 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 rounded-lg">
                            Cancelar
                        </button>
                        <button type="button" @click="captureProductCameraPhoto()"
                                :disabled="imgUploading"
                                class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white rounded-lg font-medium inline-flex items-center gap-2">
                            <i class="fas fa-camera"></i> Capturar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============ MODAL: STOCK ============ -->
    <div x-show="showStock" x-cloak class="fixed inset-0 z-50 overflow-y-auto"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black/60" @click="showStock = false"></div>
            <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-[80vw] h-[80vh] mx-4 overflow-hidden fade-in flex flex-col">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <i class="fas fa-warehouse text-blue-600"></i> Stock
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400" x-text="stockProductoNombre"></p>
                    </div>
                    <button @click="showStock = false" class="w-10 h-10 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center justify-center text-gray-500">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="px-6 border-b border-gray-200 dark:border-slate-700 flex gap-0 overflow-x-auto">
                </div>
                <div class="px-6 py-4 flex-1 min-h-0 overflow-y-auto space-y-4">
                    <!-- Stock por sucursal -->
                    <div class="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-slate-700/50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Sucursal</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Stock</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                                <template x-for="ss in stockDetalle" :key="ss.id_sucursal">
                                    <tr>
                                        <td class="px-4 py-2 text-gray-900 dark:text-white" x-text="ss.sucursal"></td>
                                        <td class="px-4 py-2 text-right font-bold" :class="ss.stock > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-500'" x-text="formatNumber(ss.stock)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <!-- Últimos movimientos -->
                    <div x-show="stockMovimientos.length > 0">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 flex items-center gap-2">
                            <i class="fas fa-history"></i> Últimos Movimientos
                        </h4>
                        <div class="space-y-2 max-h-48 overflow-y-auto">
                            <template x-for="mov in stockMovimientos" :key="mov.idproducto">
                                <div class="flex items-center justify-between p-2 bg-white dark:bg-slate-800 rounded border border-gray-200 dark:border-slate-700 text-xs">
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400" x-text="formatDate(mov.fecha)"></span>
                                        <span class="ml-2 text-gray-700 dark:text-gray-300" x-text="mov.sucursal || 'S/S'"></span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span x-show="mov.entrada > 0" class="text-green-600 font-semibold" x-text="'+' + formatNumber(mov.entrada)"></span>
                                        <span x-show="mov.salida > 0" class="text-red-600 font-semibold" x-text="'-' + formatNumber(mov.salida)"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 dark:bg-slate-700/50 border-t border-gray-200 dark:border-slate-700 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <button @click="showStock = false; showAjusteInventarioModal = true"
                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                            Ajustar Inventario
                        </button>
                        <button @click="showStock = false; showTransferenciaModal = true"
                                class="px-4 py-2 bg-violet-600 hover:bg-violet-700 text-white rounded-lg font-medium transition-colors">
                            Transferencia entre sucursales
                        </button>
                    </div>
                    <button @click="showStock = false" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg font-medium transition-colors">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ MODAL: AJUSTE INVENTARIO ============ -->
    <div x-show="showAjusteInventarioModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black/60" @click="showAjusteInventarioModal = false"></div>
            <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-[60vw] max-w-3xl mx-4 overflow-hidden fade-in flex flex-col">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <i class="fas fa-sliders-h text-blue-600"></i> Ajustar Inventario
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400" x-text="stockProductoNombre"></p>
                    </div>
                    <button @click="showAjusteInventarioModal = false" class="w-10 h-10 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center justify-center text-gray-500">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Sucursal</label>
                            <select x-model="ajuste.id_sucursal" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500">
                                <option value="">Seleccionar...</option>
                                <template x-for="s in sucursalesList" :key="s.id_sucursal">
                                    <option :value="s.id_sucursal" x-text="s.sucursal"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Cantidad (+/-)</label>
                            <input type="number" x-model.number="ajuste.cantidad" step="1" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm text-right focus:ring-2 focus:ring-cyan-500">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Motivo</label>
                            <input type="text" x-model="ajuste.motivo" placeholder="Ajuste inventario" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button @click="showAjusteInventarioModal = false" class="px-4 py-2 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-600 font-medium transition-colors">Cancelar</button>
                        <button @click="guardarAjuste()" :disabled="!ajuste.id_sucursal || !ajuste.cantidad" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg text-sm font-medium transition-colors">
                            <i class="fas fa-save mr-1"></i> Aplicar Ajuste
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ MODAL: TRANSFERENCIA ============ -->
    <div x-show="showTransferenciaModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black/60" @click="showTransferenciaModal = false"></div>
            <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-[60vw] max-w-3xl mx-4 overflow-hidden fade-in flex flex-col">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <i class="fas fa-right-left text-violet-600"></i> Transferencia entre sucursales
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400" x-text="stockProductoNombre"></p>
                    </div>
                    <button @click="showTransferenciaModal = false" class="w-10 h-10 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center justify-center text-gray-500">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Sucursal origen</label>
                            <select x-model="transferencia.id_sucursal_origen" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500">
                                <option value="">Seleccionar...</option>
                                <template x-for="s in sucursalesList" :key="'o-' + s.id_sucursal">
                                    <option :value="s.id_sucursal" x-text="s.sucursal"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Sucursal destino</label>
                            <select x-model="transferencia.id_sucursal_destino" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500">
                                <option value="">Seleccionar...</option>
                                <template x-for="s in sucursalesList" :key="'d-' + s.id_sucursal">
                                    <option :value="s.id_sucursal" x-text="s.sucursal"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Cantidad</label>
                            <input type="number" x-model.number="transferencia.cantidad" step="1" min="0" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm text-right focus:ring-2 focus:ring-cyan-500">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Motivo / Observación</label>
                            <input type="text" x-model="transferencia.obs" placeholder="Traslado interno" class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button @click="showTransferenciaModal = false" class="px-4 py-2 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-600 font-medium transition-colors">Cancelar</button>
                        <button @click="guardarTraslado()" :disabled="!transferencia.id_sucursal_origen || !transferencia.id_sucursal_destino || !transferencia.cantidad" class="px-4 py-2 bg-violet-600 hover:bg-violet-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg text-sm font-medium transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-right-left"></i> Registrar traslado
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============ MODAL: CATÁLOGOS ============ -->
    <div x-show="showCatalogos" x-cloak class="fixed inset-0 z-50 overflow-y-auto"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black/60" @click="showCatalogos = false"></div>
            <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-xl max-w-3xl w-full mx-4 overflow-hidden fade-in">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between bg-gradient-to-r from-cyan-600 to-teal-600">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <i class="fas fa-tags"></i> Catálogos
                    </h3>
                    <button @click="showCatalogos = false" class="w-10 h-10 rounded-lg hover:bg-white/20 flex items-center justify-center text-white/80 hover:text-white">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <!-- Tabs de catálogos -->
                <div class="px-6 border-b border-gray-200 dark:border-slate-700 flex gap-0 overflow-x-auto">
                    <template x-for="ct in catTabs" :key="ct.key">
                        <button @click="switchCatTab(ct.key)"
                                :class="catTab === ct.key ? 'tab-active font-semibold' : 'text-gray-500 dark:text-gray-400'"
                                class="px-4 py-3 text-sm whitespace-nowrap border-b-2 border-transparent transition-colors"
                                x-text="ct.label">
                        </button>
                    </template>
                </div>
                <div class="px-6 py-4 max-h-[55vh] overflow-y-auto">
                    <!-- Agregar nuevo -->
                    <div class="flex items-center gap-3 mb-4">
                        <input type="text" x-model="catNuevoNombre" :placeholder="'Nuevo ' + catTabLabel()"
                               @keyup.enter="crearCatalogo()"
                               class="flex-1 px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500">
                        <button @click="cargarCatalogosMock()"
                                class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-sm font-medium transition-colors"
                                title="Carga catálogos comunes para todo tipo de negocio">
                            <i class="fas fa-wand-magic-sparkles mr-1"></i> Datos Base
                        </button>
                        <button @click="crearCatalogo()" :disabled="!catNuevoNombre.trim()"
                                class="px-4 py-2 bg-cyan-600 hover:bg-cyan-700 disabled:opacity-50 text-white rounded-lg text-sm font-medium transition-colors">
                            <i class="fas fa-plus mr-1"></i> Agregar
                        </button>
                    </div>
                    <!-- Lista -->
                    <div class="space-y-2">
                        <template x-for="item in catItems" :key="item.id">
                            <div class="flex items-center gap-3 p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg group">
                                <template x-if="catEditId !== item.id">
                                    <div class="flex items-center justify-between w-full">
                                        <span class="text-sm text-gray-900 dark:text-white" x-text="item[catFieldName()] || item.nombre || ''"></span>
                                        <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                            <button @click="catEditId = item.id; catEditNombre = (item[catFieldName()] || item.nombre || '')" class="w-7 h-7 rounded bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 flex items-center justify-center" title="Editar">
                                                <i class="fas fa-pen text-xs"></i>
                                            </button>
                                            <button @click="eliminarCatalogo(item.id)" class="w-7 h-7 rounded bg-red-50 hover:bg-red-100 dark:bg-red-900/20 text-red-600 dark:text-red-400 flex items-center justify-center" title="Eliminar">
                                                <i class="fas fa-trash text-xs"></i>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="catEditId === item.id">
                                    <div class="flex items-center gap-2 w-full">
                                        <input type="text" x-model="catEditNombre" @keyup.enter="updateCatalogo(item.id)" @keyup.escape="catEditId = null"
                                               class="flex-1 px-3 py-1.5 border border-cyan-400 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500">
                                        <button @click="updateCatalogo(item.id)" class="w-7 h-7 rounded bg-green-50 hover:bg-green-100 dark:bg-green-900/20 text-green-600 flex items-center justify-center">
                                            <i class="fas fa-check text-xs"></i>
                                        </button>
                                        <button @click="catEditId = null" class="w-7 h-7 rounded bg-gray-100 hover:bg-gray-200 dark:bg-slate-600 text-gray-500 flex items-center justify-center">
                                            <i class="fas fa-times text-xs"></i>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <div x-show="catItems.length === 0" class="p-6 text-center text-gray-400 text-sm">
                            Sin elementos. Agrega uno arriba.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============ MODAL: IMPORTAR ============ -->
    <div x-show="showImport" x-cloak class="fixed inset-0 z-50 overflow-y-auto"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black/60" @click="showImport = false"></div>
            <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-xl max-w-lg w-full mx-4 overflow-hidden fade-in">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between bg-gradient-to-r from-cyan-600 to-cyan-700">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <i class="fas fa-file-import"></i> Importar Productos
                    </h3>
                    <button @click="showImport = false" class="w-10 h-10 rounded-lg hover:bg-white/20 flex items-center justify-center text-white/80 hover:text-white">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <!-- Descargar plantilla -->
                    <div class="bg-cyan-50 dark:bg-cyan-900/20 rounded-lg p-4 text-sm">
                        <p class="text-cyan-800 dark:text-cyan-300 mb-2">
                            <i class="fas fa-info-circle mr-1"></i> Descarga la plantilla CSV y complétala con tus productos.
                        </p>
                        <a href="/public/productos/api/importar.php?action=template" download
                           class="inline-flex items-center gap-2 px-3 py-1.5 bg-cyan-600 hover:bg-cyan-700 text-white rounded-lg text-sm font-medium transition-colors">
                            <i class="fas fa-download"></i> Descargar plantilla
                        </a>
                    </div>
                    <!-- Upload -->
                    <div class="border-2 border-dashed border-gray-300 dark:border-slate-600 rounded-xl p-6 text-center hover:border-cyan-500 transition-colors"
                         @dragover.prevent="$el.classList.add('border-cyan-500', 'bg-cyan-50', 'dark:bg-cyan-900/10')"
                         @dragleave.prevent="$el.classList.remove('border-cyan-500', 'bg-cyan-50', 'dark:bg-cyan-900/10')"
                         @drop.prevent="handleImportDrop($event)">
                        <i class="fas fa-cloud-upload-alt text-4xl text-gray-300 dark:text-slate-500 mb-3"></i>
                        <p class="text-gray-500 dark:text-gray-400 text-sm mb-3">Arrastra tu archivo CSV aquí o</p>
                        <label class="inline-flex items-center gap-2 px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-gray-700 dark:text-gray-300 rounded-lg cursor-pointer transition-colors text-sm font-medium">
                            <i class="fas fa-file-csv"></i> Seleccionar archivo
                            <input type="file" accept=".csv,.txt" class="hidden" @change="handleImportFile($event)">
                        </label>
                        <p x-show="importFile" class="mt-3 text-sm text-cyan-600 dark:text-cyan-400 font-medium" x-text="importFile?.name"></p>
                    </div>
                    <!-- Progress -->
                    <div x-show="importing" class="space-y-2">
                        <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <i class="fas fa-spinner fa-spin text-cyan-600"></i>
                            <span>Importando productos...</span>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-slate-700 rounded-full h-2">
                            <div class="bg-cyan-600 h-2 rounded-full transition-all" style="width: 100%; animation: pulse 1.5s infinite;"></div>
                        </div>
                    </div>
                    <!-- Resultado -->
                    <div x-show="importResult" class="p-4 rounded-lg" :class="importResult?.success ? 'bg-green-50 dark:bg-green-900/20' : 'bg-red-50 dark:bg-red-900/20'">
                        <template x-if="importResult?.success">
                            <div class="text-sm">
                                <p class="font-semibold text-green-800 dark:text-green-400 mb-1"><i class="fas fa-check-circle mr-1"></i> Importación completada</p>
                                <p class="text-green-700 dark:text-green-300">Creados: <span class="font-bold" x-text="importResult.data?.creados || 0"></span></p>
                                <p class="text-green-700 dark:text-green-300">Actualizados: <span class="font-bold" x-text="importResult.data?.actualizados || 0"></span></p>
                                <p x-show="importResult.data?.errores > 0" class="text-amber-700 dark:text-amber-300">Errores: <span class="font-bold" x-text="importResult.data?.errores"></span></p>
                                <div x-show="importResult.data?.detalle_errores?.length > 0" class="mt-2 max-h-24 overflow-y-auto text-xs text-red-600 dark:text-red-400">
                                    <template x-for="e in importResult.data?.detalle_errores || []" :key="e">
                                        <p x-text="e"></p>
                                    </template>
                                </div>
                            </div>
                        </template>
                        <template x-if="importResult && !importResult.success">
                            <p class="text-sm text-red-800 dark:text-red-400"><i class="fas fa-exclamation-circle mr-1"></i> <span x-text="importResult.error"></span></p>
                        </template>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 dark:bg-slate-700/50 border-t border-gray-200 dark:border-slate-700 flex justify-between">
                    <button @click="showImport = false" class="px-4 py-2 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-600 font-medium">
                        Cerrar
                    </button>
                    <button @click="ejecutarImport()" :disabled="!importFile || importing"
                            class="px-6 py-2 bg-cyan-600 hover:bg-cyan-700 disabled:opacity-50 disabled:cursor-not-allowed text-white rounded-lg font-medium inline-flex items-center gap-2">
                        <i class="fas fa-upload"></i> Importar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ MENU CONTEXTUAL CELDA ============ -->
    <div x-show="cellMenu.show" x-cloak
         @click.outside="closeCellMenu()"
         @mouseleave="closeCellMenu()"
         class="fixed z-[95] w-72 max-w-[90vw] rounded-xl border border-gray-200 dark:border-slate-700 bg-white/95 dark:bg-slate-800/95 backdrop-blur shadow-2xl overflow-hidden"
         :style="`left:${cellMenu.x}px;top:${cellMenu.y}px;`">
        <div class="px-3 py-2 border-b border-gray-100 dark:border-slate-700">
            <div class="text-xs font-semibold text-gray-700 dark:text-gray-200" x-text="cellMenu.title"></div>
            <div class="text-[11px] text-gray-500 dark:text-gray-400" x-text="cellMenu.subtitle"></div>
        </div>
        <div class="max-h-64 overflow-auto">
            <template x-if="cellMenu.items.length === 0">
                <div class="px-3 py-3 text-xs text-gray-500 dark:text-gray-400">Sin más detalles</div>
            </template>
            <template x-for="(it, idx) in cellMenu.items" :key="'cm_'+idx">
                <div class="px-3 py-2 text-sm border-b border-gray-100 dark:border-slate-700/60 last:border-b-0 flex items-center justify-between gap-3">
                    <span class="text-gray-600 dark:text-gray-300" x-text="it.label"></span>
                    <span class="font-semibold text-right" :class="it.valueClass || 'text-gray-900 dark:text-white'" x-text="it.value"></span>
                </div>
            </template>
        </div>
    </div>

    <!-- ============ TOAST ============ -->
    <div x-show="toast.show" x-cloak
         x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed bottom-6 right-6 z-[60] max-w-sm">
        <div class="flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg border"
             :class="toast.type === 'success' ? 'bg-green-50 dark:bg-green-900/40 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300' 
                    : toast.type === 'error' ? 'bg-red-50 dark:bg-red-900/40 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300'
                    : 'bg-blue-50 dark:bg-blue-900/40 border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-300'">
            <i class="fas" :class="toast.type === 'success' ? 'fa-check-circle' : toast.type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'"></i>
            <span class="text-sm font-medium" x-text="toast.message"></span>
        </div>
    </div>
    
</body>
</html>
