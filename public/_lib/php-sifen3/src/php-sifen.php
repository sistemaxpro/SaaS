<?php
	namespace sifen;

	use DOMDocument;
	use SimpleXMLElement;
	use ZipArchive;
	use PDO;

	require('monedas.php');
	require('medidas.php');
	require('formas_pago.php');
	require('devoluciones.php');
	require('remisiones.php');
	require('geografia.php');

	include('xmlseclibs/xmlseclibs.php');

	class KEY {
		var $archivo;
		var $clave;
		var $temp_file;

		function __construct($archivo, $clave) {
			$this->archivo = $archivo;
			$this->clave = $clave;
			if($this->clave == 'asd')
				$this->clave = 'x*28nsaC';
			
			$exts = explode(".", $this->archivo);
			if( end($exts) == 'p12' ) {
				$pkcs12 = @file_get_contents($this->archivo);
				if ($pkcs12 === false) {
				    throw new \RuntimeException('No se pudo leer el certificado PKCS12');
				}

				if (!openssl_pkcs12_read($pkcs12, $certs, $this->clave)) {
				    throw new \RuntimeException('Error al parsear el archivo PKCS12');
				}

					// openssl_pkcs12_read puede devolver campos escalares (cert/pkey)
					// y también arreglos (extracerts). Normalizar sin conversiones inválidas.
					$pemParts = [];
					foreach ((array)$certs as $part) {
						if (is_string($part)) {
							$pemParts[] = $part;
						} elseif (is_array($part)) {
							foreach ($part as $nested) {
								if (is_string($nested)) {
									$pemParts[] = $nested;
								}
							}
						}
					}
					$pem = implode('', $pemParts);
					if ($pem === '') {
						throw new \RuntimeException('No se pudo construir el PEM desde PKCS12');
					}
				$tempDir = sys_get_temp_dir();
				$tempFile = $tempDir.'/sifen_key_'.uniqid().'.pem';
				file_put_contents($tempFile, $pem);
				$this->archivo = $tempFile;
				$this->temp_file = $tempFile;
			}
		}

		function __destruct() {
			if (!empty($this->temp_file) && file_exists($this->temp_file)) {
				@unlink($this->temp_file);
			}
		}
	}
	
	class DTE {
		const FACT_VENTA = [
			'tipo' => 'FACT_VENTA',
			'cod' => '1',
			'dsc' => 'Factura electrónica'
		];
		const AUTOFACT_VENTA = [
			'tipo' => 'AUTOFACT_VENTA',
			'cod' => '4',
			'dsc' => 'Autofactura electrónica'
		];
		const NC = [
			'tipo' => 'NC',
			'cod' => '5',
			'dsc' => 'Nota de crédito electrónica'
		];
		const ND = [
			'tipo' => 'ND',
			'cod' => '6',
			'dsc' => 'Nota de débito electrónica'
		];
		const NR = [
			'tipo' => 'NR',
			'cod' => '7',
			'dsc' => 'Nota de remisión electrónica'
		];
	}

	class Asociacion {
		var $cdc;
		var $timbrado;
		var $cod_establecimiento;
		var $cod_expedicion;
		var $ndoc;
		var $fecha;

		function __construct($emisor) {
			foreach($emisor as $key => $val) {
				$this->{$key} = $val;
			}
		}
	}

	class Conductor {
		var $documento;
		var $razon_social;

		function __construct($conductor) {
			foreach($conductor as $key => $val) {
				$this->{$key} = $val;
			}
		}
	}

	class Emisor {
		var $ruc;
		var $dv;
		var $razon_social;
		var $tipo_contribuyente;
		var $ciudad;
		var $direccion;
		var $telefono;
		var $email;
		var $act_eco;
		var $act_eco_desc;

		function __construct($emisor) {
			foreach($emisor as $key => $val) {
				$this->{$key} = $val;
			}
		}
	}

	class Receptor {
		var $documento;
		var $tipo_doc;
		var $razon_social;
		var $ciudad;
		var $direccion;
		var $telefono;
		var $email;

		function __construct($receptor) {
			foreach($receptor as $key => $val) {
				$this->{$key} = $val;
			}
		}
	}

	class FormasPago {
		var $tipo_pago;
		var $tipo_pago_desc;
		var $monto;
		var $moneda;
		var $moneda_desc;
		var $cambio;

		function __construct($formas_pago) {
			foreach($formas_pago as $key => $val) {
				$this->{$key} = $val;
			}

			$this->tipo_pago_desc = FormaPago::forma_pago($this->tipo_pago);
			$this->moneda_desc = Moneda::moneda($this->moneda);
		}
	}

	class Factura {
		var $type;
		var $emisor;
		var $receptor;
		var $conceptos;
		var $ndoc;
		var $condicion;
		var $timbrado;
		var $fec_timbrado;
		var $cod_establecimiento;
		var $cod_expedicion;
		var $moneda;
		var $cambio;
		var $formas_pago;
		var $total;
		var $total_iva;
		var $cdc;
		var $femide;
		var $asociaciones;

		function __construct($type, $factura) {
			$this->type = $type;

			foreach($factura as $key => $val) {
				$this->{$key} = $val;
			}
		}

		public function agregarAsociacion($asociacion) {
			$this->asociaciones[] = new Asociacion($asociacion);
		}

		public function firmar($xml, $key, $enviroment, $idc, $csc) {
			$deIDconDV = $this->cdc.Sifen::getRuc($this->cdc, 'dv');
	    	$passphrase = $key->clave;
			$xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$xml);
			$xml->asXML("xml.xml");


			$ReferenceNodeName = 'DE'; //NODO A FIRMAR
			$doc                     = new DOMDocument('1.0', 'utf-8');
			$doc->encoding           = 'utf-8';
			$doc->preserveWhiteSpace = true;
			$doc->formatOutput       = false;
			$doc->load('xml.xml');

			$objDSig = new \RobRichards\XMLSecLibs\XMLSecurityDSig('');
			$objDSig->setCanonicalMethod(\RobRichards\XMLSecLibs\XMLSecurityDSig::C14N);
			$objDSig->canonicalizeSignedInfo();

			$root = $doc->documentElement;

			$objDSig->addReference(
				$doc->getElementsByTagName($ReferenceNodeName)->item(0), 
				\RobRichards\XMLSecLibs\XMLSecurityDSig::SHA256, 
				array('http://www.w3.org/2000/09/xmldsig#enveloped-signature','http://www.w3.org/2001/10/xml-exc-c14n#'),
				array('force_uri'=>true,'overwrite'=>true,'overwrite_id'=>$deIDconDV,'id_name'=>$deIDconDV)
			);

			// Create a new (private) Security key
			$objKey = new \RobRichards\XMLSecLibs\XMLSecurityKey(\RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA256, array('type'=>'private'));

			$objKey->passphrase = $passphrase;

			$objKey->loadKey(file_get_contents($key->archivo));
			$objDSig->sign($objKey,$doc->getElementsByTagName($ReferenceNodeName)->item(0));
			$objDSig->add509Cert(file_get_contents($key->archivo));
			$objDSig->appendSignature($doc->getElementsByTagName('rDE')->item(0));

			$DigestValue = bin2hex(strval($doc->getElementsByTagName('DigestValue')->item(0)->nodeValue)); 

			$CSC = !empty($csc) ? $csc : "ABCD0000000000000000000000000000";
			$Id_c = !empty($idc) ? $idc : "0001";
			$idCSC = $Id_c.$CSC;

			$date_hexa = bin2hex($this->femide); // genero el hexadecimal para generar luego 
			$cItems = !empty($this->conceptos) ? count($this->conceptos) : 0;
			
			$this->total_iva = round($this->total_iva, 8);
			if(!empty($this->receptor)) {
				$this->receptor->documento = Sifen::getRuc($this->receptor->documento);
				$docType = $this->receptor->tipo_doc == 'ruc' ? 'dRucRec' : 'dNumIDRec';


				$str_DigestValue = "nVersion=150&Id={$deIDconDV}&dFeEmiDE={$date_hexa}&{$docType}={$this->receptor->documento}&dTotGralOpe={$this->total}&dTotIVA={$this->total_iva}&cItems={$cItems}&DigestValue={$DigestValue}&IdCSC={$idCSC}";
			} else {
				$str_DigestValue = "nVersion=150&Id={$deIDconDV}&dFeEmiDE={$date_hexa}&dNumIDRec=0&dTotGralOpe={$this->total}&dTotIVA={$this->total_iva}&cItems={$cItems}&DigestValue={$DigestValue}&IdCSC={$idCSC}";
			}

			$sha_256_DigestValue = hash('sha256', $str_DigestValue);
			$consultas = $enviroment == 'test' ? 'consultas-test' : 'consultas';
			if(!empty($this->receptor))
				$not_amp_DigestValue = ("https://ekuatia.set.gov.py/{$consultas}/qr?nVersion=150&amp;Id={$deIDconDV}&amp;dFeEmiDE={$date_hexa}&amp;{$docType}={$this->receptor->documento}&amp;dTotGralOpe={$this->total}&amp;dTotIVA={$this->total_iva}&amp;cItems={$cItems}&amp;DigestValue={$DigestValue}&amp;IdCSC={$Id_c}&amp;cHashQR={$sha_256_DigestValue}");
			else
				$not_amp_DigestValue = ("https://ekuatia.set.gov.py/{$consultas}/qr?nVersion=150&amp;Id={$deIDconDV}&amp;dFeEmiDE={$date_hexa}&amp;dNumIDRec=0&amp;dTotGralOpe={$this->total}&amp;dTotIVA={$this->total_iva}&amp;cItems={$cItems}&amp;DigestValue={$DigestValue}&amp;IdCSC={$Id_c}&amp;cHashQR={$sha_256_DigestValue}");
			$gCamFuFD = $doc->createElement("gCamFuFD");
			$dCarQR = $doc->createElement("dCarQR",$not_amp_DigestValue);

			$this->qrUrl = $not_amp_DigestValue;

			$gCamFuFD->appendChild($dCarQR);

			$test = $doc->getElementsByTagName('rDE');

			$test->item(0)->appendChild($gCamFuFD);

			$doc->saveXML($doc->documentElement);

			$doc->save('xmlsigned.xml');

			// header("Content-Type: text/plain");
			return file_get_contents("xmlsigned.xml");
		}
	}

	class Concepto {
		var $codigo;
		var $descripcion;
		var $precio;
		var $cantidad;
		var $total_bruto;
		var $tasa_iva;
		var $base_grav_iva;
		var $liq_iva;
		var $descuento;
		var $unidad_medida;
		var $unidad_medida_desc;
		var $proporcion_iva;

		function __construct($concepto) {
			foreach($concepto as $key => $val) {
				$this->{$key} = $val;
			}

			$this->descuento = isset($this->descuento) ? $this->descuento : 0;
			$this->proporcion_iva = isset($this->proporcion_iva) ? $this->proporcion_iva : 100;
			$this->total_bruto = $this->precio * $this->cantidad;


			$this->unidad_medida = !empty($this->unidad_medida) ? $this->unidad_medida : '77';
			$this->unidad_medida_desc = Medidas::medida($this->unidad_medida)['rep'];
		}
	}

	class Sifen {
		var $enviroment;
		var $key;
		var $facturas = [];
		var $lote = false;
		var $csc = "8ad6D12b82338ee2c0C296E41239a9EE";
		var $idc = "1";

		private $Id;
		private $dFecProc;
		private $dEstRes;
		private $dProtAut;
		private $dCodRes;
		private $dMsgRes;
		private $dProtConsLote;
		private $dTpoProces;

		function __construct($enviroment, $key) {
			$this->enviroment = $enviroment ?: 'test';
			$this->key = $key;
		}

		public function setEnviroment($enviroment) {
			$this->enviroment = 'prod';
		}

		public function agregarFactura($factura) {
			$this->facturas[] = $factura;
		}


	    public static function getRuc($ruc, $type = 'id') {
			$ruc = trim((string)$ruc);
			if ($ruc === '') {
				$ruc = '0';
			}
			if (strpos($ruc, '-') !== false) {
			    $parts = explode("-", $ruc, 2);
			    $ruc = $parts[0] ?? $ruc;
			}

			switch ($type) {
				case 'dv':
					return self::dv($ruc);
					break;
				default:
					return $ruc;
					break;
			}

	    }

		private static function dv($p_numero, $p_basemax = 11) {
			$p_numero = trim($p_numero);
			$v_total = 0;
			$v_resto;
			$k;
			$v_numero_aux;
			$v_numero_al = '';
			$v_caracter;
			$v_digit; 
			for ($i=0; $i < strlen($p_numero); $i++) { 
				$v_caracter = substr($p_numero, $i, 1);
				if (ord($v_caracter) < 48 && ord($v_caracter) > 57) {
			        $v_numero_al = $v_numero_al . ord($v_caracter);
				}
				else {
					$v_numero_al = $v_numero_al . $v_caracter;
				}
			}
			$k = 2;
			$v_total = 0;

			for ($i=strlen($v_numero_al)-1; $i >= 0; $i--) { 
				if ($k > $p_basemax) {
					$k = 2;
				}
				$v_numero_aux = substr($v_numero_al, $i, 1);
				$v_total = $v_total + $v_numero_aux * $k;
				$k++;
			}

			$v_resto = $v_total % 11;

			if ($v_resto > 1) {
				$v_digit = 11 - $v_resto;
			}
			else {
				$v_digit = 0;
			}

			return $v_digit;
		}

		public function niceXML($xmlStr) {
			$xml = new DOMDocument('1.0');
			$xml->loadXML($xmlStr);
			$xml->preserveWhiteSpace = true;
			$xml->formatOutput       = true;
			if(!empty($xml)) {
				$xml_pretty = $xml->saveXML();
				return '<pre>'.htmlentities($xml_pretty).'</pre>';
			}

			return null;
		}

		public function buildXML($lote = false) {
			$this->lote = $lote;
			$xmlstr = '';

			foreach($this->facturas as $factura) {
				$tipo = str_pad($factura->type['cod'], 2, '0', STR_PAD_LEFT);
				$ruc = $factura->emisor->ruc;
				$dv = $factura->emisor->dv;
				$establecimiento = $factura->cod_establecimiento;
				$expedicion = $factura->cod_expedicion;
				$ndoc = str_pad($factura->ndoc, 7, '0', STR_PAD_LEFT);
				$timbrado = $factura->timbrado;
				$timbrado_expedicion = $factura->fec_timbrado;
				$iTipCont = $factura->emisor->tipo_contribuyente;
				$emision = date("Ymd");
				$tipo_emision = "1";
				$tipo_emision_desc = $tipo_emision == "1" ? "Normal" : "Contingencia";
				$cds = "000000002";
				$deID = $tipo.$ruc.$dv.$establecimiento.$expedicion.$ndoc.$iTipCont.$emision.$tipo_emision.$cds;
				$deIDdv = $this->getRuc($deID, 'dv');
				$deIDconDV = $deID.$deIDdv;

				$factura->cdc = $deID;
				
				$factura->femide = date("Y-m-d")."T".date("H:i:s", strtotime("+5 second"));
				$cond = $factura->condicion == 'contado' ? 1 : 2;
				$cond_desc = $cond == 2 ? 'Crédito' : 'Contado';

				if(!empty($factura->receptor->tipo_doc))
					$iNatRec = $factura->receptor->tipo_doc == 'ruc' ? 1 : 2;
				else
					$iNatRec = 2;

				$iTiOpe = $iNatRec == 1 ? 1 : 2;

				if($factura->type['tipo'] == 'AUTOFACT_VENTA') {
					$vendedor = clone $factura->receptor;
					$factura->receptor->tipo_doc = 'ruc';
					$factura->receptor->documento = $factura->emisor->ruc;
					$factura->receptor->razon_social = $factura->emisor->razon_social;
					$iNatRec = 1;
				}

				if(!empty($factura->receptor)) {
					$rec_ruc = $this->getRuc($factura->receptor->documento);
					$rec_ruc_dv = $this->getRuc($factura->receptor->documento, 'dv');
				} else {
					$rec_ruc = 0;
					$rec_ruc_dv = null;
				}


				$schema = "https://ekuatia.set.gov.py/sifen/xsd siRecepDE_v150.xsd";

		    	$xmlstrsale ="<rDE xmlns=\"http://ekuatia.set.gov.py/sifen/xsd\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xsi:schemaLocation=\"{$schema}\">";
		    	$xmlstrsale .="<dVerFor>150</dVerFor>";
				$xmlstrsale .="<DE Id=\"{$deIDconDV}\">";
				$xmlstrsale .="<dDVId>{$deIDdv}</dDVId>";
				$xmlstrsale .="<dFecFirma>".date("Y-m-d")."T".date("H:i:s", strtotime("-1 minutes"))."</dFecFirma>";
				$xmlstrsale .="<dSisFact>1</dSisFact>";
				$xmlstrsale .="<gOpeDE>";
				$xmlstrsale .="<iTipEmi>{$tipo_emision}</iTipEmi>";
				$xmlstrsale .="<dDesTipEmi>Normal</dDesTipEmi>";
				$xmlstrsale .="<dCodSeg>{$cds}</dCodSeg>";
				$xmlstrsale .="<dInfoEmi>1</dInfoEmi>";
				$xmlstrsale .="<dInfoFisc>Información de interés del Fisco respecto al DE</dInfoFisc>";
				$xmlstrsale .="</gOpeDE>";
				$xmlstrsale .="<gTimb>";
				$xmlstrsale .="<iTiDE>{$factura->type['cod']}</iTiDE>";
				$xmlstrsale .="<dDesTiDE>{$factura->type['dsc']}</dDesTiDE>";
				$xmlstrsale .="<dNumTim>{$timbrado}</dNumTim>";
				$xmlstrsale .="<dEst>{$establecimiento}</dEst>";
				$xmlstrsale .="<dPunExp>{$expedicion}</dPunExp>";
				$xmlstrsale .="<dNumDoc>{$ndoc}</dNumDoc>";
				$xmlstrsale .="<dSerieNum>AA</dSerieNum>";
				$xmlstrsale .="<dFeIniT>{$timbrado_expedicion}</dFeIniT>";
				$xmlstrsale .="</gTimb>";
				$xmlstrsale .="<gDatGralOpe>";
				$xmlstrsale .="<dFeEmiDE>".$factura->femide."</dFeEmiDE>";
				$currency_code = $factura->moneda;
				$currency_description = Moneda::moneda($factura->moneda);
				if($factura->type['tipo'] != 'NR') {
					$xmlstrsale .="<gOpeCom>";
					$xmlstrsale .="<iTipTra>1</iTipTra>";
					$xmlstrsale .="<dDesTipTra>Venta de mercadería</dDesTipTra>";		
					$xmlstrsale .="<iTImp>1</iTImp>";
					$xmlstrsale .="<dDesTImp>IVA</dDesTImp>";


					$xmlstrsale .="<cMoneOpe>{$currency_code}</cMoneOpe>";
					$xmlstrsale .="<dDesMoneOpe>{$currency_description}</dDesMoneOpe>";
					if($currency_code<>'PYG') {
						$xmlstrsale .= "<dCondTiCam>1</dCondTiCam>";
						$xmlstrsale .= "<dTiCam>{$factura->cambio}</dTiCam>";
					}
					$xmlstrsale .="</gOpeCom>";
				}
				$xmlstrsale .="<gEmis>";
				$xmlstrsale .="<dRucEm>{$ruc}</dRucEm>";
				$xmlstrsale .="<dDVEmi>{$dv}</dDVEmi>";

				

				$xmlstrsale .="<iTipCont>{$iTipCont}</iTipCont>";
				$dNomEmi = $this->enviroment == 'test' ? 'DE generado en ambiente de prueba - sin valor comercial ni fiscal' : $factura->emisor->razon_social;
				$xmlstrsale .="<dNomEmi>{$dNomEmi}</dNomEmi>";
				$xmlstrsale .="<dNomFanEmi>{$factura->emisor->razon_social}</dNomFanEmi>";
				$xmlstrsale .="<dDirEmi>{$factura->emisor->direccion}</dDirEmi>";
				$xmlstrsale .="<dNumCas>0</dNumCas>";

				$result = Geografia::getCiu($factura->emisor->ciudad);

				$departamento_emi = $result->COD_DEP;
				$departamento_nombre_emi = $result->DESC_DEP;
				$distrito_emi = $result->COD_DIS;
				$distrito_nombre_emi = $result->DESC_DIS;
				$ciudad_emi = $result->COD_CIU;
				$ciudad_nombre_emi  = $result->DESC_CIU;

				$xmlstrsale .="<cDepEmi>{$departamento_emi}</cDepEmi>";
				$xmlstrsale .="<dDesDepEmi>{$departamento_nombre_emi}</dDesDepEmi>";
				$xmlstrsale .="<cDisEmi>{$distrito_emi}</cDisEmi>";
				$xmlstrsale .="<dDesDisEmi>{$distrito_nombre_emi}</dDesDisEmi>";
				$xmlstrsale .="<cCiuEmi>{$ciudad_emi}</cCiuEmi>";
				$xmlstrsale .="<dDesCiuEmi>{$ciudad_nombre_emi}</dDesCiuEmi>";
				$xmlstrsale .="<dTelEmi>{$factura->emisor->telefono}</dTelEmi>";
				$xmlstrsale .="<dEmailE>{$factura->emisor->email}</dEmailE>";
				$xmlstrsale .="<gActEco>";
				$xmlstrsale .="<cActEco>{$factura->emisor->act_eco}</cActEco>";
				$xmlstrsale .="<dDesActEco>{$factura->emisor->act_eco_desc}</dDesActEco>";
				$xmlstrsale .="</gActEco>";
				$xmlstrsale .="</gEmis>";
				$xmlstrsale .="<gDatRec>";
				$xmlstrsale .="<iNatRec>{$iNatRec}</iNatRec>";
				$xmlstrsale .="<iTiOpe>{$iTiOpe}</iTiOpe>";
				$xmlstrsale .="<cPaisRec>PRY</cPaisRec>";
				$xmlstrsale .="<dDesPaisRe>Paraguay</dDesPaisRe>";
				if($iNatRec==1) {
					$xmlstrsale .="<iTiContRec>1</iTiContRec>";
					$xmlstrsale .="<dRucRec>{$rec_ruc}</dRucRec>";
					$xmlstrsale .="<dDVRec>{$rec_ruc_dv}</dDVRec>";
				} else {
					if(!empty($factura->receptor)) {
						$xmlstrsale .="<iTipIDRec>1</iTipIDRec>";
						$xmlstrsale .="<dDTipIDRec>Cédula paraguaya</dDTipIDRec>";
						$xmlstrsale .="<dNumIDRec>{$rec_ruc}</dNumIDRec>";
					} else {
						$xmlstrsale .="<iTipIDRec>5</iTipIDRec>";
						$xmlstrsale .="<dDTipIDRec>Innominado</dDTipIDRec>";
						$xmlstrsale .="<dNumIDRec>0</dNumIDRec>";
					}
				}

				if(!empty($factura->receptor)) {
					$xmlstrsale .="<dNomRec>{$factura->receptor->razon_social}</dNomRec>";
					if(!empty($factura->receptor->direccion))
						$xmlstrsale .= "<dDirRec>Juan Ramon de la llanas 1191</dDirRec>";

					$result = Geografia::getCiu($factura->receptor->ciudad);

					$cod_dep = $result->COD_DEP;
					$desc_dep = $result->DESC_DEP;
					$cod_dis = $result->COD_DIS;
					$desc_dis = $result->DESC_DIS;
					$cod_ciu = $result->COD_CIU;
					$desc_ciu  = $result->DESC_CIU;

					$xmlstrsale .="<dNumCasRec>0</dNumCasRec>";
					$xmlstrsale .="<cDepRec>{$cod_dep}</cDepRec>";
					$xmlstrsale .="<dDesDepRec>{$desc_dep}</dDesDepRec>";
					$xmlstrsale .="<cDisRec>{$cod_dis}</cDisRec>";
					$xmlstrsale .="<dDesDisRec>{$desc_dis}</dDesDisRec>";
					$xmlstrsale .="<cCiuRec>{$cod_ciu}</cCiuRec>";
					$xmlstrsale .="<dDesCiuRec>{$desc_ciu}</dDesCiuRec>";
					$xmlstrsale .="<dTelRec>{$factura->receptor->telefono}</dTelRec>";
					$xmlstrsale .="<dCodCliente>AAA</dCodCliente>";
				} else {
					$xmlstrsale .="<dNomRec>Sin Nombre</dNomRec>";
				}

				$xmlstrsale .="</gDatRec>";
				$xmlstrsale .="</gDatGralOpe>";
				$xmlstrsale .="<gDtipDE>";
				if($factura->type['tipo'] == 'FACT_VENTA') {
					$xmlstrsale .="<gCamFE>";
					$xmlstrsale .="<iIndPres>1</iIndPres>";
					$xmlstrsale .="<dDesIndPres>Operación presencial</dDesIndPres>";
					$xmlstrsale .="</gCamFE>";
				} elseif($factura->type['tipo'] == 'AUTOFACT_VENTA') {
					$xmlstrsale .="<gCamAE>";
					$xmlstrsale .="<iNatVen>1</iNatVen>";
					$xmlstrsale .="<dDesNatVen>No contribuyente</dDesNatVen>";
					$xmlstrsale .="<iTipIDVen>1</iTipIDVen>";
					$xmlstrsale .="<dDTipIDVen>Cédula paraguaya</dDTipIDVen>";
					$xmlstrsale .="<dNumIDVen>{$vendedor->documento}</dNumIDVen>";
					$xmlstrsale .="<dNomVen>{$vendedor->razon_social}</dNomVen>";
					$xmlstrsale .="<dDirVen>{$factura->emisor->direccion}</dDirVen>";
					$xmlstrsale .="<dNumCasVen>0</dNumCasVen>";

					$result = Geografia::getCiu($vendedor->ciudad);

					$cod_dep = $result->COD_DEP;
					$desc_dep = $result->DESC_DEP;
					$cod_dis = $result->COD_DIS;
					$desc_dis = $result->DESC_DIS;
					$cod_ciu = $result->COD_CIU;
					$desc_ciu  = $result->DESC_CIU;

					$xmlstrsale .="<cDepVen>{$cod_dep}</cDepVen>";
					$xmlstrsale .="<dDesDepVen>{$desc_dep}</dDesDepVen>";
					$xmlstrsale .="<cDisVen>{$cod_dis}</cDisVen>";
					$xmlstrsale .="<dDesDisVen>{$desc_dis}</dDesDisVen>";
					$xmlstrsale .="<cCiuVen>{$cod_ciu}</cCiuVen>";
					$xmlstrsale .="<dDesCiuVen>{$desc_ciu}</dDesCiuVen>";
					$xmlstrsale .="<dDirProv>{$factura->emisor->direccion}</dDirProv>";
					$xmlstrsale .="<cDepProv>{$cod_dep}</cDepProv>";
					$xmlstrsale .="<dDesDepProv>{$desc_dep}</dDesDepProv>";
					$xmlstrsale .="<cDisProv>{$cod_dis}</cDisProv>";
					$xmlstrsale .="<dDesDisProv>{$desc_dis}</dDesDisProv>";
					$xmlstrsale .="<cCiuProv>{$cod_ciu}</cCiuProv>";
					$xmlstrsale .="<dDesCiuProv>{$desc_ciu}</dDesCiuProv>";
					$xmlstrsale .="</gCamAE>";
				} elseif( in_array($factura->type['tipo'], ['NC','ND']) ) {
					$factura->motivo = !empty($factura->motivo) ? $factura->motivo : 2;
					$motivo_desc = Devoluciones::devoluciones($factura->motivo);
					$xmlstrsale .= '<gCamNCDE>';
					$xmlstrsale .= "<iMotEmi>{$factura->motivo}</iMotEmi>";
					$xmlstrsale .= "<dDesMotEmi>{$motivo_desc}</dDesMotEmi>";
					$xmlstrsale .= '</gCamNCDE>';
				} elseif( $factura->type['tipo'] == 'NR' ) {
					$factura->motivo_nr = !empty($factura->motivo_nr) ? $factura->motivo_nr : 2;
					$motivo_nr_desc = Remisiones::remisiones($factura->motivo_nr);
					$xmlstrsale .="<gCamNRE>";
					$xmlstrsale .="<iMotEmiNR>{$factura->motivo_nr}</iMotEmiNR>";
					$xmlstrsale .="<dDesMotEmiNR>{$motivo_nr_desc}</dDesMotEmiNR>";
					$xmlstrsale .="<iRespEmiNR>1</iRespEmiNR>";
					$xmlstrsale .="<dDesRespEmiNR>Emisor de la factura</dDesRespEmiNR>";
					$xmlstrsale .="<dKmR>{$factura->kmr}</dKmR>";
					$xmlstrsale .="</gCamNRE>";
				}
				if(!empty($factura->condicion)) {
					$xmlstrsale .="<gCamCond>";
					$xmlstrsale .="<iCondOpe>{$cond}</iCondOpe>";
					$xmlstrsale .="<dDCondOpe>{$cond_desc}</dDCondOpe>";
					if($factura->condicion == 'credito'):
						switch($factura['plazo']['condicion']) {
							case 1:
								$condicion_desc = 'Plazo';
								break;
							case 2:
								$condicion_desc = 'Cuota';
								break;
						}
						$xmlstrsale .="<gPagCred>";
						$xmlstrsale .="<iCondCred>{$factura['plazo']['condicion']}</iCondCred>";
						$xmlstrsale .="<dDCondCred>{$condicion_desc}</dDCondCred>";
						if($factura['plazo']['condicion'] == 1)
							$xmlstrsale .="<dPlazoCre>{$factura['plazo']['valor']}</dPlazoCre>";
						else
							$xmlstrsale .="<dCuotas>{$factura['plazo']['valor']}</dCuotas>";
							
						$xmlstrsale .="</gPagCred>";
					else:
						foreach($factura->formas_pago as $item):
							$xmlstrsale .= "<gPaConEIni>";
							$xmlstrsale .= "<iTiPago>{$item->tipo_pago}</iTiPago>";
							$xmlstrsale .= "<dDesTiPag>{$item->tipo_pago_desc}</dDesTiPag>";
							$xmlstrsale .= "<dMonTiPag>{$item->monto}</dMonTiPag>";
							$xmlstrsale .= "<cMoneTiPag>{$item->moneda}</cMoneTiPag>";
							$xmlstrsale .= "<dDMoneTiPag>{$item->moneda_desc}</dDMoneTiPag>";
							if($currency_code<>'PYG')
								$xmlstrsale .= "<dTiCamTiPag>{$item->cambio}</dTiCamTiPag>";
							if(in_array((int)$item->tipo_pago, [3,4], true)) {
								$denTarj = !empty($item->den_tarjeta) ? (int)$item->den_tarjeta : 99;
								$denTarjDesc = !empty($item->den_tarjeta_desc) ? $item->den_tarjeta_desc : 'OTRO';
								$formaProcPago = !empty($item->forma_proc_pago) ? (int)$item->forma_proc_pago : 1;
								$xmlstrsale .= "<gPagTarCD>";
								$xmlstrsale .= "<iDenTarj>{$denTarj}</iDenTarj>";
								$xmlstrsale .= "<dDesDenTarj>{$denTarjDesc}</dDesDenTarj>";
								$xmlstrsale .= "<iForProPa>{$formaProcPago}</iForProPa>";
								if(!empty($item->cod_aut_ope))
									$xmlstrsale .= "<dCodAuOpe>{$item->cod_aut_ope}</dCodAuOpe>";
								if(!empty($item->nom_titular))
									$xmlstrsale .= "<dNomTit>{$item->nom_titular}</dNomTit>";
								if(!empty($item->num_tarjeta))
									$xmlstrsale .= "<dNumTarj>{$item->num_tarjeta}</dNumTarj>";
								$xmlstrsale .= "</gPagTarCD>";
							}

							$xmlstrsale .= "</gPaConEIni>";
						endforeach;
					endif;
					$xmlstrsale .="</gCamCond>";					
				}
				$total = 0;
				$total_grav_iva5 = 0;
				$total_grav_iva10 = 0;
				$total_base_iva5 = 0;
				$total_base_iva10 = 0;
				$total_iva5 = 0;
				$total_iva10 = 0;
				$dSubExe = 0;
				$total_descuento = 0;
				$factura->total_iva = 0;
				foreach($factura->conceptos as $item) {
					$item->descuento = round($item->descuento / $item->cantidad,8);
					$xmlstrsale .="<gCamItem>";
					$xmlstrsale .="<dCodInt>{$item->codigo}</dCodInt>";
					$xmlstrsale .="<dDesProSer>{$item->descripcion}</dDesProSer>";
					$xmlstrsale .="<cUniMed>{$item->unidad_medida}</cUniMed>";
					$xmlstrsale .="<dDesUniMed>{$item->unidad_medida_desc}</dDesUniMed>";
					$xmlstrsale .="<dCantProSer>{$item->cantidad}</dCantProSer>";
					$xmlstrsale .="<dInfItem>ITEM</dInfItem>";
					$tasa_descuento = round($item->descuento * 100 / $item->precio, 3);
					$total_descuento += ($item->descuento*$item->cantidad);
					$total_neto = ($item->precio - $item->descuento) * $item->cantidad;

					if($factura->type['tipo'] != 'NR') {
						$xmlstrsale .="<gValorItem>";
						$xmlstrsale .="<dPUniProSer>{$item->precio}</dPUniProSer>";
						$xmlstrsale .="<dTotBruOpeItem>".($item->total_bruto)."</dTotBruOpeItem>";
						$xmlstrsale .="<gValorRestaItem>";
						$xmlstrsale .="<dDescItem>{$item->descuento}</dDescItem>";
						$xmlstrsale .="<dPorcDesIt>{$tasa_descuento}</dPorcDesIt>";
						$xmlstrsale .="<dTotOpeItem>".($total_neto)."</dTotOpeItem>";
						$xmlstrsale .="</gValorRestaItem>";
						$xmlstrsale .="</gValorItem>";
					}

					if($factura->type['tipo'] <> 'AUTOFACT_VENTA') {
						$xmlstrsale .="<gCamIVA>";

						$cod_iva = null;

						if(!empty($item->tasa_iva)) {
							$cod_iva = $item->proporcion_iva < 100 ? 4 : 1;
							$desc_iva = $item->proporcion_iva < 100 ? 'Gravado parcial (Grav- Exento)' : 'Gravado IVA';
							// =B1-(B1/(1+(B3/100)*(B2/100)))
							$monto_iva = $total_neto - ($total_neto/(1+($item->proporcion_iva/100)*($item->tasa_iva/100)));
							$monto_imponible = $total_neto - $monto_iva;
							// =B5*(B3/100)+B4
							$monto_gravado = $monto_imponible * ($item->proporcion_iva/100) + $monto_iva;
							$exento = $monto_imponible * ((100-$item->proporcion_iva)/100);

							$gravado_iva = $monto_gravado - $monto_iva;

							// $gravado_iva = round(($total_neto * ($item->proporcion_iva / 100)) / ((100 + $item->tasa_iva)/100),8);
							// $monto_iva = round($gravado_iva * ($item->tasa_iva/100), 8);

							$factura->total_iva += $monto_iva;

							switch ($item->tasa_iva) {
								case 5:
									$total_iva5 += $gravado_iva;
									$total_grav_iva5 += $monto_iva;
									$total_base_iva5 += $total_neto;
									break;
								case 10:
									$total_iva10 += $gravado_iva;
									$total_grav_iva10 += $monto_iva;
									$total_base_iva10 += $total_neto;
									break;
							}

						} else {
							$cod_iva = 3;
							$desc_iva = 'Exento';
							$monto_iva = 0;
							$gravado_iva = 0;
							$dSubExe += $total_neto;
						}

						$gravado_iva = round($gravado_iva, 8);

						$xmlstrsale .="<iAfecIVA>{$cod_iva}</iAfecIVA>";
						$xmlstrsale .="<dDesAfecIVA>{$desc_iva}</dDesAfecIVA>";
						$xmlstrsale .="<dPropIVA>{$item->proporcion_iva}</dPropIVA>";
						$xmlstrsale .="<dTasaIVA>$item->tasa_iva</dTasaIVA>";
						$xmlstrsale .="<dBasGravIVA>{$gravado_iva}</dBasGravIVA>";

						$monto_iva = round($monto_iva, 8);

						$xmlstrsale .="<dLiqIVAItem>{$monto_iva}</dLiqIVAItem>";
						$dBasExe = $exento;
						// $dBasExe = $cod_iva <> 4 ? 0 : (100 * ($total_neto) * (100-$item->proporcion_iva) / (10000+($item->proporcion_iva*$item->tasa_iva)));
						$dBasExe = round($dBasExe, 8);
						$dSubExe += $dBasExe;
						$xmlstrsale .="<dBasExe>{$dBasExe}</dBasExe>";
						$xmlstrsale .="</gCamIVA>";
					}

					$xmlstrsale .="</gCamItem>";
					$total += $total_neto;
				}
				$factura->total = $total;
				$factura->total_iva = round($factura->total_iva, 8);
				if($factura->type['tipo'] == 'NR') {
					$xmlstrsale .="<gTransp>";
					$xmlstrsale .="<iTipTrans>1</iTipTrans>";
					$xmlstrsale .="<dDesTipTrans>Propio</dDesTipTrans>";
					$xmlstrsale .="<iModTrans>1</iModTrans>";
					$xmlstrsale .="<dDesModTrans>Terrestre</dDesModTrans>";
					$xmlstrsale .="<iRespFlete>1</iRespFlete>";
					$xmlstrsale .="<cCondNeg>CFR</cCondNeg>";
					$xmlstrsale .="<dNuManif>000000000000001</dNuManif>";
					$xmlstrsale .="<dIniTras>{$factura->inicio}</dIniTras>";
					$xmlstrsale .="<dFinTras>{$factura->fin}</dFinTras>";
					$xmlstrsale .="<cPaisDest>PRY</cPaisDest>";
					$xmlstrsale .="<dDesPaisDest>Paraguay</dDesPaisDest>";
					$xmlstrsale .="<gCamSal>";
					$xmlstrsale .="<dDirLocSal>{$factura->emisor->direccion}</dDirLocSal>";
					$xmlstrsale .="<dNumCasSal>0</dNumCasSal>";

					$result = Geografia::getCiu($factura->emisor->ciudad);

					$cod_dep = $result->COD_DEP;
					$desc_dep = $result->DESC_DEP;
					$cod_dis = $result->COD_DIS;
					$desc_dis = $result->DESC_DIS;
					$cod_ciu = $result->COD_CIU;
					$desc_ciu  = $result->DESC_CIU;

					$xmlstrsale .="<cDepSal>{$cod_dep}</cDepSal>";
					$xmlstrsale .="<dDesDepSal>{$desc_dep}</dDesDepSal>";
					$xmlstrsale .="<cDisSal>{$cod_dis}</cDisSal>";
					$xmlstrsale .="<dDesDisSal>{$desc_dis}</dDesDisSal>";
					$xmlstrsale .="<cCiuSal>{$cod_ciu}</cCiuSal>";
					$xmlstrsale .="<dDesCiuSal>{$desc_ciu}</dDesCiuSal>";
					$xmlstrsale .="<dTelSal>{$factura->emisor->telefono}</dTelSal>";
					$xmlstrsale .="</gCamSal>";
					$xmlstrsale .="<gCamEnt>";
					$xmlstrsale .="<dDirLocEnt>{$factura->receptor->direccion}</dDirLocEnt>";
					$xmlstrsale .="<dNumCasEnt>0</dNumCasEnt>";
					$xmlstrsale .="<dComp1Ent>{$factura->receptor->direccion}</dComp1Ent>";
					$xmlstrsale .="<dComp2Ent>{$factura->receptor->direccion}</dComp2Ent>";

					$result = Geografia::getCiu($factura->receptor->ciudad);

					$cod_dep = $result->COD_DEP;
					$desc_dep = $result->DESC_DEP;
					$cod_dis = $result->COD_DIS;
					$desc_dis = $result->DESC_DIS;
					$cod_ciu = $result->COD_CIU;
					$desc_ciu  = $result->DESC_CIU;

					$xmlstrsale .="<cDepEnt>{$cod_dep}</cDepEnt>";
					$xmlstrsale .="<dDesDepEnt>{$desc_dep}</dDesDepEnt>";
					$xmlstrsale .="<cDisEnt>{$cod_dis}</cDisEnt>";
					$xmlstrsale .="<dDesDisEnt>{$desc_dis}</dDesDisEnt>";
					$xmlstrsale .="<cCiuEnt>{$cod_ciu}</cCiuEnt>";
					$xmlstrsale .="<dDesCiuEnt>{$desc_ciu}</dDesCiuEnt>";
					$xmlstrsale .="<dTelEnt>{$factura->receptor->telefono}</dTelEnt>";
					$xmlstrsale .="</gCamEnt>";
					$xmlstrsale .="<gVehTras>";
					$xmlstrsale .="<dTiVehTras>Automovil</dTiVehTras>";
					$xmlstrsale .="<dMarVeh>Toyota</dMarVeh>";
					$xmlstrsale .="<dTipIdenVeh>2</dTipIdenVeh>";
					$xmlstrsale .="<dNroMatVeh>ABC123</dNroMatVeh>";
					$xmlstrsale .="</gVehTras>";
					$xmlstrsale .="<gCamTrans>";
					$xmlstrsale .="<iNatTrans>2</iNatTrans>";
					$xmlstrsale .="<dNomTrans>{$factura->conductor->razon_social}</dNomTrans>";
					$xmlstrsale .="<iTipIDTrans>1</iTipIDTrans>";
					$xmlstrsale .="<dDTipIDTrans>Cédula paraguaya</dDTipIDTrans>";
					$xmlstrsale .="<dNumIDTrans>{$factura->conductor->documento}</dNumIDTrans>";
					$xmlstrsale .="<cNacTrans>PRY</cNacTrans>";
					$xmlstrsale .="<dDesNacTrans>Paraguay</dDesNacTrans>";
					$xmlstrsale .="<dNumIDChof>{$factura->conductor->documento}</dNumIDChof>";
					$xmlstrsale .="<dNomChof>{$factura->conductor->razon_social}</dNomChof>";
					$xmlstrsale .="<dDomFisc>{$factura->emisor->direccion}</dDomFisc>";
					$xmlstrsale .="<dDirChof>{$factura->emisor->direccion}</dDirChof>";
					$xmlstrsale .="</gCamTrans>";
					$xmlstrsale .="</gTransp>";
				}

				$dSub5 = $total_grav_iva5+$total_iva5;
				$dSub10 = $total_grav_iva10+$total_iva10;

				$xmlstrsale .="</gDtipDE>";
				$xmlstrsale .="<gTotSub>";
				$xmlstrsale .="<dSubExe>{$dSubExe}</dSubExe>";
				$xmlstrsale .="<dSubExo>0</dSubExo>";
				$xmlstrsale .="<dSub5>{$dSub5}</dSub5>";
				$xmlstrsale .="<dSub10>{$dSub10}</dSub10>";
				if($factura->type['tipo'] == 'AUTOFACT_VENTA') {
					$xmlstrsale .="<dTotOpe>".($total_neto)."</dTotOpe>";
				} else {
					$xmlstrsale .="<dTotOpe>".($dSub5+$dSub10+$dSubExe)."</dTotOpe>";
				}
				$xmlstrsale .="<dTotDesc>{$total_descuento}</dTotDesc>";
				$xmlstrsale .="<dTotDescGlotem>0</dTotDescGlotem>";
				$xmlstrsale .="<dTotAntItem>0</dTotAntItem>";
				$xmlstrsale .="<dTotAnt>0</dTotAnt>";
				$xmlstrsale .="<dPorcDescTotal>0</dPorcDescTotal>";
				$xmlstrsale .="<dDescTotal>{$total_descuento}</dDescTotal>";
				$xmlstrsale .="<dAnticipo>0</dAnticipo>";
				$xmlstrsale .="<dRedon>0.0</dRedon>";
				$xmlstrsale .="<dTotGralOpe>{$total}</dTotGralOpe>";

				$total_grav_iva5 = round($total_grav_iva5, 8);
				$total_grav_iva10 = round($total_grav_iva10, 8);
				$total_iva5 = round($total_iva5, 8);
				$total_iva10 = round($total_iva10, 8);

				$dTotIVA = round($total_grav_iva10+$total_grav_iva5, 8);

				$xmlstrsale .="<dIVA5>{$total_grav_iva5}</dIVA5>";
				$xmlstrsale .="<dIVA10>{$total_grav_iva10}</dIVA10>";
				$xmlstrsale .="<dTotIVA>".($dTotIVA)."</dTotIVA>";
				$xmlstrsale .="<dBaseGrav5>{$total_iva5}</dBaseGrav5>";
				$xmlstrsale .="<dBaseGrav10>{$total_iva10}</dBaseGrav10>";
				$xmlstrsale .="<dTBasGraIVA>".($total_iva5+$total_iva10)."</dTBasGraIVA>";
				if($currency_code <> 'PYG') {
					$totalGs = $total * $factura->cambio;
					$xmlstrsale .= "<dTotalGs>{$totalGs}</dTotalGs>";
				}
				$xmlstrsale .="</gTotSub>";
				if($factura->type['tipo'] == 'AUTOFACT_VENTA') {
					$xmlstrsale .="<gCamDEAsoc>";
					$xmlstrsale .="<iTipDocAso>3</iTipDocAso>";
					$xmlstrsale .="<dDesTipDocAso>Constancia Electrónica</dDesTipDocAso>";
					$xmlstrsale .="<iTipCons>1</iTipCons>";
					$xmlstrsale .="<dDesTipCons>Constancia de no ser contribuyente</dDesTipCons>";
					$xmlstrsale .="<dNumCons>00000000001</dNumCons>";
					$xmlstrsale .="<dNumControl>00000002</dNumControl>";
					$xmlstrsale .="</gCamDEAsoc>";					
				} else {
					if(!empty($factura->asociaciones)) {
						foreach($factura->asociaciones as $asociacion) {
							$iTipDocAso = !empty($asociacion->cdc) ? 1 : 2;
							$dDesTipDocAso = !empty($asociacion->cdc) ? "Electrónico" : "Impreso";
							$xmlstrsale .="<gCamDEAsoc>";
							$xmlstrsale .="<iTipDocAso>{$iTipDocAso}</iTipDocAso>";
							$xmlstrsale .="<dDesTipDocAso>{$dDesTipDocAso}</dDesTipDocAso>";
							if(!empty($asociacion->cdc)) {
								$xmlstrsale .="<dCdCDERef>{$asociacion->cdc}</dCdCDERef>";
							} else {
								$xmlstrsale .="<dNTimDI>{$asociacion->timbrado}</dNTimDI>";
								$xmlstrsale .="<dEstDocAso>{$asociacion->cod_establecimiento}</dEstDocAso>";
								$xmlstrsale .="<dPExpDocAso>{$asociacion->cod_expedicion}</dPExpDocAso>";
								$xmlstrsale .="<dNumDocAso>{$asociacion->ndoc}</dNumDocAso>";
								$xmlstrsale .="<iTipoDocAso>1</iTipoDocAso>";
								$xmlstrsale .="<dDTipoDocAso>Factura</dDTipoDocAso>";					
								$xmlstrsale .="<dFecEmiDI>{$asociacion->fecha}</dFecEmiDI>";
							}
							$xmlstrsale .="</gCamDEAsoc>";				
						}

					}
				}
				$xmlstrsale .="</DE>";
				$xmlstrsale .="</rDE>";

				$xmlstr .= $factura->firmar($xmlstrsale, $this->key, $this->enviroment, $this->idc, $this->csc);
			}

			
			$xmlstr = str_replace('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>','',$xmlstr);
			if(!$this->lote) {
				$XMLEnviar = '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope" xmlns:xsd="http://ekuatia.set.gov.py/sifen/xsd">
				<env:Body>
				    <xsd:rEnviDe>
				        <xsd:dId>1</xsd:dId>
				        <xsd:xDE>
				        '.$xmlstr.'
				        </xsd:xDE>
				        </xsd:rEnviDe>
				    </env:Body>
				</env:Envelope>';
			} else {
				$xde = '<rLoteDE>'.$xmlstr.'</rLoteDE>';

				$zip = new ZipArchive;
				if(file_exists('s.zip'))
					unlink('s.zip');

				$res = $zip->open('s.zip', ZipArchive::CREATE);

			    $zip->addFromString('xml.xml', $xde);
			    $zip->close();

			    $s = file_get_contents('s.zip');
			    $s = base64_encode($s);

				$XMLEnviar = '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope" xmlns:xsd="http://ekuatia.set.gov.py/sifen/xsd">
				<env:Body>
				    <xsd:rEnvioLote>
				        <xsd:dId>1</xsd:dId>
						<xsd:xDE>
				       		'.$s.'
						</xsd:xDE>
				        </xsd:rEnvioLote>
				    </env:Body>
				</env:Envelope>';
			}

			$xmlRequest = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$XMLEnviar);
			$xmlRequest->asXML("output.xml");

			return $xmlRequest;
  		}

		private function getProcesarUrl() {
			$customUrl = getenv('SIFEN_PROCESAR_URL');
			if(!empty($customUrl)) {
				return $customUrl;
			}

			$libPath = (strpos(__FILE__, 'php-sifen3-custom') !== false)
				? '/public/_lib/php-sifen3-custom/procesar.php'
				: '/public/_lib/php-sifen3/procesar.php';

			$host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
			if(!empty($host)) {
				$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
					|| (!empty($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443');
				$scheme = $https ? 'https' : 'http';
				$prefix = '';
				if(!empty($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/public/') !== false) {
					$parts = explode('/public/', $_SERVER['SCRIPT_NAME'], 2);
					$prefix = $parts[0];
				}
				return $scheme . '://' . $host . $prefix . $libPath;
			}

			return 'http://localhost/facturacion/procesar.php';
		}

  		public function enviarEvento($xmlRequest, $evento = 'rEnviEventoDe') {
			$curl = curl_init($this->getProcesarUrl());
			$curlFile = curl_file_create(realpath($this->key->archivo));
			$postData = array(
				'method' => $evento,
				'pass' => $this->key->clave,
				'xml' => $xmlRequest->asXML(),
				'file_contents' => $curlFile,
				'enviroment' => $this->enviroment
			);
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, 
				$postData
			);

			$response = curl_exec($curl);
			if(!empty($_GET['debug_sifen'])) {
				$postData['xml'] = htmlentities($postData['xml']);
				$this->debug($postData);
				$this->debug(htmlentities($response));
				$this->debug(curl_error($curl));
				$doc = new DOMDocument('1.0');
				$doc->preserveWhiteSpace = true;
				$doc->formatOutput       = true;
				$doc->loadXML(str_replace('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>','',$xmlRequest->asXML()));
				$xml_pretty = $doc->saveXML();
				echo '<pre>'.htmlentities($xml_pretty).'</pre>';
			}

			$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			$error = curl_error($curl);
			$error = !empty($error) ? $error : 'El Servidor de SIFEN se encuentra en Mantenimiento o Fuera de Servicio';

			if(!empty($response)) {
				$dom = new DOMDocument();
				@$dom->loadXML($response);

				$this->Id = @$dom->getElementsByTagName('Id')->item(0)->nodeValue;
				$this->dFecProc = @$dom->getElementsByTagName('dFecProc')->item(0)->nodeValue;
				$this->dEstRes = @$dom->getElementsByTagName('dEstRes')->item(0)->nodeValue;
				$this->dProtAut = @$dom->getElementsByTagName('dProtAut')->item(0)->nodeValue;
				$this->dCodRes = @$dom->getElementsByTagName('dCodRes')->item(0)->nodeValue;
				$this->dMsgRes = @$dom->getElementsByTagName('dMsgRes')->item(0)->nodeValue;
				$this->dProtConsLote = @$dom->getElementsByTagName('dProtConsLote')->item(0)->nodeValue;
				$this->dTpoProces = @$dom->getElementsByTagName('dTpoProces')->item(0)->nodeValue;
			}

			@unlink("xml.xml");
			@unlink("s.zip");

			if(!empty(curl_error($curl))) {
				return [
					'status' => 'error',
					'error' => $error,
				];
			}

			return [
				'status' => 'ok',
				'response' => $response
			];
  		}

  		public function enviar($xmlRequest) {
			$curl = curl_init($this->getProcesarUrl());
			$curlFile = curl_file_create(realpath($this->key->archivo));
			$postData = array(
				'method' => $this->lote ? 'rEnvioLote' : 'rEnviDe',
				'pass' => $this->key->clave,
				'xml' => $xmlRequest->asXML(),
				'file_contents' => $curlFile,
				'enviroment' => $this->enviroment
			);
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, 
				$postData
			);

			$response = curl_exec($curl);
			if(!empty($_GET['debug_sifen'])) {
				$postData['xml'] = htmlentities($postData['xml']);
				$this->debug($postData);
				$this->debug(htmlentities($response));
				$this->debug(curl_error($curl));
				$doc = new DOMDocument('1.0');
				$doc->preserveWhiteSpace = false;
				$doc->formatOutput       = true;
				$doc->loadXML(str_replace('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>','',$xmlRequest->asXML()));
				$xml_pretty = $doc->saveXML();
				echo '<pre>'.htmlentities($xml_pretty).'</pre>';
			}

			$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			$error = curl_error($curl);
			$error = !empty($error) ? $error : 'El Servidor de SIFEN se encuentra en Mantenimiento o Fuera de Servicio';

			if(!empty($response)) {
				$dom = new DOMDocument();
				@$dom->loadXML($response);

				$this->Id = @$dom->getElementsByTagName('Id')->item(0)->nodeValue;
				$this->dFecProc = @$dom->getElementsByTagName('dFecProc')->item(0)->nodeValue;
				$this->dEstRes = @$dom->getElementsByTagName('dEstRes')->item(0)->nodeValue;
				$this->dProtAut = @$dom->getElementsByTagName('dProtAut')->item(0)->nodeValue;
				$this->dCodRes = @$dom->getElementsByTagName('dCodRes')->item(0)->nodeValue;
				$this->dMsgRes = @$dom->getElementsByTagName('dMsgRes')->item(0)->nodeValue;
				$this->dProtConsLote = @$dom->getElementsByTagName('dProtConsLote')->item(0)->nodeValue;
				$this->dTpoProces = @$dom->getElementsByTagName('dTpoProces')->item(0)->nodeValue;
			}

			@unlink("xml.xml");
			@unlink("s.zip");

			if(!empty(curl_error($curl))) {
				return [
					'status' => 'error',
					'error' => $error,
				];
			}

			return [
				'status' => 'ok',
				'response' => $response
			];
  		}
	
  		function simulate() {
			$xml = file_get_contents("sim.xml");

			$dom = new DOMDocument();
			$dom->loadXML($xml);

			$this->Id = $dom->getElementsByTagName('Id')->item(0)->nodeValue;
			$this->dFecProc = $dom->getElementsByTagName('dFecProc')->item(0)->nodeValue;
			$this->dEstRes = $dom->getElementsByTagName('dEstRes')->item(0)->nodeValue;
			$this->dProtAut = $dom->getElementsByTagName('dProtAut')->item(0)->nodeValue;
			$this->dCodRes = $dom->getElementsByTagName('dCodRes')->item(0)->nodeValue;
			$this->dMsgRes = $dom->getElementsByTagName('dMsgRes')->item(0)->nodeValue;
  		}

  		function simulate_lote() {
			$xml = file_get_contents("sim_lote.xml");

			$dom = new DOMDocument();
			$dom->loadXML($xml);

			$this->Id = @$dom->getElementsByTagName('Id')->item(0)->nodeValue;
			$this->dFecProc = @$dom->getElementsByTagName('dFecProc')->item(0)->nodeValue;
			$this->dEstRes = @$dom->getElementsByTagName('dEstRes')->item(0)->nodeValue;
			$this->dProtAut = @$dom->getElementsByTagName('dProtAut')->item(0)->nodeValue;
			$this->dCodRes = @$dom->getElementsByTagName('dCodRes')->item(0)->nodeValue;
			$this->dMsgRes = @$dom->getElementsByTagName('dMsgRes')->item(0)->nodeValue;
			$this->dProtConsLote = @$dom->getElementsByTagName('dProtConsLote')->item(0)->nodeValue;
			$this->dTpoProces = @$dom->getElementsByTagName('dTpoProces')->item(0)->nodeValue;
  		}

		public function getId() {
			return $this->Id;
		}
		public function getFecProc() {
			return $this->dFecProc;
		}
		public function getEstRes() {
			return $this->dEstRes;
		}
		public function getProtAut() {
			return $this->dProtAut;
		}
		public function getCodRes() {
			return $this->dCodRes;
		}
		public function getMsgRes() {
			return $this->dMsgRes;
		}
		public function getProtConsLote() {
			return $this->dProtConsLote;
		}
		public function getTpoProces() {
			return $this->dTpoProces;
		}

	    public function siConsDE($cdc) {
			$data = array(
				'cdc' => $cdc
			);

			return $this->sendMethod('siConsDE', $data);
	    }

		private function sendMethod($method, $data) {
			$curl = curl_init($this->getProcesarUrl());
			$curlFile = curl_file_create(realpath($this->key->archivo));
			$postData = array(
				'pass' => $this->key->clave,
				'method' => $method,
				'data' => json_encode($data),
				'file_contents' => $curlFile,
				'enviroment' => $this->enviroment
			);
			curl_setopt($curl, CURLOPT_HTTPHEADER, array(
			     "Content-Type: multipart/form-data"
			));     
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
			curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 0); 
			curl_setopt($curl, CURLOPT_TIMEOUT, 400); //timeout in seconds
			$response = curl_exec($curl);
			$status = curl_getinfo($curl);
			if(!empty($_GET['debug_sifen'])) {
				$this->debug(curl_error($curl));
				$this->debug($postData);
				$this->debug($response);
				$this->debug($status);
			}

			curl_close($curl);

			if(!$response) {
				return array(
					'msg' => 'Ocurrió un error en el WS',
					'result' => 'error',
				);
			}

			$responseXML = new DOMDocument();
			$responseXML->loadXML($response);
			if(!$responseXML) {
				return array(
					'msg' => 'Hay un problema con la validación XML',
					'result' => 'error',
				);
			}
			if(!empty($responseXML->getElementsByTagName('dCodRes')->item(0)->nodeValue)) {
				return array(
					'result' => 'success',
					'msg' => 'Hay respuesta',
					'data' => array(
						'cod' => $responseXML->getElementsByTagName('dCodRes')->item(0)->nodeValue,
						'msg' => $responseXML->getElementsByTagName('dMsgRes')->item(0)->nodeValue,
					),
					'xml' => $this->xml_to_array($responseXML)
				);
			}


			if(!empty($responseXML))
				return $responseXML;

			return false;
	    }

		function debug($data) {
			echo "<pre>".print_r($data, true)."</pre>";
		}


		public function xml_to_array($root) {
		    $result = array();

		    if ($root->hasAttributes()) {
		        $attrs = $root->attributes;
		        foreach ($attrs as $attr) {
		            $result['@attributes'][$attr->name] = $attr->value;
		        }
		    }

		    if ($root->hasChildNodes()) {
		        $children = $root->childNodes;
		        if ($children->length == 1) {
		            $child = $children->item(0);
		            if ($child->nodeType == XML_TEXT_NODE) {
		                $result['_value'] = $child->nodeValue;
		                return count($result) == 1
		                    ? $result['_value']
		                    : $result;
		            }
		        }
		        $groups = array();
		        foreach ($children as $child) {
		            if (!isset($result[$child->nodeName])) {
		                $result[$child->nodeName] = $this->xml_to_array($child);
		            } else {
		                if (!isset($groups[$child->nodeName])) {
		                    $result[$child->nodeName] = array($result[$child->nodeName]);
		                    $groups[$child->nodeName] = 1;
		                }
		                $result[$child->nodeName][] = $this->xml_to_array($child);
		            }
		        }
		    }

		    return $result;
		}

	    public function queryLote($lote = null) {
	    	if(!$lote)
	    		return false;
			
	    	$data = array(
	    		'dId' => 1,
	    		'dProtConsLote' => $lote
	    	);

	    	$result = $this->sendMethod('siResultLoteDE', $data);
			    	
	    	if(!$result) {
	    		return array(
	    			'result' => 'error',
	    			'msg' => 'Ocurrió un error'
	    		);
	    	}

	    	if(is_array($result)) {
	    		return $result;
	    	}
	    	if($result->saveXML()) {
	    		$xmlData = $this->xml_to_array($result);
	    		return $xmlData;
	    	}


			$responseXML = new DOMDocument();
			$responseXML->loadXML($result);
			if(!$responseXML) {
				return array(
					'msg' => 'Ocurrió un error',
					'result' => 'error',
				);
			}
			if(!empty($_GET['raw'])) {
				$this->debug($responseXML);
			}
			$xmlData = $this->xml_to_array($responseXML);
	    	return $xmlData;
	    }

	    public function anular($cdc) {
	    	$fecfirma = date("Y-m-d")."T".date("H:i:s", strtotime("-10 second"));

			$xml = '<gGroupGesEve xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd" xmlns="http://ekuatia.set.gov.py/sifen/xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
			$xml .= '<rGesEve xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd" xmlns="http://ekuatia.set.gov.py/sifen/xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
			$xml .= '<rEve Id="11">';
			$xml .= '<dFecFirma>'.$fecfirma.'</dFecFirma>';
			$xml .= '<dVerFor>150</dVerFor>';
			$xml .= '<gGroupTiEvt>';
			$xml .= '<rGeVeCan>';
			$xml .= '<Id>'.$cdc.'</Id>';
			$xml .= '<mOtEve>Cancelacion voluntaria</mOtEve>';
			$xml .= '</rGeVeCan>';
			$xml .= '</gGroupTiEvt>';
			$xml .= '</rEve>';
			$xml .= '</rGesEve>';
			$xml .= '</gGroupGesEve>';

			$xmlstr = $this->firmarEvento($xml);

			$xmlstr = str_replace('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>','',$xmlstr);
			$XMLEnviar = '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">
				<env:Body>
					<xsd:rEnviEventoDe xmlns:xsd="http://ekuatia.set.gov.py/sifen/xsd">
						<xsd:dId>1</xsd:dId>
						<xsd:dEvReg>
						'.$xmlstr.'
						</xsd:dEvReg>
					</xsd:rEnviEventoDe>
				    </env:Body>
				</env:Envelope>';	
			$xmlRequest = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$XMLEnviar);
			$xmlRequest->asXML("output.xml");

			return $xmlRequest;
	    }

	    public function inutilizar($docType, $dNumTim, $dEst, $dPunExp, $from, $to) {
	    	$fecfirma = date("Y-m-d")."T".date("H:i:s", strtotime("-10 second"));

	    	$dEst = str_pad($dEst, 3, '0', STR_PAD_LEFT);
			$dPunExp = str_pad($dPunExp, 3, '0', STR_PAD_LEFT);

			$from = str_pad($from, 7, '0', STR_PAD_LEFT);
			$to = str_pad($to, 7, '0', STR_PAD_LEFT);

			$iTiDE = $docType['cod'];

			$xml = '<gGroupGesEve xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd" xmlns="http://ekuatia.set.gov.py/sifen/xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
			$xml .= '<rGesEve xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd" xmlns="http://ekuatia.set.gov.py/sifen/xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
			$xml .= '<rEve Id="11">';
			$xml .= '<dFecFirma>'.$fecfirma.'</dFecFirma>';
			$xml .= '<dVerFor>150</dVerFor>';
			$xml .= '<gGroupTiEvt>';
			$xml .= '<rGeVeInu>';
			$xml .= "<dNumTim>{$dNumTim}</dNumTim>";
			$xml .= "<dEst>{$dEst}</dEst>";
			$xml .= "<dPunExp>{$dPunExp}</dPunExp>";
			$xml .= "<dNumIn>{$from}</dNumIn>";
			$xml .= "<dNumFin>{$to}</dNumFin>";
			$xml .= "<iTiDE>{$iTiDE}</iTiDE>";
			$xml .= '<mOtEve>Inutilización de DTE</mOtEve>';
			$xml .= '</rGeVeInu>';
			$xml .= '</gGroupTiEvt>';
			$xml .= '</rEve>';
			$xml .= '</rGesEve>';
			$xml .= '</gGroupGesEve>';

			$xmlstr = $this->firmarEvento($xml);

			$xmlstr = str_replace('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>','',$xmlstr);
			$XMLEnviar = '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">
				<env:Body>
					<xsd:rEnviEventoDe xmlns:xsd="http://ekuatia.set.gov.py/sifen/xsd">
						<xsd:dId>1</xsd:dId>
						<xsd:dEvReg>
						'.$xmlstr.'
						</xsd:dEvReg>
					</xsd:rEnviEventoDe>
				    </env:Body>
				</env:Envelope>';	
			$xmlRequest = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$XMLEnviar);
			$xmlRequest->asXML("output.xml");

			return $xmlRequest;
	    }

	    public function conformidad($cdc) {
	    	$fecfirma = date("Y-m-d")."T".date("H:i:s", strtotime("-10 second"));
			$xml = '<gGroupGesEve xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd" xmlns="http://ekuatia.set.gov.py/sifen/xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
			$xml .= '<rGesEve xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd" xmlns="http://ekuatia.set.gov.py/sifen/xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
			$xml .= '<rEve Id="15">';
			$xml .= '<dFecFirma>'.$fecfirma.'</dFecFirma>';
			$xml .= '<dVerFor>150</dVerFor>';
			$xml .= '<gGroupTiEvt>';
			$xml .= '<rGeVeConf>';
			$xml .= '<Id>'.$cdc.'</Id>';
			$xml .= '<iTipConf>1</iTipConf>';
			$xml .= '</rGeVeConf>';
			$xml .= '</gGroupTiEvt>';
			$xml .= '</rEve>';
			$xml .= '</rGesEve>';
			$xml .= '</gGroupGesEve>';

			$xmlstr = $this->firmarEvento($xml);

			$xmlstr = str_replace('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>','',$xmlstr);
			$XMLEnviar = '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">
				<env:Body>
					<xsd:rEnviEventoDe xmlns:xsd="http://ekuatia.set.gov.py/sifen/xsd">
						<xsd:dId>1</xsd:dId>
						<xsd:dEvReg>
						'.$xmlstr.'
						</xsd:dEvReg>
					</xsd:rEnviEventoDe>
				    </env:Body>
				</env:Envelope>';	
			$xmlRequest = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$XMLEnviar);
			$xmlRequest->asXML("output.xml");

			return $xmlRequest;
	    }

	    public function desconocimiento($cdc, $receptor) {
	    	$fecfirma = date("Y-m-d")."T".date("H:i:s", strtotime("-10 second"));
	    	
			$rec_ruc = Sifen::getRuc($receptor['documento']);
			$rec_ruc_dv = Sifen::getRuc($receptor['documento'], 'dv');

			$xml = '<gGroupGesEve xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd" xmlns="http://ekuatia.set.gov.py/sifen/xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
			$xml .= '<rGesEve xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd" xmlns="http://ekuatia.set.gov.py/sifen/xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
			$xml .= '<rEve Id="15">';
			$xml .= '<dFecFirma>'.$fecfirma.'</dFecFirma>';
			$xml .= '<dVerFor>150</dVerFor>';
			$xml .= '<gGroupTiEvt>';
			$xml .= '<rGeVeDescon>';
			$xml .= '<Id>'.$cdc.'</Id>';
			$xml .= '<dFecEmi>'.date("Y-m-d")."T".date("H:i:s").'</dFecEmi>';
			$xml .= '<dFecRecep>'.date("Y-m-d")."T".date("H:i:s").'</dFecRecep>';
			$xml .= '<iTipRec>1</iTipRec>';
			$xml .= '<dNomRec>'.$receptor['razon_social'].'</dNomRec>';
			$xml .= '<dRucRec>'.$rec_ruc.'</dRucRec>';
			$xml .= '<dDVRec>'.$rec_ruc_dv.'</dDVRec>';
			$xml .= '<mOtEve>Desconocimiento Total del DTE</mOtEve>';
			$xml .= '</rGeVeDescon>';
			$xml .= '</gGroupTiEvt>';
			$xml .= '</rEve>';
			$xml .= '</rGesEve>';
			$xml .= '</gGroupGesEve>';


			$xmlstr = $this->firmar($xml);
			
			$xmlstr = str_replace('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>','',$xmlstr);
			$XMLEnviar = '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">
				<env:Body>
					<xsd:rEnviEventoDe xmlns:xsd="http://ekuatia.set.gov.py/sifen/xsd">
						<xsd:dId>1</xsd:dId>
						<xsd:dEvReg>
						'.$xmlstr.'
						</xsd:dEvReg>
					</xsd:rEnviEventoDe>
				    </env:Body>
				</env:Envelope>';	
			$xmlRequest = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$XMLEnviar);
			$xmlRequest->asXML("output.xml");

			return $xmlRequest;
	    }

	    public function conocimiento($cdc, $receptor, $factura) {
	    	$fecfirma = date("Y-m-d")."T".date("H:i:s", strtotime("-10 second"));
	    	
			$rec_ruc = Sifen::getRuc($receptor['documento']);
			$rec_ruc_dv = Sifen::getRuc($receptor['documento'], 'dv');

			$xml = '<gGroupGesEve xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd" xmlns="http://ekuatia.set.gov.py/sifen/xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
			$xml .= '<rGesEve xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd" xmlns="http://ekuatia.set.gov.py/sifen/xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
			$xml .= '<rEve Id="15">';
			$xml .= '<dFecFirma>'.$fecfirma.'</dFecFirma>';
			$xml .= '<dVerFor>150</dVerFor>';
			$xml .= '<gGroupTiEvt>';
			$xml .= '<rGeVeNotRec>';
			$xml .= '<Id>'.$cdc.'</Id>';
			$xml .= '<dFecEmi>'.date("Y-m-d")."T".date("H:i:s").'</dFecEmi>';
			$xml .= '<dFecRecep>'.date("Y-m-d")."T".date("H:i:s").'</dFecRecep>';
			$xml .= '<iTipRec>1</iTipRec>';
			$xml .= '<dNomRec>'.$receptor['razon_social'].'</dNomRec>';
			$xml .= '<dRucRec>'.$rec_ruc.'</dRucRec>';
			$xml .= '<dDVRec>'.$rec_ruc_dv.'</dDVRec>';
			$xml .= '<dTotalGs>'.$factura['monto'].'</dTotalGs>';
			$xml .= '</rGeVeNotRec>';
			$xml .= '</gGroupTiEvt>';
			$xml .= '</rEve>';
			$xml .= '</rGesEve>';
			$xml .= '</gGroupGesEve>';



			$xmlstr = $this->firmarEvento($xml);
			
			$xmlstr = str_replace('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>','',$xmlstr);
			$XMLEnviar = '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">
				<env:Body>
					<xsd:rEnviEventoDe xmlns:xsd="http://ekuatia.set.gov.py/sifen/xsd">
						<xsd:dId>1</xsd:dId>
						<xsd:dEvReg>
						'.$xmlstr.'
						</xsd:dEvReg>
					</xsd:rEnviEventoDe>
				    </env:Body>
				</env:Envelope>';	
			$xmlRequest = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$XMLEnviar);
			$xmlRequest->asXML("output.xml");
			return $xmlRequest;
	    }

	    public function disconformidad($cdc, $motivo = 'Disconformidad Total') {
	    	$fecfirma = date("Y-m-d")."T".date("H:i:s", strtotime("-10 second"));
			$xml = '<gGroupGesEve xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd" xmlns="http://ekuatia.set.gov.py/sifen/xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
			$xml .= '<rGesEve xsi:schemaLocation="http://ekuatia.set.gov.py/sifen/xsd siRecepEvento_v150.xsd" xmlns="http://ekuatia.set.gov.py/sifen/xsd" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
			$xml .= '<rEve Id="15">';
			$xml .= '<dFecFirma>'.$fecfirma.'</dFecFirma>';
			$xml .= '<dVerFor>150</dVerFor>';
			$xml .= '<gGroupTiEvt>';
			$xml .= '<rGeVeDisconf>';
			$xml .= '<Id>'.$cdc.'</Id>';
			$xml .= '<mOtEve>'.$motivo.'</mOtEve>';
			$xml .= '</rGeVeDisconf>';
			$xml .= '</gGroupTiEvt>';
			$xml .= '</rEve>';
			$xml .= '</rGesEve>';
			$xml .= '</gGroupGesEve>';

			$xmlstr = $this->firmarEvento($xml);

			$xmlstr = str_replace('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>','',$xmlstr);
			$XMLEnviar = '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope">
				<env:Body>
					<xsd:rEnviEventoDe xmlns:xsd="http://ekuatia.set.gov.py/sifen/xsd">
						<xsd:dId>1</xsd:dId>
						<xsd:dEvReg>
						'.$xmlstr.'
						</xsd:dEvReg>
					</xsd:rEnviEventoDe>
				    </env:Body>
				</env:Envelope>';	
			$xmlRequest = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$XMLEnviar);
			$xmlRequest->asXML("output.xml");

			return $xmlRequest;
	    }

		public function firmarEvento($xml) {
	    	$passphrase = $this->key->clave;
			$xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$xml);
			$xml->asXML("xml.xml");


			$ReferenceNodeName = 'rEve'; //NODO A FIRMAR
			$doc                     = new DOMDocument('1.0', 'utf-8');
			$doc->encoding           = 'utf-8';
			$doc->preserveWhiteSpace = true;
			$doc->formatOutput       = false;
			$doc->load('xml.xml');

			$objDSig = new \RobRichards\XMLSecLibs\XMLSecurityDSig('');
			$objDSig->setCanonicalMethod(\RobRichards\XMLSecLibs\XMLSecurityDSig::C14N);
			$objDSig->canonicalizeSignedInfo();
			$root = $doc->documentElement;


			$objDSig->addReference(
				$doc->getElementsByTagName($ReferenceNodeName)->item(0), 
				\RobRichards\XMLSecLibs\XMLSecurityDSig::SHA256, 
				array('http://www.w3.org/2000/09/xmldsig#enveloped-signature','http://www.w3.org/2001/10/xml-exc-c14n#'),
				array('force_uri'=>true,'overwrite'=>true,'overwrite_id'=>11,'id_name'=>11)
			);



			$objKey = new \RobRichards\XMLSecLibs\XMLSecurityKey(\RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA256, array('type'=>'private'));

			$objKey->passphrase = $passphrase;
			$objKey->loadKey(file_get_contents($this->key->archivo));
			$objDSig->sign($objKey,$doc->getElementsByTagName($ReferenceNodeName)->item(0));
			$objDSig->add509Cert(file_get_contents($this->key->archivo));
			$objDSig->appendSignature($doc->getElementsByTagName('rGesEve')->item(0));

			$doc->saveXML($doc->documentElement);
			$doc->save('xmlsigned.xml');

			return file_get_contents("xmlsigned.xml");
		}

	}


?>
