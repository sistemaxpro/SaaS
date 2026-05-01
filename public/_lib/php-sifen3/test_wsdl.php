<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$id_empresa = $_SESSION['id_empresa'] ?? 169;
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
        $dbHost = $_SESSION['server'] ?? 'localhost';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $stmt = $pdo->prepare("SELECT cert_path, cert_pass, ruc FROM empresa WHERE id_empresa = :id");
    $stmt->execute([':id' => $id_empresa]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);

    $certPath = $emp['cert_path'];
    $certPass = $emp['cert_pass'];
    
    // Convertir P12 a PEM si es necesario (SoapClient prefiere PEM o P12 dependiendo de la version de PHP/OpenSSL)
    // Pero SifenWSClient usa el cert_path directamente.
    
    $wsdlUrl = "https://sifen.set.gov.py/de/ws/consultas/consulta.wsdl?wsdl";
    
    echo "intentando descargar WSDL desde: $wsdlUrl\n";
    echo "Usando cert: $certPath\n";

    // Intentar con file_get_contents y context
    $context = stream_context_create([
        'ssl' => [
            'local_cert' => $certPath,
            'passphrase' => $certPass,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
        ]
    ]);

    $data = file_get_contents($wsdlUrl, false, $context);
    if ($data === false) {
        $error = error_get_last();
        echo "Error descarga: " . $error['message'] . "\n";
    } else {
        echo "Exito! Descargados " . strlen($data) . " bytes\n";
        echo substr($data, 0, 100) . "...\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
