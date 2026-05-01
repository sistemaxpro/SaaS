<?php
require 'src/php-sifen.php';

$emisor = [
    'ruc' => '80110747',
    'dv' => '4',
    'razon_social' => 'AKTIV SOCIEDAD DE RESPONSABILIDAD LIMITADA',
    'tipo_contribuyente' => '2',
    'ciudad' => 1,
    'direccion' => 'Ayolas 123',
    'telefono' => '0981123456',
    'email' => 'hola@hola.com',
    'act_eco' => '46699',
    'act_eco_desc' => 'COMERCIO AL POR MAYOR DE OTROS PRODUCTOS N.C.P.'
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

$key = new \sifen\KEY('noenviar/AKTIV.p12', 'asd');
$sifen = new \sifen\Sifen('test', $key);

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
    'inicio' => '2023-05-13',
    'fin' => '2023-05-15',
    'cod_establecimiento' => '001',
    'cod_expedicion' => '001',
    'moneda' => 'PYG',
    'cambio' => 1,
    'motivo_nr' => 1,
    'kmr' => 100
];

$factura = new \sifen\Factura(\sifen\DTE::NR, $factura);

$asociacion = [
    'cdc' => '01801107474001001000300222023051010000000019'
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
