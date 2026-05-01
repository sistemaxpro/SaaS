<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'src/php-sifen.php';

// Configuración de base de datos MySQL
$db_config = [
	'host' => '168.231.95.50',
	'user' => 'sistemax',
	'password' => 'Armagedon123',
	'db_static' => 'serproc1',
	'charset' => 'utf8mb4'
];

// Función para conectar a MySQL
function conectarMySQL($config, $database = null)
{
	try {
		$dsn = "mysql:host={$config['host']};charset={$config['charset']}";
		if ($database) {
			$dsn .= ";dbname={$database}";
		}
		$pdo = new PDO($dsn, $config['user'], $config['password']);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		return $pdo;
	} catch (PDOException $e) {
		error_log("Error de conexión MySQL: " . $e->getMessage());
		return null;
	}
}

// Función para obtener la base de datos dinámica
function obtenerBaseDatosDinamica($config)
{
	try {
		$pdo = conectarMySQL($config, $config['db_static']);
		if (!$pdo) return null;

		$stmt = $pdo->prepare("SELECT dbase FROM empresa LIMIT 1");
		$stmt->execute();
		$result = $stmt->fetch(PDO::FETCH_ASSOC);

		return $result ? $result['dbase'] : null;
	} catch (PDOException $e) {
		error_log("Error al obtener base de datos dinámica: " . $e->getMessage());
		return null;
	}
}

// Función para guardar datos en factura_ventas
function guardarFacturaVentas($config, $datos_factura)
{
	try {
		$db_dinamica = obtenerBaseDatosDinamica($config);
		if (!$db_dinamica) {
			error_log("No se pudo obtener la base de datos dinámica");
			return false;
		}

		$pdo = conectarMySQL($config, $db_dinamica);
		if (!$pdo) return false;

		$sql = "UPDATE factura_ventas SET 
			hash_cdc = :cdc,
			prot_aut_sifen = :protocolo_autorizacion,
			estado_sifen = :estado_sifen,
			fecha_envio_sifen = :fecha_procesamiento,
			qr_sifen = :qr_url,
			mensaje_sifen = :respuesta_sifen,
			prot_cons_lote_sifen = :consulta_lote
			WHERE id_factura = :id_factura";

		$stmt = $pdo->prepare($sql);
		$resultado = $stmt->execute([
			':cdc' => $datos_factura['hash_cdc'] ?? null,
			':protocolo_autorizacion' => $datos_factura['prot_aut_sifen'] ?? null,
			':estado_sifen' => $datos_factura['estado_sifen'] ?? null,
			':fecha_procesamiento' => $datos_factura['fecha_envio_sifen'] ?? null,
			':qr_url' => $datos_factura['qr_sifen'] ?? null,
			':respuesta_sifen' => $datos_factura['mensaje_sifen'] ?? null,
			':consulta_lote' => $datos_factura['prot_cons_lote_sifen'] ?? null,
			':id_factura' => $datos_factura['id_factura'] ?? null
		]);

		return $resultado;
	} catch (PDOException $e) {
		error_log("Error al guardar en factura_ventas: " . $e->getMessage());
		return false;
	}
}

// Función para enviar respuesta JSON
function enviarRespuesta($data, $status = 200)
{
	header('Content-Type: application/json');
	http_response_code($status);
	echo json_encode($data);
	exit;
}

// Función para validar datos requeridos
function validarDatos($data, $campos_requeridos)
{
	$errores = [];
	foreach ($campos_requeridos as $campo) {
		if (!isset($data[$campo]) || empty($data[$campo])) {
			$errores[] = "Campo requerido faltante: $campo";
		}
	}
	return $errores;
}

// Obtener payload JSON
$input = file_get_contents('php://input');
$payload = json_decode($input, true);

// Solo ejecutar si es llamado directamente, no cuando es incluido
if (basename($_SERVER['SCRIPT_FILENAME']) !== basename(__FILE__)) {
	return; // Salir si es incluido desde otro archivo
}

// Si no hay payload JSON, usar datos por defecto para pruebas
if (empty($payload) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
	$payload = [
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
		'conceptos' => [[
			'codigo' => '001',
			'descripcion' => 'Courier Aereo',
			'precio' => 10000,
			'cantidad' => 1,
			'tasa_iva' => 10,
			'descuento' => 0,
			'proporcion_iva' => 25,
			'unidad_medida' => '77'
		]],
		'factura' => [
			'ndoc' => '1001',
			'condicion' => 'contado',
			'timbrado' => '17993028',
			'fec_timbrado' => '2025-04-28',
			'cod_establecimiento' => '001',
			'cod_expedicion' => '001',
			'moneda' => 'PYG',
			'cambio' => 1
		],
		'sifen_config' => [
			'certificado' => 'certificados/80062286.p12',
			'password' => '12345678',
			'ambiente' => 'prod',
			'idc' => '1',
			'csc' => 'f5157Ae805c58f2eeB8eA1eB7649f864'
		]
	];
}

