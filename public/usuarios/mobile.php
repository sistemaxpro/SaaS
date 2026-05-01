<?php
/**
 * Módulo Gestión de Usuarios - Frontend Mobile
 * Gestión de usuarios y permisos de apps (vista mobile)
 */
require_once __DIR__ . '/../../config/bootstrap.php';
Session::start();
$id_empresa = $_SESSION['id_empresa'] ?? 169;

// Verificar acceso
Permission::requireAccess('usuarios');
$permisos = Permission::getAppPermissions('usuarios');
?>
<!DOCTYPE html>
<html lang="es" class="scroll-smooth" :class="isDark ? 'dark' : ''" x-data="{ isDark: localStorage.getItem('theme') === 'dark' }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1, user-scalable=no">
    <title>Usuarios - Mobile</title>
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
        body { -webkit-tap-highlight-color: transparent; overscroll-behavior: contain; }
        .toggle-switch { transition: background-color .2s; }
        .toggle-dot { transition: transform .2s; }
    </style>
</head>
<body class="bg-gray-100 dark:bg-slate-900 min-h-screen font-sans antialiased" autocomplete="off">
<script>window.__PERMISOS__ = <?= json_encode($permisos) ?>;</script>
<script>
window.__BUG_CTX__ = {
    id_empresa: <?= (int)$id_empresa ?>,
    empresa: <?= json_encode($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa ' . (int)$id_empresa), JSON_UNESCAPED_UNICODE) ?>
};
</script>

<div x-data="usuariosMobile()" x-init="init()" class="min-h-screen pb-24 max-w-lg mx-auto">

    <!-- Header -->
    <header class="bg-indigo-600 dark:bg-indigo-800 sticky top-0 z-30">
        <div class="px-4 py-4 flex items-center justify-between">
            <div>
                <h1 class="text-white font-bold text-lg leading-tight">Usuarios</h1>
                <p class="text-indigo-200 text-xs" x-text="stats.total + ' registros'"></p>
            </div>
            <div class="flex items-center gap-2"></div>
        </div>

        <!-- Search -->
        <div class="px-4 pb-3">
            <div class="relative">
                <input type="text" tabindex="-1" aria-hidden="true" autocomplete="username" class="absolute opacity-0 pointer-events-none -z-10 h-0 w-0">
                <input type="password" tabindex="-1" aria-hidden="true" autocomplete="current-password" class="absolute opacity-0 pointer-events-none -z-10 h-0 w-0">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-indigo-300"></i>
                <input x-ref="searchInput" x-init="$nextTick(() => { $el.value = '' })" type="text" x-model.debounce.400ms="search" @input="page = 1; loadUsuarios()"
                       @focus="userTouchedSearch = true"
                       placeholder="Buscar usuario..."
                       name="no_autofill_search_users_mobile"
                       autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false"
                       class="w-full pl-10 pr-4 py-2.5 rounded-xl text-sm border focus:outline-none focus:ring-2
                              bg-white text-gray-900 placeholder-gray-400 border-gray-300 focus:ring-indigo-300
                              dark:bg-white/20 dark:text-white dark:placeholder-indigo-200 dark:border-white/10 dark:focus:ring-white/30">
            </div>
        </div>

        <!-- Filter Chips -->
        <div class="px-4 pb-3 flex gap-2 overflow-x-auto scrollbar-hide">
            <button @click="filtroEstado='all'; page=1; loadUsuarios()"
                class="flex-shrink-0 px-4 py-2 rounded-full text-sm font-semibold shadow-sm transition-colors flex items-center gap-2"
                :class="filtroEstado === 'all' ? 'bg-indigo-100 text-indigo-700 ring-2 ring-indigo-300' : 'bg-white/15 text-white'">
                <i class="fas fa-users text-base"></i>
                <span>Todos</span>
                <span class="ml-1 text-xs font-bold" x-text="stats.total"></span>
            </button>
            <button @click="filtroEstado='Y'; page=1; loadUsuarios()"
                class="flex-shrink-0 px-4 py-2 rounded-full text-sm font-semibold shadow-sm transition-colors flex items-center gap-2"
                :class="filtroEstado === 'Y' ? 'bg-green-100 text-green-700 ring-2 ring-green-300' : 'bg-white/15 text-white'">
                <i class="fas fa-check-circle text-base"></i>
                <span>Activos</span>
                <span class="ml-1 text-xs font-bold" x-text="stats.activos"></span>
            </button>
            <button @click="filtroEstado='N'; page=1; loadUsuarios()"
                class="flex-shrink-0 px-4 py-2 rounded-full text-sm font-semibold shadow-sm transition-colors flex items-center gap-2"
                :class="filtroEstado === 'N' ? 'bg-red-100 text-red-700 ring-2 ring-red-300' : 'bg-white/15 text-white'">
                <i class="fas fa-ban text-base"></i>
                <span>Inactivos</span>
                <span class="ml-1 text-xs font-bold" x-text="stats.inactivos"></span>
            </button>
        </div>
    </header>

    <!-- Loading -->
    <div x-show="loading" class="flex items-center justify-center py-16">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
    </div>

    <!-- Card List -->
    <div x-show="!loading" class="px-4 pt-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <template x-for="u in usuarios" :key="u.id_login">
                <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 active:scale-[0.98] transition-transform">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-full flex items-center justify-center text-white flex-shrink-0"
                             :class="u.is_admin === 'Y' ? 'bg-indigo-600' : 'bg-gray-500'">
                            <i :class="u.genero == 2 ? 'fas fa-user-nurse' : 'fas fa-user'"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white truncate" x-text="u.name"></h3>
                                <span x-show="u.is_admin === 'Y'" class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300">ADM</span>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-mono" x-text="'@' + u.login"></p>
                        </div>
                        <span :class="u.active === 'Y' ? 'bg-green-500' : 'bg-red-500'" class="w-2.5 h-2.5 rounded-full flex-shrink-0"></span>
                    </div>
                    <div class="flex items-center gap-3 mt-2 text-xs text-gray-500 dark:text-gray-400">
                        <span x-show="u.email" class="truncate"><i class="fas fa-envelope mr-1 text-[10px]"></i><span x-text="u.email"></span></span>
                        <span x-show="u.phone"><i class="fas fa-phone mr-1 text-[10px]"></i><span x-text="u.phone"></span></span>
                    </div>
                    <div class="flex items-center gap-2 mt-2 text-[11px]">
                        <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200">
                            <i class="fas fa-store mr-1"></i>
                            Suc. <span x-text="u.id_sucursal || 0"></span>
                        </span>
                        <span class="px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300">
                            <i class="fas fa-cash-register mr-1"></i>
                            Caja #<span x-text="(u.id_caja && Number(u.id_caja) > 0) ? u.id_caja : (u.caja_def || 0)"></span>
                        </span>
                    </div>
                    <div class="flex items-center justify-end gap-2 mt-3 border-t border-gray-100 dark:border-slate-700 pt-3">
                        <button @click="verAccesos(u)" class="px-3 py-1.5 rounded-lg bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400 text-xs font-semibold flex items-center gap-1">
                            <i class="fas fa-key"></i> Accesos
                        </button>
                        <button x-show="permisos.priv_update === 'Y'" @click="editarUsuario(u)" class="w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                            <i class="fas fa-edit text-sm"></i>
                        </button>
                        <button x-show="permisos.priv_delete === 'Y'" @click="toggleEstado(u)" class="w-8 h-8 rounded-lg flex items-center justify-center"
                                :class="u.active === 'Y' ? 'bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400' : 'bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400'">
                            <i class="fas text-sm" :class="u.active === 'Y' ? 'fa-ban' : 'fa-check'"></i>
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <!-- Load more -->
        <div x-show="page < totalPages" class="mt-4 text-center">
            <button @click="page++; loadUsuarios(true)" class="px-6 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-semibold">
                Cargar más
            </button>
        </div>

        <!-- Empty -->
        <div x-show="!loading && usuarios.length === 0" class="py-16 text-center">
            <i class="fas fa-users text-5xl text-gray-300 dark:text-slate-600 mb-3"></i>
            <p class="text-gray-500 dark:text-gray-400">No se encontraron usuarios</p>
        </div>
    </div>

    <!-- FAB Nuevo -->
    <button x-show="permisos.priv_insert === 'Y'" @click="nuevoUsuario()" class="fixed bottom-6 right-6 w-14 h-14 bg-indigo-600 text-white rounded-full shadow-lg flex items-center justify-center text-xl z-20 active:scale-95">
        <i class="fas fa-user-plus"></i>
    </button>

    <!-- Bottom Sheet: Editar -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="showModal = false"></div>
        <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-slate-800 rounded-t-2xl max-h-[90vh] overflow-y-auto slide-up max-w-lg mx-auto">
            <div class="sticky top-0 bg-white dark:bg-slate-800 px-4 py-3 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between rounded-t-2xl z-10">
                <h2 class="text-base font-bold text-gray-900 dark:text-white" x-text="form.id_login ? 'Editar Usuario' : 'Nuevo Usuario'"></h2>
                <button @click="showModal = false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="px-4 py-4 space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Login *</label>
                    <input type="text" x-model="form.login" :disabled="!!form.id_login"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm disabled:opacity-50">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Contraseña</label>
                    <input type="password" x-model="form.pswd" :placeholder="form.id_login ? 'Dejar vacío para no cambiar' : ''"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Nombre *</label>
                    <input type="text" x-model="form.name"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Email</label>
                    <input type="email" x-model="form.email"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Teléfono</label>
                        <input type="text" x-model="form.phone"
                               class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Documento</label>
                        <input type="text" x-model="form.documento"
                               class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Grupo</label>
                        <select x-model="form.group_id"
                                class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                            <template x-for="g in grupos" :key="g.group_id">
                                <option :value="g.group_id" x-text="g.description"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Género</label>
                        <select x-model="form.genero"
                                class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                            <option value="1">Masculino</option>
                            <option value="2">Femenino</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Sucursal asignada</label>
                        <select x-model="form.id_sucursal"
                                class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                            <option value="0">Sin asignar</option>
                            <template x-for="s in sucursales" :key="'suc-' + s.id_sucursal">
                                <option :value="String(s.id_sucursal)" x-text="s.id_sucursal + ' - ' + s.nombre"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Caja asignada</label>
                        <select x-model="form.id_caja"
                                class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                            <option value="0">Sin asignar</option>
                            <template x-for="c in cajasFiltradas(form.id_sucursal)" :key="'caja-' + c.id_caja">
                                <option :value="String(c.id_caja)" x-text="'#' + c.id_caja + ' - ' + c.nombre"></option>
                            </template>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-2">Precios asignados</label>
                    <div class="grid grid-cols-2 gap-2 max-h-32 overflow-y-auto p-2 border border-gray-200 dark:border-slate-700 rounded-xl">
                        <template x-for="tp in tiposPrecio" :key="'tp-m-' + tp.id">
                            <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300 cursor-pointer">
                                <input type="checkbox"
                                       :checked="tipoPrecioMarcado(tp.id)"
                                       @change="toggleTipoPrecio(tp.id)"
                                       class="w-4 h-4 rounded border-gray-300 text-indigo-600">
                                <span x-text="tp.tipo"></span>
                            </label>
                        </template>
                        <div x-show="tiposPrecio.length===0" class="col-span-full text-xs text-gray-500">Sin tipos de precio</div>
                    </div>
                </div>
                <div class="flex items-center gap-6">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="form.active" true-value="Y" false-value="N" class="w-4 h-4 rounded border-gray-300 text-indigo-600">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Activo</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="form.priv_admin" true-value="Y" false-value="N" class="w-4 h-4 rounded border-gray-300 text-indigo-600">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Admin</span>
                    </label>
                </div>
            </div>
            <div class="sticky bottom-0 bg-gray-50 dark:bg-slate-900 border-t border-gray-200 dark:border-slate-700 px-4 py-3 flex gap-3">
                <button @click="showModal = false" class="flex-1 px-4 py-2.5 bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium">Cancelar</button>
                <button @click="guardarUsuario()" :disabled="saving" class="flex-1 px-4 py-2.5 bg-indigo-600 text-white rounded-xl text-sm font-semibold disabled:opacity-50 flex items-center justify-center gap-2">
                    <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                    <span x-text="saving ? 'Guardando...' : 'Guardar'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Bottom Sheet: Accesos -->
    <div x-show="showAccesos" x-cloak class="fixed inset-0 z-50">
        <div class="absolute inset-0 bg-black/50" @click="showAccesos = false"></div>
        <div class="absolute bottom-0 left-0 right-0 bg-white dark:bg-slate-800 rounded-t-2xl max-h-[85vh] overflow-y-auto slide-up max-w-lg mx-auto">
            <div class="sticky top-0 bg-white dark:bg-slate-800 px-4 py-3 border-b border-gray-200 dark:border-slate-700 rounded-t-2xl z-10">
                <div class="w-10 h-1 bg-gray-300 dark:bg-slate-600 rounded-full mx-auto mb-2"></div>
                <h2 class="text-base font-bold text-gray-900 dark:text-white">Accesos de Apps</h2>
                <p class="text-xs text-gray-500" x-text="accesosUsuario.name"></p>
            </div>

            <div x-show="loadingAccesos" class="flex items-center justify-center py-8">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
            </div>

            <div x-show="!loadingAccesos" class="px-4 py-4 space-y-3">
                <template x-for="app in appsEmpresa" :key="app.permiso_base || app.codigo_app">
                    <div class="p-3 rounded-xl border border-gray-200 dark:border-slate-700">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg flex items-center justify-center text-white text-sm shrink-0"
                                     :style="'background-color:' + getColor(app.color)">
                                    <template x-if="app.icono_svg_resuelto">
                                        <img :src="app.icono_svg_resuelto" alt="" class="w-5 h-5 object-contain">
                                    </template>
                                    <template x-if="!app.icono_svg_resuelto">
                                        <i :class="app.icono || 'fas fa-cube'"></i>
                                    </template>
                                </div>
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white" x-text="app.nombre_app"></h4>
                                    <p class="text-[10px] text-gray-400" x-text="app.modulo"></p>
                                </div>
                            </div>
                            <button @click="toggleAccesoApp(app.permiso_base || app.codigo_app)"
                                    class="relative w-12 h-6 rounded-full transition-colors toggle-switch shrink-0"
                                    :class="getPermiso(app.permiso_base || app.codigo_app, 'priv_access') === 'Y' ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-slate-600'">
                                <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow toggle-dot"
                                      :style="getPermiso(app.permiso_base || app.codigo_app, 'priv_access') === 'Y' ? 'transform:translateX(24px)' : ''"></span>
                            </button>
                        </div>
                        <!-- Permisos detallados -->
                        <div class="flex flex-wrap items-center gap-1.5 mt-2.5 pt-2.5 border-t border-gray-100 dark:border-slate-700/50">
                            <template x-for="perm in [
                                {key:'insert', label:'Insertar', icon:'fa-plus'},
                                {key:'update', label:'Editar', icon:'fa-pen'},
                                {key:'delete', label:'Eliminar', icon:'fa-trash'},
                                {key:'export', label:'Exportar', icon:'fa-file-export'},
                                {key:'print', label:'Imprimir', icon:'fa-print'}
                            ]" :key="perm.key">
                                <button @click="togglePermiso(app.permiso_base || app.codigo_app, 'priv_' + perm.key)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-semibold transition-all"
                                        :class="getPermiso(app.permiso_base || app.codigo_app, 'priv_' + perm.key) === 'Y' 
                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 ring-1 ring-green-200 dark:ring-green-800' 
                                            : 'bg-gray-100 text-gray-400 dark:bg-slate-700 dark:text-slate-500'">
                                    <i class="fas text-[9px]" :class="perm.icon"></i>
                                    <span x-text="perm.label"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>

                <div x-show="appsEmpresa.length === 0" class="py-8 text-center">
                    <i class="fas fa-box-open text-3xl text-gray-300 dark:text-slate-600 mb-2"></i>
                    <p class="text-sm text-gray-500">No hay apps habilitadas</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div x-show="toast.show" x-cloak
         x-transition class="fixed bottom-20 left-4 right-4 z-[100] mx-auto max-w-sm px-4 py-3 rounded-xl shadow-lg text-white text-sm font-medium flex items-center gap-2"
         :class="toast.type === 'error' ? 'bg-red-600' : 'bg-emerald-600'">
        <i class="fas self-start mt-0.5" :class="toast.type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'"></i>
        <div class="leading-tight">
            <div x-text="toast.msg"></div>
            <div x-show="toast.type === 'error'" class="text-[11px] text-red-100/95 mt-0.5">Enviar el error al Departamento de Desarrollo</div>
        </div>
    </div>

    <div x-show="bugReport.show" x-cloak class="fixed bottom-4 left-3 right-3 z-[110] rounded-2xl border border-red-300/60 dark:border-red-800 bg-white/95 dark:bg-slate-900/95 backdrop-blur shadow-2xl">
        <div class="px-3 py-2 border-b border-red-200/70 dark:border-red-900/50 flex items-center justify-between">
            <h3 class="text-xs font-bold text-red-700 dark:text-red-300 flex items-center gap-1.5"><i class="fas fa-bug"></i> Bug detectado</h3>
            <button @click="bugReport.show=false" class="w-6 h-6 rounded-lg bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300"><i class="fas fa-times text-[10px]"></i></button>
        </div>
        <div class="px-3 py-2 text-[11px] text-gray-800 dark:text-gray-200 space-y-1">
            <p><b>Empresa:</b> <span x-text="bugReport.empresa"></span> (<span x-text="bugReport.id_empresa"></span>)</p>
            <p><b>App:</b> <span x-text="bugReport.app"></span></p>
            <p><b>Error:</b> <span x-text="bugReport.error"></span></p>
        </div>
        <div class="px-3 pb-3 flex items-center gap-2">
            <button @click="copyBug()" class="px-3 py-1.5 rounded-lg bg-slate-700 text-white text-[11px] font-semibold">Copiar</button>
            <button @click="sendBugToDev()" class="px-3 py-1.5 rounded-lg bg-green-600 text-white text-[11px] font-semibold">Enviar a Desarrollo</button>
        </div>
    </div>
