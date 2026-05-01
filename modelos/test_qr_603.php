<?php
// Extraer y analizar QR de factura 603

$masterHost = '168.231.95.50';
$masterUser = 'sistemax';
$masterPass = 'Armagedon123';

$pdo = new PDO("mysql:host={$masterHost};dbname=smx_1039;charset=utf8mb4", $masterUser, $masterPass);
$stmt = $pdo->query("SELECT xml_firmado FROM factura_ventas WHERE id_factura = 603");
$content = $stmt->fetchColumn();

if (preg_match('/<xsd:xDE>(.*?)<\/xsd:xDE>/s', $content, $m)) {
    $b64 = trim($m[1]);
    $decoded = base64_decode($b64);

    // Es un ZIP
    if (substr($decoded, 0, 2) === 'PK') {
        file_put_contents('/tmp/x.zip', $decoded);
        $z = new ZipArchive();
        $z->open('/tmp/x.zip');
        $xml = $z->getFromIndex(0);
        $z->close();
    } else {
        $xml = $decoded;
    }

    echo "=== XML Decodificado ===\n\n";

    // Buscar el QR
    if (preg_match('/<dCarQR>([^<]+)<\/dCarQR>/', $xml, $qrMatch)) {
        $qr = html_entity_decode($qrMatch[1]);
        echo "URL QR:\n$qr\n\n";

        // Parsear parámetros
        $parts = parse_url($qr);
        parse_str($parts['query'] ?? '', $params);

        echo "Parámetros del QR:\n";
        foreach ($params as $k => $v) {
            echo "  $k = $v\n";
        }
    }

    // Buscar DigestValue
    if (preg_match('/<ds:DigestValue>([^<]+)<\/ds:DigestValue>/', $xml, $dvMatch)) {
        echo "\nDigestValue (base64): " . $dvMatch[1] . "\n";
        echo "DigestValue (hex de texto): " . bin2hex($dvMatch[1]) . "\n";
    }
} else {
    echo "No se encontró xDE en el XML\n";
}
