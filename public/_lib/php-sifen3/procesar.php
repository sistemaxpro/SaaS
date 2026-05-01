<?php

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

$baseDir = __DIR__;
$tmpDir = $baseDir . '/tmp';
$xkDir = $baseDir . '/xk';

if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0775, true);
}
if (!is_dir($xkDir)) {
    @mkdir($xkDir, 0775, true);
}

function procLog(string $msg): void
{
    global $tmpDir;
    @file_put_contents($tmpDir . '/procesar.log', '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
}

function emitError(string $msg): void
{
    header('Content-Type: text/xml; charset=UTF-8');
    echo '<error><![CDATA[' . $msg . ']]></error>';
}

$pass = (string)($_POST['pass'] ?? '');
$methodPost = (string)($_POST['method'] ?? 'rEnviDe');
$envInput = (string)($_POST['enviroment'] ?? 'test');
$enviroment = $envInput === 'test' ? 'sifen-test' : 'sifen';
$xml = (string)($_POST['xml'] ?? '');

if ($xml !== '') {
    @file_put_contents($tmpDir . '/output.xml', $xml);
}

if (empty($_FILES['file_contents']['tmp_name'])) {
    procLog('No se recibió archivo file_contents');
    emitError('No se recibió certificado PKCS12');
    exit;
}

$xkey = $xkDir . '/' . uniqid('', true) . '_key.pem';
if (!@move_uploaded_file($_FILES['file_contents']['tmp_name'], $xkey)) {
    procLog('move_uploaded_file fallo hacia: ' . $xkey);
    emitError('No se pudo guardar temporalmente el certificado');
    exit;
}
@chmod($xkey, 0600);

class AnotherSoapClient extends SoapClient
{
    public function __doRequest2($request, $location, $action, $version)
    {
        return parent::__doRequest($request, $location, $action, $version);
    }

    public function __anotherRequest(string $call, string $params, string $enviroment)
    {
        switch ($call) {
            case 'siRecepDE':
                $location = 'https://' . $enviroment . '.set.gov.py/de/ws/consultas/consulta.wsdl';
                break;
            case 'rEnviDe':
                $location = 'https://' . $enviroment . '.set.gov.py/de/ws/sync/recibe.wsdl';
                break;
            case 'rEnvioLote':
                $location = 'https://' . $enviroment . '.set.gov.py/de/ws/async/recibe-lote.wsdl';
                break;
            case 'rEnviEventoDe':
                $location = 'https://' . $enviroment . '.set.gov.py/de/ws/eventos/evento.wsdl';
                break;
            default:
                $location = 'https://' . $enviroment . '.set.gov.py/de/ws/sync/recibe.wsdl';
                break;
        }

        return $this->__doRequest2($params, $location, '', SOAP_1_2);
    }
}

$method = 'rEnviDe';
$url = 'https://' . $enviroment . '.set.gov.py/de/ws/sync/recibe.wsdl?wsdl';
$useRawRequest = true;

switch ($methodPost) {
    case 'siConsDE':
        $method = 'rEnviConsDe';
        $url = 'https://' . $enviroment . '.set.gov.py/de/ws/consultas/consulta.wsdl?wsdl';
        $useRawRequest = false;
        break;
    case 'siResultLoteDE':
        $method = 'siResultLoteDE';
        $url = 'https://' . $enviroment . '.set.gov.py/de/ws/consultas/consulta-lote.wsdl?wsdl';
        $useRawRequest = false;
        break;
    case 'rEnviConsRUC':
        $method = 'rEnviConsRUC';
        $url = 'https://' . $enviroment . '.set.gov.py/de/ws/consultas/consulta-ruc.wsdl?wsdl';
        $useRawRequest = false;
        break;
    case 'rEnvioLote':
        $method = 'rEnvioLote';
        $url = 'https://' . $enviroment . '.set.gov.py/de/ws/async/recibe-lote.wsdl?wsdl';
        $useRawRequest = true;
        break;
    case 'rEnviEventoDe':
        $method = 'rEnviEventoDe';
        $url = 'https://' . $enviroment . '.set.gov.py/de/ws/eventos/evento.wsdl?wsdl';
        $useRawRequest = true;
        break;
}

$data = [];
if (!empty($_POST['data'])) {
    $decoded = json_decode((string)$_POST['data'], true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

$options = [
    'trace' => true,
    'exceptions' => true,
    'cache_wsdl' => WSDL_CACHE_NONE,
    'local_cert' => $xkey,
    'passphrase' => $pass,
    'soap_version' => SOAP_1_2,
    'stream_context' => stream_context_create([
        'ssl' => [
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ],
        'http' => [
            'timeout' => 60,
            'user_agent' => 'SistemaX-SIFEN-Client/1.0'
        ]
    ]),
];

try {
    if ($useRawRequest) {
        $client = new AnotherSoapClient($url, $options);
        $request = $client->__anotherRequest($method, $xml, $enviroment);
    } else {
        $client = new SoapClient($url, $options);
        switch ($methodPost) {
            case 'siConsDE':
                $client->rEnviConsDe([
                    'dId' => '1',
                    'dCDC' => (string)($data['cdc'] ?? '')
                ]);
                break;
            case 'rEnviConsRUC':
                $client->rEnviConsRUC([
                    'dId' => '1',
                    'dRUCCons' => (string)($data['ruc'] ?? '')
                ]);
                break;
            case 'siResultLoteDE':
                $client->rEnviConsLoteDe([
                    'dId' => '1',
                    'dProtConsLote' => (string)($data['dProtConsLote'] ?? '')
                ]);
                break;
            default:
                emitError('Metodo no soportado: ' . $methodPost);
                @unlink($xkey);
                exit;
        }
        $request = $client->__getLastResponse();
    }

    header('Content-Type: text/xml; charset=UTF-8');
    echo $request;
    @file_put_contents($tmpDir . '/result.xml', (string)$request);
} catch (Throwable $e) {
    procLog('SOAP ERROR: ' . $e->getMessage());
    emitError('SOAP ERROR: ' . $e->getMessage());
} finally {
    @unlink($xkey);
}
