<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
Session::requireLogin('/public/login.php');
require_once __DIR__ . '/../../api/mobile_tracking_fcm_helper.php';

ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE);
ob_start();

header('Content-Type: application/json; charset=utf-8');

function smxDiagJson(array $payload, int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function smxDiagPushConfig(): array
{
    $file = dirname(__DIR__, 3) . '/config/push_vapid.php';
    $loaded = is_file($file) ? require $file : [];
    return is_array($loaded) ? $loaded : [];
}

function smxDiagPushEnabled(): bool
{
    $cfg = smxDiagPushConfig();
    return trim((string)($cfg['publicKey'] ?? '')) !== ''
        && trim((string)($cfg['privateKey'] ?? '')) !== ''
        && trim((string)($cfg['subject'] ?? '')) !== '';
}

function smxDiagEnsurePushSubscriptionsTable(PDO $pdo, string $masterDb): string
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
    }
    return $table;
}

function smxDiagEnsurePushLogTable(PDO $pdo, string $masterDb): string
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
    }
    return $table;
}

function smxDiagRunWebPush(array $subscriptions, array $payload): array
{
    if (empty($subscriptions) || !smxDiagPushEnabled()) return [];
    $script = dirname(__DIR__, 3) . '/scripts/send-web-push.js';
    if (!is_file($script)) return [];

    $cfg = smxDiagPushConfig();
    $input = json_encode([
        'vapid' => [
            'subject' => (string)($cfg['subject'] ?? ''),
            'publicKey' => (string)($cfg['publicKey'] ?? ''),
            'privateKey' => (string)($cfg['privateKey'] ?? ''),
        ],
        'payload' => $payload,
        'subscriptions' => $subscriptions,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($input) || $input === '') return [];

    $cmd = 'node ' . escapeshellarg($script);
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $spec, $pipes, dirname(__DIR__, 3));
    if (!is_resource($proc)) return [];

    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    if ($exitCode !== 0) return [];

    $decoded = json_decode((string)$stdout, true);
    return (is_array($decoded) && isset($decoded['results']) && is_array($decoded['results'])) ? $decoded['results'] : [];
}

