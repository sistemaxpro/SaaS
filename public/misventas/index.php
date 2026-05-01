<?php
/**
 * Listado de Ventas - Desktop
 * Vista principal con filtros, búsqueda y acciones
 */

// Detección de móvil y redirección automática
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = preg_match('/Mobile|Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $userAgent);

// Redirigir automáticamente a la versión móvil cuando corresponda.
if ($isMobile) {
    header('Location: /public/pos/ventas_mobile.php');
    exit;
}

require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

Permission::requireAccess('app_grid_factura_venta_global');
$permisos = Permission::getAppPermissions('app_grid_factura_venta_global');

$id_empresa = (int)Session::get('id_empresa');
$id_login = Session::get('id_login');

if ($id_empresa <= 0) {
    http_response_code(403);
    echo "Empresa no definida en sesión.";
    exit;
}

// Obtener datos de la empresa desde master
$masterPdo = Database::getMasterConnection();
$stmtEmpresa = $masterPdo->prepare("SELECT * FROM " . MASTER_DB . ".empresa WHERE id_empresa = ?");
$stmtEmpresa->execute([$id_empresa]);
$empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

$id_caja = (int)Session::get('id_caja_def', 0);
if ($id_caja <= 0) {
    $stmtUser = $masterPdo->prepare("SELECT caja_def FROM " . MASTER_DB . ".sec_users WHERE id_login = ? LIMIT 1");
    $stmtUser->execute([(int)$id_login]);
    $id_caja = (int)$stmtUser->fetchColumn();
}

$impresora_caja = '';
if (!empty($empresa['dbase']) && $id_caja > 0) {
    try {
        $stmtImp = $masterPdo->prepare("SELECT impresora FROM {$empresa['dbase']}.cajas WHERE id_caja = :id_caja AND id_empresa = :id_empresa LIMIT 1");
        $stmtImp->execute([
            ':id_caja' => $id_caja,
            ':id_empresa' => $id_empresa,
        ]);
        $impresora_caja = (string)($stmtImp->fetchColumn() ?: '');
    } catch (Throwable $e) {
        try {
            $stmtImp = $masterPdo->prepare("SELECT impresora FROM {$empresa['dbase']}.cajas WHERE id_caja = :id_caja LIMIT 1");
            $stmtImp->execute([':id_caja' => $id_caja]);
            $impresora_caja = (string)($stmtImp->fetchColumn() ?: '');
        } catch (Throwable $e2) {
            $impresora_caja = '';
        }
    }
}

$pageTitle = 'Mis Ventas';
?>
<!DOCTYPE html>
<html lang="es" x-data="ventasApp()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - SistemaX</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="/public/pos/js/smx-printer.js?v=3"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
        // Detectar tema
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }

    </script>
    
    <style>
        [x-cloak] { display: none !important; }
        .table-row:hover { background: rgba(59, 130, 246, 0.05); }
        .dark .table-row:hover { background: rgba(59, 130, 246, 0.1); }

        .discreet-scroll {
            scrollbar-width: thin;
            scrollbar-color: rgba(100, 116, 139, 0.35) transparent;
        }
        .discreet-scroll::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .discreet-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .discreet-scroll::-webkit-scrollbar-thumb {
            background: rgba(100, 116, 139, 0.28);
            border-radius: 9999px;
            border: 2px solid transparent;
            background-clip: content-box;
        }
        .discreet-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(100, 116, 139, 0.45);
            background-clip: content-box;
        }
        .dark .discreet-scroll {
            scrollbar-color: rgba(148, 163, 184, 0.35) transparent;
        }
        .dark .discreet-scroll::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.3);
            background-clip: content-box;
        }
        .dark .discreet-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.45);
            background-clip: content-box;
        }
        .ag-theme-quartz,
        .ag-theme-quartz-dark {
            --ag-font-family: Inter, sans-serif;
            --ag-font-size: 13px;
            --ag-border-color: rgba(148, 163, 184, 0.18);
            --ag-row-border-color: rgba(148, 163, 184, 0.14);
            --ag-header-background-color: rgba(249, 250, 251, 0.96);
            --ag-odd-row-background-color: transparent;
            --ag-background-color: transparent;
            --ag-wrapper-border-radius: 0;
            --ag-row-hover-color: rgba(59, 130, 246, 0.08);
            --ag-side-button-selected-background-color: rgba(59, 130, 246, 0.16);
        }
        .ag-theme-quartz-dark {
            --ag-border-color: rgba(71, 85, 105, 0.5);
            --ag-row-border-color: rgba(71, 85, 105, 0.35);
            --ag-header-background-color: rgba(30, 41, 59, 0.92);
            --ag-background-color: transparent;
            --ag-foreground-color: rgb(226, 232, 240);
            --ag-secondary-foreground-color: rgb(148, 163, 184);
            --ag-row-hover-color: rgba(59, 130, 246, 0.12);
            --ag-side-button-selected-background-color: rgba(59, 130, 246, 0.2);
        }
        .ventas-grid {
            width: 100%;
            height: 100%;
            min-height: 420px;
        }
        .venta-cell-stack {
            display: flex;
            flex-direction: column;
            justify-content: center;
            line-height: 1.2;
            min-height: 100%;
            padding: 6px 0;
        }
        .venta-cell-stack .main {
            font-weight: 600;
            color: rgb(15 23 42);
        }
        .dark .venta-cell-stack .main {
            color: rgb(248 250 252);
        }
        .venta-cell-stack .meta {
            font-size: 11px;
            color: rgb(100 116 139);
        }
        .dark .venta-cell-stack .meta {
            color: rgb(148 163 184);
        }
        .venta-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            padding: 2px 10px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 6px;
        }
        .venta-badge.doc-fe { background: rgba(59, 130, 246, 0.16); color: rgb(37, 99, 235); }
        .venta-badge.doc-auto { background: rgba(249, 115, 22, 0.16); color: rgb(194, 65, 12); }
        .venta-badge.doc-nc { background: rgba(168, 85, 247, 0.16); color: rgb(126, 34, 206); }
        .venta-badge.sifen-ok { background: rgba(34, 197, 94, 0.14); color: rgb(21, 128, 61); }
        .venta-badge.sifen-pend { background: rgba(139, 92, 246, 0.14); color: rgb(124, 58, 237); }
        .venta-badge.sifen-rej { background: rgba(239, 68, 68, 0.14); color: rgb(185, 28, 28); }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-900 h-screen overflow-hidden font-sans flex flex-col">
