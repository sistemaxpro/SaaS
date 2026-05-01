<?php
/**
 * Gestión de Productos - Mobile
 * Vista de tarjetas, FAB para crear, bottom-sheet de detalle
 * Color accent: Cyan
 */

require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

Permission::requireAccess('app_grid_mercaderias');
$permisos = Permission::getAppPermissions('app_grid_mercaderias');

$id_empresa = Session::get('id_empresa', 169);
$id_login = Session::get('id_login');

$masterPdo = Database::getMasterConnection();
$stmtEmpresa = $masterPdo->prepare("SELECT empresa FROM empresa WHERE id_empresa = ?");
$stmtEmpresa->execute([$id_empresa]);
$empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es" x-data="productosMobile()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Productos - SistemaX</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
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
        body { -webkit-tap-highlight-color: transparent; overscroll-behavior: contain; }
        .bottom-sheet { transition: transform 0.3s cubic-bezier(0.32, 0.72, 0, 1); }
        .card-press:active { transform: scale(0.97); }
        .safe-bottom { padding-bottom: env(safe-area-inset-bottom, 20px); }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-900 min-h-screen font-sans safe-bottom">
<script>window.__PERMISOS__ = <?= json_encode($permisos) ?>;</script>
    
    <!-- Header fijo -->
    <header class="bg-cyan-600 dark:bg-cyan-800 sticky top-0 z-40 safe-top">
        <div class="flex items-center justify-between px-4 h-14">
            <div class="flex items-center gap-3">
                <div>
                    <h1 class="text-lg font-bold text-white">Productos</h1>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button @click="clearAppCache()" class="w-9 h-9 rounded-lg bg-white/20 flex items-center justify-center text-white active:scale-95" title="Limpiar caché">
                    <i class="fas fa-broom"></i>
                </button>
                <button @click="showFilters = !showFilters" class="w-9 h-9 rounded-lg bg-white/20 flex items-center justify-center text-white active:scale-95">
                    <i class="fas fa-filter"></i>
                </button>
            </div>
        </div>
        
        <!-- Search bar -->
        <div class="px-4 pb-3">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-cyan-300"></i>
                <input type="text" x-model="searchQuery"
                       @input.debounce.400ms="currentPage=1; loadProductos()"
                       placeholder="Buscar producto, código o código de barra..."
                       class="w-full pl-10 pr-4 py-2.5 bg-white/20 text-white placeholder-cyan-200 rounded-xl border-0 focus:ring-2 focus:ring-white/50 focus:bg-white/30 text-sm">
            </div>
        </div>
        
        <!-- Filtros desplegables -->
        <div x-show="showFilters" x-collapse class="px-4 pb-3 space-y-2">
            <div class="flex gap-2">
                <select x-model="filtroGrupo" @change="currentPage=1; loadProductos()"
                        class="flex-1 px-3 py-2 bg-white/20 text-white rounded-lg border-0 text-sm focus:ring-2 focus:ring-white/50">
                    <option value="" class="text-gray-900">Todos los grupos</option>
                    <template x-for="g in catGrupos" :key="g.id">
                        <option :value="g.id" x-text="g.grupo" class="text-gray-900"></option>
                    </template>
                </select>
                <select x-model="filtroEstado" @change="currentPage=1; loadProductos()"
                        class="w-28 px-3 py-2 bg-white/20 text-white rounded-lg border-0 text-sm focus:ring-2 focus:ring-white/50">
                    <option value="1" class="text-gray-900">Activos</option>
                    <option value="0" class="text-gray-900">Descontinuados</option>
                    <option value="" class="text-gray-900">Todos</option>
                </select>
            </div>
        </div>
    </header>
    
    <!-- Stats mini -->
    <div class="px-4 py-3">
        <div class="grid grid-cols-3 gap-2">
            <div class="bg-white dark:bg-slate-800 rounded-xl p-3 text-center border border-gray-200 dark:border-slate-700">
                <p class="text-lg font-bold text-gray-900 dark:text-white" x-text="stats.total"></p>
                <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Total</p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl p-3 text-center border border-gray-200 dark:border-slate-700">
                <p class="text-lg font-bold text-amber-600 dark:text-amber-400" x-text="stats.stockBajo"></p>
                <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Stock Bajo</p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl p-3 text-center border border-gray-200 dark:border-slate-700">
                <p class="text-lg font-bold text-red-600 dark:text-red-400" x-text="stats.sinStock"></p>
                <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase">Sin Stock</p>
            </div>
        </div>
    </div>
    
    <!-- Lista de productos (cards) -->
    <div class="px-4 pb-24 space-y-2">
        <!-- Loading -->
        <div x-show="loading" class="py-12 text-center">
            <i class="fas fa-spinner fa-spin text-3xl text-cyan-500 mb-3"></i>
            <p class="text-sm text-gray-500 dark:text-gray-400">Cargando...</p>
        </div>
        
        <!-- Cards -->
        <template x-for="p in productos" :key="p.idproducto">
            <div @click="onCardClick(p)" 
                 class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-3 flex items-center gap-3 card-press transition-transform cursor-pointer active:bg-gray-50 dark:active:bg-slate-750">
                <!-- Imagen/icono -->
                <div class="w-12 h-12 rounded-xl bg-gray-100 dark:bg-slate-700 flex items-center justify-center flex-shrink-0 overflow-hidden"
                     @touchstart.stop="startImageLongPress(p, $event)"
                     @touchend.stop="endImageLongPress()"
                     @touchmove.stop="cancelImageLongPress()"
                     @mousedown.stop="startImageLongPress(p, $event)"
                     @mouseup.stop="endImageLongPress()"
                     @mouseleave.stop="cancelImageLongPress()"
                     @contextmenu.prevent.stop="openImageContextMenu(p)">
                    <img x-show="(p.foto_small_url || p.foto_url)" :src="(p.foto_small_url || p.foto_url)" class="w-12 h-12 object-cover rounded-xl" @error="$el.style.display='none'">
                    <i x-show="!(p.foto_small_url || p.foto_url)" class="fas fa-cube text-gray-400 dark:text-slate-500 text-lg"></i>
                </div>
                <!-- Info -->
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white truncate" x-text="p.desproducto"></p>
                    <div class="flex items-center gap-2 mt-0.5">
                        <span class="text-xs text-gray-500 dark:text-gray-400 font-mono" x-text="p.cve_producto"></span>
                        <select x-show="p.codigos_barra?.length > 0" @click.stop
                                class="text-[10px] font-mono text-gray-500 dark:text-gray-400 bg-transparent border border-gray-200 dark:border-slate-600 rounded py-0 pr-4 cursor-pointer max-w-[100px] focus:ring-1 focus:ring-cyan-500">
                            <template x-for="(cb, i) in (p.codigos_barra || [])" :key="i">
                                <option x-text="cb"></option>
                            </template>
                        </select>
                        <span x-show="p.grupo_nombre" class="text-[10px] px-1.5 py-0.5 rounded-full bg-cyan-50 dark:bg-cyan-900/30 text-cyan-700 dark:text-cyan-300" x-text="p.grupo_nombre"></span>
                    </div>
                </div>
                <!-- Precio y Stock -->
                <div class="text-right flex-shrink-0">
                    <select x-show="p.precios?.length > 0" @click.stop
                            class="text-xs font-bold text-gray-900 dark:text-white bg-transparent border border-gray-200 dark:border-slate-600 rounded-lg py-0 pr-5 text-right cursor-pointer max-w-[140px] focus:ring-1 focus:ring-cyan-500">
                        <template x-for="pr in (p.precios || [])" :key="pr.tipo">
                            <option x-text="pr.tipo_nombre + ': ₲' + formatMoney(pr.precio)"></option>
                        </template>
                    </select>
                    <p x-show="!p.precios?.length" class="text-sm font-bold text-gray-900 dark:text-white" x-text="formatMoney(p.precio_venta)"></p>
                    <template x-if="p.controla_stock == 1">
                        <span>
                            <select x-show="p.stock_sucursales?.length > 1" @click.stop
                                    class="text-[10px] font-bold bg-transparent border border-gray-200 dark:border-slate-600 rounded-lg py-0 pr-5 text-right cursor-pointer max-w-[140px] focus:ring-1 focus:ring-cyan-500 mt-0.5"
                                    :class="stockClass(p)">
                                <option x-text="'Total: ' + formatNumber(p.saldo)"></option>
                                <template x-for="ss in (p.stock_sucursales || [])" :key="ss.id_sucursal">
                                    <option x-text="ss.sucursal + ': ' + formatNumber(ss.stock)"></option>
                                </template>
                            </select>
                            <p x-show="!p.stock_sucursales?.length || p.stock_sucursales?.length <= 1" class="text-xs font-semibold" :class="stockClass(p)" x-text="'Stock: ' + formatNumber(p.saldo)"></p>
                        </span>
                    </template>
                    <p x-show="p.controla_stock != 1" class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">N/controla stock</p>
                </div>
                <!-- Chevron -->
                <button x-show="permisos.priv_delete === 'Y' && canPermanentDelete(p)"
                        @click.stop="suprimir(p)"
                        class="w-7 h-7 rounded-lg bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 flex items-center justify-center active:scale-95"
                        title="Suprimir">
                    <i class="fas fa-trash text-[10px]"></i>
                </button>
                <i class="fas fa-chevron-right text-gray-300 dark:text-slate-600 text-xs"></i>
            </div>
        </template>
        
        <!-- Vacío -->
        <div x-show="!loading && productos.length === 0" class="py-12 text-center">
            <i class="fas fa-boxes text-5xl text-gray-300 dark:text-slate-600 mb-3"></i>
            <p class="text-gray-500 dark:text-gray-400">No se encontraron productos</p>
        </div>
        
        <!-- Cargar más -->
        <div x-show="currentPage < totalPages && !loading" class="py-4 text-center">
            <button @click="currentPage++; loadProductos(true)" 
                    class="px-6 py-2.5 bg-cyan-600 hover:bg-cyan-700 text-white rounded-xl text-sm font-medium active:scale-95 transition-all">
                <i class="fas fa-arrow-down mr-1"></i> Cargar más
            </button>
        </div>
    </div>
    
    <!-- FAB: Nuevo producto -->
    <button x-show="permisos.priv_insert === 'Y'" @click="nuevoProducto()"
            class="fixed bottom-6 right-6 w-14 h-14 bg-cyan-600 hover:bg-cyan-700 text-white rounded-2xl shadow-lg shadow-cyan-600/30 flex items-center justify-center active:scale-90 transition-all z-30">
        <i class="fas fa-plus text-xl"></i>
    </button>
    
    <!-- ============ BOTTOM SHEET: DETALLE ============ -->
    <div x-show="showDetalle" x-cloak class="fixed inset-0 z-50">
        <div class="fixed inset-0 bg-black/50" @click="showDetalle = false" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"></div>
        <div class="fixed inset-x-0 bottom-0 bottom-sheet bg-white dark:bg-slate-800 rounded-t-3xl shadow-xl max-h-[85vh] overflow-y-auto"
             x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
             x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full">
            <!-- Handle -->
            <div class="sticky top-0 bg-white dark:bg-slate-800 pt-3 pb-2 px-6 z-10 rounded-t-3xl">
                <div class="w-10 h-1 bg-gray-300 dark:bg-slate-600 rounded-full mx-auto mb-3"></div>
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white truncate pr-4" x-text="detalleProducto?.desproducto"></h3>
                    <button @click="showDetalle = false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>
            </div>
            
            <div class="px-6 pb-8 space-y-4">
                <!-- Info principal -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-gray-50 dark:bg-slate-700/50 rounded-xl p-3">
                        <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase font-medium">Código</p>
                        <p class="text-sm font-mono font-bold text-gray-900 dark:text-white" x-text="detalleProducto?.cve_producto"></p>
                        <template x-if="detalleProducto?.codigos_barra?.length > 0">
                            <div class="mt-2 pt-2 border-t border-gray-200 dark:border-slate-600 space-y-1">
                                <template x-for="(cb, i) in detalleProducto.codigos_barra" :key="i">
                                    <div class="flex items-center gap-1.5 text-xs">
                                        <i class="fas fa-barcode text-gray-400 dark:text-gray-500 text-[10px]"></i>
                                        <span class="font-mono text-gray-600 dark:text-gray-300" x-text="cb"></span>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                    <div class="bg-gray-50 dark:bg-slate-700/50 rounded-xl p-3">
                        <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase font-medium">Estado</p>
                        <span :class="(detalleProducto?.descontinuado || 0) == 1 ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400' : (detalleProducto?.Estado == 1 ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400')"
                              class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium mt-1"
                              x-text="(detalleProducto?.descontinuado || 0) == 1 ? 'Descontinuado' : (detalleProducto?.Estado == 1 ? 'Activo' : 'Inactivo')"></span>
                    </div>
                    <div class="bg-gray-50 dark:bg-slate-700/50 rounded-xl p-3">
                        <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase font-medium">Precio Compra</p>
                        <p class="text-sm font-bold text-gray-900 dark:text-white" x-text="'₲ ' + formatMoney(detalleProducto?.precio_compra)"></p>
                    </div>
                    <div class="bg-cyan-50 dark:bg-cyan-900/20 rounded-xl p-3">
                        <p class="text-[10px] text-cyan-600 dark:text-cyan-400 uppercase font-medium">Precio Venta</p>
                        <p class="text-sm font-bold text-cyan-700 dark:text-cyan-300" x-text="'₲ ' + formatMoney(detalleProducto?.precio_venta)"></p>
                        <template x-if="detalleProducto?.precios?.length > 0">
                            <div class="mt-2 pt-2 border-t border-cyan-200/60 dark:border-cyan-800/60 space-y-1">
                                <template x-for="pr in detalleProducto.precios" :key="pr.tipo">
                                    <div class="flex justify-between text-xs">
                                        <span class="text-cyan-600/80 dark:text-cyan-400/80" x-text="pr.tipo_nombre"></span>
                                        <span class="font-bold text-cyan-700 dark:text-cyan-300" x-text="'₲ ' + formatMoney(pr.precio)"></span>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
                
                <!-- Stock -->
                <div class="bg-gray-50 dark:bg-slate-700/50 rounded-xl p-3">
                    <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase font-medium mb-1">Stock</p>
                    <template x-if="detalleProducto?.controla_stock == 1">
                        <div>
                            <p class="text-2xl font-bold" :class="stockClass(detalleProducto || {})" x-text="formatNumber(detalleProducto?.saldo)"></p>
                            <template x-if="detalleProducto?.stock_sucursales?.length > 0">
                                <div class="mt-2 pt-2 border-t border-gray-200 dark:border-slate-600 space-y-1">
                                    <template x-for="ss in detalleProducto.stock_sucursales" :key="ss.id_sucursal">
                                        <div class="flex justify-between text-xs">
                                            <span class="text-gray-500 dark:text-gray-400" x-text="ss.sucursal"></span>
                                            <span class="font-bold" :class="ss.stock > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400'" x-text="formatNumber(ss.stock)"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                    <span x-show="detalleProducto?.controla_stock != 1"
                          class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-500 dark:bg-slate-600 dark:text-gray-400 mt-1">
                        N/controla stock
                    </span>
                </div>
                
                <!-- Detalles extra -->
                <div class="space-y-2 text-sm">
                    <div x-show="detalleProducto?.marca_nombre" class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                        <span class="text-gray-500 dark:text-gray-400">Marca</span>
                        <span class="text-gray-900 dark:text-white font-medium" x-text="detalleProducto?.marca_nombre"></span>
                    </div>
                    <div x-show="detalleProducto?.modelo_nombre" class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                        <span class="text-gray-500 dark:text-gray-400">Modelo</span>
                        <span class="text-gray-900 dark:text-white font-medium" x-text="detalleProducto?.modelo_nombre"></span>
                    </div>
                    <div x-show="detalleProducto?.grupo_nombre" class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                        <span class="text-gray-500 dark:text-gray-400">Grupo</span>
                        <span class="text-gray-900 dark:text-white font-medium" x-text="detalleProducto?.grupo_nombre"></span>
                    </div>
                    <div x-show="detalleProducto?.capacidad" class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                        <span class="text-gray-500 dark:text-gray-400">Capacidad</span>
                        <span class="text-gray-900 dark:text-white font-medium" x-text="detalleProducto?.capacidad"></span>
                    </div>
                    <div x-show="detalleProducto?.ram" class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                        <span class="text-gray-500 dark:text-gray-400">RAM</span>
                        <span class="text-gray-900 dark:text-white font-medium" x-text="detalleProducto?.ram"></span>
                    </div>
                    <div x-show="detalleProducto?.condicion_producto" class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                        <span class="text-gray-500 dark:text-gray-400">Condición</span>
                        <span class="text-gray-900 dark:text-white font-medium capitalize" x-text="detalleProducto?.condicion_producto"></span>
                    </div>
                    <div x-show="(detalleProducto?.garantia_meses || 0) > 0" class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                        <span class="text-gray-500 dark:text-gray-400">Garantía</span>
                        <span class="text-gray-900 dark:text-white font-medium" x-text="detalleProducto?.garantia_meses + ' meses'"></span>
                    </div>
                    <div x-show="detalleProducto?.ubicacion_fisica" class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                        <span class="text-gray-500 dark:text-gray-400">Ubicación</span>
                        <span class="text-gray-900 dark:text-white font-medium text-right" x-text="detalleProducto?.ubicacion_fisica"></span>
                    </div>
                    <div x-show="detalleProducto?.proveedor_principal" class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                        <span class="text-gray-500 dark:text-gray-400">Proveedor</span>
                        <span class="text-gray-900 dark:text-white font-medium text-right" x-text="detalleProducto?.proveedor_principal"></span>
                    </div>
                    <div x-show="detalleProducto?.codigo_barra" class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                        <span class="text-gray-500 dark:text-gray-400">Código de Barra</span>
                        <span class="text-gray-900 dark:text-white font-mono text-xs" x-text="detalleProducto?.codigo_barra"></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-100 dark:border-slate-700">
                        <span class="text-gray-500 dark:text-gray-400">IVA</span>
                        <span class="text-gray-900 dark:text-white font-medium" x-text="detalleProducto?.iva == 1 ? '10%' : detalleProducto?.iva == 2 ? '5%' : 'Exenta'"></span>
                    </div>
                    <div x-show="detalleProducto?.compatibilidad" class="py-2 border-b border-gray-100 dark:border-slate-700">
                        <span class="block text-gray-500 dark:text-gray-400 mb-1">Compatibilidad</span>
                        <span class="block text-gray-900 dark:text-white font-medium" x-text="detalleProducto?.compatibilidad"></span>
                    </div>
                    <div x-show="detalleProducto?.incluye" class="py-2 border-b border-gray-100 dark:border-slate-700">
                        <span class="block text-gray-500 dark:text-gray-400 mb-1">Incluye</span>
                        <span class="block text-gray-900 dark:text-white font-medium" x-text="detalleProducto?.incluye"></span>
                    </div>
                </div>
                
                <!-- Acciones -->
                <div class="grid grid-cols-2 gap-3 pt-2">
                    <button x-show="permisos.priv_update === 'Y'" @click="editarProducto(detalleProducto?.idproducto)"
                            class="py-3 bg-cyan-600 hover:bg-cyan-700 text-white rounded-xl text-sm font-semibold active:scale-95 transition-all flex items-center justify-center gap-2">
                        <i class="fas fa-edit"></i> Editar
                    </button>
                    <button x-show="permisos.priv_delete === 'Y' && canPermanentDelete(detalleProducto)" @click="suprimir(detalleProducto)"
                            class="py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl text-sm font-semibold active:scale-95 transition-all flex items-center justify-center gap-2">
                        <i class="fas fa-trash"></i> Suprimir
                    </button>
                    <button x-show="detalleProducto?.Estado == 1 && permisos.priv_delete === 'Y'" @click="desactivar(detalleProducto)"
                            class="py-3 bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 rounded-xl text-sm font-semibold active:scale-95 transition-all flex items-center justify-center gap-2">
                        <i class="fas fa-ban"></i> Descontinuar
                    </button>
                    <button x-show="detalleProducto?.Estado == 0" @click="activar(detalleProducto)"
                            class="py-3 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-xl text-sm font-semibold active:scale-95 transition-all flex items-center justify-center gap-2">
                        <i class="fas fa-check"></i> Activar
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============ BOTTOM SHEET: CREAR/EDITAR ============ -->
    <div x-show="showForm" x-cloak class="fixed inset-0 z-50">
        <div class="fixed inset-0 bg-black/50" @click="closeFormModal()"></div>
        <div class="fixed inset-x-0 bottom-0 bottom-sheet bg-white dark:bg-slate-800 rounded-t-3xl shadow-xl max-h-[92vh] overflow-y-auto"
             x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
             x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full">
            <!-- Handle -->
            <div class="sticky top-0 bg-white dark:bg-slate-800 pt-2 pb-1.5 px-4 z-10 rounded-t-3xl border-b border-gray-200 dark:border-slate-700">
                <div class="w-10 h-1 bg-gray-300 dark:bg-slate-600 rounded-full mx-auto mb-2"></div>
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-bold text-gray-900 dark:text-white" x-text="form.idproducto ? 'Editar Producto' : 'Nuevo Producto'"></h3>
                    <button @click="closeFormModal()" class="w-7 h-7 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500">
                        <i class="fas fa-times text-xs"></i>
                    </button>
                </div>
            </div>
            
            <div class="px-4 py-3 space-y-2.5">
                <!-- Foto del producto (inicio: nuevo + editar) -->
                <div class="border border-gray-200 dark:border-slate-700 rounded-xl p-2.5 space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="text-[11px] font-semibold text-gray-600 dark:text-gray-300 flex items-center gap-1">
                            <i class="fas fa-camera text-cyan-600 text-[10px]"></i> Foto del producto
                        </label>
                        <span x-show="pendingProductImagePayload && !form.idproducto" class="text-[10px] text-amber-600 dark:text-amber-400">
                            Pendiente
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="w-16 h-16 rounded-lg overflow-hidden border border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700 flex items-center justify-center"
                             @touchstart.stop="startImageLongPress({ idproducto: Number(form?.idproducto || 0), desproducto: String(form?.desproducto || 'producto') }, $event)"
                             @touchend.stop="endImageLongPress()"
                             @touchmove.stop="cancelImageLongPress()"
                             @mousedown.stop="startImageLongPress({ idproducto: Number(form?.idproducto || 0), desproducto: String(form?.desproducto || 'producto') }, $event)"
                             @mouseup.stop="endImageLongPress()"
                             @mouseleave.stop="cancelImageLongPress()"
                             @contextmenu.prevent.stop="openImageContextMenu({ idproducto: Number(form?.idproducto || 0), desproducto: String(form?.desproducto || 'producto') })">
                            <img x-show="getFormMainImage()"
                                 :src="getFormMainImage()"
                                 class="w-16 h-16 object-cover"
                                 @error="$el.style.display='none'">
                            <i x-show="!getFormMainImage()" class="fas fa-image text-gray-400 dark:text-slate-500"></i>
                        </div>
                        <div class="flex-1">
                            <button type="button"
                                    @click="formCamaraImage()"
                                    class="h-9 px-3 rounded-lg bg-cyan-600 hover:bg-cyan-700 text-white text-[11px] font-medium flex items-center justify-center gap-1.5">
                                <i class="fas fa-camera text-[10px]"></i> Camara
                            </button>
                        </div>
                    </div>
                    <p x-show="!form.idproducto && pendingProductImagePayload" class="text-[10px] text-gray-500 dark:text-gray-400">
                        Se subirá a R2 al guardar el producto.
                    </p>
                </div>

                <!-- Descripción -->
                <div>
                    <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">Descripción *</label>
                    <div class="flex items-center gap-2">
                        <input type="text" x-model="form.desproducto" required maxlength="200"
                               class="flex-1 px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm">
                        <button type="button"
                                @click="toggleDescriptionVoiceDictation()"
                                :disabled="!speechSupported"
                                class="h-10 min-w-10 px-3 rounded-lg text-white text-xs font-semibold flex items-center justify-center gap-1 disabled:opacity-40 disabled:cursor-not-allowed"
                                :class="speechListening ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700'"
                                :title="speechSupported ? (speechListening ? 'Detener dictado' : 'Dictar descripción') : 'Micrófono no soportado'">
                            <i class="fas" :class="speechListening ? 'fa-microphone-slash' : 'fa-microphone'"></i>
                            <span x-text="speechListening ? 'Detener' : 'Mic'"></span>
                        </button>
                    </div>
                    <p class="mt-1 text-[10px] text-gray-500 dark:text-gray-400" x-show="speechSupported">Habla para completar la descripción.</p>
                    <p class="mt-1 text-[10px] text-amber-600 dark:text-amber-400" x-show="!speechSupported">Micrófono no disponible en este navegador.</p>
                </div>
                
                <div class="grid grid-cols-12 gap-2">
                    <div class="col-span-4">
                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">Código</label>
                        <input type="text" x-model="form.cve_producto" placeholder="Auto"
                               class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-xs font-mono">
                    </div>
                    <div class="col-span-8">
                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">Código de Barra</label>
                        <div class="relative">
                            <input type="text" x-model="form.codigo_barra"
                                   class="w-full px-2.5 pr-10 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-xs font-mono">
                            <button type="button"
                                    @click="openBarcodeScannerForm()"
                                    class="absolute right-1 top-1/2 -translate-y-1/2 h-7 w-7 rounded-md bg-emerald-600 hover:bg-emerald-700 text-white text-xs flex items-center justify-center"
                                    title="Escanear código de barra">
                                <i class="fas fa-barcode text-[10px]"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Códigos de barra adicionales -->
                <div class="space-y-1.5">
                    <template x-for="(cb, idx) in form.codigos_barra_extra" :key="idx">
                        <div class="flex items-center gap-1.5">
                            <input type="text" x-model="cb.codigo" placeholder="Código adicional"
                                   class="flex-1 px-2.5 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-xs font-mono">
                            <button type="button" @click="eliminarCodigoBarra(idx)"
                                    class="w-7 h-7 rounded-md bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 flex items-center justify-center hover:bg-red-200 dark:hover:bg-red-900/50 transition-colors">
                                <i class="fas fa-trash-alt text-[10px]"></i>
                            </button>
                        </div>
                    </template>
                    <button type="button" @click="agregarCodigoBarra()"
                            class="flex items-center gap-1 text-[11px] text-cyan-600 dark:text-cyan-400 hover:text-cyan-700 dark:hover:text-cyan-300 font-medium">
                        <i class="fas fa-plus-circle text-[10px]"></i> Agregar código de barra
                    </button>
                </div>
                
                <!-- Precios -->
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">P. Compra</label>
                        <input type="number" x-model.number="form.precio_compra" min="0" @focus="$event.target.select()"
                               class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm text-right">
                    </div>
                    <div>
                        <div class="flex items-center justify-between mb-0.5">
                            <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400">P. Venta</label>
                            <button type="button" @click="abrirCatModal('precios', 'Tipos de Precio')" class="text-[10px] text-cyan-600 dark:text-cyan-400 hover:underline flex items-center gap-0.5"><i class="fas fa-cog text-[8px]"></i> Gestionar</button>
                        </div>
                        <input type="number" x-model.number="form.precio_venta" min="0" @focus="$event.target.select()"
                               class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-sm text-right">
                    </div>
                </div>
                
                <!-- Grupo / IVA -->
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <div class="flex items-center justify-between mb-0.5">
                            <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400">Grupo</label>
                            <button type="button" @click="abrirCatModal('grupos', 'Grupos')" class="text-[10px] text-cyan-600 dark:text-cyan-400 hover:underline flex items-center gap-0.5"><i class="fas fa-cog text-[8px]"></i> Gestionar</button>
                        </div>
                        <select x-model="form.grupo"
                                class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs">
                            <option value="0">Sin grupo</option>
                            <template x-for="g in catGrupos" :key="g.id">
                                <option :value="g.id" x-text="g.grupo"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">IVA</label>
                        <select x-model="form.iva"
                                class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs">
                            <option value="1">10%</option>
                            <option value="2">5%</option>
                            <option value="3">Exenta</option>
                        </select>
                    </div>
                </div>
                
                <!-- Marca / Unidad -->
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <div class="flex items-center justify-between mb-0.5">
                            <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400">Marca</label>
                            <button type="button" @click="abrirCatModal('marcas', 'Marcas')" class="text-[10px] text-cyan-600 dark:text-cyan-400 hover:underline flex items-center gap-0.5"><i class="fas fa-cog text-[8px]"></i> Gestionar</button>
                        </div>
                        <select x-model="form.marca"
                                class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs">
                            <option value="0">Sin marca</option>
                            <template x-for="m in catMarcas" :key="m.id">
                                <option :value="m.id" x-text="m.marca"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <div class="flex items-center justify-between mb-0.5">
                            <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400">Unidad</label>
                            <button type="button" @click="abrirCatModal('medidas', 'Unidades de Medida')" class="text-[10px] text-cyan-600 dark:text-cyan-400 hover:underline flex items-center gap-0.5"><i class="fas fa-cog text-[8px]"></i> Gestionar</button>
                        </div>
                        <select x-model="form.unidad_medida"
                                class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs">
                            <option value="">Unidad</option>
                            <template x-for="u in unidades" :key="u">
                                <option :value="u" x-text="u"></option>
                            </template>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">Capacidad</label>
                        <input type="text" x-model="form.capacidad" placeholder="128GB"
                               class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs">
                    </div>
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">RAM</label>
                        <input type="text" x-model="form.ram" placeholder="8GB"
                               class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">Garantía</label>
                        <input type="number" x-model.number="form.garantia_meses" min="0" placeholder="0"
                               class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs text-right">
                    </div>
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">Condición</label>
                        <select x-model="form.condicion_producto"
                                class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs">
                            <option value="nuevo">Nuevo</option>
                            <option value="usado">Usado</option>
                            <option value="reacondicionado">Reacondicionado</option>
                            <option value="exhibicion">Exhibición</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">Proveedor</label>
                        <input type="text" x-model="form.proveedor_principal" placeholder="Proveedor principal"
                               class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs">
                    </div>
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">Ubicación</label>
                        <input type="text" x-model="form.ubicacion_fisica" placeholder="Vitrina A"
                               class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs">
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">Compatibilidad</label>
                    <textarea x-model="form.compatibilidad" rows="2" placeholder="Modelos compatibles o notas técnicas"
                              class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs resize-none"></textarea>
                </div>

                <div>
                    <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">Incluye</label>
                    <textarea x-model="form.incluye" rows="2" placeholder="Caja, cargador, cable..."
                              class="w-full px-2.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs resize-none"></textarea>
                </div>
                
                <!-- Stock Mín/Máx + Inicial -->
                <div class="grid" :class="form.idproducto ? 'grid-cols-2 gap-2' : 'grid-cols-4 gap-2'">
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">Stk Mín</label>
                        <input type="number" x-model.number="form.stock_minimo" min="0" @focus="$event.target.select()"
                               class="w-full px-2 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs text-right">
                    </div>
                    <div>
                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">Stk Máx</label>
                        <input type="number" x-model.number="form.stock_maximo" min="0" @focus="$event.target.select()"
                               class="w-full px-2 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs text-right">
                    </div>
                    <div x-show="!form.idproducto">
                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">Stk Inicial</label>
                        <input type="number" x-model.number="form.stock_inicial" min="0" @focus="$event.target.select()"
                               class="w-full px-2 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-cyan-500 text-xs text-right">
                    </div>
                    <div x-show="!form.idproducto">
                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-0.5">Sucursal</label>
                        <select x-model.number="form.id_sucursal"
                                class="w-full px-1.5 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs">
                            <template x-for="s in sucursalesList" :key="s.id_sucursal">
                                <option :value="s.id_sucursal" x-text="s.sucursal"></option>
                            </template>
                        </select>
                    </div>
                </div>
                
                <!-- Flags -->
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="form.controla_stock" :true-value="1" :false-value="-1" class="rounded border-gray-300 text-cyan-600 focus:ring-cyan-500 w-4 h-4">
                        <span class="text-xs text-gray-700 dark:text-gray-300">Controla stock</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="form.edita_precio" :true-value="1" :false-value="-1" class="rounded border-gray-300 text-cyan-600 focus:ring-cyan-500 w-4 h-4">
                        <span class="text-xs text-gray-700 dark:text-gray-300">Edita precio en venta</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="form.usaserial" :true-value="1" :false-value="-1" class="rounded border-gray-300 text-cyan-600 focus:ring-cyan-500 w-4 h-4">
                        <span class="text-xs text-gray-700 dark:text-gray-300">Usa serial / IMEI</span>
                    </label>
                </div>

                <div class="space-y-3">
                    <div class="rounded-xl border border-cyan-100 dark:border-cyan-900/40 bg-cyan-50/70 dark:bg-cyan-900/10 px-3 py-2 text-xs text-cyan-800 dark:text-cyan-200">
                        Para celulares y equipos con identificacion unica, activá <span class="font-semibold">Usa serial / IMEI</span> y cargá aquí las unidades.
                    </div>
                    <div x-show="Number(form.usaserial || -1) === 1" class="space-y-3">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-semibold text-gray-700 dark:text-gray-300">Series / IMEI</h4>
                            <button type="button" @click="agregarSerie()"
                                    class="text-cyan-600 hover:text-cyan-700 text-xs font-medium inline-flex items-center gap-1">
                                <i class="fas fa-plus text-[10px]"></i> Agregar
                            </button>
                        </div>
                        <template x-for="(serie, idx) in form.series" :key="'serie-mobile-' + idx">
                            <div class="rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800/70 p-3 space-y-2">
                                <div>
                                    <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-1">Serie / IMEI</label>
                                    <input type="text" x-model.trim="serie.serie" @blur="serie.serie = normalizeSerieValue(serie.serie)"
                                           placeholder="Ingresar serial o IMEI"
                                           class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs focus:ring-2 focus:ring-cyan-500">
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-1">Tipo</label>
                                        <select x-model="serie.tipo"
                                                class="w-full px-2 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs">
                                            <option value="SERIAL">Serial</option>
                                            <option value="IMEI">IMEI</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-1">Estado</label>
                                        <select x-model.number="serie.estado"
                                                class="w-full px-2 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs">
                                            <option :value="1">Disponible</option>
                                            <option :value="0">Salida / Inactivo</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-[11px] font-medium text-gray-500 dark:text-gray-400 mb-1">Observación</label>
                                    <input type="text" x-model.trim="serie.obs" placeholder="Color, lote, nota interna..."
                                           class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-xs focus:ring-2 focus:ring-cyan-500">
                                </div>
                                <div class="flex justify-end">
                                    <button type="button" @click="form.series.splice(idx, 1)"
                                            class="px-3 py-2 rounded-lg bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:hover:bg-red-900/40 text-red-500 text-xs inline-flex items-center gap-1">
                                        <i class="fas fa-trash text-[10px]"></i> Quitar
                                    </button>
                                </div>
                            </div>
                        </template>
                        <div x-show="!form.series.length" class="p-3 text-xs text-gray-500 dark:text-gray-400 rounded-lg border border-dashed border-gray-300 dark:border-slate-600">
                            Sin series cargadas.
                        </div>
                    </div>
                </div>

                <!-- Botón guardar -->
                <button @click="guardar()" :disabled="saving"
                        class="w-full py-2.5 bg-cyan-600 hover:bg-cyan-700 disabled:opacity-50 text-white rounded-lg font-semibold text-sm active:scale-[0.98] transition-all flex items-center justify-center gap-2">
                    <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                    <span x-text="saving ? 'Guardando...' : 'Guardar Producto'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal lector de código de barras (Formulario Producto) -->
    <!-- Modal Gestión de Catálogos -->
    <div x-show="showCatModal" x-cloak class="fixed inset-0 z-[70] bg-black/60 flex items-end sm:items-center justify-center" @click.self="showCatModal = false">
        <div class="w-full max-w-md bg-white dark:bg-slate-800 rounded-t-2xl sm:rounded-2xl max-h-[80vh] flex flex-col border border-slate-200 dark:border-slate-700"
             @click.stop>
            <!-- Header -->
            <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between flex-shrink-0">
                <h3 class="font-semibold text-slate-800 dark:text-slate-100 text-sm" x-text="'Gestionar ' + catModalTitulo"></h3>
                <button @click="showCatModal = false" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-700 flex items-center justify-center text-slate-500 hover:bg-slate-200 dark:hover:bg-slate-600">
                    <i class="fas fa-times text-xs"></i>
                </button>
            </div>
            <!-- Agregar nuevo -->
            <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-700 flex-shrink-0">
                <div class="flex gap-2">
                    <input type="text" x-model="catModalNuevo" placeholder="Nuevo nombre..." @keydown.enter="crearCatItem()"
                           class="flex-1 px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500">
                    <button @click="crearCatItem()" :disabled="!catModalNuevo.trim() || catModalSaving"
                            class="px-4 py-2 bg-cyan-600 hover:bg-cyan-700 disabled:opacity-50 text-white rounded-xl text-sm font-medium">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
            <!-- Lista -->
            <div class="overflow-y-auto flex-1 px-4 py-2">
                <div x-show="catModalItems.length === 0" class="py-6 text-center text-gray-400 dark:text-gray-500 text-sm">
                    <i class="fas fa-inbox text-2xl mb-2"></i>
                    <p>Sin registros</p>
                </div>
                <template x-for="item in catModalItems" :key="item.id">
                    <div class="flex items-center gap-2 py-2 border-b border-gray-100 dark:border-slate-700 last:border-0">
                        <!-- Modo visualización -->
                        <template x-if="catModalEditId !== item.id">
                            <div class="flex items-center gap-2 w-full">
                                <span class="flex-1 text-sm text-gray-800 dark:text-gray-200" x-text="item.nombre"></span>
                                <button @click="iniciarEditCat(item)" class="w-7 h-7 rounded-lg text-cyan-600 dark:text-cyan-400 hover:bg-cyan-50 dark:hover:bg-cyan-900/30 flex items-center justify-center" title="Editar">
                                    <i class="fas fa-pen text-[10px]"></i>
                                </button>
                                <button @click="eliminarCatItem(item)" class="w-7 h-7 rounded-lg text-red-500 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 flex items-center justify-center" title="Eliminar">
                                    <i class="fas fa-trash-alt text-[10px]"></i>
                                </button>
                            </div>
                        </template>
                        <!-- Modo edición -->
                        <template x-if="catModalEditId === item.id">
                            <div class="flex items-center gap-2 w-full">
                                <input type="text" x-model="catModalEditNombre" @keydown.enter="guardarEditCat()" @keydown.escape="cancelarEditCat()"
                                       class="flex-1 px-2 py-1.5 border border-cyan-400 dark:border-cyan-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500"
                                       x-init="$nextTick(() => $el.focus())">
                                <button @click="guardarEditCat()" class="w-7 h-7 rounded-lg text-green-600 hover:bg-green-50 dark:hover:bg-green-900/30 flex items-center justify-center">
                                    <i class="fas fa-check text-xs"></i>
                                </button>
                                <button @click="cancelarEditCat()" class="w-7 h-7 rounded-lg text-gray-400 hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center justify-center">
                                    <i class="fas fa-times text-xs"></i>
                                </button>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <div x-show="showBarcodeScannerForm" x-cloak class="fixed inset-0 z-[70] bg-black/80 flex items-center justify-center p-3">
        <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-800 overflow-hidden border border-slate-200 dark:border-slate-700">
            <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                <h3 class="font-semibold text-slate-800 dark:text-slate-100">Escanear Código de Barra</h3>
                <button @click="closeBarcodeScannerForm()" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-700">
                    <i class="fas fa-times text-slate-500"></i>
                </button>
            </div>
            <div class="p-3 space-y-2">
                <div class="relative rounded-xl overflow-hidden bg-black aspect-video">
                    <video x-ref="barcodeVideoForm" autoplay playsinline muted class="w-full h-full object-cover"></video>
                    <div class="absolute inset-x-8 top-1/2 -translate-y-1/2 border-2 border-emerald-400/90 rounded-lg h-14"></div>
                </div>
                <p class="text-[11px] text-slate-600 dark:text-slate-300">Enfoca el código dentro del recuadro.</p>
                <p x-show="barcodeScannerErrorForm" class="text-[11px] text-red-600 dark:text-red-400" x-text="barcodeScannerErrorForm"></p>
            </div>
        </div>
    </div>

    <div x-show="showImageContextMenu" x-cloak class="fixed inset-0 z-[76] bg-black/55 flex items-end sm:items-center sm:justify-center" @click.self="closeImageContextMenu()">
        <div class="w-full sm:max-w-sm bg-white dark:bg-slate-800 rounded-t-2xl sm:rounded-2xl border border-slate-200 dark:border-slate-700 p-4 space-y-2">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Imagen de Producto</h3>
                <button @click="closeImageContextMenu()" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-500">
                    <i class="fas fa-times text-xs"></i>
                </button>
            </div>
            <p class="text-[11px] text-slate-600 dark:text-slate-300" x-text="imageContextProduct?.desproducto || ''"></p>
            <p class="text-[11px] text-slate-500 dark:text-slate-400">Opciones: Bing catálogo, Google Image o cámara</p>
            <button @click="obtenerFotoBingCatalogo()"
                    class="w-full h-10 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold flex items-center justify-center gap-2">
                <i class="fab fa-microsoft"></i> Bing catálogo
            </button>
            <button @click="obtenerFotoGoogleImagenes()"
                    class="w-full h-10 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold flex items-center justify-center gap-2">
                <i class="fab fa-google"></i> Google Image
            </button>
            <button @click="obtenerFotoCamara()"
                    class="w-full h-10 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-semibold flex items-center justify-center gap-2">
                <i class="fas fa-camera"></i> Camara
            </button>
        </div>
    </div>

    <div x-show="showProductCameraForm" x-cloak class="fixed inset-0 z-[77] bg-black/85 flex items-center justify-center p-0">
        <div class="w-[95vw] h-[95vh] rounded-2xl bg-white dark:bg-slate-800 overflow-hidden border border-slate-200 dark:border-slate-700 flex flex-col">
            <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                <h3 class="font-semibold text-slate-800 dark:text-slate-100">Capturar Foto del Producto</h3>
                <button @click="closeProductCameraForm()" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-700">
                    <i class="fas fa-times text-slate-500"></i>
                </button>
            </div>
            <div class="p-3 space-y-2 flex-1 flex flex-col min-h-0">
                <div class="relative rounded-xl overflow-hidden bg-black flex-1 min-h-0">
                    <video x-ref="productCameraVideo" autoplay playsinline muted class="w-full h-full object-cover"></video>
                </div>
                <p class="text-[11px] text-slate-600 dark:text-slate-300">Enfoca el producto y presiona Capturar.</p>
                <p x-show="productCameraError" class="text-[11px] text-red-600 dark:text-red-400" x-text="productCameraError"></p>
                <div class="flex gap-2">
                    <button @click="captureProductFromCamera()"
                            class="flex-1 h-10 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-semibold">
                        <i class="fas fa-camera"></i> Capturar
                    </button>
                    <button @click="closeProductCameraForm()"
                            class="px-4 h-10 rounded-xl bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-semibold">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div x-show="showGoogleImageForm" x-cloak class="fixed inset-0 z-[78] bg-black/70 flex items-end sm:items-center sm:justify-center" @click.self="closeGoogleImageForm()">
        <div class="w-full sm:max-w-md bg-white dark:bg-slate-800 rounded-t-2xl sm:rounded-2xl border border-slate-200 dark:border-slate-700 p-4 space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Subir Foto desde Google Image</h3>
                <button @click="closeGoogleImageForm()" class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-500">
                    <i class="fas fa-times text-xs"></i>
                </button>
            </div>
            <p class="text-[11px] text-slate-600 dark:text-slate-300">Puede usar <b>Descargas</b> o pegar URL directa. Al pegar URL se guarda en G-Drive.</p>
            <input type="url"
                   x-ref="googleImageInput"
                   x-model.trim="googleImageUrl"
                   @paste.prevent="handleGoogleImagePasteAndSave($event)"
                   placeholder="Pegue URL directa de imagen (https://...jpg/png/webp)"
                   class="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-cyan-500">
            <p x-show="googleImageError" class="text-[11px] text-red-600 dark:text-red-400" x-text="googleImageError"></p>
            <p x-show="googleImageHint" class="text-[11px] text-cyan-700 dark:text-cyan-300" x-text="googleImageHint"></p>
            <div class="flex gap-2">
                <button @click="pasteGoogleImageUrl()"
                        class="flex-1 h-10 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold">
                    <i class="fas fa-paste"></i> Pegar y Guardar
                </button>
                <button @click="openGoogleImageGallery()"
                        class="flex-1 h-10 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold">
                    <i class="fas fa-folder-open"></i> Descargas
                </button>
            </div>
            <input type="file"
                   x-ref="googleImageFileInput"
                   accept="image/*"
                   class="absolute -left-[9999px] w-px h-px opacity-0"
                   @click="$event.target.value = ''"
                   @change="handleGoogleImageFile($event)">
        </div>
    </div>

    <!-- Toast -->
    <div x-show="toast.show" x-cloak
         x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed bottom-20 left-4 right-4 z-[60]">
        <div class="flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg border"
             :class="toast.type === 'success' ? 'bg-green-50 dark:bg-green-900/40 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300' : 'bg-red-50 dark:bg-red-900/40 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300'">
            <i class="fas" :class="toast.type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'"></i>
            <span class="text-sm font-medium" x-text="toast.message"></span>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script>
    function productosMobile() {
        return {
            idEmpresa: <?= $id_empresa ?>,
            isDark: document.documentElement.classList.contains('dark'),
            permisos: window.__PERMISOS__ || {},
            API: '/public/productos/api',
            
            productos: [],
            stats: { total: 0, activos: 0, stockBajo: 0, sinStock: 0 },
            catGrupos: [],
            catMarcas: [],
            unidades: ['UNIDAD','CAJA','LITRO','BOLSA','PACK','METRO','KILO','TONELADA','DOCENA','COMBO'],
            
            // Gestión catálogos
            showCatModal: false,
            catModalTipo: '',
            catModalTitulo: '',
            catModalItems: [],
            catModalNuevo: '',
            catModalEditId: null,
            catModalEditNombre: '',
            catModalSaving: false,
            sucursalesList: [],
            
            searchQuery: '',
            filtroGrupo: '',
            filtroEstado: '1',
            showFilters: false,
            
            currentPage: 1,
            perPage: 20,
            totalPages: 1,
            
            loading: true,
            saving: false,
            showDetalle: false,
            showForm: false,
            detalleProducto: null,
            form: {},
            
            toast: { show: false, message: '', type: 'success' },
            showImageContextMenu: false,
            imageContextProduct: null,
            imageLongPressTimer: null,
            imageLongPressTriggered: false,
            imageActionProduct: null,
            showProductCameraForm: false,
            productCameraStream: null,
            productCameraError: '',
            pendingProductImagePayload: null,
            pendingProductImagePreviewUrl: '',
            showGoogleImageForm: false,
            googleImageUrl: '',
            googleImageError: '',
            googleImageHint: '',
            googleImagePasting: false,
            speechSupported: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
            speechListening: false,
            speechRecognition: null,
            speechBaseDescription: '',
            speechFinalByIndex: {},
            descriptionMaxLength: 200,
            showBarcodeScannerForm: false,
            barcodeScannerErrorForm: '',
            barcodeStreamForm: null,
            barcodeDetectorForm: null,
            barcodeDetectActiveForm: false,
            cameraPermissionState: localStorage.getItem('sx_camera_perm_state') || 'prompt',
            offlineKey(suffix) {
                return `sx_productos_mobile_offline:${this.idEmpresa}:${suffix}`;
            },
            readOfflineCache(suffix, fallback = null) {
                return window.SmxOfflineDb?.getSync(this.offlineKey(suffix), fallback) ?? fallback;
            },
            writeOfflineCache(suffix, data) {
                return window.SmxOfflineDb?.set(this.offlineKey(suffix), data);
            },
            mergeOfflineProductos(items, stats = null) {
                const snapshot = this.readOfflineCache('productos_snapshot', { items: [], stats: this.stats }) || { items: [], stats: this.stats };
                const map = new Map((Array.isArray(snapshot.items) ? snapshot.items : []).map((item) => [Number(item.idproducto || 0), item]));
                (Array.isArray(items) ? items : []).forEach((item) => {
                    const id = Number(item?.idproducto || 0);
                    if (id > 0) map.set(id, item);
                });
                this.writeOfflineCache('productos_snapshot', {
                    items: Array.from(map.values()),
                    stats: stats || snapshot.stats || this.stats,
                    saved_at: new Date().toISOString()
                });
            },
            async warmOfflineSearchIndex(force = false) {
                if (!navigator.onLine) return;
                const meta = this.readOfflineCache('productos_index_meta', null);
                if (!force && meta?.saved_at) {
                    const ageMs = Date.now() - new Date(meta.saved_at).getTime();
                    if (Number.isFinite(ageMs) && ageMs < 1000 * 60 * 30) return;
                }
                try {
                    const params = new URLSearchParams({
                        id_empresa: this.idEmpresa,
                        page: '1',
                        per_page: '250',
                        search: '',
                        grupo: '',
                        estado: '',
                        sort_by: 'desproducto',
                        sort_dir: 'ASC'
                    });
                    const res = await fetch(`${this.API}/list.php?${params}`);
                    const data = await res.json();
                    if (!data.success) return;
                    const normalized = (data.data || []).map((p) => ({
                        ...p,
                        foto_thumb_url: p.foto_thumb_url || p.foto_url || '',
                        foto_small_url: p.foto_small_url || p.foto_url || '',
                        foto_medium_url: p.foto_medium_url || p.foto_url || '',
                        foto_large_url: p.foto_large_url || p.foto_url || ''
                    }));
                    this.mergeOfflineProductos(normalized, data.stats || this.stats);
                    this.writeOfflineCache('productos_index_meta', {
                        saved_at: new Date().toISOString(),
                        total: Number(data.pagination?.total || 0)
                    });
                } catch (_) {}
            },
            filterOfflineProductos(items) {
                const search = String(this.searchQuery || '').trim().toLowerCase();
                const grupo = String(this.filtroGrupo || '').trim();
                const estado = String(this.filtroEstado || '').trim();
                return (Array.isArray(items) ? items : []).filter((p) => {
                    if (grupo !== '' && String(p?.grupo || p?.grupo_nombre || '') !== grupo) return false;
                    if (estado !== '' && String(p?.Estado ?? p?.estado ?? '') !== estado) return false;
                    if (!search) return true;
                    const haystack = [
                        p?.desproducto, p?.descripcion, p?.cve_producto, p?.codigo_barra,
                        p?.grupo, p?.grupo_nombre, p?.marca, p?.marca_nombre, p?.modelo_nombre
                    ].join(' ').toLowerCase();
                    return haystack.includes(search);
                });
            },
            
            // ── Imágenes R2 ──
            imgList: [],
            imgUploading: false,
            imgError: '',
            imgDriveConfigured: false,
            
            async init() {
                await (window.SmxOfflineDb?.ready || Promise.resolve());
                await this.refreshCameraPermissionState();
                await this.loadCats();
                await this.loadProductos();
                this.warmOfflineSearchIndex();
                // Verificar si R2 está configurado
                this.checkDriveConfig();
            },

            async checkDriveConfig() {
                try {
                    const res = await fetch(`${this.API}/imagen.php?action=check&id_empresa=${this.idEmpresa}`);
                    const data = await res.json();
                    this.imgDriveConfigured = !!(data.configured);
                    if (!this.imgDriveConfigured) {
                        this.imgError = 'R2 no está configurado';
                    }
                } catch(e) {
                    this.imgDriveConfigured = false;
                    this.imgError = 'No se pudo validar R2';
                }
            },

            async refreshCameraPermissionState() {
                try {
                    if (navigator.permissions && navigator.permissions.query) {
                        const p = await navigator.permissions.query({ name: 'camera' });
                        this.cameraPermissionState = p.state || 'prompt';
                        localStorage.setItem('sx_camera_perm_state', this.cameraPermissionState);
                        try {
                            p.onchange = () => {
                                this.cameraPermissionState = p.state || 'prompt';
                                localStorage.setItem('sx_camera_perm_state', this.cameraPermissionState);
                            };
                        } catch (_) {}
                    }
                } catch (_) {}
            },

            async requestBarcodeCameraPermissionOnce() {
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                    stream.getTracks().forEach((t) => t.stop());
                    this.cameraPermissionState = 'granted';
                    localStorage.setItem('sx_camera_perm_state', 'granted');
                    return true;
                } catch (err) {
                    const errorName = String(err?.name || '');
                    if (errorName === 'NotAllowedError' || errorName === 'PermissionDeniedError') {
                        this.cameraPermissionState = 'denied';
                        localStorage.setItem('sx_camera_perm_state', 'denied');
                        this.barcodeScannerErrorForm = 'Permiso de cámara bloqueado. Actívalo en ajustes del navegador/app.';
                    }
                    return false;
                }
            },
            
            async loadCats() {
                try {
                    const [g, m, s] = await Promise.all([
                        fetch(`${this.API}/catalogos.php?action=list&tabla=grupos&id_empresa=${this.idEmpresa}`).then(r => r.json()),
                        fetch(`${this.API}/catalogos.php?action=list&tabla=marcas&id_empresa=${this.idEmpresa}`).then(r => r.json()),
                        fetch(`${this.API}/stock.php?action=sucursales&id_empresa=${this.idEmpresa}`).then(r => r.json()),
                    ]);
                    this.catGrupos = g?.data || [];
                    this.catMarcas = m?.data || [];
                    this.sucursalesList = s?.data || [];
                    this.writeOfflineCache('catalogos', {
                        grupos: this.catGrupos,
                        marcas: this.catMarcas,
                        sucursales: this.sucursalesList,
                        saved_at: new Date().toISOString()
                    });
                } catch(e) {
                    const cached = this.readOfflineCache('catalogos', null);
                    if (cached) {
                        this.catGrupos = Array.isArray(cached.grupos) ? cached.grupos : [];
                        this.catMarcas = Array.isArray(cached.marcas) ? cached.marcas : [];
                        this.sucursalesList = Array.isArray(cached.sucursales) ? cached.sucursales : [];
                    }
                }
            },
            
            async loadProductos(append = false) {
                if (!append) this.loading = true;
                try {
                    const params = new URLSearchParams({
                        id_empresa: this.idEmpresa,
                        page: this.currentPage,
                        per_page: this.perPage,
                        search: this.searchQuery,
                        grupo: this.filtroGrupo,
                        estado: this.filtroEstado,
                        sort_by: 'desproducto',
                        sort_dir: 'ASC'
                    });
                    const res = await fetch(`${this.API}/list.php?${params}`);
                    const data = await res.json();
                    if (data.success) {
                        const normalized = (data.data || []).map((p) => ({
                            ...p,
                            foto_thumb_url: p.foto_thumb_url || p.foto_url || '',
                            foto_small_url: p.foto_small_url || p.foto_url || '',
                            foto_medium_url: p.foto_medium_url || p.foto_url || '',
                            foto_large_url: p.foto_large_url || p.foto_url || ''
                        }));
                        if (append) {
                            this.productos = [...this.productos, ...normalized];
                        } else {
                            this.productos = normalized;
                        }
                        this.totalPages = data.pagination?.total_pages || 1;
                        this.stats = data.stats || this.stats;
                        this.mergeOfflineProductos(this.productos, this.stats);
                    }
                } catch(e) {
                    console.error(e);
                    const cached = this.readOfflineCache('productos_snapshot', null);
                    if (cached && Array.isArray(cached.items)) {
                        const filtered = this.filterOfflineProductos(cached.items);
                        const end = append ? this.productos.length + this.perPage : this.perPage;
                        this.productos = filtered.slice(0, end);
                        this.totalPages = Math.max(1, Math.ceil(filtered.length / this.perPage));
                        this.stats = cached.stats || this.stats;
                        this.showToast('Mostrando productos desde cache offline', 'warning');
                    }
                }
                this.loading = false;
            },
            
            abrirDetalle(p) {
                this.detalleProducto = p;
                this.showDetalle = true;
            },

            onCardClick(p) {
                if (this.imageLongPressTriggered) {
                    this.imageLongPressTriggered = false;
                    return;
                }
                this.abrirDetalle(p);
            },

            startImageLongPress(p) {
                if (this.imageLongPressTimer) clearTimeout(this.imageLongPressTimer);
                this.imageLongPressTriggered = false;
                this.imageLongPressTimer = setTimeout(() => {
                    this.imageLongPressTriggered = true;
                    this.openImageContextMenu(p);
                }, 520);
            },

            endImageLongPress() {
                if (this.imageLongPressTimer) {
                    clearTimeout(this.imageLongPressTimer);
                    this.imageLongPressTimer = null;
                }
            },

            cancelImageLongPress() {
                this.endImageLongPress();
            },

            openImageContextMenu(p) {
                if (!p || this.permisos.priv_update !== 'Y') return;
                this.imageContextProduct = p;
                this.showImageContextMenu = true;
            },

            closeImageContextMenu() {
                this.showImageContextMenu = false;
                this.imageContextProduct = null;
            },

            closeFormModal() {
                this.closeBarcodeScannerForm();
                this.stopDescriptionVoiceDictation(true);
                this.showForm = false;
                this.clearPendingProductImage();
            },

            getFormMainImage() {
                if (this.pendingProductImagePreviewUrl) return this.pendingProductImagePreviewUrl;
                if (Array.isArray(this.imgList) && this.imgList.length > 0) {
                    return this.imgList[0]?.medium_url || this.imgList[0]?.url || '';
                }
                return this.form?.foto_medium_url || this.form?.foto_url || '';
            },

            formGoogleImage() {
                this.imageContextProduct = {
                    idproducto: Number(this.form?.idproducto || 0),
                    desproducto: String(this.form?.desproducto || 'producto')
                };
                this.obtenerFotoGoogleImagenes();
            },

            formCamaraImage() {
                this.imageContextProduct = {
                    idproducto: Number(this.form?.idproducto || 0),
                    desproducto: String(this.form?.desproducto || 'producto')
                };
                this.obtenerFotoCamara();
            },

            clearPendingProductImage() {
                if (this.pendingProductImagePreviewUrl && this.pendingProductImagePreviewUrl.startsWith('blob:')) {
                    try { URL.revokeObjectURL(this.pendingProductImagePreviewUrl); } catch (_) {}
                }
                this.pendingProductImagePreviewUrl = '';
                this.pendingProductImagePayload = null;
            },

            queuePendingProductImage(payload) {
                this.clearPendingProductImage();
                this.pendingProductImagePayload = payload;
                if (payload?.imagen_blob instanceof Blob) {
                    this.pendingProductImagePreviewUrl = URL.createObjectURL(payload.imagen_blob);
                } else if (typeof payload?.imagen_base64 === 'string' && payload.imagen_base64.startsWith('data:image/')) {
                    this.pendingProductImagePreviewUrl = payload.imagen_base64;
                } else if (typeof payload?.imagen_url === 'string') {
                    this.pendingProductImagePreviewUrl = payload.imagen_url;
                }
            },

            applyProductImageLocally(idProducto, imageData) {
                if (!idProducto || !imageData) return;

                const fallbackUrlRaw = typeof imageData === 'string'
                    ? imageData
                    : (imageData.medium_url || imageData.url || '');
                if (!fallbackUrlRaw) return;

                const variants = (typeof imageData === 'object' && imageData.variants) ? imageData.variants : {};

                const normalizeWithCacheBust = (urlRaw) => {
                    if (!urlRaw) return '';
                    let url = String(urlRaw);
                    if (url.startsWith('/_lib')) url = '/public' + url;
                    return url + (url.includes('?') ? '&' : '?') + 't=' + Date.now();
                };

                const thumbUrl = normalizeWithCacheBust(variants.thumb || imageData.thumb_url || fallbackUrlRaw);
                const smallUrl = normalizeWithCacheBust(variants.small || imageData.small_url || fallbackUrlRaw);
                const mediumUrl = normalizeWithCacheBust(variants.medium || imageData.medium_url || fallbackUrlRaw);
                const largeUrl = normalizeWithCacheBust(variants.large || imageData.large_url || fallbackUrlRaw);

                this.productos = this.productos.map((p) => {
                    if (Number(p.idproducto) === Number(idProducto)) {
                        return {
                            ...p,
                            foto_url: smallUrl || mediumUrl || largeUrl || thumbUrl,
                            foto_thumb_url: thumbUrl || smallUrl || mediumUrl || largeUrl,
                            foto_small_url: smallUrl || mediumUrl || largeUrl || thumbUrl,
                            foto_medium_url: mediumUrl || largeUrl || smallUrl || thumbUrl,
                            foto_large_url: largeUrl || mediumUrl || smallUrl || thumbUrl
                        };
                    }
                    return p;
                });
                if (this.detalleProducto && Number(this.detalleProducto.idproducto) === Number(idProducto)) {
                    this.detalleProducto = {
                        ...this.detalleProducto,
                        foto_url: smallUrl || mediumUrl,
                        foto_thumb_url: thumbUrl,
                        foto_small_url: smallUrl,
                        foto_medium_url: mediumUrl,
                        foto_large_url: largeUrl,
                        imagen: mediumUrl || largeUrl || smallUrl || thumbUrl
                    };
                }
                if (this.form && Number(this.form.idproducto || 0) === Number(idProducto)) {
                    this.form.foto_url = smallUrl || mediumUrl;
                    this.form.foto_thumb_url = thumbUrl;
                    this.form.foto_small_url = smallUrl;
                    this.form.foto_medium_url = mediumUrl;
                    this.form.foto_large_url = largeUrl;
                    this.clearPendingProductImage();
                }
            },

            async saveProductImagePayload(payload) {
                const target = this.imageActionProduct || this.imageContextProduct;
                const targetId = Number(target?.idproducto || 0);
                if (!targetId) {
                    this.queuePendingProductImage(payload);
                    this.showToast('Foto lista. Se subirá al guardar el producto.', 'success');
                    return true;
                }
                try {
                    let res;
                    if (payload.imagen_url) {
                        res = await fetch(`${this.API}/imagen.php`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'upload_url',
                                idproducto: targetId,
                                id_empresa: this.idEmpresa,
                                image_url: payload.imagen_url
                            })
                        });
                    } else if (payload.imagen_base64) {
                        const blob = await (await fetch(payload.imagen_base64)).blob();
                        res = await this.uploadProductImageBlob(targetId, blob, `producto_${targetId}.jpg`);
                    } else if (payload.imagen_blob) {
                        const filename = payload.filename || `producto_${targetId}.png`;
                        res = await this.uploadProductImageBlob(targetId, payload.imagen_blob, filename);
                    } else {
                        this.showToast('No se recibió imagen para guardar', 'error');
                        return false;
                    }

                    const data = await res.json();
                    if (!data.success) {
                        this.showToast(data.error || 'No se pudo guardar imagen', 'error');
                        return false;
                    }
                    this.applyProductImageLocally(targetId, data?.data || data);
                    this.showToast('Foto actualizada', 'success');
                    return true;
                } catch (e) {
                    console.error('saveProductImagePayload error:', e);
                    this.showToast('Error de conexión al guardar foto', 'error');
                    return false;
                }
            },

            async uploadProductImageBlob(idProducto, blob, filename) {
                const formData = new FormData();
                formData.append('action', 'upload');
                formData.append('idproducto', String(idProducto));
                formData.append('id_empresa', String(this.idEmpresa));
                formData.append('imagen', blob, filename);
                return fetch(`${this.API}/imagen.php`, {
                    method: 'POST',
                    body: formData
                });
            },

            async obtenerFotoGoogleImagenes() {
                const p = this.imageContextProduct;
                if (!p) return;
                this.imageActionProduct = p;
                const q = encodeURIComponent(p.desproducto || 'producto');
                const url = `https://www.google.com/search?tbm=isch&q=${q}`;
                let opened = false;
                const hasAndroidBridge = !!(
                    window.Android &&
                    (
                        typeof window.Android.openExternalUrlResult === 'function' ||
                        typeof window.Android.openExternalResult === 'function' ||
                        typeof window.Android.openExternalUrl === 'function' ||
                        typeof window.Android.openExternal === 'function'
                    )
                );
                try {
                    if (window.parent && window.parent !== window && typeof window.parent.abrirNavegadorExterno === 'function') {
                        opened = window.parent.abrirNavegadorExterno(url) === true;
                    }
                } catch (_) {}
                if (!opened) {
                    try {
                        const win = window.open(url, '_blank', 'noopener,noreferrer');
                        opened = !!win;
                    } catch (_) {
                        opened = false;
                    }
                }
                if (!opened) {
                    try {
                        if (window.Android && typeof window.Android.openExternalUrlResult === 'function') {
                            const ok = window.Android.openExternalUrlResult(url);
                            opened = ok === true || ok === 'true' || ok === 1 || ok === '1';
                        } else if (window.Android && typeof window.Android.openExternalResult === 'function') {
                            const ok = window.Android.openExternalResult(url);
                            opened = ok === true || ok === 'true' || ok === 1 || ok === '1';
                        } else if (window.Android && typeof window.Android.openExternalUrl === 'function') {
                            window.Android.openExternalUrl(url);
                            opened = true;
                        } else if (window.Android && typeof window.Android.openExternal === 'function') {
                            window.Android.openExternal(url);
                            opened = true;
                        }
                    } catch (_) {}
                }
                if (!opened) {
                    try {
                        if (window.cordova && window.cordova.InAppBrowser && typeof window.cordova.InAppBrowser.open === 'function') {
                            window.cordova.InAppBrowser.open(url, '_system', 'location=yes');
                            opened = true;
                        }
                    } catch (_) {}
                }
                // Android fallback: intentar intent:// directamente desde el iframe.
                if (!opened && !hasAndroidBridge) {
                    try {
                        const ua = String(navigator.userAgent || '').toLowerCase();
                        if (ua.includes('android')) {
                            const u = new URL(url);
                            const intentUrl = `intent://${u.host}${u.pathname}${u.search || ''}#Intent;scheme=https;package=com.android.chrome;end`;
                            window.location.href = intentUrl;
                            opened = true;
                        }
                    } catch (_) {}
                }
                // Android fallback 2: esquema googlechrome://
                if (!opened && !hasAndroidBridge) {
                    try {
                        const ua = String(navigator.userAgent || '').toLowerCase();
                        if (ua.includes('android')) {
                            window.location.href = 'googlechrome://navigate?url=' + encodeURIComponent(url);
                            opened = true;
                        }
                    } catch (_) {}
                }
                this.closeImageContextMenu();
                this.googleImageUrl = '';
                this.googleImageError = '';
                this.googleImageHint = '';
                this.showGoogleImageForm = true;
                if (!opened) {
                    this.googleImageError = 'No se pudo abrir navegador externo. Abra Google Imagenes manualmente y pegue la URL.';
                }
            },

            async obtenerFotoBingCatalogo() {
                const p = this.imageContextProduct;
                if (!p) return;
                this.imageActionProduct = p;
                this.closeImageContextMenu();
                const idProducto = Number(p.idproducto || 0);
                const descripcion = String(p.desproducto || 'producto').trim();
                if (!descripcion) {
                    this.showToast('Falta descripción para buscar imagen', 'error');
                    this.imageActionProduct = null;
                    return;
                }
                try {
                    const proxyUrl = `/public/pos/api/imagen_proxy.php?id=${encodeURIComponent(idProducto > 0 ? idProducto : Date.now())}&q=${encodeURIComponent(descripcion)}&prefer=bing_catalog&refresh=1`;
                    const res = await fetch(proxyUrl, { cache: 'no-store' });
                    const contentType = String(res.headers.get('content-type') || '').toLowerCase();
                    if (!res.ok || !contentType.startsWith('image/')) {
                        throw new Error('No se encontró una imagen válida en Bing catálogo');
                    }
                    const blob = await res.blob();
                    const ok = await this.saveProductImagePayload({
                        imagen_blob: blob,
                        filename: `producto_${idProducto || 'nuevo'}_bing_catalog.webp`
                    });
                    if (ok) {
                        this.showToast('Imagen capturada desde Bing catálogo', 'success');
                    }
                } catch (err) {
                    console.error('obtenerFotoBingCatalogo error:', err);
                    this.showToast((err && err.message) ? err.message : 'No se pudo capturar imagen desde Bing catálogo', 'error');
                } finally {
                    this.imageActionProduct = null;
                }
            },

            closeGoogleImageForm() {
                this.showGoogleImageForm = false;
                this.googleImageUrl = '';
                this.googleImageError = '';
                this.googleImageHint = '';
                this.googleImagePasting = false;
                this.imageActionProduct = null;
            },

            async pasteGoogleImageUrl() {
                this.googleImageError = '';
                this.googleImageHint = '';
                if (!navigator.clipboard || !navigator.clipboard.readText) {
                    this.focusGoogleImageInput();
                    this.googleImageHint = 'Use pegado manual: mantenga pulsado en el campo y toque "Pegar".';
                    return;
                }
                try {
                    const txt = (await navigator.clipboard.readText() || '').trim();
                    if (!txt) {
                        this.focusGoogleImageInput();
                        this.googleImageHint = 'Portapapeles vacio. Pegue manualmente la URL.';
                        return;
                    }
                    this.googleImageUrl = txt;
                    await this.saveGoogleImageUrl();
                } catch (_) {
                    this.focusGoogleImageInput();
                    this.googleImageHint = 'Permiso de portapapeles denegado. Pegue manualmente la URL.';
                }
            },

            async pasteGoogleImageBlob() {
                this.googleImageError = '';
                this.googleImageHint = '';
                if (!navigator.clipboard || !navigator.clipboard.read) {
                    this.googleImageError = 'Este navegador no permite pegar imagen directa. Use URL o Camara.';
                    return;
                }
                const target = this.imageActionProduct || this.imageContextProduct;
                if (!target?.idproducto) {
                    this.googleImageError = 'No hay producto seleccionado.';
                    return;
                }

                this.googleImagePasting = true;
                try {
                    const items = await navigator.clipboard.read();
                    let imgBlob = null;
                    let mime = 'image/png';

                    for (const item of items) {
                        const imageType = item.types.find((t) => t.startsWith('image/'));
                        if (imageType) {
                            imgBlob = await item.getType(imageType);
                            mime = imageType;
                            break;
                        }
                    }

                    if (!imgBlob) {
                        this.googleImageError = 'No hay imagen en el portapapeles.';
                        return;
                    }

                    const ext = mime.includes('jpeg') ? 'jpg'
                        : mime.includes('webp') ? 'webp'
                        : mime.includes('gif') ? 'gif'
                        : mime.includes('bmp') ? 'bmp'
                        : 'png';
                    const ok = await this.saveProductImagePayload({
                        imagen_blob: imgBlob,
                        filename: `producto_${target.idproducto}_clipboard.${ext}`
                    });
                    if (ok) {
                        this.closeGoogleImageForm();
                    }
                } catch (_) {
                    this.googleImageError = 'No se pudo leer imagen del portapapeles. Use URL directa o Camara.';
                } finally {
                    this.googleImagePasting = false;
                }
            },

            async handleGoogleImagePasteAndSave(event) {
                this.googleImageError = '';
                this.googleImageHint = '';
                try {
                    const txt = event?.clipboardData?.getData('text/plain') || '';
                    if (txt && txt.trim()) {
                        this.googleImageUrl = txt.trim();
                        await this.saveGoogleImageUrl();
                    }
                } catch (_) {}
            },

            openGoogleImageGallery() {
                this.googleImageError = '';
                this.googleImageHint = '';
                this.$nextTick(() => {
                    const input = this.$refs.googleImageFileInput;
                    if (input) input.click();
                });
            },

            async handleGoogleImageFile(event) {
                this.googleImageError = '';
                this.googleImageHint = '';
                const file = event?.target?.files?.[0] || null;
                if (!file) return;
                const mime = String(file.type || '').toLowerCase();
                const name = String(file.name || '').toLowerCase();
                const isImageByMime = mime.startsWith('image/');
                const isImageByExt = /\.(jpg|jpeg|png|webp|gif|bmp|heic|heif)$/i.test(name);
                if (!isImageByMime && !isImageByExt) {
                    this.googleImageError = 'El archivo seleccionado no parece imagen.';
                    return;
                }
                const filename = this.resolveGoogleImageFilename(file);
                const ok = await this.saveProductImagePayload({
                    imagen_blob: file,
                    filename
                });
                if (ok) {
                    this.closeGoogleImageForm();
                }
            },

            resolveGoogleImageFilename(file) {
                const base = `producto_${Date.now()}`;
                const rawName = String(file?.name || '').trim();
                if (/\.[a-z0-9]{2,5}$/i.test(rawName)) {
                    return rawName.replace(/\s+/g, '_');
                }
                const mime = String(file?.type || '').toLowerCase();
                if (mime.includes('png')) return `${base}.png`;
                if (mime.includes('webp')) return `${base}.webp`;
                if (mime.includes('gif')) return `${base}.gif`;
                if (mime.includes('bmp')) return `${base}.bmp`;
                return `${base}.jpg`;
            },

            focusGoogleImageInput() {
                this.$nextTick(() => {
                    const el = this.$refs.googleImageInput;
                    if (!el) return;
                    try {
                        el.focus();
                        el.select();
                    } catch (_) {}
                });
            },

            async saveGoogleImageUrl() {
                this.googleImageError = '';
                this.googleImageHint = '';
                const rawUrl = (this.googleImageUrl || '').trim();
                if (!rawUrl) {
                    this.googleImageError = 'Pegue una URL de imagen.';
                    return;
                }
                if (!/^https?:\/\//i.test(rawUrl)) {
                    this.googleImageError = 'URL invalida. Debe iniciar con http:// o https://';
                    return;
                }

                const normalized = this.normalizeGoogleImageUrl(rawUrl);
                const imageUrl = normalized.url;
                if (normalized.note) {
                    this.googleImageHint = normalized.note;
                }

                const ok = await this.saveProductImagePayload({ imagen_url: imageUrl });
                if (ok) {
                    this.closeGoogleImageForm();
                    return;
                }

                // Fallback: si la URL falla, intentar leer imagen del portapapeles (si el navegador lo permite).
                const clipSaved = await this.trySaveImageFromClipboardSilently();
                if (clipSaved) {
                    this.closeGoogleImageForm();
                } else if (!this.googleImageError) {
                    this.googleImageError = 'No se pudo guardar desde URL. Use Descargas para subir la imagen.';
                }
            },

            normalizeGoogleImageUrl(url) {
                try {
                    const u = new URL(url);
                    const params = u.searchParams;
                    const embedded = params.get('imgurl')
                        || params.get('mediaurl')
                        || params.get('url')
                        || '';
                    if (/^https?:\/\//i.test(embedded)) {
                        return {
                            url: embedded.trim(),
                            note: 'Se detectó enlace intermedio y se extrajo la URL de imagen.',
                        };
                    }
                    return { url: u.href };
                } catch (_) {
                    return { url };
                }
            },

            async trySaveImageFromClipboardSilently() {
                try {
                    if (!navigator.clipboard || !navigator.clipboard.read) return false;
                    const target = this.imageActionProduct || this.imageContextProduct;
                    if (!target?.idproducto) return false;
                    const items = await navigator.clipboard.read();
                    for (const item of items) {
                        const imageType = item.types.find((t) => t.startsWith('image/'));
                        if (!imageType) continue;
                        const blob = await item.getType(imageType);
                        const ext = imageType.includes('jpeg') ? 'jpg'
                            : imageType.includes('webp') ? 'webp'
                            : imageType.includes('gif') ? 'gif'
                            : imageType.includes('bmp') ? 'bmp'
                            : 'png';
                        return await this.saveProductImagePayload({
                            imagen_blob: blob,
                            filename: `producto_${target.idproducto}_clipboard.${ext}`,
                        });
                    }
                } catch (_) {}
                return false;
            },

            async obtenerFotoCamara() {
                const p = this.imageContextProduct;
                if (!p) return;
                this.imageActionProduct = p;
                this.closeImageContextMenu();
                if (!this.imgDriveConfigured) {
                    this.showToast('R2 no está configurado', 'error');
                    this.imageActionProduct = null;
                    return;
                }

                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    this.showToast('Tu navegador no soporta cámara', 'error');
                    this.imageActionProduct = null;
                    return;
                }
                await this.refreshCameraPermissionState();
                if (this.cameraPermissionState === 'denied') {
                    this.showToast('Permiso de cámara bloqueado', 'error');
                    this.imageActionProduct = null;
                    return;
                }
                if (this.cameraPermissionState !== 'granted') {
                    const ok = await this.requestBarcodeCameraPermissionOnce();
                    if (!ok) {
                        this.showToast('No se pudo habilitar la cámara', 'error');
                        this.imageActionProduct = null;
                        return;
                    }
                }
                this.showProductCameraForm = true;
                this.productCameraError = '';
                await this.$nextTick();
                await this.startProductCameraForm();
            },

            async startProductCameraForm() {
                try {
                    let stream = null;
                    try {
                        stream = await navigator.mediaDevices.getUserMedia({
                            video: {
                                facingMode: { ideal: 'environment' },
                                width: { ideal: 1920 },
                                height: { ideal: 1080 }
                            },
                            audio: false
                        });
                    } catch (_) {
                        stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                    }
                    this.productCameraStream = stream;
                    const video = this.$refs.productCameraVideo;
                    if (!video) throw new Error('Video no disponible');
                    video.srcObject = stream;
                    await video.play();
                } catch (err) {
                    this.productCameraError = 'No se pudo iniciar la cámara.';
                    console.error('Product camera error:', err);
                }
            },

            stopProductCameraForm() {
                try {
                    if (this.productCameraStream) {
                        this.productCameraStream.getTracks().forEach((t) => t.stop());
                    }
                } catch (_) {}
                this.productCameraStream = null;
                try {
                    const video = this.$refs.productCameraVideo;
                    if (video) video.srcObject = null;
                } catch (_) {}
            },

            closeProductCameraForm() {
                this.stopProductCameraForm();
                this.showProductCameraForm = false;
                this.imageActionProduct = null;
            },

            async captureProductFromCamera() {
                const video = this.$refs.productCameraVideo;
                if (!video || video.readyState < 2) {
                    this.productCameraError = 'La cámara aún no está lista.';
                    return;
                }
                const w = video.videoWidth || 0;
                const h = video.videoHeight || 0;
                if (w < 2 || h < 2) {
                    this.productCameraError = 'No se pudo capturar imagen.';
                    return;
                }
                const canvas = document.createElement('canvas');
                canvas.width = w;
                canvas.height = h;
                const ctx = canvas.getContext('2d');
                if (!ctx) {
                    this.productCameraError = 'No se pudo procesar la captura.';
                    return;
                }
                ctx.drawImage(video, 0, 0, w, h);
                const blob = await new Promise((resolve) => {
                    try {
                        canvas.toBlob((b) => resolve(b), 'image/jpeg', 0.9);
                    } catch (_) {
                        resolve(null);
                    }
                });
                if (!blob) {
                    this.productCameraError = 'No se pudo generar la imagen capturada.';
                    return;
                }
                const target = this.imageActionProduct || this.imageContextProduct;
                const ok = await this.saveProductImagePayload({
                    imagen_blob: blob,
                    filename: `producto_${target?.idproducto || 'camara'}_${Date.now()}.jpg`
                });
                if (ok) {
                    this.closeProductCameraForm();
                }
            },
            
            resetForm() {
                this.form = {
                    idproducto: null, cve_producto: '', desproducto: '', codigo_barra: '',
                    precio_compra: 0, precio_venta: 0, grupo: 0, marca: 0, iva: '1', impuesto: 10,
                    unidad_medida: '', stock_minimo: 1, stock_maximo: 5,
                    controla_stock: 1, edita_precio: 1, vende_sin_stock: -1,
                    stock_inicial: 0, id_sucursal: 1,
                    garantia_meses: 0, proveedor_principal: '', ubicacion_fisica: '',
                    condicion_producto: 'nuevo', capacidad: '', ram: '',
                    compatibilidad: '', incluye: '', usaserial: -1,
                    foto_url: '',
                    foto_thumb_url: '',
                    foto_small_url: '',
                    foto_medium_url: '',
                    foto_large_url: '',
                    precios: [], series: [], codigos_barra: [], codigos_barra_extra: [],
                };
                this.imgList = [];
                this.imgError = '';
                this.imgUploading = false;
                this.clearPendingProductImage();
                this.stopDescriptionVoiceDictation(true);
            },

            toggleDescriptionVoiceDictation() {
                if (this.speechListening) {
                    this.stopDescriptionVoiceDictation(false);
                    return;
                }
                this.startDescriptionVoiceDictation();
            },

            clampDescriptionText(text) {
                const max = Number(this.descriptionMaxLength || 200);
                const normalized = String(text || '').replace(/\s+/g, ' ').trim();
                return normalized.length > max ? normalized.slice(0, max) : normalized;
            },

            startDescriptionVoiceDictation() {
                if (!this.speechSupported || this.speechListening) return;
                const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
                if (!SpeechRecognition) {
                    this.speechSupported = false;
                    this.showToast('Micrófono no soportado en este navegador', 'error');
                    return;
                }
                try {
                    const recognition = new SpeechRecognition();
                    recognition.lang = 'es-PY';
                    recognition.continuous = true;
                    recognition.interimResults = true;
                    this.speechBaseDescription = this.clampDescriptionText(this.form?.desproducto || '');
                    this.speechFinalByIndex = {};

                    recognition.onstart = () => {
                        this.speechListening = true;
                    };
                    recognition.onresult = (event) => {
                        for (let i = event.resultIndex; i < event.results.length; i++) {
                            const res = event.results[i];
                            if (res.isFinal) {
                                const part = String(res[0]?.transcript || '').replace(/\s+/g, ' ').trim();
                                if (part) this.speechFinalByIndex[i] = part;
                            }
                        }

                        const chunks = Object.keys(this.speechFinalByIndex)
                            .map((k) => Number(k))
                            .filter((n) => Number.isFinite(n))
                            .sort((a, b) => a - b)
                            .map((i) => this.speechFinalByIndex[i])
                            .filter(Boolean);
                        const deduped = [];
                        for (const chunkRaw of chunks) {
                            const chunk = String(chunkRaw || '').replace(/\s+/g, ' ').trim();
                            if (!chunk) continue;
                            if (deduped.length === 0) {
                                deduped.push(chunk);
                                continue;
                            }
                            const prev = String(deduped[deduped.length - 1] || '');
                            const prevL = prev.toLowerCase();
                            const curL = chunk.toLowerCase();

                            // Evita repetición exacta
                            if (curL === prevL) continue;

                            // Si el reconocimiento va creciendo ("clorhidrato" -> "clorhidrato de"),
                            // reemplazar el bloque anterior por el más completo.
                            if (curL.startsWith(prevL)) {
                                deduped[deduped.length - 1] = chunk;
                                continue;
                            }
                            // Si llega una versión más corta de lo mismo, ignorar.
                            if (prevL.startsWith(curL)) {
                                continue;
                            }

                            deduped.push(chunk);
                        }
                        const cleaned = deduped.join(' ').replace(/\s+/g, ' ').trim();
                        const base = String(this.speechBaseDescription || '').trim();
                        if (!cleaned) {
                            this.form.desproducto = this.clampDescriptionText(base);
                            return;
                        }
                        this.form.desproducto = this.clampDescriptionText(base ? `${base} ${cleaned}` : cleaned);
                    };
                    recognition.onerror = (event) => {
                        const code = String(event?.error || '');
                        if (code !== 'aborted') {
                            this.showToast('No se pudo usar el micrófono', 'error');
                        }
                    };
                    recognition.onend = () => {
                        this.speechListening = false;
                        this.speechRecognition = null;
                        this.speechBaseDescription = '';
                        this.speechFinalByIndex = {};
                    };

                    this.speechRecognition = recognition;
                    recognition.start();
                } catch (e) {
                    console.error('speech start error:', e);
                    this.speechListening = false;
                    this.speechRecognition = null;
                    this.speechBaseDescription = '';
                    this.speechFinalByIndex = {};
                    this.showToast('No se pudo iniciar dictado por voz', 'error');
                }
            },

            stopDescriptionVoiceDictation(forceAbort = false) {
                const recognition = this.speechRecognition;
                if (!recognition) {
                    this.speechListening = false;
                    return;
                }
                try {
                    if (forceAbort) recognition.abort();
                    else recognition.stop();
                } catch (_) {}
                this.speechListening = false;
                this.speechRecognition = null;
                this.speechBaseDescription = '';
                this.speechFinalByIndex = {};
            },
            
            nuevoProducto() {
                this.resetForm();
                this.showForm = true;
            },
            
            agregarCodigoBarra() {
                this.form.codigos_barra_extra.push({ codigo: '' });
            },
            
            eliminarCodigoBarra(idx) {
                this.form.codigos_barra_extra.splice(idx, 1);
            },
            
            // ========== IMÁGENES R2 ==========
            async handleImgUpload(event) {
                const file = event.target.files?.[0];
                if (!file) return;
                if (!this.imgDriveConfigured) {
                    this.showToast('R2 no está configurado', 'error');
                    return;
                }
                if (!this.form.idproducto) {
                    this.showToast('Guarde el producto primero para subir fotos', 'error');
                    return;
                }
                this.imgError = '';
                this.imgUploading = true;
                try {
                    const formData = new FormData();
                    formData.append('action', 'upload');
                    formData.append('imagen', file);
                    formData.append('idproducto', this.form.idproducto);
                    formData.append('id_empresa', this.idEmpresa);

                    const res = await fetch(`${this.API}/imagen.php`, {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Foto subida', 'success');
                        await this.loadImgList();
                    } else {
                        this.imgError = data.error || 'Error al subir';
                        this.showToast(data.error || 'Error al subir foto', 'error');
                    }
                } catch(e) {
                    this.imgError = 'Error de conexión';
                    this.showToast('Error de conexión al subir foto', 'error');
                }
                this.imgUploading = false;
            },

            async loadImgList() {
                if (!this.form.idproducto) { this.imgList = []; return; }
                if (!this.imgDriveConfigured) { this.imgList = []; return; }
                try {
                    const res = await fetch(`${this.API}/imagen.php?action=list&idproducto=${this.form.idproducto}&id_empresa=${this.idEmpresa}`);
                    const data = await res.json();
                    this.imgList = data.data || [];
                } catch(e) { /* silently fail */ }
            },

            async eliminarImg(img) {
                if (!this.imgDriveConfigured) {
                    this.showToast('R2 no está configurado', 'error');
                    return;
                }
                if (!confirm('¿Eliminar esta foto?')) return;
                try {
                    const res = await fetch(`${this.API}/imagen.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'delete',
                            file_id: img.drive_file_id,
                            idproducto: this.form.idproducto,
                            id_empresa: this.idEmpresa
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Foto eliminada', 'success');
                        await this.loadImgList();
                    } else {
                        this.showToast(data.error || 'Error', 'error');
                    }
                } catch(e) { this.showToast('Error de conexión', 'error'); }
            },

            async setImgPrincipal(img) {
                if (!this.imgDriveConfigured) {
                    this.showToast('R2 no está configurado', 'error');
                    return;
                }
                try {
                    const res = await fetch(`${this.API}/imagen.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'set_principal',
                            file_id: img.drive_file_id,
                            idproducto: this.form.idproducto,
                            id_empresa: this.idEmpresa
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showToast('Foto principal actualizada', 'success');
                        await this.loadImgList();
                    } else {
                        this.showToast(data.error || 'Error', 'error');
                    }
                } catch(e) { this.showToast('Error de conexión', 'error'); }
            },
            
            // ========== GESTIÓN CATÁLOGOS ==========
            async abrirCatModal(tipo, titulo) {
                this.catModalTipo = tipo;
                this.catModalTitulo = titulo;
                this.catModalNuevo = '';
                this.catModalEditId = null;
                this.catModalEditNombre = '';
                this.catModalSaving = false;
                this.showCatModal = true;
                await this.cargarCatItems();
            },
            async cargarCatItems() {
                try {
                    const res = await fetch(`${this.API}/catalogos.php?action=list&tabla=${this.catModalTipo}&id_empresa=${this.idEmpresa}`);
                    const data = await res.json();
                    this.catModalItems = data.data || [];
                } catch(e) { this.catModalItems = []; }
            },
            async crearCatItem() {
                const nombre = this.catModalNuevo.trim();
                if (!nombre) return;
                this.catModalSaving = true;
                try {
                    const res = await fetch(`${this.API}/catalogos.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'create', tabla: this.catModalTipo, nombre, id_empresa: this.idEmpresa })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.catModalNuevo = '';
                        await this.cargarCatItems();
                        await this.loadCats();
                        this.showToast('Creado correctamente', 'success');
                    } else {
                        this.showToast(data.error || 'Error', 'error');
                    }
                } catch(e) { this.showToast('Error de conexión', 'error'); }
                this.catModalSaving = false;
            },
            iniciarEditCat(item) {
                this.catModalEditId = item.id;
                this.catModalEditNombre = item.nombre;
            },
            cancelarEditCat() {
                this.catModalEditId = null;
                this.catModalEditNombre = '';
            },
            async guardarEditCat() {
                const nombre = this.catModalEditNombre.trim();
                if (!nombre || !this.catModalEditId) return;
                this.catModalSaving = true;
                try {
                    const res = await fetch(`${this.API}/catalogos.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'update', tabla: this.catModalTipo, id: this.catModalEditId, nombre, id_empresa: this.idEmpresa })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.catModalEditId = null;
                        this.catModalEditNombre = '';
                        await this.cargarCatItems();
                        await this.loadCats();
                        this.showToast('Actualizado', 'success');
                    } else {
                        this.showToast(data.error || 'Error', 'error');
                    }
                } catch(e) { this.showToast('Error de conexión', 'error'); }
                this.catModalSaving = false;
            },
            async eliminarCatItem(item) {
                if (!confirm(`¿Eliminar "${item.nombre}"?`)) return;
                try {
                    const res = await fetch(`${this.API}/catalogos.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete', tabla: this.catModalTipo, id: item.id, id_empresa: this.idEmpresa })
                    });
                    const data = await res.json();
                    if (data.success) {
                        await this.cargarCatItems();
                        await this.loadCats();
                        this.showToast('Eliminado', 'success');
                    } else {
                        this.showToast(data.error || 'Error', 'error');
                    }
                } catch(e) { this.showToast('Error de conexión', 'error'); }
            },

            agregarSerie() {
                this.form.series.push(this.normalizeSerieRow({}));
            },

            normalizeSerieValue(value) {
                return String(value || '').trim().toUpperCase();
            },

            normalizeSerieRow(row) {
                const item = row && typeof row === 'object' ? { ...row } : {};
                return {
                    id: Number(item.id || 0),
                    serie: this.normalizeSerieValue(item.serie),
                    tipo: ['IMEI', 'SERIAL'].includes(String(item.tipo || '').toUpperCase()) ? String(item.tipo).toUpperCase() : 'SERIAL',
                    estado: Number(item.estado ?? 1) === 0 ? 0 : 1,
                    id_sucursal: item.id_sucursal ?? '',
                    fecha_ingreso: String(item.fecha_ingreso || ''),
                    fecha_salida: String(item.fecha_salida || ''),
                    obs: String(item.obs || '').trim(),
                };
            },

            normalizeSeriesRows(rows) {
                const seen = new Set();
                const normalized = [];
                for (const row of Array.isArray(rows) ? rows : []) {
                    const item = this.normalizeSerieRow(row);
                    if (!item.serie || seen.has(item.serie)) continue;
                    seen.add(item.serie);
                    normalized.push(item);
                }
                return normalized;
            },
            
            async editarProducto(id) {
                this.showDetalle = false;
                this.resetForm();
                try {
                    const res = await fetch(`${this.API}/detalle.php?action=detalle&id=${id}&id_empresa=${this.idEmpresa}`);
                    const data = await res.json();
                    if (data.success && data.data) {
                        const p = data.data.producto;
                        // Cargar códigos de barra desde la tabla codigo_barra
                        const codigosDb = (data.data.codigos_barra || [])
                            .map(cb => String(cb.codigo_barra || '').trim()).filter(Boolean);
                        const codigoPrincipal = String(p.codigo_barra || codigosDb[0] || '').trim();
                        const codigosExtra = codigosDb
                            .filter(cb => cb !== codigoPrincipal)
                            .map(cb => ({ codigo: cb }));
                        this.form = {
                            idproducto: p.idproducto,
                            cve_producto: p.cve_producto || '',
                            desproducto: p.desproducto || '',
                            codigo_barra: codigoPrincipal,
                            precio_compra: parseFloat(p.precio_compra) || 0,
                            precio_venta: parseFloat(p.precio_venta) || 0,
                            grupo: p.grupo || 0,
                            marca: p.marca || 0,
                            iva: String(p.iva || 1),
                            impuesto: p.impuesto || 10,
                            unidad_medida: p.unidad_medida || '',
                            garantia_meses: parseInt(p.garantia_meses) || 0,
                            proveedor_principal: p.proveedor_principal || '',
                            ubicacion_fisica: p.ubicacion_fisica || '',
                            condicion_producto: p.condicion_producto || 'nuevo',
                            capacidad: p.capacidad || '',
                            ram: p.ram || '',
                            compatibilidad: p.compatibilidad || '',
                            incluye: p.incluye || '',
                            stock_minimo: parseFloat(p.stock_minimo) || 0,
                            stock_maximo: parseFloat(p.stock_maximo) || 0,
                            controla_stock: parseInt(p.controla_stock) || -1,
                            edita_precio: parseInt(p.edita_precio) || 1,
                            vende_sin_stock: parseInt(p.vende_sin_stock) || -1,
                            usaserial: parseInt(p.usaserial) || -1,
                            foto_url: p.foto_url || '',
                            foto_thumb_url: p.foto_thumb_url || p.foto_url || '',
                            foto_small_url: p.foto_small_url || p.foto_url || '',
                            foto_medium_url: p.foto_medium_url || p.foto_url || '',
                            foto_large_url: p.foto_large_url || p.foto_url || '',
                            precios: [],
                            series: this.normalizeSeriesRows(data.data.series || []),
                            codigos_barra: [],
                            codigos_barra_extra: codigosExtra,
                        };
                        // Cargar imágenes del producto (R2)
                        this.imgList = data.data.imagenes || [];
                    }
                } catch(e) {
                    this.showToast('Error al cargar', 'error');
                }
                this.showForm = true;
            },
            
            async guardar() {
                if (!this.form.desproducto?.trim()) {
                    this.showToast('Descripción obligatoria', 'error');
                    return;
                }
                this.saving = true;
                try {
                    const ivaMap = {1:10, 2:5, 3:0};
                    this.form.impuesto = ivaMap[this.form.iva] ?? 10;
                    this.form.series = (Array.isArray(this.form.series) ? this.form.series : [])
                        .map(sr => this.normalizeSerieRow(sr))
                        .filter(sr => sr.serie !== '');
                    
                    const payload = { ...this.form, id_empresa: this.idEmpresa };
                    // Indicar al API si es creación o edición
                    payload.action = this.form.idproducto ? 'update' : 'create';
                    // Stock inicial solo en creación
                    if (!this.form.idproducto && this.form.stock_inicial > 0) {
                        payload.stock_inicial = this.form.stock_inicial;
                        payload.id_sucursal_stock = this.form.id_sucursal || 1;
                    }
                    const todosLosCodigos = [
                        this.form.codigo_barra,
                        ...(this.form.codigos_barra_extra || []).map(cb => cb.codigo)
                    ].map(c => String(c || '').trim()).filter(Boolean);
                    // Eliminar duplicados
                    const codigosUnicos = [...new Set(todosLosCodigos)];
                    if (codigosUnicos.length) {
                        payload.codigos_barra = codigosUnicos;
                    }
                    
                    const res = await fetch(`${this.API}/guardar.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (data.success) {
                        const createdOrUpdatedId = Number(data.idproducto || this.form.idproducto || 0);
                        if (createdOrUpdatedId > 0) {
                            this.form.idproducto = createdOrUpdatedId;
                        }

                        if (createdOrUpdatedId > 0 && this.pendingProductImagePayload) {
                            this.imageActionProduct = {
                                idproducto: createdOrUpdatedId,
                                desproducto: String(this.form.desproducto || '')
                            };
                            await this.saveProductImagePayload(this.pendingProductImagePayload);
                            this.imageActionProduct = null;
                        }

                        this.showToast(data.message || 'Guardado', 'success');
                        this.closeBarcodeScannerForm();
                        this.closeFormModal();
                        this.currentPage = 1;
                        this.loadProductos();
                    } else {
                        this.showToast(data.error || 'Error al guardar', 'error');
                    }
                } catch(e) {
                    this.showToast('Error de conexión', 'error');
                }
                this.saving = false;
            },
            
            async desactivar(p) {
                if (!confirm(`¿Marcar como descontinuado "${p.desproducto}"?`)) return;
                try {
                    const res = await fetch(`${this.API}/eliminar.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'soft_delete', idproducto: p.idproducto, id_empresa: this.idEmpresa })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showDetalle = false;
                        this.showToast('Descontinuado', 'success');
                        this.loadProductos();
                    }
                } catch(e) {}
            },
            
            async activar(p) {
                try {
                    const res = await fetch(`${this.API}/eliminar.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'restore', idproducto: p.idproducto, id_empresa: this.idEmpresa })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showDetalle = false;
                        this.showToast('Activado', 'success');
                        this.loadProductos();
                    }
                } catch(e) {}
            },

            canPermanentDelete(p) {
                if (!p) return false;
                const s = parseFloat(p?.saldo) || 0;
                const hasMov = !!p?.tiene_movimientos;
                return s <= 0 && !hasMov;
            },

            async suprimir(p) {
                if (!p || !this.canPermanentDelete(p)) {
                    this.showToast('Solo se puede suprimir sin stock y sin movimientos', 'error');
                    return;
                }
                if (!confirm(`¿Suprimir permanentemente "${p.desproducto}"? Esta acción no se puede deshacer.`)) return;
                try {
                    const res = await fetch(`${this.API}/eliminar.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete_permanent', idproducto: p.idproducto, id_empresa: this.idEmpresa })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.showDetalle = false;
                        this.showToast('Producto suprimido', 'success');
                        this.currentPage = 1;
                        this.loadProductos();
                    } else {
                        this.showToast(data.error || 'No se pudo suprimir', 'error');
                    }
                } catch (_) {
                    this.showToast('Error de conexión', 'error');
                }
            },
            
            toggleTheme() {
                this.isDark = true;
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
            },
            
            formatMoney(n) { return new Intl.NumberFormat('es-PY', {maximumFractionDigits:0}).format(n || 0); },
            formatNumber(n) { return new Intl.NumberFormat('es-PY', {maximumFractionDigits:2}).format(n || 0); },
            
            stockClass(p) {
                const s = parseFloat(p?.saldo) || 0;
                const min = parseFloat(p?.stock_minimo) || 0;
                if (s <= 0) return 'text-red-600 dark:text-red-400';
                if (min > 0 && s <= min) return 'text-amber-600 dark:text-amber-400';
                return 'text-green-600 dark:text-green-400';
            },
            
            showToast(message, type = 'success') {
                this.toast = { show: true, message, type };
                setTimeout(() => { this.toast.show = false; }, 3000);
            },

            async clearAppCache() {
                try {
                    if ('caches' in window) {
                        const keys = await caches.keys();
                        await Promise.all(keys.map((k) => caches.delete(k)));
                    }
                    if (navigator.serviceWorker && navigator.serviceWorker.getRegistrations) {
                        const regs = await navigator.serviceWorker.getRegistrations();
                        await Promise.all(regs.map((r) => r.unregister()));
                    }
                    try {
                        localStorage.removeItem('sx_camera_perm_state');
                        localStorage.removeItem('sx_popular_products');
                        localStorage.removeItem('sx_popular_products_v2');
                    } catch (_) {}
                    this.showToast('Caché limpiado. Recargando...', 'success');
                    setTimeout(() => {
                        const u = new URL(window.location.href);
                        u.searchParams.set('v', String(Date.now()));
                        window.location.replace(u.toString());
                    }, 450);
                } catch (e) {
                    console.error('Error limpiando caché:', e);
                    this.showToast('No se pudo limpiar caché', 'error');
                }
            },

            async openBarcodeScannerForm() {
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    this.showToast('Tu navegador no soporta cámara para escaneo', 'error');
                    return;
                }
                await this.refreshCameraPermissionState();
                if (this.cameraPermissionState === 'denied') {
                    this.barcodeScannerErrorForm = 'Permiso de cámara bloqueado. Actívalo en ajustes del navegador/app.';
                    this.showToast('Cámara bloqueada', 'error');
                    return;
                }
                if (this.cameraPermissionState !== 'granted') {
                    const ok = await this.requestBarcodeCameraPermissionOnce();
                    if (!ok) {
                        this.showToast('No se pudo habilitar la cámara', 'error');
                        return;
                    }
                }
                this.showBarcodeScannerForm = true;
                this.barcodeScannerErrorForm = '';
                await this.$nextTick();
                await this.startBarcodeScannerForm();
            },

            async startBarcodeScannerForm() {
                try {
                    let stream = null;
                    try {
                        stream = await navigator.mediaDevices.getUserMedia({
                            video: {
                                facingMode: { ideal: 'environment' },
                                width: { ideal: 1280 },
                                height: { ideal: 720 }
                            },
                            audio: false
                        });
                    } catch (_) {
                        stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                    }

                    this.barcodeStreamForm = stream;
                    const video = this.$refs.barcodeVideoForm;
                    if (!video) throw new Error('Video no disponible');
                    video.srcObject = stream;
                    await video.play();

                    if ('BarcodeDetector' in window) {
                        this.barcodeDetectorForm = new BarcodeDetector({
                            formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'codabar']
                        });
                        this.barcodeDetectActiveForm = true;
                        this.barcodeScanLoopForm();
                    } else {
                        this.barcodeScannerErrorForm = 'Este dispositivo no soporta detector nativo. Carga el código manualmente.';
                    }
                } catch (err) {
                    const errorName = String(err?.name || '');
                    if (errorName === 'NotAllowedError' || errorName === 'PermissionDeniedError') {
                        this.barcodeScannerErrorForm = 'Permiso de cámara bloqueado. Habilítalo en configuración del navegador.';
                    } else if (errorName === 'NotFoundError' || errorName === 'DevicesNotFoundError') {
                        this.barcodeScannerErrorForm = 'No se encontró una cámara disponible en este dispositivo.';
                    } else if (errorName === 'NotReadableError' || errorName === 'TrackStartError') {
                        this.barcodeScannerErrorForm = 'La cámara está en uso por otra app. Cierra esa app y reintenta.';
                    } else {
                        this.barcodeScannerErrorForm = 'No se pudo iniciar la cámara. Verifica permisos del navegador.';
                    }
                    console.error('Barcode form camera error:', err);
                }
            },

            async barcodeScanLoopForm() {
                if (!this.barcodeDetectActiveForm || !this.showBarcodeScannerForm) return;
                const video = this.$refs.barcodeVideoForm;
                try {
                    if (video && video.readyState >= 2 && this.barcodeDetectorForm) {
                        const found = await this.barcodeDetectorForm.detect(video);
                        if (Array.isArray(found) && found.length > 0) {
                            const raw = String(found[0].rawValue || '').trim();
                            if (raw) {
                                this.barcodeDetectActiveForm = false;
                                this.form.codigo_barra = raw;
                                this.closeBarcodeScannerForm();
                                this.showToast('Código capturado', 'success');
                                return;
                            }
                        }
                    }
                } catch (_) {}
                requestAnimationFrame(() => this.barcodeScanLoopForm());
            },

            stopBarcodeScannerForm() {
                this.barcodeDetectActiveForm = false;
                this.barcodeDetectorForm = null;
                try {
                    if (this.barcodeStreamForm) {
                        this.barcodeStreamForm.getTracks().forEach((t) => t.stop());
                    }
                } catch (_) {}
                this.barcodeStreamForm = null;
                try {
                    const video = this.$refs.barcodeVideoForm;
                    if (video) video.srcObject = null;
                } catch (_) {}
            },

            closeBarcodeScannerForm() {
                this.stopBarcodeScannerForm();
                this.showBarcodeScannerForm = false;
            },

        }
    }
    </script>
</body>
</html>
