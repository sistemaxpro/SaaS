<?php
/**
 * Archivo de prueba para verificar el servidor SIFEN
 * Este archivo simula los datos que normalmente vendrían de ScriptCase
 */

// Función para enviar payload al servidor
function enviarPayloadSIFEN($payload) {
    $url = 'http://localhost:8000/facturatest.php';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return [
            'success' => false,
            'http_code' => $httpCode,
            'response' => json_encode(['error' => 'Error cURL: ' . $error]),
            'error' => 'Error cURL: ' . $error
        ];
    }
    
    return [
        'success' => true,
        'http_code' => $httpCode,
        'response' => $response,
        'error' => ''
    ];
}

// Datos de prueba simulando ScriptCase
$payload = [
    'emisor' => [
        'ruc' => '80016875-5',
        'razonSocial' => 'Empresa de Prueba S.A.',
        'nombreFantasia' => 'Empresa Prueba',
        'actividadEconomica' => '12345',
        'telefono' => '021-123456',
        'email' => 'empresa@prueba.com',
        'direccion' => 'Av. Principal 123',
        'numeroCasa' => '123',
        'departamento' => 11,
        'distrito' => 143,
        'ciudad' => 3344,
        'tipoContribuyente' => 1
    ],
    'receptor' => [
        'tipoDocumento' => 1,
        'numeroDocumento' => '12345678',
        'razonSocial' => 'Cliente de Prueba',
        'email' => 'cliente@prueba.com',
        'telefono' => '021-654321',
        'direccion' => 'Calle Secundaria 456',
        'numeroCasa' => '456',
        'departamento' => 11,
        'distrito' => 143,
        'ciudad' => 3344
    ],
    'conceptos' => [
        [
            'codigo' => 'PROD001',
            'descripcion' => 'Producto de Prueba',
            'cantidad' => 2,
            'unidadMedida' => 77,
            'precioUnitario' => 50000,
            'descuento' => 0,
            'tipoIVA' => 1,
            'ivaPorcentaje' => 10
        ]
    ],
    'factura' => [
        'tipoDocumento' => 1,
        'establecimiento' => 1,
        'puntoExpedicion' => 1,
        'numero' => 1,
        'fecha' => date('Y-m-d'),
        'tipoEmision' => 1,
        'tipoTransaccion' => 1,
        'tipoImpuesto' => 1,
        'moneda' => 'PYG',
        'condicionOperacion' => 1,
        'observaciones' => 'Factura de prueba'
    ],
    'formasPago' => [
        [
            'tipo' => 1,
            'monto' => 110000,
            'moneda' => 'PYG'
        ]
    ],
    'sifen' => [
        'ambiente' => 2,
        'version' => 150
    ]
];

echo "=== PRUEBA DEL SERVIDOR SIFEN ===\n\n";

// Convertir payload a JSON
$payload_json = json_encode($payload);

echo "=== PAYLOAD ENVIADO ===\n";
echo "<pre>";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "</pre>\n\n";

// Enviar payload
echo "Enviando payload al servidor...\n\n";
$resultado = enviarPayloadSIFEN($payload_json);

echo "=== RESPUESTA DEL SERVIDOR SIFEN ===\n";
echo "Estado: " . ($resultado['success'] ? 'ÉXITO' : 'ERROR') . "\n";
echo "Código HTTP: " . $resultado['http_code'] . "\n";

if (!empty($resultado['error'])) {
    echo "Error: " . $resultado['error'] . "\n";
}

echo "\n=== RESPUESTA COMPLETA ===\n";
echo "<pre>";

// Intentar decodificar la respuesta JSON
$response_decoded = json_decode($resultado['response'], true);
if ($response_decoded) {
    echo json_encode($response_decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo $resultado['response'];
}

echo "</pre>\n";

echo "\n=== FIN DE LA PRUEBA ===\n";
?>