<script>window.__PERMISOS__ = <?= json_encode($permisos) ?>;</script>
    
    <!-- Header -->
    <header class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 sticky top-0 z-40">
        <div class="w-full px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo y Título -->
                <div class="flex items-center gap-4">
                    <button onclick="window.location.href='/public/pos/index.php';" 
                            class="w-10 h-10 rounded-lg bg-red-600/10 border border-red-500/40 flex items-center justify-center text-red-600 dark:text-red-400 hover:bg-red-600/20 active:scale-95 transition-all cursor-pointer"
                            title="Salir">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15m-3 0-3-3m0 0 3-3m-3 3H15" />
                        </svg>
                    </button>
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 dark:text-white">Mis Ventas</h1>
                        <p class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($empresa['empresa'] ?? 'Empresa') ?></p>
                    </div>
                </div>
                
                <!-- Acciones Header -->
                <div class="flex items-center gap-3">
                    <button
                        @click="retryPrintConnect()"
                        :disabled="qzCheckingConnection"
                        class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border text-xs font-bold transition-colors"
                        :class="qzConnected
                            ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:border-emerald-700'
                            : 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-900/20 dark:text-rose-300 dark:border-rose-700'"
                        :title="qzConnected
                            ? ('Impresión conectada (' + printProvider + ')' + (printerName ? ' - ' + printerName : ''))
                            : 'Impresión desconectada. Click para reconectar'">
                        <span class="w-2.5 h-2.5 rounded-full border border-white/30"
                              :class="qzConnected ? 'bg-emerald-500 animate-pulse' : 'bg-rose-500'"></span>
                        <span x-show="!qzCheckingConnection" x-text="qzConnected ? ('Online (' + printProvider + ')') : 'Offline'"></span>
                        <span x-show="qzCheckingConnection">Conectando...</span>
                    </button>

                </div>
            </div>
        </div>
    </header>
    
    <!-- Filtros -->
    <div class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700">
        <div class="w-full px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex flex-wrap items-center gap-4">
                <!-- Búsqueda -->
                <div class="flex-1 min-w-[200px]">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" 
                               x-model="searchQuery"
                               @input.debounce.300ms="loadVentas()"
                               placeholder="Buscar por nro, cliente..."
                               class="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                
                <!-- Filtros de Período -->
                <div class="flex items-center gap-1 bg-gray-100 dark:bg-slate-700 rounded-lg p-1">
                    <template x-for="p in periodos" :key="p.key">
                        <button type="button"
                                @click="setPeriodo(p.key)"
                                :aria-pressed="isPeriodoActivo(p.key) ? 'true' : 'false'"
                                :class="periodoButtonClass(p.key)"
                                class="px-3 py-1.5 rounded-md text-sm font-medium transition-all whitespace-nowrap"
                                x-text="p.label">
                        </button>
                    </template>
                </div>

                <!-- Fechas personalizadas (solo visible con período 'custom') -->
                <template x-if="periodoActivo === 'custom'">
                    <div class="flex items-center gap-2">
                        <input type="date" 
                               x-model="fechaDesde"
                               @change="loadVentas()"
                               class="w-36 px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500">
                        <span class="text-gray-400 text-sm">a</span>
                        <input type="date" 
                               x-model="fechaHasta"
                               @change="loadVentas()"
                               class="w-36 px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-blue-500">
                    </div>
                </template>
                
                <!-- Estado -->
                <div class="w-40">
                    <select x-model="estadoFiltro" 
                            @change="loadVentas()"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500">
                        <option value="">Todos</option>
                        <option value="activo">Activos</option>
                        <option value="anulado">Anulados</option>
                    </select>
                </div>
                
                <!-- Botón Limpiar -->
                <button @click="clearFilters()" 
                        class="px-3 py-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                    <i class="fas fa-times"></i>
                </button>

                <!-- Exportar Excel -->
                <button x-show="permisos.priv_export === 'Y'"
                        @click="exportExcel()"
                        class="px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium transition-colors flex items-center gap-2">
                    <i class="fas fa-file-excel"></i>
                    <span>Exportar Excel</span>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Stats Cards -->
    <div class="w-full px-4 sm:px-6 lg:px-8 py-6 flex-1 min-h-0 flex flex-col">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <!-- Total Ventas -->
            <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                        <i class="fas fa-receipt text-blue-600 dark:text-blue-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Total Ventas</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white" x-text="stats.totalVentas"></p>
                    </div>
                </div>
            </div>
            
            <!-- Monto Total -->
            <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-green-600 dark:text-green-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Monto Total</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white" x-text="formatMoney(stats.montoTotal) + ' Gs'"></p>
                    </div>
                </div>
            </div>
            
            <!-- Ventas Hoy -->
            <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                        <i class="fas fa-calendar-day text-purple-600 dark:text-purple-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Hoy</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white" x-text="stats.ventasHoy"></p>
                    </div>
                </div>
            </div>
            
            <!-- Promedio -->
            <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-gray-200 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                        <i class="fas fa-chart-line text-amber-600 dark:text-amber-400 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Promedio</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white" x-text="formatMoney(stats.promedio) + ' Gs'"></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabla de Ventas -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 flex-1 min-h-0 flex flex-col overflow-visible">
            <!-- Loading -->
            <div x-show="loading" class="p-8 text-center flex-1 flex flex-col items-center justify-center">
                <i class="fas fa-spinner fa-spin text-3xl text-blue-500 mb-3"></i>
                <p class="text-gray-500 dark:text-gray-400">Cargando ventas...</p>
            </div>
            
            <!-- Grid -->
            <div x-show="!loading" x-cloak class="flex-1 min-h-0 p-2">
                <div class="px-1 pb-2 text-xs text-slate-500 dark:text-slate-400">
                    Acciones de lista deprecadas. Use clic derecho sobre una fila para ver opciones.
                </div>
                <div x-ref="ventasGrid"
                     class="ventas-grid"
                     :class="isDark ? 'ag-theme-quartz-dark' : 'ag-theme-quartz'"></div>
            </div>
            
            <!-- Paginación -->
            <div x-show="totalPages > 1" class="px-4 py-3 bg-gray-50 dark:bg-slate-700/50 border-t border-gray-200 dark:border-slate-700 flex items-center justify-between">
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    Mostrando <span x-text="((currentPage - 1) * perPage) + 1"></span> - <span x-text="Math.min(currentPage * perPage, totalRecords)"></span> de <span x-text="totalRecords"></span>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="prevPage()" 
                            :disabled="currentPage === 1"
                            :class="currentPage === 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-200 dark:hover:bg-slate-600'"
                            class="px-3 py-1 rounded-lg bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-300">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span class="px-3 py-1 text-sm text-gray-600 dark:text-gray-300">
                        Página <span x-text="currentPage"></span> de <span x-text="totalPages"></span>
                    </span>
                    <button @click="nextPage()" 
                            :disabled="currentPage === totalPages"
                            :class="currentPage === totalPages ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-200 dark:hover:bg-slate-600'"
                            class="px-3 py-1 rounded-lg bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-300">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Detalle Venta -->
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
            <!-- Backdrop -->
            <div class="fixed inset-0 bg-black/60" @click="showModal = false"></div>
            
            <!-- Modal Content -->
            <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-xl max-w-2xl w-full mx-4 overflow-hidden"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-4"
                 x-transition:enter-end="opacity-100 translate-y-0">
                
                <!-- Header -->
                <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Detalle de Venta</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400" x-text="selectedVenta?.nro_factura"></p>
                    </div>
                    <button @click="showModal = false" class="w-10 h-10 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center justify-center text-gray-500">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <!-- Body -->
                <div class="px-6 py-4 max-h-[60vh] overflow-y-auto">
                    <template x-if="selectedVenta">
                        <div class="space-y-4">
                            <!-- Info General -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Cliente</p>
                                    <p class="font-medium text-gray-900 dark:text-white" x-text="selectedVenta.cliente_nombre || 'Consumidor Final'"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Fecha</p>
                                    <p class="font-medium text-gray-900 dark:text-white" x-text="formatDateTime(selectedVenta.fecha)"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Forma de Pago</p>
                                    <p class="font-medium text-gray-900 dark:text-white" x-text="getFormaPago(selectedVenta.forma_pago)"></p>
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
                                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Cant</th>
                                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Precio</th>
                                                <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                                            <template x-for="item in ventaItems" :key="item.id">
                                                <tr>
                                                    <td class="px-3 py-2 text-gray-900 dark:text-white" x-text="item.descripcion"></td>
                                                    <td class="px-3 py-2 text-right text-gray-600 dark:text-gray-300" x-text="item.cantidad"></td>
                                                    <td class="px-3 py-2 text-right text-gray-600 dark:text-gray-300" x-text="formatMoney(item.precio)"></td>
                                                    <td class="px-3 py-2 text-right font-medium text-gray-900 dark:text-white" x-text="formatMoney(item.subtotal)"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Total -->
                            <div class="flex justify-end">
                                <div class="bg-gray-50 dark:bg-slate-700/50 rounded-lg px-6 py-3 text-right">
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Total</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white" x-text="formatMoney(selectedVenta.total) + ' Gs'"></p>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                
                <!-- Footer -->
                <div class="px-6 py-4 bg-gray-50 dark:bg-slate-700/50 border-t border-gray-200 dark:border-slate-700 flex justify-end gap-3">
                    <button @click="imprimirVenta(selectedVenta)" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-gray-700 dark:text-gray-300 rounded-lg font-medium transition-colors">
                        <i class="fas fa-print mr-2"></i> Imprimir
                    </button>
                    <button @click="showModal = false" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmación -->
    <div x-show="confirmState.open"
         x-cloak
         class="fixed inset-0 z-[70] flex items-center justify-center p-4"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="resolveConfirm(false)"></div>
        <div class="relative w-full max-w-sm rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 shadow-xl p-5">
            <h4 class="text-base font-semibold text-slate-900 dark:text-white" x-text="confirmState.title"></h4>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300" x-text="confirmState.message"></p>
            <div class="mt-5 flex items-center justify-end gap-2">
                <button @click="resolveConfirm(false)"
                        class="px-3 py-2 rounded-lg text-sm font-medium text-slate-700 dark:text-slate-200 bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 transition-colors">
                    Cancelar
                </button>
                <button @click="resolveConfirm(true)"
                        :class="confirmState.tone === 'danger' ? 'bg-red-600 hover:bg-red-700 text-white' : 'bg-blue-600 hover:bg-blue-700 text-white'"
                        class="px-3 py-2 rounded-lg text-sm font-medium transition-colors"
                        x-text="confirmState.confirmText">
                </button>
            </div>
        </div>
    </div>

    <!-- Aviso centrado (SIFEN) -->
    <div x-show="noticeState.open"
         x-cloak
         class="fixed inset-0 z-[75] flex items-center justify-center p-4"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="closeNotice()"></div>
        <div class="relative w-full max-w-md rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 shadow-xl p-5 text-center">
            <h4 class="text-base font-semibold text-slate-900 dark:text-white" x-text="noticeState.title"></h4>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300 whitespace-pre-wrap text-left select-text break-words" x-text="noticeState.message"></p>
            <div class="mt-5 flex items-center justify-center gap-2">
                <button @click="copyNoticeMessage()"
                        class="px-4 py-2 rounded-lg text-sm font-medium text-slate-700 dark:text-slate-200 bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 transition-colors">
                    Copiar
                </button>
                <button @click="closeNotice()"
                        :class="noticeState.tone === 'error'
                            ? 'bg-red-600 hover:bg-red-700 text-white'
                            : (noticeState.tone === 'success'
                                ? 'bg-emerald-600 hover:bg-emerald-700 text-white'
                                : 'bg-blue-600 hover:bg-blue-700 text-white')"
                        class="px-5 py-2 rounded-lg text-sm font-medium transition-colors">
                    Aceptar
                </button>
            </div>
        </div>
    </div>

    <!-- Mensaje Toast -->
    <div x-show="toast.show"
         x-cloak
         class="fixed top-4 right-4 z-[80] w-full max-w-sm"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-1">
        <div class="rounded-xl border shadow-lg px-4 py-3 bg-white dark:bg-slate-800"
             :class="toast.type === 'success'
                ? 'border-emerald-200 dark:border-emerald-800'
                : (toast.type === 'error'
                    ? 'border-red-200 dark:border-red-800'
                    : 'border-slate-200 dark:border-slate-700')">
            <div class="flex items-start gap-3">
                <div class="mt-0.5"
                     :class="toast.type === 'success'
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : (toast.type === 'error'
                            ? 'text-red-600 dark:text-red-400'
                            : 'text-blue-600 dark:text-blue-400')">
                    <i class="fas"
                       :class="toast.type === 'success'
                          ? 'fa-circle-check'
                          : (toast.type === 'error' ? 'fa-circle-xmark' : 'fa-circle-info')"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white" x-text="toast.title"></p>
                    <p class="text-sm text-slate-600 dark:text-slate-300 mt-0.5" x-text="toast.message"></p>
                </div>
                <button @click="hideToast()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
        </div>
    </div>
    
    <script>
    function ventasApp() {
        return {
            // Config
            idEmpresa: <?= $id_empresa ?>,
            printerName: <?= json_encode($impresora_caja) ?>,
            isDark: document.documentElement.classList.contains('dark'),
            permisos: window.__PERMISOS__ || {},
            gridApi: null,
            gridColumnApi: null,
            qzConnected: false,
            qzCheckingConnection: false,
            printProvider: 'N/A',
            smxPrinter: null,
            
            // Data
            ventas: [],
            isOfflineMode: typeof navigator !== 'undefined' ? !navigator.onLine : false,
            stats: {
                totalVentas: 0,
                montoTotal: 0,
                ventasHoy: 0,
                promedio: 0
            },
            
            // Filtros
            searchQuery: '',
            fechaDesde: '',
            fechaHasta: '',
            estadoFiltro: 'activo',
            periodoActivo: 'todo',
            periodos: [
                { key: 'todo', label: 'Todo' },
                { key: 'hoy', label: 'Hoy' },
                { key: 'semana', label: 'Semana' },
                { key: 'mes', label: 'Mes' },
                { key: 'anio', label: 'Año' },
                { key: 'custom', label: 'Personalizado' }
            ],
            
            // Paginación
            currentPage: 1,
            perPage: 20,
            totalRecords: 0,
            totalPages: 1,
            
            // UI
            loading: true,
            showModal: false,
            selectedVenta: null,
            ventaItems: [],
            sortBy: 'fecha',
            sortDir: 'desc',
            toast: {
                show: false,
                type: 'info',
                title: '',
                message: ''
            },
            toastTimer: null,
            confirmState: {
                open: false,
                title: '',
                message: '',
                confirmText: 'Confirmar',
                tone: 'danger'
            },
            confirmResolver: null,
            noticeState: {
                open: false,
                title: '',
                message: '',
                tone: 'info',
                reloadOnClose: false
            },
            reenviandoFacturaId: null,
            emitiendoFacturaId: null,
            
            init() {
                this.setupOfflineSupport();
                this.initAgGrid();
                this.setPeriodo('todo', false);
                this.loadVentas();
                this.initPrintAgent();
            },

            async setupOfflineSupport() {
                await (window.SmxOfflineDb?.ready || Promise.resolve());
                this.isOfflineMode = typeof navigator !== 'undefined' ? !navigator.onLine : false;
                window.addEventListener('online', () => {
                    this.isOfflineMode = false;
                    this.loadVentas();
                });
                window.addEventListener('offline', () => {
                    this.isOfflineMode = true;
                    this.showToast('error', 'Modo offline activo', 'Offline');
                });
            },

            offlineKey(suffix) {
                return `sx_misventas_offline:${this.idEmpresa}:${suffix}`;
            },

            readStorage(suffix, fallback = null) {
                return window.SmxOfflineDb?.getSync(this.offlineKey(suffix), fallback) ?? fallback;
            },

            writeStorage(suffix, value) {
                return window.SmxOfflineDb?.set(this.offlineKey(suffix), value);
            },

            buildListCacheKey() {
                return `list:${[
                    this.currentPage,
                    this.perPage,
                    this.searchQuery,
                    this.fechaDesde,
                    this.fechaHasta,
                    this.estadoFiltro,
                    this.sortBy,
                    this.sortDir
                ].map(v => String(v || '').trim().toLowerCase()).join(':')}`;
            },

            requireOnline(actionLabel = 'Esta acción') {
                if (!navigator.onLine) {
                    this.showToast('error', `${actionLabel} requiere conexión`, 'Offline');
                    return false;
                }
                return true;
            },

            async initAgGrid() {
                await window.__agGridReady;
                if (!this.$refs.ventasGrid || this.gridApi) return;

                const self = this;
                const gridOptions = {
                    context: { componentParent: self },
                    defaultColDef: {
                        sortable: true,
                        filter: true,
                        resizable: true,
                        minWidth: 120,
                        flex: 1,
                        enableRowGroup: true,
                        enableValue: true
                    },
                    columnDefs: this.getColumnDefs(),
                    rowData: [],
                    animateRows: true,
                    rowHeight: 66,
                    suppressCellFocus: true,
                    rowSelection: 'single',
                    rowGroupPanelShow: 'always',
                    groupDisplayType: 'multipleColumns',
                    localeText: window.SmxAgGridLocale?.getLocaleText?.() || {},
                    getContextMenuItems: params => self.getContextMenuItems(params),
                    sideBar: {
                        position: 'left',
                        toolPanels: [
                            {
                                id: 'columns',
                                labelDefault: 'Columnas',
                                labelKey: 'columns',
                                iconKey: 'columns',
                                toolPanel: 'agColumnsToolPanel',
                                toolPanelParams: {
                                    suppressRowGroups: false,
                                    suppressValues: false,
                                    suppressPivots: true,
                                    suppressPivotMode: true
                                }
                            },
                            {
                                id: 'filters',
                                labelDefault: 'Filtros',
                                labelKey: 'filters',
                                iconKey: 'filter',
                                toolPanel: 'agFiltersToolPanel'
                            }
                        ]
                    },
                    overlayNoRowsTemplate: '<span class="text-slate-500">No se encontraron ventas</span>',
                    onGridReady(params) {
                        self.gridApi = params.api;
                        self.gridColumnApi = params.columnApi || null;
                        params.api.setGridOption('rowData', self.sortedVentasSafe());
                        requestAnimationFrame(() => params.api.sizeColumnsToFit());
                    }
                };

                this.gridApi = agGrid.createGrid(this.$refs.ventasGrid, gridOptions);
            },

            getColumnDefs() {
                const self = this;
                return [
                    {
                        headerName: 'ID',
                        field: 'id_factura',
                        width: 110,
                        maxWidth: 130,
                        type: 'numericColumn',
                        valueFormatter: params => String(params.value ?? '')
                    },
                    {
                        headerName: 'Nro Factura',
                        field: 'nro_factura',
                        minWidth: 150,
                        cellRenderer(params) {
                            const nro = params.data?.nro_factura || 'S/N';
                            return `<div class="venta-cell-stack"><span class="main">${self.escapeHtml(nro)}</span><span class="meta">ID ${self.escapeHtml(params.data?.id_factura ?? '-')}</span></div>`;
                        }
                    },
                    {
                        headerName: 'Fecha',
                        field: 'fecha',
                        minWidth: 180,
                        sort: 'desc',
                        valueGetter: params => params.data?.fecha || '',
                        comparator: (a, b) => new Date(a || 0).getTime() - new Date(b || 0).getTime(),
                        cellRenderer(params) {
                            return `<div class="venta-cell-stack"><span class="main">${self.escapeHtml(self.formatDate(params.value))}</span><span class="meta">${self.escapeHtml(self.formatTime(params.value))}</span></div>`;
                        }
                    },
                    {
                        headerName: 'Cliente',
                        field: 'cliente_nombre',
                        minWidth: 260,
                        cellRenderer(params) {
                            const data = params.data || {};
                            const cliente = data.cliente_nombre || 'Consumidor Final';
                            const tipoDocClass = Number(data.tipo_documento || 0) === 3
                                ? 'doc-fe'
                                : (Number(data.tipo_documento || 0) === 1 ? 'doc-auto' : 'doc-nc');
                            let badges = `<span class="venta-badge ${tipoDocClass}">${self.escapeHtml(self.getTipoDocLabel(data.tipo_documento))}</span>`;
                            if (self.isDocElectronica(data.tipo_documento)) {
                                const key = self.getSifenStatusKey(data.estado_sifen);
                                const badgeClass = key === 'aprobado' ? 'sifen-ok' : (key === 'rechazado' ? 'sifen-rej' : 'sifen-pend');
                                badges += `<span class="venta-badge ${badgeClass}">${self.escapeHtml(self.getSifenStatusLabel(data.estado_sifen))}</span>`;
                            }
                            return `<div class="venta-cell-stack"><span class="main">${self.escapeHtml(cliente)}</span><span class="meta">${badges}</span></div>`;
                        }
                    },
                    {
                        headerName: 'RUC',
                        field: 'cliente_ruc',
                        minWidth: 150,
                        hide: true
                    },
                    {
                        headerName: 'Forma Pago',
                        field: 'forma_pago',
                        minWidth: 150,
                        hide: true,
                        valueFormatter: params => self.getFormaPago(params.value)
                    },
                    {
                        headerName: 'Estado',
                        field: 'estado_ui',
                        minWidth: 140,
                        valueFormatter: params => self.isVentaActiva(params.data?.estado_ui ?? params.value) ? 'Activo' : 'Anulado'
                    },
                    {
                        headerName: 'Estado SIFEN',
                        field: 'estado_sifen',
                        minWidth: 150,
                        hide: true,
                        valueFormatter: params => self.getSifenStatusLabel(params.value)
                    },
                    {
                        headerName: 'Total',
                        field: 'total',
                        minWidth: 160,
                        type: 'numericColumn',
                        valueFormatter: params => `${self.formatMoney(params.value)} Gs`,
                        cellStyle: { textAlign: 'right', fontWeight: '600' }
                    },
                ];
            },

            getContextMenuItems(params) {
                const data = params?.node?.data || params?.value || params?.data;
                const items = [];
                if (!data || typeof data !== 'object') {
                    return ['copy', 'copyWithHeaders', 'export'];
                }

                items.push({
                    name: this.isDocElectronica(data.tipo_documento) ? 'Ver KUDE' : 'Ver venta',
                    icon: this.agMenuIcon('eye'),
                    action: () => {
                        if (this.isDocElectronica(data.tipo_documento)) {
                            this.verKude(data);
                        } else {
                            this.verVenta(data);
                        }
                    }
                });

                items.push({
                    name: 'Reimprimir',
                    icon: this.agMenuIcon('copy'),
                    action: () => this.imprimirVenta(data)
                });

                if (this.isDocElectronica(data.tipo_documento) && this.getSifenStatusKey(data.estado_sifen) === 'pendiente') {
                    items.push({
                        name: 'Consultar SIFEN',
                        icon: this.agMenuIcon('filter'),
                        action: () => this.consultarSifen(data)
                    });
                }

                if (this.isDocElectronica(data.tipo_documento) && this.getSifenStatusKey(data.estado_sifen) === 'rechazado') {
                    items.push({
                        name: 'Reenviar a SIFEN',
                        icon: this.agMenuIcon('save'),
                        action: () => this.reenviarSifen(data)
                    });
                }

                if (this.canEmitirFE(data) && this.permisos.priv_update === 'Y') {
                    items.push({
                        name: 'Emitir FE',
                        icon: this.agMenuIcon('save'),
                        action: () => this.emitirFE(data)
                    });
                }

                if (this.isDocNotaControl(data.tipo_documento) && this.permisos.priv_update === 'Y') {
                    items.push({
                        name: 'Editar',
                        icon: this.agMenuIcon('settings'),
                        action: () => this.editarVenta(data)
                    });
                }

                if (this.isVentaActiva(data.estado_ui ?? data.estado) && this.permisos.priv_delete === 'Y') {
                    items.push('separator');
                    if (this.isDocElectronica(data.tipo_documento) && this.getSifenStatusKey(data.estado_sifen) === 'aprobado') {
                        items.push({
                            name: 'Anular en SIFEN',
                            icon: this.agMenuIcon('cancel'),
                            action: () => this.anularEnSifen(data)
                        });
                    } else {
                        items.push({
                            name: 'Anular local',
                            icon: this.agMenuIcon('cancel'),
                            action: () => this.anularLocal(data)
                        });
                    }
                }

                items.push('separator', 'copy', 'copyWithHeaders', 'export');
                return items;
            },

            formatLocalDate(dateValue) {
                const d = dateValue instanceof Date ? new Date(dateValue.getTime()) : new Date(dateValue);
                if (!(d instanceof Date) || Number.isNaN(d.getTime())) return '';
                const year = d.getFullYear();
                const month = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            },

            async loadPrintConfig() {
                try {
                    const res = await fetch('/public/misventas/api/print_config.php?_ts=' + Date.now(), { cache: 'no-store' });
                    const data = await res.json();
                    if (!data?.success) return false;
                    if (typeof data.impresora === 'string') {
                        this.printerName = data.impresora.trim();
                    }
                    return !!this.printerName;
                } catch (e) {
                    return false;
                }
            },

            async initPrintAgent() {
                await this.loadPrintConfig();
                if (typeof SmxPrinter === 'undefined') return;
                this.smxPrinter = new SmxPrinter({
                    strategy: 'agent-only',
                    agentBaseUrl: 'http://127.0.0.1:17890'
                });
                try {
                    await this.ensurePrintConnected();
                    this.qzConnected = true;
                    this.printProvider = this.smxPrinter.getProviderLabel();
                } catch (e) {
                    this.qzConnected = false;
                    this.printProvider = 'N/A';
                }
            },

            async ensurePrintConnected() {
                if (!this.smxPrinter) {
                    throw new Error('Sistemax Agent no disponible en esta página');
                }
                if (this.smxPrinter.isActive()) {
                    this.qzConnected = true;
                    return;
                }
                await this.smxPrinter.connect();
                this.qzConnected = true;
            },

            async retryPrintConnect() {
                if (this.qzCheckingConnection) return;
                this.qzCheckingConnection = true;
                try {
                    await this.loadPrintConfig();
                    if (!this.smxPrinter) {
                        this.smxPrinter = new SmxPrinter({
                            strategy: 'agent-only',
                            agentBaseUrl: 'http://127.0.0.1:17890'
                        });
                    }
                    await this.smxPrinter.disconnect();
                    await this.ensurePrintConnected();
                    this.printProvider = this.smxPrinter.getProviderLabel();
                    this.showToast('success', `Impresión conectada (${this.printProvider})`, 'Impresión');
                } catch (e) {
                    this.qzConnected = false;
                    this.printProvider = 'N/A';
                    this.showToast('error', e?.message || 'No se pudo conectar impresión local', 'Impresión');
                } finally {
                    this.qzCheckingConnection = false;
                }
            },

            async resolveDirectPrinter() {
                if (!this.smxPrinter) {
                    throw new Error('Sistemax Agent no disponible en esta página');
                }
                if (typeof this.smxPrinter.getDefaultPrinter === 'function') {
                    const defaultPrinter = String(await this.smxPrinter.getDefaultPrinter() || '').trim();
                    if (defaultPrinter) {
                        return defaultPrinter;
                    }
                }
                const availablePrinters = await this.smxPrinter.findPrinters();
                if (!Array.isArray(availablePrinters) || availablePrinters.length === 0) {
                    throw new Error('No hay impresoras disponibles en el sistema');
                }
                return String(availablePrinters[0] || '').trim();
            },

            async printEscposWithAgent(base64Data) {
                await this.ensurePrintConnected();
                let printer = (this.printerName || '').trim();
                if (!printer) {
                    printer = await this.resolveDirectPrinter();
                }
                const found = await this.smxPrinter.findPrinters(printer);
                if (!found || found.length === 0) {
                    const fallbackPrinter = await this.resolveDirectPrinter();
                    const fallbackFound = await this.smxPrinter.findPrinters(fallbackPrinter);
                    if (!fallbackFound || fallbackFound.length === 0) {
                        throw new Error(`Impresora "${printer}" no encontrada`);
                    }
                    printer = fallbackPrinter;
                    await this.smxPrinter.printRaw(fallbackFound[0], base64Data);
                    this.printerName = printer;
                    this.qzConnected = true;
                    this.printProvider = this.smxPrinter.getProviderLabel();
                    return;
                }
                await this.smxPrinter.printRaw(found[0], base64Data);
                this.printerName = printer;
                this.qzConnected = true;
                this.printProvider = this.smxPrinter.getProviderLabel();
            },

            isPrinterConfigError(error) {
                const msg = String(error?.message || '').toLowerCase();
                return msg.includes('impresora') || msg.includes('configurada en la caja') || msg.includes('no encontrada');
            },

            isAgentConnectionError(error) {
                const msg = String(error?.message || '').toLowerCase();
                return msg.includes('agent') || msg.includes('agente') || msg.includes('http ') || msg.includes('aborted') || msg.includes('timeout') || msg.includes('sin proveedor');
            },

            async intentarReconexionImpresion() {
                try {
                    if (!this.smxPrinter) {
                        this.smxPrinter = new SmxPrinter({
                            strategy: 'agent-only',
                            agentBaseUrl: 'http://127.0.0.1:17890'
                        });
                    } else {
                        await this.smxPrinter.disconnect();
                    }
                } catch (e) {}
                await this.ensurePrintConnected();
                this.printProvider = this.smxPrinter.getProviderLabel();
            },

            abrirFallbackNavegador(venta) {
                const isFE = this.isDocElectronica(venta?.tipo_documento);
                const script = isFE ? 'kude.php' : 'ticket.php';
                window.open(`/public/pos/${script}?id=${venta.id_factura}&id_empresa=${this.idEmpresa}&autoprint=1`, '_blank');
            },
            
            setPeriodo(key, reload = true) {
                this.periodoActivo = key;
                const today = new Date();
                const fmt = (d) => this.formatLocalDate(d);
                this.fechaHasta = fmt(today);
                
                switch (key) {
                    case 'todo':
                        this.fechaDesde = '';
                        this.fechaHasta = '';
                        break;
                    case 'hoy':
                        this.fechaDesde = fmt(today);
                        break;
                    case 'semana': {
                        const dow = today.getDay(); // 0=dom
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
                        // Mantener fechas actuales, usuario elige
                        break;
                }
                
                this.currentPage = 1;
                if (reload) this.loadVentas();
            },

            isPeriodoActivo(key) {
                return String(this.periodoActivo || '') === String(key || '');
            },

            periodoButtonClass(key) {
                if (this.isPeriodoActivo(key)) {
                    return 'bg-white dark:bg-slate-600 text-blue-600 dark:text-blue-300 shadow-sm ring-1 ring-blue-200 dark:ring-slate-500';
                }
                return 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200';
            },
            
            async loadVentas() {
                this.loading = true;
                const cacheKey = this.buildListCacheKey();
                
                try {
                    const params = new URLSearchParams({
                        id_empresa: this.idEmpresa,
                        page: this.currentPage,
                        per_page: this.perPage,
                        search: this.searchQuery,
                        fecha_desde: this.fechaDesde,
                        fecha_hasta: this.fechaHasta,
                        estado: this.estadoFiltro
                    });
                    
                    const response = await fetch(`/public/misventas/api/list.php?${params}`);
                    const data = await response.json();
                    
                    if (data.success) {
                        this.ventas = data.ventas || [];
                        this.totalRecords = data.total || 0;
                        this.totalPages = Math.ceil(this.totalRecords / this.perPage) || 1;
                        this.stats = data.stats || this.stats;
                        await this.writeStorage(cacheKey, data);
                        await Promise.all((data.ventas || []).map((venta) => this.writeStorage(`venta:${venta.id_factura}`, { success: true, venta, items: [] })));
                        if (this.gridApi) {
                            this.gridApi.setGridOption('rowData', this.sortedVentasSafe());
                        }
                    } else {
                        this.showToast('error', data.message || 'No se pudo cargar el listado', 'Error');
                    }
                } catch (error) {
                    const cached = this.readStorage(cacheKey);
                    if (cached?.success) {
                        this.ventas = cached.ventas || [];
                        this.totalRecords = cached.total || 0;
                        this.totalPages = Math.ceil(this.totalRecords / this.perPage) || 1;
                        this.stats = cached.stats || this.stats;
                        if (this.gridApi) {
                            this.gridApi.setGridOption('rowData', this.sortedVentasSafe());
                        }
                        this.showToast('error', 'Mostrando ventas desde cache offline', 'Offline');
                    } else {
                        console.error('Error cargando ventas:', error);
                        this.showToast('error', 'Error de conexión al cargar ventas', 'Conexión');
                    }
                }
                
                this.loading = false;
                if (this.gridApi) {
                    this.$nextTick(() => {
                        requestAnimationFrame(() => this.gridApi.sizeColumnsToFit());
                    });
                }
            },
            
            async verVenta(venta) {
                this.selectedVenta = venta;
                this.ventaItems = [];
                this.showModal = true;
                
                try {
                    const response = await fetch(`/public/pos/api/get_venta.php?id=${venta.id_factura}&id_empresa=${this.idEmpresa}`);
                    const data = await response.json();
                    
                    if (data.success) {
                        this.ventaItems = data.items || [];
                        await this.writeStorage(`venta:${venta.id_factura}`, { success: true, venta, items: data.items || [] });
                    } else {
                        this.showToast('error', data.message || 'No se pudo cargar el detalle', 'Error');
                    }
                } catch (error) {
                    const cached = this.readStorage(`venta:${venta.id_factura}`);
                    if (cached?.success) {
                        this.selectedVenta = cached.venta || venta;
                        this.ventaItems = cached.items || [];
                        this.showToast('error', 'Detalle cargado desde cache offline', 'Offline');
                    } else {
                        console.error('Error cargando detalle:', error);
                        this.showToast('error', 'Error de conexión al cargar detalle', 'Conexión');
                    }
                }
            },
            
            getEscposUrlByTipoDoc(venta) {
                const tipo = Number(venta?.tipo_documento || 0);
                const baseParams = `id=${venta.id_factura}&id_empresa=${this.idEmpresa}&width=48`;
                if (tipo === 3) return `/public/pos/ticket_factura_electronica.php?${baseParams}`;
                if (tipo === 1) return `/public/pos/ticket_factura_autoimpresa.php?${baseParams}`;
                return `/public/pos/ticket_nota_comun.php?${baseParams}`;
            },

            async imprimirVenta(venta) {
                if (!venta?.id_factura) return;
                if (!this.requireOnline('Imprimir')) return;

                try {
                    await this.loadPrintConfig();
                    const response = await fetch(this.getEscposUrlByTipoDoc(venta));
                    const data = await response.json();
                    if (!data?.success || !data?.data) {
                        throw new Error(data?.message || 'No se pudo generar ticket ESC/POS');
                    }

                    await this.printEscposWithAgent(data.data);
                    this.showToast('success', `Ticket enviado a impresora (${this.printProvider})`, 'Impresión');
                } catch (error) {
                    console.error('Error imprimiendo ESC/POS:', error);
                    // Error de configuración de impresora: no forzar fallback ni desconectar agent.
                    if (this.isPrinterConfigError(error)) {
                        this.showToast('error', error?.message || 'Impresora no configurada/encontrada en esta caja', 'Impresión');
                        return;
                    }

                    // Error de conexión con Agent: reintento automático 1 vez.
                    if (this.isAgentConnectionError(error)) {
                        try {
                            await this.intentarReconexionImpresion();
                            await this.loadPrintConfig();
                            const retryResponse = await fetch(this.getEscposUrlByTipoDoc(venta));
                            const retryData = await retryResponse.json();
                            if (!retryData?.success || !retryData?.data) {
                                throw new Error(retryData?.message || 'No se pudo generar ticket ESC/POS');
                            }
                            await this.printEscposWithAgent(retryData.data);
                            this.showToast('success', `Ticket enviado a impresora (${this.printProvider})`, 'Impresión');
                            return;
                        } catch (retryError) {
                            console.error('Reintento de impresión falló:', retryError);
                            this.qzConnected = false;
                            this.printProvider = 'N/A';
                            const abrirRespaldo = await this.askConfirm({
                                title: 'Impresión local no disponible',
                                message: 'El Agent no pudo imprimir el ticket. ¿Desea abrir la impresión de respaldo del navegador?',
                                confirmText: 'Abrir respaldo',
                                tone: 'danger'
                            });
                            if (abrirRespaldo) {
                                this.abrirFallbackNavegador(venta);
                            }
                            this.showToast('error', retryError?.message || 'No se pudo imprimir con Sistemax Agent.', 'Impresión');
                            return;
                        }
                    }

                    // Cualquier otro error: no fallback automático.
                    this.showToast('error', error?.message || 'No se pudo imprimir con Sistemax Agent.', 'Impresión');
                }
            },
            
            verKude(venta) {
                window.open(`/public/pos/kude.php?id=${venta.id_factura}&id_empresa=${this.idEmpresa}`, '_blank');
            },
            
            editarVenta(venta) {
                window.location.href = `/public/pos/index.php?edit_id=${venta.id_factura}`;
            },
            
            duplicarVenta(venta) {
                window.location.href = `/public/pos/index.php?duplicate=${venta.id_factura}`;
            },
            
            async anularVenta(venta) {
                if (!this.requireOnline('Anular la venta')) return;
                const ok = await this.askConfirm({
                    title: 'Anular Venta',
                    message: '¿Está seguro de anular esta venta?',
                    confirmText: 'Anular',
                    tone: 'danger'
                });
                if (!ok) return;
                
                try {
                    const response = await fetch('/public/ventas/api/anular.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id_factura: venta.id_factura, id_empresa: this.idEmpresa })
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        this.showToast('success', data.message || 'Venta anulada correctamente', 'Éxito');
                        this.loadVentas();
                    } else {
                        this.showToast('error', data.message || 'Error al anular', 'Error');
                    }
                } catch (error) {
                    this.showToast('error', 'Error de conexión', 'Conexión');
                }
            },

            async anularLocal(venta) {
                await this.anularVenta(venta);
            },

            async anularEnSifen(venta) {
                const ok = await this.askConfirm({
                    title: 'Anular en SIFEN',
                    message: '¿Confirmar anulación en SIFEN?',
                    confirmText: 'Confirmar',
                    tone: 'danger'
                });
                if (!ok) return;
                await this.anularVenta(venta);
            },

            async consultarSifen(venta) {
                if (!this.requireOnline('Consultar SIFEN')) return;
                try {
                    const body = new URLSearchParams({
                        id_factura: String(venta.id_factura),
                        id_empresa: String(this.idEmpresa),
                        consult_only: '1'
                    });
                    const response = await fetch('/public/pos/api/sifen_retry.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    });
                    const data = await response.json();
                    this.hideToast();
                    this.showNotice(
                        'SIFEN',
                        data.message || data.error || 'Consulta SIFEN ejecutada',
                        data.success ? 'success' : 'info',
                        true
                    );
                } catch (error) {
                    this.hideToast();
                    this.showNotice('Conexión', 'Error consultando SIFEN', 'error');
                }
            },

            async reenviarSifen(venta) {
                if (!this.requireOnline('Reenviar a SIFEN')) return;
                if (this.reenviandoFacturaId !== null) return;
                const estadoKey = this.getSifenStatusKey(venta && venta.estado_sifen);
                if (estadoKey === 'pendiente') {
                    this.hideToast();
                    this.showNotice(
                        'SIFEN',
                        'La factura está en estado Pendiente. Use "Consultar Sifen" para obtener el resultado del lote.',
                        'info'
                    );
                    return;
                }
                const ok = await this.askConfirm({
                    title: 'Reenviar a SIFEN',
                    message: '¿Reenviar esta factura a SIFEN?',
                    confirmText: 'Reenviar',
                    tone: 'primary'
                });
                if (!ok) return;
                this.reenviandoFacturaId = venta.id_factura;
                try {
                    const body = new URLSearchParams({
                        id_factura: String(venta.id_factura),
                        id_empresa: String(this.idEmpresa)
                    });
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 45000);
                    const response = await fetch('/public/pos/api/sifen_retry.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString(),
                        signal: controller.signal
                    });
                    clearTimeout(timeoutId);

                    const text = await response.text();
                    let data = {};
                    try {
                        data = JSON.parse(text || '{}');
                    } catch (e) {
                        data = { success: false, message: text || 'Respuesta inválida de SIFEN' };
                    }

                    this.hideToast();
                    this.showNotice(
                        'SIFEN',
                        data.message || data.error || 'Reenvío ejecutado',
                        data.success ? 'success' : 'info',
                        true
                    );
                } catch (error) {
                    const isTimeout = error && (error.name === 'AbortError');
                    if (!isTimeout) {
                        this.hideToast();
                        this.showNotice('Conexión', 'Error reenviando a SIFEN', 'error');
                        return;
                    }

                    // Fallback: encolar reenvío para proceso asíncrono cuando SIFEN demora.
                    try {
                        const qBody = new URLSearchParams({
                            id_factura: String(venta.id_factura),
                            id_empresa: String(this.idEmpresa),
                            queue_action: 'enqueue',
                            accion: 'emitir',
                            prioridad: '5'
                        });
                        const qResponse = await fetch('/public/pos/api/sifen_retry.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: qBody.toString()
                        });
                        const qText = await qResponse.text();
                        let qData = {};
                        try {
                            qData = JSON.parse(qText || '{}');
                        } catch (e) {
                            qData = {};
                        }
                        this.hideToast();
                        this.showNotice(
                            'SIFEN',
                            (qData && qData.success)
                                ? 'SIFEN demoró en línea. La FE fue encolada para reintento asíncrono. Use "Consultar Sifen" en unos segundos.'
                                : 'SIFEN no respondió a tiempo (45s). Intente nuevamente o consulte el estado.',
                            (qData && qData.success) ? 'info' : 'error',
                            true
                        );
                    } catch (queueErr) {
                        this.hideToast();
                        this.showNotice(
                            'Conexión',
                            'SIFEN no respondió a tiempo (45s) y no se pudo encolar el reenvío. Intente nuevamente.',
                            'error'
                        );
                    }
                } finally {
                    this.reenviandoFacturaId = null;
                }
            },

            parseVentaDate(venta) {
                const raw = String(venta?.fecha || '').trim();
                if (!raw) return null;
                let d = new Date(raw);
                if (!isNaN(d.getTime())) return d;
                d = new Date(raw.replace(' ', 'T'));
                if (!isNaN(d.getTime())) return d;
                return null;
            },

            canEmitirFE(venta) {
                if (!this.isDocNotaControl(venta?.tipo_documento)) return false;
                if (!this.isVentaActiva(venta?.estado_ui ?? venta?.estado)) return false;
                const fecha = this.parseVentaDate(venta);
                if (!fecha) return false;
                const diffMs = Date.now() - fecha.getTime();
                if (diffMs < 0) return true;
                return diffMs <= (48 * 60 * 60 * 1000);
            },

            async emitirFE(venta) {
                if (!this.requireOnline('Emitir FE')) return;
                if (this.emitiendoFacturaId !== null) return;
                if (!this.canEmitirFE(venta)) {
                    this.showNotice('SIFEN', 'Solo se puede emitir FE para factura común dentro de las primeras 48 horas.', 'info');
                    return;
                }
                const ok = await this.askConfirm({
                    title: 'Emitir FE',
                    message: '¿Convertir esta factura común a FE y emitir en SIFEN?',
                    confirmText: 'Emitir FE',
                    tone: 'primary'
                });
                if (!ok) return;
                this.emitiendoFacturaId = venta.id_factura;
                try {
                    const body = new URLSearchParams({
                        id_factura: String(venta.id_factura),
                        id_empresa: String(this.idEmpresa),
                        convert: '1'
                    });
                    const response = await fetch('/public/pos/api/sifen_retry.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString()
                    });
                    const text = await response.text();
                    let data = {};
                    try {
                        data = JSON.parse(text || '{}');
                    } catch (e) {
                        data = { success: false, message: text || 'Respuesta inválida de SIFEN' };
                    }
                    this.hideToast();
                    this.showNotice(
                        'SIFEN',
                        data.message || data.mensaje || data.error || 'Proceso de emisión FE ejecutado',
                        data.success ? 'success' : 'info',
                        true
                    );
                } catch (error) {
                    this.hideToast();
                    this.showNotice('Conexión', 'Error al emitir FE', 'error');
                } finally {
                    this.emitiendoFacturaId = null;
                }
            },

            showToast(type = 'info', message = '', title = 'Mensaje') {
                if (this.toastTimer) {
                    clearTimeout(this.toastTimer);
                    this.toastTimer = null;
                }
                this.toast = {
                    show: true,
                    type,
                    title,
                    message
                };
                this.toastTimer = setTimeout(() => {
                    this.toast.show = false;
                }, 3200);
            },

            hideToast() {
                if (this.toastTimer) {
                    clearTimeout(this.toastTimer);
                    this.toastTimer = null;
                }
                this.toast.show = false;
            },

            askConfirm({ title = 'Confirmar', message = '', confirmText = 'Confirmar', tone = 'danger' } = {}) {
                return new Promise((resolve) => {
                    this.confirmState = {
                        open: true,
                        title,
                        message,
                        confirmText,
                        tone
                    };
                    this.confirmResolver = resolve;
                });
            },

            resolveConfirm(result) {
                this.confirmState.open = false;
                if (typeof this.confirmResolver === 'function') {
                    this.confirmResolver(Boolean(result));
                }
                this.confirmResolver = null;
            },

            showNotice(title = 'Aviso', message = '', tone = 'info', reloadOnClose = false) {
                this.noticeState = {
                    open: true,
                    title,
                    message,
                    tone,
                    reloadOnClose
                };
            },

            closeNotice() {
                const shouldReload = Boolean(this.noticeState.reloadOnClose);
                this.noticeState.open = false;
                this.noticeState.reloadOnClose = false;
                if (shouldReload) this.loadVentas();
            },

            async copyNoticeMessage() {
                const text = String(this.noticeState.message || '').trim();
                if (!text) return;
                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(text);
                        return;
                    }
                    const tmp = document.createElement('textarea');
                    tmp.value = text;
                    tmp.style.position = 'fixed';
                    tmp.style.opacity = '0';
                    document.body.appendChild(tmp);
                    tmp.focus();
                    tmp.select();
                    document.execCommand('copy');
                    document.body.removeChild(tmp);
                } catch (e) {
                    // Si falla el portapapeles, el texto queda visible para copia manual.
                }
            },
            
            clearFilters() {
                this.searchQuery = '';
                this.estadoFiltro = 'activo';
                this.setPeriodo('todo');
            },

            exportExcel() {
                const params = new URLSearchParams({
                    id_empresa: this.idEmpresa,
                    search: this.searchQuery,
                    fecha_desde: this.fechaDesde,
                    fecha_hasta: this.fechaHasta,
                    estado: this.estadoFiltro
                });
                window.location.href = `/public/misventas/api/export_excel.php?${params.toString()}`;
            },
            
            prevPage() {
                if (this.currentPage > 1) {
                    this.currentPage--;
                    this.loadVentas();
                }
            },
            
            nextPage() {
                if (this.currentPage < this.totalPages) {
                    this.currentPage++;
                    this.loadVentas();
                }
            },
            
            toggleTheme() {
                this.isDark = true;
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
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

            sortValue(venta, field) {
                switch (field) {
                    case 'id_factura':
                        return Number(venta.id_factura || 0);
                    case 'nro_factura':
                        return (venta.nro_factura || '').toString().toLowerCase();
                    case 'fecha':
                        return new Date(venta.fecha || 0).getTime();
                    case 'cliente':
                        return (venta.cliente_nombre || 'Consumidor Final').toString().toLowerCase();
                    case 'total':
                        return Number(venta.total || 0);
                    case 'estado':
                        return Number(venta.estado || 0);
                    default:
                        return '';
                }
            },

            sortedVentas() {
                const rows = Array.isArray(this.ventas) ? [...this.ventas] : [];
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

            sortedVentasSafe() {
                try {
                    if (Array.isArray(this.ventas)) {
                        return this.sortedVentas();
                    }
                    if (this.ventas && typeof this.ventas === 'object') {
                        this.ventas = Object.values(this.ventas);
                        return this.sortedVentas();
                    }
                    return [];
                } catch (error) {
                    console.error('Error renderizando ventas:', error);
                    return Array.isArray(this.ventas) ? this.ventas : [];
                }
            },

            getVentaRowKey(venta, idx) {
                const id = venta && (venta.id_factura ?? venta.id ?? venta.nro_factura);
                return String(id ?? idx);
            },

            escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            },

            agMenuIcon(iconName) {
                const safe = String(iconName || '').replace(/[^a-z0-9-]/gi, '');
                return `<span class="ag-icon ag-icon-${safe}"></span>`;
            },
            
            formatMoney(amount) {
                return new Intl.NumberFormat('es-PY', { maximumFractionDigits: 0 }).format(amount || 0);
            },
            
            formatDate(dateStr) {
                if (!dateStr) return '-';
                const date = new Date(dateStr);
                if (Number.isNaN(date.getTime())) return String(dateStr);
                return date.toLocaleDateString('es-PY');
            },
            
            formatTime(dateStr) {
                if (!dateStr) return '';
                const date = new Date(dateStr);
                if (Number.isNaN(date.getTime())) return '';
                return date.toLocaleTimeString('es-PY', { hour: '2-digit', minute: '2-digit' });
            },
            
            formatDateTime(dateStr) {
                if (!dateStr) return '-';
                const date = new Date(dateStr);
                if (Number.isNaN(date.getTime())) return String(dateStr);
                return date.toLocaleString('es-PY');
            },

            getTipoDocLabel(tipo) {
                const t = Number(tipo || 0);
                if (t === 3) return 'Factura Electronica';
                if (t === 1) return 'Autoimpresa';
                return 'Nota de control';
            },

            getTipoDocPillClass(tipo) {
                const t = Number(tipo || 0);
                if (t === 3) return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
                if (t === 1) return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400';
                return 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400';
            },

            isDocAutoimpresa(tipo) {
                return Number(tipo || 0) === 1;
            },

            isDocNotaControl(tipo) {
                const t = Number(tipo || 0);
                return t !== 1 && t !== 3;
            },

            getSifenStatusKey(estado) {
                const val = String(estado || '').trim().toLowerCase();
                if (val === 'aprobado') return 'aprobado';
                if (val === 'rechazado') return 'rechazado';
                return 'pendiente';
            },

            isDocElectronica(tipo) {
                return Number(tipo || 0) === 3;
            },

            getSifenStatusLabel(estado) {
                const val = String(estado || '').trim().toLowerCase();
                if (val === 'aprobado') return 'Aprobado';
                if (val === 'rechazado') return 'Rechazado';
                return 'Pendiente';
            },

            getSifenStatusClass(estado) {
                const val = String(estado || '').trim().toLowerCase();
                if (val === 'aprobado') return 'text-green-600 dark:text-green-400';
                if (val === 'rechazado') return 'text-red-600 dark:text-red-400';
                return 'text-violet-600 dark:text-violet-400';
            },

            isVentaActiva(estado) {
                const val = String(estado ?? '').trim().toLowerCase();
                if (val === 'anulado' || val === 'anulada') return false;
                if (val === 'activo' || val === 'activa') return true;
                return val === '' || val === '0';
            },
            
            getFormaPago(tipo) {
                const formas = { 1: 'Efectivo', 2: 'Tarjeta', 3: 'Transferencia', 4: 'Pix', 5: 'Crédito' };
                return formas[tipo] || 'Efectivo';
            }
        }
    }
    </script>
</body>
</html>
