<?php
/**
 * API de Notificaciones - Menú Mobile
 */

require_once __DIR__ . '/../../../config/bootstrap.php';
header('Content-Type: application/json');

// Este endpoint se consulta por polling desde el menú; si la sesión expira,
// no debe redirigir ni romper con HTML, solo responder vacío en JSON.
if (!Session::isLoggedIn()) {
    echo json_encode([
        'success' => true,
        'notifications' => [],
        'session_expired' => true
    ]);
    exit;
}

function smxPublicAssetExists(string $url): bool
{
    $url = trim($url);
    if ($url === '') return false;
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) return true;
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

function smxNotifInitials(string $name): string
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

function smxNotifImageUrl(string $raw, string $fallbackDir = '/public/_lib/file/img/empresa/'): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    // Evitar mixed-content: no devolver recursos HTTP en páginas HTTPS.
    if (str_starts_with($raw, 'http://')) return '';
    if (str_starts_with($raw, 'https://') || str_starts_with($raw, '/')) {
        if (!smxPublicAssetExists($raw)) return '';
        return $raw;
    }
    $url = rtrim($fallbackDir, '/') . '/' . ltrim($raw, '/');
    return smxPublicAssetExists($url) ? $url : '';
}

function smxNotifSvgFallback(string $text, string $bg = '#334155', string $fg = '#e2e8f0'): string
{
    $t = strtoupper(trim($text));
    if ($t === '') $t = 'SMX';
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96">'
        . '<rect width="100%" height="100%" fill="' . htmlspecialchars($bg, ENT_QUOTES, 'UTF-8') . '"/>'
        . '<text x="50%" y="54%" text-anchor="middle" font-family="Arial, sans-serif" font-size="28" fill="' . htmlspecialchars($fg, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</text>'
        . '</svg>';
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

function smxNotifCompanyFallback(): string
{
    return smxNotifSvgFallback('EM', '#1e3a8a', '#dbeafe');
}

function smxNotifAvatarFallback(int $seed = 0): string
{
    return '/public/menu/api/pixabay_proxy.php?kind=avatar&seed=' . abs($seed);
}

function smxNotifInferAvatarKind(string $name): string
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
        'valeria', 'gabriela', 'daniela', 'alejandra', 'mariana', 'patricia', 'fernanda',
        'monica', 'adriana', 'romina', 'noelia', 'yamila', 'cintia', 'cecilia', 'silvia',
        'veronica', 'graciela', 'claudia', 'rocio', 'jazmin', 'ximena'
    ];
    $maleNames = [
        'jose', 'juan', 'carlos', 'luis', 'miguel', 'pedro', 'diego', 'marcos', 'marco',
        'fabio', 'martin', 'jorge', 'hernan', 'sebastian', 'alejandro', 'daniel', 'pablo',
        'nicolas', 'ricardo', 'gustavo', 'ramon', 'oscar', 'sergio', 'roberto', 'manuel',
        'francisco', 'javier', 'matias', 'cristian', 'adrian'
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

function smxNotifAvatarFallbackForUser(string $name, int $seed = 0): string
{
    return '/public/menu/api/pixabay_proxy.php?kind=' . rawurlencode(smxNotifInferAvatarKind($name)) . '&seed=' . abs($seed);
}

function smxNotifLog(string $tag, string $message, array $ctx = []): void
{
    try {
        error_log('[menu/notificaciones][' . $tag . '] ' . $message . (!empty($ctx) ? ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''));
    } catch (Throwable $e) {
        // nunca romper por log
    }
}

function smxNotifEnsureCached(PDO $pdo, string $table): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $cacheFile = rtrim((string)sys_get_temp_dir(), '/') . '/sistemax_notif_schema_' . md5($table) . '.cache';
    $fresh = false;
    if (is_file($cacheFile)) {
        $mtime = @filemtime($cacheFile);
        $fresh = $mtime !== false && (time() - $mtime) < 3600;
    }
    if (!$fresh) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$table} (
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
            @touch($cacheFile);
        } catch (Throwable $e) {
            smxNotifLog('ensure-failed', $e->getMessage(), ['table' => $table]);
        }
    }
    $done = true;
}

