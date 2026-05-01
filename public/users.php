<?php

/**
 * Gestión de Usuarios - Vista CRUD
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/Modules/Users/UsersController.php';

// Verificar login
if (!Session::isLoggedIn()) {
    header('Location: /public/login.php');
    exit;
}

// Verificar permisos de admin
$isAdmin = $_SESSION['usr_priv_admin'] === 'Y';
if (!$isAdmin) {
    header('Location: /public/menu/menu.php?error=no_permission');
    exit;
}

$csrfToken = Security::generateCSRFToken();

// Obtener datos para selectores
$empresasResult = UsersController::getEmpresas();
$empresas = $empresasResult['data'] ?? [];

$groupsResult = UsersController::getGroups();
$groups = $groupsResult['data'] ?? [];

function h($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo h($csrfToken); ?>">
    <title>Gestión de Usuarios | Sistemax v1</title>

    <link rel="stylesheet" href="assets/tailwind.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif']
                    }
                }
            }
        }
    </script>

    <style>
        [x-cloak] {
            display: none !important;
        }

        html.dark input,
        html.dark select {
            background-color: #1e293b !important;
            border-color: #475569 !important;
            color: #f1f5f9 !important;
        }
    </style>
</head>

<body class="bg-gray-100 dark:bg-slate-950 min-h-screen font-sans" x-data="usersApp()" x-init="init()">
    <!-- Header -->
    <header class="bg-white dark:bg-slate-900 shadow-sm border-b border-gray-200 dark:border-slate-800">
        <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="menu/menu.php" class="text-gray-500 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-slate-100">
                    <i class="fas fa-users mr-2 text-blue-600 dark:text-blue-400"></i>Gestión de Usuarios
                </h1>
            </div>
            <button @click="openModal('create')"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2 transition-colors">
                <i class="fas fa-plus"></i>
                <span class="hidden sm:inline">Nuevo Usuario</span>
            </button>
        </div>
    </header>

    <!-- Filtros -->
    <div class="max-w-7xl mx-auto px-4 py-4">
        <div class="bg-white dark:bg-slate-900 rounded-lg shadow-sm p-4 border border-gray-200 dark:border-slate-800">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- Búsqueda -->
                <div class="md:col-span-2">
                    <div class="relative">
                        <input type="text" x-model="filters.search" @input.debounce.300ms="loadUsers()"
                            placeholder="Buscar por usuario, nombre o email..."
                            class="w-full pl-10 pr-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 
                                      focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>

                <!-- Estado -->
                <div>
                    <select x-model="filters.status" @change="loadUsers()"
                        class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600">
                        <option value="">Todos los estados</option>
                        <option value="Y">Activos</option>
                        <option value="N">Inactivos</option>
                    </select>
                </div>

                <!-- Empresa -->
                <div>
                    <select x-model="filters.id_empresa" @change="loadUsers()"
                        class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600">
                        <option value="">Todas las empresas</option>
                        <?php foreach ($empresas as $e): ?>
                            <option value="<?php echo $e['id_empresa']; ?>"><?php echo h($e['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla -->
    <div class="max-w-7xl mx-auto px-4 pb-8">
        <div class="bg-white dark:bg-slate-900 rounded-lg shadow-sm border border-gray-200 dark:border-slate-800 overflow-hidden">
            <!-- Loading -->
            <div x-show="loading" class="p-8 text-center">
                <i class="fas fa-spinner fa-spin text-3xl text-blue-600"></i>
                <p class="mt-2 text-gray-500 dark:text-slate-400">Cargando usuarios...</p>
            </div>

            <!-- Tabla de usuarios -->
            <div x-show="!loading" x-cloak>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 dark:bg-slate-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Usuario</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Nombre</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase hidden md:table-cell">Email</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400 uppercase hidden lg:table-cell">Empresa</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Estado</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Admin</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                            <template x-for="user in users" :key="user.id_login">
                                <tr class="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors">
                                    <td class="px-4 py-3">
                                        <span class="font-medium text-gray-900 dark:text-slate-100" x-text="user.login"></span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-slate-300" x-text="user.name || '-'"></td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-slate-400 hidden md:table-cell" x-text="user.email || '-'"></td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-slate-400 hidden lg:table-cell" x-text="user.empresa_nombre || '-'"></td>
                                    <td class="px-4 py-3 text-center">
                                        <span :class="user.active === 'Y' 
                                                ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' 
                                                : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'"
                                            class="px-2 py-1 rounded-full text-xs font-medium">
                                            <span x-text="user.active === 'Y' ? 'Activo' : 'Inactivo'"></span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <i x-show="user.priv_admin === 'Y'" class="fas fa-shield-alt text-amber-500" title="Administrador"></i>
                                        <span x-show="user.priv_admin !== 'Y'" class="text-gray-400">-</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-end gap-2">
                                            <button @click="openModal('edit', user)" title="Editar"
                                                class="p-2 text-blue-600 hover:bg-blue-100 dark:hover:bg-blue-900/30 rounded-lg transition-colors">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button @click="openPasswordModal(user)" title="Cambiar contraseña"
                                                class="p-2 text-amber-600 hover:bg-amber-100 dark:hover:bg-amber-900/30 rounded-lg transition-colors">
                                                <i class="fas fa-key"></i>
                                            </button>
                                            <button x-show="user.active === 'Y'" @click="toggleStatus(user)" title="Desactivar"
                                                class="p-2 text-red-600 hover:bg-red-100 dark:hover:bg-red-900/30 rounded-lg transition-colors">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                            <button x-show="user.active === 'N'" @click="toggleStatus(user)" title="Activar"
                                                class="p-2 text-green-600 hover:bg-green-100 dark:hover:bg-green-900/30 rounded-lg transition-colors">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Sin resultados -->
                <div x-show="users.length === 0" class="p-8 text-center text-gray-500 dark:text-slate-400">
                    <i class="fas fa-user-slash text-4xl mb-3"></i>
                    <p>No se encontraron usuarios</p>
                </div>

                <!-- Paginación -->
                <div x-show="pagination.total_pages > 1" class="px-4 py-3 bg-gray-50 dark:bg-slate-800 border-t border-gray-200 dark:border-slate-700 flex items-center justify-between">
                    <div class="text-sm text-gray-500 dark:text-slate-400">
                        Mostrando <span x-text="((pagination.page - 1) * pagination.per_page) + 1"></span>
                        - <span x-text="Math.min(pagination.page * pagination.per_page, pagination.total)"></span>
                        de <span x-text="pagination.total"></span> usuarios
                    </div>
                    <div class="flex gap-2">
                        <button @click="goToPage(pagination.page - 1)" :disabled="pagination.page <= 1"
                            class="px-3 py-1 rounded border border-gray-300 dark:border-slate-600 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-100 dark:hover:bg-slate-700">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <span class="px-3 py-1 text-gray-700 dark:text-slate-300">
                            <span x-text="pagination.page"></span> / <span x-text="pagination.total_pages"></span>
                        </span>
                        <button @click="goToPage(pagination.page + 1)" :disabled="pagination.page >= pagination.total_pages"
                            class="px-3 py-1 rounded border border-gray-300 dark:border-slate-600 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-100 dark:hover:bg-slate-700">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Crear/Editar -->
    <div x-show="showModal" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0">

        <div @click.away="closeModal()" x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
            class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">

            <!-- Header -->
            <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between sticky top-0 bg-white dark:bg-slate-900">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">
                    <span x-text="modalMode === 'create' ? 'Nuevo Usuario' : 'Editar Usuario'"></span>
                </h2>
                <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-slate-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <!-- Form -->
            <form @submit.prevent="saveUser()">
                <div class="p-6 space-y-4">
                    <!-- Usuario -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                            Usuario <span class="text-red-500">*</span>
                        </label>
                        <input type="text" x-model="form.login" :readonly="modalMode === 'edit'"
                            :class="modalMode === 'edit' ? 'bg-gray-100 dark:bg-slate-800 cursor-not-allowed' : ''"
                            class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 focus:ring-2 focus:ring-blue-500"
                            required>
                        <p x-show="errors.login" class="text-red-500 text-sm mt-1" x-text="errors.login"></p>
                    </div>

                    <!-- Nombre -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                            Nombre Completo
                        </label>
                        <input type="text" x-model="form.name"
                            class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- Email -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                            Email
                        </label>
                        <input type="email" x-model="form.email"
                            class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                        <p x-show="errors.email" class="text-red-500 text-sm mt-1" x-text="errors.email"></p>
                    </div>

                    <!-- Contraseña (solo crear) -->
                    <div x-show="modalMode === 'create'">
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                            Contraseña <span class="text-red-500">*</span>
                        </label>
                        <input type="password" x-model="form.password"
                            class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 focus:ring-2 focus:ring-blue-500"
                            :required="modalMode === 'create'" minlength="6">
                        <p class="text-gray-500 dark:text-slate-400 text-xs mt-1">Mínimo 6 caracteres</p>
                        <p x-show="errors.password" class="text-red-500 text-sm mt-1" x-text="errors.password"></p>
                    </div>

                    <!-- Empresa -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                            Empresa
                        </label>
                        <select x-model="form.id_empresa"
                            class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                            <option value="">Sin empresa asignada</option>
                            <?php foreach ($empresas as $e): ?>
                                <option value="<?php echo $e['id_empresa']; ?>"><?php echo h($e['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Grupo -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                            Grupo/Rol
                        </label>
                        <select x-model="form.id_grupo"
                            class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                            <option value="">Sin grupo</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?php echo $g['id_grupo']; ?>"><?php echo h($g['description']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <!-- Estado -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                                Estado
                            </label>
                            <select x-model="form.active"
                                class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                                <option value="Y">Activo</option>
                                <option value="N">Inactivo</option>
                            </select>
                        </div>

                        <!-- Admin -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                                Administrador
                            </label>
                            <select x-model="form.priv_admin"
                                class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 focus:ring-2 focus:ring-blue-500">
                                <option value="N">No</option>
                                <option value="Y">Sí</option>
                            </select>
                        </div>
                    </div>

                    <!-- Error general -->
                    <div x-show="errorMessage" class="bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 p-3 rounded-lg">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span x-text="errorMessage"></span>
                    </div>
                </div>

                <!-- Footer -->
                <div class="px-6 py-4 bg-gray-50 dark:bg-slate-800 border-t border-gray-200 dark:border-slate-700 flex justify-end gap-3">
                    <button type="button" @click="closeModal()"
                        class="px-4 py-2 text-gray-600 dark:text-slate-400 hover:text-gray-800 dark:hover:text-slate-200">
                        Cancelar
                    </button>
                    <button type="submit" :disabled="saving"
                        class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg disabled:opacity-50 flex items-center gap-2">
                        <i x-show="saving" class="fas fa-spinner fa-spin"></i>
                        <span x-text="saving ? 'Guardando...' : 'Guardar'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Cambiar Contraseña -->
    <div x-show="showPasswordModal" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">

        <div @click.away="showPasswordModal = false"
            class="bg-white dark:bg-slate-900 rounded-xl shadow-2xl w-full max-w-sm">

            <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">
                    <i class="fas fa-key mr-2 text-amber-500"></i>Cambiar Contraseña
                </h2>
                <p class="text-sm text-gray-500 dark:text-slate-400 mt-1" x-text="'Usuario: ' + (selectedUser?.login || '')"></p>
            </div>

            <form @submit.prevent="changePassword()">
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                            Nueva Contraseña
                        </label>
                        <input type="password" x-model="newPassword"
                            class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 focus:ring-2 focus:ring-blue-500"
                            required minlength="6">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1">
                            Confirmar Contraseña
                        </label>
                        <input type="password" x-model="confirmPassword"
                            class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-slate-600 focus:ring-2 focus:ring-blue-500"
                            required minlength="6">
                    </div>
                    <p x-show="passwordError" class="text-red-500 text-sm" x-text="passwordError"></p>
                </div>

                <div class="px-6 py-4 bg-gray-50 dark:bg-slate-800 border-t border-gray-200 dark:border-slate-700 flex justify-end gap-3">
                    <button type="button" @click="showPasswordModal = false"
                        class="px-4 py-2 text-gray-600 dark:text-slate-400">Cancelar</button>
                    <button type="submit" :disabled="saving"
                        class="px-6 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-lg disabled:opacity-50">
                        <span x-text="saving ? 'Guardando...' : 'Cambiar'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast -->
    <div x-show="toast.show" x-cloak
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-2"
        :class="toast.type === 'success' ? 'bg-green-500' : 'bg-red-500'"
        class="fixed bottom-4 right-4 text-white px-6 py-3 rounded-lg shadow-lg flex items-center gap-3 z-50">
        <i :class="toast.type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle'"></i>
        <span x-text="toast.message"></span>
    </div>

    <script>
        function usersApp() {
            return {
                users: [],
                loading: true,
                saving: false,
                showModal: false,
                showPasswordModal: false,
                modalMode: 'create',
                selectedUser: null,
                csrfToken: document.querySelector('meta[name="csrf-token"]').content,

                filters: {
                    search: '',
                    status: '',
                    id_empresa: ''
                },

                pagination: {
                    page: 1,
                    per_page: 20,
                    total: 0,
                    total_pages: 0
                },

                form: {
                    login: '',
                    name: '',
                    email: '',
                    password: '',
                    id_empresa: '',
                    id_grupo: '',
                    active: 'Y',
                    priv_admin: 'N'
                },

                errors: {},
                errorMessage: '',

                newPassword: '',
                confirmPassword: '',
                passwordError: '',

                toast: {
                    show: false,
                    message: '',
                    type: 'success'
                },

                init() {
                    this.loadUsers();
                },

                async loadUsers() {
                    this.loading = true;
                    try {
                        const params = new URLSearchParams({
                            action: 'list',
                            page: this.pagination.page,
                            per_page: this.pagination.per_page,
                            search: this.filters.search,
                            status: this.filters.status,
                            id_empresa: this.filters.id_empresa
                        });

                        const response = await fetch(`api/v1/users.php?${params}`);
                        const result = await response.json();

                        if (result.success) {
                            this.users = result.data;
                            this.pagination = result.pagination;
                        } else {
                            this.showToast(result.error || 'Error cargando usuarios', 'error');
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        this.showToast('Error de conexión', 'error');
                    } finally {
                        this.loading = false;
                    }
                },

                goToPage(page) {
                    if (page >= 1 && page <= this.pagination.total_pages) {
                        this.pagination.page = page;
                        this.loadUsers();
                    }
                },

                openModal(mode, user = null) {
                    this.modalMode = mode;
                    this.errors = {};
                    this.errorMessage = '';

                    if (mode === 'edit' && user) {
                        this.selectedUser = user;
                        this.form = {
                            login: user.login,
                            name: user.name || '',
                            email: user.email || '',
                            password: '',
                            id_empresa: user.id_empresa || '',
                            id_grupo: user.id_grupo || '',
                            active: user.active,
                            priv_admin: user.priv_admin
                        };
                    } else {
                        this.selectedUser = null;
                        this.form = {
                            login: '',
                            name: '',
                            email: '',
                            password: '',
                            id_empresa: '',
                            id_grupo: '',
                            active: 'Y',
                            priv_admin: 'N'
                        };
                    }

                    this.showModal = true;
                },

                closeModal() {
                    this.showModal = false;
                    this.selectedUser = null;
                },

                async saveUser() {
                    this.saving = true;
                    this.errors = {};
                    this.errorMessage = '';

                    try {
                        const formData = new FormData();
                        formData.append('csrf_token', this.csrfToken);

                        if (this.modalMode === 'create') {
                            formData.append('action', 'create');
                            Object.keys(this.form).forEach(key => {
                                formData.append(key, this.form[key]);
                            });

                            const response = await fetch('api/v1/users.php?action=create', {
                                method: 'POST',
                                body: formData
                            });
                            const result = await response.json();

                            if (result.success) {
                                this.showToast('Usuario creado correctamente', 'success');
                                this.closeModal();
                                this.loadUsers();
                            } else {
                                if (result.errors?.validation_errors) {
                                    this.errors = result.errors.validation_errors;
                                }
                                this.errorMessage = result.error || 'Error al crear usuario';
                            }
                        } else {
                            // Editar
                            const data = {
                                csrf_token: this.csrfToken,
                                id: this.selectedUser.id_login,
                                ...this.form
                            };

                            const response = await fetch('api/v1/users.php', {
                                method: 'PUT',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': this.csrfToken
                                },
                                body: JSON.stringify(data)
                            });
                            const result = await response.json();

                            if (result.success) {
                                this.showToast('Usuario actualizado correctamente', 'success');
                                this.closeModal();
                                this.loadUsers();
                            } else {
                                if (result.errors?.validation_errors) {
                                    this.errors = result.errors.validation_errors;
                                }
                                this.errorMessage = result.error || 'Error al actualizar usuario';
                            }
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        this.errorMessage = 'Error de conexión';
                    } finally {
                        this.saving = false;
                    }
                },

                async toggleStatus(user) {
                    const action = user.active === 'Y' ? 'desactivar' : 'activar';
                    if (!confirm(`¿Seguro que desea ${action} al usuario "${user.login}"?`)) {
                        return;
                    }

                    try {
                        if (user.active === 'Y') {
                            // Desactivar (DELETE soft)
                            const response = await fetch(`api/v1/users.php?id=${user.id_login}`, {
                                method: 'DELETE',
                                headers: {
                                    'X-CSRF-TOKEN': this.csrfToken
                                }
                            });
                            const result = await response.json();

                            if (result.success) {
                                this.showToast('Usuario desactivado', 'success');
                                this.loadUsers();
                            } else {
                                this.showToast(result.error || 'Error', 'error');
                            }
                        } else {
                            // Activar
                            const formData = new FormData();
                            formData.append('csrf_token', this.csrfToken);
                            formData.append('id', user.id_login);

                            const response = await fetch('api/v1/users.php?action=activate', {
                                method: 'POST',
                                body: formData
                            });
                            const result = await response.json();

                            if (result.success) {
                                this.showToast('Usuario activado', 'success');
                                this.loadUsers();
                            } else {
                                this.showToast(result.error || 'Error', 'error');
                            }
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        this.showToast('Error de conexión', 'error');
                    }
                },

                openPasswordModal(user) {
                    this.selectedUser = user;
                    this.newPassword = '';
                    this.confirmPassword = '';
                    this.passwordError = '';
                    this.showPasswordModal = true;
                },

                async changePassword() {
                    if (this.newPassword !== this.confirmPassword) {
                        this.passwordError = 'Las contraseñas no coinciden';
                        return;
                    }

                    if (this.newPassword.length < 6) {
                        this.passwordError = 'La contraseña debe tener al menos 6 caracteres';
                        return;
                    }

                    this.saving = true;
                    this.passwordError = '';

                    try {
                        const formData = new FormData();
                        formData.append('csrf_token', this.csrfToken);
                        formData.append('id', this.selectedUser.id_login);
                        formData.append('new_password', this.newPassword);

                        const response = await fetch('api/v1/users.php?action=change-password', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();

                        if (result.success) {
                            this.showToast('Contraseña actualizada', 'success');
                            this.showPasswordModal = false;
                        } else {
                            this.passwordError = result.error || 'Error al cambiar contraseña';
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        this.passwordError = 'Error de conexión';
                    } finally {
                        this.saving = false;
                    }
                },

                showToast(message, type = 'success') {
                    this.toast = {
                        show: true,
                        message,
                        type
                    };
                    setTimeout(() => {
                        this.toast.show = false;
                    }, 3000);
                }
            };
        }
    </script>
</body>

</html>