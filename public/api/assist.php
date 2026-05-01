<?php
require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function assistJson(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function assistPdo(): PDO
{
    return Database::getMasterConnection();
}

function assistNowUtc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function assistReadJsonInput(): array
{
    $raw = file_get_contents('php://input');
    $data = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
    return is_array($data) ? $data : [];
}

function assistGetBearerToken(): string
{
    $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    $auth = (string)($headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        return trim((string)$m[1]);
    }
    return '';
}

function assistHashToken(string $token): string
{
    return hash('sha256', $token);
}

function assistGenerateToken(): string
{
    return bin2hex(random_bytes(32));
}

function assistSetupSecret(): string
{
    return (string)(getenv('SISTEMAX_MOBILE_SETUP_SECRET') ?: (__DIR__ . '|' . MASTER_DB . '|mobile-setup-v1'));
}

function assistResolveSetupToken(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || strpos($token, '.') === false) {
        return null;
    }
    [$body, $sig] = explode('.', $token, 2);
    $expected = hash_hmac('sha256', $body, assistSetupSecret());
    if (!hash_equals($expected, (string)$sig)) {
        return null;
    }
    $raw = base64_decode(strtr($body, '-_', '+/'), true);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || (int)($data['exp'] ?? 0) < time()) {
        return null;
    }
    return [
        'id_empresa' => (int)($data['id_empresa'] ?? 0),
        'id_login' => (int)($data['id_login'] ?? 0),
        'login' => (string)($data['login'] ?? ''),
        'user_name' => (string)($data['user_name'] ?? ''),
        'is_support' => in_array((int)($data['id_empresa'] ?? 0), SISTEMAX_SUPPORT_COMPANIES, true),
    ];
}

function assistFramesDir(): string
{
    return dirname(__DIR__) . '/soporte/uploads/assist_frames';
}

function assistFramesUrlPrefix(): string
{
    return '/public/soporte/uploads/assist_frames';
}

function assistCreateAgentToken(PDO $pdo, int $deviceId): array
{
    $plainToken = assistGenerateToken();
    $expiresAt = time() + (30 * 86400);
    $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_assist_agent_tokens
        (device_id, token_hash, token_scope, expires_at)
        VALUES (?, ?, 'agent', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))");
    $stmt->execute([$deviceId, assistHashToken($plainToken)]);

    return [
        'device_id' => $deviceId,
        'agent_token' => $plainToken,
        'expires_at' => gmdate('c', $expiresAt),
    ];
}

