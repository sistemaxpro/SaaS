<?php
require_once __DIR__ . '/../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
Permission::requireAccess('app_grid_estacion');
$pdo = Database::getSessionEmpresaConnection();

try {
    // Turnos activos
    $stmt = $pdo->query("SELECT COUNT(*) FROM estacion_turnos WHERE DATE(fecha_turno) = CURDATE() AND estado = 'abierto'");
    $turnosActivos = (int)$stmt->fetchColumn();

    // Despachos del día
    $stmt = $pdo->query("SELECT COUNT(*) FROM estacion_despachos WHERE DATE(fecha_hora) = CURDATE()");
    $despachosHoy = (int)$stmt->fetchColumn();

    // Total vendido
    $stmt = $pdo->query("SELECT SUM(monto_total) FROM estacion_despachos WHERE DATE(fecha_hora) = CURDATE()");
    $totalVendido = (float)($stmt->fetchColumn() ?? 0);

    // Nivel de tanques
    $stmt = $pdo->query("SELECT t.nombre, COUNT(p.id) as picos FROM estacion_tanques t LEFT JOIN estacion_picos p ON t.id = p.id_tanque WHERE t.activo = 'Y' GROUP BY t.id");
    $tanques = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Surtidores activos
    $stmt = $pdo->query("SELECT COUNT(*) FROM estacion_surtidores WHERE activo = 'Y'");
    $surtidoresActivos = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $turnosActivos = 0;
    $despachosHoy = 0;
    $totalVendido = 0;
    $tanques = [];
    $surtidoresActivos = 0;
}
?><!DOCTYPE html>
<html lang="es" class="dark-mode">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Estación de Servicio</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/public/assets/css/dark-mode.css">
</head>
<body class="bg-gray-50">
    <div class="min-h-screen">
        <!-- Encabezado -->
        <div class="bg-gradient-to-r from-orange-500 to-orange-600 text-white">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <h1 class="text-4xl font-bold flex items-center gap-3">
                    <i class="fas fa-gas-pump"></i> Estación de Servicio
                </h1>
                <p class="text-orange-100 mt-2">Control y monitoreo en tiempo real</p>
            </div>
        </div>

        <!-- KPIs -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <a href="/public/estacion-turnos/" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition cursor-pointer">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-600 text-sm">Turnos Activos</p>
                            <p class="text-4xl font-bold text-purple-600"><?= $turnosActivos ?></p>
                        </div>
                        <i class="fas fa-users text-5xl text-purple-200"></i>
                    </div>
                </a>

                <a href="/public/estacion-despachos/" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition cursor-pointer">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-600 text-sm">Despachos Hoy</p>
                            <p class="text-4xl font-bold text-green-600"><?= $despachosHoy ?></p>
                        </div>
                        <i class="fas fa-receipt text-5xl text-green-200"></i>
                    </div>
                </a>

                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-600 text-sm">Ingresos Hoy</p>
                            <p class="text-3xl font-bold text-blue-600">₲ <?= number_format($totalVendido, 0) ?></p>
                        </div>
                        <i class="fas fa-money-bill text-5xl text-blue-200"></i>
                    </div>
                </div>

                <a href="/public/estacion-surtidores/" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition cursor-pointer">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-600 text-sm">Surtidores</p>
                            <p class="text-4xl font-bold text-amber-600"><?= $surtidoresActivos ?></p>
                        </div>
                        <i class="fas fa-tint text-5xl text-amber-200"></i>
                    </div>
                </a>
            </div>

            <!-- Panel de Acciones Rápidas -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <a href="/public/estacion-turnos/" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition">
                    <div class="flex items-center gap-4">
                        <i class="fas fa-play-circle text-3xl text-green-500"></i>
                        <div>
                            <h3 class="font-bold text-lg">Abrir/Ver Turnos</h3>
                            <p class="text-gray-600 text-sm">Gestiona tus turnos de trabajo</p>
                        </div>
                    </div>
                </a>

                <a href="/public/estacion-despachos/" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition">
                    <div class="flex items-center gap-4">
                        <i class="fas fa-file-invoice text-3xl text-blue-500"></i>
                        <div>
                            <h3 class="font-bold text-lg">Registrar Despachos</h3>
                            <p class="text-gray-600 text-sm">Cada venta de combustible</p>
                        </div>
                    </div>
                </a>

                <a href="/public/estacion-cierres/" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition">
                    <div class="flex items-center gap-4">
                        <i class="fas fa-lock text-3xl text-red-500"></i>
                        <div>
                            <h3 class="font-bold text-lg">Cierre de Playa</h3>
                            <p class="text-gray-600 text-sm">Control cruzado del día</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Tanques -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-2xl font-bold mb-4 flex items-center gap-2">
                    <i class="fas fa-oil-can text-amber-500"></i> Tanques de Almacenamiento
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php if (count($tanques) > 0): ?>
                        <?php foreach ($tanques as $t): ?>
                        <div class="border rounded-lg p-4 hover:shadow-md transition">
                            <h3 class="font-bold text-lg"><?= htmlspecialchars($t['nombre']) ?></h3>
                            <div class="mt-3">
                                <div class="flex justify-between text-sm mb-2">
                                    <span class="text-gray-600">Picos conectados:</span>
                                    <span class="font-bold"><?= $t['picos'] ?></span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-blue-500 h-2 rounded-full" style="width: 65%"></div>
                                </div>
                                <p class="text-xs text-gray-600 mt-2">Nivel: 65%</p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <p class="text-gray-600 col-span-3">No hay tanques configurados</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
