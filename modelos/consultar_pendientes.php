#!/usr/bin/env php
<?php
/**
 * consultar_pendientes.php - Script CLI para consultar facturas pendientes en SIFEN
 * 
 * Uso:
 *   php consultar_pendientes.php 1039           # Todas las pendientes de empresa 1039
 *   php consultar_pendientes.php 1039 603       # Factura específica
 */

if (php_sapi_name() !== 'cli') {
    die("Este script solo puede ejecutarse desde línea de comandos\n");
}

chdir(__DIR__);
require_once __DIR__ . '/../config/bootstrap.php';
require_once '_lib/php-sifen3/src/soap-sifen.php';

$idEmpresa = intval($argv[1] ?? 0);
$idFactura = intval($argv[2] ?? 0);

if ($idEmpresa <= 0) {
    echo "Uso: php consultar_pendientes.php <id_empresa> [id_factura]\n";
    echo "Ejemplo: php consultar_pendientes.php 1039\n";
    exit(1);
}

// Configuración
$masterDb   = defined('MASTER_DB') ? MASTER_DB : 'serproc1';

try {
    echo "Conectando a base de datos...\n";

    $pdoMaster = Database::getMasterConnection();

    // Obtener datos de empresa
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
        die("Empresa {$idEmpresa} no encontrada\n");
    }

    echo "Empresa: {$empresa['ruc']} - {$empresa['dbase']}\n";
    echo "Ambiente: " . strtoupper($empresa['ambiente'] ?? 'TEST') . "\n\n";

    $dbase = $empresa['dbase'];
    $ambiente = strtolower($empresa['ambiente'] ?? 'test');
    $certPass = $empresa['cert_pass'];

    // Buscar certificado
    $certPath = '';
    $rucBase = explode('-', $empresa['ruc'])[0];
    $posibles = [
        __DIR__ . "/_lib/php-sifen3/certificados/{$rucBase}.p12",
        __DIR__ . "/_lib/php-sifen3/certificados/NEIMARKEMPF.p12",
    ];
    foreach ($posibles as $p) {
        if (file_exists($p)) {
            $certPath = $p;
            break;
        }
    }

    if (empty($certPath)) {
        die("Certificado no encontrado\n");
    }
    echo "Certificado: " . basename($certPath) . "\n\n";

    // Preparar certificado PEM
    $p12content = file_get_contents($certPath);
    if (!openssl_pkcs12_read($p12content, $certs, $certPass)) {
        die("Error leyendo certificado: " . openssl_error_string() . "\n");
    }
    $tempPem = sys_get_temp_dir() . '/sifen_cli_' . uniqid() . '.pem';
    file_put_contents($tempPem, $certs['cert'] . $certs['pkey']);

    // Conectar a base de empresa
            $pdoEmpresa = Database::getEmpresaConnection((int)$empresa['id_empresa']);

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
        echo "✓ No hay facturas pendientes\n";
        exit(0);
    }

    echo "Facturas pendientes encontradas: " . count($pendientes) . "\n";
    echo str_repeat("-", 80) . "\n";

    // Crear cliente SIFEN
    $client = new SifenWSClient($ambiente, false);
    $client->setCertificateFromPath($tempPem)->setPassphrase($certPass);

    $actualizadas = 0;
    $errores = 0;

    foreach ($pendientes as $factura) {
        $protocolo = $factura['prot_cons_lote_sifen'];
        $cdc = $factura['cdc'];
        $idFact = $factura['id_factura'];

        echo "\nFactura #{$idFact} - {$factura['nro_factura']}\n";
        echo "  CDC: {$cdc}\n";
        echo "  Protocolo: {$protocolo}\n";
        echo "  Consultando SIFEN...";

        try {
            // Primero intentar por lote
            $res = $client->consulta('siResultLoteDE', ['dProtConsLote' => $protocolo]);

            // Si falla por WSDL, intentar por CDC
            if ($res['status'] !== 'ok' && strpos($res['error'] ?? '', 'WSDL') !== false) {
                echo " (fallback CDC)...";
                $res = $client->consulta('siConsDE', ['dCDC' => $cdc]);
            }

            if ($res['status'] !== 'ok') {
                echo " ✗\n";
                echo "  Error: " . ($res['error'] ?? 'Desconocido') . "\n";
                $errores++;

                // Si es error de WSDL/Schema, SIFEN está caído
                if (
                    strpos($res['error'] ?? '', 'WSDL') !== false ||
                    strpos($res['error'] ?? '', 'Schema') !== false
                ) {
                    echo "\n⚠ SIFEN no está disponible. Intente más tarde.\n";
                    break;
                }
                continue;
            }

            // Parsear respuesta
            $xmlResponse = $res['response'];
            $estado = 'Pendiente';
            $mensaje = '';

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

            // Buscar detalles en gResProc
            if (preg_match_all('/<gResProc>.*?<dCodRes>([^<]+)<\/dCodRes>.*?<dMsgRes>([^<]+)<\/dMsgRes>.*?<\/gResProc>/s', $xmlResponse, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $codRes = $match[1];
                    $msgRes = $match[2];
                    if ($codRes !== '0260') {
                        $estado = 'Rechazado';
                        $mensaje = "[$codRes] $msgRes";
                        break;
                    } else {
                        $estado = 'Aprobado';
                        $mensaje = $msgRes;
                    }
                }
            }

            echo " ✓\n";
            echo "  Estado: {$estado}\n";
            if (!empty($mensaje)) {
                echo "  Mensaje: {$mensaje}\n";
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
                echo "  ✓ Actualizado en BD\n";
            }
        } catch (Exception $e) {
            echo " ✗\n";
            echo "  Excepción: " . $e->getMessage() . "\n";
            $errores++;
        }
    }

    @unlink($tempPem);

    echo "\n" . str_repeat("-", 80) . "\n";
    echo "Resumen: {$actualizadas} actualizadas, {$errores} errores\n";
} catch (Exception $e) {
    if (isset($tempPem) && file_exists($tempPem)) {
        @unlink($tempPem);
    }
    die("Error: " . $e->getMessage() . "\n");
}
