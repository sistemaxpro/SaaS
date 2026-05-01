<?php
require_once __DIR__ . '/../../../config/bootstrap.php';
Session::requireLogin('/public/login.php');

header('Content-Type: application/json; charset=utf-8');

function smxPublicAssetExists(string $url): bool
{
    $url = trim($url);
    if ($url === '') return false;
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg', 'avif'], true)) {
            return false;
        }
        return true;
    }
    $projectRoot = dirname(__DIR__, 3);
    if (str_starts_with($url, '/public/')) {
        $fs = $projectRoot . $url;
        return is_file($fs);
    }
    if (str_starts_with($url, '/_lib/')) {
        $fs = $projectRoot . '/public' . $url;
        return is_file($fs);
    }
    return true;
}

function smxIsAdminByRole(PDO $pdo, string $masterDb, int $idLogin, int $idEmpresa): bool
{
    $stmt = $pdo->prepare("
        SELECT priv_admin, role
        FROM {$masterDb}.sec_users
        WHERE id_login = :id_login
          AND id_empresa = :id_empresa
        LIMIT 1
    ");
    $stmt->execute([':id_login' => $idLogin, ':id_empresa' => $idEmpresa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $privAdmin = strtoupper(trim((string)($row['priv_admin'] ?? 'N')));
    $role = strtoupper(trim((string)($row['role'] ?? '')));
    if ($role === '' && $privAdmin !== 'Y') {
        $stmt = $pdo->prepare("
            SELECT priv_admin, role
            FROM {$masterDb}.sec_users
            WHERE id_login = :id_login
            LIMIT 1
        ");
        $stmt->execute([':id_login' => $idLogin]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $privAdmin = strtoupper(trim((string)($row['priv_admin'] ?? 'N')));
        $role = strtoupper(trim((string)($row['role'] ?? '')));
    }
    return $privAdmin === 'Y'
        || in_array($role, ['ADMIN', 'SUPERADMIN', 'ROOT'], true)
        || (strpos($role, 'ADMIN') !== false && strpos($role, 'VENDEDOR') === false && strpos($role, 'CAJER') === false);
}

function smxLogoUrl(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    // Evitar mixed-content: no devolver recursos HTTP en páginas HTTPS.
    if (str_starts_with($raw, 'http://')) return '';
    if (str_starts_with($raw, 'https://') || str_starts_with($raw, '/')) {
        if (!smxPublicAssetExists($raw)) return '';
        return $raw;
    }
    $url = '/public/_lib/file/img/empresa/' . ltrim($raw, '/');
    return smxPublicAssetExists($url) ? $url : '';
}

function smxUserAvatarUrl(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    // Evitar mixed-content: no devolver recursos HTTP en páginas HTTPS.
    if (str_starts_with($raw, 'http://')) return '';
    if (str_starts_with($raw, 'https://') || str_starts_with($raw, '/')) {
        if (!smxPublicAssetExists($raw)) return '';
        return $raw;
    }
    $ext = strtolower((string)pathinfo($raw, PATHINFO_EXTENSION));
    if ($ext !== '' && !in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg', 'avif'], true)) {
        return '';
    }
    $url = '/public/_lib/file/usuario/' . ltrim($raw, '/');
    return smxPublicAssetExists($url) ? $url : '';
}

function smxSvgFallback(string $text, string $bg = '#334155', string $fg = '#e2e8f0'): string
{
    $t = strtoupper(trim($text));
    if ($t === '') $t = 'SMX';
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96">'
        . '<rect width="100%" height="100%" fill="' . htmlspecialchars($bg, ENT_QUOTES, 'UTF-8') . '"/>'
        . '<text x="50%" y="54%" text-anchor="middle" font-family="Arial, sans-serif" font-size="28" fill="' . htmlspecialchars($fg, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</text>'
        . '</svg>';
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

function smxCompanyFallback(): string
{
    return smxSvgFallback('EM', '#1e3a8a', '#dbeafe');
}

function smxAvatarFallback(): string
{
    return smxSvgFallback('US', '#334155', '#e2e8f0');
}

try {
    $pdo = Database::getMasterConnection();
    $masterDb = Database::getMasterDbName();

    $action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'meta')));
    $idEmpresa = (int)($_GET['id_empresa'] ?? $_POST['id_empresa'] ?? Session::getIdEmpresa());
    $idLogin = (int)Session::getIdLogin();

    $isAdmin = smxIsAdminByRole($pdo, $masterDb, $idLogin, $idEmpresa);
    $sessionUserName = (string)(Session::get('usr_name') ?: Session::get('name') ?: Session::get('username') ?: Session::get('login') ?: ('Usuario #' . $idLogin));
    $sessionLogin = (string)(Session::get('username') ?: Session::get('login') ?: '');
    $sessionRole = '';
    $sessionPrivAdmin = 'N';
    $sessionEmpresaNombre = 'Empresa #' . $idEmpresa;

    try {
        $stmtSession = $pdo->prepare("
            SELECT
                COALESCE(NULLIF(TRIM(u.name), ''), u.login) AS usuario_nombre,
                COALESCE(NULLIF(TRIM(u.login), ''), '') AS usuario_login,
                COALESCE(NULLIF(TRIM(u.role), ''), '') AS role,
                COALESCE(NULLIF(TRIM(u.priv_admin), ''), 'N') AS priv_admin,
                COALESCE(NULLIF(TRIM(e.empresa), ''), CONCAT('Empresa #', COALESCE(u.id_empresa, :id_empresa))) AS empresa_nombre
            FROM {$masterDb}.sec_users u
            LEFT JOIN {$masterDb}.empresa e ON e.id_empresa = u.id_empresa
            WHERE u.id_login = :id_login
            LIMIT 1
        ");
        $stmtSession->execute([
            ':id_login' => $idLogin,
            ':id_empresa' => $idEmpresa,
        ]);
        $sessionRow = $stmtSession->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($sessionRow) {
            $sessionUserName = (string)($sessionRow['usuario_nombre'] ?? $sessionUserName);
            $sessionLogin = (string)($sessionRow['usuario_login'] ?? $sessionLogin);
            $sessionRole = (string)($sessionRow['role'] ?? '');
            $sessionPrivAdmin = strtoupper(trim((string)($sessionRow['priv_admin'] ?? 'N')));
            $sessionEmpresaNombre = (string)($sessionRow['empresa_nombre'] ?? $sessionEmpresaNombre);
        }
    } catch (Throwable $e) {
        // Mantener fallback de sesión.
    }

    $tableUserNotif = "{$masterDb}.smx_user_notifications";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS {$tableUserNotif} (
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

    if ($action === 'meta') {
        echo json_encode([
            'success' => true,
            'meta' => [
                'id_login' => $idLogin,
                'id_empresa' => $idEmpresa,
                'is_admin' => $isAdmin,
                'usuario_nombre' => $sessionUserName,
                'usuario_login' => $sessionLogin,
                'role' => $sessionRole,
                'priv_admin' => $sessionPrivAdmin,
                'empresa_nombre' => $sessionEmpresaNombre,
            ]
        ]);
        exit;
    }

    if ($action === 'users') {
        $q = trim((string)($_GET['q'] ?? ''));
        $idEmpresaFilter = (int)($_GET['id_empresa_filter'] ?? 0);
        $limit = max(20, min(200, (int)($_GET['limit'] ?? 60)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

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
        ";
        $params = [];
        if ($idEmpresaFilter > 0) {
            $sql .= " AND u.id_empresa = :id_empresa_filter";
            $params[':id_empresa_filter'] = $idEmpresaFilter;
        }
        if ($q !== '') {
            $sql .= "
                AND (
                    u.login LIKE :q_like
                    OR u.name LIKE :q_like
                    OR e.empresa LIKE :q_like
                )
            ";
            $params[':q_like'] = '%' . $q . '%';
            $params[':q_prefix'] = $q . '%';
            $sql .= "
                ORDER BY
                    CASE
                        WHEN u.login LIKE :q_prefix THEN 1
                        WHEN u.name LIKE :q_prefix THEN 2
                        WHEN e.empresa LIKE :q_prefix THEN 3
                        ELSE 9
                    END,
                    empresa_nombre ASC,
                    usuario_nombre ASC
            ";
        } else {
            $sql .= " ORDER BY empresa_nombre ASC, usuario_nombre ASC";
        }
        $sql .= " LIMIT " . (int)($limit + 1) . " OFFSET " . (int)$offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $items = [];
        foreach ($rows as $r) {
            $nombre = (string)$r['usuario_nombre'];
            $initials = '';
            foreach (preg_split('/\s+/', trim($nombre)) as $p) {
                if ($p === '') continue;
                $char = function_exists('mb_substr') ? mb_substr($p, 0, 1) : substr($p, 0, 1);
                $initials .= function_exists('mb_strtoupper') ? mb_strtoupper($char) : strtoupper($char);
                $len = function_exists('mb_strlen') ? mb_strlen($initials) : strlen($initials);
                if ($len >= 2) break;
            }
            if ($initials === '') $initials = 'U';

            $items[] = [
                'id_login' => (int)$r['id_login'],
                'usuario_nombre' => $nombre,
                'usuario_login' => (string)$r['usuario_login'],
                'avatar_url' => smxUserAvatarUrl((string)$r['usuario_foto']) ?: smxAvatarFallback(),
                'id_empresa' => (int)$r['id_empresa'],
                'empresa_nombre' => (string)$r['empresa_nombre'],
                'empresa_logo' => smxLogoUrl((string)$r['empresa_logo']) ?: smxCompanyFallback(),
                'avatar_initials' => $initials
            ];
        }

        echo json_encode([
            'success' => true,
            'items' => $items,
            'paging' => [
                'offset' => $offset,
                'limit' => $limit,
                'count' => count($items),
                'has_more' => $hasMore,
                'next_offset' => $offset + count($items),
            ]
        ]);
        exit;
    }

    if ($action === 'companies') {
        $stmt = $pdo->query("
            SELECT id_empresa, COALESCE(NULLIF(TRIM(empresa), ''), CONCAT('Empresa #', id_empresa)) AS empresa_nombre, COALESCE(logos, '') AS logos
            FROM {$masterDb}.empresa
            ORDER BY empresa_nombre ASC
        ");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id_empresa' => (int)$r['id_empresa'],
                'empresa_nombre' => (string)$r['empresa_nombre'],
                'empresa_logo' => smxLogoUrl((string)$r['logos']) ?: smxCompanyFallback(),
            ];
        }
        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    }

    if ($action === 'send') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = $_POST ?: [];

        $idDestLogin = (int)($input['id_dest_login'] ?? 0);
        $idDestEmpresa = (int)($input['id_dest_empresa'] ?? 0);
        $titulo = trim((string)($input['titulo'] ?? 'Notificación'));
        $mensaje = trim((string)($input['mensaje'] ?? ''));
        $tipo = strtolower(trim((string)($input['tipo'] ?? 'info')));
        $url = trim((string)($input['url'] ?? ''));

        if ($idDestLogin <= 0 || $idDestEmpresa <= 0) {
            throw new Exception('Debe seleccionar un usuario destino válido');
        }
        if ($mensaje === '') {
            throw new Exception('Debe ingresar un mensaje');
        }
        if (!in_array($tipo, ['info', 'success', 'warning', 'error'], true)) {
            $tipo = 'info';
        }

        $stmtCheck = $pdo->prepare("
            SELECT COUNT(*)
            FROM {$masterDb}.sec_users
            WHERE id_login = :id_login
              AND id_empresa = :id_empresa
              AND COALESCE(active, 'Y') = 'Y'
        ");
        $stmtCheck->execute([':id_login' => $idDestLogin, ':id_empresa' => $idDestEmpresa]);
        if ((int)$stmtCheck->fetchColumn() <= 0) {
            throw new Exception('Usuario destino no encontrado');
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO {$tableUserNotif}
            (id_empresa, id_dest_login, id_remitente_login, tipo, titulo, mensaje, url, leida, fecha_creacion)
            VALUES
            (:id_empresa, :id_dest_login, :id_remitente_login, :tipo, :titulo, :mensaje, :url, 0, NOW())
        ");
        $stmtIns->execute([
            ':id_empresa' => $idDestEmpresa,
            ':id_dest_login' => $idDestLogin,
            ':id_remitente_login' => $idLogin,
            ':tipo' => $tipo,
            ':titulo' => (function_exists('mb_substr') ? mb_substr(($titulo !== '' ? $titulo : 'Notificación'), 0, 150) : substr(($titulo !== '' ? $titulo : 'Notificación'), 0, 150)),
            ':mensaje' => $mensaje,
            ':url' => ($url !== '' ? (function_exists('mb_substr') ? mb_substr($url, 0, 255) : substr($url, 0, 255)) : null),
        ]);

        echo json_encode(['success' => true, 'message' => 'Notificación enviada']);
        exit;
    }

    throw new Exception('Acción no soportada');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