// Validar que el payload sea válido
if (json_last_error() !== JSON_ERROR_NONE && $_SERVER['REQUEST_METHOD'] === 'POST') {
	enviarRespuesta(['error' => 'JSON inválido: ' . json_last_error_msg()], 400);
}

// Validar datos del emisor
if (isset($payload['emisor'])) {
	$errores_emisor = validarDatos($payload['emisor'], ['ruc', 'dv', 'razon_social']);
	if (!empty($errores_emisor)) {
		enviarRespuesta(['error' => 'Errores en datos del emisor', 'detalles' => $errores_emisor], 400);
	}
}

// Validar datos del receptor
if (isset($payload['receptor'])) {
	$errores_receptor = validarDatos($payload['receptor'], ['documento', 'tipo_doc', 'razon_social']);
	if (!empty($errores_receptor)) {
		enviarRespuesta(['error' => 'Errores en datos del receptor', 'detalles' => $errores_receptor], 400);
	}
}

// Validar conceptos
if (isset($payload['conceptos']) && is_array($payload['conceptos'])) {
	foreach ($payload['conceptos'] as $index => $concepto) {
		$errores_concepto = validarDatos($concepto, ['descripcion', 'precio', 'cantidad']);
		if (!empty($errores_concepto)) {
			enviarRespuesta(['error' => "Errores en concepto $index", 'detalles' => $errores_concepto], 400);
		}
	}
}

// Configurar SIFEN
$sifen_config = $payload['sifen_config'] ?? [];
$certificado = $sifen_config['certificado'] ?? 'certificados/80062286.p12';
$password = $sifen_config['password'] ?? '12345678';
$ambiente = $sifen_config['ambiente'] ?? 'prod';
$idc = $sifen_config['idc'] ?? '1';
$csc = $sifen_config['csc'] ?? 'f5157Ae805c58f2eeB8eA1eB7649f864';

$key = new \sifen\KEY($certificado, $password);
$sifen = new \sifen\Sifen($ambiente, $key);
$sifen->setIDC($idc);
$sifen->setCSC($csc);
// Funcionalidad de anulación
if (!empty($_GET['anular'])) {
	$xmlRequest = $sifen->anular($_GET['anular']);
	$respuesta = $sifen->enviarEvento($xmlRequest);
	if ($respuesta['status'] == 'ok' && !empty($respuesta['response'])) {
		enviarRespuesta([
			'success' => true,
			'mensaje' => 'Factura anulada exitosamente',
			'datos' => [
				'id' => $sifen->getId(),
				'fec_proc' => $sifen->getFecProc(),
				'est_res' => $sifen->getEstRes(),
				'prot_aut' => $sifen->getProtAut(),
				'cod_res' => $sifen->getCodRes(),
				'msg_res' => $sifen->getMsgRes(),
				'prot_cons_lote' => $sifen->getProtConsLote(),
				'tpo_proces' => $sifen->getTpoProces()
			]
		]);
	} else {
		enviarRespuesta(['error' => 'No hubo respuesta del servidor SIFEN'], 500);
	}
}

// Funcionalidad de consulta de lote
if (!empty($_GET['lote'])) {
	$resultado = $sifen->queryLote($_GET['lote']);
	enviarRespuesta([
		'success' => true,
		'mensaje' => 'Consulta de lote realizada',
		'datos' => $resultado
	]);
}

// TimeZone gmt-3
date_default_timezone_set('America/Montevideo');

// Procesar datos del payload
$datos_emisor = $payload['emisor'] ?? [];
$datos_receptor = $payload['receptor'] ?? [];
$datos_conceptos = $payload['conceptos'] ?? [];
$datos_factura = $payload['factura'] ?? [];
$formas_pago_payload = $payload['formas_pago'] ?? [];

// Crear objetos SIFEN
$emisor = new \sifen\Emisor($datos_emisor);
$receptor = new \sifen\Receptor($datos_receptor);

// Procesar conceptos
$conceptos = [];
foreach ($datos_conceptos as $concepto_data) {
	$conceptos[] = new \sifen\Concepto($concepto_data);
}

// Calcular total neto
$total_neto = 0;
foreach ($conceptos as $concepto) {
	$total_neto += ($concepto->precio - $concepto->descuento) * $concepto->cantidad;
}

// Procesar formas de pago
$formas_pagos = [];
if (!empty($formas_pago_payload)) {
	foreach ($formas_pago_payload as $forma_pago) {
		$formas_pagos[] = new \sifen\FormasPago($forma_pago);
	}
} else {
	// Forma de pago por defecto
	$forma_pago_default = [
		'tipo_pago' => 1,
		'monto' => $total_neto,
		'moneda' => $datos_factura['moneda'] ?? 'PYG',
		'cambio' => $datos_factura['cambio'] ?? 1
	];
	$formas_pagos[] = new \sifen\FormasPago($forma_pago_default);
}


