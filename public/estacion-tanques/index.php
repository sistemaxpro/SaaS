<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
Permission::requireAccess('app_grid_estacion');

$pdo = Database::getSessionEmpresaConnection();

try {
    $stmt = $pdo->query("SELECT id, nombre, id_combustible, capacidad_litros, nivel_minimo_alerta, tipo_medicion, activo FROM estacion_tanques ORDER BY nombre");
    $tanques = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->query("SELECT id, nombre, unidad_medida FROM estacion_combustibles WHERE activo = 'Y' ORDER BY nombre");
    $combustibles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $tanques = [];
    $combustibles = [];
}
?><!DOCTYPE html>
<html lang="es" class="dark-mode">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tanques - Estación de Servicio</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/public/assets/css/dark-mode.css">
</head>
<body class="bg-gray-50">
    <div x-data="tanquesApp()" class="min-h-screen">
        <div class="bg-white shadow">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex justify-between items-center">
                    <h1 class="text-3xl font-bold text-gray-900">
                        <i class="fas fa-oil-can text-amber-500 mr-2"></i> Tanques de Almacenamiento
                    </h1>
                    <button @click="openNew()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg flex items-center gap-2">
                        <i class="fas fa-plus"></i> Nuevo Tanque
                    </button>
                </div>
            </div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <template x-for="tanque in tanques" :key="tanque.id">
                    <div class="bg-white rounded-lg shadow-md p-4 hover:shadow-lg transition">
                        <div class="flex justify-between items-start mb-3">
                            <div>
                                <h3 class="text-lg font-bold" x-text="tanque.nombre"></h3>
                                <p class="text-sm text-gray-500" x-text="tanque.tipo_medicion === 'sensor' ? 'Con Sensor' : 'Varillado Manual'"></p>
                            </div>
                            <span :class="tanque.activo === 'Y' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'" class="px-2 py-1 rounded text-xs font-medium">
                                <span x-text="tanque.activo === 'Y' ? 'Activo' : 'Inactivo'"></span>
                            </span>
                        </div>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Capacidad:</span>
                                <span class="font-medium"><span x-text="tanque.capacidad_litros"></span> L</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Nivel Mín. Alerta:</span>
                                <span class="font-medium"><span x-text="tanque.nivel_minimo_alerta"></span> L</span>
                            </div>
                        </div>
                        <div class="mt-4 flex gap-2">
                            <button @click="edit(tanque)" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded text-sm">
                                <i class="fas fa-edit mr-1"></i> Editar
                            </button>
                            <button @click="verLecturas(tanque)" class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-3 py-2 rounded text-sm">
                                <i class="fas fa-history mr-1"></i> Historial
                            </button>
                        </div>
                    </div>
                </template>
            </div>
            <div v-if="tanques.length === 0" class="text-center text-gray-500 py-8">
                <i class="fas fa-inbox text-4xl mb-4 block"></i>
                No hay tanques registrados
            </div>
        </div>

        <!-- Modal -->
        <div x-show="showForm" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-lg max-w-md w-full mx-4">
                <div class="px-6 py-4 border-b flex justify-between items-center">
                    <h2 class="text-xl font-bold" x-text="editingId ? 'Editar Tanque' : 'Nuevo Tanque'"></h2>
                    <button @click="showForm = false" class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <input x-model="form.nombre" placeholder="Nombre" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600 dark:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <select x-model="form.id_combustible" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Seleccionar combustible...</option>
                        <template x-for="c in combustibles">
                            <option :value="c.id" x-text="c.nombre"></option>
                        </template>
                    </select>
                    <input x-model="form.capacidad_litros" type="number" placeholder="Capacidad (L)" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600 dark:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <input x-model="form.nivel_minimo_alerta" type="number" placeholder="Nivel mínimo (L)" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600 dark:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <select x-model="form.tipo_medicion" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="manual">Varillado Manual</option>
                        <option value="sensor">Sensor Electrónico</option>
                    </select>
                    <div x-show="form.tipo_medicion === 'sensor'" class="space-y-2 bg-blue-50 dark:bg-gray-700 p-3 rounded border border-blue-200 dark:border-blue-400">
                        <input x-model="form.sensor_ip" placeholder="IP del sensor" class="w-full border rounded px-3 py-2 text-sm dark:bg-gray-600 dark:text-white dark:border-gray-500 dark:placeholder-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <input x-model="form.sensor_puerto" type="number" placeholder="Puerto" class="w-full border rounded px-3 py-2 text-sm dark:bg-gray-600 dark:text-white dark:border-gray-500 dark:placeholder-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-3">
                    <button @click="showForm = false" class="px-4 py-2 border rounded hover:bg-gray-50">Cancelar</button>
                    <button @click="save()" class="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600">Guardar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function tanquesApp() {
            return {
                tanques: <?= json_encode($tanques) ?>,
                combustibles: <?= json_encode($combustibles) ?>,
                showForm: false,
                editingId: null,
                form: {
                    nombre: '',
                    id_combustible: '',
                    capacidad_litros: '',
                    nivel_minimo_alerta: '',
                    tipo_medicion: 'manual',
                    sensor_ip: '',
                    sensor_puerto: ''
                },
                openNew() {
                    this.editingId = null;
                    this.form = { nombre: '', id_combustible: '', capacidad_litros: '', nivel_minimo_alerta: '', tipo_medicion: 'manual', sensor_ip: '', sensor_puerto: '' };
                    this.showForm = true;
                },
                edit(t) { this.editingId = t.id; this.form = {...t}; this.showForm = true; },
                async save() {
                    const res = await fetch('api/guardar.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({...this.form, id: this.editingId}) });
                    const d = await res.json();
                    if (d.ok) location.reload(); else alert('Error: ' + d.error);
                },
                verLecturas(t) { alert('Funcionalidad de lecturas próximamente'); }
            }
        }
    </script>
</body>
</html>
