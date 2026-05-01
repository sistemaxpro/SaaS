<?php

/**
 * Anulación SIFEN (endpoint)
 * Recibe datos desde ventas_sifen.php y arma la anulación con datos reales.
 *
 * Entrada (JSON/POST):
 *  - id_factura (int)     : ID de la factura a anular
 *  - cdc (string, 44)     : CDC de la factura
 *  - motivo (string)      : Motivo de anulación
 *  - cert_path (string)   : Ruta al certificado .p12
 *  - cert_pass (string)   : Clave del certificado
 *  - modo (string)        : 'prod' | 'test'
 *  - ruc (string)         : RUC emisor
 *  - idc, csc (string)    : Datos de control
 *  - id_empresa (int)     : Para buscar configuración en BD si faltan datos
 */

// Configurar OpenSSL legacy ANTES de cualquier require
// Necesario para certificados .p12 con algoritmos legacy en OpenSSL 3.x
$_legacyCnf = __DIR__ . '/../../openssl-legacy.cnf';
if (file_exists($_legacyCnf) && !getenv('OPENSSL_CONF')) {
    putenv('OPENSSL_CONF=' . $_legacyCnf);
}
if (!getenv('OPENSSL_MODULES')) {
    $modulesDir = '/usr/lib/x86_64-linux-gnu/ossl-modules';
    if (is_dir($modulesDir)) {
        putenv('OPENSSL_MODULES=' . $modulesDir);
    }
}

header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/src/php-sifen.php';

// Helpers
function parseXmlValue($xmlString, $tag)
{
    try {
        if (empty($xmlString)) {
            return null;
        }
        $xml = new SimpleXMLElement($xmlString);
        $result = $xml->xpath('//*[local-name()="' . $tag . '"]');
        if ($result && isset($result[0])) {
            return trim((string)$result[0]);
        }
    } catch (Exception $e) {
        // ignorar errores de parseo
    }
    return null;
}

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);
if (!is_array($input) || empty($input)) {
    $input = $_POST;
}

$cdc         = isset($input['cdc']) ? trim($input['cdc']) : '';
$idFactura   = isset($input['id_factura']) ? (int)$input['id_factura'] : 0;
$motivo      = isset($input['motivo']) ? trim($input['motivo']) : (isset($input['motivo_anulacion']) ? trim($input['motivo_anulacion']) : 'Cancelacion voluntaria');
$certPath    = isset($input['cert_path']) ? trim($input['cert_path']) : '';
$certPass    = isset($input['cert_pass']) ? (string)$input['cert_pass'] : '';
$modo        = isset($input['modo']) ? trim($input['modo']) : '';
$ruc         = isset($input['ruc']) ? preg_replace('/\D+/', '', (string)$input['ruc']) : '';
$idc         = isset($input['idc']) ? trim($input['idc']) : '';
$csc         = isset($input['csc']) ? trim($input['csc']) : '';
$idEmpresa   = isset($input['id_empresa']) ? (int)$input['id_empresa'] : (isset($_SESSION['id_empresa']) ? (int)$_SESSION['id_empresa'] : 0);

if (empty($cdc)) {
    echo json_encode(['success' => false, 'message' => 'CDC requerido']);
    exit;
}

// Configuración de BD (se lee desde sesión si existe)
$masterDb = $_SESSION['dbu'] ?? 'serproc1';
$dbHost   = $_SESSION['server'] ?? 'localhost';
$dbUser   = $_SESSION['user'] ?? 'sistemax';
$dbPass   = $_SESSION['password'] ?? 'Armagedon123';

$dbName = null;
$xmlFirmado = '';
$nroFactura = '';

