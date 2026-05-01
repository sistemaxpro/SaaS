<?php
/**
 * Archivo de prueba para factura_xml_cdc.php
 * Envía datos de prueba y muestra la respuesta
 */

// Datos de prueba para la factura
$datosFactura = [
    'ruc_emisor' => '80016875',
    'dv_emisor' => '5',
    'establecimiento' => '001',
    'expedicion' => '001',
    'secuencia' => '0000001',
    'moneda' => 'PYG',
    'total_factura' => 110000,
    'razon_social_receptor' => 'Cliente de Prueba S.A.',
    'ruc_receptor' => '80012345',
    'dv_receptor' => '1',
    'conceptos' => [
        [
            'codigo' => '001',
            'descripcion' => 'Producto de Prueba',
            'cantidad' => 1,
            'precio' => 100000,
            'precio_unitario' => 100000,
            'descuento' => 0,
            'total_concepto' => 100000
        ]
    ],
    'forma_pago' => 'contado',
    'tipo_documento' => 1, // Factura
    'condicion_operacion' => 1, // Contado
    'fecha_emision' => date('Y-m-d'),
    'hora_emision' => date('H:i:s')
];

// Convertir a JSON
$jsonPayload = json_encode($datosFactura, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

echo "<h2>Prueba del Sistema SIFEN - factura_xml_cdc.php</h2>";
echo "<h3>Datos de Prueba (JSON):</h3>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
echo htmlspecialchars($jsonPayload);
echo "</pre>";

// URL del endpoint
$url = 'http://localhost:8000/factura_xml_cdc.php';

echo "<h3>Enviando petición a: $url</h3>";

// Configurar cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($jsonPayload)
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

// Ejecutar la petición
$inicio = microtime(true);
$respuesta = curl_exec($ch);
$tiempoEjecucion = round((microtime(true) - $inicio) * 1000, 2);

// Verificar errores de cURL
if (curl_errno($ch)) {
    $error = curl_error($ch);
    echo "<div style='color: red; background: #ffe6e6; padding: 10px; border: 1px solid #ff0000; margin: 10px 0;'>";
    echo "<strong>Error de cURL:</strong> $error";
    echo "</div>";
    curl_close($ch);
    exit;
}

// Obtener información de la respuesta
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h3>Resultado de la Petición:</h3>";
echo "<p><strong>Código HTTP:</strong> $httpCode</p>";
echo "<p><strong>Tiempo de ejecución:</strong> {$tiempoEjecucion}ms</p>";

// Mostrar la respuesta
echo "<h3>Respuesta del Servidor:</h3>";
echo "<pre style='background: #f0f8ff; padding: 10px; border: 1px solid #0066cc; max-height: 400px; overflow-y: auto;'>";

if ($httpCode == 200) {
    // Intentar decodificar JSON
    $respuestaJson = json_decode($respuesta, true);
    
    if (json_last_error() === JSON_ERROR_NONE) {
        // Es JSON válido, mostrarlo formateado
        echo htmlspecialchars(json_encode($respuestaJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // Mostrar información específica si está disponible
        if (isset($respuestaJson['success']) && $respuestaJson['success']) {
            echo "\n\n--- INFORMACIÓN ADICIONAL ---\n";
            if (isset($respuestaJson['cdc'])) {
                echo "CDC: " . $respuestaJson['cdc'] . "\n";
            }
            if (isset($respuestaJson['qr_url'])) {
                echo "URL QR: " . $respuestaJson['qr_url'] . "\n";
            }
            if (isset($respuestaJson['numero_factura'])) {
                echo "Número de Factura: " . $respuestaJson['numero_factura'] . "\n";
            }
        }
    } else {
        // No es JSON, mostrar como texto
        echo htmlspecialchars($respuesta);
    }
} else {
    echo "<span style='color: red;'>Error HTTP $httpCode</span>\n";
    echo htmlspecialchars($respuesta);
}

echo "</pre>";

// Mostrar enlace para volver a probar
echo "<p><a href='" . $_SERVER['PHP_SELF'] . "' style='background: #007cba; color: white; padding: 8px 16px; text-decoration: none; border-radius: 4px;'>Ejecutar Nueva Prueba</a></p>";

// Mostrar información del sistema
echo "<hr>";
echo "<h3>Información del Sistema:</h3>";
echo "<ul>";
echo "<li><strong>Fecha/Hora:</strong> " . date('Y-m-d H:i:s') . "</li>";
echo "<li><strong>Servidor PHP:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Desconocido') . "</li>";
echo "<li><strong>Versión PHP:</strong> " . PHP_VERSION . "</li>";
echo "<li><strong>Extensiones cURL:</strong> " . (extension_loaded('curl') ? 'Disponible' : 'No disponible') . "</li>";
echo "<li><strong>Extensiones JSON:</strong> " . (extension_loaded('json') ? 'Disponible' : 'No disponible') . "</li>";
echo "</ul>";

?>