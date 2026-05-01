<?php
/**
 * Servidor HTTP para facturatest.php
 * Este script configura un servidor web integrado de PHP para servir facturatest.php
 * en localhost:8000
 */

// Configuración del servidor
$host = 'localhost';
$port = 8000;
$docroot = __DIR__;

echo "=== Servidor SIFEN ===\n";
echo "Iniciando servidor HTTP para facturatest.php...\n";
echo "Host: {$host}\n";
echo "Puerto: {$port}\n";
echo "Directorio: {$docroot}\n";
echo "URL: http://{$host}:{$port}/facturatest.php\n";
echo "\nPresiona Ctrl+C para detener el servidor\n";
echo "========================\n\n";

// Verificar que facturatest.php existe
if (!file_exists($docroot . '/facturatest.php')) {
    die("Error: No se encontró facturatest.php en el directorio actual\n");
}

// Configurar el comando del servidor
$command = sprintf(
    'php -S %s:%d -t %s',
    escapeshellarg($host),
    $port,
    escapeshellarg($docroot)
);

// Ejecutar el servidor
echo "Ejecutando: {$command}\n\n";
passthru($command);
?>