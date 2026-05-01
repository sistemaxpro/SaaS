<?php
/**
 * Módulo Cajas - Frontend Mobile
 * Vista responsiva para dispositivos móviles
 */

require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
Permission::requireAccess('app_grid_caja');
$permisos = Permission::getAppPermissions('app_grid_caja');
?>
<!DOCTYPE html>
<html lang="es" x-data="cajasApp()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cajas - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } }
        }
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <style>
        [x-cloak] { display: none !important; }
        .modal-enter { animation: modalIn .2s ease-out; }
        @keyframes modalIn { from { opacity:0; transform:translateY(100%); } to { opacity:1; transform:translateY(0); } }
        .fade-in { animation: fadeIn 0.2s ease-out; }
        @keyframes fadeIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }
        body { overscroll-behavior-y: contain; -webkit-tap-highlight-color: transparent; }
        input, select, textarea { font-size: 16px !important; }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-900 min-h-screen font-sans antialiased pb-20">
<script>window.__PERMISOS__ = <?= json_encode($permisos) ?>;</script>

<!-- Header -->
<header class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 sticky top-0 z-30">
    <div class="px-4 h-14 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                    class="w-9 h-9 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-600 dark:text-gray-300 active:scale-95 transition-transform" title="Volver">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h1 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                <i class="fas fa-cash-register text-green-600 dark:text-green-400"></i> Cajas
            </h1>
        </div>
        <div class="flex items-center gap-2">
            <a href="index.php?desktop=1" class="w-9 h-9 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500 dark:text-gray-400">
                <i class="fas fa-desktop text-sm"></i>
            </a>
        </div>
    </div>
</header>

<!-- Search -->
<div class="px-4 py-3 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700">
    <div class="relative">
        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
        <input type="text" x-model.debounce.300ms="search" @input="page = 1; loadCajas()"
               placeholder="Buscar caja..."
               class="w-full pl-9 pr-4 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-gray-50 dark:bg-slate-900 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-green-500">
    </div>
</div>

<!-- Stats -->
<div class="flex gap-3 px-4 py-3 overflow-x-auto">
    <div class="flex-1 min-w-[100px] bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-3 text-center">
        <p class="text-xs text-gray-500 dark:text-gray-400">Total</p>
        <p class="text-xl font-bold text-gray-900 dark:text-white" x-text="stats.total"></p>
    </div>
    <div class="flex-1 min-w-[100px] bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-3 text-center">
        <p class="text-xs text-green-600 dark:text-green-400">Resultados</p>
        <p class="text-xl font-bold text-green-600 dark:text-green-400" x-text="totalResultados"></p>
    </div>
</div>

<!-- Lista de Cajas -->
<div class="px-4 space-y-3">
    <div x-show="loading" class="flex items-center justify-center py-12">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-green-600"></div>
    </div>

    <template x-for="c in cajas" :key="c.id_caja">
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 shadow-sm fade-in">
            <div class="flex items-start justify-between mb-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-green-100 dark:bg-green-900/30 flex items-center justify-center text-green-600 dark:text-green-400">
                        <i class="fas fa-cash-register"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-gray-900 dark:text-white" x-text="c.caja"></h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400" x-text="c.nombre_sucursal || 'Suc. ' + c.id_sucursal"></p>
                    </div>
                </div>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-medium"
                      :class="c.tipo == 1 ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' : 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400'"
                      x-text="c.tipo == 1 ? 'Efectivo' : 'Mixta'"></span>
            </div>

            <div class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs mb-3">
                <div class="flex justify-between">
                    <span class="text-gray-400">Saldo Máx.</span>
                    <span class="text-gray-900 dark:text-white font-medium" x-text="formatMoney(c.saldo_maximo)"></span>
                </div>
                <div x-show="c.timbrado" class="flex justify-between">
                    <span class="text-gray-400">Timbrado</span>
                    <span class="text-gray-900 dark:text-white font-mono" x-text="c.timbrado"></span>
                </div>
                <div class="flex justify-between col-span-2">
                    <span class="text-gray-400">Numeración</span>
                    <span class="text-gray-900 dark:text-white font-mono" x-text="pad(c.factura_1,3) + '-' + pad(c.factura_2,3) + '-' + pad(c.factura_3,7)"></span>
                </div>
                <div x-show="c.vencimiento" class="flex justify-between col-span-2">
                    <span class="text-gray-400">Vence</span>
                    <span :class="isVencido(c.vencimiento) ? 'text-red-600' : 'text-gray-900 dark:text-white'" class="font-medium" x-text="c.vencimiento"></span>
                </div>
            </div>

            <!-- Usuarios -->
            <div x-show="c.usuarios_asignados && c.usuarios_asignados.length > 0" class="mb-3">
                <div class="flex flex-wrap gap-1">
                    <template x-for="u in c.usuarios_asignados" :key="u.id_login">
                        <span class="px-1.5 py-0.5 bg-gray-100 dark:bg-slate-700 rounded-full text-[10px] text-gray-600 dark:text-gray-400" x-text="u.nombre"></span>
                    </template>
                </div>
            </div>

            <!-- Acciones -->
            <div class="flex items-center justify-end gap-2 border-t border-gray-100 dark:border-slate-700 pt-2.5">
                <button @click="verKardex(c)"
                        class="px-3 py-1.5 rounded-lg bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400 text-xs font-semibold active:scale-95 transition-transform">
                    <i class="fas fa-book mr-1"></i> Kardex
                </button>
                <button x-show="permisos.priv_update === 'Y'" @click="editarCaja(c)"
                        class="px-3 py-1.5 rounded-lg bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 text-xs font-semibold active:scale-95 transition-transform">
                    <i class="fas fa-edit mr-1"></i> Editar
                </button>
                <button x-show="permisos.priv_delete === 'Y'" @click="eliminarCaja(c)"
                        class="px-3 py-1.5 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 text-xs font-semibold active:scale-95 transition-transform">
                    <i class="fas fa-trash mr-1"></i>
                </button>
            </div>
        </div>
    </template>

    <!-- Empty -->
    <div x-show="!loading && cajas.length === 0" class="py-12 text-center">
        <i class="fas fa-cash-register text-4xl text-gray-300 dark:text-slate-600 mb-2"></i>
        <p class="text-gray-400 text-sm">No hay cajas</p>
    </div>

    <!-- Paginación -->
    <div x-show="totalPages > 1" class="flex items-center justify-between py-3">
        <span class="text-xs text-gray-400" x-text="'Pág. ' + page + '/' + totalPages"></span>
        <div class="flex gap-2">
            <button @click="page--; loadCajas()" :disabled="page <= 1"
                    class="px-3 py-1.5 rounded-lg bg-white dark:bg-slate-800 border text-xs disabled:opacity-40"><i class="fas fa-chevron-left"></i></button>
            <button @click="page++; loadCajas()" :disabled="page >= totalPages"
                    class="px-3 py-1.5 rounded-lg bg-white dark:bg-slate-800 border text-xs disabled:opacity-40"><i class="fas fa-chevron-right"></i></button>
        </div>
    </div>
