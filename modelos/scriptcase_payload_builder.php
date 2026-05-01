<?php
/**
 * ScriptCase Payload Builder para SIFEN
 * 
 * Este archivo contiene funciones para construir el payload.json
 * desde las tablas de la base de datos y enviarlo al API facturas_sifen.php
 * 
 * Compatible con ScriptCase - Estructura modular y limpia
 * 
 * @author Sistema SIFEN
 * @version 1.0
 */

class SifenPayloadBuilder {
    
    private $db_path;
    private $api_url;
    private $pdo;
    
    /**
     * Constructor
     * 
     * @param string $db_path Ruta a la base de datos SQLite
     * @param string $api_url URL del API facturas_sifen.php
     */
    public function __construct($db_path = 'src/db/sifen.db', $api_url = 'http://localhost/facturas_sifen.php') {
        $this->db_path = $db_path;
        $this->api_url = $api_url;
        $this->conectarBaseDatos();
    }
    
    /**
     * Conectar a la base de datos SQLite
     */
    private function conectarBaseDatos() {
        try {
            $this->pdo = new PDO('sqlite:' . $this->db_path);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            throw new Exception('Error conectando a la base de datos: ' . $e->getMessage());
        }
    }
    
    /**
     * Obtener datos del emisor desde la base de datos
     * 
     * @param string $ruc RUC del emisor
     * @return array Datos del emisor
     */
    public function obtenerDatosEmisor($ruc = '80062286-1') {
        // En un caso real, estos datos vendrían de una tabla 'emisores'
        // Por ahora usamos datos de ejemplo que pueden ser configurados
        
        $emisor = [
            'ruc' => $ruc,
            'razon_social' => 'Empresa de Ejemplo S.A.',
            'nombre_fantasia' => 'Ejemplo',
            'actividades_economicas' => [
                [
                    'codigo' => '47300',
                    'descripcion' => 'Venta al por menor de combustible para vehículos automotores en comercios especializados'
                ]
            ],
            'direccion' => $this->obtenerDireccionGeografica(11, 143, 3344, 'Av. Ejemplo 123'),
            'telefono' => '021-123456',
            'email' => 'ejemplo@empresa.com'
        ];
        
        return $emisor;
    }
    
    /**
     * Obtener datos del receptor desde la base de datos
     * 
     * @param int $cliente_id ID del cliente
     * @return array Datos del receptor
     */
    public function obtenerDatosReceptor($cliente_id) {
        // En un caso real, consultaría una tabla 'clientes'
        // Por ahora simulamos la consulta
        
        try {
            // Ejemplo de consulta (adaptar según estructura real de tablas)
            /*
            $stmt = $this->pdo->prepare("
                SELECT documento_tipo, documento_numero, razon_social, 
                       departamento, distrito, ciudad, direccion, telefono, email
                FROM clientes 
                WHERE id = ?
            ");
            $stmt->execute([$cliente_id]);
            $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
            */
            
            // Datos de ejemplo - reemplazar con consulta real
            $receptor = [
                'documento_tipo' => 1,
                'documento_numero' => '12345678',
                'razon_social' => 'Cliente de Ejemplo',
                'direccion' => $this->obtenerDireccionGeografica(11, 143, 3344, 'Calle Cliente 456'),
                'telefono' => '021-654321',
                'email' => 'cliente@ejemplo.com'
            ];
            
            return $receptor;
            
        } catch (PDOException $e) {
            throw new Exception('Error obteniendo datos del receptor: ' . $e->getMessage());
        }
    }
    
