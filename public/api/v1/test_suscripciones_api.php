<?php
/**
 * Test para simular el endpoint de suscripciones
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../src/Modules/Empresas/SuscripcionController.php';

// Simular sesión super admin (empresa 169)
$_SESSION['id_empresa'] = 169;
$_SESSION['usr_priv_admin'] = 'Y';

$isLoggedIn = true;
$idEmpresa = 169;
$isAdmin = true;
$isSuperAdmin = true;

// Ejecutar lo que hace el endpoint
$resultado = SuscripcionController::listarApps([
    'solo_activas' => !$isSuperAdmin  // Para super admin, false (muestra todos)
]);

$apps = $resultado['data'] ?? [];
$estacion = array_filter($apps, function($app) {
    return ($app['negocio'] ?? '') === 'Estación de Servicio';
});

echo json_encode([
    'success' => $resultado['success'] ?? false,
    'total_apps' => count($apps),
    'total_estacion' => count($estacion),
    'estacion_apps' => array_values($estacion),
    'all_negocios' => array_values(array_unique(array_map(function($app) {
        return $app['negocio'] ?? 'Sin tipo';
    }, $apps)))
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
