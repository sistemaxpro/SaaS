<?php 
	/**
	 * NC.PHP - Emisión de Nota de Crédito Electrónica
	 * Recibe JSON desde emitir_nota.php
	 */
	header('Content-Type: application/json; charset=utf-8');
	require 'src/php-sifen.php';

	// Recibir datos
	$inputJSON = file_get_contents('php://input');
	$input = json_decode($inputJSON, true);

	if (empty($input)) {
		echo json_encode(['success' => false, 'message' => 'No se recibieron datos en el endpoint NC']);
		exit;
	}

	try {
		$key = new \sifen\KEY($input['cert_path'], $input['cert_pass']);
		$sifen = new \sifen\Sifen($input['modo'] ?? 'prod', $key);
		$sifen->setCSC($input['csc'] ?? 'a911929881f35219c7610A4f8F9F97D5'); // CSC por defecto si no viene
		$sifen->setIdc($input['id_csc'] ?? '1');

		$emisor = new \sifen\Emisor($input['emisor']);
		$receptor = new \sifen\Receptor($input['receptor']);

		$conceptos = [];
		foreach ($input['conceptos'] as $c) {
			$conceptos[] = new \sifen\Concepto($c);
		}

		$facturaData = [
			'emisor' => $emisor,
			'receptor' => $receptor,
			'conceptos' => $conceptos,
			'ndoc' => $input['ndoc'] ?? '1',
			'timbrado' => $input['timbrado'] ?? '',
			'fec_timbrado' => $input['fec_timbrado'] ?? date('Y-m-d'),
			'cod_establecimiento' => $input['cod_establecimiento'] ?? '001',
			'cod_expedicion' => $input['cod_expedicion'] ?? '001',
			'moneda' => $input['moneda'] ?? 'PYG',
			'cambio' => $input['cambio'] ?? 1,
			'motivo' => $input['motivo'] ?? 1
		];

		$factura = new \sifen\Factura(\sifen\DTE::NC, $facturaData);

		$asociacion = [
			'cdc' => $input['cdc']
		];
		$factura->agregarAsociacion($asociacion);

		$sifen->agregarFactura($factura);
		$xmlRequest = $sifen->buildXML(true); // true = Modo Lote (Asíncrono)
		error_log("NC Lote XML Request: " . $xmlRequest->asXML());
		$respuesta = $sifen->enviar($xmlRequest);

		if ($respuesta['status'] == 'ok' && !empty($respuesta['response'])) {
			$protConsLote = $sifen->getProtConsLote();
			$estadoFinal = 'Pendiente (Lote)';
			$mensajeFinal = 'Lote enviado, esperando procesamiento';
			
			// Si tenemos protocolo de lote, esperamos y consultamos el resultado final
			if (!empty($protConsLote)) {
				sleep(2); // Esperar 2 segundos para procesamiento en SET
				$resLote = $sifen->queryLote($protConsLote);
				
				if (!empty($resLote['response'])) {
					// Intentar extraer datos del resultado del lote
					$dom = new DOMDocument();
					@$dom->loadXML($resLote['response']);
					// Usar búsqueda recursiva sin namespaces para mayor robustez
					$nodesEst = $dom->getElementsByTagNameNS('*', 'dEstRes');
					if ($nodesEst->length > 0) $estadoFinal = $nodesEst->item(0)->nodeValue;
					
					$nodesMsg = $dom->getElementsByTagNameNS('*', 'dMsgRes');
					if ($nodesMsg->length > 0) $mensajeFinal = $nodesMsg->item(0)->nodeValue;

					$nodesProt = $dom->getElementsByTagNameNS('*', 'dProtAut');
					if ($nodesProt->length > 0) $protocoloAuth = $nodesProt->item(0)->nodeValue;
				}
			}

			echo json_encode([
				'success' => true,
				'id' => $sifen->getId(),
				'estadoFinal' => $estadoFinal,
				'mensajeFinal' => $mensajeFinal,
				'protocolo_lote' => $protConsLote,
				'protocolo_auth' => $protocoloAuth ?? $sifen->getProtAut()
			]);
		} else {
			echo json_encode([
				'success' => false,
				'message' => 'Error en respuesta de SIFEN',
				'error' => $respuesta['error'] ?? 'Error desconocido',
				'data' => ['xml_request' => $xmlRequest->asXML()]
			]);
		}

		// Limpieza de archivos temporales de la librería
		if (file_exists('xml.xml')) @unlink('xml.xml');
		if (file_exists('xmlsigned.xml')) @unlink('xmlsigned.xml');
		if (file_exists('key.pem')) @unlink('key.pem');

	} catch (Exception $e) {
		echo json_encode([
			'success' => false,
			'message' => 'Excepción en NC.php: ' . $e->getMessage()
		]);
	}
?>