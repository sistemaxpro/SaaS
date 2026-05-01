<?php
require_once __DIR__ . '/../../config/bootstrap.php';

Session::requireLogin('/public/login.php');
Permission::requireAccess('app_grid_mercaderias');

$idEmpresa = Session::get('id_empresa', 169);
$idLogin = Session::get('id_login');
$idSucursal = (int)Session::get('id_sucursal', 0);
$sucursalNombre = (string)Session::get('sucursal', '');
$permisos = Permission::getAppPermissions('app_grid_mercaderias');
?>
<!DOCTYPE html>
<html lang="es" x-data="inventarioMobile()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Inventario Movil</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="/public/pos/js/smx-printer.js?v=1" defer></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        ink: '#101828',
                        ember: '#c2410c',
                        pine: '#065f46',
                        steel: '#334155'
                    }
                }
            }
        };
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
        body { -webkit-tap-highlight-color: transparent; }
    </style>
</head>
<body class="min-h-screen bg-[radial-gradient(circle_at_top,_#f7efe3,_#eef2f7_45%,_#e2e8f0)] dark:bg-[radial-gradient(circle_at_top,_#1e293b,_#0f172a_45%,_#020617)] text-slate-900 dark:text-slate-100">
<script>
window.__INV__ = {
    idEmpresa: <?= (int)$idEmpresa ?>,
    idLogin: <?= (int)$idLogin ?>,
    idSucursal: <?= (int)$idSucursal ?>,
    sucursalNombre: <?= json_encode($sucursalNombre) ?>,
    permisos: <?= json_encode($permisos) ?>,
    focusTransfers: <?= isset($_GET['focus']) && $_GET['focus'] === 'traslados' ? 'true' : 'false' ?>
};
</script>

