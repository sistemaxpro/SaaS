<?php

/**
 * Test directo del certificado PKCS12
 */

require_once __DIR__ . '/config/db_config.php';

$id_empresa = 169;
$emp = getDbConfig($id_empresa);

echo "=== TEST CERTIFICADO EMPRESA $id_empresa ===\n\n";

// Buscar certificado
$rucBase = preg_replace('/\D+/', '', explode('-', $emp['ruc'])[0]);
echo "RUC Base: $rucBase\n";

$candidates = [
    dirname(__DIR__) . '/_lib/php-sifen3/certificados/' . $rucBase . '.p12',
    dirname(__DIR__) . '/_lib/sifen/certificados/' . $rucBase . '.p12',
    dirname(__DIR__) . '/_lib/certificados/' . $rucBase . '.p12',
    dirname(__DIR__) . '/certificados/' . $rucBase . '.p12'
];

$certPath = '';
foreach ($candidates as $cand) {
    echo "Buscando: $cand ... ";
    if (file_exists($cand)) {
        $certPath = $cand;
        echo "✅ ENCONTRADO\n";
        break;
    } else {
        echo "❌\n";
    }
}

if (empty($certPath)) {
    die("ERROR: No se encontró certificado\n");
}

echo "\nCertificado: $certPath\n";

// Probar contraseñas
$passwords = [
    'cert_pass (DB)' => $emp['cert_pass'] ?? '',
    'password_certificado (DB)' => $emp['password_certificado'] ?? '',
    'password_sifen (DB)' => $emp['password_sifen'] ?? '',
    'Hardcoded' => '3nvcEcwW'
];

echo "\n=== PROBANDO CONTRASEÑAS ===\n";

$pkcs12Content = file_get_contents($certPath);

foreach ($passwords as $label => $pass) {
    if (empty($pass)) {
        echo "$label: (vacío) - SKIP\n";
        continue;
    }

    echo "$label: '$pass' ... ";

    $certs = [];
    if (openssl_pkcs12_read($pkcs12Content, $certs, $pass)) {
        echo "✅ VÁLIDA\n";

        // Extraer info
        $certInfo = openssl_x509_parse($certs['cert']);
        echo "  Subject: {$certInfo['subject']['CN']}\n";
        echo "  Válido hasta: " . date('Y-m-d H:i:s', $certInfo['validTo_time_t']) . "\n";

        // Verificar si está vencido
        if ($certInfo['validTo_time_t'] < time()) {
            echo "  ⚠️ CERTIFICADO VENCIDO\n";
        } else {
            echo "  ✅ Certificado vigente\n";
        }

        break; // Salir al encontrar la primera válida
    } else {
        echo "❌ INVÁLIDA\n";
        echo "  Error OpenSSL: " . openssl_error_string() . "\n";
    }
}

echo "\n=== FIN TEST ===\n";
