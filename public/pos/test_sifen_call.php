<?php

/**
 * Test de emisión SIFEN - Simula llamada desde venta.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db_config.php';
require_once __DIR__ . '/api/sifen_lib.php';

// Simular empresa 169
$id_empresa = 169;
$idFactura = 999999; // ID de prueba (no existe, pero probará la config)

echo "=== TEST LLAMADA SIFEN ===\n\n";
echo "ID Empresa: $id_empresa\n";
echo "ID Factura: $idFactura\n\n";

try {
    // Obtener conexión exactamente como venta.php
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];

    echo "DB Name: $dbName\n";
    echo "Conexión OK\n\n";

    // Verificar config cargada
    $emp = getDbConfig($id_empresa);
    echo "Config Empresa:\n";
    echo "  - ID: {$emp['id_empresa']}\n";
    echo "  - Nombre: {$emp['empresa']}\n";
    echo "  - RUC: {$emp['ruc']}\n";
    echo "  - cert_pass: " . ($emp['cert_pass'] ?? 'NULL') . "\n";
    echo "  - password_certificado: " . ($emp['password_certificado'] ?? 'NULL') . "\n\n";

    // Intentar emisión (fallará porque factura no existe, pero veremos el log)
    echo "Llamando emitirFacturaElectronica...\n\n";
    $result = emitirFacturaElectronica($idFactura, $pdo, $dbName, $id_empresa);

    echo "Resultado:\n";
    print_r($result);
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== FIN TEST ===\n";
echo "\nRevisa el log: tail -30 /home/fabio/web/sistemax.com.py/public_html/_lib/tmp/pos_sifen_debug.log\n";
