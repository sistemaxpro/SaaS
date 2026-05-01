<?php
/**
 * Taller Mecanico - Modulo de Mantenimiento (Desktop)
 */
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
Permission::requireAccess('app_grid_taller_mantenimiento');
$permisos = Permission::getAppPermissions('app_grid_taller_mantenimiento');

$id_empresa = Session::get('id_empresa', 169);
$masterPdo = Database::getMasterConnection();
$stmtEmpresa = $masterPdo->prepare("SELECT empresa FROM empresa WHERE id_empresa = ?");
$stmtEmpresa->execute([$id_empresa]);
$empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es" x-data="tallerApp()" x-init="init()">
<script>
    // Heredar tema activo de la sesión de navegación (mismo criterio que otros módulos)
    (function() {
        const theme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const isDark = theme === 'dark' || (!theme && prefersDark);
        document.documentElement.classList.toggle('dark', isDark);
    })();
</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Taller Mecanico - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
    <!-- AG Grid CSS: solo desktop (media query evita parseo en mobile) -->
    <link rel="stylesheet" href="/public/_lib/ag-grid/ag-grid-enterprise/package/styles/ag-grid.css" media="(min-width: 768px)">
    <link rel="stylesheet" href="/public/_lib/ag-grid/ag-grid-enterprise/package/styles/ag-theme-quartz.css" media="(min-width: 768px)">
    <script src="/public/assets/js/ag-grid-locale.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- AG Grid JS: solo desktop (no parsear 2.8 MB en mobile) -->
    <script>
    window.__agGridReady = new Promise(function(resolve){
        if (window.innerWidth < 768) { resolve(); return; }
        var s = document.createElement('script');
        s.src = '/public/_lib/ag-grid/ag-grid-enterprise/package/dist/ag-grid-enterprise.min.noStyle.js';
        s.onload = function(){ var l = document.createElement('script'); l.src = '/public/_lib/ag-grid/license.js'; l.onload = resolve; document.head.appendChild(l); };
        s.onerror = resolve;
        document.head.appendChild(s);
    });
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        html.dark body.taller-theme {
            background-color: #020617;
            color: #e2e8f0;
        }
        html.dark body.taller-theme .bg-white { background-color: #0f172a !important; }
        html.dark body.taller-theme .bg-gray-50 { background-color: #0b1220 !important; }
        html.dark body.taller-theme .bg-gray-100 { background-color: #1e293b !important; }
        html.dark body.taller-theme .bg-orange-50 { background-color: rgba(194, 65, 12, 0.18) !important; }
        html.dark body.taller-theme .bg-red-50 { background-color: rgba(220, 38, 38, 0.18) !important; }
        html.dark body.taller-theme .border-gray-200 { border-color: #334155 !important; }
        html.dark body.taller-theme .border-gray-300 { border-color: #475569 !important; }
        html.dark body.taller-theme .text-gray-900,
        html.dark body.taller-theme .text-gray-800 { color: #f1f5f9 !important; }
        html.dark body.taller-theme .text-gray-600,
        html.dark body.taller-theme .text-gray-500 { color: #94a3b8 !important; }
        html.dark body.taller-theme input,
        html.dark body.taller-theme select,
        html.dark body.taller-theme textarea {
            background-color: #0b1220;
            border-color: #475569;
            color: #e2e8f0;
        }
        html.dark body.taller-theme input::placeholder,
        html.dark body.taller-theme textarea::placeholder { color: #64748b; }
        .taller-grid {
            width: 100%;
            height: 62vh;
            min-height: 420px;
        }
        .ag-theme-quartz,
        .ag-theme-quartz-dark {
            --ag-font-size: 12px;
        }
        /* Filas clickeables */
        .ag-row { cursor: pointer; }
        .ag-theme-quartz .ag-row:hover { background-color: rgba(249, 115, 22, 0.06); }
        .ag-theme-quartz-dark .ag-row:hover { background-color: rgba(249, 115, 22, 0.10); }
        /* ── Mobile: scroll nativo + cards HTML ── */
        @media (max-width: 767px) {
            /* ====== SCROLL FIX ====== */
            html {
                overflow-y: scroll !important;   /* html = scroll container */
                height: auto !important;
                -webkit-overflow-scrolling: touch;
            }
            body {
                overflow-y: visible !important;   /* body NO captura scroll */
                min-height: auto !important;       /* anula min-h-screen / 100vh */
                height: auto !important;
                touch-action: pan-y !important;    /* solo scroll vertical */
            }
            main, .mobile-card, header {
                touch-action: pan-y !important;
            }
            /* ====== FIN SCROLL FIX ====== */
            .taller-grid { display: none !important; }
            .mobile-card {
                padding: 12px; border: 1px solid #e5e7eb; border-radius: 12px;
                background: #fff;
                -webkit-tap-highlight-color: rgba(249,115,22,.12);
                touch-action: manipulation;        /* elimina 300ms tap delay */
                user-select: none;
            }
            .mobile-card:active { background: #fff7ed; }
            html.dark .mobile-card { background: #0f172a; border-color: #334155; }
            html.dark .mobile-card:active { background: #1c1917; }
            .mobile-pager { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 8px 0; }
            .mobile-pager button { min-width: 44px; min-height: 36px; }
        }
        .ag-watermark,.ag-watermark-text{display:none!important;opacity:0!important;visibility:hidden!important;}
    </style>
</head>
<body class="taller-theme bg-gray-50 dark:bg-slate-950 min-h-screen">
<script>
window.__PERMISOS__ = <?= json_encode($permisos) ?>;
</script>

<header class="bg-white border-b border-gray-200 sticky top-0 z-20">
    <div class="w-full px-4 sm:px-6 py-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div>
                <h1 class="text-lg font-bold text-gray-900"><i class="fas fa-screwdriver-wrench text-orange-600 mr-2"></i>Taller Mecanico</h1>
                <p class="text-xs text-gray-500"><?= htmlspecialchars($empresa['empresa'] ?? 'Empresa') ?></p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button @click="clearCache()" class="px-3 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-gray-600 dark:text-gray-300 rounded-lg text-sm" title="Limpiar caché y recargar">
                <i class="fas fa-broom"></i>
            </button>
            <button x-show="permisos.priv_insert === 'Y'" @click="openOtForm()" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-semibold">
                <i class="fas fa-plus mr-1"></i>Nueva OT
            </button>
        </div>
    </div>
</header>

<main class="w-full px-4 sm:px-6 py-4 space-y-4">
    <div class="bg-white rounded-xl border border-gray-200 p-3 flex flex-wrap items-center gap-2">
        <template x-for="t in tabs" :key="t.key">
            <button @click="setTab(t.key)" class="px-3 py-1.5 rounded-lg text-sm font-medium"
                    :class="tab===t.key ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                <i :class="t.icon" class="mr-1"></i><span x-text="t.label"></span>
            </button>
        </template>
        <div class="ml-auto w-full sm:w-auto sm:min-w-[300px]">
            <input
                x-model="gridSearch"
                @input.debounce.180ms="applyQuickFilter()"
                :placeholder="'Buscar en ' + tab + '...'"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
            >
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-4" x-show="tab==='ordenes'">
        <div class="flex flex-wrap gap-2 mb-3">
            <input x-model="filtros.search" @input.debounce.350ms="loadOrdenes()" placeholder="Buscar OT / cliente / chapa"
                   class="px-3 py-2 border border-gray-300 rounded-lg text-sm min-w-[260px]">
            <select x-model="filtros.estado" @change="loadOrdenes()" class="px-3 py-2 border border-gray-300 rounded-lg text-sm">
                <option value="">Todos los estados</option>
                <template x-for="e in estadosOt" :key="e"><option :value="e" x-text="e"></option></template>
            </select>
        </div>
        <div x-ref="gridOrdenes" :class="{'ag-theme-quartz': themeMode !== 'dark', 'ag-theme-quartz-dark': themeMode === 'dark'}" class="taller-grid"></div>
        <!-- Mobile ordenes cards -->
        <div x-show="isMobileView" class="space-y-2">
            <template x-for="o in _mobilePaginated('ordenes')" :key="'mo'+o.id_orden">
                <div @click="editOt(Number(o.id_orden))" class="mobile-card flex items-center gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold text-orange-600" x-text="'#'+o.nro_ot"></span>
                            <span class="text-xs text-gray-400" x-text="o.fecha"></span>
                        </div>
                        <div class="text-sm font-medium truncate" x-text="o.cliente||'-'"></div>
                        <div class="text-xs text-gray-500 truncate" x-text="(o.marca||'')+' '+(o.modelo||'')+(o.chapa?' ['+o.chapa+']':'')"></div>
                    </div>
                    <div class="text-right shrink-0 space-y-1">
                        <select @click.stop class="js-grid-estado block text-xs border rounded px-1.5 py-1 dark:bg-slate-800 dark:border-slate-600" :data-id="o.id_orden" data-grid="ordenes">
                            <template x-for="e in estadosOt" :key="'moe'+o.id_orden+e"><option :value="e" x-text="e" :selected="e===o.estado"></option></template>
                        </select>
                        <div class="text-sm font-bold" x-text="formatMoney(o.total)"></div>
                    </div>
                </div>
            </template>
            <div x-show="_mobilePageCount('ordenes')>1" class="mobile-pager">
                <button @click="mobilePage=Math.max(1,mobilePage-1)" :disabled="mobilePage<=1" class="px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-slate-800 disabled:opacity-30">‹ Anterior</button>
                <span class="text-xs text-gray-500" x-text="mobilePage+' / '+_mobilePageCount('ordenes')"></span>
                <button @click="mobilePage=Math.min(_mobilePageCount('ordenes'),mobilePage+1)" :disabled="mobilePage>=_mobilePageCount('ordenes')" class="px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-slate-800 disabled:opacity-30">Siguiente ›</button>
            </div>
            <p x-show="_mobileFiltered('ordenes').length===0" class="text-center text-sm text-gray-400 py-6">No hay órdenes</p>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-4" x-show="tab==='clientes'">
        <div class="flex justify-between mb-3">
            <h3 class="font-semibold text-gray-800">Clientes del taller</h3>
            <button x-show="permisos.priv_insert === 'Y'" @click="openClienteForm()" class="px-3 py-1.5 bg-orange-600 text-white rounded-lg text-sm">Agregar cliente</button>
        </div>
        <div x-ref="gridClientes" :class="{'ag-theme-quartz': themeMode !== 'dark', 'ag-theme-quartz-dark': themeMode === 'dark'}" class="taller-grid"></div>
        <!-- Mobile clientes cards -->
        <div x-show="isMobileView" class="space-y-2">
            <template x-for="c in _mobilePaginated('clientes')" :key="'mc'+c.id_cliente">
                <div @click="openClienteForm(c)" class="mobile-card">
                    <div class="flex justify-between items-start gap-2">
                        <div class="font-semibold text-sm" x-text="c.nombre||'-'"></div>
                        <span class="shrink-0 text-xs font-bold px-2 py-0.5 rounded-full"
                              :class="Number(c.saldo||0)>=0?'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300':'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'"
                              x-text="formatMoney(c.saldo)"></span>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1" x-show="c.documento" x-text="'Doc: '+c.documento"></div>
                    <div class="text-xs text-gray-500 dark:text-gray-400" x-text="'Tel: '+(c.telefono||'-')"></div>
                    <div class="flex gap-2 mt-2" @click.stop>
                        <a :href="phoneDigits(c.telefono)?('tel:'+phoneDigits(c.telefono)):'#'" class="text-xs bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 px-2.5 py-1 rounded-lg font-bold no-underline"><i class="fas fa-phone"></i> Llamar</a>
                        <a x-show="phoneDigits(c.telefono)" :href="'https://wa.me/'+phoneDigits(c.telefono)+'?text='+encodeURIComponent('Hola '+(c.nombre||''))" target="_blank" rel="noopener" class="text-xs bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 px-2.5 py-1 rounded-lg font-bold no-underline"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                    </div>
                </div>
            </template>
            <div x-show="_mobilePageCount('clientes')>1" class="mobile-pager">
                <button @click="mobilePage=Math.max(1,mobilePage-1)" :disabled="mobilePage<=1" class="px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-slate-800 disabled:opacity-30">‹ Anterior</button>
                <span class="text-xs text-gray-500" x-text="mobilePage+' / '+_mobilePageCount('clientes')"></span>
                <button @click="mobilePage=Math.min(_mobilePageCount('clientes'),mobilePage+1)" :disabled="mobilePage>=_mobilePageCount('clientes')" class="px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-slate-800 disabled:opacity-30">Siguiente ›</button>
            </div>
            <p x-show="_mobileFiltered('clientes').length===0" class="text-center text-sm text-gray-400 py-6">No hay clientes</p>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-4" x-show="tab==='vehiculos'">
        <div class="flex justify-between mb-3">
            <h3 class="font-semibold text-gray-800">Vehículo</h3>
            <button x-show="permisos.priv_insert === 'Y'" @click="openVehiculoForm()" class="px-3 py-1.5 bg-orange-600 text-white rounded-lg text-sm">Agregar vehiculo</button>
        </div>
        <div x-ref="gridVehiculos" :class="{'ag-theme-quartz': themeMode !== 'dark', 'ag-theme-quartz-dark': themeMode === 'dark'}" class="taller-grid"></div>
        <!-- Mobile vehiculos cards -->
        <div x-show="isMobileView" class="space-y-2">
            <template x-for="v in _mobilePaginated('vehiculos')" :key="'mv'+v.id_vehiculo">
                <div @click="openVehiculoForm(v)" class="mobile-card">
                    <div class="font-medium text-sm" x-text="(v.marca||'')+' '+(v.modelo||'')"></div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        <span x-text="v.chapa?('Chapa: '+v.chapa):''" class="font-semibold"></span>
                        <span x-show="v.anio" x-text="' · '+v.anio"></span>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400" x-text="v.cliente||''"></div>
                    <div x-show="v.km_actual" class="text-xs text-gray-400 mt-0.5" x-text="'KM: '+Number(v.km_actual||0).toLocaleString()"></div>
                </div>
            </template>
            <div x-show="_mobilePageCount('vehiculos')>1" class="mobile-pager">
                <button @click="mobilePage=Math.max(1,mobilePage-1)" :disabled="mobilePage<=1" class="px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-slate-800 disabled:opacity-30">‹ Anterior</button>
                <span class="text-xs text-gray-500" x-text="mobilePage+' / '+_mobilePageCount('vehiculos')"></span>
                <button @click="mobilePage=Math.min(_mobilePageCount('vehiculos'),mobilePage+1)" :disabled="mobilePage>=_mobilePageCount('vehiculos')" class="px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-slate-800 disabled:opacity-30">Siguiente ›</button>
            </div>
            <p x-show="_mobileFiltered('vehiculos').length===0" class="text-center text-sm text-gray-400 py-6">No hay vehículos</p>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-4" x-show="tab==='servicios'">
        <div class="flex justify-between mb-3">
            <h3 class="font-semibold text-gray-800">Servicio</h3>
            <button x-show="permisos.priv_insert === 'Y'" @click="openServicioForm()" class="px-3 py-1.5 bg-orange-600 text-white rounded-lg text-sm">Agregar servicio</button>
        </div>
        <div x-ref="gridServicios" :class="{'ag-theme-quartz': themeMode !== 'dark', 'ag-theme-quartz-dark': themeMode === 'dark'}" class="taller-grid"></div>
        <!-- Mobile servicios cards -->
        <div x-show="isMobileView" class="space-y-2">
            <template x-for="s in _mobilePaginated('servicios')" :key="'ms'+s.id_servicio">
                <div @click="openServicioForm(s)" class="mobile-card flex justify-between items-center gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="font-medium text-sm" x-text="s.servicio||'-'"></div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="s.descripcion||''"></div>
                        <div class="text-xs text-gray-400 mt-1" x-text="'Duración: '+formatHoursHHMM(s.duracion_horas)"></div>
                    </div>
                    <div class="text-sm font-bold text-right shrink-0" x-text="formatMoney(s.costo_base)"></div>
                </div>
            </template>
            <div x-show="_mobilePageCount('servicios')>1" class="mobile-pager">
                <button @click="mobilePage=Math.max(1,mobilePage-1)" :disabled="mobilePage<=1" class="px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-slate-800 disabled:opacity-30">‹ Anterior</button>
                <span class="text-xs text-gray-500" x-text="mobilePage+' / '+_mobilePageCount('servicios')"></span>
                <button @click="mobilePage=Math.min(_mobilePageCount('servicios'),mobilePage+1)" :disabled="mobilePage>=_mobilePageCount('servicios')" class="px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 dark:bg-slate-800 disabled:opacity-30">Siguiente ›</button>
            </div>
            <p x-show="_mobileFiltered('servicios').length===0" class="text-center text-sm text-gray-400 py-6">No hay servicios</p>
        </div>
    </div>
</main>

<div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center px-3">
    <div class="absolute inset-0 bg-black/60" @click="closeModal()"></div>
    <div
        class="relative bg-white rounded-2xl shadow-xl p-4 max-h-[90vh] overflow-y-auto"
        :class="modalType === 'ot'
            ? 'w-full md:w-[90vw] md:max-w-[90vw] max-w-full'
            : 'w-full max-w-3xl'"
    >
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-bold text-gray-800" x-text="modalTitle"></h3>
            <button @click="closeModal()" class="w-8 h-8 rounded bg-gray-100"><i class="fas fa-times"></i></button>
        </div>

        <div x-show="modalType==='cliente'" class="space-y-2">
            <input x-model="clienteForm.nombre" placeholder="Nombre" class="w-full px-3 py-2 border rounded-lg">
            <input x-model="clienteForm.telefono" placeholder="Telefono" class="w-full px-3 py-2 border rounded-lg">
            <input x-model="clienteForm.documento" placeholder="Documento" class="w-full px-3 py-2 border rounded-lg">
            <input x-model="clienteForm.direccion" placeholder="Direccion" class="w-full px-3 py-2 border rounded-lg">
            <button @click="saveCliente()" class="px-4 py-2 bg-orange-600 text-white rounded-lg">Guardar</button>
        </div>

        <div x-show="modalType==='vehiculo'" class="space-y-3">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <div class="relative md:col-span-2">
                    <input
                        x-model="vehiculoClienteSearch"
                        @input="buscarClientesVehiculo()"
                        @focus="buscarClientesVehiculo()"
                        placeholder="Buscar cliente (nombre, ruc, teléfono)"
                        class="w-full px-3 py-2 border rounded-lg"
                    >
                    <input type="hidden" x-model.number="vehiculoForm.id_cliente">
                    <div
                        x-show="vehiculoClienteResults.length > 0"
                        class="absolute z-[70] w-full mt-1 bg-white dark:bg-slate-800 rounded-lg shadow-xl border border-gray-200 dark:border-slate-700 max-h-52 overflow-y-auto"
                    >
                        <template x-for="cli in vehiculoClienteResults" :key="'vcli'+cli.id">
                            <button
                                type="button"
                                @click="selectClienteVehiculo(cli)"
                                class="w-full px-3 py-2 text-left hover:bg-orange-50 dark:hover:bg-slate-700 border-b border-gray-100 dark:border-slate-700/50 last:border-0"
                            >
                                <p class="text-sm font-medium text-gray-900 dark:text-white" x-text="cli.nombre"></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400" x-text="cli.ruc || cli.telefono || ''"></p>
                            </button>
                        </template>
                    </div>
                </div>
                <input x-model="vehiculoForm.chapa" placeholder="Chapa" class="px-3 py-2 border rounded-lg">
                <input x-model="vehiculoForm.marca" placeholder="Marca" class="px-3 py-2 border rounded-lg">
                <input x-model="vehiculoForm.modelo" placeholder="Modelo" class="px-3 py-2 border rounded-lg">
                <input x-model="vehiculoForm.anio" placeholder="Anio" class="px-3 py-2 border rounded-lg">
                <input x-model="vehiculoForm.chassis" placeholder="Chassis" class="px-3 py-2 border rounded-lg">
                <input x-model="vehiculoForm.color" placeholder="Color" class="px-3 py-2 border rounded-lg">
                <input x-model.number="vehiculoForm.km_actual" type="number" min="0" placeholder="KM actual" class="px-3 py-2 border rounded-lg">
            </div>

            <div x-show="vehiculoForm.id_vehiculo" class="border border-gray-200 dark:border-slate-700 rounded-lg p-3 space-y-2">
                <div class="flex items-center justify-between gap-2 flex-wrap">
                    <h4 class="font-semibold">Galería de Fotos</h4>
                    <div class="flex gap-2 flex-wrap">
                        <button type="button" @click="openVehiculoCamera()" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm">
                            <i class="fas fa-camera mr-1"></i>Capturar
                        </button>
                        <label class="px-3 py-1.5 bg-gray-200 dark:bg-slate-700 rounded-lg text-sm cursor-pointer">
                            <i class="fas fa-image mr-1"></i>Subir
                            <input type="file" accept="image/*" class="hidden" @change="onVehiculoFileSelected($event)">
                        </label>
                        <button type="button" @click="openVehiculoGoogleImages()" class="px-3 py-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-lg text-sm font-medium dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100">
                            <i class="fab fa-google mr-1"></i>Google Imágenes
                        </button>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-2">
                    <div
                        tabindex="0"
                        @paste.prevent="onVehiculoImagePaste($event)"
                        class="px-3 py-2 border border-dashed rounded-lg bg-gray-50 dark:bg-slate-800/70 text-sm text-gray-500 dark:text-gray-300 flex items-center"
                    >
                        Copiá la imagen desde Google y pegá aquí con Ctrl+V
                    </div>
                    <button type="button" @click="pasteVehiculoClipboardImage()" class="px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium">
                        <i class="fas fa-paste mr-1"></i>Pegar Imagen
                    </button>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                    <template x-for="foto in vehiculoFotos" :key="'vf'+foto.id_foto">
                        <div class="relative group border border-gray-200 dark:border-slate-700 rounded-lg overflow-hidden">
                            <a :href="foto.url" target="_blank" rel="noopener">
                                <img :src="foto.url" class="w-full h-28 object-cover" alt="Foto vehículo">
                            </a>
                            <button type="button" @click="deleteVehiculoFoto(foto.id_foto)" class="absolute top-1 right-1 w-7 h-7 rounded-full bg-black/60 text-white opacity-90 hover:bg-red-600" title="Suprimir foto">
                                <i class="fas fa-trash text-xs"></i>
                            </button>
                            <div class="p-2 bg-white dark:bg-slate-900 border-t border-gray-200 dark:border-slate-700">
                                <button type="button" @click="deleteVehiculoFoto(foto.id_foto)" class="w-full px-2 py-1 rounded bg-red-50 text-red-700 border border-red-200 text-xs font-semibold hover:bg-red-100">
                                    <i class="fas fa-trash mr-1"></i>Suprimir
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
                <p x-show="vehiculoFotos.length===0" class="text-xs text-gray-500 dark:text-gray-400">Sin fotos cargadas.</p>
            </div>
            <p x-show="!vehiculoForm.id_vehiculo" class="text-xs text-gray-500 dark:text-gray-400">Guardá el vehículo para habilitar galería de fotos.</p>

            <div><button @click="saveVehiculo()" class="px-4 py-2 bg-orange-600 text-white rounded-lg">Guardar</button></div>
        </div>

        <div x-show="modalType==='servicio'" class="space-y-2">
            <input x-model="servicioForm.servicio" placeholder="Servicio" class="w-full px-3 py-2 border rounded-lg">
            <input x-model="servicioForm.descripcion" placeholder="Descripcion" class="w-full px-3 py-2 border rounded-lg">
            <div class="grid grid-cols-2 gap-2">
                <input x-model.number="servicioForm.costo_base" type="number" min="0" placeholder="Costo base" class="px-3 py-2 border rounded-lg">
                <input x-model="servicioForm.duracion_hhmm" @blur="servicioForm.duracion_hhmm = normalizeHoursHHMM(servicioForm.duracion_hhmm)" inputmode="numeric" placeholder="Duración hh:mm" class="px-3 py-2 border rounded-lg">
            </div>
            <button @click="saveServicio()" class="px-4 py-2 bg-orange-600 text-white rounded-lg">Guardar</button>
        </div>

        <div x-show="modalType==='ot'" class="space-y-3">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <input x-model="otForm.fecha" type="date" class="px-3 py-2 border rounded-lg">
                <input x-model="otForm.hora_ingreso" type="time" class="px-3 py-2 border rounded-lg">
                <select x-model="otForm.prioridad" class="px-3 py-2 border rounded-lg">
                    <template x-for="p in prioridadesOt" :key="p"><option :value="p" x-text="p"></option></template>
                </select>
                <select x-model.number="otForm.id_mecanico" @change="syncOtMecanicoNombre()" class="px-3 py-2 border rounded-lg">
                    <option value="">Mecánico encargado</option>
                    <template x-for="m in mecanicos" :key="'mec'+m.id_login">
                        <option :value="Number(m.id_login)" x-text="m.nombre || m.login || ('Usuario #' + m.id_login)"></option>
                    </template>
                </select>
                <div class="relative">
                    <div class="flex gap-2">
                        <input
                            x-model="otClienteSearch"
                            @input="buscarClientesOt()"
                            @focus="buscarClientesOt()"
                            placeholder="Cliente"
                            class="w-full px-3 py-2 border rounded-lg"
                        >
                        <button type="button" @click="toggleSpeechInput('otClienteSearch')" class="w-11 shrink-0 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700">
                            <i class="fas fa-microphone"></i>
                        </button>
                    </div>
                    <div x-show="otClienteResults.length > 0" class="absolute z-[70] w-full mt-1 bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-gray-200 dark:border-slate-700 max-h-64 overflow-y-auto">
                        <template x-for="cli in otClienteResults" :key="'otcli'+cli.id">
                            <button
                                type="button"
                                @click="selectClienteOt(cli)"
                                class="w-full px-3 py-2 text-left hover:bg-orange-50 dark:hover:bg-slate-700 border-b border-gray-100 dark:border-slate-700/50 last:border-0"
                            >
                                <p class="text-sm font-medium text-gray-900 dark:text-white" x-text="cli.nombre"></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400" x-text="cli.ruc || cli.telefono || ''"></p>
                            </button>
                        </template>
                    </div>
                </div>
                <div class="relative">
                    <div class="flex gap-2">
                        <input
                            x-model="otVehiculoSearch"
                            @input="buscarVehiculosOt()"
                            @focus="buscarVehiculosOt()"
                            placeholder="Vehículo"
                            class="w-full px-3 py-2 border rounded-lg"
                        >
                        <button type="button" @click="toggleSpeechInput('otVehiculoSearch')" class="w-11 shrink-0 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700">
                            <i class="fas fa-microphone"></i>
                        </button>
                    </div>
                    <div x-show="otVehiculoResults.length > 0" class="absolute z-[70] w-full mt-1 bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-gray-200 dark:border-slate-700 max-h-72 overflow-y-auto">
                        <template x-for="v in otVehiculoResults" :key="'otveh'+v.id_vehiculo">
                            <button
                                type="button"
                                @click="selectVehiculoOt(v)"
                                class="w-full px-3 py-2 text-left hover:bg-orange-50 dark:hover:bg-slate-700 border-b border-gray-100 dark:border-slate-700/50 last:border-0 flex items-center gap-3"
                            >
                                <div class="w-14 h-14 rounded-xl overflow-hidden border border-gray-200 dark:border-slate-700 bg-slate-100 dark:bg-slate-900 shrink-0 flex items-center justify-center">
                                    <template x-if="vehiculoThumbUrl(v)">
                                        <img :src="vehiculoThumbUrl(v)" alt="Vehículo" class="w-full h-full object-cover">
                                    </template>
                                    <template x-if="!vehiculoThumbUrl(v)">
                                        <i class="fas fa-car-side text-gray-400"></i>
                                    </template>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white truncate" x-text="formatVehiculoOption(v)"></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="v.cliente || ''"></p>
                                </div>
                            </button>
                        </template>
                    </div>
                </div>
                <button
                    type="button"
                    @click="editVehiculoFromOt()"
                    :disabled="!otForm.id_vehiculo"
                    class="px-3 py-2 border rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-100 dark:hover:bg-slate-700"
                >
                    <i class="fas fa-car-side mr-1"></i>Editar vehículo
                </button>
                <input x-model.number="otForm.km_ingreso" type="number" min="0" placeholder="KM ingreso" class="px-3 py-2 border rounded-lg">
                <select x-model="otForm.estado" class="px-3 py-2 border rounded-lg">
                    <template x-for="e in estadosOt" :key="'ote'+e"><option :value="e" x-text="e"></option></template>
                </select>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                <div class="rounded-xl border border-blue-500/20 bg-blue-950/10 px-3 py-2">
                    <p class="text-xs text-blue-300/80">Duración estimada</p>
                    <p class="font-semibold text-blue-200" x-text="formatHoursHHMM(otEstimatedHours())"></p>
                </div>
                <div class="rounded-xl border border-emerald-500/20 bg-emerald-950/10 px-3 py-2">
                    <p class="text-xs text-emerald-300/80">Término estimado</p>
                    <p class="font-semibold text-emerald-200" x-text="formatDateTimeLocal(otEstimatedEndDateTime())"></p>
                </div>
            </div>
            <div class="relative">
                <textarea x-model="otForm.problema" placeholder="Problema reportado" class="w-full px-3 py-2 pr-12 border rounded-lg"></textarea>
                <button type="button" @click="toggleSpeechInput('otForm.problema')" class="absolute top-2 right-2 w-9 h-9 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700">
                    <i class="fas fa-microphone"></i>
                </button>
            </div>
            <div class="relative">
                <textarea x-model="otForm.diagnostico" placeholder="Diagnostico / trabajo a realizar" class="w-full px-3 py-2 pr-12 border rounded-lg"></textarea>
                <button type="button" @click="toggleSpeechInput('otForm.diagnostico')" class="absolute top-2 right-2 w-9 h-9 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700">
                    <i class="fas fa-microphone"></i>
                </button>
            </div>

            <div class="border rounded-lg p-3 space-y-2">
                <div class="flex justify-between items-center gap-2">
                    <div>
                        <h4 class="font-semibold">Prefactura pendiente de cierre</h4>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Agregá repuestos y servicios realizados antes del cierre final de la OT.</p>
                    </div>
                    <button @click="addOtItem()" class="px-2 py-1 bg-gray-100 rounded text-sm">+ Item</button>
                </div>
                <template x-for="(it, idx) in otForm.items" :key="'iti'+idx">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-2 items-center">
                        <select x-model="it.tipo" @change="onOtItemTypeChange(it)" class="md:col-span-2 px-2 py-2 border rounded-lg">
                            <option value="servicio">Servicio</option>
                            <option value="repuesto">Repuesto</option>
                            <option value="manual">Manual</option>
                        </select>
                        <template x-if="it.tipo === 'servicio'">
                            <select x-model.number="it.id_servicio" @change="fillItemFromServicio(it)" class="md:col-span-3 px-2 py-2 border rounded-lg">
                                <option value="">Servicio</option>
                                <template x-for="s in servicios" :key="'sis'+s.id_servicio"><option :value="Number(s.id_servicio)" x-text="s.servicio"></option></template>
                            </select>
                        </template>
                        <template x-if="it.tipo === 'repuesto'">
                            <div class="md:col-span-3 relative">
                                <input
                                    x-model="it.producto_buscar"
                                    @input="buscarProductosOtItem(it)"
                                    @focus="buscarProductosOtItem(it)"
                                    placeholder="Buscar repuesto"
                                    class="w-full px-2 py-2 border rounded-lg"
                                >
                                <div x-show="Array.isArray(it.producto_results) && it.producto_results.length > 0" class="absolute z-[70] w-full mt-1 bg-white dark:bg-slate-800 rounded-xl shadow-xl border border-gray-200 dark:border-slate-700 max-h-72 overflow-y-auto">
                                    <template x-for="prod in it.producto_results" :key="'otp'+idx+'-'+prod.id">
                                        <button
                                            type="button"
                                            @click="selectProductoOtItem(it, prod)"
                                            class="w-full px-3 py-2 text-left hover:bg-orange-50 dark:hover:bg-slate-700 border-b border-gray-100 dark:border-slate-700/50 last:border-0"
                                        >
                                            <p class="text-sm font-medium text-gray-900 dark:text-white truncate" x-text="prod.descripcion || ''"></p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="(prod.codigo || '-') + ' · ' + formatMoney(prod.precio || 0)"></p>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>
                        <template x-if="it.tipo === 'manual'">
                            <div class="md:col-span-3 px-2 py-2 border rounded-lg text-xs text-gray-400 bg-gray-50 dark:bg-slate-800/60">
                                Ítem manual
                            </div>
                        </template>
                        <div class="md:col-span-3 relative">
                            <input x-model="it.descripcion" placeholder="Descripción" class="w-full px-2 py-2 pr-10 border rounded-lg">
                            <button type="button" @click="toggleSpeechInput(`otItemDescripcion:${idx}`)" class="absolute top-1.5 right-1.5 w-8 h-8 rounded-lg border border-gray-300 dark:border-slate-600 text-gray-600 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700">
                                <i class="fas fa-microphone text-xs"></i>
                            </button>
                        </div>
                        <input x-model.number="it.cantidad" type="number" min="1" step="1" class="md:col-span-1 px-2 py-2 border rounded-lg text-right">
                        <input x-model.number="it.precio" type="number" min="0" step="100" class="md:col-span-2 px-2 py-2 border rounded-lg text-right">
                        <button @click="otForm.items.splice(idx,1)" class="md:col-span-1 w-full px-2 py-2 bg-red-50 text-red-600 rounded-lg border border-red-100 hover:bg-red-100" title="Anular ítem"><i class="fas fa-trash"></i></button>
                    </div>
                </template>
                <p class="text-right font-bold text-gray-800">Total: <span x-text="formatMoney(otTotal())"></span></p>
            </div>

            <div class="space-y-2">
                <div x-show="otForm.share_url" class="rounded-xl border border-emerald-700/40 bg-emerald-950/20 px-3 py-2 text-sm text-emerald-300 break-all" x-text="otForm.share_url"></div>
                <div x-show="otForm.id_orden" class="rounded-2xl border border-slate-700/70 bg-slate-900/60 overflow-hidden">
                    <div class="px-4 py-3 border-b border-slate-800 flex items-center justify-between gap-3">
                        <div>
                            <h4 class="font-semibold text-slate-100">Chat directo con el mecánico</h4>
                            <p class="text-xs text-slate-400" x-text="otForm.mecanico_nombre ? ('Encargado: ' + otForm.mecanico_nombre) : 'Asigná un mecánico para centralizar la conversación.'"></p>
                        </div>
                        <button type="button" @click="loadOtChat(true)" class="px-3 py-2 rounded-lg border border-slate-700 text-slate-200 hover:bg-slate-800 text-sm">Actualizar</button>
                    </div>
                    <div class="p-4 space-y-3">
                        <div class="max-h-72 overflow-y-auto space-y-2 pr-1">
                            <template x-if="!Array.isArray(otForm.chat) || otForm.chat.length === 0">
                                <div class="rounded-xl border border-dashed border-slate-700 px-3 py-4 text-sm text-slate-400">Sin mensajes todavía.</div>
                            </template>
                            <template x-for="msg in (otForm.chat || [])" :key="'otchat'+msg.id_chat">
                                <div class="rounded-xl px-3 py-2 border"
                                     :class="msg.sender_type === 'cliente' ? 'bg-slate-950/70 border-slate-700 text-slate-200' : 'bg-blue-950/30 border-blue-800/40 text-blue-100'">
                                    <div class="flex items-center justify-between gap-3 mb-1">
                                        <span class="text-xs font-semibold uppercase tracking-wide" x-text="msg.sender_name || 'Taller'"></span>
                                        <span class="text-[11px] opacity-70" x-text="formatDateTimeLocal(msg.created_at || '')"></span>
                                    </div>
                                    <div class="text-sm whitespace-pre-wrap" x-text="msg.mensaje || ''"></div>
                                </div>
                            </template>
                        </div>
                        <div class="flex gap-2">
                            <textarea x-model="otForm.chat_message" rows="2" placeholder="Escribir mensaje al cliente..." class="w-full px-3 py-2 border rounded-xl"></textarea>
                            <button type="button" @click="sendOtChat()" class="px-4 py-2 rounded-xl bg-sky-600 text-white hover:bg-sky-700 shrink-0">Enviar</button>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button @click="saveOt()" class="px-4 py-2 bg-orange-600 text-white rounded-lg">Guardar OT</button>
                    <button @click="publishOt(false)" class="px-4 py-2 bg-blue-600 text-white rounded-lg">Publicar OT</button>
                    <button @click="publishOt(true)" class="px-4 py-2 bg-green-600 text-white rounded-lg">
                        <i class="fab fa-whatsapp mr-1"></i>Enviar link
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div x-show="showVehiculoCamera" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center px-3">
    <div class="absolute inset-0 bg-black/70" @click="closeVehiculoCamera()"></div>
    <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-full max-w-2xl p-4 space-y-3">
        <div class="flex items-center justify-between">
            <h3 class="font-semibold">Capturar Foto del Vehículo</h3>
            <button @click="closeVehiculoCamera()" class="w-8 h-8 rounded bg-gray-100 dark:bg-slate-700"><i class="fas fa-times"></i></button>
        </div>
        <video x-ref="vehiculoCameraVideo" autoplay playsinline class="w-full max-h-[60vh] bg-black rounded-xl"></video>
        <div class="flex gap-2 justify-end">
            <button type="button" @click="closeVehiculoCamera()" class="px-3 py-2 bg-gray-200 dark:bg-slate-700 rounded-lg">Cancelar</button>
            <button type="button" @click="captureVehiculoPhoto()" class="px-3 py-2 bg-blue-600 text-white rounded-lg">
                <i class="fas fa-camera mr-1"></i>Tomar foto
            </button>
        </div>
    </div>
</div>

<div x-show="toastState.show" x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-y-4 opacity-0"
     x-transition:enter-end="translate-y-0 opacity-100"
     class="fixed bottom-20 left-4 right-4 z-[90]">
    <div class="mx-auto max-w-md rounded-xl bg-slate-900 text-white px-4 py-3 text-sm shadow-lg border border-slate-700">
        <span x-text="toastState.msg"></span>
    </div>
</div>

<script>
function tallerApp() {
    return {
        idEmpresa: <?= (int)$id_empresa ?>,
        isMobileView: window.innerWidth < 768,
        permisos: window.__PERMISOS__ || {},
        isOfflineMode: typeof navigator !== 'undefined' ? !navigator.onLine : false,
        offlineOtQueue: [],
        tab: 'vehiculos',
        tabs: [
            { key: 'vehiculos', label: 'Vehículo', icon: 'fas fa-car' },
            { key: 'servicios', label: 'Servicio', icon: 'fas fa-tools' },
            { key: 'ordenes', label: 'OT', icon: 'fas fa-clipboard-list' },
        ],
        estadosOt: ['abierta', 'en_proceso', 'finalizada', 'entregada', 'cancelada'],
        prioridadesOt: ['baja', 'media', 'alta', 'urgente'],
        filtros: { search: '', estado: '' },
        ordenes: [],
        clientes: [],
        vehiculos: [],
        vehiculosOt: [],
        servicios: [],
        mecanicos: [],
        mecanicos: [],
        vehiculoClienteSearch: '',
        vehiculoClienteResults: [],
        vehiculoClienteSearchTimer: null,
        otClienteSearch: '',
        otClienteResults: [],
        otClienteSearchTimer: null,
        otVehiculoSearch: '',
        otVehiculoResults: [],
        otVehiculoSearchTimer: null,
        speechRecognition: null,
        speechTarget: '',
        vehiculoFotos: [],
        vehiculoCardSlides: {},
        showVehiculoCamera: false,
        vehiculoCameraStream: null,
        otChatPoller: null,
        otChatPoller: null,
        themeMode: (document.documentElement.classList.contains('dark') || document.documentElement.getAttribute('data-bs-theme') === 'dark' || localStorage.getItem('theme') === 'dark') ? 'dark' : 'light',
        gridSearch: '',
        gridReady: false,
        gridApiOrdenes: null,
        gridApiClientes: null,
        gridApiVehiculos: null,
        gridApiServicios: null,
        mobilePage: 1,
        mobilePageSize: 20,
        toastState: { show: false, msg: '' },
        toastTimer: null,
        showModal: false,
        modalType: '',
        modalTitle: '',
        clienteForm: { id_cliente: null, nombre: '', telefono: '', documento: '', direccion: '' },
        vehiculoForm: { id_vehiculo: null, id_cliente: '', marca: '', modelo: '', anio: '', chapa: '', chassis: '', color: '', km_actual: 0 },
        servicioForm: { id_servicio: null, servicio: '', descripcion: '', costo_base: 0, duracion_horas: 1, duracion_hhmm: '01:00' },
        otForm: { id_orden: null, fecha: '', hora_ingreso: '', id_cliente: '', id_vehiculo: '', id_mecanico: '', mecanico_nombre: '', problema: '', diagnostico: '', estado: 'abierta', prioridad: 'media', km_ingreso: 0, items: [], share_url: '', whatsapp_url: '', fecha_entrega_estimada: '', chat: [], chat_message: '' },

        async clearCache() {
            try {
                /* 1. Borrar caches del navegador (Service Worker / Cache API) */
                if ('caches' in window) {
                    const keys = await caches.keys();
                    await Promise.all(keys.map(k => caches.delete(k)));
                }
                /* 2. Desregistrar Service Workers */
                if ('serviceWorker' in navigator) {
                    const regs = await navigator.serviceWorker.getRegistrations();
                    await Promise.all(regs.map(r => r.unregister()));
                }
                /* 3. Limpiar sessionStorage (no localStorage para no perder tema/sesión) */
                sessionStorage.clear();
                /* 4. Hard reload sin caché */
                window.location.reload(true);
            } catch (e) {
                console.warn('clearCache error:', e);
                window.location.reload(true);
            }
        },

        async init() {
            await this.setupOfflineSupport();
            const tabFromUrl = new URLSearchParams(window.location.search).get('tab');
            const tabsValidas = ['vehiculos', 'servicios', 'ordenes'];
            if (tabFromUrl && tabsValidas.includes(tabFromUrl)) {
                this.tab = tabFromUrl;
            }
            this.attachGridEvents();
            this.attachThemeObserver();
            this.attachResizeHandler();
            /* Cargar datos y AG Grid en paralelo */
            const dataLoad = Promise.allSettled([this.loadCatalogos(), this.loadOrdenes()]);
            if (window.__agGridReady) await window.__agGridReady;
            this.ensureGrid(this.tab);
            await dataLoad;
        },
        async setupOfflineSupport() {
            await (window.SmxOfflineDb?.ready || Promise.resolve());
            this.isOfflineMode = typeof navigator !== 'undefined' ? !navigator.onLine : false;
            this.offlineOtQueue = this.readStorage('ot_queue', []);
            window.addEventListener('online', async () => {
                this.isOfflineMode = false;
                await this.syncOfflineOtQueue(true);
            });
            window.addEventListener('offline', () => {
                this.isOfflineMode = true;
                this.toast('Modo offline activo');
            });
            if (navigator.onLine && this.offlineOtQueue.length) {
                this.syncOfflineOtQueue(false);
            }
        },
        offlineKey(suffix) {
            return `sx_taller_offline:${this.idEmpresa}:${suffix}`;
        },
        readStorage(suffix, fallback = null) {
            return window.SmxOfflineDb?.getSync(this.offlineKey(suffix), fallback) ?? fallback;
        },
        writeStorage(suffix, value) {
            return window.SmxOfflineDb?.set(this.offlineKey(suffix), value);
        },
        createOfflineOtId() {
            return `ot-local-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
        },
        isServerOtId(value) {
            const id = Number(value || 0);
            return Number.isFinite(id) && id > 0;
        },
        buildOrdenesCacheKey() {
            return `ordenes:${String(this.filtros.search || '').trim().toLowerCase()}:${String(this.filtros.estado || '').trim().toLowerCase()}`;
        },
        requireOnline(actionLabel = 'Esta acción') {
            if (!navigator.onLine) {
                this.toast(`${actionLabel} requiere conexión`);
                return false;
            }
            return true;
        },
        buildOtPreview(orden = {}) {
            const idOrden = orden.id_orden || this.createOfflineOtId();
            const cliente = (this.clientes || []).find(c => Number(c.id_cliente || 0) === Number(orden.id_cliente || 0));
            const vehiculo = (this.vehiculos || []).find(v => Number(v.id_vehiculo || 0) === Number(orden.id_vehiculo || 0));
            const mecanico = (this.mecanicos || []).find(m => Number(m.id_login || 0) === Number(orden.id_mecanico || 0));
            return {
                id_orden: idOrden,
                nro_ot: String(orden.nro_ot || 'BORRADOR OFFLINE').trim(),
                fecha: String(orden.fecha || '').trim(),
                hora_ingreso: String(orden.hora_ingreso || '').trim(),
                estado: String(orden.estado || 'abierta'),
                prioridad: String(orden.prioridad || 'media'),
                problema: String(orden.problema || '').trim(),
                diagnostico: String(orden.diagnostico || '').trim(),
                km_ingreso: Number(orden.km_ingreso || 0),
                id_cliente: Number(orden.id_cliente || 0),
                id_vehiculo: Number(orden.id_vehiculo || 0),
                id_mecanico: Number(orden.id_mecanico || 0),
                cliente: cliente ? String(cliente.nombre || '') : '',
                chapa: vehiculo ? String(vehiculo.chapa || '') : '',
                marca: vehiculo ? String(vehiculo.marca || '') : '',
                modelo: vehiculo ? String(vehiculo.modelo || '') : '',
                mecanico: mecanico ? String(mecanico.nombre || mecanico.login || '') : '',
                total: (Array.isArray(orden.items) ? orden.items : []).reduce((sum, it) => sum + (Number(it.cantidad || 0) * Number(it.precio || 0)), 0),
                offline_pending: true,
                items: Array.isArray(orden.items) ? orden.items : [],
                chat: Array.isArray(orden.chat) ? orden.chat : []
            };
        },
        mergeOfflineOrdenes(items = []) {
            const map = new Map();
            (Array.isArray(this.offlineOtQueue) ? this.offlineOtQueue : []).forEach((entry) => {
                const preview = entry?.preview;
                if (!preview?.id_orden) return;
                map.set(String(preview.id_orden), preview);
            });
            const merged = [];
            (Array.isArray(items) ? items : []).forEach((item) => {
                const id = String(item?.id_orden ?? '');
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
        queueOfflineOt(entry) {
            let queue = [...(Array.isArray(this.offlineOtQueue) ? this.offlineOtQueue : [])];
            if (entry?.preview?.id_orden) {
                queue = queue.filter((item) => String(item?.preview?.id_orden || '') !== String(entry.preview.id_orden));
            }
            this.offlineOtQueue = [...queue, entry];
            this.writeStorage('ot_queue', this.offlineOtQueue);
        },
        async syncOfflineOtQueue(showToast = false) {
            if (!navigator.onLine || !this.offlineOtQueue.length) return;
            const pending = [...this.offlineOtQueue];
            const remaining = [];
            let synced = 0;
            for (const entry of pending) {
                try {
                    const res = await fetch('/public/taller/api/ordenes.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(entry.payload)
                    });
                    const data = await res.json();
                    if (data?.success) {
                        synced += 1;
                        continue;
                    }
                    remaining.push(entry);
                } catch (_) {
                    remaining.push(entry);
                    break;
                }
            }
            this.offlineOtQueue = remaining;
            this.writeStorage('ot_queue', remaining);
            if (synced > 0) {
                await this.loadOrdenes();
            }
            if (showToast) {
                this.toast(synced > 0 ? `Se sincronizaron ${synced} OT(s)` : 'No se pudo sincronizar todavía');
            }
        },
        setTab(key) {
            this.tab = key;
            this.mobilePage = 1;
            this.ensureGrid(key);
            this.$nextTick(() => {
                this.resizeActiveGrid();
                this.applyQuickFilter();
            });
        },
        gridThemeClass() {
            return this.themeMode === 'dark' ? 'ag-theme-quartz-dark' : 'ag-theme-quartz';
        },
        /** Detecta el modo oscuro desde las 3 fuentes posibles */
        _detectDark() {
            const el = document.documentElement;
            return el.classList.contains('dark')
                || String(el.getAttribute('data-bs-theme') || '').toLowerCase() === 'dark'
                || String(localStorage.getItem('theme') || '').toLowerCase() === 'dark';
        },
        syncGridThemeClasses() {
            const themeClass = this.themeMode === 'dark' ? 'ag-theme-quartz-dark' : 'ag-theme-quartz';
            const removeClass = this.themeMode === 'dark' ? 'ag-theme-quartz' : 'ag-theme-quartz-dark';
            const refs = [this.$refs.gridOrdenes, this.$refs.gridClientes, this.$refs.gridVehiculos, this.$refs.gridServicios];
            refs.forEach((el) => {
                if (!el) return;
                el.classList.remove(removeClass);
                if (!el.classList.contains(themeClass)) el.classList.add(themeClass);
            });
        },
        applyThemeToGrids() {
            this.syncGridThemeClasses();
            const apis = [this.gridApiOrdenes, this.gridApiClientes, this.gridApiVehiculos, this.gridApiServicios];
            apis.forEach((api) => {
                if (!api) return;
                try {
                    api.refreshHeader();
                    api.redrawRows();
                } catch (_) {}
            });
            this.$nextTick(() => this.resizeActiveGrid());
        },
        applyQuickFilter() {
            if (this.isMobileView) { this.mobilePage = 1; return; }
            const txt = String(this.gridSearch || '');
            const map = {
                ordenes: this.gridApiOrdenes,
                clientes: this.gridApiClientes,
                vehiculos: this.gridApiVehiculos,
                servicios: this.gridApiServicios
            };
            const api = map[this.tab];
            if (!api) return;
            this._resetInfiniteDatasource(this.tab);
        },
        _gridRows(tabKey) {
            if (tabKey === 'ordenes') return Array.isArray(this.ordenes) ? this.ordenes : [];
            if (tabKey === 'clientes') return Array.isArray(this.clientes) ? this.clientes : [];
            if (tabKey === 'vehiculos') return Array.isArray(this.vehiculos) ? this.vehiculos : [];
            if (tabKey === 'servicios') return Array.isArray(this.servicios) ? this.servicios : [];
            return [];
        },
        /* ── Mobile: filtrado y paginación de cards ── */
        _mobileFiltered(tabKey) {
            const rows = this._gridRows(tabKey);
            const s = String(this.gridSearch || '').trim().toLowerCase();
            if (!s) return rows;
            return rows.filter(r => Object.values(r||{}).some(v => String(v??'').toLowerCase().includes(s)));
        },
        _mobilePageCount(tabKey) {
            return Math.max(1, Math.ceil(this._mobileFiltered(tabKey).length / this.mobilePageSize));
        },
        _mobilePaginated(tabKey) {
            const f = this._mobileFiltered(tabKey);
            const pc = Math.max(1, Math.ceil(f.length / this.mobilePageSize));
            if (this.mobilePage > pc) this.mobilePage = pc;
            const start = (this.mobilePage - 1) * this.mobilePageSize;
            return f.slice(start, start + this.mobilePageSize);
        },
        _valuePassesCondition(rawValue, cond) {
            const type = String(cond?.type || '');
            const filterType = String(cond?.filterType || 'text');
            const value = rawValue == null ? '' : rawValue;
            if (filterType === 'set') {
                const vals = Array.isArray(cond?.values) ? cond.values.map(v => String(v)) : [];
                return vals.length === 0 ? true : vals.includes(String(value));
            }
            if (filterType === 'number') {
                const n = Number(value);
                const f = Number(cond?.filter);
                const to = Number(cond?.filterTo);
                if (type === 'blank') return String(value).trim() === '';
                if (type === 'notBlank') return String(value).trim() !== '';
                if (Number.isNaN(n) || Number.isNaN(f)) return false;
                if (type === 'equals') return n === f;
                if (type === 'notEqual') return n !== f;
                if (type === 'lessThan') return n < f;
                if (type === 'lessThanOrEqual') return n <= f;
                if (type === 'greaterThan') return n > f;
                if (type === 'greaterThanOrEqual') return n >= f;
                if (type === 'inRange') return !Number.isNaN(to) && n >= f && n <= to;
                return true;
            }
            const v = String(value).toLowerCase();
            const f = String(cond?.filter || '').toLowerCase();
            if (type === 'contains') return v.includes(f);
            if (type === 'notContains') return !v.includes(f);
            if (type === 'equals') return v === f;
            if (type === 'notEqual') return v !== f;
            if (type === 'startsWith') return v.startsWith(f);
            if (type === 'endsWith') return v.endsWith(f);
            if (type === 'blank') return v.trim() === '';
            if (type === 'notBlank') return v.trim() !== '';
            return true;
        },
        _rowPassesFilterModel(row, filterModel) {
            if (!filterModel || typeof filterModel !== 'object') return true;
            const entries = Object.entries(filterModel);
            for (const [field, model] of entries) {
                const value = row ? row[field] : '';
                if (model && Array.isArray(model.conditions) && model.conditions.length) {
                    const op = String(model.operator || 'AND').toUpperCase();
                    const checks = model.conditions.map(c => this._valuePassesCondition(value, c));
                    const ok = op === 'OR' ? checks.some(Boolean) : checks.every(Boolean);
                    if (!ok) return false;
                    continue;
                }
                if (!this._valuePassesCondition(value, model)) return false;
            }
            return true;
        },
        _sortRows(rows, sortModel) {
            if (!Array.isArray(sortModel) || sortModel.length === 0) return rows;
            const out = [...rows];
            out.sort((a, b) => {
                for (const s of sortModel) {
                    const colId = s?.colId;
                    const dir = String(s?.sort || 'asc').toLowerCase() === 'desc' ? -1 : 1;
                    const av = a?.[colId];
                    const bv = b?.[colId];
                    const an = Number(av);
                    const bn = Number(bv);
                    let cmp = 0;
                    if (!Number.isNaN(an) && !Number.isNaN(bn) && String(av).trim() !== '' && String(bv).trim() !== '') {
                        cmp = an === bn ? 0 : (an > bn ? 1 : -1);
                    } else {
                        cmp = String(av ?? '').localeCompare(String(bv ?? ''), 'es', { sensitivity: 'base', numeric: true });
                    }
                    if (cmp !== 0) return cmp * dir;
                }
                return 0;
            });
            return out;
        },
        _resetInfiniteDatasource(tabKey) {
            const apiMap = {
                ordenes: this.gridApiOrdenes,
                clientes: this.gridApiClientes,
                vehiculos: this.gridApiVehiculos,
                servicios: this.gridApiServicios
            };
            const api = apiMap[tabKey];
            if (!api) return;
            if (this.isMobileView) return;
            const self = this;
            const dataSource = {
                rowCount: undefined,
                getRows(params) {
                    const baseRows = self._gridRows(tabKey);
                    const search = self.tab === tabKey ? String(self.gridSearch || '').trim().toLowerCase() : '';
                    const filtered = baseRows.filter((row) => {
                        if (search) {
                            const haystack = Object.values(row || {}).map(v => String(v ?? '').toLowerCase()).join(' ');
                            if (!haystack.includes(search)) return false;
                        }
                        return self._rowPassesFilterModel(row, params.filterModel);
                    });
                    const sorted = self._sortRows(filtered, params.sortModel);
                    const start = Math.max(0, Number(params.startRow || 0));
                    const end = Math.max(start, Number(params.endRow || start + 100));
                    const rowsThisBlock = sorted.slice(start, end);
                    const lastRow = end >= sorted.length ? sorted.length : -1;
                    params.successCallback(rowsThisBlock, lastRow);
                }
            };
            api.setGridOption('datasource', dataSource);
        },
        attachResizeHandler() {
            if (this._gridResizeBound) return;
            this._gridResizeBound = true;
            let t = null;
            window.addEventListener('resize', () => {
                clearTimeout(t);
                t = setTimeout(() => this.resizeActiveGrid(), 120);
            });
        },
        attachThemeObserver() {
            if (this._themeObserverBound) return;
            this._themeObserverBound = true;
            const self = this;
            const target = document.documentElement;

            const applyTheme = () => {
                const isDark = self._detectDark();
                const newMode = isDark ? 'dark' : 'light';
                if (self.themeMode !== newMode) {
                    self.themeMode = newMode;
                    self.applyThemeToGrids();
                }
            };

            /* MutationObserver: cubre html.dark y data-bs-theme */
            this._themeObserver = new MutationObserver(() => applyTheme());
            this._themeObserver.observe(target, {
                attributes: true,
                attributeFilter: ['class', 'data-bs-theme', 'data-theme']
            });

            /* storage listener: cubre localStorage.theme desde otra pestaña */
            window.addEventListener('storage', (e) => {
                if (e.key === 'theme') applyTheme();
            });

            /* Polling ligero: cubre cambios de localStorage en la misma pestaña
               (storage event no dispara en la pestaña que escribe)             */
            let lastStorage = localStorage.getItem('theme');
            this._themeStorageInterval = setInterval(() => {
                const cur = localStorage.getItem('theme');
                if (cur !== lastStorage) {
                    lastStorage = cur;
                    applyTheme();
                }
            }, 500);

            /* Aplicar inmediatamente al iniciar */
            applyTheme();
        },
        refreshGridData(gridName, rows) {
            const map = {
                ordenes: this.gridApiOrdenes,
                clientes: this.gridApiClientes,
                vehiculos: this.gridApiVehiculos,
                servicios: this.gridApiServicios
            };
            const api = map[gridName];
            if (!api) return;
            if (this.isMobileView) {
                api.setGridOption('rowData', Array.isArray(rows) ? rows : []);
                return;
            }
            this._resetInfiniteDatasource(gridName);
        },
        resizeActiveGrid() {
            const apiMap = {
                ordenes: this.gridApiOrdenes,
                clientes: this.gridApiClientes,
                vehiculos: this.gridApiVehiculos,
                servicios: this.gridApiServicios
            };
            const api = apiMap[this.tab];
            if (api) api.sizeColumnsToFit();
        },
        _gridCommon() {
            const mobile = this.isMobileView;
            const self = this;
            const base = {
                animateRows: true,
                enableCellTextSelection: true,
                ensureDomOrder: true,
                suppressColumnVirtualisation: mobile,
                columnMenu: 'new',
                localeText: window.SmxAgGridLocale?.getLocaleText?.() || {},
                defaultColDef: {
                    sortable: true,
                    resizable: !mobile,
                    filter: true,
                    flex: 1,
                    menuTabs: mobile ? [] : ['generalMenuTab', 'filterMenuTab', 'columnsMenuTab'],
                },
                onRowClicked(params) {
                    /* Ignorar clicks en elementos interactivos dentro de la celda */
                    const target = params.event?.target;
                    if (target && target.closest('a, select, button, input, .js-grid-estado')) return;
                    const data = params.data;
                    if (!data) return;
                    const tab = self.tab;
                    if (tab === 'ordenes') {
                        self.editOt(Number(data.id_orden));
                    } else if (tab === 'clientes') {
                        const c = (self.clientes||[]).find(x => Number(x.id_cliente) === Number(data.id_cliente));
                        if (c) self.openClienteForm(c);
                    } else if (tab === 'vehiculos') {
                        const v = (self.vehiculos||[]).find(x => Number(x.id_vehiculo) === Number(data.id_vehiculo));
                        if (v) self.openVehiculoForm(v);
                    } else if (tab === 'servicios') {
                        const s = (self.servicios||[]).find(x => Number(x.id_servicio) === Number(data.id_servicio));
                        if (s) self.openServicioForm(s);
                    }
                },
                getContextMenuItems(params) {
                    const tab = self.tab;
                    const data = params.node?.data;
                    if (!data) return params.defaultItems || [];
                    const canDelete = self.permisos?.priv_delete === 'Y';
                    const items = [];
                    /* ---- Editar ---- */
                    if (tab === 'ordenes') {
                        items.push({ name: 'Editar orden', icon: '<i class="fas fa-pen text-orange-600"></i>', action: () => self.editOt(Number(data.id_orden)) });
                    } else if (tab === 'clientes') {
                        items.push({ name: 'Editar cliente', icon: '<i class="fas fa-pen text-orange-600"></i>', action: () => { const c = (self.clientes||[]).find(x=>Number(x.id_cliente)===Number(data.id_cliente)); if(c) self.openClienteForm(c); } });
                    } else if (tab === 'vehiculos') {
                        items.push({ name: 'Editar vehículo', icon: '<i class="fas fa-pen text-orange-600"></i>', action: () => { const v = (self.vehiculos||[]).find(x=>Number(x.id_vehiculo)===Number(data.id_vehiculo)); if(v) self.openVehiculoForm(v); } });
                    } else if (tab === 'servicios') {
                        items.push({ name: 'Editar servicio', icon: '<i class="fas fa-pen text-orange-600"></i>', action: () => { const s = (self.servicios||[]).find(x=>Number(x.id_servicio)===Number(data.id_servicio)); if(s) self.openServicioForm(s); } });
                    }
                    /* ---- Eliminar ---- */
                    if (canDelete) {
                        items.push('separator');
                        if (tab === 'ordenes') {
                            items.push({ name: 'Eliminar orden', icon: '<i class="fas fa-trash text-red-600"></i>', cssClasses: ['text-red-600'], action: () => self.deleteOt(Number(data.id_orden)) });
                        } else if (tab === 'clientes') {
                            items.push({ name: 'Eliminar cliente', icon: '<i class="fas fa-trash text-red-600"></i>', cssClasses: ['text-red-600'], action: () => self.deleteCliente(Number(data.id_cliente)) });
                        } else if (tab === 'vehiculos') {
                            items.push({ name: 'Eliminar vehículo', icon: '<i class="fas fa-trash text-red-600"></i>', cssClasses: ['text-red-600'], action: () => self.deleteVehiculo(Number(data.id_vehiculo)) });
                        } else if (tab === 'servicios') {
                            items.push({ name: 'Eliminar servicio', icon: '<i class="fas fa-trash text-red-600"></i>', cssClasses: ['text-red-600'], action: () => self.deleteServicio(Number(data.id_servicio)) });
                        }
                    }
                    items.push('separator');
                    return [...items, ...(params.defaultItems || [])];
                }
            };
            /* Desktop only: AG Grid config */
            base.rowModelType = 'infinite';
            base.cacheBlockSize = 80;
            base.maxBlocksInCache = 6;
            base.maxConcurrentDatasourceRequests = 2;
            base.blockLoadDebounceMillis = 120;
            base.infiniteInitialRowCount = 1;
            base.rowBuffer = 8;
            base.pagination = false;
            base.sideBar = {
                toolPanels: [
                    { id: 'columns', labelDefault: 'Columnas', labelKey: 'columns', iconKey: 'columns', toolPanel: 'agColumnsToolPanel' },
                    { id: 'filters', labelDefault: 'Filtros', labelKey: 'filters', iconKey: 'filter', toolPanel: 'agFiltersToolPanel' }
                ],
                defaultToolPanel: ''
            };
            return base;
        },
        _applyLicense() {
            if (this._licenseApplied) return;
            if (typeof agGrid !== 'undefined' && agGrid.LicenseManager) {
                agGrid.LicenseManager.setLicenseKey('DownloadDevTools_COM_NDEwMjM0NTgwMDAwMA==59158b5225400879a12a96634544f5b6');
                this._licenseApplied = true;
            }
        },
        ensureGrid(tabKey) {
            if (!window.agGrid) return;   /* Mobile: no AG Grid */
            this._applyLicense();
            const common = this._gridCommon();
            const dataMap = {
                ordenes: () => this.ordenes,
                clientes: () => this.clientes,
                vehiculos: () => this.vehiculos,
                servicios: () => this.servicios
            };
            const apiMap = {
                ordenes: 'gridApiOrdenes',
                clientes: 'gridApiClientes',
                vehiculos: 'gridApiVehiculos',
                servicios: 'gridApiServicios'
            };
            /* Si ya fue creado, solo refresh */
            if (this[apiMap[tabKey]]) {
                this.refreshGridData(tabKey, dataMap[tabKey]());
                return;
            }
            const colDefs = this._columnDefs(tabKey);
            if (!colDefs) return;
            const refMap = {
                ordenes: this.$refs.gridOrdenes,
                clientes: this.$refs.gridClientes,
                vehiculos: this.$refs.gridVehiculos,
                servicios: this.$refs.gridServicios
            };
            const gridOpts = {
                ...common,
                onGridReady: () => this.$nextTick(() => {
                    this.syncGridThemeClasses();
                    this.refreshGridData(tabKey, dataMap[tabKey]());
                    this.resizeActiveGrid();
                }),
                columnDefs: colDefs
            };
            const gridEl = refMap[tabKey];
            this[apiMap[tabKey]] = agGrid.createGrid(gridEl, gridOpts);
        },
        _columnDefs(tabKey) {

            if (tabKey === 'ordenes') {
                const cols = [
                    {
                        headerName: 'Foto',
                        field: 'foto_url',
                        minWidth: 84,
                        maxWidth: 92,
                        sortable: false,
                        filter: false,
                        cellRenderer: p => this.renderVehiculoThumbCell(String(p.data?.foto_url || '').trim())
                    },
                    { field: 'nro_ot', headerName: 'OT', minWidth: 70, maxWidth: 85 },
                    { field: 'cliente', headerName: 'Cliente', minWidth: 120, flex: 1 },
                    { field: 'fecha', headerName: 'Fecha', minWidth: 100 },
                    { headerName: 'Vehículo', minWidth: 200, valueGetter: p => `${p.data?.marca || ''} ${p.data?.modelo || ''} [${p.data?.chapa || '-'}]` },
                    {
                        field: 'estado', headerName: 'Estado', minWidth: 160, cellRenderer: p => {
                            const id = Number(p.data?.id_orden || 0);
                            const estado = String(p.data?.estado || '');
                            const opts = (this.estadosOt || []).map(e => `<option value="${e}" ${e===estado?'selected':''}>${e}</option>`).join('');
                            return `<select class="js-grid-estado px-2 py-1 border rounded text-xs" data-grid="ordenes" data-id="${id}">${opts}</select>`;
                        }
                    },
                    { field: 'total', headerName: 'Total', minWidth: 100, valueFormatter: p => this.formatMoney(p.value), cellStyle: { textAlign: 'right', fontWeight: '600' } }
                ];
                return cols;
            }
            if (tabKey === 'clientes') {
                const cols = [
                    {
                        headerName: 'Foto',
                        field: 'vehiculo_thumb',
                        minWidth: 84,
                        maxWidth: 92,
                        sortable: false,
                        filter: false,
                        cellRenderer: p => this.renderVehiculoThumbCell(this.clienteThumbUrl(p.data))
                    },
                    { field: 'nombre', headerName: 'Nombre', minWidth: 160, flex: 1 },
                    { field: 'telefono', headerName: 'Teléfono', minWidth: 120 },
                    { field: 'documento', headerName: 'Documento', minWidth: 120 },
                    { field: 'direccion', headerName: 'Dirección', minWidth: 180, flex: 1 },
                    { field: 'saldo', headerName: 'Saldo', minWidth: 120, valueFormatter: p => this.formatMoney(p.value), cellStyle: p => ({ textAlign: 'right', fontWeight: '700', color: Number(p.value || 0) >= 0 ? '#16a34a' : '#dc2626' }) }
                ];
                return cols;
            }
            if (tabKey === 'vehiculos') {
                const cols = [
                    {
                        headerName: 'Foto',
                        field: 'foto_url',
                        minWidth: 84,
                        maxWidth: 92,
                        sortable: false,
                        filter: false,
                        cellRenderer: p => this.renderVehiculoThumbCell(this.vehiculoThumbUrl(p.data))
                    },
                    { headerName: 'Vehículo', minWidth: 160, flex: 1, valueGetter: p => `${p.data?.marca || ''} ${p.data?.modelo || ''}` },
                    { field: 'chapa', headerName: 'Chapa', minWidth: 100 },
                    { field: 'cliente', headerName: 'Cliente', minWidth: 160 },
                    { field: 'anio', headerName: 'Año', minWidth: 80 },
                    { field: 'chassis', headerName: 'Chassis', minWidth: 140 },
                    { field: 'color', headerName: 'Color', minWidth: 100 },
                    { field: 'km_actual', headerName: 'KM', minWidth: 90, cellStyle: { textAlign: 'right' } }
                ];
                return cols;
            }
            if (tabKey === 'servicios') {
                const cols = [
                    { field: 'servicio', headerName: 'Servicio', minWidth: 160, flex: 1 },
                    { field: 'costo_base', headerName: 'Costo', minWidth: 100, valueFormatter: p => this.formatMoney(p.value), cellStyle: { textAlign: 'right' } },
                    { field: 'descripcion', headerName: 'Descripción', minWidth: 200, flex: 1 },
                    { field: 'duracion_horas', headerName: 'Duración', minWidth: 96, valueFormatter: p => this.formatHoursHHMM(p.value), cellStyle: { textAlign: 'right' } }
                ];
                return cols;
            }
            return null;
        },
        attachGridEvents() {
            if (this._gridEventsBound) return;
            this._gridEventsBound = true;
            /* Listener para select de estado en ordenes (inline en la celda) */
            document.addEventListener('change', (ev) => {
                const sel = ev.target && ev.target.classList && ev.target.classList.contains('js-grid-estado') ? ev.target : null;
                if (!sel) return;
                const grid = sel.dataset.grid || '';
                const id = Number(sel.dataset.id || 0);
                if (grid !== 'ordenes' || id <= 0) return;
                const row = (this.ordenes || []).find(x => Number(x.id_orden) === id);
                if (row) this.setEstado(row, String(sel.value || ''));
            });
        },
        closeModal() {
            this.stopOtChatPolling();
            this.showModal = false;
            this.vehiculoClienteResults = [];
            this.closeVehiculoCamera();
        },
        renderVehiculoThumbCell(url) {
            const safeUrl = String(url || '').trim();
            if (!safeUrl) {
                return '<div class="w-12 h-12 rounded-xl border border-slate-700/80 bg-slate-800/90 flex items-center justify-center text-slate-500"><i class="fas fa-car-side text-sm"></i></div>';
            }
            const escaped = safeUrl.replace(/"/g, '&quot;');
            return `<div class="w-12 h-12 rounded-xl overflow-hidden border border-slate-700/80 bg-slate-900/80"><img src="${escaped}" alt="Vehiculo" class="w-full h-full object-cover" loading="lazy" referrerpolicy="no-referrer"></div>`;
        },
        vehiculoThumbUrl(v) {
            if (!v || typeof v !== 'object') return '';
            const direct = String(v.foto_url || '').trim();
            if (direct) return direct;
            const arr = Array.isArray(v.fotos_urls) ? v.fotos_urls : [];
            return String(arr[0] || '').trim();
        },
        clienteThumbUrl(c) {
            const idCliente = Number(c && c.id_cliente ? c.id_cliente : 0);
            if (idCliente <= 0) return '';
            const vehiculo = (this.vehiculos || []).find(v => Number(v.id_cliente || 0) === idCliente && this.vehiculoThumbUrl(v));
            return vehiculo ? this.vehiculoThumbUrl(vehiculo) : '';
        },
        formatMoney(v) { return '₲ ' + new Intl.NumberFormat('es-PY', { maximumFractionDigits: 0 }).format(Number(v || 0)); },
        formatHoursHHMM(value) {
            const totalMinutes = Math.max(0, Math.round(Number(value || 0) * 60));
            const hours = Math.floor(totalMinutes / 60);
            const minutes = totalMinutes % 60;
            return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
        },
        formatDateTimeLocal(value) {
            if (!value) return '-';
            const date = value instanceof Date ? value : new Date(value);
            if (Number.isNaN(date.getTime())) return '-';
            return date.toLocaleString('es-PY', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        },
        formatSqlDateTimeLocal(value) {
            if (!value) return '';
            const date = value instanceof Date ? value : new Date(value);
            if (Number.isNaN(date.getTime())) return '';
            const yyyy = date.getFullYear();
            const mm = String(date.getMonth() + 1).padStart(2, '0');
            const dd = String(date.getDate()).padStart(2, '0');
            const hh = String(date.getHours()).padStart(2, '0');
            const mi = String(date.getMinutes()).padStart(2, '0');
            const ss = String(date.getSeconds()).padStart(2, '0');
            return `${yyyy}-${mm}-${dd} ${hh}:${mi}:${ss}`;
        },
        parseHoursHHMM(value) {
            const raw = String(value || '').trim();
            if (!raw) return 0;
            const match = raw.match(/^(\d{1,3})(?::(\d{1,2}))?$/);
            if (match) {
                const hours = Number(match[1] || 0);
                const minutes = Math.min(59, Number(match[2] || 0));
                return hours + (minutes / 60);
            }
            return Number(raw.replace(',', '.')) || 0;
        },
        normalizeHoursHHMM(value) {
            return this.formatHoursHHMM(this.parseHoursHHMM(value));
        },
        otEstimatedHours() {
            return (this.otForm.items || []).reduce((sum, it) => {
                if (String(it.tipo || 'servicio') !== 'servicio') return sum;
                const servicio = (this.servicios || []).find(s => Number(s.id_servicio || 0) === Number(it.id_servicio || 0));
                if (!servicio) return sum;
                return sum + (Number(servicio.duracion_horas || 0) * Math.max(1, Number(it.cantidad || 1)));
            }, 0);
        },
        otEstimatedEndDateTime() {
            const fecha = String(this.otForm.fecha || '').trim();
            if (!fecha) return null;
            const hora = String(this.otForm.hora_ingreso || '08:00').trim() || '08:00';
            const base = new Date(`${fecha}T${hora}:00`);
            if (Number.isNaN(base.getTime())) return null;
            const minutes = Math.round(this.otEstimatedHours() * 60);
            return new Date(base.getTime() + (minutes * 60000));
        },
        phoneDigits(value) {
            return String(value || '').replace(/[^0-9]/g, '');
        },
        formatVehiculoOption(v) {
            if (!v || typeof v !== 'object') return '';
            const chapa = String(v.chapa || '-').trim();
            const marca = String(v.marca || '').trim();
            const modelo = String(v.modelo || '').trim();
            return `${chapa} - ${marca} ${modelo}`.trim();
        },
        toggleSpeechInput(target) {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SpeechRecognition) {
                alert('El navegador no soporta dictado por voz.');
                return;
            }
            if (this.speechRecognition && this.speechTarget === target) {
                this.speechRecognition.stop();
                return;
            }
            if (this.speechRecognition) {
                this.speechRecognition.stop();
            }
            const recognition = new SpeechRecognition();
            recognition.lang = 'es-PY';
            recognition.interimResults = false;
            recognition.maxAlternatives = 1;
            this.speechRecognition = recognition;
            this.speechTarget = target;
            recognition.onresult = (event) => {
                const transcript = String(event.results?.[0]?.[0]?.transcript || '').trim();
                if (!transcript) return;
                if (target === 'otClienteSearch') {
                    this.otClienteSearch = transcript;
                    this.buscarClientesOt();
                    return;
                }
                if (target === 'otVehiculoSearch') {
                    this.otVehiculoSearch = transcript;
                    this.buscarVehiculosOt();
                    return;
                }
                if (target === 'otForm.problema') {
                    this.otForm.problema = [this.otForm.problema, transcript].filter(Boolean).join(' ').trim();
                    return;
                }
                if (target === 'otForm.diagnostico') {
                    this.otForm.diagnostico = [this.otForm.diagnostico, transcript].filter(Boolean).join(' ').trim();
                    return;
                }
                if (target.startsWith('otItemDescripcion:')) {
                    const idx = Number(target.split(':')[1] || -1);
                    if (idx >= 0 && this.otForm.items[idx]) {
                        this.otForm.items[idx].descripcion = [this.otForm.items[idx].descripcion, transcript].filter(Boolean).join(' ').trim();
                    }
                }
            };
            recognition.onend = () => {
                this.speechRecognition = null;
                this.speechTarget = '';
            };
            recognition.onerror = () => {
                this.speechRecognition = null;
                this.speechTarget = '';
            };
            recognition.start();
        },
        async call(url, opts = {}) {
            const res = await fetch(url, opts);
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Error');
            return data;
        },
        async loadCatalogos() {
            try {
                const data = await this.call(`/public/taller/api/catalogos.php?id_empresa=${this.idEmpresa}`);
                this.clientes = data.data.clientes || [];
                this.vehiculos = data.data.vehiculos || [];
                this.vehiculoCardSlides = {};
                this.servicios = data.data.servicios || [];
                this.mecanicos = data.data.mecanicos || [];
                this.estadosOt = data.data.estados_ot || this.estadosOt;
                this.prioridadesOt = data.data.prioridades || this.prioridadesOt;
                await this.writeStorage('catalogos', data);
                this.refreshGridData('clientes', this.clientes);
                this.refreshGridData('vehiculos', this.vehiculos);
                this.refreshGridData('servicios', this.servicios);
            } catch (e) {
                const cached = this.readStorage('catalogos');
                if (cached?.success && cached?.data) {
                    this.clientes = cached.data.clientes || [];
                    this.vehiculos = cached.data.vehiculos || [];
                    this.vehiculoCardSlides = {};
                    this.servicios = cached.data.servicios || [];
                    this.mecanicos = cached.data.mecanicos || [];
                    this.estadosOt = cached.data.estados_ot || this.estadosOt;
                    this.prioridadesOt = cached.data.prioridades || this.prioridadesOt;
                    this.refreshGridData('clientes', this.clientes);
                    this.refreshGridData('vehiculos', this.vehiculos);
                    this.refreshGridData('servicios', this.servicios);
                    this.toast('Catálogos desde cache offline');
                    return;
                }
                throw e;
            }
        },
        async loadOrdenes() {
            const q = new URLSearchParams({
                id_empresa: String(this.idEmpresa),
                search: this.filtros.search || '',
                estado: this.filtros.estado || ''
            });
            const cacheKey = this.buildOrdenesCacheKey();
            try {
                const data = await this.call(`/public/taller/api/ordenes.php?${q.toString()}`);
                this.ordenes = this.mergeOfflineOrdenes(data.data || []);
                await this.writeStorage(cacheKey, data);
                await Promise.all((data.data || []).map((orden) => this.writeStorage(`orden:${orden.id_orden}`, { success: true, data: orden })));
                this.refreshGridData('ordenes', this.ordenes);
            } catch (e) {
                const cached = this.readStorage(cacheKey);
                if (cached?.success) {
                    this.ordenes = this.mergeOfflineOrdenes(cached.data || []);
                    this.refreshGridData('ordenes', this.ordenes);
                    this.toast('Órdenes desde cache offline');
                    return;
                }
                throw e;
            }
        },
        async setEstado(o, estado) {
            if (!this.requireOnline('Cambiar estado')) return;
            await this.call('/public/taller/api/ordenes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'set_estado', id_orden: o.id_orden, estado, id_empresa: this.idEmpresa })
            });
            await this.loadOrdenes();
        },
        openClienteForm(c = null) {
            this.modalType = 'cliente';
            this.modalTitle = c ? 'Editar Cliente' : 'Nuevo Cliente';
            this.clienteForm = c ? { ...c } : { id_cliente: null, nombre: '', telefono: '', documento: '', direccion: '' };
            this.showModal = true;
        },
        async saveCliente() {
            const action = this.clienteForm.id_cliente ? 'update' : 'create';
            await this.call('/public/taller/api/clientes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, id_empresa: this.idEmpresa, cliente: this.clienteForm })
            });
            await this.loadCatalogos();
            this.closeModal();
        },
        async deleteCliente(id) {
            if (!confirm('Suprimir cliente?')) return;
            await this.call('/public/taller/api/clientes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id_empresa: this.idEmpresa, id_cliente: id })
            });
            await this.loadCatalogos();
        },
        openVehiculoForm(v = null) {
            this.modalType = 'vehiculo';
            this.modalTitle = v ? 'Editar Vehiculo' : 'Nuevo Vehiculo';
            this.vehiculoForm = v
                ? { ...v, chassis: v.chassis || v.vin || '', color: v.color || '' }
                : { id_vehiculo: null, id_cliente: '', marca: '', modelo: '', anio: '', chapa: '', chassis: '', color: '', km_actual: 0 };
            this.vehiculoClienteSearch = v ? (v.cliente || '') : '';
            this.vehiculoClienteResults = [];
            this.vehiculoFotos = [];
            if (v && v.id_vehiculo) this.loadVehiculoFotos(v.id_vehiculo);
            this.showModal = true;
        },
        async saveVehiculo() {
            const action = this.vehiculoForm.id_vehiculo ? 'update' : 'create';
            const payload = await this.call('/public/taller/api/vehiculos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, id_empresa: this.idEmpresa, vehiculo: this.vehiculoForm })
            });
            if (!this.vehiculoForm.id_vehiculo && payload && payload.id) {
                this.vehiculoForm.id_vehiculo = Number(payload.id);
            }
            await this.loadCatalogos();
            if (this.vehiculoForm.id_vehiculo) {
                await this.loadVehiculoFotos(this.vehiculoForm.id_vehiculo);
            }
        },
        async deleteVehiculo(id) {
            if (!confirm('Suprimir vehiculo?')) return;
            await this.call('/public/taller/api/vehiculos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id_empresa: this.idEmpresa, id_vehiculo: id })
            });
            await this.loadCatalogos();
        },
        editVehiculoFromOt() {
            const id = Number(this.otForm.id_vehiculo || 0);
            if (id <= 0) return;
            const v = (this.vehiculos || []).find(x => Number(x.id_vehiculo) === id);
            if (!v) {
                alert('No se encontró el vehículo seleccionado.');
                return;
            }
            this.openVehiculoForm(v);
        },
        buscarClientesVehiculo() {
            clearTimeout(this.vehiculoClienteSearchTimer);
            this.vehiculoClienteSearchTimer = setTimeout(async () => {
                const q = String(this.vehiculoClienteSearch || '').trim();
                if (q.length < 2) {
                    this.vehiculoClienteResults = [];
                    return;
                }
                try {
                    const res = await fetch(`/public/pos/api/clientes.php?action=search&id_empresa=${this.idEmpresa}&q=${encodeURIComponent(q)}`);
                    const data = await res.json();
                    const fromPos = (data.clientes || []).map(c => ({
                        id: Number(c.id || 0),
                        nombre: c.nombre || '',
                        ruc: c.ruc || '',
                        telefono: c.telefono || '',
                    }));
                    if (fromPos.length > 0) {
                        this.vehiculoClienteResults = fromPos;
                        return;
                    }
                } catch (e) {
                    // fallback local
                }
                const ql = q.toLowerCase();
                this.vehiculoClienteResults = (this.clientes || [])
                    .filter(c => {
                        const t = `${c.nombre || ''} ${c.documento || ''} ${c.telefono || ''}`.toLowerCase();
                        return t.includes(ql);
                    })
                    .slice(0, 15)
                    .map(c => ({
                        id: Number(c.id_cliente || c.id || 0),
                        nombre: c.nombre || '',
                        ruc: c.documento || '',
                        telefono: c.telefono || '',
                    }));
            }, 220);
        },
        selectClienteVehiculo(cli) {
            this.vehiculoForm.id_cliente = Number(cli.id || 0);
            this.vehiculoClienteSearch = cli.nombre || '';
            this.vehiculoClienteResults = [];
        },
        async loadVehiculoFotos(idVehiculo) {
            const id = Number(idVehiculo || 0);
            if (id <= 0) {
                this.vehiculoFotos = [];
                return;
            }
            try {
                const res = await fetch(`/public/taller/api/vehiculos.php?id_empresa=${this.idEmpresa}&action=fotos&id_vehiculo=${id}`);
                const data = await res.json();
                this.vehiculoFotos = data.success ? (data.data || []) : [];
            } catch (e) {
                this.vehiculoFotos = [];
            }
        },
        fileToDataUrl(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(String(reader.result || ''));
                reader.onerror = reject;
                reader.readAsDataURL(file);
            });
        },
        async addVehiculoFotoFromDataUrl(dataUrl) {
            const idVehiculo = Number(this.vehiculoForm.id_vehiculo || 0);
            if (idVehiculo <= 0) {
                alert('Primero guardá el vehículo.');
                return false;
            }
            await this.call('/public/taller/api/vehiculos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'add_foto',
                    id_empresa: this.idEmpresa,
                    id_vehiculo: idVehiculo,
                    image_base64: dataUrl
                })
            });
            await this.loadVehiculoFotos(idVehiculo);
            return true;
        },
        async onVehiculoFileSelected(ev) {
            const file = ev && ev.target && ev.target.files ? ev.target.files[0] : null;
            if (!file) return;
            const dataUrl = await this.fileToDataUrl(file);
            await this.addVehiculoFotoFromDataUrl(dataUrl);
            ev.target.value = '';
        },
        vehiculoGoogleSearchQuery() {
            return [
                this.vehiculoForm.marca,
                this.vehiculoForm.modelo,
                this.vehiculoForm.color,
                this.vehiculoForm.anio,
                'vehiculo'
            ]
                .map(x => String(x || '').trim())
                .filter(Boolean)
                .join(' ');
        },
        openVehiculoGoogleImages() {
            const query = this.vehiculoGoogleSearchQuery() || 'vehiculo';
            window.open(`https://www.google.com/search?tbm=isch&q=${encodeURIComponent(query)}`, '_blank', 'noopener');
        },
        async pasteVehiculoClipboardImage() {
            if (!navigator.clipboard || !navigator.clipboard.read) {
                alert('Este navegador no permite pegar imagen directa. Usá Ctrl+V en el área de pegado.');
                return;
            }
            try {
                const items = await navigator.clipboard.read();
                for (const item of items) {
                    const imageType = item.types.find((t) => t.startsWith('image/'));
                    if (!imageType) continue;
                    const blob = await item.getType(imageType);
                    const file = new File([blob], `vehiculo_${Date.now()}.png`, { type: imageType || 'image/png' });
                    const dataUrl = await this.fileToDataUrl(file);
                    await this.addVehiculoFotoFromDataUrl(dataUrl);
                    return;
                }
                alert('No hay imagen en el portapapeles.');
            } catch (e) {
                alert('No se pudo leer la imagen del portapapeles.');
            }
        },
        async onVehiculoImagePaste(event) {
            const items = Array.from(event?.clipboardData?.items || []);
            if (!items.length) return;
            for (const item of items) {
                if (item.kind === 'file' && String(item.type || '').startsWith('image/')) {
                    const file = item.getAsFile();
                    if (!file) continue;
                    const dataUrl = await this.fileToDataUrl(file);
                    await this.addVehiculoFotoFromDataUrl(dataUrl);
                    return;
                }
            }
            alert('No se detectó una imagen en el pegado.');
        },
        async deleteVehiculoFoto(idFoto) {
            if (!confirm('Eliminar foto?')) return;
            await this.call('/public/taller/api/vehiculos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'delete_foto',
                    id_empresa: this.idEmpresa,
                    id_foto: Number(idFoto || 0)
                })
            });
            await this.loadVehiculoFotos(this.vehiculoForm.id_vehiculo);
        },
        async openVehiculoCamera() {
            const idVehiculo = Number(this.vehiculoForm.id_vehiculo || 0);
            if (idVehiculo <= 0) {
                alert('Primero guardá el vehículo.');
                return;
            }
            try {
                this.vehiculoCameraStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } },
                    audio: false
                });
                this.showVehiculoCamera = true;
                this.$nextTick(() => {
                    if (this.$refs.vehiculoCameraVideo) {
                        this.$refs.vehiculoCameraVideo.srcObject = this.vehiculoCameraStream;
                        this.$refs.vehiculoCameraVideo.play().catch(() => {});
                    }
                });
            } catch (e) {
                alert('No se pudo acceder a la cámara.');
            }
        },
        closeVehiculoCamera() {
            this.showVehiculoCamera = false;
            if (this.vehiculoCameraStream) {
                this.vehiculoCameraStream.getTracks().forEach(t => t.stop());
            }
            this.vehiculoCameraStream = null;
        },
        async captureVehiculoPhoto() {
            const video = this.$refs.vehiculoCameraVideo;
            if (!video || !video.videoWidth || !video.videoHeight) return;
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            const dataUrl = canvas.toDataURL('image/jpeg', 0.88);
            await this.call('/public/taller/api/vehiculos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'add_foto',
                    id_empresa: this.idEmpresa,
                    id_vehiculo: Number(this.vehiculoForm.id_vehiculo || 0),
                    image_base64: dataUrl
                })
            });
            await this.loadVehiculoFotos(this.vehiculoForm.id_vehiculo);
            this.closeVehiculoCamera();
        },
        vehiculoCardFotoCount(v) {
            const arr = Array.isArray(v && v.fotos_urls) ? v.fotos_urls : [];
            return arr.length;
        },
        vehiculoCardFotoIdx(v) {
            const id = Number((v && v.id_vehiculo) || 0);
            if (id <= 0) return 0;
            const total = this.vehiculoCardFotoCount(v);
            if (total <= 0) return 0;
            const idx = Number(this.vehiculoCardSlides[id] || 0);
            return Math.max(0, Math.min(idx, total - 1));
        },
        vehiculoCardFoto(v) {
            const arr = Array.isArray(v && v.fotos_urls) ? v.fotos_urls : [];
            if (arr.length <= 0) return '';
            return arr[this.vehiculoCardFotoIdx(v)] || arr[0] || '';
        },
        vehiculoCardFotoNext(v) {
            const id = Number((v && v.id_vehiculo) || 0);
            if (id <= 0) return;
            const total = this.vehiculoCardFotoCount(v);
            if (total <= 1) return;
            const idx = this.vehiculoCardFotoIdx(v);
            this.vehiculoCardSlides[id] = (idx + 1) % total;
        },
        vehiculoCardFotoPrev(v) {
            const id = Number((v && v.id_vehiculo) || 0);
            if (id <= 0) return;
            const total = this.vehiculoCardFotoCount(v);
            if (total <= 1) return;
            const idx = this.vehiculoCardFotoIdx(v);
            this.vehiculoCardSlides[id] = (idx - 1 + total) % total;
        },
        openServicioForm(s = null) {
            this.modalType = 'servicio';
            this.modalTitle = s ? 'Editar Servicio' : 'Nuevo Servicio';
            this.servicioForm = s
                ? { ...s, duracion_hhmm: this.formatHoursHHMM(s.duracion_horas) }
                : { id_servicio: null, servicio: '', descripcion: '', costo_base: 0, duracion_horas: 1, duracion_hhmm: '01:00' };
            this.showModal = true;
        },
        async saveServicio() {
            this.servicioForm.duracion_horas = this.parseHoursHHMM(this.servicioForm.duracion_hhmm);
            this.servicioForm.duracion_hhmm = this.formatHoursHHMM(this.servicioForm.duracion_horas);
            const action = this.servicioForm.id_servicio ? 'update' : 'create';
            await this.call('/public/taller/api/servicios.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, id_empresa: this.idEmpresa, servicio: this.servicioForm })
            });
            await this.loadCatalogos();
            this.closeModal();
        },
        async deleteServicio(id) {
            if (!confirm('Suprimir servicio?')) return;
            await this.call('/public/taller/api/servicios.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id_empresa: this.idEmpresa, id_servicio: id })
            });
            await this.loadCatalogos();
        },
        openOtForm() {
            this.modalType = 'ot';
            this.modalTitle = 'Nueva Orden de Trabajo';
            this.stopOtChatPolling();
            this.otForm = { id_orden: null, fecha: new Date().toISOString().slice(0,10), hora_ingreso: new Date().toTimeString().slice(0,5), id_cliente: '', id_vehiculo: '', id_mecanico: Number(<?= (int)($_SESSION['id_login'] ?? 0) ?>) || '', mecanico_nombre: '', problema: '', diagnostico: '', estado: 'abierta', prioridad: 'media', km_ingreso: 0, items: [], share_url: '', whatsapp_url: '', fecha_entrega_estimada: '', chat: [], chat_message: '' };
            this.syncOtMecanicoNombre();
            this.vehiculosOt = [...this.vehiculos];
            this.otClienteSearch = '';
            this.otClienteResults = [];
            this.otVehiculoSearch = '';
            this.otVehiculoResults = [...this.vehiculosOt].slice(0, 12);
            this.addOtItem();
            this.showModal = true;
        },
        async editOt(idOrden) {
            let o = {};
            try {
                const data = await this.call(`/public/taller/api/ordenes.php?action=get&id_empresa=${this.idEmpresa}&id_orden=${idOrden}`);
                o = data.data || {};
                await this.writeStorage(`orden:${idOrden}`, data);
            } catch (e) {
                const cached = this.readStorage(`orden:${idOrden}`);
                if (cached?.success && cached?.data) {
                    o = cached.data || {};
                    this.toast('Editando OT desde cache offline');
                } else {
                    throw e;
                }
            }
            this.modalType = 'ot';
            this.modalTitle = `Editar OT ${o.nro_ot || ''}`;
            this.otForm = {
                id_orden: Number(o.id_orden || 0),
                fecha: String(o.fecha || '').slice(0,10),
                hora_ingreso: String(o.hora_ingreso || '').slice(0,5),
                id_cliente: Number(o.id_cliente || 0),
                id_vehiculo: Number(o.id_vehiculo || 0),
                id_mecanico: Number(o.id_mecanico || 0),
                mecanico_nombre: o.mecanico || '',
                problema: o.problema || '',
                diagnostico: o.diagnostico || '',
                estado: o.estado || 'abierta',
                prioridad: o.prioridad || 'media',
                km_ingreso: Number(o.km_ingreso || 0),
                share_url: o.share_url || '',
                whatsapp_url: '',
                fecha_entrega_estimada: o.fecha_entrega_estimada || '',
                chat: Array.isArray(o.chat) ? o.chat : [],
                chat_message: '',
                items: Array.isArray(o.items) ? o.items.map(i => ({
                    tipo: i.tipo || (i.id_producto ? 'repuesto' : (i.id_servicio ? 'servicio' : 'manual')),
                    id_servicio: Number(i.id_servicio || 0),
                    id_producto: Number(i.id_producto || 0),
                    producto_buscar: i.id_producto ? String(i.descripcion || '') : '',
                    producto_results: [],
                    producto_timer: null,
                    descripcion: i.descripcion || '',
                    cantidad: Number(i.cantidad || 1),
                    precio: Number(i.precio || 0)
                })) : []
            };
            this.filterVehiculosOt();
            const clienteOt = (this.clientes || []).find(c => Number(c.id_cliente || 0) === Number(this.otForm.id_cliente || 0));
            this.otClienteSearch = clienteOt ? String(clienteOt.nombre || '') : '';
            const vehiculoOt = (this.vehiculos || []).find(v => Number(v.id_vehiculo || 0) === Number(this.otForm.id_vehiculo || 0));
            this.otVehiculoSearch = vehiculoOt ? this.formatVehiculoOption(vehiculoOt) : '';
            this.otClienteResults = [];
            this.otVehiculoResults = this.vehiculosOt.slice(0, 12);
            if (!this.otForm.items.length) this.addOtItem();
            this.showModal = true;
            this.syncOtMecanicoNombre();
            if (this.isServerOtId(this.otForm.id_orden)) {
                this.startOtChatPolling();
            }
        },
        syncOtMecanicoNombre() {
            const id = Number(this.otForm.id_mecanico || 0);
            const mecanico = (this.mecanicos || []).find(m => Number(m.id_login || 0) === id);
            this.otForm.mecanico_nombre = mecanico ? String(mecanico.nombre || mecanico.login || '') : '';
        },
        addOtItem() { this.otForm.items.push({ tipo: 'servicio', id_servicio: '', id_producto: 0, producto_buscar: '', producto_results: [], producto_timer: null, descripcion: '', cantidad: 1, precio: 0 }); },
        onOtItemTypeChange(it) {
            it.id_servicio = '';
            it.id_producto = 0;
            it.producto_buscar = '';
            it.producto_results = [];
            if (it.tipo === 'servicio') {
                it.descripcion = '';
                it.precio = 0;
                return;
            }
            if (it.tipo === 'repuesto') {
                it.descripcion = '';
                it.precio = 0;
                return;
            }
        },
        fillItemFromServicio(it) {
            const s = this.servicios.find(x => Number(x.id_servicio) === Number(it.id_servicio));
            if (!s) return;
            it.tipo = 'servicio';
            it.id_producto = 0;
            it.producto_buscar = '';
            it.producto_results = [];
            it.descripcion = s.servicio || it.descripcion;
            it.precio = Number(s.costo_base || 0);
        },
        buscarProductosOtItem(it) {
            if (!it || it.tipo !== 'repuesto') return;
            clearTimeout(it.producto_timer);
            it.producto_timer = setTimeout(async () => {
                const q = String(it.producto_buscar || '').trim();
                if (q.length < 2) {
                    it.producto_results = [];
                    return;
                }
                try {
                    const res = await fetch(`/public/pos/api/productos.php?action=search&id_empresa=${this.idEmpresa}&q=${encodeURIComponent(q)}`);
                    const data = await res.json();
                    it.producto_results = Array.isArray(data.productos) ? data.productos.slice(0, 12) : [];
                } catch (e) {
                    it.producto_results = [];
                }
            }, 180);
        },
        selectProductoOtItem(it, prod) {
            it.tipo = 'repuesto';
            it.id_servicio = '';
            it.id_producto = Number(prod && prod.id ? prod.id : 0);
            it.producto_buscar = String(prod?.descripcion || '');
            it.producto_results = [];
            it.descripcion = String(prod?.descripcion || it.descripcion || '');
            it.precio = Number(prod?.precio || 0);
        },
        buscarClientesOt() {
            clearTimeout(this.otClienteSearchTimer);
            this.otClienteSearchTimer = setTimeout(() => {
                const q = String(this.otClienteSearch || '').trim().toLowerCase();
                const base = Array.isArray(this.clientes) ? this.clientes : [];
                this.otClienteResults = base
                    .filter(c => {
                        if (!q) return true;
                        const text = `${c.nombre || ''} ${c.documento || ''} ${c.telefono || ''}`.toLowerCase();
                        return text.includes(q);
                    })
                    .slice(0, 12)
                    .map(c => ({
                        id: Number(c.id_cliente || 0),
                        nombre: c.nombre || '',
                        ruc: c.documento || '',
                        telefono: c.telefono || ''
                    }));
            }, 120);
        },
        selectClienteOt(cli) {
            const id = Number(cli && cli.id ? cli.id : 0);
            this.otForm.id_cliente = id;
            this.otClienteSearch = String(cli?.nombre || '');
            this.otClienteResults = [];
            this.filterVehiculosOt();
            if (this.vehiculosOt.length === 1) {
                this.selectVehiculoOt(this.vehiculosOt[0]);
                return;
            }
            this.otVehiculoResults = this.vehiculosOt.slice(0, 12);
            this.otVehiculoSearch = '';
        },
        buscarVehiculosOt() {
            clearTimeout(this.otVehiculoSearchTimer);
            this.otVehiculoSearchTimer = setTimeout(() => {
                const q = String(this.otVehiculoSearch || '').trim().toLowerCase();
                const base = Array.isArray(this.vehiculosOt) ? this.vehiculosOt : [];
                this.otVehiculoResults = base
                    .filter(v => {
                        if (!q) return true;
                        const text = `${v.chapa || ''} ${v.marca || ''} ${v.modelo || ''} ${v.cliente || ''} ${v.color || ''}`.toLowerCase();
                        return text.includes(q);
                    })
                    .slice(0, 12);
            }, 120);
        },
        selectVehiculoOt(v) {
            const idVehiculo = Number(v && v.id_vehiculo ? v.id_vehiculo : 0);
            if (idVehiculo <= 0) return;
            this.otForm.id_vehiculo = idVehiculo;
            this.otVehiculoSearch = this.formatVehiculoOption(v);
            this.otVehiculoResults = [];
            const idCliente = Number(v.id_cliente || 0);
            if (idCliente > 0) {
                this.otForm.id_cliente = idCliente;
                const cliente = (this.clientes || []).find(c => Number(c.id_cliente || 0) === idCliente);
                this.otClienteSearch = cliente ? String(cliente.nombre || '') : this.otClienteSearch;
                this.filterVehiculosOt();
            }
        },
        filterVehiculosOt() {
            const cid = Number(this.otForm.id_cliente || 0);
            this.vehiculosOt = cid > 0 ? this.vehiculos.filter(v => Number(v.id_cliente) === cid) : [...this.vehiculos];
            if (cid > 0 && !this.vehiculosOt.some(v => Number(v.id_vehiculo) === Number(this.otForm.id_vehiculo || 0))) {
                this.otForm.id_vehiculo = '';
                this.otVehiculoSearch = '';
            }
            this.otVehiculoResults = this.vehiculosOt.slice(0, 12);
        },
        otTotal() {
            return (this.otForm.items || []).reduce((sum, it) => sum + (Number(it.cantidad || 0) * Number(it.precio || 0)), 0);
        },
        async saveOt() {
            this.syncOtMecanicoNombre();
            this.otForm.fecha_entrega_estimada = this.formatSqlDateTimeLocal(this.otEstimatedEndDateTime());
            const currentId = String(this.otForm.id_orden || '').trim();
            const isLocalOnly = currentId.startsWith('ot-local-');
            const action = currentId && !isLocalOnly ? 'update' : 'create';
            const payload = {
                action,
                id_empresa: this.idEmpresa,
                orden: {
                    ...this.otForm,
                    id_orden: action === 'update' ? this.otForm.id_orden : null
                }
            };
            try {
                const data = await this.call('/public/taller/api/ordenes.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                this.otForm.id_orden = Number(data.id_orden || this.otForm.id_orden || 0);
                await this.loadOrdenes();
                this.closeModal();
            } catch (e) {
                const localId = currentId || this.createOfflineOtId();
                const preview = this.buildOtPreview({ ...this.otForm, id_orden: localId });
                this.otForm.id_orden = localId;
                this.queueOfflineOt({
                    payload: {
                        action: 'create',
                        id_empresa: this.idEmpresa,
                        orden: { ...this.otForm, id_orden: null }
                    },
                    preview
                });
                await this.writeStorage(`orden:${localId}`, { success: true, data: preview });
                this.ordenes = this.mergeOfflineOrdenes(this.ordenes || []);
                this.refreshGridData('ordenes', this.ordenes);
                this.toast('OT guardada offline');
                this.closeModal();
            }
        },
        async saveOtDraft() {
            this.syncOtMecanicoNombre();
            this.otForm.fecha_entrega_estimada = this.formatSqlDateTimeLocal(this.otEstimatedEndDateTime());
            const currentId = String(this.otForm.id_orden || '').trim();
            const isLocalOnly = currentId.startsWith('ot-local-');
            const action = currentId && !isLocalOnly ? 'update' : 'create';
            const payload = {
                action,
                id_empresa: this.idEmpresa,
                orden: {
                    ...this.otForm,
                    id_orden: action === 'update' ? this.otForm.id_orden : null
                }
            };
            try {
                const data = await this.call('/public/taller/api/ordenes.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                this.otForm.id_orden = Number(data.id_orden || this.otForm.id_orden || 0);
                await this.loadOrdenes();
                return this.otForm.id_orden;
            } catch (e) {
                const localId = currentId || this.createOfflineOtId();
                const preview = this.buildOtPreview({ ...this.otForm, id_orden: localId });
                this.otForm.id_orden = localId;
                this.queueOfflineOt({
                    payload: {
                        action: 'create',
                        id_empresa: this.idEmpresa,
                        orden: { ...this.otForm, id_orden: null }
                    },
                    preview
                });
                await this.writeStorage(`orden:${localId}`, { success: true, data: preview });
                this.ordenes = this.mergeOfflineOrdenes(this.ordenes || []);
                this.refreshGridData('ordenes', this.ordenes);
                this.toast('Borrador OT guardado offline');
                return localId;
            }
        },
        async loadOtChat(silent = false) {
            const idOrden = Number(this.otForm.id_orden || 0);
            if (!this.isServerOtId(idOrden)) {
                this.otForm.chat = [];
                return;
            }
            try {
                const data = await this.call(`/public/taller/api/ordenes.php?action=chat&id_empresa=${this.idEmpresa}&id_orden=${idOrden}`);
                this.otForm.chat = data.data || [];
            } catch (e) {
                if (!navigator.onLine && silent) return;
                if (!silent) throw e;
            }
        },
        startOtChatPolling() {
            this.stopOtChatPolling();
            if (!this.isServerOtId(this.otForm.id_orden)) return;
            this.otChatPoller = setInterval(() => {
                if (this.showModal && this.modalType === 'ot' && this.isServerOtId(this.otForm.id_orden)) {
                    this.loadOtChat(true);
                }
            }, 10000);
        },
        stopOtChatPolling() {
            if (this.otChatPoller) {
                clearInterval(this.otChatPoller);
                this.otChatPoller = null;
            }
        },
        async sendOtChat() {
            if (!this.requireOnline('Enviar mensajes')) return;
            const idOrden = Number(this.otForm.id_orden || 0);
            const mensaje = String(this.otForm.chat_message || '').trim();
            if (!this.isServerOtId(idOrden)) {
                alert('Guardá la OT antes de usar el chat.');
                return;
            }
            if (!mensaje) return;
            const data = await this.call('/public/taller/api/ordenes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'chat_send', id_empresa: this.idEmpresa, id_orden: idOrden, mensaje })
            });
            this.otForm.chat = data.data || [];
            this.otForm.chat_message = '';
        },
        async publishOt(openWhatsapp) {
            if (!this.requireOnline('Publicar OT')) return;
            const whatsappWin = openWhatsapp ? window.open('about:blank', '_blank') : null;
            const idOrden = this.isServerOtId(this.otForm.id_orden) ? Number(this.otForm.id_orden || 0) : await this.saveOtDraft();
            if (!this.isServerOtId(idOrden)) {
                if (whatsappWin) whatsappWin.close();
                this.toast('Primero se debe sincronizar la OT antes de publicarla');
                return;
            }
            const data = await this.call('/public/taller/api/ordenes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'publish', id_empresa: this.idEmpresa, id_orden: idOrden })
            });
            this.otForm.share_url = String(data.share_url || '');
            this.otForm.whatsapp_url = String(data.whatsapp_url || '');
            await this.loadOrdenes();
            if (openWhatsapp && this.otForm.whatsapp_url) {
                if (whatsappWin) {
                    try { whatsappWin.opener = null; } catch (e) {}
                    whatsappWin.location.replace(this.otForm.whatsapp_url);
                    whatsappWin.focus();
                } else {
                    window.location.href = this.otForm.whatsapp_url;
                }
                return;
            }
            if (openWhatsapp && !this.otForm.whatsapp_url) {
                if (whatsappWin) whatsappWin.close();
                alert('La OT fue publicada, pero el cliente no tiene teléfono/WhatsApp cargado.');
            }
            if (this.otForm.share_url && navigator.clipboard && navigator.clipboard.writeText) {
                try { await navigator.clipboard.writeText(this.otForm.share_url); } catch (e) {}
            }
        },
        async deleteOt(id) {
            if (!this.requireOnline('Eliminar OT')) return;
            if (!confirm('Suprimir OT?')) return;
            await this.call('/public/taller/api/ordenes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id_empresa: this.idEmpresa, id_orden: id })
            });
            await this.loadOrdenes();
        },
        toast(message) {
            const msg = String(message || '').trim();
            if (!msg) return;
            if (this.toastTimer) {
                clearTimeout(this.toastTimer);
                this.toastTimer = null;
            }
            this.toastState = { show: true, msg };
            this.toastTimer = setTimeout(() => {
                this.toastState.show = false;
            }, 3200);
        }
    };
}
</script>
</body>
</html>