</div>

<!-- FAB -->
<button x-show="permisos.priv_insert === 'Y'" @click="nuevaCaja()"
        class="fixed bottom-6 right-6 z-40 w-14 h-14 bg-green-600 hover:bg-green-700 text-white rounded-full shadow-lg flex items-center justify-center active:scale-90 transition-transform">
    <i class="fas fa-plus text-lg"></i>
</button>

<!-- ============ MODAL CREAR/EDITAR ============ -->
<div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-end justify-center" @keydown.escape.window="showModal = false">
    <div class="absolute inset-0 bg-black/50" @click="showModal = false"></div>
    <div class="relative bg-white dark:bg-slate-800 rounded-t-2xl w-full max-h-[92vh] overflow-y-auto modal-enter safe-area-bottom">
        <!-- Header -->
        <div class="sticky top-0 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-4 py-3 flex items-center justify-between z-10">
            <h2 class="text-base font-bold text-gray-900 dark:text-white" x-text="form.id_caja ? 'Editar Caja' : 'Nueva Caja'"></h2>
            <button @click="showModal = false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Body -->
        <div class="px-4 py-4 space-y-4">
            <!-- Nombre -->
            <div>
                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Nombre de la Caja *</label>
                <input type="text" x-model="form.caja" placeholder="CAJA 1"
                       class="w-full px-3 py-3 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Tipo</label>
                    <select x-model="form.tipo" class="w-full px-3 py-3 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                        <option value="1">Efectivo</option>
                        <option value="2">Mixta</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Sucursal</label>
                    <select x-model="form.id_sucursal" class="w-full px-3 py-3 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                        <template x-for="s in sucursales" :key="s.id_sucursal">
                            <option :value="s.id_sucursal" x-text="s.nombre"></option>
                        </template>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Saldo Máximo</label>
                    <input type="number" x-model="form.saldo_maximo" class="w-full px-3 py-3 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Impresora</label>
                    <div class="flex gap-1.5">
                        <div class="flex-1">
                            <template x-if="impresoras.length > 0 && !impresoraManual">
                                <select x-model="form.impresora"
                                        class="w-full px-3 py-3 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                                    <option value="">— Seleccionar —</option>
                                    <template x-for="imp in impresoras" :key="imp.nombre">
                                        <option :value="imp.nombre" x-text="imp.nombre"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="impresoras.length === 0 || impresoraManual">
                                <input type="text" x-model="form.impresora" placeholder="Nombre impresora..."
                                       class="w-full px-3 py-3 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                            </template>
                        </div>
                        <button type="button" @click="detectarImpresoras()" :disabled="detectandoImpresoras"
                                class="px-3 py-3 rounded-xl border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-gray-500">
                            <i class="fas" :class="detectandoImpresoras ? 'fa-spinner fa-spin' : 'fa-search'"></i>
                        </button>
                        <button x-show="impresoras.length > 0" type="button" @click="impresoraManual = !impresoraManual"
                                class="px-3 py-3 rounded-xl border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-gray-500">
                            <i class="fas" :class="impresoraManual ? 'fa-list' : 'fa-keyboard'"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Numeración -->
            <div class="p-3 bg-gray-50 dark:bg-slate-900 rounded-xl border border-gray-200 dark:border-slate-700">
                <h3 class="text-xs font-bold text-gray-700 dark:text-gray-300 mb-2"><i class="fas fa-receipt mr-1 text-blue-600"></i> Numeración</h3>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-[10px] text-gray-500 mb-0.5">Establ.</label>
                        <input type="number" x-model="form.factura_1" class="w-full px-2 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm font-mono text-center">
                    </div>
                    <div>
                        <label class="block text-[10px] text-gray-500 mb-0.5">Pto. Em.</label>
                        <input type="number" x-model="form.factura_2" class="w-full px-2 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm font-mono text-center">
                    </div>
                    <div>
                        <label class="block text-[10px] text-gray-500 mb-0.5">Nro. Ini.</label>
                        <input type="number" x-model="form.factura_3" class="w-full px-2 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm font-mono text-center">
                    </div>
                </div>
            </div>

            <!-- Usuarios -->
            <div class="p-3 bg-gray-50 dark:bg-slate-900 rounded-xl border border-gray-200 dark:border-slate-700">
                <h3 class="text-xs font-bold text-gray-700 dark:text-gray-300 mb-2"><i class="fas fa-users mr-1 text-indigo-600"></i> Usuarios</h3>
                <div class="max-h-36 overflow-y-auto space-y-1">
                    <template x-for="u in usuariosDisponibles" :key="u.id_login">
                        <label class="flex items-center gap-2 px-2 py-2 rounded-lg active:bg-gray-100 dark:active:bg-slate-800 cursor-pointer">
                            <input type="checkbox" :value="u.id_login" x-model="form.usuarios"
                                   class="w-4 h-4 rounded border-gray-300 text-green-600">
                            <span class="text-sm text-gray-700 dark:text-gray-300" x-text="u.name || u.login"></span>
                        </label>
                    </template>
                    <p x-show="usuariosDisponibles.length === 0" class="text-xs text-gray-400 text-center py-2">Sin usuarios</p>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="sticky bottom-0 bg-white dark:bg-slate-800 border-t border-gray-200 dark:border-slate-700 px-4 py-4 flex gap-3">
            <button @click="showModal = false" class="flex-1 py-3 bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium">Cancelar</button>
            <button @click="guardarCaja()" :disabled="saving" class="flex-1 py-3 bg-green-600 text-white rounded-xl text-sm font-semibold flex items-center justify-center gap-2 disabled:opacity-50">
                <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                <span x-text="saving ? 'Guardando...' : 'Guardar'"></span>
            </button>
        </div>
    </div>
</div>

