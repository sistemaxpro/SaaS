<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
Permission::requireAccess('app_playero_estacion');
$pdo = Database::getSessionEmpresaConnection();
$id_usuario = Session::getIdLogin();

try {
    $stmt = $pdo->prepare("SELECT id FROM estacion_turnos WHERE id_usuario = ? AND DATE(fecha_turno) = CURDATE() AND estado = 'abierto'");
    $stmt->execute([$id_usuario]);
    $turnoActual = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT id, nombre, unidad_medida, precio_venta FROM estacion_combustibles WHERE activo = 'Y' ORDER BY nombre");
    $combustibles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->query("SELECT id, nombre FROM estacion_picos WHERE activo = 'Y' ORDER BY nombre");
    $picos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($turnoActual) {
        $stmt = $pdo->prepare("SELECT id, fecha_hora, litros, monto_total, id_combustible, id_pico, modo_registro FROM estacion_despachos WHERE id_turno = ? ORDER BY fecha_hora DESC");
        $stmt->execute([$turnoActual['id']]);
        $despachos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $despachos = [];
    }
} catch (Exception $e) {
    $turnoActual = null;
    $combustibles = [];
    $picos = [];
    $despachos = [];
}
?><!DOCTYPE html>
<html lang="es" class="dark-mode">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Despachos - Estación</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/public/assets/css/dark-mode.css">
</head>
<body class="bg-gray-50">
    <div x-data="despachosApp()" class="min-h-screen">
        <div class="bg-white shadow">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <h1 class="text-3xl font-bold text-gray-900">
                    <i class="fas fa-receipt text-green-500 mr-2"></i> Despachos
                </h1>
                <p class="text-gray-600 mt-1" x-text="turnoActual ? '✓ Turno abierto' : '✗ No hay turno abierto'"></p>
            </div>
        </div>

        <template x-if="turnoActual">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <!-- Botón Nuevo Despacho -->
                <div class="mb-8">
                    <button @click="showFormDespacho = true" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg flex items-center gap-2">
                        <i class="fas fa-plus"></i> Nuevo Despacho
                    </button>
                </div>

                <!-- Resumen del turno -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                    <div class="bg-white rounded-lg shadow p-6">
                        <p class="text-gray-600 text-sm">Total Despachos</p>
                        <p class="text-3xl font-bold" x-text="despachos.length"></p>
                    </div>
                    <div class="bg-white rounded-lg shadow p-6">
                        <p class="text-gray-600 text-sm">Litros Vendidos</p>
                        <p class="text-3xl font-bold" x-text="(despachos.reduce((a,b) => a + parseFloat(b.litros), 0)).toFixed(2)"></p>
                    </div>
                    <div class="bg-white rounded-lg shadow p-6">
                        <p class="text-gray-600 text-sm">Total Vendido</p>
                        <p class="text-3xl font-bold text-green-600">
                            ₲ <span x-text="(despachos.reduce((a,b) => a + parseFloat(b.monto_total), 0)).toLocaleString('es-PY', {minimumFractionDigits: 0})"></span>
                        </p>
                    </div>
                </div>

                <!-- Tabla de despachos -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-sm font-semibold">Hora</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold">Pico</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold">Combustible</th>
                                <th class="px-6 py-3 text-center text-sm font-semibold">Modo</th>
                                <th class="px-6 py-3 text-right text-sm font-semibold">Litros</th>
                                <th class="px-6 py-3 text-right text-sm font-semibold">Monto</th>
                                <th class="px-6 py-3 text-center text-sm font-semibold">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="d in despachos" :key="d.id">
                                <tr class="border-t hover:bg-gray-50">
                                    <td class="px-6 py-3 text-sm" x-text="d.fecha_hora"></td>
                                    <td class="px-6 py-3 text-sm font-medium">Pico #<span x-text="d.id_pico"></span></td>
                                    <td class="px-6 py-3 text-sm">Combustible <span x-text="d.id_combustible"></span></td>
                                    <td class="px-6 py-3 text-center">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold"
                                              :class="(d.modo_registro || 'manual') === 'automatico' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700'">
                                            <span x-text="(d.modo_registro || 'manual') === 'automatico' ? 'Automático' : 'Manual'"></span>
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-right font-medium" x-text="parseFloat(d.litros).toFixed(2)"></td>
                                    <td class="px-6 py-3 text-right text-green-600 font-bold">₲ <span x-text="parseFloat(d.monto_total).toLocaleString('es-PY', {minimumFractionDigits: 0})"></span></td>
                                    <td class="px-6 py-3 text-center">
                                        <button @click="facturar(d)" class="text-blue-600 hover:text-blue-800 mr-2">
                                            <i class="fas fa-file-invoice"></i>
                                        </button>
                                        <button @click="eliminar(d)" class="text-red-600 hover:text-red-800">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div v-if="despachos.length === 0" class="px-6 py-8 text-center text-gray-500">
                        Sin despachos aún
                    </div>
                </div>
            </div>
        </template>

        <template x-if="!turnoActual">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 text-center">
                <i class="fas fa-exclamation-circle text-5xl text-red-500 mb-4 block"></i>
                <h2 class="text-2xl font-bold text-gray-900">No hay turno abierto</h2>
                <p class="text-gray-600 mt-2">Debes abrir un turno antes de registrar despachos</p>
                <a href="/public/estacion-turnos/" class="mt-4 inline-block bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg">
                    <i class="fas fa-play-circle mr-2"></i> Ir a Mis Turnos
                </a>
            </div>
        </template>

        <!-- Modal: Nuevo Despacho -->
        <div x-show="showFormDespacho" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
            <div class="bg-white rounded-lg shadow-lg max-w-md w-full">
                <div class="px-6 py-4 border-b">
                    <h2 class="text-xl font-bold">Nuevo Despacho</h2>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <select x-model="form.modo_registro" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="manual">Manual</option>
                        <option value="automatico">Automático</option>
                    </select>
                    <select x-model="form.id_pico" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Seleccionar pico...</option>
                        <template x-for="p in picos">
                            <option :value="p.id" x-text="p.nombre"></option>
                        </template>
                    </select>
                    <select x-model="form.id_combustible" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Seleccionar combustible...</option>
                        <template x-for="c in combustibles">
                            <option :value="c.id" x-text="c.nombre"></option>
                        </template>
                    </select>
                    <input x-model="form.litros" type="number" step="0.01" placeholder="Litros" @change="calcularMonto()" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600 dark:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <input x-model="form.monto_total" type="number" step="0.01" placeholder="Monto (₲)" @change="calcularLitros()" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600 dark:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <input x-model="form.precio_unitario" type="number" step="0.01" placeholder="Precio unitario" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600 dark:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-3">
                    <button @click="showFormDespacho = false" class="px-4 py-2 border rounded hover:bg-gray-50">Cancelar</button>
                    <button @click="guardarDespacho()" class="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600">Guardar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function despachosApp() {
            return {
                turnoActual: <?= json_encode($turnoActual) ?>,
                despachos: <?= json_encode($despachos) ?>,
                combustibles: <?= json_encode($combustibles) ?>,
                picos: <?= json_encode($picos) ?>,
                showFormDespacho: false,
                form: { modo_registro: 'manual', id_pico: '', id_combustible: '', litros: 0, monto_total: 0, precio_unitario: 0 },

                calcularMonto() {
                    this.form.monto_total = (parseFloat(this.form.litros) * parseFloat(this.form.precio_unitario)).toFixed(2);
                },

                calcularLitros() {
                    this.form.litros = (parseFloat(this.form.monto_total) / parseFloat(this.form.precio_unitario)).toFixed(3);
                },

                async guardarDespacho() {
                    try {
                        const res = await fetch('api/guardar.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.form)
                        });
                        const d = await res.json();
                        if (d.ok) { this.showFormDespacho = false; location.reload(); } else alert('Error: ' + d.error);
                    } catch (e) { alert('Error: ' + e.message); }
                },

                async facturar(d) {
                    try {
                        const res = await fetch('api/facturar.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({id: d.id})
                        });
                        const data = await res.json();
                        if (data.ok) {
                            alert('Despacho facturado: ' + data.nro_comprobante);
                            location.reload();
                        } else {
                            alert('Error: ' + (data.error || 'No se pudo facturar'));
                        }
                    } catch (e) {
                        alert('Error: ' + e.message);
                    }
                },

                async eliminar(d) {
                    if (!confirm('¿Eliminar despacho?')) return;
                    try {
                        const res = await fetch('api/eliminar.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({id: d.id})
                        });
                        const data = await res.json();
                        if (data.ok) {
                            alert('Despacho eliminado');
                            location.reload();
                        } else {
                            alert('Error: ' + (data.error || 'No se pudo eliminar'));
                        }
                    } catch (e) {
                        alert('Error: ' + e.message);
                    }
                }
            }
        }
    </script>
</body>
</html>
