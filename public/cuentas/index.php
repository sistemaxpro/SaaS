<?php
/**
 * Modulo Cuentas - Frontend (tabla cuentas)
 */
require_once __DIR__ . '/../../config/bootstrap.php';
Session::start();

$id_empresa = $_SESSION['id_empresa'] ?? 169;
Permission::requireAccess('cuentas');
$permisos = Permission::getAppPermissions('cuentas');
?>
<!DOCTYPE html>
<html lang="es" class="scroll-smooth" :class="isDark ? 'dark' : ''" x-data="{ isDark: localStorage.getItem('theme') === 'dark' }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cuentas - SistemaX PRO</title>
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
        @keyframes modalIn { from { opacity:0; transform:scale(.95) translateY(10px);} to { opacity:1; transform:scale(1) translateY(0);} }
    </style>
</head>
<body class="bg-gray-50 dark:bg-slate-900 min-h-screen font-sans antialiased">
<script>window.__PERMISOS__ = <?= json_encode($permisos) ?>;</script>

<div x-data="cuentasApp()" x-init="init()" class="min-h-screen">
    <header class="bg-white dark:bg-slate-800 border-b border-gray-200 dark:border-slate-700 sticky top-0 z-30">
        <div class="max-w-[1400px] mx-auto px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <button onclick="try{if(typeof parent.cerrarApp==='function'){parent.cerrarApp();return;}}catch(e){} window.location.href='/public/menu/menu.php';"
                        class="w-10 h-10 rounded-lg bg-red-600/10 border border-red-500/40 flex items-center justify-center text-red-600 dark:text-red-400 hover:bg-red-600/20 active:scale-95 transition-all cursor-pointer"
                        title="Salir">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15m-3 0-3-3m0 0 3-3m-3 3H15" /></svg>
                </button>
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-white">Gestión de Cuentas</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Catálogo de cuentas para caja, cobros y pagos</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button x-show="permisos.priv_insert === 'Y'" @click="nuevaCuenta()" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-semibold flex items-center gap-2 transition-colors shadow-sm">
                    <i class="fas fa-plus text-xs"></i> Nueva Cuenta
                </button>
            </div>
        </div>
    </header>

    <main class="max-w-[1400px] mx-auto px-6 py-6">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Total</p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1" x-text="stats.total"></p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                <p class="text-xs font-medium text-green-600 dark:text-green-400 uppercase">Activas</p>
                <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-1" x-text="stats.activas"></p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                <p class="text-xs font-medium text-red-600 dark:text-red-400 uppercase">Inactivas</p>
                <p class="text-2xl font-bold text-red-600 dark:text-red-400 mt-1" x-text="stats.inactivas"></p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
                <p class="text-xs font-medium text-indigo-600 dark:text-indigo-400 uppercase">Fijas</p>
                <p class="text-2xl font-bold text-indigo-600 dark:text-indigo-400 mt-1" x-text="stats.fijas"></p>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4 mb-6">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1 min-w-[240px]">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" x-model.debounce.300ms="search" @input="page=1; loadCuentas()"
                               placeholder="Buscar por ID o nombre de cuenta..."
                               class="w-full pl-10 pr-4 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                    </div>
                </div>
                <select x-model="filtroEstado" @change="page=1; loadCuentas()" class="px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                    <option value="all">Todos</option>
                    <option value="1">Activas</option>
                    <option value="0">Inactivas</option>
                </select>
                <select x-model="filtroFijo" @change="page=1; loadCuentas()" class="px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                    <option value="all">Fijas y normales</option>
                    <option value="1">Solo fijas</option>
                    <option value="0">Solo normales</option>
                </select>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
            <div x-show="loading" class="flex items-center justify-center py-12">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
            </div>
            <div x-show="!loading" class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-slate-900/50 border-b border-gray-200 dark:border-slate-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Cuenta</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Fija</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Estado</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase w-44">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                        <template x-for="c in cuentas" :key="c.id">
                            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors">
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300" x-text="c.id"></td>
                                <td class="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white" x-text="c.cuenta"></td>
                                <td class="px-4 py-3 text-center">
                                    <span :class="Number(c.fijo) === 1 ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300' : 'bg-gray-100 text-gray-500 dark:bg-slate-700 dark:text-slate-300'"
                                          class="px-2 py-1 rounded-full text-xs font-medium" x-text="Number(c.fijo) === 1 ? 'Sí' : 'No'"></span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span :class="Number(c.estado) === 1 ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300'"
                                          class="px-2 py-1 rounded-full text-xs font-medium" x-text="Number(c.estado) === 1 ? 'Activa' : 'Inactiva'"></span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <button x-show="permisos.priv_update === 'Y'" @click="editarCuenta(c)" class="w-8 h-8 rounded-lg bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-900/20 dark:hover:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 flex items-center justify-center transition-colors" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button x-show="permisos.priv_delete === 'Y' && Number(c.fijo) !== 1" @click="toggleEstado(c)" class="px-3 h-8 rounded-lg text-xs font-semibold transition-colors"
                                                :class="Number(c.estado) === 1 ? 'bg-red-50 hover:bg-red-100 dark:bg-red-900/20 text-red-600 dark:text-red-400' : 'bg-green-50 hover:bg-green-100 dark:bg-green-900/20 text-green-600 dark:text-green-400'"
                                                x-text="Number(c.estado) === 1 ? 'Anular' : 'Activar'"></button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div x-show="!loading && cuentas.length === 0" class="py-12 text-center text-gray-500 dark:text-gray-400">
                <i class="fas fa-folder-open text-4xl mb-3"></i>
                <p>No se encontraron cuentas</p>
            </div>
        </div>

        <div x-show="totalPages > 1" class="flex items-center justify-between mt-4">
            <span class="text-sm text-gray-500 dark:text-gray-400" x-text="'Pág. ' + page + ' de ' + totalPages"></span>
            <div class="flex gap-2">
                <button @click="if(page>1){page--;loadCuentas();}" :disabled="page<=1" class="px-3 py-2 rounded-lg bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 text-sm disabled:opacity-40"><i class="fas fa-chevron-left"></i></button>
                <button @click="if(page<totalPages){page++;loadCuentas();}" :disabled="page>=totalPages" class="px-3 py-2 rounded-lg bg-white dark:bg-slate-800 border border-gray-300 dark:border-slate-600 text-sm disabled:opacity-40"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
    </main>

    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showModal=false">
        <div class="absolute inset-0 bg-black/50" @click="showModal=false"></div>
        <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-lg modal-enter">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 flex items-center justify-between">
                <h2 class="text-lg font-bold text-gray-900 dark:text-white" x-text="form.id ? 'Editar Cuenta' : 'Nueva Cuenta'"></h2>
                <button @click="showModal=false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-slate-700 flex items-center justify-center"><i class="fas fa-times"></i></button>
            </div>
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-300 mb-1">Nombre de la cuenta *</label>
                    <input type="text" x-model="form.cuenta" class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-900 text-gray-900 dark:text-white text-sm">
                </div>
                <div class="flex items-center gap-6">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="form.fijo" class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Cuenta fija</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="form.estado" class="w-4 h-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="text-sm text-gray-700 dark:text-gray-300">Activa</span>
                    </label>
                </div>
            </div>
            <div class="px-6 py-4 bg-gray-50 dark:bg-slate-900 border-t border-gray-200 dark:border-slate-700 flex items-center justify-end gap-3 rounded-b-2xl">
                <button @click="showModal=false" class="px-4 py-2.5 bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium">Cancelar</button>
                <button @click="guardarCuenta()" :disabled="saving" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-semibold transition-colors disabled:opacity-50 flex items-center gap-2">
                    <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                    <span x-text="saving ? 'Guardando...' : 'Guardar'"></span>
                </button>
            </div>
        </div>
    </div>

    <div x-show="toast.show" x-cloak class="fixed bottom-6 right-6 z-[100] px-5 py-3 rounded-xl shadow-lg text-white text-sm font-medium flex items-center gap-2"
         :class="toast.type === 'error' ? 'bg-red-600' : 'bg-emerald-600'">
        <i class="fas" :class="toast.type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'"></i>
        <span x-text="toast.msg"></span>
    </div>
