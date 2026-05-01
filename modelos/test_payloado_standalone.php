<?php
// Test standalone version of payloado_scriptcase.php
// This version uses mock data instead of ScriptCase functions

// Mock data to simulate ScriptCase database queries
$fact = [
    'id_cliente' => '1',
    'nro_factura' => '001-001-0001006',
    'id_moneda' => 1
];

$emp = [
    'ruc' => '80062286-3',
    'empresa' => 'TRANS PARAGUAY S.A.'
];

$cli = [
    'nombre' => 'YENNY BEATRIZ PAREDES GONZALEZ'
];

$item = [];

list($ruc, $dv) = explode('-', $emp['ruc']);
list($estab, $exped, $secuencia) = explode('-', $fact['nro_factura']);

$fact_id_mon = $fact['id_moneda'];
// Datos de la factura

switch ($fact_id_mon) {
    case 1:
        $mon = 'PYG';
        break;
    case 2:
        $mon = 'USD';
        break;
    case 3:
        $mon = 'BRL'; // corregido: BRL (no RBR)
        break;
    default:
        $mon = 'PYG';
        break;
}
/////////////////

// Configuración del endpoint
$url_endpoint = 'http://localhost:8000/facturatest.php';

// Función para enviar payload via cURL
function enviarPayloadSIFEN($url, $payload) {
    // Inicializar cURL
    $ch = curl_init();
    
    // Configurar opciones de cURL
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: SIFEN-PHP-Client/1.0'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3
    ]);
    
    // Ejecutar la petición
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    // Cerrar cURL
    curl_close($ch);
    
    return [
        'success' => empty($error) && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $error
    ];
}