<div class="mx-auto max-w-md min-h-screen pb-24">
    <header class="sticky top-0 z-30 backdrop-blur-xl bg-white/75 dark:bg-slate-950/70 border-b border-white/40 dark:border-slate-800">
        <div class="px-4 pt-4 pb-3">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.28em] text-amber-700 dark:text-amber-300 font-black">Inventario en vivo</p>
                    <h1 class="text-2xl font-black leading-tight">Control simple para varias sucursales</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1" x-text="'Sucursal activa: ' + (sucursalActualNombre || 'Sin sucursal')"></p>
                </div>
                <button @click="toggleTheme()" class="w-10 h-10 rounded-2xl bg-slate-900 text-white dark:bg-white dark:text-slate-900 flex items-center justify-center shadow-lg">
                    <i class="fas" :class="isDark ? 'fa-sun' : 'fa-moon'"></i>
                </button>
            </div>
            <div class="mt-4 flex items-center gap-2">
                <div class="relative flex-1">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    <input x-model="searchQuery" @input.debounce.350ms="buscarProductos()" type="text" placeholder="Buscar codigo, barra o descripcion..."
                           class="w-full rounded-2xl border border-slate-200 dark:border-slate-700 bg-white/90 dark:bg-slate-900/90 pl-10 pr-20 py-3 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/40">
                    <button type="button"
                            @click="toggleSearchVoice()"
                            :disabled="!speechSupported"
                            class="absolute right-10 top-1/2 -translate-y-1/2 w-8 h-8 rounded-xl flex items-center justify-center transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                            :class="speechListening ? 'bg-rose-600 text-white shadow-lg shadow-rose-600/20' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300'"
                            :title="speechSupported ? (speechListening ? 'Detener micrófono' : 'Buscar por voz') : 'Micrófono no soportado'">
                        <i class="fas" :class="speechListening ? 'fa-microphone-slash' : 'fa-microphone'"></i>
                    </button>
                    <button type="button"
                            @click="openBarcodeScanner()"
                            class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300 flex items-center justify-center transition-all"
                            title="Escanear código de barra">
                        <i class="fas fa-barcode"></i>
                    </button>
                </div>
                <button @click="syncAll(true)" class="w-12 h-12 rounded-2xl bg-amber-600 text-white shadow-lg shadow-amber-600/20 flex items-center justify-center">
                    <i class="fas fa-rotate"></i>
                </button>
            </div>
            <div class="mt-3">
                <label class="block text-[10px] uppercase tracking-[0.2em] text-slate-400 mb-1.5">Sucursal de trabajo</label>
                <select x-model.number="idSucursal" @change="handleSucursalChange()"
                        class="w-full rounded-2xl border border-slate-200 dark:border-slate-700 bg-white/90 dark:bg-slate-900/90 px-4 py-3 text-sm font-bold focus:outline-none focus:ring-2 focus:ring-amber-500/40">
                    <template x-for="sucursal in sucursales" :key="sucursal.id_sucursal">
                        <option :value="sucursal.id_sucursal" x-text="sucursal.sucursal"></option>
                    </template>
                </select>
            </div>
        </div>
    </header>

    <main class="px-4 pt-4 space-y-4">
        <section class="grid grid-cols-2 gap-3">
            <div class="rounded-3xl bg-white/90 dark:bg-slate-900/90 border border-white/70 dark:border-slate-800 p-4 shadow-sm">
                <p class="text-[11px] uppercase tracking-[0.22em] text-slate-400">Productos</p>
                <p class="mt-2 text-3xl font-black" x-text="stats.total_productos"></p>
                <p class="text-xs text-slate-500 dark:text-slate-400">catalogo total</p>
            </div>
            <div class="rounded-3xl bg-white/90 dark:bg-slate-900/90 border border-white/70 dark:border-slate-800 p-4 shadow-sm">
                <p class="text-[11px] uppercase tracking-[0.22em] text-slate-400">Sin stock</p>
                <p class="mt-2 text-3xl font-black text-rose-600 dark:text-rose-400" x-text="stats.sin_stock"></p>
                <p class="text-xs text-slate-500 dark:text-slate-400">en sucursal activa</p>
            </div>
            <div class="rounded-3xl bg-white/90 dark:bg-slate-900/90 border border-white/70 dark:border-slate-800 p-4 shadow-sm">
                <p class="text-[11px] uppercase tracking-[0.22em] text-slate-400">Stock bajo</p>
                <p class="mt-2 text-3xl font-black text-amber-600 dark:text-amber-300" x-text="stats.stock_bajo"></p>
                <p class="text-xs text-slate-500 dark:text-slate-400">reponer pronto</p>
            </div>
            <div class="rounded-3xl bg-gradient-to-br from-emerald-600 to-teal-700 text-white p-4 shadow-lg shadow-emerald-900/20">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] uppercase tracking-[0.22em] text-white/70">Live</p>
                    <span class="inline-flex items-center gap-1 text-[11px] font-bold">
                        <span class="w-2 h-2 rounded-full bg-white animate-pulse"></span>
                        <span x-text="syncInProgress ? 'sincronizando' : 'activo'"></span>
                    </span>
                </div>
                <p class="mt-2 text-lg font-black" x-text="syncInProgress ? ('Sincronizando ' + syncProgress + '%') : lastSyncLabel"></p>
                <div class="mt-3 h-2 rounded-full bg-white/20 overflow-hidden">
                    <div class="h-full rounded-full bg-white transition-all duration-300" :style="`width:${syncProgress}%`"></div>
                </div>
                <p class="mt-2 text-xs text-white/80" x-text="syncStatusText || 'sincroniza al abrir o al pulsar actualizar'"></p>
            </div>
        </section>

        <section id="inventarioTrasladosSection" class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-black uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Resultados</h2>
                <span class="text-xs text-slate-400" x-text="loadingSearch ? 'Buscando...' : (productos.length + ' items')"></span>
            </div>
            <div x-show="loadingSearch" class="rounded-3xl border border-dashed border-slate-300 dark:border-slate-700 p-6 text-center text-sm text-slate-500">Cargando productos...</div>
            <template x-for="producto in productos" :key="producto.idproducto">
                <button @click="abrirProducto(producto.idproducto)"
                        class="w-full text-left rounded-3xl bg-white/90 dark:bg-slate-900/90 border border-white/70 dark:border-slate-800 p-4 shadow-sm">
                    <div class="flex items-start gap-3">
                        <div class="w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-800 overflow-hidden flex items-center justify-center">
                            <img x-show="producto.foto_url" :src="producto.foto_url" class="w-full h-full object-cover" @error="$el.style.display='none'">
                            <i x-show="!producto.foto_url" class="fas fa-box-open text-slate-400"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-sm font-black truncate" x-text="producto.desproducto"></p>
                                <span class="text-[10px] font-black px-2 py-1 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-500" x-text="producto.cve_producto"></span>
                            </div>
                            <p class="text-[11px] text-slate-500 mt-1" x-text="producto.referencia || 'Sin referencia'"></p>
                            <div class="mt-3 flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Sucursal actual</p>
                                    <p class="text-lg font-black" :class="Number(producto.stock_actual_sucursal) > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'" x-text="formatNumber(producto.stock_actual_sucursal)"></p>
                                </div>
                                <div class="text-right">
                                    <p class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Global</p>
                                    <p class="text-lg font-black text-slate-900 dark:text-slate-100" x-text="formatNumber(producto.stock_global)"></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </button>
            </template>
            <div x-show="!loadingSearch && productos.length === 0" class="rounded-3xl border border-dashed border-slate-300 dark:border-slate-700 p-8 text-center">
                <i class="fas fa-layer-group text-3xl text-slate-300 dark:text-slate-700"></i>
                <p class="mt-3 text-sm text-slate-500">No hay resultados para esta busqueda.</p>
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-black uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Traslados pendientes</h2>
                <span class="text-xs text-slate-400" x-text="trasladosPendientes.length + ' registros'"></span>
            </div>
            <div class="space-y-2">
                <template x-for="traslado in trasladosPendientes" :key="'tp-' + traslado.id">
                    <div class="rounded-3xl bg-white/90 dark:bg-slate-900/90 border border-white/70 dark:border-slate-800 p-4 shadow-sm">
                        <div class="flex items-start gap-3">
                            <div class="w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-800 overflow-hidden flex items-center justify-center flex-shrink-0">
                                <img x-show="traslado.foto_url" :src="traslado.foto_url" class="w-full h-full object-cover" @error="$el.style.display='none'">
                                <i x-show="!traslado.foto_url" class="fas fa-truck text-slate-400"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-black truncate" x-text="traslado.desproducto"></p>
                                        <p class="text-[11px] mt-1 text-slate-500" x-text="traslado.cve_producto + ' · Ref ' + traslado.referencia"></p>
                                    </div>
                                    <span class="text-[10px] font-black px-2 py-1 rounded-full"
                                          :class="traslado.puede_recibir ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'"
                                          x-text="traslado.puede_recibir ? 'Por recibir' : 'En tránsito'"></span>
                                </div>
                                <p class="mt-2 text-[11px] text-slate-500" x-text="traslado.sucursal_origen + ' → ' + traslado.sucursal_destino"></p>
                                <div class="mt-3 flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Cantidad</p>
                                        <p class="text-lg font-black text-sky-600 dark:text-sky-400" x-text="formatNumber(traslado.cantidad)"></p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <a :href="`/public/inventario/traslado_label.php?id=${encodeURIComponent(traslado.id)}&id_empresa=${encodeURIComponent(idEmpresa)}`"
                                           target="_blank" rel="noopener noreferrer"
                                           class="rounded-2xl bg-amber-500 text-white px-4 py-2.5 text-xs font-black inline-flex items-center justify-center">
                                            Etiqueta
                                        </a>
                                        <a :href="`/public/inventario/traslado_print.php?id=${encodeURIComponent(traslado.id)}&id_empresa=${encodeURIComponent(idEmpresa)}`"
                                           target="_blank" rel="noopener noreferrer"
                                           class="rounded-2xl bg-slate-900 dark:bg-white dark:text-slate-900 text-white px-4 py-2.5 text-xs font-black inline-flex items-center justify-center">
                                            Imprimir
                                        </a>
                                        <button x-show="traslado.puede_recibir && canReceiveTransfer()"
                                                @click="recibirTraslado(traslado)"
                                                class="rounded-2xl bg-emerald-600 text-white px-4 py-2.5 text-xs font-black">
                                            Recibir
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
            <div x-show="trasladosPendientes.length === 0" class="rounded-3xl border border-dashed border-slate-300 dark:border-slate-700 p-6 text-center text-sm text-slate-500">
                No hay traslados pendientes para esta sucursal.
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-black uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">Movimientos recientes</h2>
                <span class="text-xs text-slate-400" x-text="movimientos.length + ' registros'"></span>
            </div>
            <div class="space-y-2">
                <template x-for="mov in movimientos" :key="mov.id">
                    <div class="rounded-3xl bg-white/90 dark:bg-slate-900/90 border border-white/70 dark:border-slate-800 p-4 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-black" x-text="mov.descripcion"></p>
                                <p class="text-[11px] mt-1 text-slate-500" x-text="mov.movimiento + ' · ' + mov.nombre_sucursal"></p>
                                <p class="text-[11px] text-slate-400 mt-1" x-text="mov.fecha"></p>
                            </div>
                            <div class="text-right">
                                <p class="text-base font-black" :class="mov.direccion === 'entrada' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'" x-text="(mov.direccion === 'entrada' ? '+' : '-') + formatNumber(mov.cantidad)"></p>
                                <p class="text-[11px] text-slate-400" x-text="'Ref: ' + (mov.referencia || '-')"></p>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </section>
    </main>
