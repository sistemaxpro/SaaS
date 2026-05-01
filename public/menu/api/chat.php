<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

header('Content-Type: application/json; charset=utf-8');

function smxChatLog(string $tag, string $message, array $ctx = []): void
{
    try {
        $base = dirname(__DIR__, 3) . '/logs';
        $file = is_dir($base) && is_writable($base)
            ? ($base . '/chat_api_errors.log')
            : (rtrim((string)sys_get_temp_dir(), '/') . '/sistemax_chat_api_errors.log');
        $line = '[' . date('Y-m-d H:i:s') . '] [' . $tag . '] ' . $message;
        if (!empty($ctx)) {
            $line .= ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND);
    } catch (Throwable $e) {
        // Silencioso: nunca romper respuesta por logging.
    }
}

function smxChatAssetOk(string $url): bool
{
    $url = trim($url);
    if ($url === '') return false;
    if (str_starts_with($url, 'http://')) return false;
    if (str_starts_with($url, 'https://')) return true;
    $projectRoot = dirname(__DIR__, 3);
    if (str_starts_with($url, '/public/')) {
        return is_file($projectRoot . $url);
    }
    if (str_starts_with($url, '/_lib/')) {
        return is_file($projectRoot . '/public' . $url);
    }
    return true;
}

function smxChatSvgFallback(string $text, string $bg = '#334155', string $fg = '#e2e8f0'): string
{
    $t = strtoupper(trim($text));
    if ($t === '') $t = 'SMX';
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128">'
        . '<rect width="100%" height="100%" fill="' . htmlspecialchars($bg, ENT_QUOTES, 'UTF-8') . '"/>'
        . '<text x="50%" y="54%" text-anchor="middle" font-family="Arial, sans-serif" font-size="40" fill="' . htmlspecialchars($fg, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</text>'
        . '</svg>';
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

function smxChatLogo(string $raw, int $seed): string
{
    $raw = trim($raw);
    $url = '';
    if ($raw !== '') {
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://') || str_starts_with($raw, '/')) {
            $url = $raw;
        } else {
            $url = '/public/_lib/file/img/empresa/' . ltrim($raw, '/');
        }
    }
    if ($url !== '' && smxChatAssetOk($url)) return $url;
    return smxChatSvgFallback('EM', '#1e3a8a', '#dbeafe');
}

function smxChatAvatar(string $raw, int $seed): string
{
    $raw = trim($raw);
    $url = '';
    if ($raw !== '') {
        if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://') || str_starts_with($raw, '/')) {
            $url = $raw;
        } else {
            $url = '/public/_lib/file/usuario/' . ltrim($raw, '/');
        }
    }
    if ($url !== '' && smxChatAssetOk($url)) return $url;
    return '/public/menu/api/pixabay_proxy.php?kind=avatar&seed=' . abs($seed);
}

function smxChatInferAvatarKind(string $name): string
{
    $lower = function_exists('mb_strtolower')
        ? mb_strtolower(trim($name), 'UTF-8')
        : strtolower(trim($name));
    $first = trim((string)(preg_split('/\s+/', $lower)[0] ?? ''));
    if ($first === '') {
        return 'avatar';
    }

    $femaleNames = [
        'maria', 'ana', 'laura', 'sofia', 'camila', 'paula', 'carla', 'andrea', 'lucia',
        'lucia', 'valeria', 'gabriela', 'daniela', 'alejandra', 'mariana', 'patricia',
        'fernanda', 'monica', 'adriana', 'romina', 'noelia', 'yamila', 'cintia', 'cecilia',
        'silvia', 'veronica', 'graciela', 'claudia', 'rocio', 'jazmin', 'ximena'
    ];
    $maleNames = [
        'jose', 'juan', 'carlos', 'luis', 'miguel', 'pedro', 'diego', 'marcos', 'marco',
        'fabio', 'martin', 'jorge', 'hernan', 'sebastian', 'alejandro', 'daniel', 'pablo',
        'nicolas', 'ricardo', 'gustavo', 'ramon', 'oscar', 'sergio', 'roberto', 'manuel',
        'francisco', 'javier', 'matias', 'cristian', 'cristiano', 'adrian'
    ];

    if (in_array($first, $femaleNames, true)) {
        return 'avatar_female';
    }
    if (in_array($first, $maleNames, true)) {
        return 'avatar_male';
    }

    if (preg_match('/(a|ia|na|ela|ina|ira|isa)$/u', $first)) {
        return 'avatar_female';
    }
    if (preg_match('/(o|os|on|an|el|er)$/u', $first)) {
        return 'avatar_male';
    }

    return 'avatar';
}

function smxChatAvatarForUser(string $raw, string $name, int $seed): string
{
    $direct = smxChatAvatar($raw, $seed);
    if ($raw !== '' || !str_contains($direct, '/public/menu/api/pixabay_proxy.php')) {
        return $direct;
    }
    return '/public/menu/api/pixabay_proxy.php?kind=' . rawurlencode(smxChatInferAvatarKind($name)) . '&seed=' . abs($seed);
}

function smxChatDeveloperAvatar(): string
{
    $logo = '/public/assets/logo-192.png';
    return smxChatAssetOk($logo) ? $logo : smxChatSvgFallback('SX', '#0f172a', '#e2e8f0');
}

function smxChatDeveloperSupportId(PDO $pdo, string $masterDb): int
{
    static $cache = [];
    if (array_key_exists($masterDb, $cache)) {
        return (int)$cache[$masterDb];
    }
    $ids = smxChatDeveloperSupportIds($pdo, $masterDb);
    $cache[$masterDb] = (int)($ids[0] ?? 0);
    return (int)$cache[$masterDb];
}

