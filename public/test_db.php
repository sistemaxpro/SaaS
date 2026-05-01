<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Test de Conexión</h2>";

// 1. Verificar que existe bootstrap
$bootstrapPath = __DIR__ . '/../config/bootstrap.php';
echo "<p>Bootstrap path: $bootstrapPath</p>";
echo "<p>Existe: " . (file_exists($bootstrapPath) ? 'SÍ' : 'NO') . "</p>";

if (file_exists($bootstrapPath)) {
    require_once $bootstrapPath;
    
    // 2. Verificar clase Database
    echo "<p>Clase Database existe: " . (class_exists('Database') ? 'SÍ' : 'NO') . "</p>";
    
    if (class_exists('Database')) {
        // 3. Verificar método
        echo "<p>Método getMasterConnection existe: " . (method_exists('Database', 'getMasterConnection') ? 'SÍ' : 'NO') . "</p>";
        
        // 4. Intentar conexión
        try {
            $pdo = Database::getMasterConnection();
            echo "<p style='color:green;'>✅ Conexión exitosa: " . ($pdo ? 'SÍ' : 'NULL') . "</p>";
            
            if ($pdo) {
                // 5. Probar query
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM empresa");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                echo "<p style='color:green;'>✅ Empresas encontradas: " . $result['total'] . "</p>";
                
                // 6. Probar sec_users
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM sec_users");
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                echo "<p style='color:green;'>✅ Usuarios encontrados: " . $result['total'] . "</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color:red;'>❌ Error: " . $e->getMessage() . "</p>";
        }
    }
} else {
    echo "<p style='color:red;'>❌ No se encontró bootstrap.php</p>";
}
?>