</div>

<div x-show="showProductoModal" x-cloak class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeProductoModal()"></div>
    <div class="absolute inset-x-0 bottom-0 max-h-[90vh] overflow-y-auto rounded-t-[2rem] bg-white dark:bg-slate-950 border-t border-white/60 dark:border-slate-800">
        <div class="sticky top-0 bg-white/90 dark:bg-slate-950/90 backdrop-blur px-4 pt-4 pb-3 border-b border-slate-100 dark:border-slate-800">
            <div class="w-12 h-1.5 rounded-full bg-slate-200 dark:bg-slate-700 mx-auto mb-4"></div>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-amber-700 dark:text-amber-300 font-black">Producto</p>
                    <h3 class="text-lg font-black leading-tight" x-text="productoDetalle?.desproducto || ''"></h3>
                    <p class="text-xs text-slate-500 mt-1" x-text="productoDetalle?.cve_producto || ''"></p>
                </div>
                <button @click="closeProductoModal()" class="w-10 h-10 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="px-4 py-4 space-y-4">
            <div x-show="loadingDetalle" class="rounded-3xl border border-dashed border-slate-300 dark:border-slate-700 p-8 text-center text-sm text-slate-500">Cargando detalle...</div>
            <template x-if="productoDetalle">
                <div class="space-y-4">
                    <div class="grid grid-cols-3 gap-3">
                        <div class="rounded-3xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4">
                            <p class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Actual</p>
                            <p class="mt-2 text-2xl font-black" :class="Number(productoDetalle.stock_actual_sucursal) > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'" x-text="formatNumber(productoDetalle.stock_actual_sucursal)"></p>
                        </div>
                        <div class="rounded-3xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4">
                            <p class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Global</p>
                            <p class="mt-2 text-2xl font-black" x-text="formatNumber(productoDetalle.saldo)"></p>
                        </div>
                        <div class="rounded-3xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4">
                            <p class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Minimo</p>
                            <p class="mt-2 text-2xl font-black text-amber-600 dark:text-amber-300" x-text="formatNumber(productoDetalle.stock_minimo)"></p>
                        </div>
                    </div>

                    <div class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-black uppercase tracking-[0.18em] text-slate-500">Stock por sucursal</h4>
                            <span class="text-xs text-slate-400" x-text="(productoDetalle.stock_sucursales || []).length + ' sucursales'"></span>
                        </div>
                        <div class="mt-3 space-y-2">
                            <template x-for="item in (productoDetalle.stock_sucursales || [])" :key="item.id_sucursal">
                                <div class="flex items-center justify-between rounded-2xl bg-slate-50 dark:bg-slate-950 px-3 py-2 border border-slate-200 dark:border-slate-800">
                                    <span class="text-sm font-semibold" x-text="item.sucursal"></span>
                                    <span class="text-base font-black" :class="Number(item.stock) > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'" x-text="formatNumber(item.stock)"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2">
                        <button x-show="canAdjust()" @click="openAction('ajuste', 'entrada')" class="rounded-2xl bg-emerald-600 text-white py-3 text-sm font-black">Entrada</button>
                        <button x-show="canAdjust()" @click="openAction('ajuste', 'salida')" class="rounded-2xl bg-rose-600 text-white py-3 text-sm font-black">Salida</button>
                        <button x-show="canTransfer()" @click="openAction('traslado')" class="rounded-2xl bg-amber-600 text-white py-3 text-sm font-black">Traslado</button>
                        <button x-show="canCount()" @click="openAction('conteo')" class="col-span-3 rounded-2xl bg-slate-900 dark:bg-white dark:text-slate-900 text-white py-3 text-sm font-black">Conteo fisico</button>
                    </div>

                    <div class="rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-black uppercase tracking-[0.18em] text-slate-500">Kardex</h4>
                            <button @click="refreshDetalleMovimientos()" class="text-xs font-bold text-amber-700 dark:text-amber-300">Actualizar</button>
                        </div>
                        <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
                            <template x-for="tab in detalleKardexTabs()" :key="'tab-' + tab.key">
                                <button @click="detalleKardexTab = tab.key"
                                        class="px-3 py-2 rounded-2xl text-xs font-black whitespace-nowrap border transition-colors"
                                        :class="detalleKardexTab === tab.key
                                            ? 'bg-slate-900 text-white border-slate-900 dark:bg-white dark:text-slate-900 dark:border-white'
                                            : 'bg-slate-50 text-slate-600 border-slate-200 dark:bg-slate-950 dark:text-slate-300 dark:border-slate-800'"
                                        x-text="tab.label"></button>
                            </template>
                        </div>
                        <div class="mt-3 flex items-center justify-between">
                            <h5 class="text-xs font-black uppercase tracking-[0.18em]"
                                :class="detalleKardexTab === 'global' ? 'text-amber-700 dark:text-amber-300' : 'text-sky-700 dark:text-sky-300'"
                                x-text="detalleKardexTabLabel()"></h5>
                            <span class="text-[11px] text-slate-400" x-text="detalleKardexMovimientos().length + ' movimientos'"></span>
                        </div>
                        <div class="mt-2 space-y-2">
                            <template x-for="mov in detalleKardexMovimientos()" :key="'k-' + detalleKardexTab + '-' + mov.id">
                                <div class="rounded-2xl bg-slate-50 dark:bg-slate-950 px-3 py-2 border border-slate-200 dark:border-slate-800">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-bold" x-text="mov.movimiento"></p>
                                            <p class="text-[11px] text-slate-500 mt-1" x-text="mov.nombre_sucursal + ' · ' + mov.fecha"></p>
                                            <p class="text-[11px] text-slate-400 mt-1" x-show="mov.operador_nombre" x-text="'Operador: ' + mov.operador_nombre"></p>
                                            <p class="text-[11px] text-slate-400 mt-1" x-show="mov.documento_nro" x-text="(mov.documento_tipo || 'Documento') + ': ' + mov.documento_nro"></p>
                                            <p class="text-[11px] text-slate-400 mt-1" x-show="mov.cliente_nombre" x-text="'Cliente: ' + mov.cliente_nombre"></p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-sm font-black" :class="mov.direccion === 'entrada' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'" x-text="(mov.direccion === 'entrada' ? '+' : '-') + formatNumber(mov.cantidad)"></p>
                                            <p class="text-[11px] text-slate-400" x-text="mov.referencia || '-'"></p>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            <div x-show="detalleKardexMovimientos().length === 0" class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-700 px-3 py-4 text-center text-xs text-slate-500">
                                Sin movimientos en esta pestaña.
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<div class="fixed top-4 left-1/2 -translate-x-1/2 z-[80] w-[92vw] max-w-sm space-y-2 pointer-events-none">
    <template x-for="toast in toasts" :key="toast.id">
        <div x-show="toast.show"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-2 scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-2"
             class="pointer-events-auto rounded-2xl px-4 py-3 shadow-xl border backdrop-blur-xl"
             :class="toast.type === 'error'
                ? 'bg-rose-600/95 text-white border-rose-400/40'
                : (toast.type === 'success'
                    ? 'bg-emerald-600/95 text-white border-emerald-400/40'
                    : 'bg-slate-900/95 text-white border-slate-700/60')">
            <div class="flex items-start gap-3">
                <i class="fas mt-0.5" :class="toast.type === 'error' ? 'fa-circle-exclamation' : (toast.type === 'success' ? 'fa-circle-check' : 'fa-circle-info')"></i>
                <p class="text-sm font-semibold leading-snug" x-text="toast.message"></p>
            </div>
        </div>
    </template>