function smxChatDeveloperSupportIds(PDO $pdo, string $masterDb): array
{
    static $cache = [];
    if (array_key_exists($masterDb, $cache)) {
        return $cache[$masterDb];
    }
    $rows = smxChatResolveDeveloperSupportUsers($pdo, $masterDb);
    $ids = [];
    foreach ($rows as $row) {
        $id = (int)($row['id_login'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $cache[$masterDb] = array_values(array_unique($ids));
    return $cache[$masterDb];
}

function smxChatIsDeveloperSupportLogin(PDO $pdo, string $masterDb, int $idLogin): bool
{
    if ($idLogin <= 0) {
        return false;
    }
    return in_array($idLogin, smxChatDeveloperSupportIds($pdo, $masterDb), true);
}

function smxChatInitials(string $name): string
{
    $name = trim($name);
    if ($name === '') return 'U';
    $parts = preg_split('/\s+/', $name) ?: [];
    $out = '';
    foreach ($parts as $p) {
        if ($p === '') continue;
        $char = function_exists('mb_substr') ? mb_substr($p, 0, 1) : substr($p, 0, 1);
        $out .= function_exists('mb_strtoupper') ? mb_strtoupper($char) : strtoupper($char);
        $len = function_exists('mb_strlen') ? mb_strlen($out) : strlen($out);
        if ($len >= 2) break;
    }
    return $out !== '' ? $out : 'U';
}

function smxChatDetectTipoByMime(string $mime): string
{
    $m = strtolower(trim($mime));
    if (str_starts_with($m, 'image/')) return 'image';
    if (str_starts_with($m, 'video/')) return 'video';
    if (str_starts_with($m, 'audio/')) return 'audio';
    return 'file';
}

function smxChatUploadDir(): array
{
    $projectRoot = dirname(__DIR__, 3);
    $rel = '/public/uploads/chat/' . date('Y') . '/' . date('m');
    $abs = $projectRoot . $rel;
    if (!is_dir($abs)) {
        @mkdir($abs, 0775, true);
    }
    return [$abs, $rel];
}

function smxChatColumnExists(PDO $pdo, string $db, string $table, string $column): bool
{
    try {
        $st = $pdo->prepare("
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :db
              AND TABLE_NAME = :tbl
              AND COLUMN_NAME = :col
            LIMIT 1
        ");
        $st->execute([
            ':db' => $db,
            ':tbl' => $table,
            ':col' => $column,
        ]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function smxChatIndexExists(PDO $pdo, string $db, string $table, string $index): bool
{
    try {
        $st = $pdo->prepare("
            SELECT 1
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = :db
              AND TABLE_NAME = :tbl
              AND INDEX_NAME = :idx
            LIMIT 1
        ");
        $st->execute([
            ':db' => $db,
            ':tbl' => $table,
            ':idx' => $index,
        ]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function smxChatTryIndex(PDO $pdo, string $qualifiedTable, string $name, string $cols): void
{
    try {
        $pdo->exec("ALTER TABLE {$qualifiedTable} ADD INDEX {$name} ({$cols})");
    } catch (Throwable $e) {
        // ignorar si el índice ya existe o no hay privilegios.
    }
}

function smxChatSchemaCacheFile(): string
{
    $base = dirname(__DIR__, 3) . '/logs';
    if (is_dir($base) && is_writable($base)) {
        return $base . '/chat_schema_cache.json';
    }
    return rtrim((string)sys_get_temp_dir(), '/') . '/sistemax_chat_schema_cache.json';
}

function smxChatSchemaCacheFresh(string $masterDb, int $ttlSeconds = 900): bool
{
    try {
        $file = smxChatSchemaCacheFile();
        if (!is_file($file)) return false;
        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') return false;
        $data = json_decode($raw, true);
        if (!is_array($data)) return false;
        if ((string)($data['master_db'] ?? '') !== $masterDb) return false;
        $ts = (int)($data['ts'] ?? 0);
        return $ts > 0 && (time() - $ts) < $ttlSeconds;
    } catch (Throwable $e) {
        return false;
    }
}

function smxChatSchemaCacheTouch(string $masterDb): void
{
    try {
        $payload = json_encode([
            'master_db' => $masterDb,
            'ts' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents(smxChatSchemaCacheFile(), $payload);
    } catch (Throwable $e) {
        // nunca romper por cache auxiliar
    }
}

function smxChatRuntimeCacheFile(string $key): string
{
    $safeKey = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key) ?: 'chat_runtime';
    $base = dirname(__DIR__, 3) . '/logs';
    if (is_dir($base) && is_writable($base)) {
        return $base . '/' . $safeKey . '.json';
    }
    return rtrim((string)sys_get_temp_dir(), '/') . '/' . $safeKey . '.json';
}

function smxChatShouldRunThrottled(string $key, int $ttlSeconds): bool
{
    try {
        $file = smxChatRuntimeCacheFile($key);
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $data = json_decode((string)$raw, true);
            $ts = (int)($data['ts'] ?? 0);
            if ($ts > 0 && (time() - $ts) < $ttlSeconds) {
                return false;
            }
        }
        @file_put_contents($file, json_encode(['ts' => time()]));
        return true;
    } catch (Throwable $e) {
        return true;
    }
}

function smxChatLogSlow(string $tag, float $startedAt, array $ctx = [], float $thresholdSeconds = 2.0): void
{
    $elapsed = microtime(true) - $startedAt;
    if ($elapsed < $thresholdSeconds) {
        return;
    }
    $ctx['elapsed_ms'] = (int)round($elapsed * 1000);
    smxChatLog($tag, 'slow request', $ctx);
}

function smxChatResolveSubscriptionSupport(PDO $pdo, string $masterDb): ?array
{
    try {
        $supportCompanies = defined('SISTEMAX_SUPPORT_COMPANIES') && is_array(SISTEMAX_SUPPORT_COMPANIES)
            ? array_map('intval', SISTEMAX_SUPPORT_COMPANIES)
            : [169];
        $in = implode(',', array_fill(0, count($supportCompanies), '?'));

        $sql = "
            SELECT
                u.id_login,
                COALESCE(NULLIF(TRIM(u.name), ''), u.login) AS usuario_nombre,
                u.login AS usuario_login,
                COALESCE(u.foto, '') AS usuario_foto,
                COALESCE(u.id_empresa, 0) AS id_empresa,
                COALESCE(NULLIF(TRIM(e.empresa), ''), CONCAT('Empresa #', COALESCE(u.id_empresa, 0))) AS empresa_nombre,
                COALESCE(e.logos, '') AS empresa_logo
            FROM {$masterDb}.sec_users u
            LEFT JOIN {$masterDb}.empresa e ON e.id_empresa = u.id_empresa
            WHERE COALESCE(u.active, 'Y') = 'Y'
              AND u.id_empresa IN ({$in})
              AND (
                    LOWER(TRIM(u.login)) = 'soporte sistemax web'
                 OR LOWER(TRIM(u.login)) = 'soporte_sistemax_web'
                 OR LOWER(TRIM(u.name)) LIKE '%sistemax%'
              )
            ORDER BY
              CASE
                WHEN LOWER(TRIM(u.login)) = 'soporte sistemax web' THEN 0
                WHEN LOWER(TRIM(u.login)) = 'soporte_sistemax_web' THEN 1
                ELSE 2
              END,
              COALESCE(u.priv_admin, 'N') DESC,
              u.id_login ASC
            LIMIT 1
        ";
        $st = $pdo->prepare($sql);
        $st->execute($supportCompanies);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function smxChatResolveDeveloperSupport(PDO $pdo, string $masterDb): ?array
{
    $rows = smxChatResolveDeveloperSupportUsers($pdo, $masterDb);
    return $rows[0] ?? null;
}

function smxChatResolveDeveloperSupportUsers(PDO $pdo, string $masterDb): array
{
    try {
        $developerCompanyId = 169;

        $sql = "
            SELECT
                u.id_login,
                COALESCE(NULLIF(TRIM(u.name), ''), u.login) AS usuario_nombre,
                u.login AS usuario_login,
                COALESCE(u.foto, '') AS usuario_foto,
                COALESCE(u.id_empresa, 0) AS id_empresa,
                COALESCE(NULLIF(TRIM(e.empresa), ''), CONCAT('Empresa #', COALESCE(u.id_empresa, 0))) AS empresa_nombre,
                COALESCE(e.logos, '') AS empresa_logo,
                COALESCE((
                    SELECT sg.description
                    FROM {$masterDb}.sec_users_groups ug
                    INNER JOIN {$masterDb}.sec_groups sg
                        ON sg.id_grupo = ug.id_grupo
                       AND sg.group_id = ug.group_id
                    WHERE ug.id_grupo = u.id_empresa
                      AND TRIM(ug.login) = TRIM(u.login)
                    ORDER BY ug.group_id DESC
                    LIMIT 1
                ), '') AS grupo_nombre
            FROM {$masterDb}.sec_users u
            LEFT JOIN {$masterDb}.empresa e ON e.id_empresa = u.id_empresa
            WHERE COALESCE(u.active, 'Y') = 'Y'
              AND u.id_empresa = ?
              AND (
                    EXISTS (
                        SELECT 1
                        FROM {$masterDb}.sec_users_groups ugx
                        INNER JOIN {$masterDb}.sec_groups sgx
                            ON sgx.id_grupo = ugx.id_grupo
                           AND sgx.group_id = ugx.group_id
                        WHERE ugx.id_grupo = u.id_empresa
                          AND TRIM(ugx.login) = TRIM(u.login)
                          AND (
                                LOWER(TRIM(COALESCE(sgx.description, ''))) LIKE '%programador%'
                             OR LOWER(TRIM(COALESCE(sgx.description, ''))) LIKE '%desarroll%'
                          )
                    )
                 OR LOWER(TRIM(u.login)) IN ('marcovaldez', 'fabio')
                 OR LOWER(TRIM(u.name)) LIKE '%program%'
                 OR LOWER(TRIM(u.name)) LIKE '%desarroll%'
                 OR LOWER(TRIM(u.login)) LIKE '%program%'
                 OR LOWER(TRIM(u.login)) LIKE '%desarroll%'
              )
            ORDER BY
              CASE
                WHEN LOWER(TRIM(COALESCE((
                    SELECT sgp.description
                    FROM {$masterDb}.sec_users_groups ugp
                    INNER JOIN {$masterDb}.sec_groups sgp
                        ON sgp.id_grupo = ugp.id_grupo
                       AND sgp.group_id = ugp.group_id
                    WHERE ugp.id_grupo = u.id_empresa
                      AND TRIM(ugp.login) = TRIM(u.login)
                    ORDER BY ugp.group_id DESC
                    LIMIT 1
                ), ''))) LIKE '%programador%' THEN 0
                WHEN LOWER(TRIM(u.login)) = 'marcovaldez' THEN 1
                WHEN LOWER(TRIM(u.login)) = 'fabio' THEN 2
                WHEN LOWER(TRIM(u.name)) LIKE '%program%' THEN 3
                WHEN LOWER(TRIM(u.name)) LIKE '%desarroll%' THEN 4
                ELSE 5
              END,
              COALESCE(NULLIF(TRIM(u.name), ''), u.login) ASC,
              u.id_login ASC
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$developerCompanyId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_values(array_filter($rows, static fn($row) => (int)($row['id_login'] ?? 0) > 0));
    } catch (Throwable $e) {
        return [];
    }
}

function smxChatSubscriptionDailyUsage(PDO $pdo, string $table, int $fromLogin, int $toLogin): int
{
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*)
            FROM {$table}
            WHERE from_login = :from_login
              AND to_login = :to_login
              AND DATE(sent_at) = CURDATE()
        ");
        $st->execute([
            ':from_login' => $fromLogin,
            ':to_login' => $toLogin,
        ]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function smxChatCanAccessSubscriptionInbox(int $companyId): bool
{
    return in_array($companyId, SISTEMAX_SUPPORT_COMPANIES, true);
}

function smxChatSubscriptionDailyLimit(): int
{
    return 10;
}

function smxChatPushConfig(): array
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

function smxChatPushEnabled(): bool
{
    $cfg = smxChatPushConfig();
    return trim((string)($cfg['publicKey'] ?? '')) !== ''
        && trim((string)($cfg['privateKey'] ?? '')) !== ''
        && trim((string)($cfg['subject'] ?? '')) !== '';
}

function smxChatEnsurePushSubscriptionsTable(PDO $pdo, string $masterDb): string
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
        smxChatLog('push-table-create-failed', $e->getMessage(), ['table' => $table]);
    }
    return $table;
}

function smxChatPushUpsertSubscription(PDO $pdo, string $masterDb, int $idLogin, int $idEmpresa, array $subscription, string $userAgent = '', string $encoding = ''): bool
{
    $endpoint = trim((string)($subscription['endpoint'] ?? ''));
    if ($idLogin <= 0 || $endpoint === '') {
        return false;
    }
    $table = smxChatEnsurePushSubscriptionsTable($pdo, $masterDb);
    $json = json_encode($subscription, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return false;
    }
    $hash = hash('sha256', $endpoint);
    $st = $pdo->prepare("
        INSERT INTO {$table}
        (id_login, id_empresa, endpoint_hash, endpoint, subscription_json, content_encoding, user_agent, active, last_seen_at, last_error, last_error_at)
        VALUES
        (:id_login, :id_empresa, :endpoint_hash, :endpoint, :subscription_json, :content_encoding, :user_agent, 1, NOW(), NULL, NULL)
        ON DUPLICATE KEY UPDATE
            id_empresa = VALUES(id_empresa),
            endpoint = VALUES(endpoint),
            subscription_json = VALUES(subscription_json),
            content_encoding = VALUES(content_encoding),
            user_agent = VALUES(user_agent),
            active = 1,
            last_seen_at = NOW(),
            last_error = NULL,
            last_error_at = NULL
    ");
    return $st->execute([
        ':id_login' => $idLogin,
        ':id_empresa' => $idEmpresa,
        ':endpoint_hash' => $hash,
        ':endpoint' => $endpoint,
        ':subscription_json' => $json,
        ':content_encoding' => ($encoding !== '' ? $encoding : null),
        ':user_agent' => ($userAgent !== '' ? (function_exists('mb_substr') ? mb_substr($userAgent, 0, 255) : substr($userAgent, 0, 255)) : null),
    ]);
}

function smxChatPushDeactivateSubscription(PDO $pdo, string $masterDb, int $idLogin, string $endpoint): void
{
    $endpoint = trim($endpoint);
    if ($idLogin <= 0 || $endpoint === '') {
        return;
    }
    $table = smxChatEnsurePushSubscriptionsTable($pdo, $masterDb);
    $st = $pdo->prepare("
        UPDATE {$table}
        SET active = 0, last_seen_at = NOW()
        WHERE id_login = :id_login
          AND endpoint_hash = :endpoint_hash
    ");
    $st->execute([
        ':id_login' => $idLogin,
        ':endpoint_hash' => hash('sha256', $endpoint),
    ]);
}

function smxChatPushFetchSupportSubscriptions(PDO $pdo, string $masterDb): array
{
    $supportCompanies = defined('SISTEMAX_SUPPORT_COMPANIES') && is_array(SISTEMAX_SUPPORT_COMPANIES)
        ? array_map('intval', SISTEMAX_SUPPORT_COMPANIES)
        : [169];
    if (empty($supportCompanies)) {
        return [];
    }
    $table = smxChatEnsurePushSubscriptionsTable($pdo, $masterDb);
    $in = implode(',', array_fill(0, count($supportCompanies), '?'));
    $st = $pdo->prepare("
        SELECT ps.id, ps.id_login, ps.endpoint, ps.subscription_json
        FROM {$table} ps
        INNER JOIN {$masterDb}.sec_users u ON u.id_login = ps.id_login
        WHERE ps.active = 1
          AND u.id_empresa IN ({$in})
          AND COALESCE(u.active, 'Y') = 'Y'
    ");
    $st->execute($supportCompanies);
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

function smxChatRunWebPush(array $subscriptions, array $payload): array
{
    if (empty($subscriptions) || !smxChatPushEnabled()) {
        return [];
    }
    $script = dirname(__DIR__, 3) . '/scripts/send-web-push.js';
    if (!is_file($script)) {
        smxChatLog('push-script-missing', 'send-web-push.js not found', ['script' => $script]);
        return [];
    }

    $cfg = smxChatPushConfig();
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
        smxChatLog('push-proc-open-failed', 'Unable to open node process');
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
        smxChatLog('push-script-failed', 'Node push script failed', [
            'exit_code' => $exitCode,
            'stderr' => trim((string)$stderr),
        ]);
        return [];
    }

    $decoded = json_decode((string)$stdout, true);
    if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) {
        smxChatLog('push-script-invalid-json', 'Node push script returned invalid JSON', [
            'stdout' => trim((string)$stdout),
            'stderr' => trim((string)$stderr),
        ]);
        return [];
    }

    return $decoded['results'];
}

function smxChatPushMarkResult(PDO $pdo, string $masterDb, array $result): void
{
    $id = (int)($result['id'] ?? 0);
    if ($id <= 0) {
        return;
    }
    $ok = !empty($result['ok']);
    $statusCode = (int)($result['statusCode'] ?? 0);
    $error = trim((string)($result['error'] ?? ''));
    $table = smxChatEnsurePushSubscriptionsTable($pdo, $masterDb);
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

function smxChatPushPreview(string $msg, string $tipo = 'text', string $mediaName = ''): string
{
    $msg = trim($msg);
    if ($msg !== '') {
        return $msg;
    }
    return match ($tipo) {
        'image' => 'Envió una imagen',
        'video' => 'Envió un video',
        'audio' => 'Envió un audio',
        'file' => ('Envió un archivo' . ($mediaName !== '' ? ': ' . $mediaName : '')),
        default => 'Nuevo mensaje en la bandeja',
    };
}

function smxChatNotifySupportInboxPush(PDO $pdo, string $masterDb, int $fromLogin, string $preview): void
{
    if ($fromLogin <= 0 || !smxChatPushEnabled()) {
        return;
    }

    $subscriptions = smxChatPushFetchSupportSubscriptions($pdo, $masterDb);
    if (empty($subscriptions)) {
        return;
    }

    $st = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(u.name), ''), u.login) AS usuario_nombre,
            COALESCE(NULLIF(TRIM(e.empresa), ''), CONCAT('Empresa #', COALESCE(u.id_empresa, 0))) AS empresa_nombre
        FROM {$masterDb}.sec_users u
        LEFT JOIN {$masterDb}.empresa e ON e.id_empresa = u.id_empresa
        WHERE u.id_login = :id
        LIMIT 1
    ");
    $st->execute([':id' => $fromLogin]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $sender = trim((string)($row['usuario_nombre'] ?? 'Cliente'));
    $company = trim((string)($row['empresa_nombre'] ?? 'Empresa'));

    $payload = [
        'title' => 'Nuevo chat de suscripción',
        'body' => $sender . ' · ' . $company . ' · ' . $preview,
        'url' => '/public/menu/suscripciones_inbox.php',
        'tag' => 'subscription-inbox-' . $fromLogin,
        'peer_login' => $fromLogin,
        'channel' => 'subscription_inbox',
        'icon' => '/public/assets/logo-192.png',
        'badge' => '/public/assets/logo-192.png',
    ];

    $results = smxChatRunWebPush($subscriptions, $payload);
    foreach ($results as $result) {
        smxChatPushMarkResult($pdo, $masterDb, is_array($result) ? $result : []);
    }
}

try {
    $pdo = Database::getMasterConnection();
    $masterDb = Database::getMasterDbName();

    $me = (int)Session::getIdLogin();
    $myCompanyId = (int)Session::getIdEmpresa();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'users')));

    $table = "{$masterDb}.smx_chat_messages";
    $typingTable = "{$masterDb}.smx_chat_typing";
    $tableNameOnly = 'smx_chat_messages';
    $hasDeliveredAt = false;
    $hasTypingTable = false;
    if (!smxChatSchemaCacheFresh($masterDb)) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                from_login INT NOT NULL,
                to_login INT NOT NULL,
                mensaje TEXT NOT NULL,
                tipo VARCHAR(16) NOT NULL DEFAULT 'text',
                media_url VARCHAR(255) NULL,
                media_mime VARCHAR(120) NULL,
                media_name VARCHAR(180) NULL,
                media_size BIGINT NULL,
                media_duration_sec DECIMAL(10,2) NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                delivered_at DATETIME NULL,
                read_at DATETIME NULL,
                INDEX idx_to_read (to_login, read_at, sent_at),
                INDEX idx_to_delivered (to_login, delivered_at, sent_at),
                INDEX idx_pair_ft (from_login, to_login, sent_at),
                INDEX idx_pair_tf (to_login, from_login, sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            // Mantener compatibilidad en entornos sin privilegios de CREATE.
        }
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$typingTable} (
                    from_login INT NOT NULL,
                    to_login INT NOT NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (from_login, to_login),
                    INDEX idx_to_updated (to_login, updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            // Si no hay privilegio para CREATE, se desactiva "escribiendo..." sin romper el chat.
        }
        // Compatibilidad para instalaciones previas: evitar ALTER en cada request.
        $cols = ['tipo' => "VARCHAR(16) NOT NULL DEFAULT 'text'", 'media_url' => "VARCHAR(255) NULL", 'media_mime' => "VARCHAR(120) NULL", 'media_name' => "VARCHAR(180) NULL", 'media_size' => "BIGINT NULL", 'media_duration_sec' => "DECIMAL(10,2) NULL", 'delivered_at' => "DATETIME NULL"];
        foreach ($cols as $c => $def) {
            if (smxChatColumnExists($pdo, $masterDb, $tableNameOnly, $c)) {
                continue;
            }
            try {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$c} {$def}");
            } catch (Throwable $e) {
                // ignorar si ya existe o no hay privilegios.
            }
        }
        smxChatTryIndex($pdo, $table, 'idx_pair_id', 'from_login, to_login, id');
        smxChatTryIndex($pdo, $table, 'idx_to_from_read', 'to_login, from_login, read_at, id');
        smxChatTryIndex($pdo, $table, 'idx_to_from_delivered', 'to_login, from_login, delivered_at, id');
        smxChatSchemaCacheTouch($masterDb);
    }
    try {
        $pdo->query("SELECT delivered_at FROM {$table} LIMIT 1");
        $hasDeliveredAt = true;
    } catch (Throwable $e) {
        $hasDeliveredAt = false;
    }
    try {
        $pdo->query("SELECT from_login FROM {$typingTable} LIMIT 1");
        $hasTypingTable = true;
    } catch (Throwable $e) {
        $hasTypingTable = false;
    }
    $idxPairIdHint = smxChatIndexExists($pdo, $masterDb, $tableNameOnly, 'idx_pair_id') ? ' FORCE INDEX (idx_pair_id)' : '';
    $idxToFromReadHint = smxChatIndexExists($pdo, $masterDb, $tableNameOnly, 'idx_to_from_read') ? ' FORCE INDEX (idx_to_from_read)' : '';

    if ($action === 'push_public_key') {
        $cfg = smxChatPushConfig();
        echo json_encode([
            'success' => true,
            'enabled' => smxChatPushEnabled(),
            'public_key' => (string)($cfg['publicKey'] ?? ''),
        ]);
        exit;
    }

    if ($action === 'push_subscribe') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST ?: [];
        }
        $subscription = $input['subscription'] ?? null;
        if (!is_array($subscription) || trim((string)($subscription['endpoint'] ?? '')) === '') {
            echo json_encode(['success' => false, 'error' => 'Suscripción inválida']);
            exit;
        }
        $saved = smxChatPushUpsertSubscription(
            $pdo,
            $masterDb,
            $me,
            $myCompanyId,
            $subscription,
            (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            trim((string)($input['content_encoding'] ?? ''))
        );
        echo json_encode(['success' => $saved]);
        exit;
    }

    if ($action === 'push_unsubscribe') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST ?: [];
        }
        $endpoint = trim((string)($input['endpoint'] ?? ''));
        if ($endpoint === '') {
            echo json_encode(['success' => false, 'error' => 'Endpoint inválido']);
            exit;
        }
        smxChatPushDeactivateSubscription($pdo, $masterDb, $me, $endpoint);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'subscription_inbox_users') {
        if (!smxChatCanAccessSubscriptionInbox($myCompanyId)) {
            echo json_encode(['success' => false, 'error' => 'Acceso restringido']);
            exit;
        }

        $supportRow = smxChatResolveSubscriptionSupport($pdo, $masterDb);
        $supportId = (int)($supportRow['id_login'] ?? 0);
        if ($supportId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Usuario de suscripciones no configurado']);
            exit;
        }

        $q = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(120, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $supportCompanies = array_map('intval', SISTEMAX_SUPPORT_COMPANIES);
        $excludeSupport = implode(',', array_fill(0, count($supportCompanies), '?'));

        $baseSql = "
            FROM (
                SELECT peer_login, MAX(max_id) AS max_id
                FROM (
                    SELECT to_login AS peer_login, MAX(id) AS max_id
                    FROM {$table}{$idxPairIdHint}
                    WHERE from_login = ?
                    GROUP BY to_login
                    UNION ALL
                    SELECT from_login AS peer_login, MAX(id) AS max_id
                    FROM {$table}{$idxPairIdHint}
                    WHERE to_login = ?
                    GROUP BY from_login
                ) recent_ids
                GROUP BY peer_login
            ) conv
            INNER JOIN {$masterDb}.sec_users u ON u.id_login = conv.peer_login
            LEFT JOIN {$masterDb}.empresa e ON e.id_empresa = u.id_empresa
            LEFT JOIN {$table} msg ON msg.id = conv.max_id
            LEFT JOIN (
                SELECT from_login AS peer_login, COUNT(*) AS unread_count
                FROM {$table}{$idxToFromReadHint}
                WHERE to_login = ?
                  AND read_at IS NULL
                GROUP BY from_login
            ) un ON un.peer_login = conv.peer_login
            WHERE COALESCE(u.active, 'Y') = 'Y'
              AND COALESCE(u.id_empresa, 0) NOT IN ({$excludeSupport})
        ";

        $baseParams = array_merge([$supportId, $supportId, $supportId], $supportCompanies);
        if ($q !== '') {
            $baseSql .= " AND (u.login LIKE ? OR u.name LIKE ? OR e.empresa LIKE ?)";
            $qLike = '%' . $q . '%';
            $baseParams[] = $qLike;
            $baseParams[] = $qLike;
            $baseParams[] = $qLike;
        }

        $countSql = "SELECT COUNT(*) AS total_users, COUNT(DISTINCT COALESCE(u.id_empresa, 0)) AS total_companies " . $baseSql;
        $stCount = $pdo->prepare($countSql);
        $stCount->execute($baseParams);
        $countRow = $stCount->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalUsers = (int)($countRow['total_users'] ?? 0);
        $totalCompanies = (int)($countRow['total_companies'] ?? 0);

        $sql = "
            SELECT
                u.id_login,
                COALESCE(NULLIF(TRIM(u.name), ''), u.login) AS usuario_nombre,
                u.login AS usuario_login,
                COALESCE(u.foto, '') AS usuario_foto,
                COALESCE(u.id_empresa, 0) AS id_empresa,
                COALESCE(NULLIF(TRIM(e.empresa), ''), CONCAT('Empresa #', COALESCE(u.id_empresa, 0))) AS empresa_nombre,
                COALESCE(e.logos, '') AS empresa_logo,
                COALESCE(un.unread_count, 0) AS unread_count,
                msg.sent_at AS last_sent,
                CASE
                    WHEN COALESCE(NULLIF(TRIM(msg.mensaje), ''), '') <> '' THEN msg.mensaje
                    WHEN msg.tipo = 'image' THEN '[Imagen]'
                    WHEN msg.tipo = 'video' THEN '[Video]'
                    WHEN msg.tipo = 'audio' THEN '[Audio]'
                    WHEN msg.tipo = 'file' THEN CONCAT('[Archivo] ', COALESCE(msg.media_name, 'archivo'))
                    ELSE ''
                END AS last_message
            " . $baseSql . "
            ORDER BY (msg.sent_at IS NULL), msg.sent_at DESC, empresa_nombre ASC, usuario_nombre ASC
            LIMIT " . (int)($limit + 1) . " OFFSET " . (int)$offset;

        $st = $pdo->prepare($sql);
        $st->execute($baseParams);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = [];
        foreach ($rows as $r) {
            $id = (int)($r['id_login'] ?? 0);
            $name = (string)($r['usuario_nombre'] ?? '');
            $items[] = [
                'id_login' => $id,
                'usuario_nombre' => $name,
                'usuario_login' => (string)($r['usuario_login'] ?? ''),
                'avatar_url' => smxChatAvatarForUser((string)($r['usuario_foto'] ?? ''), $name, $id),
                'avatar_initials' => smxChatInitials($name),
                'id_empresa' => (int)($r['id_empresa'] ?? 0),
                'empresa_nombre' => (string)($r['empresa_nombre'] ?? ''),
                'empresa_logo' => smxChatLogo((string)($r['empresa_logo'] ?? ''), (int)($r['id_empresa'] ?? 0)),
                'unread_count' => (int)($r['unread_count'] ?? 0),
                'last_message' => (string)($r['last_message'] ?? ''),
                'last_sent' => (string)($r['last_sent'] ?? ''),
            ];
        }

        echo json_encode([
            'success' => true,
            'items' => $items,
            'total_users' => $totalUsers,
            'total_companies' => $totalCompanies,
            'support_user' => [
                'id_login' => $supportId,
                'usuario_nombre' => 'SistemaX Suscripciones',
                'usuario_login' => (string)($supportRow['usuario_login'] ?? ''),
            ],
            'has_more' => $hasMore,
            'next_offset' => $offset + count($items),
        ]);
        exit;
    }

    if ($action === 'subscription_inbox_thread') {
        if (!smxChatCanAccessSubscriptionInbox($myCompanyId)) {
            echo json_encode(['success' => false, 'error' => 'Acceso restringido']);
            exit;
        }

        $supportRow = smxChatResolveSubscriptionSupport($pdo, $masterDb);
        $supportId = (int)($supportRow['id_login'] ?? 0);
        $peer = (int)($_GET['peer_login'] ?? 0);
        $limit = max(10, min(120, (int)($_GET['limit'] ?? 80)));
        if ($supportId <= 0 || $peer <= 0) {
            echo json_encode(['success' => false, 'error' => 'Conversación inválida']);
            exit;
        }

        $threadCols = "id, from_login, to_login, mensaje, tipo, media_url, media_mime, media_name, media_size, media_duration_sec, sent_at, read_at";
        $threadCols .= $hasDeliveredAt ? ", delivered_at" : ", NULL AS delivered_at";
        $outerDeliveredSelect = $hasDeliveredAt ? "x.delivered_at" : "NULL AS delivered_at";
        $threadLimit = (int)$limit;
        $st = $pdo->prepare("
            SELECT
                x.id, x.from_login, x.to_login, x.mensaje, x.tipo, x.media_url, x.media_mime, x.media_name, x.media_size, x.media_duration_sec,
                x.sent_at, {$outerDeliveredSelect}, x.read_at
            FROM (
                (SELECT {$threadCols}
                 FROM {$table}{$idxPairIdHint}
                 WHERE from_login = :support1 AND to_login = :peer1
                 ORDER BY id DESC
                 LIMIT {$threadLimit})
                UNION ALL
                (SELECT {$threadCols}
                 FROM {$table}{$idxPairIdHint}
                 WHERE from_login = :peer2 AND to_login = :support2
                 ORDER BY id DESC
                 LIMIT {$threadLimit})
            ) x
            ORDER BY x.id DESC
            LIMIT {$threadLimit}
        ");
        $st->execute([
            ':support1' => $supportId,
            ':peer1' => $peer,
            ':peer2' => $peer,
            ':support2' => $supportId,
        ]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rows = array_reverse($rows);

        $participants = [$supportId => true, $peer => true];
        $participantIds = array_map('intval', array_keys($participants));
        $in = implode(',', array_fill(0, count($participantIds), '?'));
        $stUsers = $pdo->prepare("
            SELECT
                u.id_login,
                COALESCE(NULLIF(TRIM(u.name), ''), u.login) AS from_nombre,
                COALESCE(u.foto, '') AS from_foto,
                COALESCE(u.id_empresa, 0) AS from_id_empresa,
                COALESCE(e.logos, '') AS from_empresa_logo
            FROM {$masterDb}.sec_users u
            LEFT JOIN {$masterDb}.empresa e ON e.id_empresa = u.id_empresa
            WHERE u.id_login IN ({$in})
        ");
        $stUsers->execute($participantIds);
        $userMap = [];
        foreach (($stUsers->fetchAll(PDO::FETCH_ASSOC) ?: []) as $ur) {
            $userMap[(int)$ur['id_login']] = $ur;
        }

        $developerSupportId = smxChatDeveloperSupportId($pdo, $masterDb);
        foreach ($rows as &$r) {
            $fromLogin = (int)($r['from_login'] ?? 0);
            $userRow = $userMap[$fromLogin] ?? [];
            $fromName = ($fromLogin === $supportId)
                ? 'SistemaX Suscripciones'
                : (string)($userRow['from_nombre'] ?? '');
            $fromEmpresa = (int)($userRow['from_id_empresa'] ?? 0);
            $r['from_nombre'] = $fromName;
            $r['from_foto'] = (string)($userRow['from_foto'] ?? '');
            $r['from_id_empresa'] = $fromEmpresa;
            $r['from_empresa_logo'] = (string)($userRow['from_empresa_logo'] ?? '');
            $r['from_avatar_url'] = $fromLogin === $developerSupportId
                ? smxChatDeveloperAvatar()
                : smxChatAvatarForUser((string)($r['from_foto'] ?? ''), (string)($r['from_nombre'] ?? ''), $fromLogin);
            $r['from_avatar_initials'] = smxChatInitials($fromName !== '' ? $fromName : ('U' . $fromLogin));
            $r['from_empresa_logo_url'] = smxChatLogo((string)($r['from_empresa_logo'] ?? ''), $fromEmpresa);
        }
        unset($r);

        try {
            $stRead = $pdo->prepare("
                UPDATE {$table}
                SET read_at = NOW()
                WHERE from_login = :peer
                  AND to_login = :support
                  AND read_at IS NULL
            ");
            $stRead->execute([':peer' => $peer, ':support' => $supportId]);
        } catch (Throwable $e) {
            // no romper thread
        }

        echo json_encode(['success' => true, 'items' => $rows]);
        exit;
    }

    if ($action === 'subscription_inbox_send') {
        if (!smxChatCanAccessSubscriptionInbox($myCompanyId)) {
            echo json_encode(['success' => false, 'error' => 'Acceso restringido']);
            exit;
        }

        $supportRow = smxChatResolveSubscriptionSupport($pdo, $masterDb);
        $supportId = (int)($supportRow['id_login'] ?? 0);
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = $_POST ?: [];
        $peer = (int)($input['peer_login'] ?? 0);
        $msg = trim((string)($input['mensaje'] ?? ''));
        if ($supportId <= 0 || $peer <= 0 || $msg === '') {
            echo json_encode(['success' => false, 'error' => 'Mensaje inválido']);
            exit;
        }

        $stUser = $pdo->prepare("SELECT COUNT(*) FROM {$masterDb}.sec_users WHERE id_login = :id AND COALESCE(active, 'Y') = 'Y'");
        $stUser->execute([':id' => $peer]);
        if ((int)$stUser->fetchColumn() <= 0) {
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
            exit;
        }

        $st = $pdo->prepare("
            INSERT INTO {$table}
            (from_login, to_login, mensaje, tipo, sent_at)
            VALUES
            (:support, :peer, :msg, 'text', NOW())
        ");
        $st->execute([
            ':support' => $supportId,
            ':peer' => $peer,
            ':msg' => (function_exists('mb_substr') ? mb_substr($msg, 0, 2000) : substr($msg, 0, 2000)),
        ]);

        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'users') {
        $usersStartedAt = microtime(true);
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = max(1, min(120, (int)($_GET['limit'] ?? 10)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $totalUsers = 0;
        $totalCompanies = 0;
        // Evitar que el polling del panel haga un UPDATE masivo en cada request.
        if ($hasDeliveredAt && smxChatShouldRunThrottled('chat_delivered_users_' . $masterDb . '_' . $me, 30)) {
            try {
                $stDelivered = $pdo->prepare("
                    UPDATE {$table}
                    SET delivered_at = NOW()
                    WHERE to_login = :me
                      AND delivered_at IS NULL
                ");
                $stDelivered->execute([':me' => $me]);
            } catch (Throwable $e) {
                smxChatLog('users-delivered-sync-failed', $e->getMessage(), [
                    'action' => $action,
                    'me' => $me,
                ]);
            }
        }

        $sql = "
            SELECT
                u.id_login,
                COALESCE(NULLIF(TRIM(u.name), ''), u.login) AS usuario_nombre,
                u.login AS usuario_login,
                COALESCE(u.foto, '') AS usuario_foto,
                COALESCE(u.id_empresa, 0) AS id_empresa,
                COALESCE(NULLIF(TRIM(e.empresa), ''), CONCAT('Empresa #', COALESCE(u.id_empresa, 0))) AS empresa_nombre,
                COALESCE((
                    SELECT sg.description
                    FROM {$masterDb}.sec_users_groups ug
                    INNER JOIN {$masterDb}.sec_groups sg
                        ON sg.id_grupo = ug.id_grupo
                       AND sg.group_id = ug.group_id
                    WHERE ug.id_grupo = u.id_empresa
                      AND TRIM(ug.login) = TRIM(u.login)
                    ORDER BY ug.group_id DESC
                    LIMIT 1
                ), '') AS grupo_nombre,
                COALESCE(e.logos, '') AS empresa_logo,
                COALESCE(un.unread_count, 0) AS unread_count,
                COALESCE(lastx.last_message, '') AS last_message,
                lastx.last_sent
            FROM {$masterDb}.sec_users u
            LEFT JOIN {$masterDb}.empresa e ON e.id_empresa = u.id_empresa
            LEFT JOIN (
                SELECT
                    m.from_login AS peer_login,
                    COUNT(*) AS unread_count
                FROM {$table} m{$idxToFromReadHint}
                WHERE m.to_login = ?
                  AND m.read_at IS NULL
                GROUP BY m.from_login
            ) un ON un.peer_login = u.id_login
            LEFT JOIN (
                SELECT
                    lm.peer_login,
                    msg.sent_at AS last_sent,
                    CASE
                        WHEN COALESCE(NULLIF(TRIM(msg.mensaje), ''), '') <> '' THEN msg.mensaje
                        WHEN msg.tipo = 'image' THEN '[Imagen]'
                        WHEN msg.tipo = 'video' THEN '[Video]'
                        WHEN msg.tipo = 'audio' THEN '[Audio]'
                        WHEN msg.tipo = 'file' THEN CONCAT('[Archivo] ', COALESCE(msg.media_name, 'archivo'))
                        ELSE ''
                    END AS last_message
                FROM (
                    SELECT peer_login, MAX(max_id) AS max_id
                    FROM (
                        SELECT to_login AS peer_login, MAX(id) AS max_id
                        FROM {$table} FORCE INDEX (idx_pair_id)
                        WHERE from_login = ?
                        GROUP BY to_login
                        UNION ALL
                        SELECT from_login AS peer_login, MAX(id) AS max_id
                        FROM {$table} FORCE INDEX (idx_pair_tf)
                        WHERE to_login = ?
                        GROUP BY from_login
                    ) recent_ids
                    GROUP BY peer_login
                ) lm
                INNER JOIN {$table} msg ON msg.id = lm.max_id
            ) lastx ON lastx.peer_login = u.id_login
            WHERE u.id_login <> ?
              AND COALESCE(u.active, 'Y') = 'Y'
              AND LOWER(TRIM(COALESCE(u.login, ''))) NOT LIKE '%soporte%'
              AND LOWER(TRIM(COALESCE(u.name, ''))) NOT LIKE '%soporte%'
              AND LOWER(TRIM(COALESCE(u.login, ''))) NOT LIKE '%admin%'
              AND LOWER(TRIM(COALESCE(u.name, ''))) NOT LIKE '%admin%'
        ";
        $params = [$me, $me, $me, $me];
        if ($q !== '') {
            $qLike = '%' . $q . '%';
            $sql .= " AND (u.login LIKE ? OR u.name LIKE ? OR e.empresa LIKE ?)";
            $params[] = $qLike;
            $params[] = $qLike;
            $params[] = $qLike;
        }
        $sql .= " ORDER BY (last_sent IS NULL), last_sent DESC, empresa_nombre ASC, usuario_nombre ASC";
        $sql .= " LIMIT " . (int)($limit + 1) . " OFFSET " . (int)$offset;

        try {
            $countSql = "
                SELECT
                    COUNT(*) AS total_users,
                    COUNT(DISTINCT COALESCE(u.id_empresa, 0)) AS total_companies
                FROM {$masterDb}.sec_users u
                LEFT JOIN {$masterDb}.empresa e ON e.id_empresa = u.id_empresa
                WHERE u.id_login <> ?
                  AND COALESCE(u.active, 'Y') = 'Y'
                  AND LOWER(TRIM(COALESCE(u.login, ''))) NOT LIKE '%soporte%'
                  AND LOWER(TRIM(COALESCE(u.name, ''))) NOT LIKE '%soporte%'
                  AND LOWER(TRIM(COALESCE(u.login, ''))) NOT LIKE '%admin%'
                  AND LOWER(TRIM(COALESCE(u.name, ''))) NOT LIKE '%admin%'
            ";
            $countParams = [$me];
            if ($q !== '') {
                $qLikeCount = '%' . $q . '%';
                $countSql .= " AND (u.login LIKE ? OR u.name LIKE ? OR e.empresa LIKE ?)";
                $countParams[] = $qLikeCount;
                $countParams[] = $qLikeCount;
                $countParams[] = $qLikeCount;
            }
            $stCount = $pdo->prepare($countSql);
            $stCount->execute($countParams);
            $countRow = $stCount->fetch(PDO::FETCH_ASSOC) ?: [];
            $totalUsers = (int)($countRow['total_users'] ?? 0);
            $totalCompanies = (int)($countRow['total_companies'] ?? 0);
        } catch (Throwable $e) {
            smxChatLog('users-count-failed', $e->getMessage(), [
                'action' => $action,
                'me' => $me,
                'q' => $q,
            ]);
            $totalUsers = 0;
            $totalCompanies = 0;
        }

        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            smxChatLog('users-query-fallback', $e->getMessage(), [
                'action' => $action,
                'me' => $me,
                'q' => $q,
                'offset' => $offset,
                'limit' => $limit,
            ]);
            // Fallback minimal si algún esquema difiere en producción.
            $sql2 = "
                SELECT
                    u.id_login,
                    COALESCE(NULLIF(TRIM(u.name), ''), u.login) AS usuario_nombre,
                    u.login AS usuario_login,
                    COALESCE(u.foto, '') AS usuario_foto,
                    COALESCE(u.id_empresa, 0) AS id_empresa,
                    CONCAT('Empresa #', COALESCE(u.id_empresa, 0)) AS empresa_nombre,
                    '' AS grupo_nombre,
                    '' AS empresa_logo,
                    0 AS unread_count,
                    '' AS last_message,
                    NULL AS last_sent
                FROM {$masterDb}.sec_users u
                WHERE u.id_login <> :me_self
                  AND LOWER(TRIM(COALESCE(u.login, ''))) NOT LIKE '%soporte%'
                  AND LOWER(TRIM(COALESCE(u.name, ''))) NOT LIKE '%soporte%'
                  AND LOWER(TRIM(COALESCE(u.login, ''))) NOT LIKE '%admin%'
                  AND LOWER(TRIM(COALESCE(u.name, ''))) NOT LIKE '%admin%'
            ";
            $params2 = [$me];
            if ($q !== '') {
                $qLike2 = '%' . $q . '%';
                $sql2 .= " AND (u.login LIKE ? OR u.name LIKE ?)";
                $params2[] = $qLike2;
                $params2[] = $qLike2;
            }
            $sql2 .= " ORDER BY usuario_nombre ASC LIMIT " . (int)($limit + 1) . " OFFSET " . (int)$offset;
            try {
                $st2 = $pdo->prepare($sql2);
                $st2->execute($params2);
                $rows = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e2) {
                smxChatLog('users-query-fallback-failed', $e2->getMessage(), [
                    'action' => $action,
                    'me' => $me,
                    'q' => $q,
                    'offset' => $offset,
                    'limit' => $limit,
                ]);
                $rows = [];
            }
        }
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $typingMap = [];
        if ($hasTypingTable && !empty($rows)) {
            $peerIds = array_values(array_unique(array_map(static fn($r) => (int)($r['id_login'] ?? 0), $rows)));
            $peerIds = array_values(array_filter($peerIds, static fn($v) => $v > 0));
            if (!empty($peerIds)) {
                $in = implode(',', array_fill(0, count($peerIds), '?'));
                $sqlTyping = "SELECT from_login FROM {$typingTable} WHERE to_login = ? AND updated_at >= (NOW() - INTERVAL 8 SECOND) AND from_login IN ({$in})";
                $stTyping = $pdo->prepare($sqlTyping);
                $bind = array_merge([$me], $peerIds);
                $stTyping->execute($bind);
                foreach (($stTyping->fetchAll(PDO::FETCH_ASSOC) ?: []) as $tr) {
                    $typingMap[(int)$tr['from_login']] = true;
                }
            }
        }

        $items = [];
        $unreadTotal = 0;
        try {
            $stUnreadTotal = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE to_login = :me AND read_at IS NULL");
            $stUnreadTotal->execute([':me' => $me]);
            $unreadTotal = (int)$stUnreadTotal->fetchColumn();
        } catch (Throwable $e) {
            smxChatLog('users-unread-total-failed', $e->getMessage(), [
                'action' => $action,
                'me' => $me,
            ]);
            $unreadTotal = 0;
        }
        foreach ($rows as $r) {
            $id = (int)$r['id_login'];
            $name = (string)$r['usuario_nombre'];
            $unread = (int)($r['unread_count'] ?? 0);
            $items[] = [
                'id_login' => $id,
                'usuario_nombre' => $name,
                'usuario_login' => (string)$r['usuario_login'],
                'avatar_url' => smxChatAvatarForUser((string)$r['usuario_foto'], $name, $id),
                'avatar_initials' => smxChatInitials($name),
                'id_empresa' => (int)$r['id_empresa'],
                'empresa_nombre' => (string)$r['empresa_nombre'],
                'grupo_nombre' => (string)($r['grupo_nombre'] ?? ''),
                'empresa_logo' => smxChatLogo((string)$r['empresa_logo'], (int)$r['id_empresa']),
                'unread_count' => $unread,
                'last_message' => (string)($r['last_message'] ?? ''),
                'last_sent' => (string)($r['last_sent'] ?? ''),
                'peer_typing' => !empty($typingMap[$id]),
            ];
        }

        $developerChat = null;
        $developerRows = smxChatResolveDeveloperSupportUsers($pdo, $masterDb);
        if (!empty($developerRows)) {
            $developerRow = $developerRows[0];
            $developerId = (int)($developerRow['id_login'] ?? 0);
            $developerIds = array_values(array_unique(array_map(static fn($row) => (int)($row['id_login'] ?? 0), $developerRows)));
            if ($developerId > 0 && !empty($developerIds)) {
                $developerUnread = 0;
                $developerTyping = false;
                $developerLastMessage = 'Consultas directas con el equipo de desarrollo.';
                $developerLastSent = '';
                foreach ($items as $item) {
                    $id = (int)($item['id_login'] ?? 0);
                    if (!in_array($id, $developerIds, true)) {
                        continue;
                    }
                    $developerUnread += (int)($item['unread_count'] ?? 0);
                    $developerTyping = $developerTyping || !empty($item['peer_typing']);
                    $itemLastSent = (string)($item['last_sent'] ?? '');
                    if ($itemLastSent !== '' && ($developerLastSent === '' || strcmp($itemLastSent, $developerLastSent) > 0)) {
                        $developerLastSent = $itemLastSent;
                        $developerLastMessage = trim((string)($item['last_message'] ?? '')) !== ''
                            ? (string)$item['last_message']
                            : $developerLastMessage;
                    }
                }
                $developerItem = [
                    'id_login' => $developerId,
                    'usuario_nombre' => 'Chatear con Programadores',
                    'usuario_login' => (string)($developerRow['usuario_login'] ?? ''),
                    'avatar_url' => smxChatDeveloperAvatar(),
                    'avatar_initials' => 'DEV',
                    'id_empresa' => (int)($developerRow['id_empresa'] ?? 0),
                    'empresa_nombre' => 'SISTEMAX',
                    'grupo_nombre' => 'Programadores',
                    'empresa_logo' => smxChatLogo((string)($developerRow['empresa_logo'] ?? ''), (int)($developerRow['id_empresa'] ?? 0)),
                    'unread_count' => $developerUnread,
                    'last_message' => $developerLastMessage,
                    'last_sent' => $developerLastSent,
                    'peer_typing' => $developerTyping,
                    'is_developer_support' => true,
                ];
                $items = array_values(array_filter($items, static fn($item) => !in_array((int)($item['id_login'] ?? 0), $developerIds, true)));
                array_unshift($items, $developerItem);
                $developerChat = $developerItem;
            }
        }

        $subscriptionChat = null;

        echo json_encode([
            'success' => true,
            'items' => $items,
            'unread_total' => $unreadTotal,
            'total_users' => $totalUsers,
            'total_companies' => $totalCompanies,
            'developer_chat' => $developerChat,
            'subscription_chat' => $subscriptionChat,
            'has_more' => $hasMore,
            'next_offset' => $offset + count($items),
        ]);
        smxChatLogSlow('users-slow', $usersStartedAt, [
            'action' => $action,
            'me' => $me,
            'q' => $q,
            'offset' => $offset,
            'limit' => $limit,
            'items' => count($items),
        ]);
        exit;
    }

    if ($action === 'thread') {
        $threadStartedAt = microtime(true);
        $peer = (int)($_GET['peer_login'] ?? 0);
        $limit = max(10, min(120, (int)($_GET['limit'] ?? 40)));
        if ($peer <= 0) {
            echo json_encode(['success' => false, 'error' => 'Usuario destino inválido']);
            exit;
        }
        $developerIds = smxChatDeveloperSupportIds($pdo, $masterDb);
        $isDeveloperThread = !empty($developerIds) && in_array($peer, $developerIds, true);
        $threadPeers = $isDeveloperThread ? $developerIds : [$peer];
        $threadPeers = array_values(array_filter(array_unique(array_map('intval', $threadPeers)), static fn($id) => $id > 0));
        if (empty($threadPeers)) {
            echo json_encode(['success' => false, 'error' => 'Usuario destino inválido']);
            exit;
        }

        // Se entrega y lee la conversación al abrirla.
        if ($hasDeliveredAt) {
            try {
                if ($isDeveloperThread) {
                    $inDelivered = implode(',', array_fill(0, count($threadPeers), '?'));
                    $stDelivered = $pdo->prepare("
                        UPDATE {$table}
                        SET delivered_at = NOW()
                        WHERE from_login IN ({$inDelivered})
                          AND to_login = ?
                          AND delivered_at IS NULL
                    ");
                    $stDelivered->execute(array_merge($threadPeers, [$me]));
                } else {
                    $stDelivered = $pdo->prepare("
                        UPDATE {$table}
                        SET delivered_at = NOW()
                        WHERE from_login = :peer
                          AND to_login = :me
                          AND delivered_at IS NULL
                    ");
                    $stDelivered->execute([':peer' => $peer, ':me' => $me]);
                }
            } catch (Throwable $e) {
                smxChatLog('thread-delivered-sync-failed', $e->getMessage(), [
                    'action' => $action,
                    'me' => $me,
                    'peer' => $peer,
                ]);
            }
        }

        $threadCols = "id, from_login, to_login, mensaje, tipo, media_url, media_mime, media_name, media_size, media_duration_sec, sent_at, read_at";
        if ($hasDeliveredAt) {
            $threadCols .= ", delivered_at";
        } else {
            $threadCols .= ", NULL AS delivered_at";
        }
        $outerDeliveredSelect = $hasDeliveredAt ? "x.delivered_at" : "NULL AS delivered_at";
        $threadLimit = (int)$limit;
        if ($isDeveloperThread) {
            $inThread = implode(',', array_fill(0, count($threadPeers), '?'));
            $st = $pdo->prepare("
                SELECT
                    x.id, x.from_login, x.to_login, x.mensaje, x.tipo, x.media_url, x.media_mime, x.media_name, x.media_size, x.media_duration_sec,
                    x.sent_at, {$outerDeliveredSelect}, x.read_at
                FROM (
                    (SELECT {$threadCols}
                     FROM {$table}{$idxPairIdHint}
                     WHERE from_login = ? AND to_login IN ({$inThread})
                     ORDER BY id DESC
                     LIMIT {$threadLimit})
                    UNION ALL
                    (SELECT {$threadCols}
                     FROM {$table}{$idxPairIdHint}
                     WHERE from_login IN ({$inThread}) AND to_login = ?
                     ORDER BY id DESC
                     LIMIT {$threadLimit})
                ) x
                ORDER BY x.id DESC
                LIMIT {$threadLimit}
            ");
            $st->execute(array_merge([$me], $threadPeers, $threadPeers, [$me]));
        } else {
            $st = $pdo->prepare("
                SELECT
                    x.id, x.from_login, x.to_login, x.mensaje, x.tipo, x.media_url, x.media_mime, x.media_name, x.media_size, x.media_duration_sec,
                    x.sent_at, {$outerDeliveredSelect}, x.read_at
                FROM (
                    (SELECT {$threadCols}
                     FROM {$table}{$idxPairIdHint}
                     WHERE from_login = :me1 AND to_login = :peer1
                     ORDER BY id DESC
                     LIMIT {$threadLimit})
                    UNION ALL
                    (SELECT {$threadCols}
                     FROM {$table}{$idxPairIdHint}
                     WHERE from_login = :peer2 AND to_login = :me2
                     ORDER BY id DESC
                     LIMIT {$threadLimit})
                ) x
                ORDER BY x.id DESC
                LIMIT {$threadLimit}
            ");
            $st->execute([
                ':me1' => $me, ':peer1' => $peer,
                ':peer2' => $peer, ':me2' => $me
            ]);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rows = array_reverse($rows);
        $participants = [$me => true];
        foreach ($threadPeers as $threadPeer) {
            $participants[$threadPeer] = true;
        }
        $userMap = [];
        if (!empty($participants)) {
            $participantIds = array_map('intval', array_keys($participants));
            $in = implode(',', array_fill(0, count($participantIds), '?'));
            $stUsers = $pdo->prepare("
                SELECT
                    u.id_login,
                    COALESCE(NULLIF(TRIM(u.name), ''), u.login) AS from_nombre,
                    COALESCE(u.foto, '') AS from_foto,
                    COALESCE(u.id_empresa, 0) AS from_id_empresa,
                    COALESCE(e.logos, '') AS from_empresa_logo
                FROM {$masterDb}.sec_users u
                LEFT JOIN {$masterDb}.empresa e ON e.id_empresa = u.id_empresa
                WHERE u.id_login IN ({$in})
            ");
            $stUsers->execute($participantIds);
            foreach (($stUsers->fetchAll(PDO::FETCH_ASSOC) ?: []) as $ur) {
                $userMap[(int)$ur['id_login']] = $ur;
            }
        }
        $developerSupportId = smxChatDeveloperSupportId($pdo, $masterDb);
        foreach ($rows as &$r) {
            $fromLogin = (int)($r['from_login'] ?? 0);
            $userRow = $userMap[$fromLogin] ?? [];
            $fromName = (string)($userRow['from_nombre'] ?? '');
            $fromEmpresa = (int)($userRow['from_id_empresa'] ?? 0);
            $r['from_nombre'] = $fromName;
            $r['from_foto'] = (string)($userRow['from_foto'] ?? '');
            $r['from_id_empresa'] = $fromEmpresa;
            $r['from_empresa_logo'] = (string)($userRow['from_empresa_logo'] ?? '');
            $r['from_avatar_url'] = smxChatIsDeveloperSupportLogin($pdo, $masterDb, $fromLogin)
                ? smxChatDeveloperAvatar()
                : smxChatAvatarForUser((string)($r['from_foto'] ?? ''), (string)($r['from_nombre'] ?? ''), $fromLogin);
            $r['from_avatar_initials'] = smxChatInitials($fromName !== '' ? $fromName : ('U' . $fromLogin));
            $r['from_empresa_logo_url'] = smxChatLogo((string)($r['from_empresa_logo'] ?? ''), $fromEmpresa);
        }
        unset($r);

        // Marcar como leído lo recibido desde peer.
        try {
            if ($isDeveloperThread) {
                $inRead = implode(',', array_fill(0, count($threadPeers), '?'));
                $stRead = $pdo->prepare("
                    UPDATE {$table}
                    SET read_at = NOW()
                    WHERE from_login IN ({$inRead})
                      AND to_login = ?
                      AND read_at IS NULL
                ");
                $stRead->execute(array_merge($threadPeers, [$me]));
            } else {
                $stRead = $pdo->prepare("
                    UPDATE {$table}
                    SET read_at = NOW()
                    WHERE from_login = :peer
                      AND to_login = :me
                      AND read_at IS NULL
                ");
                $stRead->execute([':peer' => $peer, ':me' => $me]);
            }
        } catch (Throwable $e) {
            smxChatLog('thread-read-sync-failed', $e->getMessage(), [
                'action' => $action,
                'me' => $me,
                'peer' => $peer,
            ]);
        }

        $peerTyping = false;
        if ($hasTypingTable) {
            if ($isDeveloperThread) {
                $inTyping = implode(',', array_fill(0, count($threadPeers), '?'));
                $stTyping = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM {$typingTable}
                    WHERE from_login IN ({$inTyping})
                      AND to_login = ?
                      AND updated_at >= (NOW() - INTERVAL 8 SECOND)
                ");
                $stTyping->execute(array_merge($threadPeers, [$me]));
            } else {
                $stTyping = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM {$typingTable}
                    WHERE from_login = :peer
                      AND to_login = :me
                      AND updated_at >= (NOW() - INTERVAL 8 SECOND)
                ");
                $stTyping->execute([':peer' => $peer, ':me' => $me]);
            }
            $peerTyping = ((int)$stTyping->fetchColumn() > 0);
        }

        smxChatLogSlow('thread-slow', $threadStartedAt, [
            'action' => $action,
            'me' => $me,
            'peer' => $peer,
            'limit' => $limit,
            'items' => count($rows),
        ]);

        echo json_encode(['success' => true, 'items' => $rows, 'peer_typing' => $peerTyping]);
        exit;
    }

    if ($action === 'send') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = $_POST ?: [];

        $peer = (int)($input['peer_login'] ?? 0);
        $msg = trim((string)($input['mensaje'] ?? ''));
        $tipo = strtolower(trim((string)($input['tipo'] ?? 'text')));
        $mediaUrl = trim((string)($input['media_url'] ?? ''));
        $mediaMime = trim((string)($input['media_mime'] ?? ''));
        $mediaName = trim((string)($input['media_name'] ?? ''));
        $mediaSize = (int)($input['media_size'] ?? 0);
        $mediaDuration = (float)($input['media_duration_sec'] ?? 0);

        if ($peer <= 0 || ($msg === '' && $mediaUrl === '')) {
            echo json_encode(['success' => false, 'error' => 'Mensaje inválido']);
            exit;
        }
        if (!in_array($tipo, ['text', 'image', 'video', 'audio', 'file'], true)) {
            $tipo = $mediaUrl !== '' ? 'file' : 'text';
        }

        $developerIds = smxChatDeveloperSupportIds($pdo, $masterDb);
        $targetPeers = !empty($developerIds) && in_array($peer, $developerIds, true)
            ? array_values(array_filter($developerIds, static fn($id) => $id > 0))
            : [$peer];
        $targetPeers = array_values(array_unique(array_filter(array_map('intval', $targetPeers), static fn($id) => $id > 0)));
        if (empty($targetPeers)) {
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
            exit;
        }
        $inUsers = implode(',', array_fill(0, count($targetPeers), '?'));
        $stUser = $pdo->prepare("SELECT COUNT(*) FROM {$masterDb}.sec_users WHERE id_login IN ({$inUsers}) AND COALESCE(active, 'Y') = 'Y'");
        $stUser->execute($targetPeers);
        if ((int)$stUser->fetchColumn() !== count($targetPeers)) {
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
            exit;
        }

        if (!in_array($myCompanyId, SISTEMAX_SUPPORT_COMPANIES, true)) {
            $supportRow = smxChatResolveSubscriptionSupport($pdo, $masterDb);
            $supportId = (int)($supportRow['id_login'] ?? 0);
            if ($supportId > 0 && $peer === $supportId) {
                $dailyLimit = smxChatSubscriptionDailyLimit();
                $dailyUsed = smxChatSubscriptionDailyUsage($pdo, $table, $me, $peer);
                if ($dailyUsed >= $dailyLimit) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Límite diario alcanzado. Solo puedes enviar 10 mensajes por día a SistemaX Suscripciones.',
                        'daily_limit' => $dailyLimit,
                        'daily_used' => $dailyUsed,
                        'daily_remaining' => 0,
                    ]);
                    exit;
                }
            }
        }
        $shouldNotifySupportInbox = !in_array($myCompanyId, SISTEMAX_SUPPORT_COMPANIES, true)
            && isset($supportId)
            && $supportId > 0
            && $peer === $supportId;

        $st = $pdo->prepare("
            INSERT INTO {$table}
            (from_login, to_login, mensaje, tipo, media_url, media_mime, media_name, media_size, media_duration_sec, sent_at)
            VALUES
            (:me, :peer, :msg, :tipo, :media_url, :media_mime, :media_name, :media_size, :media_duration_sec, NOW())
        ");
        $firstInsertId = 0;
        foreach ($targetPeers as $targetPeer) {
            $st->execute([
                ':me' => $me,
                ':peer' => $targetPeer,
                ':msg' => (function_exists('mb_substr') ? mb_substr($msg, 0, 2000) : substr($msg, 0, 2000)),
                ':tipo' => $tipo,
                ':media_url' => ($mediaUrl !== '' ? $mediaUrl : null),
                ':media_mime' => ($mediaMime !== '' ? (function_exists('mb_substr') ? mb_substr($mediaMime, 0, 120) : substr($mediaMime, 0, 120)) : null),
                ':media_name' => ($mediaName !== '' ? (function_exists('mb_substr') ? mb_substr($mediaName, 0, 180) : substr($mediaName, 0, 180)) : null),
                ':media_size' => ($mediaSize > 0 ? $mediaSize : null),
                ':media_duration_sec' => ($mediaDuration > 0 ? $mediaDuration : null),
            ]);
            if ($firstInsertId <= 0) {
                $firstInsertId = (int)$pdo->lastInsertId();
            }
        }

        if ($shouldNotifySupportInbox) {
            smxChatNotifySupportInboxPush($pdo, $masterDb, $me, smxChatPushPreview($msg, $tipo, $mediaName));
        }

        echo json_encode(['success' => true, 'id' => $firstInsertId]);
        exit;
    }

    if ($action === 'upload') {
        $peer = (int)($_POST['peer_login'] ?? $_GET['peer_login'] ?? 0);
        $msg = trim((string)($_POST['mensaje'] ?? ''));
        $durationSec = (float)($_POST['duration_sec'] ?? 0);
        if ($peer <= 0) {
            echo json_encode(['success' => false, 'error' => 'Usuario destino inválido']);
            exit;
        }
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            echo json_encode(['success' => false, 'error' => 'Archivo no recibido']);
            exit;
        }
        $f = $_FILES['file'];
        if ((int)($f['error'] ?? 1) !== 0 || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) {
            echo json_encode(['success' => false, 'error' => 'Upload inválido']);
            exit;
        }
        $size = (int)($f['size'] ?? 0);
        if ($size <= 0 || $size > 60 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'Archivo excede el límite (60MB)']);
            exit;
        }

        $developerIds = smxChatDeveloperSupportIds($pdo, $masterDb);
        $targetPeers = !empty($developerIds) && in_array($peer, $developerIds, true)
            ? array_values(array_filter($developerIds, static fn($id) => $id > 0))
            : [$peer];
        $targetPeers = array_values(array_unique(array_filter(array_map('intval', $targetPeers), static fn($id) => $id > 0)));
        if (empty($targetPeers)) {
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
            exit;
        }
        $inUsers = implode(',', array_fill(0, count($targetPeers), '?'));
        $stUser = $pdo->prepare("SELECT COUNT(*) FROM {$masterDb}.sec_users WHERE id_login IN ({$inUsers}) AND COALESCE(active, 'Y') = 'Y'");
        $stUser->execute($targetPeers);
        if ((int)$stUser->fetchColumn() !== count($targetPeers)) {
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
            exit;
        }

        if (!in_array($myCompanyId, SISTEMAX_SUPPORT_COMPANIES, true)) {
            $supportRow = smxChatResolveSubscriptionSupport($pdo, $masterDb);
            $supportId = (int)($supportRow['id_login'] ?? 0);
            if ($supportId > 0 && $peer === $supportId) {
                $dailyLimit = smxChatSubscriptionDailyLimit();
                $dailyUsed = smxChatSubscriptionDailyUsage($pdo, $table, $me, $peer);
                if ($dailyUsed >= $dailyLimit) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Límite diario alcanzado. Solo puedes enviar 10 mensajes por día a SistemaX Suscripciones.',
                        'daily_limit' => $dailyLimit,
                        'daily_used' => $dailyUsed,
                        'daily_remaining' => 0,
                    ]);
                    exit;
                }
            }
        }
        $shouldNotifySupportInbox = !in_array($myCompanyId, SISTEMAX_SUPPORT_COMPANIES, true)
            && isset($supportId)
            && $supportId > 0
            && $peer === $supportId;

        $origName = (string)($f['name'] ?? 'archivo');
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName) ?: 'archivo';
        $tmp = (string)$f['tmp_name'];
        $mime = (string)($f['type'] ?? '');
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $det = finfo_file($fi, $tmp);
                finfo_close($fi);
                if (is_string($det) && $det !== '') $mime = $det;
            }
        }
        $tipo = smxChatDetectTipoByMime($mime);

        [$absDir, $relDir] = smxChatUploadDir();
        $ext = pathinfo($safeName, PATHINFO_EXTENSION);
        $token = bin2hex(random_bytes(8));
        $fileName = date('Ymd_His') . '_' . $token . ($ext !== '' ? ('.' . $ext) : '');
        $absFile = $absDir . '/' . $fileName;
        if (!@move_uploaded_file($tmp, $absFile)) {
            echo json_encode(['success' => false, 'error' => 'No se pudo guardar archivo']);
            exit;
        }
        @chmod($absFile, 0644);
        $mediaUrl = $relDir . '/' . $fileName;

        $st = $pdo->prepare("
            INSERT INTO {$table}
            (from_login, to_login, mensaje, tipo, media_url, media_mime, media_name, media_size, media_duration_sec, sent_at)
            VALUES
            (:me, :peer, :msg, :tipo, :media_url, :media_mime, :media_name, :media_size, :media_duration_sec, NOW())
        ");
        $firstInsertId = 0;
        foreach ($targetPeers as $targetPeer) {
            $st->execute([
                ':me' => $me,
                ':peer' => $targetPeer,
                ':msg' => (function_exists('mb_substr') ? mb_substr($msg, 0, 2000) : substr($msg, 0, 2000)),
                ':tipo' => $tipo,
                ':media_url' => $mediaUrl,
                ':media_mime' => (function_exists('mb_substr') ? mb_substr($mime, 0, 120) : substr($mime, 0, 120)),
                ':media_name' => (function_exists('mb_substr') ? mb_substr($safeName, 0, 180) : substr($safeName, 0, 180)),
                ':media_size' => $size,
                ':media_duration_sec' => ($durationSec > 0 ? $durationSec : null),
            ]);
            if ($firstInsertId <= 0) {
                $firstInsertId = (int)$pdo->lastInsertId();
            }
        }

        if ($shouldNotifySupportInbox) {
            smxChatNotifySupportInboxPush($pdo, $masterDb, $me, smxChatPushPreview($msg, $tipo, $safeName));
        }

        echo json_encode([
            'success' => true,
            'id' => $firstInsertId,
            'tipo' => $tipo,
            'media_url' => $mediaUrl,
            'media_name' => $safeName,
            'media_mime' => $mime,
            'media_size' => $size
        ]);
        exit;
    }

    if ($action === 'typing') {
        if (!$hasTypingTable) {
            echo json_encode(['success' => true, 'disabled' => true]);
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = $_POST ?: [];
        $peer = (int)($input['peer_login'] ?? 0);
        $isTyping = (int)($input['is_typing'] ?? 0) === 1;
        if ($peer <= 0) {
            echo json_encode(['success' => false, 'error' => 'Usuario destino inválido']);
            exit;
        }
        $developerIds = smxChatDeveloperSupportIds($pdo, $masterDb);
        $targetPeers = !empty($developerIds) && in_array($peer, $developerIds, true)
            ? array_values(array_filter($developerIds, static fn($id) => $id > 0))
            : [$peer];
        $targetPeers = array_values(array_unique(array_filter(array_map('intval', $targetPeers), static fn($id) => $id > 0)));
        if (empty($targetPeers)) {
            echo json_encode(['success' => false, 'error' => 'Usuario destino inválido']);
            exit;
        }
        if ($isTyping) {
            $st = $pdo->prepare("
                INSERT INTO {$typingTable} (from_login, to_login, updated_at)
                VALUES (:me, :peer, NOW())
                ON DUPLICATE KEY UPDATE updated_at = NOW()
            ");
            foreach ($targetPeers as $targetPeer) {
                $st->execute([':me' => $me, ':peer' => $targetPeer]);
            }
        } else {
            if (count($targetPeers) === 1) {
                $st = $pdo->prepare("DELETE FROM {$typingTable} WHERE from_login = :me AND to_login = :peer");
                $st->execute([':me' => $me, ':peer' => $targetPeers[0]]);
            } else {
                $inTyping = implode(',', array_fill(0, count($targetPeers), '?'));
                $st = $pdo->prepare("DELETE FROM {$typingTable} WHERE from_login = ? AND to_login IN ({$inTyping})");
                $st->execute(array_merge([$me], $targetPeers));
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción no soportada']);
} catch (Throwable $e) {
    // Evitar HTTP 400 para que el frontend no dispare incidente técnico.
    smxChatLog('chat-api-fatal', $e->getMessage(), [
        'action' => (string)($_GET['action'] ?? $_POST['action'] ?? ''),
        'me' => (int)($me ?? 0),
        'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
    ]);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
