<?php
/**
 * Módulo Contactos - Frontend Desktop (limpio)
 * CRUD sobre tabla clientes
 */
require_once __DIR__ . '/../../config/bootstrap.php';
Session::start();

$id_empresa = $_SESSION['id_empresa'] ?? 169;
Permission::requireAccess('app_grid_clientes');
$permisos = Permission::getAppPermissions('app_grid_clientes');

$userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$isMobile = preg_match('/(android|webos|iphone|ipad|ipod|blackberry|windows phone|opera mini|mobile)/i', $userAgent);
if ($isMobile) {
    header('Location: /public/contactos/mobile.php');
    exit;
}
$pageTitle = t('contactos.title');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(SmxI18n::getLocale()) ?>" class="scroll-smooth" :class="isDark ? 'dark' : ''" x-data="{ isDark: localStorage.getItem('theme') === 'dark' }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - SistemaX PRO</title>
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
    <link rel="stylesheet" href="/public/_lib/ag-grid/ag-grid-enterprise/package/styles/ag-grid.css">
    <link rel="stylesheet" href="/public/_lib/ag-grid/ag-grid-enterprise/package/styles/ag-theme-quartz.css">
    <script src="/public/assets/js/ag-grid-locale.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
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
    <style>
        [x-cloak] { display: none !important; }
        .modal-enter { animation: modalIn .2s ease-out; }
        @keyframes modalIn { from { opacity:0; transform:scale(.98) translateY(10px);} to { opacity:1; transform:scale(1) translateY(0);} }
        .ag-theme-quartz,
        .ag-theme-quartz-dark {
            --ag-font-family: Inter, system-ui, sans-serif;
            --ag-font-size: 14px;
        }
        .kardex-grid {
            width: 100%;
            height: 440px;
        }
        .contactos-grid {
            width: 100%;
            height: calc(100vh - 210px);
            min-height: 380px;
        }
        .contacto-stack { line-height: 1.25; }
        .contacto-stack {
            display: flex;
            flex-direction: column;
            justify-content: center;
            height: 100%;
        }
        .contacto-stack .main { font-weight: 400; color: rgb(15 23 42); }
        .dark .contacto-stack .main { color: rgb(248 250 252); }
        .contacto-stack .meta { font-size: 11px; color: rgb(100 116 139); }
        .dark .contacto-stack .meta { color: rgb(148 163 184); }
        .ag-theme-quartz .ag-cell-wrapper,
        .ag-theme-quartz-dark .ag-cell-wrapper {
            align-items: center;
        }
        .contacto-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border-radius: 0;
            font-size: 11px;
            font-weight: 400;
            background: transparent !important;
        }
        .contacto-badge.cliente { color: rgb(29, 78, 216); }
        .contacto-badge.proveedor { color: rgb(180, 83, 9); }
        .contacto-badge.empleado { color: rgb(5, 150, 105); }
        .contacto-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 2px 10px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 700;
        }
        .contacto-status.on { background: rgba(34, 197, 94, 0.14); color: rgb(21, 128, 61); }
        .contacto-status.off { background: rgba(239, 68, 68, 0.14); color: rgb(185, 28, 28); }
        .ag-theme-quartz .kardex-row-anulado,
        .ag-theme-quartz-dark .kardex-row-anulado {
            opacity: .48;
        }
        .ag-theme-quartz .kardex-row-anulado .ag-cell,
        .ag-theme-quartz-dark .kardex-row-anulado .ag-cell {
            color: rgb(100 116 139);
            text-decoration: line-through;
        }
        .kardex-request-status {
            font-size: 12px;
            font-weight: 500;
        }
        .kardex-request-status.pending { color: rgb(180, 83, 9); }
        .kardex-request-status.authorized { color: rgb(21, 128, 61); }
    </style>
</head>
<body class="bg-gray-50 dark:bg-slate-900 min-h-screen font-sans antialiased">
<script>
window.__PERMISOS__ = <?= json_encode($permisos) ?>;
window.__SESSION_ADMIN__ = <?= Session::isAdmin() ? 'true' : 'false' ?>;
</script>

