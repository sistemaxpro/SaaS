<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
Permission::requireAccess('app_playero_estacion');
$pdo = Database::getSessionEmpresaConnection();
$id_usuario = Session::getIdLogin();
$usuario = Session::get('usuario');

try {
    $stmt = $pdo->prepare("SELECT id, estado, fecha_turno, hora_apertura, hora_cierre, efectivo_apertura, efectivo_cierre FROM estacion_turnos WHERE id_usuario = ? ORDER BY fecha_turno DESC LIMIT 10");
    $stmt->execute([$id_usuario]);
    $turnos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("SELECT id, estado FROM estacion_turnos WHERE id_usuario = ? AND DATE(fecha_turno) = CURDATE() AND estado = 'abierto'");
    $stmt->execute([$id_usuario]);
    $turnoActual = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT id, nombre FROM estacion_picos WHERE activo = 'Y' ORDER BY nombre");
    $picos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $turnos = [];
    $turnoActual = null;
    $picos = [];
}
?><!DOCTYPE html>
<html lang="es" class="dark-mode">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Turnos - Estación</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/public/assets/css/dark-mode.css">
</head>
<body class="bg-gray-50">
    <div x-data="turnosApp()" class="min-h-screen">
        <div class="bg-white shadow">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <h1 class="text-3xl font-bold text-gray-900">
                    <i class="fas fa-clock text-purple-500 mr-2"></i> Mis Turnos
                </h1>
                <p class="text-gray-600 mt-1">Usuario: <span x-text="'<?= addslashes($usuario) ?>'"></span></p>
            </div>
        </div>

        <!-- Estado actual -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <template x-if="turnoActual">
                <div class="bg-green-50 border-2 border-green-200 rounded-lg p-6 mb-8">
                    <div class="flex justify-between items-start">
                        <div>
                            <h2 class="text-2xl font-bold text-green-900">Turno en progreso</h2>
                            <p class="text-green-700 mt-1"><i class="fas fa-check-circle"></i> Abierto desde <span x-text="turnoActual.hora_apertura"></span></p>
                        </div>
                        <button @click="cerrarTurno()" class="bg-red-500 hover:bg-red-600 text-white px-6 py-3 rounded-lg flex items-center gap-2">
                            <i class="fas fa-times-circle"></i> Cerrar Turno
                        </button>
                    </div>
                </div>
            </template>

            <template x-if="!turnoActual">
                <div class="bg-blue-50 border-2 border-blue-200 rounded-lg p-6 mb-8">
                    <div class="flex justify-between items-start">
                        <div>
                            <h2 class="text-2xl font-bold text-blue-900">No hay turno abierto</h2>
                            <p class="text-blue-700 mt-1"><i class="fas fa-info-circle"></i> Abre tu turno para comenzar a despachar</p>
                        </div>
                        <button @click="abrirTurno()" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg flex items-center gap-2">
                            <i class="fas fa-play-circle"></i> Abrir Turno
                        </button>
                    </div>
                </div>
            </template>

            <!-- Historial -->
            <div class="bg-white rounded-lg shadow-md">
                <div class="px-6 py-4 border-b">
                    <h3 class="text-xl font-bold">Historial de Turnos</h3>
                </div>
                <table class="w-full">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-6 py-3 text-left text-sm font-semibold">Fecha</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold">Hora Apertura</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold">Hora Cierre</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold">Efectivo Apertura</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="turno in turnos" :key="turno.id">
                            <tr class="border-t hover:bg-gray-50">
                                <td class="px-6 py-3" x-text="turno.fecha_turno"></td>
                                <td class="px-6 py-3" x-text="turno.hora_apertura"></td>
                                <td class="px-6 py-3" x-text="turno.hora_cierre || '-'"></td>
                                <td class="px-6 py-3 font-medium" x-text="'₲ ' + parseFloat(turno.efectivo_apertura).toLocaleString('es-PY')"></td>
                                <td class="px-6 py-3">
                                    <span :class="turno.estado === 'cerrado' ? 'bg-red-100 text-red-800' : turno.estado === 'conciliado' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'" class="px-3 py-1 rounded-full text-sm">
                                        <span x-text="turno.estado"></span>
                                    </span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal: Abrir Turno -->
        <div x-show="showAbrirTurno" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-lg max-w-md w-full mx-4">
                <div class="px-6 py-4 border-b">
                    <h2 class="text-xl font-bold">Abrir Turno</h2>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Efectivo de Apertura (₲)</label>
                        <input x-model="formAbrirTurno.efectivo_apertura" type="number" step="0.01" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600 dark:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="0">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Observaciones</label>
                        <textarea x-model="formAbrirTurno.observacion" class="w-full border rounded px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600 dark:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" rows="3" placeholder="Ej: Sin novedad"></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-3">
                    <button @click="showAbrirTurno = false" class="px-4 py-2 border rounded hover:bg-gray-50">Cancelar</button>
                    <button @click="guardarAbrirTurno()" class="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600">Abrir</button>
                </div>
            </div>
        </div>

        <!-- Modal: Cerrar Turno -->
        <div x-show="showCerrarTurno" x-transition class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg shadow-lg max-w-md w-full mx-4">
                <div class="px-6 py-4 border-b">
                    <h2 class="text-xl font-bold">Cerrar Turno</h2>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Efectivo Recaudado (₲)</label>
                        <input x-model="formCerrarTurno.efectivo_cierre" type="number" step="0.01" class="w-full border rounded px-3 py-2 dark:bg-gray-700 dark:text-white dark:border-gray-600 dark:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="0">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Observaciones</label>
                        <textarea x-model="formCerrarTurno.observacion" class="w-full border rounded px-3 py-2 text-sm dark:bg-gray-700 dark:text-white dark:border-gray-600 dark:placeholder-gray-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500" rows="3" placeholder="Ej: Sin incidentes"></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-3">
                    <button @click="showCerrarTurno = false" class="px-4 py-2 border rounded hover:bg-gray-50">Cancelar</button>
                    <button @click="guardarCerrarTurno()" class="px-4 py-2 bg-red-500 text-white rounded hover:bg-red-600">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function turnosApp() {
            return {
                turnoActual: <?= json_encode($turnoActual) ?>,
                turnos: <?= json_encode($turnos) ?>,
                showAbrirTurno: false,
                showCerrarTurno: false,
                formAbrirTurno: { efectivo_apertura: 0, observacion: '' },
                formCerrarTurno: { efectivo_cierre: 0, observacion: '' },

                abrirTurno() {
                    this.formAbrirTurno = { efectivo_apertura: 0, observacion: '' };
                    this.showAbrirTurno = true;
                },

                async guardarAbrirTurno() {
                    try {
                        const res = await fetch('api/abrir.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.formAbrirTurno)
                        });
                        const d = await res.json();
                        if (d.ok) location.reload(); else alert('Error: ' + d.error);
                    } catch (e) { alert('Error: ' + e.message); }
                },

                cerrarTurno() {
                    this.formCerrarTurno = { efectivo_cierre: 0, observacion: '' };
                    this.showCerrarTurno = true;
                },

                async guardarCerrarTurno() {
                    try {
                        const res = await fetch('api/cerrar.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify(this.formCerrarTurno)
                        });
                        const d = await res.json();
                        if (d.ok) location.reload(); else alert('Error: ' + d.error);
                    } catch (e) { alert('Error: ' + e.message); }
                }
            }
        }
    </script>
</body>
</html>
