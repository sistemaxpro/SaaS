<?php
	// error_reporting(E_ALL ^ E_STRICT);
	file_put_contents("log.txt", print_r($_POST, true));
	ini_set('display_errors', 1);
	ini_set('display_startup_errors', 1);
	error_reporting(E_ALL);
	$pass = $_POST['pass'];

	if(!empty($_POST['enviroment'])) {
		$enviroment = $_POST['enviroment']=='test' ? 'sifen-test' : 'sifen';
	} else {
		$enviroment = 'sifen-test';
	}

	// $enviroment = 'sifen';

	if(!empty($_POST['xml']))
		$xml = $_POST['xml'];

	if(!empty($xml))
		file_put_contents("output.xml", $xml);

	$xkey = "xk/".uniqid()."_key.pem";

	move_uploaded_file($_FILES['file_contents']['tmp_name'], $xkey);
	class anotherSoapClient extends SoapClient {

	    function __construct($wsdl, $options) {
	        parent::__construct($wsdl, $options);
	        $this->server = new SoapServer($wsdl, $options);
	    }
	    public function __doRequest2($request, $location, $action, $version) { 
	        $result = parent::__doRequest($request, $location, $action, $version); 
	        return $result; 
	    } 
	    function __anotherRequest($call, $params, $enviroment) {
	    	switch ($call) {
	    		case 'siRecepDE':
	    			$location = 'https://'.$enviroment.'.set.gov.py/de/ws/consultas/consulta.wsdl';
	    			break;
	    		case 'rEnviDe':
	    			$location = 'https://'.$enviroment.'.set.gov.py/de/ws/sync/recibe.wsdl';
	    			break;
	    		case 'rEnvioLote':
	    			$location = 'https://'.$enviroment.'.set.gov.py/de/ws/async/recibe-lote.wsdl';
	    			break;
	    		case 'rEnviEventoDe':
	    			$location = 'https://'.$enviroment.'.set.gov.py/de/ws/eventos/evento.wsdl';
	    			break;
	    		default:
	    			$location = 'https://'.$enviroment.'.set.gov.py/de/ws/sync/recibe.wsdl';
	    			break;
	    	}
	        $request = $params;
	        $action = '';
	        $result =$this->__doRequest2($request, $location, $action, 2);
	        return $result;
	    } 
	}


switch ($_POST['method']) {
	case 'siConsDE':
		$method = 'rEnviConsDe';
		$url = 'https://'.$enviroment.'.set.gov.py/de/ws/consultas/consulta.wsdl?wsdl';
		$anotherSoapClient = false;
		break;
	case 'siResultLoteDE':
		$method = 'siResultLoteDE';
		$url = 'https://'.$enviroment.'.set.gov.py/de/ws/consultas/consulta-lote.wsdl?wsdl';
		$anotherSoapClient = false;
		break;
	case 'rEnviConsRUC':
		$method = 'rEnviConsRUC';
		$url = 'https://'.$enviroment.'.set.gov.py/de/ws/consultas/consulta-ruc.wsdl?wsdl';
		$anotherSoapClient = false;
		break;
	case 'rEnvioLote':
		$method = 'rEnvioLote';
		$url = 'https://'.$enviroment.'.set.gov.py/de/ws/async/recibe-lote.wsdl?wsdl';
		$anotherSoapClient = true;
		break;
	case 'rEnviEventoDe':
		$method = 'rEnviEventoDe';
		$url = 'https://'.$enviroment.'.set.gov.py/de/ws/eventos/evento.wsdl?wsdl';
		$anotherSoapClient = true;
		break;
	default:
		$method = 'rEnviDe';
		$url = 'https://'.$enviroment.'.set.gov.py/de/ws/sync/recibe.wsdl?wsdl';
		$anotherSoapClient = true;
		break;
}

if(!empty($_POST['data'])) {
	$_POST['data'] = json_decode($_POST['data'], true);
}

if($anotherSoapClient) {
	$client = new anotherSoapClient($url, array('trace' => TRUE,'local_cert'=>  $xkey,'passphrase'    => $pass, 'soap_version'  => SOAP_1_2, 'stream_context' => stream_context_create(['ssl' => ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, ]]),));

	//$soapbody = new SoapVar($XMLEnviar, XSD_ANYXML);
	//$request = $client->__soapCall('rEnviDe', array($soapbody));
	$request = $client->__anotherRequest($method, $xml, $enviroment);	
} else {
	// echo "{$url}?wsdl";
	$client = new SoapClient("{$url}", array('trace' => TRUE,'local_cert'=>  $xkey,'passphrase'    => $pass, 'soap_version'  => SOAP_1_2, 'stream_context' => stream_context_create(['ssl' => ['crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT, ]]),));
	// echo $url;
	// var_dump($client->__getFunctions());

	switch ($_POST['method']) {
		case 'siConsDE':
			$client->rEnviConsDe(array(
				'dId' => "1",
				'dCDC' => $_POST['data']['cdc']
			));
			break;
		case 'rEnviConsRUC':
			$client->rEnviConsRUC(array(
				'dId' => "1",
				'dRUCCons' => $_POST['data']['ruc']
			));
			break;
		case 'siResultLoteDE':
			$client->rEnviConsLoteDe(array(
				'dId' => "1",
				'dProtConsLote' => $_POST['data']['dProtConsLote']
			));
			break;			
		default:
			die("501");
			break;
	}




	$request = $client->__getLastResponse();
}
echo $request;

file_put_contents("result.xml", $request);
// unlink($xkey);
?>