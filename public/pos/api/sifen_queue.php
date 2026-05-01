<?php
/**
 * Cola FE asíncrona para POS.
 */

if (!defined('SISTEMAX_V1')) {
    require_once __DIR__ . '/../config/db_config.php';
}

require_once __DIR__ . '/sifen_lib.php';

function sifenQueueEnsureTable(PDO $pdoMaster): void
{
    $sql = "CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".fe_queue (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_empresa INT NOT NULL,
        db_name VARCHAR(64) NOT NULL,
        id_factura INT NOT NULL,
        accion ENUM('emitir','consultar') NOT NULL DEFAULT 'emitir',
        estado ENUM('pendiente','procesando','resuelto','fallido') NOT NULL DEFAULT 'pendiente',
        prioridad TINYINT NOT NULL DEFAULT 5,
        intentos INT NOT NULL DEFAULT 0,
        max_intentos INT NOT NULL DEFAULT 8,
        next_retry_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_error VARCHAR(255) NULL,
        last_message VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_pick (estado, next_retry_at, prioridad, id),
        KEY idx_empresa_factura (id_empresa, id_factura),
        KEY idx_estado_accion (estado, accion)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdoMaster->exec($sql);
}

function sifenQueueBackoffSeconds(int $attempt): int
{
    if ($attempt <= 1) return 10;
    if ($attempt === 2) return 30;
    if ($attempt === 3) return 60;
    if ($attempt === 4) return 300;
    if ($attempt === 5) return 900;
    return 1800;
}

function sifenQueueEnqueue(PDO $pdoMaster, int $idEmpresa, string $dbName, int $idFactura, string $accion = 'emitir', int $prioridad = 5): array
{
    sifenQueueEnsureTable($pdoMaster);

    $accion = ($accion === 'consultar') ? 'consultar' : 'emitir';

    $stmtExists = $pdoMaster->prepare("SELECT id, estado, accion
        FROM " . MASTER_DB . ".fe_queue
        WHERE id_empresa = :emp AND id_factura = :fac AND accion = :acc
          AND estado IN ('pendiente', 'procesando')
        ORDER BY id DESC
        LIMIT 1");
    $stmtExists->execute([
        ':emp' => $idEmpresa,
        ':fac' => $idFactura,
        ':acc' => $accion
    ]);
    $exists = $stmtExists->fetch(PDO::FETCH_ASSOC);
    if ($exists) {
        return [
            'queued' => true,
            'queue_id' => (int)$exists['id'],
            'already_queued' => true
        ];
    }

    $stmtIns = $pdoMaster->prepare("INSERT INTO " . MASTER_DB . ".fe_queue
        (id_empresa, db_name, id_factura, accion, estado, prioridad, intentos, max_intentos, next_retry_at)
        VALUES (:emp, :db, :fac, :acc, 'pendiente', :prio, 0, 8, NOW())");
    $stmtIns->execute([
        ':emp' => $idEmpresa,
        ':db' => $dbName,
        ':fac' => $idFactura,
        ':acc' => $accion,
        ':prio' => $prioridad
    ]);

    return [
        'queued' => true,
        'queue_id' => (int)$pdoMaster->lastInsertId(),
        'already_queued' => false
    ];
}

function sifenQueueUpdateFeState(PDO $pdoEmpresa, string $dbName, int $idFactura, string $estado, string $mensaje = '', string $xml = ''): void
{
    $tx = 'PENDIENTE';
    if ($estado === 'Aprobado') $tx = 'ENVIADO';
    if ($estado === 'Rechazado') $tx = 'RECHAZADO';
    if ($estado === 'Error Envío') $tx = 'ERROR';
    if ($estado === 'Guardado Local') $tx = 'LOCAL';
    if ($estado === 'Enviando') $tx = 'ENVIANDO';

    try {
        $stmt = $pdoEmpresa->prepare("UPDATE $dbName.fe
            SET estado_transmision = :tx,
                estado_electronico = :est,
                mensaje_set = :msg,
                acuse_sifen = CASE WHEN :xml_r = '' THEN acuse_sifen ELSE :xml_r END,
                fecha_transmision = NOW()
            WHERE id_factura = :id");
        $stmt->execute([
            ':tx' => $tx,
            ':est' => $estado,
            ':msg' => substr($mensaje, 0, 255),
            ':xml_r' => $xml,
            ':id' => $idFactura
        ]);
    } catch (Throwable $e) {
        // No bloquear cola si tabla fe no existe o falla.
    }
}

function sifenQueueConsultarEstado(PDO $pdoEmpresa, string $dbName, int $idEmpresa): array
{
    $idFactura = (int)($_POST['_queue_id_factura'] ?? 0);
    if ($idFactura <= 0) {
        throw new Exception('ID factura inválido para consulta de estado');
    }

    $stmtFact = $pdoEmpresa->prepare("SELECT id_factura, cdc, prot_cons_lote_sifen FROM $dbName.factura_ventas WHERE id_factura = :id LIMIT 1");
    $stmtFact->execute([':id' => $idFactura]);
    $fact = $stmtFact->fetch(PDO::FETCH_ASSOC);
    if (!$fact) {
        throw new Exception('Factura no encontrada para consulta de estado');
    }

    $protLote = trim((string)($fact['prot_cons_lote_sifen'] ?? ''));
    $cdc = trim((string)($fact['cdc'] ?? ''));

    $pdoMaster = getMasterConnection();
    $stmtHab = $pdoMaster->prepare("SELECT ambiente, cert_path, cert_nombre, cert_pass, csc, id_csc
        FROM " . MASTER_DB . ".habilitacion_sifen
        WHERE id_empresa = :id AND activo = 1
        ORDER BY id DESC
        LIMIT 1");
    $stmtHab->execute([':id' => $idEmpresa]);
    $hab = $stmtHab->fetch(PDO::FETCH_ASSOC);
    if (!$hab) {
        throw new Exception('No se encontró habilitación SIFEN activa para la empresa');
    }

    $ambienteRaw = strtoupper(trim((string)($hab['ambiente'] ?? 'TEST')));
    $ambiente = ($ambienteRaw === 'PROD' || $ambienteRaw === '1') ? 'prod' : 'test';

    $certificado = '';
    if (!empty($hab['cert_path'])) $certificado = basename((string)$hab['cert_path']);
    if ($certificado === '' && !empty($hab['cert_nombre'])) $certificado = basename((string)$hab['cert_nombre']);
    if ($certificado === '') throw new Exception('Certificado SIFEN no definido en habilitación');

    $certPath = dirname(__DIR__, 2) . '/_lib/php-sifen3/certificados/' . $certificado;
    if (!is_file($certPath)) throw new Exception("Certificado no encontrado: {$certPath}");
    $certPass = (string)($hab['cert_pass'] ?? '');
    if ($certPass === '') throw new Exception('Contraseña de certificado no configurada');

    $key = new \sifen\KEY($certPath, $certPass);
    $sifen = new \sifen\Sifen($ambiente, $key);
    if (!empty($hab['csc']) && !empty($hab['id_csc']) && method_exists($sifen, 'setCSCid')) {
        $sifen->setCSCid((string)$hab['id_csc'], (string)$hab['csc']);
    }

    $parse = function ($payload, string $fallbackMsg = ''): array {
        $toText = function ($v): string {
            if (is_string($v)) return $v;
            if (is_scalar($v) || $v === null) return (string)$v;
            $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($j) ? $j : '';
        };

        $extract = function (string $xml, array $tags): string {
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

        $xml = '';
        $msg = '';
        $est = '';
        $cod = '';
        if (is_array($payload)) {
            $xml = $toText($payload['response'] ?? $payload['xml'] ?? '');
            $msg = $toText($payload['dMsgRes'] ?? $payload['dMsgResLot'] ?? '');
        } else {
            $xml = $toText($payload);
        }
        if ($xml !== '') {
            if ($msg === '') $msg = $extract($xml, ['dMsgRes', 'dMsgResLot', 'dDesMotivoRechazo', 'xMotivoRechazo']);
            $est = strtoupper($extract($xml, ['dEstRes']));
            $cod = strtoupper($extract($xml, ['dCodRes']));
        }
        if ($msg === '') $msg = $fallbackMsg;
        $msgU = strtoupper($msg);

        $isAprob = strpos($msgU, 'APROBADO') !== false
            || in_array($est, ['APROBADO', '1', '001', '100'], true)
            || in_array($cod, ['APROBADO', '001', '100'], true);
        $isRech = strpos($msgU, 'RECHAZ') !== false
            || strpos($msgU, 'DENEG') !== false
            || strpos($msgU, 'INVALID') !== false
            || in_array($est, ['RECHAZADO', '2', '002', '200'], true)
            || in_array($cod, ['RECHAZADO', '0500', '500'], true);

        if ($isAprob) return ['Aprobado', $msg !== '' ? $msg : 'Aprobado por SIFEN', $xml];
        if ($isRech) return ['Rechazado', $msg !== '' ? $msg : 'Rechazado por SIFEN', $xml];
        return ['Pendiente', $msg !== '' ? $msg : 'SIFEN aún no confirmó la aprobación del documento', $xml];
    };

    $estado = 'Pendiente';
    $mensaje = '';
    $xml = '';

    if ($protLote !== '') {
        $resLote = $sifen->queryLote($protLote);
        [$estado, $mensaje, $xml] = $parse($resLote, 'Consulta de lote realizada');
    }

    if ($estado === 'Pendiente' && $cdc !== '' && method_exists($sifen, 'siConsDE')) {
        $resDe = $sifen->siConsDE($cdc);
        [$estadoDe, $mensajeDe, $xmlDe] = $parse($resDe, $mensaje);
        if ($estadoDe === 'Aprobado' || $estadoDe === 'Rechazado') {
            $estado = $estadoDe;
            $mensaje = $mensajeDe;
            $xml = $xmlDe;
        } elseif ($mensaje === '' && $mensajeDe !== '') {
            $mensaje = $mensajeDe;
            $xml = $xmlDe;
        }
    }

    $hasFechaUlt = false;
    try {
        $cols = $pdoEmpresa->query("SHOW COLUMNS FROM $dbName.factura_ventas LIKE 'fecha_ult_consulta_sifen'")->fetchAll(PDO::FETCH_ASSOC);
        $hasFechaUlt = !empty($cols);
    } catch (Throwable $e) {
        $hasFechaUlt = false;
    }

    $sqlUpd = "UPDATE $dbName.factura_ventas
        SET estado_sifen = :est,
            mensaje_sifen = :msg,
            xml_respuesta = CASE WHEN :xml_r = '' THEN xml_respuesta ELSE :xml_r END,
            fecha_envio_sifen = NOW()";
    if ($hasFechaUlt) {
        $sqlUpd .= ", fecha_ult_consulta_sifen = NOW()";
    }
    $sqlUpd .= " WHERE id_factura = :id";

    $stmtUpd = $pdoEmpresa->prepare($sqlUpd);
    $stmtUpd->execute([
        ':est' => $estado,
        ':msg' => substr($mensaje, 0, 255),
        ':xml_r' => $xml,
        ':id' => $idFactura
    ]);

    sifenQueueUpdateFeState($pdoEmpresa, $dbName, $idFactura, $estado, $mensaje, $xml);

    return [
        'success' => ($estado === 'Aprobado'),
        'estado' => $estado,
        'message' => $mensaje
    ];
}

function sifenQueueProcessOne(PDO $pdoMaster, ?int $queueId = null): array
{
    sifenQueueEnsureTable($pdoMaster);

    $params = [];
    $sqlPick = "SELECT *
        FROM " . MASTER_DB . ".fe_queue
        WHERE estado = 'pendiente' AND next_retry_at <= NOW()";
    if ($queueId !== null) {
        $sqlPick .= " AND id = :id";
        $params[':id'] = $queueId;
    }
    $sqlPick .= " ORDER BY prioridad ASC, id ASC LIMIT 1";

    $stmtPick = $pdoMaster->prepare($sqlPick);
    $stmtPick->execute($params);
    $row = $stmtPick->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['success' => true, 'processed' => false, 'message' => 'Sin pendientes'];
    }

    $id = (int)$row['id'];
    $idEmpresa = (int)$row['id_empresa'];
    $dbName = (string)$row['db_name'];
    $idFactura = (int)$row['id_factura'];
    $accion = (string)$row['accion'];
    $intentos = (int)$row['intentos'];
    $maxIntentos = (int)$row['max_intentos'];

    $stmtLock = $pdoMaster->prepare("UPDATE " . MASTER_DB . ".fe_queue
        SET estado = 'procesando', updated_at = NOW()
        WHERE id = :id AND estado = 'pendiente'");
    $stmtLock->execute([':id' => $id]);
    if ($stmtLock->rowCount() === 0) {
        return ['success' => true, 'processed' => false, 'message' => 'Elemento tomado por otro worker'];
    }

    try {
        $conn = getEmpresaConnection($idEmpresa);
        $pdoEmpresa = $conn['pdo'];
        $dbNameEmpresa = (string)$conn['dbName'];
        if ($dbNameEmpresa !== $dbName) {
            $dbName = $dbNameEmpresa;
        }

        $result = [];
        if ($accion === 'consultar') {
            $_POST['_queue_id_factura'] = $idFactura;
            $result = sifenQueueConsultarEstado($pdoEmpresa, $dbName, $idEmpresa);
            unset($_POST['_queue_id_factura']);
        } else {
            $stmtMark = $pdoEmpresa->prepare("UPDATE $dbName.factura_ventas
                SET estado_sifen = 'Enviando',
                    mensaje_sifen = 'Enviando FE a SIFEN...'
                WHERE id_factura = :id");
            $stmtMark->execute([':id' => $idFactura]);
            sifenQueueUpdateFeState($pdoEmpresa, $dbName, $idFactura, 'Enviando', 'Enviando FE a SIFEN...');

            $result = emitirFacturaElectronica($idFactura, $pdoEmpresa, $dbName, $idEmpresa);
            if (($result['estado'] ?? '') === 'Pendiente') {
                sifenQueueUpdateFeState($pdoEmpresa, $dbName, $idFactura, 'Pendiente', (string)($result['message'] ?? 'Pendiente'));
            }
        }

        $estadoFinal = (string)($result['estado'] ?? '');
        $message = substr((string)($result['message'] ?? $result['mensaje'] ?? ''), 0, 255);

        if ($estadoFinal === 'Aprobado' || $estadoFinal === 'Rechazado') {
            $stmtDone = $pdoMaster->prepare("UPDATE " . MASTER_DB . ".fe_queue
                SET estado = 'resuelto',
                    last_message = :msg,
                    updated_at = NOW()
                WHERE id = :id");
            $stmtDone->execute([':msg' => $message, ':id' => $id]);
        } else {
            $newIntentos = $intentos + 1;
            if ($newIntentos >= $maxIntentos) {
                $stmtFail = $pdoMaster->prepare("UPDATE " . MASTER_DB . ".fe_queue
                    SET estado = 'fallido',
                        intentos = :it,
                        last_error = :err,
                        updated_at = NOW()
                    WHERE id = :id");
                $stmtFail->execute([
                    ':it' => $newIntentos,
                    ':err' => substr(($message !== '' ? $message : 'Máximo de intentos alcanzado'), 0, 255),
                    ':id' => $id
                ]);
            } else {
                $next = sifenQueueBackoffSeconds($newIntentos);
                $nextEstado = 'pendiente';
                $nextAccion = ($accion === 'emitir') ? 'consultar' : 'consultar';
                $stmtRetry = $pdoMaster->prepare("UPDATE " . MASTER_DB . ".fe_queue
                    SET estado = :st,
                        accion = :acc,
                        intentos = :it,
                        next_retry_at = DATE_ADD(NOW(), INTERVAL :sec SECOND),
                        last_message = :msg,
                        updated_at = NOW()
                    WHERE id = :id");
                $stmtRetry->execute([
                    ':st' => $nextEstado,
                    ':acc' => $nextAccion,
                    ':it' => $newIntentos,
                    ':sec' => $next,
                    ':msg' => $message,
                    ':id' => $id
                ]);
            }
        }

        return [
            'success' => true,
            'processed' => true,
            'queue_id' => $id,
            'id_factura' => $idFactura,
            'estado' => $estadoFinal !== '' ? $estadoFinal : 'Pendiente',
            'message' => $message
        ];
    } catch (Throwable $e) {
        $newIntentos = $intentos + 1;
        if ($newIntentos >= $maxIntentos) {
            $stmtFail = $pdoMaster->prepare("UPDATE " . MASTER_DB . ".fe_queue
                SET estado = 'fallido',
                    intentos = :it,
                    last_error = :err,
                    updated_at = NOW()
                WHERE id = :id");
            $stmtFail->execute([
                ':it' => $newIntentos,
                ':err' => substr($e->getMessage(), 0, 255),
                ':id' => $id
            ]);
        } else {
            $next = sifenQueueBackoffSeconds($newIntentos);
            $stmtRetry = $pdoMaster->prepare("UPDATE " . MASTER_DB . ".fe_queue
                SET estado = 'pendiente',
                    intentos = :it,
                    next_retry_at = DATE_ADD(NOW(), INTERVAL :sec SECOND),
                    last_error = :err,
                    updated_at = NOW()
                WHERE id = :id");
            $stmtRetry->execute([
                ':it' => $newIntentos,
                ':sec' => $next,
                ':err' => substr($e->getMessage(), 0, 255),
                ':id' => $id
            ]);
        }

        return [
            'success' => false,
            'processed' => true,
            'queue_id' => $id,
            'id_factura' => $idFactura,
            'message' => $e->getMessage()
        ];
    }
}
