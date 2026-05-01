<?php
/**
 * Script de prueba para facturatest.php con payload JSON
 * Demuestra cómo enviar datos via POST para generar facturas SIFEN
 */

// Función para enviar request POST con JSON
function enviarFactura($payload) {
    $url = 'http://localhost:8000/facturatest.php';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen(json_encode($payload))
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'http_code' => $httpCode,
        'response' => $response
    ];
}

// Función para mostrar resultados
function mostrarResultado($titulo, $resultado) {
    echo "\n" . str_repeat("=", 50) . "\n";
    echo $titulo . "\n";
    echo str_repeat("=", 50) . "\n";
    echo "HTTP Code: " . $resultado['http_code'] . "\n";
    echo "Response: " . $resultado['response'] . "\n";
    
    // Intentar decodificar JSON para mostrar formateado
    $json = json_decode($resultado['response'], true);
    if ($json) {
        echo "\nJSON Formateado:\n";
        echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// Payload de ejemplo completo
$payloadCompleto = [
    'emisor' => [
        'ruc' => '80062286',
        'dv' => '3',
        'razon_social' => 'TRANSPARAGUAY LOGISTICS SOCIEDAD ANONIMA',
        'tipo_contribuyente' => '2',
        'ciudad' => 1,
        'direccion' => 'Ayolas 123',
        'telefono' => '0981123456',
        'email' => 'hola@hola.com',
        'act_eco' => '49231',
        'act_eco_desc' => 'TRANSPORTE TERRESTRE LOCAL DE CARGA'
    ],
    'receptor' => [
        'documento' => '5311558',
        'tipo_doc' => 'ci',
        'razon_social' => 'YENNY PAREDES',
        'direccion' => 'Prueba 123',
        'telefono' => '0961268274',
        'email' => 'YENNY_BEATRIZ_17@HOTMAIL.COM',
        'ciudad' => 1
    ],
    'conceptos' => [
        [
            'codigo' => '001',
            'descripcion' => 'Courier Aereo',
            'precio' => 10000,
            'cantidad' => 1,
            'tasa_iva' => 10,
            'descuento' => 0,
            'proporcion_iva' => 25,
            'unidad_medida' => '77'
        ]
    ],
    'factura' => [
        'ndoc' => '1003',
        'condicion' => 'contado',
        'timbrado' => '17993028',
        'fec_timbrado' => '2025-04-28',
        'cod_establecimiento' => '001',
        'cod_expedicion' => '001',
        'moneda' => 'PYG',
        'cambio' => 1
    ],
    'formas_pago' => [
        [
            'tipo_pago' => 1,
            'monto' => 10000,
            'moneda' => 'PYG',
            'cambio' => 1
        ]
    ],
    'sifen_config' => [
        'certificado' => '80062286.p12',
        'password' => '12345678',
        'ambiente' => 'prod',
        'idc' => '1',
        'csc' => 'f5157Ae805c58f2eeB8eA1eB7649f864'
    ]
];

// Payload mínimo (solo campos requeridos)
$payloadMinimo = [
    'emisor' => [
        'ruc' => '80062286',
        'dv' => '3',
        'razon_social' => 'EMPRESA DE PRUEBA'
    ],
    'receptor' => [
        'documento' => '1234567',
        'tipo_doc' => 'ci',
        'razon_social' => 'CLIENTE DE PRUEBA'
    ],
    'conceptos' => [
        [
            'descripcion' => 'Producto de prueba',
            'precio' => 5000,
            'cantidad' => 1
        ]
    ]
];

// Payload con errores (para probar validación)
$payloadConErrores = [
    'emisor' => [
        'ruc' => '', // Error: RUC vacío
        'razon_social' => 'EMPRESA DE PRUEBA'
    ],
    'receptor' => [
        // Error: falta documento
        'razon_social' => 'CLIENTE DE PRUEBA'
    ],
    'conceptos' => [] // Error: array vacío
];

echo "SCRIPT DE PRUEBA - FACTURATEST.PHP CON PAYLOAD JSON\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";

// Prueba 1: Payload completo
echo "\n🧪 PRUEBA 1: Payload completo\n";
$resultado1 = enviarFactura($payloadCompleto);
mostrarResultado("RESULTADO - Payload Completo", $resultado1);

// Prueba 2: Payload mínimo
echo "\n🧪 PRUEBA 2: Payload mínimo\n";
$resultado2 = enviarFactura($payloadMinimo);
mostrarResultado("RESULTADO - Payload Mínimo", $resultado2);

// Prueba 3: Payload con errores
echo "\n🧪 PRUEBA 3: Payload con errores (validación)\n";
$resultado3 = enviarFactura($payloadConErrores);
mostrarResultado("RESULTADO - Payload con Errores", $resultado3);

// Prueba 4: JSON inválido
echo "\n🧪 PRUEBA 4: JSON inválido\n";
$url = 'http://localhost:8000/facturatest.php';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{"invalid": json}'); // JSON inválido
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

mostrarResultado("RESULTADO - JSON Inválido", [
    'http_code' => $httpCode,
    'response' => $response
]);

echo "\n" . str_repeat("=", 50) . "\n";
echo "PRUEBAS COMPLETADAS\n";
echo str_repeat("=", 50) . "\n";

?>