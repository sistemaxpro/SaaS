
<?php
// Forzar el uso del proveedor legacy de OpenSSL si existe el archivo de configuración
$opensslLegacy = __DIR__ . '/openssl-legacy.cnf';
if (file_exists($opensslLegacy)) {
	putenv('OPENSSL_CONF=' . $opensslLegacy);
	// Para PHP >=8.1, también puede ser necesario:
	if (function_exists('openssl_get_cert_locations')) {
		$loc = openssl_get_cert_locations();
		if (isset($loc['default_conf_file'])) {
			@copy($opensslLegacy, $loc['default_conf_file']);
		}
	}
}
// Definir constantes para forzar el uso del certificado correcto
if (!defined('SIFEN_CERT_PATH')) {
	define('SIFEN_CERT_PATH', __DIR__ . '/_lib/php-sifen3-custom/certificados/80105122.p12');
}
if (!defined('SIFEN_CERT_PASS')) {
	define('SIFEN_CERT_PASS', 'ER4q8cj7');
}

// Cargar librería custom
require __DIR__ . '/_lib/php-sifen3-custom/src/php-sifen.php';

$emisor = [
	'ruc' => '80105122',
	'dv' => '3',
	'razon_social' => 'AGRUPO MARAVILLA SOCIEDAD DE RESPONSABILIDAD LIMITA',
	'tipo_contribuyente' => '2',
	'ciudad' => 1,
	'direccion' => 'Ayolas 123',
	'telefono' => '0981123456',
	'email' => 'hola@hola.com',
	'act_eco' => '40231',
	'act_eco_desc' => 'TRANSPORTE TERRESTRE LOCAL DE CARGA'
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


// Usar las constantes para crear el objeto KEY
$key = new \sifen\KEY(SIFEN_CERT_PATH, SIFEN_CERT_PASS);
$sifen = new \sifen\Sifen('test', $key);

$emisor = new \sifen\Emisor($emisor);
$receptor = new \sifen\Receptor($receptor);

$concepto = [
	'codigo' => '001',
	'descripcion' => 'Transporte de mercaderia',
	'precio' => 100000,
	'cantidad' => 1,
	'tasa_iva' => 10,
	'descuento' => 0,
	'unidad_medida' => '77'
];

$conceptos = [];
$conceptos[] = new \sifen\Concepto($concepto);

$conductor = [
	'documento' => '1138039',
	'razon_social' => 'NORMA ROMAN'
];

$conductor = new \sifen\Conductor($conductor);

$factura = [
	'emisor' => $emisor,
	'receptor' => $receptor,
	'conceptos' => $conceptos,
	'conductor' => $conductor,
	'ndoc' => '3000',
	'timbrado' => '12558955',
	'fec_timbrado' => '2021-08-27',
	'inicio' => '2021-08-27',
	'fin' => '2025-08-27',
	'cod_establecimiento' => '001',
	'cod_expedicion' => '001',
	'moneda' => 'PYG',
	'cambio' => 1,
	'motivo_nr' => 1,
	'kmr' => 100
];

$factura = new \sifen\Factura(\sifen\DTE::NR, $factura);

$asociacion = [
	'cdc' => '01801186897001001000014022026012710000000019'
];

$factura->agregarAsociacion($asociacion);

$sifen->agregarFactura($factura);
$xmlRequest = $sifen->buildXML(false);

// Convertir SimpleXMLElement a string y guardar
$xmlString = $xmlRequest->asXML();
$xmlFile = __DIR__ . '/xml_firmado_nr_' . date('YmdHis') . '.xml';
file_put_contents($xmlFile, $xmlString);
echo "<!-- XML guardado en: $xmlFile -->\n";

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