<div x-data="contactosApp()" x-init="init()" class="min-h-screen">
    <header class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 sticky top-0 z-30">
        <div class="max-w-[1600px] mx-auto px-4 h-12 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                        class="w-8 h-8 rounded-lg bg-red-600/10 border border-red-500/40 flex items-center justify-center text-red-600 dark:text-red-400 hover:bg-red-600/20 active:scale-95 transition-all cursor-pointer"
                        title="<?= htmlspecialchars(t('common.logout')) ?>">
                    <i class="fas fa-sign-out-alt"></i>
                </button>
                <div>
                    <h1 class="text-base font-bold text-gray-900 dark:text-white leading-tight"><?= htmlspecialchars(t('contactos.title')) ?></h1>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400 leading-tight"><?= htmlspecialchars(t('contactos.subtitle')) ?></p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button x-show="permisos.priv_insert === 'Y'" @click="nuevoContacto()" class="px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white rounded-xl text-sm font-semibold flex items-center gap-2 transition-colors">
                    <i class="fas fa-plus text-xs"></i> <?= htmlspecialchars(t('contactos.new_contact')) ?>
                </button>
            </div>
        </div>
    </header>

    <main class="max-w-[1600px] mx-auto px-3 py-2">
        <div class="grid grid-cols-2 md:grid-cols-6 gap-2 mb-2">
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-2.5">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase"><?= htmlspecialchars(t('contactos.total')) ?></p>
                <p class="text-xl font-bold text-gray-900 dark:text-white mt-0.5" x-text="stats.total"></p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-2.5">
                <p class="text-xs font-medium text-green-600 dark:text-green-400 uppercase">Activos</p>
                <p class="text-xl font-bold text-green-600 dark:text-green-400 mt-0.5" x-text="stats.activos"></p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-2.5">
                <p class="text-xs font-medium text-red-600 dark:text-red-400 uppercase"><?= htmlspecialchars(t('contactos.receivable')) ?></p>
                <p class="text-lg font-bold text-red-600 dark:text-red-400 mt-0.5" x-text="'₲ ' + formatMoney(stats.a_cobrar)"></p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-2.5">
                <p class="text-xs font-medium text-amber-600 dark:text-amber-400 uppercase"><?= htmlspecialchars(t('contactos.payable')) ?></p>
                <p class="text-lg font-bold text-amber-600 dark:text-amber-400 mt-0.5" x-text="'₲ ' + formatMoney(stats.a_pagar)"></p>
            </div>
            <button @click="filtroRapido(3)" class="px-3 py-2 rounded-xl bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 font-semibold text-sm">
                <?= htmlspecialchars(t('contactos.clients')) ?> <span class="ml-1" x-text="stats.clientes"></span>
            </button>
            <button @click="filtroRapido(4)" class="px-3 py-2 rounded-xl bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 font-semibold text-sm">
                <?= htmlspecialchars(t('contactos.suppliers')) ?> <span class="ml-1" x-text="stats.proveedores"></span>
            </button>
            <button @click="filtroRapido(5)" class="px-3 py-2 rounded-xl bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 font-semibold text-sm">
                <?= htmlspecialchars(t('contactos.employees')) ?> <span class="ml-1" x-text="stats.empleados"></span>
            </button>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-2.5 mb-2">
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex-1 min-w-[260px]">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" :value="search" @input="handleSearchInput($event)"
                               placeholder="<?= htmlspecialchars(t('contactos.search_placeholder')) ?>"
                               class="w-full pl-10 pr-4 py-1.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                    </div>
                </div>
                <select x-model="filtroCuenta" @change="page=1; applyLocalFilters()" class="px-3 py-1.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                    <option value="all"><?= htmlspecialchars(t('contactos.all_types')) ?></option>
                    <option value="3"><?= htmlspecialchars(t('contactos.clients')) ?></option>
                    <option value="4"><?= htmlspecialchars(t('contactos.suppliers')) ?></option>
                    <option value="5"><?= htmlspecialchars(t('contactos.employees')) ?></option>
                </select>
                <select x-model="filtroEstado" @change="page=1; applyLocalFilters()" class="px-3 py-1.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                    <option value="1"><?= htmlspecialchars(t('common.active_plural')) ?></option>
                    <option value="-1"><?= htmlspecialchars(t('contactos.inactive_plural')) ?></option>
                    <option value="all"><?= htmlspecialchars(t('common.all')) ?></option>
                </select>
                <select x-model="perPage" @change="page=1; applyLocalFilters()" class="px-3 py-1.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                    <option value="10">10<?= htmlspecialchars(t('contactos.per_page_short')) ?></option>
                    <option value="25">25<?= htmlspecialchars(t('contactos.per_page_short')) ?></option>
                    <option value="50">50<?= htmlspecialchars(t('contactos.per_page_short')) ?></option>
                    <option value="100">100<?= htmlspecialchars(t('contactos.per_page_short')) ?></option>
                </select>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
            <div x-show="loading" class="flex items-center justify-center py-12">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-purple-600"></div>
            </div>

            <div x-show="!loading" class="p-2.5">
                <div x-ref="contactosGrid"
                     :class="isDarkGrid ? 'ag-theme-quartz-dark' : 'ag-theme-quartz'"
                     class="contactos-grid"></div>
            </div>

            <div x-show="!loading && contactos.length === 0" class="py-12 text-center text-gray-500 dark:text-gray-400">
                <i class="fas fa-users text-4xl mb-3"></i>
                <p><?= htmlspecialchars(t('contactos.none_found')) ?></p>
            </div>
        </div>

        <div x-show="totalPages > 1" class="flex items-center justify-between mt-4">
            <span class="text-sm text-gray-500 dark:text-gray-400" x-text="'Pág. ' + page + ' de ' + totalPages"></span>
            <div class="flex gap-2">
                <button @click="if(page>1){page--;applyLocalFilters();}" :disabled="page<=1" class="px-3 py-2 rounded-lg bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 text-sm disabled:opacity-40"><i class="fas fa-chevron-left"></i></button>
                <button @click="if(page<totalPages){page++;applyLocalFilters();}" :disabled="page>=totalPages" class="px-3 py-2 rounded-lg bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 text-sm disabled:opacity-40"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
    </main>

    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showModal=false">
        <div class="absolute inset-0 bg-black/50" @click="showModal=false"></div>
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto modal-enter">
            <div class="sticky top-0 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-6 py-4 flex items-center justify-between z-10">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white" x-text="form.id ? 'Editar Contacto' : 'Nuevo Contacto'"></h2>
                <button @click="showModal=false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-slate-700"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Tipo</label>
                    <select x-model="form.cuenta" class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                        <option value="3">Cliente</option>
                        <option value="4">Proveedor</option>
                        <option value="5">Empleado</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Documento</label>
                    <select x-model="form.documento" class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                        <option value="11">RUC</option>
                        <option value="12">CI</option>
                        <option value="13">Pasaporte</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">RUC / CI *</label>
                    <div class="flex gap-2">
                        <input type="text" x-model="form.numero" class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white font-mono">
                        <button type="button" @click="toggleSpeechInput('numero')" class="w-11 shrink-0 rounded-xl border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-gray-600 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700">
                            <i class="fas fa-microphone"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Nombre *</label>
                    <div class="flex gap-2">
                        <input type="text" x-model="form.nombre" class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                        <button type="button" @click="toggleSpeechInput('nombre')" class="w-11 shrink-0 rounded-xl border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-gray-600 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700">
                            <i class="fas fa-microphone"></i>
                        </button>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Dirección</label>
                    <div class="flex gap-2">
                        <input type="text" x-model="form.direccion" class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                        <button type="button" @click="captureGoogleMapsLocation()" class="w-11 shrink-0 rounded-xl border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-blue-600 dark:text-blue-300 hover:bg-gray-100 dark:hover:bg-slate-700" title="Capturar ubicación">
                            <i class="fas fa-location-crosshairs"></i>
                        </button>
                        <button type="button" @click="openDireccionInMaps()" class="w-11 shrink-0 rounded-xl border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-emerald-600 dark:text-emerald-300 hover:bg-gray-100 dark:hover:bg-slate-700" title="Abrir en Google Maps">
                            <i class="fab fa-google"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Ciudad</label>
                    <input type="text" x-model="form.ciudad" class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">País</label>
                    <input type="text" x-model="form.pais" class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                </div>
                <div>
                    <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Teléfono</label>
                    <div class="flex gap-2">
                        <select x-model="form.phone_country" class="w-[170px] px-2 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                            <template x-for="country in phoneCountries" :key="country.code">
                                <option :value="country.code" x-text="`${country.flag} ${country.code}`"></option>
                            </template>
                        </select>
                        <input type="text" x-model="form.phone_number" class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                        <button type="button" @click="toggleSpeechInput('telefono')" class="w-11 shrink-0 rounded-xl border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-gray-600 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700">
                            <i class="fas fa-microphone"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Email</label>
                    <div class="flex gap-2">
                        <input type="email" x-model="form.email" class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                        <button type="button" @click="toggleSpeechInput('email')" class="w-11 shrink-0 rounded-xl border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-gray-600 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-slate-700">
                            <i class="fas fa-microphone"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Línea de Crédito</label>
                    <input type="number" x-model.number="form.linea_credito" min="0" class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-right">
                </div>
                <div>
                    <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">Rating</label>
                    <select x-model="form.raiting" class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                        <option value="1">1 - Normal</option>
                        <option value="2">2 - Bueno</option>
                        <option value="3">3 - VIP</option>
                    </select>
                </div>
            </div>
            <div class="sticky bottom-0 bg-white dark:bg-slate-800 border-t border-gray-200 dark:border-slate-700 px-6 py-4 flex justify-end gap-3">
                <button @click="showModal=false" class="px-4 py-2.5 border border-gray-300 dark:border-slate-600 text-gray-700 dark:text-gray-300 rounded-xl">Cancelar</button>
                <button @click="guardar()" :disabled="saving" class="px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-xl disabled:opacity-50">
                    <span x-text="saving ? 'Guardando...' : (form.id ? 'Actualizar' : 'Crear')"></span>
                </button>
            </div>
        </div>
    </div>

    <div x-show="showKardexModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showKardexModal=false">
        <div class="absolute inset-0 bg-black/50" @click="showKardexModal=false"></div>
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-[90vw] max-w-[90vw] max-h-[90vh] overflow-hidden modal-enter">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white">Estado de cuenta del contacto</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400" x-text="kardexData?.nombre || ''"></p>
                </div>
                <button @click="showKardexModal=false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-slate-700"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-6 space-y-4 overflow-y-auto max-h-[calc(90vh-80px)]">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3" x-show="kardexData">
                    <div class="rounded-xl bg-gray-50 dark:bg-slate-900/50 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Documentos a Cobrar</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white" x-text="kardexData?.numero || '-'"></p>
                    </div>
                    <div class="rounded-xl bg-gray-50 dark:bg-slate-900/50 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Telefono</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white" x-text="kardexData?.telefono || '-'"></p>
                    </div>
                    <div class="rounded-xl bg-gray-50 dark:bg-slate-900/50 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Linea Credito</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white" x-text="'₲ ' + formatMoney(kardexData?.linea_credito || 0)"></p>
                    </div>
                    <div class="rounded-xl bg-gray-50 dark:bg-slate-900/50 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Saldo</p>
                        <p class="mt-1 text-sm font-semibold" :class="parseFloat(kardexData?.saldo_display?.guaranies || 0) > 0 ? 'text-red-600' : 'text-gray-900 dark:text-white'" x-text="'₲ ' + formatMoney(kardexData?.saldo_display?.guaranies || 0)"></p>
                    </div>
                </div>
                <div x-show="kardexLoading" class="py-10 text-center">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-sky-600 mx-auto"></div>
                </div>
                <div x-show="!kardexLoading" class="flex items-center gap-2 border-b border-gray-200 dark:border-slate-700 pb-3">
                    <button @click="setKardexTab('movimientos')"
                            class="px-3 py-2 rounded-xl text-sm font-semibold transition-colors"
                            :class="kardexTab === 'movimientos' ? 'bg-sky-600 text-white' : 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300'">
                        Movimientos
                    </button>
                    <button @click="setKardexTab('documentos')"
                            class="px-3 py-2 rounded-xl text-sm font-semibold transition-colors"
                            :class="kardexTab === 'documentos' ? 'bg-sky-600 text-white' : 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300'">
                        Documentos a Cobrar
                    </button>
                    <button @click="setKardexTab('documentos_pagar')"
                            class="px-3 py-2 rounded-xl text-sm font-semibold transition-colors"
                            :class="kardexTab === 'documentos_pagar' ? 'bg-sky-600 text-white' : 'bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-gray-300'">
                        Documentos a Pagar
                    </button>
                </div>
                <div x-show="!kardexLoading" class="space-y-3">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div class="flex flex-1 flex-col gap-3 xl:flex-row xl:items-center">
                            <div class="relative flex-1 max-w-xl">
                                <i class="fas fa-lightbulb absolute left-3 top-1/2 -translate-y-1/2 text-sky-500"></i>
                                <input type="text"
                                       x-model="kardexSearch"
                                       @input="applyKardexSearch()"
                                       placeholder="Buscar por pista: factura, cuota, concepto, fecha o referencia..."
                                       class="w-full pl-10 pr-4 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <select x-model="kardexEstadoFiltro"
                                        @change="applyKardexSearch()"
                                        class="px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white">
                                    <option value="0">Activo</option>
                                    <option value="1">Anulado</option>
                                    <option value="all">Todo</option>
                                </select>
                                <input type="date"
                                       x-model="kardexFechaDesde"
                                       @change="applyKardexSearch()"
                                       class="px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white"
                                       title="Fecha desde">
                                <input type="date"
                                       x-model="kardexFechaHasta"
                                       @change="applyKardexSearch()"
                                       class="px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white"
                                       title="Fecha hasta">
                                <button @click="limpiarFiltroFechaKardex()"
                                        class="px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-700 dark:text-gray-200 text-sm">
                                    Limpiar fechas
                                </button>
                            </div>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            Registros visibles: <span class="font-semibold" x-text="kardexGridVisibleRows"></span>
                        </div>
                    </div>
                    <div class="bg-gray-50 dark:bg-slate-900/50 rounded-2xl border border-gray-200 dark:border-slate-700 p-2.5">
                        <div x-ref="kardexGrid"
                             :class="isDarkGrid ? 'ag-theme-quartz-dark' : 'ag-theme-quartz'"
                             class="kardex-grid"></div>
                    </div>
                    <div x-show="!currentKardexRows().length" class="py-10 text-center text-gray-500 dark:text-gray-400">
                        <span x-text="kardexTab === 'movimientos' ? 'Sin movimientos en kardex' : (kardexTab === 'documentos' ? 'Sin documentos a cobrar' : 'Sin documentos a pagar')"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div x-show="showAuthorizationNotice" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4" @keydown.escape.window="cerrarAuthorizationNotice()">
        <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-sm" @click="cerrarAuthorizationNotice()"></div>
        <div class="relative w-full max-w-lg rounded-3xl border border-slate-200/80 bg-white/95 p-6 shadow-2xl dark:border-slate-700 dark:bg-slate-900/95 modal-enter">
            <div class="flex items-start gap-4">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300">
                    <i class="fas fa-shield-halved text-lg"></i>
                </div>
                <div class="space-y-2">
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-amber-600 dark:text-amber-300">Autorización crítica</p>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-50">Confirmación requerida</h3>
                    <p class="text-sm leading-6 text-slate-600 dark:text-slate-300">
                        Por ser una operacion Critica, se enviara una solicitud de autorizacion al centro de notificaciones para que un Administrador o Supervisor valide la accion antes de ejecutarla definitivamente.
                    </p>
                </div>
            </div>
            <div class="mt-6 flex items-center justify-end gap-3">
                <button @click="cerrarAuthorizationNotice()" class="rounded-2xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-100 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800">
                    Cancelar
                </button>
                <button @click="confirmarAuthorizationNotice()" class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">
                    Enviar solicitud
                </button>
            </div>
        </div>
    </div>

    <div x-show="toast.show" x-cloak class="fixed bottom-6 right-6 z-[999]">
        <div class="px-5 py-3 rounded-xl shadow-lg text-white text-sm font-medium" :class="toast.type === 'error' ? 'bg-red-600' : 'bg-green-600'" x-text="toast.msg"></div>
    </div>
