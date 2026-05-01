<?php
// Test OpenSSL con configuración legacy

putenv('OPENSSL_CONF=' . __DIR__ . '/openssl-legacy.cnf');
putenv('OPENSSL_MODULES=/usr/lib/x86_64-linux-gnu/ossl-modules');

echo "OPENSSL_CONF: " . getenv('OPENSSL_CONF') . "\n";
echo "OpenSSL Version: " . OPENSSL_VERSION_TEXT . "\n\n";

// Buscar un certificado
$certFiles = glob(__DIR__ . '/_lib/php-sifen3/certificados/*.p12');
if (empty($certFiles)) {
    die("No se encontraron certificados .p12\n");
}

$certPath = $certFiles[0];
echo "Usando certificado: $certPath\n";

// Probar con password común
$passwords = ['Ilufer.2017', 'KRYP#2o2o', '123456'];
$certs = [];
$certLoaded = false;

foreach ($passwords as $pass) {
    $pkcs12 = file_get_contents($certPath);
    if (openssl_pkcs12_read($pkcs12, $certs, $pass)) {
        echo "Certificado cargado con password: $pass\n";
        $certLoaded = true;
        break;
    }
}

if (!$certLoaded) {
    die("No se pudo cargar el certificado con ningún password conocido\n");
}

// Probar firma SHA256
$data = 'test data for signing';
$signature = '';

echo "\nProbando firma SHA256...\n";
if (openssl_sign($data, $signature, $certs['pkey'], OPENSSL_ALGO_SHA256)) {
    echo "✓ Firma SHA256 EXITOSA! (" . strlen($signature) . " bytes)\n";
} else {
    echo "✗ Error en firma SHA256: " . openssl_error_string() . "\n";

    // Intentar con la configuración legacy cargada via flag
    echo "\nIntentando con método alternativo...\n";
}

echo "\nTest completado.\n";
