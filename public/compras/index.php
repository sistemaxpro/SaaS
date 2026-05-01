<?php
/**
 * Listado de Compras - Desktop
 * Vista principal con filtros, búsqueda y acciones
 */

require_once __DIR__ . '/../../config/bootstrap.php';

// Redirigir a versión mobile si es dispositivo móvil
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = preg_match('/Mobile|Android|iPhone|iPod|iPad|webOS|BlackBerry|Opera Mini|IEMobile/i', $userAgent);
if ($isMobile) {
    header('Location: /public/compras/mobile.php');
    exit;
}

Session::requireLogin('/public/login.php');

Permission::requireAccess('app_grid_factura_compras');
$permisos = Permission::getAppPermissions('app_grid_factura_compras');

$id_empresa = Session::get('id_empresa', 169);
$id_login = Session::get('id_login');

// Obtener datos de la empresa desde master
$masterPdo = Database::getMasterConnection();
$stmtEmpresa = $masterPdo->prepare("SELECT * FROM empresa WHERE id_empresa = ?");
$stmtEmpresa->execute([$id_empresa]);
$empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

$pageTitle = t('compras.title');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(SmxI18n::getLocale()) ?>" x-data="comprasApp()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - SistemaX</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
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
        .table-row:hover { background: rgba(234, 88, 12, 0.05); }
        .dark .table-row:hover { background: rgba(234, 88, 12, 0.1); }
        .ocr-dropzone { transition: all 0.2s; }
        .ocr-dropzone.drag-over { border-color: #ea580c !important; background: rgba(234, 88, 12, 0.08) !important; transform: scale(1.01); }
        .ocr-pulse { animation: ocrPulse 1.5s ease-in-out infinite; }
        @keyframes ocrPulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .ocr-progress { background: linear-gradient(90deg, #ea580c 0%, #f97316 50%, #ea580c 100%); background-size: 200% 100%; animation: shimmer 1.5s infinite; }
        @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        .ag-theme-quartz,
        .ag-theme-quartz-dark {
            --ag-font-family: Inter, sans-serif;
            --ag-border-color: rgba(148, 163, 184, 0.18);
            --ag-row-border-color: rgba(148, 163, 184, 0.14);
            --ag-header-background-color: rgba(249, 250, 251, 0.96);
            --ag-odd-row-background-color: transparent;
            --ag-background-color: transparent;
            --ag-wrapper-border-radius: 0;
            --ag-row-hover-color: rgba(234, 88, 12, 0.08);
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
        .compras-grid {
            width: 100%;
            height: 620px;
        }
        .compra-cell-stack { line-height: 1.25; }
        .compra-cell-stack .main { font-weight: 600; color: rgb(15 23 42); }
        .dark .compra-cell-stack .main { color: rgb(248 250 252); }
        .compra-cell-stack .meta { font-size: 11px; color: rgb(100 116 139); }
        .dark .compra-cell-stack .meta { color: rgb(148 163 184); }
        .compra-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            padding: 2px 10px;
            font-size: 11px;
            font-weight: 600;
        }
        .compra-badge.ok { background: rgba(34, 197, 94, 0.14); color: rgb(21, 128, 61); }
        .compra-badge.off { background: rgba(239, 68, 68, 0.14); color: rgb(185, 28, 28); }
        .dark .compra-badge.ok { color: rgb(74, 222, 128); }
        .dark .compra-badge.off { color: rgb(248, 113, 113); }
        .grid-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .grid-actions button {
            width: 30px;
            height: 30px;
            border-radius: 10px;
            border: 0;
            cursor: pointer;
            transition: transform 0.12s ease, background-color 0.12s ease;
        }
        .grid-actions button:hover { transform: translateY(-1px); }
        .grid-actions .view { background: rgba(249, 115, 22, 0.12); color: rgb(234, 88, 12); }
        .grid-actions .print { background: rgba(148, 163, 184, 0.14); color: rgb(71, 85, 105); }
        .grid-actions .edit { background: rgba(59, 130, 246, 0.12); color: rgb(37, 99, 235); }
        .grid-actions .copy { background: rgba(139, 92, 246, 0.12); color: rgb(109, 40, 217); }
        .grid-actions .void { background: rgba(239, 68, 68, 0.12); color: rgb(220, 38, 38); }
        .dark .grid-actions .print { color: rgb(203, 213, 225); }
        .checkout-tile {
            position: relative;
            border-radius: 14px;
            border: 1px solid rgba(71, 85, 105, .8);
            padding: 14px 10px;
            text-align: center;
            font-weight: 800;
            transition: all .14s ease;
        }
        .checkout-tile:hover { transform: translateY(-1px); }
        .checkout-panel {
            border: 1px solid rgba(51, 65, 85, 0.95);
            box-shadow: 0 22px 60px rgba(2, 6, 23, 0.58);
        }
        .checkout-section {
            border: 1px solid rgba(51, 65, 85, 0.78);
            background: rgba(15, 23, 42, 0.72);
            border-radius: 18px;
            padding: 16px;
        }
        .checkout-footer {
            border-top: 1px solid rgba(51, 65, 85, 0.9);
            background: rgba(2, 6, 23, 0.86);
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-900 min-h-screen font-sans">
<script>window.__PERMISOS__ = <?= json_encode($permisos) ?>;</script>
    
    <!-- Header -->
    <header class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo y Título -->
                <div class="flex items-center gap-4">
                    <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';" 
                            class="w-10 h-10 rounded-lg bg-red-600/10 border border-red-500/40 flex items-center justify-center text-red-600 dark:text-red-400 hover:bg-red-600/20 active:scale-95 transition-all cursor-pointer"
                            title="<?= htmlspecialchars(t('common.logout')) ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15m-3 0-3-3m0 0 3-3m-3 3H15" />
                        </svg>
                    </button>
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 dark:text-white"><?= htmlspecialchars($pageTitle) ?></h1>
                        <p class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($empresa['empresa'] ?? t('common.company')) ?></p>
                    </div>
                </div>
                
                <!-- Acciones Header -->
                <div class="flex items-center gap-3">
                    <button x-show="permisos.priv_insert === 'Y'" @click="openCompraForm('producto')" 
                            class="inline-flex items-center gap-2 px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg font-medium transition-colors active:scale-95">
                        <i class="fas fa-plus"></i>
                        <span class="hidden sm:inline"><?= htmlspecialchars(t('compras.new_purchase')) ?></span>
                    </button>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Filtros -->
    <div class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex flex-wrap items-center gap-4">
                <!-- Búsqueda -->
                <div class="flex-1 min-w-[200px]">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" 
                               x-model="searchQuery"
                               @input.debounce.300ms="loadCompras()"
                               placeholder="<?= htmlspecialchars(t('compras.search_placeholder')) ?>"
                               class="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                    </div>
                </div>
                
                <!-- Filtros de Período -->
                <div class="flex items-center gap-1 bg-gray-100 dark:bg-slate-700 rounded-lg p-1">
                    <template x-for="p in periodos" :key="p.key">
                        <button @click="setPeriodo(p.key)" 
                                :class="periodoActivo === p.key 
                                    ? 'bg-white dark:bg-slate-600 text-orange-600 dark:text-orange-400 shadow-sm' 
                                    : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                                class="px-3 py-1.5 rounded-md text-sm font-medium transition-all whitespace-nowrap"
                                x-text="p.label">
                        </button>
                    </template>
                </div>

                <!-- Fechas personalizadas -->
                <template x-if="periodoActivo === 'custom'">
                    <div class="flex items-center gap-2">
                        <input type="date" 
                               x-model="fechaDesde"
                               @change="loadCompras()"
                               class="w-36 px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-orange-500">
                        <span class="text-gray-400 text-sm"><?= htmlspecialchars(t('common.range_to')) ?></span>
                        <input type="date" 
                               x-model="fechaHasta"
                               @change="loadCompras()"
                               class="w-36 px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-orange-500">
                    </div>
                </template>
                
                <!-- Estado -->
                <div class="w-40">
                    <select x-model="estadoFiltro" 
                            @change="loadCompras()"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500">
                        <option value=""><?= htmlspecialchars(t('common.all')) ?></option>
                        <option value="activo"><?= htmlspecialchars(t('common.active_plural')) ?></option>
                        <option value="anulado"><?= htmlspecialchars(t('common.cancelled_plural')) ?></option>
                    </select>
                </div>
                
                <!-- Botón Limpiar -->
                <button @click="clearFilters()" 
                        class="px-3 py-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <!-- Total Compras -->
            <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-lg bg-orange-100 dark:bg-orange-900/30 flex items-center justify-center">
                        <i class="fas fa-file-invoice text-orange-600 dark:text-orange-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars(t('compras.total_purchases')) ?></p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white" x-text="stats.totalCompras"></p>
                    </div>
                </div>
            </div>
            
            <!-- Monto Total -->
            <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-blue-600 dark:text-blue-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars(t('common.total_amount')) ?></p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white" x-text="formatMoney(stats.montoTotal) + ' Gs'"></p>
                    </div>
                </div>
            </div>
            
            <!-- Compras Hoy -->
            <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                        <i class="fas fa-calendar-day text-purple-600 dark:text-purple-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars(t('common.today')) ?></p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white" x-text="stats.comprasHoy"></p>
                    </div>
                </div>
            </div>
            
            <!-- Pendiente de Pago -->
            <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                        <i class="fas fa-exclamation-circle text-red-600 dark:text-red-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Pendiente</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white" x-text="formatMoney(stats.totalPendiente) + ' Gs'"></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabla de Compras -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
            <!-- Loading -->
            <div x-show="loading" class="p-8 text-center">
                <i class="fas fa-spinner fa-spin text-3xl text-orange-500 mb-3"></i>
                <p class="text-gray-500 dark:text-gray-400">Cargando compras...</p>
            </div>
            
            <div x-show="!loading" x-cloak class="p-3">
                <div x-ref="gridCompras"
                     :class="{'ag-theme-quartz': !isDark, 'ag-theme-quartz-dark': isDark}"
                     class="compras-grid"></div>
                <div class="mt-3 flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
                    <span>Registros visibles: <span x-text="gridVisibleRows"></span></span>
                    <span>Total encontrado: <span x-text="totalRecords"></span></span>
                </div>
                <div x-show="!loading && totalRecords === 0" class="p-12 text-center">
                    <i class="fas fa-truck text-5xl text-gray-300 dark:text-slate-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400 text-lg">No se encontraron compras</p>
                    <p class="text-gray-400 dark:text-gray-500 text-sm mt-1">Intenta con otros filtros</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Detalle Compra -->
    <div x-show="showModal" 
         x-cloak
         class="fixed inset-0 z-50 overflow-y-auto"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
            <div class="fixed inset-0 bg-black/60" @click="showModal = false"></div>
            
            <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-xl max-w-2xl w-full mx-4 overflow-hidden"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-4"
                 x-transition:enter-end="opacity-100 translate-y-0">
                
                <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Detalle de Compra</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400" x-text="selectedCompra?.nro_factura"></p>
                    </div>
                    <button @click="showModal = false" class="w-10 h-10 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center justify-center text-gray-500">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div class="px-6 py-4 max-h-[60vh] overflow-y-auto">
                    <template x-if="selectedCompra">
                        <div class="space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Proveedor</p>
                                    <p class="font-medium text-gray-900 dark:text-white" x-text="selectedCompra.proveedor_nombre || 'Sin proveedor'"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">RUC</p>
                                    <p class="font-medium text-gray-900 dark:text-white" x-text="selectedCompra.proveedor_ruc || '-'"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Fecha</p>
                                    <p class="font-medium text-gray-900 dark:text-white" x-text="formatDate(selectedCompra.fecha)"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Timbrado</p>
                                    <p class="font-medium text-gray-900 dark:text-white" x-text="selectedCompra.timbrado || '-'"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Vencimiento</p>
                                    <p class="font-medium text-gray-900 dark:text-white" x-text="selectedCompra.vencimiento ? formatDate(selectedCompra.vencimiento) : '-'"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Forma de Pago</p>
                                    <p class="font-medium text-gray-900 dark:text-white" x-text="getFormaPago(selectedCompra.forma_pago)"></p>
                                </div>
                            </div>
                            
                            <!-- Items -->
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Productos</p>
                                <div class="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-50 dark:bg-slate-700/50">
                                            <tr>
                                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Producto</th>
                                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Cod. Proveedor</th>
                                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Cant</th>
                                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Costo</th>
                                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                                            <template x-for="item in compraItems" :key="item.id">
                                                <tr>
                                                    <td class="px-3 py-2 text-gray-900 dark:text-white" x-text="item.descripcion || item.producto_nombre"></td>
                                                    <td class="px-3 py-2 text-gray-600 dark:text-gray-300" x-text="item.codigo || '-'"></td>
                                                    <td class="px-3 py-2 text-right text-gray-600 dark:text-gray-300" x-text="item.cantidad"></td>
                                                    <td class="px-3 py-2 text-right text-gray-600 dark:text-gray-300" x-text="formatMoney(item.costo_gs)"></td>
                                                    <td class="px-3 py-2 text-right font-medium text-gray-900 dark:text-white" x-text="formatMoney(item.importe_gs)"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Totales -->
                            <div class="flex justify-end">
                                <div class="bg-gray-50 dark:bg-slate-700/50 rounded-lg px-6 py-3 space-y-1">
                                    <div class="flex justify-between gap-8">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">Total</span>
                                        <span class="text-lg font-bold text-gray-900 dark:text-white" x-text="formatMoney(selectedCompra.total) + ' Gs'"></span>
                                    </div>
                                    <div class="flex justify-between gap-8">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">Pagado</span>
                                        <span class="text-sm font-medium text-green-600 dark:text-green-400" x-text="formatMoney(selectedCompra.pagado) + ' Gs'"></span>
                                    </div>
                                    <div class="flex justify-between gap-8">
                                        <span class="text-sm text-gray-500 dark:text-gray-400">Pendiente</span>
                                        <span class="text-sm font-medium" 
                                              :class="parseFloat(selectedCompra.pendiente) > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'"
                                              x-text="formatMoney(selectedCompra.pendiente) + ' Gs'"></span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Nota -->
                            <template x-if="selectedCompra.nota">
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Nota</p>
                                    <p class="text-sm text-gray-700 dark:text-gray-300 mt-1" x-text="selectedCompra.nota"></p>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
                
                <div class="px-6 py-4 bg-gray-50 dark:bg-slate-700/50 border-t border-gray-200 dark:border-slate-700 flex justify-end gap-3">
                    <button @click="imprimirCompra(selectedCompra)" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-gray-700 dark:text-gray-300 rounded-lg font-medium transition-colors">
                        <i class="fas fa-print mr-2"></i> Imprimir
                    </button>
                    <button @click="showModal = false" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg font-medium transition-colors">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Nueva Compra -->
    <div x-show="showFormCompra" 
         x-cloak
         class="fixed inset-0 z-50 overflow-y-auto"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        
        <div class="flex items-start justify-center min-h-screen p-3">
            <div class="fixed inset-0 bg-black/60" @click="showFormCompra = false"></div>
            
            <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-[95%] max-w-7xl overflow-hidden mt-2"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-4"
                 x-transition:enter-end="opacity-100 translate-y-0">
                
                <!-- Header -->
                <div class="px-5 py-2.5 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between bg-orange-50 dark:bg-orange-900/20">
                    <div class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-lg bg-orange-100 dark:bg-orange-900/40 flex items-center justify-center">
                            <i class="fas text-orange-600 dark:text-orange-400 text-sm" :class="isGastoMode ? 'fa-receipt' : 'fa-truck'"></i>
                        </div>
                        <h3 class="text-base font-bold text-gray-900 dark:text-white" x-text="isEditingCompra ? (isGastoMode ? 'Editar Compra Gastos' : 'Editar Compra') : (isGastoMode ? 'Nueva Compra Gastos' : 'Nueva Compra')"></h3>
                        <span class="text-xs text-gray-400 dark:text-gray-500 hidden sm:inline" x-text="isEditingCompra ? '— Actualizar compra existente' : (isGastoMode ? '— Registrar gasto con comprobante de compra' : '— Registrar factura de compra')"></span>
                    </div>
                    <button @click="showFormCompra = false" class="w-8 h-8 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center justify-center text-gray-500">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>
                
                <!-- Form -->
                <div class="px-4 py-3 max-h-[82vh] overflow-y-auto space-y-3">
                    <!-- OCR Upload Zone (compacta) -->
                    <div class="ocr-dropzone border-2 border-dashed border-gray-300 dark:border-slate-600 rounded-xl px-3 py-2.5 transition-all"
                         :class="ocrDragOver ? 'drag-over' : ''"
                         @dragover.prevent="ocrDragOver = true"
                         @dragleave.prevent="ocrDragOver = false"
                         @drop.prevent="ocrDragOver = false; handleOcrDrop($event)">
                        
                        <!-- Estado idle -->
                        <div x-show="!ocrProcessing && !ocrSuccess">
                            <div class="flex items-center gap-3 flex-wrap">
                                <div class="flex items-center gap-2 text-gray-400 dark:text-gray-500 shrink-0">
                                    <i class="fas fa-magic text-xl text-orange-400"></i>
                                    <div class="text-left">
                                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300 leading-tight">Cargar con OCR</p>
                                        <p class="text-[11px] text-gray-400 leading-tight">Arrastrá/pegá imagen, PDF o Excel</p>
                                    </div>
                                </div>
                                <div class="inline-flex items-center gap-2 rounded-lg border border-sky-200 dark:border-sky-800 bg-sky-50 dark:bg-sky-950/40 px-3 py-1.5">
                                    <i class="fas fa-cloud text-sky-600 dark:text-sky-400"></i>
                                    <span class="text-[11px] font-semibold text-sky-700 dark:text-sky-300">Google Vision OCR</span>
                                </div>
                                <p class="text-[11px] text-gray-500 dark:text-gray-400"
                                   x-text="getOcrModeDescription()"></p>
                                <div class="flex gap-1.5 flex-wrap">
                                    <button @click="pasteFromClipboard()" class="inline-flex items-center gap-1 px-2.5 py-1 bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-md text-xs font-medium hover:bg-gray-200 dark:hover:bg-slate-600 transition-colors">
                                        <i class="fas fa-paste"></i> Pegar
                                    </button>
                                    <button @click="openWebcam()" class="inline-flex items-center gap-1 px-2.5 py-1 bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 rounded-md text-xs font-medium hover:bg-purple-200 dark:hover:bg-purple-900/50 transition-colors">
                                        <i class="fas fa-video"></i> Webcam
                                    </button>
                                    <label class="cursor-pointer inline-flex items-center gap-1 px-2.5 py-1 bg-teal-100 dark:bg-teal-900/30 text-teal-700 dark:text-teal-400 rounded-md text-xs font-medium hover:bg-teal-200 dark:hover:bg-teal-900/50 transition-colors">
                                        <i class="fas fa-print"></i> Escáner
                                        <input type="file" accept="image/*,.pdf" @change="handleScannerFile($event)" class="hidden">
                                    </label>
                                    <label class="cursor-pointer inline-flex items-center gap-1 px-2.5 py-1 bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400 rounded-md text-xs font-medium hover:bg-orange-200 dark:hover:bg-orange-900/50 transition-colors">
                                        <i class="fas fa-camera"></i> Foto
                                        <input type="file" accept="image/*" capture="environment" @change="handleOcrFile($event)" class="hidden">
                                    </label>
                                    <label class="cursor-pointer inline-flex items-center gap-1 px-2.5 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 rounded-md text-xs font-medium hover:bg-blue-200 dark:hover:bg-blue-900/50 transition-colors">
                                        <i class="fas fa-file-pdf"></i> PDF
                                        <input type="file" accept=".pdf,image/*" @change="handleOcrFile($event)" class="hidden">
                                    </label>
                                    <label class="cursor-pointer inline-flex items-center gap-1 px-2.5 py-1 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-md text-xs font-medium hover:bg-green-200 dark:hover:bg-green-900/50 transition-colors">
                                        <i class="fas fa-file-excel"></i> Excel
                                        <input type="file" accept=".xlsx,.xls,.csv" @change="handleOcrFile($event)" class="hidden">
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Estado procesando -->
                        <div x-show="ocrProcessing" class="py-1">
                            <div class="flex items-center justify-center gap-3">
                                <div class="w-5 h-5 border-2 border-orange-500 border-t-transparent rounded-full animate-spin"></div>
                                <div class="text-left">
                                    <p class="text-sm font-medium text-orange-600 dark:text-orange-400 ocr-pulse" x-text="ocrStatusMsg"></p>
                                    <div class="w-40 h-1 bg-gray-200 dark:bg-slate-700 rounded-full mt-1 overflow-hidden">
                                        <div class="h-full ocr-progress rounded-full" :style="'width:' + ocrProgress + '%'"></div>
                                    </div>
                                </div>
                                <button type="button"
                                        @click="cancelOcrProcess()"
                                        class="px-2.5 py-1 rounded-md text-xs font-semibold bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-900/50 transition-colors">
                                    Cancelar OCR
                                </button>
                            </div>
                        </div>
                        
                        <!-- Estado éxito -->
                        <div x-show="ocrSuccess" class="py-0.5">
                            <div class="flex items-center justify-center gap-2">
                                <i class="fas fa-check-circle text-green-500"></i>
                                <span class="text-xs font-medium text-green-600 dark:text-green-400">Datos cargados desde <span x-text="ocrFileName"></span></span>
                                <button @click="ocrSuccess = false" class="text-gray-400 hover:text-gray-600 ml-1"><i class="fas fa-times text-xs"></i></button>
                            </div>
                        </div>
                        
                        <!-- Error -->
                        <div x-show="ocrError" class="mt-1">
                            <div class="flex items-center justify-center gap-2 bg-red-50 dark:bg-red-900/20 rounded-lg px-3 py-1.5">
                                <i class="fas fa-exclamation-triangle text-red-500 text-sm"></i>
                                <span class="text-xs text-red-600 dark:text-red-400" x-text="ocrError"></span>
                                <button @click="ocrError = ''" class="text-red-400 hover:text-red-600 ml-1"><i class="fas fa-times text-xs"></i></button>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="button"
                                @click="setCompraMode('producto')"
                                :class="!isGastoMode ? 'bg-orange-600 text-white border-orange-600' : 'bg-white dark:bg-slate-700 text-gray-600 dark:text-gray-300 border-gray-200 dark:border-slate-600'"
                                class="px-3 py-1.5 rounded-lg border text-xs font-semibold transition-colors">
                            Compra de productos
                        </button>
                        <button type="button"
                                @click="setCompraMode('gasto')"
                                :class="isGastoMode ? 'bg-rose-600 text-white border-rose-600' : 'bg-white dark:bg-slate-700 text-gray-600 dark:text-gray-300 border-gray-200 dark:border-slate-600'"
                                class="px-3 py-1.5 rounded-lg border text-xs font-semibold transition-colors">
                            Compra gastos
                        </button>
                    </div>

                    <!-- Datos principales: Proveedor + RUC + Factura en una fila -->
                    <div class="grid grid-cols-1 lg:grid-cols-7 gap-3">
                        <!-- Proveedor (ocupa 2 cols) -->
                        <div class="lg:col-span-2">
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-0.5">Proveedor</label>
                            <div class="relative">
                                <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                                <input type="text" 
                                       x-model="form.proveedorBuscar"
                                       @input.debounce.300ms="buscarProveedor()"
                                       @focus="showProveedores = true"
                                       placeholder="Nombre o RUC..."
                                       class="w-full pl-8 pr-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                                
                                <!-- Dropdown proveedores -->
                                <div x-show="showProveedores && proveedoresResults.length > 0" 
                                     @click.away="showProveedores = false"
                                     class="absolute z-50 w-full mt-1 bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-gray-200 dark:border-slate-700 max-h-40 overflow-y-auto">
                                    <template x-for="prov in proveedoresResults" :key="prov.id">
                                        <button @click="selectProveedor(prov)" 
                                                class="w-full px-3 py-1.5 text-left hover:bg-gray-100 dark:hover:bg-slate-700 flex justify-between items-center">
                                            <span class="text-sm text-gray-900 dark:text-white" x-text="prov.nombre"></span>
                                            <span class="text-xs text-gray-500" x-text="prov.numero"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                            <p x-show="form.proveedorId" class="text-[11px] text-green-600 mt-0.5 truncate">
                                <i class="fas fa-check mr-0.5"></i> <span x-text="form.proveedorNombre"></span>
                            </p>
                        </div>
                        
                        <!-- RUC Proveedor -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-0.5">RUC</label>
                            <input type="text" x-model="form.proveedorRuc" placeholder="80012345-6"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-orange-500 focus:border-transparent"
                                   :class="form.proveedorId ? 'bg-gray-50 dark:bg-slate-600/50' : ''">
                        </div>
                        
                        <!-- Nro Factura -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-0.5">Nro Factura</label>
                            <input type="text" x-model="form.nroFactura" placeholder="001-001-0000001"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        </div>
                        
                        <!-- Timbrado -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-0.5">Timbrado</label>
                            <input type="text" x-model="form.timbrado" placeholder="12345678"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        </div>
                        
                        <!-- Fecha -->
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-0.5">Fecha</label>
                            <input type="date" x-model="form.fecha"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        </div>
                        
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-0.5">Moneda</label>
                            <select x-model="form.moneda" @change="recalculatePricingAll()"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                                <option value="PYG">Guaraníes (Gs)</option>
                                <option value="BRL">Reales (R$)</option>
                            </select>
                        </div>
                        <div x-show="form.moneda === 'BRL'">
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-0.5">Cambio (R$ a Gs)</label>
                            <input type="number" x-model.number="form.cambio" @input="recalculatePricingAll()" min="1" step="1" placeholder="Ej: 1450"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-0.5">Recarga %</label>
                            <input type="number" x-model.number="form.recargaPct" @input="recalculatePricingAll()" min="0" step="0.01" placeholder="Ej: 35"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        </div>
                    </div>
                    
                    <!-- Items -->
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="text-xs font-medium text-gray-700 dark:text-gray-300" x-text="isGastoMode ? 'Rubros de gasto' : 'Productos'"></label>
                            <button @click="addItem()" class="text-xs text-orange-600 hover:text-orange-700 dark:text-orange-400 font-medium">
                                <i class="fas fa-plus mr-0.5"></i> Agregar línea
                            </button>
                        </div>
                        
                        <div class="border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-slate-700/50">
                                    <tr>
                                        <th class="px-2 py-1.5 text-left text-xs font-medium text-gray-500 dark:text-gray-400" :style="isGastoMode ? 'width:28%' : 'width:32%'" x-text="isGastoMode ? 'Detalle' : 'Descripción'"></th>
                                        <th class="px-2 py-1.5 text-left text-xs font-medium text-gray-500 dark:text-gray-400" :style="isGastoMode ? 'width:24%' : 'width:20%'">
                                            <template x-if="isGastoMode">
                                                <span><i class="fas fa-layer-group text-rose-400 mr-0.5"></i>Rubro gasto</span>
                                            </template>
                                            <template x-if="!isGastoMode">
                                                <span><i class="fas fa-link text-orange-400 mr-0.5"></i>Producto Local</span>
                                            </template>
                                        </th>
                                        <th x-show="!isGastoMode" class="px-2 py-1.5 text-left text-xs font-medium text-gray-500 dark:text-gray-400" style="width:12%">Cod. Proveedor</th>
                                        <th class="px-2 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400" style="width:8%">Cant</th>
                                        <th class="px-2 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400" style="width:12%">Costo Unit.</th>
                                        <th x-show="!isGastoMode" class="px-2 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400" style="width:11%">Precio Venta</th>
                                        <th class="px-2 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400" style="width:8%">Subtotal</th>
                                        <th class="px-2 py-1.5 text-center text-xs font-medium text-gray-500 dark:text-gray-400" style="width:7%">IVA</th>
                                        <th class="px-2 py-1.5 text-center text-xs font-medium text-gray-500 dark:text-gray-400" style="width:3%"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                                    <template x-for="(item, idx) in form.items" :key="idx">
                                        <tr class="group">
                                            <td class="px-1.5 py-1">
                                                <input type="text" x-model="item.descripcion" :placeholder="isGastoMode ? 'Detalle u observación' : 'Producto o servicio'"
                                                       class="w-full px-2 py-1.5 border border-gray-200 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white focus:ring-1 focus:ring-orange-500">
                                            </td>
                                            <!-- Producto Local (Asociar) -->
                                            <td class="px-1.5 py-1">
                                                <template x-if="isGastoMode">
                                                    <div class="relative">
                                                        <input type="text"
                                                               x-model="item.rubroBuscar"
                                                               @input.debounce.250ms="buscarRubroGasto(idx, item.rubroBuscar)"
                                                               @focus="buscarRubroGasto(idx, item.rubroBuscar || item.descripcion)"
                                                               @click.away="rubroSearchIdx !== idx || (showRubrosDropdown = false)"
                                                               placeholder="Rubro o concepto..."
                                                               class="w-full pl-7 pr-2 py-1.5 border border-gray-200 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-xs text-gray-900 dark:text-white focus:ring-1 focus:ring-rose-500 placeholder-gray-400">
                                                        <i class="fas fa-search absolute left-2 top-1/2 -translate-y-1/2 text-gray-400 text-[10px]"></i>
                                                        <div x-show="showRubrosDropdown && rubroSearchIdx === idx"
                                                             x-cloak
                                                             class="absolute z-[70] w-64 mt-1 bg-white dark:bg-slate-800 rounded-lg shadow-xl border border-gray-200 dark:border-slate-700 max-h-48 overflow-y-auto left-0">
                                                            <template x-for="rubro in rubrosResults" :key="rubro">
                                                                <button @click="selectRubroGasto(idx, rubro)"
                                                                        class="w-full px-3 py-1.5 text-left hover:bg-rose-50 dark:hover:bg-slate-700 text-xs text-gray-900 dark:text-white border-b border-gray-100 dark:border-slate-700/50 last:border-0"
                                                                        x-text="rubro"></button>
                                                            </template>
                                                            <div x-show="!rubroSearching && rubrosResults.length === 0" class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">
                                                                Sin sugerencias. Podés escribir un rubro nuevo.
                                                            </div>
                                                            <div x-show="rubroSearching" class="px-3 py-2 text-center">
                                                                <i class="fas fa-spinner fa-spin text-rose-500 text-xs"></i>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </template>
                                                <template x-if="!isGastoMode">
                                                <div class="relative">
                                                    <!-- Si ya asociado: mostrar badge -->
                                                    <div x-show="item.productoId" class="flex items-center gap-1">
                                                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded text-xs text-green-700 dark:text-green-400 truncate flex-1" :title="item.productoCodigo + ' - ' + item.productoBuscar">
                                                            <i class="fas fa-check-circle"></i>
                                                            <span class="truncate" x-text="item.productoBuscar || item.productoCodigo"></span>
                                                        </span>
                                                        <button @click="item.productoId = null; item.productoCodigo = ''; item.productoBuscar = ''" class="text-gray-400 hover:text-red-500 shrink-0" title="Desvincular">
                                                            <i class="fas fa-unlink text-xs"></i>
                                                        </button>
                                                    </div>
                                                    <!-- Si no asociado: input búsqueda -->
                                                    <div x-show="!item.productoId" class="relative">
                                                        <input type="text" 
                                                               :value="item.productoBuscar"
                                                               @input="item.productoBuscar = $event.target.value; buscarProductoItem(idx, $event.target.value)"
                                                               @focus="buscarProductoItem(idx, item.productoBuscar || item.descripcion)"
                                                               @click.away="prodSearchIdx !== idx || (showProdDropdown = false)"
                                                               placeholder="Buscar o crear..."
                                                               class="w-full pl-7 pr-2 py-1.5 border border-gray-200 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-xs text-gray-900 dark:text-white focus:ring-1 focus:ring-orange-500 placeholder-gray-400">
                                                        <i class="fas fa-search absolute left-2 top-1/2 -translate-y-1/2 text-gray-400 text-[10px]"></i>
                                                        
                                                        <!-- Dropdown resultados -->
                                                        <div x-show="showProdDropdown && prodSearchIdx === idx" 
                                                             x-cloak
                                                             class="absolute z-[70] w-64 mt-1 bg-white dark:bg-slate-800 rounded-lg shadow-xl border border-gray-200 dark:border-slate-700 max-h-48 overflow-y-auto left-0"
                                                             style="min-width: 250px;">
                                                            <!-- Resultados -->
                                                            <template x-for="prod in prodSearchResults" :key="prod.id">
                                                                <button @click="selectProductoItem(idx, prod)" 
                                                                        class="w-full px-3 py-1.5 text-left hover:bg-orange-50 dark:hover:bg-slate-700 flex items-center gap-2 border-b border-gray-100 dark:border-slate-700/50 last:border-0">
                                                                    <div class="flex-1 min-w-0">
                                                                        <p class="text-xs font-medium text-gray-900 dark:text-white truncate" x-text="prod.descripcion"></p>
                                                                        <p class="text-[10px] text-gray-500 dark:text-gray-400">
                                                                            <span x-text="prod.codigo"></span>
                                                                            <span class="mx-1">·</span>
                                                                            <span x-text="'Costo: ' + formatMoney(prod.precio_compra || 0) + ' Gs'"></span>
                                                                        </p>
                                                                    </div>
                                                                    <i class="fas fa-link text-orange-400 text-xs shrink-0"></i>
                                                                </button>
                                                            </template>
                                                            <!-- Sin resultados -->
                                                            <div x-show="!prodSearching && prodSearchResults.length === 0" class="px-3 py-3 text-center">
                                                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">No se encontró producto</p>
                                                                <button @click="abrirCrearProducto(idx)" 
                                                                        class="inline-flex items-center gap-1 px-3 py-1.5 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-xs font-medium transition-colors">
                                                                    <i class="fas fa-plus"></i> Crear producto nuevo
                                                                </button>
                                                            </div>
                                                            <!-- Buscando -->
                                                            <div x-show="prodSearching" class="px-3 py-2 text-center">
                                                                <i class="fas fa-spinner fa-spin text-orange-500 text-xs"></i>
                                                                <span class="text-xs text-gray-500 ml-1">Buscando...</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                </template>
                                            </td>
                                            <td x-show="!isGastoMode" class="px-1.5 py-1">
                                                <input type="text" x-model.trim="item.codigoProveedor" placeholder="Código proveedor"
                                                       class="w-full px-2 py-1.5 border border-gray-200 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-xs text-gray-900 dark:text-white focus:ring-1 focus:ring-orange-500">
                                            </td>
                                            <td class="px-1.5 py-1">
                                                <input type="number" x-model.number="item.cantidad" min="1" step="1"
                                                       class="w-full px-2 py-1.5 border border-gray-200 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-sm text-right text-gray-900 dark:text-white focus:ring-1 focus:ring-orange-500">
                                            </td>
                                            <td class="px-1.5 py-1">
                                                <input type="text" :value="formatNumber(item.costo, 2, 2)" @input="onCostoInput($event, item)" inputmode="decimal"
                                                       class="w-full px-2 py-1.5 border border-gray-200 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-sm text-right text-gray-900 dark:text-white focus:ring-1 focus:ring-orange-500">
                                                <p class="text-[10px] text-gray-400 text-right mt-0.5" x-text="'Gs ' + formatMoney(itemCostoGs(item))"></p>
                                            </td>
                                            <td x-show="!isGastoMode" class="px-1.5 py-1">
                                                <input type="text" :value="formatNumber(item.precioVenta, 0, 0)" @input="onPrecioVentaInput($event, item)" inputmode="numeric"
                                                       class="w-full px-2 py-1.5 border border-gray-200 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-sm text-right text-gray-900 dark:text-white focus:ring-1 focus:ring-orange-500">
                                                <p class="text-[10px] text-gray-400 text-right mt-0.5" x-text="'Gs ' + formatMoney(item.precioVenta || 0)"></p>
                                            </td>
                                            <td class="px-1.5 py-1 text-right">
                                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300" x-text="formatMoney(item.cantidad * itemCostoGs(item))"></span>
                                            </td>
                                            <td class="px-1.5 py-1">
                                                <select x-model="item.iva"
                                                        class="w-full px-1 py-1.5 border border-gray-200 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-xs text-gray-900 dark:text-white focus:ring-1 focus:ring-orange-500">
                                                    <option value="10">10%</option>
                                                    <option value="5">5%</option>
                                                    <option value="0">Ext</option>
                                                </select>
                                            </td>
                                            <td class="px-1 py-1 text-center">
                                                <button @click="removeItem(idx)" x-show="form.items.length > 1"
                                                        class="text-red-300 hover:text-red-500 transition-colors opacity-0 group-hover:opacity-100">
                                                    <i class="fas fa-trash-alt text-xs"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Nota + Totales en fila -->
                    <div class="flex gap-4 items-end">
                        <!-- Nota (lado izquierdo, crece) -->
                        <div class="flex-1">
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-0.5">Nota (opcional)</label>
                            <textarea x-model="form.nota" rows="2" placeholder="Observaciones..."
                                      class="w-full px-3 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-orange-500 focus:border-transparent resize-none"></textarea>
                        </div>
                        <!-- Totales (lado derecho, ancho fijo) -->
                        <div class="bg-orange-50 dark:bg-orange-900/20 rounded-lg px-5 py-2.5 space-y-1 min-w-[220px] shrink-0">
                            <div class="flex justify-between gap-4">
                                <span class="text-xs text-gray-600 dark:text-gray-400">Subtotal</span>
                                <span class="text-sm font-medium text-gray-900 dark:text-white" x-text="formatMoney(formTotal) + ' Gs'"></span>
                            </div>
                            <div class="flex justify-between gap-4 border-t border-orange-200 dark:border-orange-800 pt-1">
                                <span class="text-sm font-bold text-gray-800 dark:text-gray-200">Total</span>
                                <span class="text-base font-bold text-orange-600 dark:text-orange-400" x-text="formatMoney(formTotal) + ' Gs'"></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="px-4 py-2.5 bg-gray-50 dark:bg-slate-700/50 border-t border-gray-200 dark:border-slate-700 flex justify-end gap-3">
                    <button @click="showFormCompra = false" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-medium transition-colors">
                        Cancelar
                    </button>
                    <button @click="openFinalizeCompra()" 
                            :disabled="savingCompra"
                            class="px-5 py-2 bg-orange-600 hover:bg-orange-700 disabled:opacity-50 text-white rounded-lg text-sm font-medium transition-colors inline-flex items-center gap-2">
                        <i class="fas fa-arrow-right"></i>
                        <span>Continuar</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div x-show="showFinalizeCompraModal"
         x-cloak
         class="fixed inset-0 z-[65] flex items-center justify-center bg-black/70 p-4">
        <div class="checkout-panel bg-slate-950 text-white rounded-3xl w-full max-w-2xl overflow-hidden"
             @click.away="showFinalizeCompraModal = false">
            <div class="px-5 py-4 border-b border-slate-800 flex items-center justify-between">
                <div>
                    <h3 class="font-black text-white uppercase tracking-tight">Finalizar compra</h3>
                    <p class="text-xs text-slate-400">Definí comprobante y forma de pago.</p>
                </div>
                <button @click="showFinalizeCompraModal = false" class="w-9 h-9 rounded-xl bg-slate-900 border border-slate-700 flex items-center justify-center text-slate-300">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>
            <div class="p-5 space-y-5">
                <div class="checkout-section">
                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Tipo de comprobante</label>
                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" @click="finalizeCompra.tipoComprobante = 'FACTURA'"
                                class="checkout-tile"
                                :class="finalizeCompra.tipoComprobante === 'FACTURA' ? 'border-amber-400 bg-amber-700 text-amber-100 ring-2 ring-amber-300 shadow-lg shadow-amber-900 scale-[1.01]' : 'bg-slate-900 text-slate-400 hover:border-slate-500'">
                            <span class="inline-flex items-center gap-2 justify-center">
                                <span class="text-base">🧾</span>
                                <span>Factura</span>
                            </span>
                        </button>
                        <button type="button" @click="finalizeCompra.tipoComprobante = 'NOTA'"
                                class="checkout-tile"
                                :class="finalizeCompra.tipoComprobante === 'NOTA' ? 'border-amber-400 bg-amber-700 text-amber-100 ring-2 ring-amber-300 shadow-lg shadow-amber-900 scale-[1.01]' : 'bg-slate-900 text-slate-400 hover:border-slate-500'">
                            <span class="inline-flex items-center gap-2 justify-center">
                                <span class="text-base">🗒️</span>
                                <span>Nota</span>
                            </span>
                        </button>
                    </div>
                </div>
                <div class="checkout-section">
                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Forma de pago</label>
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-2">
                        <template x-for="pm in compraPaymentMethods" :key="pm.id">
                            <button type="button" @click="selectCompraPaymentMethod(pm.id)"
                                    class="relative rounded-xl border py-3 px-2 text-[10px] font-black transition-all flex flex-col items-center justify-center gap-1"
                                    :style="pm.style"
                                    :class="finalizeCompra.medioPago === pm.id ? pm.activeClass : ''">
                                <span class="text-2xl" x-text="pm.icon"></span>
                                <span x-text="pm.shortName || pm.name"></span>
                            </button>
                        </template>
                    </div>
                </div>
                <div x-show="!finalizeCompra.medioPago" class="checkout-section rounded-xl py-5 text-center">
                    <p class="text-sm font-semibold text-slate-300">Seleccione una forma de pago para continuar</p>
                </div>
                <div x-show="finalizeCompra.medioPago === 'tarjeta'" class="checkout-section space-y-2">
                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">Nro. Boucher</label>
                    <input type="text" x-model.trim="finalizeCompra.paymentRef" placeholder="Ingrese nro. boucher"
                           class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white">
                </div>
                <div x-show="finalizeCompra.medioPago === 'transferencia'" class="checkout-section space-y-2">
                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">REF Transferencia</label>
                    <input type="text" x-model.trim="finalizeCompra.paymentRef" placeholder="Ingrese referencia"
                           class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white">
                </div>
                <div x-show="finalizeCompra.medioPago === 'pix'" class="checkout-section space-y-2">
                    <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">Referencia PIX</label>
                    <input type="text" x-model.trim="finalizeCompra.paymentRef" placeholder="Ingrese referencia"
                           class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white">
                </div>
                <div x-show="finalizeCompra.medioPago === 'credito'" class="checkout-section space-y-2">
                    <div class="rounded-xl border border-amber-500 bg-amber-900/40 p-3">
                        <div class="flex items-center gap-2 text-amber-300">
                            <span class="text-lg">⚠️</span>
                            <span class="text-xs font-bold">Esta compra quedará pendiente de pago.</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Cuotas</label>
                            <select x-model.number="finalizeCompra.creditInstallments"
                                    class="w-full bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-sm text-white">
                                <template x-for="n in [1,2,3,4,5,6,9,12]" :key="'credito-compra-' + n">
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
                <div x-show="finalizeCompra.medioPago && finalizeCompra.medioPago !== 'efectivo'" class="checkout-section rounded-xl p-3.5 text-center border-emerald-500 bg-emerald-900/80">
                    <div class="text-[10px] font-bold uppercase tracking-wider text-emerald-300 mb-0.5">Cobertura</div>
                    <div class="text-2xl font-black text-emerald-300" x-text="formatMoney(formTotal) + ' Gs'"></div>
                </div>
                <div class="checkout-footer -mx-5 -mb-5 mt-2 px-5 py-4">
                    <div class="grid grid-cols-2 gap-2">
                    <button @click="showFinalizeCompraModal = false"
                            class="py-3 rounded-xl font-semibold text-base text-red-100 border border-red-500 bg-red-700 hover:bg-red-600 transition-all">
                        Cancelar
                    </button>
                    <button @click="guardarCompra()"
                            :disabled="savingCompra || !finalizeCompra.medioPago || (requiresCompraReference(finalizeCompra.medioPago) && !String(finalizeCompra.paymentRef || '').trim())"
                            class="py-3 rounded-xl font-bold text-base text-white bg-blue-600 hover:bg-blue-500 transition-all disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                        <i class="fas" :class="savingCompra ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                        <span x-text="savingCompra ? 'Procesando...' : (isEditingCompra ? 'Actualizar compra' : 'Guardar compra')"></span>
                    </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Crear Producto Rápido -->
    <div x-show="showCrearProducto" x-cloak
         class="fixed inset-0 z-[70] flex items-center justify-center bg-black/60"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl w-full max-w-md mx-4 overflow-hidden"
             @click.away="showCrearProducto = false"
             x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
            <!-- Header -->
            <div class="px-5 py-2.5 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between bg-orange-50 dark:bg-orange-900/20">
                <div class="flex items-center gap-2">
                    <i class="fas fa-box-open text-orange-500"></i>
                    <span class="font-semibold text-sm text-gray-900 dark:text-white">Crear Producto Nuevo</span>
                </div>
                <button @click="showCrearProducto = false" class="w-7 h-7 rounded-lg hover:bg-gray-200 dark:hover:bg-slate-700 flex items-center justify-center text-gray-400">
                    <i class="fas fa-times text-xs"></i>
                </button>
            </div>
            <!-- Form -->
            <div class="px-5 py-4 space-y-3">
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Código</label>
                        <input type="text" x-model="nuevoProducto.codigo" placeholder="Auto"
                               class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Nombre *</label>
                        <input type="text" x-model="nuevoProducto.nombre" placeholder="Nombre del producto"
                               class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Precio Compra</label>
                        <input type="number" x-model.number="nuevoProducto.precioCompra" min="0"
                               class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-right text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">Precio Venta</label>
                        <input type="number" x-model.number="nuevoProducto.precioVenta" min="0"
                               class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-right text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-0.5">IVA</label>
                        <select x-model="nuevoProducto.iva"
                                class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-sm text-gray-900 dark:text-white focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                            <option value="10">10%</option>
                            <option value="5">5%</option>
                            <option value="0">Exenta</option>
                        </select>
                    </div>
                </div>
            </div>
            <!-- Footer -->
            <div class="px-5 py-2.5 bg-gray-50 dark:bg-slate-700/50 border-t border-gray-200 dark:border-slate-700 flex justify-end gap-2">
                <button @click="showCrearProducto = false" class="px-3 py-1.5 text-sm text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-slate-600 rounded-lg transition-colors">
                    Cancelar
                </button>
                <button @click="guardarNuevoProducto()" :disabled="creandoProducto"
                        class="px-4 py-1.5 bg-orange-600 hover:bg-orange-700 disabled:opacity-50 text-white text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1.5">
                    <i class="fas" :class="creandoProducto ? 'fa-spinner fa-spin' : 'fa-plus'"></i>
                    <span x-text="creandoProducto ? 'Creando...' : 'Crear y Asociar'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Webcam Modal -->
    <div x-show="showWebcam" x-cloak
         class="fixed inset-0 z-[60] flex items-center justify-center bg-black/70"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-xl w-full mx-4 overflow-hidden" @click.away="closeWebcam()">
            <!-- Header -->
            <div class="px-5 py-3 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between bg-purple-50 dark:bg-purple-900/20">
                <div class="flex items-center gap-2">
                    <i class="fas fa-video text-purple-600 dark:text-purple-400"></i>
                    <span class="font-semibold text-gray-900 dark:text-white">Capturar Factura</span>
                </div>
                <button @click="closeWebcam()" class="w-8 h-8 rounded-lg hover:bg-gray-200 dark:hover:bg-slate-700 flex items-center justify-center text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <!-- Video / Preview -->
            <div class="relative bg-black aspect-[4/3] flex items-center justify-center">
                <video x-ref="webcamVideo" autoplay playsinline class="w-full h-full object-contain" x-show="!webcamCaptured"></video>
                <canvas x-ref="webcamCanvas" class="w-full h-full object-contain" x-show="webcamCaptured" style="display:none;"></canvas>
                <div x-show="webcamLoading && !webcamCaptured" class="absolute inset-0 flex items-center justify-center">
                    <div class="text-center text-white">
                        <i class="fas fa-spinner fa-spin text-3xl mb-2"></i>
                        <p class="text-sm">Iniciando cámara...</p>
                    </div>
                </div>
            </div>
            <!-- Controls -->
            <div class="px-5 py-3 flex items-center justify-center gap-3 bg-gray-50 dark:bg-slate-700/50">
                <template x-if="!webcamCaptured">
                    <button @click="captureWebcam()" :disabled="webcamLoading"
                            class="px-5 py-2.5 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white rounded-xl font-medium transition-all active:scale-95 inline-flex items-center gap-2 shadow-lg shadow-purple-500/25">
                        <i class="fas fa-camera text-lg"></i> Capturar
                    </button>
                </template>
                <template x-if="webcamCaptured">
                    <div class="flex gap-3">
                        <button @click="webcamCaptured = false" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 dark:bg-slate-600 dark:hover:bg-slate-500 text-gray-700 dark:text-gray-200 rounded-xl font-medium transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-redo"></i> Repetir
                        </button>
                        <button @click="useWebcamCapture()" class="px-5 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-xl font-medium transition-colors inline-flex items-center gap-2 shadow-lg shadow-orange-500/25">
                            <i class="fas fa-magic"></i> Procesar con IA
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>
    
    <script>
    function comprasApp() {
        return {
            // Config
            idEmpresa: <?= $id_empresa ?>,
            isDark: document.documentElement.classList.contains('dark'),
            permisos: window.__PERMISOS__ || {},
            
            // Data
            compras: [],
            stats: {
                totalCompras: 0,
                montoTotal: 0,
                comprasHoy: 0,
                totalPendiente: 0
            },
            
            // Filtros
            searchQuery: '',
            fechaDesde: '',
            fechaHasta: '',
            estadoFiltro: 'activo',
            periodoActivo: 'hoy',
            periodos: [
                { key: 'hoy', label: '<?= htmlspecialchars(t('common.today'), ENT_QUOTES) ?>' },
                { key: 'semana', label: '<?= htmlspecialchars(t('common.week'), ENT_QUOTES) ?>' },
                { key: 'mes', label: '<?= htmlspecialchars(t('common.month'), ENT_QUOTES) ?>' },
                { key: 'anio', label: '<?= htmlspecialchars(t('common.year'), ENT_QUOTES) ?>' },
                { key: 'custom', label: '<?= htmlspecialchars(t('common.custom'), ENT_QUOTES) ?>' }
            ],
            
            // Paginación
            currentPage: 1,
            perPage: 20,
            totalRecords: 0,
            totalPages: 1,
            
            // UI
            loading: true,
            useAgGrid: true,
            gridApiCompras: null,
            gridVisibleRows: 0,
            showModal: false,
            selectedCompra: null,
            compraItems: [],
            showFormCompra: false,
            showFinalizeCompraModal: false,
            savingCompra: false,
            sortBy: 'fecha',
            sortDir: 'desc',
            showProveedores: false,
            proveedoresResults: [],
            rubroSearchIdx: null,
            rubrosResults: [],
            rubroSearching: false,
            showRubrosDropdown: false,
            
            // OCR
            ocrProcessing: false,
            ocrSuccess: false,
            ocrError: '',
            ocrStatusMsg: 'Analizando documento...',
            ocrProgress: 0,
            ocrFileName: '',
            ocrDragOver: false,
            ocrMode: 'google_vision',
            ocrPasteHandler: null,
            ocrAbortController: null,
            ocrCancelled: false,
            
            // Webcam
            showWebcam: false,
            webcamStream: null,
            webcamCaptured: false,
            webcamLoading: false,
            
            // Producto asociar
            prodSearchIdx: null,
            prodSearchQuery: '',
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
            editingCompraId: null,
            offlineQueue: [],
            
            // Form nueva compra
                form: {
                    tipoCompra: 'producto',
                    proveedorId: null,
                    proveedorNombre: '',
                    proveedorRuc: '',
                    proveedorBuscar: '',
                    nroFactura: '',
                    timbrado: '',
                    fecha: (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; })(),
                    formaPago: '1',
                    moneda: 'PYG',
                    cambio: 1,
                    recargaPct: 35,
                    nota: '',
                    items: [{ descripcion: '', rubroBuscar: '', codigoProveedor: '', cantidad: 1, costo: 0, precioVenta: 0, iva: '10', productoId: null, productoCodigo: '', productoBuscar: '' }]
                },

            get isGastoMode() {
                return this.form.tipoCompra === 'gasto';
            },
            get isEditingCompra() {
                return Number(this.editingCompraId || 0) > 0;
            },
            
            get formTotal() {
                return this.form.items.reduce((sum, item) => sum + (item.cantidad * this.itemCostoGs(item)), 0);
            },
            offlineKey(suffix) {
                return `smx:compras:desktop:${this.idEmpresa}:${suffix}`;
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
                        const response = await fetch('/modelos/compras_api.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(entry.payload)
                        });
                        const data = await response.json();
                        if (!data.success) throw new Error(data.message || 'No se pudo sincronizar compra');
                    } catch (_) {
                        remaining.push(entry);
                    }
                }
                this.offlineQueue = remaining;
                this.writeStorage(this.offlineKey('queue'), remaining);
                this.loadCompras();
            },
            
            async init() {
                await (window.SmxOfflineDb?.ready || Promise.resolve());
                this.offlineQueue = this.readStorage(this.offlineKey('queue'), []);
                window.addEventListener('online', () => this.syncOfflineQueue());
                this.setPeriodo('hoy', false);
                if (window.__agGridReady) {
                    await window.__agGridReady;
                }
                this.ensureGrid();
                this.loadCompras();
                this.setupOcrPasteListener();
                this.syncOfflineQueue();
            },
            openCompraForm(mode = 'producto') {
                this.resetForm();
                this.setCompraMode(mode);
                this.showFormCompra = true;
            },
            setCompraMode(mode = 'producto') {
                this.form.tipoCompra = mode === 'gasto' ? 'gasto' : 'producto';
                this.form.items = this.form.items.map(item => ({
                    ...item,
                    rubroBuscar: item.rubroBuscar || ''
                }));
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
            getItemsValidos() {
                if (this.isGastoMode) {
                    return this.form.items.filter(i => (String(i.rubroBuscar || i.descripcion || '').trim() !== '') && Number(i.cantidad || 0) > 0 && Number(i.costo || 0) > 0);
                }
                return this.form.items.filter(i => String(i.descripcion || '').trim() !== '' && Number(i.cantidad || 0) > 0 && Number(i.costo || 0) > 0);
            },
            openFinalizeCompra() {
                const proveedorNombre = (this.form.proveedorNombre || this.form.proveedorBuscar || '').trim();
                if (!this.form.proveedorId && !proveedorNombre) {
                    alert('Selecciona o escribe el proveedor');
                    return;
                }
                if (!this.form.nroFactura.trim()) {
                    alert('Ingresa el número de factura');
                    return;
                }
                if (this.form.moneda === 'BRL' && (!this.form.cambio || this.form.cambio <= 0)) {
                    alert('Ingresa el cambio de R$ a Gs');
                    return;
                }
                const itemsValidos = this.getItemsValidos();
                if (itemsValidos.length === 0) {
                    alert(this.isGastoMode ? 'Agrega al menos un rubro de gasto con monto' : 'Agrega al menos un producto con cantidad y costo');
                    return;
                }
                this.showFinalizeCompraModal = true;
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
                    case 'hoy':
                        this.fechaDesde = fmt(today);
                        break;
                    case 'semana': {
                        const dow = today.getDay();
                        const monday = new Date(today);
                        monday.setDate(today.getDate() - (dow === 0 ? 6 : dow - 1));
                        this.fechaDesde = fmt(monday);
                        break;
                    }
                    case 'mes':
                        this.fechaDesde = fmt(new Date(today.getFullYear(), today.getMonth(), 1));
                        break;
                    case 'anio':
                        this.fechaDesde = fmt(new Date(today.getFullYear(), 0, 1));
                        break;
                    case 'custom':
                        break;
                }
                
                this.currentPage = 1;
                if (reload) this.loadCompras();
            },
            
            async loadCompras() {
                if (this.useAgGrid && this.gridApiCompras) {
                    this.loading = true;
                    this.resetComprasDatasource(true);
                    return;
                }
                this.loading = true;
                const params = new URLSearchParams({
                    id_empresa: this.idEmpresa,
                    page: this.currentPage,
                    per_page: this.perPage,
                    search: this.searchQuery,
                    fecha_desde: this.fechaDesde,
                    fecha_hasta: this.fechaHasta,
                    estado: this.estadoFiltro
                });
                try {
                    const response = await fetch(`/public/compras/api/list.php?${params}`);
                    const data = await response.json();
                    
                    if (data.success) {
                        this.compras = this.mergeQueuedCompras(data.compras || []);
                        this.totalRecords = Math.max(Number(data.total || 0) + this.offlineQueue.length, this.compras.length);
                        this.totalPages = Math.ceil(this.totalRecords / this.perPage) || 1;
                        this.stats = data.stats || this.stats;
                        this.gridVisibleRows = this.compras.length;
                        this.writeStorage(this.offlineKey(`list:${params.toString()}`), {
                            compras: data.compras || [],
                            totalRecords: data.total || 0,
                            totalPages: Math.ceil((data.total || 0) / this.perPage) || 1,
                            stats: this.stats,
                        });
                    }
                } catch (error) {
                    const cached = this.readStorage(this.offlineKey(`list:${params.toString()}`));
                    if (cached) {
                        this.compras = this.mergeQueuedCompras(cached.compras || []);
                        this.totalRecords = Math.max(Number(cached.totalRecords || 0) + this.offlineQueue.length, this.compras.length);
                        this.totalPages = cached.totalPages || 1;
                        this.stats = cached.stats || this.stats;
                        this.gridVisibleRows = this.compras.length;
                        alert('Mostrando compras desde cache offline');
                    } else {
                        console.error('Error cargando compras:', error);
                    }
                }
                
                this.loading = false;
            },
            _applyAgGridLicense() {
                if (typeof agGrid !== 'undefined' && agGrid.LicenseManager) {
                    agGrid.LicenseManager.setLicenseKey('DownloadDevTools_COM_NDEwMjM0NTgwMDAwMA==59158b5225400879a12a96634544f5b6');
                }
            },
            syncGridThemeClasses() {
                const gridEl = this.$refs.gridCompras;
                if (!gridEl) return;
                gridEl.classList.toggle('ag-theme-quartz', !this.isDark);
                gridEl.classList.toggle('ag-theme-quartz-dark', this.isDark);
            },
            ensureGrid() {
                if (!this.useAgGrid || !window.agGrid || this.gridApiCompras || !this.$refs.gridCompras) return;
                this._applyAgGridLicense();
                const columnDefs = [
                    {
                        field: 'nro_factura',
                        headerName: 'Nro / Timb.',
                        minWidth: 180,
                        sortable: true,
                        cellRenderer: (p) => {
                            const nro = this.escapeHtml(p.data?.nro_factura || '-');
                            const timb = this.escapeHtml(p.data?.timbrado || '');
                            return `<div class="compra-cell-stack"><div class="main" style="font-family:ui-monospace, SFMono-Regular, monospace;">${nro}</div>${timb ? `<div class="meta">Timb: ${timb}</div>` : ''}</div>`;
                        }
                    },
                    {
                        field: 'fecha',
                        headerName: 'Fecha',
                        minWidth: 170,
                        sortable: true,
                        cellRenderer: (p) => {
                            const fecha = this.escapeHtml(this.formatDate(p.data?.fecha));
                            const venc = p.data?.vencimiento ? this.escapeHtml(this.formatDate(p.data?.vencimiento)) : '';
                            return `<div class="compra-cell-stack"><div class="main" style="font-weight:500;">${fecha}</div>${venc ? `<div class="meta">Venc: ${venc}</div>` : ''}</div>`;
                        }
                    },
                    {
                        field: 'proveedor_nombre',
                        headerName: 'Proveedor',
                        minWidth: 220,
                        flex: 1.3,
                        sortable: true,
                        cellRenderer: (p) => {
                            const prov = this.escapeHtml(p.data?.proveedor_nombre || 'Sin proveedor');
                            const ruc = this.escapeHtml(p.data?.proveedor_ruc || '-');
                            return `<div class="compra-cell-stack"><div class="main">${prov}</div><div class="meta">${ruc}</div></div>`;
                        }
                    },
                    {
                        field: 'total',
                        headerName: 'Total',
                        minWidth: 140,
                        sortable: true,
                        type: 'numericColumn',
                        cellStyle: { textAlign: 'right' },
                        valueFormatter: (p) => `${this.formatMoney(p.value)} Gs`
                    },
                    {
                        field: 'pendiente',
                        headerName: 'Pendiente',
                        minWidth: 150,
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
                        minWidth: 120,
                        sortable: true,
                        cellStyle: { textAlign: 'center' },
                        cellRenderer: (p) => Number(p.value || 0) === 1
                            ? '<span class="compra-badge ok">Activo</span>'
                            : '<span class="compra-badge off">Anulado</span>'
                    },
                    {
                        headerName: 'Acciones',
                        field: 'acciones',
                        minWidth: 210,
                        maxWidth: 230,
                        sortable: false,
                        filter: false,
                        suppressHeaderMenuButton: true,
                        cellRenderer: (p) => this.renderCompraActionsCell(p.data || {})
                    }
                ];
                const gridOpts = {
                    columnDefs,
                    localeText: window.SmxAgGridLocale?.getLocaleText?.() || {},
                    rowModelType: 'infinite',
                    cacheBlockSize: this.perPage,
                    pagination: false,
                    animateRows: true,
                    rowHeight: 58,
                    defaultColDef: {
                        resizable: true,
                        sortable: true,
                        filter: false
                    },
                    onGridReady: () => {
                        this.syncGridThemeClasses();
                        this.resetComprasDatasource();
                        setTimeout(() => this.gridApiCompras?.sizeColumnsToFit?.(), 80);
                    },
                    onGridSizeChanged: () => this.gridApiCompras?.sizeColumnsToFit?.(),
                    onModelUpdated: () => {
                        this.gridVisibleRows = Number(this.gridApiCompras?.getDisplayedRowCount?.() || 0);
                    },
                    onCellClicked: (params) => this.onComprasGridCellClicked(params)
                };
                this.gridApiCompras = agGrid.createGrid(this.$refs.gridCompras, gridOpts);
            },
            resetComprasDatasource(purge = false) {
                if (!this.gridApiCompras) return;
                const self = this;
                const ds = {
                    rowCount: undefined,
                    async getRows(params) {
                        try {
                            const start = Number(params.startRow || 0);
                            const perPage = self.perPage;
                            const page = Math.floor(start / perPage) + 1;
                            const sort = Array.isArray(params.sortModel) && params.sortModel.length ? params.sortModel[0] : null;
                            const sortBy = String(sort?.colId || self.sortBy || 'fecha');
                            const sortDir = String(sort?.sort || self.sortDir || 'desc').toLowerCase() === 'asc' ? 'asc' : 'desc';
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
                            const response = await fetch(`/public/compras/api/list.php?${query.toString()}`);
                            const data = await response.json();
                            if (!data.success) {
                                throw new Error(data.message || 'Error');
                            }
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
                                self.gridVisibleRows = Number(self.gridApiCompras?.getDisplayedRowCount?.() || 0);
                            }, 0);
                        } catch (error) {
                            const query = new URLSearchParams({
                                id_empresa: String(self.idEmpresa),
                                page: String(Math.floor(Number(params.startRow || 0) / self.perPage) + 1),
                                per_page: String(self.perPage),
                                search: String(self.searchQuery || ''),
                                fecha_desde: String(self.fechaDesde || ''),
                                fecha_hasta: String(self.fechaHasta || ''),
                                estado: String(self.estadoFiltro || ''),
                                sort_by: String((Array.isArray(params.sortModel) && params.sortModel[0]?.colId) || self.sortBy || 'fecha'),
                                sort_dir: String((Array.isArray(params.sortModel) && params.sortModel[0]?.sort) || self.sortDir || 'desc')
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
                                    self.gridVisibleRows = Number(self.gridApiCompras?.getDisplayedRowCount?.() || 0);
                                }, 0);
                                return;
                            }
                            console.error('Error datasource compras:', error);
                            self.loading = false;
                            params.failCallback();
                        }
                    }
                };
                this.gridApiCompras.setGridOption('datasource', ds);
                if (purge && this.gridApiCompras.refreshInfiniteCache) {
                    this.gridApiCompras.refreshInfiniteCache();
                }
            },
            renderCompraActionsCell(compra) {
                const id = Number(compra.id_factura || 0);
                const buttons = [
                    `<button class="view" data-action="view" data-id="${id}" title="Ver detalle"><i class="fas fa-eye"></i></button>`,
                    `<button class="print" data-action="print" data-id="${id}" title="Imprimir"><i class="fas fa-print"></i></button>`
                ];
                if (this.permisos?.priv_update === 'Y') {
                    buttons.push(`<button class="edit" data-action="edit" data-id="${id}" title="Editar"><i class="fas fa-edit"></i></button>`);
                }
                buttons.push(`<button class="copy" data-action="duplicate" data-id="${id}" title="Duplicar"><i class="fas fa-copy"></i></button>`);
                if (Number(compra.estado || 0) === 1 && this.permisos?.priv_delete === 'Y') {
                    buttons.push(`<button class="void" data-action="void" data-id="${id}" title="Anular"><i class="fas fa-ban"></i></button>`);
                }
                return `<div class="grid-actions">${buttons.join('')}</div>`;
            },
            findCompraById(idFactura) {
                const id = Number(idFactura || 0);
                return (Array.isArray(this.compras) ? this.compras : []).find(c => Number(c.id_factura || 0) === id) || null;
            },
            onComprasGridCellClicked(params) {
                const target = params.event?.target;
                const actionEl = target?.closest?.('button[data-action]');
                if (!actionEl) return;
                const action = actionEl.dataset.action;
                const compra = this.findCompraById(actionEl.dataset.id) || params.data || null;
                if (!compra) return;
                if (action === 'view') this.verCompra(compra);
                if (action === 'print') this.imprimirCompra(compra);
                if (action === 'edit') this.editarCompra(compra);
                if (action === 'duplicate') this.duplicarCompra(compra);
                if (action === 'void') this.anularCompra(compra);
            },
            escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            },
            
            async verCompra(compra) {
                this.selectedCompra = compra;
                this.compraItems = [];
                this.showModal = true;
                
                try {
                    const data = await this.fetchCompraDetalle(compra.id_factura);
                    this.compraItems = data.items || [];
                } catch (error) {
                    console.error('Error cargando detalle:', error);
                }
            },
            async fetchCompraDetalle(idFactura) {
                const response = await fetch(`/modelos/compras_api.php?action=get&id_factura=${idFactura}&id_empresa=${this.idEmpresa}`);
                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'No se pudo cargar la compra');
                }
                return data;
            },
            
            imprimirCompra(compra) {
                if (!compra) return;
                window.open(`/modelos/compras_api.php?action=print&id_factura=${compra.id_factura}&id_empresa=${this.idEmpresa}`, '_blank');
            },
            
            normalizeCompraPaymentMethod(compra = {}) {
                const medio = String(compra.medio_pago || '').trim().toLowerCase();
                if (medio) return medio;
                return String(compra.forma_pago || '') === '2' ? 'credito' : 'efectivo';
            },
            async editarCompra(compra) {
                if (!compra?.id_factura) return;
                try {
                    const data = await this.fetchCompraDetalle(compra.id_factura);
                    const compraData = data.compra || {};
                    const items = Array.isArray(data.items) ? data.items : [];
                    this.resetForm();
                    this.editingCompraId = Number(compra.id_factura);
                    this.showFormCompra = true;
                    this.showModal = false;
                    this.form.tipoCompra = parseInt(compraData.tipo_compra || compra.tipo_compra || 1) === 2 ? 'gasto' : 'producto';
                    this.form.proveedorId = Number(compraData.id_cliente || compra.id_proveedor || 0) || null;
                    this.form.proveedorNombre = compraData.proveedor || compra.proveedor || '';
                    this.form.proveedorRuc = compraData.ruc || compra.ruc || '';
                    this.form.proveedorBuscar = this.form.proveedorNombre;
                    this.form.nroFactura = compraData.nro_factura || '';
                    this.form.timbrado = compraData.timbrado || '';
                    this.form.fecha = String(compraData.fecha || compra.fecha || this.localDateStr(new Date())).slice(0, 10);
                    this.form.formaPago = String(compraData.forma_pago || compra.forma_pago || '1');
                    this.form.moneda = Number(compraData.id_moneda || 1) === 2 ? 'BRL' : 'PYG';
                    this.form.cambio = parseFloat(compraData.cambio) > 0 ? parseFloat(compraData.cambio) : 1;
                    this.form.nota = compraData.nota || '';
                    this.form.items = items.length > 0
                        ? items.map(i => ({
                            descripcion: i.descripcion || i.producto_nombre || '',
                            rubroBuscar: i.rubro || i.descripcion || i.producto_nombre || '',
                            codigoProveedor: i.codigo || '',
                            cantidad: parseFloat(i.cantidad) || 1,
                            costo: parseFloat(i.costo_gs) || 0,
                            precioVenta: parseFloat(i.precio_venta || i.venta_ocr || 0) || 0,
                            iva: String(i.tipo_iva || (this.form.tipoCompra === 'gasto' ? '0' : '10')),
                            productoId: parseInt(i.idproducto || 0) || null,
                            productoCodigo: i.codigo || '',
                            productoBuscar: i.producto_nombre || i.descripcion || ''
                        }))
                        : [{ descripcion: '', rubroBuscar: '', codigoProveedor: '', cantidad: 1, costo: 0, precioVenta: 0, iva: this.isGastoMode ? '0' : '10', productoId: null, productoCodigo: '', productoBuscar: '' }];
                    this.finalizeCompra = {
                        tipoComprobante: String(compraData.tipo_comprobante || compra.tipo_comprobante || 'FACTURA').toUpperCase() || 'FACTURA',
                        medioPago: this.normalizeCompraPaymentMethod(compraData),
                        paymentRef: compraData.payment_ref || compraData.referencia_pago || '',
                        creditInstallments: parseInt(compraData.credit_installments || 1, 10) || 1,
                        creditDueDate: String(compraData.credit_due_date || '').slice(0, 10),
                        creditNotes: compraData.credit_notes || ''
                    };
                    this.recalculatePricingAll();
                } catch (error) {
                    console.error('Error cargando compra para editar:', error);
                    alert(error.message || 'No se pudo cargar la compra para editar');
                }
            },
            
            duplicarCompra(compra) {
                this.resetForm();
                this.showFormCompra = true;
                this.form.proveedorId = null;
                this.form.proveedorBuscar = compra.proveedor_nombre || '';
                this.form.nroFactura = '';
                this.form.timbrado = compra.timbrado || '';
                this.form.fecha = this.localDateStr(new Date());
                this.verCompra(compra).then(() => {
                    if (this.compraItems.length > 0) {
                        this.form.items = this.compraItems.map(i => ({
                            descripcion: i.descripcion || i.producto_nombre || '',
                            rubroBuscar: i.rubro || i.producto_nombre || '',
                            codigoProveedor: i.codigo || '',
                            cantidad: parseFloat(i.cantidad) || 1,
                            costo: parseFloat(i.costo_gs) || 0,
                            precioVenta: parseFloat(i.precio_venta || i.venta_ocr || 0) || 0,
                            iva: String(i.tipo_iva || '10')
                        }));
                        this.form.tipoCompra = parseInt(compra.tipo_compra || 1) === 2 ? 'gasto' : 'producto';
                        this.recalculatePricingAll();
                    }
                    this.showModal = false;
                });
            },
            
            async anularCompra(compra) {
                if (!confirm('¿Está seguro de anular esta compra?')) return;
                
                try {
                    const response = await fetch('/modelos/compras_api.php?action=anular', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id_factura: compra.id_factura, id_empresa: this.idEmpresa })
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        this.loadCompras();
                    } else {
                        alert(data.message || 'Error al anular');
                    }
                } catch (error) {
                    alert('Error de conexión');
                }
            },
            
            async buscarProveedor() {
                if (this.form.proveedorBuscar.length < 2) {
                    this.proveedoresResults = [];
                    return;
                }
                
                try {
                    const q = encodeURIComponent(this.form.proveedorBuscar);
                    const base = `/modelos/compras_api.php?id_empresa=${this.idEmpresa}&q=${q}&_t=${Date.now()}`;
                    let response = await fetch(`${base}&action=search_proveedor`, { cache: 'no-store' });
                    if (!response.ok) {
                        response = await fetch(`${base}&action=search_proveedores`, { cache: 'no-store' });
                    }
                    if (!response.ok) {
                        this.proveedoresResults = [];
                        this.showProveedores = false;
                        return;
                    }
                    const data = await response.json();
                    this.proveedoresResults = (data && data.success) ? (data.proveedores || []) : [];
                    this.showProveedores = this.proveedoresResults.length > 0;
                } catch (e) {
                    console.error(e);
                }
            },
            
            selectProveedor(prov) {
                this.form.proveedorId = prov.id;
                this.form.proveedorNombre = prov.nombre;
                this.form.proveedorRuc = prov.numero;
                this.form.proveedorBuscar = prov.nombre;
                this.showProveedores = false;
            },
            
            addItem() {
                this.form.items.push({ descripcion: '', rubroBuscar: '', codigoProveedor: '', cantidad: 1, costo: 0, precioVenta: 0, iva: this.isGastoMode ? '0' : '10', productoId: null, productoCodigo: '', productoBuscar: '' });
                this.recalculatePricingAll();
            },
            
            removeItem(idx) {
                this.form.items.splice(idx, 1);
            },
            
            resetForm() {
                this.form = {
                    tipoCompra: 'producto',
                    proveedorId: null,
                    proveedorNombre: '',
                    proveedorRuc: '',
                    proveedorBuscar: '',
                    nroFactura: '',
                    timbrado: '',
                    fecha: this.localDateStr(new Date()),
                    formaPago: '1',
                    moneda: 'PYG',
                    cambio: 1,
                    recargaPct: 35,
                    nota: '',
                    items: [{ descripcion: '', rubroBuscar: '', codigoProveedor: '', cantidad: 1, costo: 0, precioVenta: 0, iva: '10', productoId: null, productoCodigo: '', productoBuscar: '' }]
                };
                this.editingCompraId = null;
                this.finalizeCompra = { tipoComprobante: 'FACTURA', medioPago: 'efectivo', paymentRef: '', creditInstallments: 1, creditDueDate: '', creditNotes: '' };
                this.showFinalizeCompraModal = false;
                this.ocrSuccess = false;
                this.ocrError = '';
                this.ocrProcessing = false;
                this.ocrFileName = '';
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
                const itemsValidos = this.getItemsValidos();
                if (this.requiresCompraReference(this.finalizeCompra.medioPago) && !String(this.finalizeCompra.paymentRef || '').trim()) {
                    alert('Ingresá la referencia del medio de pago');
                    return;
                }
                
                this.savingCompra = true;
                
                try {
                    const fx = this.form.moneda === 'BRL' ? (parseFloat(this.form.cambio) || 0) : 1;
                    const payload = {
                        action: 'save',
                        id_factura: this.editingCompraId || 0,
                        tipo_compra: this.form.tipoCompra,
                        id_empresa: this.idEmpresa,
                        id_proveedor: this.form.proveedorId || 0,
                        id_cliente: this.form.proveedorId || 0,
                        proveedor_nombre: proveedorNombre,
                        ruc: (this.form.proveedorRuc || '').trim(),
                        nro_factura: this.form.nroFactura,
                        timbrado: this.form.timbrado,
                        fecha: this.form.fecha,
                        forma_pago: this.finalizeCompra.medioPago === 'credito' ? 2 : 1,
                        medio_pago: this.finalizeCompra.medioPago,
                        tipo_comprobante: this.finalizeCompra.tipoComprobante,
                        payment_ref: this.finalizeCompra.paymentRef,
                        credit_installments: this.finalizeCompra.medioPago === 'credito' ? Math.max(parseInt(this.finalizeCompra.creditInstallments, 10) || 1, 1) : 1,
                        credit_due_date: this.finalizeCompra.medioPago === 'credito' ? this.finalizeCompra.creditDueDate : '',
                        credit_notes: this.finalizeCompra.medioPago === 'credito' ? this.finalizeCompra.creditNotes : '',
                        moneda: this.form.moneda,
                        cambio: fx,
                        nota: this.form.nota,
                        items: itemsValidos.map(i => ({
                            rubro: (i.rubroBuscar || i.descripcion || '').trim(),
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
                        if (this.isEditingCompra) {
                            alert('Editar compras offline no está soportado todavía.');
                            return;
                        }
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
                    
                    const response = await fetch('/modelos/compras_api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        const nuevos = Array.isArray(data.productos_nuevos) ? data.productos_nuevos : [];
                        if (nuevos.length > 0) {
                            const det = nuevos.slice(0, 10).map(p => `- ${p.codigo || 'SIN-COD'} | ${p.descripcion || 'Sin descripción'} | Costo: ${this.formatMoney(p.costo || 0)} | Venta: ${this.formatMoney(p.precio_venta || 0)}`).join('\n');
                            alert(`Se cargaron ${nuevos.length} producto(s) nuevo(s):\n${det}`);
                        }
                        this.showFormCompra = false;
                        this.showFinalizeCompraModal = false;
                        this.resetForm();
                        this.currentPage = 1;
                        this.setPeriodo(this.periodoActivo);
                    } else {
                        alert(data.message || 'Error al guardar la compra');
                    }
                } catch (error) {
                    alert('Error de conexión al guardar');
                    console.error(error);
                }
                
                this.savingCompra = false;
            },
            async buscarRubroGasto(idx, query) {
                this.rubroSearchIdx = idx;
                this.showRubrosDropdown = true;
                this.rubroSearching = true;
                try {
                    const res = await fetch(`/modelos/compras_api.php?action=search_gasto_rubros&id_empresa=${this.idEmpresa}&q=${encodeURIComponent(query || '')}`);
                    const data = await res.json();
                    this.rubrosResults = Array.isArray(data.rubros) ? data.rubros : [];
                } catch (e) {
                    console.error('Error buscando rubros:', e);
                    this.rubrosResults = [];
                }
                this.rubroSearching = false;
            },
            selectRubroGasto(idx, rubro) {
                const item = this.form.items[idx];
                item.rubroBuscar = rubro;
                if (!item.descripcion) item.descripcion = rubro;
                this.showRubrosDropdown = false;
                this.rubrosResults = [];
            },
            
            // ===== PRODUCTO ASOCIAR METHODS =====
            async buscarProductoItem(idx, query) {
                this.prodSearchIdx = idx;
                this.showProdDropdown = true;
                const q = (query || '').trim();
                if (q.length < 1) {
                    this.prodSearchResults = [];
                    return;
                }
                this.prodSearching = true;
                try {
                    const res = await fetch(`/public/pos/api/productos.php?action=search&q=${encodeURIComponent(q)}&estado=1`);
                    const data = await res.json();
                    this.prodSearchResults = (data.productos || []).slice(0, 10).map(p => ({
                        id: p.id,
                        codigo: p.codigo,
                        descripcion: p.descripcion,
                        precio: p.precio,
                        precio_compra: p.precio_min || p.precio_compra || 0,
                        tasa_iva: p.tasa_iva
                    }));
                } catch (e) {
                    console.error('Error buscando producto:', e);
                    this.prodSearchResults = [];
                }
                this.prodSearching = false;
            },
            
            selectProductoItem(idx, prod) {
                const item = this.form.items[idx];
                item.productoId = prod.id;
                item.productoCodigo = prod.codigo;
                item.productoBuscar = prod.descripcion;
                if (!item.descripcion) item.descripcion = prod.descripcion;
                if (prod.precio_compra > 0 && item.costo === 0) item.costo = prod.precio_compra;
                if (prod.tasa_iva !== undefined) item.iva = String(prod.tasa_iva);
                item.precioVenta = this.calculateVentaByRecarga(item);
                this.showProdDropdown = false;
                this.prodSearchResults = [];
            },
            
            abrirCrearProducto(idx) {
                this.showProdDropdown = false;
                const item = this.form.items[idx];
                this.nuevoProducto = {
                    codigo: '',
                    nombre: item.descripcion || item.productoBuscar || '',
                    precioCompra: item.costo || 0,
                    precioVenta: 0,
                    iva: item.iva || '10'
                };
                this.prodSearchIdx = idx;
                this.showCrearProducto = true;
            },
            
            async guardarNuevoProducto() {
                if (!this.nuevoProducto.nombre.trim()) {
                    alert('Ingresá el nombre del producto');
                    return;
                }
                this.creandoProducto = true;
                try {
                    const res = await fetch('/public/compras/api/producto_crear.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            codigo: this.nuevoProducto.codigo,
                            nombre: this.nuevoProducto.nombre,
                            precio_compra: this.nuevoProducto.precioCompra,
                            precio_venta: this.nuevoProducto.precioVenta,
                            iva: this.nuevoProducto.iva
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        // Asociar al item
                        const idx = this.prodSearchIdx;
                        const item = this.form.items[idx];
                        item.productoId = data.producto.id;
                        item.productoCodigo = data.producto.codigo;
                        item.productoBuscar = data.producto.nombre;
                        if (!item.descripcion) item.descripcion = data.producto.nombre;
                        this.showCrearProducto = false;
                    } else {
                        alert(data.message || 'Error al crear producto');
                    }
                } catch (e) {
                    console.error('Error creando producto:', e);
                    alert('Error de conexión al crear producto');
                }
                this.creandoProducto = false;
            },
            
            // ===== WEBCAM METHODS =====
            async openWebcam() {
                this.showWebcam = true;
                this.webcamCaptured = false;
                this.webcamLoading = true;
                
                await this.$nextTick();
                
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({
                        video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } }
                    });
                    this.webcamStream = stream;
                    this.$refs.webcamVideo.srcObject = stream;
                    this.webcamLoading = false;
                } catch (e) {
                    console.error('Webcam error:', e);
                    this.closeWebcam();
                    this.ocrError = 'No se pudo acceder a la cámara. Verificá los permisos.';
                }
            },
            
            closeWebcam() {
                if (this.webcamStream) {
                    this.webcamStream.getTracks().forEach(t => t.stop());
                    this.webcamStream = null;
                }
                this.showWebcam = false;
                this.webcamCaptured = false;
                this.webcamLoading = false;
            },
            
            captureWebcam() {
                const video = this.$refs.webcamVideo;
                const canvas = this.$refs.webcamCanvas;
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                canvas.getContext('2d').drawImage(video, 0, 0);
                canvas.style.display = 'block';
                this.webcamCaptured = true;
            },
            
            async useWebcamCapture() {
                const canvas = this.$refs.webcamCanvas;
                const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', 0.92));
                const file = new File([blob], 'webcam_capture.jpg', { type: 'image/jpeg' });
                this.closeWebcam();
                this.processImageOcr(file);
            },
            
            // ===== OCR METHODS =====
            beginOcrRequest() {
                this.ocrCancelled = false;
                const controller = new AbortController();
                this.ocrAbortController = controller;
                return controller;
            },

            clearOcrRequest(controller) {
                if (this.ocrAbortController === controller) this.ocrAbortController = null;
            },
            handleOcrHttpError(response) {
                if (!response || response.ok) return false;
                if (response.status === 413) {
                    this.ocrError = 'El archivo es demasiado grande para OCR. Reducilo o subí una imagen comprimida.';
                    return true;
                }
                if (response.status === 504) {
                    this.ocrError = 'OCR demoró demasiado y agotó el tiempo de espera. Reintentá con una imagen más liviana.';
                    return true;
                }
                this.ocrError = `OCR devolvió HTTP ${response.status}`;
                return true;
            },
            isAiOcrMode() {
                return this.ocrMode === 'ai_cloud' || this.ocrMode === 'ai_local' || this.ocrMode === 'google_vision';
            },
            getOcrEngine() {
                if (this.ocrMode === 'google_vision') return 'google_vision';
                if (this.ocrMode === 'ai_local') return 'ollama';
                if (this.ocrMode === 'ai_cloud') return 'openai';
                return '';
            },
            getOcrModeDescription() {
                return 'Google Vision OCR: motor principal para fotos, PDFs y documentos complejos.';
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
                const isAiMode = this.isAiOcrMode();
                const threshold = isAiMode ? 178 : 168;

                for (let i = 0, p = 0; i < data.length; i += 4, p++) {
                    let lum = ((luminances[p] - minLum) * 255) / span;
                    lum = lum < threshold
                        ? Math.max(0, lum * (isAiMode ? 0.88 : 0.72))
                        : Math.min(255, 255 - ((255 - lum) * (isAiMode ? 0.55 : 0.35)));

                    if (!isAiMode) {
                        lum = lum >= threshold ? 255 : Math.max(0, lum * 0.82);
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
                const darkCutoff = this.isAiOcrMode() ? 212 : 228;

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
                if (croppedWidth < width * 0.45 || croppedHeight < height * 0.45) {
                    return canvas;
                }

                const padX = Math.max(12, Math.round(croppedWidth * 0.03));
                const padY = Math.max(12, Math.round(croppedHeight * 0.03));
                left = Math.max(0, left - padX);
                top = Math.max(0, top - padY);
                right = Math.min(width - 1, right + padX);
                bottom = Math.min(height - 1, bottom + padY);

                const outWidth = right - left + 1;
                const outHeight = bottom - top + 1;
                if (outWidth <= 0 || outHeight <= 0) {
                    return canvas;
                }

                const outCanvas = document.createElement('canvas');
                outCanvas.width = outWidth;
                outCanvas.height = outHeight;
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
                        if (lum < 208) {
                            row += (255 - lum) / 255;
                        }
                    }
                    rowHits[y] = row;
                    totalInk += row;
                }

                if (totalInk <= 0) return 0;
                let score = 0;
                for (let y = 0; y < height; y++) {
                    score += rowHits[y] * rowHits[y];
                }
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

                if (Math.abs(bestAngle) < 0.5) {
                    return canvas;
                }

                return this.rotateCanvas(canvas, bestAngle);
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
                if (deskewCtx) {
                    this.preprocessCanvasForOcr(deskewedCanvas, deskewCtx);
                }
                const finalCanvas = this.cropCanvasToDocument(deskewedCanvas);
                const qualities = this.isAiOcrMode() ? [0.9, 0.82, 0.75, 0.68] : [0.82, 0.72, 0.62];
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
                this.processOcrFile(file);
            },

            async pasteFromClipboard() {
                this.ocrError = '';
                try {
                    if (!navigator.clipboard || !navigator.clipboard.read) {
                        this.ocrError = 'Tu navegador no permite pegar por botón. Usá Ctrl+V en esta ventana.';
                        return;
                    }
                    const clipboardItems = await navigator.clipboard.read();
                    for (const item of clipboardItems) {
                        const imageType = (item.types || []).find(t => t.startsWith('image/'));
                        if (!imageType) continue;
                        const blob = await item.getType(imageType);
                        const ext = (imageType.split('/')[1] || 'png').split('+')[0];
                        const file = new File([blob], `clipboard_${Date.now()}.${ext}`, { type: imageType });
                        await this.processOcrFile(file);
                        return;
                    }
                    this.ocrError = 'El portapapeles no contiene una imagen.';
                } catch (error) {
                    this.ocrError = 'No se pudo leer el portapapeles. Permití acceso e intentá de nuevo.';
                }
            },

            handleOcrDrop(event) {
                const files = event.dataTransfer.files;
                if (files.length > 0) this.processOcrFile(files[0]);
            },
            
            handleOcrFile(event) {
                const file = event.target.files[0];
                if (file) this.processOcrFile(file);
                event.target.value = ''; // reset input
            },
            
            handleScannerFile(event) {
                const file = event.target.files[0];
                if (!file) return;
                event.target.value = '';
                this.processOcrFile(file);
            },
            
            async processOcrFile(file) {
                this.ocrError = '';
                this.ocrSuccess = false;
                this.ocrFileName = file.name;
                this.ocrCancelled = false;
                
                const ext = file.name.split('.').pop().toLowerCase();
                
                if (['xlsx', 'xls', 'csv'].includes(ext)) {
                    await this.processExcel(file);
                } else if (ext === 'pdf') {
                    await this.processPdfOcr(file);
                } else if (['jpg', 'jpeg', 'png', 'webp', 'bmp', 'gif'].includes(ext)) {
                    await this.processImageOcr(file);
                } else {
                    this.ocrError = 'Formato no soportado. Usa imagen, PDF o Excel.';
                }
            },

            async processPdfOcr(file) {
                this.ocrProcessing = true;
                this.ocrProgress = 15;
                this.ocrStatusMsg = 'Leyendo texto del PDF...';

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
                    this.ocrProgress = 20;
                    this.ocrStatusMsg = 'Convirtiendo PDF a imagen...';
                    if (!window.pdfjsLib || !window.pdfjsLib.getDocument) {
                        throw new Error('pdf.js no disponible');
                    }

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
                    const formData = new FormData();
                    formData.append('pdf', file);
                    formData.append('id_empresa', this.idEmpresa);
                    formData.append('ai_fallback', this.isAiOcrMode() ? '1' : '0');
                    formData.append('ocr_precision', this.isAiOcrMode() ? 'max' : 'fast');
                    if (this.getOcrEngine()) formData.append('engine', this.getOcrEngine());
                    const controller = this.beginOcrRequest();
                    const response = await fetch('/modelos/ocr_factura_api.php', { method: 'POST', body: formData, signal: controller.signal });
                    this.clearOcrRequest(controller);
                    if (!response.ok) {
                        if (response.status === 413) this.ocrError = 'PDF muy pesado para OCR directo. Intentando modo imagen...';
                        if (response.status === 504) this.ocrError = 'OCR directo demoró demasiado. Intentando modo imagen...';
                        return false;
                    }
                    const data = await response.json();
                    if (data.success && data.data && Array.isArray(data.data.items) && data.data.items.length > 0) {
                        const bad = data.data.items.filter(it => {
                            const d = String(it?.descripcion || '').trim().toLowerCase();
                            return /^(tel[eé]fono|ciudad|cdc|cliente|ruc|correo|p[aá]g\.)/.test(d);
                        }).length;
                        if (bad > 0 && bad >= Math.ceil(data.data.items.length * 0.35)) {
                            return false;
                        }
                        if (!this.applyOcrData(data.data)) {
                            return false;
                        }
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
                this.ocrProcessing = true;
                this.ocrProgress = 10;
                this.ocrStatusMsg = 'Subiendo imagen...';
                
                try {
                    const optimizedFile = await this.optimizeImageForOcr(file);
                    const formData = new FormData();
                    formData.append('imagen', optimizedFile);
                    formData.append('id_empresa', this.idEmpresa);
                    formData.append('ocr_source_name', sourceFile ? sourceFile.name : file.name);
                    formData.append('ocr_source_size', String(sourceFile ? sourceFile.size : file.size));
                    formData.append('ocr_upload_name', optimizedFile.name);
                    formData.append('ocr_upload_size', String(optimizedFile.size));
                    formData.append('ai_fallback', this.isAiOcrMode() ? '1' : '0');
                    formData.append('ocr_precision', this.isAiOcrMode() ? 'max' : 'fast');
                    if (this.getOcrEngine()) formData.append('engine', this.getOcrEngine());
                    
                    this.ocrProgress = 30;
                    this.ocrStatusMsg = this.ocrMode === 'ai_cloud'
                        ? 'OCR IA Cloud analizando factura...'
                        : (this.ocrMode === 'ai_local'
                            ? 'OCR IA Local (Ollama) analizando factura...'
                            : (this.ocrMode === 'google_vision'
                                ? 'Google Vision OCR analizando factura...'
                                : 'OCR rápido analizando factura...'));
                    
                    const controller = this.beginOcrRequest();
                    const response = await fetch('/modelos/ocr_factura_api.php', {
                        method: 'POST',
                        body: formData,
                        signal: controller.signal
                    });
                    this.clearOcrRequest(controller);
                    if (this.handleOcrHttpError(response)) {
                        this.ocrProcessing = false;
                        return;
                    }
                    
                    this.ocrProgress = 80;
                    this.ocrStatusMsg = 'Extrayendo datos...';
                    
                    const data = await response.json();
                    
                    if (data.success && data.data) {
                        if (!this.applyOcrData(data.data)) {
                            this.ocrProcessing = false;
                            return;
                        }
                        this.ocrProgress = 100;
                        this.ocrSuccess = true;
                    } else {
                        this.ocrError = data.message || 'No se pudo procesar la imagen';
                    }
                } catch (e) {
                    if (e && e.name === 'AbortError') {
                        this.ocrError = 'OCR cancelado por el usuario.';
                        this.ocrProcessing = false;
                        return;
                    }
                    console.error('OCR Error:', e);
                    this.ocrError = 'Error de conexión al procesar';
                }
                
                this.ocrProcessing = false;
            },
            
            async processExcel(file) {
                this.ocrProcessing = true;
                this.ocrProgress = 20;
                this.ocrStatusMsg = 'Leyendo archivo Excel...';
                
                try {
                    const arrayBuffer = await file.arrayBuffer();
                    const workbook = XLSX.read(arrayBuffer, { type: 'array', cellDates: true });
                    const sheet = workbook.Sheets[workbook.SheetNames[0]];
                    const rows = XLSX.utils.sheet_to_json(sheet, { header: 1 });
                    
                    this.ocrProgress = 60;
                    this.ocrStatusMsg = 'Interpretando datos...';
                    
                    if (rows.length < 2) {
                        this.ocrError = 'El archivo está vacío o no tiene datos suficientes';
                        this.ocrProcessing = false;
                        return;
                    }
                    
                    // Detectar columnas por encabezados
                    const headers = rows[0].map(h => String(h || '').toLowerCase().trim());
                    const colMap = this.detectExcelColumns(headers);
                    
                    // Extraer items
                    const items = [];
                    for (let i = 1; i < rows.length; i++) {
                        const row = rows[i];
                        if (!row || row.length === 0) continue;
                        
                        const desc = colMap.descripcion !== -1 ? String(row[colMap.descripcion] || '') : '';
                        const codigoProveedor = colMap.codigo !== -1 ? String(row[colMap.codigo] ?? '').trim() : '';
                        const cant = colMap.cantidad !== -1 ? parseFloat(row[colMap.cantidad]) || 0 : 1;
                        const costo = colMap.costo !== -1 ? parseFloat(row[colMap.costo]) || 0 : 0;
                        let iva = '10';
                        if (colMap.iva !== -1) {
                            const ivaVal = parseFloat(row[colMap.iva]) || 10;
                            iva = ivaVal <= 0 ? '0' : ivaVal <= 5 ? '5' : '10';
                        }
                        
                        if (desc && (cant > 0 || costo > 0)) {
                            items.push({ descripcion: desc.substring(0, 200), codigoProveedor, cantidad: cant || 1, costo: parseFloat((costo || 0).toFixed(2)), precioVenta: 0, iva });
                        }
                    }
                    
                    this.ocrProgress = 90;
                    
                    if (items.length === 0) {
                        this.ocrError = 'No se encontraron productos en el Excel. Asegurate que tenga columnas: Descripción, Cantidad, Costo/Precio';
                        this.ocrProcessing = false;
                        return;
                    }
                    
                    // Buscar datos de cabecera en el Excel (proveedor, nro factura, etc)
                    const excelData = {
                        proveedor: '',
                        ruc: '',
                        nro_factura: '',
                        timbrado: '',
                        fecha: this.localDateStr(new Date()),
                        total: items.reduce((s, i) => s + (i.cantidad * i.costo), 0),
                        items
                    };
                    
                    // Try to detect header info from named columns
                    if (colMap.nro_factura !== -1 && rows[1]) excelData.nro_factura = String(rows[1][colMap.nro_factura] || '');
                    if (colMap.proveedor !== -1 && rows[1]) excelData.proveedor = String(rows[1][colMap.proveedor] || '');
                    if (colMap.ruc !== -1 && rows[1]) excelData.ruc = String(rows[1][colMap.ruc] || '');
                    if (colMap.timbrado !== -1 && rows[1]) excelData.timbrado = String(rows[1][colMap.timbrado] || '');
                    
                    if (this.applyOcrData(excelData)) {
                        this.ocrProgress = 100;
                        this.ocrSuccess = true;
                    }
                } catch (e) {
                    console.error('Excel Error:', e);
                    this.ocrError = 'Error al leer el archivo Excel';
                }
                
                this.ocrProcessing = false;
            },
            
            detectExcelColumns(headers) {
                const map = { descripcion: -1, codigo: -1, cantidad: -1, costo: -1, iva: -1, nro_factura: -1, proveedor: -1, ruc: -1, timbrado: -1 };
                
                const patterns = {
                    descripcion: /descrip|producto|detalle|concepto|articulo|item|nombre/,
                    codigo: /codigo|cod\.?|c[oó]digo|barcode|barra|sku|referencia|ref\.?/,
                    cantidad: /cant|qty|quantity|unid|und/,
                    costo: /costo|precio|monto|importe|valor|price|unit|p\.u|p_u/,
                    iva: /iva|tax|impuesto|tipo.*iva/,
                    nro_factura: /factura|nro|numero|invoice|comprobante/,
                    proveedor: /proveedor|empresa|razon|supplier/,
                    ruc: /ruc|ci|documento|nit|cuit/,
                    timbrado: /timbrado|timb/
                };
                
                headers.forEach((h, idx) => {
                    for (const [key, regex] of Object.entries(patterns)) {
                        if (map[key] === -1 && regex.test(h)) map[key] = idx;
                    }
                });
                
                // Fallback: si no detectó, asumir primera=desc, segunda=cant, tercera=costo
                if (map.descripcion === -1 && headers.length >= 1) map.descripcion = 0;
                if (map.cantidad === -1 && headers.length >= 2) map.cantidad = 1;
                if (map.costo === -1 && headers.length >= 3) map.costo = 2;
                
                return map;
            },

            isSuspiciousOcrItemDescription(desc) {
                const text = String(desc || '').trim();
                if (!text) return true;
                if (!/[a-záéíóúñ]/i.test(text)) return true;
                if (text.length < 4) return true;
                return /^(tel[eé]fono|telefono|ciudad|cliente|ruc|correo|cajero|fecha|timbrado|cdc|p[aá]g\.?|tipo\s+de\s+transacci[oó]n|motivo|actividad|total|subtotal|exenta|gravada|iva|condici[oó]n\s+de\s+venta|vencimiento|documento)\b/i.test(text);
            },

            normalizeOcrItems(items) {
                const seen = new Set();
                return (Array.isArray(items) ? items : []).reduce((acc, item) => {
                    const descripcion = String(item?.descripcion || '').trim();
                    const cantidad = parseFloat(item?.cantidad) || 0;
                    const costo = parseFloat(item?.costo || item?.costo_gs) || 0;
                    if (this.isSuspiciousOcrItemDescription(descripcion) || cantidad <= 0 || costo <= 0) {
                        return acc;
                    }
                    const key = descripcion.toLowerCase().replace(/\s+/g, ' ');
                    if (seen.has(key)) return acc;
                    seen.add(key);
                    acc.push(item);
                    return acc;
                }, []);
            },
            
            applyOcrData(data) {
                const normalizedItems = this.normalizeOcrItems(data?.items || []);
                const headerScore = ['proveedor', 'ruc', 'nro_factura', 'timbrado']
                    .reduce((score, key) => score + (String(data?.[key] || '').trim() ? 1 : 0), 0);

                if ((Array.isArray(data?.items) && data.items.length > 0 && normalizedItems.length === 0) || (normalizedItems.length === 0 && headerScore < 2)) {
                    this.ocrError = 'OCR devolvió datos poco confiables. Probá con una imagen más nítida o usá PDF.';
                    return false;
                }

                // Llenar cabecera
                if (data.nro_factura) this.form.nroFactura = data.nro_factura;
                if (data.timbrado) this.form.timbrado = data.timbrado;
                if (data.fecha) this.form.fecha = data.fecha;
                
                // Llenar proveedor (buscar si hay nombre/ruc)
                if (data.proveedor) {
                    this.form.proveedorBuscar = data.proveedor;
                    this.form.proveedorNombre = data.proveedor;
                    this.form.proveedorRuc = data.ruc || '';
                    // Auto-buscar proveedor en BD
                    if (data.ruc) {
                        this.form.proveedorBuscar = data.ruc;
                        this.buscarProveedor().then(() => {
                            // Si hay match exacto por RUC, seleccionar
                            const match = this.proveedoresResults.find(p => 
                                p.numero && p.numero.replace(/-/g, '').includes(data.ruc.replace(/-/g, ''))
                            );
                            if (match) {
                                this.selectProveedor(match);
                            } else {
                                this.form.proveedorBuscar = data.proveedor;
                            }
                        });
                    }
                }
                
                // Llenar items
                if (normalizedItems.length > 0) {
                    this.form.items = normalizedItems.map(item => {
                        let iva = '10';
                        if (item.tipo_iva === 1 || item.tipo_iva === 0) iva = '0';
                        else if (item.tipo_iva === 2 || item.tipo_iva === 5) iva = '5';
                        else if (item.iva !== undefined) iva = String(item.iva);
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
                            descripcion: item.descripcion || '',
                            rubroBuscar: item.rubro || item.descripcion || '',
                            codigoProveedor,
                            cantidad: parseFloat(item.cantidad) || 1,
                            costo: parseFloat((parseFloat(item.costo || item.costo_gs) || 0).toFixed(2)),
                            precioVenta: Math.round(parseFloat(item.venta_ocr || item.precio_venta || item.precioVenta) || 0),
                            iva: iva,
                            productoId: null,
                            productoCodigo: '',
                            productoBuscar: ''
                        };
                    });
                    this.recalculatePricingAll();
                }
                return true;
            },
            
            clearFilters() {
                this.searchQuery = '';
                this.estadoFiltro = 'activo';
                this.setPeriodo('hoy');
            },
            
            prevPage() {
                if (this.currentPage > 1) {
                    this.currentPage--;
                    this.loadCompras();
                }
            },
            
            nextPage() {
                if (this.currentPage < this.totalPages) {
                    this.currentPage++;
                    this.loadCompras();
                }
            },

            setSort(field) {
                if (this.sortBy === field) {
                    this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    this.sortBy = field;
                    this.sortDir = field === 'fecha' ? 'desc' : 'asc';
                }
            },

            sortIcon(field) {
                if (this.sortBy !== field) return '↕';
                return this.sortDir === 'asc' ? '↑' : '↓';
            },

            sortValue(compra, field) {
                switch (field) {
                    case 'nro_factura':
                        return (compra.nro_factura || '').toString().toLowerCase();
                    case 'fecha':
                        return new Date(compra.fecha || 0).getTime();
                    case 'proveedor':
                        return (compra.proveedor_nombre || '').toString().toLowerCase();
                    case 'total':
                        return Number(compra.total || 0);
                    case 'pendiente':
                        return Number(compra.pendiente || 0);
                    case 'estado':
                        return Number(compra.estado || 0);
                    default:
                        return '';
                }
            },

            sortedCompras() {
                const rows = Array.isArray(this.compras) ? [...this.compras] : [];
                const field = this.sortBy;
                const dir = this.sortDir === 'asc' ? 1 : -1;
                return rows.sort((a, b) => {
                    const av = this.sortValue(a, field);
                    const bv = this.sortValue(b, field);
                    if (av < bv) return -1 * dir;
                    if (av > bv) return 1 * dir;
                    return 0;
                });
            },
            
            toggleTheme() {
                this.isDark = true;
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
            },
            
            formatMoney(amount) {
                return new Intl.NumberFormat('es-PY', { maximumFractionDigits: 0 }).format(amount || 0);
            },
            
            formatDate(dateStr) {
                if (!dateStr) return '-';
                return new Date(dateStr).toLocaleDateString('es-PY');
            },
            
            formatTime(dateStr) {
                if (!dateStr) return '';
                return new Date(dateStr).toLocaleTimeString('es-PY', { hour: '2-digit', minute: '2-digit' });
            },
            
            formatDateTime(dateStr) {
                if (!dateStr) return '-';
                return new Date(dateStr).toLocaleString('es-PY');
            },
            
            getFormaPago(tipo) {
                const key = String(tipo ?? '').trim().toLowerCase();
                const formas = {
                    '1': 'Contado',
                    '2': 'Crédito',
                    '3': 'Transferencia',
                    '4': 'Cheque',
                    'efectivo': 'Efectivo',
                    'tarjeta': 'Tarjeta',
                    'transferencia': 'Transferencia',
                    'pix': 'QR',
                    'qr': 'QR',
                    'credito': 'Crédito',
                    'crédito': 'Crédito'
                };
                return formas[key] || 'Contado';
            }
        }
    }
    </script>
</body>
</html>
