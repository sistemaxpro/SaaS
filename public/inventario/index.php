<?php
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = preg_match('/Mobile|Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $userAgent);
$forceDesktop = isset($_GET['desktop']) || isset($_COOKIE['inventario_desktop']);

if ($isMobile && !$forceDesktop && !isset($_GET['no_redirect'])) {
    header('Location: /public/inventario/mobile.php');
    exit;
}

if (isset($_GET['desktop'])) {
    setcookie('inventario_desktop', '1', time() + 86400 * 30, '/');
}

require_once __DIR__ . '/../../config/bootstrap.php';

Session::requireLogin('/public/login.php');
Permission::requireAccess('app_grid_mercaderias');

$idEmpresa = Session::get('id_empresa', 169);
$idLogin = Session::get('id_login');
$idSucursal = (int)Session::get('id_sucursal', 0);
$sucursalNombre = (string)Session::get('sucursal', '');
$permisos = Permission::getAppPermissions('app_grid_mercaderias');

$masterPdo = Database::getMasterConnection();
$stmtE = $masterPdo->prepare("SELECT empresa FROM empresa WHERE id_empresa = ?");
$stmtE->execute([$idEmpresa]);
$empresaNombre = $stmtE->fetchColumn() ?: 'Empresa';
?>
<!DOCTYPE html>
<html lang="es" x-data="inventarioDesktop()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="/public/pos/js/smx-printer.js?v=1" defer></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] }
                }
            }
        };
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
        body.inventario-desktop {
            font-size: 12px;
            font-weight: 300;
            line-height: 1.35;
            letter-spacing: 0.01em;
        }
        body.inventario-desktop .text-xs,
        body.inventario-desktop .text-sm,
        body.inventario-desktop .text-base,
        body.inventario-desktop .text-lg {
            font-size: 12px !important;
        }
        body.inventario-desktop .font-medium,
        body.inventario-desktop .font-semibold,
        body.inventario-desktop .font-bold {
            font-weight: 400 !important;
        }
        .inv-panel { box-shadow: 0 16px 40px rgba(15, 23, 42, .06); }
        .dark .inv-panel { box-shadow: 0 16px 40px rgba(2, 6, 23, .35); }
        .inv-card:hover { border-color: rgba(251, 191, 36, .35); }
        .dark .inv-card:hover { border-color: rgba(245, 158, 11, .28); }
    </style>
</head>
<body class="inventario-desktop min-h-screen bg-slate-50 dark:bg-slate-900 text-slate-900 dark:text-slate-100 font-sans antialiased">
<script>
window.__INV__ = {
    idEmpresa: <?= (int)$idEmpresa ?>,
    idLogin: <?= (int)$idLogin ?>,
    idSucursal: <?= (int)$idSucursal ?>,
    sucursalNombre: <?= json_encode($sucursalNombre) ?>,
    empresaNombre: <?= json_encode($empresaNombre) ?>,
    permisos: <?= json_encode($permisos) ?>,
    focusTransfers: <?= isset($_GET['focus']) && $_GET['focus'] === 'traslados' ? 'true' : 'false' ?>
};
</script>

