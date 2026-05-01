<?php
require_once __DIR__ . '/../../../config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
Session::requireLogin('/public/login.php');

function geoApiHasAccess(): bool
{
    return Permission::hasAccess('geolocalizacion_config') || Permission::hasAccess('app_grid_tracking_movil');
}

if (!geoApiHasAccess()) {
    Permission::requireAccess('geolocalizacion_config');
}

function geoJson(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function geoPdo(): PDO
{
    return Database::getMasterConnection();
}

function geoReadInput(): array
{
    $raw = file_get_contents('php://input');
    $data = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
    return is_array($data) ? $data : [];
}

function geoNormalizeMarkerLabel(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $value = mb_substr($value, 0, 24, 'UTF-8');
    return preg_replace('/\s+/', ' ', $value);
}

function geoNormalizeIconType(string $value): string
{
    $value = strtolower(trim($value));
    $allowed = ['auto', 'moto', 'auto_car', 'sedan', 'suv', 'pickup', 'furgon', 'camion'];
    return in_array($value, $allowed, true) ? $value : 'auto';
}

function geoNormalizeIconColor(string $value): string
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return '';
    }
    if (preg_match('/^#[0-9a-f]{6}$/', $value)) {
        return $value;
    }
    $map = [
        'cyan' => '#06b6d4',
        'blue' => '#2563eb',
        'emerald' => '#10b981',
        'amber' => '#f59e0b',
        'red' => '#ef4444',
        'violet' => '#8b5cf6',
        'slate' => '#475569',
        'black' => '#111827',
        'indigo' => '#4f46e5',
    ];
    return $map[$value] ?? '';
}

function geoSetupSecret(): string
{
    return (string)(getenv('SISTEMAX_MOBILE_SETUP_SECRET') ?: (__DIR__ . '|' . MASTER_DB . '|mobile-setup-v1'));
}

