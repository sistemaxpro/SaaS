<?php
require_once __DIR__ . '/../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
Session::requireLogin('/public/login.php');

const SX_SUPPORT_SHADOW_COMPANIES = SISTEMAX_SUPPORT_COMPANIES;

function sxshadow_json(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sxshadow_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = Database::getMasterConnection();
    return $pdo;
}

function sxshadow_ctx(): array {
    return [
        'id_empresa' => (int)Session::getIdEmpresa(),
        'id_login' => (int)Session::getIdLogin(),
        'login' => (string)($_SESSION['usuario'] ?? ''),
        'name' => (string)($_SESSION['user_name'] ?? $_SESSION['usuario'] ?? ''),
        'is_admin' => Session::isAdmin(),
    ];
}

function sxshadow_require_support(): array {
    $ctx = sxshadow_ctx();
    if (!in_array($ctx['id_empresa'], SX_SUPPORT_SHADOW_COMPANIES, true)) {
        sxshadow_json(['ok' => false, 'error' => 'Modo soporte reservado a empresas ' . implode(', ', SX_SUPPORT_SHADOW_COMPANIES)], 403);
    }
    if (!$ctx['is_admin']) {
        sxshadow_json(['ok' => false, 'error' => 'Se requiere perfil administrador para suplantar'], 403);
    }
    return $ctx;
}