    /**
     * Obtener dirección geográfica desde la tabla ref_geografica
     * 
     * @param int $cod_dep Código de departamento
     * @param int $cod_dis Código de distrito
     * @param int $cod_ciu Código de ciudad
     * @param string $direccion Dirección específica
     * @return array Datos de dirección
     */
    public function obtenerDireccionGeografica($cod_dep, $cod_dis, $cod_ciu, $direccion) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COD_DEP, DESC_DEP, COD_DIS, DESC_DIS, COD_CIU, DESC_CIU
                FROM ref_geografica 
                WHERE COD_DEP = ? AND COD_DIS = ? AND COD_CIU = ?
                LIMIT 1
            ");
            $stmt->execute([$cod_dep, $cod_dis, $cod_ciu]);
            $geo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'departamento' => $cod_dep,
                'distrito' => $cod_dis,
                'ciudad' => $cod_ciu,
                'direccion' => $direccion
            ];
            
        } catch (PDOException $e) {
            // Si no encuentra, usar valores por defecto
            return [
                'departamento' => $cod_dep,
                'distrito' => $cod_dis,
                'ciudad' => $cod_ciu,
                'direccion' => $direccion
            ];
        }
    }
    
    /**
     * Obtener conceptos/productos desde la base de datos
     * 
     * @param int $factura_id ID de la factura
     * @return array Array de conceptos
     */
    public function obtenerConceptos($factura_id) {
        // En un caso real, consultaría tablas 'factura_detalles' y 'productos'
        
        try {
            // Ejemplo de consulta (adaptar según estructura real)
            /*
            $stmt = $this->pdo->prepare("
                SELECT p.codigo, p.descripcion, fd.cantidad, p.unidad_medida,
                       fd.precio_unitario, fd.descuento, fd.iva_tipo, 
                       fd.iva_base, fd.iva
                FROM factura_detalles fd
                JOIN productos p ON fd.producto_id = p.id
                WHERE fd.factura_id = ?
            ");
            $stmt->execute([$factura_id]);
            $conceptos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            */
            
            // Datos de ejemplo - reemplazar con consulta real
            $conceptos = [
                [
                    'codigo' => '001',
                    'descripcion' => 'Producto de ejemplo',
                    'cantidad' => 2,
                    'unidad_medida' => 77,
                    'precio_unitario' => 50000,
                    'descuento' => 0,
                    'iva_tipo' => 1,
                    'iva_base' => 90909,
                    'iva' => 9091
                ]
            ];
            
            return $conceptos;
            
        } catch (PDOException $e) {
            throw new Exception('Error obteniendo conceptos: ' . $e->getMessage());
        }
    }
    
    /**
     * Obtener formas de pago desde la base de datos
     * 
     * @param int $factura_id ID de la factura
     * @return array Array de formas de pago
     */
    public function obtenerFormasPago($factura_id) {
        // En un caso real, consultaría tabla 'factura_pagos'
        
        try {
            // Ejemplo de consulta (adaptar según estructura real)
            /*
            $stmt = $this->pdo->prepare("
                SELECT forma_pago, monto, moneda
                FROM factura_pagos
                WHERE factura_id = ?
            ");
            $stmt->execute([$factura_id]);
            $formas_pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            */
            
            // Datos de ejemplo - reemplazar con consulta real
            $formas_pagos = [
                [
                    'forma_pago' => 1,
                    'monto' => 100000,
                    'moneda' => 'PYG'
                ]
            ];
            
            return $formas_pagos;
            
        } catch (PDOException $e) {
            throw new Exception('Error obteniendo formas de pago: ' . $e->getMessage());
        }
    }
    
    /**
     * Construir el payload completo para SIFEN
     * 
     * @param array $parametros Parámetros de la factura
     * @return array Payload completo
     */
    public function construirPayload($parametros) {
        try {
            // Validar parámetros requeridos
            $this->validarParametros($parametros);
            
            // Obtener datos desde la base
            $emisor = $this->obtenerDatosEmisor($parametros['ruc_emisor'] ?? '80062286-1');
            $receptor = $this->obtenerDatosReceptor($parametros['cliente_id']);
            $conceptos = $this->obtenerConceptos($parametros['factura_id']);
            $formas_pagos = $this->obtenerFormasPago($parametros['factura_id']);
            
            // Construir payload
            $payload = [
                'emisor' => $emisor,
                'receptor' => $receptor,
                'conceptos' => $conceptos,
                'formas_pagos' => $formas_pagos,
                'ndoc' => $parametros['ndoc'],
                'condicion' => $parametros['condicion'] ?? 1,
                'timbrado' => $parametros['timbrado'],
                'fec_timbrado' => $parametros['fec_timbrado'],
                'cod_establecimiento' => $parametros['cod_establecimiento'] ?? '001',
                'cod_expedicion' => $parametros['cod_expedicion'] ?? '001',
                'moneda' => $parametros['moneda'] ?? 'PYG',
                'cambio' => $parametros['cambio'] ?? 1,
                'plazo' => $parametros['plazo'] ?? null
            ];
            
            return $payload;
            
        } catch (Exception $e) {
            throw new Exception('Error construyendo payload: ' . $e->getMessage());
        }
    }
    
    /**
     * Validar parámetros requeridos
     * 
     * @param array $parametros
     * @throws Exception
     */
    private function validarParametros($parametros) {
        $requeridos = ['cliente_id', 'factura_id', 'ndoc', 'timbrado', 'fec_timbrado'];
        
        foreach ($requeridos as $campo) {
            if (!isset($parametros[$campo]) || empty($parametros[$campo])) {
                throw new Exception("Campo requerido faltante: {$campo}");
            }
        }
    }
    
    /**
     * Enviar payload al API SIFEN vía curl
     * 
     * @param array $payload Datos a enviar
     * @return array Respuesta del API
     */
    public function enviarFactura($payload) {
        try {
            // Convertir payload a JSON
            $json_payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            
            if ($json_payload === false) {
                throw new Exception('Error codificando JSON: ' . json_last_error_msg());
            }
            
            // Configurar curl
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->api_url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json_payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($json_payload)
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            
            // Ejecutar petición
            $respuesta = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);
            
            // Verificar errores de curl
            if ($respuesta === false || !empty($error)) {
                throw new Exception('Error en curl: ' . $error);
            }
            
            // Verificar código HTTP
            if ($http_code !== 200) {
                throw new Exception("Error HTTP {$http_code}: {$respuesta}");
            }
            
            // Intentar decodificar respuesta JSON
            $respuesta_json = json_decode($respuesta, true);
            
            return [
                'success' => true,
                'http_code' => $http_code,
                'response' => $respuesta_json ?? $respuesta,
                'raw_response' => $respuesta
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'http_code' => $http_code ?? 0
            ];
        }
    }
    
    /**
     * Procesar factura completa (construir payload y enviar)
     * 
     * @param array $parametros Parámetros de la factura
     * @return array Resultado del procesamiento
     */
    public function procesarFactura($parametros) {
        try {
            // Construir payload
            $payload = $this->construirPayload($parametros);
            
            // Enviar al API
            $resultado = $this->enviarFactura($payload);
            
            // Agregar payload al resultado para debugging
            $resultado['payload'] = $payload;
            
            return $resultado;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Guardar payload como archivo JSON (para debugging)
     * 
     * @param array $payload
     * @param string $filename
     * @return bool
     */
    public function guardarPayloadJson($payload, $filename = 'payload_generated.json') {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return file_put_contents($filename, $json) !== false;
        } catch (Exception $e) {
            return false;
        }
    }
}

