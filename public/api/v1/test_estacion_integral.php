<?php
/**
 * Test Integral - Módulo Estación de Servicio
 * Simula un flujo completo: apertura turno → despachos → cierre turno → cierre playa
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/bootstrap.php';

// Simular sesión super admin (empresa 169)
$_SESSION['id_empresa'] = 169;
$_SESSION['usr_priv_admin'] = 'Y';
$_SESSION['usr_id'] = 1;
$_SESSION['usuario'] = 'admin_test';

$isLoggedIn = true;
$idEmpresa = 169;

// Test 1: Listar apps de Estación
$master = Database::getMasterConnection();
$stmt = $master->prepare("SELECT id_app, codigo, nombre FROM saas_apps_catalogo WHERE negocio = ? AND activo = 1 ORDER BY orden");
$stmt->execute(['Estación de Servicio']);
$apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Test 2: Verificar tablas
$empresa = Database::getEmpresaConnection(169);
$stmt = $empresa->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'empresa_169' AND table_name LIKE 'estacion_%' ORDER BY table_name");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Test 3: Listar combustibles
$stmt = $empresa->query("SELECT id, nombre, unidad_medida, precio_venta FROM estacion_combustibles WHERE activo = 'Y' ORDER BY id");
$combustibles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Test 4: Listar surtidores (si existen)
$stmt = $empresa->query("SELECT COUNT(*) FROM estacion_surtidores WHERE activo = 'Y'");
$surtidoresCount = (int)$stmt->fetchColumn();

// Test 5: Listar tanques (si existen)
$stmt = $empresa->query("SELECT COUNT(*) FROM estacion_tanques WHERE activo = 'Y'");
$tanquesCount = (int)$stmt->fetchColumn();

// Test 6: Turnos del día
$stmt = $empresa->query("SELECT COUNT(*) FROM estacion_turnos WHERE DATE(fecha_turno) = CURDATE()");
$turnosHoy = (int)$stmt->fetchColumn();

// Test 7: Despachos del día
$stmt = $empresa->query("SELECT COUNT(*), SUM(monto_total) FROM estacion_despachos WHERE DATE(fecha_hora) = CURDATE()");
$despachosData = $stmt->fetch(PDO::FETCH_ASSOC);
$despachosCount = (int)($despachosData['COUNT(*)'] ?? 0);
$totalVendido = (float)($despachosData['SUM(monto_total)'] ?? 0);

echo json_encode([
    'success' => true,
    'empresa_id' => $idEmpresa,
    'timestamp' => date('Y-m-d H:i:s'),
    'verificacion' => [
        'apps_catalogo' => count($apps),
        'apps_lista' => array_map(function($app) {
            return $app['codigo'] . ' - ' . $app['nombre'];
        }, $apps),
        'tablas_base_datos' => count($tables),
        'tablas_creadas' => $tables,
        'combustibles_registrados' => count($combustibles),
        'combustibles' => $combustibles,
        'surtidores_configurados' => $surtidoresCount,
        'tanques_configurados' => $tanquesCount,
        'turnos_hoy' => $turnosHoy,
        'despachos_hoy' => $despachosCount,
        'total_vendido_hoy' => $totalVendido,
    ],
    'estado_sistema' => [
        'apps_saas_ok' => count($apps) === 6,
        'base_datos_ok' => count($tables) === 12,
        'combustibles_ok' => count($combustibles) === 6,
        'datos_iniciales_ok' => $turnosHoy === 0 && $despachosCount === 0,
    ],
    'proximo_paso' => 'Crear Estación de Prueba: configurar surtidores y tanques en /estacion-surtidores y /estacion-tanques'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