try {
    $pdo = Database::getMasterConnection();
    $masterDb = Database::getMasterDbName();
    $id_empresa = (int)($_GET['id_empresa'] ?? Session::getIdEmpresa());
    $id_login = Session::getIdLogin();
    $action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? '')));
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $isAdmin = false;

    $tableUserNotif = "{$masterDb}.smx_user_notifications";
    smxNotifEnsureCached($pdo, $tableUserNotif);

    if ($action === 'mark_read') {
        $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $idsRaw = $payload['ids'] ?? ($payload['id'] ?? []);
        $markAll = !empty($payload['all']);
        $ids = is_array($idsRaw) ? $idsRaw : [$idsRaw];
        $ids = array_values(array_filter(array_map(static function ($value) {
            if (is_string($value) && preg_match('/user_notif_(\d+)/', $value, $m)) {
                return (int)$m[1];
            }
            return (int)$value;
        }, $ids), static fn($id) => (int)$id > 0));

        if ($markAll) {
            $stmtMarkAll = $pdo->prepare("
                UPDATE {$tableUserNotif}
                SET leida = 1,
                    fecha_lectura = COALESCE(fecha_lectura, NOW())
                WHERE id_dest_login = :id_login
                  AND (id_empresa = 0 OR id_empresa = :id_empresa)
                  AND leida = 0
            ");
            $stmtMarkAll->execute([
                ':id_login' => (int)$id_login,
                ':id_empresa' => (int)$id_empresa,
            ]);
            echo json_encode([
                'success' => true,
                'marked' => (int)$stmtMarkAll->rowCount(),
            ]);
            exit;
        }

        if (empty($ids)) {
            echo json_encode([
                'success' => true,
                'marked' => 0,
            ]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [(int)$id_login, (int)$id_empresa]);
        $stmtMark = $pdo->prepare("
            UPDATE {$tableUserNotif}
            SET leida = 1,
                fecha_lectura = COALESCE(fecha_lectura, NOW())
            WHERE id IN ({$placeholders})
              AND id_dest_login = ?
              AND (id_empresa = 0 OR id_empresa = ?)
              AND leida = 0
        ");
        $stmtMark->execute($params);
        echo json_encode([
            'success' => true,
            'marked' => (int)$stmtMark->rowCount(),
        ]);
        exit;
    }

    // Validación fuerte de admin desde sec_users (evita depender solo del flag en sesión).
    try {
        $stmtAdmin = $pdo->prepare("
            SELECT priv_admin, role
            FROM {$masterDb}.sec_users
            WHERE id_login = :id_login
              AND id_empresa = :id_empresa
            LIMIT 1
        ");
        $stmtAdmin->execute([':id_login' => $id_login, ':id_empresa' => $id_empresa]);
        $u = $stmtAdmin->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$u) {
            $stmtAdmin = $pdo->prepare("
                SELECT priv_admin, role
                FROM {$masterDb}.sec_users
                WHERE id_login = :id_login
                LIMIT 1
            ");
            $stmtAdmin->execute([':id_login' => $id_login]);
            $u = $stmtAdmin->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($u) {
            $privAdmin = strtoupper(trim((string)($u['priv_admin'] ?? 'N')));
            $role = strtoupper(trim((string)($u['role'] ?? '')));
            $roleAdminLike = in_array($role, ['ADMIN', 'SUPERADMIN', 'ROOT'], true)
                || (strpos($role, 'ADMIN') !== false && strpos($role, 'VENDEDOR') === false && strpos($role, 'CAJER') === false);
            $isAdmin = ($privAdmin === 'Y') || $roleAdminLike;
        }
    } catch (Throwable $e) {
        $isAdmin = false;
    }
    
    $notifications = [];
    $empresaOrigenNombre = '';
    $empresaOrigenLogo = '';
    
    // Obtener DB de empresa
    $stmt = $pdo->prepare("SELECT dbase FROM empresa WHERE id_empresa = :id");
    $stmt->execute([':id' => $id_empresa]);
    $dbEmpresa = $stmt->fetchColumn();

    try {
        $stmtEmp = $pdo->prepare("SELECT empresa, logos FROM {$masterDb}.empresa WHERE id_empresa = :id LIMIT 1");
        $stmtEmp->execute([':id' => $id_empresa]);
        $empRow = $stmtEmp->fetch(PDO::FETCH_ASSOC) ?: [];
        $empresaOrigenNombre = (string)($empRow['empresa'] ?? ('Empresa #' . $id_empresa));
        $empresaOrigenLogo = smxNotifImageUrl((string)($empRow['logos'] ?? '')) ?: smxNotifCompanyFallback();
    } catch (Throwable $e) {
        $empresaOrigenNombre = 'Empresa #' . $id_empresa;
        $empresaOrigenLogo = smxNotifCompanyFallback();
    }
    
    if ($dbEmpresa) {
        // Productos con stock bajo
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) as total 
                FROM $dbEmpresa.mercaderias 
                WHERE stock <= stock_minimo 
                AND stock_minimo > 0 
                AND activo = 1
            ");
            $stockBajo = $stmt->fetchColumn();
            
            if ($stockBajo > 0) {
                $notifications[] = [
                    'id' => 'stock_bajo',
                    'type' => 'warning',
                    'icon' => 'fas fa-exclamation-triangle',
                    'title' => 'Stock Bajo',
                    'message' => "$stockBajo producto(s) con stock bajo",
                    'time' => 'Ahora',
                    'read' => false,
                    'origin_empresa_nombre' => $empresaOrigenNombre,
                    'origin_empresa_logo' => $empresaOrigenLogo,
                    'origin_usuario_nombre' => 'Sistema',
                    'origin_usuario_avatar' => '',
                    'origin_usuario_initials' => 'SI',
                    'is_system' => true
                ];
            }
        } catch (Exception $e) {}
        
        // Ventas del día (solo admin)
        if ($isAdmin) {
            try {
                $stmt = $pdo->query("
                    SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as monto
                    FROM $dbEmpresa.factura_ventas 
                    WHERE DATE(fecha) = CURDATE()
                ");
                $ventas = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($ventas['total'] > 0) {
                    $notifications[] = [
                        'id' => 'ventas_hoy',
                        'type' => 'info',
                        'icon' => 'fas fa-chart-line',
                        'title' => 'Ventas de Hoy',
                        'message' => "{$ventas['total']} venta(s) - " . number_format($ventas['monto'], 0, ',', '.') . " Gs",
                        'time' => 'Resumen',
                        'read' => true,
                        'origin_empresa_nombre' => $empresaOrigenNombre,
                        'origin_empresa_logo' => $empresaOrigenLogo,
                        'origin_usuario_nombre' => 'Sistema',
                        'origin_usuario_avatar' => '',
                        'origin_usuario_initials' => 'SI',
                        'is_system' => true
                    ];
                }
            } catch (Exception $e) {}
        }
        
        // Cuentas por cobrar vencidas
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) as total 
                FROM $dbEmpresa.cuentas_cobrar 
                WHERE fecha_vencimiento < CURDATE() 
                AND saldo > 0
            ");
            $vencidas = $stmt->fetchColumn();
            
            if ($vencidas > 0) {
                $notifications[] = [
                    'id' => 'cxc_vencidas',
                    'type' => 'warning',
                    'icon' => 'fas fa-clock',
                    'title' => 'Cuentas Vencidas',
                    'message' => "$vencidas cuenta(s) por cobrar vencidas",
                    'time' => 'Importante',
                    'read' => false,
                    'origin_empresa_nombre' => $empresaOrigenNombre,
                    'origin_empresa_logo' => $empresaOrigenLogo,
                    'origin_usuario_nombre' => 'Sistema',
                    'origin_usuario_avatar' => '',
                    'origin_usuario_initials' => 'SI',
                    'is_system' => true
                ];
            }
        } catch (Exception $e) {}

        // Solicitudes pendientes de autorización de anulación en caja (solo admin)
        if ($isAdmin) {
            try {
                $stmt = $pdo->query("
                    SELECT COUNT(*) as total
                    FROM $dbEmpresa.caja_void_approvals
                    WHERE estado = 'PENDIENTE'
                ");
                $pendientesVoid = (int)$stmt->fetchColumn();

                if ($pendientesVoid > 0) {
                    $notifications[] = [
                        'id' => 'caja_void_approvals',
                        'type' => 'warning',
                        'icon' => 'fas fa-user-shield',
                        'title' => 'Autorización requerida',
                        'message' => "$pendientesVoid solicitud(es) de anulación pendientes en Caja",
                        'time' => 'Ahora',
                        'url' => '/public/pos/autorizaciones.php',
                        'read' => false,
                        'origin_empresa_nombre' => $empresaOrigenNombre,
                        'origin_empresa_logo' => $empresaOrigenLogo,
                        'origin_usuario_nombre' => 'Sistema',
                        'origin_usuario_avatar' => '',
                        'origin_usuario_initials' => 'SI',
                        'is_system' => true
                    ];
                }
            } catch (Exception $e) {}

            try {
                $stmt = $pdo->query("
                    SELECT COUNT(*) as total
                    FROM $dbEmpresa.pos_price_override_approvals
                    WHERE estado = 'PENDIENTE'
                ");
                $pendientesPrice = (int)$stmt->fetchColumn();

                if ($pendientesPrice > 0) {
                    $notifications[] = [
                        'id' => 'pos_price_override_approvals',
                        'type' => 'warning',
                        'icon' => 'fas fa-tags',
                        'title' => 'Precios bajo mínimo',
                        'message' => "$pendientesPrice solicitud(es) de precio especial pendientes",
                        'time' => 'Ahora',
                        'url' => '/public/pos/autorizaciones.php',
                        'read' => false,
                        'origin_empresa_nombre' => $empresaOrigenNombre,
                        'origin_empresa_logo' => $empresaOrigenLogo,
                        'origin_usuario_nombre' => 'Sistema',
                        'origin_usuario_avatar' => '',
                        'origin_usuario_initials' => 'SI',
                        'is_system' => true
                    ];
                }
            } catch (Exception $e) {}

            try {
                $stmt = $pdo->query("
                    SELECT COUNT(*) as total
                    FROM $dbEmpresa.pos_item_delete_approvals
                    WHERE estado = 'PENDIENTE'
                ");
                $pendientesDelete = (int)$stmt->fetchColumn();

                if ($pendientesDelete > 0) {
                    $notifications[] = [
                        'id' => 'pos_item_delete_approvals',
                        'type' => 'warning',
                        'icon' => 'fas fa-trash-alt',
                        'title' => 'Eliminar ítems',
                        'message' => "$pendientesDelete solicitud(es) pendientes para eliminar ítems del POS",
                        'time' => 'Ahora',
                        'url' => '/public/pos/autorizaciones.php',
                        'read' => false,
                        'origin_empresa_nombre' => $empresaOrigenNombre,
                        'origin_empresa_logo' => $empresaOrigenLogo,
                        'origin_usuario_nombre' => 'Sistema',
                        'origin_usuario_avatar' => '',
                        'origin_usuario_initials' => 'SI',
                        'is_system' => true
                    ];
                }
            } catch (Exception $e) {}
        }
    }

    // Notificaciones directas por usuario (filtradas por sesión).
    try {
        $stmtDirect = $pdo->prepare("
            SELECT n.id, n.tipo, n.titulo, n.mensaje, n.url, n.leida, n.fecha_creacion, n.id_remitente_login,
                   COALESCE(NULLIF(TRIM(u.name), ''), u.login, CONCAT('#', n.id_remitente_login)) AS remitente,
                   COALESCE(u.foto, '') AS remitente_foto,
                   COALESCE(e2.empresa, '') AS origen_empresa_nombre,
                   COALESCE(e2.logos, '') AS origen_empresa_logo
            FROM {$tableUserNotif} n
            LEFT JOIN {$masterDb}.sec_users u ON u.id_login = n.id_remitente_login
            LEFT JOIN {$masterDb}.empresa e2 ON e2.id_empresa = n.id_empresa
            WHERE n.id_dest_login = :id_login
              AND (n.id_empresa = 0 OR n.id_empresa = :id_empresa)
            ORDER BY n.id DESC
            LIMIT 50
        ");
        $stmtDirect->execute([
            ':id_login' => (int)$id_login,
            ':id_empresa' => (int)$id_empresa
        ]);
        $directRows = $stmtDirect->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($directRows as $row) {
            $remitenteNombre = (string)($row['remitente'] ?: 'Usuario');
            $notifications[] = [
                'id' => 'user_notif_' . (int)$row['id'],
                'type' => (string)($row['tipo'] ?: 'info'),
                'icon' => 'fas fa-bell',
                'title' => (string)($row['titulo'] ?: 'Notificación'),
                'message' => (string)($row['mensaje'] ?: ''),
                'time' => (string)($row['fecha_creacion'] ?: 'Ahora'),
                'url' => (string)($row['url'] ?? ''),
                'read' => ((int)($row['leida'] ?? 0) === 1),
                'origin_empresa_nombre' => (string)($row['origen_empresa_nombre'] ?: $empresaOrigenNombre),
                'origin_empresa_logo' => smxNotifImageUrl((string)($row['origen_empresa_logo'] ?? '')) ?: smxNotifCompanyFallback(),
                'origin_usuario_nombre' => $remitenteNombre,
                'origin_usuario_avatar' => smxNotifImageUrl((string)($row['remitente_foto'] ?? ''), '/public/_lib/file/img/') ?: smxNotifAvatarFallbackForUser($remitenteNombre, (int)($row['id_remitente_login'] ?? $row['id'] ?? 0)),
                'origin_usuario_initials' => smxNotifInitials($remitenteNombre),
                'is_system' => ((int)($row['id_remitente_login'] ?? 0) <= 0)
            ];
        }
    } catch (Throwable $e) {
        // Mantener silencioso para no romper menú.
    }
    
    echo json_encode([
        'success' => true,
        'notifications' => $notifications
    ]);
    
} catch (Throwable $e) {
    smxNotifLog('fatal', $e->getMessage(), [
        'id_empresa' => (int)($id_empresa ?? 0),
        'id_login' => (int)($id_login ?? 0),
        'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
    ]);
    echo json_encode([
        'success' => true,
        'notifications' => [],
        'error' => $e->getMessage()
    ]);
}
