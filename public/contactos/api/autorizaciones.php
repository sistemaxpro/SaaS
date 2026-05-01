<?php
require_once __DIR__ . '/../../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);

Session::requireLogin();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$idEmpresa = (int)($_GET['id_empresa'] ?? $_POST['id_empresa'] ?? Session::getIdEmpresa());
$idLogin = (int)Session::getIdLogin();
$loginUsuario = trim((string)(Session::get('username') ?? Session::get('login') ?? $idLogin));

function contactosAuthIsAdmin(PDO $pdo, string $masterDb, int $idLogin, int $idEmpresa): bool
{
    $st = $pdo->prepare("
        SELECT priv_admin, role
        FROM {$masterDb}.sec_users
        WHERE id_login = :id_login
          AND id_empresa = :id_empresa
        LIMIT 1
    ");
    $st->execute([':id_login' => $idLogin, ':id_empresa' => $idEmpresa]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$u) {
        $st = $pdo->prepare("
            SELECT priv_admin, role
            FROM {$masterDb}.sec_users
            WHERE id_login = :id_login
            LIMIT 1
        ");
        $st->execute([':id_login' => $idLogin]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$u) {
        return false;
    }
    $priv = strtoupper(trim((string)($u['priv_admin'] ?? 'N')));
    $role = strtoupper(trim((string)($u['role'] ?? '')));
    return in_array($priv, ['Y', 'S', '1', 'TRUE'], true)
        || in_array($role, ['ADMIN', 'SUPERADMIN', 'ROOT', 'SUPERVISOR'], true)
        || strpos($role, 'ADMIN') !== false
        || strpos($role, 'SUPERVIS') !== false;
}

function contactosAuthEnsureUserNotificationsTable(PDO $pdo, string $masterDb): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$masterDb}.smx_user_notifications (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            id_empresa INT NOT NULL DEFAULT 0,
            id_dest_login INT NOT NULL,
            id_remitente_login INT NOT NULL DEFAULT 0,
            tipo VARCHAR(20) NOT NULL DEFAULT 'info',
            titulo VARCHAR(150) NOT NULL,
            mensaje TEXT NOT NULL,
            url VARCHAR(255) NULL,
            leida TINYINT(1) NOT NULL DEFAULT 0,
            fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            fecha_lectura DATETIME NULL,
            INDEX idx_dest_fecha (id_dest_login, fecha_creacion),
            INDEX idx_dest_leida (id_dest_login, leida),
            INDEX idx_empresa_dest (id_empresa, id_dest_login)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function contactosAuthNotifyRequester(
    PDO $pdo,
    string $masterDb,
    array $requestRow,
    int $authorizerId,
    string $authorizerLogin,
    string $decision
): void {
    $destLoginId = (int)($requestRow['requested_by_login_id'] ?? 0);
    if ($destLoginId <= 0) {
        return;
    }

    contactosAuthEnsureUserNotificationsTable($pdo, $masterDb);

    $accion = (string)($requestRow['accion'] ?? 'anular');
    $recordId = (int)($requestRow['record_id'] ?? 0);
    $empresaId = (int)($requestRow['id_empresa'] ?? 0);
    $approved = ($decision === 'approve');
    $accionLabel = $accion === 'desanular' ? 'desanulación' : 'anulación';
    $titulo = $approved
        ? 'Resultado de solicitud de ' . $accionLabel
        : 'Solicitud de ' . $accionLabel . ' rechazada';
    $mensaje = $approved
        ? sprintf(
            '%s aprobó tu solicitud de %s del registro #%d.',
            $authorizerLogin !== '' ? $authorizerLogin : ('Usuario #' . $authorizerId),
            $accionLabel,
            $recordId
        )
        : sprintf(
            '%s rechazó tu solicitud de %s del registro #%d.',
            $authorizerLogin !== '' ? $authorizerLogin : ('Usuario #' . $authorizerId),
            $accionLabel,
            $recordId
        );

    $recent = $pdo->prepare("
        SELECT id
        FROM {$masterDb}.smx_user_notifications
        WHERE id_empresa = :id_empresa
          AND id_dest_login = :id_dest_login
          AND id_remitente_login = :id_remitente_login
          AND titulo = :titulo
          AND mensaje = :mensaje
          AND fecha_creacion >= (NOW() - INTERVAL 2 MINUTE)
        LIMIT 1
    ");
    $recent->execute([
        ':id_empresa' => $empresaId,
        ':id_dest_login' => $destLoginId,
        ':id_remitente_login' => $authorizerId,
        ':titulo' => $titulo,
        ':mensaje' => $mensaje,
    ]);
    if ($recent->fetchColumn()) {
        return;
    }

    $insert = $pdo->prepare("
        INSERT INTO {$masterDb}.smx_user_notifications
        (id_empresa, id_dest_login, id_remitente_login, tipo, titulo, mensaje, url, leida, fecha_creacion)
        VALUES
        (:id_empresa, :id_dest_login, :id_remitente_login, :tipo, :titulo, :mensaje, :url, 0, NOW())
    ");
    $insert->execute([
        ':id_empresa' => $empresaId,
        ':id_dest_login' => $destLoginId,
        ':id_remitente_login' => $authorizerId,
        ':tipo' => $approved ? 'success' : 'warning',
        ':titulo' => $titulo,
        ':mensaje' => $mensaje,
        ':url' => '/public/contactos/index.php',
    ]);
}

try {
    $pdo = Database::getMasterConnection();
    $masterDb = Database::getMasterDbName();

    if (!contactosAuthIsAdmin($pdo, $masterDb, $idLogin, $idEmpresa)) {
        if (in_array($action, ['list', 'decide'], true)) {
            http_response_code(200);
        } else {
            http_response_code(403);
        }
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$masterDb}.smx_kardex_auth_requests (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            id_empresa INT NOT NULL DEFAULT 0,
            modulo VARCHAR(50) NOT NULL DEFAULT 'contactos_kardex',
            tabla VARCHAR(80) NOT NULL DEFAULT 'extracto_cliente',
            record_id BIGINT NOT NULL,
            accion VARCHAR(20) NOT NULL DEFAULT 'anular',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            requested_by_login_id INT NOT NULL DEFAULT 0,
            requested_by_login VARCHAR(120) NULL,
            authorized_by_login_id INT NULL,
            authorized_by_login VARCHAR(120) NULL,
            titulo VARCHAR(150) NULL,
            mensaje TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            INDEX idx_empresa_record (id_empresa, record_id),
            INDEX idx_status (status),
            INDEX idx_modulo_tabla (modulo, tabla)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if ($action === 'list') {
        $status = strtolower(trim((string)($_GET['status'] ?? 'pending')));
        $limit = max(20, min(500, (int)($_GET['limit'] ?? 200)));

        $sql = "
            SELECT *
            FROM {$masterDb}.smx_kardex_auth_requests
            WHERE id_empresa = :id_empresa
              AND modulo = 'contactos_kardex'
              AND tabla = 'extracto_cliente'
        ";
        $params = [':id_empresa' => $idEmpresa];
        if ($status !== 'all') {
            $sql .= " AND status = :status";
            $params[':status'] = $status;
        }
        $sql .= " ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit;

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtDb = $pdo->prepare("SELECT dbase, server, user FROM {$masterDb}.empresa WHERE id_empresa = :id LIMIT 1");
        $stmtDb->execute([':id' => $idEmpresa]);
        $empresaRow = $stmtDb->fetch(PDO::FETCH_ASSOC) ?: null;
        $dbName = (string)($empresaRow['dbase'] ?? '');

        $extractoById = [];
        if ($dbName !== '' && !empty($rows)) {
            $ids = array_values(array_unique(array_map(static fn($r) => (int)($r['record_id'] ?? 0), $rows)));
            $ids = array_values(array_filter($ids, static fn($v) => $v > 0));
            if ($ids) {
                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s;charset=utf8mb4',
                    (string)($empresaRow['server'] ?? 'localhost'),
                    $dbName
                );
                $pdoEmpresa = new PDO(
                    $dsn,
                    (string)($empresaRow['user'] ?? 'sistemax'),
                    'Armagedon123',
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stExt = $pdoEmpresa->prepare("
                    SELECT id, fecha, concepto, estado
                    FROM {$dbName}.extracto_cliente
                    WHERE id IN ({$ph})
                ");
                $stExt->execute($ids);
                foreach (($stExt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                    $extractoById[(int)$row['id']] = $row;
                }
            }
        }

        echo json_encode([
            'success' => true,
            'items' => array_map(static function (array $row) use ($extractoById) {
                $recordId = (int)($row['record_id'] ?? 0);
                $ext = $extractoById[$recordId] ?? [];
                $row['extracto_fecha'] = (string)($ext['fecha'] ?? '');
                $row['extracto_concepto'] = (string)($ext['concepto'] ?? '');
                $row['extracto_estado'] = (string)($ext['estado'] ?? '');
                return $row;
            }, $rows)
        ]);
        exit;
    }

    if ($action === 'decide') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (int)($input['id'] ?? 0);
        $decision = strtolower(trim((string)($input['decision'] ?? '')));
        if ($id <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
            throw new Exception('Parámetros inválidos');
        }

        $stReq = $pdo->prepare("
            SELECT *
            FROM {$masterDb}.smx_kardex_auth_requests
            WHERE id = :id
              AND id_empresa = :id_empresa
              AND modulo = 'contactos_kardex'
              AND tabla = 'extracto_cliente'
            LIMIT 1
        ");
        $stReq->execute([':id' => $id, ':id_empresa' => $idEmpresa]);
        $req = $stReq->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$req) {
            throw new Exception('Solicitud no encontrada');
        }
        if ((string)($req['status'] ?? '') !== 'pending') {
            echo json_encode(['success' => false, 'message' => 'La solicitud ya fue procesada']);
            exit;
        }

        $stmtDb = $pdo->prepare("SELECT dbase, server, user FROM {$masterDb}.empresa WHERE id_empresa = :id LIMIT 1");
        $stmtDb->execute([':id' => $idEmpresa]);
        $empresaRow = $stmtDb->fetch(PDO::FETCH_ASSOC) ?: null;
        $dbName = (string)($empresaRow['dbase'] ?? '');
        if ($dbName === '') {
            throw new Exception('Empresa no encontrada');
        }

        $pdoEmpresa = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', (string)($empresaRow['server'] ?? 'localhost'), $dbName),
            (string)($empresaRow['user'] ?? 'sistemax'),
            'Armagedon123',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        $pdo->beginTransaction();
        try {
            if ($decision === 'approve') {
                $nuevoEstado = ((string)($req['accion'] ?? 'anular') === 'desanular') ? 0 : 1;
                $stUpdExtracto = $pdoEmpresa->prepare("UPDATE {$dbName}.extracto_cliente SET estado = :estado WHERE id = :id");
                $stUpdExtracto->execute([
                    ':estado' => $nuevoEstado,
                    ':id' => (int)$req['record_id'],
                ]);
            }

            $newStatus = $decision === 'approve' ? 'authorized' : 'rejected';
            $stUpd = $pdo->prepare("
                UPDATE {$masterDb}.smx_kardex_auth_requests
                SET status = :status,
                    authorized_by_login_id = :auth_id,
                    authorized_by_login = :auth_login,
                    resolved_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stUpd->execute([
                ':status' => $newStatus,
                ':auth_id' => $idLogin,
                ':auth_login' => $loginUsuario,
                ':id' => $id,
            ]);
            contactosAuthNotifyRequester($pdo, $masterDb, $req, $idLogin, $loginUsuario, $decision);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        echo json_encode([
            'success' => true,
            'message' => $decision === 'approve' ? 'Solicitud autorizada' : 'Solicitud rechazada'
        ]);
        exit;
    }

    throw new Exception('Acción no soportada');
} catch (Throwable $e) {
    if (in_array($action, ['decide', 'list'], true)) {
        http_response_code(200);
    } else {
        http_response_code(400);
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
