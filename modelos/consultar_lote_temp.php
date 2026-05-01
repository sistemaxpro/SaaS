<?php

/**
 * Script temporal para consultar un lote pendiente en SIFEN
 */

require_once '_lib/php-sifen3/src/soap-sifen.php';

$protocolo = '8211972097892126';
$certPath = '/home/fabio/web/sistemax.com.py/public_html/_lib/php-sifen3/certificados/NEIMARKEMPF.p12';
$certPass = 'cortes111225';
$modo = 'test';  // El lote fue enviado a TEST

$p12cert = file_get_contents($certPath);
if (!openssl_pkcs12_read($p12cert, $certs, $certPass)) {
    die('Error leyendo certificado: ' . openssl_error_string());
}
$tempPem = sys_get_temp_dir() . '/sifen_cons_' . uniqid() . '.pem';
file_put_contents($tempPem, $certs['cert'] . $certs['pkey']);

$client = new SifenWSClient($modo, false);
$client->setCertificateFromPath($tempPem)->setPassphrase($certPass);

echo "Consultando protocolo: $protocolo en modo $modo...\n";
$res = $client->consulta('siResultLoteDE', ['dProtConsLote' => $protocolo]);

@unlink($tempPem);

echo "\nRespuesta SIFEN:\n";
print_r($res);
