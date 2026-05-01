<?php
	error_reporting(E_ALL);
	ini_set('display_errors', 1);
	require 'src/php-sifen.php';

	// Configurar timezone
	date_default_timezone_set('America/Montevideo');

	$mensaje = '';
	$tipo_mensaje = '';
	$resultado_factura = null;

	// Procesar formulario si se envió
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		try {
			// Validar datos requeridos
			if (empty($_POST['emisor_ruc']) || empty($_POST['receptor_documento']) || empty($_POST['concepto_descripcion'])) {
				throw new Exception('Faltan datos obligatorios');
			}

			// Configurar emisor
			$emisor = [
				'ruc' => $_POST['emisor_ruc'],
				'dv' => $_POST['emisor_dv'],
				'razon_social' => $_POST['emisor_razon_social'],
				'tipo_contribuyente' => $_POST['emisor_tipo_contribuyente'],
				'ciudad' => (int)$_POST['emisor_ciudad'],
				'direccion' => $_POST['emisor_direccion'],
				'telefono' => $_POST['emisor_telefono'],
				'email' => $_POST['emisor_email'],
				'act_eco' => $_POST['emisor_act_eco'],
				'act_eco_desc' => $_POST['emisor_act_eco_desc']
			];

			// Configurar receptor
			$receptor = [
				'documento' => $_POST['receptor_documento'],
				'tipo_doc' => $_POST['receptor_tipo_doc'],
				'razon_social' => $_POST['receptor_razon_social'],
				'direccion' => $_POST['receptor_direccion'],
				'telefono' => $_POST['receptor_telefono'],
				'email' => $_POST['receptor_email'],
				'ciudad' => (int)$_POST['receptor_ciudad']
			];

			// Configurar SIFEN
			$key = new \sifen\KEY('80062286.p12', '12345678');
			$sifen = new \sifen\Sifen('prod', $key);
			$sifen->setIDC("1");
			$sifen->setCSC("f5157Ae805c58f2eeB8eA1eB7649f864");

			$emisor_obj = new \sifen\Emisor($emisor);
			$receptor_obj = new \sifen\Receptor($receptor);

			// Configurar conceptos
			$conceptos = [];
			$concepto = [
				'codigo' => $_POST['concepto_codigo'] ?: '001',
				'descripcion' => $_POST['concepto_descripcion'],
				'precio' => (float)$_POST['concepto_precio'],
				'cantidad' => (float)$_POST['concepto_cantidad'],
				'tasa_iva' => (float)$_POST['concepto_tasa_iva'],
				'descuento' => (float)($_POST['concepto_descuento'] ?: 0),
				'proporcion_iva' => (float)($_POST['concepto_proporcion_iva'] ?: 25),
				'unidad_medida' => $_POST['concepto_unidad_medida'] ?: '77'
			];

			$conceptos[] = new \sifen\Concepto($concepto);

			// Calcular total
			$total_neto = 0;
			foreach($conceptos as $concepto_obj) {
				$total_neto += ($concepto_obj->precio - $concepto_obj->descuento) * $concepto_obj->cantidad;
			}

			// Configurar formas de pago
			$formas_pago = [
				'tipo_pago' => (int)($_POST['tipo_pago'] ?: 1),
				'monto' => $total_neto,
				'moneda' => $_POST['moneda'] ?: 'PYG',
				'cambio' => (float)($_POST['cambio'] ?: 1)
			];

			$formas_pagos = [];
			$formas_pagos[] = new \sifen\FormasPago($formas_pago);

			// Configurar factura
			$factura_data = [
				'emisor' => $emisor_obj,
				'receptor' => $receptor_obj,
				'formas_pago' => $formas_pagos,
				'conceptos' => $conceptos,
				'ndoc' => $_POST['ndoc'] ?: '1001',
				'condicion' => $_POST['condicion'] ?: 'contado',
				'timbrado' => $_POST['timbrado'] ?: '17993028',
				'fec_timbrado' => $_POST['fec_timbrado'] ?: '2025-04-28',
				'cod_establecimiento' => $_POST['cod_establecimiento'] ?: '001',
				'cod_expedicion' => $_POST['cod_expedicion'] ?: '001',
				'moneda' => $_POST['moneda'] ?: 'PYG',
				'cambio' => (float)($_POST['cambio'] ?: 1),
				'plazo' => [
					'condicion' => (int)($_POST['plazo_condicion'] ?: 1),
					'valor' => (int)($_POST['plazo_valor'] ?: 12)
				]
			];

			$factura = new \sifen\Factura(\sifen\DTE::FACT_VENTA, $factura_data);

			$sifen->agregarFactura($factura);
			$xmlRequest = $sifen->buildXML(true);
			$respuesta = $sifen->enviar($xmlRequest);

			if($respuesta['status'] == 'ok' && !empty($respuesta['response'])) {
				$resultado_factura = [
					'id' => $sifen->getId(),
					'fec_proc' => $sifen->getFecProc(),
					'est_res' => $sifen->getEstRes(),
					'prot_aut' => $sifen->getProtAut(),
					'cod_res' => $sifen->getCodRes(),
					'msg_res' => $sifen->getMsgRes(),
					'prot_cons_lote' => $sifen->getProtConsLote(),
					'tpo_proces' => $sifen->getTpoProces(),
					'qr_url' => $sifen->getQrUrl(),
					'qr_link' => $sifen->buildQRLink()
				];
				$mensaje = 'Factura generada exitosamente';
				$tipo_mensaje = 'success';
			} else {
				$mensaje = 'Error al generar la factura: No hubo respuesta del servidor';
				$tipo_mensaje = 'error';
			}

		} catch (Exception $e) {
			$mensaje = 'Error: ' . $e->getMessage();
			$tipo_mensaje = 'error';
		}
	}

	// Procesar anulación si se solicita
	if (!empty($_GET['anular'])) {
		try {
			$key = new \sifen\KEY('80062286.p12', '12345678');
			$sifen = new \sifen\Sifen('prod', $key);
			$sifen->setIDC("1");
			$sifen->setCSC("f5157Ae805c58f2eeB8eA1eB7649f864");
			
			$xmlRequest = $sifen->anular($_GET['anular']);
			$respuesta = $sifen->enviarEvento($xmlRequest);
			
			if($respuesta['status'] == 'ok' && !empty($respuesta['response'])) {
				$mensaje = 'Factura anulada exitosamente. ID: ' . $sifen->getId();
				$tipo_mensaje = 'success';
			} else {
				$mensaje = 'Error al anular la factura';
				$tipo_mensaje = 'error';
			}
		} catch (Exception $e) {
			$mensaje = 'Error en anulación: ' . $e->getMessage();
			$tipo_mensaje = 'error';
		}
	}

	// Procesar consulta de lote si se solicita
	if (!empty($_GET['lote'])) {
		try {
			$key = new \sifen\KEY('80062286.p12', '12345678');
			$sifen = new \sifen\Sifen('prod', $key);
			$sifen->setIDC("1");
			$sifen->setCSC("f5157Ae805c58f2eeB8eA1eB7649f864");
			
			$resultado = $sifen->queryLote($_GET['lote']);
			header('Content-Type: application/json');
			echo json_encode($resultado);
			exit();
		} catch (Exception $e) {
			header('Content-Type: application/json');
			echo json_encode(['error' => $e->getMessage()]);
			exit();
		}
	}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SIFEN - Generador de Facturas</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .header p {
            font-size: 1.1em;
            opacity: 0.9;
        }
        
        .content {
            padding: 40px;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .alert.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 25px;
            border-left: 4px solid #007bff;
        }
        
        .form-section h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 1.3em;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .form-group {
            flex: 1;
            min-width: 250px;
        }
        
        .form-group.full-width {
            flex: 100%;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
        }
        
        .btn {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,123,255,0.3);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }
        
        .result-section {
            background: #e8f5e8;
            border-radius: 10px;
            padding: 25px;
            margin-top: 25px;
            border-left: 4px solid #28a745;
        }
        
        .result-item {
            margin-bottom: 15px;
            padding: 10px;
            background: white;
            border-radius: 5px;
            border: 1px solid #d4edda;
        }
        
        .result-item strong {
            color: #155724;
        }
        
        .qr-container {
            text-align: center;
            margin-top: 20px;
        }
        
        .qr-container img {
            border: 3px solid #28a745;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .actions {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 25px;
            text-align: center;
        }
        
        .actions h4 {
            margin-bottom: 15px;
            color: #2c3e50;
        }
        
        .action-form {
            display: inline-block;
            margin: 0 10px;
        }
        
        .action-form input {
            width: 200px;
            margin-right: 10px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
            }
            
            .form-group {
                min-width: 100%;
            }
            
            .content {
                padding: 20px;
            }
            
            .header {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 2em;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🧾 SIFEN</h1>
            <p>Sistema Integrado de Facturación Electrónica Nacional</p>
        </div>
        
        <div class="content">
            <?php if ($mensaje): ?>
                <div class="alert <?php echo $tipo_mensaje; ?>">
                    <?php echo htmlspecialchars($mensaje); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($resultado_factura): ?>
                <div class="result-section">
                    <h3>✅ Factura Generada Exitosamente</h3>
                    
                    <div class="result-item">
                        <strong>ID:</strong> <?php echo htmlspecialchars($resultado_factura['id']); ?>
                    </div>
                    
                    <div class="result-item">
                        <strong>Fecha de Procesamiento:</strong> <?php echo htmlspecialchars($resultado_factura['fec_proc']); ?>
                    </div>
                    
                    <div class="result-item">
                        <strong>Estado:</strong> <?php echo htmlspecialchars($resultado_factura['est_res']); ?>
                    </div>
                    
                    <div class="result-item">
                        <strong>Protocolo de Autorización:</strong> <?php echo htmlspecialchars($resultado_factura['prot_aut']); ?>
                    </div>
                    
                    <div class="result-item">
                        <strong>Código de Respuesta:</strong> <?php echo htmlspecialchars($resultado_factura['cod_res']); ?>
                    </div>
                    
                    <div class="result-item">
                        <strong>Mensaje:</strong> <?php echo htmlspecialchars($resultado_factura['msg_res']); ?>
                    </div>
                    
                    <div class="result-item">
                        <strong>Protocolo Consulta Lote:</strong> <?php echo htmlspecialchars($resultado_factura['prot_cons_lote']); ?>
                    </div>
                    
                    <div class="result-item">
                        <strong>Tipo de Proceso:</strong> <?php echo htmlspecialchars($resultado_factura['tpo_proces']); ?>
                    </div>
                    
                    <div class="result-item">
                        <strong>URL de Consulta:</strong> 
                        <a href="<?php echo htmlspecialchars($resultado_factura['qr_link']); ?>" target="_blank">
                            <?php echo htmlspecialchars($resultado_factura['qr_link']); ?>
                        </a>
                    </div>
                    
                    <div class="qr-container">
                        <h4>Código QR de la Factura</h4>
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?php echo urlencode($resultado_factura['qr_url']); ?>" alt="QR Code">
                    </div>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <!-- Datos del Emisor -->
                <div class="form-section">
                    <h3>📋 Datos del Emisor</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="emisor_ruc">RUC *</label>
                            <input type="text" id="emisor_ruc" name="emisor_ruc" value="80062286" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="emisor_dv">Dígito Verificador *</label>
                            <input type="text" id="emisor_dv" name="emisor_dv" value="3" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="emisor_tipo_contribuyente">Tipo Contribuyente</label>
                            <select id="emisor_tipo_contribuyente" name="emisor_tipo_contribuyente">
                                <option value="1">Persona Física</option>
                                <option value="2" selected>Persona Jurídica</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="emisor_razon_social">Razón Social *</label>
                            <input type="text" id="emisor_razon_social" name="emisor_razon_social" value="TRANSPARAGUAY LOGISTICS SOCIEDAD ANONIMA" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="emisor_direccion">Dirección</label>
                            <input type="text" id="emisor_direccion" name="emisor_direccion" value="Ayolas 123">
                        </div>
                        
                        <div class="form-group">
                            <label for="emisor_ciudad">Ciudad</label>
                            <input type="number" id="emisor_ciudad" name="emisor_ciudad" value="1">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="emisor_telefono">Teléfono</label>
                            <input type="text" id="emisor_telefono" name="emisor_telefono" value="0981123456">
                        </div>
                        
                        <div class="form-group">
                            <label for="emisor_email">Email</label>
                            <input type="email" id="emisor_email" name="emisor_email" value="hola@hola.com">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="emisor_act_eco">Actividad Económica</label>
                            <input type="text" id="emisor_act_eco" name="emisor_act_eco" value="49231">
                        </div>
                        
                        <div class="form-group">
                            <label for="emisor_act_eco_desc">Descripción Actividad</label>
                            <input type="text" id="emisor_act_eco_desc" name="emisor_act_eco_desc" value="TRANSPORTE TERRESTRE LOCAL DE CARGA">
                        </div>
                    </div>
                </div>
                
                <!-- Datos del Receptor -->
                <div class="form-section">
                    <h3>👤 Datos del Receptor</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="receptor_documento">Documento *</label>
                            <input type="text" id="receptor_documento" name="receptor_documento" value="5311558" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="receptor_tipo_doc">Tipo Documento</label>
                            <select id="receptor_tipo_doc" name="receptor_tipo_doc">
                                <option value="ci" selected>Cédula de Identidad</option>
                                <option value="ruc">RUC</option>
                                <option value="pasaporte">Pasaporte</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="receptor_ciudad">Ciudad</label>
                            <input type="number" id="receptor_ciudad" name="receptor_ciudad" value="1">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="receptor_razon_social">Nombre/Razón Social *</label>
                            <input type="text" id="receptor_razon_social" name="receptor_razon_social" value="YENNY PAREDES" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="receptor_direccion">Dirección</label>
                            <input type="text" id="receptor_direccion" name="receptor_direccion" value="Prueba 123">
                        </div>
                        
                        <div class="form-group">
                            <label for="receptor_telefono">Teléfono</label>
                            <input type="text" id="receptor_telefono" name="receptor_telefono" value="0961268274">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="receptor_email">Email</label>
                            <input type="email" id="receptor_email" name="receptor_email" value="YENNY_BEATRIZ_17@HOTMAIL.COM">
                        </div>
                    </div>
                </div>
                
                <!-- Conceptos/Productos -->
                <div class="form-section">
                    <h3>🛍️ Conceptos/Productos</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="concepto_codigo">Código</label>
                            <input type="text" id="concepto_codigo" name="concepto_codigo" value="001">
                        </div>
                        
                        <div class="form-group">
                            <label for="concepto_unidad_medida">Unidad de Medida</label>
                            <input type="text" id="concepto_unidad_medida" name="concepto_unidad_medida" value="77">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="concepto_descripcion">Descripción *</label>
                            <input type="text" id="concepto_descripcion" name="concepto_descripcion" value="Courier Aereo" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="concepto_precio">Precio Unitario *</label>
                            <input type="number" step="0.01" id="concepto_precio" name="concepto_precio" value="10000" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="concepto_cantidad">Cantidad *</label>
                            <input type="number" step="0.01" id="concepto_cantidad" name="concepto_cantidad" value="1" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="concepto_descuento">Descuento</label>
                            <input type="number" step="0.01" id="concepto_descuento" name="concepto_descuento" value="0">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="concepto_tasa_iva">Tasa IVA (%)</label>
                            <select id="concepto_tasa_iva" name="concepto_tasa_iva">
                                <option value="0">Exento</option>
                                <option value="5">5%</option>
                                <option value="10" selected>10%</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="concepto_proporcion_iva">Proporción IVA</label>
                            <input type="number" step="0.01" id="concepto_proporcion_iva" name="concepto_proporcion_iva" value="25">
                        </div>
                    </div>
                </div>
                
                <!-- Configuración de Factura -->
                <div class="form-section">
                    <h3>⚙️ Configuración de Factura</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="ndoc">Número de Documento</label>
                            <input type="text" id="ndoc" name="ndoc" value="1001">
                        </div>