function geoCreateSetupToken(array $ctx, int $ttlSeconds = 900): string
{
    $payload = [
        'id_empresa' => (int)($ctx['id_empresa'] ?? 0),
        'id_login' => (int)($ctx['id_login'] ?? 0),
        'login' => (string)($ctx['login'] ?? ''),
        'user_name' => (string)($ctx['user_name'] ?? ''),
        'exp' => time() + max(60, min(3600, $ttlSeconds)),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $body = rtrim(strtr(base64_encode((string)$json), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $body, geoSetupSecret());
    return $body . '.' . $sig;
}

function geoContext(): array
{
    return [
        'id_empresa' => (int)Session::getIdEmpresa(),
        'id_login' => (int)Session::getIdLogin(),
        'login' => (string)($_SESSION['usuario'] ?? $_SESSION['login'] ?? ''),
        'user_name' => (string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? ($_SESSION['usuario'] ?? '')),
        'is_support' => in_array((int)Session::getIdEmpresa(), SISTEMAX_SUPPORT_COMPANIES, true),
    ];
}

try {
    $pdo = geoPdo();
    $ctx = geoContext();
    $action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
    $input = geoReadInput();

    if ($action === 'list') {
        $q = trim((string)($_GET['q'] ?? ''));
        $empresaFiltro = (int)($_GET['id_empresa'] ?? 0);
        $onlineOnly = (int)($_GET['online_only'] ?? 0) === 1;
        $where = ["1=1"];
        $bind = [];

        if (empty($ctx['is_support'])) {
            $where[] = "d.id_empresa = :id_empresa_ctx";
            $bind[':id_empresa_ctx'] = (int)$ctx['id_empresa'];
        } elseif ($empresaFiltro > 0) {
            $where[] = "d.id_empresa = :id_empresa";
            $bind[':id_empresa'] = $empresaFiltro;
        }

        if ($q !== '') {
            $where[] = "(d.device_name LIKE :q OR d.user_name LIKE :q OR d.company_name LIKE :q OR d.platform LIKE :q OR d.device_uuid LIKE :q)";
            $bind[':q'] = '%' . $q . '%';
        }

        if ($onlineOnly) {
            $where[] = "d.status = 'online'";
        }

        $sql = "
            SELECT
                d.id,
                d.id_empresa,
                d.id_login,
                d.company_name,
                d.user_name,
                d.login_name,
                d.device_uuid,
                d.device_name,
                d.marker_label,
                d.icon_type,
                d.icon_color,
                d.platform,
                d.platform_version,
                d.app_version,
                d.status,
                d.tracking_enabled,
                d.last_lat,
                d.last_lng,
                d.last_accuracy_m,
                d.last_fix_at,
                d.last_seen_at,
                d.battery_pct,
                d.network_type
            FROM " . MASTER_DB . ".smx_mobile_track_devices d
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE(d.last_seen_at, d.updated_at) DESC, d.id DESC
            LIMIT 500
        ";
        $st = $pdo->prepare($sql);
        $st->execute($bind);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stats = [
            'total' => count($items),
            'tracking_on' => count(array_filter($items, static fn($it) => (int)($it['tracking_enabled'] ?? 0) === 1)),
            'online' => count(array_filter($items, static fn($it) => (string)($it['status'] ?? '') === 'online')),
            'companies' => count(array_unique(array_map(static fn($it) => (int)($it['id_empresa'] ?? 0), $items))),
        ];
        geoJson(['ok' => true, 'data' => ['items' => $items, 'stats' => $stats]]);
    }

    if ($action === 'save') {
        $deviceId = (int)($input['device_id'] ?? 0);
        if ($deviceId <= 0) {
            geoJson(['ok' => false, 'error' => 'Dispositivo inválido'], 422);
        }

        $sqlDevice = "
            SELECT id, id_empresa
            FROM " . MASTER_DB . ".smx_mobile_track_devices
            WHERE id = :id
        ";
        $bindDevice = [':id' => $deviceId];
        if (empty($ctx['is_support'])) {
            $sqlDevice .= " AND id_empresa = :id_empresa";
            $bindDevice[':id_empresa'] = (int)$ctx['id_empresa'];
        }
        $sqlDevice .= " LIMIT 1";
        $stDevice = $pdo->prepare($sqlDevice);
        $stDevice->execute($bindDevice);
        $device = $stDevice->fetch(PDO::FETCH_ASSOC);
        if (!$device) {
            geoJson(['ok' => false, 'error' => 'Dispositivo no encontrado'], 404);
        }

        $st = $pdo->prepare("
            UPDATE " . MASTER_DB . ".smx_mobile_track_devices
            SET
                marker_label = :marker_label,
                icon_type = :icon_type,
                icon_color = :icon_color,
                tracking_enabled = :tracking_enabled
            WHERE id = :id
        ");
        $st->execute([
            ':id' => $deviceId,
            ':marker_label' => geoNormalizeMarkerLabel((string)($input['marker_label'] ?? '')),
            ':icon_type' => geoNormalizeIconType((string)($input['icon_type'] ?? 'auto')),
            ':icon_color' => geoNormalizeIconColor((string)($input['icon_color'] ?? '#0ea5e9')),
            ':tracking_enabled' => !empty($input['tracking_enabled']) ? 1 : 0,
        ]);

        geoJson(['ok' => true, 'message' => 'Configuración guardada']);
    }

    if ($action === 'delete') {
        $deviceId = (int)($input['device_id'] ?? 0);
        if ($deviceId <= 0) {
            geoJson(['ok' => false, 'error' => 'Dispositivo inválido'], 422);
        }

        $sqlDevice = "
            SELECT id, device_name, id_empresa
            FROM " . MASTER_DB . ".smx_mobile_track_devices
            WHERE id = :id
        ";
        $bindDevice = [':id' => $deviceId];
        if (empty($ctx['is_support'])) {
            $sqlDevice .= " AND id_empresa = :id_empresa";
            $bindDevice[':id_empresa'] = (int)$ctx['id_empresa'];
        }
        $sqlDevice .= " LIMIT 1";
        $stDevice = $pdo->prepare($sqlDevice);
        $stDevice->execute($bindDevice);
        $device = $stDevice->fetch(PDO::FETCH_ASSOC);
        if (!$device) {
            geoJson(['ok' => false, 'error' => 'Dispositivo no encontrado'], 404);
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM " . MASTER_DB . ".smx_mobile_track_positions WHERE device_id = :id")->execute([':id' => $deviceId]);
        $pdo->prepare("DELETE FROM " . MASTER_DB . ".smx_mobile_track_agent_tokens WHERE device_id = :id")->execute([':id' => $deviceId]);
        try {
            $pdo->prepare("DELETE FROM " . MASTER_DB . ".smx_mobile_track_fcm_tokens WHERE device_id = :id")->execute([':id' => $deviceId]);
        } catch (Throwable $e) {
        }
        $pdo->prepare("DELETE FROM " . MASTER_DB . ".smx_mobile_track_devices WHERE id = :id")->execute([':id' => $deviceId]);
        $pdo->commit();

        geoJson(['ok' => true, 'message' => 'Dispositivo eliminado']);
    }

    if ($action === 'issue_setup_token') {
        $setupToken = geoCreateSetupToken($ctx, 900);
        geoJson([
            'ok' => true,
            'data' => [
                'setup_token' => $setupToken,
                'expires_at' => gmdate('c', time() + 900),
                'api_url' => '/public/api/mobile_tracking.php',
            ],
        ]);
    }

    geoJson(['ok' => false, 'error' => 'Acción inválida'], 404);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    geoJson(['ok' => false, 'error' => $e->getMessage()], 500);
}