<!-- ============ MODAL KARDEX ============ -->
<div x-show="showKardex" x-cloak class="fixed inset-0 z-50 flex items-end justify-center" @keydown.escape.window="showKardex = false">
    <div class="absolute inset-0 bg-black/50" @click="showKardex = false"></div>
    <div class="relative bg-white dark:bg-slate-800 rounded-t-2xl w-full max-h-[92vh] flex flex-col modal-enter">
        <!-- Header -->
        <div class="sticky top-0 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-4 py-3 flex items-center justify-between z-10">
            <div>
                <h2 class="text-base font-bold text-gray-900 dark:text-white flex items-center gap-2">
                    <i class="fas fa-book text-amber-500"></i> Kardex
                </h2>
                <p class="text-xs text-gray-500" x-text="kardex.cajaNombre"></p>
            </div>
            <button @click="showKardex = false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Filtros -->
        <div class="px-4 py-2 border-b border-gray-100 dark:border-slate-700 space-y-2">
            <div class="flex gap-2">
                <div class="flex-1">
                    <label class="block text-[10px] text-gray-400 mb-0.5">Desde</label>
                    <input type="date" x-model="kardex.fechaDesde" @change="loadKardex()"
                           class="w-full px-2 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                </div>
                <div class="flex-1">
                    <label class="block text-[10px] text-gray-400 mb-0.5">Hasta</label>
                    <input type="date" x-model="kardex.fechaHasta" @change="loadKardex()"
                           class="w-full px-2 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                </div>
                <div class="flex items-end">
                    <button @click="kardex.fechaDesde = ''; kardex.fechaHasta = ''; kardex.filtroOperacion = ''; kardex.filtroReferencia = ''; kardex.filtroTexto = ''; kardex.page = 1; loadKardex()" class="px-2 py-2 text-gray-400">
                        <i class="fas fa-times-circle"></i>
                    </button>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[10px] text-gray-400 mb-0.5">Operación</label>
                    <select x-model="kardex.filtroOperacion" @change="kardex.page = 1; loadKardex()"
                            class="w-full px-2 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-xs">
                        <option value="">Todas</option>
                        <template x-for="op in kardex.catalogos.operaciones" :key="'kop_' + op.id">
                            <option :value="String(op.id)" x-text="op.operacion"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] text-gray-400 mb-0.5">Referencia</label>
                    <select x-model="kardex.filtroReferencia" @change="kardex.page = 1; loadKardex()"
                            class="w-full px-2 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-xs">
                        <option value="">Todas</option>
                        <template x-for="ref in kardex.catalogos.referencias" :key="'kref_' + ref.id">
                            <option :value="String(ref.id)" x-text="ref.referencia"></option>
                        </template>
                    </select>
                </div>
            </div>
            <div>
                <input type="text" x-model.debounce.350ms="kardex.filtroTexto" @input="kardex.page = 1; loadKardex()" placeholder="Buscar concepto, beneficiario, comprobante..."
                       class="w-full px-2 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-xs">
            </div>
            <!-- Resumen -->
            <div class="flex justify-between text-[10px] font-semibold">
                <span class="text-green-600"><i class="fas fa-arrow-up"></i> <span x-text="formatMoney(kardex.resumen.total_ingresos)"></span></span>
                <span class="text-red-600"><i class="fas fa-arrow-down"></i> <span x-text="formatMoney(kardex.resumen.total_egresos)"></span></span>
                <span class="text-gray-900 dark:text-white"><i class="fas fa-equals"></i> <span x-text="formatMoney(kardex.resumen.saldo_neto)"></span></span>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-slate-700 p-2">
                <p class="text-[10px] uppercase font-semibold text-gray-400 mb-1">Por operación</p>
                <div class="flex flex-wrap gap-1">
                    <template x-for="op in (kardex.resumen.por_operacion || [])" :key="'sumopm_' + op.etiqueta">
                        <span class="px-1.5 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 text-[10px]">
                            <span x-text="op.etiqueta"></span>: <strong x-text="formatMoney(op.saldo)"></strong>
                        </span>
                    </template>
                    <span x-show="(kardex.resumen.por_operacion || []).length === 0" class="text-[10px] text-gray-400">Sin datos</span>
                </div>
            </div>
            <div class="rounded-lg border border-gray-200 dark:border-slate-700 p-2">
                <p class="text-[10px] uppercase font-semibold text-gray-400 mb-1">Por referencia</p>
                <div class="flex flex-wrap gap-1">
                    <template x-for="ref in (kardex.resumen.por_referencia || [])" :key="'sumrefm_' + ref.etiqueta">
                        <span class="px-1.5 py-0.5 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 text-[10px]">
                            <span x-text="ref.etiqueta"></span>: <strong x-text="formatMoney(ref.saldo)"></strong>
                        </span>
                    </template>
                    <span x-show="(kardex.resumen.por_referencia || []).length === 0" class="text-[10px] text-gray-400">Sin datos</span>
                </div>
            </div>

            <!-- Botones operaciones -->
            <div class="flex flex-wrap gap-1.5 pt-1">
                <button @click="abrirOperacion('salida_contacto')" class="px-2 py-1 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 text-[10px] font-semibold active:scale-95">
                    <i class="fas fa-arrow-up"></i> S.Contacto
                </button>
                <button @click="abrirOperacion('entrada_contacto')" class="px-2 py-1 rounded-lg bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 text-[10px] font-semibold active:scale-95">
                    <i class="fas fa-arrow-down"></i> E.Contacto
                </button>
                <button @click="abrirOperacion('salida_caja')" class="px-2 py-1 rounded-lg bg-orange-50 dark:bg-orange-900/20 text-orange-600 dark:text-orange-400 text-[10px] font-semibold active:scale-95">
                    <i class="fas fa-exchange-alt"></i> Caja→Caja
                </button>
                <button @click="abrirOperacion('salida_cuenta')" class="px-2 py-1 rounded-lg bg-purple-50 dark:bg-purple-900/20 text-purple-600 dark:text-purple-400 text-[10px] font-semibold active:scale-95">
                    <i class="fas fa-arrow-up"></i> S.Cuenta
                </button>
                <button @click="abrirOperacion('entrada_cuenta')" class="px-2 py-1 rounded-lg bg-purple-50 dark:bg-purple-900/20 text-purple-600 dark:text-purple-400 text-[10px] font-semibold active:scale-95">
                    <i class="fas fa-arrow-down"></i> E.Cuenta
                </button>
                <button @click="abrirOperacion('salida_banco')" class="px-2 py-1 rounded-lg bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 text-[10px] font-semibold active:scale-95">
                    <i class="fas fa-arrow-up"></i> S.Banco
                </button>
                <button @click="abrirOperacion('entrada_banco')" class="px-2 py-1 rounded-lg bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 text-[10px] font-semibold active:scale-95">
                    <i class="fas fa-arrow-down"></i> E.Banco
                </button>
            </div>
        </div>

        <!-- Lista movimientos -->
        <div class="flex-1 overflow-y-auto px-4 py-2">
            <div x-show="kardex.loading" class="flex items-center justify-center py-8">
                <div class="animate-spin rounded-full h-7 w-7 border-b-2 border-amber-500"></div>
            </div>

            <div x-show="!kardex.loading" class="space-y-2">
                <template x-for="m in kardex.data" :key="m.id">
                    <div class="bg-gray-50 dark:bg-slate-900 rounded-xl p-3 border border-gray-100 dark:border-slate-700">
                        <div class="flex items-center justify-between mb-1.5">
                            <div class="flex items-center gap-1.5">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold"
                                      :class="{
                                          'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400': m.color_op === 'green',
                                          'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400': m.color_op === 'blue',
                                          'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400': m.color_op === 'red',
                                          'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400': m.color_op === 'yellow',
                                          'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400': m.color_op === 'purple',
                                          'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400': m.color_op === 'gray'
                                      }"
                                      x-text="m.tipo_operacion"></span>
                                <span x-show="m.medio_cobro" class="text-[10px] text-gray-400" x-text="m.medio_cobro"></span>
                            </div>
                            <span class="text-[10px] text-gray-400" x-text="formatFecha(m.fecha)"></span>
                        </div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 truncate mb-1.5" x-text="m.concepto || '-'"></div>
                        <div class="text-[10px] text-blue-600 dark:text-blue-400 truncate mb-1.5" x-text="m.referencia_nombre || (m.referencia ? ('Referencia #' + m.referencia) : '-')"></div>
                        <div class="flex items-center justify-between">
                            <div class="flex gap-3">
                                <span x-show="parseFloat(m.credito) > 0" class="text-xs font-bold text-green-600 dark:text-green-400">
                                    <i class="fas fa-arrow-up text-[9px]"></i> <span x-text="formatMoney(m.credito)"></span>
                                </span>
                                <span x-show="parseFloat(m.debito) > 0" class="text-xs font-bold text-red-600 dark:text-red-400">
                                    <i class="fas fa-arrow-down text-[9px]"></i> <span x-text="formatMoney(m.debito)"></span>
                                </span>
                            </div>
                            <span x-show="m.comprobante" class="text-[10px] text-gray-500 font-mono" x-text="m.comprobante"></span>
                        </div>
                        <div class="flex items-center justify-between mt-1">
                            <span class="text-[10px] text-gray-400" x-text="m.nombre_usuario"></span>
                        </div>
                    </div>
                </template>
            </div>

            <div x-show="!kardex.loading && kardex.data.length === 0" class="py-10 text-center">
                <i class="fas fa-inbox text-3xl text-gray-300 dark:text-slate-600 mb-2"></i>
                <p class="text-gray-400 text-xs">Sin movimientos</p>
            </div>
        </div>

        <!-- Paginación -->
        <div x-show="kardex.totalPages > 1" class="border-t border-gray-200 dark:border-slate-700 px-4 py-2 flex items-center justify-between">
            <span class="text-[10px] text-gray-400" x-text="kardex.total + ' mov. | Pág. ' + kardex.page + '/' + kardex.totalPages"></span>
            <div class="flex gap-2">
                <button @click="kardex.page--; loadKardex()" :disabled="kardex.page <= 1" class="px-2.5 py-1 rounded-lg bg-gray-100 dark:bg-slate-700 text-xs disabled:opacity-40"><i class="fas fa-chevron-left"></i></button>
                <button @click="kardex.page++; loadKardex()" :disabled="kardex.page >= kardex.totalPages" class="px-2.5 py-1 rounded-lg bg-gray-100 dark:bg-slate-700 text-xs disabled:opacity-40"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
    </div>
