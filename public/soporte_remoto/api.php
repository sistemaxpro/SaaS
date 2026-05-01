<?php
require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
Session::requireLogin('/public/login.php');

const SX_REMOTE_SUPPORT_COMPANIES = SISTEMAX_SUPPORT_COMPANIES;
const SX_REMOTE_CLIENT_VERSION = '1773965784';

function sxrs_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sxrs_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = Database::getMasterConnection();
    return $pdo;
}

function sxrs_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_remote_support_sessions (
        id BIGINT NOT NULL AUTO_INCREMENT,
        token VARCHAR(96) NOT NULL,
        support_company_id INT NOT NULL,
        support_login_id INT NOT NULL,
        support_login VARCHAR(120) DEFAULT NULL,
        client_company_id INT NOT NULL,
        client_login_id INT DEFAULT NULL,
        client_login VARCHAR(120) DEFAULT NULL,
        title VARCHAR(180) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        join_code VARCHAR(16) DEFAULT NULL,
        accepted_at DATETIME DEFAULT NULL,
        ended_at DATETIME DEFAULT NULL,
        last_support_seen DATETIME DEFAULT NULL,
        last_client_seen DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_token (token),
        KEY idx_support_status (support_company_id, status, created_at),
        KEY idx_client_scope (client_company_id, client_login_id, status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_remote_support_events (
        id BIGINT NOT NULL AUTO_INCREMENT,
        session_id BIGINT NOT NULL,
        sender_role VARCHAR(12) NOT NULL,
        event_type VARCHAR(24) NOT NULL,
        payload_json LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_session_events (session_id, id),
        KEY idx_sender_role (sender_role, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

function sxrs_current_context(): array
{
    return [
        'id_empresa' => (int)Session::getIdEmpresa(),
        'id_login' => (int)Session::getIdLogin(),
        'login' => (string)($_SESSION['usuario'] ?? $_SESSION['login'] ?? ''),
        'name' => (string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? ($_SESSION['usuario'] ?? '')),
        'is_admin' => Session::isAdmin(),
    ];
}

function sxrs_is_support_company(int $idEmpresa): bool
{
    return in_array($idEmpresa, SX_REMOTE_SUPPORT_COMPANIES, true);
}

function sxrs_require_support_access(): array
{
    $ctx = sxrs_current_context();
    if (!sxrs_is_support_company($ctx['id_empresa'])) {
        sxrs_json(['ok' => false, 'error' => 'Acceso reservado a soporte técnico'], 403);
    }
    return $ctx;
}

function sxrs_request_json(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function sxrs_company_name(PDO $pdo, int $idEmpresa): string
{
    static $cache = [];
    if (isset($cache[$idEmpresa])) return $cache[$idEmpresa];
    $stmt = $pdo->prepare("SELECT empresa FROM " . MASTER_DB . ".empresa WHERE id_empresa = ? LIMIT 1");
    $stmt->execute([$idEmpresa]);
    $name = (string)($stmt->fetchColumn() ?: ('Empresa #' . $idEmpresa));
    $cache[$idEmpresa] = $name;
    return $name;
}

function sxrs_user_row(PDO $pdo, int $idEmpresa, ?int $idLogin): ?array
{
    if (!$idLogin) return null;
    $stmt = $pdo->prepare("SELECT id_login, login, name, active FROM " . MASTER_DB . ".sec_users WHERE id_empresa = ? AND id_login = ? LIMIT 1");
    $stmt->execute([$idEmpresa, $idLogin]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function sxrs_session_row(PDO $pdo, int $sessionId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM " . MASTER_DB . ".smx_remote_support_sessions WHERE id = ? LIMIT 1");
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function sxrs_format_session(PDO $pdo, array $row): array
{
    $supportUser = sxrs_user_row($pdo, (int)$row['support_company_id'], (int)$row['support_login_id']);
    $clientUser = sxrs_user_row($pdo, (int)$row['client_company_id'], isset($row['client_login_id']) ? (int)$row['client_login_id'] : null);
    return [
        'id' => (int)$row['id'],
        'token' => (string)$row['token'],
        'title' => (string)($row['title'] ?? ''),
        'status' => (string)$row['status'],
        'join_code' => (string)($row['join_code'] ?? ''),
        'support_company_id' => (int)$row['support_company_id'],
        'support_company_name' => sxrs_company_name($pdo, (int)$row['support_company_id']),
        'support_login_id' => (int)$row['support_login_id'],
        'support_name' => (string)($supportUser['name'] ?? $row['support_login'] ?? ('Usuario #' . (int)$row['support_login_id'])),
        'support_login' => (string)($supportUser['login'] ?? $row['support_login'] ?? ''),
        'client_company_id' => (int)$row['client_company_id'],
        'client_company_name' => sxrs_company_name($pdo, (int)$row['client_company_id']),
        'client_login_id' => isset($row['client_login_id']) ? (int)$row['client_login_id'] : null,
        'client_name' => (string)($clientUser['name'] ?? $row['client_login'] ?? 'Cualquier usuario autorizado'),
        'client_login' => (string)($clientUser['login'] ?? $row['client_login'] ?? ''),
        'client_scope_label' => $clientUser ? ((string)$clientUser['name'] . ' (@' . (string)$clientUser['login'] . ')') : 'Cualquier usuario de la empresa',
        'created_at' => (string)$row['created_at'],
        'accepted_at' => (string)($row['accepted_at'] ?? ''),
        'ended_at' => (string)($row['ended_at'] ?? ''),
        'last_support_seen' => (string)($row['last_support_seen'] ?? ''),
        'last_client_seen' => (string)($row['last_client_seen'] ?? ''),
        'invite_url' => '/public/soporte_remoto/cliente.php?token=' . rawurlencode((string)$row['token']) . '&v=' . SX_REMOTE_CLIENT_VERSION,
    ];
}

function sxrs_assert_support_session(PDO $pdo, int $sessionId): array
{
    $ctx = sxrs_require_support_access();
    $row = sxrs_session_row($pdo, $sessionId);
    if (!$row) {
        sxrs_json(['ok' => false, 'error' => 'Sesión no encontrada'], 404);
    }
    if ((int)$row['support_company_id'] !== (int)$ctx['id_empresa']) {
        sxrs_json(['ok' => false, 'error' => 'No autorizado para esta sesión'], 403);
    }
    return $row;
}

function sxrs_assert_client_token(PDO $pdo, string $token): array
{
    $ctx = sxrs_current_context();
    $stmt = $pdo->prepare("SELECT * FROM " . MASTER_DB . ".smx_remote_support_sessions WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        sxrs_json(['ok' => false, 'error' => 'Invitación inválida o expirada'], 404);
    }
    if ((int)$row['client_company_id'] !== (int)$ctx['id_empresa']) {
        sxrs_json(['ok' => false, 'error' => 'Esta invitación pertenece a otra empresa'], 403);
    }
    if (!sxrs_is_support_company((int)$row['support_company_id'])) {
        sxrs_json(['ok' => false, 'error' => 'Esta invitación no pertenece a soporte autorizado (' . implode('/', SX_REMOTE_SUPPORT_COMPANIES) . ')'], 403);
    }
    $expectedLogin = isset($row['client_login_id']) ? (int)$row['client_login_id'] : 0;
    if ($expectedLogin > 0 && $expectedLogin !== (int)$ctx['id_login']) {
        sxrs_json(['ok' => false, 'error' => 'Esta invitación está asignada a otro usuario'], 403);
    }
    return $row;
}

function sxrs_assert_client_session(PDO $pdo, int $sessionId): array
{
    $ctx = sxrs_current_context();
    $row = sxrs_session_row($pdo, $sessionId);
    if (!$row) {
        sxrs_json(['ok' => false, 'error' => 'Sesión no encontrada'], 404);
    }
    if ((int)$row['client_company_id'] !== (int)$ctx['id_empresa']) {
        sxrs_json(['ok' => false, 'error' => 'Esta sesión pertenece a otra empresa'], 403);
    }
    if (!sxrs_is_support_company((int)$row['support_company_id'])) {
        sxrs_json(['ok' => false, 'error' => 'Esta sesión no pertenece a soporte autorizado (' . implode('/', SX_REMOTE_SUPPORT_COMPANIES) . ')'], 403);
    }
    $expectedLogin = isset($row['client_login_id']) ? (int)$row['client_login_id'] : 0;
    if ($expectedLogin > 0 && $expectedLogin !== (int)$ctx['id_login']) {
        sxrs_json(['ok' => false, 'error' => 'Esta sesión está asignada a otro usuario'], 403);
    }
    return $row;
}

function sxrs_insert_event(PDO $pdo, int $sessionId, string $senderRole, string $eventType, $payload): int
{
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_remote_support_events (session_id, sender_role, event_type, payload_json) VALUES (?, ?, ?, ?)");
    $stmt->execute([$sessionId, $senderRole, $eventType, $payloadJson]);
    return (int)$pdo->lastInsertId();
}

function sxrs_poll_events(PDO $pdo, int $sessionId, string $viewerRole, int $lastId): array
{
    $stmt = $pdo->prepare("SELECT id, sender_role, event_type, payload_json, created_at FROM " . MASTER_DB . ".smx_remote_support_events WHERE session_id = ? AND id > ? AND sender_role <> ? ORDER BY id ASC LIMIT 200");
    $stmt->execute([$sessionId, $lastId, $viewerRole]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $events = [];
    foreach ($rows as $row) {
        $payload = [];
        if (!empty($row['payload_json'])) {
            $decoded = json_decode((string)$row['payload_json'], true);
            if (is_array($decoded)) $payload = $decoded;
        }
        $events[] = [
            'id' => (int)$row['id'],
            'sender_role' => (string)$row['sender_role'],
            'event_type' => (string)$row['event_type'],
            'payload' => $payload,
            'created_at' => (string)$row['created_at'],
        ];
    }
    return $events;
}

function sxrs_ice_servers(): array
{
    $servers = [
        ['urls' => ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302']],
    ];
    $turnUrl = trim((string)getenv('SISTEMAX_REMOTE_TURN_URL'));
    if ($turnUrl !== '') {
        $entry = ['urls' => [$turnUrl]];
        $turnUser = trim((string)getenv('SISTEMAX_REMOTE_TURN_USERNAME'));
        $turnPass = trim((string)getenv('SISTEMAX_REMOTE_TURN_CREDENTIAL'));
        if ($turnUser !== '') $entry['username'] = $turnUser;
        if ($turnPass !== '') $entry['credential'] = $turnPass;
        $servers[] = $entry;
    }
    return $servers;
}

function sxrs_ensure_user_notifications_table(PDO $pdo): string
{
    $table = MASTER_DB . '.smx_user_notifications';
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$table} (
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
    return $table;
}

function sxrs_send_client_notification(PDO $pdo, array $sessionRow): bool
{
    $destLogin = isset($sessionRow['client_login_id']) ? (int)$sessionRow['client_login_id'] : 0;
    $destEmpresa = (int)($sessionRow['client_company_id'] ?? 0);
    if ($destLogin <= 0 || $destEmpresa <= 0) {
        return false;
    }

    $table = sxrs_ensure_user_notifications_table($pdo);
    $supportUser = sxrs_user_row($pdo, (int)$sessionRow['support_company_id'], (int)$sessionRow['support_login_id']);
    $supportName = (string)($supportUser['name'] ?? $sessionRow['support_login'] ?? 'Soporte SistemaX');
    $supportLogin = (string)($supportUser['login'] ?? $sessionRow['support_login'] ?? '');
    $title = 'Solicitud de soporte remoto';
    $message = 'Soporte técnico solicita ver tu pantalla';
    if ($supportName !== '') {
        $message .= ' desde ' . $supportName;
        if ($supportLogin !== '') {
            $message .= ' (@' . $supportLogin . ')';
        }
    }
    $message .= '. Pulsá Abrir para compartir tu pantalla.';
    $url = '/public/soporte_remoto/cliente.php?token=' . rawurlencode((string)$sessionRow['token']) . '&v=' . SX_REMOTE_CLIENT_VERSION;

    $stmt = $pdo->prepare("
        INSERT INTO {$table} (id_empresa, id_dest_login, id_remitente_login, tipo, titulo, mensaje, url, leida)
        VALUES (?, ?, ?, 'info', ?, ?, ?, 0)
    ");
    $stmt->execute([
        $destEmpresa,
        $destLogin,
        (int)($sessionRow['support_login_id'] ?? 0),
        $title,
        $message,
        $url,
    ]);
    return true;
}

$pdo = sxrs_pdo();
sxrs_ensure_schema($pdo);
$input = sxrs_request_json();
$action = (string)($_GET['action'] ?? $_POST['action'] ?? ($input['action'] ?? ''));
$ctx = sxrs_current_context();

try {
    if ($action === 'config') {
        sxrs_json([
            'ok' => true,
            'ice_servers' => sxrs_ice_servers(),
            'support_companies' => SX_REMOTE_SUPPORT_COMPANIES,
            'current' => $ctx,
            'is_support_company' => sxrs_is_support_company($ctx['id_empresa']),
        ]);
    }

    if ($action === 'support_catalog_companies') {
        sxrs_require_support_access();
        $q = trim((string)($_GET['q'] ?? ''));
        $stmt = $pdo->prepare("SELECT id_empresa, empresa FROM " . MASTER_DB . ".empresa WHERE (? = '' OR empresa LIKE ?) ORDER BY empresa ASC LIMIT 30");
        $like = '%' . $q . '%';
        $stmt->execute([$q, $like]);
        sxrs_json(['ok' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    if ($action === 'support_catalog_users') {
        sxrs_require_support_access();
        $companyId = (int)($_GET['id_empresa'] ?? 0);
        if ($companyId <= 0) sxrs_json(['ok' => false, 'error' => 'Empresa requerida'], 422);
        $q = trim((string)($_GET['q'] ?? ''));
        $presenceTable = MASTER_DB . '.smx_user_presence';
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS {$presenceTable} (
                id_empresa INT NOT NULL,
                id_login INT NOT NULL,
                login VARCHAR(120) DEFAULT NULL,
                user_name VARCHAR(180) DEFAULT NULL,
                current_path VARCHAR(255) DEFAULT NULL,
                last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id_empresa, id_login),
                KEY idx_last_seen (last_seen),
                KEY idx_login_seen (id_login, last_seen)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            // No romper catálogo por presencia.
        }
        $stmt = $pdo->prepare("SELECT u.id_login, u.login, u.name, u.active,
                CASE WHEN p.last_seen IS NOT NULL AND p.last_seen >= (NOW() - INTERVAL 90 SECOND) THEN 1 ELSE 0 END AS online,
                p.last_seen
            FROM " . MASTER_DB . ".sec_users u
            LEFT JOIN {$presenceTable} p ON p.id_empresa = u.id_empresa AND p.id_login = u.id_login
            WHERE u.id_empresa = ? AND (? = '' OR u.name LIKE ? OR u.login LIKE ?)
            ORDER BY online DESC, active DESC, name ASC LIMIT 50");
        $like = '%' . $q . '%';
        $stmt->execute([$companyId, $q, $like, $like]);
        sxrs_json(['ok' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    if ($action === 'support_create_session') {
        $support = sxrs_require_support_access();
        $clientCompanyId = (int)($input['client_company_id'] ?? 0);
        $clientLoginId = (int)($input['client_login_id'] ?? 0);
        $title = trim((string)($input['title'] ?? 'Soporte remoto'));
        if ($clientCompanyId <= 0) sxrs_json(['ok' => false, 'error' => 'Seleccione empresa cliente'], 422);
        $clientUser = $clientLoginId > 0 ? sxrs_user_row($pdo, $clientCompanyId, $clientLoginId) : null;
        if ($clientLoginId > 0 && !$clientUser) sxrs_json(['ok' => false, 'error' => 'Usuario cliente no encontrado'], 404);
        $token = bin2hex(random_bytes(24));
        $joinCode = substr(strtoupper(bin2hex(random_bytes(4))), 0, 8);
        $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_remote_support_sessions (token, support_company_id, support_login_id, support_login, client_company_id, client_login_id, client_login, title, status, join_code, last_support_seen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())");
        $stmt->execute([
            $token,
            (int)$support['id_empresa'],
            (int)$support['id_login'],
            (string)$support['login'],
            $clientCompanyId,
            $clientLoginId > 0 ? $clientLoginId : null,
            $clientUser['login'] ?? null,
            $title !== '' ? $title : 'Soporte remoto',
            $joinCode,
        ]);
        $id = (int)$pdo->lastInsertId();
        $row = sxrs_session_row($pdo, $id);
        $notificationSent = false;
        if ($row) {
            $notificationSent = sxrs_send_client_notification($pdo, $row);
        }
        sxrs_json(['ok' => true, 'session' => sxrs_format_session($pdo, $row), 'notification_sent' => $notificationSent]);
    }

    if ($action === 'support_list_sessions') {
        $support = sxrs_require_support_access();
        $stmt = $pdo->prepare("SELECT * FROM " . MASTER_DB . ".smx_remote_support_sessions WHERE support_company_id = ? AND status IN ('pending','active') ORDER BY CASE status WHEN 'active' THEN 0 ELSE 1 END, updated_at DESC LIMIT 100");
        $stmt->execute([(int)$support['id_empresa']]);
        $items = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $items[] = sxrs_format_session($pdo, $row);
        }
        sxrs_json(['ok' => true, 'items' => $items]);
    }

    if ($action === 'support_get_session') {
        $row = sxrs_assert_support_session($pdo, (int)($_GET['session_id'] ?? 0));
        sxrs_json(['ok' => true, 'session' => sxrs_format_session($pdo, $row)]);
    }

    if ($action === 'support_notify_client') {
        $row = sxrs_assert_support_session($pdo, (int)($input['session_id'] ?? $_GET['session_id'] ?? 0));
        $sent = sxrs_send_client_notification($pdo, $row);
        sxrs_json([
            'ok' => true,
            'notification_sent' => $sent,
            'message' => $sent ? 'Notificación enviada al cliente' : 'La sesión no tiene usuario cliente específico'
        ]);
    }

    if ($action === 'client_get_session') {
        $token = trim((string)($_GET['token'] ?? ''));
        $sessionId = (int)($_GET['session_id'] ?? 0);
        if ($token !== '') {
            $row = sxrs_assert_client_token($pdo, $token);
        } elseif ($sessionId > 0) {
            $row = sxrs_assert_client_session($pdo, $sessionId);
        } else {
            sxrs_json(['ok' => false, 'error' => 'Token o sesión requerida'], 422);
        }
        sxrs_json(['ok' => true, 'session' => sxrs_format_session($pdo, $row)]);
    }

    if ($action === 'client_list_sessions') {
        $ctx = sxrs_current_context();
        $stmt = $pdo->prepare("SELECT * FROM " . MASTER_DB . ".smx_remote_support_sessions
            WHERE client_company_id = ?
              AND status IN ('pending','active')
              AND support_company_id IN (" . implode(',', SX_REMOTE_SUPPORT_COMPANIES) . ")
              AND (client_login_id IS NULL OR client_login_id = 0 OR client_login_id = ?)
            ORDER BY CASE status WHEN 'active' THEN 0 ELSE 1 END, updated_at DESC
            LIMIT 100");
        $stmt->execute([(int)$ctx['id_empresa'], (int)$ctx['id_login']]);
        $items = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $items[] = sxrs_format_session($pdo, $row);
        }
        sxrs_json(['ok' => true, 'items' => $items]);
    }

    if ($action === 'client_accept') {
        $token = trim((string)($input['token'] ?? ''));
        $row = sxrs_assert_client_token($pdo, $token);
        $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_remote_support_sessions SET status = 'active', accepted_at = COALESCE(accepted_at, NOW()), last_client_seen = NOW() WHERE id = ?");
        $stmt->execute([(int)$row['id']]);
        sxrs_insert_event($pdo, (int)$row['id'], 'client', 'client_ready', ['client_login_id' => $ctx['id_login'], 'client_name' => $ctx['name']]);
        $fresh = sxrs_session_row($pdo, (int)$row['id']);
        sxrs_json(['ok' => true, 'session' => sxrs_format_session($pdo, $fresh)]);
    }

    if ($action === 'poll') {
        $sessionId = (int)($_GET['session_id'] ?? 0);
        $role = trim((string)($_GET['role'] ?? ''));
        $lastId = max(0, (int)($_GET['last_id'] ?? 0));
        if (!in_array($role, ['support', 'client'], true)) sxrs_json(['ok' => false, 'error' => 'Rol inválido'], 422);

        if ($role === 'support') {
            $row = sxrs_assert_support_session($pdo, $sessionId);
            $pdo->prepare("UPDATE " . MASTER_DB . ".smx_remote_support_sessions SET last_support_seen = NOW() WHERE id = ?")->execute([$sessionId]);
        } else {
            $token = trim((string)($_GET['token'] ?? ''));
            $row = $token !== '' ? sxrs_assert_client_token($pdo, $token) : sxrs_assert_client_session($pdo, $sessionId);
            if ((int)$row['id'] !== $sessionId) sxrs_json(['ok' => false, 'error' => 'Sesión inválida'], 403);
            $pdo->prepare("UPDATE " . MASTER_DB . ".smx_remote_support_sessions SET last_client_seen = NOW() WHERE id = ?")->execute([$sessionId]);
        }

        sxrs_json([
            'ok' => true,
            'session' => sxrs_format_session($pdo, sxrs_session_row($pdo, $sessionId)),
            'events' => sxrs_poll_events($pdo, $sessionId, $role, $lastId),
        ]);
    }

    if ($action === 'signal') {
        $sessionId = (int)($input['session_id'] ?? 0);
        $role = trim((string)($input['role'] ?? ''));
        $eventType = trim((string)($input['event_type'] ?? ''));
        $payload = $input['payload'] ?? [];
        if (!in_array($role, ['support', 'client'], true)) sxrs_json(['ok' => false, 'error' => 'Rol inválido'], 422);
        if ($eventType === '') sxrs_json(['ok' => false, 'error' => 'Tipo de evento requerido'], 422);

        if ($role === 'support') {
            sxrs_assert_support_session($pdo, $sessionId);
            $pdo->prepare("UPDATE " . MASTER_DB . ".smx_remote_support_sessions SET status = IF(status='pending','active',status), last_support_seen = NOW() WHERE id = ?")->execute([$sessionId]);
        } else {
            $token = trim((string)($input['token'] ?? ''));
            $row = $token !== '' ? sxrs_assert_client_token($pdo, $token) : sxrs_assert_client_session($pdo, $sessionId);
            if ((int)$row['id'] !== $sessionId) sxrs_json(['ok' => false, 'error' => 'Sesión inválida'], 403);
            $pdo->prepare("UPDATE " . MASTER_DB . ".smx_remote_support_sessions SET status = IF(status='pending','active',status), accepted_at = COALESCE(accepted_at, NOW()), last_client_seen = NOW() WHERE id = ?")->execute([$sessionId]);
        }

        $eventId = sxrs_insert_event($pdo, $sessionId, $role, $eventType, $payload);
        sxrs_json(['ok' => true, 'event_id' => $eventId]);
    }

    if ($action === 'end') {
        $sessionId = (int)($input['session_id'] ?? 0);
        $role = trim((string)($input['role'] ?? 'support'));
        if ($role === 'support') {
            sxrs_assert_support_session($pdo, $sessionId);
        } else {
            $token = trim((string)($input['token'] ?? ''));
            $row = $token !== '' ? sxrs_assert_client_token($pdo, $token) : sxrs_assert_client_session($pdo, $sessionId);
            if ((int)$row['id'] !== $sessionId) sxrs_json(['ok' => false, 'error' => 'Sesión inválida'], 403);
        }
        $pdo->prepare("UPDATE " . MASTER_DB . ".smx_remote_support_sessions SET status = 'ended', ended_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$sessionId]);
        sxrs_insert_event($pdo, $sessionId, $role, 'ended', ['ended_by' => $role]);
        sxrs_json(['ok' => true]);
    }

    sxrs_json(['ok' => false, 'error' => 'Acción no válida'], 404);
} catch (Throwable $e) {
    error_log('[soporte_remoto/api] ' . $e->getMessage());
    sxrs_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