<div class="min-h-screen">
    <header class="sticky top-0 z-30 border-b border-slate-200 dark:border-slate-800 bg-white/85 dark:bg-slate-950/80 backdrop-blur-xl">
        <div class="max-w-[1600px] mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
            <div class="flex items-center gap-4 min-w-0">
                <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                        class="w-10 h-10 rounded-xl bg-red-50 border border-red-200 text-red-600 flex items-center justify-center shadow-sm">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="min-w-0">
                    <p class="text-[10px] uppercase tracking-[0.28em] text-amber-700 dark:text-amber-300 font-black">Inventario</p>
                    <h1 class="text-xl sm:text-2xl font-black truncate">Control desktop multi sucursal</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400 truncate"><?= htmlspecialchars($empresaNombre) ?></p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="/public/inventario/mobile.php" class="px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-sm font-semibold shadow-sm">
                    <i class="fas fa-mobile-screen mr-2"></i>Modo móvil
                </a>
                <button @click="toggleTheme()" class="w-10 h-10 rounded-xl bg-slate-900 text-white dark:bg-white dark:text-slate-900 flex items-center justify-center shadow-lg">
                    <i class="fas" :class="isDark ? 'fa-sun' : 'fa-moon'"></i>
                </button>
            </div>
        </div>
    </header>

    <main class="max-w-[1600px] mx-auto px-4 sm:px-6 py-6 space-y-5">
        <section class="grid grid-cols-12 gap-4">
            <div class="col-span-12 xl:col-span-8 inv-panel rounded-[1.6rem] bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 p-4 sm:p-5">
                <div class="grid grid-cols-12 gap-3 items-end">
                    <div class="col-span-12 lg:col-span-3">
                        <label class="block text-[10px] uppercase tracking-[0.18em] text-slate-400 mb-1.5">Sucursal</label>
                        <select x-model.number="idSucursal" @change="handleSucursalChange()"
                                class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-2.5 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/40">
                            <template x-for="sucursal in sucursales" :key="sucursal.id_sucursal">
                                <option :value="sucursal.id_sucursal" x-text="sucursal.sucursal"></option>
                            </template>
                        </select>
                    </div>
                    <div class="col-span-12 lg:col-span-7">
                        <label class="block text-[10px] uppercase tracking-[0.18em] text-slate-400 mb-1.5">Buscar producto</label>
                        <div class="relative">
                            <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input x-model="searchQuery" @input.debounce.300ms="buscarProductos()" type="text"
                                   placeholder="Buscar codigo, barra, referencia o descripcion..."
                                   class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 pl-11 pr-24 py-2.5 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/40">
                            <button type="button" @click="toggleSearchVoice()" :disabled="!speechSupported"
                                    class="absolute right-12 top-1/2 -translate-y-1/2 w-9 h-9 rounded-xl flex items-center justify-center transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                                    :class="speechListening ? 'bg-rose-600 text-white shadow-lg shadow-rose-600/20' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300'">
                                <i class="fas" :class="speechListening ? 'fa-microphone-slash' : 'fa-microphone'"></i>
                            </button>
                            <button type="button" @click="openBarcodeScanner()"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-300 flex items-center justify-center transition-all">
                                <i class="fas fa-barcode"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-span-12 lg:col-span-2">
                        <label class="block text-[10px] uppercase tracking-[0.18em] text-slate-400 mb-1.5">Acción</label>
                        <button @click="syncAll(true)"
                                class="w-full rounded-xl bg-amber-600 text-white py-2.5 text-sm font-black shadow-lg shadow-amber-600/20">
                            <i class="fas fa-rotate mr-2"></i>Actualizar
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-span-12 xl:col-span-4 inv-panel rounded-[1.6rem] bg-gradient-to-br from-emerald-600 to-teal-700 text-white p-4 sm:p-5 shadow-lg shadow-emerald-900/20">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.2em] text-white/70 font-black">En vivo</p>
                        <h2 class="text-xl font-black mt-1" x-text="sucursalActualNombre || 'Sucursal'"></h2>
                    </div>
                    <span class="inline-flex items-center gap-2 text-sm font-black">
                        <span class="w-2.5 h-2.5 rounded-full bg-white animate-pulse"></span><span x-text="syncInProgress ? 'sincronizando' : 'activo'"></span>
                    </span>
                </div>
                <p class="mt-5 text-3xl font-black" x-text="syncInProgress ? (syncProgress + '%') : lastSyncLabel"></p>
                <div class="mt-4 h-2.5 rounded-full bg-white/20 overflow-hidden">
                    <div class="h-full rounded-full bg-white transition-all duration-300" :style="`width:${syncProgress}%`"></div>
                </div>
                <p class="text-sm text-white/80 mt-2" x-text="syncStatusText || 'sincroniza al abrir o al pulsar actualizar'"></p>
            </div>
        </section>

        <section class="grid grid-cols-12 gap-4">
            <div class="col-span-12 md:col-span-3 inv-panel rounded-[1.4rem] bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 p-4 sm:p-5">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Productos</p>
                <p class="mt-3 text-3xl font-black" x-text="stats.total_productos"></p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">catálogo total</p>
            </div>
            <div class="col-span-12 md:col-span-3 inv-panel rounded-[1.4rem] bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 p-4 sm:p-5">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Activos</p>
                <p class="mt-3 text-3xl font-black text-sky-600 dark:text-sky-400" x-text="stats.activos"></p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">productos disponibles</p>
            </div>
            <div class="col-span-12 md:col-span-3 inv-panel rounded-[1.4rem] bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 p-4 sm:p-5">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Sin stock</p>
                <p class="mt-3 text-3xl font-black text-rose-600 dark:text-rose-400" x-text="stats.sin_stock"></p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">en sucursal actual</p>
            </div>
            <div class="col-span-12 md:col-span-3 inv-panel rounded-[1.4rem] bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 p-4 sm:p-5">
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Stock bajo</p>
                <p class="mt-3 text-3xl font-black text-amber-600 dark:text-amber-300" x-text="stats.stock_bajo"></p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">reponer pronto</p>
            </div>
        </section>

        <section class="grid grid-cols-12 gap-6">
            <div class="col-span-12 xl:col-span-7 inv-panel rounded-[1.6rem] bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 p-4 sm:p-5">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <p class="text-[11px] uppercase tracking-[0.18em] text-slate-400 font-black">Productos</p>
                        <h2 class="text-xl font-black mt-1">Consulta y operación rápida</h2>
                    </div>
                    <span class="text-sm text-slate-400" x-text="loadingSearch ? 'Buscando...' : (productos.length + ' resultados')"></span>
                </div>
                <div x-show="loadingSearch" class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 p-8 text-center text-slate-500">Cargando productos...</div>
                <div class="space-y-3 max-h-[760px] overflow-y-auto pr-1">
                    <template x-for="producto in productos" :key="producto.idproducto">
                        <button @click="abrirProducto(producto.idproducto)"
                                class="inv-card w-full text-left rounded-[1.3rem] bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4 transition-colors">
                            <div class="flex items-start gap-4">
                                <div class="w-14 h-14 rounded-2xl bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 overflow-hidden flex items-center justify-center flex-shrink-0">
                                    <img x-show="producto.foto_url" :src="producto.foto_url" class="w-full h-full object-cover" @error="$el.style.display='none'">
                                    <i x-show="!producto.foto_url" class="fas fa-box-open text-slate-400"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-sm font-semibold truncate" x-text="producto.desproducto"></p>
                                        <span class="text-[10px] font-semibold px-2 py-1 rounded-full bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-slate-500" x-text="producto.cve_producto"></span>
                                    </div>
                                    <div class="mt-1 text-xs text-slate-500 dark:text-slate-400" x-text="producto.referencia || 'Sin referencia'"></div>
                                    <div class="mt-4 grid grid-cols-3 gap-3">
                                        <div>
                                            <p class="text-[10px] uppercase tracking-[0.16em] text-slate-400">Sucursal</p>
                                            <p class="text-base font-semibold" :class="Number(producto.stock_actual_sucursal) > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'" x-text="formatNumber(producto.stock_actual_sucursal)"></p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] uppercase tracking-[0.16em] text-slate-400">Global</p>
                                            <p class="text-base font-semibold" x-text="formatNumber(producto.stock_global)"></p>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-[10px] uppercase tracking-[0.16em] text-slate-400">Precio</p>
                                            <p class="text-base font-semibold text-sky-600 dark:text-sky-400" x-text="formatNumber(producto.precio_venta)"></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </button>
                    </template>
                    <div x-show="!loadingSearch && productos.length === 0" class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 p-10 text-center">
                        <i class="fas fa-boxes-stacked text-4xl text-slate-300 dark:text-slate-700"></i>
                        <p class="mt-3 text-slate-500">No hay productos para esa búsqueda.</p>
                    </div>
                </div>
            </div>

            <div class="col-span-12 xl:col-span-5 inv-panel rounded-[1.6rem] bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 p-4 sm:p-5">
                <div class="space-y-6">
            <section id="inventarioTrasladosSection">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-400 font-black">Traslados</p>
                                <h2 class="text-xl font-black mt-1">Pendientes por confirmar</h2>
                            </div>
                            <span class="text-sm text-slate-400" x-text="trasladosPendientes.length + ' registros'"></span>
                        </div>
                        <div class="space-y-3 max-h-[320px] overflow-y-auto pr-1">
                            <template x-for="traslado in trasladosPendientes" :key="'tp-' + traslado.id">
                                <div class="rounded-[1.5rem] bg-slate-50/80 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4">
                                    <div class="flex items-start gap-4">
                                        <div class="w-14 h-14 rounded-2xl bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 overflow-hidden flex items-center justify-center flex-shrink-0">
                                            <img x-show="traslado.foto_url" :src="traslado.foto_url" class="w-full h-full object-cover" @error="$el.style.display='none'">
                                            <i x-show="!traslado.foto_url" class="fas fa-truck text-slate-400"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="min-w-0">
                                                    <p class="text-sm font-black truncate" x-text="traslado.desproducto"></p>
                                                    <p class="text-xs text-slate-500 mt-1" x-text="traslado.cve_producto + ' · Ref ' + traslado.referencia"></p>
                                                    <p class="text-xs text-slate-400 mt-1" x-text="traslado.sucursal_origen + ' → ' + traslado.sucursal_destino"></p>
                                                </div>
                                                <span class="text-[10px] font-black px-2 py-1 rounded-full flex-shrink-0"
                                                      :class="traslado.puede_recibir ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'"
                                                      x-text="traslado.puede_recibir ? 'Por recibir' : 'En tránsito'"></span>
                                            </div>
                                            <div class="mt-3 flex items-center justify-between gap-3">
                                                <p class="text-lg font-black text-sky-600 dark:text-sky-400" x-text="formatNumber(traslado.cantidad)"></p>
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
                            <div x-show="trasladosPendientes.length === 0" class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-700 p-8 text-center text-slate-500">
                                No hay traslados pendientes para esta sucursal.
                            </div>
                        </div>
                    </section>

                    <section>
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-400 font-black">Kardex</p>
                                <h2 class="text-xl font-black mt-1">Movimientos recientes</h2>
                            </div>
                            <span class="text-sm text-slate-400" x-text="movimientos.length + ' registros'"></span>
                        </div>
                        <div class="space-y-3 max-h-[420px] overflow-y-auto pr-1">
                            <template x-for="mov in movimientos" :key="mov.id">
                                <div class="rounded-[1.5rem] bg-slate-50/80 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <p class="text-sm font-black truncate" x-text="mov.descripcion"></p>
                                            <p class="text-xs text-slate-500 mt-1" x-text="mov.movimiento + ' · ' + mov.nombre_sucursal"></p>
                                            <p class="text-xs text-slate-400 mt-1" x-text="mov.fecha"></p>
                                        </div>
                                        <div class="text-right flex-shrink-0">
                                            <p class="text-lg font-black" :class="mov.direccion === 'entrada' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'" x-text="(mov.direccion === 'entrada' ? '+' : '-') + formatNumber(mov.cantidad)"></p>
                                            <p class="text-xs text-slate-400" x-text="mov.referencia || '-'"></p>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </section>
                </div>
            </div>
        </section>
    </main>