// Función para mostrar resultados formateados
function mostrarResultado($titulo, $resultado) {
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "📋 $titulo\n";
    echo str_repeat("=", 60) . "\n";
    
    if ($resultado['success']) {
        echo "✅ Estado: ÉXITO\n";
        echo "🌐 Código HTTP: {$resultado['http_code']}\n";
        
        // Intentar decodificar la respuesta JSON
        $json_response = json_decode($resultado['response'], true);
        if ($json_response) {
            echo "\n📄 Respuesta JSON:\n";
            echo json_encode($json_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            
            // Mostrar información específica si es una factura exitosa
            if (isset($json_response['success']) && $json_response['success'] && isset($json_response['datos'])) {
                $datos = $json_response['datos'];
                echo "\n🧾 INFORMACIÓN DE LA FACTURA:\n";
                echo "   • ID: " . ($datos['id'] ?? 'N/A') . "\n";
                echo "   • Estado: " . ($datos['est_res'] ?? 'N/A') . "\n";
                echo "   • Código: " . ($datos['cod_res'] ?? 'N/A') . "\n";
                echo "   • Mensaje: " . ($datos['msg_res'] ?? 'N/A') . "\n";
                if (isset($datos['qr_url'])) {
                    echo "   • QR URL: {$datos['qr_url']}\n";
                }
            }
        } else {
            echo "\n📄 Respuesta Raw:\n";
            echo $resultado['response'] . "\n";
        }
    } else {
        echo "❌ Estado: ERROR\n";
        echo "🌐 Código HTTP: {$resultado['http_code']}\n";
        if (!empty($resultado['error'])) {
            echo "🚨 Error cURL: {$resultado['error']}\n";
        }
        echo "\n📄 Respuesta:\n";
        echo $resultado['response'] . "\n";
    }
}

// ========================================
// CONFIGURACIÓN DE DATOS PARA EL PAYLOAD
// ========================================

echo "🚀 GENERADOR DE PAYLOAD SIFEN (VERSIÓN STANDALONE)\n";
echo "Fecha y hora: " . date('Y-m-d H:i:s') . "\n";
echo "Endpoint: $url_endpoint\n";

// 1. DATOS DEL EMISOR
$datos_emisor = [
    'ruc' => $ruc,
    'dv' => $dv,
    'razon_social' => $emp['empresa'],
    'tipo_contribuyente' => '2', // 1=Persona Física, 2=Persona Jurídica
    'ciudad' => 1, // Código de ciudad (1=Asunción)
    'direccion' => 'Ayolas 123, Centro',
    'telefono' => '0981123456',
    'email' => 'facturacion@transparaguay.com.py',
    'act_eco' => '49231', // Código de actividad económica
    'act_eco_desc' => 'TRANSPORTE TERRESTRE LOCAL DE CARGA'
];

// 2. DATOS DEL RECEPTOR (CLIENTE)
$datos_receptor = [
    'documento' => '5311558',
    'tipo_doc' => 'ci', // ci=Cédula, ruc=RUC, pas=Pasaporte
    'razon_social' => 'YENNY BEATRIZ PAREDES GONZALEZ',
    'direccion' => 'Barrio San Pablo, Calle Principal 456',
    'telefono' => '0961268274',
    'email' => 'yenny.paredes@email.com',
    'ciudad' => 1
];

// 3. CONCEPTOS/PRODUCTOS DE LA FACTURA
$conceptos = [
    [
        'codigo' => 'SERV001',
        'descripcion' => 'Servicio de Courier Aéreo Nacional',
        'precio' => 15000, // Precio unitario en guaraníes
        'cantidad' => 1,
        'tasa_iva' => 10, // 5% o 10%
        'descuento' => 0,
        'proporcion_iva' => 25, // Proporción gravada con IVA
        'unidad_medida' => '77' // Código de unidad de medida
    ],
    [
        'codigo' => 'SERV002',
        'descripcion' => 'Seguro de Mercadería',
        'precio' => 5000,
        'cantidad' => 1,
        'tasa_iva' => 10,
        'descuento' => 500, // Descuento aplicado
        'proporcion_iva' => 25,
        'unidad_medida' => '77'
    ],
    [
        'codigo' => 'PROD001',
        'descripcion' => 'Embalaje Especial',
        'precio' => 3000,
        'cantidad' => 2,
        'tasa_iva' => 5,
        'descuento' => 0,
        'proporcion_iva' => 25,
        'unidad_medida' => '77'
    ]
];

// 4. CONFIGURACIÓN DE LA FACTURA
$config_factura = [
    'ndoc' => '1006', // Número de documento
    'condicion' => 'contado', // contado o credito
    'timbrado' => '17993028', // Número de timbrado
    'fec_timbrado' => '2025-04-28', // Fecha de vencimiento del timbrado
    'cod_establecimiento' => '001',
    'cod_expedicion' => '001',
    'moneda' => 'PYG', // PYG, USD, EUR, etc.
    'cambio' => 1, // Tipo de cambio
    'plazo' => [
        'condicion' => 1, // 1=Plazo en días, 2=Cuotas
        'valor' => 30 // 30 días de plazo
    ]
];

// 5. FORMAS DE PAGO
$formas_pago = [
    [
        'tipo_pago' => 1, // 1=Efectivo, 2=Cheque, 3=Tarjeta, etc.
        'monto' => 25500, // Monto total calculado
        'moneda' => 'PYG',
        'cambio' => 1
    ]
];

// 6. CONFIGURACIÓN SIFEN
// IMPORTANTE: El certificado debe usar solo el RUC sin el dígito verificador
// Ejemplo: si el RUC es '80062286-3', el archivo debe ser '80062286.p12'
$certificado_ruc = $ruc; // Solo el RUC sin dígito verificador
$certificado_path = '/var/www/html/php-sifen2.3/certificados/' . $certificado_ruc . '.p12';

// Validar que el archivo del certificado existe
if (!file_exists($certificado_path)) {
    echo "⚠️ ADVERTENCIA: El archivo del certificado no existe: $certificado_path\n";
    echo "   Verifique que el archivo esté en la carpeta 'certificados' con el nombre correcto.\n";
    echo "   Formato esperado: {RUC_SIN_DV}.p12 (ejemplo: 80062286.p12)\n\n";
} else {
    echo "✅ Certificado encontrado: $certificado_path\n\n";
}

$sifen_config = [
    'certificado' => $certificado_path, // Ruta al certificado (solo RUC sin DV)
    'password' => '12345678', // Contraseña del certificado
    'ambiente' => 'prod', // prod o test
    'idc' => '1', // Identificador del contribuyente
    'csc' => 'f5157Ae805c58f2eeB8eA1eB7649f864' // Código de seguridad del contribuyente
];

// ========================================
// CONSTRUCCIÓN DEL PAYLOAD COMPLETO
// ========================================

$payload_completo = [
    'emisor' => $datos_emisor,
    'receptor' => $datos_receptor,
    'conceptos' => $conceptos,
    'factura' => $config_factura,
    'formas_pago' => $formas_pago,
    'sifen_config' => $sifen_config
];

echo "\n📦 PAYLOAD GENERADO:\n";
echo json_encode($payload_completo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// ========================================
// ENVÍO DEL PAYLOAD
// ========================================

echo "\n🚀 ENVIANDO PAYLOAD A SIFEN...\n";

$resultado = enviarPayloadSIFEN($url_endpoint, $payload_completo);
mostrarResultado('RESULTADO DE LA FACTURACIÓN', $resultado);

// ========================================
// EJEMPLOS ADICIONALES
// ========================================

echo "\n\n🧪 EJEMPLOS ADICIONALES\n";

// Ejemplo 1: Payload mínimo
echo "\n1️⃣ Probando con payload mínimo...\n";
$payload_minimo = [
    'emisor' => [
        'ruc' => '80062286',
        'dv' => '3',
        'razon_social' => 'EMPRESA DE PRUEBA MINIMA'
    ],
    'receptor' => [
        'documento' => '1234567',
        'tipo_doc' => 'ci',
        'razon_social' => 'CLIENTE DE PRUEBA'
    ],
    'conceptos' => [
        [
            'descripcion' => 'Producto de prueba',
            'precio' => 10000,
            'cantidad' => 1
        ]
    ]
];

$resultado_minimo = enviarPayloadSIFEN($url_endpoint, $payload_minimo);
mostrarResultado('RESULTADO - Payload Mínimo', $resultado_minimo);

// Ejemplo 2: Consulta de lote (si hay un ID disponible)
echo "\n2️⃣ Ejemplo de consulta de lote...\n";
$url_consulta_lote = $url_endpoint . '?lote=1108418391654346064';
echo "URL de consulta: $url_consulta_lote\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url_consulta_lote,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30
]);
$response_lote = curl_exec($ch);
$http_code_lote = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

mostrarResultado('RESULTADO - Consulta de Lote', [
    'success' => $http_code_lote == 200,
    'http_code' => $http_code_lote,
    'response' => $response_lote,
    'error' => ''
]);

// ========================================
// RESUMEN FINAL
// ========================================

echo "\n" . str_repeat("=", 60) . "\n";
echo "📊 RESUMEN DE EJECUCIÓN\n";
echo str_repeat("=", 60) . "\n";
echo "✅ Payload completo: " . ($resultado['success'] ? 'ÉXITO' : 'ERROR') . "\n";
echo "✅ Payload mínimo: " . ($resultado_minimo['success'] ? 'ÉXITO' : 'ERROR') . "\n";
echo "✅ Consulta de lote: " . ($http_code_lote == 200 ? 'ÉXITO' : 'ERROR') . "\n";
echo "\n🎯 Script completado exitosamente\n";
echo "📅 Fecha de finalización: " . date('Y-m-d H:i:s') . "\n";
echo str_repeat("=", 60) . "\n";

?>