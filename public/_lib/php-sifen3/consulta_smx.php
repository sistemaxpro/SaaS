<?php
/**
 * consulta_smx.php
 * Endpoint para consultar el estado de un CDC o Protocolo en SIFEN.
 * Obtiene automáticamente el certificado de la empresa si está en sesión.
 */

header('Content-Type: application/json; charset=utf-8');
require_once 'src/soap-sifen.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true) ?: $_POST;

$protocolo = $input['protocolo'] ?? null;
$cdc = $input['cdc'] ?? null;
$modo = $input['modo'] ?? 'prod';
$certPath = $input['cert_path'] ?? '';
$certPass = $input['cert_pass'] ?? '';

// Si no vienen rutas, intentar obtener de la BD como en anular_fe.php
if (empty($certPath) || empty($certPass)) {
    try {
        $id_empresa = $_SESSION['id_empresa'] ?? 169;
        $masterDb = $_SESSION['dbu'] ?? 'serproc1';
        $dbHost = $_SESSION['server'] ?? 'localhost';
        $dbUser = $_SESSION['user'] ?? 'sistemax';
        $dbPass = $_SESSION['password'] ?? 'Armagedon123';

        $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("SELECT cert_path, cert_pass, ruc FROM empresa WHERE id_empresa = :id");
        $stmt->execute([':id' => $id_empresa]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($emp) {
            if (empty($certPath)) {
                $certPath = $emp['cert_path'];
                // Si no hay ruta absoluta, buscar en directorios estándar
                if (empty($certPath) || !file_exists($certPath)) {
                    $posibles = [
                        __DIR__ . "/certificados/{$emp['ruc']}.p12",
                        __DIR__ . "/noenviar/{$emp['ruc']}.p12",
                        realpath(__DIR__ . "/../_lib/php-sifen3/certificados/{$emp['ruc']}.p12")
                    ];
                    foreach ($posibles as $p) {
                        if (file_exists($p)) {
                            $certPath = $p;
                            break;
                        }
                    }
                }
            }
            if (empty($certPass)) $certPass = $emp['cert_pass'];
        }
    } catch (Exception $e) {
        // Continuar, fallará luego en el cliente si sigue vacío
    }
}

if (!$protocolo && !$cdc) {
    echo json_encode(['success' => false, 'message' => 'Se requiere protocolo o CDC']);
    exit;
}

if (empty($certPath) || !file_exists($certPath)) {
    echo json_encode(['success' => false, 'message' => 'Certificado no encontrado.']);
    exit;
}

try {
    // Si es P12, convertirlo a PEM temporalmente para SoapClient
    $finalCertPath = $certPath;
    $tempPem = null;
    
    if (strtolower(pathinfo($certPath, PATHINFO_EXTENSION)) === 'p12') {
        $p12cert = file_get_contents($certPath);
        if (openssl_pkcs12_read($p12cert, $certs, $certPass)) {
            $tempPem = sys_get_temp_dir() . '/sifen_tmp_' . uniqid() . '.pem';
            file_put_contents($tempPem, $certs['cert'] . $certs['pkey']);
            $finalCertPath = $tempPem;
        }
    }

    $client = new SifenWSClient($modo, false);
    $client->setCertificateFromPath($finalCertPath)->setPassphrase($certPass);

    if ($protocolo) {
        $res = $client->consulta('siResultLoteDE', ['dProtConsLote' => $protocolo]);
    } else {
        $res = $client->consulta('siConsDE', ['dCDC' => $cdc]);
    }

    // Limpiar temporal
    if ($tempPem && file_exists($tempPem)) @unlink($tempPem);

    if ($res['status'] === 'ok') {
        echo json_encode(['success' => true, 'xml' => $res['response']]);
    } else {
        echo json_encode(['success' => false, 'message' => $res['error']]);
    }
} catch (Exception $e) {
    if (isset($tempPem) && file_exists($tempPem)) @unlink($tempPem);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