</div>

<div x-show="showProductoModal" x-cloak class="fixed inset-0 z-50">
    <div class="absolute inset-0 bg-slate-950/65 backdrop-blur-sm" @click="closeProductoModal()"></div>
    <div class="absolute inset-x-0 top-[5vh] mx-auto w-[95vw] max-w-none max-h-[90vh] overflow-y-auto rounded-[2rem] bg-white dark:bg-slate-950 border border-white/60 dark:border-slate-800 shadow-2xl">
        <div class="sticky top-0 bg-white/95 dark:bg-slate-950/95 backdrop-blur px-6 pt-5 pb-4 border-b border-slate-100 dark:border-slate-800 z-10">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-4 min-w-0">
                    <div class="w-20 h-20 rounded-3xl bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 overflow-hidden flex items-center justify-center flex-shrink-0">
                        <img x-show="productoDetalle?.foto_url" :src="productoDetalle?.foto_url" class="w-full h-full object-cover" @error="$el.style.display='none'">
                        <i x-show="!productoDetalle?.foto_url" class="fas fa-box-open text-2xl text-slate-400"></i>
                    </div>
                    <div class="min-w-0">
                    <p class="text-[11px] uppercase tracking-[0.22em] text-amber-700 dark:text-amber-300 font-black">Producto</p>
                    <h3 class="text-2xl font-black mt-1" x-text="productoDetalle?.desproducto || ''"></h3>
                    <p class="text-sm text-slate-500 mt-1" x-text="productoDetalle?.cve_producto || ''"></p>
                    </div>
                </div>
                <button @click="closeProductoModal()" class="w-11 h-11 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <div class="px-6 py-6">
            <div x-show="loadingDetalle" class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-700 p-10 text-center text-slate-500">Cargando detalle...</div>
            <template x-if="productoDetalle">
                <div class="grid grid-cols-12 gap-6">
                    <div class="col-span-12 lg:col-span-4 space-y-4">
                        <div class="grid grid-cols-3 gap-3">
                            <div class="rounded-2xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4">
                                <p class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Actual</p>
                                <p class="mt-3 text-3xl font-black" :class="Number(productoDetalle.stock_actual_sucursal) > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'" x-text="formatNumber(productoDetalle.stock_actual_sucursal)"></p>
                            </div>
                            <div class="rounded-2xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4">
                                <p class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Global</p>
                                <p class="mt-3 text-3xl font-black" x-text="formatNumber(productoDetalle.saldo)"></p>
                            </div>
                            <div class="rounded-2xl bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4">
                                <p class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Minimo</p>
                                <p class="mt-3 text-3xl font-black text-amber-600 dark:text-amber-300" x-text="formatNumber(productoDetalle.stock_minimo)"></p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <button x-show="canAdjust()" @click="openAction('ajuste', 'entrada')" class="rounded-2xl bg-emerald-600 text-white py-3.5 text-sm font-black">Ajuste entrada</button>
                            <button x-show="canAdjust()" @click="openAction('ajuste', 'salida')" class="rounded-2xl bg-rose-600 text-white py-3.5 text-sm font-black">Ajuste salida</button>
                            <button x-show="canTransfer()" @click="openAction('traslado')" class="rounded-2xl bg-amber-600 text-white py-3.5 text-sm font-black">Traslado</button>
                            <button x-show="canCount()" @click="openAction('conteo')" class="rounded-2xl bg-slate-900 dark:bg-white dark:text-slate-900 text-white py-3.5 text-sm font-black">Conteo físico</button>
                        </div>
                        <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4">
                            <div class="flex items-center justify-between">
                                <h4 class="text-sm font-black uppercase tracking-[0.18em] text-slate-500">Stock por sucursal</h4>
                                <span class="text-xs text-slate-400" x-text="(productoDetalle.stock_sucursales || []).length + ' sucursales'"></span>
                            </div>
                            <div class="mt-3 space-y-2">
                                <template x-for="item in (productoDetalle.stock_sucursales || [])" :key="item.id_sucursal">
                                    <div class="flex items-center justify-between rounded-2xl bg-slate-50 dark:bg-slate-950 px-3 py-2 border border-slate-200 dark:border-slate-800">
                                        <span class="text-sm font-semibold" x-text="item.sucursal"></span>
                                        <span class="text-lg font-black" :class="Number(item.stock) > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'" x-text="formatNumber(item.stock)"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <div class="col-span-12 lg:col-span-8">
                        <div class="rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4">
                            <div class="flex items-center justify-between">
                                <h4 class="text-sm font-black uppercase tracking-[0.18em] text-slate-500">Kardex</h4>
                                <button @click="refreshDetalleMovimientos()" class="text-xs font-bold text-amber-700 dark:text-amber-300">Actualizar</button>
                            </div>
                            <div class="mt-4 flex gap-2 overflow-x-auto pb-1">
                                <template x-for="tab in detalleKardexTabs()" :key="'tab-' + tab.key">
                                    <button @click="detalleKardexTab = tab.key"
                                            class="px-3 py-2 rounded-2xl text-xs font-black whitespace-nowrap border transition-colors"
                                            :class="detalleKardexTab === tab.key
                                                ? 'bg-slate-900 text-white border-slate-900 dark:bg-white dark:text-slate-900 dark:border-white'
                                                : 'bg-slate-50 text-slate-600 border-slate-200 dark:bg-slate-950 dark:text-slate-300 dark:border-slate-800'"
                                            x-text="tab.label"></button>
                                </template>
                            </div>
                            <div class="mt-4 flex items-center justify-between">
                                <h5 class="text-xs font-black uppercase tracking-[0.18em]"
                                    :class="detalleKardexTab === 'global' ? 'text-amber-700 dark:text-amber-300' : 'text-sky-700 dark:text-sky-300'"
                                    x-text="detalleKardexTabLabel()"></h5>
                                <span class="text-xs text-slate-400" x-text="detalleKardexMovimientos().length + ' movimientos'"></span>
                            </div>
                            <div class="mt-3 space-y-3 max-h-[560px] overflow-y-auto pr-1">
                                <template x-for="mov in detalleKardexMovimientos()" :key="'k-' + detalleKardexTab + '-' + mov.id">
                                    <div class="rounded-2xl bg-slate-50 dark:bg-slate-950 px-4 py-3 border border-slate-200 dark:border-slate-800">
                                        <div class="flex items-start justify-between gap-4">
                                            <div class="min-w-0">
                                                <p class="text-sm font-bold" x-text="mov.movimiento"></p>
                                                <p class="text-xs text-slate-500 mt-1" x-text="mov.nombre_sucursal + ' · ' + mov.fecha"></p>
                                                <p class="text-xs text-slate-400 mt-1" x-show="mov.operador_nombre" x-text="'Operador: ' + mov.operador_nombre"></p>
                                                <p class="text-xs text-slate-400 mt-1" x-show="mov.documento_nro" x-text="(mov.documento_tipo || 'Documento') + ': ' + mov.documento_nro"></p>
                                                <p class="text-xs text-slate-400 mt-1" x-show="mov.cliente_nombre" x-text="'Cliente: ' + mov.cliente_nombre"></p>
                                                <p class="text-xs text-slate-400 mt-1" x-text="mov.referencia || '-'"></p>
                                            </div>
                                            <div class="text-right flex-shrink-0">
                                                <p class="text-lg font-black" :class="mov.direccion === 'entrada' ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'" x-text="(mov.direccion === 'entrada' ? '+' : '-') + formatNumber(mov.cantidad)"></p>
                                                <p class="text-xs text-slate-400" x-text="'Anterior: ' + formatNumber(mov.stock_anterior)"></p>
                                                <p class="text-xs text-slate-400" x-text="'Actual: ' + formatNumber(mov.stock_actual)"></p>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                                <div x-show="detalleKardexMovimientos().length === 0" class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-700 p-8 text-center text-sm text-slate-500">
                                    Sin movimientos en esta pestaña.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<div x-show="showActionModal" x-cloak class="fixed inset-0 z-[60]">
    <div class="absolute inset-0 bg-slate-950/60" @click="closeActionModal()"></div>
    <div class="absolute inset-x-0 top-[18vh] mx-auto max-w-xl w-[94vw] rounded-[2rem] bg-white dark:bg-slate-950 border border-white/60 dark:border-slate-800 shadow-2xl">
        <div class="px-6 pt-5 pb-6 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-amber-700 dark:text-amber-300 font-black">Operación</p>
                    <h3 class="text-xl font-black mt-1" x-text="actionTitle"></h3>
                </div>
                <button @click="closeActionModal()" class="w-11 h-11 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="block text-[10px] uppercase tracking-[0.18em] text-slate-400 mb-1.5">Cantidad</label>
                    <input type="number" x-model.number="actionForm.cantidad" min="0" step="0.01"
                           class="w-full rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 font-black focus:outline-none focus:ring-2 focus:ring-amber-500/40">
                </div>
                <div x-show="actionForm.action === 'traslado'">
                    <label class="block text-[10px] uppercase tracking-[0.18em] text-slate-400 mb-1.5">Destino</label>
                    <select x-model.number="actionForm.id_sucursal_destino"
                            class="w-full rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/40">
                        <template x-for="s in sucursales.filter(item => Number(item.id_sucursal) !== Number(idSucursal))" :key="s.id_sucursal">
                            <option :value="s.id_sucursal" x-text="s.sucursal"></option>
                        </template>
                    </select>
                </div>
                <div x-show="actionForm.action === 'conteo'">
                    <label class="block text-[10px] uppercase tracking-[0.18em] text-slate-400 mb-1.5">Stock físico</label>
                    <input type="number" x-model.number="actionForm.stock_fisico" min="0" step="0.01"
                           class="w-full rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 font-black focus:outline-none focus:ring-2 focus:ring-amber-500/40">
                </div>
                <div>
                    <label class="block text-[10px] uppercase tracking-[0.18em] text-slate-400 mb-1.5">Observación</label>
                    <textarea x-model="actionForm.obs" rows="3"
                              class="w-full rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/40"></textarea>
                </div>
                <button @click="submitAction()" :disabled="savingAction"
                        class="w-full rounded-2xl bg-amber-600 text-white py-3.5 text-sm font-black disabled:opacity-50">
                    <span x-show="!savingAction">Guardar movimiento</span>
                    <span x-show="savingAction">Procesando...</span>
                </button>
            </div>
        </div>
    </div>
