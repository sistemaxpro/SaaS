<?php
require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
Session::requireLogin('/public/login.php');

function supportDesktopJson(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function supportDesktopPdo(): PDO
{
    return Database::getMasterConnection();
}

function supportDesktopEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_support_desktop_requests (
        id BIGINT NOT NULL AUTO_INCREMENT,
        id_empresa INT NOT NULL,
        id_login INT NOT NULL,
        login_name VARCHAR(120) DEFAULT NULL,
        user_name VARCHAR(180) DEFAULT NULL,
        platform VARCHAR(30) DEFAULT NULL,
        app_version VARCHAR(50) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        notes TEXT DEFAULT NULL,
        remote_tool VARCHAR(30) DEFAULT NULL,
        remote_id VARCHAR(120) DEFAULT NULL,
        remote_password VARCHAR(120) DEFAULT NULL,
        remote_host VARCHAR(190) DEFAULT NULL,
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
        KEY idx_login_status (id_login, status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_user_notifications (
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
        INDEX idx_empresa_fecha (id_empresa, fecha_creacion)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_support_desktop_settings (
        setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value LONGTEXT NULL,
        updated_by_login INT NULL,
        updated_by_name VARCHAR(180) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_support_endpoints (
        id BIGINT NOT NULL AUTO_INCREMENT,
        id_empresa INT NOT NULL,
        id_login INT NOT NULL,
        login_name VARCHAR(120) DEFAULT NULL,
        user_name VARCHAR(180) DEFAULT NULL,
        company_name VARCHAR(190) DEFAULT NULL,
        host_name VARCHAR(190) DEFAULT NULL,
        platform VARCHAR(40) DEFAULT NULL,
        agent_version VARCHAR(50) DEFAULT NULL,
        remote_version VARCHAR(50) DEFAULT NULL,
        remote_id VARCHAR(120) DEFAULT NULL,
        remote_password_enc TEXT DEFAULT NULL,
        remote_host VARCHAR(190) DEFAULT NULL,
        rustdesk_version VARCHAR(50) DEFAULT NULL,
        rustdesk_id VARCHAR(120) DEFAULT NULL,
        rustdesk_password_enc TEXT DEFAULT NULL,
        rustdesk_host VARCHAR(190) DEFAULT NULL,
        permissions_screen TINYINT(1) NOT NULL DEFAULT 0,
        permissions_accessibility TINYINT(1) NOT NULL DEFAULT 0,
        agent_online TINYINT(1) NOT NULL DEFAULT 0,
        remote_installed TINYINT(1) NOT NULL DEFAULT 0,
        remote_running TINYINT(1) NOT NULL DEFAULT 0,
        rustdesk_installed TINYINT(1) NOT NULL DEFAULT 0,
        rustdesk_running TINYINT(1) NOT NULL DEFAULT 0,
        install_verified_at DATETIME DEFAULT NULL,
        last_seen DATETIME DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_empresa_login_host (id_empresa, id_login, host_name),
        KEY idx_company_seen (id_empresa, last_seen),
        KEY idx_online_seen (agent_online, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $optionalColumns = [
        'remote_tool' => "ALTER TABLE " . MASTER_DB . ".smx_support_desktop_requests ADD COLUMN remote_tool VARCHAR(30) DEFAULT NULL AFTER notes",
        'remote_id' => "ALTER TABLE " . MASTER_DB . ".smx_support_desktop_requests ADD COLUMN remote_id VARCHAR(120) DEFAULT NULL AFTER remote_tool",
        'remote_password' => "ALTER TABLE " . MASTER_DB . ".smx_support_desktop_requests ADD COLUMN remote_password VARCHAR(120) DEFAULT NULL AFTER remote_id",
        'remote_host' => "ALTER TABLE " . MASTER_DB . ".smx_support_desktop_requests ADD COLUMN remote_host VARCHAR(190) DEFAULT NULL AFTER remote_password",
        'endpoint_remote_version' => "ALTER TABLE " . MASTER_DB . ".smx_support_endpoints ADD COLUMN remote_version VARCHAR(50) DEFAULT NULL AFTER agent_version",
        'endpoint_remote_id' => "ALTER TABLE " . MASTER_DB . ".smx_support_endpoints ADD COLUMN remote_id VARCHAR(120) DEFAULT NULL AFTER remote_version",
        'endpoint_remote_password_enc' => "ALTER TABLE " . MASTER_DB . ".smx_support_endpoints ADD COLUMN remote_password_enc TEXT DEFAULT NULL AFTER remote_id",
        'endpoint_remote_host' => "ALTER TABLE " . MASTER_DB . ".smx_support_endpoints ADD COLUMN remote_host VARCHAR(190) DEFAULT NULL AFTER remote_password_enc",
        'endpoint_remote_installed' => "ALTER TABLE " . MASTER_DB . ".smx_support_endpoints ADD COLUMN remote_installed TINYINT(1) NOT NULL DEFAULT 0 AFTER agent_online",
        'endpoint_remote_running' => "ALTER TABLE " . MASTER_DB . ".smx_support_endpoints ADD COLUMN remote_running TINYINT(1) NOT NULL DEFAULT 0 AFTER remote_installed",
    ];
    $columnStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    foreach ($optionalColumns as $column => $alterSql) {
        $tableName = str_starts_with($column, 'endpoint_') ? 'smx_support_endpoints' : 'smx_support_desktop_requests';
        $columnName = str_starts_with($column, 'endpoint_') ? preg_replace('/^endpoint_/', '', $column) : $column;
        $columnStmt->execute([MASTER_DB, $tableName, $columnName]);
        if ((int)$columnStmt->fetchColumn() === 0) {
            $pdo->exec($alterSql);
        }
    }
    $pdo->exec("UPDATE " . MASTER_DB . ".smx_support_endpoints
        SET remote_version = COALESCE(NULLIF(remote_version, ''), rustdesk_version),
            remote_id = COALESCE(NULLIF(remote_id, ''), rustdesk_id),
            remote_password_enc = COALESCE(NULLIF(remote_password_enc, ''), rustdesk_password_enc),
            remote_host = COALESCE(NULLIF(remote_host, ''), rustdesk_host),
            remote_installed = IF(remote_installed = 0, rustdesk_installed, remote_installed),
            remote_running = IF(remote_running = 0, rustdesk_running, remote_running)");
    $done = true;
}

function supportDesktopIsSupportAccess(array $ctx): bool
{
    return in_array((int)$ctx['id_empresa'], SISTEMAX_SUPPORT_COMPANIES, true);
}

function supportDesktopRequireSupport(array $ctx): void
{
    if (!supportDesktopIsSupportAccess($ctx)) {
        supportDesktopJson(['ok' => false, 'error' => 'Acceso reservado a soporte'], 403);
    }
}

function supportDesktopNotifySupport(PDO $pdo, array $ctx, int $requestId): void
{
    $supportCompanies = SISTEMAX_SUPPORT_COMPANIES;
    $stmt = $pdo->prepare("SELECT id_login FROM " . MASTER_DB . ".sec_users
        WHERE id_empresa IN (" . implode(',', $supportCompanies) . ")
          AND active = 'Y'
          AND (UPPER(COALESCE(priv_admin, 'N')) = 'Y' OR LOWER(COALESCE(role, '')) LIKE '%admin%')");
    $stmt->execute();
    $destinos = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!$destinos) {
        return;
    }

    $empresaStmt = $pdo->prepare("SELECT empresa FROM " . MASTER_DB . ".empresa WHERE id_empresa = ? LIMIT 1");
    $empresaStmt->execute([(int)$ctx['id_empresa']]);
    $empresaNombre = (string)($empresaStmt->fetchColumn() ?: ('Empresa #' . (int)$ctx['id_empresa']));

    $insert = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_user_notifications
        (id_empresa, id_dest_login, id_remitente_login, tipo, titulo, mensaje, url)
        VALUES (?, ?, ?, 'warning', ?, ?, ?)");
    $titulo = 'Solicitud SistemaX Assist';
    $mensaje = $empresaNombre . ' · ' . ($ctx['user_name'] ?: $ctx['login']) . ' solicitó soporte remoto para instalar o configurar SistemaX Assist.';
    $url = '/public/apps-moviles.php#soporte-desktop';
    foreach ($destinos as $destLogin) {
        $insert->execute([
            (int)$ctx['id_empresa'],
            (int)$destLogin,
            (int)$ctx['id_login'],
            $titulo,
            $mensaje,
            $url,
        ]);
    }
}

function supportDesktopSecretKey(): string
{
    static $key = null;
    if (is_string($key)) {
        return $key;
    }
    $source = (string)(getenv('SISTEMAX_SUPPORT_SECRET') ?: (__DIR__ . '|' . MASTER_DB . '|support-desktop'));
    $key = hash('sha256', $source, true);
    return $key;
}

function supportDesktopEncrypt(?string $plain): ?string
{
    $plain = trim((string)$plain);
    if ($plain === '') {
        return null;
    }
    $cipher = 'aes-256-cbc';
    $ivLen = openssl_cipher_iv_length($cipher);
    $iv = random_bytes($ivLen);
    $enc = openssl_encrypt($plain, $cipher, supportDesktopSecretKey(), OPENSSL_RAW_DATA, $iv);
    if (!is_string($enc) || $enc === '') {
        return null;
    }
    return base64_encode($iv . $enc);
}

function supportDesktopDecrypt(?string $encoded): string
{
    $encoded = trim((string)$encoded);
    if ($encoded === '') {
        return '';
    }
    $raw = base64_decode($encoded, true);
    if (!is_string($raw) || $raw === '') {
        return '';
    }
    $cipher = 'aes-256-cbc';
    $ivLen = openssl_cipher_iv_length($cipher);
    if (strlen($raw) <= $ivLen) {
        return '';
    }
    $iv = substr($raw, 0, $ivLen);
    $payload = substr($raw, $ivLen);
    $plain = openssl_decrypt($payload, $cipher, supportDesktopSecretKey(), OPENSSL_RAW_DATA, $iv);
    return is_string($plain) ? $plain : '';
}

function supportDesktopCompanyName(PDO $pdo, int $idEmpresa): string
{
    static $cache = [];
    if (isset($cache[$idEmpresa])) {
        return $cache[$idEmpresa];
    }
    $stmt = $pdo->prepare("SELECT empresa FROM " . MASTER_DB . ".empresa WHERE id_empresa = ? LIMIT 1");
    $stmt->execute([$idEmpresa]);
    $name = (string)($stmt->fetchColumn() ?: ('Empresa #' . $idEmpresa));
    $cache[$idEmpresa] = $name;
    return $name;
}

function supportDesktopFormatEndpoint(array $row): array
{
    $row['id'] = (int)($row['id'] ?? 0);
    $row['id_empresa'] = (int)($row['id_empresa'] ?? 0);
    $row['id_login'] = (int)($row['id_login'] ?? 0);
    $row['agent_online'] = (int)($row['agent_online'] ?? 0);
    $row['remote_installed'] = (int)($row['remote_installed'] ?? $row['rustdesk_installed'] ?? 0);
    $row['remote_running'] = (int)($row['remote_running'] ?? $row['rustdesk_running'] ?? 0);
    $row['permissions_screen'] = (int)($row['permissions_screen'] ?? 0);
    $row['permissions_accessibility'] = (int)($row['permissions_accessibility'] ?? 0);
    $row['remote_version'] = (string)($row['remote_version'] ?? $row['rustdesk_version'] ?? '');
    $row['remote_id'] = (string)($row['remote_id'] ?? $row['rustdesk_id'] ?? '');
    $row['remote_password'] = supportDesktopDecrypt((string)($row['remote_password_enc'] ?? $row['rustdesk_password_enc'] ?? ''));
    $row['remote_host'] = (string)($row['remote_host'] ?? $row['rustdesk_host'] ?? '');
    unset($row['remote_password_enc'], $row['rustdesk_password_enc']);
    return $row;
}

function supportDesktopFormatUserRow(array $row): array
{
    $row['id_empresa'] = (int)($row['id_empresa'] ?? 0);
    $row['id_login'] = (int)($row['id_login'] ?? 0);
    $row['endpoint_id'] = (int)($row['endpoint_id'] ?? 0);
    $row['agent_online'] = (int)($row['agent_online'] ?? 0);
    $row['remote_installed'] = (int)($row['remote_installed'] ?? 0);
    $row['remote_running'] = (int)($row['remote_running'] ?? 0);
    $row['permissions_screen'] = (int)($row['permissions_screen'] ?? 0);
    $row['permissions_accessibility'] = (int)($row['permissions_accessibility'] ?? 0);
    $row['remote_password'] = supportDesktopDecrypt((string)($row['remote_password_enc'] ?? ''));
    unset($row['remote_password_enc']);
    $row['status_label'] = $row['agent_online'] ? 'Conectado' : ($row['endpoint_id'] > 0 ? 'Apagado' : 'Sin agente');
    return $row;
}

function supportDesktopLoadSettings(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM " . MASTER_DB . ".smx_support_desktop_settings");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    $encryptedKeys = [
        'support_dwservice_install_password_enc' => 'support_dwservice_install_password',
    ];
    foreach ($rows as $row) {
        $key = (string)$row['setting_key'];
        $value = (string)($row['setting_value'] ?? '');
        if (isset($encryptedKeys[$key])) {
            $out[$encryptedKeys[$key]] = supportDesktopDecrypt($value);
            continue;
        }
        $out[$key] = $value;
    }
    $defaults = supportDesktopDefaultSettings();
    $vendor = trim((string)($out['support_remote_vendor'] ?? ''));
    if ($vendor === '' || preg_match('/dwservice/i', $vendor)) {
        $out['support_remote_vendor'] = $defaults['support_remote_vendor'];
    }
    foreach ([
        'support_remote_docs_url',
        'support_remote_server_url',
        'support_remote_access_url',
        'support_desktop_windows_url',
        'support_desktop_macos_url',
        'support_desktop_linux_url',
    ] as $urlKey) {
        $value = trim((string)($out[$urlKey] ?? ''));
        if ($value === '' || preg_match('/dwservice/i', $value)) {
            $out[$urlKey] = $defaults[$urlKey] ?? '';
        }
    }
    $hostLabel = trim((string)($out['support_remote_host_label'] ?? ''));
    if (preg_match('/dwservice/i', $hostLabel)) {
        $out['support_remote_host_label'] = '';
    }
    return $out;
}

function supportDesktopDefaultSettings(): array
{
    return [
        'support_remote_vendor' => 'SistemaX Assist',
        'support_remote_docs_url' => '/public/soporte/index.php',
        'support_remote_host_label' => '',
        'support_remote_public_key' => '',
        'support_remote_server_url' => '/public/soporte/index.php',
        'support_remote_access_url' => '/public/soporte/assist_admin.php',
        'support_dwservice_username' => '',
        'support_dwservice_install_password' => '',
        'support_desktop_windows_url' => '/public/soporte/downloads/sistemax-support-desktop-windows-0.1.0.exe',
        'support_desktop_macos_url' => '/public/soporte/downloads/sistemax-support-desktop-macos-0.1.0.tar.gz',
        'support_desktop_linux_url' => '/public/soporte/downloads/sistemax-support-desktop-linux-0.1.0.tar.gz',
    ];
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
$input = is_array($input) ? $input : [];
$action = (string)($_GET['action'] ?? $_POST['action'] ?? ($input['action'] ?? ''));

try {
    $pdo = supportDesktopPdo();
    supportDesktopEnsureSchema($pdo);
    $ctx = [
        'id_empresa' => (int)Session::getIdEmpresa(),
        'id_login' => (int)Session::getIdLogin(),
        'login' => (string)($_SESSION['usuario'] ?? $_SESSION['login'] ?? ''),
        'user_name' => (string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? ($_SESSION['usuario'] ?? '')),
    ];

    if ($action === 'request_desktop_support') {
        $platform = substr(trim((string)($input['platform'] ?? 'unknown')), 0, 30);
        $appVersion = substr(trim((string)($input['version'] ?? '')), 0, 50);
        $notes = trim((string)($input['notes'] ?? ''));

        $recentStmt = $pdo->prepare("SELECT id FROM " . MASTER_DB . ".smx_support_desktop_requests
            WHERE id_empresa = ? AND id_login = ? AND status = 'pending' AND created_at >= (NOW() - INTERVAL 15 MINUTE)
            ORDER BY id DESC LIMIT 1");
        $recentStmt->execute([(int)$ctx['id_empresa'], (int)$ctx['id_login']]);
        $recentId = (int)($recentStmt->fetchColumn() ?: 0);
        if ($recentId > 0) {
            supportDesktopJson(['ok' => true, 'request_id' => $recentId, 'message' => 'Ya existe una solicitud pendiente reciente.']);
        }

        $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_support_desktop_requests
            (id_empresa, id_login, login_name, user_name, platform, app_version, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            (int)$ctx['id_empresa'],
            (int)$ctx['id_login'],
            $ctx['login'],
            $ctx['user_name'],
            $platform,
            $appVersion,
            $notes,
        ]);
        $requestId = (int)$pdo->lastInsertId();
        supportDesktopNotifySupport($pdo, $ctx, $requestId);
        supportDesktopJson(['ok' => true, 'request_id' => $requestId, 'message' => 'Solicitud enviada a soporte.']);
    }

    if ($action === 'register_endpoint') {
        $agent = is_array($input['agent'] ?? null) ? $input['agent'] : [];
        $remote = is_array($input['remote'] ?? null) ? $input['remote'] : [];
        if (!$remote) {
            $remote = is_array($input['rustdesk'] ?? null) ? $input['rustdesk'] : [];
        }
        $permissions = is_array($input['permissions'] ?? null) ? $input['permissions'] : [];
        $hostName = substr(trim((string)($agent['host_name'] ?? $input['host_name'] ?? '')), 0, 190);
        $platform = substr(trim((string)($agent['platform'] ?? $input['platform'] ?? 'unknown')), 0, 40);
        $agentVersion = substr(trim((string)($agent['version'] ?? $input['agent_version'] ?? '')), 0, 50);
        $remoteVersion = substr(trim((string)($remote['version'] ?? $input['remote_version'] ?? $input['rustdesk_version'] ?? '')), 0, 50);
        $remoteId = substr(trim((string)($input['remote_id'] ?? $input['rustdesk_id'] ?? $remote['id'] ?? '')), 0, 120);
        $remotePassword = substr(trim((string)($input['remote_password'] ?? $input['rustdesk_password'] ?? '')), 0, 120);
        $remoteHost = substr(trim((string)($input['remote_host'] ?? $input['rustdesk_host'] ?? $remote['host'] ?? '')), 0, 190);
        $notes = trim((string)($input['notes'] ?? ''));
        $agentOnline = !empty($agent['ok']) ? 1 : 0;
        $remoteInstalled = !empty($remote['installed']) ? 1 : 0;
        $remoteRunning = !empty($remote['running']) ? 1 : 0;
        $permScreen = !empty($permissions['screen_recording']) ? 1 : 0;
        $permAccessibility = !empty($permissions['accessibility']) ? 1 : 0;
        $companyName = supportDesktopCompanyName($pdo, (int)$ctx['id_empresa']);
        if ($hostName === '') {
            $hostName = substr(trim((string)($ctx['login'] ?: ('host-' . $ctx['id_login']))), 0, 190);
        }
        $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_support_endpoints
            (id_empresa, id_login, login_name, user_name, company_name, host_name, platform, agent_version, remote_version, remote_id, remote_password_enc, remote_host, rustdesk_version, rustdesk_id, rustdesk_password_enc, rustdesk_host, permissions_screen, permissions_accessibility, agent_online, remote_installed, remote_running, rustdesk_installed, rustdesk_running, install_verified_at, last_seen, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
            ON DUPLICATE KEY UPDATE
                login_name = VALUES(login_name),
                user_name = VALUES(user_name),
                company_name = VALUES(company_name),
                platform = VALUES(platform),
                agent_version = VALUES(agent_version),
                remote_version = VALUES(remote_version),
                remote_id = VALUES(remote_id),
                remote_password_enc = CASE WHEN VALUES(remote_password_enc) IS NULL OR VALUES(remote_password_enc) = '' THEN remote_password_enc ELSE VALUES(remote_password_enc) END,
                remote_host = VALUES(remote_host),
                rustdesk_version = VALUES(rustdesk_version),
                rustdesk_id = VALUES(rustdesk_id),
                rustdesk_password_enc = CASE WHEN VALUES(rustdesk_password_enc) IS NULL OR VALUES(rustdesk_password_enc) = '' THEN rustdesk_password_enc ELSE VALUES(rustdesk_password_enc) END,
                rustdesk_host = VALUES(rustdesk_host),
                permissions_screen = VALUES(permissions_screen),
                permissions_accessibility = VALUES(permissions_accessibility),
                agent_online = VALUES(agent_online),
                remote_installed = VALUES(remote_installed),
                remote_running = VALUES(remote_running),
                rustdesk_installed = VALUES(rustdesk_installed),
                rustdesk_running = VALUES(rustdesk_running),
                install_verified_at = NOW(),
                last_seen = NOW(),
                notes = VALUES(notes)");
        $stmt->execute([
            (int)$ctx['id_empresa'],
            (int)$ctx['id_login'],
            $ctx['login'],
            $ctx['user_name'],
            $companyName,
            $hostName,
            $platform,
            $agentVersion,
            $remoteVersion,
            $remoteId,
            supportDesktopEncrypt($remotePassword),
            $remoteHost,
            $remoteVersion,
            $remoteId,
            supportDesktopEncrypt($remotePassword),
            $remoteHost,
            $permScreen,
            $permAccessibility,
            $agentOnline,
            $remoteInstalled,
            $remoteRunning,
            $remoteInstalled,
            $remoteRunning,
            $notes,
        ]);
        supportDesktopJson(['ok' => true, 'message' => 'Equipo registrado en mesa de soporte.']);
    }

    if ($action === 'admin_list') {
        supportDesktopRequireSupport($ctx);
        $status = trim((string)($_GET['status'] ?? 'open'));
        $where = "WHERE 1=1";
        if ($status === 'open') {
            $where .= " AND r.status IN ('pending','taken')";
        } elseif ($status !== '' && $status !== 'all') {
            $where .= " AND r.status = " . $pdo->quote($status);
        }
        $stmt = $pdo->query("SELECT r.*,
                e.empresa
            FROM " . MASTER_DB . ".smx_support_desktop_requests r
            LEFT JOIN " . MASTER_DB . ".empresa e ON e.id_empresa = r.id_empresa
            {$where}
            ORDER BY FIELD(r.status, 'pending', 'taken', 'resolved', 'rejected'), r.updated_at DESC, r.id DESC
            LIMIT 200");
        supportDesktopJson(['ok' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    if ($action === 'admin_endpoints') {
        supportDesktopRequireSupport($ctx);
        $q = trim((string)($_GET['q'] ?? ''));
        $where = "WHERE 1=1";
        if ($q !== '') {
            $like = $pdo->quote('%' . $q . '%');
            $where .= " AND (company_name LIKE {$like} OR user_name LIKE {$like} OR login_name LIKE {$like} OR host_name LIKE {$like} OR remote_id LIKE {$like} OR rustdesk_id LIKE {$like})";
        }
        $stmt = $pdo->query("SELECT * FROM " . MASTER_DB . ".smx_support_endpoints {$where} ORDER BY agent_online DESC, last_seen DESC, updated_at DESC LIMIT 250");
        $items = array_map('supportDesktopFormatEndpoint', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        supportDesktopJson(['ok' => true, 'items' => $items]);
    }

    if ($action === 'admin_users_list') {
        supportDesktopRequireSupport($ctx);
        $q = trim((string)($_GET['q'] ?? ''));
        $where = "WHERE UPPER(COALESCE(u.active, 'N')) = 'Y'";
        if ($q !== '') {
            $like = $pdo->quote('%' . $q . '%');
            $where .= " AND (
                COALESCE(e.empresa, '') LIKE {$like}
                OR COALESCE(u.name, '') LIKE {$like}
                OR COALESCE(u.login, '') LIKE {$like}
                OR COALESCE(se.host_name, '') LIKE {$like}
                OR COALESCE(se.remote_id, '') LIKE {$like}
            )";
        }
        $sql = "SELECT
                u.id_empresa,
                u.id_login AS id_login,
                COALESCE(u.login, '') AS login_name,
                COALESCE(NULLIF(u.name, ''), u.login, CONCAT('Usuario #', u.id_login)) AS user_name,
                COALESCE(e.empresa, CONCAT('Empresa #', u.id_empresa)) AS company_name,
                COALESCE(se.id, 0) AS endpoint_id,
                COALESCE(se.host_name, '') AS host_name,
                COALESCE(se.platform, '') AS platform,
                COALESCE(se.agent_online, 0) AS agent_online,
                COALESCE(se.remote_installed, 0) AS remote_installed,
                COALESCE(se.remote_running, 0) AS remote_running,
                COALESCE(se.permissions_screen, 0) AS permissions_screen,
                COALESCE(se.permissions_accessibility, 0) AS permissions_accessibility,
                COALESCE(se.remote_id, '') AS remote_id,
                COALESCE(se.remote_password_enc, '') AS remote_password_enc,
                COALESCE(se.remote_host, '') AS remote_host,
                COALESCE(se.last_seen, '') AS last_seen,
                COALESCE(se.install_verified_at, '') AS install_verified_at
                FROM " . MASTER_DB . ".sec_users u
            LEFT JOIN " . MASTER_DB . ".empresa e ON e.id_empresa = u.id_empresa
            LEFT JOIN " . MASTER_DB . ".smx_support_endpoints se
                ON se.id = (
                    SELECT se2.id
                    FROM " . MASTER_DB . ".smx_support_endpoints se2
                    WHERE se2.id_empresa = u.id_empresa
                      AND se2.id_login = u.id_login
                    ORDER BY se2.agent_online DESC, se2.last_seen DESC, se2.updated_at DESC, se2.id DESC
                    LIMIT 1
                )
            {$where}
            ORDER BY COALESCE(se.agent_online, 0) DESC, COALESCE(e.empresa, ''), COALESCE(u.name, ''), COALESCE(u.login, '')
            LIMIT 500";
        $stmt = $pdo->query($sql);
        $items = array_map('supportDesktopFormatUserRow', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        supportDesktopJson(['ok' => true, 'items' => $items]);
    }

    if ($action === 'admin_get_settings') {
        supportDesktopRequireSupport($ctx);
        supportDesktopJson(['ok' => true, 'settings' => array_merge(supportDesktopDefaultSettings(), supportDesktopLoadSettings($pdo))]);
    }

    if ($action === 'admin_save_settings') {
        supportDesktopRequireSupport($ctx);
        $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $allowedKeys = [
            'support_remote_vendor',
            'support_remote_docs_url',
            'support_remote_host_label',
            'support_remote_public_key',
            'support_remote_server_url',
            'support_remote_access_url',
            'support_dwservice_username',
            'support_desktop_windows_url',
            'support_desktop_macos_url',
            'support_desktop_linux_url',
        ];
        $encryptedKeys = [
            'support_dwservice_install_password' => 'support_dwservice_install_password_enc',
        ];
        $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_support_desktop_settings
            (setting_key, setting_value, updated_by_login, updated_by_name)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by_login = VALUES(updated_by_login),
                updated_by_name = VALUES(updated_by_name)");
        foreach ($allowedKeys as $key) {
            $value = trim((string)($settings[$key] ?? ''));
            $stmt->execute([$key, $value, (int)$ctx['id_login'], $ctx['user_name'] ?: $ctx['login']]);
        }
        foreach ($encryptedKeys as $inputKey => $storedKey) {
            $value = trim((string)($settings[$inputKey] ?? ''));
            $stmt->execute([$storedKey, supportDesktopEncrypt($value) ?: '', (int)$ctx['id_login'], $ctx['user_name'] ?: $ctx['login']]);
        }
        supportDesktopJson(['ok' => true, 'message' => 'Configuración guardada.']);
    }

    if ($action === 'admin_test_url') {
        supportDesktopRequireSupport($ctx);
        $url = trim((string)($_GET['url'] ?? $input['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            supportDesktopJson(['ok' => false, 'error' => 'URL inválida'], 422);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'SistemaX-SoporteDesktop/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        supportDesktopJson([
            'ok' => $error === '' && $httpCode >= 200 && $httpCode < 400,
            'http_code' => $httpCode,
            'final_url' => $finalUrl,
            'error' => $error,
        ]);
    }

    if ($action === 'admin_take') {
        supportDesktopRequireSupport($ctx);
        $id = (int)($input['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            supportDesktopJson(['ok' => false, 'error' => 'Solicitud requerida'], 422);
        }
        $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_support_desktop_requests
            SET status = 'taken',
                taken_by_login = ?,
                taken_by_name = ?,
                taken_at = COALESCE(taken_at, NOW())
            WHERE id = ? AND status IN ('pending','taken')");
        $stmt->execute([(int)$ctx['id_login'], $ctx['user_name'] ?: $ctx['login'], $id]);
        supportDesktopJson(['ok' => true]);
    }

    if ($action === 'admin_save_remote_access') {
        supportDesktopRequireSupport($ctx);
        $id = (int)($input['id'] ?? $_POST['id'] ?? 0);
        $settings = array_merge(supportDesktopDefaultSettings(), supportDesktopLoadSettings($pdo));
        $defaultVendor = substr(trim((string)($settings['support_remote_vendor'] ?? 'SistemaX Assist')), 0, 30) ?: 'SistemaX Assist';
        $remoteTool = substr(trim((string)($input['remote_tool'] ?? $_POST['remote_tool'] ?? $defaultVendor)), 0, 30);
        $remoteId = substr(trim((string)($input['remote_id'] ?? $_POST['remote_id'] ?? '')), 0, 120);
        $remotePassword = substr(trim((string)($input['remote_password'] ?? $_POST['remote_password'] ?? '')), 0, 120);
        $remoteHost = substr(trim((string)($input['remote_host'] ?? $_POST['remote_host'] ?? '')), 0, 190);
        if ($id <= 0) {
            supportDesktopJson(['ok' => false, 'error' => 'Solicitud requerida'], 422);
        }
        $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_support_desktop_requests
            SET remote_tool = ?,
                remote_id = ?,
                remote_password = ?,
                remote_host = ?,
                taken_by_login = COALESCE(taken_by_login, ?),
                taken_by_name = COALESCE(taken_by_name, ?),
                taken_at = COALESCE(taken_at, NOW()),
                status = CASE WHEN status = 'pending' THEN 'taken' ELSE status END
            WHERE id = ?");
        $stmt->execute([
            $remoteTool !== '' ? $remoteTool : $defaultVendor,
            $remoteId,
            $remotePassword,
            $remoteHost,
            (int)$ctx['id_login'],
            $ctx['user_name'] ?: $ctx['login'],
            $id,
        ]);
        $endpointStmt = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_support_endpoints
            SET remote_id = CASE WHEN ? <> '' THEN ? ELSE remote_id END,
                remote_password_enc = CASE WHEN ? <> '' THEN ? ELSE remote_password_enc END,
                remote_host = CASE WHEN ? <> '' THEN ? ELSE remote_host END,
                rustdesk_id = CASE WHEN ? <> '' THEN ? ELSE rustdesk_id END,
                rustdesk_password_enc = CASE WHEN ? <> '' THEN ? ELSE rustdesk_password_enc END,
                rustdesk_host = CASE WHEN ? <> '' THEN ? ELSE rustdesk_host END,
                updated_at = NOW()
            WHERE id_empresa = (SELECT id_empresa FROM " . MASTER_DB . ".smx_support_desktop_requests WHERE id = ? LIMIT 1)
              AND id_login = (SELECT id_login FROM " . MASTER_DB . ".smx_support_desktop_requests WHERE id = ? LIMIT 1)");
        $encPassword = supportDesktopEncrypt($remotePassword);
        $endpointStmt->execute([
            $remoteId,
            $remoteId,
            $remotePassword,
            $encPassword,
            $remoteHost,
            $remoteHost,
            $remoteId,
            $remoteId,
            $remotePassword,
            $encPassword,
            $remoteHost,
            $remoteHost,
            $id,
            $id,
        ]);
        supportDesktopJson(['ok' => true, 'message' => 'Acceso remoto guardado.']);
    }

    if ($action === 'admin_resolve') {
        supportDesktopRequireSupport($ctx);
        $id = (int)($input['id'] ?? $_POST['id'] ?? 0);
        $resolution = trim((string)($input['resolution'] ?? $_POST['resolution'] ?? 'resolved'));
        $notes = trim((string)($input['notes'] ?? $_POST['notes'] ?? ''));
        if ($id <= 0) {
            supportDesktopJson(['ok' => false, 'error' => 'Solicitud requerida'], 422);
        }
        if (!in_array($resolution, ['resolved', 'rejected'], true)) {
            $resolution = 'resolved';
        }
        $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_support_desktop_requests
            SET status = ?,
                resolved_by_login = ?,
                resolved_by_name = ?,
                resolved_at = NOW(),
                resolution_notes = ?,
                taken_by_login = COALESCE(taken_by_login, ?),
                taken_by_name = COALESCE(taken_by_name, ?),
                taken_at = COALESCE(taken_at, NOW())
            WHERE id = ?");
        $stmt->execute([
            $resolution,
            (int)$ctx['id_login'],
            $ctx['user_name'] ?: $ctx['login'],
            $notes,
            (int)$ctx['id_login'],
            $ctx['user_name'] ?: $ctx['login'],
            $id,
        ]);
        supportDesktopJson(['ok' => true]);
    }

    supportDesktopJson(['ok' => false, 'error' => 'Acción no válida'], 404);
} catch (Throwable $e) {
    error_log('[soporte/api] ' . $e->getMessage());
    supportDesktopJson(['ok' => false, 'error' => $e->getMessage()], 500);
}
