<?php

/**
 * API para re-intentar el envío de una factura a SIFEN
 * Ubicación: /pos/api/sifen_retry.php
 */
ob_start();

// Configurar OpenSSL para soportar certificados legacy (SHA256/RC2)
// Necesario para certificados .p12 antiguos en OpenSSL 3.x
$legacyCnf = dirname(__DIR__, 2) . '/openssl-legacy.cnf';
if (file_exists($legacyCnf)) {
    putenv('OPENSSL_CONF=' . $legacyCnf);
}

// Forzar ruta de módulos de OpenSSL 3.x (legacy provider)
if (!getenv('OPENSSL_MODULES')) {
    $modulesDir = '/usr/lib/x86_64-linux-gnu/ossl-modules';
    if (is_dir($modulesDir)) {
        putenv('OPENSSL_MODULES=' . $modulesDir);
    }
}

// Configurar para mostrar errores y registrarlos
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar en pantalla
ini_set('html_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

// Asegurar que siempre devolvemos JSON, incluso en errores fatales
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Error fatal: ' . $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ]);
    }
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Intentar cargar la librería con manejo de errores
if (!file_exists('sifen_lib.php')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Librería sifen_lib.php no encontrada',
        'debug' => 'Verificar que existe /pos/api/sifen_lib.php'
    ]);
    exit;
}