</div>

<div x-show="showBarcodeScanner" x-cloak class="fixed inset-0 z-[70] bg-slate-950/80 flex items-center justify-center">
    <div class="w-[92vw] max-w-2xl rounded-[2rem] bg-white dark:bg-slate-950 border border-white/60 dark:border-slate-800 shadow-2xl">
        <div class="px-6 pt-5 pb-6 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[11px] uppercase tracking-[0.22em] text-amber-700 dark:text-amber-300 font-black">Escáner</p>
                    <h3 class="text-xl font-black mt-1">Capturar código de barra</h3>
                </div>
                <button @click="closeBarcodeScanner()" class="w-11 h-11 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="rounded-3xl overflow-hidden border border-slate-200 dark:border-slate-800 bg-slate-100 dark:bg-slate-900">
                <div class="relative aspect-[16/9]">
                    <video x-ref="barcodeVideo" autoplay playsinline muted class="w-full h-full object-cover"></video>
                    <div class="absolute inset-0 pointer-events-none flex items-center justify-center">
                        <div class="w-[58%] h-[22%] border-2 border-emerald-400 rounded-3xl shadow-[0_0_0_9999px_rgba(15,23,42,0.32)]"></div>
                    </div>
                </div>
            </div>
            <p x-show="barcodeScannerError" class="text-sm text-rose-600 dark:text-rose-400 font-semibold" x-text="barcodeScannerError"></p>
            <input x-model="searchQuery" @input.debounce.200ms="buscarProductos()" type="text" placeholder="O cargá el código manualmente..."
                   class="w-full rounded-2xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-4 py-3 font-semibold focus:outline-none focus:ring-2 focus:ring-amber-500/40">
        </div>
    </div>
