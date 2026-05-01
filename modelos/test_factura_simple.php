<?php
/**
 * Prueba simple para factura_xml_cdc.php
 * Captura solo la respuesta JSON sin warnings
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
    'tipo_documento' => 1,
    'condicion_operacion' => 1,
    'fecha_emision' => date('Y-m-d'),
    'hora_emision' => date('H:i:s')
];

// Convertir a JSON
$jsonPayload = json_encode($datosFactura);

echo "=== PRUEBA DEL SISTEMA SIFEN ===\n";
echo "Fecha/Hora: " . date('Y-m-d H:i:s') . "\n";
echo "URL: http://localhost:8000/factura_xml_cdc.php\n";
echo "\n--- DATOS ENVIADOS ---\n";
echo json_encode($datosFactura, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// Configurar cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost:8000/factura_xml_cdc.php');
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
    echo "\n--- ERROR DE CURL ---\n";
    echo curl_error($ch) . "\n";
    curl_close($ch);
    exit(1);
}

// Obtener información de la respuesta
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\n--- RESULTADO ---\n";
echo "Código HTTP: $httpCode\n";
echo "Tiempo: {$tiempoEjecucion}ms\n";

echo "\n--- RESPUESTA CRUDA ---\n";
echo $respuesta . "\n";

// Intentar extraer JSON de la respuesta
if (preg_match('/\{.*\}/', $respuesta, $matches)) {
    $jsonRespuesta = $matches[0];
    echo "\n--- JSON EXTRAÍDO ---\n";
    echo $jsonRespuesta . "\n";
    
    $respuestaDecodificada = json_decode($jsonRespuesta, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "\n--- JSON FORMATEADO ---\n";
        echo json_encode($respuestaDecodificada, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        
        if (isset($respuestaDecodificada['success']) && $respuestaDecodificada['success']) {
            echo "\n--- ÉXITO ---\n";
            echo "✓ Factura generada correctamente\n";
            if (isset($respuestaDecodificada['cdc'])) {
                echo "CDC: " . $respuestaDecodificada['cdc'] . "\n";
            }
            if (isset($respuestaDecodificada['qr_url'])) {
                echo "QR URL: " . $respuestaDecodificada['qr_url'] . "\n";
            }
            if (isset($respuestaDecodificada['numero_factura'])) {
                echo "Número: " . $respuestaDecodificada['numero_factura'] . "\n";
            }
        } else {
            echo "\n--- ERROR ---\n";
            echo "✗ Error en la generación de factura\n";
            if (isset($respuestaDecodificada['error'])) {
                echo "Error: " . $respuestaDecodificada['error'] . "\n";
            }
        }
    } else {
        echo "\n--- ERROR JSON ---\n";
        echo "No se pudo decodificar el JSON extraído\n";
    }
} else {
    echo "\n--- SIN JSON ---\n";
    echo "No se encontró JSON válido en la respuesta\n";
}

echo "\n=== FIN DE LA PRUEBA ===\n";
?>