// Configurar datos de la factura
$config_factura = [
	'emisor' => $emisor,
	'receptor' => $receptor,
	'formas_pago' => $formas_pagos,
	'conceptos' => $conceptos,
	'ndoc' => $datos_factura['ndoc'] ?? '1001',
	'condicion' => $datos_factura['condicion'] ?? 'contado',
	'timbrado' => $datos_factura['timbrado'] ?? '17993028',
	'fec_timbrado' => $datos_factura['fec_timbrado'] ?? '2025-04-28',
	'cod_establecimiento' => $datos_factura['cod_establecimiento'] ?? '001',
	'cod_expedicion' => $datos_factura['cod_expedicion'] ?? '001',
	'moneda' => $datos_factura['moneda'] ?? 'PYG',
	'cambio' => $datos_factura['cambio'] ?? 1
];

// Agregar plazo si está configurado
if (isset($datos_factura['plazo'])) {
	$config_factura['plazo'] = $datos_factura['plazo'];
} else {
	$config_factura['plazo'] = [
		'condicion' => 1, // 1 Plazo - 2 Cuota
		'valor' => 12 // 1 - Cantidad de Dias, 2 - Cantidad de Cuotas
	];
}

try {
	// Crear y procesar factura
	$factura = new \sifen\Factura(\sifen\DTE::FACT_VENTA, $config_factura);
	$sifen->agregarFactura($factura);
	$xmlRequest = $sifen->buildXML(true);
	$respuesta = $sifen->enviar($xmlRequest);

	if ($respuesta['status'] == 'ok' && !empty($respuesta['response'])) {
		// Datos iniciales de la respuesta
		$datos_respuesta = [
			'id' => $sifen->getId(),
			'fec_proc' => $sifen->getFecProc(),
			'est_res' => $sifen->getEstRes(),
			'prot_aut' => $sifen->getProtAut(),
			'cod_res' => $sifen->getCodRes(),
			'msg_res' => $sifen->getMsgRes(),
			'prot_cons_lote' => $sifen->getProtConsLote(),
			'tpo_proces' => $sifen->getTpoProces(),
			'url_cons_lote' => $sifen->buildQRLink(),
			'qr_url' => $sifen->getQrUrl(),
			'qr_image' => "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($sifen->getQrUrl() ?? '')
		];

		// Esperar 3 segundos antes de consultar el lote
		sleep(3);

		// Consultar automáticamente el estado del lote
		$consulta_lote = null;
		$prot_cons_lote = $sifen->getProtConsLote();
		if (!empty($prot_cons_lote)) {
			try {
				$consulta_lote = $sifen->queryLote($prot_cons_lote);
				$datos_respuesta['consulta_lote'] = [
					'protocolo' => $prot_cons_lote,
					'resultado' => $consulta_lote,
					'consultado_en' => date('Y-m-d H:i:s')
				];
			} catch (Exception $e) {
				$datos_respuesta['consulta_lote'] = [
					'protocolo' => $prot_cons_lote,
					'error' => 'Error al consultar lote: ' . $e->getMessage(),
					'consultado_en' => date('Y-m-d H:i:s')
				];
			}
		}

		// Preparar datos para guardar en base de datos
		$datos_para_bd = [
			'hash_cdc' => $sifen->getId(),
			'prot_aut_sifen' => $sifen->getProtAut(),
			'estado_sifen' => $sifen->getEstRes(),
			'fecha_envio_sifen' => $sifen->getFecProc(),
			'qr_sifen' => $sifen->getQrUrl(),
			'mensaje_sifen' => json_encode($datos_respuesta),
			'prot_cons_lote_sifen' => json_encode($datos_respuesta['consulta_lote'] ?? null),
			'id_factura' => $datos_factura['id_factura'] ?? $datos_factura['ndoc'] ?? '1001'
		];

		// Guardar en base de datos
		$guardado_exitoso = guardarFacturaVentas($db_config, $datos_para_bd);

		// Agregar información del guardado a la respuesta
		$datos_respuesta['guardado_bd'] = $guardado_exitoso;

		// Respuesta exitosa con consulta de lote incluida
		enviarRespuesta([
			'success' => true,
			'mensaje' => 'Factura generada exitosamente, lote consultado automáticamente y datos guardados en BD',
			'datos' => $datos_respuesta
		]);
	} else {
		enviarRespuesta([
			'error' => 'No hubo respuesta del servidor SIFEN',
			'respuesta_completa' => $respuesta
		], 500);
	}
} catch (Exception $e) {
	enviarRespuesta([
		'error' => 'Error al procesar la factura: ' . $e->getMessage(),
		'linea' => $e->getLine(),
		'archivo' => $e->getFile()
	], 500);
}
