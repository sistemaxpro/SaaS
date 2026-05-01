<?php

require_once dirname(__DIR__, 2) . '/api/mobile_tracking_fcm_helper.php';
require_once dirname(__DIR__, 2) . '/api/mobile_tracking_ios_push_helper.php';

function smxPosPushConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $file = dirname(__DIR__, 3) . '/config/push_vapid.php';
    $loaded = is_file($file) ? require $file : [];
    $config = is_array($loaded) ? $loaded : [];
    return $config;
}

function smxPosPushEnabled(): bool
{
    $cfg = smxPosPushConfig();
    return trim((string)($cfg['publicKey'] ?? '')) !== ''
        && trim((string)($cfg['privateKey'] ?? '')) !== ''
        && trim((string)($cfg['subject'] ?? '')) !== '';
}

function smxPosEnsurePushSubscriptionsTable(PDO $pdo, string $masterDb): string
{
    $table = "{$masterDb}.smx_push_subscriptions";
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                id_login INT NOT NULL,
                id_empresa INT NOT NULL DEFAULT 0,
                endpoint_hash CHAR(64) NOT NULL,
                endpoint TEXT NOT NULL,
                subscription_json LONGTEXT NOT NULL,
                content_encoding VARCHAR(32) NULL,
                user_agent VARCHAR(255) NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_seen_at DATETIME NULL,
                last_success_at DATETIME NULL,
                last_error_at DATETIME NULL,
                last_error VARCHAR(255) NULL,
                UNIQUE KEY uq_login_endpoint_hash (id_login, endpoint_hash),
                KEY idx_company_active (id_empresa, active),
                KEY idx_login_active (id_login, active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // Nunca romper el flujo principal por push.
    }
    return $table;
}

function smxPosPushFetchLoginSubscriptions(PDO $pdo, string $masterDb, array $loginIds): array
{
    $ids = array_values(array_unique(array_map('intval', $loginIds)));
    $ids = array_values(array_filter($ids, static fn ($id) => $id > 0));
    if (empty($ids)) {
        return [];
    }

    $table = smxPosEnsurePushSubscriptionsTable($pdo, $masterDb);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("
        SELECT id, id_login, endpoint, subscription_json
        FROM {$table}
        WHERE active = 1
          AND id_login IN ({$in})
    ");
    $st->execute($ids);

    $items = [];
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $subscription = json_decode((string)($row['subscription_json'] ?? ''), true);
        if (!is_array($subscription) || trim((string)($subscription['endpoint'] ?? '')) === '') {
            continue;
        }
        $items[] = [
            'id' => (int)($row['id'] ?? 0),
            'id_login' => (int)($row['id_login'] ?? 0),
            'endpoint' => (string)($row['endpoint'] ?? ''),
            'subscription' => $subscription,
        ];
    }
    return $items;
}

function smxPosRunWebPush(array $subscriptions, array $payload): array
{
    if (empty($subscriptions) || !smxPosPushEnabled()) {
        return [];
    }

    $script = dirname(__DIR__, 3) . '/scripts/send-web-push.js';
    if (!is_file($script)) {
        return [];
    }

    $cfg = smxPosPushConfig();
    $input = json_encode([
        'vapid' => [
            'subject' => (string)($cfg['subject'] ?? ''),
            'publicKey' => (string)($cfg['publicKey'] ?? ''),
            'privateKey' => (string)($cfg['privateKey'] ?? ''),
        ],
        'payload' => $payload,
        'subscriptions' => $subscriptions,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($input) || $input === '') {
        return [];
    }

    $cmd = 'node ' . escapeshellarg($script);
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($cmd, $spec, $pipes, dirname(__DIR__, 3));
    if (!is_resource($proc)) {
        return [];
    }

    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    if ($exitCode !== 0) {
        return [];
    }

    $decoded = json_decode((string)$stdout, true);
    if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) {
        return [];
    }
    return $decoded['results'];
}