</div>

<script>
function contactosApp() {
    return {
        API: './api',
        idEmpresa: <?= (int)$id_empresa ?>,
        permisos: window.__PERMISOS__ || {},
        isAdminSession: window.__SESSION_ADMIN__ === true || window.__SESSION_ADMIN__ === 'true',
        contactos: [],
        contactosBase: [],
        contactosGridApi: null,
        loading: true,
        search: '',
        filtroCuenta: 'all',
        filtroEstado: '1',
        page: 1,
        perPage: 10,
        totalPages: 1,
        searchDebounceTimer: null,
        stats: { total: 0, activos: 0, clientes: 0, proveedores: 0, empleados: 0, a_cobrar: 0, a_pagar: 0 },
        showModal: false,
        showKardexModal: false,
        kardexLoading: false,
        kardexData: null,
        kardexContactoId: 0,
        kardexRows: [],
        documentosRows: [],
        documentosPagarRows: [],
        kardexTab: 'movimientos',
        kardexSearch: '',
        kardexEstadoFiltro: '0',
        kardexFechaDesde: '',
        kardexFechaHasta: '',
        kardexGridApi: null,
        kardexGridVisibleRows: 0,
        showAuthorizationNotice: false,
        pendingKardexAction: null,
        saving: false,
        speechRecognition: null,
        speechTarget: '',
        phoneCountries: [
            { code: '+595', flag: '🇵🇾', name: 'Paraguay' },
            { code: '+54', flag: '🇦🇷', name: 'Argentina' },
            { code: '+55', flag: '🇧🇷', name: 'Brasil' },
            { code: '+598', flag: '🇺🇾', name: 'Uruguay' },
            { code: '+56', flag: '🇨🇱', name: 'Chile' },
            { code: '+34', flag: '🇪🇸', name: 'España' },
            { code: '+1', flag: '🇺🇸', name: 'USA/Canadá' },
        ],
        form: {},
        toast: { show: false, msg: '', type: 'success' },

        init() {
            this.resetForm();
            this.loadContactos();
        },

        resetForm() {
            this.form = {
                id: null, cuenta: 3, documento: '11', numero: '', nombre: '',
                direccion: '', ciudad: '', pais: 'Paraguay', telefono: '', phone_country: '+595', phone_number: '', email: '', obs: '', timbrado: '',
                fecha_nacimiento: null, linea_credito: 0, raiting: 1, sucursal: 1,
                complemento_direccion1: '', complemento_direccion2: '', numero_casa: '',
                salario: 0, comision: 0, dia_pago_salario: 5, estado: 1
            };
        },
        splitPhone(rawPhone) {
            const raw = String(rawPhone || '').trim();
            if (!raw) return { country: '+595', number: '' };
            for (const country of this.phoneCountries) {
                if (raw.startsWith(country.code)) {
                    return {
                        country: country.code,
                        number: raw.slice(country.code.length).replace(/[^\d]/g, '').trim()
                    };
                }
            }
            return { country: '+595', number: raw.replace(/[^\d]/g, '') };
        },
        buildPhone() {
            const country = String(this.form.phone_country || '+595').trim() || '+595';
            const number = String(this.form.phone_number || '').replace(/[^\d]/g, '');
            return number ? `${country} ${number}` : '';
        },
        toggleSpeechInput(target) {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SpeechRecognition) {
                this.showToast('El navegador no soporta dictado por voz', 'error');
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
                if (target === 'numero') {
                    this.form.numero = transcript.replace(/\s+/g, '');
                    return;
                }
                if (target === 'telefono') {
                    this.form.phone_number = transcript.replace(/[^\d]/g, '');
                    return;
                }
                if (target === 'email') {
                    this.form.email = transcript
                        .toLowerCase()
                        .replace(/\s+/g, '')
                        .replace(/arroba/g, '@')
                        .replace(/punto/g, '.');
                    return;
                }
                if (target === 'nombre') {
                    this.form.nombre = transcript;
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
        captureGoogleMapsLocation() {
            if (!navigator.geolocation) {
                this.showToast('El navegador no soporta geolocalización', 'error');
                return;
            }
            navigator.geolocation.getCurrentPosition(
                async (position) => {
                    const lat = Number(position.coords?.latitude || 0);
                    const lng = Number(position.coords?.longitude || 0);
                    if (!lat && !lng) {
                        this.showToast('No se pudo obtener la ubicación', 'error');
                        return;
                    }
                    const pickCity = (address) => address.city || address.town || address.village || address.municipality || address.county || address.state_district || address.state || '';
                    const buildAddress = (data) => {
                        const address = data.address || {};
                        const parts = [
                            address.road || address.pedestrian || address.cycleway || address.footway || '',
                            address.house_number || '',
                            address.suburb || address.neighbourhood || address.quarter || '',
                        ].filter(Boolean);
                        return parts.join(', ') || (data.display_name || '');
                    };
                    try {
                        const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&zoom=18&addressdetails=1`;
                        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                        const data = await res.json();
                        const address = data.address || {};
                        this.form.direccion = buildAddress(data) || `https://maps.google.com/?q=${lat},${lng}`;
                        this.form.ciudad = pickCity(address) || this.form.ciudad || '';
                        this.form.pais = address.country || this.form.pais || 'Paraguay';
                    } catch (e) {
                        this.form.direccion = `https://maps.google.com/?q=${lat},${lng}`;
                    }
                    this.showToast('Ubicación capturada');
                },
                () => {
                    this.showToast('No se pudo capturar la ubicación', 'error');
                },
                {
                    enableHighAccuracy: true,
                    timeout: 12000,
                    maximumAge: 0
                }
            );
        },
        openDireccionInMaps() {
            const value = String(this.form.direccion || '').trim();
            const url = value !== ''
                ? (/^https?:\/\//i.test(value)
                    ? value
                    : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(value)}`)
                : 'https://www.google.com/maps';
            window.open(url, '_blank');
        },

        async loadContactos() {
            this.loading = true;
            try {
                const params = new URLSearchParams({
                    id_empresa: this.idEmpresa,
                    page: 1,
                    per_page: this.perPage,
                    search: '',
                    cuenta: 'all',
                    estado: 'all',
                    all: 1,
                    sort_by: 'nombre',
                    sort_dir: 'ASC'
                });
                const res = await fetch(`${this.API}/list.php?${params}`, { cache: 'no-store' });
                const data = await res.json();
                if (data.success) {
                    this.contactosBase = Array.isArray(data.data) ? data.data : [];
                    this.stats = { ...this.stats, ...(data.stats || {}) };
                    this.recalculateHeaderBalances();
                    this.applyLocalFilters();
                    await this.$nextTick();
                    await this.ensureContactosGrid();
                    this.refreshContactosGrid();
                } else {
                    this.showToast(data.error || 'No se pudo cargar contactos', 'error');
                }
            } catch (e) {
                this.showToast('Error de conexión', 'error');
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
                this.applyLocalFilters();
            }, 250);
        },

        normalizeSearchText(value) {
            return String(value || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .trim();
        },

        recalculateHeaderBalances() {
            const rows = Array.isArray(this.contactosBase) ? this.contactosBase : [];
            let cobrar = 0;
            let pagar = 0;
            rows.forEach((item) => {
                const saldo = parseFloat(item?.saldo_guaranies ?? item?.saldo ?? 0) || 0;
                if (saldo > 0) {
                    cobrar += saldo;
                } else if (saldo < 0) {
                    pagar += Math.abs(saldo);
                }
            });
            this.stats.a_cobrar = cobrar;
            this.stats.a_pagar = pagar;
        },

        get isDarkGrid() {
            return document.documentElement.classList.contains('dark');
        },

        async ensureContactosGrid() {
            if (this.contactosGridApi || !this.$refs.contactosGrid) return;
            await window.__agGridReady;
            const gridOptions = {
                columnDefs: this.getContactosColumnDefs(),
                localeText: window.SmxAgGridLocale?.getLocaleText?.() || {},
                rowData: this.contactos,
                defaultColDef: {
                    sortable: true,
                    resizable: true,
                    filter: false,
                    enableRowGroup: true,
                    suppressHeaderMenuButton: false,
                    wrapHeaderText: true,
                    autoHeaderHeight: true,
                    cellStyle: { fontWeight: '400' },
                },
                animateRows: true,
                pagination: true,
                paginationPageSize: Math.max(1, parseInt(this.perPage, 10) || 25),
                preventDefaultOnContextMenu: true,
                allowContextMenuWithControlKey: true,
                sideBar: {
                    toolPanels: [
                        {
                            id: 'columns',
                            labelDefault: 'Columnas / Agrupación',
                            labelKey: 'columns',
                            iconKey: 'columns',
                            toolPanel: 'agColumnsToolPanel',
                            toolPanelParams: {
                                suppressRowGroups: false,
                                suppressValues: true,
                                suppressPivots: true,
                                suppressPivotMode: true,
                            },
                        },
                        {
                            id: 'filters',
                            labelDefault: 'Filters',
                            labelKey: 'filters',
                            iconKey: 'filter',
                            toolPanel: 'agFiltersToolPanel',
                        }
                    ],
                    defaultToolPanel: ''
                },
                getContextMenuItems: (params) => this.getContactosContextMenuItems(params),
                onGridReady: (params) => {
                    this.contactosGridApi = params.api;
                    params.api.sizeColumnsToFit();
                }
            };
            this.contactosGridApi = agGrid.createGrid(this.$refs.contactosGrid, gridOptions);
        },

        getContactosColumnDefs() {
            return [
                {
                    headerName: 'Tipo',
                    field: 'cuenta_tipo',
                    minWidth: 120,
                    flex: 0.8,
                    cellRenderer: (params) => {
                        const tipo = String(params.data?.cuenta_tipo || 'Otro');
                        const css = Number(params.data?.cuenta || 0) === 3 ? 'cliente' : (Number(params.data?.cuenta || 0) === 4 ? 'proveedor' : 'empleado');
                        return `<span class="contacto-badge ${css}">${this.escapeHtml(tipo)}</span>`;
                    }
                },
                { headerName: 'RUC/CI', field: 'numero', minWidth: 150, flex: 0.9 },
                {
                    headerName: 'Descripción',
                    field: 'nombre',
                    minWidth: 280,
                    flex: 1.8,
                    cellRenderer: (params) => {
                        const nombre = this.escapeHtml(params.data?.nombre || '-');
                        const email = this.escapeHtml(params.data?.email || '');
                        return `<div class="contacto-stack"><div class="main">${nombre}</div>${email ? `<div class="meta">${email}</div>` : ''}</div>`;
                    }
                },
                { headerName: 'Teléfono', field: 'telefono', minWidth: 150, flex: 0.9, valueFormatter: (p) => p.value || '-' },
                { headerName: 'Dirección', field: 'direccion', minWidth: 220, flex: 1.4, valueFormatter: (p) => p.value || '-' },
                {
                    headerName: 'Saldo',
                    field: 'saldo_guaranies',
                    minWidth: 150,
                    flex: 0.9,
                    type: 'rightAligned',
                    valueFormatter: (p) => '₲ ' + this.formatMoney(p.value || p.data?.saldo || 0)
                },
                { headerName: 'Estado', field: 'estado', hide: true, minWidth: 110 },
                { headerName: 'ID', field: 'id', hide: true, minWidth: 90 },
                { headerName: 'País', field: 'pais', hide: true, minWidth: 110 },
                { headerName: 'Ciudad', field: 'ciudad', hide: true, minWidth: 110 },
            ];
        },

        getContactosContextMenuItems(params) {
            const row = params?.node?.data || params?.value && params?.api?.getDisplayedRowAtIndex?.(params.rowIndex)?.data || null;
            const items = [];
            if (row && row.id) {
                items.push({
                    name: 'Estado de cuenta',
                    icon: '<i class="fas fa-book text-sky-500"></i>',
                    action: () => this.verKardex(row.id)
                });
                if (this.permisos?.priv_update === 'Y') {
                    items.push({
                        name: 'Editar',
                        icon: '<i class="fas fa-pen text-purple-500"></i>',
                        action: () => this.editarContacto(row.id)
                    });
                }
                if (this.permisos?.priv_delete === 'Y') {
                    items.push({
                        name: Number(row.estado || 0) === 1 ? 'Desactivar' : 'Activar',
                        icon: Number(row.estado || 0) === 1
                            ? '<i class="fas fa-ban text-red-500"></i>'
                            : '<i class="fas fa-check text-emerald-500"></i>',
                        action: () => this.toggleEstado(row)
                    });
                }
                items.push('separator');
            }
            items.push('copy', 'copyWithHeaders', 'separator', 'export');
            return items;
        },

        refreshContactosGrid() {
            if (!this.contactosGridApi) return;
            this.contactosGridApi.setGridOption('rowData', this.contactos);
            this.contactosGridApi.setGridOption('paginationPageSize', Math.max(1, parseInt(this.perPage, 10) || 25));
            this.contactosGridApi.sizeColumnsToFit();
        },

        escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        currentKardexRows() {
            if (this.kardexTab === 'documentos') return Array.isArray(this.documentosRows) ? this.documentosRows : [];
            if (this.kardexTab === 'documentos_pagar') return Array.isArray(this.documentosPagarRows) ? this.documentosPagarRows : [];
            return Array.isArray(this.kardexRows) ? this.kardexRows : [];
        },

        getKardexColumnDefs() {
            const moneyCell = (params) => {
                const value = parseFloat(params.value || 0);
                return value > 0 ? '₲ ' + this.formatMoney(value) : '-';
            };
            if (this.kardexTab === 'movimientos') {
                return [
                    { headerName: 'Fecha', field: 'fecha', minWidth: 150, flex: 1 },
                    { headerName: 'Concepto', field: 'concepto', minWidth: 260, flex: 2 },
                    { headerName: 'Debito', field: 'debito', minWidth: 130, flex: 1, type: 'rightAligned', valueFormatter: moneyCell },
                    { headerName: 'Credito', field: 'credito', minWidth: 130, flex: 1, type: 'rightAligned', valueFormatter: moneyCell },
                    { headerName: 'Saldo', field: 'saldo', minWidth: 140, flex: 1, type: 'rightAligned', valueFormatter: (p) => '₲ ' + this.formatMoney(p.value || 0) },
                    {
                        headerName: 'Solicitud',
                        field: 'request_status',
                        minWidth: 130,
                        flex: 0.9,
                        cellRenderer: (params) => {
                            const value = String(params.value || '').toLowerCase();
                            if (value === 'pending') {
                                return '<span class="kardex-request-status pending">Pendiente</span>';
                            }
                            if (value === 'authorized') {
                                return '<span class="kardex-request-status authorized">Autorizado</span>';
                            }
                            return '<span class="text-slate-400">-</span>';
                        }
                    },
                    { headerName: 'ID', field: 'id', hide: true, minWidth: 90 },
                    { headerName: 'Referencia', field: 'referencia', hide: true, minWidth: 160, valueFormatter: (p) => p.value || '-' },
                    { headerName: 'ID Login', field: 'id_login', hide: true, minWidth: 110, valueFormatter: (p) => p.value || '-' },
                    { headerName: 'Estado', field: 'estado', hide: true, minWidth: 100 }
                ];
            }
            return [
                { headerName: this.kardexTab === 'documentos' ? 'Nro' : 'Cuota', field: 'doc_display', minWidth: 130, flex: 1 },
                { headerName: 'Concepto', field: 'concepto_display', minWidth: 260, flex: 2 },
                { headerName: 'Vencimiento', field: 'fecha_vencimiento', minWidth: 150, flex: 1 },
                { headerName: 'Total', field: 'total', minWidth: 130, flex: 1, type: 'rightAligned', valueFormatter: (p) => '₲ ' + this.formatMoney(p.value || 0) },
                { headerName: 'Pendiente', field: 'pendiente', minWidth: 140, flex: 1, type: 'rightAligned', valueFormatter: (p) => '₲ ' + this.formatMoney(p.value || 0) }
            ];
        },

        mapKardexRowsForGrid() {
            const fromDate = this.parseFilterDate(this.kardexFechaDesde);
            const toDate = this.parseFilterDate(this.kardexFechaHasta, true);
            return this.currentKardexRows().filter((row) => {
                if (this.kardexEstadoFiltro !== 'all' && String(row?.estado ?? '0') !== String(this.kardexEstadoFiltro)) {
                    return false;
                }
                if (!fromDate && !toDate) return true;
                const rowDate = this.parseFilterDate(row?.fecha || row?.fecha_vencimiento || row?.fecha_creacion || '');
                if (!rowDate) return false;
                if (fromDate && rowDate < fromDate) return false;
                if (toDate && rowDate > toDate) return false;
                return true;
            }).map((row) => ({
                ...row,
                doc_display: row.cantidad_cuota || row.numero || row.cuota_numero || (row.id_factura ? ('Fact. #' + row.id_factura) : '-'),
                concepto_display: row.concepto || row.nro_factura || '-'
            }));
        },

        async ensureKardexGrid() {
            if (this.kardexGridApi || !this.$refs.kardexGrid) return;
            await window.__agGridReady;
            const gridOptions = {
                columnDefs: this.getKardexColumnDefs(),
                localeText: window.SmxAgGridLocale?.getLocaleText?.() || {},
                rowData: this.mapKardexRowsForGrid(),
                defaultColDef: {
                    sortable: true,
                    resizable: true,
                    filter: false,
                    enableRowGroup: true,
                    suppressHeaderMenuButton: false,
                    wrapHeaderText: true,
                    autoHeaderHeight: true,
                },
                animateRows: true,
                pagination: true,
                paginationPageSize: 15,
                getRowClass: (params) => Number(params?.data?.estado ?? 0) === 1 ? 'kardex-row-anulado' : '',
                preventDefaultOnContextMenu: true,
                allowContextMenuWithControlKey: true,
                sideBar: {
                    toolPanels: [
                        {
                            id: 'columns',
                            labelDefault: 'Columnas / Agrupación',
                            labelKey: 'columns',
                            iconKey: 'columns',
                            toolPanel: 'agColumnsToolPanel',
                            toolPanelParams: {
                                suppressRowGroups: false,
                                suppressValues: true,
                                suppressPivots: true,
                                suppressPivotMode: true,
                            },
                        },
                        {
                            id: 'filters',
                            labelDefault: 'Filters',
                            labelKey: 'filters',
                            iconKey: 'filter',
                            toolPanel: 'agFiltersToolPanel',
                        }
                    ],
                    defaultToolPanel: ''
                },
                getContextMenuItems: (params) => this.getKardexContextMenuItems(params),
                onGridReady: (params) => {
                    this.kardexGridApi = params.api;
                    this.kardexGridVisibleRows = params.api.getDisplayedRowCount();
                    this.applyKardexSearch();
                },
                onFilterChanged: (params) => {
                    this.kardexGridVisibleRows = params.api.getDisplayedRowCount();
                },
                onModelUpdated: (params) => {
                    this.kardexGridVisibleRows = params.api.getDisplayedRowCount();
                }
            };
            this.kardexGridApi = agGrid.createGrid(this.$refs.kardexGrid, gridOptions);
        },

        async refreshKardexGrid() {
            await this.$nextTick();
            await this.ensureKardexGrid();
            if (!this.kardexGridApi) return;
            this.kardexGridApi.setGridOption('columnDefs', this.getKardexColumnDefs());
            this.kardexGridApi.setGridOption('rowData', this.mapKardexRowsForGrid());
            this.applyKardexSearch();
            this.kardexGridApi.sizeColumnsToFit();
        },

        applyKardexSearch() {
            if (!this.kardexGridApi) return;
            this.kardexGridApi.setGridOption('rowData', this.mapKardexRowsForGrid());
            this.kardexGridApi.setGridOption('quickFilterText', String(this.kardexSearch || '').trim());
            this.kardexGridVisibleRows = this.kardexGridApi.getDisplayedRowCount();
        },

        setKardexTab(tab) {
            this.kardexTab = tab;
            this.refreshKardexGrid();
        },

        getKardexContextMenuItems(params) {
            const row = params?.node?.data || null;
            const items = [];
            if (row && this.kardexTab === 'movimientos') {
                items.push({
                    name: Number(row.estado ?? 0) === 1 ? 'Desanular registro' : 'Anular registro',
                    icon: Number(row.estado ?? 0) === 1
                        ? '<i class="fas fa-rotate-left text-emerald-500"></i>'
                        : '<i class="fas fa-ban text-red-500"></i>',
                    action: () => this.toggleKardexMovimiento(row)
                });
                items.push('separator');
            }
            items.push('copy', 'copyWithHeaders', 'separator', 'export');
            return items;
        },

        async toggleKardexMovimiento(row) {
            const id = Number(row?.id || 0);
            if (id <= 0) return;
            const accion = Number(row?.estado ?? 0) === 1 ? 'desanular' : 'anular';
            this.pendingKardexAction = { id, accion };
            this.showAuthorizationNotice = true;
        },

        cerrarAuthorizationNotice() {
            this.showAuthorizationNotice = false;
            this.pendingKardexAction = null;
        },

        async confirmarAuthorizationNotice() {
            const pending = this.pendingKardexAction;
            if (!pending || Number(pending.id || 0) <= 0) {
                this.cerrarAuthorizationNotice();
                return;
            }
            this.showAuthorizationNotice = false;
            try {
                const res = await fetch(`${this.API}/anular_kardex.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id_empresa: this.idEmpresa,
                        id: pending.id,
                        tipo: 'movimiento',
                        accion: pending.accion
                    })
                });
                const data = await res.json();
                if (!data.success) {
                    this.showToast(data.error || 'No se pudo actualizar el registro', 'error');
                    return;
                }
                this.showToast(data.message || 'Registro actualizado');
                if (this.kardexContactoId > 0) {
                    await this.verKardex(this.kardexContactoId);
                } else {
                    await this.loadContactos();
                }
            } catch (e) {
                this.showToast('Error al actualizar registro', 'error');
            } finally {
                this.pendingKardexAction = null;
            }
        },

        applyLocalFilters() {
            const terms = this.normalizeSearchText(this.search).split(/\s+/).filter(Boolean);
            let rows = Array.isArray(this.contactosBase) ? [...this.contactosBase] : [];
            const pageSize = Math.max(1, parseInt(this.perPage, 10) || 25);

            if (this.filtroCuenta !== 'all') {
                rows = rows.filter((item) => String(item?.cuenta ?? '') === String(this.filtroCuenta));
            }

            if (this.filtroEstado !== 'all') {
                const targetEstado = Number(this.filtroEstado || 0);
                rows = rows.filter((item) => {
                    const estado = Number(item?.estado ?? 1);
                    return estado === targetEstado;
                });
            }

            if (terms.length > 0) {
                rows = rows.filter((item) => {
                    const blob = this.normalizeSearchText([
                        item?.nombre,
                        item?.numero,
                        item?.direccion,
                        item?.telefono,
                        item?.email
                    ].filter(Boolean).join(' '));
                    return terms.every((term) => blob.includes(term));
                });
            }

            rows.sort((a, b) => this.normalizeSearchText(a?.nombre).localeCompare(this.normalizeSearchText(b?.nombre), 'es'));

            const total = rows.length;
            this.totalPages = Math.max(1, Math.ceil(total / pageSize));
            if (this.page > this.totalPages) {
                this.page = 1;
            }
            const start = (this.page - 1) * pageSize;
            const end = start + pageSize;
            this.contactos = rows.slice(start, end);
            this.refreshContactosGrid();
        },

        parseFilterDate(value, endOfDay = false) {
            const raw = String(value || '').trim();
            if (!raw) return null;
            const normalized = raw.includes(' ') ? raw.replace(' ', 'T') : raw;
            const date = new Date(normalized.length <= 10 ? `${normalized}T00:00:00` : normalized);
            if (Number.isNaN(date.getTime())) return null;
            if (endOfDay) {
                date.setHours(23, 59, 59, 999);
            } else {
                date.setHours(0, 0, 0, 0);
            }
            return date;
        },

        limpiarFiltroFechaKardex() {
            this.kardexFechaDesde = '';
            this.kardexFechaHasta = '';
            this.applyKardexSearch();
        },

        filtroRapido(cuenta) {
            this.filtroCuenta = this.filtroCuenta == cuenta ? 'all' : String(cuenta);
            this.page = 1;
            this.applyLocalFilters();
        },

        nuevoContacto() {
            this.resetForm();
            this.showModal = true;
        },

        async editarContacto(id) {
            this.resetForm();
            try {
                const res = await fetch(`${this.API}/detalle.php?id=${id}&id_empresa=${this.idEmpresa}`);
                const data = await res.json();
                if (data.success && data.data?.contacto) {
                    const c = data.data.contacto;
                    const phoneParts = this.splitPhone(c.telefono || '');
                    this.form = {
                        id: c.id,
                        cuenta: c.cuenta || 3,
                        documento: c.documento || '11',
                        numero: c.numero || '',
                        nombre: c.nombre || '',
                        direccion: c.direccion || '',
                        ciudad: c.ciudad || '',
                        pais: c.pais || 'Paraguay',
                        telefono: c.telefono || '',
                        phone_country: phoneParts.country,
                        phone_number: phoneParts.number,
                        email: c.email || '',
                        obs: c.obs || '',
                        timbrado: c.timbrado || '',
                        fecha_nacimiento: c.fecha_nacimiento || null,
                        linea_credito: parseFloat(c.linea_credito) || 0,
                        raiting: c.raiting || 1,
                        sucursal: c.sucursal || 1,
                        complemento_direccion1: c.complemento_direccion1 || '',
                        complemento_direccion2: c.complemento_direccion2 || '',
                        numero_casa: c.numero_casa || '',
                        salario: parseFloat(c.salario) || 0,
                        comision: parseFloat(c.comision) || 0,
                        dia_pago_salario: parseInt(c.dia_pago_salario) || 5,
                        estado: c.estado || 1
                    };
                }
            } catch (e) {
                this.showToast('Error al cargar contacto', 'error');
            }
            this.showModal = true;
        },

        async verKardex(id) {
            this.showKardexModal = true;
            this.kardexLoading = true;
            this.kardexData = null;
            this.kardexContactoId = Number(id || 0);
            this.kardexRows = [];
            this.documentosRows = [];
            this.documentosPagarRows = [];
            this.kardexSearch = '';
            this.kardexTab = 'movimientos';
            this.kardexEstadoFiltro = '0';
            this.kardexFechaDesde = '';
            this.kardexFechaHasta = '';
            try {
                const res = await fetch(`${this.API}/detalle.php?id=${id}&id_empresa=${this.idEmpresa}`);
                const data = await res.json();
                if (data.success && data.data?.contacto) {
                    this.kardexData = data.data.contacto;
                    this.kardexRows = Array.isArray(data.data.kardex) ? data.data.kardex : [];
                    this.documentosRows = Array.isArray(data.data.documentos_cobrar) ? data.data.documentos_cobrar : [];
                    this.documentosPagarRows = Array.isArray(data.data.documentos_pagar) ? data.data.documentos_pagar : [];
                    await this.refreshKardexGrid();
                } else {
                    this.showToast(data.error || 'No se pudo cargar kardex', 'error');
                }
            } catch (e) {
                this.showToast('Error al cargar kardex', 'error');
            }
            this.kardexLoading = false;
        },

        async guardar() {
            if (!this.form.nombre?.trim()) { this.showToast('Nombre obligatorio', 'error'); return; }
            if (!this.form.numero?.trim()) { this.showToast('RUC/CI obligatorio', 'error'); return; }
            this.saving = true;
            try {
                this.form.telefono = this.buildPhone();
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
                    this.showToast(data.message || 'Guardado');
                    this.showModal = false;
                    this.loadContactos();
                } else {
                    this.showToast(data.error || 'No se pudo guardar', 'error');
                }
            } catch (e) {
                this.showToast('Error de red', 'error');
            }
            this.saving = false;
        },

        async toggleEstado(c) {
            const action = c.estado == 1 ? 'deactivate' : 'activate';
            try {
                const res = await fetch(`${this.API}/eliminar.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: c.id, action, id_empresa: this.idEmpresa })
                });
                const data = await res.json();
                if (data.success) {
                    this.showToast(data.message || 'Actualizado');
                    this.loadContactos();
                } else {
                    this.showToast(data.error || 'No se pudo actualizar', 'error');
                }
            } catch (e) {
                this.showToast('Error de red', 'error');
            }
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
