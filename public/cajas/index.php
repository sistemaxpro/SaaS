<?php
/**
 * Módulo Gestión de Cajas - Frontend Desktop
 * CRUD de cajas, asignación de usuarios, timbrados
 * Color accent: Green
 */

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isMobile = preg_match('/Mobile|Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i', $userAgent);
$forceDesktop = isset($_GET['desktop']) || isset($_COOKIE['cajas_desktop']);

if ($isMobile && !$forceDesktop && !isset($_GET['no_redirect'])) {
    header('Location: /public/cajas/mobile.php');
    exit;
}

if (isset($_GET['desktop'])) {
    setcookie('cajas_desktop', '1', time() + 86400 * 30, '/');
}

require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

Permission::requireAccess('app_grid_caja');
$permisos = Permission::getAppPermissions('app_grid_caja');

$id_empresa = Session::get('id_empresa', 169);
$id_caja_def = (int)Session::get('id_caja_def', 0);

// Modo "Mi Caja": se activa desde mi_caja.php
$miCajaMode = !empty($miCajaMode);

$masterPdo = Database::getMasterConnection();
$stmtE = $masterPdo->prepare("SELECT empresa FROM empresa WHERE id_empresa = ?");
$stmtE->execute([$id_empresa]);
$empresaNombre = $stmtE->fetchColumn() ?: 'Empresa';

