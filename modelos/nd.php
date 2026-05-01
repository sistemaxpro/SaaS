<?php
require 'src/php-sifen.php';

// ============================================================
// CONFIGURACIÓN: Cambiar este ID según la empresa a usar
// ============================================================
$id_empresa = 169; // SISTEMAX E.A.S. UNIPERSONAL

// Conexión a la base de datos
$dbHost = '168.231.95.50';
$dbUser = 'sistemax';
$dbPass = 'Armagedon123';
$dbName = 'serproc1';

try {
	$pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$dbName}", $dbUser, $dbPass);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec("SET NAMES utf8");
} catch (Exception $e) {
	die("Error de conexión: " . $e->getMessage());
}

// Obtener configuración SIFEN desde habilitacion_sifen
$sql = "SELECT h.*, a.codigo as act_eco, a.descripcion as act_eco_desc 
			FROM habilitacion_sifen h 
			LEFT JOIN habilitacion_sifen_actividades a ON h.id = a.id_habilitacion AND a.principal = 1
			WHERE h.id_empresa = :id_empresa AND h.activo = 1 
			LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute([':id_empresa' => $id_empresa]);
$hab = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$hab) {
	die("No se encontró habilitación SIFEN para la empresa ID: $id_empresa");
}

// Construir ruta del certificado
$certPath = rtrim($hab['cert_path'], '/') . '/' . $hab['cert_nombre'];
$certPass = $hab['cert_pass'];
$modo = strtolower($hab['ambiente']) === 'prod' ? 'prod' : 'test';

// Datos del emisor desde habilitacion_sifen
$emisor = [
	'ruc' => $hab['ruc'],
	'dv' => (string) $hab['dv'],
	'razon_social' => $hab['razon_social'],
	'tipo_contribuyente' => '2',
	'ciudad' => (int) $hab['cod_ciudad'],
	'direccion' => $hab['direccion'] ?: '-',
	'telefono' => $hab['telefono'] ?: '-',
	'email' => $hab['email'] ?: '-',
	'act_eco' => $hab['act_eco'] ?: '0',
	'act_eco_desc' => $hab['act_eco_desc'] ?: '-'
];

$receptor = [
	'documento' => '1138039',
	'tipo_doc' => 'ci',
	'razon_social' => 'NORMA ROMAN',
	'ciudad' => 1,
	'direccion' => 'Prueba 123',
	'telefono' => '0981987654',
	'email' => 'hola@quetal.com'
];

$key = new \sifen\KEY($certPath, $certPass);
$sifen = new \sifen\Sifen($modo, $key);

// Configurar CSC e IDC
if (!empty($hab['csc'])) {
	$sifen->setCSC($hab['csc']);
}
$sifen->setIdc($hab['id_csc'] ?: '0001');

$emisor = new \sifen\Emisor($emisor);
$receptor = new \sifen\Receptor($receptor);

$concepto = [
	'codigo' => '001',
	'descripcion' => 'Producto',
	'precio' => 25000,
	'cantidad' => 1,
	'tasa_iva' => 10,
	'descuento' => 0,
	'unidad_medida' => '77'
];

$conceptos = [];
$conceptos[] = new \sifen\Concepto($concepto);

$factura = [
	'emisor' => $emisor,
	'receptor' => $receptor,
	'conceptos' => $conceptos,
	'ndoc' => '3000',
	'timbrado' => $hab['numero_timbrado'],
	'fec_timbrado' => $hab['fecha_inicio_vigencia'],
	'cod_establecimiento' => '001',
	'cod_expedicion' => '001',
	'moneda' => 'PYG',
	'cambio' => 1,
	'motivo' => 2
];

$factura = new \sifen\Factura(\sifen\DTE::ND, $factura);

// CDC de la factura original a la que se aplica la Nota de Débito
// IMPORTANTE: Debe ser un CDC válido de una factura aprobada del mismo emisor
$cdcFacturaOriginal = '01801107474001001000300222023051010000000019'; // CAMBIAR por CDC real

$asociacion = [
	'cdc' => $cdcFacturaOriginal
];

$factura->agregarAsociacion($asociacion);

$sifen->agregarFactura($factura);
$xmlRequest = $sifen->buildXML(false);
$respuesta = $sifen->enviar($xmlRequest);

if ($respuesta['status'] == 'ok' && !empty($respuesta['response'])) {
	echo $sifen->getId() . "<br />";
	echo $sifen->getFecProc() . "<br />";
	echo $sifen->getEstRes() . "<br />";
	echo $sifen->getProtAut() . "<br />";
	echo $sifen->getCodRes() . "<br />";
	echo $sifen->getMsgRes() . "<br />";
	echo $sifen->getProtConsLote() . "<br />";
	echo $sifen->getTpoProces() . "<br />";
} else {
	die("No hubo respuesta");
}
