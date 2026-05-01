<?php

/**
 * Diagnóstico de Certificado para Empresa 169
 */

session_start();
require_once __DIR__ . '/config/db_config.php';

$id_empresa = 169;

echo "<h2>Diagnóstico Certificado SIFEN - Empresa 169</h2>";
echo "<style>body{font-family:monospace;padding:20px;} .ok{color:green;} .error{color:red;} .warn{color:orange;}</style>";

try {
    // Usar configuración centralizada
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $emp = $conn['config'];

    if (!$emp) {
        echo "<p class='error'>❌ Empresa no encontrada</p>";
        exit;
    }

    echo "<h3>Configuración de Empresa</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Campo</th><th>Valor</th></tr>";
    echo "<tr><td>ID</td><td>{$emp['id_empresa']}</td></tr>";
    echo "<tr><td>Nombre</td><td>{$emp['empresa']}</td></tr>";
    echo "<tr><td>RUC</td><td><strong>{$emp['ruc']}</strong></td></tr>";
    echo "<tr><td>FE Habilitado</td><td>" . ($emp['fe'] ? 'SÍ' : 'NO') . "</td></tr>";
    echo "<tr><td>Ambiente SIFEN</td><td>{$emp['ambiente_sifen']}</td></tr>";
    echo "<tr><td>Certificado SIFEN (DB)</td><td>" . ($emp['certificado_sifen'] ?? 'NULL') . "</td></tr>";
    echo "<tr><td>Password Certificado (DB)</td><td>" . ($emp['clave_certificado'] ?? 'NULL') . "</td></tr>";
    echo "<tr><td>CSC</td><td>" . ($emp['csc'] ?? 'NULL') . "</td></tr>";
    echo "<tr><td>ID_CSC</td><td>" . ($emp['id_csc'] ?? 'NULL') . "</td></tr>";
    echo "</table>";

    // Buscar certificado
    $rucBase = preg_replace('/\D+/', '', explode('-', $emp['ruc'])[0]);
    echo "<h3>Búsqueda de Certificado para RUC: $rucBase</h3>";

    $baseDir = dirname(__DIR__);
    $candidates = [
        $baseDir . '/_lib/php-sifen3/certificados/' . $rucBase . '.p12',
        $baseDir . '/_lib/sifen/certificados/' . $rucBase . '.p12',
        $baseDir . '/_lib/certificados/' . $rucBase . '.p12',
        $baseDir . '/certificados/' . $rucBase . '.p12'
    ];

    $certFound = null;
    foreach ($candidates as $cand) {
        $exists = file_exists($cand);
        $icon = $exists ? '✅' : '❌';
        echo "<p>$icon $cand</p>";
        if ($exists && !$certFound) {
            $certFound = $cand;
        }
    }

    if (!$certFound) {
        echo "<p class='error'><strong>❌ No se encontró certificado .p12 para RUC $rucBase</strong></p>";
        echo "<h3>Certificados disponibles:</h3>";
        exec("find $baseDir -name '*.p12' 2>/dev/null", $allCerts);
        foreach ($allCerts as $cert) {
            echo "<p>• $cert</p>";
        }
        exit;
    }

    echo "<p class='ok'><strong>✅ Certificado encontrado: $certFound</strong></p>";

    // Probar contraseñas
    echo "<h3>Prueba de Contraseñas</h3>";
    $passwords = [
        $emp['clave_certificado'] ?? null,
        $emp['password_certificado'] ?? null,
        $emp['cert_pass'] ?? null,
        '3nvcEcwW',
        '',
    ];

    $passwords = array_filter(array_unique($passwords), function ($p) {
        return $p !== null;
    });

    $pkcs12Content = file_get_contents($certFound);

    $validPass = null;
    foreach ($passwords as $pass) {
        $passDisplay = $pass === '' ? '(vacía)' : $pass;
        if (openssl_pkcs12_read($pkcs12Content, $certs, $pass)) {
            echo "<p class='ok'>✅ Contraseña válida: <strong>$passDisplay</strong></p>";
            $validPass = $pass;

            // Mostrar info del certificado
            $certInfo = openssl_x509_parse($certs['cert']);
            echo "<h4>Información del Certificado:</h4>";
            echo "<ul>";
            echo "<li>Subject: " . ($certInfo['subject']['CN'] ?? 'N/A') . "</li>";
            echo "<li>Issuer: " . ($certInfo['issuer']['CN'] ?? 'N/A') . "</li>";
            echo "<li>Válido desde: " . date('Y-m-d H:i:s', $certInfo['validFrom_time_t']) . "</li>";
            echo "<li>Válido hasta: " . date('Y-m-d H:i:s', $certInfo['validTo_time_t']) . "</li>";
            $now = time();
            if ($now < $certInfo['validFrom_time_t']) {
                echo "<li class='error'>⚠️ Certificado aún no válido</li>";
            } elseif ($now > $certInfo['validTo_time_t']) {
                echo "<li class='error'>⚠️ Certificado EXPIRADO</li>";
            } else {
                echo "<li class='ok'>✅ Certificado válido</li>";
            }
            echo "</ul>";
            break;
        } else {
            echo "<p class='error'>❌ Contraseña inválida: $passDisplay</p>";
        }
    }

    if (!$validPass) {
        echo "<p class='error'><strong>❌ Ninguna contraseña funciona para este certificado</strong></p>";
        echo "<p>Necesitas obtener la contraseña correcta del certificado .p12</p>";
    }

    // Recomendaciones
    echo "<h3>Recomendaciones</h3>";
    if ($validPass !== null) {
        echo "<p class='ok'>✅ Certificado listo para usar</p>";

        // Verificar si la contraseña en DB es correcta
        $dbPass = $emp['clave_certificado'] ?? $emp['password_certificado'] ?? $emp['cert_pass'] ?? null;
        if ($dbPass !== $validPass) {
            echo "<p class='warn'>⚠️ <strong>La contraseña en la base de datos NO coincide con la contraseña válida</strong></p>";
            echo "<p>Ejecutar este SQL:</p>";
            echo "<pre>UPDATE " . MASTER_DB . ".empresa SET clave_certificado = '$validPass' WHERE id_empresa = $id_empresa;</pre>";
        } else {
            echo "<p class='ok'>✅ La contraseña en DB es correcta</p>";
        }
    }
} catch (Exception $e) {
    echo "<p class='error'>ERROR: " . $e->getMessage() . "</p>";
}
