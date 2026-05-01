<?php
require_once dirname(__DIR__, 2) . '/_lib/php-sifen3-1/src/php-sifen.php';

// Configurar entorno TEST
$certPath = '/var/www/html/scriptcase/app/smx/_lib/php-sifen3-1/certificados/80118689.p12';
$certPass = '12345678'; // Asumida del log anterior (len=8)

$key = new \sifen\KEY($certPath, $certPass);
$sifen = new \sifen\Sifen('test', $key);

$protocolo = '866477001942930930';

echo "Consultando Protocolo: $protocolo ...\n";
$res = $sifen->queryLote($protocolo);

print_r($res);
?>