try {
    $pdo = Database::getMasterConnection();
    $masterDb = Database::getMasterDbName();
    $idLogin = (int)Session::getIdLogin();
    $idEmpresa = (int)Session::getIdEmpresa();
    $action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'status')));

    $subsTable = smxDiagEnsurePushSubscriptionsTable($pdo, $masterDb);
    $logTable = smxDiagEnsurePushLogTable($pdo, $masterDb);
    $fcmLogTable = smxMobileEnsureFcmLogTable($pdo, $masterDb);

    if ($action === 'status') {
        $stSub = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active_count,
                MAX(last_seen_at) AS last_seen_at,
                MAX(last_success_at) AS last_success_at,
                MAX(last_error_at) AS last_error_at,
                MAX(COALESCE(last_error, '')) AS last_error
            FROM {$subsTable}
            WHERE id_login = :id_login
        ");
        $stSub->execute([':id_login' => $idLogin]);
        $sub = $stSub->fetch(PDO::FETCH_ASSOC) ?: [];

        $stLog = $pdo->prepare("
            SELECT id, channel, title, body, target_url, status_code, ok, error_text, created_at
            FROM {$logTable}
            WHERE id_dest_login = :id_login
            ORDER BY id DESC
            LIMIT 5
        ");
        $stLog->execute([':id_login' => $idLogin]);
        $recent = $stLog->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stFcm = $pdo->prepare("
            SELECT id, channel, title, body, target_url, ok, error_text, created_at
            FROM {$fcmLogTable}
            WHERE id_dest_login = :id_login
            ORDER BY id DESC
            LIMIT 5
        ");
        $stFcm->execute([':id_login' => $idLogin]);
        $recentFcm = $stFcm->fetchAll(PDO::FETCH_ASSOC) ?: [];

        smxDiagJson([
            'success' => true,
            'push_enabled' => smxDiagPushEnabled(),
            'subscription' => [
                'total' => (int)($sub['total'] ?? 0),
                'active_count' => (int)($sub['active_count'] ?? 0),
                'last_seen_at' => (string)($sub['last_seen_at'] ?? ''),
                'last_success_at' => (string)($sub['last_success_at'] ?? ''),
                'last_error_at' => (string)($sub['last_error_at'] ?? ''),
                'last_error' => (string)($sub['last_error'] ?? ''),
            ],
            'recent_pushes' => $recent,
            'recent_fcm' => $recentFcm,
        ]);
    }

    if ($action === 'test') {
        if (!smxDiagPushEnabled()) {
            throw new Exception('Push no configurado en servidor');
        }
        $st = $pdo->prepare("
            SELECT id, id_login, endpoint, subscription_json
            FROM {$subsTable}
            WHERE id_login = :id_login
              AND active = 1
        ");
        $st->execute([':id_login' => $idLogin]);
        $subscriptions = [];
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $subscription = json_decode((string)($row['subscription_json'] ?? ''), true);
            if (!is_array($subscription) || trim((string)($subscription['endpoint'] ?? '')) === '') continue;
            $subscriptions[] = [
                'id' => (int)($row['id'] ?? 0),
                'id_login' => (int)($row['id_login'] ?? 0),
                'endpoint' => (string)($row['endpoint'] ?? ''),
                'subscription' => $subscription,
            ];
        }
        if (empty($subscriptions)) {
            throw new Exception('Este usuario no tiene suscripción push activa');
        }

        $payload = [
            'title' => 'Prueba de notificación SistemaX',
            'body' => 'Si ves esto con la app cerrada, el push real está funcionando.',
            'url' => '/public/menu/push_diagnostico.php',
            'tag' => 'smx-push-diagnostic-' . $idLogin,
            'channel' => 'push_diagnostic',
            'peer_login' => $idLogin,
            'icon' => '/public/assets/logo-192.png',
            'badge' => '/public/assets/logo-192.png',
        ];
        $results = smxDiagRunWebPush($subscriptions, $payload);

        $insert = $pdo->prepare("
            INSERT INTO {$logTable}
            (id_empresa, id_dest_login, id_remitente_login, channel, title, body, target_url, status_code, ok, error_text, created_at)
            VALUES
            (:id_empresa, :id_dest_login, :id_remitente_login, :channel, :title, :body, :target_url, :status_code, :ok, :error_text, NOW())
        ");
        $byEndpoint = [];
        foreach ($results as $row) {
            if (is_array($row) && trim((string)($row['endpoint'] ?? '')) !== '') {
                $byEndpoint[(string)$row['endpoint']] = $row;
            }
        }
        foreach ($subscriptions as $sub) {
            $result = $byEndpoint[(string)$sub['endpoint']] ?? [];
            $insert->execute([
                ':id_empresa' => $idEmpresa,
                ':id_dest_login' => $idLogin,
                ':id_remitente_login' => $idLogin,
                ':channel' => 'push_diagnostic',
                ':title' => $payload['title'],
                ':body' => $payload['body'],
                ':target_url' => $payload['url'],
                ':status_code' => (int)($result['statusCode'] ?? 0),
                ':ok' => !empty($result['ok']) ? 1 : 0,
                ':error_text' => substr((string)($result['error'] ?? ''), 0, 255),
            ]);
        }

        smxDiagJson([
            'success' => true,
            'message' => 'Prueba enviada',
            'results' => $results,
        ]);
    }

    if ($action === 'test_fcm_auth') {
        smxMobileSendFcmToLogins($pdo, $masterDb, [$idLogin], [
            'title' => 'Autorización pendiente',
            'body' => 'Prueba nativa FCM del mismo canal que usa el POS.',
            'url' => '/public/pos/autorizaciones.php',
            'channel' => 'authorization_request',
            '_id_empresa' => $idEmpresa,
            '_id_remitente_login' => $idLogin,
        ]);
        smxDiagJson([
            'success' => true,
            'message' => 'Prueba FCM nativa enviada',
        ]);
    }

    throw new Exception('Acción no soportada');
} catch (Throwable $e) {
    smxDiagJson([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
