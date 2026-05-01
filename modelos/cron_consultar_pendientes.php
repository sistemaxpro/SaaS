#!/usr/bin/env php
<?php
/**
 * cron_consultar_pendientes.php
 * 
 * Script para ejecutar via cron que consulta y actualiza facturas pendientes en SIFEN
 * para TODAS las empresas con facturas pendientes.
 * 
 * Configurar en crontab (cada 5 min):
 *   crontab -e  y agregar la linea del cron
 * 
 * Ejecuta cada 5 minutos y consulta hasta 50 facturas pendientes por ejecución.
 */

if (php_sapi_name() !== 'cli') {
    die("Este script solo puede ejecutarse desde línea de comandos\n");
}

chdir(__DIR__);
require_once __DIR__ . '/../config/bootstrap.php';
require_once '_lib/php-sifen3/src/soap-sifen.php';

// Configuración
$masterDb   = defined('MASTER_DB') ? MASTER_DB : 'serproc1';

$maxFacturas = 50;  // Máximo de facturas a procesar por ejecución
$logFile = __DIR__ . '/_lib/tmp/cron_pendientes.log';

function logMsg($msg)
{
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] {$msg}\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

try {
    logMsg("=== INICIO CRON CONSULTA PENDIENTES ===");

    $pdoMaster = Database::getMasterConnection();

    // Buscar empresas con facturas pendientes
    $stmt = $pdoMaster->query("
        SELECT DISTINCT e.id_empresa, e.dbase, e.ruc, e.cert_path, e.cert_pass,
               h.ambiente
        FROM empresa e
        INNER JOIN habilitacion_sifen h ON h.id_empresa = e.id_empresa
        WHERE e.dbase IS NOT NULL AND e.dbase != ''
          AND h.ambiente IS NOT NULL
    ");
    $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalProcesadas = 0;
    $totalActualizadas = 0;
    $totalErrores = 0;
    $sifenDisponible = null; // null = no testeado, true/false después

    foreach ($empresas as $empresa) {
        if ($totalProcesadas >= $maxFacturas) {
            logMsg("Límite de {$maxFacturas} facturas alcanzado, finalizando.");
            break;
        }

        $dbase = $empresa['dbase'];
        $ambiente = strtolower($empresa['ambiente'] ?? 'test');

        try {
            // Conectar a base de empresa
            $pdoEmpresa = Database::getEmpresaConnection((int)$empresa['id_empresa']);

            // Verificar si tiene tabla factura_ventas con columnas necesarias
            $checkStmt = $pdoEmpresa->query("SHOW COLUMNS FROM factura_ventas LIKE 'estado_sifen'");
            if ($checkStmt->rowCount() === 0) {
                continue; // Esta empresa no tiene SIFEN configurado
            }

            // Buscar facturas pendientes
            $stmt = $pdoEmpresa->query(
                "
                SELECT id_factura, nro_factura, cdc, prot_cons_lote_sifen
                FROM factura_ventas 
                WHERE estado_sifen = 'Pendiente' 
                  AND prot_cons_lote_sifen IS NOT NULL
                  AND cdc IS NOT NULL
                ORDER BY id_factura ASC
                LIMIT " . ($maxFacturas - $totalProcesadas)
            );
            $pendientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($pendientes)) {
                continue;
            }

            logMsg("Empresa {$empresa['id_empresa']} ({$empresa['ruc']}): " . count($pendientes) . " pendientes");

            // Si ya sabemos que SIFEN no está disponible, saltar
            if ($sifenDisponible === false) {
                logMsg("  Saltando - SIFEN no disponible");
                continue;
            }

            // Preparar certificado
            $certPass = $empresa['cert_pass'];
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

            if (empty($certPath) || !file_exists($certPath)) {
                logMsg("  Sin certificado válido, saltando");
                continue;
            }

            $p12content = file_get_contents($certPath);
            if (!openssl_pkcs12_read($p12content, $certs, $certPass)) {
                logMsg("  Error leyendo certificado: " . openssl_error_string());
                continue;
            }
            $tempPem = sys_get_temp_dir() . '/sifen_cron_' . uniqid() . '.pem';
            file_put_contents($tempPem, $certs['cert'] . $certs['pkey']);

            // Crear cliente SIFEN
            $client = new SifenWSClient($ambiente, false);
            $client->setCertificateFromPath($tempPem)->setPassphrase($certPass);

            foreach ($pendientes as $factura) {
                $totalProcesadas++;
                $protocolo = $factura['prot_cons_lote_sifen'];
                $cdc = $factura['cdc'];
                $idFact = $factura['id_factura'];

                try {
                    // Intentar por lote primero
                    $res = $client->consulta('siResultLoteDE', ['dProtConsLote' => $protocolo]);

                    // Fallback por CDC
                    if ($res['status'] !== 'ok' && strpos($res['error'] ?? '', 'WSDL') !== false) {
                        $res = $client->consulta('siConsDE', ['dCDC' => $cdc]);
                    }

                    if ($res['status'] !== 'ok') {
                        $errorMsg = $res['error'] ?? 'Desconocido';

                        // Detectar si SIFEN está caído
                        if (
                            strpos($errorMsg, 'WSDL') !== false ||
                            strpos($errorMsg, 'Schema') !== false ||
                            strpos($errorMsg, 'Could not connect') !== false ||
                            strpos($errorMsg, 'failed to load') !== false
                        ) {
                            $sifenDisponible = false;
                            logMsg("  SIFEN NO DISPONIBLE - abortando");
                            break;
                        }

                        $totalErrores++;
                        logMsg("  Factura {$idFact}: Error - {$errorMsg}");
                        continue;
                    }

                    $sifenDisponible = true;

                    // Parsear respuesta
                    $xmlResponse = $res['response'];
                    $estado = 'Pendiente';
                    $mensaje = '';

                    if (preg_match('/<dEstRes>([^<]+)<\/dEstRes>/', $xmlResponse, $m)) {
                        $codigoRespuesta = $m[1];
                        if ($codigoRespuesta === 'Aprobado' || $codigoRespuesta === 'Aprobado con observación') {
                            $estado = 'Aprobado';
                        } elseif ($codigoRespuesta === 'Rechazado') {
                            $estado = 'Rechazado';
                        }
                    }

                    if (preg_match('/<dMsgRes>([^<]+)<\/dMsgRes>/', $xmlResponse, $m)) {
                        $mensaje = $m[1];
                    }

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
                        $totalActualizadas++;
                        logMsg("  Factura {$idFact}: {$estado}");
                    }
                } catch (Exception $e) {
                    $totalErrores++;
                    logMsg("  Factura {$idFact}: Excepción - " . $e->getMessage());
                }
            }

            @unlink($tempPem);
        } catch (PDOException $e) {
            // Base de datos no existe o error de conexión, continuar con siguiente empresa
            continue;
        }
    }

    logMsg("=== FIN CRON: {$totalProcesadas} procesadas, {$totalActualizadas} actualizadas, {$totalErrores} errores ===");
} catch (Exception $e) {
    logMsg("ERROR FATAL: " . $e->getMessage());
    exit(1);
}