try {
    require_once 'sifen_lib.php';
    require_once 'sifen_queue.php';
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Error cargando sifen_lib.php: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$idFactura = (int)($_POST['id_factura'] ?? 0);
$idEmpresa = (int)($_POST['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$queueAction = (string)($_POST['queue_action'] ?? $_GET['queue_action'] ?? $_GET['action'] ?? '');

// LOG CRÍTICO
error_log("SIFEN_RETRY - id_factura: $idFactura, id_empresa POST: " . ($_POST['id_empresa'] ?? 'NO ENVIADO') . ", id_empresa SESSION: " . ($_SESSION['id_empresa'] ?? 'NO EN SESION') . ", id_empresa FINAL: $idEmpresa");

if ($idFactura <= 0 && !in_array($queueAction, ['process_one', 'process_batch'], true)) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode(['success' => false, 'message' => 'ID de factura inválido']);
    exit;
}

try {
    // Obtener conexión a la base de datos de la empresa
    $conn = getEmpresaConnection($idEmpresa);
    $pdo = $conn['pdo'];
    $dbName = $conn['dbName'];

    $cfgId = $conn['config']['id_empresa'] ?? $idEmpresa;
    $cfgRuc = $conn['config']['ruc'] ?? 'N/A';
    error_log("SIFEN_RETRY - Config cargada: ID={$cfgId}, RUC={$cfgRuc}, DB=$dbName");

    // Cola FE: encolar emisión/consulta
    if ($queueAction === 'enqueue') {
        $accion = (string)($_POST['accion'] ?? 'emitir');
        $prio = (int)($_POST['prioridad'] ?? 5);
        $pdoMaster = getMasterConnection();
        $q = sifenQueueEnqueue($pdoMaster, $idEmpresa, $dbName, $idFactura, $accion, $prio);

        $stmtMark = $pdo->prepare("UPDATE $dbName.factura_ventas
            SET estado_sifen = 'Guardado Local',
                mensaje_sifen = 'Venta registrada. FE en cola para envío a SIFEN.'
            WHERE id_factura = :id");
        $stmtMark->execute([':id' => $idFactura]);
        sifenQueueUpdateFeState($pdo, $dbName, $idFactura, 'Guardado Local', 'Venta registrada. FE en cola para envío a SIFEN.');

        $ruidoSalida = trim((string)ob_get_clean());
        if ($ruidoSalida !== '') {
            error_log('SIFEN_RETRY output descartado (enqueue): ' . substr($ruidoSalida, 0, 600));
        }
        echo json_encode([
            'success' => true,
            'queued' => true,
            'queue_id' => $q['queue_id'] ?? null,
            'already_queued' => (bool)($q['already_queued'] ?? false),
            'message' => 'FE encolada para procesamiento asíncrono'
        ]);
        exit;
    }

    // Cola FE: procesar 1 item (uso manual/API interna)
    if ($queueAction === 'process_one') {
        $queueId = isset($_POST['queue_id']) ? (int)$_POST['queue_id'] : null;
        $pdoMaster = getMasterConnection();
        $res = sifenQueueProcessOne($pdoMaster, $queueId > 0 ? $queueId : null);

        $ruidoSalida = trim((string)ob_get_clean());
        if ($ruidoSalida !== '') {
            error_log('SIFEN_RETRY output descartado (process_one): ' . substr($ruidoSalida, 0, 600));
        }
        echo json_encode($res);
        exit;
    }

    // Cola FE: procesar lote de items (uso manual/API interna)
    if ($queueAction === 'process_batch') {
        $limit = max(1, min(100, (int)($_POST['limit'] ?? 10)));
        $pdoMaster = getMasterConnection();
        $out = [];
        for ($i = 0; $i < $limit; $i++) {
            $res = sifenQueueProcessOne($pdoMaster, null);
            if (!(bool)($res['processed'] ?? false)) {
                break;
            }
            $out[] = $res;
        }

        $ruidoSalida = trim((string)ob_get_clean());
        if ($ruidoSalida !== '') {
            error_log('SIFEN_RETRY output descartado (process_batch): ' . substr($ruidoSalida, 0, 600));
        }
        echo json_encode([
            'success' => true,
            'processed' => count($out),
            'items' => $out
        ]);
        exit;
    }

    // Si la factura no existe en la conexión de empresa, fallback a conexión master
    // (venta.php inserta con conexión master y puede diferir de server/user de empresa).
    $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM $dbName.factura_ventas WHERE id_factura = :id");
    $stmtChk->execute([':id' => $idFactura]);
    $existsFactura = (int)$stmtChk->fetchColumn() > 0;
    if (!$existsFactura) {
        $pdoMasterFallback = getMasterConnection();
        $stmtDb = $pdoMasterFallback->prepare("SELECT dbase FROM " . MASTER_DB . ".empresa WHERE id_empresa = :id LIMIT 1");
        $stmtDb->execute([':id' => $idEmpresa]);
        $dbNameMaster = (string)($stmtDb->fetchColumn() ?: $dbName);

        $stmtChkMaster = $pdoMasterFallback->prepare("SELECT COUNT(*) FROM $dbNameMaster.factura_ventas WHERE id_factura = :id");
        $stmtChkMaster->execute([':id' => $idFactura]);
        $existsMaster = (int)$stmtChkMaster->fetchColumn() > 0;

        if ($existsMaster) {
            error_log("SIFEN_RETRY - Fallback a conexión master aplicado. DB anterior={$dbName}, DB nueva={$dbNameMaster}, id_factura={$idFactura}");
            $pdo = $pdoMasterFallback;
            $dbName = $dbNameMaster;
        } else {
            error_log("SIFEN_RETRY - Factura {$idFactura} no encontrada ni en DB empresa ({$dbName}) ni en master ({$dbNameMaster})");
        }
    }

    // --- NUEVO: Actualizar parámetros de SIFEN si se proporcionan ---
    if (isset($_POST['actualizar_habilitacion']) && $_POST['actualizar_habilitacion'] == '1') {
        $masterDb = 'serproc1'; // Siempre serproc1: habilitacion_sifen está en la master DB
        $pdoMaster = getMasterConnection();

        $setFields = [];
        $params = [':id_empresa' => $idEmpresa];

        if (!empty($_POST['csc'])) {
            $setFields[] = "csc = :csc";
            $params[':csc'] = $_POST['csc'];
        }
        if (!empty($_POST['id_csc'])) {
            $setFields[] = "id_csc = :id_csc";
            $params[':id_csc'] = $_POST['id_csc'];
        }
        if (!empty($_POST['ambiente'])) {
            $setFields[] = "ambiente = :ambiente";
            $params[':ambiente'] = $_POST['ambiente'];
        }

        if (!empty($setFields)) {
            $sqlUp = "UPDATE {$masterDb}.habilitacion_sifen SET " . implode(", ", $setFields) . " WHERE id_empresa = :id_empresa AND activo = 1";
            $stmtUp = $pdoMaster->prepare($sqlUp);
            $stmtUp->execute($params);
            error_log("SIFEN_RETRY - Parámetros de habilitación actualizados para empresa $idEmpresa");
        }
    }
    // -------------------------------------------------------------

    // Consultar SOLO estado de lote (sin reenviar FE)
    if (isset($_POST['consult_only']) && $_POST['consult_only'] == '1') {
        $stmtFact = $pdo->prepare("SELECT id_factura, cdc, prot_cons_lote_sifen, estado_sifen FROM $dbName.factura_ventas WHERE id_factura = :id LIMIT 1");
        $stmtFact->execute([':id' => $idFactura]);
        $fact = $stmtFact->fetch(PDO::FETCH_ASSOC);
        if (!$fact) {
            throw new Exception('Factura no encontrada');
        }

        $protLote = trim((string)($fact['prot_cons_lote_sifen'] ?? ''));
        if ($protLote === '') {
            throw new Exception('La factura no tiene protocolo de consulta de lote');
        }

        $pdoMaster = getMasterConnection();
        $stmtHab = $pdoMaster->prepare("SELECT ambiente, cert_path, cert_nombre, cert_pass, csc, id_csc FROM " . MASTER_DB . ".habilitacion_sifen WHERE id_empresa = :id AND activo = 1 ORDER BY id DESC LIMIT 1");
        $stmtHab->execute([':id' => $idEmpresa]);
        $hab = $stmtHab->fetch(PDO::FETCH_ASSOC);
        if (!$hab) {
            throw new Exception('No se encontró habilitación SIFEN activa para la empresa');
        }

        $ambienteRaw = strtoupper(trim((string)($hab['ambiente'] ?? 'TEST')));
        $ambiente = ($ambienteRaw === 'PROD' || $ambienteRaw === '1') ? 'prod' : 'test';

        $certificado = '';
        if (!empty($hab['cert_path'])) {
            $certificado = basename((string)$hab['cert_path']);
        }
        if ($certificado === '' && !empty($hab['cert_nombre'])) {
            $certificado = basename((string)$hab['cert_nombre']);
        }
        if ($certificado === '') {
            throw new Exception('Certificado SIFEN no definido en habilitación');
        }
        $certPath = dirname(__DIR__, 2) . '/_lib/php-sifen3/certificados/' . $certificado;
        if (!is_file($certPath)) {
            throw new Exception("Certificado no encontrado: {$certPath}");
        }
        $certPass = (string)($hab['cert_pass'] ?? '');
        if ($certPass === '') {
            throw new Exception('Contraseña de certificado no configurada');
        }

        $extractXmlValue = function (string $xml, array $tags): string {
            $xml = trim($xml);
            if ($xml === '') return '';
            try {
                $sx = @simplexml_load_string($xml);
                if ($sx !== false) {
                    foreach ($tags as $tag) {
                        $r = $sx->xpath("//*[local-name()='{$tag}']");
                        if (is_array($r) && isset($r[0])) {
                            $v = trim((string)$r[0]);
                            if ($v !== '') return $v;
                        }
                    }
                }
            } catch (Throwable $e) {}
            foreach ($tags as $tag) {
                if (preg_match('/<[^:>]*:?' . preg_quote($tag, '/') . '>\s*(.*?)\s*<\/[^:>]*:?' . preg_quote($tag, '/') . '>/si', $xml, $m)) {
                    $v = trim((string)($m[1] ?? ''));
                    if ($v !== '') return $v;
                }
            }
            return '';
        };

        $key = new \sifen\KEY($certPath, $certPass);
        $sifen = new \sifen\Sifen($ambiente, $key);
        if (!empty($hab['csc']) && !empty($hab['id_csc']) && method_exists($sifen, 'setCSCid')) {
            $sifen->setCSCid((string)$hab['id_csc'], (string)$hab['csc']);
        }

        $toText = function ($v): string {
            if (is_string($v)) return $v;
            if (is_scalar($v) || $v === null) return (string)$v;
            $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($j) ? $j : '';
        };

        $resultadoLote = $sifen->queryLote($protLote);
        $xml = '';
        $msgRes = '';
        $estRes = '';
        $codRes = '';
        $cdcResp = '';
        $protAut = '';

        $findInArray = function ($arr, array $keys) use (&$findInArray): string {
            if (!is_array($arr)) return '';
            foreach ($arr as $k => $v) {
                $kRaw = strtolower((string)$k);
                $kNorm = $kRaw;
                if (strpos($kNorm, ':') !== false) {
                    $parts = explode(':', $kNorm);
                    $kNorm = end($parts);
                }
                foreach ($keys as $target) {
                    $targetNorm = strtolower((string)$target);
                    if ($kNorm === $targetNorm || str_ends_with($kRaw, ':' . $targetNorm)) {
                        if (is_scalar($v)) return trim((string)$v);
                        if (is_array($v)) {
                            if (isset($v['_value']) && is_scalar($v['_value'])) {
                                return trim((string)$v['_value']);
                            }
                            if (isset($v[0]) && is_scalar($v[0])) {
                                return trim((string)$v[0]);
                            }
                            $flat = json_encode($v, JSON_UNESCAPED_UNICODE);
                            return is_string($flat) ? $flat : '';
                        }
                    }
                }
                if (is_array($v)) {
                    $found = $findInArray($v, $keys);
                    if ($found !== '') return $found;
                }
            }
            return '';
        };

        $collectInArray = function ($arr, array $keys) use (&$collectInArray): array {
            $out = [];
            if (!is_array($arr)) return $out;
            foreach ($arr as $k => $v) {
                $kRaw = strtolower((string)$k);
                $kNorm = $kRaw;
                if (strpos($kNorm, ':') !== false) {
                    $parts = explode(':', $kNorm);
                    $kNorm = end($parts);
                }
                foreach ($keys as $target) {
                    $targetNorm = strtolower((string)$target);
                    if ($kNorm === $targetNorm || str_ends_with($kRaw, ':' . $targetNorm)) {
                        if (is_scalar($v)) {
                            $val = trim((string)$v);
                            if ($val !== '') $out[] = $val;
                        } elseif (is_array($v)) {
                            if (isset($v['_value']) && is_scalar($v['_value'])) {
                                $val = trim((string)$v['_value']);
                                if ($val !== '') $out[] = $val;
                            } elseif (isset($v[0]) && is_scalar($v[0])) {
                                $val = trim((string)$v[0]);
                                if ($val !== '') $out[] = $val;
                            }
                        }
                    }
                }
                if (is_array($v)) {
                    $out = array_merge($out, $collectInArray($v, $keys));
                }
            }
            return $out;
        };

        if (is_array($resultadoLote)) {
            if (isset($resultadoLote['response']) && is_string($resultadoLote['response'])) {
                $xml = $resultadoLote['response'];
            } elseif (isset($resultadoLote['response']) && is_object($resultadoLote['response']) && method_exists($resultadoLote['response'], 'saveXML')) {
                $xml = (string)$resultadoLote['response']->saveXML();
            } else {
                // queryLote del SDK puede devolver array parseado (sin XML crudo)
                $msgRes = $toText($findInArray($resultadoLote, [
                    'dMsgRes', 'dMsgResLot', 'dMsgResLote',
                    'dDesMotivoRechazo', 'xMotivoRechazo', 'dMotivo', 'dDesRes'
                ]));
                $estRes = strtoupper($toText($findInArray($resultadoLote, ['dEstRes'])));
                $codRes = strtoupper($toText($findInArray($resultadoLote, ['dCodRes'])));
                $cdcResp = strtoupper(trim($toText($findInArray($resultadoLote, ['dCDC', 'Id', 'id']))));
                $protAut = $toText($findInArray($resultadoLote, ['dProtAut']));
                $xml = json_encode($resultadoLote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!is_string($xml)) {
                    $xml = '';
                }
            }
        } elseif (is_string($resultadoLote)) {
            $xml = $resultadoLote;
        } elseif (is_object($resultadoLote) && method_exists($resultadoLote, 'saveXML')) {
            $xml = (string)$resultadoLote->saveXML();
        }

        if ($xml !== '') {
            if ($msgRes === '') $msgRes = $toText($extractXmlValue($xml, [
                'dMsgRes', 'dMsgResLot', 'dMsgResLote',
                'dDesMotivoRechazo', 'xMotivoRechazo', 'dMotivo', 'dDesRes'
            ]));
            if ($estRes === '') $estRes = strtoupper($toText($extractXmlValue($xml, ['dEstRes'])));
            if ($codRes === '') $codRes = strtoupper($toText($extractXmlValue($xml, ['dCodRes'])));
            if ($cdcResp === '') $cdcResp = strtoupper(trim($toText($extractXmlValue($xml, ['dCDC', 'Id', 'id']))));
            if ($protAut === '') $protAut = $toText($extractXmlValue($xml, ['dProtAut']));
        }

        // Si vino array parseado con múltiples nodos, priorizar mensajes de detalle/rechazo.
        if (is_array($resultadoLote)) {
            $msgCandidates = $collectInArray($resultadoLote, [
                'dMsgRes', 'dMsgResLot', 'dMsgResLote',
                'dDesMotivoRechazo', 'xMotivoRechazo', 'dMotivo', 'dDesRes'
            ]);
            $codCandidates = array_map('strtoupper', $collectInArray($resultadoLote, ['dCodRes']));
            $estCandidates = array_map('strtoupper', $collectInArray($resultadoLote, ['dEstRes']));

            $pickMsg = '';
            foreach ($msgCandidates as $m) {
                $up = strtoupper((string)$m);
                if (strpos($up, 'RECHAZ') !== false || strpos($up, 'DENEG') !== false || strpos($up, 'INVALID') !== false || strpos($up, 'ERROR') !== false) {
                    $pickMsg = (string)$m;
                    break;
                }
            }
            if ($pickMsg === '') {
                foreach ($msgCandidates as $m) {
                    $up = strtoupper((string)$m);
                    if (strpos($up, 'PROCESAMIENTO DE LOTE') === false) {
                        $pickMsg = (string)$m;
                        break;
                    }
                }
            }
            if ($pickMsg !== '') {
                $msgRes = $toText($pickMsg);
            }

            if (!empty($codCandidates)) {
                foreach ($codCandidates as $c) {
                    if (in_array($c, ['RECHAZADO', '0500', '500', '001', '100', 'APROBADO'], true)) {
                        $codRes = $c;
                        break;
                    }
                }
            }
            if (!empty($estCandidates)) {
                foreach ($estCandidates as $e) {
                    if (in_array($e, ['RECHAZADO', '2', '002', '200', 'APROBADO', '1', '001', '100'], true)) {
                        $estRes = $e;
                        break;
                    }
                }
            }
        }

        if ($xml === '' && $msgRes === '' && $estRes === '' && $codRes === '') {
            throw new Exception('SIFEN no devolvió respuesta válida al consultar el lote');
        }

        $estado = 'Pendiente';
        $xml = $toText($xml);
        $msgRes = $toText($msgRes);
        $estRes = strtoupper($toText($estRes));
        $codRes = strtoupper($toText($codRes));
        $cdcResp = strtoupper(trim($toText($cdcResp)));
        $protAut = $toText($protAut);

        $resUpper = strtoupper($xml);
        $msgUpper = strtoupper($msgRes);
        $hasValidationErrorMsg = (bool)preg_match(
            '/(OBLIGATORI|INVALID|NO\s+CORRESPONDE|NO\s+EXISTE|NO\s+VALID|ERROR\s+DE\s+VALIDACI|GRUPO\s+DE\s+CAMPOS)/',
            $msgUpper
        );
        $isAprobado = strpos($msgUpper, 'APROBADO') !== false
            || in_array($estRes, ['APROBADO', '1', '001', '100'], true)
            || in_array($codRes, ['APROBADO', '001', '100'], true)
            || preg_match('/<[^>]*:dEstRes>\s*(1|001|100|APROBADO)\s*<\/[^>]*:dEstRes>/i', $xml);
        $isRechazado = strpos($resUpper, 'RECHAZ') !== false
            || strpos($msgUpper, 'RECHAZ') !== false
            || strpos($msgUpper, 'DENEG') !== false
            || strpos($msgUpper, 'INVALID') !== false
            || $hasValidationErrorMsg
            || in_array($estRes, ['RECHAZADO', '2', '002', '200'], true)
            || in_array($codRes, ['RECHAZADO', '0500', '500'], true)
            || preg_match('/<[^>]*:dEstRes>\s*(2|002|200|RECHAZADO)\s*<\/[^>]*:dEstRes>/i', $xml);

        $cdcLocal = strtoupper(trim((string)($fact['cdc'] ?? '')));
        if ($isAprobado && $cdcResp !== '' && $cdcLocal !== '' && $cdcResp !== $cdcLocal) {
            $isAprobado = false;
            $msgRes = trim(($msgRes !== '' ? $msgRes . ' | ' : '') . 'CDC de respuesta no coincide con factura local');
        }

        if ($isAprobado) $estado = 'Aprobado';
        if ($isRechazado) $estado = 'Rechazado';
        if ($msgRes === '') {
            $msgRes = ($estado === 'Pendiente')
                ? 'SIFEN aún no confirmó la aprobación del documento'
                : ('Estado SIFEN: ' . $estado);
        }

        // Si el lote está concluido pero no tenemos estado final del DE,
        // consultar estado puntual por CDC para obtener motivo real.
        $msgUpperNow = strtoupper($msgRes);
        $loteConcluido = (strpos($msgUpperNow, 'PROCESAMIENTO DE LOTE') !== false && strpos($msgUpperNow, 'CONCLUIDO') !== false);
        if (($estado === 'Pendiente' || $loteConcluido) && $cdcLocal !== '') {
            try {
                $resDe = $sifen->siConsDE($cdcLocal);

                $msgDe = '';
                $codDe = '';
                $estDe = '';
                $xmlDe = '';

                if (is_array($resDe)) {
                    $msgDe = $toText($findInArray($resDe, [
                        'dMsgRes', 'dMsgResLot', 'dDesMotivoRechazo', 'xMotivoRechazo', 'dMotivo', 'dDesRes', 'msg'
                    ]));
                    $codDe = strtoupper($toText($findInArray($resDe, ['dCodRes', 'cod'])));
                    $estDe = strtoupper($toText($findInArray($resDe, ['dEstRes', 'est'])));
                    $xmlDe = $toText($findInArray($resDe, ['xml']));
                    if ($xmlDe === '') {
                        $tmp = json_encode($resDe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $xmlDe = is_string($tmp) ? $tmp : '';
                    }
                } else {
                    $xmlDe = $toText($resDe);
                }

                if ($xmlDe !== '') {
                    if ($msgDe === '') $msgDe = $toText($extractXmlValue($xmlDe, [
                        'dMsgRes', 'dDesMotivoRechazo', 'xMotivoRechazo', 'dMotivo', 'dDesRes'
                    ]));
                    if ($codDe === '') $codDe = strtoupper($toText($extractXmlValue($xmlDe, ['dCodRes'])));
                    if ($estDe === '') $estDe = strtoupper($toText($extractXmlValue($xmlDe, ['dEstRes'])));
                }

                $aprobDe = strpos(strtoupper($msgDe), 'APROBADO') !== false
                    || in_array($estDe, ['APROBADO', '1', '001', '100'], true)
                    || in_array($codDe, ['APROBADO', '001', '100'], true);
                $msgDeUpper = strtoupper($msgDe);
                $rechDe = strpos($msgDeUpper, 'RECHAZ') !== false
                    || strpos($msgDeUpper, 'DENEG') !== false
                    || strpos($msgDeUpper, 'INVALID') !== false
                    || (bool)preg_match('/(OBLIGATORI|NO\s+CORRESPONDE|NO\s+EXISTE|NO\s+VALID|ERROR\s+DE\s+VALIDACI|GRUPO\s+DE\s+CAMPOS)/', $msgDeUpper)
                    || in_array($estDe, ['RECHAZADO', '2', '002', '200'], true)
                    || in_array($codDe, ['RECHAZADO', '0500', '500'], true);

                if ($aprobDe) {
                    $estado = 'Aprobado';
                    if ($msgDe !== '') $msgRes = $msgDe;
                    if ($xmlDe !== '') $xml = $xmlDe;
                } elseif ($rechDe) {
                    $estado = 'Rechazado';
                    if ($msgDe !== '') $msgRes = $msgDe;
                    if ($xmlDe !== '') $xml = $xmlDe;
                }
            } catch (Throwable $e) {
                // Mantener resultado del lote si falla consulta puntual.
            }
        }

        $stmtUp = $pdo->prepare("UPDATE $dbName.factura_ventas
            SET estado_sifen = :est,
                mensaje_sifen = :msg,
                protocolo_autorizacion = :prot_aut,
                xml_respuesta = :xml_r,
                fecha_envio_sifen = NOW()
            WHERE id_factura = :id");
        $stmtUp->execute([
            ':est' => $estado,
            ':msg' => substr($msgRes, 0, 255),
            ':prot_aut' => ($protAut !== '' ? $protAut : null),
            ':xml_r' => $xml,
            ':id' => $idFactura
        ]);

        try {
            $txState = 'PENDIENTE';
            if ($estado === 'Aprobado') $txState = 'ENVIADO';
            if ($estado === 'Rechazado') $txState = 'RECHAZADO';

            $stmtFeUp = $pdo->prepare("UPDATE $dbName.fe
                SET estado_transmision = :tx,
                    estado_electronico = :est,
                    mensaje_set = :msg,
                    acuse_sifen = :xml_r,
                    fecha_transmision = NOW()
                WHERE id_factura = :id");
            $stmtFeUp->execute([
                ':tx' => $txState,
                ':est' => $estado,
                ':msg' => substr($msgRes, 0, 255),
                ':xml_r' => $xml,
                ':id' => $idFactura
            ]);
        } catch (Throwable $eFe) {
            // No cortar respuesta si falla sincronía de tabla fe.
        }

        $response = [
            'success' => ($estado === 'Aprobado'),
            'estado' => $estado,
            'message' => $msgRes,
            'prot_cons_lote_sifen' => $protLote
        ];
        if ($estado !== 'Aprobado') {
            $response['success'] = false;
        }

        $ruidoSalida = trim((string)ob_get_clean());
        if ($ruidoSalida !== '') {
            error_log('SIFEN_RETRY output descartado (consult_only): ' . substr($ruidoSalida, 0, 600));
        }
        echo json_encode($response);
        exit;
    }

    // Guardrail: si ya está pendiente con protocolo de lote, no reemitir.
    // Debe consultarse el estado del lote primero para evitar rechazos duplicados/confusos.
    if (!isset($_POST['consult_only']) || $_POST['consult_only'] != '1') {
        $stmtState = $pdo->prepare("SELECT estado_sifen, prot_cons_lote_sifen FROM $dbName.factura_ventas WHERE id_factura = :id LIMIT 1");
        $stmtState->execute([':id' => $idFactura]);
        $stateRow = $stmtState->fetch(PDO::FETCH_ASSOC);
        if ($stateRow) {
            $estadoSifen = strtolower(trim((string)($stateRow['estado_sifen'] ?? '')));
            $protLote = trim((string)($stateRow['prot_cons_lote_sifen'] ?? ''));
            if ($estadoSifen === 'pendiente' && $protLote !== '') {
                $ruidoSalida = trim((string)ob_get_clean());
                if ($ruidoSalida !== '') {
                    error_log('SIFEN_RETRY output descartado (pending_guard): ' . substr($ruidoSalida, 0, 600));
                }
                echo json_encode([
                    'success' => false,
                    'estado' => 'Pendiente',
                    'message' => 'La factura está pendiente en SIFEN. Use "Consultar Sifen" para actualizar el resultado antes de reenviar.'
                ]);
                exit;
            }
        }
    }

    // Si se solicita conversión (ej. de Ticket a FE)
    if (isset($_POST['convert']) && $_POST['convert'] == '1') {
        // 1. Obtener datos actuales de la factura para conocer el timbrado, caja y prefijos
        $stmtFac = $pdo->prepare("SELECT id_caja, timbrado, nro_factura, tipo_documento FROM $dbName.factura_ventas WHERE id_factura = :id");
        $stmtFac->execute([':id' => $idFactura]);
        $factData = $stmtFac->fetch(PDO::FETCH_ASSOC);

        if ($factData) {
            $idCaja = (int)$factData['id_caja'];
            $timbrado = (string)($factData['timbrado'] ?? '');
            try {
                $stmtHabTim = $pdoMaster->prepare("
                    SELECT numero_timbrado, timbrado
                    FROM " . MASTER_DB . ".habilitacion_sifen
                    WHERE id_empresa = :id AND activo = 1
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stmtHabTim->execute([':id' => $idEmpresa]);
                $habTim = $stmtHabTim->fetch(PDO::FETCH_ASSOC) ?: [];
                $timbradoHab = trim((string)($habTim['numero_timbrado'] ?? $habTim['timbrado'] ?? ''));
                if ($timbradoHab !== '') {
                    $timbrado = $timbradoHab;
                }
            } catch (Exception $e) {
                // conservar timbrado existente en factura si falla consulta
            }
            $nroActual = (string)($factData['nro_factura'] ?? '');
            $isFormatoFe = (bool)preg_match('/^\d{3}-\d{3}-\d{7}$/', $nroActual);
            $needsNormalize = ((int)$factData['tipo_documento'] != 3) || !$isFormatoFe;

            // 2. Obtener configuración de prefijos y base de secuencia de la caja
            $stmtCaja = $pdo->prepare("SELECT factura_1, factura_2, factura_3 FROM $dbName.cajas WHERE id_caja = :id");
            $stmtCaja->execute([':id' => $idCaja]);
            $caja = $stmtCaja->fetch(PDO::FETCH_ASSOC);

            if ($caja && $needsNormalize) {
                $f1 = str_pad($caja['factura_1'] ?? '001', 3, '0', STR_PAD_LEFT);
                $f2 = str_pad($caja['factura_2'] ?? '001', 3, '0', STR_PAD_LEFT);
                $baseSeq = (int)($caja['factura_3'] ?? 0);

                // 3. Contar facturas electrónicas existentes con este timbrado y prefijos
                $stmtCount = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM $dbName.factura_ventas 
                    WHERE timbrado = :timbrado 
                      AND tipo_documento = 3
                      AND SUBSTRING_INDEX(nro_factura, '-', 1) = :f1 
                      AND SUBSTRING_INDEX(SUBSTRING_INDEX(nro_factura, '-', 2), '-', -1) = :f2
                ");
                $stmtCount->execute([
                    ':timbrado' => $timbrado,
                    ':f1' => $f1,
                    ':f2' => $f2
                ]);
                $existingCount = (int)$stmtCount->fetchColumn();

                $nextSeq = $baseSeq + $existingCount + 1;

                // 4. Generar nuevo número y verificar unicidad
                do {
                    $newNro = $f1 . '-' . $f2 . '-' . str_pad($nextSeq, 7, '0', STR_PAD_LEFT);
                    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM $dbName.factura_ventas WHERE nro_factura = :nro AND timbrado = :timbrado");
                    $stmtCheck->execute([':nro' => $newNro, ':timbrado' => $timbrado]);
                    if ($stmtCheck->fetchColumn() > 0) {
                        $nextSeq++;
                    } else {
                        break;
                    }
                } while (true);

                // 5. Aplicar conversión/normalización de tipo y número FE
                try {
                    $stmtUpdate = $pdo->prepare("UPDATE $dbName.factura_ventas SET tipo_documento = 3, nro_factura = :nro, timbrado = :timbrado WHERE id_factura = :id");
                    $stmtUpdate->execute([':nro' => $newNro, ':timbrado' => $timbrado, ':id' => $idFactura]);
                } catch (Exception $e) {
                    $stmtUpdate = $pdo->prepare("UPDATE $dbName.factura_ventas SET tipo_documento = 3, nro_factura = :nro WHERE id_factura = :id");
                    $stmtUpdate->execute([':nro' => $newNro, ':id' => $idFactura]);
                }
            } elseif ($needsNormalize) {
                // Fallback: solo cambiar tipo si no hay datos de caja (no recomendado)
                try {
                    $stmtUpdate = $pdo->prepare("UPDATE $dbName.factura_ventas SET tipo_documento = 3, timbrado = :timbrado WHERE id_factura = :id");
                    $stmtUpdate->execute([':timbrado' => $timbrado, ':id' => $idFactura]);
                } catch (Exception $e) {
                    $stmtUpdate = $pdo->prepare("UPDATE $dbName.factura_ventas SET tipo_documento = 3 WHERE id_factura = :id");
                    $stmtUpdate->execute([':id' => $idFactura]);
                }
            }
        }
    }

    // Llamar a la librería de emisión
    $fastPrint = isset($_POST['fast_print']) && $_POST['fast_print'] == '1';
    $result = emitirFacturaElectronica($idFactura, $pdo, $dbName, $idEmpresa, [
        'fast_print' => $fastPrint
    ]);
    $ruidoSalida = trim((string)ob_get_clean());
    if ($ruidoSalida !== '') {
        error_log('SIFEN_RETRY output descartado: ' . substr($ruidoSalida, 0, 600));
    }

    echo json_encode($result);
} catch (Exception $e) {
    $ruidoSalida = trim((string)ob_get_clean());
    if ($ruidoSalida !== '') {
        error_log('SIFEN_RETRY output descartado en error: ' . substr($ruidoSalida, 0, 600));
    }
    error_log('SIFEN_RETRY ERROR: ' . $e->getMessage() . ' @ ' . basename((string)$e->getFile()) . ':' . (int)$e->getLine());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() . ' (Origen: ' . basename((string)$e->getFile()) . ':' . (int)$e->getLine() . ')'
    ]);
} finally {
    restore_error_handler();
}
