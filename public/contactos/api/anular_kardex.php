<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../config/db_config.php';

Permission::requirePermission('app_grid_clientes', 'priv_delete');

function contactosResolveAdminLike(PDO $pdoMaster, int $idLogin, int $idEmpresa): bool
{
    if ($idLogin <= 0) {
        return false;
    }
    $userRow = null;
    try {
        $stmtCol = $pdoMaster->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = :db
              AND table_name = 'sec_users'
              AND column_name = 'id_empresa'
        ");
        $stmtCol->execute([':db' => MASTER_DB]);
        $hasEmpresaColumn = ((int)$stmtCol->fetchColumn()) > 0;
        if ($hasEmpresaColumn) {
            $stmt = $pdoMaster->prepare("
                SELECT priv_admin, role
                FROM " . MASTER_DB . ".sec_users
                WHERE id_login = :id_login
                  AND id_empresa = :id_empresa
                LIMIT 1
            ");
            $stmt->execute([':id_login' => $idLogin, ':id_empresa' => $idEmpresa]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (Throwable $e) {
        $userRow = null;
    }

    if (!$userRow) {
        $stmt = $pdoMaster->prepare("
            SELECT priv_admin, role
            FROM " . MASTER_DB . ".sec_users
            WHERE id_login = :id_login
            LIMIT 1
        ");
        $stmt->execute([':id_login' => $idLogin]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$userRow) {
        return false;
    }

    $priv = strtoupper(trim((string)($userRow['priv_admin'] ?? 'N')));
    $role = strtoupper(trim((string)($userRow['role'] ?? '')));
    $hasAdminPriv = in_array($priv, ['Y', 'S', '1', 'TRUE'], true);
    $hasAdminRole = in_array($role, ['ADMIN', 'SUPERADMIN', 'ROOT', 'SUPERVISOR'], true)
        || strpos($role, 'ADMIN') !== false
        || strpos($role, 'SUPERVIS') !== false;

    return $hasAdminPriv || $hasAdminRole;
}

function contactosEnsureUserNotificationsTable(PDO $pdoMaster): void
{
    $pdoMaster->exec("
        CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_user_notifications (
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

function contactosEnsureKardexAuthRequestsTable(PDO $pdoMaster): void
{
    $pdoMaster->exec("
        CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_kardex_auth_requests (
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
}

function contactosNotifyAdmins(PDO $pdoMaster, int $idEmpresa, int $idRemitenteLogin, string $titulo, string $mensaje, ?string $url = null): int
{
    contactosEnsureUserNotificationsTable($pdoMaster);

    $stmt = $pdoMaster->prepare("
        SELECT id_login, priv_admin, role
        FROM " . MASTER_DB . ".sec_users
        WHERE id_empresa = :id_empresa
          AND COALESCE(active, 'Y') = 'Y'
    ");
    $stmt->execute([':id_empresa' => $idEmpresa]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        return 0;
    }

    $insert = $pdoMaster->prepare("
        INSERT INTO " . MASTER_DB . ".smx_user_notifications
        (id_empresa, id_dest_login, id_remitente_login, tipo, titulo, mensaje, url, leida, fecha_creacion)
        VALUES
        (:id_empresa, :id_dest_login, :id_remitente_login, 'warning', :titulo, :mensaje, :url, 0, NOW())
    ");
    $recent = $pdoMaster->prepare("
        SELECT id
        FROM " . MASTER_DB . ".smx_user_notifications
        WHERE id_empresa = :id_empresa
          AND id_dest_login = :id_dest_login
          AND id_remitente_login = :id_remitente_login
          AND titulo = :titulo
          AND mensaje = :mensaje
          AND COALESCE(url, '') = COALESCE(:url, '')
          AND fecha_creacion >= (NOW() - INTERVAL 2 MINUTE)
        LIMIT 1
    ");

    $sent = 0;
    foreach ($rows as $row) {
        $dest = (int)($row['id_login'] ?? 0);
        if ($dest <= 0) {
            continue;
        }
        $priv = strtoupper(trim((string)($row['priv_admin'] ?? 'N')));
        $role = strtoupper(trim((string)($row['role'] ?? '')));
        $hasAdminPriv = in_array($priv, ['Y', 'S', '1', 'TRUE'], true);
        $hasAdminRole = in_array($role, ['ADMIN', 'SUPERADMIN', 'ROOT', 'SUPERVISOR'], true)
            || strpos($role, 'ADMIN') !== false
            || strpos($role, 'SUPERVIS') !== false;
        if (!$hasAdminPriv && !$hasAdminRole) {
            continue;
        }

        $recent->execute([
            ':id_empresa' => $idEmpresa,
            ':id_dest_login' => $dest,
            ':id_remitente_login' => $idRemitenteLogin,
            ':titulo' => $titulo,
            ':mensaje' => $mensaje,
            ':url' => $url,
        ]);
        if ($recent->fetchColumn()) {
            continue;
        }

        $insert->execute([
            ':id_empresa' => $idEmpresa,
            ':id_dest_login' => $dest,
            ':id_remitente_login' => $idRemitenteLogin,
            ':titulo' => $titulo,
            ':mensaje' => $mensaje,
            ':url' => $url,
        ]);
        $sent++;
    }

    return $sent;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON inválido']);
    exit;
}

$id_empresa = (int)($input['id_empresa'] ?? $_SESSION['id_empresa'] ?? 169);
$rowId = (int)($input['id'] ?? 0);
$tipo = trim((string)($input['tipo'] ?? 'movimiento'));
$accion = trim((string)($input['accion'] ?? 'anular'));

if ($rowId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de registro requerido']);
    exit;
}

if ($tipo !== 'movimiento') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Solo se puede anular movimientos del estado de cuenta desde esta vista']);
    exit;
}

if (!in_array($accion, ['anular', 'desanular'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Acción inválida']);
    exit;
}

try {
    $master = Database::getMasterConnection();
    contactosEnsureKardexAuthRequestsTable($master);
    $conn = getEmpresaConnection($id_empresa);
    $pdo = $conn['pdo'];
    $db = $conn['dbName'];

    $checkStmt = $pdo->prepare("
        SELECT
            id,
            COALESCE(fecha, '') AS fecha,
            COALESCE(concepto, '') AS concepto,
            COALESCE(estado, 0) AS estado
        FROM {$db}.extracto_cliente
        WHERE id = :id
        LIMIT 1
    ");
    $checkStmt->execute([':id' => $rowId]);
    $row = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Registro no encontrado']);
        exit;
    }

    $estadoActual = (int)($row['estado'] ?? 0);
    if ($accion === 'anular' && $estadoActual !== 0) {
        echo json_encode(['success' => false, 'error' => 'El registro ya estaba anulado']);
        exit;
    }
    if ($accion === 'desanular' && $estadoActual !== 1) {
        echo json_encode(['success' => false, 'error' => 'El registro ya estaba activo']);
        exit;
    }

    $requesterId = (int)($_SESSION['id_login'] ?? 0);
    $requesterLogin = trim((string)($_SESSION['login'] ?? $_SESSION['usuario'] ?? 'Usuario'));
    $empresaStmt = $master->prepare("SELECT empresa FROM " . MASTER_DB . ".empresa WHERE id_empresa = :id LIMIT 1");
    $empresaStmt->execute([':id' => $id_empresa]);
    $empresaNombre = (string)($empresaStmt->fetchColumn() ?: ('Empresa #' . $id_empresa));

    $titulo = $accion === 'desanular'
        ? 'Solicitud de desanulacion de registro'
        : 'Solicitud de anulacion de registro';
    $mensaje = sprintf(
        '%s · %s solicito %s el registro #%d del estado de cuenta. Fecha: %s. Concepto: %s.',
        $empresaNombre,
        $requesterLogin !== '' ? $requesterLogin : 'Usuario',
        $accion === 'desanular' ? 'desanular' : 'anular',
        $rowId,
        trim((string)($row['fecha'] ?? '')) !== '' ? (string)$row['fecha'] : '-',
        trim((string)($row['concepto'] ?? '')) !== '' ? (string)$row['concepto'] : '-'
    );
    $url = '/public/contactos/autorizaciones.php';
    $pendingStmt = $master->prepare("
        SELECT id
        FROM " . MASTER_DB . ".smx_kardex_auth_requests
        WHERE id_empresa = :id_empresa
          AND modulo = 'contactos_kardex'
          AND tabla = 'extracto_cliente'
          AND record_id = :record_id
          AND accion = :accion
          AND status = 'pending'
        ORDER BY id DESC
        LIMIT 1
    ");
    $pendingStmt->execute([
        ':id_empresa' => $id_empresa,
        ':record_id' => $rowId,
        ':accion' => $accion,
    ]);
    $existingPendingId = (int)($pendingStmt->fetchColumn() ?: 0);

    if ($existingPendingId <= 0) {
        $insertReq = $master->prepare("
            INSERT INTO " . MASTER_DB . ".smx_kardex_auth_requests
            (id_empresa, modulo, tabla, record_id, accion, status, requested_by_login_id, requested_by_login, titulo, mensaje, created_at, updated_at)
            VALUES
            (:id_empresa, 'contactos_kardex', 'extracto_cliente', :record_id, :accion, 'pending', :requested_by_login_id, :requested_by_login, :titulo, :mensaje, NOW(), NOW())
        ");
        $insertReq->execute([
            ':id_empresa' => $id_empresa,
            ':record_id' => $rowId,
            ':accion' => $accion,
            ':requested_by_login_id' => $requesterId,
            ':requested_by_login' => $requesterLogin,
            ':titulo' => $titulo,
            ':mensaje' => $mensaje,
        ]);
    }

    $sent = contactosNotifyAdmins($master, $id_empresa, $requesterId, $titulo, $mensaje, $url);

    echo json_encode([
        'success' => true,
        'requested' => true,
        'notified' => $sent,
        'request_status' => 'pending',
        'message' => $sent > 0
            ? 'Solicitud enviada al centro de notificaciones para supervisor o administrador'
            : 'No se encontraron supervisores o administradores activos para notificar'
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
