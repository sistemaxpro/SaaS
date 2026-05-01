<?php
/**
 * EJEMPLO DE USO PARA SCRIPTCASE
 * 
 * Este archivo demuestra cómo usar correctamente los archivos de SIFEN
 * desde ScriptCase sin que se ejecuten automáticamente en la consola.
 * 
 * IMPORTANTE: Los archivos han sido modificados para que NO se ejecuten
 * automáticamente cuando son incluidos desde ScriptCase.
 */

// ========================================
// EJEMPLO 1: INCLUIR ARCHIVOS SIN EJECUCIÓN AUTOMÁTICA
// ========================================

// Incluir payloado_scriptcase.php sin que se ejecute automáticamente
require_once 'payloado_scriptcase.php';

// Incluir facturatest.php sin que se ejecute automáticamente
require_once 'facturatest.php';

echo "✅ Archivos incluidos correctamente sin ejecución automática\n";

// ========================================
// EJEMPLO 2: USAR FUNCIONES AUXILIARES INDIVIDUALMENTE
// ========================================

/**
 * Función para enviar un payload personalizado a SIFEN
 * Útil para eventos de ScriptCase como onAfterInsert, onAfterUpdate
 */
function enviarFacturaSIFEN($datos_factura) {
    // Configurar endpoint
    $url_endpoint = 'http://localhost:8000/facturatest.php';
    
    // Construir payload personalizado
    $payload = [
        'emisor' => [
            'ruc' => $datos_factura['ruc_emisor'],
            'dv' => $datos_factura['dv_emisor'],
            'razon_social' => $datos_factura['razon_social_emisor'],
            'tipo_contribuyente' => '2',
            'ciudad' => 1,
            'direccion' => $datos_factura['direccion_emisor'],
            'telefono' => $datos_factura['telefono_emisor'],
            'email' => $datos_factura['email_emisor'],
            'act_eco' => '49231',
            'act_eco_desc' => 'TRANSPORTE TERRESTRE LOCAL DE CARGA'
        ],
        'receptor' => [
            'documento' => $datos_factura['documento_receptor'],
            'tipo_doc' => 'ci',
            'razon_social' => $datos_factura['nombre_receptor'],
            'direccion' => $datos_factura['direccion_receptor'],
            'telefono' => $datos_factura['telefono_receptor'],
            'email' => $datos_factura['email_receptor'],
            'ciudad' => 1
        ],
        'conceptos' => $datos_factura['conceptos'], // Array de conceptos
        'factura' => [
            'ndoc' => $datos_factura['numero_factura'],
            'condicion' => $datos_factura['condicion_pago'],
            'timbrado' => $datos_factura['timbrado'],
            'fec_timbrado' => $datos_factura['fecha_timbrado'],
            'cod_establecimiento' => '001',
            'cod_expedicion' => '001',
            'moneda' => $datos_factura['moneda'],
            'cambio' => $datos_factura['tipo_cambio']
        ],
        'sifen_config' => [
            'certificado' => '/var/www/html/php-sifen2.3/certificados/' . $datos_factura['ruc_emisor'] . '.p12',
            'password' => '12345678',
            'ambiente' => 'prod',
            'idc' => '1',
            'csc' => 'f5157Ae805c58f2eeB8eA1eB7649f864'
        ]
    ];
    
    // Usar la función auxiliar para enviar
    $resultado = enviarPayloadSIFEN($url_endpoint, $payload);
    
    return $resultado;
}

// ========================================
// EJEMPLO 3: USO EN EVENTOS DE SCRIPTCASE
// ========================================

/**
 * EJEMPLO PARA EVENTO onAfterInsert EN SCRIPTCASE
 * 
 * Usar este código en el evento onAfterInsert de un formulario
 * de facturación en ScriptCase:
 */