// ============================================================================
// FUNCIONES DE EJEMPLO PARA USO EN SCRIPTCASE
// ============================================================================

/**
 * Función principal para usar en ScriptCase
 * 
 * @param array $parametros Parámetros de la factura
 * @return array Resultado del procesamiento
 */
function procesar_factura_sifen($parametros) {
    try {
        $builder = new SifenPayloadBuilder();
        return $builder->procesarFactura($parametros);
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Error inicializando: ' . $e->getMessage()
        ];
    }
}

/**
 * Ejemplo de uso básico
 */
function ejemplo_uso() {
    // Parámetros de ejemplo
    $parametros = [
        'cliente_id' => 1,
        'factura_id' => 1,
        'ndoc' => '001-001-0000001',
        'timbrado' => '12345678',
        'fec_timbrado' => '2024-12-31',
        'condicion' => 1,
        'moneda' => 'PYG',
        'cambio' => 1
    ];
    
    // Procesar factura
    $resultado = procesar_factura_sifen($parametros);
    
    // Mostrar resultado
    if ($resultado['success']) {
        echo "Factura procesada exitosamente\n";
        print_r($resultado['response']);
    } else {
        echo "Error: " . $resultado['error'] . "\n";
    }
    
    return $resultado;
}

// Ejecutar ejemplo si se llama directamente
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    echo "<h1>Ejemplo de uso - SIFEN Payload Builder</h1>";
    echo "<pre>";
    ejemplo_uso();
    echo "</pre>";
}

?>