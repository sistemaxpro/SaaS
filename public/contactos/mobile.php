<?php
/**
 * Módulo Contactos - Frontend Mobile
 * Gestión de clientes, proveedores y empleados (vista mobile)
 */
require_once __DIR__ . '/../../config/bootstrap.php';
Session::start();
$id_empresa = $_SESSION['id_empresa'] ?? 169;

// Verificar acceso
Permission::requireAccess('app_grid_clientes');
$permisos = Permission::getAppPermissions('app_grid_clientes');
?>
<!DOCTYPE html>
<html lang="es" class="scroll-smooth" :class="isDark ? 'dark' : ''" x-data="{ isDark: localStorage.getItem('theme') === 'dark' }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1, user-scalable=no">
    <title>Contactos - Mobile</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'] } } }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        .slide-up { animation: slideUp .3s ease-out; }
        @keyframes slideUp { from { transform:translateY(100%); } to { transform:translateY(0); } }
        .fade-in { animation: fadeIn .2s ease-out; }
        @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
        body { -webkit-tap-highlight-color: transparent; overscroll-behavior: contain; }
    </style>
</head>
<body class="bg-gray-100 dark:bg-slate-900 min-h-screen font-sans antialiased">
<script>window.__PERMISOS__ = <?= json_encode($permisos) ?>;</script>

