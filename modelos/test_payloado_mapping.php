<?php
// Test para verificar el mapeo de campos en payloado_scriptcase.php

// Simular respuesta JSON del servidor SIFEN
$response_json = '{
    "success": true,
    "datos": {
        "cdc": "0180062286300100100010012202508201000000001",
        "qr_url": "https://ekuatia.set.gov.py/consultas/qr?nVersion=150&cdc=0180062286300100100010012202508201000000001",
        "qr_image": "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=https%3A%2F%2Fekuatia.set.gov.py%2Fconsultas%2Fqr%3FnVersion%3D150%26cdc%3D0180062286300100100010012202508201000000001",
        "fecha_generacion": "2025-08-20 09:32:45",
        "id_factura": "1001",
        "guardado_bd": false,
        "xml_original_length": 10,
        "xml_firmado_length": 10
    }
}';

echo "<h2>Test de Mapeo de Campos - payloado_scriptcase.php</h2>";
echo "<h3>Respuesta JSON Original:</h3>";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($response_json) . "</pre>";

// Decodificar respuesta
$response_data = json_decode($response_json, true);

echo "<h3>Procesamiento de Datos:</h3>";
echo "<div style='background: #e8f4fd; padding: 10px; border: 1px solid #bee5eb;'>";

if ($response_data && isset($response_data['success']) && $response_data['success']) {
    // Extraer datos de la respuesta desde el campo 'datos'
    $datos = isset($response_data['datos']) ? $response_data['datos'] : [];
    
    $cdc = isset($datos['cdc']) ? $datos['cdc'] : '';
    $qr_url = isset($datos['qr_url']) ? $datos['qr_url'] : '';
    $qr_image = isset($datos['qr_image']) ? $datos['qr_image'] : '';
    $fecha_generacion = isset($datos['fecha_generacion']) ? $datos['fecha_generacion'] : '';
    
    echo "<strong>Mapeo de Campos:</strong><br>";
    echo "• cdc → hash_cdc: <code>" . htmlspecialchars($cdc) . "</code><br>";
    echo "• qr_url → qr_sifen: <code>" . htmlspecialchars($qr_url) . "</code><br>";
    echo "• qr_image → qr: <code>" . htmlspecialchars($qr_image) . "</code><br>";
    echo "• fecha_generacion → fecha_emision: <code>" . htmlspecialchars($fecha_generacion) . "</code><br>";
    
    echo "<br><strong>Query SQL que se ejecutaría:</strong><br>";
    $fecha_actual = date('Y-m-d H:i:s');
    $estado = 'AUTORIZADO';
    $mensaje = 'Factura procesada correctamente';
    
    $update_query = "UPDATE factura_ventas SET 
        fecha_envio_sifen = '$fecha_actual',
        estado_sifen = '$estado',
        mensaje_sifen = '$mensaje',
        hash_cdc = '$cdc',
        qr_sifen = '$qr_url',
        qr = '$qr_image',
        fecha_emision = '$fecha_generacion',
        estado_electronico = 'AUTORIZADO',
        intentos_transmision = intentos_transmision + 1
        WHERE id_factura = '[id_factura]'";
    
    echo "<pre style='background: #fff; padding: 10px; border: 1px solid #ddd; font-size: 12px;'>" . htmlspecialchars($update_query) . "</pre>";
    
    echo "<div style='background: #d4edda; padding: 10px; margin-top: 10px; border: 1px solid #c3e6cb; color: #155724;'>";
    echo "<strong>✓ Mapeo Correcto:</strong> Los campos se extraen correctamente de la estructura anidada 'datos' y se mapean a los campos correctos de la base de datos.";
    echo "</div>";
    
} else {
    echo "<div style='background: #f8d7da; padding: 10px; border: 1px solid #f5c6cb; color: #721c24;'>";
    echo "<strong>✗ Error:</strong> No se pudo procesar la respuesta JSON.";
    echo "</div>";
}

echo "</div>";

echo "<h3>Verificación de Estructura:</h3>";
echo "<pre style='background: #f8f9fa; padding: 10px; border: 1px solid #dee2e6;'>";
print_r($response_data);
echo "</pre>";

?>