<?php

require_once __DIR__ . '/mobile_tracking_fcm_helper.php';

function smxMobileEnsureIosPushTable(PDO $pdo, string $masterDb): string
{
    $table = "{$masterDb}.smx_mobile_ios_push_tokens";
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                device_id BIGINT NOT NULL DEFAULT 0,
                id_empresa INT NOT NULL DEFAULT 0,
                id_login INT NOT NULL DEFAULT 0,
                device_uuid CHAR(36) DEFAULT NULL,
                device_name VARCHAR(190) DEFAULT NULL,
                apns_token_hash CHAR(64) NOT NULL,
                apns_token TEXT NOT NULL,
                platform VARCHAR(32) NOT NULL DEFAULT 'ios',
                app_version VARCHAR(64) NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_seen_at DATETIME NULL,
                last_success_at DATETIME NULL,
                last_error_at DATETIME NULL,
                last_error VARCHAR(255) NULL,
                UNIQUE KEY uq_ios_device_token_hash (device_id, apns_token_hash),
                KEY idx_ios_login_active (id_login, active),
                KEY idx_ios_empresa_active (id_empresa, active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
    }
    return $table;
}

function smxMobileRegisterIosPushToken(PDO $pdo, string $masterDb, array $agent, string $apnsToken, string $platform = 'ios', string $appVersion = ''): array
{
    $apnsToken = trim($apnsToken);
    if ($apnsToken === '') {
        return ['token_id' => 0, 'active_tokens' => 0];
    }
    $table = smxMobileEnsureIosPushTable($pdo, $masterDb);
    $deviceId = (int)($agent['device_id'] ?? 0);
    $idEmpresa = (int)($agent['id_empresa'] ?? 0);
    $idLogin = (int)($agent['id_login'] ?? 0);
    $deviceUuid = trim((string)($agent['device_uuid'] ?? ''));
    $deviceName = trim((string)($agent['device_name'] ?? ''));
    $tokenHash = hash('sha256', $apnsToken);

    $st = $pdo->prepare("
        INSERT INTO {$table}
            (device_id, id_empresa, id_login, device_uuid, device_name, apns_token_hash, apns_token, platform, app_version, active, last_seen_at, updated_at)
        VALUES
            (:device_id, :id_empresa, :id_login, :device_uuid, :device_name, :token_hash, :apns_token, :platform, :app_version, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            id_empresa = VALUES(id_empresa),
            id_login = VALUES(id_login),
            device_uuid = VALUES(device_uuid),
            device_name = VALUES(device_name),
            apns_token = VALUES(apns_token),
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
        ':device_uuid' => $deviceUuid !== '' ? $deviceUuid : null,
        ':device_name' => $deviceName !== '' ? $deviceName : null,
        ':token_hash' => $tokenHash,
        ':apns_token' => $apnsToken,
        ':platform' => substr(trim($platform) !== '' ? $platform : 'ios', 0, 32),
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
              AND apns_token_hash <> :token_hash
              AND active = 1
        ");
        $disableOld->execute([
            ':device_id' => $deviceId,
            ':id_login' => $idLogin,
            ':token_hash' => $tokenHash,
        ]);
    } catch (Throwable $e) {
    }

    $current = $pdo->prepare("
        SELECT id
        FROM {$table}
        WHERE device_id = :device_id
          AND id_login = :id_login
          AND apns_token_hash = :token_hash
        ORDER BY id DESC
        LIMIT 1
    ");
    $current->execute([
        ':device_id' => $deviceId,
        ':id_login' => $idLogin,
        ':token_hash' => $tokenHash,
    ]);
    $tokenId = (int)($current->fetchColumn() ?: 0);

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

function smxMobileFetchIosTargets(PDO $pdo, string $masterDb, array $loginIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $loginIds), static fn ($id) => $id > 0)));
    if (empty($ids)) {
        return [];
    }
    $table = smxMobileEnsureIosPushTable($pdo, $masterDb);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("
        SELECT id, id_login, apns_token
        FROM {$table}
        WHERE active = 1
          AND id_login IN ({$in})
    ");
    $st->execute($ids);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function smxMobileMarkIosPushResult(PDO $pdo, string $masterDb, int $id, bool $ok, string $error = ''): void
{
    if ($id <= 0) {
        return;
    }
    $table = smxMobileEnsureIosPushTable($pdo, $masterDb);
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

function smxMobileSendIosPushToLogins(PDO $pdo, string $masterDb, array $loginIds, array $payload): void
{
    if (!smxMobileFcmEnabled() || !function_exists('curl_init')) {
        return;
    }
    $projectId = smxMobileFcmProjectId();
    $accessToken = smxMobileFcmAccessToken();
    if ($projectId === '' || $accessToken === null) {
        return;
    }
    $targets = smxMobileFetchIosTargets($pdo, $masterDb, $loginIds);
    if (empty($targets)) {
        return;
    }

    $endpoint = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';
    $logTable = smxMobileEnsureFcmLogTable($pdo, $masterDb);
    $insert = $pdo->prepare("
        INSERT INTO {$logTable}
        (id_empresa, id_dest_login, id_remitente_login, channel, title, body, target_url, ok, error_text, created_at)
        VALUES
        (:id_empresa, :id_dest_login, :id_remitente_login, :channel, :title, :body, :target_url, :ok, :error_text, NOW())
    ");

    foreach ($targets as $row) {
        $token = trim((string)($row['apns_token'] ?? ''));
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
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'badge' => 1,
                        ],
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
        smxMobileMarkIosPushResult($pdo, $masterDb, (int)($row['id'] ?? 0), $ok, $error);
        try {
            $insert->execute([
                ':id_empresa' => (int)($payload['_id_empresa'] ?? 0),
                ':id_dest_login' => (int)($row['id_login'] ?? 0),
                ':id_remitente_login' => (int)($payload['_id_remitente_login'] ?? 0),
                ':channel' => substr((string)($payload['channel'] ?? 'ios_push'), 0, 64),
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