</div>

<script>
function usuariosMobile() {
    return {
        usuarios: [],
        permisos: window.__PERMISOS__ || {},
        isDark: localStorage.getItem('theme') === 'dark',
        loading: true,
        search: '',
        filtroEstado: 'Y',
        page: 1,
        totalPages: 1,
        stats: { total: 0, activos: 0, inactivos: 0, admins: 0 },
        grupos: [],
        sucursales: [],
        cajas: [],
        tiposPrecio: [],

        showModal: false,
        saving: false,
        form: {},

        showAccesos: false,
        loadingAccesos: false,
        accesosUsuario: {},
        appsEmpresa: [],
        permisosMap: {},
        accesosGroupId: 0,

        toast: { show: false, msg: '', type: 'ok' },
        bugAppContext: 'usuarios/accesos',
        bugReport: { show: false, empresa: '', id_empresa: 0, app: '', error: '', text: '' },
        hasInitialized: false,
        userTouchedSearch: false,
        antiAutofillTimer: null,

        init() {
            this.applyTheme();
            this.hardResetSearch();
            this.filtroEstado = 'Y';
            this.page = 1;
            this.startAntiAutofillSweep();
            window.addEventListener('pageshow', () => {
                this.hardResetSearch();
                this.startAntiAutofillSweep();
            });
            this.loadUsuarios();
            this.loadGrupos();
            this.loadCatalogos();
        },

        hardResetSearch() {
            this.search = '';
            this.$nextTick(() => {
                if (this.$refs.searchInput) {
                    this.$refs.searchInput.value = '';
                    this.$refs.searchInput.setAttribute('value', '');
                }
            });
        },

        startAntiAutofillSweep() {
            this.userTouchedSearch = false;
            if (this.antiAutofillTimer) {
                clearInterval(this.antiAutofillTimer);
                this.antiAutofillTimer = null;
            }
            const until = Date.now() + 2500;
            this.antiAutofillTimer = setInterval(() => {
                if (this.userTouchedSearch || Date.now() > until) {
                    clearInterval(this.antiAutofillTimer);
                    this.antiAutofillTimer = null;
                    return;
                }
                if (this.$refs.searchInput && this.$refs.searchInput.value) {
                    this.$refs.searchInput.value = '';
                    this.$refs.searchInput.setAttribute('value', '');
                }
                if (this.search) this.search = '';
            }, 120);
        },

        applyTheme() {
            document.documentElement.classList.toggle('dark', !!this.isDark);
        },

        toggleTheme() {
            this.isDark = true;
            localStorage.setItem('theme', 'dark');
            this.applyTheme();
        },

        async loadUsuarios(append = false) {
            if (!append) this.loading = true;
            try {
                if (!this.hasInitialized) this.hardResetSearch();
                const params = new URLSearchParams({
                    search: this.search, estado: this.filtroEstado,
                    page: this.page, limit: 25
                });
                const res = await fetch('api/list.php?' + params);
                const data = await res.json();
                if (data.ok) {
                    if (append) {
                        this.usuarios = [...this.usuarios, ...data.data];
                    } else {
                        this.usuarios = data.data;
                    }
                    this.totalPages = data.pages;
                    if (data.stats) this.stats = data.stats;
                } else {
                    if (!append) this.usuarios = [];
                    this.totalPages = 1;
                    this.showToast(data.error || 'No se pudo cargar la lista de usuarios', 'error');
                }
            } catch (e) {
                console.error(e);
                if (!append) this.usuarios = [];
                this.totalPages = 1;
                this.showToast('Error de conexión al cargar usuarios', 'error');
            }
            this.hasInitialized = true;
            this.loading = false;
        },

        async loadGrupos() {
            try {
                const res = await fetch('api/apps_usuario.php?action=grupos');
                const data = await res.json();
                if (data.ok) this.grupos = data.grupos;
            } catch (e) {}
        },

        async loadCatalogos() {
            try {
                const res = await fetch('api/catalogos.php');
                const data = await res.json();
                if (data.ok) {
                    this.sucursales = data.sucursales || [];
                    this.cajas = data.cajas || [];
                    this.tiposPrecio = data.tipos_precio || [];
                }
            } catch (e) {}
        },

        cajasFiltradas(idSucursal) {
            const sid = Number(idSucursal || 0);
            if (!sid) return this.cajas || [];
            return (this.cajas || []).filter(c => Number(c.id_sucursal || 0) === sid);
        },

        nuevoUsuario() {
            this.form = {
                id_login: null, login: '', name: '', email: '', phone: '',
                documento: '', active: 'Y', priv_admin: 'N', pswd: '',
                group_id: this.grupos.length ? this.grupos[0].group_id : 1,
                genero: 1, role: '',
                id_sucursal: this.sucursales.length ? String(this.sucursales[0].id_sucursal) : '0',
                id_caja: '0',
                tipos_precio_asignados: []
            };
            this.showModal = true;
        },

        editarUsuario(u) {
            this.form = {
                id_login: u.id_login, login: u.login, name: u.name,
                email: u.email || '', phone: u.phone || '',
                documento: u.documento || '', active: u.active,
                priv_admin: u.priv_admin, pswd: '',
                group_id: (u.group_id ? Number(u.group_id) : 1), genero: u.genero || 1, role: u.role || '',
                id_sucursal: String(u.id_sucursal || 0),
                id_caja: String((u.id_caja && Number(u.id_caja) > 0) ? u.id_caja : (u.caja_def || 0)),
                tipos_precio_asignados: this.normalizeTiposInput(u.tipos_precio_asignados || '')
            };
            if (!u.group_id) this.loadGrupoUsuario(u.login);
            this.showModal = true;
        },
        normalizeTiposInput(v) {
            if (Array.isArray(v)) return v.map(x => Number(x)).filter(x => x > 0);
            return String(v || '')
                .split(',')
                .map(x => Number(String(x).trim()))
                .filter(x => x > 0);
        },
        tipoPrecioMarcado(id) {
            const arr = this.normalizeTiposInput(this.form.tipos_precio_asignados || []);
            return arr.includes(Number(id));
        },
        toggleTipoPrecio(id) {
            const val = Number(id);
            if (val <= 0) return;
            const arr = this.normalizeTiposInput(this.form.tipos_precio_asignados || []);
            const idx = arr.indexOf(val);
            if (idx >= 0) arr.splice(idx, 1);
            else arr.push(val);
            this.form.tipos_precio_asignados = arr;
        },

        async loadGrupoUsuario(login) {
            try {
                const res = await fetch('api/apps_usuario.php?action=permisos_usuario&login=' + encodeURIComponent(login));
                const data = await res.json();
                if (data.ok && data.group_id) this.form.group_id = data.group_id;
            } catch (e) {}
        },

        async guardarUsuario() {
            if (!this.form.login.trim() || !this.form.name.trim()) {
                this.showToast('Login y nombre son obligatorios', 'error');
                return;
            }
            this.saving = true;
            try {
                const payload = {
                    ...this.form,
                    tipos_precio_asignados: this.normalizeTiposInput(this.form.tipos_precio_asignados || [])
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
                    this.page = 1;
                    this.loadUsuarios();
                } else {
                    this.showToast(data.error, 'error');
                }
            } catch (e) {
                this.showToast('Error de conexión', 'error');
            }
            this.saving = false;
        },

        async toggleEstado(u) {
            if (!confirm(`¿${u.active === 'Y' ? 'Desactivar' : 'Activar'} a ${u.name}?`)) return;
            try {
                const res = await fetch('api/eliminar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id_login: u.id_login, accion: 'toggle' })
                });
                const data = await res.json();
                if (data.ok) {
                    this.showToast(data.msg);
                    this.page = 1;
                    this.loadUsuarios();
                } else {
                    this.showToast(data.error, 'error');
                }
            } catch (e) {
                this.showToast('Error de conexión', 'error');
            }
        },

        async verAccesos(u) {
            this.bugAppContext = 'usuarios/accesos';
            this.accesosUsuario = u;
            this.showAccesos = true;
            this.loadingAccesos = true;
            this.appsEmpresa = [];
            this.permisosMap = {};
            this.accesosGroupId = 0;
            try {
                const res = await fetch('api/apps_usuario.php?action=permisos_usuario&login=' + encodeURIComponent(u.login));
                const data = await res.json();
                if (data.ok) {
                    this.appsEmpresa = data.apps;
                    this.permisosMap = data.permisos;
                    this.accesosGroupId = data.group_id;
                } else {
                    this.showToast(data.error || 'No se pudo cargar accesos', 'error');
                }
            } catch (e) {
                console.error(e);
                this.showToast('Error cargando accesos de apps', 'error');
            }
            this.loadingAccesos = false;
        },

        getPermiso(appCode, permiso) {
            return this.permisosMap[appCode] ? (this.permisosMap[appCode][permiso] || 'N') : 'N';
        },

        async toggleAccesoApp(appCode) {
            this.bugAppContext = appCode || 'usuarios/accesos';
            if (!this.accesosGroupId) {
                this.showToast('El usuario no tiene grupo asignado', 'error');
                return;
            }
            try {
                const res = await fetch('api/apps_usuario.php?action=toggle_app_acceso', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ group_id: this.accesosGroupId, app_name: appCode })
                });
                const data = await res.json();
                if (data.ok) {
                    if (!this.permisosMap[appCode]) this.permisosMap[appCode] = {};
                    this.permisosMap[appCode].priv_access = data.acceso;
                    this.showToast(data.msg);
                } else {
                    this.showToast(data.error, 'error');
                }
            } catch (e) {
                this.showToast('Error de conexión', 'error');
            }
        },

        async togglePermiso(appCode, permiso) {
            this.bugAppContext = appCode || 'usuarios/accesos';
            if (!this.accesosGroupId) {
                this.showToast('El usuario no tiene grupo asignado', 'error');
                return;
            }
            const current = this.getPermiso(appCode, permiso);
            const newVal = current === 'Y' ? 'N' : 'Y';
            try {
                const res = await fetch('api/apps_usuario.php?action=guardar_permiso', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ group_id: this.accesosGroupId, app_name: appCode, permiso, valor: newVal })
                });
                const data = await res.json();
                if (data.ok) {
                    if (!this.permisosMap[appCode]) this.permisosMap[appCode] = {};
                    this.permisosMap[appCode][permiso] = newVal;
                    this.showToast(data.msg);
                } else {
                    this.showToast(data.error, 'error');
                }
            } catch (e) {
                this.showToast('Error de conexión', 'error');
            }
        },

        getColor(color) {
            const map = { blue:'#3b82f6', red:'#ef4444', green:'#22c55e', amber:'#f59e0b', purple:'#8b5cf6', indigo:'#6366f1', emerald:'#10b981', pink:'#ec4899', cyan:'#06b6d4', teal:'#14b8a6', orange:'#f97316' };
            return map[color] || color || '#6366f1';
        },

        buildBugText(errMsg) {
            const ctx = window.__BUG_CTX__ || {};
            const empresa = ctx.empresa || ('Empresa ' + (ctx.id_empresa || 'N/D'));
            const app = this.bugAppContext || 'usuarios/accesos';
            const user = (this.accesosUsuario && this.accesosUsuario.login) ? this.accesosUsuario.login : 'N/D';
            return [
                'BUG SistemaX',
                'Empresa: ' + empresa + ' (' + (ctx.id_empresa || 'N/D') + ')',
                'App: ' + app,
                'Usuario: ' + user,
                'Error: ' + errMsg,
                'URL: ' + window.location.href,
                'Fecha: ' + new Date().toISOString()
            ].join('\n');
        },

        async copyBug() {
            if (!this.bugReport.text) return;
            try {
                await navigator.clipboard.writeText(this.bugReport.text);
                this.showToast('Bug copiado al portapapeles');
            } catch (e) {
                this.showToast('No se pudo copiar', 'error');
            }
        },

        sendBugToDev() {
            if (!this.bugReport.text) return;
            fetch('/public/devbugs/api/report.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    incident: (this.bugReport.text.match(/Incidencia:\s*([^\n]+)/i) || [,''])[1] || ('INC-' + Date.now()),
                    app: this.bugReport.app || 'usuarios/accesos',
                    error: this.bugReport.error || 'Error no especificado',
                    detail: this.bugReport.text,
                    url: window.location.href
                })
            }).then(() => {
                this.showToast('Reporte enviado al central de bugs');
                this.bugReport.show = false;
            }).catch(() => {
                this.showToast('No se pudo enviar el reporte', 'error');
            });
        },

        showToast(msg, type = 'ok') {
            this.toast = { show: true, msg, type };
            setTimeout(() => this.toast.show = false, type === 'error' ? 9000 : 3000);
            if (type === 'error') {
                const ctx = window.__BUG_CTX__ || {};
                const errorText = String(msg || 'Error no especificado');
                this.bugReport = {
                    show: true,
                    empresa: ctx.empresa || ('Empresa ' + (ctx.id_empresa || 'N/D')),
                    id_empresa: ctx.id_empresa || 0,
                    app: this.bugAppContext || 'usuarios/accesos',
                    error: errorText,
                    text: this.buildBugText(errorText)
                };
            }
        }
    };
}
</script>
</body>
</html>
