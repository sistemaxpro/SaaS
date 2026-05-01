<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db_config.php';

$id_empresa = 169; // Empresa por defecto para debug

try {
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];

echo "\nRecent Invoices XML Content:\n";
$stmtRecent = $pdo->query("
    SELECT id_factura, nro_factura, tipo_documento, xml_firmado, estado_sifen, mensaje_sifen 
    FROM $dbName.factura_ventas 
    ORDER BY id_factura DESC 
    LIMIT 3
");
$recent = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

foreach ($recent as $r) {
    echo "--------------------------------------------------\n";
    echo "ID: {$r['id_factura']} | Nro: {$r['nro_factura']} | Est: {$r['estado_sifen']}\n";
    echo "Msg: {$r['mensaje_sifen']}\n";
    echo "XML Len: " . strlen($r['xml_firmado'] ?? '') . "\n";
    echo "--------------------------------------------------\n";
}
?>
