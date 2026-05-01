<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
Permission::requireAccess('app_grid_estacion');
$pdo = Database::getSessionEmpresaConnection();
$id_usuario = Session::getIdLogin();

$cierrePrevio = null;
try {
    $stmt = $pdo->query("SELECT * FROM estacion_cierre_playa WHERE DATE(fecha) = CURDATE() AND estado = 'cerrado'");
    $cierrePrevio = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

try {
    // Obtener turnos del día
    $stmt = $pdo->query("SELECT id, id_usuario, hora_apertura, hora_cierre, efectivo_apertura, efectivo_cierre, estado FROM estacion_turnos WHERE DATE(fecha_turno) = CURDATE() ORDER BY hora_apertura");
    $turnos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Obtener despachos del día
    $stmt = $pdo->query("SELECT ed.id, ed.id_combustible, ed.litros, ed.monto_total, ec.nombre AS combustible FROM estacion_despachos ed JOIN estacion_combustibles ec ON ed.id_combustible = ec.id WHERE DATE(ed.fecha_hora) = CURDATE()");
    $despachos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Agrupar por combustible
    $despachosPorCombustible = [];
    foreach ($despachos as $d) {
        if (!isset($despachosPorCombustible[$d['id_combustible']])) {
            $despachosPorCombustible[$d['id_combustible']] = [
                'nombre' => $d['combustible'],
                'litros' => 0,
                'monto' => 0
            ];
        }
        $despachosPorCombustible[$d['id_combustible']]['litros'] += (float)$d['litros'];
        $despachosPorCombustible[$d['id_combustible']]['monto'] += (float)$d['monto_total'];
    }

    // Lecturas de tanques del día
    $stmt = $pdo->query("SELECT id_tanque, litros_medidos, tipo FROM estacion_lecturas_tanque WHERE DATE(fecha_hora) = CURDATE() ORDER BY fecha_hora");
    $lecturas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totalLitrosVendidos = array_sum(array_column($despachos, 'litros'));
    $totalMontoVendido = array_sum(array_column($despachos, 'monto_total'));
} catch (Exception $e) {
    $turnos = [];
    $despachosPorCombustible = [];
    $totalLitrosVendidos = 0;
    $totalMontoVendido = 0;
}
?><!DOCTYPE html>
<html lang="es" class="dark-mode">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cierre de Playa - Estación</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/public/assets/css/dark-mode.css">
</head>
<body class="bg-gray-50">
    <div x-data="cierresApp()" class="min-h-screen">
        <div class="bg-white shadow">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <h1 class="text-3xl font-bold text-gray-900">
                    <i class="fas fa-check-circle text-indigo-500 mr-2"></i> Cierre de Playa
                </h1>
                <p class="text-gray-600 mt-1">Fecha: <span class="font-medium"><?= date('d/m/Y') ?></span></p>
            </div>
        </div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Resumen -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <p class="text-gray-600 text-sm">Turnos</p>
                    <p class="text-3xl font-bold"><?= count($turnos) ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <p class="text-gray-600 text-sm">Total Litros</p>
                    <p class="text-3xl font-bold"><?= number_format($totalLitrosVendidos, 2) ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <p class="text-gray-600 text-sm">Total Ingresos</p>
                    <p class="text-3xl font-bold text-green-600">₲ <?= number_format($totalMontoVendido, 0) ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <p class="text-gray-600 text-sm">Estado</p>
                    <p class="text-3xl font-bold text-blue-600"><?= count(array_filter($turnos, fn($t) => $t['estado'] === 'abierto')) ? 'Abierto' : 'Cerrado' ?></p>
                </div>
            </div>

            <!-- Despachos por combustible -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <h2 class="text-xl font-bold mb-4">Ventas por Combustible</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($despachosPorCombustible as $comb): ?>
                    <div class="border rounded p-4">
                        <h3 class="font-bold text-lg"><?= htmlspecialchars($comb['nombre']) ?></h3>
                        <div class="mt-3 space-y-1 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Litros:</span>
                                <span class="font-medium"><?= number_format($comb['litros'], 3) ?> L</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Total:</span>
                                <span class="font-bold text-green-600">₲ <?= number_format($comb['monto'], 0) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Turnos -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="px-6 py-4 border-b">
                    <h2 class="text-xl font-bold">Turnos del Día</h2>
                </div>
                <table class="w-full">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-6 py-3 text-left text-sm font-semibold">Usuario</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold">Apertura</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold">Cierre</th>
                            <th class="px-6 py-3 text-right text-sm font-semibold">Efectivo Apertura</th>
                            <th class="px-6 py-3 text-right text-sm font-semibold">Efectivo Cierre</th>
                            <th class="px-6 py-3 text-left text-sm font-semibold">Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($turnos as $t): ?>
                        <tr class="border-t hover:bg-gray-50">
                            <td class="px-6 py-3 font-medium">Usuario #<?= $t['id_usuario'] ?></td>
                            <td class="px-6 py-3 text-sm"><?= date('H:i', strtotime($t['hora_apertura'])) ?></td>
                            <td class="px-6 py-3 text-sm"><?= $t['hora_cierre'] ? date('H:i', strtotime($t['hora_cierre'])) : '—' ?></td>
                            <td class="px-6 py-3 text-right">₲ <?= number_format($t['efectivo_apertura'], 0) ?></td>
                            <td class="px-6 py-3 text-right">₲ <?= $t['efectivo_cierre'] ? number_format($t['efectivo_cierre'], 0) : '—' ?></td>
                            <td class="px-6 py-3">
                                <span class="<?= $t['estado'] === 'cerrado' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800' ?> px-3 py-1 rounded-full text-sm font-medium">
                                    <?= ucfirst($t['estado']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Botón Cerrar Playa -->
            <div class="mt-8 flex justify-end">
                <template x-if="<?= $cierrePrevio ? 'true' : 'false' ?>">
                    <div class="text-green-600 font-semibold">✓ Playa cerrada el <?= $cierrePrevio ? date('H:i', strtotime($cierrePrevio['hora_cierre'])) : '' ?></div>
                </template>
                <template x-if="<?= !$cierrePrevio ? 'true' : 'false' ?>">
                    <button @click="ejecutarCierre()" :disabled="procesando" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg flex items-center gap-2 disabled:opacity-50">
                        <i class="fas fa-lock" :class="procesando ? 'animate-spin' : ''"></i> <span x-text="procesando ? 'Procesando...' : 'Ejecutar Cierre de Playa'"></span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    <script>
        function cierresApp() {
            return {
                procesando: false,

                async ejecutarCierre() {
                    if (!confirm('¿Cerrar la playa? No se podrán agregar más despachos.')) return;

                    this.procesando = true;
                    try {
                        const res = await fetch('api/ejecutar.php', { method: 'POST' });
                        const data = await res.json();
                        if (data.ok) {
                            alert('Cierre de playa ejecutado correctamente');
                            location.reload();
                        } else {
                            alert('Error: ' + (data.error || 'No se pudo ejecutar el cierre'));
                        }
                    } catch (e) {
                        alert('Error: ' + e.message);
                    } finally {
                        this.procesando = false;
                    }
                }
            }
        }
    </script>
</body>
</html>
