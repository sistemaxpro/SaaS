<?php 
	error_reporting(E_ALL);
	ini_set('display_errors', 1);
	require __DIR__ . '/src/php-sifen.php';

	$emisor = [
		'ruc' => '80118689',
		'dv' => '7',
		'razon_social' => 'SISTEMAX EAS',
		'tipo_contribuyente' => '2', // PJ
		'ciudad' => 5393,
		'direccion' => 'HERNANDARIAS',
		'telefono' => '0983657691',
		'email' => 'soporte@sistemax.com.py',
		'act_eco' => '62010',
		'act_eco_desc' => 'ACTIVIDADES DE PROGRAMACIÓN INFORMÁTICA'
	];

	$receptor = [
		'documento' => '1023840',
		'tipo_doc' => 'ruc',
		'razon_social' => 'VALDEZ, MIGUEL ANGEL',
		'direccion' => 'Juan Ramon de la llanas 1191',
		'telefono' => '000000000',
		'email' => 'soporte@sistemax.com.py',
		'ciudad' => 1
	];

	$key = new \sifen\KEY(__DIR__ . '/certificados/80118689.p12', '3nvcEcwW');
	$sifen = new \sifen\Sifen('prod', $key);
	$sifen->setCSC('a911929881f35219c7610A4f8F9F97D5');
	$sifen->setIdc('1');

	$options = getopt("", ["lote:"]);
	$loteId = !empty($_GET['lote']) ? $_GET['lote'] : (!empty($options['lote']) ? $options['lote'] : null);

	if(!empty($loteId)) {
		$resultado = $sifen->queryLote($loteId);
		if (php_sapi_name() === 'cli') {
			print_r($resultado);
		} else {
			header('Content-Type: application/json');
			echo json_encode($resultado);
		}
		die();
	}

	// TimeZone gmt-3

	date_default_timezone_set('America/Montevideo');



 	$emisor = new \sifen\Emisor($emisor);
	$receptor = new \sifen\Receptor($receptor);

	$conceptos = [];
	$concepto = [
		'codigo' => '123',
		'descripcion' => 'PRODUCTO DE BALANZA',
		'precio' => 1300,
		'cantidad' => 1,
		'tasa_iva' => 10,
		'descuento' => 0,
		'proporcion_iva' => 100,
		'unidad_medida' => '77'
	];

	$conceptos[] = new \sifen\Concepto($concepto);

	$total_neto = 0;
	foreach($conceptos as $concepto) {
		$total_neto += ($concepto->precio - $concepto->descuento) * $concepto->cantidad;
	}

	$formas_pago = [
		'tipo_pago' => 1,
		'monto' => $total_neto,
		'moneda' => 'PYG',
		'cambio' => 1
	];

	$formas_pagos = [];
	$formas_pagos[] = new \sifen\FormasPago($formas_pago);


	$factura = [
		'emisor' => $emisor,
		'receptor' => $receptor,
		'formas_pago' => $formas_pagos,
		'conceptos' => $conceptos,
		'ndoc' => '1002',
		'condicion' => 'contado',
		'timbrado' => '18335712',
		'fec_timbrado' => '2025-09-26',
		'cod_establecimiento' => '001',
		'cod_expedicion' => '001',
		'moneda' => 'PYG',
		'cambio' => 1,
		'plazo' => [
			'condicion' => 1, // 1 Plazo - 2 Cuota
			'valor' => 12 // 1 - Cantidad de Dias, 2 - Cantidad de Cuotas
		]
	];



	$factura = new \sifen\Factura(\sifen\DTE::FACT_VENTA, $factura);

	$sifen->agregarFactura($factura);
	$xmlRequest = $sifen->buildXML(true);
	$respuesta = $sifen->enviar($xmlRequest);

	if($respuesta['status'] == 'ok' && !empty($respuesta['response'])) {
		$protConsLote = $sifen->getProtConsLote();
		
		echo "MsgRes: ".$sifen->getMsgRes()."<br />";
		echo "ProtConsLote: ".$protConsLote."<br />";

		if (!empty($protConsLote)) {
			echo "Esperando 2 segundos para consultar el resultado...\n";
			sleep(2);
			
			$resultado = $sifen->queryLote($protConsLote);
			echo "Resultado del Lote:\n";
			print_r($resultado);
		}

		echo "\nURLConsLote: ".$sifen->buildQRLink()."\n";
		echo "QR Data: ".$sifen->getQrUrl()."\n";
	} else {
		die("No hubo respuesta: " . (isset($respuesta['error']) ? $respuesta['error'] : 'Error desconocido'));
	}
?>
