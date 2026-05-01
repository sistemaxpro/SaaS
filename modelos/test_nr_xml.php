<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '_lib/php-sifen3/src/php-sifen.php';

// Usar el certificado de la empresa 1040
$certPath = '_lib/php-sifen3/certificados/80118689.p12';
$certPass = '3nvcEcwW';

if (!file_exists($certPath)) {
    echo 'Certificado no existe: ' . $certPath . PHP_EOL;
    exit;
}

// CSC e IDC de prueba
$csc = 'ABCD0000000000000000000000000000';
$idc = '0001';

$key = new \sifen\KEY($certPath, $certPass);
$sifen = new \sifen\Sifen('test', $key);
$sifen->csc = $csc;
$sifen->idc = $idc;

$emisor = new \sifen\Emisor([
    'ruc' => '80118689',
    'dv' => '2',
    'razon_social' => 'Test Empresa',
    'tipo_contribuyente' => '2',
    'ciudad' => 1,
    'direccion' => 'Asuncion',
    'telefono' => '021123456',
    'email' => 'test@test.com'
]);

$receptor = new \sifen\Receptor([
    'documento' => '12345678',
    'tipo_doc' => 'ci',
    'razon_social' => 'Cliente Test',
    'ciudad' => 1,
    'direccion' => 'Calle 1',
    'telefono' => '0981123456'
]);

$conceptos = [
    new \sifen\Concepto([
        'codigo' => 'PROD1',
        'descripcion' => 'Producto de prueba',
        'precio' => 0,
        'cantidad' => 10,
        'tasa_iva' => 0,
        'descuento' => 0,
        'unidad_medida' => '77'
    ])
];

$conductor = new \sifen\Conductor([
    'documento' => '1234567',
    'razon_social' => 'Conductor Test',
    'direccion' => 'Sin direccion'
]);

$facturaData = [
    'emisor' => $emisor,
    'receptor' => $receptor,
    'conceptos' => $conceptos,
    'conductor' => $conductor,
    'ndoc' => '0000001',
    'timbrado' => '12345678',
    'fec_timbrado' => '2025-01-01',
    'inicio' => '2026-01-27',
    'fin' => '2026-01-27',
    'cod_establecimiento' => '001',
    'cod_expedicion' => '001',
    'moneda' => 'PYG',
    'cambio' => 1,
    'motivo_nr' => 1,
    'kmr' => 100
];

try {
    $factura = new \sifen\Factura(\sifen\DTE::NR, $facturaData);
    echo 'Factura creada OK, tipo: ' . $factura->type['tipo'] . PHP_EOL;

    $sifen->agregarFactura($factura);
    echo 'Factura agregada al sifen' . PHP_EOL;

    // Probar sin firmar primero para ver el XML crudo
    echo 'Intentando buildXML...' . PHP_EOL;

    $xml = $sifen->buildXML(false);

    echo 'XML Type: ' . gettype($xml) . PHP_EOL;

    if (is_object($xml)) {
        echo 'XML es objeto de tipo: ' . get_class($xml) . PHP_EOL;

        // Convertir a string correctamente
        $xmlString = $xml->asXML();
        echo 'XML String Length: ' . strlen($xmlString) . PHP_EOL;
        echo 'First 1000 chars: ' . PHP_EOL . substr($xmlString, 0, 1000) . PHP_EOL;
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    echo 'File: ' . $e->getFile() . ' Line: ' . $e->getLine() . PHP_EOL;
    echo 'Trace: ' . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
}