</div>

<!-- ============ MODAL OPERACIÓN KARDEX (Mobile) ============ -->
<div x-show="showOperacion" x-cloak class="fixed inset-0 z-[60] flex items-end justify-center" @keydown.escape.window="showOperacion = false">
    <div class="absolute inset-0 bg-black/50" @click="showOperacion = false"></div>
    <div class="relative bg-white dark:bg-slate-800 rounded-t-2xl w-full max-h-[88vh] overflow-y-auto modal-enter">
        <div class="sticky top-0 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-4 py-3 flex items-center justify-between z-10">
            <h2 class="text-base font-bold text-gray-900 dark:text-white flex items-center gap-2">
                <i class="fas" :class="opForm.es_entrada ? 'fa-arrow-down text-green-500' : 'fa-arrow-up text-red-500'"></i>
                <span x-text="opForm.titulo"></span>
            </h2>
            <button @click="showOperacion = false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="px-4 py-4 space-y-4">
            <!-- Tipo caja a caja: dirección -->
            <div x-show="opForm.tipo_base === 'caja'" class="flex gap-2">
                <button @click="opForm.tipo_operacion = 'salida_caja'; opForm.es_entrada = false; opForm.titulo = 'Salida Caja a Caja'"
                        class="flex-1 py-2.5 rounded-lg text-xs font-semibold text-center transition-colors"
                        :class="opForm.tipo_operacion === 'salida_caja' ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400 ring-2 ring-red-300' : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-400'">
                    <i class="fas fa-arrow-up mr-1"></i> Salida
                </button>
                <button @click="opForm.tipo_operacion = 'entrada_caja'; opForm.es_entrada = true; opForm.titulo = 'Entrada Caja a Caja'"
                        class="flex-1 py-2.5 rounded-lg text-xs font-semibold text-center transition-colors"
                        :class="opForm.tipo_operacion === 'entrada_caja' ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400 ring-2 ring-green-300' : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-400'">
                    <i class="fas fa-arrow-down mr-1"></i> Entrada
                </button>
            </div>

            <!-- Referencia -->
            <div>
                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1" x-text="opForm.ref_label + ' *'"></label>
                <select x-model="opForm.referencia_id" @change="opRefChange()"
                        class="w-full px-3 py-3 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-green-500">
                    <option value="">-- Seleccionar --</option>
                    <template x-for="r in opForm.ref_options" :key="r.id">
                        <option :value="r.id" x-text="r.nombre"></option>
                    </template>
                </select>
            </div>

            <!-- Importe -->
            <div>
                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Importe (Gs.) *</label>
                <input type="number" x-model.number="opForm.importe" min="1" step="1" placeholder="0"
                       class="w-full px-3 py-3 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm font-mono focus:ring-2 focus:ring-green-500">
            </div>

            <!-- Concepto -->
            <div>
                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Concepto *</label>
                <input type="text" x-model="opForm.concepto" placeholder="Descripción" maxlength="60"
                       class="w-full px-3 py-3 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-green-500">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Medio</label>
                    <select x-model="opForm.medio_cobro"
                            class="w-full px-3 py-3 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                        <option value="EFECTIVO">Efectivo</option>
                        <option value="CHEQUE">Cheque</option>
                        <option value="TRANSFERENCIA">Transfer.</option>
                        <option value="TARJETA">Tarjeta</option>
                        <option value="DEPOSITO">Depósito</option>
                        <option value="OTRO">Otro</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Comprobante</label>
                    <input type="text" x-model="opForm.comprobante" placeholder="Nro."
                           class="w-full px-3 py-3 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm font-mono">
                </div>
            </div>
        </div>

        <div class="sticky bottom-0 bg-white dark:bg-slate-800 border-t border-gray-200 dark:border-slate-700 px-4 py-4 flex gap-3">
            <button @click="showOperacion = false" class="flex-1 py-3 bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium">Cancelar</button>
            <button @click="guardarOperacion()" :disabled="opSaving"
                    class="flex-1 py-3 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 disabled:opacity-50"
                    :class="opForm.es_entrada ? 'bg-green-600' : 'bg-red-600'">
                <i class="fas" :class="opSaving ? 'fa-spinner fa-spin' : 'fa-check'"></i>
                <span x-text="opSaving ? 'Registrando...' : 'Registrar'"></span>
            </button>
        </div>
    </div>
