<?php
/**
 * Test script para verificar la sincronización del catálogo de apps
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../src/Modules/Empresas/SuscripcionController.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Ejecutar sincronización
    $resultado = SuscripcionController::ensureAllCatalogApps();

    // Obtener todas las apps del catálogo
    $db = Database::getMasterConnection();
    $stmt = $db->query("SELECT COUNT(*) as total FROM saas_apps_catalogo WHERE activo = 1");
    $countRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalAppsActivas = $countRow['total'] ?? 0;

    // Obtener lista de apps
    $stmt = $db->query("
        SELECT id_app, codigo, nombre, modulo, negocio, activo
        FROM saas_apps_catalogo
        WHERE activo = 1
        ORDER BY modulo, nombre
    ");
    $appsLista = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Responder con los resultados
    echo json_encode([
        'success' => true,
        'message' => 'Sincronización completada',
        'sync_results' => $resultado,
        'catalog_stats' => [
            'total_apps_activas' => $totalAppsActivas,
            'apps_por_modulo' => array_reduce($appsLista, function($carry, $app) {
                $modulo = $app['modulo'] ?: 'Sin módulo';
                if (!isset($carry[$modulo])) {
                    $carry[$modulo] = [];
                }
                $carry[$modulo][] = $app['nombre'];
                return $carry;
            }, [])
        ],
        'apps_list' => $appsLista
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE);
}
