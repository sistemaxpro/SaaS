<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/mobile_tracking_fcm_helper.php';
require_once __DIR__ . '/mobile_tracking_ios_push_helper.php';

ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE);
ob_start();

header('Content-Type: application/json; charset=utf-8');

function mtJson(array $payload, int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function mtPdo(): PDO
{
    return Database::getMasterConnection();
}

function mtReadJsonInput(): array
{
    $raw = file_get_contents('php://input');
    $data = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
    return is_array($data) ? $data : [];
}

function mtGetBearerToken(): string
{
    $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    $auth = (string)($headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        return trim((string)$m[1]);
    }
    return '';
}

function mtHashToken(string $token): string
{
    return hash('sha256', $token);
}

function mtGenerateToken(): string
{
    return bin2hex(random_bytes(32));
}

function mtSetupSecret(): string
{
    return (string)(getenv('SISTEMAX_MOBILE_SETUP_SECRET') ?: (__DIR__ . '|' . MASTER_DB . '|mobile-setup-v1'));
}

function mtCreateSetupToken(array $ctx, int $ttlSeconds = 600): string
{
    $payload = [
        'id_empresa' => (int)($ctx['id_empresa'] ?? 0),
        'id_login' => (int)($ctx['id_login'] ?? 0),
        'login' => (string)($ctx['login'] ?? ''),
        'user_name' => (string)($ctx['user_name'] ?? ''),
        'exp' => time() + max(60, min(3600, $ttlSeconds)),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $body = rtrim(strtr(base64_encode((string)$json), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $body, mtSetupSecret());
    return $body . '.' . $sig;
}

function mtResolveSetupToken(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || strpos($token, '.') === false) {
        return null;
    }
    [$body, $sig] = explode('.', $token, 2);
    $expected = hash_hmac('sha256', $body, mtSetupSecret());
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

function mtNowUtc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function mtCompanyName(PDO $pdo, int $idEmpresa): string
{
    try {
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(empresa), ''), CONCAT('Empresa #', id_empresa)) FROM " . MASTER_DB . ".empresa WHERE id_empresa = ? LIMIT 1");
        $st->execute([$idEmpresa]);
        return (string)($st->fetchColumn() ?: ('Empresa #' . $idEmpresa));
    } catch (Throwable $e) {
        return 'Empresa #' . $idEmpresa;
    }
}

function mtRequireLoginContext(): array
{
    if (!Session::isLoggedIn()) {
        mtJson([
            'ok' => false,
            'auth_required' => true,
            'login_url' => '/public/login.php',
            'error' => 'Sesión vencida. Iniciá sesión otra vez para generar el token Tracking.',
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

function mtResolveRequestContext(): array
{
    if (Session::isLoggedIn()) {
        return mtRequireLoginContext();
    }
    $input = mtReadJsonInput();
    $setupToken = trim((string)($input['setup_token'] ?? $_GET['setup_token'] ?? $_POST['setup_token'] ?? ''));
    $ctx = mtResolveSetupToken($setupToken);
    if ($ctx) {
        return $ctx;
    }
    mtJson([
        'ok' => false,
        'auth_required' => true,
        'login_url' => '/public/login.php',
        'error' => 'Sesión vencida. Iniciá sesión otra vez para generar el token Tracking.',
    ]);
}

function mtEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_mobile_track_devices (
        id BIGINT NOT NULL AUTO_INCREMENT,
        id_empresa INT NOT NULL,
        id_login INT NOT NULL,
        login_name VARCHAR(120) DEFAULT NULL,
        user_name VARCHAR(180) DEFAULT NULL,
        company_name VARCHAR(190) DEFAULT NULL,
        device_uuid CHAR(36) NOT NULL,
        device_name VARCHAR(190) NOT NULL,
        marker_label VARCHAR(24) DEFAULT NULL,
        icon_type VARCHAR(24) NOT NULL DEFAULT 'auto',
        icon_color VARCHAR(24) NOT NULL DEFAULT '',
        host_name VARCHAR(190) DEFAULT NULL,
        platform VARCHAR(40) NOT NULL,
        platform_version VARCHAR(80) DEFAULT NULL,
        architecture VARCHAR(40) DEFAULT NULL,
        app_version VARCHAR(50) DEFAULT NULL,
        tracking_enabled TINYINT(1) NOT NULL DEFAULT 0,
        battery_pct INT DEFAULT NULL,
        network_type VARCHAR(30) DEFAULT NULL,
        location_mode VARCHAR(20) DEFAULT NULL,
        last_accuracy_m DECIMAL(10,2) DEFAULT NULL,
        last_speed_mps DECIMAL(10,2) DEFAULT NULL,
        last_heading_deg DECIMAL(10,2) DEFAULT NULL,
        last_altitude_m DECIMAL(10,2) DEFAULT NULL,
        last_lat DECIMAL(11,8) DEFAULT NULL,
        last_lng DECIMAL(11,8) DEFAULT NULL,
        last_fix_at DATETIME DEFAULT NULL,
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
        KEY idx_last_fix (last_fix_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_mobile_track_positions (
        id BIGINT NOT NULL AUTO_INCREMENT,
        device_id BIGINT NOT NULL,
        id_empresa INT NOT NULL,
        id_login INT NOT NULL,
        lat DECIMAL(11,8) NOT NULL,
        lng DECIMAL(11,8) NOT NULL,
        accuracy_m DECIMAL(10,2) DEFAULT NULL,
        speed_mps DECIMAL(10,2) DEFAULT NULL,
        heading_deg DECIMAL(10,2) DEFAULT NULL,
        altitude_m DECIMAL(10,2) DEFAULT NULL,
        battery_pct INT DEFAULT NULL,
        is_mock TINYINT(1) NOT NULL DEFAULT 0,
        provider VARCHAR(30) DEFAULT NULL,
        captured_at DATETIME NOT NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        raw_payload JSON DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_device_time (device_id, captured_at),
        KEY idx_empresa_time (id_empresa, captured_at),
        KEY idx_login_time (id_login, captured_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_mobile_track_agent_tokens (
        id BIGINT NOT NULL AUTO_INCREMENT,
        device_id BIGINT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_token_hash (token_hash),
        KEY idx_device_expires (device_id, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_mobile_track_bootstrap_tokens (
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

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM " . MASTER_DB . ".smx_mobile_track_devices")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $cols = array_map('strtolower', array_map('strval', $cols));
        if (!in_array('marker_label', $cols, true)) {
            $pdo->exec("ALTER TABLE " . MASTER_DB . ".smx_mobile_track_devices ADD COLUMN marker_label VARCHAR(24) DEFAULT NULL AFTER device_name");
        }
        if (!in_array('icon_type', $cols, true)) {
            $pdo->exec("ALTER TABLE " . MASTER_DB . ".smx_mobile_track_devices ADD COLUMN icon_type VARCHAR(24) NOT NULL DEFAULT 'auto' AFTER device_name");
        }
        if (!in_array('icon_color', $cols, true)) {
            $pdo->exec("ALTER TABLE " . MASTER_DB . ".smx_mobile_track_devices ADD COLUMN icon_color VARCHAR(24) NOT NULL DEFAULT '' AFTER icon_type");
        }
    } catch (Throwable $e) {
        // Keep API usable even if schema migration cannot run automatically.
    }

    smxMobileEnsureIosPushTable($pdo, MASTER_DB);

    $done = true;
}

function mtNormalizeIconType(string $value): string
{
    $value = strtolower(trim($value));
    $allowed = ['auto', 'moto', 'auto_car', 'sedan', 'suv', 'pickup', 'furgon', 'camion'];
    return in_array($value, $allowed, true) ? $value : 'auto';
}

function mtNormalizeIconColor(string $value): string
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return '';
    }
    if (preg_match('/^#[0-9a-f]{6}$/', $value)) {
        return $value;
    }
    $map = [
        'cyan' => '#06b6d4',
        'blue' => '#2563eb',
        'emerald' => '#10b981',
        'amber' => '#f59e0b',
        'red' => '#ef4444',
        'violet' => '#8b5cf6',
        'slate' => '#475569',
        'black' => '#111827',
    ];
    return $map[$value] ?? '';
}

function mtNormalizeMarkerLabel(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $value = mb_substr($value, 0, 24, 'UTF-8');
    return preg_replace('/\s+/', ' ', $value);
}

function mtCreateAgentToken(PDO $pdo, int $deviceId): array
{
    $plainToken = mtGenerateToken();
    $expiresAt = time() + (90 * 86400);
    $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_mobile_track_agent_tokens
        (device_id, token_hash, expires_at)
        VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 90 DAY))");
    $stmt->execute([$deviceId, mtHashToken($plainToken)]);

    return [
        'device_id' => $deviceId,
        'agent_token' => $plainToken,
        'expires_at' => gmdate('c', $expiresAt),
    ];
}

function mtResolveAgentToken(PDO $pdo, string $plainToken): ?array
{
    if ($plainToken === '') {
        return null;
    }
    $st = $pdo->prepare("
        SELECT
            t.device_id,
            d.id_empresa,
            d.id_login,
            d.device_uuid,
            d.device_name,
            d.user_name,
            d.company_name
        FROM " . MASTER_DB . ".smx_mobile_track_agent_tokens t
        INNER JOIN " . MASTER_DB . ".smx_mobile_track_devices d ON d.id = t.device_id
        WHERE t.token_hash = :token_hash
          AND t.revoked_at IS NULL
          AND t.expires_at >= UTC_TIMESTAMP()
        ORDER BY t.id DESC
        LIMIT 1
    ");
    $st->execute([':token_hash' => mtHashToken($plainToken)]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    return $row ?: null;
}

function mtEnsureUserNotificationsTable(PDO $pdo): void
{
    $pdo->exec("
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

function mtFetchAgentAlerts(PDO $pdo, array $agent, int $sinceId, int $limit): array
{
    mtEnsureUserNotificationsTable($pdo);
    $limit = max(1, min(20, $limit));
    $sinceId = max(0, $sinceId);
    $sql = "
        SELECT
            id,
            tipo,
            titulo,
            mensaje,
            url,
            leida,
            fecha_creacion
        FROM " . MASTER_DB . ".smx_user_notifications
        WHERE id_empresa = :id_empresa
          AND id_dest_login = :id_login
          AND id > :since_id
          AND (
                COALESCE(url, '') = '/public/pos/autorizaciones.php'
                OR titulo LIKE '%autoriz%'
                OR mensaje LIKE '%autoriz%'
              )
          AND fecha_creacion >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)
        ORDER BY id ASC
        LIMIT {$limit}
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':id_empresa' => (int)($agent['id_empresa'] ?? 0),
        ':id_login' => (int)($agent['id_login'] ?? 0),
        ':since_id' => $sinceId,
    ]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $maxId = $sinceId;
    foreach ($items as $row) {
        $maxId = max($maxId, (int)($row['id'] ?? 0));
    }
    return [
        'items' => array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'type' => (string)($row['tipo'] ?? 'info'),
                'title' => (string)($row['titulo'] ?? 'Autorizacion pendiente'),
                'body' => (string)($row['mensaje'] ?? ''),
                'url' => (string)($row['url'] ?? '/public/pos/autorizaciones.php'),
                'read' => (int)($row['leida'] ?? 0) === 1,
                'created_at' => (string)($row['fecha_creacion'] ?? ''),
            ];
        }, $items),
        'max_id' => $maxId,
    ];
}

function mtConsumeBootstrapToken(PDO $pdo, string $plainToken): ?array
{
    if ($plainToken === '') {
        return null;
    }
    $st = $pdo->prepare("
        SELECT id, id_empresa, id_login, login_name, user_name
        FROM " . MASTER_DB . ".smx_mobile_track_bootstrap_tokens
        WHERE token_hash = :token_hash
          AND expires_at >= UTC_TIMESTAMP()
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([':token_hash' => mtHashToken($plainToken)]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return null;
    }
    $upd = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_mobile_track_bootstrap_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?");
    $upd->execute([(int)$row['id']]);
    return $row;
}

function mtUpsertDevice(PDO $pdo, array $ctx, array $fields): int
{
    $companyName = mtCompanyName($pdo, (int)$ctx['id_empresa']);
    $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_mobile_track_devices
        (id_empresa, id_login, login_name, user_name, company_name, device_uuid, device_name, marker_label, icon_type, icon_color, host_name, platform, platform_version, architecture, app_version, tracking_enabled, status, last_seen_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'online', UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            id_empresa = VALUES(id_empresa),
            id_login = VALUES(id_login),
            login_name = VALUES(login_name),
            user_name = VALUES(user_name),
            company_name = VALUES(company_name),
            device_name = VALUES(device_name),
            marker_label = VALUES(marker_label),
            icon_type = VALUES(icon_type),
            icon_color = VALUES(icon_color),
            host_name = VALUES(host_name),
            platform = VALUES(platform),
            platform_version = VALUES(platform_version),
            architecture = VALUES(architecture),
            app_version = VALUES(app_version),
            tracking_enabled = VALUES(tracking_enabled),
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
        mtNormalizeMarkerLabel((string)($fields['marker_label'] ?? '')),
        mtNormalizeIconType((string)($fields['icon_type'] ?? 'auto')),
        mtNormalizeIconColor((string)($fields['icon_color'] ?? '')),
        $fields['host_name'] !== '' ? (string)$fields['host_name'] : null,
        (string)$fields['platform'],
        $fields['platform_version'] !== '' ? (string)$fields['platform_version'] : null,
        $fields['architecture'] !== '' ? (string)$fields['architecture'] : null,
        $fields['app_version'] !== '' ? (string)$fields['app_version'] : null,
        !empty($fields['tracking_enabled']) ? 1 : 0,
    ]);

    $idStmt = $pdo->prepare("SELECT id FROM " . MASTER_DB . ".smx_mobile_track_devices WHERE device_uuid = ? LIMIT 1");
    $idStmt->execute([(string)$fields['device_uuid']]);
    return (int)($idStmt->fetchColumn() ?: 0);
}

function mtUpdateHeartbeat(PDO $pdo, int $deviceId, array $payload): void
{
    $st = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_mobile_track_devices
        SET status = :status,
            tracking_enabled = :tracking_enabled,
            marker_label = :marker_label,
            icon_type = :icon_type,
            icon_color = :icon_color,
            ip_local = :ip_local,
            ip_public = :ip_public,
            battery_pct = :battery_pct,
            network_type = :network_type,
            location_mode = :location_mode,
            last_seen_at = UTC_TIMESTAMP()
        WHERE id = :device_id");
    $st->execute([
        ':status' => (string)($payload['status'] ?? 'online'),
        ':tracking_enabled' => !empty($payload['tracking_enabled']) ? 1 : 0,
        ':marker_label' => mtNormalizeMarkerLabel((string)($payload['marker_label'] ?? '')),
        ':icon_type' => mtNormalizeIconType((string)($payload['icon_type'] ?? 'auto')),
        ':icon_color' => mtNormalizeIconColor((string)($payload['icon_color'] ?? '')),
        ':ip_local' => ($payload['ip_local'] ?? '') !== '' ? (string)$payload['ip_local'] : null,
        ':ip_public' => ($payload['ip_public'] ?? '') !== '' ? (string)$payload['ip_public'] : null,
        ':battery_pct' => isset($payload['battery_pct']) ? (int)$payload['battery_pct'] : null,
        ':network_type' => ($payload['network_type'] ?? '') !== '' ? (string)$payload['network_type'] : null,
        ':location_mode' => ($payload['location_mode'] ?? '') !== '' ? (string)$payload['location_mode'] : null,
        ':device_id' => $deviceId,
    ]);
}

function mtInsertPosition(PDO $pdo, array $agent, array $payload): int
{
    $capturedAt = trim((string)($payload['captured_at'] ?? ''));
    if ($capturedAt === '') {
        $capturedAt = gmdate('Y-m-d H:i:s');
    } else {
        try {
            $capturedAt = (new DateTimeImmutable($capturedAt))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $capturedAt = gmdate('Y-m-d H:i:s');
        }
    }
    $rawPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $st = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_mobile_track_positions
        (device_id, id_empresa, id_login, lat, lng, accuracy_m, speed_mps, heading_deg, altitude_m, battery_pct, is_mock, provider, captured_at, raw_payload)
        VALUES
        (:device_id, :id_empresa, :id_login, :lat, :lng, :accuracy_m, :speed_mps, :heading_deg, :altitude_m, :battery_pct, :is_mock, :provider, :captured_at, :raw_payload)");
    $st->execute([
        ':device_id' => (int)$agent['device_id'],
        ':id_empresa' => (int)$agent['id_empresa'],
        ':id_login' => (int)$agent['id_login'],
        ':lat' => (float)$payload['lat'],
        ':lng' => (float)$payload['lng'],
        ':accuracy_m' => isset($payload['accuracy_m']) ? (float)$payload['accuracy_m'] : null,
        ':speed_mps' => isset($payload['speed_mps']) ? (float)$payload['speed_mps'] : null,
        ':heading_deg' => isset($payload['heading_deg']) ? (float)$payload['heading_deg'] : null,
        ':altitude_m' => isset($payload['altitude_m']) ? (float)$payload['altitude_m'] : null,
        ':battery_pct' => isset($payload['battery_pct']) ? (int)$payload['battery_pct'] : null,
        ':is_mock' => !empty($payload['is_mock']) ? 1 : 0,
        ':provider' => ($payload['provider'] ?? '') !== '' ? (string)$payload['provider'] : null,
        ':captured_at' => $capturedAt,
        ':raw_payload' => $rawPayload !== false ? $rawPayload : null,
    ]);

    $upd = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_mobile_track_devices
        SET last_lat = :lat,
            last_lng = :lng,
            last_accuracy_m = :accuracy_m,
            last_speed_mps = :speed_mps,
            last_heading_deg = :heading_deg,
            last_altitude_m = :altitude_m,
            battery_pct = :battery_pct,
            tracking_enabled = 1,
            status = 'tracking',
            last_fix_at = :captured_at,
            last_seen_at = UTC_TIMESTAMP()
        WHERE id = :device_id");
    $upd->execute([
        ':lat' => (float)$payload['lat'],
        ':lng' => (float)$payload['lng'],
        ':accuracy_m' => isset($payload['accuracy_m']) ? (float)$payload['accuracy_m'] : null,
        ':speed_mps' => isset($payload['speed_mps']) ? (float)$payload['speed_mps'] : null,
        ':heading_deg' => isset($payload['heading_deg']) ? (float)$payload['heading_deg'] : null,
        ':altitude_m' => isset($payload['altitude_m']) ? (float)$payload['altitude_m'] : null,
        ':battery_pct' => isset($payload['battery_pct']) ? (int)$payload['battery_pct'] : null,
        ':captured_at' => $capturedAt,
        ':device_id' => (int)$agent['device_id'],
    ]);

    return (int)$pdo->lastInsertId();
}

try {
    $pdo = mtPdo();
    mtEnsureSchema($pdo);
    $action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? '')));

    if ($action === 'issue_bootstrap_token') {
        $ctx = mtResolveRequestContext();
        $ttlMinutes = max(10, min(1440, (int)($_GET['ttl_minutes'] ?? $_POST['ttl_minutes'] ?? 60)));
        $plainToken = mtGenerateToken();
        $st = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_mobile_track_bootstrap_tokens
            (id_empresa, id_login, login_name, user_name, token_hash, expires_at)
            VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$ttlMinutes} MINUTE))");
        $st->execute([
            (int)$ctx['id_empresa'],
            (int)$ctx['id_login'],
            (string)$ctx['login'],
            (string)$ctx['user_name'],
            mtHashToken($plainToken),
        ]);

        mtJson([
            'ok' => true,
            'data' => [
                'bootstrap_token' => $plainToken,
                'expires_at' => gmdate('c', time() + ($ttlMinutes * 60)),
                'api_url' => '/public/api/mobile_tracking.php',
            ],
        ]);
    }

    if ($action === 'issue_setup_token') {
        $ctx = mtRequireLoginContext();
        mtJson([
            'ok' => true,
            'data' => [
                'setup_token' => mtCreateSetupToken($ctx, 900),
                'expires_at' => gmdate('c', time() + 900),
            ],
        ]);
    }

    if ($action === 'ios_issue_bootstrap_token') {
        $ctx = mtResolveRequestContext();
        mtJson([
            'ok' => true,
            'data' => [
                'setup_token' => mtCreateSetupToken($ctx, 900),
                'expires_at' => gmdate('c', time() + 900),
                'platform' => 'ios',
                'api_url' => '/public/api/mobile_tracking.php',
            ],
        ]);
    }

    if ($action === 'agent_register_bootstrap') {
        $input = mtReadJsonInput();
        $bootstrapToken = trim((string)($input['bootstrap_token'] ?? ''));
        $deviceUuid = trim((string)($input['device_uuid'] ?? ''));
        $deviceName = trim((string)($input['device_name'] ?? ''));
        if ($bootstrapToken === '' || $deviceUuid === '' || $deviceName === '') {
            mtJson(['ok' => false, 'error' => 'Datos de registro incompletos'], 422);
        }
        $ctx = mtConsumeBootstrapToken($pdo, $bootstrapToken);
        if (!$ctx) {
            mtJson(['ok' => false, 'error' => 'Bootstrap token inválido o vencido'], 403);
        }

        $fields = [
            'device_uuid' => $deviceUuid,
            'device_name' => $deviceName,
            'marker_label' => trim((string)($input['marker_label'] ?? '')),
            'icon_type' => trim((string)($input['icon_type'] ?? 'auto')),
            'icon_color' => trim((string)($input['icon_color'] ?? '')),
            'host_name' => trim((string)($input['host_name'] ?? '')),
            'platform' => trim((string)($input['platform'] ?? 'android')) ?: 'android',
            'platform_version' => trim((string)($input['platform_version'] ?? '')),
            'architecture' => trim((string)($input['architecture'] ?? '')),
            'app_version' => trim((string)($input['app_version'] ?? '')),
            'tracking_enabled' => !empty($input['tracking_enabled']),
        ];
        $deviceId = mtUpsertDevice($pdo, $ctx, $fields);
        $token = mtCreateAgentToken($pdo, $deviceId);
        mtJson(['ok' => true, 'data' => $token]);
    }

    if ($action === 'ios_register_bootstrap') {
        $input = mtReadJsonInput();
        $bootstrapToken = trim((string)($input['bootstrap_token'] ?? ''));
        $deviceUuid = trim((string)($input['device_uuid'] ?? ''));
        $deviceName = trim((string)($input['device_name'] ?? ''));
        if ($bootstrapToken === '' || $deviceUuid === '' || $deviceName === '') {
            mtJson(['ok' => false, 'error' => 'Datos de registro incompletos'], 422);
        }
        $ctx = mtConsumeBootstrapToken($pdo, $bootstrapToken);
        if (!$ctx) {
            mtJson(['ok' => false, 'error' => 'Bootstrap token inválido o vencido'], 403);
        }

        $fields = [
            'device_uuid' => $deviceUuid,
            'device_name' => $deviceName,
            'marker_label' => '',
            'icon_type' => 'auto',
            'icon_color' => '',
            'host_name' => trim((string)($input['host_name'] ?? 'iPhone')),
            'platform' => trim((string)($input['platform'] ?? 'ios')) ?: 'ios',
            'platform_version' => trim((string)($input['platform_version'] ?? '')),
            'architecture' => trim((string)($input['architecture'] ?? 'arm64')),
            'app_version' => trim((string)($input['app_version'] ?? '')),
            'tracking_enabled' => false,
        ];
        $deviceId = mtUpsertDevice($pdo, $ctx, $fields);
        $token = mtCreateAgentToken($pdo, $deviceId);
        mtJson(['ok' => true, 'data' => $token + ['platform' => 'ios']]);
    }

    $bearer = mtGetBearerToken();
    $agent = mtResolveAgentToken($pdo, $bearer);

    if ($action === 'agent_heartbeat') {
        if (!$agent) {
            mtJson(['ok' => false, 'error' => 'Token inválido'], 401);
        }
        $input = mtReadJsonInput();
        mtUpdateHeartbeat($pdo, (int)$agent['device_id'], $input);
        mtJson(['ok' => true, 'data' => ['device_id' => (int)$agent['device_id'], 'server_time' => gmdate('c')]]);
    }

    if ($action === 'agent_location_ping') {
        if (!$agent) {
            mtJson(['ok' => false, 'error' => 'Token inválido'], 401);
        }
        $input = mtReadJsonInput();
        if (!isset($input['lat'], $input['lng'])) {
            mtJson(['ok' => false, 'error' => 'Lat/Lng requeridos'], 422);
        }
        $positionId = mtInsertPosition($pdo, $agent, $input);
        mtJson(['ok' => true, 'data' => ['position_id' => $positionId, 'server_time' => gmdate('c')]]);
    }

    if ($action === 'agent_status') {
        if (!$agent) {
            mtJson(['ok' => false, 'error' => 'Token inválido'], 401);
        }
        mtJson(['ok' => true, 'data' => $agent]);
    }

    if ($action === 'ios_status') {
        if (!$agent) {
            mtJson(['ok' => false, 'error' => 'Token inválido'], 401);
        }
        mtJson(['ok' => true, 'data' => $agent + ['platform' => 'ios']]);
    }

    if ($action === 'agent_fcm_register') {
        if (!$agent) {
            mtJson(['ok' => false, 'error' => 'Token inválido'], 401);
        }
        $input = mtReadJsonInput();
        $fcmToken = trim((string)($input['fcm_token'] ?? ''));
        if ($fcmToken === '') {
            mtJson(['ok' => false, 'error' => 'FCM token requerido'], 422);
        }
        $registerMeta = smxMobileRegisterFcmToken(
            $pdo,
            MASTER_DB,
            $agent,
            $fcmToken,
            (string)($input['platform'] ?? 'android'),
            (string)($input['app_version'] ?? '')
        );
        mtJson(['ok' => true, 'data' => [
            'registered' => true,
            'fcm_enabled' => smxMobileFcmEnabled(),
            'token_id' => (int)($registerMeta['token_id'] ?? 0),
            'active_tokens' => (int)($registerMeta['active_tokens'] ?? 0),
        ]]);
    }

    if ($action === 'ios_push_register') {
        if (!$agent) {
            mtJson(['ok' => false, 'error' => 'Token inválido'], 401);
        }
        $input = mtReadJsonInput();
        $apnsToken = trim((string)($input['apns_token'] ?? $input['fcm_token'] ?? ''));
        if ($apnsToken === '') {
            mtJson(['ok' => false, 'error' => 'APNs token requerido'], 422);
        }
        $registerMeta = smxMobileRegisterIosPushToken(
            $pdo,
            MASTER_DB,
            $agent,
            $apnsToken,
            (string)($input['platform'] ?? 'ios'),
            (string)($input['app_version'] ?? '')
        );
        mtJson(['ok' => true, 'data' => [
            'registered' => true,
            'fcm_enabled' => smxMobileFcmEnabled(),
            'token_id' => (int)($registerMeta['token_id'] ?? 0),
            'active_tokens' => (int)($registerMeta['active_tokens'] ?? 0),
            'platform' => 'ios',
        ]]);
    }

    if ($action === 'agent_alerts_poll') {
        if (!$agent) {
            mtJson(['ok' => false, 'error' => 'Token inválido'], 401);
        }
        $input = mtReadJsonInput();
        $sinceId = (int)($input['since_id'] ?? $_GET['since_id'] ?? 0);
        $limit = (int)($input['limit'] ?? $_GET['limit'] ?? 10);
        mtJson([
            'ok' => true,
            'data' => mtFetchAgentAlerts($pdo, $agent, $sinceId, $limit),
        ]);
    }

    if ($action === 'latest_positions') {
        $ctx = mtRequireLoginContext();
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $sql = "
            SELECT
                d.id,
                d.id_empresa,
                d.company_name,
                d.id_login,
                d.user_name,
                d.device_name,
                d.marker_label,
                d.icon_type,
                d.icon_color,
                d.platform,
                d.app_version,
                d.status,
                d.tracking_enabled,
                d.last_lat,
                d.last_lng,
                d.last_accuracy_m,
                d.last_speed_mps,
                d.last_heading_deg,
                d.last_altitude_m,
                d.battery_pct,
                d.network_type,
                d.last_fix_at,
                d.last_seen_at
            FROM " . MASTER_DB . ".smx_mobile_track_devices d
            WHERE d.last_fix_at IS NOT NULL
              AND d.id_empresa = :id_empresa
        ";
        $params = [':id_empresa' => (int)$ctx['id_empresa']];
        $sql .= " ORDER BY d.last_fix_at DESC LIMIT " . (int)$limit;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        mtJson(['ok' => true, 'data' => ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]]);
    }

    if ($action === 'delete_device') {
        $ctx = mtRequireLoginContext();
        $input = mtReadJsonInput();
        $deviceId = (int)($input['device_id'] ?? $_POST['device_id'] ?? $_GET['device_id'] ?? 0);
        if ($deviceId <= 0) {
            mtJson(['ok' => false, 'error' => 'Dispositivo inválido'], 422);
        }

        $sqlDevice = "
            SELECT id, device_name, id_login, id_empresa
            FROM " . MASTER_DB . ".smx_mobile_track_devices
            WHERE id = :id
              AND id_empresa = :id_empresa
        ";
        $paramsDevice = [
            ':id' => $deviceId,
            ':id_empresa' => (int)$ctx['id_empresa'],
        ];
        $sqlDevice .= " LIMIT 1";
        $stDevice = $pdo->prepare($sqlDevice);
        $stDevice->execute($paramsDevice);
        $device = $stDevice->fetch(PDO::FETCH_ASSOC);
        if (!$device) {
            mtJson(['ok' => false, 'error' => 'Dispositivo no encontrado'], 404);
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM " . MASTER_DB . ".smx_mobile_track_positions WHERE device_id = :id")
                ->execute([':id' => $deviceId]);
            $pdo->prepare("DELETE FROM " . MASTER_DB . ".smx_mobile_track_agent_tokens WHERE device_id = :id")
                ->execute([':id' => $deviceId]);
            $pdo->prepare("DELETE FROM " . MASTER_DB . ".smx_mobile_track_fcm_tokens WHERE device_id = :id")
                ->execute([':id' => $deviceId]);
            $pdo->prepare("DELETE FROM " . MASTER_DB . ".smx_mobile_track_devices WHERE id = :id")
                ->execute([':id' => $deviceId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        mtJson([
            'ok' => true,
            'data' => [
                'deleted' => true,
                'device_id' => $deviceId,
                'device_name' => (string)($device['device_name'] ?? ''),
            ],
        ]);
    }

    mtJson(['ok' => false, 'error' => 'Acción inválida'], 404);
} catch (Throwable $e) {
    mtJson(['ok' => false, 'error' => $e->getMessage()], 500);
}
