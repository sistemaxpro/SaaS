<?php

/**
 * Gestión de Suscripciones SaaS
 * Panel de administración para gestionar catálogo de apps y suscripciones por empresa
 * Solo accesible para super admin (empresa 169)
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/Modules/Empresas/SuscripcionController.php';

// Verificar sesión y permisos
Session::requireLogin('/public/login.php');

function canAccessAdminSuscripciones(int $idEmpresa): bool
{
    if ($idEmpresa === 169) {
        return true;
    }
    if (Permission::hasAccess('suscripciones')) {
        return true;
    }
    $appsEmpresa = SuscripcionController::getAppsEmpresa($idEmpresa);
    if (($appsEmpresa['success'] ?? false) !== true) {
        return false;
    }
    foreach (($appsEmpresa['data'] ?? []) as $app) {
        $codigo = (string)($app['codigo'] ?? '');
        $ruta = (string)($app['app'] ?? $app['ruta_app'] ?? '');
        if ($codigo === 'central_apps' || $codigo === 'suscripciones' || $ruta === 'public/suscripciones.php') {
            return true;
        }
    }
    return false;
}

$id_empresa = Session::getIdEmpresa();
$usr_priv_admin = ($_SESSION['usr_priv_admin'] ?? 'N') === 'Y';

// Acceso al panel administrativo de suscripciones:
// empresa 169 o empresas con permiso/asignación explícita.
if (!canAccessAdminSuscripciones((int)$id_empresa)) {
    header('Location: /public/mi_suscripcion.php');
    exit;
}

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = (bool)preg_match('/Android|iPhone|iPad|iPod|Mobile|Opera Mini|IEMobile/i', $ua);
if ($isMobile && !isset($_GET['desktop'])) {
    header('Location: /public/suscripciones_mobile.php');
    exit;
}

$usr_name = $_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<script>
    // Detectar tema del sistema
    (function() {
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.setAttribute('data-bs-theme', prefersDark ? 'dark' : 'light');
        document.documentElement.style.colorScheme = prefersDark ? 'dark' : 'light';
        if (prefersDark) {
            document.documentElement.classList.add('dark');
        }
    })();
</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Suscripciones SaaS - SistemaX</title>
    <link rel="stylesheet" href="assets/tailwind.css">
    <link rel="stylesheet" href="/public/_lib/ag-grid/ag-grid-enterprise/package/styles/ag-grid.css">
    <link rel="stylesheet" href="/public/_lib/ag-grid/ag-grid-enterprise/package/styles/ag-theme-quartz.css">
    <script src="/public/assets/js/ag-grid-locale.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="/public/assets/js/notifications.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        window.__agGridReady = new Promise(function(resolve){
            var s = document.createElement('script');
            s.src = '/public/_lib/ag-grid/ag-grid-enterprise/package/dist/ag-grid-enterprise.min.noStyle.js';
            s.onload = function(){
                var l = document.createElement('script');
                l.src = '/public/_lib/ag-grid/license.js';
                l.onload = resolve;
                document.head.appendChild(l);
            };
            document.head.appendChild(s);
        });
    </script>
    <style>
        [x-cloak] { display: none !important; }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .dark .glass-card {
            background: rgba(30, 41, 59, 0.95);
            border-color: rgba(255, 255, 255, 0.1);
        }
        
        /* Full height app */
        html, body {
            height: 100%;
            overflow: hidden;
        }
        .app-container {
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .app-content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }
        .sus-grid {
            width: 100%;
            height: calc(100vh - 250px);
            min-height: 420px;
        }
        .ag-theme-quartz,
        .ag-theme-quartz-dark {
            --ag-font-size: 12px;
            --ag-header-height: 42px;
            --ag-row-height: 44px;
        }
        .sus-row-anulada .ag-cell {
            opacity: .55;
        }
        .sus-row-anulada .ag-cell-value {
            text-decoration: line-through;
        }
        .ag-watermark,.ag-watermark-text{display:none!important;opacity:0!important;visibility:hidden!important;}
    </style>
