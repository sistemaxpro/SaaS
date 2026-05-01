<?php
/**
 * Compras - Versión Móvil
 * Optimizado para captura de cámara, cards 2 columnas, modal fullscreen
 */
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

Permission::requireAccess('app_grid_factura_compras');
$permisos = Permission::getAppPermissions('app_grid_factura_compras');

$id_empresa = Session::get('id_empresa', 169);
$id_login = Session::get('id_login');

$masterPdo = Database::getMasterConnection();
$stmtEmpresa = $masterPdo->prepare("SELECT * FROM empresa WHERE id_empresa = ?");
$stmtEmpresa->execute([$id_empresa]);
$empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es" x-data="comprasMobileApp()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Compras - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <link rel="stylesheet" href="/public/_lib/ag-grid/ag-grid-enterprise/package/styles/ag-grid.css" media="(min-width: 768px)">
    <link rel="stylesheet" href="/public/_lib/ag-grid/ag-grid-enterprise/package/styles/ag-theme-quartz.css" media="(min-width: 768px)">
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
            theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } }
        }
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
        * { -webkit-tap-highlight-color: transparent; }
        body { overscroll-behavior-y: contain; }
        .ocr-pulse { animation: ocrPulse 1.5s ease-in-out infinite; }
        @keyframes ocrPulse { 0%,100%{opacity:1} 50%{opacity:.5} }
        .ocr-progress { background: linear-gradient(90deg,#ea580c 0%,#f97316 50%,#ea580c 100%); background-size:200% 100%; animation: shimmer 1.5s infinite; }
        @keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
        .card-touch { transition: transform .1s; }
        .card-touch:active { transform: scale(.97); }
        input, select, textarea { font-size: 16px !important; }
        .safe-area-bottom { padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 18px); }
        .safe-area-top { padding-top: env(safe-area-inset-top, 0px); }
        .camera-controls {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 18px;
            padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 42px);
            padding-top: 14px;
            min-height: 132px;
        }
        .camera-stage {
            padding-bottom: 190px;
        }
        .ag-theme-quartz,
        .ag-theme-quartz-dark {
            --ag-font-family: Inter, sans-serif;
            --ag-border-color: rgba(148, 163, 184, 0.18);
            --ag-row-border-color: rgba(148, 163, 184, 0.14);
            --ag-header-background-color: rgba(249, 250, 251, 0.96);
            --ag-background-color: transparent;
            --ag-row-hover-color: rgba(234, 88, 12, 0.08);
            --ag-wrapper-border-radius: 16px;
        }
        .ag-theme-quartz-dark {
            --ag-border-color: rgba(71, 85, 105, 0.5);
            --ag-row-border-color: rgba(71, 85, 105, 0.35);
            --ag-header-background-color: rgba(30, 41, 59, 0.92);
            --ag-background-color: transparent;
            --ag-foreground-color: rgb(226, 232, 240);
            --ag-secondary-foreground-color: rgb(148, 163, 184);
            --ag-row-hover-color: rgba(234, 88, 12, 0.12);
        }
        .tablet-compras-grid {
            width: 100%;
            height: 62vh;
            min-height: 420px;
        }
        .tablet-compra-stack { line-height: 1.25; }
        .tablet-compra-stack .main { font-weight: 600; color: rgb(15 23 42); }
        .dark .tablet-compra-stack .main { color: rgb(248 250 252); }
        .tablet-compra-stack .meta { font-size: 11px; color: rgb(100 116 139); }
        .dark .tablet-compra-stack .meta { color: rgb(148 163 184); }
        .tablet-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            padding: 2px 10px;
            font-size: 11px;
            font-weight: 700;
        }
        .tablet-badge.ok { background: rgba(34, 197, 94, 0.14); color: rgb(21, 128, 61); }
        .tablet-badge.off { background: rgba(239, 68, 68, 0.14); color: rgb(185, 28, 28); }
        .dark .tablet-badge.ok { color: rgb(74, 222, 128); }
        .dark .tablet-badge.off { color: rgb(248, 113, 113); }
        .tablet-grid-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .tablet-grid-actions button {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            border: 0;
            cursor: pointer;
            transition: transform .12s ease, background-color .12s ease;
        }
        .tablet-grid-actions button:hover { transform: translateY(-1px); }
        .tablet-grid-actions .view { background: rgba(249, 115, 22, 0.12); color: rgb(234, 88, 12); }
        .tablet-grid-actions .print { background: rgba(148, 163, 184, 0.14); color: rgb(71, 85, 105); }
        .dark .tablet-grid-actions .print { color: rgb(203, 213, 225); }
        .checkout-panel-mobile {
            border-top: 1px solid rgba(51, 65, 85, 0.98);
            border-left: 1px solid rgba(51, 65, 85, 0.72);
            border-right: 1px solid rgba(51, 65, 85, 0.72);
            box-shadow: 0 -18px 48px rgba(2, 6, 23, 0.58);
        }
        .checkout-section-mobile {
            border: 1px solid rgba(51, 65, 85, 0.8);
            background: rgba(15, 23, 42, 0.72);
            border-radius: 18px;
            padding: 14px;
        }
        .checkout-footer-mobile {
            border-top: 1px solid rgba(51, 65, 85, 0.9);
            background: rgba(2, 6, 23, 0.88);
            margin-left: -1rem;
            margin-right: -1rem;
            margin-bottom: -1.25rem;
            padding: 1rem;
            padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 1rem);
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-slate-900 min-h-screen font-sans pb-20">
<script>window.__PERMISOS__ = <?= json_encode($permisos) ?>;</script>

    <!-- ═══════════════════════════════ HEADER ═══════════════════════════════ -->
    <header class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 sticky top-0 z-40 safe-area-top">
        <div class="flex items-center justify-between px-3 h-14">
            <div class="flex items-center gap-2.5">
                <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}if(typeof parent.cerrarAppMobile==='function'){parent.cerrarAppMobile();return;}}catch(e){} window.location.href='/public/menu/menu.php';" 
                        class="w-9 h-9 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-600 dark:text-gray-300 active:scale-90 transition-transform">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div>
                    <h1 class="text-base font-bold text-gray-900 dark:text-white leading-tight">Compras</h1>
                    <p class="text-[10px] text-gray-400 leading-tight"><?= htmlspecialchars($empresa['empresa'] ?? '') ?></p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click="clearAppCache()"
                        class="h-9 w-9 rounded-lg bg-sky-100 dark:bg-sky-900/30 text-sky-700 dark:text-sky-300 active:scale-95 transition-transform inline-flex items-center justify-center"
                        title="Limpiar caché">
                    <i class="fas fa-rotate-right text-xs"></i>
                </button>
                <button x-show="permisos.priv_insert === 'Y'" @click="showFormCompra = true; resetForm()" 
                        class="h-9 px-3 bg-orange-600 text-white rounded-lg font-semibold text-sm active:scale-95 transition-transform inline-flex items-center gap-1.5">
                    <i class="fas fa-plus text-xs"></i> Compra
                </button>
            </div>
        </div>
    </header>

    <!-- ═══════════════════════════════ BARRA BÚSQUEDA + FILTROS ═══════════════════════════════ -->
    <div class="sticky top-14 z-30 bg-gray-100 dark:bg-slate-900 px-3 pt-2 pb-1 space-y-2">
        <!-- Búsqueda -->
        <div class="relative">
            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
            <input type="text" x-model="searchQuery" @input.debounce.400ms="loadCompras()"
                   placeholder="Buscar factura, proveedor..."
                   class="w-full pl-9 pr-3 py-2.5 border border-gray-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-orange-500 focus:border-transparent shadow-sm">
        </div>
        <!-- Período pills -->
        <div class="flex gap-1 overflow-x-auto scrollbar-hide pb-1 -mx-1 px-1">
            <template x-for="p in periodos" :key="p.key">
                <button @click="setPeriodo(p.key)" 
                        :class="periodoActivo === p.key 
                            ? 'bg-orange-600 text-white shadow-md shadow-orange-500/20' 
                            : 'bg-white dark:bg-slate-800 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-slate-700'"
                        class="px-3 py-1.5 rounded-full text-xs font-medium whitespace-nowrap transition-all active:scale-95 shrink-0"
                        x-text="p.label">
                </button>
            </template>
        </div>
    </div>

    <!-- ═══════════════════════════════ STATS MINI ═══════════════════════════════ -->
    <div class="px-3 pt-2 pb-1">
        <div class="grid grid-cols-2 gap-2">
            <div class="bg-white dark:bg-slate-800 rounded-xl p-2.5 border border-gray-200 dark:border-slate-700 flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center shrink-0">
                    <i class="fas fa-file-invoice text-orange-500 text-sm"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] text-gray-400 leading-tight">Compras</p>
                    <p class="text-sm font-bold text-gray-900 dark:text-white truncate" x-text="stats.totalCompras"></p>
                </div>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl p-2.5 border border-gray-200 dark:border-slate-700 flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center shrink-0">
                    <i class="fas fa-money-bill-wave text-blue-500 text-sm"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] text-gray-400 leading-tight">Monto</p>
                    <p class="text-sm font-bold text-gray-900 dark:text-white truncate" x-text="formatMoney(stats.montoTotal)"></p>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════ LISTA CARDS 2 COLUMNAS ═══════════════════════════════ -->
    <div class="px-3 pt-2 pb-6">
        <!-- Loading -->
        <div x-show="loading" class="py-12 text-center">
            <i class="fas fa-spinner fa-spin text-2xl text-orange-500 mb-2"></i>
            <p class="text-sm text-gray-400">Cargando...</p>
        </div>

        <!-- Tablet Grid -->
        <div x-show="!loading && useTabletGrid" x-cloak class="space-y-3">
            <div class="bg-white dark:bg-slate-800 rounded-2xl border border-gray-200 dark:border-slate-700 p-2.5 shadow-sm">
                <div x-ref="gridComprasTablet"
                     :class="{'ag-theme-quartz': !isDark, 'ag-theme-quartz-dark': isDark}"
                     class="tablet-compras-grid"></div>
            </div>
            <div class="flex items-center justify-between px-1 text-xs text-gray-500 dark:text-gray-400">
                <span>Visibles: <span x-text="gridVisibleRows"></span></span>
                <span>Total: <span x-text="totalRecords"></span></span>
            </div>
        </div>

        <!-- Cards Grid -->
        <div x-show="!loading && !useTabletGrid" x-cloak class="grid grid-cols-2 gap-2">
            <template x-for="compra in compras" :key="compra.id_factura">
                <div @click="verCompra(compra)" 
                     class="card-touch bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-3 cursor-pointer relative overflow-hidden">
                    <!-- Badge estado -->
                    <div class="absolute top-2 right-2">
                        <span x-show="compra.estado == 1" class="w-2 h-2 bg-green-500 rounded-full block"></span>
                        <span x-show="compra.estado == 0" class="w-2 h-2 bg-red-500 rounded-full block"></span>
                    </div>
                    <!-- Nro factura -->
                    <p class="font-mono text-[11px] font-semibold text-orange-600 dark:text-orange-400 truncate pr-4" x-text="compra.nro_factura"></p>
                    <!-- Proveedor -->
                    <p class="text-xs font-medium text-gray-900 dark:text-white truncate mt-0.5" x-text="compra.proveedor_nombre || 'Sin proveedor'"></p>
                    <!-- Fecha -->
                    <p class="text-[10px] text-gray-400 mt-0.5" x-text="formatDate(compra.fecha)"></p>
                    <!-- Total -->
                    <p class="text-sm font-bold text-gray-900 dark:text-white mt-1.5" x-text="formatMoney(compra.total)">
                    </p>
                    <span class="text-[10px] text-gray-400">Gs</span>
                    <!-- Pendiente -->
                    <div x-show="parseFloat(compra.pendiente) > 0" class="mt-1">
                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-red-50 dark:bg-red-900/20 rounded text-[10px] font-medium text-red-600 dark:text-red-400">
                            <i class="fas fa-clock text-[8px]"></i>
                            <span x-text="formatMoney(compra.pendiente) + ' pend.'"></span>
                        </span>
                    </div>
                </div>
            </template>
        </div>

        <!-- Vacío -->
        <div x-show="!loading && totalRecords === 0" x-cloak class="py-12 text-center">
            <i class="fas fa-truck text-4xl text-gray-300 dark:text-slate-600 mb-3"></i>
            <p class="text-sm text-gray-400">No se encontraron compras</p>
        </div>

        <!-- Paginación -->
        <div x-show="totalPages > 1 && !loading && !useTabletGrid" x-cloak class="mt-4 flex justify-center">
            <div class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-slate-900/80 px-3 py-2 shadow-[0_12px_30px_rgba(15,23,42,.26)] backdrop-blur-xl dark:border-slate-700/70 dark:bg-slate-900/90">
                <template x-for="pageNumber in getVisiblePageDots()" :key="'compras-page-dot-' + pageNumber">
                    <button
                        type="button"
                        @click="goToPage(pageNumber)"
                        :aria-label="'Ir a pagina ' + pageNumber"
                        :aria-current="pageNumber === currentPage ? 'page' : null"
                        class="relative flex items-center justify-center rounded-full transition-all duration-200 active:scale-90"
                        :class="pageNumber === currentPage
                            ? 'h-3.5 w-7 bg-white shadow-[0_0_0_1px_rgba(255,255,255,.16),0_6px_16px_rgba(255,255,255,.18)] dark:bg-slate-100'
                            : 'h-3.5 w-3.5 bg-white/35 hover:bg-white/55 dark:bg-slate-500 dark:hover:bg-slate-300'">
                        <span class="sr-only" x-text="'Pagina ' + pageNumber"></span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════ MODAL DETALLE (slide up fullscreen) ═══════════════════════════════ -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50"
         x-transition:enter="transition ease-out duration-250" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
         x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full">
        <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
        <div class="fixed inset-x-0 bottom-0 top-12 bg-white dark:bg-slate-800 rounded-t-2xl overflow-hidden flex flex-col">
            <!-- Handle -->
            <div class="flex justify-center pt-2 pb-1">
                <div class="w-10 h-1 bg-gray-300 dark:bg-slate-600 rounded-full"></div>
            </div>
            <!-- Header -->
            <div class="px-4 pb-3 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-gray-900 dark:text-white">Detalle</h3>
                    <p class="text-xs text-gray-500" x-text="selectedCompra?.nro_factura"></p>
                </div>
                <button @click="showModal = false" class="w-8 h-8 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center justify-center text-gray-400">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <!-- Content scroll -->
            <div class="flex-1 overflow-y-auto px-4 pb-6">
                <template x-if="selectedCompra">
                    <div class="space-y-4">
                        <!-- Info grid -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <p class="text-[10px] text-gray-400 uppercase">Proveedor</p>
                                <p class="text-sm font-medium text-gray-900 dark:text-white" x-text="selectedCompra.proveedor_nombre || '-'"></p>
                            </div>
                            <div>
                                <p class="text-[10px] text-gray-400 uppercase">RUC</p>
                                <p class="text-sm font-medium text-gray-900 dark:text-white" x-text="selectedCompra.proveedor_ruc || '-'"></p>
                            </div>
                            <div>
                                <p class="text-[10px] text-gray-400 uppercase">Fecha</p>
                                <p class="text-sm font-medium text-gray-900 dark:text-white" x-text="formatDate(selectedCompra.fecha)"></p>
                            </div>
                            <div>
                                <p class="text-[10px] text-gray-400 uppercase">Timbrado</p>
                                <p class="text-sm font-medium text-gray-900 dark:text-white" x-text="selectedCompra.timbrado || '-'"></p>
                            </div>
                        </div>
                        <!-- Items -->
                        <div>
                            <p class="text-[10px] text-gray-400 uppercase mb-2">Productos</p>
                            <div class="space-y-2">
                                <template x-for="item in compraItems" :key="item.id">
                                    <div class="bg-gray-50 dark:bg-slate-700/50 rounded-lg px-3 py-2 flex justify-between items-center">
                                        <div class="min-w-0 flex-1 mr-2">
                                            <p class="text-sm text-gray-900 dark:text-white truncate" x-text="item.descripcion || item.producto_nombre"></p>
                                            <p class="text-[10px] text-gray-400" x-text="'Cod. prov: ' + (item.codigo || '-')"></p>
                                            <p class="text-[10px] text-gray-400" x-text="item.cantidad + ' x ' + formatMoney(item.costo_gs) + ' Gs'"></p>
                                        </div>
                                        <p class="text-sm font-semibold text-gray-900 dark:text-white shrink-0" x-text="formatMoney(item.importe_gs)"></p>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <!-- Totales -->
                        <div class="bg-orange-50 dark:bg-orange-900/20 rounded-xl p-4 space-y-1">
                            <div class="flex justify-between">
                                <span class="text-sm text-gray-600 dark:text-gray-400">Total</span>
                                <span class="text-lg font-bold text-gray-900 dark:text-white" x-text="formatMoney(selectedCompra.total) + ' Gs'"></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-xs text-gray-500">Pendiente</span>
                                <span class="text-sm font-semibold" 
                                      :class="parseFloat(selectedCompra.pendiente) > 0 ? 'text-red-600' : 'text-green-600'"
                                      x-text="formatMoney(selectedCompra.pendiente) + ' Gs'"></span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════ MODAL NUEVA COMPRA (fullscreen) ═══════════════════════════════ -->
    <div x-show="showFormCompra" x-cloak class="fixed inset-0 z-50 bg-white dark:bg-slate-900"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0 opacity-100" x-transition:leave-end="translate-y-full opacity-0">
        
        <!-- Header fijo -->
        <div class="sticky top-0 z-10 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-3 py-2.5 flex items-center justify-between safe-area-top">
            <div class="flex items-center gap-2">
                <button @click="showFormCompra = false" class="w-9 h-9 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-600 dark:text-gray-300 active:scale-90 transition-transform">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h2 class="text-base font-bold text-gray-900 dark:text-white">Nueva Compra</h2>
            </div>
            <button @click="openFinalizeCompra()" :disabled="savingCompra"
                    class="h-9 px-4 bg-orange-600 hover:bg-orange-700 disabled:opacity-50 text-white rounded-lg text-sm font-semibold active:scale-95 transition-transform inline-flex items-center gap-1.5">
                <i class="fas" :class="savingCompra ? 'fa-spinner fa-spin' : 'fa-arrow-right'"></i>
                <span x-text="savingCompra ? 'Guardando...' : 'Finalizar'"></span>
            </button>
        </div>

        <!-- Form scroll -->
        <div class="overflow-y-auto pb-24" style="height: calc(100vh - 52px);">
            <div class="px-3 py-3 space-y-3">
                
                <!-- ══════ ZONA OCR / CÁMARA ══════ -->
                <div class="bg-gradient-to-br from-orange-50 to-amber-50 dark:from-slate-800 dark:to-slate-800 border-2 border-dashed border-orange-200 dark:border-slate-600 rounded-xl p-3"
                     :class="ocrDragOver ? 'border-orange-500 bg-orange-100 dark:bg-slate-700' : ''">
                    
                    <!-- Idle -->
                    <div x-show="!ocrProcessing && !ocrSuccess">
                        <div class="text-center mb-2.5">
                            <i class="fas fa-magic text-orange-400 text-lg"></i>
                            <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 mt-0.5">Cargar factura con OCR</p>
                        </div>
                        <div class="mb-2 inline-flex items-center justify-center gap-2 rounded-lg border border-sky-200 dark:border-sky-800 bg-sky-50 dark:bg-sky-950/40 px-3 py-1.5 w-full">
                            <i class="fas fa-cloud text-sky-600 dark:text-sky-400"></i>
                            <span class="text-[10px] font-semibold text-sky-700 dark:text-sky-300">Google Vision OCR</span>
                        </div>
                        <p class="text-[10px] text-center text-gray-500 dark:text-gray-400 mb-2">Google Vision OCR: motor principal para fotos, PDFs y documentos complejos.</p>
                        
                        <!-- Botón CÁMARA grande y prominente -->
                        <button @click="openWebcam('standard')" class="w-full flex items-center justify-center gap-2 py-3 bg-orange-600 hover:bg-orange-700 text-white rounded-xl font-semibold text-sm active:scale-[.97] transition-transform shadow-lg shadow-orange-500/25 mb-2">
                            <i class="fas fa-camera text-lg"></i> Sacar Foto de Factura
                        </button>
                        
                        <!-- Botones secundarios en fila -->
                        <div class="grid grid-cols-5 gap-1.5">
                            <button @click="pasteFromClipboard()" class="flex flex-col items-center gap-0.5 py-2 bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-lg text-[10px] font-medium active:scale-95 transition-transform">
                                <i class="fas fa-paste text-sm"></i> Pegar
                            </button>
                            <button @click="openWebcam('standard')" class="flex flex-col items-center gap-0.5 py-2 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 rounded-lg text-[10px] font-medium active:scale-95 transition-transform">
                                <i class="fas fa-video text-sm"></i> Webcam
                            </button>
                            <button @click="openWebcam('scanner')" class="flex flex-col items-center gap-0.5 py-2 bg-teal-100 dark:bg-teal-900/30 text-teal-700 dark:text-teal-400 rounded-lg text-[10px] font-medium active:scale-95 transition-transform">
                                <i class="fas fa-print text-sm"></i> Escáner
                            </button>
                            <label class="cursor-pointer flex flex-col items-center gap-0.5 py-2 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 rounded-lg text-[10px] font-medium active:scale-95 transition-transform">
                                <i class="fas fa-file-pdf text-sm"></i> PDF
                                <input type="file" accept=".pdf,image/*" @change="handleOcrFile($event)" class="hidden">
                            </label>
                            <label class="cursor-pointer flex flex-col items-center gap-0.5 py-2 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-lg text-[10px] font-medium active:scale-95 transition-transform">
                                <i class="fas fa-file-excel text-sm"></i> Excel
                                <input type="file" accept=".xlsx,.xls,.csv" @change="handleOcrFile($event)" class="hidden">
                            </label>
                        </div>
                    </div>
                    
                    <!-- Procesando -->
                    <div x-show="ocrProcessing" class="py-3 text-center">
                        <div class="w-8 h-8 border-3 border-orange-500 border-t-transparent rounded-full animate-spin mx-auto mb-2"></div>
                        <p class="text-sm font-medium text-orange-600 dark:text-orange-400 ocr-pulse" x-text="ocrStatusMsg"></p>
                        <div class="w-2/3 mx-auto h-1.5 bg-gray-200 dark:bg-slate-700 rounded-full mt-2 overflow-hidden">
                            <div class="h-full ocr-progress rounded-full" :style="'width:' + ocrProgress + '%'"></div>
                        </div>
                        <button type="button"
                                @click="cancelOcrProcess()"
                                class="mt-2 px-3 py-1 rounded-md text-[11px] font-semibold bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 active:scale-95 transition-transform">
                            Cancelar OCR
                        </button>
                    </div>
                    
                    <!-- Éxito -->
                    <div x-show="ocrSuccess" class="py-2 text-center">
                        <div class="flex items-center justify-center gap-2">
                            <i class="fas fa-check-circle text-green-500"></i>
                            <span class="text-xs font-medium text-green-600 dark:text-green-400">Datos cargados</span>
                            <button @click="ocrSuccess = false" class="text-gray-400 ml-1"><i class="fas fa-times text-xs"></i></button>
                        </div>
                    </div>
                    
                    <!-- Error -->
                    <div x-show="ocrError" class="mt-1">
                        <div class="flex items-center justify-center gap-1.5 bg-red-50 dark:bg-red-900/20 rounded-lg px-3 py-2">
                            <i class="fas fa-exclamation-triangle text-red-500 text-xs"></i>
                            <span class="text-xs text-red-600 dark:text-red-400" x-text="ocrError"></span>
                            <button @click="ocrError = ''" class="text-red-400 ml-1"><i class="fas fa-times text-xs"></i></button>
                        </div>
                    </div>
                </div>

                <!-- ══════ PROVEEDOR ══════ -->
                <div>
                    <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Proveedor</label>
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                        <input type="text" x-model="form.proveedorBuscar"
                               @input.debounce.300ms="buscarProveedor()"
                               @focus="showProveedores = true"
                               placeholder="Nombre o RUC..."
                               class="w-full pl-9 pr-3 py-2.5 border border-gray-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-orange-500">
                        <div x-show="showProveedores && proveedoresResults.length > 0" 
                             @click.away="showProveedores = false"
                             class="absolute z-50 w-full mt-1 bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-gray-200 dark:border-slate-700 max-h-40 overflow-y-auto">
                            <template x-for="prov in proveedoresResults" :key="prov.id">
                                <button @click="selectProveedor(prov)" 
                                        class="w-full px-3 py-2.5 text-left active:bg-gray-100 dark:active:bg-slate-700 flex justify-between items-center border-b border-gray-100 dark:border-slate-700/50 last:border-0">
                                    <span class="text-sm text-gray-900 dark:text-white" x-text="prov.nombre"></span>
                                    <span class="text-xs text-gray-500" x-text="prov.numero"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                    <p x-show="form.proveedorId" class="text-[10px] text-green-600 mt-0.5">
                        <i class="fas fa-check mr-0.5"></i> <span x-text="form.proveedorNombre"></span>
                    </p>
                </div>

                <!-- ══════ DATOS FACTURA ══════ -->
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">RUC</label>
                        <input type="text" x-model="form.proveedorRuc" placeholder="80012345-6"
                               class="w-full px-3 py-2.5 border border-gray-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Nro Factura</label>
                        <input type="text" x-model="form.nroFactura" placeholder="001-001-0000001"
                               class="w-full px-3 py-2.5 border border-gray-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Timbrado</label>
                        <input type="text" x-model="form.timbrado" placeholder="12345678"
                               class="w-full px-3 py-2.5 border border-gray-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Fecha</label>
                        <input type="date" x-model="form.fecha"
                               class="w-full px-3 py-2.5 border border-gray-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Forma Pago</label>
                        <select x-model="form.formaPago"
                                class="w-full px-3 py-2.5 border border-gray-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500">
                            <option value="1">Contado</option>
                            <option value="2">Crédito</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Moneda</label>
                        <select x-model="form.moneda" @change="recalculatePricingAll()"
                                class="w-full px-3 py-2.5 border border-gray-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500">
                            <option value="PYG">Guaraníes (Gs)</option>
                            <option value="BRL">Reales (R$)</option>
                        </select>
                    </div>
                    <div x-show="form.moneda === 'BRL'">
                        <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Cambio (R$ a Gs)</label>
                        <input type="number" x-model.number="form.cambio" @input="recalculatePricingAll()" min="1" step="1" placeholder="Ej: 1450"
                               class="w-full px-3 py-2.5 border border-gray-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Recarga %</label>
                        <input type="number" x-model.number="form.recargaPct" @input="recalculatePricingAll()" min="0" step="0.01" placeholder="Ej: 35"
                               class="w-full px-3 py-2.5 border border-gray-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500">
                    </div>
                </div>

                <!-- ══════ ITEMS (lista vertical mobile-friendly) ══════ -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase">Productos</label>
                        <button @click="addItem()" class="text-xs text-orange-600 font-semibold active:scale-95 transition-transform">
                            <i class="fas fa-plus mr-0.5"></i> Agregar
                        </button>
                    </div>
                    
                    <div class="space-y-2">
                        <template x-for="(item, idx) in form.items" :key="idx">
                            <div class="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-3 relative">
                                <!-- Botón eliminar -->
                                <button x-show="form.items.length > 1" @click="removeItem(idx)" 
                                        class="absolute top-2 right-2 w-6 h-6 rounded-full bg-red-50 dark:bg-red-900/20 text-red-400 flex items-center justify-center active:scale-90">
                                    <i class="fas fa-times text-[10px]"></i>
                                </button>
                                
                                <!-- Descripción -->
                                <input type="text" x-model="item.descripcion" placeholder="Descripción del producto"
                                       class="w-full px-0 py-1 border-0 border-b border-gray-200 dark:border-slate-700 bg-transparent text-sm font-medium text-gray-900 dark:text-white placeholder-gray-400 focus:ring-0 focus:border-orange-500 pr-8">

                                <!-- Código proveedor -->
                                <div class="mt-2">
                                    <label class="text-[9px] text-gray-400 uppercase">Código proveedor</label>
                                    <input type="text" x-model.trim="item.codigoProveedor" placeholder="Código del producto del proveedor"
                                           class="w-full px-2 py-1.5 border border-gray-200 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-xs text-gray-900 dark:text-white focus:ring-1 focus:ring-orange-500">
                                </div>
                                
                                <!-- Producto local -->
                                <div class="mt-2 relative">
                                    <div x-show="item.productoId" class="flex items-center gap-1">
                                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg text-[10px] text-green-700 dark:text-green-400 truncate flex-1">
                                            <i class="fas fa-check-circle"></i>
                                            <span class="truncate" x-text="item.productoBuscar || item.productoCodigo"></span>
                                        </span>
                                        <button @click="item.productoId = null; item.productoCodigo = ''; item.productoBuscar = ''" class="text-gray-400 active:text-red-500 shrink-0 p-1">
                                            <i class="fas fa-unlink text-[10px]"></i>
                                        </button>
                                    </div>
                                    <div x-show="!item.productoId">
                                        <div class="relative">
                                            <i class="fas fa-link absolute left-2 top-1/2 -translate-y-1/2 text-gray-300 text-[10px]"></i>
                                            <input type="text"
                                                   :value="item.productoBuscar"
                                                   @input="item.productoBuscar = $event.target.value; buscarProductoItem(idx, $event.target.value)"
                                                   @focus="buscarProductoItem(idx, item.productoBuscar || item.descripcion)"
                                                   @click.away="prodSearchIdx !== idx || (showProdDropdown = false)"
                                                   placeholder="Asociar producto local..."
                                                   class="w-full pl-6 pr-2 py-1.5 border border-dashed border-gray-300 dark:border-slate-600 rounded-lg bg-gray-50 dark:bg-slate-700/50 text-[11px] text-gray-700 dark:text-gray-300 focus:ring-1 focus:ring-orange-400 placeholder-gray-400">
                                            <!-- Dropdown -->
                                            <div x-show="showProdDropdown && prodSearchIdx === idx" x-cloak
                                                 class="absolute z-50 w-full mt-1 bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-gray-200 dark:border-slate-700 max-h-36 overflow-y-auto">
                                                <template x-for="prod in prodSearchResults" :key="prod.id">
                                                    <button @click="selectProductoItem(idx, prod)" 
                                                            class="w-full px-3 py-2 text-left active:bg-orange-50 dark:active:bg-slate-700 border-b border-gray-100 dark:border-slate-700/50 last:border-0">
                                                        <p class="text-xs font-medium text-gray-900 dark:text-white truncate" x-text="prod.descripcion"></p>
                                                        <p class="text-[10px] text-gray-400" x-text="prod.codigo + ' · Costo: ' + formatMoney(prod.precio_compra)"></p>
                                                    </button>
                                                </template>
                                                <div x-show="!prodSearching && prodSearchResults.length === 0" class="px-3 py-3 text-center">
                                                    <p class="text-[10px] text-gray-400 mb-1.5">No encontrado</p>
                                                    <button @click="abrirCrearProducto(idx)" class="px-3 py-1.5 bg-orange-600 text-white rounded-lg text-[10px] font-medium active:scale-95">
                                                        <i class="fas fa-plus mr-0.5"></i> Crear nuevo
                                                    </button>
                                                </div>
                                                <div x-show="prodSearching" class="px-3 py-2 text-center">
                                                    <i class="fas fa-spinner fa-spin text-orange-500 text-xs"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Cant / Costo / IVA en fila -->
                                <div class="grid grid-cols-3 gap-2 mt-2">
                                    <div>
                                        <label class="text-[9px] text-gray-400 uppercase">Cant</label>
                                        <input type="number" x-model.number="item.cantidad" min="1" step="1"
                                               class="w-full px-2 py-1.5 border border-gray-200 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-right text-gray-900 dark:text-white focus:ring-1 focus:ring-orange-500">
                                    </div>
                                    <div>
                                        <label class="text-[9px] text-gray-400 uppercase">Costo</label>
                                        <input type="text" :value="formatNumber(item.costo, 2, 2)" @input="onCostoInput($event, item)" inputmode="decimal"
                                               class="w-full px-2 py-1.5 border border-gray-200 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-right text-gray-900 dark:text-white focus:ring-1 focus:ring-orange-500">
                                    </div>
                                    <div>
                                        <label class="text-[9px] text-gray-400 uppercase">IVA</label>
                                        <select x-model="item.iva"
                                                class="w-full px-2 py-1.5 border border-gray-200 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white focus:ring-1 focus:ring-orange-500">
                                            <option value="10">10%</option>
                                            <option value="5">5%</option>
                                            <option value="0">Ext</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-2 mt-2">
                                    <div>
                                        <label class="text-[9px] text-gray-400 uppercase">Costo en Gs</label>
                                        <input type="text" :value="formatMoney(itemCostoGs(item))" readonly
                                               class="w-full px-2 py-1.5 border border-gray-200 dark:border-slate-600 rounded-lg bg-gray-50 dark:bg-slate-700/70 text-xs text-right text-gray-700 dark:text-gray-300">
                                    </div>
                                    <div>
                                        <label class="text-[9px] text-gray-400 uppercase">Precio venta (Gs)</label>
                                        <input type="text" :value="formatNumber(item.precioVenta, 0, 0)" @input="onPrecioVentaInput($event, item)" inputmode="numeric"
                                               class="w-full px-2 py-1.5 border border-gray-200 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-right text-gray-900 dark:text-white focus:ring-1 focus:ring-orange-500">
                                        <p class="text-[10px] text-gray-400 text-right mt-0.5" x-text="'Gs ' + formatMoney(item.precioVenta || 0)"></p>
                                    </div>
                                </div>
                                <!-- Subtotal -->
                                <div class="text-right mt-1.5">
                                    <span class="text-xs text-gray-400">Subtotal: </span>
                                    <span class="text-sm font-bold text-gray-900 dark:text-white" x-text="formatMoney(item.cantidad * itemCostoGs(item)) + ' Gs'"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- ══════ NOTA ══════ -->
                <div>
                    <label class="block text-[11px] font-semibold text-gray-500 dark:text-gray-400 uppercase mb-1">Nota (opcional)</label>
                    <textarea x-model="form.nota" rows="2" placeholder="Observaciones..."
                              class="w-full px-3 py-2 border border-gray-200 dark:border-slate-700 rounded-xl bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-orange-500 resize-none"></textarea>
                </div>

                <!-- ══════ TOTAL STICKY ══════ -->
                <div class="bg-orange-50 dark:bg-orange-900/20 rounded-xl p-4 text-center">
                    <p class="text-xs text-gray-500 uppercase mb-0.5">Total Compra</p>
                    <p class="text-2xl font-bold text-orange-600 dark:text-orange-400" x-text="formatMoney(formTotal) + ' Gs'"></p>
                </div>
            </div>
        </div>
    </div>

    <div x-show="showFinalizeCompraModal" x-cloak class="fixed inset-0 z-[65] bg-black/70 flex items-end justify-center"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
        <div class="checkout-panel-mobile w-full bg-slate-950 text-white rounded-t-3xl overflow-hidden max-h-[92vh]"
             @click.away="showFinalizeCompraModal = false"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full">
            <div class="flex justify-center pt-2 pb-1">
                <div class="w-10 h-1 bg-slate-600 rounded-full"></div>
            </div>
            <div class="px-4 pb-5 space-y-4 safe-area-bottom">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-black uppercase tracking-tight">Finalizar compra</h3>
                        <p class="text-[11px] text-slate-400">Definí comprobante y forma de pago.</p>
                    </div>
                    <button @click="showFinalizeCompraModal = false" class="w-9 h-9 rounded-xl bg-slate-800 border border-slate-700 flex items-center justify-center text-slate-300">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="checkout-section-mobile">
                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Tipo de comprobante</label>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button"
                                @click="finalizeCompra.tipoComprobante = 'FACTURA'"
                                class="relative rounded-xl border-2 py-3 px-3 text-sm font-bold transition-all text-center"
                                :class="finalizeCompra.tipoComprobante === 'FACTURA' ? 'border-amber-400 bg-amber-700 text-amber-100 ring-2 ring-amber-300 shadow-lg shadow-amber-900 scale-[1.01]' : 'border-slate-600 bg-slate-900 text-slate-400 hover:border-slate-500'">
                            <span class="inline-flex items-center gap-2 justify-center">
                                <span class="text-base">🧾</span>
                                <span>Factura</span>
                            </span>
                        </button>
                        <button type="button"
                                @click="finalizeCompra.tipoComprobante = 'NOTA'"
                                class="relative rounded-xl border-2 py-3 px-3 text-sm font-bold transition-all text-center"
                                :class="finalizeCompra.tipoComprobante === 'NOTA' ? 'border-amber-400 bg-amber-700 text-amber-100 ring-2 ring-amber-300 shadow-lg shadow-amber-900 scale-[1.01]' : 'border-slate-600 bg-slate-900 text-slate-400 hover:border-slate-500'">
                            <span class="inline-flex items-center gap-2 justify-center">
                                <span class="text-base">🗒️</span>
                                <span>Nota</span>
                            </span>
                        </button>
                    </div>
                </div>

                <div class="checkout-section-mobile">
                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Forma de pago</label>
                    <div class="grid grid-cols-3 gap-1.5">
                        <template x-for="pm in compraPaymentMethods" :key="pm.id">
                            <button type="button"
                                @click="selectCompraPaymentMethod(pm.id)"
                                class="relative rounded-xl border py-3 px-2 text-[10px] font-black transition-all flex flex-col items-center justify-center gap-1"
                                :style="pm.style"
                                :class="finalizeCompra.medioPago === pm.id ? pm.activeClass : ''">
                                <span class="text-2xl" x-text="pm.icon"></span>
                                <span x-text="pm.shortName || pm.name"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div x-show="!finalizeCompra.medioPago" class="checkout-section-mobile rounded-xl py-5 text-center">
                    <p class="text-sm font-semibold text-slate-300">Seleccione una forma de pago para continuar</p>
                </div>

                <div x-show="finalizeCompra.medioPago === 'tarjeta'" class="checkout-section-mobile space-y-2">
                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">Nro. Boucher</label>
                    <input type="text" x-model.trim="finalizeCompra.paymentRef"
                           class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white"
                           placeholder="Ingrese nro. boucher">
                </div>

                <div x-show="finalizeCompra.medioPago === 'transferencia'" class="checkout-section-mobile space-y-2">
                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">REF Transferencia</label>
                    <input type="text" x-model.trim="finalizeCompra.paymentRef"
                           class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white"
                           placeholder="Ingrese referencia">
                </div>

                <div x-show="finalizeCompra.medioPago === 'pix'" class="checkout-section-mobile space-y-2">
                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">Referencia PIX</label>
                    <input type="text" x-model.trim="finalizeCompra.paymentRef"
                           class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white"
                           placeholder="Ingrese referencia">
                </div>

                <div x-show="finalizeCompra.medioPago === 'credito'" class="checkout-section-mobile space-y-2">
                    <div class="rounded-xl border border-amber-500 bg-amber-900/40 p-3">
                        <div class="flex items-center gap-2 text-amber-300">
                            <span class="text-lg">⚠️</span>
                            <span class="text-xs font-bold">Esta compra quedará pendiente de pago.</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Cuotas</label>
                            <select x-model.number="finalizeCompra.creditInstallments"
                                    class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white">
                                <template x-for="n in [1,2,3,4,5,6,9,12]" :key="'mobile-credito-compra-' + n">
                                    <option :value="n" x-text="n + ' cuota(s)'"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Primer vencimiento</label>
                            <input type="date" x-model="finalizeCompra.creditDueDate"
                                   class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white">
                        </div>
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Observación de crédito</label>
                        <input type="text" x-model.trim="finalizeCompra.creditNotes" placeholder="Notas para proveedor o financiación"
                               class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white">
                    </div>
                </div>

                <div x-show="finalizeCompra.medioPago && finalizeCompra.medioPago !== 'efectivo'" class="checkout-section-mobile rounded-xl p-3.5 text-center border-emerald-500 bg-emerald-900/80">
                    <div class="text-[10px] font-bold uppercase tracking-wider text-emerald-300 mb-0.5">Cobertura</div>
                    <div class="text-2xl font-black text-emerald-300" x-text="formatMoney(formTotal) + ' Gs'"></div>
                </div>

                <div class="checkout-footer-mobile">
                    <div class="grid grid-cols-2 gap-2">
                    <button @click="showFinalizeCompraModal = false"
                            class="py-3 rounded-xl font-semibold text-base text-red-100 border border-red-500 bg-red-700 hover:bg-red-600 transition-all">
                        Cancelar
                    </button>
                    <button @click="guardarCompra()"
                            :disabled="savingCompra || !finalizeCompra.medioPago || (requiresCompraReference(finalizeCompra.medioPago) && !String(finalizeCompra.paymentRef || '').trim())"
                            class="py-3 rounded-xl font-bold text-base text-white bg-blue-600 hover:bg-blue-500 transition-all disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                        <template x-if="!savingCompra">
                            <span class="flex items-center gap-2"><i class="fas fa-save"></i> Guardar</span>
                        </template>
                        <template x-if="savingCompra">
                            <span class="flex items-center gap-2"><i class="fas fa-spinner fa-spin"></i> Procesando...</span>
                        </template>
                    </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════ MODAL CREAR PRODUCTO ═══════════════════════════════ -->
    <div x-show="showCrearProducto" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center bg-black/50"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
        <div class="w-full bg-white dark:bg-slate-800 rounded-t-2xl overflow-hidden max-h-[80vh]"
             @click.away="showCrearProducto = false"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full">
            <div class="flex justify-center pt-2 pb-1">
                <div class="w-10 h-1 bg-gray-300 dark:bg-slate-600 rounded-full"></div>
            </div>
            <div class="px-4 pb-2 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <i class="fas fa-box-open text-orange-500"></i>
                    <span class="font-semibold text-sm text-gray-900 dark:text-white">Crear Producto</span>
                </div>
                <button @click="showCrearProducto = false" class="w-8 h-8 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center justify-center text-gray-400">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>
            <div class="px-4 pb-5 space-y-3">
                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="text-[10px] text-gray-400 uppercase">Código</label>
                        <input type="text" x-model="nuevoProducto.codigo" placeholder="Auto"
                               class="w-full px-2.5 py-2.5 border border-gray-200 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500">
                    </div>
                    <div class="col-span-2">
                        <label class="text-[10px] text-gray-400 uppercase">Nombre *</label>
                        <input type="text" x-model="nuevoProducto.nombre" placeholder="Nombre"
                               class="w-full px-2.5 py-2.5 border border-gray-200 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500">
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div>
                        <label class="text-[10px] text-gray-400 uppercase">Costo</label>
                        <input type="number" x-model.number="nuevoProducto.precioCompra" min="0"
                               class="w-full px-2.5 py-2.5 border border-gray-200 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-700 text-sm text-right text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500">
                    </div>
                    <div>
                        <label class="text-[10px] text-gray-400 uppercase">Venta</label>
                        <input type="number" x-model.number="nuevoProducto.precioVenta" min="0"
                               class="w-full px-2.5 py-2.5 border border-gray-200 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-700 text-sm text-right text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500">
                    </div>
                    <div>
                        <label class="text-[10px] text-gray-400 uppercase">IVA</label>
                        <select x-model="nuevoProducto.iva"
                                class="w-full px-2.5 py-2.5 border border-gray-200 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500">
                            <option value="10">10%</option>
                            <option value="5">5%</option>
                            <option value="0">Exenta</option>
                        </select>
                    </div>
                </div>
                <button @click="guardarNuevoProducto()" :disabled="creandoProducto"
                        class="w-full py-3 bg-orange-600 hover:bg-orange-700 disabled:opacity-50 text-white rounded-xl font-semibold text-sm active:scale-[.98] transition-transform inline-flex items-center justify-center gap-2">
                    <i class="fas" :class="creandoProducto ? 'fa-spinner fa-spin' : 'fa-plus'"></i>
                    <span x-text="creandoProducto ? 'Creando...' : 'Crear y Asociar'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════ WEBCAM MODAL ═══════════════════════════════ -->
    <div x-show="showWebcam" x-cloak class="fixed inset-0 z-[60] bg-black flex flex-col"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
        <!-- Video fullscreen -->
        <div class="flex-1 relative flex items-center justify-center overflow-hidden camera-stage">
            <video x-ref="webcamVideo" autoplay playsinline class="w-full h-full object-cover" x-show="!webcamCaptured"></video>
            <canvas x-ref="webcamCanvas" class="w-full h-full object-contain" x-show="webcamCaptured" style="display:none;"></canvas>
            <div x-show="webcamLoading && !webcamCaptured" class="absolute inset-0 flex items-center justify-center">
                <div class="text-center text-white">
                    <i class="fas fa-spinner fa-spin text-3xl mb-2"></i>
                    <p class="text-sm">Iniciando cámara...</p>
                </div>
            </div>
            <div x-show="!webcamCaptured && !webcamLoading" class="absolute top-4 left-1/2 -translate-x-1/2 px-3 py-1.5 rounded-full bg-black/55 text-white text-xs font-semibold z-10">
                <span x-text="webcamMode === 'scanner' ? 'Modo Escáner' : 'Modo Foto'"></span>
            </div>
            <!-- Cerrar -->
            <button @click="closeWebcam()" class="absolute top-4 left-4 w-10 h-10 bg-black/50 rounded-full flex items-center justify-center text-white active:scale-90 z-10">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        <!-- Controls -->
        <div class="camera-controls bg-black/85 px-4 flex items-center justify-center gap-4 safe-area-bottom">
            <template x-if="!webcamCaptured">
                <button @click="captureWebcam()" :disabled="webcamLoading"
                        class="w-16 h-16 bg-white rounded-full flex items-center justify-center active:scale-90 transition-transform disabled:opacity-50 shadow-xl">
                    <div class="w-14 h-14 border-4 border-orange-600 rounded-full flex items-center justify-center">
                        <i class="fas fa-camera text-orange-600 text-xl"></i>
                    </div>
                </button>
            </template>
            <template x-if="webcamCaptured">
                <div class="flex gap-4 w-full justify-center">
                    <button @click="webcamCaptured = false" class="flex-1 max-w-[140px] py-3 bg-gray-700 text-white rounded-xl font-medium active:scale-95 transition-transform inline-flex items-center justify-center gap-2">
                        <i class="fas fa-redo"></i> Repetir
                    </button>
                    <button @click="useWebcamCapture()" class="flex-1 max-w-[180px] py-3 bg-orange-600 text-white rounded-xl font-medium active:scale-95 transition-transform inline-flex items-center justify-center gap-2 shadow-lg shadow-orange-500/30">
                        <i class="fas fa-magic"></i> <span x-text="webcamMode === 'scanner' ? 'Escanear factura' : 'Procesar OCR'"></span>
                    </button>
                </div>
            </template>
        </div>
    </div>

    <!-- ═══════════════════════════════ JAVASCRIPT ═══════════════════════════════ -->
    <script>
    function comprasMobileApp() {
        return {
            idEmpresa: <?= $id_empresa ?>,
            isDark: document.documentElement.classList.contains('dark'),
            permisos: window.__PERMISOS__ || {},
            
            compras: [],
            stats: { totalCompras: 0, montoTotal: 0, comprasHoy: 0, totalPendiente: 0 },
            
            searchQuery: '',
            fechaDesde: '',
            fechaHasta: '',
            estadoFiltro: 'activo',
            periodoActivo: 'hoy',
            periodos: [
                { key: 'hoy', label: 'Hoy' },
                { key: 'semana', label: 'Semana' },
                { key: 'mes', label: 'Mes' },
                { key: 'anio', label: 'Año' }
            ],
            
            currentPage: 1,
            perPage: 20,
            totalRecords: 0,
            totalPages: 1,
            
            loading: true,
            useTabletGrid: window.innerWidth >= 768,
            gridApiComprasTablet: null,
            gridVisibleRows: 0,
            showModal: false,
            selectedCompra: null,
            compraItems: [],
            showFormCompra: false,
            showFinalizeCompraModal: false,
            savingCompra: false,
            showProveedores: false,
            proveedoresResults: [],
            
            ocrProcessing: false,
            ocrSuccess: false,
            ocrError: '',
            ocrStatusMsg: 'Analizando...',
            ocrProgress: 0,
            ocrFileName: '',
            ocrDragOver: false,
            ocrMode: 'google_vision',
            ocrCaptureMode: 'standard',
            ocrPasteHandler: null,
            ocrAbortController: null,
            ocrCancelled: false,
            
            showWebcam: false,
            webcamStream: null,
            webcamCaptured: false,
            webcamLoading: false,
            webcamMode: 'standard',
            
            prodSearchIdx: null,
            prodSearchResults: [],
            prodSearching: false,
            showProdDropdown: false,
            showCrearProducto: false,
            nuevoProducto: { codigo: '', nombre: '', precioCompra: 0, precioVenta: 0, iva: '10' },
            creandoProducto: false,
            compraPaymentMethods: [
                { id: 'efectivo', name: 'Efectivo', shortName: 'Efectivo', icon: '💵', style: 'background:rgba(16,185,129,.22); border-color:rgba(16,185,129,.65); color:#d1fae5;', activeClass: 'ring-2 ring-emerald-300/80 shadow-lg shadow-emerald-500/30 scale-[1.02]' },
                { id: 'tarjeta', name: 'Tarjeta', shortName: 'Tarjeta', icon: '💳', style: 'background:rgba(14,165,233,.22); border-color:rgba(14,165,233,.65); color:#e0f2fe;', activeClass: 'ring-2 ring-sky-300/80 shadow-lg shadow-sky-500/30 scale-[1.02]' },
                { id: 'transferencia', name: 'Transferencia', shortName: 'Transfer.', icon: '🏦', style: 'background:rgba(139,92,246,.22); border-color:rgba(139,92,246,.65); color:#ede9fe;', activeClass: 'ring-2 ring-violet-300/80 shadow-lg shadow-violet-500/30 scale-[1.02]' },
                { id: 'pix', name: 'QR / PIX', shortName: 'Pix', icon: '📱', style: 'background:rgba(217,70,239,.22); border-color:rgba(217,70,239,.65); color:#fae8ff;', activeClass: 'ring-2 ring-fuchsia-300/80 shadow-lg shadow-fuchsia-500/30 scale-[1.02]' },
                { id: 'credito', name: 'Crédito', shortName: 'Crédito', icon: '📅', style: 'background:rgba(245,158,11,.22); border-color:rgba(245,158,11,.65); color:#fef3c7;', activeClass: 'ring-2 ring-amber-300/80 shadow-lg shadow-amber-500/30 scale-[1.02]' }
            ],
            finalizeCompra: {
                tipoComprobante: 'FACTURA',
                medioPago: 'efectivo',
                paymentRef: '',
                creditInstallments: 1,
                creditDueDate: '',
                creditNotes: ''
            },
            offlineQueue: [],
            
                form: {
                    proveedorId: null, proveedorNombre: '', proveedorRuc: '', proveedorBuscar: '',
                    nroFactura: '', timbrado: '',
                    fecha: (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; })(),
                    formaPago: '1', moneda: 'PYG', cambio: 1, recargaPct: 35, nota: '',
                    items: [{ descripcion: '', codigoProveedor: '', cantidad: 1, costo: 0, precioVenta: 0, iva: '10', productoId: null, productoCodigo: '', productoBuscar: '' }]
                },
            
            get formTotal() {
                return this.form.items.reduce((s, i) => s + (i.cantidad * this.itemCostoGs(i)), 0);
            },
            offlineKey(suffix) {
                return `smx:compras:mobile:${this.idEmpresa}:${suffix}`;
            },
            readStorage(key, fallback = null) {
                return window.SmxOfflineDb?.getSync(key, fallback) ?? fallback;
            },
            writeStorage(key, data) {
                return window.SmxOfflineDb?.set(key, data);
            },
            buildOfflineCompra(payload) {
                const total = Array.isArray(payload.items)
                    ? payload.items.reduce((sum, item) => sum + (Number(item.cantidad || 0) * Number(item.costo_gs || item.costo || 0)), 0)
                    : 0;
                return {
                    id_factura: `offline-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                    nro_factura: payload.nro_factura || 'OFFLINE',
                    timbrado: payload.timbrado || '',
                    fecha: payload.fecha || this.localDateStr(new Date()),
                    proveedor_nombre: payload.proveedor_nombre || 'Sin proveedor',
                    proveedor_ruc: payload.ruc || '',
                    total,
                    pendiente: payload.forma_pago === 2 ? total : 0,
                    estado: 1,
                    offline_pending: true,
                };
            },
            mergeQueuedCompras(items) {
                const seen = new Set();
                return [...this.offlineQueue.map(entry => entry.preview).filter(Boolean), ...(Array.isArray(items) ? items : [])]
                    .filter((row) => {
                        const key = String(row.id_factura || '');
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
                        const res = await fetch('/modelos/compras_api.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(entry.payload)
                        });
                        const data = await res.json();
                        if (!data.success) throw new Error(data.message || 'No se pudo sincronizar compra');
                    } catch (_) {
                        remaining.push(entry);
                    }
                }
                this.offlineQueue = remaining;
                this.writeStorage(this.offlineKey('queue'), remaining);
                await this.loadCompras();
            },
            
            async init() {
                await (window.SmxOfflineDb?.ready || Promise.resolve());
                this.offlineQueue = this.readStorage(this.offlineKey('queue'), []);
                window.addEventListener('online', () => this.syncOfflineQueue());
                this.setPeriodo('hoy', false);
                if (this.useTabletGrid && window.__agGridReady) {
                    await window.__agGridReady;
                    this.ensureTabletGrid();
                }
                this.loadCompras();
                this.setupOcrPasteListener();
                window.addEventListener('resize', () => this.handleViewportChange());
                this.syncOfflineQueue();
            },
            handleViewportChange() {
                const nextTablet = window.innerWidth >= 768;
                if (nextTablet === this.useTabletGrid) {
                    if (nextTablet) this.gridApiComprasTablet?.sizeColumnsToFit?.();
                    return;
                }
                this.useTabletGrid = nextTablet;
                if (this.useTabletGrid) {
                    this.$nextTick(async () => {
                        if (window.__agGridReady) await window.__agGridReady;
                        this.ensureTabletGrid();
                        this.loadCompras();
                    });
                    return;
                }
                this.loadCompras();
            },
            _applyAgGridLicense() {
                if (typeof agGrid !== 'undefined' && agGrid.LicenseManager) {
                    agGrid.LicenseManager.setLicenseKey('DownloadDevTools_COM_NDEwMjM0NTgwMDAwMA==59158b5225400879a12a96634544f5b6');
                }
            },
            syncTabletGridTheme() {
                const gridEl = this.$refs.gridComprasTablet;
                if (!gridEl) return;
                gridEl.classList.toggle('ag-theme-quartz', !this.isDark);
                gridEl.classList.toggle('ag-theme-quartz-dark', this.isDark);
            },
            ensureTabletGrid() {
                if (!this.useTabletGrid || !window.agGrid || this.gridApiComprasTablet || !this.$refs.gridComprasTablet) return;
                this._applyAgGridLicense();
                const columnDefs = [
                    {
                        field: 'nro_factura',
                        headerName: 'Nro / Timb.',
                        minWidth: 190,
                        sortable: true,
                        cellRenderer: (p) => {
                            const nro = this.escapeHtml(p.data?.nro_factura || '-');
                            const timb = this.escapeHtml(p.data?.timbrado || '');
                            return `<div class="tablet-compra-stack"><div class="main" style="font-family:ui-monospace, SFMono-Regular, monospace;">${nro}</div>${timb ? `<div class="meta">Timb: ${timb}</div>` : ''}</div>`;
                        }
                    },
                    {
                        field: 'fecha',
                        headerName: 'Fecha',
                        minWidth: 160,
                        sortable: true,
                        cellRenderer: (p) => {
                            const fecha = this.escapeHtml(this.formatDate(p.data?.fecha));
                            const venc = p.data?.vencimiento ? this.escapeHtml(this.formatDate(p.data?.vencimiento)) : '';
                            return `<div class="tablet-compra-stack"><div class="main" style="font-weight:500;">${fecha}</div>${venc ? `<div class="meta">Venc: ${venc}</div>` : ''}</div>`;
                        }
                    },
                    {
                        field: 'proveedor_nombre',
                        headerName: 'Proveedor',
                        minWidth: 220,
                        flex: 1.2,
                        sortable: true,
                        cellRenderer: (p) => {
                            const prov = this.escapeHtml(p.data?.proveedor_nombre || 'Sin proveedor');
                            const ruc = this.escapeHtml(p.data?.proveedor_ruc || '-');
                            return `<div class="tablet-compra-stack"><div class="main">${prov}</div><div class="meta">${ruc}</div></div>`;
                        }
                    },
                    {
                        field: 'total',
                        headerName: 'Total',
                        minWidth: 130,
                        sortable: true,
                        type: 'numericColumn',
                        cellStyle: { textAlign: 'right' },
                        valueFormatter: (p) => `${this.formatMoney(p.value)} Gs`
                    },
                    {
                        field: 'pendiente',
                        headerName: 'Pendiente',
                        minWidth: 140,
                        sortable: true,
                        type: 'numericColumn',
                        cellStyle: { textAlign: 'right' },
                        cellRenderer: (p) => {
                            const cls = Number(p.value || 0) > 0 ? 'color:#dc2626;font-weight:700;' : 'color:#16a34a;font-weight:700;';
                            return `<span style="${cls}">${this.escapeHtml(this.formatMoney(p.value || 0))} Gs</span>`;
                        }
                    },
                    {
                        field: 'estado',
                        headerName: 'Estado',
                        minWidth: 110,
                        sortable: true,
                        cellStyle: { textAlign: 'center' },
                        cellRenderer: (p) => Number(p.value || 0) === 1
                            ? '<span class="tablet-badge ok">Activo</span>'
                            : '<span class="tablet-badge off">Anulado</span>'
                    },
                    {
                        field: 'acciones',
                        headerName: 'Acciones',
                        minWidth: 120,
                        maxWidth: 132,
                        sortable: false,
                        filter: false,
                        suppressHeaderMenuButton: true,
                        cellRenderer: (p) => this.renderTabletGridActions(p.data || {})
                    }
                ];
                const gridOpts = {
                    columnDefs,
                    localeText: window.SmxAgGridLocale?.getLocaleText?.() || {},
                    rowModelType: 'infinite',
                    cacheBlockSize: this.perPage,
                    pagination: false,
                    rowHeight: 58,
                    animateRows: true,
                    defaultColDef: {
                        resizable: true,
                        sortable: true,
                        filter: false
                    },
                    onGridReady: () => {
                        this.syncTabletGridTheme();
                        this.resetTabletGridDatasource();
                        setTimeout(() => this.gridApiComprasTablet?.sizeColumnsToFit?.(), 80);
                    },
                    onGridSizeChanged: () => this.gridApiComprasTablet?.sizeColumnsToFit?.(),
                    onModelUpdated: () => {
                        this.gridVisibleRows = Number(this.gridApiComprasTablet?.getDisplayedRowCount?.() || 0);
                    },
                    onCellClicked: (params) => this.onTabletGridCellClicked(params),
                    onRowClicked: (params) => {
                        const target = params.event?.target;
                        if (target?.closest?.('button[data-action]')) return;
                        this.verCompra(params.data);
                    }
                };
                this.gridApiComprasTablet = agGrid.createGrid(this.$refs.gridComprasTablet, gridOpts);
            },
            resetTabletGridDatasource() {
                if (!this.gridApiComprasTablet) return;
                const self = this;
                const ds = {
                    rowCount: undefined,
                    async getRows(params) {
                        try {
                            const start = Number(params.startRow || 0);
                            const perPage = self.perPage;
                            const page = Math.floor(start / perPage) + 1;
                            const sort = Array.isArray(params.sortModel) && params.sortModel.length ? params.sortModel[0] : null;
                            const sortBy = String(sort?.colId || 'fecha');
                            const sortDir = String(sort?.sort || 'desc').toLowerCase() === 'asc' ? 'asc' : 'desc';
                            const query = new URLSearchParams({
                                id_empresa: String(self.idEmpresa),
                                page: String(page),
                                per_page: String(perPage),
                                search: String(self.searchQuery || ''),
                                fecha_desde: String(self.fechaDesde || ''),
                                fecha_hasta: String(self.fechaHasta || ''),
                                estado: String(self.estadoFiltro || ''),
                                sort_by: sortBy,
                                sort_dir: sortDir
                            });
                            const res = await fetch(`/public/compras/api/list.php?${query.toString()}`);
                            const data = await res.json();
                            if (!data.success) throw new Error(data.message || 'Error');
                            const rows = Array.isArray(data.compras) ? data.compras : [];
                            self.compras = self.mergeQueuedCompras(rows);
                            self.totalRecords = Math.max(Number(data.total || 0) + self.offlineQueue.length, self.compras.length);
                            self.totalPages = Math.ceil(self.totalRecords / perPage) || 1;
                            self.currentPage = Number(data.page || page);
                            self.stats = data.stats || self.stats;
                            self.loading = false;
                            self.writeStorage(self.offlineKey(`list:${query.toString()}`), {
                                compras: rows,
                                totalRecords: self.totalRecords,
                                totalPages: self.totalPages,
                                stats: self.stats,
                            });
                            params.successCallback(self.compras, self.totalRecords);
                            setTimeout(() => {
                                self.gridVisibleRows = Number(self.gridApiComprasTablet?.getDisplayedRowCount?.() || 0);
                            }, 0);
                        } catch (e) {
                            const query = new URLSearchParams({
                                id_empresa: String(self.idEmpresa),
                                page: String(Math.floor(Number(params.startRow || 0) / self.perPage) + 1),
                                per_page: String(self.perPage),
                                search: String(self.searchQuery || ''),
                                fecha_desde: String(self.fechaDesde || ''),
                                fecha_hasta: String(self.fechaHasta || ''),
                                estado: String(self.estadoFiltro || ''),
                                sort_by: String((Array.isArray(params.sortModel) && params.sortModel[0]?.colId) || 'fecha'),
                                sort_dir: String((Array.isArray(params.sortModel) && params.sortModel[0]?.sort) || 'desc')
                            });
                            const cached = self.readStorage(self.offlineKey(`list:${query.toString()}`));
                            if (cached) {
                                self.compras = self.mergeQueuedCompras(cached.compras || []);
                                self.totalRecords = Math.max(Number(cached.totalRecords || 0) + self.offlineQueue.length, self.compras.length);
                                self.totalPages = cached.totalPages || 1;
                                self.stats = cached.stats || self.stats;
                                self.loading = false;
                                params.successCallback(self.compras, self.totalRecords);
                                setTimeout(() => {
                                    self.gridVisibleRows = Number(self.gridApiComprasTablet?.getDisplayedRowCount?.() || 0);
                                }, 0);
                                return;
                            }
                            console.error(e);
                            self.loading = false;
                            params.failCallback();
                        }
                    }
                };
                this.gridApiComprasTablet.setGridOption('datasource', ds);
                this.gridApiComprasTablet.refreshInfiniteCache?.();
            },
            renderTabletGridActions(compra) {
                const id = Number(compra.id_factura || 0);
                return `<div class="tablet-grid-actions">
                    <button class="view" data-action="view" data-id="${id}" title="Ver"><i class="fas fa-eye"></i></button>
                    <button class="print" data-action="print" data-id="${id}" title="Imprimir"><i class="fas fa-print"></i></button>
                </div>`;
            },
            findCompraById(idFactura) {
                const id = Number(idFactura || 0);
                return (Array.isArray(this.compras) ? this.compras : []).find(c => Number(c.id_factura || 0) === id) || null;
            },
            onTabletGridCellClicked(params) {
                const actionEl = params.event?.target?.closest?.('button[data-action]');
                if (!actionEl) return;
                const compra = this.findCompraById(actionEl.dataset.id) || params.data || null;
                if (!compra) return;
                const action = actionEl.dataset.action;
                if (action === 'view') this.verCompra(compra);
                if (action === 'print') this.imprimirCompra(compra);
            },
            async clearAppCache() {
                try {
                    if ('caches' in window) {
                        const keys = await caches.keys();
                        await Promise.all(keys.map(key => caches.delete(key)));
                    }
                } catch (_) {}
                try {
                    sessionStorage.removeItem('compras_mobile_state');
                } catch (_) {}
                const url = new URL(window.location.href);
                url.searchParams.set('_ts', String(Date.now()));
                window.location.replace(url.toString());
            },
            
            localDateStr(d) {
                const y = d.getFullYear();
                const m = String(d.getMonth() + 1).padStart(2, '0');
                const dd = String(d.getDate()).padStart(2, '0');
                return `${y}-${m}-${dd}`;
            },
            setPeriodo(key, reload = true) {
                this.periodoActivo = key;
                const today = new Date();
                const fmt = d => this.localDateStr(d);
                this.fechaHasta = fmt(today);
                switch (key) {
                    case 'hoy': this.fechaDesde = fmt(today); break;
                    case 'semana': {
                        const dow = today.getDay();
                        const mon = new Date(today);
                        mon.setDate(today.getDate() - (dow === 0 ? 6 : dow - 1));
                        this.fechaDesde = fmt(mon); break;
                    }
                    case 'mes': this.fechaDesde = fmt(new Date(today.getFullYear(), today.getMonth(), 1)); break;
                    case 'anio': this.fechaDesde = fmt(new Date(today.getFullYear(), 0, 1)); break;
                }
                this.currentPage = 1;
                if (reload) this.loadCompras();
            },
            requiresCompraReference(medio) {
                return ['tarjeta', 'transferencia', 'pix'].includes(String(medio || '').toLowerCase());
            },
            selectCompraPaymentMethod(method) {
                const prev = String(this.finalizeCompra.medioPago || '');
                this.finalizeCompra.medioPago = method;
                if (prev !== method) {
                    this.finalizeCompra.paymentRef = '';
                    if (method !== 'credito') {
                        this.finalizeCompra.creditInstallments = 1;
                        this.finalizeCompra.creditDueDate = '';
                        this.finalizeCompra.creditNotes = '';
                    }
                }
            },
            openFinalizeCompra() {
                const proveedorNombre = (this.form.proveedorNombre || this.form.proveedorBuscar || '').trim();
                if (!this.form.proveedorId && !proveedorNombre) { alert('Seleccioná o escribí el proveedor'); return; }
                if (!this.form.nroFactura.trim()) { alert('Ingresá el número de factura'); return; }
                if (this.form.moneda === 'BRL' && (!this.form.cambio || this.form.cambio <= 0)) { alert('Ingresá el cambio de R$ a Gs'); return; }
                const itemsValidos = this.form.items.filter(i => i.descripcion && i.cantidad > 0 && i.costo > 0);
                if (itemsValidos.length === 0) { alert('Agregá al menos un producto'); return; }
                this.showFinalizeCompraModal = true;
            },
            
            async loadCompras() {
                if (this.useTabletGrid && this.gridApiComprasTablet) {
                    this.loading = true;
                    this.resetTabletGridDatasource();
                    return;
                }
                this.loading = true;
                const params = new URLSearchParams({
                    id_empresa: this.idEmpresa, page: this.currentPage, per_page: this.perPage,
                    search: this.searchQuery, fecha_desde: this.fechaDesde, fecha_hasta: this.fechaHasta, estado: this.estadoFiltro
                });
                try {
                    const res = await fetch(`/public/compras/api/list.php?${params}`);
                    const data = await res.json();
                    if (data.success) {
                        this.compras = this.mergeQueuedCompras(data.compras || []);
                        this.totalRecords = Math.max(Number(data.total || 0) + this.offlineQueue.length, this.compras.length);
                        this.totalPages = Math.ceil(this.totalRecords / this.perPage) || 1;
                        this.stats = data.stats || this.stats;
                        this.gridVisibleRows = this.compras.length;
                        this.writeStorage(this.offlineKey(`list:${params.toString()}`), {
                            compras: data.compras || [],
                            totalRecords: this.totalRecords,
                            totalPages: this.totalPages,
                            stats: this.stats,
                        });
                    }
                } catch (e) {
                    const cached = this.readStorage(this.offlineKey(`list:${params.toString()}`));
                    if (cached) {
                        this.compras = this.mergeQueuedCompras(cached.compras || []);
                        this.totalRecords = Math.max(Number(cached.totalRecords || 0) + this.offlineQueue.length, this.compras.length);
                        this.totalPages = cached.totalPages || 1;
                        this.stats = cached.stats || this.stats;
                        this.gridVisibleRows = this.compras.length;
                        alert('Mostrando compras desde cache offline');
                    } else {
                        console.error(e);
                    }
                }
                this.loading = false;
            },
            
            async verCompra(compra) {
                this.selectedCompra = compra;
                this.compraItems = [];
                this.showModal = true;
                try {
                    const res = await fetch(`/modelos/compras_api.php?action=get&id_factura=${compra.id_factura}&id_empresa=${this.idEmpresa}`);
                    const data = await res.json();
                    if (data.success) this.compraItems = data.items || [];
                } catch (e) { console.error(e); }
            },
            imprimirCompra(compra) {
                if (!compra?.id_factura) return;
                window.open(`/modelos/compras_api.php?action=print&id_factura=${compra.id_factura}&id_empresa=${this.idEmpresa}`, '_blank');
            },
            
            async buscarProveedor() {
                if (this.form.proveedorBuscar.length < 2) { this.proveedoresResults = []; return; }
                try {
                    const q = encodeURIComponent(this.form.proveedorBuscar);
                    const base = `/modelos/compras_api.php?id_empresa=${this.idEmpresa}&q=${q}&_t=${Date.now()}`;
                    let res = await fetch(`${base}&action=search_proveedor`, { cache: 'no-store' });
                    if (!res.ok) {
                        res = await fetch(`${base}&action=search_proveedores`, { cache: 'no-store' });
                    }
                    if (!res.ok) {
                        this.proveedoresResults = [];
                        this.showProveedores = false;
                        return;
                    }
                    const data = await res.json();
                    this.proveedoresResults = (data && data.success) ? (data.proveedores || []) : [];
                    this.showProveedores = this.proveedoresResults.length > 0;
                } catch (e) { console.error(e); }
            },
            
            selectProveedor(prov) {
                this.form.proveedorId = prov.id;
                this.form.proveedorNombre = prov.nombre;
                this.form.proveedorRuc = prov.numero;
                this.form.proveedorBuscar = prov.nombre;
                this.showProveedores = false;
            },
            
            addItem() {
                this.form.items.push({ descripcion: '', codigoProveedor: '', cantidad: 1, costo: 0, precioVenta: 0, iva: '10', productoId: null, productoCodigo: '', productoBuscar: '' });
                this.recalculatePricingAll();
            },
            removeItem(idx) { this.form.items.splice(idx, 1); },
            
            resetForm() {
                this.form = {
                    proveedorId: null, proveedorNombre: '', proveedorRuc: '', proveedorBuscar: '',
                    nroFactura: '', timbrado: '',
                    fecha: this.localDateStr(new Date()),
                    formaPago: '1', moneda: 'PYG', cambio: 1, recargaPct: 35, nota: '',
                    items: [{ descripcion: '', codigoProveedor: '', cantidad: 1, costo: 0, precioVenta: 0, iva: '10', productoId: null, productoCodigo: '', productoBuscar: '' }]
                };
                this.finalizeCompra = { tipoComprobante: 'FACTURA', medioPago: 'efectivo', paymentRef: '', creditInstallments: 1, creditDueDate: '', creditNotes: '' };
                this.showFinalizeCompraModal = false;
                this.ocrSuccess = false; this.ocrError = ''; this.ocrProcessing = false; this.ocrFileName = '';
            },
            itemCostoGs(item) {
                const costo = parseFloat(item?.costo) || 0;
                if (this.form.moneda === 'BRL') {
                    const fx = parseFloat(this.form.cambio) || 0;
                    return fx > 0 ? Math.round(costo * fx) : 0;
                }
                return Math.round(costo);
            },
            calculateVentaByRecarga(item) {
                const baseGs = this.itemCostoGs(item);
                const pct = Math.max(0, parseFloat(this.form.recargaPct) || 0);
                return Math.round(baseGs * (1 + (pct / 100)));
            },
            recalculatePricingAll() {
                this.form.items.forEach(item => {
                    item.precioVenta = this.calculateVentaByRecarga(item);
                });
            },
            parseLocalizedNumber(value) {
                if (value === null || value === undefined) return 0;
                const str = String(value).replace(/[^\d.,-]/g, '');
                if (!str) return 0;
                const hasComma = str.includes(',');
                const hasDot = str.includes('.');
                let normalized = str;
                if (hasComma && hasDot) {
                    normalized = str.replace(/\./g, '').replace(',', '.');
                } else if (hasComma) {
                    normalized = str.replace(',', '.');
                }
                const num = parseFloat(normalized);
                return Number.isFinite(num) ? num : 0;
            },
            formatNumber(value, min = 0, max = 2) {
                const n = Number(value || 0);
                return new Intl.NumberFormat('es-PY', { minimumFractionDigits: min, maximumFractionDigits: max }).format(n);
            },
            onCostoInput(event, item) {
                const num = this.parseLocalizedNumber(event.target.value);
                item.costo = parseFloat(num.toFixed(2));
                item.precioVenta = this.calculateVentaByRecarga(item);
                event.target.value = this.formatNumber(item.costo, 2, 2);
            },
            onPrecioVentaInput(event, item) {
                const num = this.parseLocalizedNumber(event.target.value);
                item.precioVenta = Math.round(num);
                event.target.value = this.formatNumber(item.precioVenta, 0, 0);
            },
            
            async guardarCompra() {
                const proveedorNombre = (this.form.proveedorNombre || this.form.proveedorBuscar || '').trim();
                const itemsValidos = this.form.items.filter(i => i.descripcion && i.cantidad > 0 && i.costo > 0);
                if (this.requiresCompraReference(this.finalizeCompra.medioPago) && !String(this.finalizeCompra.paymentRef || '').trim()) {
                    alert('Ingresá la referencia del medio de pago');
                    return;
                }
                
                this.savingCompra = true;
                try {
                    const fx = this.form.moneda === 'BRL' ? (parseFloat(this.form.cambio) || 0) : 1;
                    const payload = {
                        action: 'save', id_empresa: this.idEmpresa, id_proveedor: this.form.proveedorId || 0, id_cliente: this.form.proveedorId || 0,
                        proveedor_nombre: proveedorNombre, ruc: (this.form.proveedorRuc || '').trim(),
                        nro_factura: this.form.nroFactura, timbrado: this.form.timbrado,
                        fecha: this.form.fecha,
                        forma_pago: this.finalizeCompra.medioPago === 'credito' ? 2 : 1,
                        medio_pago: this.finalizeCompra.medioPago,
                        tipo_comprobante: this.finalizeCompra.tipoComprobante,
                        payment_ref: this.finalizeCompra.paymentRef,
                        credit_installments: this.finalizeCompra.medioPago === 'credito' ? Math.max(parseInt(this.finalizeCompra.creditInstallments, 10) || 1, 1) : 1,
                        credit_due_date: this.finalizeCompra.medioPago === 'credito' ? this.finalizeCompra.creditDueDate : '',
                        credit_notes: this.finalizeCompra.medioPago === 'credito' ? this.finalizeCompra.creditNotes : '',
                        nota: this.form.nota,
                        moneda: this.form.moneda, cambio: fx,
                        items: itemsValidos.map(i => ({
                            descripcion: i.descripcion,
                            cantidad: i.cantidad,
                            costo: this.form.moneda === 'BRL' ? Math.round((parseFloat(i.costo) || 0) * fx) : (parseFloat(i.costo) || 0),
                            costo_gs: this.form.moneda === 'BRL' ? Math.round((parseFloat(i.costo) || 0) * fx) : (parseFloat(i.costo) || 0),
                            costo_moneda: parseFloat(i.costo) || 0,
                            venta_ocr: parseFloat(i.precioVenta) > 0 ? Math.round(parseFloat(i.precioVenta)) : this.calculateVentaByRecarga(i),
                            tipo_iva: parseInt(i.iva),
                            codigo: (i.codigoProveedor || i.productoCodigo || '').trim()
                        }))
                    };
                    if (!navigator.onLine) {
                        this.offlineQueue.unshift({ payload, preview: this.buildOfflineCompra(payload) });
                        this.writeStorage(this.offlineKey('queue'), this.offlineQueue);
                        this.showFinalizeCompraModal = false;
                        this.showFormCompra = false;
                        this.resetForm();
                        this.currentPage = 1;
                        await this.loadCompras();
                        alert('Compra guardada offline. Se sincronizará al volver internet.');
                        return;
                    }
                    const res = await fetch('/modelos/compras_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
                    const data = await res.json();
                    if (data.success) {
                        const nuevos = Array.isArray(data.productos_nuevos) ? data.productos_nuevos : [];
                        if (nuevos.length > 0) {
                            const det = nuevos.slice(0, 10).map(p => `- ${p.codigo || 'SIN-COD'} | ${p.descripcion || 'Sin descripción'} | Costo: ${this.formatMoney(p.costo || 0)} | Venta: ${this.formatMoney(p.precio_venta || 0)}`).join('\n');
                            alert(`Se cargaron ${nuevos.length} producto(s) nuevo(s):\n${det}`);
                        }
                        this.showFinalizeCompraModal = false;
                        this.showFormCompra = false; this.resetForm(); this.currentPage = 1; this.setPeriodo(this.periodoActivo);
                    }
                    else alert(data.message || 'Error al guardar');
                } catch (e) { alert('Error de conexión'); console.error(e); }
                this.savingCompra = false;
            },
            
            // ===== PRODUCTO ASOCIAR =====
            async buscarProductoItem(idx, query) {
                this.prodSearchIdx = idx;
                this.showProdDropdown = true;
                const q = (query || '').trim();
                if (q.length < 1) { this.prodSearchResults = []; return; }
                this.prodSearching = true;
                try {
                    const res = await fetch(`/public/pos/api/productos.php?action=search&q=${encodeURIComponent(q)}&estado=1`);
                    const data = await res.json();
                    this.prodSearchResults = (data.productos || []).slice(0, 8).map(p => ({
                        id: p.id, codigo: p.codigo, descripcion: p.descripcion,
                        precio: p.precio, precio_compra: p.precio_min || p.precio_compra || 0, tasa_iva: p.tasa_iva
                    }));
                } catch (e) { this.prodSearchResults = []; }
                this.prodSearching = false;
            },
            selectProductoItem(idx, prod) {
                const item = this.form.items[idx];
                item.productoId = prod.id; item.productoCodigo = prod.codigo; item.productoBuscar = prod.descripcion;
                if (!item.descripcion) item.descripcion = prod.descripcion;
                if (prod.precio_compra > 0 && item.costo === 0) item.costo = prod.precio_compra;
                if (prod.tasa_iva !== undefined) item.iva = String(prod.tasa_iva);
                item.precioVenta = this.calculateVentaByRecarga(item);
                this.showProdDropdown = false; this.prodSearchResults = [];
            },
            abrirCrearProducto(idx) {
                this.showProdDropdown = false;
                const item = this.form.items[idx];
                this.nuevoProducto = { codigo: '', nombre: item.descripcion || item.productoBuscar || '', precioCompra: item.costo || 0, precioVenta: 0, iva: item.iva || '10' };
                this.prodSearchIdx = idx;
                this.showCrearProducto = true;
            },
            async guardarNuevoProducto() {
                if (!this.nuevoProducto.nombre.trim()) { alert('Ingresá el nombre'); return; }
                this.creandoProducto = true;
                try {
                    const res = await fetch('/public/compras/api/producto_crear.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ codigo: this.nuevoProducto.codigo, nombre: this.nuevoProducto.nombre, precio_compra: this.nuevoProducto.precioCompra, precio_venta: this.nuevoProducto.precioVenta, iva: this.nuevoProducto.iva })
                    });
                    const data = await res.json();
                    if (data.success) {
                        const item = this.form.items[this.prodSearchIdx];
                        item.productoId = data.producto.id; item.productoCodigo = data.producto.codigo; item.productoBuscar = data.producto.nombre;
                        if (!item.descripcion) item.descripcion = data.producto.nombre;
                        this.showCrearProducto = false;
                    } else alert(data.message || 'Error al crear');
                } catch (e) { alert('Error de conexión'); }
                this.creandoProducto = false;
            },
            
            // ===== WEBCAM =====
            async openWebcam(mode = 'standard') {
                this.webcamMode = mode === 'scanner' ? 'scanner' : 'standard';
                this.showWebcam = true; this.webcamCaptured = false; this.webcamLoading = true;
                await this.$nextTick();
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } } });
                    this.webcamStream = stream;
                    this.$refs.webcamVideo.srcObject = stream;
                    this.webcamLoading = false;
                } catch (e) { this.closeWebcam(); this.ocrError = 'No se pudo acceder a la cámara.'; }
            },
            closeWebcam() {
                if (this.webcamStream) { this.webcamStream.getTracks().forEach(t => t.stop()); this.webcamStream = null; }
                this.showWebcam = false; this.webcamCaptured = false; this.webcamLoading = false;
            },
            captureWebcam() {
                const v = this.$refs.webcamVideo, c = this.$refs.webcamCanvas;
                c.width = v.videoWidth; c.height = v.videoHeight;
                c.getContext('2d').drawImage(v, 0, 0);
                c.style.display = 'block'; this.webcamCaptured = true;
            },
            async useWebcamCapture() {
                const blob = await new Promise(r => this.$refs.webcamCanvas.toBlob(r, 'image/jpeg', 0.92));
                const file = new File([blob], 'webcam_capture.jpg', { type: 'image/jpeg' });
                this.ocrCaptureMode = this.webcamMode === 'scanner' ? 'scanner' : 'standard';
                this.closeWebcam(); this.processImageOcr(file);
            },
            
            // ===== OCR =====
            beginOcrRequest() {
                this.ocrCancelled = false;
                const controller = new AbortController();
                this.ocrAbortController = controller;
                return controller;
            },
            clearOcrRequest(controller) {
                if (this.ocrAbortController === controller) this.ocrAbortController = null;
            },
            handleOcrHttpError(res) {
                if (!res || res.ok) return false;
                if (res.status === 413) {
                    this.ocrError = 'El archivo es demasiado grande para OCR. Reducilo o subí una imagen comprimida.';
                    return true;
                }
                if (res.status === 504) {
                    this.ocrError = 'OCR demoró demasiado y agotó el tiempo de espera. Reintentá con una imagen más liviana.';
                    return true;
                }
                this.ocrError = `OCR devolvió HTTP ${res.status}`;
                return true;
            },
            isAiOcrMode() {
                return this.ocrMode === 'google_vision';
            },
            getOcrEngine() {
                return this.ocrMode === 'google_vision' ? 'google_vision' : '';
            },
            isScannerCaptureMode() {
                return this.ocrCaptureMode === 'scanner';
            },
            preprocessCanvasForOcr(canvas, ctx) {
                const width = canvas.width || 1;
                const height = canvas.height || 1;
                let imageData;
                try {
                    imageData = ctx.getImageData(0, 0, width, height);
                } catch (_) {
                    return;
                }
                const data = imageData.data;
                let minLum = 255;
                let maxLum = 0;
                const luminances = new Float32Array(width * height);

                for (let i = 0, p = 0; i < data.length; i += 4, p++) {
                    const lum = (0.299 * data[i]) + (0.587 * data[i + 1]) + (0.114 * data[i + 2]);
                    luminances[p] = lum;
                    if (lum < minLum) minLum = lum;
                    if (lum > maxLum) maxLum = lum;
                }

                const span = Math.max(1, maxLum - minLum);
                const threshold = this.isScannerCaptureMode() ? 170 : 180;

                for (let i = 0, p = 0; i < data.length; i += 4, p++) {
                    let lum = ((luminances[p] - minLum) * 255) / span;
                    lum = lum < threshold
                        ? Math.max(0, lum * (this.isScannerCaptureMode() ? 0.68 : 0.86))
                        : Math.min(255, 255 - ((255 - lum) * (this.isScannerCaptureMode() ? 0.28 : 0.55)));
                    if (this.isScannerCaptureMode()) {
                        lum = lum >= threshold ? 255 : Math.max(0, lum * 0.78);
                    }
                    data[i] = lum;
                    data[i + 1] = lum;
                    data[i + 2] = lum;
                    data[i + 3] = 255;
                }

                ctx.putImageData(imageData, 0, 0);
            },
            cropCanvasToDocument(canvas) {
                const width = canvas.width || 1;
                const height = canvas.height || 1;
                const ctx = canvas.getContext('2d', { alpha: false });
                if (!ctx) return canvas;
                let imageData;
                try {
                    imageData = ctx.getImageData(0, 0, width, height);
                } catch (_) {
                    return canvas;
                }
                const data = imageData.data;
                const rowThreshold = Math.max(8, Math.floor(width * 0.012));
                const colThreshold = Math.max(8, Math.floor(height * 0.012));
                const darkCutoff = this.isScannerCaptureMode() ? 235 : 212;
                const rowHits = new Uint16Array(height);
                const colHits = new Uint16Array(width);

                for (let y = 0; y < height; y++) {
                    for (let x = 0; x < width; x++) {
                        const idx = ((y * width) + x) * 4;
                        const lum = data[idx];
                        if (lum < darkCutoff) {
                            rowHits[y]++;
                            colHits[x]++;
                        }
                    }
                }

                let top = 0;
                while (top < height - 1 && rowHits[top] < rowThreshold) top++;
                let bottom = height - 1;
                while (bottom > top && rowHits[bottom] < rowThreshold) bottom--;
                let left = 0;
                while (left < width - 1 && colHits[left] < colThreshold) left++;
                let right = width - 1;
                while (right > left && colHits[right] < colThreshold) right--;

                const croppedWidth = right - left + 1;
                const croppedHeight = bottom - top + 1;
                if (croppedWidth < width * 0.4 || croppedHeight < height * 0.4) return canvas;

                const padX = Math.max(12, Math.round(croppedWidth * 0.03));
                const padY = Math.max(12, Math.round(croppedHeight * 0.03));
                left = Math.max(0, left - padX);
                top = Math.max(0, top - padY);
                right = Math.min(width - 1, right + padX);
                bottom = Math.min(height - 1, bottom + padY);

                const outWidth = right - left + 1;
                const outHeight = bottom - top + 1;
                const outCanvas = document.createElement('canvas');
                outCanvas.width = Math.max(1, outWidth);
                outCanvas.height = Math.max(1, outHeight);
                const outCtx = outCanvas.getContext('2d', { alpha: false });
                if (!outCtx) return canvas;
                outCtx.fillStyle = '#ffffff';
                outCtx.fillRect(0, 0, outWidth, outHeight);
                outCtx.drawImage(canvas, left, top, outWidth, outHeight, 0, 0, outWidth, outHeight);
                return outCanvas;
            },
            rotateCanvas(canvas, angleDeg) {
                const radians = (angleDeg * Math.PI) / 180;
                const width = canvas.width || 1;
                const height = canvas.height || 1;
                const sin = Math.abs(Math.sin(radians));
                const cos = Math.abs(Math.cos(radians));
                const outWidth = Math.max(1, Math.ceil((width * cos) + (height * sin)));
                const outHeight = Math.max(1, Math.ceil((width * sin) + (height * cos)));
                const outCanvas = document.createElement('canvas');
                outCanvas.width = outWidth;
                outCanvas.height = outHeight;
                const outCtx = outCanvas.getContext('2d', { alpha: false });
                if (!outCtx) return canvas;
                outCtx.fillStyle = '#ffffff';
                outCtx.fillRect(0, 0, outWidth, outHeight);
                outCtx.translate(outWidth / 2, outHeight / 2);
                outCtx.rotate(radians);
                outCtx.drawImage(canvas, -width / 2, -height / 2);
                return outCanvas;
            },
            scoreCanvasAlignment(canvas) {
                const ctx = canvas.getContext('2d', { alpha: false });
                if (!ctx) return 0;
                let imageData;
                try {
                    imageData = ctx.getImageData(0, 0, canvas.width || 1, canvas.height || 1);
                } catch (_) {
                    return 0;
                }
                const width = canvas.width || 1;
                const height = canvas.height || 1;
                const data = imageData.data;
                const rowHits = new Float32Array(height);
                let totalInk = 0;
                for (let y = 0; y < height; y++) {
                    let row = 0;
                    for (let x = 0; x < width; x++) {
                        const idx = ((y * width) + x) * 4;
                        const lum = data[idx];
                        if (lum < 208) row += (255 - lum) / 255;
                    }
                    rowHits[y] = row;
                    totalInk += row;
                }
                if (totalInk <= 0) return 0;
                let score = 0;
                for (let y = 0; y < height; y++) score += rowHits[y] * rowHits[y];
                return score / totalInk;
            },
            deskewCanvasForOcr(canvas) {
                const probeMax = 900;
                const scale = Math.min(1, probeMax / Math.max(canvas.width || 1, canvas.height || 1));
                const probeCanvas = document.createElement('canvas');
                probeCanvas.width = Math.max(1, Math.round((canvas.width || 1) * scale));
                probeCanvas.height = Math.max(1, Math.round((canvas.height || 1) * scale));
                const probeCtx = probeCanvas.getContext('2d', { alpha: false });
                if (!probeCtx) return canvas;
                probeCtx.fillStyle = '#ffffff';
                probeCtx.fillRect(0, 0, probeCanvas.width, probeCanvas.height);
                probeCtx.drawImage(canvas, 0, 0, probeCanvas.width, probeCanvas.height);

                const candidates = [-4, -3, -2, -1, 0, 1, 2, 3, 4];
                let bestAngle = 0;
                let bestScore = -Infinity;
                for (const angle of candidates) {
                    const rotated = angle === 0 ? probeCanvas : this.rotateCanvas(probeCanvas, angle);
                    const score = this.scoreCanvasAlignment(rotated);
                    if (score > bestScore) {
                        bestScore = score;
                        bestAngle = angle;
                    }
                }
                return Math.abs(bestAngle) < 0.5 ? canvas : this.rotateCanvas(canvas, bestAngle);
            },
            async optimizeImageForOcr(file) {
                const mime = String(file?.type || '').toLowerCase();
                if (!mime.startsWith('image/')) return file;
                const maxBytes = this.isAiOcrMode() ? 8 * 1024 * 1024 : 5 * 1024 * 1024;
                let bitmap;
                try {
                    bitmap = await createImageBitmap(file);
                } catch (_) {
                    return file;
                }
                const maxEdge = this.isAiOcrMode() ? 2400 : 1800;
                const ratio = Math.min(1, maxEdge / Math.max(bitmap.width || 1, bitmap.height || 1));
                const width = Math.max(1, Math.round((bitmap.width || 1) * ratio));
                const height = Math.max(1, Math.round((bitmap.height || 1) * ratio));
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d', { alpha: false });
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, width, height);
                ctx.drawImage(bitmap, 0, 0, width, height);
                if (bitmap.close) bitmap.close();
                this.preprocessCanvasForOcr(canvas, ctx);
                const croppedCanvas = this.cropCanvasToDocument(canvas);
                const deskewedCanvas = this.deskewCanvasForOcr(croppedCanvas);
                const deskewCtx = deskewedCanvas.getContext('2d', { alpha: false });
                if (deskewCtx) this.preprocessCanvasForOcr(deskewedCanvas, deskewCtx);
                const finalCanvas = this.cropCanvasToDocument(deskewedCanvas);
                const qualities = this.isScannerCaptureMode() ? [0.92, 0.86, 0.8] : (this.isAiOcrMode() ? [0.9, 0.82, 0.75, 0.68] : [0.82, 0.72, 0.62]);
                let outBlob = null;
                for (const q of qualities) {
                    outBlob = await new Promise(resolve => finalCanvas.toBlob(resolve, 'image/jpeg', q));
                    if (outBlob && outBlob.size <= maxBytes) break;
                }
                if (!outBlob) return file;
                return new File([outBlob], file.name.replace(/\.[^.]+$/, '.jpg'), { type: 'image/jpeg' });
            },
            cancelOcrProcess() {
                this.ocrCancelled = true;
                if (this.ocrAbortController) {
                    try { this.ocrAbortController.abort(); } catch (_) {}
                    this.ocrAbortController = null;
                }
                this.ocrProcessing = false;
                this.ocrStatusMsg = 'OCR cancelado';
                this.ocrError = 'OCR cancelado por el usuario.';
            },
            setupOcrPasteListener() {
                if (this.ocrPasteHandler) return;
                this.ocrPasteHandler = (event) => this.handleOcrPaste(event);
                window.addEventListener('paste', this.ocrPasteHandler);
            },
            extractImageFromClipboardItems(items) {
                if (!items) return null;
                for (const item of items) {
                    if (item && item.kind === 'file' && item.type && item.type.startsWith('image/')) {
                        const blob = item.getAsFile();
                        if (!blob) continue;
                        const ext = (blob.type.split('/')[1] || 'png').split('+')[0];
                        return new File([blob], `clipboard_${Date.now()}.${ext}`, { type: blob.type || 'image/png' });
                    }
                }
                return null;
            },
            handleOcrPaste(event) {
                if (!this.showFormCompra || this.ocrProcessing) return;
                const file = this.extractImageFromClipboardItems(event.clipboardData?.items);
                if (!file) return;
                event.preventDefault();
                this.ocrCaptureMode = 'standard';
                this.processOcrFile(file);
            },
            async pasteFromClipboard() {
                this.ocrError = '';
                try {
                    if (!navigator.clipboard || !navigator.clipboard.read) {
                        this.ocrError = 'Tu navegador no permite pegar por botón. Usá Ctrl+V.';
                        return;
                    }
                    const clipboardItems = await navigator.clipboard.read();
                    for (const item of clipboardItems) {
                        const imageType = (item.types || []).find(t => t.startsWith('image/'));
                        if (!imageType) continue;
                        const blob = await item.getType(imageType);
                        const ext = (imageType.split('/')[1] || 'png').split('+')[0];
                        const file = new File([blob], `clipboard_${Date.now()}.${ext}`, { type: imageType });
                        this.ocrCaptureMode = 'standard';
                        await this.processOcrFile(file);
                        return;
                    }
                    this.ocrError = 'El portapapeles no contiene una imagen.';
                } catch (error) {
                    this.ocrError = 'No se pudo leer el portapapeles. Permití acceso e intentá de nuevo.';
                }
            },
            handleOcrFile(event) {
                const file = event.target.files[0];
                this.ocrCaptureMode = 'standard';
                if (file) this.processOcrFile(file);
                event.target.value = '';
            },
            handleScannerFile(event) {
                const file = event.target.files[0];
                if (!file) return;
                this.ocrCaptureMode = 'scanner';
                event.target.value = '';
                this.processOcrFile(file);
            },
            async processOcrFile(file) {
                this.ocrError = ''; this.ocrSuccess = false; this.ocrFileName = file.name;
                this.ocrCancelled = false;
                const ext = file.name.split('.').pop().toLowerCase();
                if (['xlsx','xls','csv'].includes(ext)) await this.processExcel(file);
                else if (ext === 'pdf') await this.processPdfOcr(file);
                else if (['jpg','jpeg','png','webp','bmp','gif'].includes(ext)) await this.processImageOcr(file);
                else this.ocrError = 'Formato no soportado. Usa imagen, PDF o Excel.';
            },
            async processPdfOcr(file) {
                this.ocrProcessing = true; this.ocrProgress = 15; this.ocrStatusMsg = 'Leyendo texto del PDF...';
                try {
                    // 1) Intento directo sobre PDF (más preciso para factura electrónica).
                    const pdfDirectMaxBytes = 18 * 1024 * 1024;
                    const canTryDirect = (file.size || 0) <= pdfDirectMaxBytes;
                    const directOk = (canTryDirect && this.ocrMode !== 'google_vision') ? await this.processPdfDirectOcr(file) : false;
                    if (this.ocrCancelled) {
                        this.ocrProcessing = false;
                        return;
                    }
                    if (directOk) {
                        this.ocrProcessing = false;
                        return;
                    }

                    // 2) Fallback: convertir primera página a imagen.
                    this.ocrProgress = 20; this.ocrStatusMsg = 'Convirtiendo PDF a imagen...';
                    if (!window.pdfjsLib || !window.pdfjsLib.getDocument) throw new Error('pdf.js no disponible');
                    if (!window.pdfjsLib.GlobalWorkerOptions.workerSrc) {
                        window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                    }
                    const pdfData = await file.arrayBuffer();
                    if (this.ocrCancelled) { this.ocrProcessing = false; return; }
                    const pdf = await window.pdfjsLib.getDocument({ data: pdfData }).promise;
                    const page = await pdf.getPage(1);
                    const baseViewport = page.getViewport({ scale: 1.0 });
                    const maxEdge = 1600;
                    const maxSide = Math.max(baseViewport.width, baseViewport.height) || 1;
                    const scale = Math.min(2.0, Math.max(1.1, maxEdge / maxSide));
                    const viewport = page.getViewport({ scale });
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.max(1, Math.floor(viewport.width));
                    canvas.height = Math.max(1, Math.floor(viewport.height));
                    const ctx = canvas.getContext('2d', { alpha: false });
                    await page.render({ canvasContext: ctx, viewport }).promise;
                    if (this.ocrCancelled) { this.ocrProcessing = false; return; }
                    const blob = await new Promise((resolve, reject) => {
                        canvas.toBlob(b => b ? resolve(b) : reject(new Error('No se pudo generar imagen del PDF')), 'image/jpeg', 0.82);
                    });
                    const imageFile = new File([blob], file.name.replace(/\.pdf$/i, '.jpg'), { type: 'image/jpeg' });
                    this.ocrProcessing = false;
                    await this.processImageOcr(imageFile, file);
                } catch (e) {
                    if (e && e.name === 'AbortError') {
                        this.ocrError = 'OCR cancelado por el usuario.';
                        this.ocrProcessing = false;
                        return;
                    }
                    this.ocrError = 'No se pudo leer el PDF. Probá con una foto de la primera página.';
                    this.ocrProcessing = false;
                }
            },
            async processPdfDirectOcr(file) {
                try {
                    const fd = new FormData();
                    fd.append('pdf', file);
                    fd.append('id_empresa', this.idEmpresa);
                    fd.append('ai_fallback', this.isAiOcrMode() ? '1' : '0');
                    fd.append('ocr_precision', this.isAiOcrMode() ? 'max' : 'fast');
                    if (this.getOcrEngine()) fd.append('engine', this.getOcrEngine());
                    const controller = this.beginOcrRequest();
                    const res = await fetch('/modelos/ocr_factura_api.php', { method: 'POST', body: fd, signal: controller.signal });
                    this.clearOcrRequest(controller);
                    if (!res.ok) {
                        if (res.status === 413) this.ocrError = 'PDF muy pesado para OCR directo. Intentando modo imagen...';
                        return false;
                    }
                    const data = await res.json();
                    if (data.success && data.data && Array.isArray(data.data.items) && data.data.items.length > 0) {
                        const bad = data.data.items.filter(it => {
                            const d = String(it?.descripcion || '').trim().toLowerCase();
                            return /^(tel[eé]fono|ciudad|cdc|cliente|ruc|correo|p[aá]g\.)/.test(d);
                        }).length;
                        if (bad > 0 && bad >= Math.ceil(data.data.items.length * 0.35)) {
                            return false;
                        }
                        this.applyOcrData(data.data);
                        this.ocrProgress = 100;
                        this.ocrSuccess = true;
                        return true;
                    }
                } catch (e) {
                    if (e && e.name === 'AbortError') {
                        this.ocrCancelled = true;
                        return false;
                    }
                }
                return false;
            },
            async processImageOcr(file, sourceFile = null) {
                this.ocrProcessing = true; this.ocrProgress = 10; this.ocrStatusMsg = 'Subiendo imagen...';
                try {
                    const optimizedFile = await this.optimizeImageForOcr(file);
                    const fd = new FormData();
                    fd.append('imagen', optimizedFile); fd.append('id_empresa', this.idEmpresa);
                    fd.append('ocr_source_name', sourceFile ? sourceFile.name : file.name);
                    fd.append('ocr_source_size', String(sourceFile ? sourceFile.size : file.size));
                    fd.append('ocr_upload_name', optimizedFile.name);
                    fd.append('ocr_upload_size', String(optimizedFile.size));
                    fd.append('ai_fallback', this.isAiOcrMode() ? '1' : '0');
                    fd.append('ocr_precision', this.isAiOcrMode() ? 'max' : 'fast');
                    if (this.getOcrEngine()) fd.append('engine', this.getOcrEngine());
                    this.ocrProgress = 30;
                    this.ocrStatusMsg = this.ocrMode === 'google_vision' ? 'Google Vision OCR analizando factura...' : 'OCR analizando factura...';
                    const controller = this.beginOcrRequest();
                    const res = await fetch('/modelos/ocr_factura_api.php', { method: 'POST', body: fd, signal: controller.signal });
                    this.clearOcrRequest(controller);
                    if (this.handleOcrHttpError(res)) { this.ocrProcessing = false; return; }
                    this.ocrProgress = 80; this.ocrStatusMsg = 'Extrayendo datos...';
                    const data = await res.json();
                    if (data.success && data.data) { this.applyOcrData(data.data); this.ocrProgress = 100; this.ocrSuccess = true; }
                    else this.ocrError = data.message || 'No se pudo procesar';
                } catch (e) {
                    if (e && e.name === 'AbortError') { this.ocrError = 'OCR cancelado por el usuario.'; this.ocrProcessing = false; return; }
                    this.ocrError = 'Error de conexión';
                }
                this.ocrProcessing = false;
            },
            async processExcel(file) {
                this.ocrProcessing = true; this.ocrProgress = 20; this.ocrStatusMsg = 'Leyendo Excel...';
                try {
                    const ab = await file.arrayBuffer();
                    const wb = XLSX.read(ab, { type: 'array', cellDates: true });
                    const rows = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { header: 1 });
                    this.ocrProgress = 60; this.ocrStatusMsg = 'Interpretando...';
                    if (rows.length < 2) { this.ocrError = 'Archivo vacío'; this.ocrProcessing = false; return; }
                    const headers = rows[0].map(h => String(h||'').toLowerCase().trim());
                    const colMap = this.detectExcelColumns(headers);
                    const items = [];
                    for (let i = 1; i < rows.length; i++) {
                        const r = rows[i]; if (!r || !r.length) continue;
                        const desc = colMap.descripcion!==-1 ? String(r[colMap.descripcion]||'') : '';
                        const codigoProveedor = colMap.codigo!==-1 ? String(r[colMap.codigo] ?? '').trim() : '';
                        const cant = colMap.cantidad!==-1 ? parseFloat(r[colMap.cantidad])||0 : 1;
                        const costo = colMap.costo!==-1 ? parseFloat(r[colMap.costo])||0 : 0;
                        let iva = '10';
                        if (colMap.iva!==-1) { const v = parseFloat(r[colMap.iva])||10; iva = v<=0?'0':v<=5?'5':'10'; }
                        if (desc && (cant>0||costo>0)) items.push({ descripcion: desc.substring(0,200), codigoProveedor, cantidad: cant||1, costo: parseFloat((costo || 0).toFixed(2)), precioVenta: 0, iva });
                    }
                    if (items.length === 0) { this.ocrError = 'No se encontraron productos'; this.ocrProcessing = false; return; }
                    this.applyOcrData({ items, total: items.reduce((s,i)=>s+(i.cantidad*i.costo),0) });
                    this.ocrProgress = 100; this.ocrSuccess = true;
                } catch (e) { this.ocrError = 'Error al leer Excel'; }
                this.ocrProcessing = false;
            },
            detectExcelColumns(headers) {
                const map = { descripcion:-1, codigo:-1, cantidad:-1, costo:-1, iva:-1 };
                const pat = { descripcion:/descrip|producto|detalle|item|nombre/, codigo:/codigo|cod\.?|c[oó]digo|barcode|barra|sku|referencia|ref\.?/, cantidad:/cant|qty|unid/, costo:/costo|precio|monto|importe|valor|price/, iva:/iva|tax|impuesto/ };
                headers.forEach((h,i) => { for (const [k,rx] of Object.entries(pat)) if (map[k]===-1 && rx.test(h)) map[k]=i; });
                if (map.descripcion===-1 && headers.length>=1) map.descripcion=0;
                if (map.cantidad===-1 && headers.length>=2) map.cantidad=1;
                if (map.costo===-1 && headers.length>=3) map.costo=2;
                return map;
            },
            applyOcrData(data) {
                if (data.nro_factura) this.form.nroFactura = data.nro_factura;
                if (data.timbrado) this.form.timbrado = data.timbrado;
                if (data.fecha) this.form.fecha = data.fecha;
                if (data.proveedor) {
                    this.form.proveedorBuscar = data.proveedor;
                    this.form.proveedorNombre = data.proveedor;
                    this.form.proveedorRuc = data.ruc || '';
                    if (data.ruc) {
                        this.form.proveedorBuscar = data.ruc;
                        this.buscarProveedor().then(() => {
                            const match = this.proveedoresResults.find(p => p.numero && p.numero.replace(/-/g,'').includes(data.ruc.replace(/-/g,'')));
                            if (match) this.selectProveedor(match);
                            else this.form.proveedorBuscar = data.proveedor;
                        });
                    }
                }
                if (data.items && data.items.length > 0) {
                    this.form.items = data.items.map(item => {
                        let iva = '10';
                        if (item.tipo_iva===1||item.tipo_iva===0) iva='0';
                        else if (item.tipo_iva===2||item.tipo_iva===5) iva='5';
                        else if (item.iva!==undefined) iva=String(item.iva);
                        const codigoProveedor = String(
                            item.codigo_proveedor ||
                            item.codigoProveedor ||
                            item.codigo_producto_proveedor ||
                            item.codigo_producto ||
                            item.codigo ||
                            item.sku ||
                            ''
                        ).trim();
                        return {
                            descripcion: item.descripcion||'',
                            codigoProveedor,
                            cantidad: parseFloat(item.cantidad)||1,
                            costo: parseFloat((parseFloat(item.costo||item.costo_gs)||0).toFixed(2)),
                            precioVenta: Math.round(parseFloat(item.venta_ocr || item.precio_venta || item.precioVenta) || 0),
                            iva,
                            productoId:null,
                            productoCodigo:'',
                            productoBuscar:''
                        };
                    });
                    this.recalculatePricingAll();
                }
            },
            
            prevPage() { if (this.currentPage > 1) { this.currentPage--; this.loadCompras(); } },
            nextPage() { if (this.currentPage < this.totalPages) { this.currentPage++; this.loadCompras(); } },
            goToPage(page) {
                const targetPage = Number(page || 1);
                if (!Number.isFinite(targetPage) || targetPage < 1 || targetPage > this.totalPages || targetPage === this.currentPage) return;
                this.currentPage = targetPage;
                this.loadCompras();
            },
            getVisiblePageDots() {
                const total = Math.max(1, Number(this.totalPages || 1));
                const current = Math.max(1, Number(this.currentPage || 1));
                if (total <= 7) {
                    return Array.from({ length: total }, (_, index) => index + 1);
                }
                let start = Math.max(1, current - 3);
                let end = Math.min(total, start + 6);
                start = Math.max(1, end - 6);
                return Array.from({ length: (end - start) + 1 }, (_, index) => start + index);
            },
            toggleTheme() {
                this.isDark = true;
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
                this.syncTabletGridTheme();
            },
            escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            },
            formatMoney(a) { return new Intl.NumberFormat('es-PY', { maximumFractionDigits: 0 }).format(a || 0); },
            formatDate(d) { if (!d) return '-'; return new Date(d).toLocaleDateString('es-PY'); }
        }
    }
    </script>
</body>
</html>
