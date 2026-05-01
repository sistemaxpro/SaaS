<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../_lib/php-sifen3/src/soap-sifen.php';

echo "=== TEST SIFEN PRODUCCIÓN ===\n\n";

$certPath = '/home/fabio/web/sistemax.com.py/public_html/_lib/php-sifen3/certificados/80118689.p12';
$certPass = '3nvcEcwW';

if (!file_exists($certPath)) {
    die("ERROR: Certificado no encontrado en $certPath\n");
}

echo "1. Leyendo certificado...\n";
$pkcs12Content = file_get_contents($certPath);
if (!openssl_pkcs12_read($pkcs12Content, $certs, $certPass)) {
    die("ERROR: No se pudo leer el certificado PKCS12\n");
}
echo "   ✓ Certificado leído correctamente\n\n";

$pemContent = $certs['pkey'] . "\n" . $certs['cert'] . "\n";
if (!empty($certs['extracerts'])) {
    foreach ($certs['extracerts'] as $extra) {
        $pemContent .= $extra . "\n";
    }
}

echo "2. Inicializando cliente SIFEN (producción)...\n";
$client = new SifenWSClient('prod', true); // modo producción con debug
$client->setCertificateFromString($pemContent);
$client->setPassphrase($certPass);
echo "   ✓ Cliente inicializado\n\n";

echo "3. Consultando RUC 1023840 en SIFEN...\n";
$inicio = microtime(true);
$resSifen = $client->consulta('rEnviConsRUC', ['ruc' => '1023840']);
$tiempo = round((microtime(true) - $inicio) * 1000, 2);
echo "   Tiempo: {$tiempo}ms\n\n";

echo "4. Resultado:\n";
echo "   Status: " . $resSifen['status'] . "\n";

if ($resSifen['status'] === 'ok') {
    echo "   ✓ Respuesta OK\n";
    echo "\n5. Response XML:\n";
    echo substr($resSifen['response'], 0, 1000) . "...\n";

    if (!empty($resSifen['debug'])) {
        echo "\n6. Debug Info:\n";
        print_r($resSifen['debug']);
    }
} else {
    echo "   ✗ Error: " . ($resSifen['error'] ?? 'Unknown') . "\n";

    if (!empty($resSifen['debug'])) {
        echo "\n   Debug Info:\n";
        print_r($resSifen['debug']);
    }
}

echo "\n=== FIN TEST ===\n";