</head>
<body class="bg-slate-100 dark:bg-slate-900">
    <div x-data="suscripcionesApp()" x-cloak class="app-container">
        <!-- Header -->
        <header class="bg-white/80 dark:bg-white/10 backdrop-blur-xl border-b border-slate-200 dark:border-white/10 flex-shrink-0">
            <div class="w-full px-6 py-4 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <button @click="salir()"
                            class="w-10 h-10 rounded-lg bg-red-600/10 border border-red-500/40 flex items-center justify-center text-red-600 dark:text-red-400 hover:bg-red-600/20 active:scale-95 transition-all cursor-pointer"
                            title="Salir">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15m-3 0-3-3m0 0 3-3m-3 3H15" />
                        </svg>
                    </button>
                    <div>
                        <h1 class="text-xl font-bold text-slate-800 dark:text-white">Gestión de Suscripciones SaaS</h1>
                        <p class="text-slate-500 dark:text-white/60 text-sm">Administrar catálogo de apps y facturas mensuales</p>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <a href="suscripciones_dashboard.php" 
                       class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-all shadow-lg">
                        <i class="fas fa-chart-line mr-1"></i> Dashboard
                    </a>
                    <span class="text-slate-500 dark:text-white/60 text-sm"><?= htmlspecialchars($usr_name) ?></span>
                </div>
            </div>
        </header>

        <!-- Tabs -->
        <div class="w-full px-6 py-4 flex-shrink-0 bg-slate-100/50 dark:bg-slate-900/50">
            <div class="flex gap-2 border-b border-slate-200 dark:border-white/10">
                <button @click="setTab('suscripciones')" 
                        :class="activeTab === 'suscripciones' ? 'text-blue-600 dark:text-white border-blue-400' : 'text-slate-500 dark:text-white/50 border-transparent hover:text-slate-700 dark:hover:text-white/80'"
                        class="px-4 py-3 font-medium border-b-2 transition-colors">
                    <i class="fas fa-file-invoice-dollar mr-2"></i>Suscripciones
                </button>
                <button @click="setTab('catalogo')" 
                        :class="activeTab === 'catalogo' ? 'text-blue-600 dark:text-white border-blue-400' : 'text-slate-500 dark:text-white/50 border-transparent hover:text-slate-700 dark:hover:text-white/80'"
                        class="px-4 py-3 font-medium border-b-2 transition-colors">
                    <i class="fas fa-cubes mr-2"></i>Catálogo de Apps
                </button>
                <button @click="setTab('empresas')" 
                        :class="activeTab === 'empresas' ? 'text-blue-600 dark:text-white border-blue-400' : 'text-slate-500 dark:text-white/50 border-transparent hover:text-slate-700 dark:hover:text-white/80'"
                        class="px-4 py-3 font-medium border-b-2 transition-colors">
                    <i class="fas fa-building mr-2"></i>Por Empresa
                </button>
            </div>
            <div class="mt-3" x-show="activeTab === 'suscripciones'">
                <input x-model="gridSearch" @input.debounce.180ms="applyQuickFilter()"
                       :placeholder="'Buscar en ' + activeTab + '...'"
                       class="w-full md:w-[420px] bg-white dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm">
            </div>
        </div>

        <!-- Content -->
        <div class="app-content w-full px-6 py-6">
            
            <!-- Tab: Suscripciones -->
            <div x-show="activeTab === 'suscripciones'" x-transition>
                <div class="glass-card rounded-2xl p-6 mb-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white">
                            <i class="fas fa-file-invoice-dollar text-blue-500 mr-2"></i>
                            Todas las Suscripciones
                        </h2>
                        <div class="flex gap-3">
                            <button @click="seleccionarTodasSuscripciones()"
                                    class="bg-slate-700 hover:bg-slate-800 text-white rounded-lg px-3 py-2 text-sm font-medium">
                                Check all
                            </button>
                            <button @click="deseleccionarTodasSuscripciones()"
                                    class="bg-slate-200 hover:bg-slate-300 text-slate-800 rounded-lg px-3 py-2 text-sm font-medium dark:bg-slate-600 dark:hover:bg-slate-500 dark:text-white">
                                Uncheck all
                            </button>
                            <button @click="procesarSuscripcionesSeleccionadas(true)"
                                    class="bg-red-600 hover:bg-red-700 text-white rounded-lg px-3 py-2 text-sm font-medium">
                                Anular check
                            </button>
                            <button @click="procesarSuscripcionesSeleccionadas(false)"
                                    class="bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg px-3 py-2 text-sm font-medium">
                                Desanular check
                            </button>
                            <select x-model="filtroAnulacion" @change="cargarSuscripciones()"
                                    class="bg-white dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm">
                                <option value="activo">Activo</option>
                                <option value="anulado">Anulado</option>
                                <option value="todo">Todo</option>
                            </select>
                            <select x-model="filtroEstado" @change="cargarSuscripciones()"
                                    class="bg-white dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm">
                                <option value="">Todos los estados</option>
                                <option value="activa">Activa</option>
                                <option value="gracia">En Gracia</option>
                                <option value="vencida">Vencida</option>
                            </select>
                            <select x-model="filtroPago" @change="cargarSuscripciones()"
                                    class="bg-white dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded-lg px-3 py-2 text-sm">
                                <option value="">Todos los pagos</option>
                                <option value="pendiente">Pendiente</option>
                                <option value="pagado">Pagado</option>
                                <option value="atrasado">Atrasado</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Loading -->
                    <div x-show="cargandoSuscripciones" class="text-center py-10">
                        <i class="fas fa-spinner fa-spin text-3xl text-blue-500"></i>
                        <p class="text-gray-500 mt-2">Cargando...</p>
                    </div>
                    
                    <div x-show="!cargandoSuscripciones">
                        <div x-ref="gridSuscripciones" class="sus-grid" :class="{'ag-theme-quartz': themeMode !== 'dark', 'ag-theme-quartz-dark': themeMode === 'dark'}"></div>
                    </div>
                </div>
            </div>

            <!-- Tab: Catálogo de Apps -->
            <div x-show="activeTab === 'catalogo'" x-transition>
                <div class="glass-card rounded-2xl p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white">
                            <i class="fas fa-cubes text-purple-500 mr-2"></i>
                            Catálogo de Aplicaciones
                            <span x-show="busquedaApp" class="text-sm font-normal text-gray-500 ml-2"
                                  x-text="'(' + appsFiltradas.length + ' resultados)'">
                            </span>
                        </h2>
                        <div class="flex items-center gap-3">
                            <div class="inline-flex rounded-lg border border-gray-200 dark:border-slate-600 overflow-hidden">
                                <button @click="catalogoVista='grid'"
                                        :class="catalogoVista==='grid' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-slate-700 text-gray-600 dark:text-gray-300'"
                                        class="px-3 py-2 text-xs font-semibold">
                                    <i class="fas fa-grip mr-1"></i> Grid
                                </button>
                                <button @click="catalogoVista='list'"
                                        :class="catalogoVista==='list' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-slate-700 text-gray-600 dark:text-gray-300'"
                                        class="px-3 py-2 text-xs font-semibold border-l border-gray-200 dark:border-slate-600">
                                    <i class="fas fa-list mr-1"></i> List
                                </button>
                            </div>
                            <!-- Buscador -->
                            <div class="relative">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                <input type="text" 
                                       x-model="busquedaApp"
                                       placeholder="Buscar app..."
                                       class="bg-white dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded-lg pl-10 pr-8 py-2 text-sm w-64 focus:ring-2 focus:ring-purple-500 focus:border-purple-500 transition-all">
                                <button x-show="busquedaApp" 
                                        @click="busquedaApp = ''"
                                        class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <button @click="abrirGestionNegocios()"
                                    class="bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                                <i class="fas fa-briefcase mr-1"></i> Gestionar Negocios
                            </button>
                            <button @click="nuevaApp()"
                                    class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                                <i class="fas fa-plus mr-1"></i> Nueva App
                            </button>
                        </div>
                    </div>
                    
                    <!-- Apps en grid agrupado por estado -->
                    <div x-show="catalogoVista==='grid'">
                        <div class="space-y-7">
                            <div x-show="appsGridDisponibles.length > 0">
                                <div class="flex items-center gap-2 mb-3">
                                    <span class="inline-flex items-center gap-1 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 text-xs px-2 py-1 rounded-full font-medium">
                                        <i class="fas fa-check-circle"></i> Disponible
                                    </span>
                                    <span class="text-xs text-gray-500" x-text="appsGridDisponibles.length + ' apps'"></span>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                                    <template x-for="app in appsGridDisponibles" :key="'grid-d-'+app.id_app">
                                        <div class="bg-white dark:bg-slate-700/50 rounded-xl p-4 border-2 hover:shadow-lg transition-all border-green-300 dark:border-green-600">
                                            <div class="flex items-start justify-between mb-2">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-10 h-10 rounded-lg overflow-hidden"
                                                         :class="app.icono_source !== 'custom' ? 'flex items-center justify-center bg-' + app.color + '-100 dark:bg-' + app.color + '-900/30' : ''">
                                                        <span :class="app.icono_source === 'custom' ? 'w-full h-full block' : 'text-' + app.color + '-600 dark:text-' + app.color + '-400 w-5 h-5'"
                                                              x-html="renderHeroicon(app.icono, app.icono_source)"></span>
                                                    </div>
                                                    <div>
                                                        <h4 class="font-semibold text-gray-800 dark:text-white text-sm" x-text="app.nombre"></h4>
                                                        <p class="text-xs text-gray-400 font-mono" x-text="app.codigo"></p>
                                                    </div>
                                                </div>
                                                <span x-show="app.obligatoria == 1"
                                                      class="bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-[10px] px-1.5 py-0.5 rounded-full">Obligatoria</span>
                                            </div>
                                            <div class="flex items-center justify-between mt-3 pt-2 border-t border-gray-100 dark:border-slate-600">
                                                <span class="text-base font-bold text-green-600">₲ <span x-text="formatNumber(app.precio_mensual)"></span><span class="text-[10px] text-gray-400 font-normal">/mes</span></span>
                                                <div class="flex gap-0.5">
                                                    <button @click="abrirApp(app)" class="text-green-600 hover:text-green-800 p-1.5 hover:bg-green-50 dark:hover:bg-green-900/30 rounded-lg transition-colors" title="Abrir app"><i class="fas fa-external-link-alt text-xs"></i></button>
                                                    <button @click="editarApp(app)" class="text-blue-500 hover:text-blue-700 p-1.5 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded-lg transition-colors" title="Editar"><i class="fas fa-edit text-xs"></i></button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div x-show="appsGridEnDesarrollo.length > 0">
                                <div class="flex items-center gap-2 mb-3">
                                    <span class="inline-flex items-center gap-1 bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400 text-xs px-2 py-1 rounded-full font-medium">
                                        <i class="fas fa-code"></i> En desarrollo
                                    </span>
                                    <span class="text-xs text-gray-500" x-text="appsGridEnDesarrollo.length + ' apps'"></span>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                                    <template x-for="app in appsGridEnDesarrollo" :key="'grid-e-'+app.id_app">
                                        <div class="bg-white dark:bg-slate-700/50 rounded-xl p-4 border-2 hover:shadow-lg transition-all border-orange-300 dark:border-orange-600">
                                            <div class="flex items-start justify-between mb-2">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-10 h-10 rounded-lg overflow-hidden"
                                                         :class="app.icono_source !== 'custom' ? 'flex items-center justify-center bg-' + app.color + '-100 dark:bg-' + app.color + '-900/30' : ''">
                                                        <span :class="app.icono_source === 'custom' ? 'w-full h-full block' : 'text-' + app.color + '-600 dark:text-' + app.color + '-400 w-5 h-5'"
                                                              x-html="renderHeroicon(app.icono, app.icono_source)"></span>
                                                    </div>
                                                    <div>
                                                        <h4 class="font-semibold text-gray-800 dark:text-white text-sm" x-text="app.nombre"></h4>
                                                        <p class="text-xs text-gray-400 font-mono" x-text="app.codigo"></p>
                                                    </div>
                                                </div>
                                                <span x-show="app.obligatoria == 1"
                                                      class="bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-[10px] px-1.5 py-0.5 rounded-full">Obligatoria</span>
                                            </div>
                                            <div class="flex items-center justify-between mt-3 pt-2 border-t border-gray-100 dark:border-slate-600">
                                                <span class="text-base font-bold text-green-600">₲ <span x-text="formatNumber(app.precio_mensual)"></span><span class="text-[10px] text-gray-400 font-normal">/mes</span></span>
                                                <div class="flex gap-0.5">
                                                    <button @click="abrirApp(app)" class="text-green-600 hover:text-green-800 p-1.5 hover:bg-green-50 dark:hover:bg-green-900/30 rounded-lg transition-colors" title="Abrir app"><i class="fas fa-external-link-alt text-xs"></i></button>
                                                    <button @click="editarApp(app)" class="text-blue-500 hover:text-blue-700 p-1.5 hover:bg-blue-50 dark:hover:bg-blue-900/30 rounded-lg transition-colors" title="Editar"><i class="fas fa-edit text-xs"></i></button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div x-show="catalogoVista==='list'" class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-100 dark:border-slate-700">
                                    <th class="pb-3 pl-3">App</th>
                                    <th class="pb-3">Código</th>
                                    <th class="pb-3">Negocio</th>
                                    <th class="pb-3 text-right">Precio</th>
                                    <th class="pb-3 text-center">Estado</th>
                                    <th class="pb-3 text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                                <template x-for="app in appsFiltradas" :key="'list-'+app.id_app">
                                    <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                                        <td class="py-3 pl-3">
                                            <div class="flex items-center gap-2">
                                                <div class="w-8 h-8 rounded-lg overflow-hidden"
                                                     :class="app.icono_source !== 'custom' ? 'flex items-center justify-center bg-' + app.color + '-100 dark:bg-' + app.color + '-900/30' : ''">
                                                    <span :class="app.icono_source === 'custom' ? 'w-full h-full block' : 'text-' + app.color + '-600 dark:text-' + app.color + '-400 w-4 h-4'"
                                                          x-html="renderHeroicon(app.icono, app.icono_source)"></span>
                                                </div>
                                                <span class="font-medium text-gray-800 dark:text-white" x-text="app.nombre"></span>
                                            </div>
                                        </td>
                                        <td class="py-3 font-mono text-xs text-gray-500" x-text="app.codigo"></td>
                                        <td class="py-3 text-sm text-gray-600 dark:text-gray-300" x-text="app.negocio || 'Sin tipo'"></td>
                                        <td class="py-3 text-right font-semibold text-green-600">₲ <span x-text="formatNumber(app.precio_mensual)"></span></td>
                                        <td class="py-3 text-center">
                                            <span x-show="app.en_desarrollo == 1" class="text-[11px] px-2 py-1 rounded-full bg-orange-100 text-orange-700">En desarrollo</span>
                                            <span x-show="app.en_desarrollo != 1" class="text-[11px] px-2 py-1 rounded-full bg-green-100 text-green-700">Disponible</span>
                                        </td>
                                        <td class="py-3 text-center">
                                            <div class="flex items-center justify-center gap-2">
                                                <button @click="abrirApp(app)" class="text-green-600 hover:text-green-800 hover:bg-green-50 dark:hover:bg-green-900/30 p-1.5 rounded-lg transition-colors" title="Abrir app">
                                                    <i class="fas fa-external-link-alt text-xs"></i>
                                                </button>
                                                <button @click="editarApp(app)" class="text-blue-500 hover:text-blue-700 hover:bg-blue-50 dark:hover:bg-blue-900/30 p-1.5 rounded-lg transition-colors" title="Editar">
                                                    <i class="fas fa-edit text-xs"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Sin resultados -->
                    <template x-if="appsFiltradas.length === 0">
                        <div class="text-center py-12">
                            <i class="fas fa-search text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                            <p class="text-gray-500 dark:text-gray-400">
                                <span x-show="busquedaApp">No se encontraron aplicaciones con "<span x-text="busquedaApp" class="font-medium"></span>"</span>
                                <span x-show="!busquedaApp">No hay aplicaciones para mostrar</span>
                            </p>
                            <button @click="busquedaApp = ''" class="mt-3 text-blue-500 hover:text-blue-700 text-sm">
                                <i class="fas fa-times mr-1"></i> Limpiar búsqueda
                            </button>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Tab: Por Empresa -->
            <div x-show="activeTab === 'empresas'" x-transition>
                <div class="glass-card rounded-2xl p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white">
                            <i class="fas fa-building text-cyan-500 mr-2"></i>
                            Gestionar por Empresa
                        </h2>
                    </div>
                    
                    <!-- Buscador de empresa -->
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-2">
                            Buscar Empresa
                        </label>
                        <div class="relative w-full md:w-[500px]">
                            <!-- Campo de búsqueda -->
                            <div class="relative">
                                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                <input type="text" 
                                       x-model="busquedaEmpresa"
                                       @focus="mostrarResultados = true"
                                       @click.away="setTimeout(() => mostrarResultados = false, 200)"
                                       @input="mostrarResultados = true"
                                       placeholder="Escriba nombre o RUC de la empresa..."
                                       class="w-full bg-white dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded-xl pl-11 pr-4 py-3 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                                <template x-if="empresaActual">
                                    <button @click="limpiarEmpresaSeleccionada()" 
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-red-500">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </template>
                            </div>
                            
                            <!-- Empresa seleccionada -->
                            <template x-if="empresaActual">
                                <div class="mt-3 flex items-center gap-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-200 dark:border-blue-800">
                                    <div class="w-12 h-12 rounded-lg bg-white dark:bg-slate-700 border border-gray-200 dark:border-slate-600 flex items-center justify-center overflow-hidden flex-shrink-0">
                                        <template x-if="empresaActual.logo_url">
                                            <img :src="empresaActual.logo_url" :alt="empresaActual.empresa" class="w-full h-full object-contain p-1">
                                        </template>
                                        <template x-if="!empresaActual.logo_url">
                                            <i class="fas fa-building text-gray-400 text-xl"></i>
                                        </template>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-semibold text-gray-800 dark:text-white truncate" x-text="empresaActual.empresa"></p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            <span class="font-mono" x-text="empresaActual.ruc"></span>
                                            <template x-if="empresaActual.email">
                                                <span class="ml-2">• <span x-text="empresaActual.email"></span></span>
                                            </template>
                                        </p>
                                    </div>
                                    <span class="px-2 py-1 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 text-xs font-medium rounded-full">Seleccionada</span>
                                </div>
                            </template>
                            
                            <!-- Dropdown de resultados -->
                            <div x-show="mostrarResultados && !empresaActual && busquedaEmpresa.length > 0"
                                 x-transition:enter="transition ease-out duration-200"
                                 x-transition:enter-start="opacity-0 -translate-y-2"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 class="absolute z-50 w-full mt-2 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 rounded-xl shadow-xl max-h-80 overflow-y-auto">
                                
                                <template x-for="emp in empresasFiltradas" :key="emp.id_empresa">
                                    <div @click="seleccionarEmpresa(emp)" 
                                         class="flex items-center gap-4 p-3 hover:bg-gray-50 dark:hover:bg-slate-700 cursor-pointer border-b border-gray-100 dark:border-slate-700 last:border-0 transition-colors">
                                        <!-- Logo -->
                                        <div class="w-11 h-11 rounded-lg bg-gray-100 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 flex items-center justify-center overflow-hidden flex-shrink-0">
                                            <template x-if="emp.logo_url">
                                                <img :src="emp.logo_url" :alt="emp.empresa" class="w-full h-full object-contain p-1">
                                            </template>
                                            <template x-if="!emp.logo_url">
                                                <i class="fas fa-building text-gray-400"></i>
                                            </template>
                                        </div>
                                        <!-- Info -->
                                        <div class="flex-1 min-w-0">
                                            <p class="font-medium text-gray-800 dark:text-white truncate" x-text="emp.empresa"></p>
                                            <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                                <span class="font-mono" x-text="emp.ruc"></span>
                                                <template x-if="emp.email">
                                                    <span x-text="emp.email" class="truncate"></span>
                                                </template>
                                            </div>
                                        </div>
                                        <!-- ID -->
                                        <span class="text-xs text-gray-400 dark:text-gray-500 font-mono">#<span x-text="emp.id_empresa"></span></span>
                                    </div>
                                </template>
                                
                                <!-- Sin resultados -->
                                <div x-show="empresasFiltradas.length === 0" class="p-6 text-center text-gray-400">
                                    <i class="fas fa-search text-2xl mb-2"></i>
                                    <p>No se encontraron empresas</p>
                                </div>
                            </div>
                            
                            <!-- Mostrar todas si no hay búsqueda -->
                            <div x-show="mostrarResultados && !empresaActual && busquedaEmpresa.length === 0"
                                 x-transition
                                 class="absolute z-50 w-full mt-2 bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-600 rounded-xl shadow-xl max-h-80 overflow-y-auto">
                                <div class="p-3 border-b border-gray-100 dark:border-slate-700 bg-gray-50 dark:bg-slate-700/50">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        <span x-text="empresas.length"></span> empresas registradas
                                    </p>
                                </div>
                                <template x-for="emp in empresas.slice(0, 10)" :key="emp.id_empresa">
                                    <div @click="seleccionarEmpresa(emp)" 
                                         class="flex items-center gap-4 p-3 hover:bg-gray-50 dark:hover:bg-slate-700 cursor-pointer border-b border-gray-100 dark:border-slate-700 last:border-0 transition-colors">
                                        <div class="w-11 h-11 rounded-lg bg-gray-100 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 flex items-center justify-center overflow-hidden flex-shrink-0">
                                            <template x-if="emp.logo_url">
                                                <img :src="emp.logo_url" :alt="emp.empresa" class="w-full h-full object-contain p-1">
                                            </template>
                                            <template x-if="!emp.logo_url">
                                                <i class="fas fa-building text-gray-400"></i>
                                            </template>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="font-medium text-gray-800 dark:text-white truncate" x-text="emp.empresa"></p>
                                            <span class="text-xs text-gray-500 dark:text-gray-400 font-mono" x-text="emp.ruc"></span>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="empresas.length > 10">
                                    <div class="p-3 text-center text-xs text-gray-400 bg-gray-50 dark:bg-slate-700/50">
                                        Escriba para buscar más empresas...
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Contenido de empresa seleccionada -->
                    <div x-show="empresaSeleccionada && suscripcionEmpresa" class="space-y-6">
                        <!-- Info de suscripción actual -->
                        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-4 border border-blue-100 dark:border-blue-800">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h3 class="font-semibold text-blue-800 dark:text-blue-200">Suscripción Actual</h3>
                                    <div class="mt-1 flex items-center gap-2 flex-wrap">
                                        <p class="text-sm text-blue-600 dark:text-blue-300" x-text="suscripcionEmpresa?.nro_factura ?? ''"></p>
                                        <span x-show="Number(suscripcionEmpresa?.es_vigente ?? 1) !== 1"
                                              class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300">
                                            Última suscripción
                                        </span>
                                    </div>
                                    <span x-show="Number(suscripcionEmpresa?.es_sponsor || 0) === 1"
                                          class="inline-flex items-center mt-2 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300">
                                        <i class="fas fa-star mr-1"></i>Empresa Sponsor (sin cobro)
                                    </span>
                                    <button x-show="!editandoPeriodoSuscripcion"
                                            @click="iniciarEdicionPeriodoSuscripcion()"
                                            class="mt-2 text-xs px-2.5 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition-colors">
                                        <i class="fas fa-pen mr-1"></i>Editar inicio/fin
                                    </button>
                                    <button x-show="!editandoPeriodoSuscripcion"
                                            @click="toggleSponsorSuscripcion()"
                                            class="mt-2 ml-2 text-xs px-2.5 py-1.5 rounded-lg transition-colors"
                                            :class="Number(suscripcionEmpresa?.es_sponsor || 0) === 1
                                                ? 'bg-amber-600 hover:bg-amber-700 text-white'
                                                : 'bg-emerald-600 hover:bg-emerald-700 text-white'">
                                        <i class="fas" :class="Number(suscripcionEmpresa?.es_sponsor || 0) === 1 ? 'fa-toggle-off' : 'fa-toggle-on'"></i>
                                        <span x-text="Number(suscripcionEmpresa?.es_sponsor || 0) === 1 ? 'Quitar Sponsor' : 'Habilitar Sponsor'"></span>
                                    </button>
                                </div>
                                <div class="text-right">
                                    <p class="text-2xl font-bold text-blue-800 dark:text-blue-200">
                                        ₲ <span x-text="formatNumber(suscripcionEmpresa?.total ?? 0)"></span>
                                    </p>
                                    <p x-show="!editandoPeriodoSuscripcion"
                                       class="text-sm text-blue-600"
                                       x-text="formatDate(suscripcionEmpresa?.periodo_inicio) + ' - ' + formatDate(suscripcionEmpresa?.periodo_fin)"></p>
                                </div>
                            </div>
                            <div x-show="editandoPeriodoSuscripcion" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-blue-700 dark:text-blue-300 mb-1">Inicio</label>
                                    <input type="date" x-model="periodoEditInicio"
                                           class="w-full bg-white dark:bg-slate-800 border border-blue-200 dark:border-blue-700 rounded-lg px-3 py-2 text-sm text-gray-800 dark:text-white">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-blue-700 dark:text-blue-300 mb-1">Fin</label>
                                    <input type="date" x-model="periodoEditFin"
                                           class="w-full bg-white dark:bg-slate-800 border border-blue-200 dark:border-blue-700 rounded-lg px-3 py-2 text-sm text-gray-800 dark:text-white">
                                </div>
                                <div class="md:col-span-2">
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="block text-xs font-medium text-blue-700 dark:text-blue-300">Vencimiento (opcional manual)</label>
                                        <button @click="periodoEditVencimiento = ''"
                                                class="text-xs text-blue-700 dark:text-blue-300 hover:underline">
                                            Usar automático
                                        </button>
                                    </div>
                                    <input type="date" x-model="periodoEditVencimiento"
                                           class="w-full bg-white dark:bg-slate-800 border border-blue-200 dark:border-blue-700 rounded-lg px-3 py-2 text-sm text-gray-800 dark:text-white">
                                    <p class="mt-1 text-[11px] text-blue-700/80 dark:text-blue-300/80">
                                        Si queda vacío, se calcula con fin + días de gracia.
                                    </p>
                                </div>
                                <div class="md:col-span-2 flex items-center justify-end gap-2">
                                    <button @click="cancelarEdicionPeriodoSuscripcion()"
                                            class="px-3 py-2 rounded-lg text-sm bg-slate-200 hover:bg-slate-300 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-100">
                                        Cancelar
                                    </button>
                                    <button @click="guardarPeriodoSuscripcion()"
                                            class="px-3 py-2 rounded-lg text-sm bg-emerald-600 hover:bg-emerald-700 text-white">
                                        <i class="fas fa-save mr-1"></i>Guardar período
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Apps contratadas -->
                        <div>
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="font-semibold text-gray-800 dark:text-white">Apps Contratadas</h3>
                                <button @click="modalAgregarApp = true" 
                                        class="bg-green-500 hover:bg-green-600 text-white px-3 py-1.5 rounded-lg text-sm font-medium transition-colors">
                                    <i class="fas fa-plus mr-1"></i> Agregar App
                                </button>
                            </div>
                            
                            <div class="space-y-2">
                                <template x-for="app in suscripcionEmpresa?.apps ?? []" :key="app.id_app">
                                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-xl">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-lg overflow-hidden"
                                                 :class="app.icono_source !== 'custom' ? 'flex items-center justify-center bg-' + app.color + '-100' : ''">
                                                <span :class="app.icono_source === 'custom' ? 'w-full h-full block' : 'text-' + app.color + '-600 w-5 h-5'"
                                                      x-html="renderHeroicon(app.icono, app.icono_source)"></span>
                                            </div>
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <p class="font-medium text-gray-800 dark:text-white" x-text="app.nombre_app"></p>
                                                    <span x-show="app.obligatoria == 1" 
                                                          class="bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-[10px] px-1.5 py-0.5 rounded-full">
                                                        <i class="fas fa-lock text-[8px] mr-0.5"></i>Obligatoria
                                                    </span>
                                                </div>
                                                <p class="text-xs text-gray-500" x-text="app.codigo_app"></p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-4">
                                            <span class="font-semibold text-gray-700 dark:text-gray-200">
                                                ₲ <span x-text="formatNumber(app.precio_unitario)"></span>
                                            </span>
                                            <button x-show="app.obligatoria != 1"
                                                    @click="quitarAppSuscripcion(app.id_app)" 
                                                    class="text-red-500 hover:text-red-700 p-2 hover:bg-red-50 rounded-lg transition-colors"
                                                    title="Quitar app">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            <span x-show="app.obligatoria == 1" 
                                                  class="text-gray-300 dark:text-gray-600 p-2" 
                                                  title="No se puede quitar una app obligatoria">
                                                <i class="fas fa-lock"></i>
                                            </span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sin suscripción -->
                    <div x-show="empresaSeleccionada && !suscripcionEmpresa && !cargandoEmpresa" 
                         class="text-center py-10">
                        <i class="fas fa-file-invoice text-4xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500 mb-4">Esta empresa no tiene suscripción activa</p>
                        <button @click="crearSuscripcionEmpresa()" 
                                class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg text-sm font-medium transition-colors">
                            <i class="fas fa-plus mr-1"></i> Crear Suscripción
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- Fin Content -->

        <!-- Modal: Editar Solicitud -->
        <div x-show="modalSolicitud" x-transition.opacity class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div @click.away="modalSolicitud = false" class="bg-white dark:bg-slate-800 rounded-2xl max-w-2xl w-full max-h-[90vh] overflow-hidden flex flex-col">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white">
                        <i class="fas fa-user-edit text-indigo-500 mr-2"></i>Editar solicitud
                    </h3>
                    <button @click="modalSolicitud = false" class="text-gray-500 hover:text-gray-700 dark:text-slate-300 dark:hover:text-white">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="p-6 overflow-y-auto space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Empresa</label>
                            <input x-model="formSolicitud.empresa" type="text"
                                   class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600"
                                   placeholder="Nombre de empresa">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Contacto</label>
                            <input x-model="formSolicitud.contacto" type="text"
                                   class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600"
                                   placeholder="Nombre del contacto">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Email</label>
                            <input x-model="formSolicitud.email" type="email"
                                   class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600"
                                   placeholder="correo@empresa.com">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">WhatsApp</label>
                            <input x-model="formSolicitud.telefono" type="text"
                                   class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600"
                                   placeholder="0981xxxxxx">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">RUC</label>
                            <input x-model="formSolicitud.ruc" type="text"
                                   class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600"
                                   placeholder="80000000-0">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Estado</label>
                            <select x-model="formSolicitud.estado"
                                    class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600">
                                <option value="nuevo">Nuevo</option>
                                <option value="contactado">Contactado</option>
                                <option value="cerrado">Cerrado</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Mensaje</label>
                        <textarea x-model="formSolicitud.mensaje" rows="3"
                                  class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600"
                                  placeholder="Observaciones de la solicitud..."></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-slate-700 flex items-center justify-between gap-3 bg-gray-50 dark:bg-slate-800/50">
                    <button @click="suprimirSolicitud(formSolicitud.id_solicitud)"
                            class="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium">
                        <i class="fas fa-trash mr-1"></i>Suprimir
                    </button>
                    <div class="flex items-center gap-2">
                        <button @click="modalSolicitud = false"
                                class="px-4 py-2 text-gray-600 dark:text-slate-300 hover:text-gray-800 dark:hover:text-white font-medium">
                            Cancelar
                        </button>
                        <button @click="guardarSolicitud()"
                                class="px-5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium">
                            <i class="fas fa-save mr-1"></i>Guardar cambios
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: Editar/Crear App -->
        <div x-show="modalApp" x-transition.opacity class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div @click.away="if(!guardarEnProgreso) { modalApp = false }" class="bg-white dark:bg-slate-800 rounded-2xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
                <div class="p-6">
                    <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-6" 
                        x-text="appEditando ? 'Editar App' : 'Nueva App'"></h3>
                    
                    <div class="space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Código</label>
                                <input x-model="formApp.codigo" type="text" 
                                       :readonly="!!appEditando"
                                       :class="appEditando ? 'bg-gray-100 dark:bg-slate-800 text-gray-500 dark:text-slate-400 cursor-not-allowed' : ''"
                                       class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600"
                                       placeholder="venta_pos">
                                <p x-show="appEditando" class="text-xs text-gray-400 mt-1">
                                    El código no se edita desde este modal.
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Nombre</label>
                                <input x-model="formApp.nombre" type="text" 
                                       class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600"
                                       placeholder="Punto de Venta">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Descripción</label>
                            <textarea x-model="formApp.descripcion" rows="2"
                                      class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600"
                                      placeholder="Descripción de la app..."></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Ruta</label>
                            <input x-model="formApp.ruta_app" type="text" 
                                   class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600"
                                   placeholder="pos/index.php">
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Icono</label>
                                <div class="space-y-2">
                                    <div class="flex items-center gap-2">
                                        <div class="w-10 h-10 rounded-lg border border-gray-200 dark:border-slate-600 overflow-hidden"
                                             :class="formApp.icono_source !== 'custom' ? 'bg-gray-50 dark:bg-slate-700 flex items-center justify-center' : ''">
                                            <span :class="formApp.icono_source === 'custom' ? 'w-full h-full block' : 'w-5 h-5 text-slate-700 dark:text-slate-200'"
                                                  x-html="renderHeroicon(formApp.icono || 'cube', formApp.icono_source)"></span>
                                        </div>
                                        <input x-model="formApp.icono" type="text" 
                                               class="flex-1 border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600"
                                               placeholder="cog-6-tooth">
                                    </div>
                                    <button type="button"
                                            @click="abrirSelectorIconos()"
                                            class="w-full border border-gray-300 dark:border-slate-600 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 dark:text-slate-200 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
                                        <i class="fas fa-search mr-1"></i> Elegir icono
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Color</label>
                                <select x-model="formApp.color" 
                                        class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600">
                                    <option value="blue">Azul</option>
                                    <option value="green">Verde</option>
                                    <option value="orange">Naranja</option>
                                    <option value="purple">Morado</option>
                                    <option value="red">Rojo</option>
                                    <option value="cyan">Cian</option>
                                    <option value="pink">Rosa</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Precio Mensual (₲)</label>
                                <input x-model="formApp.precio_mensual" type="number" 
                                       class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600"
                                       placeholder="100000">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Orden</label>
                                <input x-model="formApp.orden" type="number" 
                                       class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600"
                                       placeholder="100">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-600 dark:text-gray-300 mb-1">Negocio</label>
                            <div class="relative">
                                <input type="text"
                                       x-model="formApp.negocio"
                                       list="lista-negocios"
                                       class="w-full border rounded-lg px-3 py-2 dark:bg-slate-700 dark:border-slate-600"
                                       placeholder="Seleccionar o escribir...">
                                <datalist id="lista-negocios">
                                    <template x-for="neg in negociosUnicos" :key="neg">
                                        <option :value="neg"></option>
                                    </template>
                                </datalist>
                                <i class="fas fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none text-xs"></i>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Escriba para agregar nuevo</p>
                        </div>
                        
                        <div class="flex items-center gap-6 flex-wrap">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input x-model="formApp.obligatoria" type="checkbox" class="w-4 h-4 rounded">
                                <span class="text-sm text-gray-600 dark:text-gray-300">App Obligatoria</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input x-model="formApp.activo" type="checkbox" class="w-4 h-4 rounded" checked>
                                <span class="text-sm text-gray-600 dark:text-gray-300">Activa</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input x-model="formApp.en_desarrollo" type="checkbox" class="w-4 h-4 rounded">
                                <span class="text-sm text-gray-600 dark:text-gray-300">
                                    <i class="fas fa-code text-purple-500 mr-1"></i>En desarrollo
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Selector de iconos FontAwesome -->
                    <div x-show="modalIconPicker"
                         x-transition.opacity
                         class="fixed inset-0 bg-black/60 flex items-center justify-center z-[70] p-4">
                        <div @click.away="modalIconPicker = false"
                             class="bg-white dark:bg-slate-800 rounded-2xl max-w-5xl w-full max-h-[85vh] overflow-hidden flex flex-col">
                            <div class="px-5 py-4 border-b border-gray-200 dark:border-slate-700">
                                <h4 class="font-semibold text-gray-800 dark:text-white mb-3">Seleccionar ícono</h4>

                                <!-- Mis Iconos -->
                                <p class="text-sm text-gray-500 dark:text-slate-400 mb-3">
                                    <i class="fas fa-image mr-1"></i> <?= count(glob(__DIR__ . '/assets/images/icons_v2/*.svg')) ?> iconos disponibles
                                </p>

                                <div class="relative mt-3">
                                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                    <input type="text"
                                           x-model="iconSearch"
                                           placeholder="Buscar por nombre o código (ej: cog, cube)"
                                           class="w-full border rounded-lg pl-10 pr-8 py-2 text-sm dark:bg-slate-700 dark:border-slate-600">
                                    <button x-show="iconSearch"
                                            @click="iconSearch = ''"
                                            class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="p-5 overflow-y-auto">

                                <!-- Grid Mis Iconos (icons_v2) -->
                                <div>
                                    <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 gap-3">
                                        <template x-for="ico in customIconsFiltrados" :key="ico.class">
                                            <button type="button"
                                                    @click="seleccionarIcono(ico.class)"
                                                    class="border rounded-xl overflow-hidden flex flex-col items-center transition-all hover:border-blue-400 hover:shadow-md dark:border-slate-600 dark:hover:border-blue-500"
                                                    :class="formApp.icono === ico.class ? 'border-blue-500 ring-2 ring-blue-400' : 'border-gray-200 dark:bg-slate-700/30'">
                                                <img :src="'/public/assets/images/icons_v2/' + ico.class + '.svg'"
                                                     :alt="ico.name"
                                                     class="w-full aspect-square block">
                                                <span class="text-xs text-gray-600 dark:text-slate-300 truncate w-full text-center leading-tight px-1 py-1" x-text="ico.name"></span>
                                            </button>
                                        </template>
                                    </div>
                                    <p x-show="customIconsFiltrados.length === 0" class="text-center text-sm text-gray-500 dark:text-slate-400 py-8">
                                        No se encontraron íconos.
                                    </p>
                                </div>


                            </div>

                            <div class="px-5 py-4 border-t border-gray-200 dark:border-slate-700 flex justify-end">
                                <button type="button"
                                        @click="modalIconPicker = false"
                                        class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-800 dark:text-slate-300 dark:hover:text-white">
                                    Cerrar
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-end gap-3 mt-6 pt-4 border-t dark:border-slate-700">
                        <button @click="modalApp = false"
                                :disabled="guardarEnProgreso"
                                class="px-4 py-2 text-gray-600 hover:text-gray-800 font-medium disabled:opacity-50 disabled:cursor-not-allowed">
                            Cancelar
                        </button>
                        <button @click="guardarApp()"
                                :disabled="guardarEnProgreso"
                                class="bg-blue-500 hover:bg-blue-600 disabled:opacity-50 disabled:cursor-not-allowed text-white px-6 py-2 rounded-lg font-medium transition-colors">
                            <template x-if="!guardarEnProgreso">
                                <span><i class="fas fa-save mr-1"></i> Guardar</span>
                            </template>
                            <template x-if="guardarEnProgreso">
                                <span><i class="fas fa-spinner fa-spin mr-1"></i> Guardando...</span>
                            </template>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: Agregar App a Suscripción -->
        <div x-show="modalAgregarApp" x-transition.opacity class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div @click.away="modalAgregarApp = false; busquedaAppModal = ''; modoAgregarApp = 'individual'" class="bg-white dark:bg-slate-800 rounded-2xl max-w-2xl w-full max-h-[90vh] overflow-hidden flex flex-col">
                <div class="p-6 flex-shrink-0">
                    <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-4">Agregar Apps a Suscripción</h3>
                    
                    <!-- Tabs de modo -->
                    <div class="flex gap-2 mb-4 border-b border-gray-200 dark:border-slate-700 pb-3">
                        <button @click="modoAgregarApp = 'individual'" 
                                :class="modoAgregarApp === 'individual' ? 'bg-blue-500 text-white' : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-slate-600'"
                                class="px-3 py-2 rounded-lg text-sm font-medium transition-colors">
                            <i class="fas fa-cube mr-1"></i> Individual
                        </button>
                        <button @click="modoAgregarApp = 'negocio'" 
                                :class="modoAgregarApp === 'negocio' ? 'bg-blue-500 text-white' : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-slate-600'"
                                class="px-3 py-2 rounded-lg text-sm font-medium transition-colors">
                            <i class="fas fa-building mr-1"></i> Por Negocio
                        </button>
                    </div>
                    
                    <!-- Buscador (solo en modo individual) -->
                    <div x-show="modoAgregarApp === 'individual'" class="relative mb-4">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" 
                               x-model="busquedaAppModal"
                               placeholder="Buscar app..."
                               class="w-full bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded-xl pl-10 pr-10 py-3 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                        <button x-show="busquedaAppModal" 
                                @click="busquedaAppModal = ''"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <div class="px-6 pb-6 overflow-y-auto flex-1">
                    <!-- MODO: Individual -->
                    <div x-show="modoAgregarApp === 'individual'" class="space-y-2">
                        <template x-for="app in appsDisponiblesFiltradas" :key="app.id_app">
                            <div @click="agregarAppSuscripcion(app.id_app)" 
                                 class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-xl cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900/20 hover:border-blue-200 dark:hover:border-blue-700 border border-transparent transition-all">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg overflow-hidden"
                                         :class="app.icono_source !== 'custom' ? 'flex items-center justify-center bg-' + app.color + '-100 dark:bg-' + app.color + '-900/30' : ''">
                                        <span :class="app.icono_source === 'custom' ? 'w-full h-full block' : 'text-' + app.color + '-600 dark:text-' + app.color + '-400 w-5 h-5'"
                                              x-html="renderHeroicon(app.icono, app.icono_source)"></span>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-800 dark:text-white" x-text="app.nombre"></p>
                                        <p class="text-xs text-gray-500" x-text="app.negocio || 'Sin tipo'"></p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <span class="font-semibold text-green-600">
                                        ₲ <span x-text="formatNumber(app.precio_mensual)"></span>
                                    </span>
                                    <p class="text-xs text-gray-400">/mes</p>
                                </div>
                            </div>
                        </template>
                        
                        <!-- Sin resultados -->
                        <template x-if="appsDisponiblesFiltradas.length === 0">
                            <div class="text-center py-8 text-gray-400">
                                <i class="fas fa-box-open text-3xl mb-3"></i>
                                <p x-show="busquedaAppModal">No se encontraron apps con "<span x-text="busquedaAppModal" class="font-medium"></span>"</p>
                                <p x-show="!busquedaAppModal">No hay apps disponibles para agregar</p>
                            </div>
                        </template>
                    </div>
                    
                    <!-- MODO: Por Negocio -->
                    <div x-show="modoAgregarApp === 'negocio'" class="space-y-3">
                        <template x-for="negocio in negociosDisponibles" :key="negocio">
                            <div class="rounded-xl p-4 border-2" :class="getNegocioClasses(negocio)">
                                <div class="flex items-center justify-between mb-3">
                                    <div class="flex items-center gap-2">
                                        <i :class="getNegocioIcon(negocio)" class="text-xl"></i>
                                        <span class="font-bold text-lg" x-text="negocio"></span>
                                        <span class="text-xs bg-white/50 dark:bg-black/20 px-2 py-0.5 rounded-full"
                                              x-text="appsDisponiblesPorNegocio(negocio).length + ' apps'"></span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="text-sm font-bold">
                                            ₲ <span x-text="formatNumber(totalPrecioNegocio(negocio))"></span>/mes
                                        </span>
                                        <button @click="agregarNegocioSuscripcion(negocio)"
                                                class="bg-white/80 dark:bg-slate-800/80 hover:bg-white dark:hover:bg-slate-800 text-gray-800 dark:text-white px-3 py-1.5 rounded-lg text-sm font-medium transition-colors shadow">
                                            <i class="fas fa-plus mr-1"></i> Agregar todo
                                        </button>
                                    </div>
                                </div>
                                <div class="text-sm opacity-80">
                                    <span class="font-medium">Incluye: </span>
                                    <span x-text="appsDisponiblesPorNegocio(negocio).map(a => a.nombre).join(', ')"></span>
                                </div>
                            </div>
                        </template>
                        
                        <template x-if="negociosDisponibles.length === 0">
                            <div class="text-center py-8 text-gray-400">
                                <i class="fas fa-building text-3xl mb-3"></i>
                                <p>No hay negocios disponibles para agregar</p>
                            </div>
                        </template>
                    </div>
                </div>
                
                <div class="flex justify-end p-6 pt-4 border-t dark:border-slate-700 flex-shrink-0 bg-gray-50 dark:bg-slate-800/50">
                    <button @click="modalAgregarApp = false; busquedaAppModal = ''; modoAgregarApp = 'individual'" 
                            class="px-4 py-2 text-gray-600 hover:text-gray-800 font-medium">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal: Gestionar Negocios -->
        <div x-show="modalNegocios" x-transition.opacity class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
            <div @click.away="modalNegocios = false" class="bg-white dark:bg-slate-800 rounded-2xl w-[95vw] max-h-[90vh] overflow-hidden flex flex-col">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white">
                        <i class="fas fa-briefcase text-amber-500 mr-2"></i>Gestionar Negocios
                    </h3>
                    <button @click="modalNegocios = false" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times"></i></button>
                </div>
                <div class="p-6 overflow-y-auto grid grid-cols-1 lg:grid-cols-12 gap-4">
                    <div class="lg:col-span-3 space-y-2">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Negocios</p>
                            <button @click="nuevoNegocioPlantilla()"
                                    class="text-xs px-2 py-1 rounded bg-amber-500 hover:bg-amber-600 text-white">
                                + Nuevo
                            </button>
                        </div>
                        <template x-for="neg in negociosPlantilla" :key="'np-'+neg">
                            <div class="w-full px-2 py-2 rounded-lg border text-sm flex items-center justify-between gap-2"
                                 :class="negocioPlantillaSeleccionado===neg ? 'bg-amber-100 text-amber-700 border-amber-300' : 'bg-white dark:bg-slate-700 border-gray-200 dark:border-slate-600 text-gray-700 dark:text-gray-200'">
                                <button @click="seleccionarNegocioPlantilla(neg)" class="text-left flex-1 truncate">
                                    <span x-text="neg"></span>
                                    <span class="ml-2 text-[10px] uppercase tracking-wide"
                                          :class="negocioTipoDisponible(neg) ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'"
                                          x-text="negocioTipoDisponible(neg) ? 'Disponible' : 'En desarrollo'"></span>
                                </button>
                                <div class="flex items-center gap-1">
                                    <button @click="editarNegocioTipo(neg)" class="w-7 h-7 rounded bg-blue-50 hover:bg-blue-100 text-blue-600" title="Editar tipo">
                                        <i class="fas fa-pen text-xs"></i>
                                    </button>
                                    <button @click="suprimirNegocioTipo(neg)" class="w-7 h-7 rounded bg-red-50 hover:bg-red-100 text-red-600" title="Suprimir tipo">
                                        <i class="fas fa-trash text-xs"></i>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="lg:col-span-9 space-y-4" x-show="negocioPlantillaSeleccionado" x-data="{ busquedaDisponibles: '', busquedaAsignadas: '' }">
                        <!-- Header -->
                        <div class="flex items-center justify-between pb-4 border-b border-gray-200 dark:border-slate-700">
                            <div>
                                <h4 class="font-semibold text-gray-800 dark:text-white text-lg" x-text="'Plantilla: ' + negocioPlantillaSeleccionado"></h4>
                                <p class="text-xs text-gray-500 mt-1">Arrastra apps o usa los botones para asignarlos a este negocio.</p>
                            </div>
                            <button @click="guardarNegocioPlantilla()"
                                    class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium whitespace-nowrap">
                                <i class="fas fa-save mr-1"></i> Guardar plantilla
                            </button>
                        </div>

                        <!-- Estado del negocio -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Estado del negocio</span>
                                <select x-model="negocioPlantillaDisponible"
                                        class="mt-1 w-full border rounded-lg px-3 py-2 bg-white dark:bg-slate-700 dark:border-slate-600 text-sm">
                                    <option value="1">✓ Disponible</option>
                                    <option value="0">⚙️ En desarrollo</option>
                                </select>
                            </label>
                        </div>

                        <!-- Dos columnas: Disponibles vs Asignadas -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 h-[400px]">
                            <!-- Columna Izquierda: Apps Disponibles -->
                            <div class="flex flex-col border border-gray-200 dark:border-slate-700 rounded-xl overflow-hidden bg-gray-50 dark:bg-slate-800/50">
                                <div class="px-4 py-3 border-b border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800">
                                    <h5 class="font-semibold text-gray-800 dark:text-white text-sm mb-2 flex items-center justify-between">
                                        <span>
                                            <i class="fas fa-cube text-blue-500 mr-2"></i>Catálogo de Apps
                                        </span>
                                        <span class="text-[11px] font-normal text-gray-500">
                                            <span class="text-green-600 dark:text-green-400"><i class="fas fa-check-circle mr-1"></i>Activo: <span x-text="appsDisponiblesNegocio.filter(a => Number(a.activo) === 1).length"></span></span>
                                            <span class="ml-2 text-red-600 dark:text-red-400"><i class="fas fa-ban mr-1"></i>Anulados: <span x-text="appsDisponiblesNegocio.filter(a => Number(a.activo) === 0).length"></span></span>
                                        </span>
                                    </h5>
                                    <div class="flex gap-2">
                                        <div class="relative flex-1">
                                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                                            <input type="text"
                                                   x-model="busquedaDisponibles"
                                                   placeholder="Buscar apps..."
                                                   class="w-full pl-8 pr-3 py-2 text-xs border border-gray-200 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-800 dark:text-white placeholder-gray-400">
                                        </div>
                                        <select x-model="filtroEstadoPlantilla"
                                                class="px-2 py-2 text-xs border border-gray-200 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-800 dark:text-white">
                                            <option value="">Todos</option>
                                            <option value="1">Activos</option>
                                            <option value="0">Cancelados</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="flex-1 overflow-y-auto p-2 space-y-2">
                                    <template x-for="app in appsDisponiblesNegocioFiltrados" :key="'disp-'+app.id_app">
                                        <div class="p-3 bg-white dark:bg-slate-700 rounded-lg border border-gray-200 dark:border-slate-600 hover:shadow-md transition-all"
                                             draggable="true"
                                             @dragstart="draggedApp = app">
                                            <div class="flex items-start justify-between mb-2">
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-gray-800 dark:text-white truncate" x-text="app.nombre"></p>
                                                    <p class="text-[11px] text-gray-500 dark:text-gray-400 truncate mt-0.5" x-text="app.descripcion || 'Sin descripción'"></p>
                                                </div>
                                            </div>
                                            <div class="text-[10px] space-y-1 mb-2 pb-2 border-b border-gray-200 dark:border-slate-600">
                                                <p class="text-gray-500 dark:text-gray-400 truncate"><strong>Ruta:</strong> <span x-text="app.ruta_app || '-'"></span></p>
                                                <p class="text-gray-500 dark:text-gray-400"><strong>Precio:</strong> <span x-text="'₲' + formatNumber(app.precio_mensual)"></span></p>
                                            </div>
                                            <div class="flex gap-1">
                                                <button type="button"
                                                        @click="agregarAppPlantilla(app)"
                                                        class="flex-1 px-2 py-1 rounded text-xs bg-blue-100 hover:bg-blue-200 text-blue-600 dark:bg-blue-900/30 dark:hover:bg-blue-900/50 transition-colors font-medium">
                                                    <i class="fas fa-plus mr-1"></i>Agregar
                                                </button>
                                                <button type="button"
                                                        @click="editarApp(app)"
                                                        class="flex-1 px-2 py-1 rounded text-xs bg-amber-100 hover:bg-amber-200 text-amber-600 dark:bg-amber-900/30 dark:hover:bg-amber-900/50 transition-colors font-medium">
                                                    <i class="fas fa-edit mr-1"></i>Editar
                                                </button>
                                                <button type="button"
                                                        @click="eliminarApp(app)"
                                                        class="flex-1 px-2 py-1 rounded text-xs bg-red-100 hover:bg-red-200 text-red-600 dark:bg-red-900/30 dark:hover:bg-red-900/50 transition-colors font-medium">
                                                    <i class="fas fa-trash mr-1"></i>Suprimir
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="appsDisponiblesNegocioFiltrados.length === 0">
                                        <div class="flex items-center justify-center h-32 text-gray-400">
                                            <div class="text-center">
                                                <i class="fas fa-inbox text-2xl mb-2 opacity-50"></i>
                                                <p class="text-xs" x-text="busquedaDisponibles ? 'Sin resultados' : 'Todos los apps están asignados'"></p>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Columna Derecha: Apps Asignadas -->
                            <div class="flex flex-col border border-gray-200 dark:border-slate-700 rounded-xl overflow-hidden bg-emerald-50 dark:bg-slate-800/50">
                                <div class="px-4 py-3 border-b border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800">
                                    <h5 class="font-semibold text-gray-800 dark:text-white text-sm mb-2 flex items-center justify-between">
                                        <span>
                                            <i class="fas fa-check-circle text-emerald-500 mr-2"></i>Apps Asignadas
                                        </span>
                                        <span class="text-[11px] font-normal text-gray-500">
                                            <span class="text-green-600 dark:text-green-400"><i class="fas fa-check-circle mr-1"></i>Activo: <span x-text="appsAsignadosFiltrados.filter(a => Number(a.activo) === 1).length"></span></span>
                                            <span class="ml-2 text-red-600 dark:text-red-400"><i class="fas fa-ban mr-1"></i>Anulados: <span x-text="appsAsignadosFiltrados.filter(a => Number(a.activo) === 0).length"></span></span>
                                        </span>
                                    </h5>
                                    <div class="flex gap-2">
                                        <div class="relative flex-1">
                                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                                            <input type="text"
                                                   x-model="busquedaAsignadas"
                                                   placeholder="Buscar asignados..."
                                                   class="w-full pl-8 pr-3 py-2 text-xs border border-gray-200 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-800 dark:text-white placeholder-gray-400">
                                        </div>
                                        <select x-model="filtroEstadoPlantilla"
                                                class="px-2 py-2 text-xs border border-gray-200 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-700 text-gray-800 dark:text-white">
                                            <option value="">Todos</option>
                                            <option value="1">Activos</option>
                                            <option value="0">Cancelados</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="flex-1 overflow-y-auto p-2 space-y-1"
                                     @dragover.prevent="dragOverRight = true"
                                     @dragleave="dragOverRight = false"
                                     @drop.prevent="dragOverRight = false; if(draggedApp) agregarAppPlantilla(draggedApp)">
                                    <template x-for="app in appsAsignadosFiltrados" :key="'asig-'+app.id_app">
                                        <div class="p-3 bg-white dark:bg-slate-700 rounded-lg border border-emerald-200 dark:border-emerald-600/30 hover:shadow-md transition-all">
                                            <div class="flex items-start justify-between mb-2">
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-gray-800 dark:text-white truncate" x-text="app.nombre"></p>
                                                    <p class="text-[11px] text-gray-500 dark:text-gray-400 truncate mt-0.5" x-text="app.descripcion || 'Sin descripción'"></p>
                                                </div>
                                            </div>
                                            <div class="text-[10px] space-y-1 mb-2 pb-2 border-b border-emerald-200 dark:border-emerald-600/30">
                                                <p class="text-gray-500 dark:text-gray-400 truncate"><strong>Ruta:</strong> <span x-text="app.ruta_app || '-'"></span></p>
                                                <p class="text-gray-500 dark:text-gray-400"><strong>Precio:</strong> <span x-text="'₲' + formatNumber(app.precio_mensual)"></span></p>
                                            </div>
                                            <div class="flex gap-1">
                                                <button type="button"
                                                        @click="editarApp(app)"
                                                        class="flex-1 px-2 py-1 rounded text-xs bg-amber-100 hover:bg-amber-200 text-amber-600 dark:bg-amber-900/30 dark:hover:bg-amber-900/50 transition-colors font-medium">
                                                    <i class="fas fa-edit mr-1"></i>Editar
                                                </button>
                                                <button type="button"
                                                        @click="removerAppPlantilla(app.id_app)"
                                                        class="flex-1 px-2 py-1 rounded text-xs bg-red-100 hover:bg-red-200 text-red-600 dark:bg-red-900/30 dark:hover:bg-red-900/50 transition-colors font-medium">
                                                    <i class="fas fa-trash mr-1"></i>Remover
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="appsAsignadosFiltrados.length === 0 && negocioPlantillaAppIds.length === 0">
                                        <div class="flex items-center justify-center h-32 text-gray-400">
                                            <div class="text-center">
                                                <i class="fas fa-inbox text-2xl mb-2 opacity-50"></i>
                                                <p class="text-xs">Sin apps asignadas</p>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="appsAsignadosFiltrados.length === 0 && negocioPlantillaAppIds.length > 0">
                                        <div class="flex items-center justify-center h-32 text-gray-400">
                                            <div class="text-center">
                                                <i class="fas fa-search text-2xl mb-2 opacity-50"></i>
                                                <p class="text-xs">Sin resultados en la búsqueda</p>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- Hidratar empresa por negocio -->
                        <div class="border-t border-gray-200 dark:border-slate-700 pt-4">
                            <h4 class="font-semibold text-gray-800 dark:text-white mb-2 text-sm">
                                <i class="fas fa-bolt text-amber-500 mr-2"></i>Hidratar Empresa
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                                <select x-model="negocioHydrateEmpresa" class="border rounded-lg px-3 py-2 text-sm dark:bg-slate-700 dark:border-slate-600">
                                    <option value="">Seleccionar empresa...</option>
                                    <template x-for="emp in empresas" :key="'h'+emp.id_empresa">
                                        <option :value="Number(emp.id_empresa)" x-text="emp.empresa + ' (#' + emp.id_empresa + ')'"></option>
                                    </template>
                                </select>
                                <div class="md:col-span-2">
                                    <button @click="hidratarEmpresaPorNegocio()"
                                            class="w-full px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium">
                                        <i class="fas fa-bolt mr-1"></i> Aplicar apps de esta plantilla
                                    </button>
                                </div>
                            </div>
                            <p class="text-[11px] text-gray-500 mt-2">
                                Se agregan a la suscripción activa de la empresa los apps de esta plantilla.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-800/50 flex justify-end">
                    <button @click="modalNegocios = false" class="px-4 py-2 text-gray-600 hover:text-gray-800 font-medium">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/heroicons-outline-map.js"></script>
    <script src="assets/js/tabler-icons-map.js"></script>
    <script src="assets/js/hugeicons-map.js"></script>
    <script src="assets/js/material-symbols-map.js"></script>
    <script src="assets/js/unicons-map.js"></script>
    <script>
    function suscripcionesApp() {
        return {
            activeTab: 'suscripciones',
            
            // Suscripciones
            suscripciones: [],
            cargandoSuscripciones: false,
            filtroAnulacion: 'activo',
            filtroEstado: '',
            filtroPago: '',
            sortSusBy: 'periodo',
            sortSusDir: 'desc',

            // Solicitudes públicas
            solicitudes: [],
            cargandoSolicitudes: false,
            filtroSolicitudQ: '',
            filtroSolicitudEstado: '',
            modalSolicitud: false,
            formSolicitud: {
                id_solicitud: 0,
                empresa: '',
                contacto: '',
                email: '',
                telefono: '',
                ruc: '',
                mensaje: '',
                estado: 'nuevo',
                created_at: ''
            },
            
            // Catálogo
            apps: [],
            modalApp: false,
            appEditando: null,
            formApp: {},
            guardarEnProgreso: false,
            busquedaApp: '',
            catalogoVista: 'grid',
            modalNegocios: false,
            negocioTemplatesMap: {},
            negociosTipos: [],
            negocioPlantillaSeleccionado: '',
            negocioPlantillaAppIds: [],
            negocioPlantillaDisponible: '1',
            filtroEstadoPlantilla: '',
            negocioHydrateEmpresa: '',
            modalIconPicker: false,
            iconSearch: '',
            iconCategory: 'all',
            iconosRecientes: [],
            recentIconsKey: 'suscripciones_heroicons_recent',
            customIconsV2: <?= json_encode(array_values(array_map(function($f) {
                $name = basename($f, '.svg');
                $label = ucwords(str_replace('-', ' ', $name));
                return ['class' => $name, 'name' => $label];
            }, glob(__DIR__ . '/assets/images/icons_v2/*.svg') ?: []))) ?>,
            heroiconOptions: [
                { name: 'Cube', class: 'cube', category: 'general' },
                { name: 'Squares', class: 'squares-2x2', category: 'general' },
                { name: 'List', class: 'clipboard-document-list', category: 'general' },
                { name: 'Folder', class: 'folder-open', category: 'general' },
                { name: 'Document', class: 'document-text', category: 'general' },
                { name: 'Duplicate', class: 'document-duplicate', category: 'general' },
                { name: 'Clipboard Check', class: 'clipboard-document-check', category: 'general' },
                { name: 'Calendar', class: 'calendar-days', category: 'general' },
                { name: 'Clock', class: 'clock', category: 'general' },
                { name: 'Bell', class: 'bell', category: 'general' },
                { name: 'Search', class: 'magnifying-glass', category: 'general' },
                { name: 'Filter', class: 'funnel', category: 'general' },
                { name: 'Tag', class: 'tag', category: 'general' },
                { name: 'QR', class: 'qr-code', category: 'general' },
                { name: 'Globe', class: 'globe-alt', category: 'general' },
                { name: 'Map Pin', class: 'map-pin', category: 'general' },
                { name: 'Phone', class: 'phone', category: 'general' },
                { name: 'Mail', class: 'envelope', category: 'general' },
                { name: 'Camera', class: 'camera', category: 'general' },
                { name: 'Photo', class: 'photo', category: 'general' },
                { name: 'Cart', class: 'shopping-cart', category: 'ventas' },
                { name: 'Ticket', class: 'ticket', category: 'ventas' },
                { name: 'Receipt', class: 'receipt-percent', category: 'ventas' },
                { name: 'Building Store', class: 'building-storefront', category: 'ventas' },
                { name: 'Chart', class: 'chart-bar', category: 'finanzas' },
                { name: 'Pie Chart', class: 'chart-pie', category: 'finanzas' },
                { name: 'Presentation', class: 'presentation-chart-line', category: 'finanzas' },
                { name: 'Money', class: 'banknotes', category: 'finanzas' },
                { name: 'Users', class: 'users', category: 'usuarios' },
                { name: 'User Group', class: 'user-group', category: 'usuarios' },
                { name: 'User', class: 'user', category: 'usuarios' },
                { name: 'User Circle', class: 'user-circle', category: 'usuarios' },
                { name: 'Truck', class: 'truck', category: 'inventario' },
                { name: 'Fire', class: 'fire', category: 'inventario' },
                { name: 'Beaker', class: 'beaker', category: 'inventario' },
                { name: 'Archive Box', class: 'archive-box', category: 'inventario' },
                { name: 'Cube Transparent', class: 'cube-transparent', category: 'inventario' },
                { name: 'Wrench', class: 'wrench-screwdriver', category: 'inventario' },
                { name: 'Shield', class: 'shield-check', category: 'sistema' },
                { name: 'Shield Alert', class: 'shield-exclamation', category: 'sistema' },
                { name: 'Lock', class: 'lock-closed', category: 'sistema' },
                { name: 'Key', class: 'key', category: 'sistema' },
                { name: 'Cog', class: 'cog-6-tooth', category: 'sistema' },
                { name: 'Cog 8', class: 'cog-8-tooth', category: 'sistema' },
                { name: 'Server', class: 'server-stack', category: 'sistema' },
                { name: 'CPU', class: 'cpu-chip', category: 'sistema' },
                { name: 'Cloud Up', class: 'cloud-arrow-up', category: 'sistema' },
                { name: 'Cloud Down', class: 'cloud-arrow-down', category: 'sistema' },
                { name: 'Arrow Path', class: 'arrow-path', category: 'sistema' },
                { name: 'Swap', class: 'arrows-right-left', category: 'sistema' },
                { name: 'Play', class: 'play', category: 'general' },
                { name: 'Pause', class: 'pause', category: 'general' },
                { name: 'Sparkles', class: 'sparkles', category: 'general' },
                { name: 'Bolt', class: 'bolt', category: 'general' },
                { name: 'Office', class: 'building-office', category: 'sistema' },
                { name: 'Credit Card', class: 'credit-card', category: 'finanzas' },
                { name: 'Home', class: 'home', category: 'general' },
                { name: 'Logout', class: 'arrow-right-on-rectangle', category: 'general' }
            ],
            heroiconAliases: {
                'document-text': 'clipboard-document-list',
                'document-duplicate': 'clipboard-document-list',
                'clipboard-document-check': 'clipboard-document-list',
                'calendar-days': 'clipboard-document-list',
                'clock': 'chart-bar',
                'bell': 'shield-check',
                'magnifying-glass': 'chart-bar',
                'funnel': 'chart-bar',
                'tag': 'credit-card',
                'qr-code': 'squares-2x2',
                'globe-alt': 'building-office',
                'map-pin': 'building-office',
                'phone': 'user-group',
                'envelope': 'clipboard-document-list',
                'camera': 'squares-2x2',
                'photo': 'squares-2x2',
                'ticket': 'credit-card',
                'receipt-percent': 'credit-card',
                'building-storefront': 'building-office',
                'chart-pie': 'chart-bar',
                'presentation-chart-line': 'chart-bar',
                'user': 'users',
                'user-circle': 'users',
                'archive-box': 'cube',
                'cube-transparent': 'cube',
                'wrench-screwdriver': 'cog-6-tooth',
                'shield-exclamation': 'shield-check',
                'lock-closed': 'shield-check',
                'key': 'shield-check',
                'cog-8-tooth': 'cog-6-tooth',
                'server-stack': 'building-office',
                'cpu-chip': 'cog-6-tooth',
                'cloud-arrow-up': 'arrow-right-on-rectangle',
                'cloud-arrow-down': 'arrow-right-on-rectangle',
                'arrow-path': 'cog-6-tooth',
                'arrows-right-left': 'arrow-right-on-rectangle',
                'play': 'arrow-right-on-rectangle',
                'pause': 'squares-2x2',
                'sparkles': 'fire',
                'bolt': 'fire'
            },
            iconSource: 'custom',
            tablerOptions: [],
            tablerMap: {},
            hugeOptions: [],
            hugeMap: {},
            materialOptions: [],
            materialMap: {},
            uniconsOptions: [],
            uniconsMap: {},
            heroiconsMap: {
                'shopping-cart': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z"/></svg>',
                'fire': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.362 5.214A8.252 8.252 0 0 1 12 21 8.25 8.25 0 0 1 6.038 7.047 8.287 8.287 0 0 0 9 9.601a8.983 8.983 0 0 1 3.361-6.867 8.21 8.21 0 0 0 3 2.48Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 18a3.75 3.75 0 0 0 .495-7.468 5.99 5.99 0 0 0-1.925 3.547 5.975 5.975 0 0 1-2.133-1.001A3.75 3.75 0 0 0 12 18Z"/></svg>',
                'chart-bar': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>',
                'truck': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/></svg>',
                'cube': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9"/></svg>',
                'banknotes': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z"/></svg>',
                'beaker': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M19.8 15.3l.21 1.847a2.252 2.252 0 0 1-1.809 2.498l-7.076 1.18a2.25 2.25 0 0 1-2.25-1.312L5 15.3"/></svg>',
                'folder-open': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776"/></svg>',
                'users': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128A12.318 12.318 0 0 1 8.624 21a12.318 12.318 0 0 1-6.374-1.766 6.375 6.375 0 0 1 11.964-3.07"/></svg>',
                'user-group': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198A11.944 11.944 0 0 1 12 21a11.944 11.944 0 0 1-5.963-1.584m12 0a5.971 5.971 0 0 0-.941-3.197A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772"/></svg>',
                'clipboard-document-list': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75M8.25 8.25H4.875A1.125 1.125 0 0 0 3.75 9.375v11.25A1.125 1.125 0 0 0 4.875 21.75h9.75a1.125 1.125 0 0 0 1.125-1.125V9.375A1.125 1.125 0 0 0 14.625 8.25H11.25"/></svg>',
                'squares-2x2': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25A2.25 2.25 0 0 1 8.25 10.5H6A2.25 2.25 0 0 1 3.75 8.25V6ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25A2.25 2.25 0 0 1 13.5 8.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 8.25 20.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/></svg>',
                'shield-check': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6A11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>',
                'cog-6-tooth': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>',
                'building-office': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>',
                'arrow-right-on-rectangle': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/></svg>',
                'credit-card': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z"/></svg>',
                'home': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75"/></svg>'
            },
            
            // Por empresa
            empresas: [],
            empresaSeleccionada: '',
            suscripcionEmpresa: null,
            cargandoEmpresa: false,
            editandoPeriodoSuscripcion: false,
            periodoEditInicio: '',
            periodoEditFin: '',
            periodoEditVencimiento: '',
            modalAgregarApp: false,
            busquedaAppModal: '',
            modoAgregarApp: 'individual',
            
            // Buscador de empresas
            busquedaEmpresa: '',
            mostrarResultados: false,
            empresaActual: null,
            gridSearch: '',
            themeMode: (document.documentElement.classList.contains('dark') || document.documentElement.getAttribute('data-bs-theme') === 'dark' || localStorage.getItem('theme') === 'dark') ? 'dark' : 'light',
            gridApiSuscripciones: null,
            gridApiSolicitudes: null,
            _licenseApplied: false,
            _gridEventsBound: false,
            _themeObserverBound: false,
            _gridResizeBound: false,
            
            async init() {
                if (window.__agGridReady) await window.__agGridReady;
                if (this.activeTab === 'solicitudes') {
                    this.activeTab = 'suscripciones';
                }
                this.cargarHeroiconsLocales();
                this.cargarTablerLocales();
                this.cargarHugeLocales();
                this.cargarMaterialLocales();
                this.cargarUniconsLocales();
                this.loadRecentIcons();
                this.attachGridEvents();
                this.attachThemeObserver();
                this.attachResizeHandler();
                await this.cargarApps();
                await this.cargarSuscripciones();
                await this.cargarSolicitudes();
                await this.cargarEmpresas();
                await this.cargarNegocioTipos();
                await this.cargarNegocioTemplates();
                this.ensureGrid('suscripciones');
                this.ensureGrid('solicitudes');
                this.$nextTick(() => this.resizeActiveGrid());
            },

            setTab(tab) {
                if (tab === 'solicitudes') {
                    tab = 'suscripciones';
                }
                this.activeTab = tab;
                if (tab === 'suscripciones') {
                    this.ensureGrid(tab);
                    this.$nextTick(() => {
                        this.resizeActiveGrid();
                        this.applyQuickFilter();
                    });
                }
            },

            _detectDark() {
                const el = document.documentElement;
                return el.classList.contains('dark')
                    || String(el.getAttribute('data-bs-theme') || '').toLowerCase() === 'dark'
                    || String(localStorage.getItem('theme') || '').toLowerCase() === 'dark';
            },
            syncGridThemeClasses() {
                const themeClass = this.themeMode === 'dark' ? 'ag-theme-quartz-dark' : 'ag-theme-quartz';
                const removeClass = this.themeMode === 'dark' ? 'ag-theme-quartz' : 'ag-theme-quartz-dark';
                [this.$refs.gridSuscripciones, this.$refs.gridSolicitudes].forEach((el) => {
                    if (!el) return;
                    el.classList.remove(removeClass);
                    if (!el.classList.contains(themeClass)) el.classList.add(themeClass);
                });
            },
            applyThemeToGrids() {
                this.syncGridThemeClasses();
                [this.gridApiSuscripciones, this.gridApiSolicitudes].forEach((api) => {
                    if (!api) return;
                    try { api.refreshHeader(); api.redrawRows(); } catch (_) {}
                });
                this.$nextTick(() => this.resizeActiveGrid());
            },
            attachThemeObserver() {
                if (this._themeObserverBound) return;
                this._themeObserverBound = true;
                const applyTheme = () => {
                    const next = this._detectDark() ? 'dark' : 'light';
                    if (next !== this.themeMode) {
                        this.themeMode = next;
                        this.applyThemeToGrids();
                    }
                };
                this._themeObserver = new MutationObserver(() => applyTheme());
                this._themeObserver.observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ['class', 'data-bs-theme', 'data-theme']
                });
                window.addEventListener('storage', (e) => { if (e.key === 'theme') applyTheme(); });
                applyTheme();
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
            resizeActiveGrid() {
                if (this.activeTab === 'suscripciones' && this.gridApiSuscripciones) {
                    this.gridApiSuscripciones.sizeColumnsToFit();
                }
            },
            applyQuickFilter() {
                if (this.activeTab !== 'suscripciones') return;
                this._resetInfiniteDatasource(this.activeTab);
            },
            _gridRows(tabKey) {
                if (tabKey === 'suscripciones') {
                    const rows = Array.isArray(this.suscripciones) ? this.suscripciones : [];
                    if (this.filtroAnulacion === 'anulado') {
                        return rows.filter(r => String(r?.estado || '').trim().toLowerCase() === 'cancelada');
                    }
                    if (this.filtroAnulacion === 'activo') {
                        return rows.filter(r => String(r?.estado || '').trim().toLowerCase() !== 'cancelada');
                    }
                    return rows;
                }
                if (tabKey === 'solicitudes') return Array.isArray(this.solicitudes) ? this.solicitudes : [];
                return [];
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
                    const n = Number(value), f = Number(cond?.filter), to = Number(cond?.filterTo);
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
                for (const [field, model] of Object.entries(filterModel)) {
                    const value = row ? row[field] : '';
                    if (model && Array.isArray(model.conditions) && model.conditions.length) {
                        const op = String(model.operator || 'AND').toUpperCase();
                        const checks = model.conditions.map(c => this._valuePassesCondition(value, c));
                        if (!(op === 'OR' ? checks.some(Boolean) : checks.every(Boolean))) return false;
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
                        const av = a?.[colId], bv = b?.[colId];
                        const an = Number(av), bn = Number(bv);
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
                const api = tabKey === 'suscripciones' ? this.gridApiSuscripciones : this.gridApiSolicitudes;
                if (!api) return;
                const self = this;
                api.setGridOption('datasource', {
                    rowCount: undefined,
                    getRows(params) {
                        const rows = self._gridRows(tabKey);
                        const search = self.activeTab === tabKey ? String(self.gridSearch || '').trim().toLowerCase() : '';
                        const filtered = rows.filter((row) => {
                            if (search) {
                                const haystack = Object.values(row || {}).map(v => String(v ?? '').toLowerCase()).join(' ');
                                if (!haystack.includes(search)) return false;
                            }
                            return self._rowPassesFilterModel(row, params.filterModel);
                        });
                        const sorted = self._sortRows(filtered, params.sortModel);
                        const start = Math.max(0, Number(params.startRow || 0));
                        const end = Math.max(start, Number(params.endRow || start + 100));
                        const pageRows = sorted.slice(start, end);
                        const lastRow = end >= sorted.length ? sorted.length : -1;
                        params.successCallback(pageRows, lastRow);
                    }
                });
            },
            refreshGridData(tabKey, rows) {
                if (!Array.isArray(rows)) rows = [];
                const api = tabKey === 'suscripciones' ? this.gridApiSuscripciones : this.gridApiSolicitudes;
                if (!api) return;
                if (typeof api.deselectAll === 'function') {
                    api.deselectAll();
                }
                this._resetInfiniteDatasource(tabKey);
                if (typeof api.purgeInfiniteCache === 'function') {
                    api.purgeInfiniteCache();
                } else if (typeof api.refreshInfiniteCache === 'function') {
                    api.refreshInfiniteCache();
                }
            },
            _gridCommon() {
                return {
                    rowModelType: 'infinite',
                    cacheBlockSize: 80,
                    maxBlocksInCache: 6,
                    maxConcurrentDatasourceRequests: 2,
                    blockLoadDebounceMillis: 120,
                    infiniteInitialRowCount: 1,
                    rowBuffer: 8,
                    pagination: false,
                    animateRows: true,
                    enableCellTextSelection: true,
                    ensureDomOrder: true,
                    columnMenu: 'new',
                    defaultColDef: { sortable: true, resizable: true, filter: true, flex: 1 },
                    sideBar: {
                        toolPanels: [
                            { id: 'columns', labelDefault: 'Columnas', labelKey: 'columns', iconKey: 'columns', toolPanel: 'agColumnsToolPanel' },
                            { id: 'filters', labelDefault: 'Filtros', labelKey: 'filters', iconKey: 'filter', toolPanel: 'agFiltersToolPanel' }
                        ],
                        defaultToolPanel: ''
                    }
                };
            },
            _contextMenuItems(tabKey, params) {
                const row = params?.node?.data;
                if (!row) return params?.defaultItems || [];
                const items = [];
                if (tabKey === 'suscripciones') {
                    items.push({
                        name: 'Ver detalle',
                        icon: '<i class="fas fa-eye text-blue-600"></i>',
                        action: () => this.verSuscripcion(row)
                    });
                    const anulada = String(row.estado || '') === 'cancelada';
                    items.push({
                        name: anulada ? 'Desanular suscripción' : 'Anular suscripción',
                        icon: anulada
                            ? '<i class="fas fa-rotate-left text-emerald-600"></i>'
                            : '<i class="fas fa-ban text-red-600"></i>',
                        action: () => this.toggleAnulacionSuscripcion(row, !anulada)
                    });
                    items.push({
                        name: 'Suprimir suscripción',
                        icon: '<i class="fas fa-trash text-red-700"></i>',
                        cssClasses: ['text-red-700'],
                        action: () => this.suprimirSuscripcionEmpresa(row)
                    });
                    if (String(row.estado_pago || '') !== 'pagado') {
                        items.push({
                            name: 'Marcar pagado',
                            icon: '<i class="fas fa-check-circle text-green-600"></i>',
                            action: () => this.marcarPagado(Number(row.id_suscripcion || 0))
                        });
                    }
                } else if (tabKey === 'solicitudes') {
                    items.push({
                        name: 'Editar solicitud',
                        icon: '<i class="fas fa-pen text-indigo-600"></i>',
                        action: () => this.abrirSolicitud(row)
                    });
                    items.push({
                        name: 'Suprimir solicitud',
                        icon: '<i class="fas fa-trash text-red-600"></i>',
                        cssClasses: ['text-red-600'],
                        action: () => this.suprimirSolicitud(Number(row.id_solicitud || 0))
                    });
                    items.push('separator');
                    const estadoActual = String(row.estado || 'nuevo');
                    ['nuevo', 'contactado', 'cerrado'].forEach((estado) => {
                        if (estado === estadoActual) return;
                        items.push({
                            name: `Marcar: ${estado}`,
                            icon: '<i class="fas fa-pen text-orange-600"></i>',
                            action: () => this.actualizarEstadoSolicitud(row, estado)
                        });
                    });
                }
                items.push('separator');
                return [...items, ...(params?.defaultItems || [])];
            },
            _columnDefs(tabKey) {
                if (tabKey === 'suscripciones') {
                    return [
                        {
                            headerName: '',
                            field: '__select__',
                            minWidth: 52,
                            maxWidth: 52,
                            width: 52,
                            pinned: 'left',
                            lockPinned: true,
                            sortable: false,
                            filter: false,
                            resizable: false,
                            checkboxSelection: true,
                            headerCheckboxSelection: true,
                            headerCheckboxSelectionFilteredOnly: true
                        },
                        { field: 'nombre_empresa', headerName: 'Empresa', minWidth: 180, flex: 1, cellRenderer: p => `<div><div style="font-weight:600;">${p.data?.nombre_empresa || '-'}</div><div style="font-size:11px;opacity:.7">${p.data?.ruc || ''}</div></div>` },
                        { field: 'id_empresa', headerName: 'ID Empresa', minWidth: 120, hide: true },
                        { field: 'dbase', headerName: 'DBASE', minWidth: 150, hide: true },
                        { field: 'nro_factura', headerName: 'Factura', minWidth: 130 },
                        { headerName: 'Período', minWidth: 170, valueGetter: p => `${this.formatDate(p.data?.periodo_inicio)} - ${this.formatDate(p.data?.periodo_fin)}` },
                        { field: 'total', headerName: 'Total', minWidth: 110, valueFormatter: p => '₲ ' + this.formatNumber(p.value), cellStyle: { textAlign: 'right', fontWeight: '600' } },
                        { field: 'estado', headerName: 'Estado', minWidth: 110, cellRenderer: p => {
                            const s = String(p.value || '');
                            const map = { activa:['#dcfce7','#166534'], gracia:['#fef3c7','#92400e'], vencida:['#fee2e2','#991b1b'], cancelada:['#e5e7eb','#374151'] };
                            const c = map[s] || ['#e5e7eb','#374151'];
                            return `<span style="padding:2px 8px;border-radius:999px;background:${c[0]};color:${c[1]};font-size:11px;font-weight:700">${s}</span>`;
                        }},
                        { field: 'estado_pago', headerName: 'Pago', minWidth: 110, cellRenderer: p => {
                            const s = String(p.value || '');
                            const map = { pagado:['#dcfce7','#166534'], pendiente:['#fef3c7','#92400e'], atrasado:['#fee2e2','#991b1b'] };
                            const c = map[s] || ['#e5e7eb','#374151'];
                            return `<span style="padding:2px 8px;border-radius:999px;background:${c[0]};color:${c[1]};font-size:11px;font-weight:700">${s}</span>`;
                        }},
                        { field: 'cantidad_apps', headerName: 'Apps', minWidth: 90, cellStyle: { textAlign: 'center', fontWeight: '600' } },
                        { headerName: 'Acciones', minWidth: 130, filter: false, sortable: false, cellRenderer: p => {
                            const id = Number(p.data?.id_suscripcion || 0);
                            const showPagado = String(p.data?.estado_pago || '') !== 'pagado';
                            const anulada = String(p.data?.estado || '') === 'cancelada';
                            return `<div style="display:flex;gap:8px;">
                                <button class="js-sus-ver text-blue-600" data-id="${id}" title="Ver"><i class="fas fa-eye"></i></button>
                                <button class="js-sus-toggle text-${anulada ? 'emerald' : 'red'}-600" data-id="${id}" data-anular="${anulada ? 0 : 1}" title="${anulada ? 'Desanular' : 'Anular'}"><i class="fas fa-${anulada ? 'rotate-left' : 'ban'}"></i></button>
                                ${showPagado ? `<button class="js-sus-pagado text-green-600" data-id="${id}" title="Marcar pagado"><i class="fas fa-check-circle"></i></button>` : ''}
                            </div>`;
                        }}
                    ];
                }
                if (tabKey === 'solicitudes') {
                    return [
                        { field: 'created_at', headerName: 'Fecha', minWidth: 150, valueFormatter: p => this.formatDateTime(p.value) },
                        { field: 'empresa', headerName: 'Empresa', minWidth: 180, flex: 1, cellRenderer: p => `<div><div style="font-weight:600;">${p.data?.empresa || '-'}</div><div style="font-size:11px;opacity:.7">${p.data?.ruc || ''}</div></div>` },
                        { field: 'contacto', headerName: 'Contacto', minWidth: 140 },
                        { field: 'email', headerName: 'Email', minWidth: 180 },
                        { field: 'telefono', headerName: 'WhatsApp', minWidth: 130 },
                        { field: 'estado', headerName: 'Estado', minWidth: 110, cellRenderer: p => {
                            const s = String(p.value || '');
                            const map = { nuevo:['#fef3c7','#92400e'], contactado:['#dbeafe','#1e3a8a'], cerrado:['#dcfce7','#166534'] };
                            const c = map[s] || ['#e5e7eb','#374151'];
                            return `<span style="padding:2px 8px;border-radius:999px;background:${c[0]};color:${c[1]};font-size:11px;font-weight:700">${s}</span>`;
                        }},
                        { headerName: 'Acción', minWidth: 210, filter: false, sortable: false, cellRenderer: p => {
                            const id = Number(p.data?.id_solicitud || 0);
                            const estado = String(p.data?.estado || 'nuevo');
                            return `<div style="display:flex;align-items:center;gap:8px;">
                                <button class="js-sol-edit text-indigo-600" data-id="${id}" title="Editar"><i class="fas fa-pen"></i></button>
                                <select class="js-sol-estado px-2 py-1 border rounded text-xs" data-id="${id}">
                                    <option value="nuevo" ${estado==='nuevo'?'selected':''}>nuevo</option>
                                    <option value="contactado" ${estado==='contactado'?'selected':''}>contactado</option>
                                    <option value="cerrado" ${estado==='cerrado'?'selected':''}>cerrado</option>
                                </select>
                            </div>`;
                        }}
                    ];
                }
                return [];
            },
            _applyLicense() {
                if (this._licenseApplied) return;
                if (typeof agGrid !== 'undefined' && agGrid.LicenseManager) {
                    agGrid.LicenseManager.setLicenseKey('DownloadDevTools_COM_NDEwMjM0NTgwMDAwMA==59158b5225400879a12a96634544f5b6');
                    this._licenseApplied = true;
                }
            },
            ensureGrid(tabKey) {
                if (!window.agGrid || (tabKey !== 'suscripciones' && tabKey !== 'solicitudes')) return;
                this._applyLicense();
                const apiProp = tabKey === 'suscripciones' ? 'gridApiSuscripciones' : 'gridApiSolicitudes';
                if (this[apiProp]) {
                    this.refreshGridData(tabKey, this._gridRows(tabKey));
                    return;
                }
                const gridEl = tabKey === 'suscripciones' ? this.$refs.gridSuscripciones : this.$refs.gridSolicitudes;
                if (!gridEl) return;
                const gridOpts = {
                    ...this._gridCommon(),
                    localeText: window.SmxAgGridLocale?.getLocaleText?.() || {},
                    rowSelection: tabKey === 'suscripciones' ? 'multiple' : undefined,
                    columnDefs: this._columnDefs(tabKey),
                    getRowClass: (params) => {
                        if (tabKey === 'suscripciones' && String(params?.data?.estado || '') === 'cancelada') {
                            return 'sus-row-anulada';
                        }
                        return '';
                    },
                    getContextMenuItems: (params) => this._contextMenuItems(tabKey, params),
                    onGridReady: () => this.$nextTick(() => {
                        this.syncGridThemeClasses();
                        this.refreshGridData(tabKey, this._gridRows(tabKey));
                        this.resizeActiveGrid();
                    })
                };
                this[apiProp] = agGrid.createGrid(gridEl, gridOpts);
            },
            attachGridEvents() {
                if (this._gridEventsBound) return;
                this._gridEventsBound = true;
                document.addEventListener('click', (ev) => {
                    const verBtn = ev.target?.closest?.('.js-sus-ver');
                    if (verBtn) {
                        const id = Number(verBtn.dataset.id || 0);
                        const row = (this.suscripciones || []).find(x => Number(x.id_suscripcion) === id);
                        if (row) this.verSuscripcion(row);
                        return;
                    }
                    const toggleBtn = ev.target?.closest?.('.js-sus-toggle');
                    if (toggleBtn) {
                        const id = Number(toggleBtn.dataset.id || 0);
                        const row = (this.suscripciones || []).find(x => Number(x.id_suscripcion) === id);
                        if (row) this.toggleAnulacionSuscripcion(row, Number(toggleBtn.dataset.anular || 0) === 1);
                        return;
                    }
                    const pagadoBtn = ev.target?.closest?.('.js-sus-pagado');
                    if (pagadoBtn) {
                        const id = Number(pagadoBtn.dataset.id || 0);
                        if (id > 0) this.marcarPagado(id);
                        return;
                    }
                    const editSolBtn = ev.target?.closest?.('.js-sol-edit');
                    if (editSolBtn) {
                        const id = Number(editSolBtn.dataset.id || 0);
                        const row = (this.solicitudes || []).find(x => Number(x.id_solicitud) === id);
                        if (row) this.abrirSolicitud(row);
                    }
                });
                document.addEventListener('change', (ev) => {
                    const sel = ev.target?.classList?.contains?.('js-sol-estado') ? ev.target : null;
                    if (!sel) return;
                    const id = Number(sel.dataset.id || 0);
                    const row = (this.solicitudes || []).find(x => Number(x.id_solicitud) === id);
                    if (row) this.actualizarEstadoSolicitud(row, String(sel.value || ''));
                });
            },
            
            // Formateo
            formatNumber(num) {
                return new Intl.NumberFormat('es-PY').format(num || 0);
            },
            formatDate(dateStr) {
                if (!dateStr) return '';
                const d = new Date(dateStr);
                return d.toLocaleDateString('es-PY', { day: '2-digit', month: '2-digit', year: 'numeric' });
            },
            formatDateTime(dateStr) {
                if (!dateStr) return '';
                const d = new Date(dateStr);
                return d.toLocaleDateString('es-PY', { day: '2-digit', month: '2-digit', year: 'numeric' }) +
                    ' ' +
                    d.toLocaleTimeString('es-PY', { hour: '2-digit', minute: '2-digit' });
            },

            setSortSus(field) {
                if (this.sortSusBy === field) {
                    this.sortSusDir = this.sortSusDir === 'asc' ? 'desc' : 'asc';
                } else {
                    this.sortSusBy = field;
                    this.sortSusDir = field === 'periodo' ? 'desc' : 'asc';
                }
            },

            sortIconSus(field) {
                if (this.sortSusBy !== field) return '↕';
                return this.sortSusDir === 'asc' ? '↑' : '↓';
            },

            valorSortSus(s, field) {
                switch (field) {
                    case 'empresa':
                        return (s.nombre_empresa || '').toLowerCase();
                    case 'factura':
                        return (s.nro_factura || '').toLowerCase();
                    case 'periodo':
                        return new Date(s.periodo_inicio || 0).getTime();
                    case 'total':
                        return Number(s.total || 0);
                    case 'estado':
                        return (s.estado || '').toLowerCase();
                    case 'pago':
                        return (s.estado_pago || '').toLowerCase();
                    case 'apps':
                        return Number(s.cantidad_apps || 0);
                    default:
                        return '';
                }
            },

            suscripcionesOrdenadas() {
                const rows = Array.isArray(this.suscripciones) ? [...this.suscripciones] : [];
                const field = this.sortSusBy;
                const dir = this.sortSusDir === 'asc' ? 1 : -1;
                return rows.sort((a, b) => {
                    const av = this.valorSortSus(a, field);
                    const bv = this.valorSortSus(b, field);
                    if (av < bv) return -1 * dir;
                    if (av > bv) return 1 * dir;
                    return 0;
                });
            },
            
            // API calls
            async cargarApps() {
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=apps');
                    const data = await res.json();
                    if (data.success) {
                        this.apps = (Array.isArray(data.data) ? data.data : []).map((app) => ({
                            ...app,
                            id_app: Number(app.id_app ?? app.id ?? 0),
                            activo: Number(app.activo ?? 0),
                            obligatoria: Number(app.obligatoria ?? 0),
                            precio_mensual: Number(app.precio_mensual ?? 0)
                        }));
                    }
                } catch (e) {
                    console.error('Error cargando apps:', e);
                }
            },

            async cargarNegocioTemplates() {
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=negocio_templates');
                    const data = await res.json();
                    if (data.success) {
                        this.negocioTemplatesMap = data.map || {};
                        if (!this.negocioPlantillaSeleccionado) {
                            const ks = Object.keys(this.negocioTemplatesMap || {});
                            if (ks.length) {
                                this.negocioPlantillaSeleccionado = ks[0];
                                this.negocioPlantillaAppIds = [...(this.negocioTemplatesMap[this.negocioPlantillaSeleccionado] || [])];
                            }
                        } else {
                            this.negocioPlantillaAppIds = [...(this.negocioTemplatesMap[this.negocioPlantillaSeleccionado] || [])];
                        }
                    }
                } catch (e) {
                    console.error('Error cargando plantillas de negocio:', e);
                }
            },

            async cargarNegocioTipos() {
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=negocio_tipos');
                    const data = await res.json();
                    if (data.success) {
                        this.negociosTipos = Array.isArray(data.data) ? data.data : [];
                    }
                } catch (e) {
                    console.error('Error cargando tipos de negocio:', e);
                }
            },
            
            async cargarSuscripciones() {
                this.cargandoSuscripciones = true;
                try {
                    let url = 'api/v1/suscripciones.php?action=suscripciones_todas&all=1';
                    if (this.filtroAnulacion) url += '&estado_registro=' + this.filtroAnulacion;
                    if (this.filtroEstado) url += '&estado=' + this.filtroEstado;
                    if (this.filtroPago) url += '&estado_pago=' + this.filtroPago;
                    
                    const res = await fetch(url);
                    const data = await res.json();
                    if (data.success) {
                        this.suscripciones = Array.isArray(data.data) ? data.data : [];
                    } else {
                        this.suscripciones = [];
                    }
                    this.refreshGridData('suscripciones', this.suscripciones);
                } catch (e) {
                    console.error('Error cargando suscripciones:', e);
                    this.suscripciones = [];
                    this.refreshGridData('suscripciones', this.suscripciones);
                } finally {
                    this.cargandoSuscripciones = false;
                }
            },

            async toggleAnulacionSuscripcion(item, anular = true) {
                const idSuscripcion = Number(item?.id_suscripcion || 0);
                if (idSuscripcion <= 0) return;
                const ok = await $confirm(
                    anular ? 'Anular suscripción' : 'Desanular suscripción',
                    anular
                        ? `Se anulará la suscripción ${item?.nro_factura || ('#' + idSuscripcion)}. ¿Continuar?`
                        : `Se desanulará la suscripción ${item?.nro_factura || ('#' + idSuscripcion)}. ¿Continuar?`,
                    {
                        dangerous: anular,
                        confirmText: anular ? 'Sí, anular' : 'Sí, desanular',
                        icon: anular ? 'fas fa-ban' : 'fas fa-rotate-left'
                    }
                );
                if (!ok) return;
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=toggle_anulacion_suscripcion', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            id_suscripcion: idSuscripcion,
                            anular: anular ? 1 : 0
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        await this.cargarSuscripciones();
                        $notify.success(anular ? 'Anulada' : 'Desanulada', data.message || 'Estado actualizado');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo actualizar la suscripción');
                    }
                } catch (e) {
                    console.error('Error alternando anulación de suscripción:', e);
                    $notify.error('Error', 'Error de conexión');
                }
            },

            async suprimirSuscripcionEmpresa(item) {
                const idEmpresa = Number(item?.id_empresa || 0);
                const idSuscripcion = Number(item?.id_suscripcion || 0);
                const empresa = String(item?.nombre_empresa || '').trim();
                const ruc = String(item?.ruc || '').trim();
                if (idEmpresa <= 0) {
                    $notify.error('Error', 'No se encontró la empresa de esta suscripción');
                    return;
                }
                const texto = window.prompt(
                    `Esta acción suprime completamente la empresa ${empresa || ('#' + idEmpresa)}, su base de datos y sus suscripciones.\n\nEscriba el RUC exacto para continuar:`,
                    ruc
                );
                if (texto === null) return;
                if (String(texto).trim() !== ruc) {
                    $notify.error('Error', 'Confirmación incorrecta. Debe escribir el RUC exacto.');
                    return;
                }
                try {
                    const res = await fetch('/public/empresa/editar_empresa.php?action=delete', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            id_empresa: idEmpresa,
                            id_suscripcion: idSuscripcion,
                            confirmacion: ruc
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.suscripciones = (this.suscripciones || []).filter((row) => {
                            const rowEmpresa = Number(row?.id_empresa || 0);
                            const rowSuscripcion = Number(row?.id_suscripcion || 0);
                            if (rowEmpresa > 0 && rowEmpresa === idEmpresa) return false;
                            if (idSuscripcion > 0 && rowSuscripcion === idSuscripcion) return false;
                            return true;
                        });
                        this.refreshGridData('suscripciones', this.suscripciones);
                        await this.cargarSuscripciones();
                        await this.cargarEmpresas();
                        $notify.success('Suprimida', data.message || 'Empresa y suscripción eliminadas');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo suprimir la empresa');
                    }
                } catch (e) {
                    console.error('Error suprimiendo empresa desde suscripciones:', e);
                    $notify.error('Error', 'Error de conexión');
                }
            },

            async procesarSuscripcionesSeleccionadas(anular = true) {
                const api = this.gridApiSuscripciones;
                if (!api) return;
                const selectedRows = (api.getSelectedRows ? api.getSelectedRows() : []).filter(Boolean);
                if (!selectedRows.length) {
                    $notify.error('Error', 'Selecciona al menos una suscripción');
                    return;
                }
                const ok = await $confirm(
                    anular ? 'Anular seleccionadas' : 'Desanular seleccionadas',
                    anular
                        ? `Se anularán ${selectedRows.length} suscripciones seleccionadas. ¿Continuar?`
                        : `Se desanularán ${selectedRows.length} suscripciones seleccionadas. ¿Continuar?`,
                    {
                        dangerous: anular,
                        confirmText: anular ? 'Sí, anular' : 'Sí, desanular',
                        icon: anular ? 'fas fa-ban' : 'fas fa-rotate-left'
                    }
                );
                if (!ok) return;

                let okCount = 0;
                let failCount = 0;
                for (const row of selectedRows) {
                    try {
                        const res = await fetch('api/v1/suscripciones.php?action=toggle_anulacion_suscripcion', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                id_suscripcion: Number(row.id_suscripcion || 0),
                                anular: anular ? 1 : 0
                            })
                        });
                        const data = await res.json();
                        if (data.success) {
                            okCount++;
                        } else {
                            failCount++;
                        }
                    } catch (e) {
                        failCount++;
                    }
                }

                await this.cargarSuscripciones();
                if (okCount > 0) {
                    $notify.success(
                        anular ? 'Anuladas' : 'Desanuladas',
                        `${okCount} procesadas${failCount > 0 ? `, ${failCount} con error` : ''}`
                    );
                } else {
                    $notify.error('Error', 'No se pudieron procesar las suscripciones seleccionadas');
                }
            },

            seleccionarTodasSuscripciones() {
                const api = this.gridApiSuscripciones;
                if (!api?.forEachNode) return;
                api.forEachNode((node) => {
                    node.setSelected(true);
                });
            },

            deseleccionarTodasSuscripciones() {
                const api = this.gridApiSuscripciones;
                if (!api?.deselectAll) return;
                api.deselectAll();
            },
            
            async cargarEmpresas() {
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=empresas');
                    const data = await res.json();
                    if (data.success) this.empresas = data.data;
                } catch (e) {
                    console.error('Error cargando empresas:', e);
                }
            },

            async cargarSolicitudes() {
                this.cargandoSolicitudes = true;
                try {
                    const params = new URLSearchParams({
                        action: 'solicitudes',
                        q: this.filtroSolicitudQ || '',
                        estado: this.filtroSolicitudEstado || ''
                    });
                    const res = await fetch('api/v1/suscripciones.php?' + params.toString());
                    const data = await res.json();
                    if (data.success) {
                        this.solicitudes = data.data || [];
                        this.refreshGridData('solicitudes', this.solicitudes);
                    } else {
                        this.solicitudes = [];
                        this.refreshGridData('solicitudes', this.solicitudes);
                        $notify.error('Error', data.error || 'No se pudieron cargar solicitudes');
                    }
                } catch (e) {
                    console.error('Error cargando solicitudes:', e);
                    this.solicitudes = [];
                    this.refreshGridData('solicitudes', this.solicitudes);
                } finally {
                    this.cargandoSolicitudes = false;
                }
            },

            async actualizarEstadoSolicitud(item, estado) {
                if (!item || !item.id_solicitud || !estado) return;
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=solicitud_estado', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id_solicitud: item.id_solicitud, estado })
                    });
                    const data = await res.json();
                    if (data.success) {
                        item.estado = estado;
                        this.refreshGridData('solicitudes', this.solicitudes);
                        $notify.success('Actualizado', 'Estado de solicitud actualizado');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo actualizar');
                        await this.cargarSolicitudes();
                    }
                } catch (e) {
                    console.error('Error actualizando solicitud:', e);
                    $notify.error('Error', 'Error de conexión');
                    await this.cargarSolicitudes();
                }
            },
            abrirSolicitud(item) {
                if (!item) return;
                this.formSolicitud = {
                    id_solicitud: Number(item.id_solicitud || 0),
                    empresa: String(item.empresa || ''),
                    contacto: String(item.contacto || ''),
                    email: String(item.email || ''),
                    telefono: String(item.telefono || ''),
                    ruc: String(item.ruc || ''),
                    mensaje: String(item.mensaje || ''),
                    estado: String(item.estado || 'nuevo'),
                    created_at: String(item.created_at || '')
                };
                this.modalSolicitud = true;
            },
            async guardarSolicitud() {
                const payload = {
                    id_solicitud: Number(this.formSolicitud.id_solicitud || 0),
                    empresa: String(this.formSolicitud.empresa || '').trim(),
                    contacto: String(this.formSolicitud.contacto || '').trim(),
                    email: String(this.formSolicitud.email || '').trim(),
                    telefono: String(this.formSolicitud.telefono || '').trim(),
                    ruc: String(this.formSolicitud.ruc || '').trim(),
                    mensaje: String(this.formSolicitud.mensaje || '').trim(),
                    estado: String(this.formSolicitud.estado || 'nuevo')
                };
                if (payload.id_solicitud <= 0) {
                    $notify.error('Error', 'Solicitud inválida');
                    return;
                }
                if (!payload.empresa || !payload.contacto) {
                    $notify.error('Validación', 'Empresa y contacto son obligatorios');
                    return;
                }
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=solicitud_actualizar', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.modalSolicitud = false;
                        await this.cargarSolicitudes();
                        $notify.success('Guardado', data.message || 'Solicitud actualizada');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo guardar la solicitud');
                    }
                } catch (e) {
                    console.error('Error guardando solicitud:', e);
                    $notify.error('Error', 'Error de conexión');
                }
            },
            async suprimirSolicitud(idSolicitud) {
                const id = Number(idSolicitud || 0);
                if (id <= 0) {
                    $notify.error('Error', 'Solicitud inválida');
                    return;
                }
                const ok = await $confirm(
                    'Suprimir solicitud',
                    'Esta acción no se puede deshacer. ¿Desea continuar?',
                    { dangerous: true, confirmText: 'Sí, suprimir', icon: 'fas fa-trash' }
                );
                if (!ok) return;
                try {
                    const res = await fetch(`api/v1/suscripciones.php?action=solicitud_eliminar&id_solicitud=${id}`, {
                        method: 'DELETE'
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.modalSolicitud = false;
                        await this.cargarSolicitudes();
                        $notify.success('Suprimida', data.message || 'Solicitud suprimida');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo suprimir la solicitud');
                    }
                } catch (e) {
                    console.error('Error suprimiendo solicitud:', e);
                    $notify.error('Error', 'Error de conexión');
                }
            },

            abrirGestionNegocios() {
                this.modalNegocios = true;
                if (!this.negocioPlantillaSeleccionado && this.negociosPlantilla.length > 0) {
                    this.negocioPlantillaSeleccionado = this.negociosPlantilla[0];
                    this.negocioPlantillaAppIds = [...(this.negocioTemplatesMap[this.negocioPlantillaSeleccionado] || [])];
                }
                this.negocioHydrateEmpresa = this.empresaSeleccionada ? Number(this.empresaSeleccionada) : '';
            },

            seleccionarNegocioPlantilla(negocio) {
                this.negocioPlantillaSeleccionado = negocio;
                this.negocioPlantillaAppIds = [...(this.negocioTemplatesMap[negocio] || [])];
                const tipo = (this.negociosTipos || []).find(n => String(n.nombre) === String(negocio));
                this.negocioPlantillaDisponible = String(Number(tipo?.disponible ?? 1));
            },

            negocioTipoDisponible(nombre) {
                const tipo = (this.negociosTipos || []).find(n => String(n.nombre) === String(nombre));
                return Number(tipo?.disponible ?? 1) === 1;
            },

            esNegocioTipoEditable(nombre) {
                return (this.negociosTipos || []).some(n => String(n.nombre) === String(nombre));
            },

            async nuevoNegocioPlantilla() {
                const nombre = (window.prompt('Nombre del negocio:') || '').trim();
                if (!nombre) return;
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=crear_negocio_tipo', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ nombre, disponible: 1 })
                    });
                    const data = await res.json();
                    if (!data.success) {
                        $notify.error('Error', data.error || 'No se pudo crear tipo de negocio');
                        return;
                    }
                    await this.cargarNegocioTipos();
                    if (!this.negocioTemplatesMap[nombre]) this.negocioTemplatesMap[nombre] = [];
                    this.negocioPlantillaSeleccionado = nombre;
                    this.negocioPlantillaAppIds = [...(this.negocioTemplatesMap[nombre] || [])];
                    this.negocioPlantillaDisponible = '1';
                    $notify.success('Creado', data.message || 'Tipo de negocio creado');
                } catch (e) {
                    console.error('Error creando tipo negocio:', e);
                    $notify.error('Error', 'Error de conexión');
                }
            },

            async editarNegocioTipo(nombreActual) {
                const nombreNuevo = (window.prompt('Nuevo nombre del negocio:', nombreActual) || '').trim();
                if (!nombreNuevo || nombreNuevo === nombreActual) return;
                const negocioRow = (this.negociosTipos || []).find(n => String(n.nombre) === String(nombreActual));
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=actualizar_negocio_tipo', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            id: Number(negocioRow?.id || 0),
                            nombre_actual: nombreActual,
                            nombre: nombreNuevo,
                            disponible: Number(negocioRow?.disponible ?? 1)
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        await this.cargarNegocioTipos();
                        await this.cargarApps();
                        await this.cargarNegocioTemplates();
                        this.negocioPlantillaSeleccionado = nombreNuevo;
                        this.negocioPlantillaAppIds = [...(this.negocioTemplatesMap[nombreNuevo] || [])];
                        this.negocioPlantillaDisponible = String(Number(negocioRow?.disponible ?? 1));
                        $notify.success('Actualizado', data.message || 'Tipo de negocio actualizado');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo actualizar');
                    }
                } catch (e) {
                    console.error('Error actualizando tipo negocio:', e);
                    $notify.error('Error', 'Error de conexión');
                }
            },

            async suprimirNegocioTipo(nombre) {
                const ok = await $confirm(
                    'Suprimir tipo de negocio',
                    `Se suprimirá "${nombre}" aunque tenga apps relacionadas. Las apps quedarán sin tipo de negocio.`,
                    { dangerous: true, confirmText: 'Sí, suprimir', icon: 'fas fa-trash' }
                );
                if (!ok) return;
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=eliminar_negocio_tipo', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: Number((this.negociosTipos || []).find(n => String(n.nombre) === String(nombre))?.id || 0), nombre })
                    });
                    const data = await res.json();
                    if (data.success) {
                        await this.cargarNegocioTipos();
                        await this.cargarApps();
                        await this.cargarNegocioTemplates();
                        if (this.negocioPlantillaSeleccionado === nombre) {
                            this.negocioPlantillaSeleccionado = this.negociosPlantilla.length ? this.negociosPlantilla[0] : '';
                            this.negocioPlantillaAppIds = [...(this.negocioTemplatesMap[this.negocioPlantillaSeleccionado] || [])];
                        }
                        $notify.success('Suprimido', data.message || 'Tipo de negocio suprimido');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo suprimir');
                    }
                } catch (e) {
                    console.error('Error suprimiendo tipo negocio:', e);
                    $notify.error('Error', 'Error de conexión');
                }
            },

            async guardarNegocioPlantilla() {
                const negocio = (this.negocioPlantillaSeleccionado || '').trim();
                if (!negocio) {
                    $notify.error('Error', 'Selecciona un negocio');
                    return;
                }
                try {
                    const negocioRow = (this.negociosTipos || []).find(n => String(n.nombre) === String(negocio));
                    const disponible = Number(this.negocioPlantillaDisponible || 1) === 1 ? 1 : 0;
                    if (negocioRow) {
                        const updRes = await fetch('api/v1/suscripciones.php?action=actualizar_negocio_tipo', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                id: Number(negocioRow.id || 0),
                                nombre_actual: negocio,
                                nombre: negocio,
                                disponible
                            })
                        });
                        const updData = await updRes.json();
                        if (!updData.success) {
                            $notify.error('Error', updData.error || 'No se pudo actualizar el estado del negocio');
                            return;
                        }
                    }
                    const res = await fetch('api/v1/suscripciones.php?action=guardar_negocio_template', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            negocio,
                            app_ids: (this.negocioPlantillaAppIds || []).map(v => Number(v)).filter(v => v > 0)
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.negocioTemplatesMap[negocio] = [...(this.negocioPlantillaAppIds || []).map(v => Number(v)).filter(v => v > 0)];
                        await this.cargarNegocioTipos();
                        await this.cargarNegocioTemplates();
                        $notify.success('Guardado', data.message || 'Plantilla guardada');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo guardar plantilla');
                    }
                } catch (e) {
                    console.error('Error guardar plantilla:', e);
                    $notify.error('Error', 'Error de conexión');
                }
            },

            // Agregar app a la plantilla del negocio
            agregarAppPlantilla(app) {
                const appId = Number(app.id_app);
                if (!this.negocioPlantillaAppIds.includes(appId)) {
                    this.negocioPlantillaAppIds.push(appId);
                }
            },

            // Remover app de la plantilla del negocio
            removerAppPlantilla(appId) {
                const id = Number(appId);
                this.negocioPlantillaAppIds = this.negocioPlantillaAppIds.filter(a => Number(a) !== id);
            },

            async hidratarEmpresaPorNegocio() {
                const negocio = (this.negocioPlantillaSeleccionado || '').trim();
                const idEmpresa = Number(this.negocioHydrateEmpresa || 0);
                if (!negocio || idEmpresa <= 0) {
                    $notify.error('Error', 'Selecciona negocio y empresa');
                    return;
                }
                const ok = await $confirm(
                    'Hidratar empresa',
                    `Se agregarán apps del negocio "${negocio}" a la empresa #${idEmpresa}. ¿Continuar?`,
                    { confirmText: 'Sí, hidratar', icon: 'fas fa-bolt' }
                );
                if (!ok) return;

                try {
                    const res = await fetch('api/v1/suscripciones.php?action=hidratar_empresa_negocio', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id_empresa: idEmpresa, negocio })
                    });
                    const data = await res.json();
                    if (data.success) {
                        if (Number(this.empresaSeleccionada || 0) === idEmpresa) {
                            await this.cargarSuscripcionEmpresa();
                        }
                        await this.cargarSuscripciones();
                        $notify.success('Completado', data.message || 'Empresa hidratada');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo hidratar empresa');
                    }
                } catch (e) {
                    console.error('Error hidratando empresa:', e);
                    $notify.error('Error', 'Error de conexión');
                }
            },
            
            // === LISTAS ÚNICAS PARA DROPDOWNS ===
            
            // Lista única de negocios existentes + predefinidos
            get negociosUnicos() {
                const predefinidos = [];
                const deApps = this.apps.map(a => a.negocio).filter(Boolean);
                const deTipos = (this.negociosTipos || []).map(n => n.nombre).filter(Boolean);
                return [...new Set([...predefinidos, ...deTipos, ...deApps])].sort();
            },

            get negociosPlantilla() {
                const fromTpl = Object.keys(this.negocioTemplatesMap || {});
                return [...new Set([...this.negociosUnicos, ...fromTpl])].sort();
            },

            get appsActivas() {
                return (Array.isArray(this.apps) ? this.apps : []).filter(app => Number(app.activo ?? 0) === 1);
            },
            
            // Computed: apps filtradas por búsqueda
            get appsFiltradas() {
                const base = Array.isArray(this.apps) ? [...this.apps] : [];
                const busqueda = (this.busquedaApp || '').toLowerCase();
                const filtradas = !busqueda ? base : base.filter(app => 
                    app.nombre.toLowerCase().includes(busqueda) ||
                    app.codigo.toLowerCase().includes(busqueda) ||
                    (app.descripcion && app.descripcion.toLowerCase().includes(busqueda)) ||
                    (app.negocio && app.negocio.toLowerCase().includes(busqueda))
                );
                return filtradas.sort((a, b) => {
                    const na = String(a?.nombre || '').toLowerCase();
                    const nb = String(b?.nombre || '').toLowerCase();
                    if (na < nb) return -1;
                    if (na > nb) return 1;
                    return String(a?.codigo || '').localeCompare(String(b?.codigo || ''), 'es', { sensitivity: 'base' });
                });
            },

            get appsGridDisponibles() {
                return this.appsFiltradas.filter(app => Number(app.en_desarrollo ?? 0) !== 1);
            },

            get appsGridEnDesarrollo() {
                return this.appsFiltradas.filter(app => Number(app.en_desarrollo ?? 0) === 1);
            },

            get iconosFiltrados() {
                let base;
                if (this.iconSource === 'tabler') {
                    base = this.tablerOptions;
                } else if (this.iconSource === 'huge') {
                    base = this.hugeOptions;
                } else if (this.iconSource === 'material-symbols') {
                    base = this.materialOptions;
                } else {
                    base = this.heroiconOptions;
                }
                if (this.iconCategory !== 'all') {
                    base = base.filter(i => i.category === this.iconCategory);
                }
                if (!this.iconSearch) return base;
                const q = this.iconSearch.toLowerCase();
                return base.filter(i =>
                    i.name.toLowerCase().includes(q) || i.class.toLowerCase().includes(q)
                );
            },

            get customIconsFiltrados() {
                if (!this.iconSearch) return this.customIconsV2;
                const q = this.iconSearch.toLowerCase();
                return this.customIconsV2.filter(i =>
                    i.name.toLowerCase().includes(q) || i.class.toLowerCase().includes(q)
                );
            },

            get iconCategories() {
                return [
                    { value: 'all', label: 'Todos' },
                    { value: 'general', label: 'General' },
                    { value: 'ventas', label: 'Ventas' },
                    { value: 'finanzas', label: 'Finanzas' },
                    { value: 'usuarios', label: 'Usuarios' },
                    { value: 'inventario', label: 'Inventario' },
                    { value: 'sistema', label: 'Sistema' }
                ];
            },

            get iconosRecientesFiltrados() {
                return this.iconosRecientes.filter(ico => {
                    const source = ico.source || 'heroicon';
                    return source === this.iconSource;
                });
            },
            
            // === JERARQUÍA: NEGOCIO → APP ===
            
            // Computed: lista de negocios únicos
            get negociosConApps() {
                const negocios = [...new Set(this.appsFiltradas.map(app => app.negocio || 'Comercial'))];
                const ordenNegocios = ['Comercial', 'Estación de Servicio', 'Sistema'];
                return negocios.sort((a, b) => {
                    const indexA = ordenNegocios.indexOf(a);
                    const indexB = ordenNegocios.indexOf(b);
                    return (indexA === -1 ? 999 : indexA) - (indexB === -1 ? 999 : indexB);
                });
            },
            
            // Apps de un negocio
            appsPorNegocio(negocio) {
                return this.appsFiltradas.filter(app => (app.negocio || 'Comercial') === negocio);
            },
            
            // Ícono y clases para cada negocio
            getNegocioIcon(negocio) {
                const iconos = {
                    'Comercial': 'fas fa-store',
                    'Estación de Servicio': 'fas fa-gas-pump',
                    'Sistema': 'fas fa-server'
                };
                return iconos[negocio] || 'fas fa-briefcase';
            },
            
            getNegocioClasses(negocio) {
                const clases = {
                    'Comercial': 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400',
                    'Estación de Servicio': 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400',
                    'Sistema': 'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300'
                };
                return clases[negocio] || 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300';
            },
            
            // Computed: empresas filtradas por búsqueda
            get empresasFiltradas() {
                if (!this.busquedaEmpresa) return this.empresas;
                const busqueda = this.busquedaEmpresa.toLowerCase();
                return this.empresas.filter(emp => 
                    emp.empresa.toLowerCase().includes(busqueda) ||
                    emp.ruc.toLowerCase().includes(busqueda) ||
                    (emp.email && emp.email.toLowerCase().includes(busqueda))
                );
            },
            
            seleccionarEmpresa(emp) {
                this.empresaActual = emp;
                this.empresaSeleccionada = emp.id_empresa;
                this.busquedaEmpresa = emp.empresa;
                this.mostrarResultados = false;
                this.cargarSuscripcionEmpresa();
            },
            
            limpiarEmpresaSeleccionada() {
                this.empresaActual = null;
                this.empresaSeleccionada = '';
                this.busquedaEmpresa = '';
                this.suscripcionEmpresa = null;
                this.editandoPeriodoSuscripcion = false;
                this.periodoEditInicio = '';
                this.periodoEditFin = '';
                this.periodoEditVencimiento = '';
            },
            
            async cargarSuscripcionEmpresa() {
                if (!this.empresaSeleccionada) {
                    this.suscripcionEmpresa = null;
                    return;
                }
                this.cargandoEmpresa = true;
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=suscripcion_activa&fallback_latest=1&id_empresa=' + this.empresaSeleccionada);
                    const data = await res.json();
                    if (data.success && data.data) {
                        const sus = { ...data.data };
                        sus.es_sponsor = Number(sus.es_sponsor ?? 0);
                        sus.apps = Array.isArray(sus.apps)
                            ? sus.apps.map((app) => ({
                                ...app,
                                id_app: Number(app.id_app ?? app.id ?? 0),
                                obligatoria: Number(app.obligatoria ?? 0)
                            }))
                            : [];
                        this.suscripcionEmpresa = sus;
                        this.editandoPeriodoSuscripcion = false;
                        this.periodoEditInicio = this.normalizarFechaISO(sus.periodo_inicio);
                        this.periodoEditFin = this.normalizarFechaISO(sus.periodo_fin);
                        this.periodoEditVencimiento = this.normalizarFechaISO(sus.fecha_vencimiento);
                    } else {
                        this.suscripcionEmpresa = null;
                        this.editandoPeriodoSuscripcion = false;
                        this.periodoEditInicio = '';
                        this.periodoEditFin = '';
                        this.periodoEditVencimiento = '';
                    }
                } catch (e) {
                    console.error('Error:', e);
                    this.suscripcionEmpresa = null;
                    this.editandoPeriodoSuscripcion = false;
                    this.periodoEditInicio = '';
                    this.periodoEditFin = '';
                    this.periodoEditVencimiento = '';
                } finally {
                    this.cargandoEmpresa = false;
                }
            },

            normalizarFechaISO(value) {
                if (!value) return '';
                const s = String(value).trim();
                if (s.length >= 10) return s.slice(0, 10);
                return s;
            },

            iniciarEdicionPeriodoSuscripcion() {
                if (!this.suscripcionEmpresa) return;
                this.periodoEditInicio = this.normalizarFechaISO(this.suscripcionEmpresa.periodo_inicio);
                this.periodoEditFin = this.normalizarFechaISO(this.suscripcionEmpresa.periodo_fin);
                this.periodoEditVencimiento = this.normalizarFechaISO(this.suscripcionEmpresa.fecha_vencimiento);
                this.editandoPeriodoSuscripcion = true;
            },

            cancelarEdicionPeriodoSuscripcion() {
                this.editandoPeriodoSuscripcion = false;
                if (!this.suscripcionEmpresa) return;
                this.periodoEditInicio = this.normalizarFechaISO(this.suscripcionEmpresa.periodo_inicio);
                this.periodoEditFin = this.normalizarFechaISO(this.suscripcionEmpresa.periodo_fin);
                this.periodoEditVencimiento = this.normalizarFechaISO(this.suscripcionEmpresa.fecha_vencimiento);
            },

            async guardarPeriodoSuscripcion() {
                if (!this.suscripcionEmpresa || !this.suscripcionEmpresa.id_suscripcion) return;
                const ini = this.normalizarFechaISO(this.periodoEditInicio);
                const fin = this.normalizarFechaISO(this.periodoEditFin);
                if (!ini || !fin) {
                    $notify.error('Error', 'Debe completar inicio y fin');
                    return;
                }
                if (ini > fin) {
                    $notify.error('Error', 'La fecha inicio no puede ser mayor a la fecha fin');
                    return;
                }
                const venc = this.normalizarFechaISO(this.periodoEditVencimiento);
                if (venc && venc < fin) {
                    $notify.error('Error', 'El vencimiento no puede ser menor al fin del período');
                    return;
                }

                try {
                    const res = await fetch('api/v1/suscripciones.php?action=actualizar_periodo_suscripcion', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            id_suscripcion: this.suscripcionEmpresa.id_suscripcion,
                            periodo_inicio: ini,
                            periodo_fin: fin,
                            fecha_vencimiento: venc || ''
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.editandoPeriodoSuscripcion = false;
                        await this.cargarSuscripcionEmpresa();
                        await this.cargarSuscripciones();
                        $notify.success('Actualizado', data.message || 'Período actualizado');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo actualizar el período');
                    }
                } catch (e) {
                    console.error('Error:', e);
                    $notify.error('Error', 'Error de conexión al guardar período');
                }
            },

            async toggleSponsorSuscripcion() {
                if (!this.suscripcionEmpresa || !this.suscripcionEmpresa.id_suscripcion) return;
                const esSponsorActual = Number(this.suscripcionEmpresa.es_sponsor || 0) === 1;
                const confirmText = esSponsorActual
                    ? '¿Desactivar modo Sponsor para volver al cobro normal?'
                    : '¿Activar modo Sponsor? Esta empresa quedará sin cobro.';
                const confirmed = await $confirm(
                    esSponsorActual ? 'Quitar Sponsor' : 'Habilitar Sponsor',
                    confirmText,
                    {
                        confirmText: esSponsorActual ? 'Sí, quitar' : 'Sí, habilitar',
                        icon: 'fas fa-star'
                    }
                );
                if (!confirmed) return;

                try {
                    const res = await fetch('api/v1/suscripciones.php?action=toggle_sponsor', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            id_suscripcion: this.suscripcionEmpresa.id_suscripcion,
                            sponsor: esSponsorActual ? 0 : 1
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        await this.cargarSuscripcionEmpresa();
                        await this.cargarSuscripciones();
                        $notify.success('Actualizado', data.message || 'Estado Sponsor actualizado');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo actualizar Sponsor');
                    }
                } catch (e) {
                    console.error('Error:', e);
                    $notify.error('Error', 'Error de conexión al actualizar Sponsor');
                }
            },
            
            // Apps
            inferIconCategory(iconClass) {
                const key = (iconClass || '').toLowerCase();
                if (key.includes('user') || key.includes('person') || key.includes('face')) return 'usuarios';
                if (key.includes('cart') || key.includes('receipt') || key.includes('ticket') || key.includes('store') || key.includes('tag')) return 'ventas';
                if (key.includes('chart') || key.includes('bank') || key.includes('currency') || key.includes('coin') || key.includes('credit') || key.includes('wallet')) return 'finanzas';
                if (key.includes('truck') || key.includes('box') || key.includes('cube') || key.includes('archive') || key.includes('wrench') || key.includes('tool')) return 'inventario';
                if (key.includes('shield') || key.includes('lock') || key.includes('key') || key.includes('cog') || key.includes('server') || key.includes('cpu') || key.includes('cloud')) return 'sistema';
                return 'general';
            },

            cargarHeroiconsLocales() {
                const fullMap = (window.HEROICONS_OUTLINE_MAP && typeof window.HEROICONS_OUTLINE_MAP === 'object')
                    ? window.HEROICONS_OUTLINE_MAP
                    : {};
                const fullOptions = Array.isArray(window.HEROICONS_OUTLINE_OPTIONS)
                    ? window.HEROICONS_OUTLINE_OPTIONS
                    : [];

                if (Object.keys(fullMap).length > 0) {
                    this.heroiconsMap = { ...this.heroiconsMap, ...fullMap };
                }

                if (fullOptions.length > 0) {
                    const byClass = new Map(this.heroiconOptions.map(o => [o.class, o]));
                    for (const icon of fullOptions) {
                        if (!icon || !icon.class) continue;
                        if (!byClass.has(icon.class)) {
                            const name = icon.name || icon.class;
                            const category = this.inferIconCategory(icon.class);
                            const normalized = { name, class: icon.class, category, source: 'heroicon' };
                            this.heroiconOptions.push(normalized);
                            byClass.set(icon.class, normalized);
                        }
                    }
                    this.heroiconOptions.sort((a, b) => a.name.localeCompare(b.name, 'es'));
                }
            },

            cargarTablerLocales() {
                const fullMap = (window.TABLER_ICONS_MAP && typeof window.TABLER_ICONS_MAP === 'object')
                    ? window.TABLER_ICONS_MAP
                    : {};
                const fullOptions = Array.isArray(window.TABLER_ICONS_OPTIONS)
                    ? window.TABLER_ICONS_OPTIONS
                    : [];

                if (Object.keys(fullMap).length > 0) {
                    this.tablerMap = { ...this.tablerMap, ...fullMap };
                }

                if (fullOptions.length > 0) {
                    this.tablerOptions = fullOptions.map(icon => ({
                        ...icon,
                        source: 'tabler'
                    }));
                    this.tablerOptions.sort((a, b) => a.name.localeCompare(b.name, 'es'));
                }
            },

            cargarHugeLocales() {
                const fullMap = (window.HUGEICONS_MAP && typeof window.HUGEICONS_MAP === 'object')
                    ? window.HUGEICONS_MAP
                    : {};
                const fullOptions = Array.isArray(window.HUGEICONS_OPTIONS)
                    ? window.HUGEICONS_OPTIONS
                    : [];

                if (Object.keys(fullMap).length > 0) {
                    this.hugeMap = { ...this.hugeMap, ...fullMap };
                }

                if (fullOptions.length > 0) {
                    this.hugeOptions = fullOptions.map(icon => ({
                        ...icon,
                        source: 'huge'
                    }));
                    this.hugeOptions.sort((a, b) => a.name.localeCompare(b.name, 'es'));
                }
            },

            cargarMaterialLocales() {
                const fullMap = (window.MATERIAL_SYMBOLS_MAP && typeof window.MATERIAL_SYMBOLS_MAP === 'object')
                    ? window.MATERIAL_SYMBOLS_MAP
                    : {};
                const fullOptions = Array.isArray(window.MATERIAL_SYMBOLS_OPTIONS)
                    ? window.MATERIAL_SYMBOLS_OPTIONS
                    : [];

                if (Object.keys(fullMap).length > 0) {
                    this.materialMap = { ...this.materialMap, ...fullMap };
                }

                if (fullOptions.length > 0) {
                    this.materialOptions = fullOptions.map(icon => ({
                        ...icon,
                        source: 'material-symbols'
                    }));
                    this.materialOptions.sort((a, b) => a.name.localeCompare(b.name, 'es'));
                }
            },

            cargarUniconsLocales() {
                const fullMap = (window.UNICONS_MAP && typeof window.UNICONS_MAP === 'object')
                    ? window.UNICONS_MAP
                    : {};
                const fullOptions = Array.isArray(window.UNICONS_OPTIONS)
                    ? window.UNICONS_OPTIONS
                    : [];

                if (Object.keys(fullMap).length > 0) {
                    this.uniconsMap = { ...this.uniconsMap, ...fullMap };
                }

                if (fullOptions.length > 0) {
                    this.uniconsOptions = fullOptions.map(icon => ({
                        ...icon,
                        source: 'unicons'
                    }));
                    this.uniconsOptions.sort((a, b) => a.name.localeCompare(b.name, 'es'));
                }
            },

            resolveHeroiconName(name) {
                if (!name) return 'cube';
                return this.heroiconsMap[name] ? name : (this.heroiconAliases[name] || 'cube');
            },

            renderHeroicon(name, source = '') {
                if (!name) {
                    const fallback = this.heroiconsMap['cube'];
                    return fallback ? fallback.replace('<svg ', '<svg class="w-full h-full" ') : '';
                }

                if (source === 'custom' || this.customIconsV2.some(i => i.class === name)) {
                    return `<img src="/public/assets/images/icons_v2/${name}.svg" class="w-full h-full block" style="display:block;" alt="${name}">`;
                }

                const tabler = this.tablerMap[name];
                if (tabler) {
                    return tabler.replace('<svg ', '<svg class="w-full h-full" ');
                }

                const huge = this.hugeMap[name];
                if (huge) {
                    return huge.replace('<svg ', '<svg class="w-full h-full" ');
                }

                const material = this.materialMap[name];
                if (material) {
                    return material.replace('<svg ', '<svg class="w-full h-full" ');
                }

                const unicons = this.uniconsMap[name];
                if (unicons) {
                    return unicons.replace('<svg ', '<svg class="w-full h-full" ');
                }

                const iconName = this.resolveHeroiconName(name);
                const svg = this.heroiconsMap[iconName] || this.heroiconsMap['cube'];
                return svg.replace('<svg ', '<svg class="w-full h-full" ');
            },

            resolveIconSource(iconClass, preferredSource = '') {
                const source = String(preferredSource || '').trim();
                if (source === 'heroicon' || source === 'tabler' || source === 'huge' || source === 'material-symbols' || source === 'unicons' || source === 'custom') {
                    return source;
                }
                if (iconClass && this.customIconsV2.some(i => i.class === iconClass)) {
                    return 'custom';
                }
                if (iconClass && this.tablerMap[iconClass]) {
                    return 'tabler';
                }
                if (iconClass && this.hugeMap[iconClass]) {
                    return 'huge';
                }
                if (iconClass && this.materialMap[iconClass]) {
                    return 'material-symbols';
                }
                if (iconClass && this.uniconsMap[iconClass]) {
                    return 'unicons';
                }
                return 'heroicon';
            },

            buildIconSvg(iconClass, preferredSource = '') {
                try {
                    const source = this.resolveIconSource(iconClass, preferredSource);
                    if (source === 'custom') {
                        return `/public/assets/images/icons_v2/${iconClass}.svg`;
                    }
                    if (source === 'tabler' && this.tablerMap[iconClass]) {
                        const svg = this.tablerMap[iconClass];
                        return typeof svg === 'string' ? svg : '';
                    }
                    if (source === 'huge' && this.hugeMap[iconClass]) {
                        const svg = this.hugeMap[iconClass];
                        return typeof svg === 'string' ? svg : '';
                    }
                    if (source === 'material-symbols' && this.materialMap[iconClass]) {
                        const svg = this.materialMap[iconClass];
                        return typeof svg === 'string' ? svg : '';
                    }
                    if (source === 'unicons' && this.uniconsMap[iconClass]) {
                        const svg = this.uniconsMap[iconClass];
                        return typeof svg === 'string' ? svg : '';
                    }
                    const iconName = this.resolveHeroiconName(iconClass);
                    const fallback = this.heroiconsMap[iconName] || this.heroiconsMap['cube'];
                    return typeof fallback === 'string' ? fallback : '';
                } catch (e) {
                    console.error('Error en buildIconSvg:', e);
                    return '';
                }
            },

            loadRecentIcons() {
                try {
                    const raw = localStorage.getItem(this.recentIconsKey);
                    const parsed = raw ? JSON.parse(raw) : [];
                    this.iconosRecientes = Array.isArray(parsed) ? parsed.slice(0, 12) : [];
                } catch (e) {
                    this.iconosRecientes = [];
                }
            },

            saveRecentIcons() {
                localStorage.setItem(this.recentIconsKey, JSON.stringify(this.iconosRecientes.slice(0, 12)));
            },

            touchRecentIcon(iconClass) {
                let found = this.heroiconOptions.find(i => i.class === iconClass);
                if (!found) {
                    found = this.tablerOptions.find(i => i.class === iconClass);
                }
                if (!found) {
                    found = this.hugeOptions.find(i => i.class === iconClass);
                }
                if (!found) {
                    found = this.materialOptions.find(i => i.class === iconClass);
                }
                if (!found) {
                    found = this.uniconsOptions.find(i => i.class === iconClass);
                }
                if (!found) return;
                const clean = this.iconosRecientes.filter(i => i.class !== iconClass);
                clean.unshift({
                    name: found.name,
                    class: found.class,
                    category: found.category,
                    source: found.source || this.iconSource
                });
                this.iconosRecientes = clean.slice(0, 12);
                this.saveRecentIcons();
            },

            nuevaApp() {
                this.appEditando = null;
                this.formApp = {
                    codigo: '',
                    nombre: '',
                    descripcion: '',
                    ruta_app: '',
                    icono: 'cube',
                    icono_source: 'heroicon',
                    icono_svg: this.buildIconSvg('cube', 'heroicon'),
                    color: 'blue',
                    precio_mensual: 0,
                    orden: 100,
                    obligatoria: false,
                    activo: true,
                    en_desarrollo: false,
                    negocio: 'Comercial'
                };
                this.iconSearch = '';
                this.iconCategory = 'all';
                this.modalIconPicker = false;
                this.modalApp = true;
            },

            abrirSelectorIconos() {
                this.iconSearch = '';
                this.iconCategory = 'all';
                this.iconSource = 'custom';
                this.modalIconPicker = true;
            },

            seleccionarIcono(iconClass) {
                this.formApp.icono = iconClass;
                this.formApp.icono_source = this.iconSource;
                this.formApp.icono_svg = this.buildIconSvg(iconClass, this.iconSource);
                this.touchRecentIcon(iconClass);
                this.modalIconPicker = false;
            },

            editarApp(app) {
                const resolvedSource = this.resolveIconSource(app.icono, app.icono_source);
                this.appEditando = app;
                this.formApp = { 
                    ...app,
                    icono_source: resolvedSource,
                    icono_svg: app.icono_svg || this.buildIconSvg(app.icono, resolvedSource),
                    // Convertir valores a booleanos para los checkboxes
                    obligatoria: app.obligatoria == 1 || app.obligatoria === true,
                    activo: app.activo == 1 || app.activo === true,
                    en_desarrollo: app.en_desarrollo == 1 || app.en_desarrollo === true
                };
                this.iconSource = resolvedSource;
                this.iconSearch = '';
                this.iconCategory = 'all';
                this.modalIconPicker = false;
                this.modalApp = true;
            },

            abrirApp(app) {
                if (app.ruta_app) {
                    let url = app.ruta_app;
                    // Evitar duplicación de /public
                    if (url.startsWith('/public/')) {
                        url = url; // Ya tiene /public
                    } else if (url.startsWith('public/')) {
                        url = '/' + url; // Agregar / al inicio
                    } else if (!url.startsWith('/')) {
                        url = '/public/' + url; // Agregar /public/ si no tiene
                    }
                    window.open(url, '_blank');
                } else {
                    $notifyWarn('La aplicación no tiene una URL configurada');
                }
            },

            async eliminarApp(app) {
                const confirmed = await $confirm(
                    '¿Eliminar aplicación?', 
                    `¿Está seguro de eliminar "${app.nombre}"? Esta acción no se puede deshacer.`,
                    {
                        dangerous: true,
                        confirmText: 'Sí, eliminar',
                        icon: 'fas fa-trash-alt'
                    }
                );
                if (!confirmed) return;
                
                try {
                    const res = await fetch(`api/v1/suscripciones.php?action=eliminar_app&id=${app.id_app}`, {
                        method: 'DELETE'
                    });
                    const data = await res.json();
                    
                    if (data.success) {
                        await this.cargarApps();
                        $notify.success('Eliminada', data.message || 'App eliminada correctamente');
                    } else {
                        $notify.error('Error', data.error || 'Error al eliminar la app');
                    }
                } catch (e) {
                    console.error('Error:', e);
                    $notify.error('Error', 'Error de conexión con el servidor');
                }
            },
            
            async guardarApp() {
                try {
                    this.guardarEnProgreso = true;

                    // Preparar datos para envío - convertir booleanos a números
                    const datosEnvio = { ...this.formApp };
                    datosEnvio.icono_source = this.resolveIconSource(datosEnvio.icono, datosEnvio.icono_source || this.iconSource);
                    datosEnvio.icono_svg = this.buildIconSvg(datosEnvio.icono, datosEnvio.icono_source);
                    datosEnvio.obligatoria = datosEnvio.obligatoria ? 1 : 0;
                    datosEnvio.activo = datosEnvio.activo ? 1 : 0;
                    datosEnvio.en_desarrollo = datosEnvio.en_desarrollo ? 1 : 0;

                    const url = this.appEditando
                        ? `api/v1/suscripciones.php?action=actualizar_app&id=${this.appEditando.id_app}`
                        : 'api/v1/suscripciones.php?action=crear_app';
                    const method = 'POST';

                    console.log('Guardando app:', { url, method, datos: datosEnvio });

                    const res = await fetch(url, {
                        method,
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(datosEnvio)
                    });
                    const data = await res.json();

                    console.log('Respuesta del servidor:', data);

                    if (data.success) {
                        this.modalApp = false;
                        this.appEditando = null;
                        this.formApp = {};
                        this.guardarEnProgreso = false;
                        void this.cargarApps().catch((err) => {
                            console.error('Error recargando apps:', err);
                        });
                        $notify.success('Guardado', data.message || 'App guardada correctamente');
                    } else {
                        $notify.error('Error', data.error || data.errors?.nombre || 'Error al guardar');
                    }
                } catch (e) {
                    console.error('Error:', e);
                    $notify.error('Error', 'Error de conexión con el servidor');
                } finally {
                    this.guardarEnProgreso = false;
                }
            },
            
            // Suscripciones
            verSuscripcion(s) {
                this.empresaSeleccionada = s.id_empresa;
                this.activeTab = 'empresas';
                this.cargarSuscripcionEmpresa();
            },
            
            async marcarPagado(idSuscripcion) {
                const confirmed = await $confirm('¿Marcar como pagada?', '¿Confirma que esta suscripción fue pagada?', {
                    confirmText: 'Sí, confirmar pago',
                    icon: 'fas fa-check-circle'
                });
                if (!confirmed) return;
                
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=marcar_pagado', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id_suscripcion: idSuscripcion, metodo_pago: 'manual' })
                    });
                    const data = await res.json();
                    if (data.success) {
                        await this.cargarSuscripciones();
                        $notify.success('Pagado', 'La suscripción fue marcada como pagada');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo marcar como pagada');
                    }
                } catch (e) {
                    console.error('Error:', e);
                    $notify.error('Error', 'Error de conexión');
                }
            },
            
            async crearSuscripcionEmpresa() {
                const confirmed = await $confirm('Crear suscripción', '¿Crear suscripción para el mes actual?', {
                    confirmText: 'Crear',
                    icon: 'fas fa-file-invoice-dollar'
                });
                if (!confirmed) return;
                
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=crear_suscripcion', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id_empresa: this.empresaSeleccionada })
                    });
                    const data = await res.json();
                    if (data.success) {
                        await this.cargarSuscripcionEmpresa();
                        await this.cargarSuscripciones();
                        $notify.success('Creada', 'Suscripción creada exitosamente');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo crear la suscripción');
                    }
                } catch (e) {
                    console.error('Error:', e);
                    $notify.error('Error', 'Error de conexión');
                }
            },
            
            get appsDisponibles() {
                const appsLiberadas = this.apps.filter(a => Number(a.activo) === 1);
                if (!this.suscripcionEmpresa || !Array.isArray(this.suscripcionEmpresa.apps)) return appsLiberadas;
                const idsContratadas = this.suscripcionEmpresa.apps.map(a => Number(a.id_app ?? 0));
                return appsLiberadas.filter(a => !idsContratadas.includes(Number(a.id_app ?? 0)));
            },
            
            // Apps disponibles filtradas por búsqueda en el modal
            get appsDisponiblesFiltradas() {
                if (!this.busquedaAppModal) return this.appsDisponibles;
                const busqueda = this.busquedaAppModal.toLowerCase();
                return this.appsDisponibles.filter(app =>
                    app.nombre.toLowerCase().includes(busqueda) ||
                    app.codigo.toLowerCase().includes(busqueda) ||
                    (app.descripcion && app.descripcion.toLowerCase().includes(busqueda)) ||
                    (app.negocio && app.negocio.toLowerCase().includes(busqueda))
                );
            },

            // Apps disponibles para la plantilla de negocio (no asignados) - TODOS los apps del proyecto
            get appsDisponiblesNegocio() {
                if (!this.negocioPlantillaSeleccionado) return [];
                const idsAsignados = (this.negocioPlantillaAppIds || []).map(id => Number(id));
                return this.apps.filter(app =>
                    Number(app.activo) === 1 &&
                    !idsAsignados.includes(Number(app.id_app))
                );
            },

            // Apps disponibles del negocio, filtrados por búsqueda y estado
            get appsDisponiblesNegocioFiltrados() {
                let resultado = this.appsDisponiblesNegocio;

                // Filtrar por estado
                if (this.filtroEstadoPlantilla) {
                    resultado = resultado.filter(app => String(app.activo) === String(this.filtroEstadoPlantilla));
                }

                // Filtrar por búsqueda
                if (!this.busquedaDisponibles) return resultado;
                const busqueda = this.busquedaDisponibles.toLowerCase();
                return resultado.filter(app =>
                    app.nombre.toLowerCase().includes(busqueda) ||
                    app.codigo.toLowerCase().includes(busqueda) ||
                    (app.descripcion && app.descripcion.toLowerCase().includes(busqueda))
                );
            },

            // Apps asignados a la plantilla, filtrados por búsqueda y estado
            get appsAsignadosFiltrados() {
                let resultado = this.negocioPlantillaAppIds.map(id => this.appAsignado(id)).filter(a => a);

                // Filtrar por estado
                if (this.filtroEstadoPlantilla) {
                    resultado = resultado.filter(app => String(app.activo) === String(this.filtroEstadoPlantilla));
                }

                // Filtrar por búsqueda
                if (!this.busquedaAsignadas) return resultado;
                const busqueda = this.busquedaAsignadas.toLowerCase();
                return resultado.filter(app =>
                    app.nombre.toLowerCase().includes(busqueda) ||
                    app.codigo.toLowerCase().includes(busqueda) ||
                    (app.descripcion && app.descripcion.toLowerCase().includes(busqueda))
                );
            },

            // Obtener app por ID
            appAsignado(appId) {
                return this.apps.find(a => Number(a.id_app) === Number(appId));
            },

            // Negocios únicos de apps disponibles (no asignadas aún)
            get negociosDisponibles() {
                const negocios = [...new Set(this.appsDisponibles.map(a => a.negocio || 'Comercial'))];
                return negocios.sort();
            },
            
            // Apps disponibles por negocio
            appsDisponiblesPorNegocio(negocio) {
                return this.appsDisponibles.filter(a => (a.negocio || 'Comercial') === negocio);
            },
            
            // Totales de precios
            totalPrecioNegocio(negocio) {
                return this.appsDisponiblesPorNegocio(negocio).reduce((sum, a) => sum + (parseFloat(a.precio_mensual) || 0), 0);
            },
            
            // Iconos para negocios
            getNegocioIcon(negocio) {
                const icons = {
                    'Comercial': 'fas fa-store text-blue-500',
                    'Contable': 'fas fa-calculator text-green-500',
                    'Recursos Humanos': 'fas fa-users text-purple-500',
                    'Industrial': 'fas fa-industry text-orange-500',
                    'Servicios': 'fas fa-concierge-bell text-teal-500'
                };
                return icons[negocio] || 'fas fa-building text-gray-500';
            },
            
            getNegocioClasses(negocio) {
                const classes = {
                    'Comercial': 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-700 text-blue-800 dark:text-blue-200',
                    'Contable': 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-700 text-green-800 dark:text-green-200',
                    'Recursos Humanos': 'bg-purple-50 dark:bg-purple-900/20 border-purple-200 dark:border-purple-700 text-purple-800 dark:text-purple-200',
                    'Industrial': 'bg-orange-50 dark:bg-orange-900/20 border-orange-200 dark:border-orange-700 text-orange-800 dark:text-orange-200',
                    'Servicios': 'bg-teal-50 dark:bg-teal-900/20 border-teal-200 dark:border-teal-700 text-teal-800 dark:text-teal-200'
                };
                return classes[negocio] || 'bg-gray-50 dark:bg-slate-700 border-gray-200 dark:border-slate-600 text-gray-800 dark:text-gray-200';
            },
            
            // Agregar todas las apps de un negocio
            async agregarNegocioSuscripcion(negocio) {
                const apps = this.appsDisponiblesPorNegocio(negocio);
                if (apps.length === 0) return;
                
                const confirmed = await $confirm(
                    'Agregar todo el negocio', 
                    `¿Agregar las ${apps.length} apps de "${negocio}" a la suscripción?`,
                    { confirmText: 'Sí, agregar todas', icon: 'fas fa-building' }
                );
                if (!confirmed) return;
                
                let agregadas = 0;
                for (const app of apps) {
                    try {
                        const res = await fetch('api/v1/suscripciones.php?action=agregar_app', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ 
                                id_suscripcion: this.suscripcionEmpresa.id_suscripcion,
                                id_app: app.id_app 
                            })
                        });
                        const data = await res.json();
                        if (data.success) agregadas++;
                    } catch (e) {
                        console.error('Error agregando app:', e);
                    }
                }
                
                this.modalAgregarApp = false;
                this.modoAgregarApp = 'individual';
                await this.cargarSuscripcionEmpresa();
                $notify.success('Agregadas', `${agregadas} apps del negocio "${negocio}" agregadas`);
            },
            
            async agregarAppSuscripcion(idApp) {
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=agregar_app', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            id_suscripcion: this.suscripcionEmpresa.id_suscripcion,
                            id_app: idApp 
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.modalAgregarApp = false;
                        this.busquedaAppModal = '';
                        await this.cargarSuscripcionEmpresa();
                        $notify.success('Agregada', 'App agregada a la suscripción');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo agregar la app');
                    }
                } catch (e) {
                    console.error('Error:', e);
                    $notify.error('Error', 'Error de conexión');
                }
            },
            
            async quitarAppSuscripcion(idApp) {
                idApp = Number(idApp || 0);
                if (idApp <= 0) {
                    $notify.error('Error', 'No se pudo identificar la app a suprimir');
                    return;
                }
                const confirmed = await $confirm('¿Quitar app?', '¿Está seguro de quitar esta app de la suscripción?', {
                    dangerous: true,
                    confirmText: 'Sí, quitar',
                    icon: 'fas fa-trash-alt'
                });
                if (!confirmed) return;
                
                try {
                    const res = await fetch('api/v1/suscripciones.php?action=quitar_app', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            id_suscripcion: this.suscripcionEmpresa.id_suscripcion,
                            id_app: idApp 
                        })
                    });
                    const data = await res.json();
                    if (data.success) {
                        await this.cargarSuscripcionEmpresa();
                        $notify.success('Eliminada', 'App removida de la suscripción');
                    } else {
                        $notify.error('Error', data.error || 'No se pudo quitar la app');
                    }
                } catch (e) {
                    console.error('Error:', e);
                    $notify.error('Error', 'Error de conexión');
                }
            },
            
            salir() {
                // Intentar cerrar usando la función del padre (menu.php)
                try {
                    if (typeof parent.cerrarApp === 'function') {
                        parent.cerrarApp();
                        return;
                    }
                    // Fallback: Forzar recarga del menú principal
                    if (window.top && window.top.location) {
                        window.top.location.href = '/public/menu/menu.php';
                    } else {
                        window.location.href = '/public/menu/menu.php';
                    }
                } catch (e) {
                    console.error('Error al salir:', e);
                    window.location.href = '/public/menu/menu.php';
                }
            }
        };
    }
    </script>
    
    <!-- Sistema de Notificaciones Unificado -->
    <?php include __DIR__ . '/assets/components/notifications.php'; ?>
</body>
</html>