</div>

<div x-show="showActionModal" x-cloak class="fixed inset-0 z-[60]">
    <div class="absolute inset-0 bg-black/60" @click="closeActionModal()"></div>
    <div class="absolute inset-x-0 bottom-0 rounded-t-[2rem] bg-white dark:bg-slate-950 border-t border-white/60 dark:border-slate-800">
        <div class="px-4 pt-4 pb-5 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-amber-700 dark:text-amber-300 font-black">Operacion</p>
                    <h3 class="text-lg font-black" x-text="actionTitle"></h3>
                </div>
                <button @click="closeActionModal()" class="w-10 h-10 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="space-y-3">
                <div>
                    <label class="block text-[11px] uppercase tracking-[0.18em] text-slate-400 mb-1">Cantidad</label>
                    <input type="number" x-model.number="actionForm.cantidad" min="0" step="0.01" class="w-full rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 font-black focus:outline-none focus:ring-2 focus:ring-amber-500/40">
                </div>
                <div x-show="actionForm.action === 'traslado'">
                    <label class="block text-[11px] uppercase tracking-[0.18em] text-slate-400 mb-1">Destino</label>
                    <select x-model.number="actionForm.id_sucursal_destino" class="w-full rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/40">
                        <template x-for="s in sucursales.filter(item => Number(item.id_sucursal) !== Number(idSucursal))" :key="s.id_sucursal">
                            <option :value="s.id_sucursal" x-text="s.sucursal"></option>
                        </template>
                    </select>
                </div>
                <div x-show="actionForm.action === 'conteo'">
                    <label class="block text-[11px] uppercase tracking-[0.18em] text-slate-400 mb-1">Stock fisico</label>
                    <input type="number" x-model.number="actionForm.stock_fisico" min="0" step="0.01" class="w-full rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 font-black focus:outline-none focus:ring-2 focus:ring-amber-500/40">
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-[0.18em] text-slate-400 mb-1">Observacion</label>
                    <textarea x-model="actionForm.obs" rows="3" class="w-full rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/40"></textarea>
                </div>
                <button @click="submitAction()" :disabled="savingAction" class="w-full rounded-2xl bg-amber-600 text-white py-3.5 text-sm font-black disabled:opacity-50">
                    <span x-show="!savingAction">Guardar movimiento</span>
                    <span x-show="savingAction">Procesando...</span>
                </button>
            </div>
        </div>
    </div>
</div>

<div x-show="showBarcodeScanner" x-cloak class="fixed inset-0 z-[70] bg-black/80 flex items-end">
    <div class="w-full rounded-t-[2rem] bg-white dark:bg-slate-950 border-t border-white/60 dark:border-slate-800">
        <div class="px-4 pt-4 pb-5 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-amber-700 dark:text-amber-300 font-black">Escaner</p>
                    <h3 class="text-lg font-black">Capturar código de barra</h3>
                </div>
                <button @click="closeBarcodeScanner()" class="w-10 h-10 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="rounded-3xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-900">
                <div class="relative aspect-[4/3]">
                    <video x-ref="barcodeVideo" autoplay playsinline muted class="w-full h-full object-cover"></video>
                    <div class="absolute inset-0 pointer-events-none flex items-center justify-center">
                        <div class="w-[72%] h-[32%] border-2 border-emerald-400 rounded-3xl shadow-[0_0_0_9999px_rgba(15,23,42,0.28)]"></div>
                    </div>
                </div>
            </div>
            <p x-show="barcodeScannerError" class="text-sm text-rose-600 dark:text-rose-400 font-semibold" x-text="barcodeScannerError"></p>
            <input x-model="searchQuery" @input.debounce.200ms="buscarProductos()" type="text" placeholder="O cargá el código manualmente..."
                   class="w-full rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/40">
            <button @click="closeBarcodeScanner()" class="w-full rounded-2xl bg-slate-900 dark:bg-white dark:text-slate-900 text-white py-3 text-sm font-black">Cerrar escáner</button>
        </div>
    </div>
