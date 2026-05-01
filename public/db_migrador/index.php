<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../src/Modules/Empresas/SuscripcionController.php';
Session::requireLogin('/public/login.php');

function dbMigradorEmpresaAsignada(int $idEmpresa): bool
{
    $appsEmpresa = SuscripcionController::getAppsEmpresa($idEmpresa);
    if (($appsEmpresa['success'] ?? false) !== true) {
        return false;
    }
    foreach (($appsEmpresa['data'] ?? []) as $app) {
        if ((string)($app['codigo'] ?? '') === 'db_migrador') {
            return true;
        }
    }
    return false;
}

$idEmpresa = (int)Session::getIdEmpresa();
$tieneAppAsignada = dbMigradorEmpresaAsignada($idEmpresa);
$tienePermiso = Permission::hasAccess('app_grid_db_migrador');

if (!$tieneAppAsignada || !$tienePermiso) {
    http_response_code(403);
    echo 'Acceso restringido. App no asignada o sin permiso.';
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migrador DB - SistemaX</title>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="bg-slate-100 dark:bg-slate-900 text-slate-800 dark:text-slate-100 min-h-screen">
<div x-data="dbMigratorApp()" x-init="init()" class="max-w-7xl mx-auto p-4 md:p-6 space-y-4">
    <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-4 md:p-5">
        <div class="flex items-center justify-between gap-3 mb-4">
            <div class="flex items-center gap-3">
                <div>
                    <h1 class="text-xl font-bold text-slate-800 dark:text-white">Migrador de Base de Datos</h1>
                    <p class="text-sm text-slate-500 dark:text-slate-400">Origen y destino tipo Navicat: estructura + datos por tabla</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button @click="openServerModal()" class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-800 text-white font-semibold">Servidores remotos</button>
                <button @click="startMigration()" :disabled="migrationProgress.running" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 disabled:bg-indigo-400 disabled:cursor-not-allowed text-white font-semibold">Iniciar proceso</button>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="border border-slate-200 dark:border-slate-700 rounded-xl p-3">
                <h3 class="font-semibold text-slate-800 dark:text-white mb-3">Servidor Origen</h3>
                <div class="mb-2">
                    <select x-model="selectedSourceServerId" @change="applySelectedServer('source')" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 text-sm">
                        <option value="">Seleccionar servidor guardado (origen)</option>
                        <template x-for="s in servers.filter(x => Number(x.activo) === 1)" :key="'srcsrv_'+s.id_server">
                            <option :value="String(s.id_server)" x-text="`${s.nombre} (${s.host}:${s.port})`"></option>
                        </template>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <input x-model="source.host" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 text-sm" placeholder="Host">
                    <input x-model.number="source.port" type="number" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 text-sm" placeholder="Puerto">
                    <input x-model="source.user" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 text-sm" placeholder="Usuario">
                    <div class="relative">
                        <input x-model="source.password" :type="showSourcePassword ? 'text' : 'password'" class="w-full pr-16 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 text-sm" placeholder="Password">
                        <button type="button" @click="showSourcePassword = !showSourcePassword" class="absolute right-2 top-1/2 -translate-y-1/2 text-xs px-2 py-1 rounded-md bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-300 dark:hover:bg-slate-600" x-text="showSourcePassword ? 'Ocultar' : 'Ver'"></button>
                    </div>
                </div>
                <div class="mt-2 flex gap-2">
                    <button @click="testConnection('source')" class="px-3 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm">Probar conexión</button>
                    <button @click="loadDatabases('source')" class="px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-800 text-white text-sm">Cargar DBs</button>
                </div>
                <select x-model="source.database" @change="loadSourceTables()" class="mt-2 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 text-sm">
                    <option value="">Seleccionar base origen</option>
                    <template x-for="db in source.databases" :key="'sdb_'+db">
                        <option :value="db" x-text="db"></option>
                    </template>
                </select>
            </div>

            <div class="border border-slate-200 dark:border-slate-700 rounded-xl p-3">
                <h3 class="font-semibold text-slate-800 dark:text-white mb-3">Servidor Destino</h3>
                <div class="mb-2">
                    <select x-model="selectedDestinationServerId" @change="applySelectedServer('destination')" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 text-sm">
                        <option value="">Seleccionar servidor guardado (destino)</option>
                        <template x-for="s in servers.filter(x => Number(x.activo) === 1)" :key="'dstsrv_'+s.id_server">
                            <option :value="String(s.id_server)" x-text="`${s.nombre} (${s.host}:${s.port})`"></option>
                        </template>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <input x-model="destination.host" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 text-sm" placeholder="Host">
                    <input x-model.number="destination.port" type="number" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 text-sm" placeholder="Puerto">
                    <input x-model="destination.user" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 text-sm" placeholder="Usuario">
                    <div class="relative">
                        <input x-model="destination.password" :type="showDestinationPassword ? 'text' : 'password'" class="w-full pr-16 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500 text-sm" placeholder="Password">
                        <button type="button" @click="showDestinationPassword = !showDestinationPassword" class="absolute right-2 top-1/2 -translate-y-1/2 text-xs px-2 py-1 rounded-md bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-300 dark:hover:bg-slate-600" x-text="showDestinationPassword ? 'Ocultar' : 'Ver'"></button>
                    </div>
                </div>
                <div class="mt-2 flex gap-2">
                    <button @click="testConnection('destination')" class="px-3 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm">Probar conexión</button>
                    <button @click="loadDatabases('destination')" class="px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-800 text-white text-sm">Cargar DBs</button>
                </div>
                <select x-model="destination.database" class="mt-2 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 text-sm">
                    <option value="">Seleccionar base destino</option>
                    <template x-for="db in destination.databases" :key="'ddb_'+db">
                        <option :value="db" x-text="db"></option>
                    </template>
                </select>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-slate-800 dark:text-white">Tablas a migrar</h3>
                <div class="flex items-center gap-2">
                    <button @click="setAllTables(true)" class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs">Marcar todo</button>
                    <button @click="setAllTables(false)" class="px-3 py-1.5 rounded-lg bg-slate-600 hover:bg-slate-700 text-white text-xs">Desmarcar</button>
                </div>
            </div>
            <div class="max-h-80 overflow-auto border border-slate-200 dark:border-slate-700 rounded-lg">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-700/40 text-slate-600 dark:text-slate-300">
                    <tr>
                        <th class="px-3 py-2 text-left">Sel.</th>
                        <th class="px-3 py-2 text-left">Tabla</th>
                        <th class="px-3 py-2 text-right">Filas aprox</th>
                        <th class="px-3 py-2 text-right">KB</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <template x-for="t in tables" :key="'tb_'+t.name">
                        <tr class="text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700/20">
                            <td class="px-3 py-2"><input type="checkbox" x-model="t.selected" class="accent-indigo-600"></td>
                            <td class="px-3 py-2 font-mono" x-text="t.name"></td>
                            <td class="px-3 py-2 text-right" x-text="formatNumber(t.rows)"></td>
                            <td class="px-3 py-2 text-right" x-text="formatNumber(t.size_kb)"></td>
                        </tr>
                    </template>
                    <tr x-show="tables.length===0">
                        <td colspan="4" class="px-3 py-6 text-center text-slate-500">Sin tablas cargadas</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-4 space-y-2">
            <h3 class="font-semibold text-slate-800 dark:text-white mb-2">Opciones</h3>
            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200"><input type="checkbox" x-model="options.copy_structure" class="accent-indigo-600"> Sincronización de estructuras</label>
            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200"><input type="checkbox" x-model="options.copy_data" class="accent-indigo-600"> Transferencia de datos</label>
            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200"><input type="checkbox" x-model="options.drop_table" class="accent-indigo-600"> DROP TABLE antes</label>
            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200"><input type="checkbox" x-model="options.truncate_table" class="accent-indigo-600"> TRUNCATE antes de datos</label>
            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200"><input type="checkbox" x-model="options.create_destination_db" class="accent-indigo-600"> Crear DB destino si no existe</label>
            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-200"><input type="checkbox" x-model="options.stop_on_error" class="accent-indigo-600"> Detener en primer error</label>
            <label class="block text-sm text-slate-700 dark:text-slate-200">Modo inserción
                <select x-model="options.insert_mode" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 text-sm">
                    <option value="insert">INSERT</option>
                    <option value="insert_ignore">INSERT IGNORE</option>
                    <option value="replace">REPLACE</option>
                </select>
            </label>
            <label class="block text-sm text-slate-700 dark:text-slate-200">Batch size
                <input type="number" min="100" max="5000" step="100" x-model.number="options.batch_size" class="mt-1 w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-100 text-sm">
            </label>
        </div>
    </div>

    <div class="bg-slate-950 text-slate-100 rounded-2xl border border-slate-700 shadow-sm p-4">
        <div class="flex items-center justify-between mb-2">
            <h3 class="font-semibold">Terminal de migración</h3>
            <button @click="logs=[]" class="px-3 py-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-xs">Limpiar</button>
        </div>
        <div class="h-56 overflow-auto font-mono text-xs leading-relaxed whitespace-pre-wrap">
            <template x-if="logs.length===0"><div class="text-slate-500">Sin eventos todavía...</div></template>
            <template x-for="(line, idx) in logs" :key="'log_'+idx">
                <div x-text="line"></div>
            </template>
        </div>
    </div>

    <div x-show="modal.open" x-cloak x-transition.opacity class="fixed inset-0 z-[120] bg-slate-950/60 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
        <div @click.away="modal.open=false" class="w-full max-w-md max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-700/60 bg-slate-900/95 shadow-2xl">
            <div class="p-5 border-b border-slate-700/70">
                <h3 class="text-base font-semibold text-white" x-text="modal.title"></h3>
            </div>
            <div class="p-5">
                <p class="text-sm text-slate-200 whitespace-pre-line" x-text="modal.message"></p>
            </div>
            <div class="px-5 pb-5">
                <button @click="modal.open=false" class="w-full px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">Entendido</button>
            </div>
        </div>
    </div>

    <div x-show="migrationProgress.open" x-cloak x-transition.opacity class="fixed inset-0 z-[130] bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="w-full max-w-lg rounded-2xl border border-slate-700/60 bg-slate-900/95 shadow-2xl p-5">
            <h3 class="text-base font-semibold text-white mb-2">Migración en progreso</h3>
            <p class="text-sm text-slate-300 mb-4" x-text="migrationProgress.currentLabel"></p>

            <div class="w-full h-3 bg-slate-700 rounded-full overflow-hidden">
                <div class="h-full bg-gradient-to-r from-indigo-500 to-blue-500 transition-all duration-300" :style="`width: ${migrationProgress.percent}%`"></div>
            </div>

            <div class="mt-3 flex items-center justify-between text-xs text-slate-300">
                <span x-text="`${migrationProgress.current}/${migrationProgress.total} tablas`"></span>
                <span x-text="`${migrationProgress.percent}%`"></span>
            </div>
        </div>
    </div>

    <div x-show="serversModal.open" x-cloak x-transition.opacity class="fixed inset-0 z-[140] bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
        <div @click.away="serversModal.open=false" class="w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-700/60 bg-slate-900/95 shadow-2xl p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-white">Registrar servidores remotos</h3>
                <button @click="serversModal.open=false" class="px-3 py-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm">Cerrar</button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="space-y-2">
                    <h4 class="text-sm font-semibold text-slate-200">Nuevo / Editar servidor</h4>
                    <input x-model="serverForm.nombre" class="w-full px-3 py-2 rounded-lg border border-slate-600 bg-slate-900 text-slate-100 text-sm" placeholder="Nombre del servidor">
                    <div class="grid grid-cols-2 gap-2">
                        <input x-model="serverForm.host" class="px-3 py-2 rounded-lg border border-slate-600 bg-slate-900 text-slate-100 text-sm" placeholder="Host/IP">
                        <input x-model.number="serverForm.port" type="number" class="px-3 py-2 rounded-lg border border-slate-600 bg-slate-900 text-slate-100 text-sm" placeholder="Puerto">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <input x-model="serverForm.user" class="px-3 py-2 rounded-lg border border-slate-600 bg-slate-900 text-slate-100 text-sm" placeholder="Usuario">
                        <div class="relative">
                            <input x-model="serverForm.password" :type="serverForm.showPassword ? 'text' : 'password'" class="w-full pr-16 px-3 py-2 rounded-lg border border-slate-600 bg-slate-900 text-slate-100 text-sm" placeholder="Password (opcional)">
                            <button type="button" @click="serverForm.showPassword = !serverForm.showPassword" class="absolute right-2 top-1/2 -translate-y-1/2 text-xs px-2 py-1 rounded-md bg-slate-700 text-slate-200 hover:bg-slate-600" x-text="serverForm.showPassword ? 'Ocultar' : 'Ver'"></button>
                        </div>
                    </div>
                    <input x-model="serverForm.database_default" class="w-full px-3 py-2 rounded-lg border border-slate-600 bg-slate-900 text-slate-100 text-sm" placeholder="Base por defecto (opcional)">
                    <textarea x-model="serverForm.observacion" rows="2" class="w-full px-3 py-2 rounded-lg border border-slate-600 bg-slate-900 text-slate-100 text-sm" placeholder="Observación"></textarea>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-300"><input type="checkbox" x-model="serverForm.activo" class="accent-indigo-600"> Activo</label>
                    <div class="flex gap-2 pt-1">
                        <button @click="saveServer()" class="px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold">Guardar servidor</button>
                        <button @click="resetServerForm()" class="px-3 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white text-sm">Limpiar</button>
                    </div>
                </div>

                <div>
                    <h4 class="text-sm font-semibold text-slate-200 mb-2">Servidores registrados</h4>
                    <div class="max-h-[52vh] overflow-auto border border-slate-700 rounded-lg">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-800 text-slate-300">
                            <tr>
                                <th class="px-3 py-2 text-left">Nombre</th>
                                <th class="px-3 py-2 text-left">Host</th>
                                <th class="px-3 py-2 text-right">Acciones</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700">
                            <template x-for="s in servers" :key="'srv_'+s.id_server">
                                <tr class="text-slate-200">
                                    <td class="px-3 py-2">
                                        <div class="font-semibold" x-text="s.nombre"></div>
                                        <div class="text-xs text-slate-400" x-text="(s.user || '') + '@' + (s.host || '') + ':' + (s.port || 3306)"></div>
                                    </td>
                                    <td class="px-3 py-2 text-xs font-mono text-slate-300" x-text="s.database_default || '-'"></td>
                                    <td class="px-3 py-2 text-right">
                                        <div class="inline-flex items-center gap-1">
                                            <button @click="applyServerTo('source', s)" class="px-2 py-1 rounded bg-blue-700 hover:bg-blue-600 text-white text-xs">Origen</button>
                                            <button @click="applyServerTo('destination', s)" class="px-2 py-1 rounded bg-indigo-700 hover:bg-indigo-600 text-white text-xs">Destino</button>
                                            <button @click="editServer(s)" class="px-2 py-1 rounded bg-slate-700 hover:bg-slate-600 text-white text-xs">Editar</button>
                                            <button @click="deleteServer(s)" class="px-2 py-1 rounded bg-rose-700 hover:bg-rose-600 text-white text-xs">Borrar</button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr x-show="servers.length===0">
                                <td colspan="3" class="px-3 py-6 text-center text-slate-400">Sin servidores registrados</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function dbMigratorApp() {
    return {
        source: { host: '127.0.0.1', port: 3306, user: 'root', password: '', database: '', databases: [] },
        destination: { host: '127.0.0.1', port: 3306, user: 'root', password: '', database: '', databases: [] },
        tables: [],
        options: {
            copy_structure: false,
            copy_data: false,
            drop_table: false,
            truncate_table: false,
            create_destination_db: true,
            stop_on_error: true,
            insert_mode: 'insert_ignore',
            batch_size: 500
        },
        showSourcePassword: false,
        showDestinationPassword: false,
        logs: [],
        modal: { open: false, title: '', message: '' },
        serversModal: { open: false },
        servers: [],
        selectedSourceServerId: '',
        selectedDestinationServerId: '',
        serverForm: {
            id_server: 0,
            nombre: '',
            host: '',
            port: 3306,
            user: '',
            password: '',
            database_default: '',
            observacion: '',
            activo: true,
            showPassword: false
        },
        migrationProgress: {
            open: false,
            running: false,
            percent: 0,
            current: 0,
            total: 0,
            currentLabel: ''
        },
        tablesLoadedFrom: {
            host: '',
            port: 0,
            user: '',
            database: ''
        },

        init() {
            this.log('Módulo de migración listo.');
            this.loadServers();
        },
        resetServerForm() {
            this.serverForm = {
                id_server: 0,
                nombre: '',
                host: '',
                port: 3306,
                user: '',
                password: '',
                database_default: '',
                observacion: '',
                activo: true,
                showPassword: false
            };
        },
        openServerModal() {
            this.serversModal.open = true;
            this.loadServers();
        },
        showModal(title, message) {
            this.modal = { open: true, title: title || 'Aviso', message: message || '' };
        },
        log(text) {
            const ts = new Date().toLocaleTimeString('es-PY', { hour12: false });
            this.logs.push(`[${ts}] ${text}`);
            this.$nextTick(() => {
                const list = document.querySelector('.h-56.overflow-auto');
                if (list) list.scrollTop = list.scrollHeight;
            });
        },
        formatNumber(value) {
            return Number(value || 0).toLocaleString('es-PY');
        },
        normalizeHost(v) {
            return String(v || '').trim().toLowerCase();
        },
        normalizeUser(v) {
            return String(v || '').trim().toLowerCase();
        },
        sameServer(a, b) {
            return this.normalizeHost(a.host) === this.normalizeHost(b.host)
                && Number(a.port || 3306) === Number(b.port || 3306)
                && this.normalizeUser(a.user) === this.normalizeUser(b.user);
        },
        getSelectedTables() {
            return this.tables.filter(t => t.selected).map(t => t.name);
        },
        setAllTables(selected) {
            this.tables = this.tables.map(t => ({ ...t, selected: !!selected }));
        },
        async api(action, payload) {
            const res = await fetch('api/migrador.php?action=' + encodeURIComponent(action), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload || {})
            });
            const raw = await res.text();
            let data = null;
            try {
                data = raw ? JSON.parse(raw) : null;
            } catch (e) {
                throw new Error(raw || `HTTP ${res.status} sin JSON válido`);
            }
            if (!data) {
                throw new Error(`Respuesta vacía del servidor (HTTP ${res.status})`);
            }
            return data;
        },
        async loadServers() {
            try {
                const data = await this.api('list_servers', {});
                if (!data.ok) throw new Error(data.error || 'No se pudo listar servidores');
                this.servers = data.data || [];
            } catch (e) {
                this.log(`ERROR servidores: ${e.message}`);
            }
        },
        editServer(s) {
            this.serverForm = {
                id_server: Number(s.id_server || 0),
                nombre: s.nombre || '',
                host: s.host || '',
                port: Number(s.port || 3306),
                user: s.user || '',
                password: s.password || '',
                database_default: s.database_default || '',
                observacion: s.observacion || '',
                activo: Number(s.activo) === 1,
                showPassword: false
            };
        },
        applyServerTo(side, s) {
            const target = side === 'source' ? this.source : this.destination;
            target.host = s.host || '';
            target.port = Number(s.port || 3306);
            target.user = s.user || '';
            target.password = s.password || '';
            if (s.database_default) {
                target.database = s.database_default;
            } 
            this.showModal('Servidor aplicado', `Se cargó "${s.nombre}" en ${side === 'source' ? 'ORIGEN' : 'DESTINO'}.`);
        },
        applySelectedServer(side) {
            const id = side === 'source' ? this.selectedSourceServerId : this.selectedDestinationServerId;
            if (!id) return;
            const srv = this.servers.find(s => String(s.id_server) === String(id));
            if (!srv) return;
            this.applyServerTo(side, srv);
        },
        async saveServer() {
            try {
                const payload = {
                    id_server: this.serverForm.id_server || 0,
                    nombre: this.serverForm.nombre,
                    host: this.serverForm.host,
                    port: this.serverForm.port,
                    user: this.serverForm.user,
                    password: this.serverForm.password,
                    database_default: this.serverForm.database_default,
                    observacion: this.serverForm.observacion,
                    activo: this.serverForm.activo ? 1 : 0
                };
                const data = await this.api('save_server', payload);
                if (!data.ok) throw new Error(data.error || 'No se pudo guardar el servidor');
                await this.loadServers();
                this.resetServerForm();
                this.showModal('Servidor guardado', 'El servidor remoto fue registrado correctamente.');
            } catch (e) {
                this.showModal('Error al guardar', e.message || 'No se pudo guardar el servidor.');
            }
        },
        async deleteServer(s) {
            if (!confirm(`¿Eliminar servidor "${s.nombre}"?`)) return;
            try {
                const data = await this.api('delete_server', { id_server: s.id_server });
                if (!data.ok) throw new Error(data.error || 'No se pudo eliminar');
                await this.loadServers();
                this.showModal('Servidor eliminado', 'El servidor remoto fue eliminado.');
            } catch (e) {
                this.showModal('Error al eliminar', e.message || 'No se pudo eliminar el servidor.');
            }
        },
        async testConnection(side) {
            const cfg = side === 'source' ? this.source : this.destination;
            try {
                this.log(`Probando conexión ${side}...`);
                const data = await this.api('test_connection', { config: cfg });
                if (!data.ok) throw new Error(data.error || 'Conexión fallida');
                this.log(`${side}: conexión OK (${data.data.version})`);
                this.showModal('Conexión exitosa', `${side.toUpperCase()} conectado correctamente.\nHost: ${cfg.host}:${cfg.port}\nDB: ${data.data.database || '(sin DB seleccionada)'}`);
            } catch (e) {
                this.log(`${side}: ERROR ${e.message}`);
                this.showModal('Error de conexión', e.message || 'No se pudo conectar.');
            }
        },
        async loadDatabases(side) {
            const cfg = side === 'source' ? this.source : this.destination;
            try {
                this.log(`Cargando bases de ${side}...`);
                const data = await this.api('list_databases', { config: cfg });
                if (!data.ok) throw new Error(data.error || 'No se pudieron listar las bases');
                cfg.databases = data.data || [];
                this.log(`${side}: ${cfg.databases.length} bases encontradas.`);
            } catch (e) {
                this.log(`${side}: ERROR ${e.message}`);
                this.showModal('Error', e.message || 'No se pudieron listar bases.');
            }
        },
        async loadSourceTables() {
            if (!this.source.database) return;
            try {
                this.log(`Listando tablas de ${this.source.database}...`);
                const data = await this.api('list_tables', { config: this.source, database: this.source.database });
                if (!data.ok) throw new Error(data.error || 'No se pudieron cargar tablas');
                this.tables = (data.data || []).map(t => ({ ...t, selected: true }));
                this.tablesLoadedFrom = {
                    host: this.source.host || '',
                    port: Number(this.source.port || 3306),
                    user: this.source.user || '',
                    database: this.source.database || ''
                };
                this.log(`Tablas origen: ${this.tables.length}`);
            } catch (e) {
                this.log(`ERROR tablas: ${e.message}`);
                this.showModal('Error', e.message || 'No se pudieron listar tablas.');
            }
        },
        async startMigration() {
            if (!this.source.database || !this.destination.database) {
                this.showModal('Datos incompletos', 'Debe seleccionar base origen y base destino.');
                return;
            }
            if (
                this.sameServer(this.source, this.destination) &&
                String(this.source.database || '').trim() === String(this.destination.database || '').trim()
            ) {
                this.showModal('Configuración inválida', 'Origen y destino no pueden ser la misma base en el mismo servidor.');
                return;
            }
            if (
                !this.tablesLoadedFrom.database ||
                this.normalizeHost(this.tablesLoadedFrom.host) !== this.normalizeHost(this.source.host) ||
                Number(this.tablesLoadedFrom.port || 3306) !== Number(this.source.port || 3306) ||
                this.normalizeUser(this.tablesLoadedFrom.user) !== this.normalizeUser(this.source.user) ||
                String(this.tablesLoadedFrom.database || '') !== String(this.source.database || '')
            ) {
                this.showModal('Recargar tablas', 'Las tablas seleccionadas no corresponden al origen actual. Vuelva a cargar tablas del origen antes de iniciar.');
                return;
            }
            if (!this.options.copy_structure && !this.options.copy_data) {
                this.showModal('Opciones requeridas', 'Active al menos una opción: Sincronización de estructuras o Transferencia de datos.');
                return;
            }
            const selected = this.getSelectedTables();
            if (selected.length === 0) {
                this.showModal('Sin tablas', 'Seleccione al menos una tabla para migrar.');
                return;
            }

            this.log(`Iniciando migración de ${selected.length} tablas...`);
            this.migrationProgress.open = true;
            this.migrationProgress.running = true;
            this.migrationProgress.percent = 0;
            this.migrationProgress.current = 0;
            this.migrationProgress.total = selected.length;
            this.migrationProgress.currentLabel = 'Preparando migración...';

            const total = selected.length;
            const finalSummary = {
                tables_ok: 0,
                tables_error: 0,
                rows_total: 0,
                errors: []
            };

            try {
                for (let i = 0; i < selected.length; i++) {
                    const tableName = selected[i];
                    this.migrationProgress.current = i + 1;
                    this.migrationProgress.percent = Math.max(1, Math.round((i / total) * 100));
                    let dataOffset = 0;
                    const maxRowsPerRequest = Math.max(2000, Number(this.options.batch_size || 500) * 6);

                    try {
                        while (true) {
                            this.migrationProgress.currentLabel = dataOffset > 0
                                ? `Migrando tabla: ${tableName} (offset ${dataOffset})`
                                : `Migrando tabla: ${tableName}`;

                            const data = await this.api('migrate', {
                                source: this.source,
                                destination: this.destination,
                                source_db: this.source.database,
                                destination_db: this.destination.database,
                                tables: [tableName],
                                data_offset: dataOffset,
                                max_rows_per_request: maxRowsPerRequest,
                                options: this.options
                            });

                            if (Array.isArray(data.logs)) {
                                data.logs.forEach(line => this.logs.push(line));
                            }

                            if (!data.ok) {
                                throw new Error(data.error || `Error migrando ${tableName}`);
                            }

                            const p = data.progress || {};
                            const rowsCall = Number(
                                p.rows_migrated_call ?? data.summary?.rows_total ?? 0
                            );
                            if (rowsCall > 0) {
                                finalSummary.rows_total += rowsCall;
                            }

                            if (data.partial && p.done === false) {
                                const nextOffset = Number(p.next_offset ?? (dataOffset + rowsCall));
                                if (!Number.isFinite(nextOffset) || nextOffset < 0 || nextOffset === dataOffset) {
                                    throw new Error(`Offset inválido al continuar ${tableName}`);
                                }
                                dataOffset = nextOffset;
                                this.log(`Continuando ${tableName} desde offset ${dataOffset}...`);
                                continue;
                            }

                            const s = data.summary || {};
                            finalSummary.tables_ok += Number(s.tables_ok || 1);
                            finalSummary.tables_error += Number(s.tables_error || 0);
                            if (Array.isArray(s.errors) && s.errors.length) {
                                finalSummary.errors.push(...s.errors);
                            }
                            break;
                        }
                    } catch (tableErr) {
                        finalSummary.tables_error += 1;
                        finalSummary.errors.push(`${tableName}: ${tableErr.message || 'Error no especificado'}`);
                        this.log(`ERROR tabla ${tableName}: ${tableErr.message || 'Error no especificado'}`);
                        if (this.options.stop_on_error) {
                            break;
                        }
                    }

                    this.migrationProgress.percent = Math.round(((i + 1) / total) * 100);
                }

                this.migrationProgress.percent = 100;
                this.migrationProgress.currentLabel = 'Finalizando...';
                const msg = [
                    `Tablas OK: ${finalSummary.tables_ok}`,
                    `Tablas con error: ${finalSummary.tables_error}`,
                    `Filas migradas: ${finalSummary.rows_total}`,
                    (finalSummary.errors.length > 0) ? `Errores:\n- ${finalSummary.errors.join('\n- ')}` : ''
                ].filter(Boolean).join('\n');
                this.showModal('Migración finalizada', msg);
                this.log('Migración completada.');
            } catch (e) {
                this.log(`ERROR migración: ${e.message}`);
                this.showModal('Migración con error', e.message || 'No se pudo completar la migración.');
            } finally {
                this.migrationProgress.running = false;
                setTimeout(() => { this.migrationProgress.open = false; }, 400);
            }
        },
        
    };
}
</script>
</body>
</html>
