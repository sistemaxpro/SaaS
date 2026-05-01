<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Test SIFEN Certificate - Empresa 1039</h2>";

require_once __DIR__ . '/config/db_config.php';

// Configuración
$idEmpresa = 1039;

// Obtener config desde habilitacion_sifen/empresa
$pdoMaster = getMasterConnection();
$masterDb = $_SESSION['dbu'] ?? 'serproc1';

$stmtEmp = $pdoMaster->prepare("SELECT * FROM {$masterDb}.empresa WHERE id_empresa = :id");
$stmtEmp->execute([':id' => $idEmpresa]);
$empresa = $stmtEmp->fetch(PDO::FETCH_ASSOC);

$stmtHab = $pdoMaster->prepare("SELECT * FROM {$masterDb}.habilitacion_sifen WHERE id_empresa = :id AND activo = 1 ORDER BY id DESC LIMIT 1");
$stmtHab->execute([':id' => $idEmpresa]);
$hab = $stmtHab->fetch(PDO::FETCH_ASSOC);

$certNombre = $hab['cert_nombre'] ?? '';
$certPathDb = $hab['cert_path'] ?? '';

// Buscar el certificado
$cert_paths = [];
if (!empty($certPathDb) && !empty($certNombre)) {
    $cert_paths[] = rtrim($certPathDb, '/') . '/' . $certNombre;
}

if (!empty($certNombre)) {
    $cert_paths[] = __DIR__ . '/../_lib/php-sifen3-custom/certificados/' . $certNombre;
    $cert_paths[] = __DIR__ . '/../_lib/php-sifen3/certificados/' . $certNombre;
}

// Fallback por RUC
$rucBase = isset($hab['ruc']) ? preg_replace('/\D+/', '', explode('-', $hab['ruc'])[0]) : '';
if (!empty($rucBase)) {
    $cert_paths[] = __DIR__ . '/../_lib/php-sifen3-custom/certificados/' . $rucBase . '.p12';
    $cert_paths[] = __DIR__ . '/../_lib/php-sifen3/certificados/' . $rucBase . '.p12';
}

// Eliminar duplicados
$cert_paths = array_values(array_unique($cert_paths));

echo "<h3>Buscando certificado...</h3>";
$found_cert = null;
foreach ($cert_paths as $path) {
    echo "Verificando: $path ... ";
    if (file_exists($path)) {
        echo "<strong style='color:green'>ENCONTRADO</strong><br>";
        $found_cert = $path;
        break;
    } else {
        echo "<span style='color:red'>No existe</span><br>";
    }
}

if (!$found_cert) {
    die("<h3 style='color:red'>ERROR: No se encontró el certificado 80165605.p12</h3>");
}

echo "<h3>Intentando leer certificado...</h3>";
echo "Archivo: $found_cert<br>";
echo "Tamaño: " . filesize($found_cert) . " bytes<br>";

$pkcs12 = file_get_contents($found_cert);
if (!$pkcs12) {
    die("<h3 style='color:red'>ERROR: No se pudo leer el archivo del certificado</h3>");
}

echo "<h3>Probando contraseñas configuradas...</h3>";
$passCandidates = array_values(array_filter(array_unique([
    $hab['cert_pass'] ?? null,
    $empresa['cert_pass'] ?? null,
    $empresa['password_certificado'] ?? null,
    $empresa['clave_certificado'] ?? null,
    ''
]), fn($v) => $v !== null));

$certs = [];
$result = false;
$matchedSource = null;
foreach ($passCandidates as $candidate) {
    $result = openssl_pkcs12_read($pkcs12, $certs, $candidate);
    if ($result) {
        if ($candidate === ($hab['cert_pass'] ?? null)) $matchedSource = 'habilitacion_sifen.cert_pass';
        elseif ($candidate === ($empresa['cert_pass'] ?? null)) $matchedSource = 'empresa.cert_pass';
        elseif ($candidate === ($empresa['password_certificado'] ?? null)) $matchedSource = 'empresa.password_certificado';
        elseif ($candidate === ($empresa['clave_certificado'] ?? null)) $matchedSource = 'empresa.clave_certificado';
        elseif ($candidate === '') $matchedSource = 'VACÍA';
        break;
    }
}

if (!$result) {
    echo "<h3 style='color:red'>ERROR: No se pudo leer el certificado PKCS12 con las contraseñas configuradas</h3>";
    echo "<strong>Error de OpenSSL:</strong> " . openssl_error_string() . "<br>";
    echo "<p>Revisar cert_pass en habilitacion_sifen o empresa.</p>";
    die();
}

echo "<h3 style='color:green'>✓ Certificado leído correctamente!</h3>";
echo "<strong>La contraseña válida proviene de: $matchedSource</strong><br>";

// Verificar el contenido
if (isset($certs['cert'])) {
    $cert_info = openssl_x509_parse($certs['cert']);
    echo "<h3>Información del certificado:</h3>";
    echo "Nombre: " . ($cert_info['subject']['CN'] ?? 'N/A') . "<br>";
    echo "Emisor: " . ($cert_info['issuer']['CN'] ?? 'N/A') . "<br>";
    echo "Válido desde: " . date('Y-m-d H:i:s', $cert_info['validFrom_time_t']) . "<br>";
    echo "Válido hasta: " . date('Y-m-d H:i:s', $cert_info['validTo_time_t']) . "<br>";

    if ($cert_info['validTo_time_t'] < time()) {
        echo "<h3 style='color:red'>¡ADVERTENCIA! El certificado está VENCIDO</h3>";
    } else {
        echo "<h3 style='color:green'>El certificado está VIGENTE</h3>";
    }
}

echo "<h3>Ahora probando SIFEN...</h3>";

// Cargar la librería SIFEN
require_once __DIR__ . '/../_lib/php-sifen3/src/soap-sifen.php';

try {
    $pem = openssl_x509_read($certs['cert']);
    $priv = openssl_pkey_get_private($certs['pkey']);

    $sifen = new SifenWSClient(2); // Ambiente test

    echo "Consultando RUC 1023840-9 en SIFEN...<br>";
    $response = $sifen->rEnviConsRUC('1023840-9', $pem, $priv);

    echo "<h3 style='color:green'>Respuesta SIFEN recibida:</h3>";
    echo "<pre>" . htmlspecialchars(print_r($response, true)) . "</pre>";
} catch (Exception $e) {
    echo "<h3 style='color:red'>ERROR en consulta SIFEN:</h3>";
    echo $e->getMessage();
}
