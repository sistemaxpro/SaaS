<?php
/**
 * Test de Conexión - SistemaX
 * Diagnóstico completo del sistema
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width'>";
echo "<title>Test Conexión</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#1e293b;color:#e2e8f0}";
echo ".ok{color:#22c55e}.error{color:#ef4444}.warn{color:#f59e0b}";
echo ".box{background:#0f172a;padding:15px;margin:10px 0;border-radius:8px}";
echo "pre{overflow-x:auto;}</style></head><body>";

echo "<h1>🔍 Test de Conexión - SistemaX</h1>";

// 1. Verificar PHP
echo "<div class='box'><h3>1. PHP</h3>";
echo "<p class='ok'>✅ PHP Version: " . phpversion() . "</p>";
echo "<p>PDO disponible: " . (extension_loaded('pdo') ? "<span class='ok'>✅ SÍ</span>" : "<span class='error'>❌ NO</span>") . "</p>";
echo "<p>PDO MySQL: " . (extension_loaded('pdo_mysql') ? "<span class='ok'>✅ SÍ</span>" : "<span class='error'>❌ NO</span>") . "</p>";
echo "</div>";

// 2. Verificar archivos
echo "<div class='box'><h3>2. Archivos de Configuración</h3>";

$configFiles = [
    'bootstrap.php'  => __DIR__ . '/../config/bootstrap.php',
    'database.php'   => __DIR__ . '/../config/database.php',
    'session.php'    => __DIR__ . '/../config/session.php',
    'src/Core/Auth.php'        => __DIR__ . '/../src/Core/Auth.php',
    'src/Core/Permission.php'  => __DIR__ . '/../src/Core/Permission.php',
    'src/Core/MultiTenant.php' => __DIR__ . '/../src/Core/MultiTenant.php',
];

foreach ($configFiles as $name => $path) {
    $exists = file_exists($path);
    $readable = $exists ? is_readable($path) : false;
    echo "<p>$name: ";
    if ($exists && $readable) {
        echo "<span class='ok'>✅ OK</span> <small>($path)</small>";
    } elseif ($exists) {
        echo "<span class='warn'>⚠️ Existe pero no legible</span>";
    } else {
        echo "<span class='error'>❌ NO EXISTE</span> <small>($path)</small>";
    }
    echo "</p>";
}
echo "</div>";

// 3. Cargar bootstrap
echo "<div class='box'><h3>3. Cargando Bootstrap</h3>";
$bootstrapPath = __DIR__ . '/../config/bootstrap.php';

if (file_exists($bootstrapPath)) {
    try {
        require_once $bootstrapPath;
        echo "<p class='ok'>✅ Bootstrap cargado correctamente</p>";
        
        // Verificar clases
        echo "<p>Clase Database: " . (class_exists('Database') ? "<span class='ok'>✅ SÍ</span>" : "<span class='error'>❌ NO</span>") . "</p>";
        echo "<p>Clase Session: " . (class_exists('Session') ? "<span class='ok'>✅ SÍ</span>" : "<span class='error'>❌ NO</span>") . "</p>";
        
        // Listar métodos de Database
        if (class_exists('Database')) {
            $methods = get_class_methods('Database');
            echo "<p>Métodos de Database: <pre>" . implode(', ', $methods) . "</pre></p>";
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Error al cargar bootstrap: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre class='error'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
} else {
    echo "<p class='error'>❌ Bootstrap no encontrado</p>";
}
echo "</div>";

// 4. Test de conexión
echo "<div class='box'><h3>4. Test de Conexión a Base de Datos</h3>";

if (class_exists('Database')) {
    try {
        // Verificar si el método existe
        if (method_exists('Database', 'getMasterConnection')) {
            echo "<p class='ok'>✅ Método getMasterConnection existe</p>";
            
            $pdo = Database::getMasterConnection();
            
            if ($pdo instanceof PDO) {
                echo "<p class='ok'>✅ Conexión establecida (PDO válido)</p>";
                
                // Test query
                try {
                    $stmt = $pdo->query("SELECT 1 as test");
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo "<p class='ok'>✅ Query de prueba exitoso</p>";
                    
                    // Contar empresas
                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM empresa");
                    $count = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo "<p class='ok'>✅ Empresas en BD: " . $count['total'] . "</p>";
                    
                    // Contar usuarios
                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM sec_users");
                    $count = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo "<p class='ok'>✅ Usuarios en BD: " . $count['total'] . "</p>";
                    
                } catch (PDOException $e) {
                    echo "<p class='error'>❌ Error en query: " . htmlspecialchars($e->getMessage()) . "</p>";
                }
                
            } elseif ($pdo === null) {
                echo "<p class='error'>❌ getMasterConnection retornó NULL</p>";
            } elseif ($pdo === false) {
                echo "<p class='error'>❌ getMasterConnection retornó FALSE</p>";
            } else {
                echo "<p class='warn'>⚠️ getMasterConnection retornó: " . gettype($pdo) . "</p>";
            }
            
        } else {
            echo "<p class='error'>❌ Método getMasterConnection NO existe</p>";
            
            // Buscar métodos alternativos
            $methods = get_class_methods('Database');
            echo "<p>Métodos disponibles: " . implode(', ', $methods) . "</p>";
            
            // Intentar getConnection si existe
            if (method_exists('Database', 'getConnection')) {
                echo "<p class='warn'>⚠️ Intentando getConnection()...</p>";
                try {
                    $pdo = Database::getConnection();
                    if ($pdo instanceof PDO) {
                        echo "<p class='ok'>✅ getConnection() funcionó!</p>";
                    }
                } catch (Exception $e) {
                    echo "<p class='error'>❌ getConnection() falló: " . $e->getMessage() . "</p>";
                }
            }
            
            // Intentar connect si existe
            if (method_exists('Database', 'connect')) {
                echo "<p class='warn'>⚠️ Intentando connect()...</p>";
                try {
                    $pdo = Database::connect();
                    if ($pdo instanceof PDO) {
                        echo "<p class='ok'>✅ connect() funcionó!</p>";
                    }
                } catch (Exception $e) {
                    echo "<p class='error'>❌ connect() falló: " . $e->getMessage() . "</p>";
                }
            }
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre class='error'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
} else {
    echo "<p class='error'>❌ Clase Database no disponible</p>";
}
echo "</div>";

// 5. Entorno detectado
echo "<div class='box'><h3>5. Entorno y Variables de Configuración</h3>";
$envVars = [
    'SISTEMAX_ENV'            => 'Entorno (dev/prod)',
    'SISTEMAX_MASTER_DB_HOST' => 'DB Host',
    'SISTEMAX_MASTER_DB_PORT' => 'DB Puerto',
    'SISTEMAX_MASTER_DB_NAME' => 'DB Master',
    'SISTEMAX_MASTER_DB_USER' => 'DB Usuario',
    'SISTEMAX_MASTER_DB_PASS' => 'DB Contraseña',
];
foreach ($envVars as $var => $label) {
    $val = getenv($var);
    if ($val !== false && $val !== '') {
        $display = ($var === 'SISTEMAX_MASTER_DB_PASS') ? str_repeat('*', strlen($val)) : htmlspecialchars($val);
        echo "<p>$label (<code>$var</code>): <span class='ok'>✅ $display</span></p>";
    } else {
        echo "<p>$label (<code>$var</code>): <span class='warn'>⚠️ No definida — usando valor por defecto</span></p>";
    }
}
if (defined('SISTEMAX_ENV')) {
    $envColor = SISTEMAX_ENV === 'dev' ? 'ok' : 'warn';
    echo "<p>Constante <code>SISTEMAX_ENV</code>: <span class='$envColor'>" . SISTEMAX_ENV . "</span></p>";
}
echo "</div>";

// 6. Constantes del sistema
echo "<div class='box'><h3>6. Constantes del Sistema</h3>";
$sysConstants = ['SISTEMAX_V1', 'SISTEMAX_ENV', 'SISTEMAX_IS_DEV', 'MASTER_DB', 'SISTEMAX_PROJECT_ROOT'];
foreach ($sysConstants as $const) {
    if (defined($const)) {
        $val = constant($const);
        if (is_bool($val)) $val = $val ? 'true' : 'false';
        echo "<p><code>$const</code>: <span class='ok'>✅ " . htmlspecialchars((string)$val) . "</span></p>";
    } else {
        echo "<p><code>$const</code>: <span class='error'>❌ No definida</span></p>";
    }
}
echo "</div>";

echo "<p style='margin-top:30px;text-align:center;opacity:0.5'>Generado: " . date('Y-m-d H:i:s') . "</p>";
echo "</body></html>";