</div>

<!-- Toast -->
<div x-show="toast.show" x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-y-4 opacity-0"
     x-transition:enter-end="translate-y-0 opacity-100"
     x-transition:leave="transition ease-in duration-200"
     class="fixed bottom-20 left-4 right-4 z-[100] px-4 py-3 rounded-xl shadow-lg text-white text-sm font-medium flex items-center gap-2"
     :class="toast.type === 'error' ? 'bg-red-600' : 'bg-green-600'">
    <i class="fas" :class="toast.type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'"></i>
    <span x-text="toast.msg"></span>
</div>

<script>
function cajasApp() {
    return {
        API: 'api',
        idEmpresa: <?= (int)$id_empresa ?>,
        cajas: [],
        permisos: window.__PERMISOS__ || {},
        loading: true,
        search: '',
        page: 1,
        totalPages: 1,
        totalResultados: 0,
        stats: { total: 0 },
        sucursales: [],
        usuariosDisponibles: [],
        isOfflineMode: typeof navigator !== 'undefined' ? !navigator.onLine : false,
        offlineQueue: [],
        showModal: false,
        saving: false,
        form: {},
        showKardex: false,
        kardex: {
            idCaja: null, cajaNombre: '', data: [], loading: false,
            page: 1, totalPages: 1, total: 0,
            fechaDesde: '', fechaHasta: '',
            filtroOperacion: '', filtroReferencia: '', filtroTexto: '',
            catalogos: { operaciones: [], referencias: [] },
            resumen: { total_ingresos: 0, total_egresos: 0, saldo_neto: 0, por_operacion: [], por_referencia: [] }
        },
        showOperacion: false,
        opSaving: false,
        opForm: {
            tipo_operacion: '', tipo_base: '', es_entrada: false, titulo: '',
            ref_label: '', ref_options: [],
            referencia_id: '', referencia_nombre: '',
            importe: '', concepto: '', medio_cobro: 'EFECTIVO', comprobante: ''
        },
        opCatalogos: { clientes: [], bancos: [], cuentas: [], cajas: [] },
        opCatalogosLoaded: false,
        toast: { show: false, msg: '', type: 'ok' },

        init() {
            this.setupOfflineSupport();
            this.loadCatalogos();
            this.loadCajas();
        },

        offlineKey(suffix) {
            return `sx_cajas_mobile_offline:${this.idEmpresa}:${suffix}`;
        },

        readStorage(suffix, fallback = null) {
            return window.SmxOfflineDb?.getSync(this.offlineKey(suffix), fallback) ?? fallback;
        },

        writeStorage(suffix, data) {
            return window.SmxOfflineDb?.set(this.offlineKey(suffix), data);
        },

        buildListCacheKey() {
            return `list:${this.search.trim().toLowerCase()}:page:${this.page}`;
        },

        buildKardexCacheKey() {
            return `kardex:${this.kardex.idCaja}:${this.kardex.page}:${this.kardex.fechaDesde}:${this.kardex.fechaHasta}:${this.kardex.filtroOperacion}:${this.kardex.filtroReferencia}:${String(this.kardex.filtroTexto || '').trim().toLowerCase()}`;
        },

        createOfflineId(prefix = 'offline') {
            return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
        },

        async setupOfflineSupport() {
            await (window.SmxOfflineDb?.ready || Promise.resolve());
            this.isOfflineMode = typeof navigator !== 'undefined' ? !navigator.onLine : false;
            this.offlineQueue = this.readStorage('queue', []);
            window.addEventListener('online', async () => {
                this.isOfflineMode = false;
                await this.syncOfflineQueue(true);
            });
            window.addEventListener('offline', () => {
                this.isOfflineMode = true;
                this.showToast('Modo offline activo', 'error');
            });
            if (navigator.onLine && this.offlineQueue.length) {
                this.syncOfflineQueue(false);
            }
        },

        getSucursalNombre(idSucursal) {
            const sucursal = (this.sucursales || []).find((item) => String(item?.id_sucursal) === String(idSucursal));
            return sucursal?.nombre || '';
        },

        getUsuariosPreview(ids = []) {
            return (Array.isArray(ids) ? ids : [])
                .map((id) => (this.usuariosDisponibles || []).find((u) => String(u?.id_login) === String(id)))
                .filter(Boolean)
                .map((u) => ({ id_login: u.id_login, nombre: u.name || u.login || `Usuario ${u.id_login}` }));
        },

        buildCajaPreview(form = {}) {
            const offlineId = form.id_caja || this.createOfflineId('caja');
            return {
                id_caja: offlineId,
                caja: String(form.caja || '').trim(),
                tipo: String(form.tipo || '1'),
                id_sucursal: String(form.id_sucursal || ''),
                nombre_sucursal: this.getSucursalNombre(form.id_sucursal) || `Suc. ${form.id_sucursal || ''}`,
                saldo_maximo: Number(form.saldo_maximo || 0),
                timbrado: String(form.timbrado || '').trim(),
                vencimiento: String(form.vencimiento || '').trim(),
                fecha_inicio_timbrado: String(form.fecha_inicio_timbrado || '').trim(),
                factura_1: Number(form.factura_1 || 0),
                factura_2: Number(form.factura_2 || 0),
                factura_3: Number(form.factura_3 || 0),
                impresora: String(form.impresora || '').trim(),
                impresor: String(form.impresora || '').trim(),
                usuarios_asignados: this.getUsuariosPreview(form.usuarios),
                offline_pending: true
            };
        },

        mergeOfflineCajas(items = []) {
            const map = new Map();
            (Array.isArray(this.offlineQueue) ? this.offlineQueue : [])
                .filter((entry) => entry?.kind === 'caja')
                .forEach((entry) => {
                    const preview = entry?.preview;
                    if (!preview?.id_caja) return;
                    map.set(String(preview.id_caja), preview);
                });

            const merged = [];
            (Array.isArray(items) ? items : []).forEach((item) => {
                const id = String(item?.id_caja ?? '');
                if (id && map.has(id)) {
                    merged.push({ ...item, ...map.get(id), offline_pending: true });
                    map.delete(id);
                } else {
                    merged.push(item);
                }
            });
            map.forEach((preview) => merged.unshift(preview));
            return merged;
        },

        queueOfflineEntry(entry) {
            let nextQueue = [...(Array.isArray(this.offlineQueue) ? this.offlineQueue : [])];
            if (entry?.kind === 'caja' && entry?.preview?.id_caja) {
                nextQueue = nextQueue.filter((item) => !(item?.kind === 'caja' && String(item?.preview?.id_caja) === String(entry.preview.id_caja)));
            }
            this.offlineQueue = [...nextQueue, entry];
            this.writeStorage('queue', this.offlineQueue);
        },

        async syncOfflineQueue(showToast = false) {
            if (!navigator.onLine || !this.offlineQueue.length) return;
            const pending = [...this.offlineQueue];
            const remaining = [];
            let synced = 0;

            for (const entry of pending) {
                try {
                    let res;
                    if (entry.kind === 'caja') {
                        res = await fetch(`${this.API}/guardar.php`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(entry.payload)
                        });
                    } else if (entry.kind === 'kardex') {
                        res = await fetch(`${this.API}/kardex_operacion.php`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(entry.payload)
                        });
                    } else {
                        remaining.push(entry);
                        continue;
                    }

                    const data = await res.json();
                    if (data?.ok) {
                        synced += 1;
                        continue;
                    }
                    remaining.push(entry);
                } catch (_) {
                    remaining.push(entry);
                    break;
                }
            }

            this.offlineQueue = remaining;
            this.writeStorage('queue', remaining);
            if (synced > 0) {
                await this.loadCatalogos();
                await this.loadCajas();
                if (this.showKardex && this.kardex.idCaja) {
                    await this.loadKardex();
                }
            }
            if (showToast) {
                this.showToast(
                    synced > 0 ? `Se sincronizaron ${synced} cambio(s)` : 'No se pudo sincronizar todavía',
                    synced > 0 ? 'ok' : 'error'
                );
            }
        },

        async loadCatalogos() {
            try {
                const res = await fetch(`${this.API}/catalogos.php?tipo=all&id_empresa=` + encodeURIComponent(this.idEmpresa));
                const data = await res.json();
                if (data.ok) {
                    this.sucursales = data.sucursales || [];
                    this.usuariosDisponibles = data.usuarios || [];
                    await this.writeStorage('catalogos', data);
                }
            } catch (e) {
                const cached = this.readStorage('catalogos');
                if (cached?.ok) {
                    this.sucursales = cached.sucursales || [];
                    this.usuariosDisponibles = cached.usuarios || [];
                } else {
                    console.error(e);
                }
            }
        },

        async loadCajas() {
            this.loading = true;
            const cacheKey = this.buildListCacheKey();
            try {
                const params = new URLSearchParams({ search: this.search, page: this.page, per_page: 20, _ts: Date.now() });
                params.append('id_empresa', String(this.idEmpresa));
                const res = await fetch(`${this.API}/list.php?` + params, { cache: 'no-store' });
                const data = await res.json();
                if (data.ok) {
                    this.cajas = this.mergeOfflineCajas(data.data);
                    this.totalPages = data.pages;
                    this.totalResultados = data.total;
                    if (data.stats) this.stats = data.stats;
                    await this.writeStorage(cacheKey, data);
                    await Promise.all((data.data || []).map((item) => this.writeStorage(`detail:${item.id_caja}`, { ok: true, data: item })));
                }
            } catch (e) {
                const cached = this.readStorage(cacheKey);
                if (cached?.ok) {
                    this.cajas = this.mergeOfflineCajas(cached.data || []);
                    this.totalPages = cached.pages || 1;
                    this.totalResultados = cached.total || this.cajas.length;
                    if (cached.stats) this.stats = cached.stats;
                    this.showToast('Mostrando cajas desde cache offline', 'error');
                } else {
                    console.error(e);
                }
            }
            this.loading = false;
        },

        impresoras: [],
        detectandoImpresoras: false,
        impresoraManual: false,

        normalizePrinterName(name) {
            return String(name || '').trim().toLowerCase();
        },

        ensureCurrentPrinterInList() {
            const actual = String(this.form?.impresora || '').trim();
            if (actual === '') return;

            const normActual = this.normalizePrinterName(actual);
            const existente = (this.impresoras || []).find(i => this.normalizePrinterName(i?.nombre) === normActual);
            if (existente) {
                this.form.impresora = String(existente.nombre || actual).trim();
                return;
            }
            this.impresoras = [{ nombre: actual, estado: 'guardada', fuente: 'actual' }, ...(this.impresoras || [])];
            this.form.impresora = actual;
        },

        async detectarImpresoras() {
            this.detectandoImpresoras = true;
            try {
                const res = await fetch(`${this.API}/impresoras.php`);
                const data = await res.json();
                let lista = [];
                if (data.ok && data.impresoras.length > 0) {
                    lista = data.impresoras;
                }
                if ('usb' in navigator) {
                    try {
                        const devices = await navigator.usb.getDevices();
                        for (const dev of devices) {
                            const nombre = dev.productName || `USB ${dev.vendorId.toString(16)}:${dev.productId.toString(16)}`;
                            if (!lista.find(i => i.nombre === nombre)) {
                                lista.push({ nombre, estado: 'USB', fuente: 'webusb' });
                            }
                        }
                    } catch (e) {}
                }
                // Conservar la impresora guardada aunque no aparezca en detección
                const impresoraActual = String(this.form?.impresora || '').trim();
                const normActual = this.normalizePrinterName(impresoraActual);
                if (impresoraActual !== '' && !lista.find(i => this.normalizePrinterName(i?.nombre) === normActual)) {
                    lista.unshift({ nombre: impresoraActual, estado: 'guardada', fuente: 'actual' });
                }

                this.impresoras = lista;
                this.ensureCurrentPrinterInList();
                if (lista.length === 0) {
                    this.showToast('No se detectaron impresoras', 'info');
                    this.impresoraManual = true;
                } else {
                    this.impresoraManual = false;
                    this.showToast(`${lista.length} impresora(s) detectada(s)`, 'ok');
                }
            } catch (e) {
                this.showToast('Error al detectar', 'error');
                this.impresoraManual = true;
            }
            this.detectandoImpresoras = false;
        },

        nuevaCaja() {
            this.form = {
                id_caja: null, caja: '', tipo: '1',
                id_sucursal: this.sucursales.length ? String(this.sucursales[0].id_sucursal) : '1',
                id_moneda: 1, saldo_maximo: 50000000,
                timbrado: '', vencimiento: '', fecha_inicio_timbrado: '',
                factura_1: 1, factura_2: 1, factura_3: 1, impresora: '', usuarios: []
            };
            this.showModal = true;
        },

        async editarCaja(c) {
            let row = c || {};
            try {
                const params = new URLSearchParams({
                    id_empresa: String(this.idEmpresa),
                    id_caja: String(c?.id_caja || 0),
                    _ts: String(Date.now())
                });
                const res = await fetch(`${this.API}/get.php?` + params.toString(), { cache: 'no-store' });
                const data = await res.json();
                if (data?.ok && data?.data) {
                    row = { ...c, ...data.data };
                    await this.writeStorage(`detail:${c?.id_caja}`, data);
                }
            } catch (e) {
                const cached = this.readStorage(`detail:${c?.id_caja}`);
                if (cached?.ok && cached?.data) {
                    row = { ...c, ...cached.data };
                    this.showToast('Editando caja desde cache offline', 'error');
                } else {
                    console.warn('No se pudo refrescar detalle de caja para edición:', e);
                }
            }
            this.form = {
                id_caja: row.id_caja, caja: row.caja, tipo: String(row.tipo),
                id_sucursal: String(row.id_sucursal), id_moneda: row.id_moneda || 1,
                saldo_maximo: row.saldo_maximo, timbrado: row.timbrado || '',
                vencimiento: row.vencimiento || '', fecha_inicio_timbrado: row.fecha_inicio_timbrado || '',
                factura_1: row.factura_1, factura_2: row.factura_2, factura_3: row.factura_3,
                impresora: String(row.impresora || row.impresor || '').trim(),
                usuarios: (row.usuarios_asignados || []).map(u => String(u.id_login))
            };
            this.ensureCurrentPrinterInList();
            this.showModal = true;
        },

        async guardarCaja() {
            if (!this.form.caja.trim()) { this.showToast('Nombre obligatorio', 'error'); return; }
            this.saving = true;
            const currentId = String(this.form.id_caja || '').trim();
            const isLocalOnly = currentId.startsWith('caja-');
            const isEditing = !!currentId && !isLocalOnly;
            const localId = currentId || this.createOfflineId('caja');
            const formPayload = {
                ...this.form,
                id_caja: isEditing ? localId : null
            };
            const payload = {
                action: isEditing ? 'update' : 'create',
                id_empresa: this.idEmpresa,
                caja: formPayload,
                usuarios: this.form.usuarios.map(Number)
            };
            try {
                const res = await fetch(`${this.API}/guardar.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.ok) { this.showToast(data.msg); this.showModal = false; this.loadCajas(); }
                else { this.showToast(data.error, 'error'); }
            } catch (e) {
                const preview = this.buildCajaPreview({
                    ...this.form,
                    id_caja: this.form.id_caja || localId
                });
                this.queueOfflineEntry({
                    kind: 'caja',
                    createdAt: Date.now(),
                    payload,
                    preview
                });
                await this.writeStorage(`detail:${preview.id_caja}`, { ok: true, data: preview });
                this.cajas = this.mergeOfflineCajas(this.cajas || []);
                this.showModal = false;
                this.showToast('Caja guardada offline', 'ok');
            }
            this.saving = false;
        },

        async eliminarCaja(c) {
            if (!confirm(`¿Eliminar "${c.caja}"?`)) return;
            if (!navigator.onLine) {
                this.showToast('Eliminar requiere conexión', 'error');
                return;
            }
            try {
                const res = await fetch(`${this.API}/eliminar.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id_caja: c.id_caja, id_empresa: this.idEmpresa })
                });
                const data = await res.json();
                if (data.ok) { this.showToast(data.msg); this.loadCajas(); }
                else { this.showToast(data.error, 'error'); }
            } catch (e) { this.showToast('Error de conexión', 'error'); }
        },

        verKardex(c) {
            this.kardex.idCaja = c.id_caja;
            this.kardex.cajaNombre = c.caja;
            this.kardex.page = 1;
            this.kardex.fechaDesde = '';
            this.kardex.fechaHasta = '';
            this.kardex.filtroOperacion = '';
            this.kardex.filtroReferencia = '';
            this.kardex.filtroTexto = '';
            this.kardex.catalogos = { operaciones: [], referencias: [] };
            this.kardex.data = [];
            this.kardex.resumen = { total_ingresos: 0, total_egresos: 0, saldo_neto: 0, por_operacion: [], por_referencia: [] };
            this.showKardex = true;
            this.loadKardex();
        },

        async loadKardex() {
            this.kardex.loading = true;
            const cacheKey = this.buildKardexCacheKey();
            try {
                const params = new URLSearchParams({
                    id_caja: this.kardex.idCaja, page: this.kardex.page, per_page: 30,
                    fecha_desde: this.kardex.fechaDesde, fecha_hasta: this.kardex.fechaHasta,
                    operacion: this.kardex.filtroOperacion,
                    referencia: this.kardex.filtroReferencia,
                    q: this.kardex.filtroTexto
                });
                const res = await fetch(`${this.API}/kardex.php?` + params);
                const data = await res.json();
                if (data.ok) {
                    this.kardex.data = this.mergeOfflineKardex(data.data);
                    this.kardex.totalPages = data.pages;
                    this.kardex.total = data.total;
                    this.kardex.catalogos = data.catalogos || { operaciones: [], referencias: [] };
                    this.kardex.resumen = data.resumen || { total_ingresos: 0, total_egresos: 0, saldo_neto: 0, por_operacion: [], por_referencia: [] };
                    await this.writeStorage(cacheKey, data);
                }
            } catch (e) {
                const cached = this.readStorage(cacheKey);
                if (cached?.ok) {
                    this.kardex.data = this.mergeOfflineKardex(cached.data || []);
                    this.kardex.totalPages = cached.pages || 1;
                    this.kardex.total = cached.total || this.kardex.data.length;
                    this.kardex.catalogos = cached.catalogos || { operaciones: [], referencias: [] };
                    this.kardex.resumen = cached.resumen || { total_ingresos: 0, total_egresos: 0, saldo_neto: 0, por_operacion: [], por_referencia: [] };
                    this.showToast('Kardex cargado desde cache offline', 'error');
                } else {
                    console.error(e);
                }
            }
            this.kardex.loading = false;
        },

        mergeOfflineKardex(items = []) {
            const pending = (Array.isArray(this.offlineQueue) ? this.offlineQueue : [])
                .filter((entry) => entry?.kind === 'kardex' && String(entry?.payload?.id_caja) === String(this.kardex.idCaja))
                .map((entry) => entry.preview)
                .filter(Boolean);
            return [...pending, ...(Array.isArray(items) ? items : [])];
        },

        async loadOpCatalogos() {
            if (this.opCatalogosLoaded) return;
            try {
                const res = await fetch(`${this.API}/kardex_catalogos.php`);
                const data = await res.json();
                if (data.ok) {
                    this.opCatalogos.clientes = (data.clientes || []).map(c => ({ id: c.id, nombre: c.nombre + (c.ruc ? ' (' + c.ruc + ')' : '') }));
                    this.opCatalogos.bancos = (data.bancos || []).map(b => ({ id: b.id_banco, nombre: b.banco + (b.numero_cuenta ? ' - ' + b.numero_cuenta : '') }));
                    this.opCatalogos.cuentas = (data.cuentas || []).map(c => ({ id: c.id, nombre: c.cuenta }));
                    this.opCatalogos.cajas = (data.cajas || []).map(c => ({ id: c.id_caja, nombre: c.caja }));
                    this.opCatalogosLoaded = true;
                    await this.writeStorage('kardex_catalogos', data);
                }
            } catch (e) {
                const cached = this.readStorage('kardex_catalogos');
                if (cached?.ok) {
                    this.opCatalogos.clientes = (cached.clientes || []).map(c => ({ id: c.id, nombre: c.nombre + (c.ruc ? ' (' + c.ruc + ')' : '') }));
                    this.opCatalogos.bancos = (cached.bancos || []).map(b => ({ id: b.id_banco, nombre: b.banco + (b.numero_cuenta ? ' - ' + b.numero_cuenta : '') }));
                    this.opCatalogos.cuentas = (cached.cuentas || []).map(c => ({ id: c.id, nombre: c.cuenta }));
                    this.opCatalogos.cajas = (cached.cajas || []).map(c => ({ id: c.id_caja, nombre: c.caja }));
                    this.opCatalogosLoaded = true;
                } else {
                    console.error(e);
                }
            }
        },

        async abrirOperacion(tipo) {
            await this.loadOpCatalogos();
            const mapTipo = {
                'salida_contacto':  { label: 'Contacto', options: this.opCatalogos.clientes, entrada: false, titulo: 'Salida ref. Contacto', base: 'contacto' },
                'entrada_contacto': { label: 'Contacto', options: this.opCatalogos.clientes, entrada: true,  titulo: 'Entrada ref. Contacto', base: 'contacto' },
                'salida_caja':      { label: 'Caja destino', options: this.opCatalogos.cajas.filter(c => c.id != this.kardex.idCaja), entrada: false, titulo: 'Salida Caja a Caja', base: 'caja' },
                'entrada_caja':     { label: 'Caja origen', options: this.opCatalogos.cajas.filter(c => c.id != this.kardex.idCaja), entrada: true,  titulo: 'Entrada Caja a Caja', base: 'caja' },
                'salida_cuenta':    { label: 'Cuenta', options: this.opCatalogos.cuentas, entrada: false, titulo: 'Salida ref. Cuenta', base: 'cuenta' },
                'entrada_cuenta':   { label: 'Cuenta', options: this.opCatalogos.cuentas, entrada: true,  titulo: 'Entrada ref. Cuenta', base: 'cuenta' },
                'salida_banco':     { label: 'Banco', options: this.opCatalogos.bancos, entrada: false, titulo: 'Salida ref. Banco', base: 'banco' },
                'entrada_banco':    { label: 'Banco', options: this.opCatalogos.bancos, entrada: true,  titulo: 'Entrada ref. Banco', base: 'banco' },
            };
            const cfg = mapTipo[tipo];
            if (!cfg) return;
            this.opForm = {
                tipo_operacion: tipo, tipo_base: cfg.base, es_entrada: cfg.entrada,
                titulo: cfg.titulo, ref_label: cfg.label, ref_options: cfg.options,
                referencia_id: '', referencia_nombre: '',
                importe: '', concepto: '', medio_cobro: 'EFECTIVO', comprobante: ''
            };
            this.showOperacion = true;
        },

        opRefChange() {
            const sel = this.opForm.ref_options.find(r => r.id == this.opForm.referencia_id);
            this.opForm.referencia_nombre = sel ? sel.nombre : '';
        },

        async guardarOperacion() {
            if (!this.opForm.referencia_id) { this.showToast('Seleccione referencia', 'error'); return; }
            if (!this.opForm.importe || this.opForm.importe <= 0) { this.showToast('Importe inválido', 'error'); return; }
            if (!this.opForm.concepto.trim()) { this.showToast('Ingrese concepto', 'error'); return; }
            this.opSaving = true;
            try {
                const payload = {
                    tipo_operacion: this.opForm.tipo_operacion,
                    id_caja: this.kardex.idCaja,
                    importe: this.opForm.importe,
                    concepto: this.opForm.concepto.trim(),
                    medio_cobro: this.opForm.medio_cobro,
                    referencia_id: parseInt(this.opForm.referencia_id),
                    referencia_nombre: this.opForm.referencia_nombre,
                    comprobante: this.opForm.comprobante
                };
                const res = await fetch(`${this.API}/kardex_operacion.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.ok) {
                    this.showToast(data.msg);
                    this.showOperacion = false;
                    this.loadKardex();
                } else {
                    this.showToast(data.error || 'Error', 'error');
                }
            } catch (e) {
                const preview = {
                    id: this.createOfflineId('mov'),
                    tipo_operacion: this.opForm.titulo,
                    color_op: this.opForm.es_entrada ? 'green' : 'red',
                    medio_cobro: this.opForm.medio_cobro,
                    fecha: new Date().toISOString(),
                    concepto: this.opForm.concepto.trim(),
                    referencia_nombre: this.opForm.referencia_nombre || '',
                    referencia: this.opForm.referencia_id,
                    credito: this.opForm.es_entrada ? Number(this.opForm.importe || 0) : 0,
                    debito: this.opForm.es_entrada ? 0 : Number(this.opForm.importe || 0),
                    comprobante: this.opForm.comprobante,
                    nombre_usuario: 'Pendiente de sincronización',
                    offline_pending: true
                };
                this.queueOfflineEntry({
                    kind: 'kardex',
                    createdAt: Date.now(),
                    payload,
                    preview
                });
                this.showOperacion = false;
                this.kardex.data = this.mergeOfflineKardex(this.kardex.data || []);
                this.showToast('Movimiento guardado offline', 'ok');
            }
            this.opSaving = false;
        },

        formatFecha(f) {
            if (!f) return '-';
            const d = new Date(f);
            return d.toLocaleDateString('es-PY', { day:'2-digit', month:'2-digit', year:'2-digit' }) + ' ' + d.toLocaleTimeString('es-PY', { hour:'2-digit', minute:'2-digit' });
        },

        formatMoney(val) {
            return new Intl.NumberFormat('es-PY', { style: 'currency', currency: 'PYG', maximumFractionDigits: 0 }).format(val || 0);
        },
        isVencido(f) { return f ? new Date(f) < new Date() : false; },
        pad(n, l) { return String(n || 0).padStart(l, '0'); },
        showToast(msg, type = 'ok') {
            this.toast = { show: true, msg, type };
            setTimeout(() => this.toast.show = false, 3000);
        }
    };
}
</script>
</body>
</html>