/*
// En el evento onAfterInsert de ScriptCase:
require_once 'scriptcase_ejemplo_uso.php';

// Preparar datos de la factura recién insertada
$datos_factura = [
    'ruc_emisor' => {ruc_empresa},
    'dv_emisor' => {dv_empresa},
    'razon_social_emisor' => {razon_social_empresa},
    'direccion_emisor' => {direccion_empresa},
    'telefono_emisor' => {telefono_empresa},
    'email_emisor' => {email_empresa},
    'documento_receptor' => {documento_cliente},
    'nombre_receptor' => {nombre_cliente},
    'direccion_receptor' => {direccion_cliente},
    'telefono_receptor' => {telefono_cliente},
    'email_receptor' => {email_cliente},
    'numero_factura' => {numero_factura},
    'condicion_pago' => {condicion_pago},
    'timbrado' => {timbrado},
    'fecha_timbrado' => {fecha_vencimiento_timbrado},
    'moneda' => {moneda},
    'tipo_cambio' => {tipo_cambio},
    'conceptos' => [
        [
            'codigo' => {codigo_producto},
            'descripcion' => {descripcion_producto},
            'precio' => {precio_unitario},
            'cantidad' => {cantidad},
            'tasa_iva' => {tasa_iva},
            'descuento' => {descuento},
            'proporcion_iva' => 25,
            'unidad_medida' => '77'
        ]
        // Agregar más conceptos según sea necesario
    ]
];

// Enviar factura a SIFEN
$resultado_sifen = enviarFacturaSIFEN($datos_factura);

// Procesar resultado
if ($resultado_sifen['success']) {
    $respuesta = json_decode($resultado_sifen['response'], true);
    if ($respuesta['success']) {
        // Actualizar registro con datos de SIFEN
        $id_sifen = $respuesta['datos']['id'];
        $cdc = $respuesta['datos']['prot_aut'];
        $qr_url = $respuesta['datos']['qr_url'];
        
        // Actualizar en base de datos
        sc_exec_sql("UPDATE facturas SET id_sifen = '$id_sifen', cdc = '$cdc', qr_url = '$qr_url' WHERE id = {id_factura}");
        
        sc_alert('Factura enviada exitosamente a SIFEN. CDC: ' . $cdc);
    } else {
        sc_error_message('Error al procesar factura en SIFEN: ' . $respuesta['error']);
    }
} else {
    sc_error_message('Error de comunicación con SIFEN: ' . $resultado_sifen['error']);
}
*/

// ========================================
// EJEMPLO 4: FUNCIONES AUXILIARES DISPONIBLES
// ========================================

/**
 * FUNCIONES DISPONIBLES DESPUÉS DE INCLUIR LOS ARCHIVOS:
 * 
 * 1. enviarPayloadSIFEN($url, $payload)
 *    - Envía un payload a SIFEN via cURL
 *    - Retorna array con success, http_code, response, error
 * 
 * 2. mostrarResultado($titulo, $resultado)
 *    - Muestra resultados formateados (útil para debugging)
 * 
 * 3. enviarRespuesta($data, $status)
 *    - Envía respuesta JSON (desde facturatest.php)
 * 
 * 4. validarDatos($data, $campos_requeridos)
 *    - Valida campos requeridos en un array
 */

// ========================================
// EJEMPLO 5: CONSULTA DE LOTE
// ========================================

function consultarLoteSIFEN($id_lote) {
    $url_consulta = "http://localhost:8000/facturatest.php?lote=$id_lote";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url_consulta,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'success' => $http_code == 200,
        'http_code' => $http_code,
        'response' => $response,
        'error' => ''
    ];
}

// ========================================
// EJEMPLO 6: ANULACIÓN DE FACTURA
// ========================================

function anularFacturaSIFEN($id_factura) {
    $url_anular = "http://localhost:8000/facturatest.php?anular=$id_factura";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url_anular,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'success' => $http_code == 200,
        'http_code' => $http_code,
        'response' => $response,
        'error' => ''
    ];
}

// ========================================
// EJEMPLO DE USO COMPLETO
// ========================================

echo "\n🧪 EJEMPLO DE USO COMPLETO:\n";
echo "================================\n";

// Datos de ejemplo
$datos_ejemplo = [
    'ruc_emisor' => '80062286',
    'dv_emisor' => '3',
    'razon_social_emisor' => 'TRANSPARAGUAY LOGISTICS SOCIEDAD ANONIMA',
    'direccion_emisor' => 'Ayolas 123, Centro',
    'telefono_emisor' => '0981123456',
    'email_emisor' => 'facturacion@transparaguay.com.py',
    'documento_receptor' => '5311558',
    'nombre_receptor' => 'YENNY BEATRIZ PAREDES GONZALEZ',
    'direccion_receptor' => 'Barrio San Pablo, Calle Principal 456',
    'telefono_receptor' => '0961268274',
    'email_receptor' => 'yenny.paredes@email.com',
    'numero_factura' => '1006',
    'condicion_pago' => 'contado',
    'timbrado' => '17993028',
    'fecha_timbrado' => '2025-04-28',
    'moneda' => 'PYG',
    'tipo_cambio' => 1,
    'conceptos' => [
        [
            'codigo' => 'SERV001',
            'descripcion' => 'Servicio de Courier Aéreo Nacional',
            'precio' => 15000,
            'cantidad' => 1,
            'tasa_iva' => 10,
            'descuento' => 0,
            'proporcion_iva' => 25,
            'unidad_medida' => '77'
        ]
    ]
];

echo "📦 Enviando factura de ejemplo...\n";
$resultado = enviarFacturaSIFEN($datos_ejemplo);

if ($resultado['success']) {
    echo "✅ Factura enviada exitosamente\n";
    $respuesta = json_decode($resultado['response'], true);
    if (isset($respuesta['datos']['id'])) {
        echo "🆔 ID SIFEN: " . $respuesta['datos']['id'] . "\n";
        echo "📋 CDC: " . $respuesta['datos']['prot_aut'] . "\n";
    }
} else {
    echo "❌ Error al enviar factura: " . $resultado['error'] . "\n";
}

echo "\n🎯 Ejemplo completado\n";
echo "================================\n";

?>