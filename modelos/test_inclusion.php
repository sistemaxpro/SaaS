<?php
// Archivo de prueba para verificar que payloado_scriptcase.php no se ejecuta cuando se incluye

echo "=== INICIO DEL ARCHIVO DE PRUEBA ===\n";
echo "Incluyendo payloado_scriptcase.php...\n";

// Incluir el archivo modificado
include_once 'payloado_scriptcase.php';

echo "\n=== ARCHIVO INCLUIDO EXITOSAMENTE ===\n";
echo "El archivo payloado_scriptcase.php fue incluido sin ejecutarse automáticamente.\n";

// Ahora podemos usar las funciones auxiliares
echo "\n=== PROBANDO FUNCIONES AUXILIARES ===\n";
echo "Función enviarPayloadSIFEN disponible: " . (function_exists('enviarPayloadSIFEN') ? 'SÍ' : 'NO') . "\n";
echo "Función mostrarResultado disponible: " . (function_exists('mostrarResultado') ? 'SÍ' : 'NO') . "\n";
echo "Función ejecutarPayloadSIFEN disponible: " . (function_exists('ejecutarPayloadSIFEN') ? 'SÍ' : 'NO') . "\n";

echo "\n=== FIN DEL ARCHIVO DE PRUEBA ===\n";
?>