<?php

/**
 * consultar_pendientes_api.php
 * API para consultar y actualizar el estado de facturas pendientes en SIFEN
 * 
 * Uso:
 *   POST /consultar_pendientes_api.php
 *   Body JSON: { "id_empresa": 1039, "id_factura": 603 }  // Factura específica
 *   o: { "id_empresa": 1039 }  // Todas las pendientes de la empresa
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/_lib/php-sifen3/src/soap-sifen.php';
require_once __DIR__ . '/../config/bootstrap.php';

// Configuración de base de datos maestra
$masterDb   = defined('MASTER_DB') ? MASTER_DB : 'serproc1';

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$idEmpresa = intval($input['id_empresa'] ?? 0);
$idFactura = intval($input['id_factura'] ?? 0);

if ($idEmpresa <= 0) {
    echo json_encode(['success' => false, 'error' => 'id_empresa es requerido']);
    exit;
}

try {
    // Conectar a base maestra para obtener config de empresa
    $pdoMaster = Database::getMasterConnection();

    // Obtener datos de empresa y certificado
    $stmt = $pdoMaster->prepare("
        SELECT e.id_empresa, e.dbase, e.ruc, e.cert_path, e.cert_pass,
               h.ambiente, h.csc, h.id_csc
        FROM empresa e
        LEFT JOIN habilitacion_sifen h ON h.id_empresa = e.id_empresa
        WHERE e.id_empresa = :id
    ");
    $stmt->execute([':id' => $idEmpresa]);
    $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$empresa) {
        throw new Exception("Empresa {$idEmpresa} no encontrada");
    }

    $dbase = $empresa['dbase'];
    $ambiente = strtolower($empresa['ambiente'] ?? 'test');
    $certPass = $empresa['cert_pass'];

    // Buscar certificado
    $certPath = $empresa['cert_path'];
    if (empty($certPath) || !file_exists($certPath)) {
        $rucBase = explode('-', $empresa['ruc'])[0];
        $posibles = [
            __DIR__ . "/_lib/php-sifen3/certificados/{$rucBase}.p12",
            __DIR__ . "/_lib/php-sifen3/certificados/NEIMARKEMPF.p12",
            __DIR__ . "/_lib/php-sifen3/noenviar/{$rucBase}.p12",
        ];
        foreach ($posibles as $p) {
            if (file_exists($p)) {
                $certPath = $p;
                break;
            }
        }
    }

    if (empty($certPath) || !file_exists($certPath)) {
        throw new Exception("Certificado no encontrado para empresa {$idEmpresa}");
    }

    // Preparar certificado PEM
    $p12content = file_get_contents($certPath);
    if (!openssl_pkcs12_read($p12content, $certs, $certPass)) {
        throw new Exception("Error leyendo certificado: " . openssl_error_string());
    }
    $tempPem = sys_get_temp_dir() . '/sifen_pend_' . uniqid() . '.pem';
    file_put_contents($tempPem, $certs['cert'] . $certs['pkey']);

    // Conectar a base de la empresa
    $pdoEmpresa = Database::getEmpresaConnection($idEmpresa);

    // Buscar facturas pendientes
    $sql = "SELECT id_factura, nro_factura, cdc, prot_cons_lote_sifen, estado_sifen
            FROM factura_ventas 
            WHERE estado_sifen = 'Pendiente' 
              AND prot_cons_lote_sifen IS NOT NULL";

    if ($idFactura > 0) {
        $sql .= " AND id_factura = :id_factura";
    }
    $sql .= " ORDER BY id_factura DESC LIMIT 20";

    $stmt = $pdoEmpresa->prepare($sql);
    if ($idFactura > 0) {
        $stmt->execute([':id_factura' => $idFactura]);
    } else {
        $stmt->execute();
    }
    $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pendientes)) {
        @unlink($tempPem);
        echo json_encode([
            'success' => true,
            'message' => 'No hay facturas pendientes',
            'procesadas' => 0
        ]);
        exit;
    }

    // Crear cliente SIFEN
    $client = new SifenWSClient($ambiente, false);
    $client->setCertificateFromPath($tempPem)->setPassphrase($certPass);

    $resultados = [];
    $actualizadas = 0;
    $errores = 0;
    $sifenNoDisponible = false;

    foreach ($pendientes as $factura) {
        $protocolo = $factura['prot_cons_lote_sifen'];
        $cdc = $factura['cdc'];
        $idFact = $factura['id_factura'];
        
        // Si SIFEN ya falló, no seguir intentando
        if ($sifenNoDisponible) {
            $resultados[] = [
                'id_factura' => $idFact,
                'nro_factura' => $factura['nro_factura'],
                'status' => 'skipped',
                'error' => 'SIFEN no disponible'
            ];
            continue;
        }
        
        try {
            // Primero intentar por lote
            $res = $client->consulta('siResultLoteDE', ['dProtConsLote' => $protocolo]);

            // Si falla por WSDL, intentar por CDC
            if ($res['status'] !== 'ok' && strpos($res['error'] ?? '', 'WSDL') !== false) {
                $res = $client->consulta('siConsDE', ['dCDC' => $cdc]);
            }

            if ($res['status'] !== 'ok') {
                $errorMsg = $res['error'] ?? 'Error desconocido';
                
                // Detectar si SIFEN no está disponible
                if (strpos($errorMsg, 'WSDL') !== false || 
                    strpos($errorMsg, 'Schema') !== false ||
                    strpos($errorMsg, 'Could not connect') !== false) {
                    $sifenNoDisponible = true;
                }
                
                $resultados[] = [
                    'id_factura' => $idFact,
                    'nro_factura' => $factura['nro_factura'],
                    'status' => 'error',
                    'error' => $errorMsg
                ];
                continue;
            }

            // Parsear respuesta
            $xmlResponse = $res['response'];
            $estado = 'Pendiente';
            $mensaje = '';
            $codigoRespuesta = '';

            // Buscar estado en la respuesta
            if (preg_match('/<dEstRes>([^<]+)<\/dEstRes>/', $xmlResponse, $m)) {
                $codigoRespuesta = $m[1];
                if ($codigoRespuesta === 'Aprobado' || $codigoRespuesta === 'Aprobado con observación') {
                    $estado = 'Aprobado';
                } elseif ($codigoRespuesta === 'Rechazado') {
                    $estado = 'Rechazado';
                }
            }

            // Buscar mensaje
            if (preg_match('/<dMsgRes>([^<]+)<\/dMsgRes>/', $xmlResponse, $m)) {
                $mensaje = $m[1];
            }

            // Buscar código de error específico
            if (preg_match('/<dCodRes>([^<]+)<\/dCodRes>/', $xmlResponse, $m)) {
                $codigo = $m[1];
                if (!empty($codigo) && $codigo !== '0') {
                    $mensaje = "[$codigo] $mensaje";
                }
            }

            // Si hay gResProc, buscar detalles del documento
            if (preg_match_all('/<gResProc>.*?<dCodRes>([^<]+)<\/dCodRes>.*?<dMsgRes>([^<]+)<\/dMsgRes>.*?<\/gResProc>/s', $xmlResponse, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $codRes = $match[1];
                    $msgRes = $match[2];
                    if ($codRes !== '0260') { // 0260 = Aprobado
                        $estado = 'Rechazado';
                        $mensaje = "[$codRes] $msgRes";
                        break;
                    } else {
                        $estado = 'Aprobado';
                        $mensaje = $msgRes;
                    }
                }
            }

            // Actualizar en BD si el estado cambió
            if ($estado !== 'Pendiente') {
                $updateSql = "UPDATE factura_ventas 
                              SET estado_sifen = :estado, 
                                  mensaje_sifen = :mensaje,
                                  xml_respuesta = :xml_resp
                              WHERE id_factura = :id_factura";
                $stmtUpdate = $pdoEmpresa->prepare($updateSql);
                $stmtUpdate->execute([
                    ':estado' => $estado,
                    ':mensaje' => substr($mensaje, 0, 255),
                    ':xml_resp' => $xmlResponse,
                    ':id_factura' => $idFact
                ]);
                $actualizadas++;
            }

            $resultados[] = [
                'id_factura' => $idFact,
                'nro_factura' => $factura['nro_factura'],
                'cdc' => $factura['cdc'],
                'protocolo' => $protocolo,
                'estado_anterior' => 'Pendiente',
                'estado_nuevo' => $estado,
                'mensaje' => $mensaje,
                'status' => 'ok'
            ];
        } catch (Exception $e) {
            $resultados[] = [
                'id_factura' => $idFact,
                'nro_factura' => $factura['nro_factura'],
                'status' => 'error',
                'error' => $e->getMessage()
            ];
            $errores++;
        }
    }

    @unlink($tempPem);

    $response = [
        'success' => true,
        'empresa' => $idEmpresa,
        'database' => $dbase,
        'ambiente' => $ambiente,
        'total_pendientes' => count($pendientes),
        'actualizadas' => $actualizadas,
        'errores' => $errores,
        'resultados' => $resultados
    ];
    
    if ($sifenNoDisponible) {
        $response['warning'] = 'El servicio SIFEN de Paraguay no está disponible en este momento. Intente más tarde.';
        $response['sifen_status'] = 'offline';
    }

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($tempPem) && file_exists($tempPem)) {
        @unlink($tempPem);
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