function sxshadow_request(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function sxshadow_ensure_audit(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS " . MASTER_DB . ".smx_support_shadow_audit (
        id BIGINT NOT NULL AUTO_INCREMENT,
        support_company_id INT NOT NULL,
        support_login_id INT NOT NULL,
        support_login VARCHAR(120) DEFAULT NULL,
        target_company_id INT NOT NULL,
        target_login_id INT NOT NULL,
        target_login VARCHAR(120) DEFAULT NULL,
        action VARCHAR(20) NOT NULL,
        note VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_support_created (support_company_id, support_login_id, created_at),
        KEY idx_target_created (target_company_id, target_login_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function sxshadow_company(PDO $pdo, int $idEmpresa): ?array {
    $stmt = $pdo->prepare("SELECT * FROM " . MASTER_DB . ".empresa WHERE id_empresa = ? LIMIT 1");
    $stmt->execute([$idEmpresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function sxshadow_user(PDO $pdo, int $idEmpresa, int $idLogin): ?array {
    $stmt = $pdo->prepare("SELECT * FROM " . MASTER_DB . ".sec_users WHERE id_empresa = ? AND id_login = ? LIMIT 1");
    $stmt->execute([$idEmpresa, $idLogin]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function sxshadow_log(PDO $pdo, array $support, array $targetUser, int $targetCompanyId, string $action, string $note = ''): void {
    sxshadow_ensure_audit($pdo);
    $stmt = $pdo->prepare("INSERT INTO " . MASTER_DB . ".smx_support_shadow_audit (support_company_id, support_login_id, support_login, target_company_id, target_login_id, target_login, action, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        (int)$support['id_empresa'],
        (int)$support['id_login'],
        (string)$support['login'],
        $targetCompanyId,
        (int)$targetUser['id_login'],
        (string)($targetUser['login'] ?? ''),
        $action,
        $note,
    ]);
}

$pdo = sxshadow_pdo();
$ctx = sxshadow_ctx();
$input = sxshadow_request();
$action = (string)($_GET['action'] ?? $_POST['action'] ?? ($input['action'] ?? 'meta'));

try {
    if ($action === 'meta') {
        $shadow = (array)($_SESSION['support_shadow'] ?? []);
        sxshadow_json([
            'ok' => true,
            'shadow_active' => !empty($shadow['active']),
            'shadow' => $shadow,
            'current' => $ctx,
            'can_support' => in_array($ctx['id_empresa'], SX_SUPPORT_SHADOW_COMPANIES, true) && $ctx['is_admin'],
        ]);
    }

    if ($action === 'companies') {
        sxshadow_require_support();
        $q = trim((string)($_GET['q'] ?? ''));
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare("SELECT id_empresa, empresa, COALESCE(active,'Y') AS active FROM " . MASTER_DB . ".empresa WHERE (? = '' OR empresa LIKE ?) ORDER BY CASE WHEN COALESCE(active,'Y')='Y' THEN 0 ELSE 1 END, empresa ASC LIMIT 40");
        $stmt->execute([$q, $like]);
        sxshadow_json(['ok' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    if ($action === 'users') {
        sxshadow_require_support();
        $companyId = (int)($_GET['id_empresa'] ?? 0);
        if ($companyId <= 0) sxshadow_json(['ok' => false, 'error' => 'Empresa requerida'], 422);
        $q = trim((string)($_GET['q'] ?? ''));
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare("SELECT id_login, login, name, role, priv_admin, COALESCE(active,'Y') AS active FROM " . MASTER_DB . ".sec_users WHERE id_empresa = ? AND (? = '' OR login LIKE ? OR name LIKE ?) ORDER BY CASE WHEN COALESCE(active,'Y')='Y' THEN 0 ELSE 1 END, name ASC, login ASC LIMIT 60");
        $stmt->execute([$companyId, $q, $like, $like]);
        sxshadow_json(['ok' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
    }

    if ($action === 'start') {
        $support = sxshadow_require_support();
        $targetCompanyId = (int)($input['target_company_id'] ?? 0);
        $targetLoginId = (int)($input['target_login_id'] ?? 0);
        if ($targetCompanyId <= 0 || $targetLoginId <= 0) sxshadow_json(['ok' => false, 'error' => 'Empresa y usuario requeridos'], 422);
        $targetCompany = sxshadow_company($pdo, $targetCompanyId);
        if (!$targetCompany) sxshadow_json(['ok' => false, 'error' => 'Empresa destino no encontrada'], 404);
        if (strtoupper((string)($targetCompany['active'] ?? 'Y')) !== 'Y') sxshadow_json(['ok' => false, 'error' => 'La empresa destino está inactiva'], 422);
        $targetUser = sxshadow_user($pdo, $targetCompanyId, $targetLoginId);
        if (!$targetUser) sxshadow_json(['ok' => false, 'error' => 'Usuario destino no encontrado'], 404);
        if (strtoupper((string)($targetUser['active'] ?? 'Y')) !== 'Y') sxshadow_json(['ok' => false, 'error' => 'El usuario destino está inactivo'], 422);

        if (empty($_SESSION['support_shadow_original'])) {
            $_SESSION['support_shadow_original'] = [
                'id_login' => $support['id_login'],
                'usuario' => (string)($_SESSION['usuario'] ?? ''),
                'usr_priv_admin' => (string)($_SESSION['usr_priv_admin'] ?? 'N'),
                'group_id' => $_SESSION['group_id'] ?? null,
                'group_name' => $_SESSION['group_name'] ?? null,
                'user_name' => (string)($_SESSION['user_name'] ?? ''),
                'user_email' => (string)($_SESSION['user_email'] ?? ''),
                'id_empresa' => (int)($_SESSION['id_empresa'] ?? 0),
                'dbu' => (string)($_SESSION['dbu'] ?? ''),
                'server' => (string)($_SESSION['server'] ?? ''),
                'user' => (string)($_SESSION['user'] ?? ''),
                'password' => (string)($_SESSION['password'] ?? ''),
            ];
        }

        Session::setUser($targetUser);
        Session::setEmpresa($targetCompany);
        $_SESSION['support_shadow'] = [
            'active' => true,
            'support_company_id' => (int)$support['id_empresa'],
            'support_login_id' => (int)$support['id_login'],
            'support_login' => (string)$support['login'],
            'support_name' => (string)$support['name'],
            'target_company_id' => $targetCompanyId,
            'target_company_name' => (string)($targetCompany['empresa'] ?? ('Empresa #' . $targetCompanyId)),
            'target_login_id' => (int)$targetUser['id_login'],
            'target_login' => (string)($targetUser['login'] ?? ''),
            'target_name' => (string)($targetUser['name'] ?? $targetUser['login']),
            'started_at' => date('Y-m-d H:i:s'),
            'readonly' => 0,
        ];

        sxshadow_log($pdo, $support, $targetUser, $targetCompanyId, 'start', 'Ingreso auditado de soporte');
        sxshadow_json(['ok' => true, 'redirect' => '/public/menu/menu.php', 'shadow' => $_SESSION['support_shadow']]);
    }

    if ($action === 'stop') {
        $shadow = (array)($_SESSION['support_shadow'] ?? []);
        $original = (array)($_SESSION['support_shadow_original'] ?? []);
        if (empty($shadow['active']) || empty($original['id_login']) || empty($original['id_empresa'])) {
            sxshadow_json(['ok' => true, 'restored' => false]);
        }

        $supportUser = sxshadow_user($pdo, (int)$original['id_empresa'], (int)$original['id_login']);
        $supportCompany = sxshadow_company($pdo, (int)$original['id_empresa']);
        if (!$supportUser || !$supportCompany) {
            sxshadow_json(['ok' => false, 'error' => 'No se pudo restaurar la sesión original'], 500);
        }

        $supportForLog = [
            'id_empresa' => (int)$original['id_empresa'],
            'id_login' => (int)$original['id_login'],
            'login' => (string)($original['usuario'] ?? ''),
            'name' => (string)($original['user_name'] ?? $original['usuario'] ?? ''),
        ];
        $targetUser = [
            'id_login' => (int)($shadow['target_login_id'] ?? 0),
            'login' => (string)($shadow['target_login'] ?? ''),
        ];
        sxshadow_log($pdo, $supportForLog, $targetUser, (int)($shadow['target_company_id'] ?? 0), 'stop', 'Salida de soporte auditado');

        unset($_SESSION['support_shadow']);
        unset($_SESSION['support_shadow_original']);
        Session::setUser($supportUser);
        Session::setEmpresa($supportCompany);

        sxshadow_json(['ok' => true, 'restored' => true, 'redirect' => '/public/soporte_shadow/index.php']);
    }

    sxshadow_json(['ok' => false, 'error' => 'Acción no válida'], 404);
} catch (Throwable $e) {
    error_log('[soporte_shadow/api] ' . $e->getMessage());
    sxshadow_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