function smxPosPushMarkResult(PDO $pdo, string $masterDb, array $result): void
{
    $id = (int)($result['id'] ?? 0);
    if ($id <= 0) {
        return;
    }

    $ok = !empty($result['ok']);
    $statusCode = (int)($result['statusCode'] ?? 0);
    $error = trim((string)($result['error'] ?? ''));
    $table = smxPosEnsurePushSubscriptionsTable($pdo, $masterDb);

    if ($ok) {
        $st = $pdo->prepare("
            UPDATE {$table}
            SET last_success_at = NOW(),
                last_seen_at = NOW(),
                last_error = NULL,
                last_error_at = NULL
            WHERE id = :id
        ");
        $st->execute([':id' => $id]);
        return;
    }

    $active = in_array($statusCode, [404, 410], true) ? 0 : 1;
    $st = $pdo->prepare("
        UPDATE {$table}
        SET active = :active,
            last_error = :last_error,
            last_error_at = NOW(),
            last_seen_at = NOW()
        WHERE id = :id
    ");
    $st->execute([
        ':active' => $active,
        ':last_error' => ($error !== '' ? (function_exists('mb_substr') ? mb_substr($error, 0, 255) : substr($error, 0, 255)) : null),
        ':id' => $id,
    ]);
}

function smxPosEnsurePushLogTable(PDO $pdo, string $masterDb): string
{
    $table = "{$masterDb}.smx_push_delivery_log";
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
                status_code INT NOT NULL DEFAULT 0,
                ok TINYINT(1) NOT NULL DEFAULT 0,
                error_text VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_dest_created (id_dest_login, created_at),
                KEY idx_company_channel (id_empresa, channel, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // Nunca romper el flujo principal por logging.
    }
    return $table;
}

function smxPosPushLogDeliveries(PDO $pdo, string $masterDb, array $subscriptions, array $payload, int $idEmpresa = 0, int $idRemitenteLogin = 0, array $results = []): void
{
    if (empty($subscriptions)) {
        return;
    }
    $table = smxPosEnsurePushLogTable($pdo, $masterDb);
    $byEndpoint = [];
    foreach ($results as $row) {
        if (!is_array($row)) continue;
        $endpoint = trim((string)($row['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $byEndpoint[$endpoint] = $row;
        }
    }

    $insert = $pdo->prepare("
        INSERT INTO {$table}
        (id_empresa, id_dest_login, id_remitente_login, channel, title, body, target_url, status_code, ok, error_text, created_at)
        VALUES
        (:id_empresa, :id_dest_login, :id_remitente_login, :channel, :title, :body, :target_url, :status_code, :ok, :error_text, NOW())
    ");

    foreach ($subscriptions as $sub) {
        $endpoint = trim((string)($sub['endpoint'] ?? ''));
        $result = $endpoint !== '' ? ($byEndpoint[$endpoint] ?? []) : [];
        $insert->execute([
            ':id_empresa' => $idEmpresa,
            ':id_dest_login' => (int)($sub['id_login'] ?? 0),
            ':id_remitente_login' => $idRemitenteLogin,
            ':channel' => substr((string)($payload['channel'] ?? 'generic'), 0, 64),
            ':title' => substr((string)($payload['title'] ?? 'SistemaX'), 0, 180),
            ':body' => substr((string)($payload['body'] ?? ''), 0, 255),
            ':target_url' => substr((string)($payload['url'] ?? ''), 0, 255),
            ':status_code' => (int)($result['statusCode'] ?? 0),
            ':ok' => !empty($result['ok']) ? 1 : 0,
            ':error_text' => substr((string)($result['error'] ?? ''), 0, 255),
        ]);
    }
}

function smxPosNotifyUserLoginsPush(PDO $pdo, string $masterDb, array $loginIds, array $payload): void
{
    $webPushLoginIds = $loginIds;
    $fcmLoginIds = $loginIds;
    if (!empty($payload['_web_push_extra_login_ids']) && is_array($payload['_web_push_extra_login_ids'])) {
        $webPushLoginIds = array_values(array_unique(array_merge($webPushLoginIds, array_map('intval', $payload['_web_push_extra_login_ids']))));
    }
    if (!empty($payload['_fcm_extra_login_ids']) && is_array($payload['_fcm_extra_login_ids'])) {
        $fcmLoginIds = array_values(array_unique(array_merge($fcmLoginIds, array_map('intval', $payload['_fcm_extra_login_ids']))));
    }

    $subscriptions = smxPosPushFetchLoginSubscriptions($pdo, $masterDb, $webPushLoginIds);
    if (!empty($subscriptions)) {
        $results = smxPosRunWebPush($subscriptions, $payload);
        foreach ($results as $result) {
            smxPosPushMarkResult($pdo, $masterDb, is_array($result) ? $result : []);
        }
        smxPosPushLogDeliveries(
            $pdo,
            $masterDb,
            $subscriptions,
            $payload,
            (int)($payload['_id_empresa'] ?? 0),
            (int)($payload['_id_remitente_login'] ?? 0),
            $results
        );
    }
    smxMobileSendFcmToLogins($pdo, $masterDb, $fcmLoginIds, $payload);
    smxMobileSendIosPushToLogins($pdo, $masterDb, $fcmLoginIds, $payload);
}
