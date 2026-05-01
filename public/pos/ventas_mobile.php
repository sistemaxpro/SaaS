<?php
/**
 * Ventas Mobile - Consulta de Ventas para Móvil
 * Grid de 2 columnas con filtros avanzados
 * PWA Ready - Tailwind CSS + Alpine.js
 */

require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

$id_login = Session::getIdLogin();
$id_empresa = Session::getIdEmpresa();
$usuario = Session::get('usuario', 'Usuario');
$nombreEmpresa = 'SistemaX';

try {
    $pdo = Database::getMasterConnection();
    
    // Obtener datos del usuario
    $stmt = $pdo->prepare("SELECT login, id_empresa FROM sec_users WHERE id_login = :id");
    $stmt->execute([':id' => $id_login]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user_data) {
        $usuario = $user_data['login'];
        $id_empresa = (int)$user_data['id_empresa'];
    }
    
    // Obtener nombre de empresa
    $stmt = $pdo->prepare("SELECT empresa FROM empresa WHERE id_empresa = :id");
    $stmt->execute([':id' => $id_empresa]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($emp) {
        $nombreEmpresa = $emp['empresa'];
    }
} catch (Exception $e) {
    // Mantener defaults
}
?>
<!DOCTYPE html>
<html lang="es" x-data="ventasMobile()" x-init="init()" :class="isDarkMode ? 'dark' : ''">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#1e293b">
    
    <title>Ventas - <?php echo htmlspecialchars($nombreEmpresa); ?></title>
    
    <link rel="stylesheet" href="../assets/tailwind.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        [x-cloak] { display: none !important; }
        
        /* Safe areas para notch */
        .safe-top { padding-top: env(safe-area-inset-top, 0); }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom, 0); }
        
        /* Scrollbar oculto */
        .hide-scrollbar { -webkit-overflow-scrolling: touch; scrollbar-width: none; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        
        /* Card shadow */
        .card-shadow {
            box-shadow: 0 2px 8px -2px rgba(0,0,0,0.1), 0 1px 2px -1px rgba(0,0,0,0.06);
        }
        
        /* Ripple effect */
        .ripple {
            position: relative;
            overflow: hidden;
        }
        .ripple:active::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle, rgba(0,0,0,0.1) 10%, transparent 70%);
            animation: ripple 0.4s ease-out;
        }
        @keyframes ripple {
            from { transform: scale(0); opacity: 1; }
            to { transform: scale(2.5); opacity: 0; }
        }
        
        /* Spinner */
        .spinner {
            border: 3px solid rgba(0,0,0,0.1);
            border-left-color: #3b82f6;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Input estilo iOS */
        .inp {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            font-size: 16px;
            transition: all 0.2s;
        }
        .inp:focus {
            outline: none;
            border-color: #3b82f6;
            background: white;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .dark .inp {
            background: #1e293b;
            border-color: #334155;
            color: white;
        }
        .dark .inp:focus {
            background: #0f172a;
            border-color: #3b82f6;
        }
        
        /* Select estilo */
        .sel {
            appearance: none;
            width: 100%;
            padding: 10px 32px 10px 12px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #f8fafc url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236b7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E") no-repeat right 8px center;
            background-size: 20px;
            font-size: 14px;
        }
        .dark .sel {
            background-color: #1e293b;
            border-color: #334155;
            color: white;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%239ca3af'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
        }
        
        /* Chip/Tag */
        .chip {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            gap: 4px;
        }
        .chip-active { background: #dcfce7; color: #166534; }
        .chip-anulado { background: #fee2e2; color: #991b1b; }
        .dark .chip-active { background: rgba(34, 197, 94, 0.2); color: #4ade80; }
        .dark .chip-anulado { background: rgba(239, 68, 68, 0.2); color: #f87171; }
    </style>
</head>
<body class="bg-gray-100 dark:bg-slate-900 min-h-screen">
    <div x-cloak class="flex flex-col h-screen">
        
        <!-- Header fijo -->
        <header class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 safe-top flex-shrink-0">
            <div class="flex items-center justify-between px-4 py-3">
                <div class="flex items-center gap-3">
                    <div>
                        <h1 class="font-bold text-gray-800 dark:text-white">Ventas</h1>
                        <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($nombreEmpresa); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Buscador -->
            <div class="px-4 pb-3">
                <div class="relative">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input 
                        type="text" 
                        x-model="searchQuery"
                        @input.debounce.400ms="loadVentas()"
                        placeholder="Buscar por #, cliente, RUC..."
                        class="inp pl-11 pr-10">
                    <button 
                        x-show="searchQuery"
                        @click="searchQuery = ''; loadVentas()"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 p-1">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <!-- Filtros -->
            <div class="px-4 pb-3 flex gap-2 overflow-x-auto hide-scrollbar">
                <!-- Filtro Período -->
                <select x-model="filterPeriod" @change="loadVentas()" class="sel flex-shrink-0">
                    <option value="mes">📅 Este mes</option>
                    <option value="semana">📆 Esta semana</option>
                    <option value="hoy">🕐 Hoy</option>
                    <option value="anio">📊 Este año</option>
                    <option value="custom">🔧 Personalizado</option>
                </select>
                
                <!-- Filtro Estado -->
                <select x-model="filterEstado" @change="loadVentas()" class="sel flex-shrink-0">
                    <option value="activo">✅ Activos</option>
                    <option value="anulado">❌ Anulados</option>
                    <option value="todos">📋 Todos</option>
                </select>
            </div>
            
            <!-- Fechas personalizadas -->
            <div x-show="filterPeriod === 'custom'" x-transition class="px-4 pb-3 flex gap-2">
                <div class="flex-1">
                    <label class="text-xs text-gray-500 dark:text-gray-400 mb-1 block">Desde</label>
                    <input type="date" x-model="fechaDesde" @change="loadVentas()" 
                           class="inp text-sm py-2">
                </div>
                <div class="flex-1">
                    <label class="text-xs text-gray-500 dark:text-gray-400 mb-1 block">Hasta</label>
                    <input type="date" x-model="fechaHasta" @change="loadVentas()" 
                           class="inp text-sm py-2">
                </div>
            </div>
            
            <!-- Resumen -->
            <div class="px-4 pb-3 flex items-center justify-between text-sm">
                <span class="text-gray-500 dark:text-gray-400">
                    <span x-text="ventas.length"></span> ventas
                </span>
                <span class="font-bold text-green-600 dark:text-green-400">
                    Total: <span x-text="formatMoney(totalVentas)"></span> Gs
                </span>
            </div>
        </header>
        
        <!-- Contenido scrolleable -->
        <main class="flex-1 overflow-y-auto hide-scrollbar p-3">
            <!-- Loading -->
            <div x-show="loading" class="flex items-center justify-center py-12">
                <div class="spinner"></div>
            </div>
            
            <!-- Grid de ventas 2 columnas -->
            <div x-show="!loading && ventas.length > 0" class="grid grid-cols-2 gap-2">
                <template x-for="venta in ventas" :key="venta.id_factura">
                    <div 
                        @click="openVenta(venta)"
                        :class="venta.estado == 1 ? 'opacity-60' : ''"
                        class="bg-white dark:bg-slate-800 rounded-xl p-3 card-shadow ripple">
                        
                        <!-- Header: Número y Estado -->
                        <div class="flex items-center justify-between mb-1">
                            <p class="font-bold text-sm text-gray-800 dark:text-white" x-text="'#' + venta.nro_factura"></p>
                            <span 
                                :class="venta.estado == 0 ? 'chip-active' : 'chip-anulado'"
                                class="chip text-[10px]"
                                x-text="venta.estado == 0 ? '✓' : '✗'">
                            </span>
                        </div>
                        
                        <!-- Total -->
                        <p class="font-black text-lg leading-tight" 
                           :class="venta.estado == 0 ? 'text-green-600 dark:text-green-400' : 'text-gray-400 line-through'"
                           x-text="formatMoney(venta.total)"></p>
                        <p class="text-[10px] text-gray-400">Gs</p>
                        
                        <!-- Tipo documento badge -->
                        <div class="mt-1">
                            <span class="text-[9px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-400"
                                  x-text="getTipoDoc(venta.tipo_documento)"></span>
                        </div>
                        
                        <!-- Info inferior -->
                        <div class="mt-2 pt-2 border-t border-gray-100 dark:border-slate-700">
                            <p class="text-[10px] text-gray-500 dark:text-gray-400" x-text="formatFecha(venta.fecha)"></p>
                            <p class="text-xs text-gray-700 dark:text-gray-300 truncate font-medium" x-text="venta.cliente || 'Sin cliente'"></p>
                            <p class="text-[10px] text-gray-400 truncate" x-text="venta.cliente_ruc || ''"></p>
                        </div>
                    </div>
                </template>
            </div>
            
            <!-- Sin resultados -->
            <div x-show="!loading && ventas.length === 0" class="text-center py-16">
                <div class="text-6xl mb-4 opacity-50">📋</div>
                <p class="text-gray-500 dark:text-gray-400 font-medium">No hay ventas</p>
                <p class="text-sm text-gray-400 dark:text-gray-500">Intenta cambiar los filtros</p>
            </div>
            
            <!-- Cargar más -->
            <div x-show="!loading && ventas.length > 0 && hasMore" class="py-4 text-center">
                <button 
                    @click="loadMore()"
                    :disabled="loadingMore"
                    class="px-6 py-2 bg-blue-500 text-white rounded-full text-sm font-medium disabled:opacity-50">
                    <span x-show="!loadingMore">Cargar más</span>
                    <span x-show="loadingMore"><i class="fas fa-spinner fa-spin"></i></span>
                </button>
            </div>
        </main>
        
        <!-- Modal detalle de venta -->
        <div 
            x-show="showDetail"
            x-cloak
            class="fixed inset-0 z-50">
            <!-- Backdrop -->
            <div 
                x-show="showDetail"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="showDetail = false"
                class="absolute inset-0 bg-black/60 backdrop-blur-sm">
            </div>
            
            <!-- Sheet -->
            <div 
                x-show="showDetail"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="translate-y-full"
                x-transition:enter-end="translate-y-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="translate-y-0"
                x-transition:leave-end="translate-y-full"
                class="absolute bottom-0 left-0 right-0 bg-white dark:bg-slate-800 rounded-t-3xl max-h-[85vh] overflow-hidden safe-bottom">
                
                <!-- Handle -->
                <div class="flex justify-center pt-3 pb-2">
                    <div class="w-10 h-1 bg-gray-300 dark:bg-slate-600 rounded-full"></div>
                </div>
                
                <div x-show="selectedVenta" class="px-4 pb-6 overflow-y-auto max-h-[75vh]">
                    <!-- Header -->
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">
                                Factura #<span x-text="selectedVenta?.nro_factura"></span>
                            </h2>
                            <p class="text-sm text-gray-500" x-text="formatFechaLarga(selectedVenta?.fecha)"></p>
                        </div>
                        <span 
                            :class="selectedVenta?.estado == 0 ? 'chip-active' : 'chip-anulado'"
                            class="chip"
                            x-text="selectedVenta?.estado == 0 ? 'Activo' : 'Anulado'">
                        </span>
                    </div>
                    
                    <!-- Cliente -->
                    <div class="bg-gray-50 dark:bg-slate-700 rounded-xl p-3 mb-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Cliente</p>
                        <p class="font-medium text-gray-800 dark:text-white" x-text="selectedVenta?.cliente || 'Sin cliente'"></p>
                        <p class="text-sm text-gray-500" x-text="selectedVenta?.cliente_ruc || ''"></p>
                    </div>
                    
                    <!-- Totales -->
                    <div class="bg-green-50 dark:bg-green-900/20 rounded-xl p-4 mb-4 text-center">
                        <p class="text-sm text-green-600 dark:text-green-400">Total</p>
                        <p class="text-3xl font-black text-green-600 dark:text-green-400" x-text="formatMoney(selectedVenta?.total) + ' Gs'"></p>
                    </div>
                    
                    <!-- Acciones -->
                    <div class="grid grid-cols-2 gap-2">
                        <a :href="'/public/pos/ticket.php?id=' + selectedVenta?.id_factura + '&id_empresa=<?php echo $id_empresa; ?>'" 
                           target="_blank"
                           class="flex items-center justify-center gap-2 py-3 bg-blue-500 text-white rounded-xl font-medium">
                            <i class="fas fa-eye"></i> Ver Ticket
                        </a>
                        <button 
                            @click="printTicket(selectedVenta)"
                            class="flex items-center justify-center gap-2 py-3 bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-xl font-medium">
                            <i class="fas fa-print"></i> Imprimir
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
    
    <script>
    function ventasMobile() {
        return {
            // Config
            idEmpresa: <?php echo $id_empresa; ?>,
            isDarkMode: localStorage.getItem('theme') === 'dark',
            
            // State
            loading: false,
            loadingMore: false,
            ventas: [],
            page: 1,
            hasMore: false,
            
            // Filters
            searchQuery: '',
            filterPeriod: 'mes',
            filterEstado: 'activo',
            fechaDesde: '',
            fechaHasta: '',
            
            // Detail
            showDetail: false,
            selectedVenta: null,
            
            // Computed
            get totalVentas() {
                return this.ventas
                    .filter(v => v.estado == 0)
                    .reduce((sum, v) => sum + parseFloat(v.total || 0), 0);
            },
            
            init() {
                // Fechas por defecto para custom
                const hoy = new Date();
                this.fechaHasta = hoy.toISOString().split('T')[0];
                const inicio = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
                this.fechaDesde = inicio.toISOString().split('T')[0];
                
                this.loadVentas();
            },
            
            async loadVentas() {
                this.loading = true;
                this.page = 1;
                
                try {
                    const params = new URLSearchParams({
                        action: 'list',
                        id_empresa: this.idEmpresa,
                        q: this.searchQuery,
                        periodo: this.filterPeriod,
                        estado: this.filterEstado,
                        fecha_desde: this.filterPeriod === 'custom' ? this.fechaDesde : '',
                        fecha_hasta: this.filterPeriod === 'custom' ? this.fechaHasta : '',
                        page: 1,
                        limit: 30
                    });
                    
                    const res = await fetch(`api/ventas_list.php?${params}`);
                    const data = await res.json();
                    
                    if (data.success) {
                        this.ventas = data.ventas || [];
                        this.hasMore = data.hasMore || false;
                    }
                } catch (e) {
                    console.error('Error:', e);
                }
                
                this.loading = false;
            },
            
            async loadMore() {
                this.loadingMore = true;
                this.page++;
                
                try {
                    const params = new URLSearchParams({
                        action: 'list',
                        id_empresa: this.idEmpresa,
                        q: this.searchQuery,
                        periodo: this.filterPeriod,
                        estado: this.filterEstado,
                        fecha_desde: this.filterPeriod === 'custom' ? this.fechaDesde : '',
                        fecha_hasta: this.filterPeriod === 'custom' ? this.fechaHasta : '',
                        page: this.page,
                        limit: 30
                    });
                    
                    const res = await fetch(`api/ventas_list.php?${params}`);
                    const data = await res.json();
                    
                    if (data.success) {
                        this.ventas = [...this.ventas, ...(data.ventas || [])];
                        this.hasMore = data.hasMore || false;
                    }
                } catch (e) {
                    console.error('Error:', e);
                }
                
                this.loadingMore = false;
            },
            
            openVenta(venta) {
                this.selectedVenta = venta;
                this.showDetail = true;
                if (navigator.vibrate) navigator.vibrate(10);
            },
            
            printTicket(venta) {
                if (!venta) return;
                const url = `/public/pos/ticket.php?id=${venta.id_factura}&id_empresa=${this.idEmpresa}&autoprint=1`;
                window.open(url, '_blank');
            },
            
            formatMoney(n) {
                return Math.round(n || 0).toLocaleString('es-PY');
            },
            
            formatFecha(fecha) {
                if (!fecha) return '';
                const d = new Date(fecha);
                return d.toLocaleDateString('es-PY', { day: '2-digit', month: '2-digit', year: '2-digit' }) + 
                       ' ' + d.toLocaleTimeString('es-PY', { hour: '2-digit', minute: '2-digit' });
            },
            
            formatFechaLarga(fecha) {
                if (!fecha) return '';
                const d = new Date(fecha);
                return d.toLocaleDateString('es-PY', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
            },
            
            getTipoDoc(tipo) {
                const tipos = {
                    1: 'Nota',
                    2: 'Ticket',
                    3: 'Factura',
                    4: 'FE',
                    5: 'NC',
                    6: 'ND'
                };
                return tipos[tipo] || 'Doc';
            }
        };
    }
    </script>
</body>
</html>