</div>

<script>
function inventarioMobile() {
    return {
        isDark: document.documentElement.classList.contains('dark'),
        idEmpresa: Number(window.__INV__.idEmpresa || 0),
        idLogin: Number(window.__INV__.idLogin || 0),
        idSucursal: Number(window.__INV__.idSucursal || 0),
        sucursalActualNombre: window.__INV__.sucursalNombre || '',
        permisos: window.__INV__.permisos || {},
        api: '/public/productos/api/stock.php',
        stats: { total_productos: 0, activos: 0, sin_stock: 0, stock_bajo: 0 },
        lastSyncLabel: 'Sincronizando...',
        syncInProgress: false,
        syncProgress: 0,
        syncStatusText: 'Preparando sincronización...',
        searchQuery: '',
        productos: [],
        movimientos: [],
        trasladosPendientes: [],
        sucursales: [],
        loadingSearch: false,
        loadingDetalle: false,
        showProductoModal: false,
        showActionModal: false,
        showBarcodeScanner: false,
        productoDetalle: null,
        detalleKardexTab: 'global',
        savingAction: false,
        speechSupported: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
        speechListening: false,
        speechRecognition: null,
        barcodeScannerError: '',
        barcodeStream: null,
        barcodeDetector: null,
        barcodeDetectActive: false,
        smxPrinter: null,
        cameraPermissionState: localStorage.getItem('sx_camera_perm_state') || 'prompt',
        toasts: [],
        toastSeq: 0,
        actionTitle: '',
        actionForm: {
            action: 'ajuste',
            tipo: 'entrada',
            cantidad: '',
            stock_fisico: '',
            id_sucursal_destino: '',
            obs: ''
        },
        offlineKey(suffix) {
            return `sx_inventario_mobile_offline:${this.idEmpresa}:${suffix}`;
        },
        readOfflineCache(suffix, fallback = null) {
            return window.SmxOfflineDb?.getSync(this.offlineKey(suffix), fallback) ?? fallback;
        },
        writeOfflineCache(suffix, data) {
            return window.SmxOfflineDb?.set(this.offlineKey(suffix), data);
        },
        mergeOfflineProductos(suffix, items) {
            const snapshot = this.readOfflineCache(suffix, []);
            const map = new Map((Array.isArray(snapshot) ? snapshot : []).map((item) => [String(item?.idproducto || item?.codigo || item?.cve_producto || ''), item]));
            (Array.isArray(items) ? items : []).forEach((item) => {
                const key = String(item?.idproducto || item?.codigo || item?.cve_producto || '');
                if (key) map.set(key, item);
            });
            const merged = Array.from(map.values());
            this.writeOfflineCache(suffix, merged);
            return merged;
        },
        filterOfflineProductos(items) {
            const search = String(this.searchQuery || '').trim().toLowerCase();
            return (Array.isArray(items) ? items : []).filter((p) => {
                if (!search) return true;
                const haystack = [p?.descripcion, p?.desproducto, p?.codigo, p?.cve_producto, p?.codigo_barra].join(' ').toLowerCase();
                return haystack.includes(search);
            });
        },

        async init() {
            await (window.SmxOfflineDb?.ready || Promise.resolve());
            Object.keys(this).forEach((key) => {
                if (key === 'init') return;
                if (typeof this[key] === 'function') this[key] = this[key].bind(this);
            });
            const savedSucursal = Number(localStorage.getItem('inventario_mobile_sucursal') || 0);
            if (savedSucursal > 0) this.idSucursal = savedSucursal;
            this.loadSucursales().then(() => {
                this.syncAll(false);
                if (window.__INV__.focusTransfers) {
                    setTimeout(() => {
                        document.getElementById('inventarioTrasladosSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }, 220);
                }
            });
        },

        toggleTheme() {
            this.isDark = !this.isDark;
            document.documentElement.classList.toggle('dark', this.isDark);
            localStorage.theme = this.isDark ? 'dark' : 'light';
        },

        formatNumber(value) {
            const num = Number(value || 0);
            return num.toLocaleString('es-PY', { minimumFractionDigits: num % 1 === 0 ? 0 : 2, maximumFractionDigits: 2 });
        },

        pushToast(message, type = 'info', duration = 2600) {
            const id = ++this.toastSeq;
            this.toasts.push({ id, message: String(message || ''), type, show: true });
            setTimeout(() => {
                const item = this.toasts.find((toast) => toast.id === id);
                if (item) item.show = false;
                setTimeout(() => {
                    this.toasts = this.toasts.filter((toast) => toast.id !== id);
                }, 180);
            }, duration);
        },

        async fetchJson(url, options = {}) {
            const res = await fetch(url, options);
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.success === false) {
                throw new Error(data.error || data.message || 'Error de conexion');
            }
            return data;
        },

        notify(message, type = 'info') {
            this.pushToast(message, type);
        },

        async ensurePrinterReady() {
            if (!window.SmxPrinter) throw new Error('Bridge de impresión no disponible');
            if (!this.smxPrinter) this.smxPrinter = new SmxPrinter({ agentTimeoutMs: 1800 });
            if (!this.smxPrinter.isActive()) await this.smxPrinter.connect();
            const printer = String(await this.smxPrinter.getDefaultPrinter() || '').trim();
            if (!printer) throw new Error('No hay impresora predeterminada');
            const found = await this.smxPrinter.findPrinters(printer);
            if (!found || found.length === 0) throw new Error('Impresora no encontrada');
            return found[0];
        },

        canInsert() {
            return String(this.permisos?.priv_insert || 'N') === 'Y';
        },

        canUpdate() {
            return String(this.permisos?.priv_update || 'N') === 'Y';
        },

        canAdjust() {
            return this.canInsert();
        },

        canTransfer() {
            return this.canInsert();
        },

        canCount() {
            return this.canInsert();
        },

        canReceiveTransfer() {
            return this.canUpdate();
        },

        async handleSucursalChange() {
            localStorage.setItem('inventario_mobile_sucursal', String(this.idSucursal || 0));
            const found = this.sucursales.find((item) => Number(item.id_sucursal) === Number(this.idSucursal));
            if (found) this.sucursalActualNombre = found.sucursal;
            await this.syncAll(true);
            if (this.showProductoModal && this.productoDetalle?.idproducto) {
                await this.refreshDetalleMovimientos();
            }
            this.notify('Sucursal actualizada', 'success');
        },

        startSyncStatus(message, progress) {
            this.syncInProgress = true;
            this.syncStatusText = message;
            this.syncProgress = Math.max(0, Math.min(100, Number(progress || 0)));
        },

        finishSyncStatus() {
            this.syncInProgress = false;
            this.syncProgress = 100;
                    this.syncStatusText = 'Sincronización completa';
            this.lastSyncLabel = new Date().toLocaleTimeString('es-PY', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            setTimeout(() => {
                if (!this.syncInProgress) {
                    this.syncStatusText = 'sincroniza al abrir o al pulsar actualizar';
                    this.syncProgress = 0;
                }
            }, 1200);
        },

        async syncAll(silent = false) {
            try {
                this.startSyncStatus('Conectando con inventario...', 8);
                await this.loadDashboard(silent, false);
                this.startSyncStatus('Actualizando productos...', 62);
                await this.buscarProductos(false);
                if (this.showProductoModal && this.productoDetalle?.idproducto) {
                    this.startSyncStatus('Refrescando detalle del producto...', 88);
                    await this.refreshDetalleSilencioso();
                }
                this.finishSyncStatus();
            } catch (e) {
                this.syncInProgress = false;
                this.syncStatusText = 'Error de sincronización';
                this.syncProgress = 0;
                if (!silent) this.notify(e.message || 'No se pudo sincronizar inventario', 'error');
            }
        },

        toggleSearchVoice() {
            if (this.speechListening) {
                this.stopSearchVoice(false);
                return;
            }
            this.startSearchVoice();
        },

        startSearchVoice() {
            if (!this.speechSupported || this.speechListening) return;
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SpeechRecognition) {
                this.speechSupported = false;
                this.notify('Micrófono no soportado en este navegador', 'error');
                return;
            }
            try {
                const recognition = new SpeechRecognition();
                recognition.lang = 'es-PY';
                recognition.continuous = false;
                recognition.interimResults = true;

                recognition.onstart = () => {
                    this.speechListening = true;
                };
                recognition.onresult = (event) => {
                    const chunks = [];
                    for (let i = event.resultIndex; i < event.results.length; i++) {
                        const transcript = String(event.results[i]?.[0]?.transcript || '').replace(/\s+/g, ' ').trim();
                        if (transcript) chunks.push(transcript);
                    }
                    const text = chunks.join(' ').replace(/\s+/g, ' ').trim();
                    if (text) {
                        this.searchQuery = text;
                        this.buscarProductos();
                    }
                };
                recognition.onerror = (event) => {
                    const code = String(event?.error || '');
                    if (code !== 'aborted') {
                        this.notify('No se pudo usar el micrófono', 'error');
                    }
                };
                recognition.onend = () => {
                    this.speechListening = false;
                    this.speechRecognition = null;
                };

                this.speechRecognition = recognition;
                recognition.start();
            } catch (e) {
                this.speechListening = false;
                this.speechRecognition = null;
                this.notify('No se pudo iniciar dictado por voz', 'error');
            }
        },

        stopSearchVoice(forceAbort = false) {
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
        },

        async refreshCameraPermissionState() {
            try {
                if (!navigator.permissions || !navigator.permissions.query) return;
                const p = await navigator.permissions.query({ name: 'camera' });
                this.cameraPermissionState = p.state || 'prompt';
                localStorage.setItem('sx_camera_perm_state', this.cameraPermissionState);
                if (typeof p.onchange !== 'undefined') {
                    p.onchange = () => {
                        this.cameraPermissionState = p.state || 'prompt';
                        localStorage.setItem('sx_camera_perm_state', this.cameraPermissionState);
                    };
                }
            } catch (_) {}
        },

        async requestBarcodeCameraPermissionOnce() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                try { stream.getTracks().forEach((track) => track.stop()); } catch (_) {}
                this.cameraPermissionState = 'granted';
                localStorage.setItem('sx_camera_perm_state', 'granted');
                return true;
            } catch (err) {
                const code = String(err?.name || '');
                if (code === 'NotAllowedError' || code === 'PermissionDeniedError') {
                    this.cameraPermissionState = 'denied';
                    localStorage.setItem('sx_camera_perm_state', 'denied');
                    this.barcodeScannerError = 'Permiso de cámara bloqueado. Actívalo en ajustes del navegador/app.';
                }
                return false;
            }
        },

        async openBarcodeScanner() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                this.notify('Tu navegador no soporta cámara para escaneo', 'error');
                return;
            }
            await this.refreshCameraPermissionState();
            if (this.cameraPermissionState === 'denied') {
                this.barcodeScannerError = 'Permiso de cámara bloqueado. Actívalo en ajustes del navegador/app.';
                this.notify('Cámara bloqueada', 'error');
                return;
            }
            if (this.cameraPermissionState !== 'granted') {
                const ok = await this.requestBarcodeCameraPermissionOnce();
                if (!ok) {
                    this.notify('No se pudo habilitar la cámara', 'error');
                    return;
                }
            }
            this.showBarcodeScanner = true;
            this.barcodeScannerError = '';
            await this.$nextTick();
            await this.startBarcodeScanner();
        },

        async startBarcodeScanner() {
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

                this.barcodeStream = stream;
                const video = this.$refs.barcodeVideo;
                if (!video) throw new Error('Video no disponible');
                video.srcObject = stream;
                await video.play();

                if ('BarcodeDetector' in window) {
                    this.barcodeDetector = new BarcodeDetector({
                        formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'codabar']
                    });
                    this.barcodeDetectActive = true;
                    this.barcodeScanLoop();
                } else {
                    this.barcodeScannerError = 'Este dispositivo no soporta detector nativo. Cargá el código manualmente.';
                }
            } catch (err) {
                const errorName = String(err?.name || '');
                if (errorName === 'NotAllowedError' || errorName === 'PermissionDeniedError') {
                    this.barcodeScannerError = 'Permiso de cámara bloqueado. Habilítalo en configuración del navegador.';
                } else if (errorName === 'NotFoundError' || errorName === 'DevicesNotFoundError') {
                    this.barcodeScannerError = 'No se encontró una cámara disponible en este dispositivo.';
                } else if (errorName === 'NotReadableError' || errorName === 'TrackStartError') {
                    this.barcodeScannerError = 'La cámara está en uso por otra app. Cierra esa app y reintenta.';
                } else {
                    this.barcodeScannerError = 'No se pudo iniciar la cámara. Verifica permisos del navegador.';
                }
            }
        },

        async barcodeScanLoop() {
            if (!this.barcodeDetectActive || !this.showBarcodeScanner) return;
            const video = this.$refs.barcodeVideo;
            try {
                if (video && video.readyState >= 2 && this.barcodeDetector) {
                    const found = await this.barcodeDetector.detect(video);
                    if (Array.isArray(found) && found.length > 0) {
                        const raw = String(found[0].rawValue || '').trim();
                        if (raw) {
                            this.barcodeDetectActive = false;
                            this.searchQuery = raw;
                            this.closeBarcodeScanner();
                            this.buscarProductos();
                            this.notify('Código capturado', 'success');
                            return;
                        }
                    }
                }
            } catch (_) {}
            requestAnimationFrame(() => this.barcodeScanLoop());
        },

        stopBarcodeScanner() {
            this.barcodeDetectActive = false;
            this.barcodeDetector = null;
            try {
                if (this.barcodeStream) {
                    this.barcodeStream.getTracks().forEach((track) => track.stop());
                }
            } catch (_) {}
            this.barcodeStream = null;
            try {
                const video = this.$refs.barcodeVideo;
                if (video) video.srcObject = null;
            } catch (_) {}
        },

        closeBarcodeScanner() {
            this.stopBarcodeScanner();
            this.showBarcodeScanner = false;
        },

        async loadSucursales() {
            const data = await this.fetchJson(`${this.api}?action=sucursales&id_empresa=${this.idEmpresa}`);
            this.sucursales = Array.isArray(data.data) ? data.data : [];
            this.writeOfflineCache('sucursales', this.sucursales);
            if ((!this.idSucursal || !this.sucursales.some((item) => Number(item.id_sucursal) === Number(this.idSucursal))) && this.sucursales.length > 0) {
                this.idSucursal = Number(this.sucursales[0].id_sucursal || 0);
            }
            if (!this.sucursalActualNombre && this.idSucursal > 0) {
                const found = this.sucursales.find((item) => Number(item.id_sucursal) === this.idSucursal);
                if (found) this.sucursalActualNombre = found.sucursal;
            }
        },

        async loadDashboard(silent = false, touchStatus = true) {
            try {
                if (touchStatus) this.startSyncStatus('Actualizando dashboard...', 35);
                const data = await this.fetchJson(`${this.api}?action=dashboard&id_empresa=${this.idEmpresa}&id_sucursal=${this.idSucursal}`);
                this.stats = data.data?.stats || this.stats;
                this.movimientos = Array.isArray(data.data?.movimientos) ? data.data.movimientos : [];
                this.trasladosPendientes = Array.isArray(data.data?.traslados_pendientes) ? data.data.traslados_pendientes : [];
                this.writeOfflineCache(`dashboard:${this.idSucursal}`, {
                    stats: this.stats,
                    movimientos: this.movimientos,
                    trasladosPendientes: this.trasladosPendientes
                });
                if (this.showProductoModal && this.productoDetalle?.idproducto) {
                    this.refreshDetalleSilencioso();
                }
            } catch (e) {
                const cached = this.readOfflineCache(`dashboard:${this.idSucursal}`, null);
                if (cached) {
                    this.stats = cached.stats || this.stats;
                    this.movimientos = Array.isArray(cached.movimientos) ? cached.movimientos : [];
                    this.trasladosPendientes = Array.isArray(cached.trasladosPendientes) ? cached.trasladosPendientes : [];
                    return;
                }
                if (!silent) this.notify(e.message || 'No se pudo cargar el dashboard', 'error');
                throw e;
            }
        },

        async buscarProductos(touchStatus = true) {
            this.loadingSearch = true;
            try {
                if (touchStatus) this.startSyncStatus('Buscando productos...', 72);
                const q = encodeURIComponent((this.searchQuery || '').trim());
                const data = await this.fetchJson(`${this.api}?action=buscar&id_empresa=${this.idEmpresa}&id_sucursal=${this.idSucursal}&q=${q}`);
                this.productos = Array.isArray(data.data) ? data.data : [];
                this.mergeOfflineProductos(`productos:${this.idSucursal}`, this.productos);
            } catch (e) {
                const cached = this.readOfflineCache(`productos:${this.idSucursal}`, []);
                this.productos = this.filterOfflineProductos(cached);
                if (this.productos.length > 0) {
                    this.notify('Mostrando inventario desde cache offline', 'warning');
                    return;
                }
                this.notify(e.message || 'No se pudo buscar productos', 'error');
                throw e;
            } finally {
                this.loadingSearch = false;
            }
        },

        async abrirProducto(idProducto) {
            this.showProductoModal = true;
            this.loadingDetalle = true;
            this.detalleKardexTab = String(this.idSucursal || 'global');
            try {
                const data = await this.fetchJson(`${this.api}?action=producto&id_empresa=${this.idEmpresa}&id=${idProducto}&id_sucursal=${this.idSucursal}`);
                this.productoDetalle = data.data || null;
            } catch (e) {
                this.notify(e.message || 'No se pudo cargar el producto', 'error');
                this.showProductoModal = false;
            } finally {
                this.loadingDetalle = false;
            }
        },

        closeProductoModal() {
            this.showProductoModal = false;
            this.productoDetalle = null;
            this.detalleKardexTab = 'global';
        },

        async refreshDetalleMovimientos() {
            if (!this.productoDetalle?.idproducto) return;
            const data = await this.fetchJson(`${this.api}?action=producto&id_empresa=${this.idEmpresa}&id=${this.productoDetalle.idproducto}&id_sucursal=${this.idSucursal}`);
            this.productoDetalle = data.data || this.productoDetalle;
        },

        async refreshDetalleSilencioso() {
            try {
                await this.refreshDetalleMovimientos();
            } catch (_) {}
        },

        detalleKardexTabs() {
            const tabs = Array.isArray(this.productoDetalle?.stock_sucursales)
                ? this.productoDetalle.stock_sucursales.map((item) => ({
                    key: String(item.id_sucursal),
                    label: item.sucursal || ('Suc. ' + item.id_sucursal)
                }))
                : [];
            tabs.push({ key: 'global', label: 'Global' });
            return tabs;
        },

        detalleKardexMovimientos() {
            const movimientos = Array.isArray(this.productoDetalle?.movimientos_globales) ? this.productoDetalle.movimientos_globales : [];
            if (this.detalleKardexTab === 'global') return movimientos;
            return movimientos.filter((mov) => String(mov.id_sucursal || '') === String(this.detalleKardexTab));
        },

        detalleKardexTabLabel() {
            const tab = this.detalleKardexTabs().find((item) => item.key === this.detalleKardexTab);
            return tab ? tab.label : 'Global';
        },

        openAction(action, tipo = 'entrada') {
            if (!this.productoDetalle) return;
            if ((action === 'ajuste' || action === 'traslado' || action === 'conteo') && !this.canInsert()) {
                this.notify('No tiene permisos para operar inventario', 'error');
                return;
            }
            this.actionForm = {
                action,
                tipo,
                cantidad: '',
                stock_fisico: this.productoDetalle.stock_actual_sucursal || 0,
                id_sucursal_destino: this.sucursales.find((item) => Number(item.id_sucursal) !== Number(this.idSucursal))?.id_sucursal || '',
                obs: ''
            };
            this.actionTitle = action === 'traslado'
                ? 'Traslado entre sucursales'
                : (action === 'conteo'
                    ? 'Conteo fisico'
                    : (tipo === 'entrada' ? 'Ajuste de entrada' : 'Ajuste de salida'));
            this.showActionModal = true;
        },

        closeActionModal() {
            this.showActionModal = false;
        },

        async submitAction() {
            if (!this.productoDetalle?.idproducto) return;
            this.savingAction = true;
            try {
                let payload = {
                    action: this.actionForm.action,
                    id_producto: this.productoDetalle.idproducto,
                    obs: this.actionForm.obs || ''
                };

                if (this.actionForm.action === 'traslado') {
                    payload.id_sucursal_origen = this.idSucursal;
                    payload.id_sucursal_destino = Number(this.actionForm.id_sucursal_destino || 0);
                    payload.cantidad = Number(this.actionForm.cantidad || 0);
                } else if (this.actionForm.action === 'conteo') {
                    payload.id_sucursal = this.idSucursal;
                    payload.stock_fisico = Number(this.actionForm.stock_fisico || 0);
                } else {
                    payload.id_sucursal = this.idSucursal;
                    payload.tipo = this.actionForm.tipo;
                    payload.cantidad = Number(this.actionForm.cantidad || 0);
                }

                const data = await this.fetchJson(this.api, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                this.notify(data.message || 'Movimiento guardado', 'success');
                this.closeActionModal();
                if (this.actionForm.action === 'traslado' && data.data?.id_traslado) {
                    setTimeout(() => this.printTrasladoLabel({ id: data.data.id_traslado }), 180);
                }
                await this.refreshDetalleMovimientos();
                await this.syncAll(true);
            } catch (e) {
                this.notify(e.message || 'No se pudo guardar el movimiento', 'error');
            } finally {
                this.savingAction = false;
            }
        },

        async recibirTraslado(traslado) {
            if (!traslado?.id || !traslado?.puede_recibir) return;
            if (!this.canReceiveTransfer()) {
                this.notify('No tiene permisos para recibir traslados', 'error');
                return;
            }
            if (!confirm(`¿Recibir el traslado ${traslado.referencia} en ${traslado.sucursal_destino}?`)) return;
            try {
                const data = await this.fetchJson(this.api, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'recibir_traslado',
                        id_traslado: traslado.id,
                        id_sucursal_destino: this.idSucursal
                    })
                });
                this.notify(data.message || 'Traslado recibido', 'success');
                await this.syncAll(true);
                if (this.showProductoModal && this.productoDetalle?.idproducto === traslado.id_producto) {
                    await this.refreshDetalleMovimientos();
                }
            } catch (e) {
                this.notify(e.message || 'No se pudo recibir el traslado', 'error');
            }
        },

        async printTraslado(traslado) {
            if (!traslado?.id) return;
            try {
                const printer = await this.ensurePrinterReady();
                const res = await fetch(`/public/inventario/traslado_print_escpos.php?id=${encodeURIComponent(traslado.id)}&id_empresa=${encodeURIComponent(this.idEmpresa)}&width=48`, { cache: 'no-store' });
                const data = await res.json();
                if (!data.success || !data.data) throw new Error(data.message || 'No se pudo generar comprobante ESC/POS');
                await this.smxPrinter.printRaw(printer, data.data);
                this.notify('Comprobante enviado a impresora', 'success');
            } catch (e) {
                this.notify(e.message || 'No se pudo imprimir por ESC/POS', 'error');
                const url = `/public/inventario/traslado_print.php?id=${encodeURIComponent(traslado.id)}&id_empresa=${encodeURIComponent(this.idEmpresa)}`;
                window.open(url, '_blank');
            }
        },

        async printTrasladoLabel(traslado) {
            if (!traslado?.id) return;
            try {
                const printer = await this.ensurePrinterReady();
                const res = await fetch(`/public/inventario/traslado_label_escpos.php?id=${encodeURIComponent(traslado.id)}&id_empresa=${encodeURIComponent(this.idEmpresa)}&width=32`, { cache: 'no-store' });
                const data = await res.json();
                if (!data.success || !data.data) throw new Error(data.message || 'No se pudo generar etiqueta ESC/POS');
                await this.smxPrinter.printRaw(printer, data.data);
                this.notify('Etiqueta enviada a impresora', 'success');
            } catch (e) {
                this.notify(e.message || 'No se pudo imprimir etiqueta directa', 'error');
                const url = `/public/inventario/traslado_label.php?id=${encodeURIComponent(traslado.id)}&id_empresa=${encodeURIComponent(this.idEmpresa)}`;
                window.open(url, '_blank');
            }
        }
    };
}
</script>
</body>
</html>