</div>

<div class="fixed top-4 left-1/2 -translate-x-1/2 z-[80] w-[92vw] max-w-md space-y-2 pointer-events-none">
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

<script>
function inventarioDesktop() {
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
            return `sx_inventario_offline:${this.idEmpresa}:${suffix}`;
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
                throw new Error(data.error || data.message || 'Error de conexión');
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
                recognition.onstart = () => { this.speechListening = true; };
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
                    if (String(event?.error || '') !== 'aborted') this.notify('No se pudo usar el micrófono', 'error');
                };
                recognition.onend = () => {
                    this.speechListening = false;
                    this.speechRecognition = null;
                };
                this.speechRecognition = recognition;
                recognition.start();
            } catch (_) {
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
                if (['NotAllowedError', 'PermissionDeniedError'].includes(String(err?.name || ''))) {
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
                if (['NotAllowedError', 'PermissionDeniedError'].includes(errorName)) {
                    this.barcodeScannerError = 'Permiso de cámara bloqueado. Habilítalo en configuración del navegador.';
                } else if (['NotFoundError', 'DevicesNotFoundError'].includes(errorName)) {
                    this.barcodeScannerError = 'No se encontró una cámara disponible en este dispositivo.';
                } else if (['NotReadableError', 'TrackStartError'].includes(errorName)) {
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
                if (this.barcodeStream) this.barcodeStream.getTracks().forEach((track) => track.stop());
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
            const found = this.sucursales.find((item) => Number(item.id_sucursal) === Number(this.idSucursal));
            if (found) this.sucursalActualNombre = found.sucursal;
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
            try { await this.refreshDetalleMovimientos(); } catch (_) {}
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
                : (action === 'conteo' ? 'Conteo físico' : (tipo === 'entrada' ? 'Ajuste de entrada' : 'Ajuste de salida'));
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