// Pre-cargar sucursales y usuarios desde PHP
$sucursalesJson = '[]';
$usuariosJson = '[]';
try {
    require_once __DIR__ . '/config/db_config.php';
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];
    $stmtSuc = $pdo->query("SELECT SUC as id_sucursal, NOMBRE as nombre FROM {$db}.sucursal ORDER BY SUC");
    $sucursalesJson = json_encode($stmtSuc->fetchAll(PDO::FETCH_ASSOC));
    $stmtUsr = $masterPdo->prepare("SELECT id_login, login, name FROM sec_users WHERE id_empresa = ? AND active = 'Y' ORDER BY name");
    $stmtUsr->execute([$id_empresa]);
    $usuariosJson = json_encode($stmtUsr->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    error_log("[Cajas] Error precargando catálogos: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es" x-data="cajasApp()" x-init="init()">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $miCajaMode ? 'Mi Caja' : 'Cajas' ?> - SistemaX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="/public/pos/js/smx-printer.js?v=2"></script>
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
        .modal-enter { animation: modalIn .25s ease-out; }
        @keyframes modalIn { from { opacity:0; transform:scale(.95) translateY(10px); } to { opacity:1; transform:scale(1) translateY(0); } }
        .fade-in { animation: fadeIn 0.2s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-900 min-h-screen font-sans antialiased">
<script>
window.__PERMISOS__ = <?= json_encode($permisos) ?>;
window.__SUCURSALES__ = <?= $sucursalesJson ?>;
window.__USUARIOS__ = <?= $usuariosJson ?>;
</script>

<div class="min-h-screen">
    <!-- Header -->
    <header class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 sticky top-0 z-30">
        <div class="max-w-[1600px] mx-auto px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                        class="w-10 h-10 rounded-lg bg-red-600/10 border border-red-500/40 flex items-center justify-center text-red-600 dark:text-red-400 hover:bg-red-600/20 active:scale-95 transition-all cursor-pointer" title="Salir">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15m-3 0-3-3m0 0 3-3m-3 3H15" />
                    </svg>
                </button>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <i class="fas fa-cash-register text-green-600 dark:text-green-400"></i> Cajas
                    </h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($empresaNombre) ?></p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button
                    @click="conectarQZ(true)"
                    :disabled="qzConectando"
                    class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border text-xs font-bold transition-colors"
                    :class="qzConectado
                        ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:border-emerald-700'
                        : 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-900/20 dark:text-rose-300 dark:border-rose-700'"
                    :title="qzConectado
                        ? 'Impresión conectada (Agent)'
                        : 'Impresión desconectada. Click para reconectar'">
                    <span class="w-2.5 h-2.5 rounded-full border border-white/30"
                          :class="qzConectado ? 'bg-emerald-500 animate-pulse' : 'bg-rose-500'"></span>
                    <span x-show="!qzConectando" x-text="qzConectado ? 'Online (Agent)' : 'Offline'"></span>
                    <span x-show="qzConectando">Conectando...</span>
                </button>
                <button x-show="permisos.priv_insert === 'Y'" @click="nuevaCaja()" class="px-4 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-xl text-sm font-semibold flex items-center gap-2 transition-colors shadow-sm">
                    <i class="fas fa-plus text-xs"></i> Nueva Caja
                </button>
            </div>
        </div>
    </header>

    <main class="max-w-[1600px] mx-auto px-6 py-6">

        <!-- Stats -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total Cajas</p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1" x-text="stats.total"></p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                <p class="text-xs font-medium text-green-600 dark:text-green-400 uppercase">Resultados</p>
                <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-1" x-text="totalResultados"></p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                <p class="text-xs font-medium text-blue-600 dark:text-blue-400 uppercase">Sucursales</p>
                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-1" x-text="sucursales.length"></p>
            </div>
        </div>

        <!-- Filtros -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 mb-6">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-[250px]">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" x-model.debounce.300ms="search" @input="page = 1; loadCajas()"
                               placeholder="Buscar por nombre, timbrado..."
                               class="w-full pl-10 pr-4 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-green-500 focus:border-green-500 text-sm">
                    </div>
                </div>
                <select x-model="filtroSucursal" @change="page = 1; loadCajas()"
                        class="px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                    <option value="all">Todas las sucursales</option>
                    <template x-for="s in sucursales" :key="s.id_sucursal">
                        <option :value="s.id_sucursal" x-text="s.nombre"></option>
                    </template>
                </select>
            </div>
        </div>

        <!-- Grid de cajas -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
            <div x-show="loading" class="flex items-center justify-center py-12">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-green-600"></div>
            </div>

            <div x-show="!loading" class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                    <template x-for="c in cajas" :key="c.id_caja">
                        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 shadow-sm hover:shadow-md transition-shadow fade-in">
                            <!-- Header -->
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-12 h-12 rounded-xl bg-green-100 dark:bg-green-900/30 flex items-center justify-center text-green-600 dark:text-green-400">
                                        <i class="fas fa-cash-register text-xl"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-base font-bold text-gray-900 dark:text-white" x-text="c.caja"></h3>
                                        <p class="text-xs text-gray-500 dark:text-gray-400" x-text="'ID: ' + c.id_caja"></p>
                                    </div>
                                </div>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-medium"
                                      :class="c.tipo == 1 ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' : 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400'"
                                      x-text="c.tipo == 1 ? 'Efectivo' : 'Mixta'"></span>
                            </div>

                            <!-- Info -->
                            <div class="space-y-2 text-sm mb-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500 dark:text-gray-400"><i class="fas fa-building mr-1.5 w-4 text-center"></i> Sucursal</span>
                                    <span class="text-gray-900 dark:text-white font-medium" x-text="c.nombre_sucursal || 'Suc. ' + c.id_sucursal"></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500 dark:text-gray-400"><i class="fas fa-wallet mr-1.5 w-4 text-center"></i> Saldo Máx.</span>
                                    <span class="text-gray-900 dark:text-white font-medium" x-text="formatMoney(c.saldo_maximo)"></span>
                                </div>
                                <div x-show="c.timbrado" class="flex items-center justify-between">
                                    <span class="text-gray-500 dark:text-gray-400"><i class="fas fa-stamp mr-1.5 w-4 text-center"></i> Timbrado</span>
                                    <span class="text-gray-900 dark:text-white font-mono text-xs" x-text="c.timbrado"></span>
                                </div>
                                <div x-show="c.vencimiento" class="flex items-center justify-between">
                                    <span class="text-gray-500 dark:text-gray-400"><i class="fas fa-calendar mr-1.5 w-4 text-center"></i> Vence</span>
                                    <span class="font-medium" :class="isVencido(c.vencimiento) ? 'text-red-600 dark:text-red-400' : 'text-gray-900 dark:text-white'" x-text="c.vencimiento"></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-500 dark:text-gray-400"><i class="fas fa-receipt mr-1.5 w-4 text-center"></i> Numeración</span>
                                    <span class="text-gray-900 dark:text-white font-mono text-xs" x-text="pad(c.factura_1,3) + '-' + pad(c.factura_2,3) + '-' + pad(c.factura_3,7)"></span>
                                </div>
                                <div x-show="c.impresora" class="flex items-center justify-between">
                                    <span class="text-gray-500 dark:text-gray-400"><i class="fas fa-print mr-1.5 w-4 text-center"></i> Impresora</span>
                                    <span class="text-gray-900 dark:text-white text-xs font-medium" x-text="c.impresora"></span>
                                </div>
                                <div class="flex items-start justify-between gap-2">
                                    <span class="text-gray-500 dark:text-gray-400"><i class="fas fa-credit-card mr-1.5 w-4 text-center"></i> Métodos</span>
                                    <span class="text-gray-900 dark:text-white text-xs font-medium text-right" x-text="formatMetodosCobro(c.metodo_cobro_permitido)"></span>
                                </div>
                            </div>

                            <!-- Usuarios asignados -->
                            <div x-show="c.usuarios_asignados && c.usuarios_asignados.length > 0" class="mb-4">
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1.5"><i class="fas fa-users mr-1"></i> Usuarios asignados</p>
                                <div class="flex flex-wrap gap-1.5">
                                    <template x-for="u in c.usuarios_asignados" :key="u.id_login">
                                        <span class="px-2 py-0.5 bg-gray-100 dark:bg-slate-700 rounded-full text-xs text-gray-700 dark:text-gray-300" x-text="u.nombre"></span>
                                    </template>
                                </div>
                            </div>

                            <!-- Acciones -->
                            <div class="flex items-center justify-end gap-2 border-t border-gray-100 dark:border-slate-700 pt-3">
                                <button @click="verKardex(c)"
                                        class="px-3 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 dark:bg-amber-900/20 dark:hover:bg-amber-900/40 text-amber-600 dark:text-amber-400 text-xs font-semibold flex items-center gap-1.5 transition-colors">
                                    <i class="fas fa-book"></i> Kardex
                                </button>
                                <button x-show="permisos.priv_update === 'Y'" @click="editarCaja(c)"
                                        class="px-3 py-1.5 rounded-lg bg-green-50 hover:bg-green-100 dark:bg-green-900/20 dark:hover:bg-green-900/40 text-green-600 dark:text-green-400 text-xs font-semibold flex items-center gap-1.5 transition-colors">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                <button x-show="permisos.priv_delete === 'Y'" @click="eliminarCaja(c)"
                                        class="px-3 py-1.5 rounded-lg bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:hover:bg-red-900/40 text-red-600 dark:text-red-400 text-xs font-semibold flex items-center gap-1.5 transition-colors">
                                    <i class="fas fa-trash"></i> Eliminar
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Empty -->
            <div x-show="!loading && cajas.length === 0" class="py-12 text-center">
                <i class="fas fa-cash-register text-5xl text-gray-300 dark:text-slate-600 mb-3"></i>
                <p class="text-gray-500 dark:text-gray-400">No se encontraron cajas</p>
            </div>
        </div>

        <!-- Paginación -->
        <div x-show="totalPages > 1" class="flex items-center justify-between mt-4">
            <span class="text-sm text-gray-500 dark:text-gray-400" x-text="'Pág. ' + page + ' de ' + totalPages"></span>
            <div class="flex gap-2">
                <button @click="page--; loadCajas()" :disabled="page <= 1"
                        class="px-3 py-2 rounded-lg bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 text-sm disabled:opacity-40">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button @click="page++; loadCajas()" :disabled="page >= totalPages"
                        class="px-3 py-2 rounded-lg bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 text-sm disabled:opacity-40">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </main>

    <!-- ============ MODAL CREAR/EDITAR CAJA ============ -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showModal = false">
        <div class="absolute inset-0 bg-black/50" @click="showModal = false"></div>
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto modal-enter">
            <!-- Header -->
            <div class="sticky top-0 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-5 py-2.5 flex items-center justify-between rounded-t-2xl z-10">
                <h2 class="text-base font-bold text-gray-900 dark:text-white" x-text="form.id_caja ? 'Editar Caja' : 'Nueva Caja'"></h2>
                <button @click="showModal = false" class="w-7 h-7 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500 hover:bg-gray-200 dark:hover:bg-slate-600">
                    <i class="fas fa-times text-xs"></i>
                </button>
            </div>

            <!-- Body -->
            <div class="px-5 py-3 space-y-3">
                <!-- Nombre -->
                <div>
                    <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-300 mb-0.5">Nombre de la Caja *</label>
                    <input type="text" x-model="form.caja" placeholder="Ej: CAJA 1, CAJA CENTRAL..."
                           class="w-full px-2.5 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-green-500">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <!-- Tipo -->
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-300 mb-0.5">Tipo</label>
                        <select x-model="form.tipo" class="w-full px-2.5 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                            <option value="1">Efectivo</option>
                            <option value="2">Mixta</option>
                        </select>
                    </div>
                    <!-- Sucursal -->
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-300 mb-0.5">Sucursal</label>
                        <select x-model="form.id_sucursal" class="w-full px-2.5 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                            <option value="">— Seleccionar —</option>
                            <template x-for="s in sucursales" :key="s.id_sucursal">
                                <option :value="String(s.id_sucursal)" x-text="s.id_sucursal + ' - ' + s.nombre"></option>
                            </template>
                        </select>
                    </div>
                </div>

                <!-- Métodos de cobro permitidos -->
                <div>
                    <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-300 mb-1">Método de Cobro Permitido</label>
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                        <template x-for="m in metodosCobroCatalog" :key="'mc_' + m.value">
                            <button type="button"
                                    @click="toggleMetodoCobroPermitido(m.value)"
                                    :class="form.metodos_cobro_permitidos?.includes(m.value)
                                        ? 'bg-green-100 text-green-700 ring-2 ring-green-300 dark:bg-green-900/30 dark:text-green-300'
                                        : 'bg-gray-100 text-gray-600 dark:bg-slate-700 dark:text-gray-300'"
                                    class="px-2 py-1.5 rounded-lg text-[11px] font-semibold transition-colors">
                                <span x-text="m.label"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <!-- Saldo Máximo -->
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-300 mb-0.5">Saldo Máximo</label>
                        <input type="text" :value="formatNumber(form.saldo_maximo)"
                               @input="form.saldo_maximo = parseNumber($event.target.value)"
                               @blur="$event.target.value = formatNumber(form.saldo_maximo)"
                               class="w-full px-2.5 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm text-right font-mono">
                    </div>
                    <!-- Impresora -->
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-300 mb-0.5">Impresora</label>
                        <div class="flex gap-1.5">
                            <div class="relative w-full">
                                <template x-if="impresoras.length > 0 && !impresoraManual">
                                    <select x-model="form.impresora"
                                            class="w-full px-2.5 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm appearance-none pr-8">
                                        <option value="">— Seleccionar impresora —</option>
                                        <template x-for="imp in impresoras" :key="imp.nombre">
                                            <option :value="imp.nombre" x-text="imp.nombre + (imp.estado === 'predeterminada' ? ' ⭐' : '') + (imp.descripcion ? ' — ' + imp.descripcion : '') + (imp.fuente === 'agent' ? ' 🖨️' : imp.fuente === 'cups' ? ' 🖥️' : '')"></option>
                                        </template>
                                    </select>
                                </template>
                                <template x-if="impresoras.length === 0 || impresoraManual">
                                    <input type="text" x-model="form.impresora" placeholder="Nombre de impresora"
                                           class="w-full px-2.5 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                                </template>
                            </div>
                            <button type="button" @click="detectarImpresoras()" :disabled="detectandoImpresoras"
                                    class="px-2.5 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 hover:bg-gray-50 dark:hover:bg-slate-800 transition text-gray-600 dark:text-gray-300 flex-shrink-0"
                                    :title="detectandoImpresoras ? 'Buscando...' : 'Detectar impresoras locales'">
                                <i class="fas text-xs" :class="detectandoImpresoras ? 'fa-spinner fa-spin' : 'fa-print'"></i>
                            </button>
                            <button x-show="impresoras.length > 0" type="button" @click="impresoraManual = !impresoraManual"
                                    class="px-2 py-1.5 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 hover:bg-gray-50 dark:hover:bg-slate-800 transition text-gray-600 dark:text-gray-300 flex-shrink-0"
                                    :title="impresoraManual ? 'Volver a lista' : 'Escribir manualmente'">
                                <i class="fas text-xs" :class="impresoraManual ? 'fa-list' : 'fa-keyboard'"></i>
                            </button>
                        </div>
                        <!-- Estado: conectado con impresoras -->
                        <p x-show="impresoras.length > 0" class="text-[10px] mt-0.5"
                           :class="qzConectado ? 'text-green-500' : 'text-gray-400'">
                            <i class="fas fa-circle text-[6px] mr-0.5" :class="qzConectado ? 'text-green-500' : 'text-gray-400'"></i>
                            <span x-text="impresoras.length"></span> impresora(s) detectada(s)
                            <span x-show="qzConectado" class="text-green-500">· Agent conectado</span>
                        </p>
                        <!-- Estado: detectando -->
                        <p x-show="impresoras.length === 0 && (detectandoImpresoras || qzConectando) && !qzNoInstalado" class="text-[10px] text-blue-500 mt-0.5">
                            <i class="fas fa-spinner fa-spin text-[6px] mr-0.5"></i> Conectando con Sistemax Agent…
                        </p>
                        <!-- Estado: sin detección aún -->
                        <p x-show="impresoras.length === 0 && !detectandoImpresoras && !qzConectando && !qzNoInstalado" class="text-[10px] text-gray-400 mt-0.5">
                            <i class="fas fa-info-circle mr-0.5"></i> Presioná <i class="fas fa-print text-[8px]"></i> para detectar impresoras locales
                        </p>
                    </div>

                    <!-- Agent no instalado: instrucciones -->
                    <div x-show="qzNoInstalado" x-transition
                         class="col-span-2 mt-1 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 rounded-lg">
                        <div class="flex items-start gap-2">
                            <i class="fas fa-exclamation-triangle text-amber-500 mt-0.5 text-base"></i>
                            <div class="flex-1">
                                <p class="text-xs font-semibold text-amber-700 dark:text-amber-400">
                                    Sistemax Agent no detectado
                                </p>
                                <p class="text-[11px] text-amber-600 dark:text-amber-300 mt-0.5" x-text="qzErrorMsg"></p>

                                <div class="mt-2 p-2 bg-white/60 dark:bg-slate-800/60 rounded border border-amber-200 dark:border-amber-800">
                                    <p class="text-[11px] text-gray-700 dark:text-gray-200 font-semibold mb-1">
                                        <i class="fas fa-info-circle text-blue-500 mr-0.5"></i> Sistemax Agent corre en TU computadora (no en el servidor)
                                    </p>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mb-2">
                                        Instalación rápida: descargá la app local, ejecutala y luego reintentá conexión.
                                    </p>

                                    <div class="space-y-1.5">
                                        <!-- Paso 1: Verificar que Agent esté corriendo -->
                                        <p class="text-[11px] font-semibold text-gray-600 dark:text-gray-300">
                                            <span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-amber-500 text-white text-[9px] font-bold mr-1">1</span>
                                            ¿Sistemax Agent está corriendo?
                                        </p>
                                        <div class="ml-5 space-y-1">
                                            <p class="text-[10px] text-gray-500 dark:text-gray-400" x-show="navigator.platform.indexOf('Mac') > -1 || navigator.userAgent.indexOf('Mac') > -1">
                                                <i class="fab fa-apple mr-0.5"></i> Ejecutá la app y verificá que esté activa en segundo plano.
                                            </p>
                                            <p class="text-[10px] text-gray-500 dark:text-gray-400" x-show="navigator.platform.indexOf('Win') > -1 || navigator.userAgent.indexOf('Windows') > -1">
                                                <i class="fab fa-windows mr-0.5"></i> Ejecutá el instalador y abrí Sistemax Agent.
                                            </p>
                                            <p class="text-[10px] text-gray-500 dark:text-gray-400" x-show="navigator.platform.indexOf('Linux') > -1">
                                                <i class="fab fa-linux mr-0.5"></i> Instalá y ejecutá el servicio user de Sistemax Agent.
                                            </p>
                                        </div>

                                        <!-- Paso 2: Instalar -->
                                        <p class="text-[11px] font-semibold text-gray-600 dark:text-gray-300 mt-2">
                                            <span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-amber-500 text-white text-[9px] font-bold mr-1">2</span>
                                            ¿No lo tenés instalado?
                                        </p>
                                        <div class="ml-5">
                                            <p class="text-[10px] text-gray-500 dark:text-gray-400">
                                                Descargá e instalá Sistemax Agent desde <a href="/public/pos/downloads/" target="_blank" class="text-blue-600 dark:text-blue-400 underline">/public/pos/downloads/</a>.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-2 mt-2.5">
                                    <button type="button" @click="conectarQZ(true)" :disabled="qzConectando"
                                            class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-[11px] font-semibold rounded-lg transition flex items-center gap-1">
                                        <i class="fas text-[10px]" :class="qzConectando ? 'fa-spinner fa-spin' : 'fa-sync-alt'"></i>
                                        <span x-text="qzConectando ? 'Conectando…' : 'Reintentar conexión'"></span>
                                    </button>
                                    <a href="/public/pos/downloads/" target="_blank"
                                       class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-[11px] font-semibold rounded-lg transition flex items-center gap-1">
                                        <i class="fas fa-download text-[10px]"></i> Descargar Agent
                                    </a>
                                    <button type="button" @click="qzNoInstalado = false; impresoraManual = true"
                                            class="px-3 py-1.5 bg-gray-200 dark:bg-slate-700 hover:bg-gray-300 dark:hover:bg-slate-600 text-gray-700 dark:text-gray-300 text-[11px] font-semibold rounded-lg transition">
                                        <i class="fas fa-keyboard text-[10px] mr-0.5"></i> Escribir manualmente
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Numeración Factura -->
                <div class="p-3 bg-gray-50 dark:bg-slate-900 rounded-lg border border-gray-200 dark:border-slate-700">
                    <h3 class="text-xs font-bold text-gray-700 dark:text-gray-300 mb-2"><i class="fas fa-receipt mr-1 text-blue-600"></i> Numeración de Factura</h3>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-300 mb-0.5">Establecimiento</label>
                            <input type="number" x-model="form.factura_1" min="1"
                                   class="w-full px-2.5 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm font-mono text-center">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-300 mb-0.5">Punto Emisión</label>
                            <input type="number" x-model="form.factura_2" min="1"
                                   class="w-full px-2.5 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm font-mono text-center">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-300 mb-0.5">Nro. Inicial</label>
                            <input type="number" x-model="form.factura_3" min="1"
                                   class="w-full px-2.5 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm font-mono text-center">
                        </div>
                    </div>
                </div>

                <!-- Usuarios asignados -->
                <div class="p-3 bg-gray-50 dark:bg-slate-900 rounded-lg border border-gray-200 dark:border-slate-700">
                    <h3 class="text-xs font-bold text-gray-700 dark:text-gray-300 mb-2"><i class="fas fa-users mr-1 text-indigo-600"></i> Usuarios Asignados</h3>
                    <div class="max-h-32 overflow-y-auto space-y-0.5">
                        <template x-for="u in usuariosDisponibles" :key="u.id_login">
                            <label class="flex items-center gap-2 px-2 py-1 rounded hover:bg-gray-100 dark:hover:bg-slate-800 cursor-pointer transition-colors">
                                <input type="checkbox"
                                       :checked="form.usuarios.includes(String(u.id_login))"
                                       @change="toggleUsuario(String(u.id_login))"
                                       class="w-3.5 h-3.5 rounded border-gray-300 text-green-600 focus:ring-green-500">
                                <span class="text-xs text-gray-700 dark:text-gray-300" x-text="u.name || u.login"></span>
                                <span class="text-[11px] text-gray-400 ml-auto" x-text="'@' + u.login"></span>
                            </label>
                        </template>
                        <p x-show="usuariosDisponibles.length === 0" class="text-xs text-gray-400 text-center py-1">No hay usuarios disponibles</p>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="sticky bottom-0 bg-gray-50 dark:bg-slate-900 border-t border-gray-200 dark:border-slate-700 px-5 py-2.5 flex items-center justify-end gap-2 rounded-b-2xl">
                <button @click="showModal = false" class="px-3.5 py-1.5 bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-lg text-sm font-medium hover:bg-gray-300 dark:hover:bg-slate-600 transition-colors">Cancelar</button>
                <button @click="guardarCaja()" :disabled="saving" class="px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-semibold transition-colors disabled:opacity-50 flex items-center gap-2">
                    <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                    <span x-text="saving ? 'Guardando...' : 'Guardar'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- ============ MODAL KARDEX ============ -->
    <div x-show="showKardex" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showKardex = false">
        <div class="absolute inset-0 bg-black/50" @click="showKardex = false"></div>
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-5xl max-h-[90vh] flex flex-col modal-enter">
            <!-- Header -->
            <div class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-6 py-4 flex items-center justify-between rounded-t-2xl">
                <div>
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                        <i class="fas fa-book text-amber-500"></i>
                        <span>Kardex de Caja</span>
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400" x-text="kardex.cajaNombre"></p>
                </div>
                <button @click="showKardex = false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500 hover:bg-gray-200 dark:hover:bg-slate-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Filtros de extracto -->
            <div class="px-6 py-3 border-b border-gray-100 dark:border-slate-700 flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Desde:</label>
                    <input type="date" x-model="kardex.fechaDesde" @change="loadKardex()"
                           class="px-2 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Hasta:</label>
                    <input type="date" x-model="kardex.fechaHasta" @change="loadKardex()"
                           class="px-2 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                </div>
                <select x-model="kardex.filtroOperacion" @change="kardex.page = 1; loadKardex()"
                        class="px-2 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                    <option value="">Todas las operaciones</option>
                    <template x-for="op in kardex.catalogos.operaciones" :key="'op_' + op.id">
                        <option :value="String(op.id)" x-text="op.operacion"></option>
                    </template>
                </select>
                <select x-model="kardex.filtroReferencia" @change="kardex.page = 1; loadKardex()"
                        class="px-2 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                    <option value="">Todas las referencias</option>
                    <template x-for="ref in kardex.catalogos.referencias" :key="'ref_' + ref.id">
                        <option :value="String(ref.id)" x-text="ref.referencia"></option>
                    </template>
                </select>
                <input type="text" x-model.debounce.350ms="kardex.filtroTexto" @input="kardex.page = 1; loadKardex()" placeholder="Buscar concepto, beneficiario, comprobante..."
                       class="min-w-[240px] px-3 py-1.5 border border-gray-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                <button @click="kardex.fechaDesde = ''; kardex.fechaHasta = ''; kardex.filtroOperacion = ''; kardex.filtroReferencia = ''; kardex.filtroTexto = ''; kardex.page = 1; loadKardex()" class="text-xs text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                    <i class="fas fa-times-circle mr-1"></i> Limpiar
                </button>
                <div class="ml-auto flex items-center gap-4 text-xs">
                    <span class="text-green-600 dark:text-green-400 font-semibold"><i class="fas fa-arrow-up mr-1"></i> Ingresos: <span x-text="formatMoney(kardex.resumen.total_ingresos)"></span></span>
                    <span class="text-red-600 dark:text-red-400 font-semibold"><i class="fas fa-arrow-down mr-1"></i> Egresos: <span x-text="formatMoney(kardex.resumen.total_egresos)"></span></span>
                    <span class="text-gray-900 dark:text-white font-bold"><i class="fas fa-equals mr-1"></i> Saldo: <span x-text="formatMoney(kardex.resumen.saldo_neto)"></span></span>
                </div>
            </div>

            <div class="px-6 py-2 border-b border-gray-100 dark:border-slate-700 grid grid-cols-1 lg:grid-cols-2 gap-3">
                <div class="rounded-lg border border-gray-200 dark:border-slate-700 p-2.5">
                    <p class="text-[11px] uppercase font-semibold text-gray-500 dark:text-gray-400 mb-1">Resumen por operación</p>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="op in (kardex.resumen.por_operacion || [])" :key="'sumop_' + op.etiqueta">
                            <span class="px-2 py-0.5 rounded-full bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 text-[11px]">
                                <span x-text="op.etiqueta"></span>:
                                <strong x-text="formatMoney(op.saldo)"></strong>
                            </span>
                        </template>
                        <span x-show="(kardex.resumen.por_operacion || []).length === 0" class="text-xs text-gray-400">Sin datos</span>
                    </div>
                </div>
                <div class="rounded-lg border border-gray-200 dark:border-slate-700 p-2.5">
                    <p class="text-[11px] uppercase font-semibold text-gray-500 dark:text-gray-400 mb-1">Resumen por referencia</p>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="ref in (kardex.resumen.por_referencia || [])" :key="'sumref_' + ref.etiqueta">
                            <span class="px-2 py-0.5 rounded-full bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 text-[11px]">
                                <span x-text="ref.etiqueta"></span>:
                                <strong x-text="formatMoney(ref.saldo)"></strong>
                            </span>
                        </template>
                        <span x-show="(kardex.resumen.por_referencia || []).length === 0" class="text-xs text-gray-400">Sin datos</span>
                    </div>
                </div>
            </div>

            <!-- Botones de Operaciones -->
            <div class="px-6 py-2 border-b border-gray-100 dark:border-slate-700 flex flex-wrap gap-2">
                <button @click="abrirOperacion('salida_contacto')" class="px-3 py-1.5 rounded-lg bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:hover:bg-red-900/40 text-red-600 dark:text-red-400 text-xs font-semibold flex items-center gap-1.5 transition-colors">
                    <i class="fas fa-arrow-up"></i> Salida Contacto
                </button>
                <button @click="abrirOperacion('entrada_contacto')" class="px-3 py-1.5 rounded-lg bg-green-50 hover:bg-green-100 dark:bg-green-900/20 dark:hover:bg-green-900/40 text-green-600 dark:text-green-400 text-xs font-semibold flex items-center gap-1.5 transition-colors">
                    <i class="fas fa-arrow-down"></i> Entrada Contacto
                </button>
                <div class="w-px h-6 bg-gray-200 dark:bg-slate-700 self-center"></div>
                <button @click="abrirOperacion('salida_caja')" class="px-3 py-1.5 rounded-lg bg-orange-50 hover:bg-orange-100 dark:bg-orange-900/20 dark:hover:bg-orange-900/40 text-orange-600 dark:text-orange-400 text-xs font-semibold flex items-center gap-1.5 transition-colors">
                    <i class="fas fa-exchange-alt"></i> Caja → Caja
                </button>
                <div class="w-px h-6 bg-gray-200 dark:bg-slate-700 self-center"></div>
                <button @click="abrirOperacion('salida_cuenta')" class="px-3 py-1.5 rounded-lg bg-purple-50 hover:bg-purple-100 dark:bg-purple-900/20 dark:hover:bg-purple-900/40 text-purple-600 dark:text-purple-400 text-xs font-semibold flex items-center gap-1.5 transition-colors">
                    <i class="fas fa-arrow-up text-[10px]"></i> Salida Cuenta
                </button>
                <button @click="abrirOperacion('entrada_cuenta')" class="px-3 py-1.5 rounded-lg bg-purple-50 hover:bg-purple-100 dark:bg-purple-900/20 dark:hover:bg-purple-900/40 text-purple-600 dark:text-purple-400 text-xs font-semibold flex items-center gap-1.5 transition-colors">
                    <i class="fas fa-arrow-down text-[10px]"></i> Entrada Cuenta
                </button>
                <div class="w-px h-6 bg-gray-200 dark:bg-slate-700 self-center"></div>
                <button @click="abrirOperacion('salida_banco')" class="px-3 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/40 text-blue-600 dark:text-blue-400 text-xs font-semibold flex items-center gap-1.5 transition-colors">
                    <i class="fas fa-arrow-up text-[10px]"></i> Salida Banco
                </button>
                <button @click="abrirOperacion('entrada_banco')" class="px-3 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/40 text-blue-600 dark:text-blue-400 text-xs font-semibold flex items-center gap-1.5 transition-colors">
                    <i class="fas fa-arrow-down text-[10px]"></i> Entrada Banco
                </button>
            </div>

            <!-- Tabla -->
            <div class="flex-1 overflow-auto px-6 py-3">
                <div x-show="kardex.loading" class="flex items-center justify-center py-12">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-amber-500"></div>
                </div>

                <table x-show="!kardex.loading && kardex.data.length > 0" class="w-full text-sm">
                    <thead class="sticky top-0 bg-gray-50 dark:bg-slate-900">
                        <tr class="text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">
                            <th class="px-3 py-2">Fecha</th>
                            <th class="px-3 py-2">Operación</th>
                            <th class="px-3 py-2">Referencia</th>
                            <th class="px-3 py-2">Concepto</th>
                            <th class="px-3 py-2">Medio</th>
                            <th class="px-3 py-2">Comprobante</th>
                            <th class="px-3 py-2 text-right">Entrada</th>
                            <th class="px-3 py-2 text-right">Salida</th>
                            <th class="px-3 py-2">Usuario</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
                        <template x-for="m in kardex.data" :key="m.id">
                            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors">
                                <td class="px-3 py-2.5 text-gray-700 dark:text-gray-300 whitespace-nowrap text-xs" x-text="formatFecha(m.fecha)"></td>
                                <td class="px-3 py-2.5">
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
                                </td>
                                <td class="px-3 py-2.5 text-gray-500 dark:text-gray-300 text-xs max-w-[210px] truncate" :title="m.referencia_nombre || m.referencia">
                                    <span x-text="m.referencia_nombre || (m.referencia ? ('Referencia #' + m.referencia) : '-')"></span>
                                </td>
                                <td class="px-3 py-2.5 text-gray-700 dark:text-gray-300 max-w-[220px] truncate" :title="m.concepto" x-text="m.concepto || '-'"></td>
                                <td class="px-3 py-2.5 text-gray-500 dark:text-gray-400 text-xs" x-text="m.medio_cobro || '-'"></td>
                                <td class="px-3 py-2.5 text-gray-500 dark:text-gray-400 font-mono text-xs" x-text="m.comprobante || '-'"></td>
                                <td class="px-3 py-2.5 text-right font-semibold whitespace-nowrap text-green-600 dark:text-green-400">
                                    <span x-show="parseFloat(m.credito) > 0" x-text="formatMoney(m.credito)"></span>
                                    <span x-show="parseFloat(m.credito) <= 0" class="text-gray-300 dark:text-slate-600">-</span>
                                </td>
                                <td class="px-3 py-2.5 text-right font-semibold whitespace-nowrap text-red-600 dark:text-red-400">
                                    <span x-show="parseFloat(m.debito) > 0" x-text="formatMoney(m.debito)"></span>
                                    <span x-show="parseFloat(m.debito) <= 0" class="text-gray-300 dark:text-slate-600">-</span>
                                </td>
                                <td class="px-3 py-2.5 text-gray-500 dark:text-gray-400 text-xs" x-text="m.nombre_usuario"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <div x-show="!kardex.loading && kardex.data.length === 0" class="py-12 text-center">
                    <i class="fas fa-inbox text-4xl text-gray-300 dark:text-slate-600 mb-2"></i>
                    <p class="text-gray-400 text-sm">No hay movimientos en esta caja</p>
                </div>
            </div>

            <!-- Paginación -->
            <div x-show="kardex.totalPages > 1" class="border-t border-gray-200 dark:border-slate-700 px-6 py-3 flex items-center justify-between">
                <span class="text-xs text-gray-500" x-text="'Pág. ' + kardex.page + ' de ' + kardex.totalPages + ' — ' + kardex.total + ' movimientos'"></span>
                <div class="flex gap-2">
                    <button @click="kardex.page--; loadKardex()" :disabled="kardex.page <= 1"
                            class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-slate-700 text-sm disabled:opacity-40"><i class="fas fa-chevron-left"></i></button>
                    <button @click="kardex.page++; loadKardex()" :disabled="kardex.page >= kardex.totalPages"
                            class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-slate-700 text-sm disabled:opacity-40"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ MODAL OPERACIÓN KARDEX ============ -->
    <div x-show="showOperacion" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4" @keydown.escape.window="showOperacion = false">
        <div class="absolute inset-0 bg-black/50" @click="showOperacion = false"></div>
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-lg modal-enter">
            <!-- Header -->
            <div class="border-b border-gray-200 dark:border-slate-700 px-6 py-4 flex items-center justify-between rounded-t-2xl">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                    <i class="fas" :class="opForm.es_entrada ? 'fa-arrow-down text-green-500' : 'fa-arrow-up text-red-500'"></i>
                    <span x-text="opForm.titulo"></span>
                </h2>
                <button @click="showOperacion = false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500 hover:bg-gray-200 dark:hover:bg-slate-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="px-6 py-5 space-y-4">
                <!-- Tipo caja a caja: elegir dirección -->
                <div x-show="opForm.tipo_base === 'caja'" class="flex gap-2">
                    <button @click="opForm.tipo_operacion = 'salida_caja'; opForm.es_entrada = false; opForm.titulo = 'Salida Caja a Caja'"
                            class="flex-1 py-2 rounded-lg text-xs font-semibold text-center transition-colors"
                            :class="opForm.tipo_operacion === 'salida_caja' ? 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400 ring-2 ring-red-300' : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-400'">
                        <i class="fas fa-arrow-up mr-1"></i> Salida
                    </button>
                    <button @click="opForm.tipo_operacion = 'entrada_caja'; opForm.es_entrada = true; opForm.titulo = 'Entrada Caja a Caja'"
                            class="flex-1 py-2 rounded-lg text-xs font-semibold text-center transition-colors"
                            :class="opForm.tipo_operacion === 'entrada_caja' ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400 ring-2 ring-green-300' : 'bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-400'">
                        <i class="fas fa-arrow-down mr-1"></i> Entrada
                    </button>
                </div>

                <!-- Referencia (select dinámico) -->
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1" x-text="opForm.ref_label + ' *'"></label>
                    <select x-model="opForm.referencia_id" @change="opRefChange()"
                            class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-green-500">
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
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm font-mono focus:ring-2 focus:ring-green-500">
                </div>

                <!-- Concepto -->
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Concepto *</label>
                    <input type="text" x-model="opForm.concepto" placeholder="Descripción de la operación" maxlength="60"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm focus:ring-2 focus:ring-green-500">
                </div>

                <!-- Medio de cobro -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Medio</label>
                        <select x-model="opForm.medio_cobro"
                                class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                            <option value="EFECTIVO">Efectivo</option>
                            <option value="CHEQUE">Cheque</option>
                            <option value="TRANSFERENCIA">Transferencia</option>
                            <option value="TARJETA">Tarjeta</option>
                            <option value="DEPOSITO">Depósito</option>
                            <option value="OTRO">Otro</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Comprobante</label>
                        <input type="text" x-model="opForm.comprobante" placeholder="Nro. comprobante"
                               class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm font-mono">
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="bg-gray-50 dark:bg-slate-900 border-t border-gray-200 dark:border-slate-700 px-6 py-4 flex items-center justify-end gap-3 rounded-b-2xl">
                <button @click="showOperacion = false" class="px-4 py-2.5 bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium hover:bg-gray-300 dark:hover:bg-slate-600 transition-colors">Cancelar</button>
                <button @click="guardarOperacion()" :disabled="opSaving"
                        class="px-5 py-2.5 rounded-xl text-sm font-semibold transition-colors disabled:opacity-50 flex items-center gap-2 text-white"
                        :class="opForm.es_entrada ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'">
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
         x-transition:leave-start="translate-y-0 opacity-100"
         x-transition:leave-end="translate-y-4 opacity-0"
         class="fixed bottom-6 right-6 z-[100] px-5 py-3 rounded-xl shadow-lg text-white text-sm font-medium flex items-center gap-2"
         :class="toast.type === 'error' ? 'bg-red-600' : 'bg-green-600'">
        <i class="fas" :class="toast.type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'"></i>
        <span x-text="toast.msg"></span>
    </div>
</div>

<script>
function cajasApp() {
    return {
        idEmpresa: <?= (int)$id_empresa ?>,
        miCajaMode: <?= $miCajaMode ? 'true' : 'false' ?>,
        filterIdCaja: <?= $miCajaMode ? (int)$id_caja_def : 0 ?>,
        cajas: [],
        permisos: window.__PERMISOS__ || {},
        isDark: document.documentElement.classList.contains('dark'),
        loading: true,
        search: '',
        filtroSucursal: 'all',
        page: 1,
        totalPages: 1,
        totalResultados: 0,
        stats: { total: 0 },
        sucursales: [],
        usuariosDisponibles: [],
        metodosCobroCatalog: [
            { value: 'PENDIENTE', label: 'Pendiente' },
            { value: 'EFECTIVO', label: 'Efectivo' },
            { value: 'TARJETA', label: 'Tarjeta' },
            { value: 'TRANSFERENCIA', label: 'Transfer.' },
            { value: 'QR', label: 'QR / PIX' },
            { value: 'CREDITO', label: 'Crédito' }
        ],

        // Modal
        showModal: false,
        saving: false,
        form: {},

        // Kardex
        showKardex: false,
        kardex: {
            idCaja: null,
            cajaNombre: '',
            data: [],
            loading: false,
            page: 1,
            totalPages: 1,
            total: 0,
            fechaDesde: '',
            fechaHasta: '',
            filtroOperacion: '',
            filtroReferencia: '',
            filtroTexto: '',
            catalogos: { operaciones: [], referencias: [] },
            resumen: { total_ingresos: 0, total_egresos: 0, saldo_neto: 0, por_operacion: [], por_referencia: [] }
        },

        // Operación
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

        // Toast
        toast: { show: false, msg: '', type: 'ok' },

        async init() {
            // Usar datos pre-cargados desde PHP
            this.sucursales = window.__SUCURSALES__ || [];
            this.usuariosDisponibles = window.__USUARIOS__ || [];
            this.loadCajas();
        },

        async loadCatalogos() {
            try {
                const res = await fetch('api/catalogos.php?tipo=all&id_empresa=' + encodeURIComponent(this.idEmpresa));
                if (!res.ok) {
                    console.error('Catalogos HTTP error:', res.status);
                    return;
                }
                const data = await res.json();
                console.log('Catalogos response:', data);
                if (data.ok) {
                    this.sucursales = data.sucursales || [];
                    this.usuariosDisponibles = data.usuarios || [];
                    console.log('Sucursales cargadas:', this.sucursales.length, this.sucursales);
                }
            } catch (e) { console.error('Error loadCatalogos:', e); }
        },

        async loadCajas() {
            this.loading = true;
            try {
                const params = new URLSearchParams({
                    id_empresa: this.idEmpresa,
                    search: this.search,
                    sucursal: this.filtroSucursal,
                    page: this.page,
                    per_page: 25,
                    _ts: Date.now()
                });
                if (this.filterIdCaja > 0) params.set('id_caja', this.filterIdCaja);
                const res = await fetch('api/list.php?' + params, { cache: 'no-store' });
                const data = await res.json();
                if (data.ok) {
                    this.cajas = data.data;
                    this.totalPages = data.pages;
                    this.totalResultados = data.total;
                    if (data.stats) this.stats = data.stats;
                }
            } catch (e) { console.error(e); }
            this.loading = false;
        },

        impresoras: [],
        detectandoImpresoras: false,
        impresoraManual: false,
        qzConectado: false,
        qzConectando: false,
        qzNoInstalado: false,
        qzErrorMsg: '',
        smxPrinter: null,

        normalizePrinterName(name) {
            return String(name || '').trim().toLowerCase();
        },

        ensureCurrentPrinterInList() {
            const actual = String(this.form?.impresora || '').trim();
            if (actual === '') return;

            const normActual = this.normalizePrinterName(actual);
            const existente = (this.impresoras || []).find(i => this.normalizePrinterName(i?.nombre) === normActual);
            if (existente) {
                // Usar el nombre exacto de la opción para que el <select> lo marque seleccionado.
                this.form.impresora = String(existente.nombre || actual).trim();
                return;
            }

            this.impresoras = [
                {
                    nombre: actual,
                    estado: 'guardada',
                    descripcion: 'Configurada en esta caja',
                    fuente: 'actual'
                },
                ...(this.impresoras || [])
            ];
            this.form.impresora = actual;
        },

        // ── Conectar a Sistemax Agent silenciosamente y cargar impresoras ──
        // manual=true → usuario clickeó Reintentar (más retries)
        async conectarQZ(manual = false) {
            if (typeof SmxPrinter === 'undefined') {
                this.qzNoInstalado = true;
                this.qzErrorMsg = 'No se pudo cargar el bridge de impresión local.';
                return;
            }

            if (!this.smxPrinter) {
                this.smxPrinter = new SmxPrinter({
                    strategy: 'agent-only',
                    agentBaseUrl: 'http://127.0.0.1:17890'
                });
            }

            if (this.smxPrinter.isActive()) {
                this.qzConectado = true;
                this.qzNoInstalado = false;
                if (this.impresoras.length === 0) await this.detectarImpresoras();
                return;
            }
            if (this.qzConectando) return;
            this.qzConectando = true;
            this.qzNoInstalado = false;
            this.qzErrorMsg = '';
            try {
                await this.smxPrinter.connect();
                this.qzConectado = true;
                this.qzNoInstalado = false;
                console.log('✅ Sistemax Agent conectado');
                await this.detectarImpresoras();
            } catch (e) {
                this.qzConectado = false;
                this.qzNoInstalado = true;
                this.qzErrorMsg = e.message || 'No se pudo conectar con Sistemax Agent.';
                console.warn('⚠️ Sistemax Agent:', this.qzErrorMsg);
            }
            this.qzConectando = false;
        },

        async detectarImpresoras() {
            this.detectandoImpresoras = true;
            let lista = [];

            // ── 1. Agent local: detectar impresoras de la máquina LOCAL ──
            if (this.smxPrinter) {
                try {
                    if (!this.smxPrinter.isActive()) await this.smxPrinter.connect();
                    this.qzConectado = true;
                    const printers = await this.smxPrinter.findPrinters();
                    if (Array.isArray(printers)) {
                        for (const name of printers) {
                            lista.push({ nombre: name, descripcion: '', estado: 'disponible', fuente: 'agent' });
                        }
                    }
                    console.log(`✅ Total impresoras detectadas (agent): ${lista.length}`, lista.map(i => i.nombre));

                } catch (agentErr) {
                    console.warn('Sistemax Agent no disponible:', agentErr.message || agentErr);
                    this.qzConectado = false;
                }
            }

            // ── 2. Fallback: impresoras del servidor (CUPS) ──
            if (lista.length === 0) {
                try {
                    const res = await fetch('api/impresoras.php');
                    const data = await res.json();
                    if (data.ok && data.impresoras.length > 0) {
                        lista = data.impresoras;
                    }
                } catch (e) {
                    console.warn('API impresoras servidor:', e);
                }
            }

            // Conservar la impresora ya guardada en la caja aunque no esté detectada ahora
            const impresoraActual = String(this.form?.impresora || '').trim();
            const normActual = this.normalizePrinterName(impresoraActual);
            if (impresoraActual !== '' && !lista.find(i => this.normalizePrinterName(i?.nombre) === normActual)) {
                lista.unshift({
                    nombre: impresoraActual,
                    estado: 'guardada',
                    descripcion: 'Configurada en esta caja',
                    fuente: 'actual'
                });
            }

            this.impresoras = lista;
            this.ensureCurrentPrinterInList();

            if (lista.length > 0) {
                const fuente = this.qzConectado ? 'locales (Agent)' : 'del servidor';
                this.showToast(`${lista.length} impresora(s) ${fuente} detectada(s)`, 'ok');
                // Auto-seleccionar si el campo está vacío y hay impresora por defecto
                if (!this.form.impresora && lista.length > 0) {
                    this.form.impresora = lista[0].nombre;
                }
            } else {
                this.showToast('No se detectaron impresoras. Instalá Sistemax Agent o escribí el nombre manualmente.', 'info');
                this.impresoraManual = true;
            }

            this.detectandoImpresoras = false;
        },

        nuevaCaja() {
            const metodosPermitidos = this.defaultMetodosCobroPermitidos();
            this.form = {
                id_caja: null,
                caja: '',
                tipo: '1',
                id_sucursal: this.sucursales.length ? String(this.sucursales[0].id_sucursal) : '1',
                id_moneda: 1,
                saldo_maximo: 50000000,
                timbrado: '',
                vencimiento: '',
                fecha_inicio_timbrado: '',
                factura_1: 1,
                factura_2: 1,
                factura_3: 1,
                impresora: '',
                metodo_cobro_permitido: metodosPermitidos.join(','),
                metodos_cobro_permitidos: metodosPermitidos,
                usuarios: []
            };
            this.showModal = true;
            // Auto-conectar Agent y detectar impresoras locales
            this.$nextTick(() => this.conectarQZ());
        },

        async editarCaja(c) {
            let row = c || {};
            try {
                const params = new URLSearchParams({
                    id_empresa: String(this.idEmpresa),
                    id_caja: String(c?.id_caja || 0),
                    _ts: String(Date.now())
                });
                const res = await fetch('api/get.php?' + params.toString(), { cache: 'no-store' });
                const data = await res.json();
                if (data?.ok && data?.data) {
                    row = { ...c, ...data.data };
                }
            } catch (e) {
                console.warn('No se pudo refrescar detalle de caja para edición:', e);
            }

            const metodosPermitidos = this.normalizeMetodosCobroPermitidos(row.metodo_cobro_permitido || '');
            this.form = {
                id_caja: row.id_caja,
                caja: row.caja,
                tipo: String(row.tipo),
                id_sucursal: String(row.id_sucursal),
                id_moneda: row.id_moneda || 1,
                saldo_maximo: row.saldo_maximo,
                timbrado: row.timbrado || '',
                vencimiento: row.vencimiento || '',
                fecha_inicio_timbrado: row.fecha_inicio_timbrado || '',
                factura_1: row.factura_1,
                factura_2: row.factura_2,
                factura_3: row.factura_3,
                impresora: String(row.impresora || row.impresor || '').trim(),
                metodo_cobro_permitido: metodosPermitidos.join(','),
                metodos_cobro_permitidos: metodosPermitidos,
                usuarios: (row.usuarios_asignados || []).map(u => String(u.id_login))
            };
            this.ensureCurrentPrinterInList();
            this.showModal = true;
            // Auto-conectar Agent y detectar impresoras locales
            this.$nextTick(() => this.conectarQZ());
        },

        defaultMetodosCobroPermitidos() {
            return this.metodosCobroCatalog.map(m => m.value);
        },

        normalizeMetodosCobroPermitidos(raw) {
            const allowed = this.defaultMetodosCobroPermitidos();
            const txt = String(raw || '').trim();
            if (!txt) return allowed;
            const parsed = txt.split(',').map(v => String(v || '').trim().toUpperCase()).filter(v => allowed.includes(v));
            return parsed.length > 0 ? Array.from(new Set(parsed)) : allowed;
        },

        toggleMetodoCobroPermitido(value) {
            const v = String(value || '').toUpperCase();
            if (!Array.isArray(this.form.metodos_cobro_permitidos)) {
                this.form.metodos_cobro_permitidos = this.defaultMetodosCobroPermitidos();
            }
            const idx = this.form.metodos_cobro_permitidos.indexOf(v);
            if (idx === -1) {
                this.form.metodos_cobro_permitidos.push(v);
            } else {
                this.form.metodos_cobro_permitidos.splice(idx, 1);
            }
            if (this.form.metodos_cobro_permitidos.length === 0) {
                this.form.metodos_cobro_permitidos = ['EFECTIVO'];
            }
            this.form.metodo_cobro_permitido = this.form.metodos_cobro_permitidos.join(',');
        },

        toggleUsuario(idLogin) {
            const idx = this.form.usuarios.indexOf(idLogin);
            if (idx === -1) {
                this.form.usuarios.push(idLogin);
            } else {
                this.form.usuarios.splice(idx, 1);
            }
        },

        async guardarCaja() {
            if (!this.form.caja.trim()) {
                this.showToast('El nombre de la caja es obligatorio', 'error');
                return;
            }
            if (!Array.isArray(this.form.metodos_cobro_permitidos) || this.form.metodos_cobro_permitidos.length === 0) {
                this.form.metodos_cobro_permitidos = ['EFECTIVO'];
            }
            this.form.metodo_cobro_permitido = this.form.metodos_cobro_permitidos.join(',');
            this.saving = true;
            try {
                const payload = {
                    action: this.form.id_caja ? 'update' : 'create',
                    id_empresa: this.idEmpresa,
                    caja: this.form,
                    usuarios: this.form.usuarios.map(Number)
                };
                const res = await fetch('api/guardar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.ok) {
                    this.showToast(data.msg);
                    this.showModal = false;
                    this.loadCajas();
                } else {
                    this.showToast(data.error, 'error');
                }
            } catch (e) {
                this.showToast('Error de conexión', 'error');
            }
            this.saving = false;
        },

        async eliminarCaja(c) {
            if (!confirm(`¿Eliminar la caja "${c.caja}"? Esta acción no se puede deshacer.`)) return;
            try {
                const res = await fetch('api/eliminar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id_caja: c.id_caja, id_empresa: this.idEmpresa })
                });
                const data = await res.json();
                if (data.ok) {
                    this.showToast(data.msg);
                    this.loadCajas();
                } else {
                    this.showToast(data.error, 'error');
                }
            } catch (e) {
                this.showToast('Error de conexión', 'error');
            }
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
            try {
                const params = new URLSearchParams({
                    id_caja: this.kardex.idCaja,
                    page: this.kardex.page,
                    per_page: 50,
                    fecha_desde: this.kardex.fechaDesde,
                    fecha_hasta: this.kardex.fechaHasta,
                    operacion: this.kardex.filtroOperacion,
                    referencia: this.kardex.filtroReferencia,
                    q: this.kardex.filtroTexto
                });
                const res = await fetch('api/kardex.php?' + params);
                const data = await res.json();
                if (data.ok) {
                    this.kardex.data = data.data;
                    this.kardex.totalPages = data.pages;
                    this.kardex.total = data.total;
                    this.kardex.catalogos = data.catalogos || { operaciones: [], referencias: [] };
                    this.kardex.resumen = data.resumen || { total_ingresos: 0, total_egresos: 0, saldo_neto: 0, por_operacion: [], por_referencia: [] };
                }
            } catch (e) { console.error(e); }
            this.kardex.loading = false;
        },

        async loadOpCatalogos() {
            if (this.opCatalogosLoaded) return;
            try {
                const res = await fetch('api/kardex_catalogos.php');
                const data = await res.json();
                if (data.ok) {
                    this.opCatalogos.clientes = (data.clientes || []).map(c => ({ id: c.id, nombre: c.nombre + (c.ruc ? ' (' + c.ruc + ')' : '') }));
                    this.opCatalogos.bancos = (data.bancos || []).map(b => ({ id: b.id_banco, nombre: b.banco + (b.numero_cuenta ? ' - ' + b.numero_cuenta : '') }));
                    this.opCatalogos.cuentas = (data.cuentas || []).map(c => ({ id: c.id, nombre: c.cuenta }));
                    this.opCatalogos.cajas = (data.cajas || []).map(c => ({ id: c.id_caja, nombre: c.caja }));
                    this.opCatalogosLoaded = true;
                }
            } catch (e) { console.error('Error cargando catálogos:', e); }
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
                tipo_operacion: tipo,
                tipo_base: cfg.base,
                es_entrada: cfg.entrada,
                titulo: cfg.titulo,
                ref_label: cfg.label,
                ref_options: cfg.options,
                referencia_id: '',
                referencia_nombre: '',
                importe: '',
                concepto: '',
                medio_cobro: 'EFECTIVO',
                comprobante: ''
            };
            this.showOperacion = true;
        },

        opRefChange() {
            const sel = this.opForm.ref_options.find(r => r.id == this.opForm.referencia_id);
            this.opForm.referencia_nombre = sel ? sel.nombre : '';
        },

        async guardarOperacion() {
            if (!this.opForm.referencia_id) { this.showToast('Seleccione una referencia', 'error'); return; }
            if (!this.opForm.importe || this.opForm.importe <= 0) { this.showToast('Ingrese un importe válido', 'error'); return; }
            if (!this.opForm.concepto.trim()) { this.showToast('Ingrese el concepto', 'error'); return; }

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
                const res = await fetch('api/kardex_operacion.php', {
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
                    this.showToast(data.error || 'Error al registrar', 'error');
                }
            } catch (e) {
                this.showToast('Error de conexión', 'error');
            }
            this.opSaving = false;
        },

        formatFecha(f) {
            if (!f) return '-';
            const d = new Date(f);
            return d.toLocaleDateString('es-PY', { day:'2-digit', month:'2-digit', year:'numeric' }) + ' ' + d.toLocaleTimeString('es-PY', { hour:'2-digit', minute:'2-digit' });
        },

        formatMoney(val) {
            return new Intl.NumberFormat('es-PY', { style: 'currency', currency: 'PYG', maximumFractionDigits: 0 }).format(val || 0);
        },

        formatNumber(val) {
            const n = parseFloat(val) || 0;
            return new Intl.NumberFormat('es-PY', { maximumFractionDigits: 0 }).format(n);
        },

        formatMetodosCobro(raw) {
            const txt = String(raw || '').trim();
            if (!txt) return 'Todos';
            const map = {
                PENDIENTE: 'Pendiente',
                EFECTIVO: 'Efectivo',
                TARJETA: 'Tarjeta',
                TRANSFERENCIA: 'Transfer.',
                QR: 'QR/PIX',
                CREDITO: 'Crédito'
            };
            const out = txt
                .split(',')
                .map(v => String(v || '').trim().toUpperCase())
                .filter(Boolean)
                .map(v => map[v] || v);
            return out.length ? out.join(', ') : 'Todos';
        },

        parseNumber(str) {
            return parseInt(String(str).replace(/\D/g, ''), 10) || 0;
        },

        isVencido(fecha) {
            if (!fecha) return false;
            return new Date(fecha) < new Date();
        },

        pad(num, len) {
            return String(num || 0).padStart(len, '0');
        },

        showToast(msg, type = 'ok') {
            this.toast = { show: true, msg, type };
            setTimeout(() => this.toast.show = false, 3000);
        }
    };
}
</script>
</body>
</html>
