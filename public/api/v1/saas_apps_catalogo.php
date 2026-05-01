<?php
/**
 * API: Obtener apps disponibles por tipo de negocio
 */
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../../../config/bootstrap.php';

    $rubro = $_GET['rubro'] ?? null;

    if (!$rubro) {
        throw new Exception('Rubro es requerido');
    }

    $pdo = Database::getMasterConnection();

    // Mapeo de rubro a negocio en saas_negocios_tipos
    $rubrosMap = [
        'tienda' => 'Comercial',
        'estacion' => 'Estación de Servicio',
        'restaurant' => 'Restaurante',
        'servicios' => 'Servicios',
        'otro' => 'Comercial'
    ];

    $negocio = $rubrosMap[$rubro] ?? 'Comercial';

    // Debug: verificar que la conexión está activa
    $testStmt = $pdo->prepare("SELECT DATABASE(), USER()");
    $testStmt->execute();
    $row = $testStmt->fetch(PDO::FETCH_ASSOC);
    $dbName = $row['DATABASE()'] ?? '';
    $user = $row['USER()'] ?? '';

    // Test: contar cuántas apps hay en total
    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM saas_apps_catalogo");
    $countStmt->execute();
    $countRow = $countStmt->fetch();
    $totalApps = $countRow['total'] ?? 0;

    // Obtener apps para este tipo de negocio
    $sql = "
        SELECT id_app, codigo, nombre, descripcion, ruta_app, precio_mensual, icono, negocio
        FROM saas_apps_catalogo
        WHERE negocio = ? AND activo = 1
        ORDER BY orden ASC, nombre ASC
    ";

    $stmt = $pdo->prepare($sql);
    $executed = $stmt->execute([$negocio]);
    $apps = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Test: obtener todos los negocios únicos
    $businessesStmt = $pdo->prepare("SELECT DISTINCT negocio FROM saas_apps_catalogo");
    $businessesStmt->execute();
    $businesses = $businessesStmt->fetchAll(PDO::FETCH_COLUMN);

    // Test: obtener apps de Estación de Servicio sin condición
    $estacionStmt = $pdo->prepare("SELECT id_app, codigo, nombre, negocio FROM saas_apps_catalogo WHERE negocio LIKE '%Estación%'");
    $estacionStmt->execute();
    $estacionApps = $estacionStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'negocio' => $negocio,
        'apps' => $apps,
        '_debug' => [
            'db' => $dbName,
            'user' => $user,
            'rubro' => $rubro,
            'negocio_mapped' => $negocio,
            'apps_count' => count($apps),
            'executed' => $executed,
            'total_apps_in_db' => $totalApps,
            'available_businesses' => $businesses,
            'estacion_apps_found' => count($estacionApps),
            'estacion_apps' => $estacionApps,
            'sql' => $sql
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE);
}
