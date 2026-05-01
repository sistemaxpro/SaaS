<?php
/**
 * Módulo Gestión de Usuarios - Frontend Desktop
 * Gestión de usuarios y permisos de apps
 */
require_once __DIR__ . '/../../config/bootstrap.php';
Session::start();
$id_empresa = $_SESSION['id_empresa'] ?? 169;

// Verificar acceso
Permission::requireAccess('usuarios');
$permisos = Permission::getAppPermissions('usuarios');

// Detectar móvil y redirigir
$userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$isMobile = preg_match('/(android|webos|iphone|ipad|ipod|blackberry|windows phone|opera mini|mobile)/i', $userAgent);
if ($isMobile) {
    header('Location: /public/usuarios/mobile.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es" class="scroll-smooth" :class="isDark ? 'dark' : ''" x-data="{ isDark: localStorage.getItem('theme') === 'dark' }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios - SistemaX PRO</title>
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
        .modal-enter { animation: modalIn .25s ease-out; }
        @keyframes modalIn { from { opacity:0; transform:scale(.95) translateY(10px); } to { opacity:1; transform:scale(1) translateY(0); } }
        .toggle-switch { transition: background-color .2s; }
        .toggle-dot { transition: transform .2s; }
        .avatar-hold-ring { animation: avatarHoldPulse .8s ease-in-out infinite; }
        @keyframes avatarHoldPulse {
            0%,100% { box-shadow: 0 0 0 0 rgba(99,102,241,.25); }
            50% { box-shadow: 0 0 0 10px rgba(99,102,241,0); }
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-slate-900 min-h-screen font-sans antialiased" autocomplete="off">
<script>window.__PERMISOS__ = <?= json_encode($permisos) ?>;</script>
<script>
window.__BUG_CTX__ = {
    id_empresa: <?= (int)$id_empresa ?>,
    empresa: <?= json_encode($_SESSION['empresa'] ?? $_SESSION['empresa_nombre'] ?? ('Empresa ' . (int)$id_empresa), JSON_UNESCAPED_UNICODE) ?>
};
</script>

<div x-data="usuariosApp()" x-init="init()" class="min-h-screen">
    <input x-ref="avatarFileInput" type="file" accept="image/*" class="hidden" @change="handleAvatarFileSelected($event)">
    <input x-ref="avatarCameraInput" type="file" accept="image/*" capture="user" class="hidden" @change="handleAvatarFileSelected($event)">

    <!-- Header -->
    <header class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 sticky top-0 z-30">
        <div class="max-w-[1600px] mx-auto px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                        class="w-10 h-10 rounded-lg bg-red-600/10 border border-red-500/40 flex items-center justify-center text-red-600 dark:text-red-400 hover:bg-red-600/20 active:scale-95 transition-all cursor-pointer"
                        title="Salir">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15m-3 0-3-3m0 0 3-3m-3 3H15" />
                    </svg>
                </button>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-white">Gestión de Usuarios</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Usuarios, Roles y Permisos de Apps</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button x-show="permisos.priv_insert === 'Y'" @click="nuevoUsuario()" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-semibold flex items-center gap-2 transition-colors shadow-sm">
                    <i class="fas fa-user-plus text-xs"></i> Nuevo Usuario
                </button>
            </div>
        </div>
    </header>

    <main class="max-w-[1600px] mx-auto px-6 py-6">

        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total</p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1" x-text="stats.total"></p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                <p class="text-xs font-medium text-green-600 dark:text-green-400 uppercase">Activos</p>
                <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-1" x-text="stats.activos"></p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                <p class="text-xs font-medium text-red-600 dark:text-red-400 uppercase">Inactivos</p>
                <p class="text-2xl font-bold text-red-600 dark:text-red-400 mt-1" x-text="stats.inactivos"></p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                <p class="text-xs font-medium text-indigo-600 dark:text-indigo-400 uppercase">Admins</p>
                <p class="text-2xl font-bold text-indigo-600 dark:text-indigo-400 mt-1" x-text="stats.admins"></p>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 mb-6">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-[250px]">
                    <div class="relative">
                        <input type="text" tabindex="-1" aria-hidden="true" autocomplete="username" class="absolute opacity-0 pointer-events-none -z-10 h-0 w-0">
                        <input type="password" tabindex="-1" aria-hidden="true" autocomplete="current-password" class="absolute opacity-0 pointer-events-none -z-10 h-0 w-0">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input x-ref="searchInput" x-init="$nextTick(() => { $el.value = '' })" type="text" x-model.debounce.300ms="search" @input="page = 1; loadUsuarios()"
                               @focus="userTouchedSearch = true"
                               placeholder="Buscar por nombre, login, email..."
                               name="no_autofill_search_users_desktop"
                               autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false"
                               class="w-full pl-10 pr-4 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                    </div>
                </div>
                <select x-model="filtroEstado" @change="page = 1; loadUsuarios()"
                        class="px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                    <option value="all">Todos</option>
                    <option value="Y">Activos</option>
                    <option value="N">Inactivos</option>
                </select>
            </div>
        </div>

        <!-- Grid de usuarios -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
            <div x-show="loading" class="flex items-center justify-center py-12">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
            </div>

            <div x-show="!loading" class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <template x-for="u in usuarios" :key="u.id_login">
                        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-5 shadow-sm flex flex-col gap-3 hover:shadow-md transition-shadow">
                            <!-- Cabecera -->
                            <div class="flex items-center gap-4">
                                <button type="button"
                                        @click="openAvatarModal(u)"
                                        @contextmenu.prevent="openAvatarModal(u)"
                                        class="relative w-12 h-12 rounded-full overflow-hidden flex items-center justify-center text-lg font-bold text-white flex-shrink-0 border border-gray-200 dark:border-slate-700"
                                        :title="'Avatar de ' + u.name">
                                    <template x-if="getUserAvatarUrl(u)">
                                        <img :src="getUserAvatarUrl(u)" alt="avatar" class="w-full h-full object-cover">
                                    </template>
                                    <template x-if="!getUserAvatarUrl(u)">
                                        <div class="w-full h-full flex items-center justify-center"
                                             :class="u.is_admin === 'Y' ? 'bg-indigo-600' : 'bg-gray-500'">
                                            <i :class="u.genero == 2 ? 'fas fa-user-nurse' : 'fas fa-user'"></i>
                                        </div>
                                    </template>
                                    <span class="absolute -right-0.5 -bottom-0.5 w-6 h-6 rounded-full bg-indigo-600 border-2 border-white dark:border-slate-800 text-white flex items-center justify-center shadow-lg">
                                        <i class="fas fa-pen text-[10px]"></i>
                                    </span>
                                </button>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-base font-semibold text-gray-900 dark:text-white truncate" x-text="u.name"></h3>
                                        <span x-show="u.is_admin === 'Y'" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300 uppercase">Admin</span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 font-mono" x-text="'@' + u.login"></p>
                                </div>
                                <span :class="u.active === 'Y' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'"
                                      class="px-2.5 py-0.5 rounded-full text-xs font-medium"
                                      x-text="u.active === 'Y' ? 'Activo' : 'Inactivo'"></span>
                            </div>
                            <!-- Info -->
                            <div class="flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400">
                                <span x-show="u.email"><i class="fas fa-envelope mr-1"></i><span x-text="u.email"></span></span>
                                <span x-show="u.phone"><i class="fas fa-phone mr-1"></i><span x-text="u.phone"></span></span>
                                <span x-show="u.documento"><i class="fas fa-id-card mr-1"></i><span x-text="u.documento"></span></span>
                            </div>
                            <div class="flex items-center gap-2 text-xs">
                                <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200">
                                    <i class="fas fa-store mr-1"></i>
                                    Suc. <span x-text="u.id_sucursal || 0"></span>
                                </span>
                                <span class="px-2 py-0.5 rounded bg-indigo-50 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300">
                                    <i class="fas fa-cash-register mr-1"></i>
                                    Caja #<span x-text="(u.id_caja && Number(u.id_caja) > 0) ? u.id_caja : (u.caja_def || 0)"></span>
                                </span>
                            </div>
                            <div class="flex items-center gap-2 text-xs text-gray-400 dark:text-gray-500">
                                <span x-show="u.grupo_nombre" class="px-2 py-0.5 bg-gray-100 dark:bg-slate-700 rounded text-gray-600 dark:text-gray-300" x-text="u.grupo_nombre"></span>
                                <span class="px-2 py-0.5 bg-indigo-50 dark:bg-indigo-900/20 rounded text-indigo-700 dark:text-indigo-300">
                                    <i class="fas fa-user-tag mr-1"></i>
                                    <span x-text="'Rol: ' + ((u.role && u.role.trim()) ? u.role : 'Vendedor Cajero')"></span>
                                </span>
                            </div>
                            <!-- Acciones -->
                            <div class="flex items-center justify-end gap-2 mt-1 border-t border-gray-100 dark:border-slate-700 pt-3">
                                <button @click="verAccesos(u)" class="px-3 py-1.5 rounded-lg bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-900/20 dark:hover:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 text-xs font-semibold flex items-center gap-1.5 transition-colors" title="Gestionar Accesos">
                                    <i class="fas fa-key"></i> Accesos
                                </button>
                                <button x-show="permisos.priv_update === 'Y'" @click="editarUsuario(u)" class="w-8 h-8 rounded-lg bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-900/20 dark:hover:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 flex items-center justify-center transition-colors" title="Editar">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button x-show="permisos.priv_delete === 'Y'" @click="toggleEstado(u)" class="w-8 h-8 rounded-lg flex items-center justify-center transition-colors"
                                        :class="u.active === 'Y' ? 'bg-red-50 hover:bg-red-100 dark:bg-red-900/20 text-red-600 dark:text-red-400' : 'bg-green-50 hover:bg-green-100 dark:bg-green-900/20 text-green-600 dark:text-green-400'"
                                        :title="u.active === 'Y' ? 'Desactivar' : 'Activar'">
                                    <i class="fas" :class="u.active === 'Y' ? 'fa-ban' : 'fa-check'"></i>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Empty -->
            <div x-show="!loading && usuarios.length === 0" class="py-12 text-center">
                <i class="fas fa-users text-5xl text-gray-300 dark:text-slate-600 mb-3"></i>
                <p class="text-gray-500 dark:text-gray-400">No se encontraron usuarios</p>
            </div>
        </div>

        <!-- Paginación -->
        <div x-show="totalPages > 1" class="flex items-center justify-between mt-4">
            <span class="text-sm text-gray-500 dark:text-gray-400" x-text="'Pág. ' + page + ' de ' + totalPages"></span>
            <div class="flex gap-2">
                <button @click="page--; loadUsuarios()" :disabled="page <= 1"
                        class="px-3 py-2 rounded-lg bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 text-sm disabled:opacity-40">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button @click="page++; loadUsuarios()" :disabled="page >= totalPages"
                        class="px-3 py-2 rounded-lg bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 text-sm disabled:opacity-40">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
    </main>

    <!-- Modal Crear/Editar Usuario -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showModal = false">
        <div class="absolute inset-0 bg-black/50" @click="showModal = false"></div>
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto modal-enter">
            <div class="sticky top-0 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-6 py-4 flex items-center justify-between rounded-t-2xl z-10">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white" x-text="form.id_login ? 'Editar Usuario' : 'Nuevo Usuario'"></h2>
                <button @click="showModal = false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500 hover:bg-gray-200 dark:hover:bg-slate-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="px-6 py-5 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Login *</label>
                        <input type="text" x-model="form.login" :disabled="!!form.id_login"
                               class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm disabled:opacity-50">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Contraseña <span x-show="!form.id_login">*</span></label>
                        <input type="password" x-model="form.pswd" :placeholder="form.id_login ? 'Dejar vacío para no cambiar' : ''"
                               class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Nombre Completo *</label>
                    <input type="text" x-model="form.name"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Email</label>
                        <input type="email" x-model="form.email"
                               class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Teléfono</label>
                        <input type="text" x-model="form.phone"
                               class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Documento</label>
                        <input type="text" x-model="form.documento"
                               class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                    </div>
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300">Grupo</label>
                            <button type="button" @click="openGruposModal()"
                                    class="text-[11px] font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">
                                Gestionar grupos
                            </button>
                        </div>
                        <select x-model="form.group_id"
                                class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                            <template x-for="g in grupos.filter(x => Number(x.activo ?? 1) === 1)" :key="g.group_id">
                                <option :value="g.group_id" x-text="g.description"></option>
                            </template>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Género</label>
                        <select x-model="form.genero"
                                class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                            <option value="1">Masculino</option>
                            <option value="2">Femenino</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Rol</label>
                        <select x-model="form.role"
                                class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                            <option value="Vendedor">Vendedor</option>
                            <option value="Vendedor Cajero">Vendedor Cajero</option>
                            <option value="Cajero">Cajero</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
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
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-36 overflow-y-auto p-2 border border-gray-200 dark:border-slate-700 rounded-xl">
                        <template x-for="tp in tiposPrecio" :key="'tp-' + tp.id">
                            <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300 cursor-pointer">
                                <input type="checkbox"
                                       :checked="tipoPrecioMarcado(tp.id)"
                                       @change="toggleTipoPrecio(tp.id)"
                                       class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                <span x-text="tp.tipo"></span>
                            </label>
                        </template>
                        <div x-show="tiposPrecio.length===0" class="col-span-full text-xs text-gray-500">Sin tipos de precio</div>
                    </div>
                </div>
                <div class="flex items-center gap-6">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="form.active" true-value="Y" false-value="N" class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Activo</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="form.priv_admin" true-value="Y" false-value="N" class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Administrador</span>
                    </label>
                </div>
            </div>
            <div class="sticky bottom-0 bg-gray-50 dark:bg-slate-900 border-t border-gray-200 dark:border-slate-700 px-6 py-4 flex items-center justify-end gap-3 rounded-b-2xl">
                <button @click="showModal = false" class="px-4 py-2.5 bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium hover:bg-gray-300 dark:hover:bg-slate-600 transition-colors">Cancelar</button>
                <button @click="guardarUsuario()" :disabled="saving" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-semibold transition-colors disabled:opacity-50 flex items-center gap-2">
                    <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                    <span x-text="saving ? 'Guardando...' : 'Guardar'"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Accesos (Apps habilitadas) -->
    <div x-show="showAccesos" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showAccesos = false">
        <div class="absolute inset-0 bg-black/50" @click="showAccesos = false"></div>
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-5xl max-h-[90vh] overflow-y-auto modal-enter">
            <div class="sticky top-0 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-6 py-4 flex items-center justify-between rounded-t-2xl z-10">
                <div>
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white">Accesos de Apps</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400" x-text="'Usuario: ' + accesosUsuario.name + ' (@' + accesosUsuario.login + ')'"></p>
                </div>
                <button @click="showAccesos = false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500 hover:bg-gray-200 dark:hover:bg-slate-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="px-6 py-5">
                <!-- Loading -->
                <div x-show="loadingAccesos" class="flex items-center justify-center py-8">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                </div>

                <div x-show="!loadingAccesos">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                        <i class="fas fa-info-circle mr-1"></i> Solo se muestran las apps habilitadas en la suscripción de la empresa. Los permisos se aplican al grupo del usuario.
                    </p>

                    <div class="space-y-3">
                        <template x-for="app in appsEmpresa" :key="app.permiso_base || app.codigo_app">
                            <div class="p-4 rounded-xl border border-gray-200 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700/50 transition-colors">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-sm shrink-0"
                                             :style="'background-color:' + getColor(app.color)">
                                            <template x-if="app.icono_svg_resuelto">
                                                <img :src="app.icono_svg_resuelto" alt="" class="w-6 h-6 object-contain">
                                            </template>
                                            <template x-if="!app.icono_svg_resuelto">
                                                <i :class="app.icono || 'fas fa-cube'"></i>
                                            </template>
                                        </div>
                                        <div>
                                            <h4 class="text-sm font-semibold text-gray-900 dark:text-white" x-text="app.nombre_app"></h4>
                                            <p class="text-xs text-gray-400" x-text="app.modulo"></p>
                                        </div>
                                    </div>
                                    <!-- Toggle principal acceso -->
                                    <button @click="toggleAccesoApp(app.permiso_base || app.codigo_app)"
                                            class="relative w-12 h-6 rounded-full transition-colors toggle-switch shrink-0"
                                            :class="getPermiso(app.permiso_base || app.codigo_app, 'priv_access') === 'Y' ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-slate-600'">
                                        <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow toggle-dot"
                                              :style="getPermiso(app.permiso_base || app.codigo_app, 'priv_access') === 'Y' ? 'transform:translateX(24px)' : ''"></span>
                                    </button>
                                </div>
                                <!-- Permisos detallados - fila debajo -->
                                <div class="flex flex-wrap items-center gap-1.5 mt-3 pt-3 border-t border-gray-100 dark:border-slate-700/50">
                                    <template x-for="perm in [
                                        {key:'insert', label:'Insertar', icon:'fa-plus'},
                                        {key:'update', label:'Editar', icon:'fa-pen'},
                                        {key:'delete', label:'Eliminar', icon:'fa-trash'},
                                        {key:'export', label:'Exportar', icon:'fa-file-export'},
                                        {key:'print', label:'Imprimir', icon:'fa-print'}
                                    ]" :key="perm.key">
                                        <button @click="togglePermiso(app.permiso_base || app.codigo_app, 'priv_' + perm.key)"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all"
                                                :class="getPermiso(app.permiso_base || app.codigo_app, 'priv_' + perm.key) === 'Y' 
                                                    ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 ring-1 ring-green-200 dark:ring-green-800' 
                                                    : 'bg-gray-100 text-gray-400 dark:bg-slate-700 dark:text-slate-500'">
                                            <i class="fas text-[10px]" :class="perm.icon"></i>
                                            <span x-text="perm.label"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div x-show="appsEmpresa.length === 0" class="py-8 text-center">
                        <i class="fas fa-box-open text-4xl text-gray-300 dark:text-slate-600 mb-2"></i>
                        <p class="text-sm text-gray-500">No hay apps habilitadas en la suscripción</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Grupos -->
    <div x-show="showGruposModal" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center p-4" @keydown.escape.window="showGruposModal = false">
        <div class="absolute inset-0 bg-black/60" @click="showGruposModal = false"></div>
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto modal-enter">
            <div class="sticky top-0 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-6 py-4 flex items-center justify-between rounded-t-2xl z-10">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white">Grupos de Usuarios</h2>
                <button @click="showGruposModal = false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500 hover:bg-gray-200 dark:hover:bg-slate-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="px-6 py-5 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-[1fr_auto_auto] gap-3">
                    <input type="text" x-model="groupForm.description" placeholder="Nombre del grupo"
                           class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                    <button @click="guardarGrupo()" :disabled="groupsSaving"
                            class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-semibold disabled:opacity-50">
                        <span x-text="groupForm.group_id ? 'Actualizar' : 'Agregar'"></span>
                    </button>
                    <button @click="limpiarGroupForm()"
                            class="px-4 py-2.5 bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium">
                        Limpiar
                    </button>
                </div>
                <p x-show="groupError" class="text-sm text-red-500" x-text="groupError"></p>

                <div class="rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
                    <div class="grid grid-cols-[90px_1fr_180px] px-4 py-2 bg-gray-50 dark:bg-slate-700/50 text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase">
                        <div>ID</div>
                        <div>Grupo</div>
                        <div class="text-right">Acciones</div>
                    </div>
                    <template x-for="g in gruposAll" :key="'grp-'+g.group_id">
                        <div class="grid grid-cols-[90px_1fr_180px] items-center px-4 py-3 border-t border-gray-100 dark:border-slate-700">
                            <div class="text-sm text-gray-500 dark:text-gray-400" x-text="g.group_id"></div>
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="text-sm text-gray-900 dark:text-white truncate" x-text="g.description"></span>
                                <span x-show="Number(g.activo ?? 1) !== 1" class="px-2 py-0.5 rounded-full text-[10px] bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">Anulado</span>
                            </div>
                            <div class="flex items-center justify-end gap-2">
                                <button @click="editarGrupo(g)" class="w-8 h-8 rounded-lg bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button @click="toggleGrupoEstado(g)"
                                        class="px-2.5 py-1.5 rounded-lg text-xs font-semibold"
                                        :class="Number(g.activo ?? 1) === 1 ? 'bg-red-50 text-red-600 dark:bg-red-900/20 dark:text-red-400' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/20 dark:text-emerald-400'">
                                    <span x-text="Number(g.activo ?? 1) === 1 ? 'Anular' : 'Activar'"></span>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Avatar Usuario -->
    <div x-show="showAvatarModal" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4" @keydown.escape.window="closeAvatarModal()">
        <div class="absolute inset-0 bg-black/60" @click="closeAvatarModal()"></div>
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto modal-enter">
            <div class="sticky top-0 bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 px-6 py-4 flex items-center justify-between rounded-t-2xl z-10">
                <div>
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white">Capturar Avatar</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400" x-text="avatarUser ? (avatarUser.name + ' (@' + avatarUser.login + ')') : ''"></p>
                </div>
                <button @click="closeAvatarModal()" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center text-gray-500 hover:bg-gray-200 dark:hover:bg-slate-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="px-6 py-5 space-y-5">
                <div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-5">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-40 h-40 rounded-3xl overflow-hidden border border-gray-200 dark:border-slate-700 bg-gray-100 dark:bg-slate-900 flex items-center justify-center">
                            <template x-if="avatarPreview">
                                <img :src="avatarPreview" alt="preview avatar" class="w-full h-full object-cover">
                            </template>
                            <template x-if="!avatarPreview">
                                <div class="text-center px-4">
                                    <i class="fas fa-user-circle text-5xl text-gray-300 dark:text-slate-600"></i>
                                    <p class="mt-2 text-xs text-gray-500 dark:text-slate-400">Sin previsualización</p>
                                </div>
                            </template>
                        </div>
                        <button x-show="avatarPreview" @click="clearAvatarDraft()" class="text-xs font-semibold text-red-600 dark:text-red-400 hover:underline">Limpiar borrador</button>
                    </div>

                    <div class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <button @click="captureAvatarFromBingCatalog()" class="px-4 py-3 rounded-xl bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-semibold flex items-center justify-center gap-2 disabled:opacity-50"
                                    :disabled="avatarBingLoading">
                                <i class="fab" :class="avatarBingLoading ? 'fa-microsoft fa-spin' : 'fa-microsoft'"></i> Bing catálogo
                            </button>
                            <button @click="openAvatarGoogleSearch()" class="px-4 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold flex items-center justify-center gap-2">
                                <i class="fab fa-google"></i> Imagen Google
                            </button>
                            <button @click="captureAvatarFromPixabay()" class="px-4 py-3 rounded-xl bg-sky-600 hover:bg-sky-700 text-white text-sm font-semibold flex items-center justify-center gap-2 disabled:opacity-50"
                                    :disabled="avatarPixabayLoading">
                                <i class="fas" :class="avatarPixabayLoading ? 'fa-spinner fa-spin' : 'fa-image'"></i> Pixabay
                            </button>
                            <button @click="openAvatarPexelsSearch()" class="px-4 py-3 rounded-xl bg-slate-900 hover:bg-black text-white text-sm font-semibold flex items-center justify-center gap-2">
                                <i class="fas fa-images"></i> Pexels
                            </button>
                            <button @click="$refs.avatarCameraInput.click()" class="px-4 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold flex items-center justify-center gap-2">
                                <i class="fas fa-camera"></i> Cámara
                            </button>
                            <button @click="$refs.avatarFileInput.click()" class="px-4 py-3 rounded-xl bg-slate-700 hover:bg-slate-600 text-white text-sm font-semibold flex items-center justify-center gap-2">
                                <i class="fas fa-folder-open"></i> Directorio
                            </button>
                        </div>

                        <div class="rounded-xl border border-gray-200 dark:border-slate-700 p-4 bg-gray-50 dark:bg-slate-900/60">
                            <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-2">Pegar URL manual de imagen</label>
                            <div class="flex flex-col sm:flex-row gap-2">
                                <input x-model="avatarImageUrl" type="text" placeholder="https://..." class="flex-1 px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                                <button @click="previewAvatarFromUrl()" class="px-4 py-2.5 rounded-xl bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-900/30 dark:hover:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300 text-sm font-semibold">
                                    Previsualizar
                                </button>
                            </div>
                            <p class="mt-2 text-[11px] text-gray-500 dark:text-slate-400">Fuentes rápidas: Bing catálogo, Imagen Google, Pixabay, Pexels, Directorio o Cámara. La última capturada reemplaza la anterior.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sticky bottom-0 bg-gray-50 dark:bg-slate-900 border-t border-gray-200 dark:border-slate-700 px-6 py-4 flex items-center justify-end gap-3 rounded-b-2xl">
                <button @click="closeAvatarModal()" class="px-4 py-2.5 bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium hover:bg-gray-300 dark:hover:bg-slate-600 transition-colors">Cancelar</button>
                <button @click="saveAvatarDraft()" :disabled="avatarSaving || (!avatarFile && !avatarImageUrl.trim())"
                        class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-semibold transition-colors disabled:opacity-50 flex items-center gap-2">
                    <i class="fas" :class="avatarSaving ? 'fa-spinner fa-spin' : 'fa-image-portrait'"></i>
                    <span x-text="avatarSaving ? 'Guardando...' : 'Guardar avatar'"></span>
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
         :class="toast.type === 'error' ? 'bg-red-600' : 'bg-emerald-600'">
        <i class="fas self-start mt-0.5" :class="toast.type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'"></i>
        <div class="leading-tight">
            <div x-text="toast.msg"></div>
            <div x-show="toast.type === 'error'" class="text-[11px] text-red-100/95 mt-0.5">Enviar el error al Departamento de Desarrollo</div>
        </div>
    </div>

    <!-- Error persistente para copia/reporte -->
    <div x-show="bugReport.show" x-cloak class="fixed bottom-6 left-6 z-[110] w-[min(680px,calc(100vw-3rem))] rounded-2xl border border-red-300/60 dark:border-red-800 bg-white/95 dark:bg-slate-900/95 backdrop-blur shadow-2xl">
        <div class="px-4 py-3 border-b border-red-200/70 dark:border-red-900/50 flex items-center justify-between">
            <h3 class="text-sm font-bold text-red-700 dark:text-red-300 flex items-center gap-2"><i class="fas fa-bug"></i> Bug detectado</h3>
            <button @click="bugReport.show=false" class="w-7 h-7 rounded-lg bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300"><i class="fas fa-times"></i></button>
        </div>
        <div class="px-4 py-3 text-xs md:text-sm text-gray-800 dark:text-gray-200 space-y-1">
            <p><b>Empresa:</b> <span x-text="bugReport.empresa"></span> (<span x-text="bugReport.id_empresa"></span>)</p>
            <p><b>App:</b> <span x-text="bugReport.app"></span></p>
            <p><b>Error:</b> <span x-text="bugReport.error"></span></p>
        </div>
        <div class="px-4 pb-4 flex items-center gap-2">
            <button @click="copyBug()" class="px-3 py-2 rounded-lg bg-slate-700 text-white text-xs font-semibold">Copiar</button>
            <button @click="sendBugToDev()" class="px-3 py-2 rounded-lg bg-green-600 text-white text-xs font-semibold">Enviar a Desarrollo</button>
        </div>
    </div>
</div>

<script>
function usuariosApp() {
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
        gruposAll: [],
        sucursales: [],
        cajas: [],
        tiposPrecio: [],

        // Modal usuario
        showModal: false,
        saving: false,
        form: {},

        // Modal grupos
        showGruposModal: false,
        groupsSaving: false,
        groupError: '',
        groupForm: { group_id: 0, description: '' },

        // Modal accesos
        showAccesos: false,
        loadingAccesos: false,
        accesosUsuario: {},
        appsEmpresa: [],
        permisosMap: {},
        accesosGroupId: 0,

        // Toast
        toast: { show: false, msg: '', type: 'ok' },
        bugAppContext: 'usuarios/accesos',
        bugReport: { show: false, empresa: '', id_empresa: 0, app: '', error: '', text: '' },
        hasInitialized: false,
        userTouchedSearch: false,
        antiAutofillTimer: null,
        avatarHoldTimer: null,
        avatarHoldUserId: null,
        showAvatarModal: false,
        avatarUser: null,
        avatarPreview: '',
        avatarImageUrl: '',
        avatarFile: null,
        avatarSaving: false,
        avatarBingLoading: false,
        avatarPixabayLoading: false,

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

        async parseJsonResponse(res, fallbackMessage) {
            const raw = await res.text();
            try {
                const data = raw ? JSON.parse(raw) : {};
                if (!res.ok && data && typeof data === 'object' && data.error) {
                    throw new Error(String(data.error));
                }
                return data;
            } catch (e) {
                if (res.status === 413) {
                    throw new Error('La imagen excede el tamaño permitido');
                }
                const snippet = String(raw || '').replace(/\s+/g, ' ').trim().slice(0, 180);
                throw new Error(snippet || fallbackMessage || 'Respuesta invalida del servidor');
            }
        },

        readBlobAsDataUrl(blob) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = (e) => resolve(String(e?.target?.result || ''));
                reader.onerror = () => reject(new Error('No se pudo leer la imagen'));
                reader.readAsDataURL(blob);
            });
        },

        loadImageElement(src) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => resolve(img);
                img.onerror = () => reject(new Error('No se pudo procesar la imagen'));
                img.src = src;
            });
        },

        canvasToBlob(canvas, type, quality) {
            return new Promise((resolve, reject) => {
                canvas.toBlob((blob) => {
                    if (blob) resolve(blob);
                    else reject(new Error('No se pudo generar la imagen'));
                }, type, quality);
            });
        },

        async optimizeAvatarBlob(blob, filename = 'avatar.jpg') {
            if (!blob || !String(blob.type || '').startsWith('image/')) {
                throw new Error('Archivo de imagen inválido');
            }
            if (blob.size <= 1400 * 1024) {
                return new File([blob], filename, { type: blob.type || 'image/jpeg' });
            }

            const dataUrl = await this.readBlobAsDataUrl(blob);
            const img = await this.loadImageElement(dataUrl);
            const maxSide = 960;
            const scale = Math.min(1, maxSide / Math.max(img.naturalWidth || img.width || 1, img.naturalHeight || img.height || 1));
            const width = Math.max(1, Math.round((img.naturalWidth || img.width || 1) * scale));
            const height = Math.max(1, Math.round((img.naturalHeight || img.height || 1) * scale));
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                throw new Error('No se pudo optimizar la imagen');
            }
            ctx.drawImage(img, 0, 0, width, height);

            let quality = 0.86;
            let out = await this.canvasToBlob(canvas, 'image/jpeg', quality);
            while (out.size > 1400 * 1024 && quality > 0.45) {
                quality -= 0.08;
                out = await this.canvasToBlob(canvas, 'image/jpeg', quality);
            }

            return new File([out], filename.replace(/\.[a-z0-9]+$/i, '') + '.jpg', { type: 'image/jpeg' });
        },

        getUserAvatarUrl(u) {
            const foto = String(u?.foto || '').trim();
            if (!foto || foto === 'defaultuser.png') return '';
            const idLogin = Number(u?.id_login || 0);
            if (idLogin > 0) {
                return `api/avatar.php?action=view&id_login=${idLogin}&v=${encodeURIComponent(foto)}`;
            }
            if (/^https?:\/\//i.test(foto) || foto.startsWith('/public/')) return foto;
            return '/public/_lib/file/usuario/' + encodeURIComponent(foto);
        },

        startAvatarLongPress(u) {
            this.cancelAvatarLongPress();
            const userId = Number(u?.id_login || 0);
            if (!userId) return;
            this.avatarHoldUserId = userId;
            this.avatarHoldTimer = setTimeout(() => this.openAvatarModal(u), 550);
        },

        cancelAvatarLongPress() {
            if (this.avatarHoldTimer) {
                clearTimeout(this.avatarHoldTimer);
                this.avatarHoldTimer = null;
            }
            this.avatarHoldUserId = null;
        },

        openAvatarModal(u) {
            this.cancelAvatarLongPress();
            this.avatarUser = u ? { ...u } : null;
            this.avatarPreview = u ? this.getUserAvatarUrl(u) : '';
            this.avatarImageUrl = '';
            this.avatarFile = null;
            this.avatarSaving = false;
            this.avatarBingLoading = false;
            this.avatarPixabayLoading = false;
            this.showAvatarModal = true;
        },

        closeAvatarModal() {
            this.showAvatarModal = false;
            this.avatarUser = null;
            this.avatarPreview = '';
            this.avatarImageUrl = '';
            this.avatarFile = null;
            this.avatarBingLoading = false;
            this.avatarPixabayLoading = false;
            if (this.$refs.avatarFileInput) this.$refs.avatarFileInput.value = '';
            if (this.$refs.avatarCameraInput) this.$refs.avatarCameraInput.value = '';
        },

        clearAvatarDraft() {
            this.avatarPreview = this.avatarUser ? this.getUserAvatarUrl(this.avatarUser) : '';
            this.avatarImageUrl = '';
            this.avatarFile = null;
            if (this.$refs.avatarFileInput) this.$refs.avatarFileInput.value = '';
            if (this.$refs.avatarCameraInput) this.$refs.avatarCameraInput.value = '';
        },

        async captureAvatarFromBingCatalog() {
            if (!this.avatarUser || this.avatarBingLoading) return;
            const query = String(this.avatarUser.name || this.avatarUser.login || '').trim();
            if (!query) return;
            this.avatarBingLoading = true;
            try {
                const tempId = Number(this.avatarUser.id_login || Date.now());
                const proxyUrl = `/public/pos/api/imagen_proxy.php?id=${encodeURIComponent(tempId)}&q=${encodeURIComponent(query)}&prefer=bing_catalog&refresh=1`;
                const response = await fetch(proxyUrl, { cache: 'no-store' });
                if (!response.ok) throw new Error('No se encontró una imagen válida en Bing catálogo');
                const blob = await response.blob();
                if (!blob || !String(blob.type || '').startsWith('image/')) {
                    throw new Error('Bing catálogo no devolvió una imagen válida');
                }
                const ext = blob.type.includes('png') ? 'png' : (blob.type.includes('jpeg') ? 'jpg' : 'webp');
                const file = new File([blob], `avatar_bing_${Date.now()}.${ext}`, { type: blob.type || 'image/webp' });
                this.avatarFile = file;
                this.avatarImageUrl = '';
                this.avatarPreview = URL.createObjectURL(blob);
                this.showToast('Imagen capturada desde Bing catálogo');
            } catch (e) {
                this.showToast(e?.message || 'No se pudo capturar imagen desde Bing catálogo', 'error');
            }
            this.avatarBingLoading = false;
        },

        openAvatarGoogleSearch() {
            if (!this.avatarUser) return;
            const q = encodeURIComponent(`${this.avatarUser.name || this.avatarUser.login || 'usuario'} avatar`);
            window.open(`https://www.google.com/search?tbm=isch&q=${q}`, '_blank', 'noopener,noreferrer');
        },

        openAvatarPexelsSearch() {
            if (!this.avatarUser) return;
            const q = encodeURIComponent(`${this.avatarUser.name || this.avatarUser.login || 'usuario'} portrait`);
            window.open(`https://www.pexels.com/search/${q}/`, '_blank', 'noopener,noreferrer');
        },

        async captureAvatarFromPixabay() {
            if (!this.avatarUser || this.avatarPixabayLoading) return;
            const query = String(this.avatarUser.name || this.avatarUser.login || '').trim();
            if (!query) return;
            this.avatarPixabayLoading = true;
            try {
                const res = await fetch(`/public/pos/api/buscar_imagenes.php?q=${encodeURIComponent(query)}&page=1&_t=${Date.now()}`, { cache: 'no-store' });
                const data = await this.parseJsonResponse(res, 'No se pudo consultar Pixabay');
                const img = Array.isArray(data?.images) ? (data.images[0] || null) : null;
                const imageUrl = String(img?.large || img?.preview || img?.thumbnail || '').trim();
                if (!imageUrl) {
                    throw new Error('Pixabay no encontró imagen para este usuario');
                }
                this.avatarImageUrl = imageUrl;
                this.avatarFile = null;
                this.avatarPreview = imageUrl;
                this.showToast(`Imagen cargada desde ${(data?.source || 'pixabay')}`);
            } catch (e) {
                this.showToast(e?.message || 'No se pudo consultar Pixabay', 'error');
            }
            this.avatarPixabayLoading = false;
        },

        handleAvatarFileSelected(event) {
            const file = event?.target?.files?.[0] || null;
            if (!file) return;
            this.avatarFile = file;
            this.avatarImageUrl = '';
            const reader = new FileReader();
            reader.onload = (e) => {
                this.avatarPreview = String(e?.target?.result || '');
            };
            reader.readAsDataURL(file);
        },

        previewAvatarFromUrl() {
            const url = String(this.avatarImageUrl || '').trim();
            if (!/^https?:\/\//i.test(url)) {
                this.showToast('URL de imagen inválida', 'error');
                return;
            }
            this.avatarFile = null;
            this.avatarPreview = url;
        },

        async saveAvatarDraft() {
            if (!this.avatarUser?.id_login) return;
            this.avatarSaving = true;
            try {
                let res;
                if (this.avatarFile) {
                    const optimizedFile = await this.optimizeAvatarBlob(
                        this.avatarFile,
                        String(this.avatarFile.name || `avatar_${Date.now()}.jpg`)
                    );
                    const fd = new FormData();
                    fd.append('action', 'upload');
                    fd.append('id_login', String(this.avatarUser.id_login));
                    fd.append('avatar', optimizedFile);
                    res = await fetch('api/avatar.php', { method: 'POST', body: fd });
                } else {
                    const imageUrl = String(this.avatarImageUrl || '').trim();
                    if (!/^https?:\/\//i.test(imageUrl)) {
                        throw new Error('URL de imagen inválida');
                    }
                    try {
                        const remoteRes = await fetch(imageUrl, { mode: 'cors', cache: 'no-store' });
                        if (!remoteRes.ok) throw new Error('No se pudo descargar la imagen');
                        const remoteBlob = await remoteRes.blob();
                        const optimizedFile = await this.optimizeAvatarBlob(remoteBlob, `avatar_url_${Date.now()}.jpg`);
                        const fd = new FormData();
                        fd.append('action', 'upload');
                        fd.append('id_login', String(this.avatarUser.id_login));
                        fd.append('avatar', optimizedFile);
                        res = await fetch('api/avatar.php', { method: 'POST', body: fd });
                    } catch (_) {
                        res = await fetch('api/avatar.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'upload_url',
                                id_login: Number(this.avatarUser.id_login),
                                image_url: imageUrl
                            })
                        });
                    }
                }
                const data = await this.parseJsonResponse(res, 'No se pudo guardar el avatar');
                if (!data?.ok) throw new Error(data?.error || 'No se pudo guardar el avatar');

                const idx = this.usuarios.findIndex(x => Number(x.id_login) === Number(this.avatarUser.id_login));
                if (idx >= 0) {
                    this.usuarios[idx].foto = data.foto || '';
                    this.usuarios = [...this.usuarios];
                }
                this.showToast(data.msg || 'Avatar actualizado');
                this.closeAvatarModal();
            } catch (e) {
                this.showToast(e?.message || 'No se pudo guardar el avatar', 'error');
            }
            this.avatarSaving = false;
        },

        toggleTheme() {
            this.isDark = true;
            localStorage.setItem('theme', 'dark');
            this.applyTheme();
        },

        async loadUsuarios() {
            this.loading = true;
            try {
                if (!this.hasInitialized) this.hardResetSearch();
                const params = new URLSearchParams({
                    search: this.search, estado: this.filtroEstado,
                    page: this.page, limit: 25
                });
                const res = await fetch('api/list.php?' + params);
                const data = await this.parseJsonResponse(res, 'No se pudo cargar la lista de usuarios');
                if (data.ok) {
                    this.usuarios = data.data;
                    this.totalPages = data.pages;
                    if (data.stats) this.stats = data.stats;
                } else {
                    this.usuarios = [];
                    this.totalPages = 1;
                    this.showToast(data.error || 'No se pudo cargar la lista de usuarios', 'error');
                }
            } catch (e) {
                console.error(e);
                this.usuarios = [];
                this.totalPages = 1;
                this.showToast('Error de conexión al cargar usuarios', 'error');
            }
            this.hasInitialized = true;
            this.loading = false;
        },

        async loadGrupos(includeInactive = false) {
            try {
                const res = await fetch('api/apps_usuario.php?action=grupos&include_inactive=' + (includeInactive ? '1' : '0'));
                const data = await this.parseJsonResponse(res, 'No se pudo cargar la lista de grupos');
                if (data.ok) {
                    if (includeInactive) {
                        this.gruposAll = data.grupos || [];
                        this.grupos = (data.grupos || []).filter(g => Number(g.activo ?? 1) === 1);
                    } else {
                        this.grupos = data.grupos || [];
                    }
                }
            } catch (e) { console.error(e); }
        },

        async loadCatalogos() {
            try {
                const res = await fetch('api/catalogos.php');
                const data = await this.parseJsonResponse(res, 'No se pudieron cargar los catalogos');
                if (data.ok) {
                    this.sucursales = data.sucursales || [];
                    this.cajas = data.cajas || [];
                    this.tiposPrecio = data.tipos_precio || [];
                }
            } catch (e) {
                console.error(e);
            }
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
                genero: 1, role: 'Vendedor Cajero',
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
                group_id: (u.group_id ? Number(u.group_id) : 1), genero: u.genero || 1, role: (u.role && u.role.trim()) ? u.role : 'Vendedor Cajero',
                id_sucursal: String(u.id_sucursal || 0),
                id_caja: String((u.id_caja && Number(u.id_caja) > 0) ? u.id_caja : (u.caja_def || 0)),
                tipos_precio_asignados: this.normalizeTiposInput(u.tipos_precio_asignados || '')
            };
            // Fallback por si la lista vino sin group_id.
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
                const data = await this.parseJsonResponse(res, 'No se pudo cargar el grupo del usuario');
                if (data.ok && data.group_id) {
                    this.form.group_id = Number(data.group_id);
                }
            } catch (e) {}
        },

        openGruposModal() {
            this.showGruposModal = true;
            this.groupError = '';
            this.limpiarGroupForm();
            this.loadGrupos(true);
        },

        limpiarGroupForm() {
            this.groupForm = { group_id: 0, description: '' };
            this.groupError = '';
        },

        editarGrupo(g) {
            this.groupForm = {
                group_id: Number(g.group_id || 0),
                description: String(g.description || '')
            };
            this.groupError = '';
        },

        async guardarGrupo() {
            const desc = String(this.groupForm.description || '').trim();
            if (desc.length < 2) {
                this.groupError = 'El nombre del grupo es obligatorio.';
                return;
            }
            this.groupsSaving = true;
            this.groupError = '';
            try {
                const res = await fetch('api/apps_usuario.php?action=guardar_grupo', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        group_id: Number(this.groupForm.group_id || 0),
                        description: desc
                    })
                });
                const data = await this.parseJsonResponse(res, 'No se pudo guardar el grupo');
                if (data.ok) {
                    this.showToast(data.msg || 'Grupo guardado');
                    this.limpiarGroupForm();
                    await this.loadGrupos(true);
                } else {
                    this.groupError = data.error || 'No se pudo guardar el grupo';
                }
            } catch (e) {
                this.groupError = 'Error de conexión';
            }
            this.groupsSaving = false;
        },

        async toggleGrupoEstado(g) {
            const isActivo = Number(g.activo ?? 1) === 1;
            const accion = isActivo ? 'anular' : 'activar';
            if (!confirm(`¿Desea ${accion} el grupo "${g.description}"?`)) return;
            try {
                const res = await fetch('api/apps_usuario.php?action=anular_grupo', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ group_id: Number(g.group_id), accion })
                });
                const data = await this.parseJsonResponse(res, 'No se pudo actualizar el grupo');
                if (data.ok) {
                    this.showToast(data.msg || 'Estado actualizado');
                    await this.loadGrupos(true);
                } else {
                    this.showToast(data.error || 'No se pudo actualizar el grupo', 'error');
                }
            } catch (e) {
                this.showToast('Error de conexión', 'error');
            }
        },

        async guardarUsuario() {
            if (!this.form.login.trim() || !this.form.name.trim()) {
                this.showToast('Login y nombre son obligatorios', 'error');
                return;
            }
            this.form.role = (this.form.role || '').trim() || 'Vendedor Cajero';
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
                const data = await this.parseJsonResponse(res, 'No se pudo guardar el usuario');
                if (data.ok) {
                    this.showToast(data.msg);
                    this.showModal = false;
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
                const data = await this.parseJsonResponse(res, 'No se pudo actualizar el estado del usuario');
                if (data.ok) {
                    this.showToast(data.msg);
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
                const data = await this.parseJsonResponse(res, 'No se pudo cargar accesos');
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
                const data = await this.parseJsonResponse(res, 'No se pudo actualizar el acceso');
                if (data.ok) {
                    // Actualizar local
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
            if (!this.accesosGroupId) return;
            const current = this.getPermiso(appCode, permiso);
            const newVal = current === 'Y' ? 'N' : 'Y';
            try {
                const res = await fetch('api/apps_usuario.php?action=guardar_permiso', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ group_id: this.accesosGroupId, app_name: appCode, permiso, valor: newVal })
                });
                const data = await this.parseJsonResponse(res, 'No se pudo actualizar el permiso');
                if (data.ok) {
                    if (!this.permisosMap[appCode]) this.permisosMap[appCode] = {};
                    this.permisosMap[appCode][permiso] = newVal;
                    this.showToast(data.msg);
                }
            } catch (e) {}
        },

        getColor(color) {
            const map = { blue:'#3b82f6', red:'#ef4444', green:'#22c55e', amber:'#f59e0b', purple:'#8b5cf6', indigo:'#6366f1', emerald:'#10b981', pink:'#ec4899', cyan:'#06b6d4', teal:'#14b8a6', orange:'#f97316', yellow:'#eab308' };
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
