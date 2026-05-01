<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function smxPresenceJson(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function smxPresencePdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = Database::getMasterConnection();
    return $pdo;
}

function smxPresenceEnsure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_user_presence (
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
}

function smxPresenceEnsureCached(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $cacheFile = rtrim((string)sys_get_temp_dir(), '/') . '/sistemax_presence_schema_' . md5((string)MASTER_DB) . '.cache';
    $fresh = false;
    if (is_file($cacheFile)) {
        $mtime = @filemtime($cacheFile);
        $fresh = $mtime !== false && (time() - $mtime) < 3600;
    }
    if (!$fresh) {
        smxPresenceEnsure($pdo);
        @touch($cacheFile);
    }
    $done = true;
}

$loggedIn = Session::isLoggedIn();
$idEmpresa = $loggedIn ? (int)Session::getIdEmpresa() : 0;
$idLogin = $loggedIn ? (int)Session::getIdLogin() : 0;
$login = $loggedIn ? (string)($_SESSION['usuario'] ?? '') : '';
$userName = $loggedIn ? (string)($_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '') : '';
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody ?: '', true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = strtolower(trim((string)($_GET['action'] ?? $input['action'] ?? 'ping')));

if (!$loggedIn || $idEmpresa <= 0 || $idLogin <= 0) {
    smxPresenceJson(['ok' => true, 'skipped' => 'no_session']);
    exit;
}

$pdo = smxPresencePdo();
smxPresenceEnsureCached($pdo);

try {
    if ($action === 'ping') {
        $path = trim((string)($input['path'] ?? $_SERVER['HTTP_REFERER'] ?? ''));
        if ($path !== '') {
            $path = (string)(parse_url($path, PHP_URL_PATH) ?: $path);
            $path = substr($path, 0, 255);
        }
        $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_user_presence (id_empresa, id_login, login, user_name, current_path, last_seen) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE login = VALUES(login), user_name = VALUES(user_name), current_path = VALUES(current_path), last_seen = NOW()");
        $stmt->execute([$idEmpresa, $idLogin, $login, $userName, $path]);
        smxPresenceJson(['ok' => true, 'last_seen' => date('Y-m-d H:i:s')]);
    }

    if ($action === 'offline') {
        $stmt = $pdo->prepare("UPDATE " . MASTER_DB . ".smx_user_presence SET last_seen = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE id_empresa = ? AND id_login = ?");
        $stmt->execute([$idEmpresa, $idLogin]);
        smxPresenceJson(['ok' => true]);
    }

    smxPresenceJson(['ok' => false, 'error' => 'Acción no válida'], 404);
} catch (Throwable $e) {
    error_log('[presencia/api] ' . $e->getMessage());
    smxPresenceJson(['ok' => false, 'error' => $e->getMessage()], 500);
}
