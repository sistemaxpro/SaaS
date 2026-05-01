<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Estructura de sec_users</h2>";

$pdo = Database::getMasterConnection();

if ($pdo) {
    try {
        // Obtener columnas de sec_users
        $stmt = $pdo->query("DESCRIBE sec_users");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>Columnas de sec_users:</h3>";
        echo "<table border='1' style='border-collapse:collapse;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td><strong>{$col['Field']}</strong></td>";
            echo "<td>{$col['Type']}</td>";
            echo "<td>{$col['Null']}</td>";
            echo "<td>{$col['Key']}</td>";
            echo "<td>{$col['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Mostrar un registro de ejemplo
        echo "<h3>Ejemplo de registro (sin password):</h3>";
        $stmt = $pdo->query("SELECT * FROM sec_users LIMIT 1");
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            echo "<pre>";
            foreach ($user as $key => $value) {
                // Ocultar campos sensibles
                if (in_array(strtolower($key), ['password', 'clave', 'pass', 'pwd', 'contrasena'])) {
                    echo "$key: ********\n";
                } else {
                    echo "$key: $value\n";
                }
            }
            echo "</pre>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p style='color:red;'>No hay conexión</p>";
}
?>
