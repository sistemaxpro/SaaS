<?php

/**
 * Página de Pago de Suscripción SaaS
 * Muestra el resumen de la factura y botón para pagar con Ueno
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/Modules/Empresas/SuscripcionController.php';

// Verificar sesión (opcional para esta página)
$isLoggedIn = Session::isLoggedIn();
$idEmpresaSesion = $isLoggedIn ? Session::getIdEmpresa() : 0;

// Obtener ID de suscripción
$idSuscripcion = (int)($_GET['id'] ?? 0);

if ($idSuscripcion <= 0) {
    die('ID de suscripción inválido');
}

// Obtener datos de la suscripción
try {
    $db = Database::getMasterConnection();
    
    $sql = "
        SELECT s.*, 
               e.empresa, e.ruc, e.email, e.logos,
               DATEDIFF(s.fecha_vencimiento, CURDATE()) as dias_restantes
        FROM saas_suscripcion s
        JOIN empresa e ON e.id_empresa = s.id_empresa
        WHERE s.id_suscripcion = ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$idSuscripcion]);
    $suscripcion = $stmt->fetch();
    
    if (!$suscripcion) {
        die('Suscripción no encontrada');
    }
    
    // Verificar que el usuario logueado pertenece a esta empresa (si está logueado)
    if ($isLoggedIn && $suscripcion['id_empresa'] != $idEmpresaSesion) {
        // Permitir acceso a empresa 169 (super admin)
        if ($idEmpresaSesion != 169) {
            die('No tiene permiso para ver esta suscripción');
        }
    }
    
    // Obtener items (apps)
    $stmtApps = $db->prepare("
        SELECT sa.*, a.icono, a.color
        FROM saas_suscripcion_apps sa
        JOIN saas_apps_catalogo a ON a.id_app = sa.id_app
        WHERE sa.id_suscripcion = ?
        ORDER BY sa.id
    ");
    $stmtApps->execute([$idSuscripcion]);
    $apps = $stmtApps->fetchAll();
    
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}

// Determinar estado visual
$estadoClase = 'bg-blue-500';
$estadoTexto = 'Pendiente de Pago';
$mostrarBotonPago = true;

switch ($suscripcion['estado_pago']) {
    case 'pagado':
        $estadoClase = 'bg-green-500';
        $estadoTexto = 'Pagado';
        $mostrarBotonPago = false;
        break;
    case 'atrasado':
        $estadoClase = 'bg-red-500';
        $estadoTexto = 'Atrasado';
        break;
}

if ($suscripcion['estado'] === 'vencida') {
    $estadoClase = 'bg-red-600';
    $estadoTexto = 'Suscripción Vencida';
} elseif ($suscripcion['estado'] === 'gracia') {
    $estadoClase = 'bg-amber-500';
    $estadoTexto = "Período de Gracia ({$suscripcion['dias_restantes']} días)";
}

$logoUrl = 'https://sistemax.com.py/_lib/img/grp__NM__img__NM__logo_smx_300px.png';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagar Suscripción - SistemaX</title>
    <link rel="stylesheet" href="assets/tailwind.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1e3a5f 0%, #0d1b2a 100%);
            min-height: 100vh;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        .app-item {
            transition: all 0.2s ease;
        }
        .app-item:hover {
            transform: translateX(5px);
        }
        .btn-pay {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transition: all 0.3s ease;
        }
        .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(5, 150, 105, 0.4);
        }
        .btn-pay:active {
            transform: translateY(0);
        }
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 20px rgba(5, 150, 105, 0.4); }
            50% { box-shadow: 0 0 40px rgba(5, 150, 105, 0.6); }
        }
        .btn-pay-animate {
            animation: pulse-glow 2s infinite;
        }
    </style>
</head>
<body class="p-4 md:p-8">
    <div class="max-w-2xl mx-auto">
        <!-- Logo -->
        <div class="text-center mb-8">
            <img src="<?= $logoUrl ?>" alt="SistemaX" class="h-16 mx-auto opacity-90">
        </div>
        
        <!-- Card Principal -->
        <div class="glass-card overflow-hidden">
            <!-- Header -->
            <div class="bg-gradient-to-r from-blue-600 to-blue-700 text-white p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold">Factura de Suscripción</h1>
                        <p class="text-blue-100 mt-1"><?= htmlspecialchars($suscripcion['nro_factura']) ?></p>
                    </div>
                    <span class="<?= $estadoClase ?> text-white text-sm font-semibold px-4 py-2 rounded-full">
                        <?= $estadoTexto ?>
                    </span>
                </div>
            </div>
            
            <!-- Datos de Empresa -->
            <div class="p-6 border-b border-gray-100">
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-500">Empresa</span>
                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($suscripcion['empresa']) ?></p>
                    </div>
                    <div>
                        <span class="text-gray-500">RUC</span>
                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($suscripcion['ruc']) ?></p>
                    </div>
                    <div>
                        <span class="text-gray-500">Período</span>
                        <p class="font-semibold text-gray-800">
                            <?= date('d/m/Y', strtotime($suscripcion['periodo_inicio'])) ?> - 
                            <?= date('d/m/Y', strtotime($suscripcion['periodo_fin'])) ?>
                        </p>
                    </div>
                    <div>
                        <span class="text-gray-500">Vencimiento</span>
                        <p class="font-semibold <?= $suscripcion['dias_restantes'] < 0 ? 'text-red-600' : 'text-gray-800' ?>">
                            <?= date('d/m/Y', strtotime($suscripcion['fecha_vencimiento'])) ?>
                            <?php if ($suscripcion['dias_restantes'] < 0): ?>
                                <span class="text-xs">(Vencido)</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Lista de Apps -->
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-4">
                    Aplicaciones Contratadas
                </h3>
                <div class="space-y-3">
                    <?php foreach ($apps as $app): ?>
                    <div class="app-item flex items-center justify-between p-3 bg-gray-50 rounded-xl">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-<?= $app['color'] ?>-100 flex items-center justify-center">
                                <i class="<?= htmlspecialchars($app['icono']) ?> text-<?= $app['color'] ?>-600"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-800"><?= htmlspecialchars($app['nombre_app']) ?></p>
                                <p class="text-xs text-gray-500"><?= htmlspecialchars($app['codigo_app']) ?></p>
                            </div>
                        </div>
                        <span class="font-semibold text-gray-700">
                            ₲ <?= number_format($app['precio_unitario'], 0, ',', '.') ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($apps)): ?>
                    <p class="text-center text-gray-400 py-4">No hay apps en esta suscripción</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Totales -->
            <div class="p-6 bg-gray-50">
                <div class="flex justify-between items-center mb-2 text-sm">
                    <span class="text-gray-500">Subtotal</span>
                    <span class="text-gray-700">₲ <?= number_format($suscripcion['subtotal'], 0, ',', '.') ?></span>
                </div>
                <?php if ($suscripcion['descuento'] > 0): ?>
                <div class="flex justify-between items-center mb-2 text-sm">
                    <span class="text-gray-500">Descuento</span>
                    <span class="text-green-600">- ₲ <?= number_format($suscripcion['descuento'], 0, ',', '.') ?></span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between items-center pt-3 border-t border-gray-200">
                    <span class="text-lg font-semibold text-gray-700">Total a Pagar</span>
                    <span class="text-3xl font-bold text-gray-900">
                        ₲ <?= number_format($suscripcion['total'], 0, ',', '.') ?>
                    </span>
                </div>
            </div>
            
            <!-- Botón de Pago -->
            <?php if ($mostrarBotonPago): ?>
            <div class="p-6">
                <button id="btnPagar" onclick="iniciarPago()" 
                        class="btn-pay btn-pay-animate w-full text-white text-lg font-semibold py-4 px-6 rounded-xl flex items-center justify-center gap-3">
                    <i class="fas fa-credit-card"></i>
                    Pagar Ahora con Ueno
                </button>
                
                <div id="loadingPago" class="hidden text-center py-4">
                    <div class="inline-flex items-center gap-3 text-blue-600">
                        <svg class="animate-spin h-6 w-6" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        <span>Conectando con pasarela de pago...</span>
                    </div>
                </div>
                
                <p class="text-center text-xs text-gray-400 mt-4">
                    <i class="fas fa-lock mr-1"></i>
                    Pago seguro procesado por Ueno
                </p>
            </div>
            <?php else: ?>
            <div class="p-6 text-center">
                <div class="inline-flex items-center gap-2 text-green-600 bg-green-50 px-6 py-3 rounded-xl">
                    <i class="fas fa-check-circle text-2xl"></i>
                    <span class="font-semibold">Pago Confirmado</span>
                </div>
                <?php if ($suscripcion['fecha_pago']): ?>
                <p class="text-sm text-gray-500 mt-2">
                    Pagado el <?= date('d/m/Y H:i', strtotime($suscripcion['fecha_pago'])) ?>
                </p>
                <?php endif; ?>
                
                <a href="menu/menu.php" class="inline-block mt-4 text-blue-600 hover:text-blue-700 font-medium">
                    <i class="fas fa-arrow-left mr-1"></i> Volver al Sistema
                </a>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Footer -->
        <div class="text-center mt-8 text-white/60 text-sm">
            <p>¿Necesita ayuda? <a href="mailto:soporte@sistemax.com.py" class="text-white/80 hover:text-white">soporte@sistemax.com.py</a></p>
            <p class="mt-2">© <?= date('Y') ?> SistemaX - Todos los derechos reservados</p>
        </div>
    </div>
    
    <script>
    const idSuscripcion = <?= $idSuscripcion ?>;
    
    async function iniciarPago() {
        const btnPagar = document.getElementById('btnPagar');
        const loading = document.getElementById('loadingPago');
        
        btnPagar.classList.add('hidden');
        loading.classList.remove('hidden');
        
        try {
            const response = await fetch('api/v1/suscripciones.php?action=generar_pago_ueno', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id_suscripcion: idSuscripcion
                })
            });
            
            const data = await response.json();
            
            if (data.success && data.checkout_url) {
                // Redirigir a pasarela de pago
                window.location.href = data.checkout_url;
            } else {
                alert(data.error || data.message || 'Error al generar el pago');
                btnPagar.classList.remove('hidden');
                loading.classList.add('hidden');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error de conexión. Intente nuevamente.');
            btnPagar.classList.remove('hidden');
            loading.classList.add('hidden');
        }
    }
    </script>
</body>
</html>
