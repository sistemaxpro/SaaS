<?php

function smxMobileFcmConfig(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $file = dirname(__DIR__, 2) . '/config/firebase_fcm.php';
    $loaded = is_file($file) ? require $file : [];
    $cfg = is_array($loaded) ? $loaded : [];
    return $cfg;
}

function smxMobileFcmCredentials(): ?array
{
    static $creds = false;
    if ($creds !== false) {
        return $creds ?: null;
    }
    $cfg = smxMobileFcmConfig();
    $json = trim((string)($cfg['serviceAccountJson'] ?? ''));
    if ($json !== '') {
        $data = json_decode($json, true);
        $creds = is_array($data) ? $data : null;
        return $creds;
    }
    $file = trim((string)($cfg['serviceAccountFile'] ?? ''));
    if ($file !== '' && is_file($file)) {
        $data = json_decode((string)file_get_contents($file), true);
        $creds = is_array($data) ? $data : null;
        return $creds;
    }
    $creds = null;
    return null;
}

function smxMobileFcmEnabled(): bool
{
    $creds = smxMobileFcmCredentials();
    return is_array($creds)
        && trim((string)($creds['client_email'] ?? '')) !== ''
        && trim((string)($creds['private_key'] ?? '')) !== '';
}

function smxMobileFcmProjectId(): string
{
    $cfg = smxMobileFcmConfig();
    $projectId = trim((string)($cfg['projectId'] ?? ''));
    if ($projectId !== '') {
        return $projectId;
    }
    $creds = smxMobileFcmCredentials();
    return trim((string)($creds['project_id'] ?? ''));
}

function smxMobileEnsureFcmTokensTable(PDO $pdo, string $masterDb): string
{
    $table = "{$masterDb}.smx_mobile_track_fcm_tokens";
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                device_id BIGINT NOT NULL,
                id_empresa INT NOT NULL DEFAULT 0,
                id_login INT NOT NULL DEFAULT 0,
                fcm_token_hash CHAR(64) NOT NULL,
                fcm_token TEXT NOT NULL,
                platform VARCHAR(32) NOT NULL DEFAULT 'android',
                app_version VARCHAR(64) NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_seen_at DATETIME NULL,
                last_success_at DATETIME NULL,
                last_error_at DATETIME NULL,
                last_error VARCHAR(255) NULL,
                UNIQUE KEY uq_device_token_hash (device_id, fcm_token_hash),
                KEY idx_login_active (id_login, active),
                KEY idx_empresa_active (id_empresa, active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
    }
    return $table;
}

function smxMobileEnsureFcmLogTable(PDO $pdo, string $masterDb): string
{
    $table = "{$masterDb}.smx_mobile_track_fcm_log";
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                id_empresa INT NOT NULL DEFAULT 0,
                id_dest_login INT NOT NULL DEFAULT 0,
                id_remitente_login INT NOT NULL DEFAULT 0,
                channel VARCHAR(64) NOT NULL DEFAULT '',
                title VARCHAR(180) NOT NULL DEFAULT '',
                body VARCHAR(255) NOT NULL DEFAULT '',
                target_url VARCHAR(255) NULL,
                ok TINYINT(1) NOT NULL DEFAULT 0,
                error_text VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_dest_created (id_dest_login, created_at),
                KEY idx_company_channel (id_empresa, channel, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
    }
    return $table;
}

