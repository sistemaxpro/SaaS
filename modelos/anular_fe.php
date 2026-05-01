<?php

/**
 * Anular Factura Electrónica - Frontend Controller
 * Recibe solicitud desde el grid y envía al endpoint SIFEN
 * 
 * Método: POST JSON
 * Parámetros:
 *   - id_factura: ID de la factura a anular
 *   - cdc: Código de Control del Documento
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Recibir datos - soporta JSON POST, form POST, y GET
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

// Si no hay JSON, intentar POST normal
if (empty($input)) {
    $input = $_POST;
}

// Si no hay POST, intentar GET
if (empty($input)) {
    $input = $_GET;
}

// Obtener parámetros
$id_factura = isset($input['id_factura']) ? (int)$input['id_factura'] : (isset($input['id']) ? (int)$input['id'] : 0);
$cdc = isset($input['cdc']) ? trim($input['cdc']) : '';

// Validar parámetros
if (!$id_factura) {
    echo json_encode(['success' => false, 'message' => 'ID de factura requerido']);
    exit;
}

if (empty($cdc) || strlen($cdc) !== 44) {
    echo json_encode(['success' => false, 'message' => 'CDC inválido (debe tener 44 dígitos)']);
    exit;
}

// Conexión a BD
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';
$id_empresa = isset($_SESSION['id_empresa']) && $_SESSION['id_empresa'] ? (int)$_SESSION['id_empresa'] : 169;

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");

    // Obtener datos de la empresa (certificado)
    $stmtEmpresa = $pdo->prepare("
        SELECT e.*, dbase, cert_path, cert_pass, cert_nombre
        FROM empresa e
        WHERE e.id_empresa = :id
    ");
    $stmtEmpresa->execute([':id' => $id_empresa]);
    $empresa = $stmtEmpresa->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        throw new Exception("Empresa no encontrada: $id_empresa");
    }

    $dbName = $empresa['dbase'];

    // Verificar que la factura existe y es electrónica
    $stmtFactura = $pdo->prepare("
        SELECT id_factura, nro_factura, cdc, estado, est_res_anul
        FROM $dbName.factura_ventas
        WHERE id_factura = :id
    ");
    $stmtFactura->execute([':id' => $id_factura]);
    $factura = $stmtFactura->fetch(PDO::FETCH_ASSOC);

    if (!$factura) {
        throw new Exception("Factura no encontrada: $id_factura");
    }

    // Verificar que no esté ya anulada (estado = 0 significa anulada)
    if ($factura['estado'] == 0 || !empty($factura['est_res_anul'])) {
        echo json_encode(['success' => false, 'message' => 'La factura ya está anulada']);
        exit;
    }

    // Obtener ruta del certificado
    $certPath = $empresa['cert_path'] ?? '';
    $certPass = $empresa['cert_pass'] ?? '';
    $modoSifen = 'prod'; // Por defecto test, cambiar a 'prod' para producción

    // Si no hay ruta absoluta, buscar en directorio por defecto
    if (empty($certPath) || !file_exists($certPath)) {
        // Intentar buscar por RUC
        $posiblesCerts = [
            __DIR__ . "/_lib/php-sifen3/certificados/{$empresa['ruc']}.p12",
            __DIR__ . "/_lib/php-sifen3/noenviar/{$empresa['ruc']}.p12",
            __DIR__ . "/_lib/certificados/{$empresa['ruc']}.p12",
        ];

        foreach ($posiblesCerts as $path) {
            if (file_exists($path)) {
                $certPath = $path;
                break;
            }
        }
    }

    if (empty($certPath) || !file_exists($certPath)) {
        throw new Exception("Certificado no encontrado para la empresa");
    }

    if (empty($certPass)) {
        throw new Exception("Contraseña del certificado no configurada");
    }

    // Preparar payload para el endpoint SIFEN
    $payload = [
        'cdc' => $cdc,
        'cert_path' => $certPath,
        'cert_pass' => $certPass,
        'modo' => $modoSifen
    ];

    // Llamar al endpoint de anulación
    $endpointUrl = 'http://127.0.0.1' . dirname($_SERVER['PHP_SELF']) . '/_lib/php-sifen3/anular_smx.php';

    // Usar cURL para llamar al endpoint
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $endpointUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Error de conexión: $curlError");
    }

    $result = json_decode($response, true);

    if (!$result) {
        // Detectar errores comunes de SIFEN
        if (strpos($response, 'SOAP-ERROR') !== false) {
            if (strpos($response, 'can\'t import schema') !== false) {
                throw new Exception("Error de SIFEN: El servidor de SIFEN no está disponible temporalmente. Intente nuevamente en unos minutos.");
            }
            throw new Exception("Error de comunicación con SIFEN: " . substr($response, 0, 200));
        }
        throw new Exception("Respuesta inválida del servidor: " . substr($response, 0, 200));
    }

    // Procesar resultado
    if ($result['success']) {
        // Actualizar estado de la factura en BD
        $stmtUpdate = $pdo->prepare("
            UPDATE $dbName.factura_ventas 
            SET estado = 0,
                est_res_anul = 'Anulado',
                prot_cons_lote_anul = :protocolo,
                msg_res_anul = :mensaje
            WHERE id_factura = :id
        ");
        $stmtUpdate->execute([
            ':id' => $id_factura,
            ':protocolo' => $result['data']['protocolo_auth'] ?? '',
            ':mensaje' => $result['data']['mensaje_resp'] ?? 'Anulado vía SIFEN'
        ]);

        // Registrar en log
        $logFile = __DIR__ . '/logs/anulaciones_' . date('Y-m') . '.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);

        $logEntry = date('Y-m-d H:i:s') . " | ANULADA | Empresa: $id_empresa | Factura: {$factura['nro_factura']} | CDC: $cdc | Protocolo: {$result['data']['protocolo_auth']}\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);

        echo json_encode([
            'success' => true,
            'message' => 'Factura anulada correctamente en SIFEN',
            'data' => [
                'id_factura' => $id_factura,
                'nro_factura' => $factura['nro_factura'],
                'cdc' => $cdc,
                'protocolo' => $result['data']['protocolo_auth'] ?? '',
                'estado' => $result['data']['estado'] ?? 'ANULADA',
                'mensaje_sifen' => $result['data']['mensaje_resp'] ?? ''
            ]
        ]);
    } else {
        // Error de SIFEN
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Error al anular en SIFEN',
            'data' => $result['data'] ?? null
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null
    ]);
}