</div>

<script>
function cuentasApp() {
    return {
        permisos: window.__PERMISOS__ || {},
        loading: true,
        saving: false,
        cuentas: [],
        stats: { total: 0, activas: 0, inactivas: 0, fijas: 0 },
        search: '',
        filtroEstado: 'all',
        filtroFijo: 'all',
        page: 1,
        totalPages: 1,
        showModal: false,
        form: { id: null, cuenta: '', fijo: false, estado: true },
        toast: { show: false, msg: '', type: 'ok' },

        init() {
            this.loadCuentas();
        },

        async loadCuentas() {
            this.loading = true;
            try {
                const params = new URLSearchParams({
                    search: this.search,
                    estado: this.filtroEstado,
                    fijo: this.filtroFijo,
                    page: this.page,
                    limit: 25
                });
                const res = await fetch('api/list.php?' + params.toString());
                const data = await res.json();
                if (data.ok) {
                    this.cuentas = data.data || [];
                    this.totalPages = Number(data.pages || 1);
                    this.stats = data.stats || this.stats;
                } else {
                    this.showToast(data.error || 'No se pudo cargar', 'error');
                }
            } catch (e) {
                this.showToast('Error de conexión', 'error');
            }
            this.loading = false;
        },

        nuevaCuenta() {
            this.form = { id: null, cuenta: '', fijo: false, estado: true };
            this.showModal = true;
        },

        editarCuenta(c) {
            this.form = {
                id: Number(c.id),
                cuenta: String(c.cuenta || ''),
                fijo: Number(c.fijo) === 1,
                estado: Number(c.estado) === 1
            };
            this.showModal = true;
        },

        async guardarCuenta() {
            if (!this.form.cuenta.trim()) {
                this.showToast('Debe ingresar el nombre de cuenta', 'error');
                return;
            }
            this.saving = true;
            try {
                const payload = {
                    id: this.form.id || 0,
                    cuenta: this.form.cuenta.trim(),
                    fijo: this.form.fijo ? 1 : 0,
                    estado: this.form.estado ? 1 : 0
                };
                const res = await fetch('api/guardar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.ok) {
                    this.showToast(data.msg || 'Guardado');
                    this.showModal = false;
                    this.loadCuentas();
                } else {
                    this.showToast(data.error || 'No se pudo guardar', 'error');
                }
            } catch (e) {
                this.showToast('Error de conexión', 'error');
            }
            this.saving = false;
        },

        async toggleEstado(c) {
            const actionText = Number(c.estado) === 1 ? 'anular' : 'activar';
            if (!confirm(`¿Desea ${actionText} la cuenta "${c.cuenta}"?`)) return;
            try {
                const res = await fetch('api/eliminar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: c.id })
                });
                const data = await res.json();
                if (data.ok) {
                    this.showToast(data.msg || 'Actualizado');
                    this.loadCuentas();
                } else {
                    this.showToast(data.error || 'No se pudo actualizar', 'error');
                }
            } catch (e) {
                this.showToast('Error de conexión', 'error');
            }
        },

        showToast(msg, type = 'ok') {
            this.toast = { show: true, msg, type };
            setTimeout(() => this.toast.show = false, 3000);
        }
    }
}
</script>
</body>
</html>
