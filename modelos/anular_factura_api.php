<?php

/**
 * anular_factura_api.php
 * Endpoint para anular facturas electrónicas en SIFEN y actualizar la BD local.
 */

// IMPORTANTE: Configurar OpenSSL legacy ANTES de cualquier operación de firma
// Esto es necesario para OpenSSL 3.x con certificados .p12 y SHA256
$_legacyCnf = __DIR__ . '/openssl-legacy.cnf';
if (file_exists($_legacyCnf)) {
    putenv('OPENSSL_CONF=' . $_legacyCnf);
    $modulesDir = '/usr/lib/x86_64-linux-gnu/ossl-modules';
    if (is_dir($modulesDir)) {
        putenv('OPENSSL_MODULES=' . $modulesDir);
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/sifen_error.log');

require_once __DIR__ . '/_lib/php-sifen3/src/php-sifen.php';

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true) ?: $_POST;

$id_factura = $input['id_factura'] ?? 0;
$cdc = $input['cdc'] ?? '';
$motivo = $input['motivo_anulacion'] ?? ($input['motivo'] ?? 'Cancelacion voluntaria');
$certPath = $input['cert_path'] ?? '';
$certPass = $input['cert_pass'] ?? '';
$modo = $input['modo'] ?? 'prod'; // Default to prod if not specified

if (!$id_factura || !$cdc) {
    echo json_encode(['success' => false, 'message' => 'ID de factura y CDC son requeridos']);
    exit;
}

$id_empresa = $input['id_empresa'] ?? ($_SESSION['id_empresa'] ?? 169);
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost = $_SESSION['server'] ?? '168.231.95.50';
$dbUser = $_SESSION['user'] ?? 'sistemax';
$dbPass = $_SESSION['password'] ?? 'Armagedon123';

try {
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");

    // 1. Obtener empresa para dbase
    $stmtEmp = $pdo->prepare("SELECT dbase, ruc FROM empresa WHERE id_empresa = :id");
    $stmtEmp->execute([':id' => $id_empresa]);
    $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

    if (!$emp || empty($emp['dbase'])) {
        throw new Exception("Base de datos de la empresa no definida.");
    }
    $dbName = $emp['dbase'];
    $rucEmpresa = $emp['ruc'] ?? '';

    // 2. Obtener configuración SIFEN desde habilitacion_sifen (SIEMPRE)
    $stmtHab = $pdo->prepare("SELECT * FROM {$masterDb}.habilitacion_sifen WHERE id_empresa = :id AND activo = 1 ORDER BY id DESC LIMIT 1");
    $stmtHab->execute([':id' => $id_empresa]);
    $habilitacion = $stmtHab->fetch(PDO::FETCH_ASSOC);

    if (!$habilitacion) {
        throw new Exception("No se encontró configuración SIFEN activa para esta empresa. Configure en habilitacion_sifen.");
    }

    // 3. Determinar ambiente desde habilitacion_sifen
    $ambienteRaw = strtoupper(trim($habilitacion['ambiente'] ?? 'TEST'));
    $modo = ($ambienteRaw === 'PROD' || $ambienteRaw === '1') ? 'prod' : 'test';

    // 4. Obtener certificado desde habilitacion_sifen
    $certPath = '';
    $certNombre = $habilitacion['cert_nombre'] ?? '';

    // Primero intentar con cert_path de habilitacion_sifen
    if (!empty($habilitacion['cert_path'])) {
        $candidate = $habilitacion['cert_path'];
        if (is_dir($candidate) && !empty($certNombre)) {
            $candidate = rtrim($candidate, '/') . '/' . $certNombre;
        }
        if (file_exists($candidate)) {
            $certPath = $candidate;
        }
    }

    // Fallback: buscar por cert_nombre en carpeta de certificados
    if (empty($certPath) && !empty($certNombre)) {
        $candidates = [
            __DIR__ . '/_lib/php-sifen3-custom/certificados/' . $certNombre,
            __DIR__ . '/_lib/php-sifen3/certificados/' . $certNombre,
        ];
        foreach ($candidates as $c) {
            if (file_exists($c)) {
                $certPath = $c;
                break;
            }
        }
    }

    // Fallback: buscar por RUC
    if (empty($certPath)) {
        $rucBase = preg_replace('/\D+/', '', explode('-', $habilitacion['ruc'] ?? $rucEmpresa)[0]);
        $candidates = [
            __DIR__ . '/_lib/php-sifen3-custom/certificados/' . $rucBase . '.p12',
            __DIR__ . '/_lib/php-sifen3/certificados/' . $rucBase . '.p12',
            __DIR__ . '/_lib/php-sifen3/noenviar/' . $rucBase . '.p12',
        ];
        foreach ($candidates as $c) {
            if (file_exists($c)) {
                $certPath = $c;
                break;
            }
        }
    }

    if (empty($certPath) || !file_exists($certPath)) {
        throw new Exception("Certificado no encontrado. Verifique cert_path/cert_nombre en habilitacion_sifen.");
    }

    // 5. Obtener password del certificado
    $certPass = $habilitacion['cert_pass'] ?? '';
    if (empty($certPass)) {
        // Fallback a empresa si habilitacion no tiene password
        $stmtPass = $pdo->prepare("SELECT cert_pass, password_certificado, clave_certificado FROM empresa WHERE id_empresa = :id");
        $stmtPass->execute([':id' => $id_empresa]);
        $empPass = $stmtPass->fetch(PDO::FETCH_ASSOC);
        $certPass = $empPass['cert_pass'] ?? $empPass['password_certificado'] ?? $empPass['clave_certificado'] ?? '';
    }

    // 6. Ejecutar anulación en SIFEN
    $key = new \sifen\KEY($certPath, $certPass);
    $sifen = new \sifen\Sifen($modo, $key);

    // Usar el nuevo soporte de motivo que implementamos
    $xmlRequest = $sifen->anular($cdc, $motivo);
    $respuesta = $sifen->enviarEvento($xmlRequest);

    if ($respuesta['status'] === 'ok' && !empty($respuesta['response'])) {
        $estRes  = $sifen->getEstRes();
        $msgRes  = $sifen->getMsgRes();
        $protAut = $sifen->getProtAut();
        $codRes  = $sifen->getCodRes();

        $estLower = strtolower((string)$estRes);
        $msgLower = strtolower((string)$msgRes);
        $alreadySameEvent = ($codRes === '4003') ||
            (strpos($msgLower, 'mismo evento') !== false) ||
            (strpos($msgLower, 'ya se encuentra con el mismo evento solicitado') !== false);
        $estadoAceptado = ($estLower === 'aprobado' || $estLower === 'aceptado' || $alreadySameEvent);

        if ($estadoAceptado) {
            $stmtUpdate = $pdo->prepare("
                UPDATE $dbName.factura_ventas 
                SET estado = 0,
                    est_res_anul = 'Anulado',
                    prot_cons_lote_anul = :prot,
                    msg_res_anul = :msg,
                    estado_sifen = 'Anulado'
                WHERE id_factura = :id
            ");
            $stmtUpdate->execute([
                ':id' => $id_factura,
                ':prot' => $protAut,
                ':msg' => $msgRes
            ]);

            echo json_encode([
                'success' => true,
                'status' => 'ok',
                'message' => $alreadySameEvent
                    ? 'CDC ya tenía el evento, se sincroniza como anulado.'
                    : 'Factura anulada correctamente en SIFEN.',
                'data' => [
                    'estado' => 'Aceptado',
                    'mensaje_resp' => $msgRes,
                    'fecha_proceso' => $sifen->getFecProc(),
                    'protocolo' => $protAut,
                    'codigo_resp' => $codRes
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'status' => 'error',
                'message' => 'SIFEN rechazó la anulación: ' . $msgRes,
                'data' => [
                    'estado' => $estRes,
                    'mensaje_resp' => $msgRes,
                    'codigo_resp' => $codRes
                ]
            ]);
        }
    } else {
        throw new Exception($respuesta['error'] ?? 'No hubo respuesta satisfactoria de SIFEN.');
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