function smxMobileRegisterFcmToken(PDO $pdo, string $masterDb, array $agent, string $fcmToken, string $platform = 'android', string $appVersion = ''): array
{
    $fcmToken = trim($fcmToken);
    if ($fcmToken === '') {
        return ['token_id' => 0, 'active_tokens' => 0];
    }
    $table = smxMobileEnsureFcmTokensTable($pdo, $masterDb);
    $deviceId = (int)($agent['device_id'] ?? 0);
    $idEmpresa = (int)($agent['id_empresa'] ?? 0);
    $idLogin = (int)($agent['id_login'] ?? 0);
    $tokenHash = hash('sha256', $fcmToken);
    $st = $pdo->prepare("
        INSERT INTO {$table}
            (device_id, id_empresa, id_login, fcm_token_hash, fcm_token, platform, app_version, active, last_seen_at, updated_at)
        VALUES
            (:device_id, :id_empresa, :id_login, :token_hash, :fcm_token, :platform, :app_version, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            id_empresa = VALUES(id_empresa),
            id_login = VALUES(id_login),
            fcm_token = VALUES(fcm_token),
            platform = VALUES(platform),
            app_version = VALUES(app_version),
            active = 1,
            last_seen_at = NOW(),
            last_error = NULL,
            last_error_at = NULL,
            updated_at = NOW()
    ");
    $st->execute([
        ':device_id' => $deviceId,
        ':id_empresa' => $idEmpresa,
        ':id_login' => $idLogin,
        ':token_hash' => $tokenHash,
        ':fcm_token' => $fcmToken,
        ':platform' => substr(trim($platform) !== '' ? $platform : 'android', 0, 32),
        ':app_version' => substr(trim($appVersion), 0, 64),
    ]);

    try {
        $disableOld = $pdo->prepare("
            UPDATE {$table}
            SET active = 0,
                last_error = 'Superseded by newer token on same device',
                last_error_at = NOW(),
                updated_at = NOW()
            WHERE device_id = :device_id
              AND id_login = :id_login
              AND fcm_token_hash <> :token_hash
              AND active = 1
        ");
        $disableOld->execute([
            ':device_id' => $deviceId,
            ':id_login' => $idLogin,
            ':token_hash' => $tokenHash,
        ]);
    } catch (Throwable $e) {
    }

    $selectCurrent = $pdo->prepare("
        SELECT id
        FROM {$table}
        WHERE device_id = :device_id
          AND id_login = :id_login
          AND fcm_token_hash = :token_hash
        ORDER BY id DESC
        LIMIT 1
    ");
    $selectCurrent->execute([
        ':device_id' => $deviceId,
        ':id_login' => $idLogin,
        ':token_hash' => $tokenHash,
    ]);
    $tokenId = (int)($selectCurrent->fetchColumn() ?: 0);

    $countActive = $pdo->prepare("
        SELECT COUNT(*)
        FROM {$table}
        WHERE device_id = :device_id
          AND id_login = :id_login
          AND active = 1
    ");
    $countActive->execute([
        ':device_id' => $deviceId,
        ':id_login' => $idLogin,
    ]);

    return [
        'token_id' => $tokenId,
        'active_tokens' => (int)($countActive->fetchColumn() ?: 0),
    ];
}

function smxMobileFetchFcmTargets(PDO $pdo, string $masterDb, array $loginIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $loginIds), static fn ($id) => $id > 0)));
    if (empty($ids)) {
        return [];
    }
    $table = smxMobileEnsureFcmTokensTable($pdo, $masterDb);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("
        SELECT id, id_login, fcm_token
        FROM {$table}
        WHERE active = 1
          AND id_login IN ({$in})
    ");
    $st->execute($ids);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function smxMobileFcmBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function smxMobileFcmAccessToken(): ?string
{
    static $cached = null;
    static $expiresAt = 0;
    if ($cached && $expiresAt > (time() + 60)) {
        return $cached;
    }
    $creds = smxMobileFcmCredentials();
    if (!$creds) {
        return null;
    }
    $clientEmail = trim((string)($creds['client_email'] ?? ''));
    $privateKey = (string)($creds['private_key'] ?? '');
    if ($clientEmail === '' || $privateKey === '' || !function_exists('openssl_sign')) {
        return null;
    }
    $header = smxMobileFcmBase64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $now = time();
    $claims = smxMobileFcmBase64Url(json_encode([
        'iss' => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    $unsigned = $header . '.' . $claims;
    $signature = '';
    $ok = @openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        return null;
    }
    $jwt = $unsigned . '.' . smxMobileFcmBase64Url($signature);
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300 || !is_string($raw) || $raw === '') {
        return null;
    }
    $json = json_decode($raw, true);
    $token = trim((string)($json['access_token'] ?? ''));
    $ttl = (int)($json['expires_in'] ?? 3600);
    if ($token === '') {
        return null;
    }
    $cached = $token;
    $expiresAt = time() + max(60, $ttl);
    return $cached;
}

function smxMobileMarkFcmTokenResult(PDO $pdo, string $masterDb, int $id, bool $ok, string $error = ''): void
{
    if ($id <= 0) {
        return;
    }
    $table = smxMobileEnsureFcmTokensTable($pdo, $masterDb);
    if ($ok) {
        $st = $pdo->prepare("UPDATE {$table} SET last_success_at = NOW(), last_seen_at = NOW(), last_error = NULL, last_error_at = NULL WHERE id = ?");
        $st->execute([$id]);
        return;
    }
    $normalizedError = strtolower(trim($error));
    $inactive = (strpos($normalizedError, 'unregistered') !== false || strpos($normalizedError, 'not found') !== false) ? 0 : 1;
    $st = $pdo->prepare("UPDATE {$table} SET active = ?, last_seen_at = NOW(), last_error_at = NOW(), last_error = ? WHERE id = ?");
    $st->execute([$inactive, substr($error, 0, 255), $id]);
}

function smxMobileSendFcmToLogins(PDO $pdo, string $masterDb, array $loginIds, array $payload): void
{
    if (!smxMobileFcmEnabled() || !function_exists('curl_init')) {
        return;
    }
    $projectId = smxMobileFcmProjectId();
    $accessToken = smxMobileFcmAccessToken();
    if ($projectId === '' || $accessToken === null) {
        return;
    }
    $targets = smxMobileFetchFcmTargets($pdo, $masterDb, $loginIds);
    if (empty($targets)) {
        return;
    }
    $endpoint = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';
    foreach ($targets as $row) {
        $token = trim((string)($row['fcm_token'] ?? ''));
        if ($token === '') {
            continue;
        }
        $body = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => (string)($payload['title'] ?? 'SistemaX'),
                    'body' => (string)($payload['body'] ?? ''),
                ],
                'data' => [
                    'title' => (string)($payload['title'] ?? 'SistemaX'),
                    'body' => (string)($payload['body'] ?? ''),
                    'url' => (string)($payload['url'] ?? '/public/pos/autorizaciones.php'),
                    'channel' => (string)($payload['channel'] ?? 'authorization_request'),
                ],
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'title' => (string)($payload['title'] ?? 'SistemaX'),
                        'body' => (string)($payload['body'] ?? ''),
                        'channel_id' => 'sistemax_authorizations',
                        'sound' => 'default',
                        'click_action' => 'OPEN_MAIN_ACTIVITY',
                    ],
                ],
            ],
        ];
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=utf-8',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $ok = $code >= 200 && $code < 300;
        $error = '';
        if (!$ok) {
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $error = (string)($decoded['error']['message'] ?? ('HTTP ' . $code));
        }
        smxMobileMarkFcmTokenResult($pdo, $masterDb, (int)($row['id'] ?? 0), $ok, $error);
        try {
            $logTable = smxMobileEnsureFcmLogTable($pdo, $masterDb);
            $st = $pdo->prepare("
                INSERT INTO {$logTable}
                (id_empresa, id_dest_login, id_remitente_login, channel, title, body, target_url, ok, error_text, created_at)
                VALUES
                (:id_empresa, :id_dest_login, :id_remitente_login, :channel, :title, :body, :target_url, :ok, :error_text, NOW())
            ");
            $st->execute([
                ':id_empresa' => (int)($payload['_id_empresa'] ?? 0),
                ':id_dest_login' => (int)($row['id_login'] ?? 0),
                ':id_remitente_login' => (int)($payload['_id_remitente_login'] ?? 0),
                ':channel' => substr((string)($payload['channel'] ?? 'generic'), 0, 64),
                ':title' => substr((string)($payload['title'] ?? 'SistemaX'), 0, 180),
                ':body' => substr((string)($payload['body'] ?? ''), 0, 255),
                ':target_url' => substr((string)($payload['url'] ?? ''), 0, 255),
                ':ok' => $ok ? 1 : 0,
                ':error_text' => substr($error, 0, 255),
            ]);
        } catch (Throwable $e) {
        }
    }
}