function assistUpsertDevice(PDO $pdo, array $ctx, array $fields): int
{
    $companyName = assistCompanyName($pdo, (int)$ctx['id_empresa']);
    $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_assist_devices
        (id_empresa, id_login, login_name, user_name, company_name, device_uuid, device_name, host_name, platform, platform_version, architecture, agent_version, device_public_key, status, last_seen_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'online', UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            id_empresa = VALUES(id_empresa),
            id_login = VALUES(id_login),
            login_name = VALUES(login_name),
            user_name = VALUES(user_name),
            company_name = VALUES(company_name),
            device_name = VALUES(device_name),
            host_name = VALUES(host_name),
            platform = VALUES(platform),
            platform_version = VALUES(platform_version),
            architecture = VALUES(architecture),
            agent_version = VALUES(agent_version),
            device_public_key = VALUES(device_public_key),
            status = 'online',
            last_seen_at = UTC_TIMESTAMP()");
    $stmt->execute([
        (int)$ctx['id_empresa'],
        (int)$ctx['id_login'],
        (string)($ctx['login'] ?? ''),
        (string)($ctx['user_name'] ?? ''),
        $companyName,
        (string)$fields['device_uuid'],
        (string)$fields['device_name'],
        $fields['host_name'] !== '' ? (string)$fields['host_name'] : null,
        (string)$fields['platform'],
        $fields['platform_version'] !== '' ? (string)$fields['platform_version'] : null,
        $fields['architecture'] !== '' ? (string)$fields['architecture'] : null,
        $fields['agent_version'] !== '' ? (string)$fields['agent_version'] : null,
        $fields['public_key'] !== '' ? (string)$fields['public_key'] : null,
    ]);

    $idStmt = $pdo->prepare("SELECT id FROM " . MASTER_DB . ".smx_assist_devices WHERE device_uuid = ? LIMIT 1");
    $idStmt->execute([(string)$fields['device_uuid']]);
    return (int)($idStmt->fetchColumn() ?: 0);
}

function assistRequireLoginContext(): array
{
    if (!Session::isLoggedIn()) {
        assistJson([
            'ok' => false,
            'auth_required' => true,
            'login_url' => '/public/login.php',
            'error' => 'Sesión vencida. Iniciá sesión otra vez para generar el token Assist.',
        ]);
    }
    return [
        'id_empresa' => (int)Session::getIdEmpresa(),
        'id_login' => (int)Session::getIdLogin(),
        'login' => (string)($_SESSION['usuario'] ?? $_SESSION['login'] ?? ''),
        'user_name' => (string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? ($_SESSION['usuario'] ?? '')),
        'is_support' => in_array((int)Session::getIdEmpresa(), SISTEMAX_SUPPORT_COMPANIES, true),
    ];
}

function assistResolveRequestContext(): array
{
    if (Session::isLoggedIn()) {
        return assistRequireLoginContext();
    }
    $input = assistReadJsonInput();
    $setupToken = trim((string)($input['setup_token'] ?? $_GET['setup_token'] ?? $_POST['setup_token'] ?? ''));
    $ctx = assistResolveSetupToken($setupToken);
    if ($ctx) {
        return $ctx;
    }
    assistJson([
        'ok' => false,
        'auth_required' => true,
        'login_url' => '/public/login.php',
        'error' => 'Sesión vencida. Iniciá sesión otra vez para generar el token Assist.',
    ]);
}

function assistRequireSupport(array $ctx): void
{
    if (empty($ctx['is_support'])) {
        assistJson(['ok' => false, 'error' => 'Acceso reservado a soporte'], 403);
    }
}

function assistEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_assist_devices (
        id BIGINT NOT NULL AUTO_INCREMENT,
        id_empresa INT NOT NULL,
        id_login INT NOT NULL,
        login_name VARCHAR(120) DEFAULT NULL,
        user_name VARCHAR(180) DEFAULT NULL,
        company_name VARCHAR(190) DEFAULT NULL,
        device_uuid CHAR(36) NOT NULL,
        device_name VARCHAR(190) NOT NULL,
        host_name VARCHAR(190) DEFAULT NULL,
        platform VARCHAR(40) NOT NULL,
        platform_version VARCHAR(80) DEFAULT NULL,
        architecture VARCHAR(40) DEFAULT NULL,
        agent_version VARCHAR(50) DEFAULT NULL,
        agent_channel VARCHAR(20) DEFAULT NULL,
        device_public_key TEXT DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'offline',
        ip_public VARCHAR(64) DEFAULT NULL,
        ip_local VARCHAR(64) DEFAULT NULL,
        last_seen_at DATETIME DEFAULT NULL,
        registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_device_uuid (device_uuid),
        KEY idx_empresa_status (id_empresa, status, last_seen_at),
        KEY idx_login (id_login),
        KEY idx_platform (platform)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_assist_device_capabilities (
        id BIGINT NOT NULL AUTO_INCREMENT,
        device_id BIGINT NOT NULL,
        can_screen_capture TINYINT(1) NOT NULL DEFAULT 0,
        can_input_control TINYINT(1) NOT NULL DEFAULT 0,
        can_file_transfer TINYINT(1) NOT NULL DEFAULT 0,
        can_clipboard_sync TINYINT(1) NOT NULL DEFAULT 0,
        can_audio_stream TINYINT(1) NOT NULL DEFAULT 0,
        can_unattended TINYINT(1) NOT NULL DEFAULT 0,
        requires_local_consent TINYINT(1) NOT NULL DEFAULT 1,
        permissions_screen TINYINT(1) NOT NULL DEFAULT 0,
        permissions_accessibility TINYINT(1) NOT NULL DEFAULT 0,
        permissions_input_monitoring TINYINT(1) NOT NULL DEFAULT 0,
        raw_payload JSON DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_device_caps (device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_assist_requests (
        id BIGINT NOT NULL AUTO_INCREMENT,
        id_empresa INT NOT NULL,
        id_login INT NOT NULL,
        login_name VARCHAR(120) DEFAULT NULL,
        user_name VARCHAR(180) DEFAULT NULL,
        source_device_id BIGINT DEFAULT NULL,
        request_type VARCHAR(30) NOT NULL DEFAULT 'remote_support',
        platform VARCHAR(40) DEFAULT NULL,
        app_version VARCHAR(50) DEFAULT NULL,
        priority VARCHAR(20) NOT NULL DEFAULT 'normal',
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        notes TEXT DEFAULT NULL,
        taken_by_login INT DEFAULT NULL,
        taken_by_name VARCHAR(180) DEFAULT NULL,
        resolved_by_login INT DEFAULT NULL,
        resolved_by_name VARCHAR(180) DEFAULT NULL,
        resolution_notes TEXT DEFAULT NULL,
        taken_at DATETIME DEFAULT NULL,
        resolved_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_empresa_status (id_empresa, status, created_at),
        KEY idx_login_status (id_login, status, created_at),
        KEY idx_taken (taken_by_login, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_assist_sessions (
        id BIGINT NOT NULL AUTO_INCREMENT,
        request_id BIGINT DEFAULT NULL,
        id_empresa INT NOT NULL,
        support_login_id INT NOT NULL,
        support_login_name VARCHAR(180) DEFAULT NULL,
        target_device_id BIGINT NOT NULL,
        mode VARCHAR(20) NOT NULL DEFAULT 'assisted',
        status VARCHAR(20) NOT NULL DEFAULT 'created',
        started_at DATETIME DEFAULT NULL,
        ended_at DATETIME DEFAULT NULL,
        duration_sec INT DEFAULT NULL,
        consent_required TINYINT(1) NOT NULL DEFAULT 1,
        consent_granted_at DATETIME DEFAULT NULL,
        recording_enabled TINYINT(1) NOT NULL DEFAULT 0,
        recording_path VARCHAR(255) DEFAULT NULL,
        transport_type VARCHAR(20) DEFAULT NULL,
        relay_used TINYINT(1) NOT NULL DEFAULT 0,
        close_reason VARCHAR(40) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_empresa_status (id_empresa, status, created_at),
        KEY idx_support (support_login_id, status),
        KEY idx_device (target_device_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_assist_session_events (
        id BIGINT NOT NULL AUTO_INCREMENT,
        session_id BIGINT NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        event_level VARCHAR(20) NOT NULL DEFAULT 'info',
        actor_type VARCHAR(20) DEFAULT NULL,
        actor_login_id INT DEFAULT NULL,
        payload JSON DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_session_time (session_id, created_at),
        KEY idx_event_type (event_type, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_assist_session_signals (
        id BIGINT NOT NULL AUTO_INCREMENT,
        session_id BIGINT NOT NULL,
        sender_type VARCHAR(20) NOT NULL,
        sender_login_id INT DEFAULT NULL,
        signal_type VARCHAR(40) NOT NULL,
        payload JSON DEFAULT NULL,
        delivered_to_agent_at DATETIME DEFAULT NULL,
        delivered_to_support_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_session_created (session_id, created_at),
        KEY idx_session_agent_delivery (session_id, delivered_to_agent_at),
        KEY idx_session_support_delivery (session_id, delivered_to_support_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_assist_policies (
        id BIGINT NOT NULL AUTO_INCREMENT,
        id_empresa INT NOT NULL,
        allow_unattended TINYINT(1) NOT NULL DEFAULT 0,
        require_consent TINYINT(1) NOT NULL DEFAULT 1,
        allow_file_transfer TINYINT(1) NOT NULL DEFAULT 1,
        allow_clipboard_sync TINYINT(1) NOT NULL DEFAULT 1,
        record_sessions TINYINT(1) NOT NULL DEFAULT 1,
        allowed_support_roles JSON DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_empresa_policy (id_empresa)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_assist_agent_tokens (
        id BIGINT NOT NULL AUTO_INCREMENT,
        device_id BIGINT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        token_scope VARCHAR(50) NOT NULL DEFAULT 'agent',
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_token_hash (token_hash),
        KEY idx_device_expires (device_id, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_assist_bootstrap_tokens (
        id BIGINT NOT NULL AUTO_INCREMENT,
        id_empresa INT NOT NULL,
        id_login INT NOT NULL,
        login_name VARCHAR(120) DEFAULT NULL,
        user_name VARCHAR(180) DEFAULT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_token_hash (token_hash),
        KEY idx_empresa_expires (id_empresa, expires_at),
        KEY idx_login_expires (id_login, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_assist_device_frames (
        id BIGINT NOT NULL AUTO_INCREMENT,
        device_id BIGINT NOT NULL,
        mime_type VARCHAR(40) NOT NULL DEFAULT 'image/jpeg',
        width INT NOT NULL DEFAULT 0,
        height INT NOT NULL DEFAULT 0,
        byte_size INT NOT NULL DEFAULT 0,
        file_path VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_device_created (device_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

function assistLogEvent(PDO $pdo, int $sessionId, string $type, string $level = 'info', ?string $actorType = null, ?int $actorLoginId = null, ?array $payload = null): void
{
    $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_assist_session_events
        (session_id, event_type, event_level, actor_type, actor_login_id, payload)
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $sessionId,
        $type,
        $level,
        $actorType,
        $actorLoginId,
        $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
}

function assistCompanyName(PDO $pdo, int $idEmpresa): string
{
    $stmt = $pdo->prepare("SELECT empresa FROM " . MASTER_DB . ".empresa WHERE id_empresa = ? LIMIT 1");
    $stmt->execute([$idEmpresa]);
    return (string)($stmt->fetchColumn() ?: ('Empresa #' . $idEmpresa));
}

function assistPushSignal(PDO $pdo, int $sessionId, string $senderType, ?int $senderLoginId, string $signalType, array $payload = []): int
{
    $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_assist_session_signals
        (session_id, sender_type, sender_login_id, signal_type, payload)
        VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $sessionId,
        $senderType,
        $senderLoginId,
        $signalType,
        !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
    return (int)$pdo->lastInsertId();
}

function assistPullSignals(PDO $pdo, int $sessionId, string $recipientType, int $limit = 50): array
{
    $deliveryColumn = $recipientType === 'agent' ? 'delivered_to_agent_at' : 'delivered_to_support_at';
    $senderType = $recipientType === 'agent' ? 'support' : 'agent';

    $stmt = $pdo->prepare("SELECT id, session_id, sender_type, sender_login_id, signal_type, payload, created_at
        FROM " . MASTER_DB . ".smx_assist_session_signals
        WHERE session_id = ?
          AND sender_type = ?
          AND {$deliveryColumn} IS NULL
        ORDER BY id ASC
        LIMIT " . max(1, min(200, $limit)));
    $stmt->execute([$sessionId, $senderType]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        return [];
    }

    $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $upd = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_assist_session_signals
        SET {$deliveryColumn} = UTC_TIMESTAMP()
        WHERE id IN ({$in})");
    $upd->execute($ids);

    return array_map(static function (array $row): array {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['session_id'] = (int)($row['session_id'] ?? 0);
        $row['sender_login_id'] = isset($row['sender_login_id']) ? (int)$row['sender_login_id'] : null;
        $payload = $row['payload'] ?? null;
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $row['payload'] = is_array($decoded) ? $decoded : ['raw' => $payload];
        } elseif (!is_array($payload)) {
            $row['payload'] = [];
        }
        return $row;
    }, $rows);
}

function assistAgentContext(PDO $pdo): array
{
    $token = assistGetBearerToken();
    if ($token === '') {
        assistJson(['ok' => false, 'error' => 'Bearer token requerido'], 401);
    }
    $stmt = $pdo->prepare("SELECT t.device_id, d.id_empresa, d.id_login, d.login_name, d.user_name
        FROM " . MASTER_DB . ".smx_assist_agent_tokens t
        INNER JOIN " . MASTER_DB . ".smx_assist_devices d ON d.id = t.device_id
        WHERE t.token_hash = ?
          AND t.revoked_at IS NULL
          AND t.expires_at >= UTC_TIMESTAMP()
        LIMIT 1");
    $stmt->execute([assistHashToken($token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        assistJson(['ok' => false, 'error' => 'Token de agente inválido'], 401);
    }
    return [
        'device_id' => (int)$row['device_id'],
        'id_empresa' => (int)$row['id_empresa'],
        'id_login' => (int)$row['id_login'],
        'login' => (string)($row['login_name'] ?? ''),
        'user_name' => (string)($row['user_name'] ?? ''),
    ];
}

$input = assistReadJsonInput();
$action = (string)($_GET['action'] ?? $_POST['action'] ?? ($input['action'] ?? ''));

try {
    $pdo = assistPdo();
    assistEnsureSchema($pdo);

    if ($action === 'agent_register_bootstrap') {
        $bootstrapToken = trim((string)($input['bootstrap_token'] ?? ''));
        $deviceUuid = substr(trim((string)($input['device_uuid'] ?? '')), 0, 36);
        $deviceName = substr(trim((string)($input['device_name'] ?? '')), 0, 190);
        $hostName = substr(trim((string)($input['host_name'] ?? '')), 0, 190);
        $platform = substr(trim((string)($input['platform'] ?? 'windows')), 0, 40);
        $platformVersion = substr(trim((string)($input['platform_version'] ?? '')), 0, 80);
        $architecture = substr(trim((string)($input['architecture'] ?? '')), 0, 40);
        $agentVersion = substr(trim((string)($input['agent_version'] ?? '')), 0, 50);
        $publicKey = trim((string)($input['public_key'] ?? ''));

        if ($bootstrapToken === '' || $deviceUuid === '' || $deviceName === '') {
            assistJson(['ok' => false, 'error' => 'bootstrap_token, device_uuid y device_name son requeridos'], 422);
        }

        $stmt = $pdo->prepare("SELECT id, id_empresa, id_login, login_name, user_name
            FROM " . MASTER_DB . ".smx_assist_bootstrap_tokens
            WHERE token_hash = ?
              AND used_at IS NULL
              AND expires_at >= UTC_TIMESTAMP()
            LIMIT 1");
        $stmt->execute([assistHashToken($bootstrapToken)]);
        $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tokenRow) {
            assistJson(['ok' => false, 'error' => 'Bootstrap token inválido o vencido'], 401);
        }

        $ctx = [
            'id_empresa' => (int)$tokenRow['id_empresa'],
            'id_login' => (int)$tokenRow['id_login'],
            'login' => (string)($tokenRow['login_name'] ?? ''),
            'user_name' => (string)($tokenRow['user_name'] ?? ''),
        ];
        $deviceId = assistUpsertDevice($pdo, $ctx, [
            'device_uuid' => $deviceUuid,
            'device_name' => $deviceName,
            'host_name' => $hostName,
            'platform' => $platform,
            'platform_version' => $platformVersion,
            'architecture' => $architecture,
            'agent_version' => $agentVersion,
            'public_key' => $publicKey,
        ]);

        $useStmt = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_assist_bootstrap_tokens
            SET used_at = UTC_TIMESTAMP()
            WHERE id = ?");
        $useStmt->execute([(int)$tokenRow['id']]);

        assistJson([
            'ok' => true,
            'data' => assistCreateAgentToken($pdo, $deviceId),
        ]);
    }

    if ($action === 'agent_register') {
        $ctx = assistRequireLoginContext();
        $deviceUuid = substr(trim((string)($input['device_uuid'] ?? '')), 0, 36);
        $deviceName = substr(trim((string)($input['device_name'] ?? '')), 0, 190);
        $hostName = substr(trim((string)($input['host_name'] ?? '')), 0, 190);
        $platform = substr(trim((string)($input['platform'] ?? 'windows')), 0, 40);
        $platformVersion = substr(trim((string)($input['platform_version'] ?? '')), 0, 80);
        $architecture = substr(trim((string)($input['architecture'] ?? '')), 0, 40);
        $agentVersion = substr(trim((string)($input['agent_version'] ?? '')), 0, 50);
        $publicKey = trim((string)($input['public_key'] ?? ''));

        if ($deviceUuid === '' || $deviceName === '') {
            assistJson(['ok' => false, 'error' => 'device_uuid y device_name son requeridos'], 422);
        }
        $deviceId = assistUpsertDevice($pdo, $ctx, [
            'device_uuid' => $deviceUuid,
            'device_name' => $deviceName,
            'host_name' => $hostName,
            'platform' => $platform,
            'platform_version' => $platformVersion,
            'architecture' => $architecture,
            'agent_version' => $agentVersion,
            'public_key' => $publicKey,
        ]);

        assistJson([
            'ok' => true,
            'data' => assistCreateAgentToken($pdo, $deviceId),
        ]);
    }

    if (in_array($action, ['agent_heartbeat', 'agent_pending_sessions', 'agent_session_consent', 'agent_session_state', 'agent_push_event', 'agent_request_support', 'agent_upload_frame', 'agent_pull_signals', 'agent_push_signal'], true)) {
        $agentCtx = assistAgentContext($pdo);

        if ($action === 'agent_heartbeat') {
            $caps = is_array($input['capabilities'] ?? null) ? $input['capabilities'] : [];
            $status = substr(trim((string)($input['status'] ?? 'online')), 0, 20) ?: 'online';
            $ipPublic = substr(trim((string)($input['ip_public'] ?? '')), 0, 64);
            $ipLocal = substr(trim((string)($input['ip_local'] ?? '')), 0, 64);

            $st = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_assist_devices
                SET status = ?, ip_public = ?, ip_local = ?, last_seen_at = UTC_TIMESTAMP()
                WHERE id = ?");
            $st->execute([$status, $ipPublic !== '' ? $ipPublic : null, $ipLocal !== '' ? $ipLocal : null, (int)$agentCtx['device_id']]);

            $capsStmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_assist_device_capabilities
                (device_id, can_screen_capture, can_input_control, can_file_transfer, can_clipboard_sync, can_audio_stream, can_unattended, requires_local_consent, permissions_screen, permissions_accessibility, permissions_input_monitoring, raw_payload)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    can_screen_capture = VALUES(can_screen_capture),
                    can_input_control = VALUES(can_input_control),
                    can_file_transfer = VALUES(can_file_transfer),
                    can_clipboard_sync = VALUES(can_clipboard_sync),
                    can_audio_stream = VALUES(can_audio_stream),
                    can_unattended = VALUES(can_unattended),
                    requires_local_consent = VALUES(requires_local_consent),
                    permissions_screen = VALUES(permissions_screen),
                    permissions_accessibility = VALUES(permissions_accessibility),
                    permissions_input_monitoring = VALUES(permissions_input_monitoring),
                    raw_payload = VALUES(raw_payload)");
            $capsStmt->execute([
                (int)$agentCtx['device_id'],
                !empty($caps['can_screen_capture']) ? 1 : 0,
                !empty($caps['can_input_control']) ? 1 : 0,
                !empty($caps['can_file_transfer']) ? 1 : 0,
                !empty($caps['can_clipboard_sync']) ? 1 : 0,
                !empty($caps['can_audio_stream']) ? 1 : 0,
                !empty($caps['can_unattended']) ? 1 : 0,
                array_key_exists('requires_local_consent', $caps) ? (!empty($caps['requires_local_consent']) ? 1 : 0) : 1,
                !empty($caps['permissions_screen']) ? 1 : 0,
                !empty($caps['permissions_accessibility']) ? 1 : 0,
                !empty($caps['permissions_input_monitoring']) ? 1 : 0,
                !empty($caps) ? json_encode($caps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]);

            assistJson(['ok' => true, 'data' => ['device_id' => (int)$agentCtx['device_id'], 'status' => $status]]);
        }

        if ($action === 'agent_pending_sessions') {
            $stmt = $pdo->prepare("SELECT id, request_id, mode, status, consent_required, created_at
                FROM " . MASTER_DB . ".smx_assist_sessions
                WHERE target_device_id = ?
                  AND status IN ('created', 'waiting_consent')
                ORDER BY id DESC
                LIMIT 20");
            $stmt->execute([(int)$agentCtx['device_id']]);
            assistJson(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
        }

        if ($action === 'agent_session_consent') {
            $sessionId = (int)($input['session_id'] ?? 0);
            $decision = strtolower(trim((string)($input['decision'] ?? 'reject')));
            if ($sessionId <= 0 || !in_array($decision, ['accept', 'reject'], true)) {
                assistJson(['ok' => false, 'error' => 'Parámetros inválidos'], 422);
            }
            $newStatus = $decision === 'accept' ? 'active' : 'cancelled';
            $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_assist_sessions
                SET status = ?, consent_granted_at = CASE WHEN ? = 'accept' THEN UTC_TIMESTAMP() ELSE consent_granted_at END, started_at = CASE WHEN ? = 'accept' THEN UTC_TIMESTAMP() ELSE started_at END, close_reason = CASE WHEN ? = 'reject' THEN 'consent_rejected' ELSE close_reason END
                WHERE id = ? AND target_device_id = ?");
            $stmt->execute([$newStatus, $decision, $decision, $decision, $sessionId, (int)$agentCtx['device_id']]);
            assistLogEvent($pdo, $sessionId, $decision === 'accept' ? 'session_consent_accepted' : 'session_consent_rejected', 'info', 'agent', (int)$agentCtx['id_login'], ['device_id' => (int)$agentCtx['device_id']]);
            assistJson(['ok' => true, 'data' => ['session_id' => $sessionId, 'status' => $newStatus]]);
        }

        if ($action === 'agent_session_state') {
            $sessionId = (int)($input['session_id'] ?? 0);
            $status = substr(trim((string)($input['status'] ?? 'active')), 0, 20);
            $transportType = substr(trim((string)($input['transport_type'] ?? '')), 0, 20);
            $relayUsed = !empty($input['relay_used']) ? 1 : 0;
            if ($sessionId <= 0) {
                assistJson(['ok' => false, 'error' => 'session_id inválido'], 422);
            }
            $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_assist_sessions
                SET status = ?, transport_type = ?, relay_used = ?, started_at = CASE WHEN ? = 'active' AND started_at IS NULL THEN UTC_TIMESTAMP() ELSE started_at END, ended_at = CASE WHEN ? IN ('ended', 'failed', 'cancelled') THEN UTC_TIMESTAMP() ELSE ended_at END
                WHERE id = ? AND target_device_id = ?");
            $stmt->execute([$status, $transportType !== '' ? $transportType : null, $relayUsed, $status, $status, $sessionId, (int)$agentCtx['device_id']]);
            assistLogEvent($pdo, $sessionId, 'session_state_' . $status, 'info', 'agent', (int)$agentCtx['id_login'], ['transport_type' => $transportType, 'relay_used' => $relayUsed === 1]);
            assistJson(['ok' => true, 'data' => ['session_id' => $sessionId, 'status' => $status]]);
        }

        if ($action === 'agent_push_event') {
            $sessionId = (int)($input['session_id'] ?? 0);
            $eventType = substr(trim((string)($input['event_type'] ?? '')), 0, 50);
            $eventLevel = substr(trim((string)($input['event_level'] ?? 'info')), 0, 20);
            $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
            if ($sessionId <= 0 || $eventType === '') {
                assistJson(['ok' => false, 'error' => 'Parámetros inválidos'], 422);
            }
            assistLogEvent($pdo, $sessionId, $eventType, $eventLevel, 'agent', (int)$agentCtx['id_login'], $payload);
            assistJson(['ok' => true]);
        }

        if ($action === 'agent_request_support') {
            $notes = trim((string)($input['notes'] ?? ''));
            $priority = substr(trim((string)($input['priority'] ?? 'normal')), 0, 20) ?: 'normal';
            $appVersion = substr(trim((string)($input['app_version'] ?? '')), 0, 50);

            $deviceStmt = $pdo->prepare("SELECT platform, agent_version
                FROM " . MASTER_DB . ".smx_assist_devices
                WHERE id = ?
                LIMIT 1");
            $deviceStmt->execute([(int)$agentCtx['device_id']]);
            $device = $deviceStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $recentStmt = $pdo->prepare("SELECT id FROM " . MASTER_DB . ".smx_assist_requests
                WHERE id_empresa = ? AND id_login = ? AND source_device_id = ? AND status IN ('pending', 'taken')
                ORDER BY id DESC LIMIT 1");
            $recentStmt->execute([
                (int)$agentCtx['id_empresa'],
                (int)$agentCtx['id_login'],
                (int)$agentCtx['device_id'],
            ]);
            $recentId = (int)($recentStmt->fetchColumn() ?: 0);
            if ($recentId > 0) {
                assistJson([
                    'ok' => true,
                    'data' => ['request_id' => $recentId],
                    'message' => 'Ya existe una solicitud abierta para este equipo.',
                ]);
            }

            $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_assist_requests
                (id_empresa, id_login, login_name, user_name, source_device_id, request_type, platform, app_version, priority, notes)
                VALUES (?, ?, ?, ?, ?, 'remote_support', ?, ?, ?, ?)");
            $stmt->execute([
                (int)$agentCtx['id_empresa'],
                (int)$agentCtx['id_login'],
                (string)$agentCtx['login'],
                (string)$agentCtx['user_name'],
                (int)$agentCtx['device_id'],
                substr(trim((string)($device['platform'] ?? 'android')), 0, 40),
                $appVersion !== '' ? $appVersion : (string)($device['agent_version'] ?? ''),
                $priority,
                $notes !== '' ? $notes : 'Solicitud enviada desde agente Android Assist',
            ]);

            assistJson([
                'ok' => true,
                'data' => ['request_id' => (int)$pdo->lastInsertId()],
            ]);
        }

        if ($action === 'agent_upload_frame') {
            $imageBase64 = trim((string)($input['image_base64'] ?? ''));
            $width = max(0, (int)($input['width'] ?? 0));
            $height = max(0, (int)($input['height'] ?? 0));
            if ($imageBase64 === '') {
                assistJson(['ok' => false, 'error' => 'image_base64 requerido'], 422);
            }

            $binary = base64_decode($imageBase64, true);
            if (!is_string($binary) || $binary === '') {
                assistJson(['ok' => false, 'error' => 'Frame inválido'], 422);
            }
            if (strlen($binary) > (2 * 1024 * 1024)) {
                assistJson(['ok' => false, 'error' => 'Frame demasiado grande'], 422);
            }

            $datePath = gmdate('Y/m/d');
            $relativeDir = $datePath . '/device-' . (int)$agentCtx['device_id'];
            $fullDir = assistFramesDir() . '/' . $relativeDir;
            if (!is_dir($fullDir) && !mkdir($fullDir, 0775, true) && !is_dir($fullDir)) {
                assistJson(['ok' => false, 'error' => 'No se pudo preparar directorio de frames'], 500);
            }

            $fileName = 'frame-' . gmdate('His') . '-' . bin2hex(random_bytes(4)) . '.jpg';
            $fullPath = $fullDir . '/' . $fileName;
            if (file_put_contents($fullPath, $binary) === false) {
                assistJson(['ok' => false, 'error' => 'No se pudo guardar frame'], 500);
            }

            $relativePath = $relativeDir . '/' . $fileName;
            $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_assist_device_frames
                (device_id, mime_type, width, height, byte_size, file_path)
                VALUES (?, 'image/jpeg', ?, ?, ?, ?)");
            $stmt->execute([
                (int)$agentCtx['device_id'],
                $width,
                $height,
                strlen($binary),
                $relativePath,
            ]);

            $cleanup = $pdo->prepare("DELETE FROM " . MASTER_DB . ".smx_assist_device_frames
                WHERE device_id = ?
                  AND id NOT IN (
                    SELECT id_keep FROM (
                        SELECT id AS id_keep
                        FROM " . MASTER_DB . ".smx_assist_device_frames
                        WHERE device_id = ?
                        ORDER BY id DESC
                        LIMIT 10
                    ) keep_rows
                  )");
            $cleanup->execute([(int)$agentCtx['device_id'], (int)$agentCtx['device_id']]);

            assistJson([
                'ok' => true,
                'data' => [
                    'frame_id' => (int)$pdo->lastInsertId(),
                    'image_url' => assistFramesUrlPrefix() . '/' . $relativePath,
                ],
            ]);
        }

        if ($action === 'agent_pull_signals') {
            $sessionId = (int)($input['session_id'] ?? $_GET['session_id'] ?? 0);
            if ($sessionId <= 0) {
                assistJson(['ok' => false, 'error' => 'session_id inválido'], 422);
            }
            $stmt = $pdo->prepare("SELECT id
                FROM " . MASTER_DB . ".smx_assist_sessions
                WHERE id = ? AND target_device_id = ?
                LIMIT 1");
            $stmt->execute([$sessionId, (int)$agentCtx['device_id']]);
            if (!(int)$stmt->fetchColumn()) {
                assistJson(['ok' => false, 'error' => 'Sesión no encontrada para este device'], 404);
            }
            assistJson([
                'ok' => true,
                'data' => assistPullSignals($pdo, $sessionId, 'agent'),
            ]);
        }

        if ($action === 'agent_push_signal') {
            $sessionId = (int)($input['session_id'] ?? 0);
            $signalType = substr(trim((string)($input['signal_type'] ?? '')), 0, 40);
            $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
            if ($sessionId <= 0 || $signalType === '') {
                assistJson(['ok' => false, 'error' => 'Parámetros inválidos'], 422);
            }
            $stmt = $pdo->prepare("SELECT id
                FROM " . MASTER_DB . ".smx_assist_sessions
                WHERE id = ? AND target_device_id = ?
                LIMIT 1");
            $stmt->execute([$sessionId, (int)$agentCtx['device_id']]);
            if (!(int)$stmt->fetchColumn()) {
                assistJson(['ok' => false, 'error' => 'Sesión no encontrada para este device'], 404);
            }
            $signalId = assistPushSignal($pdo, $sessionId, 'agent', (int)$agentCtx['id_login'], $signalType, $payload);
            assistLogEvent($pdo, $sessionId, 'signal_from_agent_' . $signalType, 'info', 'agent', (int)$agentCtx['id_login'], ['signal_id' => $signalId]);
            assistJson(['ok' => true, 'data' => ['signal_id' => $signalId]]);
        }
    }

    $ctx = null;
    if ($action !== 'client_issue_agent_bootstrap') {
        $ctx = assistRequireLoginContext();
    }

    if ($action === 'client_issue_agent_bootstrap') {
        $ctx = assistResolveRequestContext();
        $plainToken = assistGenerateToken();
        $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_assist_bootstrap_tokens
            (id_empresa, id_login, login_name, user_name, token_hash, expires_at)
            VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))");
        $stmt->execute([
            (int)$ctx['id_empresa'],
            (int)$ctx['id_login'],
            $ctx['login'],
            $ctx['user_name'],
            assistHashToken($plainToken),
        ]);

        assistJson([
            'ok' => true,
            'data' => [
                'bootstrap_token' => $plainToken,
                'expires_at' => gmdate('c', time() + (10 * 60)),
            ],
        ]);
    }

    if ($action === 'client_request_support') {
        $sourceDeviceId = (int)($input['source_device_id'] ?? 0);
        $requestType = substr(trim((string)($input['request_type'] ?? 'remote_support')), 0, 30);
        $platform = substr(trim((string)($input['platform'] ?? 'unknown')), 0, 40);
        $appVersion = substr(trim((string)($input['app_version'] ?? '')), 0, 50);
        $priority = substr(trim((string)($input['priority'] ?? 'normal')), 0, 20);
        $notes = trim((string)($input['notes'] ?? ''));

        $recentStmt = $pdo->prepare("SELECT id FROM " . MASTER_DB . ".smx_assist_requests
            WHERE id_empresa = ? AND id_login = ? AND status IN ('pending', 'taken') AND created_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)
            ORDER BY id DESC LIMIT 1");
        $recentStmt->execute([(int)$ctx['id_empresa'], (int)$ctx['id_login']]);
        $recentId = (int)($recentStmt->fetchColumn() ?: 0);
        if ($recentId > 0) {
            assistJson(['ok' => true, 'data' => ['request_id' => $recentId], 'message' => 'Ya existe una solicitud pendiente reciente.']);
        }

        $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_assist_requests
            (id_empresa, id_login, login_name, user_name, source_device_id, request_type, platform, app_version, priority, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            (int)$ctx['id_empresa'],
            (int)$ctx['id_login'],
            $ctx['login'],
            $ctx['user_name'],
            $sourceDeviceId > 0 ? $sourceDeviceId : null,
            $requestType,
            $platform,
            $appVersion !== '' ? $appVersion : null,
            $priority !== '' ? $priority : 'normal',
            $notes !== '' ? $notes : null,
        ]);
        assistJson(['ok' => true, 'data' => ['request_id' => (int)$pdo->lastInsertId()]]);
    }

    if ($action === 'client_my_requests') {
        $stmt = $pdo->prepare("SELECT *
            FROM " . MASTER_DB . ".smx_assist_requests
            WHERE id_empresa = ? AND id_login = ?
            ORDER BY id DESC
            LIMIT 100");
        $stmt->execute([(int)$ctx['id_empresa'], (int)$ctx['id_login']]);
        assistJson(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    if ($action === 'client_cancel_request') {
        $requestId = (int)($input['request_id'] ?? 0);
        if ($requestId <= 0) {
            assistJson(['ok' => false, 'error' => 'request_id inválido'], 422);
        }
        $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_assist_requests
            SET status = 'rejected', resolution_notes = 'Cancelado por el usuario', resolved_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
            WHERE id = ? AND id_empresa = ? AND id_login = ? AND status = 'pending'");
        $stmt->execute([$requestId, (int)$ctx['id_empresa'], (int)$ctx['id_login']]);
        assistJson(['ok' => true]);
    }

    if ($action === 'admin_devices') {
        assistRequireSupport($ctx);
        $q = trim((string)($_GET['q'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $sql = "SELECT d.*,
                c.can_screen_capture,
                c.can_input_control,
                c.can_file_transfer,
                c.can_clipboard_sync,
                c.can_audio_stream,
                c.can_unattended,
                c.requires_local_consent,
                c.permissions_screen,
                c.permissions_accessibility,
                c.permissions_input_monitoring,
                c.raw_payload AS capabilities_json
            FROM " . MASTER_DB . ".smx_assist_devices d
            LEFT JOIN " . MASTER_DB . ".smx_assist_device_capabilities c ON c.device_id = d.id
            WHERE 1=1";
        if ($status !== '') {
            $sql .= " AND d.status = " . $pdo->quote($status);
        }
        if ($q !== '') {
            $like = $pdo->quote('%' . $q . '%');
            $sql .= " AND (d.company_name LIKE {$like} OR d.user_name LIKE {$like} OR d.login_name LIKE {$like} OR d.device_name LIKE {$like} OR d.host_name LIKE {$like})";
        }
        $sql .= " ORDER BY d.status = 'online' DESC, d.last_seen_at DESC, d.id DESC LIMIT 250";
        assistJson(['ok' => true, 'data' => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    if ($action === 'admin_requests') {
        assistRequireSupport($ctx);
        $status = trim((string)($_GET['status'] ?? 'open'));
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = "SELECT * FROM " . MASTER_DB . ".smx_assist_requests WHERE 1=1";
        if ($status === 'open') {
            $sql .= " AND status IN ('pending', 'taken')";
        } elseif ($status !== '' && $status !== 'all') {
            $sql .= " AND status = " . $pdo->quote($status);
        }
        if ($q !== '') {
            $like = $pdo->quote('%' . $q . '%');
            $sql .= " AND (user_name LIKE {$like} OR login_name LIKE {$like} OR notes LIKE {$like})";
        }
        $sql .= " ORDER BY FIELD(status, 'pending', 'taken', 'resolved', 'rejected'), id DESC LIMIT 200";
        assistJson(['ok' => true, 'data' => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    if ($action === 'admin_take_request') {
        assistRequireSupport($ctx);
        $requestId = (int)($input['request_id'] ?? 0);
        if ($requestId <= 0) {
            assistJson(['ok' => false, 'error' => 'request_id inválido'], 422);
        }
        $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_assist_requests
            SET status = 'taken', taken_by_login = ?, taken_by_name = ?, taken_at = UTC_TIMESTAMP()
            WHERE id = ? AND status = 'pending'");
        $stmt->execute([(int)$ctx['id_login'], $ctx['user_name'], $requestId]);
        assistJson(['ok' => true]);
    }

    if ($action === 'admin_resolve_request') {
        assistRequireSupport($ctx);
        $requestId = (int)($input['request_id'] ?? 0);
        $status = strtolower(trim((string)($input['status'] ?? 'resolved')));
        $notes = trim((string)($input['resolution_notes'] ?? ''));
        if ($requestId <= 0 || !in_array($status, ['resolved', 'rejected'], true)) {
            assistJson(['ok' => false, 'error' => 'Parámetros inválidos'], 422);
        }
        $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_assist_requests
            SET status = ?, resolved_by_login = ?, resolved_by_name = ?, resolution_notes = ?, resolved_at = UTC_TIMESTAMP()
            WHERE id = ?");
        $stmt->execute([$status, (int)$ctx['id_login'], $ctx['user_name'], $notes !== '' ? $notes : null, $requestId]);
        assistJson(['ok' => true]);
    }

    if ($action === 'admin_create_session') {
        assistRequireSupport($ctx);
        $requestId = (int)($input['request_id'] ?? 0);
        $targetDeviceId = (int)($input['target_device_id'] ?? 0);
        $mode = substr(trim((string)($input['mode'] ?? 'assisted')), 0, 20) ?: 'assisted';
        if ($targetDeviceId <= 0) {
            assistJson(['ok' => false, 'error' => 'target_device_id es requerido'], 422);
        }
        $idEmpresa = 0;
        if ($requestId > 0) {
            $reqStmt = $pdo->prepare("SELECT id_empresa FROM " . MASTER_DB . ".smx_assist_requests WHERE id = ? LIMIT 1");
            $reqStmt->execute([$requestId]);
            $idEmpresa = (int)($reqStmt->fetchColumn() ?: 0);
        }
        if ($idEmpresa <= 0) {
            $deviceStmt = $pdo->prepare("SELECT id_empresa FROM " . MASTER_DB . ".smx_assist_devices WHERE id = ? LIMIT 1");
            $deviceStmt->execute([$targetDeviceId]);
            $idEmpresa = (int)($deviceStmt->fetchColumn() ?: 0);
        }
        if ($idEmpresa <= 0) {
            assistJson(['ok' => false, 'error' => 'No se pudo resolver la empresa del dispositivo'], 404);
        }
        $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_assist_sessions
            (request_id, id_empresa, support_login_id, support_login_name, target_device_id, mode, status, consent_required)
            VALUES (?, ?, ?, ?, ?, ?, 'waiting_consent', 1)");
        $stmt->execute([$requestId > 0 ? $requestId : null, $idEmpresa, (int)$ctx['id_login'], $ctx['user_name'], $targetDeviceId, $mode]);
        $sessionId = (int)$pdo->lastInsertId();
        assistLogEvent($pdo, $sessionId, 'session_created', 'info', 'support', (int)$ctx['id_login'], ['request_id' => $requestId > 0 ? $requestId : null, 'target_device_id' => $targetDeviceId, 'mode' => $mode]);
        assistJson(['ok' => true, 'data' => ['session_id' => $sessionId, 'status' => 'waiting_consent']]);
    }

    if ($action === 'admin_end_session') {
        assistRequireSupport($ctx);
        $sessionId = (int)($input['session_id'] ?? 0);
        $closeReason = substr(trim((string)($input['close_reason'] ?? 'support_finished')), 0, 40) ?: 'support_finished';
        if ($sessionId <= 0) {
            assistJson(['ok' => false, 'error' => 'session_id inválido'], 422);
        }
        $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_assist_sessions
            SET status = 'ended', ended_at = UTC_TIMESTAMP(), close_reason = ?
            WHERE id = ?");
        $stmt->execute([$closeReason, $sessionId]);
        assistLogEvent($pdo, $sessionId, 'session_ended', 'info', 'support', (int)$ctx['id_login'], ['close_reason' => $closeReason]);
        assistJson(['ok' => true]);
    }

    if ($action === 'admin_session_events') {
        assistRequireSupport($ctx);
        $sessionId = (int)($_GET['session_id'] ?? 0);
        if ($sessionId <= 0) {
            assistJson(['ok' => false, 'error' => 'session_id inválido'], 422);
        }
        $stmt = $pdo->prepare("SELECT * FROM " . MASTER_DB . ".smx_assist_session_events WHERE session_id = ? ORDER BY id ASC");
        $stmt->execute([$sessionId]);
        assistJson(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    if ($action === 'admin_pull_signals') {
        assistRequireSupport($ctx);
        $sessionId = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? $input['session_id'] ?? 0);
        if ($sessionId <= 0) {
            assistJson(['ok' => false, 'error' => 'session_id inválido'], 422);
        }
        $stmt = $pdo->prepare("SELECT id FROM " . MASTER_DB . ".smx_assist_sessions WHERE id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        if (!(int)$stmt->fetchColumn()) {
            assistJson(['ok' => false, 'error' => 'Sesión no encontrada'], 404);
        }
        assistJson([
            'ok' => true,
            'data' => assistPullSignals($pdo, $sessionId, 'support'),
        ]);
    }

    if ($action === 'admin_push_signal') {
        assistRequireSupport($ctx);
        $sessionId = (int)($input['session_id'] ?? 0);
        $signalType = substr(trim((string)($input['signal_type'] ?? '')), 0, 40);
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        if ($sessionId <= 0 || $signalType === '') {
            assistJson(['ok' => false, 'error' => 'Parámetros inválidos'], 422);
        }
        $stmt = $pdo->prepare("SELECT id FROM " . MASTER_DB . ".smx_assist_sessions WHERE id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        if (!(int)$stmt->fetchColumn()) {
            assistJson(['ok' => false, 'error' => 'Sesión no encontrada'], 404);
        }
        $signalId = assistPushSignal($pdo, $sessionId, 'support', (int)$ctx['id_login'], $signalType, $payload);
        assistLogEvent($pdo, $sessionId, 'signal_from_support_' . $signalType, 'info', 'support', (int)$ctx['id_login'], ['signal_id' => $signalId]);
        assistJson(['ok' => true, 'data' => ['signal_id' => $signalId]]);
    }

    if ($action === 'admin_device_frame') {
        assistRequireSupport($ctx);
        $deviceId = (int)($_GET['device_id'] ?? 0);
        if ($deviceId <= 0) {
            assistJson(['ok' => false, 'error' => 'device_id inválido'], 422);
        }
        $stmt = $pdo->prepare("SELECT id, device_id, mime_type, width, height, byte_size, file_path, created_at
            FROM " . MASTER_DB . ".smx_assist_device_frames
            WHERE device_id = ?
            ORDER BY id DESC
            LIMIT 1");
        $stmt->execute([$deviceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            assistJson(['ok' => true, 'data' => null]);
        }
        $row['image_url'] = assistFramesUrlPrefix() . '/' . ltrim((string)$row['file_path'], '/');
        assistJson(['ok' => true, 'data' => $row]);
    }

    if ($action === 'admin_sessions') {
        assistRequireSupport($ctx);
        $status = trim((string)($_GET['status'] ?? 'open'));
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = "SELECT s.*, d.device_name, d.host_name, d.platform, d.company_name, d.user_name
            FROM " . MASTER_DB . ".smx_assist_sessions s
            INNER JOIN " . MASTER_DB . ".smx_assist_devices d ON d.id = s.target_device_id
            WHERE 1=1";
        if ($status === 'open') {
            $sql .= " AND s.status IN ('created', 'waiting_consent', 'active')";
        } elseif ($status !== '' && $status !== 'all') {
            $sql .= " AND s.status = " . $pdo->quote($status);
        }
        if ($q !== '') {
            $like = $pdo->quote('%' . $q . '%');
            $sql .= " AND (d.company_name LIKE {$like} OR d.user_name LIKE {$like} OR d.device_name LIKE {$like} OR d.host_name LIKE {$like} OR s.support_login_name LIKE {$like})";
        }
        $sql .= " ORDER BY s.id DESC LIMIT 200";
        assistJson(['ok' => true, 'data' => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    assistJson(['ok' => false, 'error' => 'Acción no soportada'], 404);
} catch (Throwable $e) {
    assistJson(['ok' => false, 'error' => $e->getMessage()], 500);
}
