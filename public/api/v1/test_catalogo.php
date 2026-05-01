<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../src/Modules/Empresas/SuscripcionController.php';

// Test directo del catálogo
$resultado = SuscripcionController::listarApps(['solo_activas' => true]);

$apps = $resultado['data'] ?? [];
echo json_encode([
    'success' => true,
    'total_apps' => count($apps),
    'apps_estacion' => array_values(array_filter($apps, function($app) {
        return ($app['negocio'] ?? '') === 'Estación de Servicio';
    })),
    'count_estacion' => count(array_filter($apps, function($app) {
        return ($app['negocio'] ?? '') === 'Estación de Servicio';
    })),
    'all_negocios' => array_values(array_unique(array_map(function($app) {
        return $app['negocio'] ?? 'Sin tipo';
    }, $apps)))
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
