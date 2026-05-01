<?php 
	require 'src/php-sifen.php';

	// Array de Datos del Emisor del DTE
	$emisor = [
		'ruc' => '80110747', // RUC sin DV
		'dv' => '4', // DV
		'razon_social' => 'AKTIV SOCIEDAD DE RESPONSABILIDAD LIMITADA', // RAZON SOCIAL
		'tipo_contribuyente' => '2', // TIPO DE CONTRIBUYENTE 
		'ciudad' => 1, // CODIGO DE CIUDAD, SE GENERARÁ AUTOMÁTICAMENTE DATOS DE DISTRITO Y DEPARTAMENTO
		'direccion' => 'Ayolas 123', // DIRECCION
		'telefono' => '0981123456', // TELEFONO DEBE SER CON EL PREFIJO DE CEL O LINEA BAJA
		'email' => 'hola@hola.com', // EMAIL
		'act_eco' => '46699', // CODIGO DE ACTIVIDAD ECONOMICA, VER INFO EN MARANGATU
		'act_eco_desc' => 'COMERCIO AL POR MAYOR DE OTROS PRODUCTOS N.C.P.' // DESCRIPCION DE ACTIVIDAD ECONOMICA
	];

	// ARRAY DE DATOS DE RECEPTOR (CLIENTE)
	$receptor = [
		'documento' => '1138039', // NUMERO DE DOCUMENTO
		'tipo_doc' => 'ci', // TIPO DE DOCUMENTO (ci o ruc)
		'razon_social' => 'NORMA ROMAN', // RAZON SOCIAL
		'ciudad' => 1, // CODIGO DE CIUDAD
		'direccion' => 'Prueba 123', // DIRECCION
		'telefono' => '0981987654', // TELEFONO
		'email' => 'hola@quetal.com' // EMAIL
	];

	$key = new \sifen\KEY('noenviar/AKTIV.p12', 'asd');
	$sifen = new \sifen\Sifen('test', $key);
 	$emisor = new \sifen\Emisor($emisor);
	$receptor = new \sifen\Receptor($receptor);

	$formas_pago = [
		'tipo_pago' => 1,
		'monto' => 25000,
		'moneda' => 'PYG',
		'cambio' => 1
	];

	$formas_pagos = [];
	$formas_pagos[] = new \sifen\FormasPago($formas_pago);

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
		'formas_pago' => $formas_pagos,
		'conceptos' => $conceptos,
		'ndoc' => '3000',
		'condicion' => 'contado',
		'timbrado' => '12558955',
		'fec_timbrado' => '2021-08-27',
		'cod_establecimiento' => '001',
		'cod_expedicion' => '001',
		'moneda' => 'PYG',
		'cambio' => 1,
		'plazo' => [
			'condicion' => 1, // 1 Plazo - 2 Cuota
			'valor' => 12 // 1 - Cantidad de Dias, 2 - Cantidad de Cuotas
		]
	];

	$factura = new \sifen\Factura(\sifen\DTE::AUTOFACT_VENTA, $factura);

	$sifen->agregarFactura($factura);
	$xmlRequest = $sifen->buildXML(false);
	die();
	$respuesta = $sifen->enviar($xmlRequest);

	if($respuesta['status'] == 'ok' && !empty($respuesta['response'])) {
		echo $sifen->getId()."<br />"; 
		echo $sifen->getFecProc()."<br />";
		echo $sifen->getEstRes()."<br />";
		echo $sifen->getProtAut()."<br />";
		echo $sifen->getCodRes()."<br />";
		echo $sifen->getMsgRes()."<br />";
		echo $sifen->getProtConsLote()."<br />";
		echo $sifen->getTpoProces()."<br />";
	} else {
		die("No hubo respuesta");
	}
?>