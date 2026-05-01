<?php

/**
 * Menu Principal - SistemaX v1
 * Migrado desde admin_menu/menu.php
 */

require_once __DIR__ . '/../../config/bootstrap.php';

// Evitar bfcache en Safari (restauración desde caché deja pantalla en blanco)
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Verificar sesión
Session::requireLogin('/public/login.php');

// Obtener datos de sesión
$id_empresa = Session::getIdEmpresa();
$id_login = Session::getIdLogin();
$id_grupo = $_SESSION['group_id'] ?? 0;
$usr_priv_admin = ($_SESSION['usr_priv_admin'] ?? 'N') === 'Y';
$usr_ti = ($_SESSION['usr_ti'] ?? false);
$empresa = $_SESSION['empresa_nombre'] ?? '';
$usr_name = $_SESSION['user_name'] ?? $_SESSION['usuario'] ?? '';
$dbu = Session::getDbase();
$group_name = $_SESSION['group_name'] ?? 'Usuario';
$sucursal = $_SESSION['sucursal'] ?? '';
$caja = $_SESSION['caja'] ?? '';
$modulo = $_SESSION['modulo'] ?? 0;

// Obtener empresa actual (con fallback a sesión si falla master DB)
$empresaActual = null;
try {
    $empresaActual = MultiTenant::getEmpresaActual();
} catch (Exception $e) {
    error_log("Error obteniendo empresa actual en menú: " . $e->getMessage());
}
$empresa = $empresaActual['empresa'] ?? ($_SESSION['empresa_nombre'] ?? $empresa);
$supportsSubscriptionPush = in_array((int)$id_empresa, SISTEMAX_SUPPORT_COMPANIES, true);
$supportDesktopOpenCount = 0;
if (in_array((int)$id_empresa, SISTEMAX_SUPPORT_COMPANIES, true)) {
    try {
        $supportCountPdo = Database::getMasterConnection();
        $supportCountStmt = $supportCountPdo->query("
            SELECT COUNT(*)
            FROM " . MASTER_DB . ".smx_support_desktop_requests
            WHERE status IN ('pending', 'taken')
        ");
        $supportDesktopOpenCount = (int)($supportCountStmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $supportDesktopOpenCount = 0;
    }
}

// Obtener configuración de desactivación de video para la empresa
$disableBackgroundVideo = false;
if ((int)$id_empresa > 0) {
    try {
        $stmtVideoConfig = Database::getMasterConnection()->prepare("
            SELECT disable_background_video
            FROM " . MASTER_DB . ".empresa
            WHERE id_empresa = ?
        ");
        $stmtVideoConfig->execute([(int)$id_empresa]);
        $videoConfig = $stmtVideoConfig->fetch(PDO::FETCH_ASSOC);
        $disableBackgroundVideo = $videoConfig && (int)($videoConfig['disable_background_video'] ?? 0) === 1;
    } catch (Throwable $e) {
        $disableBackgroundVideo = false;
    }
}

function isEmpresaModoDesarrollo(int $idEmpresa, string $empresaNombre, string $dbase): bool
{
    $norm = static function (string $v): string {
        $v = mb_strtolower(trim($v), 'UTF-8');
        $v = preg_replace('/[^a-z0-9]/', '', $v);
        return (string)$v;
    };

    $empresaNorm = $norm($empresaNombre);
    $dbaseNorm = $norm($dbase);

    if ($empresaNorm === 'sistemaxpro' || str_contains($empresaNorm, 'sistemaxpro')) {
        return true;
    }
    if ($dbaseNorm === 'sistemaxpro' || str_contains($dbaseNorm, 'sistemaxpro')) {
        return true;
    }

    // Mantener entorno admin histórico como desarrollo.
    if ($idEmpresa === 169) {
        return true;
    }

    return false;
}

function getAppsCatalogoDesarrollo(PDO $db): array
{
    $stmt = $db->query("
        SELECT
            COALESCE(codigo, '') AS codigo,
            COALESCE(nombre, '') AS label,
            COALESCE(ruta_app, '') AS app,
            COALESCE(NULLIF(icono, ''), 'squares-2x2') AS icono,
            COALESCE(NULLIF(icono_svg, ''), '') AS icono_svg,
            COALESCE(NULLIF(icono_source, ''), '') AS icono_source,
            COALESCE(NULLIF(color, ''), 'blue') AS color3d,
            COALESCE(permiso_base, '') AS permiso_base
        FROM saas_apps_catalogo
        ORDER BY COALESCE(orden, 9999), nombre
    ");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function normalizeMenuRouteKey(string $route): string
{
    return ltrim(strtolower(trim($route)), '/');
}

function getCatalogIconSvgByRouteMap(PDO $db): array
{
    $stmt = $db->query("
        SELECT
            COALESCE(ruta_app, '') AS ruta_app,
            COALESCE(NULLIF(icono, ''), '') AS icono,
            COALESCE(NULLIF(icono_svg, ''), '') AS icono_svg,
            COALESCE(NULLIF(icono_source, ''), '') AS icono_source
        FROM saas_apps_catalogo
        WHERE activo = 1
          AND COALESCE(ruta_app, '') <> ''
        ORDER BY COALESCE(orden, 9999) ASC, id_app ASC
    ");

    $map = [];
    if (!$stmt) {
        return $map;
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $routeKey = normalizeMenuRouteKey((string)($row['ruta_app'] ?? ''));
        if ($routeKey === '' || isset($map[$routeKey])) {
            continue;
        }
        $iconoSvg  = trim((string)($row['icono_svg'] ?? ''));
        $icono     = trim((string)($row['icono'] ?? ''));
        $source    = trim((string)($row['icono_source'] ?? ''));

        // Para iconos custom sin icono_svg, construir el path desde icono
        if ($iconoSvg === '' && $source === 'custom' && $icono !== '') {
            $iconoSvg = '/public/assets/images/icons_v2/' . $icono . '.svg';
        }

        // Normalizar path relativo a absoluto (ej: 'assets/...' → '/public/assets/...')
        if ($iconoSvg !== '' && !isMenuInlineSvg($iconoSvg) && !str_starts_with($iconoSvg, '/') && !preg_match('~^https?://~i', $iconoSvg)) {
            $normalized = resolveMenuCatalogIconSvg($iconoSvg);
            $iconoSvg = $normalized !== '' ? $normalized : '/public/assets/images/icons_v2/' . basename($iconoSvg);
        }

        if ($iconoSvg !== '') {
            $map[$routeKey] = $iconoSvg;
        }
    }

    return $map;
}

function resolveMenuItemCode(string $codigoApp, string $labelApp, string $appRoute): string
{
    $codigoApp = strtolower(trim($codigoApp));
    $codeAliases = [
        'balanza_electronica' => 'balanzas',
        'cierres_turno' => 'cierres',
        'cierre_playa' => 'cierres',
        'taller_mecanico' => 'taller',
        'taller_ordenes' => 'taller',
        'taller_clientes' => 'taller',
        'taller_vehiculos' => 'taller',
        'taller_servicios' => 'taller',
    ];
    if ($codigoApp !== '') {
        return $codeAliases[$codigoApp] ?? $codigoApp;
    }

    $normalized = mb_strtolower(trim($labelApp), 'UTF-8');
    $mapByLabel = [
        'ventas' => 'ventas',
        'mi venta' => 'mi_venta',
        'compras' => 'compras',
        'productos' => 'productos',
        'contactos' => 'contactos',
        'taller' => 'taller',
        'suscripciones' => 'suscripciones',
        'mi suscripción' => 'mi_suscripcion',
        'mi suscripcion' => 'mi_suscripcion',
        'mi caja' => 'mi_caja',
        'configuración' => 'configuracion',
        'configuracion' => 'configuracion',
        'inventario' => 'inventario',
        'alquileres' => 'alquileres',
        'alquileres imágenes' => 'alquileres_imagenes',
        'alquileres imagenes' => 'alquileres_imagenes',
        'alquileres gastos' => 'alquileres_gastos',
        'empresas' => 'empresas',
        'sistemax assist' => 'sistemax_assist',
        'centro notificaciones' => 'centro_notificaciones',
        'centro de bug' => 'centro_bug',
        'google drive' => 'google_drive',
        'vcorta' => 'vcorta',
        'salir' => 'logout',
    ];

    if (isset($mapByLabel[$normalized])) {
        return $mapByLabel[$normalized];
    }

    $route = strtolower(trim($appRoute));
    $routeMap = [
        'public/ventas/index.php' => 'ventas',
        'public/compras/index.php' => 'compras',
        'public/productos/index.php' => 'productos',
        'public/contactos/index.php' => 'contactos',
        'public/taller/index.php' => 'taller',
        'public/suscripciones.php' => 'suscripciones',
        'public/empresa/index.php' => 'configuracion',
        'public/inventario/index.php' => 'inventario_mobile',
        'public/alquileres/index.php' => 'alquileres',
        'public/alquileres/imagenes.php' => 'alquileres_imagenes',
        'public/alquileres/gastos.php' => 'alquileres_gastos',
        'public/menu/centro_notificaciones.php' => 'centro_notificaciones',
        'public/db_migrador/index.php' => 'db_migrador',
        'public/db_manager/index.php' => 'db_manager',
        'public/helpwire/index.php' => 'helpwire',
        'public/i18n_admin/index.php' => 'i18n_admin',
        'public/setup/google_drive_setup.php' => 'google_drive',
        'public/pos/caja.php' => 'mi_caja',
        'public/balanzas/index.php' => 'balanzas',
        'public/picos/index.php' => 'picos',
        'public/estacion-picos/index.php' => 'picos',
        'public/cierres/index.php' => 'cierres',
        'public/cierre-playa/index.php' => 'cierres',
    ];

    foreach ($routeMap as $needle => $resolvedCode) {
        if ($route === $needle || str_contains($route, trim($needle, '/'))) {
            return $resolvedCode;
        }
    }

    $slug = strtolower((string) preg_replace('/[^a-z0-9]+/', '_', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized)));
    return trim($slug, '_') ?: 'app';
}

function menuIconPriority(array $item): int
{
    $icon = strtolower(trim((string)($item['icono'] ?? '')));
    $genericIcons = [
        '',
        'app-window',
        'squares-2x2',
        'cube',
        'question-mark-circle',
    ];

    $score = in_array($icon, $genericIcons, true) ? 0 : 10;
    if (trim((string)($item['icono_svg'] ?? '')) !== '') {
        $score += 5;
    }

    $order = (int)($item['orden'] ?? 9999);
    return $score * 10000 - $order;
}

function translateMenuItemLabel(string $itemCode, string $fallbackLabel): string
{
    return t_app($itemCode, $fallbackLabel);
}

$esEmpresaDesarrollo = isEmpresaModoDesarrollo((int)$id_empresa, (string)$empresa, (string)$dbu);

// Cargar permisos del usuario
$arr_perm = [];
if ($id_empresa > 0 && $id_grupo > 0) {
    try {
        $db = Database::getMasterConnection();
        $stmt = $db->prepare('SELECT app_name, priv_access FROM sec_groups_apps WHERE id_grupo = ? AND group_id = ?');
        $stmt->execute([$id_empresa, $id_grupo]);
        while ($row = $stmt->fetch()) {
            $arr_perm[$row['app_name']] = [
                'access' => ($row['priv_access'] === 'Y') ? 'on' : 'off',
            ];
        }
    } catch (Exception $e) {
        error_log("Error cargando permisos: " . $e->getMessage());
    }
}

// Función para verificar permisos
// Si existe configuración en sec_groups_apps, respetar el switch (incluso para admin)
// Si no existe configuración, permitir por defecto
function tienePermiso(string $appName, bool $esAdmin, array $arrPerm): bool
{
    if ($esAdmin) {
        return true;
    }
    if (!isset($arrPerm[$appName])) {
        // No hay configuración para esta app → permitir por defecto (especialmente admin)
        return true;
    }
    return $arrPerm[$appName]['access'] === 'on';
}

function ensureUserMenuPrefsTable(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db->exec("
        CREATE TABLE IF NOT EXISTS smx_user_menu_prefs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            id_empresa INT NOT NULL,
            id_login INT NOT NULL,
            menu_key VARCHAR(100) NOT NULL,
            order_json LONGTEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_menu_pref (id_empresa, id_login, menu_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function loadUserMenuPreference(PDO $db, int $idEmpresa, int $idLogin, string $menuKey): array
{
    ensureUserMenuPrefsTable($db);
    $stmt = $db->prepare("
        SELECT order_json
        FROM smx_user_menu_prefs
        WHERE id_empresa = ?
          AND id_login = ?
          AND menu_key = ?
        LIMIT 1
    ");
    $stmt->execute([$idEmpresa, $idLogin, $menuKey]);
    $json = (string)($stmt->fetchColumn() ?: '');
    if ($json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded), static fn($v) => $v !== '')) : [];
}

function saveUserMenuPreference(PDO $db, int $idEmpresa, int $idLogin, string $menuKey, array $order): void
{
    ensureUserMenuPrefsTable($db);
    $clean = array_values(array_filter(array_map('strval', $order), static fn($v) => trim($v) !== ''));
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare("
        INSERT INTO smx_user_menu_prefs (id_empresa, id_login, menu_key, order_json)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE order_json = VALUES(order_json), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$idEmpresa, $idLogin, $menuKey, $json]);
}

function smxMenuLogoAssetExists(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    if (str_starts_with($url, 'https://')) {
        return true;
    }
    if (str_starts_with($url, 'http://')) {
        return false;
    }
    $projectRoot = dirname(__DIR__, 2);
    if (str_starts_with($url, '/public/')) {
        return is_file($projectRoot . $url);
    }
    if (str_starts_with($url, '/_lib/')) {
        return is_file($projectRoot . '/public' . $url);
    }
    return false;
}

function resolveMenuCatalogIconSvg(string $iconoSvg): string
{
    $icon = trim($iconoSvg);
    if ($icon === '') {
        return '';
    }

    if (isMenuInlineSvg($icon)) {
        return sanitizeMenuInlineSvg($icon);
    }

    if (preg_match('~^https?://~i', $icon)) {
        return $icon;
    }

    $path = (string)(parse_url($icon, PHP_URL_PATH) ?? '');
    if ($path === '') {
        $path = $icon;
    }
    if (strpos($path, '..') !== false) {
        return '';
    }

    $projectRoot = dirname(__DIR__, 2);

    if (str_starts_with($path, '/public/')) {
        $disk = $projectRoot . $path;
        return is_file($disk) ? $path : '';
    }

    if (str_starts_with($path, '/')) {
        $disk = $projectRoot . $path;
        if (is_file($disk)) {
            return $path;
        }
        $diskPublic = $projectRoot . '/public' . $path;
        if (is_file($diskPublic)) {
            return '/public' . $path;
        }
        return '';
    }

    $rel = ltrim($path, '/');
    $disk = $projectRoot . '/public/' . $rel;
    if (is_file($disk)) {
        return '/public/' . $rel;
    }

    return '';
}

function isMenuInlineSvg(string $value): bool
{
    return preg_match('~^\s*<svg\b~i', $value) === 1;
}

function sanitizeMenuInlineSvg(string $svg): string
{
    $svg = trim($svg);
    if ($svg === '' || !isMenuInlineSvg($svg) || stripos($svg, '</svg>') === false) {
        return '';
    }

    $svg = preg_replace('~<script\b[^>]*>.*?</script>~is', '', $svg) ?? '';
    $svg = preg_replace('~\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $svg) ?? '';
    $svg = preg_replace('~javascript\s*:~i', '', $svg) ?? '';

    return $svg;
}

// Obtener logo de empresa
$logoEmpresa = '';
if ($id_empresa > 0) {
    try {
        $db = Database::getMasterConnection();
        $stmt = $db->prepare('SELECT logos FROM empresa WHERE id_empresa = ?');
        $stmt->execute([$id_empresa]);
        $row = $stmt->fetch();
        $logoEmpresa = $row['logos'] ?? '';
    } catch (Exception $e) {
    }
}

$rutaImagenEmpresas = '/public/_lib/file/img/empresa/';
if (!empty($logoEmpresa) && (str_starts_with($logoEmpresa, 'http') || str_starts_with($logoEmpresa, '/'))) {
    $logoEmpresaUrl = $logoEmpresa;
} elseif (!empty($logoEmpresa)) {
    $logoEmpresaUrl = $rutaImagenEmpresas . ltrim($logoEmpresa, '/');
} else {
    $logoEmpresaUrl = 'https://sistemax.pro/public/assets/images/logo-sistemax-v1.png';
}
if (!smxMenuLogoAssetExists($logoEmpresaUrl)) {
    $logoEmpresaUrl = 'https://sistemax.pro/public/assets/images/logo-sistemax-v1.png';
}

// Cargar sucursales de la empresa logueada
$sucursalesList = [];
$sucursalActiva = (int)($_SESSION['id_sucursal'] ?? 0);
$sucursalActivaNombre = $_SESSION['sucursal'] ?? '';
if ($id_empresa > 0) {
    try {
        $empData = Database::getEmpresaInfo((int)$id_empresa);
        if ($empData) {
            $dbEmp = $empData['dbase'];
            $pdoEmp = Database::getEmpresaConnection((int)$id_empresa);
            try {
                $stmtSuc = $pdoEmp->query("SELECT id_sucursal AS SUC, COALESCE(nombre, sucursal, NOMBRE) AS NOMBRE FROM {$dbEmp}.sucursales ORDER BY id_sucursal");
                $sucursalesList = $stmtSuc->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $stmtSuc = $pdoEmp->query("SELECT SUC, COALESCE(NOMBRE, sucursal) AS NOMBRE FROM {$dbEmp}.sucursal ORDER BY SUC");
                $sucursalesList = $stmtSuc->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            // Auto-seleccionar primera sucursal si no hay ninguna activa
            if ($sucursalActiva <= 0 && count($sucursalesList) > 0) {
                $sucursalActiva = (int)$sucursalesList[0]['SUC'];
                $sucursalActivaNombre = $sucursalesList[0]['NOMBRE'];
                Session::set('id_sucursal', $sucursalActiva);
                Session::set('sucursal', $sucursalActivaNombre);
            }
        }
    } catch (Exception $e) {
        error_log("Error cargando sucursales: " . $e->getMessage());
    }
}

// Detectar dispositivo
$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
$dispositivo = 'desktop';
if (strpos($ua, 'ipad') !== false || strpos($ua, 'tablet') !== false) {
    $dispositivo = 'tablet';
} elseif (preg_match('/mobile|android|iphone|ipod/', $ua)) {
    $dispositivo = 'mobile';
}
$isMobileDevice = ($dispositivo === 'mobile' || $dispositivo === 'tablet');

$isMobilePos = ($dispositivo === 'mobile' || $dispositivo === 'tablet');
$app_pos = $isMobilePos ? 'venta_pos' : 'venta_pc';
$app_pos_v1 = $isMobilePos ? 'public/pos/mobile.php' : 'public/pos/index_desktop_dropdown.php?desktop=1';
$currentLocale = SmxI18n::getLocale();
$localeOptions = SmxI18n::getLocaleOptions();
$menuI18n = [
    'home' => t('common.home'),
    'previous' => t('common.previous'),
    'next' => t('common.next'),
    'branches' => t('common.branches'),
    'select' => t('common.select'),
    'appsPagination' => t('common.apps_pagination'),
    'closeSessionTitle' => t('common.close_session_title'),
    'closeSessionBody' => t('common.close_session_body'),
];

// Heroicons SVG (outline style) - https://heroicons.com
$heroicons = [
    'shopping-cart' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" /></svg>',
    'fire' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.362 5.214A8.252 8.252 0 0 1 12 21 8.25 8.25 0 0 1 6.038 7.047 8.287 8.287 0 0 0 9 9.601a8.983 8.983 0 0 1 3.361-6.867 8.21 8.21 0 0 0 3 2.48Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M12 18a3.75 3.75 0 0 0 .495-7.468 5.99 5.99 0 0 0-1.925 3.547 5.975 5.975 0 0 1-2.133-1.001A3.75 3.75 0 0 0 12 18Z" /></svg>',
    'chart-bar' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>',
    'truck' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12" /></svg>',
    'cube' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" /></svg>',
    'banknotes' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>',
    'beaker' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 1-6.23.693L5 15.3m14.8 0 .21 1.847a2.252 2.252 0 0 1-1.809 2.498l-7.076 1.18a2.25 2.25 0 0 1-2.25-1.312L5 15.3m0 0-.21.849A2.25 2.25 0 0 0 6.83 18.96l.69.115" /></svg>',
    'folder-open' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776" /></svg>',
    'users' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>',
    'user-group' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" /></svg>',
    'clipboard-document-list' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" /></svg>',
    'squares-2x2' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" /></svg>',
    'shield-check' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>',
    'cog-6-tooth' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>',
    'building-office' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>',
    'arrow-right-on-rectangle' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" /></svg>',
    'credit-card' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" /></svg>',
    // Dock icons
    'home' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>',
    'chevron-left' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>',
];

// Función para obtener icono Heroicon
function getHeroicon($name, $heroicons) {
    static $dynamicCache = [];
    $name = trim((string)$name);
    if ($name === '') {
        $name = 'cube';
    }

    if (str_contains($name, 'fa-')) {
        $parts = preg_split('/\s+/', $name) ?: [];
        $faName = '';
        foreach ($parts as $part) {
            if (str_starts_with($part, 'fa-')) {
                $faName = $part;
                break;
            }
        }
        $fontAwesomeAliases = [
            'fa-file-signature' => 'clipboard-document-list',
            'fa-dolly' => 'truck',
            'fa-truck' => 'truck',
            'fa-shopping-cart' => 'shopping-cart',
            'fa-store' => 'building-storefront',
            'fa-cash-register' => 'building-storefront',
            'fa-boxes' => 'cube',
            'fa-box' => 'cube',
            'fa-cube' => 'cube',
            'fa-user-tie' => 'users',
            'fa-users' => 'users',
            'fa-folder' => 'folder-open',
            'fa-folder-open' => 'folder-open',
            'fa-chart-bar' => 'chart-bar',
            'fa-chart-line' => 'chart-bar',
            'fa-gas-pump' => 'fire',
            'fa-cogs' => 'cog-6-tooth',
            'fa-cog' => 'cog-6-tooth',
            'fa-sign-out-alt' => 'arrow-right-on-rectangle',
            'fa-sign-out' => 'arrow-right-on-rectangle',
            'fa-credit-card' => 'credit-card',
            'fa-wrench' => 'wrench-screwdriver',
        ];
        if ($faName !== '' && isset($fontAwesomeAliases[$faName])) {
            $name = $fontAwesomeAliases[$faName];
        }
    }

    $aliases = [
        'app-window' => 'squares-2x2',
        'apps-grid' => 'squares-2x2',
        'boxes-stacked' => 'cube',
        'building' => 'building-office',
        'building-community' => 'building-office',
        'warehouse' => 'building-office',
        'file-signature' => 'clipboard-document-list',
        'file-invoice-dollar' => 'credit-card',
        'receipt' => 'credit-card',
        'printer' => 'document-text',
        'android' => 'device-phone-mobile',
        'bluetooth' => 'device-phone-mobile',
        'bug' => 'shield-exclamation',
        'map-marker-check' => 'map-pin',
        'map-marker-multiple' => 'map-pin',
        'map' => 'map-pin',
        'images' => 'photo',
        'language' => 'globe-alt',
        'document-text' => 'clipboard-document-list',
        'document-duplicate' => 'clipboard-document-list',
        'clipboard-document-check' => 'clipboard-document-list',
        'calendar-days' => 'clipboard-document-list',
        'clock' => 'chart-bar',
        'bell' => 'shield-check',
        'magnifying-glass' => 'chart-bar',
        'funnel' => 'chart-bar',
        'tag' => 'credit-card',
        'qr-code' => 'squares-2x2',
        'globe-alt' => 'building-office',
        'map-pin' => 'building-office',
        'phone' => 'user-group',
        'envelope' => 'clipboard-document-list',
        'camera' => 'squares-2x2',
        'photo' => 'squares-2x2',
        'ticket' => 'credit-card',
        'receipt-percent' => 'credit-card',
        'building-storefront' => 'building-office',
        'chart-pie' => 'chart-bar',
        'presentation-chart-line' => 'chart-bar',
        'user' => 'users',
        'user-circle' => 'users',
        'archive-box' => 'cube',
        'cube-transparent' => 'cube',
        'wrench-screwdriver' => 'cog-6-tooth',
        'shield-exclamation' => 'shield-check',
        'lock-closed' => 'shield-check',
        'key' => 'shield-check',
        'cog-8-tooth' => 'cog-6-tooth',
        'server-stack' => 'building-office',
        'cpu-chip' => 'cog-6-tooth',
        'cloud-arrow-up' => 'arrow-right-on-rectangle',
        'cloud-arrow-down' => 'arrow-right-on-rectangle',
        'arrow-path' => 'cog-6-tooth',
        'arrows-right-left' => 'arrow-right-on-rectangle',
        'play' => 'arrow-right-on-rectangle',
        'pause' => 'squares-2x2',
        'sparkles' => 'fire',
        'bolt' => 'fire',
        'arrow-left-start-on-rectangle' => 'arrow-right-on-rectangle',
    ];
    if (isset($heroicons[$name])) {
        return $heroicons[$name];
    }
    if (isset($dynamicCache[$name])) {
        return $dynamicCache[$name];
    }
    $svgPath = __DIR__ . '/../../node_modules/heroicons/24/outline/' . $name . '.svg';
    if (is_file($svgPath)) {
        $svg = trim((string) file_get_contents($svgPath));
        if ($svg !== '') {
            $dynamicCache[$name] = $svg;
            return $svg;
        }
    }
    if (isset($aliases[$name])) {
        $alias = $aliases[$name];
        if (isset($heroicons[$alias])) {
            return $heroicons[$alias];
        }
        if (isset($dynamicCache[$alias])) {
            return $dynamicCache[$alias];
        }
        $aliasPath = __DIR__ . '/../../node_modules/heroicons/24/outline/' . $alias . '.svg';
        if (is_file($aliasPath)) {
            $svg = trim((string) file_get_contents($aliasPath));
            if ($svg !== '') {
                $dynamicCache[$alias] = $svg;
                return $svg;
            }
        }
    }
    return $heroicons['cube'];
}

function resolveMenuIconSvg(array $item): string
{
    global $heroicons;

    $iconoSvg = trim((string)($item['icono_svg'] ?? ''));
    $icono    = trim((string)($item['icono'] ?? ''));
    $source   = strtolower(trim((string)($item['icono_source'] ?? '')));

    // 1. Ruta a archivo SVG custom — render como <img>
    if ($iconoSvg !== '') {
        if (isMenuInlineSvg($iconoSvg)) {
            $inlineSvg = sanitizeMenuInlineSvg($iconoSvg);
            if ($inlineSvg !== '') {
                return $inlineSvg;
            }
        }

        $safe = htmlspecialchars($iconoSvg, ENT_QUOTES, 'UTF-8');
        $alt  = htmlspecialchars(basename($iconoSvg, '.svg'), ENT_QUOTES, 'UTF-8');
        return '<img src="' . $safe . '" alt="' . $alt . '">';
    }

    if ($icono === '') {
        return !empty($heroicons) ? $heroicons['cube'] : '<img src="/public/assets/images/icons_v2/sin-icono.svg" alt="sin-icono">';
    }

    // Limpiar FA class si viene con formato antiguo
    $iconoName = $icono;
    if (preg_match('/fa-([a-z0-9-]+)/', $icono, $m)) {
        $iconoName = $m[1];
    }

    // 2. Archivo local en icons_v2 — render como <img>
    if (is_file(__DIR__ . '/../assets/images/icons_v2/' . $iconoName . '.svg')) {
        $safe = htmlspecialchars('/public/assets/images/icons_v2/' . $iconoName . '.svg', ENT_QUOTES, 'UTF-8');
        return '<img src="' . $safe . '" alt="' . htmlspecialchars($iconoName, ENT_QUOTES, 'UTF-8') . '">';
    }

    // 3. Tabler icon — cargar inline desde node_modules
    if ($source === 'tabler') {
        $tablerPath = __DIR__ . '/../../node_modules/tabler-icons/icons/' . $iconoName . '.svg';
        if (is_file($tablerPath)) {
            $svg = trim((string)file_get_contents($tablerPath));
            if ($svg !== '') return $svg;
        }
    }

    // 4. Heroicon inline — getHeroicon ya tiene fallback a cube
    if (!empty($heroicons)) {
        return getHeroicon($iconoName, $heroicons);
    }

    return '<img src="/public/assets/images/icons_v2/sin-icono.svg" alt="sin-icono">';
}

// =========================================================================
// SISTEMA DE SUSCRIPCIONES SAAS - Carga dinámica del menú
// =========================================================================
require_once __DIR__ . '/../../src/Modules/Empresas/SuscripcionController.php';

// Verificar acceso de la empresa
$estadoSuscripcion = SuscripcionController::verificarAcceso($id_empresa);
$estadoAcceso = $estadoSuscripcion['estado_acceso'] ?? 'ok';
$mostrarBannerSuscripcion = $estadoSuscripcion['mostrar_banner'] ?? false;
$diasRestantesSuscripcion = $estadoSuscripcion['dias_restantes'] ?? 0;
$mensajeSuscripcion = $estadoSuscripcion['mensaje'] ?? '';
$suscripcionActual = $estadoSuscripcion['suscripcion'] ?? null;
$esCuentaSponsor = (int)($suscripcionActual['es_sponsor'] ?? 0) === 1;
if ($esCuentaSponsor) {
    $mostrarBannerSuscripcion = false;
}

if ($esEmpresaDesarrollo) {
    $estadoAcceso = 'ok';
    $mostrarBannerSuscripcion = false;
    $mensajeSuscripcion = '';
}

// Si está bloqueado, redirigir a página de pago
if (!$esEmpresaDesarrollo && $estadoAcceso === 'bloqueado' && $suscripcionActual) {
    header('Location: /public/pagar_suscripcion.php?id=' . $suscripcionActual['id_suscripcion']);
    exit;
}

// =========================================================================
// CARGA EXCLUSIVA DESDE SUSCRIPCIÓN + APPS OBLIGATORIAS
// Si la empresa no tiene suscripción, solo se muestran las apps obligatorias
// (Mi Suscripción, Suscripciones, Salir) definidas en saas_apps_catalogo
// =========================================================================
if (!function_exists('ensurePrintAgentCatalogApp')) {
function ensurePrintAgentCatalogApp(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = Database::getMasterConnection();
        $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ? LIMIT 1");
        $stmt->execute(['agente_impresion_directa']);
        $idApp = (int)($stmt->fetchColumn() ?: 0);
        if ($idApp > 0) {
            $upd = $db->prepare("UPDATE saas_apps_catalogo
                SET nombre = ?, descripcion = ?, ruta_app = ?, icono = ?, icono_source = 'custom', color = ?, precio_mensual = 0, obligatoria = 0, activo = 1, en_desarrollo = 0, modulo = ?, negocio = ?, permiso_base = ?, orden = ?
                WHERE id_app = ? AND COALESCE(icono_source, '') != 'custom'");
            $upd->execute([
                'Agente de Impresion',
                'Instalacion, prueba y configuracion del agente nativo para impresion directa con impresoras locales',
                'public/apps-moviles.php?external=1',
                'printer',
                'emerald',
                'POS',
                'General',
                'app_grid_agente_impresion',
                17,
                $idApp,
            ]);
            return;
        }
        $ins = $db->prepare("INSERT INTO saas_apps_catalogo
            (codigo, nombre, descripcion, ruta_app, icono, icono_source, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
            VALUES (?, ?, ?, ?, ?, 'custom', ?, 0, 0, NULL, ?, ?, 1, 0, ?, ?)");
        $ins->execute([
            'agente_impresion_directa',
            'Agente de Impresion',
            'Instalacion, prueba y configuracion del agente nativo para impresion directa con impresoras locales',
            'public/apps-moviles.php?external=1',
            'printer',
            'emerald',
            'app_grid_agente_impresion',
            17,
            'POS',
            'General',
        ]);
    } catch (Throwable $e) {
        error_log('[menu/menu] ensurePrintAgentCatalogApp: ' . $e->getMessage());
    }
}
}
ensurePrintAgentCatalogApp();

if (!function_exists('ensureAndroidInstallerCatalogApp')) {
function ensureAndroidInstallerCatalogApp(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = Database::getMasterConnection();
        $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ? LIMIT 1");
        $stmt->execute(['instalar_android']);
        $idApp = (int)($stmt->fetchColumn() ?: 0);
        if ($idApp > 0) {
            $upd = $db->prepare("UPDATE saas_apps_catalogo
                SET nombre = ?, descripcion = ?, ruta_app = ?, icono = ?, icono_source = 'custom', color = ?, precio_mensual = 0, obligatoria = 0, activo = 1, en_desarrollo = 0, modulo = ?, negocio = ?, permiso_base = ?, orden = ?
                WHERE id_app = ? AND COALESCE(icono_source, '') != 'custom'");
            $upd->execute([
                'Instalar Geolocalizador',
                'Instalacion guiada del agente geolocalizador para telefono o tablet Android',
                'public/apps-moviles.php?external=android',
                'mobile-phone',
                'emerald',
                'POS',
                'General',
                'app_grid_instalar_android',
                16,
                $idApp,
            ]);
            return;
        }
        $ins = $db->prepare("INSERT INTO saas_apps_catalogo
            (codigo, nombre, descripcion, ruta_app, icono, icono_source, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
            VALUES (?, ?, ?, ?, ?, 'custom', ?, 0, 0, NULL, ?, ?, 1, 0, ?, ?)");
        $ins->execute([
            'instalar_android',
            'Instalar Geolocalizador',
            'Instalacion guiada del agente geolocalizador para telefono o tablet Android',
            'public/apps-moviles.php?external=android',
            'mobile-phone',
            'emerald',
            'app_grid_instalar_android',
            16,
            'POS',
            'General',
        ]);
    } catch (Throwable $e) {
        error_log('[menu/menu] ensureAndroidInstallerCatalogApp: ' . $e->getMessage());
    }
}
}
ensureAndroidInstallerCatalogApp();

if (!function_exists('ensureAndroidBluetoothPrinterCatalogApp')) {
function ensureAndroidBluetoothPrinterCatalogApp(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = Database::getMasterConnection();
        $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ? LIMIT 1");
        $stmt->execute(['configurar_impresora_android']);
        $idApp = (int)($stmt->fetchColumn() ?: 0);
        if ($idApp > 0) {
            $upd = $db->prepare("UPDATE saas_apps_catalogo
                SET nombre = ?, descripcion = ?, ruta_app = ?, icono = ?, icono_source = 'custom', color = ?, precio_mensual = 0, obligatoria = 0, activo = 1, en_desarrollo = 0, modulo = ?, negocio = ?, permiso_base = ?, orden = ?
                WHERE id_app = ? AND COALESCE(icono_source, '') != 'custom'");
            $upd->execute([
                'Configurar Impresora BT',
                'Abrir la configuración Android para detectar y guardar impresoras Bluetooth emparejadas en SistemaX Pro',
                'public/apps-moviles.php?external=android&focus=printer',
                'printer',
                'emerald',
                'POS',
                'General',
                'app_grid_configurar_impresora_android',
                15,
                $idApp,
            ]);
            return;
        }
        $ins = $db->prepare("INSERT INTO saas_apps_catalogo
            (codigo, nombre, descripcion, ruta_app, icono, icono_source, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
            VALUES (?, ?, ?, ?, ?, 'custom', ?, 0, 0, NULL, ?, ?, 1, 0, ?, ?)");
        $ins->execute([
            'configurar_impresora_android',
            'Configurar Impresora BT',
            'Abrir la configuración Android para detectar y guardar impresoras Bluetooth emparejadas en SistemaX Pro',
            'public/apps-moviles.php?external=android&focus=printer',
            'printer',
            'emerald',
            'app_grid_configurar_impresora_android',
            15,
            'POS',
            'General',
        ]);
    } catch (Throwable $e) {
        error_log('[menu/menu] ensureAndroidBluetoothPrinterCatalogApp: ' . $e->getMessage());
    }
}
}
ensureAndroidBluetoothPrinterCatalogApp();

if (!function_exists('ensureTrackingMobileCatalogApp')) {
function ensureTrackingMobileCatalogApp(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = Database::getMasterConnection();
        $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ? LIMIT 1");
        $stmt->execute(['tracking_movil']);
        $idApp = (int)($stmt->fetchColumn() ?: 0);
        if ($idApp > 0) {
            $upd = $db->prepare("UPDATE saas_apps_catalogo
                SET nombre = ?, descripcion = ?, ruta_app = ?, icono = ?, icono_source = 'custom', color = ?, precio_mensual = 0, obligatoria = 0, activo = 1, en_desarrollo = 0, modulo = ?, negocio = ?, permiso_base = ?, orden = ?
                WHERE id_app = ? AND COALESCE(icono_source, '') != 'custom'");
            $upd->execute([
                'Tracking Movil',
                'Mapa en vivo con ubicacion, bateria y estado de dispositivos Android con SistemaX Assist',
                'public/soporte/mobile_tracking.php',
                'tracking-movil',
                'emerald',
                'General',
                'General',
                'app_grid_tracking_movil',
                20,
                $idApp,
            ]);
            return;
        }
        $ins = $db->prepare("INSERT INTO saas_apps_catalogo
            (codigo, nombre, descripcion, ruta_app, icono, icono_source, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
            VALUES (?, ?, ?, ?, ?, 'custom', ?, 0, 0, NULL, ?, ?, 1, 0, ?, ?)");
        $ins->execute([
            'tracking_movil',
            'Tracking Movil',
            'Mapa en vivo con ubicacion, bateria y estado de dispositivos Android con SistemaX Assist',
            'public/soporte/mobile_tracking.php',
            'tracking-movil',
            'emerald',
            'app_grid_tracking_movil',
            20,
            'General',
            'General',
        ]);
    } catch (Throwable $e) {
        error_log('[menu/menu] ensureTrackingMobileCatalogApp: ' . $e->getMessage());
    }
}
}
ensureTrackingMobileCatalogApp();

if (!function_exists('ensurePushDiagnosticCatalogApp')) {
function ensurePushDiagnosticCatalogApp(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = Database::getMasterConnection();
        $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ? LIMIT 1");
        $stmt->execute(['push_diagnostico']);
        $idApp = (int)($stmt->fetchColumn() ?: 0);
        if ($idApp > 0) {
            $upd = $db->prepare("UPDATE saas_apps_catalogo
                SET nombre = ?, descripcion = ?, ruta_app = ?, icono = ?, icono_source = 'custom', color = ?, precio_mensual = 0, obligatoria = 0, activo = 1, en_desarrollo = 0, modulo = ?, negocio = ?, permiso_base = ?, orden = ?
                WHERE id_app = ? AND COALESCE(icono_source, '') != 'custom'");
            $upd->execute([
                'Diagnóstico Push',
                'Verifica notificaciones push, suscripción del navegador y última entrega al dispositivo móvil',
                'public/menu/push_diagnostico.php',
                'signal',
                'cyan',
                'General',
                'General',
                'app_grid_push_diagnostico',
                21,
                $idApp,
            ]);
            return;
        }
        $ins = $db->prepare("INSERT INTO saas_apps_catalogo
            (codigo, nombre, descripcion, ruta_app, icono, icono_source, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
            VALUES (?, ?, ?, ?, ?, 'custom', ?, 0, 0, NULL, ?, ?, 1, 0, ?, ?)");
        $ins->execute([
            'push_diagnostico',
            'Diagnóstico Push',
            'Verifica notificaciones push, suscripción del navegador y última entrega al dispositivo móvil',
            'public/menu/push_diagnostico.php',
            'signal',
            'cyan',
            'app_grid_push_diagnostico',
            21,
            'General',
            'General',
        ]);
    } catch (Throwable $e) {
        error_log('[menu/menu] ensurePushDiagnosticCatalogApp: ' . $e->getMessage());
    }
}
}
ensurePushDiagnosticCatalogApp();

if (!function_exists('ensureDbManagerCatalogApp')) {
function ensureDbManagerCatalogApp(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = Database::getMasterConnection();
        $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ? LIMIT 1");
        $stmt->execute(['db_manager']);
        $idApp = (int)($stmt->fetchColumn() ?: 0);
        if ($idApp > 0) {
            $upd = $db->prepare("UPDATE saas_apps_catalogo
                SET nombre = ?, descripcion = ?, ruta_app = ?, icono = ?, icono_source = 'custom', color = ?, precio_mensual = 0, obligatoria = 0, activo = 1, en_desarrollo = 0, modulo = ?, negocio = ?, permiso_base = ?, orden = ?
                WHERE id_app = ?");
            $upd->execute([
                'DB Manager',
                'Administrador visual de bases MySQL/MariaDB con explorador, estructura, datos y editor SQL',
                'public/db_manager/index.php',
                'database',
                'slate',
                'General',
                'General',
                'app_grid_db_manager',
                22,
                $idApp,
            ]);
            return;
        }
        $ins = $db->prepare("INSERT INTO saas_apps_catalogo
            (codigo, nombre, descripcion, ruta_app, icono, icono_source, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
            VALUES (?, ?, ?, ?, ?, 'custom', ?, 0, 0, NULL, ?, ?, 1, 0, ?, ?)");
        $ins->execute([
            'db_manager',
            'DB Manager',
            'Administrador visual de bases MySQL/MariaDB con explorador, estructura, datos y editor SQL',
            'public/db_manager/index.php',
            'database',
            'slate',
            'app_grid_db_manager',
            22,
            'General',
            'General',
        ]);
    } catch (Throwable $e) {
        error_log('[menu/menu] ensureDbManagerCatalogApp: ' . $e->getMessage());
    }
}
}
ensureDbManagerCatalogApp();

$itemsMenu = [];
$appsMenuData = [];
$appsMenuCodigosCargados = [];
$appsMenuRutasCargadas = [];

if ($id_empresa > 0) {
    $menuAppsCacheKey = 'menu_apps_cache_' . (int)$id_empresa . '_' . (int)$modulo;
    $menuAppsTsKey = $menuAppsCacheKey . '_ts';

    $resultadoApps = SuscripcionController::getAppsEmpresa($id_empresa, $modulo);
    if (($resultadoApps['success'] ?? false) && !empty($resultadoApps['data']) && is_array($resultadoApps['data'])) {
        $appsMenuData = $resultadoApps['data'];
        // Limpiar cache vieja si no trae icono_svg (datos anteriores a la columna)
        $primerApp = $appsMenuData[0] ?? [];
        if (!array_key_exists('icono_svg', $primerApp)) {
            error_log('[menu] WARN: getAppsEmpresa no retorna icono_svg — query desactualizada o columna faltante');
        }
        $_SESSION[$menuAppsCacheKey] = $appsMenuData;
        $_SESSION[$menuAppsTsKey] = time();
        error_log('[menu] getAppsEmpresa OK id_empresa=' . (int)$id_empresa . ' apps=' . count($appsMenuData) . ' icono_svg[0]=' . ($primerApp['icono_svg'] ?? '(vacío)'));
    } elseif (!empty($_SESSION[$menuAppsCacheKey]) && is_array($_SESSION[$menuAppsCacheKey])) {
        $appsMenuData = $_SESSION[$menuAppsCacheKey];
        $cachedPrimero = $appsMenuData[0] ?? [];
        // Si el cache no tiene icono_svg, forzar limpieza para que el próximo request lo recargue limpio
        if (!array_key_exists('icono_svg', $cachedPrimero)) {
            unset($_SESSION[$menuAppsCacheKey], $_SESSION[$menuAppsTsKey]);
            $appsMenuData = [];
            error_log('[menu] cache de sesión sin icono_svg — limpiado para id_empresa=' . (int)$id_empresa);
        } else {
            error_log('[menu] getAppsEmpresa fallback a cache de sesión para id_empresa=' . (int)$id_empresa . ' icono_svg[0]=' . ($cachedPrimero['icono_svg'] ?? '(vacío)'));
        }
    }

    $appsMenuCodigosCargados = array_values(array_unique(array_filter(array_map(static function (array $app): string {
        return strtolower(trim((string)($app['codigo'] ?? '')));
    }, $appsMenuData))));
    $appsMenuRutasCargadas = array_values(array_unique(array_filter(array_map(static function (array $app): string {
        return trim((string)($app['app'] ?? $app['ruta_app'] ?? ''));
    }, $appsMenuData))));
    $catalogIconSvgByRoute = getCatalogIconSvgByRouteMap($db);

    if (!empty($appsMenuData)) {
        foreach ($appsMenuData as $app) {
            $codigoApp = strtolower((string)($app['codigo'] ?? ''));
            $rutaApp = (string)($app['app'] ?? $app['ruta_app'] ?? '');
            $labelOriginal = (string)($app['label'] ?? $app['nombre'] ?? '');
            $itemCode = resolveMenuItemCode($codigoApp, $labelOriginal, $rutaApp);
            $iconoApp    = (string)($app['icono'] ?? 'squares-2x2');
            $iconoSource = (string)($app['icono_source'] ?? '');
            $iconoSvgApp = (string)($catalogIconSvgByRoute[normalizeMenuRouteKey($rutaApp)] ?? ($app['icono_svg'] ?? ''));
            // Construir path para iconos custom sin icono_svg
            if ($iconoSvgApp === '' && $iconoSource === 'custom' && $iconoApp !== '') {
                $iconoSvgApp = '/public/assets/images/icons_v2/' . $iconoApp . '.svg';
            }
            $colorApp    = (string)($app['color3d'] ?? $app['color'] ?? 'blue');
            $permisoBase = (string)($app['permiso_base'] ?? '');

            // Excluir POS Clásico del menú
            if ($codigoApp === 'venta_pos_clasico') {
                continue;
            }
            
            // Determinar visibilidad basada en permisos
            $visible = true;
            if (!empty($permisoBase)) {
                $visible = tienePermiso($permisoBase, $usr_priv_admin, $arr_perm);
            }
            
            // Condiciones especiales de visibilidad
            if (!$esEmpresaDesarrollo) {
                if (!$usr_priv_admin && $codigoApp === 'habilitacion_sifen') {
                    $visible = ($usr_ti && $visible);
                }
            }

            // En modo desarrollo (sistemaxpro), siempre habilitar Migrador DB.
            if (
                $esEmpresaDesarrollo &&
                (
                    $codigoApp === 'db_migrador' ||
                    $codigoApp === 'db_manager' ||
                    strpos($codigoApp, 'migrador') !== false ||
                    strpos((string)$rutaApp, 'db_migrador') !== false ||
                    strpos((string)$rutaApp, 'db_manager') !== false
                )
            ) {
                $visible = true;
                if ($rutaApp === '' && $codigoApp === 'db_migrador') {
                    $rutaApp = 'public/db_migrador/index.php';
                } elseif ($rutaApp === '' && $codigoApp === 'db_manager') {
                    $rutaApp = 'public/db_manager/index.php';
                }
            }
            
            // Apps especiales que mantienen lógica propia
            $rutaAppNormalizada = ltrim(strtolower(trim((string)$rutaApp)), '/');
            if (
                $codigoApp === 'venta_pos' ||
                $codigoApp === 'venta_pc' ||
                in_array($rutaAppNormalizada, ['venta_pc', 'venta_pos', 'pos/index.php', 'public/pos/index.php'], true)
            ) {
                $rutaApp = $app_pos_v1;
            } elseif (
                $codigoApp === 'productos' &&
                in_array($rutaAppNormalizada, ['app_grid_mercaderias', 'public/productos/index.php'], true)
            ) {
                $rutaApp = 'public/productos/legacy_index.php';
            } elseif (
                $codigoApp === 'habilitacion_sifen' ||
                strpos($codigoApp, 'sifen') !== false ||
                strpos((string)$rutaApp, 'habilitacion_sifen_local.php') !== false
            ) {
                $rutaApp = "public/empresa/habilitacion_sifen_local.php?id_empresa=" . (int)$id_empresa;
            } elseif ($codigoApp === 'mi_caja') {
                $idCajaDef = (int)($_SESSION['id_caja_def'] ?? 0);
                $rutaApp = 'public/pos/caja.php?id_caja=' . $idCajaDef . '&id_empresa=' . (int)$id_empresa;
            } elseif ($codigoApp === 'mi_venta') {
                $idCajaDef = (int)($_SESSION['id_caja_def'] ?? 0);
                $idLogin = (int)($_SESSION['id_login'] ?? 0);
                $rutaApp = 'public/ventas/index.php?mi_venta=1&id_caja=' . $idCajaDef . '&id_login=' . $idLogin;
            } elseif ($codigoApp === 'configuracion') {
                $rutaApp = 'public/empresa/index.php';
            }
            
            $itemsMenu[] = [
                'label'       => $labelOriginal,
                'label_code'  => $itemCode,
                'order_key'   => $codigoApp !== '' ? $codigoApp : ($itemCode !== '' ? $itemCode : $rutaApp),
                'app'         => $rutaApp,
                'icono'       => $iconoApp,
                'icono_source'=> $iconoSource,
                'icono_svg'   => $iconoSvgApp,
                'orden'       => (int)($app['orden'] ?? 9999),
                'color3d'     => $colorApp,
                'visible'     => $visible
            ];
    }
}

$empresaTieneAppCargada = static function (?string $codigo = null, ?string $ruta = null) use ($appsMenuCodigosCargados, $appsMenuRutasCargadas): bool {
    $codigoNorm = strtolower(trim((string)$codigo));
    $rutaNorm = trim((string)$ruta);
    if ($codigoNorm !== '' && in_array($codigoNorm, $appsMenuCodigosCargados, true)) {
        return true;
    }
    if ($rutaNorm !== '' && in_array($rutaNorm, $appsMenuRutasCargadas, true)) {
        return true;
    }
    return false;
};

if (in_array((int)$id_empresa, SISTEMAX_SUPPORT_COMPANIES, true)) {
    if ($empresaTieneAppCargada('bandeja_suscripciones', 'public/menu/suscripciones_inbox.php')) {
        $itemsMenu[] = [
            'label' => 'Bandeja Suscripciones',
            'label_code' => 'bandeja_suscripciones',
            'order_key' => 'zzz_bandeja_suscripciones',
            'app' => 'public/menu/suscripciones_inbox.php',
            'icono' => 'chat-bubble-left-right',
            'color3d' => 'cyan',
            'visible' => true,
        ];
    }
}
if ($empresaTieneAppCargada('tracking_movil', 'public/soporte/mobile_tracking.php')) {
    $tmRoute = normalizeMenuRouteKey('public/soporte/mobile_tracking.php');
    $tmSvg = $catalogIconSvgByRoute[$tmRoute] ?? '';
    $itemsMenu[] = [
        'label'        => 'Tracking Movil',
        'label_code'   => 'tracking_movil',
        'order_key'    => 'zzz_tracking_movil',
        'app'          => 'public/soporte/mobile_tracking.php',
        'icono'        => $tmSvg !== '' ? 'tracking-movil' : 'map',
        'icono_source' => $tmSvg !== '' ? 'custom' : '',
        'icono_svg'    => $tmSvg,
        'color3d'      => 'emerald',
        'visible'      => true,
    ];
}
}

if (!empty($itemsMenu)) {
    $dedupedItems = [];
    foreach ($itemsMenu as $item) {
        $routeKey = trim((string)($item['app'] ?? ''));
        if ($routeKey === '' || str_starts_with($routeKey, '__')) {
            $dedupedItems[] = $item;
            continue;
        }

        $existingIndex = null;
        foreach ($dedupedItems as $idx => $existingItem) {
            if (($existingItem['app'] ?? '') === $routeKey) {
                $existingIndex = $idx;
                break;
            }
        }

        if ($existingIndex === null) {
            $dedupedItems[] = $item;
            continue;
        }

        if (menuIconPriority($item) > menuIconPriority($dedupedItems[$existingIndex])) {
            $dedupedItems[$existingIndex] = $item;
        }
    }
    $itemsMenu = array_values($dedupedItems);
}

// =========================================================================
// APPS OBLIGATORIAS
// Solo utilitarios fijos; el resto proviene de la suscripción
// =========================================================================
if (!function_exists('ensureSupportDesktopClientCatalogApp')) {
function ensureSupportDesktopClientCatalogApp(): void
{
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $db = Database::getMasterConnection();
            $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ? LIMIT 1");
        $stmt->execute(['soporte_desktop_cliente']);
        $idApp = (int)($stmt->fetchColumn() ?: 0);
        if ($idApp > 0) {
            $upd = $db->prepare("UPDATE saas_apps_catalogo
                    SET nombre = ?, descripcion = ?, ruta_app = ?, precio_mensual = 0, obligatoria = 0, activo = 1, en_desarrollo = 0, modulo = ?, negocio = ?
                    WHERE id_app = ?");
            $upd->execute([
                'SistemaX Assist',
                'Asistencia remota nativa del equipo cliente con SistemaX Assist',
                'public/helpwire/index.php',
                'Soporte',
                'General',
                $idApp,
                ]);
                return;
            }
            $ins = $db->prepare("INSERT INTO saas_apps_catalogo
                (codigo, nombre, descripcion, ruta_app, icono, icono_source, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
                VALUES (?, ?, ?, ?, ?, 'custom', ?, 0, 0, NULL, ?, ?, 1, 0, ?, ?)");
            $ins->execute([
                'soporte_desktop_cliente',
                'SistemaX Assist',
                'Asistencia remota nativa del equipo cliente con SistemaX Assist',
                'public/helpwire/index.php',
                'soporte',
                'cyan',
                'app_grid_soporte_desktop_cliente',
                18,
                'Soporte',
                'General',
            ]);
        } catch (Throwable $e) {
            error_log('[menu/menu] ensureSupportDesktopClientCatalogApp: ' . $e->getMessage());
        }
    }
}
// Soporte remoto aislado temporalmente.

function ensureInventoryMobileCatalogApp(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = Database::getMasterConnection();
        $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ? LIMIT 1");
        $stmt->execute(['inventario_mobile']);
        $idApp = (int)($stmt->fetchColumn() ?: 0);
        if ($idApp > 0) {
            $upd = $db->prepare("UPDATE saas_apps_catalogo
                SET nombre = ?, descripcion = ?, ruta_app = ?, precio_mensual = 0, obligatoria = 0, activo = 1, en_desarrollo = 0, modulo = ?, negocio = ?, permiso_base = ?, orden = ?
                WHERE id_app = ?");
            $upd->execute([
                'Inventario',
                'Control de inventario movil por sucursal con ajustes, traslados y conteo en vivo',
                'public/inventario/index.php',
                'Inventario',
                'Comercial',
                'app_grid_mercaderias',
                19,
                $idApp,
            ]);
            return;
        }
        $ins = $db->prepare("INSERT INTO saas_apps_catalogo
            (codigo, nombre, descripcion, ruta_app, icono, icono_source, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
            VALUES (?, ?, ?, ?, ?, 'custom', ?, 0, 0, NULL, ?, ?, 1, 0, ?, ?)");
        $ins->execute([
            'inventario_mobile',
            'Inventario',
            'Control de inventario movil por sucursal con ajustes, traslados y conteo en vivo',
            'public/inventario/index.php',
            'inventario',
            'amber',
            'app_grid_mercaderias',
            19,
            'Inventario',
            'Comercial',
        ]);
    } catch (Throwable $e) {
        error_log('[menu/menu] ensureInventoryMobileCatalogApp: ' . $e->getMessage());
    }
}
ensureInventoryMobileCatalogApp();

function ensureTransferenciaProductosCatalogApp(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = Database::getMasterConnection();
        $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ? LIMIT 1");
        $stmt->execute(['transferencia_productos']);
        $idApp = (int)($stmt->fetchColumn() ?: 0);
        if ($idApp > 0) {
            $upd = $db->prepare("UPDATE saas_apps_catalogo
                SET nombre = ?, descripcion = ?, ruta_app = ?, icono = ?, icono_source = 'custom', color = ?, precio_mensual = 0, obligatoria = 0, activo = 1, en_desarrollo = 0, modulo = ?, negocio = ?, permiso_base = ?, orden = ?
                WHERE id_app = ? AND COALESCE(icono_source, '') != 'custom'");
            $upd->execute([
                'Transferencia de Productos',
                'Traslados de stock entre sucursales con impresión, recepción y seguimiento en tiempo real',
                'public/inventario/transferencias.php',
                'transferencia-productos',
                'sky',
                'Inventario',
                'Comercial',
                'app_grid_mercaderias',
                20,
                $idApp,
            ]);
            return;
        }
        $ins = $db->prepare("INSERT INTO saas_apps_catalogo
            (codigo, nombre, descripcion, ruta_app, icono, icono_source, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
            VALUES (?, ?, ?, ?, ?, 'custom', ?, 0, 0, NULL, ?, ?, 1, 0, ?, ?)");
        $ins->execute([
            'transferencia_productos',
            'Transferencia de Productos',
            'Traslados de stock entre sucursales con impresión, recepción y seguimiento en tiempo real',
            'public/inventario/transferencias.php',
            'transferencia-productos',
            'sky',
            'app_grid_mercaderias',
            20,
            'Inventario',
            'Comercial',
        ]);
    } catch (Throwable $e) {
        error_log('[menu/menu] ensureTransferenciaProductosCatalogApp: ' . $e->getMessage());
    }
}

ensureTransferenciaProductosCatalogApp();

function ensureBugCenterCatalogApp(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = Database::getMasterConnection();
        $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ? LIMIT 1");
        $stmt->execute(['centro_bug']);
        $idApp = (int)($stmt->fetchColumn() ?: 0);
        if ($idApp > 0) {
            $upd = $db->prepare("UPDATE saas_apps_catalogo
                SET nombre = ?, descripcion = ?, ruta_app = ?, icono = ?, icono_source = 'custom', color = ?, precio_mensual = 0, obligatoria = 0, activo = 1, en_desarrollo = 0, modulo = ?, negocio = ?, permiso_base = ?, orden = ?
                WHERE id_app = ? AND COALESCE(icono_source, '') != 'custom'");
            $upd->execute([
                'Centro de Bug',
                'Central de reportes y seguimiento de bugs enviados por usuarios y soporte',
                'public/devbugs/index.php',
                'bug',
                'red',
                'Soporte',
                'General',
                'centro_bug',
                22,
                $idApp,
            ]);
            return;
        }
        $ins = $db->prepare("INSERT INTO saas_apps_catalogo
            (codigo, nombre, descripcion, ruta_app, icono, icono_source, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
            VALUES (?, ?, ?, ?, ?, 'custom', ?, 0, 0, NULL, ?, ?, 1, 0, ?, ?)");
        $ins->execute([
            'centro_bug',
            'Centro de Bug',
            'Central de reportes y seguimiento de bugs enviados por usuarios y soporte',
            'public/devbugs/index.php',
            'bug',
            'red',
            'centro_bug',
            22,
            'Soporte',
            'General',
        ]);
    } catch (Throwable $e) {
        error_log('[menu/menu] ensureBugCenterCatalogApp: ' . $e->getMessage());
    }
}
ensureBugCenterCatalogApp();

function ensureAppsCenterCatalogApp(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = Database::getMasterConnection();
        // central_apps.php no existe — apuntar al archivo real y asegurar icono custom
        $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = 'central_apps' LIMIT 1");
        $stmt->execute();
        $idApp = (int)($stmt->fetchColumn() ?: 0);
        if ($idApp > 0) {
            $db->prepare("
                UPDATE saas_apps_catalogo
                SET ruta_app = 'public/central_apps.php',
                    icono = 'central-apps',
                    icono_source = 'custom',
                    icono_svg = '/public/assets/images/icons_v2/central-apps.svg',
                    activo = 1
                WHERE id_app = ?
            ")->execute([$idApp]);
        } else {
            $db->prepare("
                INSERT INTO saas_apps_catalogo
                    (codigo, nombre, descripcion, ruta_app, icono, icono_source, icono_svg, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
                VALUES ('central_apps','Central de Apps','Panel central para administrar catálogo de apps y suscripciones',
                        'public/central_apps.php','central-apps','custom','/public/assets/images/icons_v2/central-apps.svg',
                        'green',0,0,NULL,'suscripciones',23,1,0,'Soporte','General')
            ")->execute();
        }
    } catch (Throwable $e) {
        error_log('[menu/menu] ensureAppsCenterCatalogApp: ' . $e->getMessage());
    }
}
ensureAppsCenterCatalogApp();


function ensureGeolocationConfigCatalogApp(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = Database::getMasterConnection();
        $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ? LIMIT 1");
        $stmt->execute(['geolocalizacion_config']);
        $idApp = (int)($stmt->fetchColumn() ?: 0);
        if ($idApp > 0) {
            $upd = $db->prepare("UPDATE saas_apps_catalogo
                SET nombre = ?, descripcion = ?, ruta_app = ?, icono = ?, icono_source = 'custom', color = ?, precio_mensual = 0, obligatoria = 0, activo = 1, en_desarrollo = 0, modulo = ?, negocio = ?, permiso_base = ?, orden = ?
                WHERE id_app = ? AND COALESCE(icono_source, '') != 'custom'");
            $upd->execute([
                'Configuración Geolocalización',
                'Configura dispositivos, tracking y tokens del módulo de geolocalización móvil',
                'public/geolocalizacion-config/index.php',
                'map-pin',
                'emerald',
                'Soporte',
                'General',
                'geolocalizacion_config',
                24,
                $idApp,
            ]);
            return;
        }
        $ins = $db->prepare("INSERT INTO saas_apps_catalogo
            (codigo, nombre, descripcion, ruta_app, icono, icono_source, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
            VALUES (?, ?, ?, ?, ?, 'custom', ?, 0, 0, NULL, ?, ?, 1, 0, ?, ?)");
        $ins->execute([
            'geolocalizacion_config',
            'Configuración Geolocalización',
            'Configura dispositivos, tracking y tokens del módulo de geolocalización móvil',
            'public/geolocalizacion-config/index.php',
            'map-pin',
            'emerald',
            'geolocalizacion_config',
            24,
            'Soporte',
            'General',
        ]);
    } catch (Throwable $e) {
        error_log('[menu/menu] ensureGeolocationConfigCatalogApp: ' . $e->getMessage());
    }
}
ensureGeolocationConfigCatalogApp();

function ensurePedidoProveedoresCatalogApp(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $db = Database::getMasterConnection();
        $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ? LIMIT 1");
        $stmt->execute(['pedido_proveedores']);
        $idApp = (int)($stmt->fetchColumn() ?: 0);

        if ($idApp > 0) {
            $upd = $db->prepare("UPDATE saas_apps_catalogo
                SET nombre = ?, descripcion = ?, ruta_app = ?, icono = ?, icono_source = 'custom', color = ?, precio_mensual = 0, obligatoria = 0, activo = 1, en_desarrollo = 0, modulo = ?, negocio = ?, permiso_base = ?, orden = ?
                WHERE id_app = ? AND COALESCE(icono_source, '') != 'custom'");
            $upd->execute([
                'Pedido a Proveedores',
                'Generación y gestión de pedidos de compra hacia proveedores desde el módulo de compras',
                'public/pos/pedidos_proveedor_list.php',
                'pedido-proveedores',
                'violet',
                'Compras',
                'Comercial',
                'app_grid_factura_compras',
                20,
                $idApp,
            ]);
            return;
        }

        $ins = $db->prepare("INSERT INTO saas_apps_catalogo
            (codigo, nombre, descripcion, ruta_app, icono, icono_source, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
            VALUES (?, ?, ?, ?, ?, 'custom', ?, 0, 0, NULL, ?, ?, 1, 0, ?, ?)");
        $ins->execute([
            'pedido_proveedores',
            'Pedido a Proveedores',
            'Generación y gestión de pedidos de compra hacia proveedores desde el módulo de compras',
            'public/pos/pedidos_proveedor_list.php',
            'pedido-proveedores',
            'violet',
            'app_grid_factura_compras',
            20,
            'Compras',
            'Comercial',
        ]);
    } catch (Throwable $e) {
        error_log('[menu/menu] ensurePedidoProveedoresCatalogApp: ' . $e->getMessage());
    }
}

ensurePedidoProveedoresCatalogApp();

function ensureAlquileresCatalogApps(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $apps = [
        [
            'codigo' => 'alquileres',
            'nombre' => 'Alquileres',
            'descripcion' => 'Suite principal de gestión inmobiliaria con panel, propiedades, contratos, facturación mensual y avisos de vencimiento.',
            'ruta_app' => 'public/alquileres/index.php',
            'icono' => 'key',
            'color' => 'emerald',
            'precio_mensual' => 150000,
            'permiso_base' => 'app_grid_alquileres',
            'orden' => 8,
            'modulo' => 'Inmobiliaria',
            'negocio' => 'Inmobiliaria',
        ],
        [
            'codigo' => 'alquileres_gastos',
            'nombre' => 'Alquileres Gastos',
            'descripcion' => 'Registro de gastos por propiedad con traslado opcional al inquilino y conciliación con la factura mensual.',
            'ruta_app' => 'public/alquileres/gastos.php',
            'icono' => 'receipt',
            'color' => 'rose',
            'precio_mensual' => 40000,
            'permiso_base' => 'app_grid_alquileres_gastos',
            'orden' => 81,
            'modulo' => 'Inmobiliaria',
            'negocio' => 'Inmobiliaria',
        ],
        [
            'codigo' => 'alquileres_imagenes',
            'nombre' => 'Alquileres Imágenes',
            'descripcion' => 'Galería de propiedades con imágenes principales, tira visual, cargas locales y capturas desde buscadores.',
            'ruta_app' => 'public/alquileres/imagenes.php',
            'icono' => 'images',
            'color' => 'fuchsia',
            'precio_mensual' => 50000,
            'permiso_base' => 'app_grid_alquileres_imagenes',
            'orden' => 82,
            'modulo' => 'Inmobiliaria',
            'negocio' => 'Inmobiliaria',
        ],
        [
            'codigo' => 'alquileres_propiedades',
            'nombre' => 'Alquileres Propiedades',
            'descripcion' => 'Administración de inmuebles, canon, depósito, estado y ficha locativa por unidad.',
            'ruta_app' => 'public/alquileres/propiedades.php',
            'icono' => 'building',
            'color' => 'sky',
            'precio_mensual' => 35000,
            'permiso_base' => 'app_grid_alquileres_propiedades',
            'orden' => 83,
            'modulo' => 'Inmobiliaria',
            'negocio' => 'Inmobiliaria',
        ],
        [
            'codigo' => 'alquileres_contratos',
            'nombre' => 'Alquileres Contratos',
            'descripcion' => 'Confección de contratos privados, inquilinos, cláusulas editables y documento imprimible.',
            'ruta_app' => 'public/alquileres/contratos.php',
            'icono' => 'file-signature',
            'color' => 'amber',
            'precio_mensual' => 45000,
            'permiso_base' => 'app_grid_alquileres_contratos',
            'orden' => 84,
            'modulo' => 'Inmobiliaria',
            'negocio' => 'Inmobiliaria',
        ],
        [
            'codigo' => 'alquileres_facturacion',
            'nombre' => 'Alquileres Facturación',
            'descripcion' => 'Generación mensual de facturas, control de cobro y preparación de payload para factura electrónica.',
            'ruta_app' => 'public/alquileres/facturacion.php',
            'icono' => 'file-invoice-dollar',
            'color' => 'teal',
            'precio_mensual' => 60000,
            'permiso_base' => 'app_grid_alquileres_facturacion',
            'orden' => 85,
            'modulo' => 'Inmobiliaria',
            'negocio' => 'Inmobiliaria',
        ],
        [
            'codigo' => 'alquileres_avisos',
            'nombre' => 'Alquileres Avisos',
            'descripcion' => 'Recordatorios de vencimiento por WhatsApp con programación, consentimiento y trazabilidad de envío.',
            'ruta_app' => 'public/alquileres/avisos.php',
            'icono' => 'bell',
            'color' => 'violet',
            'precio_mensual' => 30000,
            'permiso_base' => 'app_grid_alquileres_avisos',
            'orden' => 86,
            'modulo' => 'Inmobiliaria',
            'negocio' => 'Inmobiliaria',
        ],
    ];

    try {
        $db = Database::getMasterConnection();
        foreach ($apps as $app) {
            $stmt = $db->prepare("SELECT id_app FROM saas_apps_catalogo WHERE codigo = ? LIMIT 1");
            $stmt->execute([$app['codigo']]);
            $idApp = (int)($stmt->fetchColumn() ?: 0);
            if ($idApp > 0) {
                $upd = $db->prepare("UPDATE saas_apps_catalogo
                    SET nombre = ?, descripcion = ?, ruta_app = ?, icono = ?, color = ?, precio_mensual = ?, obligatoria = 0, activo = 1, en_desarrollo = 0, modulo = ?, negocio = ?, permiso_base = ?, orden = ?
                    WHERE id_app = ? AND COALESCE(icono_source, '') != 'custom'");
                $upd->execute([
                    $app['nombre'],
                    $app['descripcion'],
                    $app['ruta_app'],
                    $app['icono'],
                    $app['color'],
                    $app['precio_mensual'],
                    $app['modulo'],
                    $app['negocio'],
                    $app['permiso_base'],
                    $app['orden'],
                    $idApp,
                ]);
                continue;
            }

            $ins = $db->prepare("INSERT INTO saas_apps_catalogo
                (codigo, nombre, descripcion, ruta_app, icono, color, precio_mensual, obligatoria, requiere_modulo, permiso_base, orden, activo, en_desarrollo, modulo, negocio)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, ?, ?, 1, 0, ?, ?)");
            $ins->execute([
                $app['codigo'],
                $app['nombre'],
                $app['descripcion'],
                $app['ruta_app'],
                $app['icono'],
                $app['color'],
                $app['precio_mensual'],
                $app['permiso_base'],
                $app['orden'],
                $app['modulo'],
                $app['negocio'],
            ]);
        }
    } catch (Throwable $e) {
        error_log('[menu/menu] ensureAlquileresCatalogApps: ' . $e->getMessage());
    }
}
ensureAlquileresCatalogApps();

function ensureAlquileresBusinessTemplateAndAdminPerms(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $db = Database::getMasterConnection();

        $db->prepare("INSERT INTO tipo_negocio (nombre, orden, activo, disponible)
                      VALUES ('Inmobiliaria', 340, 1, 1)
                      ON DUPLICATE KEY UPDATE orden = VALUES(orden), activo = 1, disponible = 1")
            ->execute();

        $stmtTipo = $db->prepare("SELECT id FROM tipo_negocio WHERE nombre = 'Inmobiliaria' LIMIT 1");
        $stmtTipo->execute();
        $tipoId = (int)($stmtTipo->fetchColumn() ?: 0);
        if ($tipoId > 0) {
            $db->prepare("DELETE FROM tipo_negocio_app WHERE tipo_negocio_id = ?")->execute([$tipoId]);

            $stmtApps = $db->query("
                SELECT id_app, codigo
                FROM saas_apps_catalogo
                WHERE codigo IN (
                    'alquileres',
                    'alquileres_gastos',
                    'alquileres_imagenes',
                    'alquileres_propiedades',
                    'alquileres_contratos',
                    'alquileres_facturacion',
                    'alquileres_avisos'
                )
            ");
            $apps = $stmtApps ? ($stmtApps->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $orderMap = [
                'alquileres' => 10,
                'alquileres_gastos' => 20,
                'alquileres_imagenes' => 30,
                'alquileres_propiedades' => 40,
                'alquileres_contratos' => 50,
                'alquileres_facturacion' => 60,
                'alquileres_avisos' => 70,
            ];
            $insTpl = $db->prepare("INSERT INTO tipo_negocio_app (tipo_negocio_id, id_app, orden, activo) VALUES (?, ?, ?, 1)");
            foreach ($apps as $app) {
                $codigo = (string)($app['codigo'] ?? '');
                $insTpl->execute([
                    $tipoId,
                    (int)($app['id_app'] ?? 0),
                    (int)($orderMap[$codigo] ?? 100),
                ]);
            }
        }

        $permApps = [
            'app_grid_alquileres',
            'app_grid_alquileres_gastos',
            'app_grid_alquileres_imagenes',
            'app_grid_alquileres_propiedades',
            'app_grid_alquileres_contratos',
            'app_grid_alquileres_facturacion',
            'app_grid_alquileres_avisos',
        ];
        $adminGroups = $db->query("
            SELECT id_grupo, id
            FROM sec_groups
            WHERE LOWER(TRIM(COALESCE(description, ''))) IN ('administrador', 'administracion', 'admin', 'adm')
        ");
        $rows = $adminGroups ? ($adminGroups->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $checkStmt = $db->prepare("SELECT id FROM sec_groups_apps WHERE id_grupo = ? AND group_id = ? AND app_name = ? LIMIT 1");
        $insPerm = $db->prepare("
            INSERT INTO sec_groups_apps
                (id_grupo, group_id, app_name, priv_access, priv_insert, priv_delete, priv_update, priv_export, priv_print)
            VALUES (?, ?, ?, 'Y', 'Y', 'Y', 'Y', 'Y', 'Y')
        ");
        foreach ($rows as $group) {
            $empresaId = (int)($group['id_grupo'] ?? 0);
            $groupId = (int)($group['id'] ?? 0);
            if ($empresaId <= 0 || $groupId <= 0) {
                continue;
            }
            foreach ($permApps as $appName) {
                $checkStmt->execute([$empresaId, $groupId, $appName]);
                if ($checkStmt->fetchColumn()) {
                    continue;
                }
                $insPerm->execute([$empresaId, $groupId, $appName]);
            }
        }
    } catch (Throwable $e) {
        error_log('[menu/menu] ensureAlquileresBusinessTemplateAndAdminPerms: ' . $e->getMessage());
    }
}
ensureAlquileresBusinessTemplateAndAdminPerms();

$rutaCentroBug = 'public/devbugs/index.php';
if (in_array((int)$id_empresa, SISTEMAX_SUPPORT_COMPANIES, true)) {
    $yaExisteBug = false;
    foreach ($itemsMenu as $it) {
        if (($it['app'] ?? '') === $rutaCentroBug) {
            $yaExisteBug = true;
            break;
        }
    }
    if (!$yaExisteBug) {
        if ($empresaTieneAppCargada('centro_bug', $rutaCentroBug)) {
            $catMap = $catalogIconSvgByRoute ?? [];
            $cbSvg = $catMap[normalizeMenuRouteKey($rutaCentroBug)] ?? '';
            $itemsMenu[] = [
                'label'        => 'Centro de Bug',
                'label_code'   => 'centro_bug',
                'app'          => $rutaCentroBug,
                'icono'        => $cbSvg !== '' ? 'bug' : 'shield-exclamation',
                'icono_source' => $cbSvg !== '' ? 'custom' : '',
                'icono_svg'    => $cbSvg,
                'color3d'      => 'red',
                'visible'      => true,
            ];
        }
    }
}

// Google Drive Setup - Solo visible para empresa 168
if ((int)$id_empresa === 168) {
    $rutaGDriveSetup = 'public/setup/google_drive_setup.php';
    $yaExisteGDrive = false;
    foreach ($itemsMenu as $it) {
        if (($it['app'] ?? '') === $rutaGDriveSetup) {
            $yaExisteGDrive = true;
            break;
        }
    }
    if (!$yaExisteGDrive) {
        if ($empresaTieneAppCargada('google_drive', $rutaGDriveSetup)) {
            $itemsMenu[] = [
                'label' => 'Google Drive',
                'label_code' => 'google_drive',
                'app' => $rutaGDriveSetup,
                'icono' => 'cloud-arrow-up',
                'color3d' => 'green',
                'visible' => true
            ];
        }
    }
}

{
    $rutaI18nAdmin = 'public/i18n_admin/index.php';
    $yaExisteI18nAdmin = false;
    foreach ($itemsMenu as $it) {
        if (($it['app'] ?? '') === $rutaI18nAdmin) {
            $yaExisteI18nAdmin = true;
            break;
        }
    }
    if (!$yaExisteI18nAdmin) {
        if ($empresaTieneAppCargada('i18n_admin', $rutaI18nAdmin)) {
            $itemsMenu[] = [
                'label' => 'I18N Studio',
                'label_code' => 'i18n_admin',
                'order_key' => 'i18n_admin',
                'app' => $rutaI18nAdmin,
                'icono' => 'language',
                'color3d' => 'indigo',
                'visible' => true,
            ];
        }
    }
}

$codigosExistentes = array_column($itemsMenu, 'app');
$appsObligatorias = [];

// Solo agregar apps obligatorias si la empresa tiene al menos una app asignada
if (!empty($appsMenuData)) {
    // Leer icono de vcorta_ai desde el catálogo
    $vcortaIcono = 'sparkles';
    $vcortaIconoSvg = '';
    $vcortaIconoSource = '';
    $vcortaColor = 'cyan';
    try {
        $stVc = $db->prepare("SELECT icono, icono_source, icono_svg, color FROM saas_apps_catalogo WHERE codigo IN ('vcorta_ai', 'vcorta') ORDER BY FIELD(codigo,'vcorta_ai','vcorta') LIMIT 1");
        $stVc->execute();
        $rowVc = $stVc->fetch(PDO::FETCH_ASSOC);
        if ($rowVc) {
            $vcortaColor = $rowVc['color'] ?: 'cyan';
            $vcortaIconoSource = trim((string)($rowVc['icono_source'] ?? ''));
            $vcortaIconoSvg = trim((string)($rowVc['icono_svg'] ?? ''));
            $vcortaIcono = trim((string)($rowVc['icono'] ?? '')) ?: 'sparkles';
            if ($vcortaIconoSvg !== '') {
                $vcortaIconoSvg = resolveMenuCatalogIconSvg($vcortaIconoSvg) ?: $vcortaIconoSvg;
            } elseif ($vcortaIconoSource === 'custom' && $vcortaIcono !== '') {
                $vcortaIconoSvg = resolveMenuCatalogIconSvg('/public/assets/images/icons_v2/' . $vcortaIcono . '.svg');
            }
        }
    } catch (Throwable $_e) {}

    $appsObligatorias = [
        ['label' => 'Vcorta', 'label_code' => 'vcorta', 'order_key' => '__vcorta__', 'app' => '__vcorta__',
         'icono' => $vcortaIcono, 'icono_source' => $vcortaIconoSource, 'icono_svg' => $vcortaIconoSvg, 'color3d' => $vcortaColor,
         'visible' => true],
        ['label' => 'Salir', 'label_code' => 'logout', 'order_key' => '__logout__', 'app' => '__logout__', 'icono' => 'arrow-right-on-rectangle', 'color3d' => 'red',
         'visible' => true],
    ];
    if ($empresaTieneAppCargada('centro_notificaciones', 'public/menu/centro_notificaciones.php')) {
        $appsObligatorias[] = ['label' => 'Centro Notificaciones', 'label_code' => 'centro_notificaciones', 'order_key' => 'centro_notificaciones', 'app' => 'public/menu/centro_notificaciones.php', 'icono' => 'bell', 'color3d' => 'blue',
         'visible' => true];
    }
    if ($empresaTieneAppCargada('push_diagnostico', 'public/menu/push_diagnostico.php')) {
        $catMap2 = $catalogIconSvgByRoute ?? [];
        $pdSvg2 = $catMap2[normalizeMenuRouteKey('public/menu/push_diagnostico.php')] ?? '';
        $appsObligatorias[] = ['label' => 'Diagnóstico Push', 'label_code' => 'push_diagnostico', 'order_key' => 'push_diagnostico', 'app' => 'public/menu/push_diagnostico.php',
         'icono' => $pdSvg2 !== '' ? 'signal' : 'signal', 'icono_source' => $pdSvg2 !== '' ? 'custom' : '', 'icono_svg' => $pdSvg2, 'color3d' => 'cyan', 'visible' => true];
    }
    if ($empresaTieneAppCargada('tracking_movil', 'public/soporte/mobile_tracking.php')) {
        $catMap2 = $catalogIconSvgByRoute ?? [];
        $tmSvg3 = $catMap2[normalizeMenuRouteKey('public/soporte/mobile_tracking.php')] ?? '';
        $appsObligatorias[] = [
            'label'        => 'Tracking Movil',
            'label_code'   => 'tracking_movil',
            'order_key'    => 'tracking_movil',
            'app'          => 'public/soporte/mobile_tracking.php',
            'icono'        => $tmSvg3 !== '' ? 'tracking-movil' : 'map',
            'icono_source' => $tmSvg3 !== '' ? 'custom' : '',
            'icono_svg'    => $tmSvg3,
            'color3d'      => 'emerald',
            'visible'      => true,
        ];
    }
    if ((int)$id_empresa === 169) {
        $suscLabel = 'Central de Apps';
        $suscIcono = 'central-apps';
        $suscSvg   = '';
        $suscColor = 'green';
        try {
            $stSusc = $db->prepare("SELECT nombre, icono, icono_source, icono_svg, color FROM saas_apps_catalogo WHERE codigo = 'suscripciones' LIMIT 1");
            $stSusc->execute();
            $rowSusc = $stSusc->fetch(PDO::FETCH_ASSOC);
            if ($rowSusc) {
                if (!empty($rowSusc['nombre'])) $suscLabel = $rowSusc['nombre'];
                $suscColor  = $rowSusc['color'] ?: 'green';
                $dbIconoSvg = trim((string)($rowSusc['icono_svg'] ?? ''));
                $dbSource   = trim((string)($rowSusc['icono_source'] ?? ''));
                $dbIcono    = trim((string)($rowSusc['icono'] ?? ''));
                if ($dbSource === 'custom' && $dbIcono !== '') {
                    $suscIcono = $dbIcono;
                    $suscSvg   = resolveMenuCatalogIconSvg(
                        $dbIconoSvg !== '' ? $dbIconoSvg : '/public/assets/images/icons_v2/' . $dbIcono . '.svg'
                    );
                } elseif ($dbIconoSvg !== '') {
                    $suscSvg   = resolveMenuCatalogIconSvg($dbIconoSvg);
                    $suscIcono = basename($suscSvg, '.svg') ?: 'central-apps';
                }
            }
        } catch (Throwable $_e) {}
        $appsObligatorias[] = [
            'label'        => $suscLabel,
            'label_code'   => 'suscripciones',
            'order_key'    => 'suscripciones',
            'app'          => 'public/suscripciones.php',
            'icono'        => $suscIcono,
            'icono_source' => 'custom',
            'icono_svg'    => $suscSvg,
            'color3d'      => $suscColor,
            'visible'      => true,
        ];
    }
    if (is_file(dirname(__DIR__) . '/alquileres/index.php')) {
        $alqRoute = normalizeMenuRouteKey('public/alquileres/index.php');
        $alqSvg = $catalogIconSvgByRoute[$alqRoute] ?? '';
        $appsObligatorias[] = [
            'label'        => 'Alquileres',
            'label_code'   => 'alquileres',
            'order_key'    => 'alquileres',
            'app'          => 'public/alquileres/index.php',
            'icono'        => $alqSvg !== '' ? 'alquileres' : 'key',
            'icono_source' => $alqSvg !== '' ? 'custom' : '',
            'icono_svg'    => $alqSvg,
            'color3d'      => 'emerald',
            'visible'      => true,
        ];
    }
}
foreach ($appsObligatorias as $ob) {
    if (!in_array($ob['app'], $codigosExistentes)) {
        $itemsMenu[] = $ob;
    }
}
$itemsMenu = array_values(array_filter($itemsMenu, static function(array $item): bool {
    $app = (string)($item['app'] ?? '');
    $label = strtolower(trim((string)($item['label'] ?? '')));
    if (str_starts_with($app, 'public/soporte_remoto/cliente.php')) {
        return false;
    }
    if ($app === 'public/soporte_remoto/index.php' || $app === 'public/soporte_shadow/index.php') {
        return false;
    }
    if (str_starts_with($app, 'public/helpwire/') || str_starts_with($app, 'public/soporte/')) {
        return false;
    }
    if (in_array($label, ['sistemax assist', 'mesa soporte', 'config soporte', 'assist console'], true)) {
        return false;
    }
    return true;
}));

{
    $hasTrackingMovilFinal = false;
    foreach ($itemsMenu as $it) {
        if (($it['app'] ?? '') === 'public/soporte/mobile_tracking.php') {
            $hasTrackingMovilFinal = true;
            break;
        }
    }
    if (!$hasTrackingMovilFinal && $empresaTieneAppCargada('tracking_movil', 'public/soporte/mobile_tracking.php')) {
        $catMap = $catalogIconSvgByRoute ?? [];
        $tmSvg2 = $catMap[normalizeMenuRouteKey('public/soporte/mobile_tracking.php')] ?? '';
        $itemsMenu[] = [
            'label'        => 'Tracking Movil',
            'label_code'   => 'tracking_movil',
            'order_key'    => 'tracking_movil',
            'app'          => 'public/soporte/mobile_tracking.php',
            'icono'        => $tmSvg2 !== '' ? 'tracking-movil' : 'map',
            'icono_source' => $tmSvg2 !== '' ? 'custom' : '',
            'icono_svg'    => $tmSvg2,
            'color3d'      => 'emerald',
            'visible'      => true,
        ];
    }
}

if (in_array((int)$id_empresa, SISTEMAX_SUPPORT_COMPANIES, true)) {
    $hasPushDiagnosticFinal = false;
    foreach ($itemsMenu as $it) {
        if (($it['app'] ?? '') === 'public/menu/push_diagnostico.php') {
            $hasPushDiagnosticFinal = true;
            break;
        }
    }
    if (!$hasPushDiagnosticFinal) {
        if ($empresaTieneAppCargada('push_diagnostico', 'public/menu/push_diagnostico.php')) {
            $catMap = $catalogIconSvgByRoute ?? [];
            $pdSvg = $catMap[normalizeMenuRouteKey('public/menu/push_diagnostico.php')] ?? '';
            $itemsMenu[] = [
                'label'        => 'Diagnóstico Push',
                'label_code'   => 'push_diagnostico',
                'order_key'    => 'push_diagnostico',
                'app'          => 'public/menu/push_diagnostico.php',
                'icono'        => $pdSvg !== '' ? 'signal' : 'signal',
                'icono_source' => $pdSvg !== '' ? 'custom' : '',
                'icono_svg'    => $pdSvg,
                'color3d'      => 'cyan',
                'visible'      => true,
            ];
        }
    }

    foreach ($itemsMenu as $idx => $it) {
        if (($it['app'] ?? '') === 'public/soporte/mobile_tracking.php') {
            $trackingItem = $it;
            array_splice($itemsMenu, $idx, 1);
            array_unshift($itemsMenu, $trackingItem);
            break;
        }
    }
}

foreach ($itemsMenu as &$itemMenu) {
    $itemMenu['label'] = translateMenuItemLabel((string)($itemMenu['label_code'] ?? ''), (string)($itemMenu['label'] ?? ''));
    if (($itemMenu['app'] ?? '') === '__logout__') {
        $itemMenu['label'] = t('common.logout');
        $itemMenu['icono'] = 'arrow-left-start-on-rectangle';
        $itemMenu['color3d'] = 'red';
    }
}
unset($itemMenu);

$showMenuDebug = ((string)($_GET['debug_menu'] ?? '')) === '1';
$menuDebug = [
    'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
    'id_empresa' => (int)$id_empresa,
    'id_login' => (int)$id_login,
    'group_id' => (int)$id_grupo,
    'usr_priv_admin' => (string)($_SESSION['usr_priv_admin'] ?? 'N'),
    'usr_ti' => (bool)$usr_ti,
    'modulo' => (int)$modulo,
    'dbu' => (string)$dbu,
    'empresa_desarrollo' => (bool)$esEmpresaDesarrollo,
    'apps_result_success' => (bool)($resultadoApps['success'] ?? false),
    'apps_result_error' => (string)($resultadoApps['error'] ?? ''),
    'apps_menu_data_count' => count($appsMenuData),
    'items_menu_count' => count($itemsMenu),
    'apps_codigos' => array_values(array_slice($appsMenuCodigosCargados, 0, 40)),
    'items_visibles' => array_values(array_map(static function (array $item): string {
        return (string)($item['label'] ?? '');
    }, array_values(array_filter($itemsMenu, static function (array $item): bool {
        return !isset($item['visible']) || $item['visible'];
    })))),
    'permisos_cargados' => count($arr_perm),
];

if ($id_empresa > 0 && (count($appsMenuData) === 0 || count($itemsMenu) <= 3)) {
    error_log('[menu/menu.php][diag] ' . json_encode([
        'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'id_empresa' => (int)$id_empresa,
        'id_login' => (int)$id_login,
        'id_grupo' => (int)$id_grupo,
        'session_group_id' => (int)($_SESSION['group_id'] ?? 0),
        'session_id_group' => (int)($_SESSION['id_group'] ?? 0),
        'usr_priv_admin' => (string)($_SESSION['usr_priv_admin'] ?? 'N'),
        'usr_ti' => (bool)$usr_ti,
        'modulo' => (int)$modulo,
        'dbu' => (string)$dbu,
        'empresa_desarrollo' => (bool)$esEmpresaDesarrollo,
        'apps_result_success' => (bool)($resultadoApps['success'] ?? false),
        'apps_result_error' => (string)($resultadoApps['error'] ?? ''),
        'apps_menu_data_count' => count($appsMenuData),
        'items_menu_count' => count($itemsMenu),
        'apps_codigos' => array_values(array_slice($appsMenuCodigosCargados, 0, 20)),
        'items_visibles' => $menuDebug['items_visibles'],
        'permisos_cargados' => count($arr_perm),
    ], JSON_UNESCAPED_UNICODE));
}

// Fijar "Salir" siempre al final del menú principal.
$idxLogout = -1;
foreach ($itemsMenu as $k => $m) {
    if (($m['app'] ?? '') === '__logout__') {
        $idxLogout = (int)$k;
        break;
    }
}
if ($idxLogout >= 0 && isset($itemsMenu[$idxLogout])) {
    $logoutItem = $itemsMenu[$idxLogout];
    array_splice($itemsMenu, $idxLogout, 1);
    $itemsMenu[] = $logoutItem;
}

$dockIconMap = [];
foreach ($itemsMenu as $dockItem) {
    if (isset($dockItem['visible']) && !$dockItem['visible']) continue;
    $appRoute = (string)($dockItem['app'] ?? '');
    if ($appRoute === '' || str_starts_with($appRoute, '__')) continue;
    $dockUrl = '/' . ltrim($appRoute, '/');
    $dockIconMap[$dockUrl] = resolveMenuIconSvg($dockItem);
}

$savedHomeAppsOrder = [];
try {
    $prefsDb = Database::getMasterConnection();
    $savedHomeAppsOrder = loadUserMenuPreference($prefsDb, (int)$id_empresa, (int)$id_login, 'home_apps_order');
} catch (Throwable $e) {
    $savedHomeAppsOrder = [];
}

$menuAction = $_GET['action'] ?? $_POST['action'] ?? '';
if ($menuAction === 'load_home_apps_order' || $menuAction === 'save_home_apps_order') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $prefsDb = Database::getMasterConnection();
        if ($menuAction === 'load_home_apps_order') {
            echo json_encode([
                'ok' => true,
                'order' => loadUserMenuPreference($prefsDb, (int)$id_empresa, (int)$id_login, 'home_apps_order'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput ?: '[]', true);
        $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        saveUserMenuPreference($prefsDb, (int)$id_empresa, (int)$id_login, 'home_apps_order', $order);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'No se pudo persistir el orden del menu',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(SmxI18n::getLocale()) ?>" class="<?= $isMobileDevice ? 'is-mobile' : '' ?><?= (isset($_COOKIE['wallpaper_disabled']) && $_COOKIE['wallpaper_disabled'] === 'true') ? ' wallpaper-disabled' : '' ?>">
<script>
    window.__MENU_I18N__ = <?= json_encode($menuI18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    // Detectar tema inicial (preferencia guardada o del sistema)
    (function() {
        const savedTheme = localStorage.getItem('theme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const theme = (savedTheme === 'dark' || savedTheme === 'light')
            ? savedTheme
            : (prefersDark ? 'dark' : 'light');
        document.documentElement.setAttribute('data-bs-theme', theme);
        document.documentElement.style.colorScheme = theme;
        if (theme === 'dark') {
            document.documentElement.classList.add('dark');
        }
    })();
</script>

<head>
    <meta charset="UTF-8">
    <script>
        (function() {
            try {
                if (localStorage.getItem('wallpaper_disabled') === 'true') {
                    document.documentElement.classList.add('wallpaper-disabled');
                }
            } catch (e) {}
        })();
    </script>
    <style>
        html.wallpaper-disabled #video-background,
        html.wallpaper-disabled #video-overlay,
        html.wallpaper-disabled #image-background {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
        }
        html.wallpaper-disabled,
        html.wallpaper-disabled body {
            background: #0b1220 !important;
        }
    </style>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#3b82f6" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">
    <meta name="format-detection" content="telephone=no">
    <meta name="msapplication-tap-highlight" content="no">
    <link rel="manifest" href="../manifest.json">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon.png">
    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <title><?= htmlspecialchars(t('common.home')) ?> - Xpro</title>

    <link rel="stylesheet" href="../assets/tailwind.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        * {
            -webkit-tap-highlight-color: transparent;
            -webkit-touch-callout: none;
        }

        body,
        html {
            width: 100%;
            min-height: 100%;
            height: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
            overflow-x: hidden;
            font-family: 'Poppins', sans-serif;
            color-scheme: light dark;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            overscroll-behavior-y: auto;
        }

        /* Variables de color */
        :root {
            --label-color: #1e293b;
            --label-shadow: white;
            --bg-color: #f8fafc;
            --dock-bg: rgba(248, 249, 250, 0.85);
            --safe-top: env(safe-area-inset-top, 0px);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
            --safe-left: env(safe-area-inset-left, 0px);
            --safe-right: env(safe-area-inset-right, 0px);
        }

        html.dark {
            --label-color: #f1f5f9;
            --label-shadow: #0f172a;
            --bg-color: #0f172a;
            --dock-bg: rgba(15, 23, 42, 0.85);
        }

        /* Liquid Glass Effect - Glassmorphism */
        .liquid-glass-topbar {
            background: rgba(248, 249, 250, 0.75) !important;
            backdrop-filter: blur(24px) saturate(180%) !important;
            -webkit-backdrop-filter: blur(24px) saturate(180%) !important;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2) !important;
            box-shadow: 0 4px 16px 0 rgba(0, 0, 0, 0.08),
                inset 0 -1px 0 0 rgba(255, 255, 255, 0.1);
        }

        html.dark .liquid-glass-topbar {
            background: rgba(15, 23, 42, 0.65) !important;
            backdrop-filter: blur(28px) saturate(190%) !important;
            -webkit-backdrop-filter: blur(28px) saturate(190%) !important;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1) !important;
            box-shadow: 0 4px 16px 0 rgba(0, 0, 0, 0.3),
                inset 0 -1px 0 0 rgba(255, 255, 255, 0.05);
        }



        .liquid-glass-dropdown {
            background: rgba(248, 249, 250, 0.85) !important;
            backdrop-filter: blur(20px) saturate(180%) !important;
            -webkit-backdrop-filter: blur(20px) saturate(180%) !important;
            border: 1px solid rgba(255, 255, 255, 0.3) !important;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.3) !important;
        }

        html.dark .liquid-glass-dropdown {
            background: rgba(15, 23, 42, 0.8) !important;
            backdrop-filter: blur(24px) saturate(200%) !important;
            -webkit-backdrop-filter: blur(24px) saturate(200%) !important;
            border: 1px solid rgba(148, 163, 184, 0.15) !important;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.08) !important;
        }

        .liquid-glass-app-icon {
            background: rgba(255, 255, 255, 0.5);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.4);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        html.dark .liquid-glass-app-icon {
            background: rgba(30, 41, 59, 0.4);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(148, 163, 184, 0.15);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.05);
        }

        .liquid-glass-app-icon:hover {
            background: rgba(255, 255, 255, 0.7);
            transform: translateY(-4px) scale(1.05);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.2),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.5);
        }

        html.dark .liquid-glass-app-icon:hover {
            background: rgba(30, 41, 59, 0.6);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.08),
                0 0 30px -10px rgba(59, 130, 246, 0.5);
        }



        @keyframes shimmer {
            0% {
                left: -100%;
            }

            100% {
                left: 100%;
            }
        }

        body {
            background-color: var(--bg-color);
            color: var(--label-color);
            min-height: 100vh;
            min-height: 100dvh;
            /* En menú principal permitir gesto nativo (pull-to-refresh) */
            overscroll-behavior: auto;
            touch-action: pan-x pan-y;
        }

        /* Bloquear pull-to-refresh cuando el chat IA o una app iframe están abiertos */
        body.vcorta-open,
        body.app-open,
        body.mobile-app-open {
            overscroll-behavior-y: none;
        }

        html.block-pull-refresh,
        html.block-pull-refresh body {
            overscroll-behavior-y: none;
        }

        /* Video Wallpaper - Pixabay */
        #video-background {
            position: fixed;
            top: 50%;
            left: 50%;
            min-width: 100%;
            min-height: 100%;
            width: auto;
            height: auto;
            transform: translate(-50%, -50%);
            z-index: -2;
            object-fit: cover;
            opacity: 0;
            transition: opacity 1s ease-in-out;
        }
        
        #video-background.loaded {
            opacity: 1;
        }
        
        /* Overlay sobre el video */
        #video-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: -1;
            background: linear-gradient(135deg, 
                rgba(248, 250, 252, 0.85) 0%, 
                rgba(241, 245, 249, 0.75) 50%,
                rgba(248, 250, 252, 0.85) 100%);
            pointer-events: none;
        }
        
        html.dark #video-overlay {
            background: linear-gradient(135deg, 
                rgba(2, 6, 23, 0.85) 0%, 
                rgba(15, 23, 42, 0.75) 50%,
                rgba(2, 6, 23, 0.85) 100%);
        }

        /* TopBar */
        #topBar {
            display: none !important;
        }

        #logoContainer {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        #logoContainer img {
            height: 40px;
            object-fit: contain;
        }

        #logoText {
            font-size: 20px;
            font-weight: 600;
            color: var(--label-color);
        }

        #rightIcons {
            display: flex;
            gap: 16px;
            align-items: center;
        }

        #userMenu {
            position: relative;
            cursor: pointer;
        }

        #userMenu .dropdown-menu {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            border-radius: 12px;
            min-width: 240px;
            z-index: 200;
            padding: 8px;
        }

        #userMenu:hover .dropdown-menu {
            display: block;
        }

        #userMenu.open .dropdown-menu {
            display: block;
        }

        .dropdown-item {
            padding: 10px 12px;
            font-size: 14px;
            color: var(--label-color);
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            border-radius: 8px;
            transition: background-color 0.2s ease;
        }

        .dropdown-item:hover {
            background-color: rgba(59, 130, 246, 0.1);
        }

        .dropdown-divider {
            height: 1px;
            background-color: rgba(148, 163, 184, 0.2);
            margin: 8px 0;
        }

        .dropdown-item.logout {
            color: #ef4444;
        }

        .dropdown-item.logout:hover {
            background-color: rgba(239, 68, 68, 0.1);
        }

        /* Sucursal menu */
        #sucursalMenu {
            position: relative;
        }

        #sucursalMenu .dropdown-menu {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            border-radius: 12px;
            z-index: 200;
            padding: 8px;
        }

        #sucursalMenu.open .dropdown-menu {
            display: block !important;
        }

        .dropdown-item.active-sucursal {
            background: rgba(59, 130, 246, 0.12);
            font-weight: 600;
        }

        .user-name-text {
            max-width: 120px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .topbar-locale-select {
            height: 40px;
            min-width: 132px;
            padding: 0 36px 0 14px;
            border-radius: 10px;
            border: 1px solid rgba(148,163,184,0.25);
            background: rgba(255,255,255,0.35);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            color: var(--label-color);
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            outline: none;
            appearance: none;
            cursor: pointer;
        }

        .topbar-locale-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }

        [x-cloak] {
            display: none !important;
        }

        .topbar-locale-wrap i {
            position: absolute;
            right: 12px;
            pointer-events: none;
            opacity: .55;
            font-size: 10px;
        }

        @media (max-width: 600px) {
            .user-name-text { display: none; }
            #logoText { font-size: 15px !important; }
        }

        /* Watermark */
        #watermarkContainer {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            z-index: 95;
            pointer-events: none;
            user-select: none;
        }

        #watermarkLogo {
            width: 150px;
            height: 150px;
            opacity: 0.08;
        }

        #watermarkLogo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        #watermarkCompanyName {
            font-size: 24px;
            font-weight: 600;
            opacity: 0.1;
            letter-spacing: 2px;
        }

        #watermarkUserName {
            font-size: 16px;
            opacity: 0.08;
        }

        /* Logo SistemaX esquina inferior derecha */
        #sistemaxCornerLogo {
            position: fixed;
            right: calc(12px + var(--safe-right));
            bottom: calc(12px + var(--safe-bottom));
            width: 165px;
            height: auto;
            opacity: 0.95;
            z-index: 2;
            pointer-events: auto;
            cursor: pointer;
            user-select: none;
            filter: drop-shadow(0 0 16px rgba(59, 130, 246, 0.6))
                drop-shadow(0 0 32px rgba(37, 99, 235, 0.75));
        }

        #sistemaxCornerLogo:hover {
            transform: translateY(-6px) scale(1.05);
            filter:
                drop-shadow(0 0 20px rgba(59, 130, 246, 0.4))
                drop-shadow(0 0 40px rgba(37, 99, 235, 0.7))
                drop-shadow(0 20px 50px rgba(37, 99, 235, 0.35));
        }

        #sistemaxCornerLogo:active {
            transform: translateY(2px) scale(0.95);
        }

        #vcortaWidget {
            position: fixed;
            right: calc(12px + var(--safe-right));
            bottom: calc(92px + var(--safe-bottom));
            z-index: 12000;
            pointer-events: none;
            opacity: 0;
            transform: translateY(8px);
            transition: bottom 0.32s cubic-bezier(0.22, 1, 0.36, 1),
                        transform 0.36s cubic-bezier(0.2, 0.9, 0.2, 1),
                        opacity 0.28s ease-out;
            will-change: bottom, transform, opacity;
        }

        body.app-open #vcortaWidget,
        body.mobile-app-open #vcortaWidget {
            bottom: calc(12px + var(--safe-bottom));
            transform: translateY(0);
        }

        #vcortaPanel {
            width: min(370px, calc(100vw - 22px - var(--safe-left) - var(--safe-right)));
            height: min(560px, calc(100dvh - 110px - var(--safe-top) - var(--safe-bottom)));
            position: absolute;
            right: 0;
            bottom: 88px;
            border-radius: 24px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background:
                radial-gradient(circle at 12% 18%, rgba(59, 130, 246, 0.34), transparent 42%),
                radial-gradient(circle at 88% 88%, rgba(14, 165, 233, 0.24), transparent 46%),
                linear-gradient(155deg, rgba(10, 20, 45, 0.96), rgba(4, 10, 22, 0.96));
            box-shadow:
                0 24px 58px rgba(2, 6, 23, 0.52),
                0 10px 32px rgba(37, 99, 235, 0.28),
                inset 0 1px 0 rgba(255, 255, 255, 0.24);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            opacity: 0;
            pointer-events: none;
            transform-origin: right bottom;
            transform: translateY(28px) scale(0.965);
            transition: transform 0.34s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.26s ease-out;
        }

        #vcortaWidget.open {
            pointer-events: auto;
            opacity: 1;
            transform: translateY(0);
        }

        #vcortaWidget.open #vcortaPanel {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0) scale(1);
        }

        @media (prefers-reduced-motion: reduce) {
            #vcortaWidget,
            #vcortaPanel {
                transition: none !important;
            }
            #vcortaMessages {
                scroll-behavior: auto;
            }
        }

        .vcorta-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 14px 10px 14px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
            background: linear-gradient(90deg, rgba(30, 58, 138, 0.52), rgba(14, 116, 144, 0.34));
        }

        .vcorta-title {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #e2e8f0;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0.2px;
        }

        .vcorta-badge {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(148, 163, 184, 0.28);
            box-shadow: 0 0 14px rgba(56, 189, 248, 0.28);
            overflow: hidden;
        }

        .vcorta-badge img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .vcorta-close {
            width: 28px
            height: 28px
            border: 1px solid rgba(148, 163, 184, 0.3);
            border-radius: 9px
            background: rgba(15, 23, 42, 0.45);
            color: #e2e8f0;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .vcorta-close:hover {
            background: rgba(30, 41, 59, 0.8);
            border-color: rgba(148, 163, 184, 0.48);
        }
        #vcortaHealth {
            margin-top: 4px;
            font-size: 11px;
            font-weight: 600;
            color: #93c5fd;
            letter-spacing: 0.2px;
        }
        #vcortaHealth.ok {
            color: #86efac;
        }
        #vcortaHealth.warn {
            color: #fde68a;
        }
        #vcortaHealth.bad {
            color: #fca5a5;
        }

        #vcortaMessages {
            flex: 1;
            overflow-y: auto;
            scroll-behavior: smooth;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.5) transparent;
        }

        .vcorta-msg {
            max-width: 86%;
            border-radius: 14px;
            padding: 10px 12px;
            font-size: 13px;
            line-height: 1.42;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .vcorta-msg.user {
            align-self: flex-end;
            color: #fff;
            border-bottom-right-radius: 4px;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.92), rgba(14, 116, 144, 0.88));
            box-shadow: 0 8px 20px rgba(30, 64, 175, 0.35);
        }

        .vcorta-msg.assistant {
            align-self: flex-start;
            color: #e2e8f0;
            border-bottom-left-radius: 4px;
            background: rgba(15, 23, 42, 0.76);
            border: 1px solid rgba(148, 163, 184, 0.28);
            box-shadow: 0 8px 18px rgba(2, 6, 23, 0.24);
        }
        .vcorta-msg a {
            color: #8ed0ff;
            text-decoration: underline;
            font-weight: 600;
        }
        .vcorta-msg a:hover {
            color: #b6e3ff;
        }

        #vcortaTyping {
            display: none;
            align-self: flex-start;
            margin-left: 12px;
            margin-bottom: 8px;
            color: rgba(191, 219, 254, 0.9);
            font-size: 12px;
        }

        #vcortaTyping.visible {
            display: block;
        }

        .vcorta-input-wrap {
            padding: 12px;
            border-top: 1px solid rgba(148, 163, 184, 0.2);
            background: rgba(2, 6, 23, 0.32);
        }

        #vcortaSuggestions {
            padding: 10px 12px 4px 12px;
            display: block;
            max-height: 180px;
            overflow-y: auto;
            border-top: 1px solid rgba(148, 163, 184, 0.14);
            background: rgba(3, 10, 24, 0.24);
        }

        .vcorta-suggestion-btn {
            border: 1px solid rgba(96, 165, 250, 0.35);
            background: rgba(15, 23, 42, 0.62);
            color: #dbeafe;
            border-radius: 999px;
            font-size: 11px;
            line-height: 1.3;
            padding: 8px 10px;
            cursor: pointer;
            transition: all 0.18s ease;
            white-space: normal;
            text-align: left;
            width: 100%;
            border-radius: 9px
        }

        .vcorta-suggestion-btn:hover {
            background: rgba(30, 64, 175, 0.42);
            border-color: rgba(96, 165, 250, 0.65);
        }

        .vcorta-suggestion-group {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 8px;
        }

        .vcorta-suggestion-toggle {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            color: #bfdbfe;
            background: rgba(15, 23, 42, 0.48);
            border: 1px solid rgba(96, 165, 250, 0.32);
            border-radius: 9px
            padding: 8px 10px;
            cursor: pointer;
            transition: all 0.18s ease;
        }

        .vcorta-suggestion-toggle:hover {
            background: rgba(30, 64, 175, 0.3);
            border-color: rgba(96, 165, 250, 0.58);
        }

        .vcorta-suggestion-chevron {
            font-size: 12px;
            transition: transform 0.2s ease;
        }

        .vcorta-suggestion-group.open .vcorta-suggestion-chevron {
            transform: rotate(180deg);
        }

        .vcorta-suggestion-list {
            display: none;
            flex-direction: column;
            gap: 8px;
            padding-top: 2px;
        }

        .vcorta-suggestion-group.open .vcorta-suggestion-list {
            display: flex;
        }

        .vcorta-input-row {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        #vcortaKeyboard {
            display: none;
            padding: 8px 10px calc(8px + var(--safe-bottom));
            border-top: 1px solid rgba(148, 163, 184, 0.2);
            background: rgba(2, 6, 23, 0.5);
            gap: 6px;
            flex-direction: column;
        }

        .vcorta-kb-row {
            display: grid;
            grid-template-columns: repeat(9, minmax(0, 1fr));
            gap: 6px;
        }

        .vcorta-kb-key {
            border: 1px solid rgba(148, 163, 184, 0.28);
            background: rgba(15, 23, 42, 0.78);
            color: #dbeafe;
            border-radius: 8px;
            min-height: 38px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
            touch-action: manipulation;
        }

        .vcorta-kb-key:active {
            background: rgba(30, 64, 175, 0.55);
            transform: translateY(1px);
        }

        .vcorta-kb-key.wide-2 {
            grid-column: span 2;
        }

        .vcorta-kb-key.wide-3 {
            grid-column: span 3;
        }

        .vcorta-kb-key.wide-4 {
            grid-column: span 4;
        }

        .vcorta-kb-key.primary {
            background: linear-gradient(140deg, #2563eb, #0ea5e9);
            color: #fff;
            border-color: rgba(96, 165, 250, 0.7);
        }

        #vcortaInput {
            flex: 1;
            height: 42px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            background: rgba(15, 23, 42, 0.58);
            color: #e2e8f0;
            font-size: 13px;
            padding: 0 12px;
            outline: none;
        }

        #vcortaInput::placeholder {
            color: rgba(148, 163, 184, 0.9);
        }

        #vcortaInput:focus {
            border-color: rgba(59, 130, 246, 0.75);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }

        #vcortaSendBtn {
            width: 44px;
            height: 42px;
            border: none;
            border-radius: 12px;
            color: #fff;
            font-size: 16px;
            cursor: pointer;
            background: linear-gradient(140deg, #2563eb, #0ea5e9);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.35);
            transition: all 0.2s ease;
        }

        #vcortaSendBtn:hover {
            transform: translateY(-1px);
            filter: brightness(1.06);
        }

        #vcortaSendBtn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .vcorta-tool-btn {
            width: 42px;
            height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.34);
            background: rgba(15, 23, 42, 0.62);
            color: #dbeafe;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .vcorta-tool-btn:hover {
            background: rgba(30, 41, 59, 0.9);
            border-color: rgba(96, 165, 250, 0.6);
        }

        .vcorta-tool-btn.active {
            color: #fff;
            border-color: rgba(59, 130, 246, 0.85);
            background: linear-gradient(145deg, rgba(37, 99, 235, 0.95), rgba(14, 116, 144, 0.9));
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.24);
        }

        @media (max-width: 640px) {
            #sistemaxCornerLogo {
                display: none !important;
            }

            #vcortaWidget {
                left: 0;
                right: 0;
                bottom: 0;
                width: 100vw;
            }

            #vcortaPanel {
                width: 100vw;
                height: 100dvh;
                left: 0;
                right: 0;
                bottom: 0;
                border-radius: 0;
                transform-origin: center bottom;
                transform: translateY(24px) scale(0.99);
                overscroll-behavior: none;
                touch-action: none;
            }

            #vcortaWidget.open #vcortaPanel {
                transform: translateY(0) scale(1);
            }

            #vcortaInput {
                height: 38px;
                font-size: 14px;
                flex: 0 1 calc(100% - 132px);
                min-width: 120px;
            }

            #vcortaMicBtn {
                display: inline-flex;
                width: 38px;
                height: 38px;
            }

            #vcortaAudioBtn,
            #vcortaSendBtn {
                width: 38px;
                height: 38px;
            }

            #vcortaKeyboard {
                display: flex;
            }

            #vcortaMessages,
            #vcortaSuggestions {
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                overscroll-behavior: contain;
                touch-action: pan-y;
            }
        }

        /* HomeScreen */
        #homeScreen {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            justify-content: center;
            align-content: start;
            min-height: calc(100dvh - 90px - var(--safe-top) - var(--safe-bottom));
            padding: calc(20px + var(--safe-top)) 20px calc(100px + var(--safe-bottom)) 20px;
            padding-left: calc(20px + var(--safe-left));
            padding-right: calc(20px + var(--safe-right));
            box-sizing: border-box;
            position: relative;
            z-index: 2;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        #homeScreen::-webkit-scrollbar {
            display: none;
        }

        @media (max-width: 640px) {
            #homeScreen {
                width: 100%;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                column-gap: 6px;
                row-gap: 14px;
                padding-left: calc(12px + var(--safe-left));
                padding-right: calc(12px + var(--safe-right));
                padding-top: calc(20px + var(--safe-top));
                padding-bottom: calc(90px + var(--safe-bottom));
            }
        }

        html.is-mobile #homeScreen,
        body.is-mobile #homeScreen {
            width: 100%;
            height: calc(100dvh - 60px - var(--safe-top) - var(--safe-bottom));
            min-height: calc(100dvh - 60px - var(--safe-top) - var(--safe-bottom));
            grid-template-columns: repeat(4, minmax(0, 1fr));
            column-gap: 6px;
            row-gap: 14px;
            padding-top: calc(20px + var(--safe-top));
            padding-bottom: calc(90px + var(--safe-bottom));
            padding-left: calc(12px + var(--safe-left));
            padding-right: calc(12px + var(--safe-right));
            box-sizing: border-box;
            overflow-y: auto;
            overflow-x: hidden;
        }

        @media (max-width: 380px) {
            #homeScreen {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (min-width: 1024px) {
            #homeScreen {
                grid-template-columns: repeat(6, 1fr);
            }
        }

        .home-apps-pager {
            position: fixed;
            left: 50%;
            bottom: calc(88px + var(--safe-bottom));
            transform: translateX(-50%);
            z-index: 80;
            display: none;
            align-items: center;
            gap: 9px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.78);
            border: 1px solid rgba(148, 163, 184, 0.22);
            backdrop-filter: blur(18px) saturate(160%);
            -webkit-backdrop-filter: blur(18px) saturate(160%);
            box-shadow: 0 18px 36px rgba(2, 6, 23, 0.28),
                        inset 0 1px 0 rgba(255, 255, 255, 0.08);
            color: #e2e8f0;
        }

        .home-apps-pager .pager-dot {
            width: 10px;
            height: 10px;
            border: 0;
            padding: 0;
            border-radius: 999px;
            background: rgba(226, 232, 240, 0.34);
            cursor: pointer;
            transition: transform 0.18s ease, background-color 0.18s ease, box-shadow 0.18s ease, width 0.18s ease;
        }

        .home-apps-pager .pager-dot:hover {
            background: rgba(255, 255, 255, 0.58);
            transform: scale(1.08);
        }

        .home-apps-pager .pager-dot.active {
            width: 26px;
            background: linear-gradient(90deg, rgba(255,255,255,0.98), rgba(226,232,240,0.92));
            box-shadow: 0 0 0 1px rgba(255,255,255,0.12), 0 8px 18px rgba(255,255,255,0.18);
        }

        .home-apps-pager .pager-dot.active.pulse-next {
            animation: homePagerStretchNext 280ms cubic-bezier(0.22, 1, 0.36, 1);
        }

        .home-apps-pager .pager-dot.active.pulse-prev {
            animation: homePagerStretchPrev 280ms cubic-bezier(0.22, 1, 0.36, 1);
        }

        .home-apps-pager .pager-dot:focus-visible {
            outline: 2px solid rgba(255, 255, 255, 0.65);
            outline-offset: 2px;
        }

        .menu-spotlight-overlay {
            position: fixed;
            inset: 0;
            z-index: 1200;
            display: none;
            align-items: flex-start;
            justify-content: center;
            padding: clamp(24px, 8vh, 72px) 16px 24px;
            background:
                radial-gradient(circle at top, rgba(14,165,233,0.16), transparent 34%),
                rgba(2, 6, 23, 0.54);
            backdrop-filter: blur(16px) saturate(135%);
            -webkit-backdrop-filter: blur(16px) saturate(135%);
        }

        .menu-spotlight-overlay.visible {
            display: flex;
        }

        .menu-spotlight-panel {
            width: min(680px, 100%);
            border-radius: 24px;
            border: 1px solid rgba(148, 163, 184, 0.24);
            background: rgba(15, 23, 42, 0.82);
            box-shadow: 0 24px 80px rgba(2, 6, 23, 0.42);
            overflow: hidden;
        }

        .menu-spotlight-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 18px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.16);
        }

        .menu-spotlight-head i {
            color: #67e8f9;
            font-size: 18px;
        }

        .menu-spotlight-input {
            flex: 1;
            border: 0;
            outline: 0;
            background: transparent;
            color: #f8fafc;
            font-size: 18px;
            font-weight: 500;
            letter-spacing: 0.01em;
        }

        .menu-spotlight-input::placeholder {
            color: rgba(226, 232, 240, 0.48);
        }

        .menu-spotlight-hint {
            flex-shrink: 0;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.22);
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: rgba(226, 232, 240, 0.7);
        }

        .menu-spotlight-results {
            max-height: min(60vh, 520px);
            overflow-y: auto;
            padding: 10px;
        }

        .menu-spotlight-empty {
            padding: 28px 18px;
            text-align: center;
            color: rgba(226, 232, 240, 0.72);
            font-size: 14px;
        }

        .menu-spotlight-item {
            width: 100%;
            display: grid;
            grid-template-columns: 54px minmax(0, 1fr) auto;
            gap: 14px;
            align-items: center;
            border: 0;
            border-radius: 18px;
            padding: 12px 14px;
            background: transparent;
            color: #e2e8f0;
            text-align: left;
            cursor: pointer;
            transition: background-color .18s ease;
        }

        .menu-spotlight-item:hover,
        .menu-spotlight-item.active {
            background: rgba(14, 165, 233, 0.14);
        }

        .menu-spotlight-item-icon {
            width: 54px;
            height: 54px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 18px;
            color: #fff;
            background: linear-gradient(135deg, rgba(59,130,246,0.86), rgba(6,182,212,0.78));
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.2), 0 10px 24px rgba(2, 6, 23, 0.28);
        }

        .menu-spotlight-item-icon svg {
            width: 28px;
            height: 28px;
        }

        .menu-spotlight-item-label,
        .menu-spotlight-item-meta {
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .menu-spotlight-item-label {
            font-size: 15px;
            font-weight: 700;
            color: #f8fafc;
        }

        .menu-spotlight-item-meta {
            margin-top: 4px;
            font-size: 12px;
            color: rgba(148, 163, 184, 0.9);
        }

        .menu-spotlight-item-shortcut {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: rgba(226, 232, 240, 0.62);
        }

        @media (max-width: 640px) {
            .menu-spotlight-overlay {
                padding: calc(14px + var(--safe-top)) 10px calc(16px + var(--safe-bottom));
            }

            .menu-spotlight-panel {
                border-radius: 20px;
            }

            .menu-spotlight-head {
                padding: 14px 14px;
            }

            .menu-spotlight-input {
                font-size: 16px;
            }

            .menu-spotlight-item {
                grid-template-columns: 46px minmax(0, 1fr);
                padding: 10px 12px;
            }

            .menu-spotlight-item-icon {
                width: 46px;
                height: 46px;
                border-radius: 15px;
            }

            .menu-spotlight-item-icon svg {
                width: 24px;
                height: 24px;
            }

            .menu-spotlight-item-shortcut,
            .menu-spotlight-hint {
                display: none;
            }
        }

        #homeScreen.pager-draggable {
            cursor: grab;
            user-select: none;
            -webkit-user-select: none;
            touch-action: pan-y;
            will-change: transform;
            transition: transform 220ms cubic-bezier(0.22, 1, 0.36, 1);
        }

        #homeScreen.pager-draggable.dragging {
            cursor: grabbing;
            transition: none;
        }

        .app-icon.reorder-draggable {
            touch-action: none;
        }

        .app-icon.reorder-dragging {
            opacity: 0.34 !important;
            transform: scale(0.94);
            filter: saturate(0.8);
        }

        .app-icon.reorder-drop-before,
        .app-icon.reorder-drop-after {
            position: relative;
        }

        .app-icon.reorder-drop-before::before,
        .app-icon.reorder-drop-after::after {
            content: '';
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            width: 68px;
            height: 4px;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(59,130,246,0.2), rgba(96,165,250,0.95), rgba(59,130,246,0.2));
            box-shadow: 0 0 0 1px rgba(255,255,255,0.1), 0 10px 24px rgba(37,99,235,0.24);
            z-index: 10;
        }

        .app-icon.reorder-drop-before::before {
            top: -12px;
        }

        .app-icon.reorder-drop-after::after {
            bottom: -12px;
        }

        @keyframes homeAppsSlideInNext {
            0% {
                opacity: 0;
                transform: translateX(18px) scale(0.97);
            }
            100% {
                opacity: 1;
                transform: translateX(0) scale(1);
            }
        }

        @keyframes homeAppsSlideInPrev {
            0% {
                opacity: 0;
                transform: translateX(-18px) scale(0.97);
            }
            100% {
                opacity: 1;
                transform: translateX(0) scale(1);
            }
        }

        @keyframes homePagerStretchNext {
            0% {
                transform: scaleX(1) scaleY(1);
            }
            45% {
                transform: scaleX(1.35) scaleY(1.08);
            }
            100% {
                transform: scaleX(1) scaleY(1);
            }
        }

        @keyframes homePagerStretchPrev {
            0% {
                transform: scaleX(1) scaleY(1);
            }
            45% {
                transform: scaleX(1.35) scaleY(1.08);
            }
            100% {
                transform: scaleX(1) scaleY(1);
            }
        }

        body.app-open .home-apps-pager,
        body.mobile-app-open .home-apps-pager {
            display: none !important;
        }

        /* ==========================================
           iOS Liquid Glass App Icons
           ========================================== */
        .app-icon {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            opacity: 0;
            animation: fadeInScale 0.4s ease forwards;
            --glow-color: rgba(59, 130, 246, 0.4);
            --glow-color-strong: rgba(37, 99, 235, 0.7);
            --icon-bg-start: rgba(99, 155, 255, 0.6);
            --icon-bg-mid: rgba(59, 130, 246, 0.5);
            --icon-bg-end: rgba(37, 99, 235, 0.6);
            --icon-shadow-color: rgba(37, 99, 235, 0.5);
        }

        /* icon-back usa display:contents — es transparente para el layout */
        .app-icon .icon-back {
            display: contents;
        }

        .app-icon:nth-child(1) {
            animation-delay: 0.05s;
        }

        .app-icon:nth-child(2) {
            animation-delay: 0.1s;
        }

        .app-icon:nth-child(3) {
            animation-delay: 0.15s;
        }

        .app-icon:nth-child(4) {
            animation-delay: 0.2s;
        }

        .app-icon:nth-child(5) {
            animation-delay: 0.25s;
        }

        .app-icon:nth-child(6) {
            animation-delay: 0.3s;
        }

        .app-icon:nth-child(7) {
            animation-delay: 0.35s;
        }

        .app-icon:nth-child(8) {
            animation-delay: 0.4s;
        }

        .app-icon:nth-child(9) {
            animation-delay: 0.45s;
        }

        .app-icon:nth-child(10) {
            animation-delay: 0.5s;
        }

        .app-icon:nth-child(11) {
            animation-delay: 0.55s;
        }

        .app-icon:nth-child(12) {
            animation-delay: 0.6s;
        }

        /* ==========================================
           Botones 3D Liquid Glass
           ========================================== */
        .app-icon .icon-back i {
            width: 112px;
            height: 112px;
            font-size: 48px;
            color: white;
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            
            /* Liquid Glass Effect */
            background: linear-gradient(
                145deg, 
                rgba(99, 155, 255, 0.85) 0%, 
                rgba(59, 130, 246, 0.75) 50%, 
                rgba(37, 99, 235, 0.85) 100%
            );
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.25);
            
            /* Efecto 3D con sombras */
            box-shadow: 
                /* Sombra exterior difusa */
                0 10px 40px -5px rgba(37, 99, 235, 0.4),
                0 20px 50px -10px rgba(30, 64, 175, 0.25),
                /* Borde inferior 3D sutil */
                0 4px 0 0 rgba(29, 78, 216, 0.6),
                /* Brillo interno superior (liquid) */
                inset 0 2px 10px rgba(255, 255, 255, 0.35),
                /* Sombra interna inferior */
                inset 0 -4px 12px rgba(30, 64, 175, 0.15),
                /* Borde luminoso */
                0 0 0 1px rgba(255, 255, 255, 0.1);
            
            /* Transiciones suaves */
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            transform: translateY(0);
            transform-style: preserve-3d;
        }

        /* Brillo superior liquid glass */
        .app-icon .icon-back i::before {
            content: '';
            position: absolute;
            top: 0;
            left: 5%;
            right: 5%;
            height: 45%;
            background: linear-gradient(180deg, 
                rgba(255, 255, 255, 0.4) 0%,
                rgba(255, 255, 255, 0.15) 40%,
                rgba(255, 255, 255, 0) 100%);
            border-radius: 24px 24px 50% 50%;
            pointer-events: none;
        }

        /* Reflejo animado liquid */
        .app-icon .icon-back i::after {
            content: '';
            position: absolute;
            top: -100%;
            left: -100%;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                135deg,
                transparent 35%,
                rgba(255, 255, 255, 0.3) 50%,
                transparent 65%
            );
            transition: all 0.7s ease;
            pointer-events: none;
        }

        .app-icon:hover .icon-back i::after {
            top: 100%;
            left: 100%;
        }

        /* ==========================================
           Iconos SVG de Vidrio
           ========================================== */
        .app-icon .icon-back .glass-icon-svg {
            width: 112px;
            height: 112px;
            border-radius: 28px;
            object-fit: contain;
            display: block;
            filter: drop-shadow(0 6px 16px rgba(0, 0, 0, 0.2));
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            transform: translateY(0);
        }

        /* ==========================================
           Heroicons SVG Inline - iOS Liquid Glass Style
           ========================================== */
        /* Icono macOS directo — sin contenedor liquid glass */
        .app-icon .icon-back > svg,
        .app-icon .icon-back > img,
        .app-icon > svg,
        .app-icon > img {
            width: 112px;
            height: 112px;
            padding: 0;
            border-radius: 24px;
            border: none;
            background: none;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
            box-shadow:
                0 8px 24px -4px rgba(0, 0, 0, 0.35),
                0 16px 40px -8px rgba(0, 0, 0, 0.18);
            display: block;
            overflow: hidden;
            object-fit: cover;
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            transform: translateY(0) scale(1);
        }

        .app-icon .icon-back > svg::before,
        .app-icon > svg::before { content: none; }

        /* Hover - elevación macOS */
        .app-icon:hover .icon-back > svg,
        .app-icon:hover .icon-back > img,
        .app-icon:hover > svg,
        .app-icon:hover > img {
            transform: translateY(-7px) scale(1.06);
            box-shadow:
                0 16px 40px -6px rgba(0, 0, 0, 0.4),
                0 28px 56px -12px rgba(0, 0, 0, 0.22);
        }

        /* Active/Click */
        .app-icon:active .icon-back > svg,
        .app-icon:active .icon-back > img,
        .app-icon:active > svg,
        .app-icon:active > img {
            transform: translateY(2px) scale(0.95);
            box-shadow:
                0 4px 12px -2px rgba(0, 0, 0, 0.3),
                0 8px 20px -4px rgba(0, 0, 0, 0.15);
            transition: all 0.1s ease;
        }

        /* ==========================================
           Variantes de color - iOS Liquid Glass
           ========================================== */
        .app-icon[data-color="green"] {
            --icon-bg-start: rgba(52, 211, 153, 0.55);
            --icon-bg-mid: rgba(16, 185, 129, 0.45);
            --icon-bg-end: rgba(5, 150, 105, 0.55);
            --icon-shadow-color: rgba(5, 150, 105, 0.4);
            --glow-color: rgba(16, 185, 129, 0.4);
            --glow-color-strong: rgba(5, 150, 105, 0.7);
        }

        .app-icon[data-color="orange"] {
            --icon-bg-start: rgba(251, 191, 36, 0.55);
            --icon-bg-mid: rgba(245, 158, 11, 0.45);
            --icon-bg-end: rgba(217, 119, 6, 0.55);
            --icon-shadow-color: rgba(217, 119, 6, 0.4);
            --glow-color: rgba(245, 158, 11, 0.4);
            --glow-color-strong: rgba(217, 119, 6, 0.7);
        }

        .app-icon[data-color="purple"] {
            --icon-bg-start: rgba(167, 139, 250, 0.55);
            --icon-bg-mid: rgba(139, 92, 246, 0.45);
            --icon-bg-end: rgba(124, 58, 237, 0.55);
            --icon-shadow-color: rgba(124, 58, 237, 0.4);
            --glow-color: rgba(139, 92, 246, 0.4);
            --glow-color-strong: rgba(124, 58, 237, 0.7);
        }

        .app-icon[data-color="red"] {
            --icon-bg-start: rgba(252, 165, 165, 0.55);
            --icon-bg-mid: rgba(239, 68, 68, 0.45);
            --icon-bg-end: rgba(220, 38, 38, 0.55);
            --icon-shadow-color: rgba(220, 38, 38, 0.4);
            --glow-color: rgba(239, 68, 68, 0.4);
            --glow-color-strong: rgba(220, 38, 38, 0.7);
        }

        .app-icon[data-color="cyan"] {
            --icon-bg-start: rgba(103, 232, 249, 0.55);
            --icon-bg-mid: rgba(34, 211, 238, 0.45);
            --icon-bg-end: rgba(6, 182, 212, 0.55);
            --icon-shadow-color: rgba(6, 182, 212, 0.4);
            --glow-color: rgba(34, 211, 238, 0.4);
            --glow-color-strong: rgba(6, 182, 212, 0.7);
        }

        .app-icon[data-color="pink"] {
            --icon-bg-start: rgba(249, 168, 212, 0.55);
            --icon-bg-mid: rgba(236, 72, 153, 0.45);
            --icon-bg-end: rgba(219, 39, 119, 0.55);
            --icon-shadow-color: rgba(219, 39, 119, 0.4);
            --glow-color: rgba(236, 72, 153, 0.4);
            --glow-color-strong: rgba(219, 39, 119, 0.7);
        }

        /* Responsive para iconos SVG/IMG */
        @media (max-width: 768px) {
            .app-icon .icon-back > svg,
            .app-icon .icon-back > img,
            .app-icon > svg,
            .app-icon > img,
            .app-icon .icon-back .glass-icon-svg {
                width: 96px;
                height: 96px;
                padding: 0;
                border-radius: 21px;
            }
        }

        @media (max-width: 480px) {
            .app-icon .icon-back > svg,
            .app-icon .icon-back > img,
            .app-icon > svg,
            .app-icon > img,
            .app-icon .icon-back .glass-icon-svg {
                width: 80px;
                height: 80px;
                padding: 0;
                border-radius: 18px;
            }
        }

        .app-icon:hover .icon-back .glass-icon-svg,
        .app-icon:hover > svg {
            filter: drop-shadow(0 12px 28px rgba(0,0,0,0.35));
        }

        .app-icon:active .icon-back .glass-icon-svg {
            transform: translateY(3px) scale(0.98);
            filter: drop-shadow(0 3px 8px rgba(0, 0, 0, 0.25));
        }

        /* Iconos en móviles */
        @media (max-width: 640px) {
            .app-icon {
                width: 100%;
                min-width: 0;
                justify-self: center;
            }

            .app-icon .icon-back > svg,
            .app-icon .icon-back > img,
            .app-icon > svg,
            .app-icon > img {
                width: min(100%, 60px);
                height: min(100%, 60px);
                padding: 0;
                border-radius: 14px;
            }

            .app-icon .icon-back .glass-icon-svg {
                width: min(100%, 60px);
                height: min(100%, 60px);
                border-radius: 14px;
            }

            .app-icon:hover .icon-back > svg,
            .app-icon:hover .icon-back > img,
            .app-icon:hover > svg,
            .app-icon:hover > img {
                transform: translateY(-4px) scale(1.03);
            }

            .app-icon {
                gap: 6px;
            }

            .app-icon span {
                font-size: 12px;
                width: 100%;
                max-width: 82px;
                white-space: normal;
                line-height: 1.22;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
                text-overflow: ellipsis;
                margin: 0 auto;
            }
        }

        html.is-mobile .app-icon,
        body.is-mobile .app-icon {
            width: 100%;
            min-width: 0;
            gap: 6px;
            justify-self: center;
        }

        html.is-mobile .app-icon .icon-back > svg,
        html.is-mobile .app-icon .icon-back > img,
        body.is-mobile .app-icon .icon-back > svg,
        body.is-mobile .app-icon .icon-back > img,
        html.is-mobile .app-icon > svg,
        html.is-mobile .app-icon > img,
        body.is-mobile .app-icon > svg,
        body.is-mobile .app-icon > img,
        html.is-mobile .app-icon .icon-back .glass-icon-svg,
        body.is-mobile .app-icon .icon-back .glass-icon-svg {
            width: 68px !important;
            height: 68px !important;
            max-width: 68px !important;
            max-height: 68px !important;
            padding: 0 !important;
            border-radius: 15px !important;
        }

        html.is-mobile .app-icon span,
        body.is-mobile .app-icon span {
            width: 82px !important;
            max-width: 82px !important;
            min-width: 82px !important;
            margin: 0 auto !important;
            font-size: 12px !important;
            line-height: 1.2 !important;
            min-height: 2.4em !important;
            white-space: normal !important;
            text-wrap: unset !important;
            display: -webkit-box !important;
            -webkit-line-clamp: 2 !important;
            -webkit-box-orient: vertical !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }

        .app-icon span {
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            color: var(--label-color);
            width: 100%;
            max-width: 96px;
            min-height: 2.2em;
            line-height: 1.16;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: normal;
            text-wrap: pretty;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .app-icon.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
        }



        /* ===== MOBILE APP HEADER - Oculto por defecto en desktop ===== */
        #mobileAppHeader {
            display: none;
        }

        /* MOBILE APP EXPERIENCE - iframe embebido */
        @media (max-width: 768px) {
            /* Ajustar homeScreen */
            #homeScreen {
                height: calc(100dvh - 60px - var(--safe-top) - var(--safe-bottom)) !important;
                padding-bottom: calc(20px + var(--safe-bottom)) !important;
                min-height: calc(100dvh - 60px - var(--safe-top) - var(--safe-bottom)) !important;
            }

            /* ===== IFRAME VISIBLE EN MÓVIL ===== */
            #appFrame {
                display: none; /* Se muestra con JS al abrir app */
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: calc(84px + var(--safe-bottom, 0px)) !important;
                width: 100% !important;
                height: calc(100dvh - 84px - var(--safe-bottom, 0px)) !important;
                z-index: 9000 !important;
                border: none !important;
                background: var(--bg-body);
            }
            
            #appFrame.visible {
                display: block !important;
            }

            /* ===== HEADER DE APP MÓVIL (deprecado: reemplazado por mini dock) ===== */
            #mobileAppHeader {
                display: none !important;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                height: calc(56px + var(--safe-top));
                padding-top: var(--safe-top);
                background: rgba(255, 255, 255, 0.9);
                backdrop-filter: blur(20px) saturate(180%);
                -webkit-backdrop-filter: blur(20px) saturate(180%);
                border-bottom: 1px solid rgba(0, 0, 0, 0.1);
                z-index: 9500;
                align-items: center;
                justify-content: space-between;
                padding-left: 8px;
                padding-right: 16px;
                transform: translateY(-100%);
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }
            
            .dark #mobileAppHeader {
                background: rgba(30, 30, 30, 0.95);
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }
            
            #mobileAppHeader.visible {
                transform: translateY(0);
            }
            
            #mobileAppHeader .back-btn {
                display: flex;
                align-items: center;
                gap: 4px;
                padding: 10px 12px;
                color: #007AFF;
                font-size: 17px;
                font-weight: 400;
                background: none;
                border: none;
                cursor: pointer;
                -webkit-tap-highlight-color: transparent;
            }
            
            .dark #mobileAppHeader .back-btn {
                color: #0A84FF;
            }
            
            #mobileAppHeader .back-btn:active {
                opacity: 0.5;
            }
            
            #mobileAppHeader .back-btn i {
                font-size: 20px;
            }
            
            #mobileAppHeader .back-btn svg {
                width: 20px;
                height: 20px;
                stroke-width: 2.5;
            }
            
            #mobileAppHeader .app-title {
                position: absolute;
                left: 50%;
                transform: translateX(-50%);
                font-size: 17px;
                font-weight: 600;
                color: var(--text-color);
                max-width: 60%;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            /* Ajustar iframe cuando mini dock está visible en footer */
            body.mobile-app-open #appFrame {
                top: 0 !important;
                height: calc(100dvh - 70px - var(--safe-bottom)) !important;
            }

            #openAppsDock.mobile-mini {
                top: auto;
                bottom: calc(6px + var(--safe-bottom));
                left: 50%;
                transform: translateX(-50%) translateY(120%);
                min-height: 50px;
                max-width: 96vw;
                padding: 4px 6px 4px;
                gap: 4px;
                border-radius: 16px;
                background: linear-gradient(180deg, rgba(15, 23, 42, 0.78), rgba(15, 23, 42, 0.68));
                border: 1px solid rgba(148, 163, 184, 0.28);
                box-shadow: 0 10px 24px rgba(2, 6, 23, 0.36);
                z-index: 9500;
            }

            #openAppsDock.mobile-mini.visible {
                transform: translateX(-50%) translateY(0);
            }

            #openAppsDock.mobile-mini .dock-app-item {
                width: 48px;
                height: 46px;
                gap: 2px;
                padding: 1px 2px 2px;
            }

            #openAppsDock.mobile-mini .dock-app-icon {
                width: 28px;
                height: 28px;
                border-radius: 9px;
            }

            #openAppsDock.mobile-mini .dock-app-icon svg {
                width: 17px;
                height: 17px;
            }
            #openAppsDock.mobile-mini .dock-app-icon img {
                width: 17px;
                height: 17px;
                object-fit: contain;
            }
            #openAppsDock.mobile-mini .dock-home .dock-app-icon img,
            #openAppsDock.mobile-mini .dock-logout .dock-app-icon img {
                width: 28px;
                height: 28px;
                border-radius: 9px;
                object-fit: cover;
            }

            #openAppsDock.mobile-mini .dock-app-label-tip {
                max-width: 46px;
                font-size: 6px;
                line-height: 1.25;
                white-space: normal;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
                text-overflow: ellipsis;
                padding: 0 1px 1px
            }
            
            /* ===== INDICADOR DE CARGA MÓVIL ===== */
            #mobileLoadingIndicator {
                position: fixed;
                top: calc(8px + var(--safe-top));
                left: 50%;
                transform: translateX(-50%);
                z-index: 9600;
                display: none;
            }
            
            #mobileLoadingIndicator.visible {
                display: block;
            }
            
            #mobileLoadingIndicator .loading-bar {
                width: 120px;
                height: 3px;
                background: rgba(0, 122, 255, 0.2);
                border-radius: 2px;
                overflow: hidden;
            }
            
            #mobileLoadingIndicator .loading-progress {
                width: 30%;
                height: 100%;
                background: linear-gradient(90deg, #007AFF, #5AC8FA);
                border-radius: 2px;
                animation: loadingSlide 1s ease-in-out infinite;
            }
            
            @keyframes loadingSlide {
                0% { transform: translateX(-100%); }
                50% { transform: translateX(200%); }
                100% { transform: translateX(-100%); }
            }
            
        }



        /* Iframe - Fullscreen cuando hay app abierta */
        #appFrame {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: calc(84px + var(--safe-bottom, 0px));
            width: 100%;
            height: calc(100dvh - 84px - var(--safe-bottom, 0px));
            border: none;
            display: none;
            background: #020617;
            z-index: 9999;
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.4s ease, transform 0.4s ease;
            overscroll-behavior-y: none;
            touch-action: pan-y;
        }
        
        #appFrame.visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* Desktop: contenedor de iframes múltiples y dock de apps abiertas */
        #desktopFramesContainer {
            position: fixed;
            inset: 0;
            display: none;
            z-index: 9998;
        }

        #desktopFramesContainer.visible {
            display: block;
        }

        #desktopLoadingOverlay {
            position: fixed;
            inset: 0;
            z-index: 10005;
            display: none;
            align-items: center;
            justify-content: center;
            background: #020617;
        }

        #desktopLoadingOverlay.visible {
            display: flex;
        }

        .desktop-loading-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 14px;
            padding: 26px 30px;
            border-radius: 22px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.96), rgba(2, 6, 23, 0.98));
            color: #e2e8f0;
            box-shadow: 0 18px 60px rgba(0, 0, 0, 0.35);
        }

        .desktop-loading-spinner {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            border: 2px solid rgba(103, 232, 249, 0.22);
            border-top-color: #67e8f9;
            animation: desktopLoadingSpin .75s linear infinite;
        }

        .desktop-loading-title {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .24em;
            text-transform: uppercase;
            color: #67e8f9;
        }

        .desktop-loading-text {
            font-size: 13px;
            color: #cbd5e1;
            text-align: center;
        }

        @keyframes desktopLoadingSpin {
            to { transform: rotate(360deg); }
        }

        .desktop-app-frame {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: calc(84px + var(--safe-bottom, 0px));
            width: 100%;
            height: calc(100dvh - 84px - var(--safe-bottom, 0px));
            border: 0;
            background: #020617;
            display: none;
        }

        .desktop-app-frame.active {
            display: block;
        }

        #dockRevealHandle {
            position: fixed;
            left: 50%;
            bottom: calc(4px + var(--safe-bottom));
            transform: translateX(-50%);
            width: 140px;
            height: 12px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.25);
            border: 1px solid rgba(148, 163, 184, 0.35);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 10020;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }

        #dockRevealHandle.visible {
            opacity: 1;
            pointer-events: auto;
        }

        #openAppsDock {
            position: fixed;
            left: 50%;
            bottom: calc(12px + var(--safe-bottom));
            transform: translateX(-50%) translateY(120%);
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: min(94vw, 1120px);
            min-height: 68px;
            padding: 7px 12px 8px;
            border-radius: 22px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.28), rgba(255, 255, 255, 0.14));
            border: 1px solid rgba(255, 255, 255, 0.32);
            backdrop-filter: blur(26px) saturate(165%);
            -webkit-backdrop-filter: blur(26px) saturate(165%);
            box-shadow: 0 18px 45px rgba(2, 6, 23, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.28);
            z-index: 10030;
            opacity: 0;
            pointer-events: none;
            transition: transform 0.25s ease, opacity 0.25s ease;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        #openAppsDock::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }

        #openAppsDock.visible {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
            pointer-events: auto;
        }

        .dock-app-item {
            flex: 0 0 auto;
            width: 76px;
            height: 78px;
            border: none;
            background: transparent;
            padding: 1px 2px 2px
            position: relative;
            cursor: pointer;
            transition: filter 0.16s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            gap: 4px;
        }

        .dock-app-item:hover .dock-app-icon {
            filter: brightness(1.06);
        }

        .dock-app-icon {
            width: 58px;
            height: 58px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            background: linear-gradient(145deg, rgba(37, 99, 235, 0.95), rgba(14, 116, 144, 0.88));
            border: 1px solid rgba(255, 255, 255, 0.38);
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.26);
        }

        .dock-app-icon svg {
            width: 30px;
            height: 30px;
            stroke-width: 1.85;
        }
        .dock-app-icon img {
            width: 30px;
            height: 30px;
            object-fit: contain;
            display: block;
        }

        .dock-app-item.active .dock-app-icon {
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.55), 0 0 0 2px rgba(96, 165, 250, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.32);
        }

        .dock-app-label-tip {
            position: static;
            transform: none;
            padding: 2px 3px 3px;
            border-radius: 0;
            font-size: 9px;
            font-weight: 600;
            color: #e2e8f0;
            background: transparent;
            border: none;
            box-shadow: none;
            white-space: nowrap;
            max-width: 74px;
            overflow: hidden;
            text-overflow: ellipsis;
            text-align: center;
            line-height: 1.1;
        }

        .dock-home .dock-app-icon,
        .dock-logout .dock-app-icon {
            background: transparent;
            border: none;
            box-shadow: none;
            padding: 0;
            overflow: hidden;
        }

        .dock-home .dock-app-icon img,
        .dock-logout .dock-app-icon img {
            width: 58px;
            height: 58px;
            border-radius: 16px;
            object-fit: cover;
            display: block;
        }

        .dock-logout:hover .dock-app-icon {
            filter: brightness(1.05) saturate(1.04);
        }

        .dock-app-item.pinned-only .dock-app-icon {
            background: linear-gradient(145deg, rgba(71, 85, 105, 0.95), rgba(30, 41, 59, 0.88));
            border-color: rgba(203, 213, 225, 0.38);
        }

        #dockContextMenu {
            position: fixed;
            min-width: 170px;
            background: rgba(15, 23, 42, 0.92);
            border: 1px solid rgba(148, 163, 184, 0.35);
            border-radius: 9px
            box-shadow: 0 16px 34px rgba(2, 6, 23, 0.45);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            padding: 6px;
            z-index: 10100;
            display: none;
        }

        #dockContextMenu.visible {
            display: block;
        }

        .dock-context-item {
            width: 100%;
            border: 0;
            background: transparent;
            color: #e2e8f0;
            font-size: 13px;
            text-align: left;
            padding: 8px 10px;
            border-radius: 8px;
            cursor: pointer;
        }

        .dock-context-item:hover {
            background: rgba(30, 64, 175, 0.38);
        }

        .dock-context-item.danger:hover {
            background: rgba(185, 28, 28, 0.38);
        }

        .dock-context-item:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        @media (max-width: 768px) {
            #desktopFramesContainer,
            #dockRevealHandle {
                display: none !important;
            }
        }

        html.is-mobile #appFrame,
        body.is-mobile #appFrame {
            width: 100% !important;
            max-width: 100% !important;
        }

        /* Desktop: aumentar 25% botones del menú principal */
        @media (min-width: 769px) {
            .app-icon .icon-back > svg,
            .app-icon .icon-back > img,
            .app-icon > svg,
            .app-icon > img {
                width: 105px;
                height: 105px;
                padding: 0;
                border-radius: 23px;
            }

            .app-icon span {
                font-size: 13px;
                width: 112px;
                max-width: 112px;
            }

            #openAppsDock {
                min-height: 56px;
                padding: 5px 8px 6px;
                border-radius: 16px;
                gap: 8px;
            }

            #appFrame,
            .desktop-app-frame {
                bottom: calc(72px + var(--safe-bottom, 0px));
                height: calc(100dvh - 72px - var(--safe-bottom, 0px));
            }

            .dock-app-item {
                width: 57px;
                height: 52px;
                gap: 2px;
            }

            .dock-app-icon {
                width: 44px;
                height: 44px;
                border-radius: 12px;
            }

            .dock-app-icon svg {
                width: 22px;
                height: 22px;
            }
            .dock-app-icon img {
                width: 22px;
                height: 22px;
                object-fit: contain;
            }
            .dock-home .dock-app-icon img,
            .dock-logout .dock-app-icon img {
                width: 44px;
                height: 44px;
                border-radius: 12px;
                object-fit: cover;
            }

            .dock-app-label-tip {
                max-width: 56px;
                font-size: 7px;
            }
        }


        
        /* Animación de salida del menú principal */
        #topBar {
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
        }
        
        #topBar.hidden-bar {
            transform: translateY(-100%);
            opacity: 0;
        }
        
        #homeScreen {
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
        }
        
        #homeScreen.hidden-screen {
            transform: translateY(50px);
            opacity: 0;
            pointer-events: none;
        }
        
        #watermarkContainer {
            transition: opacity 0.3s ease;
        }
        
        #watermarkContainer.hidden-watermark {
            opacity: 0 !important;
        }
        
        #sistemaxCornerLogo {
            transition: opacity 0.3s ease, filter 0.3s ease, transform 0.3s ease;
        }
        
        #sistemaxCornerLogo.hidden-logo {
            opacity: 0 !important;
        }

        #sistemaxCornerLogo.hidden-logo + #vcortaWidget:not(.open) {
            opacity: 0 !important;
            pointer-events: none !important;
        }
        #sistemaxCornerLogo.hidden-logo + #vcortaWidget.open {
            opacity: 1 !important;
            pointer-events: auto !important;
        }

        @media (max-width: 768px) {
            #sistemaxCornerLogo {
                display: none !important;
            }
        }

        body.is-mobile #sistemaxCornerLogo {
            display: none !important;
        }

        /* En móviles, el iframe respeta el espacio del dock */
        @media (max-width: 768px) {
            #appFrame {
                top: 0;
                bottom: calc(84px + var(--safe-bottom, 0px));
                height: calc(100dvh - 84px - var(--safe-bottom, 0px));
            }
        }

        @keyframes fadeInScale {
            from {
                opacity: 0;
                transform: scale(0.85);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* Transiciones suaves tipo app nativa */
        .app-transition {
            transition: opacity 0.25s ease, transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Efecto de presión táctil global */
        .touchable:active {
            opacity: 0.7;
        }

        /* Ocultar scrollbars globalmente */
        ::-webkit-scrollbar {
            display: none;
        }

        * {
            scrollbar-width: none;
        }

        /* Estado de carga tipo app */
        .loading-shimmer {
            background: linear-gradient(90deg,
                    rgba(148, 163, 184, 0.1) 0%,
                    rgba(148, 163, 184, 0.2) 50%,
                    rgba(148, 163, 184, 0.1) 100%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
        }

        @keyframes shimmer {
            0% {
                background-position: 200% 0;
            }

            100% {
                background-position: -200% 0;
            }
        }

        /* Vibración háptica visual */
        @keyframes haptic {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(0.96);
            }
        }

        .haptic-feedback {
            animation: haptic 0.1s ease;
        }

        /* Wallpaper dinámico - deshabilitado, usando video */
        body {
            background: transparent;
        }

        /* Sistema de Notificaciones Moderno */
        .notification-container {
            position: fixed;
            top: calc(52px + var(--safe-top));
            right: 14px;
            z-index: 21010;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 12px;
            pointer-events: none;
            width: min(420px, calc(100vw - 28px));
        }

        @media (max-width: 640px) {
            .notification-container {
                left: auto;
                right: 10px;
                top: calc(48px + var(--safe-top));
                width: min(360px, calc(100vw - 20px));
            }
        }

        .notification {
            pointer-events: auto;
            width: 100%;
            min-width: 0;
            max-width: 420px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.3);
            padding: 16px;
            animation: slideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        html.dark .notification {
            background: rgba(15, 23, 42, 0.95);
            border: 1px solid rgba(148, 163, 184, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.05);
        }

        @media (max-width: 640px) {
            .notification {
                min-width: auto;
                max-width: none;
            }
        }

        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(400px);
                opacity: 0;
            }
        }

        .notification.closing {
            animation: slideOut 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Modal de confirmación */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 10000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: fadeIn 0.2s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .modal-content {
            background: rgba(15, 23, 42, 0.98);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.08);
            max-width: 400px;
            width: 100%;
            animation: modalSlide 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            color: white;
        }
        
        .modal-content h3 { color: white !important; }
        .modal-content p { color: rgba(255, 255, 255, 0.7) !important; }
        .modal-content button:first-of-type {
            background: rgba(255, 255, 255, 0.1) !important;
            border: 1px solid rgba(255, 255, 255, 0.2) !important;
            color: white !important;
        }
        .modal-content button:first-of-type:hover {
            background: rgba(255, 255, 255, 0.2) !important;
        }
        .modal-content button:last-of-type {
            background: linear-gradient(to right, #2563eb, #1d4ed8) !important;
            color: white !important;
        }

        html.dark .modal-content {
            background: rgba(15, 23, 42, 0.98);
            border: 1px solid rgba(148, 163, 184, 0.2);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6),
                inset 0 1px 0 0 rgba(255, 255, 255, 0.08);
        }

        @keyframes modalSlide {
            from {
                transform: scale(0.9) translateY(20px);
                opacity: 0;
            }

            to {
                transform: scale(1) translateY(0);
                opacity: 1;
            }
        }

        @media (max-width: 768px) {
            .modal-overlay {
                padding: 0;
                align-items: stretch;
            }

            .modal-content {
                max-width: none;
                width: 100%;
                height: 100dvh;
                max-height: 100dvh;
                border-radius: 0;
                display: flex;
                align-items: center;
            }

            .modal-content > .p-6 {
                width: 100%;
                padding: 1.25rem;
            }

            .modal-content .flex.gap-3 {
                flex-direction: column;
            }

            .modal-content button {
                min-height: 48px;
            }
        }

        /* ==========================================
           iOS Pure Glass Theme (Global Menu Override)
           ========================================== */
        body.ios-pure-glass {
            --ios-glass-bg-light: linear-gradient(145deg, rgba(255, 255, 255, 0.62), rgba(255, 255, 255, 0.28));
            --ios-glass-bg-dark: linear-gradient(145deg, rgba(31, 41, 55, 0.64), rgba(17, 24, 39, 0.46));
            --ios-glass-border-light: rgba(255, 255, 255, 0.48);
            --ios-glass-border-dark: rgba(203, 213, 225, 0.2);
            --ios-glass-shadow-light: 0 22px 46px -16px rgba(31, 41, 55, 0.3), 0 8px 18px -10px rgba(31, 41, 55, 0.18);
            --ios-glass-shadow-dark: 0 24px 52px -16px rgba(0, 0, 0, 0.62), 0 8px 18px -10px rgba(0, 0, 0, 0.46);
            --ios-glass-backdrop: blur(34px) saturate(200%) brightness(1.04);
            --ios-glass-backdrop-dark: blur(34px) saturate(215%) brightness(0.9);
        }

        body.ios-pure-glass #video-overlay {
            background:
                radial-gradient(circle at 8% -10%, rgba(255, 255, 255, 0.5), transparent 40%),
                radial-gradient(circle at 95% 120%, rgba(255, 255, 255, 0.24), transparent 46%),
                linear-gradient(135deg, rgba(248, 250, 252, 0.68), rgba(241, 245, 249, 0.56));
        }

        html.dark body.ios-pure-glass #video-overlay {
            background:
                radial-gradient(circle at 8% -10%, rgba(55, 65, 81, 0.44), transparent 44%),
                radial-gradient(circle at 95% 120%, rgba(75, 85, 99, 0.2), transparent 46%),
                linear-gradient(135deg, rgba(3, 7, 18, 0.74), rgba(17, 24, 39, 0.6));
        }

        body.ios-pure-glass #topBar,
        body.ios-pure-glass #userMenu .dropdown-menu,
        body.ios-pure-glass #sucursalMenu .dropdown-menu,
        body.ios-pure-glass #openAppsDock,
        body.ios-pure-glass #dockContextMenu,
        body.ios-pure-glass #mobileAppHeader,
        body.ios-pure-glass .context-menu,
        body.ios-pure-glass .modal-content,
        body.ios-pure-glass .notification {
            background: var(--ios-glass-bg-light) !important;
            border: 1px solid var(--ios-glass-border-light) !important;
            backdrop-filter: var(--ios-glass-backdrop) !important;
            -webkit-backdrop-filter: var(--ios-glass-backdrop) !important;
            box-shadow: var(--ios-glass-shadow-light), inset 0 1px 0 rgba(255, 255, 255, 0.56), inset 0 -1px 0 rgba(255, 255, 255, 0.2) !important;
        }

        html.dark body.ios-pure-glass #topBar,
        html.dark body.ios-pure-glass #userMenu .dropdown-menu,
        html.dark body.ios-pure-glass #sucursalMenu .dropdown-menu,
        html.dark body.ios-pure-glass #openAppsDock,
        html.dark body.ios-pure-glass #dockContextMenu,
        html.dark body.ios-pure-glass #mobileAppHeader,
        html.dark body.ios-pure-glass .context-menu,
        html.dark body.ios-pure-glass .modal-content,
        html.dark body.ios-pure-glass .notification {
            background: var(--ios-glass-bg-dark) !important;
            border: 1px solid var(--ios-glass-border-dark) !important;
            backdrop-filter: var(--ios-glass-backdrop-dark) !important;
            -webkit-backdrop-filter: var(--ios-glass-backdrop-dark) !important;
            box-shadow: var(--ios-glass-shadow-dark), inset 0 1px 0 rgba(255, 255, 255, 0.12), inset 0 -1px 0 rgba(255, 255, 255, 0.05) !important;
        }

        body.ios-pure-glass .dock-app-icon {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.42), rgba(255, 255, 255, 0.18)) !important;
            border: 1px solid rgba(255, 255, 255, 0.42) !important;
            backdrop-filter: blur(26px) saturate(190%) !important;
            -webkit-backdrop-filter: blur(26px) saturate(190%) !important;
            box-shadow: 0 16px 34px -14px rgba(31, 41, 55, 0.34), 0 6px 14px -8px rgba(15, 23, 42, 0.26), inset 0 1px 0 rgba(255, 255, 255, 0.42), inset 0 -1px 0 rgba(255, 255, 255, 0.18) !important;
        }

        /* macOS icons carry their own background — no glass overlay */
        body.ios-pure-glass .app-icon .icon-back > svg,
        body.ios-pure-glass .app-icon .icon-back > img,
        body.ios-pure-glass .app-icon > svg,
        body.ios-pure-glass .app-icon > img {
            background: none !important;
            border: none !important;
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }

        html.dark body.ios-pure-glass .dock-app-icon {
            background: linear-gradient(145deg, rgba(71, 85, 105, 0.42), rgba(30, 41, 59, 0.28)) !important;
            border-color: rgba(203, 213, 225, 0.22) !important;
        }

        body.ios-pure-glass .app-icon span,
        body.ios-pure-glass .dock-app-label-tip,
        body.ios-pure-glass .app-title,
        body.ios-pure-glass #logoText {
            text-shadow: 0 1px 8px rgba(15, 23, 42, 0.35);
        }

        body.ios-pure-glass button:not(.app-icon):not(.dock-app-item),
        body.ios-pure-glass input,
        body.ios-pure-glass select,
        body.ios-pure-glass textarea {
            border: 1px solid rgba(255, 255, 255, 0.42) !important;
            backdrop-filter: blur(22px) saturate(170%) !important;
            -webkit-backdrop-filter: blur(22px) saturate(170%) !important;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.42), 0 8px 20px -12px rgba(15, 23, 42, 0.28);
        }

        html.dark body.ios-pure-glass button:not(.app-icon):not(.dock-app-item),
        html.dark body.ios-pure-glass input,
        html.dark body.ios-pure-glass select,
        html.dark body.ios-pure-glass textarea {
            border: 1px solid rgba(148, 163, 184, 0.24) !important;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.1), 0 8px 22px -14px rgba(0, 0, 0, 0.5);
        }
    </style>

    <script>
        // ==========================================
        // Alpine.js Components
        // ==========================================

        // Sistema de Notificaciones
        function notificationSystem() {
            return {
                notifications: [],
                nextId: 1,
                seenKeys: {},
                activeKeys: {},

                show(title, message, type = 'info', duration = 5000, meta = {}) {
                    const popupKey = String(meta && meta.popupKey ? meta.popupKey : '');
                    if (popupKey && this.activeKeys[popupKey]) {
                        return this.activeKeys[popupKey];
                    }
                    const id = this.nextId++;
                    const notification = {
                        id,
                        title,
                        message,
                        type,
                        url: String(meta && meta.url ? meta.url : ''),
                        action: String(meta && meta.action ? meta.action : ''),
                        peerLogin: Number(meta && meta.peerLogin ? meta.peerLogin : 0),
                        popupKey,
                        markReadId: String(meta && meta.markReadId ? meta.markReadId : ''),
                        closing: false,
                        hidden: false
                    };

                    this.notifications.push(notification);
                    if (popupKey) {
                        this.activeKeys[popupKey] = id;
                    }
                    this.playSound(type);

                    if (duration > 0) {
                        setTimeout(() => {
                            this.closeNotification(id);
                        }, duration);
                    }

                    return id;
                },

                playSound(type = 'info') {
                    try {
                        const AC = window.AudioContext || window.webkitAudioContext;
                        if (!AC) return;
                        if (!window.__smxNotifAudioCtx) {
                            window.__smxNotifAudioCtx = new AC();
                        }
                        const ctx = window.__smxNotifAudioCtx;
                        if (ctx.state === 'suspended') {
                            ctx.resume().catch(() => {});
                        }
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.type = 'sine';
                        osc.frequency.value = (type === 'error') ? 300 : (type === 'warning' ? 520 : 680);
                        gain.gain.value = 0.0001;
                        osc.connect(gain);
                        gain.connect(ctx.destination);
                        const t = ctx.currentTime;
                        gain.gain.exponentialRampToValueAtTime(0.08, t + 0.01);
                        gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.22);
                        osc.start(t);
                        osc.stop(t + 0.24);
                    } catch (_) {}
                },

                openNotification(n) {
                    if (!n) return;
                    if (n.popupKey && Array.isArray(window.__smxBellState.items)) {
                        const bellItem = window.__smxBellState.items.find((item) => String(item.key || '') === String(n.popupKey));
                        if (bellItem) {
                            bellItem.read = true;
                            smxRenderBell();
                            smxBellSave();
                        }
                    }
                    if (n.markReadId) {
                        smxBellMarkRead([n.markReadId]);
                    }
                    if (n.action === 'open-chat' && Number(n.peerLogin || 0) > 0) {
                        smxOpenChatPeer(Number(n.peerLogin || 0));
                        this.closeNotification(n.id);
                        return;
                    }
                    if (!n.url) {
                        this.closeNotification(n.id);
                        return;
                    }
                    smxBellOpenNotificationTarget(n);
                    this.closeNotification(n.id);
                },

                closeNotification(id) {
                    const notification = this.notifications.find(n => n.id === id);
                    if (notification) {
                        if (notification.popupKey) {
                            delete this.activeKeys[notification.popupKey];
                        }
                        notification.closing = true;
                        setTimeout(() => {
                            notification.hidden = true;
                            this.notifications = this.notifications.filter(n => n.id !== id);
                        }, 300);
                    }
                }
            };
        }

        function smxNotificationContainerData() {
            const el = document.querySelector('[x-data*="notificationSystem"]');
            if (!el) return null;
            if (el.__x && el.__x.$data) return el.__x.$data;
            if (Array.isArray(el._x_dataStack) && el._x_dataStack[0]) return el._x_dataStack[0];
            return null;
        }

        function smxWithNotificationContainer(callback, attempt = 0) {
            const container = smxNotificationContainerData();
            if (container) {
                callback(container);
                return true;
            }
            if (attempt < 20) {
                setTimeout(() => smxWithNotificationContainer(callback, attempt + 1), 150);
            }
            return false;
        }

        // Modal de Confirmación
        function confirmModal() {
            return {
                show: false,
                title: '',
                message: '',
                resolvePromise: null,

                open(title, message) {
                    this.title = title;
                    this.message = message;
                    this.show = true;

                    return new Promise((resolve) => {
                        this.resolvePromise = resolve;
                    });
                },

                confirm() {
                    this.show = false;
                    if (this.resolvePromise) {
                        this.resolvePromise(true);
                        this.resolvePromise = null;
                    }
                },

                cancel() {
                    this.show = false;
                    if (this.resolvePromise) {
                        this.resolvePromise(false);
                        this.resolvePromise = null;
                    }
                }
            };
        }

        // Funciones globales para notificaciones
        window.notify = {
            success: (title, message) => {
                const event = new CustomEvent('show-notification', {
                    detail: {
                        title,
                        message,
                        type: 'success'
                    }
                });
                window.dispatchEvent(event);
            },
            error: (title, message) => {
                const event = new CustomEvent('show-notification', {
                    detail: {
                        title,
                        message,
                        type: 'error'
                    }
                });
                window.dispatchEvent(event);
            },
            warning: (title, message) => {
                const event = new CustomEvent('show-notification', {
                    detail: {
                        title,
                        message,
                        type: 'warning'
                    }
                });
                window.dispatchEvent(event);
            },
            info: (title, message) => {
                const event = new CustomEvent('show-notification', {
                    detail: {
                        title,
                        message,
                        type: 'info'
                    }
                });
                window.dispatchEvent(event);
            }
        };

        window.__confirmQueue = [];
        window.confirmDialog = (title, message) => {
            return new Promise((resolve) => {
                const tryRun = () => {
                    const modalEl = document.querySelector('[x-data*="confirmModal"]');
                    if (modalEl && modalEl._x_dataStack && modalEl._x_dataStack[0]) {
                        window.__confirmModal = modalEl._x_dataStack[0];
                    }

                    if (window.__confirmModal && typeof window.__confirmModal.open === 'function') {
                        window.__confirmModal.open(title, message).then(resolve);
                    } else {
                        window.__confirmQueue.push({ title, message, resolve });
                    }
                };

                if (document.readyState === 'complete') {
                    setTimeout(tryRun, 50);
                } else {
                    tryRun();
                }
            });
        };

        // Event listeners
        document.addEventListener('alpine:init', () => {
            window.addEventListener('show-notification', (e) => {
                smxWithNotificationContainer((container) => {
                    container.show(e.detail.title, e.detail.message, e.detail.type);
                });
            });

            // Polling de notificaciones backend (admin + operativas)
            const pollMenuNotifications = async () => {
                if (!navigator.onLine) return;
                try {
                    const res = await fetch(`/public/menu/api/notificaciones.php?id_empresa=${encodeURIComponent(String(ID_EMPRESA))}&_ts=${Date.now()}`, {
                        credentials: 'same-origin',
                        cache: 'no-store'
                    });
                    const data = await res.json();
                    if (!data || !data.success || !Array.isArray(data.notifications)) return;
                    if (typeof window.__smxBellPush === 'function') {
                        window.__smxBellPush(data.notifications);
                    }
                    const authIds = new Set(['caja_void_approvals', 'pos_price_override_approvals', 'pos_item_delete_approvals']);
                    smxWithNotificationContainer((container) => {
                        data.notifications.forEach((n) => {
                            const key = `${n.id || 'n'}|${n.title || ''}|${n.message || ''}`;
                            const notifId = String(n && n.id ? n.id : '');
                            const notifTitle = String(n && n.title ? n.title : '');
                            const lowerNotifTitle = notifTitle.toLowerCase();
                            const isUnread = !Boolean(n.read);
                            if (isUnread) {
                                container.show(
                                    n.title || 'Notificación',
                                    n.message || '',
                                    n.type || 'info',
                                    0,
                                    { url: n.url || '', popupKey: key, markReadId: notifId.startsWith('user_notif_') ? notifId : '' }
                                );
                                return;
                            }
                            if (container.seenKeys[key]) return;
                            container.seenKeys[key] = Date.now();
                            const isKardexDecision = notifId.startsWith('user_notif_') && lowerNotifTitle.includes('solicitud de') && (lowerNotifTitle.includes('anulación') || lowerNotifTitle.includes('anulacion') || lowerNotifTitle.includes('desanulación') || lowerNotifTitle.includes('desanulacion'));
                            const isPriceDecision = notifId.startsWith('user_notif_') && lowerNotifTitle.includes('precio especial');
                            if (!authIds.has(notifId) && !isKardexDecision && !isPriceDecision) return;
                            const duration = (isKardexDecision || isPriceDecision) ? 0 : 7000;
                            container.show(n.title || 'Notificación', n.message || '', n.type || 'info', duration, { url: n.url || '', popupKey: key });
                            const idx = container.notifications.length - 1;
                            if (idx >= 0) {
                                container.notifications[idx].url = n.url || '';
                            }
                        });
                    });
                } catch (_) {}
            };
            pollMenuNotifications();
            setInterval(pollMenuNotifications, 5000);
        });

        // Inicializar modal de confirmación después de que Alpine procese el DOM
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                const modalEl = document.querySelector('[x-data*="confirmModal"]');
                if (modalEl && modalEl._x_dataStack && modalEl._x_dataStack[0]) {
                    window.__confirmModal = modalEl._x_dataStack[0];

                    if (window.__confirmQueue.length) {
                        const queue = [...window.__confirmQueue];
                        window.__confirmQueue = [];
                        queue.forEach((item) => {
                            window.__confirmModal.open(item.title, item.message).then(item.resolve);
                        });
                    }
                }
            }, 100);
        });

        // Guardar datos en localStorage
        localStorage.setItem('id_login', <?= json_encode($id_login) ?>);
        localStorage.setItem('id_empresa', <?= json_encode($id_empresa) ?>);
        localStorage.setItem('db', <?= json_encode($dbu) ?>);
        localStorage.setItem('dbu', <?= json_encode($dbu) ?>);
    </script>
</head>

<body class="ios-pure-glass<?= $isMobileDevice ? ' is-mobile' : '' ?>">
    <!-- Video Background -->
    <video id="video-background" muted loop playsinline>
        <source src="" type="video/mp4">
    </video>
    <div id="video-overlay"></div>

    <!-- Burbuja de mensajería (esquina superior derecha, flotante global) -->
    <div id="notifBellWrap" style="position:fixed; top:10px; right:14px; z-index:20000;">
        <button id="notifBellBtn"
                style="display:flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:999px; border:1px solid rgba(29,78,216,0.18); background:linear-gradient(145deg, rgba(37,99,235,0.56), rgba(29,78,216,0.48)); box-shadow:0 10px 24px rgba(29,78,216,0.16), inset 0 1px 0 rgba(255,255,255,0.16); backdrop-filter:blur(10px) saturate(135%); -webkit-backdrop-filter:blur(10px) saturate(135%); cursor:pointer; color:#fff; position:relative;">
            <i class="fab fa-whatsapp" style="font-size:13px; line-height:1;"></i>
            <span id="notifBellBadge"
                  style="display:none; position:absolute; top:-4px; right:-5px; min-width:14px; height:14px; border-radius:999px; padding:0 3px; background:#ef4444; color:#fff; font-size:8px; font-weight:700; line-height:14px; text-align:center; border:1px solid #fff;">0</span>
        </button>
        <input id="notifProfileAvatarInput" type="file" accept="image/*" style="display:none;">
        <div id="notifBellDropdown" class="liquid-glass-dropdown"
             style="display:none; position:absolute; top:48px; right:0; width:760px; max-width:96vw; border-radius:14px; overflow:hidden; z-index:20001;">
            <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 12px; border-bottom:1px solid rgba(148,163,184,0.2);">
                <div style="display:flex; align-items:center; gap:10px; min-width:0; flex:1;">
                    <div style="position:relative; width:38px; height:38px; flex:0 0 38px;">
                        <img id="notifProfileAvatarImg" src="/public/usuarios/api/avatar.php?action=view&id_login=<?= (int)$id_login ?>&v=<?= time() ?>" alt="avatar" style="width:38px; height:38px; border-radius:999px; object-fit:cover; border:1px solid rgba(148,163,184,.35); background:rgba(15,23,42,.22);">
                        <button id="notifProfileAvatarEditBtn" type="button" title="Cambiar avatar" style="position:absolute; right:-3px; bottom:-3px; width:18px; height:18px; border:none; border-radius:999px; background:rgba(59,130,246,.95); color:#fff; font-size:9px; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 4px 10px rgba(37,99,235,.28);">
                            <i class="fas fa-pen"></i>
                        </button>
                    </div>
                    <div style="min-width:0;">
                        <div style="font-size:13px; font-weight:800; color:var(--label-color); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($usr_name) ?></div>
                        <div style="font-size:11px; opacity:.75; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Sesión activa</div>
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <button id="notifBellRadarToggle" type="button" style="font-size:11px; color:#f59e0b; background:transparent; border:none; cursor:pointer;">Radar: ON</button>
                    <button id="notifBellMarkRead" type="button" style="font-size:11px; color:#3b82f6; background:transparent; border:none; cursor:pointer;">Marcar leídas</button>
                    <button id="notifBellClose" type="button" title="Cerrar" style="font-size:16px; line-height:1; color:var(--label-color); background:transparent; border:none; cursor:pointer; opacity:.85;">×</button>
                </div>
            </div>
            <div style="display:flex; gap:8px; padding:8px 10px; border-bottom:1px solid rgba(148,163,184,0.2);">
                <button id="notifTabChat" type="button" style="padding:6px 12px; border-radius:999px; border:1px solid rgba(16,185,129,.45); background:rgba(16,185,129,.18); color:#10b981; font-size:12px; font-weight:700; cursor:pointer;">Chat</button>
                <button id="notifTabNoti" type="button" style="padding:6px 12px; border-radius:999px; border:1px solid rgba(148,163,184,.35); background:transparent; color:var(--label-color); font-size:12px; font-weight:600; cursor:pointer;">Notificaciones</button>
            </div>

            <div id="notifBellPanelChat" style="display:block; height:min(70vh, 560px); min-height:430px;">
                <div id="notifChatLayout" style="display:grid; grid-template-columns: 260px 1fr; height:100%; min-height:0;">
                    <div id="notifChatUsersPane" style="border-right:1px solid rgba(148,163,184,0.2); display:flex; flex-direction:column; min-height:0;">
                        <div style="padding:8px;">
                            <input id="notifChatSearch" type="text" placeholder="<?= htmlspecialchars(t('menu.user_search')) ?>" style="width:100%; height:34px; border-radius:999px; border:1px solid rgba(148,163,184,0.35); background:rgba(15,23,42,0.08); color:var(--label-color); padding:0 12px; font-size:12px; outline:none;">
                        </div>
                        <div id="notifSubscriptionChatCta" style="padding:0 12px 8px;"></div>
                        <div id="notifChatTotals" style="padding:0 12px 8px; font-size:11px; display:flex; gap:8px; flex-wrap:wrap;"></div>
                        <div id="notifChatUsers" style="overflow:auto; flex:1 1 auto; min-height:0; padding:6px;"></div>
                    </div>
                    <div id="notifChatThreadPane" style="display:flex; flex-direction:column; min-height:0;">
                        <div style="padding:10px 12px; border-bottom:1px solid rgba(148,163,184,0.2); display:flex; align-items:center; justify-content:space-between; gap:10px;">
                            <div style="display:flex; align-items:center; gap:8px; min-width:0; flex:1;">
                                <button id="notifChatBackBtn" type="button" title="Volver al listado" style="display:none; height:30px; min-width:30px; border:none; border-radius:999px; background:rgba(148,163,184,.22); color:var(--label-color); font-size:12px; font-weight:700; cursor:pointer;">←</button>
                                <div id="notifChatHeader" style="font-size:12px; font-weight:700; color:var(--label-color); min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Selecciona un usuario</div>
                            </div>
                            <div id="notifChatTyping" style="font-size:11px; color:#10b981; opacity:.9; min-height:14px;"></div>
                        </div>
                        <div id="notifChatThread" style="flex:1 1 auto; min-height:0; overflow:auto; padding:10px; background:rgba(15,23,42,0.05);"></div>
                        <div id="notifChatComposer" style="flex:0 0 auto; padding:8px; border-top:1px solid rgba(148,163,184,0.2); display:flex; gap:8px; align-items:center; position:relative;">
                            <button id="notifChatAttachBtn" type="button" title="Adjuntar archivo" style="height:36px; min-width:36px; border:none; border-radius:10px; background:rgba(59,130,246,.2); color:#60a5fa; font-size:13px; font-weight:700; cursor:pointer;">
                                <i class="fas fa-paperclip"></i>
                            </button>
                            <button id="notifChatEmojiBtn" type="button" title="Emojis" style="height:36px; min-width:36px; border:none; border-radius:10px; background:rgba(245,158,11,.2); color:#fbbf24; font-size:13px; font-weight:700; cursor:pointer;">
                                <i class="far fa-smile"></i>
                            </button>
                            <button id="notifChatMicBtn" type="button" title="Grabar voz" style="height:36px; min-width:36px; border:none; border-radius:10px; background:rgba(148,163,184,.22); color:var(--label-color); font-size:13px; font-weight:700; cursor:pointer;">
                                <i class="fas fa-microphone"></i>
                            </button>
                            <input id="notifChatFileInput" type="file" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.rar" style="display:none;">
                            <input id="notifChatInput" type="text" placeholder="Escribe un mensaje..." style="flex:1; height:36px; border-radius:10px; border:1px solid rgba(148,163,184,0.35); background:rgba(15,23,42,0.08); color:var(--label-color); padding:0 10px; font-size:12px; outline:none;">
                            <button id="notifChatSend" type="button" style="height:36px; min-width:74px; border:none; border-radius:10px; background:#10b981; color:#fff; font-size:12px; font-weight:700; cursor:pointer;">Enviar</button>
                            <div id="notifChatEmojiPanel" style="display:none; position:absolute; bottom:52px; left:52px; right:52px; max-height:130px; overflow:auto; padding:8px; border-radius:12px; border:1px solid rgba(148,163,184,.35); background:rgba(15,23,42,.96); z-index:5;">
                                <div id="notifChatEmojiList" style="display:flex; flex-wrap:wrap; gap:6px;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="notifBellPanelNoti" style="display:none;">
                <div style="padding:8px 10px; border-bottom:1px solid rgba(148,163,184,0.2);">
                    <input id="notifBellSearch" type="text" placeholder="<?= htmlspecialchars(t('menu.notification_search')) ?>" style="width:100%; height:34px; border-radius:999px; border:1px solid rgba(148,163,184,0.35); background:rgba(15,23,42,0.08); color:var(--label-color); padding:0 12px; font-size:12px; outline:none;">
                </div>
                <div id="notifBellList" style="max-height:386px; overflow:auto; padding:8px;"></div>
            </div>
            <div style="display:flex; align-items:center; justify-content:center; gap:8px; padding:7px 10px; border-top:1px solid rgba(148,163,184,0.2); background:rgba(2,6,23,.35);">
                <img src="/public/assets/images/logo-sistemaxpro.png" alt="Sistemaxpro" style="height:14px; width:auto; opacity:.95;">
                <span style="font-size:10px; opacity:.82; color:var(--label-color);">Sistema de Chat Privado desarrollado por el equipo de Sistemax.pro, diseñado para resguardar sus conversaciones comerciales y evitar su exposición en redes sociales.</span>
            </div>
        </div>
    </div>
    <style>
        @media (max-width: 900px) {
            #notifBellWrap {
                top: calc(var(--safe-top) + 8px) !important;
                right: 8px !important;
            }

            #notifBellBtn {
                width: 26px !important;
                height: 26px !important;
                box-shadow: 0 6px 14px rgba(29,78,216,0.16), inset 0 1px 0 rgba(255,255,255,0.14) !important;
            }

            #notifBellBtn > i {
                font-size: 13px !important;
            }

            #notifBellBadge {
                top: -4px !important;
                right: -5px !important;
                min-width: 14px !important;
                height: 14px !important;
                line-height: 10px !important;
                font-size: 8px !important;
                border-width: 1px !important;
                padding: 1px 3px !important;
            }

            #notifBellDropdown {
                position: fixed !important;
                top: calc(var(--safe-top) + 56px) !important;
                left: 8px !important;
                right: 8px !important;
                width: auto !important;
                max-width: none !important;
                border-radius: 16px !important;
                max-height: calc(100dvh - var(--safe-top) - var(--safe-bottom) - 64px);
            }

            #notifBellPanelChat {
                height: calc(100dvh - var(--safe-top) - var(--safe-bottom) - 200px) !important;
                min-height: 360px !important;
            }

            #notifChatLayout {
                display: block !important;
            }

            #notifChatUsersPane,
            #notifChatThreadPane {
                height: 100%;
                min-height: 0;
            }

            #notifChatUsersPane {
                border-right: none !important;
            }

            #notifChatLayout[data-mobile-view="users"] #notifChatUsersPane {
                display: flex !important;
            }

            #notifChatLayout[data-mobile-view="users"] #notifChatThreadPane {
                display: none !important;
            }

            #notifChatLayout[data-mobile-view="thread"] #notifChatUsersPane {
                display: none !important;
            }

            #notifChatLayout[data-mobile-view="thread"] #notifChatThreadPane {
                display: flex !important;
            }

            #notifChatBackBtn {
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
            }

            #notifChatComposer {
                padding-bottom: calc(8px + var(--safe-bottom));
            }
        }

        @media (max-width: 520px) {
            #notifChatComposer {
                gap: 6px !important;
                padding: 7px !important;
            }

            #notifChatAttachBtn,
            #notifChatEmojiBtn,
            #notifChatMicBtn {
                min-width: 34px !important;
                height: 34px !important;
            }

            #notifChatSend {
                min-width: 62px !important;
                height: 34px !important;
            }

            #notifChatInput {
                min-width: 0;
                height: 34px !important;
                font-size: 13px !important;
            }

            #notifChatEmojiPanel {
                left: 8px !important;
                right: 8px !important;
            }
        }

        @media (min-width: 901px) {
            #notifChatBackBtn {
                display: none !important;
            }
        }
    </style>

    <!-- TopBar -->
    <div id="topBar" class="liquid-glass-topbar">
        <div id="logoContainer">
            <img src="<?= htmlspecialchars($logoEmpresaUrl) ?>" alt="Logo" style="height:36px; border-radius:8px;">
            <span id="logoText"><?= htmlspecialchars($empresa) ?></span>
        </div>
        <div id="rightIcons">
            <div class="topbar-locale-wrap">
                <select id="globalLocaleSwitcher" class="topbar-locale-select" aria-label="<?= htmlspecialchars(t('login.language')) ?>">
                    <?php foreach ($localeOptions as $option): ?>
                        <option value="<?= htmlspecialchars($option['code']) ?>" <?= $currentLocale === $option['code'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($option['native']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <i class="fas fa-chevron-down"></i>
            </div>

            <!-- Dropdown Sucursales -->
            <?php if (count($sucursalesList) > 0): ?>
            <div id="sucursalMenu" style="position:relative;">
                <button onclick="document.getElementById('sucursalMenu').classList.toggle('open')"
                        style="display:flex; align-items:center; gap:8px; padding:6px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.25); background:rgba(255,255,255,0.35); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); cursor:pointer; font-size:13px; font-weight:500; color:var(--label-color); font-family:inherit; transition:all .2s;">
                    <i class="fas fa-building" style="font-size:13px; color:#3b82f6;"></i>
                    <span id="sucursalActualLabel"><?= htmlspecialchars($sucursalActivaNombre ?: t('common.select')) ?></span>
                    <i class="fas fa-chevron-down" style="font-size:10px; opacity:.5;"></i>
                </button>
                <div class="dropdown-menu liquid-glass-dropdown" style="display:none; min-width:220px;">
                    <div style="padding:8px 12px 6px; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:rgba(148,163,184,0.8);">
                        <i class="fas fa-building" style="margin-right:4px;"></i> <?= htmlspecialchars(t('common.branches')) ?>
                    </div>
                    <div class="dropdown-divider"></div>
                    <?php foreach ($sucursalesList as $suc): ?>
                    <div class="dropdown-item sucursal-option <?= (int)$suc['SUC'] === $sucursalActiva ? 'active-sucursal' : '' ?>"
                         data-id="<?= (int)$suc['SUC'] ?>"
                         data-nombre="<?= htmlspecialchars($suc['NOMBRE']) ?>"
                         onclick="cambiarSucursal(<?= (int)$suc['SUC'] ?>, '<?= htmlspecialchars(addslashes($suc['NOMBRE'])) ?>')">
                        <i class="fas fa-store" style="font-size:13px; color: <?= (int)$suc['SUC'] === $sucursalActiva ? '#3b82f6' : 'rgba(148,163,184,0.6)' ?>;"></i>
                        <span><?= htmlspecialchars($suc['NOMBRE']) ?></span>
                        <?php if ((int)$suc['SUC'] === $sucursalActiva): ?>
                        <i class="fas fa-check" style="margin-left:auto; font-size:11px; color:#3b82f6;"></i>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <button id="menuSpotlightTrigger"
                    type="button"
                    onclick="openMenuSpotlight()"
                    title="Buscar app"
                    style="display:flex; align-items:center; gap:8px; padding:6px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.25); background:rgba(255,255,255,0.35); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); cursor:pointer; font-size:13px; font-weight:500; color:var(--label-color); font-family:inherit; transition:all .2s;">
                <i class="fas fa-magnifying-glass" style="font-size:13px; color:#0ea5e9;"></i>
                <span class="user-name-text">Buscar app</span>
                <span style="font-size:11px; opacity:.6;">Ctrl K</span>
            </button>

            <a href="/public/inventario/index.php"
               title="<?= htmlspecialchars(t('menu.inventory_mobile')) ?>"
               style="display:flex; align-items:center; gap:8px; padding:6px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.25); background:rgba(255,255,255,0.35); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); cursor:pointer; font-size:13px; font-weight:600; color:var(--label-color); font-family:inherit; transition:all .2s; text-decoration:none;">
                <i class="fas fa-boxes-stacked" style="font-size:13px; color:#d97706;"></i>
                <span class="user-name-text"><?= htmlspecialchars(t('menu.inventory_mobile')) ?></span>
            </a>

            <!-- User menu -->
            <div id="userMenu">
                <button style="display:flex; align-items:center; gap:8px; padding:6px 12px; border-radius:10px; border:1px solid rgba(148,163,184,0.25); background:rgba(255,255,255,0.35); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); cursor:pointer; font-size:13px; font-weight:500; color:var(--label-color); font-family:inherit; transition:all .2s;">
                    <div style="width:28px; height:28px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#6366f1); display:flex; align-items:center; justify-content:center; color:white; font-size:12px; font-weight:700;">
                        <?= strtoupper(substr($usr_name, 0, 1)) ?>
                    </div>
                    <span class="user-name-text"><?= htmlspecialchars($usr_name) ?></span>
                    <i class="fas fa-chevron-down" style="font-size:10px; opacity:.5;"></i>
                </button>
                <div class="dropdown-menu liquid-glass-dropdown">
                    <div style="padding:10px 12px;">
                        <p style="font-weight:600; font-size:14px; margin:0;"><?= htmlspecialchars($usr_name) ?></p>
                        <p style="font-size:11px; opacity:.6; margin:2px 0 0;"><?= htmlspecialchars($group_name) ?></p>
                    </div>
                    <div class="dropdown-divider"></div>
                    <div class="dropdown-item logout js-logout-link" onclick="cerrarSesion()">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:16px; height:16px; color:#ef4444;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15m-3 0-3-3m0 0 3-3m-3 3H15" />
                        </svg>
                        <span>Cerrar Sesión</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Banner de Suscripción (Período de Gracia o Pago Pendiente) -->
    <?php if ($mostrarBannerSuscripcion && $suscripcionActual): ?>
    <div id="suscripcionBanner" class="fixed top-0 left-0 right-0 z-[9999] transform transition-transform duration-300"
         style="background:
            radial-gradient(circle at 15% -20%, rgba(255,255,255,0.28), transparent 42%),
            radial-gradient(circle at 85% 120%, rgba(216,180,254,0.24), transparent 45%),
            linear-gradient(135deg, rgba(109,40,217,0.68), rgba(76,29,149,0.6)); backdrop-filter: blur(18px) saturate(165%); -webkit-backdrop-filter: blur(18px) saturate(165%); border-bottom: 1px solid rgba(255,255,255,0.24); box-shadow: 0 14px 32px rgba(45, 27, 105, 0.32);">
        <div class="max-w-4xl mx-auto px-4 py-3 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 text-white">
                <i class="fas <?= $estadoAcceso === 'gracia' ? 'fa-exclamation-triangle text-xl' : 'fa-info-circle' ?> text-white"></i>
                <div>
                    <p class="font-semibold text-sm">
                        <?php if ($estadoAcceso === 'gracia'): ?>
                            ⚠️ Período de Gracia: <?= $diasRestantesSuscripcion ?> días restantes
                        <?php else: ?>
                            💳 Tiene un pago pendiente
                        <?php endif; ?>
                    </p>
                    <p class="text-xs opacity-90">
                        <?php if ($estadoAcceso === 'gracia'): ?>
                            Su suscripción será suspendida si no realiza el pago antes de que expire el período de gracia.
                        <?php else: ?>
                            Realice el pago para evitar interrupciones en el servicio.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="../pagar_suscripcion.php?id=<?= $suscripcionActual['id_suscripcion'] ?>" 
                   class="bg-white/18 text-white text-sm font-semibold px-4 py-2 rounded-lg border border-white/35 hover:bg-white/24 transition-colors whitespace-nowrap">
                    <i class="fas fa-credit-card mr-1"></i> Pagar Ahora
                </a>
                <a href="#" onclick="hideSuscripcionBanner(true); return false;" class="text-white/90 hover:text-white text-xs underline whitespace-nowrap">
                    No volver a mostrar
                </a>
                <button onclick="hideSuscripcionBanner(false)" 
                        class="text-white/85 hover:text-white p-2">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    </div>
    <style>
        @media (max-width: 640px) {
            #suscripcionBanner { padding: 8px; }
            #suscripcionBanner .max-w-4xl { flex-direction: column; text-align: center; gap: 12px; }
        }
    </style>
    <?php endif; ?>
    
    <!-- Sistema de Notificaciones -->
    <div x-data="notificationSystem()" class="notification-container">
        <template x-for="notification in notifications" :key="notification.id">
            <div class="notification"
                :class="[
                    notification.closing ? 'closing' : '',
                    notification.action === 'open-chat' ? 'ring-1 ring-sky-400/30 bg-slate-950/92' : ''
                ]"
                @click="openNotification(notification)"
                x-show="!notification.hidden">
                <div class="flex items-start gap-3">
                    <!-- Icono -->
                    <div class="flex-shrink-0">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center overflow-hidden"
                            :class="{
                                 'bg-sky-500/20 border border-sky-400/30 text-sky-200': notification.action === 'open-chat',
                                 'bg-blue-100 dark:bg-blue-900/30': notification.type === 'info',
                                 'bg-green-100 dark:bg-green-900/30': notification.type === 'success',
                                 'bg-yellow-100 dark:bg-yellow-900/30': notification.type === 'warning',
                                 'bg-red-100 dark:bg-red-900/30': notification.type === 'error'
                             }">
                            <span x-show="notification.action === 'open-chat'" class="text-sm font-bold uppercase tracking-wide"
                                x-text="(notification.title || 'Chat').slice(0, 2)"></span>
                            <i x-show="notification.action !== 'open-chat'" class="text-lg"
                                :class="{
                                   'fas fa-info-circle text-blue-600 dark:text-blue-400': notification.type === 'info',
                                   'fas fa-check-circle text-green-600 dark:text-green-400': notification.type === 'success',
                                   'fas fa-exclamation-triangle text-yellow-600 dark:text-yellow-400': notification.type === 'warning',
                                   'fas fa-times-circle text-red-600 dark:text-red-400': notification.type === 'error'
                               }"></i>
                        </div>
                    </div>

                    <!-- Contenido -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <span x-show="notification.action === 'open-chat'" class="inline-flex items-center rounded-full bg-sky-500/15 text-sky-300 border border-sky-400/20 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em]">Chat</span>
                            <h4 class="font-semibold text-gray-900 dark:text-white text-sm min-w-0 truncate" x-text="notification.title"></h4>
                        </div>
                        <p class="text-gray-600 dark:text-gray-300 text-xs leading-relaxed"
                           :class="notification.action === 'open-chat' ? 'text-slate-300' : ''"
                           x-text="notification.message"></p>
                    </div>

                    <!-- Botón cerrar -->
                    <button @click.stop="closeNotification(notification.id)"
                        class="flex-shrink-0 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </template>
    </div>

    <!-- Modal de Confirmación -->
    <div x-data="confirmModal()" x-cloak>
        <div x-show="show"
            class="modal-overlay"
            @click.self="cancel()">
            <div class="modal-content">
                <div class="p-6">
                    <!-- Icono -->
                    <div class="flex justify-center mb-4">
                        <div class="w-16 h-16 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                            <i class="fas fa-question-circle text-3xl text-amber-600 dark:text-amber-400"></i>
                        </div>
                    </div>

                    <!-- Título -->
                    <h3 class="text-xl font-bold text-center text-gray-900 dark:text-white mb-2" x-text="title"></h3>

                    <!-- Mensaje -->
                    <p class="text-center text-gray-600 dark:text-gray-300 mb-6" x-text="message"></p>

                    <!-- Botones -->
                    <div class="flex gap-3">
                        <button @click="cancel()"
                            class="flex-1 px-4 py-3 rounded-xl border-2 border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-semibold hover:bg-gray-100 dark:hover:bg-gray-800 transition-all">
                            Cancelar
                        </button>
                        <button @click="confirm()"
                            class="flex-1 px-4 py-3 rounded-xl bg-gradient-to-r from-blue-600 to-blue-700 text-white font-semibold hover:from-blue-700 hover:to-blue-800 shadow-lg hover:shadow-xl transition-all">
                            Confirmar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Watermark -->
    <div id="watermarkContainer">
        <div id="watermarkLogo">
            <img src="<?= htmlspecialchars($logoEmpresaUrl) ?>" alt="Logo">
        </div>
        <div id="watermarkCompanyName"><?= htmlspecialchars($empresa) ?></div>
        <div id="watermarkUserName"><?= htmlspecialchars($usr_name) ?></div>
    </div>

    <?php if ($showMenuDebug): ?>
    <div style="position:fixed;top:12px;left:12px;z-index:99999;max-width:min(92vw,720px);max-height:45vh;overflow:auto;padding:12px 14px;border-radius:12px;background:rgba(15,23,42,.94);color:#e2e8f0;border:1px solid rgba(148,163,184,.35);box-shadow:0 12px 30px rgba(0,0,0,.35);font:12px/1.45 monospace;">
        <div style="font-weight:700;margin-bottom:8px;color:#67e8f9;">debug_menu</div>
        <pre style="margin:0;white-space:pre-wrap;word-break:break-word;"><?= htmlspecialchars(json_encode($menuDebug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></pre>
    </div>
    <?php endif; ?>

    <div id="vcortaWidget" aria-live="polite" aria-label="Chat de Vcorta">
        <div id="vcortaPanel">
            <div class="vcorta-head">
                <div class="vcorta-title">
                    <span class="vcorta-badge"><img src="/public/assets/images/logo-vcorta.png" alt="Vcorta"></span>
                    <div>
                        <div>Vcorta</div>
                        <div style="font-size:11px; font-weight:500; color:rgba(191,219,254,0.95);">Asistente de Sistemax</div>
                        <div id="vcortaHealth">Estado IA: verificando...</div>
                    </div>
                </div>
                <button type="button" class="vcorta-close" onclick="closeVcortaChat()" aria-label="Cerrar chat">✕</button>
            </div>
            <div id="vcortaMessages"></div>
            <div id="vcortaTyping">Vcorta está escribiendo...</div>
            <div id="vcortaSuggestions"></div>
            <form class="vcorta-input-wrap" onsubmit="sendVcortaMessageFromInput(); return false;">
                <div class="vcorta-input-row">
                    <input id="vcortaInput" type="text" maxlength="1000" autocomplete="off" placeholder="Escribí tu consulta...">
                    <button id="vcortaMicBtn" class="vcorta-tool-btn" type="button" aria-label="Dictar por micrófono" title="Dictar por micrófono" onclick="toggleVcortaMic()">
                        <i class="fas fa-microphone"></i>
                    </button>
                    <button id="vcortaAudioBtn" class="vcorta-tool-btn active" type="button" aria-label="Activar o desactivar voz" title="Voz activada" onclick="toggleVcortaAudio()">
                        <i class="fas fa-volume-up"></i>
                    </button>
                    <button id="vcortaSendBtn" type="submit" aria-label="Enviar mensaje">➤</button>
                </div>
            </form>
            <div id="vcortaKeyboard" aria-label="Teclado Vcorta"></div>
        </div>
    </div>

    <!-- Home Screen -->
    <?php if (isset($_GET['debug_icons'])): ?>
    <pre style="position:fixed;top:0;left:0;z-index:99999;background:#111;color:#0f0;font-size:11px;padding:10px;max-height:80vh;overflow:auto;max-width:90vw">
<?php foreach ($itemsMenu as $dbgItem):
    $dbgRoute = htmlspecialchars($dbgItem['app']??'',ENT_QUOTES);
    $dbgIco = htmlspecialchars($dbgItem['icono']??'',ENT_QUOTES);
    $dbgSrc = htmlspecialchars($dbgItem['icono_source']??'',ENT_QUOTES);
    $dbgSvg = htmlspecialchars($dbgItem['icono_svg']??'',ENT_QUOTES);
    $dbgHtml = resolveMenuIconSvg($dbgItem);
    $isImg = strpos($dbgHtml,'<img') !== false;
    echo htmlspecialchars("[{$dbgItem['label']}] icono={$dbgIco} src={$dbgSrc} svg={$dbgSvg} → " . ($isImg ? "IMG✓" : "HEROICON") . "\n");
endforeach; ?>
</pre>
    <?php endif; ?>
    <div id="homeScreen">
        <?php foreach ($itemsMenu as $item): ?>
            <?php if (isset($item['visible']) && !$item['visible']) continue; ?>
            <?php
            $is_logout = ($item['app'] === '__logout__');
            $is_vcorta = ($item['app'] === '__vcorta__');
            if ($is_logout) continue;
            $color3d = $item['color3d'] ?? 'blue';
            $appOrderKey = trim((string)($item['order_key'] ?? ''));
            if ($appOrderKey === '') {
                $appOrderKey = $is_vcorta ? '__vcorta__' : (string)($item['app'] ?? '');
            }
            if ($is_vcorta) {
                $onclick = "toggleVcortaChat(true)";
            } else {
                $onclick = "abrirApp('{$item['label']}', '{$item['app']}')";
            }
            $iconoSvg = resolveMenuIconSvg($item);
            ?>
            <div class="app-icon"
                 data-color="<?= $color3d ?>"
                 data-app-order-key="<?= htmlspecialchars($appOrderKey, ENT_QUOTES, 'UTF-8') ?>"
                 data-app-label="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>"
                 data-app-route="<?= htmlspecialchars((string)($item['app'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                 data-app-action="<?= htmlspecialchars($is_logout ? 'logout' : ($is_vcorta ? 'vcorta' : 'app'), ENT_QUOTES, 'UTF-8') ?>"
                 <?= $is_vcorta ? ' data-vcorta-trigger="1"' : '' ?>
                 onclick="<?= $onclick ?>">
                <span class="icon-back" style="display:contents;">
                    <?= $iconoSvg ?>
                </span>
                <span><?= htmlspecialchars($item['label']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <div id="menuSpotlightOverlay" class="menu-spotlight-overlay" aria-hidden="true">
        <div class="menu-spotlight-panel" role="dialog" aria-modal="true" aria-labelledby="menuSpotlightInput">
            <div class="menu-spotlight-head">
                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                <input id="menuSpotlightInput" class="menu-spotlight-input" type="text" autocomplete="off" placeholder="Buscar app o módulo...">
                <span class="menu-spotlight-hint">ESC</span>
            </div>
            <div id="menuSpotlightResults" class="menu-spotlight-results"></div>
        </div>
    </div>
    <div id="homeAppsPager" class="home-apps-pager" aria-label="<?= htmlspecialchars(t('common.apps_pagination')) ?>">
        <div id="homeAppsPagerDots" class="flex items-center gap-2"></div>
    </div>



    <!-- Header de navegación móvil (estilo iOS/Android nativo) -->
    <div id="mobileAppHeader">
        <button class="back-btn" onclick="cerrarAppMobile()">
            <?= $heroicons['chevron-left'] ?>
            <span><?= htmlspecialchars(t('common.home')) ?></span>
        </button>
        <span class="app-title" id="mobileAppTitle">App</span>
        <div style="width: 70px;"></div> <!-- Spacer para centrar título -->
    </div>
    
    <!-- Indicador de carga móvil -->
    <div id="mobileLoadingIndicator">
        <div class="loading-bar">
            <div class="loading-progress"></div>
        </div>
    </div>

    <!-- Iframe para apps (desktop y móvil) -->
    <iframe id="appFrame"
            allowtransparency="true"
            allow="camera *; microphone *; clipboard-read *; clipboard-write *"></iframe>
    <div id="desktopFramesContainer" aria-label="Apps abiertas en escritorio"></div>
    <div id="desktopLoadingOverlay" aria-hidden="true">
        <div class="desktop-loading-card">
            <div class="desktop-loading-title">Abriendo App</div>
            <div class="desktop-loading-text" id="desktopLoadingText">Cargando TPV...</div>
            <div class="desktop-loading-spinner"></div>
        </div>
    </div>
    <div id="dockRevealHandle" aria-label="Mostrar dock de apps abiertas"></div>
    <div id="openAppsDock" aria-live="polite" aria-label="Dock de apps abiertas"></div>
    <div id="dockContextMenu" aria-label="Menú contextual del dock"></div>

    <?php if ($usr_ti): ?>
        <!-- Menú Contextual (solo TI) -->
        <div id="contextMenu" class="context-menu">
            <div class="context-menu-item" onclick="openSifenConfig()">
                <i class="fas fa-file-invoice text-green-500"></i>
                <span>Conf. SIFEN</span>
            </div>
            <div class="context-menu-item" onclick="openFeDashboard()">
                <i class="fas fa-chart-line text-cyan-500"></i>
                <span>Estado FE</span>
            </div>
            <div class="context-menu-item" onclick="openAdminEmpresas()">
                <i class="fas fa-industry text-blue-500"></i>
                <span>Admin Empresas</span>
            </div>
        </div>
        <style>
            .context-menu {
                position: fixed;
                background: var(--bg-card);
                border: 1px solid var(--border-color);
                border-radius: 8px;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
                padding: 6px 0;
                min-width: 180px;
                z-index: 9999;
                display: none;
            }

            .context-menu-item {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 10px 16px;
                cursor: pointer;
                transition: background 0.15s;
                color: var(--text-color);
                font-size: 14px;
            }

            .context-menu-item:hover {
                background: var(--hover-bg);
            }

            .context-menu-item i {
                width: 18px;
                text-align: center;
            }
        </style>
    <?php endif; ?>

    <script>
        function detectMobileLayout() {
            const uaMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent || '');
            const narrowViewport = window.innerWidth <= 900;
            const narrowScreen = Math.min(window.screen.width || 9999, window.screen.height || 9999) <= 900;
            const touchDevice = (navigator.maxTouchPoints || 0) > 1;
            return uaMobile || narrowViewport || (touchDevice && narrowScreen);
        }

        let isMobile = detectMobileLayout();

        function syncMobileLayoutClass() {
            isMobile = detectMobileLayout();
            document.documentElement.classList.toggle('is-mobile', isMobile);
            if (document.body) {
                document.body.classList.toggle('is-mobile', isMobile);
            }
        }

        syncMobileLayoutClass();
        window.addEventListener('resize', syncMobileLayoutClass, { passive: true });

        // ID Empresa para menú contextual
        const ID_EMPRESA = <?= (int)$id_empresa ?>;
        const ES_TI = <?= $usr_ti ? 'true' : 'false' ?>;
        const SUSCRIPCION_BANNER_HIDE_KEY = `suscripcion_banner_hidden_${ID_EMPRESA}_<?= (int)$id_login ?>`;
        const VCORTA_ALLOWED_APPS = <?= json_encode(array_values(array_filter(array_map(function ($it) {
            if (($it['app'] ?? '') === '__logout__') return null;
            if (isset($it['visible']) && !$it['visible']) return null;
            return [
                'label' => (string)($it['label'] ?? ''),
                'app' => (string)($it['app'] ?? ''),
            ];
        }, $itemsMenu))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const DESKTOP_DOCK_ICON_MAP = <?= json_encode($dockIconMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const SMX_BELL_STORAGE_KEY = `smx_bell_items_${ID_EMPRESA}_<?= (int)$id_login ?>`;
        const SMX_BELL_RADAR_MUTE_KEY = `smx_bell_radar_mute_${ID_EMPRESA}_<?= (int)$id_login ?>`;
        window.__smxBellState = { items: [] };
        window.__smxBellAudioArmed = false;
        window.__smxBellAudioCtx = null;
        window.__smxBellRadarMuted = false;
        window.__smxBellRadarTimer = null;

        function smxBellLoad() {
            try {
                const raw = localStorage.getItem(SMX_BELL_STORAGE_KEY);
                if (!raw) return;
                const parsed = JSON.parse(raw);
                if (!Array.isArray(parsed)) return;
                window.__smxBellState.items = parsed.slice(0, 80).map((it) => ({
                    key: String(it.key || ''),
                    id: it.id || it.key || '',
                    title: String(it.title || 'Notificación'),
                    message: String(it.message || ''),
                    type: String(it.type || 'info'),
                    url: String(it.url || ''),
                    time: String(it.time || ''),
                    read: !!it.read
                }));
            } catch (_) {}
        }

        function smxBellSave() {
            try {
                const items = Array.isArray(window.__smxBellState.items) ? window.__smxBellState.items : [];
                localStorage.setItem(SMX_BELL_STORAGE_KEY, JSON.stringify(items.slice(0, 80)));
            } catch (_) {}
        }

        function smxBellLoadRadarPref() {
            try {
                window.__smxBellRadarMuted = localStorage.getItem(SMX_BELL_RADAR_MUTE_KEY) === '1';
            } catch (_) {
                window.__smxBellRadarMuted = false;
            }
        }

        function smxBellSaveRadarPref() {
            try {
                localStorage.setItem(SMX_BELL_RADAR_MUTE_KEY, window.__smxBellRadarMuted ? '1' : '0');
            } catch (_) {}
        }

        function smxBellSyncRadarToggle() {
            const btn = document.getElementById('notifBellRadarToggle');
            if (!btn) return;
            btn.textContent = window.__smxBellRadarMuted ? 'Radar: OFF' : 'Radar: ON';
            btn.style.color = window.__smxBellRadarMuted ? '#94a3b8' : '#f59e0b';
        }

        function smxBellUnreadCount() {
            const items = Array.isArray(window.__smxBellState.items) ? window.__smxBellState.items : [];
            const unreadNoti = items.filter(i => !i.read).length;
            const unreadChat = Number(window.__smxChatState && window.__smxChatState.unreadTotal ? window.__smxChatState.unreadTotal : 0);
            return unreadNoti + unreadChat;
        }

        function smxBellAuthUnreadCount() {
            const authIds = new Set(['caja_void_approvals', 'pos_price_override_approvals', 'pos_item_delete_approvals']);
            const items = Array.isArray(window.__smxBellState.items) ? window.__smxBellState.items : [];
            return items.filter(i => !i.read && authIds.has(String(i && i.id ? i.id : ''))).length;
        }

        function smxBellPlayRadar() {
            if (smxBellAuthUnreadCount() <= 0) return;
            if (window.__smxBellRadarMuted) return;
            if (!window.__smxBellAudioArmed) return;
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                if (!window.__smxBellAudioCtx) window.__smxBellAudioCtx = new Ctx();
                const ctx = window.__smxBellAudioCtx;
                if (ctx.state === 'suspended') ctx.resume();
                const now = ctx.currentTime;
                const playTone = (start, freq, dur, gain) => {
                    const osc = ctx.createOscillator();
                    const g = ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(freq, start);
                    g.gain.setValueAtTime(0.0001, start);
                    g.gain.exponentialRampToValueAtTime(gain, start + 0.02);
                    g.gain.exponentialRampToValueAtTime(0.0001, start + dur);
                    osc.connect(g);
                    g.connect(ctx.destination);
                    osc.start(start);
                    osc.stop(start + dur + 0.03);
                };
                playTone(now, 900, 0.16, 0.06);
                playTone(now + 0.22, 1250, 0.16, 0.05);
            } catch (_) {}
        }

        function smxBellIsAuthorizationNotification(notif) {
            const authIds = new Set(['caja_void_approvals', 'pos_price_override_approvals', 'pos_item_delete_approvals']);
            const id = String(notif && notif.id ? notif.id : '');
            const url = String(notif && notif.url ? notif.url : '');
            return authIds.has(id) || url.includes('/public/pos/autorizaciones.php');
        }

        function smxBellIsAuthorizationDecisionNotification(notif) {
            const id = String(notif && notif.id ? notif.id : '');
            const title = String(notif && notif.title ? notif.title : '').toLowerCase();
            if (!id.startsWith('user_notif_')) return false;
            return title.includes('resultado de solicitud')
                || title.includes('solicitud pos rechazada')
                || title.includes('solicitud de precio especial rechazada');
        }

        function smxBellCloseDropdown() {
            const dd = document.getElementById('notifBellDropdown');
            if (dd) dd.style.display = 'none';
        }

        function smxBellIsOpen() {
            const dd = document.getElementById('notifBellDropdown');
            return !!(dd && dd.style.display === 'block');
        }

        function smxBellOpenNotificationTarget(notif) {
            if (!notif) return;
            if (smxBellIsAuthorizationDecisionNotification(notif)) {
                smxBellCloseDropdown();
                return;
            }
            const targetUrl = smxBellIsAuthorizationNotification(notif)
                ? '/public/pos/autorizaciones.php'
                : String(notif.url || '').trim();
            if (!targetUrl) return;
            smxBellCloseDropdown();
            if (typeof abrirApp === 'function') {
                abrirApp(notif.title || 'Notificación', targetUrl.replace(/^\//, ''));
            } else {
                window.location.href = targetUrl;
            }
        }

        function smxBellBackendNotifId(notif) {
            const raw = String(notif && notif.id ? notif.id : notif || '');
            const match = raw.match(/^user_notif_(\d+)$/);
            return match ? Number(match[1]) : 0;
        }

        async function smxBellMarkRead(itemsOrIds, markAll = false) {
            try {
                const source = Array.isArray(itemsOrIds) ? itemsOrIds : [itemsOrIds];
                const ids = source.map((entry) => {
                    if (typeof entry === 'number') return entry;
                    if (typeof entry === 'string') return entry;
                    return smxBellBackendNotifId(entry);
                }).filter(Boolean);
                if (!markAll && ids.length === 0) return false;
                const res = await fetch(`/public/menu/api/notificaciones.php?action=mark_read&id_empresa=${encodeURIComponent(String(ID_EMPRESA))}`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(markAll ? { all: 1 } : { ids })
                });
                const data = await res.json().catch(() => null);
                return !!(data && data.success);
            } catch (_) {
                return false;
            }
        }

        function smxBellReplayUnreadBubbles() {
            const items = Array.isArray(window.__smxBellState.items) ? window.__smxBellState.items : [];
            smxWithNotificationContainer((container) => {
                items.forEach((n) => {
                    if (Boolean(n.read)) return;
                    const notifId = String(n && n.id ? n.id : '');
                    const key = String(n.key || `${notifId}|${n.title || ''}|${n.message || ''}`);
                    container.show(
                        n.title || 'Notificación',
                        n.message || '',
                        n.type || 'info',
                        0,
                        { url: n.url || '', popupKey: key, markReadId: notifId.startsWith('user_notif_') ? notifId : '' }
                    );
                });
            });
        }

        function smxFormatTime() {
            const d = new Date();
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }

        window.__smxChatState = {
            users: [],
            selected: null,
            messages: [],
            q: '',
            developerChat: null,
            subscriptionChat: null,
            totalUsers: 0,
            totalCompanies: 0,
            unreadTotal: 0,
            lastUnreadTotal: null,
            usersBootstrapped: false,
            pollTimer: null,
            uploading: false,
            mediaRecorder: null,
            mediaChunks: [],
            mediaStream: null,
            recStartMs: 0,
            peerTyping: false,
            typingPingAt: 0,
            usersOffset: 0,
            usersHasMore: true,
            usersLoading: false,
            threadLoading: false,
            pollInFlight: false,
            usersPollAt: 0,
            usersPollEveryMs: 20000,
            mobileView: 'users',
        };
        function smxEsc(v) {
            return String(v || '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
        }

        function smxChatFmtBytes(bytes) {
            const n = Number(bytes || 0);
            if (!Number.isFinite(n) || n <= 0) return '';
            if (n < 1024) return `${n} B`;
            if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
            return `${(n / (1024 * 1024)).toFixed(1)} MB`;
        }

        function smxChatSetComposerState() {
            const st = window.__smxChatState;
            const send = document.getElementById('notifChatSend');
            const attach = document.getElementById('notifChatAttachBtn');
            const input = document.getElementById('notifChatInput');
            const mic = document.getElementById('notifChatMicBtn');
            const recording = !!st.mediaRecorder;
            const blocked = !!st.uploading;
            if (send) {
                send.disabled = blocked || recording;
                send.style.opacity = send.disabled ? '.65' : '1';
                send.textContent = blocked ? 'Subiendo...' : 'Enviar';
            }
            if (attach) {
                attach.disabled = blocked || recording;
                attach.style.opacity = attach.disabled ? '.65' : '1';
                attach.style.cursor = attach.disabled ? 'not-allowed' : 'pointer';
            }
            if (input) {
                input.disabled = blocked;
                input.placeholder = blocked ? 'Subiendo archivo...' : (recording ? 'Grabando audio... vuelve a pulsar micrófono para enviar' : 'Escribe un mensaje...');
            }
            if (mic) {
                mic.disabled = blocked;
                mic.style.opacity = mic.disabled ? '.65' : '1';
                mic.style.background = recording ? 'rgba(239,68,68,.22)' : 'rgba(148,163,184,.22)';
                mic.style.color = recording ? '#f87171' : 'var(--label-color)';
                mic.style.cursor = mic.disabled ? 'not-allowed' : 'pointer';
            }
        }

        function smxChatRenderTyping() {
            const el = document.getElementById('notifChatTyping');
            if (!el) return;
            const st = window.__smxChatState;
            if (!st.selected) {
                el.textContent = '';
                return;
            }
            el.textContent = st.peerTyping ? 'escribiendo...' : '';
        }

        function smxChatIsDeveloperSupportSelected() {
            const st = window.__smxChatState;
            return !!(st.selected && st.selected.is_developer_support);
        }

        async function smxChatStageDeveloperReply() {
            const st = window.__smxChatState;
            if (!smxChatIsDeveloperSupportSelected()) {
                await smxChatLoadThread().catch(() => {});
                await smxChatLoadUsers().catch(() => {});
                return;
            }
            st.peerTyping = true;
            smxChatRenderTyping();
            await new Promise((resolve) => setTimeout(resolve, 2200));
            await smxChatLoadThread().catch(() => {});
            await smxChatLoadUsers().catch(() => {});
        }

        async function smxChatApi(url, options = {}) {
            const method = String((options && options.method) || 'GET').toUpperCase();
            const maxAttempts = method === 'GET' ? 3 : 1;
            let lastError = null;

            for (let attempt = 1; attempt <= maxAttempts; attempt++) {
                const controller = new AbortController();
                const timeoutMs = method === 'GET' ? 15000 : 15000;
                const timer = setTimeout(() => {
                    try { controller.abort(new DOMException('Chat request timeout', 'AbortError')); }
                    catch (_) { controller.abort(); }
                }, timeoutMs);

                try {
                    const res = await fetch(url, {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        ...options,
                        signal: controller.signal
                    });
                    if (!res.ok) {
                        throw new Error(`HTTP ${res.status}`);
                    }
                    const data = await res.json();
                    clearTimeout(timer);
                    return data;
                } catch (err) {
                    clearTimeout(timer);
                    lastError = err;
                    if (attempt >= maxAttempts) break;
                    await new Promise((r) => setTimeout(r, 350 * attempt));
                }
            }

            const msg = String((lastError && lastError.message) || '').toLowerCase();
            if (lastError && (lastError.name === 'AbortError' || msg.includes('aborted') || msg.includes('abort'))) {
                throw new Error('Tiempo de espera agotado');
            }
            throw lastError || new Error('Error de red');
        }

        function smxExtractJsonPayload(raw) {
            const text = String(raw || '').trim();
            if (!text) return {};
            try {
                return JSON.parse(text);
            } catch (_) {}
            const startObj = text.indexOf('{');
            const endObj = text.lastIndexOf('}');
            if (startObj !== -1 && endObj > startObj) {
                try {
                    return JSON.parse(text.slice(startObj, endObj + 1));
                } catch (_) {}
            }
            const startArr = text.indexOf('[');
            const endArr = text.lastIndexOf(']');
            if (startArr !== -1 && endArr > startArr) {
                return JSON.parse(text.slice(startArr, endArr + 1));
            }
            throw new Error(text.replace(/\s+/g, ' ').trim().slice(0, 180) || 'Respuesta invalida del servidor');
        }

        function smxReadBlobAsDataUrl(blob) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = (e) => resolve(String(e?.target?.result || ''));
                reader.onerror = () => reject(new Error('No se pudo leer la imagen'));
                reader.readAsDataURL(blob);
            });
        }

        function smxLoadImageElement(src) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => resolve(img);
                img.onerror = () => reject(new Error('No se pudo procesar la imagen'));
                img.src = src;
            });
        }

        function smxCanvasToBlob(canvas, type, quality) {
            return new Promise((resolve, reject) => {
                canvas.toBlob((blob) => {
                    if (blob) resolve(blob);
                    else reject(new Error('No se pudo generar la imagen'));
                }, type, quality);
            });
        }

        async function smxOptimizeAvatarFile(file) {
            if (!file || !String(file.type || '').startsWith('image/')) {
                throw new Error('Archivo de imagen invalido');
            }
            if (Number(file.size || 0) <= 1400 * 1024) {
                return file;
            }

            const dataUrl = await smxReadBlobAsDataUrl(file);
            const img = await smxLoadImageElement(dataUrl);
            const maxSide = 960;
            const scale = Math.min(1, maxSide / Math.max(img.naturalWidth || img.width || 1, img.naturalHeight || img.height || 1));
            const width = Math.max(1, Math.round((img.naturalWidth || img.width || 1) * scale));
            const height = Math.max(1, Math.round((img.naturalHeight || img.height || 1) * scale));
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            if (!ctx) {
                throw new Error('No se pudo optimizar la imagen');
            }
            ctx.drawImage(img, 0, 0, width, height);

            let quality = 0.86;
            let out = await smxCanvasToBlob(canvas, 'image/jpeg', quality);
            while (out.size > 1400 * 1024 && quality > 0.45) {
                quality -= 0.08;
                out = await smxCanvasToBlob(canvas, 'image/jpeg', quality);
            }

            return new File([out], String(file.name || 'avatar').replace(/\.[a-z0-9]+$/i, '') + '.jpg', { type: 'image/jpeg' });
        }

        async function smxProfileUploadAvatar(file) {
            if (!file) return;
            const optimizedFile = await smxOptimizeAvatarFile(file);
            const fd = new FormData();
            fd.append('action', 'upload');
            fd.append('id_login', String(<?= (int)$id_login ?>));
            fd.append('avatar', optimizedFile);
            const res = await fetch('/public/usuarios/api/avatar.php', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
            });
            const raw = await res.text();
            try {
                const data = smxExtractJsonPayload(raw);
                if (!res.ok || !data.ok) {
                    throw new Error(String(data.error || `HTTP ${res.status}`));
                }
                const img = document.getElementById('notifProfileAvatarImg');
                if (img) {
                    img.src = `/public/usuarios/api/avatar.php?action=view&id_login=<?= (int)$id_login ?>&v=${Date.now()}`;
                }
                return;
            } catch (error) {
                if (res.status === 413) {
                    throw new Error('La imagen excede el tamaño permitido');
                }
                throw error;
            }
        }

        function smxChatToggleEmojiPanel(forceOpen = null) {
            const panel = document.getElementById('notifChatEmojiPanel');
            const btn = document.getElementById('notifChatEmojiBtn');
            if (!panel) return;
            const willOpen = (forceOpen === null) ? panel.style.display !== 'block' : !!forceOpen;
            panel.style.display = willOpen ? 'block' : 'none';
            if (btn) {
                btn.style.background = willOpen ? 'rgba(245,158,11,.35)' : 'rgba(245,158,11,.2)';
            }
        }

        function smxChatInsertEmoji(emoji) {
            const input = document.getElementById('notifChatInput');
            if (!input) return;
            const start = Number.isInteger(input.selectionStart) ? input.selectionStart : input.value.length;
            const end = Number.isInteger(input.selectionEnd) ? input.selectionEnd : input.value.length;
            const before = input.value.slice(0, start);
            const after = input.value.slice(end);
            input.value = before + emoji + after;
            const pos = start + emoji.length;
            input.focus();
            input.setSelectionRange(pos, pos);
            smxChatNotifyTyping(true).catch(() => {});
        }

        function smxBellSetTab(tab) {
            const pChat = document.getElementById('notifBellPanelChat');
            const pNoti = document.getElementById('notifBellPanelNoti');
            const bChat = document.getElementById('notifTabChat');
            const bNoti = document.getElementById('notifTabNoti');
            const isChat = tab === 'chat';
            if (pChat) pChat.style.display = isChat ? 'block' : 'none';
            if (pNoti) pNoti.style.display = isChat ? 'none' : 'block';
            if (bChat) {
                bChat.style.borderColor = isChat ? 'rgba(16,185,129,.45)' : 'rgba(148,163,184,.35)';
                bChat.style.background = isChat ? 'rgba(16,185,129,.18)' : 'transparent';
                bChat.style.color = isChat ? '#10b981' : 'var(--label-color)';
            }
            if (bNoti) {
                bNoti.style.borderColor = !isChat ? 'rgba(59,130,246,.45)' : 'rgba(148,163,184,.35)';
                bNoti.style.background = !isChat ? 'rgba(59,130,246,.18)' : 'transparent';
                bNoti.style.color = !isChat ? '#60a5fa' : 'var(--label-color)';
            }
        }

        function smxChatIsMobileViewport() {
            return window.matchMedia('(max-width: 900px)').matches;
        }

        function smxChatSetMobileView(view) {
            const layout = document.getElementById('notifChatLayout');
            if (!layout) return;
            const st = window.__smxChatState;
            const isMobileView = smxChatIsMobileViewport();
            if (!isMobileView) {
                st.mobileView = 'split';
                layout.setAttribute('data-mobile-view', 'split');
                return;
            }
            const requested = view === 'thread' ? 'thread' : 'users';
            const safeView = (requested === 'thread' && st.selected) ? 'thread' : 'users';
            st.mobileView = safeView;
            layout.setAttribute('data-mobile-view', safeView);
        }

        async function smxOpenChatPeer(peerLogin) {
            const dd = document.getElementById('notifBellDropdown');
            if (dd && dd.style.display !== 'block') {
                dd.style.display = 'block';
            }
            smxBellSetTab('chat');
            smxChatSetMobileView(smxChatIsMobileViewport() ? 'users' : 'split');
            await smxChatLoadUsers({ append: false }).catch(() => {});
            await smxChatSelect(Number(peerLogin || 0)).catch(() => {});
        }

        function smxChatShowUnreadBubbles(prevUsers, nextUsers) {
            if (smxBellIsOpen()) return;
            const prevMap = new Map((Array.isArray(prevUsers) ? prevUsers : []).map((u) => [Number(u.id_login || 0), Number(u.unread_count || 0)]));
            smxWithNotificationContainer((container) => {
                (Array.isArray(nextUsers) ? nextUsers : []).forEach((u) => {
                    const peerLogin = Number(u.id_login || 0);
                    if (peerLogin <= 0) return;
                    const unreadNow = Number(u.unread_count || 0);
                    const unreadPrev = Number(prevMap.get(peerLogin) || 0);
                    if (unreadNow <= unreadPrev || unreadNow <= 0) return;
                    const lastMessage = String(u.last_message || 'Nuevo mensaje').trim();
                    const popupKey = `chat|${peerLogin}|${lastMessage}|${unreadNow}`;
                    container.show(
                        u.usuario_nombre || 'Nuevo mensaje',
                        lastMessage,
                        'info',
                        0,
                        { popupKey, action: 'open-chat', peerLogin }
                    );
                });
            });
        }

        function smxChatReplayUnreadBubbles(users) {
            if (smxBellIsOpen()) return;
            smxWithNotificationContainer((container) => {
                (Array.isArray(users) ? users : []).forEach((u) => {
                    const peerLogin = Number(u.id_login || 0);
                    const unreadNow = Number(u.unread_count || 0);
                    if (peerLogin <= 0 || unreadNow <= 0) return;
                    const lastMessage = String(u.last_message || 'Nuevo mensaje').trim();
                    const popupKey = `chat-replay|${peerLogin}|${lastMessage}|${unreadNow}`;
                    container.show(
                        u.usuario_nombre || 'Nuevo mensaje',
                        lastMessage,
                        'info',
                        0,
                        { popupKey, action: 'open-chat', peerLogin }
                    );
                });
            });
        }

        function smxChatSyncResponsiveLayout() {
            const st = window.__smxChatState;
            if (smxChatIsMobileViewport()) {
                if (st.mobileView !== 'thread') {
                    smxChatSetMobileView('users');
                } else {
                    smxChatSetMobileView('thread');
                }
                return;
            }
            smxChatSetMobileView('split');
        }

        function smxChatRenderUsers() {
            const box = document.getElementById('notifChatUsers');
            const totals = document.getElementById('notifChatTotals');
            const cta = document.getElementById('notifSubscriptionChatCta');
            if (!box) return;
            const st = window.__smxChatState;
            const users = Array.isArray(st.users) ? st.users : [];
            if (cta) {
                cta.innerHTML = '';
            }
            if (totals) {
                totals.innerHTML = `
                    <span style="display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:999px;background:rgba(59,130,246,.14);color:#60a5fa;border:1px solid rgba(59,130,246,.22);">
                        <strong style="font-size:11px;">Empresas:</strong> <span>${Number(st.totalCompanies || 0)}</span>
                    </span>
                    <span style="display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:999px;background:rgba(16,185,129,.14);color:#34d399;border:1px solid rgba(16,185,129,.22);">
                        <strong style="font-size:11px;">Usuarios:</strong> <span>${Number(st.totalUsers || 0)}</span>
                    </span>
                `;
            }
            if (users.length === 0) {
                box.innerHTML = '<div style="padding:12px; font-size:12px; opacity:.7;">Sin usuarios</div>';
                return;
            }
            box.innerHTML = users.map((u) => {
                const active = st.selected && Number(st.selected.id_login) === Number(u.id_login);
                const avatar = u.avatar_url
                    ? `<img src="${smxEsc(u.avatar_url)}" style="width:60px;height:60px;border-radius:999px;object-fit:cover;border:1px solid rgba(148,163,184,.35);">`
                    : `<div style="width:60px;height:60px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;background:rgba(148,163,184,.25);">${smxEsc(u.avatar_initials || 'U')}</div>`;
                const logo = u.empresa_logo ? `<img src="${smxEsc(u.empresa_logo)}" style="position:absolute;right:-2px;bottom:-2px;width:23px;height:23px;border-radius:999px;border:1px solid rgba(148,163,184,.35);object-fit:cover;background:#fff;">` : '';
                return `
                    <button type="button" data-peer="${Number(u.id_login)}" class="smx-chat-user" style="width:100%;text-align:left;padding:7px;border:none;border-radius:10px;margin-bottom:4px;cursor:pointer;background:${active ? 'rgba(59,130,246,.16)' : 'transparent'};">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="position:relative;width:60px;height:60px;flex:0 0 60px;">${avatar}${logo}</div>
                            <div style="min-width:0;flex:1;">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">
                                    <div style="font-size:12px;font-weight:700;color:var(--label-color);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${smxEsc(u.usuario_nombre || '')}</div>
                                    ${u.unread_count > 0 ? `<span style="min-width:17px;height:17px;border-radius:999px;background:#22c55e;color:#fff;font-size:10px;font-weight:700;line-height:17px;text-align:center;padding:0 4px;">${Number(u.unread_count)}</span>` : ''}
                                </div>
                                <div style="font-size:11px;opacity:.75;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${smxEsc(u.last_message || u.empresa_nombre || '')}</div>
                            </div>
                        </div>
                    </button>
                `;
            }).join('');

            box.querySelectorAll('.smx-chat-user').forEach((btn) => {
                btn.addEventListener('click', () => smxChatSelect(Number(btn.dataset.peer || 0)));
            });
            const ctaBox = document.getElementById('notifSubscriptionChatCta');
            if (ctaBox) {
                ctaBox.querySelectorAll('.smx-chat-staff-user').forEach((btn) => {
                    btn.addEventListener('click', () => smxChatSelect(Number(btn.dataset.peer || 0)));
                });
            }
        }

        function smxChatRenderThread() {
            const hdr = document.getElementById('notifChatHeader');
            const box = document.getElementById('notifChatThread');
            if (!hdr || !box) return;
            const st = window.__smxChatState;
            if (!st.selected) {
                hdr.textContent = 'Selecciona un usuario';
                box.innerHTML = '<div style="padding:12px; font-size:12px; opacity:.7;">Sin conversación seleccionada</div>';
                smxChatRenderTyping();
                return;
            }
            hdr.textContent = `${st.selected.usuario_nombre || ''} · ${st.selected.empresa_nombre || ''}`;
            smxChatRenderTyping();
            const msgs = Array.isArray(st.messages) ? st.messages : [];
            if (msgs.length === 0) {
                box.innerHTML = '<div style="padding:12px; font-size:12px; opacity:.7;">Sin mensajes</div>';
                return;
            }
            const me = Number(<?= (int)$id_login ?>);
            box.innerHTML = msgs.map((m) => {
                const mine = Number(m.from_login) === me;
                const tipo = String(m.tipo || 'text').toLowerCase();
                const mediaUrl = String(m.media_url || '');
                const mediaName = String(m.media_name || 'archivo');
                const mediaSize = smxChatFmtBytes(m.media_size);
                const avatarUrl = String(m.from_avatar_url || '');
                const avatarInitials = String(m.from_avatar_initials || 'U');
                const logoUrl = String(m.from_empresa_logo_url || '');
                const avatarBlock = `
                    <div style="position:relative; width:49px; height:49px; flex:0 0 49px;">
                        ${avatarUrl
                            ? `<img src="${smxEsc(avatarUrl)}" style="width:49px; height:49px; border-radius:999px; object-fit:cover; border:1px solid rgba(148,163,184,.35);">`
                            : `<div style="width:49px; height:49px; border-radius:999px; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; background:rgba(148,163,184,.25);">${smxEsc(avatarInitials)}</div>`
                        }
                        ${logoUrl ? `<img src="${smxEsc(logoUrl)}" style="position:absolute; right:-2px; bottom:-2px; width:19px; height:19px; border-radius:999px; border:1px solid rgba(148,163,184,.35); object-fit:cover; background:#fff;">` : ''}
                    </div>
                `;
                let body = `<div style="font-size:12px; white-space:pre-wrap; word-break:break-word;">${smxEsc(m.mensaje || '')}</div>`;
                if (tipo === 'image' && mediaUrl) {
                    body = `<div style="display:flex;flex-direction:column;gap:6px;">
                        <a href="${smxEsc(mediaUrl)}" target="_blank" rel="noopener noreferrer">
                            <img src="${smxEsc(mediaUrl)}" alt="${smxEsc(mediaName)}" style="max-width:100%; max-height:240px; border-radius:8px; border:1px solid rgba(148,163,184,.3); object-fit:cover;">
                        </a>
                        ${m.mensaje ? `<div style="font-size:12px; white-space:pre-wrap; word-break:break-word;">${smxEsc(m.mensaje)}</div>` : ''}
                    </div>`;
                } else if (tipo === 'video' && mediaUrl) {
                    body = `<div style="display:flex;flex-direction:column;gap:6px;">
                        <video controls preload="metadata" style="max-width:100%; max-height:240px; border-radius:8px; border:1px solid rgba(148,163,184,.3);">
                            <source src="${smxEsc(mediaUrl)}">
                        </video>
                        <div style="font-size:11px; opacity:.8;">${smxEsc(mediaName)}${mediaSize ? ` · ${smxEsc(mediaSize)}` : ''}</div>
                        ${m.mensaje ? `<div style="font-size:12px; white-space:pre-wrap; word-break:break-word;">${smxEsc(m.mensaje)}</div>` : ''}
                    </div>`;
                } else if (tipo === 'audio' && mediaUrl) {
                    body = `<div style="display:flex;flex-direction:column;gap:6px;">
                        <audio controls preload="metadata" style="width:100%; height:34px;">
                            <source src="${smxEsc(mediaUrl)}">
                        </audio>
                        <div style="font-size:11px; opacity:.8;">${smxEsc(mediaName)}${mediaSize ? ` · ${smxEsc(mediaSize)}` : ''}</div>
                        ${m.mensaje ? `<div style="font-size:12px; white-space:pre-wrap; word-break:break-word;">${smxEsc(m.mensaje)}</div>` : ''}
                    </div>`;
                } else if (tipo === 'file' && mediaUrl) {
                    const mime = String(m.media_mime || '').toLowerCase();
                    const isPdf = mime.includes('pdf') || mediaName.toLowerCase().endsWith('.pdf');
                    body = `<div style="display:flex;flex-direction:column;gap:6px;">
                        <a href="${smxEsc(mediaUrl)}" target="_blank" rel="noopener noreferrer" style="font-size:12px; font-weight:700; color:#60a5fa; text-decoration:none;">${isPdf ? '📄' : '📎'} ${smxEsc(mediaName)}</a>
                        ${mediaSize ? `<div style="font-size:11px; opacity:.8;">${smxEsc(mediaSize)}</div>` : ''}
                        ${m.mensaje ? `<div style="font-size:12px; white-space:pre-wrap; word-break:break-word;">${smxEsc(m.mensaje)}</div>` : ''}
                    </div>`;
                }
                let check = '';
                if (mine) {
                    if (m.read_at) {
                        check = '<span title="Leído" style="font-size:10px; color:#60a5fa;">✓✓</span>';
                    } else if (m.delivered_at) {
                        check = '<span title="Entregado" style="font-size:10px; color:rgba(148,163,184,.95);">✓✓</span>';
                    } else {
                        check = '<span title="Enviado" style="font-size:10px; color:rgba(148,163,184,.95);">✓</span>';
                    }
                }
                return `
                    <div style="display:flex;${mine ? 'justify-content:flex-end;' : 'justify-content:flex-start;'}margin-bottom:8px;">
                        <div style="max-width:82%; display:flex; align-items:flex-end; gap:6px; ${mine ? 'flex-direction:row-reverse;' : 'flex-direction:row;'}">
                            ${avatarBlock}
                            <div style="max-width:100%;padding:7px 9px;border-radius:10px;${mine ? 'background:rgba(16,185,129,.22);' : 'background:rgba(148,163,184,.2);'}">
                                ${body}
                                <div style="font-size:10px;opacity:.65;margin-top:3px;text-align:right;display:flex;align-items:center;justify-content:flex-end;gap:4px;">
                                    <span>${smxEsc(m.sent_at || '')}</span>
                                    ${check}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
            box.scrollTop = box.scrollHeight;
        }

        async function smxChatLoadUsers(opts = {}) {
            const st = window.__smxChatState;
            const append = !!opts.append;
            const limit = Math.max(1, Math.min(50, Number(opts.limit || (append ? 3 : 10))));
            if (st.usersLoading) return;
            if (append && !st.usersHasMore) return;
            st.usersLoading = true;
            try {
                const prevUsers = Array.isArray(st.users) ? st.users.slice() : [];
                const q = encodeURIComponent(st.q || '');
                const offset = append ? Number(st.usersOffset || 0) : 0;
                const data = await smxChatApi(`/public/menu/api/chat.php?action=users&q=${q}&offset=${offset}&limit=${limit}&_ts=${Date.now()}`);
                const incoming = Array.isArray(data?.items) ? data.items : [];
                if (append) {
                    const map = new Map((st.users || []).map((u) => [Number(u.id_login), u]));
                    incoming.forEach((u) => map.set(Number(u.id_login), u));
                    st.users = Array.from(map.values());
                } else {
                    st.users = incoming;
                }
                st.usersPollAt = Date.now();
                st.usersOffset = Number(data?.next_offset || st.users.length || 0);
                st.usersHasMore = !!data?.has_more;
                st.developerChat = data?.developer_chat || null;
                st.subscriptionChat = data?.subscription_chat || null;
                st.totalUsers = Number(data?.total_users || 0);
                st.totalCompanies = Number(data?.total_companies || 0);
                const prevUnread = (st.lastUnreadTotal === null) ? null : Number(st.lastUnreadTotal || 0);
                st.unreadTotal = Number(data?.unread_total || 0);
                st.lastUnreadTotal = st.unreadTotal;
                if (prevUnread !== null && st.unreadTotal > prevUnread) {
                    smxBellPlayRadar();
                }
                if (st.usersBootstrapped) {
                    smxChatShowUnreadBubbles(prevUsers, st.users);
                } else if (st.unreadTotal > 0) {
                    smxChatReplayUnreadBubbles(st.users);
                }
                st.usersBootstrapped = true;
                if (st.selected) {
                    const refreshSelected = st.users.find((u) => Number(u.id_login) === Number(st.selected.id_login));
                    if (refreshSelected) {
                        st.selected = refreshSelected;
                        st.peerTyping = !!refreshSelected.peer_typing;
                    }
                }
                smxChatRenderUsers();
                smxRenderBell();
                smxChatRenderTyping();
                if (!st.selected && st.users.length > 0) {
                    st.selected = st.users[0];
                    st.peerTyping = !!st.selected.peer_typing;
                    await smxChatLoadThread().catch(() => {});
                }
            } catch (e) {
                // Error transitorio de red/API: no romper UI ni escalar excepción.
                if (!append && (!Array.isArray(st.users) || st.users.length === 0)) {
                    const box = document.getElementById('notifChatUsers');
                    if (box) {
                        box.innerHTML = '<div style="padding:12px; font-size:12px; opacity:.7;">Chat no disponible temporalmente. Reintentando...</div>';
                    }
                }
            } finally {
                st.usersLoading = false;
            }
        }

        async function smxChatLoadThread() {
            const st = window.__smxChatState;
            if (!st.selected) return;
            if (st.threadLoading) return;
            const peer = Number(st.selected.id_login || 0);
            if (!peer) return;
            st.threadLoading = true;
            try {
                const data = await smxChatApi(`/public/menu/api/chat.php?action=thread&peer_login=${peer}&limit=60&_ts=${Date.now()}`);
                st.messages = Array.isArray(data?.items) ? data.items : [];
                st.peerTyping = !!data?.peer_typing;
                smxChatRenderThread();
            } catch (e) {
                // Mantener último thread visible sin romper la experiencia.
            } finally {
                st.threadLoading = false;
            }
        }

        async function smxChatSelect(peer) {
            const st = window.__smxChatState;
            const found = (st.users || []).find((u) => Number(u.id_login) === Number(peer));
            if (!found) return;
            st.selected = found;
            st.peerTyping = !!found.peer_typing;
            smxChatRenderUsers();
            smxChatSetMobileView('thread');
            await smxChatLoadThread().catch(() => {});
            await smxChatLoadUsers().catch(() => {});
        }

        async function smxChatNotifyTyping(isTyping) {
            const st = window.__smxChatState;
            if (!st.selected) return;
            const peer = Number(st.selected.id_login || 0);
            if (!peer) return;
            const now = Date.now();
            if (isTyping && (now - Number(st.typingPingAt || 0) < 1200)) return;
            if (isTyping) st.typingPingAt = now;
            try {
                await smxChatApi('/public/menu/api/chat.php?action=typing', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ peer_login: peer, is_typing: isTyping ? 1 : 0 }),
                });
            } catch (_) {}
        }

        async function smxChatSend() {
            const st = window.__smxChatState;
            const input = document.getElementById('notifChatInput');
            if (!input || !st.selected || st.uploading || st.mediaRecorder) return;
            const msg = String(input.value || '').trim();
            if (!msg) return;
            const peer = Number(st.selected.id_login || 0);
            await smxChatNotifyTyping(false);
            input.value = '';
            await smxChatApi('/public/menu/api/chat.php?action=send', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ peer_login: peer, mensaje: msg }),
            });
            await smxChatStageDeveloperReply().catch(() => {});
        }

        async function smxChatUploadFile(file, extra = {}) {
            const st = window.__smxChatState;
            if (!st.selected) return;
            const peer = Number(st.selected.id_login || 0);
            if (!peer || !file) return;
            st.uploading = true;
            smxChatSetComposerState();
            const input = document.getElementById('notifChatInput');
            const caption = String(input && input.value ? input.value : '').trim();
            const fd = new FormData();
            fd.append('peer_login', String(peer));
            fd.append('mensaje', caption);
            fd.append('file', file);
            if (extra.durationSec && Number(extra.durationSec) > 0) {
                fd.append('duration_sec', String(Number(extra.durationSec)));
            }
            try {
                await smxChatNotifyTyping(false);
                const res = await smxChatApi('/public/menu/api/chat.php?action=upload', {
                    method: 'POST',
                    body: fd,
                });
                if (!res || !res.success) {
                    throw new Error(res && res.error ? res.error : 'No se pudo subir el archivo');
                }
                if (input) input.value = '';
                await smxChatStageDeveloperReply().catch(() => {});
            } catch (e) {
                alert((e && e.message) ? e.message : 'Error al subir archivo');
            } finally {
                st.uploading = false;
                smxChatSetComposerState();
            }
        }

        async function smxChatOnPickFile(ev) {
            const file = ev && ev.target && ev.target.files && ev.target.files[0] ? ev.target.files[0] : null;
            if (!file) return;
            try {
                await smxChatUploadFile(file);
            } finally {
                if (ev && ev.target) ev.target.value = '';
            }
        }

        async function smxChatToggleMic() {
            const st = window.__smxChatState;
            if (st.uploading) return;
            if (st.mediaRecorder) {
                try {
                    st.mediaRecorder.stop();
                } catch (_) {}
                return;
            }
            if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function' || typeof window.MediaRecorder === 'undefined') {
                alert('Tu navegador no soporta grabación de voz');
                return;
            }
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                st.mediaStream = stream;
                st.mediaChunks = [];
                const rec = new MediaRecorder(stream);
                st.mediaRecorder = rec;
                st.recStartMs = Date.now();
                rec.ondataavailable = (e) => {
                    if (e.data && e.data.size > 0) st.mediaChunks.push(e.data);
                };
                rec.onerror = () => {
                    alert('No se pudo grabar el audio');
                };
                rec.onstop = async () => {
                    try {
                        const chunks = Array.isArray(st.mediaChunks) ? st.mediaChunks : [];
                        const blob = new Blob(chunks, { type: rec.mimeType || 'audio/webm' });
                        const durationSec = Math.max(1, Math.round((Date.now() - (st.recStartMs || Date.now())) / 1000));
                        const ext = (rec.mimeType || '').includes('ogg') ? 'ogg' : ((rec.mimeType || '').includes('mp4') ? 'm4a' : 'webm');
                        const file = new File([blob], `voz_${Date.now()}.${ext}`, { type: rec.mimeType || 'audio/webm' });
                        if (file.size > 0) {
                            await smxChatUploadFile(file, { durationSec });
                        }
                    } catch (e) {
                        alert((e && e.message) ? e.message : 'No se pudo enviar el audio');
                    } finally {
                        if (st.mediaStream) {
                            try { st.mediaStream.getTracks().forEach((t) => t.stop()); } catch (_) {}
                        }
                        st.mediaStream = null;
                        st.mediaRecorder = null;
                        st.mediaChunks = [];
                        st.recStartMs = 0;
                        smxChatSetComposerState();
                    }
                };
                rec.start();
                smxChatSetComposerState();
            } catch (e) {
                alert((e && e.message) ? e.message : 'Permiso de micrófono denegado');
                if (st.mediaStream) {
                    try { st.mediaStream.getTracks().forEach((t) => t.stop()); } catch (_) {}
                }
                st.mediaStream = null;
                st.mediaRecorder = null;
                st.mediaChunks = [];
                st.recStartMs = 0;
                smxChatSetComposerState();
            }
        }

        function smxChatStartPolling() {
            const st = window.__smxChatState;
            if (st.pollTimer) clearInterval(st.pollTimer);
            st.pollTimer = setInterval(async () => {
                if (st.pollInFlight) return;
                st.pollInFlight = true;
                try {
                    const now = Date.now();
                    const toLoad = Math.max(10, (st.users && st.users.length ? st.users.length : 10));
                    const shouldRefreshUsers = !st.usersPollAt || ((now - st.usersPollAt) >= Number(st.usersPollEveryMs || 20000));
                    if (shouldRefreshUsers) {
                        await smxChatLoadUsers({ append: false, limit: toLoad });
                    }
                    if (st.selected) await smxChatLoadThread();
                } catch (_) {}
                finally {
                    st.pollInFlight = false;
                }
            }, 7000);
        }

        function smxRenderBell() {
            const badge = document.getElementById('notifBellBadge');
            const list = document.getElementById('notifBellList');
            const wrap = document.getElementById('notifBellWrap');
            const searchInput = document.getElementById('notifBellSearch');
            if (!badge || !list) return;
            const items = Array.isArray(window.__smxBellState.items) ? window.__smxBellState.items : [];
            if (wrap) {
                wrap.style.display = 'block';
            }
            const unreadNoti = items.filter(i => !i.read).length;
            const unreadChat = Number(window.__smxChatState && window.__smxChatState.unreadTotal ? window.__smxChatState.unreadTotal : 0);
            const unread = unreadNoti + unreadChat;
            if (unread > 0) {
                badge.style.display = 'inline-block';
                badge.textContent = unread > 99 ? '99+' : String(unread);
            } else {
                badge.style.display = 'none';
                badge.textContent = '0';
            }
            const q = String(searchInput && searchInput.value ? searchInput.value : '').trim().toLowerCase();
            const shown = q === '' ? items : items.filter((n) => {
                return String(n.title || '').toLowerCase().includes(q)
                    || String(n.message || '').toLowerCase().includes(q)
                    || String(n.origin_usuario_nombre || '').toLowerCase().includes(q)
                    || String(n.origin_empresa_nombre || '').toLowerCase().includes(q);
            });
            if (shown.length === 0) {
                list.innerHTML = '<div style="padding:14px; font-size:12px; opacity:.7;">Sin notificaciones pendientes</div>';
                return;
            }
            list.innerHTML = shown.map((n, idx) => `
                <button type="button" data-idx="${idx}" class="notif-item" style="width:100%; text-align:left; padding:9px; border:none; background:${n.read ? 'transparent' : 'rgba(16,185,129,0.10)'}; border-radius:12px; margin-bottom:6px; cursor:pointer;">
                    <div style="display:flex; align-items:flex-start; gap:8px;">
                        <div style="position:relative; width:36px; height:36px; flex:0 0 36px;">
                            ${n.origin_usuario_avatar ? `<img src="${String(n.origin_usuario_avatar)}" style="width:36px; height:36px; border-radius:999px; object-fit:cover; border:1px solid rgba(148,163,184,.35);">` : `<div style="width:36px; height:36px; border-radius:999px; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; background:rgba(148,163,184,.25);">${String(n.origin_usuario_initials || 'U')}</div>`}
                            ${n.origin_empresa_logo ? `<img src="${String(n.origin_empresa_logo)}" style="position:absolute; right:-2px; bottom:-2px; width:14px; height:14px; border-radius:999px; border:1px solid rgba(148,163,184,.35); object-fit:cover; background:#fff;">` : ''}
                        </div>
                        <div style="min-width:0; flex:1;">
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                                <div style="font-size:12px; font-weight:700; color:var(--label-color); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${String(n.origin_usuario_nombre || n.title || 'Notificación')}</div>
                                <div style="font-size:10px; opacity:.65; white-space:nowrap;">${String(n.time || '')}</div>
                            </div>
                            <div style="font-size:11px; opacity:.86; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${String(n.message || '')}</div>
                            <div style="display:flex; align-items:center; gap:6px; margin-top:3px;">
                                ${n.is_system ? '<span style="font-size:10px; padding:1px 6px; border-radius:999px; background:rgba(59,130,246,.18); color:#60a5fa;">Sistema</span>' : ''}
                                ${(!n.read) ? '<span style="margin-left:auto; min-width:18px; height:18px; border-radius:999px; background:#22c55e; color:#fff; font-size:10px; font-weight:700; line-height:18px; text-align:center;">1</span>' : ''}
                            </div>
                        </div>
                    </div>
                </button>
            `).join('');
            list.querySelectorAll('.notif-item').forEach(btn => {
                btn.addEventListener('click', () => {
                    const i = Number(btn.dataset.idx || -1);
                    const notif = shown[i];
                    if (!notif) return;
                    notif.read = true;
                    if (String(notif.id || '').startsWith('user_notif_')) {
                        smxBellMarkRead([notif]);
                    }
                    smxRenderBell();
                    smxBellSave();
                    smxBellOpenNotificationTarget(notif);
                });
            });
        }

        window.__smxBellPush = function(notifications) {
            const feed = Array.isArray(notifications) ? notifications : [];
            const existingItems = Array.isArray(window.__smxBellState.items) ? window.__smxBellState.items : [];
            const existingByKey = new Map(existingItems.map((item) => [String(item && item.key ? item.key : ''), item]));
            const items = [];
            feed.forEach((n) => {
                const key = `${n.id || 'n'}|${n.title || ''}|${n.message || ''}`;
                const existing = existingByKey.get(key) || null;
                const backendRead = !!n.read;
                if (existing) {
                    items.push({
                        ...existing,
                        key,
                        id: n.id || existing.id || key,
                        title: n.title || existing.title || 'Notificación',
                        message: n.message || existing.message || '',
                        type: n.type || existing.type || 'info',
                        url: n.url || existing.url || '',
                        time: n.time || existing.time || smxFormatTime(),
                        read: backendRead,
                        origin_empresa_nombre: n.origin_empresa_nombre || existing.origin_empresa_nombre || '',
                        origin_empresa_logo: n.origin_empresa_logo || existing.origin_empresa_logo || '',
                        origin_usuario_nombre: n.origin_usuario_nombre || existing.origin_usuario_nombre || '',
                        origin_usuario_avatar: n.origin_usuario_avatar || existing.origin_usuario_avatar || '',
                        origin_usuario_initials: n.origin_usuario_initials || existing.origin_usuario_initials || '',
                        is_system: !!n.is_system,
                    });
                    return;
                }
                items.push({
                    key,
                    id: n.id || key,
                    title: n.title || 'Notificación',
                    message: n.message || '',
                    type: n.type || 'info',
                    url: n.url || '',
                    time: n.time || smxFormatTime(),
                    read: backendRead,
                    origin_empresa_nombre: n.origin_empresa_nombre || '',
                    origin_empresa_logo: n.origin_empresa_logo || '',
                    origin_usuario_nombre: n.origin_usuario_nombre || '',
                    origin_usuario_avatar: n.origin_usuario_avatar || '',
                    origin_usuario_initials: n.origin_usuario_initials || '',
                    is_system: !!n.is_system,
                });
            });
            window.__smxBellState.items = items.slice(0, 80);
            smxRenderBell();
            smxBellSave();
        };

        function hideSuscripcionBanner(remember = false) {
            const banner = document.getElementById('suscripcionBanner');
            if (banner) banner.style.display = 'none';
            if (!remember) return;
            try {
                const oneDayMs = 24 * 60 * 60 * 1000;
                const payload = {
                    hiddenUntil: Date.now() + oneDayMs
                };
                localStorage.setItem(SUSCRIPCION_BANNER_HIDE_KEY, JSON.stringify(payload));
            } catch (_) {}
        }

        function initSuscripcionBannerVisibility() {
            const banner = document.getElementById('suscripcionBanner');
            if (!banner) return;
            try {
                const raw = localStorage.getItem(SUSCRIPCION_BANNER_HIDE_KEY);
                if (!raw) return;

                let hiddenUntil = 0;
                try {
                    const parsed = JSON.parse(raw);
                    hiddenUntil = Number(parsed && parsed.hiddenUntil ? parsed.hiddenUntil : 0);
                } catch (_) {
                    // Compatibilidad con formato viejo ('1'): ocultar por 24h desde ahora
                    hiddenUntil = Date.now() + (24 * 60 * 60 * 1000);
                    localStorage.setItem(SUSCRIPCION_BANNER_HIDE_KEY, JSON.stringify({ hiddenUntil }));
                }

                if (hiddenUntil > Date.now()) {
                    banner.style.display = 'none';
                    return;
                }

                localStorage.removeItem(SUSCRIPCION_BANNER_HIDE_KEY);
            } catch (_) {}
        }

        initSuscripcionBannerVisibility();

        document.addEventListener('DOMContentLoaded', () => {
            smxBellLoad();
            smxBellLoadRadarPref();
            smxBellSyncRadarToggle();
            setTimeout(smxBellReplayUnreadBubbles, 120);
            const armBellAudio = () => {
                window.__smxBellAudioArmed = true;
                try {
                    const Ctx = window.AudioContext || window.webkitAudioContext;
                    if (Ctx && !window.__smxBellAudioCtx) window.__smxBellAudioCtx = new Ctx();
                    if (window.__smxBellAudioCtx && window.__smxBellAudioCtx.state === 'suspended') {
                        window.__smxBellAudioCtx.resume();
                    }
                } catch (_) {}
            };
            window.addEventListener('pointerdown', armBellAudio, { once: true });
            const wrap = document.getElementById('notifBellWrap');
            if (wrap) {
                wrap.style.position = 'fixed';
                wrap.style.top = '10px';
                wrap.style.right = '14px';
                wrap.style.left = 'auto';
                wrap.style.transform = 'none';
                wrap.style.zIndex = '20000';
                wrap.style.display = 'block';
            }
            const btn = document.getElementById('notifBellBtn');
            const dd = document.getElementById('notifBellDropdown');
            const markRead = document.getElementById('notifBellMarkRead');
            const radarToggle = document.getElementById('notifBellRadarToggle');
            const closeBtn = document.getElementById('notifBellClose');
            const searchInput = document.getElementById('notifBellSearch');
            const tabChat = document.getElementById('notifTabChat');
            const tabNoti = document.getElementById('notifTabNoti');
            const chatSearch = document.getElementById('notifChatSearch');
            const chatSend = document.getElementById('notifChatSend');
            const chatInput = document.getElementById('notifChatInput');
            const chatAttachBtn = document.getElementById('notifChatAttachBtn');
            const chatEmojiBtn = document.getElementById('notifChatEmojiBtn');
            const chatEmojiPanel = document.getElementById('notifChatEmojiPanel');
            const chatEmojiList = document.getElementById('notifChatEmojiList');
            const chatMicBtn = document.getElementById('notifChatMicBtn');
            const chatFileInput = document.getElementById('notifChatFileInput');
            const chatBackBtn = document.getElementById('notifChatBackBtn');
            if (btn && dd) {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const willOpen = dd.style.display !== 'block';
                    dd.style.display = willOpen ? 'block' : 'none';
                    if (willOpen) {
                        smxBellSetTab('chat');
                        smxChatSetMobileView(smxChatIsMobileViewport() ? 'users' : 'split');
                        smxRenderBell();
                        smxChatLoadUsers().catch(() => {});
                    }
                });
                dd.addEventListener('click', (e) => {
                    e.stopPropagation();
                });
                dd.addEventListener('pointerdown', (e) => {
                    e.stopPropagation();
                });
                dd.addEventListener('mousedown', (e) => {
                    e.stopPropagation();
                });
                dd.addEventListener('touchstart', (e) => {
                    e.stopPropagation();
                }, { passive: true });
                document.addEventListener('click', (e) => {
                    if (!dd.contains(e.target) && !btn.contains(e.target)) {
                        dd.style.display = 'none';
                    }
                });
            }
            if (markRead) {
                markRead.addEventListener('click', async (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    (window.__smxBellState.items || []).forEach(it => { it.read = true; });
                    await smxBellMarkRead([], true);
                    smxRenderBell();
                    smxBellSave();
                });
            }
            if (radarToggle) {
                radarToggle.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    window.__smxBellRadarMuted = !window.__smxBellRadarMuted;
                    smxBellSaveRadarPref();
                    smxBellSyncRadarToggle();
                    if (!window.__smxBellRadarMuted) {
                        smxBellPlayRadar();
                    }
                });
            }
            if (searchInput) {
                searchInput.addEventListener('input', () => smxRenderBell());
            }
            if (closeBtn && dd) {
                closeBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    dd.style.display = 'none';
                });
            }
            if (chatBackBtn) {
                chatBackBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    smxChatSetMobileView('users');
                });
            }
            if (tabChat) {
                tabChat.addEventListener('click', () => {
                    smxBellSetTab('chat');
                    smxChatSetMobileView(smxChatIsMobileViewport() ? 'users' : 'split');
                });
            }
            if (tabNoti) tabNoti.addEventListener('click', () => smxBellSetTab('noti'));
            if (chatSearch) {
                chatSearch.addEventListener('input', () => {
                    window.__smxChatState.q = String(chatSearch.value || '');
                    window.__smxChatState.usersOffset = 0;
                    window.__smxChatState.usersHasMore = true;
                    smxChatLoadUsers({ append: false, limit: 10 }).catch(() => {});
                });
            }
            const usersBox = document.getElementById('notifChatUsers');
            if (usersBox) {
                usersBox.addEventListener('scroll', () => {
                    const st = window.__smxChatState;
                    if (st.usersLoading || !st.usersHasMore) return;
                    const remain = usersBox.scrollHeight - usersBox.scrollTop - usersBox.clientHeight;
                    if (remain <= 40) {
                        smxChatLoadUsers({ append: true, limit: 3 }).catch(() => {});
                    }
                });
            }
            if (chatSend) chatSend.addEventListener('click', () => smxChatSend().catch(() => {}));
            if (chatInput) {
                chatInput.addEventListener('input', () => {
                    smxChatNotifyTyping(true).catch(() => {});
                });
                chatInput.addEventListener('blur', () => {
                    smxChatNotifyTyping(false).catch(() => {});
                });
                chatInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        smxChatSend().catch(() => {});
                    }
                });
            }
            if (chatAttachBtn && chatFileInput) {
                chatAttachBtn.addEventListener('click', () => {
                    if (window.__smxChatState.uploading || window.__smxChatState.mediaRecorder) return;
                    chatFileInput.click();
                });
                chatFileInput.addEventListener('change', (e) => {
                    smxChatOnPickFile(e).catch(() => {});
                });
            }
            if (chatMicBtn) {
                chatMicBtn.addEventListener('click', () => {
                    smxChatToggleMic().catch(() => {});
                });
            }
            if (chatEmojiBtn && chatEmojiPanel && chatEmojiList) {
                const EMOJIS = ['😀','😁','😂','🤣','😊','😉','😍','😘','😎','🥳','🤝','🙏','👍','👎','👏','💪','🔥','🎉','💯','✅','❌','⚠️','💵','🧾','📦','📌','📞','📅','⌛','❤️'];
                chatEmojiList.innerHTML = EMOJIS.map((e) =>
                    `<button type="button" class="smx-chat-emoji" data-emoji="${e}" style="width:28px;height:28px;border:none;border-radius:8px;cursor:pointer;background:rgba(148,163,184,.15);font-size:16px;line-height:1;">${e}</button>`
                ).join('');
                chatEmojiBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    smxChatToggleEmojiPanel();
                });
                chatEmojiPanel.addEventListener('click', (e) => e.stopPropagation());
                chatEmojiList.querySelectorAll('.smx-chat-emoji').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        smxChatInsertEmoji(String(btn.dataset.emoji || ''));
                    });
                });
                if (chatInput) {
                    chatInput.addEventListener('focus', () => smxChatToggleEmojiPanel(false));
                }
            }
            smxRenderBell();
            if (window.__smxBellRadarTimer) {
                clearInterval(window.__smxBellRadarTimer);
            }
            window.__smxBellRadarTimer = setInterval(smxBellPlayRadar, 10000);
            smxChatLoadUsers({ append: false, limit: 10 }).catch(() => {});
            smxChatStartPolling();
            smxChatSetComposerState();
            smxChatSyncResponsiveLayout();
            window.addEventListener('resize', smxChatSyncResponsiveLayout);
            window.addEventListener('orientationchange', smxChatSyncResponsiveLayout);
        });

        // ==========================================
        // Menú Contextual (solo TI)
        // ==========================================
        <?php if ($usr_ti): ?>
            const contextMenu = document.getElementById('contextMenu');

            document.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                contextMenu.style.display = 'block';
                contextMenu.style.left = Math.min(e.pageX, window.innerWidth - 200) + 'px';
                contextMenu.style.top = Math.min(e.pageY, window.innerHeight - 100) + 'px';
            });

            document.addEventListener('click', function(e) {
                if (!contextMenu.contains(e.target)) {
                    contextMenu.style.display = 'none';
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    contextMenu.style.display = 'none';
                }
            });

            function openSifenConfig() {
                contextMenu.style.display = 'none';
                abrirApp('Conf. SIFEN', 'admin_empresa/editar_habilitacion_sifen.php?id_empresa=' + ID_EMPRESA);
            }

            function openFeDashboard() {
                contextMenu.style.display = 'none';
                abrirApp('Estado FE', 'public/empresa/fe_dashboard.php?id_empresa=' + ID_EMPRESA);
            }

            function openAdminEmpresas() {
                contextMenu.style.display = 'none';
                abrirApp('Admin Empresas', 'admin_empresa/empresa.php');
            }
        <?php endif; ?>

        // Estado de la app
        let appAbierta = false;
        const HOME_APPS_PER_PAGE = isMobile ? 20 : 24;
        let homeAppsCurrentPage = 1;
        let homeAppsTotalPages = 1;

        let currentAppLabel = ''; // Para guardar el nombre de la app actual
        let desktopOpenApps = [];
        let desktopActiveAppId = null;
        let desktopAppSeq = 0;
        let dockHideTimer = null;
        let desktopPinnedApps = [];
        let dockContextTarget = null;
        let currentMobileAppUrl = '';
        let embeddedLoginVisible = false;
        let mobileDockLongPressTimer = null;
        let mobileDockSuppressClickUntil = 0;
        let homeScreenHideTimer = null;
        let smxScreenShareActive = false;
        const DOCK_PINNED_APPS_KEY = `vcorta_dock_pinned_${ID_EMPRESA}_<?= (int)$id_login ?>`;
        const DOCK_AUTO_HIDE_KEY = `vcorta_dock_autohide_${ID_EMPRESA}_<?= (int)$id_login ?>`;
        const HOME_APPS_ORDER_KEY = `smx_home_apps_order_${ID_EMPRESA}_<?= (int)$id_login ?>`;
        const HOME_APPS_ORDER_ENDPOINT = <?= json_encode('/public/menu/menu.php?action=save_home_apps_order') ?>;
        const HOME_APPS_ORDER_INITIAL = <?= json_encode(array_values($savedHomeAppsOrder), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const FORCE_HOME_APPS_REFRESH = ID_EMPRESA === 169;
        let dockAutoHide = false; // Deprecado: dock siempre visible

        function initHomeAppsPagination() {
            const homeScreen = document.getElementById('homeScreen');
            const pager = document.getElementById('homeAppsPager');
            const dotsWrap = document.getElementById('homeAppsPagerDots');
            if (!homeScreen || !pager || !dotsWrap) return;

            const getCards = () => Array.from(homeScreen.querySelectorAll('.app-icon'));
            let draggedAppCard = null;
            let pagerTouchStartX = 0;
            let pagerTouchStartY = 0;
            let pagerTouchActive = false;
            let pageAnimationFrame = null;
            let pagerPulseDirection = 'none';
            let pagerPointerStartX = 0;
            let pagerPointerStartY = 0;
            let pagerPointerActive = false;
            let pagerDragTriggered = false;
            let pagerSuppressClickUntil = 0;
            let pagerCurrentOffsetX = 0;
            let reorderSuppressClickUntil = 0;
            let reorderEdgeTimer = null;
            let reorderEdgeDirection = null;

            const setPagerDragOffset = (deltaX) => {
                const limited = Math.max(-96, Math.min(96, deltaX * 0.42));
                pagerCurrentOffsetX = limited;
                homeScreen.style.transform = `translate3d(${limited}px, 0, 0)`;
            };

            const resetPagerDragOffset = () => {
                pagerCurrentOffsetX = 0;
                homeScreen.style.transform = 'translate3d(0, 0, 0)';
            };

            const clearDropMarkers = () => {
                getCards().forEach((card) => {
                    card.classList.remove('reorder-drop-before', 'reorder-drop-after');
                });
            };

            const clearReorderEdgePaging = () => {
                if (reorderEdgeTimer) {
                    clearTimeout(reorderEdgeTimer);
                    reorderEdgeTimer = null;
                }
                reorderEdgeDirection = null;
            };

            let saveHomeAppsOrderTimer = null;

            const saveHomeAppsOrder = () => {
                try {
                    const order = getCards()
                        .map((card) => String(card.dataset.appOrderKey || '').trim())
                        .filter(Boolean);
                    localStorage.setItem(HOME_APPS_ORDER_KEY, JSON.stringify(order));
                    if (saveHomeAppsOrderTimer) {
                        clearTimeout(saveHomeAppsOrderTimer);
                    }
                    saveHomeAppsOrderTimer = setTimeout(() => {
                        fetch(HOME_APPS_ORDER_ENDPOINT, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            credentials: 'same-origin',
                            body: JSON.stringify({ order })
                        }).catch(() => {});
                    }, 120);
                } catch (_) {}
            };

            const applySavedHomeAppsOrder = () => {
                try {
                    const raw = localStorage.getItem(HOME_APPS_ORDER_KEY);
                    const localOrder = raw ? JSON.parse(raw) : [];
                    const savedOrder = Array.isArray(HOME_APPS_ORDER_INITIAL) && HOME_APPS_ORDER_INITIAL.length
                        ? HOME_APPS_ORDER_INITIAL
                        : localOrder;
                    if (!Array.isArray(savedOrder) || !savedOrder.length) return;
                    const cards = getCards();
                    const map = new Map(cards.map((card) => [String(card.dataset.appOrderKey || ''), card]));
                    if (FORCE_HOME_APPS_REFRESH) {
                        const matchingKeys = savedOrder.filter((key) => map.has(String(key || ''))).length;
                        const requiresReset = matchingKeys < Math.max(3, cards.length - 1);
                        if (requiresReset) {
                            const freshOrder = cards
                                .map((card) => String(card.dataset.appOrderKey || '').trim())
                                .filter(Boolean);
                            localStorage.setItem(HOME_APPS_ORDER_KEY, JSON.stringify(freshOrder));
                            return;
                        }
                    }
                    const ordered = [];
                    savedOrder.forEach((key) => {
                        const card = map.get(String(key || ''));
                        if (card) {
                            ordered.push(card);
                            map.delete(String(key || ''));
                        }
                    });
                    map.forEach((card) => ordered.push(card));
                    ordered.forEach((card) => homeScreen.appendChild(card));
                    localStorage.setItem(HOME_APPS_ORDER_KEY, JSON.stringify(savedOrder));
                } catch (_) {}
            };

            const getCurrentPageSliceBounds = () => {
                const start = (homeAppsCurrentPage - 1) * HOME_APPS_PER_PAGE;
                const end = Math.min(getCards().length, start + HOME_APPS_PER_PAGE);
                return { start, end };
            };

            const queueEdgePageChange = (direction) => {
                if (!draggedAppCard) return;
                if (direction === 'prev' && homeAppsCurrentPage <= 1) return;
                if (direction === 'next' && homeAppsCurrentPage >= homeAppsTotalPages) return;
                if (reorderEdgeDirection === direction && reorderEdgeTimer) return;
                clearReorderEdgePaging();
                reorderEdgeDirection = direction;
                reorderEdgeTimer = setTimeout(() => {
                    const targetPage = direction === 'next' ? (homeAppsCurrentPage + 1) : (homeAppsCurrentPage - 1);
                    renderPage(targetPage, direction);
                    clearReorderEdgePaging();
                }, 380);
            };

            const renderDots = () => {
                homeAppsTotalPages = Math.max(1, Math.ceil(getCards().length / HOME_APPS_PER_PAGE));
                dotsWrap.innerHTML = '';
                for (let page = 1; page <= homeAppsTotalPages; page++) {
                    const dot = document.createElement('button');
                    dot.type = 'button';
                    const isActive = page === homeAppsCurrentPage;
                    const pulseClass = isActive && pagerPulseDirection !== 'none' ? ` pulse-${pagerPulseDirection}` : '';
                    dot.className = 'pager-dot' + (isActive ? ' active' : '') + pulseClass;
                    dot.setAttribute('aria-label', `Ir a la página ${page}`);
                    if (isActive) {
                        dot.setAttribute('aria-current', 'page');
                    }
                    dot.addEventListener('click', () => {
                        const direction = page > homeAppsCurrentPage ? 'next' : (page < homeAppsCurrentPage ? 'prev' : 'none');
                        renderPage(page, direction);
                    });
                    dotsWrap.appendChild(dot);
                }
                pagerPulseDirection = 'none';
            };

            const animateVisibleCards = (direction) => {
                if (pageAnimationFrame) {
                    cancelAnimationFrame(pageAnimationFrame);
                }
                if (direction !== 'next' && direction !== 'prev') return;
                const visibleCards = getCards().filter((card) => card.style.display !== 'none');
                const animationName = direction === 'next' ? 'homeAppsSlideInNext' : 'homeAppsSlideInPrev';
                pageAnimationFrame = requestAnimationFrame(() => {
                    visibleCards.forEach((card, index) => {
                        card.style.animation = 'none';
                        card.getBoundingClientRect();
                        card.style.animation = `${animationName} 260ms cubic-bezier(0.22, 1, 0.36, 1) forwards`;
                        card.style.animationDelay = `${index * 18}ms`;
                    });
                });
            };

            const renderPage = (page, direction = 'none') => {
                const cards = getCards();
                homeAppsTotalPages = Math.max(1, Math.ceil(cards.length / HOME_APPS_PER_PAGE));
                homeAppsCurrentPage = Math.min(homeAppsTotalPages, Math.max(1, page));
                pagerPulseDirection = direction;
                const start = (homeAppsCurrentPage - 1) * HOME_APPS_PER_PAGE;
                const end = start + HOME_APPS_PER_PAGE;
                cards.forEach((card, idx) => {
                    const visible = idx >= start && idx < end;
                    card.style.display = visible ? '' : 'none';
                    card.style.opacity = visible ? '1' : '';
                    card.style.visibility = visible ? 'visible' : '';
                });
                renderDots();
                animateVisibleCards(direction);
                homeScreen.scrollTop = 0;
            };

            const setupReorderableCards = () => {
                getCards().forEach((card) => {
                    card.draggable = true;
                    card.classList.add('reorder-draggable');

                    card.addEventListener('dragstart', (event) => {
                        if (appAbierta) {
                            event.preventDefault();
                            return;
                        }
                        draggedAppCard = card;
                        reorderSuppressClickUntil = Date.now() + 350;
                        card.classList.add('reorder-dragging');
                        if (event.dataTransfer) {
                            event.dataTransfer.effectAllowed = 'move';
                            event.dataTransfer.setData('text/plain', String(card.dataset.appOrderKey || ''));
                        }
                    });

                    card.addEventListener('dragend', () => {
                        draggedAppCard = null;
                        card.classList.remove('reorder-dragging');
                        clearDropMarkers();
                        clearReorderEdgePaging();
                    });

                    card.addEventListener('dragover', (event) => {
                        if (!draggedAppCard || draggedAppCard === card) return;
                        event.preventDefault();
                        const screenRect = homeScreen.getBoundingClientRect();
                        const edgeThreshold = Math.min(110, screenRect.width * 0.16);
                        if (event.clientX <= screenRect.left + edgeThreshold) {
                            queueEdgePageChange('prev');
                        } else if (event.clientX >= screenRect.right - edgeThreshold) {
                            queueEdgePageChange('next');
                        } else {
                            clearReorderEdgePaging();
                        }
                        clearDropMarkers();
                        const rect = card.getBoundingClientRect();
                        const before = event.clientY < rect.top + (rect.height / 2);
                        card.classList.add(before ? 'reorder-drop-before' : 'reorder-drop-after');
                    });

                    card.addEventListener('dragleave', () => {
                        if (!draggedAppCard) return;
                        card.classList.remove('reorder-drop-before', 'reorder-drop-after');
                    });

                    card.addEventListener('drop', (event) => {
                        if (!draggedAppCard || draggedAppCard === card) return;
                        event.preventDefault();
                        const rect = card.getBoundingClientRect();
                        const before = event.clientY < rect.top + (rect.height / 2);
                        if (before) {
                            homeScreen.insertBefore(draggedAppCard, card);
                        } else {
                            homeScreen.insertBefore(draggedAppCard, card.nextSibling);
                        }
                        clearDropMarkers();
                        saveHomeAppsOrder();
                        renderPage(homeAppsCurrentPage, 'none');
                    });
                });
            };

            applySavedHomeAppsOrder();
            setupReorderableCards();
            homeAppsTotalPages = Math.max(1, Math.ceil(getCards().length / HOME_APPS_PER_PAGE));

            if (homeAppsTotalPages <= 1) {
                pager.style.display = 'none';
                getCards().forEach((card) => { card.style.display = ''; });
                return;
            }

            homeScreen.classList.add('pager-draggable');

            homeScreen.addEventListener('dragover', (event) => {
                if (!draggedAppCard) return;
                event.preventDefault();
                const screenRect = homeScreen.getBoundingClientRect();
                const edgeThreshold = Math.min(110, screenRect.width * 0.16);
                if (event.clientX <= screenRect.left + edgeThreshold) {
                    queueEdgePageChange('prev');
                } else if (event.clientX >= screenRect.right - edgeThreshold) {
                    queueEdgePageChange('next');
                } else {
                    clearReorderEdgePaging();
                }
            });

            homeScreen.addEventListener('drop', (event) => {
                if (!draggedAppCard) return;
                event.preventDefault();
                clearReorderEdgePaging();
                clearDropMarkers();
                const targetCard = event.target.closest('.app-icon');
                if (targetCard) return;

                const cards = getCards();
                const { start, end } = getCurrentPageSliceBounds();
                const slice = cards.slice(start, end).filter((card) => card !== draggedAppCard);
                const screenRect = homeScreen.getBoundingClientRect();
                const insertAtStart = event.clientX < (screenRect.left + screenRect.width / 2);

                if (!slice.length) {
                    const anchor = cards[start] || null;
                    if (anchor) homeScreen.insertBefore(draggedAppCard, anchor);
                    else homeScreen.appendChild(draggedAppCard);
                } else if (insertAtStart) {
                    homeScreen.insertBefore(draggedAppCard, slice[0]);
                } else {
                    homeScreen.insertBefore(draggedAppCard, slice[slice.length - 1].nextSibling);
                }

                saveHomeAppsOrder();
                renderPage(homeAppsCurrentPage, 'none');
            });

            homeScreen.addEventListener('touchstart', (event) => {
                if (appAbierta || !event.touches || event.touches.length !== 1) return;
                const touch = event.touches[0];
                pagerTouchStartX = touch.clientX;
                pagerTouchStartY = touch.clientY;
                pagerTouchActive = true;
                homeScreen.classList.add('dragging');
            }, { passive: true });

            homeScreen.addEventListener('touchmove', (event) => {
                if (!pagerTouchActive || appAbierta || !event.touches || event.touches.length !== 1) return;
                const touch = event.touches[0];
                const deltaX = touch.clientX - pagerTouchStartX;
                const deltaY = touch.clientY - pagerTouchStartY;
                if (Math.abs(deltaX) > Math.abs(deltaY)) {
                    setPagerDragOffset(deltaX);
                }
            }, { passive: true });

            homeScreen.addEventListener('touchend', (event) => {
                if (!pagerTouchActive || appAbierta || !event.changedTouches || event.changedTouches.length !== 1) return;
                pagerTouchActive = false;
                homeScreen.classList.remove('dragging');
                const touch = event.changedTouches[0];
                const deltaX = touch.clientX - pagerTouchStartX;
                const deltaY = touch.clientY - pagerTouchStartY;
                const absX = Math.abs(deltaX);
                const absY = Math.abs(deltaY);

                if (absX < 50 || absX <= absY) {
                    resetPagerDragOffset();
                    return;
                }
                resetPagerDragOffset();
                if (deltaX < 0 && homeAppsCurrentPage < homeAppsTotalPages) {
                    renderPage(homeAppsCurrentPage + 1, 'next');
                } else if (deltaX > 0 && homeAppsCurrentPage > 1) {
                    renderPage(homeAppsCurrentPage - 1, 'prev');
                }
            }, { passive: true });

            homeScreen.addEventListener('touchcancel', () => {
                pagerTouchActive = false;
                homeScreen.classList.remove('dragging');
                resetPagerDragOffset();
            }, { passive: true });

            homeScreen.addEventListener('pointerdown', (event) => {
                if (appAbierta || event.pointerType === 'touch' || event.button !== 0) return;
                pagerPointerStartX = event.clientX;
                pagerPointerStartY = event.clientY;
                pagerPointerActive = true;
                pagerDragTriggered = false;
                homeScreen.classList.add('dragging');
            });

            homeScreen.addEventListener('pointermove', (event) => {
                if (!pagerPointerActive || appAbierta || event.pointerType === 'touch') return;
                const deltaX = event.clientX - pagerPointerStartX;
                const deltaY = event.clientY - pagerPointerStartY;
                if (Math.abs(deltaX) > 12 && Math.abs(deltaX) > Math.abs(deltaY)) {
                    pagerDragTriggered = true;
                    setPagerDragOffset(deltaX);
                }
            });

            homeScreen.addEventListener('pointerup', (event) => {
                if (!pagerPointerActive || appAbierta || event.pointerType === 'touch') return;
                const deltaX = event.clientX - pagerPointerStartX;
                const deltaY = event.clientY - pagerPointerStartY;
                const absX = Math.abs(deltaX);
                const absY = Math.abs(deltaY);
                pagerPointerActive = false;
                homeScreen.classList.remove('dragging');

                if (absX < 50 || absX <= absY) {
                    resetPagerDragOffset();
                    return;
                }
                resetPagerDragOffset();
                pagerSuppressClickUntil = Date.now() + 250;
                if (deltaX < 0 && homeAppsCurrentPage < homeAppsTotalPages) {
                    renderPage(homeAppsCurrentPage + 1, 'next');
                } else if (deltaX > 0 && homeAppsCurrentPage > 1) {
                    renderPage(homeAppsCurrentPage - 1, 'prev');
                }
            });

            homeScreen.addEventListener('pointercancel', () => {
                pagerPointerActive = false;
                pagerDragTriggered = false;
                homeScreen.classList.remove('dragging');
                resetPagerDragOffset();
            });

            homeScreen.addEventListener('click', (event) => {
                if (Date.now() <= reorderSuppressClickUntil) {
                    const appCard = event.target.closest('.app-icon');
                    if (appCard) {
                        event.preventDefault();
                        event.stopPropagation();
                        return;
                    }
                }
                if (!pagerDragTriggered && Date.now() > pagerSuppressClickUntil) return;
                const appCard = event.target.closest('.app-icon');
                if (!appCard) return;
                event.preventDefault();
                event.stopPropagation();
                pagerDragTriggered = false;
            }, true);

            pager.style.display = 'inline-flex';
            renderPage(1, 'none');
        }

        function initMenuSpotlight() {
            const overlay = document.getElementById('menuSpotlightOverlay');
            const input = document.getElementById('menuSpotlightInput');
            const results = document.getElementById('menuSpotlightResults');
            if (!overlay || !input || !results) return;

            let items = [];
            let filtered = [];
            let activeIndex = 0;

            const normalize = (value) => String(value || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .trim();

            const readMenuItems = () => {
                items = Array.from(document.querySelectorAll('#homeScreen .app-icon')).map((card) => {
                    const label = String(card.dataset.appLabel || card.querySelector('span')?.textContent || '').trim();
                    const route = String(card.dataset.appRoute || '').trim();
                    const action = String(card.dataset.appAction || 'app').trim();
                    const iconHtml = String(card.querySelector('.icon-back')?.innerHTML || '').trim();
                    return {
                        label,
                        route,
                        action,
                        iconHtml,
                        search: normalize(`${label} ${route} ${action}`)
                    };
                }).filter((item) => item.label !== '');
            };

            const renderResults = () => {
                const query = normalize(input.value);
                filtered = !query
                    ? items.slice(0, 12)
                    : items.filter((item) => item.search.includes(query)).slice(0, 12);

                if (activeIndex >= filtered.length) {
                    activeIndex = 0;
                }

                if (!filtered.length) {
                    results.innerHTML = '<div class="menu-spotlight-empty">No hay apps que coincidan con la búsqueda.</div>';
                    return;
                }

                results.innerHTML = filtered.map((item, idx) => `
                    <button type="button" class="menu-spotlight-item${idx === activeIndex ? ' active' : ''}" data-spotlight-index="${idx}">
                        <span class="menu-spotlight-item-icon">${item.iconHtml}</span>
                        <span>
                            <span class="menu-spotlight-item-label">${escapeHtml(item.label)}</span>
                            <span class="menu-spotlight-item-meta">${escapeHtml(item.route || (item.action === 'vcorta' ? 'Asistente' : 'Acción del sistema'))}</span>
                        </span>
                        <span class="menu-spotlight-item-shortcut">${idx === 0 ? 'ENTER' : ''}</span>
                    </button>
                `).join('');
            };

            const openItem = (item) => {
                if (!item) return;
                closeMenuSpotlight();
                if (item.action === 'logout') {
                    cerrarSesion();
                } else if (item.action === 'vcorta') {
                    toggleVcortaChat(true);
                } else if (item.route) {
                    abrirApp(item.label, item.route);
                }
            };

            window.openMenuSpotlight = function() {
                if (appAbierta) {
                    mostrarHome();
                }
                readMenuItems();
                activeIndex = 0;
                renderResults();
                overlay.classList.add('visible');
                overlay.setAttribute('aria-hidden', 'false');
                requestAnimationFrame(() => {
                    input.focus();
                    input.select();
                });
            };

            window.closeMenuSpotlight = function() {
                overlay.classList.remove('visible');
                overlay.setAttribute('aria-hidden', 'true');
                input.value = '';
                activeIndex = 0;
            };

            input.addEventListener('input', () => {
                activeIndex = 0;
                renderResults();
            });

            input.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    if (filtered.length) {
                        activeIndex = (activeIndex + 1) % filtered.length;
                        renderResults();
                    }
                    return;
                }
                if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    if (filtered.length) {
                        activeIndex = (activeIndex - 1 + filtered.length) % filtered.length;
                        renderResults();
                    }
                    return;
                }
                if (event.key === 'Enter') {
                    event.preventDefault();
                    openItem(filtered[activeIndex] || filtered[0] || null);
                    return;
                }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeMenuSpotlight();
                }
            });

            results.addEventListener('click', (event) => {
                const button = event.target.closest('[data-spotlight-index]');
                if (!button) return;
                openItem(filtered[Number(button.getAttribute('data-spotlight-index'))] || null);
            });

            overlay.addEventListener('click', (event) => {
                if (event.target === overlay) {
                    closeMenuSpotlight();
                }
            });

            document.addEventListener('keydown', (event) => {
                const target = event.target;
                const tag = target && target.tagName ? String(target.tagName).toLowerCase() : '';
                const editing = !!(target && (target.isContentEditable || tag === 'input' || tag === 'textarea' || tag === 'select'));
                if ((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() === 'k') {
                    event.preventDefault();
                    openMenuSpotlight();
                    return;
                }
                if (!editing && !overlay.classList.contains('visible') && event.key === '/') {
                    event.preventDefault();
                    openMenuSpotlight();
                    return;
                }
                if (overlay.classList.contains('visible') && event.key === 'Escape') {
                    event.preventDefault();
                    closeMenuSpotlight();
                }
            });
        }

        function getCellSortValue(cell) {
            if (!cell) return '';
            const raw = (cell.getAttribute('data-sort') || cell.textContent || '').trim();
            const normalized = raw
                .replace(/\s+/g, ' ')
                .replace(/\./g, '')
                .replace(/Gs/gi, '')
                .replace(/₲/g, '')
                .trim();

            const num = Number(normalized.replace(',', '.'));
            if (!Number.isNaN(num) && normalized !== '') return num;

            const dateMatch = normalized.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
            if (dateMatch) {
                const d = dateMatch[1].padStart(2, '0');
                const m = dateMatch[2].padStart(2, '0');
                const y = dateMatch[3];
                return `${y}${m}${d}`;
            }
            return normalized.toLowerCase();
        }

        function sortTableByColumn(table, columnIndex, dir) {
            const tbody = table.tBodies && table.tBodies[0];
            if (!tbody) return;

            const rows = Array.from(tbody.rows);
            rows.sort((a, b) => {
                const av = getCellSortValue(a.cells[columnIndex]);
                const bv = getCellSortValue(b.cells[columnIndex]);

                if (typeof av === 'number' && typeof bv === 'number') {
                    return dir === 'asc' ? av - bv : bv - av;
                }
                if (av < bv) return dir === 'asc' ? -1 : 1;
                if (av > bv) return dir === 'asc' ? 1 : -1;
                return 0;
            });

            rows.forEach((row) => tbody.appendChild(row));
        }

        function habilitarOrdenEnListas(doc) {
            if (!doc) return;
            const tables = doc.querySelectorAll('table');
            tables.forEach((table) => {
                if (table.dataset.sortEnabled === '1' || table.dataset.noSort === '1') return;

                const headerCells = table.querySelectorAll('thead th');
                if (!headerCells.length) return;

                table.dataset.sortEnabled = '1';
                headerCells.forEach((th, index) => {
                    if (th.dataset.noSort === '1') return;
                    th.style.cursor = 'pointer';
                    th.title = 'Ordenar';

                    let indicator = th.querySelector('[data-sort-indicator="1"]');
                    if (!indicator) {
                        indicator = doc.createElement('span');
                        indicator.setAttribute('data-sort-indicator', '1');
                        indicator.textContent = ' ↕';
                        indicator.style.opacity = '0.5';
                        th.appendChild(indicator);
                    }

                    th.addEventListener('click', () => {
                        const current = th.dataset.sortDir === 'asc' ? 'asc' : 'desc';
                        const next = current === 'asc' ? 'desc' : 'asc';

                        headerCells.forEach((h) => {
                            h.dataset.sortDir = '';
                            const mark = h.querySelector('[data-sort-indicator="1"]');
                            if (mark) mark.textContent = ' ↕';
                        });

                        th.dataset.sortDir = next;
                        indicator.textContent = next === 'asc' ? ' ↑' : ' ↓';
                        sortTableByColumn(table, index, next);
                    });
                });
            });
        }

        function activarOrdenEnIframe(iframe) {
            try {
                const iframeDoc = iframe && iframe.contentDocument ? iframe.contentDocument : null;
                habilitarOrdenEnListas(iframeDoc);
            } catch (e) {
                console.warn('No se pudo activar orden en iframe:', e);
            }
        }

        const vcortaState = {
            open: false,
            sending: false,
            history: [],
            audioEnabled: false,
            voiceProfile: 'lento',
            audioToggleSuppressUntil: 0,
            speechReady: false,
            speechVoice: null,
            ttsAudio: null,
            ttsAbort: null,
            recognition: null,
            listening: false
        };

        function shouldBlockPullRefresh() {
            return !!(vcortaState.open || appAbierta || document.body.classList.contains('app-open') || document.body.classList.contains('mobile-app-open'));
        }

        function syncPullRefreshBlockState() {
            document.documentElement.classList.toggle('block-pull-refresh', shouldBlockPullRefresh());
        }

        function preventPullToRefreshInIframe(iframe) {
            try {
                const doc = iframe && iframe.contentDocument ? iframe.contentDocument : null;
                if (!doc || !doc.documentElement || !doc.body) return;
                if (doc.documentElement.dataset.ptrGuard === '1') return;
                doc.documentElement.dataset.ptrGuard = '1';

                doc.documentElement.style.overscrollBehaviorY = 'none';
                doc.body.style.overscrollBehaviorY = 'none';

                let lastY = 0;
                doc.addEventListener('touchstart', function(ev) {
                    lastY = (ev.touches && ev.touches[0]) ? ev.touches[0].clientY : 0;
                }, { passive: true });

                doc.addEventListener('touchmove', function(ev) {
                    const y = (ev.touches && ev.touches[0]) ? ev.touches[0].clientY : lastY;
                    const dy = y - lastY;
                    lastY = y;
                    const top = (doc.scrollingElement ? doc.scrollingElement.scrollTop : 0) <= 0;
                    if (dy > 0 && top) ev.preventDefault();
                }, { passive: false });
            } catch (_) {}
        }
        const vcortaSuggestionGroups = [
            {
                title: 'Consultas rápidas',
                items: [
                    '¿Cuánto vendimos hoy?',
                    'Ventas de la semana',
                    'Ventas del mes',
                    'Ventas del año',
                    'Ventas de los últimos 6 meses',
                    'Comparativo semana actual vs semana anterior',
                    'Clientes que más compran',
                    'Top clientes por compras',
                    'Lista de clientes morosos',
                    'Clientes sin compra en 30 días'
                ]
            }
        ];
        const VCORTA_STORAGE_KEY = `vcorta_chat_${ID_EMPRESA}_<?= (int)$id_login ?>`;
        const VCORTA_AUDIO_KEY = `vcorta_audio_${ID_EMPRESA}_<?= (int)$id_login ?>`;
        const VCORTA_VOICE_PROFILE_KEY = `vcorta_voice_profile_${ID_EMPRESA}_<?= (int)$id_login ?>`;
        const VCORTA_VOICE_PROFILES = ['lento', 'claro', 'neutro', 'rapido'];

        function normalizeVcortaText(v) {
            return String(v || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .trim();
        }

        function setupVcortaMobileInputMode() {
            const input = document.getElementById('vcortaInput');
            if (!input || !isMobile) return;
            input.removeAttribute('readonly');
            input.setAttribute('inputmode', 'none');
            input.setAttribute('virtualkeyboardpolicy', 'manual');
            input.setAttribute('autocorrect', 'off');
            input.setAttribute('autocapitalize', 'off');
            input.setAttribute('spellcheck', 'false');
            input.style.caretColor = '#93c5fd';
            input.addEventListener('keydown', (e) => e.preventDefault());
            input.addEventListener('beforeinput', (e) => e.preventDefault());
        }

        function appendVcortaInputValue(chunk) {
            const input = document.getElementById('vcortaInput');
            if (!input) return;
            const current = String(input.value || '');
            if (current.length >= 1000) return;
            input.value = (current + chunk).slice(0, 1000);
        }

        function backspaceVcortaInput() {
            const input = document.getElementById('vcortaInput');
            if (!input) return;
            input.value = String(input.value || '').slice(0, -1);
        }

        function renderVcortaKeyboard() {
            const wrap = document.getElementById('vcortaKeyboard');
            if (!wrap || !isMobile) return;
            const mode = (wrap.dataset.mode === 'num') ? 'num' : 'alpha';
            const rowsAlpha = [
                ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o'],
                ['p', 'a', 's', 'd', 'f', 'g', 'h', 'j', 'k'],
                ['l', 'ñ', 'z', 'x', 'c', 'v', 'b', 'n', 'm']
            ];
            const rowsNum = [
                ['1', '2', '3', '4', '5', '6', '7', '8', '9'],
                ['0', '.', ',', '?', '!', '-', '_', '/', ':'],
                ['@', '#', '$', '%', '&', '*', '+', '(', ')']
            ];

            wrap.innerHTML = '';
            const rows = mode === 'num' ? rowsNum : rowsAlpha;
            rows.forEach((keys) => {
                const row = document.createElement('div');
                row.className = 'vcorta-kb-row';
                keys.forEach((k) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'vcorta-kb-key';
                    b.textContent = k;
                    b.addEventListener('click', () => appendVcortaInputValue(k));
                    row.appendChild(b);
                });
                wrap.appendChild(row);
            });

            const actionRow = document.createElement('div');
            actionRow.className = 'vcorta-kb-row';

            const mk = (label, className, fn) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = `vcorta-kb-key ${className || ''}`.trim();
                b.textContent = label;
                b.addEventListener('click', fn);
                return b;
            };

            actionRow.appendChild(mk(mode === 'num' ? 'ABC' : '123', 'wide-2', () => {
                wrap.dataset.mode = mode === 'num' ? 'alpha' : 'num';
                renderVcortaKeyboard();
            }));
            actionRow.appendChild(mk('Espacio', 'wide-4', () => appendVcortaInputValue(' ')));
            actionRow.appendChild(mk('⌫', '', () => backspaceVcortaInput()));
            actionRow.appendChild(mk('Enviar', 'wide-2 primary', () => sendVcortaMessageFromInput()));
            wrap.appendChild(actionRow);
        }

        function renderVcortaSuggestions() {
            const wrap = document.getElementById('vcortaSuggestions');
            if (!wrap) return;
            wrap.innerHTML = '';
            vcortaSuggestionGroups.forEach((group) => {
                if (!group || !Array.isArray(group.items) || !group.items.length) return;

                const groupWrap = document.createElement('div');
                groupWrap.className = 'vcorta-suggestion-group';

                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'vcorta-suggestion-toggle';
                toggle.setAttribute('aria-expanded', 'false');

                const toggleLabel = document.createElement('span');
                toggleLabel.textContent = String(group.title || 'Sugerencias');
                toggle.appendChild(toggleLabel);

                const chevron = document.createElement('span');
                chevron.className = 'vcorta-suggestion-chevron';
                chevron.textContent = '▾';
                toggle.appendChild(chevron);

                const list = document.createElement('div');
                list.className = 'vcorta-suggestion-list';

                const isConsultasRapidas = normalizeVcortaText(group.title || '').includes('consultas rapidas');
                group.items.forEach((q) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'vcorta-suggestion-btn';
                    const idx = list.querySelectorAll('.vcorta-suggestion-btn').length + 1;
                    b.textContent = isConsultasRapidas ? `${idx}. ${q}` : q;
                    b.addEventListener('click', () => sendVcortaSuggestedQuestion(q));
                    list.appendChild(b);
                });

                toggle.addEventListener('click', () => {
                    const isOpen = groupWrap.classList.toggle('open');
                    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                });

                groupWrap.appendChild(toggle);
                groupWrap.appendChild(list);
                wrap.appendChild(groupWrap);
            });
        }

        async function sendVcortaSuggestedQuestion(q) {
            const input = document.getElementById('vcortaInput');
            if (!input) return;
            input.value = q;
            await sendVcortaMessageFromInput();
        }

        function findAllowedAppForAlias(aliasRaw) {
            const alias = normalizeVcortaText(aliasRaw);
            if (!alias) return null;
            const apps = Array.isArray(VCORTA_ALLOWED_APPS) ? VCORTA_ALLOWED_APPS : [];
            const normalizedApps = apps.map((x) => ({
                ...x,
                labelNorm: normalizeVcortaText(x.label),
                appNorm: normalizeVcortaText(x.app)
            }));

            const aliasKeywords = {
                pos: ['pos', 'punto de venta', 'ventas'],
                caja: ['caja'],
                facturas: ['factura', 'facturas', 'sifen'],
                clientes: ['cliente', 'clientes'],
                productos: ['producto', 'productos', 'mercaderia', 'mercaderias'],
                compras: ['compra', 'compras'],
                nc: ['nc', 'nota de credito', 'nota credito'],
                nd: ['nd', 'nota de debito', 'nota debito']
            };

            let targetAliases = [alias];
            Object.entries(aliasKeywords).forEach(([key, words]) => {
                if (key === alias || words.some((w) => alias.includes(w))) targetAliases.push(key);
            });
            targetAliases = [...new Set(targetAliases)];

            for (const a of targetAliases) {
                const words = aliasKeywords[a] || [a];
                const found = normalizedApps.find((app) =>
                    words.some((w) => app.labelNorm.includes(w) || app.appNorm.includes(w))
                );
                if (found) return { label: found.label, app: found.app };
            }

            return null;
        }

        function tryVcortaOpenFromAssistant(reply) {
            const txt = String(reply || '').trim();
            const m = txt.match(/^abriendo\s+([a-z0-9áéíóúñ\s]+)\.\.\./i);
            if (!m) return false;

            const alias = (m[1] || '').trim();
            const target = findAllowedAppForAlias(alias);
            if (!target) {
                appendVcortaMessage('assistant', `No tenés permiso para abrir "${alias}" en este usuario.`, { silent: true });
                return true;
            }

            abrirApp(target.label, target.app);
            return true;
        }

        function isVcortaPosVideoIntent(text) {
            const t = normalizeVcortaText(text);
            if (!t) return false;
            const hasVideo = /\b(video|videotutorial|tutorial)\b/.test(t);
            const hasPos = /\b(pos|punto de venta)\b/.test(t);
            const asksHowToUsePos = (/\bcomo\b.*\b(usar|uso|se usa|funciona|manejar)\b.*\b(pos|punto de venta)\b/.test(t))
                || (/\b(pos|punto de venta)\b.*\bcomo\b.*\b(usar|uso|se usa|funciona|manejar)\b/.test(t))
                || (/\bcomofuncion(a)?\b.*\b(pos|punto de venta)\b/.test(t))
                || (/\b(pos|punto de venta)\b.*\bcomofuncion(a)?\b/.test(t));
            return (hasVideo && hasPos) || asksHowToUsePos;
        }

        function extractVcortaVideoUrl(reply) {
            const txt = String(reply || '');
            const m = txt.match(/(https?:\/\/[^\s<]+|\/video_out\/[^\s<]+\.mp4)/i);
            return m ? m[1] : '';
        }

        function ensureVcortaVideoPlayer() {
            let overlay = document.getElementById('vcortaVideoOverlay');
            if (overlay) return overlay;

            overlay = document.createElement('div');
            overlay.id = 'vcortaVideoOverlay';
            overlay.style.position = 'fixed';
            overlay.style.inset = '0';
            overlay.style.zIndex = '99999';
            overlay.style.display = 'none';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.style.background = 'rgba(2, 6, 23, 0.82)';

            overlay.innerHTML = `
                <div style="width:min(96vw, 1200px); max-height:92vh; border:1px solid rgba(148,163,184,.35); border-radius:16px; overflow:hidden; background:#020617; box-shadow:0 20px 60px rgba(0,0,0,.55);">
                    <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 12px; background:linear-gradient(135deg,#1d4ed8,#0f766e); color:#fff; font-weight:600;">
                        <span>Video Tutorial POS</span>
                        <button id="vcortaVideoCloseBtn" type="button" style="border:1px solid rgba(255,255,255,.35); background:rgba(15,23,42,.55); color:#fff; width:34px; height:34px; border-radius:10px; cursor:pointer;">✕</button>
                    </div>
                    <video id="vcortaVideoPlayer" controls playsinline style="width:100%; height:auto; max-height:calc(92vh - 54px); background:#000;"></video>
                </div>
            `;

            document.body.appendChild(overlay);
            const closeBtn = document.getElementById('vcortaVideoCloseBtn');
            const close = () => {
                const v = document.getElementById('vcortaVideoPlayer');
                if (v) {
                    v.pause();
                    v.removeAttribute('src');
                    v.load();
                }
                overlay.style.display = 'none';
            };
            if (closeBtn) closeBtn.addEventListener('click', close);
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) close();
            });
            return overlay;
        }

        function openVcortaVideoPlayer(videoUrl) {
            const overlay = ensureVcortaVideoPlayer();
            const video = document.getElementById('vcortaVideoPlayer');
            if (!overlay || !video || !videoUrl) return false;

            overlay.style.display = 'flex';
            video.src = videoUrl;
            video.currentTime = 0;
            const playPromise = video.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch(() => {
                    // fallback silencioso: controles quedan visibles para play manual
                });
            }
            return true;
        }

        function tryVcortaAutoPlayPosVideo(userMsg, reply) {
            if (!isVcortaPosVideoIntent(userMsg)) return false;
            const videoUrl = extractVcortaVideoUrl(reply);
            if (!videoUrl) return false;
            return openVcortaVideoPlayer(videoUrl);
        }

        function persistVcortaHistory() {
            try {
                sessionStorage.setItem(VCORTA_STORAGE_KEY, JSON.stringify(vcortaState.history.slice(-40)));
            } catch (e) {
                console.warn('No se pudo guardar historial de Vcorta:', e);
            }
        }

        function hydrateVcortaAudioState() {
            try {
                const raw = localStorage.getItem(VCORTA_AUDIO_KEY);
                if (raw === null) {
                    vcortaState.audioEnabled = false;
                } else {
                    vcortaState.audioEnabled = raw === '1';
                }
            } catch (e) {
                vcortaState.audioEnabled = false;
            }

            try {
                const rawProfile = String(localStorage.getItem(VCORTA_VOICE_PROFILE_KEY) || '').trim().toLowerCase();
                vcortaState.voiceProfile = VCORTA_VOICE_PROFILES.includes(rawProfile) ? rawProfile : 'lento';
            } catch (_) {
                vcortaState.voiceProfile = 'lento';
            }
        }

        function persistVcortaAudioState() {
            try {
                localStorage.setItem(VCORTA_AUDIO_KEY, vcortaState.audioEnabled ? '1' : '0');
            } catch (e) {
                // ignorar
            }
        }

        function persistVcortaVoiceProfile() {
            try {
                localStorage.setItem(VCORTA_VOICE_PROFILE_KEY, vcortaState.voiceProfile);
            } catch (_) {}
        }

        function hydrateVcortaHistory() {
            const wrap = document.getElementById('vcortaMessages');
            if (!wrap) return;
            try {
                const raw = sessionStorage.getItem(VCORTA_STORAGE_KEY);
                if (!raw) return;
                const parsed = JSON.parse(raw);
                if (!Array.isArray(parsed)) return;
                vcortaState.history = parsed
                    .filter((m) => m && (m.role === 'user' || m.role === 'assistant') && typeof m.content === 'string')
                    .slice(-40);
                wrap.innerHTML = '';
                vcortaState.history.forEach((m) => appendVcortaMessage(m.role, m.content));
            } catch (e) {
                console.warn('No se pudo restaurar historial de Vcorta:', e);
            }
        }

        function ensureVcortaWelcome() {
            if (vcortaState.history.length) return;
            const welcome = 'Hola, soy Vcorta. Puedo ayudarte con saldos, clientes, ventas, stock y más.';
            vcortaState.history.push({ role: 'assistant', content: welcome });
            appendVcortaMessage('assistant', welcome, { silent: true });
            persistVcortaHistory();
        }

        function toggleVcortaChat(forceOpen = null) {
            const widget = document.getElementById('vcortaWidget');
            const input = document.getElementById('vcortaInput');
            const logo = document.getElementById('sistemaxCornerLogo');
            if (!widget) return;

            vcortaState.open = forceOpen === null ? !vcortaState.open : !!forceOpen;
            widget.classList.toggle('open', vcortaState.open);
            document.body.classList.toggle('vcorta-open', vcortaState.open);
            syncPullRefreshBlockState();
            if (logo) logo.setAttribute('aria-expanded', vcortaState.open ? 'true' : 'false');

            if (vcortaState.open) {
                ensureVcortaWelcome();
                renderVcortaSuggestions();
                refreshVcortaHealth();
                setTimeout(() => {
                    if (input && !isMobile) input.focus();
                    scrollVcortaToBottom();
                }, 160);
            }
        }

        function closeVcortaChat() {
            toggleVcortaChat(false);
        }

        async function refreshVcortaHealth() {
            const el = document.getElementById('vcortaHealth');
            if (!el) return;
            el.classList.remove('ok', 'warn', 'bad');
            el.textContent = 'Estado IA: verificando...';
            try {
                const res = await fetch('api/ai_health.php', { method: 'GET' });
                const data = await res.json();
                if (!data || data.success !== true) throw new Error('health inválido');

                const openaiConfigured = !!(data.openai && data.openai.configured);
                const openaiReachable = !!(data.openai && data.openai.reachable);
                const openaiOk = openaiConfigured && openaiReachable;
                const ollamaEnabled = !!(data.ollama && data.ollama.enabled);
                const ollamaOk = !!(data.ollama && data.ollama.reachable);

                if (openaiOk && (!ollamaEnabled || ollamaOk)) {
                    el.textContent = 'Estado IA: OpenAI OK' + (ollamaEnabled ? ' | Ollama OK' : '');
                    el.classList.add('ok');
                } else if (openaiConfigured || ollamaOk) {
                    el.textContent = 'Estado IA: parcial';
                    el.classList.add('warn');
                } else {
                    el.textContent = 'Estado IA: sin conexión';
                    el.classList.add('bad');
                }
            } catch (_) {
                el.textContent = 'Estado IA: no disponible';
                el.classList.add('bad');
            }
        }

        function getVcortaVoice() {
            const voices = window.speechSynthesis ? window.speechSynthesis.getVoices() : [];
            if (!voices.length) return null;

            const maleHint = /(male|mascul|hombre|man|jorge|diego|carlos|pablo|antonio|miguel|daniel|jose|pedro|raul|luis|david|enrique|fernando|ricardo|alejandro)/i;
            const femaleHint = /(female|femen|mujer|woman|monica|paulina|helena|sofia|maria|ana|laura|carla|samantha|camila|valentina|lucia|elena|isabella)/i;
            const isSpanish = (v) => /^es[-_]/i.test(String(v.lang || '')) || /spanish|español/i.test(String(v.name || ''));
            const isLatamSpanish = (v) => /^es[-_](419|mx|ar|cl|co|pe|uy|py|bo|ve|ec|pa|cr|sv|hn|ni|do|gt|pr)/i.test(String(v.lang || ''));

            const scoreVoice = (v) => {
                let s = 0;
                const name = String(v.name || '').toLowerCase();
                const lang = String(v.lang || '').toLowerCase();
                if (/^es[-_](419|mx|ar|cl|co|pe|uy|py|bo|ve|ec|pa|cr|sv|hn|ni|do|gt|pr)/.test(lang)) s += 60;
                else if (/^es[-_]/.test(lang)) s += 40;
                if (femaleHint.test(name)) s += 65;
                if (maleHint.test(name)) s -= 40;
                if (/google|microsoft|enhanced|natural|premium|neural|online/.test(name)) s += 18;
                if (v.default) s += 8;
                return s;
            };

            const spanishVoices = voices.filter((v) => isSpanish(v));
            if (!spanishVoices.length) return null;

            const sorted = [...spanishVoices].sort((a, b) => scoreVoice(b) - scoreVoice(a));
            const latamFemale = sorted.find((v) => {
                const name = String(v.name || '');
                return isLatamSpanish(v) && femaleHint.test(name);
            });
            const spanishFemale = sorted.find((v) => femaleHint.test(String(v.name || '')));
            const latamAny = sorted.find((v) => isLatamSpanish(v));
            return latamFemale || spanishFemale || latamAny || sorted[0] || null;
        }

        function normalizeVcortaTtsText(rawText) {
            return String(rawText || '')
                .replace(/https?:\/\/\S+/g, ' ')
                .replace(/\/video_out\/\S+/g, ' ')
                .replace(/\bgs\.?\b/gi, ' guaranies ')
                .replace(/₲/g, ' guaranies ')
                .replace(/%/g, ' por ciento ')
                .replace(/[|]+/g, '. ')
                .replace(/\s+/g, ' ')
                .trim();
        }

        function splitVcortaTtsChunks(text, maxLen = 160) {
            const clean = normalizeVcortaTtsText(text);
            if (!clean) return [];
            const parts = clean
                .split(/(?<=[\.\!\?\:\;])\s+/)
                .map((x) => x.trim())
                .filter(Boolean);

            const chunks = [];
            parts.forEach((part) => {
                if (part.length <= maxLen) {
                    chunks.push(part);
                    return;
                }
                let rest = part;
                while (rest.length > maxLen) {
                    const cut = rest.lastIndexOf(' ', maxLen);
                    const idx = cut > 40 ? cut : maxLen;
                    chunks.push(rest.slice(0, idx).trim());
                    rest = rest.slice(idx).trim();
                }
                if (rest) chunks.push(rest);
            });
            return chunks.filter(Boolean);
        }

        function getVcortaVoiceProfileConfig(profileRaw, hasManyNumbers) {
            const profile = String(profileRaw || 'lento').toLowerCase();
            if (profile === 'lento') {
                return { rate: hasManyNumbers ? 0.74 : 0.8, pitch: 0.96 };
            }
            if (profile === 'claro') {
                return { rate: hasManyNumbers ? 0.88 : 0.92, pitch: 1.02 };
            }
            if (profile === 'rapido') {
                return { rate: hasManyNumbers ? 1.0 : 1.06, pitch: 0.98 };
            }
            return { rate: hasManyNumbers ? 0.9 : 0.96, pitch: 0.99 };
        }

        function initVcortaSpeech() {
            vcortaState.speechReady = ('speechSynthesis' in window);
            if (!vcortaState.speechReady) return;
            const assignVoice = () => {
                vcortaState.speechVoice = getVcortaVoice();
            };
            assignVoice();
            window.speechSynthesis.onvoiceschanged = assignVoice;
        }

        async function speakVcortaTextBrowserFallback(text) {
            if (!vcortaState.speechReady) return;
            const chunks = splitVcortaTtsChunks(text, 160);
            if (!chunks.length) return;

            try { window.speechSynthesis.cancel(); } catch (_) {}

            const baseVoice = vcortaState.speechVoice || getVcortaVoice();
            const hasManyNumbers = /\d{3,}/.test(String(text || ''));
            const voiceCfg = getVcortaVoiceProfileConfig(vcortaState.voiceProfile, hasManyNumbers);
            const rate = voiceCfg.rate;
            const pitch = voiceCfg.pitch;
            const isMobileSpeech = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '') || window.innerWidth <= 768;

            // Modo móvil: una sola locución corta mejora compatibilidad/autoplay de speechSynthesis.
            if (isMobileSpeech) {
                const mobileText = chunks.join(' ').slice(0, 260).trim();
                if (!mobileText) return;
                try {
                    const utter = new SpeechSynthesisUtterance(mobileText);
                    utter.lang = 'es-419';
                    utter.voice = baseVoice || null;
                    utter.rate = rate;
                    utter.pitch = pitch;
                    utter.volume = 1;
                    window.speechSynthesis.speak(utter);
                } catch (_) {}
                return;
            }

            for (const chunk of chunks) {
                await new Promise((resolve) => {
                    let done = false;
                    const finish = () => {
                        if (done) return;
                        done = true;
                        resolve();
                    };
                    try {
                        const utter = new SpeechSynthesisUtterance(chunk);
                        utter.lang = 'es-419';
                        utter.voice = baseVoice || null;
                        utter.rate = rate;
                        utter.pitch = pitch;
                        utter.volume = 1;
                        utter.onend = finish;
                        utter.onerror = finish;
                        window.speechSynthesis.speak(utter);
                        setTimeout(finish, 6500);
                    } catch (_) {
                        finish();
                    }
                });
            }
        }

        async function speakVcortaText(text) {
            if (!vcortaState.audioEnabled || !text) return;
            try {
                const clean = normalizeVcortaTtsText(text);
                if (!clean) return;

                // Priorizar siempre TTS del navegador para evitar consumo externo.
                if (vcortaState.speechReady) {
                    await speakVcortaTextBrowserFallback(clean);
                    return;
                }

                if (vcortaState.ttsAbort) {
                    try { vcortaState.ttsAbort.abort(); } catch (_) {}
                }
                if (vcortaState.ttsAudio) {
                    try { vcortaState.ttsAudio.pause(); } catch (_) {}
                }

                const ctrl = new AbortController();
                vcortaState.ttsAbort = ctrl;
                const resp = await fetch('api/ai_tts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ text: clean }),
                    signal: ctrl.signal
                });

                if (!resp.ok) throw new Error('TTS HTTP ' + resp.status);
                const ctype = String(resp.headers.get('content-type') || '').toLowerCase();
                if (ctype.includes('application/json')) {
                    const ttsJson = await resp.json().catch(() => ({}));
                    throw new Error((ttsJson && ttsJson.error) ? String(ttsJson.error) : 'TTS no disponible');
                }
                const blob = await resp.blob();
                if (!blob || !blob.size) throw new Error('Audio vacío');

                const url = URL.createObjectURL(blob);
                const audio = new Audio(url);
                vcortaState.ttsAudio = audio;
                audio.onended = () => URL.revokeObjectURL(url);
                await audio.play();
            } catch (e) {
                // Fallback al motor del navegador si TTS remoto falla.
                if (vcortaState.speechReady) {
                    await speakVcortaTextBrowserFallback(text);
                } else {
                    console.warn('No se pudo reproducir voz en Vcorta:', e);
                }
            }
        }

        function renderVcortaAudioBtn() {
            const btn = document.getElementById('vcortaAudioBtn');
            if (!btn) return;
            btn.classList.toggle('active', vcortaState.audioEnabled);
            const profileLabel = vcortaState.voiceProfile === 'lento'
                ? 'lenta'
                : (vcortaState.voiceProfile === 'claro'
                    ? 'clara'
                    : (vcortaState.voiceProfile === 'rapido' ? 'rápida' : 'neutra'));
            btn.title = (vcortaState.audioEnabled ? 'Voz activada' : 'Voz desactivada') + ` (${profileLabel})`;
            btn.innerHTML = vcortaState.audioEnabled
                ? '<i class="fas fa-volume-up"></i>'
                : '<i class="fas fa-volume-mute"></i>';
        }

        function cycleVcortaVoiceProfile() {
            const current = VCORTA_VOICE_PROFILES.indexOf(vcortaState.voiceProfile);
            const next = (current + 1) % VCORTA_VOICE_PROFILES.length;
            vcortaState.voiceProfile = VCORTA_VOICE_PROFILES[next];
            persistVcortaVoiceProfile();
            renderVcortaAudioBtn();

            const label = vcortaState.voiceProfile === 'lento'
                ? 'lento'
                : (vcortaState.voiceProfile === 'claro'
                    ? 'claro'
                    : (vcortaState.voiceProfile === 'rapido' ? 'rápido' : 'neutro'));
            appendVcortaMessage('assistant', `Perfil de voz del navegador: ${label}.`, { silent: true });
        }

        function setupVcortaAudioProfileShortcut() {
            const btn = document.getElementById('vcortaAudioBtn');
            if (!btn) return;

            let holdTimer = null;
            let holdTriggered = false;
            const HOLD_MS = 700;

            const start = () => {
                holdTriggered = false;
                clearTimeout(holdTimer);
                holdTimer = setTimeout(() => {
                    holdTriggered = true;
                    vcortaState.audioToggleSuppressUntil = Date.now() + 900;
                    cycleVcortaVoiceProfile();
                }, HOLD_MS);
            };

            const stop = () => {
                clearTimeout(holdTimer);
            };

            btn.addEventListener('touchstart', start, { passive: true });
            btn.addEventListener('touchend', stop, { passive: true });
            btn.addEventListener('touchcancel', stop, { passive: true });
            btn.addEventListener('mousedown', start);
            btn.addEventListener('mouseup', stop);
            btn.addEventListener('mouseleave', stop);
            btn.addEventListener('click', function(e) {
                if (!holdTriggered) return;
                e.preventDefault();
                e.stopPropagation();
            }, true);
        }

        function toggleVcortaAudio() {
            if (Date.now() < (vcortaState.audioToggleSuppressUntil || 0)) return;
            vcortaState.audioEnabled = !vcortaState.audioEnabled;
            renderVcortaAudioBtn();
            persistVcortaAudioState();
            if (!vcortaState.audioEnabled && vcortaState.ttsAbort) {
                try { vcortaState.ttsAbort.abort(); } catch (_) {}
            }
            if (!vcortaState.audioEnabled && vcortaState.ttsAudio) {
                try { vcortaState.ttsAudio.pause(); } catch (_) {}
            }
            if (!vcortaState.audioEnabled && 'speechSynthesis' in window) {
                window.speechSynthesis.cancel();
            }
        }

        function renderVcortaMicBtn() {
            const btn = document.getElementById('vcortaMicBtn');
            if (!btn) return;
            btn.classList.toggle('active', vcortaState.listening);
            btn.title = vcortaState.listening ? 'Detener micrófono' : 'Dictar por micrófono';
        }

        function initVcortaMic() {
            const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SR) return;
            const rec = new SR();
            rec.lang = (navigator.language && /^es/i.test(navigator.language)) ? navigator.language : 'es-ES';
            rec.interimResults = false;
            rec.continuous = false;
            rec.maxAlternatives = 1;

            rec.onstart = function() {
                vcortaState.listening = true;
                renderVcortaMicBtn();
            };

            rec.onresult = function(event) {
                const input = document.getElementById('vcortaInput');
                if (!input) return;
                const text = event.results && event.results[0] && event.results[0][0]
                    ? String(event.results[0][0].transcript || '').trim()
                    : '';
                if (!text) return;
                input.value = text;
                sendVcortaMessageFromInput();
            };
            rec.onend = function() {
                vcortaState.listening = false;
                renderVcortaMicBtn();
            };
            rec.onerror = function(event) {
                vcortaState.listening = false;
                renderVcortaMicBtn();
                const code = String((event && event.error) || '').toLowerCase();
                let msg = 'No se pudo usar el micrófono en este navegador.';
                if (code === 'not-allowed' || code === 'service-not-allowed') {
                    msg = 'Permiso de micrófono denegado. Habilitalo en el navegador para usar dictado.';
                } else if (code === 'no-speech') {
                    msg = 'No se detectó voz. Intentá hablar más cerca del micrófono.';
                } else if (code === 'audio-capture') {
                    msg = 'No se detectó micrófono disponible en el dispositivo.';
                }
                appendVcortaMessage('assistant', msg, { silent: true });
            };

            vcortaState.recognition = rec;
        }

        function toggleVcortaMic() {
            if (!vcortaState.recognition) {
                appendVcortaMessage('assistant', 'Tu navegador no soporta dictado por micrófono.', { silent: true });
                return;
            }
            if (vcortaState.listening) {
                try { vcortaState.recognition.stop(); } catch (_) {}
                vcortaState.listening = false;
            } else {
                try {
                    vcortaState.recognition.start();
                } catch (e) {
                    vcortaState.listening = false;
                    appendVcortaMessage('assistant', 'No se pudo iniciar el micrófono. Verificá permisos y HTTPS.', { silent: true });
                }
            }
            renderVcortaMicBtn();
        }

        function appendVcortaMessage(role, content, options = {}) {
            const wrap = document.getElementById('vcortaMessages');
            if (!wrap) return;

            const bubble = document.createElement('div');
            bubble.className = `vcorta-msg ${role === 'user' ? 'user' : 'assistant'}`;
            if (role === 'assistant') {
                bubble.innerHTML = formatVcortaAssistantMessage(content || '');
            } else {
                bubble.textContent = content || '';
            }
            wrap.appendChild(bubble);
            scrollVcortaToBottom();

            if (role === 'assistant' && !options.silent) {
                speakVcortaText(content || '');
            }
        }

        function formatVcortaAssistantMessage(content) {
            const escaped = String(content || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');

            const withLinks = escaped.replace(
                /(https?:\/\/[^\s<]+|\/video_out\/[^\s<]+)/g,
                (match) => `<a href="${match}" target="_blank" rel="noopener noreferrer">${match}</a>`
            );

            return withLinks.replace(/\n/g, '<br>');
        }

        function scrollVcortaToBottom() {
            const wrap = document.getElementById('vcortaMessages');
            if (!wrap) return;
            const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (typeof wrap.scrollTo === 'function') {
                wrap.scrollTo({
                    top: wrap.scrollHeight,
                    behavior: prefersReducedMotion ? 'auto' : 'smooth'
                });
                return;
            }
            wrap.scrollTop = wrap.scrollHeight;
        }

        function setVcortaTyping(show) {
            const typing = document.getElementById('vcortaTyping');
            if (!typing) return;
            typing.classList.toggle('visible', !!show);
            if (show) scrollVcortaToBottom();
        }

        function buildVcortaQuickAck(userMsg) {
            const t = normalizeVcortaText(userMsg);
            if (/\b(venta|vendimos|factura|saldo|deuda|stock|cliente|producto|tabla|columnas|sql)\b/.test(t)) {
                return 'Procesando rápido. Ya te paso el resultado completo.';
            }
            return 'Entendido. Estoy procesando tu consulta.';
        }

        async function sendVcortaMessageFromInput() {
            const input = document.getElementById('vcortaInput');
            const sendBtn = document.getElementById('vcortaSendBtn');
            if (!input || vcortaState.sending) return;

            const userMsg = input.value.trim();
            if (!userMsg) return;

            if (!vcortaState.open) toggleVcortaChat(true);

            appendVcortaMessage('user', userMsg);
            vcortaState.history.push({ role: 'user', content: userMsg });
            persistVcortaHistory();
            input.value = '';

            vcortaState.sending = true;
            if (sendBtn) sendBtn.disabled = true;
            if (vcortaState.listening && vcortaState.recognition) {
                vcortaState.recognition.stop();
                vcortaState.listening = false;
                renderVcortaMicBtn();
            }
            setVcortaTyping(true);

            let timeoutId = null;
            let slowHintId = null;
            let slowHintShown = false;
            try {
                const controller = new AbortController();
                const timeoutMs = 95000;
                timeoutId = setTimeout(() => controller.abort(), timeoutMs);
                slowHintId = setTimeout(() => {
                    slowHintShown = true;
                    appendVcortaMessage('assistant', buildVcortaQuickAck(userMsg), { silent: true });
                }, 5000);
                const res = await fetch('api/ai_chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    signal: controller.signal,
                    body: JSON.stringify({
                        message: userMsg,
                        history: vcortaState.history.slice(0, -1)
                    })
                });
                clearTimeout(timeoutId);
                const raw = await res.text();
                let data = null;
                try {
                    data = JSON.parse(raw);
                } catch (e) {
                    throw new Error(`Respuesta inválida (${res.status}): ${raw.slice(0, 180)}`);
                }

                const reply = data && data.success
                    ? String(data.response || '').trim()
                    : 'Error: ' + ((data && data.error) ? data.error : 'Sin respuesta');

                const finalReply = reply || 'Sin respuesta';
                appendVcortaMessage('assistant', slowHintShown ? `Detalle completo:\n${finalReply}` : finalReply);
                vcortaState.history.push({ role: 'assistant', content: reply || 'Sin respuesta' });
                if (vcortaState.history.length > 40) {
                    vcortaState.history = vcortaState.history.slice(-40);
                }
                persistVcortaHistory();
                tryVcortaOpenFromAssistant(reply || '');
                tryVcortaAutoPlayPosVideo(userMsg, reply || '');
            } catch (err) {
                const msg = (err && err.name === 'AbortError')
                    ? 'Tiempo de espera agotado. Vcorta tardó demasiado en responder.'
                    : (err && err.message)
                    ? `Error de conexión: ${err.message}`
                    : 'Error de conexión. Intente nuevamente.';
                appendVcortaMessage('assistant', msg);
                vcortaState.history.push({ role: 'assistant', content: msg });
                persistVcortaHistory();
            } finally {
                if (timeoutId) clearTimeout(timeoutId);
                if (slowHintId) clearTimeout(slowHintId);
                setVcortaTyping(false);
                vcortaState.sending = false;
                if (sendBtn) sendBtn.disabled = false;
                if (!isMobile) input.focus();
            }
        }

        hydrateVcortaHistory();
        hydrateVcortaAudioState();
        setupVcortaMobileInputMode();
        renderVcortaKeyboard();
        initVcortaSpeech();
        initVcortaMic();
        renderVcortaAudioBtn();
        setupVcortaAudioProfileShortcut();
        renderVcortaMicBtn();
        const vcortaPanelEl = document.getElementById('vcortaPanel');
        if (vcortaPanelEl) {
            vcortaPanelEl.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            let vcortaLastTouchY = 0;
            vcortaPanelEl.addEventListener('touchstart', function(e) {
                if (!isMobile || !vcortaState.open) return;
                vcortaLastTouchY = (e.touches && e.touches[0]) ? e.touches[0].clientY : 0;
            }, { passive: true });

            vcortaPanelEl.addEventListener('touchmove', function(e) {
                if (!isMobile || !vcortaState.open) return;
                const touchY = (e.touches && e.touches[0]) ? e.touches[0].clientY : vcortaLastTouchY;
                const deltaY = touchY - vcortaLastTouchY;
                vcortaLastTouchY = touchY;

                const target = e.target;
                const scroller = (target && typeof target.closest === 'function')
                    ? target.closest('#vcortaMessages, #vcortaSuggestions')
                    : null;

                if (!scroller) {
                    return;
                }

                const atTop = scroller.scrollTop <= 0;
                // Bloquear solo el gesto de pull-to-refresh (arrastre hacia abajo estando arriba)
                if (deltaY > 0 && atTop) {
                    e.preventDefault();
                }
            }, { passive: false });
        }

        let globalLastTouchY = 0;
        document.addEventListener('touchstart', function(e) {
            globalLastTouchY = (e.touches && e.touches[0]) ? e.touches[0].clientY : 0;
        }, { passive: true });

        document.addEventListener('touchmove', function(e) {
            if (!isMobile || !shouldBlockPullRefresh()) return;
            const y = (e.touches && e.touches[0]) ? e.touches[0].clientY : globalLastTouchY;
            const dy = y - globalLastTouchY;
            globalLastTouchY = y;
            if (dy <= 0) return;
            const top = (window.scrollY || document.documentElement.scrollTop || 0) <= 0;
            if (top) e.preventDefault();
        }, { passive: false });

        window.addEventListener('touchmove', function(e) {
            if (!isMobile || !vcortaState.open) return;
            const y = (e.touches && e.touches[0]) ? e.touches[0].clientY : globalLastTouchY;
            const dy = y - globalLastTouchY;
            globalLastTouchY = y;
            if (dy <= 0 || !e.cancelable) return;

            const target = e.target;
            const scroller = (target && typeof target.closest === 'function')
                ? target.closest('#vcortaMessages, #vcortaSuggestions')
                : null;

            if (!scroller) {
                e.preventDefault();
                return;
            }

            const atTop = scroller.scrollTop <= 0;
            if (atTop) e.preventDefault();
        }, { passive: false, capture: true });
        setInterval(() => {
            if (vcortaState.open) refreshVcortaHealth();
        }, 60000);

        function setCornerLogoHidden(hidden) {
            const logo = document.getElementById('sistemaxCornerLogo');
            if (!logo) return;
            logo.classList.toggle('hidden-logo', !!hidden);
        }

        function buildAppUrl(app) {
            return '/' + String(app || '').replace(/^\/+/, '');
        }

        function shouldOpenAppInExternalBrowser(app) {
            const value = String(app || '').toLowerCase();
            if (value.includes('public/apps-moviles.php?external=android&focus=printer')) {
                return false;
            }
            return value.includes('public/apps-moviles.php?external=1')
                || value.includes('public/apps-moviles.php?external=android')
                || value.includes('public/menu/push_diagnostico.php');
        }

        async function enrichExternalAppUrl(url, app) {
            const value = String(app || '').toLowerCase();
            if (!value.includes('public/apps-moviles.php?external=android')) {
                return url;
            }
            const res = await fetch('/public/api/mobile_tracking.php?action=issue_setup_token', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'issue_setup_token' })
            });
            const data = await res.json();
            if (!res.ok || !data || !data.ok || !data.data || !data.data.setup_token) {
                throw new Error((data && data.error) ? data.error : 'No se pudo preparar la instalación Android');
            }
            const u = new URL(url, window.location.origin);
            u.searchParams.set('setup_token', data.data.setup_token);
            return u.toString();
        }

        function isLoginPath(pathname) {
            const p = String(pathname || '').toLowerCase();
            return p.endsWith('/public/login.php') || p.endsWith('/login.php');
        }

        function getIframePathnameSafe(iframeEl) {
            try {
                if (!iframeEl || !iframeEl.contentWindow || !iframeEl.contentWindow.location) return '';
                return String(iframeEl.contentWindow.location.pathname || '');
            } catch (_) {
                return '';
            }
        }

        function syncEmbeddedLoginDockState() {
            let showAsLogin = false;
            if (isMobile) {
                const appFrame = document.getElementById('appFrame');
                showAsLogin = isLoginPath(getIframePathnameSafe(appFrame));
            } else if (desktopActiveAppId !== null) {
                const active = desktopOpenApps.find((a) => a.id === desktopActiveAppId);
                showAsLogin = !!active && isLoginPath(getIframePathnameSafe(active.iframe));
            }
            embeddedLoginVisible = !!showAsLogin;
            return embeddedLoginVisible;
        }

        function setDesktopLoadingOverlay(visible, label = '') {
            const overlay = document.getElementById('desktopLoadingOverlay');
            const text = document.getElementById('desktopLoadingText');
            if (!overlay) return;
            if (text) {
                text.textContent = label ? `Cargando ${label}...` : 'Cargando TPV...';
            }
            overlay.classList.toggle('visible', !!visible);
        }

        function cancelHomeScreenHideTimer() {
            if (homeScreenHideTimer) {
                clearTimeout(homeScreenHideTimer);
                homeScreenHideTimer = null;
            }
        }

        function restoreHomeScreenUi() {
            cancelHomeScreenHideTimer();
            const homeScreen = document.getElementById('homeScreen');
            if (!homeScreen) return;

            homeScreen.style.display = 'grid';
            homeScreen.style.transform = '';
            homeScreen.classList.remove('hidden-screen', 'dragging');

            const cards = Array.from(homeScreen.querySelectorAll('.app-icon'));
            const allCardsHidden = cards.length > 0 && cards.every((card) => card.style.display === 'none');
            homeAppsCurrentPage = Math.max(1, Math.min(homeAppsCurrentPage, Math.max(1, Math.ceil(cards.length / HOME_APPS_PER_PAGE))));
            const start = (homeAppsCurrentPage - 1) * HOME_APPS_PER_PAGE;
            const end = start + HOME_APPS_PER_PAGE;
            if (allCardsHidden) homeAppsCurrentPage = 1;

            cards.forEach((card, idx) => {
                const visible = allCardsHidden
                    ? idx < HOME_APPS_PER_PAGE
                    : (idx >= start && idx < end && card.style.display !== 'none');
                card.style.display = visible ? '' : 'none';
                card.style.opacity = visible ? '1' : '';
                card.style.visibility = visible ? 'visible' : '';
                card.style.animation = visible ? 'none' : '';
                card.classList.remove('reorder-dragging', 'reorder-drop-before', 'reorder-drop-after');
            });

            homeAppsTotalPages = Math.max(1, Math.ceil(cards.length / HOME_APPS_PER_PAGE));
            const pager = document.getElementById('homeAppsPager');
            if (pager) {
                pager.style.display = homeAppsTotalPages > 1 ? 'inline-flex' : 'none';
            }
        }

        function getDockInitials(label) {
            const clean = String(label || '').trim();
            if (!clean) return 'AP';
            return clean.slice(0, 2).toUpperCase();
        }

        function getDockIconKey(url) {
            const normalized = buildAppUrl(url || '');
            try {
                const u = new URL(normalized, window.location.origin);
                const full = `${u.pathname}${u.search || ''}`;
                if (DESKTOP_DOCK_ICON_MAP && DESKTOP_DOCK_ICON_MAP[full]) return full;
                if (DESKTOP_DOCK_ICON_MAP && DESKTOP_DOCK_ICON_MAP[u.pathname]) return u.pathname;
                return full;
            } catch (_) {
                return normalized;
            }
        }

        function getDockIconHtml(url, label) {
            const key = getDockIconKey(url || '');
            const icon = DESKTOP_DOCK_ICON_MAP && DESKTOP_DOCK_ICON_MAP[key]
                ? String(DESKTOP_DOCK_ICON_MAP[key])
                : '';
            if (icon) return icon;
            return `<span>${getDockInitials(label)}</span>`;
        }

        function showOpenAppsDock() {
            const dock = document.getElementById('openAppsDock');
            if (dock) dock.classList.add('visible');
            const handle = document.getElementById('dockRevealHandle');
            if (handle) handle.classList.toggle('visible', !isMobile && !!dockAutoHide);
        }

        function hideOpenAppsDock() {
            const dock = document.getElementById('openAppsDock');
            if (dock) dock.classList.remove('visible');
        }

        function scheduleHideOpenAppsDock() {
            // Deprecado: no auto-ocultar dock
            return;
        }

        function hydrateDockSettings() {
            // Deprecado: ignorar setting persistido y forzar visible
            dockAutoHide = false;
        }

        function persistDockSettings() {
            // Deprecado
        }

        function hydratePinnedDockApps() {
            try {
                const raw = localStorage.getItem(DOCK_PINNED_APPS_KEY);
                const parsed = JSON.parse(raw || '[]');
                desktopPinnedApps = Array.isArray(parsed)
                    ? parsed
                        .filter((x) => x && typeof x.label === 'string' && typeof x.url === 'string' && x.url.trim() !== '')
                        .map((x) => ({ label: String(x.label), url: buildAppUrl(String(x.url)) }))
                    : [];
            } catch (_) {
                desktopPinnedApps = [];
            }
        }

        function persistPinnedDockApps() {
            try {
                localStorage.setItem(DOCK_PINNED_APPS_KEY, JSON.stringify(desktopPinnedApps));
            } catch (_) {}
        }

        function isPinnedDockUrl(url) {
            return desktopPinnedApps.some((x) => x.url === url);
        }

        function pinDockApp(label, url) {
            const normalizedUrl = buildAppUrl(url);
            if (isPinnedDockUrl(normalizedUrl)) return;
            desktopPinnedApps.push({ label: String(label || 'App'), url: normalizedUrl });
            persistPinnedDockApps();
            renderOpenAppsDock();
        }

        function unpinDockApp(url) {
            const normalizedUrl = buildAppUrl(url);
            desktopPinnedApps = desktopPinnedApps.filter((x) => x.url !== normalizedUrl);
            persistPinnedDockApps();
            renderOpenAppsDock();
        }

        function hideDockContextMenu() {
            const menu = document.getElementById('dockContextMenu');
            if (menu) {
                menu.classList.remove('visible');
                menu.innerHTML = '';
            }
            dockContextTarget = null;
        }

        function showDockContextMenu(x, y, target) {
            const menu = document.getElementById('dockContextMenu');
            if (!menu || !target) return;
            dockContextTarget = target;

            const pinned = isPinnedDockUrl(target.url);
            const closeDisabled = !target.running;
            const rows = [];
            if (target.type !== 'home') {
                rows.push(`<button type="button" class="dock-context-item" data-cmd="pin">${pinned ? 'Desfijar' : 'Fijar'} en el Dock</button>`);
                rows.push(`<button type="button" class="dock-context-item danger" data-cmd="close"${closeDisabled ? ' disabled' : ''}>Cerrar</button>`);
            }
            menu.innerHTML = rows.join('');

            const maxX = Math.max(8, window.innerWidth - 190);
            const maxY = Math.max(8, window.innerHeight - 120);
            menu.style.left = `${Math.min(x, maxX)}px`;
            menu.style.top = `${Math.min(y, maxY)}px`;
            menu.classList.add('visible');
        }

        function handleDockContextMenuCommand(cmd) {
            if (!dockContextTarget) return;
            if (cmd === 'pin') {
                if (isPinnedDockUrl(dockContextTarget.url)) unpinDockApp(dockContextTarget.url);
                else pinDockApp(dockContextTarget.label, dockContextTarget.url);
            } else if (cmd === 'close' && dockContextTarget.running && dockContextTarget.appId) {
                closeDesktopOpenApp(Number(dockContextTarget.appId));
            }
            hideDockContextMenu();
        }

        function renderOpenAppsDock() {
            const dock = document.getElementById('openAppsDock');
            const handle = document.getElementById('dockRevealHandle');
            const ctxMenu = document.getElementById('dockContextMenu');
            if (!dock) return;

            if (syncEmbeddedLoginDockState()) {
                dock.classList.remove('visible');
                dock.style.display = 'none';
                if (handle) {
                    handle.classList.remove('visible');
                    handle.style.display = 'none';
                }
                if (ctxMenu) ctxMenu.classList.remove('visible');
                return;
            }

            dock.style.display = '';
            if (handle) handle.style.display = '';

            dock.classList.toggle('mobile-mini', !!isMobile);
            if (handle) handle.classList.toggle('visible', !isMobile && !!dockAutoHide);

            dock.innerHTML = '';
            const homeItem = document.createElement('button');
            homeItem.type = 'button';
            homeItem.className = 'dock-app-item dock-home' + (!appAbierta ? ' active' : '');
            homeItem.dataset.dockType = 'home';
            homeItem.dataset.label = window.__MENU_I18N__?.home || 'Inicio';
            homeItem.dataset.url = '';
            homeItem.dataset.running = '0';
            homeItem.dataset.appId = '';
            homeItem.innerHTML = `
                <span class="dock-app-icon"><img src="/public/assets/images/icons_v2/inicio.svg" alt="Inicio"></span>
                <span class="dock-app-label-tip">${window.__MENU_I18N__?.home || 'Inicio'}</span>
            `;
            homeItem.addEventListener('click', () => {
                if (Date.now() < mobileDockSuppressClickUntil) return;
                mostrarHome();
            });
            homeItem.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                e.stopPropagation();
                showDockContextMenu(e.clientX, e.clientY, { type: 'home', label: window.__MENU_I18N__?.home || 'Inicio', url: '', running: false, appId: null });
            });
            dock.appendChild(homeItem);

            const logoutItem = document.createElement('button');
            logoutItem.type = 'button';
            logoutItem.className = 'dock-app-item dock-logout';
            logoutItem.dataset.dockType = 'logout';
            logoutItem.dataset.label = window.__MENU_I18N__?.logout || 'Salir';
            logoutItem.dataset.url = '__logout__';
            logoutItem.dataset.running = '0';
            logoutItem.dataset.appId = '';
            logoutItem.innerHTML = `
                <span class="dock-app-icon"><img src="/public/assets/images/icons_v2/logout.svg" alt="Salir"></span>
                <span class="dock-app-label-tip">${window.__MENU_I18N__?.logout || 'Salir'}</span>
            `;
            logoutItem.addEventListener('click', () => {
                if (Date.now() < mobileDockSuppressClickUntil) return;
                cerrarSesion();
            });
            logoutItem.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
            const dockModels = [];

            if (isMobile) {
                desktopPinnedApps.forEach((pin) => {
                    dockModels.push({
                        type: 'app',
                        label: pin.label,
                        url: pin.url,
                        running: false,
                        pinned: true,
                        appId: null
                    });
                });
                if (appAbierta && currentMobileAppUrl) {
                    const existing = dockModels.find((m) => m.url === currentMobileAppUrl);
                    if (existing) {
                        existing.running = true;
                        existing.label = currentAppLabel || existing.label;
                    } else {
                        dockModels.push({
                            type: 'app',
                            label: currentAppLabel || 'App',
                            url: currentMobileAppUrl,
                            running: true,
                            pinned: false,
                            appId: null
                        });
                    }
                }
            }

            if (!isMobile) {
                desktopPinnedApps.forEach((pin) => {
                    const running = desktopOpenApps.find((x) => x.url === pin.url);
                    dockModels.push({
                        type: 'app',
                        label: running ? running.label : pin.label,
                        url: pin.url,
                        running: !!running,
                        pinned: true,
                        appId: running ? running.id : null
                    });
                });
                desktopOpenApps.forEach((app) => {
                    if (!dockModels.some((m) => m.url === app.url)) {
                        dockModels.push({
                            type: 'app',
                            label: app.label,
                            url: app.url,
                            running: true,
                            pinned: false,
                            appId: app.id
                        });
                    }
                });
            }

            dockModels.forEach((app) => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'dock-app-item' + (app.running ? ' running' : ' pinned-only') + (app.appId === desktopActiveAppId ? ' active' : '');
                item.setAttribute('data-app-id', String(app.appId || ''));
                item.dataset.dockType = app.type || 'app';
                item.dataset.label = String(app.label || '');
                item.dataset.url = String(app.url || '');
                item.dataset.running = app.running ? '1' : '0';
                item.dataset.appId = String(app.appId || '');
                item.innerHTML = `
                    <span class="dock-app-icon">${getDockIconHtml(app.url, app.label)}</span>
                    <span class="dock-app-label-tip">${app.label}</span>
                `;
                item.addEventListener('click', () => {
                    if (Date.now() < mobileDockSuppressClickUntil) return;
                    if (isMobile) {
                        if (app.running && appAbierta) return;
                        abrirApp(app.label, String(app.url || '').replace(/^\/+/, ''));
                        return;
                    }
                    if (app.running && app.appId) {
                        activateDesktopOpenApp(app.appId);
                    } else {
                        openDesktopApp(app.label, app.url);
                    }
                });
                item.addEventListener('contextmenu', (e) => {
                    if (isMobile) return;
                    e.preventDefault();
                    e.stopPropagation();
                    showDockContextMenu(e.clientX, e.clientY, app);
                });
                dock.appendChild(item);
            });

            dock.appendChild(logoutItem);

            showOpenAppsDock();
            scheduleHideOpenAppsDock();
        }

        function activateDesktopOpenApp(appId) {
            const selected = desktopOpenApps.find((a) => a.id === appId);
            if (!selected) return;

            desktopActiveAppId = selected.id;
            currentAppLabel = selected.label;

            desktopOpenApps.forEach((app) => {
                if (!app.iframe) return;
                app.iframe.classList.toggle('active', app.id === desktopActiveAppId);
            });

            syncEmbeddedLoginDockState();
            renderOpenAppsDock();
        }

        function openDesktopApp(label, url) {
            const container = document.getElementById('desktopFramesContainer');
            if (!container) return;

            const exists = desktopOpenApps.find((a) => a.url === url);
            if (exists) {
                activateDesktopOpenApp(exists.id);
                return;
            }

            const id = ++desktopAppSeq;
            const iframe = document.createElement('iframe');
            iframe.className = 'desktop-app-frame';
            iframe.setAttribute('allowtransparency', 'true');
            iframe.setAttribute('allow', 'camera *; microphone *; clipboard-read *; clipboard-write *');
            iframe.setAttribute('data-app-id', String(id));
            setDesktopLoadingOverlay(true, label);
            iframe.src = url;
            iframe.onload = function() {
                setDesktopLoadingOverlay(false);
                activarOrdenEnIframe(iframe);
                preventPullToRefreshInIframe(iframe);
                syncEmbeddedLoginDockState();
                renderOpenAppsDock();
            };

            container.appendChild(iframe);
            desktopOpenApps.push({ id, label, url, iframe });

            appAbierta = true;
            document.body.classList.add('app-open');
            syncPullRefreshBlockState();

            document.getElementById('topBar')?.classList.add('hidden-bar');
            document.getElementById('homeScreen').classList.add('hidden-screen');
            setCornerLogoHidden(true);
            container.classList.add('visible');

            cancelHomeScreenHideTimer();
            homeScreenHideTimer = setTimeout(() => {
                document.getElementById('homeScreen').style.display = 'none';
                homeScreenHideTimer = null;
            }, 350);

            activateDesktopOpenApp(id);
        }

        function closeDesktopOpenApp(appId) {
            const idx = desktopOpenApps.findIndex((a) => a.id === appId);
            if (idx < 0) return;

            const [removed] = desktopOpenApps.splice(idx, 1);
            if (removed && removed.iframe && removed.iframe.parentNode) {
                removed.iframe.parentNode.removeChild(removed.iframe);
            }
            setDesktopLoadingOverlay(false);

            if (!desktopOpenApps.length) {
                mostrarHome();
                return;
            }

            const next = desktopOpenApps[Math.max(0, idx - 1)] || desktopOpenApps[0];
            activateDesktopOpenApp(next.id);
        }

        function initOpenAppsDockInteractions() {
            const dock = document.getElementById('openAppsDock');
            const handle = document.getElementById('dockRevealHandle');
            const ctxMenu = document.getElementById('dockContextMenu');
            if (!dock) return;

            if (ctxMenu) {
                ctxMenu.addEventListener('click', (e) => {
                    const btn = e.target && e.target.closest ? e.target.closest('[data-cmd]') : null;
                    if (!btn || btn.disabled) return;
                    handleDockContextMenuCommand(String(btn.getAttribute('data-cmd') || ''));
                });
            }

            document.addEventListener('click', (e) => {
                if (!ctxMenu || !ctxMenu.classList.contains('visible')) return;
                if (ctxMenu.contains(e.target)) return;
                hideDockContextMenu();
            });
            document.addEventListener('touchstart', (e) => {
                if (!ctxMenu || !ctxMenu.classList.contains('visible')) return;
                if (ctxMenu.contains(e.target)) return;
                hideDockContextMenu();
            }, { passive: true });
            document.addEventListener('contextmenu', (e) => {
                if (!ctxMenu || !ctxMenu.classList.contains('visible')) return;
                if (ctxMenu.contains(e.target)) return;
                if (dock.contains(e.target)) return;
                hideDockContextMenu();
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') hideDockContextMenu();
            });

            if (isMobile) {
                if (handle) handle.classList.remove('visible');
                const HOLD_MS = 520;
                let pressStartX = 0;
                let pressStartY = 0;
                let pressedItem = null;

                const cancelLongPress = () => {
                    clearTimeout(mobileDockLongPressTimer);
                    mobileDockLongPressTimer = null;
                    pressedItem = null;
                };

                dock.addEventListener('touchstart', (e) => {
                    const item = e.target && e.target.closest ? e.target.closest('.dock-app-item') : null;
                    if (!item) return;
                    if (String(item.dataset.dockType || '') === 'logout') return;
                    const t = e.touches && e.touches[0] ? e.touches[0] : null;
                    if (!t) return;
                    pressedItem = item;
                    pressStartX = t.clientX;
                    pressStartY = t.clientY;
                    clearTimeout(mobileDockLongPressTimer);
                    mobileDockLongPressTimer = setTimeout(() => {
                        if (!pressedItem) return;
                        mobileDockSuppressClickUntil = Date.now() + 700;
                        const target = {
                            type: String(pressedItem.dataset.dockType || 'app'),
                            label: String(pressedItem.dataset.label || 'App'),
                            url: String(pressedItem.dataset.url || ''),
                            running: String(pressedItem.dataset.running || '0') === '1',
                            appId: String(pressedItem.dataset.appId || '')
                        };
                        showDockContextMenu(pressStartX, pressStartY, target);
                    }, HOLD_MS);
                }, { passive: true });

                dock.addEventListener('touchmove', (e) => {
                    if (!pressedItem) return;
                    const t = e.touches && e.touches[0] ? e.touches[0] : null;
                    if (!t) return;
                    if (Math.abs(t.clientX - pressStartX) > 10 || Math.abs(t.clientY - pressStartY) > 10) {
                        cancelLongPress();
                    }
                }, { passive: true });

                dock.addEventListener('touchend', cancelLongPress, { passive: true });
                dock.addEventListener('touchcancel', cancelLongPress, { passive: true });
                return;
            }
            if (!handle) return;

            handle.addEventListener('mouseenter', () => {
                clearTimeout(dockHideTimer);
                showOpenAppsDock();
            });
            dock.addEventListener('mouseenter', () => {
                clearTimeout(dockHideTimer);
                showOpenAppsDock();
            });
            dock.addEventListener('mouseleave', () => {
                scheduleHideOpenAppsDock();
            });
            dock.addEventListener('contextmenu', (e) => {
                if (e.target && e.target.closest && e.target.closest('.dock-app-item')) return;
                e.preventDefault();
                e.stopPropagation();
                showDockContextMenu(e.clientX, e.clientY, { type: 'dock', label: 'Dock', url: '', running: false, appId: null });
            });
        }
        
        async function abrirApp(label, app) {
            console.log('abrirApp llamado:', { label, app, isMobile });
            
            if (app === '__logout__') {
                cerrarSesion();
                return;
            }
            if (app === '__vcorta__') {
                toggleVcortaChat(true);
                return;
            }

            // Todas las apps usan rutas nativas (ScriptCase deprecado)
            let url = buildAppUrl(app);
            try { window.SmxOfflineShell?.precacheUrls?.([url]); } catch (_) {}
            
            console.log('URL generada:', url);

            if (shouldOpenAppInExternalBrowser(app)) {
                try {
                    const absoluteUrl = await enrichExternalAppUrl(new URL(url, window.location.origin).toString(), app);
                    if (!window.abrirNavegadorExterno(absoluteUrl)) {
                        window.open(absoluteUrl, '_blank', 'noopener,noreferrer');
                    }
                } catch (error) {
                    console.warn('No se pudo abrir app externa enriquecida:', error);
                    alert((error && error.message) ? error.message : 'No se pudo preparar la app externa.');
                }
                return;
            }

            // Guardar el label de la app actual
            currentAppLabel = label;

            // ==========================================
            // MÓVIL: Abrir app dentro del iframe (experiencia app nativa)
            // ==========================================
            if (isMobile) {
                appAbierta = true;
                document.body.classList.add('mobile-app-open');
                syncPullRefreshBlockState();
                currentMobileAppUrl = url;
                
                // Mostrar mini dock móvil (reemplaza header inicio/título).
                renderOpenAppsDock();
                showOpenAppsDock();
                
                // Mostrar indicador de carga
                const loadingIndicator = document.getElementById('mobileLoadingIndicator');
                if (loadingIndicator) loadingIndicator.classList.add('visible');
                
                // Ocultar homeScreen con animación
                document.getElementById('homeScreen').classList.add('hidden-screen');
                setCornerLogoHidden(true);
                
                // Mostrar iframe
                const appFrame = document.getElementById('appFrame');
                appFrame.src = url;
                appFrame.style.display = 'block';
                setTimeout(() => appFrame.classList.add('visible'), 50);
                
                // Ocultar indicador de carga cuando iframe termine de cargar
                appFrame.onload = function() {
                    if (loadingIndicator) loadingIndicator.classList.remove('visible');
                    activarOrdenEnIframe(appFrame);
                    preventPullToRefreshInIframe(appFrame);
                    syncEmbeddedLoginDockState();
                    renderOpenAppsDock();
                };
                
                // Ocultar homeScreen después de la animación
                cancelHomeScreenHideTimer();
                homeScreenHideTimer = setTimeout(() => {
                    document.getElementById('homeScreen').style.display = 'none';
                    homeScreenHideTimer = null;
                }, 300);
                
                // Guardar en historial para botón back del navegador
                history.pushState({ app: label, url: url }, label, '#' + encodeURIComponent(label));
                
                return;
            }

            // ==========================================
            // DESKTOP: Gestión de múltiples iframes abiertos
            // ==========================================
            openDesktopApp(label, url);
        }

        // ==========================================
        // FUNCIÓN PARA CERRAR APP EN MÓVIL
        // ==========================================
        function cerrarAppMobile() {
            if (!appAbierta) {
                restoreHomeScreenUi();
                setCornerLogoHidden(false);
                renderOpenAppsDock();
                showOpenAppsDock();
                return;
            }
            if (!canLeaveSharedScreen()) return;
            
            appAbierta = false;
            currentAppLabel = '';
            currentMobileAppUrl = '';
            embeddedLoginVisible = false;
            document.body.classList.remove('mobile-app-open');
            syncPullRefreshBlockState();
            
            const appFrame = document.getElementById('appFrame');
            const loadingIndicator = document.getElementById('mobileLoadingIndicator');
            
            // Ocultar indicador y mini dock móvil.
            if (loadingIndicator) loadingIndicator.classList.remove('visible');
            renderOpenAppsDock();
            showOpenAppsDock();
            
            // Animar salida del iframe
            appFrame.classList.remove('visible');
            
            // Mostrar homeScreen
            restoreHomeScreenUi();
            
            setTimeout(() => {
                restoreHomeScreenUi();
                setCornerLogoHidden(false);
            }, 50);
            
            // Ocultar iframe después de la animación
            setTimeout(() => {
                appFrame.style.display = 'none';
                appFrame.src = '';
            }, 300);
            
            // Limpiar hash del URL
            if (window.location.hash) {
                history.replaceState(null, '', window.location.pathname);
            }
        }

        // Escuchar botón back del navegador para cerrar app en móvil
        window.addEventListener('popstate', function(event) {
            if (isMobile && appAbierta) {
                cerrarAppMobile();
            } else if (isMobile && !appAbierta) {
                // Safari: a veces restaura desde bfcache con homeScreen oculto
                const hs = document.getElementById('homeScreen');
                if (hs && (hs.style.display === 'none' || hs.classList.contains('hidden-screen'))) {
                    restoreHomeScreenUi();
                    document.body.classList.remove('mobile-app-open');
                    const af = document.getElementById('appFrame');
                    if (af) { af.classList.remove('visible'); af.style.display = 'none'; af.src = ''; }
                }
            }
        });

        // Safari bfcache: forzar recarga si la página se restaura desde caché
        window.addEventListener('pageshow', function(e) {
            if (e.persisted) {
                window.location.reload();
            }
        });

        function mostrarHome() {
            // En móvil usar la función específica
            if (isMobile) {
                cerrarAppMobile();
                return;
            }
            if (!canLeaveSharedScreen()) return;

            const container = document.getElementById('desktopFramesContainer');
            if (container) {
                container.classList.remove('visible');
                container.innerHTML = '';
            }

            desktopOpenApps = [];
            desktopActiveAppId = null;
            appAbierta = false;
            currentAppLabel = '';
            embeddedLoginVisible = false;
            hideDockContextMenu();

            document.body.classList.remove('app-open');
            syncPullRefreshBlockState();
            hideOpenAppsDock();
            renderOpenAppsDock();

            restoreHomeScreenUi();
            setTimeout(() => {
                document.getElementById('topBar')?.classList.remove('hidden-bar');
                restoreHomeScreenUi();
                setCornerLogoHidden(false);
            }, 50);
        }
        


        // Función global para que las apps puedan cerrar el iframe
        function cerrarApp() {
            if (isMobile) {
                mostrarHome();
                return;
            }
            if (desktopActiveAppId !== null) {
                closeDesktopOpenApp(desktopActiveAppId);
                return;
            }
            mostrarHome();
        }

        function canLeaveSharedScreen() {
            if (!smxScreenShareActive) return true;
            return window.confirm('Hay una pantalla compartiéndose ahora mismo. Si volvés a Inicio, la compartición se va a cortar. ¿Querés salir igual?');
        }

        // Exponer para iframes hijos
        window.cerrarApp = cerrarApp;
        window.cerrarAppMobile = cerrarAppMobile;
        window.addEventListener('message', function(event) {
            const data = event && event.data ? event.data : null;
            if (!data || data.type !== 'smx-screen-share-state') return;
            smxScreenShareActive = !!data.active;
        });
        window.abrirNavegadorExterno = function(url) {
            try {
                const target = String(url || '').trim();
                if (!/^https?:\/\//i.test(target)) return false;
                let androidBridgeAvailable = false;

                // Bridge nativo (si la app Android lo expone)
                try {
                    if (window.Android && typeof window.Android.openExternalUrlResult === 'function') {
                        androidBridgeAvailable = true;
                        const ok = window.Android.openExternalUrlResult(target);
                        if (ok === true || ok === 'true' || ok === 1 || ok === '1') return true;
                    }
                    if (window.Android && typeof window.Android.openExternalResult === 'function') {
                        androidBridgeAvailable = true;
                        const ok = window.Android.openExternalResult(target);
                        if (ok === true || ok === 'true' || ok === 1 || ok === '1') return true;
                    }
                    if (window.Android && typeof window.Android.openExternalUrl === 'function') {
                        androidBridgeAvailable = true;
                        window.Android.openExternalUrl(target);
                        return true;
                    }
                    if (window.Android && typeof window.Android.openExternal === 'function') {
                        androidBridgeAvailable = true;
                        window.Android.openExternal(target);
                        return true;
                    }
                } catch (_) {}

                // Cordova / InAppBrowser
                try {
                    if (window.cordova && window.cordova.InAppBrowser && typeof window.cordova.InAppBrowser.open === 'function') {
                        window.cordova.InAppBrowser.open(target, '_system', 'location=yes');
                        return true;
                    }
                } catch (_) {}

                const popup = window.open(target, '_blank', 'noopener,noreferrer');
                if (popup) return true;

                // Evitar navegar la WebView a intent:// cuando estamos dentro de la app Android.
                if (!androidBridgeAvailable) {
                    // Android: forzar navegador externo (Chrome) con intent://
                    try {
                        const ua = String(navigator.userAgent || '').toLowerCase();
                        const isAndroid = ua.includes('android');
                        if (isAndroid) {
                            const u = new URL(target);
                            const scheme = (u.protocol || 'https:').replace(':', '') || 'https';
                            const intentUrl = `intent://${u.host}${u.pathname}${u.search || ''}#Intent;scheme=${scheme};package=com.android.chrome;end`;
                            window.location.href = intentUrl;
                            return true;
                        }
                    } catch (_) {}

                    // Android fallback 2: esquema googlechrome://
                    try {
                        const ua = String(navigator.userAgent || '').toLowerCase();
                        if (ua.includes('android')) {
                            window.location.href = 'googlechrome://navigate?url=' + encodeURIComponent(target);
                            return true;
                        }
                    } catch (_) {}
                }

                const a = document.createElement('a');
                a.href = target;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.style.display = 'none';
                document.body.appendChild(a);
                a.click();
                a.remove();
                return true;
            } catch (_) {
                return false;
            }
        };
        
        // ==========================================
        // GESTOS TÁCTILES PARA MÓVIL (Swipe para volver)
        // ==========================================
        let touchStartX = 0;
        let touchStartY = 0;
        let touchStartTime = 0;
        
        if (isMobile) {
            document.addEventListener('touchstart', function(e) {
                // Solo activar si hay app abierta y el toque empieza en el borde izquierdo
                if (!appAbierta) return;
                
                const touch = e.touches[0];
                touchStartX = touch.clientX;
                touchStartY = touch.clientY;
                touchStartTime = Date.now();
            }, { passive: true });
            
            document.addEventListener('touchend', function(e) {
                if (!appAbierta) return;
                
                const touch = e.changedTouches[0];
                const deltaX = touch.clientX - touchStartX;
                const deltaY = Math.abs(touch.clientY - touchStartY);
                const deltaTime = Date.now() - touchStartTime;
                
                // Detectar swipe desde el borde izquierdo hacia la derecha
                // - Empieza en los primeros 30px del borde
                // - Se mueve al menos 80px hacia la derecha
                // - El movimiento vertical es menor que el horizontal
                // - Dura menos de 500ms
                if (touchStartX < 30 && deltaX > 80 && deltaY < deltaX && deltaTime < 500) {
                    cerrarAppMobile();
                }
            }, { passive: true });
        }
        

        // ==========================================
        // CAMBIAR SUCURSAL ACTIVA
        // ==========================================
        async function cambiarSucursal(id, nombre) {
            // Cerrar dropdown
            document.getElementById('sucursalMenu')?.classList.remove('open');
            try {
                const res = await fetch('/public/api/cambiar_sucursal.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id_sucursal: id, nombre_sucursal: nombre })
                });
                const data = await res.json();
                if (data.ok) {
                    // Actualizar label
                    const label = document.getElementById('sucursalActualLabel');
                    if (label) label.textContent = data.nombre;
                    // Actualizar estilos
                    document.querySelectorAll('.sucursal-option').forEach(el => {
                        const isActive = parseInt(el.dataset.id) === id;
                        el.classList.toggle('active-sucursal', isActive);
                        el.querySelector('.fa-store').style.color = isActive ? '#3b82f6' : 'rgba(148,163,184,0.6)';
                        // Quitar o poner check
                        let check = el.querySelector('.fa-check');
                        if (isActive && !check) {
                            const ic = document.createElement('i');
                            ic.className = 'fas fa-check';
                            ic.style.cssText = 'margin-left:auto; font-size:11px; color:#3b82f6;';
                            el.appendChild(ic);
                        } else if (!isActive && check) {
                            check.remove();
                        }
                    });
                    showNotification('Sucursal cambiada a ' + data.nombre, 'success');
                    // Recargar app abierta según contexto (móvil o desktop).
                    if (isMobile) {
                        const iframe = document.getElementById('appFrame');
                        if (iframe && iframe.src && iframe.src !== 'about:blank') {
                            iframe.src = iframe.src;
                        }
                    } else {
                        const active = desktopOpenApps.find((a) => a.id === desktopActiveAppId);
                        if (active && active.iframe && active.iframe.src) {
                            active.iframe.src = active.iframe.src;
                        }
                    }
                } else {
                    showNotification(data.msg || 'Error al cambiar sucursal', 'error');
                }
            } catch (e) {
                showNotification('Error de conexión', 'error');
            }
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Click afuera cierra dropdown sucursal
        document.addEventListener('click', function(e) {
            const sm = document.getElementById('sucursalMenu');
            if (sm && !sm.contains(e.target)) sm.classList.remove('open');

            let clickInsideVcorta = false;
            if (e.target && typeof e.target.closest === 'function') {
                clickInsideVcorta = !!e.target.closest('#vcortaWidget, #sistemaxCornerLogo, [data-vcorta-trigger="1"]');
            }
            if (!clickInsideVcorta && typeof e.composedPath === 'function') {
                const path = e.composedPath();
                clickInsideVcorta = Array.isArray(path) && path.some((n) => {
                    if (!n || typeof n !== 'object') return false;
                    if (n.id === 'vcortaWidget' || n.id === 'vcortaPanel' || n.id === 'sistemaxCornerLogo') return true;
                    return n.dataset && n.dataset.vcortaTrigger === '1';
                });
            }

            if (vcortaState.open && !clickInsideVcorta) {
                closeVcortaChat();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && vcortaState.open) {
                closeVcortaChat();
            }
        });

        (function autoOpenVcortaFromQuery() {
            try {
                const qs = new URLSearchParams(window.location.search || '');
                if (qs.get('vcorta') === '1') {
                    setTimeout(() => toggleVcortaChat(true), 120);
                }
            } catch (_) {}
        })();

        async function cerrarSesion() {
            const confirmed = await confirmDialog(
                window.__MENU_I18N__?.closeSessionTitle || '¿Cerrar sesión?',
                window.__MENU_I18N__?.closeSessionBody || '¿Está seguro que desea cerrar sesión?'
            );

            if (confirmed) {
                window.location.href = '/public/api/v1/auth.php?action=logout';
            }
        }

        (function bindGlobalLocaleSwitcher() {
            const localeSwitcher = document.getElementById('globalLocaleSwitcher');
            if (!localeSwitcher || !window.SmxI18n) return;

            localeSwitcher.addEventListener('change', async function() {
                const nextLocale = this.value || 'es';
                await window.SmxI18n.setLocale(nextLocale);
                window.location.reload();
            });
        })();

        // ==========================================
        // PWA - Service Worker & Install Prompt
        // ==========================================

        const supportsSubscriptionPush = <?= $supportsSubscriptionPush ? 'true' : 'false' ?>;
        const subscriptionPushPromptKey = 'smx-subscription-push-prompt-<?= (int)$id_empresa ?>-<?= (int)$id_login ?>';

        function smxPushUrlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        }

        async function syncSubscriptionInboxPush() {
            if (!supportsSubscriptionPush) return;
            if (!navigator.onLine) return;
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
            if (!('Notification' in window) || Notification.permission !== 'granted') return;

            try {
                const reg = await navigator.serviceWorker.ready;
                let subscription = await reg.pushManager.getSubscription();
                const keyRes = await fetch('/public/menu/api/chat.php?action=push_public_key&_ts=' + Date.now(), {
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                const keyData = await keyRes.json();
                if (!keyData || !keyData.success || !keyData.public_key) return;

                if (!subscription) {
                    subscription = await reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: smxPushUrlBase64ToUint8Array(String(keyData.public_key || '')),
                    });
                }

                await fetch('/public/menu/api/chat.php?action=push_subscribe', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        subscription: subscription.toJSON(),
                        content_encoding: (PushManager.supportedContentEncodings && PushManager.supportedContentEncodings[0]) || 'aes128gcm',
                    }),
                });
            } catch (err) {
                console.log('[PWA] Push sync omitido:', err);
            }
        }

        async function maybePromptSubscriptionInboxPush() {
            if (!supportsSubscriptionPush) return;
            if (!('Notification' in window)) return;
            if (Notification.permission !== 'default') return;
            const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
            if (!isStandalone) return;
            try {
                if (window.localStorage.getItem(subscriptionPushPromptKey) === '1') return;
                window.localStorage.setItem(subscriptionPushPromptKey, '1');
            } catch (e) {}
            try {
                const permission = await Notification.requestPermission();
                if (permission === 'granted') {
                    await syncSubscriptionInboxPush();
                }
            } catch (err) {
                console.log('[PWA] Push prompt omitido:', err);
            }
        }

        const INTERNET_ONLINE_ICON = '<span class="smx-internet-status-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M2.3 8.7a15.4 15.4 0 0 1 19.4 0l-1.8 2.1a12.5 12.5 0 0 0-15.8 0L2.3 8.7Zm3.7 4.2a9.7 9.7 0 0 1 12 0l-1.8 2.1a6.8 6.8 0 0 0-8.4 0L6 12.9Zm3.7 4.1a4.1 4.1 0 0 1 4.6 0L12 19.2l-2.3-2.2Zm1.1 3.2a1.2 1.2 0 1 1 2.4 0 1.2 1.2 0 0 1-2.4 0Z" fill="currentColor"/></svg></span>';
        const INTERNET_OFFLINE_ICON = '<span class="smx-internet-status-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M2.3 8.7a15.4 15.4 0 0 1 14.7-2.5l-2.2 2.2a12.4 12.4 0 0 0-10.7 1.3L2.3 8.7Zm17.4 0 2 2.1-3 3a9.6 9.6 0 0 0-1.9-2.9l2.9-2.2ZM6 12.9a9.6 9.6 0 0 1 4.6-2.2l-2 2a6.8 6.8 0 0 0-.8.2L6 12.9Zm6 6.3-2.3-2.2a4 4 0 0 1 1.3-.3l1-1a4.2 4.2 0 0 1 2.3.6l-2.3 2.9Zm7.7 2.2L3.1 4.8l1.4-1.4 16.6 16.6-1.4 1.4Z" fill="currentColor"/></svg></span>';

        function updateInternetStatusBadge() {
            const badge = document.getElementById('internetStatusBadge');
            if (!badge) return;
            const online = navigator.onLine;
            badge.innerHTML = online ? INTERNET_ONLINE_ICON : INTERNET_OFFLINE_ICON;
            badge.classList.toggle('is-online', online);
            badge.classList.toggle('is-offline', !online);
            badge.setAttribute('title', online ? 'Conexion disponible' : 'Sin conexion a internet');
        }

        // Registrar Service Worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/public/sw.js', { scope: '/public/' })
                .then(async reg => {
                    console.log('[PWA] Service Worker registrado:', reg.scope);
                    await maybePromptSubscriptionInboxPush();
                    await syncSubscriptionInboxPush();
                })
                .catch(err => console.log('[PWA] Error registrando SW:', err));
        }

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                maybePromptSubscriptionInboxPush();
                syncSubscriptionInboxPush();
            }
        });

        // Guardar evento de instalación
        let deferredPrompt = null;

        window.addEventListener('beforeinstallprompt', (e) => {
            console.log('[PWA] beforeinstallprompt disparado');
            e.preventDefault();
            deferredPrompt = e;
            // Mostrar botón de instalación
            const installBtn = document.getElementById('installBtn');
            if (installBtn) installBtn.style.display = 'flex';
        });

        // Función para instalar la app
        async function installApp() {
            if (!deferredPrompt) {
                // Mostrar instrucciones manuales
                showInstallInstructions();
                return;
            }

            deferredPrompt.prompt();
            const {
                outcome
            } = await deferredPrompt.userChoice;
            console.log('[PWA] Usuario eligió:', outcome);
            deferredPrompt = null;
            document.getElementById('installBtn').style.display = 'none';
        }

        // Instrucciones manuales según dispositivo
        function showInstallInstructions() {
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
            const isAndroid = /Android/.test(navigator.userAgent);

            let title = '';
            let message = '';

            if (isIOS) {
                title = '📱 Instalar en iPhone/iPad';
                message = '1. Toca el botón Compartir (📤) en Safari\n2. Desplázate y toca "Añadir a pantalla de inicio"\n3. Toca "Añadir" para confirmar';
            } else if (isAndroid) {
                title = '📱 Instalar en Android';
                message = '1. Toca el menú (⋮) en Chrome\n2. Toca "Añadir a pantalla de inicio"\n3. Toca "Añadir" para confirmar';
            } else {
                title = '💻 Instalar en escritorio';
                message = '1. Haz clic en el icono de instalación en la barra de direcciones\n2. O usa el menú del navegador > "Instalar aplicación"';
            }

            notify.info(title, message);
        }

        // Detectar si ya está instalada
        window.addEventListener('appinstalled', () => {
            console.log('[PWA] App instalada exitosamente');
            deferredPrompt = null;
            try {
                localStorage.setItem('smx_pwa_installed', '1');
                sessionStorage.setItem('smx_pwa_installed', '1');
            } catch (e) {}
            const installBtn = document.getElementById('installBtn');
            if (installBtn) installBtn.style.display = 'none';
        });

        // Verificar si está corriendo como PWA (ocultar botón instalar)
        const isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true;
        if (isStandalone) {
            console.log('[PWA] Ejecutando en modo standalone');
            try {
                localStorage.setItem('smx_pwa_installed', '1');
                sessionStorage.setItem('smx_pwa_installed', '1');
            } catch (e) {}
            // Ocultar botón instalar si ya está instalada
            const installBtn = document.getElementById('installBtn');
            if (installBtn) installBtn.style.display = 'none';
        }

        // ==========================================
        // ==========================================
        // Sistema de Video Wallpaper - Usa el video de login
        // ==========================================

        const DISABLE_BACKGROUND_VIDEO_BY_EMPRESA = <?php echo json_encode($disableBackgroundVideo); ?>;

        function aplicarVideoDesdeCache() {
            if (DISABLE_BACKGROUND_VIDEO_BY_EMPRESA) {
                console.log('[Video Wallpaper] Video desactivado para esta empresa');
                return;
            }

            const cache = JSON.parse(sessionStorage.getItem('pixabay_video_session') || '{}');
            if (cache.videoUrl) {
                console.log('[Video Wallpaper] Usando video del login:', cache.videoId);
                const video = document.getElementById('video-background');
                if (!video) return;
                
                const source = video.querySelector('source');
                source.src = cache.videoUrl;
                
                video.load();
                
                video.oncanplaythrough = function() {
                    video.classList.add('loaded');
                    video.play().catch(e => {
                        console.warn('[Video Wallpaper] Autoplay bloqueado:', e);
                    });
                };
                
                video.onerror = function() {
                    console.warn('[Video Wallpaper] Error cargando video');
                };
            } else {
                console.log('[Video Wallpaper] No hay video en caché del login');
            }
        }
        
        // Aplicar video cacheado inmediatamente
        (function() {
            aplicarVideoDesdeCache();
        })();

        function applyTheme(theme) {
            document.documentElement.classList.add('dark');
            document.documentElement.setAttribute('data-bs-theme', 'dark');
            document.documentElement.style.colorScheme = 'dark';
            localStorage.setItem('theme', 'dark');
        }

        function toggleTheme() {
            applyTheme('dark');
        }

        // Ejecutar al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            applyTheme('dark');
            hydratePinnedDockApps();
            hydrateDockSettings();
            initOpenAppsDockInteractions();
            renderOpenAppsDock();
            initHomeAppsPagination();
            initMenuSpotlight();
            updateInternetStatusBadge();
            window.addEventListener('online', updateInternetStatusBadge);
            window.addEventListener('offline', updateInternetStatusBadge);

            const profileAvatarBtn = document.getElementById('notifProfileAvatarEditBtn');
            const profileAvatarInput = document.getElementById('notifProfileAvatarInput');
            if (profileAvatarBtn && profileAvatarInput) {
                profileAvatarBtn.addEventListener('click', () => profileAvatarInput.click());
                profileAvatarInput.addEventListener('change', async (ev) => {
                    const file = ev && ev.target && ev.target.files && ev.target.files[0] ? ev.target.files[0] : null;
                    if (!file) return;
                    try {
                        await smxProfileUploadAvatar(file);
                    } catch (error) {
                        alert((error && error.message) ? error.message : 'No se pudo actualizar el avatar');
                    } finally {
                        profileAvatarInput.value = '';
                    }
                });
            }

            const userMenu = document.getElementById('userMenu');
            if (userMenu) {
                const toggleBtn = userMenu.querySelector('button');
                const dropdown = userMenu.querySelector('.dropdown-menu');

                const closeMenu = (event) => {
                    if (!userMenu.contains(event.target)) {
                        userMenu.classList.remove('open');
                    }
                };

                if (toggleBtn) {
                    toggleBtn.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        userMenu.classList.toggle('open');
                    });
                }

                if (dropdown) {
                    dropdown.addEventListener('click', (event) => {
                        event.stopPropagation();
                    });
                }

                document.addEventListener('click', closeMenu);
                document.addEventListener('touchstart', closeMenu);
            }

            const logoutLinks = document.querySelectorAll('.js-logout-link');
            if (logoutLinks.length) {
                logoutLinks.forEach((link) => {
                    link.addEventListener('click', async (event) => {
                        event.preventDefault();
                        try {
                            await cerrarSesion();
                        } catch (error) {
                            window.location.href = link.href;
                        }
                    });
                });
            }

            // Reintentar aplicar video por si no se había cargado el DOM
            aplicarVideoDesdeCache();
        });
    </script>
    <style>
        #internetStatusBadge {
            position: fixed;
            left: 12px;
            bottom: max(12px, env(safe-area-inset-bottom));
            z-index: 100000;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            color: rgba(229, 231, 235, 0.48);
            user-select: none;
            pointer-events: none;
        }
        #internetStatusBadge .smx-internet-status-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            flex: 0 0 18px;
        }
        #internetStatusBadge .smx-internet-status-icon svg {
            display: block;
            width: 18px;
            height: 18px;
        }
        #internetStatusBadge.is-online {
            color: rgba(34, 197, 94, 0.58);
        }
        #internetStatusBadge.is-offline {
            color: rgba(156, 163, 175, 0.38);
        }
    </style>
    <div id="internetStatusBadge" class="is-offline" aria-live="polite"><span class="smx-internet-status-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M2.3 8.7a15.4 15.4 0 0 1 14.7-2.5l-2.2 2.2a12.4 12.4 0 0 0-10.7 1.3L2.3 8.7Zm17.4 0 2 2.1-3 3a9.6 9.6 0 0 0-1.9-2.9l2.9-2.2ZM6 12.9a9.6 9.6 0 0 1 4.6-2.2l-2 2a6.8 6.8 0 0 0-.8.2L6 12.9Zm6 6.3-2.3-2.2a4 4 0 0 1 1.3-.3l1-1a4.2 4.2 0 0 1 2.3.6l-2.3 2.9Zm7.7 2.2L3.1 4.8l1.4-1.4 16.6 16.6-1.4 1.4Z" fill="currentColor"/></svg></span></div>
</body>

</html>