<div x-data="contactosMobile()" x-init="init()" class="min-h-screen pb-24 max-w-lg mx-auto">

    <!-- Header -->
    <header class="bg-purple-600 dark:bg-purple-800 sticky top-0 z-30 safe-area-top">
        <div class="px-4 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div>
                    <h1 class="text-white font-bold text-lg leading-tight">Contactos</h1>
                    <p class="text-purple-200 text-xs" x-text="stats.total + ' registros'"></p>
                </div>
            </div>
        </div>

        <!-- Search -->
        <div class="px-4 pb-3">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-purple-300"></i>
                <input type="text" :value="search" @input="handleSearchInput($event)"
                       placeholder="Buscar contacto..."
                       class="w-full pl-10 pr-4 py-2.5 bg-white/20 text-white placeholder-purple-200 rounded-xl text-sm border border-white/10 focus:outline-none focus:ring-2 focus:ring-white/30">
            </div>
        </div>

        <!-- Filter Chips -->
        <div class="px-4 pb-3 flex gap-2 overflow-x-auto scrollbar-hide">
            <button @click="filtroRapido('all')"
                class="flex-shrink-0 px-4 py-2 rounded-full text-sm font-semibold shadow-sm transition-colors flex items-center gap-2"
                :class="filtroCuenta === 'all' ? 'bg-purple-100 text-purple-700 ring-2 ring-purple-300' : 'bg-white/15 text-white'">
                <i class="fas fa-users text-base"></i>
                <span>Todos</span>
                <span class="ml-1 text-xs font-bold" x-text="stats.total"></span>
            </button>
            <button @click="filtroRapido('3')"
                class="flex-shrink-0 px-4 py-2 rounded-full text-sm font-semibold shadow-sm transition-colors flex items-center gap-2"
                :class="filtroCuenta === '3' ? 'bg-blue-100 text-blue-700 ring-2 ring-blue-300' : 'bg-white/15 text-white'">
                <i class="fas fa-user-friends text-base"></i>
                <span>Clientes</span>
                <span class="ml-1 text-xs font-bold" x-text="stats.clientes"></span>
            </button>
            <button @click="filtroRapido('4')"
                class="flex-shrink-0 px-4 py-2 rounded-full text-sm font-semibold shadow-sm transition-colors flex items-center gap-2"
                :class="filtroCuenta === '4' ? 'bg-amber-100 text-amber-700 ring-2 ring-amber-300' : 'bg-white/15 text-white'">
                <i class="fas fa-truck text-base"></i>
                <span>Proveedores</span>
                <span class="ml-1 text-xs font-bold" x-text="stats.proveedores"></span>
            </button>
            <button @click="filtroRapido('5')"
                class="flex-shrink-0 px-4 py-2 rounded-full text-sm font-semibold shadow-sm transition-colors flex items-center gap-2"
                :class="filtroCuenta === '5' ? 'bg-emerald-100 text-emerald-700 ring-2 ring-emerald-300' : 'bg-white/15 text-white'">
                <i class="fas fa-user-tie text-base"></i>
                <span>Empleados</span>
                <span class="ml-1 text-xs font-bold" x-text="stats.empleados"></span>
            </button>
        </div>
    </header>

    <!-- Loading -->
    <div x-show="loading" class="flex items-center justify-center py-16">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-purple-600"></div>
    </div>

    <!-- Card List -->
    <div x-show="!loading" class="px-4 pt-4 space-y-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <template x-for="c in contactos" :key="c.id">
                <div @click="verDetalle(c.id)" class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 active:scale-[0.98] transition-transform cursor-pointer">
                    <div class="flex items-start gap-3">
                        <!-- Avatar -->
                        <div class="w-11 h-11 rounded-full flex items-center justify-center text-sm font-bold text-white flex-shrink-0"
                             :class="c.cuenta == 3 ? 'bg-blue-500' : c.cuenta == 4 ? 'bg-amber-500' : 'bg-emerald-500'"
                             x-text="(c.nombre || '?').charAt(0).toUpperCase()"></div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white truncate" x-text="c.nombre"></h3>
                                <span class="flex-shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase"
                                      :class="c.cuenta == 3 ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : c.cuenta == 4 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'"
                                      x-text="c.cuenta == 3 ? 'CLI' : c.cuenta == 4 ? 'PROV' : 'EMP'"></span>
                            </div>
                            <div class="flex items-center gap-3 mt-1 text-xs text-gray-500 dark:text-gray-400">
                                <span class="font-mono" x-text="c.numero"></span>
                                <span x-show="c.telefono" x-text="'• ' + c.telefono"></span>
                            </div>
                            <div class="flex items-center justify-between mt-2">
                                <div x-show="c.email" class="text-xs text-gray-400 dark:text-gray-500 truncate max-w-[60%]">
                                    <i class="fas fa-envelope text-[10px] mr-1"></i><span x-text="c.email"></span>
                                </div>
                                <div class="text-right">
                                    <span class="text-xs font-semibold"
                                          :class="parseFloat(c.saldo) > 0 ? 'text-red-600' : parseFloat(c.saldo) < 0 ? 'text-green-600' : 'text-gray-400'"
                                          x-text="parseFloat(c.saldo_guaranies || c.saldo || 0) !== 0 ? '₲ ' + formatMoney(c.saldo_guaranies || c.saldo) : ''"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Load more -->
        <div x-show="page < totalPages" class="py-4 text-center">
            <button @click="page++; loadContactos(true)" class="px-6 py-2.5 bg-purple-600 text-white rounded-xl text-sm font-semibold">
                Cargar más
            </button>
        </div>

        <!-- Empty -->
        <div x-show="!loading && contactos.length === 0" class="py-12 text-center">
            <i class="fas fa-address-book text-5xl text-gray-300 dark:text-slate-600 mb-3"></i>
            <p class="text-gray-500 dark:text-gray-400">No se encontraron contactos</p>
        </div>
    </div>

    <!-- FAB -->
    <button x-show="permisos.priv_insert === 'Y'" @click="nuevoContacto()" class="fixed bottom-6 right-6 w-14 h-14 bg-purple-600 hover:bg-purple-700 text-white rounded-full shadow-lg shadow-purple-300 dark:shadow-purple-900/40 flex items-center justify-center z-40 active:scale-90 transition-transform">
        <i class="fas fa-plus text-xl"></i>
    </button>

    <!-- ============ Bottom Sheet: Detalle ============ -->
    <div x-show="showDetalle" x-cloak class="fixed inset-0 z-50">
        <div class="fixed inset-0 bg-black/50 fade-in" @click="showDetalle = false"></div>
        <div class="fixed bottom-0 left-0 right-0 max-w-lg mx-auto bg-white dark:bg-slate-800 rounded-t-2xl max-h-[85vh] overflow-y-auto slide-up">
            <!-- Handle -->
            <div class="flex justify-center py-2"><div class="w-10 h-1 bg-gray-300 dark:bg-slate-600 rounded-full"></div></div>

            <!-- Detail Content -->
            <div x-show="detalleLoading" class="py-12 text-center"><div class="animate-spin rounded-full h-8 w-8 border-b-2 border-purple-600 mx-auto"></div></div>

            <div x-show="!detalleLoading && detalleData" class="px-5 pb-6">
                <!-- Header -->
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-14 h-14 rounded-full flex items-center justify-center text-xl font-bold text-white"
                         :class="detalleData?.cuenta == 3 ? 'bg-blue-500' : detalleData?.cuenta == 4 ? 'bg-amber-500' : 'bg-emerald-500'"
                         x-text="(detalleData?.nombre || '?').charAt(0).toUpperCase()"></div>
                    <div class="flex-1 min-w-0">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white" x-text="detalleData?.nombre"></h2>
                        <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                            <span class="font-mono" x-text="detalleData?.numero"></span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold"
                                  :class="detalleData?.cuenta == 3 ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : detalleData?.cuenta == 4 ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'"
                                  x-text="detalleData?.cuenta_tipo"></span>
                        </div>
                    </div>
                    <button x-show="permisos.priv_update === 'Y'" @click="editarDesdeDetalle()" class="w-10 h-10 bg-purple-100 dark:bg-purple-900/30 rounded-xl text-purple-600 dark:text-purple-400 flex items-center justify-center">
                        <i class="fas fa-edit"></i>
                    </button>
                </div>

                <!-- Info grid -->
                <div class="space-y-3">
                    <template x-for="item in detalleItems" :key="item.label">
                        <div x-show="item.value" class="flex items-start gap-3">
                            <div class="w-8 h-8 bg-purple-50 dark:bg-purple-900/20 rounded-lg flex items-center justify-center flex-shrink-0">
                                <i class="fas text-purple-500 text-sm" :class="item.icon"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs text-gray-400 dark:text-gray-500" x-text="item.label"></p>
                                <p class="text-sm text-gray-900 dark:text-white break-all" x-text="item.value"></p>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Saldo -->
                <div class="mt-5 p-4 bg-gray-50 dark:bg-slate-900/50 rounded-xl" x-show="detalleData?.saldo_display">
                    <p class="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase mb-2">Saldos</p>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <p class="text-[10px] text-gray-400 uppercase">Guaraníes</p>
                            <p class="text-sm font-bold" :class="parseFloat(detalleData?.saldo_display?.guaranies) > 0 ? 'text-red-600' : 'text-gray-900 dark:text-white'"
                               x-text="'₲ ' + formatMoney(detalleData?.saldo_display?.guaranies)"></p>
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-400 uppercase">Dólares</p>
                            <p class="text-sm font-bold text-gray-900 dark:text-white" x-text="'$ ' + formatMoney(detalleData?.saldo_display?.dolares)"></p>
                        </div>
                        <div>
                            <p class="text-[10px] text-gray-400 uppercase">Reales</p>
                            <p class="text-sm font-bold text-gray-900 dark:text-white" x-text="'R$ ' + formatMoney(detalleData?.saldo_display?.reales)"></p>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="mt-5 grid grid-cols-2 gap-3">
                    <a x-show="detalleData?.telefono" :href="'tel:' + detalleData?.telefono" class="flex items-center justify-center gap-2 px-4 py-2.5 bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 rounded-xl text-sm font-medium">
                        <i class="fas fa-phone"></i> Llamar
                    </a>
                    <a x-show="detalleData?.telefono" :href="'https://wa.me/595' + (detalleData?.telefono || '').replace(/^0/,'').replace(/[^0-9]/g,'')" target="_blank"
                       class="flex items-center justify-center gap-2 px-4 py-2.5 bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400 rounded-xl text-sm font-medium">
                        <i class="fab fa-whatsapp"></i> WhatsApp
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ Bottom Sheet: Form ============ -->
    <div x-show="showForm" x-cloak class="fixed inset-0 z-50">
        <div class="fixed inset-0 bg-black/50 fade-in" @click="showForm = false"></div>
        <div class="fixed bottom-0 left-0 right-0 max-w-lg mx-auto bg-white dark:bg-slate-800 rounded-t-2xl max-h-[90vh] overflow-y-auto slide-up">
            <div class="flex justify-center py-2"><div class="w-10 h-1 bg-gray-300 dark:bg-slate-600 rounded-full"></div></div>

            <div class="px-5 pb-6">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4" x-text="form.id ? 'Editar Contacto' : 'Nuevo Contacto'"></h2>

                <div class="space-y-3">
                    <!-- Tipo -->
                    <div class="grid grid-cols-3 gap-2">
                        <button @click="form.cuenta = 3" class="py-2.5 rounded-xl text-sm font-semibold transition-colors text-center"
                                :class="form.cuenta == 3 ? 'bg-blue-600 text-white' : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-300'">
                            <i class="fas fa-user mr-1"></i>Cliente
                        </button>
                        <button @click="form.cuenta = 4" class="py-2.5 rounded-xl text-sm font-semibold transition-colors text-center"
                                :class="form.cuenta == 4 ? 'bg-amber-600 text-white' : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-300'">
                            <i class="fas fa-truck mr-1"></i>Proveedor
                        </button>
                        <button @click="form.cuenta = 5" class="py-2.5 rounded-xl text-sm font-semibold transition-colors text-center"
                                :class="form.cuenta == 5 ? 'bg-emerald-600 text-white' : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-300'">
                            <i class="fas fa-id-badge mr-1"></i>Empleado
                        </button>
                    </div>

                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1 block">RUC / CI *</label>
                        <input type="text" x-model="form.numero" placeholder="Ej: 80110188-3"
                               class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm font-mono focus:ring-2 focus:ring-purple-500">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1 block">Nombre / Razón Social *</label>
                        <input type="text" x-model="form.nombre"
                               class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-purple-500">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1 block">Teléfono</label>
                            <input type="tel" x-model="form.telefono"
                                   class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-purple-500">
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1 block">Email</label>
                            <input type="email" x-model="form.email"
                                   class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-purple-500">
                        </div>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1 block">Dirección</label>
                        <input type="text" x-model="form.direccion"
                               class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-purple-500">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1 block">Timbrado</label>
                        <input type="text" x-model="form.timbrado"
                               class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-purple-500">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1 block">Línea Crédito ₲</label>
                            <input type="number" x-model.number="form.linea_credito" min="0"
                                   class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm text-right focus:ring-2 focus:ring-purple-500">
                        </div>
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1 block">Observaciones</label>
                            <input type="text" x-model="form.obs"
                                   class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-purple-500">
                        </div>
                    </div>

                    <!-- Empleado -->
                    <div x-show="form.cuenta == 5" class="p-3 bg-emerald-50 dark:bg-emerald-900/10 rounded-xl space-y-3">
                        <p class="text-xs font-bold text-emerald-700 dark:text-emerald-400 uppercase">Datos Empleado</p>
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="text-[10px] text-gray-500 mb-1 block">Salario</label>
                                <input type="number" x-model.number="form.salario"
                                       class="w-full px-2 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-xs text-right focus:ring-2 focus:ring-purple-500">
                            </div>
                            <div>
                                <label class="text-[10px] text-gray-500 mb-1 block">Comisión %</label>
                                <input type="number" x-model.number="form.comision" min="0" max="100" step="0.5"
                                       class="w-full px-2 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-xs text-right focus:ring-2 focus:ring-purple-500">
                            </div>
                            <div>
                                <label class="text-[10px] text-gray-500 mb-1 block">Día Pago</label>
                                <input type="number" x-model.number="form.dia_pago_salario" min="1" max="31"
                                       class="w-full px-2 py-2 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-xs text-right focus:ring-2 focus:ring-purple-500">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Save -->
                <div class="mt-5 flex gap-3">
                    <button @click="showForm = false" class="flex-1 py-3 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium">
                        Cancelar
                    </button>
                    <button @click="guardar()" :disabled="saving"
                            class="flex-1 py-3 bg-purple-600 text-white rounded-xl text-sm font-bold flex items-center justify-center gap-2 disabled:opacity-50">
                        <i x-show="saving" class="fas fa-spinner fa-spin text-xs"></i>
                        <span x-text="saving ? 'Guardando...' : (form.id ? 'Actualizar' : 'Crear')"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div x-show="toast.show" x-cloak x-transition class="fixed top-20 left-1/2 -translate-x-1/2 z-[999]">
        <div class="px-5 py-3 rounded-xl shadow-lg text-white text-sm font-medium flex items-center gap-2"
             :class="toast.type === 'error' ? 'bg-red-600' : 'bg-green-600'">
            <i class="fas" :class="toast.type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'"></i>
            <span x-text="toast.msg"></span>
        </div>
    </div>
