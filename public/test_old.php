<?php

/**
 * Test de Conexión - Verificar que todo funciona correctamente
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Test SistemaX v1</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 40px auto; padding: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background-color: #4CAF50; color: white; }
        .test-section { background: #f5f5f5; padding: 20px; margin: 20px 0; border-radius: 5px; }
    </style>
</head>
<body>
    <h1><i class='fas fa-vial text-indigo-600'></i> Test de Sistema v1</h1>
";

$tests = [];

// Test 1: Bootstrap
echo "<div class='test-section'><h2>Test 1: Cargar Bootstrap</h2>";
try {
    require_once __DIR__ . '/../config/bootstrap.php';
    echo "<p class='success'><i class='fas fa-check-circle'></i> Bootstrap cargado correctamente</p>";
    $tests['bootstrap'] = true;
} catch (Exception $e) {
    echo "<p class='error'><i class='fas fa-times-circle'></i> Error: " . $e->getMessage() . "</p>";
    $tests['bootstrap'] = false;
}
echo "</div>";

// Test 2: Conexión Master DB
echo "<div class='test-section'><h2>Test 2: Conexión Master DB (serproc1)</h2>";
try {
    $master = Database::getMasterConnection();
    echo "<p class='success'><i class='fas fa-check-circle'></i> Conexión a serproc1 exitosa</p>";

    $stmt = $master->query("SELECT COUNT(*) as total FROM empresa WHERE activo = 1");
    $result = $stmt->fetch();
    echo "<p class='info'><i class='fas fa-chart-bar'></i> Empresas activas: <strong>{$result['total']}</strong></p>";

    $tests['master_db'] = true;
} catch (Exception $e) {
    echo "<p class='error'><i class='fas fa-times-circle'></i> Error: " . $e->getMessage() . "</p>";
    $tests['master_db'] = false;
}
echo "</div>";

// Test 3: Sesión
echo "<div class='test-section'><h2>Test 3: Sistema de Sesión</h2>";
try {
    Session::start();

    if (Session::isLoggedIn()) {
        echo "<p class='success'><i class='fas fa-check-circle'></i> Sesión activa detectada</p>";
        echo "<table>";
        echo "<tr><th>Variable</th><th>Valor</th></tr>";
        echo "<tr><td>ID Login</td><td>" . Session::getIdLogin() . "</td></tr>";
        echo "<tr><td>ID Empresa</td><td>" . Session::getIdEmpresa() . "</td></tr>";
        echo "<tr><td>Database</td><td>" . Session::getDbase() . "</td></tr>";
        echo "<tr><td>Usuario</td><td>" . ($_SESSION['usuario'] ?? 'N/A') . "</td></tr>";
        echo "<tr><td>Es Admin</td><td>" . (Session::isAdmin() ? 'Sí' : 'No') . "</td></tr>";
        echo "</table>";

        $tests['session'] = true;
    } else {
        echo "<p class='info'><i class='fas fa-info-circle'></i> No hay sesión activa</p>";
        echo "<p>Para probar con sesión: <a href='login.php'>Iniciar sesión aquí</a></p>";
        $stmt = $empresaDb->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "<p class='info'><i class='fas fa-chart-bar'></i> Tablas en la base de datos: <strong>" . count($tables) . "</strong></p>";
        echo "<details><summary>Ver tablas</summary><ul>";
        foreach (array_slice($tables, 0, 20) as $table) {
            echo "<li>{$table}</li>";
        }
        if (count($tables) > 20) {
            echo "<li><em>... y " . (count($tables) - 20) . " más</em></li>";
        }
        echo "</ul></details>";

        $tests['empresa_db'] = true;
    } catch (Exception $e) {
        echo "<p class='error'><i class='fas fa-times-circle'></i> Error: " . $e->getMessage() . "</p>";
        $tests['empresa_db'] = false;
    }
    echo "</div>";
}

// Test 5: MultiTenant
if (Session::isLoggedIn()) {
    echo "<div class='test-section'><h2>Test 5: MultiTenant (Monedas y Sucursales)</h2>";
    try {
        $empresa = MultiTenant::getEmpresaActual();
        echo "<p class='success'><i class='fas fa-check-circle'></i> Información de empresa obtenida</p>";
        echo "<table>";
        echo "<tr><th>Campo</th><th>Valor</th></tr>";
        foreach ($empresa as $key => $value) {
            echo "<tr><td>{$key}</td><td>" . htmlspecialchars($value) . "</td></tr>";
        }
        echo "</table>";

        $tests['multitenant'] = true;
    } catch (Exception $e) {
        echo "<p class='error'><i class='fas fa-times-circle'></i> Error: " . $e->getMessage() . "</p>";
        $tests['multitenant'] = false;
    }
    echo "</div>";
}

// Resumen
echo "<div class='test-section' style='background: #e3f2fd;'>";
echo "<h2><i class='fas fa-clipboard-list'></i> Resumen de Tests</h2>";
echo "<table>";
echo "<tr><th>Test</th><th>Estado</th></tr>";
foreach ($tests as $test => $status) {
    $statusText = $status === true ? '<i class='fas fa-check-circle text-green-600"></i> OK' : ($status === false ? '<i class='fas fa-times-circle text-red-600"></i> ERROR' : '<i class='fas fa-info-circle text-blue-600"></i> N/A');
    echo "<tr><td>{$test}</td><td>{$statusText}</td></tr>";
}
echo "</table>";

$totalTests = count(array_filter($tests, fn($s) => $s !== 'N/A'));
$passedTests = count(array_filter($tests, fn($s) => $s === true));
$percentage = $totalTests > 0 ? round(($passedTests / $totalTests) * 100, 2) : 0;

echo "<h3>Resultado: {$passedTests}/{$totalTests} tests pasados ({$percentage}%)</h3>";
echo "</div>";

// Enlaces útiles
echo "<div class='test-section'>";
echo "<h2><i class='fas fa-link'></i> Enlaces Útiles</h2>";
echo "<ul>";
echo "<li><a href='index.php'>Dashboard Principal</a></li>";
echo "<li><a href='login.php'>Login Sistema v1</a></li>";
echo "<li><a href='api/v1/auth.php?action=check'>Test API Auth</a></li>";
echo "<li><a href='test.php'>Recargar Test</a></li>";
echo "</ul>";
echo "</div>";

echo "</body></html>";
