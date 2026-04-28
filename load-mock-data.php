<?php
/**
 * Script para cargar datos mock del módulo Estación de Servicio
 * SOLO PARA DESARROLLO - Puerto 3307
 *
 * Uso: php load-mock-data.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!defined('SISTEMAX_V1')) {
    define('SISTEMAX_V1', true);
}

require_once __DIR__ . '/public/shared/schema_module_compat.php';

// Cargar configuración de .env.mock
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

// Obtener configuración con fallbacks
$host = $_ENV['MOCK_DB_HOST'] ?? 'localhost';
$port = (int)($_ENV['MOCK_DB_PORT'] ?? 3307);
$user = $_ENV['MOCK_DB_USER'] ?? 'desarrollo';
$password = $_ENV['MOCK_DB_PASS'] ?? 'desarrollo123';
$database = $_ENV['MOCK_DB_NAME'] ?? 'desarrollo';
$allowedPort = (int)($_ENV['MOCK_ALLOWED_PORT'] ?? 3307);

// ====== PROTECCIÓN: Solo permitir en puerto de desarrollo ======
if ($port !== $allowedPort) {
    echo "❌ ERROR: Puerto no coincide con configuración de desarrollo\n";
    echo "Puerto esperado: {$allowedPort}\n";
    echo "Puerto configurado: {$port}\n";
    exit(1);
}

try {
    // Crear conexión
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "✓ Conectado a BD: {$database}@{$host}:{$port}\n";
    echo str_repeat("=", 60) . "\n";

    // Asegurar esquema mínimo del módulo estación antes de cargar los datos mock.
    // Si la BD está vacía, esto crea las tablas requeridas para los INSERT del seed.
    sxEnsureEstacionSchemaCompat($pdo, $database);
    echo "✓ Esquema estación verificado/creado en: {$database}\n";

    // Leer el archivo SQL
    $sqlFile = __DIR__ . '/database/seeds/estacion_datos_mock.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Archivo SQL no encontrado: {$sqlFile}");
    }

    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new Exception("No se pudo leer el archivo SQL: {$sqlFile}");
    }

    // El archivo usa comentarios de línea con "--". Los eliminamos antes de partir
    // para no descartar bloques completos que empiezan con comentarios.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    // Dividir en statements individuales y ejecutar
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $count = 0;

    foreach ($statements as $statement) {
        if ($statement === '') {
            continue;
        }

        try {
            $pdo->exec($statement);
            $count++;

            // Extraer nombre del statement para logging
            if (strpos($statement, 'INSERT') === 0) {
                preg_match('/INTO\s+(\w+)/', $statement, $matches);
                $table = $matches[1] ?? 'tabla';
                echo "✓ Insertados datos en: {$table}\n";
            } elseif (strpos($statement, 'UPDATE') === 0) {
                preg_match('/UPDATE\s+(\w+)/', $statement, $matches);
                $table = $matches[1] ?? 'tabla';
                echo "✓ Actualizados datos en: {$table}\n";
            }
        } catch (PDOException $e) {
            echo "⚠ Error en statement: " . substr($statement, 0, 50) . "...\n";
            echo "  Detalle: " . $e->getMessage() . "\n";
            // Continuar con el siguiente statement
        }
    }

    $summaryTables = [
        'estacion_combustibles' => 'combustibles',
        'estacion_tanques' => 'tanques',
        'estacion_surtidores' => 'surtidores',
        'estacion_picos' => 'picos',
        'estacion_lecturas_tanque' => 'lecturas de tanque',
        'estacion_turnos' => 'turnos',
        'estacion_turno_picos' => 'turno/picos',
        'estacion_despachos' => 'despachos',
        'estacion_lecturas_surtidor' => 'lecturas de surtidor',
        'estacion_cierre_playa' => 'cierres de playa',
        'estacion_cierre_playa_detalle' => 'detalle de cierre',
        'estacion_precios_historial' => 'historial de precios',
    ];

    $summaryCounts = [];
    foreach ($summaryTables as $table => $label) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM `{$table}`");
            $summaryCounts[] = [
                'label' => $label,
                'table' => $table,
                'count' => (int)($stmt->fetch()['cnt'] ?? 0),
            ];
        } catch (Throwable $e) {
            $summaryCounts[] = [
                'label' => $label,
                'table' => $table,
                'count' => -1,
            ];
        }
    }

    echo str_repeat("=", 60) . "\n";
    echo "✅ Carga completada: {$count} statements ejecutados\n";
    echo "\n📊 Resumen final por tabla:\n";
    foreach ($summaryCounts as $row) {
        if ($row['count'] === -1) {
            echo "   • {$row['label']} ({$row['table']}): error al contar\n";
            continue;
        }
        echo "   • {$row['label']} ({$row['table']}): {$row['count']}\n";
    }

} catch (PDOException $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
