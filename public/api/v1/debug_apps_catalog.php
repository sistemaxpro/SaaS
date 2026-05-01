<?php
/**
 * Debug: Herramienta para ver todas las apps del catálogo
 * Útil para verificar sincronización
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../src/Modules/Empresas/SuscripcionController.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Sincronizar automáticamente
    $syncResult = SuscripcionController::ensureAllCatalogApps();

    // Obtener todas las apps
    $db = Database::getMasterConnection();

    // Estadísticas generales
    $stmt = $db->query("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as activas,
            SUM(CASE WHEN en_desarrollo = 1 THEN 1 ELSE 0 END) as en_desarrollo,
            SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) as inactivas
        FROM saas_apps_catalogo
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Apps activas disponibles (no en desarrollo)
    $stmt = $db->query("
        SELECT
            id_app, codigo, nombre, modulo, negocio,
            activo, en_desarrollo, precio_mensual, icono
        FROM saas_apps_catalogo
        WHERE activo = 1 AND en_desarrollo = 0
        ORDER BY negocio, modulo, nombre
    ");
    $appsDisponibles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Apps en desarrollo
    $stmt = $db->query("
        SELECT
            id_app, codigo, nombre, modulo, negocio,
            activo, en_desarrollo, precio_mensual
        FROM saas_apps_catalogo
        WHERE activo = 1 AND en_desarrollo = 1
        ORDER BY nombre
    ");
    $appsEnDesarrollo = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Agrupar por negocio
    $appsPorNegocio = [];
    foreach ($appsDisponibles as $app) {
        $negocio = $app['negocio'] ?: 'Sin Asignar';
        if (!isset($appsPorNegocio[$negocio])) {
            $appsPorNegocio[$negocio] = [];
        }
        $appsPorNegocio[$negocio][] = $app;
    }

    // Respuesta
    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'sync_results' => $syncResult,
        'catalog_stats' => [
            'total_apps' => (int)$stats['total'],
            'apps_activas' => (int)$stats['activas'],
            'apps_en_desarrollo' => (int)$stats['en_desarrollo'],
            'apps_inactivas' => (int)$stats['inactivas'],
            'apps_disponibles_activas' => count($appsDisponibles),
            'apps_en_desarrollo_activas' => count($appsEnDesarrollo)
        ],
        'apps_por_negocio' => array_map(function($apps) {
            return [
                'count' => count($apps),
                'apps' => array_map(function($app) {
                    return [
                        'nombre' => $app['nombre'],
                        'codigo' => $app['codigo'],
                        'modulo' => $app['modulo'],
                        'icono' => $app['icono'] ?? null
                    ];
                }, $apps)
            ];
        }, $appsPorNegocio),
        'apps_en_desarrollo' => array_map(function($app) {
            return [
                'nombre' => $app['nombre'],
                'codigo' => $app['codigo'],
                'modulo' => $app['modulo']
            ];
        }, $appsEnDesarrollo),
        'detalles' => [
            'endpoint' => '/public/api/v1/suscripciones.php?action=apps',
            'nota' => 'Este endpoint retorna todas las apps disponibles (no en desarrollo)',
            'total_que_se_muestran' => count($appsDisponibles)
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE);
}