</div>

<script>
function contactosMobile() {
    return {
        API: './api',
        idEmpresa: <?= (int)$id_empresa ?>,
        permisos: window.__PERMISOS__ || {},
        contactos: [],
        loading: true,
        search: '',
        searchDebounceTimer: null,
        filtroCuenta: 'all',
        page: 1,
        totalPages: 1,
        stats: { total: 0, activos: 0, clientes: 0, proveedores: 0, empleados: 0 },
        isOfflineMode: typeof navigator !== 'undefined' ? !navigator.onLine : false,
        offlineQueue: [],

        showDetalle: false,
        detalleLoading: false,
        detalleData: null,
        detalleItems: [],

        showForm: false,
        saving: false,
        form: {},
        toast: { show: false, msg: '', type: 'success' },

        init() {
            this.resetForm();
            this.setupOfflineSupport();
            this.loadContactos();
        },

        offlineKey(suffix) {
            return `sx_contactos_mobile_offline:${this.idEmpresa}:${suffix}`;
        },

        readStorage(suffix, fallback = null) {
            return window.SmxOfflineDb?.getSync(this.offlineKey(suffix), fallback) ?? fallback;
        },

        writeStorage(suffix, data) {
            return window.SmxOfflineDb?.set(this.offlineKey(suffix), data);
        },

        buildListCacheKey() {
            return `list:${this.filtroCuenta}:${this.search.trim().toLowerCase()}`;
        },

        createOfflineId() {
            return `offline-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
        },

        buildOfflinePreview(contacto = {}) {
            return {
                id: contacto.id || this.createOfflineId(),
                cuenta: Number(contacto.cuenta || 3),
                numero: String(contacto.numero || '').trim(),
                nombre: String(contacto.nombre || '').trim(),
                direccion: String(contacto.direccion || '').trim(),
                telefono: String(contacto.telefono || '').trim(),
                email: String(contacto.email || '').trim(),
                obs: String(contacto.obs || '').trim(),
                timbrado: String(contacto.timbrado || '').trim(),
                linea_credito: Number(contacto.linea_credito || 0),
                saldo: 0,
                saldo_guaranies: 0,
                cuenta_tipo: Number(contacto.cuenta || 3) === 4 ? 'Proveedor' : (Number(contacto.cuenta || 3) === 5 ? 'Empleado' : 'Cliente'),
                offline_pending: true
            };
        },

        mergeOfflineItems(items = []) {
            const queue = Array.isArray(this.offlineQueue) ? this.offlineQueue : [];
            const map = new Map();
            queue.forEach((entry) => {
                const preview = entry?.preview;
                if (!preview || !preview.id) return;
                map.set(String(preview.id), preview);
            });
            const merged = [];
            (Array.isArray(items) ? items : []).forEach((item) => {
                const id = String(item?.id ?? '');
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

        async syncOfflineQueue(showToast = false) {
            if (!navigator.onLine || !this.offlineQueue.length) return;
            const pending = [...this.offlineQueue];
            const remaining = [];
            let synced = 0;
            for (const entry of pending) {
                try {
                    const res = await fetch(`${this.API}/guardar.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(entry.payload)
                    });
                    const data = await res.json();
                    if (res.ok && data.success) {
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
                this.page = 1;
                await this.loadContactos();
            }
            if (showToast) {
                this.showToast(
                    synced > 0 ? `Se sincronizaron ${synced} contacto(s)` : 'No se pudo sincronizar todavía',
                    synced > 0 ? 'success' : 'error'
                );
            }
        },

        resetForm() {
            this.form = {
                id: null, cuenta: 3, documento: '11', numero: '', nombre: '',
                direccion: '', telefono: '', email: '', obs: '', timbrado: '',
                linea_credito: 0, salario: 0, comision: 0, dia_pago_salario: 5, estado: 1
            };
        },

        async loadContactos(append = false) {
            this.loading = !append;
            const cacheKey = this.buildListCacheKey();
            try {
                const params = new URLSearchParams({
                    id_empresa: this.idEmpresa, page: this.page, per_page: 25,
                    search: this.search, cuenta: this.filtroCuenta, estado: '1',
                    sort_by: 'nombre', sort_dir: 'ASC'
                });
                const res = await fetch(`${this.API}/list.php?${params}`, { cache: 'no-store' });
                const data = await res.json();
                if (data.success) {
                    await this.writeStorage(cacheKey, data);
                    if (append) {
                        this.contactos = this.mergeOfflineItems([...this.contactos, ...data.data]);
                    } else {
                        this.contactos = this.mergeOfflineItems(data.data);
                    }
                    this.totalPages = data.pagination.total_pages;
                    this.stats = data.stats || this.stats;
                }
            } catch (e) {
                const cached = this.readStorage(cacheKey);
                if (cached?.success) {
                    if (append) {
                        this.contactos = this.mergeOfflineItems([...(this.contactos || []), ...(cached.data || [])]);
                    } else {
                        this.contactos = this.mergeOfflineItems(cached.data || []);
                    }
                    this.totalPages = cached?.pagination?.total_pages || 1;
                    this.stats = cached?.stats || this.stats;
                    this.showToast('Mostrando contactos desde cache offline', 'error');
                } else {
                    console.error(e);
                }
            }
            this.loading = false;
        },

        handleSearchInput(event) {
            this.search = String(event?.target?.value || '');
            this.page = 1;
            if (this.searchDebounceTimer) {
                clearTimeout(this.searchDebounceTimer);
            }
            this.searchDebounceTimer = setTimeout(() => {
                this.loadContactos();
            }, 300);
        },

        filtroRapido(val) {
            this.filtroCuenta = val;
            this.page = 1;
            this.loadContactos();
        },

        async verDetalle(id) {
            this.showDetalle = true;
            this.detalleLoading = true;
            this.detalleData = null;
            this.detalleItems = [];
            try {
                const res = await fetch(`${this.API}/detalle.php?id=${id}&id_empresa=${this.idEmpresa}`);
                const data = await res.json();
                if (data.success && data.data) {
                    await this.writeStorage(`detail:${id}`, data);
                    this.detalleData = data.data.contacto;
                    this.buildDetalleItems();
                }
            } catch (e) {
                const queued = this.contactos.find((c) => String(c.id) === String(id) && c.offline_pending);
                const cached = this.readStorage(`detail:${id}`);
                if (queued) {
                    this.detalleData = {
                        ...queued,
                        saldo_display: { guaranies: 0, dolares: 0, reales: 0 }
                    };
                    this.buildDetalleItems();
                    this.showToast('Detalle offline pendiente de sincronización', 'error');
                } else if (cached?.success && cached?.data?.contacto) {
                    this.detalleData = cached.data.contacto;
                    this.buildDetalleItems();
                    this.showToast('Detalle cargado desde cache offline', 'error');
                } else {
                    this.showToast('Error', 'error');
                }
            }
            this.detalleLoading = false;
        },

        buildDetalleItems() {
            const d = this.detalleData;
            if (!d) return;
            this.detalleItems = [
                { icon: 'fa-phone', label: 'Teléfono', value: d.telefono },
                { icon: 'fa-envelope', label: 'Email', value: d.email },
                { icon: 'fa-map-marker-alt', label: 'Dirección', value: d.direccion },
                { icon: 'fa-file-alt', label: 'Timbrado', value: d.timbrado },
                { icon: 'fa-calendar', label: 'Nacimiento', value: d.fecha_nacimiento },
                { icon: 'fa-credit-card', label: 'Línea Crédito', value: d.linea_credito > 0 ? '₲ ' + this.formatMoney(d.linea_credito) : null },
                { icon: 'fa-sticky-note', label: 'Observaciones', value: d.obs },
            ].filter(i => i.value);
        },

        editarDesdeDetalle() {
            if (!this.detalleData) return;
            const c = this.detalleData;
            this.form = {
                id: c.id, cuenta: c.cuenta || 3, documento: c.documento || '11',
                numero: c.numero || '', nombre: c.nombre || '',
                direccion: c.direccion || '', telefono: c.telefono || '',
                email: c.email || '', obs: c.obs || '', timbrado: c.timbrado || '',
                linea_credito: parseFloat(c.linea_credito) || 0,
                salario: parseFloat(c.salario) || 0, comision: parseFloat(c.comision) || 0,
                dia_pago_salario: parseInt(c.dia_pago_salario) || 5, estado: c.estado || 1,
            };
            this.showDetalle = false;
            this.showForm = true;
        },

        nuevoContacto() {
            this.resetForm();
            this.showForm = true;
        },

        async guardar() {
            if (!this.form.nombre?.trim()) { this.showToast('Nombre obligatorio', 'error'); return; }
            if (!this.form.numero?.trim()) { this.showToast('RUC/CI obligatorio', 'error'); return; }
            this.saving = true;
            try {
                const payload = {
                    id_empresa: this.idEmpresa,
                    action: this.form.id ? 'update' : 'create',
                    contacto: this.form
                };
                const res = await fetch(`${this.API}/guardar.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast(data.message);
                    this.showForm = false;
                    this.page = 1;
                    this.loadContactos();
                } else {
                    this.showToast(data.error || 'Error', 'error');
                }
            } catch (e) {
                if (!navigator.onLine) {
                    const preview = this.buildOfflinePreview(this.form);
                    const normalizedPayload = {
                        ...payload,
                        contacto: {
                            ...this.form,
                            id: this.form.id || preview.id
                        }
                    };
                    this.offlineQueue.unshift({
                        payload: normalizedPayload,
                        preview
                    });
                    this.writeStorage('queue', this.offlineQueue);
                    this.contactos = this.mergeOfflineItems(this.contactos);
                    this.stats.total = Math.max(0, Number(this.stats.total || 0)) + (this.form.id ? 0 : 1);
                    if (Number(preview.cuenta) === 3) this.stats.clientes += this.form.id ? 0 : 1;
                    if (Number(preview.cuenta) === 4) this.stats.proveedores += this.form.id ? 0 : 1;
                    if (Number(preview.cuenta) === 5) this.stats.empleados += this.form.id ? 0 : 1;
                    this.showToast('Contacto guardado offline', 'success');
                    this.showForm = false;
                    this.resetForm();
                } else {
                    this.showToast('Error de red', 'error');
                }
            }
            this.saving = false;
        },

        formatMoney(v) {
            const n = parseFloat(v) || 0;
            return n.toLocaleString('es-PY', { maximumFractionDigits: 0 });
        },

        showToast(msg, type = 'success') {
            this.toast = { show: true, msg, type };
            setTimeout(() => this.toast.show = false, 3000);
        }
    };
}
</script>
</body>
</html>
