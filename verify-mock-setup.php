<?php
/**
 * Script de diagnóstico para verificar setup de datos mock
 * Verifica conexión, tablas y permisos antes de cargar datos
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!defined('SISTEMAX_V1')) {
    define('SISTEMAX_V1', true);
}

require_once __DIR__ . '/public/shared/schema_module_compat.php';

$checks = [];
$opts = getopt('', ['create-schema']);
$createSchema = array_key_exists('create-schema', $opts);

echo "\n";
echo "╔" . str_repeat("═", 58) . "╗\n";
echo "║ DIAGNÓSTICO - Carga de Datos Mock Estación de Servicio ║\n";
echo "╚" . str_repeat("═", 58) . "╝\n\n";

// ====== CHECK 1: Archivo SQL existe ======
$sqlFile = __DIR__ . '/database/seeds/estacion_datos_mock.sql';
$checks[] = [
    'nombre' => 'Archivo SQL existe',
    'resultado' => file_exists($sqlFile),
    'detalle' => $sqlFile,
    'fix' => 'Crea el archivo: database/seeds/estacion_datos_mock.sql'
];

// ====== CHECK 2: Conexión a BD Development ======
// Cargar .env.mock si existe
$envFile = __DIR__ . '/.env.mock';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

$host = $_ENV['MOCK_DB_HOST'] ?? 'localhost';
$port = (int)($_ENV['MOCK_DB_PORT'] ?? 3307);
$user = $_ENV['MOCK_DB_USER'] ?? 'desarrollo';
$password = $_ENV['MOCK_DB_PASS'] ?? 'desarrollo123';
$database = $_ENV['MOCK_DB_NAME'] ?? 'desarrollo';

$conexion_ok = false;
$version_mysql = '';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]
    );

    $conexion_ok = true;
    $version_mysql = $pdo->query("SELECT VERSION()")->fetchColumn();
} catch (Exception $e) {
    $error_conexion = $e->getMessage();
}

$checks[] = [
    'nombre' => 'Conexión BD Desarrollo (3307)',
    'resultado' => $conexion_ok,
    'detalle' => $conexion_ok ? "MySQL {$version_mysql}" : "Error: {$error_conexion}",
    'fix' => 'Verifica que MySQL está corriendo en puerto 3307 y credenciales son correctas'
];

// ====== CHECK 3: Tablas existen ======
$tablas_requeridas = [
    'estacion_combustibles',
    'estacion_tanques',
    'estacion_surtidores',
    'estacion_picos',
    'estacion_turnos',
    'estacion_despachos',
    'estacion_cierre_playa'
];

$tablas_ok = false;
$tablas_faltantes = [];

if ($conexion_ok) {
    if ($createSchema) {
        try {
            sxEnsureEstacionSchemaCompat($pdo, $database);
        } catch (Throwable $e) {
            $checks[] = [
                'nombre' => 'Crear esquema estación',
                'resultado' => false,
                'detalle' => $e->getMessage(),
                'fix' => 'Revisa permisos en la BD de desarrollo'
            ];
        }
    }

    foreach ($tablas_requeridas as $tabla) {
        try {
            $result = $pdo->query("SELECT 1 FROM {$tabla} LIMIT 1");
        } catch (Exception $e) {
            $tablas_faltantes[] = $tabla;
        }
    }
    $tablas_ok = empty($tablas_faltantes);
}

$checks[] = [
    'nombre' => 'Tablas existen',
    'resultado' => $tablas_ok,
    'detalle' => $tablas_ok
        ? 'Todas las ' . count($tablas_requeridas) . ' tablas encontradas'
        : 'Faltan tablas: ' . implode(', ', $tablas_faltantes),
    'fix' => $createSchema
        ? 'Revisa si el usuario de BD tiene permisos CREATE TABLE'
        : 'Ejecuta: php verify-mock-setup.php --create-schema para preparar la BD o las migraciones del proyecto primero'
];

// ====== CHECK 4: Permisos de escritura ======
$permiso_escritura = false;
$directorio = __DIR__ . '/database/seeds';

if (file_exists($directorio)) {
    $permiso_escritura = is_writable($directorio);
} else {
    @mkdir($directorio, 0755, true);
    $permiso_escritura = is_writable($directorio);
}

$checks[] = [
    'nombre' => 'Permiso de escritura en database/seeds',
    'resultado' => $permiso_escritura,
    'detalle' => $permiso_escritura ? 'Directorio writable' : 'Permiso denegado',
    'fix' => 'Ejecuta: chmod -R 755 database/seeds'
];

// ====== CHECK 5: Registros existentes (para evitar duplicados) ======
$registros_ok = true;
$registros_existentes = [];

if ($conexion_ok) {
    // Contar registros en tablas principales
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM estacion_tanques");
        $cant_tanques = $stmt->fetch()['cnt'] ?? 0;

        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM estacion_surtidores");
        $cant_surtidores = $stmt->fetch()['cnt'] ?? 0;

        if ($cant_tanques > 0 || $cant_surtidores > 0) {
            $registros_ok = false;
            $registros_existentes[] = "tanques: $cant_tanques";
            $registros_existentes[] = "surtidores: $cant_surtidores";
        }
    } catch (Exception $e) {
        // Ignorar
    }
}

$checks[] = [
    'nombre' => 'Base datos vacía (sin datos previos)',
    'resultado' => $registros_ok,
    'detalle' => $registros_ok
        ? 'Base de datos lista para datos mock'
        : 'Ya existen datos: ' . implode(', ', $registros_existentes),
    'fix' => 'Ejecuta: mysql -P 3307 -u desarrollo -p desarrollo < cleanup.sql (opcional)'
];

// ====== MOSTRAR RESULTADOS ======
$todos_ok = true;
$críticos = [];
$advertencias = [];

foreach ($checks as $check) {
    $emoji = $check['resultado'] ? '✅' : '❌';
    $estado = $check['resultado'] ? 'LISTO' : 'ERROR';

    echo "{$emoji} {$check['nombre']}\n";
    echo "   → {$check['detalle']}\n";

    if (!$check['resultado']) {
        $todos_ok = false;
        $críticos[] = $check;
        echo "   ℹ  {$check['fix']}\n";
    }
    echo "\n";
}

// ====== RESUMEN FINAL ======
echo "\n" . str_repeat("─", 60) . "\n";
if ($todos_ok) {
    echo "✅ SISTEMA LISTO - Puedes cargar los datos mock\n\n";
    echo "Ejecuta: php load-mock-data.php\n";
} else {
    echo "❌ REQUIERE ATENCIÓN - Se encontraron " . count($críticos) . " problema(s)\n";
    echo "\nResolve los problemas arriba antes de continuar.\n";
}
echo str_repeat("─", 60) . "\n\n";

exit($todos_ok ? 0 : 1);
?>