try {
    // Conectar a master para obtener empresa y dbase
    $pdo = new PDO("mysql:host={$dbHost};port=3306;dbname={$masterDb}", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");

    if ($idEmpresa > 0) {
        // Primero obtener dbase de empresa
        $stmtEmp = $pdo->prepare("SELECT dbase, ruc FROM empresa WHERE id_empresa = :id");
        $stmtEmp->execute([':id' => $idEmpresa]);
        $empresa = $stmtEmp->fetch(PDO::FETCH_ASSOC);
        if ($empresa) {
            $dbName = $empresa['dbase'] ?: $masterDb;
            if (empty($ruc)) {
                $ruc = preg_replace('/\D+/', '', (string)($empresa['ruc'] ?? ''));
            }
        }

        // Obtener configuración SIFEN desde habilitacion_sifen
        $stmtHab = $pdo->prepare("
            SELECT h.*, 
                   h.cert_path as hab_cert_path, 
                   h.cert_nombre,
                   h.cert_pass as hab_cert_pass,
                   h.ambiente,
                   h.id_csc as hab_id_csc,
                   h.csc as hab_csc,
                   h.ruc as hab_ruc
            FROM {$masterDb}.habilitacion_sifen h 
            WHERE h.id_empresa = :id AND h.activo = 1 
            ORDER BY h.id DESC LIMIT 1
        ");
        $stmtHab->execute([':id' => $idEmpresa]);
        $habilitacion = $stmtHab->fetch(PDO::FETCH_ASSOC);

        if ($habilitacion) {
            // RUC desde habilitacion_sifen
            if (empty($ruc) && !empty($habilitacion['hab_ruc'])) {
                $ruc = preg_replace('/\D+/', '', explode('-', $habilitacion['hab_ruc'])[0]);
            }

            // Ambiente (modo)
            if (empty($modo)) {
                $ambienteRaw = strtoupper(trim($habilitacion['ambiente'] ?? 'TEST'));
                $modo = ($ambienteRaw === 'PROD' || $ambienteRaw === '1') ? 'prod' : 'test';
            }

            // IDC y CSC
            if (empty($idc) && !empty($habilitacion['hab_id_csc'])) {
                $idc = $habilitacion['hab_id_csc'];
            }
            if (empty($csc) && !empty($habilitacion['hab_csc'])) {
                $csc = $habilitacion['hab_csc'];
            }

            // Certificado - buscar ruta completa
            if (empty($certPath)) {
                $certNombre = $habilitacion['cert_nombre'] ?? '';

                // Intentar con cert_path de habilitacion_sifen
                if (!empty($habilitacion['hab_cert_path'])) {
                    $candidate = $habilitacion['hab_cert_path'];
                    if (is_dir($candidate) && !empty($certNombre)) {
                        $candidate = rtrim($candidate, '/') . '/' . $certNombre;
                    }
                    if (file_exists($candidate)) {
                        $certPath = $candidate;
                    }
                }

                // Intentar en directorio de certificados custom
                if (empty($certPath) && !empty($certNombre)) {
                    $candidate = dirname(__DIR__) . '/certificados/' . $certNombre;
                    if (file_exists($candidate)) {
                        $certPath = $candidate;
                    }
                }

                // Intentar por RUC
                if (empty($certPath) && !empty($ruc)) {
                    $possiblePaths = [
                        dirname(__DIR__) . '/certificados/' . $ruc . '.p12',
                        dirname(__DIR__) . '/noenviar/' . $ruc . '.p12',
                        __DIR__ . '/certificados/' . $ruc . '.p12',
                    ];
                    foreach ($possiblePaths as $path) {
                        if (file_exists($path)) {
                            $certPath = $path;
                            break;
                        }
                    }
                }
            }

            // Contraseña del certificado
            if (empty($certPass) && !empty($habilitacion['hab_cert_pass'])) {
                $certPass = $habilitacion['hab_cert_pass'];
            }
        }
    }

    // Si tenemos id_factura obtenemos xml_firmado y cdc si faltan
    if ($idFactura > 0) {
        if (!$dbName) {
            // si no se pudo determinar dbase, usamos master
            $dbName = $masterDb;
        }
        $stmtFactura = $pdo->prepare("
            SELECT xml_firmado, cdc AS cdc_db, nro_factura
            FROM {$dbName}.factura_ventas
            WHERE id_factura = :id
        ");
        $stmtFactura->execute([':id' => $idFactura]);
        $factura = $stmtFactura->fetch(PDO::FETCH_ASSOC);
        if ($factura) {
            $xmlFirmado = $factura['xml_firmado'] ?? '';
            $nroFactura = $factura['nro_factura'] ?? '';
            if (empty($cdc) && !empty($factura['cdc_db'])) {
                $cdc = trim($factura['cdc_db']);
            }
        }
    }

    // Completar datos desde xml_firmado si está disponible
    if ($xmlFirmado) {
        if (empty($modo)) {
            $amb = parseXmlValue($xmlFirmado, 'dAmb');
            // dAmb: 1 prod, 2 test
            $modo = ($amb === '1') ? 'prod' : 'test';
        }
        if (empty($ruc)) {
            $rucFromXml = parseXmlValue($xmlFirmado, 'dRucEm');
            if ($rucFromXml) {
                $ruc = preg_replace('/\D+/', '', $rucFromXml);
            }
        }
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión BD: ' . $e->getMessage()]);
    exit;
}

// Validaciones finales y fallbacks de certificado
if (empty($certPath) && !empty($ruc)) {
    $candidatos = [
        __DIR__ . "/certificados/{$ruc}.p12",
        __DIR__ . "/noenviar/{$ruc}.p12",
        __DIR__ . "/../certificados/{$ruc}.p12",
    ];
    foreach ($candidatos as $cand) {
        if (file_exists($cand)) {
            $certPath = $cand;
            break;
        }
    }
}

if (empty($certPath) || !file_exists($certPath)) {
    echo json_encode(['success' => false, 'message' => 'Certificado no encontrado']);
    exit;
}
if (empty($certPass)) {
    echo json_encode(['success' => false, 'message' => 'Contraseña de certificado requerida']);
    exit;
}
if (empty($modo)) {
    $modo = 'test';
}

try {
    $key = new \sifen\KEY($certPath, $certPass);
    $sifen = new \sifen\Sifen($modo, $key);
    if (!empty($idc)) {
        $sifen->setIdc($idc);
    }
    if (!empty($csc)) {
        $sifen->setCSC($csc);
    }

    $xmlRequest = $sifen->anular($cdc, $motivo);
    $respuesta = $sifen->enviarEvento($xmlRequest);

    if ($respuesta['status'] === 'ok' && !empty($respuesta['response'])) {
        $estado  = $sifen->getEstRes();
        $mensaje = $sifen->getMsgRes();
        $protAut = $sifen->getProtAut();
        $codRes  = $sifen->getCodRes();

        $estadoLower = strtolower((string)$estado);
        $mensajeLower = strtolower((string)$mensaje);
        $alreadySameEvent = ($codRes === '4003') ||
            (strpos($mensajeLower, 'mismo evento') !== false) ||
            (strpos($mensajeLower, 'ya se encuentra con el mismo evento solicitado') !== false);
        $estadoAceptado = ($estadoLower === 'aprobado' || $estadoLower === 'aceptado' || $alreadySameEvent);

        $data = [
            'id_factura'    => $idFactura,
            'nro_factura'   => $nroFactura,
            'cdc'           => $cdc,
            'estado'        => $estadoAceptado ? 'Aceptado' : $estado,
            'mensaje_resp'  => $mensaje,
            'fecha_proceso' => $sifen->getFecProc(),
            'protocolo'     => $protAut,
            'id_respuesta'  => $sifen->getId(),
            'codigo_resp'   => $codRes,
            'xml'           => $respuesta['response'],
        ];

        // Si SIFEN ya tenía el evento o lo aprobó, sincronizar estado local
        if ($estadoAceptado && $idFactura > 0) {
            try {
                $pdo->prepare("
                    UPDATE {$dbName}.factura_ventas 
                    SET estado = 0,
                        est_res_anul = 'Anulado',
                        prot_cons_lote_anul = :prot,
                        msg_res_anul = :msg,
                        estado_sifen = 'Anulado'
                    WHERE id_factura = :id
                ")->execute([
                    ':prot' => $protAut,
                    ':msg'  => $mensaje,
                    ':id'   => $idFactura
                ]);
            } catch (Exception $e) {
                // No interrumpir la respuesta si la actualización local falla
                $data['warning_local_update'] = $e->getMessage();
            }
        }

        echo json_encode([
            'success' => $estadoAceptado,
            'status'  => $estadoAceptado ? 'ok' : 'error',
            'message' => $estadoAceptado && $alreadySameEvent
                ? 'CDC ya tenía el evento, se sincroniza como anulado.'
                : ($mensaje ?: 'Procesado'),
            'data'    => $data
        ]);
    } else {
        throw new Exception($respuesta['error'] ?? 'No hubo respuesta');
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'status'  => 'error',
        'message' => 'Error al anular: ' . $e->getMessage()
    ]);
}
